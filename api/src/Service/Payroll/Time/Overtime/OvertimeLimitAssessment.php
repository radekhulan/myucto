<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Výsledek posouzení limitů § 93 a zákazů práce přesčas pro jeden pracovní
 * vztah a jedno mzdové období.
 *
 * Kromě nálezů nese i holá čísla, aby docházka mohla ukázat stav i tehdy, když
 * se ještě nic nepřekročilo — smysl kontroly je přijít na to VČAS, ne až po
 * překročení.
 */
final readonly class OvertimeLimitAssessment
{
    /**
     * @param list<OvertimeLimitFinding> $findings
     * @param list<array{week_start:string,week_end:string,minutes:int}> $weeks
     * @param array<string,int> $prohibitedMinutes minuty přesčasu v období,
     *        které padly na den se zákazem, podle důvodu zákazu
     */
    public function __construct(
        public int $employmentId,
        public array $findings,
        public array $weeks,
        public ?int $orderedYearMinutes,
        public int $orderedYearLimitMinutes,
        public ?int $agreedYearMinutes,
        public ?string $averagingFrom,
        public ?string $averagingTo,
        public int $averagingWeeks,
        public ?int $averagingMinutes,
        public int $averagingLimitMinutes,
        public bool $consentEvidenced,
        public bool $limitsFromRuleset,
        public ?int $averagingCompensatedMinutes = 0,
        public string $averagingBasis = OvertimeLimits::BASIS_STATUTORY,
        public ?string $averagingReference = null,
        public array $prohibitedMinutes = [],
        public array $manualSummaryPeriods = [],
    ) {}

    /** Neúplný souhrn neruší již doložená porušení v ostatních měsících. @param list<string> $periods */
    public function withManualSummaryPeriods(array $periods, string $from, string $to): self
    {
        return new self(
            employmentId: $this->employmentId,
            findings: [new OvertimeLimitFinding(
                code: OvertimeLimitFinding::CODE_MANUAL_SUMMARY,
                severity: 'warning',
                message: 'Ruční souhrn pracovní doby nemá denní rozpad přesčasů. Limity ani zákazy práce přesčas nelze ověřit; doložte ruční posouzení.',
                actualMinutes: null, limitMinutes: $this->orderedYearLimitMinutes,
                scopeFrom: $from, scopeTo: $to, consentEvidenced: $this->consentEvidenced,
                requiresOverride: true,
            ), ...$this->findings],
            weeks: [], orderedYearMinutes: null, orderedYearLimitMinutes: $this->orderedYearLimitMinutes,
            agreedYearMinutes: null, averagingFrom: $this->averagingFrom, averagingTo: $this->averagingTo,
            averagingWeeks: $this->averagingWeeks, averagingMinutes: null, averagingLimitMinutes: $this->averagingLimitMinutes,
            consentEvidenced: $this->consentEvidenced, limitsFromRuleset: $this->limitsFromRuleset,
            averagingCompensatedMinutes: null, averagingBasis: $this->averagingBasis,
            averagingReference: $this->averagingReference, prohibitedMinutes: $this->prohibitedMinutes,
            manualSummaryPeriods: $periods,
        );
    }

    public function hasWarnings(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === 'warning') {
                return true;
            }
        }

        return false;
    }

    /** Je mezi nálezy porušení zákazu, které nesmí projít mlčky? */
    public function requiresOverride(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->requiresOverride) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            ...($this->manualSummaryPeriods === [] ? [] : [
                'evidence_status' => 'manual_review_required',
                'manual_summary_periods' => $this->manualSummaryPeriods,
            ]),
            'employment_id' => $this->employmentId,
            'findings' => array_map(
                static fn (OvertimeLimitFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            'weeks' => $this->weeks,
            'ordered_year_minutes' => $this->orderedYearMinutes,
            'ordered_year_limit_minutes' => $this->orderedYearLimitMinutes,
            'agreed_year_minutes' => $this->agreedYearMinutes,
            'averaging_from' => $this->averagingFrom,
            'averaging_to' => $this->averagingTo,
            'averaging_weeks' => $this->averagingWeeks,
            'averaging_minutes' => $this->averagingMinutes,
            'averaging_limit_minutes' => $this->averagingLimitMinutes,
            'averaging_compensated_minutes' => $this->averagingCompensatedMinutes,
            'averaging_basis' => $this->averagingBasis,
            'averaging_reference' => $this->averagingReference,
            'prohibited_minutes' => $this->prohibitedMinutes,
            'requires_override' => $this->requiresOverride(),
            'consent_evidenced' => $this->consentEvidenced,
            'limits_from_ruleset' => $this->limitsFromRuleset,
        ];
    }
}
