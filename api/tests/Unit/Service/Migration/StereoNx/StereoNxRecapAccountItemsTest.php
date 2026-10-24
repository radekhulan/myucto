<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingDocuments;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use PHPUnit\Framework\TestCase;

final class StereoNxRecapAccountItemsTest extends TestCase
{
    public function testMissingIssuedLinesAreReconstructedWithoutChangingSourceIdentity(): void
    {
        $document = $this->document();
        $mapped = $this->map($document, ['602.001' => 6000, '604.002' => 4000]);
        $result = $mapped['records']['issued'][0];

        self::assertArrayHasKey('target_items', $result);
        self::assertCount(2, $result['target_items']);
        self::assertSame(['602.001', '604.002'], array_column($result['target_items'], 'target_revenue_account_code'));
        self::assertSame([60.0, 40.0], array_column($result['target_items'], 'total_without_vat'));
        self::assertSame($document['items'], $result['items']);
        $this->assertReconstructedMessage($mapped, $result);
        $hash = new \ReflectionMethod(StereoNxImporter::class, 'sourceHash');
        self::assertSame($hash->invoke(null, $document), $hash->invoke(null, $result));
        $this->assertNativeStable($result);
    }

    public function testGrossPricingKeepsNativePerAccountBasesAndVat(): void
    {
        $mapped = $this->map($this->document(true), ['602.001' => 6000, '604.002' => 4000]);
        $result = $mapped['records']['issued'][0];
        self::assertArrayHasKey('target_items', $result);
        self::assertSame([60.0, 40.0], array_column($result['target_items'], 'total_without_vat'));
        self::assertSame([72.6, 48.4], array_column($result['target_items'], 'total_with_vat'));
        $this->assertReconstructedMessage($mapped, $result);
        $this->assertNativeStable($result);
    }

    public function testNetRoundingDifferenceCannotBeHiddenByAllocatingSourceVat(): void
    {
        // Jediný základ 0,06 má daň 0,01; dva nativní základy 0,03 mají dohromady 0,02.
        $document = $this->document(false, 0.06, 0.01);
        $this->assertUnresolved($document, $this->map($document, ['602.001' => 3, '604.002' => 3]));
    }

    public function testGrossRoundingMustPreserveEachAccountBaseNotOnlyTotalVat(): void
    {
        // SHORA z cen 0,04 + 0,04 dorovná první základ na 0,04 místo účetních 0,03.
        $document = $this->document(true, 0.06, 0.01);
        $this->assertUnresolved($document, $this->map($document, ['602.001' => 3, '604.002' => 3]));
    }

    public function testJournalBaseMismatchLeavesOriginalRecapAndWarning(): void
    {
        $document = $this->document();
        $this->assertUnresolved($document, $this->map($document, ['602.001' => 6000, '604.002' => 3999]));
    }

    public function testSourceVatMismatchLeavesOriginalRecapAndWarning(): void
    {
        $document = $this->document(false, 100, 20.99);
        $this->assertUnresolved($document, $this->map($document, ['602.001' => 6000, '604.002' => 4000]));
    }

    public function testActualSourceItemsAreNeverReplacedByInventedLines(): void
    {
        $document = $this->document();
        $document['origin'] = 'source_lines';
        $document['items'][0]['origin'] = 'source_line';
        $document['review_codes'] = [];
        $this->assertUnresolved($document, $this->map($document, ['602.001' => 6000, '604.002' => 4000]));
    }

    public function testRecapFallbackAfterUnsupportedSourceItemsCannotBeReconstructed(): void
    {
        $document = $this->document();
        $document['review_codes'][] = 'issued_lines_aggregated';
        $this->assertUnresolved($document, $this->map($document, ['602.001' => 6000, '604.002' => 4000]));
    }

    public function testMultipleVatBucketsWithoutAccountProofRemainAmbiguous(): void
    {
        $document = $this->document(false, 100, 16.5);
        $first = $this->document(false, 50, 10.5)['items'][0];
        $second = $this->document(false, 50, 6)['items'][0];
        $second['source_slot'] = 's';
        $second['vat_rate_snapshot'] = $second['vat_rate_percent'] = 12.0;
        $document['items'] = [$first, $second];
        $this->assertUnresolved($document, $this->map($document, ['602.001' => 6000, '604.002' => 4000]));
    }

    public function testPositiveCreditNoteItemsWithRedStornoKeepTheirSourceSign(): void
    {
        $document = $this->document();
        $document['target_document_kind'] = 'credit_note';
        $mapped = $this->map($document, ['602.001' => 6000, '604.002' => 4000], red: true);
        $result = $mapped['records']['issued'][0];
        self::assertArrayHasKey('target_items', $result);
        self::assertSame([60.0, 40.0], array_column($result['target_items'], 'total_without_vat'));
        self::assertSame($document['items'], $result['items']);
        $this->assertReconstructedMessage($mapped, $result);
        $this->assertNativeStable($result);
    }

    public function testAlreadyNegativeCreditNoteIsNotNegatedTwice(): void
    {
        $document = $this->document(true, -100, -21);
        $document['target_document_kind'] = 'credit_note';
        $mapped = $this->map($document, ['602.001' => -6000, '604.002' => -4000]);
        $result = $mapped['records']['issued'][0];
        self::assertArrayHasKey('target_items', $result);
        self::assertSame([-60.0, -40.0], array_column($result['target_items'], 'total_without_vat'));
        $this->assertReconstructedMessage($mapped, $result);
        $this->assertNativeStable($result);
    }

    public function testPurchaseWithFullDeductionUsesProvenNetExpenseAmounts(): void
    {
        $document = $this->document(true);
        $document['review_codes'] = [];
        $document['source_key'] = '["purchase","V","1","original"]';
        $mapped = $this->map($document, ['501.001' => 6000, '518.002' => 4000], purchase: true);
        $result = $mapped['records']['purchases'][0];
        self::assertArrayHasKey('target_items', $result);
        self::assertSame(['501.001', '518.002'], array_column($result['target_items'], 'target_expense_account_code'));
        self::assertSame([60.0, 40.0], array_column($result['target_items'], 'total_without_vat'));
        self::assertSame($document['items'], $result['items']);
        $this->assertReconstructedMessage($mapped, $result);
        $this->assertNativeStable($result);
    }

    public function testPurchaseWithoutDeductionDoesNotInferNetBasesFromGrossExpenses(): void
    {
        $document = $this->document(true);
        $document['review_codes'] = [];
        $document['items'][0]['vat_deduction'] = 'none';
        $mapped = $this->map($document, ['501.001' => 7260, '518.002' => 4840], purchase: true);
        $this->assertUnresolved($document, $mapped, 'purchases');
    }

    public function testReverseChargePurchasePreservesVerifiedSelfAssessedTax(): void
    {
        $document = $this->document(false, 100, 0);
        $document['review_codes'] = [];
        $document['reverse_charge'] = true;
        $document['items'][0]['source_self_assessed_vat'] = 21.0;
        $mapped = $this->map($document, ['501.001' => 6000, '518.002' => 4000], purchase: true);
        $result = $mapped['records']['purchases'][0];
        self::assertArrayHasKey('target_items', $result);
        self::assertSame([12.6, 8.4], array_column($result['target_items'], 'source_self_assessed_vat'));
        self::assertSame([0.0, 0.0], array_column($result['target_items'], 'total_vat'));
        self::assertSame($document['items'], $result['items']);
        $this->assertReconstructedMessage($mapped, $result);
        $this->assertNativeStable($result);
    }

    public function testReverseChargeTaxMismatchCannotBeSilentlyDistributed(): void
    {
        $document = $this->document(false, 100, 0);
        $document['review_codes'] = [];
        $document['reverse_charge'] = true;
        $document['items'][0]['source_self_assessed_vat'] = 20.99;
        $this->assertUnresolved($document,
            $this->map($document, ['501.001' => 6000, '518.002' => 4000], purchase: true), 'purchases');
    }

    public function testOppositeSignedAccountMovementsDoNotInventDiscountLines(): void
    {
        $document = $this->document();
        $this->assertUnresolved($document, $this->map($document, ['602.001' => 12000, '604.002' => -2000]));
    }

    public function testForeignCurrencyAndUnspecifiedPriceModeRemainUnresolved(): void
    {
        foreach ([['currency_code' => 'EUR'], ['prices_include_vat' => null]] as $override) {
            $document = [...$this->document(), ...$override];
            $this->assertUnresolved($document, $this->map($document, ['602.001' => 6000, '604.002' => 4000]));
        }
    }

    private function assertReconstructedMessage(array $mapped, array $document): void
    {
        self::assertNotContains('document_item_account_ambiguous', array_column($mapped['warnings'], 'code'));
        $messages = array_values(array_filter($mapped['warnings'],
            static fn (array $message): bool => $message['code'] === 'document_items_reconstructed'));
        self::assertCount(1, $messages);
        self::assertSame('info', $messages[0]['level']);
        self::assertSame($document['source_key'], $messages[0]['source_key']);
        self::assertSame($document['document_no'], $messages[0]['document_no']);
        self::assertNotSame('', trim($messages[0]['message']));
    }

    private function assertUnresolved(array $original, array $mapped, string $part = 'issued'): void
    {
        $result = $mapped['records'][$part][0];
        self::assertArrayNotHasKey('target_items', $result);
        self::assertSame($original['items'], $result['items']);
        self::assertContains('document_item_account_ambiguous', array_column($mapped['warnings'], 'code'));
    }

    private function document(bool $gross = false, float $base = 100.0, float $vat = 21.0): array
    {
        return ['source_key' => '["issued","V","1","original"]', 'document_no' => 'V1',
            'origin' => 'vat_recap', 'currency_code' => 'CZK', 'prices_include_vat' => $gross,
            'reverse_charge' => false, 'target_document_kind' => 'invoice',
            'review_codes' => ['issued_lines_missing'], 'requires_draft' => true,
            'total_without_vat' => $base, 'total_vat' => $vat, 'total_with_vat' => round($base + $vat, 2),
            'source_total_with_vat' => round($base + $vat, 2), 'rounding' => 0.0,
            'items' => [['origin' => 'vat_recap', 'source_slot' => 'z', 'description' => 'Syntetická rekapitulace',
                'quantity' => 1.0, 'unit_price' => $gross ? round($base + $vat, 2) : $base,
                'vat_rate_percent' => 21.0, 'vat_rate_snapshot' => 21.0,
                'total_without_vat' => $base, 'total_vat' => $vat, 'total_with_vat' => round($base + $vat, 2),
                'vat_classification_code' => null, 'vat_deduction' => 'full']]];
    }

    private function map(array $document, array $amounts, bool $purchase = false, bool $red = false): array
    {
        $entries = [];
        foreach ($amounts as $account => $amount) {
            $entries[] = ['source_key' => json_encode([$purchase ? 'PF' : 'VF', 'V', '1', 'line', count($entries)], JSON_THROW_ON_ERROR),
                'target_debit' => $purchase ? $account : '311', 'target_credit' => $purchase ? '321' : $account,
                'amount_cents' => $amount, 'is_opening' => false, 'is_red_storno' => $red];
        }
        return StereoNxAccountingDocuments::withAccountMapping(
            ['records' => [$purchase ? 'purchases' : 'issued' => [$document]], 'warnings' => [], 'errors' => []],
            ['accounting_plan' => ['entries' => $entries]]);
    }

    private function assertNativeStable(array $document): void
    {
        $native = array_map(static fn (array $item): array => [...$item,
            'unit_price_without_vat' => $item['unit_price_without_vat'] ?? $item['unit_price']], $document['target_items']);
        $computed = InvoiceMath::compute($native, $document['reverse_charge'], $document['prices_include_vat']);
        foreach ($document['target_items'] as $i => $item) {
            foreach (['total_without_vat' => 'base', 'total_vat' => 'vat', 'total_with_vat' => 'with'] as $field => $nativeField) {
                self::assertSame((int) round($item[$field] * 100), (int) round($computed['items'][$i][$nativeField] * 100));
            }
        }
        self::assertSame((int) round($document['total_without_vat'] * 100), (int) round($computed['totals']['without_vat'] * 100));
        self::assertSame((int) round($document['total_vat'] * 100), (int) round($computed['totals']['vat'] * 100));
    }
}
