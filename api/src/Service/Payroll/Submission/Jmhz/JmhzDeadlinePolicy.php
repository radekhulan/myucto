<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Report\CzechWorkingDays;

final class JmhzDeadlinePolicy
{
    private const FIXED_WINDOW = 'transition_fixed_window';
    private const FOLLOWING_MONTH_WINDOW = 'following_month_day_window';
    private const NEXT_CZECH_WORKING_DAY = 'next_czech_working_day';

    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    public function forPeriod(string $periodStart): JmhzDeadlineWindow
    {
        $period = $this->month($periodStart);
        $ruleset = $this->rulesetFor($periodStart);
        [$earliestSubmissionOn, $statutoryDueOn] = $this->window($ruleset, $period);

        return new JmhzDeadlineWindow(
            $earliestSubmissionOn,
            $this->shiftedDueOn($ruleset, $statutoryDueOn),
            $this->text($ruleset, 'jmhz.deadline.calendar_basis'),
            $ruleset->id,
            $ruleset->canonicalHash,
        );
    }

    public function cancellationAllowed(string $periodStart): bool
    {
        $this->month($periodStart);

        return $this->boolean(
            $this->rulesetFor($periodStart),
            'jmhz.deadline.cancellation_allowed',
        );
    }

    public function lastCorrectionOn(string $periodStart): string
    {
        $dueYear = (int) substr($this->forPeriod($periodStart)->dueOn, 0, 4);

        return sprintf('%04d-12-31', $dueYear + 10);
    }

    private function rulesetFor(string $periodStart): PayrollRulesetVersion
    {
        try {
            return $this->rulesets->forCalculation(
                PayrollRulesetDomain::Deadlines,
                $periodStart,
            );
        } catch (PayrollRulesetException $e) {
            throw new \InvalidArgumentException(
                'Pro období JMHZ není dostupná účinná a výpočtově použitelná verze lhůty.',
                0,
                $e,
            );
        }
    }

    /** @return array{string,string} earliest submission and statutory due date */
    private function window(PayrollRulesetVersion $ruleset, \DateTimeImmutable $period): array
    {
        return match ($this->text($ruleset, 'jmhz.deadline.rule')) {
            self::FIXED_WINDOW => [
                $this->date($ruleset, 'jmhz.deadline.earliest_submission_on'),
                $this->date($ruleset, 'jmhz.deadline.due_on'),
            ],
            self::FOLLOWING_MONTH_WINDOW => $this->followingMonthWindow($ruleset, $period),
            default => throw new \LogicException(
                "Ruleset {$ruleset->id} má nepodporovaný tvar lhůty JMHZ.",
            ),
        };
    }

    /** @return array{string,string} earliest submission and statutory due date */
    private function followingMonthWindow(
        PayrollRulesetVersion $ruleset,
        \DateTimeImmutable $period,
    ): array {
        $offset = $this->integer($ruleset, 'jmhz.deadline.month_offset');
        if ($offset < 1) {
            throw new \LogicException("Ruleset {$ruleset->id} má neplatný měsíční posun lhůty JMHZ.");
        }
        $target = $period->modify(sprintf('first day of +%d month', $offset));

        return [
            $this->dayOfMonth($target, $this->integer($ruleset, 'jmhz.deadline.earliest_day')),
            $this->dayOfMonth($target, $this->integer($ruleset, 'jmhz.deadline.due_day')),
        ];
    }

    private function shiftedDueOn(PayrollRulesetVersion $ruleset, string $statutoryDueOn): string
    {
        if ($this->text($ruleset, 'jmhz.deadline.due_shift') !== self::NEXT_CZECH_WORKING_DAY) {
            throw new \LogicException("Ruleset {$ruleset->id} má nepodporovaný posun lhůty JMHZ.");
        }

        return CzechWorkingDays::shiftToWorkingDay(
            new \DateTimeImmutable($statutoryDueOn),
        )->format('Y-m-d');
    }

    private function dayOfMonth(\DateTimeImmutable $month, int $day): string
    {
        if ($day < 1 || $day > 31) {
            throw new \LogicException('Ruleset JMHZ má neplatný den v měsíci.');
        }
        $date = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day);
        if ((int) $date->format('j') !== $day) {
            throw new \LogicException('Ruleset JMHZ posunul den mimo cílový měsíc.');
        }

        return $date->format('Y-m-d');
    }

    private function date(PayrollRulesetVersion $ruleset, string $key): string
    {
        $value = $this->text($ruleset, $key);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \LogicException("Ruleset {$ruleset->id} má neplatné datum {$key}.");
        }

        return $value;
    }

    private function text(PayrollRulesetVersion $ruleset, string $key): string
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'text' || !is_string($parameter->value)) {
            throw new \LogicException("Ruleset {$ruleset->id} má neplatný textový parametr {$key}.");
        }

        return $parameter->value;
    }

    private function integer(PayrollRulesetVersion $ruleset, string $key): int
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'integer' || !is_int($parameter->value)) {
            throw new \LogicException("Ruleset {$ruleset->id} má neplatný celočíselný parametr {$key}.");
        }

        return $parameter->value;
    }

    private function boolean(PayrollRulesetVersion $ruleset, string $key): bool
    {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'boolean' || !is_bool($parameter->value)) {
            throw new \LogicException("Ruleset {$ruleset->id} má neplatný logický parametr {$key}.");
        }

        return $parameter->value;
    }

    private function month(string $periodStart): \DateTimeImmutable
    {
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if (!$period instanceof \DateTimeImmutable
            || $period->format('Y-m-d') !== $periodStart
            || $period->format('d') !== '01'
        ) {
            throw new \InvalidArgumentException(
                'Období JMHZ musí být kalendářní měsíc začínající prvním dnem.',
            );
        }

        return $period;
    }
}
