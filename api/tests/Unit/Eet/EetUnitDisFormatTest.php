<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eet;

use MyInvoice\Service\Eet\EetException;
use MyInvoice\Service\Eet\EetIdentifiers;
use MyInvoice\Service\Eet\EetUnitDisFormat;
use PHPUnit\Framework\TestCase;

/**
 * Řádek CSV pro hromadný import evidenčních jednotek do DIS+ (GFŘ, popis struktury
 * CSV souboru, verze 3. 11. 2026). Data jsou syntetická.
 */
final class EetUnitDisFormatTest extends TestCase
{
    /** @param array<string,mixed> $override @return array<string,mixed> */
    private static function unit(array $override = []): array
    {
        return array_replace([
            'name' => 'Jednotka', 'kind' => 'premises', 'nace_code' => '47190', 'activity_note' => null,
            'records_sales' => true, 'indirect_representation' => false, 'ruian_code' => null, 'street' => null,
            'house_number' => null, 'orientation_number' => null, 'municipality' => null, 'municipality_part' => null,
            'postal_code' => null, 'location_text' => null, 'vehicle_kind' => null, 'vehicle_kind_note' => null,
        ], $override);
    }

    public function testEveryApplicationKindExceptOtherHasADisCode(): void
    {
        self::assertSame(
            array_values(array_diff(EetIdentifiers::UNIT_KINDS, ['other'])),
            array_keys(EetUnitDisFormat::KIND_CODES),
        );
        self::assertSame(['S', 'M', 'A', 'I', 'D', 'P'], array_values(EetUnitDisFormat::KIND_CODES));
    }

    public function testRowHasSixteenColumnsAndRuianWinsOverTheAddress(): void
    {
        $row = EetUnitDisFormat::row(self::unit([
            'ruian_code' => 21717800, 'street' => 'Testovací', 'house_number' => '1', 'municipality' => 'Testov',
            'postal_code' => '11000', 'location_text' => '2. patro',
        ]));

        self::assertCount(16, $row);
        self::assertSame(
            ['Jednotka', 'S', '47190', '', '1', '0', '21717800', '', '', '', '', '', '', '2. patro', '', ''],
            $row,
        );
    }

    public function testVehicleCarriesItsKindAndNoteOnlyForOther(): void
    {
        $row = EetUnitDisFormat::row(self::unit([
            'kind' => 'vehicle', 'location_text' => '1AB 2345', 'vehicle_kind' => 'A', 'vehicle_kind_note' => 'kočár',
            'house_number' => '5',
        ]));

        self::assertSame(['D', '', '', '1AB 2345', 'A', ''], [$row[1], $row[8], $row[12], $row[13], $row[14], $row[15]]);
        self::assertSame('kočár', EetUnitDisFormat::row(self::unit([
            'kind' => 'vehicle', 'vehicle_kind' => 'J', 'vehicle_kind_note' => 'kočár',
        ]))[15]);
    }

    public function testProblemsFollowTheRulesForEachKind(): void
    {
        self::assertSame(['kind'], array_column(EetUnitDisFormat::problems(self::unit(['kind' => 'other', 'ruian_code' => 1])), 'field'));
        self::assertSame(
            ['house_number', 'municipality', 'postal_code'],
            array_column(EetUnitDisFormat::problems(self::unit()), 'field'),
        );
        self::assertSame([], EetUnitDisFormat::problems(self::unit(['ruian_code' => 1])));
        // Automat smí místo adresy nést jen popis umístění.
        self::assertSame(['location_text'], array_column(EetUnitDisFormat::problems(self::unit(['kind' => 'vending'])), 'field'));
        self::assertSame([], EetUnitDisFormat::problems(self::unit(['kind' => 'vending', 'location_text' => 'Nádraží'])));
        self::assertSame(['location_text'], array_column(EetUnitDisFormat::problems(self::unit(['kind' => 'online'])), 'field'));
        self::assertSame(
            ['vehicle_kind_note'],
            array_column(EetUnitDisFormat::problems(self::unit(['kind' => 'vehicle', 'location_text' => 'X', 'vehicle_kind' => 'J'])), 'field'),
        );
        self::assertSame(['nace_code', 'name'], array_column(
            EetUnitDisFormat::problems(self::unit(['name' => 'Bistro; bar', 'nace_code' => null, 'ruian_code' => 1])),
            'field',
        ));
    }

    public function testCsvHasNoHeaderAndEndsEveryLineWithCrlf(): void
    {
        $csv = EetUnitDisFormat::csv([self::unit(['ruian_code' => 1]), self::unit(['name' => 'Druhá', 'ruian_code' => 2])]);

        self::assertSame("Jednotka;S;47190;;1;0;1;;;;;;;;;\r\nDruhá;S;47190;;1;0;2;;;;;;;;;\r\n", $csv);
    }

    public function testNormalizeRejectsWhatTheImportRefuses(): void
    {
        foreach ([
            ['nace_code' => '47.19'],
            ['ruian_code' => '1234567890'],
            ['postal_code' => '1100'],
            ['street' => 'Ulice | 2'],
            ['location_text' => 'Kavárna ☕'],
            ['vehicle_kind' => 'X'],
            ['municipality' => str_repeat('a', 49)],
        ] as $input) {
            try {
                EetUnitDisFormat::normalize($input, null);
                self::fail('Očekávána validační chyba pro ' . json_encode($input));
            } catch (EetException $exception) {
                self::assertSame(array_key_first($input), $exception->details['field'] ?? null);
            }
        }

        $data = EetUnitDisFormat::normalize(['postal_code' => '110 00', 'municipality' => ' Testov ', 'records_sales' => 'false'], null);
        self::assertSame('11000', $data['postal_code']);
        self::assertSame('Testov', $data['municipality']);
        self::assertFalse($data['records_sales']);
        self::assertFalse($data['indirect_representation']);
    }
}
