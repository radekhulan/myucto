<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Zákonné lhůty mzdových odvodů jako DATA, ne jako výraz roztroušený po
 * materializérech.
 *
 * Každá lhůta drží pravidlo (základní den v měsíci nebo poslední den měsíce),
 * zda se posouvá na pracovní den, od kdy ji aplikace modeluje, citaci zdroje a
 * příznak, jestli je zdroj doložený v repozitáři, nebo jen převzatý zvenčí.
 *
 * Posun: připadne-li poslední den lhůty na sobotu, neděli nebo svátek, je
 * posledním dnem nejblíže následující pracovní den. Bez posunu aplikace
 * hlásila splatnost na sobotu (např. odvody za 05/2026: 20. 6. 2026 = sobota,
 * skutečný termín pondělí 22. 6. 2026).
 *
 * `effective_from` je účinnost pravidla V TÉTO APLIKACI: mzdový modul počítá
 * nad rulesety `cz-payroll-2026.*`, starší období nemá čím posoudit. Zákonné
 * znění je u pojistného i daně starší, proto se `effective_from` NESMÍ číst
 * jako datum novely.
 *
 * `jmhz_monthly_report` je tu jen jako běžné měsíční pravidlo, aby se dalo
 * porovnat s termínem, který povinnostem JMHZ skutečně nastavuje
 * JmhzDeadlinePolicy. Přechodné ustanovení za Q1/2026 (jediný termín
 * 1. 4. – 30. 6. 2026) tahle třída nezná — pro povinnosti JMHZ je autoritou
 * dál JmhzDeadlinePolicy.
 */
final class PayrollLevyDeadlinePolicy
{
    public const SOCIAL_INSURANCE = 'social_insurance';
    public const HEALTH_INSURANCE = 'health_insurance';
    public const ADVANCE_TAX = 'advance_tax';
    public const WITHHOLDING_TAX = 'withholding_tax';
    public const JMHZ_MONTHLY_REPORT = 'jmhz_monthly_report';

    private const SCHEMA_REFERENCE = 'payroll-levy-deadline-policy.v1';
    private const DAY_OF_MONTH = 'day_of_month';
    private const LAST_DAY_OF_MONTH = 'last_day_of_month';
    private const CALENDAR_BASIS = 'business_days';
    /** Posun konce lhůty na nejbližší následující český pracovní den. */
    private const WORKING_DAY_SHIFT = 'next_czech_working_day';
    /** Lhůta, která se na pracovní den neposouvá. */
    private const NO_SHIFT = 'none';

    /** Citace ověřená proti zdroji v repozitáři. */
    private const REPO_VERIFIED = 'repo_verified';
    /** Citace převzatá zvenčí — v repozitáři pro ni není doklad. */
    private const EXTERNAL_UNVERIFIED = 'external_unverified';

    private const TAX_SHIFT_SOURCE = '§ 33 odst. 4 zákona č. 280/2009 Sb.';
    private const ADMINISTRATIVE_SHIFT_SOURCE =
        '§ 40 odst. 1 písm. c) zákona č. 500/2004 Sb.';

    /**
     * @var array<string, array{
     *   ruleset_id:string,rule:string,base_event:string,
     *   base_month_offset:int,earliest_day:?int,due_basis:string,
     *   due_day:?int,due_shift:string,effective_from:string,
     *   source:string,source_status:string,repo_reference:?string,
     *   shift_source:string,shift_source_status:string
     * }>
     */
    private const RULES = [
        self::SOCIAL_INSURANCE => [
            'ruleset_id' => 'cz-payroll-levy-deadlines.social-insurance.v1',
            'rule' => 'following_month_day_1_to_20',
            'base_event' => 'payroll_period_start',
            'base_month_offset' => 1,
            'earliest_day' => 1,
            'due_basis' => self::DAY_OF_MONTH,
            'due_day' => 20,
            'due_shift' => self::WORKING_DAY_SHIFT,
            'effective_from' => '2026-01-01',
            'source' => '§ 9 odst. 2 zákona č. 589/1992 Sb.',
            'source_status' => self::EXTERNAL_UNVERIFIED,
            'repo_reference' => null,
            'shift_source' => self::ADMINISTRATIVE_SHIFT_SOURCE,
            'shift_source_status' => self::EXTERNAL_UNVERIFIED,
        ],
        self::HEALTH_INSURANCE => [
            'ruleset_id' => 'cz-payroll-levy-deadlines.health-insurance.v1',
            'rule' => 'following_month_day_1_to_20',
            'base_event' => 'payroll_period_start',
            'base_month_offset' => 1,
            'earliest_day' => 1,
            'due_basis' => self::DAY_OF_MONTH,
            'due_day' => 20,
            'due_shift' => self::WORKING_DAY_SHIFT,
            'effective_from' => '2026-01-01',
            'source' => '§ 5 odst. 2 zákona č. 592/1992 Sb.',
            'source_status' => self::EXTERNAL_UNVERIFIED,
            'repo_reference' => null,
            'shift_source' => self::ADMINISTRATIVE_SHIFT_SOURCE,
            'shift_source_status' => self::EXTERNAL_UNVERIFIED,
        ],
        self::ADVANCE_TAX => [
            'ruleset_id' => 'cz-payroll-levy-deadlines.advance-tax.v1',
            'rule' => 'following_month_day_20',
            'base_event' => 'payroll_period_start',
            'base_month_offset' => 1,
            'earliest_day' => null,
            'due_basis' => self::DAY_OF_MONTH,
            'due_day' => 20,
            'due_shift' => self::WORKING_DAY_SHIFT,
            'effective_from' => '2026-01-01',
            'source' => '§ 38h odst. 10 zákona č. 586/1992 Sb.',
            'source_status' => self::REPO_VERIFIED,
            'repo_reference' => 'manual/58_Uplne_mzdy.md',
            'shift_source' => self::TAX_SHIFT_SOURCE,
            'shift_source_status' => self::REPO_VERIFIED,
        ],
        self::WITHHOLDING_TAX => [
            'ruleset_id' => 'cz-payroll-levy-deadlines.withholding-tax.v1',
            'rule' => 'following_month_last_day',
            'base_event' => 'payroll_period_start',
            'base_month_offset' => 1,
            'earliest_day' => null,
            'due_basis' => self::LAST_DAY_OF_MONTH,
            'due_day' => null,
            'due_shift' => self::WORKING_DAY_SHIFT,
            'effective_from' => '2026-01-01',
            'source' => '§ 38d odst. 3 zákona č. 586/1992 Sb.',
            'source_status' => self::REPO_VERIFIED,
            'repo_reference' => 'manual/58_Uplne_mzdy.md',
            'shift_source' => self::TAX_SHIFT_SOURCE,
            'shift_source_status' => self::REPO_VERIFIED,
        ],
        self::JMHZ_MONTHLY_REPORT => [
            'ruleset_id' => 'cz-payroll-levy-deadlines.jmhz-monthly.v1',
            'rule' => 'following_month_day_1_to_20',
            'base_event' => 'payroll_period_start',
            'base_month_offset' => 1,
            'earliest_day' => 1,
            'due_basis' => self::DAY_OF_MONTH,
            'due_day' => 20,
            'due_shift' => self::WORKING_DAY_SHIFT,
            'effective_from' => '2026-01-01',
            'source' => 'zákon č. 323/2025 Sb.; nařízení č. 417/2025 Sb.',
            'source_status' => self::REPO_VERIFIED,
            'repo_reference' =>
                'api/src/Service/Payroll/Submission/Jmhz/JmhzDeadlinePolicy.php',
            'shift_source' => self::ADMINISTRATIVE_SHIFT_SOURCE,
            'shift_source_status' => self::EXTERNAL_UNVERIFIED,
        ],
    ];

    /** @return list<string> */
    public static function levies(): array
    {
        return array_keys(self::RULES);
    }

    /** Posunutý termín odvodu jako `Y-m-d` — jediná hodnota pro zápis i UI. */
    public function dueOn(string $levy, string $periodStart): string
    {
        return $this->forPeriod($levy, $periodStart)->dueOn;
    }

    /**
     * Den v měsíci, na který připadá odvod, BEZ posunu na pracovní den.
     *
     * Slouží jiným politikám, které stejný den v měsíci potřebují jako vstup do
     * VLASTNÍHO pravidla (např. oznamovací povinnost zdravotní pojišťovně
     * u DPP/DPČ), aby ho nedržely jako druhou kopii literálu.
     */
    public function dueDayOfMonth(string $levy): int
    {
        $rule = self::RULES[$levy] ?? null;
        if ($rule === null || $rule['due_day'] === null) {
            throw new \InvalidArgumentException(
                'Druh mzdového odvodu nemá modelovaný pevný den v měsíci.',
            );
        }

        return $rule['due_day'];
    }

    public function forPeriod(
        string $levy,
        string $periodStart,
    ): PayrollLevyDeadlineWindow {
        $rule = self::RULES[$levy] ?? null;
        if ($rule === null) {
            throw new \InvalidArgumentException(
                'Druh mzdového odvodu nemá modelovanou zákonnou lhůtu.',
            );
        }
        $period = $this->month($periodStart);
        if ($periodStart < $rule['effective_from']) {
            throw new \InvalidArgumentException(
                'Mzdové období předchází účinnosti modelované lhůty odvodu.',
            );
        }

        $target = $period->modify(
            sprintf('first day of +%d month', $rule['base_month_offset']),
        );
        $statutoryDue = $rule['due_basis'] === self::LAST_DAY_OF_MONTH
            ? $target->modify('last day of this month')
            : $this->dayOfMonth($target, $rule['due_day']);
        $due = $this->shifted($statutoryDue, $rule['due_shift']);
        $earliest = $rule['earliest_day'] === null
            ? null
            : $this->dayOfMonth($target, $rule['earliest_day'])
                ->format('Y-m-d');

        return new PayrollLevyDeadlineWindow(
            $levy,
            $period->format('Y-m-d'),
            $earliest,
            $statutoryDue->format('Y-m-d'),
            $due->format('Y-m-d'),
            $due->format('Y-m-d') !== $statutoryDue->format('Y-m-d'),
            self::CALENDAR_BASIS,
            $rule['ruleset_id'],
            $this->rulesetHash($levy, $rule),
            $rule['rule'],
            $rule['source'],
            $rule['source_status'],
            $rule['shift_source'],
            $rule['shift_source_status'],
        );
    }

    /**
     * @param array{
     *   ruleset_id:string,rule:string,base_event:string,
     *   base_month_offset:int,earliest_day:?int,due_basis:string,
     *   due_day:?int,due_shift:string,effective_from:string,
     *   source:string,source_status:string,repo_reference:?string,
     *   shift_source:string,shift_source_status:string
     * } $rule
     */
    private function rulesetHash(string $levy, array $rule): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => self::SCHEMA_REFERENCE,
            'levy' => $levy,
            'calendar_basis' => self::CALENDAR_BASIS,
            ...$rule,
        ]));
    }

    private function shifted(
        \DateTimeImmutable $due,
        string $mode,
    ): \DateTimeImmutable {
        return match ($mode) {
            self::WORKING_DAY_SHIFT
                => CzechWorkingDays::shiftToWorkingDay($due),
            self::NO_SHIFT => $due,
            default => throw new \LogicException(
                'Pravidlo lhůty odvodu má neznámý posun na pracovní den.',
            ),
        };
    }

    private function dayOfMonth(
        \DateTimeImmutable $month,
        ?int $day,
    ): \DateTimeImmutable {
        if ($day === null) {
            throw new \LogicException(
                'Pravidlo lhůty odvodu nemá základní den v měsíci.',
            );
        }

        return $month->setDate(
            (int) $month->format('Y'),
            (int) $month->format('n'),
            $day,
        );
    }

    private function month(string $periodStart): \DateTimeImmutable
    {
        $period = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $periodStart,
        );
        if (!$period instanceof \DateTimeImmutable
            || $period->format('Y-m-d') !== $periodStart
            || $period->format('d') !== '01'
        ) {
            throw new \InvalidArgumentException(
                'Mzdové období odvodu musí začínat prvním dnem měsíce.',
            );
        }

        return $period;
    }
}
