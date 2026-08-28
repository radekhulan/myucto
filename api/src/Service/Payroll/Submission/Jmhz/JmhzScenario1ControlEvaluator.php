<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Vykonávací implementace kontrol katalogu ČSSZ nad prvním profilem měsíčního
 * hlášení (`scenario_1`, `form:bezPriznaku`, řádné podání).
 *
 * Katalog 1.4.2.8 popisuje 199 kontrol textem, ne strojově. Tahle třída je
 * jediné místo, kde se text překládá do kódu, a drží tři pravidla:
 *
 * 1. **Sazby se nezadrátovávají.** Každý koeficient se bere z parametrických
 *    konstant katalogu, které jsou účinné k datu (0,288 v roce 2025 versus
 *    0,298 v roce 2026 u téže kontroly 10).
 * 2. **Chybějící atribut není nula.** Kontrola, jejíž vstupy v podání nejsou,
 *    vrací `NotApplicable`. Výjimkou jsou součtové vzorce, kde je nepřítomnost
 *    bloku doloženě rovna nule — a tam je to u každého sčítance napsané.
 * 3. **Co lokálně vyhodnotit nelze, se za splněné nevydává.** Kontroly proti
 *    registru ČSSZ končí `NotEvaluable` se zdůvodněním, ne `Passed`.
 *
 * Aritmetika je celočíselná. Pojistné se počítá v korunách, hodiny v tisícinách
 * a týdenní doba v setinách — porovnávat je přes float by pouštělo haléřové
 * rozdíly do kontroly, která má být přesná na korunu.
 */
final class JmhzScenario1ControlEvaluator
{
    /**
     * Hloubka jedné ELDP sekce: `pojisteni` → `eldpSeznam` → `eldp`. Kód
     * a počet dnů leží přímo v `eldp`, ale vyloučené a odečítané doby o úroveň
     * hlouběji, takže bez explicitní hloubky by se jedna sekce rozpadla na
     * několik skupin a kontroly, které tyhle hodnoty porovnávají, by nikdy
     * neměly obě pohromadě.
     */
    private const ELDP_SECTION_DEPTH = 3;

    /**
     * Kontroly, které ověřují stav v systémech ČSSZ nebo v naší evidenci
     * podání, a z vyrobeného XML je tedy vyhodnotit nelze. Nejsou to mezery
     * v pokrytí — rozhodne o nich až protokol o zpracování.
     */
    private const NOT_EVALUABLE = [
        7 => 'Úhrn se skládá z vyměřovacích základů zaměstnance (10477, 10478)'
            . ' rozlišených druhem činnosti (10239), které první profil nevykazuje.',
        // Okruh, ve kterém sleva podle § 7a náleží, se v hotovém XML nedá
        // přečíst: rozhoduje o něm druh činnosti (10239) a bližší určení
        // pracovněprávního vztahu (10502), a první profil ani jeden z nich
        // nevykazuje. Podmínku proto vynucuje resolver nad rozhodnutím
        // selektoru scénáře — viz `jmhz_employer_part_time_discount_activity_unsupported`.
        42 => 'Okruh slevy stojí na druhu činnosti (10239) a bližším určení'
            . ' pracovněprávního vztahu (10502), které první profil nevykazuje;'
            . ' podmínka se vynucuje před serializací, z podání ji ověřit nelze.',
        59 => 'Podmínky vyměřovacího základu se opírají o vyloučené a odečtené'
            . ' doby (10357, 10375), které první profil nevykazuje; předpoklad'
            . ' pravidla tedy nelze ani potvrdit, ani vyvrátit.',
        // 10243 „Zaměstnání malého rozsahu" nemá ve slovníku 1.4.1.6 mapování
        // na XSD, takže se z vyrobeného XML nedá přečíst vůbec. Vydávat
        // kontrolu za splněnou by znamenalo tvrdit, že prošla podmínka, na
        // kterou jsme se nikdy nemohli podívat.
        133 => 'Zaměstnání malého rozsahu (10243) nemá ve slovníku 1.4.1.6'
            . ' mapování na XSD, takže se z podání nedá přečíst.',
        143 => 'Katalog uvádí jen „standardní kontrola tvaru VS" bez algoritmu,'
            . ' takže by se musel uhodnout; shodu s registrem zaměstnavatelů'
            . ' ČSSZ navíc lokálně ověřit nelze.',
        164 => 'Lhůta splatnosti se odvozuje od data přijetí podání (10006),'
            . ' které přiděluje až ČSSZ; od verze 1.4.2.8 kontrola platí jen'
            . ' od období 04/2026 a první přijatou pojistnou část zná evidence ČSSZ.',
        217 => 'Odkaz na GUID jiného podání ověří jen evidence ČSSZ.',
        218 => 'Existenci GUID stornovaného podání ověří jen evidence ČSSZ.',
        220 => 'Existenci GUID stornované součásti ověří jen evidence ČSSZ.',
        221 => 'O zamítnutí celého podání rozhoduje výsledek zpracování všech'
            . ' součástí na straně ČSSZ.',
        226 => 'Porovnání počtu součástí s registrem ČSSZ je možné až na straně'
            . ' ČSSZ; nesoulad vede k částečnému přijetí a formální výzvě.',
        225 => 'Chybějící první dílčí podání se pozná až porovnáním došlých'
            . ' balíků na straně ČSSZ.',
        228 => 'Počet skutečně došlých dílčích podání zná jen ČSSZ.',
        238 => 'Shodu klíčů s předchozím řádným podáním drží evidence ČSSZ.',
        241 => 'Shodu metaatributů se stornovaným podáním drží evidence ČSSZ.',
        242 => 'Rozlišení rezidenta a nerezidenta stojí na atributu 10068,'
            . ' který první profil nevykazuje.',
        243 => 'Rozlišení rezidenta a nerezidenta stojí na atributu 10068,'
            . ' který první profil nevykazuje.',
        245 => 'Kontrola platí jen pro zaměstnavatele s jedinou mzdovou účtárnou'
            . ' a prahy se počítají podle druhu činnosti (10239); ani jedno'
            . ' z jednoho podání zjistit nelze.',
        261 => 'Shodu ID PPV a variabilního symbolu drží evidence ČSSZ.',
        262 => 'Existenci ID PPV ověřuje pouze registr ČSSZ.',
        263 => 'Existenci IK MPSV ověřuje pouze registr ČSSZ.',
        264 => 'Existenci dvojice IK MPSV a ID PPV ověřuje pouze registr ČSSZ.',
        290 => 'Porovnání se slevou z posledního včas podaného hlášení vyžaduje'
            . ' historii akceptovaných pojistných částí, kterou drží ČSSZ.',
        291 => 'Platnost oznámeného záměru uplatňovat slevu (OZUSPOJ) eviduje ČSSZ.',
        323 => 'Detekce duplicitního přijetí se opírá o identifikátor zprávy'
            . ' a čas přijetí, které přiděluje až ČSSZ.',
        348 => 'Nekritická kontrola integrity se opírá o dotazy do registru'
            . ' subjektů a pracovněprávních vztahů ČSSZ.',
        305 => 'Jedinečnost GUID podání vůči období a variabilnímu symbolu se'
            . ' rozhoduje nad evidencí podání, ne nad jedním XML.',
        311 => 'Jedinost ročního zúčtování v rámci roku se pozná až porovnáním'
            . ' více měsíčních hlášení.',
        321 => 'Pozitivní výčet povinných atributů souhrnné vrstvy je v katalogu'
            . ' popsaný odkazem na oblast, ne výčtem; bez doloženého seznamu by'
            . ' šlo o odhad.',
        325 => 'Prahy pro zálohovou daň se počítají podle druhu činnosti (10239),'
            . ' který první profil nevykazuje.',
        326 => 'Jedinečnost řádného podání za období se rozhoduje nad evidencí'
            . ' podání, ne nad obsahem jednoho XML.',
        333 => 'Oficiální katalog 1.4.2.8 má u kontroly časového omezení slevy'
            . ' rozporné odkazy na atributy. Věcný výsledek navíc závisí na datu'
            . ' přijetí podání, které přiděluje až ČSSZ; lokálně se proto'
            . ' neodhaduje a rozhodne protokol ČSSZ.',
        334 => 'Ztotožnění osoby provádí kmenová evidence ČSSZ.',
    ];

    /**
     * Kontroly, jejichž předpoklad v tomhle podání nenastal — pravidla pro
     * opravné a stornující hlášení, pro odložený příjem a pro roční atributy.
     *
     * Není to allowlist: u každé je uvedený atribut nebo typ podání, který ten
     * předpoklad zakládá. Jakmile se v podání objeví, kontrola přestane být
     * mimo profil a stane se z ní viditelná mezera v pokrytí. Kdyby se
     * vynechání zapsalo natvrdo, rozšíření serializéru by kontrolu tiše vypnulo.
     *
     * @var array<int, array{reason:string,absent:list<string>,not_type:list<string>}>
     */
    private const OUT_OF_PROFILE = [
        190 => [
            'reason' => 'Lhůta pro storno celého podání se týká jen podání typu S.',
            'absent' => [],
            'not_type' => ['S'],
        ],
        // Storno součásti se posílá v OPRAVNÉM podání s formulářem typu S,
        // ne v podání typu S. Kontrola tedy dopadá na typ O.
        204 => [
            'reason' => 'Lhůta pro storno součásti se týká jen opravného podání.',
            'absent' => [],
            'not_type' => ['O'],
        ],
        233 => [
            'reason' => 'Struktura opravného hlášení se týká jen podání typu O.',
            'absent' => [],
            'not_type' => ['O'],
        ],
        237 => [
            'reason' => 'Pravidlo pro stornující formuláře se týká jen podání typu O.',
            'absent' => [],
            'not_type' => ['O'],
        ],
        278 => [
            'reason' => 'Roční atributy slevy na manžela se nevykazují.',
            'absent' => ['10541', '10542'],
            'not_type' => [],
        ],
        292 => [
            'reason' => 'Roční atributy slevy na manžela se nevykazují.',
            'absent' => ['10426', '10541', '10542'],
            'not_type' => [],
        ],
        293 => [
            'reason' => 'Průběh studia se v podání nevykazuje.',
            'absent' => ['10263', '10264'],
            'not_type' => [],
        ],
        308 => [
            'reason' => 'Omezení částí se týká jen podání typu S.',
            'absent' => [],
            'not_type' => ['S'],
        ],
        336 => [
            'reason' => 'Podání neobsahuje formulář odloženého příjmu.',
            'absent' => ['10548'],
            'not_type' => [],
        ],
        337 => [
            'reason' => 'Podání neobsahuje formulář odloženého příjmu.',
            'absent' => ['10548'],
            'not_type' => [],
        ],
        338 => [
            'reason' => 'Podání neobsahuje formulář odloženého příjmu.',
            'absent' => ['10548'],
            'not_type' => [],
        ],
        339 => [
            'reason' => 'Podání neobsahuje formulář odloženého příjmu.',
            'absent' => ['10548'],
            'not_type' => [],
        ],
    ];

    public function __construct(
        private readonly JmhzControlParameterCatalog $parameters,
        private readonly JmhzDeadlinePolicy $deadlines,
        private readonly ?JmhzExternalCodebookCatalog $externalCodebooks = null,
        private readonly ?JmhzCodebookCatalog $codebooks = null,
    ) {}

    /** @return list<int> */
    public function implementedControlIds(): array
    {
        return [
            1, 3, 4, 8, 10, 11, 12, 13, 20, 23, 31, 37, 43, 44, 45, 50, 56, 57, 58,
            60, 61, 62, 72, 74, 78, 79, 84, 87, 88, 90, 93, 94, 95, 96, 97, 98, 99, 100,
            103, 109, 112, 118, 121, 124, 129, 131, 132, 134, 135, 137, 138, 144, 145, 152,
            150, 151, 153, 154, 157, 158, 159, 162, 165, 167, 168, 170, 188, 194,
            204, 207, 208,
            191, 192, 193, 211, 216, 227, 232, 233, 235,
            236, 237, 240, 244, 248, 251,
            253, 255, 260, 267, 270, 271, 272, 273, 275, 282, 283, 284, 286,
            296, 299, 300, 301, 303, 304, 306, 307, 309, 310, 315, 328, 329, 330, 332,
            335, 341, 342, 354, 355,
        ];
    }

    /** @return array<int, string> */
    public function notEvaluableControlIds(): array
    {
        return self::NOT_EVALUABLE;
    }

    /**
     * Místa, kde se doslovné znění katalogu vědomě neuplatňuje, protože by
     * neprošlo ani bezvadné podání. Patří do výstupu, ne jen do komentáře:
     * až se podání zkusí ostře, tohle je seznam, který se má ověřit proti
     * protokolu ČSSZ.
     *
     * @return array<int, string>
     */
    public function documentedDeviations(): array
    {
        return [
            134 => 'Katalog píše „počet dnů <= datum do minus datum od", tedy'
                . ' rozdíl dat. Celý měsíc má ale o den víc, než je rozdíl jeho'
                . ' krajních dnů, takže by doslovné znění neprošlo ani bezvadné'
                . ' hlášení za celý měsíc. Interval se počítá včetně krajních dnů.',
            168 => 'Vedle tolerance žádá katalog i dolní mez 7,171 % z úhrnu'
                . ' vyměřovacích základů. Na minimálním případě (základ 1 000 Kč,'
                . ' pojistné 71 Kč) by ji neprošlo ani zcela správné podání,'
                . ' protože pojistné se zaokrouhluje u každého zaměstnance zvlášť.'
                . ' Vynucuje se jen tolerance; o skutečné mezi rozhodne protokol.',
            170 => 'Vedle tolerance žádá katalog i dolní mez 6,565 % z úhrnu'
                . ' vyměřovacích základů. Je to táž konstrukce jako u kontroly 168'
                . ' a se stejným důsledkem: sleva se zaokrouhluje u každého'
                . ' zaměstnance zvlášť, takže by mez neprošlo ani správné podání.'
                . ' Vynucuje se jen tolerance; o mezi rozhodne protokol.',
            270 => 'Táž konstrukce jako u kontroly 168 a 170 — vedle tolerance'
                . ' žádá katalog dolní mez 7,171 %, která by odmítla i správné'
                . ' podání. Vynucuje se jen tolerance.',
            244 => 'Katalog zakazuje, aby slevy „nabývaly hodnot" bez podepsaného'
                . ' prohlášení. Vykázaná nula ale žádnou slevu neuplatňuje, takže'
                . ' se za hodnotu nepovažuje — jinak by neprošel ani zaměstnanec'
                . ' bez prohlášení, který má nulový bonus.',
        ];
    }

    public function handles(int $controlId): bool
    {
        return isset(self::NOT_EVALUABLE[$controlId])
            || isset(self::OUT_OF_PROFILE[$controlId])
            || in_array($controlId, $this->implementedControlIds(), true);
    }

    /**
     * Parametrické konstanty, které implementace kontroly vědomě používá.
     * Guard v testech porovná seznam s vazbami z katalogu, aby se přesun sazby
     * mezi kontrolami nedal přehlédnout.
     *
     * @return array<int, list<string>>
     */
    public function declaredParameterKeys(): array
    {
        return [
            3 => ['source_row_6'],
            45 => ['source_row_12'],
            8 => ['source_row_3'],
            10 => ['source_row_4'],
            118 => ['source_row_7'],
            74 => ['source_row_15'],
            167 => ['source_row_5'],
            // Tolerance 7,1 % je v textu kontroly 168, ale katalog ji vede pod
            // parametrem svázaným s kontrolami 118 a 270, proto se uvádí navíc.
            168 => ['source_row_7'],
            170 => ['source_row_9'],
            270 => ['source_row_7'],
            271 => ['source_row_16'],
            315 => ['source_row_3', 'source_row_4', 'source_row_5'],
        ];
    }

    /**
     * Parametry, které katalog kontrole přiřazuje, ale my je VĚDOMĚ neuplatňujeme.
     *
     * Bez tohohle seznamu by stačilo sazbu uvést mezi deklarovanými a guard by
     * byl spokojený, i kdyby ji kód nikdy nepřečetl — přesně tak se dolní mez
     * u kontroly 168 dokázala tvářit jako pokrytá.
     *
     * @return array<int, list<string>>
     */
    public function unenforcedParameterKeys(): array
    {
        return [
            // Dolní meze nad tolerancí. Na doložených případech by jimi
            // neprošlo ani zcela správné podání, viz `documentedDeviations()`.
            168 => ['source_row_8'],
            170 => ['source_row_10'],
            270 => ['source_row_8'],
        ];
    }

    /** @return list<JmhzControlVerdict> */
    public function evaluate(
        int $controlId,
        JmhzAttributeProjection $projection,
        JmhzControlContext $context,
    ): array {
        $reason = self::NOT_EVALUABLE[$controlId] ?? null;
        if ($reason !== null) {
            return [JmhzControlVerdict::notEvaluable(
                JmhzAttributeProjection::PART_SUBMISSION,
                $reason,
            )];
        }
        $outOfProfile = $this->outOfProfile($controlId, $projection);
        if ($outOfProfile !== null) {
            return [$outOfProfile];
        }

        return match ($controlId) {
            1 => $this->employerDiscountHeadcount($projection),
            3 => $this->employerDiscountAmount($projection),
            4 => $this->insurancePayable($projection),
            45 => $this->shorterWorkingTimeWithinLimit($projection),
            137 => $this->discountReasonRequired($projection),
            138 => $this->shorterWorkingTimeRequiredForReason($projection),
            158 => $this->discountReasonFromCodebook($projection),
            188 => $this->employerDiscountOnlyOnOneEmployment($projection),
            191 => $this->annualSettlementMonths($projection),
            192 => $this->annualRequestMonths($projection),
            193 => $this->januaryOnlyAnnualTotals($projection),
            78 => $this->annualSettlementSum($projection),
            79 => $this->annualSettlementResultRequired($projection),
            112 => $this->annualChildDetailsRequired($projection),
            124 => $this->annualSpouseDetailsRequired($projection),
            310 => $this->annualResultForbiddenWhenNotPerformed($projection),
            207 => $this->employerDiscountBaseMatchesForms($projection),
            8 => $this->employerInsuranceRate($projection, '10024', '10023', 'source_row_3'),
            10 => $this->employerInsuranceRate($projection, '10026', '10025', 'source_row_4'),
            11 => $this->employerInsuranceTotal($projection),
            12 => $this->employeeInsuranceMatchesForms($projection),
            13 => $this->insuranceTotal($projection),
            20 => $this->workedHoursCoverOvertime($projection),
            23 => $this->unworkedHoursCoverVacation($projection),
            43, 44 => $this->insuranceIntervalOrderedAndFilled($projection),
            56 => $this->dateNotAfterFilling($projection, '10272'),
            57 => $this->riskyHoursWithinWorkedHours($projection),
            58 => $this->insuranceDaysWithinMonth($projection),
            60 => $this->summaryDateBeforeFilling($projection),
            61, 62 => $this->schemaValidated($context),
            84 => $this->packageOrdinalWithinCount($projection),
            87 => $this->eldpCodeMatchesActivity($projection),
            88 => $this->filledAtNotInFuture($projection, $context),
            93 => $this->packageFormCountWithinTotal($projection),
            97 => $this->atMostIncome($projection, '10289'),
            103 => $this->temporaryAssignmentIdentified($projection),
            98 => $this->dayCountsWithinMonth($projection),
            99 => $this->eldpValidityWithinPeriod($projection),
            109 => $this->atMostIncome($projection, '10416'),
            118 => $this->employeeSocialInsuranceRate($projection),
            315 => $this->employerSocialInsuranceRate($projection),
            121 => $this->sumMatchesWhenPositive(
                $projection,
                '10357',
                ['10358', '10359', '10360', '10362', '10536'],
            ),
            165 => $this->sumMatchesWhenPositive(
                $projection,
                '10366',
                ['10473', '10474', '10475'],
            ),
            170 => $this->employeeDiscountTolerance(
                $projection,
                '10487',
                '10486',
                'source_row_9',
            ),
            208 => $this->onlyWithFlag($projection, '10491', '10490'),
            216 => $this->assessmentBaseSum($projection),
            270 => $this->employeeDiscountTolerance(
                $projection,
                '10545',
                '10544',
                'source_row_7',
            ),
            271 => $this->orchardDiscountAgainstAverageWage($projection),
            272 => $this->onlyWithFlag($projection, '10547', '10546'),
            273 => $this->orchardDiscountMatchesInsurance($projection),
            275 => $this->discountsAreExclusive($projection),
            284 => $this->assessmentBaseComponentPresence($projection),
            296 => $this->orchardDiscountOnlyOnDpp($projection),
            328 => $this->emptyWhenZero(
                $projection,
                '10375',
                ['10462', '10463', '10464', '10465', '10466', '10468', '10469'],
            ),
            329 => $this->emptyWhenZero(
                $projection,
                '10357',
                ['10358', '10359', '10360', '10362', '10536'],
            ),
            135 => $this->insuranceDaysAgainstEldpCode($projection),
            157 => $this->eldpCodeFromCodebook($projection),
            204 => $this->componentCancellationWindow($projection, $context),
            211 => $this->cancelledFormsLeaveAtLeastOne($projection),
            232 => $this->regularStructureComplete($projection),
            233 => $this->amendmentStructureNonEmpty($projection),
            235 => $this->declaredFormCountMatchesReality($projection),
            227 => $this->totalFormCountMatchesReality($projection),
            236 => $this->regularSubmissionHasOnlyRegularForms($projection),
            237 => $this->cancelledFormsHaveHeaderOnly($projection),
            240 => $this->packageMetadataPresent($projection),
            341, 342 => $this->schemaValidated($context),
            244 => $this->noCreditsWithoutDeclaration($projection),
            248 => $this->summaryDataOnlyOnPrimary($projection),
            251 => $this->employmentIdentifierUniqueAcrossReport($projection),
            267 => $this->wageBreakdownEmptyWhenWageZero($projection),
            282 => $this->riskyHoursEmptyWhenNoWorkedHours($projection),
            283 => $this->incomeBreakdownEmptyWhenIncomeZero($projection),
            300, 301 => $this->packageFormLimit($projection),
            303 => $this->formHasExactlyOneBody($projection),
            306, 354 => $this->formGuidUniqueWithinSubmission($projection),
            307 => $this->eldpDetailEmptyWithoutCode($projection),
            309 => $this->insuranceDaysAgainstDeductedTime($projection),
            330 => $this->eldpCodeRequiredWithDays($projection),
            332 => $this->primaryFlagRequired($projection),
            31, 131 => $this->periodNotBeforeStart($projection),
            37 => $this->personIdentifierChecksum($projection),
            50 => $this->assessmentBaseNotNegative($projection),
            72 => $this->incomeNotNegative($projection),
            74 => $this->taxBonusFloor($projection),
            90 => $this->periodAlreadyClosed($projection, $context),
            94 => $this->nonNegativeScaled($projection, '10259'),
            95 => $this->nonNegativeScaled($projection, '10260'),
            96 => $this->nonNegativeScaled($projection, '10261'),
            100 => $this->eldpValidityOrdering($projection),
            129 => $this->monthInRange($projection),
            132 => $this->amendmentWindow($projection, $context),
            134 => $this->insuranceDaysWithinInterval($projection),
            144 => $this->obstacleWithinAgreedFund($projection, '10471'),
            145 => $this->obstacleWithinAgreedFund($projection, '10472'),
            150 => $this->collectiveAgreementTypes($projection),
            151 => $this->ownershipForm($projection),
            194 => $this->decemberOnlyEmployerAnnual($projection),
            152, 335 => $this->workplaceMunicipality($projection),
            153 => $this->workplaceCountry($projection),
            154 => $this->activePolicyInstrument($projection),
            159 => $this->activePolicyInstrumentRequired($projection),
            162 => $this->employerBasePresence($projection),
            167 => $this->employerInsuranceRate($projection, '10484', '10483', 'source_row_5'),
            168 => $this->employeeInsuranceTolerance($projection),
            253 => $this->employmentIdentifierUnique($projection),
            255 => $this->primaryEmploymentAtLeastOne($projection),
            260 => $this->primaryEmploymentAtMostOne($projection),
            286 => $this->unworkedHoursBreakdownEmpty($projection),
            299 => $this->insuranceIntervalWithinPeriod($projection),
            304 => $this->taxBaseNotNegative($projection),
            355 => $this->govTalkVariableSymbol($projection, $context),
            // Kontrola mimo profil, jejíž rozhodný atribut se v podání objevil.
            // Výjimka by tady byla horší než přiznaná mezera — podání se má
            // zastavit na neúplném pokrytí, ne spadnout uprostřed nácviku.
            default => isset(self::OUT_OF_PROFILE[$controlId])
                ? [JmhzControlVerdict::unimplemented(
                    JmhzAttributeProjection::PART_SUBMISSION,
                    'Podání nově obsahuje údaje, kterých se kontrola týká,'
                        . ' ale vykonávací implementaci zatím nemá.',
                )]
                : throw new \OutOfBoundsException(
                    "Kontrola JMHZ {$controlId} nemá vykonávací implementaci.",
                ),
        };
    }

    // --- pojistná část ----------------------------------------------------

    /** @return list<JmhzControlVerdict> */
    private function employerInsuranceRate(
        JmhzAttributeProjection $projection,
        string $insuranceId,
        string $baseId,
        string $parameterKey,
    ): array {
        $pvpoj = $projection->pvpoj();
        $insurance = $pvpoj->integer($insuranceId);
        $base = $pvpoj->integer($baseId);
        if ($insurance === null && $base === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        // U nulového základu ČSSZ ve vlastních příkladech pojistné neuvádí —
        // sazba z nuly je nula, takže vynechání nic neztrácí. U nenulového
        // základu je chybějící pojistné vada; dopočítat ho nulou by zakrylo
        // neodvedené pojistné.
        if ($insurance === null && $base === 0) {
            return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
        }
        if ($insurance === null || $base === null) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Vykázán jen jeden z údajů {$baseId} a {$insuranceId}; sazbu nelze ověřit.",
            )];
        }
        $expected = $this->parameters->multiplyCeil(
            $base,
            $parameterKey,
            $this->periodStart($projection),
        );
        if ($insurance !== $expected) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Pojistné {$insuranceId} = {$insurance} Kč neodpovídá sazbě ze základu"
                    . " {$baseId} = {$base} Kč; očekáváno {$expected} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /**
     * Kontrola 118: pojistné zaměstnance (10370) = sazba z jeho vyměřovacího
     * základu (10477), zaokrouhleno nahoru na celé koruny.
     *
     * Chybějící základ tu není „neaplikovatelné". ČSSZ ho bere jako nulu, takže
     * podání s vykázaným pojistným a bez základu odmítne — ověřeno odmítnutím
     * v testovacím prostředí. Kontrola proto musí padnout stejně jako tam,
     * jinak by lokální brána pustila dál něco, co ČSSZ vrátí.
     *
     * @return list<JmhzControlVerdict>
     */
    private function employeeSocialInsuranceRate(JmhzAttributeProjection $projection): array
    {
        return $this->socialInsuranceRate($projection, '10370', ['source_row_7']);
    }

    /**
     * Kontrola 315: pojistné zaměstnavatele (10481) = součet sazeb z dílčích
     * vyměřovacích základů podle § 5a odst. 1 ZPSZ (10478 písm. a, 10479
     * písm. b, 10480 písm. c). Nejsou-li dílčí základy vykázané, počítá se
     * jediná sazba ze základu zaměstnance (10477).
     *
     * Každý mezivýpočet se zaokrouhluje nahoru samostatně — zaokrouhlení až
     * ze součtu by u tří složek dalo jiný haléřový výsledek než ČSSZ.
     *
     * @return list<JmhzControlVerdict>
     */
    private function employerSocialInsuranceRate(JmhzAttributeProjection $projection): array
    {
        return $this->socialInsuranceRate(
            $projection,
            '10481',
            ['source_row_3', 'source_row_4', 'source_row_5'],
            ['10478', '10479', '10480'],
        );
    }

    /**
     * @param list<string> $rateKeys sazby v pořadí odpovídajícím `$partIds`;
     *     první z nich platí i pro výpočet ze základu 10477
     * @param list<string> $partIds dílčí základy podle § 5a; prázdné u sazby,
     *     která žádný rozpad nezná
     * @return list<JmhzControlVerdict>
     */
    private function socialInsuranceRate(
        JmhzAttributeProjection $projection,
        string $insuranceId,
        array $rateKeys,
        array $partIds = [],
    ): array {
        $onDate = $this->periodStart($projection);

        return $this->perForm(
            $projection,
            function (JmhzAttributeScope $form) use (
                $insuranceId,
                $rateKeys,
                $partIds,
                $onDate,
            ): ?string {
                $insurance = $form->integer($insuranceId);
                $parts = [];
                foreach ($partIds as $index => $partId) {
                    $value = $form->integer($partId);
                    if ($value !== null) {
                        $parts[$index] = $value;
                    }
                }
                $base = $form->integer('10477');
                if ($insurance === null && $parts === [] && $base === null) {
                    return null;
                }
                if ($parts !== []) {
                    $expected = 0;
                    foreach ($parts as $index => $value) {
                        $expected += $this->parameters->multiplyCeil(
                            $value,
                            $rateKeys[$index] ?? $rateKeys[0],
                            $onDate,
                        );
                    }
                } else {
                    // Vykázané pojistné bez základu je právě ten případ, na
                    // kterém ČSSZ podání zamítla: základ se dopočítat nedá
                    // a nula z něj dá nulové očekávané pojistné.
                    $expected = $this->parameters->multiplyCeil(
                        $base ?? 0,
                        $rateKeys[0],
                        $onDate,
                    );
                }
                $reported = $insurance ?? 0;
                if ($reported === $expected) {
                    return null;
                }
                $from = $parts === []
                    ? 'vyměřovacího základu 10477 = ' . ($base ?? 0) . ' Kč'
                    : 'dílčích vyměřovacích základů podle § 5a';

                return "Pojistné {$insuranceId} = {$reported} Kč neodpovídá sazbě z "
                    . "{$from}; očekáváno {$expected} Kč.";
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function employerInsuranceTotal(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $total = $pvpoj->integer('10027');
        if ($total === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        // Nepřítomný sčítanec je doloženě nula: bez zaměstnanců v dané skupině
        // se odpovídající blok pojistné části neuvádí vůbec.
        $sum = ($pvpoj->integer('10024') ?? 0)
            + ($pvpoj->integer('10026') ?? 0)
            + ($pvpoj->integer('10484') ?? 0);
        if ($total !== $sum) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Pojistné za zaměstnavatele celkem {$total} Kč neodpovídá součtu"
                    . " dílčích sazeb {$sum} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function insuranceTotal(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $total = $pvpoj->integer('10029');
        $employer = $pvpoj->integer('10027');
        $employee = $pvpoj->integer('10028');
        if ($total === null && $employer === null && $employee === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        if ($total === null || $employer === null || $employee === null) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                'Pojistná část vykazuje jen část trojice celkem/zaměstnavatel/zaměstnanec;'
                    . ' chybějící sčítanec není nula.',
            )];
        }
        if ($total !== $employer + $employee) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Pojistné celkem {$total} Kč neodpovídá součtu {$employer} + {$employee} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /**
     * Pojistná část musí sedět na součet součástí. Je to jediná kontrola, která
     * spojí souhrn za zaměstnavatele s individualizovanými formuláři — bez ní
     * projde podání, kde se odvod a rozpad rozcházejí.
     *
     * @return list<JmhzControlVerdict>
     */
    private function employeeInsuranceMatchesForms(JmhzAttributeProjection $projection): array
    {
        $total = $projection->pvpoj()->integer('10028');
        if ($total === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        $sum = 0;
        $seen = false;
        foreach ($projection->forms() as $form) {
            $value = $form->integer('10370');
            if ($value === null) {
                continue;
            }
            $seen = true;
            $sum += $value;
        }
        if (!$seen && $total !== 0) {
            // Vykázaný odvod bez jediné součásti, která by ho doložila, je
            // právě ten rozpor, kvůli kterému kontrola existuje.
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Pojistné za zaměstnance {$total} Kč nedokládá žádná součást"
                    . ' individualizované části.',
            )];
        }
        if (!$seen) {
            return [JmhzControlVerdict::notApplicable(
                JmhzAttributeProjection::PART_PVPOJ,
                'Žádná součást nevykazuje sociální pojištění zaměstnance.',
            )];
        }
        if ($total !== $sum) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Pojistné za zaměstnance {$total} Kč neodpovídá součtu za jednotlivé"
                    . " součásti {$sum} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function employerDiscountHeadcount(JmhzAttributeProjection $projection): array
    {
        $headcount = $projection->pvpoj()->integer('10030');
        if ($headcount === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        $claimed = 0;
        foreach ($projection->forms() as $form) {
            if ($form->boolean('10372') === true) {
                ++$claimed;
            }
        }
        if ($headcount !== $claimed) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Počet zaměstnanců se slevou na pojistném {$headcount} neodpovídá"
                    . " počtu součástí s uplatněnou slevou {$claimed}.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function employerDiscountAmount(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $discount = $pvpoj->integer('10032');
        $base = $pvpoj->integer('10031');
        if ($discount === null && $base === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        if ($discount === null || $base === null) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                'Sleva na pojistném je vykázána bez úhrnu vyměřovacích základů, nebo naopak.',
            )];
        }
        $percent = $this->parameters->integerValue(
            'source_row_6',
            $this->periodStart($projection),
        );
        $expected = intdiv($base * $percent + 99, 100);
        if ($discount !== $expected) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Sleva na pojistném {$discount} Kč neodpovídá {$percent} % z úhrnu"
                    . " {$base} Kč; očekáváno {$expected} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    // --- sleva zaměstnavatele podle § 7a ----------------------------------

    /**
     * Kontrola 45 — rozsah kratší pracovní nebo služební doby (10373) nesmí
     * překročit mez katalogu, tedy 30 hodin týdně podle § 7a odst. 2.
     *
     * Mez je parametrická konstanta, ne literál: ČSSZ ji vede jako hodnotu
     * účinnou k datu a mění ji stejně jako sazby.
     *
     * @return list<JmhzControlVerdict>
     */
    private function shorterWorkingTimeWithinLimit(
        JmhzAttributeProjection $projection,
    ): array {
        $limit = $this->parameters->integerValue(
            'source_row_12',
            $this->periodStart($projection),
        );

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($limit): ?string {
                $hours = $form->scaled('10373');
                if ($hours === null) {
                    return null;
                }
                if (self::compareScaled($hours, [$limit, 0]) > 0) {
                    return "Rozsah kratší pracovní/služební doby překračuje mez"
                        . " {$limit} hodin.";
                }

                return null;
            },
        );
    }

    /**
     * Kontrola 137 — uplatněná sleva (10372) musí nést důvod (10374).
     *
     * @return list<JmhzControlVerdict>
     */
    private function discountReasonRequired(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            if ($form->boolean('10372') !== true) {
                return null;
            }
            if ($form->value('10374') === null) {
                return 'Uplatněná sleva na pojistném zaměstnavatele nemá vyplněný'
                    . ' důvod uplatnění.';
            }

            return null;
        });
    }

    /**
     * Kontrola 138 — u důvodů „A" až „F" musí být vyplněn rozsah kratší
     * pracovní nebo služební doby (10373), u ostatních vyplněn být nesmí.
     *
     * Rozdíl není kosmetický: podmínku kratší doby váže § 7a odst. 2 výslovně
     * jen na okruh podle odst. 1 písm. a) až f). Zaměstnanci mladšímu 21 let
     * podle písmene g) sleva náleží i při plném úvazku, a vykázaný rozsah by
     * u něj tvrdil podmínku, kterou zákon nestanoví.
     *
     * @return list<JmhzControlVerdict>
     */
    private function shorterWorkingTimeRequiredForReason(
        JmhzAttributeProjection $projection,
    ): array {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $reason = $form->value('10374');
            $hours = $form->value('10373');
            if ($reason === null) {
                // Vyplněný rozsah bez důvodu je vada, ale hlásí ji kontrola 137;
                // tady by šlo o druhé hlášení téhož nálezu.
                return null;
            }
            $requiresShorterTime = preg_match('/^[A-F]$/D', $reason) === 1;
            if ($requiresShorterTime && $hours === null) {
                return "Důvod uplatnění slevy {$reason} vyžaduje vyplněný rozsah"
                    . ' kratší pracovní/služební doby.';
            }
            if (!$requiresShorterTime && $hours !== null) {
                return "Důvod uplatnění slevy {$reason} nepřipouští rozsah kratší"
                    . ' pracovní/služební doby.';
            }

            return null;
        });
    }

    /**
     * Kontrola 158 — důvod uplatnění slevy (10374) musí být z číselníku
     * `duvod_uplatneni_slevy` (písmena A až G podle § 7a odst. 1).
     *
     * @return list<JmhzControlVerdict>
     */
    private function discountReasonFromCodebook(JmhzAttributeProjection $projection): array
    {
        return $this->againstCodebook(
            $projection,
            fn (JmhzAttributeProjection $p): array => $this->checkDiscountReasonFromCodebook($p),
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function checkDiscountReasonFromCodebook(
        JmhzAttributeProjection $projection,
    ): array {
        $catalog = $this->codebooks;
        if ($catalog === null) {
            return [JmhzControlVerdict::unverifiable(
                JmhzAttributeProjection::PART_FORM,
                'Číselník důvodů uplatnění slevy není k dispozici.',
            )];
        }

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($catalog): ?string {
                $reason = $form->value('10374');
                if ($reason === null) {
                    return null;
                }
                try {
                    $catalog->requireValue('duvod_uplatneni_slevy', $reason);
                } catch (JmhzCodebookValueException | JmhzCodebookUnavailableException $exception) {
                    return $exception->getMessage();
                }

                return null;
            },
        );
    }

    /**
     * Kontrola 188 — vykonává-li zaměstnanec u téhož zaměstnavatele více
     * zaměstnání v pracovním poměru, sleva náleží jen z jednoho z nich.
     *
     * @return list<JmhzControlVerdict>
     */
    private function employerDiscountOnlyOnOneEmployment(
        JmhzAttributeProjection $projection,
    ): array {
        $counts = [];
        foreach ($projection->forms() as $form) {
            $person = $form->value('10051');
            if ($person === null || !$form->has('10372')) {
                continue;
            }
            $counts[$person] ??= 0;
            if ($form->boolean('10372') === true) {
                ++$counts[$person];
            }
        }
        if ($counts === []) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        $verdicts = [];
        foreach ($counts as $person => $count) {
            if ($count > 1) {
                $verdicts[] = JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_FORM,
                    null,
                    "Za IK MPSV {$person} je sleva na pojistném zaměstnavatele"
                        . " uplatněna u {$count} zaměstnání.",
                );
            }
        }

        return $verdicts === []
            ? [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)]
            : $verdicts;
    }

    /**
     * Kontrola 207 — úhrn vyměřovacích základů zaměstnanců se slevou (10031)
     * se musí rovnat součtu základů (10477) těch součástí, které slevu
     * vykazují.
     *
     * @return list<JmhzControlVerdict>
     */
    private function employerDiscountBaseMatchesForms(
        JmhzAttributeProjection $projection,
    ): array {
        $total = $projection->pvpoj()->integer('10031');
        $sum = 0;
        $claimed = 0;
        foreach ($projection->forms() as $form) {
            if ($form->boolean('10372') !== true) {
                continue;
            }
            ++$claimed;
            $sum += $form->integer('10477') ?? 0;
        }
        if ($total === null && $claimed === 0) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        if ($total === null) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Slevu vykazuje {$claimed} součástí, ale pojistná část úhrn"
                    . ' jejich vyměřovacích základů neuvádí.',
            )];
        }
        if ($total !== $sum) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Úhrn vyměřovacích základů se slevou {$total} Kč neodpovídá součtu"
                    . " za jednotlivé součásti {$sum} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function insurancePayable(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $payable = $pvpoj->integer('10033');
        $total = $pvpoj->integer('10029');
        if ($payable === null && $total === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        if ($payable === null || $total === null) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                'Vykázáno jen jedno z pojistného celkem a pojistného k úhradě;'
                    . ' dopočítat druhé nelze.',
            )];
        }
        // Neuvedená sleva je doloženě nula — blok slevy se do pojistné části
        // dává jen tehdy, když ji zaměstnavatel uplatňuje.
        $expected = $total
            - ($pvpoj->integer('10032') ?? 0)
            - ($pvpoj->integer('10487') ?? 0)
            - ($pvpoj->integer('10545') ?? 0);
        if ($payable !== $expected) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Pojistné k úhradě {$payable} Kč neodpovídá pojistnému po slevách"
                    . " {$expected} Kč.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /** @return list<JmhzControlVerdict> */
    private function employerBasePresence(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $bases = [];
        foreach (['10023', '10025', '10483'] as $attributeId) {
            $value = $pvpoj->integer($attributeId);
            if ($value !== null) {
                $bases[$attributeId] = $value;
            }
        }
        if ($bases === []) {
            // Katalog žádá, aby atributy BYLY vyplněny. Pojistná část bez
            // jediného vyměřovacího základu tedy pravidlo porušuje; „nedopadá"
            // by z povinnosti udělalo možnost.
            if ($pvpoj->attributeIds() === []) {
                return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
            }

            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                'Pojistná část neuvádí ani jeden vyměřovací základ zaměstnavatele.',
            )];
        }
        $positive = array_filter($bases, static fn (int $value): bool => $value > 0);
        $allZero = array_filter($bases, static fn (int $value): bool => $value !== 0) === [];
        if ($positive === [] && !$allZero) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                'Vyměřovací základy zaměstnavatele musí být buď všechny nulové,'
                    . ' nebo alespoň jeden kladný.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
    }

    /**
     * Kontrola 168 — pojistné za zaměstnance proti úhrnu vyměřovacích základů.
     *
     * Katalog dává dvě nezávislé tolerance a přijímá hodnotu, když projde
     * aspoň jedna: relativní odchylku do 1 % a absolutní do 100 Kč. Důvod je
     * v zaokrouhlování — pojistné se počítá a zaokrouhluje nahoru u každého
     * zaměstnance zvlášť, takže úhrn nikdy nesedí na procento z celkového
     * základu přesně.
     *
     * Text kontroly navíc žádá dolní mez 7,171 % z úhrnu základů. Ta se tady
     * NEVYNUCUJE: na doloženém minimálním případě (základ 1 000 Kč, pojistné
     * 71 Kč) by ji neprošlo ani zcela správné podání, protože 7,171 % z 1 000
     * je 71,71 Kč. Uplatnit ji by znamenalo lokálně blokovat platná podání;
     * o skutečné mezi rozhodne protokol ČSSZ. Ověřit proti PDF katalogu.
     *
     * @return list<JmhzControlVerdict>
     */
    private function employeeInsuranceTolerance(JmhzAttributeProjection $projection): array
    {
        $pvpoj = $projection->pvpoj();
        $employee = $pvpoj->integer('10028');
        if ($employee === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        $base = ($pvpoj->integer('10023') ?? 0)
            + ($pvpoj->integer('10025') ?? 0)
            + ($pvpoj->integer('10483') ?? 0);
        if ($base === 0) {
            return $employee === 0
                ? [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)]
                : [JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_PVPOJ,
                    null,
                    "Pojistné za zaměstnance {$employee} Kč je vykázáno bez vyměřovacího základu.",
                )];
        }
        [$numerator, $denominator] = $this->parameters->multiplyExact(
            $base,
            'source_row_7',
            $this->periodStart($projection),
        );
        // |expected - employee| <= 100 Kč, počítáno bez dělení, ať se nekrátí
        // zbytek: |numerator - employee * denominator| <= 100 * denominator.
        $absoluteDeviation = abs($numerator - $employee * $denominator);
        if ($absoluteDeviation <= 100 * $denominator) {
            return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
        }
        // |1 - expected / employee| <= 0,01, opět bez dělení:
        // |employee * denominator - numerator| <= employee * denominator / 100.
        if ($employee !== 0
            && $absoluteDeviation * 100 <= abs($employee * $denominator)
        ) {
            return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
        }
        $expected = intdiv($numerator, $denominator);

        return [JmhzControlVerdict::failed(
            JmhzAttributeProjection::PART_PVPOJ,
            null,
            "Pojistné za zaměstnance {$employee} Kč je mimo toleranci vůči úhrnu"
                . " vyměřovacích základů {$base} Kč; orientačně {$expected} Kč.",
        )];
    }

    // --- metadatová hlavička ----------------------------------------------

    /**
     * Začátek účinnosti JMHZ se nebere z letopočtu v kódu, ale z politiky lhůt,
     * která je jediným zdrojem pravdy o tom, za která období se vůbec podává.
     * Druhá letopočtová brána by se rozešla s rulesetem hned, jak se hranice
     * pohne.
     *
     * @return list<JmhzControlVerdict>
     */
    private function periodNotBeforeStart(JmhzAttributeProjection $projection): array
    {
        $period = $this->period($projection);
        if ($period === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        [$year, $month] = $period;
        try {
            $this->deadlines->forPeriod($this->periodStart($projection));
        } catch (\InvalidArgumentException) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Za období {$month}/{$year} se měsíční hlášení nepodává;"
                    . ' je mimo účinnost jednotného měsíčního hlášení.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function monthInRange(JmhzAttributeProjection $projection): array
    {
        $month = $projection->submission()->integer('10010');
        if ($month === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        if ($month < 1 || $month > 12) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Číslo měsíce {$month} je mimo rozsah 1 až 12.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function periodAlreadyClosed(
        JmhzAttributeProjection $projection,
        JmhzControlContext $context,
    ): array {
        $period = $this->period($projection);
        if ($period === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        [$year, $month] = $period;
        $reported = sprintf('%04d-%02d', $year, $month);
        $current = substr($context->evaluatedOn, 0, 7);
        if (strcmp($reported, $current) >= 0) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Hlášené období {$reported} ještě neskončilo; podává se až za"
                    . ' uplynulý kalendářní měsíc.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function amendmentWindow(
        JmhzAttributeProjection $projection,
        JmhzControlContext $context,
    ): array {
        $type = $projection->submission()->value('10007');
        if ($type !== 'O') {
            return [JmhzControlVerdict::notApplicable(
                JmhzAttributeProjection::PART_SUBMISSION,
                'Lhůta pro opravné hlášení se na řádné podání nevztahuje.',
            )];
        }

        $lastAllowedOn = $this->deadlines->lastCorrectionOn(
            $this->periodStart($projection),
        );
        if (strcmp($context->evaluatedOn, $lastAllowedOn) > 0) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Desetiletá lhůta pro opravné hlášení skončila {$lastAllowedOn}.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function govTalkVariableSymbol(
        JmhzAttributeProjection $projection,
        JmhzControlContext $context,
    ): array {
        $inSubmission = $projection->submission()->value('10221');
        if ($inSubmission === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        if ($context->govTalkVariableSymbol === null) {
            // Ne mezera v našich schopnostech: obálka v téhle fázi ještě
            // neexistuje, takže není co porovnávat. Kontrola se vyhodnotí až
            // v okamžiku odeslání, kdy obálka vznikne.
            return [JmhzControlVerdict::notEvaluable(
                JmhzAttributeProjection::PART_SUBMISSION,
                'Obálka GovTalk vzniká až při odeslání přes VREP; bez ní není'
                    . ' s čím variabilní symbol porovnat.',
            )];
        }
        if (!hash_equals($context->govTalkVariableSymbol, $inSubmission)) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                'Variabilní symbol v podání neodpovídá symbolu v obálce GovTalk.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    // --- součásti individualizované části ---------------------------------

    /** @return list<JmhzControlVerdict> */
    private function personIdentifierChecksum(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $value = $form->value('10051');
            if ($value === null) {
                return null;
            }
            if (preg_match('/^\d{10}$/D', $value) !== 1) {
                return "IK MPSV {$value} nemá deset číslic.";
            }
            $body = (int) substr($value, 0, 9);
            // Zbytek 10 se do jedné kontrolní číslice nevejde; stejně jako
            // u rodného čísla se zapisuje nulou.
            $expected = $body % 11 % 10;
            if ((int) $value[9] !== $expected) {
                return "IK MPSV {$value} nesplňuje kontrolní číslici modulo 11.";
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function assessmentBaseNotNegative(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            foreach ($form->all('10245') as $occurrence) {
                if ((int) $occurrence->value < 0) {
                    return "Vyměřovací základ ELDP {$occurrence->value} je záporný.";
                }
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function incomeNotNegative(JmhzAttributeProjection $projection): array
    {
        return $this->nonNegativeInteger($projection, '10286', 'Zúčtovaný příjem celkem');
    }

    /** @return list<JmhzControlVerdict> */
    private function taxBaseNotNegative(JmhzAttributeProjection $projection): array
    {
        return $this->nonNegativeInteger($projection, '10535', 'Základ pro výpočet daně');
    }

    /** @return list<JmhzControlVerdict> */
    private function taxBonusFloor(JmhzAttributeProjection $projection): array
    {
        $floor = $this->parameters->integerValue(
            'source_row_15',
            $this->periodStart($projection),
        );

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($floor): ?string {
                $bonus = $form->integer('10306');
                if ($bonus === null) {
                    return null;
                }
                if ($bonus < 0) {
                    return "Měsíční daňový bonus {$bonus} Kč je záporný.";
                }
                if ($bonus > 0 && $bonus < $floor) {
                    return "Měsíční daňový bonus {$bonus} Kč je nižší než {$floor} Kč.";
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function unworkedHoursCoverVacation(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $withPay = $form->scaled('10276');
            $vacation = $form->scaled('10279');
            if ($withPay === null || $vacation === null) {
                return null;
            }
            if (self::compareScaled($withPay, $vacation) < 0) {
                return 'Neodpracované hodiny s náhradou jsou nižší než hodiny čerpané dovolené.';
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function obstacleWithinAgreedFund(
        JmhzAttributeProjection $projection,
        string $attributeId,
    ): array {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($attributeId): ?string {
                $obstacle = $form->scaled($attributeId);
                $fund = $form->scaled('10260');
                if ($obstacle === null || $fund === null) {
                    return null;
                }
                if (self::compareScaled($obstacle, $fund) > 0) {
                    return "Překážky v práci ({$attributeId}) překračují sjednaný"
                        . ' fond pracovní doby.';
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function unworkedHoursBreakdownEmpty(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $total = $form->scaled('10275');
            if ($total === null || $total[0] !== 0) {
                return null;
            }
            foreach (['10276', '10277', '10278', '10279', '10280', '10471', '10472'] as $id) {
                $value = $form->scaled($id);
                if ($value !== null && $value[0] !== 0) {
                    return "Celkový počet neodpracovaných hodin je nula, ale atribut"
                        . " {$id} je vyplněný.";
                }
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function nonNegativeScaled(
        JmhzAttributeProjection $projection,
        string $attributeId,
    ): array {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($attributeId): ?string {
                $value = $form->scaled($attributeId);
                if ($value === null || $value[0] >= 0) {
                    return null;
                }

                return "Atribut {$attributeId} je záporný.";
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function nonNegativeInteger(
        JmhzAttributeProjection $projection,
        string $attributeId,
        string $label,
    ): array {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($attributeId, $label): ?string {
                $value = $form->integer($attributeId);
                if ($value === null || $value >= 0) {
                    return null;
                }

                return "{$label} ({$attributeId}) je záporný: {$value}.";
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function eldpValidityOrdering(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            foreach ($form->groupedBy(['10241', '10242'], self::ELDP_SECTION_DEPTH) as $section) {
                $from = $section['10241'] ?? null;
                $to = $section['10242'] ?? null;
                if ($from === null || $to === null) {
                    continue;
                }
                if (strcmp($from, $to) > 0) {
                    return "Platnost kódu ELDP od {$from} je po platnosti do {$to}.";
                }
            }

            return null;
        });
    }

    /**
     * Kontrola 134 — počet dnů pojištění v ELDP sekci proti intervalu trvání.
     *
     * Katalog píše `10356 <= 10355 - 10354`, což je rozdíl dat. Celý červenec
     * (1. až 31. 7.) má ale 31 dnů pojištění, ne 30, takže doslovné znění by
     * neprošlo ani u bezvadného hlášení za celý měsíc. Počítá se proto interval
     * včetně obou krajních dnů.
     *
     * @return list<JmhzControlVerdict>
     */
    private function insuranceDaysWithinInterval(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $from = $form->value('10354');
            $to = $form->value('10355');
            if ($from === null || $to === null) {
                return null;
            }
            $span = self::intervalDays($from, $to);
            if ($span === null) {
                return null;
            }
            foreach ($form->groupedBy(['10356'], self::ELDP_SECTION_DEPTH) as $section) {
                $days = $section['10356'] ?? null;
                if ($days === null) {
                    continue;
                }
                if ((int) $days > $span) {
                    return "Počet dnů pojištění {$days} překračuje délku intervalu"
                        . " {$from} až {$to} ({$span} dnů).";
                }
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function insuranceIntervalWithinPeriod(JmhzAttributeProjection $projection): array
    {
        $period = $this->period($projection);
        if ($period === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        $prefix = sprintf('%04d-%02d', $period[0], $period[1]);

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($prefix): ?string {
                foreach (['10354', '10355'] as $attributeId) {
                    $value = $form->value($attributeId);
                    if ($value !== null && !str_starts_with($value, $prefix . '-')) {
                        return "Datum {$attributeId} = {$value} leží mimo hlášený"
                            . " měsíc {$prefix}.";
                    }
                }

                return null;
            },
        );
    }

    /**
     * Číselníková kontrola. Nedostupný nebo nepokrytý číselník není vada dat,
     * ale mezera na naší straně: neznamená, že je hodnota špatně, jen že ji
     * nemáme proti čemu ověřit. Vydávat to za nález by uživatele poslalo
     * opravovat správně vyplněné podání.
     *
     * @param callable(JmhzAttributeProjection):list<JmhzControlVerdict> $check
     * @return list<JmhzControlVerdict>
     */
    private function againstCodebook(
        JmhzAttributeProjection $projection,
        callable $check,
    ): array {
        try {
            return $check($projection);
        } catch (JmhzCodebookUnavailableException $exception) {
            return [JmhzControlVerdict::unverifiable(
                JmhzAttributeProjection::PART_FORM,
                $exception->getMessage(),
            )];
        }
    }

    /** @return list<JmhzControlVerdict> */
    private function workplaceMunicipality(JmhzAttributeProjection $projection): array
    {
        return $this->againstCodebook(
            $projection,
            fn (JmhzAttributeProjection $p): array => $this->checkWorkplaceMunicipality($p),
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function workplaceCountry(JmhzAttributeProjection $projection): array
    {
        return $this->againstCodebook(
            $projection,
            fn (JmhzAttributeProjection $p): array => $this->checkWorkplaceCountry($p),
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function activePolicyInstrument(JmhzAttributeProjection $projection): array
    {
        return $this->againstCodebook(
            $projection,
            fn (JmhzAttributeProjection $p): array => $this->checkActivePolicyInstrument($p),
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function eldpCodeFromCodebook(JmhzAttributeProjection $projection): array
    {
        return $this->againstCodebook(
            $projection,
            fn (JmhzAttributeProjection $p): array => $this->checkEldpCodeFromCodebook($p),
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function checkWorkplaceMunicipality(JmhzAttributeProjection $projection): array
    {
        $catalog = $this->externalCodebooks;
        if ($catalog === null) {
            return [JmhzControlVerdict::unverifiable(
                JmhzAttributeProjection::PART_FORM,
                'Číselník obcí CISOB je externí reference; bez připnutého overlay'
                    . ' jej ověřit nelze.',
            )];
        }
        $validOn = $this->periodStart($projection);

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($catalog, $validOn): ?string {
                $code = $form->value('10230');
                if ($code === null) {
                    return null;
                }
                // Porovnává se KÓD, ne název. Katalog žádá, aby hodnota byla
                // z číselníku CISOB, ne aby se název shodoval na bajt — a ČSSZ
                // ve vlastním příkladu píše u kódu 554782 „Praha", zatímco
                // číselník má „Hlavní město Praha". Trvat na doslovné shodě by
                // odmítalo správně vyplněná podání.
                $found = $catalog->searchMunicipalities($code, $validOn, 5);
                foreach ($found as $entry) {
                    if ($entry['code'] === $code) {
                        return null;
                    }
                }

                return "Kód obce {$code} není v připnutém číselníku CISOB platný.";
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function checkWorkplaceCountry(JmhzAttributeProjection $projection): array
    {
        $catalog = $this->externalCodebooks;
        if ($catalog === null) {
            return [JmhzControlVerdict::unverifiable(
                JmhzAttributeProjection::PART_FORM,
                'Číselník států CZEMALFA je externí reference; bez připnutého'
                    . ' overlay jej ověřit nelze.',
            )];
        }
        $validOn = $this->periodStart($projection);

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($catalog, $validOn): ?string {
                $code = $form->value('10231');
                if ($code === null) {
                    return null;
                }
                try {
                    $catalog->requireCountry($code, $validOn);
                } catch (JmhzCodebookValueException $exception) {
                    return $exception->getMessage();
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function checkActivePolicyInstrument(JmhzAttributeProjection $projection): array
    {
        $catalog = $this->codebooks;
        if ($catalog === null) {
            return [JmhzControlVerdict::unverifiable(
                JmhzAttributeProjection::PART_FORM,
                'Číselník nástrojů APZ není k dispozici.',
            )];
        }

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($catalog): ?string {
                $code = $form->value('10233');
                if ($code === null) {
                    return null;
                }
                try {
                    $catalog->requireValue('nastroj_opatreni', $code);
                } catch (JmhzCodebookValueException $exception) {
                    return $exception->getMessage();
                }

                return null;
            },
        );
    }

    /**
     * Evidované dočasné přidělení musí být identifikované právě jedním z tří
     * způsobů. Brána stojí na HODNOTĚ 10251, ne na nepřítomnosti identifikace —
     * ta nepřítomnost je totiž přesně to porušení, které má kontrola chytat.
     *
     * @return list<JmhzControlVerdict>
     */
    private function temporaryAssignmentIdentified(JmhzAttributeProjection $projection): array
    {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form): ?string {
                if ($form->boolean('10251') !== true) {
                    return null;
                }
                $ways = 0;
                $ways += $form->value('10252') !== null ? 1 : 0;
                $ways += $form->value('10457') !== null ? 1 : 0;
                $ways += ($form->value('10492') !== null
                    && $form->value('10493') !== null
                    && $form->value('10494') !== null) ? 1 : 0;
                if ($ways === 1) {
                    return null;
                }

                return $ways === 0
                    ? 'Evidované dočasné přidělení nemá uvedenou identifikaci uživatele.'
                    : 'Dočasné přidělení má uvedeno více způsobů identifikace najednou.';
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function activePolicyInstrumentRequired(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            if ($form->boolean('10232') !== true) {
                return null;
            }
            if ($form->value('10233') === null) {
                return 'Uplatněný mzdový příspěvek APZ vyžaduje vyplněný nástroj opatření.';
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function employmentIdentifierUnique(JmhzAttributeProjection $projection): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($projection->forms() as $form) {
            $identifier = $form->value('10228');
            // Formuláře odloženého příjmu se do jedinečnosti nezapočítávají —
            // k jednomu aktivnímu vztahu smí být v podání dvě řádné součásti.
            if ($identifier === null || in_array('odlozenyPrijem', $form->bodies(), true)) {
                continue;
            }
            if (isset($seen[$identifier])) {
                $duplicates[$identifier] = $form->ordinal;
            }
            $seen[$identifier] = true;
        }
        if ($seen === []) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        if ($duplicates === []) {
            return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)];
        }
        $verdicts = [];
        foreach ($duplicates as $identifier => $ordinal) {
            $verdicts[] = JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_FORM,
                $ordinal,
                "ID PPV {$identifier} je v dílčím podání uvedeno více než jednou.",
            );
        }

        return $verdicts;
    }

    /**
     * Kontrola 253 měří jedinečnost v dílčím podání, kontrola 251 v celém
     * měsíčním hlášení. Rozdíl je vidět až u děleného podání — nad více balíky
     * jedno XML na odpověď nestačí a rozhodne až ČSSZ.
     *
     * @return list<JmhzControlVerdict>
     */
    private function employmentIdentifierUniqueAcrossReport(
        JmhzAttributeProjection $projection,
    ): array {
        $packages = $projection->submission()->integer('10003');
        if ($packages !== null && $packages > 1) {
            return [JmhzControlVerdict::notEvaluable(
                JmhzAttributeProjection::PART_FORM,
                'Hlášení je dělené do více balíků; jedinečnost ID PPV napříč nimi'
                    . ' vidí až ČSSZ.',
            )];
        }

        return $this->employmentIdentifierUnique($projection);
    }

    /** @return list<JmhzControlVerdict> */
    private function primaryEmploymentAtLeastOne(JmhzAttributeProjection $projection): array
    {
        return $this->primaryEmploymentCounts(
            $projection,
            static fn (int $count): bool => $count < 1,
            'Za IK MPSV %s není v podání žádné primární PPV.',
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function primaryEmploymentAtMostOne(JmhzAttributeProjection $projection): array
    {
        return $this->primaryEmploymentCounts(
            $projection,
            static fn (int $count): bool => $count > 1,
            'Za IK MPSV %s je v podání více než jedno primární PPV.',
        );
    }

    /**
     * @param callable(int):bool $violates
     * @return list<JmhzControlVerdict>
     */
    private function primaryEmploymentCounts(
        JmhzAttributeProjection $projection,
        callable $violates,
        string $template,
    ): array {
        $counts = [];
        foreach ($projection->forms() as $form) {
            $person = $form->value('10051');
            if ($person === null) {
                continue;
            }
            $counts[$person] ??= 0;
            if ($form->boolean('10495') === true) {
                ++$counts[$person];
            }
        }
        if ($counts === []) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        $verdicts = [];
        foreach ($counts as $person => $count) {
            if ($violates($count)) {
                $verdicts[] = JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_FORM,
                    null,
                    sprintf($template, $person),
                );
            }
        }

        return $verdicts === []
            ? [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)]
            : $verdicts;
    }

    // --- metadata balíku a struktura --------------------------------------

    /** @return list<JmhzControlVerdict> */
    private function schemaValidated(JmhzControlContext $context): array
    {
        if (!$context->schemaValidated) {
            return [JmhzControlVerdict::unverifiable(
                JmhzAttributeProjection::PART_SUBMISSION,
                'Volající neprovedl validaci proti připnutému XSD, takže ji'
                    . ' nelze vykázat jako splněnou.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function packageOrdinalWithinCount(JmhzAttributeProjection $projection): array
    {
        $submission = $projection->submission();
        $ordinal = $submission->integer('10002');
        $count = $submission->integer('10003');
        if ($ordinal === null || $count === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        if ($ordinal > $count) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Pořadí balíku {$ordinal} je vyšší než počet balíků {$count}.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function packageFormCountWithinTotal(JmhzAttributeProjection $projection): array
    {
        $submission = $projection->submission();
        $inPackage = $submission->integer('10015');
        $total = $submission->integer('10488');
        if ($inPackage === null || $total === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        if ($inPackage > $total) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Počet formulářů v balíku {$inPackage} je vyšší než počet celkem {$total}.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /**
     * První dílčí podání pojme navíc pojistnou a souhrnnou část, další už jen
     * součásti individualizované části.
     *
     * @return list<JmhzControlVerdict>
     */
    private function packageFormLimit(JmhzAttributeProjection $projection): array
    {
        $submission = $projection->submission();
        $inPackage = $submission->integer('10015');
        $ordinal = $submission->integer('10002');
        if ($inPackage === null || $ordinal === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        $limit = $ordinal === 1 ? 1502 : 1500;
        if ($inPackage > $limit) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Balík {$ordinal} obsahuje {$inPackage} formulářů, povoleno je {$limit}.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function packageMetadataPresent(JmhzAttributeProjection $projection): array
    {
        $type = $projection->submission()->value('10007');
        if ($type !== 'R' && $type !== 'O') {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        $missing = [];
        foreach (['10002', '10003', '10015', '10488'] as $attributeId) {
            if ($projection->submission()->value($attributeId) === null) {
                $missing[] = $attributeId;
            }
        }
        if ($missing !== []) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                'Podání typu ' . $type . ' musí uvádět metaatributy balíku; chybí '
                    . implode(', ', $missing) . '.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /**
     * První dílčí podání řádného hlášení musí nést souhrnnou i pojistnou část
     * a alespoň jednu součást. Serializér zatím staví jediný balík, takže se
     * kontrola vyhodnocuje jen pro něj.
     *
     * @return list<JmhzControlVerdict>
     */
    private function regularStructureComplete(JmhzAttributeProjection $projection): array
    {
        $submission = $projection->submission();
        if ($submission->value('10007') !== 'R') {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        if (($submission->integer('10002') ?? 1) !== 1) {
            return [JmhzControlVerdict::notApplicable(
                JmhzAttributeProjection::PART_SUBMISSION,
                'Povinné vrstvy se vyžadují jen v prvním dílčím podání.',
            )];
        }
        $missing = [];
        if ($projection->summary()->attributeIds() === []) {
            $missing[] = 'souhrnná část';
        }
        if ($projection->pvpoj()->attributeIds() === []) {
            $missing[] = 'pojistná část';
        }
        if ($projection->forms() === []) {
            $missing[] = 'individualizovaná součást';
        }
        if ($missing !== []) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                'Prvnímu dílčímu podání řádného hlášení chybí: '
                    . implode(', ', $missing) . '.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /**
     * Deklarovaný počet formulářů v balíku musí sedět na skutečnost. V prvním
     * balíku se do počtu započítává i souhrnná a pojistná část.
     *
     * @return list<JmhzControlVerdict>
     */
    private function declaredFormCountMatchesReality(JmhzAttributeProjection $projection): array
    {
        $submission = $projection->submission();
        $declared = $submission->integer('10015');
        $type = $submission->value('10007');
        if ($declared === null || ($type !== 'R' && $type !== 'O')) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        $ordinal = $submission->integer('10002') ?? 1;
        $layers = 0;
        if ($ordinal === 1) {
            $layers += $projection->summary()->attributeIds() === [] ? 0 : 1;
            $layers += $projection->pvpoj()->attributeIds() === [] ? 0 : 1;
        }
        $expected = count($projection->forms()) + $layers;
        if ($declared !== $expected) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Uvedený počet formulářů v balíku {$declared} neodpovídá skutečnému"
                    . " počtu {$expected}.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /**
     * Celkový počet formulářů se skládá ze všech dílčích podání. Ověřit ho lze
     * jen tehdy, když je podání jediné — jinak o něm rozhoduje až ČSSZ, která
     * vidí všechny došlé balíky.
     *
     * @return list<JmhzControlVerdict>
     */
    private function totalFormCountMatchesReality(JmhzAttributeProjection $projection): array
    {
        $submission = $projection->submission();
        $total = $submission->integer('10488');
        $packages = $submission->integer('10003');
        $type = $submission->value('10007');
        if ($total === null || ($type !== 'R' && $type !== 'O')) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        if ($packages !== 1) {
            return [JmhzControlVerdict::notEvaluable(
                JmhzAttributeProjection::PART_SUBMISSION,
                'Celkový počet formulářů se skládá z více dílčích podání, která'
                    . ' vidí až ČSSZ.',
            )];
        }
        $layers = ($projection->summary()->attributeIds() === [] ? 0 : 1)
            + ($projection->pvpoj()->attributeIds() === [] ? 0 : 1);
        $expected = count($projection->forms()) + $layers;
        if ($total !== $expected) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Celkový počet formulářů {$total} neodpovídá skutečnému počtu {$expected}.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function componentCancellationWindow(
        JmhzAttributeProjection $projection,
        JmhzControlContext $context,
    ): array {
        $hasCancellation = false;
        foreach ($projection->forms() as $form) {
            if ($form->value('10016') === 'S') {
                $hasCancellation = true;
                break;
            }
        }
        if (!$hasCancellation) {
            return [JmhzControlVerdict::notApplicable(
                JmhzAttributeProjection::PART_FORM,
                'Opravné podání neobsahuje stornující formulář.',
            )];
        }
        $periodStart = $this->periodStart($projection);
        if (!$this->deadlines->cancellationAllowed($periodStart)) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_FORM,
                null,
                'ČSSZ nepovoluje storno formuláře za leden až březen 2026.',
            )];
        }
        $window = $this->deadlines->forPeriod($periodStart);
        if (strcmp($context->evaluatedOn, $window->earliestSubmissionOn) < 0
            || strcmp($context->evaluatedOn, $window->dueOn) > 0
        ) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_FORM,
                null,
                "Storno formuláře lze podat jen od {$window->earliestSubmissionOn}"
                    . " do {$window->dueOn}.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)];
    }

    /** @return list<JmhzControlVerdict> */
    private function amendmentStructureNonEmpty(JmhzAttributeProjection $projection): array
    {
        if ($projection->submission()->value('10007') !== 'O') {
            return [JmhzControlVerdict::notApplicable(
                JmhzAttributeProjection::PART_SUBMISSION,
            )];
        }
        if ($projection->summary()->attributeIds() === []
            && $projection->pvpoj()->attributeIds() === []
            && $projection->forms() === []
        ) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                'Opravné podání musí obsahovat souhrn, PVPOJ nebo alespoň jeden formulář.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function cancelledFormsHaveHeaderOnly(JmhzAttributeProjection $projection): array
    {
        $evaluated = 0;
        $verdicts = [];
        foreach ($projection->forms() as $form) {
            if ($form->value('10016') !== 'S') {
                continue;
            }
            ++$evaluated;
            if ($form->bodies() !== []) {
                $verdicts[] = JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_FORM,
                    $form->ordinal,
                    'Stornující formulář smí obsahovat pouze hlavičku.',
                );
            }
        }
        if ($evaluated === 0) {
            return [JmhzControlVerdict::notApplicable(
                JmhzAttributeProjection::PART_FORM,
                'Opravné podání neobsahuje stornující formulář.',
            )];
        }

        return $verdicts === []
            ? [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)]
            : $verdicts;
    }

    /** @return list<JmhzControlVerdict> */
    private function regularSubmissionHasOnlyRegularForms(
        JmhzAttributeProjection $projection,
    ): array {
        if ($projection->submission()->value('10007') !== 'R') {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }

        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $type = $form->value('10016');
            if ($type === null || $type === 'R') {
                return null;
            }

            return "Řádné hlášení nesmí obsahovat formulář typu {$type}.";
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function cancelledFormsLeaveAtLeastOne(JmhzAttributeProjection $projection): array
    {
        $types = [];
        foreach ($projection->forms() as $form) {
            $type = $form->value('10016');
            if ($type !== null) {
                $types[] = $type;
            }
        }
        if ($types === [] || !in_array('S', $types, true)) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        $remaining = array_filter($types, static fn (string $type): bool => $type !== 'S');
        if ($remaining === []) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_FORM,
                null,
                'Po stornu součástí nezbyla v hlášení žádná platná součást.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)];
    }

    /** @return list<JmhzControlVerdict> */
    private function formHasExactlyOneBody(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $bodies = $form->bodies();
            if (count($bodies) === 1) {
                return null;
            }
            if ($bodies === []) {
                return 'Součást individualizované části neobsahuje žádný typ formuláře.';
            }

            return 'Součást individualizované části obsahuje více typů formuláře: '
                . implode(', ', $bodies) . '.';
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function formGuidUniqueWithinSubmission(JmhzAttributeProjection $projection): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($projection->forms() as $form) {
            $guid = $form->value('10012');
            if ($guid === null) {
                continue;
            }
            $key = strtoupper($guid);
            if (isset($seen[$key])) {
                $duplicates[$key] = $form->ordinal;
            }
            $seen[$key] = true;
        }
        if ($seen === []) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        $verdicts = [];
        foreach ($duplicates as $guid => $ordinal) {
            $verdicts[] = JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_FORM,
                $ordinal,
                "GUID formuláře {$guid} je v podání použit více než jednou.",
            );
        }

        return $verdicts === []
            ? [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)]
            : $verdicts;
    }

    /** @return list<JmhzControlVerdict> */
    private function primaryFlagRequired(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            if ($form->value('10495') !== null) {
                return null;
            }
            // Nepovinný je jen u scénáře pro druh činnosti 10 a u stornujícího
            // formuláře; jinde je příznak primárního vztahu povinný.
            if ($form->value('10016') === 'S' || in_array('ozpTpp', $form->bodies(), true)) {
                return null;
            }

            return 'Součást neuvádí příznak primárního pracovněprávního vztahu.';
        });
    }

    // --- data a lhůty -----------------------------------------------------

    /** @return list<JmhzControlVerdict> */
    private function filledAtNotInFuture(
        JmhzAttributeProjection $projection,
        JmhzControlContext $context,
    ): array {
        $filledAt = $this->filledOn($projection);
        if ($filledAt === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        if (strcmp($filledAt, $context->evaluatedOn) > 0) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                "Datum vyplnění podání {$filledAt} je v budoucnosti.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function insuranceIntervalOrderedAndFilled(JmhzAttributeProjection $projection): array
    {
        $filledOn = $this->filledOn($projection);

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($filledOn): ?string {
                $from = $form->value('10354');
                $to = $form->value('10355');
                if ($from === null || $to === null) {
                    return null;
                }
                if (strcmp($from, $to) > 0) {
                    return "Pojištění od {$from} je po datu do {$to}.";
                }
                if ($filledOn !== null && strcmp($to, $filledOn) > 0) {
                    return "Pojištění do {$to} je po datu vyplnění podání {$filledOn}.";
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function dateNotAfterFilling(
        JmhzAttributeProjection $projection,
        string $attributeId,
    ): array {
        $filledOn = $this->filledOn($projection);
        if ($filledOn === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($attributeId, $filledOn): ?string {
                $value = $form->value($attributeId);
                if ($value === null || strcmp($value, $filledOn) <= 0) {
                    return null;
                }

                return "Datum {$attributeId} = {$value} je po datu vyplnění podání {$filledOn}.";
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function summaryDateBeforeFilling(JmhzAttributeProjection $projection): array
    {
        $value = $projection->summary()->value('10409');
        $filledOn = $this->filledOn($projection);
        if ($value === null || $filledOn === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUMMARY)];
        }
        if (strcmp($value, $filledOn) >= 0) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUMMARY,
                null,
                "Datum specifické právní skutečnosti {$value} není před datem"
                    . " vyplnění podání {$filledOn}.",
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUMMARY)];
    }

    /** @return list<JmhzControlVerdict> */
    private function insuranceDaysWithinMonth(JmhzAttributeProjection $projection): array
    {
        $days = $this->daysInReportedMonth($projection);
        if ($days === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($days): ?string {
                foreach ($form->all('10356') as $occurrence) {
                    if ((int) $occurrence->value > $days) {
                        return "Počet dnů pojištění {$occurrence->value} překračuje"
                            . " počet dnů v měsíci ({$days}).";
                    }
                }

                return null;
            },
        );
    }

    /**
     * Sada denních atributů ELDP nesmí přesáhnout počet dnů v hlášeném měsíci.
     * Kontroluje se jen to, co je v podání — nepřítomný atribut není nula.
     *
     * @return list<JmhzControlVerdict>
     */
    private function dayCountsWithinMonth(JmhzAttributeProjection $projection): array
    {
        $days = $this->daysInReportedMonth($projection);
        if ($days === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        $attributeIds = [
            '10357', '10358', '10359', '10360', '10362', '10536', '10366',
            '10473', '10474', '10475', '10375', '10462', '10463', '10464',
            '10465', '10466', '10468', '10469',
        ];

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($attributeIds, $days): ?string {
                foreach ($attributeIds as $attributeId) {
                    foreach ($form->all($attributeId) as $occurrence) {
                        if ((int) $occurrence->value > $days) {
                            return "Atribut {$attributeId} = {$occurrence->value}"
                                . " překračuje počet dnů v měsíci ({$days}).";
                        }
                    }
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function eldpValidityWithinPeriod(JmhzAttributeProjection $projection): array
    {
        $period = $this->period($projection);
        if ($period === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_FORM)];
        }
        $prefix = sprintf('%04d-%02d-', $period[0], $period[1]);

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($prefix): ?string {
                foreach (['10241', '10242'] as $attributeId) {
                    foreach ($form->all($attributeId) as $occurrence) {
                        if (!str_starts_with($occurrence->value, $prefix)) {
                            return "Platnost kódu ELDP ({$attributeId}) ="
                                . " {$occurrence->value} leží mimo hlášený měsíc.";
                        }
                    }
                }

                return null;
            },
        );
    }

    // --- hodiny a příjmy --------------------------------------------------

    /** @return list<JmhzControlVerdict> */
    private function workedHoursCoverOvertime(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $worked = $form->scaled('10268');
            $overtime = $form->scaled('10269');
            if ($worked === null || $overtime === null) {
                return null;
            }
            if (self::compareScaled($worked, $overtime) < 0) {
                return 'Počet odpracovaných hodin je nižší než počet přesčasových hodin.';
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function riskyHoursWithinWorkedHours(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $risky = $form->scaled('10273');
            $worked = $form->scaled('10268');
            if ($risky === null || $worked === null) {
                return null;
            }
            if (self::compareScaled($risky, $worked) > 0) {
                return 'Hodiny v rizikové práci překračují počet odpracovaných hodin.';
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function riskyHoursEmptyWhenNoWorkedHours(JmhzAttributeProjection $projection): array
    {
        return $this->emptyWhenZero(
            $projection,
            '10268',
            ['10269', '10270', '10271', '10272', '10273', '10274'],
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function wageBreakdownEmptyWhenWageZero(JmhzAttributeProjection $projection): array
    {
        return $this->emptyWhenZero(
            $projection,
            '10328',
            ['10329', '10330', '10331', '10332', '10333', '10334', '10335', '10336'],
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function incomeBreakdownEmptyWhenIncomeZero(JmhzAttributeProjection $projection): array
    {
        return $this->emptyWhenZero(
            $projection,
            '10286',
            [
                '10289', '10417', '10292', '10293', '10294', '10295', '10296',
                '10418', '10308', '10309', '10310', '10416',
            ],
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function atMostIncome(JmhzAttributeProjection $projection, string $attributeId): array
    {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($attributeId): ?string {
                $part = $form->integer($attributeId);
                $total = $form->integer('10286');
                if ($part === null || $total === null || $part <= $total) {
                    return null;
                }

                return "Atribut {$attributeId} = {$part} překračuje zúčtovaný"
                    . " příjem celkem {$total}.";
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    /** @return list<JmhzControlVerdict> */
    private function collectiveAgreementTypes(JmhzAttributeProjection $projection): array
    {
        $occurrences = $projection->summary()->all('10214');
        if ($occurrences === []) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUMMARY)];
        }
        $values = array_map(
            static fn (JmhzAttributeOccurrence $occurrence): string => $occurrence->value,
            $occurrences,
        );
        foreach ($values as $value) {
            if (!in_array($value, ['0', '1', '2', '3', '4', '5'], true)) {
                return [JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_SUMMARY,
                    null,
                    "Typ kolektivní smlouvy {$value} není v číselníku 0–5.",
                )];
            }
        }
        if (in_array('0', $values, true) && $values !== ['0']) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUMMARY,
                null,
                'Kód 0 znamená neexistenci kolektivní smlouvy a nesmí se kombinovat s jiným typem.',
            )];
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUMMARY)];
    }

    /** @return list<JmhzControlVerdict> */
    private function ownershipForm(JmhzAttributeProjection $projection): array
    {
        $value = $projection->summary()->value('10220');
        if ($value === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUMMARY)];
        }

        return [in_array($value, ['1', '2', '3', '4'], true)
            ? JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUMMARY)
            : JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUMMARY,
                null,
                "Forma vlastnictví {$value} není v číselníku 1–4.",
            )];
    }

    /** @return list<JmhzControlVerdict> */
    private function decemberOnlyEmployerAnnual(JmhzAttributeProjection $projection): array
    {
        $present = false;
        foreach (['10452', '10038', '10039', '10220', '10214'] as $attributeId) {
            $present = $projection->summary()->has($attributeId) || $present;
        }
        if (!$present) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUMMARY)];
        }
        $month = $projection->submission()->integer('10010');

        return [$month === 12
            ? JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUMMARY)
            : JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_SUMMARY,
                null,
                'Roční údaje zaměstnavatele lze uvést jen v prosincovém hlášení.',
            )];
    }

    private function annualSettlementMonths(JmhzAttributeProjection $projection): array
    {
        $month = $projection->submission()->integer('10010');
        if ($month === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        $annualIds = [
            '10320', '10321', '10322', '10323', '10420', '10421', '10422',
            '10423', '10424', '10425', '10426', '10430', '10454', '10455',
            '10441', '10442', '10443', '10444', '10445', '10446', '10447',
            '10448', '10449', '10450', '10451',
        ];

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($month, $annualIds): ?string {
                if ($month >= 1 && $month <= 3) {
                    return $form->has('10320')
                        ? null
                        : 'Atribut 10320 musí být uveden v lednovém až březnovém podání.';
                }
                foreach ($annualIds as $attributeId) {
                    if ($form->has($attributeId)) {
                        return "Atribut {$attributeId} smí být uveden jen v lednovém až březnovém podání.";
                    }
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function annualRequestMonths(JmhzAttributeProjection $projection): array
    {
        $month = $projection->submission()->integer('10010');
        if ($month === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_SUBMISSION)];
        }

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($month): ?string {
                if ($month === 1 || $month === 2) {
                    return $form->has('10319')
                        ? null
                        : 'Atribut 10319 musí být uveden v lednovém a únorovém podání.';
                }

                return $form->has('10319')
                    ? 'Atribut 10319 smí být uveden jen v lednovém a únorovém podání.'
                    : null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function januaryOnlyAnnualTotals(JmhzAttributeProjection $projection): array
    {
        $month = $projection->submission()->integer('10010');
        if ($month === null || $month === 1) {
            return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
        }
        foreach (['10313', '10317', '10316', '10318', '10311', '10312'] as $attributeId) {
            if ($projection->has($attributeId)) {
                return [JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_SUBMISSION,
                    null,
                    "Atribut {$attributeId} smí být uveden jen v lednovém podání.",
                )];
            }
        }

        return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_SUBMISSION)];
    }

    /** @return list<JmhzControlVerdict> */
    private function annualSettlementSum(JmhzAttributeProjection $projection): array
    {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form): ?string {
                $total = $form->integer('10321');
                $tax = $form->integer('10322');
                $bonus = $form->integer('10323');
                if ($total === null && $tax === null && $bonus === null) {
                    return null;
                }
                if ($total === null || $tax === null || $bonus === null) {
                    return 'Výsledek ročního zúčtování 10321–10323 není úplný.';
                }

                return $total === $tax + $bonus
                    ? null
                    : "Výsledek 10321 = {$total} není součtem 10322 + 10323 ({$tax} + {$bonus}).";
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function annualSettlementResultRequired(JmhzAttributeProjection $projection): array
    {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form): ?string {
                if ($form->boolean('10320') !== true) {
                    return null;
                }
                foreach (['10321', '10322', '10323', '10420', '10454'] as $attributeId) {
                    if (!$form->has($attributeId)) {
                        return "Pro provedené roční zúčtování chybí atribut {$attributeId}.";
                    }
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function annualChildDetailsRequired(JmhzAttributeProjection $projection): array
    {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form): ?string {
                if ($form->boolean('10454') !== true) {
                    return null;
                }
                foreach (['10446', '10447', '10451'] as $attributeId) {
                    if (!$form->has($attributeId)) {
                        return "Pro roční zvýhodnění na dítě chybí atribut {$attributeId}.";
                    }
                }
                if (!$form->has('10448') && !$form->has('10449')) {
                    return 'Pro roční zvýhodnění na dítě chybí datum narození nebo rodné číslo.';
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function annualSpouseDetailsRequired(JmhzAttributeProjection $projection): array
    {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form): ?string {
                if ($form->boolean('10420') !== true) {
                    return null;
                }
                foreach (['10421', '10422', '10425', '10426'] as $attributeId) {
                    if (!$form->has($attributeId)) {
                        return "Pro roční slevu na partnera chybí atribut {$attributeId}.";
                    }
                }
                if (!$form->has('10423') && !$form->has('10424')) {
                    return 'Pro roční slevu na partnera chybí datum narození nebo rodné číslo.';
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function annualResultForbiddenWhenNotPerformed(
        JmhzAttributeProjection $projection,
    ): array {
        $resultIds = [
            '10321', '10322', '10323', '10420', '10421', '10422', '10423',
            '10424', '10425', '10426', '10430', '10539', '10540', '10541',
            '10542', '10454', '10455', '10441', '10442', '10443', '10444',
            '10445', '10446', '10447', '10448', '10449', '10450', '10451',
        ];

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($resultIds): ?string {
                if ($form->boolean('10320') !== false) {
                    return null;
                }
                foreach ($resultIds as $attributeId) {
                    if ($form->has($attributeId)) {
                        return "Při neprovedeném zúčtování nesmí být uveden atribut {$attributeId}.";
                    }
                }

                return null;
            },
        );
    }

    // --- daňové slevy a souhrnná data -------------------------------------

    /**
     * Bez podepsaného prohlášení poplatníka nelze uplatnit žádnou slevu ani
     * daňové zvýhodnění. Je to nejčastější systémová chyba, protože rozpad
     * slev vzniká ve výpočtu nezávisle na tom, jestli prohlášení existuje.
     *
     * @return list<JmhzControlVerdict>
     */
    private function noCreditsWithoutDeclaration(JmhzAttributeProjection $projection): array
    {
        $forbidden = [
            '10299', '10300', '10301', '10302', '10303', '10453', '10431',
            '10432', '10433', '10434', '10435', '10436', '10437', '10438',
            '10439', '10440', '10304', '10306',
        ];

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($forbidden): ?string {
                if ($form->boolean('10419') !== false) {
                    return null;
                }
                foreach ($forbidden as $attributeId) {
                    $value = $form->value($attributeId);
                    // Vykázaná nula slevu neuplatňuje, a katalog zakazuje
                    // „nabývat hodnot", ne uvést nulu.
                    if ($value !== null && $value !== '0' && $value !== 'false') {
                        return 'Bez podepsaného prohlášení poplatníka nesmí být'
                            . " uplatněna sleva ani zvýhodnění ({$attributeId}).";
                    }
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function summaryDataOnlyOnPrimary(JmhzAttributeProjection $projection): array
    {
        $summaryAttributes = [
            '10286', '10416', '10289', '10417', '10292', '10293', '10294',
            '10295', '10296', '10418', '10419', '10297', '10298', '10299',
            '10300', '10301', '10302', '10303', '10453', '10431', '10432',
            '10433', '10434', '10435', '10436', '10437', '10438', '10439',
            '10440', '10304', '10305', '10306', '10307', '10308', '10309',
            '10310', '10313', '10317', '10316', '10318', '10311', '10312',
            '10319', '10320', '10321', '10322', '10323', '10420', '10421',
            '10422', '10423', '10424', '10425', '10426', '10430', '10539',
            '10540', '10541', '10542', '10454', '10455', '10441', '10442',
            '10443', '10444', '10445', '10446', '10447', '10448', '10449',
            '10450', '10451', '10344', '10116', '10348', '10349', '10347',
            '10350', '10351', '10352', '10353', '10482', '10371',
        ];

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($summaryAttributes): ?string {
                if ($form->boolean('10495') !== false) {
                    return null;
                }
                foreach ($summaryAttributes as $attributeId) {
                    if ($form->has($attributeId)) {
                        return 'Souhrnná data zaměstnance patří jen k primárnímu'
                            . " pracovněprávnímu vztahu; vykázán atribut {$attributeId}.";
                    }
                }

                return null;
            },
        );
    }

    // --- součty a slevy nad rámec prvního profilu -------------------------

    /**
     * Součtové pravidlo, které platí až od nenulového úhrnu. Nulový úhrn se
     * nekontroluje — katalog ho podmiňuje výslovně a rozpad k nule se u ELDP
     * běžně neuvádí.
     *
     * @param list<string> $parts
     * @return list<JmhzControlVerdict>
     */
    private function sumMatchesWhenPositive(
        JmhzAttributeProjection $projection,
        string $totalId,
        array $parts,
    ): array {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($totalId, $parts): ?string {
                $total = $form->integer($totalId);
                if ($total === null || $total <= 0) {
                    return null;
                }
                $sum = 0;
                foreach ($parts as $part) {
                    $sum += $form->integer($part) ?? 0;
                }
                if ($total !== $sum) {
                    return "Úhrn {$totalId} = {$total} neodpovídá součtu složek {$sum}.";
                }

                return null;
            },
        );
    }

    /**
     * Slevu lze vykázat jen tehdy, když je uplatněná. Vykázaná částka bez
     * příznaku je buď sleva, na kterou není nárok, nebo chybějící příznak —
     * obojí ČSSZ zamítne.
     *
     * @return list<JmhzControlVerdict>
     */
    private function onlyWithFlag(
        JmhzAttributeProjection $projection,
        string $amountId,
        string $flagId,
    ): array {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($amountId, $flagId): ?string {
                if (!$form->has($amountId)) {
                    return null;
                }
                if ($form->boolean($flagId) === true) {
                    return null;
                }

                return "Atribut {$amountId} smí být vyplněn jen při uplatněné slevě ({$flagId}).";
            },
        );
    }

    /**
     * Tolerance úhrnu slev proti procentu z vyměřovacích základů. Stejná úvaha
     * jako u kontroly 168: sleva se počítá a zaokrouhluje u každého zaměstnance
     * zvlášť, takže úhrn nikdy nesedí na procento z celku přesně.
     *
     * @return list<JmhzControlVerdict>
     */
    private function employeeDiscountTolerance(
        JmhzAttributeProjection $projection,
        string $discountId,
        string $baseId,
        string $rateKey,
    ): array {
        $pvpoj = $projection->pvpoj();
        $discount = $pvpoj->integer($discountId);
        $base = $pvpoj->integer($baseId);
        if ($discount === null && $base === null) {
            return [JmhzControlVerdict::notApplicable(JmhzAttributeProjection::PART_PVPOJ)];
        }
        if ($discount === null || $base === null) {
            return [JmhzControlVerdict::failed(
                JmhzAttributeProjection::PART_PVPOJ,
                null,
                "Vykázán jen jeden z údajů {$baseId} a {$discountId}; slevu nelze ověřit.",
            )];
        }
        if ($base === 0) {
            return $discount === 0
                ? [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)]
                : [JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_PVPOJ,
                    null,
                    "Sleva {$discountId} = {$discount} Kč je vykázána bez vyměřovacího základu.",
                )];
        }
        [$numerator, $denominator] = $this->parameters->multiplyExact(
            $base,
            $rateKey,
            $this->periodStart($projection),
        );
        $deviation = abs($numerator - $discount * $denominator);
        if ($deviation <= 100 * $denominator
            || $deviation * 100 <= abs($discount * $denominator)
        ) {
            return [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_PVPOJ)];
        }

        return [JmhzControlVerdict::failed(
            JmhzAttributeProjection::PART_PVPOJ,
            null,
            "Úhrn slev {$discountId} = {$discount} Kč je mimo toleranci vůči úhrnu"
                . " vyměřovacích základů {$base} Kč.",
        )];
    }

    /** @return list<JmhzControlVerdict> */
    private function orchardDiscountAgainstAverageWage(JmhzAttributeProjection $projection): array
    {
        $limit = $this->parameters->integerValue(
            'source_row_16',
            $this->periodStart($projection),
        );

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($limit): ?string {
                $base = $form->integer('10477');
                if ($base === null || $base <= $limit) {
                    return null;
                }
                if ($form->boolean('10546') !== true) {
                    return null;
                }

                return "Sleva pro ovocnářství se nesmí uplatnit, je-li vyměřovací základ"
                    . " {$base} Kč vyšší než {$limit} Kč.";
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function orchardDiscountMatchesInsurance(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            if ($form->boolean('10546') !== true) {
                return null;
            }
            $discount = $form->integer('10547');
            $insurance = $form->integer('10370');
            if ($discount === null || $insurance === null) {
                return 'Uplatněná sleva pro ovocnářství vyžaduje slevu i pojistné zaměstnance.';
            }
            if ($discount !== $insurance) {
                return "Sleva pro ovocnářství {$discount} Kč se musí rovnat pojistnému"
                    . " zaměstnance {$insurance} Kč.";
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function discountsAreExclusive(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            if ($form->boolean('10490') === true && $form->boolean('10546') === true) {
                return 'Obecnou slevu a slevu pro ovocnářství nelze uplatnit současně.';
            }

            return null;
        });
    }

    /**
     * Obě pravidla o vyměřovacím základu zaměstnance katalog podmiňuje tím, že
     * NEJDE o vyjmenované datové scénáře — činnosti K až S, pěstoun, a činnosti
     * 1 až 9 s příznakem specifické skupiny. Rozhodnout to jde jedině z druhu
     * činnosti (10239), případně z příznaku 10502.
     *
     * Doloženo skutečně přijatým podáním: ČSSZ přijala hlášení s nenulovým
     * 10477 bez jediné složky 10478–10480 a bez 10239. Vynucovat pravidlo bez
     * znalosti scénáře by tedy odmítalo podání, která projdou — a to je horší
     * chyba než kontrolu nevykonat.
     */
    /**
     * Větve formuláře, na které kontroly nad § 5a (216, 284) dopadají,
     * a větve, kde jsou mimo datový scénář.
     *
     * Rozhoduje VĚTEV, ne atribut 10239. Ten je podle pokynů (kap. 1.4.13,
     * s. 69) povinný jen tehdy, když zaměstnanec nemá přidělené OIČ ani ID PPV
     * — v běžném scénáři tedy chybí vždycky, a podmiňovat jím vyhodnocení
     * znamenalo, že se obě kontroly nespustily nikdy. Matice datových scénářů
     * (`datove_scenare_interakce_povinnosti_MH_1.4.0.2.xlsx`, list MASTER)
     * přitom vede dílčí základy jako CORE DATA právě u `bezPriznaku`
     * a odloženého příjmu, kdežto u činností K–S a u pěstouna vůbec.
     *
     * Ověřeno odesláním: podání ve větvi `bezPriznaku` s nenulovým 10477 a bez
     * § 5a vrátí blokující 20216 i 20284.
     *
     * @var array<string, bool> true = kontrola platí, false = mimo scénář
     */
    private const PARAGRAPH5_SCOPE = [
        'bezPriznaku' => true,
        'odlozenyPrijem' => true,
        'cinnostKS' => false,
        'pestoun' => false,
    ];

    /**
     * Vrací true, když se u součásti nedá rozhodnout, jestli § 5a platí —
     * tedy u větví, které matice scénářů nepokrývá. Neznámá větev se nesmí
     * tvářit ani jako splněná kontrola, ani jako porušená.
     */
    private function requiresActivityScenario(JmhzAttributeScope $form): bool
    {
        foreach ($form->bodies() as $body) {
            if (array_key_exists($body, self::PARAGRAPH5_SCOPE)) {
                return false;
            }
        }

        return true;
    }

    /** Součást, na kterou § 5a podle matice datových scénářů nedopadá. */
    private function outsideParagraph5Scenario(JmhzAttributeScope $form): bool
    {
        foreach ($form->bodies() as $body) {
            if ((self::PARAGRAPH5_SCOPE[$body] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kontrola 216: 10477 = 10478 + 10479 + 10480.
     *
     * Rozhoduje se per součást, ne za celé podání: v jednom hlášení můžou být
     * vedle sebe součásti ve větvi, kde pravidlo platí, i ve větvi, kde je
     * mimo scénář. Jedna z nich nesmí umlčet druhou.
     *
     * @return list<JmhzControlVerdict>
     */
    private function assessmentBaseSum(JmhzAttributeProjection $projection): array
    {
        if ($this->lacksActivityScenario($projection)) {
            return [$this->activityScenarioUnknown()];
        }

        return $this->perForm($projection, function (JmhzAttributeScope $form): ?string {
            if ($this->outsideParagraph5Scenario($form)) {
                return null;
            }
            $total = $form->integer('10477');
            if ($total === null || $total <= 0) {
                return null;
            }
            $sum = 0;
            foreach (['10478', '10479', '10480'] as $part) {
                $sum += $form->integer($part) ?? 0;
            }
            if ($total !== $sum) {
                return "Vyměřovací základ 10477 = {$total} neodpovídá součtu dílčích"
                    . " základů podle § 5a ({$sum}).";
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function assessmentBaseComponentPresence(JmhzAttributeProjection $projection): array
    {
        if ($this->lacksActivityScenario($projection)) {
            return [$this->activityScenarioUnknown()];
        }

        return $this->assessmentBaseHasComponent($projection);
    }

    private function lacksActivityScenario(JmhzAttributeProjection $projection): bool
    {
        foreach ($projection->forms() as $form) {
            if ($this->requiresActivityScenario($form)) {
                return true;
            }
        }

        return false;
    }

    private function activityScenarioUnknown(): JmhzControlVerdict
    {
        return JmhzControlVerdict::notEvaluable(
            JmhzAttributeProjection::PART_FORM,
            'Větev formuláře součásti není v matici datových scénářů, takže'
                . ' nelze rozhodnout, jestli § 5a na tuhle součást dopadá.',
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function assessmentBaseHasComponent(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, function (JmhzAttributeScope $form): ?string {
            if ($this->outsideParagraph5Scenario($form)) {
                return null;
            }
            $base = $form->integer('10477');
            if ($base === null || $base === 0) {
                return null;
            }
            foreach (['10478', '10479', '10480'] as $part) {
                if ($form->has($part)) {
                    return null;
                }
            }

            return 'Nenulový vyměřovací základ zaměstnance neuvádí, ze které složky'
                . ' se pojistné odvádí.';
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function orchardDiscountOnlyOnDpp(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            if ($form->boolean('10546') !== true) {
                return null;
            }
            $activity = $form->value('10239');
            if ($activity === null) {
                return null;
            }
            // Dohody o provedení práce mají v číselníku druhů činnosti kódy
            // T až ZC; sleva pro ovocnářství se jinam nevztahuje.
            if (!in_array(
                $activity,
                ['T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC'],
                true,
            )) {
                return "Sleva pro ovocnářství se vztahuje jen na dohody o provedení"
                    . " práce; druh činnosti je {$activity}.";
            }

            return null;
        });
    }

    // --- kód ELDP ---------------------------------------------------------

    /** @return list<JmhzControlVerdict> */
    private function checkEldpCodeFromCodebook(JmhzAttributeProjection $projection): array
    {
        $catalog = $this->codebooks;
        if ($catalog === null) {
            return [JmhzControlVerdict::unverifiable(
                JmhzAttributeProjection::PART_FORM,
                'Číselník kódů ELDP není k dispozici.',
            )];
        }

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($catalog): ?string {
                foreach ($form->all('10240') as $occurrence) {
                    try {
                        $catalog->requireValue('kod_eldp', $occurrence->value);
                    } catch (JmhzCodebookValueException | JmhzCodebookUnavailableException $exception) {
                        return $exception->getMessage();
                    }
                }

                return null;
            },
        );
    }

    /** @return list<JmhzControlVerdict> */
    private function eldpCodeMatchesActivity(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $activity = $form->value('10239');
            if ($activity === null) {
                return null;
            }
            foreach ($form->all('10240') as $occurrence) {
                if (self::eldpPosition($occurrence->value, 1) !== $activity) {
                    return "První pozice kódu ELDP {$occurrence->value} neodpovídá"
                        . " druhu činnosti {$activity}.";
                }
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function eldpCodeRequiredWithDays(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            foreach ($form->groupedBy(['10356', '10240'], self::ELDP_SECTION_DEPTH) as $section) {
                $days = $section['10356'] ?? null;
                if ($days === null || (int) $days <= 0) {
                    continue;
                }
                if (($section['10240'] ?? null) === null) {
                    return 'Započtené dny důchodového pojištění vyžadují uvedený kód ELDP.';
                }
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function eldpDetailEmptyWithoutCode(JmhzAttributeProjection $projection): array
    {
        $detail = [
            '10241', '10242', '10245', '10357', '10358', '10359', '10360',
            '10362', '10536', '10375', '10462', '10463', '10464', '10465',
            '10466', '10468', '10469',
        ];

        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($detail): ?string {
                foreach ($form->groupedBy([...$detail, '10240'], self::ELDP_SECTION_DEPTH) as $section) {
                    if (($section['10240'] ?? null) !== null) {
                        continue;
                    }
                    foreach ($detail as $attributeId) {
                        if (($section[$attributeId] ?? null) !== null) {
                            return "Bez kódu ELDP nesmí být vyplněn atribut {$attributeId}.";
                        }
                    }
                }

                return null;
            },
        );
    }

    /**
     * Započtené dny podle druhé pozice kódu ELDP. Interval se počítá včetně
     * krajních dnů — ze stejného důvodu jako u kontroly 134.
     *
     * @return list<JmhzControlVerdict>
     */
    private function insuranceDaysAgainstEldpCode(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $span = self::intervalDays($form->value('10354'), $form->value('10355'));
            foreach (
                $form->groupedBy(['10240', '10356', '10375'], self::ELDP_SECTION_DEPTH)
                as $section
            ) {
                $code = $section['10240'] ?? null;
                $days = $section['10356'] ?? null;
                if ($code === null || $days === null) {
                    continue;
                }
                $second = self::eldpPosition($code, 2);
                $third = self::eldpPosition($code, 3);
                if ($second === 'P' && (int) $days !== 0) {
                    return "Kód ELDP {$code} vyžaduje nulové započtené dny, uvedeno {$days}.";
                }
                if ($second === 'V' && $span !== null && (int) $days > $span) {
                    return "Kód ELDP {$code} připouští nejvýš {$span} započtených dnů,"
                        . " uvedeno {$days}.";
                }
                // Třetí pravidlo katalogu: mimo P a V, s třetí pozicí „T"
                // a uvedenými odečtenými dobami je počet dnů dán přesně.
                $deducted = $section['10375'] ?? null;
                if ($second !== 'P' && $second !== 'V' && $third === 'T'
                    && $deducted !== null && $span !== null
                ) {
                    $expected = $span - (int) $deducted;
                    if ((int) $days !== $expected) {
                        return "Kód ELDP {$code} vyžaduje započtené dny rovné intervalu"
                            . " zmenšenému o odečtené doby ({$expected}), uvedeno {$days}.";
                    }
                }
            }

            return null;
        });
    }

    /** @return list<JmhzControlVerdict> */
    private function insuranceDaysAgainstDeductedTime(JmhzAttributeProjection $projection): array
    {
        return $this->perForm($projection, static function (JmhzAttributeScope $form): ?string {
            $span = self::intervalDays($form->value('10354'), $form->value('10355'));
            if ($span === null) {
                return null;
            }
            $smallScale = $form->value('10243') === 'A';
            foreach ($form->groupedBy(['10240', '10356', '10375'], self::ELDP_SECTION_DEPTH) as $section) {
                $code = $section['10240'] ?? null;
                $days = $section['10356'] ?? null;
                $deducted = $section['10375'] ?? null;
                if ($code === null || $days === null || $deducted === null) {
                    continue;
                }
                $second = self::eldpPosition($code, 2);
                $third = self::eldpPosition($code, 3);
                $first = self::eldpPosition($code, 1);
                if ($second === 'P' || $second === 'V' || $third === 'T') {
                    continue;
                }
                if (!$smallScale
                    && !in_array($first, ['T', 'U', 'V', 'W', 'X', 'Y', 'Z'], true)
                ) {
                    continue;
                }
                $expected = $span - (int) $deducted;
                if ((int) $days !== $expected) {
                    return "Započtené dny {$days} neodpovídají intervalu zmenšenému"
                        . " o odečtené doby ({$expected}).";
                }
            }

            return null;
        });
    }

    // --- pomocné ----------------------------------------------------------

    /**
     * Kontroly, jejichž předpoklad v podání nenastal. Fail-closed: jakmile se
     * rozhodný atribut nebo typ podání objeví, vrací se `null` a kontrola
     * propadne do neimplementovaných, tedy do viditelné mezery v pokrytí.
     */
    private function outOfProfile(
        int $controlId,
        JmhzAttributeProjection $projection,
    ): ?JmhzControlVerdict {
        $rule = self::OUT_OF_PROFILE[$controlId] ?? null;
        if ($rule === null) {
            return null;
        }
        foreach ($rule['absent'] as $attributeId) {
            if ($projection->has($attributeId)) {
                return null;
            }
        }
        if ($rule['not_type'] !== []) {
            $type = $projection->submission()->value('10007');
            if ($type === null || in_array($type, $rule['not_type'], true)) {
                return null;
            }
        }

        return JmhzControlVerdict::notApplicable(
            JmhzAttributeProjection::PART_SUBMISSION,
            $rule['reason'],
        );
    }

    /**
     * „Je-li X nula, nesmí být vyplněné Y." Vykázaná nula hodnotou není —
     * katalog zakazuje nabývat hodnot, ne uvést nulu.
     *
     * @param list<string> $dependents
     * @return list<JmhzControlVerdict>
     */
    private function emptyWhenZero(
        JmhzAttributeProjection $projection,
        string $triggerId,
        array $dependents,
    ): array {
        return $this->perForm(
            $projection,
            static function (JmhzAttributeScope $form) use ($triggerId, $dependents): ?string {
                $trigger = $form->scaled($triggerId);
                if ($trigger === null || $trigger[0] !== 0) {
                    return null;
                }
                foreach ($dependents as $attributeId) {
                    if (!$form->has($attributeId)) {
                        continue;
                    }
                    $value = $form->scaled($attributeId);
                    if ($value === null || $value[0] !== 0) {
                        return "Atribut {$triggerId} je nula, ale {$attributeId}"
                            . ' je vyplněný.';
                    }
                }

                return null;
            },
        );
    }

    /**
     * Pozice kódu ELDP. Kód je tří- až čtyřznakový: první pozice je druh
     * činnosti o jednom nebo dvou znacích (`1`, `ZC`), druhá a třetí jsou
     * vždy jednoznakové. Počítá se proto od konce.
     */
    private static function eldpPosition(string $code, int $position): string
    {
        $length = strlen($code);
        if ($length < 3) {
            return '';
        }

        return match ($position) {
            1 => substr($code, 0, $length - 2),
            2 => $code[$length - 2],
            3 => $code[$length - 1],
            default => '',
        };
    }

    /** Počet dnů intervalu včetně krajních dnů. */
    private static function intervalDays(?string $from, ?string $to): ?int
    {
        $start = self::calendarDay($from);
        $end = self::calendarDay($to);
        if ($start === null || $end === null) {
            return null;
        }

        return (int) $start->diff($end)->format('%r%a') + 1;
    }

    /**
     * Kalendářní den z textu. `createFromFormat` přetečené datum nezamítne —
     * `2026-02-30` tiše vrátí 2. březen, takže by kontrola počítala interval
     * z data, které v podání není. Výsledek se proto porovnává zpět se vstupem.
     */
    private static function calendarDay(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('UTC'),
        );

        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $value
            ? $parsed
            : null;
    }

    /** Datum vyplnění podání (10005) jako kalendářní den. */
    private function filledOn(JmhzAttributeProjection $projection): ?string
    {
        $value = $projection->submission()->value('10005');
        if ($value === null || strlen($value) < 10) {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function daysInReportedMonth(JmhzAttributeProjection $projection): ?int
    {
        $period = $this->period($projection);
        if ($period === null) {
            return null;
        }
        [$year, $month] = $period;
        if ($month < 1 || $month > 12) {
            return null;
        }
        $first = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%04d-%02d-01', $year, $month),
            new \DateTimeZone('UTC'),
        );

        return $first instanceof \DateTimeImmutable ? (int) $first->format('t') : null;
    }


    /**
     * Projde všechny součásti podání. Nález se váže na konkrétní součást,
     * splnění se hlásí jednou souhrnně — u patnácti set formulářů by jinak
     * protokol utopil skutečné vady v tisících zelených řádků.
     *
     * Součást se počítá za vyhodnocenou jen tehdy, když kontrola opravdu
     * přečetla aspoň jeden vykázaný atribut. Bez toho by se za splněnou vydala
     * i kontrola, jejíž první podmínka se opírá o nevykázaný údaj a která proto
     * skončí dřív, než se k čemukoli dostane — „prošlo" a „nebylo co číst" jsou
     * dvě různé odpovědi a jen jedna z nich smí uklidnit uživatele.
     *
     * @param callable(JmhzAttributeScope):?string $check
     * @return list<JmhzControlVerdict>
     */
    private function perForm(JmhzAttributeProjection $projection, callable $check): array
    {
        $verdicts = [];
        $evaluated = 0;
        foreach ($projection->forms() as $form) {
            $form->resetReadCount();
            $message = $check($form);
            if ($form->readCount() === 0) {
                continue;
            }
            ++$evaluated;
            if ($message !== null) {
                $verdicts[] = JmhzControlVerdict::failed(
                    JmhzAttributeProjection::PART_FORM,
                    $form->ordinal,
                    $message,
                );
            }
        }
        if ($evaluated === 0) {
            return [JmhzControlVerdict::notApplicable(
                JmhzAttributeProjection::PART_FORM,
                'Žádná součást nevykazuje údaje, na kterých kontrola stojí.',
            )];
        }

        return $verdicts === []
            ? [JmhzControlVerdict::passed(JmhzAttributeProjection::PART_FORM)]
            : $verdicts;
    }

    /** @return array{0:int,1:int}|null */
    private function period(JmhzAttributeProjection $projection): ?array
    {
        $submission = $projection->submission();
        $month = $submission->integer('10010');
        $year = $submission->integer('10011');
        if ($month === null || $year === null) {
            return null;
        }

        return [$year, $month];
    }

    /**
     * První den vykazovaného období. Parametrické konstanty jsou účinné k datu
     * a rozhoduje období, za které se hlásí, ne den odeslání.
     */
    private function periodStart(JmhzAttributeProjection $projection): string
    {
        $period = $this->period($projection);
        if ($period === null) {
            throw new JmhzXmlException(
                'jmhz_control_period_missing',
                'Podání bez hlášeného období nelze proti katalogu kontrol vyhodnotit.',
            );
        }

        return sprintf('%04d-%02d-01', $period[0], $period[1]);
    }

    /**
     * @param array{0:int,1:int} $left
     * @param array{0:int,1:int} $right
     */
    private static function compareScaled(array $left, array $right): int
    {
        $scale = max($left[1], $right[1]);

        return ($left[0] * 10 ** ($scale - $left[1]))
            <=> ($right[0] * 10 ** ($scale - $right[1]));
    }
}
