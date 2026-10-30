<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Dashboard;

use MyInvoice\Service\Dashboard\DashboardWidgetAccess;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DashboardWidgetRegistryTest extends TestCase
{
    public function testManifestHasCompleteDefinitionsAndValidPresets(): void
    {
        $registry = new DashboardWidgetRegistry();
        self::assertCount(24, $registry->all());
        $types = [];
        foreach ($registry->all() as $widget) {
            self::assertNotContains($widget['type'], $types);
            $types[] = $widget['type'];
            self::assertContains($widget['accessRule'], DashboardWidgetAccess::RULES);
            self::assertContains($widget['scope'], DashboardWidgetAccess::SCOPES);
            self::assertContains($widget['defaultSpan'], $widget['spans']);
            self::assertContains($widget['defaultHeight'], $widget['heights']);
            if ($widget['type'] === 'layout.divider') {
                self::assertSame([], $widget['dependencies']);
            } else {
                self::assertNotEmpty($widget['dependencies']);
            }
            self::assertNotEmpty($widget['titleKey']);
            self::assertNotEmpty($widget['descriptionKey']);
            self::assertIsBool($widget['repeatable']);
        }
        $company = json_decode(file_get_contents(dirname(__DIR__, 4) . '/web/shared/dashboard-presets.json'), true);
        foreach ($company['presets'] as $layout) {
            self::assertCount(count($layout['widgets']), $registry->validate($layout)['widgets']);
        }
        self::assertEquals($registry->defaultLayout('portal'), $registry->validate($company['presets']['company-v1']));
        self::assertCount(39, $registry->defaultLayout('home')['widgets']);
        self::assertCount(32, $registry->defaultLayout('portal')['widgets']);
    }

    public function testEmptyCustomLayoutDoesNotReintroduceDefaults(): void
    {
        $layout = ['schemaVersion' => 1, 'presetVersion' => 1, 'widgets' => []];
        self::assertSame($layout, (new DashboardWidgetRegistry())->validate($layout));
    }

    public function testKnownUnavailableWidgetIsStructurallyValid(): void
    {
        $layout = self::layout();
        $layout['widgets'] = [['id' => 'hidden', 'type' => 'accounting.status', 'span' => 12, 'options' => []]];
        self::assertSame('accounting.status', (new DashboardWidgetRegistry())->validate($layout)['widgets'][0]['type']);
    }

    #[DataProvider('invalidLayouts')]
    public function testRejectsInvalidLayouts(mixed $layout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DashboardWidgetRegistry())->validate($layout);
    }

    public static function invalidLayouts(): iterable
    {
        yield 'scalar' => [false];
        $base = self::layout();
        foreach ([['schemaVersion', 2], ['schemaVersion', '1'], ['presetVersion', 0], ['presetVersion', 2], ['supplier_id', 12]] as [$key, $value]) {
            yield $key . ':' . $value => [array_replace($base, [$key => $value])];
        }
        foreach ([['id', ''], ['id', '../bad'], ['id', str_repeat('a', 65)], ['type', 'unknown'], ['span', '3'], ['span', 2], ['span', 13], ['span', 4.5], ['height', 'giant'], ['height', 300], ['height', 'normal'], ['height', null], ['options', ['period' => 'invalid']], ['options', ['period' => null]], ['options', ['unknown' => 1]], ['options', ['period' => ['ytd']]], ['options', ['currency' => 'czk']]] as $i => [$key, $value]) {
            $layout = $base;
            $layout['widgets'][0][$key] = $value;
            yield 'instance ' . $i => [$layout];
        }
        $duplicate = $base;
        $duplicate['widgets'][] = $base['widgets'][0];
        yield 'duplicate id' => [$duplicate];
        $duplicate['widgets'] = [
            ['id' => 'one', 'type' => 'tax.deadlines', 'span' => 6, 'options' => []],
            ['id' => 'two', 'type' => 'tax.deadlines', 'span' => 6, 'options' => []],
        ];
        yield 'non repeatable' => [$duplicate];
        $many = $base;
        $many['widgets'] = array_map(static fn (int $i): array => array_replace($base['widgets'][0], ['id' => 'kpi-' . $i]), range(1, 33));
        yield 'data instance limit' => [$many];
        $dividers = $base;
        $dividers['widgets'] = array_map(static fn (int $i): array => ['id' => 'divider-' . $i, 'type' => 'layout.divider', 'span' => 12, 'options' => []], range(1, 33));
        yield 'divider limit' => [$dividers];
        $total = $many;
        $total['widgets'] = array_merge($many['widgets'], $dividers['widgets']);
        yield 'total instance limit' => [$total];
        $oversize = $base;
        $oversize['widgets'][0]['options']['currency'] = str_repeat('A', 17000);
        yield 'byte limit' => [$oversize];
        $extra = $base;
        $extra['widgets'][0]['companyId'] = 123;
        yield 'instance unknown keys' => [$extra];
        $numeric = $base;
        $numeric['widgets'] = [1 => $base['widgets'][0]];
        yield 'non list' => [$numeric];
    }

    public function testOptionTypesAreStrictAndDefaultsAreCanonical(): void
    {
        $layout = self::layout();
        $layout['widgets'][0] = ['id' => 'monthly', 'type' => 'business.monthly', 'span' => 9, 'options' => ['months' => '12']];
        try {
            (new DashboardWidgetRegistry())->validate($layout);
            self::fail('Číselná volba nesmí přijmout řetězec.');
        } catch (\InvalidArgumentException) {
            $layout['widgets'][0]['options']['months'] = 12;
        }
        $validated = (new DashboardWidgetRegistry())->validate($layout);
        self::assertEquals((object) ['months' => 12, 'currency' => 'auto'], $validated['widgets'][0]['options']);
    }

    public function testThirtyTwoDataWidgetsAndThirtyTwoDividersAreAllowed(): void
    {
        $layout = self::layout();
        $data = array_map(static fn (int $i): array => array_replace($layout['widgets'][0], ['id' => 'kpi-' . $i]), range(1, 32));
        $dividers = array_map(static fn (int $i): array => ['id' => 'divider-' . $i, 'type' => 'layout.divider', 'span' => 12, 'options' => []], range(1, 32));
        $layout['widgets'] = array_merge($data, $dividers);
        self::assertCount(64, (new DashboardWidgetRegistry())->validate($layout)['widgets']);
    }

    public function testMissingHeightIsBackwardsCompatibleAndSupportedHeightsAreRetained(): void
    {
        $registry = new DashboardWidgetRegistry();
        self::assertSame('auto', $registry->validate(self::layout())['widgets'][0]['height']);
        $layout = self::layout();
        $layout['widgets'][0] = ['id' => 'monthly', 'type' => 'business.monthly', 'span' => 9, 'height' => 'tall', 'options' => []];
        self::assertSame('tall', $registry->validate($layout)['widgets'][0]['height']);
    }

    public function testTopClientsTablePositionDefaultsToLeftAndAcceptsSupportedPositions(): void
    {
        $registry = new DashboardWidgetRegistry();
        $layout = self::layout();
        $layout['widgets'][0] = ['id' => 'top', 'type' => 'clients.top', 'span' => 12, 'options' => []];
        self::assertSame('left', $registry->validate($layout)['widgets'][0]['options']->tablePosition);
        foreach (['left', 'right', 'above', 'below'] as $position) {
            $layout['widgets'][0]['options'] = ['tablePosition' => $position];
            self::assertSame($position, $registry->validate($layout)['widgets'][0]['options']->tablePosition);
        }
    }

    public function testTopClientsRejectsUnknownTablePosition(): void
    {
        $layout = self::layout();
        $layout['widgets'][0] = ['id' => 'top', 'type' => 'clients.top', 'span' => 12, 'options' => ['tablePosition' => 'diagonal']];
        $this->expectException(\InvalidArgumentException::class);
        (new DashboardWidgetRegistry())->validate($layout);
    }

    public function testActionWidgetsSupportQuarterAndThirdWidth(): void
    {
        $registry = new DashboardWidgetRegistry();
        foreach (['actions.quick', 'actions.todo'] as $type) {
            self::assertSame(4, $registry->get($type)['defaultSpan']);
            foreach ([3, 4] as $span) {
                $layout = self::layout();
                $layout['widgets'] = [['id' => 'actions', 'type' => $type, 'span' => $span, 'options' => []]];
                self::assertSame($span, $registry->validate($layout)['widgets'][0]['span']);
            }
        }
    }

    public function testDefaultActionRowUsesThreeThirdWidthWidgets(): void
    {
        $registry = new DashboardWidgetRegistry();
        foreach (['home', 'portal'] as $board) {
            $widgets = $registry->defaultLayout($board)['widgets'];
            self::assertSame(['actions.quick', 'actions.todo', 'documents.requests', 'layout.divider'], array_column(array_slice($widgets, 0, 4), 'type'));
            self::assertSame([4, 4, 4], array_column(array_slice($widgets, 0, 3), 'span'));
        }
    }

    public function testEveryWidgetAcceptsEveryColumnBetweenItsBounds(): void
    {
        $registry = new DashboardWidgetRegistry();
        foreach ($registry->all() as $widget) {
            self::assertArrayHasKey('minSpan', $widget);
            self::assertArrayHasKey('maxSpan', $widget);
            self::assertSame(range($widget['minSpan'], $widget['maxSpan']), $widget['spans']);
            foreach ($widget['spans'] as $span) {
                $layout = self::layout();
                $layout['widgets'] = [['id' => 'size', 'type' => $widget['type'], 'span' => $span, 'options' => []]];
                self::assertSame($span, $registry->validate($layout)['widgets'][0]['span']);
            }
            foreach ([$widget['minSpan'] - 1, $widget['maxSpan'] + 1] as $span) {
                $layout = self::layout();
                $layout['widgets'] = [['id' => 'size', 'type' => $widget['type'], 'span' => $span, 'options' => []]];
                try {
                    $registry->validate($layout);
                    self::fail('Rozměr mimo hranice widgetu musí být odmítnut.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    private static function layout(): array
    {
        return ['schemaVersion' => 1, 'presetVersion' => 1, 'widgets' => [
            ['id' => 'kpi-ytd', 'type' => 'business.kpi', 'span' => 3, 'options' => ['period' => 'ytd', 'currency' => 'all']],
        ]];
    }
}
