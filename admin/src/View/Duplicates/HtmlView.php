<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\View\Duplicates;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * "Dubbele kopieën" page (v2.9.0), reached from the Opruimen tab.
 */
class HtmlView extends BaseHtmlView
{
    /** @var array */
    protected $items = [];

    /** @var string|null */
    protected $searchedAt = null;

    /**
     * @param   string  $tpl
     *
     * @return  void
     */
    public function display($tpl = null)
    {
        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\DuplicatesModel $model */
        $model = $this->getModel();

        $this->items      = $model->getItems();
        $this->searchedAt = $model->getSearchedAt();

        HTMLHelper::_('stylesheet', 'com_mediacleaner/admin.css', ['version' => 'auto', 'relative' => true]);
        Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('keepalive');

        ToolbarHelper::title(Text::_('COM_MEDIACLEANER_DUPLICATES_TITLE'), 'copy mediacleaner');
        ToolbarHelper::back(Text::_('COM_MEDIACLEANER_BACK_TO_FILES'), 'index.php?option=com_mediacleaner&view=files');

        parent::display($tpl);
    }
}
