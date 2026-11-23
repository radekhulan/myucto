<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Schválení měsíce odmítnuté kvůli nepublikovaným směnám musí říct, kolik jich
 * je, od kdy a kde se publikují. Holé „publikujte všechny plánované směny"
 * posílalo účetní hledat po celém kalendáři.
 */
final class PayrollTimeUnpublishedShiftsMessageTest extends TestCase
{
    public function testMessageNamesCountFirstLocalDateAndWhereToPublish(): void
    {
        $message = self::message([
            ['starts_at_utc' => '2026-06-18 06:00:00', 'timezone_name' => 'Europe/Prague'],
            // 23:30 UTC 2. 6. je v Praze už 3. 6. — rozhoduje místní datum.
            ['starts_at_utc' => '2026-06-02 23:30:00', 'timezone_name' => 'Europe/Prague'],
        ]);

        self::assertStringContainsString('2 plánované směny nejsou publikované', $message);
        self::assertStringContainsString('první 3. 6. 2026', $message);
        self::assertStringContainsString('Mzdy → Docházka', $message);
        self::assertStringContainsString('Publikovat zaměstnanci', $message);
    }

    public function testSingleShiftUsesSingular(): void
    {
        self::assertStringContainsString(
            '1 plánovaná směna není publikovaná, první 1. 6. 2026',
            self::message([['starts_at_utc' => '2026-06-01 05:00:00', 'timezone_name' => 'Europe/Prague']]),
        );
    }

    /** @param list<array<string,string>> $shifts */
    private static function message(array $shifts): string
    {
        $method = new \ReflectionMethod(PayrollTimeRepository::class, 'unpublishedShiftsMessage');

        return (string) $method->invoke(null, $shifts);
    }
}
