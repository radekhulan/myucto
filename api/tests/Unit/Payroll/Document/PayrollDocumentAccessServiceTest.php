<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\Payroll\PayrollDocumentAccessLinkRepository;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollDeliveryRecipientResolver;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollDocumentAccessService;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollSecureDeliveryPolicy;
use MyInvoice\Service\Payroll\Document\PayrollDocumentDeliveryLedgerService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Veřejná cesta zaměstnance k jeho pásce. Bez DB a s FAKE MAILEREM — tenhle test
 * nemůže odeslat žádný skutečný e-mail ani při chybě v konfiguraci prostředí.
 *
 * Ověřuje přesně ty vlastnosti, kvůli kterým je cesta bez přihlášení obhajitelná:
 * lokátor sám nestačí, hádání kódu má strop, nic se nevydá bez ověřené relace
 * a stav dokumentu neuniká před ověřením.
 */
#[Group('unit')]
final class PayrollDocumentAccessServiceTest extends TestCase
{
    /** @var list<array{code:string,to:list<string>,vars:array<string,mixed>}> */
    private array $sentMail = [];

    private PayrollDocumentAccessLinkRepository $links;
    private PayrollDocumentAccessService $service;

    protected function setUp(): void
    {
        $this->sentMail = [];
        $this->links = $this->createMock(PayrollDocumentAccessLinkRepository::class);

        $states = $this->createStub(PayrollModuleStateRepository::class);
        $states->method('get')->willReturn(['status' => 'active']);
        $employerPolicies = $this->createStub(PayrollEmployerPolicyRepository::class);
        $employerPolicies->method('findEffective')->willReturn([
            'delivery_channel' => 'employee_portal',
            'delivery_verified_on' => '2026-01-01',
        ]);

        $policy = new PayrollSecureDeliveryPolicy(
            new Config(['payroll' => ['secure_delivery' => [
                'enabled' => true,
                'max_code_attempts' => 3,
                'code_ttl_seconds' => 600,
            ]]]),
            new PayrollProductionGate($states, true),
            $employerPolicies,
        );

        $recipients = $this->createStub(PayrollDeliveryRecipientResolver::class);
        $recipients->method('plaintextEmail')->willReturn('zamestnanec@example.test');

        // Fake mailer: nikam nic neodesílá, jen si zprávy odkládá do pole.
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendTemplate')->willReturnCallback(
            function (string $code, string $locale, array $to, array $vars): string {
                $this->sentMail[] = ['code' => $code, 'to' => $to, 'vars' => $vars];
                return 'faked';
            },
        );

        $documents = $this->createStub(PayrollDocumentRepository::class);
        $documents->method('find')->willReturn([
            'id' => 55,
            'document_kind' => 'payslip',
            'period_start' => '2026-07-01',
            'created_at' => '2026-08-01 10:00:00',
            'size_bytes' => 4096,
            'suggested_filename' => 'vyplatni-paska.pdf',
            'mime_type' => 'application/pdf',
            'storage_key' => 'abc',
            'employee_id' => 9,
        ]);

        $this->service = new PayrollDocumentAccessService(
            $this->links,
            $documents,
            $this->createStub(PayrollDocumentStorage::class),
            $this->createStub(PayrollDocumentDeliveryLedgerService::class),
            $policy,
            $recipients,
            $mailer,
            new NullLogger(),
        );
    }

    public function testMalformedTokenNeverReachesTheDatabase(): void
    {
        // Bez téhle kontroly by šlo krátkými nebo netypickými řetězci sondovat
        // úložiště. Repozitář se nesmí ani dotknout.
        $this->links->expects(self::never())->method('findByTokenHash');

        foreach ([
            '',
            'kratky',
            str_repeat('a', 63),
            str_repeat('a', 65),
            str_repeat('A', 64),
            str_repeat('z', 64),
            '../../etc/passwd',
        ] as $token) {
            self::assertNull($this->service->resolveLive($token));
        }
    }

    public function testTokenIsLookedUpOnlyAsHash(): void
    {
        $token = str_repeat('ab', 32);
        $this->links->expects(self::once())
            ->method('findByTokenHash')
            ->with(hash('sha256', $token))
            ->willReturn(null);

        self::assertNull($this->service->resolveLive($token));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLinkStillInQueueIsNotUsable(): void
    {
        // Dokud odkaz neodešel, jeho lokátor nikdo nedostal — zásah je pokus
        // o uhodnutí a nesmí uspět, i kdyby náhodou trefil řádek.
        foreach (['pending', 'sending', 'failed', 'cancelled'] as $state) {
            self::assertNull(
                $this->serviceReturning($this->linkRow(['dispatch_state' => $state]))
                    ->resolveLive(str_repeat('ab', 32)),
                "Odkaz ve stavu {$state} nesmí být použitelný.",
            );
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRevokedOrExpiredLinkIsNotUsable(): void
    {
        foreach ([
            ['revoked_at' => '2026-08-01 00:00:00'],
            ['is_live' => false],
        ] as $override) {
            self::assertNull(
                $this->serviceReturning($this->linkRow($override))
                    ->resolveLive(str_repeat('ab', 32)),
            );
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testStateRevealsNothingAboutTheDocumentBeforeVerification(): void
    {
        $this->links->method('touchValidSession')->willReturn(false);

        $state = $this->service->state($this->linkRow(), null);

        self::assertFalse($state['verified']);
        self::assertArrayNotHasKey('document', $state);
        // Maskovaná adresa je jediné, co smí ven: zaměstnanec musí poznat,
        // do které schránky se má podívat.
        self::assertSame('z***@e***.test', $state['recipient_masked']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testStateRevealsDocumentOnlyAfterVerification(): void
    {
        $this->links->method('touchValidSession')->willReturn(true);

        $state = $this->service->state($this->linkRow(), str_repeat('cd', 32));

        self::assertTrue($state['verified']);
        self::assertSame('payslip', $state['document']['kind']);
    }

    public function testIssuedCodeGoesOutByMailAndIsStoredOnlyAsHash(): void
    {
        $this->links->method('secondsSinceLastCode')->willReturn(null);
        $this->links->expects(self::once())->method('invalidateCodes');
        $storedHash = null;
        $this->links->expects(self::once())
            ->method('insertCode')
            ->willReturnCallback(
                function (int $s, int $l, string $hash) use (&$storedHash): int {
                    $storedHash = $hash;
                    return 1;
                },
            );

        $result = $this->service->issueCode($this->linkRow(), '127.0.0.1');

        self::assertTrue($result['sent']);
        self::assertCount(1, $this->sentMail);
        $code = (string) $this->sentMail[0]['vars']['code'];
        self::assertMatchesRegularExpression('/^\d{6}$/D', $code);
        // Do úložiště jde jen otisk. Plaintext kódu existuje výhradně v e-mailu.
        self::assertSame(hash('sha256', $code), $storedHash);
        self::assertNotSame($code, $storedHash);
    }

    public function testResendCooldownIsEnforcedAndSendsNothing(): void
    {
        $this->links->method('secondsSinceLastCode')->willReturn(5);
        $this->links->expects(self::never())->method('insertCode');

        $result = $this->service->issueCode($this->linkRow(), null);

        self::assertFalse($result['sent']);
        self::assertGreaterThan(0, $result['cooldown_remaining']);
        self::assertSame([], $this->sentMail);
    }

    public function testWrongCodeCountsAnAttemptAndBurnsTheCodeAtTheCap(): void
    {
        $this->links->method('activeCode')->willReturn([
            'id' => 7,
            'code_hash' => hash('sha256', '123456'),
            'attempts' => 2,
        ]);
        $this->links->method('bumpCodeAttempts')->willReturn(3);
        // Třetí chybný pokus kód spálí — hádání šestimístného čísla tak není
        // průchozí cesta, protože další kód jde vydat až po cooldownu.
        $this->links->expects(self::once())->method('markCodeUsed');
        $this->links->expects(self::never())->method('createSession');

        self::assertNull($this->service->verifyCode($this->linkRow(), '999999', null));
    }

    public function testExhaustedCodeCannotBeUsedEvenWithTheRightValue(): void
    {
        $this->links->method('activeCode')->willReturn([
            'id' => 7,
            'code_hash' => hash('sha256', '123456'),
            'attempts' => 3,
        ]);
        $this->links->expects(self::never())->method('createSession');

        self::assertNull($this->service->verifyCode($this->linkRow(), '123456', null));
    }

    public function testNonNumericCodeIsRejectedWithoutTouchingStorage(): void
    {
        $this->links->expects(self::never())->method('activeCode');

        // `' 123456'` tu vědomě NENÍ: obalové mezery se ořezávají, protože kód se
        // typicky kopíruje z e-mailu i s nimi. Ořezaný kód je pak plnohodnotný.
        foreach (['', 'abcdef', '12345', '1234567', '12 34 56', '12345o'] as $code) {
            self::assertNull($this->service->verifyCode($this->linkRow(), $code, null));
        }
    }

    public function testCorrectCodeBurnsItAndIssuesASessionStoredAsHash(): void
    {
        $this->links->method('activeCode')->willReturn([
            'id' => 7,
            'code_hash' => hash('sha256', '123456'),
            'attempts' => 0,
        ]);
        $this->links->expects(self::once())->method('markCodeUsed');
        $storedHash = null;
        $this->links->expects(self::once())
            ->method('createSession')
            ->willReturnCallback(
                function (int $s, int $l, string $hash) use (&$storedHash): void {
                    $storedHash = $hash;
                },
            );

        $session = $this->service->verifyCode($this->linkRow(), '123456', '127.0.0.1');

        self::assertIsString($session);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $session);
        self::assertSame(hash('sha256', $session), $storedHash);
    }

    public function testDownloadWithoutVerifiedSessionIsRefused(): void
    {
        $this->links->method('touchValidSession')->willReturn(false);
        $this->links->expects(self::never())->method('recordDownload');

        $this->expectException(\DomainException::class);
        $this->service->download($this->linkRow(), str_repeat('cd', 32));
    }

    public function testMalformedSessionTokenIsNeverLookedUp(): void
    {
        $this->links->expects(self::never())->method('touchValidSession');

        foreach ([null, '', 'x', str_repeat('a', 63), str_repeat('G', 64)] as $session) {
            self::assertFalse($this->service->hasValidSession($this->linkRow(), $session));
        }
    }

    /** @param array<string,mixed> $linkRow */
    private function serviceReturning(array $linkRow): PayrollDocumentAccessService
    {
        $links = $this->createStub(PayrollDocumentAccessLinkRepository::class);
        $links->method('findByTokenHash')->willReturn($linkRow);
        $this->links = $links;
        $this->setUpWithLinks($links);
        return $this->service;
    }

    private function setUpWithLinks(PayrollDocumentAccessLinkRepository $links): void
    {
        $service = $this->service;
        $reflection = new \ReflectionClass(PayrollDocumentAccessService::class);
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $property = $reflection->getProperty($parameter->getName());
            $arguments[] = $parameter->getName() === 'links'
                ? $links
                : $property->getValue($service);
        }
        $this->service = $reflection->newInstanceArgs($arguments);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function linkRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 3,
            'supplier_id' => 1,
            'payroll_document_id' => 55,
            'employee_id' => 9,
            'recipient_masked' => 'z***@e***.test',
            'recipient_email_hash' => str_repeat("\x01", 32),
            'dispatch_state' => 'sent',
            'revoked_at' => null,
            'download_count' => 0,
            'expires_at' => '2026-12-31 00:00:00',
            'is_live' => true,
        ], $overrides);
    }
}
