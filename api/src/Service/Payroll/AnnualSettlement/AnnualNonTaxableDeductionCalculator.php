<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\IncomeTax\TaxIntegerMath;

/**
 * Nezdanitelné části základu daně (§ 15 ZDP) v ročním zúčtování.
 *
 * Jediné místo, kde se uplatňují limity § 15. Čistá funkce: dostane hrubý
 * základ (úhrn nezaokrouhlených zálohových základů od všech plátců) a seznam
 * položek, vrátí odpočet po limitech a rozpis, ze kterého je vidět, který limit
 * kterou položku zkrátil.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Limity
 * ─────────────────────────────────────────────────────────────────────────────
 * Dary (§ 15 odst. 1): odečíst lze, jen když úhrn PŘESÁHNE 2 % základu, nebo
 * činí ALESPOŇ 1 000 Kč — první nerovnost ostrá, druhá neostrá. V úhrnu nejvýše
 * 15 % základu; strop se zaokrouhluje na celé koruny dolů, protože odpočet se
 * uvádí v celých korunách a zaokrouhlení nahoru by překročilo zákonný strop.
 *
 * Úroky (§ 15 odst. 4): 150 000 Kč, při placení jen po část roku 1/12 za každý
 * měsíc placení. Měsíce se berou jako SJEDNOCENÍ přes úvěry — refinancování
 * v polovině roku (starý úvěr leden–červen, nový červenec–prosinec) je dvanáct
 * měsíců placení, ne šest. Bytová potřeba obstaraná před 1. 1. 2021 má limit
 * 300 000 Kč; při souběhu jde nový úvěr nejvýš do svého limitu a oba dohromady
 * nejvýš do 300 000 Kč za měsíce placení kteréhokoli z nich.
 *
 * Spoření na stáří a dlouhodobá péče (§ 15 odst. 6): jeden společný limit
 * 48 000 Kč pro penzijní produkty, životní pojištění, DIP i pojištění
 * dlouhodobé péče.
 *
 * Úhrn odpočtů nesmí přesáhnout základ. Kdyby přesáhl, krátí se v pořadí
 * odstavců § 15: nejdřív se vejdou dary, pak úroky, pak spoření. Na výsledné
 * dani to nezáleží (základ je tak jako tak nula), jen na tom, co se vykáže.
 *
 * Sleva za zastavenou exekuci (§ 35 odst. 4) sem nepatří — snižuje daň, ne
 * základ. Vrací se jen její úhrn, uplatní ji kalkulačka zúčtování.
 */
final class AnnualNonTaxableDeductionCalculator
{
    /** @param list<AnnualNonTaxableClaim> $claims */
    public function calculate(
        int $taxYear,
        int $taxBaseMinorUnits,
        array $claims,
    ): AnnualNonTaxableDeductions {
        if ($taxBaseMinorUnits < 0) {
            throw new \InvalidArgumentException('Základ daně nesmí být záporný.');
        }
        if ($claims === []) {
            return AnnualNonTaxableDeductions::none();
        }
        AnnualNonTaxableStatute::assertSupportedYear($taxYear);

        $donationClaimed = 0;
        $retirementClaimed = 0;
        $enforcementCredit = 0;
        $newInterest = 0;
        $legacyInterest = 0;
        $newInterestMonths = 0;
        $legacyInterestMonths = 0;
        $claimedByKind = [];
        foreach ($claims as $claim) {
            $value = $claim->valueMinorUnits();
            $claimedByKind[$claim->kind->value] = TaxIntegerMath::add(
                $claimedByKind[$claim->kind->value] ?? 0,
                $value,
            );
            if ($claim->kind->isDonation()) {
                $donationClaimed = TaxIntegerMath::add($donationClaimed, $value);
            } elseif ($claim->kind->isRetirementOrCare()) {
                $retirementClaimed = TaxIntegerMath::add($retirementClaimed, $value);
            } elseif ($claim->kind === AnnualNonTaxableKind::EnforcementStop) {
                $enforcementCredit = TaxIntegerMath::add($enforcementCredit, $value);
            } elseif ($claim->housingAcquiredBefore2021) {
                $legacyInterest = TaxIntegerMath::add($legacyInterest, $value);
                $legacyInterestMonths |= (int) $claim->monthsMask;
            } else {
                $newInterest = TaxIntegerMath::add($newInterest, $value);
                $newInterestMonths |= (int) $claim->monthsMask;
            }
        }
        ksort($claimedByKind);

        // § 15 odst. 1 — dary.
        $donationThresholdMet = $donationClaimed > 0 && (
            $donationClaimed * 100 > $taxBaseMinorUnits * AnnualNonTaxableStatute::DONATION_MINIMUM_PERCENT
            || $donationClaimed >= AnnualNonTaxableStatute::DONATION_MINIMUM_MINOR_UNITS
        );
        $donationCap = intdiv(
            intdiv($taxBaseMinorUnits * AnnualNonTaxableStatute::DONATION_MAXIMUM_PERCENT, 100),
            100,
        ) * 100;
        $donationAllowed = $donationThresholdMet ? min($donationClaimed, $donationCap) : 0;

        // § 15 odst. 4 — úroky.
        $newInterestCap = intdiv(
            AnnualNonTaxableStatute::HOUSING_INTEREST_LIMIT_MINOR_UNITS
                * AnnualSpouseCreditClaim::bitCount($newInterestMonths),
            AnnualTaxRates::MONTHS_IN_YEAR,
        );
        $interestCap = $newInterestCap;
        $interestAllowed = min($newInterest, $newInterestCap);
        if ($legacyInterestMonths !== 0) {
            $interestCap = intdiv(
                AnnualNonTaxableStatute::HOUSING_INTEREST_LEGACY_LIMIT_MINOR_UNITS
                    * AnnualSpouseCreditClaim::bitCount($legacyInterestMonths | $newInterestMonths),
                AnnualTaxRates::MONTHS_IN_YEAR,
            );
            $interestAllowed = min($interestCap, $interestAllowed + $legacyInterest);
        }

        // § 15 odst. 6 — společný limit.
        $retirementAllowed = min(
            $retirementClaimed,
            AnnualNonTaxableStatute::RETIREMENT_AND_CARE_LIMIT_MINOR_UNITS,
        );

        // Úhrn odpočtů nejvýš do výše základu, v pořadí odstavců § 15.
        $remaining = $taxBaseMinorUnits;
        $donationApplied = min($donationAllowed, $remaining);
        $remaining -= $donationApplied;
        $interestApplied = min($interestAllowed, $remaining);
        $remaining -= $interestApplied;
        $retirementApplied = min($retirementAllowed, $remaining);

        return new AnnualNonTaxableDeductions(
            donationClaimedMinorUnits: $donationClaimed,
            donationThresholdMet: $donationThresholdMet,
            donationCapMinorUnits: $donationCap,
            donationMinorUnits: $donationApplied,
            interestClaimedMinorUnits: TaxIntegerMath::add($newInterest, $legacyInterest),
            interestMonths: AnnualSpouseCreditClaim::bitCount(
                $newInterestMonths | $legacyInterestMonths,
            ),
            interestCapMinorUnits: $interestCap,
            interestMinorUnits: $interestApplied,
            retirementClaimedMinorUnits: $retirementClaimed,
            retirementMinorUnits: $retirementApplied,
            enforcementCreditClaimedMinorUnits: $enforcementCredit,
            claimedByKind: $claimedByKind,
        );
    }
}
