<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\View\Links;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Mediacleaner\Administrator\Helper\TabsHelper;
use Joomla\Component\Mediacleaner\Administrator\Model\LinksModel;
use Joomla\Component\Mediacleaner\Administrator\Service\LinkChecker;
use Joomla\Component\Mediacleaner\Administrator\Service\StorageLocator;

/**
 * The Linkcontrole tab (v2.9.1).
 */
class HtmlView extends BaseHtmlView
{
    /** @var string|null */
    protected $checkedAt = null;

    /** @var boolean */
    protected $wasCut = false;

    /** @var array */
    protected $counts = [];

    /** @var string */
    protected $filter = '';

    /** @var boolean */
    protected $showTrashed = false;

    /** @var string  Table name the list is narrowed to, '' = all (v2.9.2). */
    protected $source = '';

    /** @var array  See LinksModel::getSources(). */
    protected $sources = [];

    /** @var boolean  Checkbox "also external links" (remembered). */
    protected $externalChoice = false;

    /** @var boolean  The last check included external links. */
    protected $checkedExternal = false;

    /** @var boolean  This server can request other websites. */
    protected $canCheckExternal = true;

    /** @var array */
    protected $list = ['items' => [], 'total' => 0];

    /** @var integer */
    protected $start = 0;

    /** @var integer */
    protected $limit = 50;

    /** @var array */
    protected $fixes = ['items' => [], 'total' => 0];

    /** @var integer */
    protected $fixStart = 0;

    /** @var integer */
    protected $fixLimit = 20;

    /** @var string */
    protected $componentVersion = '';

    /**
     * @param   string  $tpl
     *
     * @return  void
     */
    public function display($tpl = null)
    {
        /** @var LinksModel $model */
        $model = $this->getModel();
        $app   = Factory::getApplication();
        $input = $app->getInput();

        $filter            = (string) $app->getUserStateFromRequest('com_mediacleaner.links.filter', 'filter', '', 'cmd');
        $this->filter      = \in_array($filter, LinksModel::FILTERS, true) ? $filter : '';
        $this->showTrashed = (int) $app->getUserStateFromRequest('com_mediacleaner.links.trashed', 'trashed', 0, 'int') === 1;
        $this->start       = max(0, (int) $app->getUserStateFromRequest('com_mediacleaner.links.start', 'start', 0, 'int'));

        $this->source = (string) $app->getUserStateFromRequest('com_mediacleaner.links.source', 'source', '', 'string');

        // Another filter: back to page 1.
        if ($input->get('filter', null, 'cmd') !== null || $input->get('trashed', null, 'int') !== null || $input->get('source', null, 'string') !== null) {
            $this->start = $input->getInt('start', 0);
            $app->setUserState('com_mediacleaner.links.start', $this->start);
        }

        $this->checkedAt = $model->getCheckedAt();
        $this->wasCut    = $model->wasCut();
        $this->sources   = $model->getSources($this->filter, $this->showTrashed);

        // A source the current filter has nothing of (any more): all.
        if ($this->source !== '' && !isset($this->sources[$this->source])) {
            $this->source = '';
            $app->setUserState('com_mediacleaner.links.source', '');
        }

        $this->counts    = $model->getCounts($this->showTrashed, $this->source);
        $this->list      = $model->getItems($this->filter, $this->showTrashed, $this->start, $this->limit, $this->source);

        $this->externalChoice   = StorageLocator::getState('links_external', '0') === '1';
        $this->checkedExternal  = $model->checkedExternal();
        $this->canCheckExternal = LinkChecker::canCheckExternal();

        // Page emptied by repairs: show the last page that has rows.
        if (!$this->list['items'] && $this->start > 0 && $this->list['total'] > 0) {
            $this->start = (int) (floor(($this->list['total'] - 1) / $this->limit) * $this->limit);
            $app->setUserState('com_mediacleaner.links.start', $this->start);
            $this->list = $model->getItems($this->filter, $this->showTrashed, $this->start, $this->limit, $this->source);
        }

        $this->fixStart = max(0, $input->getInt('fstart', 0));
        $this->fixes    = $model->getFixes($this->fixStart, $this->fixLimit);

        $this->componentVersion = TabsHelper::version();

        HTMLHelper::_('stylesheet', 'com_mediacleaner/admin.css', ['version' => 'auto', 'relative' => true]);
        $app->getDocument()->getWebAssetManager()->useScript('keepalive');

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * @return  void
     */
    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_MEDIACLEANER_TAB_LINKS_TITLE'), 'link mediacleaner');

        // Same order and reasoning as the Files view (Options docks
        // right, Help right after it).
        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_mediacleaner')) {
            $return = urlencode(base64_encode((string) Uri::getInstance()));

            ToolbarHelper::link(
                Route::_('index.php?option=com_config&view=component&component=com_mediacleaner&return=' . $return),
                Text::_('JTOOLBAR_OPTIONS'),
                'options'
            );
        }

        ToolbarHelper::link(
            Route::_('index.php?option=com_mediacleaner&view=help'),
            Text::_('COM_MEDIACLEANER_HELP'),
            'help'
        );
    }
}
