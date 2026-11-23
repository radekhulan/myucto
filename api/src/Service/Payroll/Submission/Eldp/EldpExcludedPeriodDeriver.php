<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

/**
 * Vyloučené a odečítané doby evidenčního listu.
 *
 * Tohle je nejcitlivější místo celého ELDP: vyloučené doby zvedají osobní
 * vyměřovací základ tím, že se odečtou ze jmenovatele, takže chyba tady
 * změní důchod. Proto se tu nic nedopočítává „rozumným odhadem“ — modul
 * odvodí jen to, co má bezpečně doložené v zmrazeném snapshotu, a všechno
 * ostatní vrátí jako blokátor.
 *
 * ## Co se odvozuje (§ 16 odst. 4 písm. a) zákona č. 155/1995 Sb.)
 *
 * | druh absence          | atribut ELDP                        |
 * |-----------------------|-------------------------------------|
 * | `dpn`, `quarantine`   | 10358 dočasná pracovní neschopnost  |
 * | `ocr`,`long_term_care`| 10360 ošetřovné / dlouhodobé ošetř. |
 * | `ppm` (jen před porodem) | 10359 peněžitá pomoc v mateřství |
 * | `paternity`           | 10362 otcovská                      |
 * | `invalid_termination` | 10536 § 16 odst. 4 písm. j)         |
 *
 * `invalid_termination` není omluvný důvod podle písm. a), ale doba, po kterou
 * podle pravomocného rozhodnutí soudu nebo mimosoudní dohody uzavřené po podání
 * žaloby trval vztah zakládající účast na pojištění po jeho neplatném skončení,
 * aniž byla přiznána náhrada mzdy (§ 16 odst. 4 písm. j) zákona č. 155/1995 Sb.,
 * datový slovník JMHZ 1.4.1.6 u 10536). Vztah po tu dobu trval a účast by bez
 * neplatného skončení vznikla, proto měsíc zůstává dobou pojištění stejně jako
 * u omluvného důvodu.
 *
 * Peněžitá pomoc v mateřství je vyloučenou dobou jen PŘED porodem: „doby před
 * porodem, po kterou nebyla vykonávána výdělečná činnost z důvodu
 * těhotenství, nejdříve však od začátku osmého týdne před očekávaným dnem
 * porodu do dne, který bezprostředně předcházel dni porodu" (§ 16 odst. 4
 * věta třetí písm. a) zákona č. 155/1995 Sb.). Počítá se proto jen průnik
 * s intervalem viz {@see ppmPreBirthWindow()}; den porodu ani doba po něm
 * vyloučenou dobou nejsou.
 *
 * Součet 10357 = 10358 + 10359 + 10360 + 10362 + 10536 podle *Pravidel podání
 * JMHZ a souvisejících procesů* verze 1.4.4, kapitola 4 (ELDP).
 *
 * Otcovská je vyloučenou dobou v celé podpůrčí době — § 16 odst. 4 věta třetí
 * písm. a) zákona č. 155/1995 Sb. jmenuje „dobu, po kterou trvala podpůrčí doba
 * u dávky otcovské poporodní péče" bez dalšího omezení (od 1. 1. 2022 dva týdny,
 * § 38b zákona č. 187/2006 Sb.). Za ty dny mzda nenáleží, takže nevzniká krytí
 * s příjmem, které návětí téhož ustanovení zapovídá.
 *
 * ## Co vyloučenou dobu netvoří a nechává řádek beze změny
 *
 * `vacation` (dovolená se proplácí a pojištění běží dál), `employer_obstacle`
 * a `employee_obstacle` (překážky v práci s náhradou mzdy, která je součástí
 * vyměřovacího základu), `compensatory_time_off` (náhradní volno za přesčas
 * podle § 114 odst. 1 zákoníku práce), `unpaid_leave`, `unexcused` a
 * `parental`. Podrobné odůvodnění u konstanty {@see NEUTRAL_TYPES}.
 *
 * Výčet § 16 odst. 4 věty třetí písm. a) je uzavřený, takže „netvoří vyloučenou
 * dobu" je u nich závěr ze zákona, ne úsudek z výčtu atributů. Zbývá jediný
 * úsudkový případ: `compensatory_time_off` — na náhradní volno nesedí ani
 * jeden atribut, který ELDP zná, a 10536 (§ 16 odst. 4 písm. j)) je o době
 * trvání pracovního vztahu po neplatném skončení.
 *
 * ## Co modul NEUMÍ a co proto musí ošetřit volající
 *
 * Doba pojištění se u nepřítomnosti bez příjmu nekrátí po dnech, ale po CELÝCH
 * MĚSÍCÍCH podle § 11 odst. 2 zákona č. 155/1995 Sb. („za dobu pojištění se
 * nepovažuje kalendářní měsíc, ve kterém nebyly dosaženy příjmy … pokud nešlo
 * o omluvné důvody"), v ELDP znakem „X". Tenhle modul počítá jen vyloučené
 * doby a o příjmech nic neví — měsíc bez započitatelného příjmu musí rozpoznat
 * a odmítnout ten, kdo modul volá.
 *
 * ## Co je fail-closed a proč
 *
 * - `ppm` bez očekávaného dne porodu, nebo s nevyplněným dnem porodu
 *   v intervalu, který sahá na očekávaný den porodu nebo za něj, viz
 *   {@see ppmPreBirthWindow()}.
 * - `other` — nerozlišený druh.
 *
 * Ošetřovné je vyloučenou dobou jen v rozsahu podpůrčí doby: pokyny MPSV
 * k 10360 „nejvýše však v rozsahu prvních 9 kalendářních dnů potřeby
 * ošetřování …, popřípadě prvních 16 kalendářních dnů, jde-li o osamělého
 * zaměstnance" (§ 40 odst. 1 zákona č. 187/2006 Sb.), u dlouhodobého
 * ošetřovného nejdéle 90 dnů (§ 41e odst. 1). Viz {@see careSupportEnd()}.
 *
 * ## Krytí vyloučené doby příjmem (Příloha č. 3 Všeobecných zásad ELDP)
 *
 * § 16 odst. 4 věta třetí zákona č. 155/1995 Sb. bere za vyloučenou dobu jen
 * dobu, která se nekryje s dobou pojištění s příjmy zahrnovanými do
 * vyměřovacího základu. Metodická pomůcka ČSSZ k ELDP to rozvádí příklady,
 * které {@see derive()} plní doslova:
 *
 * - a) omluvný důvod trvá celý měsíc (celý interval vztahu v měsíci) a v měsíci
 *   je zúčtován příjem: dny se do vyloučených dob nezahrnou (př. 19, 23, květen),
 * - b) omluvný důvod netrvá celý měsíc: ke krytí nedochází a vykážou se dny
 *   jeho trvání (př. 20, 22),
 * - c) nemoc začala dnem, ve kterém zaměstnanec odpracoval část směny
 *   (`partial_first_minutes`): vyloučená doba až od následujícího dne (př. 23),
 * - e) souběžný vztah u téhož zaměstnavatele s příjmem v měsíci kryje omluvné
 *   dny vztahu, ve kterých souběžný vztah sám omluvný důvod neměl (př. 39, 40).
 *   Které dny to jsou, určuje volající ({@see derive()} parametr
 *   `$concurrentIncomeDays`), protože zná ostatní vztahy osoby.
 *
 * Bod d) (dorovnání nemocenské zaměstnavatelem nad zákonný nárok) modul nemá
 * jak poznat: aplikace takovou složku mzdy nevede, náhrada mzdy podle § 192 ZP
 * do vyměřovacího základu nevstupuje a dobu nekryje.
 *
 * ## Odečtené doby (údaj 40 ELDP, JMHZ 10375 a 10462–10469)
 *
 * Týkají se jen sekcí s kódem „D" (výdělečná činnost po dovršení důchodového
 * věku bez plného starobního důchodu). Odvozuje je {@see deriveDeducted()} ze
 * stejných nepřítomností: omluvné důvody podle § 16 odst. 4 písm. a) (po krytí
 * příjmem), ošetřování za podpůrčí dobou, neplacené volno, neomluvená absence
 * a všechny dny měsíce bez účasti („X"). Všeobecné zásady ELDP, údaj Doby
 * odečtené; Pokyny MPSV k vyplnění MH, ID 10375 až 10469.
 *
 * ## 10536 jen z výslovného vstupu
 *
 * **10536** (§ 16 odst. 4 písm. j)) vzniká jedině z nepřítomnosti
 * `invalid_termination`, kterou účetní zapíše podle rozhodnutí soudu. Nic jiného
 * se do něj neodvozuje.
 */
final class EldpExcludedPeriodDeriver
{
    /** Druh absence → atribut vyloučené doby podle § 16 odst. 4 písm. a). */
    private const EXCLUDED_ATTRIBUTES = [
        'dpn' => 'docasNeschopnost',
        'quarantine' => 'docasNeschopnost',
        'ocr' => 'osetrovaniClenaRodiny',
        'long_term_care' => 'osetrovaniClenaRodiny',
        // Jen předporodní část, interval zužuje ppmPreBirthWindow().
        'ppm' => 'penezitaPomocMaterstvi',
        'paternity' => 'otcovska',
        // § 16 odst. 4 písm. j): vztah trvající po neplatném skončení bez
        // přiznané náhrady mzdy, viz docblock třídy.
        'invalid_termination' => 'vyloucenePar16',
    ];

    /**
     * Začátek osmého týdne před očekávaným dnem porodu: sedm celých týdnů
     * a den, kterým osmý týden začíná, tedy 56 dnů před očekávaným dnem.
     * Tutéž hranici pro nástup na PPM stanoví § 32 odst. 1 písm. a) a § 34
     * odst. 1 písm. a) zákona č. 187/2006 Sb.
     */
    public const PPM_PRE_BIRTH_DAYS = 56;

    /**
     * Podpůrčí doba ošetřovného: § 40 odst. 1 písm. a) zákona č. 187/2006 Sb.
     * 9 kalendářních dnů, písm. b) 16 dnů u osamělého zaměstnance s dítětem
     * do 16 let, které neukončilo povinnou školní docházku (příznak
     * `lone_carer` u nepřítomnosti). Dlouhodobé ošetřovné nejdéle 90 dnů
     * (§ 41e odst. 1). Zákonné lhůty jako {@see PPM_PRE_BIRTH_DAYS}: modul je
     * čistý a ruleset nemá, stejná čísla vede i ruleset nemocenského pojištění.
     */
    public const CARE_SUPPORT_DAYS = 9;
    public const CARE_SUPPORT_DAYS_LONE_CARER = 16;
    public const LONG_TERM_CARE_SUPPORT_DAYS = 90;

    /**
     * Nepřítomnosti BEZ započitatelného příjmu, u kterých měsíc bez příjmu
     * podle § 11 odst. 2 zákona č. 155/1995 Sb. není dobou pojištění.
     *
     * @var list<string>
     */
    public const INCOME_LESS_TYPES = [...self::UNPAID_EXCUSED_TYPES, 'unexcused', 'parental'];

    /**
     * Omluvené nepřítomnosti, za které náhrada příjmu nenáleží: neplacené
     * volno a pracovní volno bez náhrady mzdy (výkon veřejné funkce podle
     * § 200 až 202 ZP, neplacená překážka na straně zaměstnance). Jediné
     * místo, kde se tahle skupina vyjmenovává — ELDP, doba pojištění i
     * vyloučené dny § 18 odst. 7 z ní čtou stejně.
     *
     * @var list<string>
     */
    public const UNPAID_EXCUSED_TYPES = ['unpaid_leave', 'public_function', 'employee_obstacle_unpaid'];

    public const MONTH_INSURED = 'insured';
    public const MONTH_OUTSIDE_INSURANCE = 'outside_insurance';
    public const MONTH_UNEXPLAINED = 'unexplained';

    /**
     * Je měsíc účastného vztahu dobou pojištění? Jediné místo rozhodnutí
     * pro měsíční hlášení i roční evidenční list.
     *
     * § 11 odst. 2 zákona č. 155/1995 Sb.: za dobu pojištění se nepovažuje
     * kalendářní měsíc, ve kterém nebyly dosaženy příjmy započitatelné do
     * vyměřovacího základu, nešlo-li o omluvné důvody podle § 16 odst. 4
     * věty třetí písm. a). Měsíc s příjmem je dobou pojištění vždy; měsíc bez
     * příjmu tehdy, když v něm nastal ALESPOŇ JEDEN omluvný důvod (nemoc,
     * ošetřovné, doba před porodem, otcovská). Výhradně nepřítomnosti bez
     * příjmu = mimo dobu pojištění. Nulový příjem bez jakékoli nepřítomnosti
     * zákon z podkladů nerozhodne a volající musí zastavit.
     *
     * Souběh omluvného důvodu s neplaceným volnem (nebo jinou nepřítomností
     * bez příjmu) v měsíci bez příjmu je měsícem POJIŠTĚNÝM: Metodická pomůcka
     * ČSSZ pro vyplňování ELDP, příklad 5 (neplacené volno celý měsíc a od
     * 7. dne pracovní neschopnost) — „měsíc je měsícem pojištěným", vyloučenou
     * dobou je jen pracovní neschopnost. Pomůcka to vylučuje u zaměstnání
     * malého rozsahu a DPP; ty ale bez příjmu v měsíci na pojištění účast
     * nemají, takže sem vůbec nedojdou (volající rozhoduje jen o účastném
     * vztahu). Dřív takový měsíc zastavil celé hlášení i roční ELDP.
     *
     * Peněžitá pomoc v mateřství je omluvný důvod jen do dne předcházejícího
     * porodu (tentýž odkaz § 11 odst. 2 na § 16 odst. 4 písm. a)); den porodu
     * a doba po něm jsou nepřítomnost bez příjmu. Proto se rozhoduje nad
     * intervalem měsíce `[$intervalFrom, $intervalTo]` týmž výpočtem, který
     * odvozuje atribut 10359 ({@see ppmPreBirthWindow()}). Měsíc celý po porodu
     * je mimo dobu pojištění. Měsíc, ve kterém se porod stal, omluvné dny má,
     * a proto dobou pojištění zůstává celý: tak ho vykázal jiný mzdový systém
     * a ČSSZ přijala řádné i opravné hlášení (10356 = celý měsíc, 10357 =
     * 10359 = dny před porodem). Totéž platí, když k porodu přibude v měsíci
     * neplacené volno.
     *
     * PPM, u které se předporodní část určit nedá (chybí očekávaný den porodu
     * nebo nevyplněný den porodu), se tu počítá jako omluvná: odvození
     * vyloučených dob ji pak zastaví blokátorem, který účetní řekne, co doplnit.
     *
     * @param list<array<string,mixed>> $absences
     */
    public static function insuranceMonthStatus(
        array $absences,
        int $uncappedBaseMinor,
        string $intervalFrom,
        string $intervalTo,
    ): string {
        if ($uncappedBaseMinor > 0) {
            return self::MONTH_INSURED;
        }
        $incomeLess = false;
        $excused = false;
        $other = false;
        foreach ($absences as $absence) {
            $type = is_array($absence) ? ($absence['absence_type'] ?? null) : null;
            if ($type === 'ppm') {
                [$preBirth, $postBirth] = self::ppmExcusedSplit($absence, $intervalFrom, $intervalTo);
                $excused = $excused || $preBirth;
                $incomeLess = $incomeLess || ($postBirth && !$preBirth);
                continue;
            }
            if (array_key_exists((string) $type, self::EXCLUDED_ATTRIBUTES)) {
                $excused = true;
            } elseif (in_array($type, self::INCOME_LESS_TYPES, true)) {
                $incomeLess = true;
            } else {
                $other = true;
            }
        }

        /*
         * `$other` jsou nepřítomnosti, které omluvným důvodem podle § 16
         * odst. 4 písm. a) nejsou, ale bez příjmu také nejsou nebo jejich
         * zařazení zákon neřeší (dovolená, překážky s náhradou, náhradní volno).
         * Samy dobu pojištění nemění; vedle nepřítomnosti bez příjmu v měsíci
         * s nulovým příjmem si ale odporují a měsíc zůstává nevysvětlený.
         */
        return match (true) {
            $excused => self::MONTH_INSURED,
            $incomeLess && $other => self::MONTH_UNEXPLAINED,
            $incomeLess => self::MONTH_OUTSIDE_INSURANCE,
            $other => self::MONTH_INSURED,
            default => self::MONTH_UNEXPLAINED,
        };
    }

    /**
     * Druhy absence, které dobu pojištění ani vyloučenou dobu nemění.
     *
     * `compensatory_time_off` je tu proto, že pojistný vztah po dobu čerpání
     * náhradního volna trvá dál a žádný atribut vyloučené doby, který ELDP zná,
     * na něj nesedí — 10536 je podle § 16 odst. 4 písm. j) zákona č. 155/1995 Sb.
     * o době trvání pracovního vztahu po neplatném skončení, ne o volnu za
     * přesčas. Mez důkazu: úplný výčet § 16 odst. 4 v repozitáři doložený není.
     */
    private const NEUTRAL_TYPES = [
        'vacation',
        'employer_obstacle',
        'compensatory_time_off',
        'holiday_compensatory_time_off',
        /*
         * Neplacené volno, neomluvená absence a rodičovská dovolená.
         *
         * Žádná z nich není vyloučenou dobou: výčet § 16 odst. 4 věty třetí
         * písm. a) zákona č. 155/1995 Sb. je UZAVŘENÝ (nemoc, karanténa,
         * ošetřovné, dlouhodobé ošetřovné, otcovská, doba před porodem) a ani
         * jedna z těchhle tří v něm není. Do ELDP se přitom vykazují jen doby
         * podle písm. a) — Všeobecné zásady ČSSZ pro vyplňování ELDP:
         * „Vyloučené doby: uvádí se doba trvání omluvných důvodů uvedených
         * v § 16 odst. 4 písm. a) zákona č. 155/1995 Sb."
         *
         * Ani jedna z nich zároveň nepřerušuje pojištění. Jediné přerušení
         * účasti uvnitř trvajícího zaměstnání zná § 10 odst. 9 zákona
         * č. 187/2006 Sb. a týká se výkonu trestu a zabezpečovací detence.
         *
         * Rodičovská JE náhradní dobou pojištění (osobní péče o dítě do 4 let,
         * § 5 odst. 2 písm. c) a § 12 odst. 1 zákona č. 155/1995 Sb.) a je
         * i vyloučenou dobou — ale podle § 16 odst. 4 věty třetí písm. **e)**,
         * ne písm. a). Tu ČSSZ nedostává od zaměstnavatele; pojištěnec ji
         * dokládá čestným prohlášením až v důchodovém řízení. Zaměstnavatel
         * ji na ELDP nevykazuje vůbec.
         *
         * Doba pojištění se u všech tří krátí jinou cestou — celým měsícem
         * podle § 11 odst. 2 zákona č. 155/1995 Sb. (znak „X"), když v měsíci
         * nebyl zúčtován započitatelný příjem. Ten případ řeší volající;
         * do vyloučených dob nepatří ani tehdy.
         */
        ...self::UNPAID_EXCUSED_TYPES,
        'unexcused',
        'parental',
        /*
         * Překážka na straně zaměstnance je v aplikaci vždy PLACENÁ (validátor
         * absence jí vynucuje schválený snapshot průměru a sazbu 100 %).
         * Náhrada mzdy je součástí vyměřovacího základu, takže by se vyloučená
         * doba kryla s příjmem — a § 16 odst. 4 věta třetí návětí zákona
         * č. 155/1995 Sb. vyloučenou dobu při krytí s příjmem zapovídá.
         * Neplacená varianta má vlastní druh `employee_obstacle_unpaid`
         * a patří do {@see self::UNPAID_EXCUSED_TYPES}.
         */
        'employee_obstacle',
    ];

    /** Druhy absence, u kterých modul nemá doložený způsob výpočtu. */
    private const UNSUPPORTED_TYPES = [
        'other' => 'nerozlišený druh absence',
    ];

    public const COMPONENTS = [
        'docasNeschopnost',
        'penezitaPomocMaterstvi',
        'osetrovaniClenaRodiny',
        'otcovska',
        'vyloucenePar16',
    ];

    /**
     * Složky odečtených dob v pořadí `odecitaneDnyType` (ID 10462–10469; 10467
     * datový slovník nemá). Den měsíce bez účasti („X") se do 10375 počítá, ale
     * vlastní složku nemá (Pokyny MPSV k 10375).
     */
    public const DEDUCTED_COMPONENTS = [
        'pracovniNeschopnost',
        'materstvi',
        'osetrovaniSNarokem',
        'osetrovaniBezNaroku',
        'otcovska',
        'neplaceneVolno',
        'neomluveneAbsence',
    ];

    /** Vyloučená doba → složka odečtené doby téže nepřítomnosti. */
    private const DEDUCTED_FROM_EXCLUDED = [
        'docasNeschopnost' => 'pracovniNeschopnost',
        'penezitaPomocMaterstvi' => 'materstvi',
        'osetrovaniClenaRodiny' => 'osetrovaniSNarokem',
        'otcovska' => 'otcovska',
    ];

    public const COVERED_WHOLE_MONTH_INCOME = 'whole_month_income';
    public const COVERED_CONCURRENT_INCOME = 'concurrent_income';
    public const COVERED_PARTIAL_FIRST_DAY = 'partial_first_day';

    /**
     * Složky vyloučených dnů podle § 18 odst. 7 zákona č. 187/2006 Sb.
     *
     * Jiná veličina než {@see COMPONENTS}: ty jsou vyloučenými DOBAMI pro
     * důchodové pojištění (§ 16 odst. 4 zákona č. 155/1995 Sb.), tyhle jsou
     * vyloučenými DNY pro denní vyměřovací základ nemocenských dávek. Do
     * hlášení jdou vedle sebe a mohou se v týchž dnech překrývat — nemoc je
     * v obou.
     *
     * Datový slovník JMHZ 1.4.1.6 u atributu 10366 předepisuje součet
     * `10366 = 10473 + 10474 + 10475`, takže rozpad je úplný, nebo se nesmí
     * vykázat vůbec.
     *
     * @var list<string>
     */
    public const SECTION18_COMPONENTS = [
        'omluvenaNepritomnost',
        'pracovniNeschopnost',
        'vyplaceniDavek',
    ];

    /**
     * Druh nepřítomnosti → složka § 18 odst. 7, do které se jeho dny počítají.
     *
     * Neplacené volno je omluvená nepřítomnost, za kterou nenáleží náhrada
     * příjmu (10473, „neplacené volno, stávka" v datovém slovníku). Stávku
     * aplikace jako druh nepřítomnosti nezná.
     *
     * Náhradní volno za přesčas je týž případ: § 18 odst. 7 písm. a) mluví
     * o „kalendářních dnech omluvené nepřítomnosti …, za které zaměstnanci
     * nenáleží náhrada příjmu", a za dobu čerpání náhradního volna mzda ani
     * náhrada nepřísluší (§ 114 odst. 1 zákoníku práce; aplikace ho proto
     * mzdově vede jako neplacené).
     *
     * Rodičovskou dovolenou jmenují Pokyny MPSV k vyplnění MH 1.4.13 u 10473
     * výslovně („[např. rodičovská dovolená (doba péče o dítě do 4 let
     * věku)]"). Že je zároveň náhradní dobou důchodového pojištění podle
     * § 16 odst. 4 písm. e) zákona č. 155/1995 Sb., na tom nic nemění: to je
     * vyloučená DOBA pro důchod, tady jde o vyloučené DNY pro nemocenské.
     */
    private const SECTION18_ATTRIBUTES = [
        'unpaid_leave' => 'omluvenaNepritomnost',
        'public_function' => 'omluvenaNepritomnost',
        'employee_obstacle_unpaid' => 'omluvenaNepritomnost',
        'compensatory_time_off' => 'omluvenaNepritomnost',
        'parental' => 'omluvenaNepritomnost',
    ];

    /**
     * Dávky, které ČSSZ vyplácí po celou nepřítomnost: peněžitá pomoc
     * v mateřství (před porodem i po něm) a otcovská. Celé dny → 10475
     * („dny, za které bylo zaměstnanci vypláceno … peněžitá pomoc
     * v mateřství, otcovská").
     *
     * @var list<string>
     */
    private const SECTION18_BENEFIT_TYPES = ['ppm', 'paternity'];

    /** Ošetřovné a dlouhodobé ošetřovné: dávka jen v rozsahu podpůrčí doby. */
    private const CARE_TYPES = ['ocr', 'long_term_care'];

    /** Nemoc a karanténa: okno náhrady mzdy (§ 192 ZP) a za ním nemocenské. */
    private const SICKNESS_TYPES = ['dpn', 'quarantine'];

    /**
     * Druhy nepřítomnosti, které vyloučený den podle § 18 odst. 7 netvoří.
     *
     * - `vacation`, `employer_obstacle`, `employee_obstacle` — náhrada příjmu
     *   (nebo nekrácená mzda) náleží, takže o vyloučený den nejde už
     *   z návětí § 18 odst. 7.
     * - `unexcused` — neomluvená absence není OMLUVENÁ nepřítomnost.
     *
     * @var list<string>
     */
    private const SECTION18_NEUTRAL_TYPES = [
        'vacation',
        'holiday_compensatory_time_off',
        'employer_obstacle',
        'employee_obstacle',
        'unexcused',
    ];

    /**
     * Vyloučené doby jednoho měsíce (intervalu vztahu v měsíci) včetně krytí
     * příjmem podle Přílohy č. 3 Všeobecných zásad ELDP.
     *
     * `$incomeMinor` je příjem započitatelný do vyměřovacího základu, zúčtovaný
     * vztahu v měsíci; `null` = volající ho nezná a krytí podle bodu a) se
     * nehodnotí (převzatý měsíc bez výpočtu). `$concurrentIncomeDays` jsou dny
     * intervalu, ve kterých souběžný vztah téže osoby u téhož zaměstnavatele
     * zúčtoval příjem a sám omluvný důvod neměl (bod e)).
     *
     * Dny, které krytí z vyloučených dob vyřadí, vrací `covered` s důvodem:
     * měsíční hlášení je potřebuje, aby hodiny nemoci v pracovním souhrnu
     * nepovažovalo za nedoložené.
     *
     * @param list<array<string,mixed>> $absences absence ze zmrazeného snapshotu
     * @param array<string,true> $concurrentIncomeDays
     * @return array{
     *   components:array<string,int>,
     *   total:int,
     *   provenance:list<array{
     *     absence_id:int,absence_type:string,attribute:string,
     *     absence_from:string,absence_to:string,
     *     counted_from:string,counted_to:string,days:int
     *   }>,
     *   covered:list<array{
     *     absence_id:int,absence_type:string,attribute:string,reason:string,
     *     counted_from:string,counted_to:string,days:int
     *   }>,
     *   blockers:list<array{code:string,message:string,detail:array<string,mixed>}>
     * }
     */
    public function derive(
        array $absences,
        string $intervalFrom,
        string $intervalTo,
        string $periodLabel,
        ?int $incomeMinor = null,
        array $concurrentIncomeDays = [],
    ): array {
        $components = array_fill_keys(self::COMPONENTS, 0);
        $provenance = [];
        $covered = [];
        $blockers = [];
        $claimedDays = [];
        /** @var array<string,true> $excusedDays dny s omluvným důvodem (vyloučené i kryté souběhem) */
        $excusedDays = [];

        foreach ($absences as $absence) {
            $absenceId = $absence['id'] ?? null;
            $type = $absence['absence_type'] ?? null;
            if (!is_int($absenceId) || $absenceId <= 0 || !is_string($type)) {
                $blockers[] = [
                    'code' => 'eldp_absence_source_invalid',
                    'message' => "Absence v období {$periodLabel} nemá použitelnou identifikaci ani druh.",
                    'detail' => ['period' => $periodLabel],
                ];
                continue;
            }
            $from = self::date($absence['date_from'] ?? null);
            $to = self::date($absence['date_to'] ?? null);
            if ($from === null || $to === null || $from > $to) {
                $blockers[] = [
                    'code' => 'eldp_absence_interval_invalid',
                    'message' => "Absence #{$absenceId} v období {$periodLabel} nemá platný interval.",
                    'detail' => ['absence_id' => $absenceId, 'period' => $periodLabel],
                ];
                continue;
            }
            $countedFrom = max($from, $intervalFrom);
            $countedTo = min($to, $intervalTo);
            if ($countedFrom > $countedTo) {
                continue;
            }
            if (in_array($type, self::NEUTRAL_TYPES, true)) {
                continue;
            }
            if (array_key_exists($type, self::UNSUPPORTED_TYPES)) {
                $blockers[] = [
                    'code' => 'eldp_absence_kind_unsupported',
                    'message' => "Absence #{$absenceId} ({$type}) v období {$periodLabel} nemá doložený "
                        . 'způsob zápisu do evidenčního listu — '
                        . self::UNSUPPORTED_TYPES[$type] . '.',
                    'detail' => [
                        'absence_id' => $absenceId,
                        'absence_type' => $type,
                        'period' => $periodLabel,
                    ],
                ];
                continue;
            }
            $attribute = self::EXCLUDED_ATTRIBUTES[$type] ?? null;
            if ($attribute === null) {
                $blockers[] = [
                    'code' => 'eldp_absence_kind_unknown',
                    'message' => "Absence #{$absenceId} má neznámý druh {$type} a evidenční list ji neumí zapsat.",
                    'detail' => [
                        'absence_id' => $absenceId,
                        'absence_type' => $type,
                        'period' => $periodLabel,
                    ],
                ];
                continue;
            }
            if ($type === 'ppm') {
                $preBirth = self::ppmPreBirthWindow($absence, $countedFrom, $countedTo);
                if ($preBirth['missing'] !== null) {
                    $blockers[] = self::ppmBlocker(
                        $preBirth['missing'],
                        $absenceId,
                        $periodLabel,
                        $absence['expected_childbirth_date'] ?? null,
                    );
                    continue;
                }
                if ($preBirth['window'] === null) {
                    // Celý průnik leží po porodu (nebo před osmým týdnem):
                    // vyloučenou dobou není ani den.
                    continue;
                }
                [$countedFrom, $countedTo] = $preBirth['window'];
            }
            if (in_array($type, self::CARE_TYPES, true)) {
                // Za podpůrčí dobou ošetřovné nenáleží a den vyloučenou dobou
                // podle pokynů k 10360 není.
                $countedTo = min($countedTo, self::careSupportEnd($absence, $from));
                if ($countedFrom > $countedTo) {
                    continue;
                }
            }
            $days = self::inclusiveDays($countedFrom, $countedTo);
            $overlap = self::claim($claimedDays, $countedFrom, $days);
            if ($overlap !== null) {
                $blockers[] = [
                    'code' => 'eldp_absence_overlap_unsupported',
                    'message' => "Absence #{$absenceId} se v období {$periodLabel} překrývá s jinou "
                        . "vyloučenou dobou ({$overlap}); souběh by se ve vyloučených dnech započítal dvakrát.",
                    'detail' => [
                        'absence_id' => $absenceId,
                        'overlapping_day' => $overlap,
                        'period' => $periodLabel,
                    ],
                ];
                continue;
            }
            /*
             * Bod c) Přílohy č. 3: nemoc ode dne, ve kterém zaměstnanec odpracoval
             * část směny (zameškané minuty prvního dne), je vyloučenou dobou až od
             * následujícího dne — za odpracovanou část náleží mzda.
             */
            if (in_array($type, self::SICKNESS_TYPES, true)
                && $countedFrom === $from
                && is_int($absence['partial_first_minutes'] ?? null)
                && $absence['partial_first_minutes'] > 0
            ) {
                $covered[] = self::coveredEntry($absenceId, $type, $attribute, self::COVERED_PARTIAL_FIRST_DAY, $countedFrom, $countedFrom);
                $countedFrom = self::nextDay($countedFrom);
                if ($countedFrom > $countedTo) {
                    continue;
                }
            }
            $excluded = [];
            $concurrent = [];
            for ($day = $countedFrom; $day <= $countedTo; $day = self::nextDay($day)) {
                $excusedDays[$day] = true;
                if (isset($concurrentIncomeDays[$day])) {
                    $concurrent[] = $day;
                } else {
                    $excluded[] = $day;
                }
            }
            foreach (self::runs($concurrent) as [$runFrom, $runTo]) {
                $covered[] = self::coveredEntry($absenceId, $type, $attribute, self::COVERED_CONCURRENT_INCOME, $runFrom, $runTo);
            }
            foreach (self::runs($excluded) as [$runFrom, $runTo]) {
                $runDays = self::inclusiveDays($runFrom, $runTo);
                $components[$attribute] += $runDays;
                $provenance[] = [
                    'absence_id' => $absenceId,
                    'absence_type' => $type,
                    'attribute' => $attribute,
                    'absence_from' => $from,
                    'absence_to' => $to,
                    'counted_from' => $runFrom,
                    'counted_to' => $runTo,
                    'days' => $runDays,
                ];
            }
        }

        /*
         * Bod a) Přílohy č. 3: omluvný důvod po celý měsíc (celý interval vztahu
         * v měsíci) a v měsíci zúčtovaný příjem — dny se do vyloučených dob
         * nezahrnou vůbec. Netrvá-li omluvný důvod celý měsíc, ke krytí
         * nedochází (bod b), př. 20 a 22 Metodické pomůcky ČSSZ).
         */
        if ($blockers === [] && $provenance !== [] && $incomeMinor !== null && $incomeMinor > 0
            && count($excusedDays) === self::inclusiveDays($intervalFrom, $intervalTo)
        ) {
            foreach ($provenance as $item) {
                $covered[] = self::coveredEntry(
                    $item['absence_id'],
                    $item['absence_type'],
                    $item['attribute'],
                    self::COVERED_WHOLE_MONTH_INCOME,
                    $item['counted_from'],
                    $item['counted_to'],
                );
            }
            $components = array_fill_keys(self::COMPONENTS, 0);
            $provenance = [];
        }

        usort(
            $provenance,
            static fn (array $left, array $right): int =>
                [$left['counted_from'], $left['absence_id']]
                <=> [$right['counted_from'], $right['absence_id']],
        );
        usort(
            $covered,
            static fn (array $left, array $right): int =>
                [$left['counted_from'], $left['absence_id'], $left['reason']]
                <=> [$right['counted_from'], $right['absence_id'], $right['reason']],
        );

        return [
            'components' => $components,
            'total' => array_sum($components),
            'provenance' => $provenance,
            'covered' => $covered,
            'blockers' => $blockers,
        ];
    }

    /**
     * Odečtené doby měsíce po dovršení důchodového věku (údaj 40 ELDP,
     * JMHZ 10375 a rozpad 10462–10469).
     *
     * Všeobecné zásady ELDP, údaj Doby odečtené: „počet kalendářních dnů, kdy
     * pojištěnec čerpal pracovní volno bez náhrady příjmů, měl neomluvenou
     * nepřítomnost; odečítají se i doby trvání omluvných důvodů [§ 16 odst. 4
     * písm. a)] a dny měsíce označeného X". Dny započtené jsou pak interval
     * Od–Do minus doby odečtené, takže vyloučené doby leží vždy uvnitř odečtených
     * (logické testy ELDP12 č. 39 a 48).
     *
     * Omluvné důvody se berou z výsledku {@see derive()} téhož intervalu, tedy
     * po krytí příjmem — den, který krytí z vyloučených dob vyřadilo, je den
     * s příjmem, ne den bez výdělečné činnosti. Každý den se počítá jednou;
     * složka připadne první nepřítomnosti v pořadí omluvné důvody, ošetřování
     * bez nároku, neplacené volno, neomluvená absence. Měsíc X odečítá celý
     * interval a dny bez nepřítomnosti složku nemají (Pokyny MPSV k 10375).
     *
     * @param list<array<string,mixed>> $absences
     * @param array{provenance:list<array<string,mixed>>} $excluded výsledek derive() téhož intervalu
     * @return array{components:array<string,int>,total:int}
     */
    public function deriveDeducted(
        array $absences,
        string $intervalFrom,
        string $intervalTo,
        array $excluded,
        bool $monthOutsideInsurance,
    ): array {
        $components = array_fill_keys(self::DEDUCTED_COMPONENTS, 0);
        $claimed = [];
        $assign = static function (string $component, string $from, string $to) use (&$components, &$claimed, $intervalFrom, $intervalTo): void {
            $from = max($from, $intervalFrom);
            $to = min($to, $intervalTo);
            for ($day = $from; $day <= $to; $day = self::nextDay($day)) {
                if (isset($claimed[$day])) {
                    continue;
                }
                $claimed[$day] = true;
                ++$components[$component];
            }
        };
        foreach ($excluded['provenance'] as $item) {
            $component = self::DEDUCTED_FROM_EXCLUDED[(string) $item['attribute']] ?? null;
            if ($component !== null) {
                $assign($component, (string) $item['counted_from'], (string) $item['counted_to']);
            }
        }
        foreach ($absences as $absence) {
            $type = $absence['absence_type'] ?? null;
            $from = self::date($absence['date_from'] ?? null);
            $to = self::date($absence['date_to'] ?? null);
            if (!is_string($type) || $from === null || $to === null || $from > $to) {
                continue;
            }
            if (in_array($type, self::CARE_TYPES, true)) {
                $supportEnd = self::careSupportEnd($absence, $from);
                if ($to > $supportEnd) {
                    $assign('osetrovaniBezNaroku', self::nextDay($supportEnd), $to);
                }
                continue;
            }
            if (in_array($type, self::UNPAID_EXCUSED_TYPES, true)) {
                $assign('neplaceneVolno', $from, $to);
            } elseif ($type === 'unexcused') {
                $assign('neomluveneAbsence', $from, $to);
            }
        }
        $total = $monthOutsideInsurance
            ? self::inclusiveDays($intervalFrom, $intervalTo)
            : count($claimed);

        return ['components' => $components, 'total' => $total];
    }

    /**
     * Dny intervalu, které kryje příjem souběžného vztahu (bod e) Přílohy č. 3).
     *
     * Vykonává-li zaměstnanec u téhož zaměstnavatele souběžně další vztah,
     * který má v měsíci zúčtovaný příjem započitatelný do vyměřovacího
     * základu, kryje tento příjem omluvné dny prvního vztahu — zúčtovacím
     * obdobím je celý kalendářní měsíc (§ 6 zákona č. 589/1992 Sb.; Metodická
     * pomůcka ČSSZ k ELDP, př. 40). Den, ve kterém měl omluvný důvod i souběžný
     * vztah, kryt není a vyloučenou dobu nese každý vztah zvlášť (př. 39).
     * Souběžný vztah kryje jen dny, kdy trval.
     *
     * Čte oba zmrazené snapshoty revize (vstup s nepřítomnostmi a trváním
     * vztahů osoby, výsledek s vyměřovacími základy), stejně pro měsíční
     * hlášení i roční evidenční list.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $result
     * @return array<string,true>
     */
    public static function concurrentIncomeDays(
        array $input,
        array $result,
        int $employeeId,
        int $employmentId,
        string $intervalFrom,
        string $intervalTo,
    ): array {
        $withIncome = [];
        foreach ((array) ($result['people'] ?? []) as $person) {
            if (!is_array($person) || ($person['employee_id'] ?? null) !== $employeeId) {
                continue;
            }
            $social = (($person['statutory'] ?? [])['social_insurance'] ?? []);
            foreach ((array) (is_array($social) ? ($social['relationships'] ?? []) : []) as $relationship) {
                $id = is_array($relationship) ? ($relationship['relationship_id'] ?? null) : null;
                if (!is_string($id) || preg_match('/^employment:(\d+)$/D', $id, $match) !== 1) {
                    continue;
                }
                $base = $relationship['assessment_base_minor_units'] ?? null;
                $status = is_array($relationship['participation'] ?? null)
                    ? ($relationship['participation']['status'] ?? null)
                    : null;
                if (is_int($base) && $base > 0 && $status === 'participates'
                    && (int) $match[1] !== $employmentId
                ) {
                    $withIncome[(int) $match[1]] = true;
                }
            }
        }
        if ($withIncome === []) {
            return [];
        }
        $days = [];
        foreach ((array) ($input['people'] ?? []) as $person) {
            if (!is_array($person) || (($person['employee'] ?? [])['id'] ?? null) !== $employeeId) {
                continue;
            }
            foreach ((array) ($person['employments'] ?? []) as $entry) {
                $other = is_array($entry) ? ($entry['employment'] ?? null) : null;
                if (!is_array($other) || !isset($withIncome[(int) ($other['id'] ?? 0)])) {
                    continue;
                }
                $start = self::date($other['actual_start_date'] ?? $other['start_date'] ?? null);
                $end = self::date($other['end_date'] ?? null);
                $from = max($intervalFrom, $start ?? $intervalFrom);
                $to = $end === null ? $intervalTo : min($intervalTo, $end);
                $excused = [];
                foreach ((array) ($entry['absences'] ?? []) as $absence) {
                    if (!is_array($absence) || !array_key_exists((string) ($absence['absence_type'] ?? ''), self::EXCLUDED_ATTRIBUTES)) {
                        continue;
                    }
                    $absenceFrom = self::date($absence['date_from'] ?? null);
                    $absenceTo = self::date($absence['date_to'] ?? null);
                    if ($absenceFrom === null || $absenceTo === null) {
                        continue;
                    }
                    for ($day = max($absenceFrom, $from); $day <= min($absenceTo, $to); $day = self::nextDay($day)) {
                        $excused[$day] = true;
                    }
                }
                for ($day = $from; $day <= $to; $day = self::nextDay($day)) {
                    if (!isset($excused[$day])) {
                        $days[$day] = true;
                    }
                }
            }
        }

        return $days;
    }

    /**
     * @return array{absence_id:int,absence_type:string,attribute:string,reason:string,
     *     counted_from:string,counted_to:string,days:int}
     */
    private static function coveredEntry(
        int $absenceId,
        string $type,
        string $attribute,
        string $reason,
        string $from,
        string $to,
    ): array {
        return [
            'absence_id' => $absenceId,
            'absence_type' => $type,
            'attribute' => $attribute,
            'reason' => $reason,
            'counted_from' => $from,
            'counted_to' => $to,
            'days' => self::inclusiveDays($from, $to),
        ];
    }

    /**
     * Souvislé úseky seřazených dnů.
     *
     * @param list<string> $days
     * @return list<array{0:string,1:string}>
     */
    private static function runs(array $days): array
    {
        $runs = [];
        foreach ($days as $day) {
            $last = array_key_last($runs);
            if ($last !== null && self::nextDay($runs[$last][1]) === $day) {
                $runs[$last][1] = $day;
                continue;
            }
            $runs[] = [$day, $day];
        }

        return $runs;
    }

    /**
     * Vyloučené dny podle § 18 odst. 7 zákona č. 187/2006 Sb. (10366 a rozpad
     * 10473–10475).
     *
     * Jde o dny, které se vyřazují z rozhodného období pro denní vyměřovací
     * základ nemocenských dávek. Bez nich ČSSZ počítá dávku z měsíce, ve
     * kterém zaměstnanec kvůli neplacenému volnu nebo nemoci nevydělával, a
     * dávka vyjde nižší, než na jakou má nárok.
     *
     * ## Rozpad podle druhu nepřítomnosti (pokyny MPSV k 10473–10475)
     *
     * - neplacené volno, náhradní volno, rodičovská → 10473;
     * - nemoc a karanténa: dny uvnitř okna náhrady mzdy (§ 192 ZP, prvních
     *   14 kalendářních dnů, `compensation_window_from/to` ze schváleného
     *   výpočtu náhrady) → 10474; dny za oknem → 10475, jen když účetní při
     *   schválení potvrdila účast na nemocenském pojištění
     *   (`insurance_eligibility_confirmed`); DPN bez nároku na nemocenské
     *   nepatří nikam (pokyny k 10473 ji výslovně vylučují). Den před oknem
     *   (první den nemoci odpracovaný celý) vyloučeným dnem není;
     * - peněžitá pomoc v mateřství (celá, i po porodu) a otcovská → 10475;
     * - ošetřovné a dlouhodobé ošetřovné: dny v podpůrčí době → 10475, dny za
     *   ní jsou omluvená nepřítomnost bez náhrady příjmu → 10473.
     *
     * ## Proč se rozpad buď vykáže celý, nebo vůbec
     *
     * Datový slovník předepisuje `10366 = 10473 + 10474 + 10475`. Vykázat jen
     * část by bylo tvrzení, že zbytek je nula — a to by dávku podhodnotilo
     * úplně stejně jako mlčení, jenom by se to nedalo poznat. `derivable` se
     * proto obrací na `false` jen u nepřítomnosti, o které snapshot nerozhodne:
     * nemoc zmrazená dřív, než snapshot nesl okno náhrady mzdy, vadný řádek,
     * neznámý druh a souběh dvou nepřítomností v jednom dni.
     *
     * Vynechání je legální jen při 10357 > 0 (matice povinností JMHZ 1.4.0.2:
     * „nepovinné, pokud je vyplněn 10357 > 0"); v měsíci bez vyloučených dob
     * musí volající nederivovatelný rozpad zastavit.
     *
     * @param list<array<string,mixed>> $absences absence ze zmrazeného snapshotu
     * @return array{
     *   components:array<string,int>,
     *   total:int,
     *   derivable:bool,
     *   undecidable_types:list<string>,
     *   provenance:list<array{
     *     absence_id:int,absence_type:string,attribute:string,
     *     absence_from:string,absence_to:string,
     *     counted_from:string,counted_to:string,days:int
     *   }>
     * }
     */
    public function deriveSection18(
        array $absences,
        string $intervalFrom,
        string $intervalTo,
    ): array {
        $components = array_fill_keys(self::SECTION18_COMPONENTS, 0);
        $provenance = [];
        $undecidable = [];
        $claimedDays = [];

        foreach ($absences as $absence) {
            $absenceId = $absence['id'] ?? null;
            $type = $absence['absence_type'] ?? null;
            if (!is_int($absenceId) || $absenceId <= 0 || !is_string($type)) {
                // Vadný řádek nahlásí už derive(); tady stačí, že se o něm
                // nedá rozhodnout.
                $undecidable[] = 'unknown';
                continue;
            }
            $from = self::date($absence['date_from'] ?? null);
            $to = self::date($absence['date_to'] ?? null);
            if ($from === null || $to === null || $from > $to) {
                $undecidable[] = $type;
                continue;
            }
            $countedFrom = max($from, $intervalFrom);
            $countedTo = min($to, $intervalTo);
            if ($countedFrom > $countedTo) {
                continue;
            }
            if (in_array($type, self::SECTION18_NEUTRAL_TYPES, true)) {
                continue;
            }
            $classify = self::section18Classifier($type, $absence, $from);
            if ($classify === null) {
                $undecidable[] = $type;
                continue;
            }
            /** @var array<string,array{from:string,to:string,days:list<string>}> $segments */
            $segments = [];
            for ($day = $countedFrom; $day <= $countedTo; $day = self::nextDay($day)) {
                $attribute = $classify($day);
                if ($attribute === null) {
                    continue;
                }
                $segments[$attribute] ??= ['from' => $day, 'to' => $day, 'days' => []];
                $segments[$attribute]['to'] = $day;
                $segments[$attribute]['days'][] = $day;
            }
            $allDays = array_merge(...array_values(array_map(
                static fn (array $segment): array => $segment['days'],
                $segments,
            )));
            if (self::claimDays($claimedDays, $allDays) !== null) {
                // Souběh by tentýž den započítal dvakrát. Souběh hlásí
                // blokátorem derive(); tady se jen přestane tvrdit součet.
                $undecidable[] = $type;
                continue;
            }
            foreach ($segments as $attribute => $segment) {
                $days = count($segment['days']);
                $components[$attribute] += $days;
                $provenance[] = [
                    'absence_id' => $absenceId,
                    'absence_type' => $type,
                    'attribute' => $attribute,
                    'absence_from' => $from,
                    'absence_to' => $to,
                    'counted_from' => $segment['from'],
                    'counted_to' => $segment['to'],
                    'days' => $days,
                ];
            }
        }

        usort(
            $provenance,
            static fn (array $left, array $right): int =>
                [$left['counted_from'], $left['absence_id'], $left['attribute']]
                <=> [$right['counted_from'], $right['absence_id'], $right['attribute']],
        );
        $undecidable = array_values(array_unique($undecidable));
        sort($undecidable);

        return [
            'components' => $components,
            'total' => array_sum($components),
            'derivable' => $undecidable === [],
            'undecidable_types' => $undecidable,
            'provenance' => $provenance,
        ];
    }

    /**
     * Den nepřítomnosti → složka § 18 odst. 7, nebo `null` (vyloučeným dnem
     * není). `null` místo funkce znamená, že o druhu snapshot nerozhodne.
     *
     * @param array<string,mixed> $absence
     * @return (\Closure(string):?string)|null
     */
    private static function section18Classifier(string $type, array $absence, string $from): ?\Closure
    {
        $simple = self::SECTION18_ATTRIBUTES[$type] ?? null;
        if ($simple !== null) {
            return static fn (string $day): string => $simple;
        }
        if (in_array($type, self::SECTION18_BENEFIT_TYPES, true)) {
            return static fn (string $day): string => 'vyplaceniDavek';
        }
        if (in_array($type, self::CARE_TYPES, true)) {
            $supportEnd = self::careSupportEnd($absence, $from);

            return static fn (string $day): string => $day <= $supportEnd
                ? 'vyplaceniDavek'
                : 'omluvenaNepritomnost';
        }
        if (in_array($type, self::SICKNESS_TYPES, true)) {
            $windowFrom = self::date($absence['compensation_window_from'] ?? null);
            $windowTo = self::date($absence['compensation_window_to'] ?? null);
            $eligible = $absence['insurance_eligibility_confirmed'] ?? null;
            // Prázdné okno (navazující část neschopnosti za vyčerpaným oknem, DPN-01) se
            // ukládá jako konec den před začátkem; všechny dny pak leží za oknem.
            if ($windowFrom === null || $windowTo === null || !is_bool($eligible)
                || $windowTo < (new \DateTimeImmutable($windowFrom))->modify('-1 day')->format('Y-m-d')
            ) {
                return null;
            }

            return static fn (string $day): ?string => match (true) {
                $day < $windowFrom => null,
                $day <= $windowTo => 'pracovniNeschopnost',
                $eligible => 'vyplaceniDavek',
                default => null,
            };
        }

        return null;
    }

    /**
     * Poslední den podpůrčí doby ošetřovného nebo dlouhodobého ošetřovného
     * počítaný od prvního dne nepřítomnosti (viz {@see CARE_SUPPORT_DAYS}).
     *
     * @param array<string,mixed> $absence
     */
    public static function careSupportEnd(array $absence, string $from): string
    {
        $days = match (true) {
            ($absence['absence_type'] ?? null) === 'long_term_care' => self::LONG_TERM_CARE_SUPPORT_DAYS,
            ($absence['lone_carer'] ?? false) === true => self::CARE_SUPPORT_DAYS_LONE_CARER,
            default => self::CARE_SUPPORT_DAYS,
        };

        return (new \DateTimeImmutable($from))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
    }

    private static function nextDay(string $day): string
    {
        return (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    }

    /**
     * @param array<string,true> $claimed
     * @param list<string> $days
     * @return string|null první den, který už patřil jiné nepřítomnosti
     */
    private static function claimDays(array &$claimed, array $days): ?string
    {
        foreach ($days as $day) {
            if (isset($claimed[$day])) {
                return $day;
            }
        }
        foreach ($days as $day) {
            $claimed[$day] = true;
        }

        return null;
    }

    /**
     * Předporodní část peněžité pomoci v mateřství uvnitř `[$countedFrom,
     * $countedTo]`. Je to jediné místo, které ji určuje, pro vyloučené doby
     * (10359) i pro rozhodnutí o době pojištění podle § 11 odst. 2.
     *
     * Vyloučenou dobou je průnik s intervalem [očekávaný den porodu − 56 dnů,
     * den porodu − 1 den] (§ 16 odst. 4 věta třetí písm. a) zákona
     * č. 155/1995 Sb.). Den porodu ani doba po něm do průniku nepatří.
     *
     * Nevyplněný den porodu znamená „do konce vykazovaného intervalu k porodu
     * nedošlo": mzdová účetní hlásí měsíc zpětně, takže porod, který se v něm
     * stal, už zná a doplní ho. Tenhle výklad se ale připouští JEN pro interval,
     * který končí před očekávaným dnem porodu. Interval, který na očekávaný
     * den sahá nebo za něj, bez dne porodu nerozhodne (porod v něm s velkou
     * pravděpodobností proběhl) a vrátí se `missing = childbirth_date`. Jinak
     * by zapomenutý den porodu tiše vykázal jako vyloučenou dobu i celé měsíce
     * po porodu a nadhodnotil osobní vyměřovací základ.
     *
     * @param array<string,mixed> $absence
     * @return array{window:array{0:string,1:string}|null,missing:'expected_childbirth_date'|'childbirth_date'|null}
     */
    public static function ppmPreBirthWindow(array $absence, string $countedFrom, string $countedTo): array
    {
        $expected = self::date($absence['expected_childbirth_date'] ?? null);
        if ($expected === null) {
            return ['window' => null, 'missing' => 'expected_childbirth_date'];
        }
        $childbirth = self::date($absence['childbirth_date'] ?? null);
        if ($childbirth === null && $countedTo >= $expected) {
            return ['window' => null, 'missing' => 'childbirth_date'];
        }
        $from = max(
            $countedFrom,
            (new \DateTimeImmutable($expected))
                ->modify('-' . self::PPM_PRE_BIRTH_DAYS . ' days')->format('Y-m-d'),
        );
        $to = $childbirth === null
            ? $countedTo
            : min($countedTo, (new \DateTimeImmutable($childbirth))->modify('-1 day')->format('Y-m-d'));

        return ['window' => $from <= $to ? [$from, $to] : null, 'missing' => null];
    }

    /**
     * Rozpad PPM v intervalu na omluvnou předporodní část a na dny bez příjmu.
     *
     * @return array{0:bool,1:bool} [má omluvné dny, má dny bez příjmu]
     */
    private static function ppmExcusedSplit(mixed $absence, string $intervalFrom, string $intervalTo): array
    {
        if (!is_array($absence)) {
            return [true, false];
        }
        $from = self::date($absence['date_from'] ?? null);
        $to = self::date($absence['date_to'] ?? null);
        if ($from === null || $to === null || $from > $to) {
            // Vadný interval zastaví derive() blokátorem, který ho pojmenuje.
            return [true, false];
        }
        $countedFrom = max($from, $intervalFrom);
        $countedTo = min($to, $intervalTo);
        if ($countedFrom > $countedTo) {
            return [false, false];
        }
        $preBirth = self::ppmPreBirthWindow($absence, $countedFrom, $countedTo);
        if ($preBirth['missing'] !== null) {
            return [true, false];
        }
        if ($preBirth['window'] === null) {
            return [false, true];
        }

        return [
            true,
            self::inclusiveDays($countedFrom, $countedTo)
                > self::inclusiveDays($preBirth['window'][0], $preBirth['window'][1]),
        ];
    }

    /** @return array{code:string,message:string,detail:array<string,mixed>} */
    private static function ppmBlocker(
        string $missing,
        int $absenceId,
        string $periodLabel,
        mixed $expected,
    ): array {
        $detail = ['absence_id' => $absenceId, 'absence_type' => 'ppm', 'period' => $periodLabel];
        if ($missing === 'expected_childbirth_date') {
            return [
                'code' => 'eldp_ppm_expected_childbirth_missing',
                'message' => "Peněžitá pomoc v mateřství #{$absenceId} v období {$periodLabel} nemá"
                    . ' očekávaný den porodu, takže nejde určit, od kdy je vyloučenou dobou'
                    . ' (§ 16 odst. 4 věta třetí písm. a) zákona č. 155/1995 Sb.).'
                    . ' Zrušte ji a zapište znovu s očekávaným dnem porodu.',
                'detail' => $detail,
            ];
        }

        return [
            'code' => 'eldp_ppm_childbirth_missing',
            'message' => "Peněžitá pomoc v mateřství #{$absenceId} v období {$periodLabel} sahá na"
                . ' očekávaný den porodu ' . (is_string($expected) ? $expected : '') . ' nebo za něj'
                . ' a nemá doplněný den porodu. Vyloučenou dobou je jen část do dne, který'
                . ' porodu předcházel. Doplňte u nepřítomnosti den porodu.',
            'detail' => $detail + ['expected_childbirth_date' => $expected],
        ];
    }

    public static function inclusiveDays(string $from, string $to): int
    {
        return (new \DateTimeImmutable($from))
            ->diff(new \DateTimeImmutable($to))
            ->days + 1;
    }

    /**
     * @param array<string,true> $claimed
     * @return string|null první den, který už patřil jiné vyloučené době
     */
    private static function claim(array &$claimed, string $from, int $days): ?string
    {
        $cursor = new \DateTimeImmutable($from);
        $taken = [];
        for ($index = 0; $index < $days; ++$index) {
            $day = $cursor->format('Y-m-d');
            if (isset($claimed[$day])) {
                return $day;
            }
            $taken[] = $day;
            $cursor = $cursor->modify('+1 day');
        }
        foreach ($taken as $day) {
            $claimed[$day] = true;
        }

        return null;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
