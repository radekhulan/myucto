<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsAgendaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSubmissionService;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;

/**
 * Kterou agendu umí aplikace odeslat SAMA, a když ne, tak proč — jeden seznam.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to vzniklo
 * ═══════════════════════════════════════════════════════════════════════════
 * Odpověď byla rozeseta po obrazovkách: „Stav odeslání" se ptala natvrdo na
 * JMHZ, registrace měly vlastní tlačítko na kartě vztahu, nemocenské své na
 * kartě případu a přehledy pojišťovnám další v panelu zdravotní skupiny.
 * Účetní tak neměla JEDINÉ místo, kde by viděla, co má rozděláno — a když se
 * agenda odeslat nedala, obrazovka ji prostě NEUKÁZALA. Mlčky vynechaná
 * položka je horší než položka s důvodem: uživatel neví, jestli na ni
 * zapomněl, nebo ji aplikace neumí.
 *
 * Katalog je proto UPLNÝ v obou směrech: nese i agendy, které odeslat nejde,
 * a u každé takové větu, kterou lze ukázat účetní.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Vztah k ostatním katalogům
 * ═══════════════════════════════════════════════════════════════════════════
 * Tenhle katalog NEROZHODUJE o kanálech — jen z nich čte a překládá je do
 * odpovědi „jde to odeslat odtud?". Doložené datové schránky drží
 * {@see PayrollIsdsAgendaCatalog} (ČSSZ) a
 * {@see \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerChannelCatalog}
 * (pojišťovny); shodu hlídá {@see \MyInvoice\Tests\Architecture\PayrollDispatchCapabilityCatalogTest}.
 *
 * Kanál v evidenci (`payroll_submissions.channel`) tady záměrně NEHRAJE ROLI:
 * říká, kde podání VZNIKLO, ne kudy může ven — JMHZ je vedené na `vrep_apep`
 * a přitom jde i datovkou, OZUSPOJ je na `vrep_apep` taky a ven nejde vůbec.
 */
final class PayrollDispatchCapabilityCatalog
{
    /** Aplikace odešle sama na VREP/APEP (e-Podání ČSSZ, web service). */
    public const MODE_VREP_JMHZ = 'vrep_jmhz';

    /** Totéž, ale přes adaptér registrací (PREZEC/REGZEC). */
    public const MODE_VREP_REGISTRATION = 'vrep_registration';

    /** Aplikace zařadí do odchozí fronty datové schránky (e-Podání ČSSZ). */
    public const MODE_ISDS_PAYROLL = 'isds_payroll';

    /** Totéž, ale do schránky zdravotní pojišťovny. */
    public const MODE_ISDS_HEALTH = 'isds_health';

    /** Odeslat z aplikace nejde; důvod nese {@see PayrollDispatchCapability::reason}. */
    public const MODE_NONE = 'none';

    /**
     * Všechny režimy jako jeden seznam — páruje se s TS unionem
     * `PayrollSubmissionDispatchMode` ve `web/src/api/payroll.ts`
     * (hlídá `PayrollEnumContractTest`), aby se klient a server nemohly
     * rozejít v tom, které hodnoty smí v odpovědi přijít.
     *
     * @var list<string>
     */
    public const MODES = [
        self::MODE_VREP_JMHZ,
        self::MODE_VREP_REGISTRATION,
        self::MODE_ISDS_PAYROLL,
        self::MODE_ISDS_HEALTH,
        self::MODE_NONE,
    ];

    /** @var array<string,PayrollDispatchCapability>|null */
    private static ?array $capabilities = null;

    public function forAgenda(string $agendaCode): PayrollDispatchCapability
    {
        $canonical = self::canonical($agendaCode);

        return self::capabilities()[$canonical]
            // Neznámý kód není „umíme": `agenda_code` je u povinnosti volný
            // text, takže sem může přijít cokoliv, co zapsala starší verze
            // nebo ruční evidence. Fail-closed s pojmenovaným důvodem.
            ?? new PayrollDispatchCapability(
                $canonical,
                self::MODE_NONE,
                'Tuhle agendu aplikace odeslat neumí — nemá pro ni doložený'
                    . ' kanál ani tvar zprávy. Připravený soubor stáhněte'
                    . ' a podejte obvyklou cestou.',
            );
    }

    /** @return list<string> */
    public function dispatchableCodes(): array
    {
        $codes = [];
        foreach (self::capabilities() as $code => $capability) {
            if ($capability->mode !== self::MODE_NONE) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys(self::capabilities());
    }

    /**
     * Ročník v kódu se odřezává stejně jako v {@see PayrollAgendaGroupCatalog}:
     * historické povinnosti nesou `JMHZ`, nové `JMHZ25`, a obě znamenají totéž
     * měsíční hlášení. Bez normalizace by starší řádek dostal „neumíme".
     */
    public static function canonical(string $agendaCode): string
    {
        return PayrollIsdsAgendaCatalog::canonical($agendaCode);
    }

    /** @return array<string,PayrollDispatchCapability> */
    private static function capabilities(): array
    {
        if (self::$capabilities !== null) {
            return self::$capabilities;
        }

        $entries = [
            new PayrollDispatchCapability(
                JmhzSubmissionBridgeService::AGENDA_CODE,
                self::MODE_VREP_JMHZ,
                null,
                // Datovka je u JMHZ rovnocenný druhý kanál (ČSSZ pro něj
                // zřídila vlastní schránku), ale fronta nabízí VREP: je to
                // jediná cesta, po které odpověď úřadu dorazí zpátky do
                // aplikace bez ručního nahrávání doručenky.
                alternateMode: self::MODE_ISDS_PAYROLL,
            ),
            new PayrollDispatchCapability(
                PayrollRegistrationSubmissionService::AGENDA_PREZEC,
                self::MODE_VREP_REGISTRATION,
                null,
            ),
            new PayrollDispatchCapability(
                PayrollRegistrationSubmissionService::AGENDA_REGZEC,
                self::MODE_VREP_REGISTRATION,
                null,
            ),
            new PayrollDispatchCapability(
                'NEMPRI',
                self::MODE_ISDS_PAYROLL,
                null,
            ),
            new PayrollDispatchCapability(
                'HZUPN',
                self::MODE_ISDS_PAYROLL,
                null,
            ),
            new PayrollDispatchCapability(
                HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
                self::MODE_ISDS_HEALTH,
                null,
                // Schránky pojišťoven jsou doložené jen pro ostré prostředí;
                // testovací protějšek pojišťovny nezveřejnily. Fronta na to
                // musí upozornit dřív, než účetní klikne — viz
                // `HealthInsuranceIsdsSubmissionService::enqueue()`.
                productionOnly: true,
                // Pojišťovna na přehled neodpovídá ničím, co by šlo strojově
                // přečíst. Odeslané se tedy samo nikdy neuzavře.
                authorityReportsResult: false,
            ),
            new PayrollDispatchCapability(
                HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
                self::MODE_ISDS_HEALTH,
                null,
                productionOnly: true,
                authorityReportsResult: false,
            ),
            // Agendy bez odesílacího kanálu mají `authorityReportsResult`
            // rovněž `false` — je to prostý fakt: co aplikace neodešle, na to
            // jí úřad nemá jak odpovědět. Ruční uzavření z fronty tím ale
            // NEOTEVÍRÁ: {@see PayrollSubmissionSettlementService} vyžaduje
            // navíc doložený kanál, aby se neobešla vlastní evidence ELDP
            // ({@see \MyInvoice\Service\Payroll\Submission\Eldp\EldpManualCompletionService}),
            // která výsledek dokládá dokumentem, ne jen kliknutím.
            new PayrollDispatchCapability(
                EldpStatementService::AGENDA_CODE,
                self::MODE_NONE,
                'Evidenční list důchodového pojištění aplikace neodesílá.'
                    . ' Připravený list si stáhněte na záložce ELDP a podejte'
                    . ' ho na ČSSZ obvyklou cestou.',
                authorityReportsResult: false,
            ),
            new PayrollDispatchCapability(
                OzuspojSubmissionService::AGENDA_CODE,
                self::MODE_NONE,
                'Oznámení záměru uplatňovat slevu na pojistném aplikace'
                    . ' neodesílá — ČSSZ pro něj nemá doložený strojový kanál.'
                    . ' Připravené XML stáhněte na záložce Záměry slev'
                    . ' a podejte je ze své datové schránky.',
                authorityReportsResult: false,
            ),
            new PayrollDispatchCapability(
                PayrollRegistrationSubmissionService::AGENDA_EMPLOYER_REGISTRATION,
                self::MODE_NONE,
                'První přihláška zaměstnavatele do registru ČSSZ nemá datovou'
                    . ' větu ani XSD, takže ji aplikace odeslat nemůže. Podává'
                    . ' se na místně příslušné OSSZ; tady se jen hlídá lhůta.',
                authorityReportsResult: false,
            ),
            new PayrollDispatchCapability(
                RegzelSubmissionBridgeService::AGENDA_CODE,
                self::MODE_NONE,
                'Doplnění údajů do registru zaměstnavatelů se podává ručně —'
                    . ' aplikace pro něj nemá doložený odesílací kanál.'
                    . ' Podklad najdete na záložce REGZEL.',
                authorityReportsResult: false,
            ),
        ];

        $indexed = [];
        foreach ($entries as $capability) {
            $indexed[$capability->agendaCode] = $capability;
        }
        // Pojistka proti tichému rozejití se seznamem odesílatelných
        // nemocenských agend: kdyby přibyla třetí, musí sem přibýt taky.
        foreach (SicknessSubmissionService::DISPATCHABLE_AGENDA_CODES as $code) {
            if (!isset($indexed[self::canonical($code)])) {
                throw new \LogicException(
                    "Nemocenská agenda {$code} nemá v katalogu odesílatelnosti"
                        . ' záznam.',
                );
            }
        }

        return self::$capabilities = $indexed;
    }
}
