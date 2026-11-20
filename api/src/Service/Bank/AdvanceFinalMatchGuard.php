<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceLinks;
use MyInvoice\Support\Sql\PurchaseSettledExpr;

/**
 * Platba zálohy patří na ZÁLOHU, ne na konečnou fakturu, která ji vyúčtovává.
 *
 * Spárovaná s konečnou fakturou se zaúčtuje proti saldokontu (321/221, 221/311), záloha
 * zůstane neuhrazená a zúčtování zálohy (321/314, 324/311) v zápisu faktury se nikdy
 * nezapíše. Účetní pak zálohu „uhradí evidenčně" a stav dokladů lže (viz oprava dat
 * api/bin/fix-advance-payment-on-final.php).
 *
 * Jediné místo s tím pravidlem — čte ho zápis párování ({@see PurchasePaymentMatchWriter}),
 * ruční párování vydané faktury, potvrzení návrhu i automatické párování (filtr kandidátů).
 *
 * Párování se odmítne, když konečná faktura navázaná na zálohu (přijatá:
 * advance_purchase_invoice_id; vydaná: parent proforma nebo proforma čerpaná odpočtovým
 * řádkem) a záloha:
 *   - není uhrazená (úhrada se počítá ze VŠECH kanálů — banka, pokladna, zápočty —
 *     přes SSOT {@see PurchaseSettledExpr}, resp. invoices.paid_total),
 *   - není převzatá z jiného programu (tam úhradu nese počáteční stav, ne doklad),
 * a platba buď odpovídá zbytku zálohy a NE zbytku faktury, nebo na faktuře už nic
 * k úhradě nezbývá. Doplatek faktury nad zálohu (platba = zbytek faktury) projde.
 * Jen v podvojném účetnictví a jen u korunových dokladů; vědomé obejití je `force`.
 */
final class AdvanceFinalMatchGuard
{
    public const CODE = 'advance_payment_belongs_to_advance';

    private const TOLERANCE = 1.0;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{advance_id:int, advance_type:'purchase_invoice'|'invoice', message:string}|null
     */
    public function purchaseViolation(int $supplierId, int $purchaseInvoiceId, float $paymentAmount): ?array
    {
        // Faktura s víc zálohami ({@see PurchaseAdvanceLinks}) se posuzuje proti každé z nich,
        // stejně jako vydaná faktura čerpající víc proforem.
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.id, COALESCE(NULLIF(a.vendor_invoice_number, ''), a.varsymbol) AS label,
                    a.total_with_vat AS advance_total, (" . PurchaseSettledExpr::settled('a') . ") AS advance_paid,
                    f.amount_to_pay AS final_to_pay, (" . PurchaseSettledExpr::settled('f') . ") AS final_paid,
                    COALESCE(f.tax_date, f.issue_date) AS doc_date, fc.code AS currency
               FROM purchase_invoices f
               JOIN currencies fc ON fc.id = f.currency_id
               JOIN (" . PurchaseAdvanceLinks::linksSql('?') . ") l ON l.final_id = f.id
               JOIN purchase_invoices a
                 ON a.id = l.advance_id AND a.supplier_id = f.supplier_id
                AND a.document_kind = 'advance' AND a.status <> 'cancelled'
              WHERE f.id = ? AND f.supplier_id = ? AND f.document_kind = 'invoice'
              ORDER BY a.id"
        );
        if ($stmt === false) {
            return null; // PDO bez výjimek nad zjednodušeným schématem (jednotkové testy matcheru)
        }
        $stmt->execute([$supplierId, $supplierId, $purchaseInvoiceId, $supplierId]);
        $violations = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ((new TakenOverRecord($this->db))->isDocument($supplierId, 'purchase_invoice', (int) $row['id'])) {
                continue;
            }
            $violation = $this->decide($supplierId, $row, $paymentAmount, 'purchase_invoice');
            if ($violation === null) {
                continue;
            }
            $remaining = round((float) $row['advance_total'] - (float) $row['advance_paid'], 2);
            if (abs(abs($paymentAmount) - $remaining) <= self::TOLERANCE) {
                return $violation;
            }
            $violations[] = $violation;
        }
        return $violations[0] ?? null;
    }

    /**
     * Zrcadlo vydané strany: platba proformy spárovaná s vyúčtovací fakturou. Faktura čerpající
     * víc záloh ({@see \MyInvoice\Support\Sql\AdvanceDrawSql}) se posuzuje proti každé z nich;
     * přednost má záloha, jejímuž zbytku platba odpovídá.
     *
     * @return array{advance_id:int, advance_type:'purchase_invoice'|'invoice', message:string}|null
     */
    public function issuedViolation(int $supplierId, int $invoiceId, float $paymentAmount): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.id, p.varsymbol AS label,
                    p.amount_to_pay AS advance_total, p.paid_total AS advance_paid,
                    f.amount_to_pay AS final_to_pay, f.paid_total AS final_paid,
                    COALESCE(f.tax_date, f.issue_date) AS doc_date, fc.code AS currency
               FROM invoices f
               JOIN currencies fc ON fc.id = f.currency_id
               JOIN (SELECT parent_invoice_id AS advance_id FROM invoices
                      WHERE id = ? AND parent_invoice_id IS NOT NULL
                     UNION
                     SELECT advance_invoice_id FROM invoice_items
                      WHERE invoice_id = ? AND advance_invoice_id IS NOT NULL) adv
               JOIN invoices p
                 ON p.id = adv.advance_id AND p.supplier_id = f.supplier_id
                AND p.invoice_type = 'proforma' AND p.status <> 'cancelled'
              WHERE f.id = ? AND f.supplier_id = ? AND f.invoice_type = 'invoice'
              ORDER BY p.id"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->execute([$invoiceId, $invoiceId, $invoiceId, $supplierId]);
        $violations = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ((new TakenOverRecord($this->db))->isDocument($supplierId, 'invoice', (int) $row['id'])) {
                continue;
            }
            $violation = $this->decide($supplierId, $row, $paymentAmount, 'invoice');
            if ($violation === null) {
                continue;
            }
            $remaining = round((float) $row['advance_total'] - (float) $row['advance_paid'], 2);
            if (abs(abs($paymentAmount) - $remaining) <= self::TOLERANCE) {
                return $violation;
            }
            $violations[] = $violation;
        }
        return $violations[0] ?? null;
    }

    /**
     * @throws AdvanceFinalMatchException
     */
    public function assertPurchaseAllowed(int $supplierId, int $purchaseInvoiceId, float $paymentAmount): void
    {
        $violation = $this->purchaseViolation($supplierId, $purchaseInvoiceId, $paymentAmount);
        if ($violation !== null) {
            throw new AdvanceFinalMatchException($violation['advance_id'], $violation['advance_type'], $violation['message']);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param 'purchase_invoice'|'invoice' $type
     * @return array{advance_id:int, advance_type:'purchase_invoice'|'invoice', message:string}|null
     */
    private function decide(int $supplierId, array $row, float $paymentAmount, string $type): ?array
    {
        if (strtoupper((string) $row['currency']) !== 'CZK') {
            return null; // platba v měně pohybu × doklad v cizí měně — porovnání částek by lhalo
        }
        $year = (int) substr((string) ($row['doc_date'] ?? date('Y')), 0, 4);
        if ((new AccountingModeRepository($this->db))->forYear($supplierId, $year) !== 'double_entry') {
            return null; // v daňové evidenci zálohu nezúčtovává deník, párování se neblokuje
        }
        $advanceRemaining = round((float) $row['advance_total'] - (float) $row['advance_paid'], 2);
        if ($advanceRemaining <= self::TOLERANCE) {
            return null; // záloha je uhrazená — platba na fakturu je doplatek
        }
        $finalRemaining = round((float) $row['final_to_pay'] - (float) $row['final_paid'], 2);
        $amount = abs($paymentAmount);
        $nothingLeftOnFinal = $finalRemaining <= 0.005;
        $looksLikeAdvance = abs($amount - $advanceRemaining) <= self::TOLERANCE
            && abs($amount - $finalRemaining) > self::TOLERANCE;
        if (!$nothingLeftOnFinal && !$looksLikeAdvance) {
            return null;
        }
        $label = (string) ($row['label'] ?? '') !== '' ? (string) $row['label'] : '#' . $row['id'];
        $accounts = $type === 'invoice' ? '324, faktura ji zúčtuje 324/311' : '314, faktura ji zúčtuje 321/314';
        return [
            'advance_id'   => (int) $row['id'],
            'advance_type' => $type,
            'message'      => 'Faktura vyúčtovává zálohu ' . $label . ', která zatím není uhrazená. Spárujte platbu se '
                . 'zálohou ' . $label . ' — zaúčtuje se na ' . $accounts . '. Jde-li opravdu o platbu faktury, '
                . 'potvrďte párování s fakturou.',
        ];
    }
}
