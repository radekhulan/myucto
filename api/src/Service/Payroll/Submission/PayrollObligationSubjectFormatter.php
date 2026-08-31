<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;

/**
 * Lidsky čitelný „předmět" mzdové povinnosti z jejího `subject_reference` —
 * u agendových povinností (`payroll_obligations`) je to interní složený klíč
 * (`payroll_run:8:office:4`, `payroll_run:8:111`,
 * `health_bulk_notification:2026-08:111`, `employment:37`, …), ne text pro
 * účetní.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč je tohle SDÍLENÁ třída, ne kopie ve třech panelech
 * ═══════════════════════════════════════════════════════════════════════════
 * Stejná ošklivá syrová hodnota se objevila na třech místech nezávisle:
 * v měsíčním přehledu ({@see PayrollMonthlyChecklistService}), v inboxu
 * ({@see \MyInvoice\Action\Payroll\PayrollSubmissionInboxAction}) a v přehledu
 * podání ({@see \MyInvoice\Action\Payroll\PayrollSubmissionOverviewAction}) —
 * všechny tři čtou tentýž sloupec `payroll_obligations.subject_reference`.
 * Kdyby si formát každý panel vykládal po svém, dřív nebo později by se
 * rozešly v tom, co je „ověřené" a co ne.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč je vstupem i `agendaCode`, ne jen `subjectReference`
 * ═══════════════════════════════════════════════════════════════════════════
 * `payroll_run:{runId}:{X}` má DVA různé významy podle agendy, které samotný
 * tvar řetězce nerozliší: u JMHZ/REGZEL je `X` doslovné `office:{officeId}`
 * (čtyři segmenty), u přehledu o platbě pojistného (PPZ) je `X` rovnou kód
 * pojišťovny (tři segmenty — `JmhzSubmissionBridgeService::runReference()`
 * vs. `HealthInsuranceSubmissionService::register()`). Bez agendy by tenhle
 * rozdíl šel jen HÁDAT podle počtu dvojteček — a to je přesně ten druh
 * odhadu, který si tahle třída nesmí dovolit.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Pravidlo: radši nic než hádaný popis
 * ═══════════════════════════════════════════════════════════════════════════
 * Rozpoznané tvary:
 *   zdravotní agendy (HOZ/PPZ) — poslední segment je kód pojišťovny (viz
 *   `HealthInsuranceSubmissionService::register()`, `$subjectReference =
 *   "...:" . $insurerCode`),
 *   `payroll_run:{runId}:office:{officeId}` (JMHZ, REGZEL) — účtárna JE
 *   užitečný doplněk, protože firma s víc účtárnami by jinak měla dva řádky
 *   bez rozlišení,
 *   cokoliv jiného (typicky `payroll_run:{runId}` bez účtárny, nebo
 *   `employment:{id}` u ELDP/OZUSPOJ/PREZEC/REGZEC) — appka nezná jméno
 *   osoby ani účtárny na tomhle řádku dat a interní ID by nikomu nic
 *   neřeklo, takže se radši NEUKÁŽE NIC, než syrové ID.
 */
final class PayrollObligationSubjectFormatter
{
    public static function humanSubject(
        string $agendaCode,
        string $subjectReference,
    ): ?string {
        if (in_array($agendaCode, [
            HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
        ], true)) {
            return self::insurerLabel($subjectReference);
        }
        if (str_starts_with($subjectReference, 'payroll_run:')) {
            $parts = explode(':', $subjectReference);
            if (count($parts) === 4 && $parts[2] === 'office') {
                return 'mzdová účtárna ' . $parts[3];
            }

            return null;
        }

        return null;
    }

    /**
     * Poslední segment `subject_reference` je u zdravotních agend kód
     * pojišťovny. Když formát neodpovídá, radši žádný název pojišťovny
     * než hádaný.
     *
     * Kód se doplňuje zkratkou z číselníku („VZP (111)"), protože samotné
     * „111" účetní s pojišťovnou nespojí, ale kód potřebuje - pod ním se
     * pojišťovna eviduje v platebních účtech institucí. Neznámý kód zůstane
     * holý; vymýšlet si k němu název by bylo horší než ho neukázat.
     */
    private static function insurerLabel(string $subjectReference): ?string
    {
        $parts = explode(':', $subjectReference);
        $code = end($parts);
        if ($code === '' || $code === false) {
            return null;
        }

        $abbreviation = HealthInsurers::abbreviation($code);

        return $abbreviation !== null
            ? $abbreviation . ' (' . $code . ')'
            : 'zdravotní pojišťovna ' . $code;
    }
}
