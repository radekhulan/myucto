<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Jeden nález nad limity a zákazy § 93, § 240 odst. 3, § 245 odst. 1 a § 78
 * odst. 1 písm. i). Nese jen fakta — do věty pro uživatele ho převádí
 * {@see OvertimeLimitEvaluator}, do validace mzdového běhu
 * {@see PayrollOvertimeLimitService}.
 *
 * `provision` je odkaz na ustanovení, o které se nález opírá; drží se
 * strojově, aby ho obrazovka i validace běhu mohly zobrazit odděleně od věty
 * a aby se nálezy daly filtrovat podle ustanovení.
 *
 * `requiresOverride` odděluje PŘEKROČENÍ LIMITU od PORUŠENÍ ZÁKAZU. Překročený
 * limit je vada, na kterou stačí upozornit; zákaz práce přesčas u chráněné
 * skupiny se ale nesmí dát odklepnout mlčky, proto si vynutí ruční výjimku
 * s pojmenovaným důvodem.
 */
final readonly class OvertimeLimitFinding
{
    public const CODE_MANUAL_SUMMARY = 'overtime_manual_summary_review_required';
    public const CODE_WEEKLY = 'overtime_ordered_weekly_limit_exceeded';
    public const CODE_YEARLY = 'overtime_ordered_annual_limit_exceeded';
    public const CODE_AVERAGING = 'overtime_averaging_period_limit_exceeded';
    public const CODE_YEARLY_APPROACHING = 'overtime_annual_limit_approaching';
    public const CODE_ROLLING_WEEK = 'overtime_ordered_rolling_week_limit_exceeded';
    public const CODE_PROHIBITED_JUVENILE = 'overtime_prohibited_juvenile';
    public const CODE_PROHIBITED_PREGNANCY = 'overtime_prohibited_pregnancy';
    public const CODE_PROHIBITED_CHILD_CARE = 'overtime_ordered_prohibited_child_care';
    public const CODE_PROHIBITED_PART_TIME = 'overtime_ordered_prohibited_part_time';
    public const CODE_BIRTH_DATE_MISSING = 'overtime_juvenile_check_unavailable';

    public function __construct(
        public string $code,
        public string $severity,
        public string $message,
        public ?int $actualMinutes,
        public int $limitMinutes,
        public string $scopeFrom,
        public string $scopeTo,
        public bool $consentEvidenced,
        public string $provision = '§ 93 zákoníku práce',
        public bool $requiresOverride = false,
    ) {
        if (!in_array($severity, ['warning', 'info'], true)) {
            throw new \InvalidArgumentException(
                'Nález limitu přesčasu smí být jen varování nebo informace.',
            );
        }
        if ($requiresOverride && $severity !== 'warning') {
            throw new \InvalidArgumentException(
                'Ruční výjimku smí vyžadovat jen varování.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
            'actual_minutes' => $this->actualMinutes,
            'limit_minutes' => $this->limitMinutes,
            'scope_from' => $this->scopeFrom,
            'scope_to' => $this->scopeTo,
            'consent_evidenced' => $this->consentEvidenced,
            'provision' => $this->provision,
            'requires_override' => $this->requiresOverride,
        ];
    }
}
