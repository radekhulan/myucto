<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class HealthInsuranceRelationshipInput
{
    /**
     * Prázdný seznam = měsíc bez příjmu vysvětlený nepřítomností; viz
     * {@see \MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceRelationshipInput::$components}.
     *
     * @var list<HealthAssessmentComponent>
     */
    public array $components;

    /** @param array<mixed> $components */
    public function __construct(
        public string $relationshipId,
        public HealthEmploymentKind $kind,
        public string $employmentFrom,
        public ?string $employmentTo,
        public HealthIncomeAttribution $incomeAttribution,
        array $components,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $relationshipId) !== 1) {
            throw new InvalidArgumentException('Health insurance relationship ID is not canonical.');
        }
        $start = self::date($employmentFrom);
        if ($employmentTo !== null && self::date($employmentTo) < $start) {
            throw new InvalidArgumentException('Employment end cannot precede its start.');
        }
        if (
            (
                $incomeAttribution === HealthIncomeAttribution::PostTerminationEndMonthVerified
                || $incomeAttribution
                    === HealthIncomeAttribution::PostTerminationPaymentMonthVerified
            )
            && $employmentTo === null
        ) {
            throw new InvalidArgumentException(
                'Verified post-termination attribution requires the employment end date.',
            );
        }
        if (
            $incomeAttribution === HealthIncomeAttribution::PostTerminationEndMonthVerified
            && $kind !== HealthEmploymentKind::Dpp
            && $kind !== HealthEmploymentKind::Dpc
        ) {
            throw new InvalidArgumentException(
                'End-month post-termination attribution is reserved for DPP and DPČ.',
            );
        }
        if (
            $incomeAttribution === HealthIncomeAttribution::PostTerminationPaymentMonthVerified
            && ($kind === HealthEmploymentKind::Dpp || $kind === HealthEmploymentKind::Dpc)
        ) {
            throw new InvalidArgumentException(
                'DPP and DPČ post-termination income must be attributed to the agreement end month.',
            );
        }
        if (!array_is_list($components)) {
            throw new InvalidArgumentException(
                'Health insurance relationship components must be a list.',
            );
        }
        foreach ($components as $component) {
            if (!$component instanceof HealthAssessmentComponent) {
                throw new InvalidArgumentException(
                    'Health insurance components must use the dedicated input type.',
                );
            }
        }

        $this->components = $components;
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Health insurance dates must use YYYY-MM-DD.');
        }

        return $date;
    }
}
