<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service;

use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;

final class IpMatcherTest extends TestCase
{
    private IpMatcher $m;

    protected function setUp(): void
    {
        $this->m = (new \ReflectionClass(IpMatcher::class))->newInstanceWithoutConstructor();
    }

    public function testIPv4ExactMatch(): void
    {
        self::assertTrue($this->m->matches('192.168.1.10', ['192.168.1.10']));
        self::assertFalse($this->m->matches('192.168.1.11', ['192.168.1.10']));
    }

    public function testIPv4Cidr(): void
    {
        self::assertTrue($this->m->matches('192.168.1.55', ['192.168.1.0/24']));
        self::assertFalse($this->m->matches('192.168.2.55', ['192.168.1.0/24']));
        self::assertTrue($this->m->matches('10.0.0.1', ['10.0.0.0/8']));
    }

    public function testIPv6ExactAndCidr(): void
    {
        self::assertTrue($this->m->matches('::1', ['::1']));
        self::assertTrue($this->m->matches('2001:db8::1', ['2001:db8::/32']));
        self::assertFalse($this->m->matches('2001:dba::1', ['2001:db8::/32']));
    }

    public function testEmptyRulesAlwaysFalse(): void
    {
        self::assertFalse($this->m->matches('1.2.3.4', []));
    }

    public function testInvalidIpReturnsFalse(): void
    {
        self::assertFalse($this->m->matches('not-an-ip', ['1.2.3.4']));
    }

    /** @param array<string,mixed> $extra */
    private function params(string $remote, ?string $xff = null, array $extra = []): array
    {
        $p = ['REMOTE_ADDR' => $remote] + $extra;
        if ($xff !== null) {
            $p['HTTP_X_FORWARDED_FOR'] = $xff;
        }
        return $p;
    }

    public function testUntrustedRemoteIgnoresHeader(): void
    {
        // REMOTE_ADDR není trusted proxy → hlavička se vůbec nesmí brát v potaz
        self::assertSame(
            '203.0.113.9',
            $this->m->clientIp($this->params('203.0.113.9', '1.2.3.4'), ['10.0.0.0/8']),
        );
    }

    public function testNoTrustedProxiesConfiguredIgnoresHeader(): void
    {
        self::assertSame(
            '10.0.0.5',
            $this->m->clientIp($this->params('10.0.0.5', '1.2.3.4'), []),
        );
    }

    public function testSingleEntryChainReturnsClient(): void
    {
        // Jednopoložkový chain = edge proxy hlavičku PŘEPSALA (proxy_set_header
        // X-Forwarded-For $remote_addr). Jediná položka je pak reálný klient.
        //
        // ⚠️ Platí to VÝHRADNĚ za předpokladu, že edge hlavičku přepisuje. Když ji
        // jen appenduje (nebo nesahá), zapsal si tuhle položku klient sám a jde
        // podvrhnout. Tenhle scénář nezachrání žádná logika nad chainem — proto
        // clientIpFromRequest() preferuje nepodvrhnutelný MYUCTO_CLIENT_IP
        // (viz testTrustedServerParamWinsOverForgedHeader) a docker/nginx.conf ho
        // nastavuje. Předpoklad je zdokumentovaný v manual/97_Bezpecnost.md.
        self::assertSame(
            '203.0.113.7',
            $this->m->clientIp($this->params('10.0.0.1', '203.0.113.7'), ['10.0.0.0/8']),
        );
    }

    public function testSpoofedFirstEntryIsIgnored(): void
    {
        // Útočník poslal vlastní XFF, edge proxy jen appendovala jeho reálnou IP.
        // Zleva by vyšlo podvržené 1.2.3.4; zprava správně 203.0.113.7.
        self::assertSame(
            '203.0.113.7',
            $this->m->clientIp(
                $this->params('10.0.0.1', '1.2.3.4, 203.0.113.7, 10.0.0.2'),
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testMultipleTrustedHopsAreStripped(): void
    {
        self::assertSame(
            '203.0.113.7',
            $this->m->clientIp(
                $this->params('10.0.0.1', '203.0.113.7, 10.0.0.9, 10.0.0.8, 10.0.0.2'),
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testAllTrustedChainReturnsLeftmost(): void
    {
        // Požadavek vznikl na některé z trusted proxy (interní monitoring apod.)
        self::assertSame(
            '10.0.0.9',
            $this->m->clientIp(
                $this->params('10.0.0.1', '10.0.0.9, 10.0.0.2'),
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testEmptyHeaderFallsBackToRemote(): void
    {
        self::assertSame('10.0.0.1', $this->m->clientIp($this->params('10.0.0.1', ''), ['10.0.0.0/8']));
        self::assertSame('10.0.0.1', $this->m->clientIp($this->params('10.0.0.1', '   '), ['10.0.0.0/8']));
        self::assertSame('10.0.0.1', $this->m->clientIp($this->params('10.0.0.1'), ['10.0.0.0/8']));
    }

    public function testInvalidEntryInChainFailsClosedToProxyIp(): void
    {
        // Rozbitý chain → klienta nelze určit, fail-closed na IP proxy
        self::assertSame(
            '10.0.0.1',
            $this->m->clientIp(
                $this->params('10.0.0.1', 'not-an-ip, 10.0.0.2'),
                ['10.0.0.0/8'],
            ),
        );
        // Prázdná položka uprostřed chainu se chová stejně
        self::assertSame(
            '10.0.0.1',
            $this->m->clientIp(
                $this->params('10.0.0.1', '203.0.113.7, , 10.0.0.2'),
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testTrailingGarbageDoesNotLeakIntoResult(): void
    {
        // Poslední položku zapsal trusted proxy; když je nesmyslná, nesmí se vrátit
        self::assertSame(
            '10.0.0.1',
            $this->m->clientIp(
                $this->params('10.0.0.1', '203.0.113.7, junk'),
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testIPv6ChainAndMappedNormalization(): void
    {
        self::assertSame(
            '2001:db8::beef',
            $this->m->clientIp(
                $this->params('::1', '2001:db8::beef, ::1'),
                ['::1/128'],
            ),
        );
        // IPv4-mapped IPv6 klient se normalizuje na IPv4
        self::assertSame(
            '203.0.113.7',
            $this->m->clientIp(
                $this->params('10.0.0.1', '::ffff:203.0.113.7, 10.0.0.2'),
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testPortSuffixedChainEntriesAreAccepted(): void
    {
        // Některé proxy zapisují i port. Dřív to normalize() odmítl a chain
        // fail-closed spadl na IP proxy → reálný klient se ztratil.
        self::assertSame(
            '203.0.113.7',
            $this->m->clientIp(
                $this->params('10.0.0.1', '203.0.113.7:41234, 10.0.0.2'),
                ['10.0.0.0/8'],
            ),
        );
        // Port u trusted hopu nesmí zabránit jeho odloupnutí
        self::assertSame(
            '203.0.113.7',
            $this->m->clientIp(
                $this->params('10.0.0.1', '203.0.113.7, 10.0.0.2:8080'),
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testBracketedIPv6ChainEntriesAreAccepted(): void
    {
        self::assertSame(
            '2001:db8::beef',
            $this->m->clientIp(
                $this->params('10.0.0.1', '[2001:db8::beef], 10.0.0.2'),
                ['10.0.0.0/8'],
            ),
        );
        self::assertSame(
            '2001:db8::beef',
            $this->m->clientIp(
                $this->params('10.0.0.1', '[2001:db8::beef]:443, 10.0.0.2'),
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testMalformedBracketAndPortStillFailClosed(): void
    {
        // Tolerance k portu/závorkám nesmí propustit vyloženě rozbité položky
        self::assertSame(
            '10.0.0.1',
            $this->m->clientIp($this->params('10.0.0.1', '[2001:db8::beef, 10.0.0.2'), ['10.0.0.0/8']),
        );
        self::assertSame(
            '10.0.0.1',
            $this->m->clientIp($this->params('10.0.0.1', '[2001:db8::beef]junk, 10.0.0.2'), ['10.0.0.0/8']),
        );
        self::assertSame(
            '10.0.0.1',
            $this->m->clientIp($this->params('10.0.0.1', '203.0.113.7:notaport, 10.0.0.2'), ['10.0.0.0/8']),
        );
    }

    public function testTrustedServerParamWinsOverForgedHeader(): void
    {
        // SEC-12: MYUCTO_CLIENT_IP nastavuje web server jako fastcgi_param BEZ
        // prefixu HTTP_, takže ho klient nemůže podvrhnout (klientské hlavičky
        // se vždy mapují na HTTP_*). Musí přebít i kompletně podvržený chain.
        $matcher = new IpMatcher();

        self::assertSame(
            '203.0.113.7',
            $matcher->clientIpFromRequest([
                'REMOTE_ADDR'           => '172.17.0.1',
                'HTTP_X_FORWARDED_FOR'  => '1.2.3.4, 82.142.99.142',
                IpMatcher::TRUSTED_CLIENT_IP_PARAM => '203.0.113.7',
            ]),
        );
    }

    public function testTrustedServerParamAlsoAppliesToDirectClientIpCall(): void
    {
        // Middleware (AuthMiddleware, IpAllowlistMiddleware, RateLimitMiddleware)
        // volají clientIp() přímo — ochrana nesmí jít obejít o úroveň níž.
        self::assertSame(
            '203.0.113.7',
            $this->m->clientIp(
                $this->params('10.0.0.1', '1.2.3.4, 10.0.0.2', [
                    IpMatcher::TRUSTED_CLIENT_IP_PARAM => '203.0.113.7',
                ]),
                ['10.0.0.0/8'],
            ),
        );
    }

    public function testMissingOrInvalidServerParamFallsBackToHeaderLogic(): void
    {
        $matcher = new IpMatcher();

        // Bez server parametru (IIS/Apache) → chování jako dřív. Config chybí,
        // takže trusted_proxies je prázdné a vrací se REMOTE_ADDR.
        self::assertSame(
            '172.17.0.1',
            $matcher->clientIpFromRequest([
                'REMOTE_ADDR'          => '172.17.0.1',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            ]),
        );

        // Nesmyslná hodnota parametru se ignoruje, nefailuje
        self::assertSame(
            '172.17.0.1',
            $matcher->clientIpFromRequest([
                'REMOTE_ADDR'                      => '172.17.0.1',
                IpMatcher::TRUSTED_CLIENT_IP_PARAM => 'not-an-ip',
            ]),
        );
    }

    public function testCustomHeaderName(): void
    {
        self::assertSame(
            '203.0.113.7',
            $this->m->clientIp(
                $this->params('10.0.0.1', null, ['HTTP_X_REAL_IP' => '203.0.113.7, 10.0.0.2']),
                ['10.0.0.0/8'],
                'X-Real-IP',
            ),
        );
    }
}
