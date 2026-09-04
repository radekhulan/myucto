<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountingSetupKeywordCatalogTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'čeština' => ['cs'];
        yield 'slovenština' => ['sk'];
        yield 'němčina' => ['de'];
        yield 'angličtina' => ['en'];
    }

    #[DataProvider('locales')]
    public function testEveryLocaleHasBroadPositiveCoverageAndSafetyVetoes(string $locale): void
    {
        $rows = array_values(array_filter(
            $this->catalogRows(),
            static fn (array $row): bool => $row['locale'] === $locale,
        ));
        $positives = array_filter($rows, static fn (array $row): bool => $row['polarity'] === 'positive');
        $vetos = array_filter($rows, static fn (array $row): bool => $row['polarity'] === 'veto');

        self::assertGreaterThanOrEqual(50, count($positives), "Katalog {$locale} má pokrýt běžné náklady obecně.");
        self::assertGreaterThanOrEqual(12, count($vetos), "Katalog {$locale} potřebuje souměrné bezpečnostní výjimky.");

        $concepts = array_unique(array_column($positives, 'concept'));
        foreach (['fuel', 'material', 'energy', 'small_asset', 'insurance', 'repair', 'vehicle_repair', 'service'] as $concept) {
            self::assertContains($concept, $concepts, "Katalog {$locale} neobsahuje koncept {$concept}.");
        }
    }

    public function testCatalogAvoidsKnownAmbiguousPrefixPhrases(): void
    {
        $forbidden = [
            'cs|fuel|super',
            'cs|fuel|premium',
            'cs|fuel|natural',
            'cs|fuel|kwh',
            'cs|fuel|charging',
            'cs|fuel|recharge',
            'cs|fuel|wallbox',
            'cs|small_asset|nas',
            'de|fuel|ladung',
        ];
        $keys = array_map(
            static fn (array $row): string => $row['locale'] . '|' . $row['concept'] . '|' . $row['phrase'],
            $this->catalogRows(),
        );

        foreach ($forbidden as $key) {
            self::assertNotContains($key, $keys, "Příliš obecná prefixová fráze {$key} vytváří falešné shody.");
        }
    }

    public function testCatalogRowsAreUnique(): void
    {
        $keys = array_map(
            static fn (array $row): string => implode('|', [
                $row['locale'],
                $row['concept'],
                $row['phrase'],
                $row['polarity'],
            ]),
            $this->catalogRows(),
        );

        self::assertSame(count($keys), count(array_unique($keys)), 'Katalog nesmí obsahovat duplicitní řádky.');
    }

    /** @return list<array{locale:string,concept:string,phrase:string,polarity:string}> */
    private function catalogRows(): array
    {
        $path = dirname(__DIR__, 3) . '/db/migrations/1740_accounting_setup_assistant.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        preg_match_all(
            "/\\(1,'(?<locale>cs|sk|de|en)','(?<concept>[a-z_]+)','(?<phrase>[^']+)','(?<polarity>positive|veto)'/",
            $sql,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            static fn (array $match): array => [
                'locale' => $match['locale'],
                'concept' => $match['concept'],
                'phrase' => $match['phrase'],
                'polarity' => $match['polarity'],
            ],
            $matches,
        );
    }
}
