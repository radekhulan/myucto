<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Stock;

use MyInvoice\Service\Stock\StockHistoryImportProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockHistoryImportProfileTest extends TestCase
{
    private const HEADER = ['Doklad', 'Typ', 'Datum', 'Sklad', 'Cíl', 'Kód', 'Množství', 'Cena'];

    private static function profile(array $over = []): array
    {
        return StockHistoryImportProfile::normalize($over + [
            'source_key' => 'stary-sklad',
            'cutover_date' => '2026-01-01',
            'mapping' => [
                'doc_number' => 'Doklad', 'doc_type' => 'Typ', 'doc_date' => 'Datum', 'warehouse' => 'Sklad',
                'warehouse_to' => 'Cíl', 'sku' => 'Kód', 'quantity' => 'Množství', 'unit_cost' => 'Cena', 'partner' => '',
            ],
        ]);
    }

    public function testMapsCzechTypesDatesPrefixesAndIgnoresIssueCost(): void
    {
        $profile = self::profile(['number_prefixes' => ['issue' => 'MP-', 'receipt' => '']]);
        $indexes = StockHistoryImportProfile::headerIndexes($profile, self::HEADER);
        self::assertArrayNotHasKey('partner', $profile['mapping']);

        $issue = StockHistoryImportProfile::map($profile, $indexes, ['0042', 'Výdejka', '5. 3. 2025', 'HL', '', '000007', '1,5', '99']);
        self::assertSame(['MP-0042', 'issue', '2025-03-05', '1.500', null], [$issue['doc_number'], $issue['doc_type'], $issue['doc_date'], $issue['quantity'], $issue['unit_cost']]);
        self::assertSame('mp-0042', StockHistoryImportProfile::documentKey($profile, $indexes, ['0042', 'výdejka']));

        $surplus = StockHistoryImportProfile::map($profile, $indexes, ['I1', 'přebytek', '45658', 'HL', '', 'A', '1', '12,345678']);
        self::assertSame(['surplus', '2025-01-01', '12.345678'], [$surplus['doc_type'], $surplus['doc_date'], $surplus['unit_cost']]);
    }

    #[DataProvider('invalidRows')]
    public function testRejectsInvalidRows(array $cells, string $error): void
    {
        $profile = self::profile();
        $this->expectExceptionMessage($error);
        StockHistoryImportProfile::map($profile, StockHistoryImportProfile::headerIndexes($profile, self::HEADER), $cells);
    }

    public static function invalidRows(): iterable
    {
        yield 'bez čísla' => [['', 'prijemka', '2025-01-01', 'HL', '', 'A', '1', '1'], 'history_import_doc_number_required'];
        yield 'neznámý typ' => [['1', 'dodak', '2025-01-01', 'HL', '', 'A', '1', '1'], 'history_import_doc_type_invalid'];
        yield 'neexistující den' => [['1', 'prijemka', '2025-02-31', 'HL', '', 'A', '1', '1'], 'history_import_doc_date_invalid'];
        yield 'po dni přechodu' => [['1', 'prijemka', '2026-01-01', 'HL', '', 'A', '1', '1'], 'history_import_doc_date_after_cutover'];
        yield 'převodka bez cíle' => [['1', 'prevodka', '2025-01-01', 'HL', '', 'A', '1', ''], 'history_import_warehouse_to_invalid'];
        yield 'převodka na týž sklad' => [['1', 'prevodka', '2025-01-01', 'HL', 'hl', 'A', '1', ''], 'history_import_warehouse_to_invalid'];
        yield 'cíl u výdejky' => [['1', 'vydejka', '2025-01-01', 'HL', 'POB', 'A', '1', ''], 'history_import_warehouse_to_invalid'];
        yield 'příjem bez ceny' => [['1', 'prijemka', '2025-01-01', 'HL', '', 'A', '1', ''], 'history_import_unit_cost_required'];
        yield 'nulové množství' => [['1', 'vydejka', '2025-01-01', 'HL', '', 'A', '0', ''], 'history_import_quantity_invalid'];
        yield 'záporné množství' => [['1', 'vydejka', '2025-01-01', 'HL', '', 'A', '-1', ''], 'history_import_quantity_invalid'];
        yield 'dlouhé číslo' => [[str_repeat('9', 31), 'vydejka', '2025-01-01', 'HL', '', 'A', '1', ''], 'history_import_doc_number_too_long'];
    }

    public function testRejectsFutureDocumentAndCutoverDates(): void
    {
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $profile = self::profile(['cutover_date' => null]);
        $indexes = StockHistoryImportProfile::headerIndexes($profile, self::HEADER);
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        self::assertSame($yesterday, StockHistoryImportProfile::map($profile, $indexes, ['1', 'prijemka', $yesterday, 'HL', '', 'A', '1', '1'])['doc_date']);
        // Bez data přechodu by dnešní doklad historie zamkl i dnešní živé doklady.
        foreach ([date('Y-m-d'), $tomorrow] as $date) {
            try {
                StockHistoryImportProfile::map($profile, $indexes, ['1', 'prijemka', $date, 'HL', '', 'A', '1', '1']);
                self::fail('Doklad historie s dnešním nebo budoucím datem musí selhat.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('history_import_doc_date_not_past', $e->getMessage());
            }
        }
        self::assertSame(date('Y-m-d'), self::profile(['cutover_date' => date('Y-m-d')])['cutover_date']);
        $this->expectExceptionMessage('history_import_cutover_future');
        self::profile(['cutover_date' => $tomorrow]);
    }

    public function testRejectsIncompleteMappingAndUnknownFields(): void
    {
        $this->expectExceptionMessage('history_import_mapping_invalid');
        self::profile(['mapping' => ['doc_number' => 'Doklad']]);
    }
}
