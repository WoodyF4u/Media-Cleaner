<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

/**
 * Points database references at a converted file (v2.9.0) - e.g. every
 * "images/foto.png" becomes "images/foto.webp" - and can put them back
 * exactly ("Herstellen"). This replaces the SQL script the stand-alone
 * "media-verkleinen" tool made people run in phpMyAdmin themselves.
 *
 * Every text column of every table of this site is searched, except the
 * tables in $excludedTables (sessions, logs, version history, search
 * index, this component's own tables, ...). Before a value is changed,
 * its old value is stored in `#__mediacleaner_compress_refs`, one row per
 * converted file, so a single file can be restored on its own later.
 * The UPDATE itself only goes through when the value is still exactly
 * what was read (no clash with someone editing at the same moment).
 *
 * What counts as a reference to "images/a/foto.png":
 *   images/a/foto.png               as such, URL-encoded (spaces as %20),
 *                                   HTML-encoded (&amp;) and JSON-escaped
 *                                   (images\/a\/foto.png)
 *   /images/a/foto.png              with this site's base path (e.g.
 *                                   /00-aijen/images/...) or full URL
 *   https://site.nl/images/a/...    in front (with or without www., http
 *                                   or https)
 *   joomlaImage://local-images/a/foto.png   Joomla's media field suffix
 * but NOT "media/images/a/foto.png", "oud-images/a/foto.png" or
 * "foto.png.bak": those are other files.
 *
 * PHP-serialized values (some extensions store their settings that way)
 * carry the length of every string; a path that gets longer (.png ->
 * .webp) would break them with a plain text replace. Those values are
 * taken apart and put back together with corrected lengths instead.
 */
class ReferenceRewriter
{
    /**
     * Table name patterns (after the prefix, case-insensitive regex)
     * that are never changed.
     *
     * @var string
     */
    protected $excludedTables = '~^(mediacleaner_|session$|history$|finder_|action_log|updates$|update_sites|schemas$|redirect_links$|akeeba|.*_bak_)|_bak_media$~i';

    /**
     * @var \Joomla\Database\DatabaseDriver
     */
    protected $db;

    /**
     * @var string[]  Accepted text in front of a path, see allowedPrefixes().
     */
    protected $prefixes = null;

    /**
     * @param   \Joomla\Database\DatabaseDriver  $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Every table that may hold references: [['table' => ..., 'pk' => ..., 'columns' => [...]], ...]
     *
     * Tables with a single-column primary key are worked through row by
     * row. Tables without one - Joomla's own `#__fields_values` (custom
     * fields, e.g. of type "media"), `#__user_profiles`, many extension
     * tables - cannot be addressed per row; those get one target per text
     * column with 'pk' => '' and are rewritten by value instead: every
     * distinct value that holds a reference is changed wherever it occurs
     * (see rewriteByValue()).
     *
     * @return  array  ['targets' => array, 'skipped' => string[]]
     */
    public function getTargets()
    {
        $db       = $this->db;
        $prefix   = $db->getPrefix();
        $database = $db->setQuery('SELECT DATABASE()')->loadResult();

        $columns = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['TABLE_NAME', 'COLUMN_NAME']))
                ->from($db->quoteName('information_schema.COLUMNS'))
                ->where($db->quoteName('TABLE_SCHEMA') . ' = ' . $db->quote($database))
                ->where($db->quoteName('DATA_TYPE') . " IN ('char','varchar','tinytext','text','mediumtext','longtext')")
                ->order($db->quoteName(['TABLE_NAME', 'ORDINAL_POSITION']))
        )->loadRowList();

        $keys = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['TABLE_NAME', 'COLUMN_NAME']))
                ->from($db->quoteName('information_schema.KEY_COLUMN_USAGE'))
                ->where($db->quoteName('TABLE_SCHEMA') . ' = ' . $db->quote($database))
                ->where($db->quoteName('CONSTRAINT_NAME') . ' = ' . $db->quote('PRIMARY'))
        )->loadRowList();

        $pk = [];

        foreach ($keys as [$table, $column]) {
            $pk[$table][] = $column;
        }

        $targets = [];
        $byValue = [];

        foreach ($columns as [$table, $column]) {
            if ($prefix !== '' && strpos($table, $prefix) !== 0) {
                continue;
            }

            if (preg_match($this->excludedTables, substr($table, \strlen($prefix)))) {
                continue;
            }

            if (\in_array($column, $pk[$table] ?? [], true)) {
                // A path as (part of the) primary key is not something to rewrite.
                continue;
            }

            if (\count($pk[$table] ?? []) !== 1) {
                $byValue[] = ['table' => $table, 'pk' => '', 'columns' => [$column]];
                continue;
            }

            $targets[$table]['table']     = $table;
            $targets[$table]['pk']        = $pk[$table][0];
            $targets[$table]['columns'][] = $column;
        }

        return ['targets' => array_merge(array_values($targets), $byValue), 'skipped' => []];
    }

    /**
     * Work through the tables for roughly until $deadline, rewriting
     * references for all $pairs.
     *
     * @param   array       $targets  From getTargets().
     * @param   array       $pairs    [['id' => compress row id, 'from' => 'images/a.png', 'to' => 'images/a.webp'], ...]
     * @param   array       &$cursor  ['t' => table index, 'last' => last primary key value or null]
     * @param   float|null  $deadline
     * @param   array       &$counts  compress id => number of references changed (added to)
     *
     * @return  boolean  true when all tables are done.
     */
    public function rewriteStep(array $targets, array $pairs, array &$cursor, $deadline, array &$counts)
    {
        if (!$pairs) {
            return true;
        }

        $db         = $this->db;
        $extensions = [];

        foreach ($pairs as $pair) {
            $extensions[strtolower(pathinfo($pair['from'], PATHINFO_EXTENSION))] = true;
        }

        while ($cursor['t'] < \count($targets)) {
            $target = $targets[$cursor['t']];

            if ($target['pk'] === '') {
                $this->rewriteByValue($target['table'], $target['columns'][0], array_keys($extensions), $pairs, $cursor, $counts);

                if ($deadline !== null && microtime(true) >= $deadline) {
                    return $cursor['t'] >= \count($targets);
                }

                continue;
            }

            $where  = [];

            foreach ($target['columns'] as $column) {
                foreach (array_keys($extensions) as $ext) {
                    $where[] = $db->quoteName($column) . ' LIKE ' . $db->quote('%.' . $ext . '%');
                }
            }

            $query = $db->getQuery(true)
                ->select($db->quoteName(array_merge([$target['pk']], $target['columns'])))
                ->from($db->quoteName($target['table']))
                ->where('(' . implode(' OR ', $where) . ')')
                ->order($db->quoteName($target['pk']))
                ->setLimit(100);

            if ($cursor['last'] !== null) {
                $query->where($db->quoteName($target['pk']) . ' > ' . $db->quote($cursor['last']));
            }

            try {
                $rows = $db->setQuery($query)->loadAssocList();
            } catch (\Throwable $e) {
                // A table that cannot be read (view, crashed table): skip it.
                $rows = [];
            }

            foreach ($rows as $row) {
                $pkValue = (string) $row[$target['pk']];

                foreach ($target['columns'] as $column) {
                    if ($row[$column] === null || $row[$column] === '') {
                        continue;
                    }

                    $this->rewriteCell($target['table'], $target['pk'], $pkValue, $column, (string) $row[$column], $pairs, $counts);
                }

                $cursor['last'] = $pkValue;
            }

            if (\count($rows) < 100) {
                $cursor['t']++;
                $cursor['last'] = null;
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                return $cursor['t'] >= \count($targets);
            }
        }

        return true;
    }

    /**
     * One batch of a table without a single-column primary key: read up
     * to 100 distinct values (compared byte for byte, so "Foto.png" and
     * "foto.png" stay two values) after $cursor['last'], rewrite each and
     * update every row holding exactly that value. A value that changed
     * may come along again later in the order; rewriting it again finds
     * nothing, so that is harmless.
     *
     * @param   string    $table
     * @param   string    $column
     * @param   string[]  $extensions
     * @param   array     $pairs
     * @param   array     &$cursor
     * @param   array     &$counts
     *
     * @return  void
     */
    protected function rewriteByValue($table, $column, array $extensions, array $pairs, array &$cursor, array &$counts)
    {
        $db     = $this->db;
        $binary = 'CAST(' . $db->quoteName($column) . ' AS BINARY)';
        $where  = [];

        foreach ($extensions as $ext) {
            $where[] = $db->quoteName($column) . ' LIKE ' . $db->quote('%.' . $db->escape($ext, true) . '%');
        }

        $query = 'SELECT DISTINCT ' . $binary . ' AS ' . $db->quoteName('v')
            . ' FROM ' . $db->quoteName($table)
            . ' WHERE (' . implode(' OR ', $where) . ')'
            . ($cursor['last'] !== null ? ' AND ' . $binary . ' > ' . $db->quote($cursor['last']) : '')
            . ' ORDER BY ' . $db->quoteName('v')
            . ' LIMIT 100';

        try {
            $values = $db->setQuery($query)->loadColumn();
        } catch (\Throwable $e) {
            $values = [];
        }

        foreach ($values as $value) {
            $value = (string) $value;
            $new   = $value;
            $hits  = [];

            foreach ($pairs as $pair) {
                if (!$this->mayContain($new, $pair['from'])) {
                    continue;
                }

                $count     = 0;
                $rewritten = $this->rewriteValue($new, $pair['from'], $pair['to'], $count);

                if ($count > 0 && $rewritten !== $new) {
                    $hits[] = [$pair['id'], $count];
                    $new    = $rewritten;
                }
            }

            if ($hits) {
                $db->setQuery(
                    'UPDATE ' . $db->quoteName($table)
                    . ' SET ' . $db->quoteName($column) . ' = ' . $db->quote($new)
                    . ' WHERE ' . $binary . ' = ' . $db->quote($value)
                )->execute();

                $rows = (int) $db->getAffectedRows();

                if ($rows > 0) {
                    $now = Factory::getDate()->toSql();

                    foreach ($hits as [$id, $count]) {
                        $record = (object) [
                            'compress_id' => (int) $id,
                            'table_name'  => $table,
                            'pk_column'   => '',
                            'pk_value'    => '',
                            'column_name' => $column,
                            'old_value'   => $value,
                            'created'     => $now,
                        ];

                        $db->insertObject('#__mediacleaner_compress_refs', $record);

                        $counts[$id] = ($counts[$id] ?? 0) + $count * $rows;
                    }
                }
            }

            $cursor['last'] = $value;
        }

        if (\count($values) < 100) {
            $cursor['t']++;
            $cursor['last'] = null;
        }
    }

    /**
     * Cheap first test: does $text mention the file name of $path in one
     * of its likely forms at all?
     *
     * @param   string  $text
     * @param   string  $path
     *
     * @return  boolean
     */
    protected function mayContain($text, $path)
    {
        $name = basename($path);

        return strpos($text, $name) !== false
            || strpos($text, rawurlencode($name)) !== false
            || strpos($text, htmlspecialchars($name, ENT_QUOTES)) !== false
            || strpos($text, substr((string) json_encode($name), 1, -1)) !== false;
    }

    /**
     * Put back the database references of one converted file.
     *
     * @param   integer  $compressId
     * @param   string   $from  Original path (e.g. images/a.png).
     * @param   string   $to    Converted path (e.g. images/a.webp).
     *
     * @return  integer  Number of values changed back.
     */
    public function restore($compressId, $from, $to)
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__mediacleaner_compress_refs'))
                ->where($db->quoteName('compress_id') . ' = ' . (int) $compressId)
                ->order($db->quoteName('id') . ' DESC')
        )->loadAssocList();

        $changed = 0;
        $byValue = [];

        foreach ($rows as $ref) {
            if ($ref['pk_column'] === '') {
                // Table without a single-column key: put back by value,
                // once per column, below.
                $byValue[$ref['table_name'] . "\0" . $ref['column_name']] = [$ref['table_name'], $ref['column_name']];
                continue;
            }

            try {
                $current = $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName($ref['column_name']))
                        ->from($db->quoteName($ref['table_name']))
                        ->where($db->quoteName($ref['pk_column']) . ' = ' . $db->quote($ref['pk_value']))
                )->loadResult();
            } catch (\Throwable $e) {
                continue;
            }

            if ($current === null) {
                continue;
            }

            $count    = 0;
            $reverted = $this->rewriteValue((string) $current, $to, $from, $count);

            if ($count > 0 && $reverted !== $current) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->quoteName($ref['table_name']))
                        ->set($db->quoteName($ref['column_name']) . ' = ' . $db->quote($reverted))
                        ->where($db->quoteName($ref['pk_column']) . ' = ' . $db->quote($ref['pk_value']))
                )->execute();

                $changed++;
            }
        }

        foreach ($byValue as [$table, $column]) {
            $changed += $this->restoreByValue($table, $column, $from, $to);
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__mediacleaner_compress_refs'))
                ->where($db->quoteName('compress_id') . ' = ' . (int) $compressId)
        )->execute();

        return $changed;
    }

    /**
     * Restore counterpart of rewriteByValue(): every distinct value in
     * $table.$column that mentions the converted file gets $to turned
     * back into $from.
     *
     * @param   string  $table
     * @param   string  $column
     * @param   string  $from
     * @param   string  $to
     *
     * @return  integer  Number of distinct values changed back.
     */
    protected function restoreByValue($table, $column, $from, $to)
    {
        $db      = $this->db;
        $binary  = 'CAST(' . $db->quoteName($column) . ' AS BINARY)';
        $name    = basename($to);
        $changed = 0;
        $like    = [];

        foreach (array_unique([$name, rawurlencode($name), substr((string) json_encode($name), 1, -1)]) as $form) {
            $like[] = $db->quoteName($column) . ' LIKE ' . $db->quote('%' . $db->escape($form, true) . '%');
        }

        try {
            $values = $db->setQuery(
                'SELECT DISTINCT ' . $binary . ' FROM ' . $db->quoteName($table) . ' WHERE ' . implode(' OR ', $like)
            )->loadColumn();
        } catch (\Throwable $e) {
            return 0;
        }

        foreach ($values as $value) {
            $value    = (string) $value;
            $count    = 0;
            $reverted = $this->rewriteValue($value, $to, $from, $count);

            if ($count > 0 && $reverted !== $value) {
                $db->setQuery(
                    'UPDATE ' . $db->quoteName($table)
                    . ' SET ' . $db->quoteName($column) . ' = ' . $db->quote($reverted)
                    . ' WHERE ' . $binary . ' = ' . $db->quote($value)
                )->execute();

                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @param   string  $table
     * @param   string  $pk
     * @param   string  $pkValue
     * @param   string  $column
     * @param   string  $value
     * @param   array   $pairs
     * @param   array   &$counts
     *
     * @return  void
     */
    protected function rewriteCell($table, $pk, $pkValue, $column, $value, array $pairs, array &$counts)
    {
        $new     = $value;
        $history = [];

        foreach ($pairs as $pair) {
            if (!$this->mayContain($new, $pair['from'])) {
                continue;
            }

            $count     = 0;
            $rewritten = $this->rewriteValue($new, $pair['from'], $pair['to'], $count);

            if ($count > 0 && $rewritten !== $new) {
                $history[] = [$pair['id'], $new, $count];
                $new       = $rewritten;
            }
        }

        if (!$history) {
            return;
        }

        $db = $this->db;

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName($table))
                ->set($db->quoteName($column) . ' = ' . $db->quote($new))
                ->where($db->quoteName($pk) . ' = ' . $db->quote($pkValue))
                ->where($db->quoteName($column) . ' = ' . $db->quote($value))
        )->execute();

        if ($db->getAffectedRows() < 1) {
            // Changed by someone else in the meantime - leave it.
            return;
        }

        $now = Factory::getDate()->toSql();

        foreach ($history as [$id, $old, $count]) {
            $record = (object) [
                'compress_id' => (int) $id,
                'table_name'  => $table,
                'pk_column'   => $pk,
                'pk_value'    => $pkValue,
                'column_name' => $column,
                'old_value'   => $old,
                'created'     => $now,
            ];

            $db->insertObject('#__mediacleaner_compress_refs', $record);

            $counts[$id] = ($counts[$id] ?? 0) + $count;
        }
    }

    /**
     * Replace references to $from by $to in one stored value, aware of
     * PHP-serialized data.
     *
     * @param   string   $value
     * @param   string   $from
     * @param   string   $to
     * @param   integer  &$count  Number of references replaced (added to).
     *
     * @return  string
     */
    public function rewriteValue($value, $from, $to, &$count)
    {
        $serialized = $this->rewriteSerialized($value, function ($text) use ($from, $to, &$count) {
            return $this->rewriteValue($text, $from, $to, $count);
        });

        if ($serialized !== null) {
            return $serialized;
        }

        return $this->replacePaths($value, $from, $to, $count);
    }

    /**
     * Plain-text replace with the boundary rules described at the top.
     *
     * @param   string   $text
     * @param   string   $from
     * @param   string   $to
     * @param   integer  &$count
     *
     * @return  string
     */
    public function replacePaths($text, $from, $to, &$count)
    {
        foreach ($this->variants($from, $to) as [$needle, $replacement, $requiredPrefix]) {
            if ($needle === '' || strpos($text, $needle) === false) {
                continue;
            }

            $out    = '';
            $offset = 0;
            $length = \strlen($needle);

            while (($pos = strpos($text, $needle, $offset)) !== false) {
                if ($this->boundaryBefore($text, $pos, $requiredPrefix) && $this->boundaryAfter($text, $pos + $length)) {
                    $out .= substr($text, $offset, $pos - $offset) . $replacement;
                    $count++;
                } else {
                    $out .= substr($text, $offset, $pos - $offset + $length);
                }

                $offset = $pos + $length;
            }

            $text = $out . substr($text, $offset);
        }

        return $text;
    }

    /**
     * [needle, replacement, required prefix (null = the general rules)]
     *
     * @param   string  $from
     * @param   string  $to
     *
     * @return  array
     */
    protected function variants($from, $to)
    {
        $encode = static function ($path) {
            return implode('/', array_map('rawurlencode', explode('/', $path)));
        };

        $forms = [[$from, $to, null]];

        if ($encode($from) !== $from) {
            $forms[] = [$encode($from), $encode($to), null];
        }

        if (htmlspecialchars($from, ENT_QUOTES) !== $from) {
            $forms[] = [htmlspecialchars($from, ENT_QUOTES), htmlspecialchars($to, ENT_QUOTES), null];
        }

        // Joomla's media field: "images/a.png#joomlaImage://local-images/a.png?width=..."
        if (strpos($from, 'images/') === 0 && strpos($to, 'images/') === 0) {
            $forms[] = ['local-' . $from, 'local-' . $to, 'joomlaImage://'];
        }

        $all = [];

        foreach ($forms as [$a, $b, $prefix]) {
            $all[] = [$a, $b, $prefix];

            if (strpos($a, '/') !== false) {
                $all[] = [str_replace('/', '\\/', $a), str_replace('/', '\\/', $b), $prefix];
            }

            // JSON as Joomla writes it: slashes AND non-ASCII escaped
            // ("images\/café.png").
            $jsonA = substr((string) json_encode($a), 1, -1);
            $jsonB = substr((string) json_encode($b), 1, -1);

            if ($jsonA !== $a && $jsonA !== str_replace('/', '\\/', $a)) {
                $all[] = [$jsonA, $jsonB, $prefix];
            }
        }

        return $all;
    }

    /**
     * @param   string       $text
     * @param   integer      $pos
     * @param   string|null  $requiredPrefix
     *
     * @return  boolean
     */
    protected function boundaryBefore($text, $pos, $requiredPrefix)
    {
        $start = $pos;

        while ($start > 0 && strpbrk($text[$start - 1], 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-.~%/\\:@') !== false) {
            $start--;
        }

        $token = str_replace('\\/', '/', substr($text, $start, $pos - $start));

        if ($requiredPrefix !== null) {
            return $token === $requiredPrefix;
        }

        return \in_array($token, $this->allowedPrefixes(), true);
    }

    /**
     * @param   string   $text
     * @param   integer  $pos  Position right after the match.
     *
     * @return  boolean
     */
    protected function boundaryAfter($text, $pos)
    {
        if ($pos >= \strlen($text)) {
            return true;
        }

        $next = $text[$pos];

        if (ctype_alnum($next) || $next === '_' || $next === '-') {
            return false;
        }

        // "foto.png.bak" is another file; "foto.png." at the end of a sentence is not.
        return !($next === '.' && isset($text[$pos + 1]) && ctype_alnum($text[$pos + 1]));
    }

    /**
     * What may stand directly in front of "images/...": nothing, "/", the
     * site's base path, or the site's full URL.
     *
     * @return  string[]
     */
    protected function allowedPrefixes()
    {
        if ($this->prefixes !== null) {
            return $this->prefixes;
        }

        $prefixes = [''];

        try {
            $path = rtrim(Uri::root(true), '/');
            $root = Uri::getInstance(Uri::root());
            $host = (string) $root->getHost();
            $port = (int) $root->getPort();
        } catch (\Throwable $e) {
            $path = '';
            $host = '';
            $port = 0;
        }

        // "/images/..." means this site only when it lives in the web
        // root; in a subfolder (/00-aijen) it points at another site.
        $prefixes[] = $path . '/';

        if ($host !== '') {
            $bare  = preg_replace('~^www\.~i', '', $host);
            $hosts = array_unique([$host, $bare, 'www.' . $bare]);

            foreach ($hosts as $h) {
                foreach (['http://', 'https://', '//'] as $scheme) {
                    $prefixes[] = $scheme . $h . $path . '/';

                    if ($port > 0) {
                        $prefixes[] = $scheme . $h . ':' . $port . $path . '/';
                    }
                }
            }
        }

        return $this->prefixes = array_values(array_unique($prefixes));
    }

    // ------------------------------------------------------------------
    // PHP-serialized values
    // ------------------------------------------------------------------

    /**
     * If $value is one complete PHP-serialized value, rebuild it with $fn
     * applied to every string in it and all lengths corrected; otherwise
     * null.
     *
     * @param   string    $value
     * @param   callable  $fn  function (string): string
     *
     * @return  string|null
     */
    public function rewriteSerialized($value, callable $fn)
    {
        if (\strlen($value) < 4 || !preg_match('~^(?:[aOsCE]:\d+:|[ibd]:|N;)~', $value)) {
            return null;
        }

        $pos = 0;

        try {
            $out = $this->parseSerialized($value, $pos, $fn, 0);
        } catch (\UnexpectedValueException $e) {
            return null;
        }

        return $pos === \strlen($value) ? $out : null;
    }

    /**
     * @param   string    $s
     * @param   integer   &$pos
     * @param   callable  $fn
     * @param   integer   $depth
     *
     * @return  string
     *
     * @throws  \UnexpectedValueException
     */
    protected function parseSerialized($s, &$pos, callable $fn, $depth)
    {
        if ($depth > 64 || !isset($s[$pos])) {
            throw new \UnexpectedValueException();
        }

        $type = $s[$pos];

        switch ($type) {
            case 'N':
                $this->expect($s, $pos, 'N;');

                return 'N;';

            case 'b':
            case 'i':
            case 'd':
            case 'r':
            case 'R':
                if (!preg_match('~\G' . $type . ':([^;]*);~', $s, $m, 0, $pos)) {
                    throw new \UnexpectedValueException();
                }

                $pos += \strlen($m[0]);

                return $m[0];

            case 's':
                $string = $this->readLengthString($s, $pos, 's');
                $this->expect($s, $pos, ';');
                $string = (string) $fn($string);

                return 's:' . \strlen($string) . ':"' . $string . '";';

            case 'E':
                $string = $this->readLengthString($s, $pos, 'E');
                $this->expect($s, $pos, ';');

                return 'E:' . \strlen($string) . ':"' . $string . '";';

            case 'a':
                if (!preg_match('~\Ga:(\d+):\{~', $s, $m, 0, $pos)) {
                    throw new \UnexpectedValueException();
                }

                $pos += \strlen($m[0]);
                $out  = $m[0];

                for ($i = 0; $i < (int) $m[1] * 2; $i++) {
                    $out .= $this->parseSerialized($s, $pos, $fn, $depth + 1);
                }

                $this->expect($s, $pos, '}');

                return $out . '}';

            case 'O':
                $class = $this->readLengthString($s, $pos, 'O');

                if (!preg_match('~\G:(\d+):\{~', $s, $m, 0, $pos)) {
                    throw new \UnexpectedValueException();
                }

                $pos += \strlen($m[0]);
                $out  = 'O:' . \strlen($class) . ':"' . $class . '"' . $m[0];

                for ($i = 0; $i < (int) $m[1] * 2; $i++) {
                    $out .= $this->parseSerialized($s, $pos, $fn, $depth + 1);
                }

                $this->expect($s, $pos, '}');

                return $out . '}';

            case 'C':
                // Custom serialize(): opaque - copied as it is.
                $class = $this->readLengthString($s, $pos, 'C');

                if (!preg_match('~\G:(\d+):\{~', $s, $m, 0, $pos)) {
                    throw new \UnexpectedValueException();
                }

                $pos += \strlen($m[0]);
                $data = substr($s, $pos, (int) $m[1]);

                if (\strlen($data) !== (int) $m[1]) {
                    throw new \UnexpectedValueException();
                }

                $pos += (int) $m[1];
                $this->expect($s, $pos, '}');

                return 'C:' . \strlen($class) . ':"' . $class . '"' . $m[0] . $data . '}';
        }

        throw new \UnexpectedValueException();
    }

    /**
     * Reads `<type>:<len>:"<len bytes>"` and returns the bytes.
     *
     * @param   string   $s
     * @param   integer  &$pos
     * @param   string   $type
     *
     * @return  string
     *
     * @throws  \UnexpectedValueException
     */
    protected function readLengthString($s, &$pos, $type)
    {
        if (!preg_match('~\G' . $type . ':(\d+):"~', $s, $m, 0, $pos)) {
            throw new \UnexpectedValueException();
        }

        $pos   += \strlen($m[0]);
        $length = (int) $m[1];
        $string = substr($s, $pos, $length);

        if (\strlen($string) !== $length) {
            throw new \UnexpectedValueException();
        }

        $pos += $length;
        $this->expect($s, $pos, '"');

        return $string;
    }

    /**
     * @param   string   $s
     * @param   integer  &$pos
     * @param   string   $literal
     *
     * @return  void
     *
     * @throws  \UnexpectedValueException
     */
    protected function expect($s, &$pos, $literal)
    {
        if (substr($s, $pos, \strlen($literal)) !== $literal) {
            throw new \UnexpectedValueException();
        }

        $pos += \strlen($literal);
    }
}
