<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Service\Payroll\Absence\AbsenceHolidayTreatment;
use PDO;

/**
 * Neodpracované hodiny měsíce rozpadlé podle druhu evidované absence.
 *
 * ## Proč to existuje
 *
 * Podmíněné bloky měsíčního hlášení (atributy 10275–10280 a 10471/10472) se
 * dosud nenavrhovaly vůbec: účetní musela osm čísel opsat ručně z evidence,
 * kterou aplikace už má. Když je nechala prázdná a měsíc schválila jako
 * bezabsenční, ELDP to o krok dál shodil na `jmhz_eldp_work_summary_mismatch`
 * — a jedna dovolená tak zablokovala hlášení celé firmy.
 *
 * ## Odkud se hodiny berou
 *
 * Ze STEJNÉ mechaniky, ze které se počítala náhrada mzdy:
 * {@see PayrollAbsenceRepository::publishedShiftSegments()} vrací minuty
 * publikovaných směn spadajících do absence, včetně omezení částečně
 * zameškaných směn (`partial_first_minutes` / `partial_last_minutes`).
 * Zacházení se svátkem je proto u každého druhu doslova to, které při
 * schválení absence rozhodlo o penězích:
 *
 * | druh                  | zacházení se svátkem        | proč                       |
 * |-----------------------|-----------------------------|----------------------------|
 * | `vacation`            | `ExcludeFromLeave`          | § 219 odst. 1 ZP           |
 * | `dpn`, `quarantine`   | `CompensateSickness`        | § 192 odst. 1 ZP           |
 * | ostatní podporované   | `Ignore`                    | bez směny nejsou hodiny    |
 *
 * Kdyby se hodiny počítaly jinak, hlásily by se ČSSZ jiné hodiny, než ze
 * kterých vznikla náhrada — a rozpor by se ukázal až u kontroly.
 *
 * U nemoci se rozsah dělí oknem náhrady mzdy podle § 192 ZP: dny uvnitř okna
 * jdou do 10278 (náhradu poskytuje zaměstnavatel), dny za ním do 10277.
 * Příznak „první den odpracován celý" se čte z {@see payroll_sickness_events},
 * aby se výpočet trefil do téhož okna jako schválený výpočet náhrady.
 *
 * ## Fail-closed
 *
 * Navrhuje se jen to, co je doložené. Jakmile měsíc obsahuje absenci, jejíž
 * druh nemá v hlášení doložený rozpad (nerozlišené „jiné"), nebo absenci,
 * která ještě není schválená, vrátí se `supported = false` a
 * nenavrhne se NIC. Částečný návrh by v součtu 10275 tiše chyběl a hlášení by
 * bylo nepravdivé; prázdný dialog je proti tomu jen práce navíc.
 */
final class PayrollJmhzAbsenceHoursDeriver
{
    /**
     * Prázdný rozpad — měsíc bez absencí i každý fail-closed případ.
     *
     * Prvních šest kbelíků má vlastní atribut hlášení (10277–10280, 10471,
     * 10472). Zbytek žádný atribut NEMÁ: peněžitá pomoc v mateřství, otcovská,
     * rodičovská dovolená, neplacené volno a neomluvená absence se ČSSZ
     * nevykazují po hodinách, ale po DNECH v evidenčním listu. Hodiny se u nich
     * počítají jen proto, aby šlo dny doložit proti druhému zmrazenému zdroji
     * (viz {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceBuilder}),
     * a aby do úhrnu 10275 nechyběly.
     */
    private const EMPTY_BUCKETS = [
        'vacation' => 0,
        'dpn_with_employer_compensation' => 0,
        'dpn_without_employer_compensation' => 0,
        'care' => 0,
        'employee_obstacle_paid' => 0,
        'employer_obstacle' => 0,
        'maternity' => 0,
        'paternity' => 0,
        'parental' => 0,
        'unpaid_leave' => 0,
        'unexcused' => 0,
        'compensatory_time_off' => 0,
    ];

    /**
     * Druh absence → blok hlášení.
     *
     * `ocr` i `long_term_care` míří do 10280 shodně s
     * {@see \MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver},
     * které je do vyloučených dob taky slučuje.
     *
     * `employee_obstacle` je v aplikaci vždy PLACENÁ překážka — validátor jí
     * vynucuje schválený snapshot průměru a sazbu 100 % — a 10471 je právě
     * „překážky na straně zaměstnance s náhradou mzdy/platu". Neplacená
     * varianta má vlastní druh `employee_obstacle_unpaid` a do 10471 nejde.
     */
    private const TYPE_BUCKETS = [
        'vacation' => 'vacation',
        'dpn' => 'sickness',
        'quarantine' => 'sickness',
        'ocr' => 'care',
        'long_term_care' => 'care',
        'employee_obstacle' => 'employee_obstacle_paid',
        'employer_obstacle' => 'employer_obstacle',
        'ppm' => 'maternity',
        'paternity' => 'paternity',
        'parental' => 'parental',
        'unpaid_leave' => 'unpaid_leave',
        // Pracovní volno bez náhrady mzdy — výkon veřejné funkce (§ 200 až 202
        // ZP) a neplacená překážka na straně zaměstnance. Do 10471 nepatří:
        // ten je podle datového slovníku „překážky na straně zaměstnance
        // S NÁHRADOU mzdy/platu". Hodiny proto jdou jen do úhrnu 10275 a v ELDP
        // se dokládají stejně jako neplacené volno.
        'public_function' => 'unpaid_leave',
        'employee_obstacle_unpaid' => 'unpaid_leave',
        // Vztah trvající po neplatném skončení bez přiznané náhrady mzdy
        // (§ 16 odst. 4 písm. j) zákona č. 155/1995 Sb.): směny, které na tu
        // dobu připadnou, jsou neodpracované hodiny bez náhrady, jen v úhrnu 10275.
        'invalid_termination' => 'unpaid_leave',
        'unexcused' => 'unexcused',
        // Náhradní volno za přesčas: mzda za dobu čerpání nepřísluší
        // (§ 114 odst. 1 ZP), takže hodiny jdou jen do úhrnu 10275, ne do 10276.
        'compensatory_time_off' => 'compensatory_time_off',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAbsenceRepository $absences,
    ) {}

    /**
     * @param list<array<string,mixed>> $absences absence měsíce tak, jak je
     *        načetl {@see PayrollJmhzWorkMonthSummaryBuilder}
     * @param string $periodEnd první den následujícího měsíce (exkluzivně)
     * @return array{supported:bool,minutes:array<string,int>,total:int,paid:int}
     */
    public function derive(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
        array $absences,
    ): array {
        if ($absences === []) {
            return self::result(self::EMPTY_BUCKETS);
        }
        $lastDay = (new \DateTimeImmutable($periodEnd))->modify('-1 day')->format('Y-m-d');
        $buckets = self::EMPTY_BUCKETS;
        foreach ($absences as $absence) {
            $type = $absence['absence_type'] ?? null;
            $bucket = is_string($type) ? (self::TYPE_BUCKETS[$type] ?? null) : null;
            if ($bucket === null
                || ($absence['status'] ?? null) !== 'approved'
                || ($absence['correction_pending'] ?? false) === true
            ) {
                return self::unsupported();
            }
            $row = $absence + [
                'supplier_id' => $supplierId,
                'employment_id' => $employmentId,
            ];
            if ($bucket !== 'sickness') {
                $buckets[$bucket] += $this->minutesInMonth(
                    $this->absences->publishedShiftSegments(
                        $row,
                        false,
                        $bucket === 'vacation'
                            ? AbsenceHolidayTreatment::ExcludeFromLeave
                            : AbsenceHolidayTreatment::Ignore,
                    ),
                    $periodStart,
                    $lastDay,
                );
                continue;
            }
            $event = $this->sicknessEvent($supplierId, $absence);
            if ($event === null) {
                return self::unsupported();
            }
            $firstDayFullyWorked = $event['first_day_fully_worked'];
            // DPN bez nároku (§ 15a zák. č. 187/2006 Sb.): náhradu zaměstnavatel
            // neposkytuje ani v okně § 192 ZP, všechny hodiny jdou do 10277.
            $windowBucket = $event['eligible']
                ? 'dpn_with_employer_compensation'
                : 'dpn_without_employer_compensation';
            $buckets[$windowBucket] += $this->minutesInMonth(
                $this->absences->publishedShiftSegments(
                    $row,
                    $firstDayFullyWorked,
                    AbsenceHolidayTreatment::CompensateSickness,
                ),
                $periodStart,
                $lastDay,
            );
            $buckets['dpn_without_employer_compensation'] += $this->minutesInMonth(
                $this->absences->publishedShiftSegmentsBeyondSicknessWindow(
                    $row,
                    $firstDayFullyWorked,
                ),
                $periodStart,
                $lastDay,
            );
        }

        return self::result($buckets);
    }

    /**
     * Byl první den nemoci odpracován celý?
     *
     * Odpověď dala účetní při schvalování absence a je zmrazená ve výpočtu
     * náhrady. Kdyby se hádala znovu, posunulo by se okno § 192 o den a
     * hlášené hodiny by přestaly sedět na vyplacenou náhradu. Chybí-li výpočet,
     * vrací se `null` a měsíc zůstane bez návrhu.
     *
     * Spolu s ním se čte i nárok: DPN bez nároku náhradu nemá ani v okně.
     *
     * @param array<string,mixed> $absence
     * @return array{first_day_fully_worked:bool,eligible:bool}|null
     */
    private function sicknessEvent(int $supplierId, array $absence): ?array
    {
        $absenceId = $absence['id'] ?? null;
        if (!is_int($absenceId) || $absenceId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT first_day_fully_worked, insurance_eligibility_confirmed
               FROM payroll_sickness_events
              WHERE supplier_id = ? AND absence_id = ?'
        );
        $stmt->execute([$supplierId, $absenceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? [
            'first_day_fully_worked' => (int) $row['first_day_fully_worked'] === 1,
            'eligible' => (int) $row['insurance_eligibility_confirmed'] === 1,
        ] : null;
    }

    /**
     * Týž rozpad jako {@see derive()}, ale z pracovního kalendáře vztahu místo
     * publikovaných směn. Pro měsíc s docházkou ze souhrnu importu: směny se v něm
     * nepublikují a náhrada mzdy se počítá kalendářní cestou, takže hodiny
     * nepřítomností musí vzniknout ze stejného fondu. Hranice nepřítomnosti a okno
     * náhrady při nemoci (§ 192 ZP) bere z {@see PayrollAbsenceRepository::absenceBounds()},
     * jedné pravdy pro náhradu, krácení i hlášení.
     *
     * @param list<array<string,mixed>> $absences
     * @param callable(string,bool):int $dayMinutes plánované minuty dne podle kalendáře;
     *        druhý argument říká, zda se počítá i svátek (u nemoci ano, § 192 odst. 1 ZP)
     * @return array{supported:bool,minutes:array<string,int>,total:int,paid:int}
     */
    public function deriveFromCalendar(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
        array $absences,
        callable $dayMinutes,
    ): array {
        $lastDay = (new \DateTimeImmutable($periodEnd))->modify('-1 day')->format('Y-m-d');
        $buckets = self::EMPTY_BUCKETS;
        $sum = static function (\DateTimeImmutable $from, \DateTimeImmutable $to, bool $holidays, array $caps = []) use ($periodStart, $lastDay, $dayMinutes): int {
            $minutes = 0;
            for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
                $iso = $day->format('Y-m-d');
                if ($iso >= $periodStart && $iso <= $lastDay) {
                    $minutes += isset($caps[$iso]) ? min($dayMinutes($iso, $holidays), $caps[$iso]) : $dayMinutes($iso, $holidays);
                }
            }

            return $minutes;
        };
        foreach ($absences as $absence) {
            $type = $absence['absence_type'] ?? null;
            $bucket = is_string($type) ? (self::TYPE_BUCKETS[$type] ?? null) : null;
            if ($bucket === null
                || ($absence['status'] ?? null) !== 'approved'
                || ($absence['correction_pending'] ?? false) === true
            ) {
                return self::unsupported();
            }
            $row = $absence + ['supplier_id' => $supplierId, 'employment_id' => $employmentId];
            if ($bucket !== 'sickness') {
                $bounds = $this->absences->absenceBounds($row, false);
                // Zameškaná část směny prvního a posledního dne, stejně jako
                // {@see PayrollAbsenceRepository::publishedShiftSegments()}.
                $caps = [];
                if (($absence['partial_first_minutes'] ?? null) !== null) {
                    $caps[(string) $absence['date_from']] = (int) $absence['partial_first_minutes'];
                }
                if (($absence['partial_last_minutes'] ?? null) !== null) {
                    $last = (string) $absence['date_to'];
                    $caps[$last] = min($caps[$last] ?? PHP_INT_MAX, (int) $absence['partial_last_minutes']);
                }
                $buckets[$bucket] += $sum($bounds['from'], $bounds['to'], false, $caps);
                continue;
            }
            // Navazující část dlouhé nemoci (pokračování z předchozího měsíce) vlastní
            // událost nemá. Za oknem náhrady na nároku ani na prvním dni nezáleží, takže
            // jde odvodit; zasahuje-li do měsíce okno, bez události se nehádá.
            $event = $this->sicknessEvent($supplierId, $absence);
            $bounds = $this->absences->absenceBounds($row, $event['first_day_fully_worked'] ?? false);
            $window = $sum($bounds['from'], $bounds['window_to'], true);
            if ($event === null && $window > 0) {
                return self::unsupported();
            }
            $windowBucket = ($event['eligible'] ?? false) ? 'dpn_with_employer_compensation' : 'dpn_without_employer_compensation';
            $buckets[$windowBucket] += $window;
            $buckets['dpn_without_employer_compensation'] += $sum(
                max($bounds['from'], $bounds['window_to']->modify('+1 day')),
                $bounds['to'],
                true,
            );
        }

        return self::result($buckets);
    }

    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     */
    private function minutesInMonth(array $segments, string $from, string $to): int
    {
        $minutes = 0;
        foreach ($segments as $segment) {
            $localDate = (string) $segment['local_date'];
            if ($localDate < $from || $localDate > $to) {
                continue;
            }
            $minutes += (int) $segment['eligible_minutes'];
        }

        return $minutes;
    }

    /** @return array{supported:bool,minutes:array<string,int>,total:int,paid:int} */
    private static function unsupported(): array
    {
        return [
            'supported' => false,
            'minutes' => self::EMPTY_BUCKETS,
            'total' => 0,
            'paid' => 0,
        ];
    }

    /**
     * @param array<string,int> $buckets
     * @return array{supported:bool,minutes:array<string,int>,total:int,paid:int}
     */
    private static function result(array $buckets): array
    {
        return [
            'supported' => true,
            'minutes' => $buckets,
            'total' => array_sum($buckets),
            /*
             * Neodpracované hodiny, za které náleží náhrada mzdy nebo se mzda
             * nekrátí: dovolená (§ 222 ZP), náhrada při nemoci v okně § 192 ZP
             * i obě překážky v práci s náhradou. Nepatří sem nemoc za oknem
             * (platí ji dávka ČSSZ, ne zaměstnavatel) ani ošetřovné. V tomhle
             * rozsahu je potřebuje sleva zaměstnavatele podle § 7a ZPSZ.
             *
             * Atribut 10276 hlášení je užší (bez nemoci) a hodiny nemoci
             * s náhradou patří do 10471; převod dělá jen serializér, viz
             * JmhzScenario1XmlSerializer::reportedUnworkedHours(). Kontrola 23
             * (10276 >= 10279) platí pro oba rozsahy.
             */
            'paid' => $buckets['vacation']
                + $buckets['dpn_with_employer_compensation']
                + $buckets['employee_obstacle_paid']
                + $buckets['employer_obstacle'],
        ];
    }
}
