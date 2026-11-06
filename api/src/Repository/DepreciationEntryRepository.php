<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro depreciation_entries — uplatněné/zaúčtované odpisy rok × druh
 * (Epic F3). Materializuje se JEN skutečnost (R11), unikát (asset_id, kind,
 * fiscal_year) drží idempotenci upsertu.
 */
final class DepreciationEntryRepository
{
    /**
     * `detail.journal` účetního řádku, který zaúčtoval deník převzatý z jiného programu
     * (převod z PREMIER) - v deníku je už jako zápisy toho programu, MyÚčto ho znovu neúčtuje.
     */
    public const MIGRATED_JOURNAL = 'migration';

    /** Klíč původu daňového řádku v `detail`; hodnota {@see MIGRATED_JOURNAL} = potvrdil ho převod. */
    public const MIGRATED_TAX_SOURCE = 'source';

    public function __construct(private readonly Connection $db) {}

    /** Daňový odpis roku ručně přepsaný na kartě (důvod v `override_reason`). */
    public static function isOverridden(?array $entry): bool
    {
        return $entry !== null && ($entry['override_reason'] ?? null) !== null && (string) $entry['override_reason'] !== '';
    }

    /**
     * Daňový řádek potvrdil převod z jiného programu (původ v `detail`). Ručně přepsaný
     * řádek už převodu nepatří: opakovaný převod ho nemění.
     */
    public static function isConfirmedByMigration(?array $entry): bool
    {
        if ($entry === null || self::isOverridden($entry)) {
            return false;
        }
        $detail = is_string($entry['detail'] ?? null) ? json_decode((string) $entry['detail'], true) : ($entry['detail'] ?? null);
        return is_array($detail) && ($detail[self::MIGRATED_TAX_SOURCE] ?? null) === self::MIGRATED_JOURNAL;
    }

    /** @param array<string,mixed>|null $entry řádek z {@see findYear()} */
    public static function isBookedByMigratedJournal(?array $entry): bool
    {
        if ($entry === null || ($entry['status'] ?? '') !== 'posted') {
            return false;
        }
        $detail = is_string($entry['detail'] ?? null) ? json_decode((string) $entry['detail'], true) : ($entry['detail'] ?? null);
        return is_array($detail) && ($detail['journal'] ?? null) === self::MIGRATED_JOURNAL;
    }

    /**
     * Všechny řádky karty (tvar pro DepreciationContext::confirmedEntries).
     *
     * @return list<array<string,mixed>>
     */
    public function forAsset(int $assetId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, asset_id, kind, fiscal_year, amount, full_amount,
                    residual_value_end, is_paused, is_half, months_count, detail, status,
                    override_reason, override_original_amount, override_by, override_at,
                    created_at, updated_at
               FROM depreciation_entries
              WHERE asset_id = ? AND voided_at IS NULL
              ORDER BY kind, fiscal_year'
        );
        $stmt->execute([$assetId]);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, asset_id, kind, fiscal_year, amount, full_amount,
                    residual_value_end, is_paused, is_half, months_count, detail, status,
                    override_reason, override_original_amount, override_by, override_at,
                    created_at, updated_at
               FROM depreciation_entries
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function findYear(int $assetId, string $kind, int $fiscalYear): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, asset_id, kind, fiscal_year, amount, full_amount,
                    residual_value_end, is_paused, is_half, months_count, detail, status,
                    override_reason, override_original_amount, override_by, override_at,
                    created_at, updated_at
               FROM depreciation_entries
              WHERE asset_id = ? AND kind = ? AND fiscal_year = ? AND voided_at IS NULL'
        );
        $stmt->execute([$assetId, $kind, $fiscalYear]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * INSERT … ON DUPLICATE KEY UPDATE přes uq_de_asset_kind_year — idempotentní
     * re-book přepisuje in-place (R12). Vrací id řádku (existující při update).
     *
     * @param array{
     *     supplier_id:int, asset_id:int, kind:'tax'|'accounting', fiscal_year:int,
     *     amount:float, full_amount:float, residual_value_end:float,
     *     is_paused?:bool|int, is_half?:bool|int, months_count?:?int, detail?:?string,
     *     status?:'confirmed'|'posted'
     * } $row
     */
    public function upsert(array $row): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO depreciation_entries
                (supplier_id, asset_id, kind, fiscal_year, amount, full_amount,
                 residual_value_end, is_paused, is_half, months_count, detail, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                amount = VALUES(amount),
                full_amount = VALUES(full_amount),
                residual_value_end = VALUES(residual_value_end),
                is_paused = VALUES(is_paused),
                is_half = VALUES(is_half),
                months_count = VALUES(months_count),
                detail = VALUES(detail),
                status = VALUES(status),
                voided_at = NULL,
                id = LAST_INSERT_ID(id)'
        )->execute([
            (int) $row['supplier_id'],
            (int) $row['asset_id'],
            (string) $row['kind'],
            (int) $row['fiscal_year'],
            round((float) $row['amount'], 2),
            round((float) $row['full_amount'], 2),
            round((float) $row['residual_value_end'], 2),
            (int) (bool) ($row['is_paused'] ?? false),
            (int) (bool) ($row['is_half'] ?? false),
            $row['months_count'] ?? null,
            $row['detail'] ?? null,
            (string) ($row['status'] ?? 'confirmed'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Ruční přepis daňového odpisu roku: nová částka a zůstatková cena, původní hodnoty
     * se uloží jen při prvním přepisu (další přepis vrací pořád k odpisu kalkulačky/převodu).
     */
    public function applyOverride(int $supplierId, int $id, float $amount, float $residualEnd, string $reason, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE depreciation_entries
                SET override_original_amount = COALESCE(override_original_amount, amount),
                    override_original_full_amount = COALESCE(override_original_full_amount, full_amount),
                    override_original_residual = COALESCE(override_original_residual, residual_value_end),
                    amount = ?, full_amount = ?, residual_value_end = ?,
                    override_reason = ?, override_by = ?, override_at = NOW()
              WHERE id = ? AND supplier_id = ?'
        )->execute([round($amount, 2), round($amount, 2), round($residualEnd, 2), $reason, $userId, $id, $supplierId]);
    }

    /**
     * Zruší ruční přepis: vrátí původní odpis a zůstatkovou cenu.
     *
     * @return array{amount:float, full_amount:float, residual:float}|null původní hodnoty; null = řádek přepsaný nebyl
     */
    public function clearOverride(int $supplierId, int $id): ?array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT override_original_amount, override_original_full_amount, override_original_residual
               FROM depreciation_entries WHERE id = ? AND supplier_id = ? AND override_reason IS NOT NULL'
        );
        $stmt->execute([$id, $supplierId]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($original === false) {
            return null;
        }
        $pdo->prepare(
            'UPDATE depreciation_entries
                SET amount = override_original_amount, full_amount = override_original_full_amount,
                    residual_value_end = override_original_residual,
                    override_reason = NULL, override_original_amount = NULL, override_original_full_amount = NULL,
                    override_original_residual = NULL, override_by = NULL, override_at = NULL
              WHERE id = ? AND supplier_id = ?'
        )->execute([$id, $supplierId]);
        return [
            'amount' => (float) $original['override_original_amount'],
            'full_amount' => (float) $original['override_original_full_amount'],
            'residual' => (float) $original['override_original_residual'],
        ];
    }

    /**
     * Posune daňovou zůstatkovou cenu potvrzených řádků po roce `$year` o `-$delta`
     * (změna stanoveného odpisu dřívějšího roku). Částky odpisů pozdějších let zůstávají.
     */
    public function shiftLaterTaxResiduals(int $supplierId, int $assetId, int $year, float $delta): void
    {
        $this->db->pdo()->prepare(
            "UPDATE depreciation_entries SET residual_value_end = residual_value_end - ?
              WHERE supplier_id = ? AND asset_id = ? AND kind = 'tax' AND fiscal_year > ?"
        )->execute([round($delta, 2), $supplierId, $assetId, $year]);
    }

    /** Nejnižší daňová zůstatková cena potvrzených řádků po roce `$year`, null = žádné nejsou. */
    public function minLaterTaxResidual(int $assetId, int $year): ?float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MIN(residual_value_end) FROM depreciation_entries WHERE asset_id = ? AND kind = 'tax' AND fiscal_year > ?"
        );
        $stmt->execute([$assetId, $year]);
        $min = $stmt->fetchColumn();
        return $min === null || $min === false ? null : (float) $min;
    }

    /**
     * Zruší účetní řádek, který nelze smazat, protože na něj odkazuje neměnná historie
     * investičního zdroje. Plán ho přestane vidět a rok se dopočítá znovu; nové
     * zaúčtování roku řádek přepíše přes {@see upsert()}.
     */
    public function void(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE depreciation_entries
                SET amount = 0, full_amount = 0, months_count = NULL, detail = NULL, voided_at = NOW()
              WHERE supplier_id = ? AND id = ?'
        )->execute([$supplierId, $id]);
    }

    /** Smaže oba druhy řádku daného roku (revert vyřazení R24). */
    public function deleteYear(int $assetId, int $fiscalYear): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM depreciation_entries WHERE asset_id = ? AND fiscal_year = ?'
        )->execute([$assetId, $fiscalYear]);
    }

    /** Smaže jeden řádek (kind, rok) — zrušení pauzy §26/8 bez dotčení účetního řádku. */
    public function deleteOne(int $assetId, string $kind, int $fiscalYear): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM depreciation_entries WHERE asset_id = ? AND kind = ? AND fiscal_year = ?'
        )->execute([$assetId, $kind, $fiscalYear]);
    }

    public function lastConfirmedYear(int $assetId, string $kind): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(fiscal_year) FROM depreciation_entries WHERE asset_id = ? AND kind = ? AND voided_at IS NULL'
        );
        $stmt->execute([$assetId, $kind]);
        $max = $stmt->fetchColumn();
        return $max === null || $max === false ? null : (int) $max;
    }

    /** Existuje potvrzený daňový řádek? → zámek tax_* parametrů karty (R13). */
    public function existsAnyTax(int $assetId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM depreciation_entries WHERE asset_id = ? AND kind = 'tax' LIMIT 1"
        );
        $stmt->execute([$assetId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Souhrn roku pro výsledek hromadného zaúčtování (bookYear §3.4).
     *
     * @return array<string, array{count:int, amount:float, full_amount:float}> klíč = kind
     */
    public function yearSummary(int $supplierId, int $fiscalYear): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT kind, COUNT(*) AS cnt, SUM(amount) AS amount_sum, SUM(full_amount) AS full_sum
               FROM depreciation_entries
              WHERE supplier_id = ? AND fiscal_year = ? AND voided_at IS NULL
              GROUP BY kind'
        );
        $stmt->execute([$supplierId, $fiscalYear]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['kind']] = [
                'count' => (int) $r['cnt'],
                'amount' => round((float) $r['amount_sum'], 2),
                'full_amount' => round((float) $r['full_sum'], 2),
            ];
        }
        return $out;
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['asset_id'] = (int) $r['asset_id'];
        $r['fiscal_year'] = (int) $r['fiscal_year'];
        $r['amount'] = (float) $r['amount'];
        $r['full_amount'] = (float) $r['full_amount'];
        $r['residual_value_end'] = (float) $r['residual_value_end'];
        $r['is_paused'] = (bool) $r['is_paused'];
        $r['is_half'] = (bool) $r['is_half'];
        $r['months_count'] = $r['months_count'] === null ? null : (int) $r['months_count'];
        if (array_key_exists('override_original_amount', $r)) {
            $r['override_original_amount'] = $r['override_original_amount'] === null ? null : (float) $r['override_original_amount'];
            $r['override_by'] = $r['override_by'] === null ? null : (int) $r['override_by'];
        }
        return $r;
    }
}
