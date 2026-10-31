<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Bootstrap;
use MyInvoice\Support\Imaging\ImagickGuard;
use Psr\Log\LoggerInterface;

/**
 * Příjme uploadnutý soubor (PNG / JPG / SVG) a uloží ho jako transparentní PNG
 * pro použití jako logo v hlavičce emailů.
 *
 * Pipeline:
 *   1. Detekuj formát z obsahu (magic bytes / MIME).
 *   2. SVG → rasterizuj na transparent PNG (max-height 480px). Strategie:
 *        a) PHP `Imagick` extension (cross-platform — Linux i Windows, pokud
 *           má `pecl install imagick` + ImageMagick s SVG delegate).
 *        b) Fallback: `rsvg-convert` CLI (z balíčku `librsvg2-bin`, Linux/macOS).
 *        c) Pokud žádný nedostupný — srozumitelná chyba s návodem.
 *   3. PNG / JPG / WebP → načti GD, downsample na max-height 480px (zachová
 *      poměr stran), ulož jako PNG s alfa kanálem.
 *
 * Cílový soubor: storage/supplier-logos/sup-{id}.png (relativně k rootDir).
 */
final class SupplierLogoConverter
{
    /**
     * Cílová max-height v emailu je 48 px; ukládáme 240 px (5× retina) pro
     * crispness i na 4K displejích — větší by jen zvětšovalo přílohy bez
     * vizuálního přínosu (email klienti downscalují agresivně).
     */
    private const MAX_HEIGHT_PX = 240;
    private const MAX_WIDTH_PX  = 800;
    private const MAX_INPUT_BYTES = 1_048_576; // 1 MiB upload limit
    /** Pixel-bomb protection — odmítne dekódovaný obrázek nad 12 MP. */
    private const MAX_DECODED_PIXELS = 12_000_000;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param string $sourcePath  Cesta k uploadnutému dočasnému souboru
     * @param int    $supplierId
     * @return array{logo_path: string, abs_path: string, width: int, height: int}
     * @throws \RuntimeException Pro user-facing chyby (přepošleme jako HTTP 4xx)
     */
    public function process(string $sourcePath, int $supplierId, ?int $brandingProfileId = null): array
    {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Source soubor nenalezen.');
        }
        $size = (int) @filesize($sourcePath);
        if ($size <= 0) {
            throw new \RuntimeException('Soubor je prázdný.');
        }
        if ($size > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException('Soubor je příliš velký (max 1 MiB).');
        }

        $mime = $this->detectMime($sourcePath);

        $targetDir  = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('supplier-logos');
        $baseName = 'sup-' . $supplierId;
        if ($brandingProfileId !== null) {
            if ($brandingProfileId <= 0) throw new \RuntimeException('Neplatný brandingový profil.');
            $hash = substr(hash_file('sha256', $sourcePath), 0, 12);
            $baseName .= '-brand-' . $brandingProfileId . '-' . $hash;
        }
        $targetPath = $targetDir . '/' . $baseName . '.png';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }
        if (!is_writable($targetDir)) {
            throw new \RuntimeException('Adresář pro loga není zapisovatelný.');
        }

        // SVG: ulož originál vedle PNG (PDF přes mPDF preferuje vektor pro
        // crisp render při libovolné velikosti / zvětšení). Email naopak vždy
        // používá PNG, protože Outlook/Gmail SVG nepodporují.
        $svgSidecar = $targetDir . '/' . $baseName . '.svg';
        @unlink($svgSidecar); // čistka po předchozím uploadu
        // Cache bíle-podloženého PDF loga (issue #152) — zneplatni po re-uploadu.
        \MyInvoice\Service\Pdf\PdfLogoFlattener::cleanup($targetPath);

        if ($mime === 'image/svg+xml') {
            // Bezpečnost: sanitizace PŘED rasterizací (žádné `<script>`, externí entity,
            // externí odkazy ani CSS url()/@import). Rasterizér i uložený originál
            // dostanou totéž očištěné SVG, surový upload se do renderu nedostane.
            $svgClean = $this->sanitizeSvg((string) @file_get_contents($sourcePath));
            if ($svgClean === '') {
                throw new \RuntimeException('SVG soubor nelze zpracovat.');
            }
            $cleanPath = $targetDir . '/.' . $baseName . '-' . bin2hex(random_bytes(6)) . '.svg';
            if (@file_put_contents($cleanPath, $svgClean) === false) {
                throw new \RuntimeException('Nepodařilo se uložit logo.');
            }
            try {
                $this->convertSvgToPng($cleanPath, $targetPath);
            } finally {
                @unlink($cleanPath);
            }
            @file_put_contents($svgSidecar, $svgClean);
        } elseif (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $this->convertRasterToPng($sourcePath, $targetPath, $mime);
        } else {
            throw new \RuntimeException('Nepodporovaný formát: ' . $mime . '. Povolené: PNG, JPG, SVG.');
        }

        // Verify výstup
        $info = @getimagesize($targetPath);
        if ($info === false) {
            throw new \RuntimeException('Konverze loga selhala (output není validní obrázek).');
        }

        return [
            'logo_path' => 'storage/supplier-logos/' . $baseName . '.png',
            'abs_path'  => $targetPath,
            'width'     => (int) $info[0],
            'height'    => (int) $info[1],
        ];
    }

    /**
     * Smaže logo (PNG i případný SVG sidecar) pro daného supplier — idempotentní.
     */
    public function delete(int $supplierId): void
    {
        $base = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('supplier-logos') . '/sup-' . $supplierId;
        foreach (['.png', '.svg', '.pdf.png'] as $ext) {
            if (is_file($base . $ext)) @unlink($base . $ext);
        }
    }

    /** Vrátí absolutní cestu k PNG logu pokud existuje, jinak null. (Email — vždy PNG.) */
    public function absPathFor(int $supplierId): ?string
    {
        $path = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('supplier-logos') . '/sup-' . $supplierId . '.png';
        return is_file($path) ? $path : null;
    }

    /**
     * Vrátí absolutní cestu pro PDF render — preferuje SVG (vektor = crisp v PDF),
     * fallback na PNG. Volá InvoicePdfRenderer::resolveLogoPath.
     */
    public function pdfPathFor(int $supplierId): ?string
    {
        $base = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('supplier-logos') . '/sup-' . $supplierId;
        if (is_file($base . '.svg')) return $base . '.svg';
        if (is_file($base . '.png')) return $base . '.png';
        return null;
    }

    private function detectMime(string $path): string
    {
        // Prioritně přes finfo (čte magic bytes — důvěryhodné). PHP 8.5+:
        // finfo_close je deprecated (objekt se uvolní GC), takže neuzavíráme.
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $path);
                if ($mime !== '') {
                    // SVG je text/xml ze finfa — zkontroluj manuálně
                    if (str_starts_with($mime, 'text/') || $mime === 'application/xml') {
                        $head = (string) @file_get_contents($path, false, null, 0, 512);
                        if (preg_match('/<svg[\s>]/i', $head)) {
                            return 'image/svg+xml';
                        }
                    }
                    return $mime;
                }
            }
        }
        // Fallback — getimagesize
        $info = @getimagesize($path);
        if ($info !== false && isset($info['mime'])) {
            return (string) $info['mime'];
        }
        // Poslední fallback — SVG signature
        $head = (string) @file_get_contents($path, false, null, 0, 512);
        if (preg_match('/<svg[\s>]/i', $head)) {
            return 'image/svg+xml';
        }
        return 'application/octet-stream';
    }

    /**
     * SVG → transparentní PNG. Zkusí v pořadí:
     *   1. PHP Imagick extension (cross-platform)
     *   2. rsvg-convert CLI (Linux/macOS přes librsvg2-bin)
     *   3. Chyba s instalačním návodem
     */
    private function convertSvgToPng(string $sourcePath, string $targetPath): void
    {
        if (class_exists(\Imagick::class) && $this->convertSvgViaImagick($sourcePath, $targetPath)) {
            return;
        }
        if ($this->convertSvgViaRsvg($sourcePath, $targetPath)) {
            return;
        }
        throw new \RuntimeException(
            'SVG konverze není dostupná: na hostu chybí PHP `imagick` extension '
            . 'i nástroj `rsvg-convert`. Nainstaluj jedno z toho '
            . '(Linux: `apt install librsvg2-bin` nebo `apt install php-imagick`; '
            . 'macOS: `brew install librsvg`; '
            . 'Windows: `pecl install imagick` + ImageMagick s SVG delegate), '
            . 'nebo nahraj logo ve formátu PNG / JPG.'
        );
    }

    /**
     * SVG via Imagick. Vrací true při úspěchu, false pokud Imagick neumí SVG
     * (chybí delegate) — caller pak zkusí rsvg-convert.
     *
     * Obecný coder SVG může i při pingImage spustit externí Inkscape a vykreslit
     * celý nativní rastr mimo limity Imagicku. Rozměry proto čteme interním MSVG
     * a vykreslujeme explicitním RSVG/MSVG až s omezeným cílovým rozměrem.
     */
    private function convertSvgViaImagick(string $sourcePath, string $targetPath): bool
    {
        try {
            $hasRsvg = \Imagick::queryFormats('RSVG') !== [];
            $probeCoder = \Imagick::queryFormats('MSVG') !== [] ? 'msvg:' : ($hasRsvg ? 'rsvg:' : null);
            if ($probeCoder === null) return false;
            return $this->rasterizeSvgViaImagick($sourcePath, $targetPath, $hasRsvg ? 'rsvg:' : 'msvg:', $probeCoder);
        } catch (\Throwable $e) {
            $this->logger->info('Imagick SVG conversion failed, will try rsvg fallback: ' . $e->getMessage());
            return false;
        }
    }

    /** Explicitní coder sdílí normalizaci viewportu i stejné limity na všech platformách. */
    private function rasterizeSvgViaImagick(string $sourcePath, string $targetPath, string $coder, string $probeCoder): bool
    {
        $normalized = null;
        try {
            $document = new \DOMDocument();
            $svg = file_get_contents($sourcePath);
            if ($svg === false || !@$document->loadXML($svg, LIBXML_NONET) || $document->doctype !== null) return false;
            $root = $document->documentElement;
            if ($root === null || $root->localName !== 'svg') return false;
            $viewBox = trim($root->getAttribute('viewBox'));
            if ($viewBox !== '') {
                $values = preg_split('/[\s,]+/', $viewBox);
                if (count($values) !== 4) return false;
                foreach ($values as $value) {
                    if (!is_numeric($value) || !is_finite((float) $value)) return false;
                }
                if ((float) $values[2] <= 0 || (float) $values[3] <= 0) return false;
                // Některé buildy MSVG odmítají chybějící rozměry a procentní
                // viewport renderují prázdný. Samostatné logo nemá rodičovský viewport.
                foreach (['width' => 2, 'height' => 3] as $attribute => $index) {
                    $value = trim($root->getAttribute($attribute));
                    if ($value === '' || str_ends_with($value, '%')) $root->setAttribute($attribute, $values[$index]);
                }
            }
            // Samostatný SVG obrázek bez přirozených rozměrů používá výchozí
            // viewport 300 × 150 (SVG Integration, chybějící sizing attributes):
            // https://svgwg.org/specs/integration/#svg-css-sizing
            // MSVG jej samo nedoplní; obecný SVG coder by přitom spustil externí renderer.
            if ($viewBox === '') {
                foreach (['width' => 300, 'height' => 150] as $attribute => $fallback) {
                    $value = trim($root->getAttribute($attribute));
                    if ($value === '' || $value === 'auto') {
                        $root->setAttribute($attribute, (string) $fallback);
                    } elseif (preg_match('/\A([0-9]+(?:\.[0-9]+)?)%\z/D', $value, $percentage)) {
                        $root->setAttribute($attribute, (string) ($fallback * (float) $percentage[1] / 100));
                    }
                }
            }
            $normalized = tempnam(sys_get_temp_dir(), 'logo-svg-');
            if ($normalized === false) return false;
            if ($document->save($normalized) === false) return false;
            return ImagickGuard::withLimits(function () use ($normalized, $targetPath, $coder, $probeCoder, $document, $root, $viewBox): bool {
                $size = ImagickGuard::ping($probeCoder . $normalized);
                if ($size === null) return false;
                [$natW, $natH] = $size;
                // Malé vektory zvětšíme pro retinu. Subpixelovou hranu RSVG
                // zaokrouhluje na nulu, proto mezirastr má nejkratší hranu 1 px.
                $scale = max(min(self::MAX_WIDTH_PX / $natW, self::MAX_HEIGHT_PX / $natH), 1 / min($natW, $natH));
                $width = max(1, (int) round($natW * $scale));
                $height = max(1, (int) round($natH * $scale));
                if (!ImagickGuard::withinPixelLimit($width, $height, self::MAX_DECODED_PIXELS)) return false;
                // Měníme jen rasterizační kopii; původní sanitizované SVG zůstává
                // pro PDF. Explicitní viewport zabrání prázdnému PNG z MSVG.
                if ($viewBox === '') $root->setAttribute('viewBox', "0 0 $natW $natH");
                $root->setAttribute('width', (string) $width);
                $root->setAttribute('height', (string) $height);
                if ($document->save($normalized) === false) return false;
                $im = new \Imagick();
                try {
                    $im->setBackgroundColor(new \ImagickPixel('transparent'));
                    $im->setSize($width, $height);
                    $im->readImage($coder . $normalized);
                    $im->setImageFormat('png32');
                    if ($im->getImageHeight() > self::MAX_HEIGHT_PX || $im->getImageWidth() > self::MAX_WIDTH_PX) {
                        [$width, $height] = $this->fitTo($im->getImageWidth(), $im->getImageHeight(), self::MAX_WIDTH_PX, self::MAX_HEIGHT_PX);
                        // Best-fit může zaokrouhlit úzkou hranu na nulu; fitTo ji
                        // bezpečně ponechá na jednom pixelu.
                        $im->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, false);
                    }
                    return $im->writeImage('png32:' . $targetPath) && is_file($targetPath);
                } finally {
                    $im->clear();
                }
            });
        } catch (\Throwable $e) {
            $this->logger->info('Imagick SVG conversion failed, will try rsvg fallback: ' . $e->getMessage());
            if (is_file($targetPath)) @unlink($targetPath);
            return false;
        } finally {
            if (is_string($normalized)) @unlink($normalized);
        }
    }

    /**
     * Bezpečnostní sanitizace SVG před uložením na disk (PDF render):
     *   - žádný `<script>` nebo `on*` event handler
     *   - žádné `<foreignObject>` (může vložit HTML/JS)
     *   - žádné externí reference (`xlink:href` nebo `href` na external URL)
     *   - žádné `<!ENTITY>` (XXE)
     * Vrací očištěný SVG, nebo prázdný string pokud sanitizace selhala.
     */
    private function sanitizeSvg(string $svg): string
    {
        if ($svg === '') return '';
        // XML XXE: odstraň DOCTYPE i s internal subset (Adobe Illustrator typicky
        // exportuje `<!DOCTYPE svg PUBLIC "..." "..." [ <!ENTITY ns_X "..."> ... ]>`
        // — `[^>]*` by se ukousnul na první `>` uvnitř ENTITY decl. Použijeme
        // greedy match přes `]>` pokud existuje, jinak fallback na `>`).
        $svg = (string) preg_replace('/<!DOCTYPE[^>\[]*\[[^\]]*\]\s*>/is', '', $svg);
        $svg = (string) preg_replace('/<!DOCTYPE[^>]*>/is', '', $svg);
        $svg = (string) preg_replace('/<!ENTITY[^>]*>/is', '', $svg);
        // Orphan internal subset closers (`]>`) z DOCTYPE bez subjektu
        $svg = (string) preg_replace('/^\s*\]\s*>\s*/m', '', $svg);
        // Externí styl přes processing instruction xml-stylesheet s atributem href
        $svg = (string) preg_replace('/<\?xml-stylesheet\b.*?\?>/is', '', $svg);
        // Skript a foreignObject elementy (i s obsahem)
        $svg = (string) preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $svg);
        $svg = (string) preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject\s*>#is', '', $svg);
        // XInclude (librsvg ho umí a načetl by lokální soubor), párový i self-closing
        $svg = (string) preg_replace('#<([\w.-]+:)?include\b[^>]*/>#is', '', $svg);
        $svg = (string) preg_replace('#<([\w.-]+:)?include\b[^>]*>.*?</([\w.-]+:)?include\s*>#is', '', $svg);
        // on* event attributes (onclick, onload, …)
        $svg = (string) preg_replace('/\s+on[a-z]+\s*=\s*"[^"]*"/is', '', $svg);
        $svg = (string) preg_replace("/\s+on[a-z]+\s*=\s*'[^']*'/is", '', $svg);
        // External href s libovolným namespace prefixem (data: URI nechat, base64 obrázek je OK)
        $svg = (string) preg_replace('/\s+(?:[\w.-]+:)?href\s*=\s*"(?!#|data:)[^"]*"/i', '', $svg);
        $svg = (string) preg_replace("/\s+(?:[\w.-]+:)?href\s*=\s*'(?!#|data:)[^']*'/i", '', $svg);
        // CSS: @import a url() mimo fragment (#id) a data: URI
        $svg = (string) preg_replace('/@import\b[^;]*;?/i', '', $svg);
        $svg = (string) preg_replace('/url\(\s*(?![\'"]?\s*(?:#|data:))[^)]*\)/i', 'none', $svg);
        // Orphan xmlns entity refs (`xmlns:x="&ns_extend;"` apod. — DOCTYPE/ENTITY
        // pryč, ale namespace deklarace zůstaly. Bez nich mPDF SVG parser umírá
        // a vykresluje černé pozadí.) Najdeme všechny "broken" prefixy a kromě
        // xmlns deklarace stripneme i všechny `<prefix:el>...</prefix:el>` a
        // `prefix:attr="..."` použití (XML parser jinak vyhodí "Namespace prefix
        // X is not defined" warning při dalším parsování).
        $brokenPrefixes = [];
        if (preg_match_all('/\bxmlns:(\w+)\s*=\s*["\']&\w+;["\']/i', $svg, $mm)) {
            $brokenPrefixes = array_unique($mm[1]);
        }
        // Stripni xmlns:X="&Y;" deklarace
        $svg = (string) preg_replace('/\s+xmlns:\w+\s*=\s*["\']&\w+;["\']/i', '', $svg);
        // Stripni `<prefix:el ...>...</prefix:el>` (self-closing i párové) a
        // `prefix:attr="..."` na všech tagech pro každý broken prefix.
        foreach ($brokenPrefixes as $p) {
            $pq = preg_quote($p, '/');
            // Párové: `<p:el ...>...</p:el>` (greedy přes řádky)
            $svg = (string) preg_replace('#<' . $pq . ':[a-zA-Z][\w-]*\b[^>]*>.*?</' . $pq . ':[a-zA-Z][\w-]*\s*>#is', '', $svg);
            // Self-closing: `<p:el ... />`
            $svg = (string) preg_replace('#<' . $pq . ':[a-zA-Z][\w-]*\b[^>]*/>#is', '', $svg);
            // Otevírací bez ukončení (orphan): `<p:el ...>`
            $svg = (string) preg_replace('#<' . $pq . ':[a-zA-Z][\w-]*\b[^>]*>#is', '', $svg);
            // Atributy `p:attr="..."` nebo `p:attr='...'` na zachovaných tagech
            $svg = (string) preg_replace('/\s+' . $pq . ':[a-zA-Z][\w-]*\s*=\s*"[^"]*"/i', '', $svg);
            $svg = (string) preg_replace("/\s+" . $pq . ":[a-zA-Z][\w-]*\s*=\s*'[^']*'/i", '', $svg);
        }
        // Final sanity check — must still contain <svg
        if (!preg_match('/<svg[\s>]/i', $svg)) {
            return '';
        }
        return $this->ensureSvgNamespace($svg);
    }

    /**
     * Doplní povinný SVG namespace do kořenového `<svg>`, pokud chybí. Bez něj
     * prohlížeč standalone SVG (data: URI v `<img>` — náhled výkazu práce) odmítne
     * vykreslit (parsuje se jako přísné XML → naturalWidth=0, rozbitý obrázek);
     * mPDF to v PDF toleruje, prohlížeč ne. Optimalizované exporty (Illustrator
     * „SVG bez xmlns", apod.) ho často nemají. Idempotentní.
     */
    private function ensureSvgNamespace(string $svg): string
    {
        if (!preg_match('/<svg\b[^>]*\bxmlns\s*=/i', $svg)) {
            $svg = (string) preg_replace(
                '/<svg\b/i',
                '<svg xmlns="http://www.w3.org/2000/svg"',
                $svg,
                1,
            );
        }
        if (preg_match('/\bxlink:/i', $svg) && !preg_match('/<svg\b[^>]*\bxmlns:xlink\s*=/i', $svg)) {
            $svg = (string) preg_replace(
                '/<svg\b/i',
                '<svg xmlns:xlink="http://www.w3.org/1999/xlink"',
                $svg,
                1,
            );
        }
        return $svg;
    }

    /**
     * SVG via rsvg-convert CLI (z balíčku librsvg2-bin). Vrací true/false.
     */
    private function convertSvgViaRsvg(string $sourcePath, string $targetPath): bool
    {
        $bin = $this->findBinary('rsvg-convert');
        if ($bin === null) return false;

        // rsvg-convert -w <maxWidth> -h <maxHeight> -a -f png -o <out> <in>: výstup se vejde
        // do MAX rámečku i u extrémního poměru stran. Background default = transparent.
        $cmd = sprintf(
            '%s -w %d -h %d -a -f png -o %s %s 2>&1',
            escapeshellarg($bin),
            self::MAX_WIDTH_PX,
            self::MAX_HEIGHT_PX,
            escapeshellarg($targetPath),
            escapeshellarg($sourcePath),
        );
        $output = [];
        $exit = -1;
        exec($cmd, $output, $exit);
        if ($exit !== 0 || !is_file($targetPath)) {
            $this->logger->warning('rsvg-convert selhalo: ' . implode("\n", $output));
            return false;
        }
        // Width safety pro extrémně široká text-logo SVG
        $info = @getimagesize($targetPath);
        if ($info !== false && (int) $info[0] > self::MAX_WIDTH_PX) {
            $this->resizePngFile($targetPath, self::MAX_WIDTH_PX, self::MAX_HEIGHT_PX);
        }
        return true;
    }

    /**
     * PNG / JPG / WebP → PNG přes GD, max-height 480px, zachovaný alfa kanál.
     */
    private function convertRasterToPng(string $sourcePath, string $targetPath, string $mime): void
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD extension není dostupný — nelze zpracovat obrázek.');
        }

        // Pixel-bomb protection — odmítni dekódování souborů, které expandnou
        // do enormního rasteru (např. 50 000×50 000 px PNG = 10 GB RAM).
        $info = @getimagesize($sourcePath);
        if ($info !== false && (int) $info[0] * (int) $info[1] > self::MAX_DECODED_PIXELS) {
            throw new \RuntimeException('Obrázek je příliš velký (nad ' . (int) (self::MAX_DECODED_PIXELS / 1_000_000) . ' MP).');
        }

        $src = match ($mime) {
            'image/png'  => @imagecreatefrompng($sourcePath),
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default      => false,
        };
        if (!$src) {
            throw new \RuntimeException('Nepodařilo se načíst obrázek (poškozený soubor?).');
        }

        $w = imagesx($src);
        $h = imagesy($src);

        // Spočítej cílové rozměry
        [$tw, $th] = $this->fitTo($w, $h, self::MAX_WIDTH_PX, self::MAX_HEIGHT_PX);

        if ($tw === $w && $th === $h && $mime === 'image/png') {
            // Bezeztrátový passthrough — PNG na PNG bez resize
            imagedestroy($src);
            if (!@copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Nepodařilo se uložit logo.');
            }
            return;
        }

        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $tw, $th, $transparent);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        if (!imagepng($dst, $targetPath, 6)) {
            imagedestroy($src);
            imagedestroy($dst);
            throw new \RuntimeException('Nepodařilo se zapsat PNG.');
        }
        imagedestroy($src);
        imagedestroy($dst);
    }

    /** Resize již existujícího PNG na disku (in-place). */
    private function resizePngFile(string $path, int $maxW, int $maxH): void
    {
        $src = @imagecreatefrompng($path);
        if (!$src) return;
        [$tw, $th] = $this->fitTo(imagesx($src), imagesy($src), $maxW, $maxH);
        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $tw, $th, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, imagesx($src), imagesy($src));
        imagepng($dst, $path, 6);
        imagedestroy($src);
        imagedestroy($dst);
    }

    /** Spočítej rozměry, které se vejdou do (maxW × maxH) při zachování poměru stran. */
    private function fitTo(int $w, int $h, int $maxW, int $maxH): array
    {
        if ($w <= $maxW && $h <= $maxH) return [$w, $h];
        $ratio = min($maxW / $w, $maxH / $h);
        return [max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio))];
    }

    /** Najde binárku v PATH (přes `which` / `where`). Vrací absolutní cestu nebo null. */
    private function findBinary(string $name): ?string
    {
        $isWin = stripos(PHP_OS_FAMILY, 'win') !== false;
        $cmd = $isWin
            ? 'where ' . escapeshellarg($name) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($name) . ' 2>/dev/null';
        $out = (string) @shell_exec($cmd);
        $lines = preg_split('/\r?\n/', trim($out)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && is_file($line)) return $line;
        }
        return null;
    }
}
