<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Makes the images inside a PDF smaller, without any external program
 * (v2.9.0, taken over unchanged from the stand-alone "media-verkleinen"
 * script, only put in this component's namespace).
 *
 * How: the PDF is not rebuilt. Only the objects holding an image (JPEG /
 * DCTDecode, or lossless FlateDecode) are replaced by a smaller JPEG;
 * every other byte (text, fonts, links, bookmarks, forms) stays as it
 * was. A new cross-reference table is then appended, the way a PDF
 * editor saves an incremental change. The result is read back in and
 * checked object by object, and page by page, before it is used.
 *
 * Left alone (the PDF stays unchanged): encrypted PDFs, images with
 * transparency (SMask/Mask), CMYK / palette / special colour spaces,
 * 1-bit black and white (CCITT/JBIG2) and anything not safely readable.
 *
 * Public API (Dutch names, as in the original): verwerk($in, $out),
 * controleer($pdf, $pages), paginas($pdf). Requires GD (JPEG) and zlib.
 */
final class PdfShrinker
{
    private string $d = '';
    private int $n = 0;
    /** @var array<int, array{t:int, off?:int, gen?:int, stm?:int, idx?:int}> */
    private array $xref = [];
    private array $trailer = [];
    private bool $heeftXrefStream = false;
    private array $objStmCache = [];
    private bool $geheugenTekort = false;

    public function __construct(
        private int $dpi = 200,
        private int $kwaliteit = 80,
        private bool $flateNaarJpg = true,
        private float $minWinst = 0.2
    ) {
    }

    // ------------------------------------------------------------------
    // Openbaar
    // ------------------------------------------------------------------

    /**
     * Verkleint $in en schrijft het resultaat naar $uit (alleen als het de moeite waard is).
     * @return array{status:string, reden?:string, oud:int, nieuw?:int, paginas?:int, afbeeldingen?:int, verkleind?:int}
     */
    public function verwerk(string $in, string $uit): array
    {
        $oud = (int) filesize($in);
        $r = ['status' => 'overgeslagen', 'oud' => $oud];
        $data = @file_get_contents($in);
        if ($data === false) {
            return $r + ['reden' => 'kon het bestand niet lezen'];
        }
        try {
            $this->laad($data);
        } catch (\Throwable $e) {
            return $r + ['reden' => 'opbouw van de PDF niet te lezen (' . $e->getMessage() . ')'];
        }
        if (isset($this->trailer['Encrypt'])) {
            return $r + ['reden' => 'PDF is beveiligd (versleuteld)'];
        }
        $paginas = 0;
        $box = $this->grootstePagina($paginas);
        if ($paginas < 1) {
            return $r + ['reden' => 'geen pagina\'s gevonden'];
        }

        $vervang = [];
        $aantal = 0;
        foreach ($this->xref as $nr => $e) {
            if ($e['t'] !== 1) {
                continue;
            }
            $kop = substr($this->d, $e['off'], 4096);
            if (stripos($kop, '/Image') === false || stripos($kop, 'stream') === false) {
                continue;
            }
            $o = $this->objAt($e['off']);
            if (!$o || $o['nr'] !== $nr || !$o['stream'] || ($o['val']['t'] ?? '') !== 'dict') {
                continue;
            }
            if ($this->naam($o['val']['v']['Subtype'] ?? null) !== 'Image') {
                continue;
            }
            $aantal++;
            $nieuw = $this->verkleinAfbeelding($o, $box);
            if ($nieuw !== null) {
                $vervang[] = [$o['start'], $o['eind'], $nieuw];
            }
        }
        if (!$vervang && $this->geheugenTekort) {
            return $r + ['reden' => 'te weinig PHP-geheugen voor de afbeeldingen (memory_limit verhogen)', 'paginas' => $paginas, 'afbeeldingen' => $aantal];
        }
        if (!$vervang) {
            return $r + ['reden' => $aantal ? 'afbeeldingen niet (veilig) te verkleinen' : 'geen afbeeldingen in deze PDF', 'paginas' => $paginas, 'afbeeldingen' => $aantal];
        }

        $nieuwePdf = $this->schrijf($vervang);
        if (strlen($nieuwePdf) > $oud * (1 - $this->minWinst)) {
            return $r + ['reden' => 'levert te weinig op (' . round($oud / 1024) . ' → ' . round(strlen($nieuwePdf) / 1024) . ' KB)', 'paginas' => $paginas, 'afbeeldingen' => $aantal];
        }

        // Controle: de nieuwe PDF opnieuw inlezen en alle objecten nalopen
        $fout = (new self())->controleer($nieuwePdf, $paginas);
        if ($fout !== null) {
            return $r + ['reden' => 'controle mislukt: ' . $fout];
        }
        if (@file_put_contents($uit, $nieuwePdf) !== strlen($nieuwePdf)) {
            @unlink($uit);
            return $r + ['reden' => 'kon het nieuwe bestand niet opslaan'];
        }
        return ['status' => 'ok', 'oud' => $oud, 'nieuw' => strlen($nieuwePdf), 'paginas' => $paginas, 'afbeeldingen' => $aantal, 'verkleind' => count($vervang), 'geheugenTekort' => $this->geheugenTekort];
    }

    /** Leest $pdf opnieuw in en controleert alle verwijzingen; null = in orde, anders de fout. */
    public function controleer(string $pdf, int $verwachtePaginas): ?string
    {
        try {
            $this->laad($pdf);
            foreach ($this->xref as $nr => $e) {
                if ($e['t'] === 1) {
                    $o = $this->objAt($e['off']);
                    if (!$o || $o['nr'] !== $nr) {
                        return "object $nr staat niet op de juiste plek";
                    }
                } elseif ($e['t'] === 2 && $this->objUitStream($e['stm'], $nr) === null) {
                    return "object $nr niet gevonden in objectstream";
                }
            }
            $p = 0;
            $this->grootstePagina($p);
            if ($p !== $verwachtePaginas) {
                return "aantal pagina's klopt niet ($p in plaats van $verwachtePaginas)";
            }
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return null;
    }

    /** Aantal pagina's, of 0 als de PDF niet te lezen is */
    public function paginas(string $pdf): int
    {
        try {
            $this->laad($pdf);
            $p = 0;
            $this->grootstePagina($p);
            return $p;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * v2.9.0: whether $pdf is encrypted (also when only an owner password
     * is set). When the structure cannot be read, the /Encrypt key is
     * looked for in the raw bytes (trailers and xref-stream dictionaries
     * are never compressed, so it is visible there).
     */
    public function isVersleuteld(string $pdf): bool
    {
        try {
            $this->laad($pdf);
            return isset($this->trailer['Encrypt']);
        } catch (\Throwable $e) {
            return preg_match('~/Encrypt(?![A-Za-z0-9])~', $pdf) === 1;
        }
    }

    // ------------------------------------------------------------------
    // Afbeelding verkleinen
    // ------------------------------------------------------------------

    private function verkleinAfbeelding(array $o, array $box): ?string
    {
        $dict = $o['val']['v'];
        foreach (['SMask', 'Mask', 'Decode', 'SMaskInData'] as $k) {
            if (isset($dict[$k])) {
                return null;
            }
        }
        if ($this->waarde($dict['ImageMask'] ?? null) === true) {
            return null;
        }
        $w = (int) $this->waarde($dict['Width'] ?? null);
        $h = (int) $this->waarde($dict['Height'] ?? null);
        $bpc = (int) $this->waarde($dict['BitsPerComponent'] ?? null);
        if ($w < 64 || $h < 64 || $bpc !== 8) {
            return null;
        }
        $kanalen = $this->kanalen($dict['ColorSpace'] ?? null);
        if ($kanalen === null) {
            return null;
        }
        [$filters, $parms] = $this->filters($dict);
        $bron = substr($this->d, $o['stream'][0], $o['stream'][1]);
        // Tekstcoderingen ervoor (ASCII85/ASCIIHex, vooral in oudere PDF's) eerst uitpakken
        while ($filters && in_array($filters[0], ['ASCII85Decode', 'ASCIIHexDecode'], true)) {
            $bron = $filters[0] === 'ASCII85Decode' ? $this->ascii85($bron) : $this->asciiHex($bron);
            array_shift($filters);
            array_shift($parms);
            if ($bron === null) {
                return null;
            }
        }
        if (isset($parms[0]['ColorTransform'])) {
            return null; // afwijkende JPEG-kleurcodering: niet aanraken
        }

        // Doelgrootte: niet meer pixels dan de grootste pagina op DPI nodig heeft
        $lang = max($box[0], $box[1]) / 72 * $this->dpi;
        $kort = min($box[0], $box[1]) / 72 * $this->dpi;
        $f = min(1, $lang / max($w, $h), $kort / min($w, $h));
        if (!$this->genoegGeheugen($w, $h, $f < 0.95 ? $f : 1.0)) {
            $this->geheugenTekort = true;
            return null;
        }
        $img = null;
        if ($filters === ['DCTDecode']) {
            $info = @getimagesizefromstring($bron);
            if (!$info || $info[0] !== $w || $info[1] !== $h || (isset($info['channels']) && $info['channels'] > 3)) {
                return null;
            }
            $img = $this->decodeerStil($bron);
        } elseif ($filters === ['FlateDecode'] && $this->flateNaarJpg && $w * $h >= 1000000) {
            $png = $this->flateAlsPng($bron, $parms[0] ?? [], $w, $h, $kanalen);
            if ($png !== null) {
                $img = $this->decodeerStil($png);
            }
        }
        if (!$img) {
            return null;
        }
        if (imagesx($img) !== $w || imagesy($img) !== $h) {
            imagedestroy($img);
            return null;
        }

        if ($f < 0.95) {
            $nw = max(1, (int) round($w * $f));
            $nh = max(1, (int) round($h * $f));
            $klein = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($klein, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $klein;
        }
        ob_start();
        imagejpeg($img, null, $this->kwaliteit);
        $jpg = (string) ob_get_clean();
        $nw = imagesx($img);
        $nh = imagesy($img);
        imagedestroy($img);
        if ($jpg === '' || strlen($jpg) > strlen($bron) * 0.9) {
            return null;
        }

        // Nieuw woordenboek: alles behouden behalve wat door de nieuwe JPEG verandert
        $uit = '';
        foreach ($dict as $k => $v) {
            if (in_array($k, ['Width', 'Height', 'Length', 'Filter', 'DecodeParms', 'BitsPerComponent', 'ColorSpace'], true)) {
                continue;
            }
            $uit .= '/' . $this->naamUit($k) . ' ' . $v['raw'] . "\n";
        }
        // GD schrijft altijd een kleuren-JPEG; bij grijs wordt de kleurruimte RGB (ziet er hetzelfde uit)
        $cs = $kanalen === 3 ? $dict['ColorSpace']['raw'] : '/DeviceRGB';
        $uit .= "/Width $nw\n/Height $nh\n/BitsPerComponent 8\n/ColorSpace $cs\n/Filter /DCTDecode\n/Length " . strlen($jpg) . "\n";
        return $o['nr'] . ' ' . $o['gen'] . " obj\n<<\n" . $uit . ">>\nstream\n" . $jpg . "\nendstream\nendobj\n";
    }

    private function ascii85(string $s): ?string
    {
        $s = preg_replace('/\s+/', '', $s);
        if (str_starts_with($s, '<~')) {
            $s = substr($s, 2);
        }
        $e = strpos($s, '~>');
        if ($e !== false) {
            $s = substr($s, 0, $e);
        }
        $uit = '';
        $tupel = 0;
        $aantal = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === 'z' && $aantal === 0) {
                $uit .= "\0\0\0\0";
                continue;
            }
            $v = ord($c) - 33;
            if ($v < 0 || $v > 84) {
                return null;
            }
            $tupel = $tupel * 85 + $v;
            if (++$aantal === 5) {
                $uit .= pack('N', $tupel);
                $tupel = 0;
                $aantal = 0;
            }
        }
        if ($aantal === 1) {
            return null;
        }
        if ($aantal > 0) {
            for ($k = $aantal; $k < 5; $k++) {
                $tupel = $tupel * 85 + 84;
            }
            $uit .= substr(pack('N', $tupel), 0, $aantal - 1);
        }
        return $uit;
    }

    private function asciiHex(string $s): ?string
    {
        $e = strpos($s, '>');
        $s = preg_replace('/[^0-9A-Fa-f]/', '', $e === false ? $s : substr($s, 0, $e));
        if (strlen($s) % 2) {
            $s .= '0';
        }
        $b = @hex2bin($s);
        return $b === false ? null : $b;
    }

    /** Afbeelding inlezen; bij elke waarschuwing (bijv. beschadigde JPEG) niet gebruiken */
    private function decodeerStil(string $bytes)
    {
        $melding = false;
        set_error_handler(function () use (&$melding) {
            $melding = true;
            return true;
        });
        try {
            $img = imagecreatefromstring($bytes);
        } catch (\Throwable $e) {
            $img = false;
        } finally {
            restore_error_handler();
        }
        if ($melding && $img) {
            imagedestroy($img);
            return false;
        }
        return $img;
    }

    /** Zet een FlateDecode-afbeelding om naar een PNG-bestand (zonder pixels één voor één te verwerken) */
    private function flateAlsPng(string $data, $parms, int $w, int $h, int $kanalen): ?string
    {
        $pred = (int) $this->waarde($parms['Predictor'] ?? null) ?: 1;
        if ($pred >= 10) {
            $kol = (int) ($this->waarde($parms['Columns'] ?? null) ?: 1);
            $kl = (int) ($this->waarde($parms['Colors'] ?? null) ?: 1);
            $b = (int) ($this->waarde($parms['BitsPerComponent'] ?? null) ?: 8);
            if ($kol !== $w || $kl !== $kanalen || $b !== 8) {
                return null;
            }
            $idat = $data; // PNG-voorspellers in een PDF zijn precies het PNG-formaat
        } elseif ($pred === 1) {
            $ruw = @gzuncompress($data);
            if ($ruw === false) {
                $ruw = @gzinflate($data);
            }
            $rij = $w * $kanalen;
            if ($ruw === false || strlen($ruw) < $rij * $h) {
                return null;
            }
            $idat = gzcompress("\0" . implode("\0", str_split(substr($ruw, 0, $rij * $h), $rij)), 6);
        } else {
            return null;
        }
        $stuk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        return "\x89PNG\r\n\x1a\n" . $stuk('IHDR', pack('NNCCCCC', $w, $h, 8, $kanalen === 3 ? 2 : 0, 0, 0, 0)) . $stuk('IDAT', $idat) . $stuk('IEND', '');
    }

    /** 3 = RGB, 1 = grijs, null = niet ondersteund */
    private function kanalen($cs): ?int
    {
        $cs = $this->los($cs);
        if (($cs['t'] ?? '') === 'name') {
            return match ($cs['v']) {
                'DeviceRGB', 'CalRGB' => 3,
                'DeviceGray', 'CalGray' => 1,
                default => null,
            };
        }
        if (($cs['t'] ?? '') === 'arr' && $cs['v']) {
            $soort = $this->naam($cs['v'][0]);
            if ($soort === 'CalRGB') {
                return 3;
            }
            if ($soort === 'CalGray') {
                return 1;
            }
            if ($soort === 'ICCBased' && isset($cs['v'][1]) && $cs['v'][1]['t'] === 'ref') {
                $icc = $this->obj($cs['v'][1]['v'][0]);
                $nn = (int) $this->waarde($icc['val']['v']['N'] ?? null);
                return $nn === 3 ? 3 : ($nn === 1 ? 1 : null);
            }
        }
        return null;
    }

    private function filters(array $dict): array
    {
        $f = $this->los($dict['Filter'] ?? null);
        $p = $this->los($dict['DecodeParms'] ?? null);
        $filters = [];
        if (($f['t'] ?? '') === 'name') {
            $filters = [$f['v']];
        } elseif (($f['t'] ?? '') === 'arr') {
            foreach ($f['v'] as $x) {
                $filters[] = $this->naam($x);
            }
        }
        $parms = [];
        if (($p['t'] ?? '') === 'dict') {
            $parms = [$p['v']];
        } elseif (($p['t'] ?? '') === 'arr') {
            foreach ($p['v'] as $x) {
                $x = $this->los($x);
                $parms[] = ($x['t'] ?? '') === 'dict' ? $x['v'] : [];
            }
        }
        $filters = array_map(fn($x) => match ($x) { 'DCT' => 'DCTDecode', 'Fl' => 'FlateDecode', 'A85' => 'ASCII85Decode', 'AHx' => 'ASCIIHexDecode', default => $x }, $filters);
        return [$filters, $parms];
    }

    private function genoegGeheugen(int $w, int $h, float $f): bool
    {
        $lim = trim((string) ini_get('memory_limit'));
        if ($lim === '' || $lim === '-1') {
            return true;
        }
        $n = (int) $lim;
        $e = strtolower(substr($lim, -1));
        $n *= $e === 'g' ? 1073741824 : ($e === 'm' ? 1048576 : ($e === 'k' ? 1024 : 1));
        // bron (GD: ca. 4,5 byte per pixel) + verkleinde kopie + JPEG-buffer en marge
        return memory_get_usage() + $w * $h * 4.5 + $w * $h * $f * $f * 4.5 + 12 * 1048576 < $n * 0.92;
    }

    // ------------------------------------------------------------------
    // Nieuwe PDF schrijven
    // ------------------------------------------------------------------

    private function schrijf(array $vervang): string
    {
        usort($vervang, fn($a, $b) => $a[0] <=> $b[0]);
        $uit = '';
        $pos = 0;
        $kaart = []; // [oudBegin, oudEind, nieuwBegin, verschil-na]
        $verschil = 0;
        foreach ($vervang as [$begin, $eind, $nieuw]) {
            if ($begin < $pos) {
                continue; // overlap (kapotte PDF): niet vervangen
            }
            $uit .= substr($this->d, $pos, $begin - $pos);
            $nieuwBegin = strlen($uit);
            $uit .= $nieuw;
            $verschil += strlen($nieuw) - ($eind - $begin);
            $kaart[] = [$begin, $eind, $nieuwBegin, $verschil];
            $pos = $eind;
        }
        $uit .= substr($this->d, $pos);

        // Een oude 'linearisatie' (snel web-weergave) klopt niet meer: onschadelijk maken (zelfde lengte)
        $lin = strpos(substr($uit, 0, 2048), '/Linearized');
        if ($lin !== false) {
            $uit = substr_replace($uit, '/LinearizeX', $lin, 11);
        }

        $map = function (int $off) use ($kaart): int {
            $d = 0;
            foreach ($kaart as [$b, $e, $nb, $na]) {
                if ($off >= $e) {
                    $d = $na;
                } elseif ($off >= $b) {
                    return $nb;
                } else {
                    break;
                }
            }
            return $off + $d;
        };

        if ($uit !== '' && !in_array(substr($uit, -1), ["\n", "\r"], true)) {
            $uit .= "\n";
        }
        $max = $this->xref ? max(array_keys($this->xref)) : 0;
        $extra = '';
        foreach (['Root', 'Info', 'ID'] as $k) {
            if (isset($this->trailer[$k])) {
                $extra .= ' /' . $k . ' ' . $this->trailer[$k]['raw'];
            }
        }

        if ($this->heeftXrefStream) {
            $eigen = $max + 1;
            $size = $eigen + 1;
            $eigenOff = strlen($uit);
            $rijen = '';
            for ($i = 0; $i < $size; $i++) {
                $e = $this->xref[$i] ?? null;
                if ($i === $eigen) {
                    $rijen .= pack('CNn', 1, $eigenOff, 0);
                } elseif ($e && $e['t'] === 1) {
                    $rijen .= pack('CNn', 1, $map($e['off']), $e['gen'] & 0xFFFF);
                } elseif ($e && $e['t'] === 2) {
                    $rijen .= pack('CNn', 2, $e['stm'], $e['idx'] & 0xFFFF);
                } else {
                    $rijen .= pack('CNn', 0, 0, $i === 0 ? 65535 : 0);
                }
            }
            $z = gzcompress($rijen);
            $uit .= "$eigen 0 obj\n<< /Type /XRef /Size $size /W [1 4 2] /Index [0 $size]$extra /Filter /FlateDecode /Length " . strlen($z) . " >>\nstream\n" . $z . "\nendstream\nendobj\n";
            $uit .= "startxref\n$eigenOff\n%%EOF\n";
        } else {
            $size = $max + 1;
            $xrefOff = strlen($uit);
            $t = "xref\n0 $size\n";
            for ($i = 0; $i < $size; $i++) {
                $e = $this->xref[$i] ?? null;
                if ($e && $e['t'] === 1) {
                    $t .= sprintf("%010d %05d n\r\n", $map($e['off']), $e['gen']);
                } else {
                    $t .= $i === 0 ? "0000000000 65535 f\r\n" : "0000000000 00000 f\r\n";
                }
            }
            $uit .= $t . "trailer\n<< /Size $size$extra >>\nstartxref\n$xrefOff\n%%EOF\n";
        }
        return $uit;
    }

    // ------------------------------------------------------------------
    // PDF lezen: verwijzingstabellen, objecten, pagina's
    // ------------------------------------------------------------------

    private function laad(string $data): void
    {
        $this->d = $data;
        $this->n = strlen($data);
        $this->xref = [];
        $this->trailer = [];
        $this->heeftXrefStream = false;
        $this->objStmCache = [];
        if (strpos(substr($data, 0, 1024), '%PDF-') === false) {
            throw new \RuntimeException('geen PDF');
        }
        $pos = strrpos($data, 'startxref');
        if ($pos === false || !preg_match('/\Gstartxref\s+(\d+)/', $data, $m, 0, $pos)) {
            throw new \RuntimeException('startxref ontbreekt');
        }
        $off = (int) $m[1];
        $gezien = [];
        $eerste = true;
        while ($off > 0 && !isset($gezien[$off]) && count($gezien) < 200) {
            $gezien[$off] = true;
            $p = $this->ws($off);
            if (substr($data, $p, 4) === 'xref') {
                $trailer = $this->leesTabel($p + 4);
                if ($eerste) {
                    $this->trailer = $trailer;
                }
                if (isset($trailer['XRefStm'])) {
                    $this->leesXrefStream((int) $this->waarde($trailer['XRefStm']));
                }
                $off = (int) ($this->waarde($trailer['Prev'] ?? null) ?? 0);
            } else {
                $dict = $this->leesXrefStream($off);
                if ($eerste) {
                    $this->trailer = $dict;
                }
                $off = (int) ($this->waarde($dict['Prev'] ?? null) ?? 0);
            }
            $eerste = false;
        }
        if (!isset($this->trailer['Root'])) {
            throw new \RuntimeException('geen /Root');
        }
    }

    private function leesTabel(int $p): array
    {
        while (true) {
            $p = $this->ws($p);
            if (substr($this->d, $p, 7) === 'trailer') {
                [$t, ] = $this->waardeOp($p + 7);
                if (($t['t'] ?? '') !== 'dict') {
                    throw new \RuntimeException('trailer onleesbaar');
                }
                return $t['v'];
            }
            if (!preg_match('/\G(\d+)\s+(\d+)/', $this->d, $m, 0, $p)) {
                throw new \RuntimeException('xref-tabel onleesbaar');
            }
            $start = (int) $m[1];
            $aantal = (int) $m[2];
            $p += strlen($m[0]);
            for ($i = 0; $i < $aantal; $i++) {
                if (!preg_match('/\G\s*(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->d, $m, 0, $p)) {
                    throw new \RuntimeException('xref-regel onleesbaar');
                }
                $p += strlen($m[0]);
                $nr = $start + $i;
                if ($m[3] === 'n' && !isset($this->xref[$nr]) && (int) $m[1] > 0) {
                    $this->xref[$nr] = ['t' => 1, 'off' => (int) $m[1], 'gen' => (int) $m[2]];
                }
            }
        }
    }

    private function leesXrefStream(int $off): array
    {
        $this->heeftXrefStream = true;
        $o = $this->objAt($off);
        if (!$o || !$o['stream'] || $this->naam($o['val']['v']['Type'] ?? null) !== 'XRef') {
            throw new \RuntimeException('xref-stream onleesbaar');
        }
        $dict = $o['val']['v'];
        $data = $this->decodeer($o);
        if ($data === null) {
            throw new \RuntimeException('xref-stream niet te decoderen');
        }
        $W = array_map(fn($x) => (int) $this->waarde($x), $this->los($dict['W'])['v'] ?? []);
        if (count($W) !== 3) {
            throw new \RuntimeException('xref-stream /W onleesbaar');
        }
        $index = isset($dict['Index']) ? array_map(fn($x) => (int) $this->waarde($x), $this->los($dict['Index'])['v']) : [0, (int) $this->waarde($dict['Size'])];
        $rij = array_sum($W);
        $p = 0;
        $veld = function (int $len) use (&$p, $data): ?int {
            if ($len === 0) {
                return null;
            }
            $v = 0;
            for ($i = 0; $i < $len; $i++) {
                $v = ($v << 8) | ord($data[$p + $i] ?? "\0");
            }
            $p += $len;
            return $v;
        };
        for ($s = 0; $s + 1 < count($index); $s += 2) {
            for ($i = 0; $i < $index[$s + 1]; $i++) {
                if ($p + $rij > strlen($data)) {
                    break 2;
                }
                $type = $veld($W[0]) ?? 1;
                $f2 = $veld($W[1]) ?? 0;
                $f3 = $veld($W[2]) ?? 0;
                $nr = $index[$s] + $i;
                if (isset($this->xref[$nr])) {
                    continue;
                }
                if ($type === 1 && $f2 > 0) {
                    $this->xref[$nr] = ['t' => 1, 'off' => $f2, 'gen' => $f3];
                } elseif ($type === 2) {
                    $this->xref[$nr] = ['t' => 2, 'stm' => $f2, 'idx' => $f3];
                }
            }
        }
        return $dict;
    }

    /** Object op een bepaalde plek in het bestand */
    private function objAt(int $off): ?array
    {
        if ($off < 0 || $off >= $this->n || !preg_match('/\G[\s\0]*(\d+)\s+(\d+)\s+obj\b/', $this->d, $m, PREG_OFFSET_CAPTURE, $off)) {
            return null;
        }
        $start = $m[1][1];
        $p = $off + strlen($m[0][0]);
        [$val, $p] = $this->waardeOp($p);
        $p = $this->ws($p);
        $stream = null;
        if (substr($this->d, $p, 6) === 'stream') {
            $p += 6;
            if (($this->d[$p] ?? '') === "\r") {
                $p++;
            }
            if (($this->d[$p] ?? '') === "\n") {
                $p++;
            }
            $len = null;
            $l = $val['v']['Length'] ?? null;
            if ($l) {
                if ($l['t'] === 'ref') {
                    $lo = $this->xref[$l['v'][0]] ?? null;
                    if ($lo) {
                        $lv = $this->obj($l['v'][0]);
                        $len = is_int($this->waarde($lv['val'] ?? null)) ? $this->waarde($lv['val']) : null;
                    }
                } else {
                    $len = is_int($l['v']) ? $l['v'] : null;
                }
            }
            if ($len === null || $len < 0 || substr($this->d, $this->ws($p + $len), 9) !== 'endstream') {
                $es = strpos($this->d, 'endstream', $p);
                if ($es === false) {
                    return null;
                }
                $len = $es - $p;
                if ($len > 0 && $this->d[$p + $len - 1] === "\n") {
                    $len--;
                }
                if ($len > 0 && $this->d[$p + $len - 1] === "\r") {
                    $len--;
                }
            }
            $stream = [$p, $len];
            $p = $this->ws($p + $len) + 9;
            $p = $this->ws($p);
        }
        if (substr($this->d, $p, 6) === 'endobj') {
            $p += 6;
        }
        return ['nr' => (int) $m[1][0], 'gen' => (int) $m[2][0], 'start' => $start, 'eind' => $p, 'val' => $val, 'stream' => $stream];
    }

    /** Object op nummer (ook uit een objectstream) */
    private function obj(int $nr, int $diepte = 0): ?array
    {
        $e = $this->xref[$nr] ?? null;
        if (!$e || $diepte > 20) {
            return null;
        }
        if ($e['t'] === 1) {
            return $this->objAt($e['off']);
        }
        $v = $this->objUitStream($e['stm'], $nr);
        return $v === null ? null : ['nr' => $nr, 'gen' => 0, 'val' => $v, 'stream' => null];
    }

    private function objUitStream(int $stm, int $nr): ?array
    {
        if (!isset($this->objStmCache[$stm])) {
            $this->objStmCache[$stm] = null;
            $o = $this->obj($stm);
            if ($o && $o['stream'] && ($data = $this->decodeer($o)) !== null) {
                $first = (int) $this->waarde($o['val']['v']['First'] ?? null);
                $aantal = (int) $this->waarde($o['val']['v']['N'] ?? null);
                preg_match_all('/\d+/', substr($data, 0, $first), $g);
                $offs = [];
                for ($i = 0; $i + 1 < count($g[0]) && $i / 2 < $aantal; $i += 2) {
                    $offs[(int) $g[0][$i]] = $first + (int) $g[0][$i + 1];
                }
                $this->objStmCache[$stm] = ['data' => $data, 'offs' => $offs];
            }
        }
        $c = $this->objStmCache[$stm];
        if (!$c || !isset($c['offs'][$nr])) {
            return null;
        }
        $lezer = new self();
        $lezer->d = $c['data'];
        $lezer->n = strlen($c['data']);
        return $lezer->waardeOp($c['offs'][$nr])[0];
    }

    /** FlateDecode (met PNG-voorspeller) voor xref- en objectstreams */
    private function decodeer(array $o): ?string
    {
        [$filters, $parms] = $this->filters($o['val']['v']);
        $data = substr($this->d, $o['stream'][0], $o['stream'][1]);
        if (!$filters) {
            return $data;
        }
        if ($filters !== ['FlateDecode']) {
            return null;
        }
        $ruw = @gzuncompress($data);
        if ($ruw === false) {
            $ruw = @gzinflate($data);
        }
        if ($ruw === false) {
            return null;
        }
        $p = $parms[0] ?? [];
        $pred = (int) ($this->waarde($p['Predictor'] ?? null) ?? 1);
        if ($pred < 10) {
            return $pred <= 1 ? $ruw : null;
        }
        $kol = (int) ($this->waarde($p['Columns'] ?? null) ?? 1);
        $kl = (int) ($this->waarde($p['Colors'] ?? null) ?? 1);
        $b = (int) ($this->waarde($p['BitsPerComponent'] ?? null) ?? 8);
        $bpp = max(1, intdiv($kl * $b + 7, 8));
        $rij = intdiv($kol * $kl * $b + 7, 8);
        $uit = '';
        $vorige = str_repeat("\0", $rij);
        $lengte = strlen($ruw);
        for ($i = 0; $i < $lengte; $i += $rij + 1) {
            $type = ord($ruw[$i]);
            $r = substr($ruw, $i + 1, $rij);
            $r = str_pad($r, $rij, "\0");
            $n = '';
            for ($x = 0; $x < $rij; $x++) {
                $a = $x >= $bpp ? ord($n[$x - $bpp]) : 0;
                $up = ord($vorige[$x]);
                $c = $x >= $bpp ? ord($vorige[$x - $bpp]) : 0;
                $v = ord($r[$x]);
                switch ($type) {
                    case 1: $v += $a; break;
                    case 2: $v += $up; break;
                    case 3: $v += intdiv($a + $up, 2); break;
                    case 4:
                        $pp = $a + $up - $c;
                        $pa = abs($pp - $a);
                        $pb = abs($pp - $up);
                        $pc = abs($pp - $c);
                        $v += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $up : $c);
                        break;
                }
                $n .= chr($v & 0xFF);
            }
            $uit .= $n;
            $vorige = $n;
        }
        return $uit;
    }

    /** Grootste paginaformaat [breedte, hoogte] in punten, en het aantal pagina's */
    private function grootstePagina(int &$aantal): array
    {
        $aantal = 0;
        $max = [0.0, 0.0];
        $root = $this->los($this->trailer['Root'] ?? null);
        $pages = $root['v']['Pages'] ?? null;
        $gezien = [];
        $loop = function ($knoop, $box, int $diepte) use (&$loop, &$aantal, &$max, &$gezien): void {
            if ($diepte > 64) {
                return;
            }
            if (($knoop['t'] ?? '') === 'ref') {
                if (isset($gezien[$knoop['v'][0]])) {
                    return;
                }
                $gezien[$knoop['v'][0]] = true;
            }
            $k = $this->los($knoop);
            if (($k['t'] ?? '') !== 'dict') {
                return;
            }
            $mb = $this->los($k['v']['MediaBox'] ?? null);
            if (($mb['t'] ?? '') === 'arr' && count($mb['v']) === 4) {
                $g = array_map(fn($x) => (float) $this->waarde($x), $mb['v']);
                $box = [abs($g[2] - $g[0]), abs($g[3] - $g[1])];
            }
            $kids = $this->los($k['v']['Kids'] ?? null);
            if (($kids['t'] ?? '') === 'arr') {
                foreach ($kids['v'] as $kind) {
                    $loop($kind, $box, $diepte + 1);
                }
            } elseif ($this->naam($k['v']['Type'] ?? null) === 'Page' || !isset($k['v']['Kids'])) {
                $aantal++;
                if ($box[0] * $box[1] > $max[0] * $max[1]) {
                    $max = $box;
                }
            }
        };
        if ($pages) {
            $loop($pages, [612.0, 792.0], 0);
        }
        if ($max[0] < 1) {
            $max = [842.0, 1191.0]; // onbekend: A3 aannemen (veilig: verkleint minder)
        }
        return $max;
    }

    // ------------------------------------------------------------------
    // Tokenizer
    // ------------------------------------------------------------------

    private function ws(int $p): int
    {
        while ($p < $this->n) {
            $c = $this->d[$p];
            if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\f" || $c === "\0") {
                $p++;
            } elseif ($c === '%') {
                while ($p < $this->n && $this->d[$p] !== "\n" && $this->d[$p] !== "\r") {
                    $p++;
                }
            } else {
                break;
            }
        }
        return $p;
    }

    private function scheiding(string $c): bool
    {
        return strpos(" \n\r\t\f\0()<>[]{}/%", $c) !== false;
    }

    /** Leest één PDF-waarde vanaf $p. Geeft [waarde, positie erna] */
    private function waardeOp(int $p, int $diepte = 0): array
    {
        if ($diepte > 100) {
            throw new \RuntimeException('te diep genest');
        }
        $p = $this->ws($p);
        $begin = $p;
        $c = $this->d[$p] ?? '';
        if ($c === '') {
            throw new \RuntimeException('onverwacht einde');
        }
        if ($c === '<' && ($this->d[$p + 1] ?? '') === '<') {
            $p += 2;
            $v = [];
            while (true) {
                $p = $this->ws($p);
                if (substr($this->d, $p, 2) === '>>') {
                    $p += 2;
                    break;
                }
                if ($p >= $this->n) {
                    throw new \RuntimeException('woordenboek niet afgesloten');
                }
                [$k, $p] = $this->waardeOp($p, $diepte + 1);
                if ($k['t'] !== 'name') {
                    throw new \RuntimeException('ongeldige sleutel');
                }
                [$w, $p] = $this->waardeOp($p, $diepte + 1);
                $v[$k['v']] = $w;
            }
            return [['t' => 'dict', 'v' => $v, 'raw' => substr($this->d, $begin, $p - $begin)], $p];
        }
        if ($c === '<') {
            $e = strpos($this->d, '>', $p);
            if ($e === false) {
                throw new \RuntimeException('hex-tekst niet afgesloten');
            }
            return [['t' => 'str', 'v' => null, 'raw' => substr($this->d, $p, $e + 1 - $p)], $e + 1];
        }
        if ($c === '(') {
            $diep = 0;
            for ($i = $p; $i < $this->n; $i++) {
                $ch = $this->d[$i];
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === '(') {
                    $diep++;
                } elseif ($ch === ')') {
                    if (--$diep === 0) {
                        return [['t' => 'str', 'v' => null, 'raw' => substr($this->d, $p, $i + 1 - $p)], $i + 1];
                    }
                }
            }
            throw new \RuntimeException('tekst niet afgesloten');
        }
        if ($c === '[') {
            $p++;
            $v = [];
            while (true) {
                $p = $this->ws($p);
                if (($this->d[$p] ?? '') === ']') {
                    $p++;
                    break;
                }
                if ($p >= $this->n) {
                    throw new \RuntimeException('lijst niet afgesloten');
                }
                [$w, $p] = $this->waardeOp($p, $diepte + 1);
                $v[] = $w;
            }
            return [['t' => 'arr', 'v' => $v, 'raw' => substr($this->d, $begin, $p - $begin)], $p];
        }
        if ($c === '/') {
            $p++;
            $s = $p;
            while ($p < $this->n && !$this->scheiding($this->d[$p])) {
                $p++;
            }
            $naam = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn($m) => chr(hexdec($m[1])), substr($this->d, $s, $p - $s));
            return [['t' => 'name', 'v' => $naam, 'raw' => substr($this->d, $begin, $p - $begin)], $p];
        }
        // getal, verwijzing of sleutelwoord
        $s = $p;
        while ($p < $this->n && !$this->scheiding($this->d[$p])) {
            $p++;
        }
        $tok = substr($this->d, $s, $p - $s);
        if ($tok === '') {
            throw new \RuntimeException('onverwacht teken');
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)$/', $tok)) {
            if (ctype_digit($tok) && preg_match('/\G\s+(\d+)\s+R(?=[\s\0()<>\[\]{}\/%]|$)/', $this->d, $m, 0, $p)) {
                $e = $p + strlen($m[0]);
                return [['t' => 'ref', 'v' => [(int) $tok, (int) $m[1]], 'raw' => substr($this->d, $begin, $e - $begin)], $e];
            }
            $num = strpbrk($tok, '.') === false ? (int) $tok : (float) $tok;
            return [['t' => 'num', 'v' => $num, 'raw' => $tok], $p];
        }
        if ($tok === 'true' || $tok === 'false') {
            return [['t' => 'bool', 'v' => $tok === 'true', 'raw' => $tok], $p];
        }
        return [['t' => 'kw', 'v' => $tok, 'raw' => $tok], $p];
    }

    // ------------------------------------------------------------------
    // Kleine hulpjes
    // ------------------------------------------------------------------

    /** Verwijzing oplossen naar de waarde zelf */
    private function los($v, int $diepte = 0)
    {
        while (is_array($v) && ($v['t'] ?? '') === 'ref' && $diepte++ < 20) {
            $o = $this->obj($v['v'][0]);
            $v = $o['val'] ?? null;
        }
        return $v;
    }

    private function waarde($v)
    {
        $v = $this->los($v);
        return is_array($v) ? ($v['v'] ?? null) : null;
    }

    private function naam($v): ?string
    {
        $v = $this->los($v);
        return (is_array($v) && ($v['t'] ?? '') === 'name') ? $v['v'] : null;
    }

    private function naamUit(string $naam): string
    {
        return preg_replace_callback('/[^\x21-\x7E]|[#()<>\[\]{}\/%]/', fn($m) => sprintf('#%02X', ord($m[0])), $naam);
    }
}
