<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Converts a single scanned file to a new `.webp` file placed alongside
 * the original, using a fixed quality. The original file is never
 * touched, overwritten or deleted - this only ever adds a new file.
 * jpg/jpeg/png go through the GD extension; tif/tiff needs the Imagick
 * PHP extension.
 *
 * v1.37.0: extracted out of FilesModel.php as part of a deliberate split
 * into single-purpose classes - Scanner, QuarantineManager,
 * WebpConverter. Structural-only change: no behaviour was altered, every
 * method body was moved verbatim.
 *
 * This class does NOT check permissions itself - same as the model
 * method it was extracted from, that's the caller's responsibility
 * (FilesModel::convertToWebp()).
 */
class WebpConverter
{
    /**
     * File types this class can convert to WebP.
     *
     * @var array
     */
    protected $convertibleTypes = ['jpg', 'jpeg', 'png', 'tif', 'tiff'];

    /**
     * WebP compression quality (0-100) used for every conversion.
     *
     * @var integer
     */
    protected $quality = 80;

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
     * Whether a given file type can be converted to WebP by this class.
     *
     * @param   string  $type
     *
     * @return  boolean
     */
    public function isConvertible($type)
    {
        return in_array(strtolower($type), $this->convertibleTypes, true);
    }

    /**
     * No permission check of its own - the caller (FilesModel::
     * convertToWebp()) is responsible for authorising the request first.
     *
     * @param   integer  $id  Id from `#__mediacleaner_files`.
     *
     * @return  array  ['success' => bool, ...] - see inline reason codes below.
     */
    public function convert($id)
    {
        $id = (int) $id;

        if ($id <= 0) {
            return ['success' => false, 'reason' => 'invalid'];
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__mediacleaner_files'))
            ->where($db->quoteName('id') . ' = ' . $id);

        $db->setQuery($query);

        try {
            $row = $db->loadAssoc();
        } catch (\Exception $e) {
            return ['success' => false, 'reason' => 'not_found'];
        }

        if (!$row) {
            return ['success' => false, 'reason' => 'not_found'];
        }

        $type = strtolower($row['type']);

        if (!$this->isConvertible($type)) {
            return ['success' => false, 'reason' => 'unsupported_type'];
        }

        $relativeDir = trim($row['path'], '/');
        $source      = JPATH_ROOT . '/' . $relativeDir . '/' . $row['name'];

        if (!is_file($source)) {
            return ['success' => false, 'reason' => 'missing'];
        }

        $baseName    = pathinfo($row['name'], PATHINFO_FILENAME);
        $webpName    = $baseName . '.webp';
        $destination = JPATH_ROOT . '/' . $relativeDir . '/' . $webpName;

        if (is_file($destination)) {
            return ['success' => false, 'reason' => 'exists', 'webp_name' => $webpName];
        }

        try {
            if (in_array($type, ['tif', 'tiff'], true)) {
                if (!class_exists('Imagick')) {
                    return ['success' => false, 'reason' => 'no_imagick'];
                }

                $image = new \Imagick($source);
                $image->setImageFormat('webp');
                $image->setImageCompressionQuality($this->quality);
                $image->writeImage($destination);
                $image->destroy();
            } else {
                if (!function_exists('imagewebp')) {
                    return ['success' => false, 'reason' => 'no_gd'];
                }

                $image = $type === 'png' ? @imagecreatefrompng($source) : @imagecreatefromjpeg($source);

                if (!$image) {
                    return ['success' => false, 'reason' => 'read_failed'];
                }

                // Preserve transparency where present (e.g. PNG).
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);

                $written = imagewebp($image, $destination, $this->quality);
                imagedestroy($image);

                if (!$written) {
                    return ['success' => false, 'reason' => 'write_failed'];
                }
            }
        } catch (\Exception $e) {
            return ['success' => false, 'reason' => 'exception'];
        }

        if (!is_file($destination)) {
            return ['success' => false, 'reason' => 'write_failed'];
        }

        $webpSize = (int) @filesize($destination);

        return [
            'success'      => true,
            'webp_name'    => $webpName,
            'webp_size_kb' => $webpSize / 1024,
            'original_kb'  => ((int) $row['size']) / 1024,
        ];
    }
}
