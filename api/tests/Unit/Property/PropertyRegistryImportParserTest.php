<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyRegistryImportParser;
use MyInvoice\Service\Property\PropertyRational;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class PropertyRegistryImportParserTest extends TestCase
{
    public function testCsvRequiresExplicitPartyMappingAndKeepsOneMaritalParticipation(): void
    {
        $rows = [self::unit(), self::owner('A'), self::owner('B')];
        $parser = new PropertyRegistryImportParser();
        $unresolved = $parser->parse('csv', self::csv($rows), [], []);
        self::assertFalse($unresolved['ready']);
        self::assertSame(['A', 'B'], array_column($unresolved['unresolved_parties'], 'reference'));
        self::assertSame([], $unresolved['units'][0]['state']['ownerships']);
        $resolved = $parser->parse('csv', self::csv($rows), ['A' => 11, 'B' => 12], []);
        self::assertTrue($resolved['ready'], json_encode($resolved['errors']));
        $groups = $resolved['units'][0]['state']['ownerships'];
        self::assertCount(1, $groups);
        self::assertSame([11, 12], $groups[0]['members']);
        self::assertSame(['num' => '1', 'den' => '1'], $groups[0]['share']);
        self::assertArrayNotHasKey('debtor', $groups[0]);
    }

    public function testConflictingMembersCannotSilentlyCreateTwoClaims(): void
    {
        $second = self::owner('B');
        $second['title_ref'] = 'Different synthetic deed';
        $result = (new PropertyRegistryImportParser())->rows([self::unit(), self::owner('A'), $second], ['A' => 11, 'B' => 12], []);
        self::assertFalse($result['ready']);
        self::assertContains('property_import_group_conflict', array_column($result['errors'], 'code'));
    }

    public function testNumericSourceReferencesRemainUsableThroughJsonObjectMaps(): void
    {
        $parties = json_decode('{"123":11,"124":12}', true, flags: JSON_THROW_ON_ERROR);
        $services = json_decode('{"42":7}', true, flags: JSON_THROW_ON_ERROR);
        $result = (new PropertyRegistryImportParser())->rows([self::unit(), self::owner('123'), self::owner('124'),
            ['record_type' => 'service', 'unit_code' => 'U01', 'service_code' => '42']], $parties, $services);
        self::assertTrue($result['ready']);
        self::assertSame([11, 12], $result['units'][0]['state']['ownerships'][0]['members']);
        self::assertSame([7], $result['units'][0]['state']['services']);
    }

    public function testTwelveUnitCsvKeepsRepeatedOwnerThirdPartyPayerAndGarage(): void
    {
        $fixture = require dirname(__DIR__, 2) . '/Fixtures/property-house-at-limes.php';
        $rows = [];
        $parties = [];
        foreach ($fixture as $code => $unit) {
            $rows[] = ['record_type' => 'unit', 'unit_code' => $code, 'unit_no' => $unit['unit_no'], 'kind' => $unit['kind'],
                'share_num' => $unit['attributes']['share']['num'], 'share_den' => $unit['attributes']['share']['den']];
            foreach ($unit['ownerships'] as $group) foreach ($group['members'] as $id) {
                $parties['P' . $id] = $id;
                $rows[] = ['record_type' => 'owner', 'unit_code' => $code, 'group_key' => $group['group_key'], 'ownership_kind' => $group['kind'],
                    'person_ref' => 'P' . $id, 'num' => $group['share']['num'], 'den' => $group['share']['den'], 'title_ref' => $group['title_ref']];
            }
            foreach ($unit['roles'] as $role) {
                $parties['P' . $role['client_id']] = $role['client_id'];
                $rows[] = ['record_type' => 'role', 'unit_code' => $code, 'person_ref' => 'P' . $role['client_id'], 'role' => $role['role'],
                    'scopes' => implode('|', $role['scopes']), 'title_ref' => $role['title_ref']];
            }
            foreach ($unit['services'] as $id) $rows[] = ['record_type' => 'service', 'unit_code' => $code, 'service_code' => 'S' . $id];
        }
        $result = (new PropertyRegistryImportParser())->parse('csv', self::csv($rows), $parties, ['S1' => 1]);
        self::assertTrue($result['ready'], json_encode($result['errors']));
        self::assertCount(12, $result['units']);
        $states = array_column(array_column($result['units'], 'state', 'code'), null);
        self::assertSame(['num' => '1', 'den' => '1'], PropertyRational::sum(array_map(static fn (array $s): array => $s['attributes']['share'], $states)));
        $units = array_column($result['units'], 'state', 'code');
        self::assertSame($units['U03']['ownerships'][0]['members'], $units['U04']['ownerships'][0]['members']);
        self::assertSame(13, $units['U07']['roles'][0]['client_id']);
        self::assertSame([12], $units['U07']['ownerships'][0]['members']);
        self::assertSame([], $units['G12']['services']);
    }

    public function testUnknownServiceAndDuplicateUnitBlockImport(): void
    {
        $result = (new PropertyRegistryImportParser())->rows([self::unit(), self::unit(),
            ['record_type' => 'service', 'unit_code' => 'U01', 'service_code' => 'WATER']], [], []);
        self::assertFalse($result['ready']);
        self::assertSame(['WATER'], $result['unresolved_services']);
        self::assertContains('property_import_duplicate_unit', array_column($result['errors'], 'code'));
    }

    public function testXlsxParsesTheSameDataAndRejectsFormula(): void
    {
        $rows = [self::unit(), self::owner('A'), self::owner('B')];
        $parser = new PropertyRegistryImportParser();
        $csv = $parser->parse('csv', self::csv($rows), ['A' => 11, 'B' => 12], []);
        $xlsx = $parser->parse('xlsx', self::xlsx($rows), ['A' => 11, 'B' => 12], []);
        self::assertSame($csv, $xlsx);
        $this->expectException(PropertyException::class);
        $parser->parse('xlsx', self::xlsx($rows, formula: true), [], []);
    }

    public function testSecondSheetIsNeverSilentlyIgnored(): void
    {
        try {
            (new PropertyRegistryImportParser())->parse('xlsx', self::xlsx([self::unit()], secondSheet: true), [], []);
            self::fail('A second sheet cannot be silently discarded.');
        } catch (PropertyException $e) {
            self::assertSame('property_import_single_sheet', $e->errorCode);
        }
    }

    private static function unit(): array
    {
        return ['record_type' => 'unit', 'unit_code' => 'U01', 'unit_no' => '12/1', 'kind' => 'apartment', 'share_num' => '90', 'share_den' => '1000'];
    }

    private static function owner(string $person): array
    {
        return ['record_type' => 'owner', 'unit_code' => 'U01', 'group_key' => 'MARRIED', 'ownership_kind' => 'marital_community',
            'person_ref' => $person, 'person_name' => 'Same synthetic name', 'person_email' => 'same@example.test',
            'num' => '1', 'den' => '1', 'title_ref' => 'Synthetic deed'];
    }

    private static function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, PropertyRegistryImportParser::COLUMNS, ';', escape: '');
        foreach ($rows as $row) fputcsv($stream, array_map(static fn (string $column): string => (string) ($row[$column] ?? ''), PropertyRegistryImportParser::COLUMNS), ';', escape: '');
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return $content;
    }

    private static function xlsx(array $rows, bool $formula = false, bool $secondSheet = false): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        foreach (PropertyRegistryImportParser::COLUMNS as $i => $column) $sheet->setCellValueExplicit([$i + 1, 1], $column, DataType::TYPE_STRING);
        foreach ($rows as $r => $row) foreach (PropertyRegistryImportParser::COLUMNS as $i => $column) {
            $sheet->setCellValueExplicit([$i + 1, $r + 2], (string) ($row[$column] ?? ''), DataType::TYPE_STRING);
        }
        if ($formula) $sheet->setCellValue('C2', '=1+1');
        if ($secondSheet) $book->createSheet()->setCellValue('A1', 'Not silently ignored');
        $path = tempnam(sys_get_temp_dir(), 'property-test-');
        try {
            (new Xlsx($book))->save($path);
            return file_get_contents($path);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }
}
