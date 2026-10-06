<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;

/**
 * Runs a complete scan as a series of short, resumable steps (v2.8.3).
 *
 * Why: a scan used to be one single web request - walk the whole site,
 * search the whole database, write the whole result. On a site with a
 * very large media library that request simply takes longer than the
 * web server allows, and the server cuts it off: nothing gets stored,
 * and the overview stays at "Nog niet gescand" (first seen on
 * niburu.co, right after the out-of-memory problem was fixed).
 *
 * Now the browser asks for the scan one step at a time (see
 * FilesController::scanStep() and the script in tmpl/files/default.php).
 * Each request does a few seconds of work, writes down exactly where it
 * got to, and reports real progress back. No single request is long, so
 * no time limit is hit, however large the site.
 *
 * Phases, in order:
 *
 *   walk           find the media files on disk
 *   curated        search Joomla's own content tables (always complete)
 *   generic        search every other table (within a time budget)
 *   code           search template/plugin/component/module source
 *   classify       derived files, ignored state, extension hints
 *   refs           work out where each linked file is used
 *   persist_files  write `#__mediacleaner_files`
 *   persist_refs   write `#__mediacleaner_references`
 *
 * Between requests the intermediate result lives in a few files in
 * Joomla's tmp folder, named after an unguessable scan id. Every step
 * is safe to repeat: if a request is lost half-way, the next one picks
 * up from the last completed step and nothing is counted or stored
 * twice. The live tables are only touched in the two persist phases, at
 * the very end - a scan that fails earlier leaves the previous overview
 * exactly as it was.
 *
 * run($id, null) runs everything in one go; that is what the
 * no-JavaScript fallback (FilesModel::rescan()) uses, so both routes
 * share one implementation and give identical results.
 */
class ScanJob extends Scanner
{
    /**
     * Column order of the lines in the "final" work file (see
     * writeFinalFile() / readFinalLine()).
     *
     * @var string[]
     */
    protected const FINAL_FIELDS = [
        'name', 'size', 'type', 'mtime', 'linked', 'linkConfidence', 'ignored', 'noPreview', 'isThumbsDir',
        'isActiveExtensionAsset', 'isActiveExtensionImagesDir', 'possiblyOrphanedExtension',
        'imagesDirExtensionName', 'imagesDirExtensionRemoved', 'assetDirExtensionName', 'assetDirExtensionRemoved',
        'isSystemAssetDir', 'likelyManualUpload', 'derivedFrom', 'derivedStatus',
    ];

    /**
     * Scanned items, loaded at most once per request.
     *
     * @var ScanItem[]|null
     */
    protected $items = null;

    /**
     * @var array|null  Result of buildFileLookupIndex() for $items.
     */
    protected $index = null;

    /**
     * @var string|null
     */
    protected $workDir = null;

    /**
     * Begin a new scan. Any earlier scan's work files are removed first -
     * there is only ever one scan in flight, which also keeps two people
     * from writing the overview tables at the same time.
     *
     * @return  string  The new scan id.
     *
     * @throws  \RuntimeException  When no writable work folder is available.
     */
    public function start()
    {
        $dir = $this->getWorkDir();

        foreach ((array) glob($dir . '/mediacleaner_scan_*') as $old) {
            @unlink($old);
        }

        $id = bin2hex(random_bytes(16));

        $this->saveState([
            'v'          => 1,
            'id'         => $id,
            'phase'      => 'walk',
            'started'    => microtime(true),
            'now'        => Factory::getDate()->toSql(),
            'requests'   => 0,
            'walk'       => ['stack' => [''], 'cur' => null],
            'itemsBytes' => 0,
            'count'      => 0,
            'cur'        => ['t' => 0, 'c' => null],
            'gen'        => [],
            'code'       => ['d' => 0, 'stats' => ['dirs_covered' => [], 'files_scanned' => 0, 'stopped_reason' => 'finished']],
            'exception'  => null,
            'refs'       => ['s' => 0, 'c' => null],
            'refsBytes'  => 0,
            'finalBytes' => 0,
            'totalSize'  => 0,
            'pf'         => null,
            'pr'         => null,
            'skipped'    => 0,
            'ms'         => [],
        ]);

        return $id;
    }

    /**
     * Do scan work for roughly $budget seconds (null: until finished)
     * and report where things stand.
     *
     * @param   string        $scanId  Id returned by start().
     * @param   integer|null  $budget  Seconds of work for this call; null for no limit.
     *
     * @return  array  ['done' => bool, 'scanId' => string, 'phase' => string, 'percent' => int,
     *                  'label' => string, 'count' => int, 'sizeKB' => float]
     *
     * @throws  \RuntimeException  When the scan's work files are gone (expired, or another scan was started).
     */
    public function run($scanId, $budget = null)
    {
        $this->registerCrashLogger();
        $this->prepareEnvironmentForLongScan();

        $state = $this->loadState($scanId);

        // Only one request works on a scan at a time. When a connection
        // is cut by the web server, the PHP process behind it often
        // carries on - and the browser's retry must then wait for it
        // rather than do the same step a second time alongside it. The
        // lock disappears by itself when the request holding it ends,
        // however it ends.
        $lock = @fopen($this->path($scanId, 'lock'), 'c');

        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return ['busy' => true] + $this->status($state);
        }

        // Re-read now that the lock is held: the request that just
        // finished may have moved things along.
        $state    = $this->loadState($scanId);
        $deadline = $budget === null ? null : microtime(true) + max(0.001, (float) $budget);
        $units    = 0;

        $state['requests']++;

        do {
            $phase   = $state['phase'];
            $started = microtime(true);

            // 'classify' is the one unit that cannot be paused half-way.
            // It gets a request to itself instead of being started in
            // whatever time another phase happened to leave over.
            if ($phase === 'classify' && $deadline !== null && $units > 0) {
                break;
            }

            $units++;

            switch ($phase) {
                case 'walk':
                    $this->stepWalk($state, $deadline);
                    break;

                case 'curated':
                    $this->stepCurated($state, $deadline);
                    break;

                case 'generic':
                case 'code':
                    // The two newer, heavier sweeps are more exposed to
                    // surprises on a given site's exact combination of
                    // extensions, table sizes and file permissions. If
                    // something unanticipated throws, the scan carries
                    // on with whatever was already found instead of
                    // failing outright.
                    try {
                        $phase === 'generic' ? $this->stepGeneric($state, $deadline) : $this->stepCode($state, $deadline);
                    } catch (\Throwable $e) {
                        $state['exception'] = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();

                        Log::add(
                            'Media Cleaner: the extended (generic table / filesystem code) scan failed and was skipped for this rescan: ' . $e->getMessage(),
                            Log::WARNING,
                            'jerror'
                        );

                        $this->saveLinks($state);
                        $this->finishLinking($state);
                    }

                    break;

                case 'classify':
                    $this->stepClassify($state);
                    break;

                case 'refs':
                    $this->stepRefs($state, $deadline);
                    break;

                case 'persist_files':
                    $this->stepPersistFiles($state, $deadline);
                    break;

                case 'persist_refs':
                    $this->stepPersistRefs($state, $deadline);
                    break;
            }

            $state['ms'][$phase] = ($state['ms'][$phase] ?? 0) + (int) round((microtime(true) - $started) * 1000);

            if ($state['phase'] === 'done') {
                $this->writeFinishedLog($state);
                $this->cleanup($state['id']);

                break;
            }

            // Written after every completed unit of work, so a request
            // that dies later on only loses the unit it was in.
            $this->saveState($state);
        } while ($deadline === null || microtime(true) < $deadline);

        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $this->status($state);
    }

    /**
     * Whether a scan was cut off while it was already writing the
     * overview tables - the one situation in which the overview on
     * screen is incomplete rather than just old.
     *
     * @return  boolean
     */
    public function hasInterruptedPersist()
    {
        try {
            $dir = $this->getWorkDir();
        } catch (\Exception $e) {
            return false;
        }

        foreach ((array) glob($dir . '/mediacleaner_scan_*.state') as $file) {
            $state = @unserialize((string) @file_get_contents($file), ['allowed_classes' => false]);

            if (\is_array($state) && \in_array($state['phase'] ?? '', ['persist_files', 'persist_refs'], true) && ($state['pf'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Phases
    // ------------------------------------------------------------------

    /**
     * Phase 'walk': append the media files found to the "items" work
     * file - one "D" line per folder, followed by an "F" line per file
     * in it.
     *
     * @param   array       &$state
     * @param   float|null  $deadline
     *
     * @return  void
     */
    protected function stepWalk(array &$state, $deadline)
    {
        $handle = $this->openForAppend($this->path($state['id'], 'items'), $state['itemsBytes']);
        $count  = 0;

        $done = $this->walkFilesystem(
            $state['walk'],
            $deadline,
            function ($relDir) use ($handle) {
                fwrite($handle, "D\t" . $this->encode($relDir) . "\n");
            },
            function ($name, $full, $size, $mtime, $extension) use ($handle, &$count) {
                $type      = $extension === 'tiff' ? 'tif' : $extension;
                $noPreview = $type === 'svg' && $this->svgHasNoVisualContent($full, $size);

                fwrite($handle, "F\t" . $this->encode($name) . "\t" . $size . "\t" . ($mtime ?? '') . "\t" . ($noPreview ? 1 : 0) . "\t" . $type . "\n");
                $count++;
            }
        );

        $state['itemsBytes'] = $this->closeAppended($handle);
        $state['count']     += $count;

        if ($done) {
            $state['phase'] = 'curated';
        }
    }

    /**
     * Phase 'curated': Joomla's own content tables.
     *
     * @param   array       &$state
     * @param   float|null  $deadline
     *
     * @return  void
     */
    protected function stepCurated(array &$state, $deadline)
    {
        $this->loadItems($state);

        $done = $this->sweepCuratedContent($this->items, $this->index, $state['cur'], $deadline);

        $this->saveLinks($state);

        if ($done) {
            $state['phase'] = 'generic';
        }
    }

    /**
     * Phase 'generic': every other table, within a total time budget -
     * generous when the scan runs in steps, the original 20 seconds when
     * everything has to fit in one request.
     *
     * @param   array       &$state
     * @param   float|null  $deadline
     *
     * @return  void
     */
    protected function stepGeneric(array &$state, $deadline)
    {
        $this->loadItems($state);

        $budget = $deadline === null ? $this->haystackMaxSeconds : $this->genericSteppedMaxSeconds;
        $done   = $this->sweepGenericTables($this->items, $this->index, $state['gen'], $deadline, $budget);

        $this->saveLinks($state);

        if ($done) {
            $state['phase'] = 'code';
        }
    }

    /**
     * Phase 'code': one of $codeScanDirs per call, each with its own
     * time cap.
     *
     * @param   array       &$state
     * @param   float|null  $deadline
     *
     * @return  void
     */
    protected function stepCode(array &$state, $deadline)
    {
        $this->loadItems($state);

        if ($state['code']['d'] < \count($this->codeScanDirs)) {
            $this->sweepCodeDirectory(
                $this->items,
                $this->index,
                $this->codeScanDirs[$state['code']['d']],
                microtime(true) + $this->codeScanMaxSeconds,
                $state['code']['stats']
            );

            $state['code']['d']++;

            $this->saveLinks($state);
        }

        if ($state['code']['d'] >= \count($this->codeScanDirs)) {
            $this->finishLinking($state);
        }
    }

    /**
     * End of the three linking phases: write the diagnostic summary and
     * move on.
     *
     * @param   array  &$state
     *
     * @return  void
     */
    protected function finishLinking(array &$state)
    {
        $this->loadItems($state);

        $this->writeScanDebugLog(
            [
                'curated_ms' => $state['ms']['curated'] ?? 0,
                'generic_ms' => $state['ms']['generic'] ?? 0,
                'code_ms'    => $state['ms']['code'] ?? 0,
                'generic'    => !empty($state['gen']['stats']) ? $state['gen']['stats'] : null,
                'code'       => $state['code']['stats'],
                'exception'  => $state['exception'],
            ],
            $this->items
        );

        $state['phase'] = 'classify';
    }

    /**
     * Phase 'classify': everything that is decided per file once linking
     * is complete, in the same order as always - then the complete
     * result is written to the "final" work file, from which the two
     * persist phases (and the reference search) read.
     *
     * @param   array  &$state
     *
     * @return  void
     */
    protected function stepClassify(array &$state)
    {
        $this->loadItems($state);

        $items = $this->items;

        $this->items = null;
        $this->index = null;

        $items = $this->applyManualUploadOutlierDetection($items);

        // Must run after linking (needs each original's own 'linked'
        // result) and before markIgnoredStatus()/applyAutoIgnoreThumbs()
        // (a derivative of a linked original is linked itself, so it
        // must never be auto-ignored).
        $items = $this->linkDerivedFilesEnabled()
            ? $this->applyIdNamedCacheLinking($this->applyDerivedFileLinking($items))
            : $this->withEmptyDerivedInfo($items);

        $items = $this->markIgnoredStatus($items);
        $items = $this->applyAutoIgnoreThumbs($items);
        $items = $this->markActiveExtensionAssetStatus($items);
        $items = $this->applyExtensionFolderSegmentOverride($items, ['images'], false);
        $items = $this->applyExtensionFolderSegmentOverride($items, ['canvas', 'fonts'], true);
        $items = $this->applyActiveExtensionFilenameOverride($items);

        // The "links" work file is deliberately left in place until the
        // whole scan is cleaned up: should this step ever have to be
        // repeated, it needs it again.
        $this->writeFinalFile($state, $items);

        $state['phase'] = 'refs';
    }

    /**
     * Phase 'refs': "gebruikt in" references, appended to the "refs"
     * work file as they are found.
     *
     * @param   array       &$state
     * @param   float|null  $deadline
     *
     * @return  void
     */
    protected function stepRefs(array &$state, $deadline)
    {
        // Derived files (v2.8.0) are linked through their original,
        // never referenced directly anywhere - searching for them would
        // only cost time and find nothing. The overview shows their
        // original instead.
        $linkedItems = [];
        $nameIndex   = [];

        $this->readFinalFile($state, 0, function (array $row, $path) use (&$linkedItems, &$nameIndex) {
            if ($row['linked'] !== '1' || $row['linkConfidence'] === 'derived') {
                return true;
            }

            $item       = new ScanItem();
            $item->name = $row['name'];
            $item->path = $path;

            $this->addToLookupIndex($nameIndex, strtolower($item->name), \count($linkedItems));

            $linkedItems[] = $item;

            return true;
        });

        $done = true;

        if (!empty($linkedItems)) {
            $handle = $this->openForAppend($this->path($state['id'], 'refs'), $state['refsBytes']);

            $done = $this->collectReferences(
                $linkedItems,
                $nameIndex,
                $state['refs'],
                $deadline,
                function ($relative, $type, $title, $url) use ($handle) {
                    fwrite($handle, $this->encode($relative) . "\t" . $this->encode($type) . "\t" . $this->encode($title) . "\t" . $this->encode((string) $url) . "\n");
                }
            );

            $state['refsBytes'] = $this->closeAppended($handle);
        }

        if ($done) {
            $state['phase'] = 'persist_files';
        }
    }

    /**
     * Phase 'persist_files': replace the contents of
     * `#__mediacleaner_files`, 200 rows per INSERT, straight from the
     * "final" work file - so this phase needs hardly any memory.
     *
     * @param   array       &$state
     * @param   float|null  $deadline
     *
     * @return  void
     */
    protected function stepPersistFiles(array &$state, $deadline)
    {
        $db = $this->db;

        if ($state['pf'] === null) {
            $db->truncateTable('#__mediacleaner_files');

            $state['pf'] = ['offset' => 0, 'path' => '/', 'maxId' => 0];

            $this->saveState($state);
        } else {
            // Rows a cut-off earlier attempt at this step may have
            // written beyond the last recorded position.
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__mediacleaner_files'))
                    ->where($db->quoteName('id') . ' > ' . (int) $state['pf']['maxId'])
            )->execute();
        }

        $columns = ['name', 'path', 'size', 'type', 'url', 'linked', 'link_confidence', 'ignored', 'no_preview', 'is_thumbs_dir', 'is_active_extension_asset', 'is_active_extension_images_dir', 'possibly_orphaned_extension', 'images_dir_extension_name', 'images_dir_extension_removed', 'asset_dir_extension_name', 'asset_dir_extension_removed', 'file_modified_at', 'is_system_asset_dir', 'likely_manual_upload', 'derived_from', 'derived_status', 'scanned_at'];

        $urlRoot = rtrim(Uri::root(), '/');
        $now     = $db->quote($state['now']);
        $batch   = [];
        $paused  = false;

        $flag = static function ($value) {
            return $value === '1' ? 1 : 0;
        };

        $nullable = static function ($value) use ($db) {
            return $value !== '' ? $db->quote($value) : 'NULL';
        };

        $end = $this->readFinalFile(
            $state,
            $state['pf']['offset'],
            function (array $row, $path, $offsetAfter) use (&$batch, &$state, &$paused, $db, $columns, $urlRoot, $now, $flag, $nullable, $deadline) {
                $relative = ltrim(trim($path, '/') . '/' . $row['name'], '/');

                $batch[] = implode(',', [
                    $db->quote($row['name']),
                    $db->quote($path),
                    (int) $row['size'],
                    $db->quote($row['type']),
                    $db->quote($urlRoot . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)))),
                    $flag($row['linked']),
                    $db->quote($row['linkConfidence'] !== '' ? $row['linkConfidence'] : 'none'),
                    $flag($row['ignored']),
                    $flag($row['noPreview']),
                    $flag($row['isThumbsDir']),
                    $flag($row['isActiveExtensionAsset']),
                    $flag($row['isActiveExtensionImagesDir']),
                    $flag($row['possiblyOrphanedExtension']),
                    $nullable($row['imagesDirExtensionName']),
                    $flag($row['imagesDirExtensionRemoved']),
                    $nullable($row['assetDirExtensionName']),
                    $flag($row['assetDirExtensionRemoved']),
                    $row['mtime'] !== '' ? $db->quote(date('Y-m-d H:i:s', (int) $row['mtime'])) : 'NULL',
                    $flag($row['isSystemAssetDir']),
                    $flag($row['likelyManualUpload']),
                    $nullable($row['derivedFrom']),
                    $nullable($row['derivedStatus']),
                    $now,
                ]);

                if (\count($batch) < 200) {
                    return true;
                }

                $this->insertBatch('#__mediacleaner_files', $columns, $batch, $state);

                $batch                 = [];
                $state['pf']['offset'] = $offsetAfter;
                $state['pf']['path']   = $path;

                if ($deadline !== null && microtime(true) >= $deadline) {
                    $paused = true;

                    return false;
                }

                return true;
            },
            $state['pf']['path']
        );

        if (!$paused) {
            $this->insertBatch('#__mediacleaner_files', $columns, $batch, $state);

            $state['pf']['offset'] = $end;
            $state['phase']        = 'persist_refs';
        }

        $db->setQuery(
            $db->getQuery(true)->select('MAX(' . $db->quoteName('id') . ')')->from($db->quoteName('#__mediacleaner_files'))
        );

        $state['pf']['maxId'] = (int) $db->loadResult();
    }

    /**
     * Phase 'persist_refs': replace the contents of
     * `#__mediacleaner_references`.
     *
     * @param   array       &$state
     * @param   float|null  $deadline
     *
     * @return  void
     */
    protected function stepPersistRefs(array &$state, $deadline)
    {
        $db = $this->db;

        if ($state['pr'] === null) {
            $db->truncateTable('#__mediacleaner_references');

            $state['pr'] = ['i' => 0, 'maxId' => 0];

            $this->saveState($state);
        } else {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__mediacleaner_references'))
                    ->where($db->quoteName('id') . ' > ' . (int) $state['pr']['maxId'])
            )->execute();
        }

        // De-duplicate identical references per file (e.g. a match found
        // in both the intro and the body of the same article). The file
        // is read the same way on every call, so position $state['pr']['i']
        // always means the same row.
        $rows = [];
        $file = $this->path($state['id'], 'refs');

        if ($state['refsBytes'] > 0 && is_file($file)) {
            $handle = fopen($file, 'rb');
            $seen   = [];

            while (ftell($handle) < $state['refsBytes'] && ($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\n");

                if (isset($seen[$line])) {
                    continue;
                }

                $seen[$line] = true;
                $rows[]      = $line;
            }

            fclose($handle);
            unset($seen);
        }

        $columns = ['relative_path', 'source_type', 'title', 'edit_url'];
        $total   = \count($rows);
        $paused  = false;

        while ($state['pr']['i'] < $total) {
            $batch = [];

            foreach (\array_slice($rows, $state['pr']['i'], 200) as $line) {
                $parts = array_map([$this, 'decode'], explode("\t", $line));

                $batch[] = implode(',', [
                    $db->quote($parts[0] ?? ''),
                    $db->quote($parts[1] ?? ''),
                    $db->quote($parts[2] ?? ''),
                    ($parts[3] ?? '') !== '' ? $db->quote($parts[3]) : 'NULL',
                ]);
            }

            $this->insertBatch('#__mediacleaner_references', $columns, $batch, $state);

            $state['pr']['i'] += \count($batch);

            if ($deadline !== null && microtime(true) >= $deadline && $state['pr']['i'] < $total) {
                $paused = true;
                break;
            }
        }

        if (!$paused) {
            $state['phase'] = 'done';

            return;
        }

        $db->setQuery(
            $db->getQuery(true)->select('MAX(' . $db->quoteName('id') . ')')->from($db->quoteName('#__mediacleaner_references'))
        );

        $state['pr']['maxId'] = (int) $db->loadResult();
    }

    // ------------------------------------------------------------------
    // Work files
    // ------------------------------------------------------------------

    /**
     * Load the scanned files from the "items" work file, re-apply the
     * links found so far, and build the lookup index. Done at most once
     * per request.
     *
     * @param   array  $state
     *
     * @return  void
     */
    protected function loadItems(array $state)
    {
        if ($this->items !== null) {
            return;
        }

        ScanItem::$urlRoot = rtrim(Uri::root(), '/');

        $items = [];
        $file  = $this->path($state['id'], 'items');

        if ($state['itemsBytes'] > 0 && is_file($file)) {
            $handle = fopen($file, 'rb');

            // All files in a folder share one and the same 'path' string
            // instead of each carrying its own copy.
            $path             = '/';
            $isThumbsDir      = false;
            $isSystemAssetDir = false;

            while (ftell($handle) < $state['itemsBytes'] && ($line = fgets($handle)) !== false) {
                $parts = explode("\t", rtrim($line, "\n"));

                if ($parts[0] === 'D') {
                    $relDir           = $this->decode($parts[1] ?? '');
                    $path             = $relDir === '' ? '/' : '/' . $relDir;
                    $isThumbsDir      = $this->pathHasThumbsSegment($relDir);
                    $isSystemAssetDir = $this->pathHasSystemAssetSegment($relDir);

                    continue;
                }

                if ($parts[0] !== 'F' || \count($parts) < 6) {
                    continue;
                }

                $item = new ScanItem();

                $item->name             = $this->decode($parts[1]);
                $item->path             = $path;
                $item->size             = (int) $parts[2];
                $item->mtime            = $parts[3] === '' ? null : (int) $parts[3];
                $item->noPreview        = $parts[4] === '1';
                $item->type             = $parts[5];
                $item->isThumbsDir      = $isThumbsDir;
                $item->isSystemAssetDir = $isSystemAssetDir;

                $items[] = $item;
            }

            fclose($handle);
        }

        $linksFile = $this->path($state['id'], 'links');

        if (is_file($linksFile)) {
            $links = @unserialize((string) file_get_contents($linksFile), ['allowed_classes' => false]);

            foreach ((array) $links as $idx => $confidence) {
                if (isset($items[$idx])) {
                    $items[$idx]->linked         = true;
                    $items[$idx]->linkConfidence = $confidence === 1 ? 'confirmed' : 'probable';
                }
            }
        }

        $this->items = $items;
        $this->index = $this->buildFileLookupIndex($items);
    }

    /**
     * Remember which files have been found linked so far, so the next
     * request can carry on from there.
     *
     * @param   array  $state
     *
     * @return  void
     */
    protected function saveLinks(array $state)
    {
        if ($this->items === null) {
            return;
        }

        $links = [];

        foreach ($this->items as $idx => $item) {
            if ($item->linked) {
                $links[$idx] = $item->linkConfidence === 'confirmed' ? 1 : 2;
            }
        }

        $this->writeAtomically($this->path($state['id'], 'links'), serialize($links));
    }

    /**
     * Write every item, fully classified, to the "final" work file: a
     * "D" line with the stored path whenever the folder changes, then
     * an "F" line per file with the FINAL_FIELDS columns.
     *
     * @param   array       &$state
     * @param   ScanItem[]  $items
     *
     * @return  void
     */
    protected function writeFinalFile(array &$state, array $items)
    {
        $file   = $this->path($state['id'], 'final');
        $handle = $this->openForAppend($file, 0);
        $path   = null;
        $total  = 0;

        foreach ($items as $item) {
            if ($item->path !== $path) {
                $path = $item->path;

                fwrite($handle, "D\t" . $this->encode($path) . "\n");
            }

            $total += $item->size;

            fwrite($handle, "F\t" . implode("\t", [
                $this->encode($item->name),
                (int) $item->size,
                $item->type,
                $item->mtime ?? '',
                $item->linked ? 1 : 0,
                $item->linkConfidence,
                $item->ignored ? 1 : 0,
                $item->noPreview ? 1 : 0,
                $item->isThumbsDir ? 1 : 0,
                $item->isActiveExtensionAsset ? 1 : 0,
                $item->isActiveExtensionImagesDir ? 1 : 0,
                $item->possiblyOrphanedExtension ? 1 : 0,
                $this->encode((string) $item->imagesDirExtensionName),
                $item->imagesDirExtensionRemoved ? 1 : 0,
                $this->encode((string) $item->assetDirExtensionName),
                $item->assetDirExtensionRemoved ? 1 : 0,
                $item->isSystemAssetDir ? 1 : 0,
                $item->likelyManualUpload ? 1 : 0,
                $this->encode((string) $item->derivedFrom),
                (string) $item->derivedStatus,
            ]) . "\n");
        }

        $state['finalBytes'] = $this->closeAppended($handle);
        $state['totalSize']  = $total;
        $state['count']      = \count($items);
    }

    /**
     * Read the "final" work file from $offset, handing each file to
     * $callback until it returns false.
     *
     * @param   array     $state
     * @param   integer   $offset    Byte offset of a line start.
     * @param   callable  $callback  function (array $row, string $path, int $offsetAfterThisLine): bool
     * @param   string    $path      The folder in effect at $offset.
     *
     * @return  integer  Byte offset where reading stopped.
     */
    protected function readFinalFile(array $state, $offset, callable $callback, $path = '/')
    {
        $file = $this->path($state['id'], 'final');

        if ($state['finalBytes'] <= 0 || !is_file($file)) {
            return 0;
        }

        $handle = fopen($file, 'rb');
        $fields = self::FINAL_FIELDS;
        $count  = \count($fields);

        fseek($handle, $offset);

        while (ftell($handle) < $state['finalBytes'] && ($line = fgets($handle)) !== false) {
            $parts = explode("\t", rtrim($line, "\n"));

            if ($parts[0] === 'D') {
                $path = $this->decode($parts[1] ?? '/');

                continue;
            }

            if ($parts[0] !== 'F' || \count($parts) < $count + 1) {
                continue;
            }

            $row = array_combine($fields, \array_slice($parts, 1, $count));

            foreach (['name', 'imagesDirExtensionName', 'assetDirExtensionName', 'derivedFrom'] as $field) {
                $row[$field] = $this->decode($row[$field]);
            }

            if ($callback($row, $path, ftell($handle)) === false) {
                break;
            }
        }

        $position = ftell($handle);

        fclose($handle);

        return $position;
    }

    /**
     * One multi-row INSERT; if the database rejects it (one file name
     * it cannot store is enough), the rows are retried one at a time so
     * that only the offending ones are left out.
     *
     * @param   string  $table
     * @param   array   $columns
     * @param   array   $batch    Ready-made VALUES tuples.
     * @param   array   &$state
     *
     * @return  void
     */
    protected function insertBatch($table, array $columns, array $batch, array &$state)
    {
        if (empty($batch)) {
            return;
        }

        $db = $this->db;

        $build = static function (array $tuples) use ($db, $table, $columns) {
            $query = $db->getQuery(true)->insert($db->quoteName($table))->columns($db->quoteName($columns));

            foreach ($tuples as $tuple) {
                $query->values($tuple);
            }

            return $query;
        };

        try {
            $db->setQuery($build($batch))->execute();

            return;
        } catch (\Exception $e) {
            $reason = $e->getMessage();
        }

        foreach ($batch as $tuple) {
            try {
                $db->setQuery($build([$tuple]))->execute();
            } catch (\Exception $e) {
                $state['skipped']++;
                $reason = $e->getMessage();
            }
        }

        if ($state['skipped'] > 0) {
            Log::add('Media Cleaner: ' . $state['skipped'] . ' row(s) could not be stored in ' . $table . ' so far (' . $reason . ').', Log::WARNING, 'jerror');
        }
    }

    /**
     * Folder for the work files: Joomla's configured tmp folder, with a
     * few fallbacks for hosts where that one isn't writable.
     *
     * @return  string
     *
     * @throws  \RuntimeException
     */
    protected function getWorkDir()
    {
        if ($this->workDir !== null) {
            return $this->workDir;
        }

        $candidates = [];

        try {
            $candidates[] = (string) Factory::getApplication()->get('tmp_path', '');
        } catch (\Throwable $e) {
            // No application available - fall through to the defaults.
        }

        $candidates[] = JPATH_ROOT . '/tmp';
        $candidates[] = JPATH_ADMINISTRATOR . '/cache';
        $candidates[] = sys_get_temp_dir();

        foreach ($candidates as $candidate) {
            $candidate = rtrim($candidate, '/\\');

            if ($candidate !== '' && @is_dir($candidate) && @is_writable($candidate)) {
                return $this->workDir = $candidate;
            }
        }

        throw new \RuntimeException(Text::_('COM_MEDIACLEANER_SCAN_NO_WORKDIR'));
    }

    /**
     * @param   string  $id
     * @param   string  $kind  'state', 'items', 'links', 'final' or 'refs'.
     *
     * @return  string
     */
    protected function path($id, $kind)
    {
        return $this->getWorkDir() . '/mediacleaner_scan_' . $id . '.' . $kind;
    }

    /**
     * @param   string  $scanId
     *
     * @return  array
     *
     * @throws  \RuntimeException
     */
    protected function loadState($scanId)
    {
        $scanId = (string) $scanId;

        if (preg_match('~^[a-f0-9]{32}$~', $scanId)) {
            $file = $this->path($scanId, 'state');

            if (is_file($file)) {
                $state = @unserialize((string) file_get_contents($file), ['allowed_classes' => false]);

                if (\is_array($state) && ($state['id'] ?? '') === $scanId && ($state['v'] ?? 0) === 1) {
                    return $state;
                }
            }
        }

        throw new \RuntimeException(Text::_('COM_MEDIACLEANER_SCAN_STATE_LOST'));
    }

    /**
     * @param   array  $state
     *
     * @return  void
     */
    protected function saveState(array $state)
    {
        $this->writeAtomically($this->path($state['id'], 'state'), serialize($state));
    }

    /**
     * Write via a temporary file and a rename, so a request that is cut
     * off mid-write can never leave a half-written file behind.
     *
     * @param   string  $file
     * @param   string  $contents
     *
     * @return  void
     *
     * @throws  \RuntimeException
     */
    protected function writeAtomically($file, $contents)
    {
        $temporary = $file . '.tmp';

        if (@file_put_contents($temporary, $contents) !== \strlen($contents) || !@rename($temporary, $file)) {
            @unlink($temporary);

            throw new \RuntimeException(Text::sprintf('COM_MEDIACLEANER_SCAN_WRITE_FAILED', $this->getWorkDir()));
        }
    }

    /**
     * Open a work file for appending at exactly $length bytes - anything
     * beyond that was written by a step that never got to record it, and
     * is discarded.
     *
     * @param   string   $file
     * @param   integer  $length
     *
     * @return  resource
     *
     * @throws  \RuntimeException
     */
    protected function openForAppend($file, $length)
    {
        $handle = @fopen($file, 'c+b');

        if ($handle === false || !ftruncate($handle, (int) $length) || fseek($handle, 0, SEEK_END) !== 0) {
            throw new \RuntimeException(Text::sprintf('COM_MEDIACLEANER_SCAN_WRITE_FAILED', $this->getWorkDir()));
        }

        return $handle;
    }

    /**
     * @param   resource  $handle
     *
     * @return  integer  Size of the file in bytes.
     *
     * @throws  \RuntimeException
     */
    protected function closeAppended($handle)
    {
        $flushed = fflush($handle);
        $length  = ftell($handle);

        fclose($handle);

        if (!$flushed || $length === false) {
            throw new \RuntimeException(Text::sprintf('COM_MEDIACLEANER_SCAN_WRITE_FAILED', $this->getWorkDir()));
        }

        return (int) $length;
    }

    /**
     * Work-file fields are tab-separated, one record per line; a name
     * can in principle contain either, so those (and the backslash) are
     * escaped. Byte-safe - file names on disk aren't always valid UTF-8.
     *
     * @param   string  $value
     *
     * @return  string
     */
    protected function encode($value)
    {
        return addcslashes((string) $value, "\\\t\n\r");
    }

    /**
     * @param   string  $value
     *
     * @return  string
     */
    protected function decode($value)
    {
        return strpos($value, '\\') === false ? $value : strtr($value, ['\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\r' => "\r"]);
    }

    /**
     * @param   string  $id
     *
     * @return  void
     */
    protected function cleanup($id)
    {
        foreach (['state', 'items', 'links', 'final', 'refs', 'lock'] as $kind) {
            @unlink($this->path($id, $kind));
            @unlink($this->path($id, $kind) . '.tmp');
        }
    }

    // ------------------------------------------------------------------
    // Reporting
    // ------------------------------------------------------------------

    /**
     * Where the scan stands, for the progress bar.
     *
     * @param   array  $state
     *
     * @return  array
     */
    protected function status(array $state)
    {
        $phase   = $state['phase'];
        $count   = (int) $state['count'];
        $percent = 0;
        $label   = '';

        switch ($phase) {
            case 'walk':
                // The total isn't known until the walk is over, so this
                // part of the bar approaches its end rather than
                // counting up to it.
                $percent = 2 + (int) round(18 * (1 - exp(-$count / 40000)));
                $label   = Text::sprintf('COM_MEDIACLEANER_SCAN_PHASE_WALK', $count);
                break;

            case 'curated':
                $percent = 20 + (int) round(20 * $state['cur']['t'] / max(1, \count($this->curatedSources)));
                $label   = Text::sprintf('COM_MEDIACLEANER_SCAN_PHASE_CURATED', $count);
                break;

            case 'generic':
                $tables  = \count($state['gen']['tables'] ?? []);
                $percent = 40 + (int) round(15 * ($state['gen']['i'] ?? 0) / max(1, $tables));
                $label   = Text::sprintf('COM_MEDIACLEANER_SCAN_PHASE_GENERIC', min($tables, ($state['gen']['i'] ?? 0) + 1), $tables);
                break;

            case 'code':
                $percent = 55 + (int) round(5 * $state['code']['d'] / max(1, \count($this->codeScanDirs)));
                $label   = Text::_('COM_MEDIACLEANER_SCAN_PHASE_CODE');
                break;

            case 'classify':
                $percent = 61;
                $label   = Text::sprintf('COM_MEDIACLEANER_SCAN_PHASE_CLASSIFY', $count);
                break;

            case 'refs':
                $percent = 65 + (int) round(15 * $state['refs']['s'] / (\count($this->getReferenceSources()) + 1));
                $label   = Text::_('COM_MEDIACLEANER_SCAN_PHASE_REFS');
                break;

            case 'persist_files':
                $fraction = ($state['pf']['offset'] ?? 0) / max(1, $state['finalBytes']);
                $percent  = 80 + (int) round(17 * $fraction);
                $label    = Text::sprintf('COM_MEDIACLEANER_SCAN_PHASE_PERSIST', (int) round(100 * $fraction));
                break;

            case 'persist_refs':
                $percent = 98;
                $label   = Text::sprintf('COM_MEDIACLEANER_SCAN_PHASE_PERSIST', 100);
                break;

            case 'done':
                $percent = 100;
                $label   = Text::_('COM_MEDIACLEANER_SCAN_PHASE_DONE');
                break;
        }

        return [
            'done'    => $phase === 'done',
            'scanId'  => $state['id'],
            'phase'   => $phase,
            'percent' => $percent,
            'label'   => $label,
            'count'   => $count,
            'sizeKB'  => $state['totalSize'] / 1024,
        ];
    }

    /**
     * One more line in the scan debug log once everything is stored:
     * how long each phase took and how many requests it was spread over.
     *
     * @param   array  $state
     *
     * @return  void
     */
    protected function writeFinishedLog(array $state)
    {
        $parts = [];

        foreach ($state['ms'] as $phase => $ms) {
            $parts[] = $phase . '=' . $ms . 'ms';
        }

        $line = '[' . date('Y-m-d H:i:s') . '] scan stored: ' . $state['count'] . ' files in '
            . number_format(microtime(true) - $state['started'], 1, '.', '') . ' s over ' . $state['requests'] . ' request(s); '
            . implode(', ', $parts)
            . ($state['skipped'] > 0 ? '; rows not stored: ' . $state['skipped'] : '')
            . '; memory peak (last request) ' . number_format(memory_get_peak_usage() / 1048576, 1, '.', '') . " MB\n\n";

        @file_put_contents(JPATH_ADMINISTRATOR . '/logs/mediacleaner_scan_debug.log', $line, FILE_APPEND);
    }
}
