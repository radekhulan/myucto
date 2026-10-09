<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverInvariants;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayrollTwoYears as Payroll;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Brána G2: invarianty převzetí mezd nad syntetickým převodem PAMICA se souběžnými
 * vztahy (Alena HPP a DPP, Bohumil HPP a DPP v roce 2025).
 *
 *  - čistý převod nemá žádné porušení a protokol to říká krokem kontroly,
 *  - opakovaný převod týchž dat nic nezmění (ani verze řádků),
 *  - pořadí let nemění výsledek,
 *  - každý invariant porušení opravdu najde a převod skončí rozdílem k přijetí.
 *
 * Fiktivní osoby, transakce se v tearDown vrací.
 */
#[Group('integration')]
final class PayrollTakeoverInvariantsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PohodaPayrollImporter $importer;
    private PayrollTakeoverInvariants $invariants;
    private int $userId = 0;
    private int $sourceSupplierId = 0;
    private string $tmp = '';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(PohodaPayrollImporter::class);
        $this->invariants = $container->get(PayrollTakeoverInvariants::class);
        foreach (['payroll_employees', 'payroll_migration_reference_totals', 'payroll_takeover_invariant_checks', 'pohoda_import_map'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'payroll_invariants_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testCleanTakeoverWithConcurrentRelationsHasNoViolation(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = Payroll::write($this->tmp);

        foreach ([2025, 2026] as $year) {
            $protocol = $this->import($supplierId, $file, $year);
            self::assertSame(0, self::stepCounts($protocol, PohodaPayrollImporter::STEP_INVARIANTS)['invariant_violations'] ?? null, $this->explain($protocol));
        }
        self::assertSame([], $this->invariants->live($supplierId));
        $stored = $this->invariants->stored($supplierId);
        self::assertSame(['pamica'], array_column($stored, 'source'));
        self::assertSame([], $stored[0]['violations']);

        // Souběžné vztahy: každý má vlastní převzatý úhrn a součet osoby sedí na zdroj.
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.code, SUM(t.gross_minor) FROM payroll_migration_reference_totals t
               JOIN payroll_employments e ON e.supplier_id = t.supplier_id AND e.id = t.employment_id
              WHERE t.supplier_id = ? AND t.period_start = '2025-01-01' GROUP BY e.code ORDER BY e.code"
        );
        $stmt->execute([$supplierId]);
        self::assertSame([Payroll::ALENA_HPP => 3_200_000, Payroll::ALENA_DPP => 400_000, Payroll::BOHUMIL_HPP => 3_200_000, Payroll::BOHUMIL_DPP => 400_000],
            array_map('intval', $stmt->fetchAll(\PDO::FETCH_KEY_PAIR)));
    }

    public function testRepeatedTakeoverChangesNothing(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = Payroll::write($this->tmp);
        $this->import($supplierId, $file, 2025);
        $this->import($supplierId, $file, 2026);
        $before = $this->snapshot($supplierId, true);

        $this->import($supplierId, $file, 2025);
        $this->import($supplierId, $file, 2026);

        self::assertSame($before, $this->snapshot($supplierId, true));
    }

    public function testYearOrderDoesNotChangeTheResult(): void
    {
        $file = Payroll::write($this->tmp);
        $ascending = $this->payrollSupplier();
        $this->import($ascending, $file, 2025);
        $this->import($ascending, $file, 2026);
        $descending = $this->payrollSupplier();
        $this->import($descending, $file, 2026);
        $this->import($descending, $file, 2025);

        self::assertSame([], $this->invariants->live($descending));
        self::assertSame($this->snapshot($ascending, false), $this->snapshot($descending, false));
    }

    public function testEachInvariantFindsItsViolation(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = Payroll::write($this->tmp);
        // Novější rok dřív: složka má verzi za každý rok.
        $this->import($supplierId, $file, 2026);
        $this->import($supplierId, $file, 2025);
        $pdo = $this->db->pdo();
        $hpp = $this->employmentId($supplierId, Payroll::ALENA_HPP);
        $dpp = $this->employmentId($supplierId, Payroll::BOHUMIL_DPP);

        // Převzatý úhrn bez vztahu.
        $pdo->prepare("UPDATE payroll_migration_reference_totals SET employment_id = NULL WHERE supplier_id = ? AND employment_id = ? AND period_start = '2025-02-01'")
            ->execute([$supplierId, $dpp]);
        // Verze mzdové složky a podmínek bez konce, za kterými následuje další.
        $pdo->prepare("UPDATE payroll_component_definitions SET valid_to = NULL WHERE supplier_id = ? AND code = ? AND valid_from = '2025-01-01'")
            ->execute([$supplierId, Payroll::BONUS_CODE]);
        // Druhá verze podmínek uprostřed první, která nemá konec.
        $pdo->prepare('CREATE TEMPORARY TABLE g2_terms AS SELECT * FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from LIMIT 1')
            ->execute([$supplierId, $hpp]);
        $pdo->exec("UPDATE g2_terms SET id = 0, effective_from = '2025-06-01', effective_to = NULL");
        $pdo->exec('INSERT INTO payroll_employment_terms SELECT * FROM g2_terms');
        $pdo->exec('DROP TEMPORARY TABLE g2_terms');
        $pdo->prepare("UPDATE payroll_employment_terms SET effective_to = NULL WHERE supplier_id = ? AND employment_id = ? AND effective_from < '2025-06-01'")
            ->execute([$supplierId, $hpp]);
        // Táž osoba zdroje na dvou osobách MyÚčta.
        $pdo->prepare("UPDATE payroll_migration_reference_totals t
                         JOIN payroll_employments e ON e.supplier_id = t.supplier_id AND e.code = ?
                          SET t.employee_id = e.employee_id
                        WHERE t.supplier_id = ? AND t.employment_id = ? AND t.period_start = '2025-01-01'")
            ->execute([Payroll::BOHUMIL_HPP, $supplierId, $hpp]);

        $codes = array_column($this->invariants->live($supplierId), 'code');
        foreach ([PayrollTakeoverInvariants::ORPHAN_TOTALS, PayrollTakeoverInvariants::COMPONENT_OVERLAP,
            PayrollTakeoverInvariants::TERMS_OVERLAP, PayrollTakeoverInvariants::DUPLICATE_PERSON] as $code) {
            self::assertContains($code, $codes);
        }

        // Převod narazí na tytéž porušení: chyba převodu, kterou je třeba přijmout.
        $protocol = $this->importer->run($supplierId, $this->userId, $file, 2026, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
        self::assertSame('failed', $protocol->status(), $this->explain($protocol));
        self::assertTrue($protocol->acceptableOnly(), $this->explain($protocol));
        $stored = array_column($this->invariants->stored($supplierId)[0]['violations'], 'code');
        self::assertNotContains(PayrollTakeoverInvariants::ORPHAN_TOTALS, $stored, 'Kontroly vlastních dat počítá stránka znovu, uložené jsou jen ty proti zdroji.');
    }

    public function testSourceTotalsAreComparedPerPersonAndMonth(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = Payroll::write($this->tmp);
        $this->import($supplierId, $file, 2025);
        $dpp = $this->employmentId($supplierId, Payroll::ALENA_DPP);
        $row = $this->db->pdo()->prepare(
            "SELECT external_person_ref, external_relationship_ref FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND employment_id = ? AND period_start = '2025-01-01'"
        );
        $row->execute([$supplierId, $dpp]);
        [$person, $relation] = $row->fetch(\PDO::FETCH_NUM);
        // Zdroj nese o 1 Kč vyšší hrubou mzdu a vztah, který MyÚčto nemá.
        $source = [
            self::total('2025-01', (string) $person, (string) $relation, 400_100),
            self::total('2025-01', (string) $person, 'chybejici-vztah', 50_000),
        ];

        $violations = $this->invariants->verify($supplierId, 'pamica', $source, 1, false);

        $codes = array_column($violations, 'code');
        self::assertContains(PayrollTakeoverInvariants::TOTALS_MISMATCH, $codes);
        self::assertContains(PayrollTakeoverInvariants::RELATION_MISSING, $codes);
        self::assertContains(PayrollTakeoverInvariants::SOURCE_ROWS_SKIPPED, $codes);
        $mismatch = array_values(array_filter($violations, static fn (array $v): bool => $v['code'] === PayrollTakeoverInvariants::TOTALS_MISMATCH))[0];
        // Osoba má v lednu i pracovní poměr (3 200 000) - zdroj ho tu nenese, proto zdroj = 450 100.
        self::assertSame(['source_minor' => 450_100, 'takeover_minor' => 3_600_000], $mismatch['context']['differences']['gross']);
    }

    private static function total(string $period, string $person, string $relation, int $gross): PayrollMigrationReferenceTotals
    {
        return new PayrollMigrationReferenceTotals($period, $person, $relation, null, null, $gross, $gross, 0, 0, 0, 0, 0, 0, 0, 0, 0);
    }

    /**
     * Stav mezd firmy bez technických id: osoby, vztahy, podmínky, složky, převzaté úhrny,
     * vstupy, nepřítomnosti a pracovní měsíce. S verzemi řádků pozná i přepis na stejnou hodnotu.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function snapshot(int $supplierId, bool $withVersions): array
    {
        $version = static fn (string $alias): string => $withVersions ? ", {$alias}.row_version" : '';
        $queries = [
            'employees' => 'SELECT p.full_name, p.birth_date FROM payroll_employees p WHERE p.supplier_id = ? ORDER BY 1, 2',
            'employments' => 'SELECT e.code, e.relation_type, e.start_date, e.end_date, e.status, p.full_name' . $version('e') . '
                FROM payroll_employments e JOIN payroll_employees p ON p.supplier_id = e.supplier_id AND p.id = e.employee_id
                WHERE e.supplier_id = ? ORDER BY 1',
            'terms' => 'SELECT e.code, t.effective_from, t.effective_to, t.monthly_gross_minor, t.weekly_hours, t.workload_basis_points,
                    t.activity_code, t.social_part_time_discount_reason, t.tax_regime' . $version('t') . '
                FROM payroll_employment_terms t JOIN payroll_employments e ON e.supplier_id = t.supplier_id AND e.id = t.employment_id
                WHERE t.supplier_id = ? ORDER BY 1, 2, 3',
            'components' => 'SELECT d.code, d.valid_from, d.valid_to, d.component_kind, d.is_active' . $version('d') . '
                FROM payroll_component_definitions d WHERE d.supplier_id = ? ORDER BY 1, 2',
            'recurring' => 'SELECT e.code, d.code AS component, r.calculation_kind, r.amount_minor, r.valid_from, r.valid_to, r.is_active' . $version('r') . '
                FROM payroll_recurring_components r
                JOIN payroll_employments e ON e.supplier_id = r.supplier_id AND e.id = r.employment_id
                JOIN payroll_component_definitions d ON d.supplier_id = r.supplier_id AND d.id = r.component_id
                WHERE r.supplier_id = ? ORDER BY 1, 2, 5',
            'totals' => 'SELECT t.source, t.period_start, e.code, t.external_person_ref, t.external_relationship_ref, t.gross_minor,
                    t.social_base_minor, t.health_base_minor, t.employee_social_minor, t.employee_health_minor, t.advance_tax_minor,
                    t.relationship_start_date, t.relationship_end_date, t.relation_type
                FROM payroll_migration_reference_totals t
                LEFT JOIN payroll_employments e ON e.supplier_id = t.supplier_id AND e.id = t.employment_id
                WHERE t.supplier_id = ? ORDER BY 1, 2, 5',
            'inputs' => 'SELECT e.code, d.code AS component, i.period_start, i.amount_minor, i.quantity_milliunits, i.status' . $version('i') . '
                FROM payroll_inputs i
                JOIN payroll_employments e ON e.supplier_id = i.supplier_id AND e.id = i.employment_id
                JOIN payroll_component_definitions d ON d.supplier_id = i.supplier_id AND d.id = i.component_id
                WHERE i.supplier_id = ? ORDER BY 1, 2, 3, 4, 6',
            'absences' => 'SELECT e.code, a.absence_type, a.date_from, a.date_to, a.status, a.lone_carer' . $version('a') . '
                FROM payroll_absences a JOIN payroll_employments e ON e.supplier_id = a.supplier_id AND e.id = a.employment_id
                WHERE a.supplier_id = ? ORDER BY 1, 3, 2',
            'time_months' => 'SELECT e.code, m.period_start, m.status, m.work_source' . $version('m') . '
                FROM payroll_time_months m JOIN payroll_employments e ON e.supplier_id = m.supplier_id AND e.id = m.employment_id
                WHERE m.supplier_id = ? ORDER BY 1, 2',
        ];
        $out = [];
        foreach ($queries as $key => $sql) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId]);
            $out[$key] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        if (!$withVersions) {
            $out['components'] = self::mergedVersions($out['components']);
        }

        return $out;
    }

    /**
     * Navazující verze složky se stejným obsahem jsou táž složka: převod staršího roku
     * po novějším ji rozdělí na hranici roku, v pořadí let zůstane jedna verze.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function mergedVersions(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $last = $out === [] ? null : array_key_last($out);
            $same = $last !== null
                && array_diff_key($out[$last], ['valid_from' => 1, 'valid_to' => 1]) == array_diff_key($row, ['valid_from' => 1, 'valid_to' => 1])
                && $out[$last]['valid_to'] !== null
                && (new \DateTimeImmutable((string) $out[$last]['valid_to']))->modify('+1 day')->format('Y-m-d') === substr((string) $row['valid_from'], 0, 10);
            if ($same) {
                $out[$last]['valid_to'] = $row['valid_to'];
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function import(int $supplierId, string $file, int $year): ImportProtocol
    {
        $protocol = $this->importer->run($supplierId, $this->userId, $file, $year, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        return $protocol;
    }

    private function employmentId(int $supplierId, string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, "Vztah {$code} nevznikl.");

        return $id;
    }

    private function payrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-03-01", ?, NOW())',
        )->execute([$supplierId, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "IMP", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2024-01-01", "1234567890", "synthetic:pohoda-payroll")',
        )->execute([$supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$supplierId, $officeId]);

        return $supplierId;
    }

    /** @return array<string,int> */
    private static function stepCounts(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step['counts'];
            }
        }

        return [];
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
