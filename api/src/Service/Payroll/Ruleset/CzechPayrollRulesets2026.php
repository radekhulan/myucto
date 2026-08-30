<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Payroll-only immutable fixture. The broader legacy TaxConstants table remains
 * an accounting fallback and is deliberately not a runtime input to this registry.
 *
 * ## Proč je dodaná sada rovnou `active`
 *
 * Do 8/2026 tady stálo `PayrollRulesetLifecycle::Reviewed` a zákazník musel
 * u každé z deseti domén projít `review → approve → activate`, jinak si mzdy
 * nespočítal vůbec. Rešerše patnácti českých mzdových systémů
 * (`private/LEGISLATIVNI-SADY-KONKURENCE.md`) ukázala, že schvalování
 * legislativních sazeb uživatelem NEMÁ ANI JEDEN — kdo má nejsilnější důvod
 * přenést odpovědnost, přenáší ji smlouvou, ne klikáním.
 *
 * Sada je proto dodávaná jako účinná. Invarianta „účinné vyžaduje schválení"
 * se NERUŠÍ, jen se omezuje na obsah, který se od dodané sady liší — viz
 * {@see VendorRulesetManifest}. Za dodané hodnoty ručí dodavatel a doloženy jsou
 * {@see RulesetSource} (odkaz + datum stažení) a {@see RulesetTechnicalReview}.
 *
 * ## Kdo za hodnoty ručí
 *
 * Do 8/2026 tu stálo, že formální záznam o tom, KDO za hodnoty ručí, nevzniká,
 * a `approval` bylo u všech jedenácti verzí `null`. Zadavatel rozhodl, že
 * schvalovatelem je firma, která instalaci PROVOZUJE — proto sadu podepisuje
 * {@see VendorRulesetApprover}, a to hodnotou z konfigurace instalace, ne
 * literálem v kódu. Technická kontrola zůstává tím, čím byla (kontrola zdrojů
 * a přesných hodnot), a je v podpisu vedená jako `reviewed_by`; odbornou
 * odpovědnost nese `approved_by`.
 *
 * Zákazníka se to nedotkne: schválení není součástí otisku OBSAHU
 * ({@see PayrollRulesetContent}), takže `content_hash` každé verze i uložené
 * overridy zůstávají beze změny a sada je dál poznána jako dodaná.
 */
final class CzechPayrollRulesets2026
{
    public const RETRIEVED_ON = '2026-08-03';

    /**
     * Integritní pin nezabavitelných částek (§ 278 o. s. ř., nařízení vlády
     * č. 595/2006 Sb.). Do MZ-14-W11 stejnou roli plnil `EXPECTED_HASH` nad
     * konstantami v `EnforcementRuleset2026`; hodnoty se ale kvůli tomu nedaly
     * změnit bez nasazení. Bydlí proto v registry jako každý jiný parametr
     * a pin hlídá už jen VÝCHOZÍ sadu z kódu — override z administrace má
     * vlastní `content_hash` a vlastní auditní stopu.
     *
     * Pin je od 8/2026 vedený nad otiskem OBSAHU ({@see PayrollRulesetContent}),
     * ne nad plným snapshotem. Dřív byl nad plným snapshotem a hýbal se pokaždé,
     * když se změnilo něco, co s nezabavitelnými částkami nemá nic společného —
     * naposledy při překlopení dodané sady na `active`. Rozhodující ale bylo
     * doplnění schvalovatele: ten je podle {@see VendorRulesetApprover} vlastností
     * INSTALACE, takže pin nad plným snapshotem by u jiného provozovatele nesedl
     * a shodil by celý mzdový modul výjimkou o nesouhlasném kontrolním součtu.
     * Nad obsahem pin hlídá přesně to, co hlídat má: dodané částky a jejich zdroje.
     *
     * Je to tedy TÁŽ hodnota, jakou pro doménu exekučních srážek nese
     * {@see VendorRulesetManifest::CONTENT_HASHES}. Dvě čísla pro jednu věc jsou
     * riziko, proto jejich shodu hlídá `CzechPayrollRulesets2026Test`.
     */
    public const ENFORCEMENT_DEDUCTIONS_HASH =
        'ed148cfae04da4449f38425a3ff641f5c54865d741122735992eadb202d8bd1e';

    public static function provider(): PayrollRulesetProvider
    {
        $technicalReview = new RulesetTechnicalReview(
            'myucto/payroll-ruleset-source-check',
            self::RETRIEVED_ON,
            'Manifest oficiálních zdrojů, kontrola přesných hodnot a testy bajtové stability — '
            . 'technická kontrola, ne odborné ani právní schválení.',
        );

        return new PayrollRulesetProvider([
            self::incomeTax($technicalReview),
            self::socialInsurance($technicalReview),
            self::healthInsurance($technicalReview),
            self::employmentThresholds($technicalReview),
            self::compensationAverages($technicalReview),
            self::travelAllowancesUntilMay($technicalReview),
            self::travelAllowancesFromJune($technicalReview),
            self::enforcementDeductions($technicalReview),
            ...self::deadlines($technicalReview),
            self::codebooks($technicalReview),
            self::submissions($technicalReview),
        ]);
    }

    private static function incomeTax(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.income-tax.v1',
            PayrollRulesetDomain::IncomeTax,
            PayrollRulesetCapability::Supported,
            [self::financialAdministration()],
            [
                'advance.high_rate' => PayrollRuleValue::rate('0.23'),
                'advance.high_threshold.monthly' => PayrollRuleValue::moneyMinor(14_690_100),
                'advance.low_rate' => PayrollRuleValue::rate('0.15'),
                'advance.rounding.base_above_100_czk' => PayrollRuleValue::text('ceil-to-100-czk'),
                'advance.rounding.base_up_to_100_czk' => PayrollRuleValue::text('ceil-to-1-czk'),
                'advance.rounding.result' => PayrollRuleValue::text('ceil-to-1-czk'),
                // § 6 odst. 9 písm. b) ZDP — příspěvek na stravování „poskytnutého
                // zaměstnavatelem za jednu směnu …, pokud během této směny
                // zaměstnanec vykonával práci alespoň 3 hodiny a nevznikl mu během
                // této směny nárok na stravné v rámci cestovních náhrad …, a to
                // v úhrnu do výše 70 % horní hranice stravného, které lze poskytnout
                // zaměstnancům odměňovaným platem při pracovní cestě trvající 5 až
                // 12 hodin, a v úhrnu do výše 70 % této hranice, je-li příspěvek
                // poskytnut jako další příspěvek v rámci stejné směny, pokud její
                // délka v úhrnu s přestávkou v práci povinně poskytovanou
                // zaměstnavatelem … je delší než 11 hodin".
                //
                // Čtyři parametry, protože zákon dává čtyři různá čísla:
                //   per_shift ....... limit na jednu směnu, tj. 70 % ze 185,00 Kč
                //                     (`meal_allowance.band_1.tax_exempt_maximum`
                //                     v doméně cestovních náhrad) = 129,50 Kč.
                //                     Odvození HLÍDÁ TEST, ať částka nemůže utéct
                //                     od sazby stravného, ze které plyne.
                //   shift_rate ...... těch 70 % jako data, ne jako věta v komentáři.
                //   minimum_work_minutes ......... „alespoň 3 hodiny", NEOSTŘE.
                //   second_contribution_shift_minutes ... „delší než 11 hodin",
                //                     OSTŘE, a měří se délka směny V ÚHRNU
                //                     S PŘESTÁVKOU, tedy hrubý interval směny.
                //   second_contribution_day_minutes ..... větev pro výkon práce
                //                     nerozvržený na směny: tam zákon říká
                //                     „vykonával práci alespoň 11 hodin", tedy
                //                     NEOSTŘE a o odpracované době, ne o intervalu.
                'benefit_exemption.meal.minimum_work_minutes' => PayrollRuleValue::integer(180),
                'benefit_exemption.meal.per_shift' => PayrollRuleValue::moneyMinor(12_950),
                'benefit_exemption.meal.second_contribution_day_minutes' =>
                    PayrollRuleValue::integer(660),
                'benefit_exemption.meal.second_contribution_shift_minutes' =>
                    PayrollRuleValue::integer(660),
                'benefit_exemption.meal.shift_rate' => PayrollRuleValue::rate('0.70'),
                // § 6 odst. 9 písm. d) ZDP — nepeněžní plnění zaměstnanci a jeho
                // rodinnému příslušníkovi. Od 1. 1. 2025 má dva samostatné roční
                // ÚHRNNÉ limity odvozené z průměrné mzdy za zdaňovací období
                // (§ 21g ZDP, 2026 = 48 967 Kč):
                //   bod 1 — zdravotnické služby a zdravotnické prostředky … průměrná mzda
                //   bod 2 — rekreace a zájezd, sport, kultura, tisk, použití
                //           vzdělávacích a předškolních zařízení … polovina průměrné mzdy
                // Limit je úhrn za bod, ne za jednu mzdovou složku, a nerovnost je
                // NEOSTRÁ („do výše"). Od migrace 1480 ho drží společný koš
                // {@see \MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket};
                // složkový `annual_limit_minor` je jen vlastní strop zaměstnavatele.
                'benefit_exemption.non_cash_health.yearly' => PayrollRuleValue::moneyMinor(4_896_700),
                'benefit_exemption.non_cash_leisure.yearly' => PayrollRuleValue::moneyMinor(2_448_350),
                // § 6 odst. 9 písm. m) ZDP — příspěvek zaměstnavatele na daňově
                // podporované produkty spoření na stáří a na pojištění dlouhodobé
                // péče, osvobozený „do úhrnné výše 50000 Kč ročně". Částku píše
                // zákon číslem, z průměrné mzdy se neodvozuje. Písmeno se posunulo:
                // do 2023 to bylo p), ve znění účinném pro 2026 je to m).
                'benefit_exemption.old_age_savings.yearly' => PayrollRuleValue::moneyMinor(5_000_000),
                // § 6 odst. 9 písm. i) ZDP — „hodnota přechodného ubytování, nejde-li
                // o ubytování při pracovní cestě, poskytovaná jako nepeněžní plnění
                // zaměstnavatelem zaměstnancům v souvislosti s výkonem práce, pokud
                // obec přechodného ubytování není shodná s obcí, kde má zaměstnanec
                // bydliště, a to maximálně do výše 3 500 Kč měsíčně". Částku píše
                // zákon číslem a nerovnost je NEOSTRÁ („maximálně do výše"), takže
                // přesně 3 500 Kč je ještě celé osvobozených. Období je KALENDÁŘNÍ
                // MĚSÍC, nepřenáší se ani nesčítá do roku.
                'benefit_exemption.temporary_accommodation.monthly' =>
                    PayrollRuleValue::moneyMinor(350_000),
                // § 35d odst. 4 ZDP: „Měsíční daňový bonus lze vyplatit, pokud jeho
                // výše činí ALESPOŇ 50 Kč." Nerovnost je NEOSTRÁ — přesně 50 Kč se
                // vyplácí. Sourozeneckým klíčem je `bonus.minimum_amount.yearly`
                // (§ 35c odst. 3), ne dvanáctinásobek tohohle čísla.
                'bonus.minimum_amount.monthly' => PayrollRuleValue::moneyMinor(5_000),
                // § 35c odst. 3 ZDP: „Poplatník může daňový bonus uplatnit, pokud jeho
                // výše činí ALESPOŇ 100 Kč." Roční hodnota tu stojí VÝSLOVNĚ, protože
                // 12× měsíční práh by dal 600 Kč — vztah dvanáctiny, který § 35d odst. 2
                // zakládá pro slevy a daňové zvýhodnění, na prahy výplaty NEDOPADÁ.
                // Odvozovat ji by byla tichá chyba přesně té třídy, před kterou varuje
                // {@see \MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxRates}.
                'bonus.minimum_amount.yearly' => PayrollRuleValue::moneyMinor(10_000),
                'bonus.minimum_income.monthly' => PayrollRuleValue::moneyMinor(1_120_000),
                'bonus.minimum_income.yearly' => PayrollRuleValue::moneyMinor(13_440_000),
                'credit.child.first.monthly' => PayrollRuleValue::moneyMinor(126_700),
                'credit.child.second.monthly' => PayrollRuleValue::moneyMinor(186_000),
                'credit.child.third_and_next.monthly' => PayrollRuleValue::moneyMinor(232_000),
                'credit.disability.basic.monthly' => PayrollRuleValue::moneyMinor(21_000),
                'credit.disability.extended.monthly' => PayrollRuleValue::moneyMinor(42_000),
                // § 35bb ZDP (od 1. 1. 2024 vyčleněno z § 35ba odst. 1 písm. b), kde
                // zůstal jen odkaz). Sleva se uplatní AŽ v ročním zúčtování — § 38h
                // odst. 6 ji vyjmenovává mezi tím, k čemu plátce při výpočtu záloh
                // nepřihlíží — proto je klíč roční a měsíční protějšek nemá.
                //
                // § 35bb odst. 1 věta první: „Výše slevy na manžela činí 24 840 Kč."
                'credit.spouse.yearly' => PayrollRuleValue::moneyMinor(2_484_000),
                // § 35bb odst. 1 věta druhá: „Výše slevy se zvyšuje na DVOJNÁSOBEK,
                // pokud je sleva uplatňována na manžela, kterému je přiznán nárok na
                // průkaz ZTP/P." Rozhodný je PŘIZNANÝ NÁROK na průkaz, ne jeho držení.
                // Násobek se veze jako vlastní parametr, ne jako druhá částka, aby
                // novela základní částky nemohla nechat zdvojnásobenou verzi stát.
                'credit.spouse.ztp_p_multiplier' => PayrollRuleValue::integer(2),
                'credit.taxpayer.monthly' => PayrollRuleValue::moneyMinor(257_000),
                'credit.ztp_p.monthly' => PayrollRuleValue::moneyMinor(134_500),
                // ROZHODNÁ ČÁSTKA, ne „nejvyšší ještě sražená odměna“. § 6 odst. 4
                // ZDP ve znění zák. č. 470/2024 Sb. (od 1. 1. 2025) říká „NEDOSÁHNE
                // částky rozhodné pro účast … na nemocenském pojištění“ — test je
                // tedy OSTRÝ (`<`) a hodnota je sama rozhodná částka, ne o korunu
                // nižší číslo. Do 31. 12. 2024 stálo v zákoně „nepřesáhne 10 000 Kč“,
                // tedy hranice včetně; proto se ta stará mez nedá vyjádřit týmž
                // klíčem a v tomhle rulesetu ani není.
                //
                // Klíče se jmenovaly `*.maximum` a nesly 11 999 / 4 499 Kč, což byl
                // přepis populárního výkladu „limit je 11 999" do `<=`. Pro celé
                // koruny to vychází stejně, pro odměnu s haléři ne: 11 999,50 Kč
                // rozhodné částky NEDOSÁHNE, a měla by tedy jít srážkou — se starým
                // zápisem šla zálohou. Přejmenování je záměrné: uložený override na
                // starý klíč se po obratu operátoru NESMÍ tiše použít dál, jinak by
                // posunul hranici o korunu níž.
                //
                // 2026: 25 % průměrné mzdy 48 967 = 12 241,75 → dolů na celých 500
                // (§ 7a odst. 2 z. č. 187/2006 Sb.) = 12 000 Kč.
                'dpp.withholding.threshold' => PayrollRuleValue::moneyMinor(1_200_000),
                // § 6 odst. 4 písm. b) ZDP — ostatní příjmy ze závislé činnosti;
                // rozhodná částka pro účast na nemocenském pojištění podle § 6 odst. 1
                // písm. a) z. č. 187/2006 Sb. je 1/10 průměrné mzdy zaokrouhlená dolů
                // na celých 500 Kč: 48 967 / 10 = 4 896,7 → 4 500 Kč.
                'other.withholding.threshold' => PayrollRuleValue::moneyMinor(450_000),
                // § 38ch odst. 5 ZDP: přeplatek z ročního zúčtování plátce vrátí,
                // „činí-li úhrnná výše tohoto přeplatku VÍCE NEŽ 50 Kč"; § 35d odst. 8
                // říká totéž o doplatku ze zúčtování u poplatníka s daňovým zvýhodněním.
                // Nerovnost je OSTRÁ — přesně 50 Kč se nevyplácí.
                //
                // Je to JINÉ PRAVIDLO než `bonus.minimum_amount.monthly`, i když je
                // tam dnes stejné číslo: tamto je práh měsíčního daňového bonusu podle
                // § 35d odst. 4 a je NEOSTRÝ. Sloučit je by znamenalo, že novela
                // jednoho tiše změní druhé a navíc obrátí operátor.
                'settlement.payout_threshold' => PayrollRuleValue::moneyMinor(5_000),
                // § 35bb odst. 2 písm. b) ZDP: slevu lze uplatnit, jen pokud „manžel
                // poplatníka nemá vlastní příjem PŘESAHUJÍCÍ za zdaňovací období
                // 68 000 Kč". Příjem přesně 68 000 Kč nárok neruší.
                'spouse.income_limit' => PayrollRuleValue::moneyMinor(6_800_000),
                // Nárok na slevu na manžela aplikace NETVRDÍ. § 35bb odst. 2 písm. a)
                // přidal od 1. 1. 2024 druhou, KUMULATIVNÍ podmínku: poplatník musí žít
                // ve společně hospodařící domácnosti s manželem A s vyživovaným dítětem
                // poplatníka, které nedovršilo věku 3 let (odst. 3 z toho vylučuje vnuka
                // mimo náhradní péči). Do toho vlastní příjem manžela s taxativním
                // výčtem sedmi vyňatých plnění (odst. 4) a doložení podle § 38l.
                // Nic z toho nemá mzdový modul v datech a odhadovat to nebude —
                // částky výše jsou zákonná čísla, ne příslib, že se sleva spočítá.
                'credit.spouse.eligibility' => PayrollRuleValue::manualReview(
                    'Nárok na slevu na manžela závisí na společně hospodařící domácnosti, '
                    . 'na vyživovaném dítěti do 3 let věku, na vlastním příjmu manžela '
                    . 'a na doložení podle § 38l — musí ho posoudit mzdová účetní.',
                ),
                'withholding.rate' => PayrollRuleValue::rate('0.15'),
            ],
            $technicalReview,
        );
    }

    private static function socialInsurance(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.social-insurance.v1',
            PayrollRulesetDomain::SocialInsurance,
            PayrollRulesetCapability::Supported,
            [self::socialSecurity()],
            [
                'employee.discount.agriculture_dpp' => PayrollRuleValue::manualReview(
                    'Nárok na slevu závisí na zákonných podmínkách sezónní zemědělské činnosti '
                    . 'a musí ho posoudit člověk.',
                ),
                'employee.discount.working_pensioner' => PayrollRuleValue::rate('0.065'),
                'employee.rate.ordinary' => PayrollRuleValue::rate('0.071'),
                'employer.discount.part_time' => PayrollRuleValue::rate('0.05'),
                // § 7a odst. 3 vyjmenovává meze, při jejichž překročení sleva
                // NENÁLEŽÍ, ačkoli je zaměstnanec v okruhu podle odst. 1: úhrn
                // vyměřovacích základů nad 1,5násobek průměrné mzdy, základ na
                // hodinu nad 1,15 % průměrné mzdy a odpracovaná doba nad 138
                // hodin. § 7a odst. 2 k tomu váže rozsah sjednané kratší doby
                // 8 až 30 hodin týdně. Průměrná mzda se mění každý rok, a proto
                // sem patří i ona — sazby ani limity nesmí být v kódu.
                'employer.discount.part_time.assessment_base_limit_multiple' =>
                    PayrollRuleValue::rate('1.5'),
                'employer.discount.part_time.hourly_assessment_base_limit' =>
                    PayrollRuleValue::rate('0.0115'),
                'employer.discount.part_time.maximum_monthly_millihours' =>
                    PayrollRuleValue::integer(138_000),
                'employer.discount.part_time.maximum_weekly_millihours' =>
                    PayrollRuleValue::integer(30_000),
                'employer.discount.part_time.minimum_weekly_millihours' =>
                    PayrollRuleValue::integer(8_000),
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(4_896_700),
                // § 7 odst. 1 zák. č. 589/1992 Sb. dává zaměstnavateli TŘI sazby,
                // každou z vlastního vyměřovacího základu podle § 5a odst. 1:
                // písm. a) běžná 24,8 %, písm. b) zdravotničtí záchranáři a HZS
                // podniku „počínaje rokem 2026" 29,8 %, písm. c) rizikové
                // zaměstnání „v roce 2026" 27,8 %. Sazby b) a c) rostou po letech,
                // takže patří do ročního rulesetu, ne do kódu.
                //
                // Zařazení zaměstnance ke kategorii ruleset NEROZHODUJE — to je
                // údaj o konkrétním člověku a nese ho pracovní vztah spolu
                // s odkazem na podklad. Bez doloženého zařazení skončí výpočet
                // na `manual_review` ve vstupu, ne tady na chybějící sazbě.
                'employer.rate.ordinary' => PayrollRuleValue::rate('0.248'),
                'employer.rate.rescue_and_company_fire_service' => PayrollRuleValue::rate('0.298'),
                'employer.rate.risk_employment' => PayrollRuleValue::rate('0.278'),
                'maximum_assessment_base.yearly' => PayrollRuleValue::moneyMinor(235_041_600),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_200_000),
                'participation.small_scale.minimum' => PayrollRuleValue::moneyMinor(450_000),
                'risky_savings.effective_from' => PayrollRuleValue::text('2026-01-01'),
                'risky_savings.minimum_shift_eighths' => PayrollRuleValue::integer(24),
                'risky_savings.payment_due.months_after_period' => PayrollRuleValue::integer(1),
                'risky_savings.payment_due.rule' => PayrollRuleValue::text('last_day_of_month'),
                'risky_savings.rate' => PayrollRuleValue::rate('0.04'),
            ],
            $technicalReview,
        );
    }

    private static function healthInsurance(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.health-insurance.v1',
            PayrollRulesetDomain::HealthInsurance,
            PayrollRulesetCapability::Supported,
            [self::healthInsuranceMethod(), self::healthInsurance2026(), self::healthAgreements2026()],
            [
                'employee.rate' => PayrollRuleValue::rate('0.045'),
                'employer.rate' => PayrollRuleValue::rate('0.09'),
                'minimum_assessment_base.monthly' => PayrollRuleValue::moneyMinor(2_240_000),
                'minimum_contribution.monthly' => PayrollRuleValue::moneyMinor(302_400),
                'participation.dpc.minimum' => PayrollRuleValue::moneyMinor(450_000),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_200_000),
                'rounding.total' => PayrollRuleValue::text('ceil-to-1-czk'),
                'total.rate' => PayrollRuleValue::rate('0.135'),
            ],
            $technicalReview,
        );
    }

    private static function employmentThresholds(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.employment-thresholds.v1',
            PayrollRulesetDomain::EmploymentThresholds,
            PayrollRulesetCapability::Supported,
            [self::minimumWage(), self::socialSecurity()],
            [
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(4_896_700),
                'minimum_wage.hourly_40h_week' => PayrollRuleValue::moneyMinor(13_440),
                'minimum_wage.monthly_40h_week' => PayrollRuleValue::moneyMinor(2_240_000),
                // § 79 odst. 1 zákoníku práce — obecná stanovená týdenní pracovní
                // doba je 40 hodin, tedy 2 400 minut; kratší stanovená doba se od ní
                // odvozuje ({@see \MyInvoice\Service\Payroll\Absence\MinimumWageFloor}).
                'minimum_wage.standard_weekly_minutes' => PayrollRuleValue::integer(2_400),
                // § 93 odst. 2 zákoníku práce — nařízený přesčas nesmí přesáhnout
                // 8 hodin v jednotlivých týdnech a 150 hodin v kalendářním roce.
                'overtime.ordered.weekly_max_minutes' => PayrollRuleValue::integer(480),
                'overtime.ordered.yearly_max_minutes' => PayrollRuleValue::integer(9_000),
                // § 93 odst. 4 — celkový přesčas nejvýše průměrně 8 hodin týdně ve
                // vyrovnávacím období nejvýše 26 týdnů. Kolektivní smlouva ho smí
                // rozšířit na 52, ale registr kolektivních smluv per zaměstnavatele
                // neexistuje, takže je hodnota národní.
                'overtime.averaging.max_weeks' => PayrollRuleValue::integer(26),
                'overtime.averaging.weekly_average_max_minutes' => PayrollRuleValue::integer(480),
                // Bez opory v zákoně: práh, od kterého se na blížící se roční limit
                // upozorňuje dřív, než se vyčerpá.
                'overtime.annual.early_warning_basis_points' => PayrollRuleValue::integer(8_000),
                'participation.dpc.minimum' => PayrollRuleValue::moneyMinor(450_000),
                'participation.dpp.minimum' => PayrollRuleValue::moneyMinor(1_200_000),
                'participation.small_scale.minimum' => PayrollRuleValue::moneyMinor(450_000),
            ],
            $technicalReview,
        );
    }

    private static function compensationAverages(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.compensation-averages.v1',
            PayrollRulesetDomain::CompensationAverages,
            PayrollRulesetCapability::ManualReview,
            [self::socialSecurity(), self::labourCode()],
            [
                'average_earning.minimum_worked_days' => PayrollRuleValue::integer(21),
                'average_wage.monthly' => PayrollRuleValue::moneyMinor(4_896_700),
                'leave.agreement_weekly_minutes' => PayrollRuleValue::integer(1_200),
                'leave.entitlement_weeks.statutory_minimum' => PayrollRuleValue::integer(4),
                'leave.minimum_continuous_calendar_days' => PayrollRuleValue::integer(28),
                'leave.minimum_worked_week_multiples' => PayrollRuleValue::integer(4),
                'leave.weeks_per_year' => PayrollRuleValue::integer(52),
                // ── Podpůrčí doby nemocenského pojištění, z. č. 187/2006 Sb. ──────
                // § 38b odst. 1 — podpůrčí doba u otcovské činí 2 týdny.
                'sickness_benefit.paternity_support_days' => PayrollRuleValue::integer(14),
                // § 40 odst. 1 písm. a) — ošetřovné nejdéle 9 kalendářních dnů.
                'sickness_benefit.care_support_days' => PayrollRuleValue::integer(9),
                // § 40 odst. 1 písm. b) — u osamělého pojištěnce s dítětem do 16 let
                // se podpůrčí doba prodlužuje na 16 kalendářních dnů.
                'sickness_benefit.care_support_days_lone_carer' => PayrollRuleValue::integer(16),
                // ── Zákonné příplatky ke mzdě, § 114 až § 118 zákoníku práce ──────
                //
                // Sazba i ZÁKLAD jsou parametry, ne konstanty v kalkulátoru: § 117
                // jako jediný počítá z minimální mzdy, ostatní z průměrného výdělku,
                // a kdyby to bylo napsané v kódu, změna základu by znamenala
                // nasazení. Základ je proto text (`average_earning` /
                // `minimum_wage_hourly`), který čte
                // {@see \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeBasis}.
                //
                // Všechny sazby jsou ZÁKONNÉ MINIMUM. Vyšší sjednaná sazba je
                // vlastnost pracovního vztahu, ne legislativy, a bydlí
                // v `payroll_employment_surcharge_policies` (migrace 1624).
                //
                // § 117 odst. 2 říká „nejméně 10 % základní sazby minimální mzdy",
                // tedy sazby pro čtyřicetihodinový týden. NEPŘEPOČÍTÁVÁ se na kratší
                // úvazek — na rozdíl od minima průměrného výdělku podle § 357, kde
                // přepočet dělá {@see \MyInvoice\Service\Payroll\Absence\MinimumWageFloor}.
                'surcharge.difficult_environment.basis' => PayrollRuleValue::text('minimum_wage_hourly'),
                'surcharge.difficult_environment.rate' => PayrollRuleValue::rate('0.10'),
                // § 115 odst. 2 — příplatek NEJMÉNĚ ve výši průměrného výdělku,
                // tedy 100 %, a jen tehdy, byl-li sjednán MÍSTO náhradního volna.
                'surcharge.holiday.basis' => PayrollRuleValue::text('average_earning'),
                'surcharge.holiday.rate' => PayrollRuleValue::rate('1.00'),
                // § 115 odst. 1 a § 114 odst. 2 — náhradní volno do konce třetího
                // kalendářního měsíce následujícího po výkonu práce.
                'surcharge.holiday.time_off_months' => PayrollRuleValue::integer(3),
                'surcharge.night.basis' => PayrollRuleValue::text('average_earning'),
                'surcharge.night.rate' => PayrollRuleValue::rate('0.10'),
                // § 78 odst. 1 písm. j) a k) — noční doba je doba mezi 22:00 a 6:00.
                // Nepoužívá se k výpočtu, ale k tomu, aby se do příplatku nedostal
                // interval, který noční prací být nemůže.
                'surcharge.night.window_end_hour' => PayrollRuleValue::integer(6),
                'surcharge.night.window_start_hour' => PayrollRuleValue::integer(22),
                'surcharge.overtime.basis' => PayrollRuleValue::text('average_earning'),
                'surcharge.overtime.rate' => PayrollRuleValue::rate('0.25'),
                'surcharge.overtime.time_off_months' => PayrollRuleValue::integer(3),
                'surcharge.weekend.basis' => PayrollRuleValue::text('average_earning'),
                'surcharge.weekend.rate' => PayrollRuleValue::rate('0.10'),
                'wage_compensation.compensation_rate' => PayrollRuleValue::rate('0.60'),
                'wage_compensation.hourly_boundary_1_minor' => PayrollRuleValue::moneyMinor(28_578),
                'wage_compensation.hourly_boundary_2_minor' => PayrollRuleValue::moneyMinor(42_858),
                'wage_compensation.hourly_boundary_3_minor' => PayrollRuleValue::moneyMinor(85_698),
                'wage_compensation.manual_review' => PayrollRuleValue::manualReview(
                    'Nárok na náhradu, úplnost rozvrhu směn, souběh s dávkami a přerušené směny '
                    . 'musí posoudit mzdová účetní.',
                ),
                'wage_compensation.reduction_band_1_rate' => PayrollRuleValue::rate('0.90'),
                'wage_compensation.reduction_band_2_rate' => PayrollRuleValue::rate('0.60'),
                'wage_compensation.reduction_band_3_rate' => PayrollRuleValue::rate('0.30'),
                'wage_compensation.window_calendar_days' => PayrollRuleValue::integer(14),
            ],
            $technicalReview,
        );
    }

    /**
     * Tuzemské cestovní náhrady. Časová pásma, krácení za bezplatné jídlo a
     * zaokrouhlení plynou přímo ze zákoníku práce, peněžní sazby z vyhlášky
     * č. 573/2025 Sb. Novela č. 78/2026 Sb. zvedla od 1. 6. 2026 průměrnou cenu
     * motorové nafty, proto má rok dvě neprolínající se účinné verze.
     */
    private static function travelAllowancesUntilMay(
        RulesetTechnicalReview $technicalReview,
    ): PayrollRulesetVersion {
        return self::travelAllowances(
            'cz-payroll-2026.travel-allowances.v1',
            '2026.1.0',
            '2026-01-01',
            '2026-05-31',
            3_410,
            [self::labourCodeTravel(), self::travelAllowanceDecree2026()],
            $technicalReview,
        );
    }

    private static function travelAllowancesFromJune(
        RulesetTechnicalReview $technicalReview,
    ): PayrollRulesetVersion {
        return self::travelAllowances(
            'cz-payroll-2026.travel-allowances.v2',
            '2026.2.0',
            '2026-06-01',
            '2026-12-31',
            4_450,
            [
                self::labourCodeTravel(),
                self::travelAllowanceDecree2026(),
                self::travelAllowanceDieselAmendment2026(),
            ],
            $technicalReview,
        );
    }

    /** @param non-empty-list<RulesetSource> $sources */
    private static function travelAllowances(
        string $id,
        string $version,
        string $effectiveFrom,
        string $effectiveTo,
        int $dieselPerLitreMinor,
        array $sources,
        RulesetTechnicalReview $technicalReview,
    ): PayrollRulesetVersion {
        $parameters = [
            'foreign_travel' => PayrollRuleValue::manualReview(
                'Zahraniční pracovní cesty (zahraniční stravné, kapesné, přepočet měn) tenhle '
                . 'ruleset neřeší a vyúčtují se ručně podle vyhlášky pro daný stát.',
            ),
            'fuel.average_price.diesel_per_litre' => PayrollRuleValue::moneyMinor($dieselPerLitreMinor),
            'fuel.average_price.electricity_per_kwh' => PayrollRuleValue::moneyMinor(720),
            'fuel.average_price.petrol_95_per_litre' => PayrollRuleValue::moneyMinor(3_470),
            'fuel.average_price.petrol_98_per_litre' => PayrollRuleValue::moneyMinor(3_900),
            'meal_allowance.band_1.free_meal_reduction_rate' => PayrollRuleValue::rate('0.70'),
            'meal_allowance.band_1.minimum' => PayrollRuleValue::moneyMinor(15_500),
            'meal_allowance.band_1.tax_exempt_maximum' => PayrollRuleValue::moneyMinor(18_500),
            'meal_allowance.band_1.to_minutes' => PayrollRuleValue::integer(720),
            'meal_allowance.band_2.free_meal_reduction_rate' => PayrollRuleValue::rate('0.35'),
            'meal_allowance.band_2.minimum' => PayrollRuleValue::moneyMinor(23_600),
            'meal_allowance.band_2.tax_exempt_maximum' => PayrollRuleValue::moneyMinor(28_400),
            'meal_allowance.band_2.to_minutes' => PayrollRuleValue::integer(1_080),
            'meal_allowance.band_3.free_meal_reduction_rate' => PayrollRuleValue::rate('0.25'),
            'meal_allowance.band_3.minimum' => PayrollRuleValue::moneyMinor(37_000),
            'meal_allowance.band_3.tax_exempt_maximum' => PayrollRuleValue::moneyMinor(44_200),
            'meal_allowance.from_minutes' => PayrollRuleValue::integer(300),
            'meal_allowance.two_day_merge_rule' => PayrollRuleValue::text(
                'merge-two-calendar-days-when-more-favourable',
            ),
            'rounding.entitlement' => PayrollRuleValue::text('ceil-to-1-czk'),
            'vehicle.basic_compensation.car_per_km' => PayrollRuleValue::moneyMinor(590),
            'vehicle.basic_compensation.single_track_per_km' => PayrollRuleValue::moneyMinor(160),
        ];
        ksort($parameters, SORT_STRING);

        return new PayrollRulesetVersion(
            $id,
            $version,
            PayrollRulesetDomain::TravelAllowances,
            $effectiveFrom,
            $effectiveTo,
            PayrollRulesetLifecycle::Active,
            PayrollRulesetCapability::Supported,
            $sources,
            $parameters,
            VendorRulesetApprover::approval($technicalReview),
            $technicalReview,
        );
    }

    /**
     * Nezabavitelné částky a pravidla pořadí exekučních srážek. Životní minimum
     * i normativní náklady na bydlení mění vláda nařízením několikrát za rok,
     * proto jsou hodnoty administrovatelné a výchozí sada je jen pinnutý default.
     *
     * Odvozené částky (základ pro výpočet, nezabavitelná částka na povinného,
     * hranice plně zabavitelného zbytku) se ZÁMĚRNĚ vezou jako samostatné
     * parametry, ne jako runtime dopočet: nařízení je vyhlašuje přímo v korunách
     * a účetní je opisuje z tabulky. Jejich soulad se vstupy hlídá test.
     */
    private static function enforcementDeductions(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.enforcement-deductions.v1',
            PayrollRulesetDomain::EnforcementDeductions,
            PayrollRulesetCapability::Supported,
            [
                self::civilProcedure(),
                self::enforcementCalculator(),
                self::enforcementIncome(),
                self::insolvencyDebtRelief(),
                self::labourCodeDeductions(),
            ],
            [
                'debtor_share.denominator' => PayrollRuleValue::integer(100),
                'debtor_share.numerator' => PayrollRuleValue::integer(85),
                'dependant_share.denominator' => PayrollRuleValue::integer(4),
                'dependant_share.numerator' => PayrollRuleValue::integer(1),
                'employer_flat_fee.maximum.monthly' => PayrollRuleValue::moneyMinor(5_000),
                'employer_flat_fee.order_effective_from' => PayrollRuleValue::text('2022-01-01'),
                'energy_flat.monthly' => PayrollRuleValue::moneyMinor(230_000),
                'four_enforcement_rule.pension_exception_limit' =>
                    PayrollRuleValue::moneyMinor(108_900),
                'fully_attachable.factor_denominator' => PayrollRuleValue::integer(10),
                'fully_attachable.factor_numerator' => PayrollRuleValue::integer(19),
                'fully_attachable.threshold.monthly' => PayrollRuleValue::moneyMinor(3_152_100),
                'life_minimum.monthly' => PayrollRuleValue::moneyMinor(486_000),
                'normative_rent.monthly' => PayrollRuleValue::moneyMinor(943_000),
                'protected_amount.calculation_base.monthly' =>
                    PayrollRuleValue::moneyMinor(1_659_000),
                'protected_amount.debtor_base.monthly' => PayrollRuleValue::moneyMinor(1_410_150),
                'rounding.proportional_allocation' =>
                    PayrollRuleValue::text('floor_minor_units_then_largest_remainder'),
                'rounding.protected_total' => PayrollRuleValue::text('ceil_to_whole_czk_after_sum'),
                'rounding.thirds_base' =>
                    PayrollRuleValue::text('floor_to_whole_czk_divisible_by_three'),
            ],
            $technicalReview,
            self::ENFORCEMENT_DEDUCTIONS_HASH,
        );
    }

    /** @return list<PayrollRulesetVersion> */
    private static function deadlines(RulesetTechnicalReview $technicalReview): array
    {
        return [
            self::jmhzDeadlineRuleset(
                'cz-jmhz-deadlines-2026.transition.v1',
                '2026.1.0',
                '2026-01-01',
                '2026-03-31',
                [
                    'jmhz.deadline.calendar_basis' => PayrollRuleValue::text('business_days'),
                    'jmhz.deadline.cancellation_allowed' => PayrollRuleValue::boolean(false),
                    'jmhz.deadline.due_on' => PayrollRuleValue::text('2026-06-30'),
                    'jmhz.deadline.due_shift' => PayrollRuleValue::text('next_czech_working_day'),
                    'jmhz.deadline.earliest_submission_on' => PayrollRuleValue::text('2026-04-01'),
                    'jmhz.deadline.rule' => PayrollRuleValue::text('transition_fixed_window'),
                ],
                $technicalReview,
            ),
            self::jmhzDeadlineRuleset(
                'cz-jmhz-deadlines-2026.regular.v1',
                '2026.1.0',
                '2026-04-01',
                '2026-12-31',
                [
                    'jmhz.deadline.calendar_basis' => PayrollRuleValue::text('business_days'),
                    'jmhz.deadline.cancellation_allowed' => PayrollRuleValue::boolean(true),
                    'jmhz.deadline.due_day' => PayrollRuleValue::integer(20),
                    'jmhz.deadline.due_shift' => PayrollRuleValue::text('next_czech_working_day'),
                    'jmhz.deadline.earliest_day' => PayrollRuleValue::integer(1),
                    'jmhz.deadline.month_offset' => PayrollRuleValue::integer(1),
                    'jmhz.deadline.rule' => PayrollRuleValue::text('following_month_day_window'),
                ],
                $technicalReview,
            ),
        ];
    }

    /**
     * @param non-empty-array<string, PayrollRuleValue> $parameters
     */
    private static function jmhzDeadlineRuleset(
        string $id,
        string $version,
        string $effectiveFrom,
        string $effectiveTo,
        array $parameters,
        RulesetTechnicalReview $technicalReview,
    ): PayrollRulesetVersion {
        ksort($parameters, SORT_STRING);

        return new PayrollRulesetVersion(
            $id,
            $version,
            PayrollRulesetDomain::Deadlines,
            $effectiveFrom,
            $effectiveTo,
            PayrollRulesetLifecycle::Active,
            PayrollRulesetCapability::Supported,
            [
                self::jmhzAct(),
                self::jmhzGovernmentRegulation(),
                self::jmhzDocumentation(),
            ],
            $parameters,
            VendorRulesetApprover::approval($technicalReview),
            $technicalReview,
        );
    }

    private static function codebooks(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.codebooks.v1',
            PayrollRulesetDomain::Codebooks,
            PayrollRulesetCapability::ManualReview,
            [self::jmhzDocumentation()],
            [
                'catalog_versions' => PayrollRuleValue::manualReview(
                    'Provozní číselníky se nahrávají importem s vlastním datem vydání a kontrolním '
                    . 'součtem, ne zápisem hodnoty do téhle sady.',
                ),
            ],
            $technicalReview,
        );
    }

    private static function submissions(RulesetTechnicalReview $technicalReview): PayrollRulesetVersion
    {
        return self::version(
            'cz-payroll-2026.submissions.v1',
            PayrollRulesetDomain::Submissions,
            PayrollRulesetCapability::ManualReview,
            [self::jmhzDocumentation()],
            [
                'dzmh.schema_version' => PayrollRuleValue::text('1.1'),
                'jmhz.schema_version' => PayrollRuleValue::text('1.4.3.4'),
                'prezec.schema_version' => PayrollRuleValue::text('1.2'),
                'regzec.schema_version' => PayrollRuleValue::text('1.4.0.4'),
                'regzeldopl.schema_version' => PayrollRuleValue::text('1.2'),
                'submission' => PayrollRuleValue::manualReview(
                    'Verze schémat jsou evidované, ale samotné odeslání podání není součástí '
                    . 'mzdového výpočtu.',
                ),
            ],
            $technicalReview,
        );
    }

    /**
     * @param non-empty-list<RulesetSource> $sources
     * @param non-empty-array<string, PayrollRuleValue> $parameters
     */
    private static function version(
        string $id,
        PayrollRulesetDomain $domain,
        PayrollRulesetCapability $capability,
        array $sources,
        array $parameters,
        RulesetTechnicalReview $technicalReview,
        ?string $expectedContentHash = null,
    ): PayrollRulesetVersion {
        ksort($parameters, SORT_STRING);

        return new PayrollRulesetVersion(
            $id,
            '2026.1.0',
            $domain,
            '2026-01-01',
            '2026-12-31',
            PayrollRulesetLifecycle::Active,
            $capability,
            $sources,
            $parameters,
            VendorRulesetApprover::approval($technicalReview),
            $technicalReview,
            $expectedContentHash,
        );
    }

    private static function financialAdministration(): RulesetSource
    {
        return new RulesetSource(
            'fs-dependent-activity-2026',
            'Finanční správa: zaměstnanci a zaměstnavatelé, zdaňovací období 2026',
            'https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/zamestnanci-zamestnavatele/obecne-informace',
            self::RETRIEVED_ON,
        );
    }

    private static function socialSecurity(): RulesetSource
    {
        return new RulesetSource(
            'cssz-key-data-2026',
            'ČSSZ: Přehled nejdůležitějších údajů pro sociální zabezpečení v roce 2026',
            'https://www.cssz.cz/documents/20143/2872693/TZ__P%C5%99ehled%20nejd%C5%AFle%C5%BEit%C4%9Bj%C5%A1%C3%ADch%20%C3%BAdaj%C5%AF%20pro%20soci%C3%A1ln%C3%AD%20zabezpe%C4%8Den%C3%AD%20v%20roce%202026.pdf/3c0800f6-15d0-a4df-8a9e-35b93969b355',
            self::RETRIEVED_ON,
        );
    }

    private static function minimumWage(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-minimum-wage-2026',
            'MPSV: Minimální mzda v roce 2026',
            'https://ppropo.mpsv.cz/xviii1minimalnimzdaanejnizsiurov',
            self::RETRIEVED_ON,
        );
    }

    private static function healthInsuranceMethod(): RulesetSource
    {
        return new RulesetSource(
            'vzp-employer-method',
            'VZP: Plátce pojistného – zaměstnavatel',
            'https://www.vzp.cz/platci/informace/povinnosti-platcu-metodika/2-4-platce-pojistneho-zamestnavatel',
            self::RETRIEVED_ON,
        );
    }

    private static function healthInsurance2026(): RulesetSource
    {
        return new RulesetSource(
            'vzp-health-payments-2026',
            'VZP: Platby zdravotního pojištění v roce 2026',
            'https://www.vzp.cz/o-nas/tiskove-centrum/otazky-tydne/platby-zdravotniho-pojisteni-v-roce-2026',
            self::RETRIEVED_ON,
        );
    }

    private static function healthAgreements2026(): RulesetSource
    {
        return new RulesetSource(
            'vzp-dpp-dpc-2026',
            'VZP: Změny u odvodů na zdravotním pojištění pro DPP a DPČ 2026',
            'https://www.vzp.cz/o-nas/tiskove-centrum/otazky-tydne/zmeny-u-odvodu-na-zdravotnim-pojisteni-pro-dpp-a-dpc-2026',
            self::RETRIEVED_ON,
        );
    }

    private static function civilProcedure(): RulesetSource
    {
        return new RulesetSource(
            'e-sbirka-civil-procedure',
            'e-Sbírka: občanský soudní řád č. 99/1963 Sb.',
            'https://www.e-sbirka.cz/sb/1963/99',
            self::RETRIEVED_ON,
        );
    }

    private static function enforcementCalculator(): RulesetSource
    {
        return new RulesetSource(
            'justice-enforcement-calculator-2026',
            'Justice.cz: výpočet srážek ze mzdy pro rok 2026',
            'https://exekuce.justice.cz/vypocet-srazek-ze-mzdy/',
            self::RETRIEVED_ON,
        );
    }

    private static function enforcementIncome(): RulesetSource
    {
        return new RulesetSource(
            'justice-enforcement-income',
            'Justice.cz: srážky ze mzdy a jiných příjmů',
            'https://exekuce.justice.cz/srazky-ze-mzdy-a-jinych-prijmu/',
            self::RETRIEVED_ON,
        );
    }

    private static function insolvencyDebtRelief(): RulesetSource
    {
        return new RulesetSource(
            'justice-insolvency-debt-relief',
            'Justice.cz: oddlužení — jak ven z dluhové pasti',
            'https://insolvence.justice.cz/jak-ven-z-dluhove-pasti/oddluzeni/',
            self::RETRIEVED_ON,
        );
    }

    private static function labourCodeDeductions(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-labour-code-deductions',
            'MPSV: srážky z příjmu z pracovněprávního vztahu',
            'https://ppropo.mpsv.cz/pdf/XXI4Srazkyzprijmuzpracovnepravni.pdf',
            self::RETRIEVED_ON,
        );
    }

    private static function labourCode(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-labour-code-current',
            'MPSV: zákoník práce č. 262/2006 Sb., § 192, § 213 a § 351 až 362',
            'https://ppropo.mpsv.cz/zakon_262_2006',
            self::RETRIEVED_ON,
        );
    }

    private static function labourCodeTravel(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-labour-code-travel',
            'MPSV: zákoník práce č. 262/2006 Sb., § 156 až 189 (cestovní náhrady, časová pásma stravného a krácení za bezplatné jídlo)',
            'https://ppropo.mpsv.cz/zakon_262_2006',
            self::RETRIEVED_ON,
        );
    }

    private static function travelAllowanceDecree2026(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-travel-allowance-decree-2026',
            'MPSV: vyhláška č. 573/2025 Sb., o sazbě základní náhrady, stravném a průměrné ceně pohonných hmot pro rok 2026',
            'https://ppropo.mpsv.cz/Vyhlaska_573_2025',
            self::RETRIEVED_ON,
        );
    }

    private static function travelAllowanceDieselAmendment2026(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-travel-allowance-diesel-2026',
            'MPSV: vyhláška č. 78/2026 Sb. — změna průměrné ceny motorové nafty od 1. 6. 2026',
            'https://mpsv.gov.cz/rust-cen-nafty-se-promita-do-cestovnich-nahrad-mpsv-aktualizuje-vyhlasku',
            self::RETRIEVED_ON,
        );
    }

    private static function jmhzDocumentation(): RulesetSource
    {
        return new RulesetSource(
            'mpsv-jmhz-documentation',
            'MPSV: technická dokumentace JMHZ',
            'https://developers.mpsv.cz/api-list/jednotne-mesicni-hlaseni-zamestnavatelu/documentation/4589f5c6-30e8-4e2b-b341-fe8481ad4e70',
            self::RETRIEVED_ON,
        );
    }

    private static function jmhzAct(): RulesetSource
    {
        return new RulesetSource(
            'e-sbirka-jmhz-act-2025',
            'e-Sbírka: zákon č. 323/2025 Sb., o jednotném měsíčním hlášení zaměstnavatele',
            'https://www.e-sbirka.cz/sb/2025/323',
            self::RETRIEVED_ON,
        );
    }

    private static function jmhzGovernmentRegulation(): RulesetSource
    {
        return new RulesetSource(
            'e-sbirka-jmhz-regulation-2025',
            'e-Sbírka: nařízení vlády č. 417/2025 Sb., o náležitostech jednotného měsíčního hlášení zaměstnavatele',
            'https://www.e-sbirka.cz/sb/2025/417',
            self::RETRIEVED_ON,
        );
    }
}
