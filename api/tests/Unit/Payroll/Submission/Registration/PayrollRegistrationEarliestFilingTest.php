<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEarliestFiling;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Jedno nejbližší datum přípravy a podání přihlášky (částečné PREZEC i plné
 * REGZEC): pozdější z pravidla osmi dnů před nástupem a ze dne, od kterého
 * ostrá ČSSZ přijímá REGZEC27 pro nástupy od 1. 1. 2027.
 */
final class PayrollRegistrationEarliestFilingTest extends TestCase
{
    /** @return iterable<string,array{0:string,1:string}> */
    public static function starts(): iterable
    {
        yield 'nástup v roce 2026: osm dnů předem' => ['2026-11-16', '2026-11-08'];
        yield 'poslední nástup hlášený REGZEC25' => ['2026-12-31', '2026-12-23'];
        yield 'nástup 1. 1. 2027: osm dnů vychází později než příjem REGZEC27' => ['2027-01-01', '2026-12-24'];
        yield 'nástup 4. 1. 2027' => ['2027-01-04', '2026-12-27'];
        yield 'nástup v únoru 2027' => ['2027-02-01', '2027-01-24'];
    }

    #[DataProvider('starts')]
    public function testEarliestDayIsTheLaterOfBothRules(string $startOn, string $expected): void
    {
        self::assertSame($expected, PayrollRegistrationEarliestFiling::earliestOn($startOn));
    }

    public function testMessageGivesOneDateAndWhatCanBeDoneNow(): void
    {
        $message = PayrollRegistrationEarliestFiling::tooEarlyMessage('2027-01-01');

        self::assertStringContainsString('nástupem 1. 1. 2027', $message);
        self::assertStringContainsString('nejdřív 24. 12. 2026', $message);
        self::assertSame(1, substr_count($message, '24. 12. 2026'));
        self::assertStringContainsString('profil registrace A1', $message);
        self::assertStringContainsString('Přihlášky k podání (A1)', $message);
        // Jediné datum pokynu: den příjmu REGZEC27 smí zaznít jen jako vysvětlení.
        self::assertStringNotContainsString('23. 12. 2026 nebo později', $message);
        self::assertStringNotContainsString('podejte rovnou plnou registraci', $message);
    }

    public function testMessageForStartIn2026DoesNotMentionRegzec27(): void
    {
        $message = PayrollRegistrationEarliestFiling::tooEarlyMessage('2026-11-30');

        self::assertStringContainsString('nejdřív 22. 11. 2026', $message);
        self::assertStringNotContainsString('REGZEC27', $message);
    }
}
