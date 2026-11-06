<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ImportedAssetPlanTest extends TestCase
{
    private Connection $db;
    private AssetService $service;
    private DepreciationPostingService $posting;
    private DepreciationEntryRepository $entries;
    private JournalEntryRepository $journal;
    private int $supplier;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(AssetService::class);
        $this->posting = $container->get(DepreciationPostingService::class);
        $this->entries = $container->get(DepreciationEntryRepository::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->supplier = (int) $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $this->supplier, 'Test requires a seeded test tenant.');
        $this->db->pdo()->beginTransaction();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2098, '2098-01-01', '2098-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testExactPlanSurvivesPersistenceBookingDisposalAndRevert(): void
    {
        $created = $this->service->createImported($this->supplier, $this->card(), $this->source());
        $id = (int) $created['asset']['id'];
        self::assertSame($this->source(), $created['asset']['imported_depreciation_plan']);
        self::assertNull($this->journal->findBySource($this->supplier, 'asset', $id));
        self::assertSame([], $this->entries->forAsset($id), 'Import does not repost historical or future depreciation.');
        $this->assertExactPlan($id);
        $this->posting->bookYear($this->supplier, 2098);
        $entry = $this->entries->findYear($id, 'accounting', 2098);
        self::assertNotNull($entry);
        self::assertSame(36000.0, $entry['amount']);
        $journal = $this->journal->findBySource($this->supplier, 'depreciation', (int) $entry['id']);
        self::assertNotNull($journal);
        $this->posting->bookYear($this->supplier, 2098);
        self::assertSame($entry['id'], $this->entries->findYear($id, 'accounting', 2098)['id']);
        self::assertSame($journal['id'], $this->journal->findBySource($this->supplier, 'depreciation', (int) $entry['id'])['id']);
        $this->assertExactPlan($id);

        $this->service->dispose($this->supplier, $id, ['date' => '2098-02-12', 'type' => 'sold', 'price' => 18020.0], []);
        self::assertSame(17980.0, $this->entries->findYear($id, 'accounting', 2098)['amount']);
        self::assertSame(17980.0, $this->entries->findYear($id, 'tax', 2098)['amount']);
        self::assertSame([8990.0, 8990.0], array_column($this->service->plan($this->supplier, $id)['accounting'][0]['months'], 'amount'));
        $this->service->revertDisposal($this->supplier, $id);
        $this->assertExactPlan($id);
        $this->posting->bookYear($this->supplier, 2098);
        self::assertSame(36000.0, $this->entries->findYear($id, 'accounting', 2098)['amount']);
    }

    public function testOnlyMetadataCanChangeAndDraftActivationDoesNotPostAcquisition(): void
    {
        $id = (int) $this->service->createImported($this->supplier, array_replace($this->card(), ['status' => 'draft']), $this->source())['asset']['id'];
        $this->service->update($this->supplier, $id, ['name' => 'Renamed synthetic asset']);
        foreach ([
            fn () => $this->service->update($this->supplier, $id, ['input_price' => 97000]),
            fn () => $this->service->addImprovement($this->supplier, $id, ['completed_on' => '2098-01-05', 'amount' => 3000]),
            fn () => $this->service->pauseYear($this->supplier, $id, 2098),
            fn () => $this->service->overrideTaxYear($this->supplier, $id, 2098, 100.0, 'override'),
            fn () => $this->service->putIntoUse($this->supplier, $id, '2096-08-05', true, []),
        ] as $call) $this->reject($call, 'imported_plan_locked');
        $this->service->putIntoUse($this->supplier, $id, '2096-08-05', false, []);
        self::assertSame('in_use', $this->service->get($this->supplier, $id)['status']);
        self::assertNull($this->journal->findBySource($this->supplier, 'asset', $id));
        $this->reject(fn () => $this->service->update($this->supplier, $id, ['imported_depreciation_plan' => null]), 'imported_plan_internal_only');
        $this->reject(fn () => $this->service->create($this->supplier, $this->card() + ['imported_depreciation_plan' => $this->source()]), 'imported_plan_internal_only');
    }

    public function testJournalDisposalRejectsConflictingConfirmedYearBeforeChangingCard(): void
    {
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $this->posting->bookYear($this->supplier, 2098);
        $this->reject(fn () => $this->service->disposeFromJournal($this->supplier, $id,
            ['date' => '2098-02-12', 'type' => 'sold']), 'imported_plan_conflict');
        self::assertSame('in_use', $this->service->get($this->supplier, $id)['status']);
        $this->assertExactPlan($id);
    }

    public function testJournalDisposalRevertCannotLeavePartialConfirmedYear(): void
    {
        $id = (int) $this->service->createImported($this->supplier, $this->card(), $this->source())['asset']['id'];
        $this->service->disposeFromJournal($this->supplier, $id, ['date' => '2098-02-12', 'type' => 'sold']);
        $this->posting->bookYear($this->supplier, 2098);
        $this->reject(fn () => $this->service->revertDisposal($this->supplier, $id), 'imported_plan_conflict');
        self::assertSame('disposed', $this->service->get($this->supplier, $id)['status']);
        self::assertSame(17980.0, $this->service->plan($this->supplier, $id)['accounting'][0]['amount']);
    }

    public function testTaxEvidenceSupplierCannotImportPlan(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplier]);
        $this->reject(fn () => $this->service->createImported($this->supplier, $this->card(), $this->source()), 'imported_plan_invalid');
        $count = $this->db->pdo()->prepare("SELECT COUNT(*) FROM assets WHERE supplier_id = ? AND inventory_number = 'SYN-IMPORTED-1'");
        $count->execute([$this->supplier]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    private function assertExactPlan(int $id): void
    {
        $plan = $this->service->plan($this->supplier, $id);
        self::assertSame([8990.0, 8990.0, 8990.0, 9030.0], array_column($plan['accounting'][0]['months'], 'amount'));
        self::assertSame(36000.0, $plan['tax'][0]['amount']);
    }

    private function reject(callable $call, string $code): void
    {
        try { $call(); self::fail('Expected ' . $code); }
        catch (AssetException $e) { self::assertSame($code, $e->errorCode); }
    }

    private function card(): array
    {
        return ['inventory_number' => 'SYN-IMPORTED-1', 'name' => 'Synthetic imported software', 'kind' => 'intangible',
            'asset_account_code' => '013', 'accumulated_account_code' => '073', 'acquisition_account_code' => '041',
            'input_price' => 96000.0, 'acquisition_date' => '2096-08-03', 'put_into_use_date' => '2096-08-05',
            'status' => 'in_use', 'tax_method' => 'by_accounting', 'acc_method' => 'straight_line',
            'acc_useful_life_months' => 20, 'opening_tax_years' => 2, 'opening_tax_amount' => 60000.0,
            'opening_acc_months' => 16, 'opening_acc_amount' => 60000.0];
    }

    private function source(): array
    {
        return ['version' => 1, 'source_program' => 'Synthetic source', 'source_tax_method' => 'source-tax',
            'source_accounting_method' => 'source-accounting', 'accounting' => [
                ['month' => '2098-01', 'amount_cents' => 899000], ['month' => '2098-02', 'amount_cents' => 899000],
                ['month' => '2098-03', 'amount_cents' => 899000], ['month' => '2098-04', 'amount_cents' => 903000],
            ], 'tax' => [['fiscal_year' => 2098, 'amount_cents' => 3600000]]];
    }
}
