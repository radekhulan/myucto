<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Česká jména parametrů rulesetu, popisy výčtových hodnot a vysvětlení ručního
 * posouzení — tedy VÝKLADOVÁ vrstva nad legislativní sadou.
 *
 * Proč to nebydlí v {@see PayrollRuleValue} vedle hodnoty, ačkoli by to tam
 * „patřilo k parametru": `PayrollRuleValue::toCanonicalArray()` je vstup do
 * kanonického otisku verze ({@see PayrollRulesetVersion::$canonicalHash},
 * {@see PayrollRulesetContent::hash()}). Kdyby v něm byl název, pak by:
 *
 *  - oprava překlepu v češtině změnila otisk každé verze a rozbila integritní
 *    piny ({@see CzechPayrollRulesets2026::ENFORCEMENT_DEDUCTIONS_HASH}) i
 *    `content_hash` u všech uložených overridů v databázi,
 *  - se název vezl v auditní stopě jako obsahová změna rulesetu, takže „změnil
 *    jsem popisek" by bylo k nerozeznání od „změnil jsem sazbu",
 *  - diff proti ověřené sadě hlásil rozdíl tam, kde se žádná legislativní
 *    hodnota nezměnila.
 *
 * Katalog je proto samostatná, ryze prezentační tabulka ve stejném namespace:
 * žije vedle definice parametru (jeden soubor dál), chodí v odpovědi API
 * (`PayrollRulesetAdminService::detail()`), ale do otisku ani do auditu
 * nezasahuje. Klíč zůstává identifikátorem, název je jen jeho lidský popis.
 *
 * Jazyk je natvrdo česky, bez i18n — je to česká mzdová legislativa a
 * `minimum_assessment_base.monthly` nemá anglický ekvivalent, který by dával
 * účetní smysl. Hlášky a popisky STRÁNKY i18n dál používají.
 *
 * Klíčem je dvojice doména + kanonický klíč parametru: `participation.dpp.minimum`
 * znamená u sociálního pojištění něco jiného než u zdravotního.
 */
final class PayrollRuleParameterCatalog
{
    /**
     * Doména => kanonický klíč => český název parametru.
     *
     * @var array<string, array<string, string>>
     */
    private const LABELS = [
        'income_tax' => [
            'advance.high_rate' => 'Sazba zálohy na daň nad hranicí druhého pásma',
            'advance.high_threshold.monthly' => 'Měsíční hranice pro druhé pásmo zálohy na daň',
            'advance.low_rate' => 'Základní sazba zálohy na daň',
            'advance.rounding.base_above_100_czk' => 'Zaokrouhlení základu zálohy nad 100 Kč',
            'advance.rounding.base_up_to_100_czk' => 'Zaokrouhlení základu zálohy do 100 Kč',
            'advance.rounding.result' => 'Zaokrouhlení vypočtené zálohy na daň',
            'benefit_exemption.meal.minimum_work_minutes' =>
                'Nejkratší odpracovaná doba ve směně pro osvobozený příspěvek na stravování',
            'benefit_exemption.meal.per_shift' => 'Osvobozený příspěvek na stravování za jednu směnu',
            'benefit_exemption.meal.second_contribution_day_minutes' =>
                'Odpracovaná doba za den pro druhý osvobozený příspěvek na stravování',
            'benefit_exemption.meal.second_contribution_shift_minutes' =>
                'Délka směny včetně přestávky pro druhý osvobozený příspěvek na stravování',
            'benefit_exemption.meal.shift_rate' =>
                'Podíl horní hranice stravného za pracovní cestu 5 až 12 hodin',
            'benefit_exemption.non_cash_health.yearly' =>
                'Roční limit osvobození nepeněžních zdravotnických plnění',
            'benefit_exemption.non_cash_leisure.yearly' =>
                'Roční limit osvobození nepeněžních volnočasových plnění',
            'benefit_exemption.old_age_savings.yearly' =>
                'Roční limit osvobození příspěvku na produkty spoření na stáří a pojištění dlouhodobé péče',
            'benefit_exemption.temporary_accommodation.monthly' =>
                'Měsíční limit osvobození hodnoty přechodného ubytování',
            'bonus.minimum_amount.monthly' => 'Nejnižší vyplatitelný měsíční daňový bonus',
            'bonus.minimum_amount.yearly' => 'Nejnižší uplatnitelný roční daňový bonus',
            'bonus.minimum_income.monthly' => 'Minimální měsíční příjem pro nárok na daňový bonus',
            'bonus.minimum_income.yearly' => 'Minimální roční příjem pro nárok na daňový bonus',
            'credit.child.first.monthly' => 'Měsíční daňové zvýhodnění na první dítě',
            'credit.child.second.monthly' => 'Měsíční daňové zvýhodnění na druhé dítě',
            'credit.child.third_and_next.monthly' => 'Měsíční daňové zvýhodnění na třetí a další dítě',
            'credit.disability.basic.monthly' => 'Měsíční základní sleva na invaliditu prvního a druhého stupně',
            'credit.disability.extended.monthly' => 'Měsíční rozšířená sleva na invaliditu třetího stupně',
            'credit.spouse.eligibility' => 'Nárok na slevu na manžela',
            'credit.spouse.yearly' => 'Roční sleva na manžela',
            'credit.spouse.ztp_p_multiplier' => 'Násobek slevy na manžela s přiznaným nárokem na průkaz ZTP/P',
            'credit.taxpayer.monthly' => 'Měsíční základní sleva na poplatníka',
            'credit.ztp_p.monthly' => 'Měsíční sleva na držitele průkazu ZTP/P',
            'dpp.withholding.threshold' => 'Rozhodná částka pro srážkovou daň u dohody o provedení práce',
            'other.withholding.threshold' => 'Rozhodná částka pro srážkovou daň u ostatních příjmů ze závislé činnosti',
            'settlement.payout_threshold' => 'Nejnižší vyplácený přeplatek z ročního zúčtování',
            'spouse.income_limit' => 'Nejvyšší vlastní příjem manžela pro nárok na slevu',
            'withholding.rate' => 'Sazba srážkové daně',
        ],
        'social_insurance' => [
            'average_wage.monthly' => 'Průměrná měsíční mzda pro limity slevy na kratší úvazky',
            'employee.discount.agriculture_dpp' => 'Sleva zaměstnance u zemědělské dohody o provedení práce',
            'employee.discount.working_pensioner' => 'Snížená sazba zaměstnance — pracující starobní důchodce',
            'employee.rate.ordinary' => 'Sazba pojistného zaměstnance',
            'employer.discount.part_time' => 'Sleva zaměstnavatele na kratší pracovní úvazek',
            'employer.discount.part_time.assessment_base_limit_multiple' =>
                'Násobek průměrné mzdy, nad který sleva na kratší úvazek nenáleží',
            'employer.discount.part_time.hourly_assessment_base_limit' =>
                'Podíl průměrné mzdy jako nejvyšší vyměřovací základ na hodinu',
            'employer.discount.part_time.maximum_monthly_millihours' =>
                'Nejvyšší odpracovaná doba za měsíc pro slevu na kratší úvazek (tisíciny hodiny)',
            'employer.discount.part_time.maximum_weekly_millihours' =>
                'Nejvyšší sjednaná týdenní doba pro slevu na kratší úvazek (tisíciny hodiny)',
            'employer.discount.part_time.minimum_weekly_millihours' =>
                'Nejnižší sjednaná týdenní doba pro slevu na kratší úvazek (tisíciny hodiny)',
            'employer.rate.ordinary' => 'Sazba pojistného zaměstnavatele',
            'employer.rate.rescue_and_company_fire_service' =>
                'Sazba zaměstnavatele u hasičských záchranných sborů a podnikových hasičů',
            'employer.rate.risk_employment' => 'Sazba zaměstnavatele u rizikových prací',
            'maximum_assessment_base.yearly' => 'Roční maximální vyměřovací základ',
            'participation.dpp.minimum' =>
                'Rozhodná částka pro účast na nemocenském pojištění u dohody o provedení práce',
            'participation.small_scale.minimum' =>
                'Rozhodná částka pro účast na nemocenském pojištění u zaměstnání malého rozsahu',
            'risky_savings.effective_from' => 'Datum účinnosti povinného příspěvku na spoření zaměstnanců v rizikové práci',
            'risky_savings.minimum_shift_eighths' => 'Nejnižší rozsah rozhodných směn pro povinný příspěvek (osminy směn)',
            'risky_savings.payment_due.months_after_period' => 'Počet měsíců po mzdovém období do splatnosti povinného příspěvku',
            'risky_savings.payment_due.rule' => 'Pravidlo určení dne splatnosti povinného příspěvku',
            'risky_savings.rate' => 'Sazba povinného příspěvku na spoření zaměstnanců v rizikové práci',
        ],
        'health_insurance' => [
            'employee.rate' => 'Podíl zaměstnance na pojistném',
            'employer.rate' => 'Podíl zaměstnavatele na pojistném',
            'minimum_assessment_base.monthly' => 'Měsíční minimální vyměřovací základ',
            'minimum_contribution.monthly' => 'Měsíční minimální pojistné',
            'participation.dpc.minimum' => 'Rozhodná částka pro odvod u dohody o pracovní činnosti',
            'participation.dpp.minimum' => 'Rozhodná částka pro odvod u dohody o provedení práce',
            'rounding.total' => 'Zaokrouhlení celkového pojistného',
            'total.rate' => 'Celková sazba pojistného (zaměstnanec i zaměstnavatel)',
        ],
        'employment_thresholds' => [
            'average_wage.monthly' => 'Průměrná měsíční mzda v národním hospodářství',
            'minimum_wage.hourly_40h_week' => 'Minimální hodinová mzda při 40hodinovém týdnu',
            'minimum_wage.monthly_40h_week' => 'Minimální měsíční mzda při 40hodinovém týdnu',
            'overtime.annual.early_warning_basis_points' => 'Podíl ročního limitu přesčasů, od kterého se upozorňuje předem',
            'overtime.averaging.max_weeks' => 'Nejdelší vyrovnávací období pro průměr přesčasů v týdnech',
            'overtime.averaging.weekly_average_max_minutes' => 'Nejvyšší průměrný týdenní přesčas ve vyrovnávacím období',
            'overtime.ordered.weekly_max_minutes' => 'Nejvyšší nařízený přesčas za týden',
            'overtime.ordered.yearly_max_minutes' => 'Nejvyšší nařízený přesčas za kalendářní rok',
            'participation.dpc.minimum' => 'Rozhodná částka pro účast na pojištění u dohody o pracovní činnosti',
            'participation.dpp.minimum' => 'Rozhodná částka pro účast na pojištění u dohody o provedení práce',
            'participation.small_scale.minimum' => 'Rozhodná částka pro zaměstnání malého rozsahu',
        ],
        'compensation_averages' => [
            'average_earning.minimum_worked_days' => 'Nejmenší počet odpracovaných dnů pro skutečný průměrný výdělek',
            'average_wage.monthly' => 'Průměrná měsíční mzda pro odvození redukčních hranic',
            'leave.agreement_weekly_minutes' => 'Fikce týdenní pracovní doby u dohod pro nárok na dovolenou',
            'leave.entitlement_weeks.statutory_minimum' => 'Zákonná minimální výměra dovolené v týdnech',
            'leave.minimum_continuous_calendar_days' => 'Nejkratší souvislá část dovolené v kalendářních dnech',
            'leave.minimum_worked_week_multiples' => 'Nejmenší počet odpracovaných násobků týdenní doby pro nárok',
            'leave.weeks_per_year' => 'Počet týdnů v roce pro poměrný nárok na dovolenou',
            'surcharge.difficult_environment.basis' =>
                'Základ příplatku za práci ve ztíženém pracovním prostředí',
            'surcharge.difficult_environment.rate' =>
                'Sazba příplatku za práci ve ztíženém pracovním prostředí za každý ztěžující vliv',
            'surcharge.holiday.basis' => 'Základ příplatku za práci ve svátek',
            'surcharge.holiday.rate' => 'Sazba příplatku za práci ve svátek místo náhradního volna',
            'surcharge.holiday.time_off_months' =>
                'Lhůta pro poskytnutí náhradního volna za práci ve svátek v kalendářních měsících',
            'surcharge.night.basis' => 'Základ příplatku za noční práci',
            'surcharge.night.rate' => 'Sazba příplatku za noční práci',
            'surcharge.night.window_end_hour' => 'Konec noční doby',
            'surcharge.night.window_start_hour' => 'Začátek noční doby',
            'surcharge.overtime.basis' => 'Základ příplatku za práci přesčas',
            'surcharge.overtime.rate' => 'Sazba příplatku za práci přesčas',
            'surcharge.overtime.time_off_months' =>
                'Lhůta pro poskytnutí náhradního volna za práci přesčas v kalendářních měsících',
            'surcharge.weekend.basis' => 'Základ příplatku za práci v sobotu a v neděli',
            'surcharge.weekend.rate' => 'Sazba příplatku za práci v sobotu a v neděli',
            'wage_compensation.compensation_rate' => 'Sazba náhrady mzdy z redukovaného průměrného výdělku',
            'wage_compensation.hourly_boundary_1_minor' => 'První hodinová redukční hranice',
            'wage_compensation.hourly_boundary_2_minor' => 'Druhá hodinová redukční hranice',
            'wage_compensation.hourly_boundary_3_minor' => 'Třetí hodinová redukční hranice',
            'wage_compensation.manual_review' => 'Ruční posouzení náhrady mzdy',
            'wage_compensation.reduction_band_1_rate' => 'Redukce výdělku do první redukční hranice',
            'wage_compensation.reduction_band_2_rate' => 'Redukce výdělku mezi první a druhou redukční hranicí',
            'wage_compensation.reduction_band_3_rate' => 'Redukce výdělku mezi druhou a třetí redukční hranicí',
            'wage_compensation.window_calendar_days' => 'Délka období náhrady mzdy při dočasné pracovní neschopnosti',
        ],
        'travel_allowances' => [
            'foreign_travel' => 'Zahraniční pracovní cesty',
            'fuel.average_price.diesel_per_litre' => 'Průměrná cena motorové nafty za litr',
            'fuel.average_price.electricity_per_kwh' => 'Průměrná cena elektřiny za kilowatthodinu',
            'fuel.average_price.petrol_95_per_litre' => 'Průměrná cena benzinu 95 oktanů za litr',
            'fuel.average_price.petrol_98_per_litre' => 'Průměrná cena benzinu 98 oktanů za litr',
            'meal_allowance.band_1.free_meal_reduction_rate' =>
                'Krácení stravného za bezplatné jídlo — první časové pásmo',
            'meal_allowance.band_1.minimum' => 'Dolní sazba stravného — první časové pásmo (5 až 12 hodin)',
            'meal_allowance.band_1.tax_exempt_maximum' =>
                'Horní sazba stravného osvobozená od daně — první časové pásmo',
            'meal_allowance.band_1.to_minutes' => 'Konec prvního časového pásma v minutách',
            'meal_allowance.band_2.free_meal_reduction_rate' =>
                'Krácení stravného za bezplatné jídlo — druhé časové pásmo',
            'meal_allowance.band_2.minimum' => 'Dolní sazba stravného — druhé časové pásmo (12 až 18 hodin)',
            'meal_allowance.band_2.tax_exempt_maximum' =>
                'Horní sazba stravného osvobozená od daně — druhé časové pásmo',
            'meal_allowance.band_2.to_minutes' => 'Konec druhého časového pásma v minutách',
            'meal_allowance.band_3.free_meal_reduction_rate' =>
                'Krácení stravného za bezplatné jídlo — třetí časové pásmo',
            'meal_allowance.band_3.minimum' => 'Dolní sazba stravného — třetí časové pásmo (nad 18 hodin)',
            'meal_allowance.band_3.tax_exempt_maximum' =>
                'Horní sazba stravného osvobozená od daně — třetí časové pásmo',
            'meal_allowance.from_minutes' => 'Nejkratší doba pracovní cesty zakládající nárok na stravné',
            'meal_allowance.two_day_merge_rule' => 'Pravidlo pro sloučení dvou kalendářních dnů cesty',
            'rounding.entitlement' => 'Zaokrouhlení vypočtené cestovní náhrady',
            'vehicle.basic_compensation.car_per_km' => 'Základní náhrada za kilometr u osobního automobilu',
            'vehicle.basic_compensation.single_track_per_km' => 'Základní náhrada za kilometr u jednostopého vozidla',
        ],
        'enforcement_deductions' => [
            'debtor_share.denominator' => 'Nezabavitelná částka na povinného — jmenovatel podílu ze základu',
            'debtor_share.numerator' => 'Nezabavitelná částka na povinného — čitatel podílu ze základu',
            'dependant_share.denominator' =>
                'Nezabavitelná částka na vyživovanou osobu — jmenovatel podílu z částky na povinného',
            'dependant_share.numerator' =>
                'Nezabavitelná částka na vyživovanou osobu — čitatel podílu z částky na povinného',
            'employer_flat_fee.maximum.monthly' => 'Nejvyšší měsíční paušální náhrada nákladů zaměstnavatele',
            'employer_flat_fee.order_effective_from' =>
                'Datum, od kterého se paušální náhrada zaměstnavatele řadí přednostně',
            'energy_flat.monthly' => 'Paušální částka nákladů na energie v základu nezabavitelné částky',
            'four_enforcement_rule.pension_exception_limit' =>
                'Hranice třetiny pro důchodovou výjimku z pravidla čtyř exekucí',
            'fully_attachable.factor_denominator' => 'Hranice plně zabavitelného zbytku — jmenovatel násobku základu',
            'fully_attachable.factor_numerator' => 'Hranice plně zabavitelného zbytku — čitatel násobku základu',
            'fully_attachable.threshold.monthly' => 'Měsíční hranice plně zabavitelného zbytku čisté mzdy',
            'life_minimum.monthly' => 'Životní minimum jednotlivce',
            'normative_rent.monthly' => 'Normativní náklady na bydlení',
            'protected_amount.calculation_base.monthly' => 'Základ pro výpočet nezabavitelných částek',
            'protected_amount.debtor_base.monthly' => 'Měsíční nezabavitelná částka na povinného',
            'rounding.proportional_allocation' => 'Zaokrouhlení poměrného rozdělení srážky mezi pohledávky',
            'rounding.protected_total' => 'Zaokrouhlení celkové nezabavitelné částky',
            'rounding.thirds_base' => 'Zaokrouhlení základu pro výpočet třetin',
        ],
        'deadlines' => [
            'jmhz.deadline.calendar_basis' => 'Kalendář použitý pro lhůtu JMHZ',
            'jmhz.deadline.cancellation_allowed' => 'Přípustnost storna JMHZ v daném období',
            'jmhz.deadline.due_day' => 'Den pravidelného termínu JMHZ',
            'jmhz.deadline.due_on' => 'Pevný přechodný termín JMHZ',
            'jmhz.deadline.due_shift' => 'Posun termínu JMHZ na pracovní den',
            'jmhz.deadline.earliest_day' => 'První den pravidelného okna JMHZ',
            'jmhz.deadline.earliest_submission_on' => 'Pevný začátek přechodného okna JMHZ',
            'jmhz.deadline.month_offset' => 'Posun pravidelného okna JMHZ v měsících',
            'jmhz.deadline.rule' => 'Pravidlo výpočtu lhůty JMHZ',
            'submission_calendar' => 'Kalendář lhůt pro podání',
        ],
        'codebooks' => [
            'catalog_versions' => 'Verze provozních číselníků',
        ],
        'submissions' => [
            'dzmh.schema_version' => 'Verze schématu DZMH — dotaz na stav hlášení',
            'jmhz.schema_version' => 'Verze schématu JMHZ — jednotné měsíční hlášení zaměstnavatele',
            'prezec.schema_version' => 'Verze schématu PREZEC — předregistrace zaměstnance',
            'regzec.schema_version' => 'Verze schématu REGZEC — registrace zaměstnance',
            'regzeldopl.schema_version' => 'Verze schématu REGZELDOPL — doplnění registrace zaměstnavatele',
            'submission' => 'Přímé odeslání podání z aplikace',
        ],
    ];

    /**
     * Výčtové textové hodnoty. Klíč je kanonická hodnota parametru, jak se
     * ukládá i porovnává; popis je jen pro čtení v administraci.
     *
     * Datum ani verze schématu tu schválně nejsou — to nejsou výčty, ale data.
     *
     * @var array<string, string>
     */
    private const VALUE_LABELS = [
        'ceil-to-1-czk' => 'zaokrouhlit nahoru na celé koruny',
        'ceil-to-100-czk' => 'zaokrouhlit nahoru na celé stokoruny',
        'ceil_to_whole_czk_after_sum' => 'sečíst a výsledek zaokrouhlit nahoru na celé koruny',
        'floor_minor_units_then_largest_remainder' =>
            'zaokrouhlit dolů na haléře a zbytek přidělit metodou největšího zbytku',
        'floor_to_whole_czk_divisible_by_three' => 'zaokrouhlit dolů na celé koruny dělitelné třemi',
        'merge-two-calendar-days-when-more-favourable' =>
            'sloučit dva kalendářní dny cesty, je-li to pro zaměstnance výhodnější',
        'last_day_of_month' => 'poslední den příslušného měsíce',
    ];

    /**
     * Vysvětlení ručního posouzení: proč tu aplikace vědomě netvrdí hodnotu
     * a co s tím má uživatel dělat. Skoro vždy nic — proto to musí být napsané.
     *
     * @var array<string, array<string, array{why: string, action: string}>>
     */
    private const MANUAL_REVIEW = [
        'income_tax' => [
            'credit.spouse.eligibility' => [
                'why' => 'Roční sleva na manžela má od 1. 1. 2024 dvě podmínky NAJEDNOU: společně '
                    . 'hospodařící domácnost s manželem A s vyživovaným dítětem poplatníka, které '
                    . 'nedovršilo 3 let věku, k tomu vlastní příjem manžela do zákonného limitu '
                    . 'a doložení podle § 38l. Domácnost, věk dítěte ani příjmy manžela mzdový '
                    . 'modul v datech nemá — částky vedle jsou zákonná čísla, ne posouzení nároku.',
                'action' => 'Nic tu nevyplňujte ani neschvalujte. Slevu na manžela uplatněte '
                    . 'v ročním zúčtování ručně proti doloženým podkladům, nebo ji zaměstnanec '
                    . 'uplatní v daňovém přiznání.',
            ],
        ],
        'social_insurance' => [
            'employee.discount.agriculture_dpp' => [
                'why' => 'Slevu na pojistném u zemědělské dohody o provedení práce lze uplatnit jen '
                    . 'při splnění zákonných podmínek o sezónní zemědělské činnosti. Ty aplikace '
                    . 'z mzdových dat nepozná a nebude si je domýšlet.',
                'action' => 'Běžného zaměstnavatele se to netýká a nic nevyplňujte. Zaměstnáváte-li '
                    . 'sezónní pracovníky v zemědělství na dohodu o provedení práce, uplatněte '
                    . 'slevu ručně a splnění podmínek doložte.',
            ],
            // Tři parametry NÍŽE jsou ruční posouzení jen v ročníku 2025, kam se
            // doplnily zpětně. Ročník 2026 je má jako sazby — vysvětlení se proto
            // uživateli ukáže jedině u sady, které hodnota opravdu chybí.
            'employee.discount.working_pensioner' => [
                'why' => 'Sazbu slevy pracujícího starobního důchodce se pro rok 2025 nepodařilo '
                    . 'doložit z primárního zdroje se stejnou jistotou jako zbytek dodané sady. '
                    . 'Sazba, která by se lišila jen o desetinu procenta, by se propsala do každé '
                    . 'výplaty důchodce, a proto aplikace radši nedosadí nic.',
                'action' => 'Zaměstnáváte-li pracujícího starobního důchodce a počítáte mu mzdu '
                    . 'zpětně za rok 2025, doplňte sazbu podle znění § 7 z. č. 589/1992 Sb. '
                    . 'účinného pro rok 2025. Pro rok 2026 je hodnota dodaná a nic dělat nemusíte.',
            ],
            'employer.rate.rescue_and_company_fire_service' => [
                'why' => 'Zvláštní sazba zaměstnavatele za zdravotnické záchranáře a členy HZS '
                    . 'podniku se pro rok 2025 nepodařilo doložit z primárního zdroje — sekundární '
                    . 'zdroje si navíc protiřečí v tom, která sazba patří které kategorii.',
                'action' => 'Běžného zaměstnavatele se to netýká a nic nevyplňujte. Zaměstnáváte-li '
                    . 'zdravotnické záchranáře nebo členy HZS podniku a počítáte mzdu zpětně za rok '
                    . '2025, doplňte sazbu podle § 7 odst. 1 z. č. 589/1992 Sb. ve znění pro rok 2025.',
            ],
            'employer.rate.risk_employment' => [
                'why' => 'Zvýšené pojistné za rizikové zaměstnání je v dodané sadě doložené až pro '
                    . 'rok 2026. Pro rok 2025 není doloženo ani to, zda tahle kategorie tehdy '
                    . 'existovala, a hádat se to nebude.',
                'action' => 'Běžného zaměstnavatele se to netýká a nic nevyplňujte. Pokud za rok '
                    . '2025 počítáte mzdu zaměstnanci v rizikovém zaměstnání, ověřte znění '
                    . '§ 7 odst. 1 z. č. 589/1992 Sb. pro rok 2025 a sazbu doplňte.',
            ],
        ],
        'compensation_averages' => [
            'wage_compensation.manual_review' => [
                'why' => 'Nárok na náhradu mzdy, úplnost rozvrhu směn, souběh s jinými dávkami '
                    . 'a přerušené směny nejde spolehlivě odvodit z uložených dat. Aplikace proto '
                    . 'radši nedosadí nic, než aby odhadovala.',
                'action' => 'Není to fronta ke schválení a odklikávat se nedá. Náhradu mzdy za '
                    . 'prvních 14 dnů nemoci zkontrolujte na mzdovém listu, než mzdu uzavřete.',
            ],
        ],
        'travel_allowances' => [
            'foreign_travel' => [
                'why' => 'Zahraniční stravné, kapesné a přepočet měn stanoví vyhláška Ministerstva '
                    . 'financí pro každý stát zvlášť. Tenhle ruleset drží jen tuzemské sazby, '
                    . 'a proto pro zahraniční cesty žádnou hodnotu netvrdí.',
                'action' => 'Tuzemské cesty se počítají normálně a nic tu nevyplňujte. Zahraniční '
                    . 'pracovní cestu vyúčtujte ručně podle vyhlášky pro daný stát.',
            ],
        ],
        'deadlines' => [
            'submission_calendar' => [
                'why' => 'Lhůta pro podání se liší podle agendy, události, zvoleného kanálu '
                    . 'a přechodných ustanovení. Jedno univerzální datum neexistuje, takže by ho '
                    . 'aplikace musela vymyslet.',
                'action' => 'Nic tu neschvalujete. Konkrétní termín u konkrétního hlášení ukazuje '
                    . 'stránka Podání.',
            ],
        ],
        'codebooks' => [
            'catalog_versions' => [
                'why' => 'Číselníky se do aplikace nahrávají jako soubor s vlastním datem vydání '
                    . 'a kontrolním součtem. Nemají jednu „hodnotu", kterou by šlo napsat sem.',
                'action' => 'Nic tu nevyplňujte ani neschvalujte. Aktuálnost číselníků se řeší '
                    . 'jejich importem.',
            ],
        ],
        'submissions' => [
            'submission' => [
                'why' => 'Verze schémat jsou zapsané jako parametry níže, ale samotné odeslání '
                    . 'podání není součástí mzdového výpočtu.',
                'action' => 'Nic tu neschvalujete. Podání se odesílá ze stránky Podání.',
            ],
        ],
    ];

    /**
     * Vysvětlení pro celou doménu vedenou jako ruční posouzení. Doplňuje počty,
     * které dopočítává {@see PayrollRulesetAdminService}.
     *
     * @var array<string, string>
     */
    private const MANUAL_REVIEW_DOMAINS = [
        'compensation_averages' => 'Redukční hranice i sazby náhrady mzdy tu jsou vyplněné, ale nárok '
            . 'na náhradu a úplnost rozvrhu směn musí potvrdit člověk. Doména proto do výpočtu '
            . 'nevstupuje automaticky.',
        'deadlines' => 'Lhůty závisí na agendě, události a kanálu podání, takže tu není jedno datum, '
            . 'které by šlo schválit. Termíny hlídá stránka Podání.',
        'codebooks' => 'Číselníky se nahrávají importem se svým datem vydání a kontrolním součtem — '
            . 'v téhle sadě se neschvalují.',
        'submissions' => 'Evidují se tu jen verze schémat pro kontrolu kompatibility. Odesílání '
            . 'podání není součástí mzdového výpočtu.',
    ];

    public static function label(string $domain, string $key): ?string
    {
        return self::LABELS[$domain][$key] ?? null;
    }

    public static function valueLabel(mixed $value): ?string
    {
        return is_string($value) ? (self::VALUE_LABELS[$value] ?? null) : null;
    }

    /** @return array{why: string, action: string}|null */
    public static function manualReview(string $domain, string $key): ?array
    {
        return self::MANUAL_REVIEW[$domain][$key] ?? null;
    }

    public static function domainManualReview(string $domain): ?string
    {
        return self::MANUAL_REVIEW_DOMAINS[$domain] ?? null;
    }

    /**
     * Parametry živé sady, které nemají český název. Slouží testu, aby se na
     * nově přidaný parametr nedalo zapomenout.
     *
     * @return list<string> `doména|klíč`
     */
    public static function missingLabels(PayrollRulesetProvider $provider): array
    {
        $missing = [];
        foreach ($provider->versions() as $version) {
            foreach (array_keys($version->parameters) as $key) {
                if (self::label($version->domain->value, $key) === null) {
                    $missing[$version->domain->value . '|' . $key] = true;
                }
            }
        }
        $keys = array_keys($missing);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Parametry živé sady s ručním posouzením, které nemají vysvětlení.
     *
     * @return list<string> `doména|klíč`
     */
    public static function missingManualReviewExplanations(PayrollRulesetProvider $provider): array
    {
        $missing = [];
        foreach ($provider->versions() as $version) {
            foreach ($version->parameters as $key => $parameter) {
                if (
                    $parameter->capability === PayrollRulesetCapability::ManualReview
                    && self::manualReview($version->domain->value, $key) === null
                ) {
                    $missing[$version->domain->value . '|' . $key] = true;
                }
            }
        }
        $keys = array_keys($missing);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
