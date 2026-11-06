<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro definice výkazů (Epic F2) — statement_versions, statement_rows,
 * statement_account_map. Mapování je GLOBÁLNÍ (bez supplier_id), verzované v čase
 * přes valid_from/valid_to (R4).
 */
final class StatementDefinitionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Verze výkazu platná k rozvahovému dni (R4): valid_from <= asOf a
     * (valid_to IS NULL nebo valid_to >= asOf).
     *
     * @param 'balance_sheet'|'income_statement'|'income_statement_purpose' $statementType
     * @return array<string,mixed>|null
     */
    public function findVersion(string $statementType, string $asOf, string $framework = 'cz_500'): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, statement_type, version_code, valid_from, valid_to
               FROM statement_versions
              WHERE statement_type = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) AND framework = ?
              ORDER BY valid_from DESC
              LIMIT 1'
        );
        $stmt->execute([$statementType, $asOf, $asOf, $framework]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** @return array<string,mixed>|null */
    public function findVersionById(int $versionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, statement_type, version_code, valid_from, valid_to
               FROM statement_versions
              WHERE id = ?'
        );
        $stmt->execute([$versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /**
     * Řádky výkazu dané verze v pořadí výkazu (position).
     *
     * @return list<array<string,mixed>>
     */
    public function rows(int $versionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, row_code, parent_row_code, section, label, level, position, row_type, calc_key
               FROM statement_rows
              WHERE version_id = ?
              ORDER BY position ASC'
        );
        $stmt->execute([$versionId]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['level'] = (int) $r['level'];
            $r['position'] = (int) $r['position'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function rows504(int $versionId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT adis_sentence AS sentence, adis_row AS number, display_code, reduced_scope AS reduced, label
            FROM statement_rows WHERE version_id = ? ORDER BY position');
        $stmt->execute([$versionId]);
        return array_map(static function (array $r): array {
            $r['number'] = (int) $r['number'];
            $r['reduced'] = (bool) $r['reduced'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Kompletní mapa účtů dané verze — indexaci podle prefixu si dělá service.
     *
     * @return list<array<string,mixed>>
     */
    public function accountMap(int $versionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, row_code, account_prefix, target, balance_condition, sign
               FROM statement_account_map
              WHERE version_id = ?'
        );
        $stmt->execute([$versionId]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['sign'] = (int) $r['sign'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Řádek VZZ v účelovém členění pro danou funkci (vyhl. 500/2002 př. 2 část II). */
    public const FUNCTION_ROWS = [
        'cost_of_sales'  => 'A.',
        'distribution'   => 'B.',
        'administration' => 'C.',
    ];

    /**
     * PER-FIRMA přiřazení nákladových účtů funkci — řádky A. / B. / C. účelového VZZ.
     *
     * Na rozdíl od {@see accountMap()} tohle globální být nemůže: funkce, které náklad
     * slouží, není vlastnost účtu. Tytéž Služby (518) jsou u jedné firmy náklad prodeje
     * a u druhé správní režie, a zpravidla se dělí mezi víc funkcí přes analytiky.
     *
     * Vrací se ve tvaru {@see accountMap()}, aby se daly obě mapy prostě slít a platila
     * pro ně stejná pravidla nejdelšího prefixu.
     *
     * @return list<array<string,mixed>>
     */
    public function functionMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, account_prefix, function_code
               FROM statement_function_map
              WHERE supplier_id = ?
              ORDER BY account_prefix'
        );
        $stmt->execute([$supplierId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rowCode = self::FUNCTION_ROWS[(string) $r['function_code']] ?? null;
            if ($rowCode === null) {
                continue;
            }
            $out[] = [
                'id'                => (int) $r['id'],
                'row_code'          => $rowCode,
                'account_prefix'    => (string) $r['account_prefix'],
                'target'            => 'gross',
                'balance_condition' => 'any',
                'sign'              => 1,
            ];
        }

        return $out;
    }

    /**
     * Přiřazení účtu funkci (upsert).
     *
     * @param 'cost_of_sales'|'distribution'|'administration' $functionCode
     */
    public function setFunctionMapping(int $supplierId, string $accountPrefix, string $functionCode, ?int $userId = null): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO statement_function_map (supplier_id, account_prefix, function_code, created_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE function_code = VALUES(function_code)'
        );
        $stmt->execute([$supplierId, $accountPrefix, $functionCode, $userId]);
    }

    /** Zruší přiřazení účtu funkci. */
    public function deleteFunctionMapping(int $supplierId, string $accountPrefix): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM statement_function_map WHERE supplier_id = ? AND account_prefix = ?'
        );
        $stmt->execute([$supplierId, $accountPrefix]);
    }
}
