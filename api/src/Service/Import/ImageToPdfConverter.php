<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Převod nahraného obrázku (fotka účtenky/faktury z telefonu) na PDF.
 *
 * Motivace (issue #75): import přijatých faktur umí jen PDF. Místo abychom
 * obrázky tahali skrz celý pipeline (AI extrakce, archivace, preview, download),
 * normalizujeme je na vstupu na PDF — downstream zůstává beze změny.
 *
 * Postup:
 *   1. JPG/PNG/WEBP/GIF/BMP → dekód přes GD; HEIC/HEIF → přes Imagick (pokud je).
 *   2. Auto-rotace dle EXIF (nakřivo nafocené účtenky).
 *   3. Downscale delší hrany na MAX_DIM (menší soubor + méně AI tokenů).
 *   4. Re-encode jako JPEG (PDF umí JPEG vložit nativně bez ztráty — DCTDecode).
 *   5. Zabalit do PDF stránky s poměrem stran obrázku (žádné bílé okraje).
 *
 * Výstup je validní PDF (začíná `%PDF`), takže projde i `%PDF` guardem v
 * {@see AnthropicClient::extractInvoice()} i magic-bytes kontrolou při archivaci.
 */
final class ImageToPdfConverter
{
    /** Delší hrana po downscale (px). 1600 stačí AI i pro čitelnost účtenky a
     *  drží výstupní PDF malé (~150–300 kB místo násobků MB u syrové fotky). */
    private const MAX_DIM = 1600;
    private const JPEG_QUALITY = 72;

    /** MIME typy přes GD. HEIC/HEIF jen když je Imagick s podporou (runtime). */
    private const GD_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp'];
    private const HEIC_MIMES = ['image/heic', 'image/heif'];

    /** Sniff MIME z prvních bajtů (magic bytes), nezávisle na klientském Content-Type. */
    public function detectImageMime(string $bytes): ?string
    {
        if (strlen($bytes) < 12) {
            return null;
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($bytes, 'BM')) {
            return 'image/bmp';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        // ISO-BMFF (HEIC/HEIF): ....ftyp<brand>
        if (substr($bytes, 4, 4) === 'ftyp') {
            $brand = substr($bytes, 8, 4);
            if (in_array($brand, ['heic', 'heix', 'hevc', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'], true)) {
                return 'image/heic';
            }
        }
        return null;
    }

    /** Je tento MIME obrázek, který umíme zpracovat v tomto prostředí? */
    public function isSupportedImage(string $mime): bool
    {
        $mime = strtolower($mime);
        if (in_array($mime, self::GD_MIMES, true)) {
            return true;
        }
        if (in_array($mime, self::HEIC_MIMES, true)) {
            return $this->imagickSupportsHeic();
        }
        return false;
    }

    /**
     * Převede obrázek na PDF. Vrací PDF bajty.
     *
     * @throws \RuntimeException když formát není podporovaný nebo dekód selže.
     */
    public function convert(string $bytes, string $mime): string
    {
        $mime = strtolower($mime);

        if (in_array($mime, self::HEIC_MIMES, true)) {
            if (!$this->imagickSupportsHeic()) {
                throw new \RuntimeException(
                    'Formát HEIC/HEIF není na tomto serveru podporován. Převeďte fotku do JPEG a nahrajte znovu.'
                );
            }
            $bytes = $this->heicToJpeg($bytes);
            $mime = 'image/jpeg';
        } elseif (!in_array($mime, self::GD_MIMES, true)) {
            throw new \RuntimeException("Nepodporovaný formát obrázku: {$mime}.");
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('GD rozšíření není dostupné — obrázky nelze zpracovat.');
        }

        $img = @imagecreatefromstring($bytes);
        if (!$img instanceof \GdImage) {
            throw new \RuntimeException('Obrázek se nepodařilo načíst (poškozený soubor?).');
        }

        try {
            // EXIF rotace jen u JPEG (jediný formát s Orientation tagem v praxi).
            if ($mime === 'image/jpeg') {
                $img = $this->applyExifRotation($img, $bytes);
            }

            $w = imagesx($img);
            $h = imagesy($img);
            if ($w < 1 || $h < 1) {
                throw new \RuntimeException('Obrázek má neplatné rozměry.');
            }

            // Downscale delší hrany na MAX_DIM.
            $scale = min(1.0, self::MAX_DIM / max($w, $h));
            if ($scale < 1.0) {
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $resized = imagecreatetruecolor($nw, $nh);
                // Bílé pozadí (JPEG nemá alfu) — pro PNG/WEBP s průhledností.
                $white = imagecolorallocate($resized, 255, 255, 255);
                imagefilledrectangle($resized, 0, 0, $nw, $nh, $white);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $img = $resized; // starý $img se uvolní GC (imagedestroy je v PHP 8.5 deprecated no-op)
                $w = $nw;
                $h = $nh;
            }

            ob_start();
            imagejpeg($img, null, self::JPEG_QUALITY);
            $jpeg = (string) ob_get_clean();
        } finally {
            unset($img);
        }

        return $this->wrapJpegInPdf($jpeg, $w, $h);
    }

    /**
     * Zabalí JPEG do PDF stránky s poměrem stran obrázku (bez bílých okrajů).
     * Stránku škálujeme tak, aby delší hrana byla A4 (297 mm).
     */
    private function wrapJpegInPdf(string $jpeg, int $wPx, int $hPx): string
    {
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $longMm = 297.0;
        if ($wPx >= $hPx) {
            $pageW = $longMm;
            $pageH = $longMm * $hPx / max(1, $wPx);
        } else {
            $pageH = $longMm;
            $pageW = $longMm * $wPx / max(1, $hPx);
        }
        $pageW = round($pageW, 2);
        $pageH = round($pageH, 2);

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => [$pageW, $pageH],
            'margin_left'   => 0,
            'margin_right'  => 0,
            'margin_top'    => 0,
            'margin_bottom' => 0,
            'tempDir'       => $tmpDir,
            // I když stránka nese jen obrázek, mPDF inicializuje default font;
            // bez tohoto sahá po serif (DejaVuSerifCondensed.ttf) a spadne.
            'default_font'  => 'dejavusans',
        ]);
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('MyÚčto.cz');

        $dataUri = 'data:image/jpeg;base64,' . base64_encode($jpeg);
        // Procentní HTML výška může obrázek zmáčknout do čtverce. Umístíme ho přímo
        // přes celou stránku v mm, bez omezení na tiskovou oblast a bez HTML layoutu.
        $mpdf->AddPage();
        $mpdf->Image($dataUri, 0, 0, $pageW, $pageH, 'jpg', '', true, false);

        return (string) $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** Aplikuje EXIF Orientation na GD obrázek (vrací nový/otočený resource). */
    private function applyExifRotation(\GdImage $img, string $bytes): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        try {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        } catch (\Throwable) {
            return $img;
        }
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);
        return $rotated instanceof \GdImage ? $rotated : $img;
    }

    /** Konvertuje HEIC/HEIF bajty na JPEG bajty přes Imagick. */
    private function heicToJpeg(string $bytes): string
    {
        try {
            $im = new \Imagick();
            $im->readImageBlob($bytes);
            if ($im->getNumberImages() > 1) {
                $im->setIteratorIndex(0);
                $im = $im->getImage();
            }
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(self::JPEG_QUALITY);
            $jpeg = $im->getImageBlob();
            $im->clear();
            return $jpeg;
        } catch (\Throwable $e) {
            throw new \RuntimeException('HEIC se nepodařilo zpracovat: ' . $e->getMessage());
        }
    }

    /** Má prostředí Imagick s podporou HEIC/HEIF? (runtime detekce, ne tvrdá závislost) */
    private function imagickSupportsHeic(): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }
        try {
            $formats = array_map('strtoupper', \Imagick::queryFormats('HE*'));
            return in_array('HEIC', $formats, true) || in_array('HEIF', $formats, true);
        } catch (\Throwable) {
            return false;
        }
    }
}
