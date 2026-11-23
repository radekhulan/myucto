<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\CzIscoCodebook;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;

/**
 * Hodnoty přihlášky A1 z vnějších číselníků, které EDV REGZEC přebírá:
 * obec pracoviště (municode, ID 10230, číselník CIS_OBCE = CISOB ČSÚ
 * + „111111" mimo ČR) a profese CZ-ISCO (clas, ID 10234).
 *
 * Tvar (6 a 5 číslic) hlídá XSD a {@see PayrollRegistrationProfessionCode};
 * tady se ověřuje, že kód v číselníku opravdu je. Bez toho by kód přepsaný
 * ve formuláři A1 nebo nezměněný historický kód z karty vztahu odešel do
 * věty a ČSSZ by přihlášku odmítla až po odeslání.
 *
 * Obce pro den, na který připnuté číselníky nesahají (nástup od 1. 1. 2027,
 * než vyjde vyhláška k rozpočtovému určení daní), se ověřují proti
 * POSLEDNÍMU vyhlášenému stavu: kód, který neplatil ani tehdy, je téměř
 * jistě překlep. Obec zrušenou až k 1. 1. 2027 takhle poznat nejde; to
 * zachytí až nový číselník.
 */
final class PayrollRegistrationA1CodebookValues
{
    private static ?JmhzExternalCodebookCatalog $municipalities = null;
    private static ?CzIscoCodebook $professions = null;

    /**
     * `null` = kód je v pořádku; jinak den, ke kterému se ověřoval,
     * a zda šlo o poslední vyhlášený stav místo stavu ke dni nástupu.
     *
     * @return array{checked_on:string,fallback:bool}|null
     */
    public static function municipalityProblem(string $code, string $effectiveOn): ?array
    {
        if ($code === PayrollRegistrationSpecVersionRules::MUNICIPALITY_OUTSIDE_CZ) {
            // Ročník věty hlídá PayrollRegistrationSpecVersionRules.
            return null;
        }
        $catalog = self::$municipalities ??= new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog());
        $checkedOn = $effectiveOn;
        $fallback = false;
        if (!$catalog->coversDate($checkedOn)) {
            $last = self::lastCoveredDay($catalog, $effectiveOn);
            if ($last === null) {
                return null;
            }
            $checkedOn = $last;
            $fallback = true;
        }

        return $catalog->findMunicipality($code, $checkedOn) === null
            ? ['checked_on' => $checkedOn, 'fallback' => $fallback]
            : null;
    }

    /** Stav kódu CZ-ISCO: `null` = aktivní, jinak `unknown` nebo `retired`. */
    public static function professionProblem(string $code): ?string
    {
        $status = (self::$professions ??= new CzIscoCodebook())->status($code);

        return $status === CzIscoCodebook::STATUS_ACTIVE ? null : $status;
    }

    /**
     * Poslední den před `$after`, který připnuté číselníky obcí pokrývají
     * (hledá se zpět po koncích měsíců, nejvýš rok).
     */
    private static function lastCoveredDay(JmhzExternalCodebookCatalog $catalog, string $after): ?string
    {
        $day = (new \DateTimeImmutable(substr($after, 0, 7) . '-01'))->modify('-1 day');
        for ($i = 0; $i < 12; $i++) {
            if ($catalog->coversDate($day->format('Y-m-d'))) {
                return $day->format('Y-m-d');
            }
            $day = $day->modify('first day of this month')->modify('-1 day');
        }

        return null;
    }
}
