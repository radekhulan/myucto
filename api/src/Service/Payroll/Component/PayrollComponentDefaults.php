<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Výchozí číselník mzdových složek — VERZOVANĚ.
 *
 * Tabulka dřív bydlela jako `PayrollComponentRepository::DEFAULTS` a zakládala se
 * s natvrdo napsaným `valid_from = '2026-01-01'`. To mělo dvě následky:
 *
 *  1. Legislativní změnu klasifikace nešlo do existujících firem rozvést vůbec —
 *     `INSERT IGNORE` narazil na unikátní klíč `(supplier_id, code, valid_from)`
 *     a tiše neudělal nic. Firma založená loni zůstala navždy na staré klasifikaci.
 *  2. Roční limit osvobození benefitů (`annual_limit_minor`) ve VÝČTU VKLÁDANÝCH
 *     SLOUPCŮ vůbec nebyl, takže zůstal NULL. `PayrollInputRepository::approve()`
 *     přitom limit hlídá jen tehdy, když NENÍ NULL — u výchozích složek se tedy
 *     roční strop nehlídal vůbec a benefit prošel v jakékoli výši.
 *
 * Verzuje se stejně jako podmínky pracovního vztahu a ruleset: nová verze VZNIKNE
 * VEDLE té staré a předchozí otevřené verzi se dopočítá `valid_to` na den před
 * účinností nové. Historie se nepřepisuje — mzdový vstup schválený loni si drží
 * `component_snapshot_json` a nadále ukazuje na verzi, která tehdy platila.
 *
 * ── Novou verzi, nebo opravu na místě? ──────────────────────────────────────
 * Rozhoduje se podle toho, ČÍ chyba se napravuje, ne podle toho, že se mění
 * hodnota ve sloupci:
 *
 *  - **Změnilo se právo od určitého dne** → NOVÁ VERZE s tímto `valid_from`.
 *    Stará klasifikace pro dřívější období PLATILA a musí zůstat čitelná,
 *    jinak by se zpětně přepsalo zacházení, které je zmrazené ve schválených
 *    vstupech a v revizích běhů.
 *  - **Klasifikace byla od začátku napsaná špatně** → OPRAVA ŘÁDKU NA MÍSTĚ
 *    v té verzi, kde vznikla. Zakládat novou verzi by tvrdilo, že do jejího
 *    dne platilo něco jiného — a to je nepravda: platilo totéž, jen to bylo
 *    v číselníku uvedené chybně. Tak to dělají migrace 1480, 1590 i 1610
 *    s řádky verze 2026-01-01 a je to správně.
 *
 * Oprava na místě je proto **jen pro dosud neúčinnou nebo právě probíhající
 * vlastní chybu**; jakmile se mění samo právo, verze se přidává.
 *
 * Zákonné částky se sem NEPÍŠOU a od migrace 1480 se ani nedosazují do složkového
 * stropu. Složka jen řekne, do KTERÉHO zákonného koše patří
 * ({@see PayrollBenefitExemptionBasket}); částku drží ruleset a limituje se ÚHRN
 * všech složek téhož koše za rok, ne jednotlivá složka. Nová průměrná mzda tedy
 * znamená nový ruleset, ne editaci čísla v kódu ani novou verzi klasifikace.
 *
 * `annual_limit_minor` zůstává výchozím složkám prázdný: je to vlastní strop
 * zaměstnavatele (tvrdá zábrana schválení), ne daňová hranice.
 */
final class PayrollComponentDefaults
{
    /**
     * Verze výchozí klasifikace, chronologicky. Sloupce řádku:
     *
     *  0 kód, 1 název, 2 druh složky, 3 peněžní/nepeněžní, 4 četnost,
     *  5 daň, 6 sociální (účast i vyměřovací základ), 7 zdravotní (účast
     *  i vyměřovací základ), 8 průměrný výdělek, 9 exekuční srážky, 10 JMHZ,
     *  11 statistika, 12 zákonný koš osvobození
     *     ({@see PayrollBenefitExemptionBasket}; NULL = složka do žádného koše
     *     nepatří, důvod je u konkrétního řádku),
     * 13 podklad osvobození ({@see PayrollExemptionBasis}; vyplněný jen u složky
     *     s daňovým zacházením `exempt`, jinak NULL).
     *
     * @var list<array{valid_from:string, rows:list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string,13:?string}>}>
     */
    private const VERSIONS = [
        [
            'valid_from' => '2026-01-01',
            'rows' => [
                ['MZDA_MESICNI', 'Základní měsíční mzda', 'base_wage', 'monetary', 'regular', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['MZDA_HODINOVA', 'Základní hodinová mzda', 'hourly_wage', 'monetary', 'regular', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['MZDA_UKOLOVA', 'Úkolová mzda', 'task_wage', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['ODMENA', 'Odměna', 'bonus', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['PREMIE_PRIPLATKY', 'Prémie a příplatky', 'premium', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['PROVIZE', 'Provize', 'commission', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['NAHRADA_MZDY', 'Náhrada mzdy', 'compensation', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                ['ODSTUPNE', 'Odstupné', 'severance', 'monetary', 'one_off', 'included', 'excluded', 'excluded', 'excluded', 'included', 'included', 'included', null, null],
                ['NAHRADA_KONKURENCNI_DOLOZKA', 'Náhrada za konkurenční doložku', 'competitive_clause', 'monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null, null],
                ['DOPLATEK_MZDY', 'Doplatek mzdy za minulé období', 'backpay', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['NEPENEZNI_PRIJEM', 'Nepeněžní příjem', 'non_cash', 'non_monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                // § 6 odst. 9 písm. b) ZDP. Limit je ZA SMĚNU (70 % horní hranice
                // stravného za cestu 5 až 12 hodin), ne za rok — roční strop složky
                // ho nevyjádří, proto ho drží koš `meal_per_shift`: ruleset dá
                // částku na jednu směnu a počet směn s nárokem dodá evidence
                // docházky ({@see PayrollMealShiftEvidenceService}). Do limitu se
                // neodvádí nic; nadlimitní část odbaví samostatná složka
                // `.nadlimit` ve výpočtu běhu, stejně jako u ročního koše.
                ['PRISPEVEK_STRAVOVANI', 'Příspěvek na stravování', 'benefit_meal', 'monetary', 'regular', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'excluded', 'included', 'meal_per_shift', 'periodic_benefit_limit'],
                // § 6 odst. 9 písm. i) ZDP — hodnota přechodného ubytování do
                // 3 500 Kč měsíčně. Osvobozeno je jen NEPENĚŽNÍ plnění, jen mimo
                // pracovní cestu a jen tehdy, není-li obec přechodného ubytování
                // shodná s obcí bydliště zaměstnance. Obec ani účel plnění aplikace
                // v datech nemá; tyhle podmínky nese ZAŘAZENÍ složky, které volí
                // účetní, a aplikace hlídá to jediné, co spočítat umí — měsíční
                // strop.
                ['PRECHODNE_UBYTOVANI', 'Přechodné ubytování zaměstnance', 'benefit_accommodation', 'non_monetary', 'regular', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'excluded', 'included', 'temporary_accommodation', 'periodic_benefit_limit'],
                // Soukromé užití vozidla je podle § 6 odst. 6 ZDP OCENĚNÍ příjmu
                // (1 % / 0,5 % / 0,25 % vstupní ceny měsíčně), ne osvobozený
                // benefit — žádný roční strop osvobození neexistuje.
                ['SOUKROME_VOZIDLO', 'Soukromé užití vozidla', 'benefit_vehicle', 'non_monetary', 'regular', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                ['PRISPEVEK_PENZE_ZIVOTNI', 'Příspěvek na penzijní a životní produkty', 'benefit_pension', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', 'old_age_savings', null],
                // § 6 odst. 9 písm. m) ZDP sdílí 50 000 Kč s příspěvkem na produkty
                // spoření na stáří, ale jde-li o jinou formu podpory dlouhodobé péče
                // než pojištění, spadá jinam. Zařazení tenhle číselník neurčí, takže
                // limit zůstává prázdný a vyplní ho účetní.
                ['PRISPEVEK_DLOUHODOBA_PECE', 'Příspěvek na dlouhodobou péči', 'benefit_care', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null, null],
                // Vzdělávání má DVA různé režimy: odborný rozvoj související
                // s předmětem činnosti zaměstnavatele je podle § 6 odst. 9 písm. a)
                // ZDP osvobozený BEZ limitu, ostatní vzdělávání spadá pod strop
                // § 6 odst. 9 písm. d) bodu 2. Který z nich platí, plyne z náplně
                // kurzu — proto se tu limit netvrdí; naslepo nasazený strop by
                // blokoval schválení legitimního školení.
                ['VZDELAVANI', 'Vzdělávání zaměstnance', 'benefit_education', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null, null],
                ['REKREACE_VOLNY_CAS', 'Rekreace a volnočasový benefit', 'benefit_recreation', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', 'non_cash_leisure', null],
                ['ZDRAVOTNI_BENEFIT', 'Zdravotní benefit', 'benefit_health', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', 'non_cash_health', null],
                // Povinný příspěvek podle zákona č. 324/2025 Sb. je příspěvkem
                // zaměstnavatele na produkt spoření na stáří. Výši nevytváří
                // ruční mzdový vstup: runtime ji odvodí pouze ze schválené
                // evidence rozhodných směn a zmrazeného vyměřovacího základu.
                ['PRISPEVEK_RIZIKOVE_SPORENI', 'Povinný příspěvek na spoření u rizikové práce', 'risky_savings', 'non_monetary', 'regular', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'included', 'included', 'old_age_savings', 'benefit_basket'],
                ['CESTOVNI_NAHRADA', 'Cestovní náhrada', 'travel_reimbursement', 'monetary', 'one_off', 'manual_review', 'excluded', 'excluded', 'excluded', 'excluded', 'manual_review', 'included', null, null],
                // MZ-08-W07 — klasifikovaný rozpad vyúčtování pracovní cesty. Do zákonného
                // limitu (§ 6 odst. 7 písm. a) ZDP) není náhrada předmětem daně, pojistného,
                // průměrného výdělku ani exekučních srážek; nadlimitní část je běžný
                // zdanitelný příjem ze závislé činnosti a vstupuje do vyměřovacích základů.
                ['CESTOVNI_NAHRADA_LIMIT', 'Cestovní náhrada do zákonného limitu', 'travel_reimbursement', 'monetary', 'one_off', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'excluded', 'included', null, 'not_subject_to_tax'],
                ['CESTOVNI_NAHRADA_NADLIMIT', 'Nadlimitní cestovní náhrada', 'travel_reimbursement', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
            ],
        ],
    ];

    /** @var list<array{valid_from:string, rows:list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string,13:?string}>}> */
    private array $catalog;

    /**
     * @param list<array{valid_from:string, rows:list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string,13:?string}>}>|null $catalog
     *        Jen pro testy verzování; runtime bere vestavěnou sadu.
     */
    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
        ?array $catalog = null,
    ) {
        $this->catalog = $catalog ?? self::VERSIONS;
    }

    /**
     * Kódy složek, které si aplikace zakládá sama, napříč všemi verzemi.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = [];
        foreach (self::VERSIONS as $version) {
            foreach ($version['rows'] as $row) {
                $codes[$row[0]] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Verze klasifikace se zákonným košem osvobození u benefitních složek.
     *
     * Verze, ke které ruleset daně z příjmů nezná částku koše, se PŘESKOČÍ celá.
     * Založit složku s košem, jehož limit neexistuje, by znamenalo tiše vypnout
     * hlídání ročního stropu — přesně vada, kvůli které tahle třída vznikla.
     * Ostatní verze se založí normálně.
     *
     * @return list<array{valid_from:string, rows:list<array{
     *   code:string, name:string, component_kind:string, value_kind:string,
     *   frequency_kind:string, tax_treatment:string,
     *   social_treatment:string, health_treatment:string,
     *   average_earning_treatment:string, enforcement_treatment:string,
     *   jmhz_treatment:string, statistics_treatment:string,
     *   exemption_basket:?string, exemption_basis:?string
     * }>}>
     */
    public function versions(): array
    {
        $versions = [];
        foreach ($this->catalog as $version) {
            $rows = [];
            foreach ($version['rows'] as $row) {
                try {
                    $basket = $this->basket($row[12], $version['valid_from']);
                } catch (PayrollRulesetException) {
                    continue 2;
                }
                $rows[] = [
                    'code' => $row[0],
                    'name' => $row[1],
                    'component_kind' => $row[2],
                    'value_kind' => $row[3],
                    'frequency_kind' => $row[4],
                    'tax_treatment' => $row[5],
                    'social_treatment' => $row[6],
                    'health_treatment' => $row[7],
                    'average_earning_treatment' => $row[8],
                    'enforcement_treatment' => $row[9],
                    'jmhz_treatment' => $row[10],
                    'statistics_treatment' => $row[11],
                    'exemption_basket' => $basket?->value,
                    'exemption_basis' => $this->basis($row[13] ?? null, $row[5]),
                ];
            }
            $versions[] = ['valid_from' => $version['valid_from'], 'rows' => $rows];
        }
        usort(
            $versions,
            static fn (array $left, array $right): int => $left['valid_from'] <=> $right['valid_from'],
        );

        return $versions;
    }

    /**
     * Podklad osvobození ({@see PayrollExemptionBasis}) smí nést jen složka
     * klasifikovaná jako osvobozená — jinak by číselník tvrdil doklad k něčemu,
     * co se stejně zdaní.
     */
    private function basis(?string $value, string $taxTreatment): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($taxTreatment !== PayrollComponentTaxTreatment::EXEMPT->value
            || PayrollExemptionBasis::tryFrom($value) === null
        ) {
            throw new PayrollRulesetException(
                "Podklad osvobození {$value} nelze pro tuhle složku použít.",
            );
        }

        return $value;
    }

    /**
     * Ruleset se bere `forDate()`, ne `forCalculation()`: výchozí číselník je
     * jen SEED, který si firma smí přepsat, a nesmí spadnout jen proto, že
     * sada ještě není odborně schválená.
     *
     * Částka se z rulesetu jen OVĚŘÍ, do složky se nekopíruje — limit koše se
     * čte až ve chvíli výpočtu, aby nemohl zestárnout v číselníku.
     */
    private function basket(?string $value, string $validFrom): ?PayrollBenefitExemptionBasket
    {
        if ($value === null) {
            return null;
        }
        $basket = PayrollBenefitExemptionBasket::tryFrom($value);
        if ($basket === null) {
            throw new PayrollRulesetException(
                "Zákonný koš osvobození {$value} neexistuje.",
            );
        }
        $limit = $this->rulesets
            ->forDate(PayrollRulesetDomain::IncomeTax, $validFrom)
            ->parameter($basket->rulesetKey())
            ->value;
        if (!is_int($limit)) {
            throw new PayrollRulesetException(
                "Roční limit {$basket->rulesetKey()} není částka v haléřích.",
            );
        }

        return $basket;
    }
}
