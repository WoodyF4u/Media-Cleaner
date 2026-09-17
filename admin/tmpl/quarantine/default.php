<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Joomla\Component\Mediacleaner\Administrator\View\Quarantine\HtmlView $this */

// Plain inline SVGs (currentColor fill/stroke) instead of Unicode glyphs
// for the restore/purge icons - v2.3.7 fix: the Unicode arrow (&#8634;)
// and wastebasket (&#128465;) characters were rendering via the
// browser's colour-emoji fallback font on at least one real site
// (Wouter's screenshot showed a blue arrow and a full-colour trash can,
// completely ignoring the buttons' `color: #fff` CSS), making the
// restore icon nearly invisible on its green background. An inline SVG
// with `stroke="currentColor"`/`fill="currentColor"` always follows the
// element's CSS text colour, with no font/glyph rendering ambiguity.
$restoreIcon = '<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
    . '<path d="M3.5 8a4.5 4.5 0 1 1 1.5 3.35" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
    . '<path d="M2.6 5.1 3.4 8.3l3.1-1.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>'
    . '</svg>';
$purgeIcon = '<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
    . '<path d="M3 4.6h10M6.3 4.6V3.2a1 1 0 0 1 1-1h1.4a1 1 0 0 1 1 1v1.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>'
    . '<path d="M4.4 4.6 5 13a1 1 0 0 0 1 .9h4a1 1 0 0 0 1-.9l.6-8.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>'
    . '<path d="M6.6 7.2v4M9.4 7.2v4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>'
    . '</svg>';

// v2.4.0: "Voorbeeld" thumbnail column, matching the Files view. Unlike
// there, a quarantined file's `<img src>` can't point at the file
// directly - the quarantine folder is deliberately not web-accessible
// (see COM_MEDIACLEANER_QUARANTINE_NOTICE below) - so it's routed
// through QuarantineController::thumb(), which re-validates the file's
// location and type before ever reading it from disk. Non-image types
// fall back to the same colour-coded badge the Files view uses.
$videoExtensions = ['mp4', 'webm', 'ogv'];
$audioExtensions = ['mp3', 'wav', 'm4a', 'aac', 'ogg'];

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

    return '<span class="mc-badge mc-badge-doc">' . htmlspecialchars(strtoupper($type)) . '</span>';
};
?>
<div class="com-mediacleaner-quarantine">
    <h2 class="mc-page-title">Media Cleaner</h2>

    <div class="mc-notice">
        <?php echo Text::_('COM_MEDIACLEANER_QUARANTINE_NOTICE'); ?>
    </div>

    <p class="mc-summary mc-summary-standalone">
        <?php echo Text::sprintf('COM_MEDIACLEANER_QUARANTINE_SUMMARY', count($this->items)); ?>
    </p>

    <?php if (empty($this->items)) : ?>
        <div class="mc-empty">
            <?php echo Text::_('COM_MEDIACLEANER_QUARANTINE_EMPTY'); ?>
        </div>
    <?php else : ?>
        <form
            action="<?php echo htmlspecialchars(Route::_('index.php?option=com_mediacleaner&view=quarantine', false)); ?>"
            method="post"
            name="adminForm"
            id="adminForm"
        >
            <div class="mc-actions-bar">
                <button type="button" id="mc-q-restore" class="mc-btn mc-btn-restore" disabled>
                    <?php echo $restoreIcon; ?> <?php echo Text::_('COM_MEDIACLEANER_RESTORE'); ?>
                </button>
                <button type="button" id="mc-q-purge" class="mc-btn mc-btn-purge" disabled>
                    <?php echo $purgeIcon; ?> <?php echo Text::_('COM_MEDIACLEANER_PURGE'); ?>
                </button>
                <span class="mc-selected-count"><span id="mc-q-count">0</span> <?php echo Text::_('COM_MEDIACLEANER_SELECTED_SUFFIX'); ?></span>
            </div>

            <table class="mc-table table table-striped">
                <thead>
                    <tr>
                        <th style="width:26px;">
                            <input type="checkbox" id="mc-q-check-all" class="form-check-input">
                        </th>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_THUMB'); ?></th>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_NAME'); ?></th>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_SIZE'); ?></th>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_ORIGINAL_LOCATION'); ?></th>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_TYPE'); ?></th>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_DELETED_AT'); ?></th>
                        <th style="width:76px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $item) : ?>
                        <tr>
                            <td>
                                <input
                                    type="checkbox"
                                    class="mc-q-checkbox form-check-input"
                                    name="cid[]"
                                    value="<?php echo (int) $item['id']; ?>"
                                    id="mc-q-cb-<?php echo (int) $item['id']; ?>"
                                >
                            </td>
                            <td class="mc-thumb-cell">
                                <?php $previewableTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp']; ?>
                                <?php if (in_array(strtolower($item['type']), $previewableTypes, true)) : ?>
                                    <img
                                        src="<?php echo htmlspecialchars(Route::_('index.php?option=com_mediacleaner&task=quarantine.thumb&id=' . (int) $item['id'], false)); ?>"
                                        alt="<?php echo htmlspecialchars($item['original_name']); ?>"
                                        loading="lazy"
                                        onerror="this.style.display='none';"
                                    >
                                <?php else : ?>
                                    <?php echo $badge($item['type']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($item['original_name']); ?></td>
                            <td><?php echo number_format($item['sizeKB'], 1, ',', '.'); ?> KB</td>
                            <td class="mc-path"><?php echo htmlspecialchars($item['original_path']); ?></td>
                            <td><?php echo htmlspecialchars(strtoupper($item['type'])); ?></td>
                            <td><?php echo HTMLHelper::_('date', $item['deleted_at'], Text::_('DATE_FORMAT_LC5')); ?></td>
                            <td>
                                <div class="mc-row-actions">
                                    <a
                                        href="#"
                                        class="mc-row-icon mc-row-icon-restore mc-q-row-restore"
                                        data-id="<?php echo (int) $item['id']; ?>"
                                        title="<?php echo htmlspecialchars(Text::_('COM_MEDIACLEANER_RESTORE')); ?>"
                                    ><?php echo $restoreIcon; ?></a>
                                    <a
                                        href="#"
                                        class="mc-row-icon mc-row-icon-purge mc-q-row-purge"
                                        data-id="<?php echo (int) $item['id']; ?>"
                                        title="<?php echo htmlspecialchars(Text::_('COM_MEDIACLEANER_PURGE')); ?>"
                                    ><?php echo $purgeIcon; ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <input type="hidden" name="task" id="mc-q-task" value="">
            <input type="hidden" name="boxchecked" value="0">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>

        <script>
            (function () {
                var form       = document.getElementById('adminForm');
                var taskField  = document.getElementById('mc-q-task');
                var checkAll   = document.getElementById('mc-q-check-all');
                var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.mc-q-checkbox'));
                var restoreBtn = document.getElementById('mc-q-restore');
                var purgeBtn   = document.getElementById('mc-q-purge');
                var countLabel = document.getElementById('mc-q-count');

                function updateState() {
                    var checked = checkboxes.filter(function (cb) { return cb.checked; }).length;
                    countLabel.textContent = checked;
                    restoreBtn.disabled = checked === 0;
                    purgeBtn.disabled   = checked === 0;
                }

                checkAll.addEventListener('change', function () {
                    checkboxes.forEach(function (cb) { cb.checked = checkAll.checked; });
                    updateState();
                });

                checkboxes.forEach(function (cb) {
                    cb.addEventListener('change', updateState);
                });

                restoreBtn.addEventListener('click', function () {
                    if (restoreBtn.disabled) {
                        return;
                    }

                    if (!confirm('<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_RESTORE_SELECTED')); ?>')) {
                        return;
                    }

                    taskField.value = 'quarantine.restore';
                    form.submit();
                });

                purgeBtn.addEventListener('click', function () {
                    if (purgeBtn.disabled) {
                        return;
                    }

                    if (!confirm('<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_PURGE_SELECTED')); ?>')) {
                        return;
                    }

                    taskField.value = 'quarantine.purge';
                    form.submit();
                });

                document.querySelectorAll('.mc-q-row-restore').forEach(function (link) {
                    link.addEventListener('click', function (event) {
                        event.preventDefault();

                        if (!confirm('<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_RESTORE_ONE')); ?>')) {
                            return;
                        }

                        checkboxes.forEach(function (cb) { cb.checked = (cb.value === link.dataset.id); });
                        taskField.value = 'quarantine.restore';
                        form.submit();
                    });
                });

                document.querySelectorAll('.mc-q-row-purge').forEach(function (link) {
                    link.addEventListener('click', function (event) {
                        event.preventDefault();

                        if (!confirm('<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_PURGE_ONE')); ?>')) {
                            return;
                        }

                        checkboxes.forEach(function (cb) { cb.checked = (cb.value === link.dataset.id); });
                        taskField.value = 'quarantine.purge';
                        form.submit();
                    });
                });

                updateState();
            })();
        </script>
    <?php endif; ?>
</div>
