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
use Joomla\Component\Mediacleaner\Administrator\Helper\TabsHelper;
use Joomla\Component\Mediacleaner\Administrator\Model\CompressModel;

/** @var \Joomla\Component\Mediacleaner\Administrator\View\Compress\HtmlView $this */

$h      = static function ($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
};
$action = Route::_('index.php?option=com_mediacleaner&view=compress', false);

$select = function ($key) use ($h) {
    $html = '<select class="form-select mc-compress-choice" name="choices[' . $h($key) . ']" data-key="' . $h($key) . '">';

    foreach (CompressModel::CHOICES[$key] as $value) {
        if ($value === 'webp' && !$this->capabilities['webp']) {
            continue;
        }

        $html .= '<option value="' . $h($value) . '"' . ($this->choices[$key] === $value ? ' selected' : '') . '>'
            . $h(Text::_('COM_MEDIACLEANER_COMPRESS_CHOICE_' . strtoupper($key) . '_' . strtoupper($value)))
            . '</option>';
    }

    return $html . '</select>';
};

$found = function ($type) use ($h) {
    $out = '';

    foreach (['linked', 'all'] as $scope) {
        $s    = $this->summary[$scope][$type] ?? ['count' => 0, 'size' => 0];
        $out .= '<span class="mc-compress-found" data-scope="' . $scope . '"'
            . (($scope === 'linked') !== $this->onlyLinked ? ' hidden' : '') . '>'
            . '<span class="mc-compress-found-count">' . $h(Text::plural('COM_MEDIACLEANER_COMPRESS_FOUND_N', (int) $s['count'])) . '</span>'
            . ((int) $s['count'] > 0 ? '<span class="mc-compress-sub">' . $h($this->mb($s['size'])) . '</span>' : '')
            . '</span>';
    }

    return $out;
};

$kind = static function ($name, $minKb) use ($h) {
    return '<span class="mc-compress-kind">' . $h($name) . '</span>'
        . '<span class="mc-compress-sub">' . $h(Text::sprintf('COM_MEDIACLEANER_COMPRESS_FROM_KB', $minKb)) . '</span>';
};

$actionLabel = static function (array $row) {
    if ($row['action'] === 'to_webp') {
        return Text::_('COM_MEDIACLEANER_COMPRESS_ACTION_TO_WEBP');
    }

    if ($row['action'] === 'to_jpg') {
        return Text::_('COM_MEDIACLEANER_COMPRESS_ACTION_TO_JPG');
    }

    return Text::_('COM_MEDIACLEANER_COMPRESS_ACTION_RESIZE');
};

$statusLabel = static function (array $row) {
    return Text::_('COM_MEDIACLEANER_COMPRESS_STATUS_' . strtoupper($row['status']));
};

$logLink = function ($filter, $label) use ($h) {
    $url = Route::_('index.php?option=com_mediacleaner&view=compress' . ($filter !== '' ? '&log=' . $filter : ''), false) . '#mc-compress-log';

    return '<a href="' . $h($url) . '" class="mc-filter-link' . ($this->logFilter === $filter ? ' mc-filter-active' : '') . '">' . $h($label) . '</a>';
};

$pages   = (int) ceil($this->log['total'] / $this->logLimit);
$page    = (int) floor($this->logStart / $this->logLimit) + 1;
$pageUrl = function ($start) use ($h) {
    return $h(Route::_('index.php?option=com_mediacleaner&view=compress' . ($this->logFilter !== '' ? '&log=' . $this->logFilter : '') . '&start=' . (int) $start, false) . '#mc-compress-log');
};
?>
<div class="com-mediacleaner-files com-mediacleaner-compress">
    <div id="mc-rescan-overlay-slot"></div>

    <h2 class="mc-page-title">Media Cleaner</h2>

    <?php echo TabsHelper::render('compress'); ?>

    <div class="mc-summary-row">
        <p class="mc-summary">
            <?php if ($this->stats['done'] > 0) : ?>
                <?php echo $h(Text::sprintf('COM_MEDIACLEANER_COMPRESS_STATS', $this->stats['done'], $this->mb($this->stats['saved']))); ?>
            <?php else : ?>
                <?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_INTRO')); ?>
            <?php endif; ?>
        </p>

        <?php if ($this->componentVersion !== '') : ?>
            <span class="mc-version-label">Media Cleaner &middot; v<?php echo $h($this->componentVersion); ?></span>
        <?php endif; ?>
    </div>

    <?php if (!$this->capabilities['gd']) : ?>
        <div class="alert alert-danger"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_NO_GD')); ?></div>
    <?php endif; ?>

    <?php if ($this->storage['root'] === '') : ?>
        <div class="alert alert-danger"><?php echo $h(Text::_('COM_MEDIACLEANER_STORAGE_NONE')); ?></div>
    <?php elseif ($this->storage['reachable'] === true) : ?>
        <div class="alert alert-danger">
            <?php echo $h(Text::sprintf('COM_MEDIACLEANER_STORAGE_REACHABLE', $this->storage['root'])); ?>
        </div>
    <?php endif; ?>

    <?php if (!$this->hasScan) : ?>
        <div class="alert alert-info">
            <?php echo Text::sprintf('COM_MEDIACLEANER_COMPRESS_SCAN_FIRST', $h(Route::_('index.php?option=com_mediacleaner&view=files', false))); ?>
        </div>
    <?php else : ?>
        <form action="<?php echo $h($action); ?>" method="post" id="mc-compress-form" class="mc-compress-box">
            <h3 class="mc-compress-heading"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_WHAT')); ?></h3>

            <table class="table mc-compress-choices">
                <colgroup>
                    <col class="mc-compress-col-kind">
                    <col class="mc-compress-col-found">
                    <col class="mc-compress-col-do">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_COL_KIND')); ?></th>
                        <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_COL_FOUND')); ?></th>
                        <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_COL_DO')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row"><?php echo $kind('JPG', $this->settings['jpg_min_kb']); ?></th>
                        <td><?php echo $found('jpg'); ?></td>
                        <td><?php echo $select('jpg'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo $kind('PNG', $this->settings['png_min_kb']); ?></th>
                        <td><?php echo $found('png'); ?></td>
                        <td>
                            <div class="mc-compress-sub-choice">
                                <span class="mc-compress-sub-label"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_PNG_OPAQUE')); ?></span>
                                <?php echo $select('png'); ?>
                            </div>
                            <div class="mc-compress-sub-choice">
                                <span class="mc-compress-sub-label"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_PNG_ALPHA')); ?></span>
                                <?php echo $select('png_alpha'); ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo $kind('PDF', $this->settings['pdf_min_kb']); ?></th>
                        <td><?php echo $found('pdf'); ?></td>
                        <td><?php echo $select('pdf'); ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="mc-compress-options">
                <label>
                    <input type="checkbox" name="only_linked" value="1" id="mc-compress-only-linked" <?php echo $this->onlyLinked ? 'checked' : ''; ?>>
                    <?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_ONLY_LINKED')); ?>
                </label>
                <?php if ($this->stats['skipped'] > 0) : ?>
                    <label>
                        <input type="checkbox" name="retry" value="1" id="mc-compress-retry">
                        <?php echo $h(Text::sprintf('COM_MEDIACLEANER_COMPRESS_RETRY', $this->stats['skipped'])); ?>
                    </label>
                <?php endif; ?>
            </div>

            <ul class="mc-compress-notes">
                <li><?php echo $h(Text::sprintf('COM_MEDIACLEANER_COMPRESS_NOTE_SIZE', $this->settings['max_pixels'], $this->settings['jpg_quality'], $this->settings['webp_quality'], $this->settings['pdf_dpi'])); ?></li>
                <li><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_NOTE_SCOPE')); ?></li>
                <li><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_NOTE_REFS')); ?></li>
                <li><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_NOTE_CODE')); ?></li>
                <?php if ($this->capabilities['webp']) : ?>
                    <li><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_NOTE_WEBP')); ?></li>
                <?php else : ?>
                    <li><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_NOTE_NO_WEBP')); ?></li>
                <?php endif; ?>
                <li><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_NOTE_META')); ?></li>
            </ul>

            <button type="button" class="btn btn-primary" id="mc-compress-start" <?php echo !$this->capabilities['gd'] || $this->storage['root'] === '' ? 'disabled' : ''; ?>>
                <?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_START')); ?>
            </button>
        </form>
    <?php endif; ?>

    <div class="mc-compress-box mc-compress-storage">
        <h3 class="mc-compress-heading"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_ORIGINALS')); ?></h3>

        <?php if ($this->storage['root'] !== '') : ?>
            <p>
                <?php echo $h(Text::sprintf(
                    $this->storage['inside'] ? 'COM_MEDIACLEANER_STORAGE_INSIDE' : 'COM_MEDIACLEANER_STORAGE_OUTSIDE',
                    $this->storage['root']
                )); ?>
                <?php if ($this->storage['free'] !== null) : ?>
                    <?php echo $h(Text::sprintf('COM_MEDIACLEANER_STORAGE_FREE', $this->mb($this->storage['free']))); ?>
                <?php endif; ?>
            </p>
            <?php if ($this->storage['inside']) : ?>
                <p class="mc-compress-hint"><?php echo $h(Text::_('COM_MEDIACLEANER_STORAGE_INSIDE_HINT')); ?></p>
                <form action="<?php echo $h($action); ?>" method="post" class="mc-inline-form">
                    <input type="hidden" name="task" value="compress.recheck">
                    <?php echo HTMLHelper::_('form.token'); ?>
                    <button type="submit" class="mc-btn"><?php echo $h(Text::_('COM_MEDIACLEANER_STORAGE_RECHECK')); ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($this->stats['originals'] > 0) : ?>
            <p>
                <strong><?php echo $h(Text::sprintf('COM_MEDIACLEANER_COMPRESS_ORIGINALS_KEPT', $this->stats['originals'], $this->mb($this->stats['originalsSize']))); ?></strong>
            </p>

            <?php if ($this->zipParts) : ?>
                <div class="mc-compress-zip">
                    <?php foreach ($this->zipParts as $i => $part) : ?>
                        <form action="<?php echo $h($action); ?>" method="post" class="mc-inline-form">
                            <input type="hidden" name="task" value="compress.zip">
                            <input type="hidden" name="part" value="<?php echo $i + 1; ?>">
                            <?php echo HTMLHelper::_('form.token'); ?>
                            <button type="submit" class="mc-btn">
                                <?php echo $h(Text::sprintf('COM_MEDIACLEANER_COMPRESS_ZIP_PART', $i + 1, \count($part), $this->mb(array_sum($part)))); ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <p class="mc-compress-hint"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_ZIP_HINT')); ?></p>
            <?php endif; ?>

            <form action="<?php echo $h($action); ?>" method="post" class="mc-inline-form"
                onsubmit="return confirm(<?php echo $h(json_encode(Text::sprintf('COM_MEDIACLEANER_COMPRESS_PURGE_CONFIRM', $this->stats['originals']))); ?>);">
                <input type="hidden" name="task" value="compress.purge">
                <?php echo HTMLHelper::_('form.token'); ?>
                <button type="submit" class="mc-btn mc-btn-purge"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_PURGE')); ?></button>
            </form>
        <?php else : ?>
            <p><?php echo $h(Text::_($this->stats['done'] + $this->stats['restored'] > 0 ? 'COM_MEDIACLEANER_COMPRESS_ORIGINALS_NONE' : 'COM_MEDIACLEANER_COMPRESS_ORIGINALS_NOTHING_YET')); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($this->stats['done'] + $this->stats['skipped'] + $this->stats['restored'] > 0) : ?>
        <div class="mc-compress-box" id="mc-compress-log">
            <h3 class="mc-compress-heading"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_LOG')); ?></h3>

            <div class="mc-filters">
                <?php echo $logLink('', Text::_('COM_MEDIACLEANER_COMPRESS_LOG_ALL')); ?>
                <?php echo $logLink('done', Text::sprintf('COM_MEDIACLEANER_COMPRESS_LOG_DONE', $this->stats['done'])); ?>
                <?php echo $logLink('skipped', Text::sprintf('COM_MEDIACLEANER_COMPRESS_LOG_SKIPPED', $this->stats['skipped'])); ?>
                <?php echo $logLink('restored', Text::sprintf('COM_MEDIACLEANER_COMPRESS_LOG_RESTORED', $this->stats['restored'])); ?>
            </div>

            <form action="<?php echo $h($action); ?>" method="post" id="mc-compress-log-form">
                <div class="mc-actions-bar">
                    <button type="submit" class="mc-btn mc-btn-restore" id="mc-compress-restore" disabled
                        onclick="return confirm(<?php echo $h(json_encode(Text::_('COM_MEDIACLEANER_COMPRESS_RESTORE_CONFIRM'))); ?>);">
                        <?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_RESTORE')); ?>
                    </button>
                    <span class="mc-selected-count"><span id="mc-compress-count">0</span> <?php echo $h(Text::_('COM_MEDIACLEANER_SELECTED_SUFFIX')); ?></span>
                </div>

                <div class="table-responsive">
                    <table class="table mc-compress-log">
                        <thead>
                            <tr>
                                <th scope="col" class="w-1"><input type="checkbox" id="mc-compress-check-all" aria-label="<?php echo $h(Text::_('JGLOBAL_CHECK_ALL')); ?>"></th>
                                <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_COL_FILE')); ?></th>
                                <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_COL_RESULT')); ?></th>
                                <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_COL_SIZE')); ?></th>
                                <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_COL_REFS')); ?></th>
                                <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_COL_DATE')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->log['items'] as $row) : ?>
                                <tr class="mc-compress-row-<?php echo $h($row['status']); ?>">
                                    <td>
                                        <?php if ($row['status'] === 'done' && ($row['original_stored'] !== '' || ($row['new_path'] !== '' && $row['new_path'] !== $row['relative_path']))) : ?>
                                            <input type="checkbox" class="mc-compress-check" name="cid[]" value="<?php echo (int) $row['id']; ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td class="mc-compress-path">
                                        <?php echo $h($row['relative_path']); ?>
                                        <?php if ($row['new_path'] !== '' && $row['new_path'] !== $row['relative_path']) : ?>
                                            <br><span class="mc-compress-arrow">&rarr; <?php echo $h($row['new_path']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] === 'skipped') : ?>
                                            <span class="mc-compress-skipped"><?php echo $h($statusLabel($row)); ?></span>:
                                            <?php echo $h(CompressModel::reasonText($row['reason'])); ?>
                                        <?php else : ?>
                                            <span class="mc-compress-status-<?php echo $h($row['status']); ?>"><?php echo $h($actionLabel($row)); ?></span>
                                            <?php if ($row['status'] !== 'done') : ?>
                                                &middot; <?php echo $h($statusLabel($row)); ?>
                                            <?php endif; ?>
                                            <?php if ($row['info'] !== '') : ?>
                                                <br><small><?php echo $h($row['info']); ?></small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="mc-nowrap">
                                        <?php echo $h($this->kb($row['old_size'])); ?>
                                        <?php if ($row['status'] !== 'skipped' && (int) $row['new_size'] > 0) : ?>
                                            &rarr; <?php echo $h($this->kb($row['new_size'])); ?>
                                        <?php endif; ?>
                                        <?php if ($row['old_px'] !== '' && $row['new_px'] !== '' && $row['old_px'] !== $row['new_px']) : ?>
                                            <br><small><?php echo $h($row['old_px'] . ' → ' . $row['new_px']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $row['action'] === 'resize' ? '&ndash;' : (int) $row['ref_count']; ?></td>
                                    <td class="mc-nowrap"><?php echo $h(HTMLHelper::_('date', $row['created'], Text::_('DATE_FORMAT_LC5'))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <input type="hidden" name="task" value="compress.restore">
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>

            <?php if ($pages > 1) : ?>
                <div class="mc-pagination">
                    <span><?php echo $h(Text::sprintf('COM_MEDIACLEANER_COMPRESS_PAGE', $page, $pages, $this->log['total'])); ?></span>
                    <span>
                        <?php if ($page > 1) : ?>
                            <a class="mc-btn" href="<?php echo $pageUrl($this->logStart - $this->logLimit); ?>">&larr; <?php echo $h(Text::_('JPREV')); ?></a>
                        <?php endif; ?>
                        <?php if ($page < $pages) : ?>
                            <a class="mc-btn" href="<?php echo $pageUrl($this->logStart + $this->logLimit); ?>"><?php echo $h(Text::_('JNEXT')); ?> &rarr;</a>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    (function () {
        // Restore checkboxes.
        var checks   = document.querySelectorAll('.mc-compress-check');
        var checkAll = document.getElementById('mc-compress-check-all');
        var restore  = document.getElementById('mc-compress-restore');
        var counter  = document.getElementById('mc-compress-count');

        function refresh() {
            var n = 0;

            checks.forEach(function (c) { if (c.checked) { n++; } });

            if (counter) { counter.textContent = n; }
            if (restore) { restore.disabled = n === 0; }
        }

        checks.forEach(function (c) { c.addEventListener('change', refresh); });

        if (checkAll) {
            checkAll.addEventListener('change', function () {
                checks.forEach(function (c) { c.checked = checkAll.checked; });
                refresh();
            });
        }

        // "Alleen gekoppelde bestanden" switches which counts are shown.
        var onlyLinked = document.getElementById('mc-compress-only-linked');

        if (onlyLinked) {
            onlyLinked.addEventListener('change', function () {
                document.querySelectorAll('.mc-compress-found').forEach(function (el) {
                    el.hidden = (el.dataset.scope === 'linked') !== onlyLinked.checked;
                });
            });
        }
    })();

    (function () {
        var form  = document.getElementById('mc-compress-form');
        var start = document.getElementById('mc-compress-start');
        var slot  = document.getElementById('mc-rescan-overlay-slot');

        if (!form || !start || !slot) {
            return;
        }

        // Same stepped approach, progress bar and session handling as the
        // scan on the Opruimen tab (see tmpl/files/default.php).
        var endpoint  = <?php echo json_encode(Route::_('index.php?option=com_mediacleaner', false)); ?>;
        var tokenName = <?php echo json_encode(Session::getFormToken()); ?>;
        var doneUrl   = <?php echo json_encode(Route::_('index.php?option=com_mediacleaner&view=compress', false)); ?>;
        var text      = <?php echo json_encode([
            'starting' => Text::_('COM_MEDIACLEANER_COMPRESS_STARTING'),
            'retrying' => Text::_('COM_MEDIACLEANER_SCAN_RETRYING'),
            'failed'   => Text::_('COM_MEDIACLEANER_COMPRESS_FAILED'),
            'resume'   => Text::_('COM_MEDIACLEANER_SCAN_RESUME'),
            'network'  => Text::_('COM_MEDIACLEANER_SCAN_NO_RESPONSE'),
            'expired'  => Text::_('COM_MEDIACLEANER_SCAN_SESSION_EXPIRED'),
            'stale'    => Text::_('COM_MEDIACLEANER_SCAN_SESSION_STALE'),
            'login'    => Text::_('COM_MEDIACLEANER_SCAN_LOGIN_AGAIN'),
            'reload'   => Text::_('COM_MEDIACLEANER_SCAN_RELOAD_PAGE'),
            'confirm'  => Text::_('COM_MEDIACLEANER_COMPRESS_START_CONFIRM'),
            'resumed'  => Text::_('COM_MEDIACLEANER_COMPRESS_RESUMED'),
            'etaMore'  => Text::_('COM_MEDIACLEANER_COMPRESS_ETA_MINUTES'),
            'etaOne'   => Text::_('COM_MEDIACLEANER_COMPRESS_ETA_ONE'),
            'etaLess'  => Text::_('COM_MEDIACLEANER_COMPRESS_ETA_LESS'),
        ]); ?>;

        var overlay = null, fill = null, label = null, button = null, note = null;
        var startedAt = 0;
        var jobId = '', budget = 8, failures = 0, busy = 0, running = false, needsReload = false;
        var lastLabel = text.starting;

        function showOverlay() {
            overlay = document.createElement('div');
            overlay.id = 'mc-rescan-overlay';

            var track = document.createElement('div');
            track.className = 'mc-rescan-bar-track';
            fill = document.createElement('div');
            fill.className = 'mc-rescan-bar-fill';
            fill.style.width = '1%';
            track.appendChild(fill);

            label = document.createElement('div');
            label.className = 'mc-rescan-label';
            label.textContent = text.starting;

            button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-secondary mc-rescan-resume';
            button.hidden = true;
            button.addEventListener('click', function () {
                if (running) {
                    return;
                }

                if (needsReload) {
                    running = false;
                    window.location.href = doneUrl;

                    return;
                }

                running = true;
                failures = 0;
                busy = 0;
                budget = 2;
                overlay.classList.remove('mc-rescan-failed');
                button.hidden = true;
                label.textContent = lastLabel;
                step();
            });

            note = document.createElement('div');
            note.className = 'mc-rescan-note';
            note.hidden = true;

            overlay.appendChild(track);
            overlay.appendChild(label);
            overlay.appendChild(button);
            overlay.appendChild(note);
            slot.appendChild(overlay);

            document.querySelector('.com-mediacleaner-compress').classList.add('mc-scanning');
            toTop();
        }

        // The progress bar sits at the top of the page: always bring it
        // (and any message in it) into view.
        function toTop() {
            try {
                window.scrollTo({top: 0, behavior: 'smooth'});
            } catch (e) {
                window.scrollTo(0, 0);
            }
        }

        // Rough time left, from how fast the bar moved so far. Only shown
        // once there is something to go on.
        function eta(percent) {
            var elapsed = (Date.now() - startedAt) / 1000;

            if (percent < 3 || percent >= 100 || elapsed < 8) {
                return '';
            }

            var left = Math.round(elapsed * (100 - percent) / percent / 60);

            if (left < 1) {
                return text.etaLess;
            }

            return left === 1 ? text.etaOne : text.etaMore.replace('%d', left);
        }

        function fail(message, buttonText, reload) {
            running = false;
            needsReload = !!reload;
            overlay.classList.add('mc-rescan-failed');
            label.textContent = message;
            button.textContent = buttonText;
            button.hidden = false;
            toTop();
        }

        function isLoginPage(result) {
            if (result.url && /[?&]option=com_login\b/i.test(result.url)) {
                return true;
            }

            var body = String(result.text || '');

            return /name=["']?passwd["']?/i.test(body) || /id=["']?form-login["']?/i.test(body);
        }

        function describe(result) {
            if (!result || typeof result.status === 'undefined') {
                return text.network;
            }

            var snippet = String(result.text || '')
                .replace(/<style[\s\S]*?<\/style>|<script[\s\S]*?<\/script>/gi, ' ')
                .replace(/<[^>]+>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 240);

            return '(HTTP ' + result.status + (snippet ? ': ' + snippet : '') + ')';
        }

        function step() {
            var body = new FormData();

            body.append('option', 'com_mediacleaner');
            body.append('task', 'compress.step');
            body.append('job_id', jobId);
            body.append('budget', String(budget));
            body.append(tokenName, '1');

            if (jobId === '') {
                form.querySelectorAll('.mc-compress-choice').forEach(function (s) {
                    body.append('choices[' + s.dataset.key + ']', s.value);
                });

                var linked = document.getElementById('mc-compress-only-linked');
                var retry  = document.getElementById('mc-compress-retry');

                body.append('only_linked', linked && linked.checked ? '1' : '0');
                body.append('retry', retry && retry.checked ? '1' : '0');
            }

            fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            }).then(function (response) {
                return response.text().then(function (t) {
                    return {status: response.status, text: t, url: response.url || ''};
                });
            }).then(function (result) {
                var data = null;

                try {
                    data = JSON.parse(result.text);
                } catch (e) {
                    data = null;
                }

                if (!data && isLoginPage(result)) {
                    fail(text.expired, text.login, true);

                    return;
                }

                if (data && data.error) {
                    // The server explained what is wrong; repeating the
                    // same request would not change that.
                    fail(text.failed + ' ' + (data.reload ? text.stale : data.error), text.reload, true);

                    return;
                }

                if (!data || result.status !== 200 || !data.jobId) {
                    throw result;
                }

                jobId = data.jobId;

                if (data.busy) {
                    if (++busy > 60) {
                        throw result;
                    }

                    window.setTimeout(step, 3000);

                    return;
                }

                failures = 0;
                busy = 0;
                lastLabel = data.label || lastLabel;
                fill.style.width = Math.max(1, Math.min(100, data.percent || 0)) + '%';

                var left = eta(data.percent || 0);

                label.textContent = lastLabel + (left ? ' \u00b7 ' + left : '');

                if (data.resumed && note) {
                    note.textContent = text.resumed;
                    note.hidden = false;
                }

                if (data.done) {
                    running = false;
                    window.location.href = doneUrl;

                    return;
                }

                step();
            }).catch(function (result) {
                if (++failures <= 4) {
                    budget = Math.max(2, Math.floor(budget / 2));
                    label.textContent = lastLabel + ' ' + text.retrying;
                    window.setTimeout(step, 2500);

                    return;
                }

                fail(text.failed + ' ' + describe(result), text.resume, false);
            });
        }

        // Leaving half-way is safe (the next round finishes what was
        // started), but the person should know it stops here.
        window.addEventListener('beforeunload', function (event) {
            if (running) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        start.addEventListener('click', function () {
            if (running || !window.confirm(text.confirm)) {
                return;
            }

            running = true;
            start.disabled = true;
            startedAt = Date.now();
            showOverlay();
            step();
        });
    })();
</script>
