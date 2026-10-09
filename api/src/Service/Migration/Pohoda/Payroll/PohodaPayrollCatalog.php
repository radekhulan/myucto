<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Absence\VacationCompensationReturn;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceText;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;

/**
 * Význam mzdové složky, nepřítomnosti a srážky POHODA Mzdy / PAMICA pro import
 * docházky a mezd MyÚčta. Klasifikace se řídí číslem složky (skupina podle první
 * číslice katalogu) a u víceúčelových složek (O01, J03) i jejím názvem.
 *
 * U srážek je to jinak: tam rozhoduje číselník `sMZsrazky` (příznaky `JeZak`,
 * `JeDepon` a název), ne číslo složky - to si uživatel v PAMICA přidává a přepisuje,
 * takže `S01a` / `S07` viděné na jedné instalaci nejsou kontrakt.
 *
 * Co import MyÚčta vědomě nepřebírá sešitem, protože to nese jiná cesta převodu
 * (základní mzdu sloupec měsíční mzdy, zákonné srážky krok exekucí), vrací význam
 * `ignore`. Položka, kterou převod nezná, vrací `unknown`: do sešitu nejde, ale
 * měsíc ji vrátí k varování v protokolu ({@see PohodaPayrollConverter::month()}),
 * aby se nic neztratilo tiše.
 */
final class PohodaPayrollCatalog
{
    /** Standardní složka příplatku za noční práci (JMHZ 10334), stejná jako ve vzoru GIRITON. */
    public const NIGHT_PREMIUM = 'PRIPLATEK_NOCNI';

    /**
     * Zdanitelná část závodního stravování: složka výchozího číselníku se zařazením
     * do JMHZ 10328. Vlastní `PAM_*` by pro totéž plnění vyrobila druhou složku bez
     * zařazení, rozdělila by úhrn v hlášení a zmrazení podání by na ní spadlo.
     */
    public const TAXABLE_MEAL = 'STRAVOVANI_ZDANITELNE';

    /**
     * Stravenkový paušál (Z21) do limitu za směnu, složka výchozího číselníku
     * (osvobozeno § 6 odst. 9 písm. b) ZDP, bez pojistného, JMHZ 10286 a 10289).
     */
    public const MEAL_ALLOWANCE_EXEMPT = 'PRISPEVEK_STRAVOVANI_PREVZATY';

    /** Stravenkový paušál nad limit (Z21 `Hodnota4`) a paušál bez osvobození (Z21a). */
    public const MEAL_ALLOWANCE_TAXABLE = 'PRISPEVEK_STRAVOVANI_ZDANITELNY';

    /** Odměna za kontejnery, tatáž složka jako ve vzoru GIRITON (druh `bonus`, JMHZ 10331). */
    public const CONTAINER_BONUS = 'ODMENA_KONTEJNERY';

    /** Odstupné: složka výchozího číselníku (druh `severance`, bez pojistného). */
    public const SEVERANCE = 'ODSTUPNE';

    /**
     * Náhrada výdajů, která není předmětem daně (§ 6 odst. 7 ZDP): PAMICA „Náhrada
     * nezdaněná" (J03, J09). Vyplácí se nad čistou mzdu mimo hrubou mzdu, mimo
     * vyměřovací základy i mimo úhrny JMHZ 10286 a 10289.
     */
    public const UNTAXED_REIMBURSEMENT = 'NAHRADA_VYDAJU_PREVZATA';

    /** Sloupec sešitu pro nezdaněnou náhradu výdajů; J03 i J09 se v měsíci sčítají. */
    private const UNTAXED_REIMBURSEMENT_HEADER = 'Náhrada výdajů nepodléhající dani (Kč)';

    /** Zákonné příplatky katalogu PAMICA => složka výchozího číselníku a sloupec sešitu. */
    private const STATUTORY_PREMIUMS = [
        'P01' => ['PRIPLATEK_PRESCAS', 'Příplatek za práci přesčas (Kč)'],
        'P03' => ['PRIPLATEK_SVATEK', 'Příplatek za práci ve svátek (Kč)'],
        'P04' => ['PRIPLATEK_VIKEND', 'Příplatek za práci v sobotu a v neděli (Kč)'],
    ];

    /**
     * Srážka za stravování; jediný druh dobrovolné srážky, který import docházky
     * odlišuje vlastním významem ({@see \MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning::DEDUCTIONS}).
     * Tentýž výraz nese položka `meal` v `PohodaPayrollDeductions::VOLUNTARY_KINDS`.
     */
    public const MEAL_DEDUCTION = '/obed|strav/';

    /**
     * Insolvence v číselníku srážek. Vlastní příznak pro ni `sMZsrazky` nemá (u části
     * druhů chybí i `JeZak`), takže jediné vodítko je číslo a název položky.
     */
    public const INSOLVENCY_DEDUCTION = '/insolven|oddluz/';

    /** Sloupec sešitu, do kterého se sčítá srážka za stravování (složka i srážka). */
    private const MEAL_HEADER = 'Obědy - srážka ze mzdy (Kč)';

    private const MEAL_ALLOWANCE_EXEMPT_HEADER = 'Příspěvek na stravování - osvobozená část (Kč)';
    private const MEAL_ALLOWANCE_TAXABLE_HEADER = 'Příspěvek na stravování - zdanitelná část (Kč)';

    /** Sloupec sešitu pro ostatní dobrovolné srážky; jeden na měsíc, hodnoty se sčítají. */
    private const OTHER_DEDUCTION_HEADER = 'Srážka ze mzdy (Kč)';

    /**
     * @param array<string,mixed> $catalog řádek číselníku `sMZslozky`; u položek, které
     *        rozlišuje jen daňový režim (nezdaněná náhrada), rozhodují jeho příznaky
     * @return array{meaning:string,kind:?string,code:?string,header:string}
     */
    public static function component(string $number, string $name, bool $sharedNumber, array $catalog = []): array
    {
        $number = strtoupper(trim($number));
        $label = trim("{$number} {$name}");
        $code = 'PAM_' . preg_replace('/[^A-Z0-9]/', '', $number) . ($sharedNumber ? '_' . self::slug($name, 20) : '');
        $normalized = AttendanceText::normalize($name);
        $component = static fn (string $kind): array => ['meaning' => 'component', 'kind' => $kind, 'code' => $code, 'header' => "{$label} (Kč)"];

        // Základní měsíční mzda: sešit ji nese ve sloupci „Měsíční mzda" (sazba
        // `Hodnota1`) a běh ji počítá ze sjednané mzdy vztahu, krácenou na odpracovanou
        // dobu. Jako složka by se vyplatila podruhé.
        if (in_array($number, ['M01', 'M09'], true)) {
            return ['meaning' => 'ignore', 'kind' => null, 'code' => null, 'header' => $label];
        }
        if ($number === 'C01') {
            return $component('hourly_wage');
        }
        if (in_array($number, ['C02', 'U01', 'U02', 'U03', 'U04'], true)) {
            return $component('task_wage');
        }
        // Mzda za odpracovaný přesčas, ne příplatek za něj: ten vede PAMICA zvlášť pod P01.
        // Druh složky proto nese tarifní mzdu, ze které plyne zařazení do JMHZ 10329.
        if (in_array($number, ['M02', 'C08'], true)) {
            return $component('hourly_wage');
        }
        if ($number === 'U05') {
            return $component('task_wage');
        }
        // Příplatek za noční práci (P07 i pojmenovaná O01) jde na standardní složku
        // PRIPLATEK_NOCNI (JMHZ 10334), stejně jako u vzoru GIRITON. Jeden sloupec, aby
        // se oba zdroje v měsíci sečetly.
        if ($number === 'P07' || (str_starts_with($number, 'O') && preg_match('/nocni|\bnoc\b/', $normalized) === 1)) {
            return ['meaning' => 'component', 'kind' => 'premium', 'code' => self::NIGHT_PREMIUM, 'header' => 'Příplatek za noční práci (Kč)'];
        }
        // Zákonné příplatky mají v hlášení vlastní kolonky (10333 přesčas, 10335 sobota
        // a neděle, 10336 svátek). Obecná `PAM_P*` by je sečetla jen do 10332.
        if (isset(self::STATUTORY_PREMIUMS[$number])) {
            [$premiumCode, $premiumHeader] = self::STATUTORY_PREMIUMS[$number];
            return ['meaning' => 'component', 'kind' => 'premium', 'code' => $premiumCode, 'header' => $premiumHeader];
        }
        if (str_starts_with($number, 'P')) {
            return $component('premium');
        }
        if (str_starts_with($number, 'O')) {
            // Plnění, která už v číselníku svou složku mají, jdou na ni: jedna složka
            // pro jedno plnění, a se zařazením do JMHZ rovnou od založení.
            if (preg_match('/obed|strav/', $normalized) === 1) {
                return ['meaning' => 'component', 'kind' => 'other', 'code' => self::TAXABLE_MEAL, 'header' => 'Zdanitelná část stravování (Kč)'];
            }
            if (str_contains($normalized, 'kontejner')) {
                return ['meaning' => 'component', 'kind' => 'bonus', 'code' => self::CONTAINER_BONUS, 'header' => 'Odměna za kontejnery (Kč)'];
            }
            // Doplatek, dorovnání i placená doba školení jsou mzda za práci (JMHZ 10329),
            // ne odměna ani příplatek. Příspěvek sem nepatří: z názvu nejde poznat, jestli
            // je to mzdové plnění, nebo benefit, a zařazení zůstává na účetní.
            if (preg_match('/doplatek|dorovnani|skoleni/', $normalized) === 1) {
                return $component('hourly_wage');
            }
            if (preg_match('/odmen|bonus|premi/', $normalized) === 1) {
                return $component('bonus');
            }
            if (preg_match('/priplat|pripl|bozp/', $normalized) === 1) {
                return $component('premium');
            }
            return $component('other');
        }
        if ($number === 'J11') {
            return $component('compensation');
        }
        // „Proplacená / vrácená dovolená" zadaná částkou; vrácená (přečerpaná dovolená,
        // § 147 odst. 1 písm. e) ZP) je v PAMICA záporná a snižuje hrubou mzdu měsíce.
        // Vlastní standardní složka, na které běh zápornou částku přijme.
        if (in_array($number, ['J07', 'J10'], true)) {
            return ['meaning' => 'component', 'kind' => 'compensation', 'code' => VacationCompensationReturn::SETTLEMENT_CODE, 'header' => 'Proplacená / vrácená náhrada za dovolenou (Kč)'];
        }
        // Odstupné má v MyÚčtu vlastní složku (bez pojistného, JMHZ jako odstupné). Měsíc,
        // který počítá MyÚčto, ho bez ní nevyplatí; převzatý měsíc ho má v úhrnech PAMICA.
        if ($number === 'D06' || str_contains($normalized, 'odstupn')) {
            return ['meaning' => 'component', 'kind' => 'other', 'code' => self::SEVERANCE, 'header' => 'Odstupné (Kč)'];
        }
        if ($number === 'J03' && str_contains($normalized, 'obed')) {
            return ['meaning' => 'meal', 'kind' => null, 'code' => null, 'header' => self::MEAL_HEADER];
        }
        if (self::untaxedReimbursement($normalized, $catalog)) {
            return ['meaning' => 'component', 'kind' => 'other', 'code' => self::UNTAXED_REIMBURSEMENT, 'header' => self::UNTAXED_REIMBURSEMENT_HEADER];
        }
        // Stravenkový paušál: osvobozenou část a nadlimitní část (`Hodnota4`) rozdělí
        // sešit ({@see self::mealAllowanceSplit()}). Druh v profilu jen pro případ, že
        // by složka výchozího číselníku ve firmě chyběla; zakládá ji číselník sám.
        if ($number === 'Z21') {
            return ['meaning' => 'meal_allowance', 'kind' => 'other', 'code' => self::MEAL_ALLOWANCE_EXEMPT, 'header' => self::MEAL_ALLOWANCE_EXEMPT_HEADER];
        }
        if ($number === 'Z21A') {
            return ['meaning' => 'component', 'kind' => 'other', 'code' => self::MEAL_ALLOWANCE_TAXABLE, 'header' => self::MEAL_ALLOWANCE_TAXABLE_HEADER];
        }
        return ['meaning' => 'unknown', 'kind' => null, 'code' => null, 'header' => $label];
    }

    /**
     * Stravenkový paušál z položky mzdy: osvobozená část a nadlimitní část. PAMICA
     * vede v `Hodnota4` („z toho nad limit") část paušálu nad limitem za odpracované
     * směny (`Hodnota3`); jen ona počet směn s nárokem zná.
     *
     * @return array{exempt:array{header:string,code:string,amount:float}, taxable:array{header:string,code:string,amount:float}}
     */
    public static function mealAllowanceSplit(float $amount, float $overLimit): array
    {
        $taxable = $amount > 0 ? min($amount, max(0.0, $overLimit)) : 0.0;

        return [
            'exempt' => ['header' => self::MEAL_ALLOWANCE_EXEMPT_HEADER, 'code' => self::MEAL_ALLOWANCE_EXEMPT, 'amount' => $amount - $taxable],
            'taxable' => ['header' => self::MEAL_ALLOWANCE_TAXABLE_HEADER, 'code' => self::MEAL_ALLOWANCE_TAXABLE, 'amount' => $taxable],
        ];
    }

    /** @return array{meaning:string,header:string} */
    public static function absence(string $number, string $name): array
    {
        $number = strtoupper(trim($number));
        $normalized = AttendanceText::normalize($name);

        return match (true) {
            $number === 'V01' => ['meaning' => 'vacation_hours', 'header' => 'Dovolená (h)'],
            $number === 'V02' => ['meaning' => 'holiday_hours', 'header' => 'Svátek (h)'],
            $number === 'V03' && str_contains($normalized, 'lekar') => ['meaning' => 'doctor_hours', 'header' => 'Lékař (h)'],
            $number === 'V03' => ['meaning' => 'obstacle_employee_hours', 'header' => 'Placené volno (h)'],
            // „Sick days": placené volno sjednané nad rámec zákona. PAMICA platí náhradu
            // z průměru se sazbou `Hodnota9` (výchozí 100 %), tedy stejně jako za V03;
            // stejný sloupec, aby se hodiny sečetly a převod za ně dopočítal náhradu.
            str_starts_with($number, 'V18') => ['meaning' => 'obstacle_employee_hours', 'header' => 'Placené volno (h)'],
            $number === 'V04' => ['meaning' => 'unpaid_leave_hours', 'header' => 'Neplacené volno (h)'],
            $number === 'V05' => ['meaning' => 'unexcused_hours', 'header' => 'Neomluvená absence (h)'],
            str_starts_with($number, 'V06') => ['meaning' => 'obstacle_employer_hours', 'header' => 'Překážka na straně zaměstnavatele (h)'],
            in_array($number, ['H01', 'H02', 'H03', 'H04'], true) => ['meaning' => 'sick_hours', 'header' => 'Nemoc (h)'],
            in_array($number, ['H05', 'H06'], true) => ['meaning' => 'care_hours', 'header' => 'OČR (h)'],
            $number === 'H15' => ['meaning' => 'paternity_hours', 'header' => 'Otcovská (h)'],
            // Mateřská, rodičovská, dlouhodobé ošetřovné a další druhy evidence převod
            // zapisuje s daty ({@see PohodaPayrollPeople::absenceType()}); co nezná ani
            // tam, nebo co data nemá, vrací měsíc k varování.
            default => ['meaning' => 'unknown', 'header' => trim("Nepřítomnost {$number}")],
        };
    }

    /**
     * Nese nepřítomnost hodiny, které evidence umí vést jedině s daty od a do?
     *
     * **Hranice mezi oběma cestami převodu nepřítomností a žije jen tady.** Nemoc,
     * ošetřovné, otcovská, neplacené volno, náhradní volno a neomluvená absence
     * rozhodují o náhradě mzdy, vyloučené době i době pojištění, a z holého měsíčního
     * součtu hodin se nic z toho odvodit nedá: schválení měsíce takový souhrn odmítne
     * s `absence_hours_without_dates` ({@see \MyInvoice\Service\Payroll\Time\PayrollTimeImportApprovalService}).
     * Do měsíčního sešitu ({@see PohodaPayrollConverter::month()}) proto takové hodiny
     * nejdou a tatáž doba se zapíše datovaně z `MZneprit`
     * ({@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter::absences()}). Dovolená a překážky zůstávají
     * v souhrnu: ty schválení s daty nevyžaduje.
     */
    public static function absenceNeedsDates(string $number, string $name): bool
    {
        return in_array(
            self::absence($number, $name)['meaning'],
            PayrollJmhzWorkMonthSummaryBuilder::IMPORT_HOURS_REQUIRING_DATES,
            true,
        );
    }

    /**
     * Zákonná srážka podle číselníku `sMZsrazky`: exekuce, insolvence i deponovaná
     * částka zákonné srážky (ta není vlastní titul, ale stav téže srážky).
     *
     * **Tohle je hranice mezi oběma cestami převodu srážek a žije jen tady.** Měsíční
     * sešit ({@see PohodaPayrollConverter}) zákonnou srážku nést nesmí: exekuční případ
     * z ní dělá samostatný krok ({@see PohodaPayrollDeductions},
     * {@see PohodaPayrollDeductionsWriter}) a druhý zápis by ji z čisté mzdy strhl
     * podruhé. Klasifikace exekučního kroku proto rozhoduje touž metodou.
     *
     * @param array<string,mixed> $catalog řádek číselníku `sMZsrazky`
     */
    public static function statutoryDeduction(array $catalog): bool
    {
        return self::bool(PohodaXml::text($catalog, 'JeZak'))
            || self::bool(PohodaXml::text($catalog, 'JeDepon'))
            || self::insolvencyDeduction($catalog);
    }

    /**
     * Insolvence mezi zákonnými srážkami. Číselník ji od exekuce neodlišuje, rozhoduje
     * název; oddělená metoda proto, že exekuční krok potřebuje i tenhle mezistupeň.
     *
     * @param array<string,mixed> $catalog řádek číselníku `sMZsrazky`
     */
    public static function insolvencyDeduction(array $catalog): bool
    {
        $text = AttendanceText::normalize(PohodaXml::text($catalog, 'Cislo') . ' ' . PohodaXml::text($catalog, 'Nazev'));

        return preg_match(self::INSOLVENCY_DEDUCTION, $text) === 1;
    }

    /**
     * Význam srážky pro měsíční sešit převodu.
     *
     * - `ignore` = zákonná srážka, tu přebírá krok exekučních případů,
     * - `unclassified` = řádek `MZsrazky` bez druhu v číselníku; zařadit ho nejde a do
     *   sešitu nesmí (mohla by to být exekuce), takže ho převod vypíše účetní,
     * - `net_meal_deduction` / `net_other_deduction` = dobrovolná srážka. Víc významů
     *   pro srážky import docházky nezná a hodnoty téhož významu nesčítá, takže každý
     *   z nich má v sešitu právě jeden sloupec, do kterého se srážky sčítají.
     *   `net_other_deduction` (např. S07 „Srážka zadaná částkou") import zakládá
     *   s druhem `imported`, ne jako dohodu podle OZ: ta za ní doložená není
     *   a PAMICA ji do JMHZ 10116 nehlásí ({@see \MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService}).
     *
     * @param array<string,mixed>|string $catalog řádek číselníku `sMZsrazky`; samotné
     *        číslo složky je zkratka pro případ, kdy o zákonnosti rozhodl volající už
     *        z téhož číselníku ({@see PohodaPayrollDeductions})
     * @return array{meaning:string,header:string,code:string,name:string}
     */
    public static function deduction(array|string $catalog): array
    {
        if (is_string($catalog)) {
            $catalog = ['Cislo' => $catalog];
        }
        $code = strtoupper(PohodaXml::text($catalog, 'Cislo'));
        $name = PohodaXml::text($catalog, 'Nazev');
        $result = static fn (string $meaning, string $header): array
            => ['meaning' => $meaning, 'header' => $header, 'code' => $code, 'name' => $name];

        if ($catalog === []) {
            return $result('unclassified', trim("Srážka {$code}"));
        }
        if (self::statutoryDeduction($catalog)) {
            return $result('ignore', trim("Zákonná srážka {$code}"));
        }
        if (preg_match(self::MEAL_DEDUCTION, AttendanceText::normalize("{$code} {$name}")) === 1) {
            return $result('net_meal_deduction', self::MEAL_HEADER);
        }

        return $result('net_other_deduction', self::OTHER_DEDUCTION_HEADER);
    }

    /** @return array{meaning:string,header:string}|null hodiny ze složek, které MyÚčto vede jako druh práce */
    public static function workHours(string $number): ?array
    {
        return match (strtoupper(trim($number))) {
            'P01', 'P13' => ['meaning' => 'overtime_hours', 'header' => 'Přesčas (h)'],
            'P07' => ['meaning' => 'night_hours', 'header' => 'Noční práce (h)'],
            'P04' => ['meaning' => 'weekend_hours', 'header' => 'Práce o víkendu (h)'],
            'P03' => ['meaning' => 'holiday_work_hours', 'header' => 'Práce ve svátek (h)'],
            default => null,
        };
    }

    /**
     * Nezdaněná náhrada výdajů: název „náhrada … nezdaněná" a v číselníku bez daně
     * (`RelTpDan` 4), bez sociálního i zdravotního pojistného. Číslo složky nerozhoduje:
     * J03 je v jiné instalaci srážka za obědy. Bez řádku číselníku, nebo když příznaky
     * názvu neodpovídají, zůstává položka neznámá a převod ji vypíše.
     *
     * @param array<string,mixed> $catalog
     */
    private static function untaxedReimbursement(string $normalized, array $catalog): bool
    {
        if ($catalog === [] || preg_match('/\bnahrad.*\bnezdan/', $normalized) !== 1) {
            return false;
        }

        return trim(PohodaXml::text($catalog, 'RelTpDan')) === '4'
            && trim(PohodaXml::text($catalog, 'JeSoc')) === '0'
            && trim(PohodaXml::text($catalog, 'JeZdr')) === '0';
    }

    private static function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', '-1', 'true'], true);
    }

    private static function slug(string $text, int $max): string
    {
        $ascii = strtoupper(AttendanceText::normalize($text));
        return substr(trim((string) preg_replace('/[^A-Z0-9]+/', '_', $ascii), '_'), 0, $max);
    }
}
