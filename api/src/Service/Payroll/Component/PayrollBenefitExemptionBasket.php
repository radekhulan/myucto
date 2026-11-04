<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/**
 * Koš osvobození plnění zaměstnanci — ÚHRN za ustanovení a za ROZHODNÉ OBDOBÍ.
 *
 * § 6 odst. 9 ZDP nelimituje jednotlivou mzdovou složku, ale ÚHRN plnění za dané
 * ustanovení. Doslovné znění účinné pro rok 2026 (ověřeno proti verzím
 * 1. 1. 2026 i 1. 8. 2026, obě znějí stejně):
 *
 *   písm. b)       — „…a to v úhrnu do výše 70 % horní hranice stravného, které
 *                     lze poskytnout zaměstnancům odměňovaným platem při pracovní
 *                     cestě trvající 5 až 12 hodin,"  (za JEDNU SMĚNU)
 *   písm. d) bod 1 — „…; tato plnění jsou osvobozena v úhrnu do výše průměrné
 *                     mzdy za zdaňovací období,"
 *   písm. d) bod 2 — „…; tato plnění jsou osvobozena v úhrnu do výše poloviny
 *                     průměrné mzdy za zdaňovací období,"
 *   písm. i)       — „…a to maximálně do výše 3 500 Kč měsíčně,"
 *   písm. m)       — „…do úhrnné výše 50000 Kč ročně,"
 *
 * Tři důsledky, které z toho plynou a které složkový `annual_limit_minor`
 * vyjádřit neumí:
 *
 *  1. **Koš je společný.** Dvě různé mzdové složky téhož bodu se sčítají. Strop
 *     jedné složky je proto nutná, ne postačující podmínka.
 *  2. **Nerovnost je NEOSTRÁ.** „do výše X" znamená, že částka rovna X je ještě
 *     osvobozená; zdaňuje se až to, co X převyšuje. Srovnej § 6 odst. 4 ZDP, kde
 *     zákon říká „nedosáhne částky", a test je proto ostrý — tady takové slovo
 *     není.
 *  3. **Osvobozeno je plnění „do výše", ne plnění jako celek.** Překročení
 *     nezruší osvobození už poskytnutého úhrnu; zdanitelný je jen přebytek.
 *
 * Průměrná mzda je podle § 21g odst. 2 ZDP „průměrná mzda stanovená podle zákona
 * upravujícího pojistné na sociální zabezpečení", tedy MĚSÍČNÍ částka — roční
 * strop bodu 1 je jeden její násobek, ne dvanáct. Hodnoty proto nesedí v tomhle
 * enumu, ale v rulesetu pod {@see rulesetKey()}: nový rok = nový ruleset.
 *
 * Koš se kumuluje vždy za OSOBU U ZAMĚSTNAVATELE — zákon mluví o plnění
 * poskytovaném zaměstnavatelem zaměstnanci, ne o plnění z jednoho pracovního
 * vztahu. Souběžné vztahy téže osoby u téže firmy proto sdílí jeden koš.
 *
 * ROZHODNÉ OBDOBÍ ale společné není a plyne z textu ustanovení
 * ({@see accumulatesPerMonth()}):
 *
 *  - písm. d) a m) říkají „za zdaňovací období" resp. „ročně" → KALENDÁŘNÍ ROK
 *    (zdaňovací období poplatníka podle § 16b ZDP),
 *  - písm. i) říká „měsíčně" → KALENDÁŘNÍ MĚSÍC,
 *  - písm. b) váže limit na JEDNU SMĚNU. Mzdový vstup je ale měsíční, takže se
 *    limit skládá jako počet nárokových směn × limit na směnu a kumuluje se za
 *    měsíc; kolik směn na to je, řekne
 *    {@see \MyInvoice\Service\Payroll\Component\PayrollMealShiftEntitlement}
 *    z publikovaných směn a schválené docházky, ne odhad.
 *
 * ── Od zdaňovacího období 2027 (zák. č. 180/2026 Sb., § 30 body 2 až 6) ──────
 * Písm. d) se přestavělo (doslovné znění k 1. 1. 2027):
 *
 *   bod 1 — beze změny, „v úhrnu do výše průměrné mzdy za zdaňovací období",
 *   bod 2 — vzdělávací a rekreační zařízení, předškolní zařízení, knihovna,
 *           sport, společenská akce, příspěvek na kulturní či sportovní akce
 *           a tištěné knihy: limit ZRUŠEN ({@see isUnlimited()}),
 *   bod 3 — „poskytnutí rekreace nebo zájezdu; tato plnění jsou osvobozena
 *           v úhrnu do výše 20000 Kč za zdaňovací období" — vlastní koš
 *           {@see Recreation}, částka v rulesetu,
 *   bod 4 — příspěvek na komunitní sociální služby, bez limitu (koš nepotřebuje).
 *
 * Nový § 6 odst. 7 písm. f) (péče o zdraví v rozsahu přílohy č. 4) příjmem
 * vůbec není, takže ani do koše nepatří. Přechodné ustanovení § 31 bod 2
 * váže nové znění písm. d) na plnění poskytnutá od 1. 1. 2027; o roce se proto
 * rozhoduje podle mzdového období vstupu.
 */
enum PayrollBenefitExemptionBasket: string
{
    /** § 6 odst. 9 písm. d) bod 1 ZDP — zdravotní plnění, úhrn do průměrné mzdy. */
    case NonCashHealth = 'non_cash_health';

    /**
     * § 6 odst. 9 písm. d) bod 2 ZDP — rekreace a zájezd, sport, kultura, tištěné
     * knihy, vzdělávací a předškolní zařízení; úhrn do poloviny průměrné mzdy.
     */
    case NonCashLeisure = 'non_cash_leisure';

    /**
     * § 6 odst. 9 písm. m) ZDP — příspěvek na daňově podporované produkty spoření
     * na stáří a na pojištění dlouhodobé péče, úhrn 50 000 Kč ročně. Písmeno se
     * konsolidačním balíčkem posunulo; ve znění účinném pro rok 2026 je to m).
     */
    case OldAgeSavings = 'old_age_savings';

    /**
     * § 6 odst. 9 písm. b) ZDP — příspěvek na stravování za jednu směnu. Limit je
     * ZA SMĚNU, ne za měsíc: ruleset drží částku na jednu směnu a počet směn
     * s nárokem dodá evidence docházky.
     */
    case MealPerShift = 'meal_per_shift';

    /**
     * § 6 odst. 9 písm. i) ZDP — hodnota přechodného ubytování, „maximálně do výše
     * 3 500 Kč měsíčně". Osvobozeno je jen NEPENĚŽNÍ plnění a jen tehdy, nejde-li
     * o ubytování při pracovní cestě a není-li obec přechodného ubytování shodná
     * s obcí bydliště zaměstnance. Obec ani formu plnění aplikace z mzdových dat
     * nezná — nese je zařazení složky, které volí účetní; aplikace hlídá to
     * jediné, co spočítat umí, tedy měsíční strop.
     */
    case TemporaryAccommodation = 'temporary_accommodation';

    /**
     * § 6 odst. 9 písm. d) bod 3 ZDP ve znění od 1. 1. 2027 — rekreace a zájezd,
     * úhrn 20 000 Kč za zdaňovací období. Před rokem 2027 samostatný koš
     * neexistoval (rekreace byla v bodu 2), takže se pro dřívější období nepoužije.
     */
    case Recreation = 'recreation';

    /**
     * § 6 odst. 9 písm. g) ZDP od 1. 1. 2027 — spropitné ze stravovacích služeb,
     * za kalendářní měsíc do 7 % měsíčních příjmů zaměstnavatele ze stravovacích
     * služeb. Limit nestojí v rulesetu, ale na evidenci firmy
     * ({@see PayrollFoodServiceTipService}). Spropitné není benefit, proto ho
     * nese složka druhu `other` ({@see allowsComponentKind()}).
     */
    case FoodServiceTips = 'food_service_tips';

    /** První zdaňovací období přestavěného § 6 odst. 9 písm. d) ZDP. */
    public const BENEFITS_2027_FROM_YEAR = 2027;

    /**
     * Hodnota parametru koše v rulesetu pro plnění bez číselného limitu. Rozhoduje
     * {@see isUnlimited()} podle zákona; ruleset to jen říká nahlas, aby administrace
     * neukazovala chybějící částku jako nevyřízenou věc.
     */
    public const UNLIMITED_RULESET_VALUE = 'unlimited';

    /** Stojí limit koše na evidenci firmy místo rulesetu? */
    public function limitFromCompanyEvidence(): bool
    {
        return $this === self::FoodServiceTips;
    }

    /** Smí koš nést mzdová složka daného druhu? */
    public function allowsComponentKind(PayrollComponentKind $kind): bool
    {
        return $this === self::FoodServiceTips
            ? $kind === PayrollComponentKind::OTHER
            : $kind->isBenefit();
    }

    /**
     * Kanonický klíč limitu koše v rulesetu daně z příjmů (částka v haléřích).
     * U spropitného `null` — limit je z evidence firmy.
     */
    public function rulesetKey(): ?string
    {
        return match ($this) {
            self::NonCashHealth => 'benefit_exemption.non_cash_health.yearly',
            self::NonCashLeisure => 'benefit_exemption.non_cash_leisure.yearly',
            self::OldAgeSavings => 'benefit_exemption.old_age_savings.yearly',
            self::MealPerShift => 'benefit_exemption.meal.per_shift',
            self::TemporaryAccommodation => 'benefit_exemption.temporary_accommodation.monthly',
            self::Recreation => 'benefit_exemption.recreation.yearly',
            self::FoodServiceTips => null,
        };
    }

    /**
     * Je koš v daném zdaňovacím období osvobozený bez číselného limitu?
     *
     * Jen písm. d) bod 2 od roku 2027: nové znění u něj větu „v úhrnu do výše
     * poloviny průměrné mzdy" nemá. Limit se pak z rulesetu vůbec nečte.
     */
    public function isUnlimited(int $taxYear): bool
    {
        return $this === self::NonCashLeisure && $taxYear >= self::BENEFITS_2027_FROM_YEAR;
    }

    /** Existuje koš v daném zdaňovacím období? */
    public function existsIn(int $taxYear): bool
    {
        return !in_array($this, [self::Recreation, self::FoodServiceTips], true)
            || $taxYear >= self::BENEFITS_2027_FROM_YEAR;
    }

    /**
     * Kumuluje se úhrn za kalendářní měsíc (ne za zdaňovací období)?
     *
     * Rozhoduje slovo v zákoně, ne pohodlí aplikace: „ročně" / „za zdaňovací
     * období" versus „měsíčně" / „za jednu směnu".
     */
    public function accumulatesPerMonth(): bool
    {
        return match ($this) {
            self::NonCashHealth, self::NonCashLeisure, self::OldAgeSavings, self::Recreation => false,
            self::MealPerShift, self::TemporaryAccommodation, self::FoodServiceTips => true,
        };
    }

    /**
     * Násobí se limit z rulesetu počtem směn s nárokem?
     *
     * Jen u písm. b). Limit ostatních košů je za období pevný a počet směn na něj
     * nemá vliv.
     */
    public function scalesWithShifts(): bool
    {
        return $this === self::MealPerShift;
    }

    /**
     * ČEHO SE LIMIT TÝKÁ — jedna z {@see PayrollBenefitBasketUsage::LIMIT_BASES}.
     *
     * Není to totéž co {@see accumulatesPerMonth()}. Ten říká, za jaké období se
     * sčítá ÚHRN; tohle říká, za jaké období platí LIMIT. U písm. b) se ty dvě
     * věci rozcházejí: úhrn se kvůli měsíčnímu mzdovému vstupu kumuluje za měsíc,
     * ale limit je za JEDNU SMĚNU. Měsíční součet proto proti němu nelze poměřit
     * a přehled, který by z něj usoudil „v limitu", by lhal — tenhle údaj jde do
     * odpovědi právě proto, aby to obrazovka mohla říct nahlas.
     */
    public function limitBasis(): string
    {
        return match ($this) {
            self::NonCashHealth, self::NonCashLeisure, self::OldAgeSavings, self::Recreation => 'tax_year',
            self::TemporaryAccommodation, self::FoodServiceTips => 'calendar_month',
            self::MealPerShift => 'per_shift',
        };
    }

    public function statute(): string
    {
        return match ($this) {
            self::NonCashHealth => '§ 6 odst. 9 písm. d) bod 1 ZDP',
            self::NonCashLeisure => '§ 6 odst. 9 písm. d) bod 2 ZDP',
            self::OldAgeSavings => '§ 6 odst. 9 písm. m) ZDP',
            self::MealPerShift => '§ 6 odst. 9 písm. b) ZDP',
            self::TemporaryAccommodation => '§ 6 odst. 9 písm. i) ZDP',
            self::Recreation => '§ 6 odst. 9 písm. d) bod 3 ZDP',
            self::FoodServiceTips => PayrollFoodServiceTipStatute::STATUTE,
        };
    }
}
