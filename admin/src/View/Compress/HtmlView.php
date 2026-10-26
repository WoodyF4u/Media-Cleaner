<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\View\Compress;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Mediacleaner\Administrator\Helper\TabsHelper;
use Joomla\Component\Mediacleaner\Administrator\Service\CompressJob;
use Joomla\Component\Mediacleaner\Administrator\Service\Compressor;
use Joomla\Component\Mediacleaner\Administrator\Service\StorageLocator;

/**
 * The Verkleinen tab (v2.9.0).
 */
class HtmlView extends BaseHtmlView
{
    /** @var boolean */
    protected $hasScan = false;

    /** @var array */
    protected $choices = [];

    /** @var boolean */
    protected $onlyLinked = true;

    /** @var array  ['linked' => summary, 'all' => summary] */
    protected $summary = [];

    /** @var array */
    protected $settings = [];

    /** @var array */
    protected $stats = [];

    /** @var array */
    protected $capabilities = [];

    /** @var array */
    protected $storage = [];

    /** @var array */
    protected $log = ['items' => [], 'total' => 0];

    /** @var string */
    protected $logFilter = '';

    /** @var integer */
    protected $logStart = 0;

    /** @var integer */
    protected $logLimit = 50;

    /** @var array */
    protected $zipParts = [];

    /** @var string */
    protected $componentVersion = '';

    /**
     * @param   string  $tpl
     *
     * @return  void
     */
    public function display($tpl = null)
    {
        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\CompressModel $model */
        $model = $this->getModel();
        $input = Factory::getApplication()->getInput();

        $remembered = $model->getRememberedChoices();

        $this->hasScan      = $model->hasScan();
        $this->choices      = $remembered['choices'];
        $this->onlyLinked   = $remembered['only_linked'];
        $this->settings     = CompressJob::getSettings();
        $this->capabilities = Compressor::capabilities();
        $this->stats        = $model->getStats();

        if ($this->hasScan) {
            $this->summary = [
                'linked' => $model->getCandidateSummary(true),
                'all'    => $model->getCandidateSummary(false),
            ];
        }

        $filter          = $input->getCmd('log', '');
        $this->logFilter = \in_array($filter, ['done', 'skipped', 'restored'], true) ? $filter : '';
        $this->logStart  = max(0, $input->getInt('start', 0));
        $this->log       = $model->getLog($this->logFilter, $this->logStart, $this->logLimit);

        try {
            $locator       = new StorageLocator();
            $this->storage = [
                'root'      => $locator->getRoot(),
                'inside'    => $locator->isInsideWebroot(),
                'reachable' => $locator->isWebAccessible(),
                'free'      => $locator->getFreeSpace(),
            ];
            $this->zipParts = $this->stats['originals'] > 0 && $this->capabilities['zip'] ? $model->getZipParts() : [];
        } catch (\RuntimeException $e) {
            $this->storage = ['root' => '', 'inside' => false, 'reachable' => null, 'free' => null];
        }

        $this->componentVersion = TabsHelper::version();

        HTMLHelper::_('stylesheet', 'com_mediacleaner/admin.css', ['version' => 'auto', 'relative' => true]);
        Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('keepalive');

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * @return  void
     */
    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_MEDIACLEANER_TAB_COMPRESS_TITLE'), 'images mediacleaner');

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

    /**
     * @param   integer  $bytes
     *
     * @return  string
     */
    public function mb($bytes)
    {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }

    /**
     * @param   integer  $bytes
     *
     * @return  string
     */
    public function kb($bytes)
    {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
}
