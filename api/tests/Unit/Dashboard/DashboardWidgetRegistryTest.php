<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Dashboard;

use MyInvoice\Service\Dashboard\DashboardWidgetAccess;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DashboardWidgetRegistryTest extends TestCase
{
    public function testManifestContainsOnlyDataDefinitionsAndLocalizedMetadata(): void
    {
        $registry = new DashboardWidgetRegistry();
        self::assertCount(23, $registry->all());
        $locales = [];
        foreach (['cs', 'en'] as $locale) {
            $locales[$locale] = json_decode(file_get_contents(dirname(__DIR__, 4) . '/web/src/i18n/' . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR);
        }
        $types = [];
        foreach ($registry->all() as $widget) {
            self::assertNotContains($widget['type'], $types);
            $types[] = $widget['type'];
            self::assertContains($widget['accessRule'], DashboardWidgetAccess::RULES);
            self::assertContains($widget['scope'], DashboardWidgetAccess::SCOPES);
            self::assertNotEmpty($widget['dependencies']);
            foreach (['titleKey', 'descriptionKey'] as $key) {
                foreach ($locales as $locale) {
                    $value = $locale;
                    foreach (explode('.', $widget[$key]) as $part) $value = $value[$part];
                    self::assertIsString($value);
                    self::assertNotSame('', $value);
                }
            }
            foreach (['span', 'minSpan', 'heights', 'repeatable'] as $key) self::assertArrayNotHasKey($key, $widget);
        }
        self::assertNull($registry->get('layout.divider'));
    }

    public function testDefaultsAndRepeatedRequestsAreCanonicalWithoutLayoutState(): void
    {
        $registry = new DashboardWidgetRegistry();
        self::assertSame([], $registry->validateWidgets([]));
        $widgets = $registry->validateWidgets([
            ['id' => 'first', 'type' => 'business.monthly', 'options' => (object) []],
            ['id' => 'second', 'type' => 'business.monthly', 'options' => ['months' => 6]],
        ]);
        self::assertEquals((object) ['months' => 12, 'currency' => 'auto'], $widgets[0]['options']);
        self::assertEquals((object) ['months' => 6, 'currency' => 'auto'], $widgets[1]['options']);
        self::assertSame(['id', 'type', 'options'], array_keys($widgets[0]));
    }

    public function testWeeklyForecastRequiresAnExplicitCurrencyInsteadOfSilentlyChoosingCzk(): void
    {
        $registry = new DashboardWidgetRegistry();
        self::assertSame('CZK', $registry->get('cashflow.weekly')['options']['currency']['default']);
        $query = $registry->validateWidgets([['id' => 'forecast', 'type' => 'cashflow.weekly', 'options' => ['currency' => 'EUR']]]);
        self::assertSame('EUR', $query[0]['options']->currency);
        foreach (['auto', 'all'] as $currency) {
            try {
                $registry->validateWidgets([['id' => 'forecast', 'type' => 'cashflow.weekly', 'options' => ['currency' => $currency]]]);
                self::fail('Jednoměnový výhled nesmí nabídnout automatický výběr ani součet různých měn.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesAreRejectedBeforeProviders(mixed $widgets): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DashboardWidgetRegistry())->validateWidgets($widgets);
    }

    public static function invalidQueries(): iterable
    {
        yield 'scalar' => [false];
        yield 'not a list' => [['named' => self::widget()]];
        yield 'duplicate id' => [[self::widget(), self::widget()]];
        yield 'limit' => [array_map(static fn (int $i): array => array_replace(self::widget(), ['id' => 'id-' . $i]), range(1, 33))];
        foreach ([['id', ''], ['id', '../bad'], ['id', str_repeat('a', 65)], ['type', 'unknown'], ['span', 3],
            ['options', null], ['options', ['period' => null]], ['options', ['unknown' => 1]], ['options', ['period' => ['ytd']]],
            ['options', ['currency' => 'czk']], ['options', ['currency' => str_repeat('A', 17000)]]] as $index => [$key, $value]) {
            yield 'instance ' . $index => [[array_replace(self::widget(), [$key => $value])]];
        }
        yield 'numeric choice must stay numeric' => [[['id' => 'monthly', 'type' => 'business.monthly', 'options' => ['months' => '12']]]];
    }

    private static function widget(): array
    {
        return ['id' => 'kpi', 'type' => 'business.kpi', 'options' => []];
    }
}
