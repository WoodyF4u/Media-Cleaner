<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Joomla\Component\Mediacleaner\Administrator\View\Files\HtmlView $this */

// Plain inline SVG (currentColor stroke) instead of the Unicode
// wastebasket character (&#128465;) - v2.3.7 fix, same root cause as
// Quarantine/default.php: that glyph can render via the browser's
// colour-emoji fallback font, ignoring this button's `color: #fff` CSS
// entirely. See that file's $purgeIcon for the fuller explanation.
$purgeIcon = '<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
    . '<path d="M3 4.6h10M6.3 4.6V3.2a1 1 0 0 1 1-1h1.4a1 1 0 0 1 1 1v1.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>'
    . '<path d="M4.4 4.6 5 13a1 1 0 0 0 1 .9h4a1 1 0 0 0 1-.9l.6-8.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>'
    . '<path d="M6.6 7.2v4M9.4 7.2v4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>'
    . '</svg>';

/**
 * Build a sortable column header link. Always includes the current filter
 * state explicitly, so switching sort column never resets the active
 * "linked" filter.
 *
 * @param   string  $column  Internal column key (size, type, name, path, linked).
 * @param   string  $label   Translated label to show.
 *
 * @return  string
 */
$sortHeader = function (string $column, string $label) {
    $newDir = ($this->listOrder === $column && $this->listDirn === 'ASC') ? 'DESC' : 'ASC';
    $url    = Route::_(
        'index.php?option=com_mediacleaner&view=files'
        . '&filter_order=' . $column
        . '&filter_order_Dir=' . $newDir
        . '&filter_linked=' . $this->filterLinked
        . '&limitstart=0',
        false
    );
    $active = $this->listOrder === $column;
    $icon   = '<span class="mc-sort-icon' . ($active ? ' mc-sort-icon-active' : '') . '">'
        . ($active && $this->listDirn === 'ASC' ? '&#9650;' : '&#9660;')
        . '</span>';

    return '<a href="' . htmlspecialchars($url) . '" class="mc-sort-link' . ($active ? ' mc-sort-active' : '') . '">'
        . htmlspecialchars($label) . ' ' . $icon . '</a>';
};

/**
 * Build one "linked" filter link (Alle media / Gekoppelde media /
 * Niet-gekoppelde media / Genegeerde media). Always forces the sort back
 * to "size, largest first" so each view starts out sorted the same,
 * useful way, regardless of what sort order was active before switching.
 *
 * v2.3.6: "Genegeerde media" used to be a separate on/off toggle layered
 * on top of these three (any of them could have ignored files mixed in
 * or not) - Wouter pointed out that visually and conceptually it reads
 * as a fifth, equally-weighted bucket ("show me this particular set"),
 * not a modifier on the other three, so it's now just a fourth value
 * this same function handles ('ignored' -> every ignored file,
 * regardless of linked status - see FilesModel::getItems()).
 *
 * @param   string   $value  'all', 'linked', 'unlinked' or 'ignored'.
 * @param   string   $label  Translated label to show.
 * @param   integer  $count  Number of files this button would show.
 *
 * @return  string
 */
$filterLink = function (string $value, string $label, int $count) {
    $url = Route::_(
        'index.php?option=com_mediacleaner&view=files'
        . '&filter_linked=' . $value
        . '&filter_order=size'
        . '&filter_order_Dir=DESC'
        . '&limitstart=0',
        false
    );
    $active = $this->filterLinked === $value;

    return '<a href="' . htmlspecialchars($url) . '" class="mc-filter-link' . ($active ? ' mc-filter-active' : '') . '">'
        . htmlspecialchars($label) . ' <span class="mc-filter-count">(' . $count . ')</span></a>';
};

$videoExtensions = ['mp4', 'webm', 'ogv'];
$audioExtensions = ['mp3', 'wav', 'm4a', 'aac', 'ogg'];

// Whether the currently displayed list contains at least one unlinked file -
// determines whether the selection column, bulk action bar and per-row
// delete icon are shown at all (independent of which filter is active).
// v2.2.0: switched from scanning $this->items (now just the current page,
// since pagination was added) to the library-wide $this->unlinkedCount,
// so the bulk-action UI stays consistently visible across every page of
// a filter, not just pages that happen to contain an unlinked file.
$hasUnlinkedInList = $this->unlinkedCount > 0;

/**
 * Build the badge markup shown in the thumbnail cell for file types that
 * can't be rendered as a real <img> thumbnail (video, audio, pdf, tiff).
 *
 * @param   string  $type  Lowercase file extension.
 *
 * @return  string|null  HTML for the badge, or null if a real thumbnail should be used instead.
 */
$badge = function (string $type) use ($videoExtensions, $audioExtensions) {
    if (in_array($type, $videoExtensions, true)) {
        return '<span class="mc-badge mc-badge-video">' . Text::_('COM_MEDIACLEANER_BADGE_VIDEO') . '</span>';
    }

    if (in_array($type, $audioExtensions, true)) {
        return '<span class="mc-badge mc-badge-audio">' . Text::_('COM_MEDIACLEANER_BADGE_AUDIO') . '</span>';
    }

    if ($type === 'pdf') {
        return '<span class="mc-badge mc-badge-pdf">' . Text::_('COM_MEDIACLEANER_BADGE_PDF') . '</span>';
    }

    if ($type === 'tif' || $type === 'tiff') {
        return '<span class="mc-badge mc-badge-doc">' . Text::_('COM_MEDIACLEANER_BADGE_TIFF') . '</span>';
    }

    return null;
};

/**
 * Translate a reference "type" code into a readable label for the deep-link
 * lines shown under the file name on the "Gekoppelde media" view.
 *
 * @param   string  $type
 *
 * @return  string
 */
$refTypeLabel = function (string $type) {
    $labels = [
        'article'    => Text::_('COM_MEDIACLEANER_REF_ARTICLE'),
        'module'     => Text::_('COM_MEDIACLEANER_REF_MODULE'),
        'menu'       => Text::_('COM_MEDIACLEANER_REF_MENU'),
        'category'   => Text::_('COM_MEDIACLEANER_REF_CATEGORY'),
        'contact'    => Text::_('COM_MEDIACLEANER_REF_CONTACT'),
        'banner'     => Text::_('COM_MEDIACLEANER_REF_BANNER'),
        'field'      => Text::_('COM_MEDIACLEANER_REF_FIELD'),
        'icagenda'   => Text::_('COM_MEDIACLEANER_REF_ICAGENDA'),
        'jdownloads' => Text::_('COM_MEDIACLEANER_REF_JDOWNLOADS'),
        'bagallery'  => Text::_('COM_MEDIACLEANER_REF_BAGALLERY'),
    ];

    return $labels[$type] ?? ucfirst($type);
};

/**
 * Build one "extensie filter" link for the dropdown-replacement bar
 * under the four main tabs (v2.7.5) - re-uses the exact same URL
 * pattern as $filterLink() (full page reload, no JS-driven filtering),
 * just varying `extension_hint` instead of `filter_linked`.
 *
 * @return  string
 */
$extensionFilterOptions = function () {
    $options = '<option value=""' . ($this->extensionHint === '' ? ' selected' : '') . '>'
        . htmlspecialchars(Text::_('COM_MEDIACLEANER_EXTENSION_FILTER_ALL')) . '</option>';

    foreach ($this->extensionFilterCounts as $hint) {
        $options .= '<option value="' . htmlspecialchars($hint['name']) . '"'
            . ($this->extensionHint === $hint['name'] ? ' selected' : '') . '>'
            . htmlspecialchars($hint['name']) . ' (' . (int) $hint['count'] . ')</option>';
    }

    return $options;
};

$webpConvertibleTypes = ['jpg', 'jpeg', 'png', 'tif', 'tiff'];
?>
<form action="<?php echo Route::_('index.php?option=com_mediacleaner&view=files'); ?>" method="post" name="adminForm" id="adminForm">

<div class="com-mediacleaner-files">
    <div id="mc-rescan-overlay-slot"></div>

    <h2 class="mc-page-title">Media Cleaner</h2>

    <?php if ($this->hideContentForRescan) : ?>
        <?php // The actual "Scan wordt opnieuw uitgevoerd..." bar is
              // rendered by the existing JS below, into
              // #mc-rescan-overlay-slot just above this - same mechanism
              // already used for the manual button and the
              // Options-return flow, so it looks identical in every
              // case. Nothing else needs to go here; this branch's whole
              // job is to skip rendering the summary/filters/table
              // further down while they're guaranteed to be stale. ?>
        <noscript>
            <p class="mc-summary"><?php echo Text::_('COM_MEDIACLEANER_RESCAN_IN_PROGRESS'); ?></p>
        </noscript>
    <?php else : ?>
    <div class="mc-summary-row">
        <p class="mc-summary">
            <?php if ($this->lastScan) : ?>
                <?php echo Text::sprintf('COM_MEDIACLEANER_SUMMARY', (int) $this->totalCount, number_format($this->totalSizeKB, 1, ',', '.')); ?>
                &nbsp;&mdash;&nbsp;
                <?php echo Text::sprintf('COM_MEDIACLEANER_LAST_SCAN', HTMLHelper::_('date', $this->lastScan, Text::_('DATE_FORMAT_LC5'))); ?>
                <?php if ($this->unlinkedCount > 0) : ?>
                    &nbsp;&mdash;&nbsp;
                    <span class="mc-unlinked-hint">
                        <?php echo Text::sprintf('COM_MEDIACLEANER_UNLINKED_HINT', $this->unlinkedCount); ?>
                    </span>
                <?php endif; ?>
            <?php else : ?>
                <?php echo Text::_('COM_MEDIACLEANER_NEVER_SCANNED'); ?>
            <?php endif; ?>
        </p>

        <?php if ($this->componentVersion !== '') : ?>
            <span class="mc-version-label">Media Cleaner &middot; v<?php echo htmlspecialchars($this->componentVersion); ?></span>
        <?php endif; ?>
    </div>

    <?php if ($this->lastScan) : ?>
        <div class="mc-filters">
            <?php echo $filterLink('all', Text::_('COM_MEDIACLEANER_FILTER_ALL'), $this->filterCounts['all']); ?>
            <?php echo $filterLink('linked', Text::_('COM_MEDIACLEANER_FILTER_LINKED'), $this->filterCounts['linked']); ?>
            <?php echo $filterLink('unlinked', Text::_('COM_MEDIACLEANER_FILTER_UNLINKED'), $this->filterCounts['unlinked']); ?>
            <?php echo $filterLink('ignored', Text::_('COM_MEDIACLEANER_SHOW_IGNORED'), $this->filterCounts['ignored']); ?>
            <a href="<?php echo htmlspecialchars(Route::_('index.php?option=com_mediacleaner&view=quarantine', false)); ?>" class="mc-filter-link mc-quarantine-link">
                <?php echo Text::_('COM_MEDIACLEANER_SHOW_QUARANTINE'); ?> <span class="mc-filter-count">(<?php echo (int) $this->quarantineCount; ?>)</span> <span aria-hidden="true">&#8599;</span>
            </a>
            <?php if ($this->pagination) : ?>
                <span class="mc-limitbox"><?php echo $this->pagination->getLimitBox(); ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($this->lastScan && !empty($this->extensionFilterCounts)) : ?>
        <div class="mc-actions-bar mc-extension-filter-bar">
            <label for="mc-extension-filter-select" class="mc-bulk-label">
                <?php echo Text::_('COM_MEDIACLEANER_EXTENSION_FILTER_LABEL'); ?>
            </label>
            <select id="mc-extension-filter-select" class="mc-bulk-select">
                <?php echo $extensionFilterOptions(); ?>
            </select>
        </div>
    <?php endif; ?>

    <?php if ($this->lastScan && in_array($this->filterLinked, ['ignored', 'unlinked'], true)) : ?>
        <div class="mc-actions-bar mc-extension-filter-bar">
            <label for="mc-system-asset-filter-select" class="mc-bulk-label">
                <?php echo Text::_('COM_MEDIACLEANER_SYSTEM_ASSET_FILTER_LABEL'); ?>
            </label>
            <select id="mc-system-asset-filter-select" class="mc-bulk-select">
                <option value="" <?php echo $this->systemAssetFilter === '' ? 'selected' : ''; ?>>
                    <?php echo Text::_('COM_MEDIACLEANER_SYSTEM_ASSET_FILTER_ALL'); ?>
                </option>
                <option value="system" <?php echo $this->systemAssetFilter === 'system' ? 'selected' : ''; ?>>
                    <?php echo Text::_('COM_MEDIACLEANER_SYSTEM_ASSET_FILTER_SYSTEM'); ?>
                </option>
                <option value="other" <?php echo $this->systemAssetFilter === 'other' ? 'selected' : ''; ?>>
                    <?php echo Text::_('COM_MEDIACLEANER_SYSTEM_ASSET_FILTER_OTHER'); ?>
                </option>
            </select>
        </div>
    <?php endif; ?>

    <?php if ($this->lastScan && $this->systemAssetFileCount > 0) : ?>
        <div class="mc-actions-bar">
            <form action="<?php echo Route::_('index.php?option=com_mediacleaner&view=files'); ?>" method="post" onsubmit="return confirm('<?php echo addslashes(Text::sprintf('COM_MEDIACLEANER_CONFIRM_SYSTEM_ASSET_IGNORE', (int) $this->systemAssetFileCount)); ?>');">
                <?php echo Text::sprintf('COM_MEDIACLEANER_SYSTEM_ASSET_BAR_LABEL', (int) $this->systemAssetFileCount); ?>
                <button type="submit" class="mc-btn mc-btn-bulk-ignore" id="mc-system-asset-apply-btn">
                    <?php echo Text::_('COM_MEDIACLEANER_SYSTEM_ASSET_BAR_BUTTON'); ?>
                </button>
                <input type="hidden" name="filter_linked" value="<?php echo htmlspecialchars($this->filterLinked); ?>">
                <input type="hidden" name="task" value="files.ignoreSystemAssets">
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($this->lastScan && $hasUnlinkedInList) : ?>
        <div class="mc-actions-bar">
            <label for="mc-f-bulk-select" class="mc-bulk-label"><?php echo Text::_('COM_MEDIACLEANER_BULK_ACTION_LABEL'); ?></label>
            <select id="mc-f-bulk-select" class="mc-bulk-select">
                <option value="files.ignore"><?php echo Text::_('COM_MEDIACLEANER_IGNORE'); ?></option>
                <option value="files.delete"><?php echo Text::_('COM_MEDIACLEANER_DELETE'); ?></option>
                <option value="files.unignore"><?php echo Text::_('COM_MEDIACLEANER_UNIGNORE'); ?></option>
            </select>
            <button type="button" id="mc-f-bulk-apply" class="mc-btn mc-btn-purge" disabled>
                <?php echo Text::_('COM_MEDIACLEANER_BULK_APPLY'); ?>
            </button>
            <span class="mc-selected-count"><span id="mc-f-count">0</span> <?php echo Text::_('COM_MEDIACLEANER_SELECTED_SUFFIX'); ?></span>
            <?php if ($this->pagination && $this->pagination->pagesTotal > 1 && $this->totalCount > 0) : ?>
                <label class="mc-select-all-matching-label">
                    <input type="checkbox" id="mc-f-select-all-matching">
                    <?php echo Text::sprintf('COM_MEDIACLEANER_SELECT_ALL_MATCHING', (int) $this->totalCount); ?>
                </label>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($this->items)) : ?>
        <div class="mc-empty">
            <?php echo Text::_($this->lastScan ? 'COM_MEDIACLEANER_NO_FILES' : 'COM_MEDIACLEANER_NEVER_SCANNED_HINT'); ?>
        </div>
    <?php else : ?>
        <table class="mc-table table table-striped">
            <thead>
                <tr>
                    <?php if ($hasUnlinkedInList) : ?>
                        <th style="width:26px;">
                            <input type="checkbox" id="mc-f-check-all" class="form-check-input">
                        </th>
                    <?php endif; ?>
                    <th><?php echo Text::_('COM_MEDIACLEANER_COL_THUMB'); ?></th>
                    <th><?php echo $sortHeader('name', Text::_('COM_MEDIACLEANER_COL_NAME')); ?></th>
                    <th style="white-space:nowrap;"><?php echo $sortHeader('size', Text::_('COM_MEDIACLEANER_COL_SIZE')); ?></th>
                    <th><?php echo $sortHeader('path', Text::_('COM_MEDIACLEANER_COL_LOCATION')); ?></th>
                    <th><?php echo $sortHeader('type', Text::_('COM_MEDIACLEANER_COL_TYPE')); ?></th>
                    <th>
                        <?php if ($this->filterLinked === 'all') : ?>
                            <?php echo $sortHeader('linked', Text::_('COM_MEDIACLEANER_COL_LINKED')); ?>
                        <?php else : ?>
                            <span class="mc-sort-disabled"><?php echo Text::_('COM_MEDIACLEANER_COL_LINKED'); ?></span>
                        <?php endif; ?>
                    </th>
                    <?php if ($hasUnlinkedInList) : ?>
                        <th style="width:190px;"><?php echo Text::_('COM_MEDIACLEANER_COL_ACTION'); ?></th>
                    <?php endif; ?>
                    <?php if ($this->filterLinked === 'linked') : ?>
                        <th style="width:150px;"><?php echo Text::_('COM_MEDIACLEANER_COL_WEBP'); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($this->items as $item) : ?>
                    <tr>
                        <?php if ($hasUnlinkedInList) : ?>
                            <td>
                                <?php if (!$item['linked']) : ?>
                                    <input
                                        type="checkbox"
                                        class="mc-f-checkbox form-check-input"
                                        name="cid[]"
                                        value="<?php echo (int) $item['id']; ?>"
                                        id="mc-f-cb-<?php echo (int) $item['id']; ?>"
                                    >
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="mc-thumb-cell">
                            <?php $badgeHtml = $badge($item['type']); ?>
                            <?php if ($badgeHtml === null && empty($item['noPreview'])) : ?>
                                <img
                                    src="<?php echo htmlspecialchars($item['url']); ?>"
                                    alt="<?php echo htmlspecialchars($item['name']); ?>"
                                    loading="lazy"
                                    onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';"
                                    onload="if (this.naturalWidth === 0) { this.style.display='none'; this.nextElementSibling.style.display='inline-flex'; }"
                                >
                                <span class="mc-badge-camera" aria-hidden="true">
                                    <svg viewBox="0 0 16 16" width="26" height="26" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/>
                                        <path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2h-12zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1h12z"/>
                                    </svg>
                                </span>
                            <?php elseif ($badgeHtml === null) : ?>
                                <span class="mc-badge-camera mc-badge-camera-static" aria-hidden="true" style="display:inline-flex;">
                                    <svg viewBox="0 0 16 16" width="26" height="26" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/>
                                        <path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2h-12zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1h12z"/>
                                    </svg>
                                </span>
                            <?php else : ?>
                                <?php echo $badgeHtml; ?>
                            <?php endif; ?>
                        </td>
                        <td class="mc-f-name-cell">
                            <a href="<?php echo htmlspecialchars($item['url']); ?>" target="_blank" rel="noopener">
                                <?php echo htmlspecialchars($item['name']); ?>
                            </a>
                            <?php if ($this->filterLinked === 'linked' && !empty($item['references'])) : ?>
                                <div class="mc-refs">
                                    <?php foreach ($item['references'] as $ref) : ?>
                                        <div class="mc-ref-line">
                                            &#8627; <?php echo htmlspecialchars($refTypeLabel($ref['type'])); ?>:
                                            <?php if (!empty($ref['url'])) : ?>
                                                <a
                                                    href="<?php echo htmlspecialchars(Route::_($ref['url'], false)); ?>"
                                                    target="_blank"
                                                    rel="noopener"
                                                ><?php echo htmlspecialchars($ref['title']); ?></a>
                                            <?php else : ?>
                                                <?php echo htmlspecialchars($ref['title']); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;"><?php echo number_format($item['sizeKB'], 1, ',', '.'); ?> KB</td>
                        <td class="mc-path">
                            <?php echo htmlspecialchars($item['path']); ?>
                            <?php if (!empty($item['assetDirExtensionName'])) : ?>
                                <?php if (!empty($item['assetDirExtensionRemoved'])) : ?>
                                    <div class="mc-orphan-hint"><?php echo Text::sprintf('COM_MEDIACLEANER_ASSET_DIR_EXTENSION_REMOVED', htmlspecialchars($item['assetDirExtensionName'])); ?></div>
                                <?php else : ?>
                                    <div class="mc-orphan-hint"><?php echo Text::sprintf('COM_MEDIACLEANER_ASSET_DIR_POSSIBLE_EXTENSION', htmlspecialchars($item['assetDirExtensionName'])); ?></div>
                                <?php endif; ?>
                            <?php elseif (!empty($item['imagesDirExtensionName'])) : ?>
                                <?php if (!empty($item['imagesDirExtensionRemoved'])) : ?>
                                    <div class="mc-orphan-hint"><?php echo Text::sprintf('COM_MEDIACLEANER_IMAGES_DIR_EXTENSION_REMOVED', htmlspecialchars($item['imagesDirExtensionName'])); ?></div>
                                <?php else : ?>
                                    <div class="mc-orphan-hint"><?php echo Text::sprintf('COM_MEDIACLEANER_IMAGES_DIR_POSSIBLE_EXTENSION', htmlspecialchars($item['imagesDirExtensionName'])); ?></div>
                                <?php endif; ?>
                            <?php elseif (!$item['linked'] && !empty($item['possiblyOrphanedExtension'])) : ?>
                                <div class="mc-orphan-hint"><?php echo Text::_('COM_MEDIACLEANER_POSSIBLY_ORPHANED_EXTENSION'); ?></div>
                            <?php elseif (!$item['linked'] && !empty($item['isThumbsDir']) && $this->showThumbsDirHint) : ?>
                                <div class="mc-orphan-hint"><?php echo Text::_('COM_MEDIACLEANER_THUMBS_DIR_HINT'); ?></div>
                            <?php elseif (!$item['linked'] && !empty($item['isSystemAssetDir']) && empty($item['likelyManualUpload'])) : ?>
                                <div class="mc-orphan-hint"><?php echo Text::_('COM_MEDIACLEANER_SYSTEM_ASSET_HINT'); ?></div>
                            <?php elseif (!$item['linked'] && !empty($item['isSystemAssetDir']) && !empty($item['likelyManualUpload'])) : ?>
                                <div class="mc-orphan-hint"><?php echo Text::_('COM_MEDIACLEANER_SYSTEM_ASSET_MANUAL_HINT'); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars(strtoupper($item['type'])); ?></td>
                        <td>
                            <?php if (($item['linkConfidence'] ?? 'none') === 'confirmed') : ?>
                                <span class="mc-badge mc-badge-linked"><?php echo Text::_('COM_MEDIACLEANER_LINKED_YES'); ?></span>
                            <?php elseif (($item['linkConfidence'] ?? 'none') === 'probable') : ?>
                                <span class="mc-badge mc-badge-probable" title="<?php echo htmlspecialchars(Text::_('COM_MEDIACLEANER_LINKED_PROBABLE_HINT')); ?>"><?php echo Text::_('COM_MEDIACLEANER_LINKED_PROBABLE'); ?></span>
                            <?php else : ?>
                                <span class="mc-badge mc-badge-unlinked"><?php echo Text::_('COM_MEDIACLEANER_LINKED_NO'); ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($hasUnlinkedInList) : ?>
                            <td>
                                <?php if (!$item['linked']) : ?>
                                    <div class="mc-action-group">
                                        <?php if ($item['ignored']) : ?>
                                            <a
                                                href="#"
                                                class="mc-ignore-btn mc-unignore-btn mc-f-row-unignore"
                                                data-id="<?php echo (int) $item['id']; ?>"
                                                title="<?php echo htmlspecialchars(Text::_('COM_MEDIACLEANER_UNIGNORE')); ?>"
                                            ><?php echo Text::_('COM_MEDIACLEANER_UNIGNORE'); ?></a>
                                        <?php else : ?>
                                            <a
                                                href="#"
                                                class="mc-ignore-btn mc-f-row-ignore"
                                                data-id="<?php echo (int) $item['id']; ?>"
                                                title="<?php echo htmlspecialchars(Text::_('COM_MEDIACLEANER_IGNORE')); ?>"
                                            ><?php echo Text::_('COM_MEDIACLEANER_IGNORE'); ?></a>
                                        <?php endif; ?>
                                        <a
                                            href="#"
                                            class="mc-row-icon mc-row-icon-purge mc-f-row-delete"
                                            data-id="<?php echo (int) $item['id']; ?>"
                                            title="<?php echo htmlspecialchars(Text::_('COM_MEDIACLEANER_DELETE')); ?>"
                                        ><?php echo $purgeIcon; ?></a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($this->filterLinked === 'linked') : ?>
                            <td>
                                <?php if (in_array($item['type'], $webpConvertibleTypes, true)) : ?>
                                    <a
                                        href="#"
                                        class="mc-ignore-btn mc-webp-btn mc-f-row-webp"
                                        data-id="<?php echo (int) $item['id']; ?>"
                                        title="<?php echo htmlspecialchars(Text::_('COM_MEDIACLEANER_CONVERT_WEBP')); ?>"
                                    ><?php echo Text::_('COM_MEDIACLEANER_CONVERT_WEBP'); ?></a>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($this->pagination && $this->pagination->total > 0) : ?>
            <div class="mc-pagination">
                <div class="mc-pagination-counter"><?php echo $this->pagination->getPagesCounter(); ?></div>
                <?php echo $this->pagination->getPagesLinks(); ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; // hideContentForRescan ?>
</div>

<input type="hidden" name="task" id="mc-f-task" value="">
<input type="hidden" name="id" id="mc-f-webp-id" value="">
<input type="hidden" name="extension_hint" id="mc-f-extension-hint" value="<?php echo htmlspecialchars($this->extensionHint); ?>">
<input type="hidden" name="system_asset_filter" id="mc-f-system-asset-filter" value="<?php echo htmlspecialchars($this->systemAssetFilter); ?>">
<input type="hidden" name="filter_linked" value="<?php echo htmlspecialchars($this->filterLinked); ?>">
<input type="hidden" name="select_all_matching" id="mc-f-select-all-matching-field" value="0">
<input type="hidden" name="boxchecked" value="0">
<input type="hidden" name="limitstart" id="limitstart" value="<?php echo (int) $this->limitstart; ?>">
<?php echo HTMLHelper::_('form.token'); ?>
</form>

<?php if ($this->lastScan && !empty($this->extensionFilterCounts)) : ?>
    <script>
        (function () {
            var select = document.getElementById('mc-extension-filter-select');

            if (!select) {
                return;
            }

            select.addEventListener('change', function () {
                var url = '<?php echo addslashes(Route::_('index.php?option=com_mediacleaner&view=files&filter_linked=' . $this->filterLinked . '&limitstart=0', false)); ?>';
                window.location = url + '&extension_hint=' + encodeURIComponent(select.value);
            });
        })();
    </script>
<?php endif; ?>

<?php if ($this->lastScan && in_array($this->filterLinked, ['ignored', 'unlinked'], true)) : ?>
    <script>
        (function () {
            var select = document.getElementById('mc-system-asset-filter-select');

            if (!select) {
                return;
            }

            select.addEventListener('change', function () {
                var url = '<?php echo addslashes(Route::_('index.php?option=com_mediacleaner&view=files&filter_linked=' . $this->filterLinked . '&limitstart=0', false)); ?>';
                window.location = url + '&system_asset_filter=' + encodeURIComponent(select.value);
            });
        })();
    </script>
<?php endif; ?>

<?php if ($hasUnlinkedInList && !$this->hideContentForRescan) : ?>
    <script>
        (function () {
            var form        = document.getElementById('adminForm');
            var taskField   = document.getElementById('mc-f-task');
            var checkAll    = document.getElementById('mc-f-check-all');
            var checkboxes  = Array.prototype.slice.call(document.querySelectorAll('.mc-f-checkbox'));
            var bulkSelect  = document.getElementById('mc-f-bulk-select');
            var applyBtn    = document.getElementById('mc-f-bulk-apply');
            var countLabel  = document.getElementById('mc-f-count');
            var selectAllMatching      = document.getElementById('mc-f-select-all-matching');
            var selectAllMatchingField = document.getElementById('mc-f-select-all-matching-field');
            var totalMatchingCount     = <?php echo (int) $this->totalCount; ?>;

            var buttonClassByTask = {
                'files.delete':   'mc-btn-purge',
                'files.ignore':   'mc-btn-bulk-ignore',
                'files.unignore': 'mc-btn-bulk-unignore'
            };

            function updateButtonStyle() {
                Object.keys(buttonClassByTask).forEach(function (task) {
                    applyBtn.classList.remove(buttonClassByTask[task]);
                });
                applyBtn.classList.add(buttonClassByTask[bulkSelect.value] || 'mc-btn-purge');
            }

            function updateState() {
                if (selectAllMatching && selectAllMatching.checked) {
                    countLabel.textContent = totalMatchingCount;
                    applyBtn.disabled = totalMatchingCount === 0;
                    return;
                }

                var checked = checkboxes.filter(function (cb) { return cb.checked; }).length;
                countLabel.textContent = checked;
                applyBtn.disabled = checked === 0;
            }

            checkAll.addEventListener('change', function () {
                checkboxes.forEach(function (cb) { cb.checked = checkAll.checked; });
                updateState();
            });

            checkboxes.forEach(function (cb) {
                cb.addEventListener('change', updateState);
            });

            if (selectAllMatching) {
                selectAllMatching.addEventListener('change', function () {
                    var matching = selectAllMatching.checked;

                    // Per-row/select-all-on-page checkboxes become
                    // meaningless once every matching file (over every
                    // page) is the target - disable them so they can't
                    // be un-ticked to (seemingly) exclude one, and
                    // visibly check every one of them too, so the page
                    // actually looks fully selected rather than relying
                    // on the user to infer that from a disabled,
                    // unchecked row of boxes.
                    checkAll.checked  = matching;
                    checkAll.disabled = matching;
                    checkboxes.forEach(function (cb) {
                        cb.checked  = matching;
                        cb.disabled = matching;
                    });

                    updateState();
                });
            }

            bulkSelect.addEventListener('change', updateButtonStyle);

            applyBtn.addEventListener('click', function () {
                if (applyBtn.disabled) {
                    return;
                }

                var task    = bulkSelect.value;
                var matching = !!(selectAllMatching && selectAllMatching.checked);

                if (matching) {
                    var confirmKey = task === 'files.delete'
                        ? 'COM_MEDIACLEANER_CONFIRM_DELETE_ALL_MATCHING'
                        : (task === 'files.ignore' ? 'COM_MEDIACLEANER_CONFIRM_IGNORE_ALL_MATCHING' : 'COM_MEDIACLEANER_CONFIRM_UNIGNORE_ALL_MATCHING');

                    var confirmTexts = {
                        COM_MEDIACLEANER_CONFIRM_DELETE_ALL_MATCHING: '<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_DELETE_ALL_MATCHING')); ?>',
                        COM_MEDIACLEANER_CONFIRM_IGNORE_ALL_MATCHING: '<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_IGNORE_ALL_MATCHING')); ?>',
                        COM_MEDIACLEANER_CONFIRM_UNIGNORE_ALL_MATCHING: '<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_UNIGNORE_ALL_MATCHING')); ?>'
                    };

                    if (!confirm(confirmTexts[confirmKey].replace('%n', totalMatchingCount))) {
                        return;
                    }

                    selectAllMatchingField.value = '1';
                } else {
                    if (task === 'files.delete' && !confirm('<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_DELETE_SELECTED')); ?>')) {
                        return;
                    }

                    selectAllMatchingField.value = '0';
                }

                taskField.value = task;
                form.submit();
            });

            document.querySelectorAll('.mc-f-row-delete').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();

                    if (!confirm('<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_DELETE_ONE')); ?>')) {
                        return;
                    }

                    checkboxes.forEach(function (cb) { cb.checked = (cb.value === link.dataset.id); });
                    taskField.value = 'files.delete';
                    form.submit();
                });
            });

            document.querySelectorAll('.mc-f-row-ignore').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();

                    checkboxes.forEach(function (cb) { cb.checked = (cb.value === link.dataset.id); });
                    taskField.value = 'files.ignore';
                    form.submit();
                });
            });

            document.querySelectorAll('.mc-f-row-unignore').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();

                    checkboxes.forEach(function (cb) { cb.checked = (cb.value === link.dataset.id); });
                    taskField.value = 'files.unignore';
                    form.submit();
                });
            });

            updateButtonStyle();
            updateState();
        })();
    </script>
<?php endif; ?>

<?php if ($this->filterLinked === 'linked') : ?>
    <script>
        (function () {
            var form      = document.getElementById('adminForm');
            var taskField = document.getElementById('mc-f-task');
            var idField   = document.getElementById('mc-f-webp-id');

            document.querySelectorAll('.mc-f-row-webp').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();

                    idField.value   = link.dataset.id;
                    taskField.value = 'files.webp';
                    form.submit();
                });
            });
        })();
    </script>
<?php endif; ?>

<script>
    (function () {
        var slot = document.getElementById('mc-rescan-overlay-slot');
        var form = document.getElementById('adminForm');

        if (!slot || !form) {
            return;
        }

        // Keep roughly in sync with FilesModel::$haystackMaxSeconds - this
        // is only used to pace the animation, not an actual timeout, so
        // being slightly off has no functional effect.
        var estimatedSeconds = 20;

        // Shared by both the manual "Scan opnieuw uitvoeren" button below
        // and the auto-rescan-after-Options flow further down, so the two
        // always look identical. Returns the overlay element, or null if
        // one is already showing.
        function showRescanOverlay() {
            if (document.getElementById('mc-rescan-overlay')) {
                return null;
            }

            var overlay = document.createElement('div');
            overlay.id = 'mc-rescan-overlay';

            var track = document.createElement('div');
            track.className = 'mc-rescan-bar-track';

            var fill = document.createElement('div');
            fill.className = 'mc-rescan-bar-fill';
            track.appendChild(fill);

            var label = document.createElement('div');
            label.className = 'mc-rescan-label';
            label.textContent = <?php echo json_encode(Text::_('COM_MEDIACLEANER_RESCAN_IN_PROGRESS')); ?>;

            overlay.appendChild(track);
            overlay.appendChild(label);
            slot.appendChild(overlay);

            var start = Date.now();

            // Asymptotic curve: creeps up quickly at first, then slows
            // down and approaches (but deliberately never quite reaches)
            // 92%. We genuinely don't know the real duration in advance -
            // it depends on how much the site's database and file tree
            // have to be searched - so this is a plausible-looking
            // estimate, not a real percentage. The bar disappears on its
            // own once the page navigates away (either the manual button's
            // own form submit below, or the AJAX flow's redirect once the
            // background scan finishes).
            function tick() {
                if (!document.body.contains(overlay)) {
                    return;
                }

                var elapsed = (Date.now() - start) / 1000;
                var pct     = 92 * (1 - Math.exp(-elapsed / (estimatedSeconds * 0.6)));

                fill.style.width = pct.toFixed(1) + '%';

                requestAnimationFrame(tick);
            }

            requestAnimationFrame(tick);

            return overlay;
        }

        // The "Scan opnieuw uitvoeren" button itself is rendered by
        // Joomla core (ToolbarHelper::custom()), not by this template, so
        // it isn't something we can add our own markup around - instead
        // this listens for the click alongside Joomla's own handler
        // (without preventing it) and shows the bar for however long the
        // full-page form submit takes to come back.
        var refreshWrapper = document.getElementById('toolbar-refresh');
        var refreshButton  = refreshWrapper ? refreshWrapper.querySelector('button') : null;

        if (refreshButton) {
            refreshButton.addEventListener('click', function () {
                refreshButton.disabled = true;
                showRescanOverlay();
            });
        }

        // Auto-rescan after returning from the Options screen (see
        // Files/HtmlView::addToolbar(), which puts "mc_auto_rescan=1" on
        // the Options button's return URL) OR after this component was
        // just updated (see HtmlView::consumeNeedsRescanAfterUpdateFlag(),
        // set by script.php's postflight() so the database never lags
        // behind whatever a new version's scan logic changed): land on
        // this page immediately - rather than making the person wait on a
        // blank-looking screen while the scan runs before the redirect
        // even happens - then run the scan here in the background over
        // the same task the manual button above uses, with the bar
        // visible the whole time. Once it finishes, navigate to the clean
        // URL so the page re-renders with the fresh results and the usual
        // "scan done" message.
        //
        // v2.7.18: $hideContentForRescan (both trigger sources combined,
        // computed once server-side in HtmlView::display()) also decided
        // whether the summary/filters/table further up got rendered at
        // all this load - so by the time this script runs, the person is
        // looking at just the page title and this progress bar, never
        // the previous (guaranteed stale) results sitting there looking
        // clickable while a fresh scan is actually still running
        // underneath.
        if (<?php echo $this->hideContentForRescan ? 'true' : 'false'; ?>) {
            var cleanUrl = window.location.href
                .replace(/([?&])mc_auto_rescan=1&?/, '$1')
                .replace(/[?&]$/, '');

            showRescanOverlay();

            var taskField = document.getElementById('mc-f-task');
            taskField.value = 'files.rescan';

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin'
            }).catch(function () {
                // Ignore network errors here - the navigation below still
                // takes the person back to a normal page either way, and a
                // scan that didn't actually run is visible from the "last
                // scan" timestamp rather than silently pretending it
                // worked.
            }).finally(function () {
                window.location.href = cleanUrl;
            });
        }
    })();
</script>
