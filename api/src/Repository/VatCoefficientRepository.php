<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro vat_coefficients (C2', audit 2026-07, vat) — zálohový a vypořádací
 * koeficient krácení nároku na odpočet dle § 76 ZDPH, per (firma, rok).
 *
 * Zálohový koeficient (provisional_percent, § 76 odst. 6) se během roku uplatňuje na
 * ř. 52 DPHDP3; vypořádací (final_percent, § 76 odst. 7) se spočte ze skutečných dat
 * celého roku a uloží až explicitním vypořádáním (nikdy jako vedlejší efekt náhledu).
 */
final class VatCoefficientRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{supplier_id:int, year:int, provisional_percent:?int, final_percent:?int,
     *   numerator_czk:?int, denominator_czk:?int, settled_at:?string, settled_by:?int}|null
     */
    public function get(int $supplierId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, year, provisional_percent, final_percent,
                    numerator_czk, denominator_czk, settled_at, settled_by
               FROM vat_coefficients
              WHERE supplier_id = ? AND year = ?'
        );
        $stmt->execute([$supplierId, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'supplier_id'         => (int) $row['supplier_id'],
            'year'                => (int) $row['year'],
            'provisional_percent' => $row['provisional_percent'] === null ? null : (int) $row['provisional_percent'],
            'final_percent'       => $row['final_percent'] === null ? null : (int) $row['final_percent'],
            'numerator_czk'       => $row['numerator_czk'] === null ? null : (int) $row['numerator_czk'],
            'denominator_czk'     => $row['denominator_czk'] === null ? null : (int) $row['denominator_czk'],
            'settled_at'          => $row['settled_at'] !== null ? (string) $row['settled_at'] : null,
            'settled_by'          => $row['settled_by'] === null ? null : (int) $row['settled_by'],
        ];
    }

    /**
     * Zálohový koeficient POUŽITELNÝ pro daný rok (§ 76 odst. 6). Priorita:
     *   1) ruční provisional_percent pro daný rok (kvalifikovaný odhad účetní),
     *   2) auto carry-forward: final_percent (vypořádací) z PŘEDCHOZÍHO roku, pokud je
     *      vypořádán (settled_at IS NOT NULL) — dle § 76 odst. 6 „koeficient vypočtený
     *      z údajů za zdaňovací období předcházejícího kalendářního roku".
     * Vrátí null, když ani jedno neexistuje → účetní MUSÍ koeficient explicitně zadat
     * (žádný tichý default 0/100 %).
     */
    public function resolveProvisionalPercent(int $supplierId, int $year): ?int
    {
        $current = $this->get($supplierId, $year);
        if ($current !== null && $current['provisional_percent'] !== null) {
            return $current['provisional_percent'];
        }
        $prev = $this->get($supplierId, $year - 1);
        if ($prev !== null && $prev['final_percent'] !== null && $prev['settled_at'] !== null) {
            return $prev['final_percent'];
        }
        return null;
    }

    public static function deductionPercent(?array $current, ?array $previous): ?int
    {
        $percent = $current['final_percent'] ?? $current['provisional_percent']
            ?? (($previous['settled_at'] ?? null) !== null ? ($previous['final_percent'] ?? null) : null);
        return $percent === null ? null : max(0, min(100, (int) $percent));
    }

    /**
     * Ruční nastavení zálohového koeficientu pro rok (účetní/admin). Nedotýká se
     * vypořádacích sloupců (final/numerator/denominator/settled_*) — partial upsert.
     */
    public function setProvisionalPercent(int $supplierId, int $year, int $percent): void
    {
        $percent = max(0, min(100, $percent));
        $this->db->pdo()->prepare(
            'INSERT INTO vat_coefficients (supplier_id, year, provisional_percent)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE provisional_percent = VALUES(provisional_percent)'
        )->execute([$supplierId, $year, $percent]);
    }

    /**
     * Explicitní vypořádání roku (§ 76 odst. 7) — uloží vypořádací koeficient + čitatel/
     * jmenovatel + kdo/kdy. Volá se JEN z explicitní admin akce, nikdy z build()/download()
     * (dorevize B8: readonly GET nesmí mutovat účetní stav). Idempotentní přes upsert.
     */
    public function settleYear(
        int $supplierId,
        int $year,
        int $finalPercent,
        int $numeratorCzk,
        int $denominatorCzk,
        ?int $settledBy,
    ): void {
        $finalPercent = max(0, min(100, $finalPercent));
        $this->db->pdo()->prepare(
            'INSERT INTO vat_coefficients
                (supplier_id, year, final_percent, numerator_czk, denominator_czk, settled_at, settled_by)
             VALUES (?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                 final_percent   = VALUES(final_percent),
                 numerator_czk   = VALUES(numerator_czk),
                 denominator_czk = VALUES(denominator_czk),
                 settled_at      = VALUES(settled_at),
                 settled_by      = VALUES(settled_by)'
        )->execute([$supplierId, $year, $finalPercent, $numeratorCzk, $denominatorCzk, $settledBy]);
    }
}
