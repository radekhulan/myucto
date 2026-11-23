<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;

/**
 * Převody z Money S3: běhy průvodce s protokolem (`money_s3_imports`) a mapa „co už
 * z agendy v MyÚčtu vzniklo" (`money_s3_import_map`, migrace 1806).
 *
 * Mapa, běhy i zámek převodu firmy jsou společné s ostatními převody
 * ({@see AbstractMigrationImportRepository}).
 */
final class MoneyS3ImportRepository extends AbstractMigrationImportRepository
{
    public const KIND_PERIOD = 'period';
    public const KIND_JOURNAL_ENTRY = 'journal_entry';
    public const KIND_CLIENT = 'client';
    public const KIND_POSTING_RULE = 'posting_rule';
    public const KIND_PURCHASE_INVOICE = 'purchase_invoice';
    public const KIND_INVOICE = 'invoice';
    public const KIND_CASH_REGISTER = 'cash_register';
    public const KIND_CASH_DOCUMENT = 'cash_document';
    public const KIND_BANK_STATEMENT = 'bank_statement';
    public const KIND_BANK_TRANSACTION = 'bank_transaction';
    public const KIND_PAYMENT = 'payment';
    public const KIND_COST_CENTER = 'cost_center';
    public const KIND_DIMENSION_VALUE = 'dimension_value';
    public const KIND_ASSET = 'asset';
    public const KIND_SMALL_ASSET = 'small_asset';
    /** Čerpání poskytnuté (klíč `pad|…`) nebo přijaté zálohy (`iad|…`), které převod spároval podle saldokonta ({@see \MyInvoice\Service\Migration\MoneyS3\AdvanceDeductionMatcher}). */
    public const KIND_ADVANCE_DEDUCTION = 'advance_deduction';
    /** Daňová evidence: úhrada z peněžního deníku, zápočet, zařazení pohybu, ostatní položka, podání DPH. */
    public const KIND_TAX_PAYMENT = 'tax_payment';
    public const KIND_TAX_OFFSET = 'tax_offset';
    public const KIND_TAX_CLASSIFICATION = 'tax_classification';
    public const KIND_OTHER_ITEM = 'other_item';
    public const KIND_VAT_FILING = 'vat_filing';

    /**
     * Otisky zdrojových záznamů druhu (volitelně jen klíče s předponou): klíč => otisk,
     * null = záznam převedený před zavedením otisků.
     *
     * @return array<string,?string>
     */
    public function fingerprints(int $supplierId, string $kind, ?string $keyPrefix = null): array
    {
        $sql = 'SELECT money_key, fingerprint FROM money_s3_import_map WHERE supplier_id = ? AND kind = ?';
        $params = [$supplierId, $kind];
        if ($keyPrefix !== null) {
            $sql .= ' AND money_key LIKE ?';
            $params[] = addcslashes($keyPrefix, '%_\\') . '%';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$key, $fingerprint]) {
            $out[(string) $key] = $fingerprint === null ? null : (string) $fingerprint;
        }
        return $out;
    }

    /**
     * Otisky zdrojových záznamů, jak je převod právě zapsal nebo ověřil, po dávkách.
     * Jen u existujících řádků mapy (`$rows` klíč => [cíl, otisk]); cíl se nemění.
     *
     * @param array<string,array{0:int,1:string}> $rows
     */
    public function setFingerprints(int $supplierId, string $kind, array $rows): void
    {
        foreach (array_chunk($rows, 500, true) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $key => [$targetId, $fingerprint]) {
                $values[] = '(?, ?, ?, ?, ?)';
                array_push($params, $supplierId, $kind, self::key((string) $key), $targetId, $fingerprint);
            }
            $this->db->pdo()->prepare(
                'INSERT INTO money_s3_import_map (supplier_id, kind, money_key, target_id, fingerprint) VALUES ' . implode(', ', $values)
                . ' ON DUPLICATE KEY UPDATE fingerprint = VALUES(fingerprint)'
            )->execute($params);
        }
    }

    /** Řádek mapy záznamu, který převod smazal, protože ho smazalo Money. */
    public function forget(int $supplierId, string $kind, string $key): void
    {
        $this->db->pdo()->prepare('DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = ? AND money_key = ?')
            ->execute([$supplierId, $kind, self::key($key)]);
    }

    protected function runsTable(): string
    {
        return 'money_s3_imports';
    }

    /**
     * Převzal převod z Money S3 zaúčtování mezd dnů `$from` až `$until` (bez něj)? Rozhoduje
     * živý převzatý zápis s předpisem na účet zaměstnanců (Dal 331): hrubé mzdy z interního
     * dokladu mezd nebo čistá mzda závazku. Počáteční stavy a uzávěrka se nepočítají, úhrada
     * mezd (MD 331) také ne. Mzdy téhož měsíce z mzdového programu by deník i závazky zdvojily.
     */
    public function payrollMonthConverted(int $supplierId, string $from, string $until): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id AND ca.supplier_id = l.supplier_id
              WHERE e.supplier_id = ? AND e.entry_date >= ? AND e.entry_date < ?
                AND e.source_type NOT IN ('opening', 'closing') AND e.reversed_by IS NULL
                AND l.side = 'credit' AND (ca.account_code = '331' OR ca.account_code LIKE '331.%')
                AND EXISTS (SELECT 1 FROM money_s3_import_map m
                             WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id)
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $from, $until]);
        return $stmt->fetchColumn() !== false;
    }

    protected function mapTable(): string
    {
        return 'money_s3_import_map';
    }

    protected function keyColumn(): string
    {
        return 'money_key';
    }

    protected function lockPrefix(): string
    {
        return 'money_s3';
    }

    protected function runMeta(array $meta): array
    {
        return [
            'agenda_ico' => self::str($meta['agenda_ico'] ?? null, 20),
            'agenda_name' => self::str($meta['agenda_name'] ?? null, 190),
            'money_version' => self::str($meta['money_version'] ?? null, 20),
            'backup_sha256' => self::str($meta['backup_sha256'] ?? null, 64),
        ];
    }

    protected function mapConflict(string $kind, string $key): \RuntimeException
    {
        return new MoneyS3Exception('map_conflict', "Záznam {$kind} {$key} z Money už v MyÚčtu převedený je — převod se zastavil, aby nic nezdvojil.");
    }
}
