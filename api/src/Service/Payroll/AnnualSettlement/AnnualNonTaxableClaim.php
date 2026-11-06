<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use JsonSerializable;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;

/**
 * Jedna ročně uplatňovaná položka — dar, úvěr, smlouva, přiznaná náhrada.
 *
 * Konstruktor odmítá jen to, co nedává smysl ani jako rozpracovaná evidence
 * (záporná částka, odběr bez počtu, úroky bez měsíců). Doložení se tu
 * nevynucuje — nedoložená položka se uložit smí a zúčtování ji pak zastaví
 * překážkou, stejně jako u potvrzení od předchozích plátců.
 *
 * Částka je to, co poplatník uplatňuje: u úroků jeho podíl (§ 15 odst. 4 věta
 * první), u penzijních produktů část nad hranicí nejvyššího státního příspěvku
 * z potvrzení fondu (§ 15 odst. 7), u jednorázového životního pojištění
 * poměrná část připadající na rok. Limity zákona uplatní až výpočet.
 */
final readonly class AnnualNonTaxableClaim implements JsonSerializable
{
    public const ALL_MONTHS_MASK = 0xFFF;

    public function __construct(
        public string $reference,
        public AnnualNonTaxableKind $kind,
        public ?int $amountMinorUnits,
        public ?int $quantity = null,
        /** Bit 0 = leden. Jen u úroků — měsíce, v nichž poplatník úroky platil. */
        public ?int $monthsMask = null,
        /** Jen u úroků: bytová potřeba obstaraná před 1. 1. 2021. */
        public bool $housingAcquiredBefore2021 = false,
        public TaxEvidenceStatus $evidenceStatus = TaxEvidenceStatus::Unverified,
        public ?string $evidenceReference = null,
        public ?string $counterpartyName = null,
    ) {
        if (trim($reference) === '') {
            throw new \InvalidArgumentException('Položka ročního zúčtování nemá označení.');
        }
        if ($kind->isCountedDonation()) {
            if ($quantity === null || $quantity < 1 || $quantity > 100) {
                throw new \InvalidArgumentException(
                    'U odběru je potřeba uvést počet odběrů (1 až 100).',
                );
            }
            if ($amountMinorUnits !== null) {
                throw new \InvalidArgumentException(
                    'Odběr se oceňuje zákonnou částkou, vlastní částka se nezadává.',
                );
            }
        } else {
            if ($amountMinorUnits === null || $amountMinorUnits <= 0) {
                throw new \InvalidArgumentException(
                    'Položka ročního zúčtování musí mít kladnou částku.',
                );
            }
            if ($amountMinorUnits > 100_000_000_000) {
                throw new \InvalidArgumentException(
                    'Částka položky ročního zúčtování je mimo rozsah.',
                );
            }
            if ($quantity !== null) {
                throw new \InvalidArgumentException('Počet se uvádí jen u odběrů.');
            }
        }
        if ($kind === AnnualNonTaxableKind::HousingInterest) {
            if ($monthsMask === null || $monthsMask < 1 || $monthsMask > self::ALL_MONTHS_MASK) {
                throw new \InvalidArgumentException(
                    'U úroků je potřeba označit měsíce, v nichž se úroky platily.',
                );
            }
        } elseif ($monthsMask !== null || $housingAcquiredBefore2021) {
            throw new \InvalidArgumentException(
                'Měsíce a datum obstarání bytové potřeby se uvádějí jen u úroků.',
            );
        }
    }

    public function isVerified(): bool
    {
        return $this->evidenceStatus === TaxEvidenceStatus::Verified;
    }

    /** Hodnota, se kterou počítá výpočet — u odběrů zákonné ocenění. */
    public function valueMinorUnits(): int
    {
        if ($this->kind->isCountedDonation()) {
            return AnnualNonTaxableStatute::countedDonationValue($this->kind)
                * (int) $this->quantity;
        }

        return (int) $this->amountMinorUnits;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'reference' => $this->reference,
            'kind' => $this->kind->value,
            'amount_minor_units' => $this->amountMinorUnits,
            'quantity' => $this->quantity,
            'months_mask' => $this->monthsMask,
            'housing_acquired_before_2021' => $this->housingAcquiredBefore2021,
            'value_minor_units' => $this->valueMinorUnits(),
            'evidence_status' => $this->evidenceStatus->value,
            'evidence_reference' => $this->evidenceReference,
            'counterparty_name' => $this->counterpartyName,
        ];
    }
}
