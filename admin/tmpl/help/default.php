<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Joomla\Component\Mediacleaner\Administrator\View\Help\HtmlView $this */

$items = [
    ['COM_MEDIACLEANER_HELP_RESCAN_TITLE', 'COM_MEDIACLEANER_HELP_RESCAN_BODY'],
    ['COM_MEDIACLEANER_HELP_FILTERS_TITLE', 'COM_MEDIACLEANER_HELP_FILTERS_BODY'],
    ['COM_MEDIACLEANER_HELP_IGNORE_ACTION_TITLE', 'COM_MEDIACLEANER_HELP_IGNORE_ACTION_BODY'],
    ['COM_MEDIACLEANER_HELP_DELETE_TITLE', 'COM_MEDIACLEANER_HELP_DELETE_BODY'],
    ['COM_MEDIACLEANER_HELP_QUARANTINE_TITLE', 'COM_MEDIACLEANER_HELP_QUARANTINE_BODY'],
    ['COM_MEDIACLEANER_HELP_COLUMNS_TITLE', 'COM_MEDIACLEANER_HELP_COLUMNS_BODY'],
    ['COM_MEDIACLEANER_HELP_PROBABLE_TITLE', 'COM_MEDIACLEANER_HELP_PROBABLE_BODY'],
    ['COM_MEDIACLEANER_HELP_ORPHANED_TITLE', 'COM_MEDIACLEANER_HELP_ORPHANED_BODY'],
    ['COM_MEDIACLEANER_HELP_IGNORE_TOGGLE_TITLE', 'COM_MEDIACLEANER_HELP_IGNORE_TOGGLE_BODY'],
    ['COM_MEDIACLEANER_HELP_REFERENCES_TITLE', 'COM_MEDIACLEANER_HELP_REFERENCES_BODY'],
    ['COM_MEDIACLEANER_HELP_WEBP_TITLE', 'COM_MEDIACLEANER_HELP_WEBP_BODY'],
    ['COM_MEDIACLEANER_HELP_OPTIONS_TITLE', 'COM_MEDIACLEANER_HELP_OPTIONS_BODY'],
];
?>
<div class="com-mediacleaner-help">
    <?php if ($this->componentVersion !== '') : ?>
        <div class="mc-help-summary-row">
            <span class="mc-help-version-label">Media Cleaner &middot; v<?php echo htmlspecialchars($this->componentVersion); ?></span>
        </div>
    <?php endif; ?>

    <h2 class="mc-help-section-title"><?php echo Text::_('COM_MEDIACLEANER_HELP_INTRO_TITLE'); ?></h2>

    <p class="mc-help-intro">
        <?php echo Text::_('COM_MEDIACLEANER_LINK_CHECK_NOTE'); ?>
    </p>

    <h3 class="mc-help-section-title"><?php echo Text::_('COM_MEDIACLEANER_HELP_FEATURES_TITLE'); ?></h3>

    <?php foreach ($items as [$titleKey, $bodyKey]) : ?>
        <div class="mc-help-item">
            <p class="mc-help-item-title"><?php echo Text::_($titleKey); ?></p>
            <p class="mc-help-item-body"><?php echo Text::_($bodyKey); ?></p>
        </div>
    <?php endforeach; ?>
</div>
