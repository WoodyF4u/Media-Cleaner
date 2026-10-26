<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Makes one file smaller (v2.9.0, the Verkleinen tab). Image handling
 * taken over from the stand-alone "media-verkleinen" script, with WebP
 * added as a target format.
 *
 * Two kinds of result:
 *
 *  - In place (JPG made smaller, PDF made smaller): the new file takes
 *    the old file's name, so nothing in the database needs to change.
 *    The original is moved to the originals folder right away.
 *    Result status 'done'.
 *
 *  - Converted (PNG -> JPG, JPG/PNG -> WebP): the new file is written
 *    NEXT TO the original, which stays where it is for now - the site
 *    keeps working while CompressJob updates the references in the
 *    database. Only after that is the original moved away
 *    (moveOriginal()). Result status 'converted'.
 *
 * Nothing here checks permissions; CompressJob's caller does.
 */
class Compressor
{
    /**
     * @var array  Settings, see __construct().
     */
    protected $settings;

    /**
     * @var string  Absolute path of the originals folder.
     */
    protected $originalsDir;

    /**
     * @param   array   $settings      max_pixels, jpg_quality, webp_quality, pdf_dpi, pdf_quality, use_ghostscript
     * @param   string  $originalsDir  Absolute path, see StorageLocator::getOriginalsDir().
     */
    public function __construct(array $settings, $originalsDir)
    {
        $this->settings = $settings + [
            'max_pixels'      => 2400,
            'jpg_quality'     => 85,
            'webp_quality'    => 80,
            'pdf_dpi'         => 200,
            'pdf_quality'     => 80,
            'use_ghostscript' => 1,
        ];

        $this->originalsDir = rtrim($originalsDir, '/');
    }

    /**
     * What this server can do.
     *
     * @return  array  ['gd' => bool, 'webp' => bool, 'exif' => bool, 'ghostscript' => bool, 'zip' => bool]
     */
    public static function capabilities()
    {
        $gd = \function_exists('gd_info') ? gd_info() : [];

        return [
            'gd'          => !empty($gd['JPEG Support']) && !empty($gd['PNG Support']),
            'webp'        => !empty($gd['WebP Support']) && \function_exists('imagewebp'),
            'exif'        => \function_exists('exif_read_data'),
            'ghostscript' => self::ghostscript() !== null,
            'zip'         => class_exists('ZipArchive'),
        ];
    }

    /**
     * @param   string  $relative  Path relative to JPATH_ROOT, no leading slash.
     * @param   array   $choices   ['jpg' => 'resize'|'webp'|'skip', 'png' => 'jpg'|'webp'|'skip',
     *                             'png_alpha' => 'skip'|'webp', 'pdf' => 'resize'|'skip']
     *
     * @return  array  status ('done'|'converted'|'skipped'), action, new_path, old_size, new_size,
     *                 old_px, new_px, info, reason, original_stored
     */
    public function process($relative, array $choices)
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $abs      = JPATH_ROOT . '/' . $relative;
        $ext      = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $type     = $ext === 'jpeg' ? 'jpg' : $ext;
        $base     = [
            'action'          => '',
            'file_type'       => $type,
            'new_path'        => '',
            'old_size'        => is_file($abs) ? (int) filesize($abs) : 0,
            'new_size'        => 0,
            'old_px'          => '',
            'new_px'          => '',
            'info'            => '',
            'reason'          => '',
            'original_stored' => '',
        ];

        if (!is_file($abs)) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_MISSING');
        }

        if (!is_writable(\dirname($abs))) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_NOT_WRITABLE');
        }

        try {
            switch ($type) {
                case 'jpg':
                    $choice = $choices['jpg'] ?? 'resize';

                    if ($choice === 'webp') {
                        return $this->toWebp($relative, $base, IMAGETYPE_JPEG);
                    }

                    return $choice === 'resize' ? $this->resizeJpg($relative, $base) : $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_CHOICE');

                case 'png':
                    return $this->png($relative, $base, $choices);

                case 'pdf':
                    return ($choices['pdf'] ?? 'resize') === 'resize' ? $this->pdf($relative, $base) : $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_CHOICE');
            }
        } catch (\Throwable $e) {
            $this->cleanTemp($abs);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_ERROR', $e->getMessage());
        }

        return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_TYPE');
    }

    /**
     * After a conversion: move the original (still at $relative) into
     * the originals folder.
     *
     * @param   string  $relative
     *
     * @return  string|null  Path inside the originals folder, or null on failure.
     */
    public function moveOriginal($relative)
    {
        $relative = ltrim($relative, '/');
        $abs      = JPATH_ROOT . '/' . $relative;

        if (!is_file($abs)) {
            return null;
        }

        return $this->storeOriginal($abs, $relative);
    }

    /**
     * Put an original back. $replaceCurrent: the file at the original
     * location is a smaller version made by us and may be overwritten.
     *
     * @param   string   $stored          Path inside the originals folder.
     * @param   string   $relative        Where it belongs, relative to JPATH_ROOT.
     * @param   boolean  $replaceCurrent
     *
     * @return  boolean
     */
    public function restoreOriginal($stored, $relative, $replaceCurrent)
    {
        $from = $this->originalsDir . '/' . ltrim($stored, '/');
        $to   = JPATH_ROOT . '/' . ltrim($relative, '/');

        if (!is_file($from)) {
            return false;
        }

        if (is_file($to)) {
            if (!$replaceCurrent) {
                return false;
            }

            $backup = $to . '.mediacleaner-old';
            @unlink($backup);

            if (!@rename($to, $backup)) {
                return false;
            }

            if (!(new StorageLocator())->move($from, $to)) {
                @rename($backup, $to);

                return false;
            }

            @unlink($backup);
        } elseif (!is_dir(\dirname($to)) && !@mkdir(\dirname($to), 0755, true)) {
            return false;
        } elseif (!(new StorageLocator())->move($from, $to)) {
            return false;
        }

        $this->removeEmptyParents(\dirname($from));

        return true;
    }

    // ------------------------------------------------------------------
    // JPG
    // ------------------------------------------------------------------

    /**
     * @param   string  $relative
     * @param   array   $base
     *
     * @return  array
     */
    protected function resizeJpg($relative, array $base)
    {
        $abs            = JPATH_ROOT . '/' . $relative;
        $base['action'] = 'resize';
        $info           = @getimagesize($abs);

        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_INVALID');
        }

        [$w, $h]        = $info;
        $base['old_px'] = $w . 'x' . $h;

        if (!$this->enoughMemory($w, $h)) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_MEMORY', $w . 'x' . $h);
        }

        $img = @imagecreatefromjpeg($abs);

        if (!$img) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_UNREADABLE');
        }

        $scaled = max($w, $h) > $this->settings['max_pixels'];
        $img    = $this->rotateByExif($this->scale($img), $abs);

        $base['new_px'] = imagesx($img) . 'x' . imagesy($img);

        $tmp = $abs . '.mediacleaner-tmp';
        imageinterlace($img, true);
        $ok = imagejpeg($img, $tmp, (int) $this->settings['jpg_quality']);
        imagedestroy($img);
        clearstatcache();

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_WRITE');
        }

        $new = (int) filesize($tmp);

        // Without making it smaller in pixels, only save again when that
        // really gains a lot - no quality loss for nothing.
        if ($new >= $base['old_size'] * ($scaled ? 0.9 : 0.7)) {
            @unlink($tmp);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_NO_GAIN', $this->kb($base['old_size']) . ' → ' . $this->kb($new));
        }

        $base['new_size'] = $new;

        return $this->replaceInPlace($relative, $tmp, $base);
    }

    // ------------------------------------------------------------------
    // PNG
    // ------------------------------------------------------------------

    /**
     * @param   string  $relative
     * @param   array   $base
     * @param   array   $choices
     *
     * @return  array
     */
    protected function png($relative, array $base, array $choices)
    {
        $abs  = JPATH_ROOT . '/' . $relative;
        $info = @getimagesize($abs);

        if (!$info || $info[2] !== IMAGETYPE_PNG) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_INVALID');
        }

        [$w, $h]        = $info;
        $base['old_px'] = $w . 'x' . $h;
        $choice         = $choices['png'] ?? 'jpg';
        $alphaChoice    = $choices['png_alpha'] ?? 'skip';

        if ($choice === 'skip' && $alphaChoice === 'skip') {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_CHOICE');
        }

        if (!$this->enoughMemory($w, $h)) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_MEMORY', $w . 'x' . $h);
        }

        $img = @imagecreatefrompng($abs);

        if (!$img) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_UNREADABLE');
        }

        $transparent = $this->pngMayBeTransparent($abs) && $this->hasTransparency($img);

        if ($transparent) {
            if ($alphaChoice !== 'webp') {
                imagedestroy($img);

                return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_TRANSPARENT');
            }

            return $this->writeWebp($relative, $img, $base, true);
        }

        if ($choice === 'skip') {
            imagedestroy($img);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_CHOICE');
        }

        if ($choice === 'webp') {
            return $this->writeWebp($relative, $img, $base, false);
        }

        // PNG -> JPG: on a white background, to be safe.
        $base['action'] = 'to_jpg';
        $target         = preg_replace('~\.png$~i', '.jpg', $relative);

        if (file_exists(JPATH_ROOT . '/' . $target)) {
            imagedestroy($img);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_TARGET_EXISTS', basename($target));
        }

        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

        $flat           = $this->scale($flat);
        $base['new_px'] = imagesx($flat) . 'x' . imagesy($flat);

        $targetAbs = JPATH_ROOT . '/' . $target;
        $tmp       = $targetAbs . '.mediacleaner-tmp';
        imageinterlace($flat, true);
        $ok = imagejpeg($flat, $tmp, (int) $this->settings['jpg_quality']);
        imagedestroy($flat);

        return $this->finishConversion($tmp, $targetAbs, $target, $ok, $base);
    }

    // ------------------------------------------------------------------
    // WebP
    // ------------------------------------------------------------------

    /**
     * JPG -> WebP.
     *
     * @param   string   $relative
     * @param   array    $base
     * @param   integer  $type
     *
     * @return  array
     */
    protected function toWebp($relative, array $base, $type)
    {
        $abs  = JPATH_ROOT . '/' . $relative;
        $info = @getimagesize($abs);

        $base['action'] = 'to_webp';

        if (!$info || $info[2] !== $type) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_INVALID');
        }

        [$w, $h]        = $info;
        $base['old_px'] = $w . 'x' . $h;

        if (!$this->enoughMemory($w, $h)) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_MEMORY', $w . 'x' . $h);
        }

        $img = @imagecreatefromjpeg($abs);

        if (!$img) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_UNREADABLE');
        }

        return $this->writeWebp($relative, $this->rotateByExif($img, $abs), $base, false);
    }

    /**
     * Write $img (any source) as "<name>.webp" next to $relative.
     *
     * @param   string     $relative
     * @param   \GdImage   $img
     * @param   array      $base
     * @param   boolean    $keepAlpha
     *
     * @return  array
     */
    protected function writeWebp($relative, $img, array $base, $keepAlpha)
    {
        $base['action'] = 'to_webp';

        if (!self::capabilities()['webp']) {
            imagedestroy($img);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_NO_WEBP');
        }

        $target = preg_replace('~\.(png|jpe?g)$~i', '.webp', $relative);

        if ($target === $relative || file_exists(JPATH_ROOT . '/' . $target)) {
            imagedestroy($img);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_TARGET_EXISTS', basename($target));
        }

        if ($keepAlpha) {
            $img = $this->scaleKeepingAlpha($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);
        } else {
            if (!imageistruecolor($img)) {
                imagepalettetotruecolor($img);
            }

            $img = $this->scale($img);
        }

        $base['new_px'] = imagesx($img) . 'x' . imagesy($img);

        $targetAbs = JPATH_ROOT . '/' . $target;
        $tmp       = $targetAbs . '.mediacleaner-tmp';
        $ok        = imagewebp($img, $tmp, (int) $this->settings['webp_quality']);
        imagedestroy($img);

        // Some GD builds write an odd trailing byte for odd-sized files;
        // a WebP that does not read back is never used.
        if ($ok && is_file($tmp) && !@getimagesize($tmp)) {
            $ok = false;
        }

        return $this->finishConversion($tmp, $targetAbs, $target, $ok, $base);
    }

    /**
     * Shared tail of PNG->JPG and ->WebP: check the gain, put the new
     * file in place next to the original.
     *
     * @param   string   $tmp
     * @param   string   $targetAbs
     * @param   string   $target
     * @param   boolean  $ok
     * @param   array    $base
     *
     * @return  array
     */
    protected function finishConversion($tmp, $targetAbs, $target, $ok, array $base)
    {
        clearstatcache();

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_WRITE');
        }

        $new = (int) filesize($tmp);

        if ($new >= $base['old_size'] * 0.9) {
            @unlink($tmp);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_NO_GAIN', $this->kb($base['old_size']) . ' → ' . $this->kb($new));
        }

        if (file_exists($targetAbs) || !@rename($tmp, $targetAbs)) {
            @unlink($tmp);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_WRITE');
        }

        @chmod($targetAbs, 0644);

        return ['status' => 'converted', 'new_path' => $target, 'new_size' => $new] + $base;
    }

    // ------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------

    /**
     * @param   string  $relative
     * @param   array   $base
     *
     * @return  array
     */
    protected function pdf($relative, array $base)
    {
        $abs            = JPATH_ROOT . '/' . $relative;
        $base['action'] = 'resize';
        $tmp            = $abs . '.mediacleaner-tmp';
        @unlink($tmp);

        if (!$this->enoughMemoryForPdf($base['old_size'])) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_MEMORY', $this->kb($base['old_size']));
        }

        // An encrypted PDF is never touched: Ghostscript would write it out
        // again WITHOUT its protection.
        if ((new PdfShrinker())->isVersleuteld((string) @file_get_contents($abs))) {
            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_PDF', 'PDF is beveiligd (versleuteld)');
        }

        $result = null;
        $method = 'builtin';

        // 1. Ghostscript, when this server has it and Opties allow it: it
        //    rewrites the whole PDF and usually gains the most.
        if (!empty($this->settings['use_ghostscript']) && self::ghostscript() !== null) {
            $result = $this->pdfWithGhostscript($abs, $tmp);
            $method = 'ghostscript';
        }

        // 2. Otherwise, or when that did not work: built in. Only the images
        //    are replaced, every other byte of the PDF stays the same.
        if ($result === null) {
            @unlink($tmp);
            $result = (new PdfShrinker((int) $this->settings['pdf_dpi'], (int) $this->settings['pdf_quality']))->verwerk($abs, $tmp);
            $method = 'builtin';
        }

        if ($result['status'] !== 'ok') {
            @unlink($tmp);

            return $this->skip($base, 'COM_MEDIACLEANER_COMPRESS_R_PDF', (string) ($result['reden'] ?? ''));
        }

        clearstatcache();

        $base['info'] = $method === 'ghostscript'
            ? 'Ghostscript, ' . ($result['paginas'] ?? '?') . ' p.'
            : ($result['verkleind'] ?? 0) . '/' . ($result['afbeeldingen'] ?? 0) . ' img, ' . ($result['paginas'] ?? '?') . ' p.'
                . (!empty($result['geheugenTekort']) ? ' (memory_limit)' : '');

        $base['new_size'] = (int) filesize($tmp);

        return $this->replaceInPlace($relative, $tmp, $base);
    }

    /**
     * Path of a Ghostscript binary this server may start, or null.
     *
     * @return  string|null
     */
    public static function ghostscript()
    {
        static $gs = false;

        if ($gs !== false) {
            return $gs;
        }

        $gs       = null;
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (!\function_exists('proc_open') || \in_array('proc_open', $disabled, true) || \in_array('proc_get_status', $disabled, true)) {
            return null;
        }

        foreach (['gs', '/usr/bin/gs', '/usr/local/bin/gs'] as $bin) {
            $process = @proc_open([$bin, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

            if (\is_resource($process)) {
                $version = trim((string) stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                fclose($pipes[2]);

                if (proc_close($process) === 0 && preg_match('/^\d+\.\d+/', $version)) {
                    return $gs = $bin;
                }
            }
        }

        return null;
    }

    /**
     * @param   string  $in
     * @param   string  $out
     *
     * @return  array|null
     */
    protected function pdfWithGhostscript($in, $out)
    {
        $old   = (int) filesize($in);
        $pages = (new PdfShrinker())->paginas((string) file_get_contents($in));
        $dpi   = (string) (int) $this->settings['pdf_dpi'];
        $cmd   = [
            self::ghostscript(), '-q', '-dSAFER', '-dNOPAUSE', '-dBATCH', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.6',
            '-dPDFSETTINGS=/printer', '-dDetectDuplicateImages=true', '-dCompressFonts=true',
            '-dDownsampleColorImages=true', '-dColorImageDownsampleType=/Bicubic', '-dColorImageResolution=' . $dpi, '-dColorImageDownsampleThreshold=1.2',
            '-dDownsampleGrayImages=true', '-dGrayImageDownsampleType=/Bicubic', '-dGrayImageResolution=' . $dpi, '-dGrayImageDownsampleThreshold=1.2',
            '-dDownsampleMonoImages=true', '-dMonoImageDownsampleType=/Subsample', '-dMonoImageResolution=' . ((int) $dpi * 2),
            '-sOutputFile=' . $out, $in,
        ];

        $process = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!\is_resource($process)) {
            return null;
        }

        $start = time();

        while (($status = proc_get_status($process)) && $status['running']) {
            if (time() - $start > 90) {
                proc_terminate($process);
                break;
            }

            usleep(200000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        clearstatcache();

        if (!is_file($out) || filesize($out) < 100) {
            @unlink($out);

            return null;
        }

        $new      = (int) filesize($out);
        $newPages = (new PdfShrinker())->paginas((string) file_get_contents($out));

        if ($pages < 1 || $newPages !== $pages || $new > $old * 0.8) {
            @unlink($out);

            return null;
        }

        return ['status' => 'ok', 'oud' => $old, 'nieuw' => $new, 'paginas' => $pages];
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * Move the original to the originals folder, then put the smaller
     * $tmp in its place. Rolls back if the second step fails.
     *
     * @param   string  $relative
     * @param   string  $tmp
     * @param   array   $result
     *
     * @return  array
     */
    protected function replaceInPlace($relative, $tmp, array $result)
    {
        $abs    = JPATH_ROOT . '/' . $relative;
        $stored = $this->storeOriginal($abs, $relative);

        if ($stored === null) {
            @unlink($tmp);

            return $this->skip($result, 'COM_MEDIACLEANER_COMPRESS_R_ORIGINAL_FAILED');
        }

        if (!@rename($tmp, $abs)) {
            (new StorageLocator())->move($this->originalsDir . '/' . $stored, $abs);
            @unlink($tmp);

            return $this->skip($result, 'COM_MEDIACLEANER_COMPRESS_R_WRITE');
        }

        @chmod($abs, 0644);

        return ['status' => 'done', 'original_stored' => $stored] + $result;
    }

    /**
     * @param   string  $abs
     * @param   string  $relative
     *
     * @return  string|null  Path inside the originals folder.
     */
    protected function storeOriginal($abs, $relative)
    {
        // An original of the same name may already be kept (e.g. from
        // before Media Cleaner was reinstalled): never overwrite it, keep
        // this one next to it as "name.2.ext", "name.3.ext", ...
        $stored = $relative;
        $dot    = strrpos($relative, '.');
        $stem   = $dot === false || $dot < (int) strrpos($relative, '/') ? $relative : substr($relative, 0, $dot);
        $ext    = $stem === $relative ? '' : substr($relative, $dot);

        for ($n = 2; file_exists($this->originalsDir . '/' . $stored); $n++) {
            if ($n > 99) {
                return null;
            }

            $stored = $stem . '.' . $n . $ext;
        }

        $target = $this->originalsDir . '/' . $stored;

        if (!is_dir(\dirname($target)) && !@mkdir(\dirname($target), 0755, true) && !is_dir(\dirname($target))) {
            return null;
        }

        return (new StorageLocator())->move($abs, $target) ? $stored : null;
    }

    /**
     * @param   string  $dir
     *
     * @return  void
     */
    protected function removeEmptyParents($dir)
    {
        while (strpos($dir, $this->originalsDir . '/') === 0) {
            $entries = @scandir($dir);

            if ($entries === false || \count($entries) > 2 || !@rmdir($dir)) {
                return;
            }

            $dir = \dirname($dir);
        }
    }

    /**
     * @param   string  $abs
     *
     * @return  void
     */
    protected function cleanTemp($abs)
    {
        @unlink($abs . '.mediacleaner-tmp');

        foreach (['jpg', 'webp'] as $ext) {
            @unlink(preg_replace('~\.[^./]+$~', '.' . $ext, $abs) . '.mediacleaner-tmp');
        }
    }

    /**
     * @param   array   $base
     * @param   string  $reasonKey  Language key.
     * @param   string  $detail
     *
     * @return  array
     */
    protected function skip(array $base, $reasonKey, $detail = '')
    {
        return ['status' => 'skipped', 'reason' => $reasonKey . ($detail !== '' ? '|' . $detail : '')] + $base;
    }

    /**
     * @param   \GdImage  $img
     *
     * @return  \GdImage
     */
    protected function scale($img)
    {
        $w   = imagesx($img);
        $h   = imagesy($img);
        $max = max(1, (int) $this->settings['max_pixels']);

        if (max($w, $h) <= $max) {
            return $img;
        }

        $f  = $max / max($w, $h);
        $nw = max(1, (int) round($w * $f));
        $nh = max(1, (int) round($h * $f));
        $n  = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($n, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        return $n;
    }

    /**
     * @param   \GdImage  $img
     *
     * @return  \GdImage
     */
    protected function scaleKeepingAlpha($img)
    {
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        $w   = imagesx($img);
        $h   = imagesy($img);
        $max = max(1, (int) $this->settings['max_pixels']);

        if (max($w, $h) <= $max) {
            return $img;
        }

        $f  = $max / max($w, $h);
        $nw = max(1, (int) round($w * $f));
        $nh = max(1, (int) round($h * $f));
        $n  = imagecreatetruecolor($nw, $nh);
        imagealphablending($n, false);
        imagesavealpha($n, true);
        imagefill($n, 0, 0, imagecolorallocatealpha($n, 0, 0, 0, 127));
        imagecopyresampled($n, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        return $n;
    }

    /**
     * @param   \GdImage  $img
     * @param   string    $abs
     *
     * @return  \GdImage
     */
    protected function rotateByExif($img, $abs)
    {
        if (!\function_exists('exif_read_data')) {
            return $img;
        }

        $exif  = @exif_read_data($abs);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;

        if (!$angle) {
            return $img;
        }

        $rotated = imagerotate($img, $angle, 0);

        if (!$rotated) {
            return $img;
        }

        imagedestroy($img);

        return $rotated;
    }

    /**
     * Quick look in the PNG header: alpha channel or tRNS chunk?
     *
     * @param   string  $abs
     *
     * @return  boolean
     */
    protected function pngMayBeTransparent($abs)
    {
        $f = @fopen($abs, 'rb');

        if (!$f) {
            return true;
        }

        fread($f, 8);
        $colourType = null;

        while (!feof($f)) {
            $head = fread($f, 8);

            if (\strlen($head) < 8) {
                break;
            }

            $len  = unpack('N', substr($head, 0, 4))[1];
            $type = substr($head, 4, 4);

            if ($type === 'IHDR') {
                $data       = fread($f, $len);
                $colourType = \strlen($data) > 9 ? \ord($data[9]) : null;
                fseek($f, 4, SEEK_CUR);
                continue;
            }

            if ($type === 'tRNS') {
                fclose($f);

                return true;
            }

            if ($type === 'IDAT' || $type === 'IEND') {
                break;
            }

            fseek($f, $len + 4, SEEK_CUR);
        }

        fclose($f);

        return \in_array($colourType, [4, 6], true);
    }

    /**
     * Real check: any (semi-)transparent pixels? On a small copy.
     *
     * @param   \GdImage  $img
     *
     * @return  boolean
     */
    protected function hasTransparency($img)
    {
        $w  = imagesx($img);
        $h  = imagesy($img);
        $f  = min(1, 400 / max($w, $h));
        $sw = max(1, (int) round($w * $f));
        $sh = max(1, (int) round($h * $f));
        $k  = imagecreatetruecolor($sw, $sh);
        imagealphablending($k, false);
        imagesavealpha($k, true);
        imagefill($k, 0, 0, imagecolorallocatealpha($k, 0, 0, 0, 127));
        imagecopyresampled($k, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);

        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                if (((imagecolorat($k, $x, $y) >> 24) & 0x7F) > 0) {
                    imagedestroy($k);

                    return true;
                }
            }
        }

        imagedestroy($k);

        return false;
    }

    /**
     * @param   integer  $w
     * @param   integer  $h
     *
     * @return  boolean
     */
    protected function enoughMemory($w, $h)
    {
        $limit = self::memoryLimit();

        if ($limit === -1) {
            return true;
        }

        $f    = min(1, (int) $this->settings['max_pixels'] / max(1, $w, $h));
        $need = (int) ($w * $h * 5 * 1.3 + ($w * $f) * ($h * $f) * 5 * 2.2 + 8 * 1048576);

        return memory_get_usage(true) + $need < $limit * 0.95;
    }

    /**
     * The PDF is held in memory about three times over (original, new
     * copy, check), plus room for one image.
     *
     * @param   integer  $size
     *
     * @return  boolean
     */
    protected function enoughMemoryForPdf($size)
    {
        $limit = self::memoryLimit();

        return $limit === -1 || memory_get_usage(true) + $size * 3 + 48 * 1048576 < $limit * 0.95;
    }

    /**
     * @return  integer  Bytes, -1 for unlimited.
     */
    public static function memoryLimit()
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $n = (int) $value;

        switch (strtolower(substr($value, -1))) {
            case 'g':
                $n *= 1024;
                // no break
            case 'm':
                $n *= 1024;
                // no break
            case 'k':
                $n *= 1024;
        }

        return $n;
    }

    /**
     * @param   integer  $bytes
     *
     * @return  string
     */
    protected function kb($bytes)
    {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
}
