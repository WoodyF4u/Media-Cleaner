<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/** @var \Joomla\Component\Mediacleaner\Site\View\Files\HtmlView $this */

$itemId = (int) Uri::getInstance()->getVar('Itemid', 0);
$idSuffix = $itemId ? '&Itemid=' . $itemId : '';

/**
 * One "linked" filter link (Alle bestanden / Gekoppelde media / Niet-gekoppelde media).
 *
 * @param   string  $value
 * @param   string  $label
 *
 * @return  string
 */
$filterLink = function (string $value, string $label) use ($idSuffix) {
    $url    = Route::_('index.php?option=com_mediacleaner&view=files&filter_linked=' . $value . $idSuffix, false);
    $active = $this->filterLinked === $value;

    return '<a href="' . htmlspecialchars($url) . '" class="mc-site-filter' . ($active ? ' mc-site-filter-active' : '') . '">'
        . htmlspecialchars($label) . '</a>';
};

/**
 * Human-readable file size.
 *
 * @param   float  $sizeKB
 *
 * @return  string
 */
$formatSize = function (float $sizeKB) {
    return number_format($sizeKB, 1, ',', '.') . ' KB';
};
?>
<div class="mc-site">
    <h2><?php echo Text::_('COM_MEDIACLEANER_FILES'); ?></h2>

    <div class="mc-site-filters">
        <?php echo $filterLink('all', Text::_('COM_MEDIACLEANER_FILTER_ALL')); ?>
        <?php echo $filterLink('linked', Text::_('COM_MEDIACLEANER_FILTER_LINKED')); ?>
        <?php echo $filterLink('unlinked', Text::_('COM_MEDIACLEANER_FILTER_UNLINKED')); ?>
    </div>

    <?php if (empty($this->items)) : ?>
        <p class="mc-site-empty"><?php echo Text::_('COM_MEDIACLEANER_SITE_EMPTY'); ?></p>
    <?php else : ?>
        <form action="<?php echo htmlspecialchars(Route::_('index.php?option=com_mediacleaner&view=files' . $idSuffix, false)); ?>" method="post" name="mcSiteForm" id="mcSiteForm">
            <table class="mc-site-table">
                <thead>
                    <tr>
                        <?php if ($this->canIgnore || $this->canDelete) : ?>
                            <th style="width:24px;"></th>
                        <?php endif; ?>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_NAME'); ?></th>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_SIZE'); ?></th>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_LOCATION'); ?></th>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_TYPE'); ?></th>
                        <th><?php echo Text::_('COM_MEDIACLEANER_COL_LINKED'); ?></th>
                        <?php if ($this->canIgnore || $this->canDelete) : ?>
                            <th><?php echo Text::_('COM_MEDIACLEANER_COL_ACTION'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $item) : ?>
                        <tr>
                            <?php if ($this->canIgnore || $this->canDelete) : ?>
                                <td>
                                    <input type="checkbox" class="mc-site-checkbox" name="cid[]" value="<?php echo (int) $item['id']; ?>">
                                </td>
                            <?php endif; ?>
                            <td class="mc-site-name-cell">
                                <?php if (!empty($item['url'])) : ?>
                                    <a href="<?php echo htmlspecialchars($item['url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($item['name']); ?></a>
                                <?php else : ?>
                                    <?php echo htmlspecialchars($item['name']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $formatSize($item['sizeKB']); ?></td>
                            <td class="mc-site-path"><?php echo htmlspecialchars($item['path']); ?></td>
                            <td><?php echo htmlspecialchars(strtoupper($item['type'])); ?></td>
                            <td>
                                <?php if (($item['linkConfidence'] ?? 'none') === 'confirmed') : ?>
                                    <span class="mc-site-badge mc-site-badge-linked"><?php echo Text::_('COM_MEDIACLEANER_LINKED_YES'); ?></span>
                                <?php elseif (($item['linkConfidence'] ?? 'none') === 'probable') : ?>
                                    <span class="mc-site-badge mc-site-badge-probable" title="<?php echo htmlspecialchars(Text::_('COM_MEDIACLEANER_LINKED_PROBABLE_HINT')); ?>"><?php echo Text::_('COM_MEDIACLEANER_LINKED_PROBABLE'); ?></span>
                                <?php else : ?>
                                    <span class="mc-site-badge mc-site-badge-unlinked"><?php echo Text::_('COM_MEDIACLEANER_LINKED_NO'); ?></span>
                                <?php endif; ?>
                            </td>
                            <?php if ($this->canIgnore || $this->canDelete) : ?>
                                <td>
                                    <?php if ($this->canIgnore && !$item['linked']) : ?>
                                        <?php if ($item['ignored']) : ?>
                                            <button type="button" class="mc-site-btn mc-site-btn-info mc-site-row-unignore" data-id="<?php echo (int) $item['id']; ?>"><?php echo Text::_('COM_MEDIACLEANER_UNIGNORE'); ?></button>
                                        <?php else : ?>
                                            <button type="button" class="mc-site-btn mc-site-btn-info mc-site-row-ignore" data-id="<?php echo (int) $item['id']; ?>"><?php echo Text::_('COM_MEDIACLEANER_IGNORE'); ?></button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($this->canDelete && !$item['linked']) : ?>
                                        <button type="button" class="mc-site-btn mc-site-btn-danger mc-site-row-delete" data-id="<?php echo (int) $item['id']; ?>"><?php echo Text::_('COM_MEDIACLEANER_DELETE'); ?></button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($this->canIgnore || $this->canDelete) : ?>
                <div class="mc-site-actions" style="margin-top:14px;">
                    <label for="mc-site-bulk-select"><?php echo Text::_('COM_MEDIACLEANER_BULK_ACTION_LABEL'); ?></label>
                    <select id="mc-site-bulk-select" class="mc-site-bulk-select">
                        <?php if ($this->canDelete) : ?>
                            <option value="files.delete"><?php echo Text::_('COM_MEDIACLEANER_DELETE'); ?></option>
                        <?php endif; ?>
                        <?php if ($this->canIgnore) : ?>
                            <option value="files.ignore"><?php echo Text::_('COM_MEDIACLEANER_IGNORE'); ?></option>
                            <option value="files.unignore"><?php echo Text::_('COM_MEDIACLEANER_UNIGNORE'); ?></option>
                        <?php endif; ?>
                    </select>
                    <button type="button" id="mc-site-bulk-apply" class="mc-site-btn mc-site-btn-danger" disabled><?php echo Text::_('COM_MEDIACLEANER_BULK_APPLY'); ?></button>
                </div>
            <?php endif; ?>

            <input type="hidden" name="task" id="mc-site-task" value="">
            <input type="hidden" name="Itemid" value="<?php echo $itemId; ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>

        <?php if ($this->canIgnore || $this->canDelete) : ?>
            <script>
                (function () {
                    var form       = document.getElementById('mcSiteForm');
                    var taskField  = document.getElementById('mc-site-task');
                    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.mc-site-checkbox'));
                    var bulkSelect = document.getElementById('mc-site-bulk-select');
                    var applyBtn   = document.getElementById('mc-site-bulk-apply');

                    function updateApplyState() {
                        applyBtn.disabled = !checkboxes.some(function (cb) { return cb.checked; });
                    }

                    checkboxes.forEach(function (cb) {
                        cb.addEventListener('change', updateApplyState);
                    });

                    if (bulkSelect) {
                        applyBtn.addEventListener('click', function () {
                            if (applyBtn.disabled) {
                                return;
                            }

                            if (bulkSelect.value === 'files.delete' && !confirm('<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_DELETE_SELECTED')); ?>')) {
                                return;
                            }

                            taskField.value = bulkSelect.value;
                            form.submit();
                        });
                    }

                    document.querySelectorAll('.mc-site-row-delete').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            if (!confirm('<?php echo addslashes(Text::_('COM_MEDIACLEANER_CONFIRM_DELETE_ONE')); ?>')) {
                                return;
                            }

                            checkboxes.forEach(function (cb) { cb.checked = (cb.value === btn.dataset.id); });
                            taskField.value = 'files.delete';
                            form.submit();
                        });
                    });

                    document.querySelectorAll('.mc-site-row-ignore').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            checkboxes.forEach(function (cb) { cb.checked = (cb.value === btn.dataset.id); });
                            taskField.value = 'files.ignore';
                            form.submit();
                        });
                    });

                    document.querySelectorAll('.mc-site-row-unignore').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            checkboxes.forEach(function (cb) { cb.checked = (cb.value === btn.dataset.id); });
                            taskField.value = 'files.unignore';
                            form.submit();
                        });
                    });

                    updateApplyState();
                })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
