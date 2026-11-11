<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Varování ke slevě na pojistném za kratší úvazek vedou na záložku záměrů
 * (OZUSPOJ) v Mzdových podáních a na období běhu, ne na obecný přehled
 * podání za aktuální měsíc.
 */
final class PayrollE2eHppDiscountLinkTest extends TestCase
{
    public function testMissingIntentLinksToDiscountIntentsOfTheRunPeriod(): void
    {
        $validations = self::discountValidations(null, null);

        self::assertSame(
            ['part_time_discount_intent_missing' => '/payroll/submissions/discount_intents?period=2026-06'],
            array_column(array_map(static fn ($item): array => (array) $item, $validations), 'remediationPath', 'code'),
        );
    }

    public function testEmployeeNotInformedLinksToDiscountIntentsOfTheRunPeriod(): void
    {
        $intent = ['status' => 'accepted', 'intent_from' => '2026-05-01', 'intent_to' => null, 'accepted_on' => '2026-04-20', 'discount_reason' => 'age_55'];
        $validations = self::discountValidations($intent, ['intent_from' => '2026-05-01', 'employee_informed_on' => null, 'predecessor_source' => null]);

        $paths = array_column(array_map(static fn ($item): array => (array) $item, $validations), 'remediationPath', 'code');
        self::assertSame('/payroll/submissions/discount_intents?period=2026-06', $paths['part_time_discount_employee_not_informed'] ?? null);
    }

    /**
     * @param array<string,mixed>|null $intent
     * @param array<string,mixed>|null $information
     * @return list<object>
     */
    private static function discountValidations(?array $intent, ?array $information): array
    {
        $reflection = new \ReflectionClass(PayrollRunSnapshotBuilder::class);

        return $reflection->getMethod('discountValidations')->invokeArgs(
            $reflection->newInstanceWithoutConstructor(),
            [['social_part_time_discount_reason' => 'age_55'], $intent, 22, 11, '2026-06-01', $information],
        );
    }
}
