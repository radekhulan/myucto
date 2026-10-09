<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceColumnMapper;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceProfileComponents;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRuleSuggester;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheetAnalyzer;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use PHPUnit\Framework\TestCase;

/**
 * Vzor GIRITON nad syntetickými podklady v téže struktuře jako skutečné
 * exporty: datum exportu místo hlavičky nad jmény, výpočetní list s peněžními
 * sloupci, součty, sazbami v hlavičce, kopiemi jmen a opakovanou hlavičkou,
 * pomocný list s dalšími jmény, hlavní seznam osob a CSV mezd.
 */
final class AttendanceSampleProfileTest extends TestCase
{
    private const DURATION = '[h]:mm';

    public function testSampleRulesAndComponentsAreValid(): void
    {
        self::assertSame(AttendanceSampleProfile::rules(), AttendanceRules::validate(AttendanceSampleProfile::rules()));
        self::assertCount(
            count(AttendanceSampleProfile::components()),
            AttendanceProfileComponents::validate(AttendanceSampleProfile::components()),
        );
    }

    /**
     * Složka vzoru musí mít zařazení pro JMHZ: bez něj nejde zmrazit měsíční
     * hlášení a účetní by ho musela doplňovat ručně. Buď je složka deklarovaná
     * ve vzoru (druh určí zařazení), nebo je ve výchozím číselníku složek.
     *
     * Výjimkou jsou odměny (druh `bonus`): zda je odměna pravidelná (10330),
     * nebo nepravidelná (10331), vzor ani import neví (Pokyny MH 1.4.14
     * kap. 3.5.1) a rozhodne účetní jednou za firmu.
     */
    public function testEveryComponentOfTheSampleHasJmhzTarget(): void
    {
        /*
         * Žádná jiná složka vzoru nesmí zůstat bez zařazení: chodí z importu každý
         * měsíc a bez zařazení nejde zmrazit měsíční hlášení. Zdanitelná část
         * stravování má proto vlastní složku číselníku se sběrným uzlem 10328, ne
         * obecný nepeněžní příjem, u kterého zařazení rozhoduje účetní.
         */
        $declared = array_column(AttendanceSampleProfile::components(), null, 'code');
        $bonuses = [];
        foreach (AttendanceSampleProfile::rules() as $rule) {
            $code = $rule['component_code'] ?? null;
            if ($rule['meaning'] !== 'component' || $code === null || $code === AttendanceRules::AUTO_COMPONENT) {
                continue;
            }
            $target = isset($declared[$code])
                ? PayrollComponentJmhzMappingDefaults::targetFor(
                    $code,
                    (string) $declared[$code]['kind'],
                    'one_off',
                    'included',
                )
                : PayrollComponentJmhzMappingDefaults::targetForCode($code);
            if (($declared[$code]['kind'] ?? null) === 'bonus') {
                self::assertNull($target, "Odměna {$code} nesmí mít výchozí zařazení.");
                $bonuses[] = $code;
                continue;
            }
            self::assertNotNull($target, "Složka {$code} nemá zařazení pro JMHZ.");
        }
        self::assertContains('ODMENA_KONTEJNERY', $bonuses);
        // Sloupce, které bývají prázdné, ale s částkou by jinak vyrobily nezařazenou složku.
        foreach (['ODMENA_SENIOR' => null, 'DOPLATEK_MZDY' => '10329', 'MZDA_SKOLENI' => '10329'] as $code => $expected) {
            self::assertArrayHasKey($code, $declared, "Složka {$code} není ve vzoru deklarovaná.");
            self::assertSame($expected, PayrollComponentJmhzMappingDefaults::targetFor(
                $code,
                (string) $declared[$code]['kind'],
                'one_off',
                'included',
            ));
        }
    }

    public function testSampleMapsTheWholeStructureWithoutConflictsOrExtraPersons(): void
    {
        $result = $this->pipeline($this->files(), AttendanceSampleProfile::rules());

        $used = [];
        foreach ($result['sheets'] as $sheet) {
            if ($sheet['person_column'] !== null && $sheet['data_rows'] > 0) {
                $used[] = $sheet['sheet']->id();
            }
        }
        self::assertSame(['podklady.xlsx#Mzdy 06-26', 'provoz.xlsx#data', 'provoz.xlsx#výpočet', 'mzdy.csv#CSV'], $used);

        $persons = [];
        foreach ($result['persons'] as $person) {
            $persons[$person['key']] = $person;
            self::assertSame([], $person['warnings'], $person['display_name']);
        }
        ksort($persons);
        // Pomocný list „Produktivita" má vlastní jména — osoby z něj nevznikají.
        self::assertSame(['jana novakova', 'karel technik', 'petr svoboda'], array_keys($persons));

        $jana = $persons['jana novakova'];
        $metrics = array_column($jana['metrics'], null, 'meaning');
        self::assertSame('160.50', $metrics['worked_hours']['hours']);
        self::assertStringContainsString('výpočet', $metrics['worked_hours']['source']);
        self::assertSame('16.00', $metrics['vacation_hours']['hours']);
        self::assertStringContainsString('výpočet', $metrics['vacation_hours']['source']);
        self::assertSame('30.00', $metrics['afternoon_hours']['hours']);
        self::assertSame('36.50', $metrics['home_office_hours']['hours']);
        self::assertSame('Z001', $jana['personal_number']);
        self::assertNull($jana['monthly_wage']);
        self::assertSame('42000', $persons['petr svoboda']['monthly_wage']);
        self::assertSame(4_500_000, $jana['reference']['gross_minor']);

        $components = array_column($jana['components'], 'amount_minor', 'component_code');
        ksort($components);
        self::assertSame([
            'DOCH_PREMIE_ZA_BALENI' => 70_000,
            'MZDA_HODINOVA_DOCH' => 2_352_000,
            'MZDA_UKOLOVA' => 1_292_900,
            'PRIPLATEK_BOZP' => 170_000,
            // „Suma hodinovky NOC" je příplatek za noční práci, ne druhá hodinová mzda.
            'PRIPLATEK_NOCNI' => 172_900,
            'PRIPLATKY_K_HODINOVE' => 673_800,
            // Zdanitelná část stravování: nepeněžní příjem do hrubé mzdy.
            'STRAVOVANI_ZDANITELNE' => 61_000,
        ], $components);
        // Obědy placené zaměstnancem jsou srážka z čisté mzdy, ne mzdová složka.
        self::assertSame(['net_meal_deduction' => 29_000], array_column($jana['deductions'], 'amount_minor', 'meaning'));

        // Mimo výrobu je ve sloupci odměn odměna, srážky jdou z čisté mzdy.
        $petr = $persons['petr svoboda'];
        self::assertSame(200_000, array_column($petr['components'], 'amount_minor', 'component_code')['ODMENA']);
        self::assertSame(['net_other_deduction' => 50_000], array_column($petr['deductions'], 'amount_minor', 'meaning'));

        // Ve výrobě nese sloupec odměn celou úkolovou mzdu technika.
        $karel = $persons['karel technik'];
        $karelComponents = array_column($karel['components'], 'amount_minor', 'component_code');
        ksort($karelComponents);
        self::assertSame(['MZDA_UKOLOVA' => 7_000_000, 'PRIPLATEK_BOZP' => 200_000], $karelComponents);
        self::assertSame('2026-06-03', $karel['end_on']);
        self::assertNull($karel['start_on']);

        // Kopie jmen s nulami, součty, stropy sazeb ani kontrolní sloupce složku nevyrobí.
        self::assertSame(['DOCH_PREMIE_ZA_BALENI' => 'Prémie za balení'], $result['auto_components']);
        foreach ($persons as $person) {
            foreach ($person['components'] as $component) {
                self::assertStringNotContainsStringIgnoringCase('MAX', (string) $component['component_code']);
                self::assertStringNotContainsStringIgnoringCase('KONTROLA', (string) $component['component_code']);
            }
        }
    }

    public function testPersonColumnWithoutLabelGetsPlaceholderEvenWhenNamesCarryNumbers(): void
    {
        $sheets = (new AttendanceWorkbookReader())->read('export.xlsx', 0, 'xlsx', AttendanceFixture::xlsx([
            'data' => [
                'rows' => [
                    1 => ['A' => 46203, 'B' => 'Práce (celkem)', 'C' => 'Dovolená'],
                    2 => ['A' => 'Zaměstnanec Z0042', 'B' => 0.5, 'C' => 0.25],
                    3 => ['A' => 'Novák Jan (Z0990)', 'B' => 0.5, 'C' => 0.0],
                ],
                'formats' => ['A1' => 'd.m.yyyy', 'B2:C3' => self::DURATION],
            ],
        ]));
        $layout = (new AttendanceSheetAnalyzer(new AttendanceRuleSuggester()))->analyze($sheets[0]);

        self::assertSame(1, $layout->suggestedPersonColumn);
        self::assertSame(AttendanceSheetAnalyzer::PERSON_PLACEHOLDER, $layout->headers[1]);
    }

    public function testPersonNameDetection(): void
    {
        self::assertTrue(AttendanceText::looksLikePersonName('Jana Nováková'));
        self::assertTrue(AttendanceText::looksLikePersonName('Zaměstnanec Z0042'));
        self::assertTrue(AttendanceText::looksLikePersonName('Novák Jan (Z0990)'));
        self::assertFalse(AttendanceText::looksLikePersonName('Linka 2'));
        self::assertFalse(AttendanceText::looksLikePersonName('Porucha A10 B20'));
        self::assertFalse(AttendanceText::looksLikePersonName('12:30'));
        self::assertFalse(AttendanceText::looksLikePersonName('Celkem'));
    }

    public function testWildcardRulesMatchNormalizedText(): void
    {
        self::assertTrue(AttendanceRules::like('výpočet*', 'vypocet'));
        self::assertTrue(AttendanceRules::like('mzdy*', 'mzdy 07-26'));
        self::assertTrue(AttendanceRules::like('suma hodinovky noc*', 'suma hodinovky noc vc.prescasu a bp'));
        self::assertFalse(AttendanceRules::like('suma hodinovky noc*', 'suma hodinovky vc. prescasu'));
        self::assertTrue(AttendanceRules::like('přesčas*25*', 'prescas den x 25%'));
        self::assertTrue(AttendanceRules::like('úkol', 'ukol'));
        self::assertFalse(AttendanceRules::like('úkol', 'mzda ukol'));
    }

    /** @return list<array{name:string,content:string,sha256:string,extension:string}> */
    private function files(): array
    {
        $day = 1 / 24;
        $export = AttendanceFixture::xlsx([
            'data' => [
                'rows' => [
                    1 => [
                        'A' => 46203, 'B' => 'mzda paušál', 'D' => 'Práce (celkem)', 'E' => 'Činnost A +10 Kč',
                        'F' => 'Dovolená', 'G' => 'Práce odpolední', 'H' => 'Home Office',
                    ],
                    2 => ['A' => 'Jana Nováková', 'D' => 160 * $day, 'E' => 40 * $day, 'F' => 15 * $day, 'G' => 30 * $day, 'H' => 36.5 * $day],
                    3 => ['A' => 'Petr Svoboda', 'D' => 150 * $day, 'F' => 0.0, 'G' => 0.0],
                ],
                'formats' => ['A1' => 'd.m.yyyy', 'D2:H3' => self::DURATION],
            ],
            'výpočet' => [
                'rows' => [
                    1 => [
                        'A' => 46203, 'B' => 'Fond prac. doby', 'C' => 'úkol',
                        'D' => 'Odpracováno celkem včetně přesčasů', 'E' => 'Dovolená', 'F' => 'mzda úkol',
                        'G' => 'Suma hodinovky vč. přesčasů', 'H' => 'Suma hodinovky NOC', 'I' => 'Suma hodinovky NOC',
                        'J' => 'Prémie za balení', 'K' => 'Součet mzdy', 'L' => 'paušál', 'M' => '140',
                        'N' => 'Příplatky k hodinové mzdě',
                        // Strop sazby pro výpočet v sešitu, ne částka k výplatě.
                        'O' => 'max příplatek za přesčas +100', 'P' => 'Kontrola příplatků VÝPOČET',
                    ],
                    2 => [
                        'A' => 'Jana Nováková', 'B' => 176, 'C' => 32.6, 'D' => 160.5, 'E' => 16, 'F' => 12929,
                        'G' => 23520, 'H' => 1729, 'I' => 999, 'J' => 700, 'K' => 25249, 'L' => 'Petr Svoboda',
                        'M' => 5 * $day, 'N' => 6738, 'O' => 900, 'P' => 1500,
                    ],
                    3 => [
                        'A' => 'Petr Svoboda', 'B' => 176, 'C' => 0, 'D' => 150, 'E' => 0, 'F' => 0,
                        'G' => 21000, 'H' => 0, 'I' => 0, 'J' => 0, 'K' => 21000, 'L' => 0, 'M' => 0, 'N' => 0,
                        'O' => 0, 'P' => 0,
                    ],
                    // Technik: úkol je jen v hlavním seznamu, výpočet ho nemá (prázdná buňka, ne nula).
                    4 => ['A' => 'Karel Technik', 'B' => 176, 'D' => 20],
                ],
                'formats' => ['A1' => 'd.m.yyyy', 'M2:M3' => self::DURATION],
            ],
            'Produktivita' => [
                'rows' => [
                    1 => ['A' => 'pořadí', 'B' => 'Jméno', 'C' => 'Fond'],
                    2 => ['A' => 1, 'B' => 'Jana Nováková', 'C' => 176],
                    3 => ['A' => 2, 'B' => 'Eva Pomocná', 'C' => 176],
                ],
            ],
        ]);
        $main = AttendanceFixture::xlsx([
            'Mzdy 06-26' => [
                'rows' => [
                    1 => ['A' => 2026, 'I' => 'obědy'],
                    2 => [
                        'A' => 'jméno a příjmení', 'B' => 'oddělení', 'C' => 'středisko', 'D' => 'týdenní fond',
                        'E' => 'název pozice', 'F' => 'MV', 'G' => 'příplatek BOZP', 'H' => 'nový nástup/ukončení',
                        'I' => 'Součet z výpočtu obědů', 'J' => 'Srážky', 'K' => 'Odměny/bonus/příspěvky',
                        'L' => 'Součet z Dotovaná cena / por.',
                    ],
                    3 => ['A' => 'Jana Nováková', 'B' => 'výroba', 'C' => 'VÝROBA', 'D' => 40, 'E' => 'Operátorka', 'G' => 1700, 'I' => 610, 'L' => 290],
                    4 => ['A' => 'Petr Svoboda', 'B' => 'sklad', 'C' => 'SKLAD', 'D' => 40, 'E' => 'Skladník', 'F' => 42000, 'G' => 0, 'J' => 500, 'K' => 2000],
                    5 => [
                        'A' => 'Karel Technik', 'B' => 'výroba', 'C' => 'VÝROBA', 'D' => 37.5, 'E' => 'Technik',
                        'G' => 2000, 'H' => 'ukončení k 3.6.2026', 'K' => 70000,
                    ],
                ],
            ],
        ]);
        $csv = "X,Měsíc,Rok,Zaměstnanec,Rodné číslo,Osobní číslo,Pracpoměr,Odprachod,Hrubá mzda,Čistá mzda\n"
            . "NEPRAVDA,červen,2026,Jana Nováková," . AttendanceFixture::janaBirthNumber()
            . ",Z001,Pracovní poměr,\"160,5\",\"45 000,00 Kč\",\"35 000,00 Kč\"\n"
            . "NEPRAVDA,červen,2026,Petr Svoboda," . AttendanceFixture::petrBirthNumber()
            . ",Z002,Pracovní poměr,150,\"42 000,00 Kč\",\"33 000,00 Kč\"\n";

        return [
            AttendanceFixture::file('podklady.xlsx', $main),
            AttendanceFixture::file('provoz.xlsx', $export),
            AttendanceFixture::file('mzdy.csv', $csv),
        ];
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @param list<array<string,mixed>> $rules
     * @return array<string,mixed>
     */
    private function pipeline(array $files, array $rules): array
    {
        $reader = new AttendanceWorkbookReader();
        $sheets = [];
        foreach ($files as $index => $file) {
            array_push($sheets, ...$reader->read($file['name'], $index, $file['extension'], $file['content']));
        }
        $suggester = new AttendanceRuleSuggester();
        $mapper = new AttendanceColumnMapper(new AttendanceSheetAnalyzer($suggester), $suggester);
        /** @var list<array{sheet:?string,header:string,meaning:string,unit:?string,component_code:?string}> $rules */
        $mapped = $mapper->map($sheets, AttendanceRules::validate($rules));

        return $mapped + ['persons' => (new AttendancePersonAggregator($mapper))->aggregate($mapped['sheets'])];
    }
}
