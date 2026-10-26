<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * "Dubbele kopieën" (v2.9.0, from the stand-alone "media-verkleinen"
 * script): finds files that are NOT linked anywhere but are byte for byte
 * the same as a file that IS linked - typically the same photo uploaded
 * twice. Those copies can go without anything on the site changing.
 *
 * Works from the last scan: files of equal size are grouped first (cheap),
 * and only groups holding both a linked and an unlinked file are read
 * and compared (SHA-1), in short resumable steps like the scan itself.
 * Ignored files never count as a copy to remove.
 */
class DuplicateFinder
{
    /**
     * @var \Joomla\Database\DatabaseDriver
     */
    protected $db;

    /**
     * @param   \Joomla\Database\DatabaseDriver  $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @return  string  Job id.
     */
    public function start()
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['path', 'name', 'size', 'linked', 'ignored']))
                ->from($db->quoteName('#__mediacleaner_files'))
                ->where($db->quoteName('size') . ' > 0')
        )->loadAssocList();

        $bySize = [];

        foreach ($rows as $row) {
            $bySize[(int) $row['size']][] = [
                'rel'     => ltrim(trim($row['path'], '/') . '/' . $row['name'], '/'),
                'linked'  => (int) $row['linked'] === 1,
                'ignored' => (int) $row['ignored'] === 1,
            ];
        }

        $groups = [];
        $bytes  = 0;

        foreach ($bySize as $size => $members) {
            if (\count($members) < 2) {
                continue;
            }

            $linked   = array_filter($members, static function ($m) {
                return $m['linked'];
            });
            $unlinked = array_filter($members, static function ($m) {
                return !$m['linked'] && !$m['ignored'];
            });

            if (!$linked || !$unlinked) {
                continue;
            }

            $groups[] = [
                'size'     => $size,
                'linked'   => array_values(array_column($linked, 'rel')),
                'unlinked' => array_values(array_column($unlinked, 'rel')),
            ];
            $bytes += $size * (\count($linked) + \count($unlinked));
        }

        $id = bin2hex(random_bytes(16));

        $this->save($id, ['id' => $id, 'groups' => $groups, 'bytes' => $bytes, 'done' => 0, 'g' => 0, 'hashes' => []]);

        return $id;
    }

    /**
     * Compare for roughly $budget seconds.
     *
     * @param   string   $id
     * @param   integer  $budget
     *
     * @return  array  ['jobId', 'done', 'percent', 'label', 'found']
     */
    public function run($id, $budget)
    {
        $state    = $this->load($id);
        $deadline = microtime(true) + max(1, (int) $budget);

        while ($state['g'] < \count($state['groups'])) {
            $group = $state['groups'][$state['g']];

            foreach (array_merge($group['linked'], $group['unlinked']) as $rel) {
                if (isset($state['hashes'][$rel])) {
                    continue;
                }

                $abs                    = JPATH_ROOT . '/' . $rel;
                $state['hashes'][$rel]  = is_file($abs) ? (string) sha1_file($abs) : '';
                $state['done']         += $group['size'];

                if (microtime(true) >= $deadline) {
                    $this->save($id, $state);

                    return $this->status($state, false, 0);
                }
            }

            $state['g']++;
        }

        $found = $this->store($state);
        @unlink($this->path($id));

        return $this->status($state, true, $found);
    }

    /**
     * Write the result to `#__mediacleaner_duplicates`.
     *
     * @param   array  $state
     *
     * @return  integer
     */
    protected function store(array $state)
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();

        $db->truncateTable('#__mediacleaner_duplicates');

        $found = 0;

        foreach ($state['groups'] as $group) {
            $linked = [];

            foreach ($group['linked'] as $rel) {
                $hash = $state['hashes'][$rel] ?? '';

                if ($hash !== '' && !isset($linked[$hash])) {
                    $linked[$hash] = $rel;
                }
            }

            foreach ($group['unlinked'] as $rel) {
                $hash = $state['hashes'][$rel] ?? '';

                if ($hash === '' || !isset($linked[$hash])) {
                    continue;
                }

                $record = (object) [
                    'relative_path' => $rel,
                    'twin_path'     => $linked[$hash],
                    'size'          => (int) $group['size'],
                    'hash'          => $hash,
                    'found_at'      => $now,
                ];

                $db->insertObject('#__mediacleaner_duplicates', $record);
                $found++;
            }
        }

        StorageLocator::setState('duplicates_searched', $now);

        return $found;
    }

    /**
     * @param   array    $state
     * @param   boolean  $done
     * @param   integer  $found
     *
     * @return  array
     */
    protected function status(array $state, $done, $found)
    {
        $total = max(1, (int) $state['bytes']);

        return [
            'jobId'   => $state['id'],
            'done'    => $done,
            'percent' => $done ? 100 : max(1, min(99, (int) floor(100 * $state['done'] / $total))),
            'label'   => Text::sprintf('COM_MEDIACLEANER_DUPLICATES_PROGRESS', number_format($state['done'] / 1048576, 1, ',', '.'), number_format($state['bytes'] / 1048576, 1, ',', '.')),
            'found'   => $found,
        ];
    }

    /**
     * @param   string  $id
     *
     * @return  string
     */
    protected function path($id)
    {
        $dir = (string) Factory::getApplication()->get('tmp_path', '');
        $dir = $dir !== '' && is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir();

        return rtrim($dir, '/\\') . '/mediacleaner_duplicates_' . $id . '.state';
    }

    /**
     * @param   string  $id
     * @param   array   $state
     *
     * @return  void
     */
    protected function save($id, array $state)
    {
        $file = $this->path($id);
        $data = serialize($state);

        if (@file_put_contents($file . '.tmp', $data) !== \strlen($data) || !@rename($file . '.tmp', $file)) {
            throw new \RuntimeException(Text::sprintf('COM_MEDIACLEANER_SCAN_WRITE_FAILED', \dirname($file)));
        }
    }

    /**
     * @param   string  $id
     *
     * @return  array
     */
    protected function load($id)
    {
        if (preg_match('~^[a-f0-9]{32}$~', (string) $id) && is_file($this->path($id))) {
            $state = @unserialize((string) file_get_contents($this->path($id)), ['allowed_classes' => false]);

            if (\is_array($state) && ($state['id'] ?? '') === $id) {
                return $state;
            }
        }

        throw new \RuntimeException(Text::_('COM_MEDIACLEANER_COMPRESS_STATE_LOST'));
    }
}
