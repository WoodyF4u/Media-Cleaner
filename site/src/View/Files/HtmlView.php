<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Site\View\Files;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * View class for the frontend file overview.
 *
 * Access here is deliberately independent of the menu item's own "Access
 * Level" setting: that setting only controls whether the menu link is
 * shown/reachable at all, but says nothing about which *actions* a visitor
 * who does reach the page should get. So even if a menu item ends up
 * pointing here with an Access Level of "Public" (e.g. by accident), this
 * view still requires the visitor to hold the `mediacleaner.site.view`
 * permission before it shows anything, and only exposes the "Negeren" and
 * "Verwijderen" buttons to logged-in users who additionally hold
 * `mediacleaner.site.ignore` / `mediacleaner.site.delete`. None of these
 * three permissions are granted to anyone by default (see admin/access.xml
 * and the component's Permissions tab), so simply linking a menu item to
 * this component does not, by itself, expose anything.
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var array
     */
    protected $items = [];

    /**
     * @var string
     */
    protected $filterLinked = 'unlinked';

    /**
     * Whether the current visitor may use "Negeren" / "Herstel genegeerd".
     *
     * @var boolean
     */
    protected $canIgnore = false;

    /**
     * Whether the current visitor may use "Verwijderen".
     *
     * @var boolean
     */
    protected $canDelete = false;

    /**
     * Display the view.
     *
     * @param   string  $tpl  Template name.
     *
     * @return  void
     */
    public function display($tpl = null)
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('mediacleaner.site.view', 'com_mediacleaner')) {
            if ($user->guest) {
                // Send guests to log in first rather than a bare "no access"
                // message - they may simply not be logged in yet.
                $return = base64_encode(Uri::getInstance()->toString());
                $app->enqueueMessage(Text::_('COM_MEDIACLEANER_SITE_LOGIN_REQUIRED'), 'notice');
                $app->redirect(Route::_('index.php?option=com_users&view=login&return=' . $return, false));

                return;
            }

            $app->enqueueMessage(Text::_('COM_MEDIACLEANER_SITE_NO_ACCESS'), 'error');
            $app->redirect(Route::_('index.php', false));

            return;
        }

        $this->canIgnore = !$user->guest && $user->authorise('mediacleaner.site.ignore', 'com_mediacleaner');
        $this->canDelete = !$user->guest && $user->authorise('mediacleaner.site.delete', 'com_mediacleaner');

        $this->filterLinked = (string) $app->getUserStateFromRequest(
            'com_mediacleaner.site.files.filter.linked',
            'filter_linked',
            'unlinked',
            'cmd'
        );

        if (!\in_array($this->filterLinked, ['all', 'linked', 'unlinked'], true)) {
            $this->filterLinked = 'unlinked';
        }

        /** @var \Joomla\Component\Mediacleaner\Site\Model\FilesModel $model */
        $model = $this->getModel();

        // The frontend overview always sorts by size (the thing visitors
        // are here to check) and never shows ignored files - that toggle
        // stays a backend-only convenience.
        $this->items = $model->getItems('size', 'DESC', $this->filterLinked, false);

        Factory::getDocument()->setTitle(Text::_('COM_MEDIACLEANER_FILES'));

        // Extracted from an inline <style> block in this view's template
        // into its own file (see media/css/site.css), same as the admin
        // views' media/css/admin.css.
        HTMLHelper::_('stylesheet', 'com_mediacleaner/site.css', ['version' => 'auto', 'relative' => true]);

        parent::display($tpl);
    }
}
