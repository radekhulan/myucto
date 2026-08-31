<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Self-hostované fonty musí být dosažitelné na VŠECH třech webserverech.
 *
 * `web/index.html` preloaduje woff2 absolutní cestou `/fonts/…`, ale skutečné
 * soubory leží v `web/dist/fonts/` (Vite je tam kopíruje z `web/public/fonts`).
 * Každý webserver proto potřebuje vlastní alias — jinak požadavek spadne do SPA
 * fallbacku, vrátí se index.html s Content-Type `text/html` a prohlížeč font
 * TIŠE zahodí (`OTS parsing error: invalid sfntVersion: 1008821359`, což je
 * 0x3C21444F, tedy `<!DO`). Aplikace pak celá běží v systémovém fontu.
 *
 * ⚠️ Chyba se neprojeví ve vývoji: `pnpm dev` servíruje `web/public/` z rootu,
 * takže `/fonts/…` tam funguje bez jakéhokoli pravidla. Rozejde se to až
 * v nasazení — a jen vzhledem, takže si toho nikdo nemusí všimnout. Právě proto
 * je to hlídané testem, a ne jen komentářem u pravidla.
 */
final class SelfHostedFontRoutingParityTest extends TestCase
{
    /** Reprezentativní preloadovaný font — stačí jeden, pravidla jsou prefixová. */
    private const SAMPLE_URI = '/fonts/geist-latin.woff2';

    public function testIndexPreloadsFontsFromAbsolutePath(): void
    {
        $index = self::read(self::repoRoot() . '/web/index.html');
        preg_match_all(
            '#<link[^>]+rel="preload"[^>]+href="(/fonts/[^"]+\.woff2)"#',
            $index,
            $matches,
        );

        self::assertNotEmpty(
            $matches[1],
            'web/index.html musí preloadovat self-hostované fonty z /fonts/ — jinak tenhle test hlídá pravidlo, které už nikdo nepotřebuje.',
        );
        self::assertContains(
            self::SAMPLE_URI,
            $matches[1],
            'Vzorová cesta testu se rozešla s index.html; sjednoť SAMPLE_URI.',
        );

        // Zdrojové soubory pro build. Bez nich by aliasy mířily do prázdna
        // a všechny tři konfigurace by vracely 404 místo fontu.
        foreach ($matches[1] as $uri) {
            $source = self::repoRoot() . '/web/public' . $uri;
            self::assertFileExists(
                $source,
                "Preloadovaný font {$uri} nemá zdroj v web/public/fonts/.",
            );
        }
    }

    public function testApacheRewritesFontsIntoDist(): void
    {
        $configuration = self::read(self::repoRoot() . '/.htaccess');
        preg_match_all(
            '/^\s*RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]+)]\s*$/m',
            $configuration,
            $rules,
            PREG_SET_ORDER,
        );

        $path = ltrim(self::SAMPLE_URI, '/');
        $rewritten = null;
        $fallbackIndex = null;
        foreach ($rules as $index => $rule) {
            // SPA fallback posílá na front controller všechno, co není reálný
            // soubor — pravidlo pro fonty tedy musí být PŘED ním.
            //
            // ⚠️ Bere se POSLEDNÍ takové pravidlo, ne první. Na front controller
            // míří i zámek údržby (pravidlo 2d), a ten stojí před statikou
            // ZÁMĚRNĚ: v údržbě má i font dostat 503 z PHP, ne se odbavit
            // webserverem. Kontrola na první shodu by tenhle správný stav
            // hlásila jako chybu.
            if ($rule[2] === 'api/public/index.php'
                && preg_match('#' . $rule[1] . '#D', $path) === 1
            ) {
                $fallbackIndex = $index;
            }
            if ($rewritten === null
                && preg_match('#' . $rule[1] . '#D', $path) === 1
                && str_starts_with($rule[2], 'web/dist/fonts/')
            ) {
                $rewritten = $index;
            }
        }

        self::assertNotNull(
            $rewritten,
            'Apache musí /fonts/* přepsat na web/dist/fonts/* — jinak je spolkne SPA fallback.',
        );
        if ($fallbackIndex !== null) {
            self::assertLessThan(
                $fallbackIndex,
                $rewritten,
                'Přepis fontů musí v .htaccess předcházet SPA fallbacku.',
            );
        }
    }

    public function testNginxAliasesFontsIntoDist(): void
    {
        $configuration = self::read(self::repoRoot() . '/docker/nginx.conf');

        self::assertSame(
            1,
            preg_match(
                '#location\s+\^~\s+/fonts/\s*\{([^{}]*)\}#s',
                $configuration,
                $location,
            ),
            'nginx musí mít prefixový location ^~ /fonts/ — jinak požadavek spadne do location / a odtud na @spa.',
        );
        self::assertMatchesRegularExpression(
            '#\balias\s+/var/www/html/web/dist/fonts/\s*;#',
            $location[1],
            'nginx location /fonts/ musí aliasovat do web/dist/fonts/.',
        );
        self::assertMatchesRegularExpression(
            '#\btry_files\s+\$uri\s+=404\s*;#',
            $location[1],
            'Chybějící font musí skončit 404, ne tichým průchodem na SPA.',
        );

        self::assertStringContainsString(
            'COPY docker/nginx.conf /etc/nginx/nginx.conf',
            self::read(self::repoRoot() . '/Dockerfile.alpine'),
            'Alpine image musí testovanou nginx konfiguraci skutečně instalovat.',
        );
    }

    public function testIisRewritesFontsIntoDist(): void
    {
        $document = new DOMDocument();
        self::assertTrue(
            $document->load(self::repoRoot() . '/web.config', LIBXML_NONET),
            'IIS web.config není platné XML.',
        );
        $xpath = new DOMXPath($document);
        $rules = $xpath->query('//system.webServer/rewrite/rules/rule');
        self::assertNotFalse($rules);

        $path = ltrim(self::SAMPLE_URI, '/');
        $rewriteIndex = null;
        $fallbackIndex = null;
        foreach ($rules as $index => $rule) {
            self::assertInstanceOf(DOMElement::class, $rule);
            if ($rule->getAttribute('name') === 'SPA fallback') {
                $fallbackIndex ??= $index;
            }

            $match = $rule->getElementsByTagName('match')->item(0);
            $action = $rule->getElementsByTagName('action')->item(0);
            if (!$match instanceof DOMElement || !$action instanceof DOMElement) {
                continue;
            }
            if ($rewriteIndex === null
                && $action->getAttribute('type') === 'Rewrite'
                && str_starts_with($action->getAttribute('url'), 'web/dist/fonts/')
                && preg_match('#' . $match->getAttribute('url') . '#D', $path) === 1
            ) {
                $rewriteIndex = $index;
            }
        }

        self::assertNotNull($rewriteIndex, 'IIS musí /fonts/* přepsat na web/dist/fonts/*.');
        self::assertNotNull($fallbackIndex, 'IIS konfigurace nemá očekávaný SPA fallback.');
        self::assertLessThan($fallbackIndex, $rewriteIndex, 'Přepis fontů musí předcházet SPA fallbacku.');
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, "Nelze načíst {$path}.");
        // .htaccess, nginx.conf ani index.html nemají v .gitattributes vynucené
        // LF, takže Windows checkout (core.autocrlf) je dostane s CRLF.
        return str_replace("\r\n", "\n", $contents);
    }
}
