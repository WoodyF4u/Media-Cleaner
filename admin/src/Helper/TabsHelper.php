<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/**
 * The tabs at the top of Media Cleaner: "Opruimen" (the scanned files -
 * linked or not) and "Verkleinen" (making media files smaller), both
 * v2.9.0, and "Linkcontrole" (links to files that do not exist, v2.9.1).
 * Each tab is its own view with its own toolbar; this only draws the tab
 * row so they all look the same.
 */
abstract class TabsHelper
{
    /**
     * @param   string  $active  'files', 'compress' or 'links'
     *
     * @return  string
     */
    public static function render($active)
    {
        $tabs = [
            'files'    => 'COM_MEDIACLEANER_TAB_CLEANUP',
            'compress' => 'COM_MEDIACLEANER_TAB_COMPRESS',
            'links'    => 'COM_MEDIACLEANER_TAB_LINKS',
        ];

        $html = '<ul class="nav nav-tabs mc-tabs" role="tablist">';

        foreach ($tabs as $view => $label) {
            $isActive = $view === $active;

            $html .= '<li class="nav-item" role="presentation">'
                . '<a class="nav-link' . ($isActive ? ' active' : '') . '"'
                . ($isActive ? ' aria-current="page"' : '')
                . ' href="' . htmlspecialchars(Route::_('index.php?option=com_mediacleaner&view=' . $view, false)) . '">'
                . htmlspecialchars(Text::_($label))
                . '</a></li>';
        }

        return $html . '</ul>';
    }

    /**
     * Installed version from the manifest on disk, '' if unreadable.
     *
     * @return  string
     */
    public static function version()
    {
        $file = JPATH_ADMINISTRATOR . '/components/com_mediacleaner/mediacleaner.xml';

        if (!is_file($file)) {
            return '';
        }

        $xml = @simplexml_load_file($file);

        return $xml && isset($xml->version) ? (string) $xml->version : '';
    }
}
