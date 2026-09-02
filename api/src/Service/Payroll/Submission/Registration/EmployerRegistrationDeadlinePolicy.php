<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Report\CzechWorkingDays;

final class EmployerRegistrationDeadlinePolicy
{
    private const SUPPORTED_FROM = '2026-07-01';
    private const RULESET_ID =
        'cz-jmhz-employer-registration-2026-07.v1';
    private const SOURCES = [
        'law' => '323/2025 Sb. § 17 odst. 1, 2 a 5',
        'cssz_document' =>
            'REGZEL-Přihláška do evidence zaměstnavatelů – pokyny platné od 1. 7. 2026',
    ];

    public function forFirstEmployeeStart(
        string $expectedStartOn,
    ): EmployerRegistrationDeadlineWindow {
        $start = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $expectedStartOn,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$start instanceof \DateTimeImmutable
            || $start->format('Y-m-d') !== $expectedStartOn
            || $expectedStartOn < self::SUPPORTED_FROM
        ) {
            // Výjimka zůstává: bez platného dne nástupu se lhůta nedá
            // spočítat, takže by se vracelo prázdno tvářící se jako výsledek.
            throw new \InvalidArgumentException(
                'Předpokládané datum nástupu prvního zaměstnance chybí nebo '
                    . 'není platné. Zadejte ho ve tvaru RRRR-MM-DD a nejdřív '
                    . 'k 1. 7. 2026 — od té doby se zaměstnavatel na ČSSZ '
                    . 'přihlašuje tímhle způsobem.',
            );
        }

        $earliest = $start->modify('-15 days');
        $due = $this->previousWorkingDay($start, 2);
        $noShowDue = $start->modify('+8 days');
        $rulesetHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' =>
                'payroll-employer-registration-deadline-policy.v1',
            'ruleset_id' => self::RULESET_ID,
            'effective_from' => self::SUPPORTED_FROM,
            'earliest_days_before_start' => 15,
            'due_working_days_before_start' => 2,
            'deemed_employer_days_before_start' => 15,
            'no_show_notification_calendar_days' => 8,
            'sources' => self::SOURCES,
        ]));

        return new EmployerRegistrationDeadlineWindow(
            $earliest->format('Y-m-d'),
            $due->format('Y-m-d'),
            $earliest->format('Y-m-d'),
            $noShowDue->format('Y-m-d'),
            'czech_working_days',
            self::RULESET_ID,
            $rulesetHash,
        );
    }

    private function previousWorkingDay(
        \DateTimeImmutable $date,
        int $days,
    ): \DateTimeImmutable {
        $candidate = $date;
        while ($days > 0) {
            $candidate = $candidate->modify('-1 day');
            if (CzechWorkingDays::isWorkingDay($candidate)) {
                $days--;
            }
        }

        return $candidate;
    }
}
