<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use PDO;

/**
 * Převod agendy, kterou POHODA vede jako daňovou evidenci (typ jednotky `singleEntry`).
 *
 * Peněžní deník MyÚčto počítá z dokladů, banky a pokladny (`CashJournalService`). Převod
 * proto zapíše doklady, bankovní pohyby a pokladní doklady (stávající kroky převodu
 * podvojného účetnictví bez zaúčtování) a z exportu vezme navíc jen to, co z dokladů
 * vyčíst nejde:
 *
 *   - **úhrady** ({@see payments()}): přesná vazba pohybu na uhrazený doklad z úhrad dokladu
 *     POHODY (`liquidations`). Doklad označený zaplaceným bez vazby na pohyb by peněžní deník
 *     MyÚčta započetl jako virtuální pohyb vedle skutečného, proto se stav zaplacení dokladů
 *     počítá až z navázaných úhrad,
 *   - **úhrady minulého roku** u dokladů, které POHODA přenesla do agendy: doklad je nese
 *     jako uhrazenou část mimo peněžní deník roku,
 *   - **kontrolu** převodu ({@see reconcile()}): co z exportu chybí a které pohyby bez
 *     dokladu musí účetní zařadit v peněžním deníku.
 *
 * Pohyby bez dokladu (vklad, výběr, odvody) převod nezařazuje, předkontace daňové evidence
 * POHODY převod nečte. Firma zůstává v daňové evidenci: převod režim nemění, deník, osnovu,
 * předkontace ani účetní období nezakládá.
 */
final class PohodaTaxEvidenceImporter
{
    /** Stejný klíč kroku jako u podvojného převodu: úhrady ostatních položek hlásí {@see DocumentLinker}. */
    public const STEP_PAYMENTS = DocumentLinker::STEP_PAYMENTS;
    public const STEP_RECONCILIATION = 'tax_evidence_reconciliation';

    private const PREVIOUS_YEAR_NOTE = 'Převzato z POHODY, úhrada v minulém roce';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly MigratedPaymentWriter $payments,
        private readonly DocumentLinker $linker,
        private readonly AccountingModeRepository $modes,
        private readonly AccountingPeriodRepository $periods,
        private readonly AccountingSupplierSettingsRepository $settings,
    ) {}

    /**
     * Kontrola cílové firmy pro agendu daňové evidence: právnická osoba daňovou evidenci
     * vést nemůže, daňová evidence se vede po kalendářních letech a převod nepřimíchá
     * doklady do roku, který firma vede jako podvojné účetnictví nebo už v MyÚčtu eviduje.
     *
     * @param array{taxpayer_type:?string} $supplier
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, array $supplier, int $year): array
    {
        $out = [];
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$out): void {
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };
        if (($supplier['taxpayer_type'] ?? null) === 'po') {
            $add('error', 'tax_evidence_legal_entity', 'Agenda POHODY je vedená v daňové evidenci, firma v MyÚčtu je ale právnická osoba, '
                . 'která vede účetnictví. Daňovou evidenci převod převede jen do firmy fyzické osoby.');
        }
        if ($this->periods->fiscalCalendar($supplierId)->startMonth() !== 1) {
            $add('error', 'tax_evidence_fiscal_year', 'Daňová evidence se vede po kalendářních letech, firma má v MyÚčtu nastavený '
                . 'hospodářský rok (Firma → Nastavení, Daně a účetnictví, Účetní rok začíná). Nastavte leden a převod spusťte znovu.');
        }
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $journal = $this->db->pdo()->prepare('SELECT EXISTS (SELECT 1 FROM journal_entries WHERE supplier_id = ? AND entry_date >= ? AND entry_date <= ?)');
        $journal->execute([$supplierId, $from, $to]);
        if ($this->modes->forYear($supplierId, $year) === 'double_entry' || $this->periods->findByYear($supplierId, $year) !== null || (bool) $journal->fetchColumn()) {
            $add('error', 'target_double_entry', "Agenda POHODY je vedená v daňové evidenci, firma v MyÚčtu vede rok {$year} jako podvojné účetnictví. "
                . 'Převod režim firmy nemění, daňovou evidenci převede jen do firmy v daňové evidenci.', ['year' => $year]);
        } else {
            $add('info', 'tax_evidence_kept', 'Agenda POHODY je vedená v daňové evidenci. Firma zůstane v daňové evidenci, převod její režim nemění, deník ani osnovu nezakládá.');
        }
        foreach ($this->targetChecks($supplierId, $year) as $m) {
            $out[] = $m;
        }
        return $out;
    }

    /**
     * Rok agendy nesmí obsahovat doklady, výpisy ani pokladní doklady, které nevznikly
     * převodem z POHODY (do rozjeté evidence se převod nepřimíchává), a první převod nesmí
     * zapisovat pod zámek dokladů firmy. Opakovaný převod (doklady z mapy) projde.
     *
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    private function targetChecks(int $supplierId, int $year): array
    {
        $out = [];
        $pdo = $this->db->pdo();
        $foreign = [
            ["SELECT COUNT(*) FROM invoices d WHERE d.supplier_id = ? AND d.issue_date BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = 'invoice' AND m.target_id = d.id)", 'vydaných dokladů'],
            ["SELECT COUNT(*) FROM purchase_invoices d WHERE d.supplier_id = ? AND d.issue_date BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = 'purchase_invoice' AND m.target_id = d.id)", 'přijatých dokladů'],
            ["SELECT COUNT(*) FROM cash_documents d WHERE d.supplier_id = ? AND d.issue_date BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = 'cash_document' AND m.target_id = d.id)", 'pokladních dokladů'],
            ["SELECT COUNT(*) FROM bank_transactions d JOIN bank_statements s ON s.id = d.statement_id
                WHERE s.supplier_id = ? AND d.posted_at BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = d.id)", 'bankovních pohybů'],
        ];
        $found = [];
        foreach ($foreign as [$sql, $label]) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31 23:59:59', $year)]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $found[] = "{$count} {$label}";
            }
        }
        if ($found !== []) {
            $out[] = ['level' => 'error', 'code' => 'tax_evidence_year_not_empty', 'message' => "Rok {$year} už v MyÚčtu obsahuje " . implode(', ', $found)
                . ', které nevznikly převodem z POHODY. Daňová evidence z POHODY se do rozjeté evidence nepřimíchává.', 'context' => ['year' => $year]];
        }
        $lockedUntil = $this->settings->getLockedUntil($supplierId);
        if ($lockedUntil !== null && sprintf('%04d-01-01', $year) <= $lockedUntil
            && !$this->map->hasAny($supplierId, PohodaImportRepository::KIND_INVOICE)
            && !$this->map->hasAny($supplierId, PohodaImportRepository::KIND_BANK_TRANSACTION)) {
            $out[] = ['level' => 'error', 'code' => 'tax_evidence_locked', 'message' => 'Doklady firmy jsou v MyÚčtu uzamčené do ' . date('j. n. Y', (int) strtotime($lockedUntil))
                . ", převod by zapisoval do uzamčeného období (rok {$year}). Zámek zrušte a převod spusťte znovu.", 'context' => ['locked_until' => $lockedUntil]];
        }
        return $out;
    }

    /**
     * Úhrady faktur z úhrad dokladu v POHODĚ: úhrada bankovním nebo pokladním dokladem roku
     * agendy se naváže na převzatý pohyb ({@see MigratedPaymentWriter::attach()}), stav
     * zaplacení dokladů se pak spočte z navázaných úhrad. Úhrada z minulého roku je u dokladu
     * přeneseného do agendy uhrazenou částí mimo peněžní deník. Úhrada bez pohybu (zápočet,
     * interní doklad) se nenaváže a doklad zůstane neuhrazený s upozorněním. Úhrady ostatních
     * pohledávek, závazků a záloh na daň přiřadí {@see DocumentLinker::matchOtherItemPayments()}.
     * Opakovaný převod nic nezdvojí.
     */
    public function payments(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $start = $ctx->periodStart();
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT);
        $documents = ['invoice' => [], 'purchase_invoice' => []];
        $previous = ['invoice' => [], 'purchase_invoice' => []];
        $statements = [];
        $withoutMovement = [];
        foreach ($ctx->liquidations as $l) {
            if (!isset($documents[$l['doc']])) {
                continue;
            }
            $id = (int) $l['id'];
            if ($ctx->skipsDate($l['date'])) {
                $p->count(self::STEP_PAYMENTS, 'later_year_skipped');
                continue;
            }
            if ($l['date'] !== null && $l['date'] < $start) {
                $previous[$l['doc']][$id] = round(($previous[$l['doc']][$id] ?? 0.0) + abs((float) $l['amount']), 2);
                $documents[$l['doc']][$id] = $id;
                $p->count(self::STEP_PAYMENTS, 'previous_period');
                continue;
            }
            $mapKey = $l['doc'] . '|' . $id . '|' . $l['liq'];
            $documents[$l['doc']][$id] = $id;
            if (isset($existing[$mapKey])) {
                $p->count(self::STEP_PAYMENTS, 'existing');
                continue;
            }
            $movementKind = match ($l['agenda']) { 'bank' => 'bank', 'voucher' => 'cash', default => null };
            if ($movementKind === null) {
                $withoutMovement[$l['number']] = true;
                $p->count(self::STEP_PAYMENTS, $l['agenda'] !== '' ? 'other_' . $l['agenda'] : 'without_document');
                continue;
            }
            if ($movementKind === 'bank') {
                $movementId = $this->linker->bankTransactionFor($ctx, $l);
            } else {
                $movementId = $ctx->cashDocuments[$l['source']] ?? null;
                if ($movementId === null) {
                    $p->count(self::STEP_PAYMENTS, 'not_found');
                    $p->warn(self::STEP_PAYMENTS, 'payment_not_found', "Úhrada {$l['source']} dokladu {$l['number']} v převedené pokladně není.", ['document_no' => $l['number']]);
                }
            }
            $amount = round(abs((float) $l['amount']), 2);
            if ($movementId === null || $amount < 0.005) {
                continue;
            }
            try {
                $linkId = $this->payments->attach($ctx->supplierId, $ctx->userOrNull(), $l['doc'] === 'invoice' ? 'issued' : 'purchase',
                    $movementKind, $id, (int) $movementId, $amount);
            } catch (\InvalidArgumentException $e) {
                $p->count(self::STEP_PAYMENTS, 'not_linked');
                $p->warn(self::STEP_PAYMENTS, 'payment_not_linked', sprintf(
                    'Úhradu %s dokladu %s převod nenavázal (%s), doklad zůstává neuhrazený. Navažte ji ručně.',
                    $l['source'], $l['number'], match ($e->getMessage()) {
                        'cash_payment_ambiguous' => 'pokladní doklad hradí víc dokladů nebo jinou částku',
                        'payment_currency_unverified' => 'doklad nebo pohyb v cizí měně',
                        default => 'doklad nebo pohyb chybí',
                    },
                ), ['document_no' => $l['number']]);
                continue;
            }
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, $linkId, $ctx->runId);
            if ($movementKind === 'bank') {
                $statementId = $this->payments->statementId($ctx->supplierId, (int) $movementId);
                if ($statementId !== null) {
                    $statements[$statementId] = $statementId;
                }
            }
            $p->count(self::STEP_PAYMENTS, $movementKind);
        }
        $this->previousYearPayments($ctx, $previous);
        $issued = array_values($documents['invoice']);
        $purchases = array_values($documents['purchase_invoice']);
        $this->payments->refreshBalances($ctx->supplierId, $issued, $purchases, array_values($statements));
        $this->settleRemainders($ctx, $issued, $purchases);
        $this->linker->matchOtherItemPayments($ctx);
        if ($withoutMovement !== []) {
            $p->warn(self::STEP_PAYMENTS, 'payment_without_movement', count($withoutMovement) . ' dokladů POHODA vyrovnala bez bankovního nebo pokladního dokladu (zápočet, interní doklad). '
                . 'Peněžní deník je bez pohybu nezapočte, doklady zůstávají neuhrazené: vyrovnání doplňte ručně (' . implode(', ', array_slice(array_keys($withoutMovement), 0, 20)) . ').',
                ['documents' => array_slice(array_keys($withoutMovement), 0, 50)]);
        }
        $p->finish(self::STEP_PAYMENTS);
    }

    /**
     * Úhrady dokladů přenesených z minulého roku, které padly do minulého roku: pohyb je
     * v agendě minulého roku, ne v tomto exportu. Vydaný doklad je dostane jako evidovanou
     * platbu se zdrojem `settlement` (peněžní deník ji nepočítá), přijatý jako vyrovnání mimo
     * párované úhrady (`settled_outside_amount`). Jen doklady v korunách; nastavuje se
     * absolutně, opakovaný převod nic nezdvojí.
     *
     * @param array{invoice:array<int,float>,purchase_invoice:array<int,float>} $previous
     */
    private function previousYearPayments(PohodaContext $ctx, array $previous): void
    {
        $pdo = $this->db->pdo();
        $issued = $pdo->prepare("SELECT i.id, ABS(i.total_with_vat) - i.advance_paid_amount AS to_pay,
                (SELECT ip.id FROM invoice_payments ip WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id AND ip.source = 'settlement' AND ip.note = ? LIMIT 1) AS payment_id
              FROM invoices i JOIN currencies c ON c.id = i.currency_id AND c.supplier_id = i.supplier_id
             WHERE i.id = ? AND i.supplier_id = ? AND c.code = 'CZK' AND i.status NOT IN ('draft', 'cancelled')");
        $insert = $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
            VALUES (?, ?, ?, ?, 'CZK', ?, 'settlement', ?)");
        $update = $pdo->prepare('UPDATE invoice_payments SET amount = ? WHERE id = ? AND supplier_id = ?');
        $lastDay = date('Y-m-d', (int) strtotime($ctx->periodStart() . ' -1 day'));
        foreach ($previous['invoice'] as $id => $amount) {
            $issued->execute([self::PREVIOUS_YEAR_NOTE, $id, $ctx->supplierId]);
            $row = $issued->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                continue;
            }
            $amount = number_format(min($amount, max(0.0, (float) $row['to_pay'])), 2, '.', '');
            if ($row['payment_id'] !== null) {
                $update->execute([$amount, (int) $row['payment_id'], $ctx->supplierId]);
                continue;
            }
            if ((float) $amount >= 0.01) {
                $insert->execute([$ctx->supplierId, $id, $lastDay, $amount, self::PREVIOUS_YEAR_NOTE, $ctx->userOrNull()]);
                $ctx->protocol->count(self::STEP_PAYMENTS, 'previous_period_paid');
            }
        }
        $purchase = $pdo->prepare("UPDATE purchase_invoices pi JOIN currencies c ON c.id = pi.currency_id AND c.supplier_id = pi.supplier_id
               SET pi.settled_outside_amount = LEAST(GREATEST(pi.amount_to_pay, 0), ?)
             WHERE pi.id = ? AND pi.supplier_id = ? AND c.code = 'CZK' AND pi.status NOT IN ('draft', 'cancelled')");
        foreach ($previous['purchase_invoice'] as $id => $amount) {
            $purchase->execute([number_format($amount, 2, '.', ''), $id, $ctx->supplierId]);
            if ($purchase->rowCount() > 0) {
                $ctx->protocol->count(self::STEP_PAYMENTS, 'previous_period_paid');
            }
        }
    }

    /**
     * Doklad uhrazený navázanými úhradami spolu s odečtenou zálohou nebo úhradou z minulého
     * roku: {@see MigratedPaymentWriter::refreshBalances()} je neporovnává. Uhrazený je jen
     * doklad, který má aspoň jednu navázanou úhradu z banky nebo pokladny - datum úhrady
     * přijatého dokladu bez ní by peněžní deník započetl jako virtuální výdaj.
     *
     * @param list<int> $issued
     * @param list<int> $purchases
     */
    private function settleRemainders(PohodaContext $ctx, array $issued, array $purchases): void
    {
        $pdo = $this->db->pdo();
        $s = $ctx->supplierId;
        foreach (array_chunk($issued, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $pdo->prepare("UPDATE invoices i
                   SET i.status = 'paid',
                       i.paid_at = (SELECT MAX(ip.paid_on) FROM invoice_payments ip WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id AND ip.source IN ('bank', 'cash'))
                 WHERE i.supplier_id = ? AND i.id IN ({$in}) AND i.paid_at IS NULL AND i.status NOT IN ('draft', 'cancelled')
                   AND i.paid_total + i.advance_paid_amount >= ABS(i.total_with_vat) - 0.01
                   AND EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id AND ip.source IN ('bank', 'cash'))")
                ->execute([$s]);
        }
        foreach (array_chunk($purchases, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $pdo->prepare("UPDATE purchase_invoices pi
                   SET pi.status = 'paid',
                       pi.paid_at = NULLIF(GREATEST(
                           COALESCE((SELECT MAX(bt.posted_at) FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                                      WHERE pm.supplier_id = pi.supplier_id AND pm.purchase_invoice_id = pi.id), '1000-01-01'),
                           COALESCE((SELECT MAX(cd.issue_date) FROM cash_documents cd
                                      WHERE cd.supplier_id = pi.supplier_id AND cd.purchase_invoice_id = pi.id AND cd.status = 'posted'), '1000-01-01')
                       ), '1000-01-01')
                 WHERE pi.supplier_id = ? AND pi.id IN ({$in}) AND pi.paid_at IS NULL AND pi.status NOT IN ('draft', 'cancelled')
                   AND pi.paid_amount_invoice_ccy > 0
                   AND pi.paid_amount_invoice_ccy + pi.settled_outside_amount >= ABS(pi.amount_to_pay) - 0.01")
                ->execute([$s]);
        }
    }

    /**
     * Kontrola převodu daňové evidence: pohyby banky a pokladny z exportu proti převzatým
     * a pohyby bez dokladu, úhrady ani zařazení. Ty peněžní deník nezapočte, dokud je účetní
     * nezařadí (vklad, výběr, odvody, poplatky): převod je nezařazuje, předkontace daňové
     * evidence POHODY nečte. Rok agendy se zapamatuje pro vypořádání koeficientu § 76.
     */
    public function reconcile(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $s = $ctx->supplierId;
        $pdo = $this->db->pdo();
        $bank = 0;
        foreach ($ctx->bankTransactions as $txs) {
            $bank += count($txs);
        }
        $p->setCount(self::STEP_RECONCILIATION, 'bank_transactions', $bank);
        $p->setCount(self::STEP_RECONCILIATION, 'cash_documents', count($ctx->cashDocuments));
        $p->setCount(self::STEP_RECONCILIATION, 'issued_documents', count($ctx->issuedInvoices) + count($ctx->receivables) + count($ctx->internalSales));
        $p->setCount(self::STEP_RECONCILIATION, 'purchase_documents', count($ctx->purchaseInvoices) + count($ctx->commitments) + count($ctx->internalPurchases));

        $ids = [];
        foreach ($ctx->bankTransactions as $txs) {
            foreach ($txs as $tx) {
                $ids[] = (int) $tx['id'];
            }
        }
        $unclassified = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            $unclassified += (int) $pdo->query(
                "SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = {$s}
                  WHERE bt.id IN ({$in})
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.supplier_id = {$s} AND pm.bank_transaction_id = bt.id)
                    AND NOT EXISTS (SELECT 1 FROM other_item_allocations a WHERE a.supplier_id = {$s} AND a.bank_transaction_id = bt.id AND a.reversed_on IS NULL)
                    AND NOT EXISTS (SELECT 1 FROM tax_advance_schedules t WHERE t.supplier_id = {$s} AND t.matched_transaction_id = bt.id)
                    AND NOT EXISTS (SELECT 1 FROM de_movement_classification c WHERE c.supplier_id = {$s} AND c.source_type = 'bank' AND c.bank_transaction_id = bt.id)"
            )->fetchColumn();
        }
        $p->setCount(self::STEP_RECONCILIATION, 'bank_unclassified', $unclassified);
        if ($unclassified > 0) {
            $p->warn(self::STEP_RECONCILIATION, 'bank_unclassified', sprintf(
                '%d bankovních pohybů nemá doklad ani úhradu (vklad, výběr, poplatky, odvody). Převod je nezařazuje: zařaďte je v Daňová evidence → Peněžní deník, jinak je peněžní deník nezapočte.',
                $unclassified,
            ), ['transactions' => $unclassified]);
        }
        if ($this->map->get($s, PohodaImportRepository::KIND_TAX_EVIDENCE_YEAR, (string) $ctx->year()) === null) {
            $this->map->put($s, PohodaImportRepository::KIND_TAX_EVIDENCE_YEAR, (string) $ctx->year(), $ctx->year(), $ctx->runId);
        }
        $p->info(self::STEP_RECONCILIATION, 'tax_evidence_summary', sprintf(
            'Daňová evidence %d: vydané doklady %d, přijaté doklady %d, bankovní pohyby %d, pokladní doklady %d. Firma zůstala v daňové evidenci; peněžní deník sestaví MyÚčto z dokladů, banky a pokladny.',
            $ctx->year(), count($ctx->issuedInvoices) + count($ctx->receivables) + count($ctx->internalSales),
            count($ctx->purchaseInvoices) + count($ctx->commitments) + count($ctx->internalPurchases), $bank, count($ctx->cashDocuments),
        ));
        $p->finish(self::STEP_RECONCILIATION);
    }

    /** @return list<int> roky daňové evidence převedené z POHODY (i dřívějšími běhy) */
    public function convertedYears(int $supplierId): array
    {
        return array_map('intval', array_keys($this->map->all($supplierId, PohodaImportRepository::KIND_TAX_EVIDENCE_YEAR)));
    }
}
