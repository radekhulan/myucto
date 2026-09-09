<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\PriceMatrixAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Repository\CatalogPricingProfileRepository;
use MyInvoice\Repository\CatalogPricingRuleRepository;
use MyInvoice\Repository\StockCurrencyRepository;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Eshop\Pricing\PriceMatrixCsv;
use MyInvoice\Service\Eshop\Pricing\PriceMatrixService;
use MyInvoice\Service\Eshop\Pricing\PriceMatrixWorker;
use MyInvoice\Service\Eshop\Pricing\PriceCalculationService;
use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PriceMatrixWorkflowTest extends StockTestCase
{
    private PriceMatrixService $matrix;
    private PriceMatrixWorker $worker;
    private CatalogJobItemRepository $jobItems;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matrix = $this->container->get(PriceMatrixService::class);
        $this->worker = $this->container->get(PriceMatrixWorker::class);
        $this->jobItems = $this->container->get(CatalogJobItemRepository::class);
    }

    public function testPreviewAndApplyUseFrozenPolicySnapshot(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $itemId = $this->item($supplierId, 'MATRIX-SNAPSHOT');
        $this->receiveStock($supplierId, $this->warehouse($supplierId), $itemId, '1.000', 100.0);
        $profiles = $this->container->get(CatalogPricingProfileRepository::class);
        $profileId = $profiles->insert($supplierId, $this->profile('matrix-snapshot', '10'));
        $this->container->get(CatalogPricingRuleRepository::class)->insert($supplierId, [
            'profile_id' => $profileId,
            'match_type' => 'default',
            'match_id' => null,
            'priority' => 0,
            'is_active' => true,
        ]);

        $preview = $this->matrix->preview($supplierId, $this->selection($itemId), $this->options('CZK', ensure: true));
        $profiles->update($supplierId, $profileId, $this->profile('matrix-snapshot', '50'));
        $preview = $this->worker->tick($supplierId);
        self::assertSame('completed', $preview['status']);
        self::assertSame(1, $preview['report']['counts']['ready']);
        $previewItem = $this->jobItems->page($supplierId, $preview['id'])['items'][0];
        self::assertSame('110.00', $previewItem['after']['cells']['CZK']['price']);
        self::assertSame($profileId, $previewItem['after']['cells']['CZK']['profile_id']);

        $apply = $this->matrix->apply($supplierId, $preview['id']);
        self::assertSame(1, $apply['total']);
        $jobResponse = $this->container->get(\MyInvoice\Action\Eshop\CatalogJobAction::class)->get(
            $this->request($supplierId, ['eshop.write' => 2, 'stock.items.write' => 2]), new Response(), ['id' => $preview['id']],
        );
        $jobPayload = json_decode((string) $jobResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($apply['id'], $jobPayload['apply_job_id'] ?? null);
        self::assertSame($apply['id'], $this->matrix->present($preview)['apply_job_id']);
        $apply = $this->worker->tick($supplierId);
        self::assertSame(1, $apply['report']['counts']['applied']);
        $price = $this->container->get(StockItemPriceRepository::class)->findByCurrency($supplierId, $itemId, 'CZK');
        self::assertSame('110.00', $price['computed_price']);
        self::assertSame($profileId, $price['computed_profile_id']);
        $locked = $this->matrix->preview($supplierId, $this->selection($itemId), [
            ...$this->options('CZK'),
            'overrides' => [['item_id' => $itemId, 'currency_code' => 'CZK', 'operation' => 'lock_current']],
        ]);
        $this->worker->tick($supplierId);
        $this->matrix->apply($supplierId, $locked['id']);
        self::assertSame(1, $this->worker->tick($supplierId)['report']['counts']['applied']);
        self::assertNull($this->container->get(StockItemPriceRepository::class)->findByCurrency($supplierId, $itemId, 'CZK')['computed_profile_id']);
    }

    public function testManualPriceIsPreservedAndMissingDeleteIsNoOp(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $this->currency($supplierId, 'EUR');
        $itemId = $this->item($supplierId, 'MATRIX-MANUAL');
        $this->container->get(PriceWriteService::class)->save($supplierId, $itemId, [[
            'currency_code' => 'CZK',
            'price_mode' => 'fixed',
            'fixed_price' => '90',
            'rounding' => 'none',
            'is_manual_override' => true,
            'use_pricing_rules' => false,
        ]]);
        $preview = $this->matrix->preview($supplierId, $this->selection($itemId), [
            'currencies' => ['CZK', 'EUR'],
            'on_date' => date('Y-m-d'),
            'ensure_missing' => false,
            'reprice' => true,
            'overrides' => [[
                'item_id' => $itemId,
                'currency_code' => 'EUR',
                'operation' => 'delete',
            ]],
        ]);
        $preview = $this->worker->tick($supplierId);
        self::assertSame(1, $preview['report']['counts']['unchanged']);
        self::assertSame(0, $preview['report']['counts']['ready']);
        $entry = $this->jobItems->page($supplierId, $preview['id'])['items'][0];
        self::assertSame('90.00', $entry['after']['cells']['CZK']['price']);
        self::assertTrue($entry['after']['cells']['CZK']['is_manual_override']);
        self::assertSame(1, $this->matrix->items($supplierId, $preview['id'], 1, 10, issue: 'manual_override')['pagination']['total']);
        self::assertSame(1, $this->matrix->items($supplierId, $preview['id'], 1, 10, currency: 'EUR', issue: 'missing_price')['pagination']['total']);
        self::assertSame(0, $this->matrix->items($supplierId, $preview['id'], 1, 10, currency: 'EUR', issue: 'manual_override')['pagination']['total']);
        self::assertSame(0, $this->matrix->apply($supplierId, $preview['id'])['total']);
    }

    public function testExplicitDeleteAndVersionConflictNeverOverwriteConcurrentChange(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $itemId = $this->item($supplierId, 'MATRIX-CAS');
        $writer = $this->container->get(PriceWriteService::class);
        $writer->save($supplierId, $itemId, [[
            'currency_code' => 'CZK',
            'price_mode' => 'fixed',
            'fixed_price' => '100',
            'is_manual_override' => true,
        ]]);
        $preview = $this->matrix->preview($supplierId, $this->selection($itemId), [
            ...$this->options('CZK'),
            'overrides' => [[
                'item_id' => $itemId,
                'currency_code' => 'CZK',
                'operation' => 'delete',
            ]],
        ]);
        $preview = $this->worker->tick($supplierId);
        self::assertSame(1, $preview['report']['counts']['ready']);
        $apply = $this->matrix->apply($supplierId, $preview['id']);

        $item = $this->itemsRepo->find($supplierId, $itemId);
        $item['name'] = 'Souběžně upravená karta';
        self::assertTrue($this->itemsRepo->updateVersioned($supplierId, $itemId, $item['row_version'], $item));
        $apply = $this->worker->tick($supplierId);
        self::assertSame(1, $apply['report']['counts']['conflict']);
        self::assertNotNull($this->container->get(StockItemPriceRepository::class)->findByCurrency($supplierId, $itemId, 'CZK'));

        $fresh = $this->matrix->preview($supplierId, $this->selection($itemId), [
            ...$this->options('CZK'),
            'overrides' => [[
                'item_id' => $itemId,
                'currency_code' => 'CZK',
                'operation' => 'delete',
            ]],
        ]);
        $fresh = $this->worker->tick($supplierId);
        $this->matrix->apply($supplierId, $fresh['id']);
        $this->worker->tick($supplierId);
        self::assertNull($this->container->get(StockItemPriceRepository::class)->findByCurrency($supplierId, $itemId, 'CZK'));
        self::assertNull($this->itemsRepo->find($supplierId, $itemId)['sale_price_without_vat']);
    }

    public function testCsvRoundTripEscapesFormulaAndProducesImportOverrides(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $itemId = $this->item($supplierId, '=MATRIX-CSV');
        $this->container->get(PriceWriteService::class)->save($supplierId, $itemId, [[
            'currency_code' => 'CZK',
            'price_mode' => 'fixed',
            'fixed_price' => '125',
            'is_manual_override' => true,
        ]]);
        $preview = $this->matrix->preview($supplierId, $this->selection($itemId), $this->options('CZK'));
        $preview = $this->worker->tick($supplierId);
        $csv = implode('', iterator_to_array($this->container->get(PriceMatrixCsv::class)->export($supplierId, $preview['id'], 'after')));
        self::assertStringContainsString("'=MATRIX-CSV", $csv);
        $import = $this->container->get(PriceMatrixCsv::class)->import($csv);
        self::assertSame([$itemId], $import['selection']['ids']);
        self::assertSame('upsert', $import['options']['overrides'][0]['operation']);
        self::assertSame('125.00', $import['options']['overrides'][0]['definition']['fixed_price']);
        $header = strstr($csv, "\n", true) . "\n";
        $overflow = ((string) PHP_INT_MAX) . '0';
        $invalid = $header . $overflow . ';sku;name;CZK;delete;;;;;;;;;;;;' . "\n";
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('neplatné ID');
        $this->container->get(PriceMatrixCsv::class)->import($invalid);
    }

    public function testRecomputeClearsStaleCzkMirrorButForeignOnlyPricesDoNot(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $this->currency($supplierId, 'EUR');
        $prices = $this->container->get(StockItemPriceRepository::class);
        $calculation = $this->container->get(PriceCalculationService::class);

        $czkItem = $this->item($supplierId, 'MATRIX-STALE-CZK');
        $this->itemsRepo->setSalePrice($supplierId, $czkItem, '110.00');
        $prices->upsert($supplierId, $czkItem, 'CZK', [
            'price_mode' => 'markup', 'markup_pct' => '10', 'fixed_price' => null,
            'rounding' => 'none', 'is_manual_override' => false, 'use_pricing_rules' => false,
        ]);
        $calculation->recompute($supplierId, $czkItem);
        self::assertNull($this->itemsRepo->find($supplierId, $czkItem)['sale_price_without_vat']);

        $eurItem = $this->item($supplierId, 'MATRIX-EUR-ONLY');
        $this->itemsRepo->setSalePrice($supplierId, $eurItem, '110.00');
        $prices->upsert($supplierId, $eurItem, 'EUR', [
            'price_mode' => 'fixed', 'markup_pct' => null, 'fixed_price' => '5',
            'rounding' => 'none', 'is_manual_override' => true, 'use_pricing_rules' => false,
        ]);
        $calculation->recompute($supplierId, $eurItem);
        self::assertSame('110.00', $this->itemsRepo->find($supplierId, $eurItem)['sale_price_without_vat']);
    }

    public function testActionRequiresBothWritePermissionsAndDoesNotExposeSnapshot(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $itemId = $this->item($supplierId, 'MATRIX-ACTION');
        $action = $this->container->get(PriceMatrixAction::class);
        $body = ['selection' => $this->selection($itemId), 'options' => $this->options('CZK')];

        $denied = $this->request($supplierId, ['eshop.write' => 2])->withParsedBody($body);
        self::assertSame(403, $action->preview($denied, new Response())->getStatusCode());

        $allowed = $this->request($supplierId, ['eshop.write' => 2, 'stock.items.write' => 2])->withParsedBody($body);
        $response = $action->preview($allowed, new Response());
        self::assertSame(202, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('input', $payload);
        self::assertSame(403, $action->items($denied, new Response(), ['id' => $payload['id']])->getStatusCode());
    }

    public function testCsvExportSkipsFailedRowsWithoutRepeatingTheBatch(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $itemId = $this->item($supplierId, 'MATRIX-FAILED-CSV');
        $preview = $this->matrix->preview($supplierId, $this->selection($itemId), $this->options('CZK', ensure: true));
        $preview = $this->worker->tick($supplierId);
        self::assertSame(1, $preview['report']['counts']['failed']);

        $csv = implode('', iterator_to_array($this->container->get(PriceMatrixCsv::class)->export($supplierId, $preview['id'], 'after')));
        self::assertSame(1, substr_count($csv, "\n"));
        self::assertStringContainsString('item_id;sku;name;currency_code', $csv);
    }

    public function testApplyRecalculatesOnlyCurrenciesIncludedInTheSnapshot(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $this->currency($supplierId, 'EUR');
        $itemId = $this->item($supplierId, 'MATRIX-BOUNDED');
        $writer = $this->container->get(PriceWriteService::class);
        $writer->save($supplierId, $itemId, [
            ['currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => '100'],
            ['currency_code' => 'EUR', 'price_mode' => 'fixed', 'fixed_price' => '5'],
        ]);
        $prices = $this->container->get(StockItemPriceRepository::class);
        $prices->upsert($supplierId, $itemId, 'EUR', [
            'price_mode' => 'fixed', 'markup_pct' => null, 'fixed_price' => '9',
            'rounding' => 'none', 'is_manual_override' => false, 'use_pricing_rules' => false,
        ]);

        $preview = $this->matrix->preview($supplierId, $this->selection($itemId), [
            ...$this->options('CZK'),
            'overrides' => [[
                'item_id' => $itemId,
                'currency_code' => 'CZK',
                'operation' => 'set_fixed',
                'fixed_price' => '120',
            ]],
        ]);
        $preview = $this->worker->tick($supplierId);
        $filtered = $this->matrix->items($supplierId, $preview['id'], 1, 10, currency: 'CZK', issue: 'deviation');
        self::assertSame(1, $filtered['pagination']['total']);
        self::assertSame('20.000', $filtered['items'][0]['after']['cells']['CZK']['deviation_pct']);
        $this->matrix->apply($supplierId, $preview['id']);
        $this->worker->tick($supplierId);

        self::assertSame('120.00', $prices->findByCurrency($supplierId, $itemId, 'CZK')['computed_price']);
        self::assertSame('5.00', $prices->findByCurrency($supplierId, $itemId, 'EUR')['computed_price']);
        self::assertSame('9.00', $prices->findByCurrency($supplierId, $itemId, 'EUR')['fixed_price']);
    }

    public function testApplyRollsBackWhenPurchaseCostChangedAfterPreview(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $itemId = $this->item($supplierId, 'MATRIX-COST-DRIFT');
        $warehouseId = $this->warehouse($supplierId);
        $this->receiveStock($supplierId, $warehouseId, $itemId, '1.000', 100.0);
        $profileId = $this->container->get(CatalogPricingProfileRepository::class)
            ->insert($supplierId, $this->profile('matrix-cost-drift', '10'));
        $this->container->get(CatalogPricingRuleRepository::class)->insert($supplierId, [
            'profile_id' => $profileId,
            'match_type' => 'default',
            'match_id' => null,
            'priority' => 0,
            'is_active' => true,
        ]);

        $preview = $this->matrix->preview($supplierId, $this->selection($itemId), $this->options('CZK', ensure: true));
        $preview = $this->worker->tick($supplierId);
        self::assertSame(1, $preview['report']['counts']['ready']);
        $this->matrix->apply($supplierId, $preview['id']);

        $this->db->pdo()->prepare('UPDATE stock_levels SET value_total = 200, avg_unit_cost = 200
            WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?')
            ->execute([$supplierId, $warehouseId, $itemId]);
        $apply = $this->worker->tick($supplierId);

        self::assertSame(1, $apply['report']['counts']['conflict']);
        self::assertNull($this->container->get(StockItemPriceRepository::class)->findByCurrency($supplierId, $itemId, 'CZK'));
        $entry = $this->jobItems->page($supplierId, $apply['id'])['items'][0];
        self::assertSame('version_conflict', $entry['error_code']);
        self::assertNull($entry['after']['cells']['CZK']);
    }

    public function testAllMatchingCellLimitUsesActualSnapshotCount(): void
    {
        $supplierId = $this->createSupplier();
        foreach (['CZK', 'EUR', 'USD', 'GBP'] as $currency) {
            $this->currency($supplierId, $currency);
        }
        $this->item($supplierId, 'MATRIX-SMALL-SELECTION');

        $job = $this->matrix->preview($supplierId, [
            'all_matching' => true,
            'filters' => ['q' => 'MATRIX-SMALL-SELECTION'],
            'excluded_ids' => [],
        ], [
            'currencies' => ['CZK', 'EUR', 'USD', 'GBP'],
            'on_date' => date('Y-m-d'),
            'ensure_missing' => false,
            'reprice' => false,
            'overrides' => [],
        ]);

        self::assertSame(1, $job['total']);
    }

    public function testUnselectedBrokenCurrencyDoesNotBlockFixedPriceChange(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $this->currency($supplierId, 'EUR');
        $itemId = $this->item($supplierId, 'MATRIX-UNSELECTED');
        $this->container->get(StockItemPriceRepository::class)->upsert($supplierId, $itemId, 'EUR', [
            'price_mode' => 'markup', 'markup_pct' => '10', 'fixed_price' => null,
            'rounding' => 'none', 'is_manual_override' => false, 'use_pricing_rules' => true,
        ]);
        $preview = $this->matrix->preview($supplierId, $this->selection($itemId), [
            ...$this->options('CZK'),
            'overrides' => [['item_id' => $itemId, 'currency_code' => 'CZK', 'operation' => 'set_fixed', 'fixed_price' => '150']],
        ]);
        self::assertSame(1, $this->worker->tick($supplierId)['report']['counts']['ready']);
        $this->matrix->apply($supplierId, $preview['id']);
        self::assertSame(1, $this->worker->tick($supplierId)['report']['counts']['applied']);
    }

    public function testDisabledRepricePreservesUnchangedSelectedCurrency(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $this->currency($supplierId, 'EUR');
        $itemId = $this->item($supplierId, 'MATRIX-NO-REPRICE');
        $warehouseId = $this->warehouse($supplierId);
        $this->receiveStock($supplierId, $warehouseId, $itemId, '1.000', 100.0);
        $this->container->get(PriceWriteService::class)->save($supplierId, $itemId, [
            ['currency_code' => 'CZK', 'price_mode' => 'markup', 'markup_pct' => '10', 'rounding' => 'none'],
            ['currency_code' => 'EUR', 'price_mode' => 'fixed', 'fixed_price' => '10', 'rounding' => 'none'],
        ]);
        $this->db->pdo()->prepare('UPDATE stock_levels SET value_total = 200, avg_unit_cost = 200 WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ?')
            ->execute([$supplierId, $warehouseId, $itemId]);
        $preview = $this->matrix->preview($supplierId, $this->selection($itemId), [
            ...$this->options('CZK'), 'currencies' => ['CZK', 'EUR'], 'reprice' => false,
            'overrides' => [['item_id' => $itemId, 'currency_code' => 'EUR', 'operation' => 'set_fixed', 'fixed_price' => '15']],
        ]);
        self::assertSame(1, $this->worker->tick($supplierId)['report']['counts']['ready']);
        $this->matrix->apply($supplierId, $preview['id']);
        self::assertSame(1, $this->worker->tick($supplierId)['report']['counts']['applied']);
        self::assertSame('110.00', $this->container->get(StockItemPriceRepository::class)->findByCurrency($supplierId, $itemId, 'CZK')['computed_price']);
    }

    public function testCsvChangesOnlyCellsIncludedForEachItem(): void
    {
        $sid = $this->createSupplier();
        $this->currency($sid, 'CZK');
        $this->currency($sid, 'EUR');
        $first = $this->item($sid, 'CSV-CELL-A');
        $second = $this->item($sid, 'CSV-CELL-B');
        $prices = $this->container->get(StockItemPriceRepository::class);
        foreach ([[$first, 'EUR'], [$second, 'CZK']] as [$id, $currency]) {
            $prices->upsert($sid, $id, $currency, [
                'price_mode' => 'markup', 'markup_pct' => '10', 'fixed_price' => null,
                'rounding' => 'none', 'is_manual_override' => false, 'use_pricing_rules' => true,
            ]);
        }
        $header = 'item_id;sku;name;currency_code;operation;price_mode;markup_pct;fixed_price;rounding;is_manual_override;use_pricing_rules;price;cost_czk;margin_pct;rate;profile_id;rule_id';
        $csv = $header . "\n{$first};A;A;CZK;set_fixed;fixed;;150;none;1;0;;;;;;\n{$second};B;B;EUR;set_fixed;fixed;;8;none;1;0;;;;;;\n";
        $input = $this->container->get(PriceMatrixCsv::class)->import($csv);
        $preview = $this->matrix->preview($sid, $input['selection'], $input['options']);
        self::assertSame(2, $this->worker->tick($sid)['report']['counts']['ready']);
        $this->matrix->apply($sid, $preview['id']);
        self::assertSame(2, $this->worker->tick($sid)['report']['counts']['applied']);
        self::assertSame('150.00', $prices->findByCurrency($sid, $first, 'CZK')['computed_price']);
        self::assertSame('8.00', $prices->findByCurrency($sid, $second, 'EUR')['computed_price']);
        self::assertNull($prices->findByCurrency($sid, $first, 'EUR')['computed_price']);
        self::assertNull($prices->findByCurrency($sid, $second, 'CZK')['computed_price']);
    }

    private function currency(int $supplierId, string $code): void
    {
        $this->container->get(StockCurrencyRepository::class)->insert($supplierId, [
            'code' => $code,
            'name' => $code,
            'is_default' => $code === 'CZK',
        ]);
    }

    private function profile(string $code, string $percentage): array
    {
        return [
            'code' => $code,
            'name' => 'Matrix profile',
            'currency_code' => 'CZK',
            'calculation_mode' => 'markup',
            'percentage' => $percentage,
            'rounding' => 'none',
            'fx_source' => 'cnb',
            'max_rate_age_days' => 7,
            'is_active' => true,
        ];
    }

    private function selection(int $itemId): array
    {
        return ['all_matching' => false, 'ids' => [$itemId]];
    }

    private function options(string $currency, bool $ensure = false): array
    {
        return [
            'currencies' => [$currency],
            'on_date' => date('Y-m-d'),
            'ensure_missing' => $ensure,
            'reprice' => true,
            'overrides' => [],
        ];
    }

    private function request(int $supplierId, array $permissions): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/eshop/pricing/matrix/preview')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(321, 'Matrix', 'staff', true, $permissions));
    }
}
