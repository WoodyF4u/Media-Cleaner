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
use Joomla\Component\Mediacleaner\Administrator\Service\LinkChecker;

/**
 * Tasks of the Linkcontrole tab (v2.9.1).
 */
class LinksController extends BaseController
{
    /**
     * One step of a check, answered as JSON.
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
                /** @var \Joomla\Component\Mediacleaner\Administrator\Model\LinksModel $model */
                $model = $this->getModel('Links');
                $model->authorise();

                $checker = new LinkChecker(Factory::getContainer()->get('DatabaseDriver'));
                $jobId   = $this->input->getCmd('job_id', '');

                if ($jobId === '') {
                    $jobId = $checker->start();
                }

                $payload = $checker->run($jobId, max(2, min(20, $this->input->getInt('budget', 8))));

                if (!empty($payload['done'])) {
                    $app->enqueueMessage(Text::plural('COM_MEDIACLEANER_LINKS_FOUND', (int) $payload['found']));
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
     * Repair one link (the row's own "Repareren" button).
     *
     * @return  void
     */
    public function fix()
    {
        $this->checkToken();

        $id = $this->input->getInt('fix_id', 0);

        $this->runFix($id > 0 ? [$id] : []);
    }

    /**
     * Repair every checked link with the repair chosen in its row.
     *
     * @return  void
     */
    public function fixSelected()
    {
        $this->checkToken();

        $this->runFix($this->input->get('cid', [], 'array'));
    }

    /**
     * @param   array  $ids
     *
     * @return  void
     */
    protected function runFix(array $ids)
    {
        $choices  = $this->input->get('choice', [], 'array');
        $custom   = $this->input->get('custom', [], 'array');
        $requests = [];

        foreach (array_map('intval', $ids) as $id) {
            if ($id > 0) {
                $requests[$id] = [
                    'choice' => preg_replace('~[^a-z0-9]~', '', (string) ($choices[$id] ?? '')),
                    'custom' => (string) ($custom[$id] ?? ''),
                ];
            }
        }

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\LinksModel $model */
        $model  = $this->getModel('Links');
        $result = $model->fix($requests);
        $app    = Factory::getApplication();

        if ($result['fixed'] > 0) {
            $app->enqueueMessage(Text::plural('COM_MEDIACLEANER_LINKS_FIXED', $result['fixed']));
        }

        if ($result['skipped'] > 0) {
            $app->enqueueMessage(Text::plural('COM_MEDIACLEANER_LINKS_SKIPPED', $result['skipped']), 'notice');
        }

        foreach ($result['errors'] as $message => $count) {
            $app->enqueueMessage($message . ' (' . (int) $count . ')', 'warning');
        }

        if (!$requests) {
            $app->enqueueMessage(Text::_('COM_MEDIACLEANER_LINKS_NOTHING_SELECTED'), 'notice');
        }

        $this->back();
    }

    /**
     * @return  void
     */
    public function ignore()
    {
        $this->checkToken();

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\LinksModel $model */
        $model = $this->getModel('Links');
        $n     = $model->setIgnored($this->ids(), true);

        $this->setMessage(Text::plural('COM_MEDIACLEANER_LINKS_IGNORED_N', $n));
        $this->back();
    }

    /**
     * @return  void
     */
    public function unignore()
    {
        $this->checkToken();

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\LinksModel $model */
        $model = $this->getModel('Links');
        $n     = $model->setIgnored($this->ids(), false);

        $this->setMessage(Text::plural('COM_MEDIACLEANER_LINKS_UNIGNORED_N', $n));
        $this->back();
    }

    /**
     * Undo repairs from the "Gerepareerd" list.
     *
     * @return  void
     */
    public function undo()
    {
        $this->checkToken();

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\LinksModel $model */
        $model  = $this->getModel('Links');
        $one    = $this->input->getInt('undo_id', 0);
        $result = $model->undo($one > 0 ? [$one] : $this->input->get('fid', [], 'array'));
        $app    = Factory::getApplication();

        if ($result['undone'] > 0) {
            $app->enqueueMessage(Text::plural('COM_MEDIACLEANER_LINKS_UNDONE', $result['undone']));
        }

        if ($result['failed'] > 0) {
            $app->enqueueMessage(Text::plural('COM_MEDIACLEANER_LINKS_UNDO_FAILED', $result['failed']), 'warning');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=links', false) . '#mc-links-fixes');
    }

    /**
     * Row's own button (one_id) or the checked rows.
     *
     * @return  integer[]
     */
    protected function ids()
    {
        $one = $this->input->getInt('one_id', 0);

        return $one > 0 ? [$one] : array_map('intval', $this->input->get('cid', [], 'array'));
    }

    /**
     * Back to the list, on the same filter and page.
     *
     * @return  void
     */
    protected function back()
    {
        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=links', false));
    }
}
