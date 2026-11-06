<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\PurchaseInvoice;

use MyInvoice\Service\PurchaseInvoice\PurchaseVatTreatment;
use MyInvoice\Service\PurchaseInvoice\PurchaseVatTreatmentConflict;
use PHPUnit\Framework\TestCase;

final class PurchaseVatTreatmentTest extends TestCase
{
    private const MAP = [
        '40' => ['dphdp3_line' => '40', 'dphdp3_line_secondary' => null, 'kh_section' => 'B.2', 'is_reverse_charge' => false],
        '41' => ['dphdp3_line' => '41', 'dphdp3_line_secondary' => null, 'kh_section' => 'B.2', 'is_reverse_charge' => false],
        '42' => ['dphdp3_line' => null, 'dphdp3_line_secondary' => null, 'kh_section' => null, 'is_reverse_charge' => false],
        'mimo' => ['dphdp3_line' => null, 'dphdp3_line_secondary' => null, 'kh_section' => null, 'is_reverse_charge' => false],
        '24e' => ['dphdp3_line' => '5', 'dphdp3_line_secondary' => '43', 'kh_section' => 'A.2', 'is_reverse_charge' => true],
    ];

    public function testNoClaimCodesAreThoseOutsideReturnAndNotSelfAssessed(): void
    {
        self::assertTrue(PurchaseVatTreatment::isNoClaimCode('42', self::MAP));
        self::assertTrue(PurchaseVatTreatment::isNoClaimCode('mimo', self::MAP));
        self::assertFalse(PurchaseVatTreatment::isNoClaimCode('40', self::MAP));
        self::assertFalse(PurchaseVatTreatment::isNoClaimCode('24e', self::MAP));
        self::assertFalse(PurchaseVatTreatment::isNoClaimCode('neznamy', self::MAP));
        self::assertFalse(PurchaseVatTreatment::isNoClaimCode(null, self::MAP));
        self::assertTrue(PurchaseVatTreatment::isClaimCode('41', self::MAP));
        self::assertFalse(PurchaseVatTreatment::isClaimCode('24e', self::MAP));
    }

    public function testMixedAllocationsAreSummarisedByPart(): void
    {
        $summary = PurchaseVatTreatment::summarize([
            'vat_deduction' => 'full', 'vat_deduction_percent' => 100,
            'vat_allocations' => [
                ['vat_deduction' => 'full', 'vat_deduction_percent' => 100, 'base_amount' => 700, 'vat_amount' => 147],
                ['vat_deduction' => 'proportional', 'vat_deduction_percent' => 60, 'base_amount' => 300, 'vat_amount' => 63],
            ],
            'items' => [['id' => 5, 'vat_classification_code' => '40']],
        ], self::MAP);

        self::assertSame('allocations', $summary['source']);
        self::assertSame('mixed', $summary['effective']);
        self::assertCount(2, $summary['parts']);
        self::assertSame(60.0, $summary['parts'][1]['vat_deduction_percent']);
        self::assertSame([], $summary['warnings'], 'Smíšený nárok hlavička vyjádřit neumí, upozornění by nešlo odstranit.');
        self::assertSame('allocations', $summary['items'][0]['state']);
    }

    public function testNoClaimHeaderCodeWithClaimItemIsFlagged(): void
    {
        $summary = PurchaseVatTreatment::summarize([
            'vat_deduction' => 'full', 'vat_classification_code' => '42',
            'items' => [['id' => 1, 'vat_classification_code' => '40'], ['id' => 2, 'vat_classification_code' => null]],
        ], self::MAP);

        self::assertSame(['header_no_claim_code_with_claim_items'], $summary['warnings']);
        self::assertSame(['claim', 'no_claim_code'], array_column($summary['items'], 'state'));
        self::assertSame(['40', '42'], array_column($summary['items'], 'effective_code'));
    }

    public function testSyncLeavesAllocationsAloneWhenHeaderDoesNotChangeOrUserEditedThem(): void
    {
        $full = ['vat_deduction' => 'full', 'vat_deduction_percent' => 100];
        $none = ['vat_deduction' => 'none', 'vat_deduction_percent' => 0];
        $rows = [['usage_type' => 'business'] + $full];

        self::assertNull(PurchaseVatTreatment::syncAllocationsWithHeader($full, $full, $rows, null));
        self::assertNull(PurchaseVatTreatment::syncAllocationsWithHeader($full, $none, [], null));
        self::assertNull(PurchaseVatTreatment::syncAllocationsWithHeader($full, $none, $rows, [['usage_type' => 'business'] + $none]),
            'Rozpis změněný v témže požadavku je volba uživatele.');
    }

    public function testSyncPropagatesUniformAllocationsAndRejectsAmbiguousOnes(): void
    {
        $full = ['vat_deduction' => 'full', 'vat_deduction_percent' => 100];
        $prop = ['vat_deduction' => 'proportional', 'vat_deduction_percent' => 40];
        $rows = [['usage_type' => 'business'] + $full, ['usage_type' => 'business'] + $full];

        $out = PurchaseVatTreatment::syncAllocationsWithHeader($full, $prop, $rows, null);
        self::assertSame(['proportional', 'proportional'], array_column((array) $out, 'vat_deduction'));
        self::assertSame([40.0, 40.0], array_column((array) $out, 'vat_deduction_percent'));

        $this->expectException(PurchaseVatTreatmentConflict::class);
        PurchaseVatTreatment::syncAllocationsWithHeader($full, $prop, [
            ['usage_type' => 'business'] + $full,
            ['usage_type' => 'personal', 'vat_deduction' => 'none', 'vat_deduction_percent' => 0],
        ], null);
    }

    public function testSyncRejectsUsageThatForbidsNewDeduction(): void
    {
        $none = ['vat_deduction' => 'none', 'vat_deduction_percent' => 0];
        $this->expectException(PurchaseVatTreatmentConflict::class);
        PurchaseVatTreatment::syncAllocationsWithHeader($none, ['vat_deduction' => 'full', 'vat_deduction_percent' => 100],
            [['usage_type' => 'personal'] + $none], null);
    }
}
