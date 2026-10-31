<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Dashboard;

use MyInvoice\Service\Dashboard\DashboardLayoutRegistry;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DashboardLayoutRegistryTest extends TestCase
{
    private function registry(): DashboardLayoutRegistry { return new DashboardLayoutRegistry(new DashboardWidgetRegistry()); }

    public function testDefaultSectionsPreserveBothExistingScreensAndObjectKeyOrderDoesNotMatter(): void
    {
        $registry = $this->registry();
        self::assertSame(['actions','drafts','tax-net','tax-advances','sales','purchases','statistics','due','forecast','invoice-lists','top-clients'], array_column($registry->defaults('home')['items'], 'type'));
        self::assertSame(['business','cashflow','vat','quick-actions'], array_column($registry->defaults('portal')['items'], 'type'));
        foreach (['home', 'portal'] as $board) {
            $layout = $registry->defaults($board);
            self::assertEquals($layout, $registry->validate($board, array_reverse($layout, true)));
        }
        self::assertSame(['schemaVersion' => 2, 'items' => []], $registry->validate('portal', ['schemaVersion' => 2, 'items' => []]));
    }

    public function testUnavailableButKnownWidgetsRemainValidAndUseDataRegistryOptions(): void
    {
        $item = self::widget('accounting.status');
        self::assertSame('accounting.status', $this->registry()->validate('portal', ['schemaVersion' => 2, 'items' => [$item]])['items'][0]['type']);
        $item = self::widget('business.kpi');
        self::assertSame('all', $this->registry()->validate('portal', ['schemaVersion' => 2, 'items' => [$item]])['items'][0]['options']->currency);
        $item = self::widget('cashflow.weekly');
        self::assertSame('CZK', $this->registry()->validate('portal', ['schemaVersion' => 2, 'items' => [$item]])['items'][0]['options']->currency);
    }

    #[DataProvider('invalidLayouts')]
    public function testInvalidOrCrossBoardLayoutsAreRejected(mixed $layout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry()->validate('portal', $layout);
    }

    public static function invalidLayouts(): iterable
    {
        yield 'scalar' => [null];
        yield 'unknown field' => [['schemaVersion' => 2, 'items' => [], 'user_id' => 1]];
        yield 'old schema' => [['schemaVersion' => 1, 'items' => []]];
        foreach ([['type', 'unknown'], ['span', 0], ['span', 13], ['span', '6'], ['height', 'huge'], ['kind', 'unknown'], ['id', '../bad'], ['options', ['currency' => null]], ['options', ['unexpected' => true]]] as $i => [$key, $value]) {
            yield 'invalid field ' . $i => [['schemaVersion' => 2, 'items' => [array_replace(self::widget('business.kpi'), [$key => $value])]]];
        }
        $section = ['id' => 'section-sales', 'kind' => 'section', 'type' => 'sales', 'span' => 12, 'height' => 'auto', 'options' => []];
        yield 'home section on portal' => [['schemaVersion' => 2, 'items' => [$section]]];
        yield 'duplicate instance' => [['schemaVersion' => 2, 'items' => [self::widget('business.kpi'), self::widget('business.kpi')]]];
        yield 'widget limit' => [['schemaVersion' => 2, 'items' => array_map(static fn (int $i): array => array_replace(self::widget('business.kpi'), ['id' => 'w-' . $i]), range(1, 33))]];
        yield 'duplicate todo feed' => [['schemaVersion' => 2, 'items' => [self::widget('actions.todo'), array_replace(self::widget('actions.todo'), ['id' => 'second'])]]];
        yield 'invented option cannot be persisted' => [['schemaVersion' => 2, 'items' => [array_replace(self::widget('cashflow.weekly'), ['options' => ['currency' => 'auto']])]]];
    }

    private static function widget(string $type): array
    {
        return ['id' => 'test', 'kind' => 'widget', 'type' => $type, 'span' => 12, 'height' => 'auto', 'options' => []];
    }
}
