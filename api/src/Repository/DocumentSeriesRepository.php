<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro accounting_document_series — číselné řady deníku (Epic F4,
 * R13). Řádek řady vzniká lazy při prvním výdeji čísla (ensure); výdej drží
 * zámek SELECT ... FOR UPDATE v transakci volajícího (DocumentSeriesService).
 * UNIQUE (supplier_id, series_code, fiscal_year, register_id), kde register_id = 0
 * je společná řada firmy a >0 vlastní řada: pokladny u cash_in/cash_out (L-3, migrace
 * 1506), druhu ostatní položky u other_receivable/other_payable (migrace 2228).
 */
final class DocumentSeriesRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Lazy založení řádku řady s výchozím prefixem — existující řádek se
     * NEMĚNÍ (INSERT ... ON DUPLICATE KEY UPDATE id=id).
     */
    public function ensure(
        int $supplierId,
        string $seriesCode,
        int $fiscalYear,
        string $defaultPrefix,
        int $registerId = 0,
        ?string $numberFormat = null,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_document_series (supplier_id, series_code, fiscal_year, register_id, prefix, number_format, next_number)
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE id = id'
        )->execute([$supplierId, $seriesCode, $fiscalYear, $registerId, $defaultPrefix, $numberFormat]);
    }

    /**
     * Zamkne řádek řady pro výdej čísla — MUSÍ běžet uvnitř transakce
     * (FOR UPDATE mimo transakci zámek okamžitě pouští).
     *
     * @return array{id:int, prefix:string, number_format:string|null, next_number:int}|null
     */
    public function lockRow(int $supplierId, string $seriesCode, int $fiscalYear, int $registerId = 0): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, prefix, number_format, next_number
               FROM accounting_document_series
              WHERE supplier_id = ? AND series_code = ? AND fiscal_year = ? AND register_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $seriesCode, $fiscalYear, $registerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'            => (int) $row['id'],
            'prefix'        => (string) $row['prefix'],
            'number_format' => $row['number_format'] !== null ? (string) $row['number_format'] : null,
            'next_number'   => (int) $row['next_number'],
        ];
    }

    /**
     * Posune čítač po výdeji čísla (volá se nad zamčeným řádkem).
     */
    public function bumpNextNumber(int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE accounting_document_series SET next_number = next_number + 1 WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * Řady firmy (všechny roky) pro správu prefixů.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.supplier_id, s.series_code, s.register_id, s.fiscal_year, s.prefix,
                    s.number_format, s.next_number, s.created_at, s.updated_at, r.name AS register_name,
                    k.name AS kind_name, k.builtin_code AS kind_builtin_code
               FROM accounting_document_series s
          LEFT JOIN cash_registers r ON r.id = s.register_id AND r.supplier_id = s.supplier_id
                AND s.series_code IN ('cash_in', 'cash_out')
          LEFT JOIN other_item_kinds k ON k.id = s.register_id AND k.supplier_id = s.supplier_id
                AND s.series_code IN ('other_receivable', 'other_payable')
              WHERE s.supplier_id = ?
              ORDER BY s.fiscal_year DESC, s.series_code, s.register_id"
        );
        $stmt->execute([$supplierId]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['supplier_id'] = (int) $r['supplier_id'];
            $r['register_id'] = (int) $r['register_id'];
            $r['fiscal_year'] = (int) $r['fiscal_year'];
            $r['next_number'] = (int) $r['next_number'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Smaže všechny roky jedné vlastní řady (register_id > 0). */
    public function deleteScope(int $supplierId, string $seriesCode, int $registerId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM accounting_document_series WHERE supplier_id = ? AND series_code = ? AND register_id = ?'
        )->execute([$supplierId, $seriesCode, $registerId]);
    }

    /**
     * Nastaví prefix / šablonu čísla / čítač řady; řádek případně lazy založí
     * (edit smí předcházet prvnímu výdeji čísla). Přepisují se jen klíče
     * přítomné v $patch — ostatní sloupce zůstávají.
     *
     * @param array{prefix?:string, number_format?:string|null, next_number?:int} $patch
     */
    public function upsert(
        int $supplierId,
        string $seriesCode,
        int $fiscalYear,
        string $defaultPrefix,
        array $patch,
        int $registerId = 0,
    ): bool {
        $updates = [];
        foreach (['prefix', 'number_format', 'next_number'] as $col) {
            if (array_key_exists($col, $patch)) {
                $updates[] = $col . ' = VALUES(' . $col . ')';
            }
        }
        if ($updates === []) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO accounting_document_series (supplier_id, series_code, fiscal_year, register_id, prefix, number_format, next_number)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        );
        $stmt->execute([
            $supplierId,
            $seriesCode,
            $fiscalYear,
            $registerId,
            $patch['prefix'] ?? $defaultPrefix,
            $patch['number_format'] ?? null,
            $patch['next_number'] ?? 1,
        ]);
        return true;
    }
}
