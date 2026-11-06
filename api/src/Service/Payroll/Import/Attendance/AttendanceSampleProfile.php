<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Ukázkový profil „Vzor GIRITON" — měsíční podklady z docházkového systému
 * GIRITON a tabulek, které z nich firma skládá:
 *
 *  - list `data*` = export docházky (trvání v excelovém formátu). Rozpad
 *    odpracované doby podle činností je jen členění téhož času, proto se
 *    z exportu berou jen údaje, které výpočetní list nemá;
 *  - list `výpočet*` = měsíční souhrn v desetinných hodinách a peněžní složky.
 *    Známé sloupce mají vlastní složky, ostatní sloupce s částkami dostanou
 *    složku podle hlavičky; součty a kontrolní sloupce se nikdy nepřenášejí;
 *  - list `mzdy*` = seznam osob se zařazením, měsíčními příplatky a odměnami.
 *    Ve výrobě je ve sloupci odměn úkolová mzda (pravidlo s podmínkou na
 *    oddělení). Obědy placené zaměstnancem a srážky jdou z čisté mzdy;
 *  - CSV exportu mezd = osobní a rodné číslo a referenční hrubá a čistá mzda.
 *
 * Každé pravidlo míří na konkrétní druh listu a na konci je „vše ostatní
 * ignorovat" — pomocné listy sešitu (přehledy, produktivita, kopie jmen) tak
 * nevyrobí osoby ani hodnoty. Pořadí je priorita: hodiny z výpočetního listu
 * mají přednost, stejný údaj se nikdy nesčítá.
 *
 * Firma dostane profil jako vlastní záznam (upravit, smazat, zkopírovat);
 * kód drží jen výchozí podobu. Při každé změně pravidel nebo složek zvyšte
 * {@see self::VERSION} — nedotčený vzor firmy se pak převede sám, upravený
 * dostane jen nabídku (PayrollImportProfileRepository::upgradeSample()).
 */
final class AttendanceSampleProfile
{
    public const KEY = 'giriton';
    public const NAME = 'Vzor GIRITON';
    /*
     * 4: „Suma hodinovky NOC" je v podkladech částka, kterou mzdy vyplácejí
     *    jako příplatek za noční práci, ne druhá hodinová mzda. Složka je proto
     *    příplatek a hodinová mzda za noc se nezakládá (základ by se zdvojil
     *    s „Suma hodinovky").
     * 5: „Kontejnery" je deklarovaná složka vzoru, ne složka podle hlavičky.
     *    Složka podle hlavičky vzniká s druhem „ostatní" a bez zařazení pro
     *    JMHZ, takže měsíční hlášení nešlo zmrazit, dokud zařazení nedoplnila
     *    účetní ručně.
     * 6: Pomocné sloupce výpočetního listu (stropy „max příplatek za přesčas",
     *    kontroly a mezisoučty) se výslovně ignorují. Peníze v nich nejsou
     *    k výplatě, ale pravidlo „vše ostatní podle hlavičky" by z nich jinak
     *    udělalo mzdovou složku a nafouklo hrubou mzdu.
     * 7: „senior", „doplatek" a „školení" jsou deklarované složky, ne složky
     *    podle hlavičky, aby měly zařazení pro JMHZ.
     * 8: Zdanitelná část stravování („Součet z Výpočet pro socku") je nepeněžní
     *    příjem a patří do hrubé mzdy i do obou vyměřovacích základů. Bere se
     *    z výchozího číselníku, ne jako deklarovaná složka vzoru: deklarovaná
     *    složka vzniká vždy jako PENĚŽNÍ a tahle by se pak zaměstnanci vyplatila
     *    v čisté mzdě, přestože jídlo už dostal.
     * 9: Stravování míří na vlastní složku číselníku STRAVOVANI_ZDANITELNE se
     *    zařazením 10328 (úhrn zúčtované mzdy) místo obecného NEPENEZNI_PRIJEM.
     *    Obecná složka nese i plnění, která do úhrnu zúčtované mzdy nepatří,
     *    takže výchozí zařazení mít nesmí; bez zařazení ale nejde zmrazit
     *    měsíční hlášení.
     */
    public const VERSION = 9;

    private const CALCULATION = 'výpočet*';
    private const EXPORT = 'data*';
    private const MAIN = 'mzdy*';
    private const CSV = 'csv';

    /** @return list<array{sheet:?string,header:string,meaning:string,unit:?string,component_code:?string,when_header?:string,when_value?:string,rate_percent?:int}> */
    public static function rules(): array
    {
        $rules = [];
        $add = static function (
            ?string $sheet,
            string $header,
            string $meaning,
            ?string $unit = null,
            ?string $component = null,
            ?string $whenHeader = null,
            ?string $whenValue = null,
            ?int $ratePercent = null,
        ) use (&$rules): void {
            $rule = ['sheet' => $sheet, 'header' => $header, 'meaning' => $meaning, 'unit' => $unit, 'component_code' => $component];
            if ($whenHeader !== null && $whenValue !== null) {
                $rule['when_header'] = $whenHeader;
                $rule['when_value'] = $whenValue;
            }
            if ($ratePercent !== null) {
                $rule['rate_percent'] = $ratePercent;
            }
            $rules[] = $rule;
        };

        // Výpočetní list: osoba, hodiny, peníze.
        $add(self::CALCULATION, AttendanceSheetAnalyzer::PERSON_PLACEHOLDER, 'person_name');
        $add(self::CALCULATION, 'jméno*', 'person_name');
        foreach ([
            'fond prac. doby' => 'fund_hours',
            'odpracováno celkem*' => 'worked_hours',
            'přesčasové hodiny' => 'overtime_hours',
            'svátek' => 'holiday_hours',
            'dovolená' => 'vacation_hours',
            'lékař' => 'doctor_hours',
            'nemocenská' => 'sick_hours',
            'paragraf' => 'obstacle_employee_hours',
            'očr' => 'care_hours',
            'otcovská' => 'paternity_hours',
            'neomluveno' => 'unexcused_hours',
            'neplacené volno' => 'unpaid_leave_hours',
            'služební cesta' => 'business_trip_hours',
            'doma za 80*' => 'obstacle_employer_hours',
            'práce ve svátek' => 'holiday_work_hours',
            'práce noční*' => 'night_hours',
            'práce o víkendu*' => 'weekend_hours',
        ] as $header => $meaning) {
            // „Doma za 80 %" = prostoj, náhrada 80 % průměru; firma ji v profilu může upravit.
            $add(self::CALCULATION, $header, $meaning, AttendanceMeaning::UNIT_HOURS, ratePercent: $meaning === AttendanceRules::RATE_MEANING ? 80 : null);
        }
        foreach ([
            'mzda úkol' => 'MZDA_UKOLOVA',
            'suma hodinovky noc*' => 'PRIPLATEK_NOCNI',
            'suma hodinovky*' => 'MZDA_HODINOVA_DOCH',
            'příplatky k hodinové mzdě*' => 'PRIPLATKY_K_HODINOVE',
            'příplatek odpolední*' => 'PRIPLATEK_ODPOLEDNI',
            'kontejnery' => 'ODMENA_KONTEJNERY',
            'mimořádná odměna' => 'ODMENA_MIMORADNA',
            'hotovostní odměna*' => 'ODMENA_HOTOVOSTNI',
            /*
             * Tyhle tři sloupce bývají celé měsíce prázdné, a právě proto jsou tu
             * vyjmenované: jakmile v nich částka je, udělalo by z nich pravidlo
             * „vše ostatní podle hlavičky" složku s druhem „ostatní" a bez zařazení
             * pro JMHZ, což zablokuje zmrazení měsíčního hlášení. Zařazení plyne
             * z druhu složky: odměna za seniorství je nepravidelná odměna,
             * doplatek do zaručené mzdy i placená doba školení jsou mzda.
             */
            'senior' => 'ODMENA_SENIOR',
            'doplatek' => 'DOPLATEK_MZDY',
            'školení' => 'MZDA_SKOLENI',
        ] as $header => $code) {
            $add(self::CALCULATION, $header, 'component', AttendanceMeaning::UNIT_AMOUNT, $code);
        }
        /*
         * Mezisoučty, kontroly a pomocná množství — kdyby se přenesly, zdvojily by mzdu.
         *
         * Patří sem i sloupce, které vypadají jako peníze za přesčas: „přesčas den x 25 %"
         * nese hodiny, ne částku, a „max příplatek za přesčas +100" je jen strop sazby pro
         * výpočet v sešitu. Mzdu za přesčas nese „Suma hodinovky vč. přesčasů", zákonný
         * příplatek počítá MyÚčto z hodin přesčasu podle politiky příplatků vztahu.
         */
        foreach ([
            'z toho v hodinovce*', 'úkol', 'hod', 'součet*', 'orientační*', 'hrubý příjem',
            'čas hodinovky*', 'přesčas*25*', 'noční úvazek', 'záloha*', 'oddělení', 'poznámka',
            'max příplatek*', 'kontrola*', 'dorovnání*', 'souhrn v hodinovce*', 'souhrn v úkole*',
            'souhrn noc*',
        ] as $header) {
            $add(self::CALCULATION, $header, 'ignore');
        }
        $add(self::CALCULATION, '*', 'component', AttendanceMeaning::UNIT_AMOUNT, AttendanceRules::AUTO_COMPONENT);

        // Export docházky: jen údaje, které výpočetní list nemá.
        $add(self::EXPORT, AttendanceSheetAnalyzer::PERSON_PLACEHOLDER, 'person_name');
        $add(self::EXPORT, 'jméno*', 'person_name');
        $add(self::EXPORT, 'práce odpolední', 'afternoon_hours', AttendanceMeaning::UNIT_DURATION);
        $add(self::EXPORT, 'home office', 'home_office_hours', AttendanceMeaning::UNIT_DURATION);
        $add(self::EXPORT, 'volno z přesčasu', 'compensatory_time_off_hours', AttendanceMeaning::UNIT_DURATION);
        $add(self::EXPORT, '*', 'ignore');

        // Hlavní seznam osob měsíce.
        $add(self::MAIN, 'jméno*', 'person_name');
        $add(self::MAIN, 'oddělení', 'department');
        $add(self::MAIN, 'středisko', 'cost_center');
        $add(self::MAIN, 'týdenní fond', 'weekly_hours');
        $add(self::MAIN, 'název pozice', 'position');
        // Sjednaná měsíční mzda patří do podmínek vztahu, ne mezi měsíční vstupy:
        // předvyplní se při zakládání osoby a u existujícího vztahu ji import
        // zapíše do podmínek jen na výslovné potvrzení.
        $add(self::MAIN, 'mv', 'monthly_wage');
        $add(self::MAIN, 'mzdový výměr*', 'monthly_wage');
        $add(self::MAIN, 'měsíční mzda*', 'monthly_wage');
        $add(self::MAIN, 'nový nástup*', 'start_end_note');
        $add(self::MAIN, 'příplatek bozp', 'component', AttendanceMeaning::UNIT_AMOUNT, 'PRIPLATEK_BOZP');
        // Ve výrobě nese sloupec odměn úkolovou mzdu, jinde odměnu.
        $add(self::MAIN, 'odměny*', 'component', AttendanceMeaning::UNIT_AMOUNT, 'MZDA_UKOLOVA', 'oddělení', 'výroba');
        $add(self::MAIN, 'odměny*', 'component', AttendanceMeaning::UNIT_AMOUNT, 'ODMENA');
        /*
         * Zdanitelná část závodního stravování: hodnota jídla nad osvobozený limit
         * (§ 6 odst. 9 písm. b) ZDP). Je to NEPENĚŽNÍ příjem — zvyšuje hrubou mzdu
         * a oba vyměřovací základy, ale nevyplácí se. Proto míří na složku
         * STRAVOVANI_ZDANITELNE z výchozího číselníku, kterou vstupní brána zná
         * a která má výchozí zařazení do JMHZ 10328 (úhrn zúčtované mzdy);
         * deklarovat ji ve vzoru nelze, složky vzoru vznikají jako peněžní.
         *
         * Druhá strana téhož plnění je srážka za obědy („dotovaná cena") níže.
         * Ta jde z ČISTÉ mzdy a zůstává beze změny — nejde o dvojí započtení,
         * ale o dvě různé strany: hodnota jídla v hrubém, spoluúčast v čistém.
         */
        $add(self::MAIN, '*pro socku*', 'component', AttendanceMeaning::UNIT_AMOUNT, 'STRAVOVANI_ZDANITELNE');
        $add(self::MAIN, '*výpočtu obědů*', 'component', AttendanceMeaning::UNIT_AMOUNT, 'STRAVOVANI_ZDANITELNE');
        $add(self::MAIN, '*dotovaná cena*', 'net_meal_deduction', AttendanceMeaning::UNIT_AMOUNT);
        $add(self::MAIN, 'srážky*', 'net_other_deduction', AttendanceMeaning::UNIT_AMOUNT);
        $add(self::MAIN, '*', 'ignore');

        // CSV exportu mezd.
        $add(self::CSV, 'zaměstnanec', 'person_name');
        $add(self::CSV, 'rodné číslo', 'birth_number');
        $add(self::CSV, 'osobní číslo', 'personal_number');
        $add(self::CSV, 'pracpoměr', 'relation_label');
        $add(self::CSV, 'odprachod', 'reference_hours', AttendanceMeaning::UNIT_HOURS);
        $add(self::CSV, 'hrubá mzda', 'reference_gross', AttendanceMeaning::UNIT_AMOUNT);
        $add(self::CSV, 'čistá mzda', 'reference_net', AttendanceMeaning::UNIT_AMOUNT);
        $add(self::CSV, '*', 'ignore');

        // Ostatní listy (přehledy, produktivita, poznámky) do importu nepatří.
        $add('*', '*', 'ignore');

        return $rules;
    }

    /** @return list<array{code:string,name:string,kind:string}> */
    public static function components(): array
    {
        return [
            ['code' => 'MZDA_HODINOVA_DOCH', 'name' => 'Hodinová mzda podle docházky', 'kind' => 'hourly_wage'],
            ['code' => 'PRIPLATEK_NOCNI', 'name' => 'Příplatek za noční práci podle podkladů', 'kind' => 'premium'],
            ['code' => 'PRIPLATKY_K_HODINOVE', 'name' => 'Příplatky k hodinové mzdě', 'kind' => 'premium'],
            ['code' => 'PRIPLATEK_ODPOLEDNI', 'name' => 'Příplatek za odpolední směnu', 'kind' => 'premium'],
            ['code' => 'PRIPLATEK_BOZP', 'name' => 'Příplatek BOZP', 'kind' => 'premium'],
            // Odměna za odvedený výkon (počet kontejnerů), vyplácená nepravidelně:
            // druh `bonus` a jednorázová četnost ji v JMHZ řadí na 10331 Odměny nepravidelné.
            ['code' => 'ODMENA_KONTEJNERY', 'name' => 'Odměna za kontejnery', 'kind' => 'bonus'],
            ['code' => 'ODMENA_MIMORADNA', 'name' => 'Mimořádná odměna', 'kind' => 'bonus'],
            ['code' => 'ODMENA_HOTOVOSTNI', 'name' => 'Hotovostní odměna', 'kind' => 'bonus'],
            ['code' => 'ODMENA_SENIOR', 'name' => 'Odměna za seniorství', 'kind' => 'bonus'],
            // Doplatek do zaručené mzdy a placená doba školení jsou mzda za práci,
            // proto tarifní druh; v JMHZ jdou na tarifní mzdy jako hodinová mzda.
            ['code' => 'DOPLATEK_MZDY', 'name' => 'Doplatek mzdy', 'kind' => 'hourly_wage'],
            ['code' => 'MZDA_SKOLENI', 'name' => 'Mzda za dobu školení', 'kind' => 'hourly_wage'],
        ];
    }
}
