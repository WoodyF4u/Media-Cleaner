<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Mediacleaner\Administrator\Helper\TabsHelper;

/** @var \Joomla\Component\Mediacleaner\Administrator\View\Links\HtmlView $this */

$h       = static function ($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
};
$view    = 'index.php?option=com_mediacleaner&view=links';
$action  = Route::_($view, false);
$task    = static function ($name) {
    return Route::_('index.php?option=com_mediacleaner&task=links.' . $name, false);
};
$root    = rtrim(Uri::root(), '/') . '/';
$webRoot = rtrim(Uri::getInstance(Uri::root())->toString(['scheme', 'host', 'port']), '/') . '/';
$isImage = static function ($path) {
    return (bool) preg_match('~\.(?:jpe?g|png|gif|webp|avif|svg|bmp)$~i', (string) $path);
};

$filterLink = function ($filter, $key, $count) use ($h, $view) {
    $url = Route::_($view . '&filter=' . $filter, false);

    return '<a href="' . $h($url) . '" class="mc-filter-link' . ($this->filter === $filter ? ' mc-filter-active' : '') . '">'
        . $h(Text::_($key)) . ' <span class="mc-filter-count">' . (int) $count . '</span></a>';
};

$stateLabel = static function ($state) {
    if ($state === null || $state === '' || (int) $state === 1) {
        return '';
    }

    switch ((int) $state) {
        case 0:
            return Text::_('COM_MEDIACLEANER_LINKS_STATE_UNPUBLISHED');
        case 2:
            return Text::_('COM_MEDIACLEANER_LINKS_STATE_ARCHIVED');
        case -2:
            return Text::_('COM_MEDIACLEANER_LINKS_STATE_TRASHED');
    }

    return '';
};

$locationKey = static function (array $item) {
    return $item['table_name'] . "\0" . $item['pk_value'] . "\0" . ($item['pk_column'] === '' ? $item['column_name'] : '');
};

$typeLabel = static function ($type) {
    return $type === '' ? '' : Text::_('COM_MEDIACLEANER_LINKS_TYPE_' . strtoupper($type));
};

// What the reference is in, always with its kind in front ("Artikel:
// Dinsdag 23 september"), so a title that is a date is never read as the
// date of a check. A custom field's title already says what it is; a
// table without a known kind is named as a table.
$locationTitle = static function (array $item) use ($typeLabel) {
    if ($item['location_title'] !== '') {
        $type = $item['location_type'] ?? '';

        return $type === '' || $type === 'field'
            ? $item['location_title']
            : Text::sprintf('COM_MEDIACLEANER_LINKS_LOCATION_TYPED', $typeLabel($type), trim($item['location_title']));
    }

    $table = preg_replace('~^[^_]+_~', '#__', $item['table_name']);

    return $item['pk_column'] !== ''
        ? Text::sprintf('COM_MEDIACLEANER_LINKS_LOCATION_ROW', $table, $item['pk_value'], $item['column_name'])
        : Text::sprintf('COM_MEDIACLEANER_LINKS_LOCATION_COLUMN', $table, $item['column_name']);
};

$elementLabel = static function (array $item) {
    if ($item['element'] === 'a') {
        return Text::_((int) $item['popup'] ? 'COM_MEDIACLEANER_LINKS_EL_POPUP' : 'COM_MEDIACLEANER_LINKS_EL_LINK');
    }

    if ($item['element'] === 'img') {
        return Text::_('COM_MEDIACLEANER_LINKS_EL_IMG');
    }

    if ($item['element'] === 'css') {
        return Text::_('COM_MEDIACLEANER_LINKS_EL_CSS');
    }

    if ($item['element'] === 'value') {
        return Text::_('COM_MEDIACLEANER_LINKS_EL_VALUE');
    }

    return $item['element'] !== '' ? '<' . $item['element'] . '>' : Text::_('COM_MEDIACLEANER_LINKS_EL_TEXT');
};

$options = function (array $item) use ($h, $root, $isImage) {
    $selected = (int) $item['best'] >= 0 ? 's' . (int) $item['best'] : '';
    $html     = '<option value=""' . ($selected === '' ? ' selected' : '') . '>' . $h(Text::_('COM_MEDIACLEANER_LINKS_CHOOSE')) . '</option>';

    if ($item['suggestions']) {
        $html .= '<optgroup label="' . $h(Text::_('COM_MEDIACLEANER_LINKS_GROUP_POINT')) . '">';

        foreach ($item['suggestions'] as $i => $s) {
            $value = 's' . $i;
            $html .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '')
                . ($isImage($s['path']) ? ' data-preview="' . $h($root . $s['path']) . '"' : '') . '>'
                . $h(Text::sprintf('COM_MEDIACLEANER_LINKS_REASON_' . strtoupper($s['reason']), $s['path']))
                . '</option>';
        }

        $html .= '</optgroup>';
    }

    $html .= '<optgroup label="' . $h(Text::_('COM_MEDIACLEANER_LINKS_GROUP_OTHER')) . '">';

    if ($item['kind'] === 'outside') {
        $html .= '<option value="copy">' . $h(Text::_('COM_MEDIACLEANER_LINKS_DO_COPY')) . '</option>';
    }

    if ($item['element'] === 'a') {
        $html .= '<option value="unwrap">' . $h(Text::_((int) $item['popup'] ? 'COM_MEDIACLEANER_LINKS_DO_UNWRAP_POPUP' : 'COM_MEDIACLEANER_LINKS_DO_UNWRAP')) . '</option>';
    }

    if ($item['element'] === 'img') {
        $html .= '<option value="remove">' . $h(Text::_('COM_MEDIACLEANER_LINKS_DO_REMOVE')) . '</option>';
    }

    $html .= '<option value="custom">' . $h(Text::_('COM_MEDIACLEANER_LINKS_DO_CUSTOM')) . '</option>';

    return $html . '</optgroup>';
};

/*
 * Page navigation as in Joomla's own lists: first, previous, up to ten
 * page numbers around the current page, next, last - plus "Pagina x van
 * y". $param is the request variable holding the first row of the page.
 */
$pager = static function ($total, $start, $limit, $param, $anchor = '') use ($h, $view) {
    $pages = (int) ceil($total / $limit);

    if ($pages <= 1) {
        return '';
    }

    $current = (int) floor($start / $limit) + 1;
    $first   = max(1, min($current - 4, $pages - 9));
    $last    = min($pages, $first + 9);
    $url     = static function ($page) use ($h, $view, $param, $limit, $anchor) {
        return $h(Route::_($view . '&' . $param . '=' . (($page - 1) * $limit), false) . $anchor);
    };
    $item    = static function ($page, $label, $aria, $enabled, $active = false) use ($h, $url) {
        if ($active) {
            return '<li class="page-item active"><span class="page-link" aria-current="page">' . $label . '</span></li>';
        }

        if (!$enabled) {
            return '<li class="page-item disabled"><span class="page-link" aria-hidden="true">' . $label . '</span></li>';
        }

        return '<li class="page-item"><a class="page-link" href="' . $url($page) . '" aria-label="' . $h($aria) . '">' . $label . '</a></li>';
    };

    $html = '<div class="mc-pagination"><span class="mc-pagination-counter">'
        . $h(Text::sprintf('COM_MEDIACLEANER_COMPRESS_PAGE', $current, $pages, $total))
        . '</span><nav aria-label="' . $h(Text::_('JLIB_HTML_PAGINATION')) . '"><ul class="pagination">'
        . $item(1, '&laquo;', Text::_('JLIB_HTML_START'), $current > 1)
        . $item($current - 1, '&lsaquo;', Text::_('JPREV'), $current > 1);

    for ($p = $first; $p <= $last; $p++) {
        $html .= $item($p, (string) $p, Text::sprintf('JLIB_HTML_GOTO_PAGE', $p), true, $p === $current);
    }

    return $html
        . $item($current + 1, '&rsaquo;', Text::_('JNEXT'), $current < $pages)
        . $item($pages, '&raquo;', Text::_('JLIB_HTML_END'), $current < $pages)
        . '</ul></nav></div>';
};

$open = $this->counts[''];
?>
<div class="com-mediacleaner-files com-mediacleaner-links">
    <div id="mc-rescan-overlay-slot"></div>

    <h2 class="mc-page-title">Media Cleaner</h2>

    <?php echo TabsHelper::render('links'); ?>

    <div class="mc-summary-row">
        <p class="mc-summary">
            <?php if ($this->checkedAt === null) : ?>
                <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_INTRO')); ?>
            <?php elseif ($open > 0) : ?>
                <?php echo $h(Text::plural('COM_MEDIACLEANER_LINKS_SUMMARY', $open)); ?>
            <?php else : ?>
                <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_SUMMARY_NONE')); ?>
            <?php endif; ?>
        </p>

        <?php if ($this->componentVersion !== '') : ?>
            <span class="mc-version-label">Media Cleaner &middot; v<?php echo $h($this->componentVersion); ?></span>
        <?php endif; ?>
    </div>

    <div class="mc-compress-box">
        <p class="mc-links-explain"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_EXPLAIN')); ?></p>
        <p class="mc-links-start">
            <button type="button" class="btn btn-primary" id="mc-links-check"><?php echo $h(Text::_($this->checkedAt === null ? 'COM_MEDIACLEANER_LINKS_CHECK' : 'COM_MEDIACLEANER_LINKS_CHECK_AGAIN')); ?></button>
            <?php if ($this->checkedAt !== null) : ?>
                <span class="mc-compress-hint"><?php echo $h(Text::sprintf('COM_MEDIACLEANER_LINKS_CHECKED', HTMLHelper::_('date', $this->checkedAt, Text::_('DATE_FORMAT_LC5')))); ?></span>
            <?php endif; ?>
        </p>
        <?php if ($this->wasCut) : ?>
            <div class="mc-notice"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_CUT')); ?></div>
        <?php endif; ?>
    </div>

    <?php if ($this->checkedAt !== null) : ?>
        <div class="mc-filters">
            <?php echo $filterLink('', 'COM_MEDIACLEANER_LINKS_FILTER_ALL', $this->counts['']); ?>
            <?php echo $filterLink('missing', 'COM_MEDIACLEANER_LINKS_FILTER_MISSING', $this->counts['missing']); ?>
            <?php echo $filterLink('outside', 'COM_MEDIACLEANER_LINKS_FILTER_OUTSIDE', $this->counts['outside']); ?>
            <?php echo $filterLink('ignored', 'COM_MEDIACLEANER_LINKS_FILTER_IGNORED', $this->counts['ignored']); ?>
            <?php if ($this->showTrashed || $this->counts['trashed'] > 0) : ?>
                <a class="mc-links-trashed-toggle" href="<?php echo $h(Route::_($view . '&trashed=' . ($this->showTrashed ? 0 : 1), false)); ?>">
                    <?php echo $h($this->showTrashed ? Text::_('COM_MEDIACLEANER_LINKS_HIDE_TRASHED') : Text::plural('COM_MEDIACLEANER_LINKS_SHOW_TRASHED', $this->counts['trashed'])); ?>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($this->filter === 'outside') : ?>
            <div class="mc-notice"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_OUTSIDE_NOTE')); ?></div>
        <?php endif; ?>

        <?php if (!$this->list['items']) : ?>
            <div class="alert alert-success"><?php echo $h(Text::_($this->filter === 'ignored' ? 'COM_MEDIACLEANER_LINKS_NONE_IGNORED' : 'COM_MEDIACLEANER_LINKS_NONE')); ?></div>
        <?php else : ?>
            <form action="<?php echo $h($action); ?>" method="post" id="mc-links-form">
                <div class="mc-actions-bar">
                    <?php if ($this->filter !== 'ignored') : ?>
                        <button type="submit" class="mc-btn mc-btn-restore mc-links-bulk" formaction="<?php echo $h($task('fixSelected')); ?>" disabled
                            onclick="return confirm(<?php echo $h(json_encode(Text::_('COM_MEDIACLEANER_LINKS_FIX_CONFIRM'))); ?>);">
                            <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_FIX_SELECTED')); ?>
                        </button>
                        <button type="submit" class="mc-btn mc-btn-bulk-ignore mc-links-bulk" formaction="<?php echo $h($task('ignore')); ?>" disabled>
                            <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_IGNORE')); ?>
                        </button>
                        <button type="button" class="mc-btn" id="mc-links-pick-suggested"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_PICK_SUGGESTED')); ?></button>
                    <?php else : ?>
                        <button type="submit" class="mc-btn mc-btn-bulk-unignore mc-links-bulk" formaction="<?php echo $h($task('unignore')); ?>" disabled>
                            <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_UNIGNORE')); ?>
                        </button>
                    <?php endif; ?>
                    <span class="mc-selected-count"><span id="mc-links-count">0</span> <?php echo $h(Text::_('COM_MEDIACLEANER_SELECTED_SUFFIX')); ?></span>
                </div>

                <div class="table-responsive">
                    <table class="table mc-links-table">
                        <thead>
                            <tr>
                                <th scope="col" class="w-1"><input type="checkbox" id="mc-links-check-all" aria-label="<?php echo $h(Text::_('JGLOBAL_CHECK_ALL')); ?>"></th>
                                <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_COL_LINK')); ?></th>
                                <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_COL_FIX')); ?></th>
                                <th scope="col" class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $previous = null; ?>
                            <?php foreach ($this->list['items'] as $item) : ?>
                                <?php $key = $locationKey($item); ?>
                                <?php if ($key !== $previous) : ?>
                                    <?php $previous = $key; ?>
                                    <tr class="mc-links-location">
                                        <th colspan="4" scope="rowgroup">
                                            <?php if ($item['location_url'] !== '') : ?>
                                                <a href="<?php echo $h(Route::_($item['location_url'], false)); ?>" target="_blank" rel="noopener"><?php echo $h($locationTitle($item)); ?></a>
                                            <?php else : ?>
                                                <?php echo $h($locationTitle($item)); ?>
                                            <?php endif; ?>
                                            <?php if ($stateLabel($item['item_state']) !== '') : ?>
                                                <span class="mc-links-state"><?php echo $h($stateLabel($item['item_state'])); ?></span>
                                            <?php endif; ?>
                                            <?php if ($item['location_url'] !== '') : ?>
                                                <a class="mc-links-edit" href="<?php echo $h(Route::_($item['location_url'], false)); ?>" target="_blank" rel="noopener"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_EDIT')); ?></a>
                                            <?php endif; ?>
                                        </th>
                                    </tr>
                                <?php endif; ?>
                                <tr class="mc-links-row" data-id="<?php echo (int) $item['id']; ?>">
                                    <td><input type="checkbox" class="mc-links-check" name="cid[]" value="<?php echo (int) $item['id']; ?>"></td>
                                    <td class="mc-links-ref">
                                        <?php if ($item['inner_path'] !== '' && $isImage($item['inner_path'])) : ?>
                                            <img class="mc-links-thumb" src="<?php echo $h($root . $item['inner_path']); ?>" alt="" loading="lazy">
                                        <?php endif; ?>
                                        <code class="mc-links-url"><?php echo $h($item['url']); ?></code>
                                        <div class="mc-links-tags">
                                            <span class="mc-links-kind mc-links-kind-<?php echo $h($item['kind']); ?>"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_KIND_' . strtoupper($item['kind']))); ?></span>
                                            <span class="mc-links-el"><?php echo $h($elementLabel($item)); ?></span>
                                            <?php if ((int) $item['occurrences'] > 1) : ?>
                                                <span class="mc-links-el"><?php echo $h(Text::sprintf('COM_MEDIACLEANER_LINKS_TIMES', (int) $item['occurrences'])); ?></span>
                                            <?php endif; ?>
                                            <?php if ($item['kind'] === 'outside') : ?>
                                                <a class="mc-links-el" href="<?php echo $h($webRoot . $item['path']); ?>" target="_blank" rel="noopener"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_OPEN_OUTSIDE')); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="mc-links-fix">
                                        <?php if ($this->filter !== 'ignored') : ?>
                                            <div class="mc-links-fix-line">
                                                <select class="form-select form-select-sm mc-links-choice" name="choice[<?php echo (int) $item['id']; ?>]" aria-label="<?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_COL_FIX')); ?>">
                                                    <?php echo $options($item); ?>
                                                </select>
                                                <img class="mc-links-preview" alt="" hidden>
                                            </div>
                                            <input type="text" class="form-control form-control-sm mc-links-custom" name="custom[<?php echo (int) $item['id']; ?>]"
                                                placeholder="<?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_CUSTOM_PLACEHOLDER')); ?>" hidden>
                                        <?php else : ?>
                                            <span class="mc-compress-hint"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_IGNORED_HINT')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="mc-nowrap mc-links-buttons">
                                        <?php if ($this->filter !== 'ignored') : ?>
                                            <button type="submit" class="mc-btn mc-btn-restore mc-links-fix-one" name="fix_id" value="<?php echo (int) $item['id']; ?>" formaction="<?php echo $h($task('fix')); ?>">
                                                <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_FIX')); ?>
                                            </button>
                                            <button type="submit" class="mc-ignore-btn" name="one_id" value="<?php echo (int) $item['id']; ?>" formaction="<?php echo $h($task('ignore')); ?>">
                                                <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_IGNORE')); ?>
                                            </button>
                                        <?php else : ?>
                                            <button type="submit" class="mc-unignore-btn" name="one_id" value="<?php echo (int) $item['id']; ?>" formaction="<?php echo $h($task('unignore')); ?>">
                                                <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_UNIGNORE')); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php echo HTMLHelper::_('form.token'); ?>
            </form>

            <?php echo $pager($this->list['total'], $this->start, $this->limit, 'start'); ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($this->fixes['total'] > 0) : ?>
        <div class="mc-compress-box" id="mc-links-fixes">
            <h3 class="mc-compress-heading"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_FIXES')); ?></h3>
            <form action="<?php echo $h($task('undo')); ?>" method="post">
                <div class="table-responsive">
                    <table class="table mc-compress-log">
                        <thead>
                            <tr>
                                <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_COL_WHERE')); ?></th>
                                <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_COL_CHANGE')); ?></th>
                                <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_COL_DATE')); ?></th>
                                <th scope="col" class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->fixes['items'] as $fix) : ?>
                                <tr<?php echo $fix['undone'] !== null ? ' class="mc-links-undone"' : ''; ?>>
                                    <td>
                                        <?php $title = $fix['location_title'] !== '' ? $locationTitle($fix + ['pk_column' => 'id']) : preg_replace('~^[^_]+_~', '#__', $fix['table_name']) . ' #' . $fix['pk_value']; ?>
                                        <?php if ($fix['location_url'] !== '') : ?>
                                            <a href="<?php echo $h(Route::_($fix['location_url'], false)); ?>" target="_blank" rel="noopener"><?php echo $h($title); ?></a>
                                        <?php else : ?>
                                            <?php echo $h($title); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="mc-compress-path">
                                        <code><?php echo $h($fix['url']); ?></code><br>
                                        <span class="mc-compress-arrow">&rarr;
                                            <?php if ($fix['action'] === 'unwrap') : ?>
                                                <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_DID_UNWRAP')); ?>
                                            <?php elseif ($fix['action'] === 'remove') : ?>
                                                <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_DID_REMOVE')); ?>
                                            <?php else : ?>
                                                <code><?php echo $h($fix['new_url']); ?></code>
                                                <?php if ($fix['action'] === 'copy') : ?>
                                                    <small>(<?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_DID_COPY')); ?>)</small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="mc-nowrap">
                                        <?php echo $h(HTMLHelper::_('date', $fix['created'], Text::_('DATE_FORMAT_LC5'))); ?>
                                        <?php if ($fix['undone'] !== null) : ?>
                                            <br><small><?php echo $h(Text::sprintf('COM_MEDIACLEANER_LINKS_UNDONE_AT', HTMLHelper::_('date', $fix['undone'], Text::_('DATE_FORMAT_LC5')))); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="mc-nowrap">
                                        <?php if ($fix['undone'] === null) : ?>
                                            <button type="submit" class="mc-btn" name="undo_id" value="<?php echo (int) $fix['id']; ?>"
                                                onclick="return confirm(<?php echo $h(json_encode(Text::_('COM_MEDIACLEANER_LINKS_UNDO_CONFIRM'))); ?>);">
                                                <?php echo $h(Text::_('COM_MEDIACLEANER_LINKS_UNDO')); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>

            <?php echo $pager($this->fixes['total'], $this->fixStart, $this->fixLimit, 'fstart', '#mc-links-fixes'); ?>
        </div>
    <?php endif; ?>
</div>

<script>
    (function () {
        var form = document.getElementById('mc-links-form');

        if (!form) {
            return;
        }

        var checks   = form.querySelectorAll('.mc-links-check');
        var checkAll = document.getElementById('mc-links-check-all');
        var bulk     = form.querySelectorAll('.mc-links-bulk');
        var counter  = document.getElementById('mc-links-count');
        var pick     = document.getElementById('mc-links-pick-suggested');

        function refresh() {
            var n = 0;

            checks.forEach(function (c) { if (c.checked) { n++; } });

            if (counter) { counter.textContent = n; }
            bulk.forEach(function (b) { b.disabled = n === 0; });
        }

        checks.forEach(function (c) { c.addEventListener('change', refresh); });

        if (checkAll) {
            checkAll.addEventListener('change', function () {
                checks.forEach(function (c) { c.checked = checkAll.checked; });
                refresh();
            });
        }

        // Check every row that has a repair chosen (preselected or picked).
        if (pick) {
            pick.addEventListener('click', function () {
                form.querySelectorAll('.mc-links-row').forEach(function (row) {
                    var select = row.querySelector('.mc-links-choice');
                    var check  = row.querySelector('.mc-links-check');

                    if (select && check) {
                        check.checked = select.value !== '' && select.value !== 'custom';
                    }
                });
                refresh();
            });
        }

        form.querySelectorAll('.mc-links-row').forEach(function (row) {
            var select  = row.querySelector('.mc-links-choice');
            var custom  = row.querySelector('.mc-links-custom');
            var preview = row.querySelector('.mc-links-preview');
            var button  = row.querySelector('.mc-links-fix-one');

            if (!select) {
                return;
            }

            function update() {
                var option = select.options[select.selectedIndex];
                var src    = option ? option.getAttribute('data-preview') : null;

                // Long paths do not fit: the whole choice as a tooltip.
                select.title = option ? option.text : '';

                if (custom) {
                    custom.hidden = select.value !== 'custom';
                }

                if (preview) {
                    preview.hidden = !src;

                    if (src) {
                        preview.src = src;
                    }
                }

                if (button) {
                    button.disabled = select.value === '';
                }
            }

            select.addEventListener('change', function () {
                update();

                if (select.value === 'custom' && custom) {
                    custom.focus();
                }
            });

            if (custom && button) {
                // Enter in the path field repairs this row, nothing else.
                custom.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        button.click();
                    }
                });
            }

            update();
        });
    })();

    (function () {
        var button = document.getElementById('mc-links-check');
        var slot   = document.getElementById('mc-rescan-overlay-slot');

        if (!button || !slot) {
            return;
        }

        var endpoint  = <?php echo json_encode(Route::_('index.php?option=com_mediacleaner', false)); ?>;
        var tokenName = <?php echo json_encode(Session::getFormToken()); ?>;
        var doneUrl   = <?php echo json_encode(Route::_($view . '&filter=&start=0', false)); ?>;
        var text      = <?php echo json_encode([
            'starting' => Text::_('COM_MEDIACLEANER_LINKS_STARTING'),
            'failed'   => Text::_('COM_MEDIACLEANER_LINKS_FAILED'),
            'expired'  => Text::_('COM_MEDIACLEANER_SCAN_SESSION_EXPIRED'),
            'reload'   => Text::_('COM_MEDIACLEANER_SCAN_RELOAD_PAGE'),
            'login'    => Text::_('COM_MEDIACLEANER_SCAN_LOGIN_AGAIN'),
        ]); ?>;

        var overlay, fill, label, again, jobId = '', failures = 0;

        function fail(message, buttonText) {
            overlay.classList.add('mc-rescan-failed');
            label.textContent = message;
            again.textContent = buttonText;
            again.hidden = false;
        }

        function step() {
            var body = new FormData();

            body.append('option', 'com_mediacleaner');
            body.append('task', 'links.step');
            body.append('job_id', jobId);
            body.append('budget', '8');
            body.append(tokenName, '1');

            fetch(endpoint, {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(function (r) { return r.text().then(function (t) { return {status: r.status, text: t}; }); })
                .then(function (result) {
                    var data = null;

                    try { data = JSON.parse(result.text); } catch (e) { data = null; }

                    if (!data && /name=["']?passwd["']?/i.test(result.text)) {
                        fail(text.expired, text.login);

                        return;
                    }

                    if (data && data.error) {
                        fail(text.failed + ' ' + data.error, text.reload);

                        return;
                    }

                    if (!data || !data.jobId) {
                        throw result;
                    }

                    jobId = data.jobId;
                    failures = 0;
                    fill.style.width = Math.max(1, Math.min(100, data.percent || 0)) + '%';
                    label.textContent = data.label;

                    if (data.done) {
                        window.location.href = doneUrl;

                        return;
                    }

                    step();
                })
                .catch(function () {
                    if (++failures <= 4) {
                        window.setTimeout(step, 2500);

                        return;
                    }

                    fail(text.failed, text.reload);
                });
        }

        button.addEventListener('click', function () {
            button.disabled = true;

            overlay = document.createElement('div');
            overlay.id = 'mc-rescan-overlay';
            overlay.innerHTML = '<div class="mc-rescan-bar-track"><div class="mc-rescan-bar-fill" style="width:1%"></div></div><div class="mc-rescan-label"></div>';
            fill  = overlay.querySelector('.mc-rescan-bar-fill');
            label = overlay.querySelector('.mc-rescan-label');
            label.textContent = text.starting;

            again = document.createElement('button');
            again.type = 'button';
            again.className = 'btn btn-sm btn-secondary mc-rescan-resume';
            again.hidden = true;
            again.addEventListener('click', function () { window.location.href = doneUrl; });
            overlay.appendChild(again);

            slot.appendChild(overlay);
            document.querySelector('.com-mediacleaner-links').classList.add('mc-scanning');

            step();
        });
    })();
</script>
