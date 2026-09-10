<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Sets\ProductSetDefinition;
use MyInvoice\Service\Eshop\Sets\ProductSetGraph;
use PHPUnit\Framework\TestCase;

final class ProductSetGraphTest extends TestCase
{
    public function testDiamondAndRepeatedComponentsAggregateExactly(): void
    {
        $definitions = [10 => $this->set([[20, '2'], [30, '1'], [1, '0.500']]), 20 => $this->set([[1, '1.250'], [2, '2']]), 30 => $this->set([[1, '3'], [2, '1']])];
        $graph = new ProductSetGraph();
        $graph->assertAcyclic($definitions);
        $expanded = $graph->expand(10, '2', $definitions);
        self::assertSame([1 => '12.000', 2 => '10.000'], $expanded['components']);
        self::assertCount(3, $expanded['tree']['children']);
    }

    public function testCycleThroughOptionalComponentIsRejected(): void
    {
        $definitions = [10 => ProductSetDefinition::normalize(['groups' => [[
            'code' => 'extra', 'name' => 'Fixture option', 'min' => 0, 'max' => 1,
            'options' => [['code' => 'nested', 'name' => 'Fixture', 'item_id' => 20, 'quantity' => '1']],
        ]]]), 20 => $this->set([[10, '1']])];
        $this->expectException(EshopException::class);
        $this->expectExceptionMessage('cyklus');
        (new ProductSetGraph())->assertAcyclic($definitions);
    }

    public function testConfiguratorUsesSelectedOptionsAndSurcharges(): void
    {
        $definition = ProductSetDefinition::normalize(['components' => [['item_id' => 1, 'quantity' => '1']], 'groups' => [[
            'code' => 'battery', 'name' => 'Battery', 'min' => 1, 'max' => 1,
            'options' => [
                ['code' => 'small', 'name' => 'Small', 'item_id' => 2, 'quantity' => '1'],
                ['code' => 'large', 'name' => 'Large', 'item_id' => 3, 'quantity' => '2', 'surcharges' => ['CZK' => '15']],
            ],
        ]]]);
        $expanded = (new ProductSetGraph())->expand(10, '3', [10 => $definition], [10 => ['battery' => ['large']]]);
        self::assertSame([1 => '3.000', 3 => '6.000'], $expanded['components']);
        self::assertSame('45.00000', $expanded['tree']['surcharges']['CZK']);
        $this->expectException(EshopException::class);
        (new ProductSetGraph())->expand(10, '1', [10 => $definition]);
    }

    public function testFractionalComponentCannotSilentlyRoundAwayStock(): void
    {
        $this->expectException(EshopException::class);
        (new ProductSetGraph())->expand(10, '0.001', [10 => $this->set([[1, '0.001']])]);
    }

    public function testDepthLimitIsIndependentOfTraversalOrder(): void
    {
        $definitions = [];
        for ($id = 1; $id <= ProductSetGraph::MAX_DEPTH; $id++) $definitions[$id] = $this->set([[$id + 1, '1']]);
        $graph = new ProductSetGraph();
        $graph->assertAcyclic($definitions);
        $graph->assertAcyclic(array_reverse($definitions, true));
        self::assertSame([13 => '1.000'], $graph->expand(1, '1', $definitions)['components']);
        $definitions[0] = $this->set([[1, '1']]);
        $this->expectException(EshopException::class);
        $graph->assertAcyclic($definitions);
    }

    public function testExpansionLimitsDiamondExplosion(): void
    {
        $definitions = [];
        for ($id = 1; $id <= 10; $id++) $definitions[$id] = $this->set([[$id + 1, '1'], [$id + 1, '1']]);
        $this->expectException(EshopException::class);
        (new ProductSetGraph())->expand(1, '1', $definitions);
    }

    private function set(array $components): array
    {
        return ProductSetDefinition::normalize(['components' => array_map(static fn (array $row): array => ['item_id' => $row[0], 'quantity' => $row[1]], $components)]);
    }
}
