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

/** @var \Joomla\Component\Mediacleaner\Administrator\View\Duplicates\HtmlView $this */

$h      = static function ($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
};
$action = Route::_('index.php?option=com_mediacleaner&view=duplicates', false);
$total  = array_sum(array_column($this->items, 'size'));
$root   = rtrim(Uri::root(), '/') . '/';
?>
<div class="com-mediacleaner-files com-mediacleaner-duplicates">
    <div id="mc-rescan-overlay-slot"></div>

    <h2 class="mc-page-title"><?php echo $h(Text::_('COM_MEDIACLEANER_DUPLICATES_TITLE')); ?></h2>

    <p><?php echo $h(Text::_('COM_MEDIACLEANER_DUPLICATES_INTRO')); ?></p>

    <p>
        <button type="button" class="btn btn-primary" id="mc-duplicates-search"><?php echo $h(Text::_('COM_MEDIACLEANER_DUPLICATES_SEARCH')); ?></button>
        <?php if ($this->searchedAt) : ?>
            <span class="mc-compress-hint"><?php echo $h(Text::sprintf('COM_MEDIACLEANER_DUPLICATES_SEARCHED', HTMLHelper::_('date', $this->searchedAt, Text::_('DATE_FORMAT_LC5')))); ?></span>
        <?php endif; ?>
    </p>

    <?php if ($this->searchedAt && !$this->items) : ?>
        <div class="alert alert-success"><?php echo $h(Text::_('COM_MEDIACLEANER_DUPLICATES_NONE')); ?></div>
    <?php elseif ($this->items) : ?>
        <form action="<?php echo $h($action); ?>" method="post" id="mc-duplicates-form">
            <p class="mc-summary mc-summary-standalone">
                <?php echo $h(Text::sprintf('COM_MEDIACLEANER_DUPLICATES_SUMMARY', \count($this->items), number_format($total / 1048576, 1, ',', '.'))); ?>
            </p>

            <div class="mc-actions-bar">
                <button type="submit" class="mc-btn mc-btn-purge" id="mc-duplicates-delete" disabled
                    onclick="return confirm(<?php echo $h(json_encode(Text::_('COM_MEDIACLEANER_DUPLICATES_CONFIRM'))); ?>);">
                    <?php echo $h(Text::_('COM_MEDIACLEANER_DUPLICATES_DELETE')); ?>
                </button>
                <span class="mc-selected-count"><span id="mc-duplicates-count">0</span> <?php echo $h(Text::_('COM_MEDIACLEANER_SELECTED_SUFFIX')); ?></span>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col" class="w-1"><input type="checkbox" id="mc-duplicates-check-all" aria-label="<?php echo $h(Text::_('JGLOBAL_CHECK_ALL')); ?>"></th>
                            <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_DUPLICATES_COL_COPY')); ?></th>
                            <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_DUPLICATES_COL_TWIN')); ?></th>
                            <th scope="col"><?php echo $h(Text::_('COM_MEDIACLEANER_COMPRESS_COL_SIZE')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->items as $item) : ?>
                            <tr>
                                <td><input type="checkbox" class="mc-duplicates-check" name="cid[]" value="<?php echo (int) $item['id']; ?>"></td>
                                <td class="mc-compress-path"><a href="<?php echo $h($root . $item['relative_path']); ?>" target="_blank" rel="noopener"><?php echo $h($item['relative_path']); ?></a></td>
                                <td class="mc-compress-path"><a href="<?php echo $h($root . $item['twin_path']); ?>" target="_blank" rel="noopener"><?php echo $h($item['twin_path']); ?></a></td>
                                <td class="mc-nowrap"><?php echo $h(number_format($item['size'] / 1024, 0, ',', '.') . ' KB'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <input type="hidden" name="task" value="duplicates.delete">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    <?php endif; ?>
</div>

<script>
    (function () {
        var checks   = document.querySelectorAll('.mc-duplicates-check');
        var checkAll = document.getElementById('mc-duplicates-check-all');
        var del      = document.getElementById('mc-duplicates-delete');
        var counter  = document.getElementById('mc-duplicates-count');

        function refresh() {
            var n = 0;

            checks.forEach(function (c) { if (c.checked) { n++; } });

            if (counter) { counter.textContent = n; }
            if (del) { del.disabled = n === 0; }
        }

        checks.forEach(function (c) { c.addEventListener('change', refresh); });

        if (checkAll) {
            checkAll.addEventListener('change', function () {
                checks.forEach(function (c) { c.checked = checkAll.checked; });
                refresh();
            });
        }
    })();

    (function () {
        var button = document.getElementById('mc-duplicates-search');
        var slot   = document.getElementById('mc-rescan-overlay-slot');

        if (!button || !slot) {
            return;
        }

        var endpoint  = <?php echo json_encode(Route::_('index.php?option=com_mediacleaner', false)); ?>;
        var tokenName = <?php echo json_encode(Session::getFormToken()); ?>;
        var doneUrl   = <?php echo json_encode($action); ?>;
        var text      = <?php echo json_encode([
            'starting' => Text::_('COM_MEDIACLEANER_DUPLICATES_STARTING'),
            'failed'   => Text::_('COM_MEDIACLEANER_DUPLICATES_FAILED'),
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
            body.append('task', 'duplicates.step');
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
            document.querySelector('.com-mediacleaner-duplicates').classList.add('mc-scanning');

            step();
        });
    })();
</script>
