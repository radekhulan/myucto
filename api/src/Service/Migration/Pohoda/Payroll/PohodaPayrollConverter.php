<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Mzdy z datového souboru POHODA Mzdy / PAMICA (`91_mzdy.xml`, vytváří ho exportní
 * nástroj `tools/pohoda-export/Export-PohodaMdb.ps1`) jako měsíční sešity pro import
 * docházky a mezd MyÚčta ({@see \MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService}).
 *
 * Řádek sešitu = pracovní poměr v měsíci (tabulka `MZ`): identita osoby a vztahu
 * (`ZAM`, `ZAMpomer`), fond a odpracované hodiny, nepřítomnosti v hodinách (`MZneprit`),
 * mzdové složky v Kč (`MZslozky`), srážky (`MZsrazky`) a kontrolní hrubá a čistá mzda.
 * Význam sloupců nese profil importu ({@see self::profile()}); import pak jde stejnou
 * cestou jako ruční import v Mzdy → Importy.
 *
 * Druhý souběžný poměr téže osoby dostane osobní číslo s pořadím (`1001-2`).
 */
final class PohodaPayrollConverter
{
    public const SHEET = 'mzdy-pohoda';
    public const PROFILE_NAME = 'POHODA mzdy (převod)';

    /** Identita a vztah; každý měsíc má tyto sloupce ve stejném pořadí. */
    private const BASE_COLUMNS = [
        ['Zaměstnanec', 'person_name', 'text'],
        ['Osobní číslo', 'personal_number', 'text'],
        ['Rodné číslo', 'birth_number', 'text'],
        ['Datum narození', 'birth_date', 'text'],
        ['Zdravotní pojišťovna', 'health_insurer_code', 'text'],
        ['Druh vztahu', 'relation_label', 'text'],
        ['Středisko', 'cost_center', 'text'],
        ['Pracovní místo', 'position', 'text'],
        ['Týdenní úvazek', 'weekly_hours', 'text'],
        ['Měsíční mzda', 'monthly_wage', 'text'],
        ['Nástup / ukončení', 'start_end_note', 'text'],
        ['Příjmení', 'ignore', null],
        ['Jméno', 'ignore', null],
        ['Fond pracovní doby (h)', 'fund_hours', 'hours'],
        ['Odpracováno (h)', 'worked_hours', 'hours'],
    ];

    private const HOLIDAY_HEADER = 'Svátek (h)';

    /** @var array<string,array<string,array<string,mixed>>> tabulka => ID => řádek (číselníky, osoby, vztahy) */
    private array $byId = [];
    /** Sloupce `MZ`, které čte skládání sešitu ({@see self::month()}, {@see self::holidayHours()}). */
    private const MZ_COLUMNS = ['ID', 'Rok', 'RelMes', 'RefZAM', 'RefPomer', 'RefPoj', 'KcPrum', 'KcPrumU', 'TUvazek',
        'DnyFond2', 'HodFond', 'HodOdpra', 'DnyStSv', 'KcHrubaM', 'KcCistaM'];

    /** Sloupce položek mezd, které čte skládání sešitu. */
    private const ITEM_COLUMNS = [
        'MZslozky' => ['RefAg', 'RefSlozka', 'KcMzda', 'PocHodin', 'Hodnota1', 'Hodnota4'],
        'MZneprit' => ['RefAg', 'RefSlozka', 'HodPrac', 'KcNahr', 'DatZac', 'DatKon', 'DatPorod'],
        'MZsrazky' => ['RefAg', 'RefSlozka', 'KcSrazeno'],
        'MZdoch' => ['RefAg', 'Hodin1', 'Hodin2', 'Hodin3', 'Hodin4', 'Hodin5', 'Hodin6', 'Hodin7', 'Hodin8', 'Hodin9', 'Hodin10',
            'Hodin11', 'Hodin12', 'Hodin13', 'Hodin14', 'Hodin15', 'Hodin16', 'Hodin17', 'Hodin18', 'Hodin19', 'Hodin20',
            'Hodin21', 'Hodin22', 'Hodin23', 'Hodin24', 'Hodin25', 'Hodin26', 'Hodin27', 'Hodin28', 'Hodin29', 'Hodin30', 'Hodin31'],
    ];

    /** Rok, pro který se načetly mzdy a položky; `null` = všechny. */
    private ?int $year = null;
    /** @var array<string,list<array<string,mixed>>> období `Y-m` => řádky MZ (jen sloupce {@see self::MZ_COLUMNS}) */
    private array $mz = [];
    /** @var array<string,int> období `Y-m` => počet mezd, za všechny roky exportu */
    private array $payslips = [];
    /** @var array<string,array<string,list<array<string,mixed>>>> tabulka => ID mzdy => řádky položek */
    private array $items = [];
    /** @var array<string,int> ID osoby => počet vztahů */
    private array $relationCount = [];
    /** @var array<string,int> číslo složky => počet složek katalogu s tím číslem */
    private array $numberUse = [];

    /**
     * @param ?string $exportedOn den exportu `Y-m-d` (atribut `created` datového souboru),
     *        `null` u exportu, který ho nenese
     */
    private function __construct(public readonly string $ico, public readonly ?string $exportedOn = null) {}

    /**
     * @param ?int $year rok, jehož měsíce se budou skládat ({@see self::month()}); mzdy ostatních
     *        let se jen spočítají po obdobích (stačí na {@see self::periods()} a spol.).
     *        `null` = všechny roky
     */
    public static function read(string $file, ?int $year = null): self
    {
        if (!is_file($file)) {
            throw new PohodaException('payroll_missing', 'Export neobsahuje mzdy (91_mzdy.xml).');
        }
        $info = PohodaXml::packInfo($file);
        $self = new self(preg_replace('/\D/', '', $info['ico']) ?? '', self::exportDate($info['created'] ?? ''));
        $self->year = $year;
        $byId = ['sMZslozky', 'sMZneprit', 'sMZsrazky', 'sMzPoj', 'sSTR', 'PracMista', 'ZAM', 'ZAMpomer'];
        $items = ['MZslozky', 'MZneprit', 'MZsrazky', 'MZdoch'];
        $byIdTables = array_fill_keys($byId, true);
        $mzColumns = array_fill_keys(self::MZ_COLUMNS, true);
        $itemColumns = array_map(static fn (array $columns): array => array_fill_keys($columns, true), self::ITEM_COLUMNS);
        // Jeden průchod souborem pro všechny tabulky: každý průchod 50MB exportu stojí
        // sekundy a tabulek je dvanáct. Mzdy a jejich položky (tisíce řádků o desítkách až
        // stovkách sloupců) se drží jen se sloupci, které čte skládání sešitu.
        foreach (PohodaXml::scan($file, [...$byId, 'MZ', ...$items]) as $table => $row) {
            if (isset($byIdTables[$table])) {
                $self->byId[$table][PohodaXml::text($row, 'ID')] = $row;
            } elseif ($table === 'MZ') {
                $rowYear = (int) PohodaXml::text($row, 'Rok');
                $period = sprintf('%04d-%02d', $rowYear, (int) PohodaXml::text($row, 'RelMes'));
                $self->payslips[$period] = ($self->payslips[$period] ?? 0) + 1;
                if ($year === null || $rowYear === $year) {
                    $self->mz[$period][] = array_intersect_key($row, $mzColumns);
                }
            } else {
                $self->items[$table][PohodaXml::text($row, 'RefAg')][] = array_intersect_key($row, $itemColumns[$table]);
            }
        }
        if ($year !== null) {
            // Položky mezd ostatních let (tabulky položek rok nenesou, pozná se až podle mzdy).
            $loaded = [];
            foreach ($self->mz as $rows) {
                foreach ($rows as $mz) {
                    $loaded[PohodaXml::text($mz, 'ID')] = true;
                }
            }
            foreach ($self->items as $table => $byPayslip) {
                $self->items[$table] = array_intersect_key($byPayslip, $loaded);
            }
        }
        foreach ($self->byId['ZAMpomer'] ?? [] as $relation) {
            $person = PohodaXml::text($relation, 'RefZAM');
            $self->relationCount[$person] = ($self->relationCount[$person] ?? 0) + 1;
        }
        foreach ($self->byId['sMZslozky'] ?? [] as $row) {
            $number = strtoupper(PohodaXml::text($row, 'Cislo'));
            $self->numberUse[$number] = ($self->numberUse[$number] ?? 0) + 1;
        }
        ksort($self->mz);
        ksort($self->payslips);
        return $self;
    }

    /** @return list<string> období `Y-m`, pro která jsou v exportu mzdy (i rozpracované) */
    public function periods(?int $year = null): array
    {
        return array_values(array_filter(array_keys($this->payslips), static fn (string $p): bool => $year === null || str_starts_with($p, $year . '-')));
    }

    /**
     * Uzavřené měsíce: mzdy, které předchozí program zpracoval za měsíc, jenž
     * v den exportu už skončil. Jen ty se převádějí a jen podle nich se určuje
     * začátek vedení mezd v MyÚčtu.
     *
     * @return list<string>
     */
    public function closedPeriods(?int $year = null): array
    {
        return array_values(array_filter($this->periods($year), fn (string $p): bool => !self::openPeriod($p, $this->exportedOn)));
    }

    /**
     * Rozpracované měsíce s počtem mezd: export z 26. 9. nese i září a říjen,
     * které v předchozím programu teprve běží (typicky pár výstupních mezd).
     *
     * @return array<string,int> období `Y-m` => počet mezd
     */
    public function openPeriods(?int $year = null): array
    {
        $open = [];
        foreach ($this->periods($year) as $period) {
            if (self::openPeriod($period, $this->exportedOn)) {
                $open[$period] = $this->payslips[$period] ?? 0;
            }
        }
        return $open;
    }

    /**
     * Neskončil měsíc v den exportu? Takový měsíc předchozí program nemohl
     * uzavřít ani podat, takže ho MyÚčto nepřebírá a spočítá ho samo.
     * Bez data exportu se nic za rozpracované nepovažuje (starší exporty).
     */
    public static function openPeriod(string $period, ?string $exportedOn): bool
    {
        if ($exportedOn === null || preg_match('/^\d{4}-\d{2}$/D', $period) !== 1) {
            return false;
        }
        $lastDay = (new \DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d');
        return $lastDay >= $exportedOn;
    }

    /** Den exportu z atributu `created` (`Y-m-dTH:i:s`), nebo `null`. */
    public static function exportDate(string $created): ?string
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($created), $m) === 1 ? $m[1] : null;
    }

    public function employees(): int
    {
        return count($this->byId['ZAM'] ?? []);
    }

    /**
     * Sešit jednoho měsíce: sloupce (hlavička => význam), řádky a součty ke kontrole.
     *
     * `unclassified_deductions` nese druhy srážek, které se do sešitu nedostaly, protože
     * jim v exportu chybí řádek číselníku `sMZsrazky`; zahodit je tiše nelze (mohla by to
     * být exekuce), takže je převod předá protokolu k ručnímu dořešení.
     *
     * `unconverted_items` nese mzdové složky a nepřítomnosti, které převod nezná
     * ({@see PohodaPayrollCatalog} význam `unknown`): klíč `druh:kód` => počet vstupů
     * s částkou nebo hodinami, jejich součet a hodiny. Nepřítomnost, kterou převod
     * zapisuje s daty do evidence nepřítomností, sem patří jen tehdy, když data nemá.
     *
     * @return array{period:string, columns:array<string,array{meaning:string,unit:?string,kind:?string,code:?string}>,
     *     rows:list<array<string,string|float|null>>, totals:array<string,int>, omitted:list<string>,
     *     unclassified_deductions:array<string,array{code:string,name:string,inputs:int}>,
     *     unconverted_items:array<string,array{code:string,name:string,kind:string,inputs:int,amount:float,hours:float}>}
     */
    public function month(string $period): array
    {
        if ($this->year !== null && !str_starts_with($period, $this->year . '-')) {
            throw new \LogicException("Mzdy za {$period} se nečetly, převodník je načtený jen pro rok {$this->year}.");
        }
        $columns = [];
        $column = static function (string $header, string $meaning, ?string $unit, ?string $kind = null, ?string $code = null) use (&$columns): void {
            $columns[$header] ??= ['meaning' => $meaning, 'unit' => $unit, 'kind' => $kind, 'code' => $code];
        };
        foreach (self::BASE_COLUMNS as [$header, $meaning, $unit]) {
            $column($header, $meaning, $unit);
        }

        $rows = [];
        $omitted = [];
        /** @var array<string,array{code:string,name:string,inputs:int}> $unclassifiedDeductions */
        $unclassifiedDeductions = [];
        /** @var array<string,array{code:string,name:string,kind:string,inputs:int,amount:float,hours:float}> $unconverted */
        $unconverted = [];
        $skip = static function (string $kind, string $code, string $name, float $amount, float $hours) use (&$unconverted): void {
            if (round($amount, 2) == 0.0 && round($hours, 2) == 0.0) {
                return;
            }
            $key = $kind . ':' . ($code !== '' ? $code : '?');
            $unconverted[$key] ??= ['code' => $code, 'name' => $name, 'kind' => $kind, 'inputs' => 0, 'amount' => 0.0, 'hours' => 0.0];
            $unconverted[$key]['inputs']++;
            $unconverted[$key]['amount'] = round($unconverted[$key]['amount'] + $amount, 2);
            $unconverted[$key]['hours'] = round($unconverted[$key]['hours'] + $hours, 2);
        };
        $totals = ['rows' => 0, 'gross_minor' => 0, 'net_minor' => 0, 'components_minor' => 0, 'meal_minor' => 0, 'deduction_minor' => 0, 'worked_millihours' => 0];
        /** @var array<int,true> $obstacleRates procento průměru, se kterým PAMICA platila překážky na straně zaměstnavatele */
        $obstacleRates = [];
        foreach ($this->mz[$period] ?? [] as $mz) {
            $mzId = PohodaXml::text($mz, 'ID');
            $person = $this->byId['ZAM'][PohodaXml::text($mz, 'RefZAM')] ?? null;
            $relation = $this->byId['ZAMpomer'][PohodaXml::text($mz, 'RefPomer')] ?? null;
            if ($person === null || $relation === null) {
                throw new PohodaException('payroll_inconsistent', "Mzda za {$period} nemá v exportu zaměstnance nebo pracovní poměr.");
            }
            $values = [];
            $add = static function (string $header, float $value) use (&$values): void {
                $values[$header] = ($values[$header] ?? 0.0) + $value;
            };
            $monthlyWage = null;
            // PAMICA vede odpracované hodiny (`HodOdpra`) bez přesčasu; import MyÚčta
            // (docházka, pracovní měsíc, JMHZ) čeká odpracováno včetně přesčasu.
            $overtime = 0.0;
            foreach ($this->items['MZslozky'][$mzId] ?? [] as $item) {
                $catalog = $this->byId['sMZslozky'][PohodaXml::text($item, 'RefSlozka')] ?? null;
                $number = strtoupper(PohodaXml::text($catalog ?? [], 'Cislo'));
                $class = PohodaPayrollCatalog::component($number, PohodaXml::text($catalog ?? [], 'Nazev'), ($this->numberUse[$number] ?? 0) > 1);
                $amount = PohodaXml::num($item, 'KcMzda');
                if ($class['meaning'] === 'meal') {
                    $column($class['header'], 'net_meal_deduction', 'amount');
                    $add($class['header'], -$amount);
                    $totals['meal_minor'] += self::minor(-$amount);
                } elseif ($class['meaning'] === 'component') {
                    $column($class['header'], 'component', 'amount', $class['kind'], $class['code']);
                    $add($class['header'], $amount);
                    $totals['components_minor'] += self::minor($amount);
                } elseif ($class['meaning'] === 'meal_allowance') {
                    foreach (PohodaPayrollCatalog::mealAllowanceSplit($amount, PohodaXml::num($item, 'Hodnota4')) as $part) {
                        if (round($part['amount'], 2) == 0.0) {
                            continue;
                        }
                        $column($part['header'], 'component', 'amount', $class['kind'], $part['code']);
                        $add($part['header'], $part['amount']);
                        $totals['components_minor'] += self::minor($part['amount']);
                    }
                } elseif ($class['meaning'] === 'unknown') {
                    $skip('component', $number, PohodaXml::text($catalog ?? [], 'Nazev'), $amount, 0.0);
                }
                $hours = PohodaPayrollCatalog::workHours($number);
                if ($hours !== null) {
                    $column($hours['header'], $hours['meaning'], 'hours');
                    $add($hours['header'], PohodaXml::num($item, 'PocHodin'));
                    if ($hours['meaning'] === 'overtime_hours') {
                        $overtime += PohodaXml::num($item, 'PocHodin');
                    }
                }
                if (in_array($number, ['M01', 'M09'], true) && PohodaXml::num($item, 'Hodnota1') > 0) {
                    $monthlyWage = max($monthlyWage ?? 0.0, PohodaXml::num($item, 'Hodnota1'));
                }
            }
            /*
             * Nepřítomnost, kterou evidence vede jedině s daty od a do (nemoc, ošetřovné,
             * otcovská, neplacené volno, neomluvená absence), do měsíčního sešitu NEPATŘÍ:
             * tutéž dobu zapíše převod datovaně z `MZneprit` a jeden údaj má mít jediný
             * zdroj - {@see PohodaPayrollCatalog::absenceNeedsDates()}. Z holého měsíčního
             * součtu hodin se náhrada mzdy ani vyloučená doba spočítat nedá, takže souhrn
             * s nimi měsíc stejně schválit nepustí.
             *
             * Vypouští se jen druh, u kterého má data KAŽDÝ jeho řádek v měsíci. Kdyby se
             * vypustila jen datovaná část, zbytek by v souhrnu druh podržel, převod by kvůli
             * tomu nezapsal ani datovanou část ({@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter::absences()})
             * a ta doba by zmizela z obou stran.
             */
            $year = (int) substr($period, 0, 4);
            /** @var array<string,list<array{header:string,hours:float}>> $absenceHours význam => hodiny řádků */
            $absenceHours = [];
            /** @var array<string,bool> $absenceDated význam => zapíše se datovaně místo do sešitu */
            $absenceDated = [];
            foreach ($this->items['MZneprit'][$mzId] ?? [] as $item) {
                $catalog = $this->byId['sMZneprit'][PohodaXml::text($item, 'RefSlozka')] ?? [];
                $number = PohodaXml::text($catalog, 'Cislo');
                $name = PohodaXml::text($catalog, 'Nazev');
                $class = PohodaPayrollCatalog::absence($number, $name);
                if ($class['meaning'] === 'unknown') {
                    // Druh, který převod zapíše s daty do evidence nepřítomností, se
                    // neztrácí; bez dat ale nejde ani tam.
                    $dated = PohodaPayrollPeople::absenceType($number, self::date(PohodaXml::text($item, 'DatPorod'))) !== null
                        && PohodaPayrollPeople::absenceDates($item, $year) !== null;
                    if (!$dated) {
                        $skip('absence', strtoupper(trim($number)), $name, PohodaXml::num($item, 'KcNahr'), PohodaXml::num($item, 'HodPrac'));
                    }
                    continue;
                }
                $meaning = $class['meaning'];
                $absenceHours[$meaning][] = ['header' => $class['header'], 'hours' => PohodaXml::num($item, 'HodPrac')];
                if ($meaning === AttendanceRules::RATE_MEANING) {
                    $average = PohodaXml::num($mz, 'KcPrum') > 0 ? PohodaXml::num($mz, 'KcPrum') : PohodaXml::num($mz, 'KcPrumU');
                    if ($average > 0 && PohodaXml::num($item, 'HodPrac') > 0) {
                        $obstacleRates[(int) round(PohodaXml::num($item, 'KcNahr') / (PohodaXml::num($item, 'HodPrac') * $average) * 100)] = true;
                    }
                }
                $absenceDated[$meaning] = ($absenceDated[$meaning] ?? true)
                    && PohodaPayrollCatalog::absenceNeedsDates($number, $name)
                    && PohodaPayrollPeople::absenceDates($item, $year) !== null;
            }
            foreach ($absenceHours as $meaning => $items) {
                if ($absenceDated[$meaning] === true) {
                    continue;
                }
                foreach ($items as $absence) {
                    $column($absence['header'], $meaning, 'hours');
                    $add($absence['header'], $absence['hours']);
                }
            }
            foreach ($this->items['MZsrazky'][$mzId] ?? [] as $item) {
                $reference = PohodaXml::text($item, 'RefSlozka');
                // Zákonná srážka (`ignore`) do sešitu nepatří: exekuční případ z ní dělá
                // samostatný krok převodu, takže by se z čisté mzdy strhla dvakrát.
                $class = PohodaPayrollCatalog::deduction($this->byId['sMZsrazky'][$reference] ?? []);
                $amount = PohodaXml::num($item, 'KcSrazeno');
                if ($class['meaning'] === 'unclassified') {
                    if (round($amount, 2) != 0.0) {
                        $key = $class['code'] !== '' ? $class['code'] : '#' . $reference;
                        $unclassifiedDeductions[$key] ??= ['code' => $key, 'name' => $class['name'], 'inputs' => 0];
                        $unclassifiedDeductions[$key]['inputs']++;
                    }
                    continue;
                }
                if ($class['meaning'] === 'ignore') {
                    continue;
                }
                $column($class['header'], $class['meaning'], 'amount');
                $add($class['header'], $amount);
                $totals[$class['meaning'] === 'net_meal_deduction' ? 'meal_minor' : 'deduction_minor'] += self::minor($amount);
            }

            $isDpp = self::bool(PohodaXml::text($relation, 'JeDPP'));
            $weekly = PohodaXml::num($mz, 'TUvazek') > 0 ? PohodaXml::num($mz, 'TUvazek') : PohodaXml::num($relation, 'TUvazek');
            // `HodFond` je fond plného úvazku (denní úvazek × pracovní dny) i u kratšího
            // týdenního úvazku; fond vztahu je pracovní dny fondu × týdenní úvazek / 5.
            $fundDays = PohodaXml::num($mz, 'DnyFond2');
            $fund = $fundDays > 0 && $weekly > 0 && !$isDpp ? $fundDays * $weekly / 5 : PohodaXml::num($mz, 'HodFond');
            $worked = PohodaXml::num($mz, 'HodOdpra') + $overtime;
            // PAMICA počítá svátek v jinak pracovní den do odpracovaných hodin (`HodOdpra`),
            // v měsíčním hlášení ho ale vede mezi neodpracovanými hodinami s náhradou. Import
            // MyÚčta ho proto dostane zvlášť (`holiday_hours`) a odpracováno je bez něj.
            // Týká se jen měsíční mzdy (`M01`/`M09`): mzda za hodiny nebo úkol má za svátek
            // náhradu (`V02` v `MZneprit`), ta už v sešitu je a `HodOdpra` ji neobsahuje.
            $holiday = $isDpp || $monthlyWage === null || isset($absenceHours['holiday_hours'])
                ? 0.0
                : min($worked, $this->holidayHours($mz, $weekly));
            $worked -= $holiday;
            $insurer = $this->byId['sMzPoj'][PohodaXml::text($mz, 'RefPoj')] ?? $this->byId['sMzPoj'][PohodaXml::text($person, 'RefPoj')] ?? [];
            $center = $this->byId['sSTR'][PohodaXml::text($relation, 'ResStr')] ?? [];
            $place = $this->byId['PracMista'][PohodaXml::text($relation, 'RelPracMist')] ?? [];
            $start = self::date(PohodaXml::text($relation, 'DatNast')) ?? self::date(PohodaXml::text($relation, 'DatVstup'));
            $end = self::date(PohodaXml::text($relation, 'DatOdch'));
            $personalNumber = $this->personalNumber($person, $relation);
            // Údaj, který by osobu nešlo založit (rodné číslo bez platného data narození,
            // pojišťovna mimo číselník - typicky cizinci), se vynechá a převod ho vypíše k doplnění.
            $birthNumber = PohodaXml::text($person, 'RodCisl');
            if ($birthNumber !== '') {
                try {
                    CzechBirthNumber::normalize($birthNumber);
                } catch (\InvalidArgumentException $e) {
                    // Tentýž soubor 91_mzdy.xml vyrábí POHODA Mzdy i PAMICA —
                    // hláška proto jmenuje zdroj obecně, ne jen POHODU.
                    $omitted[] = "osobní číslo {$personalNumber}: rodné číslo z převáděných mezd (POHODA/PAMICA) je neplatné ({$e->getMessage()}), vynecháno - doplňte ho v evidenci zaměstnance.";
                    $birthNumber = '';
                }
            }
            $insurerCode = PohodaXml::text($insurer, 'Kod');
            if ($insurerCode !== '' && !HealthInsurers::isValid($insurerCode)) {
                $omitted[] = "osobní číslo {$personalNumber}: kód zdravotní pojišťovny {$insurerCode} není v číselníku, vynechán - doplňte ho v evidenci zaměstnance.";
                $insurerCode = '';
            }
            $row = [
                'Zaměstnanec' => trim(PohodaXml::text($person, 'Prijmeni') . ' ' . PohodaXml::text($person, 'Jmeno')),
                'Osobní číslo' => $personalNumber,
                'Rodné číslo' => $birthNumber,
                'Datum narození' => self::czechDate(self::date(PohodaXml::text($person, 'DatNar'))),
                'Zdravotní pojišťovna' => $insurerCode,
                'Druh vztahu' => $isDpp ? 'DPP' : 'pracovní poměr',
                'Středisko' => PohodaXml::text($center, 'IDS'),
                'Pracovní místo' => PohodaXml::text($place, 'SText') ?: PohodaXml::text($place, 'IDS'),
                'Týdenní úvazek' => $weekly > 0 && !$isDpp ? round($weekly, 2) : null,
                'Měsíční mzda' => $monthlyWage !== null ? round($monthlyWage, 2) : null,
                'Nástup / ukončení' => trim(($start !== null ? 'nástup ' . self::czechDate($start) : '') . ($end !== null ? ', ukončení ' . self::czechDate($end) : ''), ', '),
                'Příjmení' => PohodaXml::text($person, 'Prijmeni'),
                'Jméno' => PohodaXml::text($person, 'Jmeno'),
                'Fond pracovní doby (h)' => round($fund, 2),
                'Odpracováno (h)' => round($worked, 2),
                'Hrubá mzda (Kč)' => round(PohodaXml::num($mz, 'KcHrubaM'), 2),
                'Čistá mzda (Kč)' => round(PohodaXml::num($mz, 'KcCistaM'), 2),
                '_start' => $start,
            ];
            foreach ($values as $header => $value) {
                if (round($value, 2) != 0.0) {
                    $row[$header] = round($value, 2);
                }
            }
            if (round($holiday, 2) > 0.0) {
                $column(self::HOLIDAY_HEADER, 'holiday_hours', 'hours');
                $row[self::HOLIDAY_HEADER] = round(($row[self::HOLIDAY_HEADER] ?? 0.0) + $holiday, 2);
            }
            $rows[] = $row;
            $totals['rows']++;
            $totals['gross_minor'] += self::minor(PohodaXml::num($mz, 'KcHrubaM'));
            $totals['net_minor'] += self::minor(PohodaXml::num($mz, 'KcCistaM'));
            $totals['worked_millihours'] += (int) round($worked * 1000);
        }
        $column('Hrubá mzda (Kč)', 'reference_gross', 'amount');
        $column('Čistá mzda (Kč)', 'reference_net', 'amount');
        usort($rows, static fn (array $a, array $b): int => [AttendanceText::normalize((string) $a['Zaměstnanec']), $a['Osobní číslo']]
            <=> [AttendanceText::normalize((string) $b['Zaměstnanec']), $b['Osobní číslo']]);

        uasort($unclassifiedDeductions, static fn (array $a, array $b): int => [$b['inputs'], $a['code']] <=> [$a['inputs'], $b['code']]);

        ksort($obstacleRates);
        ksort($unconverted);

        return ['period' => $period, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'omitted' => $omitted,
            'unclassified_deductions' => $unclassifiedDeductions, 'obstacle_rates' => array_keys($obstacleRates),
            'unconverted_items' => $unconverted];
    }

    /**
     * Varování protokolu o položkách, které převod nezná, přes všechny měsíce; `null`,
     * když žádná není. Kód a název položky, počet vstupů, částka a u nepřítomností hodiny.
     *
     * @param list<array{unconverted_items?:array<string,array{code:string,name:string,kind:string,inputs:int,amount:float,hours:float}>}> $months
     */
    public static function unconvertedItemsMessage(array $months, int $limit = 20): ?string
    {
        $items = [];
        foreach ($months as $month) {
            foreach ($month['unconverted_items'] ?? [] as $key => $entry) {
                $items[$key] ??= ['code' => $entry['code'], 'name' => $entry['name'], 'inputs' => 0, 'amount' => 0.0, 'hours' => 0.0];
                $items[$key]['inputs'] += $entry['inputs'];
                $items[$key]['amount'] += $entry['amount'];
                $items[$key]['hours'] += $entry['hours'];
            }
        }
        if ($items === []) {
            return null;
        }
        uasort($items, static fn (array $a, array $b): int => [$b['inputs'], $a['code']] <=> [$a['inputs'], $b['code']]);
        $list = [];
        foreach (array_slice($items, 0, $limit) as $entry) {
            $inputs = $entry['inputs'];
            $parts = [$inputs . ' ' . ($inputs === 1 ? 'vstup' : ($inputs < 5 ? 'vstupy' : 'vstupů')),
                number_format($entry['amount'], 2, ',', ' ') . ' Kč'];
            if (round($entry['hours'], 2) != 0.0) {
                $parts[] = rtrim(rtrim(number_format($entry['hours'], 2, ',', ' '), '0'), ',') . ' h';
            }
            $list[] = trim($entry['code'] . ' ' . $entry['name']) . ' (' . implode(', ', $parts) . ')';
        }
        if (count($items) > $limit) {
            $list[] = sprintf('a dalších %d', count($items) - $limit);
        }

        return sprintf(
            'Položky mezd PAMICA, které převod nezná: %s. Do mzdových vstupů ani souhrnu hodin se nepřevedly. '
            . 'V převzatých měsících jsou v úhrnech PAMICA; v měsících, které počítá MyÚčto, je doplňte '
            . 'v Mzdy → Vstupy (složky) nebo v Mzdy → Absence (nepřítomnosti).',
            implode(', ', $list),
        );
    }

    /**
     * Hodiny svátků v jinak pracovní dny podle rozvrhu mzdy (`MZdoch.HodinN` je plán dne N),
     * nejvýš za tolik svátků, kolik jich PAMICA vztahu započítala (`DnyStSv`). Bez rozvrhu
     * počet svátků v pracovní dny × denní díl týdenního úvazku.
     *
     * @param array<string,mixed> $mz
     */
    private function holidayHours(array $mz, float $weekly): float
    {
        // `DnyStSv` počítá jen svátky za trvání vztahu; rozvrh `MZdoch` je celý měsíc.
        if (PohodaXml::num($mz, 'DnyStSv') <= 0) {
            return 0.0;
        }
        $year = (int) PohodaXml::text($mz, 'Rok');
        $month = (int) PohodaXml::text($mz, 'RelMes');
        $plan = $this->items['MZdoch'][PohodaXml::text($mz, 'ID')][0] ?? null;
        if ($plan !== null) {
            $hours = 0.0;
            foreach (array_keys((new CzechHolidayCalendar())->forYear($year)) as $date) {
                if ((int) substr($date, 5, 2) === $month) {
                    $hours += PohodaXml::num($plan, 'Hodin' . (int) substr($date, 8, 2));
                }
            }
            return $weekly > 0 ? min($hours, PohodaXml::num($mz, 'DnyStSv') * $weekly / 5) : $hours;
        }

        return $weekly > 0 ? PohodaXml::num($mz, 'DnyStSv') * $weekly / 5 : 0.0;
    }

    /**
     * Sazba náhrady za překážky na straně zaměstnavatele, se kterou PAMICA v převáděných
     * měsících opravdu počítala (náhrada / hodiny × průměr). Jen když je v celém převodu
     * jediná a zákon ji připouští (§ 207 až § 209 ZP: 60 až 100 %); jinak `null` a platí
     * výchozí sazba importu docházky.
     *
     * @param list<array{obstacle_rates?:list<int>}> $months
     */
    public static function obstacleEmployerRate(array $months): ?int
    {
        $rates = [];
        foreach ($months as $month) {
            foreach ($month['obstacle_rates'] ?? [] as $rate) {
                $rates[$rate] = true;
            }
        }
        if (count($rates) !== 1) {
            return null;
        }
        $rate = (int) array_key_first($rates);

        return $rate >= 60 && $rate <= 100 ? $rate : null;
    }

    /**
     * Profil importu nad sloupci všech měsíců: přesná hlavička → význam, zbytek listu se
     * ignoruje; mzdové složky, které ve firmě chybí, import založí podle `components`.
     *
     * @param list<array{columns:array<string,array{meaning:string,unit:?string,kind:?string,code:?string}>}> $months
     * @return array{name:string, rules:list<array<string,mixed>>, components:list<array{code:string,name:string,kind:string}>}
     */
    public static function profile(array $months): array
    {
        $columns = [];
        foreach ($months as $month) {
            $columns += $month['columns'];
        }
        $rules = [];
        $components = [];
        $obstacleRate = self::obstacleEmployerRate($months);
        foreach ($columns as $header => $column) {
            $rule = ['sheet' => self::SHEET, 'header' => $header, 'meaning' => $column['meaning'], 'unit' => $column['unit'], 'component_code' => $column['code']];
            if ($obstacleRate !== null && $column['meaning'] === AttendanceRules::RATE_MEANING) {
                $rule['rate_percent'] = $obstacleRate;
            }
            $rules[] = $rule;
            if ($column['meaning'] === 'component' && $column['code'] !== null) {
                $components[$column['code']] = [
                    'code' => $column['code'],
                    'name' => mb_substr((string) preg_replace('/\s*\(Kč\)$/u', '', $header), 0, 120),
                    'kind' => (string) $column['kind'],
                ];
            }
        }
        $rules[] = ['sheet' => self::SHEET, 'header' => '*', 'meaning' => 'ignore', 'unit' => null, 'component_code' => null];
        return ['name' => self::PROFILE_NAME, 'rules' => $rules, 'components' => array_values($components)];
    }

    /**
     * Sešit měsíce jako soubor pro import (stejný tvar, jaký dává nahrání souboru).
     *
     * @param array{period:string, columns:array<string,mixed>, rows:list<array<string,mixed>>} $month
     * @return array{name:string,content:string,sha256:string,extension:string}
     */
    public static function workbook(array $month): array
    {
        $book = new Spreadsheet();
        $sheet = $book->getSheet(0);
        $sheet->setTitle(self::SHEET);
        $headers = array_keys($month['columns']);
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        foreach ($month['rows'] as $r => $row) {
            foreach ($headers as $index => $header) {
                $value = $row[$header] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_int($value) || is_float($value)) {
                    $sheet->setCellValue([$index + 1, $r + 2], $value);
                } else {
                    $sheet->setCellValueExplicit([$index + 1, $r + 2], (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $stream = fopen('php://memory', 'w+b');
        (new Xlsx($book))->save($stream);
        $book->disconnectWorksheets();
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);
        return ['name' => 'mzdy-pohoda-' . $month['period'] . '.xlsx', 'content' => $content, 'sha256' => hash('sha256', $content), 'extension' => 'xlsx'];
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $relation
     */
    private function personalNumber(array $person, array $relation): string
    {
        $number = PohodaXml::text($person, 'OsCislo');
        $order = (int) (PohodaXml::text($relation, 'Poradi') ?: '1');
        if (($this->relationCount[PohodaXml::text($person, 'ID')] ?? 1) > 1 && $order > 1) {
            $number .= '-' . $order;
        }
        return $number;
    }

    private static function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', '-1', 'true'], true);
    }

    /** Datum `Y-m-d`; prázdné nebo nulové datum Accessu (před rokem 1901) = null. */
    private static function date(string $value): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 && (int) $m[1] >= 1901 ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    private static function czechDate(?string $iso): string
    {
        if ($iso === null) {
            return '';
        }
        [$y, $m, $d] = array_map('intval', explode('-', $iso));
        return "{$d}. {$m}. {$y}";
    }

    private static function minor(float $value): int
    {
        return (int) round($value * 100);
    }
}
