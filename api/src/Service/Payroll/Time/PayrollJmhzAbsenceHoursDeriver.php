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
 * druh nemá v hlášení jednoznačný atribut (neplacené volno, rodičovská, PPM,
 * otcovská, náhradní volno, neomluvená absence, nerozlišené „jiné"), nebo
 * absenci, která ještě není schválená, vrátí se `supported = false` a
 * nenavrhne se NIC. Částečný návrh by v součtu 10275 tiše chyběl a hlášení by
 * bylo nepravdivé; prázdný dialog je proti tomu jen práce navíc.
 */
final class PayrollJmhzAbsenceHoursDeriver
{
    /** Prázdný rozpad — měsíc bez absencí i každý fail-closed případ. */
    private const EMPTY_BUCKETS = [
        'vacation' => 0,
        'dpn_with_employer_compensation' => 0,
        'dpn_without_employer_compensation' => 0,
        'care' => 0,
        'employee_obstacle_paid' => 0,
        'employer_obstacle' => 0,
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
     * „překážky na straně zaměstnance s náhradou mzdy/platu". Neplacenou
     * variantu aplikace neeviduje, takže se sem nemůže dostat.
     */
    private const TYPE_BUCKETS = [
        'vacation' => 'vacation',
        'dpn' => 'sickness',
        'quarantine' => 'sickness',
        'ocr' => 'care',
        'long_term_care' => 'care',
        'employee_obstacle' => 'employee_obstacle_paid',
        'employer_obstacle' => 'employer_obstacle',
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
            $firstDayFullyWorked = $this->firstDayFullyWorked($supplierId, $absence);
            if ($firstDayFullyWorked === null) {
                return self::unsupported();
            }
            $buckets['dpn_with_employer_compensation'] += $this->minutesInMonth(
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
     * @param array<string,mixed> $absence
     */
    private function firstDayFullyWorked(int $supplierId, array $absence): ?bool
    {
        $absenceId = $absence['id'] ?? null;
        if (!is_int($absenceId) || $absenceId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT first_day_fully_worked
               FROM payroll_sickness_events
              WHERE supplier_id = ? AND absence_id = ?'
        );
        $stmt->execute([$supplierId, $absenceId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value === 1;
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
             * 10276 je „počet neodpracovaných hodin s náhradou či nekrácením
             * mzdy". Patří sem dovolená (§ 222 ZP), náhrada při nemoci v okně
             * § 192 ZP i obě překážky v práci s náhradou. Nepatří sem nemoc za
             * oknem (platí ji dávka ČSSZ, ne zaměstnavatel) ani ošetřovné.
             * Kontrola 23 hlášení vyžaduje 10276 >= 10279, což tenhle součet
             * drží z definice.
             */
            'paid' => $buckets['vacation']
                + $buckets['dpn_with_employer_compensation']
                + $buckets['employee_obstacle_paid']
                + $buckets['employer_obstacle'],
        ];
    }
}
