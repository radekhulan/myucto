<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\StereoNx\StereoNxEmployees;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportMap;
use MyInvoice\Service\Migration\StereoNx\StereoNxPayrollMonths;
use MyInvoice\Service\Migration\StereoNx\StereoNxPayrollWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxPayrollTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class StereoNxPayrollWriterTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ContainerInterface $container;
    private StereoNxPayrollWriter $writer;
    private int $supplierId;
    private int $userId;
    private int $templateId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildApp()->getContainer();
        $this->db = $this->container->get(Connection::class);
        $this->writer = $this->container->get(StereoNxPayrollWriter::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $this->templateId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $this->templateId);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createPayrollSupplier();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testMonthIsReadableRepeatPreservesRowsAndRollbackRemovesIt(): void
    {
        $pdo = $this->db->pdo();
        $plan = $this->plan();
        $pdo->exec('SAVEPOINT payroll_dry');
        self::assertSame(1, $this->writer->write($plan, $this->supplierId)['counts']['historical_payroll_created']);
        $pdo->exec('ROLLBACK TO SAVEPOINT payroll_dry');
        $pdo->exec('RELEASE SAVEPOINT payroll_dry');
        self::assertSame([], $this->rows());
        self::assertSame(1, $this->writer->write($plan, $this->supplierId)['counts']['historical_payroll_created']);
        $before = $this->rows();
        self::assertSame(1, $this->writer->write($plan, $this->supplierId)['counts']['historical_payroll_existing']);
        self::assertSame($before, $this->rows());
        $year = $this->container->get(PayrollTakeoverReader::class)->forEmployee($this->supplierId, (int) $before[0]['employee_id'], 2026);
        self::assertSame(['stereo_nx'], $year->sources());
        self::assertSame(['2026-01'], $year->takeoverPeriods());
        self::assertSame(1_000_000, (int) $before[0]['gross_minor']);
        self::assertSame(839_000, (int) $before[0]['net_payable_minor']);
        foreach (['journal_entries', 'payroll_runs'] as $table) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
            $stmt->execute([$this->supplierId]);
            self::assertSame(0, (int) $stmt->fetchColumn(), $table);
        }
    }

    public function testStartAndLaterMonthsAreSkippedWhileEarlierMonthIsTransferred(): void
    {
        $plan = $this->plan();
        foreach (['2026-02', '2026-03'] as $index => $period) {
            $record = $plan['records'][0];
            $record['source_key'] = 'later-' . $index;
            $record['period'] = $period;
            $plan['records'][] = self::rehash($record);
        }
        $result = $this->writer->write($plan, $this->supplierId);
        self::assertSame(1, $result['counts']['historical_payroll_created']);
        self::assertSame(2, $result['counts']['historical_payroll_skipped']);
        self::assertSame(['payroll_period_not_historical'], array_column($result['warnings'], 'code'));
    }

    public function testMissingStartDoesNotInventHistoricalBoundary(): void
    {
        $this->db->pdo()->prepare('DELETE FROM payroll_module_state WHERE supplier_id = ?')->execute([$this->supplierId]);
        $result = $this->writer->write($this->plan(), $this->supplierId);
        self::assertSame(1, $result['counts']['historical_payroll_skipped']);
        self::assertSame(['payroll_start_missing'], array_column($result['warnings'], 'code'));
        self::assertSame([], $this->rows());
    }

    public function testDisabledPayrollAndUnmappedPersonAreExplicitSkips(): void
    {
        $this->db->pdo()->prepare('DELETE FROM stereo_nx_import_map WHERE supplier_id = ? AND kind = ?')
            ->execute([$this->supplierId, 'payroll_employee']);
        $result = $this->writer->write($this->plan(), $this->supplierId);
        self::assertSame(['payroll_employee_missing'], array_column($result['warnings'], 'code'));
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);
        $result = $this->writer->write($this->plan(), $this->supplierId);
        self::assertSame(['payroll_module_disabled'], array_column($result['warnings'], 'code'));
        self::assertSame([], $this->rows());
    }

    public function testChangedSourceIsRejectedWithoutOverwritingReference(): void
    {
        $plan = $this->plan();
        $this->writer->write($plan, $this->supplierId);
        $before = $this->rows();
        $plan['records'][0]['amounts']['net'] = 123;
        $plan['records'][0] = self::rehash($plan['records'][0]);
        $this->assertError('payroll_source_changed', fn () => $this->writer->write($plan, $this->supplierId));
        self::assertSame($before, $this->rows());
    }

    public function testDeletedMappedTargetIsRejected(): void
    {
        $plan = $this->plan();
        $this->writer->write($plan, $this->supplierId);
        $this->db->pdo()->prepare('DELETE FROM payroll_migration_reference_totals WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->assertError('payroll_target_changed', fn () => $this->writer->write($plan, $this->supplierId));
        self::assertSame([], $this->rows());
    }

    public function testExistingMonthWithoutImportMapCannotBeOverwrittenEvenWithinSameSource(): void
    {
        $plan = $this->plan();
        $this->writer->write($plan, $this->supplierId);
        $this->db->pdo()->prepare('DELETE FROM stereo_nx_import_map WHERE supplier_id = ? AND kind = ?')
            ->execute([$this->supplierId, 'payroll_month']);
        $before = $this->rows();
        $this->assertError('payroll_period_collision', fn () => $this->writer->write($plan, $this->supplierId));
        self::assertSame($before, $this->rows());
        $this->db->pdo()->prepare("UPDATE payroll_migration_reference_totals SET source = 'other' WHERE supplier_id = ?")
            ->execute([$this->supplierId]);
        $this->assertError('payroll_period_collision', fn () => $this->writer->write($plan, $this->supplierId));
    }

    public function testMapCannotPointToAnotherSuppliersEmploymentOrMonth(): void
    {
        $otherId = $this->createPayrollSupplier();
        $plan = $this->plan();
        $this->writer->write($plan, $otherId);
        $this->writer->write($plan, $this->supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT id, employment_id FROM payroll_migration_reference_totals WHERE supplier_id = ?');
        $stmt->execute([$otherId]);
        $other = $stmt->fetch(\PDO::FETCH_ASSOC);
        $update = $this->db->pdo()->prepare('UPDATE stereo_nx_import_map SET target_id = ? WHERE supplier_id = ? AND kind = ?');
        $update->execute([$other['id'], $this->supplierId, 'payroll_month']);
        $this->assertError('payroll_target_changed', fn () => $this->writer->write($plan, $this->supplierId));
        $update->execute([$other['employment_id'], $this->supplierId, 'payroll_employment']);
        $this->assertError('payroll_target_mismatch', fn () => $this->writer->write($plan, $this->supplierId));
    }

    private function plan(): array
    {
        return StereoNxPayrollMonths::fromTables(SyntheticStereoNxPayrollTables::tables(), SyntheticStereoNxPayrollTables::identity(), 1);
    }

    private function createPayrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->templateId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
            VALUES (?, "setup", "2026-02-01", ?, NOW())')->execute([$supplierId, $this->userId]);
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
            VALUES (?, "SYN", "Syntetická účtárna", "1234567890", 1)')->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_office_registration_versions
            (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
            VALUES (?, ?, "2020-01-01", "1234567890", "synthetic:stereo")')->execute([$supplierId, $officeId]);
        $pdo->prepare('INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
            VALUES (?, ?, "P")')->execute([$supplierId, $officeId]);
        $tables = SyntheticStereoNxPayrollTables::tables();
        $tables['MZAMEST'][0] += ['KrestniJmeno' => 'Jana', 'Prijmeni' => 'Vzorová', 'Vyrazen' => false,
            'TydUvazHod' => 40.0, 'MesTarif' => 10000.0, 'HodTarif' => 0.0];
        foreach (['MPOJIST', 'MDeti', 'MOpNezdC', 'MDovol', 'MPRVYD'] as $name) $tables[$name] = [];
        $employees = $this->container->get(StereoNxEmployees::class);
        $result = $employees->write(StereoNxEmployees::fromTables($tables, SyntheticStereoNxPayrollTables::identity(), 1), $supplierId, $this->userId);
        self::assertSame(1, $result['counts']['employees_created']);
        return $supplierId;
    }

    private function rows(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM payroll_migration_reference_totals WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$this->supplierId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function rehash(array $record): array
    {
        unset($record['source_hash']);
        $record['source_hash'] = StereoNxImportMap::fingerprint($record);
        return $record;
    }

    private function assertError(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('Očekávána chyba ' . $code);
        } catch (StereoNxException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
