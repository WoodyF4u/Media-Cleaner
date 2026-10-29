<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Repairs one broken link found by LinkChecker (v2.9.1), right in the
 * stored value, and can undo that again.
 *
 * Repairs:
 *   'path'    point the link at another, existing file of this site;
 *   'copy'    (link to a file outside this site) copy that file into this
 *             site under the same path, then point the link at the copy;
 *   'unwrap'  remove the link but keep what is inside it (the small photo
 *             of a pop-up link stays on the page, it just does not open
 *             any more);
 *   'remove'  remove a broken image (and the pop-up link around it, when
 *             the image was all that link held).
 *
 * Only the reference as it was found is touched, with the same rules as
 * elsewhere in this component: the value is taken apart first when it is
 * JSON or PHP-serialized (and only put back that way when doing so gives
 * back exactly the original for an unchanged value), the UPDATE only goes
 * through when the value is still what was read, and the value before and
 * after is kept in `#__mediacleaner_link_fixes` for "Ongedaan maken".
 */
class LinkFixer
{
    /** @var \Joomla\Database\DatabaseDriver */
    protected $db;

    /** @var LinkChecker */
    protected $checker;

    /** @var ReferenceRewriter */
    protected $rewriter;

    /**
     * @param   \Joomla\Database\DatabaseDriver  $db
     */
    public function __construct($db)
    {
        $this->db       = $db;
        $this->checker  = new LinkChecker($db);
        $this->rewriter = new ReferenceRewriter($db);
    }

    /**
     * A path typed in by the user, as a path of this site of an existing
     * file - or null.
     *
     * @param   string  $input
     *
     * @return  string|null
     */
    public function normaliseTarget($input)
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        $resolved = $this->checker->resolve($input);

        if ($resolved === null || $resolved['base'] !== 'site') {
            return null;
        }

        $abs  = $this->checker->absolute('site', $resolved['rel']);
        $real = $abs === null ? false : realpath($abs);
        $root = realpath(JPATH_ROOT);

        if ($real === false || $root === false || !is_file($real) || strpos(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $root), '/') . '/') !== 0) {
            return null;
        }

        return $resolved['rel'];
    }

    /**
     * @param   array   $link    Row of `#__mediacleaner_links`.
     * @param   string  $action  'path', 'copy', 'unwrap' or 'remove'
     * @param   string  $target  For 'path': existing file of this site.
     *
     * @return  array  ['ok' => bool, 'error' => language key suffix or '']
     */
    public function fix(array $link, $action, $target = '')
    {
        $copied = '';

        if ($action === 'copy') {
            $result = $this->copyIn($link);

            if ($result !== '') {
                return ['ok' => false, 'error' => $result];
            }

            $target = $link['path'];
            $copied = $link['path'];
            $action = 'path';
            $logAs  = 'copy';
        } else {
            $logAs = $action;
        }

        if ($action === 'path' && ($target === '' || !is_file((string) $this->checker->absolute('site', $target)))) {
            return ['ok' => false, 'error' => 'TARGET_MISSING'];
        }

        if ($action === 'unwrap' && $link['element'] !== 'a') {
            return ['ok' => false, 'error' => 'NOT_POSSIBLE'];
        }

        if ($action === 'remove' && $link['element'] !== 'img') {
            return ['ok' => false, 'error' => 'NOT_POSSIBLE'];
        }

        $raw    = (string) $link['url'];
        $newRaw = $action === 'path' ? $this->replacementFor($raw, $link, $target) : '';
        $fn     = $this->transformer($action, $raw, $newRaw);
        $pairs  = [];

        foreach ($this->currentValues($link) as $value) {
            $count = 0;
            $new   = $this->apply($value, $fn, $count, $action === 'path' ? [$raw, $newRaw] : null);

            if ($new === null) {
                return ['ok' => false, 'error' => 'UNSAFE'];
            }

            if ($count < 1 || $new === $value) {
                continue;
            }

            if ($this->write($link, $value, $new)) {
                $pairs[] = [$value, $new];
            }
        }

        if (!$pairs) {
            return ['ok' => false, 'error' => 'GONE'];
        }

        $db = $this->db;

        $entry = $link;
        unset($entry['id']);

        $record = (object) [
            'link_hash'      => $link['hash'],
            'table_name'     => $link['table_name'],
            'pk_column'      => $link['pk_column'],
            'pk_value'       => $link['pk_value'],
            'column_name'    => $link['column_name'],
            'url'            => $raw,
            'new_url'        => $newRaw,
            'action'         => $logAs,
            'target_path'    => $action === 'path' ? $target : '',
            'copied_file'    => $copied,
            'old_value'      => json_encode(array_column($pairs, 0), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'new_value'      => json_encode(array_column($pairs, 1), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'entry'          => json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'location_title' => (string) $link['location_title'],
            'created'        => Factory::getDate()->toSql(),
            'created_by'     => (int) Factory::getApplication()->getIdentity()->id,
        ];

        $db->insertObject('#__mediacleaner_link_fixes', $record);

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__mediacleaner_links'))
                ->where($db->quoteName('id') . ' = ' . (int) $link['id'])
        )->execute();

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Put a repair back.
     *
     * @param   array  $fix  Row of `#__mediacleaner_link_fixes`.
     *
     * @return  boolean
     */
    public function undo(array $fix)
    {
        $old  = json_decode((string) $fix['old_value'], true);
        $new  = json_decode((string) $fix['new_value'], true);
        $link = json_decode((string) $fix['entry'], true);

        if (!\is_array($old) || !\is_array($new) || \count($old) !== \count($new) || !\is_array($link)) {
            return false;
        }

        $done = 0;

        foreach ($new as $i => $newValue) {
            $current = $this->readValue($fix, (string) $newValue);

            if ($current === (string) $newValue) {
                $done += $this->write($fix, $current, (string) $old[$i]) ? 1 : 0;
                continue;
            }

            if ($current === null || $fix['new_url'] === '') {
                continue;
            }

            // Edited since: put back only the link itself, if it is
            // still exactly what the repair made of it.
            $count    = 0;
            $reverted = $this->apply($current, $this->transformer('path', (string) $fix['new_url'], (string) $fix['url']), $count, [(string) $fix['new_url'], (string) $fix['url']]);

            if ($reverted !== null && $count > 0 && $reverted !== $current) {
                $done += $this->write($fix, $current, $reverted) ? 1 : 0;
            }
        }

        if ($done < 1) {
            return false;
        }

        $db = $this->db;

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__mediacleaner_link_fixes'))
                ->set($db->quoteName('undone') . ' = ' . $db->quote(Factory::getDate()->toSql()))
                ->where($db->quoteName('id') . ' = ' . (int) $fix['id'])
        )->execute();

        // The broken link is back: show it in the list again straight away.
        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__mediacleaner_links'))
                ->where($db->quoteName('hash') . ' = ' . $db->quote($fix['link_hash']))
        )->loadResult();

        if ($exists === 0) {
            $record = [];

            foreach (['hash', 'table_name', 'pk_column', 'pk_value', 'column_name', 'url', 'path', 'base', 'kind', 'element', 'popup', 'inner_path',
                'occurrences', 'location_type', 'location_title', 'location_url', 'item_state', 'suggestions', 'best', 'found_at'] as $key) {
                if (\array_key_exists($key, $link)) {
                    $record[$key] = $link[$key];
                }
            }

            $record['ignored'] = 0;
            $object            = (object) $record;

            $db->insertObject('#__mediacleaner_links', $object);
        }

        return true;
    }

    // ------------------------------------------------------------------

    /**
     * The reference as it should be written for $target, in the same
     * style as $raw: relative stays relative, "/000/..." keeps the base
     * path, a full address keeps the address, %20 stays %20, &amp; stays
     * &amp;, "?width=..." and Joomla's "#joomlaImage://local-..." stay
     * (pointing at the new file). A link that pointed outside this site
     * becomes relative, so it points inside it.
     *
     * @param   string  $raw
     * @param   array   $link
     * @param   string  $target
     *
     * @return  string
     */
    public function replacementFor($raw, array $link, $target)
    {
        $cut     = strcspn($raw, '?#');
        $rawPath = substr($raw, 0, $cut);
        $suffix  = substr($raw, $cut);
        $path    = $target;

        if (strpos($rawPath, '%') !== false || strpos($target, ' ') !== false && strpos($rawPath, ' ') === false) {
            $path = implode('/', array_map('rawurlencode', explode('/', $target)));
        }

        if (preg_match('~&(?:amp|#0?38);~i', $rawPath)) {
            $path = str_replace('&', '&amp;', $path);
        }

        $prefix = '';

        if (preg_match('~^((?:https?:)?//[^/]+)~i', $rawPath, $m)) {
            $prefix = $m[1] . $this->checker->getBasePath() . '/';
        } elseif ($rawPath !== '' && $rawPath[0] === '/' && $link['base'] === 'site') {
            $prefix = $this->checker->getBasePath() . '/';
        }

        if ($suffix !== '' && strpos($suffix, 'local-') !== false) {
            $suffix = str_replace('local-' . $link['path'], 'local-' . $target, $suffix);
        }

        return $prefix . $path . $suffix;
    }

    /**
     * function (string): array [string, count] for one repair.
     *
     * @param   string  $action
     * @param   string  $raw
     * @param   string  $newRaw
     *
     * @return  callable
     */
    protected function transformer($action, $raw, $newRaw)
    {
        $quoted = preg_quote($raw, '~');

        switch ($action) {
            case 'unwrap':
                return static function ($text) use ($quoted) {
                    $count = 0;
                    $out   = preg_replace('~<a\b[^>]*?\bhref\s*=\s*(["\'])' . $quoted . '\1[^>]*>(.*?)</a\s*>~is', '$2', $text, -1, $count);

                    return [$out === null ? $text : $out, $out === null ? 0 : $count];
                };

            case 'remove':
                return static function ($text) use ($quoted) {
                    $img    = '<img\b[^>]*?\bsrc\s*=\s*(["\'])' . $quoted . '\1[^>]*>';
                    $count  = 0;
                    $count2 = 0;
                    $out    = preg_replace('~<a\b[^>]*>\s*' . $img . '\s*</a\s*>~is', '', $text, -1, $count);
                    $out    = $out === null ? null : preg_replace('~' . $img . '~is', '', $out, -1, $count2);

                    return [$out === null ? $text : $out, $out === null ? 0 : $count + $count2];
                };

            default:
                return function ($text) use ($raw, $newRaw) {
                    $count = 0;

                    return [$this->replaceToken($text, $raw, $newRaw, $count), $count];
                };
        }
    }

    /**
     * Replace $raw where it stands on its own (as an attribute value, in
     * url(), as a whole value, as a word) - never inside a longer path.
     *
     * @param   string   $text
     * @param   string   $raw
     * @param   string   $newRaw
     * @param   integer  &$count
     *
     * @return  string
     */
    public function replaceToken($text, $raw, $newRaw, &$count)
    {
        if ($raw === '' || strpos($text, $raw) === false) {
            return $text;
        }

        $out    = '';
        $offset = 0;
        $length = \strlen($raw);
        $before = "\"'(=, \t\r\n>;";
        $after  = "\"') \t\r\n<,;";

        while (($pos = strpos($text, $raw, $offset)) !== false) {
            $okBefore = $pos === 0 || strpos($before, $text[$pos - 1]) !== false || ($text[$pos - 1] === '\\' && $pos > 1);
            $end      = $pos + $length;
            $okAfter  = $end >= \strlen($text) || strpos($after, $text[$end]) !== false || $text[$end] === '\\';

            // A word of loose text may be followed by a full stop.
            if (!$okAfter && $text[$end] === '.' && ($end + 1 >= \strlen($text) || ctype_space($text[$end + 1]))) {
                $okAfter = true;
            }

            if ($okBefore && $okAfter) {
                $out .= substr($text, $offset, $pos - $offset) . $newRaw;
                $count++;
            } else {
                $out .= substr($text, $offset, $end - $offset);
            }

            $offset = $end;
        }

        return $out . substr($text, $offset);
    }

    /**
     * Apply $fn to the strings inside $value (see LinkChecker::strings()).
     *
     * @param   string      $value
     * @param   callable    $fn       function (string): [string, int]
     * @param   integer     &$count
     * @param   array|null  $textPair [raw, newRaw]: fallback for JSON that
     *                                cannot be written back unchanged -
     *                                replace in the stored text itself.
     *
     * @return  string|null  null when it cannot be done safely.
     */
    public function apply($value, callable $fn, &$count, $textPair = null)
    {
        $trim = ltrim($value);

        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $data = json_decode($value);

            if (\is_array($data) || \is_object($data)) {
                $flags = JSON_INVALID_UTF8_SUBSTITUTE;
                $flags |= strpos($value, '\\/') === false ? JSON_UNESCAPED_SLASHES : 0;
                $flags |= preg_match('~\\\\u[0-9a-fA-F]{4}~', $value) ? 0 : JSON_UNESCAPED_UNICODE;

                if (json_encode($data, $flags) === $value) {
                    $hits = 0;
                    $data = $this->walk($data, $fn, $hits);

                    $count += $hits;

                    return $hits > 0 ? (string) json_encode($data, $flags) : $value;
                }

                if ($textPair === null) {
                    return null;
                }

                // Written by something other than plain json_encode():
                // replace the reference in the stored text, in each of
                // the forms JSON may have given it.
                [$raw, $newRaw] = $textPair;
                $text           = $value;

                foreach ([
                    [$raw, $newRaw],
                    [str_replace('/', '\\/', $raw), str_replace('/', '\\/', $newRaw)],
                    [substr((string) json_encode($raw), 1, -1), substr((string) json_encode($newRaw), 1, -1)],
                ] as [$from, $to]) {
                    $text = $this->replaceToken($text, $from, $to, $count);
                }

                return $text;
            }
        }

        $hits       = 0;
        $serialized = $this->rewriter->rewriteSerialized($value, static function ($text) use ($fn, &$hits) {
            [$out, $n] = $fn((string) $text);
            $hits     += $n;

            return $out;
        });

        if ($serialized !== null) {
            $count += $hits;

            return $serialized;
        }

        [$out, $n] = $fn($value);
        $count    += $n;

        return $out;
    }

    /**
     * @param   mixed     $data
     * @param   callable  $fn
     * @param   integer   &$hits
     *
     * @return  mixed
     */
    protected function walk($data, callable $fn, &$hits)
    {
        if (\is_string($data)) {
            [$out, $n] = $fn($data);
            $hits     += $n;

            return $out;
        }

        if (\is_array($data)) {
            foreach ($data as $key => $item) {
                $data[$key] = $this->walk($item, $fn, $hits);
            }
        } elseif (\is_object($data)) {
            foreach (get_object_vars($data) as $key => $item) {
                $data->$key = $this->walk($item, $fn, $hits);
            }
        }

        return $data;
    }

    /**
     * The value(s) holding the link now: one for a table with a key, every
     * distinct value mentioning it for a table without one.
     *
     * @param   array  $link
     *
     * @return  string[]
     */
    protected function currentValues(array $link)
    {
        $db = $this->db;

        try {
            if ($link['pk_column'] !== '') {
                $value = $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName($link['column_name']))
                        ->from($db->quoteName($link['table_name']))
                        ->where($db->quoteName($link['pk_column']) . ' = ' . $db->quote($link['pk_value']))
                )->loadResult();

                return $value === null ? [] : [(string) $value];
            }

            $name = basename(substr($link['url'], 0, strcspn($link['url'], '?#')));

            return array_map('strval', $db->setQuery(
                'SELECT DISTINCT CAST(' . $db->quoteName($link['column_name']) . ' AS BINARY) FROM ' . $db->quoteName($link['table_name'])
                . ' WHERE ' . $db->quoteName($link['column_name']) . ' LIKE ' . $db->quote('%' . $db->escape($name, true) . '%')
            )->loadColumn());
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Current value of the cell a fix changed, or null.
     *
     * @param   array   $fix
     * @param   string  $expected  For a table without a key: the value the fix wrote.
     *
     * @return  string|null
     */
    protected function readValue(array $fix, $expected)
    {
        $db = $this->db;

        try {
            if ($fix['pk_column'] !== '') {
                $value = $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName($fix['column_name']))
                        ->from($db->quoteName($fix['table_name']))
                        ->where($db->quoteName($fix['pk_column']) . ' = ' . $db->quote($fix['pk_value']))
                )->loadResult();

                return $value === null ? null : (string) $value;
            }

            $found = (int) $db->setQuery(
                'SELECT COUNT(*) FROM ' . $db->quoteName($fix['table_name'])
                . ' WHERE CAST(' . $db->quoteName($fix['column_name']) . ' AS BINARY) = ' . $db->quote($expected)
            )->loadResult();

            return $found > 0 ? $expected : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Write $new where the value is still exactly $old.
     *
     * @param   array   $where  Row with table_name, pk_column, pk_value, column_name.
     * @param   string  $old
     * @param   string  $new
     *
     * @return  boolean
     */
    protected function write(array $where, $old, $new)
    {
        $db     = $this->db;
        $column = $db->quoteName($where['column_name']);
        $sql    = 'UPDATE ' . $db->quoteName($where['table_name'])
            . ' SET ' . $column . ' = ' . $db->quote($new)
            . ' WHERE CAST(' . $column . ' AS BINARY) = ' . $db->quote($old);

        if ($where['pk_column'] !== '') {
            $sql .= ' AND ' . $db->quoteName($where['pk_column']) . ' = ' . $db->quote($where['pk_value']);
        }

        try {
            $db->setQuery($sql)->execute();
        } catch (\Throwable $e) {
            return false;
        }

        return $db->getAffectedRows() > 0;
    }

    /**
     * 'copy': the file outside this site into this site, same path.
     *
     * @param   array  $link
     *
     * @return  string  '' when done, else an error key suffix.
     */
    protected function copyIn(array $link)
    {
        if ($link['base'] !== 'web' || $link['kind'] !== 'outside') {
            return 'NOT_POSSIBLE';
        }

        $from = $this->checker->absolute('web', $link['path']);
        $to   = $this->checker->absolute('site', $link['path']);

        if ($from === null || $to === null || !is_file($from)) {
            return 'SOURCE_MISSING';
        }

        if (is_file($to)) {
            return sha1_file($to) === sha1_file($from) ? '' : 'TARGET_EXISTS';
        }

        if (!is_dir(\dirname($to)) && !@mkdir(\dirname($to), 0755, true)) {
            return 'COPY_FAILED';
        }

        if (!@copy($from, $to)) {
            return 'COPY_FAILED';
        }

        @chmod($to, 0644);

        return '';
    }
}
