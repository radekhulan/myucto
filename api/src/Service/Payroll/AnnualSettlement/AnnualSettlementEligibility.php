<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use DateTimeImmutable;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;

/**
 * Posouzení podmínek § 38ch — smí se roční zúčtování vůbec provést?
 *
 * Oddělené od výpočtu schválně. Výpočet je čistá aritmetika nad kumulacemi;
 * tohle je právní posouzení proti kalendáři a evidenci. Kdyby to bylo v jedné
 * třídě, nešlo by otestovat ani jedno bez druhého — a hlavně by se dalo tiše
 * obejít, protože výpočet by uměl vrátit číslo i tam, kde na něj není nárok.
 *
 * Vrací SEZNAM překážek, ne první nalezenou. Uživatel, který má tři chybějící
 * podklady, se o nich má dozvědět najednou, ne třikrát po sobě.
 */
final class AnnualSettlementEligibility
{
    /**
     * @param list<AnnualSettlementBlocker> $externalBlockers
     *        Překážky zjištěné jinde (kumulace chybí, ruleset nepokrývá rok,
     *        zúčtování už proběhlo) — posouzení je jen převezme, aby výsledek
     *        nesl úplný seznam.
     * @return list<AnnualSettlementBlocker>
     */
    public function evaluate(
        AnnualSettlementRequest $request,
        TaxDeclarationStatus $declarationStatus,
        TaxResidence $residence,
        DateTimeImmutable $today,
        array $externalBlockers = [],
    ): array {
        $blockers = $externalBlockers;
        $taxYear = $request->taxYear;

        // § 38ch odst. 1: o zúčtování musí být požádáno, a to do 15. února.
        $blockers = match ($request->status) {
            AnnualSettlementRequestStatus::Requested => $blockers,
            AnnualSettlementRequestStatus::NotRequested,
            AnnualSettlementRequestStatus::Withdrawn,
            AnnualSettlementRequestStatus::Unknown =>
                [...$blockers, AnnualSettlementBlocker::NotRequested],
        };
        if ($request->status === AnnualSettlementRequestStatus::Requested
            && $request->requestedOn !== null
            && $request->requestedOn > AnnualSettlementStatute::requestDeadline($taxYear)
        ) {
            $blockers[] = AnnualSettlementBlocker::RequestedAfterDeadline;
        }

        // § 38k odst. 4 a § 38ch odst. 1: prohlášení k dani u tohoto plátce.
        $blockers = match ($declarationStatus) {
            TaxDeclarationStatus::Signed => $blockers,
            TaxDeclarationStatus::NotSigned =>
                [...$blockers, AnnualSettlementBlocker::DeclarationNotSigned],
            TaxDeclarationStatus::Unverified =>
                [...$blockers, AnnualSettlementBlocker::DeclarationUnverified],
        };

        // § 38ch odst. 3: doklady od všech předchozích plátců do 15. února.
        $blockers = match ($request->priorEmployers) {
            AnnualSettlementPriorEmployers::None,
            AnnualSettlementPriorEmployers::AllDocumented => $blockers,
            AnnualSettlementPriorEmployers::Missing,
            AnnualSettlementPriorEmployers::Unknown =>
                [...$blockers, AnnualSettlementBlocker::PriorEmployerDocumentsMissing],
        };
        if ($request->priorEmployers === AnnualSettlementPriorEmployers::AllDocumented
            && $request->priorDocumentsReceivedOn !== null
            && $request->priorDocumentsReceivedOn
                > AnnualSettlementStatute::priorDocumentsDeadline($taxYear)
        ) {
            $blockers[] = AnnualSettlementBlocker::PriorEmployerDocumentsLate;
        }

        // § 38ch odst. 1 věta druhá ve spojení s § 38g.
        $blockers = match ($request->filingObligation) {
            AnnualSettlementFilingObligation::None => $blockers,
            AnnualSettlementFilingObligation::Required =>
                [...$blockers, AnnualSettlementBlocker::MustFileTaxReturn],
            AnnualSettlementFilingObligation::Unknown =>
                [...$blockers, AnnualSettlementBlocker::FilingObligationUnknown],
        };

        // § 38h odst. 6: položky uplatňované až ročně, které modul neumí.
        $blockers = match ($request->annualClaims) {
            AnnualSettlementAnnualClaims::None => $blockers,
            AnnualSettlementAnnualClaims::PresentUnsupported =>
                [...$blockers, AnnualSettlementBlocker::AnnualOnlyClaimsUnsupported],
            AnnualSettlementAnnualClaims::Unknown =>
                [...$blockers, AnnualSettlementBlocker::AnnualOnlyClaimsUnknown],
        };

        // § 38g odst. 2: nerezident uplatňující cokoli nad slevu na poplatníka
        // je povinen podat přiznání, takže mu plátce zúčtování provést nemůže.
        if ($residence !== TaxResidence::CzechResident) {
            $blockers[] = AnnualSettlementBlocker::NonResident;
        }

        // § 38ch odst. 1 a 4: zúčtování je úkon nad UPLYNULÝM zdaňovacím
        // obdobím. Lhůta má dva konce — dokud rok neskončil, není z čeho
        // počítat úhrn, a roční sleva na poplatníka se přitom nekrátí, takže by
        // z půlky roku vyšel přeplatek, který poplatníkovi nenáleží.
        $day = $today->setTime(0, 0);
        if ($day < AnnualSettlementStatute::settlementEarliest($taxYear)) {
            $blockers[] = AnnualSettlementBlocker::TaxYearNotFinished;
        }

        // § 38ch odst. 4: zúčtování se provádí nejpozději do 31. března.
        // Porovnává se DEN, ne okamžik: `new DateTimeImmutable()` nese i čas,
        // takže syrové porovnání by 31. března v devět ráno vyhodnotilo jako
        // „po lhůtě" a poslední den lhůty by se nedal využít.
        if ($day > AnnualSettlementStatute::settlementDeadline($taxYear)) {
            $blockers[] = AnnualSettlementBlocker::SettlementDeadlinePassed;
        }

        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker->value] = $blocker;
        }
        ksort($unique);

        return array_values($unique);
    }
}
