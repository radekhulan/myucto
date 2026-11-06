<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceColumnMapper;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRuleSuggester;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheetAnalyzer;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;
use PHPUnit\Framework\TestCase;

/**
 * Celá cesta bez databáze: soubory → mapování sloupců → osoby s hodnotami.
 */
final class AttendancePersonAggregatorTest extends TestCase
{
    public function testPersonsComeOnlyFromThePersonColumnAndMergeAcrossFiles(): void
    {
        $result = $this->pipeline(AttendanceFixture::scenario());
        $persons = $this->byKey($result['persons']);

        // Kopie jmen vpravo, řádek součtů ani zopakovaná hlavička osoby nevyrobí.
        self::assertSame(['eva pokusna', 'jana testovaci', 'petr zkusebni'], array_keys($persons));
        $jana = $persons['jana testovaci'];
        self::assertSame('Jana Testovací', $jana['display_name']);
        self::assertSame('Z001', $jana['personal_number']);
        self::assertSame(AttendanceFixture::janaBirthNumber(), $jana['_birth_number']);
        self::assertContains(['sheet_id' => 'mzdy.csv#CSV', 'row' => 2], $jana['sources']);
        self::assertSame('Výroba', $jana['department']);
        self::assertSame('Pracovní poměr', $jana['relation_label']);
        self::assertSame('20', $persons['eva pokusna']['weekly_hours']);
        self::assertSame('nástup 1. 6. 2026', $persons['eva pokusna']['start_end_note']);
        self::assertSame('2026-06-01', $persons['eva pokusna']['start_on']);
        self::assertNull($persons['eva pokusna']['end_on']);

        $sheets = [];
        foreach ($result['sheets'] as $sheet) {
            $sheets[$sheet['sheet']->id()] = $sheet;
        }
        self::assertSame(2, $sheets['podklady.xlsx#Přehled']['layout']->headerRow);
        self::assertSame(3, $sheets['podklady.xlsx#Přehled']['data_rows']);
        self::assertSame(2, $sheets['provoz.xlsx#vstup']['data_rows']);
        self::assertSame(1, $sheets['podklady.xlsx#Přehled']['person_column']);
    }

    /**
     * Datum narození z textu i z buňky s datem skončí jako YYYY-MM-DD, kód
     * pojišťovny jako text. Obojí zůstává jen interní, do náhledu nejde.
     */
    public function testBirthDateAndHealthInsurerFromSourceData(): void
    {
        $persons = $this->byKey($this->pipeline(AttendanceFixture::scenarioWithPersonalData())['persons']);

        self::assertSame('1990-05-01', $persons['jana testovaci']['_birth_date']);
        self::assertSame('211', $persons['jana testovaci']['_health_insurer_code']);
        self::assertSame('1985-03-12', $persons['petr zkusebni']['_birth_date']);
        self::assertSame('111', $persons['petr zkusebni']['_health_insurer_code']);
        self::assertNull($persons['eva pokusna']['_birth_date']);
        self::assertNull($persons['eva pokusna']['_health_insurer_code']);

        $serial = (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable('1992-02-29'))->days;
        $workbook = AttendanceFixture::xlsx([
            'Osoby' => [
                'rows' => [
                    1 => ['A' => 'Jméno', 'B' => 'Datum narození', 'C' => 'ZP'],
                    2 => ['A' => 'Test Datový', 'B' => $serial, 'C' => 205],
                    3 => ['A' => 'Test Chybný', 'B' => '31. 2. 1990', 'C' => null],
                ],
                'formats' => ['B2' => 'd.m.yyyy'],
            ],
        ]);
        $persons = $this->byKey($this->pipeline([AttendanceFixture::file('osoby.xlsx', $workbook)])['persons']);

        self::assertSame('1992-02-29', $persons['datovy test']['_birth_date']);
        self::assertSame('205', $persons['datovy test']['_health_insurer_code']);
        self::assertNull($persons['chybny test']['_birth_date'], 'Neexistující datum se nepoužije.');
    }

    /**
     * Hodinová sazba zůstává text (čte ji až založení osoby), konec dohody se
     * čte z data v buňce i z textu a číslo účtu zůstává jen interní.
     */
    public function testHourlyRateAgreedEndAndBankAccountFromSourceData(): void
    {
        $serial = (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable('2026-12-31'))->days;
        $workbook = AttendanceFixture::xlsx([
            'Podklady' => [
                'rows' => [
                    1 => ['A' => 'Příjmení a jméno', 'B' => 'Hodinová sazba (Kč/h)', 'C' => 'Konec dohody', 'D' => 'Číslo účtu'],
                    2 => ['A' => 'Sazbová Alena', 'B' => 180.5, 'C' => $serial, 'D' => '1000000005/0100'],
                    3 => ['A' => 'Textová Běla', 'B' => '165,50 Kč', 'C' => '30. 9. 2026', 'D' => null],
                    4 => ['A' => 'Chybná Cecílie', 'B' => null, 'C' => 'do odvolání', 'D' => '123'],
                ],
                'formats' => ['C2' => 'd.m.yyyy'],
            ],
        ]);
        $persons = $this->byKey($this->pipeline(
            [AttendanceFixture::file('podklady.xlsx', $workbook)],
            AttendanceMyUctoSampleProfile::rules(),
        )['persons']);

        $alena = $persons['alena sazbova'];
        self::assertSame('180.5', $alena['hourly_rate']);
        self::assertSame('2026-12-31', $alena['agreed_end_on']);
        self::assertNull($alena['end_on'], 'Sjednaný konec není ukončení z poznámky.');
        self::assertSame('1000000005/0100', $alena['_bank_account']);
        self::assertNull($alena['bank_account_masked'], 'Maskuje až služba importu.');
        self::assertArrayNotHasKey('bank_account', $alena);

        self::assertSame('165,50 Kč', $persons['bela textova']['hourly_rate']);
        self::assertSame('2026-09-30', $persons['bela textova']['agreed_end_on']);
        self::assertNull($persons['bela textova']['_bank_account']);

        $cecilie = $persons['cecilie chybna'];
        self::assertNull($cecilie['hourly_rate']);
        self::assertNull($cecilie['agreed_end_on']);
        self::assertSame('do odvolání', $cecilie['end_date']);
        self::assertStringContainsString('Konec dohody „do odvolání“', implode(' ', $cecilie['warnings']));
    }

    public function testHoursDurationsAndEmptyCells(): void
    {
        $persons = $this->byKey($this->pipeline(AttendanceFixture::scenario())['persons']);
        $jana = $this->metrics($persons['jana testovaci']);
        $petr = $this->metrics($persons['petr zkusebni']);

        self::assertSame('168.00', $jana['worked_hours']['hours']);
        self::assertSame('16.00', $jana['vacation_hours']['hours']);
        self::assertSame([], $jana['vacation_hours']['conflicts'], 'Stejná hodnota ze dvou listů není konflikt.');
        self::assertSame('36.00', $jana['overtime_hours']['hours']);
        self::assertArrayNotHasKey('sick_hours', $jana, 'Prázdná buňka není nula.');
        self::assertSame('8.00', $petr['sick_hours']['hours']);
        self::assertSame('provoz.xlsx!vstup!B3', $jana['worked_hours']['source']);
        self::assertSame('168.00', $persons['jana testovaci']['reference']['hours']);
        self::assertSame(7187500, $persons['jana testovaci']['reference']['gross_minor']);
        self::assertSame(5512050, $persons['jana testovaci']['reference']['net_minor']);
    }

    /**
     * Dovolená je ve vstupním listu (24 h) i ve výpočtu (20 h). Nesčítá se:
     * platí první podle pořadí pravidel a druhá hodnota je konflikt s varováním.
     */
    public function testSameMeaningFromTwoSheetsIsNeverSummed(): void
    {
        $petr = $this->byKey($this->pipeline(AttendanceFixture::scenario())['persons'])['petr zkusebni'];
        $vacation = $this->metrics($petr)['vacation_hours'];

        self::assertSame('24.00', $vacation['hours']);
        self::assertSame([['hours' => '20.00', 'source' => 'provoz.xlsx!výpočet!B3']], $vacation['conflicts']);
        self::assertNotEmpty(array_filter($petr['warnings'], static fn (string $w): bool => str_contains($w, 'nesčítají')));
    }

    public function testRuleOrderDecidesWhichValueWins(): void
    {
        $rules = AttendanceRules::validate([
            ['sheet' => 'výpočet', 'header' => 'Dovolená', 'meaning' => 'vacation_hours', 'unit' => 'hours', 'component_code' => null],
        ]);
        $petr = $this->byKey($this->pipeline(AttendanceFixture::scenario(), $rules)['persons'])['petr zkusebni'];
        $vacation = $this->metrics($petr)['vacation_hours'];

        self::assertSame('20.00', $vacation['hours']);
        self::assertSame('24.00', $vacation['conflicts'][0]['hours']);
    }

    public function testComponentsUseStoredFormulaValuesAndReportErrors(): void
    {
        $persons = $this->byKey($this->pipeline(AttendanceFixture::scenario())['persons']);
        $jana = $this->components($persons['jana testovaci']);
        $petr = $this->components($persons['petr zkusebni']);

        self::assertSame(150000, $jana['ODMENA']['amount_minor']);
        self::assertSame([], $jana['ODMENA']['conflicts']);
        self::assertSame(1680000, $jana['MZDA_UKOLOVA']['amount_minor']);
        self::assertSame(123450, $jana['PREMIE_PRIPLATKY']['amount_minor']);
        self::assertSame(200000, $petr['ODMENA']['amount_minor']);
        self::assertSame(250000, $petr['ODMENA']['conflicts'][0]['amount_minor']);
        self::assertArrayNotHasKey('PREMIE_PRIPLATKY', $petr, '#REF! není nula ani částka.');
        self::assertNotEmpty(array_filter(
            $persons['petr zkusebni']['warnings'],
            static fn (string $w): bool => str_contains($w, '#REF!') && str_contains($w, 'výpočet!D3'),
        ));
        self::assertArrayNotHasKey('ODMENA', $this->components($persons['eva pokusna']));
    }

    public function testSameNameWithDifferentPersonalNumbersIsNotMerged(): void
    {
        $csv = "Jméno;Osobní číslo;Dovolená\nJan Novák;A1;8\nNovák Jan;A2;16\n";
        $persons = $this->byKey($this->pipeline([AttendanceFixture::file('a.csv', $csv)])['persons']);

        self::assertSame(['jan novak#A1', 'jan novak#A2'], array_keys($persons));
        self::assertSame('8.00', $this->metrics($persons['jan novak#A1'])['vacation_hours']['hours']);
        self::assertNotEmpty($persons['jan novak#A2']['warnings']);
    }

    public function testDuplicateRowOfOnePersonInOneSheetIsAConflictNotASum(): void
    {
        $csv = "Jméno;Dovolená\nJan Novák;8\nIng. Jan Novák;16\n";
        $persons = $this->byKey($this->pipeline([AttendanceFixture::file('a.csv', $csv)])['persons']);
        $vacation = $this->metrics($persons['jan novak'])['vacation_hours'];

        self::assertCount(1, $persons);
        self::assertSame('8.00', $vacation['hours']);
        self::assertSame('16.00', $vacation['conflicts'][0]['hours']);
        self::assertNotEmpty(array_filter($persons['jan novak']['warnings'], static fn (string $w): bool => str_contains($w, 'vícekrát')));
    }

    /**
     * Seznam bez čísel uvádí jméno i s dalším křestním jménem, mzdový export
     * bez něj. Je to jedna osoba — spojí se, ale ohlásí se to.
     */
    public function testNameVariantWithExtraGivenNameJoinsTheIdentifiedPerson(): void
    {
        $rules = AttendanceRules::validate([
            ['sheet' => null, 'header' => 'Jméno', 'meaning' => 'person_name', 'unit' => null, 'component_code' => null],
            ['sheet' => null, 'header' => 'Osobní číslo', 'meaning' => 'personal_number', 'unit' => null, 'component_code' => null],
            ['sheet' => null, 'header' => 'Dovolená', 'meaning' => 'vacation_hours', 'unit' => 'hours', 'component_code' => null],
        ]);
        $persons = $this->byKey($this->pipeline([
            AttendanceFixture::file('mzdy.csv', "Jméno;Osobní číslo\nZkoušková Marie Mgr.;Z9\n"),
            AttendanceFixture::file('seznam.csv', "Jméno;Dovolená\nZkoušková Marie Anna;8\n"),
        ], $rules)['persons']);

        self::assertSame(['marie zkouskova'], array_keys($persons));
        self::assertSame('Z9', $persons['marie zkouskova']['personal_number']);
        self::assertSame('8.00', $this->metrics($persons['marie zkouskova'])['vacation_hours']['hours']);
        self::assertNotEmpty(array_filter(
            $persons['marie zkouskova']['warnings'],
            static fn (string $w): bool => str_contains($w, 'spojily'),
        ));
    }

    public function testGenerationMarkerKeepsFatherAndSonApart(): void
    {
        $csv = "Jméno;Dovolená\nNovák Jan;8\nNovák Jan ml.;16\n";
        $persons = $this->byKey($this->pipeline([AttendanceFixture::file('a.csv', $csv)])['persons']);

        self::assertSame(['jan ml novak', 'jan novak'], array_keys($persons));
    }

    public function testRelationNoteInBracketsIsNotPartOfTheName(): void
    {
        $csv = "Jméno;Dovolená\nKopecký Tomáš (DPP);8\n";
        $persons = $this->byKey($this->pipeline([AttendanceFixture::file('a.csv', $csv)])['persons']);

        self::assertSame(['kopecky tomas'], array_keys($persons));
    }

    public function testConditionalRuleDecidesPerRow(): void
    {
        $rules = AttendanceRules::validate([
            ['sheet' => null, 'header' => 'Jméno', 'meaning' => 'person_name', 'unit' => null, 'component_code' => null],
            ['sheet' => null, 'header' => 'Oddělení', 'meaning' => 'department', 'unit' => null, 'component_code' => null],
            [
                'sheet' => null, 'header' => 'Odměny', 'meaning' => 'component', 'unit' => 'amount',
                'component_code' => 'MZDA_UKOLOVA', 'when_header' => 'oddělení', 'when_value' => 'výroba',
            ],
            ['sheet' => null, 'header' => 'Odměny', 'meaning' => 'component', 'unit' => 'amount', 'component_code' => 'ODMENA'],
        ]);
        $csv = "Jméno;Oddělení;Odměny\nJan Novák;Výroba;70000\nPetr Svoboda;sklad;2000\n";
        $persons = $this->byKey($this->pipeline([AttendanceFixture::file('a.csv', $csv)], $rules)['persons']);

        self::assertSame(['MZDA_UKOLOVA' => 7_000_000], array_column($persons['jan novak']['components'], 'amount_minor', 'component_code'));
        self::assertSame(['ODMENA' => 200_000], array_column($persons['petr svoboda']['components'], 'amount_minor', 'component_code'));
    }

    public function testDeductionColumnsBecomeDeductionsNotComponents(): void
    {
        $rules = AttendanceRules::validate([
            ['sheet' => null, 'header' => 'Jméno', 'meaning' => 'person_name', 'unit' => null, 'component_code' => null],
            ['sheet' => null, 'header' => 'Obědy', 'meaning' => 'net_meal_deduction', 'unit' => 'amount', 'component_code' => null],
            ['sheet' => null, 'header' => 'Srážky', 'meaning' => 'net_other_deduction', 'unit' => 'amount', 'component_code' => null],
        ]);
        $csv = "Jméno;Obědy;Srážky\nJan Novák;290;\nPetr Svoboda;-50;0\n";
        $persons = $this->byKey($this->pipeline([AttendanceFixture::file('a.csv', $csv)], $rules)['persons']);

        self::assertSame([], $persons['jan novak']['components']);
        self::assertSame(['net_meal_deduction' => 29_000], array_column($persons['jan novak']['deductions'], 'amount_minor', 'meaning'));
        self::assertSame(29_000, $persons['jan novak']['_deductions']['net_meal_deduction']['amount_minor']);
        self::assertSame([], $persons['petr svoboda']['deductions']);
        self::assertNotEmpty(array_filter(
            $persons['petr svoboda']['warnings'],
            static fn (string $w): bool => str_contains($w, 'záporná'),
        ));
    }

    public function testSuggestedRulesAreReturnedAndCopiesOfNamesAreIgnored(): void
    {
        $result = $this->pipeline(AttendanceFixture::scenario());
        $vstup = null;
        foreach ($result['sheets'] as $sheet) {
            if ($sheet['sheet']->id() === 'provoz.xlsx#vstup') {
                $vstup = $sheet;
            }
        }
        self::assertNotNull($vstup);
        self::assertSame('person_name', $vstup['columns'][1]['meaning']);
        self::assertSame('ignore', $vstup['columns'][6]['meaning']);
        self::assertSame('none', $vstup['columns'][6]['rule_source']);
        self::assertSame('excel_duration', $vstup['columns'][3]['unit']);
        self::assertSame(['16:00', '24:00'], $vstup['columns'][3]['samples']);

        $meanings = array_map(static fn (array $rule): string => $rule['meaning'], $result['rules']);
        self::assertContains('vacation_hours', $meanings);
        self::assertNotContains('ignore', $meanings);
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @param list<array<string,mixed>> $rules
     * @return array{sheets:list<array<string,mixed>>,rules:list<array<string,mixed>>,persons:list<array<string,mixed>>}
     */
    private function pipeline(array $files, array $rules = []): array
    {
        $reader = new AttendanceWorkbookReader();
        $sheets = [];
        foreach ($files as $index => $file) {
            array_push($sheets, ...$reader->read($file['name'], $index, $file['extension'], $file['content']));
        }
        $suggester = new AttendanceRuleSuggester();
        $mapper = new AttendanceColumnMapper(new AttendanceSheetAnalyzer($suggester), $suggester);
        /** @var list<array{sheet:?string,header:string,meaning:string,unit:?string,component_code:?string}> $rules */
        $mapped = $mapper->map($sheets, $rules);

        return $mapped + ['persons' => (new AttendancePersonAggregator($mapper))->aggregate($mapped['sheets'])];
    }

    /**
     * @param list<array<string,mixed>> $persons
     * @return array<string,array<string,mixed>>
     */
    private function byKey(array $persons): array
    {
        $result = [];
        foreach ($persons as $person) {
            $result[$person['key']] = $person;
        }
        ksort($result);

        return $result;
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,array<string,mixed>>
     */
    private function metrics(array $person): array
    {
        return array_column($person['metrics'], null, 'meaning');
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,array<string,mixed>>
     */
    private function components(array $person): array
    {
        return array_column($person['components'], null, 'component_code');
    }
}
