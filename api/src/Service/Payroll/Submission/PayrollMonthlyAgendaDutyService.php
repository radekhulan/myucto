<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollMonthlyAgendaDutyRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;

/**
 * MĚSÍČNÍ AGENDOVÉ POVINNOSTI, KTERÉ JEŠTĚ NEMAJÍ ZALOŽENÉ PODÁNÍ.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co bylo špatně
 * ═══════════════════════════════════════════════════════════════════════════
 * Měsíční přehled uměl vypsat jen povinnosti, které UŽ existují v evidenci
 * (`payroll_obligations`), a termíny navázané na člověka nebo na platbu.
 * Povinnost „za srpen podat JMHZ" ani „za srpen podat přehled o platbě
 * pojistného VZP" ale v evidenci nevznikne dřív, než si ji účetní sama
 * založí — takže dokud nic neudělala, tvrdil přehled, že nemá co dělat.
 * Přesně naopak, než má být: nejvíc informace potřebuje ten, kdo ještě
 * nezačal.
 *
 * Povinnost tady proto NEPLYNE z existence dokladu, ale ze schváleného
 * mzdového běhu — stejným obratem, jakým {@see \MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService::taxStatementItems()}
 * odvozuje roční vyúčtování z toho, že firma v roce vyplácela příjmy.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se povinnost NEMATERIALIZUJE do `payroll_obligations`
 * ═══════════════════════════════════════════════════════════════════════════
 * Podání je dokument se zmrazeným artefaktem a spisovou značkou. Zakládat ho
 * při každé uzávěrce by plodilo koncepty, které se musí rušit, jakmile se
 * cokoliv doopraví. Přehled proto nese POVINNOST, ne dokument; dokument vzniká
 * až kliknutím na akci
 * ({@see \MyInvoice\Service\Payroll\Submission\PayrollMonthlyAgendaPreparationService}).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Disjunktnost proti pramenu `submission` — KONSTRUKCÍ, ne filtrem
 * ═══════════════════════════════════════════════════════════════════════════
 * {@see self::unprepared()} dostane seznam povinností, které volající
 * z evidence PRÁVĚ PŘEČETL, a vrací DOPLNĚK k němu: jen ty, ke kterým řádek
 * v evidenci neexistuje. Obě množiny tedy vznikají z jednoho a téhož dotazu,
 * takže se nemůžou překrýt ani rozejít v tom, které období pokrývají —
 * na rozdíl od druhého dotazu s vlastní podmínkou, který by se dřív nebo
 * později posunul o den.
 *
 * Důsledek, který je ZÁMĚR: jakmile povinnost jednou v evidenci je, mluví
 * o ní už jen pramen `submission`, a to i tehdy, když je podání ZRUŠENÉ.
 * Zrušené podání totiž povinnost nesplnilo — přehled ji proto dál ukazuje
 * jako nesplněnou, jen s jejím skutečným stavem a s výzvou připravit ji znovu.
 * Kdyby ji místo toho zase vydával tenhle pramen, ztratila by se historie
 * (spisová značka, důvod zrušení) a řádek by se v evidenci i tady zdvojil.
 */
final readonly class PayrollMonthlyAgendaDutyService
{
    public function __construct(
        private PayrollMonthlyAgendaDutyRepository $repository,
        private JmhzDeadlinePolicy $jmhzDeadlines,
        private HealthNotificationDeadlinePolicy $healthDeadlines,
        private PayrollDeadlineAssessmentService $assessments,
    ) {}

    /**
     * Povinnosti období, ke kterým v evidenci NENÍ řádek.
     *
     * `$registered` je seznam dvojic agenda + `subject_reference` tak, jak je
     * vrací {@see \MyInvoice\Repository\Payroll\PayrollSubmissionRepository::listOverview()}
     * — tedy přesně to, co si volající o období z evidence přečetl.
     *
     * @param string $period RRRR-MM
     * @param list<array{agenda_code:string,subject_reference:string}> $registered
     * @return list<array{
     *   key:string,agenda_code:string,insurer_code:?string,subject:?string,
     *   run_id:int,revision_id:int,period:string,due_on:string,
     *   earliest_submission_on:string,phase:string,days_to_due:int,
     *   is_overdue:bool,deadline_source:string,deadline_ruleset_id:string
     * }>
     */
    public function unprepared(
        int $supplierId,
        string $period,
        array $registered,
    ): array {
        $duties = [];
        foreach ($this->all($supplierId, $period) as $duty) {
            if (self::isRegistered($duty, $registered)) {
                continue;
            }
            $duties[] = $duty;
        }

        return $duties;
    }

    /**
     * Všechny agendové povinnosti období bez ohledu na to, co je připravené —
     * veřejné kvůli přípravě na jedno kliknutí, která z povinnosti potřebuje
     * revizi, ze které se podání sestaví.
     *
     * @param string $period RRRR-MM
     * @return list<array<string,mixed>>
     */
    public function all(int $supplierId, string $period): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma měsíčních agendových povinností není platná.',
            );
        }
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException(
                'Období měsíčních agendových povinností musí mít formát RRRR-MM.',
            );
        }
        $periodStart = $period . '-01';

        $duties = [];
        foreach ($this->repository->approvedRunsForPeriod(
            $supplierId,
            $periodStart,
        ) as $run) {
            // Běh bez lidí nezakládá ani hlášení, ani odvod. Připomínat prázdný
            // tiskopis je ta nejjistější cesta, jak obsluhu naučit hlášky
            // přeskakovat — a s nimi i tu vedle.
            if ($run['person_count'] < 1) {
                continue;
            }
            $jmhz = $this->jmhzDuty($run, $period, $periodStart);
            if ($jmhz !== null) {
                $duties[] = $jmhz;
            }
            foreach ($run['insurer_codes'] as $insurerCode) {
                $health = $this->healthDuty($run, $period, $insurerCode);
                if ($health !== null) {
                    $duties[] = $health;
                }
            }
        }
        usort(
            $duties,
            static fn (array $a, array $b): int
                => [$a['due_on'], $a['agenda_code'], (string) $a['insurer_code']]
                <=> [$b['due_on'], $b['agenda_code'], (string) $b['insurer_code']],
        );

        return $duties;
    }

    /**
     * Jedno měsíční hlášení zaměstnavatele za běh.
     *
     * Podává se za REGISTRACI u OSSZ, takže běh přes víc účtáren zmrazí víc
     * podání ({@see JmhzSubmissionBridgeService::runReference()}). Přehled
     * o tom mluví jako o JEDNÉ povinnosti: seznam registrací zná až náhled
     * PVPOJ nad revizí a tahat ho do čtecí cesty přehledu by znamenalo
     * sestavovat sociální výsledek pokaždé, co se panel otevře. Rozpad na
     * účtárny řeší až obrazovka JMHZ.
     *
     * @param array{run_id:int,revision_id:int,person_count:int,insurer_codes:list<string>} $run
     * @return array<string,mixed>|null
     */
    private function jmhzDuty(array $run, string $period, string $periodStart): ?array
    {
        try {
            $window = $this->jmhzDeadlines->forPeriod($periodStart);
        } catch (\Throwable) {
            // Období bez účinné verze lhůty (staré běhy před JMHZ) povinnost
            // nemá — dohadovat pro ně termín by znamenalo hlásit zpoždění,
            // které podle tehdejšího práva nenastalo.
            return null;
        }

        return $this->duty(
            'agenda_duty:' . JmhzSubmissionBridgeService::AGENDA_CODE
                . ':' . $run['run_id'],
            JmhzSubmissionBridgeService::AGENDA_CODE,
            null,
            null,
            $run,
            $period,
            $window->earliestSubmissionOn,
            $window->dueOn,
            $window->calendarBasis,
            $window->rulesetId,
        );
    }

    /**
     * Přehled o platbě pojistného za JEDNU pojišťovnu.
     *
     * Hromadné oznámení (HOZ) tu vědomě NENÍ: nevzniká z měsíčního běhu, ale
     * z události u konkrétního člověka, a jako měsíční povinnost ho hlásit
     * nelze — o jeho lhůtách mluví pramen `checklist` a `registration_change`.
     *
     * @param array{run_id:int,revision_id:int,person_count:int,insurer_codes:list<string>} $run
     * @return array<string,mixed>|null
     */
    private function healthDuty(
        array $run,
        string $period,
        string $insurerCode,
    ): ?array {
        try {
            $window = $this->healthDeadlines->forPaymentOverview($period);
        } catch (\Throwable) {
            return null;
        }

        return $this->duty(
            'agenda_duty:' . HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW
                . ':' . $run['run_id'] . ':' . $insurerCode,
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            $insurerCode,
            PayrollObligationSubjectFormatter::insurerName($insurerCode),
            $run,
            $period,
            $window->earliestSubmissionOn,
            $window->dueOn,
            $window->calendarBasis,
            $window->rulesetId,
        );
    }

    /**
     * Fáze termínu se posuzuje TOUTÉŽ službou jako u povinností v evidenci
     * ({@see PayrollDeadlineAssessmentService}) — jinak by „po termínu"
     * znamenalo na jednom řádku přehledu něco jiného než na sousedním.
     * Povinnost bez podání je z pohledu posudku otevřená (`open`) a bez
     * jakéhokoli stavu podání.
     *
     * @param array{run_id:int,revision_id:int,person_count:int,insurer_codes:list<string>} $run
     * @return array<string,mixed>
     */
    private function duty(
        string $key,
        string $agendaCode,
        ?string $insurerCode,
        ?string $subject,
        array $run,
        string $period,
        string $earliestSubmissionOn,
        string $dueOn,
        string $calendarBasis,
        string $rulesetId,
    ): array {
        $assessment = $this->assessments->assess(
            $earliestSubmissionOn,
            $dueOn,
            'open',
            null,
        );

        return [
            'key' => $key,
            'agenda_code' => $agendaCode,
            'insurer_code' => $insurerCode,
            'subject' => $subject,
            'run_id' => $run['run_id'],
            'revision_id' => $run['revision_id'],
            'period' => $period,
            'due_on' => $dueOn,
            'earliest_submission_on' => $earliestSubmissionOn,
            'phase' => $assessment->phase,
            'days_to_due' => $assessment->daysToDue,
            'is_overdue' => $assessment->isOverdue,
            'deadline_source' => $calendarBasis,
            'deadline_ruleset_id' => $rulesetId,
        ];
    }

    /**
     * Řádek evidence pokrývá povinnost, když sedí agenda a — u zdravotní
     * agendy — i pojišťovna. `subject_reference` u JMHZ nese navíc účtárnu
     * (`payroll_run:8:office:4`), takže se porovnává jen PŘEDPONA běhu; jinak
     * by se povinnost hlásila znovu vedle už založeného podání.
     *
     * @param array<string,mixed> $duty
     * @param list<array{agenda_code:string,subject_reference:string}> $registered
     */
    private static function isRegistered(array $duty, array $registered): bool
    {
        $agendaCode = (string) $duty['agenda_code'];
        $runPrefix = 'payroll_run:' . $duty['run_id'];
        $insurerCode = $duty['insurer_code'];

        foreach ($registered as $row) {
            if ((string) $row['agenda_code'] !== $agendaCode) {
                continue;
            }
            $reference = (string) $row['subject_reference'];
            if ($reference !== $runPrefix
                && !str_starts_with($reference, $runPrefix . ':')
            ) {
                continue;
            }
            if ($insurerCode === null) {
                return true;
            }
            $parts = explode(':', $reference);
            if (end($parts) === $insurerCode) {
                return true;
            }
        }

        return false;
    }
}
