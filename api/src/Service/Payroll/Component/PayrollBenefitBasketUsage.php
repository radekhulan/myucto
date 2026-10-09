<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/**
 * Roční čerpání jednoho koše osvobození jednou osobou.
 *
 * Protějšek {@see PayrollBenefitBasketSplit}: ten popisuje JEDNO plnění v okamžiku
 * schválení, tohle je souhrn za rok. Rozdíl je zásadní a je v tom, co se smí
 * počítat:
 *
 *  - `usedMinor` je součet HRUBÝCH plnění z ročního akumulátoru — totéž číslo,
 *    proti kterému se poměřuje další vstup,
 *  - `exemptMinor` a `taxableMinor` jsou součty ZMRAZENÝCH rozpadů ze mzdových
 *    vstupů. Nedopočítávají se z dnešního rulesetu; pořadí čerpání je dané
 *    pořadím schválení a přepočet by rozešel přehled s výplatní páskou.
 *
 * `limitMinor` smí být `null`. Ruleset se bere fail-closed, takže pro rok bez
 * schválené sady se limit netvrdí — a bez limitu se netvrdí ani „zbývá".
 */
final readonly class PayrollBenefitBasketUsage implements \JsonSerializable
{
    /**
     * Stavy řádku přehledu. Nejsou to jen barvy: `incomplete`
     * a `limit_unavailable` jsou PŘIZNÁNÍ, že podklad chybí, a musí mít na
     * obrazovce vlastní větu — jinak by se tvářily jako „v pořádku".
     */
    public const STATUSES = ['ok', 'approaching', 'exceeded', 'incomplete', 'limit_unavailable'];

    /**
     * Čeho se `limitMinor` týká. Je to ODPOVĚĎ NA JINOU OTÁZKU než období součtu:
     * měsíční přehled u příspěvku na stravování sčítá měsíc, ale limit je podle
     * § 6 odst. 9 písm. b) ZDP za JEDNU SMĚNU (`per_shift`). Takový řádek proto
     * limit netvrdí vůbec a obrazovka musí říct, že měsíční součet se proti
     * limitu za směnu poměřit nedá — jinak by z prázdného sloupce udělala
     * „v pořádku".
     */
    public const LIMIT_BASES = ['tax_year', 'calendar_month', 'per_shift'];

    /**
     * Práh „blíží se limitu" — 80 % koše, počítáno celočíselně
     * (`used * 5 >= limit * 4`), aby hranicí nehýbalo zaokrouhlení.
     */
    private const APPROACHING_NUMERATOR = 4;

    private const APPROACHING_DENOMINATOR = 5;

    public function __construct(
        public int $employeeId,
        public string $employeeName,
        public PayrollBenefitExemptionBasket $basket,
        public ?int $limitMinor,
        public int $usedMinor,
        public int $exemptMinor,
        public int $taxableMinor,
        public int $inputCount,
        public int $unfrozenCount,
        public int $negativeCount,
        /**
         * Akumulátory uvolněné stornem
         * ({@see \MyInvoice\Repository\Payroll\PayrollInputRepository::reverseBenefit()}).
         * Do `usedMinor` nevstupují — koš už nečerpají. Řádek je nese proto, aby
         * šlo uvolněný koš odlišit od koše, který se nikdy nečerpal.
         */
        public int $reversedCount = 0,
        public int $reversedMinor = 0,
        /**
         * Čerpání v měsících převzatých z jiného mzdového programu (rok přechodu). Je
         * součástí `usedMinor` (limit platí za celý rok), ale zmrazený rozpad za ně MyÚčto
         * nevede: koš z nich čerpá jako první, protože leží před začátkem vedení mezd.
         */
        public int $takenOverMinor = 0,
        public int $takenOverMonths = 0,
    ) {}

    public function remainingMinor(): ?int
    {
        return $this->limitMinor === null
            ? null
            : max(0, $this->limitMinor - $this->usedMinor);
    }

    /**
     * Stav řádku. Pořadí rozhodování je záměrné:
     *
     *  1. `exceeded` — nadlimitní část je ZMRAZENÝ fakt ze schválení, platí tedy
     *     i tehdy, když dnešní ruleset limit netvrdí nebo když části vstupů
     *     rozpad chybí. Schovat ji za chybějící podklad by zamlčelo to jediné,
     *     co je jisté.
     *  2. `limit_unavailable` — bez limitu nejde říct „zbývá" ani „blíží se".
     *  3. `incomplete` — část vstupů je z doby před koši a rozpad nemá.
     *     Nedopočítává se; řádek jen řekne, že podklad chybí.
     *  4. `approaching` / `ok` — až když jsou známé obě strany.
     */
    public function status(): string
    {
        if ($this->taxableMinor > 0) {
            return 'exceeded';
        }
        // Převzaté měsíce rozpad nemají; nad limit se koš dostal, i když zdanění nadlimitní
        // části proběhlo v předchozím programu.
        if ($this->takenOverMinor > 0 && $this->limitMinor !== null && $this->usedMinor > $this->limitMinor) {
            return 'exceeded';
        }
        if ($this->limitMinor === null) {
            return 'limit_unavailable';
        }
        if ($this->unfrozenCount > 0) {
            return 'incomplete';
        }
        if ($this->usedMinor * self::APPROACHING_DENOMINATOR
            >= $this->limitMinor * self::APPROACHING_NUMERATOR
        ) {
            return 'approaching';
        }

        return 'ok';
    }

    /**
     * Rozešel se zmrazený rozpad s dnešním limitem?
     *
     * Součet osvobozených částí se rovná `min(vyčerpáno, limit)` právě tehdy,
     * když všechna schválení proběhla proti témuž limitu. Nerovnost tedy znamená,
     * že se limit v rulesetu po schválení změnil. Přehled to jen OZNÁMÍ a dál
     * ukazuje zmrazená čísla — přepsat je dnešním limitem by rozešlo přehled
     * s výplatní páskou.
     *
     * Testuje se jen tam, kde má rovnost vůbec platit: chybí-li u některého
     * vstupu rozpad nebo je-li v koši záporná částka (oprava), je nerovnost
     * očekávaná a nic neříká.
     */
    public function splitDrift(): bool
    {
        if ($this->limitMinor === null || $this->unfrozenCount > 0 || $this->negativeCount > 0) {
            return false;
        }

        // Převzaté měsíce čerpají koš dřív než vstupy MyÚčta, takže na zmrazené rozpady
        // vstupů zbývá limit snížený o ně.
        return $this->exemptMinor !== max(
            0,
            min($this->usedMinor, $this->limitMinor) - min($this->takenOverMinor, $this->limitMinor),
        );
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
            'basket' => $this->basket->value,
            'statute' => $this->basket->statute(),
            'limit_basis' => $this->basket->limitBasis(),
            'limit_minor' => $this->limitMinor,
            'used_minor' => $this->usedMinor,
            'exempt_minor' => $this->exemptMinor,
            'taxable_minor' => $this->taxableMinor,
            'remaining_minor' => $this->remainingMinor(),
            'input_count' => $this->inputCount,
            'unfrozen_count' => $this->unfrozenCount,
            'reversed_count' => $this->reversedCount,
            'reversed_minor' => $this->reversedMinor,
            'taken_over_minor' => $this->takenOverMinor,
            'taken_over_months' => $this->takenOverMonths,
            'status' => $this->status(),
            'split_drift' => $this->splitDrift(),
        ];
    }
}
