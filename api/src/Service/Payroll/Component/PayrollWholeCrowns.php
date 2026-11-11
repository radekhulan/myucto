<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;

/**
 * Mzdové vstupy v celých korunách pro měsíční hlášení (JMHZ).
 *
 * Hlášení nese výdělek vztahu po atributech 10328 až 10331 (a úhrn 10286)
 * v celých korunách; částky se tam sčítají ze vstupů složek se zařazením
 * `jmhz_treatment = included` ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder}).
 * Haléř v takovém vstupu končí až při přípravě hlášení nálezem
 * `jmhz_scenario1_whole_czk_required` — tedy po schválení mzdy. Mzda, odměna
 * z dohody i náhrada se podle § 142 odst. 2 a § 144 zákoníku práce
 * zaokrouhlují na celé koruny nahoru, proto se tu zaokrouhluje vždy nahoru
 * (ve prospěch zaměstnance) a celočíselně v haléřích.
 *
 * VÝJIMKA: osvobozený benefit v koši § 6 odst. 9 ZDP. Zákonný limit sám nese
 * haléře (stravenkový paušál 129,50 Kč za směnu = 70 % ze 185 Kč podle písm. b),
 * polovina průměrné mzdy 24 483,50 Kč podle písm. d) bodu 2 pro rok 2026), takže
 * částka přesně na limitu ani nadlimitní část na celé koruny vyjít nemusí.
 * § 142 odst. 2 zákoníku práce zaokrouhluje mzdu a plat, ne benefit; zaokrouhlit
 * takový vstup nahoru by vytvořilo zdanitelný přebytek nad limit, který
 * zaměstnavatel neposkytl. Takový vstup proto haléře nést smí
 * ({@see self::mayCarryHalere()}) a celé koruny, které hlášení vyžaduje, vznikají
 * až při vykázání ({@see self::reportedExemptCzk()},
 * {@see self::reportedIncomeTotalCzk()}, {@see self::reportedBaseCzk()}).
 *
 * Jediné místo, kde se rozhoduje, kterých vstupů se pravidlo týká a jak se
 * zaokrouhlují: kontrola mzdového běhu, hromadná oprava vstupů, import docházky
 * i vykázání úhrnů v měsíčním hlášení.
 */
final class PayrollWholeCrowns
{
    /** Zaokrouhlení nahoru (k +∞) na celé koruny, v haléřích. */
    public static function roundUpMinor(int $minor): int
    {
        $remainder = $minor % 100;
        if ($remainder === 0) {
            return $minor;
        }

        return $remainder > 0 ? $minor - $remainder + 100 : $minor - $remainder;
    }

    public static function hasHalere(int $minor): bool
    {
        return $minor % 100 !== 0;
    }

    /**
     * Vstupy, které hlášení zablokují: haléřové vstupy skupin (vztah + složka),
     * jejichž součet nevyjde na celé koruny. Výdělek se v hlášení sčítá po
     * složkách, takže dva vstupy téže složky po 0,50 Kč nevadí.
     *
     * @param array<array-key,array{group:string,amount_minor:int}> $inputs
     * @return list<array-key> klíče vstupů k zaokrouhlení
     */
    public static function blockingInputs(array $inputs): array
    {
        $sums = [];
        foreach ($inputs as $input) {
            $sums[$input['group']] = ($sums[$input['group']] ?? 0) + $input['amount_minor'];
        }
        $blocking = [];
        foreach ($inputs as $key => $input) {
            if (self::hasHalere($sums[$input['group']]) && self::hasHalere($input['amount_minor'])) {
                $blocking[] = $key;
            }
        }

        return $blocking;
    }

    /** Vstup složky s tímto zařazením se vykazuje ve výdělku hlášení. */
    public static function reportedInJmhz(mixed $jmhzTreatment): bool
    {
        return $jmhzTreatment === 'included';
    }

    /**
     * Vstup téhle složky musí být v celých korunách.
     *
     * Osvobozený benefit v koši haléře nést smí, dokud je vstup celý
     * osvobozený nebo dokud jeho rozpad ještě není zmrazený (koncept). Má-li
     * schválený vstup nadlimitní část, musí být jeho ČÁSTKA v celých korunách:
     * haléře nadlimitní části pak doplňují haléře osvobozené části do celé
     * koruny a úhrn 10286 zůstává celý.
     *
     * @param array<string,mixed> $component řádek nebo zmrazený snímek mzdové
     *   složky (`jmhz_treatment`, `tax_treatment`, `exemption_basket`)
     * @param ?int $frozenTaxableMinor zmrazená nadlimitní část vstupu
     *   (`benefit_taxable_minor`), `null` u vstupu bez zmrazeného rozpadu
     */
    public static function required(array $component, ?int $frozenTaxableMinor = null): bool
    {
        if (!self::reportedInJmhz($component['jmhz_treatment'] ?? null)) {
            return false;
        }

        return !self::mayCarryHalere($component)
            || ($frozenTaxableMinor !== null && $frozenTaxableMinor > 0);
    }

    /**
     * Osvobozený benefit v koši § 6 odst. 9 ZDP, který se v hlášení vykazuje
     * jen v úhrnech (10286, 10289 a nadlimitní část v základech): haléře nést
     * smí, viz hlavička třídy.
     *
     * Koš příspěvků na produkty spoření na stáří (písm. m)) sem nepatří. Limit
     * 50 000 Kč haléře nenese a příspěvek má v hlášení vlastní atribut 10417
     * v celých korunách, do kterého se opisuje částka vstupu.
     *
     * @param array<string,mixed> $component
     */
    public static function mayCarryHalere(array $component): bool
    {
        if (($component['tax_treatment'] ?? null) !== 'exempt') {
            return false;
        }
        $basket = is_string($component['exemption_basket'] ?? null)
            ? PayrollBenefitExemptionBasket::tryFrom($component['exemption_basket'])
            : null;

        return $basket !== null && $basket !== PayrollBenefitExemptionBasket::OldAgeSavings;
    }

    /**
     * Atributy měsíčního hlášení, u kterých haléře z nadlimitní části benefitu
     * nejsou chybou vstupu a vykazují se zaokrouhlené NAHORU:
     *
     *  - 10297 a 10535 (základ pro výpočet zálohy): § 38h odst. 1 ZDP základ
     *    zaokrouhluje vždy nahoru (do 100 Kč na koruny, nad 100 Kč na
     *    stokoruny), takže vypočtená záloha je z haléřového i z nahoru
     *    zaokrouhleného základu stejná;
     *  - 10476 (příjem zahrnovaný do vyměřovacího základu): § 5d zák.
     *    č. 589/1992 Sb. zaokrouhluje vyměřovací základ na celé koruny nahoru
     *    a 10477 už takto zaokrouhlený je;
     *  - 10344 (čistý příjem): rozdíl haléřového základu a srážek v celých
     *    korunách.
     *
     * Datový slovník 1.4.1.6 u nich žádá „celé číslo“ a způsob zaokrouhlení
     * nestanoví. Výdělek po složkách (10328 až 10343) zůstává přísný: mzda se
     * zaokrouhluje už ve vstupu a haléř v ní je chyba.
     */
    public const REPORTED_ROUNDED_UP = ['10297', '10344', '10476', '10535'];

    /**
     * Osvobozené příjmy (10289) v celých korunách: DOLŮ.
     *
     * Vykázané osvobození tak nikdy nepřekročí zákonný limit, ze kterého
     * haléře pocházejí, a spolu se základem zaokrouhleným nahoru dává zpět
     * úhrn zúčtovaného příjmu (kontrola 97: 10289 ≤ 10286).
     */
    public static function reportedExemptCzk(int $exemptMinor): int
    {
        return intdiv($exemptMinor - (($exemptMinor % 100 + 100) % 100), 100);
    }

    /**
     * Zúčtovaný příjem celkem (10286) v celých korunách, nebo `null`, když
     * haléře nejdou vysvětlit osvobozeným benefitem (pak jde o chybu vstupu
     * a hlášení ji hlásí jako dosud).
     *
     * Úhrn v celých korunách se opisuje. Haléřový úhrn smí vzniknout jen
     * z osvobozené části (zbytek je v celých korunách) a vykáže se jako
     * zdanitelná část plus osvobozená část zaokrouhlená dolů, aby 10286
     * a 10289 zůstaly v souladu.
     */
    public static function reportedIncomeTotalCzk(int $totalMinor, int $exemptMinor): ?int
    {
        if (!self::hasHalere($totalMinor)) {
            return intdiv($totalMinor, 100);
        }
        if (self::hasHalere($totalMinor - $exemptMinor)) {
            return null;
        }

        return intdiv($totalMinor - $exemptMinor, 100) + self::reportedExemptCzk($exemptMinor);
    }

    /** Základ vykazovaný nahoru ({@see self::REPORTED_ROUNDED_UP}), v korunách. */
    public static function reportedBaseCzk(int $minor): int
    {
        return intdiv(self::roundUpMinor($minor), 100);
    }

    /**
     * Celé koruny se hlídají za každý měsíc, za který jde připravit měsíční
     * hlášení — tedy stejně jako příprava, ne od zákonného startu JMHZ
     * (1. 4. 2026): firmy připravují a mrazí hlášení i za 01/2026 a 02/2026
     * a příprava za únor haléře odmítla.
     */
    public static function jmhzPeriod(string $periodStart): bool
    {
        return JmhzSpecVersion::monthlyReportPreparable($periodStart);
    }
}
