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
        $orphans = [];
        foreach ([
            ['purchase_invoice', 'purchase_invoice', $ctx->purchaseInvoices],
            ['invoice', 'invoice', $ctx->issuedInvoices],
            ['cash', 'cash', $ctx->cashDocuments],
            ['bank', 'bank', $ctx->bankTransactions],
        ] as [$sourceType, $docType, $docs]) {
            foreach ($docs as $key => $docId) {
                [$year, $docNo] = explode('|', (string) $key, 2);
                $entries = $index[(int) $year][$sourceType][$docNo] ?? [];
                if ($entries === []) {
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
                . 'Nejsou zaúčtované; automatika je po převodu zaúčtuje, pokud je nezaúčtujete ručně.');
        }
        $p->finish(self::STEP_LINK);
    }

    /**
     * Úhrady: Money u faktury drží číslo dokladu, kterým byla uhrazena (`UDoklad`), takže
     * se nehádá podle částky a data.
     */
    public function matchPayments(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $bankByDoc = [];
        foreach ($ctx->bankTransactions as $key => $id) {
            [$year, $docNo] = explode('|', (string) $key, 2);
            $bankByDoc[$docNo][(int) $year] = $id;
        }
        $cashByDoc = [];
        foreach ($ctx->cashDocuments as $key => $id) {
            [$year, $docNo] = explode('|', (string) $key, 2);
            $cashByDoc[$docNo][(int) $year] = $id;
        }
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT);
        $txRow = $pdo->prepare(
            'SELECT t.amount FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE t.id = ? AND s.supplier_id = ?'
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
                $txId = self::pick($bankByDoc[$settledBy] ?? [], $year);
                if ($txId !== null) {
                    $txRow->execute([$txId, $ctx->supplierId]);
                    $txAmount = abs((float) $txRow->fetchColumn());
                    $docTotal = $this->documentTotal($ctx->supplierId, $isIssued, $docId);
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
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, $mapKey, $matchId, $ctx->runId);
                    $p->count(self::STEP_PAYMENTS, 'bank');
                    continue;
                }
                $cashId = self::pick($cashByDoc[$settledBy] ?? [], $year);
                if ($cashId !== null) {
                    $pdo->prepare(
                        'UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id')
                        . ' = ?, purpose = ? WHERE id = ? AND supplier_id = ?'
                    )->execute([$docId, $isIssued ? 'invoice_payment' : 'purchase_payment', $cashId, $ctx->supplierId]);
                    if (!$isIssued) {
                        $pdo->prepare("UPDATE purchase_invoices SET payment_method = 'cash' WHERE id = ? AND supplier_id = ?")
                            ->execute([$docId, $ctx->supplierId]);
                    }
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PAYMENT, $mapKey, $cashId, $ctx->runId);
                    $p->count(self::STEP_PAYMENTS, 'cash');
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
     * @return array<int,array<string,array<string,list<int>>>>
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
            $index[(int) $year][Ms3Journal::sourceType($source)][$docNo][$date . '|' . str_pad((string) $entryId, 12, '0', STR_PAD_LEFT)] = $entryId;
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
     * Úhrada s daným číslem: nejdřív ze stejného roku, pak z následujícího (faktura
     * z prosince placená v lednu), jinak jediná existující.
     *
     * @param array<int,int> $byYear
     */
    private static function pick(array $byYear, int $year): ?int
    {
        if (isset($byYear[$year])) {
            return $byYear[$year];
        }
        if (isset($byYear[$year + 1])) {
            return $byYear[$year + 1];
        }
        return count($byYear) === 1 ? (int) reset($byYear) : null;
    }
}
