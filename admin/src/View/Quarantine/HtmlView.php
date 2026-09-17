<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\View\Quarantine;

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for the quarantine overview.
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var array
     */
    protected $items = [];

    /**
     * Display the view.
     *
     * @param   string  $tpl  Template name.
     *
     * @return  void
     */
    public function display($tpl = null)
    {
        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\QuarantineModel $model */
        $model = $this->getModel();

        $this->items = $model->getItems();

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
        ToolbarHelper::title(Text::_('COM_MEDIACLEANER_QUARANTINE'), 'trash mediacleaner');
        ToolbarHelper::back(Text::_('COM_MEDIACLEANER_BACK_TO_FILES'), 'index.php?option=com_mediacleaner&view=files');
    }
}
