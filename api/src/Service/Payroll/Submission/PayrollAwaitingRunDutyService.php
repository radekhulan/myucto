<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use Psr\Clock\ClockInterface;

/**
 * Měsíce vedení mezd v MyÚčtu, za které ještě není schválený mzdový běh.
 *
 * Povinnosti měsíce (JMHZ, přehledy o platbě pojistného) odvozuje
 * {@see PayrollMonthlyAgendaDutyService} ze SCHVÁLENÉHO běhu. Dokud běh
 * schválený není, Měsíční přehled tvrdil „žádná otevřená položka" a hlídač
 * termínů o hlášení za měsíc mlčel, přestože lhůta běží a firma má lidi
 * v pracovním poměru. Tahle služba doplní právě ty měsíce jako povinnost
 * „čeká na mzdový běh":
 *
 *   - měsíc není před prvním měsícem vedení mezd (převzaté měsíce hlídá
 *     {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPredecessorGapService})
 *     a není po dnešním měsíci,
 *   - firma má v měsíci aspoň jeden trvající vztah,
 *   - za měsíc není nezrušený převzatý běh ani běh se schválenou revizí,
 *   - pro období platí lhůta JMHZ.
 *
 * Bez nastaveného začátku vedení mezd je dolní hranicí první vlastní běh; firma
 * bez jediného běhu nemá co připomínat (průvodce nastavením ji vede jinudy).
 */
final readonly class PayrollAwaitingRunDutyService
{
    /** Víc měsíců zpět se nehlídá — starší díry řeší kontrola převzetí. */
    private const MAX_MONTHS = 13;

    public function __construct(
        private Connection $db,
        private JmhzDeadlinePolicy $deadlines,
        private PayrollDeadlineAssessmentService $assessments,
        private PayrollHistoricalPeriodService $historicalPeriods,
        private ClockInterface $clock,
    ) {}

    /**
     * @param ?string $period `YYYY-MM`, jen tento měsíc; `null` = všechny hlídané
     * @return list<array{
     *   period:string,earliest_submission_on:string,due_on:string,phase:string,
     *   days_to_due:int,is_overdue:bool,employment_count:int
     * }>
     */
    public function missing(int $supplierId, ?string $period = null): array
    {
        if (!$this->db->hasTable('payroll_runs') || !$this->db->hasTable('payroll_employments')) {
            return [];
        }
        $current = \DateTimeImmutable::createFromInterface($this->clock->now())->format('Y-m');
        $lower = $this->historicalPeriods->startPeriod($supplierId) ?? $this->firstRunPeriod($supplierId);
        if ($lower === null) {
            return [];
        }
        $months = [];
        if ($period !== null) {
            /*
             * Výslovně zvolený měsíc se ukáže i tehdy, když teprve nastane.
             * Mzdový běh za budoucí měsíc založit jde a kontrola před ním má
             * stejně jako u minulého měsíce říct, že z jeho schválení vznikne
             * hlášení a do kdy. Dřív byl přehled u budoucího měsíce prázdný.
             * Souhrn bez zvoleného měsíce dál hlídá jen měsíce do dneška.
             */
            if ($period >= $lower) {
                $months[] = $period;
            }
        } else {
            $cursor = new \DateTimeImmutable($current . '-01');
            for ($i = 0; $i < self::MAX_MONTHS; ++$i) {
                $month = $cursor->format('Y-m');
                if ($month < $lower) {
                    break;
                }
                $months[] = $month;
                $cursor = $cursor->modify('-1 month');
            }
            sort($months);
        }

        $out = [];
        foreach ($months as $month) {
            $employments = $this->uncoveredEmploymentCount($supplierId, $month);
            if ($employments < 1) {
                continue;
            }
            try {
                $window = $this->deadlines->forPeriod($month . '-01');
            } catch (\Throwable) {
                continue;
            }
            $assessment = $this->assessments->assess($window->earliestSubmissionOn, $window->dueOn, 'open', null);
            $out[] = [
                'period' => $month,
                'earliest_submission_on' => $window->earliestSubmissionOn,
                'due_on' => $window->dueOn,
                'phase' => $assessment->phase,
                'days_to_due' => $assessment->daysToDue,
                'is_overdue' => $assessment->isOverdue,
                'employment_count' => $employments,
            ];
        }

        return $out;
    }

    /** Agenda, pod kterou se povinnost hlásí (lidský název dodává klient). */
    public static function agendaCode(): string
    {
        return JmhzSubmissionBridgeService::AGENDA_CODE;
    }

    private function firstRunPeriod(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DATE_FORMAT(MIN(period_start), '%Y-%m')
               FROM payroll_runs
              WHERE supplier_id = ? AND run_kind = 'calculated' AND status <> 'cancelled'",
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Počet trvajících vztahů v měsíci, když za měsíc NENÍ převzatý běh ani běh
     * se schválenou revizí; jinak 0.
     */
    private function uncoveredEmploymentCount(int $supplierId, string $month): int
    {
        $periodStart = $month . '-01';
        $stmt = $this->db->pdo()->prepare(
            "SELECT CASE
                      WHEN EXISTS (
                          SELECT 1 FROM payroll_runs run
                           WHERE run.supplier_id = ? AND run.period_start = ?
                             AND run.status <> 'cancelled'
                             AND (run.run_kind = 'takeover'
                                  OR EXISTS (
                                      SELECT 1 FROM payroll_run_revisions revision
                                       WHERE revision.supplier_id = run.supplier_id
                                         AND revision.run_id = run.id
                                         AND revision.status IN ('approved', 'superseded')
                                  ))
                      ) THEN 0
                      ELSE (
                          SELECT COUNT(*) FROM payroll_employments employment
                           WHERE employment.supplier_id = ?
                             AND employment.is_legacy_projection = 0
                             AND employment.status IN ('active', 'suspended', 'ended')
                             AND COALESCE(employment.actual_start_date, employment.start_date) <= LAST_DAY(?)
                             AND (employment.end_date IS NULL OR employment.end_date >= ?)
                      )
                    END",
        );
        $stmt->execute([$supplierId, $periodStart, $supplierId, $periodStart, $periodStart]);

        return (int) $stmt->fetchColumn();
    }
}
