<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\People;

use MyInvoice\Service\Payroll\People\PayrollPersonDataGapCatalog as Catalog;
use PHPUnit\Framework\TestCase;

/**
 * Katalog je smlouva mezi třemi obrazovkami (seznam osob, karta osoby, kontrola
 * před během) a klientem. Tenhle test hlídá právě ty vlastnosti, na kterých ta
 * smlouva stojí — ne to, jak je katalog napsaný uvnitř.
 */
final class PayrollPersonDataGapCatalogTest extends TestCase
{
    public function testEveryDefinitionHasAKnownSeverityAndAReachableTarget(): void
    {
        foreach (Catalog::definitions() as $key => $definition) {
            self::assertContains(
                $definition['severity'],
                Catalog::SEVERITIES,
                "Mezera {$key} má neznámou naléhavost.",
            );
            // Pole bez panelu by uživatele poslalo doskočit nikam.
            if ($definition['field'] !== null) {
                self::assertNotNull(
                    $definition['panel'],
                    "Mezera {$key} má pole, ale nemá panel, ve kterém leží.",
                );
            }
        }
    }

    /** Klíče v `KEYS` se nesmí rozejít s katalogem — hlídá to `keys()` sama. */
    public function testKeysMirrorTheCatalogOrder(): void
    {
        self::assertSame(array_keys(Catalog::definitions()), Catalog::keys());
    }

    /**
     * Starší `setup_gaps` musí zůstat PODMNOŽINOU katalogu. Kdyby vypadl klíč,
     * seznam osob by přestal značit něco, co uložení profilu dál vynucuje.
     */
    public function testLegacySetupKeysStayInsideTheCatalog(): void
    {
        self::assertSame(
            ['employment', 'name', 'identifier', 'residence', 'contact'],
            Catalog::legacySetupKeys(),
        );
    }

    /** Pořadí je pracovní postup: co brání podání, se doplňuje dřív než rady. */
    public function testBlockingGapsComeBeforeAdvisoryOnes(): void
    {
        $seenAdvisory = false;
        foreach (Catalog::definitions() as $key => $definition) {
            if ($definition['severity'] === Catalog::SEVERITY_ADVISORY) {
                $seenAdvisory = true;
                continue;
            }
            self::assertFalse(
                $seenAdvisory,
                "Blokující mezera {$key} stojí až za radou.",
            );
        }
    }

    public function testSelectColumnsAndFilterCoverExactlyTheCatalog(): void
    {
        $columns = Catalog::selectColumns();
        foreach (Catalog::keys() as $key) {
            self::assertStringContainsString(
                Catalog::COLUMN_PREFIX . $key,
                $columns,
            );
        }

        $blocking = Catalog::gapExpression(Catalog::SEVERITY_BLOCKING);
        $any = Catalog::gapExpression();
        self::assertNotSame($blocking, $any);
        self::assertStringContainsString('payroll_person_health_coverage_history', $blocking);
        self::assertStringContainsString('payroll_person_contacts', $any);
        self::assertStringNotContainsString('payroll_person_contacts', $blocking);
    }

    public function testCountsSplitBySeverity(): void
    {
        $gaps = [
            Catalog::describe('name'),
            Catalog::describe('identifier'),
            Catalog::describe('contact'),
        ];
        self::assertSame(['blocking' => 2, 'advisory' => 1], Catalog::counts($gaps));
    }

    public function testFromRowKeepsCatalogOrderRegardlessOfInput(): void
    {
        $set = ['contact', 'name'];
        $gaps = Catalog::fromRow(
            static fn (string $column): bool => in_array(
                substr($column, strlen(Catalog::COLUMN_PREFIX)),
                $set,
                true,
            ),
        );
        self::assertSame(['name', 'contact'], array_column($gaps, 'key'));
    }

    public function testDescribeRefusesAnUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Catalog::describe('neexistuje');
    }
}
