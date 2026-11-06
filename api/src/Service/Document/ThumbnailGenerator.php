<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

use MyInvoice\Support\Imaging\ImagickGuard;

/**
 * Generuje náhledy (thumbnaily) pro PDF (1. strana) a rastrové obrázky.
 * Best-effort: Imagick (pokud dostupný) zvládá PDF i obrázky, jinak GD jen
 * rastrové obrázky. Když nic — status 'unsupported'. Chyba → 'failed'.
 *
 * Náhled: storage/documents/sup-{id}/_thumbs/{sha8}.jpg (max strana 480 px).
 */
final class ThumbnailGenerator
{
    private const MAX_DIM = 480;
    /** GD dekóduje celý rastr do paměti PHP (~4 B/px), proto nižší strop než Imagick. */
    private const GD_MAX_PIXELS = 40_000_000;

    /**
     * @return array{status:'generated'|'unsupported'|'failed', path:?string}
     */
    public function generate(string $absPath, string $docType, string $sha256, int $supplierId): array
    {
        if ($docType !== 'pdf' && $docType !== 'image') {
            return ['status' => 'unsupported', 'path' => null];
        }

        $dir = DocumentStorage::thumbsDir($supplierId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['status' => 'failed', 'path' => null];
        }
        $name = substr($sha256, 0, 8) . '.jpg';
        $out = $dir . '/' . $name;

        try {
            if (class_exists(\Imagick::class)) {
                if ($this->viaImagick($absPath, $docType, $out)) {
                    return ['status' => 'generated', 'path' => $name];
                }
            }
            if ($docType === 'image' && function_exists('imagecreatetruecolor')) {
                if ($this->viaGd($absPath, $out)) {
                    return ['status' => 'generated', 'path' => $name];
                }
            }
        } catch (\Throwable) {
            if (is_file($out)) @unlink($out);
            return ['status' => 'failed', 'path' => null];
        }
        return ['status' => 'unsupported', 'path' => null];
    }

    /**
     * Doc_type pochází z přípony, obsah ale určuje uživatel. Coder proto bereme z magic
     * bytes a vynucujeme prefixem; nepodporovaný obsah do Imagicku vůbec nepustíme.
     * Rastr musí projít stropem pixelů už při pingImage(), tedy před dekódováním.
     */
    private function viaImagick(string $src, string $docType, string $out): bool
    {
        if ($docType === 'pdf') {
            if (!ImagickGuard::isPdf($src)) {
                return false;
            }
            $spec = 'pdf:' . $src . '[0]'; // jen první strana
        } else {
            $coder = ImagickGuard::rasterCoder($src);
            if ($coder === null) {
                return false;
            }
            $spec = $coder . ':' . $src . '[0]'; // jen první snímek
        }

        return ImagickGuard::withLimits(function () use ($spec, $docType, $out): bool {
            $im = new \Imagick();
            if ($docType === 'pdf') {
                $im->setResolution(120, 120);
                $im->readImage($spec);
                $im->setImageBackgroundColor('white');
                $im = $im->flattenImages();
            } else {
                $size = ImagickGuard::ping($spec);
                if ($size === null || !ImagickGuard::withinPixelLimit($size[0], $size[1])) {
                    return false;
                }
                // JPEG stačí dekódovat ve zmenšeném měřítku (libjpeg DCT scaling).
                if (str_starts_with($spec, 'jpeg:')) {
                    $im->setOption('jpeg:size', (self::MAX_DIM * 2) . 'x' . (self::MAX_DIM * 2));
                }
                $im->readImage($spec);
            }
            $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            $im->thumbnailImage(self::MAX_DIM, self::MAX_DIM, true);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(82);
            $ok = $im->writeImage('jpeg:' . $out);
            $im->clear();
            $im->destroy();
            return $ok && is_file($out);
        });
    }

    private function viaGd(string $src, string $out): bool
    {
        $info = @getimagesize($src);
        if ($info === false) return false;
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || (int) ($w * $h) > self::GD_MAX_PIXELS) return false; // pixel-bomb guard

        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_GIF  => @imagecreatefromgif($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            IMAGETYPE_BMP  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($src) : false,
            default        => false,
        };
        if (!$img) return false;

        $scale = min(self::MAX_DIM / $w, self::MAX_DIM / $h, 1.0);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = imagejpeg($dst, $out, 82);
        imagedestroy($img);
        imagedestroy($dst);
        return $ok && is_file($out);
    }
}
