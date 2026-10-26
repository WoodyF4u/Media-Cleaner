<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Component\Mediacleaner\Administrator\Service\CompressJob;
use Joomla\Component\Mediacleaner\Administrator\Service\StorageLocator;

/**
 * Tasks of the Verkleinen tab (v2.9.0).
 */
class CompressController extends BaseController
{
    /**
     * One step of a round, answered as JSON - see the script in
     * tmpl/compress/default.php. The first request (no job_id) starts the
     * round with the choices sent along.
     *
     * @return  void
     */
    public function step()
    {
        $app = Factory::getApplication();

        try {
            if (!Session::checkToken('post')) {
                // Same answer as FilesController::scanStep(): the page
                // offers a reload instead of a "Verder gaan" that keeps failing.
                $payload = ['error' => Text::_('JINVALID_TOKEN'), 'reload' => true];
            } else {
                /** @var \Joomla\Component\Mediacleaner\Administrator\Model\CompressModel $model */
                $model = $this->getModel('Compress');
                $model->authorise();

                $job   = new CompressJob(Factory::getContainer()->get('DatabaseDriver'));
                $jobId = $this->input->getCmd('job_id', '');

                $resumed = false;

                if ($jobId === '') {
                    $jobId = (string) $job->findRunning();

                    if ($jobId !== '') {
                        // A round is already under way: carry that one on.
                        $resumed = true;
                    } else {
                        $choices    = $model->normaliseChoices($this->input->get('choices', [], 'array'));
                        $onlyLinked = $this->input->getInt('only_linked', 1) === 1;
                        $retry      = $this->input->getInt('retry', 0) === 1;

                        $model->rememberChoices($choices, $onlyLinked);
                        $jobId = $job->start($choices, $onlyLinked, $retry);

                        // Answer the start right away (it is quick); the work
                        // begins with the next request. A slow first request
                        // can then never make the browser start a second round.
                        $payload = ['jobId' => $jobId, 'done' => false, 'percent' => 1, 'label' => Text::_('COM_MEDIACLEANER_COMPRESS_STARTING')];
                    }
                }

                if (!isset($payload)) {
                    $payload = $job->run($jobId, max(2, min(20, $this->input->getInt('budget', 8))));
                }

                if ($resumed) {
                    $payload['resumed'] = true;
                }

                if (!empty($payload['done'])) {
                    $stats = $payload['stats'];

                    $app->enqueueMessage(
                        Text::sprintf(
                            'COM_MEDIACLEANER_COMPRESS_DONE',
                            (int) $stats['done'] + (int) $stats['converted'],
                            number_format($stats['saved'] / 1048576, 1, ',', '.'),
                            (int) $stats['skipped']
                        )
                    );

                    $app->getSession()->set('application.queue', $app->getMessageQueue());
                }
            }
        } catch (\Throwable $e) {
            $payload = ['error' => $e->getMessage()];
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->sendHeaders();

        echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);

        $app->close();
    }

    /**
     * "Herstellen" for the checked log rows.
     *
     * @return  void
     */
    public function restore()
    {
        $this->checkToken();

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\CompressModel $model */
        $model  = $this->getModel('Compress');
        $result = $model->restore($this->input->get('cid', [], 'array'));

        if ($result['restored'] > 0) {
            $this->setMessage(Text::sprintf('COM_MEDIACLEANER_COMPRESS_RESTORED', $result['restored']));
        }

        if ($result['remaining'] > 0) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('COM_MEDIACLEANER_COMPRESS_RESTORE_REMAINING', $result['remaining']), 'warning');
        }

        if ($result['errors']) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('COM_MEDIACLEANER_COMPRESS_RESTORE_ERRORS', htmlspecialchars(implode(', ', \array_slice($result['errors'], 0, 10)))),
                'warning'
            );
        }

        $this->redirectBack();
    }

    /**
     * Delete all kept originals for good.
     *
     * @return  void
     */
    public function purge()
    {
        $this->checkToken();

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\CompressModel $model */
        $model  = $this->getModel('Compress');
        $result = $model->purgeOriginals(null);

        $this->setMessage(Text::sprintf('COM_MEDIACLEANER_COMPRESS_PURGED', $result['deleted'], number_format($result['bytes'] / 1048576, 1, ',', '.')));
        $this->redirectBack();
    }

    /**
     * Forget skipped files so they are tried again.
     *
     * @return  void
     */
    public function retry()
    {
        $this->checkToken();

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\CompressModel $model */
        $model = $this->getModel('Compress');
        $count = $model->clearSkipped();

        $this->setMessage(Text::sprintf('COM_MEDIACLEANER_COMPRESS_RETRY_DONE', $count));
        $this->redirectBack();
    }

    /**
     * Check again whether the storage folder can be reached from the web.
     *
     * @return  void
     */
    public function recheck()
    {
        $this->checkToken();

        Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_mediacleaner')
            && (new StorageLocator())->isWebAccessible(true);

        $this->redirectBack();
    }

    /**
     * Download one ZIP part with originals.
     *
     * @return  void
     */
    public function zip()
    {
        $this->checkToken();

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\CompressModel $model */
        $model  = $this->getModel('Compress');
        $number = max(1, $this->input->getInt('part', 1));

        try {
            $zip = $model->buildZipPart($number);
        } catch (\RuntimeException $e) {
            $this->setMessage($e->getMessage(), 'error');
            $this->redirectBack();

            return;
        }

        $app  = Factory::getApplication();
        $name = 'mediacleaner-originals-' . preg_replace('~[^a-z0-9-]+~', '-', strtolower((string) parse_url(\Joomla\CMS\Uri\Uri::root(), PHP_URL_HOST))) . '-' . $number . '.zip';

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($zip));
        header('Cache-Control: no-store');

        readfile($zip);
        @unlink($zip);

        $app->close();
    }

    /**
     * @return  void
     */
    protected function redirectBack()
    {
        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=compress', false));
    }
}
