<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\ImportedPlanRevisionService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Reports\AssetDepreciationCardReportService;
use MyInvoice\Service\ActivityLogger;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class ImportedAssetReviewTest extends TestCase
{
    private Connection $db;
    private AssetRepository $repository;
    private AssetService $service;
    private int $supplier;
    private int $asset;
    private int $actor;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->repository = $container->get(AssetRepository::class);
        $this->service = $container->get(AssetService::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->supplier = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $this->supplier);
        self::assertGreaterThan(0, $this->actor);
        $pdo->beginTransaction();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2098, '2098-01-01', '2098-12-31');
        $this->asset = (int) $this->service->createImported($this->supplier, $this->card(), $this->source(),
            ['user_id' => $this->actor])['asset']['id'];
    }

    protected function tearDown(): void
    {
        ImportedAssetColumnStatement::$columns = [];
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testListDoesNotFetchPlanColumnAndDetailKeepsExactPlan(): void
    {
        $native = $this->service->create($this->supplier,
            array_replace($this->card(), ['inventory_number' => 'SYN-REVIEW-NATIVE']));
        $pdo = $this->db->pdo();
        $statementClass = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ImportedAssetColumnStatement::class]);
        try {
            $listed = $this->repository->list($this->supplier, ['q' => 'SYN-REVIEW-', 'per_page' => 20]);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $statementClass);
        }
        self::assertCount(2, $listed['items']);
        self::assertNotEmpty(ImportedAssetColumnStatement::$columns, 'Ověřujeme skutečná metadata sloupců DB výsledku.');
        self::assertContains('inventory_number', ImportedAssetColumnStatement::$columns);
        self::assertNotContains('imported_depreciation_plan', ImportedAssetColumnStatement::$columns,
            'JSON nesmí být vybrán z DB ani tehdy, kdyby ho list odstranil až po načtení.');
        foreach ($listed['items'] as $item) self::assertArrayNotHasKey('imported_depreciation_plan', $item);
        self::assertSame($this->source(), $this->repository->find($this->supplier, $this->asset)['imported_depreciation_plan']);
        self::assertSame($this->source(), $this->service->get($this->supplier, $this->asset)['imported_depreciation_plan']);
        self::assertNull($this->repository->find($this->supplier, (int) $native['asset']['id'])['imported_depreciation_plan']);
        self::assertSame([$this->asset => $this->source()], $this->repository->importedPlansForAssets(
            $this->supplier, [$this->asset, (int) $native['asset']['id']]));
        self::assertSame([], $this->repository->importedPlansForAssets($this->supplier + 1000000, [$this->asset]));
    }

    public function testWritableNumericStringsAlreadyNormalizeToUnchangedValues(): void
    {
        $updated = $this->service->update($this->supplier, $this->asset, [
            'name' => 'Syntetická licence se změněným názvem',
            'input_price' => '12000.07', 'opening_tax_years' => '1', 'opening_tax_amount' => '6000.00',
            'opening_acc_months' => '6', 'opening_acc_amount' => '6000.00', 'acc_useful_life_months' => '12',
            'acc_residual_value' => '0.00', 'is_first_owner' => '0', 'is_m1_vehicle' => '0',
            'm1_limit_exception' => '0', 'is_zero_emission' => '0',
        ])['asset'];
        self::assertSame('Syntetická licence se změněným názvem', $updated['name']);
        self::assertSame($this->source(), $updated['imported_depreciation_plan']);
        self::assertSame(12000.07, $updated['input_price']);
    }

    public function testEchoedReadonlyNumericStringsDoNotBlockMetadataUpdate(): void
    {
        $echo = $this->service->get($this->supplier, $this->asset);
        unset($echo['imported_depreciation_plan']);
        foreach (['id', 'supplier_id', 'created_by'] as $field) $echo[$field] = (string) $echo[$field];
        $echo['name'] = 'Metadata s echoed readonly identifikátory';
        $updated = $this->service->update($this->supplier, $this->asset, $echo)['asset'];
        self::assertSame('Metadata s echoed readonly identifikátory', $updated['name']);
        self::assertSame($this->asset, $updated['id']);
        self::assertSame($this->supplier, $updated['supplier_id']);
        self::assertSame($this->actor, $updated['created_by']);
        self::assertSame($this->source(), $updated['imported_depreciation_plan']);
    }

    #[DataProvider('equalShares')]
    public function testUnchangedCoOwnerShareAcceptsEquivalentNumericTypes(int|float|string $share): void
    {
        $card = array_replace($this->card(), ['inventory_number' => 'SYN-REVIEW-SHARE',
            'depreciator_ground' => 'co_owner', 'co_ownership_share' => 25.0]);
        $created = $this->service->createImported($this->supplier, $card, $this->source())['asset'];
        $updated = $this->service->update($this->supplier, (int) $created['id'], [
            'co_ownership_share' => $share, 'name' => 'Stejný podíl s jiným JSON typem',
        ])['asset'];
        self::assertSame('Stejný podíl s jiným JSON typem', $updated['name']);
        self::assertSame(25.0, (float) $updated['co_ownership_share']);
        self::assertSame($this->source(), $updated['imported_depreciation_plan']);
    }

    public static function equalShares(): array
    {
        return ['integer' => [25], 'float' => [25.0], 'string' => ['25']];
    }

    public function testActualCoOwnerShareChangeRemainsLocked(): void
    {
        $created = $this->service->createImported($this->supplier, array_replace($this->card(), [
            'inventory_number' => 'SYN-REVIEW-SHARE', 'depreciator_ground' => 'co_owner',
            'co_ownership_share' => 25.0,
        ]), $this->source())['asset'];
        try {
            $this->service->update($this->supplier, (int) $created['id'], ['co_ownership_share' => '26']);
            self::fail('Změna podílu není pouhá změna JSON typu.');
        } catch (AssetException $e) {
            self::assertSame('imported_plan_locked', $e->errorCode);
        }
        self::assertSame(25.0, (float) $this->service->get($this->supplier, (int) $created['id'])['co_ownership_share']);
    }

    public function testBulkDepreciationReportRetainsEffectiveImportedPlanWithoutAddingItToList(): void
    {
        $container = Bootstrap::buildContainer();
        $revisions = new ImportedPlanRevisionService($this->db, $this->repository, $this->service,
            $container->get(AccountingPeriodRepository::class), new MockClock('2098-01-15T10:00:00+00:00'), $container->get(ActivityLogger::class));
        $input = ['effective_from' => '2098-01', 'remaining_months' => 14,
            'reason' => 'Syntetický nový odhad pro hromadnou sestavu', 'expected_revision' => 0];
        $preview = $revisions->preview($this->supplier, $this->asset, $input, $this->actor);
        $revisions->revise($this->supplier, $this->asset, $input + [
            'expected_calendar_start_month' => $preview['calendar_start_month'],
            'expected_calendar_fingerprint' => $preview['calendar_fingerprint'],
        ], $this->actor);
        $reports = $container->get(AssetDepreciationCardReportService::class);
        $period = $container->get(AccountingPeriodRepository::class)->findByYear($this->supplier, 2098);
        $single = $reports->buildForAsset($this->supplier, $this->asset)['cards'][0];
        self::assertSame([5142.84, 857.23], array_column($single['rows'], 'depreciation'));
        $bulk = $reports->build($this->supplier, (int) $period['id']);
        $matched = array_values(array_filter($bulk['cards'],
            static fn (array $card): bool => $card['inventory_number'] === 'SYN-REVIEW-IMPORTED'));
        self::assertCount(1, $matched);
        self::assertSame($single['rows'], $matched[0]['rows'], 'Hromadná sestava nesmí převzatý plán nahradit nativním přepočtem.');
        $listed = $this->repository->list($this->supplier, ['q' => 'SYN-REVIEW-IMPORTED']);
        self::assertArrayNotHasKey('imported_depreciation_plan', $listed['items'][0]);
    }

    #[DataProvider('financialChanges')]
    public function testActualFinancialChangesRemainLocked(string $field, mixed $value): void
    {
        try {
            $this->service->update($this->supplier, $this->asset, [$field => $value, 'name' => 'Nesmí se uložit']);
            self::fail('Skutečná změna převzatých parametrů musí zůstat zamčená.');
        } catch (AssetException $e) {
            self::assertSame('imported_plan_locked', $e->errorCode);
        }
        $stored = $this->service->get($this->supplier, $this->asset);
        self::assertSame($this->card()['name'], $stored['name']);
        self::assertSame($this->source(), $stored['imported_depreciation_plan']);
    }

    public static function financialChanges(): array
    {
        return [['input_price', '12001.07'], ['opening_tax_amount', '6001.00'],
            ['opening_acc_months', '7'], ['acc_useful_life_months', '13'], ['acc_method', 'by_tax']];
    }

    public function testExplicitPlanInputIsStillRejected(): void
    {
        try {
            $this->service->update($this->supplier, $this->asset, ['imported_depreciation_plan' => null]);
            self::fail('JSON nelze přepsat ani vynulovat běžnou editací karty.');
        } catch (AssetException $e) {
            self::assertSame('imported_plan_internal_only', $e->errorCode);
        }
        self::assertSame($this->source(), $this->service->get($this->supplier, $this->asset)['imported_depreciation_plan']);
    }

    private function card(): array
    {
        return ['inventory_number' => 'SYN-REVIEW-IMPORTED', 'name' => 'Syntetická licence pro review',
            'kind' => 'intangible', 'asset_account_code' => '013', 'accumulated_account_code' => '073',
            'acquisition_account_code' => '041', 'input_price' => 12000.07, 'acquisition_date' => '2097-06-03',
            'put_into_use_date' => '2097-06-05', 'status' => 'in_use', 'tax_method' => 'by_accounting',
            'acc_method' => 'straight_line', 'acc_useful_life_months' => 12, 'opening_tax_years' => 1,
            'opening_tax_amount' => 6000.0, 'opening_acc_months' => 6, 'opening_acc_amount' => 6000.0];
    }

    private function source(): array
    {
        return ['version' => 1, 'source_program' => 'Synthetic source', 'source_tax_method' => 'synthetic-tax',
            'source_accounting_method' => 'synthetic-accounting', 'accounting' => [
                ['month' => '2098-01', 'amount_cents' => 100000], ['month' => '2098-02', 'amount_cents' => 100000],
                ['month' => '2098-03', 'amount_cents' => 100000], ['month' => '2098-04', 'amount_cents' => 100000],
                ['month' => '2098-05', 'amount_cents' => 100000], ['month' => '2098-06', 'amount_cents' => 100007],
            ], 'tax' => [['fiscal_year' => 2098, 'amount_cents' => 600007]]];
    }
}

/** Zachytí skutečné sloupce vybrané DB, bez závislosti na syntaxi dotazu repository. */
final class ImportedAssetColumnStatement extends \PDOStatement
{
    public static array $columns = [];

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        for ($column = 0; $column < $this->columnCount(); $column++) {
            $meta = $this->getColumnMeta($column);
            if (isset($meta['name'])) self::$columns[] = $meta['name'];
        }
        return $result;
    }
}
