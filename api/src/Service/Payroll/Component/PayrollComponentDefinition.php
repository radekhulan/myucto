<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Accounting\PayrollAccountCode;
use MyInvoice\Service\Payroll\Calculation\Money;

final readonly class PayrollComponentDefinition
{
    public function __construct(
        public string $code,
        public string $name,
        public PayrollComponentKind $kind,
        public PayrollComponentValueKind $valueKind,
        public PayrollComponentFrequency $frequency,
        public PayrollComponentTaxTreatment $taxTreatment,
        public PayrollComponentInclusion $socialParticipationTreatment,
        public PayrollComponentInclusion $socialTreatment,
        public PayrollComponentInclusion $healthParticipationTreatment,
        public PayrollComponentInclusion $healthTreatment,
        public PayrollComponentInclusion $averageEarningTreatment,
        public PayrollComponentInclusion $enforcementTreatment,
        public PayrollComponentInclusion $jmhzTreatment,
        public PayrollComponentInclusion $statisticsTreatment,
        public ?string $accountingDebitCode = null,
        public ?string $accountingCreditCode = null,
        public ?int $annualLimitMinor = null,
        public ?PayrollBenefitExemptionBasket $exemptionBasket = null,
        public ?PayrollExemptionBasis $exemptionBasis = null,
    ) {
        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Kód mzdové složky není platný.');
        }
        if (trim($name) === '' || mb_strlen($name) > 190) {
            throw new \InvalidArgumentException('Název mzdové složky není platný.');
        }
        foreach ([
            'MD' => $accountingDebitCode,
            'Dal' => $accountingCreditCode,
        ] as $side => $account) {
            if ($account !== null && !PayrollAccountCode::isValid($account)) {
                throw new \InvalidArgumentException("Účet {$side} mzdové složky není platný.");
            }
        }
        if ($annualLimitMinor !== null && $annualLimitMinor <= 0) {
            throw new \InvalidArgumentException('Roční limit mzdové složky musí být kladný.');
        }
        if ($annualLimitMinor !== null && !$kind->isBenefit()) {
            throw new \InvalidArgumentException(
                'Roční limit lze nastavit jen pro benefitní složku.'
            );
        }
        if ($exemptionBasket !== null && !$kind->isBenefit()) {
            throw new \InvalidArgumentException(
                'Zákonný koš osvobození lze nastavit jen pro benefitní složku.'
            );
        }
        if ($exemptionBasis !== null
            && $taxTreatment !== PayrollComponentTaxTreatment::EXEMPT
        ) {
            throw new \InvalidArgumentException(
                'Podklad osvobození má smysl jen u složky osvobozené od daně.'
            );
        }
        if ($exemptionBasis?->requiresFrozenSplit() === true && $exemptionBasket === null) {
            throw new \InvalidArgumentException(
                'Podklad limitovaného osvobození vyžaduje zařazení složky do zákonného koše.'
            );
        }
    }

    public function impact(Money $amount): PayrollComponentImpact
    {
        $this->assertAutomaticallyClassified();
        $zero = new Money(0, $amount->currency);

        return new PayrollComponentImpact(
            sourceAmount: $amount,
            cashPayable: $this->valueKind === PayrollComponentValueKind::MONETARY
                ? $amount
                : $zero,
            taxBase: in_array($this->taxTreatment, [
                PayrollComponentTaxTreatment::INCLUDED,
                PayrollComponentTaxTreatment::WITHHOLDING_CANDIDATE,
            ], true) ? $amount : $zero,
            socialBase: $this->included($this->socialTreatment) ? $amount : $zero,
            healthBase: $this->included($this->healthTreatment) ? $amount : $zero,
            averageEarningBase: $this->included($this->averageEarningTreatment)
                ? $amount
                : $zero,
            enforcementBase: $this->included($this->enforcementTreatment)
                ? $amount
                : $zero,
            jmhzAmount: $this->included($this->jmhzTreatment) ? $amount : $zero,
            statisticsIncluded:
                $this->statisticsTreatment === PayrollComponentInclusion::INCLUDED,
            withholdingCandidate:
                $this->taxTreatment === PayrollComponentTaxTreatment::WITHHOLDING_CANDIDATE,
        );
    }

    /** @return array<string,string|int|null> */
    public function snapshot(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'component_kind' => $this->kind->value,
            'value_kind' => $this->valueKind->value,
            'frequency_kind' => $this->frequency->value,
            'tax_treatment' => $this->taxTreatment->value,
            'social_participation_treatment' =>
                $this->socialParticipationTreatment->value,
            'social_treatment' => $this->socialTreatment->value,
            'health_participation_treatment' =>
                $this->healthParticipationTreatment->value,
            'health_treatment' => $this->healthTreatment->value,
            'average_earning_treatment' => $this->averageEarningTreatment->value,
            'enforcement_treatment' => $this->enforcementTreatment->value,
            'jmhz_treatment' => $this->jmhzTreatment->value,
            'statistics_treatment' => $this->statisticsTreatment->value,
            'accounting_debit_code' => $this->accountingDebitCode,
            'accounting_credit_code' => $this->accountingCreditCode,
            'annual_limit_minor' => $this->annualLimitMinor,
            'exemption_basket' => $this->exemptionBasket?->value,
            'exemption_basis' => $this->exemptionBasis?->value,
        ];
    }

    private function assertAutomaticallyClassified(): void
    {
        if ($this->taxTreatment === PayrollComponentTaxTreatment::MANUAL_REVIEW) {
            throw new \DomainException(
                "Mzdová složka {$this->code} vyžaduje ruční posouzení daně."
            );
        }
        foreach ([
            'účasti na sociálním pojištění' =>
                $this->socialParticipationTreatment,
            'sociálního pojištění' => $this->socialTreatment,
            'účasti na zdravotním pojištění' =>
                $this->healthParticipationTreatment,
            'zdravotního pojištění' => $this->healthTreatment,
            'průměrného výdělku' => $this->averageEarningTreatment,
            'srážek' => $this->enforcementTreatment,
            'JMHZ' => $this->jmhzTreatment,
            'statistiky' => $this->statisticsTreatment,
        ] as $label => $treatment) {
            if ($treatment === PayrollComponentInclusion::MANUAL_REVIEW) {
                throw new \DomainException(
                    "Mzdová složka {$this->code} vyžaduje ruční posouzení {$label}."
                );
            }
        }
    }

    private function included(PayrollComponentInclusion $treatment): bool
    {
        return $treatment === PayrollComponentInclusion::INCLUDED;
    }
}
