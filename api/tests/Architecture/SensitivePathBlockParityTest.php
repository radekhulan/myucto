<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Citlivé cesty musí být zavřené na VŠECH třech webserverech.
 *
 * Document root je u self-hosted i hostované instalace kořen bundlu, ne
 * `web/dist`, takže ve veřejném prostoru reálně leží `cfg.php`, `VERSION`,
 * `production.cmd`, `docker-entrypoint.sh` i `portainer-template.json`. Ochrana
 * je proto v konfiguraci webserveru — a existuje TŘIKRÁT: `.htaccess` (Apache),
 * `web.config` (IIS) a `docker/nginx.conf` (Docker image).
 *
 * ⚠️ Tenhle test existuje, protože drift už jednou nastala: commit H-19
 * (`05ab85815`) doplnil `cfg.sample.php`, `cfg.docker.php`, `VERSION`,
 * `web.config`, `portainer-template.json` a přípony `.cmd`/`.ps1`/`.sh`
 * do `.htaccess` a `web.config` — „v synchronizaci", jak říká commit message —
 * ale na `docker/nginx.conf` se zapomnělo. Docker instance pak přes rok vydávala
 * `/VERSION` i `/production.cmd` s 200, zatímco hosting na Apache/IIS je vracel
 * jako 403. Selhání je tiché: nic nespadne, jen to jde stáhnout.
 *
 * Test se ptá na účinek, ne na znění direktivy — pro každou vzorovou cestu
 * ověřuje, že ji každá ze tří konfigurací zavře, ať už jakýmkoli mechanismem
 * (rewrite na 403, `<FilesMatch>`, `return 403`, IIS requestFiltering).
 *
 * @see \MyInvoice\Tests\Architecture\SelfHostedFontRoutingParityTest — táž třída
 *      problému v opačném gardu (pravidlo mělo jen IIS, chybělo Apachi i nginxu).
 */
final class SensitivePathBlockParityTest extends TestCase
{
    /**
     * Vzorové cesty, které musí být zavřené. Sada je držená konzistentně
     * s `cmd/verify-instance-hardening.{sh,ps1}` (H-19), který totéž ověřuje
     * přes HTTP proti nasazené instanci.
     *
     * @return iterable<string, array{string}>
     */
    public static function sensitiveUris(): iterable
    {
        // Doplněné v H-19 — právě tyhle nginxu chyběly.
        yield 'VERSION' => ['/VERSION'];
        yield 'web.config' => ['/web.config'];
        yield 'portainer-template.json' => ['/portainer-template.json'];
        yield 'cfg.sample.php' => ['/cfg.sample.php'];
        yield 'cfg.docker.php' => ['/cfg.docker.php'];
        // Pomocné skripty v kořeni bundlu — kryté příponou, ne jménem.
        yield 'production.cmd' => ['/production.cmd'];
        yield 'demo.cmd' => ['/demo.cmd'];
        yield 'docker-entrypoint.sh' => ['/docker-entrypoint.sh'];
        yield 'tools/export-pdf.ps1' => ['/tools/export-pdf.ps1'];
        // Kontrolní vzorky staršího jádra seznamu — kdyby se rozpadl parser,
        // musí spadnout i tyhle, ne jen nové položky.
        yield 'cfg.php' => ['/cfg.php'];
        yield 'api/composer.lock' => ['/api/composer.lock'];
        yield 'README.md' => ['/README.md'];
    }

    #[DataProvider('sensitiveUris')]
    public function testApacheBlocks(string $uri): void
    {
        $configuration = self::read(self::repoRoot() . '/.htaccess');
        $path = ltrim($uri, '/');

        // a) rewrite pravidla končící [F] (403).
        preg_match_all(
            '/^\s*RewriteRule\s+(\S+)\s+\S+\s+\[([^\]]*F[^\]]*)]\s*$/m',
            $configuration,
            $rules,
            PREG_SET_ORDER,
        );
        foreach ($rules as $rule) {
            if (preg_match('#' . $rule[1] . '#D', $path) === 1) {
                self::assertTrue(true);

                return;
            }
        }

        // b) <FilesMatch> + Require all denied — kryje přípony kdekoli v cestě.
        preg_match_all(
            '/<FilesMatch\s+"([^"]+)"\s*>(.*?)<\/FilesMatch>/s',
            $configuration,
            $blocks,
            PREG_SET_ORDER,
        );
        foreach ($blocks as $block) {
            if (!str_contains($block[2], 'Require all denied')) {
                continue;
            }
            if (preg_match('#' . $block[1] . '#D', basename($path)) === 1) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail(".htaccess nezavírá {$uri} — parita s web.config a nginx.conf se rozešla.");
    }

    #[DataProvider('sensitiveUris')]
    public function testNginxBlocks(string $uri): void
    {
        $configuration = self::read(self::repoRoot() . '/docker/nginx.conf');

        // Prefixový `^~` location vyhrává nad regexem, takže by blokující
        // pravidlo obešel. Žádná citlivá cesta pod ně spadnout nesmí.
        preg_match_all('/^\s*location\s+\^~\s+(\S+)\s*\{/m', $configuration, $prefixes);
        foreach ($prefixes[1] as $prefix) {
            self::assertFalse(
                str_starts_with($uri, $prefix),
                "nginx: {$uri} spadá pod prefixový location {$prefix}, který přebije blokující regex.",
            );
        }

        preg_match_all(
            '/^\s*location\s+(~\*?)\s+(\S+)\s*\{\s*\r?\n\s*return\s+403\s*;/m',
            $configuration,
            $locations,
            PREG_SET_ORDER,
        );
        self::assertNotEmpty($locations, 'nginx.conf nemá žádný blokující location — parser se rozešel s konfigurací.');

        foreach ($locations as $location) {
            $flags = $location[1] === '~*' ? 'iD' : 'D';
            if (preg_match('#' . $location[2] . '#' . $flags, $uri) === 1) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail("docker/nginx.conf nezavírá {$uri} — parita s .htaccess a web.config se rozešla.");
    }

    #[DataProvider('sensitiveUris')]
    public function testIisBlocks(string $uri): void
    {
        $document = new DOMDocument();
        self::assertTrue(
            $document->load(self::repoRoot() . '/web.config', LIBXML_NONET),
            'IIS web.config není platné XML.',
        );
        $xpath = new DOMXPath($document);
        $path = ltrim($uri, '/');

        // a) rewrite pravidla vracející 403.
        $rules = $xpath->query('//system.webServer/rewrite/rules/rule');
        self::assertNotFalse($rules);
        foreach ($rules as $rule) {
            self::assertInstanceOf(DOMElement::class, $rule);
            $match = $rule->getElementsByTagName('match')->item(0);
            $action = $rule->getElementsByTagName('action')->item(0);
            if (!$match instanceof DOMElement || !$action instanceof DOMElement) {
                continue;
            }
            if ($action->getAttribute('statusCode') !== '403') {
                continue;
            }
            if (preg_match('#' . $match->getAttribute('url') . '#D', $path) === 1) {
                self::assertTrue(true);

                return;
            }
        }

        // b) requestFiltering — zakázané přípony IIS odmítne dřív než rewrite.
        $extensions = $xpath->query(
            '//system.webServer/security/requestFiltering/fileExtensions/add[@allowed="false"]',
        );
        self::assertNotFalse($extensions);
        foreach ($extensions as $extension) {
            self::assertInstanceOf(DOMElement::class, $extension);
            $suffix = $extension->getAttribute('fileExtension');
            if ($suffix !== '' && str_ends_with(strtolower($path), strtolower($suffix))) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail("web.config nezavírá {$uri} — parita s .htaccess a nginx.conf se rozešla.");
    }

    /**
     * Seznam blokovaných složek je ve všech třech konfiguracích zapsaný týmž
     * regexem. Porovnáváme ho doslova — kdyby se lišil, je to buď drift, nebo
     * vědomá odchylka, kterou má autor obhájit úpravou tohohle testu.
     */
    public function testBlockedDirectoryListIsIdentical(): void
    {
        $pattern = '(private|db|log|source|storage|tools|cmd|docker|web/(src|shared|node_modules)|node_modules|api/(src|vendor|templates|tests|bin))';

        self::assertStringContainsString(
            $pattern,
            self::read(self::repoRoot() . '/.htaccess'),
            '.htaccess má jiný seznam blokovaných složek.',
        );
        self::assertStringContainsString(
            $pattern,
            self::read(self::repoRoot() . '/docker/nginx.conf'),
            'docker/nginx.conf má jiný seznam blokovaných složek.',
        );
        self::assertStringContainsString(
            $pattern,
            self::read(self::repoRoot() . '/web.config'),
            'web.config má jiný seznam blokovaných složek.',
        );
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, "Nelze načíst {$path}.");

        // Konfigurace nemají v .gitattributes vynucené LF, takže je Windows
        // checkout (core.autocrlf) dostane s CRLF.
        return str_replace("\r\n", "\n", $contents);
    }
}
