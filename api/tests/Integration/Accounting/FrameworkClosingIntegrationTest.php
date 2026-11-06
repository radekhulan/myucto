<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class FrameworkClosingIntegrationTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private int $supplierId;
    private int $periodId;
    private int $userId;
    private array $taxDimensions = [];

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $this->userId);
        $pdo->exec("INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
            SELECT 'Synthetic role fixture', 'Test 1', 'Praha', '11000', country_id, 'roles@example.com', default_currency_id, default_vat_rate_id FROM supplier ORDER BY id LIMIT 1");
        $this->supplierId = (int) $pdo->lastInsertId();
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        foreach (['931', '932', '933', '961', '962'] as $code) {
            $pdo->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_active) VALUES (?, ?, ?, 'equity', NULL, 1)")->execute([$this->supplierId, $code, 'Synthetic ' . $code]);
        }
        $this->periods = $this->container->get(AccountingPeriodRepository::class);
        $this->periodId = $this->periods->create($this->supplierId, 2024, '2024-07-01', '2025-06-30');
        $this->closing = $this->container->get(ClosingService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testOpeningUsesHistorical500DespiteLater504(): void
    {
        $this->framework('2025-07-01', 'cz_504');
        $result = $this->postOpening('2024-07-01');
        self::assertSame(['221', '701', '701', '932'], array_column($this->entryLines($result['journal_entry_id']), 'account_code'));
    }

    public function testInitial504OpeningUses961AndKeepsImported932(): void
    {
        $this->framework('2024-07-01', 'cz_504');
        $result = $this->postOpening('2024-10-01');
        self::assertSame(['221', '961', '961', '932'], array_column($this->entryLines($result['journal_entry_id']), 'account_code'));
    }

    public function testInitial504OpeningRejectsAutomaticCounteraccount(): void
    {
        $this->framework('2024-07-01', 'cz_504');
        $opening = $this->container->get(OpeningBalanceService::class);
        $opening->saveDraft($this->supplierId, [['account_code' => '221', 'side' => 'debit', 'amount' => 100], ['account_code' => '961', 'side' => 'credit', 'amount' => 100]]);
        try {
            $opening->post($this->supplierId, '2024-07-01', $this->meta());
            self::fail('Automatic counteraccount must not be supplied manually.');
        } catch (PostingException $e) {
            self::assertSame('opening_counteraccount_forbidden', $e->errorCode);
        }
    }

    public function test504CloseAndOpenPreserveActivityAndTransfer932To931(): void
    {
        $this->framework('2024-07-01', 'cz_504');
        $dimensions = $this->container->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $types = $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko']);
        $typeId = $types['cost_center'];
        $main = (int) $dimensions->createValue($this->supplierId, $typeId, ['code' => 'MAIN', 'name' => 'Synthetic main'])['id'];
        $economic = (int) $dimensions->createValue($this->supplierId, $typeId, ['code' => 'ECON', 'name' => 'Synthetic economic'])['id'];
        $this->manual([['account_code' => '221', 'side' => 'debit', 'amount' => 150], ['account_code' => '602', 'side' => 'credit', 'amount' => 150]], [$typeId => $main]);
        $this->manual([['account_code' => '518', 'side' => 'debit', 'amount' => 50], ['account_code' => '221', 'side' => 'credit', 'amount' => 50]], [$typeId => $economic]);
        $this->manual([['account_code' => '221', 'side' => 'debit', 'amount' => 200], ['account_code' => '932', 'side' => 'credit', 'amount' => 200]], [$typeId => $main]);
        $this->closeBooks();
        $closed = $this->container->get(JournalEntryRepository::class)->findBySource($this->supplierId, 'closing', $this->periodId);
        $closedCodes = array_column($this->entryLines((int) $closed['id']), 'account_code');
        self::assertContains('933', $closedCodes);
        self::assertContains('962', $closedCodes);
        self::assertNotContains('710', $closedCodes);
        self::assertNotContains('702', $closedCodes);
        $opened = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $lines = $this->entryLines((int) $opened['entry_id']);
        self::assertNotContains('932', array_column($lines, 'account_code'));
        self::assertNotContains('933', array_column($lines, 'account_code'));
        $balances = $this->container->get(ClosingRepository::class)->openingBalancesWithDimensionsInPeriod($this->supplierId, (int) $opened['next_period_id']);
        $result = [];
        foreach ($balances as $row) {
            if ($row['account_code'] === '931') {
                $value = $row['dimensions'][$typeId];
                $result[$value] = ($result[$value] ?? 0.0) + $row['bal'];
            }
        }
        self::assertSame(-350.0, $result[$main]);
        self::assertSame(50.0, $result[$economic]);
        $repeated = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame('taken_over', $repeated['opening_source']);
    }

    public function testDocumentedManualAssessmentAllowsFiscal504ClosingAndOpening(): void
    {
        $this->db->pdo()->prepare("UPDATE accounting_periods SET fiscal_year = 2026, starts_on = '2026-07-01', ends_on = '2027-06-30' WHERE id = ?")
            ->execute([$this->periodId]);
        $this->framework('2026-07-01', 'cz_504');
        $this->manual([['account_code' => '221', 'side' => 'debit', 'amount' => 120], ['account_code' => '602', 'side' => 'credit', 'amount' => 120]]);
        $this->closeBooks();
        $opened = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertContains('931', array_column($this->entryLines((int) $opened['entry_id']), 'account_code'));
        self::assertSame('2027-07-01', $this->periods->findById($this->supplierId, (int) $opened['next_period_id'])['starts_on']);
    }

    public function testOpeningAcrossFrameworkChangeIsRejectedBeforePosting(): void
    {
        $this->manual([['account_code' => '221', 'side' => 'debit', 'amount' => 100], ['account_code' => '602', 'side' => 'credit', 'amount' => 100]]);
        $this->closeBooks();
        $this->framework('2025-07-01', 'cz_504');
        try {
            $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Cross-framework opening must be explicit.');
        } catch (ClosingException $e) {
            self::assertSame('accounting_framework_transition_not_supported', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'opening'");
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function test504DefaultDeferredMethodIsOffAndRejectsAutomatic500Posting(): void
    {
        $this->framework('2024-07-01', 'cz_504');
        $preview = $this->closing->deferredTaxPreview($this->supplierId, $this->periodId);
        self::assertSame('cz_504', $preview['accounting_framework']);
        self::assertFalse($preview['enabled']);
        self::assertSame(['opening' => [], 'closing' => []], $preview['movements']);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        try {
            $this->closing->runDeferredTax($this->supplierId, $this->periodId, 25.0, $this->rv(), $this->meta());
            self::fail('504 deferred tax cannot use the automatic 500 calculator.');
        } catch (ClosingException $e) {
            self::assertSame('accounting_framework_operation_not_supported', $e->errorCode);
        }
        self::assertNull($this->container->get(JournalEntryRepository::class)->findBySource($this->supplierId, 'deferred_tax', $this->periodId));
    }

    public function testHistorical500ClosingStillOpens431WithLater504History(): void
    {
        $this->manual([['account_code' => '221', 'side' => 'debit', 'amount' => 100], ['account_code' => '602', 'side' => 'credit', 'amount' => 100]]);
        $this->closeBooks();
        $this->framework('2026-07-01', 'cz_504');
        $opened = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame(['221', '701', '701', '431'], array_column($this->entryLines((int) $opened['entry_id']), 'account_code'));
    }

    public function test504Pending931BlocksStart(): void
    {
        $this->framework('2024-07-01', 'cz_504');
        $this->manual([['account_code' => '221', 'side' => 'debit', 'amount' => 100], ['account_code' => '931', 'side' => 'credit', 'amount' => 100]]);
        try {
            $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Pending 931 result must block closing.');
        } catch (ClosingException $e) {
            self::assertSame('profit_not_distributed', $e->errorCode);
            self::assertStringContainsString('931', $e->getMessage());
            self::assertStringContainsString('932', $e->getMessage());
        }
    }

    public function test504DistributionCannotReuse500DecisionWorkflow(): void
    {
        $this->framework('2024-07-01', 'cz_504');
        $this->manual([['account_code' => '221', 'side' => 'debit', 'amount' => 100], ['account_code' => '602', 'side' => 'credit', 'amount' => 100]]);
        $this->closeBooks();
        $opened = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'approved' WHERE id = ? AND supplier_id = ?")->execute([$this->periodId, $this->supplierId]);
        $next = $this->periods->findById($this->supplierId, (int) $opened['next_period_id']);
        try {
            $this->closing->runProfitDistribution($this->supplierId, $this->periodId, [
                'decision_date' => '2025-07-01', 'target_row_version' => (int) $next['row_version'],
                'allocations' => [['account_code' => '932', 'amount' => 100]],
            ], $this->meta());
            self::fail('504 result decisions require their own legal context.');
        } catch (ClosingException $e) {
            self::assertSame('accounting_framework_operation_not_supported', $e->errorCode);
        }
    }

    private function closeBooks(): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $items = [];
        foreach ($this->closing->inventoryPreview($sid, $pid)['rows'] as $row) {
            $items[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->closing->saveInventory($sid, $pid, $this->rv(), ['complete' => true], $items, $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $fx = $this->closing->fxPreview($sid, $pid);
        $fxDecision = isset($fx['snapshot_hash']) ? ['snapshot_hash' => $fx['snapshot_hash']] : [];
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta(), $fxDecision);
        foreach (['estimates', 'deferrals', 'provisions', 'income_tax'] as $step) {
            $this->closing->confirmStep($sid, $pid, $step, 'skipped', null, $this->rv(), $this->meta());
        }
        if ($this->taxDimensions !== []) {
            \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($this->container, $sid, $pid, $this->userId);
        }
        $result = $this->closing->closeBooks($sid, $pid, $this->rv(), $this->meta());
        self::assertSame('closed', $result['status']);
    }

    private function framework(string $date, string $framework): void
    {
        $this->db->pdo()->prepare('INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, ?, ?)')->execute([$this->supplierId, $date, $framework]);
    }

    private function postOpening(string $date): array
    {
        $opening = $this->container->get(OpeningBalanceService::class);
        $opening->saveDraft($this->supplierId, [['account_code' => '221', 'side' => 'debit', 'amount' => 100], ['account_code' => '932', 'side' => 'credit', 'amount' => 100]]);
        return $opening->post($this->supplierId, $date, $this->meta());
    }

    private function manual(array $lines, array $dimensions = []): void
    {
        $period = $this->periods->findById($this->supplierId, $this->periodId);
        $date = (new \DateTimeImmutable($period['starts_on']))->modify('+3 months')->format('Y-m-d');
        $framework = $this->container->get(\MyInvoice\Service\Accounting\AccountingFrameworkService::class)->forDate($this->supplierId, $date);
        if ($framework === 'cz_504' && $this->taxDimensions === []) {
            $catalog = $this->container->get(\MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog::class)->ensureForFramework($this->supplierId, $date);
            $this->db->pdo()->prepare('INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES (?,?,?,?)')
                ->execute([$catalog['activity_kind']['id'], $this->supplierId, 'synthetic_rent', 'Synthetic rent']);
            $this->taxDimensions = [$catalog['activity']['id'] => $catalog['activity']['values']['main'],
                $catalog['activity_kind']['id'] => (int) $this->db->pdo()->lastInsertId(),
                $catalog['tax_regime']['id'] => $catalog['tax_regime']['values']['subject']];
        }
        if ($framework === 'cz_504') $dimensions += $this->taxDimensions;
        $origins = array_fill_keys(array_keys($dimensions), ['origin' => 'explicit', 'ref' => null]);
        $lines = array_map(static fn (array $line): array => $line + ['dimensions' => $dimensions, 'dimension_origins' => $origins], $lines);
        $this->container->get(PostingService::class)->postDocument($this->supplierId, 'manual', null, $lines, ['entry_date' => $date, 'posted' => true] + $this->meta());
    }

    private function entryLines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT a.account_code, l.side, l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.supplier_id = ? AND l.entry_id = ? ORDER BY l.id');
        $stmt->execute([$this->supplierId, $entryId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }
}
