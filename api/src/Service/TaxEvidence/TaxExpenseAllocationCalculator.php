<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\VatCoefficientRepository;
use PDO;

final class TaxExpenseAllocationCalculator
{
    /** Zápočty dobropisů proti přijatým fakturám i s poli dobropisu, které potřebuje paymentTaxable(). */
    private const OFFSETS_SQL =
        "SELECT o.id AS offset_id, o.invoice_id, o.offset_on, o.amount AS offset_amount, cn.id, cn.tax_deductible,
                cn.total_vat, cn.total_with_vat, cn.vat_deduction, cn.vat_deduction_percent
           FROM credit_note_offsets o
           JOIN purchase_invoices cn ON cn.id = o.credit_note_id AND cn.supplier_id = o.supplier_id
          WHERE o.supplier_id = ? AND o.doc_type = 'purchase_invoice'";

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

    /**
     * Zápočty všech přijatých faktur firmy jedním dotazem, klíč faktura. Peněžní deník je
     * načte jednou na sestavení a předává je {@see forPurchaseInvoice()}, aby se nedotazoval
     * pro každou úhradu zvlášť.
     *
     * @return array<int, list<array<string,mixed>>>
     */
    public function purchaseOffsets(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(self::OFFSETS_SQL . ' ORDER BY o.id');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['invoice_id']][] = $row;
        }
        return $out;
    }

    /**
     * Daňová část úhrady přijaté faktury.
     *
     * @param string|null                            $offsetsAsOf     zápočty dobropisů známé k datu úhrady
     *                                                                (paymentTaxable()); null = všechny zápočty
     * @param int|null                               $throughOffsetId opravný řádek zápočtu: ze zápočtů dne
     *                                                                $offsetsAsOf jen do tohoto id
     * @param list<array<string,mixed>>|null         $offsets         zápočty faktury načtené předem
     *                                                                ({@see purchaseOffsets()}); null = dotaz
     */
    public function forPurchaseInvoice(
        int $supplierId,
        int $purchaseInvoiceId,
        float $paidAmountCzk,
        bool $isVatPayer,
        int $year,
        float $fixedAssetLimit,
        ?string $offsetsAsOf = null,
        ?int $throughOffsetId = null,
        ?array $offsets = null,
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

        return round($this->paymentTaxable(
            $supplierId, $invoice, $allocations, $paidAmountCzk, $isVatPayer, $year, $offsetsAsOf, $throughOffsetId, $offsets,
        ), 2);
    }

    /**
     * Daňová část úhrady faktury. Fakturu, kterou zčásti vyrovnal zápočet dobropisu
     * (`credit_note_offsets`), zbývá penězi uhradit jen zbytek, a ten nemá složení celé
     * faktury: dobropis z ní ubral část ve svém složení (jiná sazba, jen některé položky).
     * Zbytek je faktura minus započtené dobropisy: brutto minus částka zápočtu (táž veličina,
     * kterou od faktury odečítá noha C2 peněžního deníku), daňová část minus daňová část
     * dobropisu. Kryje-li zápočet celé brutto dobropisu, odečte se celá daňová část dobropisu,
     * jinak (dobropis s odečtenou zálohou) její poměrná část podle částky zápočtu. Daňovou
     * část každého dokladu počítá taxableAmount() i s řádkovým rozpisem a kráceným odpočtem,
     * bez ořezu a se znaménkem podle brutto dokladu: opravný dobropis s brutto −395 a DPH
     * +105 sníží daňovou část o 500, ne o 395. Vydaná strana odečítá brutto a DPH týmž
     * pravidlem ({@see \MyInvoice\Support\Sql\CreditNoteOffsetRemainderExpr}).
     *
     * Dobropis, který není daňovým výdajem (`tax_deductible = 0`), vrací nedaňovou část
     * faktury, například soukromou položku řádkového rozpisu. Daňovou část zbytku proto
     * nesnižuje, stejně jako jeho vratka penězi nesnižuje daňový výdaj (forPurchaseInvoice
     * u něj vrací nulu).
     *
     * Úhrada se pak dělí poměrem daňové části zbytku k brutto zbytku; ořízne se až výsledek,
     * jako na vydané straně v CashJournalService::prorateBase. Úhrada celého zbytku tak
     * dostane přesně daňovou část zbytku, poměr rozhoduje jen u částečné úhrady. Bez
     * zápočtu, nebo když zápočet vyrovnal celou fakturu, rozhoduje faktura sama.
     *
     * Zápočty se berou jen k datu úhrady ($asOf), pozdější zápočet dřívější úhradu nemění.
     * Rozdíl nese opravný řádek peněžního deníku ke dni zápočtu, který srovnává úhradu se
     * zápočty do daného zápočtu ($throughOffsetId) a bez něj
     * ({@see \MyInvoice\Support\Sql\CreditNoteOffsetRemainderExpr}).
     *
     * @param array<string,mixed> $invoice @param list<array<string,mixed>> $allocations
     * @param list<array<string,mixed>>|null $offsets zápočty faktury načtené předem, null = dotaz
     */
    private function paymentTaxable(
        int $supplierId,
        array $invoice,
        array $allocations,
        float $paidAmount,
        bool $isVatPayer,
        int $year,
        ?string $asOf,
        ?int $throughOffsetId,
        ?array $offsets,
    ): float {
        if ($offsets === null) {
            $stmt = $this->db->pdo()->prepare(self::OFFSETS_SQL . ' AND o.invoice_id = ? ORDER BY o.id');
            $stmt->execute([$supplierId, (int) $invoice['id']]);
            $offsets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $asOfDay = $asOf !== null ? substr($asOf, 0, 10) : null;
        $notes = array_values(array_filter($offsets, static fn (array $o): bool => $asOfDay === null
            || (string) $o['offset_on'] < $asOfDay
            || ((string) $o['offset_on'] === $asOfDay && ($throughOffsetId === null || (int) $o['offset_id'] <= $throughOffsetId))));

        $remaining = (float) $invoice['total_with_vat'];
        $taxable = $this->taxableAmount($supplierId, $invoice, $allocations, $remaining, $isVatPayer, $year, false);
        foreach ($notes as $note) {
            $offset = (float) $note['offset_amount'];
            $noteGross = abs((float) $note['total_with_vat']);
            $noteTaxable = (int) $note['tax_deductible'] === 1 ? $this->taxableAmount(
                $supplierId, $note, $this->allocations($supplierId, (int) $note['id']), $noteGross, $isVatPayer, $year, false,
            ) : 0.0;
            $remaining -= $offset;
            $taxable -= $noteGross >= 0.005 && abs($offset - $noteGross) >= 0.005
                ? $noteTaxable * $offset / $noteGross
                : $noteTaxable;
        }
        if ($notes === [] || $remaining < 0.005) {
            return $this->taxableAmount($supplierId, $invoice, $allocations, $paidAmount, $isVatPayer, $year);
        }
        return $paidAmount * max(0.0, min($remaining, $taxable)) / $remaining;
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

    /**
     * @param array<int, list<array<string,mixed>>>|null $offsetsByInvoice zápočty načtené předem
     *                                                                     ({@see purchaseOffsets()}); null = dotaz
     */
    public function forBankPayment(
        int $supplierId,
        int $bankTransactionId,
        bool $isVatPayer,
        int $year,
        float $fixedAssetLimit,
        ?array $offsetsByInvoice = null,
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
            $total += $this->forBankMatch(
                $supplierId,
                (int) $payment['purchase_invoice_id'],
                (float) $payment['amount'],
                (string) $payment['currency'],
                (string) $payment['posted_at'],
                $isVatPayer,
                $year,
                $fixedAssetLimit,
                (string) $payment['posted_at'],
                null,
                $offsetsByInvoice !== null ? ($offsetsByInvoice[(int) $payment['purchase_invoice_id']] ?? []) : null,
            );
        }
        return round($total, 2);
    }

    /**
     * Daňová část jednoho bankovního párování přijaté faktury. Částka párování je v měně
     * bankovního pohybu, na Kč se převádí kurzem ke dni pohybu.
     *
     * @param string|null                    $offsetsAsOf     viz {@see forPurchaseInvoice()}
     * @param int|null                       $throughOffsetId viz {@see forPurchaseInvoice()}
     * @param list<array<string,mixed>>|null $offsets         viz {@see forPurchaseInvoice()}
     */
    public function forBankMatch(
        int $supplierId,
        int $purchaseInvoiceId,
        float $amount,
        string $currency,
        string $postedAt,
        bool $isVatPayer,
        int $year,
        float $fixedAssetLimit,
        ?string $offsetsAsOf,
        ?int $throughOffsetId = null,
        ?array $offsets = null,
    ): float {
        $rate = $this->exchangeRate($currency !== '' ? $currency : 'CZK', $postedAt);
        return $this->forPurchaseInvoice(
            $supplierId,
            $purchaseInvoiceId,
            round($amount * $rate, 2),
            $isVatPayer,
            $year,
            $fixedAssetLimit,
            $offsetsAsOf,
            $throughOffsetId,
            $offsets,
        );
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
     * DPH se bere se znaménkem podle brutto dokladu a u úhrady se ořízne do [0, brutto].
     * Bez ořezu ($clampVat = false) slouží jako složka zbytku po zápočtu (paymentTaxable):
     * opravný doklad s opačným znaménkem DPH a brutto tam musí přispět skutečnou daňovou
     * částí a výsledek pak může mít opačné znaménko než $paidAmount; ořízne se až zbytek.
     *
     * @param array<string,mixed> $invoice @param list<array<string,mixed>> $allocations
     */
    private function taxableAmount(int $supplierId, array $invoice, array $allocations, float $paidAmount, bool $isVatPayer, int $year, bool $clampVat = true): float
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
            $vat = $sign * (float) $invoice['total_vat'];
            if ($clampVat) {
                $vat = min($gross, max(0.0, $vat));
            }
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
