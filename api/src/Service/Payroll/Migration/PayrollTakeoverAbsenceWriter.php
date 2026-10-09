<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceOverlapException;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollLeaveRepository;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\Absence\AbsenceRuleset;
use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseFromAbsenceService;

/**
 * Zápis převzatých nepřítomností a zůstatku dovolené ({@see PayrollTakeoverEmployment}),
 * společný pro všechny převody mezd z předchozího systému.
 *
 * Nepřítomnosti se zapisují s daty od a do a schvalují se jen ty, které schválení pustí.
 * Dobu, kterou už nese souhrn z importu docházky, převod nezapisuje podruhé
 * ({@see self::IMPORT_SUMMARY_ABSENCE_HOURS}).
 */
final class PayrollTakeoverAbsenceWriter
{
    /**
     * Druh nepřítomnosti => hodiny souhrnu importu docházky, které tutéž dobu nesou.
     * Zrcadlí `PayrollWageProrationService::IMPORT_SUMMARY_TITLES`: co je v souhrnu,
     * má jediný zdroj v importu. Peněžitá pomoc v mateřství, rodičovská a dlouhodobé
     * ošetřovné v souhrnu nejsou, ty zapisuje převod dál.
     *
     * Rozhoduje se podle SKUTEČNÝCH hodin souhrnu ({@see self::carriedByImportSummary()}),
     * ne podle druhu, a to je i cesta pro nemoc, ošetřovné, otcovskou, neplacené volno
     * a neomluvenou absenci: ty do měsíčního sešitu vůbec nejdou, pokud je zdroj nese
     * s daty (u PAMICA `MZneprit`, {@see \MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog::absenceNeedsDates()}),
     * souhrn pro ně proto hodiny nemá a zapíšou se tudy s daty. Bez dat zůstanou
     * v souhrnu a tahle tabulka je z datovaného zápisu vyloučí, aby se doba nevedla dvakrát.
     */
    public const IMPORT_SUMMARY_ABSENCE_HOURS = [
        'vacation' => ['vacation_hours'],
        'dpn' => ['sick_hours'],
        'quarantine' => ['sick_hours'],
        'ocr' => ['care_hours'],
        'paternity' => ['paternity_hours'],
        'employee_obstacle' => ['doctor_hours', 'obstacle_employee_hours'],
        'employer_obstacle' => ['obstacle_employer_hours'],
        'unpaid_leave' => ['unpaid_leave_hours'],
        'unexcused' => ['unexcused_hours'],
        'compensatory_time_off' => ['compensatory_time_off_hours'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAbsenceRepository $absences,
        private readonly PayrollAbsenceValidator $absenceValidator,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly PayrollTimeRepository $time,
        private readonly PayrollLeaveRepository $leave,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly SicknessCaseFromAbsenceService $sicknessCases,
        private readonly PayrollModuleStateRepository $moduleState,
    ) {}

    /**
     * Nepřítomnosti s daty od a do; u peněžité pomoci v mateřství i den porodu.
     * Schvalují se jen ty, které schválení pustí (u náhrady z průměru musí existovat
     * schválený průměr); ostatní zůstanou zapsané k rozhodnutí.
     *
     * Nepřítomnost, kterou zdroj nenese s daty, se nezapisuje: den od ani do se
     * z hodin dopočítat nedá. Její hodiny proto zůstávají v měsíčním sešitu a protokol
     * ji hlásí s osobním číslem.
     *
     * @return array<string,int>
     */
    public function absences(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy, PayrollTakeoverRunState $state): array
    {
        $absences = $employment->absences;
        $number = $employment->personalNumber;
        $counts = [];
        $undated = $employment->absencesWithoutDates;
        if ($undated > 0) {
            $state->absencesWithoutDates[$number] = $undated;
            $counts['absences_without_dates'] = $undated;
        }
        if ($absences === []) {
            return $counts;
        }
        // Doplňuje se po záznamech: převody běží po letech a každý rok přináší další
        // nepřítomnosti téhož vztahu. Přeskočí se jen ta, která už je zapsaná se stejným
        // druhem a daty (opakovaný převod nic nezdvojí); jiná nepřítomnost v týchž dnech
        // (ruční, z jiného kroku) se nezapíše a protokol ji hlásí jako překryv.
        $written = 0;
        $overlaps = 0;
        $approved = 0;
        $fromImport = 0;
        $already = 0;
        $rejected = 0;
        $continued = 0;
        /** @var array<string,int> $sicknessCases */
        $sicknessCases = [];
        foreach (self::splitAtQuarters(self::mergedAbsences($absences, $overlaps)) as $absence) {
            if ($this->recorded($supplierId, $employmentId, $absence)) {
                $already++;
                continue;
            }
            // Měsíc, jehož docházku nese souhrn z importu, má tytéž hodiny i náhradu už z něj.
            // Zapsat k němu ještě nepřítomnost s daty znamená vést jeden údaj dvakrát a krácení
            // měsíční mzdy se pak neprovede vůbec: `PayrollWageProrationService` takový měsíc
            // odmítne měřit. Nestačí nechat nepřítomnost neschválenou, protože se do překážky
            // počítá i nerozhodnutá.
            $type = (string) $absence['type'];
            if ($this->carriedByImportSummary($supplierId, $employmentId, $type, (string) $absence['from'], (string) $absence['to'], $state)) {
                $state->absencesFromImport[$type] = ($state->absencesFromImport[$type] ?? 0) + 1;
                $fromImport++;
                continue;
            }
            $body = [
                'employment_id' => $employmentId,
                'absence_type' => $absence['type'],
                'date_from' => $absence['from'],
                'date_to' => $absence['to'],
                'note' => $policy->note('nepřítomnost ze zpracovaných mezd.'),
            ];
            if ($absence['type'] === 'ppm' && is_string($absence['childbirth'])) {
                $body['expected_childbirth_date'] = $absence['childbirth'];
                $body['childbirth_date'] = $absence['childbirth'];
            }
            try {
                $created = $this->absences->create($supplierId, $this->absenceValidator->absence($body, takeover: true), $userId);
            } catch (PayrollAbsenceOverlapException) {
                // Týž den už nepřítomnost má (jiný druh z téže mzdy): druhý zápis se vynechá.
                $overlaps++;
                continue;
            } catch (\DomainException|\InvalidArgumentException) {
                // Nepřípustné datum nebo uzavřený rok: nechá se na účetní, protokol ji spočítá.
                $rejected++;
                continue;
            }
            $written++;
            $carried = $this->continuedWindowDays($supplierId, $employmentId, $absence, (int) $created['id'], $policy);
            if ($carried > 0) {
                $created = $this->absences->setSicknessWindowCarriedDays($supplierId, (int) $created['id'], $carried);
                $continued++;
            }
            // Schvaluje se vše, co schválení pustí: druhy bez náhrady z průměru rovnou,
            // ostatní tehdy, když čtvrtletí už má schválený průměr. Nerozhodnutá
            // nepřítomnost jinak blokuje schválení pracovního měsíce.
            // Historická nepřítomnost z doby před prvním rulesetem náhrad se schvaluje
            // i bez průměru: průměr chrání výpočet náhrady, který za takové období
            // nevznikne nikdy (běh ho bez rulesetu nespočítá, náhradu nese převzatá
            // mzda), a průměr pro takový rok ani založit nejde. Neschválenou by ELDP
            // při skládání vyloučených dob převzatého měsíce nevidělo.
            $quarter = (int) ceil(((int) substr((string) $absence['from'], 5, 2)) / 3);
            $historical = $this->absenceValidator->predatesRulesets((string) $absence['to']);
            $hasAverage = !$historical
                && $this->averages->findApproved($supplierId, $employmentId, (int) substr((string) $absence['from'], 0, 4), $quarter) !== null;
            if ($historical || !in_array($absence['type'], PayrollAbsenceValidator::TYPES_REQUIRING_AVERAGE, true) || $hasAverage) {
                try {
                    $this->absences->decide($supplierId, (int) $created['id'], (int) $created['row_version'], 'approved', $userId);
                    $approved++;
                } catch (\DomainException|\InvalidArgumentException) {
                    // Neschválená zůstane k rozhodnutí účetní; případ dávky se
                    // přesto založí níž, událost u předchozího programu proběhla.
                }
            }
            $sicknessCase = $this->sicknessCase($supplierId, (int) $created['id'], $userId, $policy);
            if ($sicknessCase !== null) {
                $sicknessCases[$sicknessCase] = ($sicknessCases[$sicknessCase] ?? 0) + 1;
            }
        }
        if ($overlaps > 0) {
            $state->absenceOverlaps[$number] = $overlaps;
            $counts['absences_overlap'] = $overlaps;
        }
        if ($rejected > 0) {
            $state->absencesRejected[$number] = ($state->absencesRejected[$number] ?? 0) + $rejected;
            $counts['absences_rejected'] = $rejected;
        }
        if ($continued > 0) {
            $counts['sickness_window_continued'] = $continued;
        }
        if ($approved > 0) {
            $counts['absences_approved'] = $approved;
        }
        if ($fromImport > 0) {
            $counts['absences_from_import'] = $fromImport;
        }
        if ($already > 0) {
            $counts['absences_existing'] = $already;
        }
        foreach ($sicknessCases as $key => $count) {
            $counts[$key] = $count;
        }
        return $written > 0 ? $counts + ['absences' => $written] : $counts;
    }

    /**
     * Případ dávky k převzaté nepřítomnosti, která trvá aspoň do prvního měsíce
     * vedení mezd v MyÚčtu (§ 97 odst. 2 a 3 zák. č. 187/2006 Sb.). Převod
     * schvaluje nepřítomnosti mimo akci schválení, takže by jinak případ nevznikl
     * a lhůtu HZUPN k návratu do práce by nikdo nehlídal. Den vzniku se odvodí
     * z nepřítomnosti a dnů okna vyčerpaných předchozím plátcem.
     *
     * Vrací klíč počtu do protokolu, nebo `null`, když z nepřítomnosti případ
     * neplyne.
     */
    private function sicknessCase(int $supplierId, int $absenceId, ?int $userId, PayrollTakeoverPolicy $policy): ?string
    {
        $startPeriod = $this->moduleState->get($supplierId)['start_period'] ?? null;
        $absence = $this->absences->find($supplierId, $absenceId);
        if (!is_string($startPeriod) || $startPeriod === '' || $absence === null) {
            return null;
        }
        $result = $this->sicknessCases->onTakenOver(
            $supplierId,
            $absence,
            $userId,
            $startPeriod,
            null,
            $policy->sourceKey . ':absence:' . $absenceId,
        );
        if ($result === null) {
            return null;
        }

        return match ($result['outcome']) {
            'created' => 'sickness_cases',
            'skipped' => 'sickness_cases_skipped',
            default => 'sickness_cases_existing',
        };
    }

    /**
     * Dny okna náhrady mzdy podle § 192 ZP, které neschopnost vyčerpala ještě před touto
     * nepřítomností, pokud je pokračováním převzaté neschopnosti téhož vztahu.
     *
     * Převod po letech (PREMIER) rozdělí neschopnost přes konec roku na dvě nepřítomnosti:
     * jedna končí 31. 12., druhá začíná 1. 1. Schválení v aplikaci řetěz navazujících
     * částí dopočítá samo ({@see PayrollAbsenceRepository::sicknessChain()}), převod ale
     * schvaluje bez výpočtu náhrady a navazuje i na neschválenou část, proto se druhá
     * naváže hned tady, tímtéž vzorcem ({@see PayrollAbsenceRepository::continuedWindowDays()}):
     * počtem vyčerpaných dnů okna (`sickness_window_carried_days`, stejná cesta jako
     * rozpracovaný případ z PAMICA).
     *
     * Navazuje se jen na nepřítomnost, kterou zapsal převod téhož zdroje a která končí den
     * před začátkem této; ručně zapsaná sousední neschopnost může být nový případ.
     * Řetěz více navazujících nepřítomností se sečte přes vyčerpané dny předchůdce.
     *
     * @param array<string,mixed> $absence
     */
    private function continuedWindowDays(int $supplierId, int $employmentId, array $absence, int $createdId, PayrollTakeoverPolicy $policy): int
    {
        if (!PayrollAbsenceRepository::isSickness(['absence_type' => $absence['type']])) {
            return 0;
        }
        $from = (string) $absence['from'];
        $previousTo = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT date_from, sickness_window_carried_days FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ? AND absence_type = ? AND date_to = ? AND id <> ?
                AND status NOT IN ('cancelled', 'rejected') AND note LIKE ?
              ORDER BY date_from LIMIT 1"
        );
        $stmt->execute([$supplierId, $employmentId, (string) $absence['type'], $previousTo, $createdId,
            str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $policy->note('')) . '%']);
        $previous = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($previous === false) {
            return 0;
        }
        return PayrollAbsenceRepository::continuedWindowDays(
            (string) $previous['date_from'],
            PayrollAbsenceRepository::carriedWindowDays($previous),
            $from,
            AbsenceRuleset::forSicknessWindow($this->rulesets, (string) $previous['date_from'])->sicknessWindowCalendarDays(),
        );
    }

    /**
     * Je tatáž nepřítomnost (druh a data) u vztahu už zapsaná? Zrušená ani zamítnutá se
     * nepočítá.
     *
     * @param array<string,mixed> $absence
     */
    private function recorded(int $supplierId, int $employmentId, array $absence): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ? AND absence_type = ? AND date_from = ? AND date_to = ?
                AND status NOT IN ('cancelled', 'rejected') LIMIT 1"
        );
        $stmt->execute([$supplierId, $employmentId, (string) $absence['type'], (string) $absence['from'], (string) $absence['to']]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Zůstatek dovolené jako převod (`carryover`) do knihy dovolené.
     *
     * Zapisuje se JEN zůstatek, ne čerpání. Ruční `taken` za období před zahájením vedení
     * mezd kniha dovolené sice přijmout umí, ale v převáděném zůstatku je čerpání už
     * odečtené, takže by se počítalo dvakrát; kdo chce čerpání i po jednotlivých položkách,
     * zadá je ručně a zůstatek si o ně sníží. Záporný zůstatek se nepřevádí vůbec -
     * přečerpání je rozhodnutí zaměstnavatele, ne údaj k opsání.
     *
     * Účinnost má den, od kterého mzdy vede MyÚčto; od té chvíle se z knihy odečítá.
     *
     * @return array<string,int>
     */
    public function leaveCarryover(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy, PayrollTakeoverRunState $state): array
    {
        $leave = $employment->leave;
        if (!is_array($leave)) {
            if ($employment->leaveShared) {
                $state->leaveShared++;
            }
            return [];
        }
        $state->leaveTakenHours += (int) round((float) $leave['taken_hours']);
        $year = (int) $leave['year'];
        $minutes = (int) round(((float) $leave['balance_hours']) * 60);
        if ($minutes <= 0) {
            return $minutes < 0 ? ['leave_overdrawn' => 1] : [];
        }
        $existing = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM payroll_leave_ledger
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = ? AND entry_type = 'carryover'"
        );
        $existing->execute([$supplierId, $employmentId, $year]);
        if ((int) $existing->fetchColumn() > 0) {
            return ['leave_existing' => 1];
        }
        $from = $employment->transferStart . '-01';
        if (substr($from, 0, 4) !== (string) $year) {
            $from = sprintf('%04d-01-01', $year);
        }
        $daily = $leave['daily_hours'] === null ? null : (float) $leave['daily_hours'];
        $reason = $policy->note('zůstatek dovolené ke dni převodu, ' . PayrollTakeoverFormat::decimal((float) $leave['balance_hours']) . ' h'
            . ($leave['balance_days'] === null || $daily === null
                ? ''
                : ' (' . PayrollTakeoverFormat::decimal((float) $leave['balance_days']) . ' dne při úvazku ' . PayrollTakeoverFormat::decimal($daily) . ' h denně)')
            . ($leave['from_days'] === true ? '; export nesl jen dny, hodiny dopočteny denním úvazkem vztahu' : '')
            . '.');
        $this->leave->appendManual($supplierId, $employmentId, $year, $from, 'carryover', $minutes, $reason, $userId);
        $state->leaveTransferred++;
        return ['leave_carryover' => 1];
    }

    /**
     * Čerpání dovolené po měsících ({@see PayrollTakeoverEmployment::$leaveTaken}) pro zdroj,
     * který zůstatek nenese (měsíční hlášení JMHZ vykazuje jen hodiny dovolené). Každý měsíc
     * je jedna položka `taken` v knize dovolené s doložením původu, s účinností posledního
     * dne měsíce; kniha ji pustí jen před zahájením vedení mezd v MyÚčtu. Zůstatek pak
     * vyjde z nároku, který určí účetní, minus převzaté čerpání.
     *
     * Vylučuje se s {@see self::leaveCarryover()}: zůstatek ze zdroje už čerpání odečtené má.
     * Položku za měsíc, která v knize už je, zápis nemění; liší-li se, ohlásí to.
     *
     * @return array<string,int>
     */
    public function leaveTaken(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        if ($employment->leaveTaken === [] || $employment->leave !== null) {
            return [];
        }
        $written = 0;
        $existing = 0;
        $different = [];
        $ledgers = [];
        foreach ($employment->leaveTaken as $month) {
            $minutes = (int) $month['minutes'];
            if ($minutes <= 0) {
                continue;
            }
            $period = (string) $month['period'];
            $year = (int) substr($period, 0, 4);
            $marker = self::leaveTakenMarker($policy, $period);
            $ledgers[$year] ??= $this->leave->list($supplierId, $employmentId, $year);
            $found = null;
            foreach ($ledgers[$year] as $entry) {
                if ($entry['entry_type'] === 'taken' && str_contains((string) $entry['reason'], $marker)) {
                    $found = (int) $entry['minutes_delta'];
                    break;
                }
            }
            if ($found !== null) {
                if ($found === -$minutes) {
                    $existing++;
                } else {
                    $different[] = PayrollTakeoverFormat::czechPeriod($period);
                }
                continue;
            }
            $end = (new \DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d');
            $this->leave->appendManual(
                $supplierId,
                $employmentId,
                $year,
                $end,
                'taken',
                -$minutes,
                $marker . ': ' . PayrollTakeoverFormat::decimal($minutes / 60) . ' h.',
                $userId,
                null,
                $policy->label,
            );
            $written++;
        }
        if ($different !== []) {
            throw new \DomainException('v knize dovolené už je převzaté čerpání za ' . implode(', ', $different)
                . ' s jinou hodnotou; opravte ho ručně.');
        }
        $counts = $written > 0 ? ['leave_taken' => $written] : [];
        return $existing > 0 ? $counts + ['leave_taken_existing' => $existing] : $counts;
    }

    private static function leaveTakenMarker(PayrollTakeoverPolicy $policy, string $period): string
    {
        return 'Čerpání za ' . PayrollTakeoverFormat::czechPeriod($period) . ' podle ' . $policy->label;
    }

    /**
     * Nese tutéž dobu souhrn z importu docházky? Rozhoduje se podle skutečných hodin
     * souhrnu daného měsíce, ne podle druhu nepřítomnosti: podklady se firmu od firmy
     * liší a co v souhrnu opravdu je, má mít jediný zdroj. Nepřítomnost přes víc měsíců
     * se vynechá jen tehdy, když ji nese souhrn v každém z nich, jinak by se část
     * evidence ztratila.
     */
    private function carriedByImportSummary(int $supplierId, int $employmentId, string $type, string $from, string $to, PayrollTakeoverRunState $state): bool
    {
        $meanings = self::IMPORT_SUMMARY_ABSENCE_HOURS[$type] ?? [];
        if ($meanings === []) {
            return false;
        }
        $cursor = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($from, 0, 7) . '-01');
        if ($cursor === false) {
            return false;
        }
        $last = substr($to, 0, 7);
        while ($cursor->format('Y-m') <= $last) {
            $month = $cursor->format('Y-m');
            $key = $employmentId . '|' . $month;
            if (!array_key_exists($key, $state->importSummaries)) {
                $summary = $this->time->importSummary($supplierId, $employmentId, $month . '-01');
                $state->importSummaries[$key] = $summary === null ? null : $summary['values'];
            }
            $values = $state->importSummaries[$key];
            $carried = false;
            foreach ($meanings as $meaning) {
                if ($values !== null && (int) ($values[$meaning] ?? 0) > 0) {
                    $carried = true;
                    break;
                }
            }
            if (!$carried) {
                return false;
            }
            $cursor = $cursor->modify('+1 month');
        }

        return true;
    }

    /**
     * Nepřítomnost s náhradou z průměru, která přechází přes konec kalendářního čtvrtletí
     * (typicky dovolená rozepsaná po měsících a spojená {@see self::mergedAbsences()}),
     * se rozdělí na hranici čtvrtletí. Náhrada se počítá z průměru zjištěného k prvnímu dni
     * čtvrtletí (§ 354 odst. 1 ZP), evidence proto celou nepřijme
     * ({@see PayrollAbsenceValidator::TYPES_WITHIN_QUARTER}) a bez rozdělení by se nezapsala.
     *
     * @param list<array<string,mixed>> $absences
     * @return list<array<string,mixed>>
     */
    private static function splitAtQuarters(array $absences): array
    {
        $out = [];
        foreach ($absences as $absence) {
            if (!in_array($absence['type'], PayrollAbsenceValidator::TYPES_WITHIN_QUARTER, true)) {
                $out[] = $absence;
                continue;
            }
            $from = new \DateTimeImmutable((string) $absence['from']);
            $to = (string) $absence['to'];
            while (true) {
                $month = (int) $from->format('n');
                $quarterEnd = $from->setDate((int) $from->format('Y'), intdiv($month - 1, 3) * 3 + 3, 1)->modify('last day of this month')->format('Y-m-d');
                if ($quarterEnd >= $to) {
                    $out[] = ['from' => $from->format('Y-m-d')] + $absence;
                    break;
                }
                $out[] = ['from' => $from->format('Y-m-d'), 'to' => $quarterEnd] + $absence;
                $from = (new \DateTimeImmutable($quarterEnd))->modify('+1 day');
            }
        }
        return $out;
    }

    /**
     * Souvislé nepřítomnosti téhož druhu (tentýž případ rozepsaný po měsících) se spojí
     * a překryv dvou různých druhů v týchž dnech se ořízne: evidence překryv nepovolí
     * a nezapsaná nepřítomnost by pak blokovala schválení pracovního měsíce.
     *
     * @param list<array<string,mixed>> $absences
     * @return list<array<string,mixed>>
     */
    private static function mergedAbsences(array $absences, int &$trimmed): array
    {
        usort($absences, static fn (array $a, array $b): int => [$a['from'], $a['to'], $a['type']] <=> [$b['from'], $b['to'], $b['type']]);
        $out = [];
        foreach ($absences as $absence) {
            $last = $out === [] ? null : array_key_last($out);
            if ($last !== null && $out[$last]['type'] === $absence['type']
                && (new \DateTimeImmutable($out[$last]['to']))->modify('+1 day')->format('Y-m-d') >= $absence['from']
            ) {
                $out[$last]['to'] = max($out[$last]['to'], $absence['to']);
                if ($out[$last]['childbirth'] === null) {
                    $out[$last]['childbirth'] = $absence['childbirth'];
                }
                continue;
            }
            if ($last !== null && $out[$last]['to'] >= $absence['from']) {
                // Jiný druh ve stejných dnech: zapíše se jen část, která zbývá.
                $trimmed++;
                $absence['from'] = (new \DateTimeImmutable($out[$last]['to']))->modify('+1 day')->format('Y-m-d');
                if ($absence['from'] > $absence['to']) {
                    continue;
                }
            }
            $out[] = $absence;
        }
        return $out;
    }
}
