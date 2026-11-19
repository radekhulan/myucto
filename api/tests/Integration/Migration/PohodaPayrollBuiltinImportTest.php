<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PayrollBuiltin\PohodaMzdyAdapter;
use MyInvoice\Service\Migration\Pohoda\PayrollBuiltin\PohodaMzdyReconciliation;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaMzdy;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod mezd vedených přímo v POHODĚ (agenda Mzdy, bez pracovních poměrů a položek mezd)
 * touž cestou jako mzdy z PAMICA: osoby a vztahy z karet, převzaté měsíce, slevy, nemoc
 * a kontrola převzatých úhrnů proti mzdám POHODY. Transakce se v tearDown vrací.
 */
#[Group('integration')]
final class PohodaPayrollBuiltinImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PohodaPayrollImporter $importer;
    private PohodaMzdyReconciliation $reconciliation;
    private int $userId = 0;
    private int $sourceSupplierId = 0;
    private string $tmp = '';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(PohodaPayrollImporter::class);
        $this->reconciliation = $container->get(PohodaMzdyReconciliation::class);
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
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_mzdy_int_' . bin2hex(random_bytes(5));
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

    public function testImportsPohodaPayrollAndReconcilesWithSource(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaMzdy::write($this->tmp);

        // Náhled průvodce: kontrola před převodem řekne, že mzdy vede POHODA.
        $preflight = $this->importer->preflight($supplierId, $file, SyntheticPohodaMzdy::YEAR);
        self::assertContains(PohodaPayrollImporter::SOURCE_POHODA, array_column($preflight, 'code'));
        self::assertNotContains('error', array_column($preflight, 'level'), json_encode($preflight, JSON_UNESCAPED_UNICODE));

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaMzdy::YEAR, false, approveTakenOver: true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(PohodaMzdyAdapter::VARIANT_POHODA, $protocol->get('payroll_source'));
        self::assertContains(PohodaPayrollImporter::SOURCE_POHODA, $this->codes($protocol));
        $months = self::stepCounts($protocol, PohodaPayrollImporter::STEP_MONTHS);
        self::assertSame(2, $months['months'] ?? 0, $this->explain($protocol));
        self::assertSame(4, $months['payslips'] ?? 0);

        $pdo = $this->db->pdo();
        $employments = $pdo->prepare('SELECT code, relation_type, start_date FROM payroll_employments WHERE supplier_id = ? ORDER BY code');
        $employments->execute([$supplierId]);
        self::assertSame([
            ['code' => 'P001', 'relation_type' => 'employment', 'start_date' => '2024-03-01'],
            ['code' => 'P002', 'relation_type' => 'employment', 'start_date' => '2025-09-01'],
        ], $employments->fetchAll(\PDO::FETCH_ASSOC));

        // Převzaté úhrny: hrubá mzda a výplata po měsících tak, jak je spočítala POHODA.
        $totals = $pdo->prepare(
            'SELECT period_start, COUNT(*) AS payslips, SUM(gross_minor) AS gross, SUM(net_payable_minor) AS payout
               FROM payroll_migration_reference_totals WHERE supplier_id = ? GROUP BY period_start ORDER BY period_start'
        );
        $totals->execute([$supplierId]);
        $expected = [];
        foreach (SyntheticPohodaMzdy::GROSS as $period => $gross) {
            $expected[] = ['period_start' => $period . '-01', 'payslips' => 2, 'gross' => (int) round($gross * 100),
                'payout' => (int) round(SyntheticPohodaMzdy::PAYOUT[$period] * 100)];
        }
        self::assertSame($expected, array_map(static fn (array $r): array => ['period_start' => $r['period_start'], 'payslips' => (int) $r['payslips'],
            'gross' => (int) $r['gross'], 'payout' => (int) $r['payout']], $totals->fetchAll(\PDO::FETCH_ASSOC)));

        // Kontrola převzetí: oba měsíce sedí ve všech úhrnech včetně slev na dani.
        $reconciliation = $protocol->get('pohoda_payroll_reconciliation');
        self::assertIsArray($reconciliation);
        self::assertSame(['2026-01', '2026-02'], array_column($reconciliation, 'period'));
        foreach ($reconciliation as $month) {
            self::assertTrue($month['ok'], $this->explain($protocol));
            foreach ($month['metrics'] as $metric => $values) {
                self::assertSame($values['source'], $values['takeover'], "{$month['period']} {$metric}");
            }
        }
        self::assertSame(257000 + 126700 + 257000, $reconciliation[0]['metrics']['tax_reliefs']['takeover']);

        // Dovolená jde souhrnem docházky, nemoc s daty do evidence nepřítomností.
        $absences = $pdo->prepare('SELECT absence_type, date_from, date_to FROM payroll_absences WHERE supplier_id = ? ORDER BY date_from');
        $absences->execute([$supplierId]);
        self::assertSame([['absence_type' => 'dpn', 'date_from' => '2026-02-16', 'date_to' => '2026-02-27']], $absences->fetchAll(\PDO::FETCH_ASSOC));

        // Převzaté úhrny, které by nesouhlasily s mzdami POHODY, kontrola ukáže jako rozdíl.
        $pdo->prepare("UPDATE payroll_migration_reference_totals SET employer_social_minor = employer_social_minor + 100
                        WHERE supplier_id = ? AND period_start = '2026-02-01' AND external_relationship_ref = '11'")->execute([$supplierId]);
        $prepared = PohodaMzdyAdapter::prepare($file);
        self::assertNotNull($prepared);
        $again = $this->reconciliation->takeover($supplierId, $prepared['months']);
        self::assertTrue($again[0]['ok']);
        self::assertFalse($again[1]['ok']);
        $violations = PohodaMzdyReconciliation::violations([], $again);
        self::assertSame([PohodaMzdyReconciliation::TAKEOVER_MISMATCH], array_column($violations, 'code'));
        self::assertStringContainsString('sociální pojištění zaměstnavatele POHODA 13 640,00 Kč, MyÚčto 13 641,00 Kč', $violations[0]['text']);
    }

    /** Mzda, jejíž sloupce nesedí samy se sebou, je rozdíl k přijetí, ne tiché upozornění. */
    public function testTamperedPayslipIsDifference(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaMzdy::write($this->tmp);
        file_put_contents($file, str_replace('<KcDov>4000</KcDov>', '<KcDov>4500</KcDov>', (string) file_get_contents($file)));

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaMzdy::YEAR, true);
        self::assertTrue($protocol->hasErrors(), $this->explain($protocol));
        self::assertTrue($protocol->acceptableOnly(), $this->explain($protocol));
        self::assertContains(PohodaMzdyReconciliation::SOURCE_MISMATCH, $this->codes($protocol));
        self::assertSame(1, self::stepCounts($protocol, PohodaPayrollImporter::STEP_INVARIANTS)['invariant_violations'] ?? 0, $this->explain($protocol));
    }

    /** Mzdy z PAMICA jdou dál beze změny: bez převodu souboru a bez kontroly proti POHODĚ. */
    public function testPamicaPayrollIsUnchanged(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);

        $protocol = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, true, startDecision: PohodaPayrollImporter::START_KEEP);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertNull($protocol->get('payroll_source'));
        self::assertNull($protocol->get('pohoda_payroll_reconciliation'));
        self::assertNotContains(PohodaPayrollImporter::SOURCE_POHODA, $this->codes($protocol));
        self::assertFileDoesNotExist(dirname($file) . '/' . PohodaMzdyAdapter::ADAPTED_FILE);
    }

    private function payrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        // MyÚčto vede mzdy od března: leden a únor převezme převod jako zpracované v POHODĚ.
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
             VALUES (?, ?, "2024-01-01", "1234567890", "synthetic:pohoda-mzdy")',
        )->execute([$supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$supplierId, $officeId]);
        return $supplierId;
    }

    /** @return list<string> */
    private function codes(ImportProtocol $protocol): array
    {
        return array_column(array_merge(...array_column($protocol->toArray()['steps'], 'messages')), 'code');
    }

    /** @return array<string,int|float> */
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
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
