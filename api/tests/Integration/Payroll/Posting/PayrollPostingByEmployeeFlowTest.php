<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Posting;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use MyInvoice\Tests\Support\PairedJournalAssertions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zaúčtování mzdového předpisu po zaměstnancích celým tokem: volba v nastavení
 * mezd → zmrazení do snapshotu běhu → schválení → zaúčtování ve formě single.
 * Každá dvojice nese osobní číslo, náklad 524 je u osoby. Bez volby se zápis
 * nemění (souhrnně, bez textu osoby).
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class PayrollPostingByEmployeeFlowTest extends TestCase
{
    use EnforcementRunFixtureTrait;
    use PairedJournalAssertions;

    protected function setUp(): void
    {
        $this->bootEnforcementRun();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_accounting_modes WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->service(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->service(AccountingPeriodRepository::class)
            ->create($this->supplierId, 2026, '2026-01-01', '2026-12-31');
        $this->enableSingleEntryForm($pdo, $this->supplierId, '2026-01-01');
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    public function testEveryPairCarriesThePersonalNumber(): void
    {
        $this->setGranularity('employee');
        [$revisionId, $input] = $this->postRun();

        self::assertSame('employee', $input['employer']['posting_granularity'] ?? null);
        $entryId = $this->entryId($revisionId);
        $this->assertEntryPaired($this->db->pdo(), $this->supplierId, $entryId);
        $lines = $this->lines($entryId);
        $costs = array_filter($lines, static fn (array $line): bool => str_starts_with($line['account_code'], '52'));
        self::assertNotSame([], $costs);
        foreach ($costs as $line) {
            self::assertSame('Os. č. SYN-HPP', $line['line_text'], "Náklad {$line['account_code']} nese osobu.");
        }
        self::assertContains('524', array_column($costs, 'account_code'));
    }

    public function testSummaryPostingStaysWithoutPersonAndSnapshotKey(): void
    {
        $this->setGranularity('summary');
        [$revisionId, $input] = $this->postRun();

        self::assertArrayNotHasKey('posting_granularity', $input['employer']);
        $entryId = $this->entryId($revisionId);
        $this->assertEntryPaired($this->db->pdo(), $this->supplierId, $entryId);
        foreach ($this->lines($entryId) as $line) {
            self::assertNull($line['line_text']);
        }
    }

    /**
     * Sestava podrobného zaúčtování: zůstatek po účtech i obrat sedí na mzdový
     * zápis období v deníku, souhrn za firmu na rozpad po zaměstnancích, druh mzdy
     * je název složky a osoba nese osobní číslo a jméno ze zmrazené revize.
     *
     * @param 'summary'|'employee' $granularity
     */
    #[\PHPUnit\Framework\Attributes\TestWith(['summary', true])]
    #[\PHPUnit\Framework\Attributes\TestWith(['employee', true])]
    #[\PHPUnit\Framework\Attributes\TestWith(['employee', false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['summary', false])]
    public function testDetailReportMatchesTheJournal(string $granularity, bool $single): void
    {
        if (!$single) {
            $this->db->pdo()->prepare('DELETE FROM accounting_entry_form_history WHERE supplier_id = ?')
                ->execute([$this->supplierId]);
            \MyInvoice\Service\Accounting\EntryForm\EntryFormService::forgetShared($this->supplierId);
        }
        $this->setGranularity($granularity);
        [$revisionId] = $this->postRun();
        $entryId = $this->entryId($revisionId);

        $report = $this->service(\MyInvoice\Service\Payroll\Report\PayrollPostingDetailReportService::class)
            ->report($this->supplierId, '2026-06');

        $journal = $this->db->pdo()->prepare(
            "SELECT account.account_code,
                    SUM(CASE WHEN line.side = 'debit' THEN 1 ELSE -1 END * ROUND(line.amount * 100)) AS net,
                    SUM(CASE WHEN line.side = 'debit' THEN ROUND(line.amount * 100) ELSE 0 END) AS debit
               FROM journal_entry_lines line
               JOIN chart_of_accounts account
                 ON account.supplier_id = line.supplier_id AND account.id = line.account_id
              WHERE line.supplier_id = ? AND line.entry_id = ?
              GROUP BY account.account_code"
        );
        $journal->execute([$this->supplierId, $entryId]);
        $journalNet = [];
        $journalDebit = 0;
        foreach ($journal->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['net'] !== 0) {
                $journalNet[(string) $row['account_code']] = (int) $row['net'];
            }
            $journalDebit += (int) $row['debit'];
        }
        ksort($journalNet);

        $reportNet = [];
        foreach ($report['rows'] as $row) {
            $reportNet[$row['debit_account']] = ($reportNet[$row['debit_account']] ?? 0) + $row['amount_minor'];
            $reportNet[$row['credit_account']] = ($reportNet[$row['credit_account']] ?? 0) - $row['amount_minor'];
        }
        $reportNet = array_filter($reportNet, static fn (int $value): bool => $value !== 0);
        ksort($reportNet);

        self::assertSame($journalNet, $reportNet, 'Zůstatek sestavy po účtech je zůstatek deníku.');
        self::assertSame($journalDebit, $report['totals']['amount_minor'], 'Součet sestavy je obrat mzdového zápisu.');
        self::assertSame(
            $report['totals']['amount_minor'],
            array_sum(array_column($report['summary'], 'amount_minor')),
        );
        self::assertSame([$entryId], array_column($report['runs'], 'journal_entry_id'));
        $wage = array_values(array_filter(
            $report['rows'],
            static fn (array $row): bool => $row['debit_account'] === '521',
        ));
        self::assertCount(1, $wage);
        self::assertSame('Měsíční mzda', $wage[0]['label']);
        self::assertSame('331', $wage[0]['credit_account']);
        self::assertSame('SYN-HPP', $wage[0]['personal_number']);
        self::assertSame('Syntetický Zaměstnanec', $wage[0]['name']);
        self::assertSame(3_500_000, $wage[0]['amount_minor']);
    }

    private function setGranularity(string $granularity): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, posting_granularity)
             SELECT supplier_id, id, ? FROM payroll_offices WHERE supplier_id = ? ORDER BY id LIMIT 1'
        )->execute([$granularity, $this->supplierId]);
    }

    /** @return array{int,array<string,mixed>} */
    private function postRun(): array
    {
        $run = $this->calculateEnforcementRun();
        $this->approveEnforcementRun($run);
        $stmt = $this->db->pdo()->prepare(
            'SELECT input_snapshot_json, result_snapshot_json
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $run['revision_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $input = json_decode((string) $row['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $result = json_decode((string) $row['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertNotNull($this->service(PayrollApprovedRevisionPostingService::class)->postManually(
            $this->supplierId,
            $run['revision_id'],
            $input,
            $result,
            $this->actorId,
        ));

        return [$run['revision_id'], $input];
    }

    private function entryId(int $revisionId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = 'payroll' AND source_id = ?"
        );
        $stmt->execute([$this->supplierId, $revisionId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{account_code:string,side:string,line_text:?string}> */
    private function lines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account.account_code, line.side, line.line_text
               FROM journal_entry_lines line
               JOIN chart_of_accounts account
                 ON account.supplier_id = line.supplier_id AND account.id = line.account_id
              WHERE line.supplier_id = ? AND line.entry_id = ?
              ORDER BY line.line_no'
        );
        $stmt->execute([$this->supplierId, $entryId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
