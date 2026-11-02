<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Dimension;

use MyInvoice\Service\Accounting\Dimension\DimensionValueLabel;
use MyInvoice\Service\Accounting\Reports\DimensionAnalyticsTables;
use PHPUnit\Framework\TestCase;

/**
 * Popisek hodnoty dimenze ve výstupech serveru: systémová hodnota nese v kódu
 * interní identifikátor, proto se ukazuje jen názvem.
 */
final class DimensionValueLabelTest extends TestCase
{
    public function testSystemValueShowsOnlyItsName(): void
    {
        $economic = ['code' => 'economic', 'name' => 'Hospodářská činnost', 'semantic_key' => 'economic'];
        self::assertSame('Hospodářská činnost', DimensionValueLabel::short($economic));
        self::assertSame('Hospodářská činnost', DimensionValueLabel::full($economic));
        self::assertSame('Jednotka A1', DimensionValueLabel::full(['code' => 'unit:12', 'name' => 'Jednotka A1', 'semantic_key' => 'unit:12']));
    }

    public function testCustomValueKeepsItsCode(): void
    {
        $brno = ['code' => 'BRNO', 'name' => 'Brno', 'semantic_key' => null];
        self::assertSame('BRNO', DimensionValueLabel::short($brno));
        self::assertSame('BRNO Brno', DimensionValueLabel::full($brno));
        self::assertSame('Brno', DimensionValueLabel::short(['code' => '', 'name' => 'Brno']));
        self::assertSame('Brno', DimensionValueLabel::full(['code' => ' ', 'name' => 'Brno', 'semantic_key' => '']));
    }

    /**
     * Jednotky dvou domů se mohou jmenovat stejně („Jednotka 1"). Bez kódu by ve výběru
     * i ve sloupcích exportu splynuly, proto při shodě dostanou za název kód.
     */
    public function testSystemValuesWithSameNameAreDisambiguatedByCode(): void
    {
        $values = [
            7 => ['code' => 'unit:12', 'name' => 'Jednotka 1', 'semantic_key' => 'unit:12'],
            9 => ['code' => 'unit:15', 'name' => 'Jednotka 1', 'semantic_key' => 'unit:15'],
            11 => ['code' => 'unit:16', 'name' => 'Jednotka 2', 'semantic_key' => 'unit:16'],
            13 => ['code' => 'J1', 'name' => 'Jednotka 1', 'semantic_key' => null],
        ];
        self::assertSame([
            7 => 'Jednotka 1 (unit:12)',
            9 => 'Jednotka 1 (unit:15)',
            11 => 'Jednotka 2',
            13 => 'J1 Jednotka 1',
        ], DimensionValueLabel::fullAll($values));
        self::assertSame([
            7 => 'Jednotka 1 (unit:12)',
            9 => 'Jednotka 1 (unit:15)',
            11 => 'Jednotka 2',
            13 => 'J1',
        ], DimensionValueLabel::shortAll($values));
        self::assertSame(
            ['a' => 'Hospodářská činnost', 'b' => 'Projekt'],
            DimensionValueLabel::fullAll([
                'a' => ['code' => 'economic', 'name' => 'Hospodářská činnost', 'semantic_key' => 'economic'],
                'b' => ['code' => '', 'name' => 'Projekt'],
            ]),
        );
    }

    public function testAnalyticsExportDisambiguatesUnitsWithSameName(): void
    {
        $amounts = ['revenue' => 0.0, 'cost' => 0.0, 'result' => 0.0, 'tax_deductible_cost' => 0.0, 'non_deductible_cost' => 0.0, 'income_tax_cost' => 0.0];
        $data = [
            'year' => 2026,
            'type' => ['name' => 'Jednotka'],
            'supplier_ids' => [1],
            'rows' => [
                ['value_id' => 1, 'depth' => 0, 'code' => 'unit:12', 'name' => 'Jednotka 1', 'semantic_key' => 'unit:12'],
                ['value_id' => 2, 'depth' => 0, 'code' => 'unit:15', 'name' => 'Jednotka 1', 'semantic_key' => 'unit:15'],
            ],
            'unassigned' => $amounts,
            'totals' => $amounts,
            'value_totals' => ['1' => $amounts, '2' => $amounts],
            'monthly' => [],
            'previous_monthly' => [],
            'value_monthly' => [],
        ];
        self::assertSame('Jednotka · 2026 · Jednotka 1 (unit:15)', DimensionAnalyticsTables::build($data, 'comparison', '2')['subtitle']);
    }

    public function testAnalyticsExportNamesSystemValueWithoutCode(): void
    {
        $amounts = ['revenue' => 0.0, 'cost' => 0.0, 'result' => 0.0, 'tax_deductible_cost' => 0.0, 'non_deductible_cost' => 0.0, 'income_tax_cost' => 0.0];
        $data = [
            'year' => 2026,
            'type' => ['name' => 'Činnost'],
            'supplier_ids' => [1],
            'rows' => [
                ['value_id' => 1, 'depth' => 0, 'code' => 'economic', 'name' => 'Hospodářská činnost', 'semantic_key' => 'economic'],
                ['value_id' => 2, 'depth' => 0, 'code' => 'P', 'name' => 'Projekt', 'semantic_key' => null],
            ],
            'unassigned' => $amounts,
            'totals' => $amounts,
            'value_totals' => ['1' => $amounts, '2' => $amounts],
            'monthly' => [],
            'previous_monthly' => [],
            'value_monthly' => [],
        ];
        self::assertSame('Činnost · 2026 · Hospodářská činnost', DimensionAnalyticsTables::build($data, 'comparison', '1')['subtitle']);
        self::assertSame('Činnost · 2026 · P Projekt', DimensionAnalyticsTables::build($data, 'comparison', '2')['subtitle']);
    }
}
