<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Identifikace zaměstnance/jednatele-společníka pro mzdový list (§38j ZDP).
 *
 * Záměrně jednoduchá evidence — jméno, datum narození a prohlášení poplatníka
 * (sleva na poplatníka, počet dětí). Neduplikuje `PayrollCalculator::types()`
 * (typ poplatníka řídí kontaci 521/331 vs. 522/366), jen ho eviduje spolu s identifikací.
 *
 * ── Rodné číslo a adresa tudy NEVEDOU (W1/P-02) ───────────────────────────────
 * Sloupce `birth_number` a `address` v `payroll_employees` drží legacy hodnoty
 * v OTEVŘENÉM tvaru. Tahle routa je přitom chráněná jen právem `accounting`,
 * tedy dostupná i uživateli bez jediného mzdového práva — plné rodné číslo tak
 * teklo do JSONu mimo `payroll_person_identifiers` (šifrováno, maskováno,
 * s auditní stopou o odhalení). Repository je proto ani nečte, ani nezapisuje:
 * jediná legální cesta k rodnému číslu je nový mzdový modul přes
 * {@see \MyInvoice\Service\Payroll\Security\PayrollPersonSensitiveRevealService}.
 * Sloupce zůstávají v DB kvůli datům, která nikdo nepřesealoval — viz migrace 1611.
 */
final class PayrollEmployeeRepository
{
    private const COLS = 'id, supplier_id, full_name, birth_date,
        taxpayer_type, employment_type, tax_declaration_signed,
        tax_credit_taxpayer, child_count, net_settlement_account_code,
        monthly_gross, auto_post, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForTenant(int $supplierId, ?bool $active = null): array
    {
        $sql = 'SELECT ' . self::COLS . ' FROM payroll_employees WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($active !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $active ? 1 : 0;
        }
        $sql .= ' ORDER BY is_active DESC, full_name ASC, id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @param array<string,mixed> $data */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, birth_date,
                 taxpayer_type, employment_type, tax_declaration_signed,
                 tax_credit_taxpayer, child_count, net_settlement_account_code,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['full_name'],
            $data['birth_date'] ?? null,
            $data['taxpayer_type'],
            $data['employment_type'] ?? 'hpp',
            array_key_exists('tax_declaration_signed', $data) ? (int) (bool) $data['tax_declaration_signed'] : 1,
            (int) (bool) $data['tax_credit_taxpayer'],
            $data['child_count'],
            self::settlementAccountOrNull($data['net_settlement_account_code'] ?? null),
            $data['monthly_gross'] ?? null,
            array_key_exists('auto_post', $data) ? (int) (bool) $data['auto_post'] : 0,
            array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : 1,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Částečný update — jen předané klíče. Vrací true při zásahu do řádku tenanta.
     * @param array<string,mixed> $fields
     */
    public function update(int $supplierId, int $id, array $fields): bool
    {
        // `birth_number` ani `address` v seznamu ZÁMĚRNĚ nejsou — legacy routa
        // nesmí zapisovat otevřené rodné číslo (W1/P-02).
        $allowed = [
            'full_name', 'birth_date',
            'taxpayer_type', 'employment_type', 'tax_declaration_signed',
            'tax_credit_taxpayer', 'child_count', 'net_settlement_account_code',
            'monthly_gross', 'auto_post', 'is_active',
        ];
        $sets = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = ?";
                // `tax_declaration_signed` MUSÍ být v seznamu boolean sloupců — jinak by
                // do TINYINT šlo syrové "false"/"0" z JSONu a MariaDB by z něj udělala 1.
                if (in_array($col, ['tax_credit_taxpayer', 'is_active', 'tax_declaration_signed', 'auto_post'], true)) {
                    $params[] = (int) (bool) $fields[$col];
                } elseif ($col === 'net_settlement_account_code') {
                    // Prázdný řetězec z formuláře znamená „bez přeúčtování" — musí dojít
                    // jako NULL, jinak by kalkulátor hledal účet s prázdným kódem.
                    $params[] = self::settlementAccountOrNull($fields[$col]);
                } else {
                    $params[] = $fields[$col];
                }
            }
        }
        if ($sets === []) {
            return false;
        }
        $params[] = $id;
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_employees SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /** Kód účtu pro přeúčtování čisté mzdy; prázdno i mezery → NULL (bez přeúčtování). */
    private static function settlementAccountOrNull(mixed $value): ?string
    {
        $code = trim((string) ($value ?? ''));
        return $code === '' ? null : $code;
    }

    /** Zaměstnanec s historií mzdových záznamů se nesmí smazat — jen deaktivovat. */
    public function hasMonthlyRecords(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_monthly_records WHERE employee_id = ? AND supplier_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM payroll_employees WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['tax_credit_taxpayer'] = (bool) $r['tax_credit_taxpayer'];
        // Doplněno později (migrace 1156) — bez castu chodí ven TINYINT jako `1`,
        // ne `true`. PHP strana si to castuje sama, ale checkbox ve frontendu
        // porovnává s `true`, takže se podepsané prohlášení zobrazovalo jako
        // NEpodepsané, zatímco server slevu uplatnil. Rozpor UI × zaúčtování.
        $r['tax_declaration_signed'] = (bool) ($r['tax_declaration_signed'] ?? false);
        $r['child_count'] = (int) $r['child_count'];
        // Migrace 1175. `monthly_gross` musí zůstat rozlišitelně NULL — 0 Kč je jiný
        // stav než „pravidelná mzda nesjednaná" a `(int) null` by ty dva slil dohromady.
        $r['monthly_gross'] = ($r['monthly_gross'] ?? null) === null ? null : (int) $r['monthly_gross'];
        $r['auto_post'] = (bool) ($r['auto_post'] ?? false);
        $r['is_active'] = (bool) $r['is_active'];
        return $r;
    }

    /**
     * Aktivní zaměstnanci, které má cron zaúčtovat sám (migrace 1175).
     *
     * Podmínka na `monthly_gross > 0` je součástí dotazu, ne až kontrolou nad výsledkem:
     * zapnutý příznak bez částky není chyba k nahlášení, ale nedokončené nastavení —
     * cron o takovém zaměstnanci nemá co reportovat, protože nemá co účtovat.
     *
     * @return list<array<string,mixed>>
     */
    public function autoPostCandidates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM payroll_employees
              WHERE supplier_id = ? AND is_active = 1 AND auto_post = 1
                AND monthly_gross IS NOT NULL AND monthly_gross > 0
              ORDER BY id ASC'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
