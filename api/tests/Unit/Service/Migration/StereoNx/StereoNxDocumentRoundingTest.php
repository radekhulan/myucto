<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingDocuments;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use PHPUnit\Framework\TestCase;

final class StereoNxDocumentRoundingTest extends TestCase
{
    public function testIssuedRoundingDoesNotBecomeAnotherRevenueItem(): void
    {
        $document = $this->document();
        $mapped = $this->map($document, [['602.001', 20000], ['604.002', 13]]);
        $result = $mapped['records']['issued'][0];
        self::assertArrayHasKey('target_revenue_account_code', $result['items'][0]);
        self::assertSame('602.001', $result['items'][0]['target_revenue_account_code']);
        self::assertArrayNotHasKey('target_items', $result);
        $this->assertRoundingReview($mapped, '604.002', 0.13);
        $hash = new \ReflectionMethod(StereoNxImporter::class, 'sourceHash');
        self::assertSame($hash->invoke(null, $document), $hash->invoke(null, $result));
    }

    public function testPurchaseRoundingDoesNotBecomeAnotherExpenseItem(): void
    {
        $mapped = $this->map($this->document(), [['501.001', 20000], ['504.002', 13]], purchase: true);
        self::assertSame('501.001', $mapped['records']['purchases'][0]['items'][0]['target_expense_account_code']);
        $this->assertRoundingReview($mapped, '504.002', 0.13);
    }

    public function testNegativeRoundingIsSeparatedWithItsSignedAmount(): void
    {
        $mapped = $this->map($this->document(-0.13), [['602.001', 20000], ['604.002', -13]]);
        self::assertSame('602.001', $mapped['records']['issued'][0]['items'][0]['target_revenue_account_code']);
        $this->assertRoundingReview($mapped, '604.002', -0.13);
    }

    public function testCreditNoteRedStornoKeepsPositiveSourceItemsAndRounding(): void
    {
        $document = $this->document();
        $document['target_document_kind'] = 'credit_note';
        $mapped = $this->map($document, [['602.001', 20000], ['604.002', 13]], red: true);
        $result = $mapped['records']['issued'][0];
        self::assertSame('602.001', $result['items'][0]['target_revenue_account_code']);
        self::assertSame(200.0, $result['items'][0]['total_without_vat']);
        $this->assertRoundingReview($mapped, '604.002', 0.13);
    }

    public function testTwoPossibleRoundingAccountsRemainAmbiguous(): void
    {
        $document = $this->document();
        $document['items'][] = $this->item(0.13, 0.03);
        $document['total_without_vat'] = 200.13;
        $document['total_vat'] = 42.03;
        $document['total_with_vat'] = 242.16;
        $document['source_total_with_vat'] = 242.29;
        $mapped = $this->map($document, [['602.001', 20000], ['604.002', 13], ['609.003', 13]]);
        $this->assertUnresolved($document, $mapped);
    }

    public function testExhaustingBudgetOnLaterCandidateCannotConfirmEarlierRoundingAccount(): void
    {
        $document = $this->document();
        $document['items'][] = $this->item(0.13, 0.03);
        $document['total_without_vat'] = 200.13;
        $document['total_vat'] = 42.03;
        $document['total_with_vat'] = 242.16;
        $document['source_total_with_vat'] = 242.29;
        $amounts = ['602.001' => 20000, '604.002' => 13, '609.003' => 13];
        $postings = [
            ['account' => '604.002', 'amount_cents' => 13, 'control' => '311'],
            ['account' => '609.003', 'amount_cents' => 13, 'control' => '311'],
        ];
        $ampleBudget = 100;
        self::assertNull(StereoNxAccountingDocuments::separateRounding(
            $document, 'revenue_account_code', $amounts, $postings, [], $ampleBudget));
        $limitedBudget = 5;
        self::assertNull(StereoNxAccountingDocuments::separateRounding(
            $document, 'revenue_account_code', $amounts, $postings, [], $limitedBudget),
            'Nedokončené prověření druhého účtu nesmí být důkazem jeho vyloučení.');
    }

    public function testCandidateWithSeveralValidItemAllocationsCannotBeExcluded(): void
    {
        $document = $this->document();
        $document['items'] = [$this->item(0.13, 0.03), $this->item(0.13, 0.03), $this->item(0.39, 0.08)];
        $document['total_without_vat'] = 0.65;
        $document['total_vat'] = 0.14;
        $document['total_with_vat'] = 0.79;
        $document['source_total_with_vat'] = 0.92;
        $amounts = ['602.001' => 26, '604.002' => 52];
        $postings = [
            ['account' => '602.001', 'amount_cents' => 13, 'control' => '311'],
            ['account' => '604.002', 'amount_cents' => 13, 'control' => '311'],
        ];
        $budget = 1000;
        self::assertNull(StereoNxAccountingDocuments::separateRounding(
            $document, 'revenue_account_code', $amounts, $postings, [], $budget),
            'Více přiřazení položek po odečtení zaokrouhlení stále dokládá možný účet zaokrouhlení.');
    }

    public function testDuplicateCandidateRowsOnSameAccountDoNotCreateFalseAmbiguity(): void
    {
        // Jedna částka je položka, druhá zaokrouhlení; obě patří témuž účtu.
        $document = $this->document();
        $document['items'][] = $this->item(0.13, 0.03);
        $document['total_without_vat'] = 200.13;
        $document['total_vat'] = 42.03;
        $document['total_with_vat'] = 242.16;
        $document['source_total_with_vat'] = 242.29;
        $mapped = $this->map($document, [['602.001', 20000], ['604.002', 13], ['604.002', 13]]);
        self::assertSame(['602.001', '604.002'], array_column($mapped['records']['issued'][0]['items'], 'target_revenue_account_code'));
        $this->assertRoundingReview($mapped, '604.002', 0.13);
    }

    public function testMissingRoundingHeaderDoesNotHideAnotherAccount(): void
    {
        $document = $this->document();
        unset($document['rounding']);
        $this->assertUnresolved($document, $this->map($document, [['602.001', 20000], ['604.002', 13]]));
    }

    public function testMismatchedAmountDoesNotHideAnotherAccount(): void
    {
        $document = $this->document();
        $this->assertUnresolved($document, $this->map($document, [['602.001', 20000], ['604.002', 14]]));
    }

    public function testMatchingAmountWithoutControlAccountIsNotProofOfRounding(): void
    {
        $document = $this->document();
        $this->assertUnresolved($document, $this->map($document, [['602.001', 20000], ['604.002', 13, '221']]));
    }

    public function testMissingRawSidesDoNotWarnOrProveRoundingFromTargetAccounts(): void
    {
        foreach ([false, true] as $purchase) {
            $document = $this->document();
            $part = $purchase ? 'purchases' : 'issued';
            $entries = [];
            foreach ([[$purchase ? '501.001' : '602.001', 20000], [$purchase ? '504.002' : '604.002', 13]] as [$account, $amount]) {
                $entries[] = ['source_key' => json_encode([$purchase ? 'PF' : 'VF', 'V', '1', 'line', count($entries)], JSON_THROW_ON_ERROR),
                    'target_debit' => $purchase ? $account : '311', 'target_credit' => $purchase ? '321' : $account,
                    'amount_cents' => $amount, 'is_opening' => false];
            }
            set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            try {
                $mapped = StereoNxAccountingDocuments::withAccountMapping(
                    ['records' => [$part => [$document]], 'warnings' => [], 'errors' => []],
                    ['accounting_plan' => ['entries' => $entries]]);
            } finally {
                restore_error_handler();
            }
            self::assertSame($document['items'], $mapped['records'][$part][0]['items']);
            self::assertContains('document_item_account_ambiguous', array_column($mapped['warnings'], 'code'));
            self::assertNotContains('document_rounding_account_review', array_column($mapped['warnings'], 'code'));
        }
    }

    public function testMatchingRoundingAmountDoesNotHideUnexplainedBaseDifference(): void
    {
        $document = $this->document();
        $this->assertUnresolved($document, $this->map($document, [['602.001', 19999], ['604.002', 13]]));
    }

    private function assertUnresolved(array $document, array $mapped): void
    {
        self::assertSame($document['items'], $mapped['records']['issued'][0]['items']);
        self::assertContains('document_item_account_ambiguous', array_column($mapped['warnings'], 'code'));
        self::assertNotContains('document_rounding_account_review', array_column($mapped['warnings'], 'code'));
    }

    private function document(float $rounding = 0.13): array
    {
        return ['source_key' => '["issued","V","1","original"]', 'document_no' => 'V1',
            'origin' => 'source_lines', 'currency_code' => 'CZK', 'prices_include_vat' => false,
            'reverse_charge' => false, 'target_document_kind' => 'invoice',
            'review_codes' => [], 'requires_draft' => false, 'rounding' => $rounding,
            'total_without_vat' => 200.0, 'total_vat' => 42.0, 'total_with_vat' => 242.0,
            'source_total_with_vat' => round(242 + $rounding, 2),
            'items' => [$this->item(200.0, 42.0)]];
    }

    private function item(float $base, float $vat): array
    {
        return ['origin' => 'source_line', 'description' => 'Syntetická položka', 'quantity' => 1.0,
            'unit_price' => $base, 'vat_rate_snapshot' => 21.0, 'vat_rate_percent' => 21.0,
            'total_without_vat' => $base, 'total_vat' => $vat, 'total_with_vat' => round($base + $vat, 2),
            'vat_deduction' => 'full'];
    }

    private function map(array $document, array $postings, bool $purchase = false, bool $red = false): array
    {
        $entries = [];
        foreach ($postings as $posting) {
            [$account, $amount] = $posting;
            $control = $posting[2] ?? ($purchase ? '321' : '311');
            $entries[] = ['source_key' => json_encode([$purchase ? 'PF' : 'VF', 'V', '1', 'line', count($entries)], JSON_THROW_ON_ERROR),
                'debit' => $purchase ? $account : $control, 'credit' => $purchase ? $control : $account,
                'target_debit' => $purchase ? $account : $control, 'target_credit' => $purchase ? $control : $account,
                'amount_cents' => $amount, 'is_opening' => false, 'is_red_storno' => $red];
        }
        return StereoNxAccountingDocuments::withAccountMapping(
            ['records' => [$purchase ? 'purchases' : 'issued' => [$document]], 'warnings' => [], 'errors' => []],
            ['accounting_plan' => ['entries' => $entries]]);
    }

    private function assertRoundingReview(array $mapped, string $account, float $rounding): void
    {
        self::assertNotContains('document_item_account_ambiguous', array_column($mapped['warnings'], 'code'));
        $warnings = array_values(array_filter($mapped['warnings'],
            static fn (array $warning): bool => $warning['code'] === 'document_rounding_account_review'));
        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]['level']);
        self::assertSame($account, $warnings[0]['source_rounding_account']);
        self::assertSame((int) round($rounding * 100), (int) round($warnings[0]['rounding_amount'] * 100));
        self::assertStringContainsString('zaokrouhlen', $warnings[0]['message']);
    }
}
