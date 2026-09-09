<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro vat_classifications — kódy MF ČR pro DPH přiznání + KH.
 *
 * Globální seed (supplier_id IS NULL, ze migrace 0037) + per-tenant overrides.
 * Tenant může přidat custom kód, který se aplikuje jen pro něj.
 */
final class VatClassificationRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * List kódů — globální + tenant overrides. Filter na direction (sale/purchase/both).
     *
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $supplierId, ?string $direction = null, bool $includeArchived = false): array
    {
        $where = ['(supplier_id IS NULL OR supplier_id = ?)'];
        $params = [$supplierId];
        if (!$includeArchived) {
            $where[] = 'archived = 0';
        }
        if ($direction !== null) {
            $where[] = '(direction = ? OR direction = "both")';
            $params[] = $direction;
        }
        $sql = 'SELECT id, supplier_id, code, label, direction, dphdp3_line, kh_section,
                       vat_rate, is_reverse_charge, kod_pred_pl, kh_regime_code, kh_bad_debt,
                       display_order, archived, created_at
                  FROM vat_classifications
                 WHERE ' . implode(' AND ', $where) .
               ' ORDER BY supplier_id IS NULL DESC, display_order ASC, code ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn ($r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM vat_classifications WHERE id = ? AND (supplier_id IS NULL OR supplier_id = ?)'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function create(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO vat_classifications
                (supplier_id, code, label, direction, dphdp3_line, kh_section,
                 vat_rate, is_reverse_charge, kod_pred_pl, kh_regime_code, kh_bad_debt, display_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['code'],
            (string) $data['label'],
            in_array($data['direction'] ?? 'both', ['sale', 'purchase', 'both'], true)
                ? $data['direction'] : 'both',
            !empty($data['dphdp3_line']) ? (string) $data['dphdp3_line'] : null,
            !empty($data['kh_section']) ? (string) $data['kh_section'] : null,
            isset($data['vat_rate']) ? (float) $data['vat_rate'] : null,
            !empty($data['is_reverse_charge']) ? 1 : 0,
            self::normalizeKodPredPl($data['kod_pred_pl'] ?? null),
            in_array($data['kh_regime_code'] ?? null, ['0', '1', '2'], true) ? $data['kh_regime_code'] : null,
            in_array($data['kh_bad_debt'] ?? null, ['N', 'P'], true) ? $data['kh_bad_debt'] : null,
            (int) ($data['display_order'] ?? 100),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, int $supplierId, array $data): bool
    {
        // Pozor: globální kódy (supplier_id IS NULL) nelze editovat per-tenant
        $existing = $this->find($id, $supplierId);
        if ($existing === null) return false;
        if ($existing['supplier_id'] === null) {
            throw new \RuntimeException('Globální kódy nelze editovat. Vytvoř custom kód pro váš tenant.');
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE vat_classifications
                SET label = ?, direction = ?, dphdp3_line = ?, kh_section = ?,
                    vat_rate = ?, is_reverse_charge = ?, kod_pred_pl = ?, kh_regime_code = ?, kh_bad_debt = ?,
                    display_order = ?, archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['label'],
            in_array($data['direction'] ?? 'both', ['sale', 'purchase', 'both'], true)
                ? $data['direction'] : 'both',
            !empty($data['dphdp3_line']) ? (string) $data['dphdp3_line'] : null,
            !empty($data['kh_section']) ? (string) $data['kh_section'] : null,
            isset($data['vat_rate']) ? (float) $data['vat_rate'] : null,
            !empty($data['is_reverse_charge']) ? 1 : 0,
            self::normalizeKodPredPl($data['kod_pred_pl'] ?? null),
            in_array($data['kh_regime_code'] ?? null, ['0', '1', '2'], true) ? $data['kh_regime_code'] : null,
            in_array($data['kh_bad_debt'] ?? null, ['N', 'P'], true) ? $data['kh_bad_debt'] : null,
            (int) ($data['display_order'] ?? 100),
            !empty($data['archived']) ? 1 : 0,
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $supplierId): bool
    {
        $existing = $this->find($id, $supplierId);
        if ($existing === null) return false;
        if ($existing['supplier_id'] === null) {
            throw new \RuntimeException('Globální kódy nelze smazat.');
        }
        // Soft: archived=1, hard delete by mohl zlomit FK přes vat_classification_code (varchar, ne id)
        $stmt = $this->db->pdo()->prepare('UPDATE vat_classifications SET archived = 1 WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Kód předmětu plnění pro KH (§ 92b–92f) — normalizace na tvar, který XSD připouští
     * (`maxLength=3`), nebo `null`.
     *
     * Hodnotový výčet je v EXTERNÍM číselníku MFČR, ne v XSD, takže se proti němu
     * validovat nedá — kontroluje se jen tvar. Vymýšlet si vlastní seznam kódů by bylo
     * horší než žádná kontrola: seznam by se s číselníkem rozešel a odmítal by legitimní
     * hodnoty.
     *
     * Do doplnění zápisu byl sloupec jen ČTEN — migrace 0127 do něj plošně nasadila `'4'`
     * (stavební práce) pro všechny tuzemské režimy a uživatel neměl jak to změnit.
     * Dodavatel odpadu, zlata nebo zboží z přílohy 6 tak posílal do KH systematicky
     * špatný kód.
     *
     * ⚠️ Číselník NENÍ jen číselný — má i kódy s písmenným sufixem (`1a` odpad a šrot,
     * `3a`). Normalizace kdysi nechávala jen číslice, takže validace v
     * {@see \MyInvoice\Action\Codebook\VatClassificationsAction} sice `1a` pustila, ale
     * do DB se tiše uložilo `1` (= zlato) — tichý špatný kód předmětu plnění v KH.
     * Písmenný sufix se proto zachovává; ořez na 3 znaky drží XSD limit `maxLength=3`.
     */
    private static function normalizeKodPredPl(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = preg_replace('/[^0-9a-z]/', '', strtolower(trim((string) $value))) ?? '';
        // Kód číselníku vždy začíná číslicí (`4`, `1a`, `3a`). Text, který jí nezačíná,
        // není zkomolený kód, ale něco jiného — dřív z „A.1" vzniklo tiše `1` (zlato).
        if ($normalized === '' || !ctype_digit($normalized[0])) {
            return null;
        }

        return substr($normalized, 0, 3);
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null;
        $r['vat_rate'] = $r['vat_rate'] !== null ? (float) $r['vat_rate'] : null;
        $r['is_reverse_charge'] = (bool) $r['is_reverse_charge'];
        $r['display_order'] = (int) $r['display_order'];
        $r['archived'] = (bool) $r['archived'];
        return $r;
    }
}
