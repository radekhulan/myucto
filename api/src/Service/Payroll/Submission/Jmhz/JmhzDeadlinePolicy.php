<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Report\CzechWorkingDays;

final class JmhzDeadlinePolicy
{
    private const TRANSITION_RULESET =
        'cz-jmhz-deadlines-2026.transition.v1';
    private const REGULAR_RULESET = 'cz-jmhz-deadlines-2026.regular.v1';
    private const SOURCES = [
        'law' => '323/2025 Sb.',
        'regulation' => '417/2025 Sb.',
        'mpsv' => 'https://mpsv.gov.cz/socialni-pojisteni',
        'cssz_document' =>
            'Jednotné měsíční hlášení zaměstnavatele – základní informace',
    ];

    public function forPeriod(string $periodStart): JmhzDeadlineWindow
    {
        $period = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $periodStart,
        );
        if (!$period instanceof \DateTimeImmutable
            || $period->format('Y-m-d') !== $periodStart
            || $period->format('d') !== '01'
            || $periodStart < '2026-01-01'
        ) {
            throw new \InvalidArgumentException(
                'Období JMHZ musí být podporovaný kalendářní měsíc od ledna 2026.',
            );
        }

        if ($periodStart <= '2026-03-01') {
            return $this->window(
                '2026-04-01',
                '2026-06-30',
                self::TRANSITION_RULESET,
                'transition_2026_q1',
            );
        }

        $followingMonth = $period->modify('first day of next month');

        return $this->window(
            $followingMonth->format('Y-m-01'),
            $followingMonth->format('Y-m-20'),
            self::REGULAR_RULESET,
            'regular_following_month_1_20',
        );
    }

    public function cancellationAllowed(string $periodStart): bool
    {
        return $this->forPeriod($periodStart)->rulesetId !== self::TRANSITION_RULESET;
    }

    public function lastCorrectionOn(string $periodStart): string
    {
        $dueYear = (int) substr($this->forPeriod($periodStart)->dueOn, 0, 4);

        return sprintf('%04d-12-31', $dueYear + 10);
    }

    private function window(
        string $earliestSubmissionOn,
        string $dueOn,
        string $rulesetId,
        string $rule,
    ): JmhzDeadlineWindow {
        $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if (!$dueDate instanceof \DateTimeImmutable) {
            throw new \LogicException('Termín JMHZ není platné datum.');
        }
        $shiftedDueOn = CzechWorkingDays::shiftToWorkingDay($dueDate)
            ->format('Y-m-d');
        $rulesetHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'jmhz-deadline-policy.v1',
            'ruleset_id' => $rulesetId,
            'rule' => $rule,
            'due_shift' => 'next_czech_working_day',
            'calendar_basis' => 'business_days',
            'sources' => self::SOURCES,
        ]));

        return new JmhzDeadlineWindow(
            $earliestSubmissionOn,
            $shiftedDueOn,
            'business_days',
            $rulesetId,
            $rulesetHash,
        );
    }
}
