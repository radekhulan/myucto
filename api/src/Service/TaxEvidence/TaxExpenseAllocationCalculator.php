<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\VatCoefficientRepository;
use PDO;

final class TaxExpenseAllocationCalculator
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatCoefficientRepository $coefficients,
    ) {}

    public function assertAnnualCoefficientReady(int $supplierId, int $year): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT EXISTS(
                SELECT 1 FROM purchase_invoices pi
                 WHERE pi.supplier_id = ? AND pi.status <> 'cancelled'
                   AND YEAR(COALESCE(pi.paid_at, pi.tax_date, pi.issue_date)) = ?
                   AND (pi.vat_deduction = 'reduced' OR EXISTS (
                       SELECT 1 FROM purchase_invoice_vat_allocations a
                        WHERE a.purchase_invoice_id = pi.id AND a.supplier_id = pi.supplier_id
                          AND a.vat_deduction = 'reduced'
                   ))
            )"
        );
        $stmt->execute([$supplierId, $year]);
        if (!(bool) $stmt->fetchColumn()) {
            return;
        }
        $coefficient = $this->coefficients->get($supplierId, $year);
        if ($coefficient === null || $coefficient['final_percent'] === null || $coefficient['settled_at'] === null) {
            throw new \RuntimeException('Roční uzávěrka vyžaduje vypořádací koeficient DPH pro krácené odpočty.');
        }
    }

    public function forPurchaseInvoice(
        int $supplierId,
        int $purchaseInvoiceId,
        float $paidAmountCzk,
        bool $isVatPayer,
        int $year,
        float $fixedAssetLimit,
    ): float {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, document_kind, tax_deductible, is_fixed_asset, parent_purchase_invoice_id,
                    total_without_vat, total_vat, total_with_vat, vat_deduction, vat_deduction_percent
               FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false) {
            throw new \RuntimeException('Přijatá faktura k úhradě nebyla nalezena.');
        }
        if ((string) $invoice['status'] === 'cancelled' || (int) $invoice['tax_deductible'] !== 1) {
            return 0.0;
        }
        // DDKP (daňový doklad k poskytnuté záloze) NENÍ daňový výdaj: peníze odešly už na
        // zálohové faktuře a v kasové bázi § 7b se výdaj uplatnil tam. DDKP jen dokládá
        // nárok na odpočet DPH — bez tohoto filtru by vygeneroval DRUHÝ daňový výdaj
        // v plné výši. `document_kind` se tu SELECTuje od začátku, jen se nikdy
        // nevyhodnocoval; bankovní noha peněžního deníku týž filtr má
        // (CashJournalRepository:239-240).
        //
        // POZOR — `advance` se zde NEVYLUČUJE, na rozdíl od podvojného účetnictví.
        // V daňové evidenci je zaplacená záloha výdajem v okamžiku úhrady (kasová báze),
        // proto ji sem pouštíme; hlídá to CashJournalScenariosTest.
        if ((string) $invoice['document_kind'] === 'tax_document') {
            return 0.0;
        }

        $allocations = $this->allocations($supplierId, $purchaseInvoiceId);
        if ($this->isDepreciatedAsset($supplierId, $invoice, $allocations, $isVatPayer, $year, $fixedAssetLimit)) {
            return 0.0;
        }

        return round($this->taxableAmount($supplierId, $invoice, $allocations, $paidAmountCzk, $isVatPayer, $year), 2);
    }

    /**
     * Majetek nad limitem se do výdajů dostává odpisy, ne úhradou. Dobropis k němu mění
     * vstupní cenu, ne peněžní výdaj, proto se u navázaného dobropisu posuzuje původní
     * faktura; nenavázaný dobropis podle vlastní (absolutní) částky.
     *
     * @param array<string,mixed> $invoice @param list<array<string,mixed>> $allocations
     */
    private function isDepreciatedAsset(int $supplierId, array $invoice, array $allocations, bool $isVatPayer, int $year, float $fixedAssetLimit): bool
    {
        $isCreditNote = (float) $invoice['total_with_vat'] < 0.0
            || \MyInvoice\Service\Report\VatLedgerService::purchaseDocumentSign(
                isset($invoice['document_kind']) ? (string) $invoice['document_kind'] : null,
                (float) $invoice['total_with_vat'],
            ) < 0.0;
        if ($isCreditNote && ($invoice['parent_purchase_invoice_id'] ?? null) !== null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, is_fixed_asset, total_vat, total_with_vat, vat_deduction, vat_deduction_percent
                   FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute([(int) $invoice['parent_purchase_invoice_id'], $supplierId]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($parent !== false) {
                $invoice = $parent;
                $allocations = $this->allocations($supplierId, (int) $parent['id']);
            }
        }
        if ((int) $invoice['is_fixed_asset'] !== 1) {
            return false;
        }
        $entryPrice = $this->taxableAmount($supplierId, $invoice, $allocations, (float) $invoice['total_with_vat'], $isVatPayer, $year);
        return abs($entryPrice) > $fixedAssetLimit;
    }

    public function forBankPayment(
        int $supplierId,
        int $bankTransactionId,
        bool $isVatPayer,
        int $year,
        float $fixedAssetLimit,
    ): float {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pm.purchase_invoice_id, pm.amount, bt.currency, bt.posted_at
               FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
              WHERE pm.supplier_id = ? AND pm.bank_transaction_id = ? AND pm.purchase_invoice_id IS NOT NULL'
        );
        $stmt->execute([$supplierId, $bankTransactionId]);
        $total = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $rate = $this->exchangeRate((string) ($payment['currency'] ?: 'CZK'), (string) $payment['posted_at']);
            $total += $this->forPurchaseInvoice(
                $supplierId,
                (int) $payment['purchase_invoice_id'],
                round((float) $payment['amount'] * $rate, 2),
                $isVatPayer,
                $year,
                $fixedAssetLimit,
            );
        }
        return round($total, 2);
    }

    public function forCashDocument(int $supplierId, int $cashDocumentId, bool $isVatPayer, int $year): float
    {
        // total_amount i cash_document_vat_lines jsou v DB už v CZK (valutový doklad
        // přepočítá CashDocumentService::resolveCurrency() kurzem před uložením, migrace
        // 1114) — kurzem se tu proto NENÁSOBÍ, jinak vznikne dvojí přepočet.
        $stmt = $this->db->pdo()->prepare(
            'SELECT total_amount FROM cash_documents WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$cashDocumentId, $supplierId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($document === false) {
            throw new \RuntimeException('Pokladní doklad nebyl nalezen.');
        }
        $amount = round((float) $document['total_amount'], 2);
        if (!$isVatPayer) {
            return $amount;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT base_amount, vat_amount, vat_deduction, vat_deduction_percent, tax_treatment
               FROM cash_document_vat_lines WHERE cash_document_id = ? ORDER BY id'
        );
        $stmt->execute([$cashDocumentId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($lines === []) {
            return $amount;
        }
        $taxable = 0.0;
        foreach ($lines as $line) {
            if ((string) $line['tax_treatment'] !== 'deductible') {
                continue;
            }
            $taxable += (float) $line['base_amount'] + (float) $line['vat_amount'] * (1 - $this->deductionRatio(
                $supplierId,
                (string) $line['vat_deduction'],
                (float) $line['vat_deduction_percent'],
                $year,
            ));
        }
        return round(min($amount, max(0.0, $taxable)), 2);
    }

    /** @return list<array<string,mixed>> */
    private function allocations(int $supplierId, int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT base_amount, vat_amount, total_amount, vat_deduction,
                    vat_deduction_percent, tax_treatment
               FROM purchase_invoice_vat_allocations
              WHERE supplier_id = ? AND purchase_invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Daňová část uhrazené částky. Počítá se poměrem daňové části k brutto dokladu, a ten
     * je u dobropisu (záporné brutto i DPH) stejný jako u faktury. Výsledek má znaménko
     * $paidAmount; směr peněz (vratka snižuje výdaj) určuje volající podle směru pohybu.
     * Dřív záporné brutto vracelo nulu, takže vratka k přijatému dobropisu daňový výdaj
     * nesnížila. Zrcadlo CashJournalService::prorateBase na příjmové straně.
     *
     * @param array<string,mixed> $invoice @param list<array<string,mixed>> $allocations
     */
    private function taxableAmount(int $supplierId, array $invoice, array $allocations, float $paidAmount, bool $isVatPayer, int $year): float
    {
        $signedGross = (float) $invoice['total_with_vat'];
        $sign = $signedGross < 0.0 ? -1.0 : 1.0;
        $gross = abs($signedGross);
        if ($gross < 0.005 || $paidAmount == 0.0) {
            return 0.0;
        }
        if ($allocations === []) {
            if (!$isVatPayer) {
                return $paidAmount;
            }
            // The paid gross can include document rounding. Only the stored VAT
            // is deductible; gross - net would wrongly treat rounding as VAT.
            $vat = min($gross, max(0.0, $sign * (float) $invoice['total_vat']));
            $deductible = $gross - $vat * $this->deductionRatio(
                $supplierId,
                (string) $invoice['vat_deduction'],
                (float) $invoice['vat_deduction_percent'],
                $year,
            );
            return $paidAmount * ($deductible / $gross);
        }

        $allocationGross = array_sum(array_map(static fn (array $a): float => (float) $a['total_amount'], $allocations));
        if (abs($allocationGross) < 0.005) {
            throw new \RuntimeException('Řádkové daňové alokace přijaté faktury mají nulový součet.');
        }
        $ratio = $paidAmount / $allocationGross;
        $taxable = 0.0;
        foreach ($allocations as $allocation) {
            if ((string) $allocation['tax_treatment'] !== 'deductible') {
                continue;
            }
            if (!$isVatPayer) {
                $taxable += (float) $allocation['total_amount'] * $ratio;
                continue;
            }
            $taxable += ((float) $allocation['base_amount']
                + (float) $allocation['vat_amount'] * (1 - $this->deductionRatio(
                    $supplierId,
                    (string) $allocation['vat_deduction'],
                    (float) $allocation['vat_deduction_percent'],
                    $year,
                ))) * $ratio;
        }
        return $taxable;
    }

    private function deductionRatio(int $supplierId, string $mode, float $percent, int $year): float
    {
        if ($mode === 'full') {
            return 1.0;
        }
        if ($mode === 'none') {
            return 0.0;
        }
        if ($mode === 'reduced') {
            $coefficient = $this->coefficients->get($supplierId, $year);
            $resolved = $coefficient['final_percent'] ?? $this->coefficients->resolveProvisionalPercent($supplierId, $year);
            if ($resolved === null) {
                throw new \RuntimeException('Pro krácený odpočet chybí roční koeficient DPH.');
            }
            return max(0, min(100, (int) $resolved)) / 100;
        }
        return max(0.0, min(100.0, $percent)) / 100;
    }

    private function exchangeRate(string $currency, string $date): float
    {
        if ($currency === 'CZK') {
            return 1.0;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT rate FROM exchange_rates WHERE currency_code = ? AND rate_date <= ? ORDER BY rate_date DESC LIMIT 1'
        );
        $stmt->execute([$currency, $date]);
        $rate = $stmt->fetchColumn();
        if ($rate === false || (float) $rate <= 0.0) {
            throw new \RuntimeException('Pro cizoměnovou bankovní úhradu chybí kurz ke dni pohybu.');
        }
        return (float) $rate;
    }
}
