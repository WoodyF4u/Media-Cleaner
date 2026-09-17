<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Utilities\ArrayHelper;

/**
 * Controller for the frontend "files" view. Only handles the mutating
 * actions ("Negeren" / "Herstel genegeerd" / "Verwijderen"); there is no
 * frontend rescan - that stays a backend-only action.
 *
 * Every task here re-checks permissions itself before even calling the
 * model, even though the model's setIgnored()/quarantine() already do the
 * same check - a deliberate belt-and-braces approach so a future change
 * to either layer on its own still fails safe.
 */
class FilesController extends BaseController
{
    /**
     * Move the selected files to quarantine.
     *
     * @return  void
     */
    public function delete()
    {
        $this->checkToken();
        $this->requireSitePermission('mediacleaner.site.delete');

        $ids = ArrayHelper::toInteger($this->input->get('cid', [], 'array'));

        /** @var \Joomla\Component\Mediacleaner\Site\Model\FilesModel $model */
        $model = $this->getModel('Files');

        try {
            $result = $model->quarantine($ids);

            if ($result['moved'] > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_QUARANTINE_DONE', $result['moved']));
            }

            if ($result['errors'] > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_QUARANTINE_ERRORS', $result['errors']), 'warning');
            }

            if ($result['moved'] === 0 && $result['errors'] === 0) {
                $this->setMessage(Text::_('COM_MEDIACLEANER_QUARANTINE_NONE_SELECTED'), 'warning');
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=files' . $this->getMenuSuffix(), false));
    }

    /**
     * Mark the selected file(s) as "ignored".
     *
     * @return  void
     */
    public function ignore()
    {
        $this->checkToken();
        $this->requireSitePermission('mediacleaner.site.ignore');

        $ids = ArrayHelper::toInteger($this->input->get('cid', [], 'array'));

        /** @var \Joomla\Component\Mediacleaner\Site\Model\FilesModel $model */
        $model = $this->getModel('Files');

        try {
            $count = $model->setIgnored($ids, true);

            if ($count > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_IGNORE_DONE', $count));
            } else {
                $this->setMessage(Text::_('COM_MEDIACLEANER_QUARANTINE_NONE_SELECTED'), 'warning');
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=files' . $this->getMenuSuffix(), false));
    }

    /**
     * Undo "ignored" for the selected file(s).
     *
     * @return  void
     */
    public function unignore()
    {
        $this->checkToken();
        $this->requireSitePermission('mediacleaner.site.ignore');

        $ids = ArrayHelper::toInteger($this->input->get('cid', [], 'array'));

        /** @var \Joomla\Component\Mediacleaner\Site\Model\FilesModel $model */
        $model = $this->getModel('Files');

        try {
            $count = $model->setIgnored($ids, false);

            if ($count > 0) {
                $this->setMessage(Text::sprintf('COM_MEDIACLEANER_UNIGNORE_DONE', $count));
            } else {
                $this->setMessage(Text::_('COM_MEDIACLEANER_QUARANTINE_NONE_SELECTED'), 'warning');
            }
        } catch (\Exception $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=files' . $this->getMenuSuffix(), false));
    }

    /**
     * Refuse the request outright (before ever touching the model) unless
     * the current visitor is logged in and holds the given permission.
     * Guests are always refused, regardless of how permissions are set up.
     *
     * @param   string  $action
     *
     * @return  void
     */
    protected function requireSitePermission($action)
    {
        $user = Factory::getApplication()->getIdentity();

        if ($user->guest || !$user->authorise($action, 'com_mediacleaner')) {
            $this->setMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_mediacleaner&view=files' . $this->getMenuSuffix(), false));
            $this->redirect();
        }
    }

    /**
     * Preserve the current menu item (Itemid) on redirects, if any, so the
     * user stays on the same page of the site instead of bouncing to a
     * bare, template-less component URL.
     *
     * @return  string
     */
    protected function getMenuSuffix()
    {
        $itemId = $this->input->getInt('Itemid', 0);

        return $itemId ? '&Itemid=' . $itemId : '';
    }
}
