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
