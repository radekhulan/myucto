<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\JournalEntryLinker;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;
use PDO;

/**
 * Provázání převedených dokladů s převedeným deníkem a úhrad s doklady.
 *
 * Zaúčtování se nepřepočítává: zápis z Money dostane `source_id` = id dokladu a vazbu
 * v `journal_entry_document_links`. Na klíči (typ, id dokladu) stojí v MyÚčtu všechno,
 * co se ptá „je doklad zaúčtovaný" — úhrada na detailu faktury, saldokonto, kontroly
 * uzávěrky i automatika. **U bankovního zápisu je `source_id` id pohybu**; bez něj
 * detail faktury hlásí „úhrada NENÍ zaúčtovaná" a automatika by pohyb zaúčtovala znovu.
 *
 * Páruje se podle čísla dokladu Money, které nese doklad i řádek deníku — ne podle
 * částky a data, kde by dvě stejné platby téhož dne splynuly.
 */
final class DocumentLinker
{
    public const STEP_LINK = 'link';
    public const STEP_PAYMENTS = 'payments';

    private readonly JournalEntryLinker $entries;

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
    ) {
        $this->entries = new JournalEntryLinker($db, 'Money S3');
    }

    public function link(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        // Index deníku jen roku, jehož doklady se právě navazují (doklady jdou po letech).
        $index = [];
        $indexYear = null;
        // Koncept k ruční kontrole (zálohová faktura, doklad nejisté daňové povahy)
        // v deníku Money zápis mít nemusí a zaúčtuje se až po potvrzení účetní.
        $drafts = [
            'purchase_invoice' => $this->draftIds('purchase_invoices', $ctx->supplierId),
            'invoice' => $this->draftIds('invoices', $ctx->supplierId),
        ];
        $orphans = [];
        $evidence = null;
        $evidential = [];
        // Mapa převodu může ukazovat na záznam smazaný (zápis stornovaný) v MyÚčtu.
        $live = $this->liveRecords($ctx->supplierId);
        // Ostatní pohledávky vede Money v deníku pod zdrojem KP (převod ho čte jako ruční
        // zápis); navázaný zápis se přeznačí na zápis vydaného dokladu — jen tak ho kontrola
        // 343 i Doúčtování dokladů poznají jako zaúčtovaný doklad.
        foreach ([
            ['purchase_invoice', 'purchase_invoice', $ctx->purchaseInvoices, null],
            ['invoice', 'invoice', $ctx->issuedInvoices, null],
            ['cash', 'cash', $ctx->cashDocuments, null],
            ['bank', 'bank', $ctx->bankTransactions, null],
            ['manual', 'invoice', $ctx->otherReceivables, 'invoice'],
        ] as [$sourceType, $docType, $docs, $retype]) {
            foreach ($docs as $key => $docId) {
                [$year, $docNo] = explode('|', (string) $key, 3);
                if ($indexYear !== (int) $year) {
                    $indexYear = (int) $year;
                    $index = $this->journalIndex($ctx->supplierId, $indexYear);
                }
                $entries = $index[$sourceType][$docNo] ?? [];
                if (count($entries) > 1 && in_array($docType, ['bank', 'cash'], true)) {
                    // Stejné číslo dokladu na dvou účtech (pokladnách): zápis se pozná podle data.
                    $date = $this->documentDate($ctx->supplierId, $docType, $docId);
                    $sameDay = array_values(array_filter($entries, static fn (array $e): bool => $e['date'] === $date));
                    $entries = $sameDay !== [] ? $sameDay : $entries;
                }
                if (!isset($live[$docType][$docId])) {
                    $p->count(self::STEP_LINK, 'missing_target');
                    continue;
                }
                $entries = array_values(array_filter(array_column($entries, 'id'), static fn (int $id): bool => isset($live['entry'][$id])));
                if ($entries === []) {
                    if (isset($drafts[$docType][$docId])) {
                        $p->count(self::STEP_LINK, 'review_unlinked');
                        continue;
                    }
                    if ($retype === null && in_array($docType, ['invoice', 'purchase_invoice'], true)) {
                        $evidence ??= $this->evidencePostings($ctx);
                        $neutral = $evidence['neutral'][$year . '|' . ($docType === 'invoice' ? 'FV' : 'FP') . '|' . $docNo] ?? null;
                        if ($neutral !== null) {
                            $evidential[] = ['type' => $docType, 'year' => (int) $year, 'document_no' => $docNo, 'id' => $docId] + $neutral;
                            continue;
                        }
                    }
                    if (count($orphans) < ImportProtocol::LIST_LIMIT) {
                        $orphans[] = ['type' => $docType, 'year' => (int) $year, 'document_no' => $docNo, 'id' => $docId];
                    }
                    $p->count(self::STEP_LINK, 'orphans');
                    continue;
                }
                $new = $this->entries->attach($ctx->supplierId, $ctx->userId > 0 ? $ctx->userId : null, $retype ?? $sourceType, $sourceType, $docType, $docId, $entries);
                $p->count(self::STEP_LINK, $new ? 'linked' : 'existing');
            }
        }
        if ($evidential !== []) {
            $this->linkEvidential($ctx, $evidential, $evidence ?? [], $live);
        }
        $p->set('orphans', $orphans);
        if ($orphans !== []) {
            $p->warn(self::STEP_LINK, 'orphan_documents', count($orphans) . ' dokladů nemá v deníku Money zápis se stejným číslem. '
                . 'Nejsou zaúčtované; zaúčtujte je ručně nebo v Účetnictví → Doúčtovat doklady.');
        }
        $this->entries->refreshDescriptions($ctx->supplierId, $p, self::STEP_LINK);
        $p->finish(self::STEP_LINK);
    }

    /**
     * Úhrady: Money u faktury drží číslo dokladu, kterým byla uhrazena (`UDoklad`). Číslo
     * se ale v Money každý rok opakuje, takže z pohybů se stejným číslem rozhoduje
     * částka a datum úhrady ({@see choosePayment()}), ne jen rok.
     */
    public function matchPayments(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $txRow = $pdo->prepare(
            'SELECT t.amount, DATE(t.posted_at) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE t.id = ? AND s.supplier_id = ?'
        );
        $cashRow = $pdo->prepare('SELECT total_amount, issue_date FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $bankByDoc = [];
        foreach ($ctx->bankTransactions as $key => $id) {
            [$year, $docNo] = explode('|', (string) $key, 3);
            $candidate = self::candidate($txRow, $id, (int) $year, $ctx->supplierId);
            // Pohyb eurového účtu je v eurech, faktury z Money v Kč — porovnává se částka v Kč.
            if (isset($ctx->bankTransactionCzk[$id])) {
                $candidate['amount'] = $ctx->bankTransactionCzk[$id];
            }
            $bankByDoc[$docNo][] = $candidate;
        }
        $cashByDoc = [];
        foreach ($ctx->cashDocuments as $key => $id) {
            [$year, $docNo] = explode('|', (string) $key, 3);
            $cashByDoc[$docNo][] = self::candidate($cashRow, $id, (int) $year, $ctx->supplierId);
        }
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT);
        // Doklad převedený dřív jako neuhrazený: spárováním se stane uhrazeným. Koncept
        // k ruční kontrole zůstává konceptem, jeho stav určí účetní. Převod zakládá jen
        // běžné faktury (document_kind = 'invoice'), DDKP se sem dostat nemůže.
        $markPurchasePaid = $pdo->prepare(
            "UPDATE purchase_invoices SET status = 'paid', paid_at = COALESCE(paid_at, ?)
              WHERE id = ? AND supplier_id = ? AND document_kind = 'invoice' AND status IN ('received', 'booked')"
        );
        $markIssuedPaid = $pdo->prepare(
            "UPDATE invoices SET status = 'paid', paid_at = COALESCE(paid_at, ?), paid_total = total_with_vat
              WHERE id = ? AND supplier_id = ? AND status IN ('issued', 'sent', 'reminded')"
        );
        $insertMatch = $pdo->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
             VALUES (?, ?, ?, ?, ?, "manual", ?)'
        );
        // Pohyb ignorovaný uživatelem ani párování pohybu s jiným dokladem se nepřepisuje.
        $markTx = $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.ignore_note = IF(t.match_status = 'ignored', NULL, t.ignore_note),
                    t.match_reason = IF(t.match_status = 'ignored', NULL, t.match_reason),
                    t.match_status = 'manual', t.matched_at = COALESCE(t.matched_at, NOW()), t.matched_by = COALESCE(t.matched_by, ?),
                    t.matched_invoice_id = COALESCE(t.matched_invoice_id, ?)
              WHERE t.id = ? AND s.supplier_id = ?
                AND (t.match_status <> 'ignored' OR t.match_reason = 'money_s3_booked')"
        );
        $cashLink = $pdo->prepare('SELECT invoice_id, purchase_invoice_id FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $cashMulti = [];
        $movements = $this->bankMovements($ctx->supplierId);
        $issuedPayments = new IssuedPaymentRecorder($this->db);
        $matchRow = $pdo->prepare('SELECT bank_transaction_id, invoice_id, purchase_invoice_id FROM payment_matches WHERE id = ? AND supplier_id = ?');
        $sameMatch = $pdo->prepare('SELECT MIN(id) FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ? AND (invoice_id = ? OR purchase_invoice_id = ?)');
        $relink = $ctx->options->relinkOnly;
        $docState = $this->documentState($ctx->supplierId);

        foreach ([['PFaktury', $ctx->purchaseInvoices, false], ['VFaktury', $ctx->issuedInvoices, true]] as [$table, $docs, $isIssued]) {
            $type = $isIssued ? 'invoice' : 'purchase_invoice';
            foreach ($ctx->backup->rowsAcrossYears($table) as $r) {
                $year = $ctx->yearOf($r);
                $docNo = trim((string) ($r['Doklad'] ?? ''));
                $settledBy = trim((string) ($r['UDoklad'] ?? ''));
                $docId = $docs[$year . '|' . $docNo] ?? null;
                if ($year === null || $docId === null || $settledBy === '') {
                    continue;
                }
                if (!isset($docState['exists'][$type][$docId])) {
                    // Doklad z mapy převodu v MyÚčtu mezitím smazali.
                    $p->count(self::STEP_PAYMENTS, 'missing_target');
                    continue;
                }
                $mapKey = ($isIssued ? 'v' : 'p') . '|' . $year . '|' . $docNo;
                $bankCandidates = $bankByDoc[$settledBy] ?? [];
                $cashCandidates = $cashByDoc[$settledBy] ?? [];
                if (isset($existing[$mapKey])) {
                    // Dřívější běh úhradu spároval, ale evidovanou platbu vydané faktury nezaložil.
                    // Cíl mapy je párování banky (pohyb je mezi kandidáty čísla úhrady a párování
                    // patří tomuto dokladu), nebo pokladní doklad téhož čísla.
                    if ($isIssued) {
                        $target = (int) $existing[$mapKey];
                        $matchRow->execute([$target, $ctx->supplierId]);
                        $match = $matchRow->fetch(PDO::FETCH_ASSOC);
                        if ($match !== false && (int) ($match['invoice_id'] ?? 0) === $docId
                            && in_array((int) $match['bank_transaction_id'], array_column($bankCandidates, 'id'), true)) {
                            $this->recordIssuedBank($ctx, $issuedPayments, $docId, (int) $match['bank_transaction_id'], $movements, $settledBy);
                        } elseif (in_array($target, array_column($cashCandidates, 'id'), true)) {
                            $this->recordIssuedCash($ctx, $issuedPayments, $docId, $target, $cashCandidates, $settledBy);
                        } else {
                            $p->count(self::STEP_PAYMENTS, 'missing_target');
                        }
                    }
                    $p->count(self::STEP_PAYMENTS, 'existing');
                    continue;
                }
                if ($relink && isset($docState['paid'][$type][$docId])) {
                    // Opravný převod úhradu k dokladu s evidovanou úhradou nepřidává - mohla
                    // vzniknout v MyÚčtu po převodu.
                    $p->count(self::STEP_PAYMENTS, 'relink_paid_kept');
                    continue;
                }
                $paidAt = InvoiceImporter::date($r, ['Uhrazeno']);
                $docTotal = $this->documentTotal($ctx->supplierId, $isIssued, $docId);
                $txId = self::choosePayment($bankCandidates, $year, $docTotal, $paidAt);
                if ($txId !== null) {
                    $movement = $movements[$txId] ?? null;
                    if (!self::movementEditable($movement, $relink)) {
                        $p->count(self::STEP_PAYMENTS, 'movement_kept');
                        continue;
                    }
                    $txAmount = 0.0;
                    foreach ($bankCandidates as $c) {
                        $txAmount = $c['id'] === $txId ? abs($c['amount']) : $txAmount;
                    }
                    // Částka párování je v měně pohybu; kandidát nese částku v Kč. Nejvýš do
                    // nespárované části pohybu a u korunového dokladu do jeho nespárované části.
                    $matched = min($txAmount, abs($docTotal)) ?: $txAmount;
                    if ($movement['currency'] !== 'CZK' && $txAmount >= 0.01) {
                        $matched = abs($movement['amount']) * min(1.0, $matched / $txAmount);
                    } elseif (!isset($docState['foreign'][$type][$docId])) {
                        $matched = min($matched, abs($docTotal) - ($docState['matchedCzk'][$type][$docId] ?? 0.0));
                    }
                    $matched = round(min($matched, abs($movement['amount']) - $movement['matched']), 2);
                    // Pohyb s dokladem spárovaný mezitím v MyÚčtu se nezdvojí.
                    $sameMatch->execute([$ctx->supplierId, $txId, $isIssued ? $docId : 0, $isIssued ? 0 : $docId]);
                    $matchId = (int) $sameMatch->fetchColumn();
                    if ($matchId === 0 && isset($existing[self::paymentMapKey($txId, $type, $docId)])) {
                        // Párování podle symbolu, které převod vytvořil dřív a uživatel ho zrušil.
                        $p->count(self::STEP_PAYMENTS, 'removed_kept');
                        continue;
                    }
                    if ($matchId === 0 && $matched < 0.01) {
                        $p->count(self::STEP_PAYMENTS, 'capacity_exhausted');
                        continue;
                    }
                    if ($matchId === 0) {
                        $insertMatch->execute([
                            $ctx->supplierId,
                            $txId,
                            $isIssued ? $docId : null,
                            $isIssued ? null : $docId,
                            number_format($matched, 2, '.', ''),
                            $ctx->userId > 0 ? $ctx->userId : null,
                        ]);
                        $matchId = (int) $pdo->lastInsertId();
                        $movements[$txId]['matched'] += $matched;
                        if ($movement['currency'] === 'CZK') {
                            $docState['matchedCzk'][$type][$docId] = ($docState['matchedCzk'][$type][$docId] ?? 0.0) + $matched;
                        }
                    }
                    $markTx->execute([$ctx->userId > 0 ? $ctx->userId : null, $isIssued ? $docId : null, $txId, $ctx->supplierId]);
                    if ($paidAt !== null) {
                        ($isIssued ? $markIssuedPaid : $markPurchasePaid)->execute([$paidAt, $docId, $ctx->supplierId]);
                    }
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, $mapKey, $matchId, $ctx->runId);
                    if ($isIssued) {
                        $this->recordIssuedBank($ctx, $issuedPayments, $docId, $txId, $movements, $settledBy);
                    }
                    $p->count(self::STEP_PAYMENTS, 'bank');
                    continue;
                }
                $cashId = self::choosePayment($cashCandidates, $year, $docTotal, $paidAt);
                if ($cashId !== null) {
                    // Pokladní doklad nese vazbu jen na jeden doklad. Hradí-li víc faktur, zůstane
                    // vazba na první a další ji nepřepíše; faktury jsou uhrazené podle Money.
                    $cashLink->execute([$cashId, $ctx->supplierId]);
                    $linked = $cashLink->fetch(PDO::FETCH_ASSOC) ?: [];
                    $current = $isIssued ? ($linked['invoice_id'] ?? null) : ($linked['purchase_invoice_id'] ?? null);
                    if (($linked['invoice_id'] ?? null) === null && ($linked['purchase_invoice_id'] ?? null) === null) {
                        $pdo->prepare(
                            'UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id')
                            . ' = ?, purpose = ? WHERE id = ? AND supplier_id = ?'
                        )->execute([$docId, $isIssued ? 'invoice_payment' : 'purchase_payment', $cashId, $ctx->supplierId]);
                    } elseif ($current === null || (int) $current !== $docId) {
                        $cashMulti[$settledBy] = true;
                        $p->count(self::STEP_PAYMENTS, 'cash_multi_invoice');
                    }
                    if (!$isIssued) {
                        $pdo->prepare("UPDATE purchase_invoices SET payment_method = 'cash' WHERE id = ? AND supplier_id = ?")
                            ->execute([$docId, $ctx->supplierId]);
                    }
                    if ($paidAt !== null) {
                        ($isIssued ? $markIssuedPaid : $markPurchasePaid)->execute([$paidAt, $docId, $ctx->supplierId]);
                    }
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, $mapKey, $cashId, $ctx->runId);
                    if ($isIssued) {
                        $this->recordIssuedCash($ctx, $issuedPayments, $docId, $cashId, $cashCandidates, $settledBy);
                    }
                    $p->count(self::STEP_PAYMENTS, 'cash');
                    continue;
                }
                if ($bankCandidates !== [] || $cashCandidates !== []) {
                    $p->count(self::STEP_PAYMENTS, 'ambiguous');
                    $p->warn(self::STEP_PAYMENTS, 'payment_ambiguous', "Úhradu {$settledBy} faktury {$docNo} nejde jednoznačně určit — v převedené bance nebo pokladně je víc dokladů se stejným číslem, částkou i datem. Spárujte ji ručně.", ['document_no' => $docNo]);
                    continue;
                }
                $p->count(self::STEP_PAYMENTS, 'not_found');
                $p->warn(self::STEP_PAYMENTS, 'payment_not_found', "Úhrada {$settledBy} faktury {$docNo} v převedené bance ani pokladně není.", ['document_no' => $docNo]);
            }
        }
        $this->repairForeignMatchAmounts($ctx, $movements);
        (new PairingSymbolMatcher($this->db, $this->map, $issuedPayments))->match($ctx, $movements);
        $this->recordMissingIssuedPayments($ctx, $issuedPayments, $movements);
        (new AdvanceLinker($this->db, $this->map))->link($ctx);
        $this->markBookedWithoutInvoice($ctx);
        if ($cashMulti !== []) {
            $p->info(self::STEP_PAYMENTS, 'cash_multi_invoice', count($cashMulti) . ' pokladních dokladů hradí víc faktur; pokladní doklad ukazuje vazbu jen na první, faktury jsou uhrazené podle Money.',
                ['documents' => array_slice(array_keys($cashMulti), 0, 50)]);
        }
        $p->finish(self::STEP_PAYMENTS);
    }

    /**
     * SQL podmínka „párování `$p` vytvořil převod". Párování vzniklé převodem je v mapě úhrad:
     * podle `UDoklad` pod klíčem dokladu (`v|rok|číslo`, `p|rok|číslo` - cíl musí patřit
     * dokladu z klíče, cílem bývá i id pokladního dokladu), podle párovacího symbolu a vrácení
     * zálohy pod klíčem pohybu a dokladu ({@see paymentMapKey()}). Ostatní párování zadal uživatel.
     */
    public static function ownMatchSql(string $p): string
    {
        return "(EXISTS (SELECT 1 FROM money_s3_import_map m
                          JOIN money_s3_import_map dm ON dm.supplier_id = m.supplier_id
                           AND dm.kind = IF({$p}.invoice_id IS NOT NULL, 'invoice', 'purchase_invoice')
                           AND dm.target_id = COALESCE({$p}.invoice_id, {$p}.purchase_invoice_id)
                         WHERE m.supplier_id = {$p}.supplier_id AND m.kind = 'payment' AND m.target_id = {$p}.id
                           AND m.money_key = CONCAT(IF({$p}.invoice_id IS NOT NULL, 'v|', 'p|'), dm.money_key))
                 OR EXISTS (SELECT 1 FROM money_s3_import_map m
                         WHERE m.supplier_id = {$p}.supplier_id AND m.kind = 'payment' AND m.target_id = {$p}.id
                           AND m.money_key = CONCAT('ps|', {$p}.bank_transaction_id, '|', IF({$p}.invoice_id IS NOT NULL, 'invoice|', 'purchase_invoice|'),
                                                    COALESCE({$p}.invoice_id, {$p}.purchase_invoice_id))))";
    }

    /**
     * Bankovní pohyby firmy jedním dotazem: id => částka v měně pohybu, měna, datum, částka
     * v Kč (u pohybu v cizí měně z Money, jinak částka sama), stav párování, součet párování
     * (v měně pohybu), počet párování zadaných uživatelem ({@see ownMatchSql()}) a zda má
     * pohyb živý převzatý bankovní zápis.
     *
     * @return array<int,array{amount:float,currency:string,date:string,czk:float,status:string,reason:string,matched:float,taken:bool}>
     */
    private function bankMovements(int $supplierId): array
    {
        $own = self::ownMatchSql('p');
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.amount, t.currency, DATE(t.posted_at) AS posted_on, t.match_status, t.match_reason,
                    COALESCE(pm.matched, 0) AS matched, COALESCE(pm.foreign_matches, 0) AS foreign_matches,
                    ' . TakenOverRecord::liveBankEntrySql('s.supplier_id', 't.id') . ' AS taken
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
               LEFT JOIN (SELECT p.bank_transaction_id, SUM(p.amount) AS matched, SUM(CASE WHEN ' . $own . ' THEN 0 ELSE 1 END) AS foreign_matches
                            FROM payment_matches p WHERE p.supplier_id = ? GROUP BY p.bank_transaction_id) pm
                      ON pm.bank_transaction_id = t.id
              WHERE s.supplier_id = ?'
        );
        $stmt->execute([$supplierId, $supplierId]);
        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $amount = (float) $r['amount'];
            $out[(int) $r['id']] = ['amount' => $amount, 'currency' => (string) $r['currency'], 'date' => (string) $r['posted_on'], 'czk' => $amount,
                'status' => (string) $r['match_status'], 'reason' => (string) ($r['match_reason'] ?? ''), 'matched' => (float) $r['matched'],
                'taken' => (int) $r['taken'] === 1, 'user_matches' => (int) $r['foreign_matches']];
        }
        return $out;
    }

    /** Klíč mapy úhrad pro párování vzniklé jinak než podle `UDoklad` (pohyb × doklad). */
    public static function paymentMapKey(int $txId, string $docType, int $docId): string
    {
        return 'ps|' . $txId . '|' . $docType . '|' . $docId;
    }

    /**
     * Živé záznamy firmy po druzích (dotaz na tabulku): doklady, pokladní doklady, pohyby
     * a nestornované zápisy deníku.
     *
     * @return array<string,array<int,true>>
     */
    private function liveRecords(int $supplierId): array
    {
        $out = ['invoice' => [], 'purchase_invoice' => [], 'cash' => [], 'bank' => [], 'entry' => []];
        foreach ([
            'invoice' => 'SELECT id FROM invoices WHERE supplier_id = ?',
            'purchase_invoice' => 'SELECT id FROM purchase_invoices WHERE supplier_id = ?',
            'cash' => 'SELECT id FROM cash_documents WHERE supplier_id = ?',
            'bank' => 'SELECT t.id FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ?',
            'entry' => 'SELECT id FROM journal_entries WHERE supplier_id = ? AND reversed_by IS NULL',
        ] as $kind => $sql) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $out[$kind][(int) $id] = true;
            }
        }
        return $out;
    }

    /**
     * Doklady firmy jedním dotazem na tabulku: které existují (mapa převodu může ukazovat
     * na doklad smazaný v MyÚčtu), které jsou v cizí měně, které mají evidovanou úhradu
     * (platba, párování, pokladna, zápočet) a kolik mají spárováno korunovými pohyby.
     *
     * @return array{exists:array<string,array<int,true>>,foreign:array<string,array<int,true>>,paid:array<string,array<int,true>>,matchedCzk:array<string,array<int,float>>}
     */
    private function documentState(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $out = ['exists' => [], 'foreign' => [], 'paid' => [], 'matchedCzk' => []];
        foreach ([
            'invoice' => 'SELECT d.id, cur.code FROM invoices d JOIN currencies cur ON cur.id = d.currency_id WHERE d.supplier_id = ?',
            'purchase_invoice' => 'SELECT d.id, cur.code FROM purchase_invoices d JOIN currencies cur ON cur.id = d.currency_id WHERE d.supplier_id = ?',
        ] as $type => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId]);
            while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
                $out['exists'][$type][(int) $r[0]] = true;
                if ((string) $r[1] !== 'CZK') {
                    $out['foreign'][$type][(int) $r[0]] = true;
                }
            }
        }
        $paid = $pdo->prepare(
            "SELECT 'invoice', invoice_id FROM invoice_payments WHERE supplier_id = ?
             UNION SELECT 'purchase_invoice', purchase_invoice_id FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id IS NOT NULL
             UNION SELECT 'purchase_invoice', purchase_invoice_id FROM cash_documents WHERE supplier_id = ? AND purchase_invoice_id IS NOT NULL
             UNION SELECT doc_type, doc_id FROM invoice_settlements WHERE supplier_id = ? AND status = 'confirmed'
             UNION SELECT oi.doc_type, oi.doc_id FROM offset_agreement_items oi WHERE oi.supplier_id = ?
             UNION SELECT doc_type, invoice_id FROM credit_note_offsets WHERE supplier_id = ?"
        );
        $paid->execute(array_fill(0, 6, $supplierId));
        while ($r = $paid->fetch(PDO::FETCH_NUM)) {
            $out['paid'][(string) $r[0]][(int) $r[1]] = true;
        }
        $matched = $pdo->prepare(
            "SELECT pm.invoice_id, pm.purchase_invoice_id, SUM(pm.amount)
               FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id AND bt.currency = 'CZK'
              WHERE pm.supplier_id = ? GROUP BY pm.invoice_id, pm.purchase_invoice_id"
        );
        $matched->execute([$supplierId]);
        while ($r = $matched->fetch(PDO::FETCH_NUM)) {
            if ($r[0] !== null) {
                $out['matchedCzk']['invoice'][(int) $r[0]] = (float) $r[2];
            } elseif ($r[1] !== null) {
                $out['matchedCzk']['purchase_invoice'][(int) $r[1]] = (float) $r[2];
            }
        }
        return $out;
    }

    /**
     * Smí převod pohyb spárovat? Jen pohyb s živým převzatým bankovním zápisem (platbu
     * zaúčtovanou v MyÚčtu převod nechá být) a jen nespárovaný, nebo převodem označený jako
     * zaúčtovaný bez dokladu; pohyb, který uživatel ignoroval, ne. Řádný převod smí přidat
     * párování i k pohybu, který převod spároval s jiným dokladem (hradí víc faktur), opravný
     * převod jen k pohybu, jehož všechna párování vytvořil převod - párování zadané
     * uživatelem je jeho rozhodnutí. Volnou částku pohybu hlídá volající.
     *
     * @param array<string,mixed>|null $m
     */
    public static function movementEditable(?array $m, bool $relinkOnly): bool
    {
        if ($m === null || !$m['taken']) {
            return false;
        }
        $free = $m['status'] === 'unmatched' || ($m['status'] === 'ignored' && $m['reason'] === 'money_s3_booked');
        $matched = in_array($m['status'], ['manual', 'auto_exact', 'auto_partial'], true);
        if ($relinkOnly) {
            return ($free && $m['matched'] < 0.005) || ($matched && ($m['user_matches'] ?? 1) === 0);
        }
        return $free || $matched;
    }

    /**
     * Párování úhrady z účtu v cizí měně, které dřívější verze převodu zapsala s částkou
     * v Kč (částka párování je v měně pohybu): taková verze zapisovala přesně menší z částky
     * pohybu v Kč a celku dokladu v Kč. Párování s právě touto částkou se přepočte kurzem
     * pohybu z Money. Jen párování z mapy úhrad převodu, které patří dokladu z klíče mapy
     * (cíl mapy je u pokladní úhrady id pokladního dokladu, ne párování); párování zadané
     * v MyÚčtu se nemění.
     *
     * @param array<int,array<string,mixed>> $movements
     */
    private function repairForeignMatchAmounts(ImportContext $ctx, array $movements): void
    {
        $pdo = $this->db->pdo();
        $row = $pdo->prepare('SELECT bank_transaction_id, invoice_id, purchase_invoice_id, amount FROM payment_matches WHERE id = ? AND supplier_id = ?');
        $update = $pdo->prepare('UPDATE payment_matches SET amount = ? WHERE id = ? AND supplier_id = ? AND amount = ?');
        $fixed = 0;
        foreach ($this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT) as $key => $target) {
            $parts = explode('|', (string) $key, 3);
            if (count($parts) !== 3 || !in_array($parts[0], ['v', 'p'], true)) {
                continue;
            }
            $issued = $parts[0] === 'v';
            $docId = ($issued ? $ctx->issuedInvoices : $ctx->purchaseInvoices)[$parts[1] . '|' . $parts[2]] ?? null;
            $row->execute([(int) $target, $ctx->supplierId]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if ($docId === null || $r === false || (int) ($r[$issued ? 'invoice_id' : 'purchase_invoice_id'] ?? 0) !== $docId) {
                continue;
            }
            $tx = (int) $r['bank_transaction_id'];
            $m = $movements[$tx] ?? null;
            $czk = abs($ctx->bankTransactionCzk[$tx] ?? 0.0);
            $amount = (float) $r['amount'];
            if ($m === null || $m['currency'] === 'CZK' || $czk < 0.01 || ReconciliationTolerance::sameCent($amount, abs($m['amount']))
                || !ReconciliationTolerance::sameCent($amount, min($czk, abs($this->documentTotal($ctx->supplierId, $issued, $docId))))) {
                continue;
            }
            $update->execute([number_format(abs($m['amount']) * min(1.0, $amount / $czk), 2, '.', ''), (int) $target, $ctx->supplierId, $r['amount']]);
            $fixed += $update->rowCount();
        }
        if ($fixed > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'foreign_match_amount_fixed', $fixed);
        }
    }

    /**
     * Párování vydané faktury, které vytvořil převod a ke kterému chybí evidovaná úhrada
     * (dřívější verze ji k faktuře, kterou Money neoznačilo jako uhrazenou, nezakládala):
     * úhrada se doplní v částce párování, jako při párování v bance v MyÚčtu. Párování
     * zadané uživatelem i vratka (odchozí pohyb) se nechají.
     *
     * @param array<int,array<string,mixed>> $movements
     */
    private function recordMissingIssuedPayments(ImportContext $ctx, IssuedPaymentRecorder $payments, array $movements): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.bank_transaction_id, p.invoice_id, p.amount FROM payment_matches p
              WHERE p.supplier_id = ? AND p.invoice_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.invoice_id = p.invoice_id AND ip.bank_transaction_id = p.bank_transaction_id)
                AND ' . self::ownMatchSql('p') . '
              ORDER BY p.id'
        );
        $stmt->execute([$ctx->supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return;
        }
        $docNo = [];
        foreach ($ctx->bankTransactions as $key => $id) {
            $docNo[(int) $id] = explode('|', (string) $key, 3)[1] ?? '';
        }
        foreach ($rows as $r) {
            $tx = (int) $r['bank_transaction_id'];
            $m = $movements[$tx] ?? null;
            if ($m === null || $m['amount'] <= 0) {
                continue;
            }
            $matched = abs((float) $r['amount']);
            $czk = $matched;
            if ($m['currency'] !== 'CZK') {
                $czk = isset($ctx->bankTransactionCzk[$tx]) && abs($m['amount']) >= 0.01 ? $matched * abs($ctx->bankTransactionCzk[$tx]) / abs($m['amount']) : 0.0;
            }
            $amount = $payments->toInvoiceCurrency($ctx->supplierId, (int) $r['invoice_id'], $czk, $m['currency'], $matched);
            if ($amount !== null && $amount >= 0.01) {
                $payments->record($ctx, (int) $r['invoice_id'], 'bank', $tx, null, $amount, $m['date'], $docNo[$tx] ?? '');
            }
        }
    }

    /** @param array<int,array{amount:float,currency:string,date:string,czk:float}> $movements */
    private function recordIssuedBank(ImportContext $ctx, IssuedPaymentRecorder $payments, int $invoiceId, int $txId, array $movements, string $paymentNo): void
    {
        $m = $movements[$txId] ?? null;
        if ($m === null) {
            return;
        }
        $czk = $m['currency'] === 'CZK' ? $m['amount'] : ($ctx->bankTransactionCzk[$txId] ?? null);
        $amount = $payments->toInvoiceCurrency($ctx->supplierId, $invoiceId, (float) ($czk ?? 0.0), $m['currency'], $m['amount']);
        if ($amount !== null) {
            $payments->record($ctx, $invoiceId, 'bank', $txId, null, $amount, $m['date'], $paymentNo);
        }
    }

    /** @param list<array{id:int,year:int,amount:float,date:?string}> $candidates */
    private function recordIssuedCash(ImportContext $ctx, IssuedPaymentRecorder $payments, int $invoiceId, int $cashId, array $candidates, string $paymentNo): void
    {
        foreach ($candidates as $c) {
            if ($c['id'] === $cashId && $c['date'] !== null) {
                $amount = $payments->toInvoiceCurrency($ctx->supplierId, $invoiceId, $c['amount']);
                if ($amount !== null) {
                    $payments->record($ctx, $invoiceId, 'cash', null, $cashId, $amount, $c['date'], $paymentNo);
                }
                return;
            }
        }
    }

    /**
     * Pohyby, které Money zaúčtovalo bez faktury (poplatky, převody mezi vlastními účty,
     * mzdy, odvody, ostatní závazky), jsou vyřízené - v MyÚčtu pro ně faktura není a výpis
     * by jinak trvale svítil jako nedopárovaný. Označí se jako ignorované s poznámkou; jen
     * pohyby převodu, které mají zápis v deníku a zůstaly nespárované. Uživatel to u pohybu
     * může vrátit.
     */
    private function markBookedWithoutInvoice(ImportContext $ctx): void
    {
        $marked = $this->entries->markBankBookedWithoutDocument(
            $ctx->supplierId,
            $ctx->userId > 0 ? $ctx->userId : null,
            array_map('intval', $ctx->bankTransactions),
            'money_s3_booked',
            'Zaúčtováno v Money S3 bez dokladu (převod z Money S3).',
            "EXISTS (SELECT 1 FROM journal_entry_document_links k WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id)"
            // Opravný převod jen pohyb, který uživatel nikdy neměl v rukou.
            . ($ctx->options->relinkOnly ? ' AND t.matched_by IS NULL AND NOT EXISTS (SELECT 1 FROM payment_matches rpm WHERE rpm.bank_transaction_id = t.id)' : ''),
        );
        if ($marked > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'booked_without_invoice', $marked);
        }
    }

    /**
     * Faktura, kterou Money zaúčtovalo jen evidenčně (řádky deníku MD = D, bez účinku),
     * protože výnos či náklad nese ostatní pohledávka (KP) nebo závazek (KZ) se stejným
     * párovacím symbolem — typicky prodej pozemku: pohledávka PPS 311/641, k ní vystavená
     * faktura pro odběratele 311/311. Převod evidenční řádky vynechává, faktura by tak
     * zůstala „zaúčtovaná" bez zápisu v deníku.
     *
     * Jediná pohledávka (závazek) se shodným symbolem i částkou ze stejného nebo předchozího
     * roku, kterou nečerpá jiná faktura, se navěsí jako zápis faktury (stejně jako KP
     * převedená do přiznání). Úhradu pohledávky z Money pak faktura převezme. Faktura bez
     * takového protějšku zůstane zaúčtovaná jen evidenčně; v Doúčtování dokladů se
     * nenabídne, protože by výnos zdvojila.
     *
     * @param list<array{type:string,year:int,document_no:string,id:int,par:string,amount:float}> $docs
     * @param array{neutral:array<string,array{par:string,amount:float}>,claims:array<string,array<string,array{year:int,doc:string,amounts:list<float>}>>} $evidence
     * @param array<string,array<int,true>> $live
     */
    private function linkEvidential(ImportContext $ctx, array $docs, array $evidence, array $live): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $matches = [];
        $claimUse = [];
        foreach ($docs as $i => $doc) {
            $source = $doc['type'] === 'invoice' ? 'KP' : 'KZ';
            $found = [];
            foreach ($evidence['claims'][$source . '|' . $doc['par']] ?? [] as $claimKey => $claim) {
                if ($doc['par'] === '' || ($claim['year'] !== $doc['year'] && $claim['year'] !== $doc['year'] - 1)
                    || isset($ctx->otherReceivables[$claimKey])) {
                    continue;
                }
                foreach ($claim['amounts'] as $amount) {
                    if (ReconciliationTolerance::sameCent($amount, $doc['amount'])) {
                        $found[$claimKey] = $claim;
                        break;
                    }
                }
            }
            if (count($found) === 1) {
                $matches[$i] = array_key_first($found);
                $claimUse[$matches[$i]] = ($claimUse[$matches[$i]] ?? 0) + 1;
            }
        }

        $owners = $pdo->prepare(
            'SELECT e.id FROM journal_entries e
              WHERE e.supplier_id = ? AND e.id = ?
                AND ((e.source_type = \'manual\' AND e.source_id IS NULL) OR (e.source_type = ? AND e.source_id = ?))
                AND NOT EXISTS (SELECT 1 FROM journal_document_postings g
                                 WHERE g.supplier_id = e.supplier_id AND g.entry_id = e.id AND NOT (g.doc_type = ? AND g.doc_id = ?))'
        );
        $settlements = null;
        $markIssuedPaid = $pdo->prepare(
            "UPDATE invoices SET status = 'paid', paid_at = COALESCE(paid_at, ?), paid_total = total_with_vat
              WHERE id = ? AND supplier_id = ? AND status IN ('issued', 'sent', 'reminded')"
        );
        $markPurchasePaid = $pdo->prepare(
            "UPDATE purchase_invoices SET status = 'paid', paid_at = COALESCE(paid_at, ?)
              WHERE id = ? AND supplier_id = ? AND document_kind = 'invoice' AND status IN ('received', 'booked')"
        );
        $indexes = [];
        $linked = 0;
        $evidentialOnly = 0;
        foreach ($docs as $i => $doc) {
            $claimKey = $matches[$i] ?? null;
            if ($claimKey === null || $claimUse[$claimKey] !== 1) {
                $evidentialOnly++;
                continue;
            }
            [$claimYear, $claimDoc] = explode('|', $claimKey, 2);
            $indexes[$claimYear] ??= $this->journalIndex($ctx->supplierId, (int) $claimYear);
            $entries = [];
            foreach ($indexes[$claimYear]['manual'][$claimDoc] ?? [] as $entry) {
                if (!isset($live['entry'][$entry['id']])) {
                    continue;
                }
                $owners->execute([$ctx->supplierId, $entry['id'], $doc['type'], $doc['id'], $doc['type'], $doc['id']]);
                if ($owners->fetchColumn() !== false) {
                    $entries[] = $entry['id'];
                }
            }
            if ($entries === []) {
                $evidentialOnly++;
                continue;
            }
            $this->entries->attach($ctx->supplierId, $ctx->userId > 0 ? $ctx->userId : null, $doc['type'], 'manual', $doc['type'], $doc['id'], $entries);
            $linked++;
            $settlements ??= $this->claimSettlements($ctx);
            $paidAt = $settlements[($doc['type'] === 'invoice' ? 'KP|' : 'KZ|') . $claimKey] ?? null;
            if ($paidAt !== null) {
                ($doc['type'] === 'invoice' ? $markIssuedPaid : $markPurchasePaid)->execute([$paidAt, $doc['id'], $ctx->supplierId]);
                if (($doc['type'] === 'invoice' ? $markIssuedPaid : $markPurchasePaid)->rowCount() > 0) {
                    $p->count(self::STEP_LINK, 'evidential_paid_by_claim');
                }
            }
        }
        if ($linked > 0) {
            $p->count(self::STEP_LINK, 'evidential_linked_claim', $linked);
            $p->info(self::STEP_LINK, 'evidential_linked_claim', $linked . ' faktur Money zaúčtovalo jen evidenčně (MD = D); '
                . 'zápisem faktury je ostatní pohledávka nebo závazek se stejným párovacím symbolem a částkou.');
        }
        if ($evidentialOnly > 0) {
            $p->count(self::STEP_LINK, 'evidential_only', $evidentialOnly);
            $p->info(self::STEP_LINK, 'evidential_only', $evidentialOnly . ' faktur Money zaúčtovalo jen evidenčně (MD = D) bez dohledatelného '
                . 'protějšku. Zůstávají zaúčtované bez vlastního zápisu; účetní účinek je v deníku pod jiným dokladem.');
        }
    }

    /**
     * Evidenční zaúčtování faktur v deníku Money a ostatní pohledávky/závazky podle
     * párovacího symbolu. `neutral`: „rok|FV|číslo" faktury, jejíž všechny řádky jsou
     * bez účinku (MD = D nebo nulová částka); `claims`: „KP|symbol" → „rok|číslo" → částky.
     *
     * @return array{neutral:array<string,array{par:string,amount:float}>,claims:array<string,array<string,array{year:int,doc:string,amounts:list<float>}>>}
     */
    private function evidencePostings(ImportContext $ctx): array
    {
        $docs = [];
        $claims = [];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik', ['Zdroj', 'Doklad', 'Castka', 'UcMD', 'UcD', 'ParSym']) as $row) {
            $source = strtoupper(trim((string) ($row['Zdroj'] ?? '')));
            $docNo = trim((string) ($row['Doklad'] ?? ''));
            $year = $ctx->yearOf($row);
            if ($year === null || $docNo === '' || !in_array($source, ['FV', 'FP', 'KP', 'KZ'], true)) {
                continue;
            }
            $par = trim((string) ($row['ParSym'] ?? ''));
            $amount = abs(round((float) ($row['Castka'] ?? 0), 2));
            $effect = Ms3Journal::effect($row) !== null;
            if ($source === 'FV' || $source === 'FP') {
                $key = $year . '|' . $source . '|' . $docNo;
                $docs[$key] ??= ['par' => $par, 'amount' => 0.0, 'effect' => false];
                $docs[$key]['effect'] = $docs[$key]['effect'] || $effect;
                $docs[$key]['amount'] = max($docs[$key]['amount'], $amount);
                if ($docs[$key]['par'] === '') {
                    $docs[$key]['par'] = $par;
                }
                continue;
            }
            if ($effect && $par !== '') {
                $claims[$source . '|' . $par][$year . '|' . $docNo]['year'] = $year;
                $claims[$source . '|' . $par][$year . '|' . $docNo]['doc'] = $docNo;
                $claims[$source . '|' . $par][$year . '|' . $docNo]['amounts'][] = $amount;
            }
        }
        $neutral = [];
        foreach ($docs as $key => $doc) {
            if (!$doc['effect']) {
                $neutral[$key] = ['par' => $doc['par'], 'amount' => $doc['amount']];
            }
        }
        return ['neutral' => $neutral, 'claims' => $claims];
    }

    /**
     * Datum úhrady ostatních pohledávek a závazků z Money (`UhDatum`), jen plně uhrazené
     * (bez zbytku k úhradě). Klíč „KP|rok|číslo", resp. „KZ|rok|číslo".
     *
     * @return array<string,string>
     */
    private function claimSettlements(ImportContext $ctx): array
    {
        $out = [];
        foreach (['KP' => 'KnihPohl', 'KZ' => 'KnihZav'] as $source => $table) {
            foreach ($ctx->backup->rowsAcrossYears($table) as $r) {
                $year = $ctx->yearOf($r);
                $docNo = trim((string) ($r['Doklad'] ?? ''));
                $paidAt = trim((string) ($r['UhDatum'] ?? ''));
                if ($year === null || $docNo === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidAt) !== 1
                    || (int) ($r['Storno'] ?? 0) === 1 || abs(round((float) ($r['UhZbyva'] ?? 0), 2)) >= 0.005) {
                    continue;
                }
                $out[$source . '|' . $year . '|' . $docNo] = $paidAt;
            }
        }
        return $out;
    }

    /**
     * Zápisy deníku roku převedené z Money: typ zdroje → číslo dokladu → id zápisů
     * (v pořadí data). Staví se z mapy převodu, takže funguje i pro zápisy z dřívějšího
     * běhu. Klíč zápisu v mapě je `rok|zdroj|číslo|datum`.
     *
     * @return array<string,array<string,list<array{date:string,id:int}>>>
     */
    private function journalIndex(int $supplierId, int $year): array
    {
        $index = [];
        foreach ($this->map->all($supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $year . '|') as $key => $entryId) {
            $parts = explode('|', (string) $key);
            if (count($parts) < 4 || (int) $parts[0] !== $year) {
                continue; // otevírací zápis "rok|XP"
            }
            [, $source, $docNo, $date] = $parts;
            if ($docNo === '') {
                continue;
            }
            $index[Ms3Journal::sourceType($source)][$docNo][$date . '|' . str_pad((string) $entryId, 12, '0', STR_PAD_LEFT)] = ['date' => $date, 'id' => $entryId];
        }
        foreach ($index as &$docs) {
            foreach ($docs as &$entries) {
                ksort($entries);
                $entries = array_values($entries);
            }
        }
        unset($docs, $entries);
        return $index;
    }

    private function documentDate(int $supplierId, string $docType, int $docId): ?string
    {
        $stmt = $this->db->pdo()->prepare($docType === 'bank'
            ? 'SELECT DATE(t.posted_at) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE t.id = ? AND s.supplier_id = ?'
            : 'SELECT issue_date FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$docId, $supplierId]);
        $date = $stmt->fetchColumn();
        return is_string($date) ? substr($date, 0, 10) : null;
    }

    /**
     * Doklady, u kterých převod zápis v deníku Money nečeká: koncept k ruční kontrole
     * a zálohová faktura, kterou Money neúčtuje (DPH nese daňový doklad k platbě).
     *
     * @return array<int,true>
     */
    private function draftIds(string $table, int $supplierId): array
    {
        $advance = $table === 'invoices' ? "invoice_type = 'proforma'" : "document_kind = 'advance'";
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND (status = 'draft' OR {$advance})");
        $stmt->execute([$supplierId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** Celkem dokladu v Kč - úhrady se porovnávají v Kč, doklad v cizí měně se přepočte kurzem dokladu. */
    private function documentTotal(int $supplierId, bool $issued, int $id): float
    {
        $total = ForeignCurrencyTakeover::homeAmountSql('total_with_vat', 'exchange_rate');
        $stmt = $this->db->pdo()->prepare(
            $issued
                ? "SELECT {$total} FROM invoices WHERE id = ? AND supplier_id = ?"
                : "SELECT {$total} FROM purchase_invoices WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Pohyb, kterým byla faktura uhrazena, z dokladů se stejným číslem. Uvažují se
     * doklady z roku faktury a z následujícího (faktura z prosince placená v lednu), jinak
     * jen jediný existující. Rozhoduje shoda částky s fakturou, pak datum úhrady z Money,
     * nakonec stejný rok. Dva stejně dobré kandidáty nejde rozlišit — null.
     *
     * @param list<array{id:int,year:int,amount:float,date:?string}> $candidates
     */
    public static function choosePayment(array $candidates, int $year, float $docTotal, ?string $paidAt): ?int
    {
        $near = array_values(array_filter($candidates, static fn (array $c): bool => $c['year'] === $year || $c['year'] === $year + 1));
        if ($near === [] && count($candidates) === 1) {
            $near = $candidates;
        }
        $best = null;
        $bestScore = -1;
        $tie = false;
        foreach ($near as $c) {
            $score = (ReconciliationTolerance::sameCent(abs($c['amount']), abs($docTotal)) ? 8 : 0)
                + ($paidAt !== null && $c['date'] === $paidAt ? 4 : 0)
                + ($c['year'] === $year ? 2 : 1);
            if ($score > $bestScore) {
                [$best, $bestScore, $tie] = [$c['id'], $score, false];
            } elseif ($score === $bestScore) {
                $tie = true;
            }
        }
        return $tie ? null : $best;
    }

    /** @return array{id:int,year:int,amount:float,date:?string} */
    private static function candidate(\PDOStatement $row, int $id, int $year, int $supplierId): array
    {
        $row->execute([$id, $supplierId]);
        $r = $row->fetch(PDO::FETCH_NUM) ?: [0, null];
        return ['id' => $id, 'year' => $year, 'amount' => (float) $r[0], 'date' => $r[1] !== null ? substr((string) $r[1], 0, 10) : null];
    }
}
