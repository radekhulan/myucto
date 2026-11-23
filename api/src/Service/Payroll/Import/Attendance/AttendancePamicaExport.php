<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;

/**
 * Exporty docházkových systémů pro PAMICA převedené na měsíční souhrn osoby.
 *
 * Import docházky pracuje s listem, kde jeden řádek = jedna osoba za měsíc,
 * a stejný údaj z více řádků NIKDY nesčítá ({@see AttendancePersonAggregator}).
 * Oba exporty pro PAMICA ale nesou data po dnech nebo po složkách:
 *
 *  - XML „Docházka zaměstnanců" podle schématu
 *    {@see self::NAMESPACE}: rozvrh po dnech, nepřítomnosti s kódem PAMICA
 *    a obdobím od–do (i zkrácené první a poslední dny), přesčasy a nadúvazky,
 *    příplatky a mzdové složky (mzda, prémie, osobní ohodnocení, stravenkový
 *    paušál, osvobozené příjmy, obecná složka), vše i v obalu `slozka`;
 *  - CSV „Excel" ze stejného systému: řádek = osoba a den, hodiny fondu
 *    a nepřítomností v samostatných sloupcích.
 *
 * Obojí se tu sečte do virtuálního listu „PAMICA RRRR-MM" se sloupci
 * {@see self::columns()}. Na ten list míří pravidla Vzoru PAMICA
 * ({@see AttendancePamicaSampleProfile}); měsíc v názvu listu porovná import
 * s vybraným obdobím ({@see AttendancePeriodDetector}).
 *
 * Odpracované hodiny export přímo nemá: počítají se jako rozvrh mínus
 * nepřítomnosti plus přesčas a nadúvazek, stejně jako je počítá PAMICA.
 *
 * Co se převést nedá bez rizika (mzda se sazbou vedle sjednané mzdy, příplatek
 * v procentech výdělku, osvobozené příjmy a obecná složka s neznámým daňovým
 * režimem, složka určená jen kódem docházkového systému), se neimportuje
 * a osoba dostane varování s hodnotami ze souboru.
 */
final class AttendancePamicaExport
{
    public const SHEET_PREFIX = 'PAMICA';
    public const NAMESPACE = 'http://www.stormware.cz/schema/pamica/dochazka.xsd';
    public const ROOT = 'dochazky_zamestnancu';

    public const PERSONAL_NUMBER = 'Osobní číslo';
    public const NAME = 'Příjmení a jméno';
    public const RELATION = 'Pracovní poměr';
    public const BIRTH_NUMBER = 'Rodné číslo';
    public const COST_CENTER = 'Středisko';
    public const OTHER_ABSENCE = 'Ostatní nepřítomnost (h)';
    public const PRESENCE = 'Doba přítomnosti (h)';
    public const EXTRA_HOURS = 'z toho nad úvazek (h)';

    /** Složky vzoru, které výchozí číselník nemá ({@see AttendancePamicaSampleProfile::components()}). */
    public const PERSONAL_RATING = 'OSOBNI_OHODNOCENI';

    private const MAX_PEOPLE = 2_000;
    private const MAX_ITEMS = 5_000;

    /**
     * Sloupce souhrnného listu v pořadí. Hodiny jsou desetinné, částky v Kč.
     *
     * @return list<array{header:string,meaning:string,component_code:?string}>
     */
    public static function columns(): array
    {
        $column = static fn (string $header, string $meaning, ?string $component = null): array => [
            'header' => $header, 'meaning' => $meaning, 'component_code' => $component,
        ];

        return [
            $column(self::PERSONAL_NUMBER, 'personal_number'),
            $column(self::NAME, 'person_name'),
            $column(self::RELATION, 'relation_label'),
            $column(self::BIRTH_NUMBER, 'birth_number'),
            $column(self::COST_CENTER, 'cost_center'),
            $column('Fond (h)', 'fund_hours'),
            $column('Odpracováno (h)', 'worked_hours'),
            $column('Přesčas (h)', 'overtime_hours'),
            // Nadúvazek (práce nad kratší úvazek, ne přesčas) je v odpracovaných hodinách; sloupec je k přehledu.
            $column(self::EXTRA_HOURS, AttendanceMeaning::IGNORE),
            $column('Noční práce (h)', 'night_hours'),
            $column('Práce v sobotu a neděli (h)', 'weekend_hours'),
            $column('Práce ve svátek (h)', 'holiday_work_hours'),
            $column('Pracovní cesta (h)', 'business_trip_hours'),
            $column('Home office (h)', 'home_office_hours'),
            $column('Dovolená (h)', 'vacation_hours'),
            $column('Svátek (h)', 'holiday_hours'),
            $column('Nemoc (h)', 'sick_hours'),
            $column('OČR (h)', 'care_hours'),
            $column('Lékař (h)', 'doctor_hours'),
            $column('Překážky na straně zaměstnance (h)', 'obstacle_employee_hours'),
            $column('Překážky na straně zaměstnavatele (h)', 'obstacle_employer_hours'),
            $column('Neplacené volno (h)', 'unpaid_leave_hours'),
            $column('Neomluvená absence (h)', 'unexcused_hours'),
            $column('Otcovská (h)', 'paternity_hours'),
            $column('Náhradní volno (h)', 'compensatory_time_off_hours'),
            // Druhy, které import neumí převést; hodiny jsou vidět v náhledu a varování.
            $column(self::OTHER_ABSENCE, AttendanceMeaning::IGNORE),
            // Jen CSV: čas mezi příchodem a odchodem včetně přestávek, k porovnání.
            $column(self::PRESENCE, AttendanceMeaning::IGNORE),
            $column('Úkolová mzda (Kč)', AttendanceMeaning::COMPONENT, 'MZDA_UKOLOVA'),
            $column('Prémie (Kč)', AttendanceMeaning::COMPONENT, 'ODMENA'),
            $column('Osobní ohodnocení (Kč)', AttendanceMeaning::COMPONENT, self::PERSONAL_RATING),
            $column('Příplatek za ztížené prostředí (Kč)', AttendanceMeaning::COMPONENT, 'PRIPLATEK_ZTIZENE_PROSTREDI'),
            $column('Pohotovost mimo pracoviště (Kč)', AttendanceMeaning::COMPONENT, 'ODMENA_POHOTOVOST'),
            $column('Jiné příplatky (Kč)', AttendanceMeaning::COMPONENT, 'PREMIE_PRIPLATKY'),
            $column('Stravenkový paušál osvobozený (Kč)', AttendanceMeaning::COMPONENT, 'PRISPEVEK_STRAVOVANI_PREVZATY'),
            $column('Stravenkový paušál zdanitelný (Kč)', AttendanceMeaning::COMPONENT, 'PRISPEVEK_STRAVOVANI_ZDANITELNY'),
        ];
    }

    /** Hodiny, které jsou nepřítomností a zmenšují odpracovanou dobu. */
    private const ABSENCE_MEANINGS = [
        'vacation_hours', 'holiday_hours', 'sick_hours', 'care_hours', 'doctor_hours',
        'obstacle_employee_hours', 'obstacle_employer_hours', 'unpaid_leave_hours',
        'unexcused_hours', 'paternity_hours', 'compensatory_time_off_hours', 'other',
    ];

    /**
     * Význam kódu nepřítomnosti PAMICA.
     *
     * Nejdřív kódy, které vyjmenovává schéma importu docházky (nemoci jsou tam
     * `N01`–`N08`), potom vnitřní číselník PAMICA z převodu mezd
     * ({@see PohodaPayrollCatalog::absence()}), kde má nemoc kódy `H…`.
     * `business_trip_hours` je odpracovaná doba, ne nepřítomnost.
     */
    public static function absenceMeaning(string $code): string
    {
        $code = strtoupper(trim($code));
        $schema = match (true) {
            str_starts_with($code, 'V01') => 'vacation_hours',
            $code === 'V02' => 'holiday_hours',
            $code === 'V03' => 'obstacle_employee_hours',
            $code === 'V04', $code === 'V14' => 'unpaid_leave_hours',
            $code === 'V05' => 'unexcused_hours',
            str_starts_with($code, 'V06') => 'obstacle_employer_hours',
            // Vojenské cvičení: překážka z důvodu obecného zájmu.
            $code === 'V07' => 'obstacle_employee_hours',
            $code === 'V12' => 'business_trip_hours',
            in_array($code, ['V19', 'V20'], true) => 'compensatory_time_off_hours',
            in_array($code, ['N01', 'N02', 'N03', 'N04'], true) => 'sick_hours',
            in_array($code, ['N05', 'N06'], true) => 'care_hours',
            default => null,
        };
        if ($schema !== null) {
            return $schema;
        }
        $meaning = PohodaPayrollCatalog::absence($code, '')['meaning'];

        return $meaning === 'unknown' ? 'other' : $meaning;
    }

    public static function isXml(string $content): bool
    {
        $head = substr((string) preg_replace('/^\xEF\xBB\xBF/', '', $content), 0, 2048);

        return str_contains($head, '<' . self::ROOT) || str_contains($head, ':' . self::ROOT);
    }

    /** @return list<AttendanceSheet> */
    public static function fromXml(string $fileName, int $fileIndex, string $content): array
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
            throw new \InvalidArgumentException("Soubor „{$fileName}“ obsahuje nepovolené definice XML.");
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $loaded ? $document->documentElement : null;
        // Schéma dovoluje i jediného zaměstnance bez obalového seznamu.
        $employees = match ($root?->localName) {
            self::ROOT => self::children($root, 'dochazka_zamestnance'),
            'dochazka_zamestnance' => [$root],
            default => throw new \InvalidArgumentException(
                "Soubor „{$fileName}“ není export docházky pro PAMICA. Nahrajte XML „Docházka zaměstnanců“, XLSX nebo CSV.",
            ),
        };

        $people = [];
        $warnings = [];
        foreach ($employees as $index => $employee) {
            if ($index >= self::MAX_PEOPLE) {
                throw new \InvalidArgumentException("Soubor „{$fileName}“ má víc než " . self::MAX_PEOPLE . ' zaměstnanců. Rozdělte ho.');
            }
            [$person, $personWarnings] = self::xmlPerson($fileName, $employee, $index + 1);
            $people[] = $person;
            array_push($warnings, ...$personWarnings);
        }
        if ($people === []) {
            throw new \InvalidArgumentException("Soubor „{$fileName}“ neobsahuje žádného zaměstnance.");
        }

        return self::sheets($fileName, $fileIndex, $people, $warnings);
    }

    /**
     * Denní CSV exportu („Jmeno;Prijmeni;Cislo;Datum;Prichod;Odchod;Fond;…")
     * sečtené po osobách. Jiné CSV vrací `null` a čte se jako dosud.
     *
     * @return list<AttendanceSheet>|null
     */
    public static function fromDailyCsv(AttendanceSheet $csv): ?array
    {
        $headers = [];
        foreach ($csv->rows[1] ?? [] as $column => $cell) {
            $key = str_replace([' ', '_', '.'], '', AttendanceText::normalize($cell->textValue()));
            if ($key !== '') {
                $headers[$key] = $column;
            }
        }
        foreach (['jmeno', 'prijmeni', 'datum', 'fond'] as $required) {
            if (!isset($headers[$required])) {
                return null;
            }
        }
        $absenceColumns = [
            'nemoc' => 'sick_hours',
            'ocr' => 'care_hours',
            'dl' => 'care_hours',
            'lekar' => 'doctor_hours',
            'dovolena' => 'vacation_hours',
            'statnisvatek' => 'holiday_hours',
            'svatek' => 'holiday_hours',
            'studium' => 'obstacle_employee_hours',
            'studijnivolno' => 'obstacle_employee_hours',
            'soud' => 'obstacle_employee_hours',
            'prekazkavpraci' => 'obstacle_employee_hours',
            'neplacenevolno' => 'unpaid_leave_hours',
            'neomluveno' => 'unexcused_hours',
            'otcovska' => 'paternity_hours',
            'nahradnivolno' => 'compensatory_time_off_hours',
        ];
        // Práce mimo pracoviště je odpracovaná doba, ne nepřítomnost.
        $workColumns = ['homeoffice' => 'home_office_hours', 'sluzebnicesta' => 'business_trip_hours', 'teren' => null, 'skoleni' => null];
        $known = ['jmeno', 'prijmeni', 'cislo', 'osobnicislo', 'datum', 'prichod', 'odchod', 'fond'];
        $unknown = [];
        foreach ($headers as $key => $column) {
            if (!in_array($key, $known, true) && !isset($absenceColumns[$key]) && !array_key_exists($key, $workColumns)) {
                $unknown[] = $csv->cell(1, $column)->textValue();
            }
        }

        $people = [];
        $order = [];
        $offScheduleDays = [];
        for ($row = 2; $row <= $csv->maxRow; ++$row) {
            $first = $csv->cell($row, $headers['jmeno'])->textValue();
            $last = $csv->cell($row, $headers['prijmeni'])->textValue();
            $date = self::csvDate($csv->cell($row, $headers['datum'])->textValue());
            if (trim($first . $last) === '' || $date === null) {
                continue;
            }
            $number = isset($headers['cislo']) ? $csv->cell($row, $headers['cislo'])->textValue()
                : (isset($headers['osobnicislo']) ? $csv->cell($row, $headers['osobnicislo'])->textValue() : '');
            $period = $date->format('Y-m');
            $key = $period . '|' . mb_strtoupper($number . '|' . $last . '|' . $first, 'UTF-8');
            if (!isset($people[$key])) {
                if (count($people) >= self::MAX_PEOPLE) {
                    throw new \InvalidArgumentException("Soubor „{$csv->file}“ má víc než " . self::MAX_PEOPLE . ' osob. Rozdělte ho.');
                }
                $people[$key] = self::emptyPerson($period, $number, trim($last . ' ' . $first), '');
                $order[] = $key;
            }
            $person = &$people[$key];
            $fund = self::csvMinutes($csv, $row, $headers['fond']);
            $absent = 0;
            foreach ($absenceColumns as $header => $meaning) {
                if (isset($headers[$header])) {
                    $minutes = self::csvMinutes($csv, $row, $headers[$header]);
                    $person['hours'][$meaning] = ($person['hours'][$meaning] ?? 0) + $minutes;
                    $absent += $minutes;
                }
            }
            foreach ($workColumns as $header => $meaning) {
                if ($meaning !== null && isset($headers[$header])) {
                    $person['hours'][$meaning] = ($person['hours'][$meaning] ?? 0) + self::csvMinutes($csv, $row, $headers[$header]);
                }
            }
            $presence = self::presenceMinutes(
                isset($headers['prichod']) ? $csv->cell($row, $headers['prichod'])->textValue() : '',
                isset($headers['odchod']) ? $csv->cell($row, $headers['odchod'])->textValue() : '',
            );
            $person['fund'] += $fund;
            $person['presence'] = ($person['presence'] ?? 0) + $presence;
            if ($fund > 0) {
                $person['worked'] += max(0, $fund - $absent);
            } elseif ($presence > 0) {
                // Práce v den bez rozvrhu (víkend, svátek): rozvrh ji nezná, započte se přítomnost.
                $person['worked'] += $presence;
                $offScheduleDays[$key][] = $date->format('j. n.');
            }
            unset($person);
        }
        if ($people === []) {
            return null;
        }

        $warnings = [];
        if ($unknown !== []) {
            $warnings[] = "Soubor „{$csv->file}“: sloupce " . implode(', ', array_map(static fn (string $h): string => "„{$h}“", $unknown))
                . ' import nezná a hodiny z nich nepřevedl. Zkontrolujte je v docházce.';
        }
        foreach ($offScheduleDays as $key => $days) {
            $warnings[] = "{$people[$key]['name']}: práce mimo rozvrh (" . implode(', ', $days)
                . '). Do odpracované doby se započetla doba přítomnosti včetně přestávek; zkontrolujte přesčas.';
        }

        return self::sheets($csv->file, $csv->fileIndex, array_map(static fn (string $key): array => $people[$key], $order), $warnings);
    }

    /**
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    private static function xmlPerson(string $fileName, \DOMElement $employee, int $position): array
    {
        $header = self::child($employee, 'hlavicka');
        $month = (int) self::text($header, 'mesic');
        $year = (int) self::text($header, 'rok');
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException("Soubor „{$fileName}“: zaměstnanec č. {$position} nemá platný měsíc a rok docházky.");
        }
        $period = sprintf('%04d-%02d', $year, $month);
        $name = trim(self::text($header, 'prijmeni') . ' ' . self::text($header, 'jmeno'));
        $person = self::emptyPerson($period, self::text($header, 'cislo_pracovniho_pomeru'), $name, self::text($header, 'pracovni_pomer'));
        $person['birth_number'] = self::text($header, 'rodne_cislo');
        $label = $name !== '' ? $name : "zaměstnanec č. {$position}";
        $warnings = [];
        $note = static function (string $text) use (&$warnings, $label): void {
            $warnings[] = "{$label}: {$text}";
        };

        $schedule = [];
        foreach (self::children(self::child($employee, 'rozvrh'), 'uvazek') as $day) {
            $date = $day->getAttribute('datum');
            if (!str_starts_with($date, $period . '-')) {
                continue;
            }
            $schedule[$date] = self::clockMinutes($day->textContent);
        }
        $person['fund'] = array_sum($schedule);

        $used = [];
        $otherCodes = [];
        foreach (self::children(self::child($employee, 'nepritomnosti'), 'nepritomnost') as $absence) {
            $code = strtoupper(self::text($absence, 'kod'));
            $attendanceCode = self::text($absence, 'dochazka_kod');
            self::costCenter($person, $absence);
            // Schéma: zadaný `dochazka_kod` má přednost před `kod`; jeho význam zná jen nastavení PAMICA.
            if ($attendanceCode !== '' || $code === '') {
                $code = $attendanceCode !== '' ? $attendanceCode : '?';
                $meaning = 'other';
                $otherCodes[self::describe($code, $absence)] = true;
            } else {
                $meaning = self::absenceMeaning($code);
                if ($meaning === 'other') {
                    $otherCodes[self::describe($code, $absence)] = true;
                }
            }
            $fromNode = self::child($absence, 'od');
            $toNode = self::child($absence, 'do');
            $from = self::date((string) $fromNode?->textContent);
            $to = self::date((string) $toNode?->textContent) ?? $from;
            if ($from === null || $to === null || $to < $from) {
                $note("nepřítomnost {$code} nemá platné období od–do, nepřevedla se.");
                continue;
            }
            $firstHours = self::partial($fromNode);
            $lastHours = $from == $to ? $firstHours : self::partial($toNode);
            $minutes = 0;
            for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
                $key = $day->format('Y-m-d');
                $free = max(0, ($schedule[$key] ?? 0) - ($used[$key] ?? 0));
                $limit = match (true) {
                    $day == $from && $firstHours !== null => $firstHours,
                    $day == $to && $lastHours !== null => $lastHours,
                    default => null,
                };
                $taken = $limit === null ? $free : min($free, $limit);
                $used[$key] = ($used[$key] ?? 0) + $taken;
                $minutes += $taken;
            }
            $person['hours'][$meaning] = ($person['hours'][$meaning] ?? 0) + $minutes;
            $rate = self::text($absence, 'proc_sazba');
            if ($rate !== '' && $meaning !== 'other') {
                $note("nepřítomnost {$code} má v souboru sazbu {$rate} %. MyÚčto počítá náhradu podle sazby v profilu mapování a zákona; zkontrolujte ji.");
            }
        }
        if ($otherCodes !== []) {
            $note('nepřítomnost ' . implode(', ', array_keys($otherCodes))
                . ' import nezná. Hodiny jsou ve sloupci „' . self::OTHER_ABSENCE . '“ a do mezd se nepřevedly; zadejte je ručně.');
        }

        $overtime = 0;
        $extra = 0;
        foreach (self::items(self::child($employee, 'pritomnost')) as [$kind, $item]) {
            self::costCenter($person, $item);
            $minutes = self::clockMinutes(self::text($item, 'hodiny'));
            match ($kind) {
                'prescas_pracovni_den', 'prescas_den_klidu' => $overtime += $minutes,
                'naduvazek_pracovni_den', 'naduvazek_den_klidu' => $extra += $minutes,
                default => $note("položku docházky „{$kind}“ import nezná."),
            };
            // U úkolové mzdy posílá docházka i mzdu za přesčas.
            $amount = self::amount($item);
            if ($amount !== null) {
                self::addMoney($person, 'MZDA_UKOLOVA', $amount);
            }
        }
        $person['hours']['overtime_hours'] = $overtime;
        $person['extra'] = $extra;

        self::wages($person, self::child($employee, 'mzdy'), $note);

        $absent = 0;
        foreach (self::ABSENCE_MEANINGS as $meaning) {
            $absent += $person['hours'][$meaning] ?? 0;
        }
        $person['worked'] = max(0, $person['fund'] - $absent) + $overtime + $extra;

        return [$person, $warnings];
    }

    /**
     * Mzdové složky (`mzdy`): částky, které jde převést na složku MyÚčto,
     * a hodiny příplatků, ze kterých MyÚčto spočítá zákonný příplatek samo.
     *
     * @param array<string,mixed> $person
     * @param callable(string):void $note
     */
    private static function wages(array &$person, ?\DOMElement $wages, callable $note): void
    {
        foreach (self::items($wages) as [$kind, $item]) {
            self::costCenter($person, $item);
            $code = strtoupper(self::text($item, 'kod'));
            $attendanceCode = self::text($item, 'dochazka_kod');
            $label = self::describe($code !== '' ? $code : $attendanceCode, $item);
            $hours = self::clockMinutes(self::text($item, 'hodiny'));
            $units = self::decimal(self::text($item, 'jednic'));
            $rate = self::money(self::text($item, 'castka'));
            switch ($kind) {
                case 'mzda':
                    if ($units !== null && $rate !== null) {
                        self::addMoney($person, 'MZDA_UKOLOVA', (int) round($rate * $units));
                    } elseif ($rate !== null) {
                        // Sazba za konkrétní práci místo sazby vztahu: převod by zdvojil mzdu z odpracovaných hodin.
                        $note("mzda {$label} se sazbou " . self::kc($rate) . ' Kč za ' . self::hoursText($hours)
                            . ' h se nepřevedla. MyÚčto počítá mzdu z odpracovaných hodin a podmínek vztahu; jinou sazbu zadejte ručně.');
                    }
                    break;
                case 'premie':
                    self::fixedAmount($person, 'ODMENA', $rate, $units, "prémie {$label}", $note);
                    break;
                case 'osobni_ohodnoceni':
                    self::fixedAmount($person, self::PERSONAL_RATING, $rate, $units, "osobní ohodnocení {$label}", $note);
                    break;
                case 'priplatek':
                    self::premium($person, $code, $attendanceCode, $label, $hours, $rate, $units, $note);
                    break;
                case 'stravenkovy_pausal':
                case 'stravenkovy_pausal_bez_limitu':
                    self::mealAllowance($person, $kind === 'stravenkovy_pausal', $rate, $units, $label, $note);
                    break;
                case 'osvobozene_prijmy':
                case 'obecna_slozka':
                    $value = $rate === null ? '' : ' (' . self::kc((int) round($rate * ($units ?? 1.0))) . ' Kč)';
                    $what = $kind === 'osvobozene_prijmy' ? 'osvobozený příjem' : 'obecná složka';
                    $note("{$what} {$label}{$value} se nepřevedl, daňový režim ze souboru nejde poznat. Zadejte ho ručně na složku se správným režimem.");
                    break;
                default:
                    $note("mzdovou složku „{$kind}“ import nezná.");
            }
        }
    }

    /**
     * Příplatky: svátek, víkend a noc jsou hodiny, ze kterých MyÚčto spočítá
     * zákonný příplatek podle politiky vztahu; korunové příplatky jsou částka
     * sazba × hodiny (nebo × jednice).
     *
     * @param array<string,mixed> $person
     * @param callable(string):void $note
     */
    private static function premium(
        array &$person,
        string $code,
        string $attendanceCode,
        string $label,
        int $hours,
        ?int $rate,
        ?float $units,
        callable $note,
    ): void {
        if ($attendanceCode !== '' || $code === '') {
            $note("příplatek {$label} je určený kódem docházkového systému, jeho druh import nezná. Zadejte ho ručně.");

            return;
        }
        $hoursMeaning = match ($code) {
            'P03' => 'holiday_work_hours',
            'P04' => 'weekend_hours',
            'P07' => 'night_hours',
            default => null,
        };
        if ($hoursMeaning !== null) {
            $person['hours'][$hoursMeaning] = ($person['hours'][$hoursMeaning] ?? 0) + $hours;
            if ($rate !== null) {
                $note("příplatek {$label} má v souboru sazbu " . self::kc($rate) . ' Kč. MyÚčto ho počítá z hodin podle politiky příplatků vztahu.');
            }

            return;
        }
        $component = match ($code) {
            'P08' => 'PRIPLATEK_ZTIZENE_PROSTREDI',
            'P15' => 'ODMENA_POHOTOVOST',
            'P06', 'P09' => 'PREMIE_PRIPLATKY',
            default => null,
        };
        if ($component === null) {
            $note("příplatek {$label} za " . self::hoursText($hours) . ' h se nepřevedl (příplatek v procentech výdělku nebo neznámý druh). Zadejte ho ručně.');

            return;
        }
        if ($rate === null) {
            $note("příplatek {$label} za " . self::hoursText($hours) . ' h nemá v souboru sazbu, nepřevedl se. Zadejte částku ručně.');

            return;
        }
        $quantity = $units ?? ($hours / 60);
        self::addMoney($person, $component, (int) round($rate * $quantity));
    }

    /**
     * Stravenkový paušál: počet dnů × denní částka. U běžného paušálu je
     * osvobozená část nejvýše limit za směnu (§ 6 odst. 9 písm. b) ZDP),
     * zbytek je zdanitelný; paušál „bez limitu" je zdanitelný celý.
     *
     * @param array<string,mixed> $person
     * @param callable(string):void $note
     */
    private static function mealAllowance(array &$person, bool $limited, ?int $rate, ?float $units, string $label, callable $note): void
    {
        if ($rate === null || $units === null) {
            $note("stravenkový paušál {$label} nemá v souboru denní částku a počet dnů, nepřevedl se. Zadejte ho ručně.");

            return;
        }
        $total = (int) round($rate * $units);
        if (!$limited) {
            self::addMoney($person, 'PRISPEVEK_STRAVOVANI_ZDANITELNY', $total);

            return;
        }
        $limit = self::mealLimit((string) $person['period']);
        if ($limit === null) {
            $note("stravenkový paušál {$label}: pro období chybí limit osvobození, nepřevedl se. Zadejte ho ručně.");

            return;
        }
        $exempt = (int) round(min($rate, $limit) * $units);
        self::addMoney($person, 'PRISPEVEK_STRAVOVANI_PREVZATY', $exempt);
        if ($total > $exempt) {
            self::addMoney($person, 'PRISPEVEK_STRAVOVANI_ZDANITELNY', $total - $exempt);
        }
    }

    /** @var array<string,?int> */
    private static array $mealLimits = [];

    /** Osvobozený příspěvek na stravování za směnu v haléřích podle rulesetu období. */
    private static function mealLimit(string $period): ?int
    {
        if (!array_key_exists($period, self::$mealLimits)) {
            try {
                $value = CzechPayrollRulesets::provider()
                    ->forCalculation(PayrollRulesetDomain::IncomeTax, $period . '-01')
                    ->parameter('benefit_exemption.meal.per_shift')
                    ->value;
                self::$mealLimits[$period] = is_int($value) && $value > 0 ? $value : null;
            } catch (\Throwable) {
                self::$mealLimits[$period] = null;
            }
        }

        return self::$mealLimits[$period];
    }

    /**
     * @param array<string,mixed> $person
     * @param callable(string):void $note
     */
    private static function fixedAmount(array &$person, string $component, ?int $rate, ?float $units, string $label, callable $note): void
    {
        if ($rate === null) {
            $note("{$label} nemá v souboru částku (výši nastavuje PAMICA), nepřevedla se. Zadejte ji ručně.");

            return;
        }
        self::addMoney($person, $component, (int) round($rate * ($units ?? 1.0)));
    }

    /** @param array<string,mixed> $person */
    private static function addMoney(array &$person, string $component, int $minor): void
    {
        if ($minor !== 0) {
            $person['money'][$component] = ($person['money'][$component] ?? 0) + $minor;
        }
    }

    /**
     * Středisko z členění složky; první vyplněné vyhrává.
     *
     * @param array<string,mixed> $person
     */
    private static function costCenter(array &$person, \DOMElement $item): void
    {
        $center = self::text($item, 'stredisko');
        if ($center !== '' && $person['cost_center'] === '') {
            $person['cost_center'] = $center;
        }
    }

    /**
     * Položky seznamu, i zabalené v `slozka` (schéma ji dovoluje kvůli pořadí).
     *
     * @return list<array{0:string,1:\DOMElement}>
     */
    private static function items(?\DOMElement $parent): array
    {
        $items = [];
        foreach (self::children($parent, null) as $child) {
            $nested = $child->localName === 'slozka' ? self::children($child, null) : [$child];
            foreach ($nested as $item) {
                $items[] = [(string) $item->localName, $item];
                if (count($items) > self::MAX_ITEMS) {
                    throw new \InvalidArgumentException('Export docházky má u jedné osoby příliš mnoho položek.');
                }
            }
        }

        return $items;
    }

    private static function describe(string $code, \DOMElement $item): string
    {
        $name = self::text($item, 'nazev');
        if ($name === '') {
            $name = self::text($item, 'dochazka_nazev');
        }

        return $name === '' ? $code : "{$code} ({$name})";
    }

    /** Zkrácené trvání dne z atributu `nepritomnost` (HH:MM). */
    private static function partial(?\DOMElement $node): ?int
    {
        if ($node === null || !$node->hasAttribute('nepritomnost')) {
            return null;
        }

        return max(0, self::clockMinutes($node->getAttribute('nepritomnost')));
    }

    /** @return array<string,mixed> */
    private static function emptyPerson(string $period, string $number, string $name, string $relation): array
    {
        return [
            'period' => $period,
            'number' => trim($number),
            'name' => $name,
            'relation' => trim($relation),
            'birth_number' => '',
            'cost_center' => '',
            'fund' => 0,
            'worked' => 0,
            'extra' => 0,
            'presence' => null,
            'hours' => [],
            'money' => [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $people
     * @param list<string> $warnings
     * @return list<AttendanceSheet>
     */
    private static function sheets(string $fileName, int $fileIndex, array $people, array $warnings): array
    {
        $byPeriod = [];
        foreach ($people as $person) {
            $byPeriod[$person['period']][] = $person;
        }
        ksort($byPeriod);
        if (count($byPeriod) > 1) {
            $warnings[] = "Soubor „{$fileName}“ obsahuje docházku za více měsíců (" . implode(', ', array_keys($byPeriod))
                . '). Každý měsíc je samostatný list; importujte jen ten, který odpovídá vybranému období.';
        }
        $columns = self::columns();
        $sheets = [];
        $sheetIndex = 0;
        foreach ($byPeriod as $period => $rows) {
            $grid = [];
            foreach ($columns as $index => $column) {
                $grid[1][$index + 1] = AttendanceCell::string($column['header']);
            }
            foreach ($rows as $offset => $person) {
                $row = $offset + 2;
                foreach ($columns as $index => $column) {
                    $cell = self::cell($person, $column);
                    if ($cell !== null) {
                        $grid[$row][$index + 1] = $cell;
                    }
                }
            }
            $sheets[] = new AttendanceSheet(
                $fileName,
                $fileIndex,
                $sheetIndex,
                self::SHEET_PREFIX . ' ' . $period,
                $grid,
                count($rows) + 1,
                count($columns),
                $sheetIndex === 0 ? $warnings : [],
            );
            ++$sheetIndex;
        }

        return $sheets;
    }

    /**
     * @param array<string,mixed> $person
     * @param array{header:string,meaning:string,component_code:?string} $column
     */
    private static function cell(array $person, array $column): ?AttendanceCell
    {
        $text = match ($column['header']) {
            self::PERSONAL_NUMBER => $person['number'],
            self::NAME => $person['name'],
            self::RELATION => $person['relation'],
            self::BIRTH_NUMBER => $person['birth_number'],
            self::COST_CENTER => $person['cost_center'],
            default => null,
        };
        if ($text !== null) {
            return $text === '' ? null : AttendanceCell::string($text);
        }
        if ($column['component_code'] !== null) {
            $minor = $person['money'][$column['component_code']] ?? 0;

            return $minor === 0 ? null : AttendanceCell::number($minor / 100);
        }
        $minutes = match (true) {
            $column['header'] === self::OTHER_ABSENCE => $person['hours']['other'] ?? 0,
            $column['header'] === self::PRESENCE => $person['presence'],
            $column['header'] === self::EXTRA_HOURS => $person['extra'],
            $column['meaning'] === 'fund_hours' => $person['fund'],
            $column['meaning'] === 'worked_hours' => $person['worked'],
            default => $person['hours'][$column['meaning']] ?? 0,
        };
        // Fond a odpracováno jsou vždy; ostatní hodiny jen nenulové, prázdná buňka není nula.
        if ($minutes === null || ($minutes === 0 && !in_array($column['meaning'], ['fund_hours', 'worked_hours'], true))) {
            return null;
        }

        return AttendanceCell::number(round($minutes / 60, 4));
    }

    /** Částka položky v haléřích: sazba × jednice (nebo × 1). */
    private static function amount(\DOMElement $item): ?int
    {
        $rate = self::money(self::text($item, 'castka'));

        return $rate === null ? null : (int) round($rate * (self::decimal(self::text($item, 'jednic')) ?? 1.0));
    }

    /** Kč z textu („1200.00", „1 200,50") v haléřích. */
    private static function money(string $value): ?int
    {
        $decimal = self::decimal($value);

        return $decimal === null ? null : (int) round($decimal * 100);
    }

    private static function decimal(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $decimal = AttendanceDecimal::parseNumber($value);

        return $decimal === null ? null : (float) $decimal;
    }

    private static function kc(int $minor): string
    {
        return number_format($minor / 100, 2, ',', "\u{00A0}");
    }

    private static function hoursText(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Minuty z „HH:MM" (i přes 24 hodin) nebo z desetinných hodin. */
    private static function clockMinutes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^(-?)(\d{1,4}):([0-5]\d)(?::[0-5]\d)?$/', $value, $match) === 1) {
            $minutes = (int) $match[2] * 60 + (int) $match[3];

            return $match[1] === '-' ? -$minutes : $minutes;
        }
        $decimal = AttendanceDecimal::parseNumber($value);

        return $decimal === null ? 0 : (int) round((float) $decimal * 60);
    }

    private static function csvMinutes(AttendanceSheet $csv, int $row, int $column): int
    {
        return max(0, self::clockMinutes($csv->cell($row, $column)->textValue()));
    }

    private static function presenceMinutes(string $arrival, string $departure): int
    {
        if (preg_match('/^\d{1,2}:[0-5]\d$/', trim($arrival)) !== 1 || preg_match('/^\d{1,2}:[0-5]\d$/', trim($departure)) !== 1) {
            return 0;
        }
        $minutes = self::clockMinutes($departure) - self::clockMinutes($arrival);

        // Odchod po půlnoci (noční směna).
        return $minutes < 0 ? $minutes + 24 * 60 : $minutes;
    }

    private static function csvDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]))
                : null;
        }

        return self::date($value);
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]}");
    }

    private static function child(?\DOMElement $parent, string $name): ?\DOMElement
    {
        return self::children($parent, $name)[0] ?? null;
    }

    /** @return list<\DOMElement> */
    private static function children(?\DOMElement $parent, ?string $name): array
    {
        if ($parent === null) {
            return [];
        }
        $result = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && ($name === null || $node->localName === $name)) {
                $result[] = $node;
            }
        }

        return $result;
    }

    private static function text(?\DOMElement $parent, string $name): string
    {
        return trim((string) self::child($parent, $name)?->textContent);
    }
}
