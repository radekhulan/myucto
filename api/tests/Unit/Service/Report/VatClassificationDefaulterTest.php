<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Audit 2026-07 follow-up (doplnění commitu 88794465): reálný signál zboží/služba
 * pro RC prodej do EU (ř.20 „dodání zboží do JČS" vs ř.21 „poskytnutí služby § 9/1").
 *
 * Commit 88794465 jen otočil konstantu '20' → '22', čímž pouze prohodil, který
 * případ je špatně klasifikovaný. Tady testujeme dvě čisté (bez DB) rozhodovací
 * funkce, které nahrazují slepou konstantu skutečným signálem:
 *   - classifyUnitsGoodsVsServices() — per-item měrná jednotka,
 *   - naceIsGoods()                  — CZ-NACE dodavatele (company-level default).
 */
#[CoversClass(VatClassificationDefaulter::class)]
final class VatClassificationDefaulterTest extends TestCase
{
    /**
     * Fyzikální míra (kg, l, m, m²…) i balicí jednotky = ZBOŽÍ.
     */
    #[DataProvider('goodsUnitProvider')]
    public function testGoodsLikeUnitClassifiesAsGoods(array $units): void
    {
        $this->assertSame(
            'goods',
            VatClassificationDefaulter::classifyUnitsGoodsVsServices($units),
            'Fyzikální / balicí měrná jednotka → zboží (ř.20)'
        );
    }

    public static function goodsUnitProvider(): array
    {
        return [
            'kg'      => [['kg']],
            'litr'    => [['l']],
            'metr'    => [['m']],
            'm2'      => [['m2']],
            'tuna'    => [['t']],
            'paleta'  => [['paleta']],
            'karton'  => [['karton']],
            'velké písmo' => [['KG']],   // case-insensitive
        ];
    }

    /**
     * Časové / výkonové jednotky (h, den, měsíc…) = SLUŽBA.
     */
    #[DataProvider('serviceUnitProvider')]
    public function testServiceLikeUnitClassifiesAsServices(array $units): void
    {
        $this->assertSame(
            'services',
            VatClassificationDefaulter::classifyUnitsGoodsVsServices($units),
            'Časová / výkonová měrná jednotka → služba (ř.21)'
        );
    }

    public static function serviceUnitProvider(): array
    {
        return [
            'hodina'      => [['h']],
            'hod'         => [['hod']],
            'den'         => [['den']],
            'měsíc'       => [['měsíc']],
            'rok'         => [['rok']],
            'velké písmo' => [['Hodina']],
        ];
    }

    /**
     * 'ks'/'kus' (defaultní hodnota sloupce) a neznámé jednotky NENESOU signál —
     * vrací null, aby rozhodla vyšší vrstva (NACE / statistický default). To je
     * jádro opravy: nesmíme z „ks" (= „nespecifikováno") dělat automaticky zboží.
     */
    #[DataProvider('neutralUnitProvider')]
    public function testAmbiguousUnitYieldsNoSignal(array $units): void
    {
        $this->assertNull(
            VatClassificationDefaulter::classifyUnitsGoodsVsServices($units),
            'ks / neznámé / prázdné → žádný signál (null)'
        );
    }

    public static function neutralUnitProvider(): array
    {
        return [
            'ks'       => [['ks']],
            'kus'      => [['kus']],
            'neznámá'  => [['xyz']],
            'prázdná'  => [['']],
            'žádné položky' => [[]],
        ];
    }

    /**
     * Smíšená faktura (zboží i služba) — rozhodne převaha, remíza → opatrněji služba.
     */
    public function testMixedUnitsDecidedByMajority(): void
    {
        $this->assertSame('goods', VatClassificationDefaulter::classifyUnitsGoodsVsServices(['kg', 'kg', 'h']));
        $this->assertSame('services', VatClassificationDefaulter::classifyUnitsGoodsVsServices(['h', 'h', 'kg']));
        // remíza → služba (konzervativnější ř.21)
        $this->assertSame('services', VatClassificationDefaulter::classifyUnitsGoodsVsServices(['kg', 'h']));
        // 'ks' se nezapočítává, rozhodne jediný fyzikální signál
        $this->assertSame('goods', VatClassificationDefaulter::classifyUnitsGoodsVsServices(['ks', 'ks', 'kg']));
    }

    /**
     * CZ-NACE heuristika: oddíly 01–33 (zemědělství/těžba/výroba) a 45–47 (obchod)
     * = zboží; služby, stavebnictví, doprava, IT, poradenství = ne-zboží.
     */
    #[DataProvider('naceProvider')]
    public function testNaceGoodsHeuristic(string $nace, bool $expected): void
    {
        $this->assertSame($expected, VatClassificationDefaulter::naceIsGoods($nace));
    }

    public static function naceProvider(): array
    {
        return [
            'maloobchod 47'         => ['47110', true],
            'velkoobchod 46'        => ['46', true],
            'obchod s vozidly 45'   => ['451', true],
            'výroba potravin 10'    => ['108', true],
            'výroba strojů 28'      => ['2896', true],
            'těžba 08'              => ['0812', true],
            'zemědělství 01'        => ['011', true],
            'IT programování 62'    => ['62010', false],
            'účetnictví 69'         => ['6920', false],
            'stavebnictví 43'       => ['4321', false],
            'doprava 49'            => ['4941', false],
            'ubytování 55'          => ['5510', false],
            'prázdné'               => ['', false],
            'jen jedna číslice'     => ['4', false],
        ];
    }

    /**
     * Fallback bez DB kódu se klíčuje AKTUÁLNÍ sazbou roku z konstant, ne zamrzlým
     * literálem — po legislativní změně sazby se fallback chytí nové hodnoty.
     */
    public function testFallbackHeaderRateComesFromTaxConstantsForYear(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE tax_constants (year INTEGER PRIMARY KEY, data TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE vat_classifications (code TEXT, direction TEXT, vat_rate REAL, is_reverse_charge INTEGER, archived INTEGER, supplier_id INTEGER, display_order INTEGER)');
        $pdo->exec('CREATE TABLE vat_rates (rate_percent REAL, valid_from TEXT, valid_to TEXT)');

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $conn = new Connection($config);
        $prop = (new \ReflectionClass($conn))->getProperty('pdo');
        $prop->setValue($conn, $pdo);

        // Override 2025: sazby posunuté na 22 %/13 %, nikdo je nemá v číselníku.
        $override = \MyInvoice\Service\Tax\TaxConstants::forYear(2025);
        $override['vat_rate_standard'] = 22.0;
        $override['vat_rate_reduced'] = 13.0;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?)')
            ->execute([2025, json_encode($override)]);

        $defaulter = new VatClassificationDefaulter($conn, new TaxConstantsRepository($conn));

        // Header bez položek → dominantní sazba = standardní sazba roku podání.
        self::assertSame('1', $defaulter->suggestHeaderForInvoice([], false, 'sale', '2025-06-15'));
    }
}
