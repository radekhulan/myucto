<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\NonprofitActivityDefault;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

final class MoneyS3NonprofitImportTest extends MoneyS3ImportTestCase
{
    public function testClosesHistoryAndKeepsMoneyOpeningAndDistribution(): void
    {
        $supplier = $this->supplier504();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET is_identified = 1 WHERE id = ?')->execute([$supplier]);
        VatStatusService::seedInitialStatus($pdo, $supplier, false, true);
        $this->useFixture(SyntheticAgenda::filesNonprofit());
        $protocol = $this->import($supplier, reports: [2024 => $this->tmp . '/predvaha-2024.csv']);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('closed', $protocol->get('closing')[0]['status'], $this->explain($protocol));
        self::assertSame([], $protocol->get('closing')[0]['diffs']);
        foreach ($protocol->get('reconciliation') as $year) {
            self::assertSame([], $year['journal_diffs']);
            self::assertNull($year['money_report']);
            self::assertTrue(array_column($year['checks'], 'ok', 'key')['balance_sheet_balanced']);
        }
        $closing = $this->container(ClosingRepository::class);
        $next = $this->periodId($supplier, 2025);
        self::assertCount(1, $closing->openingEntriesInPeriod($supplier, $next));
        self::assertEquals(-95.0, $closing->openingBalancesInPeriod($supplier, $next)['931.000']);
        self::assertSame(1, $this->rowCount('journal_entries', $supplier, "source_type = 'manual' AND document_no = 'ID-N2501'"));
        self::assertEquals(0.0, $closing->accountBalance($supplier, '931', '2025-12-31'));
        self::assertSame(1, $this->rowCount('invoices', $supplier));
        self::assertSame(2, $this->snapshotCounts($supplier)['bank_transactions']);
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplier, "varsymbol = 'FP25071' AND reverse_charge = 1 AND vat_deduction = 'none'"));
        self::assertEqualsWithDelta(-8.61, $closing->accountBalance($supplier, '343', '2025-12-31'), 0.001);
        $vat = $this->container(DphPriznaniBuilder::class)->build($supplier, 2025, 7, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(41.0, (float) ($vat['5']['base'] ?? 0.0), 0.001);
        self::assertEqualsWithDelta(8.61, (float) ($vat['5']['vat'] ?? 0.0), 0.001);
        self::assertEquals(0.0, (float) ($vat['43']['vat'] ?? 0.0));
        $statement = $this->container(FinancialStatementService::class)->incomeStatement($supplier, $next, null, 'full');
        self::assertSame(69.0, array_column($statement['rows'], 'main', 'row_code')['UT.45']);
        self::assertSame(41.0, array_column($statement['rows'], 'main', 'row_code')['UT.8']);
        $messages = $this->messages($protocol);
        self::assertSame(5, $messages['nonprofit_activity_default']['context']['lines']);
        self::assertSame('info', $messages['nonprofit_tax_assessment_manual']['level']);
        $activity = $this->activityRows($supplier);
        self::assertSame(array_fill(0, 5, 'main'), array_column($activity, 'code'));
        self::assertSame(['money_s3_import'], array_unique(array_column($activity, 'assignment_origin_ref')));

        $accounts = $this->container(ChartOfAccountsRepository::class);
        foreach (['682.100' => 'revenue', '931.100' => 'equity', '518.100' => 'expense'] as $code => $type) {
            $analytic = $accounts->findByCode($supplier, $code);
            self::assertSame($type, $analytic['account_type']);
            self::assertSame($accounts->findByCode($supplier, substr($code, 0, 3))['id'], $analytic['parent_id']);
        }
        $expense = $accounts->findByCode($supplier, '518.100');
        self::assertSame('non_deductible', $accounts->findById($supplier, $expense['id'])['tax_deductibility']);
        self::assertArrayNotHasKey('non_deductible_accounts', $messages);
    }

    public function testRefusesNonprofitAgendaInFramework500BeforeWrites(): void
    {
        $supplier = $this->supplier();
        $before = $this->snapshotCounts($supplier);
        $this->useFixture(SyntheticAgenda::filesNonprofit());
        $protocol = $this->import($supplier);
        self::assertArrayHasKey('nonprofit_agenda_needs_504', $this->messages($protocol), $this->explain($protocol));
        self::assertSame($before, $this->snapshotCounts($supplier));
        self::assertSame(0, $this->rowCount('chart_of_accounts', $supplier));
    }

    public function testFramework504RequiresNonprofitChart(): void
    {
        $supplier = $this->supplier504();
        $ordinary = $this->import($supplier);
        self::assertArrayHasKey('accounting_framework_import_unsupported', $this->messages($ordinary), $this->explain($ordinary));
        self::assertSame(0, $this->rowCount('journal_entries', $supplier));
        $this->useFixture(SyntheticAgenda::filesNonprofit());
        $nonprofit = $this->import($supplier);
        self::assertFalse($nonprofit->hasErrors(), $this->explain($nonprofit));
    }

    public function testRepeatedImportKeepsActivityChangedByAccountant(): void
    {
        $supplier = $this->supplier504();
        $this->useFixture(SyntheticAgenda::filesNonprofit());
        $first = $this->import($supplier);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $activity = array_column($this->activityRows($supplier), null, 'document_no')['BK-N2501'];
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id FROM dimension_values WHERE type_id = ? AND code = 'economic'");
        $stmt->execute([$activity['dimension_type_id']]);
        $this->container(DimensionService::class)->saveEntryLines($supplier, (int) $activity['entry_id'], [
            (int) $activity['line_id'] => [(int) $activity['dimension_type_id'] => (int) $stmt->fetchColumn()],
        ]);
        self::assertSame('economic', array_column($this->activityRows($supplier), 'code', 'document_no')['BK-N2501']);

        $again = $this->import($supplier);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertArrayNotHasKey('nonprofit_activity_default', $this->messages($again));
        self::assertSame('economic', array_column($this->activityRows($supplier), 'code', 'document_no')['BK-N2501']);
    }

    public function testOpeningMismatchLeavesBooksOpen(): void
    {
        $supplier = $this->supplier504();
        $this->useFixture(SyntheticAgenda::filesNonprofit(94.0));
        $protocol = $this->import($supplier);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertArrayHasKey('closing_mismatch', $this->messages($protocol));
        self::assertSame('mismatch', $protocol->get('closing')[0]['status']);
    }

    public function testOpeningOnlyCarriesProfitWithoutImportingNextYearDocuments(): void
    {
        $supplier = $this->supplier504();
        $this->useFixture(SyntheticAgenda::filesNonprofit());
        $protocol = $this->importer->run($supplier, $this->userId, $this->backup(),
            new ImportOptions(ImportOptions::MODE_IMPORT, true, toYear: 2024, openingOnlyNext: true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(['closed', 'opening_only'], array_column($protocol->get('closing'), 'status'), $this->explain($protocol));
        self::assertEquals(-95.0, $this->container(ClosingRepository::class)
            ->openingBalancesInPeriod($supplier, $this->periodId($supplier, 2025))['931.000']);
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplier, "varsymbol = 'FP25071'"));
    }

    public function testAssignsActivityToEntryCreatedFromImportedBankDocument(): void
    {
        $supplier = $this->supplier504();
        $this->useFixture(SyntheticAgenda::filesNonprofit());
        $first = $this->import($supplier);
        self::assertFalse($first->hasErrors(), $this->explain($first));

        $bank = array_column($this->activityRows($supplier), null, 'document_no')['BK-N2501'];
        $entries = $this->map->all($supplier, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, '2025|');
        $key = array_search((int) $bank['entry_id'], $entries, true);
        self::assertIsString($key);
        $this->map->forget($supplier, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $key);
        $this->db->pdo()->prepare('DELETE FROM journal_entry_line_dimensions WHERE supplier_id = ? AND line_id = ?')
            ->execute([$supplier, $bank['line_id']]);

        $protocol = new ImportProtocol(ImportOptions::MODE_IMPORT);
        (new NonprofitActivityDefault($this->db))->assign($supplier, [$this->periodId($supplier, 2025)], '2025-01-01',
            $this->userId, 'money_s3_import', $protocol, 'dimensions', 'činnost z Money S3 nepřebírá');
        self::assertSame('main', array_column($this->activityRows($supplier), 'code', 'document_no')['BK-N2501']);
        self::assertSame(1, $this->messages($protocol)['nonprofit_activity_default']['context']['lines']);
    }

    public function testUndistributedResultOfOpenYearIsNotReconciliationError(): void
    {
        $supplier = $this->supplier504();
        $this->useFixture(SyntheticAgenda::filesNonprofit(distributeResult: false));
        $protocol = $this->import($supplier);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $skipped = $this->messages($protocol)['nonprofit_statement_skipped'] ?? null;
        self::assertNotNull($skipped, $this->explain($protocol));
        self::assertSame('statement_result_pending_approval_unresolved', $skipped['context']['reason']);
        $years = array_column($protocol->get('reconciliation'), null, 'year');
        self::assertTrue(array_column($years[2025]['checks'], 'ok', 'key')['balance_sheet_balanced']);
    }

    public function testGroupAccountWithoutStatementRowIsNamed(): void
    {
        $supplier = $this->supplier504();
        $this->useFixture(SyntheticAgenda::filesNonprofit(groupRevenue: true));
        $protocol = $this->import($supplier);
        $failed = $this->messages($protocol)['nonprofit_statement_failed'] ?? null;
        self::assertNotNull($failed, $this->explain($protocol));
        self::assertStringContainsString('Účet 640.000.', $failed['text']);
        $years = array_column($protocol->get('reconciliation'), null, 'year');
        self::assertSame(['640.000'], array_column($years[2025]['unmapped_accounts'], 'account'));
    }

    private function supplier504(): int
    {
        $supplier = $this->supplier(mode: 'double_entry');
        self::assertTrue($this->container(AccountingFrameworkService::class)->adoptCz504BaselineForNewSupplier($supplier, $this->userId));
        return $supplier;
    }

    private function useFixture(array $files): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);
    }

    private function messages(ImportProtocol $protocol): array
    {
        return array_column(array_merge(...array_column($protocol->toArray()['steps'], 'messages')), null, 'code');
    }

    private function activityRows(int $supplier): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT e.id AS entry_id, e.document_no, l.id AS line_id, d.dimension_type_id, v.code, d.assignment_origin_ref
            FROM journal_entries e
            JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
            JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.supplier_id = l.supplier_id
            LEFT JOIN dimension_values v ON v.id = d.dimension_value_id
            WHERE e.supplier_id = ? AND e.source_type <> 'closing' AND a.account_code REGEXP '^[56]'
            ORDER BY e.entry_date, l.id");
        $stmt->execute([$supplier]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
