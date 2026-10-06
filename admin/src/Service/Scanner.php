<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Filesystem scanner and reference-linking engine: walks the Joomla
 * webroot for media files, then works out which of them are still
 * referenced somewhere (articles, modules, custom fields, template/
 * plugin/component source, and a generic database-wide sweep) versus
 * genuinely unlinked.
 *
 * v1.37.0: extracted out of FilesModel.php (which had grown past 2700
 * lines) as part of a deliberate split into single-purpose classes -
 * Scanner, QuarantineManager, WebpConverter - each independently
 * reviewable. Structural-only change: no behaviour was altered, every
 * method body was moved verbatim; only `$this->getDatabase()` became
 * `$this->db` (constructor-injected instead of inherited from Joomla's
 * model base class) and the top-level scan() orchestration method is new
 * (it's exactly what FilesModel::rescan() used to do inline before the
 * split - scan, link, tag, then hand back to the model for persistence).
 *
 * v2.8.3: this class holds the building blocks - walking the filesystem,
 * matching text against the scanned files, classifying files - each of
 * them resumable. Running them in order, keeping the intermediate result
 * between requests and writing the outcome to the database is ScanJob's
 * job (a subclass, so it can use the protected methods here).
 */
class Scanner
{
    /**
     * File extensions that are considered "media files" for this tool,
     * grouped by category purely for documentation purposes below (the
     * actual list used for scanning is the flat array).
     *
     * Images:  jpg, jpeg, png, webp, svg, gif, avif, tif, tiff
     * Video:   mp4, webm, ogv
     * Audio:   mp3, wav, m4a, aac, ogg
     * Docs:    pdf
     *
     * @var array
     */
    protected $extensions = [
        // Images
        'jpg', 'jpeg', 'png', 'webp', 'svg', 'gif', 'avif', 'tif', 'tiff',
        // Video
        'mp4', 'webm', 'ogv',
        // Audio
        'mp3', 'wav', 'm4a', 'aac', 'ogg',
        // Documents
        'pdf',
    ];

    /**
     * Top level folders that are skipped while scanning (system/vendor
     * folders that never contain content the site owner needs to review).
     *
     * @var array
     */
    protected $excludeDirs = [
        'administrator',
        'cache',
        'cli',
        'installation',
        'libraries',
        'log',
        'logs',
        'tmp',
        'vendor',
        'node_modules',
        'api',
        'media',
    ];

    /**
     * @var \Joomla\Database\DatabaseDriver
     */
    protected $db;

    /**
     * Table name patterns (glob syntax with * wildcards, matched
     * case-insensitively) excluded from the generic database sweep (see
     * sweepGenericTables()). Covers this component's own bookkeeping
     * tables (scanning those would be circular), plus sessions, logs,
     * caches and version-backup tables from other extensions.
     *
     * @var array
     */
    protected $genericScanExcludeTablePatterns = [
        '#__mediacleaner_*',
        '#__session',
        '*_session',
        '*_sessions',
        '*_log',
        '*_logs',
        '*_logging',
        '*_log_*',
        '*cache*',
        '*_backup_*',
        '*_backups',
        '*messages_text',
        '*private_messages*',
        '*plugin_messages*',
        '*finder_*',
        '*redirect_links*',
        // v2.8.3: Joomla's article version history. An image that only
        // still occurs in an *old version* of an article is not in use
        // on the site, so it must not count as linked (on niburu.co this
        // table held 72,000 old versions, 700 MB).
        '#__history',
        '#__extensions',
        '#__update_sites*',
        '#__schemas',
        '#__assets',
        '#__usergroups',
        '#__user_usergroup_map',
    ];

    /**
     * Directories (relative to JPATH_ROOT) whose PHP/CSS/JS/XML source
     * code is searched for hardcoded media references.
     *
     * @var array
     */
    protected $codeScanDirs = ['templates', 'plugins', 'components', 'modules'];

    /**
     * File extensions read as text during the code sweep above.
     *
     * @var array
     */
    protected $codeScanExtensions = ['php', 'css', 'js', 'xml'];

    /**
     * Safety cap per file during the code sweep, so one unexpectedly huge
     * (or misidentified binary) file can't blow up memory usage.
     *
     * @var integer
     */
    protected $codeScanMaxFileBytes = 2097152; // 2 MB

    /**
     * Wall-clock safety cap (seconds) for the filesystem code sweep
     * specifically - separate from, and smaller than, $haystackMaxSeconds.
     *
     * @var integer
     */
    protected $codeScanMaxSeconds = 10;

    /**
     * Safety cap on any single piece of text checked at once (one file's
     * content, or one database row's concatenated text columns).
     *
     * @var integer
     */
    protected $haystackMaxCellBytes = 1048576; // 1 MB

    /**
     * Wall-clock safety cap (seconds) for the database table sweep
     * (sweepGenericTables()) - the main share of the overall budget.
     *
     * @var integer
     */
    protected $haystackMaxSeconds = 20;

    /**
     * v2.8.3: every table read during a scan goes through
     * forEachTableRow(), which fetches a bounded number of rows per query
     * and adapts that number to how big the rows turn out to be - it
     * starts small, doubles while a chunk stays well under
     * $chunkTargetBytes, and halves as soon as one exceeds it. So a table
     * of short rows is read in few queries, while a table of very long
     * article bodies never has more than a few MB in memory at once.
     *
     * @var integer
     */
    protected $chunkRowsStart = 25;

    /**
     * @var integer
     */
    protected $chunkRowsMin = 5;

    /**
     * @var integer
     */
    protected $chunkRowsMax = 1000;

    /**
     * @var integer
     */
    protected $chunkTargetBytes = 4194304; // 4 MB

    /**
     * Core Joomla tables and the text columns in them that commonly hold
     * media references - read in full, without a time budget, by
     * sweepCuratedContent(). sweepGenericTables() skips exactly these
     * columns (it still reads every *other* text column of the same
     * tables), so the largest table on most sites - `#__content` - is no
     * longer searched twice.
     *
     * @var array
     */
    protected $curatedSources = [
        '#__content'         => ['introtext', 'fulltext', 'images', 'metadesc', 'metakey'],
        '#__modules'         => ['content', 'params'],
        '#__categories'      => ['description', 'params'],
        '#__menu'            => ['link', 'params'],
        '#__fields_values'   => ['value'],
        '#__contact_details' => ['misc', 'address', 'params'],
        '#__banners'         => ['description', 'params'],
    ];

    /**
     * Cache for getExtensionEndRegex() - built once per request.
     *
     * @var string|null
     */
    protected $extensionEndRegex = null;

    /**
     * @param   \Joomla\Database\DatabaseDriver  $db
     */
    public function __construct($db)
    {
        $this->db = $db;
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
     * Generic, not-extension-specific folder-name segments that
     * overwhelmingly mean "bundled UI/library material shipped with an
     * extension" across totally unrelated extensions - matched against
     * `is_system_asset_dir`, which is a separate, independent flag from
     * `is_thumbs_dir` even though "thumbs" is now one of the segments
     * matched here too (see below). Confirmed by inspecting a real,
     * extension-heavy site's scan data (ijk23_mediacleaner_files,
     * September 2026): "assets"/"asset" folders under
     * `.../pagebuilderck/` (one per element type: blog, gallery,
     * carousel, ...), `com_osservicesbooking`, and `com_baforms` all
     * held the same kind of small (under a few KB), generically-named UI
     * icon files (e.g. "arrow_switch.png", "next_green.png") - nothing a
     * content editor would ever produce or upload. "vendor(s)"/
     * "webfonts" are the same idea for bundled third-party libraries
     * (Font Awesome, icomoon, owl-carousel). "themes" was also present
     * in that same data (e.g. a system plugin's own
     * `themes/base/css|vendors/...`) bundling a template/plugin's own
     * skin assets - same reasoning, added on Wouter's request.
     * "yootheme" (v2.7.13, also on Wouter's request) covers the YOOtheme
     * Pro page-builder framework's own bundled folder, wherever it
     * appears in the path - same single-segment-anywhere matching as
     * every other entry here, so a file several levels below "yootheme"
     * still matches (Wouter confirmed this nested-subfolder behaviour is
     * what he wants for "assets" too - it already worked this way from
     * the start, see explode()/in_array() below: every path segment is
     * checked independently, so how many folders come after the
     * matching one makes no difference).
     *
     * "thumbs" (v2.7.10, also on Wouter's request) is added here too, on
     * top of its own separate, dedicated handling
     * (pathHasThumbsSegment(), `is_thumbs_dir`, and
     * Scanner::applyAutoIgnoreThumbs()'s auto-ignore-on-scan behaviour -
     * none of that changes). The two flags simply overlap now for a
     * thumbs-folder file: it's still auto-ignored as before, but it's
     * ALSO tagged `is_system_asset_dir` so it shows up consistently
     * under the "Toon systeembestanden" filter on "Genegeerde media"
     * and, in the rarer case a thumbs file somehow isn't auto-ignored
     * (e.g. the "'thumbs'-mappen" Opties toggle is off), it's also
     * covered by the "Zet systeembestanden apart" button as a fallback.
     * One caveat worth knowing: thumbnails can, in principle, be
     * (re)generated on any date a page happens to need one rather than
     * only at install time, so the manual-upload-date-outlier heuristic
     * (applyManualUploadOutlierDetection()) may cluster less reliably
     * for a thumbs folder than for a genuinely install-once assets
     * folder - a real but minor caveat, since the vast majority of
     * thumbs files never reach this check at all (they're auto-ignored
     * before it would matter).
     *
     * Deliberately NOT included: bare "system" as a segment - it looked
     * promising in that same data (e.g. a template's own
     * "images/system" folder), but "system" is also the literal name of
     * Joomla's own core plugin group folder (`/plugins/system/...`),
     * so matching on it generically would flag a huge swath of unrelated
     * files that just happen to live under a system plugin. Also not
     * included: bare "fonts"/"css"/"js" - real enough as bundled-library
     * signals, but each is common enough as an ordinary, non-generic
     * folder name that the false-positive risk didn't seem worth it
     * without more real-world data to check against first.
     *
     * @param   string  $relDir  Folder path relative to JPATH_ROOT, same
     *                           format as pathHasThumbsSegment().
     *
     * @return  boolean
     */
    protected function pathHasSystemAssetSegment($relDir)
    {
        static $segments = ['assets', 'asset', 'vendor', 'vendors', 'webfonts', 'themes', 'thumbs', 'yootheme'];

        $pathSegments = explode('/', trim($relDir, '/'));

        foreach ($pathSegments as $segment) {
            if (in_array(strtolower($segment), $segments, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Among files flagged `isSystemAssetDir` (see pathHasSystemAssetSegment()),
     * guesses which ones were likely added to that folder by hand later,
     * rather than shipped together with the rest of the extension's own
     * bundled files - purely from each file's own filesystem
     * modification date, grouped per folder.
     *
     * The idea: an extension's own bundled asset files are all copied to
     * disk in one shot, at install or update time, so within any one
     * folder they cluster tightly on a small number of dates. A file a
     * content editor adds later into that same folder gets its own,
     * unrelated date - almost always a clear outlier from that cluster.
     *
     * Per folder (with at least 3 dated files - too few to call anything
     * an "outlier" with confidence otherwise, so everything in a smaller
     * folder is left unflagged): finds the single most common date (day
     * resolution, not exact time - an install can touch files a few
     * seconds apart, but a manual upload is essentially never on the
     * exact same day purely by chance) and flags every file whose date
     * differs from it as `likelyManualUpload`.
     *
     * This is a heuristic, not a certainty - an extension update can
     * shift some-but-not-all of its own files' dates, and pure
     * coincidence can make a manual upload land on the same day as an
     * install. It's used only to pre-select files for the reversible
     * "Negeren" bulk action (never delete) - see
     * FilesModel::ignoreSystemAssetFiles() for how the result is
     * actually used; this method only computes the guess.
     *
     * @param   array  $items
     *
     * @return  array  Same items, 'likelyManualUpload' set on each.
     */
    protected function applyManualUploadOutlierDetection(array $items)
    {
        $byFolder = [];

        foreach ($items as $idx => $item) {
            $items[$idx]['likelyManualUpload'] = false;

            if (empty($item['isSystemAssetDir']) || empty($item['modifiedAt'])) {
                continue;
            }

            $byFolder[$item['path']][] = $idx;
        }

        foreach ($byFolder as $indices) {
            if (count($indices) < 3) {
                // Too few files to trust a "majority date" - leave
                // unflagged rather than guess.
                continue;
            }

            $dateCounts = [];

            foreach ($indices as $idx) {
                $date = substr($items[$idx]['modifiedAt'], 0, 10);
                $dateCounts[$date] = ($dateCounts[$date] ?? 0) + 1;
            }

            arsort($dateCounts);
            $majorityDate = array_key_first($dateCounts);

            foreach ($indices as $idx) {
                if (substr($items[$idx]['modifiedAt'], 0, 10) !== $majorityDate) {
                    $items[$idx]['likelyManualUpload'] = true;
                }
            }
        }

        return $items;
    }

    /**
     * Lowercased element names (e.g. 'com_something') of every enabled
     * site component, read from `#__extensions`.
     *
     * @return  array
     */
    protected function getEnabledComponentElements()
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('element'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('enabled') . ' = 1');

        $db->setQuery($query);

        try {
            $elements = $db->loadColumn();
        } catch (\Exception $e) {
            return [];
        }

        return array_map('strtolower', $elements);
    }

    /**
     * Same set as getEnabledComponentElements(), but with the leading
     * "com_" stripped (e.g. 'com_icagenda' -> 'icagenda'). Many
     * extensions create their own subfolder under /images/ using the
     * bare name rather than the full element - e.g. IC Agenda writes to
     * /images/icagenda/..., not /images/com_icagenda/... - so both forms
     * need to be checked when matching an /images/<x> folder back to its
     * owning component.
     *
     * @return  array
     */
    protected function getEnabledComponentBareNames()
    {
        return array_map(
            static function ($element) {
                return strpos($element, 'com_') === 0 ? substr($element, 4) : $element;
            },
            $this->getEnabledComponentElements()
        );
    }

    /**
     * Lowercased element names of every enabled `type = 'file'` row in
     * `#__extensions`. Joomla uses this extension type for some
     * third-party packages that don't have their own dedicated
     * component/module/plugin/template - they register a 'file' row
     * purely so Joomla can track (and uninstall) the file set - yet the
     * package can still own its own /images/<x> folder using that same
     * element as the folder name. Balbooa Joomla Gallery is a confirmed
     * example: element 'bagallery', type 'file', while its front-end
     * component element was separately renamed to 'com_gallery' (its
     * /images/ assets still live under the original 'bagallery' name).
     * Used alongside getEnabledComponentElements()/
     * getEnabledComponentBareNames() in
     * pathBelongsToActiveExtensionImagesFolder().
     *
     * @return  array
     */
    protected function getEnabledFileTypeElements()
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('element'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('file'))
            ->where($db->quoteName('enabled') . ' = 1');

        $db->setQuery($query);

        try {
            $elements = $db->loadColumn();
        } catch (\Exception $e) {
            return [];
        }

        return array_map('strtolower', $elements);
    }

    /**
     * Lowercased element/folder names of every enabled *site* template
     * (client_id = 0 - the separate administrator/templates tree isn't
     * scanned in the first place, see $excludeDirs), read from
     * `#__extensions`.
     *
     * @return  array
     */
    protected function getEnabledTemplateElements()
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('element'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('enabled') . ' = 1');

        $db->setQuery($query);

        try {
            $elements = $db->loadColumn();
        } catch (\Exception $e) {
            return [];
        }

        return array_map('strtolower', $elements);
    }

    /**
     * Lowercased element/folder names of every enabled *site* module
     * (client_id = 0 - administrator modules live under
     * administrator/modules, which isn't scanned in the first place, see
     * $excludeDirs), read from `#__extensions`. A module's `element`
     * matches its on-disk folder name (e.g. 'mod_login').
     *
     * @return  array
     */
    protected function getEnabledModuleElements()
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('element'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('module'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('enabled') . ' = 1');

        $db->setQuery($query);

        try {
            $elements = $db->loadColumn();
        } catch (\Exception $e) {
            return [];
        }

        return array_map('strtolower', $elements);
    }

    /**
     * Lowercased "folder/element" pairs (e.g. 'system/cache',
     * 'content/pagebreak') of every enabled plugin, read from
     * `#__extensions`. Plugins are the one extension type with a two-
     * level on-disk layout, /plugins/<group>/<element>/... - `folder`
     * holds the group (matches the group's directory name, e.g.
     * 'system', 'content', 'user') and `element` the plugin's own
     * directory name within it, so both are needed to identify a match.
     *
     * @return  array
     */
    protected function getEnabledPluginFolderElementPairs()
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select([$db->quoteName('folder'), $db->quoteName('element')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('enabled') . ' = 1');

        $db->setQuery($query);

        try {
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return [];
        }

        return array_map(
            static function ($row) {
                return strtolower($row['folder']) . '/' . strtolower($row['element']);
            },
            $rows
        );
    }

    /**
     * Every installed plugin (active or not, unlike
     * getEnabledPluginFolderElementPairs()), grouped by lowercased plugin
     * group ('folder'), each mapped to a normalised-element => title map
     * (see normaliseExtensionFolderKey(), resolveExtensionDisplayName()).
     * The plugin counterpart of getAllInstalledExtensionNamesByImagesFolder()
     * - used for the /plugins/<group>/<element> "possibly orphaned
     * extension" hint (see markActiveExtensionAssetStatus()), which
     * needs to name a match even for a disabled or since-uninstalled
     * plugin. Group is kept as a separate, exact-matched key (plugin
     * groups are a small, structural set of directory names - not a
     * descriptive name an extension author might spell differently -
     * only the element segment gets the loosened matching used
     * elsewhere in this class, see matchFolderSegmentToExtension()).
     *
     * @return  array  group => [normalisedElement => title].
     */
    protected function getAllInstalledPluginElementsByGroup()
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['folder', 'element', 'name']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));

        $db->setQuery($query);

        try {
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return [];
        }

        $byGroup = [];

        foreach ($rows as $row) {
            $group   = strtolower((string) $row['folder']);
            $element = strtolower((string) $row['element']);

            if ($group === '' || $element === '' || $row['name'] === '') {
                continue;
            }

            $title = $this->resolveExtensionDisplayName('plugin', $element, $group, $row['name']);

            if ($title === '') {
                continue;
            }

            $byGroup[$group][$this->normaliseExtensionFolderKey($element)] = $title;
        }

        return $byGroup;
    }

    /**
     * Whether a scanned folder path sits directly under /components/<x>,
     * /modules/<x>, /templates/<x> (2 path segments matter), or
     * /plugins/<group>/<element> (3 path segments matter), where <x> /
     * <element> identifies a currently enabled extension. Deeper files
     * still belong to that extension - e.g.
     * /components/com_icagenda/assets/images/themes/foo.png or
     * /plugins/content/pagebreak/images/icon.png both match.
     *
     * Matching is loosened the same way as the /images/<x> hint (see
     * matchFolderSegmentToExtension()) rather than a plain exact
     * comparison: an on-disk folder can legitimately differ from the
     * extension's own `element` in `#__extensions`, the same class of
     * mismatch already fixed for /images/ (e.g. an extension renamed
     * post-install, or a descriptive on-disk name that only starts with
     * the element name). Found in practice under /templates/ - the
     * original motivation for generalising this. For plugins, only the
     * element segment gets the loosened match; the group segment
     * (segments[1]) is matched exactly first, since plugin groups are a
     * small, fixed set of directory names rather than an
     * extension-chosen name.
     *
     * @param   string  $relDir                        Folder path
     *                                                 relative to
     *                                                 JPATH_ROOT, as
     *                                                 produced by
     *                                                 scanFilesystem()
     *                                                 (leading slash, no
     *                                                 trailing slash, ''
     *                                                 for root).
     * @param   array   $normalisedEnabledComponents   Normalised-key
     *                                                 => true map,
     *                                                 covering enabled
     *                                                 components (full
     *                                                 element + bare
     *                                                 name) and enabled
     *                                                 type='file' rows.
     * @param   array   $normalisedEnabledTemplates    Same shape, for
     *                                                 enabled templates
     *                                                 + type='file' rows.
     * @param   array   $normalisedEnabledModules      Same shape, for
     *                                                 enabled modules +
     *                                                 type='file' rows.
     * @param   array   $normalisedEnabledPluginsByGroup   group =>
     *                                                     [normalisedElement
     *                                                     => true] map,
     *                                                     for enabled
     *                                                     plugins.
     *
     * @return  boolean
     */
    protected function pathBelongsToActiveExtension(
        $relDir,
        array $normalisedEnabledComponents,
        array $normalisedEnabledTemplates,
        array $normalisedEnabledModules,
        array $normalisedEnabledPluginsByGroup
    ) {
        $segments = explode('/', trim($relDir, '/'));

        if (count($segments) < 2) {
            return false;
        }

        $top = strtolower($segments[0]);

        if ($top === 'components') {
            return $this->matchFolderSegmentToExtension($segments[1], $normalisedEnabledComponents) !== null;
        }

        if ($top === 'templates') {
            return $this->matchFolderSegmentToExtension($segments[1], $normalisedEnabledTemplates) !== null;
        }

        if ($top === 'modules') {
            return $this->matchFolderSegmentToExtension($segments[1], $normalisedEnabledModules) !== null;
        }

        if ($top === 'plugins') {
            if (count($segments) < 3) {
                return false;
            }

            $group           = strtolower($segments[1]);
            $elementsInGroup = $normalisedEnabledPluginsByGroup[$group] ?? null;

            if ($elementsInGroup === null) {
                return false;
            }

            return $this->matchFolderSegmentToExtension($segments[2], $elementsInGroup) !== null;
        }

        // v2.7.16: /administrator/components/<x>/... - a component's own
        // admin-side assets, same folder shape as /components/<x>/ one
        // level deeper. Found missing when Wouter reported "canvas"/
        // "fonts"/"icon" overrides (all gated on this method returning
        // true) weren't firing for files that turned out to live under
        // paths this method simply never recognised at all.
        if ($top === 'administrator' && isset($segments[1], $segments[2]) && strtolower($segments[1]) === 'components') {
            return $this->matchFolderSegmentToExtension($segments[2], $normalisedEnabledComponents) !== null;
        }

        // v2.7.16: /media/<x>/... - the Joomla 4+ convention for a
        // component/module/plugin/template's own public assets (JS, CSS,
        // images), which has been the *recommended* place for exactly
        // this kind of file for several major versions now and is common
        // on a Joomla 6 site. Same root cause as above: this method
        // never recognised it, so `isActiveExtensionAsset` came back
        // false for any file living there, silently defeating every
        // override that gates on it.
        if ($top === 'media' && isset($segments[1])) {
            $mediaSegment = strtolower($segments[1]);

            // Templates: /media/templates/site/<tpl>/... or
            // /media/templates/administrator/<tpl>/... - an extra two
            // segments deep rather than directly under /media/.
            if ($mediaSegment === 'templates' && isset($segments[2], $segments[3])) {
                return $this->matchFolderSegmentToExtension($segments[3], $normalisedEnabledTemplates) !== null;
            }

            // Plugins: Joomla combines group+element into one folder
            // name, /media/plg_<group>_<element>/... - group and element
            // can each contain underscores, so the split is inherently
            // ambiguous from the folder name alone. Resolved by trying
            // every enabled plugin group as a candidate prefix (there are
            // only ever a handful of groups actually enabled on a given
            // site) rather than guessing where the boundary falls.
            if (strpos($mediaSegment, 'plg_') === 0) {
                $afterPrefix = substr($mediaSegment, 4);

                foreach ($normalisedEnabledPluginsByGroup as $group => $elementsInGroup) {
                    $groupPrefix = strtolower($group) . '_';

                    if (strpos($afterPrefix, $groupPrefix) === 0) {
                        $elementGuess = substr($afterPrefix, strlen($groupPrefix));

                        if ($this->matchFolderSegmentToExtension($elementGuess, $elementsInGroup) !== null) {
                            return true;
                        }
                    }
                }

                return false;
            }

            // Components and modules: matchFolderSegmentToExtension()
            // already strips a "com_"/"mod_" prefix (or matches the bare
            // name directly) via its own progressive-prefix logic, the
            // same as it does for /components/<x>/ and /modules/<x>/
            // above - no special-casing needed here.
            return $this->matchFolderSegmentToExtension($segments[1], $normalisedEnabledComponents) !== null
                || $this->matchFolderSegmentToExtension($segments[1], $normalisedEnabledModules) !== null;
        }

        return false;
    }

    /**
     * Whether a scanned folder path sits directly under /images/<x>,
     * where <x> matches a currently enabled component - either its full
     * element (/images/com_something/...) or the bare name with "com_"
     * stripped (/images/something/..., e.g. IC Agenda's
     * /images/icagenda/...). Only the first two path segments matter -
     * deeper files still belong to that component, e.g.
     * /images/icagenda/thumbs/themes/foo.png matches.
     *
     * @param   string  $relDir                       Folder path relative
     *                                                to JPATH_ROOT, as
     *                                                produced by
     *                                                scanFilesystem()
     *                                                (leading slash, no
     *                                                trailing slash, ''
     *                                                for root).
     * @param   array   $enabledComponents            Result of
     *                                                getEnabledComponentElements().
     * @param   array   $enabledComponentBareNames    Result of
     *                                                getEnabledComponentBareNames().
     * @param   array   $enabledFileElements          Result of
     *                                                getEnabledFileTypeElements()
     *                                                - covers packages
     *                                                like Balbooa Joomla
     *                                                Gallery that own an
     *                                                /images/<x> folder
     *                                                via a 'file'-type
     *                                                `#__extensions` row
     *                                                rather than a
     *                                                component element.
     *
     * @return  boolean
     */
    protected function pathBelongsToActiveExtensionImagesFolder(
        $relDir,
        array $enabledComponents,
        array $enabledComponentBareNames,
        array $enabledFileElements = []
    ) {
        $segments = explode('/', trim($relDir, '/'));

        if (count($segments) < 2 || strtolower($segments[0]) !== 'images') {
            return false;
        }

        $second = strtolower($segments[1]);

        return in_array($second, $enabledComponents, true)
            || in_array($second, $enabledComponentBareNames, true)
            || in_array($second, $enabledFileElements, true);
    }

    /**
     * Lowercases a folder-name-or-element-name candidate and strips every
     * character that isn't a letter or digit, so e.g. 'jch-optimize',
     * 'JCH_Optimize' and 'jchoptimize' all normalise to the same key.
     * Used only for the /images/<x>-to-extension matching in
     * getAllInstalledExtensionNamesByImagesFolder() /
     * pathBelongsToKnownExtensionImagesFolder() - many extensions use a
     * more "readable" folder name for their own /images/ subfolder than
     * their actual Joomla element (e.g. JCH Optimize's element is
     * 'com_jchoptimize', but it writes to /images/jch-optimize/...).
     * Matching stays exact everywhere else in this class (asset-folder
     * shape detection, active-extension checks) - this loosened
     * comparison is deliberately scoped to the images-dir hint only,
     * since it still only ever matches a real, currently installed
     * extension's name, just spelled slightly differently.
     *
     * @param   string  $value
     *
     * @return  string
     */
    protected function normaliseExtensionFolderKey($value)
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }

    /**
     * Element/bare-name-to-title map of *every installed* component,
     * module, plugin, template and 'file'-type extension - regardless of
     * enabled status - read from `#__extensions`. Used to recognise
     * /images/<x> folders that belong to an extension even when that
     * extension is currently disabled (see
     * pathBelongsToKnownExtensionImagesFolder()). Unlike
     * getEnabledComponentElements() and friends, this is not filtered to
     * enabled=1, because a disabled-but-still-installed extension's own
     * /images/<x> folder is just as real as an enabled one's - only a
     * fully *uninstalled* extension needs the separate
     * known-folder-registry fallback (see
     * upsertKnownImagesExtensionFolders() / getKnownImagesExtensionFolders()).
     *
     * 'file' is included alongside the four standard extension types
     * because some third-party packages register themselves under it
     * purely to let Joomla track their installed file set (no dedicated
     * component/module/plugin/template of their own) - e.g. Balbooa
     * Joomla Gallery, whose `#__extensions` row has element 'bagallery'
     * and type 'file', while its actual front-end component was
     * separately renamed to 'com_gallery'. Such a 'file' row's element
     * still names the real /images/<x> folder the package writes to, so
     * excluding type 'file' here left that folder permanently
     * unrecognised even though the exact element existed in
     * `#__extensions` all along.
     *
     * Keys are lowercased folder-name candidates (element, and for
     * components also the "com_"-stripped bare name - see
     * getEnabledComponentBareNames()); values are the extension's
     * display title as stored in `#__extensions.name`.
     *
     * @return  array
     */
    protected function getAllInstalledExtensionNamesByImagesFolder()
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['element', 'name', 'type', 'client_id', 'folder']))
            ->from($db->quoteName('#__extensions'))
            ->where(
                $db->quoteName('type') . ' IN ('
                . implode(',', array_map([$db, 'quote'], ['component', 'module', 'plugin', 'template', 'file']))
                . ')'
            );

        $db->setQuery($query);

        try {
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return [];
        }

        $map = [];

        foreach ($rows as $row) {
            // Administrator-only modules/templates don't have their own
            // /images/<x> folder on the site - only client_id = 0 (site)
            // ones do, or components, which aren't split by client_id.
            if (in_array($row['type'], ['module', 'template'], true) && (int) $row['client_id'] !== 0) {
                continue;
            }

            $element = strtolower($row['element']);

            if ($element === '' || $row['name'] === '') {
                continue;
            }

            $title = $this->resolveExtensionDisplayName($row['type'], $element, (string) ($row['folder'] ?? ''), $row['name']);

            if ($title === '') {
                continue;
            }

            $map[$this->normaliseExtensionFolderKey($element)] = $title;

            if ($row['type'] === 'component' && strpos($element, 'com_') === 0) {
                $map[$this->normaliseExtensionFolderKey(substr($element, 4))] = $title;
            }
        }

        return $map;
    }

    /**
     * `#__extensions.name` is very often not a display-ready string, but
     * a language KEY that still needs translating - e.g. a plugin's
     * manifest typically declares `<name>PLG_CONSOLE_JCHOPTIMIZE</name>`,
     * which Joomla's own Extension Manager list only ever shows
     * correctly because it runs every row through Text::_() after making
     * sure that extension's own language file is loaded first (untranslated
     * strings are otherwise returned byte-for-byte unchanged by Text::_() -
     * exactly the raw "plg_console_jchoptimize" Wouter saw in the hint
     * before this fix). Loading is best-effort and silently ignored if
     * nothing is found (e.g. the extension has no language file at all,
     * or `name` genuinely was already a literal display string to begin
     * with) - Text::_() harmlessly returns the input unchanged either way.
     *
     * @param   string  $type      'component', 'module', 'plugin' or 'template'.
     * @param   string  $element   Lowercased element, e.g. 'com_something', 'mod_something'.
     * @param   string  $folder    Plugin group folder (e.g. 'console') - only meaningful for plugins.
     * @param   string  $rawName   The raw `#__extensions.name` value.
     *
     * @return  string
     */
    protected function resolveExtensionDisplayName($type, $element, $folder, $rawName)
    {
        $languageExtension = null;

        switch ($type) {
            case 'component':
            case 'module':
                // Already in the right shape: 'com_xxx' / 'mod_xxx'.
                $languageExtension = $element;
                break;
            case 'plugin':
                $languageExtension = 'plg_' . strtolower($folder) . '_' . $element;
                break;
            case 'template':
                $languageExtension = strpos($element, 'tpl_') === 0 ? $element : 'tpl_' . $element;
                break;
        }

        if ($languageExtension !== null) {
            $language = \Joomla\CMS\Factory::getLanguage();
            $language->load($languageExtension, JPATH_ADMINISTRATOR)
                || $language->load($languageExtension, JPATH_SITE);
        }

        $title = Text::_($rawName);

        // Plugin display names conventionally lead with their plugin
        // group, e.g. "Console - JCH Optimize", "System - Cache" -
        // accurate (it's genuinely the extension's official Joomla
        // title), but not useful for this hint's purpose, which is just
        // naming *which extension* a file probably belongs to. Strips
        // generically for every plugin that follows this "<Group> - "
        // shape, not any specific one.
        if ($type === 'plugin') {
            $dashPos = strpos($title, ' - ');

            if ($dashPos !== false) {
                $title = substr($title, $dashPos + 3);
            }
        }

        return $title;
    }

    /**
     * Whether a scanned folder path sits directly under /images/<x>,
     * where <x> matches ANY currently *installed* component, module,
     * plugin or template - active or not (see
     * getAllInstalledExtensionNamesByImagesFolder()). Broader than
     * pathBelongsToActiveExtensionImagesFolder(), which only matches
     * enabled components and is used for the separate "hide from
     * Unlinked" option; this one is used purely for the informational
     * hint shown next to a file's location (see
     * markActiveExtensionAssetStatus()).
     *
     * @param   string  $relDir              Folder path relative to
     *                                       JPATH_ROOT (leading slash,
     *                                       no trailing slash, '' for
     *                                       root).
     * @param   array   $installedByFolder   Result of
     *                                       getAllInstalledExtensionNamesByImagesFolder()
     *                                       - keyed by normalised name,
     *                                       see normaliseExtensionFolderKey().
     *
     * @return  string|null  The matched extension's title, or null.
     */
    protected function pathBelongsToKnownExtensionImagesFolder($relDir, array $installedByFolder)
    {
        $segments = explode('/', trim($relDir, '/'));

        if (count($segments) < 2 || strtolower($segments[0]) !== 'images') {
            return null;
        }

        return $this->matchFolderSegmentToExtension($segments[1], $installedByFolder);
    }

    /**
     * Matches one on-disk folder-name segment against an
     * extension-name map, generically - not just an exact name match
     * (see normaliseExtensionFolderKey()), but also a folder that
     * *starts* with an extension's name plus its own descriptive
     * suffix, e.g. JCH Optimize (element 'com_jchoptimize') writing its
     * backups to /images/jch_optimize_backup_images/. An exact match is
     * tried first; failing that, $segment is split on '-'/'_' into
     * tokens and progressively longer prefixes (starting from the first
     * token) are tested, so the match only ever happens at a genuine
     * word boundary in the *original* folder name - never a mid-word
     * coincidence. This is what keeps e.g. a 'newsletter' folder from
     * matching some unrelated 'news' extension: there is no separator
     * between "news" and "letter" for the prefix search to stop on, so
     * no prefix of 'newsletter' other than the full (non-matching) word
     * is ever tried.
     *
     * Originally built for the /images/<x> hint only (hence the name of
     * $installedByFolder's usual source,
     * getAllInstalledExtensionNamesByImagesFolder()); reused as-is for
     * the same class of mismatch under /components/, /modules/,
     * /plugins/<group>/ and /templates/ - see
     * pathBelongsToActiveExtension() and markActiveExtensionAssetStatus().
     * The map's values can be a display title (for naming a hint) or
     * simply `true` (for a plain yes/no membership check) - this method
     * only cares whether a key was found, and returns whatever value is
     * stored there.
     *
     * @param   string  $segment             The folder-name segment, as
     *                                       found on disk (original
     *                                       casing/separators - not yet
     *                                       normalised).
     * @param   array   $installedByFolder   Normalised-key => value map,
     *                                       see normaliseExtensionFolderKey().
     *
     * @return  mixed  The matched map value, or null.
     */
    protected function matchFolderSegmentToExtension($segment, array $installedByFolder)
    {
        $fullKey = $this->normaliseExtensionFolderKey($segment);

        if (isset($installedByFolder[$fullKey])) {
            return $installedByFolder[$fullKey];
        }

        $tokens = preg_split('/[-_]+/', $segment, -1, PREG_SPLIT_NO_EMPTY);

        if (count($tokens) < 2) {
            return null;
        }

        // Never test the very last token as a would-be complete prefix -
        // that's the fullKey case already handled above.
        array_pop($tokens);

        $prefix = '';

        foreach ($tokens as $token) {
            $prefix .= $token;
            $key     = $this->normaliseExtensionFolderKey($prefix);

            if (isset($installedByFolder[$key])) {
                return $installedByFolder[$key];
            }
        }

        return null;
    }

    /**
     * The /images/<x> folder-name-to-title pairs this component has
     * previously confirmed (on some earlier scan) belonged to an
     * installed extension, read from
     * `#__mediacleaner_known_images_extensions`. This is what lets a
     * *fully uninstalled* extension (no longer present in
     * `#__extensions` at all, so
     * getAllInstalledExtensionNamesByImagesFolder() can no longer see it)
     * still be named in the "possibly removed extension" hint, rather
     * than falling back to a bare folder-name guess - which would be
     * unreliable, since an ordinary content folder a site owner created
     * themselves (e.g. /images/banners) could coincidentally share a
     * name with some extension and would otherwise trigger a false
     * positive. A name only ever enters this registry once this
     * component itself has seen it installed (see
     * upsertKnownImagesExtensionFolders()), so it can never flag a
     * folder that was never genuinely an extension's.
     *
     * @return  array  Lowercased folder name => display title.
     */
    protected function getKnownImagesExtensionFolders()
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['folder_name', 'extension_name']))
            ->from($db->quoteName('#__mediacleaner_known_images_extensions'));

        $db->setQuery($query);

        try {
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return [];
        }

        $map = [];

        foreach ($rows as $row) {
            $map[$row['folder_name']] = $row['extension_name'];
        }

        return $map;
    }

    /**
     * Persists every /images/<x> folder confirmed this scan to belong to
     * a currently installed extension (see
     * getAllInstalledExtensionNamesByImagesFolder()) into
     * `#__mediacleaner_known_images_extensions`, so the name/title
     * survives even after that extension is later uninstalled (see
     * getKnownImagesExtensionFolders()). One row per distinct folder
     * name per scan - upserted, not appended, so the stored title always
     * reflects the most recently seen one and last_seen_installed
     * tracks recency.
     *
     * @param   array  $foldersSeenThisScan  Lowercased folder name =>
     *                                       display title, deduplicated.
     *
     * @return  void
     */
    protected function upsertKnownImagesExtensionFolders(array $foldersSeenThisScan)
    {
        if (empty($foldersSeenThisScan)) {
            return;
        }

        $db  = $this->db;
        $now = \Joomla\CMS\Factory::getDate()->toSql();

        foreach ($foldersSeenThisScan as $folderName => $title) {
            $sql = 'INSERT INTO ' . $db->quoteName('#__mediacleaner_known_images_extensions')
                . ' (' . $db->quoteName('folder_name') . ', ' . $db->quoteName('extension_name') . ', ' . $db->quoteName('last_seen_installed') . ')'
                . ' VALUES (' . $db->quote($folderName) . ', ' . $db->quote($title) . ', ' . $db->quote($now) . ')'
                . ' ON DUPLICATE KEY UPDATE '
                . $db->quoteName('extension_name') . ' = VALUES(' . $db->quoteName('extension_name') . '), '
                . $db->quoteName('last_seen_installed') . ' = VALUES(' . $db->quoteName('last_seen_installed') . ')';

            try {
                $db->setQuery($sql)->execute();
            } catch (\Exception $e) {
                // Best-effort - a failed upsert only means a later
                // "removed extension" hint might be missed for this one
                // folder, never a scan failure.
            }
        }
    }

    /**
     * The /components/<x>, /modules/<x>, /templates/<x> or
     * /plugins/<group>/<element> folder-key-to-title pairs this
     * component has previously confirmed (on some earlier scan) belonged
     * to an installed extension, read from
     * `#__mediacleaner_known_asset_extensions` - the asset-folder
     * counterpart of getKnownImagesExtensionFolders(), needed so a
     * *fully uninstalled* extension can still be named in the "possibly
     * removed extension" hint here too. Kept in a separate table (rather
     * than reusing `#__mediacleaner_known_images_extensions`) because a
     * component, module, plugin and template can plausibly share the
     * same folder/element name (e.g. an extension that ships
     * com_example, mod_example and a matching plugin) - $folderType
     * keeps those apart.
     *
     * @param   string  $folderType  One of 'components', 'modules',
     *                               'templates', 'plugins'.
     *
     * @return  array  Lowercased folder key (element, or
     *                 "group/element" for plugins) => display title.
     */
    protected function getKnownAssetExtensionFolders($folderType)
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['folder_key', 'extension_name']))
            ->from($db->quoteName('#__mediacleaner_known_asset_extensions'))
            ->where($db->quoteName('folder_type') . ' = ' . $db->quote($folderType));

        $db->setQuery($query);

        try {
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return [];
        }

        $map = [];

        foreach ($rows as $row) {
            $map[$row['folder_key']] = $row['extension_name'];
        }

        return $map;
    }

    /**
     * Persists every asset folder (/components/<x>, /modules/<x>,
     * /templates/<x>, /plugins/<group>/<element>) confirmed this scan to
     * belong to a currently installed extension into
     * `#__mediacleaner_known_asset_extensions`, so the name/title
     * survives even after that extension is later uninstalled (see
     * getKnownAssetExtensionFolders()) - the asset-folder counterpart of
     * upsertKnownImagesExtensionFolders().
     *
     * @param   string  $folderType           One of 'components',
     *                                        'modules', 'templates',
     *                                        'plugins'.
     * @param   array   $foldersSeenThisScan  Lowercased folder key =>
     *                                        display title,
     *                                        deduplicated.
     *
     * @return  void
     */
    protected function upsertKnownAssetExtensionFolders($folderType, array $foldersSeenThisScan)
    {
        if (empty($foldersSeenThisScan)) {
            return;
        }

        $db  = $this->db;
        $now = \Joomla\CMS\Factory::getDate()->toSql();

        foreach ($foldersSeenThisScan as $folderKey => $title) {
            $sql = 'INSERT INTO ' . $db->quoteName('#__mediacleaner_known_asset_extensions')
                . ' (' . $db->quoteName('folder_type') . ', ' . $db->quoteName('folder_key') . ', ' . $db->quoteName('extension_name') . ', ' . $db->quoteName('last_seen_installed') . ')'
                . ' VALUES (' . $db->quote($folderType) . ', ' . $db->quote($folderKey) . ', ' . $db->quote($title) . ', ' . $db->quote($now) . ')'
                . ' ON DUPLICATE KEY UPDATE '
                . $db->quoteName('extension_name') . ' = VALUES(' . $db->quoteName('extension_name') . '), '
                . $db->quoteName('last_seen_installed') . ' = VALUES(' . $db->quoteName('last_seen_installed') . ')';

            try {
                $db->setQuery($sql)->execute();
            } catch (\Exception $e) {
                // Best-effort - a failed upsert only means a later
                // "removed extension" hint might be missed for this one
                // folder, never a scan failure.
            }
        }
    }

    /**
     * Whether a scanned folder path has the *shape* of an extension's own
     * asset folder - i.e. sits under /components/<x>, /modules/<x>,
     * /templates/<x> or /plugins/<group>/<element> - regardless of
     * whether that particular extension is currently active. Unlike
     * /images/<x> (see pathBelongsToActiveExtensionImagesFolder()),
     * nothing legitimately lives directly under these four folders other
     * than an extension's own files, so the shape alone is a reliable
     * signal - deliberately not extended to /images/<x>, where the same
     * shape is just as often an ordinary content folder a site owner
     * created themselves (e.g. /images/banners, /images/stories) and
     * would make the hint below unreliable.
     *
     * @param   string  $relDir  Folder path relative to JPATH_ROOT, as
     *                           produced by scanFilesystem() (leading
     *                           slash, no trailing slash, '' for root).
     *
     * @return  boolean
     */
    protected function pathHasExtensionFolderShape($relDir)
    {
        $segments = explode('/', trim($relDir, '/'));

        if (count($segments) < 2) {
            return false;
        }

        $top = strtolower($segments[0]);

        if (in_array($top, ['components', 'modules', 'templates'], true)) {
            return true;
        }

        return $top === 'plugins' && count($segments) >= 3;
    }

    /**
     * Builds a normalised-key => true membership map from a flat list of
     * element/bare-name strings (see normaliseExtensionFolderKey()) -
     * the shape pathBelongsToActiveExtension() needs for its loosened
     * matching. Empty candidates are skipped.
     *
     * @param   array  $names
     *
     * @return  array
     */
    protected function buildNormalisedMembershipMap(array $names)
    {
        $map = [];

        foreach ($names as $name) {
            $name = (string) $name;

            if ($name === '') {
                continue;
            }

            $map[$this->normaliseExtensionFolderKey($name)] = true;
        }

        return $map;
    }

    /**
     * Determine, for every scanned file:
     * - whether it sits inside the asset folder of a component, module,
     *   plugin or template that is currently installed and enabled (see
     *   pathBelongsToActiveExtension());
     * - whether it sits inside an /images/<x> folder belonging to a
     *   currently enabled component (see
     *   pathBelongsToActiveExtensionImagesFolder());
     * - whether it sits in a folder shaped like *some* extension's own
     *   asset folder (components/modules/plugins/templates) without
     *   matching any currently *active* one - a strong hint that it's
     *   left over from an extension that has since been disabled or
     *   uninstalled (see pathHasExtensionFolderShape());
     * - for /images/<x> files specifically: whether <x> names an
     *   installed extension (active or not) or, failing that, an
     *   extension this component has previously seen installed under
     *   that same folder name - either way naming the extension in the
     *   hint (see pathBelongsToKnownExtensionImagesFolder(),
     *   getKnownImagesExtensionFolders());
     * - the same "does <x> name an installed extension" naming, applied
     *   to /components/<x>, /modules/<x>, /templates/<x> and
     *   /plugins/<group>/<element> too (see
     *   getAllInstalledExtensionNamesByImagesFolder(),
     *   getAllInstalledPluginElementsByGroup(),
     *   getKnownAssetExtensionFolders()) - added because the same
     *   folder-name-doesn't-exactly-match-the-element mismatch class
     *   originally fixed for /images/ turned out to affect these four
     *   folders too (found in practice under /templates/).
     *
     * Reads every enabled/installed-extension list once and reuses it
     * for every item, rather than querying `#__extensions` per file.
     *
     * @param   array  $items
     *
     * @return  array
     */
    protected function markActiveExtensionAssetStatus(array $items)
    {
        $enabledComponents         = $this->getEnabledComponentElements();
        $enabledComponentBareNames = $this->getEnabledComponentBareNames();
        $enabledFileElements       = $this->getEnabledFileTypeElements();
        $enabledTemplates          = $this->getEnabledTemplateElements();
        $enabledModules            = $this->getEnabledModuleElements();
        $enabledPlugins            = $this->getEnabledPluginFolderElementPairs();

        // Normalised membership maps for the loosened is_active_extension_asset
        // check (see pathBelongsToActiveExtension()) - built once per
        // scan, reused for every file below.
        $normalisedEnabledComponents = $this->buildNormalisedMembershipMap(
            array_merge($enabledComponents, $enabledComponentBareNames, $enabledFileElements)
        );
        $normalisedEnabledTemplates = $this->buildNormalisedMembershipMap(
            array_merge($enabledTemplates, $enabledFileElements)
        );
        $normalisedEnabledModules = $this->buildNormalisedMembershipMap(
            array_merge($enabledModules, $enabledFileElements)
        );
        $normalisedEnabledPluginsByGroup = [];

        foreach ($enabledPlugins as $pair) {
            [$group, $element] = explode('/', $pair, 2);
            $normalisedEnabledPluginsByGroup[$group][$this->normaliseExtensionFolderKey($element)] = true;
        }

        $installedImagesExtensions = $this->getAllInstalledExtensionNamesByImagesFolder();
        $knownImagesExtensions     = $this->getKnownImagesExtensionFolders();
        $imagesFoldersSeenThisScan = [];

        $installedPluginsByGroup = $this->getAllInstalledPluginElementsByGroup();

        // The "installed by folder" map is naturally reused across
        // /components/, /modules/ and /templates/ (see
        // getAllInstalledExtensionNamesByImagesFolder() - it's not
        // actually images-specific, it already spans every extension
        // type). Plugins need their own per-group version because of
        // the extra path segment - see
        // getAllInstalledPluginElementsByGroup().
        $knownAssetExtensions = [
            'components' => $this->getKnownAssetExtensionFolders('components'),
            'modules'    => $this->getKnownAssetExtensionFolders('modules'),
            'templates'  => $this->getKnownAssetExtensionFolders('templates'),
            'plugins'    => $this->getKnownAssetExtensionFolders('plugins'),
        ];
        $assetFoldersSeenThisScan = [
            'components' => [],
            'modules'    => [],
            'templates'  => [],
            'plugins'    => [],
        ];

        foreach ($items as &$item) {
            $isActiveExtensionAsset = $this->pathBelongsToActiveExtension(
                $item['path'],
                $normalisedEnabledComponents,
                $normalisedEnabledTemplates,
                $normalisedEnabledModules,
                $normalisedEnabledPluginsByGroup
            );

            $item['isActiveExtensionAsset']     = $isActiveExtensionAsset;
            $item['isActiveExtensionImagesDir'] = $this->pathBelongsToActiveExtensionImagesFolder(
                $item['path'],
                $enabledComponents,
                $enabledComponentBareNames,
                $enabledFileElements
            );
            $item['possiblyOrphanedExtension'] = !$isActiveExtensionAsset
                && $this->pathHasExtensionFolderShape($item['path']);

            // Informational hint for /images/<x> files: is <x> a
            // currently *installed* extension (active or not - see
            // getAllInstalledExtensionNamesByImagesFolder())? If so,
            // remember it so it's persisted into
            // #__mediacleaner_known_images_extensions below. If not
            // currently installed, fall back to what this component has
            // previously confirmed for that exact folder name (see
            // getKnownImagesExtensionFolders()) - never a bare
            // pattern/shape guess, to avoid flagging an ordinary content
            // folder like /images/banners.
            $item['imagesDirExtensionName']    = null;
            $item['imagesDirExtensionRemoved'] = false;

            $installedTitle = $this->pathBelongsToKnownExtensionImagesFolder($item['path'], $installedImagesExtensions);

            if ($installedTitle !== null) {
                $item['imagesDirExtensionName'] = $installedTitle;

                $segments = explode('/', trim($item['path'], '/'));
                if (isset($segments[1])) {
                    $imagesFoldersSeenThisScan[strtolower($segments[1])] = $installedTitle;
                }
            } else {
                $segments = explode('/', trim($item['path'], '/'));

                if (count($segments) >= 2 && strtolower($segments[0]) === 'images') {
                    $knownTitle = $knownImagesExtensions[strtolower($segments[1])] ?? null;

                    if ($knownTitle !== null) {
                        $item['imagesDirExtensionName']    = $knownTitle;
                        $item['imagesDirExtensionRemoved'] = true;
                    }
                }
            }

            // Same naming for /components/<x>, /modules/<x>,
            // /templates/<x> and /plugins/<group>/<element> - see the
            // class docblock note above. Computed regardless of
            // $isActiveExtensionAsset, same as the images-dir hint
            // above (an active extension's files can still legitimately
            // reach this list if the "hide active extension assets"
            // option is switched off, and the hint remains correct
            // either way).
            $item['assetDirExtensionName']    = null;
            $item['assetDirExtensionRemoved'] = false;

            $segments = explode('/', trim($item['path'], '/'));
            $top      = count($segments) >= 1 ? strtolower($segments[0]) : '';

            if (in_array($top, ['components', 'modules', 'templates'], true) && isset($segments[1])) {
                $folderType = $top;
                $folderKey  = strtolower($segments[1]);

                $title = $this->matchFolderSegmentToExtension($segments[1], $installedImagesExtensions);

                if ($title !== null) {
                    $item['assetDirExtensionName']              = $title;
                    $assetFoldersSeenThisScan[$folderType][$folderKey] = $title;
                } else {
                    $knownTitle = $knownAssetExtensions[$folderType][$folderKey] ?? null;

                    if ($knownTitle !== null) {
                        $item['assetDirExtensionName']    = $knownTitle;
                        $item['assetDirExtensionRemoved'] = true;
                    }
                }
            } elseif ($top === 'plugins' && isset($segments[1], $segments[2])) {
                $group      = strtolower($segments[1]);
                $folderKey  = $group . '/' . strtolower($segments[2]);
                $elementMap = $installedPluginsByGroup[$group] ?? [];

                $title = $this->matchFolderSegmentToExtension($segments[2], $elementMap);

                if ($title !== null) {
                    $item['assetDirExtensionName']            = $title;
                    $assetFoldersSeenThisScan['plugins'][$folderKey] = $title;
                } else {
                    $knownTitle = $knownAssetExtensions['plugins'][$folderKey] ?? null;

                    if ($knownTitle !== null) {
                        $item['assetDirExtensionName']    = $knownTitle;
                        $item['assetDirExtensionRemoved'] = true;
                    }
                }
            } elseif ($top === 'administrator' && isset($segments[1], $segments[2]) && strtolower($segments[1]) === 'components') {
                // v2.7.17: /administrator/components/<x>/... - same shape
                // as /components/<x>/, one level deeper. Same
                // registry/bookkeeping bucket ('components') as that
                // branch, since it's the same extension type.
                $folderType = 'components';
                $folderKey  = strtolower($segments[2]);

                $title = $this->matchFolderSegmentToExtension($segments[2], $installedImagesExtensions);

                if ($title !== null) {
                    $item['assetDirExtensionName']              = $title;
                    $assetFoldersSeenThisScan[$folderType][$folderKey] = $title;
                } else {
                    $knownTitle = $knownAssetExtensions[$folderType][$folderKey] ?? null;

                    if ($knownTitle !== null) {
                        $item['assetDirExtensionName']    = $knownTitle;
                        $item['assetDirExtensionRemoved'] = true;
                    }
                }
            } elseif ($top === 'media' && isset($segments[1])) {
                // v2.7.17: /media/<x>/... - same rationale as the
                // isActiveExtensionAsset fix in pathBelongsToActiveExtension()
                // (v2.7.16): this is the Joomla-recommended location for a
                // component/module/plugin/template's own public assets on
                // any reasonably modern install, and was simply never
                // recognised here before, so the "hoort mogelijk bij
                // extensie" hint (and the v2.7.13 "images"-in-extension
                // override, which gates on assetDirExtensionName) silently
                // never applied to anything living there.
                $mediaSegment = strtolower($segments[1]);

                if ($mediaSegment === 'templates' && isset($segments[2], $segments[3])) {
                    // /media/templates/site|administrator/<tpl>/...
                    $folderType = 'templates';
                    $folderKey  = strtolower($segments[3]);

                    $title = $this->matchFolderSegmentToExtension($segments[3], $installedImagesExtensions);

                    if ($title !== null) {
                        $item['assetDirExtensionName']              = $title;
                        $assetFoldersSeenThisScan[$folderType][$folderKey] = $title;
                    } else {
                        $knownTitle = $knownAssetExtensions[$folderType][$folderKey] ?? null;

                        if ($knownTitle !== null) {
                            $item['assetDirExtensionName']    = $knownTitle;
                            $item['assetDirExtensionRemoved'] = true;
                        }
                    }
                } elseif (strpos($mediaSegment, 'plg_') === 0) {
                    // /media/plg_<group>_<element>/... - group and
                    // element are concatenated into one folder name with
                    // no reliable string-only split point, so every
                    // *installed* plugin group is tried as a candidate
                    // prefix (mirrors pathBelongsToActiveExtension()'s
                    // v2.7.16 fix, using installed rather than
                    // enabled-only groups here since this hint - unlike
                    // isActiveExtensionAsset - is meant to apply
                    // regardless of active status).
                    $afterPrefix = substr($mediaSegment, 4);

                    foreach ($installedPluginsByGroup as $group => $elementMap) {
                        $groupPrefix = strtolower($group) . '_';

                        if (strpos($afterPrefix, $groupPrefix) !== 0) {
                            continue;
                        }

                        $elementGuess = substr($afterPrefix, strlen($groupPrefix));
                        $folderKey    = $group . '/' . $elementGuess;

                        $title = $this->matchFolderSegmentToExtension($elementGuess, $elementMap);

                        if ($title !== null) {
                            $item['assetDirExtensionName']                   = $title;
                            $assetFoldersSeenThisScan['plugins'][$folderKey] = $title;
                            break;
                        }

                        $knownTitle = $knownAssetExtensions['plugins'][$folderKey] ?? null;

                        if ($knownTitle !== null) {
                            $item['assetDirExtensionName']    = $knownTitle;
                            $item['assetDirExtensionRemoved'] = true;
                            break;
                        }
                    }
                } else {
                    // /media/<x>/... for a component or module - the
                    // installed-extension-by-folder map spans every
                    // extension type already (see the comment on
                    // $installedImagesExtensions' own construction
                    // above), so the match itself doesn't need to know
                    // which of the two it is. What it CAN'T tell us is
                    // which of the 'components'/'modules' known-folder
                    // registry buckets to remember it under if the
                    // extension is later uninstalled - so, deliberately,
                    // this sets the immediate hint (what actually drives
                    // the displayed text and the "images"-in-extension
                    // override) but skips the seen-this-scan/known-folder
                    // bookkeeping for this specific ambiguous shape. Net
                    // effect: the hint still disappears (correctly) if
                    // this exact extension is later removed, rather than
                    // being remembered forever under a possibly-wrong
                    // bucket.
                    $title = $this->matchFolderSegmentToExtension($segments[1], $installedImagesExtensions);

                    if ($title !== null) {
                        $item['assetDirExtensionName'] = $title;
                    }
                }
            }
        }

        unset($item);

        $this->upsertKnownImagesExtensionFolders($imagesFoldersSeenThisScan);

        foreach ($assetFoldersSeenThisScan as $folderType => $foldersSeenThisScan) {
            $this->upsertKnownAssetExtensionFolders($folderType, $foldersSeenThisScan);
        }

        return $items;
    }

    /**
     * Files sitting in specific folder names inside a recognised
     * extension's own components/modules/plugins/templates folder (i.e.
     * `assetDirExtensionName` is already resolved for it - see
     * markActiveExtensionAssetStatus(), which must run before this) are
     * always treated as system files, unconditionally - no
     * date-clustering outlier check (applyManualUploadOutlierDetection())
     * needed or applied, since Wouter was explicit these should always
     * count as such once the folder-name-plus-scope condition matches.
     *
     * This is deliberately NOT the same as adding these names to
     * pathHasSystemAssetSegment()'s generic list - a bare folder-name
     * match on something like "fonts" would also catch an ordinary,
     * unrelated content folder a site owner named that themselves, and
     * "images" would also match the site's own top-level `/images/...`
     * media library, where genuine uploaded content and an extension's
     * `/images/<x>/` content folder (see `imagesDirExtensionName`) both
     * live. Gating on the folder actually being inside a recognised
     * extension's own code tree removes that risk entirely, and
     * $requireActive (v2.7.14) tightens it further where Wouter asked
     * for it: "canvas"/"fonts" (typically comprofiler's own template
     * canvas-frame images, or a bundled webfont folder) are common
     * enough, ordinary-sounding names on their own that he wanted them
     * gated on the extension being *currently active*
     * (`isActiveExtensionAsset`, which is - unlike `assetDirExtensionName`
     * - specifically an active/inactive check), not just "recognised as
     * some extension's folder shape, active or not". "images" (v2.7.13)
     * stays on the looser `assetDirExtensionName`-only gate, since
     * Wouter asked for that one unconditionally.
     *
     * @param   array    $items
     * @param   array    $segmentNames    Lowercased folder-name segments to match.
     * @param   boolean  $requireActive   true: gate on `isActiveExtensionAsset` (v2.7.14, "canvas"/"fonts").
     *                                    false: gate on `assetDirExtensionName` being set at all,
     *                                    active or not (v2.7.13, "images").
     *
     * @return  array  Same items, `isSystemAssetDir` set to true where it matches.
     */
    protected function applyExtensionFolderSegmentOverride(array $items, array $segmentNames, $requireActive)
    {
        foreach ($items as &$item) {
            $gate = $requireActive ? !empty($item['isActiveExtensionAsset']) : !empty($item['assetDirExtensionName']);

            if (!$gate) {
                continue;
            }

            $segments = explode('/', trim($item['path'], '/'));

            foreach ($segments as $segment) {
                if (in_array(strtolower($segment), $segmentNames, true)) {
                    $item['isSystemAssetDir'] = true;
                    break;
                }
            }
        }

        unset($item);

        return $items;
    }

    /**
     * Files whose own filename contains "icon" or "logo" (case-
     * insensitive substring, e.g. "icon-close.svg", "favicon.png",
     * "app-icons.png", "logo-dark.svg") AND belong to a currently active
     * component/module/plugin/template (`isActiveExtensionAsset`) are
     * always treated as system files - v2.7.15 ("icon"), extended with
     * "logo" in v2.7.16 on Wouter's request. Unlike
     * applyExtensionFolderSegmentOverride(), this checks the filename
     * itself rather than a folder segment, since an icon or logo shipped
     * with an extension can just as easily sit loose in the extension's
     * own root asset folder as inside a dedicated subfolder.
     *
     * Same reasoning as "canvas"/"fonts" (v2.7.14) for requiring the
     * extension to be *active*, not merely recognised: "icon"/"logo" are
     * even more common substrings to turn up in an ordinary, unrelated
     * filename (a content editor's own "site-icon-final.png" or
     * "client-logo.png" upload, for instance) than "fonts" is as a
     * folder name, so this is deliberately scoped no wider than Wouter's
     * own wording - component/module/plugin/template, i.e.
     * `isActiveExtensionAsset` only, not the `/images/<x>/` media-library
     * convention (`isActiveExtensionImagesDir`) - worth flagging in case
     * broader coverage was actually wanted there too.
     *
     * Same as every other override in this group: no date-clustering
     * outlier check, unconditionally "always system" once matched.
     *
     * @param   array  $items
     *
     * @return  array  Same items, `isSystemAssetDir` set to true where it matches.
     */
    protected function applyActiveExtensionFilenameOverride(array $items)
    {
        static $needles = ['icon', 'logo'];

        foreach ($items as &$item) {
            if (empty($item['isActiveExtensionAsset'])) {
                continue;
            }

            foreach ($needles as $needle) {
                if (stripos($item['name'], $needle) !== false) {
                    $item['isSystemAssetDir'] = true;
                    break;
                }
            }
        }

        unset($item);

        return $items;
    }

    /**
     * TEMPORARY DIAGNOSTIC AID (v1.10.2) - not a fix by itself.
     *
     * A genuine PHP fatal error (memory exhausted, max execution time
     * reached) cannot be caught with try/catch - it terminates the script
     * immediately, which is exactly why our own byte/time budgets exist:
     * to stop well before either limit is hit. But if a rescan still
     * fails with a 500 despite those budgets, we have no way to know
     * *why* without seeing PHP's own fatal error text - and that text
     * normally only goes to a server error log the developer may not
     * have access to (as is the case here).
     *
     * A "shutdown function" is the one thing PHP still runs after a fatal
     * error, even though normal code (including our own try/catch) has
     * already stopped executing. This registers one that writes whatever
     * fatal error just happened to a plain file next to this component's
     * own log folder - readable over plain FTP, no server error log
     * access required.
     *
     * Safe to leave in place even once no longer needed: it does nothing
     * unless a fatal error actually occurs, and never touches anything
     * other than this one log file.
     *
     * @return  void
     */
    protected function registerCrashLogger()
    {
        $logFile = JPATH_ADMINISTRATOR . '/logs/mediacleaner_crash.log';

        register_shutdown_function(static function () use ($logFile) {
            $error = error_get_last();

            if (
                $error === null
                || !\in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
            ) {
                return;
            }

            $line = sprintf(
                "[%s] %s in %s:%d\n",
                date('Y-m-d H:i:s'),
                $error['message'],
                $error['file'],
                $error['line']
            );

            // Deliberately not using Joomla's own Log class here: after a
            // fatal error the framework's state can no longer be trusted
            // to still work correctly, so this writes directly and as
            // simply as possible instead.
            @file_put_contents($logFile, $line, FILE_APPEND);
        });
    }

    /**
     * Restore each file's persisted "ignored" choice (set by the user via
     * the "Negeren" action) after a fresh scan, matched by relative path
     * since the database ids are regenerated on every rescan.
     *
     * @param   array  $items  Items as returned by scanFilesystem()/markLinkedStatus().
     *
     * @return  array  Same items, each with an added 'ignored' boolean.
     */
    protected function markIgnoredStatus(array $items)
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('relative_path'))
            ->from($db->quoteName('#__mediacleaner_ignored'))
            // v2.7.3: a suppressed row is a permanent "user chose to
            // keep this visible" marker for a file this component once
            // auto-ignored (see applyAutoIgnoreThumbs()) - it must NOT
            // count as ignored, even though the row itself stays in the
            // table so that pass knows not to re-ignore the same file.
            ->where($db->quoteName('suppressed') . ' = 0');

        $db->setQuery($query);

        try {
            $ignoredPaths = $db->loadColumn();
        } catch (\Exception $e) {
            $ignoredPaths = [];
        }

        $ignoredSet = array_flip($ignoredPaths);

        foreach ($items as &$item) {
            $relative        = trim($item['path'], '/') . '/' . $item['name'];
            $item['ignored'] = isset($ignoredSet[$relative]);
        }

        unset($item);

        return $items;
    }

    /**
     * Whether newly-found unlinked files sitting in a 'thumbs' cache
     * folder (see pathHasThumbsSegment()) should be auto-ignored on
     * scan, moving them straight into "Genegeerde media" instead of
     * leaving them in "Niet-gekoppeld" with just an explanatory note.
     * Reuses the existing "'thumbs'-mappen in de map 'images'" Opties
     * toggle (default on) - same underlying idea (these are regenerated
     * cache, not content), now acted on instead of just annotated.
     *
     * @return  boolean
     */
    protected function autoIgnoreThumbsEnabled()
    {
        return (int) ComponentHelper::getParams('com_mediacleaner')->get('hide_thumbs_from_unlinked', 0) === 1;
    }

    /**
     * Auto-ignore every unlinked, not-yet-ignored 'thumbs'-folder file
     * this pass has never seen before, by inserting a
     * `#__mediacleaner_ignored` row for it with source='auto_thumbs' -
     * reusing the exact same persistence mechanism (by relative path,
     * survives rescans) as a manual "Negeren" action.
     *
     * Deliberately only acts on a relative path with NO existing row at
     * all (manual, auto_thumbs, or suppressed) - a suppressed row means
     * the user already un-ignored this exact file once and chose to
     * keep it visible, so this must never re-ignore it; any other
     * existing row means its fate was already decided and doesn't need
     * touching again.
     *
     * Must run after markLinkedStatus() (needs 'linked') and
     * markIgnoredStatus() (needs 'ignored', and this pass's own inserts
     * must not double up with what that pass already found).
     *
     * @param   array  $items
     *
     * @return  array  Same items, 'ignored' updated in place for any
     *                 file this pass just auto-ignored.
     */
    protected function applyAutoIgnoreThumbs(array $items)
    {
        if (!$this->autoIgnoreThumbsEnabled()) {
            return $items;
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('relative_path'))
            ->from($db->quoteName('#__mediacleaner_ignored'));

        $db->setQuery($query);

        try {
            $known = array_flip($db->loadColumn());
        } catch (\Exception $e) {
            // Can't tell what's already decided - safer to skip this
            // pass entirely for this scan than risk re-ignoring a file
            // the user deliberately un-ignored.
            return $items;
        }

        $now        = \Joomla\CMS\Factory::getDate()->toSql();
        $toInsert   = [];

        foreach ($items as &$item) {
            if (!$item['isThumbsDir'] || $item['linked'] || $item['ignored']) {
                continue;
            }

            $relative = trim($item['path'], '/') . '/' . $item['name'];

            if (isset($known[$relative])) {
                continue;
            }

            $toInsert[]        = $relative;
            $known[$relative]  = true;
            $item['ignored']   = true;
        }

        unset($item);

        // v2.8.3: 200 rows per INSERT rather than one query per file - a
        // first scan of a large site can auto-ignore tens of thousands of
        // thumbnails in one go.
        foreach (array_chunk($toInsert, 200) as $chunk) {
            try {
                $insertQuery = $db->getQuery(true)
                    ->insert($db->quoteName('#__mediacleaner_ignored'))
                    ->columns($db->quoteName(['relative_path', 'ignored_at', 'source', 'suppressed']));

                foreach ($chunk as $relative) {
                    $insertQuery->values($db->quote($relative) . ',' . $db->quote($now) . ",'auto_thumbs',0");
                }

                $db->setQuery($insertQuery)->execute();
            } catch (\Exception $e) {
                // Best-effort: a failed insert just leaves these files
                // visible under Niet-gekoppeld until the next scan
                // retries them, rather than failing the whole scan.
            }
        }

        return $items;
    }

    /**
     * Folder-name words that mark a folder as holding generated/derived
     * copies of files that live elsewhere (v2.8.0, see
     * applyDerivedFileLinking()). Used three ways on a single path
     * segment: as the whole segment ("thumbs", "cache"), as a suffix of
     * it ("eventgallery_generated", "gallery-thumbs") or as a prefix
     * ("thumbs_gallery").
     *
     * @var array
     */
    protected $derivedFolderWords = [
        'thumb', 'thumbs', 'thumbnail', 'thumbnails', 'tmb',
        'cache', 'cached', 'generated', 'resized', 'resize', 'sized',
        'preview', 'previews', 'crop', 'crops', 'small', 'medium', 'large', 'mini',
    ];

    /**
     * Folder names an extension commonly keeps its *originals* in, next
     * to a derived folder - e.g. JoomGallery's
     * /images/joomgallery/thumbnails/<cat>/x.jpg next to
     * /images/joomgallery/originals/<cat>/x.jpg. A whole-segment derived
     * folder is also tried with each of these in its place.
     *
     * @var array
     */
    protected $derivedOriginalFolderWords = ['originals', 'original', 'orig', 'source', 'src', 'full', 'fullsize', 'uploads'];

    /**
     * Whether derived files (generated thumbnails and resized copies of
     * another scanned file) inherit their original's linked status.
     * Opties toggle, default on.
     *
     * @return  boolean
     */
    protected function linkDerivedFilesEnabled()
    {
        return (int) ComponentHelper::getParams('com_mediacleaner')->get('link_derived_files', 1) === 1;
    }

    /**
     * Give every item the (empty) derived-file fields, so persistence
     * never has to guess whether applyDerivedFileLinking() ran.
     *
     * @param   array  $items
     *
     * @return  array
     */
    protected function withEmptyDerivedInfo(array $items)
    {
        foreach ($items as &$item) {
            $item['derivedFrom']   = null;
            $item['derivedStatus'] = null;
        }

        unset($item);

        return $items;
    }

    /**
     * v2.8.0 - generic, extension-agnostic detection of *derived* files:
     * thumbnails and resized copies an extension generates itself from
     * an original that also lives on disk. Nothing in the database ever
     * refers to such a file directly (the extension computes its name on
     * the fly), so the text-search passes can never link it - which is
     * why e.g. every one of Event Gallery's 1000+ files in
     * /images/eventgallery_generated/ used to show up as "Niet gekoppeld"
     * while the originals in /images/eventgallery/ were linked fine.
     *
     * Instead of knowing each extension's naming scheme, this recognises
     * the two conventions virtually all of them follow, and then only
     * accepts a match when the original it points to *actually exists in
     * this scan* - that last check is what keeps false positives out:
     *
     * 1. The name carries a size/variant marker around the original's
     *    name: "nocrop_512_x.jpg" (Event Gallery), "phoca_thumb_l_x.jpg"
     *    (Phoca Gallery), "x-300x200.jpg" (WordPress-style), "x@2x.png",
     *    "x-800w.jpg", "thumb_x.jpg", "x_small.jpg", and double
     *    extensions like "x.jpg.webp".
     * 2. The folder is a derived folder of the original's folder: a
     *    parallel folder ("eventgallery_generated/<f>" next to
     *    "eventgallery/<f>"), or a cache subfolder ("<f>/thumbs" or
     *    "<f>/cache" under "<f>"), or a sibling of an "originals" folder.
     *
     * A same-folder match (rule 1 only) is restricted to *numeric* size
     * markers ("nocrop_512_", "-300x200", "@2x", "-800w"). A word marker
     * ("-sm", "_thumb") in the same folder is too often a separate,
     * hand-made asset - e.g. a template's "logo-sm.png" next to
     * "logo.png" - so word markers only count inside a derived folder.
     *
     * Outcome per file, stored as `derived_status` (+ `derived_from`):
     * - 'linked_original': original is linked -> this file becomes
     *   linked too, `link_confidence` = 'derived'. It stays linked only
     *   as long as the original does: once the original loses its last
     *   reference, the next scan puts both back under "Niet gekoppeld".
     * - 'unlinked_original': original exists but is unlinked -> file
     *   stays unlinked, with a hint to clean it up together with the
     *   original.
     * - 'missing_original': derived folder + marker, but the original is
     *   gone (typically: photo deleted, generated thumbnails left
     *   behind) -> stays unlinked, hint says it's safe to delete.
     *
     * Status is taken from a snapshot of 'linked' as markLinkedStatus()
     * left it, so the result never depends on the order items happen to
     * be processed in.
     *
     * @param   array  $items  Items after markLinkedStatus().
     *
     * @return  array  Same items with 'derivedFrom'/'derivedStatus' set,
     *                 and 'linked'/'linkConfidence' updated for
     *                 derivatives of a linked original.
     */
    protected function applyDerivedFileLinking(array $items)
    {
        $items = $this->withEmptyDerivedInfo($items);

        $byPath        = [];
        $knownDirs     = [];
        $linkedAtStart = [];

        foreach ($items as $idx => $item) {
            $dir = strtolower(trim($item['path'], '/'));

            $this->addToLookupIndex($byPath, $dir . '/' . strtolower($item['name']), $idx);
            $knownDirs[$dir]     = true;
            $linkedAtStart[$idx] = !empty($item['linked']);
        }

        foreach ($items as $idx => &$item) {
            if ($linkedAtStart[$idx]) {
                // Already linked on its own merits - keep that, it's the
                // stronger signal.
                continue;
            }

            $dir      = strtolower(trim($item['path'], '/'));
            $name     = strtolower($item['name']);
            $ownKey   = $dir . '/' . $name;
            $pDirs    = $this->getDerivedParentDirCandidates($item['path']);
            $inDerivedFolder = !empty($pDirs);

            $sameDirNames = $this->getOriginalNameCandidates($name, false);
            $allNames     = $inDerivedFolder ? $this->getOriginalNameCandidates($name, true) : [];

            // Candidate original locations, strongest convention first.
            $candidates = [];

            foreach ($sameDirNames as $candidateName) {
                if ($candidateName !== $name) {
                    $candidates[] = $dir . '/' . $candidateName;
                }
            }

            foreach ($pDirs as $parentDir) {
                foreach (array_merge([$name], $allNames) as $candidateName) {
                    $candidates[] = $parentDir . '/' . $candidateName;
                }
            }

            $matchIdx = null;

            foreach (array_unique($candidates) as $candidateKey) {
                if ($candidateKey === $ownKey || !isset($byPath[$candidateKey])) {
                    continue;
                }

                foreach ((array) $byPath[$candidateKey] as $originalIdx) {
                    if ($originalIdx === $idx) {
                        continue;
                    }

                    if ($matchIdx === null || ($linkedAtStart[$originalIdx] && !$linkedAtStart[$matchIdx])) {
                        $matchIdx = $originalIdx;
                    }
                }

                if ($matchIdx !== null && $linkedAtStart[$matchIdx]) {
                    break;
                }
            }

            if ($matchIdx !== null) {
                $original            = $items[$matchIdx];
                $item['derivedFrom'] = rtrim($original['path'], '/') . '/' . $original['name'];

                if ($linkedAtStart[$matchIdx]) {
                    $item['linked']         = true;
                    $item['linkConfidence'] = 'derived';
                    $item['derivedStatus']  = 'linked_original';
                } else {
                    $item['derivedStatus'] = 'unlinked_original';
                }

                continue;
            }

            // No original found. Only call it an orphaned derivative when
            // both conventions agree - derived folder whose original
            // folder still exists, AND a stripped size/variant marker -
            // so an ordinary file that merely lives in a folder called
            // "large" or "preview" is never labelled like this.
            $hasMarker = count(array_diff($allNames, [$name])) > 0;

            if ($inDerivedFolder && $hasMarker) {
                foreach ($pDirs as $parentDir) {
                    if (isset($knownDirs[$parentDir])) {
                        $item['derivedStatus'] = 'missing_original';
                        $item['derivedFrom']   = null;
                        break;
                    }
                }
            }
        }

        unset($item);

        return $items;
    }

    /**
     * v2.8.1 - second, complementary derived-file convention: cache files
     * a *module* generates per article and names purely by database IDs,
     * with nothing of the original's file name left in them. Concrete
     * case: Mini FrontPage writes
     * /images/thumbnails/mod_minifrontpage/168_164.png for "article 168,
     * shown in module 164" - applyDerivedFileLinking() can never match
     * that, since there is no name to strip a marker from.
     *
     * Generic, not tied to Mini FrontPage. A file qualifies when BOTH:
     * - it sits in a folder named after a module ("mod_<name>" segment
     *   anywhere in its path), and
     * - its name is nothing but 2-4 numbers joined by "_" or "-"
     *   ("168_164.png").
     * The folder requirement is what keeps this safe: plain dated files
     * like "2023-11-24.pdf" are common content and never qualify on
     * their name alone.
     *
     * The numbers are then checked against the database:
     * - one of them must be a module of exactly that type
     *   (`#__modules`.`module` = the folder's "mod_<name>"), and
     * - every other number must be an article (`#__content`).
     *
     * Result:
     * - module published AND every article published -> linked,
     *   `link_confidence` = 'derived', `derived_status` =
     *   'linked_original', `derived_from` = a readable description
     *   ("artikel 168 "..." in module 164 "...""). Same trade-off as
     *   every other derived link: it stays linked for as long as its
     *   source exists and is published; whether the module happens to
     *   show that article *today* (e.g. only the newest 3) is that
     *   module's own query and deliberately not second-guessed here -
     *   a thumbnail of an article that dropped out is harmless, and
     *   the module regenerates it if the article comes back.
     * - module or an article gone/unpublished/trashed -> stays
     *   unlinked, `derived_status` = 'inactive_source', with a hint
     *   that the file is safe to delete.
     * - numbers that don't resolve as module + articles at all (some
     *   other ID scheme) -> left untouched, exactly as before.
     *
     * @param   array  $items  Items after applyDerivedFileLinking().
     *
     * @return  array
     */
    protected function applyIdNamedCacheLinking(array $items)
    {
        $candidates = [];
        $moduleIds  = [];
        $articleIds = [];

        foreach ($items as $idx => $item) {
            if (!empty($item['linked']) || !empty($item['derivedStatus'])) {
                continue;
            }

            $moduleType = $this->getModuleFolderSegment($item['path']);

            if ($moduleType === null) {
                continue;
            }

            if (!preg_match('~^(\d{1,10}(?:[_\-]\d{1,10}){1,3})\.[a-z0-9]+$~i', $item['name'], $m)) {
                continue;
            }

            $numbers = array_map('intval', preg_split('~[_\-]~', $m[1]));

            $candidates[$idx] = ['module' => $moduleType, 'numbers' => $numbers];

            foreach ($numbers as $n) {
                $moduleIds[$n]  = true;
                $articleIds[$n] = true;
            }
        }

        if (empty($candidates)) {
            return $items;
        }

        $modules  = $this->loadModulesByIds(array_keys($moduleIds));
        $articles = $this->loadArticlesByIds(array_keys($articleIds));

        foreach ($candidates as $idx => $candidate) {
            // Which number is the module? The one whose row is a module
            // of exactly this folder's type - usually the last, but the
            // order isn't assumed.
            $moduleId = null;

            foreach ($candidate['numbers'] as $n) {
                if (isset($modules[$n]) && strtolower($modules[$n]['module']) === $candidate['module']) {
                    $moduleId = $n;
                    break;
                }
            }

            $others = $candidate['numbers'];

            if ($moduleId !== null) {
                unset($others[array_search($moduleId, $others, true)]);
            }

            $othersAreArticles = !empty($others);

            foreach ($others as $n) {
                if (!isset($articles[$n])) {
                    $othersAreArticles = false;
                    break;
                }
            }

            if ($moduleId === null || !$othersAreArticles) {
                // Only act when the whole name resolves: a module of
                // this exact type plus existing articles. Anything else
                // (module deleted outright, or a module using some other
                // ID scheme - K2 items, events, ...) can't be verified,
                // so it's left exactly as it was: plain "Niet gekoppeld",
                // never a "safe to delete" claim we can't back up.
                continue;
            }

            $parts = [];

            foreach ($others as $n) {
                $parts[] = Text::sprintf('COM_MEDIACLEANER_DERIVED_SOURCE_ARTICLE', $n, trim((string) $articles[$n]['title']));
            }

            $parts[] = Text::sprintf('COM_MEDIACLEANER_DERIVED_SOURCE_MODULE', $moduleId, trim((string) $modules[$moduleId]['title']));

            // Trashed (-2), unpublished (0) or archived (2) all count as
            // "not shown" - archived articles don't appear in these
            // listing modules either.
            $active = (int) $modules[$moduleId]['published'] === 1;

            foreach ($others as $n) {
                if ((int) $articles[$n]['state'] !== 1) {
                    $active = false;
                }
            }

            $items[$idx]['derivedFrom'] = implode(', ', $parts);

            if ($active) {
                $items[$idx]['linked']         = true;
                $items[$idx]['linkConfidence'] = 'derived';
                $items[$idx]['derivedStatus']  = 'linked_original';
            } else {
                $items[$idx]['derivedStatus'] = 'inactive_source';
            }
        }

        return $items;
    }

    /**
     * The "mod_<name>" folder segment of a path, lowercased, or null.
     *
     * @param   string  $path
     *
     * @return  string|null
     */
    protected function getModuleFolderSegment($path)
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if (preg_match('~^mod_[a-z0-9_]+$~i', $segment)) {
                return strtolower($segment);
            }
        }

        return null;
    }

    /**
     * Modules by id: [id => ['module', 'title', 'published']].
     *
     * @param   int[]  $ids
     *
     * @return  array
     */
    protected function loadModulesByIds(array $ids)
    {
        return $this->loadRowsByIds('#__modules', ['id', 'module', 'title', 'published'], $ids);
    }

    /**
     * Articles by id: [id => ['title', 'state']].
     *
     * @param   int[]  $ids
     *
     * @return  array
     */
    protected function loadArticlesByIds(array $ids)
    {
        return $this->loadRowsByIds('#__content', ['id', 'title', 'state'], $ids);
    }

    /**
     * @param   string  $table
     * @param   array   $columns  Must include 'id'.
     * @param   int[]   $ids
     *
     * @return  array  [id => row]
     */
    protected function loadRowsByIds($table, array $columns, array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            return [];
        }

        $db     = $this->db;
        $result = [];

        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $query = $db->getQuery(true)
                    ->select($db->quoteName($columns))
                    ->from($db->quoteName($table))
                    ->where($db->quoteName('id') . ' IN (' . implode(',', $chunk) . ')');

                $db->setQuery($query);

                foreach ((array) $db->loadAssocList() as $row) {
                    $result[(int) $row['id']] = $row;
                }
            }
        } catch (\Exception $e) {
            // Can't verify - return what we have; unverifiable files
            // simply stay as they were.
        }

        return $result;
    }

    /**
     * Possible names of the original a (lowercased) file name was derived
     * from, by stripping one size/variant prefix and/or suffix from its
     * stem - see applyDerivedFileLinking() for the conventions. Never
     * returns an empty stem. May include the name itself (callers filter
     * that where it matters).
     *
     * @param   string   $name          Lowercased file name.
     * @param   boolean  $allowWordMarkers  false: numeric size markers only (same-folder use).
     *
     * @return  string[]
     */
    protected function getOriginalNameCandidates($name, $allowWordMarkers)
    {
        $dotPos = strrpos($name, '.');

        if ($dotPos === false || $dotPos === 0) {
            return [];
        }

        $stem = substr($name, 0, $dotPos);
        $ext  = substr($name, $dotPos + 1);

        $wordList = 'thumb|thumbs|thumbnail|tn|th|small|medium|large|mini|preview|resized|sm|md|lg|xs|xl';

        // "nocrop_512_", "crop_104_", "w_800_", "thumb_200x150_"
        $prefixes = ['~^[a-z]{1,16}[_\-]\d{1,5}(?:x\d{1,5})?[_\-]~'];
        // "300x200_" anywhere; a bare "512_" only inside a derived
        // folder - in the same folder a leading number is far more often
        // a date or sequence ("2020_foto.jpg" next to "foto.jpg").
        $prefixes[] = $allowWordMarkers ? '~^\d{1,5}(?:x\d{1,5})?[_\-]~' : '~^\d{1,5}x\d{1,5}[_\-]~';

        // "-300x200", "_1024x768", "-800w", "@2x"
        $suffixes = ['~[_\-]\d{1,5}x\d{1,5}$~', '~[_\-]\d{2,5}w$~', '~@[1-4]x$~'];

        if ($allowWordMarkers) {
            // "thumb_", "phoca_thumb_l_", "small-"
            $prefixes[] = '~^(?:[a-z0-9]+_)?(?:' . $wordList . ')[_\-](?:[a-z][_\-])?~';
            // "_thumb", "-small"
            $suffixes[] = '~[_\-](?:' . $wordList . ')$~';
        }

        $stems = [$stem => true];

        foreach ($prefixes as $pattern) {
            if (preg_match($pattern, $stem, $m) && \strlen($stem) > \strlen($m[0])) {
                $stems[substr($stem, \strlen($m[0]))] = true;
            }
        }

        foreach (array_keys($stems) as $candidate) {
            foreach ($suffixes as $pattern) {
                if (preg_match($pattern, $candidate, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
                    $stems[substr($candidate, 0, $m[0][1])] = true;
                }
            }
        }

        $names = [];

        foreach (array_keys($stems) as $candidate) {
            $names[$candidate . '.' . $ext] = true;

            // Double extension: "x.jpg.webp" / "x.jpg_thumb.webp" -> "x.jpg".
            // Only inside a derived folder: in the same folder a plain
            // "x.webp" next to "x.jpg" is usually a deliberate conversion
            // (Media Cleaner's own "Converteer naar WebP" makes exactly
            // those), not a cache file.
            if ($allowWordMarkers && strpos($candidate, '.') !== false) {
                $names[$candidate] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * For a file's folder, every folder its original could be in if this
     * folder is a derived one (see applyDerivedFileLinking()). Returns an
     * empty array when no segment of the path looks derived - which is
     * also how callers tell "this is a derived folder" at all.
     *
     * Only one segment is rewritten at a time, so
     * "images/eventgallery_generated/Album" yields
     * "images/eventgallery/album" but never touches the album name.
     *
     * @param   string  $path  Item path as stored ("/images/x/y").
     *
     * @return  string[]  Lowercased relative folders, without leading/trailing slash.
     */
    protected function getDerivedParentDirCandidates($path)
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));

        if (empty($segments)) {
            return [];
        }

        $words   = implode('|', array_map(static function ($w) {
            return preg_quote($w, '~');
        }, $this->derivedFolderWords));
        $whole   = '~^[._]?(?:' . $words . ')$~i';
        $suffix  = '~^(.+?)[_.\-](?:' . $words . ')$~i';
        $prefix  = '~^(?:' . $words . ')[_.\-](.+)$~i';
        $results = [];

        foreach ($segments as $i => $segment) {
            $before = \array_slice($segments, 0, $i);
            $after  = \array_slice($segments, $i + 1);

            if ($i > 0 && preg_match($whole, $segment)) {
                // ".../album/thumbs/x.jpg" -> ".../album/x.jpg"
                $results[] = implode('/', array_merge($before, $after));

                // ".../thumbnails/cat/x.jpg" -> ".../originals/cat/x.jpg"
                foreach ($this->derivedOriginalFolderWords as $originalWord) {
                    $results[] = implode('/', array_merge($before, [$originalWord], $after));
                }

                continue;
            }

            foreach ([$suffix, $prefix] as $pattern) {
                if (preg_match($pattern, $segment, $m)) {
                    // "eventgallery_generated" -> "eventgallery"
                    $results[] = implode('/', array_merge($before, [$m[1]], $after));

                    // Mirror-style cache root: "images/webp-cache/blog"
                    // mirrors "images/blog" - drop the segment entirely.
                    if ($i > 0) {
                        $results[] = implode('/', array_merge($before, $after));
                    }
                }
            }
        }

        $results = array_map('strtolower', array_filter($results, 'strlen'));

        return array_values(array_unique($results));
    }

    /**
     * Build a lookup index from the scanned items, once per request, so
     * every sweep can check "is this name one of our files?" with a hash
     * lookup instead of comparing it against every one of potentially
     * hundreds of thousands of items individually.
     *
     * Keyed by lowercased file name only. Matching is case-insensitive
     * by design, so two files differing only by case in the same folder
     * ("Luxemburg-oude.jpg" / "luxemburg-oude.jpg", seen for real on
     * bmwcruiser.nl) share a key and are both marked linked on a hit -
     * the safe direction, since dropping one from consideration can
     * hide a genuine reference (v2.7.2). Whether a hit is 'confirmed'
     * is decided afterwards from the folder path in front of the name
     * (see filterByPrecedingFolder()); a second index keyed by full
     * path is no longer needed for that (v2.8.3).
     *
     * A value is a bare item index while a name is unique, and only
     * becomes a list on an actual collision (see addToLookupIndex()).
     *
     * @param   array  $items
     *
     * @return  array  ['byName' => [lowercased name => itemIndex | itemIndex[]]]
     */
    protected function buildFileLookupIndex(array $items)
    {
        $byName = [];

        foreach ($items as $idx => $item) {
            $this->addToLookupIndex($byName, strtolower($item['name']), $idx);
        }

        return ['byName' => $byName];
    }

    /**
     * Adds one item index under $key in a lookup map whose values are
     * either a single integer (the common case: one file per key) or a
     * list of integers (several files share the key). Read such a value
     * back with a `(array)` cast, which turns both shapes into a list.
     *
     * @param   array    &$map
     * @param   string   $key
     * @param   integer  $idx
     *
     * @return  void
     */
    protected function addToLookupIndex(array &$map, $key, $idx)
    {
        if (!isset($map[$key])) {
            $map[$key] = $idx;
        } elseif (\is_array($map[$key])) {
            $map[$key][] = $idx;
        } else {
            $map[$key] = [$map[$key], $idx];
        }
    }

    /**
     * TEMPORARY DIAGNOSTIC AID (v1.13.1) - writes a plain-text summary of
     * what the extended scan actually did on this rescan (which tables it
     * considered/excluded/searched, how long each phase took, whether it
     * ran out of the time budget, and any exception) to a file readable
     * over plain FTP - no server log access required. Safe to leave in
     * place: it only ever appends a short summary, once per rescan.
     *
     * @param   array  $debug
     *
     * @return  void
     */
    protected function writeScanDebugLog(array $debug, array $items)
    {
        $logFile = JPATH_ADMINISTRATOR . '/logs/mediacleaner_scan_debug.log';

        $counts = ['confirmed' => 0, 'probable' => 0, 'none' => 0];

        foreach ($items as $item) {
            $counts[$item['linkConfidence']] = ($counts[$item['linkConfidence']] ?? 0) + 1;
        }

        $lines   = [];
        $lines[] = '[' . date('Y-m-d H:i:s') . '] rescan';
        $lines[] = '  curated content phase: ' . $debug['curated_ms'] . ' ms';
        $lines[] = '  filesystem code phase: ' . $debug['code_ms'] . ' ms';
        $lines[] = '  generic table phase:   ' . $debug['generic_ms'] . ' ms';
        $lines[] = '  result: confirmed=' . $counts['confirmed'] . ', probable=' . $counts['probable'] . ', unlinked=' . $counts['none']
            . ' (total ' . \count($items) . ')';
        $lines[] = '  memory: peak ' . number_format(memory_get_peak_usage() / 1048576, 1, '.', '') . ' MB so far'
            . ' (memory_limit ' . ini_get('memory_limit') . ', max_execution_time ' . ini_get('max_execution_time') . ')';

        if (\is_array($debug['code'])) {
            $c       = $debug['code'];
            $lines[] = '  code sweep: dirs covered=' . implode(',', $c['dirs_covered'])
                . ', files scanned=' . $c['files_scanned']
                . ', stopped_reason=' . $c['stopped_reason'];
        }

        if ($debug['exception'] !== null) {
            $lines[] = '  EXCEPTION (extended scan skipped): ' . $debug['exception'];
        } elseif (\is_array($debug['generic'])) {
            $g       = $debug['generic'];
            $lines[] = '  generic sweep: tables total=' . $g['tables_total']
                . ', excluded=' . $g['tables_excluded']
                . ', scanned=' . $g['tables_scanned']
                . ', stopped_reason=' . $g['stopped_reason'];
            $lines[] = '  generic sweep: rows checked=' . $g['rows_checked'];

            if (!empty($g['last_tables_scanned'])) {
                $lines[] = '  generic sweep: last tables reached: ' . implode(', ', $g['last_tables_scanned']);
            }

            if (!empty($g['sample_excluded'])) {
                $lines[] = '  generic sweep: sample of excluded tables: ' . implode(', ', $g['sample_excluded']);
            }
        } else {
            $lines[] = '  generic sweep: did not run (see filesystem code phase / exception above)';
        }

        $lines[] = '';

        @file_put_contents($logFile, implode("\n", $lines) . "\n", FILE_APPEND);
    }

    /**
     * Match a table name against a list of glob-style patterns (* = any
     * number of characters), case-insensitively. Uses PHP's fnmatch()
     * rather than hand-rolling SQL-LIKE-to-regex conversion, which is
     * easy to get subtly wrong around escaping.
     *
     * @param   string  $table     Table name to check.
     * @param   array   $patterns  Glob-style patterns, e.g. "*_session".
     *
     * @return  boolean
     */
    protected function tableNameMatchesAnyPattern($table, array $patterns)
    {
        $table = strtolower($table);

        foreach ($patterns as $pattern) {
            if (fnmatch(strtolower($pattern), $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a single file's path appears in the given haystack,
     * trying a few common variations (with/without leading slash, and the
     * JSON-escaped-slash form used by some custom field values).
     *
     * @param   ScanItem|array  $item      Single file record (name + path).
     * @param   string          $haystack  Combined searchable content.
     *
     * @return  boolean
     */
    protected function isReferenced($item, $haystack)
    {
        $relative = trim($item['path'], '/') . '/' . $item['name'];

        $candidates = [
            '/' . $relative,
            $relative,
            str_replace('/', '\\/', $relative),
        ];

        // v2.8.2: same file written URL-encoded ("Stress%20meten.jpg"),
        // so a file linked via an encoded reference also gets its
        // "gebruikt in" line - see matchIndexedCandidates().
        if (preg_match('~[^A-Za-z0-9_.\-/]~', $relative)) {
            $spacesOnly = str_replace(' ', '%20', $relative);
            $fully      = implode('/', array_map('rawurlencode', explode('/', $relative)));

            foreach (array_unique([$spacesOnly, $fully]) as $encoded) {
                if ($encoded !== $relative) {
                    $candidates[] = $encoded;
                    $candidates[] = str_replace('/', '\\/', $encoded);
                }
            }
        }

        // "A&B.jpg" as written inside HTML.
        if (strpos($relative, '&') !== false) {
            $candidates[] = str_replace('&', '&amp;', $relative);
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && stripos($haystack, $candidate) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort preparation for a scan that may take a while on a
     * large site (v2.8.3). Each call is harmless where the host doesn't
     * allow it - the scan then simply runs under the server's own
     * limits, as it always did.
     *
     * - Keep going if the browser gives up waiting, so the result is
     *   still stored and visible on the next page view.
     * - Ask for up to 10 minutes of execution time and 512 MB of memory
     *   when the configured limits are lower (never lowers a limit).
     *
     * @return  void
     */
    protected function prepareEnvironmentForLongScan()
    {
        if (\function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $maxTime = (int) ini_get('max_execution_time');

        if ($maxTime > 0 && $maxTime < 600 && \function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $memoryLimit = trim((string) ini_get('memory_limit'));

        if ($memoryLimit !== '' && $memoryLimit !== '-1' && preg_match('~^(\d+)\s*([kmg]?)~i', $memoryLimit, $m)) {
            $bytes = (int) $m[1] * [' ' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2]) ?: ' '];

            if ($bytes < 536870912 && \function_exists('ini_set')) {
                @ini_set('memory_limit', '512M');
            }
        }
    }

    /**
     * Characters that can never be part of a file name as it appears in
     * content: path separators, the quote and angle brackets that end an
     * HTML attribute or tag, and control characters. A file name mention
     * is whatever runs from the nearest one of these up to a tracked
     * extension (see extractSegments()).
     *
     * @var string
     */
    protected $segmentDelimiters = "/\\\"<>\0\t\n\r";

    /**
     * Total time (seconds) the generic table sweep and the code sweep
     * may take when the scan runs in steps (see ScanJob) - each step is
     * a short request of its own, so the total can be far more generous
     * than the single-request budgets ($haystackMaxSeconds,
     * $codeScanMaxSeconds), which stay as they were for the
     * no-JavaScript fallback.
     *
     * @var integer
     */
    protected $genericSteppedMaxSeconds = 300;

    /**
     * Find every place in a piece of (already lowercased) text where a
     * tracked extension ends a name, and return the text leading up to
     * it - back to the nearest path separator, quote, angle bracket or
     * control character, at most 255 bytes.
     *
     * v2.8.3: replaces the old "candidate" regex, which only accepted
     * letters, digits, "_", "-", ".", slashes and spaces. Any other
     * character cut the name short, so a file called "foto (1).jpg",
     * "a+b.jpg" or "café.jpg" could never be recognised in an article
     * and always showed up as "Niet gekoppeld" even when it was in use.
     * A segment now simply contains whatever the name contains.
     *
     * "images/a.jpg and b.jpg" yields "a.jpg" and "a.jpg and b.jpg" -
     * one segment per extension found; segmentSuffixOffsets() then takes
     * care of names that start later in the segment.
     *
     * @param   string  $lower  Lowercased text.
     *
     * @return  array  List of [segment, offset of the segment in $lower].
     */
    protected function extractSegments($lower)
    {
        $segments = [];

        if ($lower === '' || !preg_match_all($this->getExtensionEndRegex(), $lower, $matches, PREG_OFFSET_CAPTURE)) {
            return $segments;
        }

        foreach ($matches[0] as $match) {
            $pos    = $match[1];
            $from   = $pos > 255 ? $pos - 255 : 0;
            $before = substr($lower, $from, $pos - $from);
            $length = strcspn(strrev($before), $this->segmentDelimiters);

            if ($length === 0) {
                continue;
            }

            $segments[] = [substr($lower, $pos - $length, $length + \strlen($match[0])), $pos - $length];
        }

        return $segments;
    }

    /**
     * Positions inside a segment where a (shorter) file name could
     * start: right after any run of ASCII characters other than letters,
     * digits, "_", "-" and "." - a space, "=", "(", ",", "'" and so on.
     * So for the segment "url(foto (1).jpg" the names tried are, longest
     * first: the whole segment, "foto (1).jpg", "(1).jpg", "1).jpg".
     *
     * @param   string  $segment
     *
     * @return  integer[]  Ascending offsets, at most 32 (the ones nearest the end).
     */
    protected function segmentSuffixOffsets($segment)
    {
        if (!preg_match_all('~[^a-z0-9_\\-.\\x80-\\xff]+~', $segment, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $offsets = [];
        $length  = \strlen($segment);

        foreach ($matches[0] as $match) {
            $offset = $match[1] + \strlen($match[0]);

            if ($offset < $length) {
                $offsets[] = $offset;
            }
        }

        return \count($offsets) > 32 ? \array_slice($offsets, -32) : $offsets;
    }

    /**
     * The text itself plus its decoded forms, where it has any: an
     * editor writes "foto (1).jpg" as "foto%20(1).jpg" in a src
     * attribute (rawurldecode, not urldecode, so a literal "+" in a file
     * name stays a "+"), "A&B.jpg" as "A&amp;B.jpg", and JSON turns
     * "café.jpg" into "caf\u00e9.jpg".
     *
     * @param   string  $text
     *
     * @return  string[]
     */
    protected function textVariants($text)
    {
        $variants = [$text];

        if (strpos($text, '%') !== false && preg_match('~%[0-9a-f]{2}~i', $text)) {
            $variants[] = rawurldecode($text);
        }

        if (strpos($text, '&') !== false && preg_match('~&(?:amp|#\\d+|#x[0-9a-f]+|[a-z]+);~i', $text)) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($decoded !== $text) {
                $variants[] = $decoded;

                if (strpos($decoded, '%') !== false && preg_match('~%[0-9a-f]{2}~i', $decoded)) {
                    $variants[] = rawurldecode($decoded);
                }
            }
        }

        // JSON as Joomla stores it (article images, module params,
        // custom fields) writes "café.jpg" as "caf\u00e9.jpg".
        if (strpos($text, '\\u') !== false) {
            $decoded = preg_replace_callback(
                '~(?:\\\\u[0-9a-f]{4})+~i',
                static function ($match) {
                    $value = json_decode('"' . $match[0] . '"');

                    return \is_string($value) ? $value : $match[0];
                },
                $text
            );

            if (\is_string($decoded) && $decoded !== $text) {
                $variants[] = $decoded;
            }
        }

        return $variants;
    }

    /**
     * The core of the extension-agnostic scan: checks one or more
     * separate pieces of text (one per database column, or one file's
     * content) for references to scanned files, and marks those files
     * linked.
     *
     * Cost is proportional to how much text there is, not to how many
     * files are tracked: each value is searched once for tracked
     * extensions, and what precedes each one is looked up directly in
     * the index built by buildFileLookupIndex().
     *
     * - The longest name that exists on disk wins: for "nieuwe foto.jpg"
     *   a file by exactly that name is preferred over one called
     *   "foto.jpg".
     * - 'confirmed' when the file's own folder path stands directly in
     *   front of the name (plain, with JSON-escaped slashes or with
     *   backslashes). When several same-named files qualify, only the
     *   one(s) with the longest matching folder - and same-named files
     *   in other folders are then left alone, as before.
     * - 'probable' when only the bare name was found.
     *
     * Takes separate values rather than one joined string, so a name can
     * never be glued together out of two adjacent columns.
     *
     * @param   array   &$items  Items array, modified in place.
     * @param   array   $values  One or more separate pieces of text.
     * @param   array   $index   Result of buildFileLookupIndex().
     *
     * @return  void
     */
    protected function matchIndexedCandidates(array &$items, array $values, array $index)
    {
        $byName = $index['byName'];

        foreach ($values as $value) {
            $value = (string) $value;

            if ($value === '' || \strlen($value) > $this->haystackMaxCellBytes) {
                continue;
            }

            foreach ($this->textVariants($value) as $text) {
                $lower = strtolower($text);

                foreach ($this->extractSegments($lower) as $found) {
                    $segment   = $found[0];
                    $nameStart = $found[1];
                    $key       = null;

                    if (isset($byName[$segment])) {
                        $key = $segment;
                    } else {
                        foreach ($this->segmentSuffixOffsets($segment) as $offset) {
                            $candidate = substr($segment, $offset);

                            if (isset($byName[$candidate])) {
                                $key        = $candidate;
                                $nameStart += $offset;
                                break;
                            }
                        }
                    }

                    if ($key === null) {
                        continue;
                    }

                    $indices   = (array) $byName[$key];
                    $confirmed = $this->filterByPrecedingFolder($items, $indices, $lower, $nameStart);

                    if (!empty($confirmed)) {
                        foreach ($confirmed as $idx) {
                            $items[$idx]['linked']         = true;
                            $items[$idx]['linkConfidence'] = 'confirmed';
                        }

                        continue;
                    }

                    foreach ($indices as $idx) {
                        if ($items[$idx]['linkConfidence'] !== 'confirmed') {
                            $items[$idx]['linked']         = true;
                            $items[$idx]['linkConfidence'] = 'probable';
                        }
                    }
                }
            }
        }
    }

    /**
     * Of the files in $indices (all sharing one name), which have their
     * own folder path standing directly in front of the name at
     * $nameStart in $lower? Returns only those with the longest such
     * folder - two files differing only in letter case share folder and
     * name, and are both returned (see buildFileLookupIndex()).
     *
     * The folder must itself start at a boundary - "ximages/a.jpg" is
     * not a reference to "images/a.jpg" - but may be preceded by more
     * path: "https://site.nl/images/a.jpg" and "/submap/images/a.jpg"
     * both count. Files in the site root have no folder to check and are
     * never 'confirmed' this way, same as before.
     *
     * @param   array    $items
     * @param   array    $indices
     * @param   string   $lower      Lowercased text.
     * @param   integer  $nameStart  Offset of the name in $lower.
     *
     * @return  integer[]
     */
    protected function filterByPrecedingFolder(array $items, array $indices, $lower, $nameStart)
    {
        if ($nameStart === 0) {
            return [];
        }

        $take = $nameStart > 1200 ? 1200 : $nameStart;
        $pre  = substr($lower, $nameStart - $take, $take);

        if (strpos($pre, '\\') !== false) {
            $pre = str_replace(['\\/', '\\'], '/', $pre);
        }

        $preLength = \strlen($pre);

        if ($preLength === 0 || $pre[$preLength - 1] !== '/') {
            return [];
        }

        $best      = 0;
        $confirmed = [];

        foreach ($indices as $idx) {
            $dir    = strtolower(trim($items[$idx]['path'], '/'));
            $length = \strlen($dir);

            if ($length === 0 || $length < $best || $length + 1 > $preLength) {
                continue;
            }

            if (substr_compare($pre, $dir . '/', -($length + 1)) !== 0) {
                continue;
            }

            $before = $preLength - $length - 2;

            if ($before >= 0 && preg_match('~[a-z0-9_\\-.\\x80-\\xff]~', $pre[$before])) {
                continue;
            }

            if ($length > $best) {
                $best      = $length;
                $confirmed = [];
            }

            $confirmed[] = $idx;
        }

        return $confirmed;
    }

    /**
     * Which of the linked files does this piece of text refer to?
     *
     * Step 1 narrows the field: every name that occurs in the text (see
     * extractSegments(), segmentSuffixOffsets()) is looked up in
     * $nameIndex. Step 2 confirms each of those few files with
     * isReferenced() - the file's full relative path must be present.
     * That used to be the only step, run for every linked file against
     * every row: 20,000 articles x 30,000 linked files = 600 million
     * searches through article bodies, which never finishes. Now the
     * cost is proportional to the amount of text.
     *
     * @param   string  $haystack     Text of one row.
     * @param   array   $nameIndex    lowercased file name => index (or list of indices) into $linkedItems.
     * @param   array   $linkedItems  Linked items, 0-based.
     *
     * @return  string[]  Relative paths (no leading slash) of the files referenced, in $linkedItems order.
     */
    protected function findReferencedFiles($haystack, array $nameIndex, array $linkedItems)
    {
        $variants = $this->textVariants($haystack);
        $indices  = [];

        foreach ($variants as $text) {
            foreach ($this->extractSegments(strtolower($text)) as $found) {
                $segment = $found[0];

                if (isset($nameIndex[$segment])) {
                    foreach ((array) $nameIndex[$segment] as $idx) {
                        $indices[$idx] = true;
                    }
                }

                foreach ($this->segmentSuffixOffsets($segment) as $offset) {
                    $candidate = substr($segment, $offset);

                    if (isset($nameIndex[$candidate])) {
                        foreach ((array) $nameIndex[$candidate] as $idx) {
                            $indices[$idx] = true;
                        }
                    }
                }
            }
        }

        if (empty($indices)) {
            return [];
        }

        ksort($indices);

        $found = [];

        foreach ($indices as $idx => $unused) {
            $file = $linkedItems[$idx];

            foreach ($variants as $text) {
                if ($this->isReferenced($file, $text)) {
                    $found[] = trim($file['path'], '/') . '/' . $file['name'];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Matches ".jpg", ".pdf", ... (any tracked extension) where it ends
     * a name, in already-lowercased text. Built once and cached.
     *
     * @return  string
     */
    protected function getExtensionEndRegex()
    {
        if ($this->extensionEndRegex === null) {
            $extPattern = implode('|', array_map(
                static function ($ext) {
                    return preg_quote($ext, '~');
                },
                $this->extensions
            ));

            $this->extensionEndRegex = '~\\.(?:' . $extPattern . ')(?![a-z0-9])~';
        }

        return $this->extensionEndRegex;
    }

    /**
     * The sources searched for "gebruikt in" references:
     * [table, text columns, title column, reference type, edit-url builder].
     *
     * Third-party tables are only read if they exist (forEachTableRow()
     * simply reports an error and the source is skipped otherwise), so
     * this is safe on sites that don't have the extension installed.
     * Their edit links point at the extension's general list view rather
     * than a specific item, since their single-item edit route isn't
     * something we could verify without the extension's own source.
     *
     * @return  array
     */
    protected function getReferenceSources()
    {
        return [
            ['#__content', ['introtext', 'fulltext', 'images', 'metadesc', 'metakey'], 'title', 'article', static function ($row) {
                return 'index.php?option=com_content&task=article.edit&id=' . (int) $row['id'];
            }],
            ['#__modules', ['content', 'params'], 'title', 'module', static function ($row) {
                return 'index.php?option=com_modules&task=module.edit&id=' . (int) $row['id'];
            }],
            ['#__menu', ['link', 'params'], 'title', 'menu', static function ($row) {
                return 'index.php?option=com_menus&task=item.edit&id=' . (int) $row['id'];
            }],
            ['#__categories', ['description', 'params'], 'title', 'category', static function ($row) {
                $extension = $row['extension'] ?? 'com_content';

                return 'index.php?option=com_categories&task=category.edit&id=' . (int) $row['id']
                    . '&extension=' . urlencode($extension);
            }],
            ['#__contact_details', ['misc', 'address', 'params'], 'name', 'contact', static function ($row) {
                return 'index.php?option=com_contact&task=contact.edit&id=' . (int) $row['id'];
            }],
            ['#__banners', ['description', 'params'], 'name', 'banner', static function ($row) {
                return 'index.php?option=com_banners&task=banner.edit&id=' . (int) $row['id'];
            }],
            ['#__icagenda_events', ['image', 'file', 'shortdesc', 'desc', 'params', 'version_customfields'], 'title', 'icagenda', static function ($row) {
                return 'index.php?option=com_icagenda&view=events';
            }],
            ['#__jdownloads_files', ['file_pic', 'images', 'url_download', 'preview_filename', 'description', 'description_long'], 'title', 'jdownloads', static function ($row) {
                return 'index.php?option=com_jdownloads&view=files';
            }],
            // Confirmed via the site's own admin menu: this extension's
            // element is "com_gallery" (shown in the sidebar as "Gallery"),
            // not "com_bagallery" as originally guessed.
            ['#__gallery_items', ['path', 'url', 'thumbnail_url', 'name', 'settings'], 'title', 'bagallery', static function ($row) {
                return 'index.php?option=com_gallery&view=galleries';
            }],
        ];
    }

    /**
     * For every linked file, find which article(s), module(s), menu
     * item(s), custom fields etc. reference it, so "Gekoppelde media"
     * can show a deep link straight to the right edit screen. Each
     * reference found is handed to $emit straight away rather than
     * collected here.
     *
     * Resumable: $cursor remembers which source and which row it had
     * reached, so the work can be spread over several short requests.
     *
     * @param   array       $linkedItems  Linked items, 0-based.
     * @param   array       $nameIndex    See findReferencedFiles().
     * @param   array       &$cursor      ['s' => source number, 'c' => forEachTableRow() cursor]; start with ['s' => 0, 'c' => null].
     * @param   float|null  $deadline     microtime(true) value to pause at, or null to run to the end.
     * @param   callable    $emit         function (string $relativePath, string $type, string $title, ?string $url): void
     *
     * @return  boolean  true when every source has been read, false when paused at the deadline.
     */
    protected function collectReferences(array $linkedItems, array $nameIndex, array &$cursor, $deadline, callable $emit)
    {
        $sources = $this->getReferenceSources();
        $total   = \count($sources);

        while ($cursor['s'] < $total) {
            [$table, $textColumns, $titleColumn, $type, $urlBuilder] = $sources[$cursor['s']];

            $columns = array_values(array_unique(array_merge(['id', $titleColumn], $textColumns)));

            $result = $this->forEachTableRow(
                $table,
                $columns,
                'id',
                function (array $row) use ($emit, $nameIndex, $linkedItems, $textColumns, $titleColumn, $type, $urlBuilder) {
                    $haystack = '';

                    foreach ($textColumns as $column) {
                        $haystack .= ' ' . (string) ($row[$column] ?? '');
                    }

                    if (trim($haystack) === '') {
                        return;
                    }

                    $found = $this->findReferencedFiles($haystack, $nameIndex, $linkedItems);

                    if (empty($found)) {
                        return;
                    }

                    $title = (string) ($row[$titleColumn] ?? '');
                    $url   = $urlBuilder($row);

                    foreach ($found as $relative) {
                        $emit($relative, $type, $title, $url);
                    }
                },
                $deadline,
                $cursor['c']
            );

            if ($result['reason'] === 'deadline') {
                return false;
            }

            $cursor['s']++;
            $cursor['c'] = null;
        }

        // Custom fields come last, as one more "source" ($total). Only
        // fields attached to articles (com_content.article) get a
        // clickable deep link, since that is the only context with a
        // predictable, generic edit URL; matches in fields on other
        // content types are still reported, just without a link.
        if ($cursor['s'] === $total) {
            $db = $this->db;

            // The field definitions are few and small; the *values*
            // table is the one that can get large, so that is read in
            // chunks and matched to its field here rather than with one
            // big JOIN.
            try {
                $query = $db->getQuery(true)
                    ->select($db->quoteName(['id', 'title', 'context']))
                    ->from($db->quoteName('#__fields'));

                $db->setQuery($query);
                $fields = $db->loadAssocList('id');
            } catch (\Exception $e) {
                $fields = [];
            }

            if (!empty($fields)) {
                $result = $this->forEachTableRow(
                    '#__fields_values',
                    ['field_id', 'item_id', 'value'],
                    null,
                    function (array $row) use ($emit, $fields, $nameIndex, $linkedItems) {
                        $field = $fields[$row['field_id']] ?? null;

                        if ($field === null) {
                            return;
                        }

                        $haystack = (string) ($row['value'] ?? '');

                        if (trim($haystack) === '') {
                            return;
                        }

                        foreach ($this->findReferencedFiles($haystack, $nameIndex, $linkedItems) as $relative) {
                            if ($field['context'] === 'com_content.article' && is_numeric($row['item_id'])) {
                                $emit(
                                    $relative,
                                    'field',
                                    Text::sprintf('COM_MEDIACLEANER_REF_FIELD_ARTICLE', $field['title']),
                                    'index.php?option=com_content&task=article.edit&id=' . (int) $row['item_id']
                                );
                            } else {
                                $emit($relative, 'field', Text::sprintf('COM_MEDIACLEANER_REF_FIELD_GENERIC', $field['title']), null);
                            }
                        }
                    },
                    $deadline,
                    $cursor['c']
                );

                if ($result['reason'] === 'deadline') {
                    return false;
                }
            }

            $cursor['s']++;
            $cursor['c'] = null;
        }

        return true;
    }

    /**
     * Sweep the core Joomla tables that commonly reference media files
     * (see $curatedSources): articles, modules, categories, menu items,
     * custom fields, contacts and banners. Each row is checked against
     * the file lookup index and then discarded. This pass has no time
     * budget of its own - however large the site, every article is
     * always searched; $deadline only *pauses* it.
     *
     * @param   array       &$items    Items array, modified in place.
     * @param   array       $index     Result of buildFileLookupIndex().
     * @param   array       &$cursor   ['t' => table number, 'c' => forEachTableRow() cursor]; start with ['t' => 0, 'c' => null].
     * @param   float|null  $deadline  microtime(true) value to pause at, or null to run to the end.
     *
     * @return  boolean  true when finished, false when paused at the deadline.
     */
    protected function sweepCuratedContent(array &$items, array $index, array &$cursor, $deadline)
    {
        $tables = array_keys($this->curatedSources);

        while ($cursor['t'] < \count($tables)) {
            $table = $tables[$cursor['t']];

            // Table/columns not present on this Joomla version: the
            // first query fails and the table is skipped silently.
            $result = $this->forEachTableRow(
                $table,
                $this->curatedSources[$table],
                $table === '#__fields_values' ? null : 'id',
                function (array $row) use (&$items, $index) {
                    $this->matchIndexedCandidates($items, $row, $index);
                },
                $deadline,
                $cursor['c']
            );

            if ($result['reason'] === 'deadline') {
                return false;
            }

            $cursor['t']++;
            $cursor['c'] = null;
        }

        return true;
    }

    /**
     * Sweep every database table (aside from this component's own and
     * the excluded noise patterns - see $genericScanExcludeTablePatterns)
     * for text columns, checking each row against the lookup index as
     * soon as it's read and then discarding it. This is what lets any
     * third-party extension's media references be picked up
     * automatically, without a hand-maintained list of tables per
     * extension.
     *
     * Resumable: $state carries the table list, the position reached
     * and the diagnostic stats from one call to the next. Unlike the
     * curated sweep this one does have a total time budget ($budget
     * seconds over all calls together) - a site can hold gigabytes of
     * unrelated data in other extensions' tables.
     *
     * SHOW TABLES / SHOW COLUMNS rather than information_schema: both
     * are available to any MySQL/MariaDB user that can use the database
     * at all, while information_schema access is restricted on some
     * shared hosting setups (exactly what happened on bmwcruiser.nl).
     *
     * @param   array       &$items    Items array, modified in place.
     * @param   array       $index     Result of buildFileLookupIndex().
     * @param   array       &$state    Start with []; see below for the keys.
     * @param   float|null  $deadline  microtime(true) value to pause at, or null to run to the end.
     * @param   integer     $budget    Total seconds allowed over all calls.
     *
     * @return  boolean  true when finished (or out of budget), false when paused at the deadline.
     */
    protected function sweepGenericTables(array &$items, array $index, array &$state, $deadline, $budget)
    {
        $db     = $this->db;
        $prefix = $db->getPrefix();
        $start  = microtime(true);

        if (empty($state)) {
            $state = [
                'tables'  => [],
                'i'       => 0,
                'c'       => null,
                'spent'   => 0.0,
                'stats'   => [
                    'tables_total'        => 0,
                    'tables_excluded'     => 0,
                    'tables_scanned'      => 0,
                    'rows_checked'        => 0,
                    'stopped_reason'      => 'finished',
                    'last_tables_scanned' => [],
                    'sample_excluded'     => [],
                ],
            ];

            $excludePatterns = array_map(
                static function ($pattern) use ($prefix) {
                    return str_replace('#__', $prefix, $pattern);
                },
                $this->genericScanExcludeTablePatterns
            );

            try {
                $db->setQuery('SHOW TABLES LIKE ' . $db->quote($prefix . '%'));
                $tables = $db->loadColumn();
            } catch (\Exception $e) {
                $state['stats']['stopped_reason'] = 'could not list tables: ' . $e->getMessage();
                Log::add('Media Cleaner: generic table sweep could not list tables (' . $e->getMessage() . ') - skipped.', Log::WARNING, 'jerror');

                return true;
            }

            $state['stats']['tables_total'] = \count($tables);

            foreach ($tables as $table) {
                if ($this->tableNameMatchesAnyPattern($table, $excludePatterns)) {
                    $state['stats']['tables_excluded']++;

                    if (\count($state['stats']['sample_excluded']) < 15) {
                        $state['stats']['sample_excluded'][] = $table;
                    }

                    continue;
                }

                $state['tables'][] = $table;
            }
        }

        $budgetDeadline    = $start + max(0.0, $budget - $state['spent']);
        $effectiveDeadline = $deadline === null ? $budgetDeadline : min($deadline, $budgetDeadline);

        $textTypes    = ['varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'char'];
        $integerTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];

        // Columns sweepCuratedContent() has already read in full, keyed
        // by real (prefixed, lowercased) table name - not read again
        // here, so the largest table on most sites (`#__content`) isn't
        // searched twice.
        $alreadyCovered = [];

        foreach ($this->curatedSources as $curatedTable => $curatedColumns) {
            $alreadyCovered[strtolower(str_replace('#__', $prefix, $curatedTable))] = array_map('strtolower', $curatedColumns);
        }

        $finished = true;

        while ($state['i'] < \count($state['tables'])) {
            if (microtime(true) >= $budgetDeadline) {
                $state['stats']['stopped_reason'] = 'time budget reached';
                break;
            }

            $table = $state['tables'][$state['i']];

            try {
                $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName($table));
                $columnRows = $db->loadAssocList();
            } catch (\Exception $e) {
                // Table disappeared mid-scan or similar - skip it.
                $state['i']++;
                $state['c'] = null;

                continue;
            }

            $columns     = [];
            $primaryKeys = [];
            $covered     = $alreadyCovered[strtolower($table)] ?? [];

            foreach ($columnRows as $columnRow) {
                // Column type strings look like "varchar(255)", "text",
                // "int(10) unsigned", "bigint unsigned" - only the
                // leading word matters.
                $type = strtolower((string) ($columnRow['Type'] ?? ''));
                $type = preg_match('~^[a-z]+~', $type, $typeMatch) ? $typeMatch[0] : $type;

                if (($columnRow['Key'] ?? '') === 'PRI') {
                    $primaryKeys[] = ['field' => $columnRow['Field'], 'integer' => \in_array($type, $integerTypes, true)];
                }

                if (\in_array($type, $textTypes, true) && !\in_array(strtolower((string) $columnRow['Field']), $covered, true)) {
                    $columns[] = $columnRow['Field'];
                }
            }

            if (empty($columns)) {
                $state['i']++;
                $state['c'] = null;

                continue;
            }

            if ($state['c'] === null) {
                $state['stats']['tables_scanned']++;
                $state['stats']['last_tables_scanned'][] = $table;

                if (\count($state['stats']['last_tables_scanned']) > 15) {
                    array_shift($state['stats']['last_tables_scanned']);
                }
            }

            // A single integer primary key lets forEachTableRow() page
            // through the table by key ("WHERE id > last"), which stays
            // fast however large the table is. Anything else falls back
            // to LIMIT/OFFSET.
            $keyColumn  = \count($primaryKeys) === 1 && $primaryKeys[0]['integer'] ? $primaryKeys[0]['field'] : null;
            $rowsBefore = $state['c']['rows'] ?? 0;

            $result = $this->forEachTableRow(
                $table,
                $columns,
                $keyColumn,
                function (array $row) use (&$items, $index) {
                    $this->matchIndexedCandidates($items, $row, $index);
                },
                $effectiveDeadline,
                $state['c']
            );

            $state['stats']['rows_checked'] += $result['rows'] - $rowsBefore;

            if ($result['reason'] === 'deadline') {
                if (microtime(true) >= $budgetDeadline) {
                    $state['stats']['stopped_reason'] = 'time budget reached';
                } else {
                    $finished = false;
                }

                break;
            }

            // 'finished', or 'error' (table/columns changed shape
            // mid-scan, or a transient DB error): on to the next table
            // rather than failing the whole scan over one bad table.
            $state['i']++;
            $state['c'] = null;
        }

        $state['spent'] += microtime(true) - $start;

        return $finished;
    }

    /**
     * Read the text content of every source file under one of
     * $codeScanDirs (see $codeScanExtensions), checking each file's
     * content against the lookup index and then discarding it - so a
     * media reference hardcoded in a template override, a system plugin
     * or a third-party component's own bundled UI is still picked up.
     *
     * @param   array    &$items    Items array, modified in place.
     * @param   array    $index     Result of buildFileLookupIndex().
     * @param   string   $dir       One of $codeScanDirs.
     * @param   float    $deadline  microtime(true) value to stop at.
     * @param   array    &$stats    ['dirs_covered' => [], 'files_scanned' => 0, 'stopped_reason' => 'finished']
     *
     * @return  void
     */
    protected function sweepCodeDirectory(array &$items, array $index, $dir, $deadline, array &$stats)
    {
        $path = rtrim(JPATH_ROOT, '/\\') . '/' . $dir;

        if (!is_dir($path)) {
            return;
        }

        $stats['dirs_covered'][] = $dir;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            // The foreach itself is inside this try block:
            // RecursiveDirectoryIterator can throw *during* iteration
            // (e.g. on a permission-denied subdirectory), not only when
            // it's constructed.
            foreach ($iterator as $file) {
                if (microtime(true) >= $deadline) {
                    $stats['stopped_reason'] = 'deadline reached';

                    return;
                }

                if (!$file->isFile()) {
                    continue;
                }

                if (!\in_array(strtolower($file->getExtension()), $this->codeScanExtensions, true)) {
                    continue;
                }

                $size = $file->getSize();

                if ($size <= 0 || $size > $this->codeScanMaxFileBytes) {
                    continue;
                }

                $content = @file_get_contents($file->getPathname());

                if ($content === false || $content === '') {
                    continue;
                }

                $this->matchIndexedCandidates($items, [$content], $index);
                $stats['files_scanned']++;
            }
        } catch (\Exception $e) {
            // Unreadable subdirectory or similar - keep whatever was
            // already found in this directory.
        }
    }

    /**
     * Walk the Joomla installation and report every media file (see
     * $extensions) to $onFile, folder by folder.
     *
     * v2.8.3: replaces the single RecursiveDirectoryIterator pass.
     * Resumable - $walk holds the folders still to visit and how far
     * into the current one it got - so a site with hundreds of thousands
     * of files can be walked over several short requests. Entries are
     * visited in alphabetical order (a folder's own files first, then
     * its subfolders), so a scan gives the same result regardless of the
     * order the filesystem happens to list things in.
     *
     * Skipped, at any depth: hidden entries (.git, .well-known, ...),
     * the folders in $excludeDirs, and symlinked folders (avoids
     * infinite loops).
     *
     * @param   array       &$walk     Start with ['stack' => [''], 'cur' => null].
     * @param   float|null  $deadline  microtime(true) value to pause at, or null to run to the end.
     * @param   callable    $onDir     function (string $relDir): void - called before the first file of a folder
     *                                 ('' for the root, no leading or trailing slash). May be called again for the
     *                                 same folder when a walk resumes in the middle of it.
     * @param   callable    $onFile    function (string $name, string $absolutePath, int $size, ?int $mtime, string $extension): void
     *
     * @return  boolean  true when the whole tree has been walked, false when paused at the deadline.
     */
    protected function walkFilesystem(array &$walk, $deadline, callable $onDir, callable $onFile)
    {
        $root = rtrim(JPATH_ROOT, '/\\');

        while (true) {
            if ($walk['cur'] === null) {
                if (empty($walk['stack'])) {
                    return true;
                }

                if ($deadline !== null && microtime(true) >= $deadline) {
                    return false;
                }

                $walk['cur'] = ['dir' => array_pop($walk['stack']), 'offset' => 0, 'subdirs' => []];
            }

            $relDir  = $walk['cur']['dir'];
            $absDir  = $relDir === '' ? $root : $root . '/' . $relDir;
            $entries = @scandir($absDir);

            if ($entries === false) {
                $walk['cur'] = null;

                continue;
            }

            $count     = \count($entries);
            $first     = $walk['cur']['offset'];
            $announced = false;

            for ($i = $first; $i < $count; $i++) {
                // Checked every 64 entries, and never before at least
                // one batch of this call has been handled - so even an
                // absurdly short deadline still makes progress.
                if ($deadline !== null && $i > $first && ($i & 63) === 0 && microtime(true) >= $deadline) {
                    $walk['cur']['offset'] = $i;

                    return false;
                }

                $name = (string) $entries[$i];

                if ($name === '' || $name[0] === '.') {
                    continue;
                }

                $full = $absDir . '/' . $name;

                if (is_dir($full)) {
                    if (!\in_array(strtolower($name), $this->excludeDirs, true) && !is_link($full)) {
                        $walk['cur']['subdirs'][] = $name;
                    }

                    continue;
                }

                $dot = strrpos($name, '.');

                if ($dot === false) {
                    continue;
                }

                $extension = strtolower(substr($name, $dot + 1));

                if (!\in_array($extension, $this->extensions, true) || !is_file($full)) {
                    continue;
                }

                if (!$announced) {
                    $onDir($relDir);
                    $announced = true;
                }

                $size  = @filesize($full);
                $mtime = @filemtime($full);

                $onFile($name, $full, $size === false ? 0 : (int) $size, $mtime === false ? null : (int) $mtime, $extension);
            }

            // Pushed in reverse so they come off the stack alphabetically.
            foreach (array_reverse($walk['cur']['subdirs']) as $subdir) {
                $walk['stack'][] = $relDir === '' ? $subdir : $relDir . '/' . $subdir;
            }

            $walk['cur'] = null;
        }
    }

    /**
     * Reads $columns of every row of $table and hands each row (as an
     * associative array) to $callback, a bounded number of rows per
     * query - see $chunkRowsStart for how the chunk size adapts. Nothing
     * is accumulated here: once $callback returns, the row is gone.
     *
     * v2.8.3: replaces the unbounded "SELECT ... FROM table" calls the
     * curated sweep and the reference index used to make. Those pulled
     * an entire table - every article body on the site - into memory in
     * one go, which is what ran a 512 MB memory_limit dry on a site with
     * a very large `#__content` table.
     *
     * @param   string         $table      Table name; "#__" prefix notation or a real name.
     * @param   array          $columns    Columns to read.
     * @param   string|null    $keyColumn  Integer, unique, indexed column to page by
     *                                     ("WHERE key > last ORDER BY key"); null to page
     *                                     by LIMIT/OFFSET. If the keyed query fails outright
     *                                     on the first chunk, OFFSET paging is tried instead.
     * @param   callable       $callback   function (array $row): void
     * @param   float|null     $deadline   microtime(true) value to pause at, or null for none. At least one
     *                                     chunk is always read per call, so repeated calls always advance.
     * @param   array|null     &$cursor    Position to resume from / reached; pass null to start at the top.
     *                                     Only meaningful after a 'deadline' result.
     *
     * @return  array  ['rows' => int (total so far, over all calls with this cursor), 'reason' => 'finished'|'deadline'|'error']
     */
    protected function forEachTableRow($table, array $columns, $keyColumn, callable $callback, $deadline = null, &$cursor = null)
    {
        $db = $this->db;

        if (!\is_array($cursor)) {
            $cursor = ['key' => null, 'offset' => 0, 'limit' => $this->chunkRowsStart, 'rows' => 0, 'nokey' => false];
        }

        if ($cursor['nokey']) {
            $keyColumn = null;
        }

        $keyIsData  = $keyColumn !== null && \in_array($keyColumn, $columns, true);
        $chunksDone = 0;

        while (true) {
            if ($deadline !== null && $chunksDone > 0 && microtime(true) >= $deadline) {
                return ['rows' => $cursor['rows'], 'reason' => 'deadline'];
            }

            try {
                $query = $db->getQuery(true)->from($db->quoteName($table));

                if ($keyColumn !== null) {
                    $query->select($db->quoteName($keyIsData ? $columns : array_merge($columns, [$keyColumn])));

                    if ($cursor['key'] !== null) {
                        $query->where($db->quoteName($keyColumn) . ' > ' . (int) $cursor['key']);
                    }

                    $query->order($db->quoteName($keyColumn) . ' ASC')->setLimit($cursor['limit']);
                } else {
                    $query->select($db->quoteName($columns))->setLimit($cursor['limit'], $cursor['offset']);
                }

                $db->setQuery($query);
                $rows = $db->loadAssocList();
            } catch (\Exception $e) {
                if ($keyColumn !== null && $cursor['rows'] === 0) {
                    // No such key column on this table after all -
                    // retry the plain way before giving up on it.
                    $keyColumn       = null;
                    $keyIsData       = false;
                    $cursor['nokey'] = true;

                    continue;
                }

                return ['rows' => $cursor['rows'], 'reason' => 'error'];
            }

            $fetched = \is_array($rows) ? \count($rows) : 0;

            if ($fetched === 0) {
                break;
            }

            $bytes = 0;

            foreach ($rows as $row) {
                if ($keyColumn !== null) {
                    $cursor['key'] = $row[$keyColumn];

                    if (!$keyIsData) {
                        unset($row[$keyColumn]);
                    }
                }

                foreach ($row as $value) {
                    $bytes += \strlen((string) $value);
                }

                $callback($row);
            }

            unset($rows);

            $cursor['rows']   += $fetched;
            $cursor['offset'] += $fetched;
            $chunksDone++;

            if ($fetched < $cursor['limit']) {
                // Reached the end of this table.
                break;
            }

            if ($bytes > $this->chunkTargetBytes) {
                $cursor['limit'] = max($this->chunkRowsMin, intdiv($cursor['limit'], 2));
            } elseif ($bytes < intdiv($this->chunkTargetBytes, 4)) {
                $cursor['limit'] = min($this->chunkRowsMax, $cursor['limit'] * 2);
            }
        }

        return ['rows' => $cursor['rows'], 'reason' => 'finished'];
    }

    /**
     * Detect SVG files that won't show anything meaningful when displayed
     * as a picture - most commonly webfont/icon-sprite SVGs, which browsers
     * happily "load" successfully (so error/onload-based checks in the
     * browser can't reliably catch them) but render as blank or empty.
     * Checked once here, at scan time, so the overview can show the
     * generic placeholder icon for these deterministically instead of
     * guessing client-side.
     *
     * Two independent signals are used, since icon-font SVGs vary in
     * internal structure between tools/versions:
     * 1. Content signature: presence of `<font`, `<glyph` or `<symbol`
     *    elements - the classic SVG-font spec and the more modern
     *    icon-sprite approach, respectively.
     * 2. File size: a genuine single-picture SVG is essentially never
     *    larger than a moderate size; icon-font/sprite collections
     *    routinely run into the hundreds of KB purely because they bundle
     *    hundreds of glyphs in one file, regardless of which of the above
     *    internal formats they use.
     *
     * @param   string   $absolutePath
     * @param   integer  $size          File size in bytes.
     *
     * @return  boolean
     */
    protected function svgHasNoVisualContent($absolutePath, $size = 0)
    {
        // A real, single-picture SVG larger than this is exceedingly rare;
        // icon-font/sprite collections routinely exceed it many times over.
        if ($size > 100 * 1024) {
            return true;
        }

        $handle = @fopen($absolutePath, 'rb');

        if (!$handle) {
            return false;
        }

        $chunk = fread($handle, 65536);
        fclose($handle);

        if ($chunk === false) {
            return false;
        }

        return stripos($chunk, '<font') !== false
            || stripos($chunk, '<glyph') !== false
            || stripos($chunk, '<symbol') !== false;
    }
}
