<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * H-26 — konfigurace za proxy spravovaného hostingu.
 *
 * Hosting má Apache s `mod_remoteip`, takže do `REMOTE_ADDR` jde UŽ SKUTEČNÁ
 * IP klienta. Z toho plynou tři invarianty, které se dají snadno „opravit"
 * špatným směrem, a proto mají bránu:
 *
 *  1. `ip_allowlist.trusted_proxies` zůstává PRÁZDNÉ a `X-Forwarded-For` si
 *     nečteme sami. Kdyby se doplnilo, adresa se započítá dvakrát: mod_remoteip
 *     ji už přepsal do REMOTE_ADDR a chain by nás poslal o hop dál — rate-limit
 *     i auditní log by pak lhaly. Auditní log je důkazní materiál.
 *  2. `X-Forwarded-Proto` čteme dál — bez něj se aplikace za terminovaným TLS
 *     zacyklí v redirectu na HTTPS.
 *  3. Tenantový host gate čte `Host`, ne `X-Forwarded-Host`. `X-Forwarded-Host`
 *     je klientem podvrhnutelná hlavička; kdyby na ní visel výběr tenanta,
 *     dala by se jí přepnout instance.
 *
 * Testy nechytají chybu, která v repu je — drží stav, na kterém stojí správnost
 * auditního logu a tenantové izolace, aby ho nešlo tiše přenastavit.
 */
final class ManagedProxyHeaderInvariantTest extends TestCase
{
    /** Invariant 1 — s prázdným `trusted_proxies` vyhrává REMOTE_ADDR. */
    public function testEmptyTrustedProxiesMakesRemoteAddrAuthoritative(): void
    {
        $matcher = new IpMatcher(new Config([
            'ip_allowlist' => ['trusted_proxies' => [], 'header' => 'X-Forwarded-For'],
        ]));

        // Přesně to, co za mod_remoteip dorazí: REMOTE_ADDR = klient, a útočníkem
        // dodaná hlavička, kterou Apache nesmazal.
        self::assertSame(
            '203.0.113.7',
            $matcher->clientIpFromRequest([
                'REMOTE_ADDR'          => '203.0.113.7',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
            ]),
            'S prázdným trusted_proxies se X-Forwarded-For nesmí vzít v potaz.',
        );

        // Ani chain, ani pokus podvrhnout si vlastní „proxy hop".
        self::assertSame(
            '203.0.113.7',
            $matcher->clientIpFromRequest([
                'REMOTE_ADDR'          => '203.0.113.7',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 198.51.100.9, 192.0.2.4',
            ]),
        );
    }

    /** Chybějící sekce v cfg se musí chovat stejně jako prázdná — ne jako „věř všemu". */
    public function testMissingIpAllowlistSectionBehavesLikeEmptyTrustedProxies(): void
    {
        $matcher = new IpMatcher(new Config([]));

        self::assertSame(
            '203.0.113.7',
            $matcher->clientIpFromRequest([
                'REMOTE_ADDR'          => '203.0.113.7',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
            ]),
        );
    }

    /**
     * Invariant 1, druhá půlka: `X-Forwarded-For` se nesmí číst mimo IpMatcher.
     * Jedno `$_SERVER['HTTP_X_FORWARDED_FOR']` v akci obchází celou trusted-proxy
     * logiku naráz.
     */
    public function testNobodyReadsForwardedForOutsideIpMatcher(): void
    {
        $offenders = [];
        foreach ($this->phpSources() as $file => $code) {
            if (str_ends_with(str_replace('\\', '/', $file), 'Service/IpMatcher.php')) {
                continue;
            }
            // Konstanta s názvem hlavičky předaná do IpMatcher::clientIp() je v pořádku
            // — hlavička se pořád vyhodnocuje trusted-proxy logikou.
            if (preg_match('/HTTP_X_FORWARDED_FOR|\$_SERVER\s*\[\s*[\'"]HTTP_X_FORWARDED_FOR/i', $code) === 1) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'X-Forwarded-For smí vyhodnocovat jen IpMatcher.');
    }

    /** Invariant 3 — `X-Forwarded-Host` nesmí nikde rozhodovat o hostiteli. */
    public function testForwardedHostIsNeverTrusted(): void
    {
        $offenders = [];
        foreach ($this->phpSources() as $file => $code) {
            if (preg_match('/X-Forwarded-Host|HTTP_X_FORWARDED_HOST/i', $code) !== 1) {
                continue;
            }
            // Zmínka v komentáři je neškodná; čtení hlavičky ne.
            if (preg_match('/getHeaderLine\s*\(\s*[\'"]X-Forwarded-Host|HTTP_X_FORWARDED_HOST/i', $code) === 1) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'X-Forwarded-Host se nesmí brát jako důvěryhodný zdroj hostname.');
    }

    /** Invariant 3 — tenantový resolver bere hostname z `Host`/URI, ne z forwardované hlavičky. */
    public function testTenantResolverReadsHostHeader(): void
    {
        $code = $this->source('api/src/Service/Tenant/TenantDomainResolver.php');

        self::assertStringContainsString("getUri()->getHost()", $code);
        self::assertStringContainsString("getHeaderLine('Host')", $code);
        self::assertStringNotContainsStringIgnoringCase('X-Forwarded-Host', $code);
    }

    /**
     * Invariant 2 — obě webserverové konfigurace musí `X-Forwarded-Proto` číst dál.
     * Bez něj skončí instance za terminovaným TLS v nekonečném redirectu.
     */
    public function testHttpsRedirectStillHonoursForwardedProto(): void
    {
        self::assertStringContainsString(
            'X-Forwarded-Proto',
            $this->source('.htaccess'),
            'Apache redirect na HTTPS musí respektovat X-Forwarded-Proto.',
        );
        self::assertStringContainsString(
            'HTTP_X_FORWARDED_PROTO',
            $this->source('web.config'),
            'IIS redirect na HTTPS musí respektovat X-Forwarded-Proto.',
        );
    }

    /**
     * Distribuovaná cfg nesmí přednastavit důvěryhodnou proxy.
     *
     * ⚠️ `cfg.docker.php` v repozitáři NENÍ a nikdy nebude: je v `.gitignore`,
     * generuje ho `cmd/docker-ghcr.ps1` z `cfg.sample.php` a publikovaný image
     * ho výslovně vylučuje. Jediná skutečně distribuovaná konfigurace je tedy
     * vzorek — ten se kontroluje vždy a lokálně vygenerovaný soubor jen navíc,
     * když existuje. Protože z vzorku vzniká, kontrola tím neslábne.
     *
     * Bez toho brána padala na CI, kde ten soubor nemá jak vzniknout.
     */
    public function testShippedConfigsKeepTrustedProxiesEmpty(): void
    {
        $shipped = ['cfg.sample.php'];
        if (is_file($this->path('cfg.docker.php'))) {
            $shipped[] = 'cfg.docker.php';
        }

        foreach ($shipped as $file) {
            $code = $this->source($file);
            self::assertSame(
                1,
                preg_match("/'trusted_proxies'\s*=>\s*\[\s*(?:\/\/[^\n]*\n\s*)*\]/", $code),
                $file . ' nesmí mít předvyplněnou důvěryhodnou proxy.',
            );
        }
    }

    /** @return array<string,string> cesta => obsah */
    private function phpSources(): array
    {
        $root = rtrim(str_replace('\\', '/', Bootstrap::rootDir()), '/') . '/api/src';
        $out = [];
        foreach (SourceCorpus::files($root) as $path) {
            $out[$path] = SourceCorpus::read($path);
        }
        self::assertNotEmpty($out, 'Nenačetl se žádný zdroják — brána by tiše procházela.');

        return $out;
    }

    private function source(string $relative): string
    {
        $path = $this->path($relative);
        self::assertFileExists($path);

        return SourceCorpus::read($path);
    }

    private function path(string $relative): string
    {
        return rtrim(str_replace('\\', '/', Bootstrap::rootDir()), '/') . '/' . $relative;
    }
}
