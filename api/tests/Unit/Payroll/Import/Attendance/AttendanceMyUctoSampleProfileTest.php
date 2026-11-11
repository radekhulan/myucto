<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceColumnMapper;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoTemplate;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceProfileComponents;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRuleSuggester;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSampleProfiles;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheetAnalyzer;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * Vzor MyÚčto a jeho šablona XLSX: pravidla i šablona vznikají z jednoho
 * seznamu sloupců, takže vygenerovaná šablona se vzorem přečte celá.
 */
final class AttendanceMyUctoSampleProfileTest extends TestCase
{
    public function testRulesAndComponentsAreValid(): void
    {
        self::assertSame(AttendanceMyUctoSampleProfile::rules(), AttendanceRules::validate(AttendanceMyUctoSampleProfile::rules()));
        self::assertCount(
            count(AttendanceMyUctoSampleProfile::components()),
            AttendanceProfileComponents::validate(AttendanceMyUctoSampleProfile::components()),
        );
    }

    /** Obecný vzor nezakládá složky podle hlavičky: neznámé sloupce se ignorují. */
    public function testNoComponentByHeader(): void
    {
        foreach (AttendanceMyUctoSampleProfile::rules() as $rule) {
            self::assertNotSame(AttendanceRules::AUTO_COMPONENT, $rule['component_code']);
        }
        $last = AttendanceMyUctoSampleProfile::rules()[count(AttendanceMyUctoSampleProfile::rules()) - 1];
        self::assertSame(['sheet' => '*', 'header' => '*', 'meaning' => AttendanceMeaning::IGNORE], array_intersect_key($last, ['sheet' => 1, 'header' => 1, 'meaning' => 1]));
    }

    /**
     * Složky vzoru mají zařazení pro JMHZ z výchozího číselníku nebo z druhu
     * deklarované složky. Výjimkou je odměna (druh `bonus`), o pravidelnosti
     * rozhoduje účetní, stejně jako ve Vzoru GIRITON.
     */
    public function testEveryComponentHasJmhzTargetOrIsBonus(): void
    {
        $declared = array_column(AttendanceMyUctoSampleProfile::components(), null, 'code');
        $codes = [];
        foreach (AttendanceMyUctoSampleProfile::rules() as $rule) {
            $code = $rule['component_code'];
            if ($rule['meaning'] !== 'component' || $code === null) {
                continue;
            }
            $codes[] = $code;
            if (($declared[$code]['kind'] ?? null) === 'bonus') {
                continue;
            }
            $target = isset($declared[$code])
                ? PayrollComponentJmhzMappingDefaults::targetFor($code, (string) $declared[$code]['kind'], 'one_off', 'included')
                : PayrollComponentJmhzMappingDefaults::targetForCode($code);
            self::assertNotNull($target, "Složka {$code} nemá zařazení pro JMHZ.");
        }
        // Odměna je z výchozího číselníku (10331), ne deklarovaná složka vzoru.
        self::assertContains('ODMENA', $codes);
        self::assertArrayNotHasKey('ODMENA', $declared);
        self::assertSame('10331', PayrollComponentJmhzMappingDefaults::targetForCode('ODMENA'));
        self::assertSame('10329', PayrollComponentJmhzMappingDefaults::targetFor('MZDA_HODINOVA_DOCH', 'hourly_wage', 'one_off', 'included'));
    }

    public function testRegistryKeepsGiritonAndAddsMyUcto(): void
    {
        self::assertSame(['giriton', 'myucto', 'pamica'], AttendanceSampleProfiles::keys());
        self::assertSame(AttendanceSampleProfile::NAME, AttendanceSampleProfiles::name('giriton'));
        self::assertSame(AttendanceSampleProfile::rules(), AttendanceSampleProfiles::rules('giriton'));
        self::assertSame(AttendanceSampleProfile::VERSION, AttendanceSampleProfiles::version('giriton'));
        self::assertSame('Vzor MyÚčto', AttendanceSampleProfiles::name('myucto'));
        self::assertFalse(AttendanceSampleProfiles::isKey('neznamy'));
    }

    /** Každá hlavička šablony dostane přesně ten význam, který jí seznam sloupců dává. */
    public function testEveryTemplateColumnIsRecognized(): void
    {
        $result = $this->pipeline([$this->templateFile()]);
        $sheets = [];
        foreach ($result['sheets'] as $sheet) {
            $sheets[$sheet['sheet']->name] = $sheet;
        }
        self::assertSame([AttendanceMyUctoSampleProfile::SHEET, AttendanceMyUctoSampleProfile::GUIDE_SHEET], array_keys($sheets));
        // Návod osoby nevyrobí.
        self::assertNull($sheets[AttendanceMyUctoSampleProfile::GUIDE_SHEET]['person_column']);

        $data = $sheets[AttendanceMyUctoSampleProfile::SHEET];
        $bound = [];
        foreach ($data['columns'] as $column) {
            self::assertSame('profile', $column['rule_source'], $column['header']);
            $bound[$column['header']] = [$column['meaning'], $column['component_code']];
        }
        $expected = [];
        foreach (AttendanceMyUctoSampleProfile::columns() as $column) {
            $expected[$column['header']] = [$column['meaning'], $column['component_code']];
        }
        self::assertSame($expected, $bound);
    }

    public function testTemplateRoundTripGivesPersonsHoursAndComponents(): void
    {
        $persons = [];
        foreach ($this->pipeline([$this->templateFile()])['persons'] as $person) {
            $persons[$person['key']] = $person;
        }
        ksort($persons);
        self::assertSame(['adam prikladny', 'eva ukazkova'], array_keys($persons));

        $eva = $persons['eva ukazkova'];
        self::assertSame([], $eva['warnings']);
        self::assertSame('Ukázková Eva', $eva['display_name']);
        self::assertSame('B001', $eva['personal_number']);
        self::assertSame('DPP', $eva['relation_label']);
        self::assertSame('2026-06-01', $eva['start_on']);
        self::assertSame('2001-03-15', $eva['_birth_date']);
        self::assertSame('111', $eva['_health_insurer_code']);
        self::assertNull($eva['weekly_hours']);
        self::assertSame(
            ['worked_hours' => '42.50', 'night_hours' => '8.00', 'weekend_hours' => '12.00'],
            array_column($eva['metrics'], 'hours', 'meaning'),
        );
        $components = array_column($eva['components'], 'amount_minor', 'component_code');
        ksort($components);
        self::assertSame(['MZDA_HODINOVA_DOCH' => 765_000, 'ODMENA' => 100_000], $components);
        self::assertSame([], $eva['deductions']);
        // Údaje pro registraci ČSSZ z ukázkového řádku.
        self::assertSame([
            'address_street' => 'Ukázková',
            'address_house_number' => '12',
            'address_city' => 'Praha',
            'address_postal_code' => '110 00',
            'address_country' => 'CZ',
            'citizenship' => 'CZ',
            'cz_isco_code' => '43111',
            'workplace' => 'Praha',
            'tax_residence' => 'CZ',
            'tax_declaration' => 'ano',
            'tax_declaration_from' => '2026-06-01',
            'education_code' => 'M',
        ], $eva['_registration']);

        $adam = $persons['adam prikladny'];
        self::assertSame([], $adam['warnings']);
        self::assertSame('DPČ', $adam['relation_label']);
        self::assertSame('2026-06-15', $adam['start_on']);
        self::assertSame('10', $adam['weekly_hours']);
        self::assertSame('211', $adam['_health_insurer_code']);
        self::assertSame(
            ['worked_hours' => '60.00', 'vacation_hours' => '8.00'],
            array_column($adam['metrics'], 'hours', 'meaning'),
        );
        $components = array_column($adam['components'], 'amount_minor', 'component_code');
        ksort($components);
        self::assertSame(['MZDA_HODINOVA_DOCH' => 1_080_000, 'ODMENA_MIMORADNA' => 200_000], $components);
        self::assertSame(['net_other_deduction' => 50_000], array_column($adam['deductions'], 'amount_minor', 'meaning'));
    }

    /**
     * Vlastní sloupec s částkou, který firma do šablony připsala, nevyrobí
     * složku podle hlavičky; hodiny zapsané jako čas v Excelu se přečtou.
     */
    public function testUnknownColumnIsIgnoredAndExcelTimeIsRead(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'myucto_tpl_') . '.xlsx';
        try {
            file_put_contents($source, (new AttendanceMyUctoTemplate())->build()['bytes']);
            $spreadsheet = IOFactory::load($source);
        } finally {
            @unlink($source);
        }
        $sheet = $spreadsheet->getSheetByName(AttendanceMyUctoSampleProfile::SHEET);
        self::assertNotNull($sheet);
        $headers = array_column(AttendanceMyUctoSampleProfile::columns(), 'header');
        $letter = static fn (string $header): string => Coordinate::stringFromColumnIndex(
            (int) array_search($header, $headers, true) + 1,
        );
        $extra = Coordinate::stringFromColumnIndex(count($headers) + 1);
        $sheet->setCellValueExplicit("{$extra}1", 'Prémie navíc (Kč)', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("{$extra}2", 999, DataType::TYPE_NUMERIC);
        // „Odpracováno" jako čas v Excelu (trvání ve dnech): 42:30 a 60:00.
        $worked = $letter('Odpracováno (hod)');
        $sheet->setCellValueExplicit("{$worked}2", 42.5 / 24, DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit("{$worked}3", 60 / 24, DataType::TYPE_NUMERIC);
        $sheet->getStyle("{$worked}2:{$worked}3")->getNumberFormat()->setFormatCode('[h]:mm');
        $sheet->setCellValueExplicit($letter('Nástup') . '2', '1. 7. 2026', DataType::TYPE_STRING);
        $path = tempnam(sys_get_temp_dir(), 'myucto_') . '.xlsx';
        try {
            (new XlsxWriter($spreadsheet))->save($path);
            $content = (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $spreadsheet->disconnectWorksheets();
        }

        $result = $this->pipeline([AttendanceFixture::file('podklady.xlsx', $content)]);
        self::assertSame([], $result['auto_components']);
        $persons = array_column($result['persons'], null, 'key');
        $eva = $persons['eva ukazkova'];
        self::assertSame('2026-07-01', $eva['start_on']);
        self::assertSame('42.50', array_column($eva['metrics'], 'hours', 'meaning')['worked_hours']);
        self::assertNotContains(999_00, array_column($eva['components'], 'amount_minor'));
    }

    /** @return array{name:string,content:string,sha256:string,extension:string} */
    private function templateFile(): array
    {
        $file = (new AttendanceMyUctoTemplate())->build();
        self::assertSame(AttendanceMyUctoTemplate::FILENAME, $file['filename']);

        return AttendanceFixture::file($file['filename'], $file['bytes']);
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array<string,mixed>
     */
    private function pipeline(array $files): array
    {
        $reader = new AttendanceWorkbookReader();
        $sheets = [];
        foreach ($files as $index => $file) {
            array_push($sheets, ...$reader->read($file['name'], $index, $file['extension'], $file['content']));
        }
        $suggester = new AttendanceRuleSuggester();
        $mapper = new AttendanceColumnMapper(new AttendanceSheetAnalyzer($suggester), $suggester);
        $mapped = $mapper->map($sheets, AttendanceRules::validate(AttendanceMyUctoSampleProfile::rules()));

        return $mapped + ['persons' => (new AttendancePersonAggregator($mapper))->aggregate($mapped['sheets'])];
    }
}
