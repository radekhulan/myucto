<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxPayrollTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticNx1Archive.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxAccountingTables.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

#[Group('integration')]
final class StereoNxAccountingPaymentsOrchestrationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxAccountingImporter $importer;
    private int $supplierId;
    private int $userId;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(StereoNxAccountingImporter::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $identity = SyntheticStereoNxAccountingTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name=?, ic=?, dic=?, accounting_mode='double_entry', is_vat_payer=1 WHERE id=?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $pdo->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default)
            VALUES (?,"CZK","CZK","Kč","CZK","CZK",2,1,1)')->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int) $pdo->lastInsertId(), $this->supplierId]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testOrchestratorIncludesPaymentsDryActualAndRepeat(): void
    {
        $backup = $this->backup(self::tables());
        $before = $this->snapshot();
        $dry = $this->importer->run($backup, $this->supplierId, $this->userId, true, true);
        self::assertTrue($dry['ok'], json_encode($dry, JSON_UNESCAPED_UNICODE));
        self::assertSame($before, $this->snapshot());
        self::assertSame(4, $dry['counts']['bank_transactions']);
        self::assertSame(1, $dry['counts']['skipped_bank_transactions']);
        self::assertSame(3, $dry['counts']['payments']);
        self::assertSame(5, $dry['counts']['requires_movement_review']);
        self::assertTrue($dry['partial']);
        self::assertContains(['table' => 'CBankap', 'count' => 1, 'reason' => 'skipped_bank_transactions'], $dry['not_transferred']);
        foreach ($dry['review_movements'] as $review) {
            self::assertNull($review['target_id']);
            self::assertNull($review['statement_id'] ?? null);
        }

        $actual = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($actual['ok'], json_encode($actual, JSON_UNESCAPED_UNICODE));
        self::assertSame(4, $actual['written']['bank_transactions']);
        self::assertSame(1, $actual['written']['cash_transactions']);
        self::assertSame(3, $actual['written']['payments']);
        self::assertSame(4, $this->scalar('SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=?'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM cash_documents WHERE supplier_id=?'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type IN ('cash','bank')"));
        $bankReview = array_values(array_filter($actual['review_movements'], static fn (array $r): bool => $r['kind'] === 'bank'))[0];
        self::assertGreaterThan(0, $bankReview['target_id']);
        self::assertGreaterThan(0, $bankReview['statement_id']);
        self::assertSame('BANK-4', $bankReview['document_no']);

        $after = $this->snapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($repeat['ok'], json_encode($repeat, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $repeat['written']['bank_transactions']);
        self::assertSame(0, $repeat['written']['cash_transactions']);
        self::assertSame(0, $repeat['written']['payments']);
        self::assertSame($after, $this->snapshot());
    }

    public function testReconciliationDetectsBalancedChangeToImportedJournal(): void
    {
        $backup = $this->backup(self::tables());
        $result = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($result['ok'], json_encode($result['errors']));
        self::assertTrue($result['reconciliation'][0]['ok']);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id
            SET l.amount=l.amount+1 WHERE e.supplier_id=? AND e.source_type<>'opening'")
            ->execute([$this->supplierId]);
        $container = Bootstrap::buildApp()->getContainer();
        $plan = $container->get(\MyInvoice\Service\Migration\StereoNx\StereoNxAccountingWriter::class)->prepare($backup);
        $checked = $container->get(\MyInvoice\Service\Migration\StereoNx\StereoNxReconciler::class)
            ->run($this->supplierId, $plan['accounting_plan']);
        self::assertFalse($checked['reconciliation'][0]['ok']);
        self::assertNotEmpty($checked['reconciliation'][0]['journal_diffs']);
        self::assertFalse($checked['criteria']['total']['K1']);
    }

    public function testHistoricalPayrollSharesDryRunTransactionAndDoesNotPostAgain(): void
    {
        $this->assertHistoricalPayroll(false);
    }

    public function testHistoricalPayrollInitializesMissingStartAndRollsItBackInDryRun(): void
    {
        $this->assertHistoricalPayroll(true);
    }

    private function assertHistoricalPayroll(bool $missingStart): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        if (!$missingStart) $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
            VALUES (?, "setup", "2026-02-01", ?, NOW())')->execute([$this->supplierId, $this->userId]);
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
            VALUES (?, "SYN", "Syntetická účtárna", "1234567890", 1)')->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_office_registration_versions
            (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
            VALUES (?, ?, "2020-01-01", "1234567890", "synthetic:stereo")')->execute([$this->supplierId, $officeId]);
        $pdo->prepare('INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
            VALUES (?, ?, "P")')->execute([$this->supplierId, $officeId]);
        $payroll = SyntheticStereoNxPayrollTables::tables();
        $rates = $payroll['Gparrok'];
        unset($payroll['Gparrok']);
        $payroll['MZAMEST'][0] += ['KrestniJmeno' => 'Jana', 'Prijmeni' => 'Vzorová',
            'Vyrazen' => false, 'TydUvazHod' => 40.0, 'MesTarif' => 10000.0, 'HodTarif' => 0.0];
        $backup = $this->backup(array_replace(self::tables(), $payroll));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->files[array_key_last($this->files)]));
        $zip->addFromString('DataPrg/GDATA/Gparrok.nx1', SyntheticNx1Archive::table($rates));
        $zip->close();

        $before = $this->snapshot();
        $dry = $this->importer->run($backup, $this->supplierId, $this->userId, true, true);
        self::assertTrue($dry['ok'], json_encode($dry['errors']));
        self::assertSame(1, $dry['written']['historical_payroll_created']);
        if ($missingStart) {
            self::assertSame('2026-02', $dry['payroll_setup']['start_period']);
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_module_state WHERE supplier_id=?'));
        }
        self::assertSame($before, $this->snapshot());
        $actual = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($actual['ok'], json_encode($actual['errors']));
        self::assertSame(1, $actual['written']['historical_payroll_created']);
        $startQuery = $pdo->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id=?');
        $startQuery->execute([$this->supplierId]);
        self::assertSame('2026-02-01', $startQuery->fetchColumn());
        self::assertSame(0, $actual['written']['historical_payroll_skipped']);
        self::assertNotContains('historical_payroll_skipped', array_column($actual['warnings'], 'code'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_runs WHERE supplier_id=?'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='payroll'"));
        $after = $this->snapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($repeat['ok'], json_encode($repeat['errors']));
        self::assertSame(1, $repeat['written']['historical_payroll_existing']);
        self::assertSame($after, $this->snapshot());
    }

    public function testSourceMovementEntriesSurviveRepeatAndLaterBackfill(): void
    {
        $tables = self::tables();
        $template = $tables['Cdenik'][2];
        foreach (['CBankap' => 'B', 'CPokl' => 'P'] as $table => $agenda) {
            foreach ($tables[$table] as $row) {
                if ($row['Castka'] == 0 || ($row['Kurz'] ?? 1) != 1) continue;
                $entry = array_replace($template, [
                    'Agenda' => $agenda, 'DoklRada' => $row['DoklRada'], 'DoklCislo' => $row['DoklCislo'],
                    'Klic' => $row['Klic'] ?? 0, 'Poradi' => 0, 'KdyUcPripad' => $row['KdyUcPripad'],
                    'Rok' => (int) substr($row['KdyUcPripad'], 0, 4), 'Mesic' => (int) substr($row['KdyUcPripad'], 5, 2),
                    'Celkem' => (float) $row['Castka'], 'DPH' => 0.0,
                ]);
                $tables['Cdenik'][] = $entry;
                if ($agenda === 'P') {
                    // Split cash posting: both source entries must attach to the same document.
                    $tables['Cdenik'][array_key_last($tables['Cdenik'])]['Celkem'] = $entry['Celkem'] / 2;
                    $entry['Celkem'] /= 2;
                    $entry['Poradi'] = 1;
                    $tables['Cdenik'][] = $entry;
                }
            }
        }
        $backup = $this->backup($tables);
        $result = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame(4, $this->scalar("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='bank'"));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM cash_documents WHERE supplier_id=? AND journal_entry_id IS NOT NULL'));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM journal_entry_document_links WHERE supplier_id=? AND doc_type='cash'"));
        $before = $this->snapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($repeat['ok'], json_encode($repeat));
        self::assertSame($before, $this->snapshot());
        $container = Bootstrap::buildApp()->getContainer();
        $pending = $container->get(\MyInvoice\Service\Accounting\Activation\PendingBackfillCounter::class)->count($this->supplierId);
        self::assertSame(0, $pending['bank_transactions']);
        self::assertSame(0, $pending['cash_documents']);
        $bank = $container->get(\MyInvoice\Service\Accounting\Bank\BankPostingBackfill::class)
            ->run($this->supplierId, null, true, false, $this->userId);
        self::assertSame(0, $bank['posted']);
        self::assertSame([], $bank['errors']);
        self::assertSame($before, $this->snapshot());
    }

    public function testUnlinkedMovementsCannotBePickedUpByLaterBackfill(): void
    {
        $report = $this->importer->run($this->backup(self::tables()), $this->supplierId, $this->userId, false, true);
        self::assertTrue($report['ok'], json_encode($report));
        $pending = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Service\Accounting\Activation\PendingBackfillCounter::class)
            ->count($this->supplierId);
        self::assertSame(0, $pending['bank_transactions'], 'A later activation must not post a second copy of imported source entries.');
        self::assertSame(0, $pending['cash_documents']);
    }

    public function testClosedDateOfSkippedMovementStillBlocksTheRun(): void
    {
        $tables = self::tables();
        $tables['CBankap'][4]['KdyUcPripad'] = '2024-12-31';
        $period = Bootstrap::buildApp()->getContainer()->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, 2024, '2024-01-01', '2024-12-31');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE id=? AND supplier_id=?")
            ->execute([$period, $this->supplierId]);

        $before = $this->snapshot();
        $report = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false, true);
        self::assertFalse($report['ok']);
        self::assertContains('document_date_locked', array_column($report['errors'], 'code'));
        self::assertSame($before, $this->snapshot());
    }

    /** @return array<string,list<array<string,mixed>>> */
    private static function tables(): array
    {
        $documents = SyntheticStereoNxTables::tables();
        $tables = array_replace($documents, SyntheticStereoNxAccountingTables::tables());
        $tables['LAdresy'] = $documents['LAdresy'];
        $tables['LFirmaUc'][0]['BaUcet'] = '19-1000000005';
        $tables['LFirmaUc'][0]['KodBanky'] = '0100';
        $tables['CBanka'][0]['Kurz'] = 1.0; $tables['CBanka'][0]['KurzMn'] = 1.0;
        foreach ($tables['CBankap'] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        $tables['CBankap'][3]['DPHz'] = 1.0;
        $tables['CBankap'][3]['DokladS'] = 'BANK-4';
        $tables['CBankap'][4]['Kurz'] = 25.0;
        $tables['CBankap'][4]['CastkaVlastni'] = 1250.0;
        foreach ($tables['CPokl'] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        foreach ($tables['CPZZ'] as &$row) $row['Mena'] = 'Kč';
        unset($row);
        foreach ($tables['Cpz'] as &$row) $row['Agenda'] = $row['DoklSRada'];
        unset($row);
        return $tables;
    }

    /** @param array<string,list<array<string,mixed>>> $tables */
    private function backup(array $tables): StereoNxBackup
    {
        $path = sys_get_temp_dir() . '/stereo-accounting-orchestration-' . bin2hex(random_bytes(6)) . '.zip';
        SyntheticNx1Archive::write($path, $tables, SyntheticStereoNxAccountingTables::identity());
        $this->files[] = $path;
        return StereoNxBackup::open($path, 0);
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->db->pdo()->prepare($sql); $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> */
    private function snapshot(): array
    {
        $out = [];
        foreach (['clients', 'invoices', 'purchase_invoices', 'bank_statements', 'cash_documents',
            'journal_entries', 'stereo_nx_import_map', 'payroll_employees', 'payroll_employments',
            'payroll_migration_reference_totals'] as $table) {
            $out[$table] = $this->scalar("SELECT COUNT(*) FROM {$table} WHERE supplier_id=?");
        }
        return $out;
    }
}
