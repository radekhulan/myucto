<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Component\PayrollWholeCrowns;
use MyInvoice\Service\Payroll\Run\PayrollRunWholeCrownInputs;
use PHPUnit\Framework\TestCase;

/**
 * Haléře ve vstupu složky vykazované v JMHZ blokují běh už před schválením,
 * ne až přípravu hlášení po něm.
 */
final class PayrollRunWholeCrownInputsTest extends TestCase
{
    private const EMPLOYMENT = ['employment_id' => 7, 'full_name' => 'Syntetická Brigádnice'];

    public function testHalereInReportedComponentBlockRunWithInputList(): void
    {
        $validations = PayrollRunWholeCrownInputs::validations(
            [self::EMPLOYMENT],
            [7 => [
                self::input(123_450, 11, 'Provize', 'included'),
                self::input(50_000, 12, 'Odměna z DPP', 'included'),
            ]],
            '2026-05-01',
        );

        self::assertCount(1, $validations);
        self::assertSame('blocker', $validations[0]->severity);
        self::assertSame('inputs_not_whole_crowns', $validations[0]->code);
        self::assertSame(7, $validations[0]->entityId);
        self::assertStringContainsString('Provize 1 234,50 Kč', $validations[0]->message);
        self::assertStringNotContainsString('Odměna z DPP', $validations[0]->message);
        self::assertSame('/payroll/components?tab=inputs&period=2026-05&employment=7', $validations[0]->remediationPath);
    }

    public function testWholeComponentSumExcludedComponentsAndPreJmhzPeriodsPass(): void
    {
        $rows = [7 => [
            self::input(10_050, 11, 'Provize', 'included'),
            self::input(9_950, 11, 'Provize', 'included'),
            self::input(33_333, 13, 'Cestovní náhrada', 'excluded'),
        ]];

        self::assertSame([], PayrollRunWholeCrownInputs::validations([self::EMPLOYMENT], $rows, '2026-05-01'));
        self::assertSame([], PayrollRunWholeCrownInputs::validations(
            [self::EMPLOYMENT],
            [7 => [self::input(123_450, 11, 'Provize', 'included')]],
            '2026-03-01',
        ));
    }

    public function testRoundingIsUpwardInWholeHalere(): void
    {
        self::assertSame(123_500, PayrollWholeCrowns::roundUpMinor(123_401));
        self::assertSame(123_500, PayrollWholeCrowns::roundUpMinor(123_450));
        self::assertSame(123_400, PayrollWholeCrowns::roundUpMinor(123_400));
        self::assertSame(-100, PayrollWholeCrowns::roundUpMinor(-150));
        self::assertSame(0, PayrollWholeCrowns::roundUpMinor(-1));
    }

    /** @return array<string,mixed> */
    private static function input(int $amount, int $componentId, string $name, string $treatment): array
    {
        return [
            'amount_minor' => $amount,
            'component_snapshot_json' => json_encode([
                'component_id' => $componentId,
                'code' => 'C' . $componentId,
                'name' => $name,
                'jmhz_treatment' => $treatment,
            ], JSON_THROW_ON_ERROR),
        ];
    }
}
