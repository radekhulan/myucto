<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\MfaOfferService;
use MyInvoice\Service\Auth\MfaPolicyService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class MfaOfferServiceTest extends TestCase
{
    public function testVynucenaPolitikaNabidkuNikdyNenabidne(): void
    {
        // Nabídka nese tlačítko „pokračovat bez ověření" — u povinné MFA by byla
        // cestou kolem politiky. DB se na to ani nemá ptát.
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $service = new MfaOfferService($db, $this->policy(true));

        self::assertFalse($service->shouldOffer(17, false));
    }

    public function testUcetSFaktoremNabidkuNedostane(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $service = new MfaOfferService($db, $this->policy(false));

        self::assertFalse($service->shouldOffer(17, true));
    }

    public function testUcetBezFaktoruABezOdmitnutiNabidkuDostane(): void
    {
        $service = new MfaOfferService($this->connectionReturning(null), $this->policy(false));

        self::assertTrue($service->shouldOffer(17, false));
    }

    public function testJednouOdmitnutaNabidkaSeUzNevrati(): void
    {
        $service = new MfaOfferService(
            $this->connectionReturning('2026-08-27 10:00:00.000000'),
            $this->policy(false),
        );

        self::assertFalse($service->shouldOffer(17, false));
    }

    public function testDismissPriVynucenemMfaNeprojde(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $service = new MfaOfferService($db, $this->policy(true));

        self::assertFalse($service->dismiss(17));
    }

    public function testDismissZapiseRozhodnutiJenPoprve(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([17])->willReturn(true);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            // `IS NULL` v podmínce drží okamžik skutečného rozhodnutí — opakované
            // volání endpointu čas nepřepíše.
            ->with(self::stringContains('mfa_offer_dismissed_at IS NULL'))
            ->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $service = new MfaOfferService($db, $this->policy(false));

        self::assertTrue($service->dismiss(17));
    }

    private function policy(bool $required): MfaPolicyService
    {
        return new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa' => $required,
                'require_totp' => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]));
    }

    private function connectionReturning(?string $dismissedAt): Connection
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([17])->willReturn(true);
        $statement->expects(self::once())->method('fetchColumn')->willReturn($dismissedAt);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        return $db;
    }
}
