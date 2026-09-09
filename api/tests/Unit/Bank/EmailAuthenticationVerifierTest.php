<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\EmailAuthenticationVerifier;
use PHPUnit\Framework\TestCase;

final class EmailAuthenticationVerifierTest extends TestCase
{
    private EmailAuthenticationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new EmailAuthenticationVerifier();
    }

    /**
     * Chybějící hlavička `Authentication-Results` je ODMÍTNUTÍ, ne přeskočení
     * kontroly — scanner na `pass = false` avízo zahodí jako `security_rejected`.
     * `checked = false` je jen diagnostika pro chybovou hlášku.
     */
    public function testMissingHeaderIsRejectedNotSkipped(): void
    {
        $r = $this->verifier->verify([], 'rb.cz');
        self::assertFalse($r['checked']);
        self::assertFalse($r['pass']);
        self::assertSame('no_authentication_results', $r['detail']);
    }

    public function testDmarcPassWithAlignedHeaderFromIsAccepted(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; spf=pass; dmarc=pass header.from=rb.cz'],
            'rb.cz',
            'mx.tvujmail.cz',
        );
        self::assertTrue($r['pass']);
    }

    public function testDmarcPassForDifferentHeaderFromIsRejected(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dmarc=pass header.from=evil.example'],
            'rb.cz',
            'mx.tvujmail.cz',
        );

        self::assertFalse($r['pass']);
        self::assertSame('dmarc_domain_mismatch', $r['detail']);
    }

    public function testDmarcHeaderFromMustExactlyMatchSenderDomain(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dmarc=pass header.from=mail.rb.cz'],
            'rb.cz',
            'mx.tvujmail.cz',
        );

        self::assertFalse($r['pass']);
        self::assertSame('dmarc_domain_mismatch', $r['detail']);
    }

    public function testDmarcHeaderFromInsideReasonIsIgnored(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dmarc=pass reason=" header.from=rb.cz " header.from=evil.example'],
            'rb.cz',
            'mx.tvujmail.cz',
        );

        self::assertFalse($r['pass']);
        self::assertSame('dmarc_domain_mismatch', $r['detail']);
    }

    public function testDmarcPassWithoutHeaderFromIsRejected(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dmarc=pass'],
            'rb.cz',
            'mx.tvujmail.cz',
        );

        self::assertFalse($r['pass']);
        self::assertSame('dmarc_domain_missing', $r['detail']);
    }

    public function testDmarcPropertyMustBelongToTheSameResult(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dmarc=pass; arc=pass header.from=rb.cz'],
            'rb.cz',
            'mx.tvujmail.cz',
        );

        self::assertFalse($r['pass']);
        self::assertSame('dmarc_domain_missing', $r['detail']);
    }

    public function testAuthenticationResultInsideCommentIsIgnored(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dmarc=fail (dkim=pass header.d=rb.cz)'],
            'rb.cz',
            'mx.tvujmail.cz',
        );

        self::assertFalse($r['pass']);
        self::assertSame('auth_failed', $r['detail']);
    }

    public function testDkimPassWithAlignedDomainIsAccepted(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dkim=pass header.d=rb.cz'],
            'rb.cz',
            'mx.tvujmail.cz',
        );
        self::assertTrue($r['pass']);
    }

    public function testDkimPassWithSubdomainAligns(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dkim=pass header.d=mail.rb.cz'],
            'rb.cz',
            'mx.tvujmail.cz',
        );
        self::assertTrue($r['pass']);
    }

    public function testDkimPassWithWrongDomainIsRejected(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dkim=pass header.d=evil.com'],
            'rb.cz',
            'mx.tvujmail.cz',
        );
        self::assertTrue($r['checked']);
        self::assertFalse($r['pass']);
    }

    public function testDkimHeaderDomainInsideReasonIsIgnored(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dkim=pass reason=" header.d=rb.cz " header.d=evil.example'],
            'rb.cz',
            'mx.tvujmail.cz',
        );

        self::assertFalse($r['pass']);
        self::assertSame('dkim_domain_mismatch', $r['detail']);
    }

    public function testDkimFailIsRejected(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; spf=fail; dkim=fail; dmarc=fail'],
            'rb.cz',
            'mx.tvujmail.cz',
        );
        self::assertFalse($r['pass']);
    }

    public function testUnpinnedAuthenticationResultsAreRejected(): void
    {
        $r = $this->verifier->verify(
            ['attacker.example; dmarc=pass header.from=rb.cz'],
            'rb.cz',
        );

        self::assertFalse($r['pass']);
        self::assertSame('authserv_id_required', $r['detail']);
    }

    public function testPinnedAuthServIdRequiresExactMatch(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz.attacker.example; dmarc=pass header.from=rb.cz'],
            'rb.cz',
            'mx.tvujmail.cz',
        );

        self::assertFalse($r['pass']);
        self::assertSame('authserv_id_mismatch', $r['detail']);
    }

    public function testPinnedAuthServIdAcceptsCaseInsensitiveTokenWithVersionAndComment(): void
    {
        $r = $this->verifier->verify(
            ['MX.TVUJMAIL.CZ (inbound) 1; dkim=pass header.d=rb.cz'],
            'rb.cz',
            'mx.tvujmail.cz',
        );

        self::assertTrue($r['pass']);
    }

    public function testDoesNotScanPastUntrustedTopHeader(): void
    {
        $headers = [
            'attacker-injected; dmarc=pass header.from=rb.cz',
            'mx.tvujmail.cz; dkim=pass header.d=rb.cz',
        ];
        $r = $this->verifier->verify($headers, 'rb.cz', 'mx.tvujmail.cz');

        self::assertFalse($r['pass']);
        self::assertSame('authserv_id_mismatch', $r['detail']);
    }

    public function testDoesNotScanPastAuthoritativeFailure(): void
    {
        $headers = [
            'mx.tvujmail.cz; dkim=fail; dmarc=fail',
            'mx.tvujmail.cz; dkim=pass header.d=rb.cz',
        ];
        $r = $this->verifier->verify($headers, 'rb.cz', 'mx.tvujmail.cz');

        self::assertFalse($r['pass']);
        self::assertSame('auth_failed', $r['detail']);
    }

    public function testInvalidSenderDomainFailsClosed(): void
    {
        $r = $this->verifier->verify(
            ['mx.tvujmail.cz; dkim=pass header.d=rb.cz'],
            null,
            'mx.tvujmail.cz',
        );

        self::assertFalse($r['pass']);
        self::assertSame('sender_domain_missing', $r['detail']);
    }

    public function testDomainFromSender(): void
    {
        self::assertSame('rb.cz', $this->verifier->domainFromSender('Raiffeisenbank <info@rb.cz>'));
        self::assertSame('rb.cz', $this->verifier->domainFromSender('info@rb.cz'));
        self::assertSame('evil.example', $this->verifier->domainFromSender('"RB <info@rb.cz>" <attacker@evil.example>'));
        self::assertNull($this->verifier->domainFromSender('not-an-email'));
    }
}
