<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use Psr\Clock\ClockInterface;

/**
 * Co je do kdy a co je po termínu — jedním voláním za celou firmu.
 *
 * ## Proč to vzniklo
 *
 * Modul uměl zákonné lhůty spočítat na den přesně a s citací paragrafu
 * ({@see PayrollLevyDeadlinePolicy}, lhůty podání, lhůty checklistu), ale
 * neexistovalo místo, které by je někomu ŘEKLO. Termín se dal najít jen tak,
 * že si člověk otevřel přehled podání za správné období, pak platby za jiné
 * období a pak kartu každého zaměstnance zvlášť. Zmeškaný termín se nikde
 * nezvýraznil — a lhůta, kterou nikdo neuvidí, není hlídaná.
 *
 * ## Co se do přehledu dostane
 *
 * Tři prameny, které mají zákonnou lhůtu a nesplněný stav:
 *
 * 1. **podání** — `payroll_obligations` + `payroll_submission_deadlines`,
 *    tedy tatáž evidence, ze které žije obrazovka podání; stav se posuzuje
 *    {@see PayrollDeadlineAssessmentService}, aby přehled a detail povinnosti
 *    neříkaly o jednom termínu dvě různé věci,
 * 2. **odvody** — nezaplacené závazky ze splatnosti podle
 *    {@see PayrollLevyDeadlinePolicy} (pojistné, zálohová a srážková daň),
 * 3. **lhůty u lidí** — nevyřízené položky nástupního a výstupního checklistu
 *    s odvozenou zákonnou lhůtou (přihláška ČSSZ, oznámení pojišťovně, ELDP).
 *
 * ## Co se do něj vědomě nedostane
 *
 * Čistá mzda, srážky ze mzdy ani exekuční platby: jejich termín plyne ze
 * smlouvy nebo z rozhodnutí, ne ze zákonné lhůty, a přimíchat je by z hlídače
 * termínů udělalo výpis všech plateb. Stejně tak položky checklistu bez
 * odvozené lhůty (`due_date IS NULL`) — připomínat termín, který neexistuje,
 * je ta nejjistější cesta, jak obsluhu naučit hlášky přeskakovat.
 *
 * Cron ani e-mail tady NENÍ. Přehled je čtecí; rozeslat ho je samostatné
 * rozhodnutí s vlastními následky (komu, jak často, co s firmou bez účetní).
 */
final readonly class PayrollDeadlineOverviewService
{
    /** Kolik dnů dopředu se termín považuje za „brzy". */
    private const DUE_SOON_DAYS = 5;

    /** Výchozí dohled dopředu — pokrývá celý příští měsíc včetně 20. dne. */
    public const DEFAULT_HORIZON_DAYS = 45;

    public const MAX_HORIZON_DAYS = 400;

    /**
     * Jak hluboko do minulosti se zmeškané termíny ještě ukazují. Bez meze by
     * dashboard firmy s historií vypsal roky staré nedodělky a to podstatné
     * by v nich zaniklo.
     */
    private const OVERDUE_LOOKBACK_DAYS = 400;

    public function __construct(
        private PayrollDeadlineOverviewRepository $repository,
        private PayrollDeadlineAssessmentService $assessments,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array{
     *   as_of:string,horizon_days:int,window:array{from:string,to:string},
     *   summary:array<string,int>,
     *   items:list<array<string,mixed>>
     * }
     */
    public function overview(
        int $supplierId,
        string $environment,
        int $horizonDays = self::DEFAULT_HORIZON_DAYS,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma přehledu mzdových termínů není platná.',
            );
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí přehledu mzdových termínů musí být production nebo test.',
            );
        }
        if ($horizonDays < 1 || $horizonDays > self::MAX_HORIZON_DAYS) {
            throw new \InvalidArgumentException(
                'Dohled přehledu mzdových termínů musí být 1 až '
                . self::MAX_HORIZON_DAYS . ' dnů.',
            );
        }
        $today = $this->today();
        $from = $today
            ->sub(new \DateInterval('P' . self::OVERDUE_LOOKBACK_DAYS . 'D'))
            ->format('Y-m-d');
        $to = $today
            ->add(new \DateInterval('P' . $horizonDays . 'D'))
            ->format('Y-m-d');

        $items = [
            ...$this->submissionItems($supplierId, $environment, $from, $to),
            ...$this->levyItems($supplierId, $from, $to),
            ...$this->checklistItems($supplierId, $from, $to),
        ];
        usort(
            $items,
            static fn (array $a, array $b): int
                => [$a['due_on'], $a['source'], $a['title']]
                <=> [$b['due_on'], $b['source'], $b['title']],
        );

        $summary = [
            'total' => count($items),
            'overdue' => 0,
            'due_today' => 0,
            'due_soon' => 0,
            'open' => 0,
            'awaiting_result' => 0,
            'action_required' => 0,
        ];
        foreach ($items as $item) {
            $phase = (string) $item['phase'];
            if (array_key_exists($phase, $summary) && $phase !== 'total') {
                ++$summary[$phase];
            }
        }

        return [
            'as_of' => $today->format('Y-m-d'),
            'horizon_days' => $horizonDays,
            'window' => ['from' => $from, 'to' => $to],
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function submissionItems(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->submissionDeadlines(
            $supplierId,
            $environment,
            $from,
            $to,
        ) as $row) {
            $assessment = $this->assessments->assess(
                (string) $row['earliest_submission_on'],
                (string) $row['due_on'],
                (string) $row['status'],
                $row['submission_status'] === null
                    ? null
                    : (string) $row['submission_status'],
            );
            // `not_open` a `fulfilled` do hlídače nepatří: první ještě nejde
            // podat, druhý je hotový. Kdyby se ukazovaly, tvořily by většinu
            // seznamu a to podstatné by v nich zaniklo.
            if (in_array(
                $assessment->phase,
                ['not_open', 'fulfilled', 'cancelled'],
                true,
            )) {
                continue;
            }
            $items[] = [
                'source' => 'submission',
                'reference' => 'payroll_obligation:' . (int) $row['obligation_id'],
                'title' => (string) $row['agenda_code'],
                'subject' => (string) $row['subject_reference'],
                'period' => substr((string) $row['period_start'], 0, 7),
                'due_on' => (string) $row['due_on'],
                'phase' => $assessment->phase,
                'days_to_due' => $assessment->daysToDue,
                'is_overdue' => $assessment->isOverdue,
                'status' => (string) $row['status'],
                'submission_status' => $row['submission_status'],
                'ruleset_id' => (string) $row['ruleset_id'],
                'path' => '/payroll/submissions',
            ];
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function levyItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->levyDeadlines(
            $supplierId,
            $from,
            $to,
        ) as $row) {
            $dueOn = (string) $row['due_on'];
            $remaining = (int) $row['amount_minor'] - (int) $row['settled_minor'];
            $items[] = [
                'source' => 'levy',
                'reference' => 'payroll_liability:' . (int) $row['liability_id'],
                'title' => (string) $row['liability_kind'],
                'subject' => (string) $row['recipient_reference'],
                'period' => substr((string) $row['period_start'], 0, 7),
                'due_on' => $dueOn,
                'phase' => $this->phase($dueOn),
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $this->phase($dueOn) === 'overdue',
                'remaining_minor' => $remaining,
                'run_id' => (int) $row['run_id'],
                'path' => '/payroll/payments',
            ];
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function checklistItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->checklistDeadlines(
            $supplierId,
            $from,
            $to,
        ) as $row) {
            $dueOn = (string) $row['due_date'];
            $items[] = [
                'source' => 'checklist',
                'reference' => 'payroll_checklist_item:' . (int) $row['item_id'],
                'title' => (string) $row['item_key'],
                'subject' => (string) $row['full_name'],
                'period' => null,
                'due_on' => $dueOn,
                'phase' => $this->phase($dueOn),
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $this->phase($dueOn) === 'overdue',
                'employment_id' => (int) $row['employment_id'],
                'employee_id' => (int) $row['employee_id'],
                'checklist_phase' => (string) $row['phase'],
                'deadline_source' => $row['deadline_source'],
                'deadline_source_status' => $row['deadline_source_status'],
                'path' => '/payroll/employees/' . (int) $row['employee_id'],
            ];
        }

        return $items;
    }

    /**
     * Fáze termínu u pramenů, které stav podání nemají.
     *
     * Prahy jsou schválně tytéž jako v {@see PayrollDeadlineAssessmentService}
     * — kdyby se rozešly, znamenalo by „brzy" na dashboardu něco jiného než
     * „brzy" u povinnosti podání a přehled by si protiřečil sám se sebou.
     */
    private function phase(string $dueOn): string
    {
        $days = $this->daysToDue($dueOn);
        if ($days < 0) {
            return 'overdue';
        }
        if ($days === 0) {
            return 'due_today';
        }

        return $days <= self::DUE_SOON_DAYS ? 'due_soon' : 'open';
    }

    private function daysToDue(string $dueOn): int
    {
        $due = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $dueOn,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$due instanceof \DateTimeImmutable
            || $due->format('Y-m-d') !== $dueOn
        ) {
            throw new \UnexpectedValueException(
                'Termín mzdové povinnosti není platné datum.',
            );
        }

        return (int) $this->today()->diff($due)->format('%r%a');
    }

    private function today(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->setTime(0, 0);
    }
}
