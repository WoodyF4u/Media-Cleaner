<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;

/**
 * Decides where Media Cleaner keeps the files it takes off the site
 * (v2.9.0): the quarantine ("Tijdelijk verwijderd") and the originals of
 * files that were made smaller on the Verkleinen tab.
 *
 * Until v2.8.3 the quarantine lived in
 * administrator/components/com_mediacleaner/quarantine - a folder Joomla
 * deletes, contents and all, when the component is uninstalled. Now one
 * storage root holds both, in this order of preference:
 *
 *   1. The folder set in Opties -> Bewaarmap, if any.
 *   2. Outside the web root, next to it: "mediacleaner-<site>". Nothing
 *      in there can ever be requested by a browser, and it is not part of
 *      a backup made of the site itself (which is the whole point of
 *      making files smaller).
 *   3. "mediacleaner" in the Joomla root, shielded by .htaccess (Apache,
 *      LiteSpeed), web.config (IIS) and empty index.html files. Those
 *      only work on servers that read them - isWebAccessible() checks
 *      whether the shielding really works, so the person can be warned.
 *
 * Whichever root is chosen the first time is remembered (in
 * `#__mediacleaner_state`, name `storage_root`), so it never silently
 * moves to another place later - e.g. because a CLI request sees a
 * different DOCUMENT_ROOT.
 *
 * The three protection files have fixed contents, byte for byte the same
 * in every version and on every site, so a monitoring tool can recognise
 * them by checksum (PROTECTION_FILES).
 */
class StorageLocator
{
    /**
     * Fixed contents - never change these: monitoring tools may check
     * them by checksum.
     */
    public const PROTECTION_FILES = [
        '.htaccess'  => "# Media Cleaner - no direct web access to this folder.\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order allow,deny\n"
            . "    Deny from all\n"
            . "</IfModule>\n",
        'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<configuration>\n"
            . "    <system.webServer>\n"
            . "        <authorization>\n"
            . "            <deny users=\"*\" />\n"
            . "        </authorization>\n"
            . "    </system.webServer>\n"
            . "</configuration>\n",
        'index.html' => "<!DOCTYPE html><title></title>\n",
    ];

    public const QUARANTINE = 'quarantine';
    public const ORIGINALS  = 'originals';

    /**
     * Where the quarantine lived up to v2.8.3, relative to JPATH_ROOT.
     */
    public const LEGACY_QUARANTINE = 'administrator/components/com_mediacleaner/quarantine';

    /**
     * @var string|null
     */
    protected static $root = null;

    /**
     * Absolute path of the storage root, created and protected. Throws
     * when no writable place could be found at all.
     *
     * @return  string
     *
     * @throws  \RuntimeException
     */
    public function getRoot()
    {
        if (self::$root !== null) {
            return self::$root;
        }

        $params     = ComponentHelper::getParams('com_mediacleaner');
        $configured = trim((string) $params->get('storage_path', ''));
        $remembered = trim((string) self::getState('storage_root', ''));
        $wasAuto    = self::getState('storage_root_mode', 'auto') !== 'configured';
        $candidates = [];
        $site       = $this->normalise(JPATH_ROOT);
        $rememberedFor = (string) self::getState('storage_root_site', '');

        if ($remembered !== '' && $rememberedFor !== '' && $rememberedFor !== $site) {
            // This database was copied from another site (Akeeba, a test
            // copy, a move to another domain): the remembered folder
            // belongs to THAT site. Never use it, never move files out of
            // it - this site gets a folder of its own.
            $remembered = '';
        }

        if ($configured !== '') {
            $candidates[] = $this->normalise($configured);
        } else {
            // A folder chosen automatically before stays the folder; one
            // that was set in Opties (and is now cleared) does not.
            if ($remembered !== '' && $wasAuto) {
                $candidates[] = $remembered;
            }

            $outside = $this->getOutsideWebrootCandidate();

            if ($outside !== null) {
                $candidates[] = $outside;
            }

            $candidates[] = $this->normalise(JPATH_ROOT) . '/mediacleaner';
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($this->isUnsafeLocation($candidate)) {
                continue;
            }

            if ($this->prepare($candidate)) {
                self::$root = $candidate;

                if ($candidate !== $remembered) {
                    // The storage folder changed (setting in Opties):
                    // bring along what was kept in the previous one, so
                    // nothing becomes unrestorable.
                    if ($remembered !== '' && is_dir($remembered)) {
                        $this->migrateRoot($remembered, $candidate);
                    }

                    self::setState('storage_root', $candidate);
                }

                $mode = $configured !== '' ? 'configured' : 'auto';

                if (self::getState('storage_root_mode', '') !== $mode) {
                    self::setState('storage_root_mode', $mode);
                }

                if ($rememberedFor !== $site) {
                    self::setState('storage_root_site', $site);
                }

                return $candidate;
            }
        }

        throw new \RuntimeException('Media Cleaner: no writable storage folder.');
    }

    /**
     * The storage root if it was ever resolved, without creating
     * anything - for the scanner, which must skip it but should not make
     * folders just by scanning.
     *
     * @return  string[]  Absolute paths that are (or could be) a storage root.
     */
    public static function getKnownRoots()
    {
        $roots  = [rtrim(str_replace('\\', '/', JPATH_ROOT), '/') . '/mediacleaner'];
        $values = [
            (string) ComponentHelper::getParams('com_mediacleaner')->get('storage_path', ''),
            (string) self::getState('storage_root', ''),
        ];

        foreach ($values as $value) {
            $value = trim($value);

            if ($value !== '') {
                $roots[] = rtrim(str_replace('\\', '/', $value), '/');
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @return  string  Absolute path of the quarantine folder (created).
     */
    public function getQuarantineDir()
    {
        $dir = $this->getRoot() . '/' . self::QUARANTINE;

        $this->prepare($dir);
        $this->migrateLegacyQuarantine($dir);

        return $dir;
    }

    /**
     * @return  string  Absolute path of the folder with originals (created).
     */
    public function getOriginalsDir()
    {
        $dir = $this->getRoot() . '/' . self::ORIGINALS;

        $this->prepare($dir);

        return $dir;
    }

    /**
     * Whether the storage root sits inside the folder the web server
     * serves.
     *
     * @return  boolean
     */
    public function isInsideWebroot()
    {
        $root = $this->getRoot() . '/';

        foreach ([$this->getDocumentRoot(), $this->normalise(JPATH_ROOT)] as $webroot) {
            if ($webroot !== '' && strpos($root, $webroot . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * For a storage root inside the web root: can a browser fetch the
     * index.html in it? Checked over HTTP at most once a day (result
     * kept in `#__mediacleaner_state`). null when it could not be checked.
     *
     * @param   boolean  $force  Check now, ignoring the remembered result.
     *
     * @return  boolean|null
     */
    public function isWebAccessible($force = false)
    {
        if (!$this->isInsideWebroot()) {
            return false;
        }

        $checked = (int) self::getState('storage_web_checked', 0);

        if (!$force && $checked > time() - 86400 && self::getState('storage_web_root', '') === $this->getRoot()) {
            $value = (string) self::getState('storage_web_accessible', '');

            return $value === '' ? null : (bool) $value;
        }

        $relative = substr($this->getRoot(), \strlen($this->normalise(JPATH_ROOT)) + 1);

        if ($relative === false || $relative === '' || strpos($this->getRoot(), $this->normalise(JPATH_ROOT) . '/') !== 0) {
            // Inside the document root but outside Joomla: no URL to test.
            return null;
        }

        $url    = rtrim(Uri::root(), '/') . '/' . str_replace('%2F', '/', rawurlencode($relative)) . '/index.html';
        $result = null;

        try {
            $response = HttpFactory::getHttp()->get($url, [], 5);
            $result   = (int) $response->code === 200;
        } catch (\Throwable $e) {
            $result = null;
        }

        self::setState('storage_web_checked', (string) time());
        self::setState('storage_web_root', $this->getRoot());
        self::setState('storage_web_accessible', $result === null ? '' : (string) (int) $result);

        return $result;
    }

    /**
     * Free disk space in the storage root, or null when unknown.
     *
     * @return  float|null
     */
    public function getFreeSpace()
    {
        $free = @disk_free_space($this->getRoot());

        return $free === false ? null : (float) $free;
    }

    /**
     * Move the contents of an earlier storage root into the new one.
     * `quarantine_path` and `original_stored` in the database are
     * relative to their folder, so the rows stay valid. Whatever cannot
     * be moved (name already taken) stays where it was.
     *
     * @param   string  $from
     * @param   string  $to
     *
     * @return  void
     */
    protected function migrateRoot($from, $to)
    {
        foreach ([self::QUARANTINE, self::ORIGINALS] as $sub) {
            $source = $from . '/' . $sub;

            if (!is_dir($source)) {
                continue;
            }

            $target = $to . '/' . $sub;

            if (!$this->prepare($target)) {
                continue;
            }

            $this->mergeInto($source, $target);
        }
    }

    /**
     * @param   string  $source
     * @param   string  $target
     *
     * @return  void
     */
    protected function mergeInto($source, $target)
    {
        foreach ((array) @scandir($source) as $entry) {
            if ($entry === '.' || $entry === '..' || isset(self::PROTECTION_FILES[$entry])) {
                continue;
            }

            $from = $source . '/' . $entry;
            $to   = $target . '/' . $entry;

            if (!file_exists($to)) {
                $this->move($from, $to);
            } elseif (is_dir($from) && is_dir($to)) {
                $this->mergeInto($from, $to);
                @rmdir($from);
            }
        }
    }

    /**
     * Moves quarantined files from the pre-v2.9.0 location into $target.
     * `quarantine_path` in the database is relative to the quarantine
     * root, so the rows stay valid as they are. Safe to call repeatedly.
     *
     * @param   string  $target
     *
     * @return  void
     */
    public function migrateLegacyQuarantine($target)
    {
        $legacy = $this->normalise(JPATH_ROOT) . '/' . self::LEGACY_QUARANTINE;

        if ($legacy === $target || !is_dir($legacy)) {
            return;
        }

        $entries = @scandir($legacy);

        if ($entries === false) {
            return;
        }

        $left = 0;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || isset(self::PROTECTION_FILES[$entry])) {
                continue;
            }

            $from = $legacy . '/' . $entry;
            $to   = $target . '/' . $entry;

            if (file_exists($to) || !$this->move($from, $to)) {
                $left++;
            }
        }

        if ($left === 0) {
            foreach (array_keys(self::PROTECTION_FILES) as $file) {
                @unlink($legacy . '/' . $file);
            }

            @rmdir($legacy);
        }
    }

    /**
     * Rename, or copy-and-delete when the two are on different disks.
     * Works for files and folders.
     *
     * @param   string  $from
     * @param   string  $to
     *
     * @return  boolean
     */
    public function move($from, $to)
    {
        if (@rename($from, $to)) {
            return true;
        }

        if (is_dir($from)) {
            if (!@mkdir($to, 0755, true) && !is_dir($to)) {
                return false;
            }

            foreach ((array) @scandir($from) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                if (!$this->move($from . '/' . $entry, $to . '/' . $entry)) {
                    return false;
                }
            }

            return @rmdir($from);
        }

        if (@copy($from, $to) && @filesize($to) === @filesize($from)) {
            return @unlink($from);
        }

        @unlink($to);

        return false;
    }

    /**
     * Create $dir if needed, check it is writable, and drop the fixed
     * protection files in it.
     *
     * @param   string  $dir
     *
     * @return  boolean
     */
    protected function prepare($dir)
    {
        if (!@is_dir($dir) && !@mkdir($dir, 0755, true) && !@is_dir($dir)) {
            return false;
        }

        if (!@is_writable($dir)) {
            return false;
        }

        foreach (self::PROTECTION_FILES as $name => $contents) {
            $file = $dir . '/' . $name;

            if (!is_file($file) || @file_get_contents($file) !== $contents) {
                @file_put_contents($file, $contents);
            }
        }

        return is_file($dir . '/index.html');
    }

    /**
     * "mediacleaner-<site>" next to the web root, or null when the web
     * root cannot be determined. The name includes the Joomla folder and
     * a short hash of its full path, so several Joomla sites under one
     * web root (or one account) never share a folder.
     *
     * @return  string|null
     */
    protected function getOutsideWebrootCandidate()
    {
        $docroot = $this->getDocumentRoot();
        $joomla  = $this->normalise(JPATH_ROOT);

        if ($docroot === '' || ($joomla !== $docroot && strpos($joomla . '/', $docroot . '/') !== 0)) {
            // Joomla is not inside the document root we were told about
            // (CLI, unusual setups): its own parent folder is the best guess.
            $docroot = $joomla;
        }

        $parent = \dirname($docroot);

        if ($parent === $docroot || $parent === '' || $parent === '/' || $parent === '.') {
            return null;
        }

        $label = $joomla === $docroot ? basename($docroot) : str_replace('/', '-', substr($joomla, \strlen($docroot) + 1));
        $label = strtolower(trim(preg_replace('~[^A-Za-z0-9_-]+~', '-', $label), '-'));

        return $parent . '/mediacleaner-' . ($label !== '' ? $label . '-' : '') . substr(md5($joomla), 0, 6);
    }

    /**
     * Refuses folders that are part of Joomla itself or the media
     * library: a storage root there would be scanned, served or
     * overwritten.
     *
     * @param   string  $dir
     *
     * @return  boolean
     */
    protected function isUnsafeLocation($dir)
    {
        $joomla = $this->normalise(JPATH_ROOT);

        if ($dir === '' || $dir === $joomla || strpos($joomla . '/', $dir . '/') === 0) {
            return true;
        }

        foreach (['images', 'media', 'administrator', 'components', 'modules', 'plugins', 'templates', 'libraries', 'cache', 'tmp', 'language', 'layouts', 'api', 'cli', 'includes'] as $core) {
            if (strpos($dir . '/', $joomla . '/' . $core . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return  string  Document root without trailing slash, '' if unknown.
     */
    protected function getDocumentRoot()
    {
        $docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

        if ($docroot === '') {
            return '';
        }

        $real = @realpath($docroot);

        return $this->normalise($real !== false ? $real : $docroot);
    }

    /**
     * @param   string  $path
     *
     * @return  string
     */
    protected function normalise($path)
    {
        return rtrim(str_replace('\\', '/', (string) $path), '/');
    }

    /**
     * Small key/value store of the component's own (v2.9.0). Not the
     * component params: saving Opties replaces those completely, which
     * would make Media Cleaner forget where it keeps its files.
     *
     * @param   string  $name
     * @param   mixed   $default
     *
     * @return  mixed
     */
    public static function getState($name, $default = null)
    {
        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $value = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('value'))
                    ->from($db->quoteName('#__mediacleaner_state'))
                    ->where($db->quoteName('name') . ' = ' . $db->quote($name))
            )->loadResult();

            return $value === null ? $default : $value;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * @param   string  $name
     * @param   string  $value
     *
     * @return  void
     */
    public static function setState($name, $value)
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $db->setQuery(
                'REPLACE INTO ' . $db->quoteName('#__mediacleaner_state')
                . ' (' . $db->quoteName('name') . ', ' . $db->quoteName('value') . ')'
                . ' VALUES (' . $db->quote($name) . ', ' . $db->quote((string) $value) . ')'
            )->execute();
        } catch (\Throwable $e) {
            // Best effort.
        }
    }
}
