<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceColumnMapper;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePamicaExport;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePamicaSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePamicaTemplate;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePeriodDetector;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceProfileComponents;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRuleSuggester;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSampleProfiles;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheetAnalyzer;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;
use PHPUnit\Framework\TestCase;

/**
 * Vzor PAMICA: XML „Docházka zaměstnanců" i denní CSV téhož docházkového
 * systému se sečtou na měsíční souhrn osoby a vzor ho přečte celý.
 * Všechna data v testu jsou smyšlená.
 */
final class AttendancePamicaSampleProfileTest extends TestCase
{
    public function testRulesAreValidAndRegistered(): void
    {
        self::assertSame(AttendancePamicaSampleProfile::rules(), AttendanceRules::validate(AttendancePamicaSampleProfile::rules()));
        self::assertCount(
            count(AttendancePamicaSampleProfile::components()),
            AttendanceProfileComponents::validate(AttendancePamicaSampleProfile::components()),
        );
        self::assertTrue(AttendanceSampleProfiles::isKey('pamica'));
        self::assertSame('Vzor PAMICA', AttendanceSampleProfiles::name('pamica'));
    }

    /**
     * Složky z výchozího číselníku mají zařazení pro JMHZ; deklarovaná složka
     * vzoru je odměna (druh `bonus`), o její pravidelnosti rozhoduje účetní.
     */
    public function testEveryComponentHasJmhzTargetOrIsBonus(): void
    {
        $declared = array_column(AttendancePamicaSampleProfile::components(), 'kind', 'code');
        $codes = [];
        foreach (AttendancePamicaSampleProfile::rules() as $rule) {
            if ($rule['meaning'] !== 'component') {
                continue;
            }
            $code = (string) $rule['component_code'];
            $codes[] = $code;
            self::assertNotSame(AttendanceRules::AUTO_COMPONENT, $code);
            if (($declared[$code] ?? null) === 'bonus' || $code === 'ODMENA') {
                continue;
            }
            // Osvobozená část stravování nemá v rozpadu JMHZ kolonku (stejně jako PRISPEVEK_STRAVOVANI).
            if ($code === 'PRISPEVEK_STRAVOVANI_PREVZATY') {
                self::assertNull(PayrollComponentJmhzMappingDefaults::targetForCode($code));
                continue;
            }
            self::assertArrayNotHasKey($code, $declared, "Složka {$code} má být z výchozího číselníku.");
            self::assertNotNull(PayrollComponentJmhzMappingDefaults::targetForCode($code), "Složka {$code} nemá zařazení pro JMHZ.");
        }
        self::assertContains(AttendancePamicaExport::PERSONAL_RATING, $codes);
    }

    public function testXmlSumsScheduleAbsencesAndOvertime(): void
    {
        $persons = $this->persons([$this->file('dochazka.xml', self::xml())]);
        self::assertSame(['petr zkusebni'], array_keys($persons));
        $petr = $persons['petr zkusebni'];
        self::assertSame('70001', $petr['personal_number']);
        self::assertSame('Pracovní poměr', $petr['relation_label']);
        // Rozvrh 5 × 8 h (víkend 0), dovolená 2 dny, nemoc přes víkend jen pracovní den,
        // svátek 1 den, přesčas 1:30 + 2:00.
        self::assertSameHours(
            [
                'fund_hours' => '40.00',
                'worked_hours' => '11.50',
                'overtime_hours' => '3.50',
                'vacation_hours' => '16.00',
                'holiday_hours' => '8.00',
                'sick_hours' => '8.00',
            ],
            self::hours($petr),
        );
    }

    public function testUnknownAbsenceCodeIsReportedNotImported(): void
    {
        $xml = str_replace('<kod>V02</kod>', '<kod>N99</kod>', self::xml());
        $sheets = (new AttendanceWorkbookReader())->read('dochazka.xml', 0, 'xml', $xml);
        self::assertCount(1, $sheets);
        self::assertStringContainsString('N99', implode(' ', $sheets[0]->warnings));
        $petr = $this->persons([$this->file('dochazka.xml', $xml)])['petr zkusebni'];
        self::assertArrayNotHasKey('holiday_hours', array_column($petr['metrics'], 'hours', 'meaning'));
    }

    public function testSheetNameCarriesPeriod(): void
    {
        $sheets = (new AttendanceWorkbookReader())->read('dochazka.xml', 0, 'xml', self::xml());
        self::assertSame('PAMICA 2026-03', $sheets[0]->name);
        self::assertSame(['2026-03'], array_keys(AttendancePeriodDetector::detect([$sheets[0]->name])));
    }

    public function testDailyCsvIsCollapsedPerPerson(): void
    {
        $csv = "\u{FEFF}Jmeno;Prijmeni;Cislo;Datum;Prichod;Odchod;Fond;Nemoc;OCR;DL;Lekar;Dovolena;StatniSvatek;Studium;StudijniVolno;NeplaceneVolno;PrekazkaVPraci;Soud;Teren;Skoleni;HomeOffice;\n"
            . "Petr;Zkušební;70001;02.03.2026;07:00;15:30;8;0;0;0;0;0;0;0;0;0;0;0;0;0;0;\n"
            . "Petr;Zkušební;70001;03.03.2026;;;8;0;0;0;0;8;0;0;0;0;0;0;0;0;0;\n"
            . "Petr;Zkušební;70001;04.03.2026;07:00;13:00;8;0;0;0;2;0;0;0;0;0;0;0;0;0;0;\n"
            . "Petr;Zkušební;70001;05.03.2026;;;8;0;0;8;0;0;0;0;0;0;0;0;0;0;0;\n"
            . "Petr;Zkušební;70001;07.03.2026;08:00;12:00;0;0;0;0;0;0;0;0;0;0;0;0;0;0;0;\n"
            . "Jana;Pokusná;70002;02.03.2026;06:00;14:30;8;0;0;0;0;0;0;0;0;0;0;0;0;0;8;\n";
        $sheets = (new AttendanceWorkbookReader())->read('export.csv', 0, 'csv', $csv);
        self::assertCount(1, $sheets);
        self::assertSame('PAMICA 2026-03', $sheets[0]->name);
        self::assertStringContainsString('7. 3.', implode(' ', $sheets[0]->warnings));

        $persons = $this->persons([$this->file('export.csv', $csv)]);
        self::assertSameHours(
            [
                'fund_hours' => '32.00',
                // 8 + 0 + 6 + 0 + 4 (sobota mimo rozvrh podle přítomnosti)
                'worked_hours' => '18.00',
                'vacation_hours' => '8.00',
                'care_hours' => '8.00',
                'doctor_hours' => '2.00',
            ],
            self::hours($persons['petr zkusebni']),
        );
        self::assertSameHours(
            ['fund_hours' => '8.00', 'worked_hours' => '8.00', 'home_office_hours' => '8.00'],
            self::hours($persons['jana pokusna']),
        );
    }

    public function testOtherCsvStaysUntouched(): void
    {
        $sheets = (new AttendanceWorkbookReader())->read('jiny.csv', 0, 'csv', "Jméno;Odpracováno\nPetr Zkušební;10\n");
        self::assertSame('CSV', $sheets[0]->name);
    }

    public function testTemplateRoundTripUsesOnlyFictitiousPeople(): void
    {
        $file = (new AttendancePamicaTemplate())->build('2026-11');
        self::assertSame('pamica-dochazka-vzor-2026-11.xml', $file['filename']);
        $persons = $this->persons([$this->file($file['filename'], $file['bytes'])]);
        self::assertSame(['adam prikladny', 'eva ukazkova', 'jana vzorova'], array_keys($persons));
        foreach ($persons as $person) {
            self::assertSame([], $person['warnings']);
        }
        // Listopad 2026: 21 pracovních dnů včetně svátku 17. 11.; placené volno jen 4 h.
        self::assertSameHours([
            'fund_hours' => '168.00',
            'worked_hours' => '142.50',
            'overtime_hours' => '2.50',
            'night_hours' => '16.00',
            'vacation_hours' => '16.00',
            'holiday_hours' => '8.00',
            'obstacle_employee_hours' => '4.00',
        ], self::hours($persons['eva ukazkova']));
        // Stravenkový paušál 19 × 150 Kč: osvobozeno 19 × limit za směnu, zbytek zdanitelný.
        self::assertSame(
            ['ODMENA' => 150_000, 'PRISPEVEK_STRAVOVANI_PREVZATY' => 246_050, 'PRISPEVEK_STRAVOVANI_ZDANITELNY' => 38_950],
            self::money($persons['eva ukazkova']),
        );
        self::assertSameHours([
            'fund_hours' => '168.00',
            'worked_hours' => '108.00',
            'overtime_hours' => '4.00',
            'weekend_hours' => '4.00',
            'holiday_hours' => '8.00',
            'sick_hours' => '40.00',
            'care_hours' => '16.00',
        ], self::hours($persons['adam prikladny']));
        self::assertSame([AttendancePamicaExport::PERSONAL_RATING => 200_000], self::money($persons['adam prikladny']));
        // Nadúvazek je odpracovaná doba, úkolová mzda 120 jednic × 12,50 Kč.
        self::assertSameHours(['fund_hours' => '36.00', 'worked_hours' => '38.00'], self::hours($persons['jana vzorova']));
        self::assertSame(['MZDA_UKOLOVA' => 150_000], self::money($persons['jana vzorova']));
        self::assertSame('Dohoda o provedení práce', $persons['jana vzorova']['relation_label']);
    }

    /**
     * Prvky schématu navíc: nemoc s kódem N01 ze schématu, zkrácený první
     * a poslední den, `dochazka_kod`, přesčas a nadúvazek v obalu `slozka`,
     * příplatky, korunové složky a co se převést nedá (varování).
     */
    public function testXmlSchemaElementsAreConverted(): void
    {
        $xml = self::xml(
            '<nepritomnost><kod>N01</kod><od nepritomnost="03:00">2026-03-02</od><do nepritomnost="02:00">2026-03-04</do></nepritomnost>'
            . '<nepritomnost><dochazka_kod>X9</dochazka_kod><dochazka_nazev>Vlastní konto</dochazka_nazev><od>2026-03-05</od><do>2026-03-05</do></nepritomnost>'
            . '<nepritomnost><kod>V12</kod><od>2026-03-06</od><do>2026-03-06</do><stredisko>VYR</stredisko></nepritomnost>',
            '<slozka><naduvazek_pracovni_den><hodiny>1:00</hodiny></naduvazek_pracovni_den></slozka>'
            . '<prescas_pracovni_den><hodiny>2:00</hodiny></prescas_pracovni_den>',
            '<mzda><hodiny>10:00</hodiny><castka>250</castka></mzda>'
            . '<premie><castka>400.00</castka><jednic>3</jednic></premie>'
            . '<priplatek><kod>P07</kod><hodiny>6:30</hodiny></priplatek>'
            . '<priplatek><kod>P03</kod><hodiny>8:00</hodiny></priplatek>'
            . '<priplatek><kod>P08</kod><hodiny>10:00</hodiny><castka>15.50</castka></priplatek>'
            . '<priplatek><kod>P05</kod><hodiny>4:00</hodiny></priplatek>'
            . '<slozka><stravenkovy_pausal_bez_limitu><jednic>2</jednic><castka>100</castka></stravenkovy_pausal_bez_limitu></slozka>'
            . '<osvobozene_prijmy><castka>500</castka></osvobozene_prijmy>',
        );
        $petr = $this->persons([$this->file('dochazka.xml', $xml)])['petr zkusebni'];
        // Nemoc 3 + 8 + 2 h; konto docházky jen jako ostatní (8 h); pracovní cesta je práce.
        self::assertSameHours([
            'fund_hours' => '40.00',
            'worked_hours' => '22.00',
            'overtime_hours' => '2.00',
            'night_hours' => '6.50',
            'holiday_work_hours' => '8.00',
            'business_trip_hours' => '8.00',
            'sick_hours' => '13.00',
        ], self::hours($petr));
        self::assertSame(
            ['ODMENA' => 120_000, 'PRIPLATEK_ZTIZENE_PROSTREDI' => 15_500, 'PRISPEVEK_STRAVOVANI_ZDANITELNY' => 20_000],
            self::money($petr),
        );
        self::assertSame('VYR', $petr['cost_center']);
        $warnings = implode(' ', (new AttendanceWorkbookReader())->read('dochazka.xml', 0, 'xml', $xml)[0]->warnings);
        self::assertStringContainsString('X9 (Vlastní konto)', $warnings);
        self::assertStringContainsString('mzda', $warnings);
        self::assertStringContainsString('P05', $warnings);
        self::assertStringContainsString('osvobozený příjem', $warnings);
    }

    /** Schéma dovoluje i jediného zaměstnance bez obalového seznamu. */
    public function testSingleEmployeeRootIsAccepted(): void
    {
        $xml = (string) preg_replace('~^.*?<dochazky_zamestnancu[^>]*>(.*)</dochazky_zamestnancu>$~s', '$1', self::xml());
        $xml = str_replace('<dochazka_zamestnance>', '<dochazka_zamestnance xmlns="' . AttendancePamicaExport::NAMESPACE . '">', $xml);
        self::assertSame(['petr zkusebni'], array_keys($this->persons([$this->file('jeden.xml', $xml)])));
    }

    public function testInvalidXmlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AttendanceWorkbookReader())->read('jiny.xml', 0, 'xml', '<?xml version="1.0"?><faktura/>');
    }

    public function testDoctypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AttendanceWorkbookReader())->read('zly.xml', 0, 'xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "x">]><dochazky_zamestnancu/>');
    }

    private static function xml(?string $absences = null, ?string $presence = null, string $wages = ''): string
    {
        $days = '';
        for ($day = 1; $day <= 31; ++$day) {
            $date = sprintf('2026-03-%02d', $day);
            // Rozvrh jen 2.–6. 3. (pondělí až pátek), jinak 0.
            $hours = $day >= 2 && $day <= 6 ? '08:00' : '00:00';
            $days .= "<uvazek datum=\"{$date}\">{$hours}</uvazek>";
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<dochazky_zamestnancu version="1.0" xmlns="' . AttendancePamicaExport::NAMESPACE . '">'
            . '<dochazka_zamestnance><hlavicka><mesic>3</mesic><rok>2026</rok><jmeno>Petr</jmeno><prijmeni>Zkušební</prijmeni>'
            . '<pracovni_pomer>Pracovní poměr</pracovni_pomer><cislo_pracovniho_pomeru>70001</cislo_pracovniho_pomeru></hlavicka>'
            . "<rozvrh>{$days}</rozvrh>"
            . '<nepritomnosti>'
            . ($absences ?? '<nepritomnost><kod>V01</kod><od>2026-03-02</od><do>2026-03-03</do></nepritomnost>'
                . '<nepritomnost><kod>H01</kod><od>2026-02-27</od><do>2026-03-04</do></nepritomnost>'
                . '<nepritomnost><kod>V02</kod><od>2026-03-05</od><do>2026-03-05</do></nepritomnost>')
            . '</nepritomnosti>'
            . '<pritomnost>'
            . ($presence ?? '<prescas_pracovni_den><hodiny>01:30</hodiny><interni_poznamka /></prescas_pracovni_den>'
                . '<prescas_den_klidu><hodiny>02:00</hodiny><interni_poznamka /></prescas_den_klidu>')
            . '</pritomnost>'
            . "<mzdy>{$wages}</mzdy></dochazka_zamestnance></dochazky_zamestnancu>";
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,int> kód složky → haléře
     */
    private static function money(array $person): array
    {
        $money = array_column($person['components'], 'amount_minor', 'component_code');
        ksort($money);

        return $money;
    }

    /** @param array<string,string> $expected */
    private static function assertSameHours(array $expected, array $actual): void
    {
        ksort($expected);
        self::assertSame($expected, $actual);
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,string> význam → hodiny, seřazené podle významu
     */
    private static function hours(array $person): array
    {
        $hours = array_column($person['metrics'], 'hours', 'meaning');
        ksort($hours);

        return $hours;
    }

    /** @return array{name:string,extension:string,content:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)), 'content' => $content];
    }

    /**
     * @param list<array{name:string,extension:string,content:string}> $files
     * @return array<string,array<string,mixed>>
     */
    private function persons(array $files): array
    {
        $reader = new AttendanceWorkbookReader();
        $sheets = [];
        foreach ($files as $index => $file) {
            array_push($sheets, ...$reader->read($file['name'], $index, $file['extension'], $file['content']));
        }
        $suggester = new AttendanceRuleSuggester();
        $mapper = new AttendanceColumnMapper(new AttendanceSheetAnalyzer($suggester), $suggester);
        $mapped = $mapper->map($sheets, AttendanceRules::validate(AttendancePamicaSampleProfile::rules()));
        $persons = [];
        foreach ((new AttendancePersonAggregator($mapper))->aggregate($mapped['sheets']) as $person) {
            $persons[$person['key']] = $person;
        }
        ksort($persons);

        return $persons;
    }
}
