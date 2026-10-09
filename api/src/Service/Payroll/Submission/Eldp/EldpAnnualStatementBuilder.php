<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverYear;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use Psr\Clock\ClockInterface;

/**
 * Sestavení evidenčního listu důchodového pojištění za kalendářní rok.
 *
 * Na rozdíl od `JmhzEldpEvidenceBuilder`, který zmrazuje ELDP atributy jednoho
 * měsíce jako součást měsíčního hlášení, je tohle **celý evidenční list jako
 * samostatná zákonná povinnost**: sečte odpracované doby a vyměřovací základy
 * napříč měsíci roku, rozdělí je na sekce podle kódu ELDP a doplní vyloučené
 * doby. Zdrojem je vždy zmrazená schválená mzdová revize, nikdy živá tabulka —
 * evidenční list musí být reprodukovatelný i za deset let.
 *
 * Stavební zásada: **co není doložené, blokuje**. Chybějící měsíc, absence,
 * kterou modul neumí zapsat, jiné datum nástupu mezi měsíci nebo krácení
 * ročním maximem nevede k odhadu, ale k blokátoru, který pojmenuje konkrétní
 * chybějící podklad. Zákonný rámec a lhůty popisuje `EldpDeadlinePolicy`.
 *
 * ## Rok přechodu z jiného mzdového programu
 *
 * Měsíc má **dva možné zdroje**: zmrazenou schválenou revizi a převzatý mzdový
 * měsíc ({@see PayrollTakeoverYear}, plněný převodem z jiného systému). Zdroje
 * se nesčítají a nemíchají uvnitř měsíce — každý měsíc pochází právě z jednoho:
 *
 *  - měsíc se schválenou revizí se bere **vždy z revize**, i když k němu
 *    převzatá data leží; převzatý protějšek se jen zapíše do podkladu
 *    (`takeover_overridden_periods`) jako doložený rozpor, viz
 *    {@see self::readTakeoverMonths()},
 *  - měsíc bez schválené revize, který MyÚčto přesto počítá, převzatá data
 *    **nenahradí**; jinak by se schoval rozpracovaný běh s jinými čísly,
 *  - měsíc jen s převzatými daty se bere z nich, a je v podkladu, v otisku
 *    i v manifestu vidět jako převzatý ({@see self::takeoverLine()}).
 *
 * **Trvání vztahu drží zmrazená revize.** Pole `relationship_end_date` je
 * u převzatých dat dvojznačné (prázdné znamená „trvá" i „původní systém to
 * nevydal"), takže zná-li vztah aspoň jedna revize roku, převzatá data se
 * proti ní jen kontrolují. Vztah, který skončil v převzatém období (nebo celý
 * rok vedl jiný program), ale žádná revize roku nezná; jeho trvání se pak
 * převezme z převzatých měsíců, jen je-li doložené: jednoznačné datum nástupu
 * i skončení ({@see self::employmentFromTakeover()}). Prázdné skončení se
 * bere jako „vztah trvá" jen tehdy, když trvání po 31. 12. dokládá převzatý
 * nebo schválený měsíc následujícího období; doklad zapíše payload do
 * `employment_continuation_evidence`. Payload to přizná klíčem
 * `employment_dates_source`. Revize za měsíce mimo trvání vztahu (firma počítá
 * dál po jeho skončení) list neblokují ({@see self::resolveEmployment()}).
 *
 * Bez jediného převzatého měsíce je payload i znění blokátorů **doslova** jako
 * dřív: nové klíče (`source_takeovers`, `takeover_overridden_periods`,
 * `employment_dates_source`, `monthly_lines[].source`) se objeví jen tam, kde převzatá data opravdu jsou.
 * Evidenční list je zmrazený otiskem a prázdný klíč navíc by z dřív platného
 * listu udělal neověřitelný.
 *
 * ## Co se do listu vědomě nezapisuje
 *
 * - **Doba uchování stejnopisu.** § 38 odst. 5 věta první zákona č. 582/1991 Sb.
 *   ve znění účinném do 31. 12. 2025 ukládá založit stejnopis do evidence
 *   zaměstnavatele a odkazuje přitom na § 35a odst. 4 písm. a) — tam, a ne
 *   v § 38, stojí lhůta **3 kalendářní roky**. Do listu se proto nezapisuje:
 *   uchovávací lhůty modul drží na jednom místě, v retenčním katalogu
 *   (kategorie `PENSION_EVIDENCE_SHEETS`), ne v jednotlivých sestavovačích.
 * - **Nový list po žádosti o invalidní důchod** (§ 39 odst. 5 zákona
 *   č. 582/1991 Sb. ve znění do 31. 12. 2025): zůstane-li občan v zaměstnání
 *   po podání žádosti o invalidní důchod, zaměstnavatel mu založí nový
 *   evidenční list. Den podání žádosti mzdová revize nenese a modul vede
 *   jediný list za vztah a rok, takže takový list nerozdělí; podává se mimo
 *   aplikaci (popsáno v manuálu, kapitola 85.13).
 *
 * ## Důchodové údaje zaměstnance: výslovné potvrzení
 *
 * Kód ELDP i to, zda se list vůbec vede, závisí na údajích, které zmrazená
 * revize nenese: den dosažení důchodového věku, předčasný starobní důchod,
 * první měsíc výplaty starobního důchodu v plné výši a účast na důchodovém
 * pojištění v cizině. Přicházejí v `confirmation.pension_status`; první tři
 * do něj dosazuje {@see EldpStatementService} ze zákonné evidence osoby
 * ({@see \MyInvoice\Service\Payroll\Pension\PayrollPensionStatus}, týž zdroj
 * jako měsíční ELDP řez JMHZ) a výslovné potvrzení účetní s ní jen porovná.
 * Bez potvrzení se list nesestaví. Ověřená sleva pracujícího důchodce ze zákonné evidence
 * ({@see JmhzEldpEvidenceBuilder::workingPensioner()}) slouží jako kontrola:
 * potvrzení, které žádný starobní důchod neuvádí, s ní nesmí projít.
 *
 * - **Plný starobní důchod (§ 38 odst. 1 věta druhá, od roku 2025).** Ve znění
 *   účinném od 1. 1. 2025 se evidenční list nevede za občana, který má nárok na
 *   výplatu starobního důchodu v plné výši, nebyl-li nebo není-li účasten
 *   důchodového pojištění v cizině; novela č. 360/2025 Sb. podmínku převzala
 *   („Věta první se nepoužije"). Měsíce od prvního měsíce výplaty se proto
 *   z listu vypustí, a nezbude-li žádný, list se nesestaví
 *   (`eldp_not_kept_full_old_age_pension`). Za roky do 2024 se list za
 *   pracujícího důchodce vedl dál (Metodická pomůcka ČSSZ k ELDP, příklad 8).
 * - **Kód D** (číselník kódů ELDP, druhý znak): výdělečná činnost po dovršení
 *   důchodového věku nebo poživatel předčasného starobního důchodu. Sekce se
 *   dělí ke dni, od kterého kód platí. Den uprostřed měsíce by vyžadoval
 *   rozdělit měsíční vyměřovací základ, který výpočet za část měsíce nevede,
 *   a proto blokuje (`eldp_pension_age_mid_month_unsupported`).
 *
 * Měsíční hlášení JMHZ třídu ELDP u poživatele starobního důchodu neuvádí.
 * Kód D pro ostatní (po dovršení důchodového věku bez důchodu, předčasný důchod)
 * se skládá tímtéž pravidlem ({@see EldpPensionAgeCode}) i v hlášení
 * ({@see JmhzEldpEvidenceBuilder}), pokud zdroj nese potvrzené důchodové údaje.
 */
final class EldpAnnualStatementBuilder
{
    public const BUILDER_VERSION = 'eldp-annual-statement.v4';

    /**
     * První rok, za který se evidenční list za poživatele starobního důchodu
     * v plné výši nevede (§ 38 odst. 1 věta druhá zákona č. 582/1991 Sb. ve
     * znění účinném od 1. 1. 2025, po novele č. 360/2025 Sb. „Věta první se
     * nepoužije").
     */
    public const FULL_PENSION_EXCLUSION_FROM_YEAR = 2025;

    /** První rok, za který se list ELDP12 vyplňuje (Zadání ELDP12, údaj Rok: > 2008). */
    public const FIRST_ELDP12_YEAR = 2009;

    private const MONTH_NAMES = [
        1 => 'leden', 2 => 'únor', 3 => 'březen', 4 => 'duben',
        5 => 'květen', 6 => 'červen', 7 => 'červenec', 8 => 'srpen',
        9 => 'září', 10 => 'říjen', 11 => 'listopad', 12 => 'prosinec',
    ];

    /** @var array{manifest_sha256:string,payload:array<string,mixed>}|null */
    private ?array $specManifest = null;

    public function __construct(
        private readonly EldpExcludedPeriodDeriver $excludedPeriods
            = new EldpExcludedPeriodDeriver(),
        private readonly EldpDeadlinePolicy $deadlines
            = new EldpDeadlinePolicy(),
        /**
         * Dnešek pro logický test ELDP12 č. 54 (datum vyhotovení nejpozději
         * v den přijetí). Kontejner ho dodá explicitní definicí v Bootstrapu
         * (PHP-DI volitelný parametr autowiringem nevyplní); bez hodin (čisté
         * sestavení v testech) se datum vyhotovení proti dnešku neporovnává.
         */
        private readonly ?ClockInterface $clock = null,
    ) {}

    /**
     * @param list<mixed> $revisions zmrazené mzdové revize roku
     * @param array<string,mixed>       $confirmation výslovné potvrzení účetní
     * @param PayrollTakeoverYear|null  $takeover převzaté mzdy roku přechodu;
     *        `null` (nebo prázdný rok) = firma vede mzdy v MyÚčtu celý rok
     * @param list<int> $separatelyFiledEmploymentIds vztahy, za které už
     *        samostatný list za tento rok zmrazený je; navazující zaměstnání
     *        se k nim proto nepřipojuje ({@see self::continuingEmployments()})
     * @param (\Closure(int):?PayrollTakeoverYear)|null $takeoverLoader převzaté
     *        mzdy navazujícího vztahu téže osoby
     */
    public function build(
        int $supplierId,
        int $employmentId,
        int $year,
        array $revisions,
        array $confirmation,
        ?PayrollTakeoverYear $takeover = null,
        array $separatelyFiledEmploymentIds = [],
        ?\Closure $takeoverLoader = null,
    ): EldpAnnualStatement {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a pracovní vztah musí být kladná čísla.',
            );
        }
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException(
                'Rok evidenčního listu musí být v rozsahu 2000 až 2100.',
            );
        }
        /*
         * Zadání ELDP12, údaj Rok: hodnota je vždy větší než 2008 a nejvýš
         * rok přijetí listu. Dnešek zná jen kontejner ({@see self::$clock});
         * bez hodin hlídá horní mez nepřímo datum vyhotovení (nejdřív „Do").
         */
        $currentYear = $this->clock === null
            ? null
            : (int) substr(PayrollSubmissionCalendar::today($this->clock->now()), 0, 4);
        if ($year < self::FIRST_ELDP12_YEAR ||($currentYear !== null && $year > $currentYear)) {
            throw new EldpValidationException(
                'eldp_year_out_of_range',
                'Evidenční list ELDP12 jde sestavit jen za rok ' . self::FIRST_ELDP12_YEAR
                    . ' až ' . ($currentYear ?? 'letošní') . ", ne za rok {$year} (Zadání ELDP12, "
                    . 'údaj Rok). Za dřívější rok ho podejte mimo aplikaci, budoucí rok '
                    . 'ještě nejde vykázat.',
            );
        }
        $requestedByAuthority = $confirmation['requested_by_authority'] ?? null;
        if (!is_bool($requestedByAuthority)) {
            throw new EldpValidationException(
                'eldp_confirmation_invalid',
                'Potvrzení musí výslovně určit, zda jde o evidenční list na výzvu ČSSZ/ÚSSZ.',
            );
        }
        $authorityRequestReceivedOn = null;
        if ($requestedByAuthority) {
            $authorityRequestReceivedOn = $confirmation['authority_request_received_on']
                ?? null;
            if (!is_string($authorityRequestReceivedOn)
                || !self::isDate($authorityRequestReceivedOn)
                || substr($authorityRequestReceivedOn, 0, 4) < sprintf('%04d', $year)
            ) {
                throw new EldpValidationException(
                    'eldp_authority_request_date_invalid',
                    'Při sestavení na výzvu zadejte platné datum doručení výzvy '
                        . 'ČSSZ/ÚSSZ, které není před vykazovaným rokem.',
                );
            }
        }
        $authorityRequestDueOn = $requestedByAuthority
            ? ($confirmation['authority_request_due_on'] ?? null)
            : null;
        if ($authorityRequestDueOn === '') {
            $authorityRequestDueOn = null;
        }
        if ($authorityRequestDueOn !== null
            && (!is_string($authorityRequestDueOn) || !self::isDate($authorityRequestDueOn))
        ) {
            throw new EldpValidationException(
                'eldp_authority_request_due_on_invalid',
                'Lhůta uvedená ve výzvě ČSSZ/ÚSSZ musí být platné datum RRRR-MM-DD.',
            );
        }
        if (($confirmation['excluded_days_confirmed'] ?? null) !== true) {
            throw new EldpValidationException(
                'eldp_excluded_days_not_confirmed',
                'Vyloučené doby evidenčního listu musí mzdová účetní výslovně potvrdit.',
            );
        }
        /*
         * Odečtené doby (údaj 40, § 38 odst. 4 písm. h) zákona č. 582/1991 Sb.)
         * se odvozují z nepřítomností u sekcí s kódem D
         * ({@see self::applyPensionAgeCode()}). Dřívější potvrzení „žádné nejsou"
         * se proto nevyžaduje: u kódu D by bylo nepravdivé a ČSSZ by list
         * odmítla logickým testem 39.
         */
        $deathOn = $confirmation['death_on'] ?? null;
        if ($deathOn === '') {
            $deathOn = null;
        }
        if ($deathOn !== null
            && (!is_string($deathOn) || !self::isDate($deathOn) || (int) substr($deathOn, 0, 4) < $year)
        ) {
            throw new EldpValidationException(
                'eldp_death_date_invalid',
                'Datum úmrtí zaměstnance musí být platné datum RRRR-MM-DD, které neleží '
                    . 'před vykazovaným rokem.',
            );
        }
        $pension = self::pensionStatus($confirmation['pension_status'] ?? null);
        // Poznámka je NAŠE pole, ne položka evidenčního listu — ČSSZ ji nikde
        // nepřijímá ani nečte, do XML se nedostane a slouží jen jako interní
        // stopa, proč byl list sestaven. Vyžadovat ji jako podmínku sestavení
        // proto znamenalo blokovat zákonnou povinnost kvůli naší evidenci.
        // Zůstává jen horní mez, aby se do sloupce vešla.
        $note = $confirmation['note'] ?? null;
        if ($note !== null && !is_string($note)) {
            throw new EldpValidationException(
                'eldp_confirmation_note_invalid',
                'Poznámka evidenčního listu musí být text.',
            );
        }
        $note = $note === null ? '' : trim($note);
        if (mb_strlen($note, 'UTF-8') > 500) {
            throw new EldpValidationException(
                'eldp_confirmation_note_invalid',
                'Poznámka evidenčního listu smí mít nejvýše 500 znaků.',
            );
        }

        if ($takeover !== null
            && ($takeover->supplierId !== $supplierId || $takeover->year !== $year)
        ) {
            throw new \InvalidArgumentException(
                'Převzaté mzdy musí být načtené za tutéž firmu a rok jako evidenční list.',
            );
        }
        $takeoverRows = $takeover !== null ? $takeover->forEmployment($employmentId) : [];

        $blockers = [];
        $months = $this->readMonths(
            $supplierId,
            $year,
            $revisions,
            $blockers,
        );
        if ($months === [] && $takeoverRows === []) {
            $blockers[] = [
                'code' => 'eldp_no_source_revision',
                'message' => "Za rok {$year} není k pracovnímu vztahu žádná schválená mzdová revize.",
                'detail' => ['year' => $year, 'employment_id' => $employmentId],
            ];
            throw EldpValidationException::blocked($blockers);
        }
        ksort($months, SORT_STRING);
        $allMonths = $months;
        // Výzva v průběhu vykazovaného roku: list končí posledním zúčtovaným
        // měsícem ({@see self::employmentLines()}).
        $untilLastAccountedMonth = $requestedByAuthority
            && substr((string) $authorityRequestReceivedOn, 0, 4) === sprintf('%04d', $year);

        $employmentFromTakeover = false;
        $continuationEvidence = [];
        $usedContinuation = null;
        $employment = $this->resolveEmployment(
            $months,
            $employmentId,
            $blockers,
            $takeoverRows,
            $employmentFromTakeover,
            $takeover?->continuationAfterYearEnd($employmentId),
            $usedContinuation,
        );
        if ($usedContinuation !== null) {
            $continuationEvidence[$employmentId] = $usedContinuation;
        }
        $employeeEmployments = self::employeeEmployments($allMonths, $employment['employee_id']);
        $this->assertNotContinuationOfEarlierEmployment(
            $employeeEmployments,
            $employment,
            $employmentId,
            $year,
            $separatelyFiledEmploymentIds,
            $blockers,
        );
        $assembled = $this->employmentLines(
            $employmentId,
            $year,
            $employment,
            $months,
            $takeover,
            $takeoverRows,
            $pension,
            $untilLastAccountedMonth,
            $blockers,
        );
        $lines = $assembled['lines'];
        $notKeptFrom = $assembled['not_kept_from'];
        $takeoverOverridden = $assembled['takeover_overridden'];
        $pensionAgeCodeFrom = EldpPensionAgeCode::codeFrom($pension);
        $firstEmployment = $employment;
        /*
         * Navazující zaměstnání u téhož zaměstnavatele (Všeobecné zásady ELDP,
         * Hlavní zásady): zahájí-li občan ve stejném roce do tří měsíců od
         * skončení znovu činnost, list se neuzavírá a další doba jde do dalších
         * řádků TÉHOŽ listu. List patří nejstaršímu vztahu řetězu; navazující
         * vztahy do něj přidají své řádky ({@see self::sections()} rozhodne, zda
         * jde o jeden řádek, nebo o samostatné řádky).
         */
        $continued = [];
        foreach ($this->continuingEmployments(
            $employeeEmployments,
            $employment,
            $employmentId,
            $year,
            $separatelyFiledEmploymentIds,
        ) as $continuedId) {
            $continuedTakeover = $takeoverLoader !== null ? $takeoverLoader($continuedId) : null;
            if ($continuedTakeover !== null
                && ($continuedTakeover->supplierId !== $supplierId || $continuedTakeover->year !== $year)
            ) {
                throw new \InvalidArgumentException(
                    'Převzaté mzdy musí být načtené za tutéž firmu a rok jako evidenční list.',
                );
            }
            $continuedRows = $continuedTakeover !== null
                ? $continuedTakeover->forEmployment($continuedId)
                : [];
            $continuedMonths = $allMonths;
            $continuedFromTakeover = false;
            $usedContinuation = null;
            $continuedEmployment = $this->resolveEmployment(
                $continuedMonths,
                $continuedId,
                $blockers,
                $continuedRows,
                $continuedFromTakeover,
                $continuedTakeover?->continuationAfterYearEnd($continuedId),
                $usedContinuation,
            );
            if ($usedContinuation !== null) {
                $continuationEvidence[$continuedId] = $usedContinuation;
            }
            $part = $this->employmentLines(
                $continuedId,
                $year,
                $continuedEmployment,
                $continuedMonths,
                $continuedTakeover,
                $continuedRows,
                $pension,
                $untilLastAccountedMonth,
                $blockers,
            );
            if ($continued === []) {
                $lines = self::withEmployment($lines, $employmentId, $employeeEmployments);
            }
            $lines = [
                ...$lines,
                ...self::withEmployment($part['lines'], $continuedId, $employeeEmployments),
            ];
            $takeoverOverridden = array_values(array_unique([
                ...$takeoverOverridden,
                ...$part['takeover_overridden'],
            ]));
            $employmentFromTakeover = $employmentFromTakeover || $continuedFromTakeover;
            $continued[] = $continuedId;
            $employment = $continuedEmployment;
        }
        /*
         * Dohoda, která se v roce ani jednou neúčastnila pojištění, evidenční
         * list nemá: všechny její měsíce by byly „X". U pracovního poměru to
         * neplatí — rok bez započitatelného příjmu (např. celoroční neplacené
         * volno) se do listu zapisuje s nulou dnů a vyznačenými měsíci.
         */
        $participating = array_filter(
            $lines,
            static fn (array $line): bool => $line['participates'] === true,
        );
        if ($lines === [] || $participating === []) {
            throw new EldpValidationException(
                'eldp_no_insurance_period',
                'Za vykazovaný rok nevznikla doba důchodového pojištění.',
            );
        }

        $sections = $this->sections($lines);
        $codebook = $this->codebook();
        $codeEvidence = [];
        foreach ($sections as $section) {
            $codeEvidence[] = [
                'code' => $section['code'],
                'row_sha256' => $codebook->requireValue('kod_eldp', $section['code'])['row_hash'],
            ];
        }

        $participationEnd = $employment['end'] !== null
            && $employment['end'] <= sprintf('%04d-12-31', $year)
                ? $employment['end']
                : null;
        $eligibility = EldpDeadlinePolicy::standaloneStatementAllowed(
            $year,
            $participationEnd,
            $requestedByAuthority,
        );
        if (!$eligibility['allowed']) {
            throw new EldpValidationException(
                'eldp_standalone_statement_not_applicable',
                $eligibility['reason'],
            );
        }
        // Konečné vyúčtování: nejpozdější zúčtovaný měsíc listu, u navazujícího
        // zaměstnání tedy měsíc posledního vztahu řetězu.
        $lastSettlementEnd = max(array_map(
            static fn (array $line): string => (string) $line['period_end'],
            $lines,
        ));
        $datedLines = array_values(array_filter(
            $lines,
            static fn (array $line): bool => $line['post_termination'] === false,
        ));
        // Skončí-li vztah přesně posledním dnem roku, evidenční list pokrývá
        // celý rok a platí řádná lhůta do 30. dubna. Mimořádná lhůta „do
        // jednoho měsíce po konečném vyúčtování“ patří jen skončení v průběhu
        // roku. Konečné vyúčtování je poslední zúčtovaný měsíc včetně
        // dodatečně zúčtovaného příjmu „P+".
        if ($requestedByAuthority) {
            $window = $this->deadlines->forAuthorityRequest(
                (string) $authorityRequestReceivedOn,
                $year,
                is_string($authorityRequestDueOn) ? $authorityRequestDueOn : null,
            );
        } elseif ($deathOn !== null) {
            $window = $this->deadlines->forDeath($year, $deathOn);
        } elseif ($participationEnd !== null
            && $participationEnd < sprintf('%04d-01-01', $year)
        ) {
            $window = $this->deadlines->forPostTerminationIncome(
                $year,
                $lastSettlementEnd,
            );
        } elseif ($participationEnd !== null
            && $participationEnd < sprintf('%04d-12-31', $year)
        ) {
            $window = $this->deadlines->forTermination(
                $year,
                $participationEnd,
                $lastSettlementEnd,
            );
        } else {
            $window = $this->deadlines->forYear($year);
        }
        // Začátek listu je „Od" prvního řádku, ne první měsíc vztahu: měsíce
        // dohody nebo ZMR před vznikem účasti do listu nepatří ({@see self::sections()}).
        $datedSections = array_values(array_filter(
            $sections,
            static fn (array $section): bool => is_string($section['valid_from']),
        ));
        $periodFrom = $datedSections !== []
            ? $datedSections[0]['valid_from']
            : $lines[0]['period_start'];
        $periodTo = $datedLines !== []
            ? $datedLines[count($datedLines) - 1]['insurance_to']
            : $lastSettlementEnd;
        $form = $this->form(
            $confirmation,
            self::eldpType($employment['end'], $year, $deathOn),
            $firstEmployment['start'],
            $sections,
            $lastSettlementEnd,
        );

        /*
         * Poctivost dokladu: převzatá část se nikde neschová. Jde do podkladu
         * (`monthly_lines[].source`), do seznamu zdrojů s otiskem řádku
         * (`source_takeovers`) a odtud i do zdrojového manifestu evidenčního
         * listu — stejně jako otisky snapshotů mzdové revize.
         */
        $takeoverSources = [];
        foreach ($lines as $line) {
            if ($line['source'] === 'takeover') {
                $takeoverSources[] = $line['takeover'];
            }
        }
        $mixedSources = $takeoverSources !== [];

        $spec = $this->specManifest();
        $payload = [
            'schema_reference' => EldpAnnualStatement::SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'employee_id' => $employment['employee_id'],
                'employment_id' => $employmentId,
                'year' => $year,
                'statement_kind' => $window->statementKind,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
            ],
            'form' => $form,
            'eligibility' => [
                'rule' => $eligibility['rule'],
                // Do neměnného snapshotu patří i to, jestli šlo o běžnou roční
                // povinnost, nebo o výjimku. Za pár let už z roku a dat nepůjde
                // poznat, proč evidenční list vůbec vznikl.
                'routine' => $eligibility['routine'],
                'reason' => $eligibility['reason'],
                'requested_by_authority' => $requestedByAuthority,
                'authority_request_received_on' => $authorityRequestReceivedOn,
            ],
            'deadline' => [
                'ruleset_id' => $window->rulesetId,
                'ruleset_hash' => $window->rulesetHash,
                'earliest_submission_on' => $window->earliestSubmissionOn,
                'due_on' => $window->dueOn,
                'calendar_basis' => $window->calendarBasis,
                'legal_basis' => $window->legalBasis,
            ],
            'specification' => [
                'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                'spec_manifest_sha256' => $spec['manifest_sha256'],
                'eldp_code_evidence' => $codeEvidence,
            ],
            // Navazující vztahy téhož měsíce stojí na téže revizi; zdroj se
            // proto uvádí jednou za měsíc.
            'source_revisions' => array_values(array_column(array_map(
                static fn (array $line): array => [
                    'period_start' => $line['period_start'],
                    'revision_id' => $line['revision_id'],
                    'run_id' => $line['run_id'],
                    'input_snapshot_hash' => $line['input_snapshot_hash'],
                    'result_snapshot_hash' => $line['result_snapshot_hash'],
                ],
                array_filter(
                    $lines,
                    static fn (array $line): bool => $line['source'] === 'revision',
                ),
            ), null, 'period_start')),
            'monthly_lines' => array_map(
                static function (array $line) use ($mixedSources, $continued): array {
                    $entry = [
                        'period_start' => $line['period_start'],
                        'insurance_from' => $line['insurance_from'],
                        'insurance_to' => $line['insurance_to'],
                        'insurance_days' => $line['insurance_days'],
                        'assessment_base_czk' => $line['assessment_base_czk'],
                        'code' => $line['code'],
                        'excluded_days' => $line['excluded']['components'],
                        'excluded_days_total' => $line['excluded']['total'],
                        'excluded_days_provenance' => $line['excluded']['provenance'],
                    ];
                    // Krytí příjmem a odečtené doby jen tam, kde nastaly: otisk
                    // ostatních listů zůstává stejný.
                    if (($line['excluded']['covered'] ?? []) !== []) {
                        $entry['excluded_days_covered'] = $line['excluded']['covered'];
                    }
                    if (($line['deducted'] ?? null) !== null) {
                        $entry['deducted_days'] = $line['deducted']['components'];
                        $entry['deducted_days_total'] = $line['deducted']['total'];
                    }
                    if (($line['section_15a'] ?? null) !== null) {
                        $entry['section_15a'] = $line['section_15a'];
                    }
                    if ($mixedSources) {
                        $entry['source'] = $line['source'];
                        if ($line['source'] === 'takeover') {
                            $entry['takeover_source'] = $line['takeover']['source'];
                        }
                    }
                    if ($continued !== []) {
                        $entry['employment_id'] = $line['employment_id'];
                    }

                    return $entry;
                },
                $lines,
            ),
            'eldp_sections' => $sections,
            'pension' => [
                'not_kept_from' => $notKeptFrom,
                'code_d_from' => $pensionAgeCodeFrom,
            ],
            'confirmation' => [
                'excluded_days_confirmed' => true,
                // Odečtené doby se odvozují z nepřítomností, nepotvrzují se.
                'deducted_days_derived' => true,
                ...($deathOn !== null ? ['death_on' => $deathOn] : []),
                'requested_by_authority' => $requestedByAuthority,
                'authority_request_received_on' => $authorityRequestReceivedOn,
                'authority_request_due_on' => $authorityRequestDueOn,
                'pension_status' => $pension,
                'note' => trim($note),
            ],
        ];
        if ($takeoverSources !== []) {
            $payload['source_takeovers'] = $takeoverSources;
        }
        if ($takeoverOverridden !== []) {
            $payload['takeover_overridden_periods'] = $takeoverOverridden;
        }
        if ($employmentFromTakeover) {
            $payload['employment_dates_source'] = 'takeover';
        }
        if ($continuationEvidence !== []) {
            ksort($continuationEvidence);
            $payload['employment_continuation_evidence'] = array_map(
                static fn (int $id, array $evidence): array => ['employment_id' => $id, ...$evidence],
                array_keys($continuationEvidence),
                array_values($continuationEvidence),
            );
        }
        if ($continued !== []) {
            $payload['scope']['continued_employment_ids'] = $continued;
        }

        return new EldpAnnualStatement($payload);
    }

    /**
     * Doby důchodového pojištění jednoho vztahu v kalendářním roce — podklad
     * potvrzení podle § 42 zákona č. 582/1991 Sb. („potvrzení o době trvání
     * zaměstnání v kalendářním roce, po kterou byl zaměstnanec důchodově
     * pojištěn").
     *
     * Potvrzení vydává zaměstnavatel na žádost za každý rok, i za roky, za
     * které evidenční list sestavuje ČSSZ. Doby proto skládá týž sestavovač
     * z týchž zmrazených podkladů jako evidenční list (účast, měsíce bez
     * pojištění, „Od" od vzniku účasti), jen bez přípustnosti samostatného
     * listu, lhůty a tiskopisu. Souvislé řádky listu se slévají do jedné doby;
     * plný starobní důchod dobu pojištění nekrátí. Trvá-li vztah, potvrzení
     * končí posledním zúčtovaným měsícem — stejně jako list na výzvu v roce.
     *
     * @param list<mixed> $revisions
     * @param array<string,mixed> $pensionStatus důchodové údaje osoby ze zákonné evidence
     * @return array{
     *   employee_id:int,employment_start:string,employment_end:?string,
     *   periods:list<array{from:string,to:string,days:int,months_without_insurance:list<int>}>,
     *   insurance_days:int,source_revisions:list<array{period_start:string,revision_id:int}>,
     *   source_takeover_periods:list<string>
     * }
     */
    public function insurancePeriods(
        int $supplierId,
        int $employmentId,
        int $year,
        array $revisions,
        array $pensionStatus,
        ?PayrollTakeoverYear $takeover = null,
    ): array {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException('Firma a pracovní vztah musí být kladná čísla.');
        }
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Rok potvrzení musí být v rozsahu 2000 až 2100.');
        }
        if ($takeover !== null
            && ($takeover->supplierId !== $supplierId || $takeover->year !== $year)
        ) {
            throw new \InvalidArgumentException(
                'Převzaté mzdy musí být načtené za tutéž firmu a rok jako potvrzení.',
            );
        }
        $pension = self::pensionStatus($pensionStatus);
        $takeoverRows = $takeover !== null ? $takeover->forEmployment($employmentId) : [];
        $blockers = [];
        $months = $this->readMonths($supplierId, $year, $revisions, $blockers);
        if ($months === [] && $takeoverRows === []) {
            throw EldpValidationException::blocked([[
                'code' => 'eldp_no_source_revision',
                'message' => "Za rok {$year} není k pracovnímu vztahu žádná schválená mzdová revize.",
                'detail' => ['year' => $year, 'employment_id' => $employmentId],
            ]]);
        }
        ksort($months, SORT_STRING);
        $fromTakeover = false;
        $employment = $this->resolveEmployment(
            $months,
            $employmentId,
            $blockers,
            $takeoverRows,
            $fromTakeover,
            $takeover?->continuationAfterYearEnd($employmentId),
        );
        $assembled = $this->employmentLines(
            $employmentId,
            $year,
            $employment,
            $months,
            $takeover,
            $takeoverRows,
            $pension,
            true,
            $blockers,
            true,
        );
        $lines = $assembled['lines'];
        $periods = [];
        if (array_filter($lines, static fn (array $line): bool => $line['participates'] === true) !== []) {
            foreach ($this->sections($lines) as $section) {
                if (!is_string($section['valid_from']) || !is_string($section['valid_to'])) {
                    continue;
                }
                $last = array_key_last($periods);
                if ($last !== null
                    && (new \DateTimeImmutable($periods[$last]['to']))->modify('+1 day')->format('Y-m-d')
                        === $section['valid_from']
                ) {
                    $periods[$last]['to'] = $section['valid_to'];
                    $periods[$last]['days'] += (int) $section['insurance_days'];
                    $periods[$last]['months_without_insurance'] = array_values(array_unique([
                        ...$periods[$last]['months_without_insurance'],
                        ...$section['months_without_insurance'],
                    ]));
                    continue;
                }
                $periods[] = [
                    'from' => $section['valid_from'],
                    'to' => $section['valid_to'],
                    'days' => (int) $section['insurance_days'],
                    'months_without_insurance' => $section['months_without_insurance'],
                ];
            }
        }

        return [
            'employee_id' => $employment['employee_id'],
            'employment_start' => $employment['start'],
            'employment_end' => $employment['end'],
            'periods' => $periods,
            'insurance_days' => array_sum(array_column($periods, 'days')),
            'source_revisions' => array_values(array_column(array_map(
                static fn (array $line): array => [
                    'period_start' => (string) $line['period_start'],
                    'revision_id' => (int) $line['revision_id'],
                ],
                array_filter($lines, static fn (array $line): bool => $line['source'] === 'revision'),
            ), null, 'period_start')),
            'source_takeover_periods' => array_values(array_map(
                static fn (array $line): string => (string) $line['period_start'],
                array_filter($lines, static fn (array $line): bool => $line['source'] === 'takeover'),
            )),
        ];
    }

    /**
     * Řádky listu za jeden pracovní vztah: měsíce ze schválených revizí,
     * v roce přechodu doplněné převzatými, dodatečně zúčtovaný příjem „P+"
     * a dělení podle kódu D. Při navazujícím zaměstnání se volá za každý
     * vztah řetězu zvlášť, takže každý vztah drží svá vlastní pravidla
     * (trvání, účast, § 15a).
     *
     * @param array{employee_id:int,start:string,end:?string} $employment
     * @param array<string,array<string,mixed>> $months měsíce po {@see self::resolveEmployment()}
     * @param list<PayrollTakeoverMonth> $takeoverRows
     * @param array<string,mixed> $pension
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array{lines:list<array<string,mixed>>,not_kept_from:?string,takeover_overridden:list<string>}
     */
    private function employmentLines(
        int $employmentId,
        int $year,
        array $employment,
        array $months,
        ?PayrollTakeoverYear $takeover,
        array $takeoverRows,
        array $pension,
        bool $untilLastAccountedMonth,
        array &$blockers,
        bool $keepFullPensionMonths = false,
    ): array {
        $this->assertPensionStatusMatchesEvidence(
            $months,
            $employment['employee_id'],
            $employmentId,
            $pension,
            $blockers,
        );
        $reportingEnd = $employment['end'];
        // Všeobecné zásady ČSSZ k ELDP určují pro výzvu během roku jako
        // datum „Do“ konec posledního měsíce se zúčtovaným příjmem. Schválená
        // aktuální revize je zde neměnným důkazem takového zúčtovaného měsíce.
        if ($untilLastAccountedMonth && $months !== []) {
            $lastAccountedMonth = (string) array_key_last($months);
            $lastAccountedOn = (new \DateTimeImmutable($lastAccountedMonth))
                ->modify('last day of this month')->format('Y-m-d');
            $reportingEnd = $reportingEnd === null
                ? $lastAccountedOn
                : min($reportingEnd, $lastAccountedOn);
        }
        $requiredMonths = self::requiredMonths(
            $year,
            $employment['start'],
            $reportingEnd,
        );
        // Plný starobní důchod vylučuje jen vedení listu, ne důchodové pojištění:
        // potvrzení o době pojištění (§ 42) měsíce nevypouští.
        $notKeptFrom = $keepFullPensionMonths ? null : self::fullPensionNotKeptFrom($year, $pension);
        if ($notKeptFrom !== null) {
            $requiredMonths = array_values(array_filter(
                $requiredMonths,
                static fn (string $periodStart): bool => $periodStart < $notKeptFrom,
            ));
            foreach (array_keys($months) as $periodStart) {
                if ((string) $periodStart >= $notKeptFrom) {
                    unset($months[$periodStart]);
                }
            }
            if ($requiredMonths === [] && $months === []) {
                throw new EldpValidationException(
                    'eldp_not_kept_full_old_age_pension',
                    "Za rok {$year} se evidenční list nevede: zaměstnanec má od "
                        . self::monthLabel($notKeptFrom) . ' nárok na výplatu starobního '
                        . 'důchodu v plné výši a není účasten důchodového pojištění v cizině '
                        . '(§ 38 odst. 1 věta druhá zákona č. 582/1991 Sb.).',
                );
            }
        }
        /*
         * Druhý zdroj měsíců se zapíná jen tehdy, když k tomuhle vztahu opravdu
         * nějaký převzatý měsíc leží. Firma, která vede mzdy v MyÚčtu celý rok,
         * tak projde doslova touž cestou včetně znění blokátorů.
         */
        $hasTakeover = $takeoverRows !== [];
        $takeoverMonths = [];
        $takeoverRejected = [];
        $takeoverOverridden = [];
        $withoutAnySource = [];
        if ($hasTakeover) {
            /** @var PayrollTakeoverYear $takeover */
            [$takeoverMonths, $takeoverRejected, $takeoverOverridden] = $this->readTakeoverMonths(
                $takeover,
                $employmentId,
                $requiredMonths,
                $months,
                $blockers,
            );
            // Trvání vztahu v roce, ať se na měsíce mimo vztah vůbec neptáme.
            $withoutAnySource = array_flip(
                $takeover->missingPeriods($employment['start'], $reportingEnd),
            );
            $this->assertNoTakeoverIncomeAfterTermination(
                $takeoverRows,
                $employment,
                $months,
                $notKeptFrom,
                $employmentId,
                $blockers,
            );
        }
        foreach ($requiredMonths as $periodStart) {
            if (isset($months[$periodStart])
                || isset($takeoverMonths[$periodStart])
                || isset($takeoverRejected[$periodStart])
            ) {
                continue;
            }
            $blockers[] = [
                'code' => 'eldp_month_source_missing',
                'message' => self::missingMonthMessage(
                    self::monthLabel($periodStart),
                    $hasTakeover,
                    isset($withoutAnySource[substr($periodStart, 0, 7)]),
                ),
                'detail' => ['period_start' => $periodStart, 'employment_id' => $employmentId],
            ];
        }
        $postTerminationMonths = [];
        foreach (array_keys($months) as $periodStart) {
            if (!in_array($periodStart, $requiredMonths, true)) {
                /*
                 * Revize za měsíc PO skončení vztahu je dodatečně zúčtovaný
                 * příjem (odměna, doplatek). Evidenční list ho zapisuje řádkem
                 * „P+" ({@see self::postTerminationLine()}), ne blokátorem.
                 */
                if ($employment['end'] !== null && $periodStart > $employment['end']) {
                    $postTerminationMonths[] = (string) $periodStart;
                    continue;
                }
                $label = self::monthLabel((string) $periodStart);
                $blockers[] = [
                    'code' => 'eldp_month_outside_employment',
                    'message' => "Mzdová revize za {$label} leží mimo trvání pracovního vztahu.",
                    'detail' => ['period_start' => $periodStart],
                ];
            }
        }
        if ($blockers !== []) {
            throw EldpValidationException::blocked($blockers);
        }

        $lines = [];
        foreach ($requiredMonths as $periodStart) {
            $line = isset($months[$periodStart])
                ? $this->monthLine(
                    $employmentId,
                    $months[$periodStart],
                    $employment,
                    $blockers,
                )
                : $this->takeoverLine(
                    $employmentId,
                    $periodStart,
                    $takeoverMonths[$periodStart],
                    $employment,
                    $blockers,
                    $takeover,
                );
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        $lines = $this->applySection15a($lines, $year, $employment['start'], $employmentId, $blockers);
        foreach ($postTerminationMonths as $periodStart) {
            $line = $this->postTerminationLine(
                $employmentId,
                $months[$periodStart],
                $employment,
                $blockers,
            );
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        $pensionAgeCodeFrom = EldpPensionAgeCode::codeFrom($pension);
        if ($pensionAgeCodeFrom !== null) {
            $lines = $this->applyPensionAgeCode(
                $lines,
                $pensionAgeCodeFrom,
                $employmentId,
                $blockers,
            );
        }
        if ($blockers !== []) {
            throw EldpValidationException::blocked($blockers);
        }

        return [
            'lines' => $lines,
            'not_kept_from' => $notKeptFrom,
            'takeover_overridden' => $takeoverOverridden,
        ];
    }

    /**
     * @param list<mixed> $revisions
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array<string,array<string,mixed>> klíčem je `period_start`
     */
    private function readMonths(
        int $supplierId,
        int $year,
        array $revisions,
        array &$blockers,
    ): array {
        $months = [];
        foreach ($revisions as $revision) {
            if (!is_array($revision) || array_is_list($revision)) {
                throw new EldpValidationException(
                    'eldp_source_invalid',
                    'Zdrojová revize evidenčního listu není objekt.',
                );
            }
            $periodStart = $revision['period_start'] ?? null;
            if (!is_string($periodStart)
                || !self::isDate($periodStart)
                || !str_ends_with($periodStart, '-01')
            ) {
                throw new EldpValidationException(
                    'eldp_source_invalid',
                    'Zdrojová revize evidenčního listu nemá platné období.',
                );
            }
            if (substr($periodStart, 0, 4) !== sprintf('%04d', $year)) {
                continue;
            }
            if (isset($months[$periodStart])) {
                $label = self::monthLabel($periodStart);
                $blockers[] = [
                    'code' => 'eldp_month_source_ambiguous',
                    'message' => "Za {$label} přišly dvě schválené revize; evidenční list "
                        . 'nesmí stát na nejednoznačném podkladu.',
                    'detail' => ['period_start' => $periodStart],
                ];
                continue;
            }
            $revisionNo = $revision['revision_no'] ?? null;
            if (($revision['status'] ?? null) !== 'approved'
                || !in_array(
                    $revision['revision_kind'] ?? null,
                    ['regular', 'correction'],
                    true,
                )
                || !is_int($revisionNo)
                || ($revision['current_revision_no'] ?? null) !== $revisionNo
            ) {
                $label = self::monthLabel($periodStart);
                $blockers[] = [
                    'code' => 'eldp_revision_not_current_approved',
                    'message' => "Revize za {$label} není aktuální schválená revize.",
                    'detail' => ['period_start' => $periodStart],
                ];
                continue;
            }
            $input = $this->canonicalSnapshot(
                $revision['input_snapshot_json'] ?? null,
                $revision['input_snapshot_hash'] ?? null,
                'vstupního',
            );
            $result = $this->canonicalSnapshot(
                $revision['result_snapshot_json'] ?? null,
                $revision['result_snapshot_hash'] ?? null,
                'výsledkového',
            );
            if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2'
                || ($input['supplier_id'] ?? null) !== $supplierId
                || ($input['period_start'] ?? null) !== $periodStart
                || ($result['schema_version'] ?? null) !== 'payroll-run-result.v2'
                || ($result['source_snapshot_hash'] ?? null)
                    !== ($revision['input_snapshot_hash'] ?? null)
            ) {
                $label = self::monthLabel($periodStart);
                $blockers[] = [
                    'code' => 'eldp_source_mismatch',
                    'message' => "Podklad za {$label} neodpovídá firmě, období nebo výsledku revize.",
                    'detail' => ['period_start' => $periodStart],
                ];
                continue;
            }
            $months[$periodStart] = [
                'period_start' => $periodStart,
                'revision_id' => self::positiveInt($revision['id'] ?? null, 'revision.id'),
                'run_id' => self::positiveInt($revision['run_id'] ?? null, 'revision.run_id'),
                'input_snapshot_hash' => (string) $revision['input_snapshot_hash'],
                'result_snapshot_hash' => (string) $revision['result_snapshot_hash'],
                'input' => $input,
                'result' => $result,
            ];
        }

        return $months;
    }

    /**
     * Trvání pracovního vztahu, ze kterého se list skládá.
     *
     * Revize jsou za celou firmu a do snapshotu běhu patří jen vztahy, které
     * v měsíci trvají (případně mají dodatečně zúčtovaný příjem). Revize, která
     * vztah neobsahuje a leží celá před nástupem nebo po skončení, proto
     * s listem nesouvisí: z `$months` se vyřadí a nic neblokuje. Revize UVNITŘ
     * trvání vztahu, která ho nezná, blokuje dál, protože tam podklad chybí.
     *
     * Trvání drží zmrazená revize. Teprve když vztah nezná žádná revize roku
     * (skončil v převzatém období, nebo celý rok vedl jiný program), převezme
     * se trvání z převzatých měsíců, a to jen doložené: jednoznačné datum
     * nástupu i skončení ({@see self::employmentFromTakeover()}).
     *
     * @param array<string,array<string,mixed>> $months
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @param list<PayrollTakeoverMonth> $takeoverRows převzaté měsíce vztahu
     * @param array{period:string,source:string,relationship_start_date:?string,relationship_end_date:?string}|null $continuation
     *        doložené trvání vztahu po konci roku
     * @param array{period:string,source:string}|null $usedContinuation doklad, o který se trvání opřelo
     * @return array{employee_id:int,start:string,end:?string}
     */
    private function resolveEmployment(
        array &$months,
        int $employmentId,
        array &$blockers,
        array $takeoverRows,
        bool &$fromTakeover,
        ?array $continuation = null,
        ?array &$usedContinuation = null,
    ): array {
        $resolved = null;
        $inAnyRevision = false;
        /*
         * Měsíce bez vztahu se nehodnotí hned: jestli leží mimo jeho trvání,
         * je známé až po vyřešení trvání. Pořadí blokátorů zůstává po měsících.
         */
        $events = [];
        foreach ($months as $periodStart => $month) {
            $input = $month['input'];
            if (!is_array($input)) {
                continue;
            }
            $entry = $this->findEmploymentEntry($input, $employmentId);
            if ($entry === null) {
                $events[] = ['period' => (string) $periodStart, 'blocker' => null];
                continue;
            }
            $inAnyRevision = true;
            [$employeeId, $data] = $entry;
            $employment = $data['employment'];
            $start = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
            $end = $employment['end_date'] ?? null;
            if (!is_string($start) || !self::isDate($start)
                || ($end !== null && (!is_string($end) || !self::isDate($end)))
            ) {
                $label = self::monthLabel((string) $periodStart);
                $events[] = ['period' => (string) $periodStart, 'blocker' => [
                    'code' => 'eldp_employment_dates_missing',
                    'message' => "Pracovní vztah nemá v revizi za {$label} zmrazené datum nástupu nebo skončení.",
                    'detail' => ['period_start' => $periodStart],
                ]];
                continue;
            }
            $candidate = ['employee_id' => $employeeId, 'start' => $start, 'end' => $end];
            if ($resolved === null) {
                $resolved = $candidate;
                continue;
            }
            if ($resolved !== $candidate) {
                $label = self::monthLabel((string) $periodStart);
                $events[] = ['period' => (string) $periodStart, 'blocker' => [
                    'code' => 'eldp_employment_dates_inconsistent',
                    'message' => "Revize za {$label} eviduje jiné trvání pracovního vztahu než dřívější měsíce; "
                        . 'evidenční list nesmí sečíst nesourodé podklady.',
                    'detail' => ['period_start' => $periodStart],
                ]];
            }
        }
        if (!$inAnyRevision && $takeoverRows !== []) {
            $takeoverBlockers = [];
            $resolved = $this->employmentFromTakeover(
                $takeoverRows,
                $employmentId,
                $takeoverBlockers,
                $continuation,
                $usedContinuation,
            );
            if ($resolved === null) {
                throw EldpValidationException::blocked($takeoverBlockers);
            }
            $fromTakeover = true;
        }
        foreach ($events as $event) {
            if ($event['blocker'] !== null) {
                $blockers[] = $event['blocker'];
                continue;
            }
            $periodStart = $event['period'];
            if ($resolved !== null && self::monthOutsideEmployment($periodStart, $resolved)) {
                unset($months[$periodStart]);
                continue;
            }
            $blockers[] = [
                'code' => 'eldp_employment_not_in_revision',
                'message' => 'Pracovní vztah není ve zmrazené revizi za '
                    . self::monthLabel($periodStart) . '.',
                'detail' => ['period_start' => $periodStart],
            ];
        }
        if ($resolved === null) {
            throw EldpValidationException::blocked(
                $blockers === []
                    ? [[
                        'code' => 'eldp_employment_not_in_revision',
                        'message' => 'Pracovní vztah není v žádné zmrazené revizi vykazovaného roku.',
                        'detail' => [],
                    ]]
                    : $blockers,
            );
        }

        return $resolved;
    }

    /**
     * Leží měsíc celý před nástupem, nebo po měsíci skončení vztahu?
     *
     * @param array{start:string,end:?string} $employment
     */
    private static function monthOutsideEmployment(string $periodStart, array $employment): bool
    {
        $month = substr($periodStart, 0, 7);

        return $month < substr($employment['start'], 0, 7)
            || ($employment['end'] !== null && $month > substr($employment['end'], 0, 7));
    }

    /**
     * Trvání vztahu z převzatých měsíců, když ho nezná žádná revize roku.
     *
     * Převzatý řádek nese datum nástupu a skončení vztahu, jak ho vydal
     * původní program. Prázdné datum skončení je ale dvojznačné: „vztah trvá"
     * i „původní program ho nevydal". Bere se proto jen doložené trvání:
     * všechny vyplněné údaje se musí shodovat a datum skončení musí stát
     * aspoň u jednoho řádku. Jinak blokátor s adresou, kde údaj doplnit,
     * nikdy odhad (ani z živé karty vztahu, ta není zmrazená).
     *
     * Výjimkou je vztah bez skončení, jehož trvání po konci roku dokládá
     * měsíc následujícího období ({@see PayrollTakeoverYear::continuationAfterYearEnd()}).
     *
     * @param list<PayrollTakeoverMonth> $rows
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @param array{period:string,source:string,relationship_start_date:?string,relationship_end_date:?string}|null $continuation
     * @param array{period:string,source:string}|null $usedContinuation
     * @return array{employee_id:int,start:string,end:?string}|null
     */
    private function employmentFromTakeover(
        array $rows,
        int $employmentId,
        array &$blockers,
        ?array $continuation = null,
        ?array &$usedContinuation = null,
    ): ?array {
        $where = ' Doplňte údaj u převzatého měsíce v Mzdy → Kontrola převodu mezd a import opakujte.';
        $employeeIds = [];
        $starts = [];
        $ends = [];
        foreach ($rows as $row) {
            if ($row->employeeId !== null) {
                $employeeIds[$row->employeeId] = true;
            }
            if ($row->relationshipStartDate !== null) {
                $starts[$row->relationshipStartDate] = true;
            }
            if ($row->relationshipEndDate !== null) {
                $ends[$row->relationshipEndDate] = true;
            }
        }
        $starts = array_map(strval(...), array_keys($starts));
        $ends = array_map(strval(...), array_keys($ends));
        sort($starts, SORT_STRING);
        sort($ends, SORT_STRING);
        $detail = [
            'employment_id' => $employmentId,
            'source' => 'takeover',
            'takeover_start_dates' => $starts,
            'takeover_end_dates' => $ends,
        ];
        $invalid = array_filter(
            [...$starts, ...$ends],
            static fn (string $date): bool => !self::isDate($date),
        );
        if (count($employeeIds) !== 1 || $starts === [] || $invalid !== []) {
            $blockers[] = [
                'code' => 'eldp_takeover_employment_unresolved',
                'message' => 'Pracovní vztah není v žádné schválené mzdové revizi roku a převzaté měsíce '
                    . 'nemají jednoznačnou osobu ani platné datum nástupu, ze kterých by šlo '
                    . 'evidenční list sestavit.' . $where,
                'detail' => $detail,
            ];

            return null;
        }
        /*
         * Různá data mezi řádky jsou u převodu běžná, když původní program
         * skončení vztahu později opravil: každý řádek nese trvání, jak ho
         * program znal při zpracování svého měsíce. Platí nejpozdější řádek
         * s vyplněným skončením ({@see self::supersededTakeoverDates()});
         * starší řádky se proti němu v takeoverLine() jen ověří.
         */
        if (count($starts) > 1 || count($ends) > 1) {
            $latest = null;
            foreach ($rows as $row) {
                if (($row->relationshipStartDate !== null || $row->relationshipEndDate !== null)
                    && ($latest === null || $row->period > $latest->period)
                ) {
                    $latest = $row;
                }
            }
            if ($latest !== null && $latest->relationshipEndDate !== null
                && ($latest->relationshipStartDate !== null || count($starts) === 1)
            ) {
                $start = $latest->relationshipStartDate ?? $starts[0];
                if ($latest->relationshipEndDate >= $start) {
                    return [
                        'employee_id' => (int) array_key_first($employeeIds),
                        'start' => $start,
                        'end' => $latest->relationshipEndDate,
                    ];
                }
            }
            $blockers[] = [
                'code' => 'eldp_takeover_employment_dates_ambiguous',
                'message' => 'Převzaté měsíce pracovního vztahu uvádějí různá data nástupu nebo skončení ('
                    . implode(', ', $starts) . ' – ' . ($ends === [] ? 'neuvedeno' : implode(', ', $ends))
                    . '); evidenční list nesmí stát na nejednoznačném podkladu.' . $where,
                'detail' => $detail,
            ];

            return null;
        }
        if ($ends === []) {
            /*
             * Vztah, který převzaté měsíce roku nechávají bez skončení a který
             * doložitelně běží i po 31. 12. (převzatý nebo schválený měsíc
             * následujícího období), za rok trval celý: list se podává jako
             * roční (§ 38 odst. 3 zákona č. 582/1991 Sb. ve znění do
             * 31. 12. 2025). Doklad musí patřit témuž nástupu a nesmí sám
             * skončení do konce roku uvádět.
             */
            $yearEnd = max(array_map(
                static fn (PayrollTakeoverMonth $row): string => substr($row->period, 0, 4),
                $rows,
            )) . '-12-31';
            if ($continuation !== null
                && $continuation['period'] > substr($yearEnd, 0, 7)
                && ($continuation['relationship_start_date'] === null
                    || $continuation['relationship_start_date'] === $starts[0])
                && ($continuation['relationship_end_date'] === null
                    || $continuation['relationship_end_date'] > $yearEnd)
            ) {
                $usedContinuation = [
                    'period' => $continuation['period'],
                    'source' => $continuation['source'],
                ];

                return [
                    'employee_id' => (int) array_key_first($employeeIds),
                    'start' => $starts[0],
                    'end' => $continuation['relationship_end_date'],
                ];
            }
            $blockers[] = [
                'code' => 'eldp_takeover_employment_end_unknown',
                'message' => 'Pracovní vztah není v žádné schválené mzdové revizi roku a převzaté měsíce '
                    . 'neuvádějí datum jeho skončení. Prázdné datum může znamenat, že vztah trvá, '
                    . 'i že ho původní program nevydal, a trvání vztahu po 31. 12. nedokládá ani '
                    . 'převzatý či schválený měsíc následujícího období, takže trvání listu nejde doložit.'
                    . $where,
                'detail' => $detail,
            ];

            return null;
        }
        if ($ends[0] < $starts[0]) {
            $blockers[] = [
                'code' => 'eldp_takeover_employment_dates_ambiguous',
                'message' => "Převzaté měsíce uvádějí skončení pracovního vztahu ({$ends[0]}) "
                    . "před jeho nástupem ({$starts[0]})." . $where,
                'detail' => $detail,
            ];

            return null;
        }

        return [
            'employee_id' => (int) array_key_first($employeeIds),
            'start' => $starts[0],
            'end' => $ends[0],
        ];
    }

    /**
     * Převzatý vyměřovací základ zúčtovaný po měsíci skončení vztahu.
     *
     * Ze schválené revize se takový příjem zapíše řádkem „P+"
     * ({@see self::postTerminationLine()}); převzatý měsíc ale nenese, z jaké
     * činnosti a ke kterému dni příjem patří, takže řádek „P+" z něj modul
     * nedoloží. Bez blokátoru by vyměřovací základ v listu tiše chyběl.
     * Měsíc bez základu (původní program vydal jen prázdný řádek) nevadí.
     *
     * @param list<PayrollTakeoverMonth> $rows
     * @param array{employee_id:int,start:string,end:?string} $employment
     * @param array<string,array<string,mixed>> $months měsíce ze schválených revizí
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     */
    private function assertNoTakeoverIncomeAfterTermination(
        array $rows,
        array $employment,
        array $months,
        ?string $notKeptFrom,
        int $employmentId,
        array &$blockers,
    ): void {
        if ($employment['end'] === null) {
            return;
        }
        $endMonth = substr($employment['end'], 0, 7);
        foreach ($rows as $row) {
            $periodStart = $row->period . '-01';
            if ($row->period <= $endMonth
                || $row->socialBaseMinor === 0
                || isset($months[$periodStart])
                || ($notKeptFrom !== null && $periodStart >= $notKeptFrom)
            ) {
                continue;
            }
            $blockers[] = [
                'code' => 'eldp_takeover_post_termination_income_unsupported',
                'message' => 'Převzatý měsíc ' . self::monthLabel($periodStart) . ' nese vyměřovací '
                    . 'základ sociálního pojištění až po skončení pracovního vztahu ('
                    . $employment['end'] . '). Dodatečně zúčtovaný příjem (řádek „P+“) '
                    . 'z převzatých dat modul nedoloží; evidenční list podejte mimo aplikaci, '
                    . 'nebo údaj opravte v Mzdy → Kontrola převodu mezd.',
                'detail' => [
                    'period_start' => $periodStart,
                    'employment_id' => $employmentId,
                    'source' => 'takeover',
                    'takeover_source' => $row->source,
                ],
            ];
        }
    }

    /**
     * @param array<string,mixed> $month
     * @param array{employee_id:int,start:string,end:string|null} $employment
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array<string,mixed>|null
     */
    private function monthLine(
        int $employmentId,
        array $month,
        array $employment,
        array &$blockers,
    ): ?array {
        $periodStart = (string) $month['period_start'];
        $label = self::monthLabel($periodStart);
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')->format('Y-m-d');
        $input = $month['input'];
        $entry = is_array($input)
            ? $this->findEmploymentEntry($input, $employmentId)
            : null;
        if ($entry === null) {
            $blockers[] = [
                'code' => 'eldp_employment_not_in_revision',
                'message' => "Pracovní vztah není ve zmrazené revizi za {$label}.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        [$employeeId, $data] = $entry;
        $relation = $data['employment']['relation_type'] ?? null;
        $activityCode = $this->activityCode($data, $relation, $label, $periodStart, $blockers);
        if ($activityCode === null) {
            return null;
        }
        $socialKind = self::socialKind($relation);
        $result = is_array($month['result'] ?? null) ? $month['result'] : [];
        $relationship = $this->socialRelationship(
            $result,
            $employeeId,
            $employmentId,
            $label,
            $periodStart,
            $blockers,
            $socialKind,
            false,
            $relation === 'small_scale_employment',
        );
        if ($relationship === null) {
            return null;
        }
        $insuranceFrom = max($periodStart, $employment['start']);
        $insuranceTo = $employment['end'] === null
            ? $periodEnd
            : min($periodEnd, $employment['end']);
        if ($insuranceFrom > $insuranceTo) {
            return null;
        }
        $absences = $data['absences'] ?? null;
        if (!is_array($absences) || !array_is_list($absences)) {
            $blockers[] = [
                'code' => 'eldp_absences_invalid',
                'message' => "Absence za {$label} nejsou ve zmrazené revizi seznam.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        /** @var list<array<string,mixed>> $absences */
        $revisionSource = [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'source' => 'revision',
            'revision_id' => $month['revision_id'],
            'run_id' => $month['run_id'],
            'input_snapshot_hash' => $month['input_snapshot_hash'],
            'result_snapshot_hash' => $month['result_snapshot_hash'],
            'insurance_from' => $insuranceFrom,
            'insurance_to' => $insuranceTo,
            // ELDP12 údaj 21 (42, 63) „MR": A jen u zaměstnání malého rozsahu.
            'small_scale' => $relation === 'small_scale_employment',
        ];
        /*
         * DPČ, DPP a zaměstnání malého rozsahu se důchodového pojištění účastní
         * jen v měsících, kdy zúčtovaný příjem dosáhl rozhodné částky (§ 7 a 7a
         * zákona č. 187/2006 Sb.). Měsíc bez účasti není dobou pojištění: sekce
         * jde dál se svým kódem a měsíc se v listu vyznačí „X". Nemoc, karanténu
         * nebo PPM v takovém měsíci posoudí až {@see self::applySection15a()},
         * protože nárok podle § 15a zákona č. 187/2006 Sb. závisí na účasti
         * v předchozích měsících.
         */
        if (($relationship['participation']['status'] ?? null) === 'does_not_participate') {
            return $revisionSource + [
                'insurance_days' => 0,
                'assessment_base_czk' => 0,
                'code' => $activityCode . '++',
                'participates' => false,
                'outside_insurance' => true,
                'post_termination' => false,
                'excluded' => self::noExcludedDays(),
                'deductible' => [
                    'components' => array_fill_keys(EldpExcludedPeriodDeriver::DEDUCTED_COMPONENTS, 0),
                    'total' => EldpExcludedPeriodDeriver::inclusiveDays($insuranceFrom, $insuranceTo),
                ],
                'section_15a_absences' => self::section15aAbsences($absences, $insuranceFrom, $insuranceTo),
            ];
        }
        /*
         * Do evidenčního listu jde vyměřovací základ v plné výši, i nad roční
         * maximum (§ 15a zákona č. 589/1992 Sb. omezuje jen základ pro
         * pojistné). Měsíce po dosažení maxima jsou dobou pojištění (Metodická
         * pomůcka ČSSZ k ELDP, př. 1; Všeobecné zásady, údaj Vyměřovací základ).
         */
        $uncapped = $relationship['assessment_base_minor_units'] ?? null;
        if (!is_int($uncapped) || $uncapped < 0) {
            $blockers[] = [
                'code' => 'eldp_assessment_base_missing',
                'message' => "Za {$label} chybí vyměřovací základ sociálního pojištění.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        if ($uncapped % 100 !== 0 || intdiv($uncapped, 100) > 9_999_999_999) {
            $blockers[] = [
                'code' => 'eldp_assessment_base_not_whole_czk',
                'message' => "Vyměřovací základ za {$label} není celé Kč v rozsahu datové věty.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }

        /*
         * § 11 odst. 2 zákona č. 155/1995 Sb.: měsíc bez započitatelného
         * příjmu kvůli nepřítomnosti bez příjmu není dobou pojištění. Sekce
         * pokračuje se svým kódem, ale měsíc do ní přidá nula dnů — tak ho
         * vykazuje i měsíční hlášení a přijatá hlášení jiných systémů. Dřív
         * se započítal jako plný měsíc pojištění.
         */
        $monthStatus = EldpExcludedPeriodDeriver::insuranceMonthStatus(
            $absences,
            $uncapped,
            $insuranceFrom,
            $insuranceTo,
        );
        if ($monthStatus === EldpExcludedPeriodDeriver::MONTH_UNEXPLAINED) {
            $blockers[] = [
                'code' => 'eldp_insurance_month_without_income',
                'message' => "Za {$label} nebyl zúčtován započitatelný příjem a evidované"
                    . ' nepřítomnosti to nevysvětlí, takže podle § 11 odst. 2 zákona'
                    . ' č. 155/1995 Sb. nejde určit, zda je měsíc dobou pojištění.'
                    . ' Doplňte mzdu nebo nepřítomnost v Mzdy → Nepřítomnosti.',
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        $excluded = $this->excludedPeriods->derive(
            $absences,
            $insuranceFrom,
            $insuranceTo,
            $label,
            $uncapped,
            EldpExcludedPeriodDeriver::concurrentIncomeDays(
                is_array($input) ? $input : [],
                $result,
                $employeeId,
                $employmentId,
                $insuranceFrom,
                $insuranceTo,
            ),
        );
        foreach ($excluded['blockers'] as $blocker) {
            $blocker['detail']['period_start'] = $periodStart;
            $blocker['detail']['employment_id'] = $employmentId;
            $blockers[] = $blocker;
        }
        if ($excluded['blockers'] !== []) {
            return null;
        }
        $outside = $monthStatus === EldpExcludedPeriodDeriver::MONTH_OUTSIDE_INSURANCE;
        $days = $outside
            ? 0
            : EldpExcludedPeriodDeriver::inclusiveDays($insuranceFrom, $insuranceTo);
        if ($excluded['total'] > $days) {
            $blockers[] = [
                'code' => 'eldp_excluded_days_exceed_period',
                'message' => "Vyloučené doby za {$label} přesahují dobu pojištění v měsíci.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }

        return $revisionSource + [
            'insurance_days' => $days,
            'assessment_base_czk' => intdiv($uncapped, 100),
            'code' => $activityCode . '++',
            'participates' => true,
            'outside_insurance' => $outside,
            'post_termination' => false,
            'excluded' => $excluded,
            'deductible' => $this->excludedPeriods->deriveDeducted(
                $absences,
                $insuranceFrom,
                $insuranceTo,
                $excluded,
                $outside,
            ),
        ];
    }

    /**
     * Řádek „příjem zúčtovaný po skončení zaměstnání" (kód `1P+`, `AP+`).
     *
     * Číselník kódů ELDP (ID 10240) má pro tenhle případ vlastní kód:
     * „1P+ – jeden prac. poměr u zaměst. – dodat. zúčtování příjmů po skončení
     * výdělečné čin." Takový řádek nenese dobu pojištění — jen vyměřovací
     * základ; data „Od" a „Do" ani počet dnů se u něj nevyplňují (přijatý
     * evidenční list jiného mzdového programu je u 1P+ nechává prázdné). Dřív
     * každá revize za měsíc po skončení vztahu zablokovala celý list.
     *
     * Měsíc po skončení bez vyměřovacího základu do listu nepatří vůbec.
     *
     * @param array<string,mixed> $month
     * @param array{employee_id:int,start:string,end:string|null} $employment
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array<string,mixed>|null
     */
    private function postTerminationLine(
        int $employmentId,
        array $month,
        array $employment,
        array &$blockers,
    ): ?array {
        $periodStart = (string) $month['period_start'];
        $label = self::monthLabel($periodStart);
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')->format('Y-m-d');
        $input = $month['input'];
        $entry = is_array($input)
            ? $this->findEmploymentEntry($input, $employmentId)
            : null;
        if ($entry === null) {
            return null;
        }
        [$employeeId, $data] = $entry;
        $relation = $data['employment']['relation_type'] ?? null;
        $activityCode = $this->activityCode($data, $relation, $label, $periodStart, $blockers);
        if ($activityCode === null) {
            return null;
        }
        $relationship = $this->socialRelationship(
            is_array($month['result'] ?? null) ? $month['result'] : [],
            $employeeId,
            $employmentId,
            $label,
            $periodStart,
            $blockers,
            self::socialKind($relation),
            true,
        );
        if ($relationship === null) {
            return null;
        }
        // Plný vyměřovací základ i nad roční maximum, stejně jako v monthLine().
        $base = $relationship['assessment_base_minor_units'] ?? null;
        if (!is_int($base) || $base <= 0) {
            return null;
        }
        /*
         * Druhý znak „P" nepřichází v úvahu u zaměstnání malého rozsahu ani
         * u DPP: příjem zúčtovaný po skončení se u nich považuje za příjem
         * měsíce, ve kterém zaměstnání skončilo (§ 7 odst. 3 a § 7a odst. 2
         * zákona č. 187/2006 Sb.; Všeobecné zásady ELDP, Kód — druhý znak).
         * Může tím zpětně založit účast v posledním měsíci, a to je oprava
         * listu za ten měsíc (Metodická pomůcka ČSSZ, př. 30), ne řádek „P+".
         */
        if (in_array($relation, ['small_scale_employment', 'dpp'], true)) {
            $blockers[] = [
                'code' => 'eldp_post_termination_small_scale_unsupported',
                'message' => "Za {$label} je zúčtován příjem po skončení "
                    . ($relation === 'dpp' ? 'dohody o provedení práce' : 'zaměstnání malého rozsahu')
                    . '. U těchto vztahů se kód „P+" nepoužívá: příjem patří do měsíce skončení '
                    . 'a může zpětně založit účast na pojištění (§ 7 odst. 3 a § 7a odst. 2 zákona '
                    . 'č. 187/2006 Sb.). Přepočtěte měsíc skončení s tímto příjmem, nebo evidenční '
                    . 'list (opravný, typ 52) podejte mimo aplikaci.',
                'detail' => ['period_start' => $periodStart, 'relation_type' => $relation],
            ];

            return null;
        }
        if ($base % 100 !== 0 || intdiv($base, 100) > 9_999_999_999) {
            $blockers[] = [
                'code' => 'eldp_assessment_base_not_whole_czk',
                'message' => "Vyměřovací základ příjmu zúčtovaného po skončení zaměstnání za {$label} "
                    . 'není celé Kč v rozsahu datové věty.',
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        $code = $activityCode . 'P+';
        if (!in_array($code, self::POST_TERMINATION_CODES, true)) {
            $blockers[] = [
                'code' => 'eldp_post_termination_code_missing',
                'message' => "Za {$label} je příjem zúčtovaný po skončení vztahu, ale číselník kódů "
                    . "ELDP pro druh činnosti {$activityCode} kód „P+“ nezná.",
                'detail' => ['period_start' => $periodStart, 'activity_code' => $activityCode],
            ];

            return null;
        }

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'source' => 'revision',
            'revision_id' => $month['revision_id'],
            'run_id' => $month['run_id'],
            'input_snapshot_hash' => $month['input_snapshot_hash'],
            'result_snapshot_hash' => $month['result_snapshot_hash'],
            'insurance_from' => null,
            'insurance_to' => null,
            'insurance_days' => 0,
            'assessment_base_czk' => intdiv($base, 100),
            'code' => $code,
            'participates' => true,
            'outside_insurance' => false,
            'post_termination' => true,
            'excluded' => self::noExcludedDays(),
            'deductible' => null,
        ];
    }

    /** @return array{components:array<string,int>,total:int,provenance:list<array<string,mixed>>,covered:list<array<string,mixed>>} */
    private static function noExcludedDays(): array
    {
        return [
            'components' => array_fill_keys(EldpExcludedPeriodDeriver::COMPONENTS, 0),
            'total' => 0,
            'provenance' => [],
            'covered' => [],
        ];
    }

    /**
     * Kódy „P+" z číselníku kódů ELDP (ID 10240) připnutého datového slovníku
     * JMHZ. Pro dohodu o provedení práce číselník kód dodatečného zúčtování
     * nemá, proto se u ní nevymýšlí.
     */
    private const POST_TERMINATION_CODES = [
        '1P+', '2P+', '3P+', '4P+', '5P+', '6P+', '7P+', '8P+', '9P+',
        'AP+', 'BP+', 'CP+', 'DP+', 'EP+', 'FP+', 'GP+', 'HP+', 'IP+', 'JP+',
    ];

    /**
     * Druh sociálního vztahu, pod kterým výsledek výpočtu vede pracovní vztah.
     * Zaměstnání malého rozsahu je pro sociální pojištění pracovní poměr
     * (stejně jako v {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceBuilder}).
     * Člen statutárního orgánu a společník s.r.o. jsou pojištěnci § 5 odst. 1
     * písm. a) bodu 2 zák. č. 155/1995 Sb.; měsíční hlášení je vede s kódem S++
     * a evidenční list za ně musí jít sestavit stejně.
     */
    private static function socialKind(mixed $relation): ?string
    {
        return match ($relation) {
            'employment', 'small_scale_employment' => 'employment',
            'dpc' => 'dpc',
            'dpp' => 'dpp',
            'partner_dependent', 'statutory_body' => 'corporate_body',
            default => null,
        };
    }

    /**
     * Druh činnosti ČSSZ, ze kterého se skládá kód sekce. Rodinu druhu
     * činnosti ke druhu vztahu ověřuje jediné pravidlo
     * {@see PayrollEmploymentJmhzActivityFamily::matches()} — totéž, podle
     * kterého se kód skládá v měsíčním hlášení.
     *
     * @param array<string,mixed> $data
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     */
    private function activityCode(
        array $data,
        mixed $relation,
        string $label,
        string $periodStart,
        array &$blockers,
    ): ?string {
        if (self::socialKind($relation) === null) {
            $blockers[] = [
                'code' => 'eldp_relationship_kind_unsupported',
                'message' => "Evidenční list podporuje pracovní poměr, dohodu o pracovní činnosti "
                    . "a dohodu o provedení práce; za {$label} je vztah typu "
                    . (is_string($relation) ? $relation : 'neznámý') . '.',
                'detail' => ['period_start' => $periodStart, 'relation_type' => $relation],
            ];

            return null;
        }
        $term = $data['term'] ?? null;
        $activityCode = is_array($term) ? ($term['activity_code'] ?? null) : null;
        $detailCode = is_array($term) ? ($term['jmhz_relationship_detail_code'] ?? null) : null;
        if (!is_string($activityCode)
            || ($detailCode !== null && !is_string($detailCode))
            || !PayrollEmploymentJmhzActivityFamily::matches(
                (string) $relation,
                $activityCode,
                $detailCode,
            )
        ) {
            $blockers[] = [
                'code' => 'eldp_activity_unsupported',
                'message' => "Za {$label} neodpovídá druh činnosti ČSSZ druhu pracovního vztahu; "
                    . 'kód ELDP by se musel odvodit jinak, než modul umí.',
                'detail' => ['period_start' => $periodStart, 'activity_code' => $activityCode],
            ];

            return null;
        }

        return $activityCode;
    }

    /**
     * Které měsíce roku se vezmou z převzatých mezd, a které se tím naopak
     * zablokují.
     *
     * ## Měsíc z obou stran vyhrává REVIZE
     *
     * `presence() === 'both'` není věc k sečtení — dvakrát započtený měsíc
     * znamená dvojí dobu pojištění i dvojí vyměřovací základ. Vyhrává schválená
     * mzdová revize, a to ze tří důvodů:
     *
     *  1. Je to jediný podklad, který umí evidenční list **doložit**: je
     *     zmrazený, ověřený otiskem obou snapshotů a rozpadá se až na jednotlivé
     *     nepřítomnosti, ze kterých vznikly vyloučené doby. Převzatý měsíc je
     *     opis souhrnu bez vnitřní struktury, kterou by šlo přezkoumat.
     *  2. Je to **náš** výpočet za měsíc, který MyÚčto opravdu počítalo.
     *     Převzatý protějšek téhož měsíce je kontrolní údaj (přesně nad ním
     *     stojí sestava „naše přepočtená mzda vs. převzatá"), ne druhá pravda.
     *  3. Celá reprodukovatelnost listu stojí na tom, že jde znovu sestavit ze
     *     zmrazených revizí i za deset let.
     *
     * Rozpor se tím ale nezamete: měsíc jde do `takeover_overridden_periods`,
     * tedy do podkladu i do manifestu.
     *
     * Měsíc, který MyÚčto počítá, ale nemá schválenou revizi, převzatá data
     * NENAHRADÍ. Jinak by se za doložený převod vydal měsíc, ke kterému uvnitř
     * leží rozpracovaný běh s jinými čísly.
     *
     * @param list<string> $requiredMonths
     * @param array<string,array<string,mixed>> $months měsíce ze schválených revizí
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array{0:array<string,PayrollTakeoverMonth>,1:array<string,true>,2:list<string>}
     */
    private function readTakeoverMonths(
        PayrollTakeoverYear $takeover,
        int $employmentId,
        array $requiredMonths,
        array $months,
        array &$blockers,
    ): array {
        $rows = [];
        foreach ($takeover->forEmployment($employmentId) as $month) {
            $rows[$month->period][] = $month;
        }
        $resolved = [];
        $rejected = [];
        $overridden = [];
        foreach ($requiredMonths as $periodStart) {
            $period = substr($periodStart, 0, 7);
            $candidates = $rows[$period] ?? [];
            if ($candidates === []) {
                continue;
            }
            if (isset($months[$periodStart])) {
                $overridden[] = $periodStart;
                continue;
            }
            $label = self::monthLabel($periodStart);
            if ($takeover->hasCalculated($period)) {
                $rejected[$periodStart] = true;
                $blockers[] = [
                    'code' => 'eldp_takeover_month_not_substitutable',
                    'message' => "Mzdu za {$label} počítá MyÚčto, ale nemá schválenou mzdovou revizi; "
                        . 'převzatý měsíc ji nesmí nahradit. Revizi měsíce schvalte, '
                        . 'nebo rozpracovaný běh zrušte.',
                    'detail' => ['period_start' => $periodStart, 'employment_id' => $employmentId],
                ];
                continue;
            }
            if (count($candidates) > 1) {
                $rejected[$periodStart] = true;
                $blockers[] = [
                    'code' => 'eldp_takeover_month_ambiguous',
                    'message' => "Za {$label} leží víc převzatých mzdových měsíců téhož pracovního vztahu; "
                        . 'evidenční list nesmí stát na nejednoznačném podkladu. '
                        . 'Nechte v Mzdy → Kontrola převodu mezd jediný řádek za měsíc.',
                    'detail' => ['period_start' => $periodStart, 'employment_id' => $employmentId],
                ];
                continue;
            }
            $resolved[$periodStart] = $candidates[0];
        }

        return [$resolved, $rejected, $overridden];
    }

    /**
     * Řádek evidenčního listu z převzatého mzdového měsíce.
     *
     * Převzatý měsíc není výsledek výpočtu MyÚčta, ale opis toho, co za měsíc
     * vydal původní mzdový program. Proto se z něj **nic nedopočítává**: každý
     * údaj, který evidenční list potřebuje a původní systém ho nevydal, je
     * blokátor s adresou, kde ho doplnit. Odvodit dny pojištění z odpracované
     * doby nebo složky vyloučených dob z jejich součtu by vyrobilo nedoložený
     * údaj v zákonné evidenci, ze které se za desítky let počítá důchod.
     *
     * Druhým doloženým zdrojem je evidence nepřítomností vztahu (s daty od–do,
     * převedená týmž převodem, {@see PayrollTakeoverYear::absencesFor()}). Z ní,
     * a jedině z ní, se u převzatého měsíce rozhoduje totéž co u spočítaného:
     *
     * - měsíc s nulovým vyměřovacím základem je dobou pojištění, jen když ho
     *   vysvětluje omluvný důvod, a mimo ni („X"), když ho vysvětluje jen
     *   nepřítomnost bez příjmu (§ 11 odst. 2 zákona č. 155/1995 Sb.,
     *   {@see EldpExcludedPeriodDeriver::insuranceMonthStatus()}); převzaté dny
     *   pojištění u PAMICA vznikají z kalendářních dnů (`DnyKal`) a o § 11
     *   odst. 2 nic neříkají,
     * - rozpad vyloučených dob podle § 16 odst. 4 se odvodí z nepřítomností
     *   týmž modulem včetně krytí příjmem a jeho úhrn se musí rovnat úhrnu,
     *   který vydal původní program. Rozpor obou zdrojů blokuje.
     *
     * @param array{employee_id:int,start:string,end:string|null} $employment
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array<string,mixed>|null
     */
    private function takeoverLine(
        int $employmentId,
        string $periodStart,
        PayrollTakeoverMonth $month,
        array $employment,
        array &$blockers,
        ?PayrollTakeoverYear $takeover = null,
    ): ?array {
        $label = self::monthLabel($periodStart);
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')->format('Y-m-d');
        $where = ' Doplňte jej u převzatého měsíce v Mzdy → Kontrola převodu mezd a import opakujte.';
        $detail = [
            'period_start' => $periodStart,
            'employment_id' => $employmentId,
            'source' => 'takeover',
            'takeover_source' => $month->source,
        ];

        if (!in_array($month->relationType, ['employment', 'dpc', 'dpp'], true)) {
            $blockers[] = [
                'code' => 'eldp_takeover_relationship_kind_unsupported',
                'message' => "Převzatý měsíc {$label} nemá druh vztahu pracovní poměr, DPČ ani DPP ("
                    . ($month->relationType ?? 'neuvedeno') . '); evidenční list jiný neumí.'
                    . $where,
                'detail' => $detail + ['relation_type' => $month->relationType],
            ];

            return null;
        }
        $code = $month->relationType === 'employment'
            ? $month->eldpCode()
            : ($month->activityCode !== null
                && PayrollEmploymentJmhzActivityFamily::matches(
                    $month->relationType,
                    $month->activityCode,
                    null,
                )
                    ? $month->activityCode . '++'
                    : null);
        if ($code === null) {
            $blockers[] = [
                'code' => 'eldp_takeover_activity_missing',
                'message' => "Převzatý měsíc {$label} nemá druh činnosti ČSSZ 1–9, ze kterého se skládá "
                    . 'kód sekce evidenčního listu.' . $where,
                'detail' => $detail + ['activity_code' => $month->activityCode],
            ];

            return null;
        }
        /*
         * Rozsah listu drží zmrazená revize; převzatá data se proti ní jen
         * kontrolují. Je to tatáž přísnost jako u `eldp_employment_dates_inconsistent`
         * mezi revizemi: nesourodý podklad se nesjednocuje, protože právě datum
         * „od" a „do" je to, co ČSSZ z listu čte.
         *
         * Výjimkou je řádek, jehož data později opravil sám původní program:
         * převzatý řádek nese trvání vztahu, jak ho program znal v době zpracování
         * měsíce, a pozdější řádek téhož vztahu s daty listu je jeho opravou
         * ({@see self::supersededTakeoverDates()}).
         */
        $superseded = false;
        if (($month->relationshipStartDate !== null
                && $month->relationshipStartDate !== $employment['start'])
            || ($month->relationshipEndDate !== null
                && $month->relationshipEndDate !== $employment['end'])
        ) {
            $superseded = $takeover !== null
                && self::supersededTakeoverDates($takeover->forEmployment($employmentId), $month, $employment);
            if (!$superseded) {
                $blockers[] = [
                    'code' => 'eldp_takeover_employment_dates_inconsistent',
                    'message' => "Převzatý měsíc {$label} eviduje jiné trvání pracovního vztahu ("
                        . ($month->relationshipStartDate ?? '?') . ' – '
                        . ($month->relationshipEndDate ?? 'trvá') . ') než schválené mzdové revize ('
                        . $employment['start'] . ' – ' . ($employment['end'] ?? 'trvá')
                        . '); evidenční list nesmí sečíst nesourodé podklady.',
                    'detail' => $detail + [
                        'takeover_start_date' => $month->relationshipStartDate,
                        'takeover_end_date' => $month->relationshipEndDate,
                    ],
                ];

                return null;
            }
        }

        $insuranceFrom = max($periodStart, $employment['start']);
        $insuranceTo = $employment['end'] === null
            ? $periodEnd
            : min($periodEnd, $employment['end']);
        if ($insuranceFrom > $insuranceTo) {
            return null;
        }
        if (!$month->pensionParticipation && $month->socialBaseMinor !== 0) {
            $blockers[] = [
                'code' => 'eldp_takeover_participation_conflict',
                'message' => "Převzatý měsíc {$label} je označen jako měsíc bez účasti na důchodovém "
                    . 'pojištění, ale nese vyměřovací základ; jedno z toho je chybně převzaté.'
                    . $where,
                'detail' => $detail,
            ];

            return null;
        }
        $base = $month->socialBaseMinor;
        if ($base < 0) {
            $blockers[] = [
                'code' => 'eldp_takeover_assessment_base_missing',
                'message' => "Převzatý měsíc {$label} má záporný vyměřovací základ sociálního pojištění."
                    . $where,
                'detail' => $detail,
            ];

            return null;
        }
        $absences = $takeover?->absencesFor($employmentId, $insuranceFrom, $insuranceTo) ?? [];
        /*
         * Nula dnů je u převzatého měsíce dvojznačná — může znamenat „původní
         * systém to nevydal" i „měsíc není dobou pojištění". Rozhoduje příznak
         * účasti; měsíc s účastí a nulovým základem pak § 11 odst. 2 zákona
         * č. 155/1995 Sb. nad evidovanými nepřítomnostmi.
         */
        $outside = false;
        if ($month->pensionParticipation && $base === 0) {
            $status = EldpExcludedPeriodDeriver::insuranceMonthStatus($absences, 0, $insuranceFrom, $insuranceTo);
            if ($status === EldpExcludedPeriodDeriver::MONTH_UNEXPLAINED) {
                $blockers[] = [
                    'code' => 'eldp_takeover_assessment_base_missing',
                    'message' => "Převzatý měsíc {$label} vykazuje dobu pojištění, ale nemá vyměřovací "
                        . 'základ sociálního pojištění a evidované nepřítomnosti to nevysvětlí '
                        . '(§ 11 odst. 2 zákona č. 155/1995 Sb.). Zaevidujte nepřítomnost s daty '
                        . 'v Mzdy → Nepřítomnosti, nebo doplňte základ u převzatého měsíce v Mzdy → '
                        . 'Kontrola převodu mezd.',
                    'detail' => $detail,
                ];

                return null;
            }
            $outside = $status === EldpExcludedPeriodDeriver::MONTH_OUTSIDE_INSURANCE;
        }
        $available = EldpExcludedPeriodDeriver::inclusiveDays($insuranceFrom, $insuranceTo);
        $days = $month->pensionParticipation && !$outside ? $month->insuranceDays : 0;
        if ($month->pensionParticipation && !$outside && $days <= 0) {
            $blockers[] = [
                'code' => 'eldp_takeover_insurance_days_missing',
                'message' => "Převzatý měsíc {$label} se účastní důchodového pojištění, ale nemá dny "
                    . 'účasti. Modul je z ničeho jiného neodvozuje.' . $where,
                'detail' => $detail,
            ];

            return null;
        }
        /*
         * Dny řádku s opraveným trváním vztahu odpovídají starému trvání; dobou
         * pojištění měsíce je ale interval podle opravy. Přijme se jen tehdy,
         * když převzaté dny přesně odpovídají starému intervalu — jiný rozdíl
         * by nebyl následkem opravy data, ale jiným údajem.
         */
        if ($superseded && $days > 0 && $days !== $available) {
            $ownSpan = $month->insuranceSpan();
            if ($ownSpan !== null && $days === EldpExcludedPeriodDeriver::inclusiveDays($ownSpan[0], $ownSpan[1])) {
                $days = $available;
            }
        }
        if ($days > $available) {
            $blockers[] = [
                'code' => 'eldp_takeover_insurance_days_exceed_period',
                'message' => "Převzatý měsíc {$label} vykazuje {$days} dnů účasti, ale pracovní vztah "
                    . "v něm trval jen {$available} dnů.",
                'detail' => $detail + ['insurance_days' => $days, 'available_days' => $available],
            ];

            return null;
        }
        /*
         * Vyloučené doby jdou do listu jen rozepsané na složky § 16 odst. 4
         * (nemoc, PPM, OČR, otcovská, ostatní) — převzatá data mají jen jejich
         * součet. Rozpad se proto odvodí z evidovaných nepřítomností měsíce
         * (týmž modulem jako u spočítaného měsíce, s krytím příjmem) a jeho úhrn
         * se musí shodovat s převzatým. Bez nepřítomností, nebo při rozporu,
         * zůstává blokátor: zařadit součet do jedné složky by byl vymyšlený údaj.
         */
        $excluded = self::noExcludedDays();
        if ($absences !== [] && !$outside && $month->pensionParticipation) {
            $excluded = $this->excludedPeriods->derive($absences, $insuranceFrom, $insuranceTo, $label, $base);
            foreach ($excluded['blockers'] as $blocker) {
                $blockers[] = array_replace_recursive($blocker, ['detail' => $detail]);
            }
            if ($excluded['blockers'] !== []) {
                return null;
            }
            if ($excluded['total'] !== $month->excludedDays) {
                $blockers[] = [
                    'code' => 'eldp_takeover_excluded_days_mismatch',
                    'message' => "Převzatý měsíc {$label} má {$month->excludedDays} dnů vyloučených dob, ale "
                        . "evidované nepřítomnosti jich podle § 16 odst. 4 zákona č. 155/1995 Sb. dávají "
                        . "{$excluded['total']}. Opravte nepřítomnosti v Mzdy → Nepřítomnosti, nebo "
                        . 'převzatý měsíc v Mzdy → Kontrola převodu mezd.',
                    'detail' => $detail + [
                        'excluded_days' => $month->excludedDays,
                        'derived_excluded_days' => $excluded['total'],
                    ],
                ];

                return null;
            }
        } elseif ($month->excludedDays !== 0) {
            $blockers[] = [
                'code' => 'eldp_takeover_excluded_days_breakdown_missing',
                'message' => "Převzatý měsíc {$label} má {$month->excludedDays} dnů vyloučených dob, "
                    . 'ale ne jejich rozpad podle § 16 odst. 4 zákona č. 155/1995 Sb. '
                    . 'Zaevidujte odpovídající nepřítomnosti s daty v Mzdy → Nepřítomnosti, '
                    . 'nebo evidenční list podejte mimo aplikaci.',
                'detail' => $detail + ['excluded_days' => $month->excludedDays],
            ];

            return null;
        }
        if ($excluded['total'] > $days) {
            $blockers[] = [
                'code' => 'eldp_excluded_days_exceed_period',
                'message' => "Vyloučené doby za {$label} přesahují dobu pojištění v měsíci.",
                'detail' => $detail,
            ];

            return null;
        }
        if ($base % 100 !== 0 || intdiv($base, 100) > 9_999_999_999) {
            $blockers[] = [
                'code' => 'eldp_takeover_assessment_base_not_whole_czk',
                'message' => "Vyměřovací základ převzatého měsíce {$label} není celé Kč v rozsahu "
                    . 'datové věty.' . $where,
                'detail' => $detail,
            ];

            return null;
        }

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'source' => 'takeover',
            'takeover' => [
                'period_start' => $periodStart,
                'source' => $month->source,
                'external_person_ref' => $month->externalPersonRef,
                'external_relationship_ref' => $month->externalRelationshipRef,
                'import_reference' => $month->importReference,
                // Otisk přesně toho řádku, ze kterého měsíc vznikl — protějšek
                // otisků snapshotů mzdové revize v `source_revisions`.
                'row_sha256' => hash('sha256', CanonicalJson::encode($month->toArray())),
                // Odchylky od převzatého řádku, jen kde nastaly (otisk ostatních
                // listů se nemění): opravené trvání vztahu a měsíc mimo dobu
                // pojištění podle evidovaných nepřítomností.
                ...($superseded ? ['dates_superseded_by_later_row' => true] : []),
                ...($outside ? ['outside_insurance_by_absences' => true] : []),
            ],
            'insurance_from' => $insuranceFrom,
            'insurance_to' => $insuranceTo,
            'insurance_days' => $days,
            'assessment_base_czk' => intdiv($base, 100),
            'code' => $code,
            'participates' => $month->pensionParticipation,
            'outside_insurance' => !$month->pensionParticipation || $outside,
            'post_termination' => false,
            'excluded' => $excluded,
            // Odečtené doby převzatý měsíc neumí doložit; kód D nad ním blokuje.
            'deductible' => null,
        ];
    }

    /**
     * Opravil převzatý řádek s jiným trváním vztahu pozdější řádek téhož vztahu?
     *
     * Převod přebírá trvání vztahu z každého zpracovaného měsíce zvlášť (u PAMICA
     * `DatOdch` záznamu `MZ`), tedy jak ho původní program znal v době zpracování.
     * Když program skončení vztahu později opraví, starší měsíc dál nese původní
     * datum a novější měsíc nové. Platí poslední verze: řádek je opravený, když
     * nejpozdější převzatý řádek vztahu s vyplněným datem nese přesně trvání
     * listu a žádný DŘÍVĚJŠÍ řádek ho ještě nenesl. Odchylka uprostřed řady,
     * kterou obklopují řádky s platným trváním, opravou není (program by se
     * k původnímu datu musel vrátit) a dál blokuje.
     *
     * @param list<PayrollTakeoverMonth> $rows
     * @param array{employee_id:int,start:string,end:string|null} $employment
     */
    private static function supersededTakeoverDates(array $rows, PayrollTakeoverMonth $month, array $employment): bool
    {
        $latest = null;
        $finalBefore = false;
        $matchesFinal = static fn (PayrollTakeoverMonth $row): bool =>
            ($row->relationshipStartDate ?? $employment['start']) === $employment['start']
            && $row->relationshipEndDate === $employment['end'];
        foreach ($rows as $row) {
            if ($row->relationshipStartDate === null && $row->relationshipEndDate === null) {
                continue;
            }
            if ($latest === null || $row->period > $latest->period) {
                $latest = $row;
            }
            if ($row->period < $month->period && $matchesFinal($row)) {
                $finalBefore = true;
            }
        }

        return $latest !== null
            && !$finalBefore
            && $latest->period > $month->period
            && $matchesFinal($latest);
    }

    /**
     * Výslovně potvrzené důchodové údaje zaměstnance.
     *
     * Všechny čtyři klíče musí přijít, i když jsou prázdné: „nic se nezadalo"
     * a „zaměstnanec důchod nepobírá" se jinak nedají rozlišit, a právě na tom
     * stojí kód ELDP i to, zda se list vůbec vede.
     *
     * @return array{
     *   pension_age_reached_on:?string,early_pension_from:?string,
     *   full_pension_paid_from:?string,foreign_insurance:bool
     * }
     */
    private static function pensionStatus(mixed $value): array
    {
        $keys = [
            'pension_age_reached_on',
            'early_pension_from',
            'full_pension_paid_from',
            'foreign_insurance',
        ];
        if (!is_array($value)
            || array_diff($keys, array_keys($value)) !== []
        ) {
            throw new EldpValidationException(
                'eldp_pension_status_not_confirmed',
                'Potvrďte důchodové údaje zaměstnance: zda a kdy ve vykazovaném roce '
                    . 'dosáhl důchodového věku, zda pobírá předčasný starobní důchod, '
                    . 'od kterého měsíce mu náleží výplata starobního důchodu v plné výši '
                    . 'a zda je účasten důchodového pojištění v cizině. Na těchto údajích '
                    . 'závisí kód ELDP i to, zda se evidenční list vůbec vede.',
            );
        }
        foreach (['pension_age_reached_on', 'early_pension_from'] as $key) {
            $date = $value[$key];
            if ($date !== null && (!is_string($date) || !self::isDate($date))) {
                throw new EldpValidationException(
                    'eldp_pension_status_invalid',
                    'Den dosažení důchodového věku a den přiznání předčasného starobního '
                        . 'důchodu musí být prázdné, nebo platné datum RRRR-MM-DD.',
                );
            }
        }
        $paidFrom = $value['full_pension_paid_from'];
        if ($paidFrom !== null
            && (!is_string($paidFrom)
                || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $paidFrom) !== 1)
        ) {
            throw new EldpValidationException(
                'eldp_pension_status_invalid',
                'První měsíc výplaty starobního důchodu v plné výši musí být prázdný, '
                    . 'nebo měsíc ve tvaru RRRR-MM.',
            );
        }
        if (!is_bool($value['foreign_insurance'])) {
            throw new EldpValidationException(
                'eldp_pension_status_invalid',
                'Účast na důchodovém pojištění v cizině musí být výslovně ano, nebo ne.',
            );
        }

        return [
            'pension_age_reached_on' => $value['pension_age_reached_on'],
            'early_pension_from' => $value['early_pension_from'],
            'full_pension_paid_from' => $paidFrom,
            'foreign_insurance' => $value['foreign_insurance'],
        ];
    }

    /**
     * Ověřená sleva pracujícího důchodce (§ 7d zákona č. 589/1992 Sb.) náleží
     * jen poživateli starobního důchodu. Potvrzení, které v témž roce žádný
     * starobní důchod neuvádí, si s ní odporuje: list by vyšel s kódem „++"
     * a za měsíce, za které se nevede.
     *
     * @param array<string,array<string,mixed>> $months
     * @param array{early_pension_from:?string,full_pension_paid_from:?string} $pension
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     */
    private function assertPensionStatusMatchesEvidence(
        array $months,
        int $employeeId,
        int $employmentId,
        array $pension,
        array &$blockers,
    ): void {
        if ($pension['early_pension_from'] !== null
            || $pension['full_pension_paid_from'] !== null
        ) {
            return;
        }
        foreach ($months as $periodStart => $month) {
            $input = $month['input'] ?? null;
            if (is_array($input)
                && JmhzEldpEvidenceBuilder::workingPensioner($input, $employeeId)
            ) {
                $blockers[] = [
                    'code' => 'eldp_pension_status_conflict',
                    'message' => 'Zákonná evidence má za ' . self::monthLabel((string) $periodStart)
                        . ' ověřenou slevu pracujícího důchodce, ale potvrzení evidenčního '
                        . 'listu neuvádí žádný starobní důchod. Doplňte první měsíc výplaty '
                        . 'starobního důchodu, nebo den přiznání předčasného důchodu.',
                    'detail' => [
                        'period_start' => (string) $periodStart,
                        'employment_id' => $employmentId,
                    ],
                ];

                return;
            }
        }
    }

    /**
     * Od kterého měsíce se list za poživatele starobního důchodu v plné výši
     * nevede; `null`, když se pravidlo na rok nebo osobu nevztahuje.
     *
     * @param array{full_pension_paid_from:?string,foreign_insurance:bool} $pension
     */
    private static function fullPensionNotKeptFrom(int $year, array $pension): ?string
    {
        if ($year < self::FULL_PENSION_EXCLUSION_FROM_YEAR
            || $pension['full_pension_paid_from'] === null
            || $pension['foreign_insurance']
        ) {
            return null;
        }

        return $pension['full_pension_paid_from'] . '-01';
    }

    /**
     * Druhý znak kódu ELDP „D" pro dobu od dovršení důchodového věku nebo od
     * přiznání předčasného starobního důchodu (číselník kódů ELDP, ID 10240).
     * Pravidlo je společné s měsíčním hlášením JMHZ ({@see EldpPensionAgeCode}).
     *
     * Měsíc, uvnitř kterého kód začíná, se nerozděluje: výpočet vede
     * vyměřovací základ jen za celý měsíc a rozdělit ho na dvě sekce by byl
     * vymyšlený údaj v zákonné evidenci.
     *
     * Měsíc s kódem D nese odečtené doby (§ 38 odst. 4 písm. h) zákona
     * č. 582/1991 Sb., údaj 40 ELDP) odvozené z nepřítomností
     * ({@see EldpExcludedPeriodDeriver::deriveDeducted()}) a dny pojištění jsou
     * interval Od–Do minus odečtené doby (Všeobecné zásady ELDP, údaj Dny).
     * Převzatý měsíc nepřítomnosti v potřebném rozsahu doložit neumí, takže kód
     * D nad ním blokuje.
     *
     * @param list<array<string,mixed>> $lines
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return list<array<string,mixed>>
     */
    private function applyPensionAgeCode(
        array $lines,
        string $codeFrom,
        int $employmentId,
        array &$blockers,
    ): array {
        foreach ($lines as $index => $line) {
            $from = $line['insurance_from'];
            $to = $line['insurance_to'];
            if ($line['post_termination'] === true || !is_string($from) || !is_string($to)) {
                continue;
            }
            $placement = EldpPensionAgeCode::placement($codeFrom, $from, $to);
            if ($placement === EldpPensionAgeCode::PENSION_AGE) {
                $lines[$index]['code'] = EldpPensionAgeCode::withPensionAge((string) $line['code']);
                $deductible = $line['deductible'] ?? null;
                if ($deductible === null) {
                    $blockers[] = [
                        'code' => 'eldp_deducted_days_unknown',
                        'message' => 'Za ' . self::monthLabel((string) $line['period_start'])
                            . ' platí kód D (po dovršení důchodového věku), ale převzatý měsíc '
                            . 'nedoloží odečtené doby (neplacené volno, neomluvenou absenci, '
                            . 'omluvné důvody, § 38 odst. 4 písm. h) zákona č. 582/1991 Sb.). '
                            . 'Evidenční list podejte mimo aplikaci.',
                        'detail' => [
                            'period_start' => (string) $line['period_start'],
                            'employment_id' => $employmentId,
                        ],
                    ];
                    continue;
                }
                $lines[$index]['deducted'] = $deductible;
                $lines[$index]['insurance_days'] = EldpExcludedPeriodDeriver::inclusiveDays($from, $to)
                    - (int) $deductible['total'];
                continue;
            }
            if ($placement === EldpPensionAgeCode::MID_INTERVAL) {
                $label = self::monthLabel((string) $line['period_start']);
                $blockers[] = [
                    'code' => 'eldp_pension_age_mid_month_unsupported',
                    'message' => "Kód ELDP se mění uprostřed měsíce {$label} (od {$codeFrom}: "
                        . 'dovršení důchodového věku nebo předčasný starobní důchod). '
                        . 'Vyměřovací základ za část měsíce výpočet nevede, takže měsíc nejde '
                        . 'rozdělit na dvě sekce; evidenční list podejte mimo aplikaci.',
                    'detail' => [
                        'period_start' => (string) $line['period_start'],
                        'employment_id' => $employmentId,
                        'code_from' => $codeFrom,
                    ],
                ];
            }
        }

        return $lines;
    }

    /**
     * Nemoc, karanténa a mateřství v měsíci bez účasti (DPP, zaměstnání malého
     * rozsahu, DPČ pod rozhodnou částkou).
     *
     * Nárok na dávku, a tedy vyloučená doba podle § 16 odst. 4 písm. a) zákona
     * č. 155/1995 Sb., vzniká, když sociální událost začala v měsíci s účastí,
     * nebo podle § 15a zákona č. 187/2006 Sb. (od 1. 2. 2018), když vztah byl
     * účasten pojištění ve třech kalendářních měsících bezprostředně před
     * měsícem, v němž událost vznikla. Pak se vyloučená doba vykáže v rozsahu
     * trvání vztahu, započte se do dnů a měsíc zůstane označen „X" (Metodická
     * pomůcka ČSSZ k ELDP, př. 31 a 38; Zadání ELDP12, údaj Dny: u specifické
     * doby Dny = Vyloučené doby). Bez nároku se doba nevykazuje (př. 32).
     *
     * Účast v měsících před vykazovaným rokem list nezná; nedá-li se nárok bez
     * nich rozhodnout, blokuje.
     *
     * @param list<array<string,mixed>> $lines
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return list<array<string,mixed>>
     */
    private function applySection15a(array $lines, int $year, string $employmentStart, int $employmentId, array &$blockers): array
    {
        $participation = [];
        foreach ($lines as $line) {
            $participation[substr((string) $line['period_start'], 0, 7)] = $line['participates'] === true;
        }
        $yearStart = sprintf('%04d-01', $year);
        $startPeriod = substr($employmentStart, 0, 7);
        $participates = static function (string $period) use ($participation, $yearStart, $startPeriod): ?bool {
            if (isset($participation[$period])) {
                return $participation[$period];
            }
            // Měsíc před nástupem nebo mimo vztah účastný nebyl; měsíc trvání
            // vztahu před vykazovaným rokem list nezná.
            if ($period < $startPeriod || $period >= $yearStart) {
                return false;
            }

            return null;
        };
        foreach ($lines as $index => $line) {
            $candidates = $line['section_15a_absences'] ?? [];
            unset($lines[$index]['section_15a_absences']);
            if ($candidates === []) {
                continue;
            }
            $entitled = [];
            $decisions = [];
            foreach ($candidates as $absence) {
                $eventMonth = substr((string) $absence['date_from'], 0, 7);
                $eventParticipates = $participates($eventMonth);
                $preceding = [];
                for ($back = 1; $back <= 3; ++$back) {
                    $preceding[] = (new \DateTimeImmutable($eventMonth . '-01'))
                        ->modify("-{$back} month")->format('Y-m');
                }
                $precedingParticipation = array_map($participates, $preceding);
                if ($eventParticipates === null
                    || ($eventParticipates === false && in_array(null, $precedingParticipation, true))
                ) {
                    $blockers[] = [
                        'code' => 'eldp_section_15a_history_unavailable',
                        'message' => 'V měsíci ' . self::monthLabel((string) $line['period_start'])
                            . ' bez účasti na pojištění trvala nemoc, karanténa nebo mateřství (#'
                            . $absence['id'] . '). Zda je vyloučenou dobou, rozhoduje účast '
                            . 'v měsících před vznikem události (§ 15a zákona č. 187/2006 Sb.), '
                            . 'které leží před vykazovaným rokem. Evidenční list podejte mimo aplikaci.',
                        'detail' => [
                            'period_start' => (string) $line['period_start'],
                            'employment_id' => $employmentId,
                            'absence_id' => $absence['id'],
                        ],
                    ];
                    continue;
                }
                $rule = $eventParticipates
                    ? 'event_in_participating_month'
                    : (!in_array(false, $precedingParticipation, true) ? 'section_15a' : null);
                $decisions[] = [
                    'absence_id' => $absence['id'],
                    'event_month' => $eventMonth,
                    'entitled' => $rule !== null,
                    'rule' => $rule ?? 'no_entitlement',
                ];
                if ($rule !== null) {
                    $entitled[] = $absence;
                }
            }
            if ($decisions === []) {
                continue;
            }
            $lines[$index]['section_15a'] = $decisions;
            if ($entitled === []) {
                continue;
            }
            $excluded = $this->excludedPeriods->derive(
                $entitled,
                (string) $line['insurance_from'],
                (string) $line['insurance_to'],
                self::monthLabel((string) $line['period_start']),
            );
            foreach ($excluded['blockers'] as $blocker) {
                $blocker['detail']['period_start'] = $line['period_start'];
                $blocker['detail']['employment_id'] = $employmentId;
                $blockers[] = $blocker;
            }
            $lines[$index]['excluded'] = $excluded;
            $lines[$index]['insurance_days'] = $excluded['total'];
        }

        return $lines;
    }

    /**
     * Nemoc, karanténa a mateřství (jen předporodní část) v měsíci bez účasti,
     * o kterých rozhoduje {@see self::applySection15a()}.
     *
     * @param list<array<string,mixed>> $absences
     * @return list<array<string,mixed>>
     */
    private static function section15aAbsences(array $absences, string $from, string $to): array
    {
        return array_values(array_filter(
            $absences,
            static fn (array $absence): bool => in_array($absence['absence_type'] ?? null, ['dpn', 'quarantine', 'ppm'], true)
                && is_string($absence['date_from'] ?? null)
                && is_string($absence['date_to'] ?? null)
                && $absence['date_from'] <= $to
                && $absence['date_to'] >= $from,
        ));
    }

    /**
     * Pracovní vztahy téže osoby ze zmrazených revizí roku: trvání a druh.
     *
     * Pozdější měsíc přepíše dřívější (trvání se během roku upřesňuje, např.
     * doplněné skončení). Nejednoznačné trvání vlastního vztahu blokuje
     * {@see self::resolveEmployment()}; tady jde jen o to, zda na sebe vztahy
     * navazují.
     *
     * @param array<string,array<string,mixed>> $months
     * @return array<int,array{start:string,end:?string,relation:?string}>
     */
    private static function employeeEmployments(array $months, int $employeeId): array
    {
        $found = [];
        foreach ($months as $month) {
            $people = is_array($month['input'] ?? null) ? ($month['input']['people'] ?? []) : [];
            foreach (is_array($people) ? $people : [] as $person) {
                if (!is_array($person) || (($person['employee'] ?? [])['id'] ?? null) !== $employeeId) {
                    continue;
                }
                foreach ((array) ($person['employments'] ?? []) as $entry) {
                    $other = is_array($entry) ? ($entry['employment'] ?? null) : null;
                    if (!is_array($other) || !is_int($other['id'] ?? null)) {
                        continue;
                    }
                    $start = $other['actual_start_date'] ?? $other['start_date'] ?? null;
                    $end = $other['end_date'] ?? null;
                    if (!is_string($start) || !self::isDate($start)
                        || ($end !== null && (!is_string($end) || !self::isDate($end)))
                    ) {
                        continue;
                    }
                    $found[$other['id']] = [
                        'start' => $start,
                        'end' => $end,
                        'relation' => is_string($other['relation_type'] ?? null) ? $other['relation_type'] : null,
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * Poslední den, kdy nový nástup ještě navazuje na skončenou činnost:
     * tři měsíce po skončení, nejpozději 31. prosince téhož roku (Všeobecné
     * zásady ELDP, Hlavní zásady: „ve stejném kalendářním roce do tří měsíců").
     */
    private static function continuationLimit(string $end, int $year): string
    {
        $endDate = new \DateTimeImmutable($end);
        $limit = $endDate->modify('+3 months');
        if ($limit->format('d') !== $endDate->format('d')) {
            $limit = $endDate->modify('first day of +3 months')->modify('last day of this month');
        }

        return min($limit->format('Y-m-d'), sprintf('%04d-12-31', $year));
    }

    /**
     * Navazuje vztah na skončenou činnost téže osoby ve stejném roce?
     *
     * @param array{start:string,end:?string} $earlier
     */
    private static function continues(array $earlier, string $laterStart, int $year): bool
    {
        /*
         * Pravidlo „list se neuzavírá" patří k roční povinnosti zaměstnavatele,
         * tedy k rokům do 2025. Od roku 2026 jde navazující doba měsíčním
         * hlášením a samostatný list vzniká jen za skončenou účast (čl. V bod 8
         * zák. č. 360/2025 Sb.) nebo na výzvu — každý vztah zvlášť.
         */
        if ($year > EldpDeadlinePolicy::LAST_ANNUAL_YEAR) {
            return false;
        }
        $end = $earlier['end'];

        return $end !== null
            && substr($end, 0, 4) === sprintf('%04d', $year)
            && $laterStart > $end
            && $laterStart <= self::continuationLimit($end, $year);
    }

    /**
     * Vztah, který navazuje na dřívější skončenou činnost, nemá vlastní list:
     * jeho doba patří do dalších řádků listu dřívějšího vztahu (Všeobecné
     * zásady ELDP, Hlavní zásady). Výjimkou je dřívější vztah, za který už
     * samostatný list zmrazený je — na odeslaný list se pokračovat nedá
     * a navazující doba dostane list vlastní.
     *
     * @param array<int,array{start:string,end:?string,relation:?string}> $employments
     * @param array{employee_id:int,start:string,end:?string} $employment
     * @param list<int> $separatelyFiled
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     */
    private function assertNotContinuationOfEarlierEmployment(
        array $employments,
        array $employment,
        int $employmentId,
        int $year,
        array $separatelyFiled,
        array &$blockers,
    ): void {
        $previous = null;
        foreach ($employments as $otherId => $other) {
            if ($otherId === $employmentId
                || in_array($otherId, $separatelyFiled, true)
                || !self::continues($other, $employment['start'], $year)
            ) {
                continue;
            }
            if ($previous === null || (string) $other['end'] > (string) $employments[$previous]['end']) {
                $previous = $otherId;
            }
        }
        if ($previous === null) {
            return;
        }
        $previousEnd = (string) $employments[$previous]['end'];
        $blockers[] = [
            'code' => 'eldp_statement_continues_previous_employment',
            'message' => 'Pracovní vztah začal ' . $employment['start'] . ', do tří měsíců od skončení '
                . 'vztahu #' . $previous . ' (' . $previousEnd . ') ve stejném roce. Jeho doba se '
                . 'zapisuje do dalších řádků evidenčního listu vztahu #' . $previous . ' (Všeobecné '
                . 'zásady ELDP); připravte list u vztahu #' . $previous . '.',
            'detail' => [
                'employment_id' => $employmentId,
                'previous_employment_id' => $previous,
                'previous_end_date' => $previousEnd,
            ],
        ];
    }

    /**
     * Vztahy téže osoby, které na sebe od `$employment` navazují; v pořadí
     * nástupu. Souběžné vztahy (nástup před skončením) do řetězu nepatří,
     * každý má samostatný list (Všeobecné zásady ELDP, Hlavní zásady).
     *
     * @param array<int,array{start:string,end:?string,relation:?string}> $employments
     * @param array{employee_id:int,start:string,end:?string} $employment
     * @param list<int> $separatelyFiled
     * @return list<int>
     */
    private function continuingEmployments(
        array $employments,
        array $employment,
        int $employmentId,
        int $year,
        array $separatelyFiled,
    ): array {
        $chain = [];
        $current = ['start' => $employment['start'], 'end' => $employment['end']];
        $seen = [$employmentId => true];
        while (true) {
            $next = null;
            foreach ($employments as $otherId => $other) {
                if (isset($seen[$otherId])
                    || in_array($otherId, $separatelyFiled, true)
                    || !self::continues($current, $other['start'], $year)
                ) {
                    continue;
                }
                if ($next === null || $other['start'] < $employments[$next]['start']) {
                    $next = $otherId;
                }
            }
            if ($next === null) {
                return $chain;
            }
            $chain[] = $next;
            $seen[$next] = true;
            $current = $employments[$next];
        }
    }

    /**
     * Označí řádky vztahem a jeho druhem — podle nich {@see self::sections()}
     * pozná, zda navazující doba pokračuje týmž řádkem listu.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<int,array{start:string,end:?string,relation:?string}> $employments
     * @return list<array<string,mixed>>
     */
    private static function withEmployment(array $lines, int $employmentId, array $employments): array
    {
        foreach ($lines as $index => $line) {
            $lines[$index]['employment_id'] = $employmentId;
            $lines[$index]['relation'] = $employments[$employmentId]['relation'] ?? null;
        }

        return $lines;
    }

    /**
     * Typ ELDP v záhlaví (Všeobecné zásady ELDP, Typ ELDP): 03 při úmrtí,
     * 02 když činnost ve vykazovaném roce skončila, a to i přesně 31. prosince
     * nebo dříve (list jen s dodatečně zúčtovaným příjmem), jinak 01. Opravný
     * list nese 5 a druhou číslici opravovaného typu ({@see self::form()}).
     * Typ je nezávislý na lhůtě: konec 31. 12. má řádnou roční lhůtu, ale typ 02.
     */
    private static function eldpType(?string $employmentEnd, int $year, ?string $deathOn): string
    {
        if ($deathOn !== null) {
            return '03';
        }

        return $employmentEnd !== null && $employmentEnd <= sprintf('%04d-12-31', $year) ? '02' : '01';
    }

    private static function missingMonthMessage(
        string $label,
        bool $hasTakeover,
        bool $withoutAnySource,
    ): string {
        if (!$hasTakeover) {
            return "Chybí schválená mzdová revize za {$label} — "
                . 'bez ní nelze doložit dobu pojištění ani vyměřovací základ.';
        }
        if ($withoutAnySource) {
            return "Za {$label} není ani schválená mzdová revize, ani převzatý mzdový měsíc — "
                . 'bez jednoho z nich nelze doložit dobu pojištění ani vyměřovací základ. '
                . 'Měsíc doplňte v Mzdy → Kontrola převodu mezd, nebo mzdu spočítejte a schvalte.';
        }

        return "Mzdu za {$label} počítá MyÚčto, ale nemá schválenou mzdovou revizi; "
            . 'bez ní nelze doložit dobu pojištění ani vyměřovací základ.';
    }

    /**
     * Údaje tiskopisu, které datová věta JMHZ `eldpType` nenese, ale ČSSZ je
     * u samostatného evidenčního listu čte: typ listu, „zaměstnán od" a datum
     * vyhotovení. Kontrolní XML je proto nemá a obrazovka je ukazuje k opisu.
     *
     * - **Typ** podle {@see self::eldpType()}: `01` = činnost trvá, `02` =
     *   činnost v roce skončila (i k 31. 12.), `03` = úmrtí. Opravný list nese
     *   `5` + druhou číslici opravovaného typu (`51`, `52`, `53`) a odkaz na
     *   opravovaný list.
     * - **Datum vyhotovení** nesmí předcházet údaji „Do" žádného řádku — ČSSZ
     *   takový list odmítne chybou 251 („Datum vyhotovení ELDP předchází datum
     *   v údaji Do"). Nezadá-li ho účetní, bere se nejdřívější přípustné datum:
     *   konec posledního zúčtovaného měsíce. Den přípravy by rozbil
     *   idempotenci — list připravený znovu jiný den by měl jiný obsah.
     *
     * @param array<string,mixed> $confirmation
     * @param list<array<string,mixed>> $sections
     * @return array<string,mixed>
     */
    private function form(
        array $confirmation,
        string $type,
        string $employedFrom,
        array $sections,
        string $lastSettlementEnd,
    ): array {
        $latestTo = null;
        foreach ($sections as $section) {
            if (is_string($section['valid_to'])
                && ($latestTo === null || $section['valid_to'] > $latestTo)
            ) {
                $latestTo = $section['valid_to'];
            }
        }
        $preparedOn = $confirmation['prepared_on'] ?? null;
        if ($preparedOn === null || $preparedOn === '') {
            $preparedOn = max($latestTo ?? $lastSettlementEnd, $lastSettlementEnd);
        }
        if (!is_string($preparedOn) || !self::isDate($preparedOn)) {
            throw new EldpValidationException(
                'eldp_prepared_on_invalid',
                'Datum vyhotovení evidenčního listu musí být platné datum RRRR-MM-DD.',
            );
        }
        if ($latestTo !== null && $preparedOn < $latestTo) {
            throw new EldpValidationException(
                'eldp_prepared_on_before_period_end',
                "Datum vyhotovení {$preparedOn} předchází datu „Do“ ({$latestTo}); ČSSZ "
                    . 'takový evidenční list odmítne chybou 251. Zadejte datum vyhotovení '
                    . 'nejdříve v den „Do“.',
            );
        }
        /*
         * Logický test ELDP12 č. 54: datum vyhotovení nesmí být pozdější než
         * datum přijetí listu. List s datem v budoucnosti ČSSZ odmítne; týká se
         * i výchozího data (konec posledního zúčtovaného měsíce), když se list
         * připravuje před jeho koncem.
         */
        $today = $this->clock === null ? null : PayrollSubmissionCalendar::today($this->clock->now());
        if ($today !== null && $preparedOn > $today) {
            throw new EldpValidationException(
                'eldp_prepared_on_future',
                "Datum vyhotovení {$preparedOn} je v budoucnosti; ČSSZ list s datem vyhotovení "
                    . 'pozdějším než den přijetí odmítne (logický test 54). Zadejte datum '
                    . 'vyhotovení mezi datem „Do“ a dneškem.',
            );
        }
        $corrects = $confirmation['corrects'] ?? null;
        $correction = null;
        if ($corrects !== null) {
            $correctedType = is_array($corrects) ? ($corrects['eldp_type'] ?? null) : null;
            $correctedId = is_array($corrects) ? ($corrects['statement_id'] ?? null) : null;
            $correctedOn = is_array($corrects) ? ($corrects['prepared_on'] ?? null) : null;
            if (!in_array($correctedType, ['01', '02', '03'], true)
                || !is_int($correctedId)
                || $correctedId <= 0
                || ($correctedOn !== null && (!is_string($correctedOn) || !self::isDate($correctedOn)))
            ) {
                throw new EldpValidationException(
                    'eldp_correction_reference_invalid',
                    'Opravný evidenční list musí odkazovat na zmrazený opravovaný list.',
                );
            }
            $type = '5' . substr($correctedType, 1);
            $correction = [
                'statement_id' => $correctedId,
                'eldp_type' => $correctedType,
                'prepared_on' => $correctedOn,
            ];
        }

        return [
            'eldp_type' => $type,
            'employed_from' => $employedFrom,
            'prepared_on' => $preparedOn,
            'corrects' => $correction,
        ];
    }

    /**
     * Sekce evidenčního listu: souvislé měsíce se stejným kódem ELDP.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function sections(array $lines): array
    {
        $sections = [];
        $current = null;
        $previous = null;
        $postTermination = [];
        foreach ($lines as $line) {
            if ($line['post_termination'] === true) {
                $postTermination[$line['code']][] = $line;
                continue;
            }
            /*
             * Údaj „Od" u zaměstnání malého rozsahu a DPP je den vzniku účasti,
             * a vznikne-li účast až v dalším měsíci po nástupu, první den toho
             * měsíce (Všeobecné zásady, údaj „Od" – „Do"; Metodická pomůcka
             * př. 33). Měsíce před vznikem účasti proto řádek neotevírají
             * a nevyznačují se „X" — tak je nevykazuje ani měsíční hlášení
             * ({@see JmhzEldpEvidenceBuilder}, měsíc bez účasti nemá interval).
             * Měsíc bez účasti s vyloučenou dobou podle § 15a zákona
             * č. 187/2006 Sb. dobou je, a řádek tedy otevírá. Totéž platí
             * pro navazující vztah téhož listu.
             */
            $newEmployment = $previous === null
                || ($previous['employment_id'] ?? null) !== ($line['employment_id'] ?? null);
            if ($newEmployment && self::beforeParticipationArose($line)) {
                continue;
            }
            $smallScale = ($line['small_scale'] ?? false) === true;
            $continues = $current !== null
                && $current['code'] === $line['code']
                && ($current['small_scale'] ?? false) === $smallScale
                && (new \DateTimeImmutable($current['valid_to']))
                    ->modify('+1 day')->format('Y-m-d') === $line['insurance_from']
                && (!$newEmployment || self::continuesInOneRow($previous, $line));
            $previous = $line;
            if (!$continues) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = [
                    'ordinal' => count($sections) + 1,
                    'code' => $line['code'],
                    'valid_from' => $line['insurance_from'],
                    'valid_to' => $line['insurance_to'],
                    'insurance_days' => 0,
                    'assessment_base_czk' => 0,
                    'excluded_days' => array_fill_keys(
                        EldpExcludedPeriodDeriver::COMPONENTS,
                        0,
                    ),
                    'excluded_days_total' => 0,
                    'deducted_days_total' => 0,
                    'excluded_days_provenance' => [],
                    'months_without_insurance' => [],
                ];
                /*
                 * ELDP12 údaj 21 (42, 63) „MR": A = zaměstnání malého rozsahu
                 * (§ 7 zákona č. 187/2006 Sb.), N = jinak, i u DPP. Klíč jen
                 * u řádku zaměstnání malého rozsahu, chybějící znamená N:
                 * otisk ostatních listů zůstává stejný.
                 */
                if ($smallScale) {
                    $current['small_scale'] = true;
                }
            }
            /** @var array<string,mixed> $current */
            $current['valid_to'] = $line['insurance_to'];
            /*
             * Měsíc uvnitř sekce, který není dobou pojištění (§ 11 odst. 2
             * zákona č. 155/1995 Sb., DPČ/DPP bez účasti), se v tiskopisu
             * vyznačuje „X" v řádku měsíců. Datová věta JMHZ `eldpType` pro to
             * prvek nemá; údaj proto nese podklad a obrazovka evidenčního listu.
             */
            if (($line['outside_insurance'] ?? $line['insurance_days'] === 0) === true) {
                $current['months_without_insurance'][] = (int) substr(
                    (string) $line['period_start'],
                    5,
                    2,
                );
            }
            $current['insurance_days'] += $line['insurance_days'];
            $current['assessment_base_czk'] += $line['assessment_base_czk'];
            foreach ($line['excluded']['components'] as $key => $value) {
                $current['excluded_days'][$key] += $value;
            }
            $current['excluded_days_total'] += $line['excluded']['total'];
            $current['deducted_days_total'] += (int) ($line['deducted']['total'] ?? 0);
            foreach ($line['excluded']['provenance'] as $item) {
                $current['excluded_days_provenance'][] = $item + [
                    'period_start' => $line['period_start'],
                ];
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }
        /*
         * Údaj „1-12" (ELDP12 údaj 37, 58, 79; Všeobecné zásady, údaje 1 až 12):
         * nepřichází-li po celý kalendářní rok v úvahu zápočet ani jednoho dne
         * pojištění (např. celoroční rodičovská dovolená), vyznačí se X ve
         * třináctém prostoru místo dvanácti X u jednotlivých měsíců. Seznam
         * měsíců zůstává jako podklad (potvrzení o době pojištění ho čte),
         * tiskopis a údaje k opisu ukážou jen „1-12". Klíč jen tam, kde nastal:
         * otisk ostatních listů zůstává stejný.
         */
        foreach ($sections as $index => $section) {
            if (self::wholeYearWithoutInsurance($section)) {
                $sections[$index]['whole_year_without_insurance'] = true;
            }
        }
        $sections = self::moveBaseIntoPensionAgeSection($sections);
        /*
         * Dodatečně zúčtovaný příjem po skončení vztahu tvoří vlastní řádek
         * bez „Od", „Do" a dnů — jen s kódem „P+" a vyměřovacím základem.
         */
        foreach ($postTermination as $code => $postLines) {
            $sections[] = [
                'ordinal' => count($sections) + 1,
                'code' => (string) $code,
                'valid_from' => null,
                'valid_to' => null,
                'insurance_days' => 0,
                'assessment_base_czk' => array_sum(array_column($postLines, 'assessment_base_czk')),
                'excluded_days' => array_fill_keys(EldpExcludedPeriodDeriver::COMPONENTS, 0),
                'excluded_days_total' => 0,
                'deducted_days_total' => 0,
                'excluded_days_provenance' => [],
                'months_without_insurance' => [],
                'post_termination_periods' => array_values(array_column($postLines, 'period_start')),
            ];
        }
        foreach ($sections as $section) {
            $componentSum = array_sum($section['excluded_days']);
            if ($componentSum !== $section['excluded_days_total']) {
                throw new EldpValidationException(
                    'eldp_excluded_days_sum_mismatch',
                    'Součet vyloučených dob neodpovídá rozpadu podle § 16 odst. 4 '
                        . 'písm. a) a j) zákona č. 155/1995 Sb.',
                );
            }
            $code = (string) $section['code'];
            $second = $code[strlen($code) - 2] ?? '';
            $third = $code[strlen($code) - 1] ?? '';
            /*
             * U kódu D jsou vyloučené doby podmnožinou odečtených (logický
             * test ELDP12 č. 39) a dny pojištění jsou interval minus odečtené
             * doby, takže vyloučené doby počet dnů přesáhnout smějí (test 37
             * se na kód D nevztahuje).
             */
            if ($second === 'D') {
                if ($section['excluded_days_total'] > $section['deducted_days_total']) {
                    throw new EldpValidationException(
                        'eldp_excluded_days_exceed_deducted',
                        'Vyloučené doby sekce s kódem D přesahují odečtené doby (logický test ELDP12 č. 39).',
                    );
                }
                // Logický test 43 a kontrola JMHZ 59 část 4.
                if ($section['insurance_days'] === 0
                    && $section['deducted_days_total'] === $section['excluded_days_total']
                    && $section['assessment_base_czk'] !== 0
                ) {
                    throw new EldpValidationException(
                        'eldp_base_with_fully_excluded_section',
                        'Sekce ' . $code . ' po dovršení důchodového věku nemá započtené dny a celá je '
                            . 'vyloučenou dobou, ale nese vyměřovací základ, který nejde přiřadit '
                            . 'předcházejícímu řádku (logický test 43). Evidenční list podejte mimo aplikaci.',
                    );
                }
            } elseif ($section['excluded_days_total'] > $section['insurance_days']) {
                throw new EldpValidationException(
                    'eldp_excluded_days_exceed_period',
                    'Vyloučené doby sekce přesahují dobu pojištění.',
                );
            }
            /*
             * Logický test ELDP12 č. 41 a kontrola JMHZ 59 část 2: jsou-li dny
             * sekce celé vyloučenou dobou (a druhý znak není D ani P, třetí
             * není S), vyměřovací základ se neuvádí. Krytí příjmem
             * ({@see EldpExcludedPeriodDeriver::derive()}) takovou sekci se
             * základem nedovolí; kdyby přesto vznikla, nesmí odejít.
             */
            if ($section['valid_from'] !== null
                && $second !== 'D' && $second !== 'P' && $third !== 'S'
                && $section['insurance_days'] === $section['excluded_days_total']
                && $section['assessment_base_czk'] !== 0
            ) {
                throw new EldpValidationException(
                    'eldp_base_with_fully_excluded_section',
                    'Sekce ' . $code . ' od ' . $section['valid_from'] . ' má všechny dny pojištění '
                        . 'vyloučenou dobou, ale nese vyměřovací základ; ČSSZ takovou sekci odmítne '
                        . '(logický test 41). Zkontrolujte nepřítomnosti a příjmy sekce.',
                );
            }
        }

        return $sections;
    }

    /**
     * Pokračuje navazující vztah týmž řádkem listu?
     *
     * Bezprostředně navazující zaměstnání stejného druhu se stejnými
     * podmínkami účasti je jedno trvající pojištění (§ 10 odst. 6 zákona
     * č. 187/2006 Sb.) a zapisuje se jedním řádkem (Metodická pomůcka př. 12).
     * Neplatí to, je-li jedním z nich zaměstnání malého rozsahu nebo DPP, ani
     * při změně druhu činnosti (př. 13 a 33) — pak jde o samostatné řádky.
     * Navazování dnem a shodu kódu kontroluje volající.
     *
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $line
     */
    private static function continuesInOneRow(array $previous, array $line): bool
    {
        $relation = $previous['relation'] ?? null;

        return is_string($relation)
            && $relation === ($line['relation'] ?? null)
            && !in_array($relation, ['small_scale_employment', 'dpp'], true);
    }

    /** @param array<string,mixed> $section */
    private static function wholeYearWithoutInsurance(array $section): bool
    {
        $from = $section['valid_from'] ?? null;
        $to = $section['valid_to'] ?? null;
        if (!is_string($from) || !is_string($to)
            || !str_ends_with($from, '-01-01')
            || $to !== substr($from, 0, 4) . '-12-31'
            || (int) $section['insurance_days'] !== 0
        ) {
            return false;
        }
        $months = array_values(array_unique(array_map(intval(...), (array) $section['months_without_insurance'])));
        sort($months);

        return $months === range(1, 12);
    }

    /** @param array<string,mixed> $line */
    private static function beforeParticipationArose(array $line): bool
    {
        return ($line['participates'] ?? null) === false
            && (int) $line['insurance_days'] === 0
            && (int) $line['excluded']['total'] === 0;
    }

    /**
     * Vyměřovací základ při přechodu na kód D (logický test ELDP12 č. 61,
     * Zadání ELDP12 údaj 39, kontrola JMHZ 59 část 3).
     *
     * Navazují-li na sebe řádek před dovršením důchodového věku a řádek s kódem
     * D téhož druhu činnosti, uvede se vyměřovací základ úhrnem v řádku po
     * dovršení věku a v předcházejícím řádku se neuvádí. Výjimka: je-li celé
     * období po dovršení věku dobou odečtenou (řádek D bez započtených dnů),
     * uvede se základ úhrnem v řádku do dovršení věku. Řádky jiného druhu
     * činnosti nebo nenavazující zůstávají se svým základem.
     *
     * @param list<array<string,mixed>> $sections
     * @return list<array<string,mixed>>
     */
    private static function moveBaseIntoPensionAgeSection(array $sections): array
    {
        $activity = static fn (string $code): string => substr($code, 0, strlen($code) - 2);
        $second = static fn (string $code): string => $code[strlen($code) - 2] ?? '';
        for ($index = 1, $count = count($sections); $index < $count; ++$index) {
            $before = $sections[$index - 1];
            $after = $sections[$index];
            if ($second((string) $after['code']) !== 'D'
                || $second((string) $before['code']) === 'D'
                || $activity((string) $after['code']) !== $activity((string) $before['code'])
                || !is_string($before['valid_to']) || !is_string($after['valid_from'])
                || (new \DateTimeImmutable($before['valid_to']))->modify('+1 day')->format('Y-m-d')
                    !== $after['valid_from']
            ) {
                continue;
            }
            if ($after['insurance_days'] > 0) {
                $sections[$index]['assessment_base_czk'] += $before['assessment_base_czk'];
                $sections[$index - 1]['assessment_base_czk'] = 0;
                if ($before['assessment_base_czk'] !== 0) {
                    $sections[$index]['assessment_base_moved_from_ordinal'] = $before['ordinal'];
                }
                continue;
            }
            $sections[$index - 1]['assessment_base_czk'] += $after['assessment_base_czk'];
            $sections[$index]['assessment_base_czk'] = 0;
            if ($after['assessment_base_czk'] !== 0) {
                $sections[$index - 1]['assessment_base_moved_from_ordinal'] = $after['ordinal'];
            }
        }

        return $sections;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:int,1:array<string,mixed>}|null
     */
    private function findEmploymentEntry(array $input, int $employmentId): ?array
    {
        $match = null;
        $people = $input['people'] ?? null;
        if (!is_array($people) || !array_is_list($people)) {
            return null;
        }
        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }
            $employee = $person['employee'] ?? null;
            $employeeId = is_array($employee) ? ($employee['id'] ?? null) : null;
            $employments = $person['employments'] ?? null;
            if (!is_int($employeeId) || !is_array($employments)
                || !array_is_list($employments)
            ) {
                continue;
            }
            foreach ($employments as $entry) {
                if (!is_array($entry) || !is_array($entry['employment'] ?? null)) {
                    continue;
                }
                if (($entry['employment']['id'] ?? null) !== $employmentId) {
                    continue;
                }
                if ($match !== null
                    || ($entry['employment']['employee_id'] ?? null) !== $employeeId
                ) {
                    throw new EldpValidationException(
                        'eldp_employment_scope_mismatch',
                        'Pracovní vztah není ve zmrazené revizi jednoznačný.',
                    );
                }
                $match = [$employeeId, $entry];
            }
        }

        return $match;
    }

    /**
     * @param array<string,mixed> $result
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array<string,mixed>|null
     */
    private function socialRelationship(
        array $result,
        int $employeeId,
        int $employmentId,
        string $label,
        string $periodStart,
        array &$blockers,
        ?string $expectedKind = 'employment',
        bool $postTermination = false,
        bool $smallScale = false,
    ): ?array {
        $people = $result['people'] ?? null;
        $matched = null;
        if (is_array($people) && array_is_list($people)) {
            foreach ($people as $person) {
                if (is_array($person) && ($person['employee_id'] ?? null) === $employeeId) {
                    $matched = $person;
                }
            }
        }
        $statutory = is_array($matched) ? ($matched['statutory'] ?? null) : null;
        $social = is_array($statutory) ? ($statutory['social_insurance'] ?? null) : null;
        if (!is_array($social) || ($social['status'] ?? null) !== 'calculated') {
            $blockers[] = [
                'code' => 'eldp_social_not_calculated',
                'message' => "Za {$label} není vypočtené sociální pojištění.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        $relationships = $social['relationships'] ?? null;
        $match = null;
        if (is_array($relationships) && array_is_list($relationships)) {
            foreach ($relationships as $relationship) {
                if (is_array($relationship)
                    && ($relationship['relationship_id'] ?? null) === "employment:{$employmentId}"
                ) {
                    if ($match !== null) {
                        $blockers[] = [
                            'code' => 'eldp_social_relationship_ambiguous',
                            'message' => "Výsledek za {$label} obsahuje pracovní vztah vícekrát.",
                            'detail' => ['period_start' => $periodStart],
                        ];

                        return null;
                    }
                    $match = $relationship;
                }
            }
        }
        $participation = is_array($match) ? ($match['participation'] ?? null) : null;
        $status = is_array($participation) ? ($participation['status'] ?? null) : null;
        /*
         * Pracovní poměr bez účasti je rozpor, který list nesmí překrýt. DPČ,
         * DPP a zaměstnání malého rozsahu se naopak účastní jen v některých
         * měsících, takže neúčast je u nich doložený stav měsíce, ne chybějící
         * podklad. U příjmu zúčtovaného po skončení vztahu rozhoduje jen
         * vyměřovací základ.
         */
        $acceptedStatuses = $postTermination || $smallScale
            ? ['participates', 'does_not_participate']
            : ($expectedKind === 'employment'
                ? ['participates']
                : ['participates', 'does_not_participate']);
        if (!is_array($match)
            || $expectedKind === null
            || ($match['kind'] ?? null) !== $expectedKind
            || !in_array($status, $acceptedStatuses, true)
        ) {
            $blockers[] = [
                'code' => 'eldp_social_participation_missing',
                'message' => "Za {$label} není doložena účast pracovního vztahu na sociálním pojištění.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }

        return $match;
    }

    /** @return list<string> */
    private static function requiredMonths(int $year, string $start, ?string $end): array
    {
        $months = [];
        for ($month = 1; $month <= 12; ++$month) {
            $periodStart = sprintf('%04d-%02d-01', $year, $month);
            $periodEnd = (new \DateTimeImmutable($periodStart))
                ->modify('last day of this month')->format('Y-m-d');
            $from = max($periodStart, $start);
            $to = $end === null ? $periodEnd : min($periodEnd, $end);
            if ($from <= $to) {
                $months[] = $periodStart;
            }
        }

        return $months;
    }

    private static function monthLabel(string $periodStart): string
    {
        $month = (int) substr($periodStart, 5, 2);
        $year = substr($periodStart, 0, 4);

        return (self::MONTH_NAMES[$month] ?? $periodStart) . ' ' . $year;
    }

    /** @return array<string,mixed> */
    private function canonicalSnapshot(mixed $json, mixed $hash, string $label): array
    {
        if (!is_string($json) || !is_string($hash)
            || !hash_equals($hash, hash('sha256', $json))
        ) {
            throw new EldpValidationException(
                'eldp_source_hash_mismatch',
                "Otisk {$label} snapshotu evidenčního listu nesouhlasí.",
            );
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)
            || CanonicalJson::encode($decoded) !== $json
        ) {
            throw new EldpValidationException(
                'eldp_source_invalid',
                "Snapshot {$label} evidenčního listu není kanonický objekt.",
            );
        }

        return $decoded;
    }

    /** @return array{manifest_sha256:string,payload:array<string,mixed>} */
    private function specManifest(): array
    {
        return $this->specManifest ??= (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
    }

    private function codebook(): JmhzCodebookCatalog
    {
        return new JmhzCodebookCatalog($this->specManifest());
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new EldpValidationException(
                'eldp_source_invalid',
                "{$field} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable
            && $date->format('Y-m-d') === $value;
    }
}
