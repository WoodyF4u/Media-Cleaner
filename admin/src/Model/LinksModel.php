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
use Joomla\Component\Mediacleaner\Administrator\Service\LinkFixer;
use Joomla\Component\Mediacleaner\Administrator\Service\StorageLocator;

/**
 * The Linkcontrole tab (v2.9.1) - see LinkChecker and LinkFixer.
 */
class LinksModel extends BaseDatabaseModel
{
    /**
     * List filters.
     *
     * @var string[]
     */
    const FILTERS = ['', 'missing', 'outside', 'ignored'];

    /**
     * Joomla's "trashed" state.
     *
     * @var integer
     */
    const TRASHED = -2;

    /**
     * @return  void
     *
     * @throws  \RuntimeException
     */
    public function authorise()
    {
        if (!Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_mediacleaner')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * @return  string|null  When the last check finished.
     */
    public function getCheckedAt()
    {
        $value = StorageLocator::getState('links_checked', '');

        return $value === '' ? null : $value;
    }

    /**
     * @return  boolean  The last check stopped collecting (too many).
     */
    public function wasCut()
    {
        return StorageLocator::getState('links_checked_full', '0') === '1';
    }

    /**
     * @param   boolean  $showTrashed
     *
     * @return  array  ['' => n, 'missing' => n, 'outside' => n, 'ignored' => n, 'trashed' => n]
     */
    public function getCounts($showTrashed)
    {
        $db   = $this->getDatabase();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('kind'),
                    $db->quoteName('ignored'),
                    'CASE WHEN ' . $db->quoteName('item_state') . ' = ' . self::TRASHED . ' THEN 1 ELSE 0 END AS ' . $db->quoteName('trashed'),
                    'COUNT(*) AS ' . $db->quoteName('n'),
                ])
                ->from($db->quoteName('#__mediacleaner_links'))
                ->group($db->quoteName(['kind', 'ignored', 'trashed']))
        )->loadAssocList();

        $counts = ['' => 0, 'missing' => 0, 'outside' => 0, 'ignored' => 0, 'trashed' => 0];

        foreach ($rows as $row) {
            $n = (int) $row['n'];

            if ((int) $row['trashed'] === 1 && !$showTrashed) {
                $counts['trashed'] += (int) $row['ignored'] ? 0 : $n;
                continue;
            }

            if ((int) $row['ignored']) {
                $counts['ignored'] += $n;
                continue;
            }

            $counts[''] += $n;

            if (isset($counts[$row['kind']])) {
                $counts[$row['kind']] += $n;
            }
        }

        return $counts;
    }

    /**
     * @param   string   $filter
     * @param   boolean  $showTrashed
     * @param   integer  $start
     * @param   integer  $limit
     *
     * @return  array  ['items' => array, 'total' => int]
     */
    public function getItems($filter, $showTrashed, $start, $limit)
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->from($db->quoteName('#__mediacleaner_links'));

        if ($filter === 'ignored') {
            $query->where($db->quoteName('ignored') . ' = 1');
        } else {
            $query->where($db->quoteName('ignored') . ' = 0');

            if ($filter === 'missing' || $filter === 'outside') {
                $query->where($db->quoteName('kind') . ' = ' . $db->quote($filter));
            }
        }

        if (!$showTrashed) {
            $query->where('(' . $db->quoteName('item_state') . ' IS NULL OR ' . $db->quoteName('item_state') . ' <> ' . self::TRASHED . ')');
        }

        $total = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();

        $query->select('*')
            ->order([
                // Articles, modules, ... people know first, then the rest.
                'CASE WHEN ' . $db->quoteName('location_title') . " = '' THEN 1 ELSE 0 END",
                $db->quoteName('table_name'),
                $db->quoteName('location_title'),
                'CAST(' . $db->quoteName('pk_value') . ' AS UNSIGNED)',
                $db->quoteName('pk_value'),
                $db->quoteName('column_name'),
                $db->quoteName('id'),
            ])
            ->setLimit($limit, $start);

        $items = $db->setQuery($query)->loadAssocList();

        foreach ($items as &$item) {
            $list                = json_decode((string) $item['suggestions'], true);
            $item['suggestions'] = \is_array($list) ? $list : [];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param   integer  $start
     * @param   integer  $limit
     *
     * @return  array  ['items' => array, 'total' => int]
     */
    public function getFixes($start, $limit)
    {
        $db    = $this->getDatabase();
        $total = (int) $db->setQuery(
            $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__mediacleaner_link_fixes'))
        )->loadResult();

        $items = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'table_name', 'pk_value', 'column_name', 'url', 'new_url', 'action', 'target_path', 'copied_file', 'location_title', 'entry', 'created', 'undone']))
                ->from($db->quoteName('#__mediacleaner_link_fixes'))
                ->order($db->quoteName('id') . ' DESC')
                ->setLimit($limit, $start)
        )->loadAssocList();

        foreach ($items as &$item) {
            $entry                = json_decode((string) $item['entry'], true);
            $item['location_url'] = \is_array($entry) ? (string) ($entry['location_url'] ?? '') : '';
            $item['location_type'] = \is_array($entry) ? (string) ($entry['location_type'] ?? '') : '';
            unset($item['entry']);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Carry out the chosen repairs.
     *
     * @param   array  $requests  [id => ['choice' => 's0'|'copy'|'unwrap'|'remove'|'custom', 'custom' => path], ...]
     *
     * @return  array  ['fixed' => int, 'skipped' => int, 'errors' => [message => count]]
     */
    public function fix(array $requests)
    {
        $this->authorise();

        $result = ['fixed' => 0, 'skipped' => 0, 'errors' => []];
        $ids    = array_values(array_unique(array_filter(array_map('intval', array_keys($requests)))));

        if (!$ids) {
            return $result;
        }

        $db    = $this->getDatabase();
        $links = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__mediacleaner_links'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')
                ->order($db->quoteName('id'))
        )->loadAssocList('id');

        $fixer = new LinkFixer($db);

        foreach ($ids as $id) {
            if (!isset($links[$id])) {
                $result['skipped']++;
                continue;
            }

            $link        = $links[$id];
            $choice      = (string) ($requests[$id]['choice'] ?? '');
            $suggestions = json_decode((string) $link['suggestions'], true) ?: [];
            $target      = '';

            if ($choice === '') {
                $result['skipped']++;
                continue;
            }

            if (preg_match('~^s(\d+)$~', $choice, $m)) {
                if (!isset($suggestions[(int) $m[1]]['path'])) {
                    $result['skipped']++;
                    continue;
                }

                $action = 'path';
                $target = (string) $suggestions[(int) $m[1]]['path'];
            } elseif ($choice === 'custom') {
                $target = $fixer->normaliseTarget((string) ($requests[$id]['custom'] ?? ''));
                $action = 'path';

                if ($target === null) {
                    $this->countError($result, 'CUSTOM_INVALID');
                    continue;
                }
            } elseif (\in_array($choice, ['copy', 'unwrap', 'remove'], true)) {
                $action = $choice;
            } else {
                $result['skipped']++;
                continue;
            }

            try {
                $done = $fixer->fix($link, $action, $target);
            } catch (\Throwable $e) {
                $done = ['ok' => false, 'error' => 'ERROR'];
            }

            if ($done['ok']) {
                $result['fixed']++;
            } else {
                $this->countError($result, $done['error']);
            }
        }

        return $result;
    }

    /**
     * @param   array   &$result
     * @param   string  $key
     *
     * @return  void
     */
    protected function countError(array &$result, $key)
    {
        $message                    = Text::_('COM_MEDIACLEANER_LINKS_ERR_' . $key);
        $result['errors'][$message] = ($result['errors'][$message] ?? 0) + 1;
    }

    /**
     * @param   integer[]  $ids
     * @param   boolean    $ignore
     *
     * @return  integer
     */
    public function setIgnored(array $ids, $ignore)
    {
        $this->authorise();

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return 0;
        }

        $db     = $this->getDatabase();
        $hashes = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('hash'))
                ->from($db->quoteName('#__mediacleaner_links'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')
        )->loadColumn();

        if (!$hashes) {
            return 0;
        }

        $quoted = implode(',', array_map([$db, 'quote'], $hashes));

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__mediacleaner_links_ignored'))
                ->where($db->quoteName('hash') . ' IN (' . $quoted . ')')
        )->execute();

        if ($ignore) {
            $now = Factory::getDate()->toSql();

            foreach (array_unique($hashes) as $hash) {
                $record = (object) ['hash' => $hash, 'created' => $now];
                $db->insertObject('#__mediacleaner_links_ignored', $record);
            }
        }

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__mediacleaner_links'))
                ->set($db->quoteName('ignored') . ' = ' . ($ignore ? 1 : 0))
                ->where($db->quoteName('hash') . ' IN (' . $quoted . ')')
        )->execute();

        return \count($hashes);
    }

    /**
     * @param   integer[]  $ids  `#__mediacleaner_link_fixes` ids.
     *
     * @return  array  ['undone' => int, 'failed' => int]
     */
    public function undo(array $ids)
    {
        $this->authorise();

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return ['undone' => 0, 'failed' => 0];
        }

        $db    = $this->getDatabase();
        $fixes = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__mediacleaner_link_fixes'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')
                ->where($db->quoteName('undone') . ' IS NULL')
                ->order($db->quoteName('id') . ' DESC')
        )->loadAssocList();

        $fixer  = new LinkFixer($db);
        $result = ['undone' => 0, 'failed' => \count($ids) - \count($fixes)];

        foreach ($fixes as $fix) {
            try {
                $ok = $fixer->undo($fix);
            } catch (\Throwable $e) {
                $ok = false;
            }

            $result[$ok ? 'undone' : 'failed']++;
        }

        return $result;
    }
}
