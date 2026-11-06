<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Garnishment\EnforcementEvidenceScope;
use MyInvoice\Service\Payroll\Garnishment\EnforcementEvidenceSource;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthEvidence;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzOrdinaryEvidenceBuilder
{
    public const BUILDER_VERSION = 'jmhz-ordinary-evidence.v1';

    private const FACT_KEYS = [
        'reportable_wage_deductions_recorded',
        'employee_social_discount_claimed',
        'specific_legal_fact_occurred',
        'ozp_employment_support_claimed',
        'deep_mining_work_occurred',
    ];

    private ?JmhzScenarioSelectorResolver $scenarioSelector = null;
    private ?JmhzScenarioRequirementSourceCatalog $scenarioRequirements = null;

    /**
     * Ověřené a dekódované snímky revize podle pole (`input` / `result`).
     *
     * Builder se volá za KAŽDÝ pracovní vztah revize nad týmž zdrojem a snímky
     * mají u stovek lidí jednotky MB. Opakované ověření otisku, dekódování
     * a kanonická kontrola z přehledu evidence dělaly kvadratickou úlohu
     * (226 vztahů ≈ 40 s). Výsledek je čistá funkce dvojice (JSON, otisk),
     * takže se znovu použije jen při shodě obou; chyby se neukládají.
     *
     * @var array<string,array{json:string,hash:string,decoded:array<string,mixed>}>
     */
    private array $canonicalSnapshots = [];

    /**
     * @param array<string,mixed> $facts
     * @return array<string,bool>
     */
    public static function normalizeFacts(array $facts): array
    {
        $keys = array_keys($facts);
        sort($keys, SORT_STRING);
        $expected = self::FACT_KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new JmhzOrdinaryEvidenceException(
                'jmhz_ordinary_evidence_incomplete',
                'Potvrzení musí obsahovat přesně všech pět právních skutečností.',
            );
        }
        $normalized = [];
        foreach (self::FACT_KEYS as $key) {
            if (!is_bool($facts[$key])) {
                throw new JmhzOrdinaryEvidenceException(
                    'jmhz_ordinary_evidence_invalid',
                    'Každá právní skutečnost musí být výslovné Ano nebo Ne.',
                );
            }
            /*
             * `reportable_wage_deductions_recorded` (10116) se NEPOTVRZUJE,
             * odvozuje se ze zmrazené revize — srážka ze mzdy je fakt v datech,
             * ne rozhodnutí účetní. Hodnotu proto přepíše builder; tady se jen
             * nevyžaduje „Ne", aby existující volání s `false` prošla.
             *
             * Podpora zaměstnávání OZP (IN28) smí být Ano: výdělek OZP a srážky
             * ve prospěch zaměstnavatele (10347 až 10353) builder sestaví
             * z podkladů, které k potvrzení patří ({@see self::ozpWage()}).
             * Zbylé tři skutečnosti potvrzuje účetní a profil je má za „Ne".
             */
            $passThrough = in_array(
                $key,
                ['reportable_wage_deductions_recorded', 'ozp_employment_support_claimed'],
                true,
            );
            if (!$passThrough && $facts[$key] !== false) {
                throw new JmhzOrdinaryEvidenceException(
                    'jmhz_ordinary_evidence_positive_unsupported',
                    'Ordinary profil podporuje jen výslovné Ne u potvrzovaných skutečností.',
                );
            }
            $normalized[$key] = $passThrough ? $facts[$key] : false;
        }
        return $normalized;
    }

    /**
     * Ordinary evidence se zmrazuje ZA PRACOVNÍ VZTAH, ne za revizi.
     *
     * Úložiště je unikátní na `(supplier_id, source_revision_id, employee_id,
     * employment_id)`, takže jedna revize nese jednu evidenci za každý vztah.
     * Dřív si builder vztah odvozoval z toho, že revize má právě jednu osobu
     * s právě jedním vztahem — firma s víc zaměstnanci proto ordinary evidenci
     * nezmrazila vůbec. Cíl vztahu je proto explicitní parametr; přísnost
     * kontrol zůstává, jen se přestalo vyžadovat, že je vztah v revizi jediný.
     *
     * @param array<string,mixed> $source
     * @param array<string,bool> $facts
     * @param array<string,mixed>|null $ozpWage podklady výdělku OZP, které zadává
     *        účetní (10347 datum úhrady, 10348 hotovost); jen u vztahu
     *        s podporou zaměstnávání OZP (IN28)
     */
    public function build(
        int $supplierId,
        array $source,
        int $targetEmploymentId,
        array $facts,
        int $confirmedBy,
        string $confirmedAt,
        string $sourceKind = 'explicit_confirmation',
        ?array $ozpWage = null,
        ?int $riskShiftCount = null,
    ): JmhzOrdinaryEvidenceSnapshot {
        if ($targetEmploymentId <= 0) {
            throw new \InvalidArgumentException('Pracovní vztah musí být kladné číslo.');
        }
        $facts = self::normalizeFacts($facts);
        $revision = $this->object($source['revision'] ?? null, 'revision');
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        $runId = $this->positiveInt($revision['run_id'] ?? null, 'revision.run_id');
        $revisionNo = $this->positiveInt($revision['revision_no'] ?? null, 'revision.revision_no');
        if (($revision['status'] ?? null) !== 'approved'
            || !in_array($revision['revision_kind'] ?? null, ['regular', 'correction'], true)
            || ($revision['current_revision_no'] ?? null) !== $revisionNo
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_revision_not_current_approved',
                'Měsíční podklady JMHZ vyžadují aktuální schválenou revizi mzdy.',
            );
        }
        $periodStart = $this->date($revision['period_start'] ?? null, 'period_start');
        if (!str_ends_with($periodStart, '-01')) {
            $this->invalid('jmhz_ordinary_evidence_period_invalid', 'Období nezačíná prvním dnem měsíce.');
        }
        $input = $this->canonicalSnapshot(
            $revision['input_snapshot_json'] ?? null,
            $revision['input_snapshot_hash'] ?? null,
            'input',
        );
        $result = $this->canonicalSnapshot(
            $revision['result_snapshot_json'] ?? null,
            $revision['result_snapshot_hash'] ?? null,
            'result',
        );
        if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2'
            || ($input['supplier_id'] ?? null) !== $supplierId
            || ($input['period_start'] ?? null) !== $periodStart
            || ($result['schema_version'] ?? null) !== 'payroll-run-result.v2'
            || ($result['source_snapshot_hash'] ?? null) !== ($revision['input_snapshot_hash'] ?? null)
        ) {
            $this->invalid('jmhz_ordinary_evidence_source_mismatch', 'Zmrazený vstup neodpovídá firmě nebo období.');
        }
        [$employeeId, $employmentId, $person, $employment] = $this->targetEmployment(
            $input,
            $targetEmploymentId,
        );
        $profileSource = $this->ordinaryProfileSource($employment, $sourceKind);
        $facts['reportable_wage_deductions_recorded'] =
            $this->resolveWageDeductionsRecorded($person, $result, $employeeId);
        $term = $this->object($employment['term'] ?? null, 'term');
        $facts['ozp_employment_support_claimed'] = $this->ozpEmploymentSupport(
            $employment,
            $sourceKind,
            $facts['ozp_employment_support_claimed'],
        );
        $employmentSource = $this->object($employment['employment'] ?? null, 'employment');
        $selectorActivityCode = is_string($term['activity_code'] ?? null)
            ? $term['activity_code']
            : null;
        $selectorRelationshipDetailCode = is_string($term['jmhz_relationship_detail_code'] ?? null)
            ? $term['jmhz_relationship_detail_code']
            : null;
        $selection = $this->scenarioSelector()->resolve(
            $selectorActivityCode,
            $selectorRelationshipDetailCode,
        );
        $scenarioResolution = $selection['evidence'] ?? null;
        $scenarioKey = is_array($scenarioResolution)
            ? ($scenarioResolution['scenario_key'] ?? null)
            : null;
        $relationType = $employmentSource['relation_type'] ?? null;
        $supportedProfile = is_string($scenarioKey)
            && JmhzScenarioFormProfile::preparable(
                $scenarioKey,
                $selectorActivityCode,
                $selectorRelationshipDetailCode,
                is_string($relationType) ? $relationType : '',
            );
        if ($selection['supported'] !== true || !$supportedProfile) {
            $this->invalid(
                'jmhz_ordinary_evidence_scenario_unsupported',
                'Revize nepatří do podporovaného běžného profilu JMHZ.',
                ['reason' => $selection['issue_code'] ?? 'unsupported_profile'],
            );
        }

        $catalog = $this->scenarioRequirements();
        $interactions = [];
        foreach (['IN13', 'IN28', 'IN30'] as $interactionId) {
            $definition = $catalog->interaction($interactionId);
            $interactions[] = [
                'interaction_id' => $interactionId,
                'triggered' => $interactionId === 'IN28'
                    && $facts['ozp_employment_support_claimed'],
                'row_sha256' => $definition->rowHash,
            ];
        }
        $in36 = $catalog->interaction('IN36');
        $requirements = JmhzOrdinaryEvidenceCompatibility::requirementRowHashes($catalog, $scenarioKey);
        // Matice scénáře 1 vede oba atributy jako povinné jádro; chybí-li
        // tam, je připnutý katalog poškozený, ne scénář bez srážek.
        if ($scenarioKey === 'scenario_1'
            && array_map('strval', array_keys($requirements)) !== JmhzOrdinaryEvidenceCompatibility::ATTRIBUTE_IDS
        ) {
            $this->invalid('jmhz_ordinary_evidence_catalog_mismatch', 'Katalog ordinary evidence není úplný.');
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')->format('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{6})?Z$/D', $confirmedAt) !== 1) {
            $this->invalid('jmhz_ordinary_evidence_confirmation_invalid', 'Čas potvrzení není kanonický UTC timestamp.');
        }
        if (!$facts['ozp_employment_support_claimed'] && $ozpWage !== null) {
            $this->invalid(
                'jmhz_ordinary_evidence_ozp_wage_unexpected',
                'Výdělek OZP (10347 až 10349) se uvádí jen u vztahu s podporou zaměstnávání OZP.',
            );
        }
        $ozpWageBlock = $facts['ozp_employment_support_claimed']
            ? $this->ozpWage($ozpWage, $term, $person, $result, $employeeId, $periodStart, $confirmedAt)
            : null;
        $payload = [
            'schema_reference' => JmhzOrdinaryEvidenceSnapshot::SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'run_id' => $runId,
                'source_revision_id' => $revisionId,
                'revision_no' => $revisionNo,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'scenario_key' => $scenarioKey,
            ],
            'specification' => [
                'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
                'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
                'attribute_requirement_row_sha256' => $requirements,
            ],
            'source_revision' => [
                'input_snapshot_hash' => $revision['input_snapshot_hash'],
                'result_snapshot_hash' => $revision['result_snapshot_hash'],
                'ruleset_manifest_hash' => $revision['ruleset_manifest_hash'],
            ],
            'attribute_values' => [
                '10116' => $facts['reportable_wage_deductions_recorded'],
                '10546' => $facts['employee_social_discount_claimed'],
            ],
            'interaction_decisions' => $interactions,
            'derived_interactions' => [[
                'interaction_id' => 'IN36',
                'triggered' => false,
                'source_attribute_id' => '10546',
                'row_sha256' => $in36->rowHash,
            ]],
            'confirmation' => array_filter([
                'source_kind' => $sourceKind,
                'source_term_id' => $profileSource['source_term_id'] ?? null,
                'source_term_row_version' => $profileSource['source_term_row_version'] ?? null,
                'confirmed_by_user_id' => $confirmedBy,
                'confirmed_at' => $confirmedAt,
            ], static fn (mixed $value): bool => $value !== null),
        ];
        // Klíč jen u vztahu s IN28: evidence ostatních vztahů zůstává
        // bajtově stejná jako před podporou výdělku OZP.
        if ($ozpWageBlock !== null) {
            $payload['ozp_wage'] = $ozpWageBlock;
        }
        /*
         * Počet směn při rizikové práci (10580, verze 2027, IN42: kategorizace
         * rizika 1 a odpracované hodiny). Mzdy směny neevidují, proto ho
         * zadává účetní za měsíc; jen u vztahu zařazeného do kategorie 4
         * (§ 5a odst. 1 písm. c) ZPSZ). Bez něj zůstává evidence beze změny.
         */
        $is2027 = JmhzSpecVersion::forPeriodStart($periodStart) === JmhzSpecVersion::V2027;
        if ($riskShiftCount === null
            && $is2027
            && ($term['social_employer_rate_category'] ?? null) === 'risk_employment'
            && self::riskShiftCountExpected($employment)
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_risk_shifts_required',
                'Doplňte počet směn odpracovaných při rizikové práci v měsíci (10580); mzdy směny neevidují.',
            );
        }
        if ($riskShiftCount !== null) {
            if (($term['social_employer_rate_category'] ?? null) !== 'risk_employment') {
                $this->invalid(
                    'jmhz_ordinary_evidence_risk_shifts_unexpected',
                    'Počet směn při rizikové práci (10580) se uvádí jen u vztahu s prací zařazenou do kategorie 4.',
                );
            }
            if ($riskShiftCount < 0 || $riskShiftCount > 999) {
                $this->invalid(
                    'jmhz_ordinary_evidence_risk_shifts_invalid',
                    'Počet směn při rizikové práci (10580) musí být celé číslo 0 až 999.',
                );
            }
            $payload['risk_shift_count'] = $riskShiftCount;
        }
        return new JmhzOrdinaryEvidenceSnapshot($payload);
    }

    /**
     * Čeká se v měsíci počet směn 10580? Jen při odpracovaných hodinách
     * rizikové práce (IN42, kontrola 386: 10274 = 1 a 10273 > 0), tedy podle
     * {@see Xml2027\Jmhz2027DocumentContract::riskShiftCountRequired()}
     * s hodinami vztahu po odečtu náhradního volna, stejně jako resolver.
     * Bez zmrazeného pracovního souhrnu se počet žádá (fail-closed).
     *
     * @param array<string,mixed> $employment
     */
    private static function riskShiftCountExpected(array $employment): bool
    {
        $timeMonth = $employment['time_month'] ?? null;
        $summary = is_array($timeMonth) ? ($timeMonth['jmhz_work_summary'] ?? null) : null;
        $values = is_array($summary) ? ($summary['values'] ?? null) : null;
        if (!is_array($values)) {
            return true;
        }
        $worked = JmhzReportedWorkHours::reported($values, true)['worked_millihours'];

        return $worked === null || Xml2027\Jmhz2027DocumentContract::riskShiftCountRequired(
            Xml2027\Jmhz2027DocumentContract::RISK_SHIFT_CODE,
            $worked,
        );
    }

    /**
     * Automatický běžný profil je dovolený jen z údajů zmrazených ve mzdové
     * revizi. Zapnutá výjimka se nesmí tiše přepsat nulovým měsíčním stavem.
     *
     * @param array<string,mixed> $employment
     * @return array{source_term_id:int,source_term_row_version:int}|array{}
     */
    private function ordinaryProfileSource(array $employment, string $sourceKind): array
    {
        if (!in_array(
            $sourceKind,
            ['explicit_confirmation', 'derived_from_frozen_payroll_sources'],
            true,
        )) {
            $this->invalid(
                'jmhz_ordinary_evidence_confirmation_invalid',
                'Zdroj potvrzení právních skutečností není podporován.',
            );
        }
        if ($sourceKind === 'explicit_confirmation') {
            return [];
        }

        $profileValue = $employment['ordinary_evidence_profile'] ?? null;
        if (!is_array($profileValue) || array_is_list($profileValue)) {
            $this->invalid(
                'jmhz_ordinary_evidence_profile_missing',
                'Tato revize vznikla před doplněním podkladů JMHZ. Mzdu znovu přepočítejte a schvalte.',
            );
        }
        $profile = $profileValue;
        $termId = $this->positiveInt($profile['source_term_id'] ?? null, 'source_term_id');
        $termVersion = $this->positiveInt(
            $profile['source_term_row_version'] ?? null,
            'source_term_row_version',
        );
        foreach ([
            'orchard_discount_eligible',
            'specific_legal_fact_applies',
            'ozp_employment_support_applies',
            'deep_mining_work_applies',
        ] as $key) {
            if (!is_bool($profile[$key] ?? null)) {
                $this->invalid(
                    'jmhz_ordinary_evidence_profile_incomplete',
                    'Zmrazené nastavení neobvyklých situací JMHZ není úplné.',
                    ['field' => $key],
                );
            }
            // Podpora zaměstnávání OZP (IN28) výjimkou není: příznak je vstupem
            // interakce, viz self::ozpEmploymentSupport().
            if ($profile[$key] === true && $key !== 'ozp_employment_support_applies') {
                $this->invalid(
                    'jmhz_ordinary_evidence_monthly_exception_required',
                    'Pracovní vztah má evidovanou neobvyklou situaci, kterou automatická příprava zatím nepodporuje. Ověřte nastavení vztahu; pravdivý údaj neměňte jen kvůli přípravě hlášení.',
                    ['field' => $key],
                );
            }
        }

        return [
            'source_term_id' => $termId,
            'source_term_row_version' => $termVersion,
        ];
    }

    /**
     * Najde ve zmrazeném vstupu právě ten vztah, za který se evidence potvrzuje.
     *
     * @param array<string,mixed> $input
     * @return array{int,int,array<string,mixed>,array<string,mixed>}
     */
    private function targetEmployment(array $input, int $targetEmploymentId): array
    {
        $people = $input['people'] ?? null;
        if (!is_array($people) || !array_is_list($people) || $people === []) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Zmrazený vstup neobsahuje žádnou osobu.');
        }
        $found = null;
        foreach ($people as $candidate) {
            $person = $this->object($candidate, 'person');
            $employee = $this->object($person['employee'] ?? null, 'employee');
            $employeeId = $this->positiveInt($employee['id'] ?? null, 'employee.id');
            $employments = $person['employments'] ?? null;
            if (!is_array($employments) || !array_is_list($employments)) {
                $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Pracovní vztahy osoby nejsou seznam.');
            }
            foreach ($employments as $candidateEntry) {
                $entry = $this->object($candidateEntry, 'employment entry');
                $employment = $this->object($entry['employment'] ?? null, 'employment');
                $employmentId = $this->positiveInt($employment['id'] ?? null, 'employment.id');
                if ($employmentId !== $targetEmploymentId) {
                    continue;
                }
                if (($employment['employee_id'] ?? null) !== $employeeId) {
                    $this->invalid('jmhz_ordinary_evidence_scope_mismatch', 'Pracovní vztah nepatří zmrazené osobě.');
                }
                if ($found !== null) {
                    $this->invalid('jmhz_ordinary_evidence_scope_mismatch', 'Zmrazený vstup obsahuje pracovní vztah vícekrát.');
                }
                $found = [$employeeId, $employmentId, $person, $entry];
            }
        }
        if ($found === null) {
            $this->invalid(
                'jmhz_ordinary_evidence_scope_mismatch',
                'Zmrazená revize neobsahuje zvolený pracovní vztah.',
            );
        }
        return $found;
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $result
     */
    private function resolveWageDeductionsRecorded(
        array $person,
        array $result,
        int $employeeId,
    ): bool
    {
        $agreements = $person['deduction_agreements'] ?? null;
        if (!is_array($agreements) || !array_is_list($agreements)) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Zmrazená evidence dohod o srážkách není úplná.');
        }
        $enforcement = $this->object($person['enforcement_evidence'] ?? null, 'enforcement_evidence');
        $claims = $enforcement['claims'] ?? null;
        $insolvency = $this->object($enforcement['insolvency'] ?? null, 'insolvency');
        if (!is_array($claims) || !array_is_list($claims)
            || !is_string($insolvency['mode'] ?? null)
        ) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Zmrazená exekuční evidence není úplná.');
        }
        /*
         * 10116 je prostý boolean „vykazují se ze mzdy srážky". Odvozuje se ze
         * zmrazené revize, ne z potvrzení účetní — dohoda o srážkách, exekuční
         * pohledávka i insolvenční režim jsou fakt v datech. Částky JMHZ
         * nechce a čistá mzda 10344 se vykazuje před srážkami, takže tenhle
         * příznak je všechno, co se o srážkách hlásí.
         *
         * Ne každá evidovaná srážka je ale srážkou podle 10116: srážka za
         * závodní stravování a srážky ze zákona (§ 147 odst. 1 ZP) do něj
         * nepatří ({@see DeductionAgreementTerms::reportedAsWageDeduction()}).
         * Pro kontrolu „sraženo bez titulu" dál platí všechny evidované tituly.
         *
         * Pokyny MPSV k MH 1.4.13 (10116): „Uvede se ANO, pokud zaměstnavatel
         * PROVÁDÍ z příjmu zaměstnance srážky na základě nařízeného soudního
         * nebo správního výkonu rozhodnutí, konkursu nebo dohody o srážkách
         * z příjmu uzavřené podle občanského zákoníku, jinak NE." Rozhoduje
         * tedy, že se v měsíci sráží, ne že titul existuje:
         *
         * - Exekuční pohledávky se do zmrazené revize dostávají jen ze stavů,
         *   ve kterých se sráží (`withhold_and_hold`, `remit`, `deferred_hold`;
         *   výběr v PayrollEnforcementRepository). Doručená exekuce (`received`)
         *   ještě srážena není a odklad bez srážení (`deferred_no_withholding`,
         *   § 266 o. s. ř., § 54 exekučního řádu) výkon pozastavuje — obojí je
         *   NE, pokud se přesto něco nesrazilo. Aktivní pohledávka, u které
         *   v měsíci vyšla nulová srážka (příjem pod nezabavitelnou částkou),
         *   zůstává ANO: výkon rozhodnutí plátce provádí dál (srážku každý
         *   měsíc počítá, § 282 o. s. ř.), jen tentokrát bez výsledné částky.
         * - Samotné zahájené insolvenční řízení (`alert_only`) žádnou srážku
         *   nezakládá: sráží se jen dosavadní exekuce (deponují se, § 109
         *   odst. 1 písm. c) IZ) a dohody se neprovádějí vůbec (písm. d)).
         *   ANO je proto jen s aktivní exekucí nebo skutečnou srážkou.
         *   Schválené oddlužení (§ 406 odst. 3 písm. d) IZ) sráží pro
         *   insolvenčního správce vždy.
         *
         * Zápočtový list (§ 313 odst. 1 písm. d) ZP) hlásí naopak i doručené,
         * dosud nesrážené exekuce — tam jde o pokračující pohledávky, které
         * přebírá další plátce mzdy, ne o srážky provedené v měsíci
         * ({@see \MyInvoice\Service\Payroll\Document\EmploymentExitSnapshotBuilder}).
         */
        $mode = InsolvencyMode::tryFrom($insolvency['mode']);
        if ($mode === null) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Zmrazená exekuční evidence není úplná.');
        }
        $reportableAgreements = $mode->depositsEnforcementDeductions() ? [] : array_filter(
            $agreements,
            static fn (mixed $agreement): bool => is_array($agreement)
                && DeductionAgreementTerms::reportedAsWageDeduction(
                    (string) ($agreement['deduction_kind'] ?? ''),
                    (string) ($agreement['legal_basis'] ?? 'agreement'),
                ),
        );
        $withholdingClaims = array_filter(
            $claims,
            static fn (mixed $claim): bool => is_array($claim) && ($claim['active'] ?? null) === true,
        );
        $titled = $agreements !== []
            || $claims !== []
            || $mode !== InsolvencyMode::None;
        $deductionsRecorded = $reportableAgreements !== []
            || $withholdingClaims !== []
            || $mode->redirectsPaymentToAdministrator();
        // Kontroluje se osoba, za jejíž vztah se evidence potvrzuje. Ostatní
        // osoby revize mají vlastní evidenci a vlastní kontrolu, takže se
        // nevyžaduje, aby byla v revizi osoba jediná.
        $resultPeople = $result['people'] ?? null;
        if (!is_array($resultPeople) || !array_is_list($resultPeople) || $resultPeople === []) {
            $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Výsledek neobsahuje žádnou osobu.');
        }
        $resultPerson = null;
        foreach ($resultPeople as $candidate) {
            if (is_array($candidate) && !array_is_list($candidate)
                && ($candidate['employee_id'] ?? null) === $employeeId
            ) {
                if ($resultPerson !== null) {
                    $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Výsledek obsahuje osobu vícekrát.');
                }
                $resultPerson = $candidate;
            }
        }
        if (!is_array($resultPerson)) {
            $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Výsledek neobsahuje zmrazenou osobu.');
        }
        $statutory = $this->object($resultPerson['statutory'] ?? null, 'statutory');
        if (($statutory['status'] ?? null) !== 'calculated') {
            $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Zákonný výsledek osoby není vypočtený.');
        }
        $net = $this->object($statutory['net_pay'] ?? null, 'net_pay');
        $deductions = $net['deductions'] ?? null;
        if (!is_array($deductions) || !array_is_list($deductions)
            || !is_int($net['deducted_minor_units'] ?? null)
        ) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Zmrazený výsledek srážek není úplný.');
        }
        $resultHasDeductions = $deductions !== [] || $net['deducted_minor_units'] !== 0;
        if ($resultHasDeductions && !$titled) {
            // Sražené peníze bez evidovaného titulu jsou rozpor: buď chybí
            // dohoda/exekuce ve vstupu, nebo se srazilo něco, co nikdo
            // nenařídil. Ani jedno se nesmí vykázat jako „bez srážek".
            $this->invalid(
                'jmhz_ordinary_evidence_deduction_conflict',
                'Výsledek obsahuje srážku bez evidovaného titulu.',
                ['reason' => 'missing_title'],
            );
        }
        $resultEnforcement = $this->object($resultPerson['enforcement'] ?? null, 'result.enforcement');
        $enforcementInput = $this->object($resultEnforcement['input'] ?? null, 'result.enforcement.input');
        $enforcementResult = $this->object($resultEnforcement['result'] ?? null, 'result.enforcement.result');
        try {
            $calculationInput = GarnishmentInput::fromCanonicalArray($enforcementInput);
            $calculationEvidence = new EnforcementPersonMonthEvidence(
                $calculationInput->claims,
                $calculationInput->eligibleDependants,
                $calculationInput->dependantsEvidenceComplete,
                $calculationInput->eligibleSpouse,
                $calculationInput->spouseEvidenceComplete,
                $calculationInput->pensionEvidence,
                $calculationInput->hasMultiplePayers,
                $calculationInput->protectedAmountOverrideMinorUnits,
                $calculationInput->protectedAmountOverrideVerified,
                $calculationInput->claimRegisterEvidenceComplete,
                $calculationInput->insolvency,
                $calculationInput->spousePensionEvidence,
            );
        } catch (\Throwable) {
            $this->invalid(
                'jmhz_ordinary_evidence_source_invalid',
                'Výsledek neobsahuje platný vstup výpočtu srážek.',
            );
        }
        if (($enforcement['claim_register_evidence_complete'] ?? null) !== true) {
            try {
                $evidenceScope = EnforcementEvidenceScope::fromCanonicalArray(
                    $this->object($enforcementResult['evidence_source'] ?? null, 'result.enforcement.result.evidence_source'),
                );
            } catch (\Throwable) {
                $this->invalid(
                    'jmhz_ordinary_evidence_source_invalid',
                    'Výsledek neobsahuje platný rozsah kontroly exekuční evidence.',
                );
            }
            if ($evidenceScope->claimRegister !== EnforcementEvidenceSource::NotApplicable) {
                $this->invalid(
                    'jmhz_ordinary_evidence_deduction_conflict',
                    'Kontrola evidence pohledávek není doložená ani označená jako nepoužitelná.',
                    ['reason' => 'register_unverified'],
                );
            }
        }
        /*
         * Výpočet srážek musí být DOKONČENÝ — `manual_review` nebo otevřený
         * nález znamená, že sražená částka není doložená, a takový měsíc se
         * hlásit nesmí (viz souběh exekuce a oddlužení). Samotná existence
         * alokací ale chybou není; ta se do 10116 promítne jako `true`.
         */
        if (($enforcementResult['status'] ?? null) !== 'supported'
            || ($enforcementResult['issues'] ?? null) !== []
            || CanonicalJson::encode($calculationEvidence->toCanonicalArray()) !== CanonicalJson::encode($enforcement)
        ) {
            $this->invalid('jmhz_ordinary_evidence_deduction_conflict', 'Výpočet srážek není dokončený a doložený.', ['reason' => 'calculation_incomplete']);
        }
        $withheld = $enforcementResult['total_withheld_minor_units'] ?? null;
        if (!is_int($withheld)
            || !is_array($enforcementResult['allocations'] ?? null)
            || !is_bool($enforcementResult['insolvency_applied'] ?? null)
        ) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Výsledek srážek není úplný.');
        }

        // `insolvency_applied` je u `alert_only` true i bez jediné sražené
        // koruny (jen říká, že se případné exekuční srážky deponují), proto
        // sám o sobě ANO nedává; srážku doloží alokace nebo sražená částka.
        return $deductionsRecorded
            || $withheld !== 0
            || $enforcementResult['allocations'] !== [];
    }

    /**
     * Uplatňuje se u vztahu podpora zaměstnávání OZP (interakce IN28)?
     *
     * Zdrojem je příznak podmínek vztahu `jmhz_ozp_employment_support_applies`
     * zmrazený v revizi. Automatické doložení ho převezme; výslovné potvrzení
     * mu nesmí odporovat, jinak by hlášení tvrdilo něco jiného než nastavení
     * vztahu, ze kterého se mzda počítala. Revize bez zmrazeného profilu
     * (starší) nese jen výslovné potvrzení.
     *
     * @param array<string,mixed> $employment
     */
    private function ozpEmploymentSupport(array $employment, string $sourceKind, bool $claimed): bool
    {
        $profile = $employment['ordinary_evidence_profile'] ?? null;
        $flag = is_array($profile) && is_bool($profile['ozp_employment_support_applies'] ?? null)
            ? $profile['ozp_employment_support_applies']
            : null;
        if ($sourceKind === 'derived_from_frozen_payroll_sources') {
            return $flag === true;
        }
        if ($flag !== null && $flag !== $claimed) {
            $this->invalid(
                'jmhz_ordinary_evidence_ozp_profile_mismatch',
                $flag
                    ? 'Pracovní vztah má v podmínkách zapnutou podporu zaměstnávání OZP, potvrzení ji ale neuvádí.'
                    : 'Potvrzení uvádí podporu zaměstnávání OZP, podmínky pracovního vztahu ji ale nemají.',
            );
        }

        return $claimed;
    }

    /**
     * Výdělek osob OZP (10347 až 10349) a srážky ve prospěch zaměstnavatele
     * (10350 až 10353) za osobu a měsíc.
     *
     * Pokyny MPSV k MH 1.4.14, kap. 2.4.14 a 2.4.15: 10347 až 10349 jsou
     * „povinný údaj v rámci IN28", 10350 až 10353 se uvádějí v rámci IN24
     * (10349 = ANO). XSD 1.4.3.6 (`vydelekOsobOZPType`) má datum úhrady 10347
     * povinné, takže bez něj se blok sestavit nedá; datum se nikdy nedomýšlí.
     * Dotaz 12 k webináři MPSV 11. 5. 2026 (FAQ v2) navíc říká, že hlášení se
     * datem úhrady se posílá až po úhradě mzdy včetně pojistného (kontrola
     * 20201: datum úhrady <= datum vyplnění) — proto datum nesmí být po dni
     * potvrzení a podání vzniká až po něm.
     *
     * Částky srážek se berou ze zmrazené revize (`net_pay.deductions`, sražená
     * částka za dohodu), ne z nového výpočtu; zařazení do 10350 až 10353 je
     * uložené u dohody (`jmhz_target`). Nepatří sem výkon rozhodnutí (exekuce
     * nejsou dohodami), srážky ze zákona podle § 147 odst. 1 ZP (datový slovník
     * 1.4.1.6 vede 10353 jako srážky „podle § 146 písm. b) ZP", tedy z dohody)
     * ani dohody ve prospěch třetí osoby. Dohoda bez rozhodnutí o věřiteli,
     * ze které se v měsíci srazilo, hlášení zastaví.
     *
     * @param array<string,mixed>|null $input
     * @param array<string,mixed> $term
     * @param array<string,mixed> $person
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function ozpWage(
        ?array $input,
        array $term,
        array $person,
        array $result,
        int $employeeId,
        string $periodStart,
        string $confirmedAt,
    ): array {
        // Pokyny k 10348 a 10349: jen u zaměstnavatele uznaného na chráněném
        // trhu práce podle § 78 zákona o zaměstnanosti (REGZEL 10211).
        if (($term['employer_protected_labor_market'] ?? null) !== true) {
            $this->invalid(
                'jmhz_ordinary_evidence_ozp_employer_not_protected',
                'Podpora zaměstnávání OZP se hlásí jen u zaměstnavatele uznaného na chráněném trhu práce;'
                    . ' v registraci zaměstnavatele to uvedeno není.',
            );
        }
        if ($input === null) {
            $this->invalid(
                'jmhz_ordinary_evidence_ozp_wage_required',
                'Doplňte datum úhrady mzdy včetně pojistného (10347) a zda byla mzda vyplacena v hotovosti (10348).',
            );
        }
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        // Od verze 2027 se 10347 dělí na datum úhrady mzdy 10589 a dny
        // provedení odvodů sociálního 10570 a zdravotního pojištění 10571.
        // Obě data odvodů se zadávají spolu; evidence bez nich zůstává
        // bajtově stejná jako dřív.
        $withContributionDates = $keys === [
            'cash_paid',
            'health_insurance_paid_on',
            'payment_date',
            'social_insurance_paid_on',
        ];
        if (($keys !== ['cash_paid', 'payment_date'] && !$withContributionDates)
            || !is_bool($input['cash_paid'])
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_ozp_wage_invalid',
                'Výdělek OZP musí obsahovat datum úhrady, výslovné Ano nebo Ne u výplaty v hotovosti'
                    . ' a případně obě data odvodů pojistného.',
            );
        }
        if (!$withContributionDates
            && JmhzSpecVersion::forPeriodStart($periodStart) === JmhzSpecVersion::V2027
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_ozp_wage_required',
                'Od ledna 2027 doplňte k datu úhrady mzdy (10589) i dny provedení odvodu sociálního'
                    . ' (10570) a zdravotního pojištění (10571).',
            );
        }
        $contributionDates = [];
        if ($withContributionDates) {
            $confirmedDay = (new \DateTimeImmutable($confirmedAt))
                ->setTimezone(new \DateTimeZone('Europe/Prague'))
                ->format('Y-m-d');
            foreach (['social_insurance_paid_on', 'health_insurance_paid_on'] as $key) {
                $value = $input[$key];
                $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if ($parsed === false || $parsed->format('Y-m-d') !== $value
                    || $value < $periodStart || $value > $confirmedDay
                ) {
                    $this->invalid(
                        'jmhz_ordinary_evidence_ozp_payment_date_invalid',
                        'Den provedení odvodu pojistného (10570, 10571) musí být platné datum mezi začátkem'
                            . ' vykazovaného měsíce a dneškem; hlášení se podává až po odvodu.',
                        ['payment_date' => is_string($value) ? $value : null, 'period_start' => $periodStart],
                    );
                }
                $contributionDates[$key] = $value;
            }
        }
        $paymentDate = $input['payment_date'];
        $date = is_string($paymentDate)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate)
            : false;
        if ($date === false || $date->format('Y-m-d') !== $paymentDate) {
            $this->invalid('jmhz_ordinary_evidence_ozp_wage_invalid', 'Datum úhrady mzdy není platné datum.');
        }
        $confirmedOn = (new \DateTimeImmutable($confirmedAt))
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d');
        if ($paymentDate < $periodStart || $paymentDate > $confirmedOn) {
            $this->invalid(
                'jmhz_ordinary_evidence_ozp_payment_date_invalid',
                'Datum úhrady mzdy včetně pojistného (10347) musí ležet mezi začátkem vykazovaného měsíce'
                    . ' a dneškem; hlášení se podává až po úhradě.',
                ['payment_date' => $paymentDate, 'period_start' => $periodStart],
            );
        }

        $agreements = [];
        foreach ($person['deduction_agreements'] ?? [] as $agreement) {
            if (is_array($agreement) && is_int($agreement['id'] ?? null)) {
                $agreements[$agreement['id']] = $agreement;
            }
        }
        $deductions = [];
        foreach ($result['people'] ?? [] as $candidate) {
            if (is_array($candidate) && ($candidate['employee_id'] ?? null) === $employeeId) {
                $deductions = $candidate['statutory']['net_pay']['deductions'] ?? [];
            }
        }
        $sums = [];
        $sources = [];
        $unclassified = [];
        foreach (is_array($deductions) ? $deductions : [] as $deduction) {
            $reference = is_array($deduction) ? ($deduction['deduction_reference'] ?? null) : null;
            $applied = is_array($deduction) ? ($deduction['applied_minor_units'] ?? null) : null;
            if (!is_string($reference)
                || preg_match('/^agreement:([1-9][0-9]*)$/D', $reference, $match) !== 1
                || !is_int($applied) || $applied < 0
            ) {
                $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Zmrazený výsledek srážek není úplný.');
            }
            if ($applied === 0) {
                continue;
            }
            $agreementId = (int) $match[1];
            $agreement = $agreements[$agreementId] ?? null;
            if ($agreement === null) {
                $this->invalid(
                    'jmhz_ordinary_evidence_deduction_conflict',
                    'Výsledek obsahuje srážku bez evidovaného titulu.',
                    ['reason' => 'missing_title'],
                );
            }
            if (DeductionAgreementTerms::isStatutory((string) ($agreement['legal_basis'] ?? 'agreement'))) {
                continue;
            }
            if (!array_key_exists('creditor_is_employer', $agreement)) {
                $unclassified[] = $agreementId;
                continue;
            }
            if ($agreement['creditor_is_employer'] !== true) {
                continue;
            }
            $target = $agreement['jmhz_target'] ?? null;
            if (!is_string($target) || !in_array($target, DeductionAgreementTerms::JMHZ_TARGETS, true)) {
                $unclassified[] = $agreementId;
                continue;
            }
            $sums[$target] = ($sums[$target] ?? 0) + $applied;
            $sources[] = [
                'agreement_id' => $agreementId,
                'jmhz_target' => $target,
                'applied_minor_units' => $applied,
            ];
        }
        if ($unclassified !== []) {
            sort($unclassified, SORT_NUMERIC);
            $this->invalid(
                'jmhz_ordinary_evidence_ozp_deduction_unclassified',
                'U dohody o srážce, ze které se v měsíci srazilo, chybí rozhodnutí, zda je věřitelem'
                    . ' zaměstnavatel a jak se srážka vykáže v JMHZ (10350 až 10353).',
                ['agreement_ids' => array_values(array_unique($unclassified))],
            );
        }
        ksort($sums, SORT_STRING);
        usort(
            $sources,
            static fn (array $left, array $right): int => $left['agreement_id'] <=> $right['agreement_id'],
        );

        return [
            'payment_date' => $paymentDate,
            ...$contributionDates,
            'cash_paid' => $input['cash_paid'],
            'employer_deductions_made' => $sums !== [],
            'employer_deductions_minor' => $sums,
            'employer_deduction_sources' => $sources,
        ];
    }

    private function scenarioSelector(): JmhzScenarioSelectorResolver
    {
        return $this->scenarioSelector ??= JmhzScenarioSelectorResolver::load();
    }

    private function scenarioRequirements(): JmhzScenarioRequirementSourceCatalog
    {
        return $this->scenarioRequirements ??= JmhzScenarioRequirementSourceCatalog::load();
    }

    /** @return array<string,mixed> */
    private function canonicalSnapshot(mixed $json, mixed $hash, string $field): array
    {
        $cached = $this->canonicalSnapshots[$field] ?? null;
        if ($cached !== null && $cached['hash'] === $hash && $cached['json'] === $json) {
            return $cached['decoded'];
        }
        if (!is_string($json) || !is_string($hash)
            || !hash_equals($hash, hash('sha256', $json))
        ) {
            $this->invalid('jmhz_ordinary_evidence_source_hash_mismatch', "Otisk {$field} snapshotu nesouhlasí.");
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded) || CanonicalJson::encode($decoded) !== $json) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Vstupní snapshot není kanonický objekt.');
        }
        $this->canonicalSnapshots[$field] = ['json' => $json, 'hash' => $hash, 'decoded' => $decoded];
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} musí být objekt.");
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} musí být kladné celé číslo.");
        }
        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} není platné datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} není platné datum.");
        }
        return $value;
    }

    private function invalid(string $code, string $message, array $context = []): never
    {
        throw new JmhzOrdinaryEvidenceException($code, $message, $context);
    }
}
