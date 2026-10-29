<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/**
 * "Linkcontrole" (v2.9.1): finds references in the database to files of
 * this site that do not exist (any more) - a photo in an article whose
 * file was never uploaded, a pop-up link (JCE MediaBox) to a large
 * version that is missing, a typo such as "foto-1jpg", a link that only
 * works because another site in the web root happens to have the file.
 *
 * Read the same way as ReferenceRewriter writes: every text column of
 * every table of this site (minus logs, sessions, version history, search
 * index, this component's own tables, ...), in short resumable steps.
 * Values that are JSON or PHP-serialized are taken apart first, so paths
 * inside them are read as they are meant ("images\/a.jpg" is
 * "images/a.jpg").
 *
 * What is looked at in a value:
 *   - src, href, poster and data-...src/href/image attributes in HTML;
 *   - CSS url(...);
 *   - a value that as a whole is a path (Joomla's media field,
 *     "images/a.jpg#joomlaImage://...", a menu item's link, ...);
 *   - other words that end in a media extension and contain a "/".
 * Only paths of this site count: relative ones ("images/a.jpg"), ones
 * starting with the site's base path ("/000/images/a.jpg") or with its
 * own address ("https://site.nl/images/a.jpg"). The first folder must
 * exist ("images", "files", ...) - so a link to a page ("nieuws/item")
 * is never taken for a file. "index.php" links, external sites,
 * placeholders such as {loadposition} and "../" are skipped.
 *
 * When the site lives in a subfolder (e.g. /000), "/images/a.jpg"
 * points at the web root, outside this site. If the file exists there,
 * the link works now but leans on another site; that is reported as
 * kind "outside", so it can be pointed at this site's own copy (or the
 * file copied in) before the site moves.
 *
 * For every broken link, possible repairs are worked out once at the end
 * (see suggest()): another case, a missing dot before the extension, the
 * same name with another extension (converted file), the same file
 * elsewhere on the site, the small image inside a pop-up link.
 */
class LinkChecker
{
    /**
     * Extensions that make a word a file reference on its own.
     *
     * @var string
     */
    const MEDIA_EXT = 'jpe?g|png|gif|webp|avif|svg|bmp|ico|tiff?|heic|pdf|docx?|xlsx?|pptx?|odt|ods|odp|rtf|txt|csv|zip|rar|7z|mp3|m4a|wav|ogg|oga|mp4|m4v|webm|ogv|mov|avi|wmv|mpe?g|flv|eps|psd';

    /**
     * Extensions a converted or re-saved file may have got instead.
     *
     * @var string[]
     */
    const SWAP_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'JPG', 'JPEG', 'PNG', 'WEBP', 'GIF'];

    /**
     * Tables (after the prefix) not read on top of ReferenceRewriter's
     * own exclusions: Joomla's tag copies of content (#__ucm_content is
     * rewritten when the item is saved), mail templates, logs, messages.
     *
     * @var string
     */
    protected $skipTables = '~^(ucm_|mail_templates$|user_keys$|messages|privacy_|guidedtour|webauthn|.*_logs?$)~i';

    /**
     * Rows read per query.
     *
     * @var integer
     */
    protected $batch = 200;

    /**
     * Results kept per check - a site with more broken links than this
     * has a bigger problem than a list can show.
     *
     * @var integer
     */
    protected $maxFound = 20000;

    /** @var \Joomla\Database\DatabaseDriver */
    protected $db;

    /** @var string  '' or e.g. '/000' */
    protected $basePath = '';

    /** @var string|null  Folder that "/..." points at when the site is in a subfolder. */
    protected $webRoot = null;

    /** @var string[] */
    protected $hosts = [];

    /** @var array  "base\0rel" => bool */
    protected $exists = [];

    /** @var array  "base\0segment" => bool */
    protected $topDirs = [];

    /** @var array  dir => entries */
    protected $dirCache = [];

    /** @var ReferenceRewriter */
    protected $rewriter;

    /**
     * @param   \Joomla\Database\DatabaseDriver  $db
     */
    public function __construct($db)
    {
        $this->db       = $db;
        $this->rewriter = new ReferenceRewriter($db);

        try {
            $this->basePath = rtrim((string) Uri::root(true), '/');
            $host           = (string) Uri::getInstance(Uri::root())->getHost();
        } catch (\Throwable $e) {
            $host = '';
        }

        if ($host !== '') {
            $bare        = strtolower(preg_replace('~^www\.~i', '', $host));
            $this->hosts = [$bare, 'www.' . $bare];
        }

        if ($this->basePath !== '') {
            $root = rtrim(str_replace('\\', '/', JPATH_ROOT), '/');

            if (substr($root, -\strlen($this->basePath)) === $this->basePath) {
                $this->webRoot = substr($root, 0, -\strlen($this->basePath));
            } elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
                $this->webRoot = rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/');
            }

            if ($this->webRoot !== null && ($this->webRoot === '' || !is_dir($this->webRoot) || $this->webRoot === $root)) {
                $this->webRoot = null;
            }
        }
    }

    // ------------------------------------------------------------------
    // Job
    // ------------------------------------------------------------------

    /**
     * @return  string  Job id.
     */
    public function start()
    {
        $prefix  = $this->db->getPrefix();
        $targets = [];

        foreach ($this->rewriter->getTargets()['targets'] as $target) {
            if (preg_match($this->skipTables, substr($target['table'], \strlen($prefix)))) {
                continue;
            }

            $targets[] = $target;
        }

        $id = bin2hex(random_bytes(16));

        $this->save($id, [
            'id'      => $id,
            'targets' => $targets,
            'cursor'  => ['t' => 0, 'last' => null],
            'found'   => [],
            'rows'    => 0,
            'full'    => false,
        ]);

        return $id;
    }

    /**
     * Check for roughly $budget seconds.
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
        $targets  = $state['targets'];

        while ($state['cursor']['t'] < \count($targets)) {
            $target = $targets[$state['cursor']['t']];

            if ($target['pk'] === '') {
                $this->readByValue($target, $state);
            } else {
                $this->readRows($target, $state);
            }

            if (microtime(true) >= $deadline) {
                $this->save($id, $state);

                return $this->status($state, false, 0);
            }
        }

        $found = $this->store($state);
        @unlink($this->path($id));

        return $this->status($state, true, $found);
    }

    /**
     * One batch of a table with a single-column primary key.
     *
     * @param   array  $target
     * @param   array  &$state
     *
     * @return  void
     */
    protected function readRows(array $target, array &$state)
    {
        $db       = $this->db;
        $describe = $this->describer($target['table']);
        $where    = [];

        foreach ($target['columns'] as $column) {
            foreach (['%/%', '%src=%', '%href=%'] as $like) {
                $where[] = $db->quoteName($column) . ' LIKE ' . $db->quote($like);
            }
        }

        $columns = array_values(array_unique(array_merge([$target['pk']], $target['columns'], $describe['columns'])));
        $rows    = null;

        foreach ([$columns, array_values(array_unique(array_merge([$target['pk']], $target['columns'])))] as $attempt => $select) {
            $query = $db->getQuery(true)
                ->select($db->quoteName($select))
                ->from($db->quoteName($target['table']))
                ->where('(' . implode(' OR ', $where) . ')')
                ->order($db->quoteName($target['pk']))
                ->setLimit($this->batch);

            if ($state['cursor']['last'] !== null) {
                $query->where($db->quoteName($target['pk']) . ' > ' . $db->quote($state['cursor']['last']));
            }

            try {
                $rows = $db->setQuery($query)->loadAssocList();
                break;
            } catch (\Throwable $e) {
                // Describing columns missing (other version of the table):
                // try once more without them; then give up on the table.
                $rows = $attempt === 0 ? null : [];
            }
        }

        foreach ((array) $rows as $row) {
            $pkValue = (string) $row[$target['pk']];
            $info    = null;

            foreach ($target['columns'] as $column) {
                $value = (string) ($row[$column] ?? '');

                if ($value === '') {
                    continue;
                }

                foreach ($this->brokenIn($value) as $link) {
                    if ($info === null) {
                        $info = $describe['fn']($row);
                    }

                    $this->collect($state, $target['table'], $target['pk'], $pkValue, $column, $link, $info);
                }
            }

            $state['cursor']['last'] = $pkValue;
            $state['rows']++;
        }

        if (\count((array) $rows) < $this->batch) {
            $state['cursor']['t']++;
            $state['cursor']['last'] = null;
        }
    }

    /**
     * One batch of a table without a single-column primary key
     * (#__fields_values and many extension tables): distinct values.
     *
     * @param   array  $target
     * @param   array  &$state
     *
     * @return  void
     */
    protected function readByValue(array $target, array &$state)
    {
        $db     = $this->db;
        $column = $target['columns'][0];
        $binary = 'CAST(' . $db->quoteName($column) . ' AS BINARY)';
        $query  = 'SELECT DISTINCT ' . $binary . ' AS ' . $db->quoteName('v')
            . ' FROM ' . $db->quoteName($target['table'])
            . ' WHERE (' . $db->quoteName($column) . " LIKE '%/%' OR " . $db->quoteName($column) . " LIKE '%src=%')"
            . ($state['cursor']['last'] !== null ? ' AND ' . $binary . ' > ' . $db->quote($state['cursor']['last']) : '')
            . ' ORDER BY ' . $db->quoteName('v')
            . ' LIMIT ' . (int) $this->batch;

        try {
            $values = $db->setQuery($query)->loadColumn();
        } catch (\Throwable $e) {
            $values = [];
        }

        $isFields = substr($target['table'], \strlen($db->getPrefix())) === 'fields_values' && $column === 'value';

        foreach ($values as $value) {
            $value = (string) $value;

            foreach ($this->brokenIn($value) as $link) {
                $info = $isFields ? $this->describeFieldValue($target['table'], $value) : ['type' => '', 'title' => '', 'url' => '', 'state' => null];

                $this->collect($state, $target['table'], '', '', $column, $link, $info);
            }

            $state['cursor']['last'] = $value;
            $state['rows']++;
        }

        if (\count($values) < $this->batch) {
            $state['cursor']['t']++;
            $state['cursor']['last'] = null;
        }
    }

    /**
     * @param   array   &$state
     * @param   string  $table
     * @param   string  $pk
     * @param   string  $pkValue
     * @param   string  $column
     * @param   array   $link
     * @param   array   $info
     *
     * @return  void
     */
    protected function collect(array &$state, $table, $pk, $pkValue, $column, array $link, array $info)
    {
        if (\count($state['found']) >= $this->maxFound) {
            $state['full'] = true;

            return;
        }

        $state['found'][] = $link + [
            'table_name'     => $table,
            'pk_column'      => $pk,
            'pk_value'       => $pkValue,
            'column_name'    => $column,
            'location_type'  => $info['type'],
            'location_title' => $info['title'],
            'location_url'   => $info['url'],
            'item_state'     => $info['state'],
        ];
    }

    // ------------------------------------------------------------------
    // Reading one value
    // ------------------------------------------------------------------

    /**
     * All broken references in one stored value, one entry per distinct
     * reference: ['url', 'path', 'base', 'kind', 'element', 'popup',
     * 'inner_path', 'occurrences'].
     *
     * @param   string  $value
     *
     * @return  array
     */
    public function brokenIn($value)
    {
        $refs = [];

        foreach ($this->strings($value) as $string) {
            foreach ($this->extract($string) as $ref) {
                $key = $ref['raw'];

                if (isset($refs[$key])) {
                    $refs[$key]['occurrences']++;

                    if ($refs[$key]['element'] === '' && $ref['element'] !== '') {
                        $refs[$key] = ['occurrences' => $refs[$key]['occurrences']] + $ref;
                    }

                    continue;
                }

                $refs[$key] = $ref + ['occurrences' => 1];
            }
        }

        $broken = [];

        foreach ($refs as $raw => $ref) {
            $resolved = $this->resolve($raw);

            if ($resolved === null) {
                continue;
            }

            // Outside HTML (settings, fields, plain text) only paths of
            // media files count: "images/videos" or "media/com_x/upload/"
            // there is a folder setting, ".../forms/item.xml" internal
            // bookkeeping - not a link anyone sees.
            if (($ref['element'] === 'value' || $ref['element'] === '')
                && !preg_match('~\.(?:' . self::MEDIA_EXT . ')$~i', $resolved['rel'])
            ) {
                continue;
            }

            $kind = $this->kindOf($resolved);

            if ($kind === null) {
                continue;
            }

            $inner = '';

            if ($ref['inner'] !== '') {
                $innerResolved = $this->resolve($ref['inner']);

                if ($innerResolved !== null && $innerResolved['base'] === 'site' && $this->fileExists('site', $innerResolved['rel'])) {
                    $inner = $innerResolved['rel'];
                }
            }

            $broken[] = [
                'url'         => (string) $raw,
                'path'        => $resolved['rel'],
                'base'        => $resolved['base'],
                'kind'        => $kind,
                'element'     => $ref['element'],
                'popup'       => $ref['popup'] ? 1 : 0,
                'inner_path'  => $inner,
                'occurrences' => (int) $ref['occurrences'],
            ];
        }

        return $broken;
    }

    /**
     * The strings inside a stored value: the value itself, or every string
     * in it when it is JSON or PHP-serialized.
     *
     * @param   string  $value
     *
     * @return  string[]
     */
    public function strings($value)
    {
        $trim = ltrim($value);

        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $data = json_decode($value, true);

            if (\is_array($data)) {
                $out = [];

                array_walk_recursive($data, static function ($leaf) use (&$out) {
                    if (\is_string($leaf) && $leaf !== '') {
                        $out[] = $leaf;
                    }
                });

                return $out;
            }
        }

        $out        = [];
        $serialized = $this->rewriter->rewriteSerialized($value, function ($text) use (&$out) {
            foreach ($this->strings((string) $text) as $inner) {
                $out[] = $inner;
            }

            return $text;
        });

        return $serialized !== null ? $out : [$value];
    }

    /**
     * Candidate references in one string: [['raw', 'element', 'popup', 'inner'], ...]
     *
     * @param   string  $text
     *
     * @return  array
     */
    public function extract($text)
    {
        $found  = [];
        $masked = $text;

        if (strpos($text, '<') !== false && stripos($text, '=') !== false) {
            $pattern = '~\b(src|href|poster|data-[a-z0-9_-]*(?:src|href|image|img|url|background|bg))\s*=\s*(["\'])(.*?)\2~is';

            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $m) {
                    $raw = $m[3][0];

                    if (trim($raw) === '') {
                        continue;
                    }

                    [$element, $popup, $inner] = $this->tagContext($text, $m[0][1]);

                    $found[] = ['raw' => trim($raw), 'element' => $element, 'popup' => $popup, 'inner' => $inner];
                    $masked  = substr_replace($masked, str_repeat(' ', \strlen($m[0][0])), $m[0][1], \strlen($m[0][0]));
                }
            }
        }

        if (stripos($text, 'url(') !== false && preg_match_all('~url\(\s*(["\']?)([^"\')]+)\1\s*\)~i', $masked, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $found[] = ['raw' => trim($m[2][0]), 'element' => 'css', 'popup' => false, 'inner' => ''];
                $masked  = substr_replace($masked, str_repeat(' ', \strlen($m[0][0])), $m[0][1], \strlen($m[0][0]));
            }
        }

        // The whole value is one path (media field, link field, ...).
        // A path may hold spaces here ("images/Mijn foto.jpg"), so only
        // when the part before ?/# ends in a media extension, or has none.
        $whole = trim($text);

        if (!$found && $whole !== '' && \strlen($whole) <= 2048 && strpos($whole, '/') !== false && strpbrk($whole, "<>\"'\r\n\t") === false) {
            $pathPart = substr($whole, 0, strcspn($whole, '?#'));

            if ((strpos($pathPart, ' ') === false || preg_match('~\.(?:' . self::MEDIA_EXT . ')$~i', $pathPart)) && $this->resolve($whole) !== null) {
                return [['raw' => $whole, 'element' => 'value', 'popup' => false, 'inner' => '']];
            }
        }

        // Loose words with a media extension (settings, plain text) -
        // not inside a placeholder such as {loadposition ...} or [gallery ...].
        $masked = preg_replace_callback('~\{[^{}]*\}|\[[^\[\]]*\]~', static function ($m) {
            return str_repeat(' ', \strlen($m[0]));
        }, $masked);

        if (preg_match_all('#(?<![A-Za-z0-9_\-.%/~:@\\\\])((?:https?:)?(?://[A-Za-z0-9.\-]+(?::\d+)?)?/?[A-Za-z0-9_\-.~%]+(?:/[A-Za-z0-9_\-.~%]+)+\.(?:' . self::MEDIA_EXT . '))(?![A-Za-z0-9_\-])#i', $masked, $matches)) {
            foreach ($matches[1] as $raw) {
                $found[] = ['raw' => $raw, 'element' => '', 'popup' => false, 'inner' => ''];
            }
        }

        return $found;
    }

    /**
     * Which HTML element an attribute at $offset belongs to; for a link,
     * whether it is a pop-up link and the first image inside it.
     *
     * @param   string   $text
     * @param   integer  $offset
     *
     * @return  array  [element, popup, inner src]
     */
    protected function tagContext($text, $offset)
    {
        $open = strrpos(substr($text, 0, $offset), '<');

        if ($open === false || !preg_match('~\G<([a-zA-Z][a-zA-Z0-9]*)~', $text, $m, 0, $open)) {
            return ['', false, ''];
        }

        $element = strtolower($m[1]);

        if ($element !== 'a') {
            return [$element, false, ''];
        }

        $close = strpos($text, '>', $offset);
        $tag   = $close === false ? substr($text, $open) : substr($text, $open, $close - $open + 1);
        $popup = (bool) preg_match('~jcepopup|data-mediabox|jcemediabox|lightbox|fancybox|glightbox|data-lightbox|rel\s*=\s*["\'][^"\']*(?:lightbox|shadowbox|prettyphoto)~i', $tag);
        $inner = '';

        if ($close !== false) {
            $end  = stripos($text, '</a', $close);
            $body = $end === false ? '' : substr($text, $close + 1, $end - $close - 1);

            if ($body !== '' && preg_match('~<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1~is', $body, $img)) {
                $inner = trim($img[2]);
            }
        }

        return ['a', $popup, $inner];
    }

    /**
     * Turn a reference as written into a path of this site, or null when
     * it is not one (external, a page, a placeholder, ...).
     *
     * @param   string  $raw
     *
     * @return  array|null  ['base' => 'site'|'web', 'rel' => 'images/a.jpg']
     */
    public function resolve($raw)
    {
        $raw = trim((string) $raw);

        if ($raw === '' || preg_match('~^(?:#|mailto:|tel:|sms:|javascript:|data:|callto:|skype:|whatsapp:|ftp:|file:|about:|blob:)~i', $raw)) {
            return null;
        }

        // Placeholders ({loadposition}, [shortcode], $var) and Windows
        // paths (a folder setting such as "media/com_x\upload\") are no links.
        if (strpbrk($raw, '{}[]<>`$|^\\') !== false) {
            return null;
        }

        $path = substr($raw, 0, strcspn($raw, '?#'));

        if ($path === '') {
            return null;
        }

        $path = rawurldecode(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $path = str_replace('\\', '/', $path);

        if (preg_match('~^(?:(?:https?:)?//)([^/]+)(.*)$~i', $path, $m)) {
            $host = strtolower(preg_replace('~:\d+$~', '', $m[1]));

            if (!\in_array($host, $this->hosts, true)) {
                return null;
            }

            $path = $m[2] === '' ? '/' : $m[2];
        } elseif (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $path)) {
            return null;
        }

        $base = 'site';

        if ($path[0] === '/') {
            if ($this->basePath !== '' && ($path === $this->basePath || strpos($path, $this->basePath . '/') === 0)) {
                $path = substr($path, \strlen($this->basePath));
            } elseif ($this->basePath !== '') {
                if ($this->webRoot === null) {
                    return null;
                }

                $base = 'web';
            }
        }

        $rel = preg_replace('~/{2,}~', '/', $path);
        $rel = preg_replace('~^(?:\./)+~', '', ltrim($rel, '/'));

        if ($rel === '' || preg_match('~(?:^|/)\.\.?(?:/|$)~', $rel) || preg_match('~(?:^|/)index\.php~i', $rel)) {
            return null;
        }

        $slash   = strpos($rel, '/');
        $isMedia = (bool) preg_match('~\.(?:' . self::MEDIA_EXT . ')$~i', $rel);

        if ($slash === false) {
            // "logo.png" on its own: a file in the site root, but only
            // when it really looks like one.
            return $isMedia ? ['base' => $base, 'rel' => $rel] : null;
        }

        $first = substr($rel, 0, $slash);

        if (!$this->topDirExists($base, $first) && !($base === 'web' && $this->topDirExists('site', $first))) {
            return null;
        }

        return ['base' => $base, 'rel' => $rel];
    }

    /**
     * null = fine, 'missing' or 'outside'.
     *
     * @param   array  $resolved
     *
     * @return  string|null
     */
    protected function kindOf(array $resolved)
    {
        $exists = $this->fileExists($resolved['base'], $resolved['rel']);

        if ($resolved['base'] === 'site') {
            return $exists ? null : 'missing';
        }

        return $exists ? 'outside' : 'missing';
    }

    /**
     * @param   string  $base  'site' or 'web'
     * @param   string  $rel
     *
     * @return  boolean
     */
    public function fileExists($base, $rel)
    {
        $key = $base . "\0" . $rel;

        if (!isset($this->exists[$key])) {
            $abs                = $this->absolute($base, $rel);
            $this->exists[$key] = $abs !== null && (is_file($abs) || is_dir($abs));
        }

        return $this->exists[$key];
    }

    /**
     * @param   string  $base
     * @param   string  $rel
     *
     * @return  string|null
     */
    public function absolute($base, $rel)
    {
        $root = $base === 'web' ? $this->webRoot : rtrim(str_replace('\\', '/', JPATH_ROOT), '/');

        return $root === null ? null : $root . '/' . ltrim($rel, '/');
    }

    /**
     * @param   string  $base
     * @param   string  $segment
     *
     * @return  boolean
     */
    protected function topDirExists($base, $segment)
    {
        $key = $base . "\0" . $segment;

        if (!isset($this->topDirs[$key])) {
            $abs                 = $this->absolute($base, $segment);
            $this->topDirs[$key] = $abs !== null && is_dir($abs);
        }

        return $this->topDirs[$key];
    }

    /**
     * @return  string
     */
    public function getBasePath()
    {
        return $this->basePath;
    }

    /**
     * @return  boolean
     */
    public function hasWebRoot()
    {
        return $this->webRoot !== null;
    }

    // ------------------------------------------------------------------
    // Where a value lives
    // ------------------------------------------------------------------

    /**
     * Extra columns to read and a function making ['type', 'title',
     * 'url', 'state'] from a row, for tables people know by name.
     *
     * @param   string  $table  Full table name.
     *
     * @return  array  ['columns' => string[], 'fn' => callable]
     */
    protected function describer($table)
    {
        $short = substr($table, \strlen($this->db->getPrefix()));
        $map   = [
            'content'         => ['id', 'title', 'state', 'article', 'index.php?option=com_content&task=article.edit&id=%d'],
            'modules'         => ['id', 'title', 'published', 'module', 'index.php?option=com_modules&task=module.edit&id=%d'],
            'menu'            => ['id', 'title', 'published', 'menu', 'index.php?option=com_menus&task=item.edit&id=%d'],
            'categories'      => ['id', 'title', 'published', 'category', 'index.php?option=com_categories&task=category.edit&id=%d'],
            'contact_details' => ['id', 'name', 'published', 'contact', 'index.php?option=com_contact&task=contact.edit&id=%d'],
            'banners'         => ['id', 'name', 'state', 'banner', 'index.php?option=com_banners&task=banner.edit&id=%d'],
            'tags'            => ['id', 'title', 'published', 'tag', 'index.php?option=com_tags&task=tag.edit&id=%d'],
            'template_styles' => ['id', 'title', '', 'template', 'index.php?option=com_templates&task=style.edit&id=%d'],
            'users'           => ['id', 'name', '', 'user', 'index.php?option=com_users&task=user.edit&id=%d'],
            'extensions'      => ['extension_id', 'name', 'enabled', 'extension', ''],
        ];

        if (!isset($map[$short])) {
            return ['columns' => [], 'fn' => static function () {
                return ['type' => '', 'title' => '', 'url' => '', 'state' => null];
            }];
        }

        [$id, $title, $stateColumn, $type, $url] = $map[$short];

        $columns = array_values(array_filter([$id, $title, $stateColumn, $short === 'categories' ? 'extension' : '']));

        return ['columns' => $columns, 'fn' => static function (array $row) use ($id, $title, $stateColumn, $type, $url, $short) {
            $link = $url === '' ? '' : sprintf($url, (int) ($row[$id] ?? 0));

            if ($short === 'categories' && $link !== '') {
                $link .= '&extension=' . rawurlencode((string) ($row['extension'] ?? 'com_content'));
            }

            return [
                'type'  => $type,
                'title' => (string) ($row[$title] ?? ''),
                'url'   => $link,
                'state' => $stateColumn !== '' && isset($row[$stateColumn]) ? (int) $row[$stateColumn] : null,
            ];
        }];
    }

    /**
     * A custom field value: which field, which article.
     *
     * @param   string  $table
     * @param   string  $value
     *
     * @return  array
     */
    protected function describeFieldValue($table, $value)
    {
        $db = $this->db;

        try {
            $row = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['v.field_id', 'v.item_id', 'f.title', 'f.context']))
                    ->from($db->quoteName($table, 'v'))
                    ->join('LEFT', $db->quoteName('#__fields', 'f') . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('v.field_id'))
                    ->where('CAST(' . $db->quoteName('v.value') . ' AS BINARY) = ' . $db->quote($value))
                    ->setLimit(1)
            )->loadAssoc();
        } catch (\Throwable $e) {
            $row = null;
        }

        if (!$row) {
            return ['type' => 'field', 'title' => '', 'url' => '', 'state' => null];
        }

        $isArticle = ($row['context'] ?? '') === 'com_content.article' && is_numeric($row['item_id']);

        return [
            'type'  => 'field',
            'title' => Text::sprintf($isArticle ? 'COM_MEDIACLEANER_REF_FIELD_ARTICLE' : 'COM_MEDIACLEANER_REF_FIELD_GENERIC', (string) $row['title']),
            'url'   => $isArticle ? 'index.php?option=com_content&task=article.edit&id=' . (int) $row['item_id'] : '',
            'state' => null,
        ];
    }

    // ------------------------------------------------------------------
    // Suggestions
    // ------------------------------------------------------------------

    /**
     * Possible repairs for one broken link, best first:
     * [['path' => 'images/...', 'reason' => 'case'|'dot'|'ext'|'identical'|'elsewhere'|'thumb', 'score' => int], ...]
     * plus the index of the one to preselect (-1: none is sure enough).
     *
     * @param   array  $link   As made by brokenIn().
     * @param   array  $index  See buildIndex().
     *
     * @return  array  ['list' => array, 'best' => int]
     */
    public function suggest(array $link, array $index)
    {
        $rel   = $link['path'];
        $dir   = \dirname($rel) === '.' ? '' : \dirname($rel);
        $name  = basename($rel);
        $dot   = strrpos($name, '.');
        $stem  = $dot === false ? $name : substr($name, 0, $dot);
        $ext   = $dot === false ? '' : substr($name, $dot + 1);
        $list  = [];
        $add   = function ($path, $reason, $score) use (&$list, $rel, $link) {
            if ($path === $rel && $link['base'] === 'site') {
                return;
            }

            if (!$this->fileExists('site', $path) || is_dir((string) $this->absolute('site', $path))) {
                return;
            }

            if (!isset($list[$path]) || $list[$path]['score'] < $score) {
                $list[$path] = ['path' => $path, 'reason' => $reason, 'score' => $score];
            }
        };

        // 0. "/images/a.jpg" in a site in a subfolder, while this site
        //    itself has images/a.jpg: the base path was forgotten.
        if ($link['base'] === 'web') {
            $add($rel, 'insite', 97);
        }

        // 1. Same folder: other case, missing dot, other extension.
        foreach ($this->dirEntries($dir) as $entry) {
            if ($entry === $name) {
                continue;
            }

            $entryPath = ($dir === '' ? '' : $dir . '/') . $entry;
            $entryDot  = strrpos($entry, '.');
            $entryStem = $entryDot === false ? $entry : substr($entry, 0, $entryDot);
            $entryExt  = $entryDot === false ? '' : substr($entry, $entryDot + 1);

            if (strcasecmp($entry, $name) === 0) {
                $add($entryPath, 'case', 100);
            } elseif ($dot === false || !preg_match('~^(?:' . self::MEDIA_EXT . ')$~i', $ext)) {
                // "15-8jpg": the dot before the extension is missing.
                if ($entryDot !== false && strcasecmp($entryStem . $entryExt, $name) === 0) {
                    $add($entryPath, 'dot', 95);
                }
            } elseif (strcasecmp($entryStem, $stem) === 0 && \in_array($entryExt, self::SWAP_EXT, true) && \in_array($ext, self::SWAP_EXT, true)) {
                $add($entryPath, 'ext', 90);
            }
        }

        // Folder of another case ("Images/Foto.jpg" for "images/foto.jpg").
        foreach ($index['byPath'][strtolower($rel)] ?? [] as $path) {
            $add($path, 'case', 100);
        }

        // 2. Elsewhere on the site: same name, or same name with another
        //    image extension.
        $candidates = [];

        foreach ($index['byName'][strtolower($name)] ?? [] as $path) {
            $candidates[$path] = 0;
        }

        if (\in_array($ext, self::SWAP_EXT, true)) {
            foreach ($index['byStem'][strtolower($stem)] ?? [] as $path) {
                if (!isset($candidates[$path])) {
                    $candidates[$path] = -1;
                }
            }
        }

        if (\count($candidates) > 40) {
            // A name like "1.jpg" exists everywhere - not a useful hint.
            $candidates = [];
        }

        $source = $link['base'] === 'web' && $link['kind'] === 'outside' ? $this->absolute('web', $rel) : null;
        $tokens = $this->pathTokens($dir);

        foreach ($candidates as $path => $penalty) {
            if (!$this->fileExists('site', $path)) {
                continue;
            }

            if ($source !== null && $penalty === 0 && @filesize($source) === @filesize((string) $this->absolute('site', $path))
                && @sha1_file($source) === @sha1_file((string) $this->absolute('site', $path))
            ) {
                $add($path, 'identical', 98);
                continue;
            }

            $haystack = preg_replace('~[^a-z0-9]~', '', strtolower(\dirname($path)));
            $hits     = 0;

            foreach ($tokens as $token) {
                if (strpos($haystack, $token) !== false) {
                    $hits++;
                }
            }

            $add($path, 'elsewhere', min(84, 50 + 8 * $hits + 10 * $penalty));
        }

        // 3. The image inside the pop-up link itself.
        if ($link['inner_path'] !== '' && $link['element'] === 'a') {
            $add($link['inner_path'], 'thumb', 20);
        }

        $list = array_values($list);

        usort($list, static function ($a, $b) {
            return $b['score'] <=> $a['score'] ?: strcmp($a['path'], $b['path']);
        });

        $list = \array_slice($list, 0, 6);
        $best = -1;

        if ($list) {
            $top    = $list[0];
            $second = $list[1]['score'] ?? 0;
            $sure   = \in_array($top['reason'], ['case', 'dot', 'identical', 'insite'], true)
                || ($top['reason'] === 'ext' && $second < 90)
                || ($top['reason'] === 'elsewhere' && $top['score'] >= 66 && $second <= $top['score'] - 8)
                // The only file of that name on the whole site, in a
                // folder that looks like the one linked to.
                || ($top['reason'] === 'elsewhere' && $top['score'] >= 58 && \count(array_filter($list, static function ($s) {
                    return $s['reason'] === 'elsewhere';
                })) === 1);

            $best = $sure ? 0 : -1;
        }

        return ['list' => $list, 'best' => $best];
    }

    /**
     * Words of a folder path that are worth comparing ("2026", "verslag",
     * "polota"), lower case.
     *
     * @param   string  $dir
     *
     * @return  string[]
     */
    protected function pathTokens($dir)
    {
        $tokens = [];

        foreach (preg_split('~[^a-z0-9]+~', strtolower($dir)) as $token) {
            if ($token !== '' && (\strlen($token) >= 3 || ctype_digit($token)) && $token !== 'images') {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @param   string  $dir  Relative to the site root.
     *
     * @return  string[]
     */
    protected function dirEntries($dir)
    {
        if (!isset($this->dirCache[$dir])) {
            $abs   = rtrim(str_replace('\\', '/', JPATH_ROOT), '/') . ($dir === '' ? '' : '/' . $dir);
            $items = is_dir($abs) ? @scandir($abs) : false;

            $this->dirCache[$dir] = $items === false ? [] : array_values(array_diff($items, ['.', '..']));
        }

        return $this->dirCache[$dir];
    }

    /**
     * Files of the site by lower-case name, from the last scan plus what
     * Verkleinen made since (new names are not in the scan until the next
     * one).
     *
     * @return  array  ['byName' => [...], 'byStem' => [...], 'byPath' => [...]]
     */
    public function buildIndex()
    {
        $db    = $this->db;
        $paths = [];

        try {
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['path', 'name']))
                    ->from($db->quoteName('#__mediacleaner_files'))
            )->loadRowList();

            foreach ($rows as [$path, $name]) {
                $paths[] = ltrim(trim((string) $path, '/') . '/' . $name, '/');
            }
        } catch (\Throwable $e) {
            // No scan yet.
        }

        try {
            $paths = array_merge($paths, $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('new_path'))
                    ->from($db->quoteName('#__mediacleaner_compress'))
                    ->where($db->quoteName('status') . ' = ' . $db->quote('done'))
                    ->where($db->quoteName('new_path') . " <> ''")
            )->loadColumn());
        } catch (\Throwable $e) {
            // Nothing made smaller yet.
        }

        $index = ['byName' => [], 'byStem' => [], 'byPath' => []];

        foreach (array_unique($paths) as $path) {
            $name = basename($path);
            $dot  = strrpos($name, '.');

            $index['byName'][strtolower($name)][] = $path;
            $index['byPath'][strtolower($path)][] = $path;

            if ($dot !== false && \in_array(substr($name, $dot + 1), self::SWAP_EXT, true)) {
                $index['byStem'][strtolower(substr($name, 0, $dot))][] = $path;
            }
        }

        return $index;
    }

    // ------------------------------------------------------------------
    // Result
    // ------------------------------------------------------------------

    /**
     * Write the result to `#__mediacleaner_links`.
     *
     * @param   array  $state
     *
     * @return  integer  Number of broken links (not counting ignored ones).
     */
    protected function store(array $state)
    {
        $db      = $this->db;
        $now     = Factory::getDate()->toSql();
        $index   = $this->buildIndex();
        $ignored = [];

        try {
            $ignored = array_flip($db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('hash'))
                    ->from($db->quoteName('#__mediacleaner_links_ignored'))
            )->loadColumn());
        } catch (\Throwable $e) {
            $ignored = [];
        }

        $db->truncateTable('#__mediacleaner_links');

        $count = 0;
        $cache = [];

        foreach ($state['found'] as $link) {
            $hash = self::hash($link['table_name'], $link['pk_column'], $link['pk_value'], $link['column_name'], $link['url']);
            $key  = $link['base'] . "\0" . $link['path'] . "\0" . $link['kind'] . "\0" . $link['inner_path'] . "\0" . $link['element'];

            if (!isset($cache[$key])) {
                $cache[$key] = $this->suggest($link, $index);
            }

            $record = (object) [
                'hash'           => $hash,
                'table_name'     => $link['table_name'],
                'pk_column'      => $link['pk_column'],
                'pk_value'       => $link['pk_value'],
                'column_name'    => $link['column_name'],
                'url'            => mb_substr($link['url'], 0, 2048),
                'path'           => mb_substr($link['path'], 0, 1024),
                'base'           => $link['base'],
                'kind'           => $link['kind'],
                'element'        => mb_substr($link['element'], 0, 20),
                'popup'          => (int) $link['popup'],
                'inner_path'     => mb_substr($link['inner_path'], 0, 1024),
                'occurrences'    => (int) $link['occurrences'],
                'location_type'  => $link['location_type'],
                'location_title' => mb_substr($link['location_title'], 0, 255),
                'location_url'   => mb_substr($link['location_url'], 0, 512),
                'item_state'     => $link['item_state'],
                'suggestions'    => json_encode($cache[$key]['list'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'best'           => (int) $cache[$key]['best'],
                'ignored'        => isset($ignored[$hash]) ? 1 : 0,
                'found_at'       => $now,
            ];

            $db->insertObject('#__mediacleaner_links', $record);

            if (!$record->ignored) {
                $count++;
            }
        }

        StorageLocator::setState('links_checked', $now);
        StorageLocator::setState('links_checked_full', $state['full'] ? '1' : '0');

        return $count;
    }

    /**
     * @param   string  $table
     * @param   string  $pk
     * @param   string  $pkValue
     * @param   string  $column
     * @param   string  $url
     *
     * @return  string
     */
    public static function hash($table, $pk, $pkValue, $column, $url)
    {
        return sha1($table . "\0" . $pk . "\0" . $pkValue . "\0" . $column . "\0" . $url);
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
        $total = max(1, \count($state['targets']));
        $at    = min($state['cursor']['t'], $total);

        return [
            'jobId'   => $state['id'],
            'done'    => $done,
            'percent' => $done ? 100 : max(1, min(99, (int) floor(100 * $at / $total))),
            'label'   => Text::sprintf('COM_MEDIACLEANER_LINKS_PROGRESS', $at, $total, \count($state['found'])),
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

        return rtrim($dir, '/\\') . '/mediacleaner_links_' . $id . '.state';
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
