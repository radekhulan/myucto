<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Druh položky uplatňované až v ročním zúčtování (§ 38h odst. 6).
 *
 * Hodnoty jsou stabilní — jsou to hodnoty sloupce
 * `payroll_annual_settlement_deductions.kind` a klíče do i18n.
 *
 * `EnforcementStop` mezi nezdanitelné části nepatří, je to sleva na dani podle
 * § 35 odst. 4. Eviduje se ve stejném seznamu, protože se dokládá i zadává
 * stejně (částka, doklad, kdo ji přiznal) — výpočet ji ale bere zvlášť a uplatní
 * ji až na daň, ne na základ.
 */
enum AnnualNonTaxableKind: string
{
    /** § 15 odst. 1 — bezúplatné plnění (dar) v penězích nebo ve hodnotě. */
    case Donation = 'donation';
    /** § 15 odst. 1 — odběr krve nebo jejích složek, 3 000 Kč za odběr. */
    case BloodDonation = 'blood_donation';
    /** § 15 odst. 1 — odběr orgánu od žijícího dárce, 20 000 Kč. */
    case OrganDonation = 'organ_donation';
    /** § 15 odst. 1 — odběr krvetvorných buněk, 20 000 Kč za odběr. */
    case StemCellDonation = 'stem_cell_donation';
    /** § 15 odst. 3 a 4 — úroky z úvěru na financování bytové potřeby. */
    case HousingInterest = 'housing_interest';
    /** § 15 odst. 6 a 7, § 15a odst. 1 písm. a) až c) — penzijní produkty. */
    case PensionSavings = 'pension_savings';
    /** § 15 odst. 6, § 15a odst. 3 — soukromé životní pojištění. */
    case LifeInsurance = 'life_insurance';
    /** § 15 odst. 6, § 15a odst. 5 — dlouhodobý investiční produkt. */
    case LongTermInvestment = 'long_term_investment';
    /** § 15 odst. 6 — pojištění dlouhodobé péče. */
    case LongTermCareInsurance = 'long_term_care_insurance';
    /** § 35 odst. 4 — sleva na dani za zastavenou exekuci. */
    case EnforcementStop = 'enforcement_stop';

    /** Darem se oceňuje počet odběrů, ne zadaná částka. */
    public function isCountedDonation(): bool
    {
        return in_array($this, [
            self::BloodDonation,
            self::OrganDonation,
            self::StemCellDonation,
        ], true);
    }

    public function isDonation(): bool
    {
        return $this === self::Donation || $this->isCountedDonation();
    }

    /** Společný limit § 15 odst. 6. */
    public function isRetirementOrCare(): bool
    {
        return in_array($this, [
            self::PensionSavings,
            self::LifeInsurance,
            self::LongTermInvestment,
            self::LongTermCareInsurance,
        ], true);
    }
}
