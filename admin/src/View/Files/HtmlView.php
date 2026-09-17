<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\View\Files;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;

/**
 * View class for the file overview.
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var array
     */
    protected $items = [];

    /**
     * @var string
     */
    protected $listOrder = 'size';

    /**
     * @var string
     */
    protected $listDirn = 'DESC';

    /**
     * @var integer
     */
    protected $totalCount = 0;

    /**
     * @var float
     */
    protected $totalSizeKB = 0.0;

    /**
     * Timestamp of the last completed scan, or null if never scanned.
     *
     * @var string|null
     */
    protected $lastScan = null;

    /**
     * Current "linked" filter: '', 'linked' or 'unlinked'.
     *
     * @var string
     */
    protected $filterLinked = 'all';

    /**
     * Number of unlinked files in the current (unfiltered) dataset, shown
     * as a hint even when a filter hides them.
     *
     * @var integer
     */
    protected $unlinkedCount = 0;

    /**
     * Rows per page. 0 (only reachable via the limit box's "Alle") means
     * no limit - every matching row is fetched and rendered in one go,
     * same as before pagination existed (see getFilteredCount()-based
     * clamping in display()).
     *
     * @var integer
     */
    protected $limit = 20;

    /**
     * Zero-based offset of the first row on the current page.
     *
     * @var integer
     */
    protected $limitstart = 0;

    /**
     * Standard Joomla pagination object for the current filter's result
     * set (see display()) - renders the limit box and page links exactly
     * like core Joomla list views (e.g. Users, Articles).
     *
     * @var \Joomla\CMS\Pagination\Pagination|null
     */
    protected $pagination = null;

    /**
     * Number of files currently sitting in quarantine, shown on the
     * "Quarantine" toolbar button.
     *
     * @var integer
     */
    protected $quarantineCount = 0;

    /**
     * Whether files marked as "ignored" are currently included in the list.
     *
     * @var boolean
     */
    protected $showIgnored = false;

    /**
     * Number of files currently marked as "ignored".
     *
     * @var integer
     */
    protected $ignoredCount = 0;

    /**
     * Whether a one-shot "needs rescan" marker was found set - either by
     * script.php's postflight() after an update, or by
     * QuarantineModel::markNeedsRescan() after a manually restored file -
     * so the template's existing "mc_auto_rescan" flow (normally reached
     * via the Options-return-URL marker) fires on this load too. The
     * marker itself is cleared in display() as soon as it's read, so this
     * only ever fires once per write.
     *
     * @var boolean
     */
    protected $autoRescanAfterUpdate = false;

    /**
     * v2.7.18: true whenever THIS page load is about to trigger the
     * background auto-rescan (see the template's "mc_auto_rescan" JS
     * block) - i.e. $autoRescanAfterUpdate above, OR the
     * "mc_auto_rescan=1" query-string marker used when returning from
     * the Options screen. In either case, whatever's about to render
     * below the page title is guaranteed stale (an update or an Opties
     * change just happened, and the fresh scan hasn't run yet), so the
     * template shows only the "Scan wordt opnieuw uitgevoerd..." bar
     * while this is true and suppresses the summary/filters/table
     * entirely - rather than showing the old data next to a progress
     * bar, which looked like the page was already usable while the scan
     * was still running underneath it.
     *
     * @var boolean
     */
    protected $hideContentForRescan = false;

    /**
     * Counts for each of the four filter buttons (all/linked/unlinked/
     * ignored), matching the exact same filter combination getItems()
     * uses - shown as "Alle media (3266)", "Gekoppelde media (491)",
     * "Niet gekoppelde media (2604)", "Genegeerde media (12)".
     *
     * @var array
     */
    protected $filterCounts = ['all' => 0, 'linked' => 0, 'unlinked' => 0, 'ignored' => 0];

    /**
     * Installed version number of this component (e.g. "1.4.6"), shown as
     * a purely informational label - not part of the button toolbar.
     *
     * @var string
     */
    protected $componentVersion = '';

    /**
     * Current extension-hint filter (v2.7.5) - '' means "alle extensies",
     * i.e. no constraint. Combines with $filterLinked to narrow the list
     * (see FilesModel::applyExtensionHintWhere()), and is shown in each
     * of the four main tabs' new filter dropdown (see
     * admin/tmpl/files/default.php) - replaces the old dedicated
     * "Bulkselectie voor negeren/verwijderen" bar entirely.
     *
     * @var string
     */
    protected $extensionHint = '';

    /**
     * Current "Toon systeembestanden"/"Verberg systeembestanden"
     * sub-filter on "Genegeerde media" and "Niet-gekoppelde media"
     * (v2.7.9, extended to "Niet-gekoppeld" in v2.7.12) - '', 'system'
     * or 'other'. Always '' outside those two tabs. See
     * FilesModel::applySystemAssetFilterWhere().
     *
     * @var string
     */
    protected $systemAssetFilter = '';

    /**
     * Distinct "possibly belongs to extension" hint names present among
     * the files in the *active* $filterLinked bucket (regardless of
     * $extensionHint - this always lists every option the dropdown
     * offers, not just the currently chosen one), each with its file
     * count - backs the extension filter dropdown (see
     * FilesModel::getExtensionFilterCounts()).
     *
     * @var array
     */
    protected $extensionFilterCounts = [];

    /**
     * How many not-yet-ignored files matching $filterLinked are flagged
     * as a generic bundled-asset-folder file NOT likely manually
     * uploaded (see FilesModel::getSystemAssetFileCount()) - backs the
     * "Zet systeembestanden apart" button (v2.7.8).
     *
     * @var integer
     */
    protected $systemAssetFileCount = 0;

    /**
     * Whether to show the explanatory "auto-generated 'thumbs' folder"
     * note next to unlinked files that sit in one (see
     * FilesModel::showThumbsDirHint() for what this Options-screen
     * toggle controls since v2.6.0 - the files themselves are always
     * shown regardless).
     *
     * @var boolean
     */
    protected $showThumbsDirHint = false;

    /**
     * Display the view.
     *
     * @param   string  $tpl  Template name.
     *
     * @return  void
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        $this->listOrder = (string) $app->getUserStateFromRequest(
            'com_mediacleaner.files.ordercol',
            'filter_order',
            'size',
            'cmd'
        );

        $this->listDirn = (string) $app->getUserStateFromRequest(
            'com_mediacleaner.files.orderdirn',
            'filter_order_Dir',
            'DESC',
            'cmd'
        );

        if (!in_array($this->listOrder, ['size', 'type', 'name', 'path', 'linked'], true)) {
            $this->listOrder = 'size';
        }

        if (!in_array(strtoupper($this->listDirn), ['ASC', 'DESC'], true)) {
            $this->listDirn = 'DESC';
        }

        // v2.7.5: if the tab itself just changed, the previously chosen
        // extension filter almost certainly doesn't make sense on the
        // new tab (different hint set entirely) and silently carrying it
        // over would show a confusingly near-empty page with no visible
        // explanation - so it resets to "alle extensies" whenever that
        // happens. Must read the *previous* stored value before calling
        // getUserStateFromRequest() for filter_linked below, since that
        // call's side effect is to immediately overwrite this same
        // session key with the new request value - reading "previous"
        // after that point (the v2.7.5 bug: it did) always sees the new
        // value compared against itself, so the reset branch could never
        // fire and a stale extension_hint stuck around indefinitely,
        // silently filtering later tabs down to zero results.
        $previousFilterLinked = (string) $app->getUserState('com_mediacleaner.files.filter.linked', 'all');

        $this->filterLinked = (string) $app->getUserStateFromRequest(
            'com_mediacleaner.files.filter.linked',
            'filter_linked',
            'all',
            'cmd'
        );

        if (!in_array($this->filterLinked, ['all', 'linked', 'unlinked', 'ignored'], true)) {
            $this->filterLinked = 'all';
        }

        if ($this->filterLinked !== $previousFilterLinked) {
            $app->setUserState('com_mediacleaner.files.filter.extension_hint', '');
            $app->setUserState('com_mediacleaner.files.filter.system_asset', '');
        }

        $this->extensionHint = (string) $app->getUserStateFromRequest(
            'com_mediacleaner.files.filter.extension_hint',
            'extension_hint',
            '',
            'string'
        );

        // v2.7.9 (extended to "Niet-gekoppeld" too in v2.7.12): "Toon
        // systeembestanden" / "Verberg systeembestanden" on "Genegeerde
        // media" and "Niet-gekoppelde media" - lets Wouter separate
        // files flagged as a bundled extension asset (see
        // Scanner::pathHasSystemAssetSegment()) from the rest, on
        // whichever of those two tabs a large pile of them tends to
        // build up. Meaningless on "Alle"/"Gekoppeld" (system-asset
        // files there aren't the review problem this exists for), so
        // it's simply not read from the request there - see below,
        // where it's forced back to '' outside those two tabs.
        $this->systemAssetFilter = (string) $app->getUserStateFromRequest(
            'com_mediacleaner.files.filter.system_asset',
            'system_asset_filter',
            '',
            'cmd'
        );

        if (!in_array($this->systemAssetFilter, ['', 'system', 'other'], true) || !in_array($this->filterLinked, ['ignored', 'unlinked'], true)) {
            $this->systemAssetFilter = '';
        }

        // v2.3.6: "Genegeerde media" is now its own filter bucket (see
        // the template's $filterLink('ignored', ...) call) rather than a
        // toggle layered on top of the other three - Wouter pointed out
        // that visually and conceptually it should be a fifth peer of
        // Alle/Gekoppeld/Niet-gekoppeld ("show me this particular set of
        // files"), not an on/off switch that could apply to any of them
        // at once. So the three category tabs now always exclude ignored
        // files unconditionally; $showIgnored stays around only because
        // getItems()/getFilteredCount()/getFilteredTotalSizeKB() still
        // accept it as a parameter (kept for other callers, e.g. the
        // site-side view), always false from this View now.
        $this->showIgnored = false;

        $this->limit = (int) $app->getUserStateFromRequest(
            'com_mediacleaner.files.limit',
            'limit',
            (int) $app->get('list_limit', 20),
            'uint'
        );

        $this->limitstart = (int) $app->getUserStateFromRequest(
            'com_mediacleaner.files.limitstart',
            'limitstart',
            0,
            'uint'
        );

        /** @var \Joomla\Component\Mediacleaner\Administrator\Model\FilesModel $model */
        $model = $this->getModel();

        // Every hint present in the active tab (unfiltered by
        // $extensionHint itself - this is what populates the dropdown's
        // options). Computed before $activeFilterTotal below so a stale
        // $extensionHint that no longer exists in this tab's own hint
        // list (e.g. session state left over from before a rescan
        // removed that hint entirely) can be caught defensively too, on
        // top of the tab-switch reset above - belt and braces, since
        // this exact class of "silently filtered to zero, no visible
        // explanation" bug is precisely what bit Wouter here.
        $this->extensionFilterCounts = $model->getExtensionFilterCounts($this->filterLinked);

        if ($this->extensionHint !== '') {
            $validHintNames = array_column($this->extensionFilterCounts, 'name');

            if (!in_array($this->extensionHint, $validHintNames, true)) {
                $this->extensionHint = '';
                $app->setUserState('com_mediacleaner.files.filter.extension_hint', '');
            }
        }

        // Counts/totals for the ACTIVE filter are computed before
        // fetching the page itself, since the page count (getItems()'s
        // $limit) and the Pagination object both depend on knowing the
        // true total first - and because the summary line ("X files,
        // Y KB") must reflect the whole filtered set, not just the
        // current page (see getFilteredTotalSizeKB()).
        $this->unlinkedCount   = $model->getUnlinkedCount();
        $this->quarantineCount = $model->getQuarantineCount();
        $this->ignoredCount    = $model->getIgnoredCount();

        // The four tab badges always show the whole bucket, regardless of
        // $extensionHint - they're navigation, not a reflection of the
        // current narrower list (which resets $extensionHint on tab
        // switch anyway, see above).
        $this->filterCounts = [
            'all'      => $model->getFilteredCount('all', $this->showIgnored),
            'linked'   => $model->getFilteredCount('linked', $this->showIgnored),
            'unlinked' => $model->getFilteredCount('unlinked', $this->showIgnored),
            'ignored'  => $this->ignoredCount,
        ];

        $activeFilterTotal = $model->getFilteredCount($this->filterLinked, $this->showIgnored, $this->extensionHint, $this->systemAssetFilter);

        // If the stored limitstart no longer fits (e.g. the previous
        // filter had more pages than this one, or files were deleted
        // since), snap back to the first page rather than rendering an
        // empty one.
        if ($this->limit > 0 && $this->limitstart >= $activeFilterTotal) {
            $this->limitstart = 0;
        }

        $this->items    = $model->getItems($this->listOrder, $this->listDirn, $this->filterLinked, $this->showIgnored, $this->limit, $this->limitstart, $this->extensionHint, $this->systemAssetFilter);
        $this->lastScan = $model->getLastScanTime();

        $this->totalCount   = $activeFilterTotal;
        $this->totalSizeKB  = $model->getFilteredTotalSizeKB($this->filterLinked, $this->showIgnored, $this->extensionHint, $this->systemAssetFilter);
        $this->componentVersion = $this->getComponentVersion();

        $this->pagination = new Pagination($activeFilterTotal, $this->limitstart, $this->limit);

        // v2.7.9: the "Zet systeembestanden apart" button only makes
        // sense on "Niet-gekoppeld" - it ignores files, so on "Genegeerde
        // media" it would just be re-ignoring files that are already
        // ignored (a visible but no-op button, which is exactly the bug
        // Wouter reported), and on "Gekoppeld"/"Alle" a linked file isn't
        // the kind of clutter this button exists to clear.
        $this->systemAssetFileCount = $this->filterLinked === 'unlinked'
            ? $model->getSystemAssetFileCount('unlinked')
            : 0;

        $this->showThumbsDirHint = $model->showThumbsDirHint();

        $this->autoRescanAfterUpdate = $this->consumeNeedsRescanAfterUpdateFlag();

        $this->hideContentForRescan = $this->autoRescanAfterUpdate
            || $app->getInput()->getInt('mc_auto_rescan', 0) === 1;

        // Extracted from an inline <style> block in this view's template
        // into its own file (see media/css/admin.css) so the component
        // has no CSS hardcoded in the PHP - shared with the Quarantine
        // and Help views, which each load the same stylesheet from their
        // own display().
        HTMLHelper::_('stylesheet', 'com_mediacleaner/admin.css', ['version' => 'auto', 'relative' => true]);

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Reads and immediately clears the one-shot "needs rescan" marker -
     * set either by script.php's postflight() after an update (see
     * markNeedsRescanAfterUpdate() there) or by
     * QuarantineModel::markNeedsRescan() after a manually restored file -
     * a raw params read/write rather than going through
     * ComponentHelper::getParams(), since that only reads the cached copy
     * and offers no straightforward way to persist a change back from
     * inside a View. Cleared unconditionally as soon as it's read (whether
     * or not anything below actually goes on to use it), so each marker
     * write can only ever trigger one background rescan, never one on
     * every subsequent page load.
     *
     * @return  boolean  Whether the marker was set.
     */
    protected function consumeNeedsRescanAfterUpdateFlag()
    {
        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_mediacleaner'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'));

            $db->setQuery($query);
            $row = $db->loadAssoc();

            if (!$row) {
                return false;
            }

            $params = !empty($row['params']) ? json_decode($row['params'], true) : [];

            if (!is_array($params) || empty($params['_needs_rescan_after_update'])) {
                return false;
            }

            unset($params['_needs_rescan_after_update']);

            $update = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($db->quoteName('extension_id') . ' = ' . (int) $row['extension_id']);

            $db->setQuery($update)->execute();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Configure the admin toolbar.
     *
     * @return  void
     */
    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_MEDIACLEANER_FILES'), 'images mediacleaner');
        ToolbarHelper::custom('files.rescan', 'refresh', 'refresh', 'COM_MEDIACLEANER_RESCAN', false);

        // Only show the "Options" button (component permissions/config) to
        // users who are actually allowed to change them. Built manually
        // (rather than via ToolbarHelper::preferences()) so the "return"
        // URL carries a marker: when com_config redirects back here after
        // Save/Save & Close, the inline script at the bottom of this view
        // picks up "mc_auto_rescan=1" and runs a fresh scan in the
        // background (with the progress bar visible) rather than the page
        // itself waiting on the scan before it can even redirect here.
        //
        // This must be added BEFORE Help, not after: Joomla's own
        // core layout for toolbar "Link" buttons
        // (layouts/joomla/toolbar/link.php) automatically adds a
        // Bootstrap "ms-auto" class to any button whose URL contains
        // "option=com_config" - which ours does - docking it (and
        // everything added after it) to the right edge of the toolbar,
        // regardless of how many buttons come before it. An earlier
        // attempt at this (v1.32.0) added Help first and Options
        // second, which meant Help stayed in normal left-aligned flow
        // while Options alone jumped to the right edge - exactly the
        // gap the site owner kept seeing. Simply adding Options first
        // and Help right after it means Help now rides along
        // immediately to the right of Options' auto-margin instead of
        // needing (fragile) positioning tricks of its own.
        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_mediacleaner')) {
            $returnUri = clone Uri::getInstance();
            $returnUri->setVar('mc_auto_rescan', '1');
            $return = urlencode(base64_encode((string) $returnUri));

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
     * Read the installed version number of this component, and — as a
     * side effect — silently repair Joomla's own `manifest_cache` entry if
     * it has gone out of sync with what's actually installed on disk.
     *
     * This works around a known Joomla core issue
     * (https://issues.joomla.org/tracker/joomla-cms/37160) where, for
     * third-party extensions, `manifest_cache.version` can end up blank or
     * stale after an install/update - which then makes System Information
     * → Database permanently report a version mismatch. Rather than
     * relying on the exact timing of Joomla's own install lifecycle hooks
     * (which turned out not to be reliable enough here), this check runs
     * on every page view of the component and fixes things itself if
     * needed, using the actually-installed manifest XML on disk as the
     * source of truth.
     *
     * @return  string  e.g. "1.5.5", or an empty string if it can't be determined.
     */
    protected function getComponentVersion()
    {
        $realVersion = $this->getInstalledManifestVersion();

        $db    = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName(['extension_id', 'manifest_cache']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_mediacleaner'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'));

        $db->setQuery($query);

        try {
            $row = $db->loadAssoc();
        } catch (\Exception $e) {
            return $realVersion;
        }

        if (!$row) {
            return $realVersion;
        }

        $extensionId = (int) $row['extension_id'];

        $cache = !empty($row['manifest_cache']) ? json_decode($row['manifest_cache'], true) : [];

        if (!is_array($cache)) {
            $cache = [];
        }

        $cachedVersion = isset($cache['version']) ? (string) $cache['version'] : '';
        $versionStale  = $realVersion !== '' && $cachedVersion !== $realVersion;

        if ($versionStale) {
            try {
                $cache['version'] = $realVersion;

                $update = $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('manifest_cache') . ' = ' . $db->quote(json_encode($cache)))
                    ->where($db->quoteName('extension_id') . ' = ' . $extensionId);

                $db->setQuery($update);
                $db->execute();
            } catch (\Throwable $e) {
                // Best-effort only - never let this break the page.
            }
        }

        // Also run Joomla's own "Fix Structure" logic whenever the cached
        // version just went stale (the original trigger), OR - discovered
        // after a v1.24.0 upgrade where one of that version's ALTER TABLE
        // statements silently didn't take effect on a live site (later
        // traced to an unsafe DROP+re-ADD pattern, fixed for good in
        // v1.27.0 - see unlinkedViewIndexLooksHealthy()) - whenever this
        // table's own structure doesn't match what the currently
        // installed version expects, even if manifest_cache already
        // (incorrectly) looks up to date. That second condition means a
        // structural hiccup like that one now heals itself on the next
        // page view instead of silently persisting forever, the way it
        // did here: once `manifest_cache.version` finally matched
        // `mediacleaner.xml` again, this method had no way left to
        // know anything was still actually wrong underneath.
        //
        // An earlier version of this method skipped calling this based on
        // a Joomla core bug report (issues.joomla.org/tracker/joomla-cms/
        // 37124) that turned out to be specific to *plugins* with a
        // particular subfolder layout - repeated real-world testing on
        // this exact component confirmed that for components, calling
        // this is both safe and actually necessary: clicking
        // "Fix Structure" by hand reliably resolves the System
        // Information warning, while the manual manifest_cache patch
        // above alone does not.
        if ($versionStale || !$this->unlinkedViewIndexLooksHealthy()) {
            $this->runOfficialDatabaseFix($extensionId);
        }

        return $realVersion !== '' ? $realVersion : $cachedVersion;
    }

    /**
     * Whether `#__mediacleaner_files.idx_unlinked_view2` - the 5-column
     * composite index (linked, ignored, is_thumbs_dir,
     * is_active_extension_asset, is_active_extension_images_dir) that
     * backs the combined Unlinked-view query - currently exists. Used as
     * an extra trigger for the self-heal in getComponentVersion(),
     * alongside the manifest_cache staleness check, since a live site
     * was seen where the original v1.24.0 attempt to widen this index in
     * place (DROP + re-ADD under the same name, `idx_unlinked_view`)
     * silently failed - and, worse, that same DROP statement later
     * *aborted the entire v1.26.0 update* outright when Joomla's
     * installer ran it directly ("Can't DROP INDEX ...; check that it
     * exists"). v1.27.0 replaced that approach: the index is now only
     * ever added fresh, under this new name, and the old
     * `idx_unlinked_view` (in whatever state it happens to be in on a
     * given site - present, absent, or the original 4-column version) is
     * left alone rather than touched. Checked directly against
     * `information_schema` rather than trusting Joomla's own schema
     * bookkeeping, which is exactly what went stale in the original
     * case.
     *
     * Fails safe: any error while checking is treated as "healthy" (no
     * repair triggered) rather than risking calling the repair logic on
     * every single page view if e.g. the database user lacks
     * `information_schema` access.
     *
     * @return  boolean
     */
    protected function unlinkedViewIndexLooksHealthy()
    {
        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $table = $db->replacePrefix('#__mediacleaner_files');

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('information_schema.STATISTICS'))
                ->where($db->quoteName('TABLE_SCHEMA') . ' = DATABASE()')
                ->where($db->quoteName('TABLE_NAME') . ' = ' . $db->quote($table))
                ->where($db->quoteName('INDEX_NAME') . ' = ' . $db->quote('idx_unlinked_view2'));

            $db->setQuery($query);
            $columnCount = (int) $db->loadResult();

            return $columnCount === 5;
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Run Joomla's own "Fix Structure" logic for this extension - the
     * exact same action as ticking the checkbox and clicking "Fix" on
     * System Information → Database.
     *
     * The model call is wrapped defensively: the request's `cid` input is
     * seeded with this extension's id first, in case the model reads its
     * selection from the request rather than (or in addition to) a method
     * argument - this covers both possibilities without needing to know
     * Joomla's exact internal implementation.
     *
     * @param   integer  $extensionId
     *
     * @return  void
     */
    protected function runOfficialDatabaseFix($extensionId)
    {
        if ($extensionId <= 0) {
            return;
        }

        try {
            $app   = Factory::getApplication();
            $input = $app->getInput();

            // Seed the request in case DatabaseModel::fix() reads its
            // selection from there instead of (or as well as) its
            // method argument.
            $input->set('cid', [$extensionId]);

            $component  = $app->bootComponent('com_installer');
            $mvcFactory = $component->getMVCFactory();
            $model      = $mvcFactory->createModel('Database', 'Administrator', ['ignore_request' => false]);

            if ($model && method_exists($model, 'fix')) {
                $model->fix([$extensionId]);
            }
        } catch (\Throwable $e) {
            // Best-effort only - the manual manifest_cache patch that
            // already ran before this is still in place either way.
        }
    }

    /**
     * Read the <version> from the manifest XML actually installed on
     * disk - unaffected by whatever causes `manifest_cache` to go stale,
     * so it's used as the source of truth for self-healing above.
     *
     * @return  string
     */
    protected function getInstalledManifestVersion()
    {
        $path = JPATH_ADMINISTRATOR . '/components/com_mediacleaner/mediacleaner.xml';

        if (!is_file($path)) {
            return '';
        }

        try {
            $xml = simplexml_load_file($path);
        } catch (\Exception $e) {
            return '';
        }

        if ($xml === false || !isset($xml->version)) {
            return '';
        }

        return trim((string) $xml->version);
    }
}
