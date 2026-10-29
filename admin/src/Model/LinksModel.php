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
    const FILTERS = ['', 'missing', 'outside', 'external', 'ignored'];

    /**
     * Joomla's own tables, by what people call them (v2.9.2 source filter).
     *
     * @var array
     */
    const CORE_SOURCES = [
        'content'         => 'COM_MEDIACLEANER_LINKS_SOURCE_CONTENT',
        'modules'         => 'COM_MEDIACLEANER_LINKS_SOURCE_MODULES',
        'menu'            => 'COM_MEDIACLEANER_LINKS_SOURCE_MENU',
        'categories'      => 'COM_MEDIACLEANER_LINKS_SOURCE_CATEGORIES',
        'contact_details' => 'COM_MEDIACLEANER_LINKS_SOURCE_CONTACTS',
        'banners'         => 'COM_MEDIACLEANER_LINKS_SOURCE_BANNERS',
        'tags'            => 'COM_MEDIACLEANER_LINKS_SOURCE_TAGS',
        'template_styles' => 'COM_MEDIACLEANER_LINKS_SOURCE_TEMPLATES',
        'users'           => 'COM_MEDIACLEANER_LINKS_SOURCE_USERS',
        'user_profiles'   => 'COM_MEDIACLEANER_LINKS_SOURCE_USERS',
        'extensions'      => 'COM_MEDIACLEANER_LINKS_SOURCE_EXTENSIONS',
        'fields_values'   => 'COM_MEDIACLEANER_LINKS_SOURCE_FIELDS',
        'fields'          => 'COM_MEDIACLEANER_LINKS_SOURCE_FIELDS',
        'newsfeeds'       => 'COM_MEDIACLEANER_LINKS_SOURCE_NEWSFEEDS',
    ];

    /**
     * @var array  table => label
     */
    protected $sourceLabels = [];

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
     * @return  boolean  The last check included links to other websites.
     */
    public function checkedExternal()
    {
        return StorageLocator::getState('links_checked_external', '0') === '1';
    }

    /**
     * @param   boolean  $showTrashed
     * @param   string   $source  Table name, '' = all.
     *
     * @return  array  ['' => n, 'missing' => n, 'outside' => n, 'external' => n, 'ignored' => n, 'trashed' => n]
     */
    public function getCounts($showTrashed, $source = '')
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('kind'),
                $db->quoteName('ignored'),
                'CASE WHEN ' . $db->quoteName('item_state') . ' = ' . self::TRASHED . ' THEN 1 ELSE 0 END AS ' . $db->quoteName('trashed'),
                'COUNT(*) AS ' . $db->quoteName('n'),
            ])
            ->from($db->quoteName('#__mediacleaner_links'))
            ->group($db->quoteName(['kind', 'ignored', 'trashed']));

        if ($source !== '') {
            $query->where($db->quoteName('table_name') . ' = ' . $db->quote($source));
        }

        $rows   = $db->setQuery($query)->loadAssocList();
        $counts = ['' => 0, 'missing' => 0, 'outside' => 0, 'external' => 0, 'ignored' => 0, 'trashed' => 0];

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
            $kind        = strpos($row['kind'], 'ext_') === 0 ? 'external' : $row['kind'];

            if (isset($counts[$kind])) {
                $counts[$kind] += $n;
            }
        }

        return $counts;
    }

    /**
     * The WHERE of the list, shared by getItems(), getSources() and
     * getIdsForFilter() so they always mean the same rows.
     *
     * @param   \Joomla\Database\QueryInterface  $query
     * @param   string                            $filter
     * @param   boolean                           $showTrashed
     * @param   string                            $source  Table name, '' = all.
     *
     * @return  void
     */
    protected function applyWhere($query, $filter, $showTrashed, $source)
    {
        $db = $this->getDatabase();

        if ($filter === 'ignored') {
            $query->where($db->quoteName('ignored') . ' = 1');
        } else {
            $query->where($db->quoteName('ignored') . ' = 0');

            if ($filter === 'missing' || $filter === 'outside') {
                $query->where($db->quoteName('kind') . ' = ' . $db->quote($filter));
            } elseif ($filter === 'external') {
                $query->where($db->quoteName('kind') . ' LIKE ' . $db->quote('ext\_%'));
            }
        }

        if (!$showTrashed) {
            $query->where('(' . $db->quoteName('item_state') . ' IS NULL OR ' . $db->quoteName('item_state') . ' <> ' . self::TRASHED . ')');
        }

        if ($source !== '') {
            $query->where($db->quoteName('table_name') . ' = ' . $db->quote($source));
        }
    }

    /**
     * Where the references of the current filter come from: one entry per
     * table, named as people know it ("Artikelen", "Balboa Gallery").
     *
     * @param   string   $filter
     * @param   boolean  $showTrashed
     *
     * @return  array  table => ['label' => string, 'count' => int], by label
     */
    public function getSources($filter, $showTrashed)
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('table_name'), 'COUNT(*) AS ' . $db->quoteName('n')])
            ->from($db->quoteName('#__mediacleaner_links'))
            ->group($db->quoteName('table_name'));

        $this->applyWhere($query, $filter, $showTrashed, '');

        $sources = [];

        foreach ($db->setQuery($query)->loadAssocList() as $row) {
            $sources[$row['table_name']] = ['label' => $this->sourceLabel($row['table_name']), 'count' => (int) $row['n']];
        }

        uasort($sources, static function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        return $sources;
    }

    /**
     * Name of a table as people know it: Joomla's own tables by what they
     * hold, an extension's tables by the extension's name (looked up
     * generically: "#__gallery_items" belongs to the installed component
     * com_gallery, whatever it is called), else the table itself.
     *
     * @param   string  $table  Full table name.
     *
     * @return  string
     */
    public function sourceLabel($table)
    {
        if (isset($this->sourceLabels[$table])) {
            return $this->sourceLabels[$table];
        }

        $db     = $this->getDatabase();
        $prefix = $db->getPrefix();
        $short  = strpos($table, $prefix) === 0 ? substr($table, \strlen($prefix)) : $table;

        if (isset(self::CORE_SOURCES[$short])) {
            return $this->sourceLabels[$table] = Text::_(self::CORE_SOURCES[$short]);
        }

        $parts = explode('_', $short);

        for ($i = \count($parts); $i >= 1; $i--) {
            $name = implode('_', \array_slice($parts, 0, $i));

            try {
                $row = $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName(['name', 'element', 'type']))
                        ->from($db->quoteName('#__extensions'))
                        ->where($db->quoteName('element') . ' IN (' . implode(',', array_map([$db, 'quote'], ['com_' . $name, $name, 'mod_' . $name, 'pkg_' . $name])) . ')')
                        ->order('CASE ' . $db->quoteName('type') . " WHEN 'component' THEN 0 WHEN 'package' THEN 1 ELSE 2 END")
                        ->setLimit(1)
                )->loadAssoc();
            } catch (\Throwable $e) {
                $row = null;
            }

            if ($row) {
                $language = Factory::getApplication()->getLanguage();
                $language->load($row['element'] . '.sys', JPATH_ADMINISTRATOR)
                    || $language->load($row['element'] . '.sys', JPATH_ADMINISTRATOR . '/components/' . $row['element']);

                $label = trim(Text::_($row['name']));

                return $this->sourceLabels[$table] = $label !== '' ? $label : $row['element'];
            }
        }

        return $this->sourceLabels[$table] = Text::sprintf('COM_MEDIACLEANER_LINKS_SOURCE_TABLE', '#__' . $short);
    }

    /**
     * Every id the current filter holds, over all pages - for "Selecteer
     * alle ... (alle pagina's)".
     *
     * @param   string   $filter
     * @param   boolean  $showTrashed
     * @param   string   $source
     *
     * @return  integer[]
     */
    public function getIdsForFilter($filter, $showTrashed, $source)
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__mediacleaner_links'));

        $this->applyWhere($query, $filter, $showTrashed, $source);

        return array_map('intval', $db->setQuery($query)->loadColumn());
    }

    /**
     * @param   string   $filter
     * @param   boolean  $showTrashed
     * @param   integer  $start
     * @param   integer  $limit
     * @param   string   $source  Table name, '' = all.
     *
     * @return  array  ['items' => array, 'total' => int]
     */
    public function getItems($filter, $showTrashed, $start, $limit, $source = '')
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->from($db->quoteName('#__mediacleaner_links'));

        $this->applyWhere($query, $filter, $showTrashed, $source);

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

                $target = (string) $suggestions[(int) $m[1]]['path'];
                $action = ($suggestions[(int) $m[1]]['reason'] ?? '') === 'archive' ? 'url' : 'path';
            } elseif ($choice === 'custom') {
                $custom = trim((string) ($requests[$id]['custom'] ?? ''));

                // A web address (v2.9.2), or a file of this site.
                if (LinkFixer::normaliseUrl($custom) !== null && $fixer->normaliseTarget($custom) === null) {
                    $target = LinkFixer::normaliseUrl($custom);
                    $action = 'url';
                } else {
                    $target = $fixer->normaliseTarget($custom);
                    $action = 'path';
                }

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
