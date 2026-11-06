<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticNx1Archive.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxAccountingTables.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

#[Group('integration')]
final class StereoNxBankSeriesOrchestrationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxAccountingImporter $importer;
    private int $supplier;
    private int $actor;
    private array $files = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(StereoNxAccountingImporter::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $seed = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->actor = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $seed);
        self::assertGreaterThan(0, $this->actor);
        $this->supplier = $this->createIsolatedSupplier($pdo, $seed);
        $identity = SyntheticStereoNxAccountingTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name=?, ic=?, dic=?, accounting_mode='double_entry',
            accounting_enabled=0, accounting_starts_on=NULL, accounting_activation_status='none' WHERE id=?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplier]);
        $this->setVatPayerAt($pdo, $this->supplier, '1900-01-01', true);
        $pdo->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default)
            VALUES (?,"CZK","CZK","Kč","CZK","CZK",2,1,1)')->execute([$this->supplier]);
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')
            ->execute([(int) $pdo->lastInsertId(), $this->supplier]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    #[DataProvider('unresolvedSeries')]
    public function testUnresolvedStatementDoesNotBlockMappedBankOrSourceJournal(string $unresolved): void
    {
        $backup = $this->backup(self::tables($unresolved));
        $before = $this->snapshot();
        $dry = $this->importer->run($backup, $this->supplier, $this->actor, true, true);
        self::assertTrue($dry['ok'], json_encode($dry['errors']));
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, $dry['counts']['bank_accounts']);
        self::assertSame(1, $dry['counts']['bank_statements']);
        self::assertSame(1, $dry['counts']['bank_transactions']);
        self::assertSame(1, $dry['counts']['skipped_bank_account_statements']);
        self::assertSame(1, $dry['counts']['skipped_bank_transactions']);
        $warnings = array_values(array_filter($dry['warnings'], static fn (array $w): bool => $w['code'] === 'bank_account_unresolved'));
        self::assertCount(1, $warnings);
        self::assertSame(2, $warnings[0]['count'], 'Výpis i jeho pohyb zůstávají nepřevedené bez odhadnutí účtu.');

        $actual = $this->importer->run($backup, $this->supplier, $this->actor, false, true);
        self::assertTrue($actual['ok'], json_encode($actual['errors']));
        self::assertContains('bank_account_unresolved', array_column($actual['warnings'], 'code'));
        self::assertSame(1, $actual['written']['bank_transactions']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM supplier_bank_accounts WHERE supplier_id=?'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM bank_statements WHERE supplier_id=?'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=?'));
        self::assertGreaterThan(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?'));
        self::assertTrue($actual['reconciliation'][0]['ok']);
        $after = $this->snapshot();
        $repeat = $this->importer->run($backup, $this->supplier, $this->actor, false, true);
        self::assertTrue($repeat['ok'], json_encode($repeat['errors']));
        self::assertSame(0, $repeat['written']['bank_transactions']);
        self::assertSame($after, $this->snapshot());
    }

    public static function unresolvedSeries(): array
    {
        return ['unmatched C among A/B accounts' => ['C'], 'empty statement series' => ['']];
    }

    public function testDuplicateAccountSeriesStillBlockBeforeAnyWrite(): void
    {
        $tables = self::tables('C');
        $tables['LFirmaUc'][1]['DoklRada'] = 'A';
        $before = $this->snapshot();
        $report = $this->importer->run($this->backup($tables), $this->supplier, $this->actor, false, true);
        self::assertFalse($report['ok']);
        self::assertContains('bank_account_series_ambiguous', array_column($report['errors'], 'code'));
        self::assertSame($before, $this->snapshot());
    }

    public function testSingleMissingAccountSeriesStillRequiresExplicitConfirmation(): void
    {
        $tables = self::tables('C');
        $tables['LFirmaUc'] = [array_replace($tables['LFirmaUc'][0], ['DoklRada' => ''])];
        $tables['CBanka'] = [$tables['CBanka'][0]];
        $tables['CBankap'] = [$tables['CBankap'][0]];
        $backup = $this->backup($tables);
        $before = $this->snapshot();
        $report = $this->importer->run($backup, $this->supplier, $this->actor, false, true);
        self::assertFalse($report['ok']);
        self::assertContains('bank_account_series_confirmation_required', array_column($report['errors'], 'code'));
        self::assertSame($before, $this->snapshot());
        $confirmed = $this->importer->run($backup->withBankAccountSeriesMatching(true), $this->supplier, $this->actor, true, true);
        self::assertTrue($confirmed['ok'], json_encode($confirmed['errors']));
        self::assertContains('bank_account_series_confirmed', array_column($confirmed['warnings'], 'code'));
        self::assertSame('', iterator_to_array($backup->rows('LFirmaUc'), false)[0]['DoklRada']);
        self::assertSame($before, $this->snapshot());
    }

    public function testMappedStatementWithoutNumberStillFailsIdentityValidation(): void
    {
        $tables = self::tables('C');
        $tables['CBanka'][0]['DoklCislo'] = '';
        $before = $this->snapshot();
        $report = $this->importer->run($this->backup($tables), $this->supplier, $this->actor, false, true);
        self::assertFalse($report['ok']);
        self::assertContains('source_identity_invalid', array_column($report['errors'], 'code'), json_encode($report['errors']));
        self::assertSame($before, $this->snapshot());
    }

    /** Záloha bez deníku, dokladů a pohybů (jen adresář a nastavení) se převede jako dřív. */
    public function testSourceWithoutDatedAccountingRecordsStillImports(): void
    {
        $tables = self::tables('C');
        foreach (['Cdenik', 'SfaktV', 'SfaktP', 'CBanka', 'CBankap', 'CPokl'] as $table) $tables[$table] = [];
        $tables['LFirmaUc'] = [$tables['LFirmaUc'][0]];
        $backup = $this->backup($tables);
        $before = $this->snapshot();
        $dry = $this->importer->run($backup, $this->supplier, $this->actor, true, true);
        self::assertTrue($dry['ok'], json_encode($dry['errors']));
        self::assertSame($before, $this->snapshot());
        $actual = $this->importer->run($backup, $this->supplier, $this->actor, false, true);
        self::assertTrue($actual['ok'], json_encode($actual['errors']));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?'));
    }

    private static function tables(string $unresolved): array
    {
        $tables = array_replace(SyntheticStereoNxTables::tables(), SyntheticStereoNxAccountingTables::tables());
        foreach (['Svfh', 'Svfp', 'SPFH', 'Spfp', 'Cpz', 'CPZZ', 'CPokl'] as $table) $tables[$table] = [];
        $account = ['DoklRada' => 'A', 'NazevUctu' => 'Syntetický účet A',
            'BaUcet' => '19-1000000005', 'KodBanky' => '0100', 'IBAN' => ''];
        $tables['LFirmaUc'] = [$account,
            array_replace($account, ['DoklRada' => 'B', 'NazevUctu' => 'Syntetický účet B', 'BaUcet' => '1000000013']),
            array_replace($account, ['DoklRada' => '', 'NazevUctu' => 'Nepoužitý syntetický účet'])];
        $statement = ['DoklRada' => 'A', 'DoklCislo' => 1, 'Doklad' => 'SYN-A-1',
            'KdyVystaveno' => '2026-02-03', 'Kurz' => 1.0, 'KurzMn' => 1.0];
        $tables['CBanka'] = [$statement, array_replace($statement, ['DoklRada' => $unresolved, 'Doklad' => 'SYN-UNRESOLVED-1'])];
        $movement = ['DoklRada' => 'A', 'DoklCislo' => 1, 'Klic' => 1, 'DoklSRada' => '', 'DoklSCislo' => 0,
            'SmerPlatby' => 'P', 'Castka' => 25.0, 'CastkaVlastni' => 25.0, 'MenaCizi' => '',
            'Kurz' => 1.0, 'KurzMn' => 1.0, 'KdyUcPripad' => '2026-02-03', 'Sloupec' => '20',
            'DPHz' => 0.0, 'DPHs' => 0.0, 'DPHt' => 0.0, 'Text' => 'Syntetický nepřiřazený pohyb',
            'VarSym' => '', 'BaUcet' => '1000000005', 'KodBanky' => '0100', 'DokladS' => 'SYN-MOVE-A'];
        $tables['CBankap'] = [$movement, array_replace($movement, ['DoklRada' => $unresolved, 'DokladS' => 'SYN-MOVE-UNRESOLVED'])];
        return $tables;
    }

    private function backup(array $tables): StereoNxBackup
    {
        $path = sys_get_temp_dir() . '/stereo-bank-series-orchestration-' . bin2hex(random_bytes(6)) . '.zip';
        SyntheticNx1Archive::write($path, $tables, SyntheticStereoNxAccountingTables::identity());
        $this->files[] = $path;
        return StereoNxBackup::open($path, 0);
    }

    private function scalar(string $sql): int
    {
        $query = $this->db->pdo()->prepare($sql);
        $query->execute([$this->supplier]);
        return (int) $query->fetchColumn();
    }

    private function snapshot(): array
    {
        $counts = [];
        foreach (['supplier_bank_accounts', 'bank_statements', 'journal_entries', 'stereo_nx_import_map'] as $table) {
            $counts[$table] = $this->scalar("SELECT COUNT(*) FROM $table WHERE supplier_id=?");
        }
        $counts['bank_transactions'] = $this->scalar('SELECT COUNT(*) FROM bank_transactions bt
            JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=?');
        return $counts;
    }
}
