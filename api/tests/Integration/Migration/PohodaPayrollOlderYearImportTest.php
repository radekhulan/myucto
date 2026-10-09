<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayrollTwoYears as Payroll;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod mezd z PAMICA po letech v libovolném pořadí: starší rok doplněný až po novějším
 * (rozhodné období pro NEMPRI) i obráceně a opakovaně.
 *
 *  - Složka, kterou převod zakládá, už má verzi od 1. 1. novějšího roku: starší verze
 *    musí skončit den před ní, jinak každý měsíc staršího roku spadne na překryv verzí.
 *  - Vztah, který zdroj vede jen ve starším roce, patří osobě, která už ve firmě je:
 *    založí se jako její další vztah (druh, nástup a skončení ze zdroje), převzaté úhrny
 *    se na něj navážou a duplicitní osoba nevznikne.
 *
 * Transakce se v tearDown vrací.
 */
#[Group('integration')]
final class PohodaPayrollOlderYearImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PohodaPayrollImporter $importer;
    private int $userId = 0;
    private int $sourceSupplierId = 0;
    private string $tmp = '';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(PohodaPayrollImporter::class);
        foreach (['payroll_attendance_imports', 'payroll_employees', 'payroll_offices', 'pohoda_import_map', 'payroll_migration_reference_totals'] as $table) {
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
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_payroll_years_' . bin2hex(random_bytes(5));
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

    public function testOlderYearAfterNewerKeepsComponentVersionsApart(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = Payroll::write($this->tmp);

        $this->import($supplierId, $file, 2026);
        $older = $this->import($supplierId, $file, 2025);

        self::assertSame(2, self::stepCounts($older, PohodaPayrollImporter::STEP_MONTHS)['months'] ?? 0, $this->explain($older));
        self::assertSame([], self::messages($older, 'payroll_month_failed'), $this->explain($older));
        // Osobní ohodnocení má Alena v obou letech: převod roku 2026 založil verzi od 1. 1. 2026.
        self::assertSame([
            ['2025-01-01', '2025-12-31'],
            ['2026-01-01', null],
        ], $this->componentVersions($supplierId, Payroll::BONUS_CODE), $this->explain($older));
    }

    public function testOlderYearAfterNewerAddsRelationsOfExistingPeople(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = Payroll::write($this->tmp);

        $this->import($supplierId, $file, 2026);
        $older = $this->import($supplierId, $file, 2025);

        $this->assertRelations($supplierId, $older);
        self::assertSame([], self::messages($older, 'person_failed'), $this->explain($older));
        self::assertSame([], self::messages($older, 'person_not_found'), $this->explain($older));

        // Mzdy DPP z roku 2025 patří DPP, ne pracovnímu poměru téže osoby.
        $dpp = $this->employment($supplierId, Payroll::ALENA_DPP);
        $hpp = $this->employment($supplierId, Payroll::ALENA_HPP);
        self::assertSame(400000, $this->scalar(
            "SELECT COALESCE(SUM(i.amount_minor), 0) FROM payroll_inputs i JOIN payroll_component_definitions c ON c.id = i.component_id
              WHERE i.supplier_id = ? AND i.employment_id = ? AND c.code = ? AND i.period_start = '2025-01-01' AND i.status <> 'cancelled'",
            [$supplierId, $dpp['id'], Payroll::HOURLY_CODE],
        ), $this->explain($older));
        self::assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM payroll_inputs i JOIN payroll_component_definitions c ON c.id = i.component_id
              WHERE i.supplier_id = ? AND i.employment_id = ? AND c.code = ?",
            [$supplierId, $hpp['id'], Payroll::HOURLY_CODE],
        ), $this->explain($older));
    }

    public function testNewerYearAfterOlderDoesNotDuplicateRelations(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = Payroll::write($this->tmp);

        $older = $this->import($supplierId, $file, 2025);
        $this->assertRelations($supplierId, $older, false);
        $newer = $this->import($supplierId, $file, 2026);
        $this->assertRelations($supplierId, $newer);
        self::assertSame([], self::messages($newer, 'person_failed'), $this->explain($newer));
        // Únorový doplatek po skončení vztahu ukončeného převodem 2025: varování místo druhé osoby.
        self::assertCount(1, self::messages($newer, 'payslip_outside_employment'), $this->explain($newer));
    }

    /**
     * `SocPojSlevaZadost`/`SocPojSlevaNarok` jsou v PAMICA sleva ZAMĚSTNAVATELE na pojistném
     * (§ 7a zák. č. 589/1992 Sb.), ne sleva pracujícího důchodce. Dřív z nich převod udělal
     * slevu důchodce (Alena, nar. 1990). Teď jde důvod slevy do podmínek pracovního poměru;
     * důvod, který věk osoby vylučuje, se nepřevezme a ohlásí.
     */
    public function testPamicaDiscountFlagIsEmployerPartTimeDiscount(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = Payroll::write($this->tmp);

        $protocol = $this->import($supplierId, $file, 2025);

        $alena = $this->employment($supplierId, Payroll::ALENA_HPP);
        self::assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM payroll_person_social_discount_claims WHERE supplier_id = ? AND employee_id = ? AND status = 'verified'",
            [$supplierId, $alena['employee_id']],
        ), 'Žádost o slevu zaměstnavatele není sleva pracujícího důchodce. ' . $this->explain($protocol));
        $reason = $this->db->pdo()->prepare(
            'SELECT social_part_time_discount_reason FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $reason->execute([$supplierId, $alena['id']]);
        self::assertSame(Payroll::ALENA_DISCOUNT_REASON, $reason->fetchColumn(), $this->explain($protocol));
        $bohumil = $this->employment($supplierId, Payroll::BOHUMIL_HPP);
        $reason->execute([$supplierId, $bohumil['id']]);
        self::assertSame('none', $reason->fetchColumn(), 'Důvod a) u osoby mladší 55 let se nepřebírá.');
        self::assertCount(1, self::messages($protocol, 'part_time_discount_doubtful'), $this->explain($protocol));
        // Bez přijatého záměru OZUSPOJ převod upozorní, že běh slevu zastaví k ručnímu posouzení.
        self::assertCount(1, self::messages($protocol, 'part_time_discount_intent_missing'), $this->explain($protocol));
    }

    public function testRepeatedOlderYearChangesNothing(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = Payroll::write($this->tmp);

        $this->import($supplierId, $file, 2026);
        $this->import($supplierId, $file, 2025);
        $inputs = $this->rows('payroll_inputs', $supplierId);
        $again = $this->import($supplierId, $file, 2025);

        $this->assertRelations($supplierId, $again);
        self::assertSame(2, self::stepCounts($again, PohodaPayrollImporter::STEP_MONTHS)['existing'] ?? 0, $this->explain($again));
        self::assertSame($inputs, $this->rows('payroll_inputs', $supplierId));
    }

    /** Dvě osoby, čtyři vztahy s druhem a daty ze zdroje a převzaté úhrny všech mezd na vztahu. */
    private function assertRelations(int $supplierId, ImportProtocol $protocol, bool $year2026 = true): void
    {
        $open = $year2026 ? [[Payroll::ALENA_OPEN, 'dpp', Payroll::ALENA_OPEN_START, null, 'active', 'Alena Vzorová']] : [];
        self::assertSame(2, $this->rows('payroll_employees', $supplierId), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.code, e.relation_type, e.start_date, e.end_date, e.status, p.full_name
               FROM payroll_employments e JOIN payroll_employees p ON p.id = e.employee_id AND p.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? ORDER BY e.code'
        );
        $stmt->execute([$supplierId]);
        self::assertSame([
            [Payroll::ALENA_HPP, 'employment', '2024-01-01', null, 'active', 'Alena Vzorová'],
            [Payroll::ALENA_DPP, 'dpp', Payroll::ALENA_DPP_START, Payroll::ALENA_DPP_END, 'ended', 'Alena Vzorová'],
            ...$open,
            [Payroll::BOHUMIL_HPP, 'employment', '2025-01-01', Payroll::BOHUMIL_END, 'ended', 'Bohumil Ukázkový'],
            [Payroll::BOHUMIL_DPP, 'dpp', '2025-01-01', Payroll::BOHUMIL_END, 'ended', 'Bohumil Ukázkový'],
        ], array_map(static fn (array $r): array => array_values($r), $stmt->fetchAll(\PDO::FETCH_ASSOC)), $this->explain($protocol));
        self::assertSame(0, $this->scalar(
            'SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ? AND employment_id IS NULL',
            [$supplierId],
        ), $this->explain($protocol));
    }

    private function import(int $supplierId, string $file, int $year): ImportProtocol
    {
        $protocol = $this->importer->run($supplierId, $this->userId, $file, $year, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        return $protocol;
    }

    /** @return list<array{0:string,1:?string}> */
    private function componentVersions(int $supplierId, string $code): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT valid_from, valid_to FROM payroll_component_definitions WHERE supplier_id = ? AND code = ? ORDER BY valid_from'
        );
        $stmt->execute([$supplierId, $code]);

        return array_map(
            static fn (array $r): array => [substr((string) $r['valid_from'], 0, 10), $r['valid_to'] === null ? null : substr((string) $r['valid_to'], 0, 10)],
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    private function payrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        // Oba převáděné roky jsou evidence předchozího programu.
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

    /** @return array{id:int,employee_id:int,status:string,end_date:?string} */
    private function employment(int $supplierId, string $code): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, employee_id, status, end_date FROM payroll_employments WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Vztah {$code} nevznikl.");
        return $row;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function rows(string $table, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
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

    /** @return list<string> texty zpráv s daným kódem ze všech kroků */
    private static function messages(ImportProtocol $protocol, string $code): array
    {
        $out = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $message) {
                if (($message['code'] ?? null) === $code) {
                    $out[] = (string) ($message['text'] ?? '');
                }
            }
        }
        return $out;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
