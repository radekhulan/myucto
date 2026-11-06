<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPersonAnonymizationRepository;
use MyInvoice\Service\ActivityLogger;
use PHPUnit\Framework\TestCase;

/**
 * Přehled zbytků po anonymizaci musí hlásit i zmrazené mzdové běhy a archivy
 * mezd za období, kdy osoba dostala výplatu, jinak schvalovatel vidí neúplný seznam.
 */
final class PayrollAnonymizationResidueTest extends TestCase
{
    public function testResidueListsRunSnapshotsAndPeriodArchives(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (PayrollPersonAnonymizationRepository::residueTables() as $table) {
            if (!in_array($table, PayrollPersonAnonymizationRepository::derivedResidueTables(), true)
                && $table !== 'payroll_run_persons') {
                $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, supplier_id INTEGER, employee_id INTEGER)");
            }
        }
        $pdo->exec('CREATE TABLE payroll_run_revisions (id INTEGER PRIMARY KEY, supplier_id INTEGER, run_id INTEGER)');
        $pdo->exec('CREATE TABLE payroll_run_persons (id INTEGER PRIMARY KEY, supplier_id INTEGER, revision_id INTEGER, period_start TEXT, employee_id INTEGER)');
        $pdo->exec('CREATE TABLE payroll_period_exports (id INTEGER PRIMARY KEY, supplier_id INTEGER, period_start TEXT, period_end TEXT)');

        // Osoba 7 ve dvou revizích běhu za březen, osoba 8 jen v dubnu, cizí firma 2.
        $pdo->exec("INSERT INTO payroll_run_revisions VALUES (1, 1, 10), (2, 1, 10), (3, 1, 11), (4, 2, 12)");
        $pdo->exec("INSERT INTO payroll_run_persons VALUES
            (1, 1, 1, '2026-03-01', 7), (2, 1, 2, '2026-03-01', 7), (3, 1, 3, '2026-04-01', 8), (4, 2, 4, '2026-03-01', 7)");
        $pdo->exec("INSERT INTO payroll_period_exports VALUES
            (1, 1, '2026-03-01', '2026-03-31'), (2, 1, '2026-01-01', '2026-12-31'), (3, 1, '2026-04-01', '2026-04-30'), (4, 2, '2026-03-01', '2026-03-31')");
        $pdo->exec('INSERT INTO payroll_generated_documents VALUES (1, 1, 7)');

        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $repository = new PayrollPersonAnonymizationRepository($db, $this->createStub(ActivityLogger::class));

        self::assertSame([
            'payroll_generated_documents' => 1,
            'payroll_run_persons' => 2,
            'payroll_run_revisions' => 2,
            'payroll_period_exports' => 2,
        ], $repository->residue(1, 7));
        self::assertSame([], $repository->residue(1, 99));
    }
}
