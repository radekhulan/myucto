<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceColumnMapper;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRuleSuggester;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheetAnalyzer;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Nepřítomnost, kterou evidence vede jedině s daty od a do, se z PAMICA přebírá datovaně
 * z `MZneprit` a tytéž hodiny nejdou zároveň měsíčním souhrnem importu docházky.
 *
 * Bez toho zůstal měsíc s nemocí neschválený: souhrn s `sick_hours` odmítá
 * {@see \MyInvoice\Service\Payroll\Time\PayrollTimeImportApprovalService} s kódem
 * `absence_hours_without_dates`, protože z měsíčního součtu hodin nejde spočítat náhradu
 * mzdy ani vyloučenou dobu. Dovolená a překážky data nevyžadují a zůstávají v souhrnu.
 *
 * Syntetická data, žádné reálné doklady ani osoby.
 */
final class PohodaPayrollDatedAbsenceTest extends TestCase
{
    private string $tmp = '';
    private string $file = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_absence_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/12345678_2026', 0755, true);
        $this->file = $this->tmp . '/12345678_2026/91_mzdy.xml';
        file_put_contents($this->file, self::payrollXml());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /**
     * Měsíc s nemocí, ke které má PAMICA data: hodiny nemoci do sešitu nejdou, takže
     * souhrn importu už schválení měsíce neblokuje. Dovolená v témže měsíci zůstává
     * v sešitu beze změny.
     */
    public function testDatedSicknessLeavesTheMonthlySheetSoTheMonthCanBeApproved(): void
    {
        $month = PohodaPayrollConverter::read($this->file)->month('2026-03');
        $row = $month['rows'][0];

        self::assertArrayNotHasKey('Nemoc (h)', $row, 'Nemoc s daty patří do evidence nepřítomností, ne do souhrnu.');
        self::assertArrayNotHasKey('Nemoc (h)', $month['columns']);
        self::assertSame(8.0, $row['Dovolená (h)'], 'Dovolená data nevyžaduje a chování se u ní nemění.');
        self::assertSame('vacation_hours', $month['columns']['Dovolená (h)']['meaning']);

        self::assertSame([], $this->hoursRequiringDates($month), 'Souhrn měsíce už schválení neodmítne.');
    }

    /**
     * Nepřítomnost, kterou PAMICA nese bez data, zapsat nejde - hodiny proto v souhrnu
     * zůstanou, aby se neztratily, a měsíc si vyžádá ruční dořešení.
     */
    public function testUndatedSicknessStaysInTheMonthlySheet(): void
    {
        $month = PohodaPayrollConverter::read($this->file)->month('2026-04');

        self::assertSame(16.0, $month['rows'][0]['Nemoc (h)']);
        self::assertSame(['sick_hours'], $this->hoursRequiringDates($month));
    }

    /**
     * Druh, který má v měsíci i řádek bez data, zůstane v souhrnu CELÝ. Vypustit jen
     * datovanou část nejde: zbytek by druh v souhrnu podržel, převod by kvůli tomu
     * nezapsal ani datovanou část a ta doba by zmizela z obou stran.
     */
    public function testPartlyDatedAbsenceKeepsAllItsHoursInTheMonthlySheet(): void
    {
        $month = PohodaPayrollConverter::read($this->file)->month('2026-05');

        self::assertSame(24.0, $month['rows'][0]['OČR (h)'], 'Obě ošetřovné, datované i bez data.');
        self::assertSame(['care_hours'], $this->hoursRequiringDates($month));
    }

    /** Datovaná nepřítomnost se čte z `MZneprit` s datem od a do; bez data se počítá k dořešení. */
    public function testPeopleReadTakesDatesFromMzneprit(): void
    {
        $records = PohodaPayrollPeople::read($this->file, 2026);
        self::assertCount(1, $records);
        $record = $records[0];

        $absences = array_map(
            static fn (array $a): array => ['type' => $a['type'], 'from' => $a['from'], 'to' => $a['to']],
            $record['absences'],
        );
        self::assertContains(['type' => 'dpn', 'from' => '2026-03-05', 'to' => '2026-03-13'], $absences);
        self::assertContains(['type' => 'vacation', 'from' => '2026-03-02', 'to' => '2026-03-02'], $absences);
        self::assertContains(['type' => 'ocr', 'from' => '2026-05-04', 'to' => '2026-05-05'], $absences);
        self::assertContains(['type' => 'ocr', 'from' => '2026-04-20', 'to' => '2026-04-21'], $absences);
        self::assertCount(4, $absences);

        // Nemoc bez data (duben) a jedno ošetřovné bez data (květen).
        self::assertSame(2, $record['absences_without_dates']);
    }

    /**
     * Ošetřovné osamělého pracovníka (H06, N06) má podpůrčí dobu 16 dnů místo 9 a ELDP
     * z ní odvozuje vyloučené dny. Převod dřív příznak zahodil a list vyšel s jinými
     * vyloučenými dny než u předchozího programu.
     */
    public function testLoneCarerCareKeepsTheFlag(): void
    {
        $flags = [];
        foreach (PohodaPayrollPeople::read($this->file, 2026)[0]['absences'] as $absence) {
            $flags[$absence['type'] . ' ' . $absence['from']] = $absence['lone_carer'] ?? null;
        }

        self::assertTrue($flags['ocr 2026-04-20']);
        self::assertFalse($flags['ocr 2026-05-04']);
        self::assertFalse($flags['dpn 2026-03-05']);
    }

    /** Nulové datum Accessu i doba mimo převáděný rok znamenají „nepoužitelné datum". */
    public function testAbsenceDatesRejectsZeroDatesAndTimeOutsideTheYear(): void
    {
        self::assertSame(
            ['from' => '2026-01-01', 'to' => '2026-01-20'],
            PohodaPayrollPeople::absenceDates(['DatZac' => '2025-12-18', 'DatKon' => '2026-01-20'], 2026),
        );
        self::assertNull(PohodaPayrollPeople::absenceDates(['DatZac' => '1899-12-30', 'DatKon' => '1899-12-30'], 2026));
        self::assertNull(PohodaPayrollPeople::absenceDates(['DatZac' => '2026-03-10', 'DatKon' => '2026-03-01'], 2026));
        self::assertNull(PohodaPayrollPeople::absenceDates(['DatZac' => '2025-11-01', 'DatKon' => '2025-12-20'], 2026));
    }

    /**
     * Významy s kladnými hodinami, ke kterým schválení měsíce vyžaduje data - měřeno na
     * souhrnu, který z vygenerovaného sešitu opravdu vznikne (parser importu docházky).
     *
     * @param array{period:string,columns:array<string,mixed>,rows:list<array<string,mixed>>} $month
     * @return list<string>
     */
    private function hoursRequiringDates(array $month): array
    {
        $file = PohodaPayrollConverter::workbook($month);
        $profile = PohodaPayrollConverter::profile([$month]);
        $sheets = (new AttendanceWorkbookReader())->read($file['name'], 0, 'xlsx', $file['content']);
        $suggester = new AttendanceRuleSuggester();
        $mapper = new AttendanceColumnMapper(new AttendanceSheetAnalyzer($suggester), $suggester);
        $mapped = $mapper->map($sheets, AttendanceRules::validate($profile['rules']));
        $persons = (new AttendancePersonAggregator($mapper))->aggregate($mapped['sheets']);

        // Souhrn se zapisuje ze stejné množiny významů jako v `PayrollTimeImportSummaryWriter`.
        $values = [];
        foreach ($persons[0]['_metrics'] ?? [] as $meaning => $metric) {
            if (in_array((string) $meaning, AttendanceMeaning::HOURS, true)) {
                $values[(string) $meaning] = (int) $metric['millihours'];
            }
        }

        return PayrollJmhzWorkMonthSummaryBuilder::importHoursRequiringDates($values);
    }

    /**
     * Jedna fiktivní osoba s měsíční mzdou a čtyřmi nepřítomnostmi: březen dovolená
     * a nemoc s daty, duben nemoc bez data, květen ošetřovné s datem i bez data.
     */
    private static function payrollXml(): string
    {
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $row('sMZneprit', ['ID' => 1, 'Cislo' => 'V01', 'Nazev' => 'Dovolená']);
        $row('sMZneprit', ['ID' => 2, 'Cislo' => 'H01', 'Nazev' => 'Náhrada za nemoc']);
        $row('sMZneprit', ['ID' => 3, 'Cislo' => 'H05', 'Nazev' => 'Ošetřování člena rodiny']);
        $row('sMZneprit', ['ID' => 4, 'Cislo' => 'H06', 'Nazev' => 'Ošetřovné - osamělý pracovník']);
        $row('sMZslozky', ['ID' => 1, 'Cislo' => 'M01', 'Nazev' => 'Základní mzda měsíční']);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '4001', 'Jmeno' => 'Hana', 'Prijmeni' => 'Nemocná', 'DatNar' => '1988-02-03',
            'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1]);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'JeDPP' => 0, 'DatNast' => '2024-01-01', 'TUvazek' => 40]);
        foreach ([3 => 30, 4 => 40, 5 => 50] as $month => $mzId) {
            $row('MZ', ['ID' => $mzId, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => $month, 'HodFond' => 168,
                'DnyFond2' => 21, 'TUvazek' => 40, 'HodOdpra' => 120, 'RefPoj' => 1, 'KcHrubaM' => 35000, 'KcCistaM' => 27000,
                'KcZaklM' => 35000, 'DnyPrac' => 21, 'DnyOdpra' => 21]);
            $row('MZslozky', ['ID' => $mzId, 'RefAg' => $mzId, 'RefSlozka' => 1, 'KcMzda' => 35000, 'Hodnota1' => 35000]);
        }
        // Březen: dovolená i nemoc s daty od a do.
        $row('MZneprit', ['ID' => 1, 'RefAg' => 30, 'RefSlozka' => 1, 'HodPrac' => 8, 'DatZac' => '2026-03-02', 'DatKon' => '2026-03-02']);
        $row('MZneprit', ['ID' => 2, 'RefAg' => 30, 'RefSlozka' => 2, 'HodPrac' => 40, 'DatZac' => '2026-03-05', 'DatKon' => '2026-03-13']);
        // Duben: nemoc s nulovým datem Accessu, tedy bez použitelného data.
        $row('MZneprit', ['ID' => 3, 'RefAg' => 40, 'RefSlozka' => 2, 'HodPrac' => 16, 'DatZac' => '1899-12-30', 'DatKon' => '1899-12-30']);
        // Duben: ošetřovné osamělého pracovníka s daty.
        $row('MZneprit', ['ID' => 6, 'RefAg' => 40, 'RefSlozka' => 4, 'HodPrac' => 16, 'DatZac' => '2026-04-20', 'DatKon' => '2026-04-21']);
        // Květen: jedno ošetřovné s daty, druhé bez nich.
        $row('MZneprit', ['ID' => 4, 'RefAg' => 50, 'RefSlozka' => 3, 'HodPrac' => 16, 'DatZac' => '2026-05-04', 'DatKon' => '2026-05-05']);
        $row('MZneprit', ['ID' => 5, 'RefAg' => 50, 'RefSlozka' => 3, 'HodPrac' => 8, 'DatZac' => '1899-12-30', 'DatKon' => '1899-12-30']);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="POHODA" state="ok">' . $x . '</mdbExport>';
    }
}
