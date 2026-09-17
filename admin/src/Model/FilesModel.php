<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Component\Mediacleaner\Administrator\Service\QuarantineManager;
use Joomla\Component\Mediacleaner\Administrator\Service\Scanner;
use Joomla\Component\Mediacleaner\Administrator\Service\WebpConverter;

/**
 * Model backing the file overview. The actual filesystem scan only runs
 * when explicitly triggered (rescan()); the overview itself is served from
 * the `#__mediacleaner_files` table so that browsing/sorting the list is
 * fast and doesn't hit the filesystem on every page load.
 *
 * v1.37.0: this model used to also contain the filesystem-scan/reference-
 * linking engine, the quarantine-move logic, and the WebP conversion logic
 * directly - all now split out into their own single-purpose classes
 * (Scanner, QuarantineManager, WebpConverter - see admin/src/Service/) once
 * this file had grown past 2700 lines. What stays here is genuine Model
 * responsibility: reading/filtering the cached list (getItems() and
 * friends), the "ignored" toggle, the Options-screen-driven Unlinked-view
 * exclusion filters, and permission-checked entry points that delegate the
 * actual work to those service classes. Structural-only change: no
 * behaviour was altered.
 */
class FilesModel extends BaseDatabaseModel
{
    /**
     * @var Scanner|null
     */
    protected $scanner;

    /**
     * @var QuarantineManager|null
     */
    protected $quarantineManager;

    /**
     * @var WebpConverter|null
     */
    protected $webpConverter;

    /**
     * @return  Scanner
     */
    protected function getScanner()
    {
        if ($this->scanner === null) {
            $this->scanner = new Scanner($this->getDatabase());
        }

        return $this->scanner;
    }

    /**
     * @return  QuarantineManager
     */
    protected function getQuarantineManager()
    {
        if ($this->quarantineManager === null) {
            $this->quarantineManager = new QuarantineManager($this->getDatabase());
        }

        return $this->quarantineManager;
    }

    /**
     * @return  WebpConverter
     */
    protected function getWebpConverter()
    {
        if ($this->webpConverter === null) {
            $this->webpConverter = new WebpConverter($this->getDatabase());
        }

        return $this->webpConverter;
    }

    /**
     * Whether an explanatory note should be shown next to an unlinked
     * "thumbs"-folder file in "Niet-gekoppelde media" (see
     * admin/tmpl/files/default.php).
     *
     * v2.7.3: the "'thumbs'-mappen in de map 'images'" Opties toggle
     * (config.xml, "hide_thumbs_from_unlinked") now controls whether
     * such files get auto-ignored on scan (see
     * Scanner::applyAutoIgnoreThumbs()) rather than just annotated -
     * when that's on, a thumbs file simply never appears in "Niet-
     * gekoppelde media" any more, so there's nothing left to annotate
     * there. This method covers the cases where one still can show up
     * despite the toggle being on (the toggle was just switched on and
     * no rescan has run yet, or auto-ignoring failed for some reason) as
     * well as the toggle being off entirely - in both, showing the note
     * is always helpful and never wrong, so this no longer reads the
     * toggle at all and simply always returns true.
     *
     * @return  boolean
     */
    public function showThumbsDirHint()
    {
        return true;
    }

    /**
     * Whether a scanned folder path contains a segment literally named
     * "thumbs" (case-insensitive) at any depth - e.g. /thumbs,
     * /images/thumbs and /images/icagenda/thumbs/themes all match, but
     * /images/mythumbsdir does not. Computed once per file at scan time
     * (see scanFilesystem()) and persisted in the `is_thumbs_dir` column,
     * so filtering on it later is a plain indexed lookup rather than a
     * leading-wildcard LIKE scan on every page load.
     *
     * @param   string  $relDir  Folder path relative to JPATH_ROOT, as
     *                           produced by scanFilesystem() (leading
     *                           slash, no trailing slash, '' for root).
     *
     * @return  boolean
     */
    protected function pathHasThumbsSegment($relDir)
    {
        $segments = explode('/', trim($relDir, '/'));

        foreach ($segments as $segment) {
            if (strtolower($segment) === 'thumbs') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the "Bestanden van actieve componenten/modules/plugins/
     * templates" option is enabled on this component's Options screen
     * (see config.xml, "hide_active_extension_assets_from_unlinked"). A
     * file sitting in /components/com_xxx/..., /modules/mod_xxx/...,
     * /plugins/<group>/<element>/... or /templates/yyy/... belongs to
     * that extension's own bundled assets - often layout images only
     * pulled in once a specific layout is actually chosen, so many of
     * them will never show up as "linked" even though they genuinely
     * belong to, and ship with, an extension that is still installed and
     * enabled. Since v2.6.0 this no longer hides that whole category from
     * "Niet-gekoppelde media" (see the note on
     * applyBulkHintProtectionExclusions()) - such files are always shown
     * and counted there now, with a named hint (see
     * markActiveExtensionAssetStatus() in Scanner) explaining which
     * extension they probably belong to. What this option still does:
     * keep those files OUT of the "Bulkselectie voor negeren/verwijderen"
     * bulk action, so a site owner can never accidentally bulk-delete a
     * batch of files an active extension still genuinely uses, only
     * because they happen to share an extension-name hint with some
     * other, actually orphaned files. A file under a disabled/orphaned
     * extension is never protected this way, regardless of this setting.
     *
     * @return  boolean
     */
    protected function hideActiveExtensionAssetsFromUnlinked()
    {
        return (int) ComponentHelper::getParams('com_mediacleaner')->get('hide_active_extension_assets_from_unlinked', 0) === 1;
    }

    /**
     * SQL condition that excludes files flagged as belonging to a
     * currently active component/module/plugin/template (see
     * markActiveExtensionAssetStatus() in Scanner) from the bulk-hint
     * actions (see applyBulkHintProtectionExclusions()). Returns null
     * when the option (see hideActiveExtensionAssetsFromUnlinked()) is
     * off, so callers can skip appending anything.
     *
     * @param   \Joomla\Database\DatabaseDriver  $db
     *
     * @return  string|null
     */
    protected function getActiveExtensionAssetExclusionSql($db)
    {
        if (!$this->hideActiveExtensionAssetsFromUnlinked()) {
            return null;
        }

        return $db->quoteName('is_active_extension_asset') . ' = 0';
    }

    /**
     * Whether the "Media-bestanden van actieve extensies" option is
     * enabled on this component's Options screen (see config.xml,
     * "hide_active_extension_images_from_unlinked"). Several extensions
     * (e.g. IC Agenda, JDownloads) create their own subfolder directly
     * under /images/ for uploads, generated thumbnails and the like - see
     * pathBelongsToActiveExtensionImagesFolder(). Since v2.6.0 this no
     * longer hides that whole category from "Niet-gekoppelde media" (see
     * the note on applyBulkHintProtectionExclusions()) - see
     * hideActiveExtensionAssetsFromUnlinked() above for the full
     * reasoning, which applies identically here, just for the
     * /images/<x> case instead of /components|modules|plugins|templates/.
     *
     * @return  boolean
     */
    protected function hideActiveExtensionImagesFromUnlinked()
    {
        return (int) ComponentHelper::getParams('com_mediacleaner')->get('hide_active_extension_images_from_unlinked', 0) === 1;
    }

    /**
     * SQL condition that excludes files flagged as sitting in an active
     * component's /images/<x> folder (see markActiveExtensionAssetStatus())
     * from the bulk-hint actions (see applyBulkHintProtectionExclusions()).
     * Returns null when the option (see
     * hideActiveExtensionImagesFromUnlinked()) is off, so callers can skip
     * appending anything.
     *
     * @param   \Joomla\Database\DatabaseDriver  $db
     *
     * @return  string|null
     */
    protected function getActiveExtensionImagesExclusionSql($db)
    {
        if (!$this->hideActiveExtensionImagesFromUnlinked()) {
            return null;
        }

        return $db->quoteName('is_active_extension_images_dir') . ' = 0';
    }

    /**
     * Appends every enabled "protect from bulk delete" exclusion (see
     * getActiveExtensionAssetExclusionSql() and
     * getActiveExtensionImagesExclusionSql()) to a query already filtered
     * to `linked = 0` - used by getIdsForFilter($excludeProtected = true),
     * i.e. whenever "Actie voor geselecteerde items" is about to delete
     * files, whether via checkbox selection or "select all matching".
     *
     * Before v2.6.0, this same method (then called
     * applyUnlinkedViewExclusions()) was also used by getItems(),
     * getFilteredCount(), getFilteredTotalSizeKB() and getUnlinkedCount()
     * to hide these files from "Niet-gekoppelde media" entirely. Wouter
     * pointed out that broke a basic invariant a site owner would
     * reasonably expect - "Alle media" should always equal "Gekoppelde
     * media" + "Niet-gekoppelde media" - since a file could be neither
     * `linked = 1` nor visible in the Unlinked view at all, silently
     * vanishing from both counts. Fixed by narrowing this method's scope
     * to just the one place still hiding something matters for safety
     * (see hideActiveExtensionAssetsFromUnlinked()'s docblock): a file
     * belonging to an active extension is still always shown and counted
     * in "Niet-gekoppelde media" now, just excluded from the bulk-delete-
     * by-hint action so it can't be swept away by accident alongside
     * genuinely orphaned files sharing the same hint name. The
     * thumbs-folder exclusion this method used to also apply is gone
     * entirely - thumbs-dir files never carry an extension-name hint in
     * the first place, so they were never reachable via the bulk-hint
     * action either way; see showThumbsDirHint() for what that option
     * controls now instead.
     *
     * @param   \Joomla\Database\QueryInterface  $query
     * @param   \Joomla\Database\DatabaseDriver  $db
     *
     * @return  void
     */
    protected function applyBulkHintProtectionExclusions($query, $db)
    {
        $activeExtensionExclusion = $this->getActiveExtensionAssetExclusionSql($db);

        if ($activeExtensionExclusion !== null) {
            $query->where($activeExtensionExclusion);
        }

        $activeExtensionImagesExclusion = $this->getActiveExtensionImagesExclusionSql($db);

        if ($activeExtensionImagesExclusion !== null) {
            $query->where($activeExtensionImagesExclusion);
        }
    }

    /**
     * Applies the "linked" + "ignored" half of the overview's filtering -
     * shared by getItems(), getFilteredCount(), getFilteredTotalSizeKB(),
     * getExtensionFilterCounts() and getIdsForFilter(), so the five can
     * never drift out of sync about which rows a given $filterLinked
     * bucket actually contains (see the v2.4.0 postmortem on exactly
     * that class of bug).
     *
     * @param   \Joomla\Database\QueryInterface  $query
     * @param   \Joomla\Database\DatabaseDriver  $db
     * @param   string                           $filterLinked  'all', 'linked', 'unlinked' or 'ignored'.
     * @param   boolean                          $showIgnored   Whether ignored files are included (irrelevant when $filterLinked = 'ignored').
     *
     * @return  void
     */
    protected function applyLinkedIgnoredWhere($query, $db, $filterLinked, $showIgnored)
    {
        if ($filterLinked === 'linked') {
            $query->where($db->quoteName('linked') . ' = 1');
        } elseif ($filterLinked === 'unlinked') {
            $query->where($db->quoteName('linked') . ' = 0');
        }

        if ($filterLinked === 'ignored') {
            // Its own bucket, deliberately not constrained by linked
            // status - every ignored file, whether it was linked or
            // unlinked when it got ignored.
            $query->where($db->quoteName('ignored') . ' = 1');
        } elseif (!$showIgnored) {
            $query->where($db->quoteName('ignored') . ' = 0');
        }
    }

    /**
     * Applies the "hoort mogelijk bij extensie X" filter - shared by the
     * same methods as applyLinkedIgnoredWhere(). A blank/whitespace-only
     * $extensionHint applies no constraint at all (matches every hinted
     * AND every un-hinted file), used for "Alle extensies" in the new
     * v2.7.5 per-tab extension filter dropdown.
     *
     * @param   \Joomla\Database\QueryInterface  $query
     * @param   \Joomla\Database\DatabaseDriver  $db
     * @param   string                           $extensionHint
     *
     * @return  void
     */
    protected function applyExtensionHintWhere($query, $db, $extensionHint)
    {
        $extensionHint = trim((string) $extensionHint);

        if ($extensionHint === '') {
            return;
        }

        $query->where(
            '(' . $db->quoteName('images_dir_extension_name') . ' = ' . $db->quote($extensionHint)
            . ' OR ' . $db->quoteName('asset_dir_extension_name') . ' = ' . $db->quote($extensionHint) . ')'
        );
    }

    /**
     * Applies the "Toon systeembestanden"/"Toon overige bestanden"
     * sub-filter (v2.7.9, "Genegeerde media" only) - shared by the same
     * methods as applyLinkedIgnoredWhere()/applyExtensionHintWhere().
     *
     * @param   \Joomla\Database\QueryInterface  $query
     * @param   \Joomla\Database\DatabaseDriver  $db
     * @param   string                           $systemAssetFilter  '', 'system' or 'other'. Blank applies no constraint.
     *
     * @return  void
     */
    protected function applySystemAssetFilterWhere($query, $db, $systemAssetFilter)
    {
        if ($systemAssetFilter === 'system') {
            $query->where($db->quoteName('is_system_asset_dir') . ' = 1');
        } elseif ($systemAssetFilter === 'other') {
            $query->where($db->quoteName('is_system_asset_dir') . ' = 0');
        }
    }

    /**
     * Get the sorted/filtered list of media files, read from the cached
     * database table (fast, no filesystem access).
     *
     * v2.2.0: added $limit/$limitstart (standard Joomla pagination - see
     * HtmlView::display()). Before this, every call returned the entire
     * filtered result set in one go, which the Files template then
     * rendered as one giant table - fine on small sites, but on a
     * library of several thousand files this made the admin page's own
     * memory footprint scale directly with total library size, up to
     * exhausting PHP's memory_limit on large sites (see the
     * "images-dir extension hint" v2.1.0 postmortem). Passing $limit = 0
     * preserves the old "return everything" behaviour, so existing
     * callers (e.g. the rescan summary) that legitimately need the full
     * set are unaffected.
     *
     * @param   string   $order              Column to sort on: size, type, name, path or linked.
     * @param   string   $direction          ASC or DESC.
     * @param   string   $filterLinked       'all', 'linked' or 'unlinked' to filter the overview.
     * @param   boolean  $showIgnored        Whether to include files marked as "ignored".
     * @param   integer  $limit              Max rows to return, or 0 for no limit (all rows).
     * @param   integer  $limitstart         Zero-based offset to start from - ignored when $limit is 0.
     * @param   string   $extensionHint      v2.7.5: only files whose "possibly belongs to extension"
     *                                       hint matches exactly (see applyExtensionHintWhere()); blank
     *                                       for no constraint.
     * @param   string   $systemAssetFilter  v2.7.9: '', 'system' or 'other' (see applySystemAssetFilterWhere()).
     *
     * @return  array
     */
    public function getItems($order = 'size', $direction = 'DESC', $filterLinked = 'all', $showIgnored = false, $limit = 0, $limitstart = 0, $extensionHint = '', $systemAssetFilter = '')
    {
        $columnMap = [
            'size'   => 'size',
            'type'   => 'type',
            'name'   => 'name',
            'path'   => 'path',
            'linked' => 'linked',
        ];

        if (!isset($columnMap[$order])) {
            $order = 'size';
        }

        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__mediacleaner_files'));

        $this->applyLinkedIgnoredWhere($query, $db, $filterLinked, $showIgnored);
        $this->applyExtensionHintWhere($query, $db, $extensionHint);
        $this->applySystemAssetFilterWhere($query, $db, $systemAssetFilter);

        $query->order($db->quoteName($columnMap[$order]) . ' ' . $direction);

        if ($order !== 'name') {
            $query->order($db->quoteName('name') . ' ASC');
        }

        if ((int) $limit > 0) {
            $query->setLimit((int) $limit, max(0, (int) $limitstart));
        }

        $db->setQuery($query);

        try {
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            $size = (int) $row['size'];

            $items[] = [
                'id'             => (int) $row['id'],
                'name'           => $row['name'],
                'path'           => $row['path'],
                'size'           => $size,
                'sizeKB'         => $size / 1024,
                'type'           => $row['type'],
                'url'            => $row['url'],
                'linked'         => (int) $row['linked'] === 1,
                'linkConfidence' => $row['link_confidence'] ?? ((int) $row['linked'] === 1 ? 'confirmed' : 'none'),
                'ignored'        => (int) $row['ignored'] === 1,
                'noPreview'      => (int) $row['no_preview'] === 1,
                'isThumbsDir'    => (int) ($row['is_thumbs_dir'] ?? 0) === 1,
                'isSystemAssetDir'   => (int) ($row['is_system_asset_dir'] ?? 0) === 1,
                'likelyManualUpload' => (int) ($row['likely_manual_upload'] ?? 0) === 1,
                'possiblyOrphanedExtension' => (int) ($row['possibly_orphaned_extension'] ?? 0) === 1,
                'imagesDirExtensionName'    => $row['images_dir_extension_name'] ?? null,
                'imagesDirExtensionRemoved' => (int) ($row['images_dir_extension_removed'] ?? 0) === 1,
                'assetDirExtensionName'     => $row['asset_dir_extension_name'] ?? null,
                'assetDirExtensionRemoved'  => (int) ($row['asset_dir_extension_removed'] ?? 0) === 1,
                'references'     => [],
            ];
        }

        if ($filterLinked === 'linked' && !empty($items)) {
            $items = $this->attachReferences($items);
        }

        return $items;
    }

    /**
     * Attach the stored "where is this file used" references to each item,
     * matched by relative path.
     *
     * @param   array  $items
     *
     * @return  array
     */
    protected function attachReferences(array $items)
    {
        $db        = $this->getDatabase();
        $relatives = [];

        foreach ($items as $item) {
            $relatives[] = trim($item['path'], '/') . '/' . $item['name'];
        }

        $relatives = array_unique($relatives);

        if (empty($relatives)) {
            return $items;
        }

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__mediacleaner_references'))
            ->where($db->quoteName('relative_path') . ' IN (' . implode(',', array_map([$db, 'quote'], $relatives)) . ')');

        $db->setQuery($query);

        try {
            $refRows = $db->loadAssocList();
        } catch (\Exception $e) {
            return $items;
        }

        $refsByPath = [];

        foreach ($refRows as $ref) {
            $refsByPath[$ref['relative_path']][] = [
                'type'  => $ref['source_type'],
                'title' => $ref['title'],
                'url'   => $ref['edit_url'],
            ];
        }

        foreach ($items as &$item) {
            $relative           = trim($item['path'], '/') . '/' . $item['name'];
            $item['references'] = $refsByPath[$relative] ?? [];
        }

        unset($item);

        return $items;
    }

    /**
     * Timestamp of the last completed scan, or null if a scan has never run.
     *
     * @return  string|null
     */
    public function getLastScanTime()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('MAX(' . $db->quoteName('scanned_at') . ')')
            ->from($db->quoteName('#__mediacleaner_files'));

        $db->setQuery($query);

        try {
            return $db->loadResult();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Count of files matching the exact same filter combination as
     * getItems() would return, without fetching the rows themselves -
     * used to show a live count on each filter button ("Alle media
     * (3266)", "Gekoppelde media (491)", ...). Kept as one shared method
     * (rather than one bespoke query per button) so the counts can never
     * drift out of sync with what clicking that button actually shows.
     *
     * @param   string   $filterLinked  'all', 'linked' or 'unlinked'.
     * @param   boolean  $showIgnored   Whether ignored files are included.
     * @param   string   $extensionHint v2.7.5: restrict to this exact hint; blank for no constraint.
     * @param   string   $systemAssetFilter v2.7.9: '', 'system' or 'other'.
     *
     * @return  integer
     */
    public function getFilteredCount($filterLinked = 'all', $showIgnored = false, $extensionHint = '', $systemAssetFilter = '')
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__mediacleaner_files'));

        $this->applyLinkedIgnoredWhere($query, $db, $filterLinked, $showIgnored);
        $this->applyExtensionHintWhere($query, $db, $extensionHint);
        $this->applySystemAssetFilterWhere($query, $db, $systemAssetFilter);

        $db->setQuery($query);

        try {
            return (int) $db->loadResult();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Total size (in KB) of every file matching the exact same filter
     * combination as getFilteredCount()/getItems() - i.e. the true total
     * across the whole filtered set, independent of pagination. Needed
     * since v2.2.0: with getItems() now only returning one page at a
     * time, summing $item['size'] over the returned page (the old
     * approach, see HtmlView::display()) would only reflect that page,
     * not the library-wide total shown in the summary line.
     *
     * @param   string   $filterLinked  'all', 'linked' or 'unlinked'.
     * @param   boolean  $showIgnored   Whether ignored files are included.
     * @param   string   $extensionHint v2.7.5: restrict to this exact hint; blank for no constraint.
     * @param   string   $systemAssetFilter v2.7.9: '', 'system' or 'other'.
     *
     * @return  float
     */
    public function getFilteredTotalSizeKB($filterLinked = 'all', $showIgnored = false, $extensionHint = '', $systemAssetFilter = '')
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('SUM(' . $db->quoteName('size') . ')')
            ->from($db->quoteName('#__mediacleaner_files'));

        $this->applyLinkedIgnoredWhere($query, $db, $filterLinked, $showIgnored);
        $this->applyExtensionHintWhere($query, $db, $extensionHint);
        $this->applySystemAssetFilterWhere($query, $db, $systemAssetFilter);

        $db->setQuery($query);

        try {
            return ((float) $db->loadResult()) / 1024;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Number of files currently flagged as "not linked" AND not ignored,
     * i.e. files that still genuinely need review. Shown as a hint even
     * when a filter hides them. Deliberately counts every such file,
     * without any "hide from Unlinked" exclusion (see the v2.6.0 note on
     * applyBulkHintProtectionExclusions()) - this is the same count
     * getFilteredCount('unlinked') and the "Niet-gekoppelde media" tab
     * badge show, and it's important they always agree.
     *
     * @return  integer
     */
    public function getUnlinkedCount()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__mediacleaner_files'))
            ->where($db->quoteName('linked') . ' = 0')
            ->where($db->quoteName('ignored') . ' = 0');

        $db->setQuery($query);

        try {
            return (int) $db->loadResult();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Number of files currently marked as "ignored" (hidden from the
     * overview by default).
     *
     * @return  integer
     */
    public function getIgnoredCount()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__mediacleaner_files'))
            ->where($db->quoteName('ignored') . ' = 1');

        $db->setQuery($query);

        try {
            return (int) $db->loadResult();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Distinct "possibly belongs to extension" hint names (see
     * `images_dir_extension_name` and `asset_dir_extension_name` in
     * Scanner.php - a file only ever has one of the two set, since a
     * path is either under /images/ or under one of
     * /components//modules//plugins//templates/, never both) currently
     * present among not-yet-ignored files matching $filterLinked, each
     * with how many files carry it. Grouped purely by name, regardless of
     * whether that extension is still installed or just remembered as
     * "probably removed".
     *
     * v2.7.5: this used to back a dedicated "bulk negeren/verwijderen per
     * hint" bar with its own active-extension protection logic (only
     * `count` after excluding protected files, plus a separate
     * `deletableCount`). Wouter asked for something simpler and more
     * consistent instead: a plain extension-filter dropdown under all
     * four main tabs (Alle/Gekoppeld/Niet-gekoppeld/Genegeerd) that just
     * narrows the visible list, with the *existing* "Actie voor
     * geselecteerde items" bar (now extended with a "select all matching,
     * alle pagina's" mode - see getIdsForFilter()) doing the actual bulk
     * work. So this is now a plain, unprotected count - exactly what
     * getFilteredCount($filterLinked, false, $name) would also return for
     * each name - and the active-extension delete protection lives
     * entirely in getIdsForFilter() now, applied at the moment of action
     * rather than hidden from view beforehand.
     *
     * @param   string   $filterLinked  'all', 'linked', 'unlinked' or 'ignored'.
     *
     * @return  array  List of ['name' => string, 'count' => int], ordered by count descending.
     */
    public function getExtensionFilterCounts($filterLinked = 'all')
    {
        $db           = $this->getDatabase();
        $hintNameExpr = 'COALESCE(' . $db->quoteName('images_dir_extension_name') . ', ' . $db->quoteName('asset_dir_extension_name') . ')';

        $query = $db->getQuery(true)
            ->select([
                $hintNameExpr . ' AS ' . $db->quoteName('name'),
                'COUNT(*) AS ' . $db->quoteName('count'),
            ])
            ->from($db->quoteName('#__mediacleaner_files'));

        $this->applyLinkedIgnoredWhere($query, $db, $filterLinked, false);

        $query->where($hintNameExpr . ' IS NOT NULL')
            ->group($hintNameExpr)
            ->order($db->quoteName('count') . ' DESC');

        $db->setQuery($query);

        try {
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return [];
        }

        return array_map(
            static function ($row) {
                return ['name' => $row['name'], 'count' => (int) $row['count']];
            },
            $rows
        );
    }

    /**
     * Numeric ids of every file matching the current $filterLinked +
     * $extensionHint filter - the "select all matching, alle pagina's"
     * counterpart to the per-page checkbox selection in "Actie voor
     * geselecteerde items" (see admin/tmpl/files/default.php). Shares
     * applyLinkedIgnoredWhere()/applyExtensionHintWhere() with
     * getItems()/getFilteredCount(), so "select all matching" can never
     * select a different set of files than what's actually on screen.
     *
     * @param   string   $filterLinked      'all', 'linked', 'unlinked' or 'ignored'.
     * @param   string   $extensionHint     Restrict to this exact hint; blank for no constraint.
     * @param   boolean  $excludeProtected  v2.7.5: whether to apply the active-extension protection
     *                                      exclusion (see applyBulkHintProtectionExclusions()) -
     *                                      pass true for anything that deletes/quarantines files,
     *                                      false for ignoring/un-ignoring, which are always safely
     *                                      reversible per file. Never applied for $filterLinked =
     *                                      'linked' or 'ignored' regardless of this flag - deleting
     *                                      by filter alone is never offered for either (see
     *                                      FilesController::delete()'s handling of select_all_matching).
     * @param   string   $systemAssetFilter v2.7.9: '', 'system' or 'other'.
     *
     * @return  array  List of integer ids.
     */
    public function getIdsForFilter($filterLinked = 'all', $extensionHint = '', $excludeProtected = false, $systemAssetFilter = '')
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__mediacleaner_files'));

        $this->applyLinkedIgnoredWhere($query, $db, $filterLinked, false);
        $this->applyExtensionHintWhere($query, $db, $extensionHint);
        $this->applySystemAssetFilterWhere($query, $db, $systemAssetFilter);

        if ($excludeProtected && !in_array($filterLinked, ['linked', 'ignored'], true)) {
            $this->applyBulkHintProtectionExclusions($query, $db);
        }

        $db->setQuery($query);

        try {
            return array_map('intval', $db->loadColumn());
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * How many not-yet-ignored files matching $filterLinked are sitting
     * in a generic bundled-asset folder (see
     * Scanner::pathHasSystemAssetSegment()) AND were NOT flagged as a
     * likely later manual upload into that same folder (see
     * Scanner::applyManualUploadOutlierDetection()) - i.e. exactly the
     * set the "Zet systeembestanden apart" button (see
     * ignoreSystemAssetFiles()) would act on. Shown next to that button
     * so its confirm dialog can state a real number.
     *
     * @param   string  $filterLinked  'all', 'linked', 'unlinked' or 'ignored'.
     *
     * @return  integer
     */
    public function getSystemAssetFileCount($filterLinked = 'unlinked')
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__mediacleaner_files'));

        $this->applyLinkedIgnoredWhere($query, $db, $filterLinked, false);

        $query->where($db->quoteName('is_system_asset_dir') . ' = 1')
            ->where($db->quoteName('likely_manual_upload') . ' = 0');

        $db->setQuery($query);

        try {
            return (int) $db->loadResult();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Ignores every not-yet-ignored file matching $filterLinked that's
     * sitting in a generic bundled-asset folder AND wasn't flagged as a
     * likely manual upload (see getSystemAssetFileCount() - same
     * criteria, kept in sync by both reading through
     * applyLinkedIgnoredWhere()). The "met een druk op de knop" bulk
     * action Wouter asked for - deliberately ignore-only, same as every
     * other heuristic-based bulk classification in this component
     * (auto-ignore-thumbs, the old hint-bulk-bar): a folder-name-plus-
     * date guess is not grounds for an irreversible delete, only for
     * moving a file out of the pile that needs a human look.
     *
     * @param   string  $filterLinked  'all', 'linked', 'unlinked' or 'ignored'.
     *
     * @return  integer  Number of files ignored.
     *
     * @throws  \Exception  When the current user is not allowed to manage this component.
     */
    public function ignoreSystemAssetFiles($filterLinked = 'unlinked')
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.manage', 'com_mediacleaner')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__mediacleaner_files'));

        $this->applyLinkedIgnoredWhere($query, $db, $filterLinked, false);

        $query->where($db->quoteName('is_system_asset_dir') . ' = 1')
            ->where($db->quoteName('likely_manual_upload') . ' = 0');

        $db->setQuery($query);

        try {
            $ids = array_map('intval', $db->loadColumn());
        } catch (\Exception $e) {
            return 0;
        }

        if (empty($ids)) {
            return 0;
        }

        return $this->doSetIgnored($ids, true);
    }

    /**
     * Mark or unmark one or more files (by their `#__mediacleaner_files`
     * id) as "ignored". Ignored files are hidden from the overview by
     * default and are excluded from the "unlinked" hint count, but are not
     * moved or deleted in any way. The choice is stored separately (by
     * relative path) so it survives the next rescan.
     *
     * @param   array    $ids      Numeric ids from `#__mediacleaner_files`.
     * @param   boolean  $ignored  True to ignore, false to un-ignore.
     *
     * @return  integer  Number of files updated.
     *
     * @throws  \Exception  When the current user is not allowed to manage this component.
     */
    public function setIgnored(array $ids, $ignored)
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.manage', 'com_mediacleaner')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        return $this->doSetIgnored($ids, $ignored);
    }

    /**
     * Actual "ignore/un-ignore" implementation, without any permission
     * check of its own. Callers (the admin controller via setIgnored()
     * above, or the site component with its own, separate frontend
     * permissions) are responsible for authorising the request first.
     *
     * @param   array    $ids      Numeric ids from `#__mediacleaner_files`.
     * @param   boolean  $ignored  True to ignore, false to un-ignore.
     *
     * @return  integer  Number of files updated.
     */
    protected function doSetIgnored(array $ids, $ignored)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids)) {
            return 0;
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select(['id', 'path', 'name'])
            ->from($db->quoteName('#__mediacleaner_files'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');

        $db->setQuery($query);

        try {
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return 0;
        }

        if (empty($rows)) {
            return 0;
        }

        $updateQuery = $db->getQuery(true)
            ->update($db->quoteName('#__mediacleaner_files'))
            ->set($db->quoteName('ignored') . ' = ' . ($ignored ? 1 : 0))
            ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');

        $db->setQuery($updateQuery)->execute();

        $now = Factory::getDate()->toSql();

        foreach ($rows as $row) {
            $relative = trim($row['path'], '/') . '/' . $row['name'];

            if ($ignored) {
                // A deliberate "Negeren" action always wins over
                // whatever was there before (a prior auto_thumbs
                // suggestion, a suppression marker, or nothing) - drop
                // any existing row and record a fresh, plain manual
                // ignore.
                $deleteQuery = $db->getQuery(true)
                    ->delete($db->quoteName('#__mediacleaner_ignored'))
                    ->where($db->quoteName('relative_path') . ' = ' . $db->quote($relative));

                $db->setQuery($deleteQuery)->execute();

                $insertQuery = $db->getQuery(true)
                    ->insert($db->quoteName('#__mediacleaner_ignored'))
                    ->columns($db->quoteName(['relative_path', 'ignored_at', 'source', 'suppressed']))
                    ->values($db->quote($relative) . ',' . $db->quote($now) . ",'manual',0");

                $db->setQuery($insertQuery)->execute();

                continue;
            }

            // Un-ignoring. If the existing row is this component's own
            // auto_thumbs suggestion (see Scanner::applyAutoIgnoreThumbs()),
            // don't delete it outright - flip it to a permanent
            // suppressed marker instead, so a future rescan's
            // auto-ignore pass sees this exact file was already decided
            // and leaves it alone. A plain manual ignore (or no row at
            // all) has nothing to protect against re-application, so it
            // can just be deleted as before.
            $sourceQuery = $db->getQuery(true)
                ->select($db->quoteName('source'))
                ->from($db->quoteName('#__mediacleaner_ignored'))
                ->where($db->quoteName('relative_path') . ' = ' . $db->quote($relative));

            $db->setQuery($sourceQuery);

            try {
                $existingSource = $db->loadResult();
            } catch (\Exception $e) {
                $existingSource = null;
            }

            if ($existingSource === 'auto_thumbs') {
                $suppressQuery = $db->getQuery(true)
                    ->update($db->quoteName('#__mediacleaner_ignored'))
                    ->set($db->quoteName('suppressed') . ' = 1')
                    ->where($db->quoteName('relative_path') . ' = ' . $db->quote($relative));

                $db->setQuery($suppressQuery)->execute();

                continue;
            }

            $deleteQuery = $db->getQuery(true)
                ->delete($db->quoteName('#__mediacleaner_ignored'))
                ->where($db->quoteName('relative_path') . ' = ' . $db->quote($relative));

            $db->setQuery($deleteQuery)->execute();
        }

        return count($rows);
    }

    /**
     * Number of files currently sitting in quarantine, awaiting review.
     *
     * @return  integer
     */
    public function getQuarantineCount()
    {
        return $this->getQuarantineManager()->getCount();
    }

    /**
     * Move one or more files (by their `#__mediacleaner_files` id) to the
     * protected quarantine folder, and record enough information to allow
     * restoring them later. The files table row is removed once a file has
     * been moved, since the overview always reflects what's currently on
     * disk.
     *
     * @param   array  $ids  Numeric ids from `#__mediacleaner_files`.
     *
     * @return  array  ['moved' => int, 'errors' => int]
     *
     * @throws  \Exception  When the current user is not allowed to manage this component.
     */
    public function quarantine(array $ids)
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.manage', 'com_mediacleaner')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        return $this->getQuarantineManager()->quarantine($ids);
    }

    /**
     * Run a fresh filesystem scan and replace the cached table contents.
     * The actual scan-and-link work happens in Scanner::scan(); this
     * method's own job is just persisting the result to
     * `#__mediacleaner_files` / `#__mediacleaner_references`, same as
     * before the v1.37.0 split.
     *
     * @return  array  ['count' => int, 'sizeKB' => float]
     *
     * @throws  \Exception  When the current user is not allowed to manage this component.
     */
    public function rescan()
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.manage', 'com_mediacleaner')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $result         = $this->getScanner()->scan();
        $items          = $result['items'];
        $referenceIndex = $result['referenceIndex'];

        $db  = $this->getDatabase();
        $now = Factory::getDate()->toSql();

        $db->truncateTable('#__mediacleaner_files');

        foreach (array_chunk($items, 200) as $chunk) {
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__mediacleaner_files'))
                ->columns($db->quoteName(['name', 'path', 'size', 'type', 'url', 'linked', 'link_confidence', 'ignored', 'no_preview', 'is_thumbs_dir', 'is_active_extension_asset', 'is_active_extension_images_dir', 'possibly_orphaned_extension', 'images_dir_extension_name', 'images_dir_extension_removed', 'asset_dir_extension_name', 'asset_dir_extension_removed', 'file_modified_at', 'is_system_asset_dir', 'likely_manual_upload', 'scanned_at']));

            foreach ($chunk as $row) {
                $query->values(
                    implode(',', [
                        $db->quote($row['name']),
                        $db->quote($row['path']),
                        (int) $row['size'],
                        $db->quote($row['type']),
                        $db->quote($row['url']),
                        $row['linked'] ? 1 : 0,
                        $db->quote($row['linkConfidence'] ?? 'none'),
                        $row['ignored'] ? 1 : 0,
                        !empty($row['noPreview']) ? 1 : 0,
                        !empty($row['isThumbsDir']) ? 1 : 0,
                        !empty($row['isActiveExtensionAsset']) ? 1 : 0,
                        !empty($row['isActiveExtensionImagesDir']) ? 1 : 0,
                        !empty($row['possiblyOrphanedExtension']) ? 1 : 0,
                        $row['imagesDirExtensionName'] !== null && $row['imagesDirExtensionName'] !== '' ? $db->quote($row['imagesDirExtensionName']) : 'NULL',
                        !empty($row['imagesDirExtensionRemoved']) ? 1 : 0,
                        $row['assetDirExtensionName'] !== null && $row['assetDirExtensionName'] !== '' ? $db->quote($row['assetDirExtensionName']) : 'NULL',
                        !empty($row['assetDirExtensionRemoved']) ? 1 : 0,
                        !empty($row['modifiedAt']) ? $db->quote($row['modifiedAt']) : 'NULL',
                        !empty($row['isSystemAssetDir']) ? 1 : 0,
                        !empty($row['likelyManualUpload']) ? 1 : 0,
                        $db->quote($now),
                    ])
                );
            }

            $db->setQuery($query)->execute();
        }

        $db->truncateTable('#__mediacleaner_references');

        $referenceRows = [];

        foreach ($referenceIndex as $relativePath => $refs) {
            foreach ($refs as $ref) {
                $referenceRows[] = [
                    'relative_path' => $relativePath,
                    'source_type'   => $ref['type'],
                    'title'         => $ref['title'],
                    'edit_url'      => $ref['url'],
                ];
            }
        }

        foreach (array_chunk($referenceRows, 200) as $chunk) {
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__mediacleaner_references'))
                ->columns($db->quoteName(['relative_path', 'source_type', 'title', 'edit_url']));

            foreach ($chunk as $row) {
                $query->values(
                    implode(',', [
                        $db->quote($row['relative_path']),
                        $db->quote($row['source_type']),
                        $db->quote($row['title']),
                        $row['edit_url'] !== null ? $db->quote($row['edit_url']) : 'NULL',
                    ])
                );
            }

            $db->setQuery($query)->execute();
        }

        $bytes = 0;

        foreach ($items as $item) {
            $bytes += $item['size'];
        }

        return [
            'count'  => count($items),
            'sizeKB' => $bytes / 1024,
        ];
    }

    /**
     * Convert a single file (by its `#__mediacleaner_files` id) to a new
     * `.webp` file placed alongside the original, using a fixed quality of
     * 80. The original file is never touched, overwritten or deleted -
     * this only ever adds a new file. jpg/jpeg/png go through the GD
     * extension; tif/tiff needs the Imagick PHP extension.
     *
     * @param   integer  $id  Id from `#__mediacleaner_files`.
     *
     * @return  array  ['success' => bool, ...] - see inline reason codes below.
     *
     * @throws  \Exception  When the current user is not allowed to manage this component.
     */
    public function convertToWebp($id)
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.manage', 'com_mediacleaner')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        return $this->getWebpConverter()->convert($id);
    }
}
