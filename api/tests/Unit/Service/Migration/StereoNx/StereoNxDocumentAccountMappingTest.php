<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingDocuments;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use PHPUnit\Framework\TestCase;

final class StereoNxDocumentAccountMappingTest extends TestCase
{
    public function testUniqueConfirmedAccountsFollowExactSourceDocumentIdentity(): void
    {
        $plan = $this->documents();
        $journal = ['accounting_plan' => ['entries' => [
            $this->entry('PF', 'P', '1', '518.001', '321.001'),
            $this->entry('PF', 'P', '1', '343.021', '321.001'),
            $this->entry('PF', 'P', '1', '518.001', '321.001'),
            $this->entry('VF', 'V', '1', '311.001', '602.007'),
            $this->entry('BF', 'P', '1', '501.008', '221.001'),
            $this->entry('PF', 'OTHER', '1', '501.009', '321.001'),
            $this->entry('PF', 'P', '2', '042.003', '321.001'),
            [...$this->entry('PF', 'P', '1', '501.010', '701'), 'is_opening' => true],
            [...$this->entry('PF', 'P', '1', '501.011', '321.001'), 'amount_cents' => 0],
        ]]];

        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, $journal);

        self::assertSame('518.001', $mapped['records']['purchases'][0]['items'][0]['target_expense_account_code']);
        self::assertSame('042.003', $mapped['records']['purchases'][1]['items'][0]['target_expense_account_code']);
        self::assertSame('602.007', $mapped['records']['issued'][0]['items'][0]['target_revenue_account_code']);
        self::assertSame([], $mapped['warnings']);
        $hash = new \ReflectionMethod(StereoNxImporter::class, 'sourceHash');
        foreach (['issued', 'purchases'] as $part) {
            foreach ($plan['records'][$part] as $i => $document) {
                self::assertSame($hash->invoke(null, $document), $hash->invoke(null, $mapped['records'][$part][$i]),
                    'Potvrzený cílový účet nesmí měnit otisk zdrojového dokladu.');
                unset($mapped['records'][$part][$i]['items'][0]['target_expense_account_code'],
                    $mapped['records'][$part][$i]['items'][0]['target_revenue_account_code']);
                self::assertSame($document, $mapped['records'][$part][$i]);
            }
        }
    }

    public function testAmbiguousAccountsWarnWithoutGuessingItemAllocation(): void
    {
        $plan = $this->documents();
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            $this->entry('PF', 'P', '1', '518.001', '321.001'),
            $this->entry('PF', 'P', '1', '501.002', '321.001'),
        ]]]);

        self::assertSame($plan['records'], $mapped['records']);
        self::assertCount(1, $mapped['warnings']);
        self::assertSame('document_item_account_ambiguous', $mapped['warnings'][0]['code']);
        self::assertSame($plan['records']['purchases'][0]['source_key'], $mapped['warnings'][0]['source_key']);
        self::assertSame(['501.002', '518.001'], $mapped['warnings'][0]['accounts']);
        self::assertStringContainsString('polož', $mapped['warnings'][0]['message']);
    }

    public function testOriginalItemAccountsAndSourceHashesKeepPrecedence(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'][0]['expense_account_code'] = '501.099';
        $plan['records']['issued'][0]['items'][0]['revenue_account_code'] = '604.099';
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            $this->entry('PF', 'P', '1', '518.001', '321.001'),
            $this->entry('PF', 'P', '1', '501.002', '321.001'),
            $this->entry('VF', 'V', '1', '311.001', '602.007'),
        ]]]);
        self::assertSame($plan, $mapped);
        $hash = new \ReflectionMethod(StereoNxImporter::class, 'sourceHash');
        $changed = $plan['records']['purchases'][0];
        $changed['items'][0]['expense_account_code'] = '501.098';
        self::assertNotSame($hash->invoke(null, $changed), $hash->invoke(null, $plan['records']['purchases'][0]));
    }

    public function testUnconfirmedAccountsAreNeverCopiedFromRawJournal(): void
    {
        $plan = $this->documents();
        $entry = $this->entry('PF', 'P', '1', '518.001', '321.001');
        unset($entry['target_debit'], $entry['target_credit']);
        self::assertSame($plan, StereoNxAccountingDocuments::withAccountMapping($plan,
            ['accounting_plan' => ['entries' => [$entry]]]));
    }

    public function testExplicitSourceItemAccountUsesItsConfirmedMapping(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'][0]['expense_account_code'] = '518001';
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, [
            'account_mapping' => ['accounts' => ['518001' => '518.009']],
            'accounting_plan' => ['entries' => [
                $this->entry('PF', 'P', '1', '518.009', '321.001'),
                $this->entry('PF', 'P', '1', '501.002', '321.001'),
            ]],
        ]);
        self::assertSame('518001', $mapped['records']['purchases'][0]['items'][0]['expense_account_code']);
        self::assertSame('518.009', $mapped['records']['purchases'][0]['items'][0]['target_expense_account_code']);
        self::assertSame([], $mapped['warnings']);
        $hash = new \ReflectionMethod(StereoNxImporter::class, 'sourceHash');
        self::assertSame($hash->invoke(null, $plan['records']['purchases'][0]),
            $hash->invoke(null, $mapped['records']['purchases'][0]));
    }

    public function testDerivedBankAnalyticSuffixDoesNotChangeSourceHash(): void
    {
        $hash = new \ReflectionMethod(StereoNxImporter::class, 'sourceHash');
        $record = ['source_key' => 'synthetic-bank', 'number' => '1000000005', 'bank_code' => '0100'];
        self::assertSame($hash->invoke(null, $record),
            $hash->invoke(null, [...$record, 'target_analytic_suffix' => '003']));
    }

    public function testMultipleAccountsAreAssignedByUniqueDirectItemAmountsIncludingGrossPricing(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'] = [$this->item(100, 21), $this->item(200, 12)];
        $journal = ['accounting_plan' => ['entries' => [
            $this->entry('PF', 'P', '1', '518.001', '321'),
            [...$this->entry('PF', 'P', '1', '501.002', '321'), 'amount_cents' => 20000],
            [...$this->entry('PF', 'P', '1', '343.100', '321'), 'amount_cents' => 4500],
        ]]];
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, $journal);
        self::assertSame(['518.001', '501.002'], array_column($mapped['records']['purchases'][0]['items'], 'target_expense_account_code'));
        self::assertSame([], $mapped['warnings']);
        self::assertTrue($mapped['records']['purchases'][0]['prices_include_vat']);
        $hash = new \ReflectionMethod(StereoNxImporter::class, 'sourceHash');
        self::assertSame($hash->invoke(null, $plan['records']['purchases'][0]), $hash->invoke(null, $mapped['records']['purchases'][0]));
    }

    public function testSeveralItemsCanBelongToOneAggregatedJournalAccountOnlyWhenUnique(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'] = [$this->item(30), $this->item(70), $this->item(200)];
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            $this->entry('PF', 'P', '1', '518.001', '321'),
            [...$this->entry('PF', 'P', '1', '501.002', '321'), 'amount_cents' => 20000],
        ]]]);
        self::assertSame(['518.001', '518.001', '501.002'], array_column($mapped['records']['purchases'][0]['items'], 'target_expense_account_code'));
        self::assertSame([], $mapped['warnings']);
    }

    public function testEqualAmountItemsRemainAmbiguousWithoutPartialAssignments(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'] = [$this->item(100), $this->item(100), $this->item(300)];
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            $this->entry('PF', 'P', '1', '518.001', '321'),
            $this->entry('PF', 'P', '1', '501.002', '321'),
            [...$this->entry('PF', 'P', '1', '504.003', '321'), 'amount_cents' => 30000],
        ]]]);
        self::assertSame($plan['records'], $mapped['records']);
        self::assertCount(1, $mapped['warnings']);
    }

    public function testDirectMatchDoesNotHideAlternativeGroupedSolution(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'] = [$this->item(100), $this->item(60), $this->item(40), $this->item(200)];
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            $this->entry('PF', 'P', '1', '518.001', '321'),
            [...$this->entry('PF', 'P', '1', '501.002', '321'), 'amount_cents' => 30000],
        ]]]);
        self::assertSame($plan['records'], $mapped['records'], 'Částku 100 lze doložit položkou 100 i součtem 60 + 40.');
        self::assertCount(1, $mapped['warnings']);
    }

    public function testExplicitItemAssignmentDisambiguatesTheRemainingEqualAmounts(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'] = [
            $this->item(100) + ['expense_account_code' => '518001'], $this->item(100),
        ];
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, [
            'account_mapping' => ['accounts' => ['518001' => '518.001']],
            'accounting_plan' => ['entries' => [$this->entry('PF', 'P', '1', '518.001', '321'),
                $this->entry('PF', 'P', '1', '501.002', '321')]],
        ]);
        self::assertSame(['518.001', '501.002'], array_column($mapped['records']['purchases'][0]['items'], 'target_expense_account_code'));
        self::assertSame('518001', $mapped['records']['purchases'][0]['items'][0]['expense_account_code']);
        self::assertSame([], $mapped['warnings']);
    }

    public function testNonDeductibleVatMatchesGrossExpenseWhileFullDeductionMatchesNet(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'] = [
            array_replace($this->item(100, 21), ['vat_deduction' => 'none']), $this->item(200, 21),
        ];
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            [...$this->entry('PF', 'P', '1', '518.001', '321'), 'amount_cents' => 12100],
            [...$this->entry('PF', 'P', '1', '501.002', '321'), 'amount_cents' => 20000],
        ]]]);
        self::assertSame(['518.001', '501.002'], array_column($mapped['records']['purchases'][0]['items'], 'target_expense_account_code'));
        self::assertSame([], $mapped['warnings']);
    }

    public function testCreditNoteAndRedStornoPreserveSignedExpenseEvidence(): void
    {
        foreach ([false, true] as $redStorno) {
            $plan = $this->documents();
            $plan['records']['purchases'][0]['target_document_kind'] = 'credit_note';
            $plan['records']['purchases'][0]['items'] = [$this->item(100), $this->item(200)];
            $entry = $redStorno
                ? $this->entry('PF', 'P', '1', '518.001', '321')
                : $this->entry('PF', 'P', '1', '321', '518.001');
            $second = $redStorno
                ? $this->entry('PF', 'P', '1', '501.002', '321')
                : $this->entry('PF', 'P', '1', '321', '501.002');
            $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
                [...$entry, 'is_red_storno' => $redStorno], [...$second, 'amount_cents' => 20000, 'is_red_storno' => $redStorno],
            ]]]);
            self::assertSame(['518.001', '501.002'], array_column($mapped['records']['purchases'][0]['items'], 'target_expense_account_code'));
            self::assertSame([], $mapped['warnings']);
        }
    }

    public function testIssuedRevenueAndNegativeDiscountItemUseSignedTotals(): void
    {
        $plan = $this->documents();
        $plan['records']['issued'][0]['items'] = [$this->item(120), $this->item(-20), $this->item(200)];
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            $this->entry('VF', 'V', '1', '311', '602.001'),
            [...$this->entry('VF', 'V', '1', '311', '604.002'), 'amount_cents' => 20000],
        ]]]);
        self::assertSame(['602.001', '602.001', '604.002'], array_column($mapped['records']['issued'][0]['items'], 'target_revenue_account_code'));
        self::assertSame([], $mapped['warnings']);
    }

    public function testSeveralJournalRowsAndRedStornoAreAggregatedWithTheirSigns(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'] = [$this->item(100), $this->item(-20), $this->item(200)];
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            $this->entry('PF', 'P', '1', '518.001', '321'),
            [...$this->entry('PF', 'P', '1', '518.001', '321'), 'amount_cents' => 2000, 'is_red_storno' => true],
            [...$this->entry('PF', 'P', '1', '501.002', '321'), 'amount_cents' => 20000],
        ]]]);
        self::assertSame(['518.001', '518.001', '501.002'], array_column($mapped['records']['purchases'][0]['items'], 'target_expense_account_code'));
        self::assertSame([], $mapped['warnings']);
    }

    public function testSignedMismatchCannotBeHiddenByAbsoluteAmounts(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'] = [$this->item(100), $this->item(200)];
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            [...$this->entry('PF', 'P', '1', '518.001', '321'), 'is_red_storno' => true],
            [...$this->entry('PF', 'P', '1', '501.002', '321'), 'amount_cents' => 20000, 'is_red_storno' => true],
        ]]]);
        self::assertSame($plan['records'], $mapped['records']);
        self::assertCount(1, $mapped['warnings']);
    }

    public function testNegativeCreditNoteItemsAreNotNegatedTwice(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['target_document_kind'] = 'credit_note';
        $plan['records']['purchases'][0]['items'] = [$this->item(-100), $this->item(-200)];
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            $this->entry('PF', 'P', '1', '321', '518.001'),
            [...$this->entry('PF', 'P', '1', '321', '501.002'), 'amount_cents' => 20000],
        ]]]);
        self::assertSame(['518.001', '501.002'], array_column($mapped['records']['purchases'][0]['items'], 'target_expense_account_code'));
        self::assertSame([], $mapped['warnings']);
    }

    public function testAllocationBeyondBoundedSearchLimitKeepsWarning(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'] = array_fill(0, 25, $this->item(100));
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            [...$this->entry('PF', 'P', '1', '518.001', '321'), 'amount_cents' => 10000],
            [...$this->entry('PF', 'P', '1', '501.002', '321'), 'amount_cents' => 240000],
        ]]]);
        self::assertSame($plan['records'], $mapped['records']);
        self::assertCount(1, $mapped['warnings']);
    }

    public function testCombinatorialDeadEndStopsWithoutInventingAnAllocation(): void
    {
        $plan = $this->documents();
        $plan['records']['purchases'][0]['items'] = array_fill(0, 24, $this->item(100));
        $mapped = StereoNxAccountingDocuments::withAccountMapping($plan, ['accounting_plan' => ['entries' => [
            [...$this->entry('PF', 'P', '1', '518.001', '321'), 'amount_cents' => 115000],
            [...$this->entry('PF', 'P', '1', '501.002', '321'), 'amount_cents' => 125000],
        ]]]);
        self::assertSame($plan['records'], $mapped['records']);
        self::assertCount(1, $mapped['warnings']);
    }

    private function item(float $net, float $rate = 21): array
    {
        return ['description' => 'Syntetická položka', 'quantity' => 1.0, 'unit_price' => round($net * (1 + $rate / 100), 2),
            'total_without_vat' => $net, 'total_vat' => round($net * $rate / 100, 2),
            'total_with_vat' => round($net * (1 + $rate / 100), 2), 'vat_rate_snapshot' => $rate, 'vat_deduction' => 'full'];
    }

    private function documents(): array
    {
        $item = ['description' => 'Syntetická položka', 'quantity' => 1.0,
            'total_without_vat' => 100.0, 'total_vat' => 21.0, 'total_with_vat' => 121.0,
            'vat_rate_snapshot' => 21.0, 'vat_deduction' => 'full'];
        $document = static fn (string $agenda, string $series, string $number): array => [
            'source_key' => json_encode([$agenda, $series, $number, 'original'], JSON_THROW_ON_ERROR),
            'source_hash' => 'original-source-hash', 'document_no' => $series . $number,
            'tax_date' => '2026-03-01', 'prices_include_vat' => true, 'items' => [$item],
        ];
        return ['records' => ['issued' => [$document('issued', 'V', '1')],
            'purchases' => [$document('purchase', 'P', '1'), $document('purchase', 'P', '2')]],
            'warnings' => [], 'errors' => []];
    }

    private function entry(string $agenda, string $series, string $number, string $debit, string $credit): array
    {
        return ['source_key' => json_encode([$agenda, $series, $number, 'line', '1'], JSON_THROW_ON_ERROR),
            'debit' => 'original-debit', 'credit' => 'original-credit', 'source_hash' => 'original-journal-hash',
            'target_debit' => $debit, 'target_credit' => $credit, 'amount_cents' => 10000, 'is_opening' => false];
    }
}
