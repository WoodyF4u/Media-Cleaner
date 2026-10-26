<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * Runs one "Verkleinen" round as a series of short, resumable steps
 * (v2.9.0) - same idea as ScanJob: the browser asks for one step at a
 * time, each request does a few seconds of work and reports progress, so
 * no request runs into the server's time limit however many files there
 * are.
 *
 * Phases, in order:
 *   code      only when files may get a new name (PNG -> JPG, -> WebP):
 *             look through template / extension source code for their
 *             file names. A file mentioned in code keeps its name - code
 *             is never rewritten - so a JPG is then made smaller under
 *             its own name instead, and a PNG is skipped.
 *   files     make the files smaller (Compressor). In-place results are
 *             final; converted files sit next to their original.
 *   refs      point database references at the converted files
 *             (ReferenceRewriter).
 *   finalize  move the originals of converted files to the originals
 *             folder, ask for a fresh scan.
 *
 * State lives in Joomla's tmp folder between requests, named after an
 * unguessable id; every step is safe to repeat.
 */
class CompressJob
{
    /**
     * Top-level Joomla folders holding code and the files that come with
     * it; nothing in there is ever made smaller.
     */
    public const CODE_FOLDERS = [
        'administrator', 'api', 'cache', 'cli', 'components', 'includes', 'installation',
        'language', 'layouts', 'libraries', 'media', 'modules', 'plugins', 'templates', 'tmp',
    ];

    /**
     * A round whose state file was touched less than this many seconds
     * ago counts as still running (another tab, another administrator).
     */
    public const RUNNING_WINDOW = 600;

    /**
     * @var \Joomla\Database\DatabaseDriver
     */
    protected $db;

    /**
     * @var string|null
     */
    protected $workDir = null;

    /**
     * Folders (relative to JPATH_ROOT) whose source files are searched
     * for file names before renaming - Scanner::$codeScanDirs plus the
     * template asset folder Joomla 4+ uses.
     *
     * @var string[]
     */
    protected $codeDirs = ['templates', 'media/templates', 'plugins', 'components', 'modules'];

    /**
     * @var string[]
     */
    protected $codeExtensions = ['php', 'css', 'js', 'xml', 'json', 'html', 'htm', 'less', 'scss', 'ini'];

    /**
     * @param   \Joomla\Database\DatabaseDriver  $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Settings from Opties, with the stand-alone script's values as
     * defaults (stored params never contain the XML defaults).
     *
     * @return  array
     */
    public static function getSettings()
    {
        $p = ComponentHelper::getParams('com_mediacleaner');

        return [
            'jpg_min_kb'      => max(1, (int) $p->get('compress_jpg_min_kb', 1024)),
            'png_min_kb'      => max(1, (int) $p->get('compress_png_min_kb', 500)),
            'pdf_min_kb'      => max(1, (int) $p->get('compress_pdf_min_kb', 500)),
            'max_pixels'      => max(200, (int) $p->get('compress_max_pixels', 2400)),
            'jpg_quality'     => min(100, max(30, (int) $p->get('compress_jpg_quality', 85))),
            'webp_quality'    => min(100, max(30, (int) $p->get('compress_webp_quality', 80))),
            'pdf_dpi'         => min(600, max(72, (int) $p->get('compress_pdf_dpi', 200))),
            'pdf_quality'     => min(100, max(30, (int) $p->get('compress_pdf_quality', 80))),
            'use_ghostscript' => (int) $p->get('compress_use_ghostscript', 1),
        ];
    }

    /**
     * The files a round with these choices would handle.
     *
     * @param   array    $choices     See Compressor::process().
     * @param   boolean  $onlyLinked
     * @param   boolean  $retry       Also files skipped before.
     *
     * @return  array  [['rel' => 'images/a.jpg', 'type' => 'jpg', 'size' => int], ...], largest first
     */
    public function getCandidates(array $choices, $onlyLinked, $retry = false)
    {
        $settings = self::getSettings();
        $db       = $this->db;
        $types    = [];

        if (($choices['jpg'] ?? 'skip') !== 'skip') {
            $types['jpg']  = $settings['jpg_min_kb'];
            $types['jpeg'] = $settings['jpg_min_kb'];
        }

        if (($choices['png'] ?? 'skip') !== 'skip' || ($choices['png_alpha'] ?? 'skip') !== 'skip') {
            $types['png'] = $settings['png_min_kb'];
        }

        if (($choices['pdf'] ?? 'skip') !== 'skip') {
            $types['pdf'] = $settings['pdf_min_kb'];
        }

        if (!$types) {
            return [];
        }

        $or = [];

        foreach ($types as $type => $minKb) {
            $or[] = '(' . $db->quoteName('type') . ' = ' . $db->quote($type) . ' AND ' . $db->quoteName('size') . ' >= ' . ((int) $minKb * 1024) . ')';
        }

        // Only the site's own media (images/ and any other folder of its
        // own, e.g. files/): never files an extension or template brought
        // along - an update would put the originals back anyway, and their
        // names live in code. System folders (thumbs, assets, vendor, ...)
        // are left alone for the same reason.
        $query = $db->getQuery(true)
            ->select($db->quoteName(['path', 'name', 'type', 'size']))
            ->from($db->quoteName('#__mediacleaner_files'))
            ->where('(' . implode(' OR ', $or) . ')')
            ->where($db->quoteName('ignored') . ' = 0')
            ->where($db->quoteName('is_system_asset_dir') . ' = 0')
            ->where($db->quoteName('is_active_extension_asset') . ' = 0')
            ->order($db->quoteName('size') . ' DESC');

        if ($onlyLinked) {
            $query->where($db->quoteName('linked') . ' = 1');
        }

        $rows = $db->setQuery($query)->loadAssocList();
        $seen = $this->getHandledPaths($retry);
        $out  = [];

        foreach ($rows as $row) {
            $rel = ltrim(trim($row['path'], '/') . '/' . $row['name'], '/');

            if (\in_array(strtolower(strtok($rel, '/')), self::CODE_FOLDERS, true)) {
                continue;
            }

            if (isset($seen['done'][$rel]) || (isset($seen['skipped'][$rel]) && $seen['skipped'][$rel] === (int) $row['size'])) {
                continue;
            }

            $out[] = ['rel' => $rel, 'type' => $row['type'] === 'jpeg' ? 'jpg' : $row['type'], 'size' => (int) $row['size']];
        }

        return $out;
    }

    /**
     * Paths already made smaller - also when the original was deleted
     * for good ('final') - never again, until restored; and
     * paths skipped before with the size they had then.
     *
     * @param   boolean  $retry
     *
     * @return  array  ['done' => [rel => true], 'skipped' => [rel => size]]
     */
    protected function getHandledPaths($retry)
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['relative_path', 'new_path', 'status', 'old_size']))
                ->from($db->quoteName('#__mediacleaner_compress'))
                ->where($db->quoteName('status') . " IN ('done','final','converted','skipped')")
        )->loadAssocList();

        $out = ['done' => [], 'skipped' => []];

        foreach ($rows as $row) {
            if ($row['status'] === 'skipped') {
                if (!$retry) {
                    $out['skipped'][$row['relative_path']] = (int) $row['old_size'];
                }

                continue;
            }

            $out['done'][$row['relative_path']] = true;

            if ($row['new_path'] !== '') {
                $out['done'][$row['new_path']] = true;
            }
        }

        return $out;
    }

    /**
     * The id of a round that is still under way (its state file was
     * touched in the last RUNNING_WINDOW seconds), or null.
     *
     * Two rounds at once would both pick up the same converted files, so
     * a new start joins that round instead. This is also what happens when
     * the browser retries a first request that took longer than the
     * connection allows: the round that request started is simply carried
     * on. Steps are locked per round, so two pages driving the same round
     * never do the same work twice.
     *
     * @return  string|null
     */
    public function findRunning()
    {
        $newest = null;
        $time   = time() - self::RUNNING_WINDOW;

        foreach ((array) glob($this->getWorkDir() . '/mediacleaner_compress_*.state') as $file) {
            if (!is_file($file) || ($mtime = filemtime($file)) <= $time) {
                continue;
            }

            if (preg_match('~mediacleaner_compress_([a-f0-9]{32})\.state$~', $file, $m)) {
                $time   = $mtime;
                $newest = $m[1];
            }
        }

        return $newest;
    }

    /**
     * Begin a round.
     *
     * @param   array    $choices
     * @param   boolean  $onlyLinked
     * @param   boolean  $retry
     *
     * @return  string  Job id.
     */
    public function start(array $choices, $onlyLinked, $retry)
    {
        $dir = $this->getWorkDir();

        foreach ((array) glob($dir . '/mediacleaner_compress_*') as $old) {
            if (is_file($old) && filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }

        $queue   = $this->getCandidates($choices, $onlyLinked, $retry);
        $renames = ($choices['jpg'] ?? '') === 'webp' || \in_array($choices['png'] ?? '', ['jpg', 'webp'], true) || ($choices['png_alpha'] ?? '') === 'webp';
        $id      = bin2hex(random_bytes(16));

        if ($retry) {
            // Forget earlier skips of the files in this round, so they
            // are not listed twice.
            $paths = array_column($queue, 'rel');

            foreach (array_chunk($paths, 200) as $chunk) {
                $this->db->setQuery(
                    $this->db->getQuery(true)
                        ->delete($this->db->quoteName('#__mediacleaner_compress'))
                        ->where($this->db->quoteName('status') . ' = ' . $this->db->quote('skipped'))
                        ->where($this->db->quoteName('relative_path') . ' IN (' . implode(',', array_map([$this->db, 'quote'], $chunk)) . ')')
                )->execute();
            }
        }

        // Files converted by an earlier round that was never finished
        // (page closed half-way): their references still point at the
        // original. This round picks them up in its refs/finalize phases.
        $this->db->setQuery(
            $this->db->getQuery(true)
                ->update($this->db->quoteName('#__mediacleaner_compress'))
                ->set($this->db->quoteName('job_id') . ' = ' . $this->db->quote($id))
                ->where($this->db->quoteName('status') . ' = ' . $this->db->quote('converted'))
        )->execute();

        $adopted = (int) $this->db->getAffectedRows();

        $this->saveState([
            'v'        => 1,
            'adopted'  => $adopted,
            'id'       => $id,
            'phase'    => $renames && $queue ? 'code' : 'files',
            'user'     => (int) Factory::getApplication()->getIdentity()->id,
            'choices'  => $choices,
            'settings' => self::getSettings(),
            'queue'    => $queue,
            'total'    => \count($queue),
            'pos'      => 0,
            'code'     => ['listed' => false, 'count' => 0, 'pos' => 0, 'hits' => []],
            'refs'     => ['t' => 0, 'last' => null, 'targets' => null, 'counts' => []],
            'stats'    => ['done' => 0, 'converted' => 0, 'skipped' => 0, 'saved' => 0],
            'current'  => '',
        ]);

        return $id;
    }

    /**
     * Do roughly $budget seconds of work.
     *
     * @param   string   $id
     * @param   integer  $budget
     *
     * @return  array  ['jobId', 'done', 'busy', 'percent', 'label', 'stats']
     */
    public function run($id, $budget)
    {
        $this->prepareEnvironment();

        $state = $this->loadState($id);
        $lock  = @fopen($this->path($id, 'lock'), 'c');

        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return ['busy' => true] + $this->status($state);
        }

        $state    = $this->loadState($id);
        $deadline = microtime(true) + max(1, (int) $budget);

        do {
            switch ($state['phase']) {
                case 'code':
                    $this->stepCode($state, $deadline);
                    break;

                case 'files':
                    $this->stepFiles($state, $deadline);
                    break;

                case 'refs':
                    $this->stepRefs($state, $deadline);
                    break;

                case 'finalize':
                    $this->stepFinalize($state);
                    break;
            }

            if ($state['phase'] === 'done') {
                break;
            }

            $this->saveState($state);
        } while (microtime(true) < $deadline);

        $status = $this->status($state);

        if ($state['phase'] === 'done') {
            $this->cleanup($id);
        } else {
            $this->saveState($state);
        }

        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $status;
    }

    // ------------------------------------------------------------------
    // Phases
    // ------------------------------------------------------------------

    /**
     * @param   array  &$state
     * @param   float  $deadline
     *
     * @return  void
     */
    protected function stepCode(array &$state, $deadline)
    {
        $listFile = $this->path($state['id'], 'codefiles');

        if (!$state['code']['listed']) {
            $files = [];

            foreach ($this->codeDirs as $dir) {
                $abs = JPATH_ROOT . '/' . $dir;

                if (!is_dir($abs)) {
                    continue;
                }

                try {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::LEAVES_ONLY,
                        \RecursiveIteratorIterator::CATCH_GET_CHILD
                    );

                    foreach ($iterator as $file) {
                        if (
                            $file->isFile()
                            && \in_array(strtolower($file->getExtension()), $this->codeExtensions, true)
                            && $file->getSize() > 0
                            && $file->getSize() <= 2097152
                        ) {
                            $files[] = $file->getPathname();
                        }
                    }
                } catch (\Throwable $e) {
                    // Unreadable folder: carry on with the rest.
                }
            }

            $this->writeAtomically($listFile, implode("\n", $files));
            $state['code'] = ['listed' => true, 'count' => \count($files), 'pos' => 0, 'hits' => []];

            return;
        }

        $names = [];

        foreach ($state['queue'] as $item) {
            $names[strtolower(basename($item['rel']))] = true;
        }

        $files   = $state['code']['count'] ? explode("\n", (string) @file_get_contents($listFile)) : [];
        $chunks  = array_chunk(array_keys($names), 200);
        $regexes = [];

        foreach ($chunks as $chunk) {
            $regexes[] = '~(?<![a-z0-9_.-])(' . implode('|', array_map(static function ($n) {
                return preg_quote($n, '~');
            }, $chunk)) . ')(?![a-z0-9_-])~';
        }

        while ($state['code']['pos'] < \count($files)) {
            $content = strtolower((string) @file_get_contents($files[$state['code']['pos']]));
            $content = str_replace(['%20', '\\/'], [' ', '/'], $content);

            foreach ($regexes as $regex) {
                if (preg_match_all($regex, $content, $m)) {
                    foreach ($m[1] as $hit) {
                        $state['code']['hits'][$hit] = true;
                    }
                }
            }

            $state['code']['pos']++;

            if (($state['code']['pos'] & 31) === 0 && microtime(true) >= $deadline) {
                return;
            }
        }

        @unlink($listFile);
        $state['phase'] = 'files';
    }

    /**
     * @param   array  &$state
     * @param   float  $deadline
     *
     * @return  void
     */
    protected function stepFiles(array &$state, $deadline)
    {
        $compressor = new Compressor($state['settings'], (new StorageLocator())->getOriginalsDir());
        $handled    = 0;

        while ($state['pos'] < \count($state['queue'])) {
            $item = $state['queue'][$state['pos']];

            // A PDF can take long: start it in a fresh request rather
            // than in what is left of this one.
            if ($handled > 0 && $item['type'] === 'pdf') {
                return;
            }

            $state['current'] = $item['rel'];
            $choices          = $state['choices'];
            $inCode           = isset($state['code']['hits'][strtolower(basename($item['rel']))]);

            if (($state['attempt'] ?? -1) === $state['pos']) {
                // The previous request started on this very file and never
                // came back: PHP was stopped while working on it (nearly
                // always memory). Trying again would stop it again.
                $result = [
                    'status' => 'skipped', 'action' => '', 'file_type' => $item['type'], 'new_path' => '', 'old_size' => $item['size'],
                    'new_size' => 0, 'old_px' => '', 'new_px' => '', 'info' => '', 'original_stored' => '',
                    'reason' => 'COM_MEDIACLEANER_COMPRESS_R_CRASHED|' . ini_get('memory_limit'),
                ];

                foreach (['', '.jpg', '.webp'] as $suffix) {
                    $base = $suffix === '' ? $item['rel'] : preg_replace('~\.[^./]+$~', $suffix, $item['rel']);
                    @unlink(JPATH_ROOT . '/' . $base . '.mediacleaner-tmp');
                }
            } elseif ($inCode && $item['type'] === 'png') {
                $result = [
                    'status' => 'skipped', 'action' => '', 'file_type' => 'png', 'new_path' => '', 'old_size' => $item['size'],
                    'new_size' => 0, 'old_px' => '', 'new_px' => '', 'info' => '', 'original_stored' => '',
                    'reason' => 'COM_MEDIACLEANER_COMPRESS_R_IN_CODE',
                ];
            } else {
                if ($inCode && $item['type'] === 'jpg' && ($choices['jpg'] ?? '') === 'webp') {
                    // Named in code: keep the name, only make it smaller.
                    $choices['jpg'] = 'resize';
                }

                // Written down BEFORE starting, so a request that dies on
                // this file leaves a trace the next request recognises.
                $state['attempt'] = $state['pos'];
                $this->saveState($state);

                $result = $compressor->process($item['rel'], $choices);

                if ($inCode && $result['status'] !== 'skipped') {
                    $result['info'] = trim($result['info'] . ' ' . Text::_('COM_MEDIACLEANER_COMPRESS_INFO_KEPT_NAME'));
                }
            }

            unset($state['attempt']);
            $state['pos']++;
            $handled++;

            // A file the person chose to leave alone is not worth a log line.
            if (strpos($result['reason'], 'COM_MEDIACLEANER_COMPRESS_R_CHOICE') !== 0) {
                $this->log($state, $item['rel'], $result);
            }

            if ($result['status'] === 'skipped') {
                $state['stats']['skipped']++;
            } else {
                $state['stats'][$result['status']]++;
                $state['stats']['saved'] += max(0, $result['old_size'] - $result['new_size']);
            }

            if ($item['type'] === 'pdf' || microtime(true) >= $deadline) {
                return;
            }
        }

        $state['current'] = '';
        $state['phase']   = $state['stats']['converted'] + $state['adopted'] > 0 ? 'refs' : 'finalize';
    }

    /**
     * @param   array  &$state
     * @param   float  $deadline
     *
     * @return  void
     */
    protected function stepRefs(array &$state, $deadline)
    {
        $rewriter = new ReferenceRewriter($this->db);

        if ($state['refs']['targets'] === null) {
            $state['refs']['targets'] = $rewriter->getTargets()['targets'];

            return;
        }

        $pairs = [];
        $rows  = $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName(['id', 'relative_path', 'new_path']))
                ->from($this->db->quoteName('#__mediacleaner_compress'))
                ->where($this->db->quoteName('job_id') . ' = ' . $this->db->quote($state['id']))
                ->where($this->db->quoteName('status') . ' = ' . $this->db->quote('converted'))
        )->loadAssocList();

        foreach ($rows as $row) {
            $pairs[] = ['id' => (int) $row['id'], 'from' => $row['relative_path'], 'to' => $row['new_path']];
        }

        $cursor = ['t' => $state['refs']['t'], 'last' => $state['refs']['last']];
        $counts = $state['refs']['counts'];
        $done   = $rewriter->rewriteStep($state['refs']['targets'], $pairs, $cursor, $deadline, $counts);

        $state['refs']['t']      = $cursor['t'];
        $state['refs']['last']   = $cursor['last'];
        $state['refs']['counts'] = $counts;

        if ($done) {
            $state['phase'] = 'finalize';
        }
    }

    /**
     * @param   array  &$state
     *
     * @return  void
     */
    protected function stepFinalize(array &$state)
    {
        $db         = $this->db;
        $compressor = new Compressor($state['settings'], (new StorageLocator())->getOriginalsDir());
        $rows       = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'relative_path']))
                ->from($db->quoteName('#__mediacleaner_compress'))
                ->where($db->quoteName('job_id') . ' = ' . $db->quote($state['id']))
                ->where($db->quoteName('status') . ' = ' . $db->quote('converted'))
        )->loadAssocList();

        foreach ($rows as $row) {
            $stored = $compressor->moveOriginal($row['relative_path']);
            $fields = [
                $db->quoteName('status') . ' = ' . $db->quote('done'),
                $db->quoteName('ref_count') . ' = ' . (int) ($state['refs']['counts'][$row['id']] ?? 0),
            ];

            if ($stored !== null) {
                $fields[] = $db->quoteName('original_stored') . ' = ' . $db->quote($stored);
            } else {
                $fields[] = $db->quoteName('info') . ' = CONCAT(' . $db->quoteName('info') . ', ' . $db->quote(' ' . Text::_('COM_MEDIACLEANER_COMPRESS_INFO_ORIGINAL_LEFT')) . ')';
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__mediacleaner_compress'))
                    ->set($fields)
                    ->where($db->quoteName('id') . ' = ' . (int) $row['id'])
            )->execute();
        }

        if ($state['stats']['done'] + $state['stats']['converted'] + $state['adopted'] > 0) {
            self::markNeedsRescan($db);
        }

        $state['phase'] = 'done';
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param   array   $state
     * @param   string  $relative
     * @param   array   $result
     *
     * @return  void
     */
    protected function log(array $state, $relative, array $result)
    {
        $record = (object) [
            'relative_path'   => $relative,
            'new_path'        => (string) $result['new_path'],
            'action'          => (string) $result['action'],
            'file_type'       => (string) $result['file_type'],
            'status'          => (string) $result['status'],
            'old_size'        => (int) $result['old_size'],
            'new_size'        => (int) $result['new_size'],
            'old_px'          => (string) $result['old_px'],
            'new_px'          => (string) $result['new_px'],
            'info'            => mb_substr((string) $result['info'], 0, 255),
            'reason'          => mb_substr((string) $result['reason'], 0, 512),
            'original_stored' => (string) $result['original_stored'],
            'ref_count'       => 0,
            'job_id'          => $state['id'],
            'created'         => Factory::getDate()->toSql(),
            'created_by'      => (int) $state['user'],
        ];

        $this->db->insertObject('#__mediacleaner_compress', $record);
    }

    /**
     * Same one-shot "rescan when the Opruimen tab opens next" marker that
     * script.php and QuarantineModel set.
     *
     * @param   \Joomla\Database\DatabaseDriver  $db
     *
     * @return  void
     */
    public static function markNeedsRescan($db)
    {
        try {
            $row = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['extension_id', 'params']))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('com_mediacleaner'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            )->loadAssoc();

            if (!$row) {
                return;
            }

            $params = json_decode((string) $row['params'], true);
            $params = \is_array($params) ? $params : [];

            $params['_needs_rescan_after_update'] = 1;

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                    ->where($db->quoteName('extension_id') . ' = ' . (int) $row['extension_id'])
            )->execute();
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    /**
     * @param   array  $state
     *
     * @return  array
     */
    protected function status(array $state)
    {
        $total = max(1, (int) $state['total']);

        switch ($state['phase']) {
            case 'code':
                $percent = 1 + (int) floor(4 * ($state['code']['count'] ? $state['code']['pos'] / $state['code']['count'] : 0));
                $label   = Text::_('COM_MEDIACLEANER_COMPRESS_PHASE_CODE');
                break;

            case 'files':
                $percent = 5 + (int) floor(80 * $state['pos'] / $total);
                $label   = Text::sprintf('COM_MEDIACLEANER_COMPRESS_PHASE_FILES', $state['pos'], $state['total'])
                    . ($state['current'] !== '' ? ' - ' . basename($state['current']) : '');
                break;

            case 'refs':
                $tables  = $state['refs']['targets'] === null ? 0 : \count($state['refs']['targets']);
                $percent = 85 + (int) floor(13 * ($tables ? $state['refs']['t'] / $tables : 0));
                $label   = Text::sprintf('COM_MEDIACLEANER_COMPRESS_PHASE_REFS', $state['refs']['t'], $tables);
                break;

            case 'finalize':
                $percent = 99;
                $label   = Text::_('COM_MEDIACLEANER_COMPRESS_PHASE_FINALIZE');
                break;

            default:
                $percent = 100;
                $label   = Text::_('COM_MEDIACLEANER_COMPRESS_PHASE_DONE');
        }

        return [
            'jobId'   => $state['id'],
            'done'    => $state['phase'] === 'done',
            'percent' => min(100, max(1, $percent)),
            'label'   => $label,
            'stats'   => $state['stats'],
        ];
    }

    /**
     * @return  void
     */
    protected function prepareEnvironment()
    {
        if (\function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $maxTime = (int) ini_get('max_execution_time');

        if ($maxTime > 0 && $maxTime < 300 && \function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $limit = Compressor::memoryLimit();

        if ($limit !== -1 && $limit < 536870912 && \function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }
    }

    /**
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
            // No application: defaults below.
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
     * @param   string  $kind
     *
     * @return  string
     */
    protected function path($id, $kind)
    {
        return $this->getWorkDir() . '/mediacleaner_compress_' . $id . '.' . $kind;
    }

    /**
     * @param   string  $id
     *
     * @return  array
     *
     * @throws  \RuntimeException
     */
    protected function loadState($id)
    {
        $id = (string) $id;

        if (preg_match('~^[a-f0-9]{32}$~', $id) && is_file($this->path($id, 'state'))) {
            $state = @unserialize((string) file_get_contents($this->path($id, 'state')), ['allowed_classes' => false]);

            if (\is_array($state) && ($state['id'] ?? '') === $id && ($state['v'] ?? 0) === 1) {
                return $state;
            }
        }

        throw new \RuntimeException(Text::_('COM_MEDIACLEANER_COMPRESS_STATE_LOST'));
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
     * @param   string  $id
     *
     * @return  void
     */
    protected function cleanup($id)
    {
        foreach (['state', 'codefiles', 'lock'] as $kind) {
            @unlink($this->path($id, $kind));
            @unlink($this->path($id, $kind) . '.tmp');
        }
    }
}
