<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Tax\TaxConstants;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * TaxConstantsRepository — merge DB override přes defaulty + DPH helpery
 * (khItemThreshold / vatRateStandard / vatBucketThreshold).
 *
 * Klíčová regrese: override uložený STARŠÍ verzí aplikace nezná později přidané
 * konstanty (vat_rate_standard, kh_item_threshold, …) — forYear je musí doplnit
 * z defaultů, jinak by DPH výkazy po uložení libovolného override spadly na
 * chybějícím klíči.
 */
final class TaxConstantsRepositoryTest extends TestCase
{
    private PDO $pdo;
    private TaxConstantsRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE tax_constants (year INTEGER PRIMARY KEY, data TEXT NOT NULL)');

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $conn = new Connection($config);
        $prop = (new \ReflectionClass($conn))->getProperty('pdo');
        $prop->setValue($conn, $this->pdo);
        $this->repo = new TaxConstantsRepository($conn);
    }

    public function testDefaultsContainVatConstants(): void
    {
        $c = $this->repo->forYear(2026);
        $this->assertSame(21.0, (float) $c['vat_rate_standard']);
        $this->assertSame(12.0, (float) $c['vat_rate_reduced']);
        $this->assertSame(10000.0, (float) $c['kh_item_threshold']);
    }

    public function testLegacyOverrideWithoutNewKeysIsMergedWithDefaults(): void
    {
        // Override z doby, kdy číselník DPH konstanty neznal — jen DPFO hodnoty.
        $legacy = TaxConstants::forYear(2026);
        unset($legacy['vat_rate_standard'], $legacy['vat_rate_reduced'], $legacy['kh_item_threshold']);
        $legacy['credit_taxpayer'] = 99999; // marker, že override per klíč vyhrává
        $this->pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?)')
            ->execute([2026, json_encode($legacy)]);

        $c = $this->repo->forYear(2026);
        $this->assertSame(99999, (int) $c['credit_taxpayer'], 'override per klíč vyhrává');
        $this->assertSame(21.0, (float) $c['vat_rate_standard'], 'chybějící klíč doplněn z defaultu');
        $this->assertSame(10000.0, (float) $c['kh_item_threshold'], 'chybějící klíč doplněn z defaultu');

        // listEffective musí mergovat stejně (editor by jinak ukázal prázdná pole).
        foreach ($this->repo->listEffective() as $y) {
            if ($y['year'] === 2026) {
                $this->assertTrue($y['is_override']);
                $this->assertSame(21.0, (float) $y['data']['vat_rate_standard']);
            }
        }
    }

    public function testFutureYearTemporarilyUsesLatestKnownConstants(): void
    {
        self::assertSame(2027, $this->repo->forYear(2028)['year']);
    }

    public function testPartialNestedOverrideKeepsSiblingValuesAndListsReplaceWholly(): void
    {
        $override = [
            'entity_category_thresholds' => ['micro' => ['assets_net' => 12345678]],
            'advance_semiannual_months' => [7, 12],
        ];
        $this->pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?)')
            ->execute([2026, json_encode($override)]);

        $c = $this->repo->forYear(2026);
        self::assertSame(12345678, $c['entity_category_thresholds']['micro']['assets_net']);
        self::assertSame(22000000, $c['entity_category_thresholds']['micro']['net_turnover']);
        self::assertArrayHasKey('small', $c['entity_category_thresholds']);
        self::assertSame([7, 12], $c['advance_semiannual_months']);
    }

    public function testKnownHistoricalYearUsesExactDefaults(): void
    {
        self::assertSame(2024, $this->repo->forYear(2024)['year']);
    }

    public function testUnknownYearOwnOverrideIsAccepted(): void
    {
        $data28 = TaxConstants::forYear(2027);
        $data28['year'] = 2028;
        $data28['kh_item_threshold'] = 20000;
        $this->pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?)')
            ->execute([2028, json_encode($data28)]);
        $this->assertSame(20000.0, $this->repo->khItemThreshold(2028));
        self::assertSame(2028, $this->repo->forExactYear(2028)['year']);
        self::assertSame(20000.0, $this->repo->khItemThreshold(2029), 'další rok přebírá nejbližší DB sadu');
    }

    public function testExactYearRejectsFallback(): void
    {
        $this->expectException(\OutOfRangeException::class);
        $this->repo->forExactYear(2028);
    }

    public function testHelpersRespectOverride(): void
    {
        $data = TaxConstants::forYear(2026);
        $data['kh_item_threshold'] = 15000;
        $data['vat_rate_standard'] = 22.0;
        $data['vat_rate_reduced']  = 10.0;
        $this->pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?)')
            ->execute([2026, json_encode($data)]);

        $this->assertSame(15000.0, $this->repo->khItemThreshold(2026));
        $this->assertSame(22.0, $this->repo->vatRateStandard(2026));
        $this->assertSame(16.0, $this->repo->vatBucketThreshold(2026), 'midpoint (22+10)/2');
        // Rok bez override → defaulty (midpoint 21/12 = 16,5)
        $this->assertSame(16.5, $this->repo->vatBucketThreshold(2025));
        $this->assertSame(10000.0, $this->repo->khItemThreshold(2025));
    }
}
