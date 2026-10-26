<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Component\Mediacleaner\Administrator\Service\CompressJob;
use Joomla\Component\Mediacleaner\Administrator\Service\Compressor;
use Joomla\Component\Mediacleaner\Administrator\Service\ReferenceRewriter;
use Joomla\Component\Mediacleaner\Administrator\Service\StorageLocator;

/**
 * Everything behind the Verkleinen tab (v2.9.0) except the stepped round
 * itself (CompressJob): what can be made smaller, the log, restoring,
 * and the originals folder.
 */
class CompressModel extends BaseDatabaseModel
{
    /**
     * Allowed values per choice; the first is the default.
     */
    public const CHOICES = [
        'jpg'       => ['resize', 'webp', 'skip'],
        'png'       => ['jpg', 'webp', 'skip'],
        'png_alpha' => ['skip', 'webp'],
        'pdf'       => ['resize', 'skip'],
    ];

    /**
     * Maximum size of one ZIP part with originals.
     */
    public const ZIP_PART_BYTES = 157286400;

    /**
     * @return  void
     *
     * @throws  \RuntimeException  When the current user may not manage this component.
     */
    public function authorise()
    {
        if (!Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_mediacleaner')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Valid choices from a request (or the remembered ones).
     *
     * @param   array|null  $input
     *
     * @return  array
     */
    public function normaliseChoices($input)
    {
        $out = [];

        foreach (self::CHOICES as $key => $allowed) {
            $value     = \is_array($input) ? (string) ($input[$key] ?? '') : '';
            $out[$key] = \in_array($value, $allowed, true) ? $value : $allowed[0];
        }

        return $out;
    }

    /**
     * @return  array  ['choices' => array, 'only_linked' => bool]
     */
    public function getRememberedChoices()
    {
        $app = Factory::getApplication();

        return [
            'choices'     => $this->normaliseChoices($app->getUserState('com_mediacleaner.compress.choices')),
            'only_linked' => (bool) $app->getUserState('com_mediacleaner.compress.only_linked', true),
        ];
    }

    /**
     * @param   array    $choices
     * @param   boolean  $onlyLinked
     *
     * @return  void
     */
    public function rememberChoices(array $choices, $onlyLinked)
    {
        $app = Factory::getApplication();
        $app->setUserState('com_mediacleaner.compress.choices', $choices);
        $app->setUserState('com_mediacleaner.compress.only_linked', (bool) $onlyLinked);
    }

    /**
     * Whether a scan has ever been done.
     *
     * @return  boolean
     */
    public function hasScan()
    {
        $db = $this->getDatabase();

        try {
            return (int) $db->setQuery(
                $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__mediacleaner_files'))
            )->loadResult() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * What a round would handle, per kind, for the "Wat wordt verkleind"
     * table. Every kind is counted as if it were chosen, so the numbers
     * do not jump around when a choice is changed.
     *
     * @param   boolean  $onlyLinked
     *
     * @return  array  ['jpg' => ['count' => int, 'size' => int], 'png' => ..., 'pdf' => ...]
     */
    public function getCandidateSummary($onlyLinked)
    {
        $job   = new CompressJob($this->getDatabase());
        $all   = $job->getCandidates(['jpg' => 'resize', 'png' => 'jpg', 'png_alpha' => 'webp', 'pdf' => 'resize'], $onlyLinked);
        $out   = ['jpg' => ['count' => 0, 'size' => 0], 'png' => ['count' => 0, 'size' => 0], 'pdf' => ['count' => 0, 'size' => 0]];

        foreach ($all as $item) {
            if (isset($out[$item['type']])) {
                $out[$item['type']]['count']++;
                $out[$item['type']]['size'] += $item['size'];
            }
        }

        return $out;
    }

    /**
     * Totals over everything ever done on this tab.
     *
     * @return  array  done, saved (bytes), skipped, restored, originals (count), originalsSize (bytes)
     */
    public function getStats()
    {
        $db   = $this->getDatabase();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('status'),
                    'COUNT(*) AS ' . $db->quoteName('n'),
                    'SUM(GREATEST(CAST(' . $db->quoteName('old_size') . ' AS SIGNED) - CAST(' . $db->quoteName('new_size') . ' AS SIGNED), 0)) AS ' . $db->quoteName('saved'),
                    'SUM(CASE WHEN ' . $db->quoteName('original_stored') . " <> '' THEN 1 ELSE 0 END) AS " . $db->quoteName('originals'),
                    'SUM(CASE WHEN ' . $db->quoteName('original_stored') . " <> '' THEN " . $db->quoteName('old_size') . ' ELSE 0 END) AS ' . $db->quoteName('originals_size'),
                ])
                ->from($db->quoteName('#__mediacleaner_compress'))
                ->group($db->quoteName('status'))
        )->loadAssocList('status');

        $done = ($rows['done']['n'] ?? 0) + ($rows['final']['n'] ?? 0);

        return [
            'done'          => (int) $done,
            'saved'         => (int) (($rows['done']['saved'] ?? 0) + ($rows['final']['saved'] ?? 0)),
            'skipped'       => (int) ($rows['skipped']['n'] ?? 0),
            'restored'      => (int) ($rows['restored']['n'] ?? 0),
            'originals'     => (int) ($rows['done']['originals'] ?? 0),
            'originalsSize' => (int) ($rows['done']['originals_size'] ?? 0),
        ];
    }

    /**
     * @param   string   $filter  '', 'done', 'skipped', 'restored'
     * @param   integer  $start
     * @param   integer  $limit
     *
     * @return  array  ['items' => array, 'total' => int]
     */
    public function getLog($filter, $start, $limit)
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->from($db->quoteName('#__mediacleaner_compress'));

        switch ($filter) {
            case 'done':
                $query->where($db->quoteName('status') . " IN ('done','final','converted')");
                break;

            case 'skipped':
            case 'restored':
                $query->where($db->quoteName('status') . ' = ' . $db->quote($filter));
                break;
        }

        $total = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $items = $db->setQuery(
            $query->select('*')->order($db->quoteName('id') . ' DESC'),
            max(0, (int) $start),
            max(1, (int) $limit)
        )->loadAssocList();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Readable text of a stored reason ("LANG_KEY|detail").
     *
     * @param   string  $reason
     *
     * @return  string
     */
    public static function reasonText($reason)
    {
        if ($reason === '') {
            return '';
        }

        [$key, $detail] = array_pad(explode('|', $reason, 2), 2, '');

        return Text::_($key) . ($detail !== '' ? ' (' . $detail . ')' : '');
    }

    /**
     * Put files back the way they were, for roughly 20 seconds.
     *
     * @param   integer[]  $ids
     *
     * @return  array  ['restored' => int, 'errors' => string[], 'remaining' => int]
     */
    public function restore(array $ids)
    {
        $this->authorise();

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return ['restored' => 0, 'errors' => [], 'remaining' => 0];
        }

        $db   = $this->getDatabase();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__mediacleaner_compress'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')
                ->where($db->quoteName('status') . ' = ' . $db->quote('done'))
        )->loadAssocList();

        $locator    = new StorageLocator();
        $compressor = new Compressor(CompressJob::getSettings(), $locator->getOriginalsDir());
        $rewriter   = new ReferenceRewriter($db);
        $deadline   = microtime(true) + 20;
        $restored   = 0;
        $errors     = [];
        $remaining  = 0;

        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                $remaining++;
                continue;
            }

            $converted = $row['new_path'] !== '' && $row['new_path'] !== $row['relative_path'];

            if ($row['original_stored'] === '' && !$converted) {
                $errors[] = $row['relative_path'];
                continue;
            }

            if ($converted) {
                if ($row['original_stored'] !== '' && !$compressor->restoreOriginal($row['original_stored'], $row['relative_path'], false)) {
                    $errors[] = $row['relative_path'];
                    continue;
                }

                $rewriter->restore((int) $row['id'], $row['relative_path'], $row['new_path']);

                $newAbs = JPATH_ROOT . '/' . $row['new_path'];

                if (is_file($newAbs)) {
                    @unlink($newAbs);
                }
            } elseif (!$compressor->restoreOriginal($row['original_stored'], $row['relative_path'], true)) {
                $errors[] = $row['relative_path'];
                continue;
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__mediacleaner_compress'))
                    ->set($db->quoteName('status') . ' = ' . $db->quote('restored'))
                    ->set($db->quoteName('original_stored') . ' = ' . $db->quote(''))
                    ->set($db->quoteName('restored') . ' = ' . $db->quote(Factory::getDate()->toSql()))
                    ->where($db->quoteName('id') . ' = ' . (int) $row['id'])
            )->execute();

            $restored++;
        }

        if ($restored > 0) {
            CompressJob::markNeedsRescan($db);
        }

        return ['restored' => $restored, 'errors' => $errors, 'remaining' => $remaining];
    }

    /**
     * Delete kept originals for good. Afterwards those files can no
     * longer be restored.
     *
     * @param   integer[]|null  $ids  null: all.
     *
     * @return  array  ['deleted' => int, 'bytes' => int]
     */
    public function purgeOriginals($ids = null)
    {
        $this->authorise();

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'original_stored', 'old_size']))
            ->from($db->quoteName('#__mediacleaner_compress'))
            ->where($db->quoteName('status') . ' = ' . $db->quote('done'))
            ->where($db->quoteName('original_stored') . " <> ''");

        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

            if (!$ids) {
                return ['deleted' => 0, 'bytes' => 0];
            }

            $query->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');
        }

        $rows    = $db->setQuery($query)->loadAssocList();
        $dir     = (new StorageLocator())->getOriginalsDir();
        $deleted = 0;
        $bytes   = 0;

        foreach ($rows as $row) {
            $file = $dir . '/' . ltrim($row['original_stored'], '/');

            if (is_file($file) && !@unlink($file)) {
                continue;
            }

            $this->removeEmptyParents(\dirname($file), $dir);

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__mediacleaner_compress'))
                    ->set($db->quoteName('status') . ' = ' . $db->quote('final'))
                    ->set($db->quoteName('original_stored') . ' = ' . $db->quote(''))
                    ->where($db->quoteName('id') . ' = ' . (int) $row['id'])
            )->execute();

            // The database references of a converted file no longer
            // need to be restorable either.
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__mediacleaner_compress_refs'))
                    ->where($db->quoteName('compress_id') . ' = ' . (int) $row['id'])
            )->execute();

            $deleted++;
            $bytes += (int) $row['old_size'];
        }

        return ['deleted' => $deleted, 'bytes' => $bytes];
    }

    /**
     * Forget all skipped files, so the next round tries them again.
     *
     * @return  integer
     */
    public function clearSkipped()
    {
        $this->authorise();

        $db = $this->getDatabase();
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__mediacleaner_compress'))
                ->where($db->quoteName('status') . ' = ' . $db->quote('skipped'))
        )->execute();

        return (int) $db->getAffectedRows();
    }

    /**
     * The kept originals, split into ZIP parts of at most
     * ZIP_PART_BYTES.
     *
     * @return  array  [[stored path => size, ...], ...]
     */
    public function getZipParts()
    {
        $db   = $this->getDatabase();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['original_stored']))
                ->from($db->quoteName('#__mediacleaner_compress'))
                ->where($db->quoteName('status') . ' = ' . $db->quote('done'))
                ->where($db->quoteName('original_stored') . " <> ''")
                ->order($db->quoteName('original_stored'))
        )->loadColumn();

        $dir   = (new StorageLocator())->getOriginalsDir();
        $parts = [];
        $part  = [];
        $sum   = 0;

        foreach ($rows as $stored) {
            $file = $dir . '/' . $stored;

            if (!is_file($file)) {
                continue;
            }

            $size = (int) filesize($file);

            if ($part && $sum + $size > self::ZIP_PART_BYTES) {
                $parts[] = $part;
                $part    = [];
                $sum     = 0;
            }

            $part[$stored] = $size;
            $sum          += $size;
        }

        if ($part) {
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * Build ZIP part $number in the tmp folder and return its path. The
     * caller streams it and deletes it.
     *
     * @param   integer  $number  1-based.
     *
     * @return  string
     *
     * @throws  \RuntimeException
     */
    public function buildZipPart($number)
    {
        $this->authorise();

        $parts = $this->getZipParts();

        if (!class_exists('ZipArchive') || !isset($parts[$number - 1])) {
            throw new \RuntimeException(Text::_('COM_MEDIACLEANER_COMPRESS_ZIP_FAILED'));
        }

        $dir = (string) Factory::getApplication()->get('tmp_path', JPATH_ROOT . '/tmp');
        $dir = is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir();
        $zip = $dir . '/mediacleaner_originals_' . bin2hex(random_bytes(8)) . '.zip';

        $archive = new \ZipArchive();

        if ($archive->open($zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(Text::_('COM_MEDIACLEANER_COMPRESS_ZIP_FAILED'));
        }

        $originals = (new StorageLocator())->getOriginalsDir();

        foreach (array_keys($parts[$number - 1]) as $stored) {
            $archive->addFile($originals . '/' . $stored, $stored);

            if (method_exists($archive, 'setCompressionName')) {
                // Images and PDFs are compressed already.
                $archive->setCompressionName($stored, \ZipArchive::CM_STORE);
            }
        }

        if (!$archive->close() || !is_file($zip)) {
            @unlink($zip);

            throw new \RuntimeException(Text::_('COM_MEDIACLEANER_COMPRESS_ZIP_FAILED'));
        }

        return $zip;
    }

    /**
     * @param   string  $dir
     * @param   string  $stop
     *
     * @return  void
     */
    protected function removeEmptyParents($dir, $stop)
    {
        while (strpos($dir, $stop . '/') === 0) {
            $entries = @scandir($dir);

            if ($entries === false || \count($entries) > 2 || !@rmdir($dir)) {
                return;
            }

            $dir = \dirname($dir);
        }
    }
}
