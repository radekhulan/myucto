<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Validation;

use MyInvoice\Service\Invoice\InvoiceRounding;
use MyInvoice\Service\Invoice\RefundDocument;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use MyInvoice\Service\Validation\InvoiceValidation;
use MyInvoice\Support\Sql\CreditNoteRefundExpr;
use PHPUnit\Framework\TestCase;

/**
 * Vyúčtování s výsledkem k vyplacení: faktura vratných obalů, kde vrácený sud převáží
 * zboží. 24× limonáda (21 %) + 24× záloha lahev + 1× přepravka (0 %) − sud 1 500 (0 %)
 * = 892 − 1 500 = −608 Kč.
 */
final class RefundInvoicePolicyTest extends TestCase
{
    private const VAT_RATES = [1 => 21.0, 2 => 0.0];

    /** @return array<string,mixed> */
    private static function bottleInvoice(array $overrides = []): array
    {
        return $overrides + [
            'invoice_type' => 'invoice',
            'advance_paid_amount' => 0,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Limonáda', 'quantity' => 24, 'unit_price_without_vat' => 25, 'vat_rate_id' => 1],
                ['description' => 'Záloha lahev', 'quantity' => 24, 'unit_price_without_vat' => 3, 'vat_rate_id' => 2],
                ['description' => 'Přepravka', 'quantity' => 1, 'unit_price_without_vat' => 94, 'vat_rate_id' => 2],
                ['description' => 'Vrácený sud', 'quantity' => 1, 'unit_price_without_vat' => -1500, 'vat_rate_id' => 2],
            ],
        ];
    }

    public function testNegativeInvoiceStaysRejectedWithoutSupplierSwitch(): void
    {
        self::assertSame(
            InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE,
            InvoiceAmountPolicy::validatePositiveAmountToPay(self::bottleInvoice(), self::VAT_RATES),
        );
        self::assertSame(
            InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE,
            InvoiceAmountPolicy::validatePositiveAmountToPay(self::bottleInvoice(), self::VAT_RATES, false, 'CZK'),
        );
    }

    public function testNegativeInvoiceWithSupplyLineIsAllowedWithSwitch(): void
    {
        self::assertNull(InvoiceAmountPolicy::validatePositiveAmountToPay(self::bottleInvoice(), self::VAT_RATES, true, 'CZK'));
        self::assertNull(InvoiceAmountPolicy::validatePositiveAmountToPay(self::bottleInvoice(), self::VAT_RATES, true));

        $errors = InvoiceValidation::invoice(self::bottleInvoice(['client_id' => 1]), self::VAT_RATES, null, null, true, 'CZK');
        self::assertArrayNotHasKey('amount_to_pay', $errors);
        $errors = InvoiceValidation::invoice(self::bottleInvoice(['client_id' => 1]), self::VAT_RATES);
        self::assertSame([InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE], $errors['amount_to_pay'] ?? null);
    }

    public function testProformaNeverRefund(): void
    {
        self::assertSame(
            InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE,
            InvoiceAmountPolicy::validatePositiveAmountToPay(self::bottleInvoice(['invoice_type' => 'proforma']), self::VAT_RATES, true, 'CZK'),
        );
    }

    public function testOnlyNegativeLinesStayACreditNote(): void
    {
        $data = self::bottleInvoice(['items' => [
            ['description' => 'Vrácený sud', 'quantity' => 1, 'unit_price_without_vat' => -1500, 'vat_rate_id' => 2],
            ['description' => 'Vrácená přepravka', 'quantity' => -1, 'unit_price_without_vat' => 94, 'vat_rate_id' => 2],
        ]]);

        self::assertSame(
            InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE,
            InvoiceAmountPolicy::validatePositiveAmountToPay($data, self::VAT_RATES, true, 'CZK'),
        );
    }

    public function testZeroAmountStaysRejected(): void
    {
        $data = self::bottleInvoice(['items' => [
            ['description' => 'Přepravka', 'quantity' => 1, 'unit_price_without_vat' => 94, 'vat_rate_id' => 2],
            ['description' => 'Vrácená přepravka', 'quantity' => 1, 'unit_price_without_vat' => -94, 'vat_rate_id' => 2],
        ]]);

        self::assertSame(
            InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE,
            InvoiceAmountPolicy::validatePositiveAmountToPay($data, self::VAT_RATES, true, 'CZK'),
        );
    }

    public function testCashRoundingToZeroIsRejectedAndToWholeCrownsAllowed(): void
    {
        $tiny = self::bottleInvoice([
            'payment_method' => 'cash',
            'rounding_mode' => 'auto',
            'items' => [
                ['description' => 'Přepravka', 'quantity' => 1, 'unit_price_without_vat' => 94, 'vat_rate_id' => 2],
                ['description' => 'Vrácená přepravka', 'quantity' => 1, 'unit_price_without_vat' => -94.30, 'vat_rate_id' => 2],
            ],
        ]);
        self::assertSame(
            InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE,
            InvoiceAmountPolicy::validatePositiveAmountToPay($tiny, self::VAT_RATES, true, 'CZK'),
            'Hotově −0,30 Kč se zaokrouhlí na 0, vyplácet není co.',
        );
        self::assertNull(
            InvoiceAmountPolicy::validatePositiveAmountToPay($tiny + [], self::VAT_RATES, true, 'EUR'),
            'Cizí měna se nezaokrouhluje.',
        );

        $cash = self::bottleInvoice(['payment_method' => 'cash', 'rounding_mode' => 'auto']);
        $cash['items'][3]['unit_price_without_vat'] = -1499.60;
        self::assertNull(InvoiceAmountPolicy::validatePositiveAmountToPay($cash, self::VAT_RATES, true, 'CZK'));
    }

    public function testRoundingIsSymmetricForRefundAmount(): void
    {
        self::assertSame(-0.4, InvoiceRounding::adjustment(-607.60, 'auto', 'CZK', 'cash', 'invoice'));
        self::assertSame(0.4, InvoiceRounding::adjustment(607.60, 'auto', 'CZK', 'cash', 'invoice'));
        self::assertSame(-0.5, InvoiceRounding::adjustment(-607.50, 'auto', 'CZK', 'cash', 'invoice'));
        self::assertSame(0.5, InvoiceRounding::adjustment(607.50, 'auto', 'CZK', 'cash', 'invoice'));
        self::assertSame(-608.0, round(-607.60 + InvoiceRounding::adjustment(-607.60, 'auto', 'CZK', 'cash', 'invoice'), 2));
    }

    public function testFinalInvoiceWithAdvanceKeepsExistingExemption(): void
    {
        $data = self::bottleInvoice(['parent_invoice_id' => 99]);
        self::assertNull(InvoiceAmountPolicy::validatePositiveAmountToPay($data, self::VAT_RATES));
        self::assertNull(InvoiceAmountPolicy::validatePositiveAmountToPay($data, self::VAT_RATES, true, 'CZK'));
    }

    public function testStoredRefundInvoicePredicates(): void
    {
        $stored = [
            'invoice_type' => 'invoice',
            'amount_to_pay' => -608.0,
            'status' => 'issued',
            'parent_invoice_id' => null,
            'items' => self::bottleInvoice()['items'],
        ];

        self::assertTrue(InvoiceAmountPolicy::isAllowedRefundInvoice($stored));
        self::assertFalse(InvoiceAmountPolicy::hasPositiveAmountToPay($stored));
        self::assertTrue(InvoiceAmountPolicy::canBeMarkedPaid($stored));
        self::assertFalse(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue($stored), 'Vystavení nesmí fakturu k vyplacení označit jako zaplacenou.');
        self::assertTrue(
            InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue(['parent_invoice_id' => 7] + $stored),
            'Přeplatek zálohy (finál s parentem) se označuje jako dosud.',
        );

        self::assertFalse(InvoiceAmountPolicy::isAllowedRefundInvoice(['invoice_type' => 'proforma'] + $stored));
        self::assertFalse(InvoiceAmountPolicy::isAllowedRefundInvoice(['items' => [self::bottleInvoice()['items'][3]]] + $stored));
        self::assertFalse(InvoiceAmountPolicy::isAllowedRefundInvoice(['amount_to_pay' => 0.0] + $stored));
        self::assertFalse(InvoiceAmountPolicy::canBeMarkedPaid(['amount_to_pay' => 0.0] + $stored));

        self::assertTrue(RefundDocument::isOpenRefund($stored));
        self::assertSame(608.0, RefundDocument::refundAmount($stored));
        self::assertFalse(RefundDocument::isRefundDocument(['invoice_type' => 'proforma'] + $stored));
    }

    public function testRefundedPredicateCoversNegativeInvoiceButNotAdvanceOverpayment(): void
    {
        self::assertTrue(CreditNoteRefundExpr::isRefundedAsOf('credit_note', 'paid', '2099-05-20', '2099-12-31'));
        self::assertTrue(CreditNoteRefundExpr::isRefundedAsOf('invoice', 'paid', '2099-05-20', '2099-12-31', -608.0, null));
        self::assertFalse(CreditNoteRefundExpr::isRefundedAsOf('invoice', 'paid', '2099-05-20', '2099-05-19', -608.0, null));
        self::assertFalse(CreditNoteRefundExpr::isRefundedAsOf('invoice', 'paid', '2099-05-20', '2099-12-31', -608.0, 7));
        self::assertFalse(CreditNoteRefundExpr::isRefundedAsOf('invoice', 'paid', '2099-05-20', '2099-12-31', 608.0, null));
        self::assertFalse(CreditNoteRefundExpr::isRefundedAsOf('invoice', 'issued', null, '2099-12-31', -608.0, null));
        // Záporná zálohová faktura (vrácení zálohy z převodu) je doklad k vyplacení stejně jako faktura.
        self::assertTrue(CreditNoteRefundExpr::isRefundedAsOf('proforma', 'paid', '2099-05-20', '2099-12-31', -25000.0, null));
        self::assertFalse(CreditNoteRefundExpr::isRefundedAsOf('proforma', 'paid', '2099-05-20', '2099-12-31', 25000.0, null));
        self::assertFalse(CreditNoteRefundExpr::isRefundedAsOf('proforma', 'sent', null, '2099-12-31', -25000.0, null));
        self::assertFalse(CreditNoteRefundExpr::isRefundedAsOf('tax_document', 'paid', '2099-05-20', '2099-12-31', -608.0, null));

        $sql = CreditNoteRefundExpr::refundedAsOfSql('d');
        self::assertStringContainsString("d.invoice_type IN ('invoice', 'proforma') AND d.amount_to_pay < 0 AND d.parent_invoice_id IS NULL", $sql);
        self::assertSame(1, substr_count($sql, '?'));
    }
}
