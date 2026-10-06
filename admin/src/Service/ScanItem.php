<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

/**
 * One scanned media file, as passed around inside Scanner and handed to
 * FilesModel::rescan() for persistence.
 *
 * v2.8.3: until now every scanned file was a plain PHP array with ~25
 * string keys. A PHP array of that shape costs well over 1 KB per file
 * before any of its string values are counted, and every pass in
 * Scanner::scan() that touched each item (`foreach ($items as &$item)`)
 * briefly held a second full copy of all of them. On a site with a few
 * hundred thousand media files that alone exhausted a 512 MB
 * memory_limit (first seen on niburu.co).
 *
 * An object with declared properties stores the same data in roughly a
 * third of the memory and is never duplicated when passed around. It
 * implements ArrayAccess with exactly the old key names, so all existing
 * code - `$item['linked']`, `$items[$idx]['ignored'] = true`,
 * `empty($item['derivedStatus'])` - keeps working unchanged.
 *
 * Three former keys are no longer stored per file but computed on
 * request, since they follow directly from other fields:
 * - 'sizeKB'     = size / 1024
 * - 'url'        = site root + url-encoded path + name
 * - 'modifiedAt' = `mtime` formatted as "Y-m-d H:i:s" (null if unknown)
 */
#[\AllowDynamicProperties]
final class ScanItem implements \ArrayAccess
{
    /**
     * Site root URL without trailing slash, set once per scan by
     * Scanner::scanFilesystem() - shared by every item rather than
     * repeated inside each one's own url string.
     *
     * @var string
     */
    public static $urlRoot = '';

    /** @var string File name, original casing. */
    public $name = '';

    /** @var string Folder relative to JPATH_ROOT: leading slash, no trailing slash, '/' for the root. */
    public $path = '/';

    /** @var integer Size in bytes. */
    public $size = 0;

    /** @var string Lowercased extension ('tiff' normalised to 'tif'). */
    public $type = '';

    /** @var integer|null Filesystem modification time (unix timestamp), null if unreadable. */
    public $mtime = null;

    public $noPreview                  = false;
    public $isThumbsDir                = false;
    public $isSystemAssetDir           = false;
    public $likelyManualUpload         = false;
    public $linked                     = false;
    public $linkConfidence             = 'none';
    public $derivedFrom                = null;
    public $derivedStatus              = null;
    public $ignored                    = false;
    public $isActiveExtensionAsset     = false;
    public $isActiveExtensionImagesDir = false;
    public $possiblyOrphanedExtension  = false;
    public $imagesDirExtensionName     = null;
    public $imagesDirExtensionRemoved  = false;
    public $assetDirExtensionName      = null;
    public $assetDirExtensionRemoved   = false;

    /**
     * Path relative to JPATH_ROOT without a leading slash, e.g.
     * "images/headers/hero.jpg" - the form used as key in
     * `#__mediacleaner_ignored` / `#__mediacleaner_references` and when
     * searching content for a reference.
     *
     * @return  string
     */
    public function relativePath()
    {
        return trim($this->path, '/') . '/' . $this->name;
    }

    public function offsetExists(mixed $offset): bool
    {
        switch ($offset) {
            case 'sizeKB':
            case 'url':
                return true;

            case 'modifiedAt':
                return $this->mtime !== null;
        }

        return isset($this->$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        switch ($offset) {
            case 'sizeKB':
                return $this->size / 1024;

            case 'url':
                $relative = ltrim($this->relativePath(), '/');

                return self::$urlRoot . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));

            case 'modifiedAt':
                return $this->mtime !== null ? date('Y-m-d H:i:s', $this->mtime) : null;
        }

        return $this->$offset ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        switch ($offset) {
            case 'sizeKB':
            case 'url':
                // Derived values - nothing to store.
                return;

            case 'modifiedAt':
                $timestamp   = $value === null || $value === '' ? false : strtotime((string) $value);
                $this->mtime = $timestamp === false ? null : $timestamp;

                return;
        }

        $this->$offset = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        if ($offset === 'modifiedAt') {
            $this->mtime = null;

            return;
        }

        if ($offset !== 'sizeKB' && $offset !== 'url') {
            $this->$offset = null;
        }
    }
}
