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
use RecursiveCallbackFilterIterator;
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
 * This class does not touch `#__mediacleaner_files` /
 * `#__mediacleaner_references` itself - it only returns arrays. Writing
 * the scan results to the database stays FilesModel::rescan()'s
 * responsibility, same as before the split.
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
     * Cache for getCandidateFileRegex() - built once per request.
     *
     * @var string|null
     */
    protected $candidateFileRegex = null;

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
     * Number of rows fetched per query in sweepGenericTables(), instead of
     * reading an entire (potentially huge) table in one go.
     *
     * @var integer
     */
    protected $genericScanChunkSize = 500;

    /**
     * @param   \Joomla\Database\DatabaseDriver  $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Run a full scan-and-link pass: walk the filesystem, work out which
     * files are still referenced, apply the "ignored" and "active
     * extension asset" flags, and build the reference index. This is
     * exactly the sequence FilesModel::rescan() used to run inline before
     * the v1.37.0 split - only the database persistence step stayed
     * behind in FilesModel, since writing `#__mediacleaner_files` /
     * `#__mediacleaner_references` is that model's own responsibility.
     *
     * @return  array  ['items' => array, 'referenceIndex' => array]
     */
    public function scan()
    {
        $this->registerCrashLogger();

        $items = $this->scanFilesystem();
        $items = $this->applyManualUploadOutlierDetection($items);
        $items = $this->markLinkedStatus($items);
        $items = $this->markIgnoredStatus($items);
        $items = $this->applyAutoIgnoreThumbs($items);
        $items = $this->markActiveExtensionAssetStatus($items);
        $items = $this->applyExtensionFolderSegmentOverride($items, ['images'], false);
        $items = $this->applyExtensionFolderSegmentOverride($items, ['canvas', 'fonts'], true);
        $items = $this->applyActiveExtensionFilenameOverride($items);

        $linkedItems = array_values(array_filter($items, static function ($item) {
            return $item['linked'];
        }));

        $referenceIndex = $this->buildReferenceIndex($linkedItems);

        return [
            'items'          => $items,
            'referenceIndex' => $referenceIndex,
        ];
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

        foreach ($toInsert as $relative) {
            try {
                $insertQuery = $db->getQuery(true)
                    ->insert($db->quoteName('#__mediacleaner_ignored'))
                    ->columns($db->quoteName(['relative_path', 'ignored_at', 'source', 'suppressed']))
                    ->values($db->quote($relative) . ',' . $db->quote($now) . ",'auto_thumbs',0");

                $db->setQuery($insertQuery)->execute();
            } catch (\Exception $e) {
                // Best-effort: a failed insert just leaves this one file
                // visible under Niet-gekoppeld until the next scan
                // retries it, rather than failing the whole scan.
            }
        }

        return $items;
    }

    /**
     * Determine, for every scanned file, whether it is still referenced
     * somewhere on the site, and how confident we are about that:
     *
     * - 'confirmed': the file's full relative path (folder + name) was
     *   found - in Joomla's own content tables, in the generic sweep of
     *   every other database table (see sweepGenericTables()), or
     *   hardcoded in a template/plugin/component source file (see
     *   sweepFilesystemCode()). This is about as sure as a text
     *   search can be.
     * - 'probable': no full path was found anywhere, but the file's exact
     *   name turned up in some other table's text column. Some
     *   extensions (JDownloads is a concrete example) only store the bare
     *   filename and keep the folder as separate, non-obvious
     *   configuration - a real reference, but one a generic filename
     *   search could in principle also match by coincidence, so it's
     *   flagged rather than silently treated the same as a confirmed hit.
     * - 'none': not found anywhere - "Niet gekoppeld".
     *
     * This is a heuristic text search, not a guarantee, on either tier:
     * always check manually before deleting "unlinked" files.
     *
     * @param   array  $items  Items as returned by scanFilesystem().
     *
     * @return  array  Same items, each with added 'linked' (boolean) and 'linkConfidence' ('confirmed'|'probable'|'none').
     */
    /**
     * Build a lookup index from the scanned items, once per rescan, so
     * every sweep below can check "is this candidate string one of our
     * files?" with an O(1) hash lookup instead of comparing it against
     * every one of potentially thousands of items individually.
     *
     * @param   array  $items
     *
     * @return  array  ['byPath' => [relativePath => [itemIndex, ...]], 'byName' => [basename => [itemIndex, ...]]]
     */
    protected function buildFileLookupIndex(array $items)
    {
        $byPath = [];
        $byName = [];

        foreach ($items as $idx => $item) {
            $relative = strtolower(trim($item['path'], '/') . '/' . $item['name']);

            // v2.7.2: was a single idx (last write wins) - two files
            // differing only by case in the same folder (e.g.
            // "Luxemburg-oude-....jpg" and "luxemburg-oude-....jpg",
            // seen for real on bmwcruiser.nl) both normalise to the same
            // $relative key here, so whichever scanned second used to
            // silently overwrite the first. Since matching is
            // case-insensitive by design (candidate paths get
            // strtolower()'d too - see matchIndexedCandidates()), a real
            // reference to one of the two physical files could only ever
            // confirm-link whichever one happened to win the collision,
            // leaving its same-name-different-case sibling looking
            // "Niet-gekoppeld" even when it's the file actually in use.
            // Now every colliding item is tracked and all of them get
            // marked linked on a hit - the safe direction, since the
            // alternative (silently dropping one from consideration) can
            // hide a genuine reference.
            if (!isset($byPath[$relative])) {
                $byPath[$relative] = [];
            }

            $byPath[$relative][] = $idx;

            $name = strtolower($item['name']);

            if (!isset($byName[$name])) {
                $byName[$name] = [];
            }

            $byName[$name][] = $idx;
        }

        return ['byPath' => $byPath, 'byName' => $byName];
    }

    /**
     * A regex matching any run of path-like characters ending in one of
     * our tracked media extensions (see $extensions) - "candidate file
     * references" that a piece of text might contain, regardless of
     * which extension put them there. Built once and cached, since it's
     * used on every chunk of every sweep.
     *
     * @return  string
     */
    protected function getCandidateFileRegex()
    {
        if ($this->candidateFileRegex === null) {
            $extPattern = implode('|', array_map(
                static function ($ext) {
                    return preg_quote($ext, '~');
                },
                $this->extensions
            ));

            // Includes a literal space in the character class: real
            // filenames often contain spaces (e.g. "Handleiding 1200
            // C.pdf", seen verbatim in JDownloads data on bmwcruiser.nl) -
            // without it, the match would be truncated at the first space
            // and never line up with the actual filename. (?<![\w]) still
            // avoids starting mid-word, so this doesn't swallow entire
            // unrelated sentences before a coincidental ".pdf" - it only
            // extends into whitespace *within* an already-started
            // path/filename-like run.
            $this->candidateFileRegex = '~(?<![\\w])[\\w\\-./\\\\ ]{1,300}\\.(?:' . $extPattern . ')~i';
        }

        return $this->candidateFileRegex;
    }

    /**
     * The core of the new, extension-agnostic, universal scan: rather
     * than checking every item against a whole chunk of text (expensive
     * when there are thousands of items - this was the reason
     * sweepGenericTables() could get through only 58 of 238 tables in
     * 20 seconds on bmwcruiser.nl), this scans each value *once* for
     * anything that looks like a file reference ending in one of our
     * tracked extensions, and looks each one up directly in the index
     * built by buildFileLookupIndex(). Cost is proportional to how much
     * text there is, not to how many files we're tracking - so it stays
     * fast no matter how large the site's media library is, and works
     * identically for any extension's tables without needing to know
     * that extension's schema in advance.
     *
     * Takes an array of separate values (e.g. one per database column),
     * not one pre-joined string: because the character class now allows
     * spaces (needed for filenames like "Handleiding 1200 C.pdf" - see
     * CHANGELOG v1.17.1), joining every column with a single space first
     * let a match span across what were originally separate columns
     * (e.g. gluing a checksum column onto an adjacent filename column),
     * producing a garbled candidate that could never match the index.
     * Scanning each value on its own avoids that entirely, while a
     * genuine space *within* one value (a real filename) still works.
     *
     * @param   array   &$items  Items array, modified in place.
     * @param   array   $values  One or more separate pieces of text (column values, or a single file's content).
     * @param   array   $index   Result of buildFileLookupIndex().
     *
     * @return  void
     */
    protected function matchIndexedCandidates(array &$items, array $values, array $index)
    {
        foreach ($values as $text) {
            $text = (string) $text;

            if ($text === '' || \strlen($text) > $this->haystackMaxCellBytes) {
                continue;
            }

            if (!preg_match_all($this->getCandidateFileRegex(), $text, $matches)) {
                continue;
            }

            foreach ($matches[0] as $candidate) {
                // Normalise: JSON-escaped slashes (\/) collapse to a
                // single real slash first - doing a bare backslash-to-
                // slash replace before this would turn "a\/b" into
                // "a//b" instead of "a/b", since the slash that was
                // already there stays put. Any *remaining* backslash (a
                // genuine Windows-style separator, not JSON escaping)
                // becomes a forward slash afterwards.
                $normalized = str_replace('\\/', '/', $candidate);
                $normalized = str_replace('\\', '/', $normalized);
                $normalized = strtolower($normalized);
                $normalized = ltrim($normalized, '/');

                if (isset($index['byPath'][$normalized])) {
                    foreach ($index['byPath'][$normalized] as $idx) {
                        $items[$idx]['linked']         = true;
                        $items[$idx]['linkConfidence'] = 'confirmed';
                    }

                    continue;
                }

                $basename = strtolower(basename($normalized));

                if (isset($index['byName'][$basename])) {
                    foreach ($index['byName'][$basename] as $idx) {
                        if ($items[$idx]['linkConfidence'] !== 'confirmed') {
                            $items[$idx]['linked']         = true;
                            $items[$idx]['linkConfidence'] = 'probable';
                        }
                    }
                }
            }
        }
    }

    protected function markLinkedStatus(array $items)
    {
        foreach ($items as &$item) {
            $item['linked']         = false;
            $item['linkConfidence'] = 'none';
        }

        unset($item);

        $index = $this->buildFileLookupIndex($items);

        // The overall deadline for the two heavier phases combined - a
        // safety net now rather than the load-bearing mechanism it used
        // to be, since the index-based matching below is no longer
        // O(items) per chunk and should comfortably finish well inside
        // this on any realistic site.
        $deadline = microtime(true) + $this->haystackMaxSeconds;

        $debug = [
            'curated_ms' => 0,
            'generic_ms' => 0,
            'code_ms'    => 0,
            'generic'    => null,
            'code'       => null,
            'exception'  => null,
        ];

        $t0 = microtime(true);

        // Phase 1 - curated core Joomla content (articles, modules,
        // menus, categories, contacts, banners, custom fields). Kept as
        // a fast, guaranteed-to-run-to-completion pass even though the
        // generic sweep below now covers these tables too - cheap
        // insurance in case the generic sweep is ever interrupted.
        $this->sweepCuratedContent($items, $index);
        $debug['curated_ms'] = (int) round((microtime(true) - $t0) * 1000);

        // Phase 2 and 3 below are the newer, heavier machinery - inherently
        // more exposed to surprises on a given site's exact combination of
        // extensions, table sizes and file permissions. This outer
        // try/catch is the last line of defense: if something we didn't
        // anticipate still throws, the rescan falls back to whatever was
        // already found instead of failing outright.
        try {
            $t1               = microtime(true);
            $debug['generic'] = $this->sweepGenericTables($items, $index, $deadline);
            $debug['generic_ms'] = (int) round((microtime(true) - $t1) * 1000);

            $codeDeadline = min($deadline, microtime(true) + $this->codeScanMaxSeconds);

            $t2 = microtime(true);
            $debug['code'] = $this->sweepFilesystemCode($items, $index, $codeDeadline);
            $debug['code_ms'] = (int) round((microtime(true) - $t2) * 1000);
        } catch (\Throwable $e) {
            $debug['exception'] = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            Log::add(
                'Media Cleaner: the extended (generic table / filesystem code) scan failed and was skipped for this rescan: ' . $e->getMessage(),
                Log::WARNING,
                'jerror'
            );
        }

        $this->writeScanDebugLog($debug, $items);

        return $items;
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
     * Sweep every database table (aside from this component's own and the
     * excluded noise patterns - see $genericScanExcludeTablePatterns) for
     * text/varchar columns, checking each row against the
     * still-unresolved items as soon as it's read and then discarding it
     * - never accumulating everything into one big string (see
     * $haystackMaxCellBytes for why).
     *
     * This is what lets any third-party extension's media references be
     * picked up automatically, without a curated, hand-maintained list of
     * tables/columns per extension.
     *
     * @param   array    &$items    Items array, modified in place.
     * @param   array    $index     Result of buildFileLookupIndex().
     * @param   float    $deadline  microtime(true) value to stop at.
     *
     * @return  array  Diagnostic stats - see writeScanDebugLog().
     */
    protected function sweepGenericTables(array &$items, array $index, $deadline)
    {
        $db     = $this->db;
        $prefix = $db->getPrefix();

        $stats = [
            'tables_total'        => 0,
            'tables_excluded'     => 0,
            'tables_scanned'      => 0,
            'rows_checked'        => 0,
            'stopped_reason'      => 'finished',
            'last_tables_scanned' => [],
            'sample_excluded'     => [],
        ];

        $excludePatterns = array_map(
            static function ($pattern) use ($prefix) {
                return str_replace('#__', $prefix, $pattern);
            },
            $this->genericScanExcludeTablePatterns
        );

        // SHOW TABLES / SHOW COLUMNS rather than querying
        // information_schema directly: both are universally available to
        // any MySQL/MariaDB user that can use the database at all, while
        // information_schema access can be restricted on some shared
        // hosting setups - which would make the whole generic sweep
        // silently find nothing, with no obvious symptom beyond "this
        // isn't working" (exactly what happened on bmwcruiser.nl).
        try {
            $db->setQuery('SHOW TABLES LIKE ' . $db->quote($prefix . '%'));
            $tables = $db->loadColumn();
        } catch (\Exception $e) {
            $stats['stopped_reason'] = 'could not list tables: ' . $e->getMessage();
            Log::add('Media Cleaner: generic table sweep could not list tables (' . $e->getMessage() . ') - skipped.', Log::WARNING, 'jerror');

            return $stats;
        }

        $stats['tables_total'] = \count($tables);
        $textTypePrefixes      = ['varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'char'];

        foreach ($tables as $table) {
            if (microtime(true) >= $deadline) {
                $stats['stopped_reason'] = 'deadline reached';
                return $stats;
            }

            if ($this->tableNameMatchesAnyPattern($table, $excludePatterns)) {
                $stats['tables_excluded']++;

                if (\count($stats['sample_excluded']) < 15) {
                    $stats['sample_excluded'][] = $table;
                }

                continue;
            }

            try {
                $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName($table));
                $columnRows = $db->loadAssocList();
            } catch (\Exception $e) {
                // Table disappeared mid-scan or similar - skip it.
                continue;
            }

            $columns = [];

            foreach ($columnRows as $columnRow) {
                // Column type strings look like "varchar(255)", "text",
                // "mediumtext", etc. - match on the prefix before any "(".
                $type = strtolower((string) ($columnRow['Type'] ?? ''));
                $type = strstr($type, '(', true) ?: $type;

                if (\in_array($type, $textTypePrefixes, true)) {
                    $columns[] = $columnRow['Field'];
                }
            }

            if (empty($columns)) {
                continue;
            }

            $stats['tables_scanned']++;
            $stats['last_tables_scanned'][] = $table;

            if (\count($stats['last_tables_scanned']) > 15) {
                array_shift($stats['last_tables_scanned']);
            }

            $offset = 0;

            // Read this table in bounded chunks rather than one
            // unbounded SELECT, so a single very large table (an active
            // forum's post bodies, for example) never needs to be held in
            // memory all at once - and each row is checked against the
            // lookup index and then discarded immediately below, rather
            // than being collected anywhere.
            while (true) {
                if (microtime(true) >= $deadline) {
                    $stats['stopped_reason'] = 'deadline reached';
                    return $stats;
                }

                try {
                    $query = $db->getQuery(true)
                        ->select($db->quoteName($columns))
                        ->from($db->quoteName($table))
                        ->setLimit($this->genericScanChunkSize, $offset);
                    $db->setQuery($query);
                    $rows = $db->loadRowList();
                } catch (\Exception $e) {
                    // Table/columns changed shape mid-scan, or a transient
                    // DB error - move on to the next table rather than
                    // failing the whole rescan over one bad table.
                    break;
                }

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $this->matchIndexedCandidates($items, $row, $index);
                    $stats['rows_checked']++;
                }

                $offset += $this->genericScanChunkSize;

                if (count($rows) < $this->genericScanChunkSize) {
                    // Reached the end of this table.
                    break;
                }
            }
        }

        return $stats;
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
     * Read the text content of every template/plugin/component/module
     * source file (see $codeScanDirs / $codeScanExtensions), checking each
     * file's content against the file lookup index and then discarding
     * it - so a media reference hardcoded directly in a template
     * override, a system plugin, or (importantly) a third-party
     * component's own bundled UI assets is still picked up, without ever
     * holding more than one file's content in memory at a time.
     *
     * @param   array  &$items    Items array, modified in place.
     * @param   array  $index     Result of buildFileLookupIndex().
     * @param   float  $deadline  microtime(true) value to stop at.
     *
     * @return  array  Diagnostic stats - see writeScanDebugLog().
     */
    protected function sweepFilesystemCode(array &$items, array $index, $deadline)
    {
        $root = rtrim(JPATH_ROOT, '/\\');

        $stats = [
            'dirs_covered'   => [],
            'files_scanned'  => 0,
            'stopped_reason' => 'finished',
        ];

        foreach ($this->codeScanDirs as $dir) {
            if (microtime(true) >= $deadline) {
                $stats['stopped_reason'] = 'deadline reached';
                return $stats;
            }

            $path = $root . '/' . $dir;

            if (!is_dir($path)) {
                continue;
            }

            $stats['dirs_covered'][] = $dir;

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                // The foreach itself - not just building the iterator - is
                // inside this try block: RecursiveDirectoryIterator can
                // throw *during* iteration (e.g. UnexpectedValueException
                // on a permission-denied subdirectory), not only when it's
                // constructed. An uncaught exception here previously
                // turned one unreadable folder into a fatal error for the
                // whole rescan; now it just ends this top-level directory
                // early and moves on to the next one in $codeScanDirs.
                foreach ($iterator as $file) {
                    if (microtime(true) >= $deadline) {
                        $stats['stopped_reason'] = 'deadline reached';
                        return $stats;
                    }

                    if (!$file->isFile()) {
                        continue;
                    }

                    $ext = strtolower($file->getExtension());

                    if (!\in_array($ext, $this->codeScanExtensions, true)) {
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
                // Unreadable subdirectory or similar - skip the rest of
                // this top-level dir, keep whatever was already found,
                // and continue with the next one.
                continue;
            }
        }

        return $stats;
    }

    /**
     * Check whether a single file's path appears in the given haystack,
     * trying a few common variations (with/without leading slash, and the
     * JSON-escaped-slash form used by some custom field values).
     *
     * @param   array   $item      Single file record (name + path).
     * @param   string  $haystack  Combined searchable content.
     *
     * @return  boolean
     */
    protected function isReferenced(array $item, $haystack)
    {
        $relative = trim($item['path'], '/') . '/' . $item['name'];

        $candidates = [
            '/' . $relative,
            $relative,
            str_replace('/', '\\/', $relative),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && stripos($haystack, $candidate) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * For every file already known to be "linked", figure out exactly
     * which article(s), module(s), menu item(s), etc. reference it, so the
     * "Gekoppelde media" view can show a deep link straight to the right
     * edit screen.
     *
     * This only runs for the (usually much smaller) set of already-linked
     * files, keeping rescan() performance reasonable.
     *
     * @param   array  $linkedItems  Items from scanFilesystem() with 'linked' === true.
     *
     * @return  array  relative_path => list of ['type' => ..., 'title' => ..., 'url' => ...|null]
     */
    protected function buildReferenceIndex(array $linkedItems)
    {
        $references = [];

        if (empty($linkedItems)) {
            return $references;
        }

        $db = $this->db;

        /**
         * Scan a table's rows for matches against every linked file and
         * append a reference entry for each hit.
         */
        $scan = function ($table, array $textColumns, $titleColumn, $type, callable $urlBuilder) use (&$references, $db, $linkedItems) {
            try {
                $columns = array_unique(array_merge(['id', $titleColumn], $textColumns));
                $query   = $db->getQuery(true)->select($db->quoteName($columns))->from($db->quoteName($table));
                $db->setQuery($query);
                $rows = $db->loadAssocList();
            } catch (\Exception $e) {
                return;
            }

            foreach ($rows as $row) {
                $haystack = '';

                foreach ($textColumns as $column) {
                    $haystack .= ' ' . (string) ($row[$column] ?? '');
                }

                if (trim($haystack) === '') {
                    continue;
                }

                foreach ($linkedItems as $file) {
                    if (!$this->isReferenced($file, $haystack)) {
                        continue;
                    }

                    $relative                = trim($file['path'], '/') . '/' . $file['name'];
                    $references[$relative][] = [
                        'type'  => $type,
                        'title' => (string) ($row[$titleColumn] ?? ''),
                        'url'   => $urlBuilder($row),
                    ];
                }
            }
        };

        $scan(
            '#__content',
            ['introtext', 'fulltext', 'images', 'metadesc', 'metakey'],
            'title',
            'article',
            static function ($row) {
                return 'index.php?option=com_content&task=article.edit&id=' . (int) $row['id'];
            }
        );

        $scan(
            '#__modules',
            ['content', 'params'],
            'title',
            'module',
            static function ($row) {
                return 'index.php?option=com_modules&task=module.edit&id=' . (int) $row['id'];
            }
        );

        $scan(
            '#__menu',
            ['link', 'params'],
            'title',
            'menu',
            static function ($row) {
                return 'index.php?option=com_menus&task=item.edit&id=' . (int) $row['id'];
            }
        );

        $scan(
            '#__categories',
            ['description', 'params'],
            'title',
            'category',
            static function ($row) {
                $extension = $row['extension'] ?? 'com_content';

                return 'index.php?option=com_categories&task=category.edit&id=' . (int) $row['id']
                    . '&extension=' . urlencode($extension);
            }
        );

        $scan(
            '#__contact_details',
            ['misc', 'address', 'params'],
            'name',
            'contact',
            static function ($row) {
                return 'index.php?option=com_contact&task=contact.edit&id=' . (int) $row['id'];
            }
        );

        $scan(
            '#__banners',
            ['description', 'params'],
            'name',
            'banner',
            static function ($row) {
                return 'index.php?option=com_banners&task=banner.edit&id=' . (int) $row['id'];
            }
        );

        // Third-party extensions below. These are only scanned if their
        // table actually exists (the $scan() closure catches the DB error
        // and simply skips silently otherwise), so this is safe to ship
        // even for sites that don't have the extension installed.
        //
        // The edit links point at each extension's general list view
        // rather than a specific item, since - unlike the Joomla core
        // views above - their exact single-item edit route isn't
        // something we could verify without the extension's own source.
        // If either of these turns out to be wrong for a given version of
        // the extension, the type label and title are still correct; only
        // the deep link would need adjusting.
        $scan(
            '#__icagenda_events',
            ['image', 'file', 'shortdesc', 'desc', 'params', 'version_customfields'],
            'title',
            'icagenda',
            static function ($row) {
                return 'index.php?option=com_icagenda&view=events';
            }
        );

        $scan(
            '#__jdownloads_files',
            ['file_pic', 'images', 'url_download', 'preview_filename', 'description', 'description_long'],
            'title',
            'jdownloads',
            static function ($row) {
                return 'index.php?option=com_jdownloads&view=files';
            }
        );

        // Confirmed via the site's own admin menu: this extension's
        // element is "com_gallery" (shown in the sidebar as "Gallery"),
        // not "com_bagallery" as originally guessed.
        $scan(
            '#__gallery_items',
            ['path', 'url', 'thumbnail_url', 'name', 'settings'],
            'title',
            'bagallery',
            static function ($row) {
                return 'index.php?option=com_gallery&view=galleries';
            }
        );

        $this->scanCustomFields($linkedItems, $references);

        // De-duplicate identical references per file (e.g. a match found in
        // both the title and the body of the same article).
        foreach ($references as $relative => $refs) {
            $seen   = [];
            $unique = [];

            foreach ($refs as $ref) {
                $key = $ref['type'] . '|' . $ref['url'] . '|' . $ref['title'];

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $unique[]   = $ref;
                }
            }

            $references[$relative] = $unique;
        }

        return $references;
    }

    /**
     * Scan custom field values for references. Only fields attached to
     * articles (com_content.article) get a clickable deep link, since that
     * is the only context with a predictable, generic edit URL; matches in
     * fields on other content types are still reported, just without a link.
     *
     * @param   array  $linkedItems
     * @param   array  &$references  Passed by reference, same shape as buildReferenceIndex()'s return value.
     *
     * @return  void
     */
    protected function scanCustomFields(array $linkedItems, array &$references)
    {
        $db = $this->db;

        try {
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('fv.item_id', 'item_id'),
                    $db->quoteName('fv.value', 'value'),
                    $db->quoteName('f.title', 'field_title'),
                    $db->quoteName('f.context', 'context'),
                ])
                ->from($db->quoteName('#__fields_values', 'fv'))
                ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('fv.field_id'));

            $db->setQuery($query);
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return;
        }

        foreach ($rows as $row) {
            $haystack = (string) ($row['value'] ?? '');

            if (trim($haystack) === '') {
                continue;
            }

            foreach ($linkedItems as $file) {
                if (!$this->isReferenced($file, $haystack)) {
                    continue;
                }

                $relative = trim($file['path'], '/') . '/' . $file['name'];

                if ($row['context'] === 'com_content.article' && is_numeric($row['item_id'])) {
                    $references[$relative][] = [
                        'type'  => 'field',
                        'title' => Text::sprintf('COM_MEDIACLEANER_REF_FIELD_ARTICLE', $row['field_title']),
                        'url'   => 'index.php?option=com_content&task=article.edit&id=' . (int) $row['item_id'],
                    ];
                } else {
                    $references[$relative][] = [
                        'type'  => 'field',
                        'title' => Text::sprintf('COM_MEDIACLEANER_REF_FIELD_GENERIC', $row['field_title']),
                        'url'   => null,
                    ];
                }
            }
        }
    }

    /**
     * Sweep the core Joomla tables that commonly reference media files,
     * checking each row against the file lookup index and then discarding
     * it, rather than concatenating everything into one big string first.
     *
     * Covered: articles, modules, categories, menu items, custom fields,
     * contacts and banners. Third-party extensions (IC Agenda, JDownloads,
     * BA Gallery, and anything else) no longer need a hand-curated entry
     * here: since v1.17.0 the generic sweep (sweepGenericTables()) checks
     * every table via the same fast, extension-pattern-based index lookup
     * used here, so it's both fast enough and general enough to find them
     * without needing to know their schema in advance. This method stays
     * as a small, fast, always-completes-first pass over Joomla's own
     * core tables specifically - cheap insurance in case the generic
     * sweep is ever interrupted before reaching them.
     *
     * @param   array  &$items  Items array, modified in place.
     * @param   array  $index   Result of buildFileLookupIndex().
     *
     * @return  void
     */
    protected function sweepCuratedContent(array &$items, array $index)
    {
        $db = $this->db;

        $sources = [
            '#__content'         => ['introtext', 'fulltext', 'images', 'metadesc', 'metakey'],
            '#__modules'         => ['content', 'params'],
            '#__categories'      => ['description', 'params'],
            '#__menu'            => ['link', 'params'],
            '#__fields_values'   => ['value'],
            '#__contact_details' => ['misc', 'address', 'params'],
            '#__banners'         => ['description', 'params'],
        ];

        foreach ($sources as $table => $columns) {
            try {
                $query = $db->getQuery(true)
                    ->select($db->quoteName($columns))
                    ->from($db->quoteName($table));

                $db->setQuery($query);
                $rows = $db->loadRowList();
            } catch (\Exception $e) {
                // Table/columns not present on this Joomla version - skip
                // silently.
                continue;
            }

            foreach ($rows as $row) {
                $this->matchIndexedCandidates($items, $row, $index);
            }
        }
    }

    /**
     * Recursively walk the whole Joomla installation and collect every file
     * whose extension matches one of the configured graphic file types.
     *
     * @return  array
     */
    protected function scanFilesystem()
    {
        $root    = rtrim(JPATH_ROOT, '/\\');
        $exclude = $this->excludeDirs;
        $results = [];

        if (!is_dir($root) || !is_readable($root)) {
            return $results;
        }

        try {
            $directoryIterator = new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
            );

            $filterIterator = new RecursiveCallbackFilterIterator(
                $directoryIterator,
                function ($current) use ($exclude) {
                    $name = $current->getFilename();

                    // Skip hidden files/folders (.git, .well-known, ...)
                    if (substr($name, 0, 1) === '.') {
                        return false;
                    }

                    if ($current->isDir()) {
                        if (in_array(strtolower($name), $exclude, true)) {
                            return false;
                        }

                        // Never follow symlinks: avoids infinite loops.
                        if ($current->isLink()) {
                            return false;
                        }
                    }

                    return true;
                }
            );

            $iterator = new RecursiveIteratorIterator(
                $filterIterator,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (\Exception $e) {
            return $results;
        }

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $extension = strtolower($fileInfo->getExtension());

            if (!in_array($extension, $this->extensions, true)) {
                continue;
            }

            $fullPath = $fileInfo->getPathname();
            $relDir   = ltrim(str_replace($root, '', $fileInfo->getPath()), '/\\');
            $relDir   = str_replace('\\', '/', $relDir);

            $relFile = ($relDir === '' ? '' : $relDir . '/') . $fileInfo->getFilename();
            $urlPath = implode('/', array_map('rawurlencode', explode('/', $relFile)));

            $size = 0;

            try {
                $size = $fileInfo->getSize();
            } catch (\Exception $e) {
                // Unreadable file, skip size but still list it.
            }

            $type = $extension === 'tiff' ? 'tif' : $extension;

            $modifiedAt = null;

            try {
                $mtime = $fileInfo->getMTime();

                if ($mtime !== false) {
                    $modifiedAt = date('Y-m-d H:i:s', $mtime);
                }
            } catch (\Exception $e) {
                // Unreadable mtime - leave null, just skip the
                // manual-upload-date heuristic for this one file.
            }

            $results[] = [
                'name'             => $fileInfo->getFilename(),
                'path'             => $relDir === '' ? '/' : '/' . $relDir,
                'size'             => $size,
                'sizeKB'           => $size / 1024,
                'type'             => $type,
                'url'              => rtrim(Uri::root(), '/') . '/' . $urlPath,
                'noPreview'        => $type === 'svg' ? $this->svgHasNoVisualContent($fullPath, $size) : false,
                'isThumbsDir'      => $this->pathHasThumbsSegment($relDir),
                'isSystemAssetDir' => $this->pathHasSystemAssetSegment($relDir),
                'modifiedAt'       => $modifiedAt,
            ];
        }

        return $results;
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
