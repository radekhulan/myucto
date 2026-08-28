<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Důvody, proč roční zúčtování NELZE provést.
 *
 * Každý důvod je uzavřená množina — buď je splněné všechno, nebo se
 * nedopočítává nic. Roční zúčtování je právní úkon plátce daně, ne odhad:
 * § 38ch odst. 3 přímo říká, že plátce zúčtování „neprovede", chybí-li doklady,
 * a odst. 1 věta druhá totéž u poplatníka, který podá nebo je povinen podat
 * přiznání. „Aspoň částečně" tady žádnou oporu nemá.
 *
 * Kódy jsou stabilní — jde o klíče do i18n a do uloženého snapshotu, takže se
 * nepřejmenovávají. Věta pro člověka žije ve frontendu, ne tady.
 */
enum AnnualSettlementBlocker: string
{
    /**
     * § 38ch odst. 1: o zúčtování musí poplatník POŽÁDAT. Bez žádosti není co
     * provádět — a podle § 38h odst. 9 je jeho daňová povinnost splněna
     * sraženými zálohami.
     */
    case NotRequested = 'not_requested';

    /**
     * § 38ch odst. 1: žádost nejpozději do 15. února po uplynutí zdaňovacího
     * období.
     */
    case RequestedAfterDeadline = 'requested_after_deadline';

    /**
     * § 38k odst. 4 a § 38ch odst. 1: prohlášení k dani u tohoto plátce.
     * Bez podepsaného prohlášení plátce podle § 38h odst. 5 vůbec nepřihlíží ke
     * slevám a roční zúčtování nepřipadá v úvahu.
     */
    case DeclarationNotSigned = 'declaration_not_signed';

    /** Prohlášení je v evidenci vedené jako nedoložené — to není totéž co podepsané. */
    case DeclarationUnverified = 'declaration_unverified';

    /**
     * § 38ch odst. 3: doklady od všech předchozích plátců za uplynulé zdaňovací
     * období. Nepředloží-li je poplatník do 15. února, plátce zúčtování
     * neprovede.
     */
    case PriorEmployerDocumentsMissing = 'prior_employer_documents_missing';

    /** Doklady předchozích plátců došly, ale až po 15. únoru (§ 38ch odst. 3 věta druhá). */
    case PriorEmployerDocumentsLate = 'prior_employer_documents_late';

    /**
     * § 38ch odst. 1 věta druhá ve spojení s § 38g: poplatníkovi, který podá
     * nebo je povinen podat přiznání, zúčtování provést nelze.
     */
    case MustFileTaxReturn = 'must_file_tax_return';

    /**
     * Nevíme, zda poplatník přiznání podá nebo musí podat. „Nevíme" není
     * „nemusí" — dokud to není zodpovězené, zúčtování se neprovádí.
     */
    case FilingObligationUnknown = 'filing_obligation_unknown';

    /**
     * Poplatník uplatňuje něco, co se uplatňuje AŽ ROČNĚ (§ 38h odst. 6):
     * nezdanitelné části základu daně podle § 15, slevu na manžela
     * (§ 35ba odst. 1 písm. b), § 35bb) nebo slevu za zastavenou exekuci
     * (§ 35 odst. 4). Modul pro ně nemá datový model ani doložení, takže je
     * neumí spočítat — a spočítat zúčtování bez nich by znamenalo vyrobit číslo,
     * které je prokazatelně nižší, než na jaké má poplatník nárok.
     */
    case AnnualOnlyClaimsUnsupported = 'annual_only_claims_unsupported';

    /** O ročně uplatňovaných položkách nevíme nic. Viz výše — „nevíme" ≠ „žádné". */
    case AnnualOnlyClaimsUnknown = 'annual_only_claims_unknown';

    /**
     * § 38ch odst. 4 mluví o ÚHRNU mezd od všech plátců. Potvrzení od
     * předchozího plátce, které je v evidenci vedené jako nedoložené, do úhrnu
     * vzít nejde.
     */
    case ExternalCertificateUnverified = 'external_certificate_unverified';

    /**
     * Potvrzení od předchozího plátce sice v evidenci je, ale nenese všechno,
     * co § 38ch odst. 3 vyjmenovává: kromě zúčtované mzdy a sražených záloh
     * také POSKYTNUTÉ MĚSÍČNÍ SLEVY podle § 35ba a 35c a VYPLACENÉ MĚSÍČNÍ
     * DAŇOVÉ BONUSY. Bez těch položek by roční porovnání bonusů podle
     * § 35d odst. 7 vycházelo z neúplného úhrnu — a vyšel by přeplatek, který
     * poplatníkovi nenáleží.
     *
     * Které údaje konkrétně chybí, nese stopa výsledku
     * (`missing_statutory_fields` u každého potvrzení). Prázdné pole se NIKDY
     * nečte jako nula: `null` je „na potvrzení to není", `0` je „je tam nula".
     * Padá to i tehdy, chybí-li jediná složka jediného potvrzení.
     */
    case ExternalCertificateIncomplete = 'external_certificate_incomplete';

    /**
     * Roční kumulace daně pro daný rok v evidenci není (chybí opening balance
     * nebo schválené měsíce). Bez ní není z čeho počítat.
     */
    case AccumulatorMissing = 'accumulator_missing';

    /** V roce není ani jeden uzavřený měsíc — není co zúčtovávat. */
    case NoApprovedMonths = 'no_approved_months';

    /**
     * § 38ch odst. 4: zúčtování provede plátce nejpozději do 31. března.
     * Po termínu se v aplikaci neprovádí — opravy jdou cestou § 38i, ne
     * opožděným zúčtováním.
     */
    case SettlementDeadlinePassed = 'settlement_deadline_passed';

    /**
     * Poplatník není doložený daňový rezident ČR. § 38g odst. 2 věta čtvrtá:
     * poplatník podle § 2 odst. 3, který uplatňuje slevy podle § 35ba odst. 1
     * písm. b) až e), daňové zvýhodnění nebo nezdanitelnou část základu daně,
     * JE POVINEN podat přiznání. Modul nerezidentům měsíčně nepřiznává nic než
     * slevu na poplatníka, takže roční zúčtování u nich nedělá.
     */
    case NonResident = 'non_resident';

    /**
     * Nárok na slevu podle § 35ba je v evidenci vedený jako nedoložený.
     * § 38l odst. 2 vyjmenovává, čím se každá z nich prokazuje; bez toho ji
     * plátce uznat nesmí ani měsíčně, natož ročně.
     */
    case CreditEvidenceUnverified = 'credit_evidence_unverified';

    /** Nárok na daňové zvýhodnění není doložený podle § 38l odst. 3 a 4. */
    case ChildEvidenceUnverified = 'child_evidence_unverified';

    /**
     * Nároky na děti si v rámci roku odporují: mění se pořadí pro určení výše
     * (§ 35c odst. 1), chybí potvrzení o společně hospodařící domácnosti, není
     * vyloučeno souběžné uplatnění druhým poplatníkem (§ 35c odst. 9), nebo
     * pořadí netvoří souvislou řadu od jedné.
     */
    case ChildClaimConflict = 'child_claim_conflict';

    /**
     * Uplatněné dítě nemá úplnou identitu pro JMHZ nebo není zodpovězeno,
     * zda daňové zvýhodnění v některém měsíci uplatňovala jiná osoba ve
     * společné domácnosti. Po provedení se roční podklad zmrazí, proto tento
     * údaj nelze bezpečně doplňovat až při pozdějším podání JMHZ.
     */
    case ChildJmhzEvidenceIncomplete = 'child_jmhz_evidence_incomplete';

    /** Roční zúčtování už za tento rok proběhlo (§ 38ch odst. 4 — jednou ročně). */
    case AlreadySettled = 'already_settled';

    /** Ruleset daně z příjmů nepokrývá celý rok, takže roční sazby nejsou odvoditelné. */
    case RulesetYearNotCovered = 'ruleset_year_not_covered';
}
