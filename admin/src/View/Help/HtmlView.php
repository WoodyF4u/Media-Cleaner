<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\View\Help;

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for the help page. Purely informational - no model needed.
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var string
     */
    protected $componentVersion = '';

    /**
     * Display the view.
     *
     * @param   string  $tpl  Template name.
     *
     * @return  void
     */
    public function display($tpl = null)
    {
        $this->componentVersion = $this->getInstalledManifestVersion();

        // Shared admin stylesheet - see the matching note in the Files
        // view's HtmlView::display().
        HTMLHelper::_('stylesheet', 'com_mediacleaner/admin.css', ['version' => 'auto', 'relative' => true]);

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Configure the admin toolbar.
     *
     * @return  void
     */
    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_MEDIACLEANER_HELP_TITLE'), 'help mediacleaner');
        ToolbarHelper::back(Text::_('COM_MEDIACLEANER_BACK_TO_FILES'), 'index.php?option=com_mediacleaner&view=files');
    }

    /**
     * Read the installed version number of this component straight from
     * its manifest XML on disk - just for display here, so this
     * deliberately doesn't duplicate the self-heal side effects that the
     * Files view's equivalent method carries (that repair already runs
     * on every visit to the main page, which the site owner reaches far
     * more often than this one).
     *
     * @return  string  e.g. "1.34.0", or an empty string if it can't be determined.
     */
    protected function getInstalledManifestVersion()
    {
        $path = JPATH_ADMINISTRATOR . '/components/com_mediacleaner/mediacleaner.xml';

        if (!is_file($path)) {
            return '';
        }

        try {
            $xml = simplexml_load_file($path);
        } catch (\Exception $e) {
            return '';
        }

        if ($xml === false || !isset($xml->version)) {
            return '';
        }

        return trim((string) $xml->version);
    }
}
