<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\Attributes\Group;

final class MoneyS3AdvanceAccountsImportTest extends MoneyS3ImportTestCase
{
    public function testImportSetsAllSixRulesAndPreservesTheirCounteraccounts(): void
    {
        $supplier = $this->supplier();
        $backup = $this->advanceBackup();
        $step = $this->importAdvanceAccounts($supplier, $backup);

        self::assertSame(6, $step['counts']['advance_accounts'] ?? 0);
        $rules = $this->container(PostingRuleRepository::class);
        $expected = [
            'advance.received.collection' => ['credit_account_code', 'debit_account_code', '324.700'],
            'advance.received.settlement' => ['debit_account_code', 'credit_account_code', '324.700'],
            'advance.received.vatdocument' => ['debit_account_code', 'credit_account_code', '324.700'],
            'advance.paid.payment' => ['debit_account_code', 'credit_account_code', '314.300'],
            'advance.paid.settlement' => ['credit_account_code', 'debit_account_code', '314.300'],
            'advance.paid.vatdocument' => ['credit_account_code', 'debit_account_code', '314.300'],
        ];
        foreach ($expected as $key => [$side, $otherSide, $account]) {
            $rule = $rules->resolve($supplier, $key);
            self::assertSame($account, $rule[$side], $key);
            self::assertSame($rules->resolve(0, $key)[$otherSide], $rule[$otherSide], $key);
        }
    }

    public function testImportWarnsAndLeavesAmbiguousDirectionWithoutOverrides(): void
    {
        $supplier = $this->supplier();
        $step = $this->importAdvanceAccounts($supplier, $this->advanceBackup(['324700', '324800']));

        self::assertSame(3, $step['counts']['advance_accounts'] ?? 0);
        self::assertContains('advance_accounts_ambiguous_documents', array_column($step['messages'], 'code'));
        self::assertSame('warning', $step['status']);
        self::assertSame([], $this->container(PostingRuleRepository::class)->listOverrides($supplier, 'advance.received.'));
    }

    #[Group('fulltext-transactions')]
    public function testRepairCliPreviewsBothSourcesAndCommitsOnlyMissingRules(): void
    {
        $supplier = $this->supplier();
        $backup = $this->advanceBackup();
        $this->importAdvanceAccounts($supplier, $backup);
        $this->db->pdo()->prepare("DELETE FROM posting_rules WHERE supplier_id = ? AND rule_key LIKE 'advance.%'")->execute([$supplier]);
        $rules = $this->container(PostingRuleRepository::class);
        $rules->upsertOverride($supplier, 'advance.received.collection', '221', '324.800', 'Ruční nastavení');
        $this->db->pdo()->prepare(
            "INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, 'advance.paid.settlement', 'Ruční nastavení', '321', '314.000', 50, 0)"
        )->execute([$supplier]);
        $this->insertNativeBankEntry($supplier);
        $before = $rules->listOverrides($supplier, 'advance.');

        [$code, $out] = $this->cli($supplier, '--backup=' . $backup->dir());
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('[NANEČISTO]', $out);
        self::assertStringContainsString('Návrh advance.received.settlement (MD 324.700 / D 311)', $out);
        self::assertStringContainsString('Stávající advance.received.collection (MD 221 / D 324.800, vlastní, ponecháno)', $out);
        self::assertStringContainsString('Stávající advance.paid.settlement (MD 321 / D 314.000, vlastní, vypnuto a ponecháno)', $out);
        self::assertSame($before, $rules->listOverrides($supplier, 'advance.'));

        [$code, $out] = $this->cli($supplier, '--from-journal');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Návrh advance.received.settlement (MD 324.000 / D 311)', $out);
        self::assertSame($before, $rules->listOverrides($supplier, 'advance.'));

        [$code, $out] = $this->cli($supplier, '--from-journal', '--commit');
        self::assertSame(0, $code, $out);
        $after = $rules->listOverrides($supplier, 'advance.');
        self::assertSame($before, array_intersect_key($after, $before));
        self::assertCount(6, $after);
        self::assertSame('324.000', $rules->resolve($supplier, 'advance.received.settlement')['debit_account_code']);
        self::assertSame('314.300', $rules->resolve($supplier, 'advance.paid.payment')['debit_account_code']);

        [$code, $out] = $this->cli($supplier, '--from-journal', '--commit');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Žádné nové předkontace k doplnění.', $out);
        self::assertSame($after, $rules->listOverrides($supplier, 'advance.'));
    }

    private function advanceBackup(array $received = ['324700']): Ms3Backup
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAdvanceAccounts($received));
        return $this->backup();
    }

    private function importAdvanceAccounts(int $supplier, Ms3Backup $backup): array
    {
        $protocol = $this->importer->run($supplier, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        return array_column($protocol->toArray()['steps'], null, 'key')['posting_rules'];
    }

    private function insertNativeBankEntry(int $supplier): void
    {
        $chart = $this->container(ChartOfAccountsRepository::class)->codeToIdMap($supplier);
        $period = $this->container(AccountingPeriodRepository::class)->findByYear($supplier, 2025);
        $this->container(JournalEntryRepository::class)->insert([
            'supplier_id' => $supplier,
            'period_id' => $period['id'],
            'entry_date' => '2025-09-01',
            'source_type' => 'bank',
            'document_no' => 'BP-MYUCTO-1',
            'posted_at' => '2025-09-01 12:00:00',
        ], [
            ['line_no' => 1, 'side' => 'debit', 'account_id' => $chart['221.001']['id'], 'amount' => 100],
            ['line_no' => 2, 'side' => 'credit', 'account_id' => $chart['324.800']['id'], 'amount' => 100],
        ]);
    }

    private function cli(int $supplier, string ...$args): array
    {
        $script = dirname(__DIR__, 3) . '/bin/repair-money-s3-advance-accounts.php';
        $env = getenv();
        $env['MYINVOICE_DB_NAME'] = (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();
        $process = proc_open([PHP_BINARY, $script, '--supplier=' . $supplier, ...$args],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out];
    }
}
