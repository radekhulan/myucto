<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Net;

use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use PHPUnit\Framework\TestCase;

/**
 * Den doručení dohody o srážkách plátci mzdy (§ 2045 odst. 2 obč. zák.) —
 * vstupní vrstva. Nález E-03.
 */
final class DeductionAgreementDeliveryDateTest extends TestCase
{
    public function testDeliveryDateIsOptionalAndDefaultsToNull(): void
    {
        self::assertNull(DeductionAgreementTerms::fromRequest($this->body())->deliveredOn);
        self::assertNull(
            DeductionAgreementTerms::fromRequest($this->body(['delivered_on' => '']))->deliveredOn,
        );
    }

    public function testDeliveryDateIsKept(): void
    {
        self::assertSame(
            '2026-01-15',
            DeductionAgreementTerms::fromRequest(
                $this->body(['delivered_on' => '2026-01-15']),
            )->deliveredOn,
        );
    }

    /** Doručení PŘED začátkem účinnosti je legitimní — pořadí vzniká doručením. */
    public function testDeliveryDateMayPrecedeValidity(): void
    {
        self::assertSame(
            '2025-12-01',
            DeductionAgreementTerms::fromRequest($this->body([
                'delivered_on' => '2025-12-01',
                'valid_from' => '2026-01-01',
            ]))->deliveredOn,
        );
    }

    public function testDeliveryDateAfterEndOfValidityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Den doručení');

        DeductionAgreementTerms::fromRequest($this->body([
            'valid_to' => '2026-06-30',
            'delivered_on' => '2026-07-01',
        ]));
    }

    public function testMalformedDeliveryDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeductionAgreementTerms::fromRequest($this->body(['delivered_on' => '15. 1. 2026']));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'title' => 'Stravenky',
            'deduction_kind' => 'meal',
            'priority_no' => 100,
            'requested_minor' => 50_000,
            'valid_from' => '2026-01-01',
            ...$overrides,
        ];
    }
}
