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
use Joomla\Component\Mediacleaner\Administrator\Service\DuplicateFinder;

/**
 * Tasks of the "Dubbele kopieën" page (v2.9.0).
 */
class DuplicatesController extends BaseController
{
    /**
     * One step of a search, answered as JSON.
     *
     * @return  void
     */
    public function step()
    {
        $app = Factory::getApplication();

        try {
            if (!Session::checkToken('post')) {
                $payload = ['error' => Text::_('JINVALID_TOKEN'), 'reload' => true];
            } else {
                /** @var \Joomla\Component\Mediacleaner\Administrator\Model\DuplicatesModel $model */
                $model = $this->getModel('Duplicates');
                $model->authorise();

                $finder = new DuplicateFinder(Factory::getContainer()->get('DatabaseDriver'));
                $jobId  = $this->input->getCmd('job_id', '');

                if ($jobId === '') {
                    $jobId = $finder->start();
                }

                $payload = $finder->run($jobId, max(2, min(20, $this->input->getInt('budget', 8))));

                if (!empty($payload['done'])) {
                    $app->enqueueMessage(Text::sprintf('COM_MEDIACLEANER_DUPLICATES_FOUND', (int) $payload['found']));
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
     * Move the checked copies to "Tijdelijk verwijderd".
     *
     * @return  void
     */
    public function delete()
    {
        $this->checkToken();

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\DuplicatesModel $model */
        $model  = $this->getModel('Duplicates');
        $result = $model->quarantine($this->input->get('cid', [], 'array'));

        $this->setMessage(Text::sprintf('COM_MEDIACLEANER_DUPLICATES_MOVED', $result['moved']));

        if ($result['changed'] > 0) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('COM_MEDIACLEANER_DUPLICATES_CHANGED', $result['changed']), 'warning');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=duplicates', false));
    }
}
