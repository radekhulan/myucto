<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\IncomeTax\ChildCreditRateKey;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentIncomeTaxPolicy2026;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;

/**
 * Roční částky slev, daňového zvýhodnění a hranice pásma — odvozené z rulesetu.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč se roční částka SLEV smí odvodit z měsíční
 * ─────────────────────────────────────────────────────────────────────────────
 * Ruleset daně z příjmů drží slevy a daňové zvýhodnění jako MĚSÍČNÍ částky
 * (klíče končí `.monthly`). Roční zúčtování ale počítá s ročními částkami podle
 * § 35ba, § 35c a § 16. Vztah mezi nimi NEODHADUJEME — je v zákoně:
 *
 *   § 35d odst. 2: záloha se sníží „o částku ve výši odpovídající JEDNÉ
 *   DVANÁCTINĚ částky stanovené v § 35ba odst. 1 písm. a), c) až e)" a o daňové
 *   zvýhodnění „ve výši odpovídající JEDNÉ DVANÁCTINĚ částky stanovené
 *   v § 35c".
 *
 *   § 38h odst. 2 versus § 16 odst. 1: hranice vyšší sazby je měsíčně
 *   3násobek průměrné mzdy, ročně 36násobek — tedy přesně dvanáctinásobek.
 *
 * Měsíční hodnota v rulesetu je tedy DEFINIČNĚ dvanáctina roční. Násobení
 * dvanácti není aproximace, je to tatáž rovnice čtená zprava doleva.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A proč se na to přesto nespoléhá naslepo
 * ─────────────────────────────────────────────────────────────────────────────
 * Ruleset je přepisovatelný adminem. Kdyby někdo zadal měsíční částku, která
 * dvanáctinou roční není, odvození by tiše vyrobilo špatné roční číslo.
 * Ruleset naštěstí obsahuje JEDNU hodnotu uvedenou obojím způsobem —
 * `bonus.minimum_income.monthly` a `bonus.minimum_income.yearly`. Ta slouží jako
 * spustitelná kontrola vztahu: neplatí-li u ní 12×, odvození se zastaví
 * a zúčtování se neprovede. Je to levná brána proti celé třídě „admin přepsal
 * měsíční hodnotu a roční se rozešla".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Kde se roční hodnota naopak odvodit NESMÍ
 * ─────────────────────────────────────────────────────────────────────────────
 * Dvanáctina podle § 35d odst. 2 platí pro SLEVY a daňové zvýhodnění. Na prahy
 * výplaty a na roční slevu na manžela nedopadá vůbec:
 *
 *   - § 35c odst. 3 dává roční bonus „alespoň 100 Kč", kdežto § 35d odst. 4 dává
 *     měsíční „alespoň 50 Kč". Dvanáctinásobek by vyrobil 600 Kč a poplatník by
 *     o bonus mezi 100 a 600 Kč tiše přišel.
 *   - § 38ch odst. 5 (a § 35d odst. 8) mají práh výplaty „více než 50 Kč" —
 *     stejné číslo jako měsíční bonus, ale OPAČNÝ operátor.
 *   - § 35bb dává slevu na manžela rovnou ročně; měsíční protějšek nemá, protože
 *     se k ní podle § 38h odst. 6 při zálohách nepřihlíží.
 *
 * Tyhle hodnoty proto ruleset od 8/2026 nese VÝSLOVNĚ jako roční klíče a čtou se
 * přímo, bez násobení. Prahy zároveň existují jako zákonná čísla v
 * {@see AnnualSettlementStatute}; jejich SHODU s rulesetem hlídá druhá brána
 * v {@see forRuleset()} — dvě čísla pro jednu věc smí koexistovat jen potud,
 * pokud se rozejít nemůžou.
 *
 * @see AnnualSettlementStatute pro lhůty a prahy, které naopak stojí v zákoně
 */
final readonly class AnnualTaxRates
{
    public const MONTHS_IN_YEAR = 12;

    private function __construct(
        public string $rulesetId,
        public string $rulesetHash,
        public string $policyId,
        /** § 16 odst. 1 písm. b): hranice 23% pásma za zdaňovací období. */
        public int $highRateThresholdMinorUnits,
        public string $lowRate,
        public string $highRate,
        /** § 35c odst. 4: šestinásobek minimální mzdy za zdaňovací období. */
        public int $bonusMinimumIncomeMinorUnits,
        /** § 35c odst. 3: nejnižší uplatnitelný roční bonus. Nerovnost je NEOSTRÁ. */
        public int $bonusMinimumAmountMinorUnits,
        /** § 38ch odst. 5 a § 35d odst. 8: práh výplaty. Nerovnost je OSTRÁ. */
        public int $payoutThresholdMinorUnits,
        /**
         * § 35bb odst. 1 věta první: roční sleva na manžela.
         *
         * ⚠ DO VÝPOČTU NEVSTUPUJE. Modul slevu na manžela neuplatňuje —
         * chybí evidence podmínek (společně hospodařící domácnost, vyživované
         * dítě do 3 let podle § 35bb odst. 2 písm. a), doložení podle § 38l),
         * takže {@see AnnualSettlementAnnualClaims::PresentUnsupported}
         * zúčtování zastaví. Číslo se drží jen proto, aby ho účetní viděla
         * v administraci a aby se roční a měsíční ruleset nemohly rozejít
         * nepozorovaně; ve stopě výpočtu je označené jako NEUPLATNĚNÉ —
         * viz {@see toArray()}.
         */
        public int $spouseCreditMinorUnits,
        /** § 35bb odst. 1 věta druhá: násobek u manžela s nárokem na průkaz ZTP/P. Do výpočtu nevstupuje. */
        public int $spouseCreditZtpPMultiplier,
        /** § 35bb odst. 2 písm. b): nejvyšší vlastní příjem manžela; nerovnost NEOSTRÁ. Do výpočtu nevstupuje. */
        public int $spouseIncomeLimitMinorUnits,
        /** @var array<string,int> roční částka slevy podle TaxCreditKind->value */
        public array $annualCreditMinorUnits,
        /** @var array<int,int> roční daňové zvýhodnění podle pořadí dítěte (1, 2, 3+) */
        public array $annualChildCreditMinorUnits,
    ) {}

    public static function forRuleset(PayrollRulesetVersion $ruleset): self
    {
        $policy = EmploymentIncomeTaxPolicy2026::forRuleset($ruleset);

        // Brána vztahu 12× — viz vysvětlení v hlavičce třídy.
        $monthlyBonusIncome = $policy->money('bonus.minimum_income.monthly');
        $yearlyBonusIncome = $policy->money('bonus.minimum_income.yearly');
        if ($monthlyBonusIncome * self::MONTHS_IN_YEAR !== $yearlyBonusIncome) {
            throw new AnnualSettlementUnavailableException(
                'Ruleset daně z příjmů nemá roční hodnoty jako dvanáctinásobek '
                . 'měsíčních, takže roční částky slev z něj odvodit nelze.',
            );
        }

        // Druhá brána: hodnoty, které jsou zároveň v zákoně opsané v kódu
        // (AnnualSettlementStatute) a zároveň administrovatelné v rulesetu, se
        // NESMĚJÍ rozejít. Rozejdou-li se, zúčtování se zastaví — je to o řád
        // lepší než tiše rozhodnout, které z těch dvou čísel platí.
        $payoutThreshold = self::money($ruleset, 'settlement.payout_threshold');
        $annualBonusMinimum = self::money($ruleset, 'bonus.minimum_amount.yearly');
        foreach ([
            'settlement.payout_threshold' =>
                [$payoutThreshold, AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS],
            'bonus.minimum_amount.yearly' =>
                [$annualBonusMinimum, AnnualSettlementStatute::ANNUAL_BONUS_MINIMUM_MINOR_UNITS],
        ] as $key => [$fromRuleset, $fromStatute]) {
            if ($fromRuleset !== $fromStatute) {
                throw new AnnualSettlementUnavailableException(
                    "Ruleset daně z příjmů má u {$key} jinou částku než zákonné číslo "
                    . 'v modulu ročního zúčtování, takže není jasné, která platí.',
                );
            }
        }

        $credits = [];
        foreach (
            [
                TaxCreditKind::Taxpayer->value => 'credit.taxpayer.monthly',
                TaxCreditKind::DisabilityBasic->value => 'credit.disability.basic.monthly',
                TaxCreditKind::DisabilityExtended->value => 'credit.disability.extended.monthly',
                TaxCreditKind::ZtpP->value => 'credit.ztp_p.monthly',
            ] as $kind => $key
        ) {
            $credits[$kind] = $policy->money($key) * self::MONTHS_IN_YEAR;
        }
        ksort($credits);

        $children = [];
        foreach ([1, 2, 3] as $order) {
            $children[$order] = $policy->money(ChildCreditRateKey::forOrder($order))
                * self::MONTHS_IN_YEAR;
        }

        return new self(
            $ruleset->id,
            $ruleset->canonicalHash,
            EmploymentIncomeTaxPolicy2026::ID,
            $policy->money('advance.high_threshold.monthly') * self::MONTHS_IN_YEAR,
            $policy->rate('advance.low_rate'),
            $policy->rate('advance.high_rate'),
            $yearlyBonusIncome,
            $annualBonusMinimum,
            $payoutThreshold,
            self::money($ruleset, 'credit.spouse.yearly'),
            self::integer($ruleset, 'credit.spouse.ztp_p_multiplier'),
            self::money($ruleset, 'spouse.income_limit'),
            $credits,
            $children,
        );
    }

    /**
     * Roční klíče se čtou přímo z verze, ne přes {@see EmploymentIncomeTaxPolicy2026}.
     * Ta hlídá úplnost parametrů potřebných pro MĚSÍČNÍ zálohu a její seznam je
     * zároveň otiskem kontraktu — přidat do něj roční hodnoty by znamenalo, že
     * ruleset bez nich neumí spočítat ani měsíční mzdu, což není pravda.
     */
    private static function money(PayrollRulesetVersion $ruleset, string $key): int
    {
        $value = $ruleset->parameter($key);
        if ($value->type !== 'money_minor' || !is_int($value->value)) {
            throw new AnnualSettlementUnavailableException(
                "Roční parametr {$key} v rulesetu daně z příjmů není peněžní částka.",
            );
        }

        return $value->value;
    }

    private static function integer(PayrollRulesetVersion $ruleset, string $key): int
    {
        $value = $ruleset->parameter($key);
        if ($value->type !== 'integer' || !is_int($value->value)) {
            throw new AnnualSettlementUnavailableException(
                "Roční parametr {$key} v rulesetu daně z příjmů není celé číslo.",
            );
        }

        return $value->value;
    }

    /**
     * Roční sleva podle § 35ba za `$months` měsíců.
     *
     * § 35ba odst. 3 dává dvanáctinovou úpravu VÝSLOVNĚ jen slevám podle
     * odst. 1 písm. b) až e). Základní sleva na poplatníka (písm. a) v tom výčtu
     * NENÍ — náleží za celé zdaňovací období v plné výši i tomu, kdo pracoval
     * jediný měsíc. Krátit ji podle měsíců by byla častá a drahá chyba, proto to
     * tady stojí jako podmínka, ne jako poznámka.
     */
    public function creditForMonths(TaxCreditKind $kind, int $months): int
    {
        if ($months < 0 || $months > self::MONTHS_IN_YEAR) {
            throw new \InvalidArgumentException(
                'Počet měsíců nároku na slevu není platný.',
            );
        }
        $annual = $this->annualCreditMinorUnits[$kind->value]
            ?? throw new \InvalidArgumentException('Sleva nemá roční částku.');

        if ($kind === TaxCreditKind::Taxpayer) {
            return $months > 0 ? $annual : 0;
        }

        return intdiv($annual, self::MONTHS_IN_YEAR) * $months;
    }

    /**
     * Roční daňové zvýhodnění na jedno dítě za `$months` měsíců.
     *
     * § 35c odst. 10: „lze poskytnout daňové zvýhodnění ve výši 1/12 za každý
     * kalendářní měsíc, na jehož počátku byly splněny podmínky".
     * § 35c odst. 7: u dítěte s průkazem ZTP/P se částka zvyšuje na dvojnásobek.
     */
    public function childCreditForMonths(int $order, int $months, bool $ztpP): int
    {
        if ($order < 1) {
            throw new \InvalidArgumentException('Pořadí dítěte není platné.');
        }
        if ($months < 0 || $months > self::MONTHS_IN_YEAR) {
            throw new \InvalidArgumentException(
                'Počet měsíců vyživování není platný.',
            );
        }
        $annual = $this->annualChildCreditMinorUnits[min($order, 3)]
            ?? throw new \InvalidArgumentException('Pořadí dítěte nemá roční částku.');
        $amount = intdiv($annual, self::MONTHS_IN_YEAR) * $months;

        return $ztpP ? $amount * 2 : $amount;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ruleset_id' => $this->rulesetId,
            'ruleset_hash' => $this->rulesetHash,
            'policy_id' => $this->policyId,
            'high_rate_threshold_minor_units' => $this->highRateThresholdMinorUnits,
            'low_rate' => $this->lowRate,
            'high_rate' => $this->highRate,
            'bonus_minimum_income_minor_units' => $this->bonusMinimumIncomeMinorUnits,
            'bonus_minimum_amount_minor_units' => $this->bonusMinimumAmountMinorUnits,
            'payout_threshold_minor_units' => $this->payoutThresholdMinorUnits,
            // Sleva na manžela se ve stopě drží POD VLASTNÍM KLÍČEM s příznakem
            // `applied = false`. Ploché klíče vedle sazeb, které se skutečně
            // počítají, dělaly ze snapshotu tvrzení „sleva byla posouzena" —
            // a to modul nedělá. Vstupní podmínky (§ 35bb odst. 2, § 38l)
            // nemá v evidenci a zúčtování na nich fail-closed padá.
            'spouse_credit' => [
                'applied' => false,
                'reason' => 'eligibility_manual_review',
                'statute' => 'zdp-35bb',
                'annual_credit_minor_units' => $this->spouseCreditMinorUnits,
                'ztp_p_multiplier' => $this->spouseCreditZtpPMultiplier,
                'income_limit_minor_units' => $this->spouseIncomeLimitMinorUnits,
            ],
            'annual_credit_minor_units' => $this->annualCreditMinorUnits,
            'annual_child_credit_minor_units' => $this->annualChildCreditMinorUnits,
            // Odvození 12× platí pro slevy a daňové zvýhodnění. Prahy výplaty
            // a sleva na manžela se čtou z rulesetu jako roční hodnoty.
            'derivation' => 'monthly-times-twelve',
            'derivation_basis' => 'zdp-35d-2',
            'annual_keys_source' => 'ruleset',
        ];
    }
}
