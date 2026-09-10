<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
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

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
    ) {}

    public function link(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $index = $this->journalIndex($ctx->supplierId);
        // Koncept k ruční kontrole (zálohová faktura, doklad nejisté daňové povahy)
        // v deníku Money zápis mít nemusí a zaúčtuje se až po potvrzení účetní.
        $drafts = [
            'purchase_invoice' => $this->draftIds('purchase_invoices', $ctx->supplierId),
            'invoice' => $this->draftIds('invoices', $ctx->supplierId),
        ];
        $orphans = [];
        foreach ([
            ['purchase_invoice', 'purchase_invoice', $ctx->purchaseInvoices],
            ['invoice', 'invoice', $ctx->issuedInvoices],
            ['cash', 'cash', $ctx->cashDocuments],
            ['bank', 'bank', $ctx->bankTransactions],
        ] as [$sourceType, $docType, $docs]) {
            foreach ($docs as $key => $docId) {
                [$year, $docNo] = explode('|', (string) $key, 3);
                $entries = $index[(int) $year][$sourceType][$docNo] ?? [];
                if (count($entries) > 1 && in_array($docType, ['bank', 'cash'], true)) {
                    // Stejné číslo dokladu na dvou účtech (pokladnách): zápis se pozná podle data.
                    $date = $this->documentDate($ctx->supplierId, $docType, $docId);
                    $sameDay = array_values(array_filter($entries, static fn (array $e): bool => $e['date'] === $date));
                    $entries = $sameDay !== [] ? $sameDay : $entries;
                }
                $entries = array_column($entries, 'id');
                if ($entries === []) {
                    if (isset($drafts[$docType][$docId])) {
                        $p->count(self::STEP_LINK, 'review_unlinked');
                        continue;
                    }
                    if (count($orphans) < ImportProtocol::LIST_LIMIT) {
                        $orphans[] = ['type' => $docType, 'year' => (int) $year, 'document_no' => $docNo, 'id' => $docId];
                    }
                    $p->count(self::STEP_LINK, 'orphans');
                    continue;
                }
                $new = $this->attach($ctx, $sourceType, $docType, $docId, $entries);
                $p->count(self::STEP_LINK, $new ? 'linked' : 'existing');
            }
        }
        $p->set('orphans', $orphans);
        if ($orphans !== []) {
            $p->warn(self::STEP_LINK, 'orphan_documents', count($orphans) . ' dokladů nemá v deníku Money zápis se stejným číslem. '
                . 'Nejsou zaúčtované; zaúčtujte je ručně nebo v Účetnictví → Doúčtovat doklady.');
        }
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
            $bankByDoc[$docNo][] = self::candidate($txRow, $id, (int) $year, $ctx->supplierId);
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
        $markTx = $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.match_status = 'manual', t.matched_at = NOW(), t.matched_by = ?, t.matched_invoice_id = COALESCE(?, t.matched_invoice_id)
              WHERE t.id = ? AND s.supplier_id = ?"
        );

        foreach ([['PFaktury', $ctx->purchaseInvoices, false], ['VFaktury', $ctx->issuedInvoices, true]] as [$table, $docs, $isIssued]) {
            foreach ($ctx->backup->rowsAcrossYears($table) as $r) {
                $year = $ctx->yearOf($r);
                $docNo = trim((string) ($r['Doklad'] ?? ''));
                $settledBy = trim((string) ($r['UDoklad'] ?? ''));
                $docId = $docs[$year . '|' . $docNo] ?? null;
                if ($year === null || $docId === null || $settledBy === '') {
                    continue;
                }
                $mapKey = ($isIssued ? 'v' : 'p') . '|' . $year . '|' . $docNo;
                if (isset($existing[$mapKey])) {
                    $p->count(self::STEP_PAYMENTS, 'existing');
                    continue;
                }
                $paidAt = InvoiceImporter::date($r, ['Uhrazeno']);
                $docTotal = $this->documentTotal($ctx->supplierId, $isIssued, $docId);
                $bankCandidates = $bankByDoc[$settledBy] ?? [];
                $txId = self::choosePayment($bankCandidates, $year, $docTotal, $paidAt);
                if ($txId !== null) {
                    $txAmount = 0.0;
                    foreach ($bankCandidates as $c) {
                        $txAmount = $c['id'] === $txId ? abs($c['amount']) : $txAmount;
                    }
                    $insertMatch->execute([
                        $ctx->supplierId,
                        $txId,
                        $isIssued ? $docId : null,
                        $isIssued ? null : $docId,
                        number_format(min($txAmount, abs($docTotal)) ?: $txAmount, 2, '.', ''),
                        $ctx->userId > 0 ? $ctx->userId : null,
                    ]);
                    $matchId = (int) $pdo->lastInsertId();
                    $markTx->execute([$ctx->userId > 0 ? $ctx->userId : null, $isIssued ? $docId : null, $txId, $ctx->supplierId]);
                    if ($paidAt !== null) {
                        ($isIssued ? $markIssuedPaid : $markPurchasePaid)->execute([$paidAt, $docId, $ctx->supplierId]);
                    }
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, $mapKey, $matchId, $ctx->runId);
                    $p->count(self::STEP_PAYMENTS, 'bank');
                    continue;
                }
                $cashCandidates = $cashByDoc[$settledBy] ?? [];
                $cashId = self::choosePayment($cashCandidates, $year, $docTotal, $paidAt);
                if ($cashId !== null) {
                    $pdo->prepare(
                        'UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id')
                        . ' = ?, purpose = ? WHERE id = ? AND supplier_id = ?'
                    )->execute([$docId, $isIssued ? 'invoice_payment' : 'purchase_payment', $cashId, $ctx->supplierId]);
                    if (!$isIssued) {
                        $pdo->prepare("UPDATE purchase_invoices SET payment_method = 'cash' WHERE id = ? AND supplier_id = ?")
                            ->execute([$docId, $ctx->supplierId]);
                    }
                    if ($paidAt !== null) {
                        ($isIssued ? $markIssuedPaid : $markPurchasePaid)->execute([$paidAt, $docId, $ctx->supplierId]);
                    }
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, $mapKey, $cashId, $ctx->runId);
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
        $p->finish(self::STEP_PAYMENTS);
    }

    /**
     * Zápisy deníku převedené z Money: rok → typ zdroje → číslo dokladu → id zápisů
     * (v pořadí data). Staví se z mapy převodu, takže funguje i pro zápisy z dřívějšího
     * běhu.
     *
     * @return array<int,array<string,array<string,list<array{date:string,id:int}>>>>
     */
    private function journalIndex(int $supplierId): array
    {
        $index = [];
        foreach ($this->map->all($supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY) as $key => $entryId) {
            $parts = explode('|', (string) $key);
            if (count($parts) < 4) {
                continue; // otevírací zápis "rok|XP"
            }
            [$year, $source, $docNo, $date] = $parts;
            if ($docNo === '') {
                continue;
            }
            $index[(int) $year][Ms3Journal::sourceType($source)][$docNo][$date . '|' . str_pad((string) $entryId, 12, '0', STR_PAD_LEFT)] = ['date' => $date, 'id' => $entryId];
        }
        foreach ($index as &$types) {
            foreach ($types as &$docs) {
                foreach ($docs as &$entries) {
                    ksort($entries);
                    $entries = array_values($entries);
                }
            }
        }
        return $index;
    }

    /**
     * @param list<int> $entryIds
     * @return bool true = nová vazba
     */
    private function attach(ImportContext $ctx, string $sourceType, string $docType, int $docId, array $entryIds): bool
    {
        $pdo = $this->db->pdo();
        $owner = $pdo->prepare(
            'SELECT id FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL LIMIT 1'
        );
        $owner->execute([$ctx->supplierId, $sourceType, $docId]);
        $isNew = false;
        if ($owner->fetchColumn() === false) {
            $pdo->prepare(
                'UPDATE journal_entries SET source_id = ?
                  WHERE id = ? AND supplier_id = ? AND source_type = ? AND source_id IS NULL'
            )->execute([$docId, $entryIds[0], $ctx->supplierId, $sourceType]);
            $isNew = true;
        }
        $link = $pdo->prepare(
            'INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($entryIds as $entryId) {
            $link->execute([$ctx->supplierId, $entryId, $docType, $docId, 'Převzato z Money S3', $ctx->userId > 0 ? $ctx->userId : null]);
            $isNew = $isNew || $link->rowCount() > 0;
        }
        if ($sourceType === 'cash') {
            $pdo->prepare('UPDATE cash_documents SET journal_entry_id = ? WHERE id = ? AND supplier_id = ? AND journal_entry_id IS NULL')
                ->execute([$entryIds[0], $docId, $ctx->supplierId]);
        }
        return $isNew;
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

    /** @return array<int,true> */
    private function draftIds(string $table, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND status = 'draft'");
        $stmt->execute([$supplierId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private function documentTotal(int $supplierId, bool $issued, int $id): float
    {
        $stmt = $this->db->pdo()->prepare(
            $issued
                ? 'SELECT total_with_vat FROM invoices WHERE id = ? AND supplier_id = ?'
                : 'SELECT total_with_vat FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
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
            $score = (abs(abs($c['amount']) - abs($docTotal)) < 0.005 ? 8 : 0)
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
