<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Http;

use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SEC-04 / SEC-13 — anti-SSRF guard pro odchozí HTTP.
 *
 * Testy záměrně používají IP literály v URL, takže neprobíhá žádný DNS dotaz
 * ani síťové spojení — chování je deterministické i bez konektivity.
 */
final class OutboundUrlGuardTest extends TestCase
{
    private function guard(): OutboundUrlGuard
    {
        return new OutboundUrlGuard();
    }

    private function assertRejected(string $url, array $allowedHosts = []): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->guard()->validate($url, $allowedHosts);
    }

    // --- schéma a tvar URL -------------------------------------------------

    public function testRejectsPlainHttp(): void
    {
        $this->assertRejected('http://93.184.216.34/tsa');
    }

    public function testRejectsNonHttpScheme(): void
    {
        $this->assertRejected('file:///etc/passwd');
    }

    public function testRejectsGopherScheme(): void
    {
        $this->assertRejected('gopher://93.184.216.34:70/_x');
    }

    public function testRejectsUserinfo(): void
    {
        // Klasické obcházení naivní host kontroly: "povolený" host je jen userinfo.
        $this->assertRejected('https://app.fakturoid.cz@93.184.216.34/attachment.pdf');
    }

    public function testRejectsFragment(): void
    {
        $this->assertRejected('https://93.184.216.34/tsa#app.fakturoid.cz');
    }

    public function testRejectsControlCharacters(): void
    {
        $this->assertRejected("https://93.184.216.34/tsa\r\nX-Injected: 1");
    }

    public function testRejectsEmptyUrl(): void
    {
        $this->assertRejected('   ');
    }

    public function testRejectsRelativeUrl(): void
    {
        $this->assertRejected('/api/v3/attachment.pdf');
    }

    // --- zakázané rozsahy --------------------------------------------------

    public function testRejectsLoopbackV4(): void
    {
        $this->assertRejected('https://127.0.0.1/tsa');
    }

    public function testRejectsLoopbackV6(): void
    {
        $this->assertRejected('https://[::1]/tsa');
    }

    public function testRejectsPrivateRange10(): void
    {
        $this->assertRejected('https://10.1.2.3/tsa');
    }

    public function testRejectsPrivateRange192(): void
    {
        $this->assertRejected('https://192.168.0.1/tsa');
    }

    public function testRejectsPrivateRange172(): void
    {
        $this->assertRejected('https://172.16.5.5/tsa');
    }

    public function testRejectsCloudMetadata(): void
    {
        $this->assertRejected('https://169.254.169.254/latest/meta-data/iam/security-credentials/');
    }

    public function testRejectsAwsIpv6Metadata(): void
    {
        $this->assertRejected('https://[fd00:ec2::254]/latest/meta-data/');
    }

    public function testRejectsCgnatRange(): void
    {
        // 100.64.0.0/10 — Alibaba metadata 100.100.100.200 spadá sem.
        $this->assertRejected('https://100.100.100.200/latest/meta-data/');
    }

    public function testRejectsIpv4MappedLoopback(): void
    {
        // ::ffff:127.0.0.1 se musí posuzovat jako IPv4 loopback, ne jako "nějaká IPv6".
        $this->assertRejected('https://[::ffff:127.0.0.1]/tsa');
    }

    public function testRejectsUnspecifiedAddress(): void
    {
        $this->assertRejected('https://0.0.0.0/tsa');
    }

    public function testRejectsMulticast(): void
    {
        $this->assertRejected('https://224.0.0.1/tsa');
    }

    public function testRejectsBroadcast(): void
    {
        $this->assertRejected('https://255.255.255.255/tsa');
    }

    public function testRejectsLinkLocalV6(): void
    {
        $this->assertRejected('https://[fe80::1]/tsa');
    }

    // --- isPublicIp přímo --------------------------------------------------

    /**
     * @return list<array{0:string,1:bool}>
     */
    public static function ipProvider(): array
    {
        return [
            ['8.8.8.8', true],
            ['93.184.216.34', true],
            ['2606:4700:4700::1111', true],
            ['127.0.0.1', false],
            ['127.255.255.254', false],
            ['10.0.0.1', false],
            ['172.15.255.255', true],   // těsně pod RFC1918 blokem
            ['172.16.0.0', false],
            ['172.31.255.255', false],
            ['172.32.0.0', true],       // těsně nad RFC1918 blokem
            ['192.168.255.255', false],
            ['169.254.169.254', false],
            ['100.63.255.255', true],   // těsně pod CGNAT
            ['100.64.0.0', false],
            ['198.18.0.1', false],
            ['203.0.113.1', false],
            ['::1', false],
            ['fc00::1', false],
            ['fe80::1', false],
            ['ff02::1', false],
            ['2001:db8::1', false],
            ['nesmysl', false],
            ['', false],
        ];
    }

    #[DataProvider('ipProvider')]
    public function testIsPublicIp(string $ip, bool $expected): void
    {
        self::assertSame($expected, $this->guard()->isPublicIp($ip));
    }

    // --- allowlist ---------------------------------------------------------

    public function testRejectsHostOutsideAllowlist(): void
    {
        $this->assertRejected('https://93.184.216.34/attachment.pdf', ['app.fakturoid.cz']);
    }

    public function testAllowlistIsCaseInsensitive(): void
    {
        $this->expectNotToPerformAssertions();
        $this->guard()->assertSyntax('https://APP.Fakturoid.CZ/x.pdf', ['app.fakturoid.cz']);
    }

    public static function trailingDotUrls(): array
    {
        return [
            'hostname' => ['https://audit-rebind.invalid./document', []],
            'allowed hostname' => ['https://APP.Fakturoid.CZ./x.pdf', ['app.fakturoid.cz']],
            'multiple dots' => ['https://audit-rebind.invalid../document', []],
            'public IPv4' => ['https://93.184.216.34./document', []],
        ];
    }

    #[DataProvider('trailingDotUrls')]
    public function testRejectsTrailingDotInsteadOfChangingPinnedHost(string $url, array $allowedHosts): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->guard()->assertSyntax($url, $allowedHosts);
    }

    public function testRequestRejectsTrailingDotBeforeDnsOrCurl(): void
    {
        try {
            $this->guard()->request('GET', 'https://audit-rebind.invalid.:1/');
            self::fail('Host s koncovou tečkou musí být odmítnut před DNS.');
        } catch (OutboundRequestException $e) {
            self::assertSame(OutboundRequestException::TARGET_BLOCKED, $e->reason);
        }
    }

    public function testPublicIpv6LiteralKeepsItsUrlAndDoesNotRequireDnsPin(): void
    {
        $target = $this->guard()->validate('https://[2606:4700:4700::1111]/document');
        self::assertSame('https://[2606:4700:4700::1111]/document', $target->url);
        self::assertSame('2606:4700:4700::1111', $target->host);
        self::assertSame(['2606:4700:4700::1111'], $target->ips);
        self::assertTrue($target->hostIsIpLiteral);
    }

    public function testAssertSyntaxRejectsForeignHost(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->guard()->assertSyntax('https://evil.example.com/x.pdf', ['app.fakturoid.cz']);
    }

    // --- assertSyntax (save-time validace TSA URL) -------------------------

    public function testAssertSyntaxAcceptsPublicHttpsHost(): void
    {
        $this->expectNotToPerformAssertions();
        $this->guard()->assertSyntax('https://freetsa.org/tsr');
    }

    public function testAssertSyntaxRejectsHttp(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->guard()->assertSyntax('http://freetsa.org/tsr');
    }

    public function testAssertSyntaxRejectsLoopbackLiteral(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->guard()->assertSyntax('https://127.0.0.1:8080/tsr');
    }

    public function testAssertSyntaxRejectsUserinfo(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->guard()->assertSyntax('https://user:pass@freetsa.org/tsr');
    }

    // --- request() nesmí navázat spojení na zakázaný cíl -------------------

    public function testRequestRefusesBeforeConnectingToMetadata(): void
    {
        // Kdyby se spojení navázalo, test by na CI trval do timeoutu; výjimka musí
        // padnout z validace, tedy okamžitě.
        $this->expectException(OutboundRequestException::class);
        $this->guard()->request('GET', 'https://169.254.169.254/latest/meta-data/');
    }

    public function testRequestRefusesRedirectTargetSemantics(): void
    {
        // Redirecty jsou vypnuté; kdyby někdo v budoucnu FOLLOWLOCATION zapnul,
        // musí každý hop projít stejnou validací — což by u tohoto cíle selhalo.
        $this->expectException(OutboundRequestException::class);
        $this->guard()->validate('https://10.0.0.5/redirected');
    }
}
