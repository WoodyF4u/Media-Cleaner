<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Site\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Component\Mediacleaner\Administrator\Model\FilesModel as AdminFilesModel;

/**
 * Site (frontend) file overview model.
 *
 * Reuses the admin model's filesystem-move and database logic wholesale -
 * getItems() for reading the overview, and the protected doSetIgnored() /
 * getQuarantineManager() from the parent class for the mutating actions -
 * rather than duplicating that code. What's different on the site side is *which*
 * permission gates the mutating actions: the backend uses `core.manage`
 * (see the parent class), while here we check the separate, more granular
 * `mediacleaner.site.ignore` / `mediacleaner.site.delete` actions instead,
 * so a frontend-only group can be granted these without also getting
 * backend management rights over the component.
 *
 * A logged-out visitor is never allowed to ignore or delete anything, no
 * matter how the permissions are configured - see the guest check below.
 */
class FilesModel extends AdminFilesModel
{
    /**
     * Mark or unmark files as "ignored", gated by the site-specific
     * "mediacleaner.site.ignore" permission instead of core.manage.
     *
     * @param   array    $ids
     * @param   boolean  $ignored
     *
     * @return  integer
     *
     * @throws  \Exception  When the current user is not allowed to do this from the frontend.
     */
    public function setIgnored(array $ids, $ignored)
    {
        $this->authoriseSiteAction('mediacleaner.site.ignore');

        return $this->doSetIgnored($ids, $ignored);
    }

    /**
     * Move files to quarantine, gated by the site-specific
     * "mediacleaner.site.delete" permission instead of core.manage.
     *
     * @param   array  $ids
     *
     * @return  array
     *
     * @throws  \Exception  When the current user is not allowed to do this from the frontend.
     */
    public function quarantine(array $ids)
    {
        $this->authoriseSiteAction('mediacleaner.site.delete');

        return $this->getQuarantineManager()->quarantine($ids);
    }

    /**
     * Shared guard for the two mutating actions above: the user must be
     * logged in (guests are never trusted with this, regardless of ACL
     * configuration - this is the safety net for a menu item accidentally
     * left open to the "Public" access level) and must hold the specific
     * frontend permission being asked for.
     *
     * @param   string  $action  The ACL action to check, e.g. "mediacleaner.site.ignore".
     *
     * @return  void
     *
     * @throws  \Exception  When not authorised.
     */
    protected function authoriseSiteAction($action)
    {
        $user = Factory::getApplication()->getIdentity();

        if ($user->guest || !$user->authorise($action, 'com_mediacleaner')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }
}
