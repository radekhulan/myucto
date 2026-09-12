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
 * ## Co se z principu nevyplňuje
 *
 * - **10536** (§ 16 odst. 4 písm. j)) a **10366** (§ 18 odst. 7 zákona
 *   č. 187/2006 Sb.) — modul pro ně nemá žádný vstup, drží se na nule a je to
 *   vidět v součtovém pravidle.
 * - **10474 / 10475** — rozpad nemoci na dny s náhradou příjmu od
 *   zaměstnavatele a na dny s vyplacenou dávkou. Okno prvních čtrnácti dnů
 *   sice `payroll_sickness_events` zná, ale ve zmrazeném snapshotu mzdové
 *   revize není; a jestli dávku ČSSZ skutečně přiznala, zaměstnavatel neví
 *   vůbec. Oba atributy jsou nepovinné, takže se neuvádějí.
 * - **Odečítané doby (10375 a 10462–10469)** se týkají výhradně dob po
 *   dosažení důchodového věku. Modul důchodový věk nezná — nepočítá ho ani
 *   ho neeviduje — takže odečítané doby nedopočítává. Nula na řádku je proto
 *   podmíněná výslovným lidským potvrzením, ne odvozením.
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
    ];

    /**
     * Začátek osmého týdne před očekávaným dnem porodu: sedm celých týdnů
     * a den, kterým osmý týden začíná, tedy 56 dnů před očekávaným dnem.
     * Tutéž hranici pro nástup na PPM stanoví § 32 odst. 1 písm. a) a § 34
     * odst. 1 písm. a) zákona č. 187/2006 Sb.
     */
    public const PPM_PRE_BIRTH_DAYS = 56;

    /**
     * Nepřítomnosti BEZ započitatelného příjmu, u kterých měsíc bez příjmu
     * podle § 11 odst. 2 zákona č. 155/1995 Sb. není dobou pojištění.
     *
     * @var list<string>
     */
    public const INCOME_LESS_TYPES = ['unpaid_leave', 'unexcused', 'parental'];

    public const MONTH_INSURED = 'insured';
    public const MONTH_OUTSIDE_INSURANCE = 'outside_insurance';
    public const MONTH_MIXED = 'mixed';
    public const MONTH_UNEXPLAINED = 'unexplained';

    /**
     * Je měsíc účastného vztahu dobou pojištění? Jediné místo rozhodnutí
     * pro měsíční hlášení i roční evidenční list.
     *
     * § 11 odst. 2 zákona č. 155/1995 Sb.: za dobu pojištění se nepovažuje
     * kalendářní měsíc, ve kterém nebyly dosaženy příjmy započitatelné do
     * vyměřovacího základu, nešlo-li o omluvné důvody podle § 16 odst. 4
     * věty třetí písm. a). Měsíc s příjmem je dobou pojištění vždy; měsíc bez
     * příjmu jen tehdy, když ho vysvětlují výhradně omluvné nepřítomnosti
     * (nemoc, ošetřovné). Výhradně nepřítomnosti bez příjmu = mimo dobu
     * pojištění. Zákon rozhoduje o CELÉM měsíci, takže jejich souběh
     * a nulový příjem bez jakékoli nepřítomnosti nerozhodne a volající musí
     * zastavit.
     *
     * Peněžitá pomoc v mateřství je omluvný důvod jen do dne předcházejícího
     * porodu (tentýž odkaz § 11 odst. 2 na § 16 odst. 4 písm. a)); den porodu
     * a doba po něm jsou nepřítomnost bez příjmu. Proto se rozhoduje nad
     * intervalem měsíce `[$intervalFrom, $intervalTo]` týmž výpočtem, který
     * odvozuje atribut 10359 ({@see ppmPreBirthWindow()}). Měsíc, ve kterém se
     * porod stal, obsahuje obojí a zastaví se jako souběh.
     *
     * PPM, u které se předporodní část určit nedá (chybí očekávaný den porodu
     * nebo nevyplněný den porodu), se tu počítá jako omluvná: odvození
     * vyloučených dob ji pak zastaví blokátorem, který účetní řekne, co doplnit.
     * Tady by stejný stav vyšel jen jako obecný souběh.
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
        foreach ($absences as $absence) {
            $type = is_array($absence) ? ($absence['absence_type'] ?? null) : null;
            if ($type === 'ppm') {
                [$preBirth, $postBirth] = self::ppmExcusedSplit($absence, $intervalFrom, $intervalTo);
                $excused = $excused || $preBirth;
                $incomeLess = $incomeLess || $postBirth;
                continue;
            }
            if (in_array($type, self::INCOME_LESS_TYPES, true)) {
                $incomeLess = true;
            } else {
                $excused = true;
            }
        }

        return match (true) {
            $incomeLess && $excused => self::MONTH_MIXED,
            $incomeLess => self::MONTH_OUTSIDE_INSURANCE,
            $excused => self::MONTH_INSURED,
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
        'unpaid_leave',
        'unexcused',
        'parental',
        /*
         * Překážka na straně zaměstnance je v aplikaci vždy PLACENÁ (validátor
         * absence jí vynucuje schválený snapshot průměru a sazbu 100 %).
         * Náhrada mzdy je součástí vyměřovacího základu, takže by se vyloučená
         * doba kryla s příjmem — a § 16 odst. 4 věta třetí návětí zákona
         * č. 155/1995 Sb. vyloučenou dobu při krytí s příjmem zapovídá.
         * Neplacenou variantu aplikace neeviduje.
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
     */
    private const SECTION18_ATTRIBUTES = [
        'unpaid_leave' => 'omluvenaNepritomnost',
        'compensatory_time_off' => 'omluvenaNepritomnost',
    ];

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
        'employer_obstacle',
        'employee_obstacle',
        'unexcused',
    ];

    /**
     * @param list<array<string,mixed>> $absences absence ze zmrazeného snapshotu
     * @return array{
     *   components:array<string,int>,
     *   total:int,
     *   provenance:list<array{
     *     absence_id:int,absence_type:string,attribute:string,
     *     absence_from:string,absence_to:string,
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
    ): array {
        $components = array_fill_keys(self::COMPONENTS, 0);
        $provenance = [];
        $blockers = [];
        $claimedDays = [];

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
            $components[$attribute] += $days;
            $provenance[] = [
                'absence_id' => $absenceId,
                'absence_type' => $type,
                'attribute' => $attribute,
                'absence_from' => $from,
                'absence_to' => $to,
                'counted_from' => $countedFrom,
                'counted_to' => $countedTo,
                'days' => $days,
            ];
        }

        usort(
            $provenance,
            static fn (array $left, array $right): int =>
                [$left['counted_from'], $left['absence_id']]
                <=> [$right['counted_from'], $right['absence_id']],
        );

        return [
            'components' => $components,
            'total' => array_sum($components),
            'provenance' => $provenance,
            'blockers' => $blockers,
        ];
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
     * ## Proč se rozpad buď vykáže celý, nebo vůbec
     *
     * Datový slovník předepisuje `10366 = 10473 + 10474 + 10475`. Vykázat jen
     * část by bylo tvrzení, že zbytek je nula — a to by dávku podhodnotilo
     * úplně stejně jako mlčení, jenom by se to nedalo poznat. Proto se
     * `derivable` obrací na `false`, jakmile je v intervalu nepřítomnost,
     * jejíž zacházení v § 18 odst. 7 ze zmrazeného snapshotu neplyne:
     *
     * - `dpn`, `quarantine` — rozpad na dny s náhradou příjmu (10474, prvních
     *   čtrnáct kalendářních dnů podle § 192 zákoníku práce) a na dny
     *   s vyplacenou dávkou (10475) závisí na skutečném začátku dočasné
     *   pracovní neschopnosti. `payroll_absences` drží interval nepřítomnosti,
     *   ne běh podpůrčí doby, takže navazující neschopnost nebo neschopnost
     *   zapsanou po měsících by čtrnáctidenní okno posunulo.
     * - `ocr`, `long_term_care`, `paternity`, `ppm` — dny s vyplacenou dávkou
     *   (10475) tvrdí, že dávku ČSSZ opravdu vyplatila. Zaměstnavatel to neví;
     *   ví jen, že o ni bylo požádáno.
     * - `parental` — rodičovská je omluvená nepřítomnost bez náhrady příjmu,
     *   ale současně náhradní doba pojištění hodnocená mimo hlášení; doložený
     *   způsob zápisu do 10473 repozitář nemá.
     * - neznámý druh — fail-closed stejně jako u vyloučených dob.
     *
     * Vynechání je legální: matice povinností JMHZ 1.4.0.2 vede 10366 jako
     * podmíněně nepovinný („nepovinné, pokud je vyplněn 10357 > 0"), a každý
     * z nederivovatelných druhů kromě `parental` vyloučenou dobu podle
     * § 16 odst. 4 tvoří, takže 10357 > 0 nastane s ním.
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
            $attribute = self::SECTION18_ATTRIBUTES[$type] ?? null;
            if ($attribute === null) {
                $undecidable[] = $type;
                continue;
            }
            $days = self::inclusiveDays($countedFrom, $countedTo);
            if (self::claim($claimedDays, $countedFrom, $days) !== null) {
                // Souběh by tentýž den započítal dvakrát. Souběh hlásí
                // blokátorem derive(); tady se jen přestane tvrdit součet.
                $undecidable[] = $type;
                continue;
            }
            $components[$attribute] += $days;
            $provenance[] = [
                'absence_id' => $absenceId,
                'absence_type' => $type,
                'attribute' => $attribute,
                'absence_from' => $from,
                'absence_to' => $to,
                'counted_from' => $countedFrom,
                'counted_to' => $countedTo,
                'days' => $days,
            ];
        }

        usort(
            $provenance,
            static fn (array $left, array $right): int =>
                [$left['counted_from'], $left['absence_id']]
                <=> [$right['counted_from'], $right['absence_id']],
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
