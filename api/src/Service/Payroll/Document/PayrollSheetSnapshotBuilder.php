<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualDocumentRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Component\PayrollExemptIncomeSplit;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

final class PayrollSheetSnapshotBuilder
{
    public const SCHEMA_VERSION = 'payroll-sheet-document.v4';
    public const PURPOSE = 'payroll_sheet';

    /**
     * Mapování v2 doplnilo § 38j odst. 2 písm. e) (den nástupu), písm. f) bod 2
     * (částky osvobozené od daně) a chybějící polovinu bodu 3 (základ daně
     * podle zvláštní sazby).
     *
     * Mapování v3 doplňuje zbytek bodu 6 (měsíční daňové zvýhodnění jako NÁROK
     * vedle uplatněné slevy podle § 35c) a písm. h) (údaje o výpočtu daně
     * a provedeném ročním zúčtování), které se dosud zapisovalo natvrdo jako
     * „neprovedeno".
     *
     * Mapování v4 opravuje zdroj UPLATNĚNÉ slevy podle § 35c. Bralo se
     * `advance_tax.child_credit_minor_units`, jenže to není uplatněná část —
     * měsíční záloha si tam odkládá CELÝ nárok, se kterým do výpočtu vstoupila
     * ({@see \MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxCalculator}).
     * Uplatněná část je `income_tax.applied_child_credit_minor_units`, tedy ta,
     * která se vešla do daně (§ 35c odst. 2). Rozdíl není kosmetický: jakmile
     * nárok daň převýšil a vznikl bonus, součet „slevy" a bonusu nárok přesáhl
     * a mzdový list se nedal vydat vůbec — spadl na kontrole měsíčního řádku.
     *
     * Mapování v5 opravuje týmž způsobem slevu podle § 35ba. Bralo se
     * `advance_tax.non_refundable_credits_minor_units`, což je opět jen NÁROK,
     * se kterým záloha počítala. Bod 5 ale žádá „měsíční slevu na dani podle
     * § 35ba", a to je legální zkratka z § 35d odst. 2, kterou § 35d odst. 3
     * věta první omezuje výší zálohy — poskytnutou slevu, ne nárok. Uplatněnou
     * částku nese `income_tax.applied_non_refundable_credits_minor_units`.
     * U mzdy s daní nižší než měsíční sleva doklad dosud vykazoval vyšší slevu,
     * než jaká se skutečně poskytla, a druhá polovina bodu 5 („záloha snížená
     * o měsíční slevu") mu z toho vycházela záporná.
     *
     * Verze je součástí zdrojového manifestu, takže se pro tytéž zdrojové revize
     * NENAJDE dřívější revize a doklad se vydá jako DALŠÍ revize v řetězu.
     * Existující revize ani její archivované PDF se tím nemění — což je jediná
     * přípustná cesta, protože roční revize jsou append-only a kotvené otiskem.
     */
    public const MAPPING_VERSION = 'payroll-sheet-mapping.v5';

    /**
     * Snapshoty vydané pod starším mapováním zůstávají čitelné. Nedopočítávají
     * se — zdrojové vstupní revize v nich nejsou, takže osvobozené částky ani
     * nárok na zvýhodnění by šlo jen hádat. Hydratují se proto jako neevidovaný
     * údaj a doklad ho pojmenuje slovem, ne nulou.
     */
    private const SCHEMA_VERSION_V1 = 'payroll-sheet-document.v1';
    private const SCHEMA_VERSION_V2 = 'payroll-sheet-document.v2';
    private const SCHEMA_VERSION_V3 = 'payroll-sheet-document.v3';

    private const SUPPORTED_SCHEMA_VERSIONS = [
        self::SCHEMA_VERSION_V1,
        self::SCHEMA_VERSION_V2,
        self::SCHEMA_VERSION_V3,
        self::SCHEMA_VERSION,
    ];

    private const INPUT_SCHEMA_VERSION = 'payroll-run-input.v2';

    /**
     * Doména klíčovaného otisku podle účelu cizí roční revize. Mzdový list si
     * cizí revizi jen čte, takže ji musí ověřovat pod doménou jejího vydavatele.
     */
    private const ANNUAL_FINGERPRINT_DOMAINS = [
        AnnualSettlementSnapshotBuilder::PURPOSE =>
            AnnualSettlementSnapshotBuilder::SNAPSHOT_FINGERPRINT_DOMAIN,
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAnnualDocumentRepository $annualRevisions,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly SecretEncryption $encryption,
    ) {}

    /**
     * @return array{revision:array<string,mixed>,document:PayrollSheetDocumentData}
     */
    public function build(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        ?int $actorUserId,
    ): array {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Roční mzdový snapshot vyžaduje aktivní transakci.');
        }
        if ($supplierId <= 0 || $employeeId <= 0 || $taxYear < 2000 || $taxYear > 2199) {
            throw new \InvalidArgumentException('Identita ročního mzdového listu není platná.');
        }

        $sources = $this->annualRevisions->lockApprovedYearSources(
            $supplierId,
            $employeeId,
            $taxYear,
        );
        if ($sources === []) {
            throw new \DomainException(
                'Mzdový list nelze vytvořit bez schváleného výsledku zaměstnance v daném roce.',
            );
        }
        $profile = $this->profileSnapshot($supplierId, $employeeId, $taxYear);
        $employer = $this->employerSnapshot($supplierId);
        [$months, $manifestSources, $employments] = $this->months($sources, $employeeId);

        $profileHash = $this->sensitiveData->keyedFingerprint(
            CanonicalJson::encode($profile),
            'annual-payroll-profile-v1',
            $supplierId,
        );
        $employerHash = $this->sensitiveData->keyedFingerprint(
            CanonicalJson::encode($employer),
            'annual-payroll-employer-v1',
            $supplierId,
        );
        [$settlement, $settlementEvidence] = $this->annualSettlement(
            $supplierId,
            $employeeId,
            $taxYear,
        );
        // Roční zúčtování se provádí až po posledním měsíci, takže mzdový list
        // vydaný dřív ho nést nemůže. Do manifestu proto patří — jinak by se
        // po jeho provedení vrátila PŮVODNÍ revize bez písm. h), nebo by
        // stejný manifest ukazoval na jiný snapshot a build by spadl.
        $manifest = [
            'schema_version' => 'payroll-annual-source-manifest.v1',
            'document_schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => self::PURPOSE,
            'tax_year' => $taxYear,
            'employee_id' => $employeeId,
            'profile_snapshot_hash' => $profileHash,
            'employer_snapshot_hash' => $employerHash,
            'annual_settlement_hash' => hash('sha256', CanonicalJson::encode([
                'settlement' => $settlement,
                'evidence' => $settlementEvidence,
            ])),
            'sources' => $manifestSources,
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $manifestHash = hash('sha256', $manifestJson);
        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'tax_year' => $taxYear,
            'employer' => $employer,
            'employee' => $profile,
            'employments' => $employments,
            'months' => array_map(
                static fn (PayrollSheetMonth $month): array => $month->toTemplateData(),
                $months,
            ),
            'annual_settlement_status' => $settlement === null
                ? PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_PERFORMED
                : PayrollSheetDocumentData::ANNUAL_SETTLEMENT_APPROVED,
            'annual_settlement' => $settlement,
            'annual_settlement_evidence' => $settlementEvidence,
        ];
        $snapshotJson = CanonicalJson::encode($snapshot);
        $snapshotHash = $this->snapshotFingerprint($snapshotJson, $supplierId);

        $existing = $this->annualRevisions->findBySourceManifest(
            $supplierId,
            $employeeId,
            $taxYear,
            self::PURPOSE,
            $manifestHash,
        );
        if ($existing !== null) {
            if (!hash_equals((string) $existing['snapshot_hash'], $snapshotHash)) {
                throw new \DomainException(
                    'Stejný roční manifest odkazuje na jiný kanonický mzdový snapshot.',
                );
            }
            return [
                'revision' => $existing,
                'document' => $this->hydrate(
                    $this->decryptSnapshot($existing),
                    (string) $existing['snapshot_hash'],
                ),
            ];
        }
        $previous = $this->annualRevisions->latest(
            $supplierId,
            $employeeId,
            $taxYear,
            self::PURPOSE,
        );
        $ciphertext = $this->encryption->encryptFor(
            $snapshotJson,
            $this->encryptionContext(
                $supplierId,
                $employeeId,
                $taxYear,
                $manifestHash,
            ),
        );
        $revision = $this->annualRevisions->insertApproved([
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'tax_year' => $taxYear,
            'purpose' => self::PURPOSE,
            'revision_no' => $previous === null ? 1 : (int) $previous['revision_no'] + 1,
            'previous_revision_id' => $previous['id'] ?? null,
            'snapshot_ciphertext' => $ciphertext,
            'snapshot_hash' => $snapshotHash,
            'source_manifest_json' => $manifestJson,
            'source_manifest_hash' => $manifestHash,
            'approved_by' => $actorUserId,
        ], $sources);

        return [
            'revision' => $revision,
            'document' => $this->hydrate($snapshot, $snapshotHash),
        ];
    }

    /**
     * @param list<array<string,mixed>> $sources
     * @return array{
     *     0:list<PayrollSheetMonth>,
     *     1:list<array<string,mixed>>,
     *     2:list<array<string,mixed>>
     * }
     */
    private function months(array $sources, int $employeeId): array
    {
        $months = [];
        $manifest = [];
        $employments = [];
        foreach ($sources as $source) {
            $inputJson = $this->text($source, 'input_snapshot_json');
            $resultJson = $this->text($source, 'result_snapshot_json');
            $personJson = $this->text($source, 'person_result_json');
            $inputHash = $this->hash($source, 'input_snapshot_hash');
            $resultHash = $this->hash($source, 'result_snapshot_hash');
            $personHash = $this->hash($source, 'person_result_hash');
            $this->assertJsonHash($inputJson, $inputHash, 'vstupní revize');
            $this->assertJsonHash($resultJson, $resultHash, 'výsledné revize');
            $this->assertJsonHash($personJson, $personHash, 'výsledku osoby');

            $root = $this->decodeObject($resultJson, 'Výsledek schválené revize');
            $storedPerson = $this->decodeObject($personJson, 'Výsledek zaměstnance');
            $rootPerson = null;
            foreach ($this->list($root['people'] ?? null, 'result.people') as $candidate) {
                $candidate = $this->object($candidate, 'result.people[]');
                if ($this->positiveInt($candidate, 'employee_id') === $employeeId) {
                    if ($rootPerson !== null) {
                        throw new \DomainException('Schválená revize obsahuje zaměstnance vícekrát.');
                    }
                    $rootPerson = $candidate;
                }
            }
            if ($rootPerson === null
                || !hash_equals($personHash, hash('sha256', CanonicalJson::encode($rootPerson)))
                || !hash_equals(
                    hash('sha256', CanonicalJson::encode($storedPerson)),
                    $personHash,
                )
            ) {
                throw new \DomainException('Výsledek zaměstnance nesouhlasí se schválenou revizí.');
            }
            $periodStart = $this->text($source, 'period_start');
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $periodStart) !== 1) {
                throw new \DomainException('Zdroj mzdového listu má neplatné období.');
            }
            $monthNumber = (int) substr($periodStart, 5, 2);
            $frozenInputs = $this->frozenPersonInputs(
                $this->decodeObject($inputJson, 'Vstup schválené revize'),
                $employeeId,
                $employments,
            );
            $amounts = $this->personAmounts($storedPerson);
            $amounts['tax_exempt_income_minor_units'] = $this->taxExemptIncome(
                $storedPerson,
                $frozenInputs,
            );
            if (!isset($months[$monthNumber])) {
                $months[$monthNumber] = [
                    'source_revision_count' => 0,
                    ...array_fill_keys(array_keys($amounts), 0),
                ];
            }
            $months[$monthNumber]['source_revision_count']++;
            foreach ($amounts as $key => $amount) {
                $months[$monthNumber][$key] = $this->add(
                    $months[$monthNumber][$key],
                    $amount,
                );
            }
            $manifest[] = [
                'period_start' => $periodStart,
                'run_id' => $this->positiveInt($source, 'run_id'),
                'revision_id' => $this->positiveInt($source, 'revision_id'),
                'input_snapshot_hash' => $inputHash,
                'result_snapshot_hash' => $resultHash,
                'person_result_hash' => $personHash,
            ];
        }
        ksort($months, SORT_NUMERIC);
        $result = [];
        foreach ($months as $month => $amounts) {
            $result[] = new PayrollSheetMonth(
                month: $month,
                sourceRevisionCount: $amounts['source_revision_count'],
                grossMinorUnits: $amounts['gross_minor_units'],
                cashIncomeMinorUnits: $amounts['cash_income_minor_units'],
                nonCashIncomeMinorUnits: $amounts['non_cash_income_minor_units'],
                socialAssessmentBaseMinorUnits: $amounts['social_assessment_base_minor_units'],
                employeeSocialMinorUnits: $amounts['employee_social_minor_units'],
                employerSocialMinorUnits: $amounts['employer_social_minor_units'],
                healthAssessmentBaseMinorUnits: $amounts['health_assessment_base_minor_units'],
                employeeHealthMinorUnits: $amounts['employee_health_minor_units'],
                employerHealthMinorUnits: $amounts['employer_health_minor_units'],
                healthMinimumTopUpMinorUnits: $amounts['health_minimum_top_up_minor_units'],
                advanceTaxBaseMinorUnits: $amounts['advance_tax_base_minor_units'],
                advanceTaxBeforeCreditsMinorUnits: $amounts['advance_tax_before_credits_minor_units'],
                nonRefundableCreditsMinorUnits: $amounts['non_refundable_credits_minor_units'],
                childCreditMinorUnits: $amounts['child_credit_minor_units'],
                advanceTaxMinorUnits: $amounts['advance_tax_minor_units'],
                taxBonusMinorUnits: $amounts['tax_bonus_minor_units'],
                withholdingTaxMinorUnits: $amounts['withholding_tax_minor_units'],
                otherDeductionsMinorUnits: $amounts['other_deductions_minor_units'],
                netPayableMinorUnits: $amounts['net_payable_minor_units'],
                annualSettlementMinorUnits:
                    $amounts['annual_settlement_minor_units'],
                withholdingTaxBaseMinorUnits:
                    $amounts['withholding_tax_base_minor_units'],
                taxExemptIncomeMinorUnits:
                    $amounts['tax_exempt_income_minor_units'],
                taxDetailStatus: PayrollSheetMonth::TAX_DETAIL_RECORDED,
                childEntitlementMinorUnits:
                    $amounts['child_entitlement_minor_units'],
                childDetailStatus: PayrollSheetMonth::CHILD_DETAIL_RECORDED,
                creditDetailStatus: PayrollSheetMonth::CREDIT_DETAIL_APPLIED,
            );
        }
        usort(
            $employments,
            static fn (array $left, array $right): int =>
                [$left['start_date'], $left['code']]
                <=> [$right['start_date'], $right['code']],
        );
        return [$result, $manifest, array_values($employments)];
    }

    /**
     * Zmrazené mzdové vstupy zaměstnance z JEDNÉ zdrojové revize, klíčované
     * `employment_id` a `input_id`.
     *
     * Vstupní snapshot je jediné místo, kde je zapsané daňové zacházení složky
     * a rozpad koše osvobození (§ 6 odst. 9 ZDP) tak, jak platily v okamžiku
     * schválení. Výsledek běhu nese jen částky, takže z něj osvobozenou část
     * poznat nelze.
     *
     * Průběžně plní i `$employments` — § 38j odst. 2 písm. e) chce den nástupu
     * a ten je ve zmrazeném vztahu, ne v dnešní evidenci.
     *
     * @param array<string,mixed> $inputRoot
     * @param array<int,array<string,mixed>> $employments
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function frozenPersonInputs(
        array $inputRoot,
        int $employeeId,
        array &$employments,
    ): array {
        if (($inputRoot['schema_version'] ?? null) !== self::INPUT_SCHEMA_VERSION) {
            throw new \DomainException(
                'Mzdový list vyžaduje zdrojový vstup ' . self::INPUT_SCHEMA_VERSION . '.',
            );
        }
        $person = null;
        foreach ($this->list($inputRoot['people'] ?? null, 'input.people') as $candidate) {
            $candidate = $this->object($candidate, 'input.people[]');
            $employee = $this->object($candidate['employee'] ?? null, 'input.people[].employee');
            if ($this->positiveInt($employee, 'id') === $employeeId) {
                if ($person !== null) {
                    throw new \DomainException('Zmrazený vstup obsahuje zaměstnance vícekrát.');
                }
                $person = $candidate;
            }
        }
        if ($person === null) {
            throw new \DomainException('Zmrazený vstup revize zaměstnance neobsahuje.');
        }
        $result = [];
        foreach ($this->list($person['employments'] ?? null, 'input.employments') as $row) {
            $row = $this->object($row, 'input.employments[]');
            $employment = $this->object($row['employment'] ?? null, 'input.employment');
            $employmentId = $this->positiveInt($employment, 'id');
            $employments[$employmentId] ??= [
                'code' => $this->text($employment, 'code'),
                'relation_type' => $this->text($employment, 'relation_type'),
                'start_date' => $this->text($employment, 'start_date'),
                'actual_start_date' => $this->nullableText($employment, 'actual_start_date'),
                'end_date' => $this->nullableText($employment, 'end_date'),
            ];
            $inputs = [];
            foreach ($this->list($row['inputs'] ?? null, 'input.inputs') as $input) {
                $input = $this->object($input, 'input.inputs[]');
                $inputId = $this->positiveInt($input, 'id');
                if (isset($inputs[$inputId])) {
                    throw new \DomainException('Zmrazené mzdové vstupy nejsou jednoznačné.');
                }
                $inputs[$inputId] = $input;
            }
            $result[$employmentId] = $inputs;
        }
        return $result;
    }

    /**
     * § 38j odst. 2 písm. f) bod 2 — částky osvobozené od daně z úhrnu
     * zúčtovaných mezd.
     *
     * Sčítá se přes TYTÉŽ vstupy, které tvoří úhrn v bodě 1, takže osvobozená
     * část je jeho podmnožina a nemůže se s ním rozejít. Osvobození se pozná
     * z daňového zacházení složky, ne z jejího druhu.
     *
     * Nezdaněné ale není totéž co osvobozené: cestovní náhrada do limitu podle
     * § 6 odst. 7 písm. a) ZDP PŘEDMĚTEM DANĚ VŮBEC NENÍ a mezi „částky
     * osvobozené od daně" tedy nepatří. Rozliší je `exemption_basis`
     * ({@see \MyInvoice\Service\Payroll\Component\PayrollExemptionBasis}).
     *
     * U složky v koši se bere ZMRAZENÝ rozpad (`benefit_exempt_minor`), ne
     * dopočet: koš čerpají všechny složky téhož bodu v pořadí schválení a
     * pozdější přepočet by dal jiné dělení než výplatní páska.
     *
     * @param array<string,mixed> $person
     * @param array<int,array<int,array<string,mixed>>> $frozenInputs
     */
    private function taxExemptIncome(array $person, array $frozenInputs): int
    {
        $total = 0;
        foreach ($this->list($person['employments'] ?? null, 'result.employments') as $row) {
            $row = $this->object($row, 'result.employments[]');
            $employmentId = $this->positiveInt($row, 'employment_id');
            $inputs = $frozenInputs[$employmentId] ?? null;
            if ($inputs === null) {
                throw new \DomainException(
                    "Výsledek odkazuje na nezmrazený vztah {$employmentId}.",
                );
            }
            foreach ($this->list($row['inputs'] ?? null, 'result.inputs') as $inputResult) {
                $inputResult = $this->object($inputResult, 'result.inputs[]');
                $inputId = $this->positiveInt($inputResult, 'input_id');
                $input = $inputs[$inputId] ?? null;
                if ($input === null) {
                    throw new \DomainException(
                        "Výsledek odkazuje na nezmrazený vstup {$inputId}.",
                    );
                }
                $totals = $this->object($inputResult['totals'] ?? null, 'result.inputs[].totals');
                // Oprava minulého období vstupuje jako ZÁPORNÁ složka. Znaménko
                // se tu nesmí useknout — záporný úhrn za měsíc má spadnout až na
                // kontrole měsíčního řádku, ne se tiše proměnit v nulu.
                $sourceAmount = $this->integer($totals, 'source_amount_minor');
                if ($sourceAmount !== $this->integer($input, 'amount_minor')) {
                    throw new \DomainException(
                        "Zmrazená částka vstupu {$inputId} nesouhlasí s výsledkem běhu.",
                    );
                }
                $total = $this->add($total, PayrollExemptIncomeSplit::fromFrozenInput(
                    $input,
                    $sourceAmount,
                    $inputId,
                )->reportedExemptMinorUnits());
            }
        }
        return $total;
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,int>
     */
    private function personAmounts(array $person): array
    {
        $payslip = $this->object($person['payslip_document'] ?? null, 'payslip_document');
        $statutory = $this->object($person['statutory'] ?? null, 'statutory');
        if (($statutory['status'] ?? null) !== 'calculated') {
            throw new \DomainException('Mzdový list vyžaduje uzavřený zákonný výpočet.');
        }
        $social = $this->object($statutory['social_insurance'] ?? null, 'social_insurance');
        $health = $this->object($statutory['health_insurance'] ?? null, 'health_insurance');
        $tax = $this->object($statutory['income_tax'] ?? null, 'income_tax');
        $net = $this->object($statutory['net_pay'] ?? null, 'net_pay');
        $advance = ($tax['advance_tax'] ?? null) === null
            ? []
            : $this->object($tax['advance_tax'], 'advance_tax');
        $enforcement = $this->object($person['enforcement'] ?? null, 'enforcement');
        $enforcementResult = $this->object($enforcement['result'] ?? null, 'enforcement.result');
        if (($enforcementResult['status'] ?? null) !== 'supported') {
            throw new \DomainException('Mzdový list vyžaduje uzavřený výsledek srážek.');
        }
        $employeeHealthTotal = $this->nonNegativeInt($health, 'employee_contribution_minor_units');
        $healthTopUp = $this->nonNegativeInt($health, 'employee_minimum_top_up_minor_units');

        return [
            'gross_minor_units' => $this->nonNegativeInt($payslip, 'gross_minor_units'),
            'cash_income_minor_units' => $this->nonNegativeInt($net, 'cash_income_minor_units'),
            'non_cash_income_minor_units' => $this->nonNegativeInt($net, 'non_cash_income_minor_units'),
            'social_assessment_base_minor_units' =>
                $this->nonNegativeInt($social, 'capped_assessment_base_minor_units'),
            'employee_social_minor_units' =>
                $this->nonNegativeInt($social, 'employee_contribution_minor_units'),
            'employer_social_minor_units' =>
                $this->nonNegativeInt($payslip, 'employer_social_minor_units'),
            'health_assessment_base_minor_units' =>
                $this->nonNegativeInt($health, 'assessment_base_minor_units'),
            'employee_health_minor_units' => $employeeHealthTotal - $healthTopUp,
            'employer_health_minor_units' =>
                $this->nonNegativeInt($health, 'employer_contribution_minor_units'),
            'health_minimum_top_up_minor_units' => $healthTopUp,
            'advance_tax_base_minor_units' =>
                $advance === [] ? 0 : $this->nonNegativeInt($advance, 'rounded_tax_base_minor_units'),
            // Druhá polovina § 38j odst. 2 písm. f) bodu 3. Bez ní doklad
            // u srážkové daně vykazoval daň bez základu, ze kterého vznikla.
            'withholding_tax_base_minor_units' =>
                $this->nonNegativeInt($tax, 'withholding_base_minor_units'),
            'advance_tax_before_credits_minor_units' =>
                $advance === [] ? 0 : $this->nonNegativeInt($advance, 'tax_before_credits_minor_units'),
            // UPLATNĚNÁ sleva podle § 35ba (bod 5). Bod 5 žádá „měsíční slevu
            // na dani podle § 35ba", a tu § 35d odst. 3 věta první omezuje
            // výší zálohy: poskytnutou částku, ne nárok.
            // `advance_tax.non_refundable_credits_minor_units` je vstupní NÁROK,
            // se kterým záloha počítala — u nízké mzdy je vyšší než daň a doklad
            // by tvrdil slevu, která se nemohla poskytnout. Bod 5 druhé číslo
            // nežádá: přebytek nároku nad zálohou nemá u § 35ba kam jít, kdežto
            // u § 35c končí bonusem, a právě proto bod 6 vypisuje nárok
            // i uplatnění odděleně.
            'non_refundable_credits_minor_units' =>
                $this->nonNegativeInt($tax, 'applied_non_refundable_credits_minor_units'),
            // § 38j odst. 2 písm. f) bod 6 — vedle UPLATNĚNÉ slevy podle § 35c
            // žádá i samotné měsíční daňové zvýhodnění, tedy NÁROK podle § 35c
            // odst. 1. Bez něj doklad zamlčel nárok, který se nevešel do daně
            // a zároveň na něj nevznikl bonus.
            'child_entitlement_minor_units' =>
                $this->nonNegativeInt($tax, 'claimed_child_credit_minor_units'),
            // UPLATNĚNÁ sleva (§ 35c odst. 2) se čte z `applied_child_credit`,
            // ne ze zálohy. `advance_tax.child_credit_minor_units` je vstupní
            // NÁROK, se kterým měsíční záloha počítala — kdyby se tiskl sem,
            // doklad by u bonusového měsíce tvrdil, že se uplatnilo víc, než
            // kolik daň unesla, a součet slevy s bonusem by nárok převýšil.
            'child_credit_minor_units' =>
                $this->nonNegativeInt($tax, 'applied_child_credit_minor_units'),
            'advance_tax_minor_units' => $this->nonNegativeInt($net, 'advance_tax_minor_units'),
            'tax_bonus_minor_units' => $this->nonNegativeInt($net, 'tax_bonus_minor_units'),
            'withholding_tax_minor_units' =>
                $this->nonNegativeInt($net, 'withholding_tax_minor_units'),
            'other_deductions_minor_units' => $this->add(
                $this->nonNegativeInt($net, 'deducted_minor_units'),
                $this->nonNegativeInt($enforcementResult, 'total_withheld_minor_units'),
            ),
            'annual_settlement_minor_units' => $this->nonNegativeInt(
                $net + ['annual_settlement_minor_units' => 0],
                'annual_settlement_minor_units',
            ),
            // Záporná čistá výplata je legitimní (přeplatek u měsíce bez
            // peněžního příjmu s doplatkem ZP do minimálního vyměřovacího
            // základu, § 3 odst. 10 z. č. 592/1992 Sb.). Znaménko hlídá až
            // {@see PayrollSheetMonth}, který zároveň ověří, že sedí na
            // příjem, odvody, daň, bonus a srážky.
            'net_payable_minor_units' =>
                $this->integer($person, 'payable_after_enforcement_minor'),
        ];
    }

    /** @return array<string,mixed> */
    private function profileSnapshot(int $supplierId, int $employeeId, int $taxYear): array
    {
        $from = sprintf('%04d-01-01', $taxYear);
        $to = sprintf('%04d-12-31', $taxYear);
        $employee = $this->db->pdo()->prepare(
            'SELECT birth_date FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $employee->execute([$supplierId, $employeeId]);
        $employeeRow = $employee->fetch(PDO::FETCH_ASSOC);
        if (!is_array($employeeRow)) {
            throw new \DomainException('Zaměstnanec mzdového listu neexistuje.');
        }
        $identities = $this->db->pdo()->prepare(
            'SELECT id, full_name, birth_surname, effective_from, effective_to
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from, id
              FOR UPDATE'
        );
        $identities->execute([$supplierId, $employeeId, $to, $from]);
        $identityRows = $identities->fetchAll(PDO::FETCH_ASSOC);
        if ($identityRows === []) {
            throw new \DomainException('Pro mzdový list chybí účinná historie jména.');
        }
        $currentIdentity = $identityRows[array_key_last($identityRows)];
        $names = [];
        foreach ($identityRows as $identity) {
            foreach ([$identity['full_name'] ?? null, $identity['birth_surname'] ?? null] as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $names[trim($name)] = true;
                }
            }
        }
        unset($names[(string) $currentIdentity['full_name']]);

        $address = $this->db->pdo()->prepare(
            'SELECT id, street_line, city, postal_code, country_code
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ?
                AND address_type = "residence"
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $address->execute([$supplierId, $employeeId, $to, $from]);
        $addressRow = $address->fetch(PDO::FETCH_ASSOC);
        if (!is_array($addressRow)) {
            throw new \DomainException('Pro mzdový list chybí účinná adresa bydliště.');
        }
        if (($addressRow['country_code'] ?? null) !== 'CZ') {
            throw new \DomainException(
                'Mzdový list nerezidenta vyžaduje rozšířenou identitu dokladu a státu.',
            );
        }
        $identifier = $this->db->pdo()->prepare(
            'SELECT id, identifier_type, value_ciphertext
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?
                AND identifier_type = "birth_number"
              LIMIT 1
              FOR UPDATE'
        );
        $identifier->execute([$supplierId, $employeeId]);
        $identifierRow = $identifier->fetch(PDO::FETCH_ASSOC);
        if (is_array($identifierRow)) {
            $identifierLabel = 'Rodné číslo';
            $identifierValue = $this->sensitiveData->reveal(
                (string) $identifierRow['value_ciphertext'],
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
                (int) $identifierRow['id'],
                PayrollRevealPurpose::DOCUMENT_PAYROLL_SHEET,
            );
        } elseif (is_string($employeeRow['birth_date'] ?? null)) {
            $identifierLabel = 'Datum narození';
            $identifierValue = (new \DateTimeImmutable(
                (string) $employeeRow['birth_date'],
            ))->format('d.m.Y');
        } else {
            throw new \DomainException('Pro mzdový list chybí rodné číslo i datum narození.');
        }

        return [
            'name' => (string) $currentIdentity['full_name'],
            'previous_names' => array_keys($names),
            'identifier_label' => $identifierLabel,
            'identifier_value' => $identifierValue,
            'address' => implode(', ', [
                trim((string) $addressRow['street_line']),
                trim((string) $addressRow['postal_code'] . ' ' . (string) $addressRow['city']),
                (string) $addressRow['country_code'],
            ]),
        ];
    }

    /** @return array<string,string> */
    private function employerSnapshot(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(display_name, ""), company_name) AS name,
                    ic, street, zip, city
               FROM supplier
              WHERE id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Zaměstnavatel mzdového listu neexistuje.');
        }
        foreach (['name', 'ic', 'street', 'zip', 'city'] as $field) {
            if (!is_string($row[$field] ?? null) || trim((string) $row[$field]) === '') {
                throw new \DomainException("Zaměstnavatel nemá pro mzdový list vyplněné pole {$field}.");
            }
        }
        return [
            'name' => trim((string) $row['name']),
            'identification_number' => trim((string) $row['ic']),
            'address' => trim((string) $row['street'])
                . ', '
                . trim((string) $row['zip'] . ' ' . (string) $row['city']),
        ];
    }

    /**
     * @param array<string,mixed> $revision
     * @return array<string,mixed>
     */
    private function decryptSnapshot(array $revision): array
    {
        $json = $this->encryption->decryptFor(
            $this->text($revision, 'snapshot_ciphertext'),
            $this->encryptionContext(
                (int) $revision['supplier_id'],
                (int) $revision['employee_id'],
                (int) $revision['tax_year'],
                $this->hash($revision, 'source_manifest_hash'),
            ),
        );
        $hash = $this->hash($revision, 'snapshot_hash');
        $expected = $this->snapshotFingerprint($json, (int) $revision['supplier_id']);
        if (!hash_equals($hash, $expected)) {
            throw new \DomainException('Otisk ročního mzdového snapshotu nesouhlasí.');
        }
        return $this->decodeObject($json, 'Roční mzdový snapshot');
    }

    /** @param array<string,mixed> $snapshot */
    private function hydrate(array $snapshot, string $snapshotHash): PayrollSheetDocumentData
    {
        $schemaVersion = $snapshot['schema_version'] ?? null;
        if (!is_string($schemaVersion)
            || !in_array($schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)
        ) {
            throw new \DomainException('Roční mzdový snapshot má nepodporované schéma.');
        }
        $taxDetail = $schemaVersion === self::SCHEMA_VERSION_V1
            ? PayrollSheetMonth::TAX_DETAIL_NOT_RECORDED
            : PayrollSheetMonth::TAX_DETAIL_RECORDED;
        // Nárok na zvýhodnění a stav ročního zúčtování přibyly ve v3 a od té
        // doby ve snapshotu jsou; v4 mění jen zdroj slevy podle § 35ba.
        $recordedSinceV3 = in_array(
            $schemaVersion,
            [self::SCHEMA_VERSION_V3, self::SCHEMA_VERSION],
            true,
        );
        $childDetail = $recordedSinceV3
            ? PayrollSheetMonth::CHILD_DETAIL_RECORDED
            : PayrollSheetMonth::CHILD_DETAIL_NOT_RECORDED;
        // Do v3 včetně nesla kolonka slevy podle § 35ba NÁROK, ne poskytnutou
        // slevu. Zpětně se nepřepočítává — zmrazený snapshot je závazný obsah
        // vydané revize a rozdíl v něm už není z čeho dopočítat.
        $creditDetail = $schemaVersion === self::SCHEMA_VERSION
            ? PayrollSheetMonth::CREDIT_DETAIL_APPLIED
            : PayrollSheetMonth::CREDIT_DETAIL_CLAIMED;
        $employer = $this->object($snapshot['employer'] ?? null, 'employer');
        $employee = $this->object($snapshot['employee'] ?? null, 'employee');
        $months = [];
        foreach ($this->list($snapshot['months'] ?? null, 'months') as $row) {
            $row = $this->object($row, 'months[]');
            $months[] = new PayrollSheetMonth(
                $this->positiveInt($row, 'month'),
                $this->positiveInt($row, 'source_revision_count'),
                $this->nonNegativeInt($row, 'gross_minor_units'),
                $this->nonNegativeInt($row, 'cash_income_minor_units'),
                $this->nonNegativeInt($row, 'non_cash_income_minor_units'),
                $this->nonNegativeInt($row, 'social_assessment_base_minor_units'),
                $this->nonNegativeInt($row, 'employee_social_minor_units'),
                $this->nonNegativeInt($row, 'employer_social_minor_units'),
                $this->nonNegativeInt($row, 'health_assessment_base_minor_units'),
                $this->nonNegativeInt($row, 'employee_health_minor_units'),
                $this->nonNegativeInt($row, 'employer_health_minor_units'),
                $this->nonNegativeInt($row, 'health_minimum_top_up_minor_units'),
                $this->nonNegativeInt($row, 'advance_tax_base_minor_units'),
                $this->nonNegativeInt($row, 'advance_tax_before_credits_minor_units'),
                $this->nonNegativeInt($row, 'non_refundable_credits_minor_units'),
                $this->nonNegativeInt($row, 'child_credit_minor_units'),
                $this->nonNegativeInt($row, 'advance_tax_minor_units'),
                $this->nonNegativeInt($row, 'tax_bonus_minor_units'),
                $this->nonNegativeInt($row, 'withholding_tax_minor_units'),
                $this->nonNegativeInt($row, 'other_deductions_minor_units'),
                // Jediná podepsaná položka mzdového listu — viz PayrollSheetMonth.
                $this->integer($row, 'net_payable_minor_units'),
                // Starší zmrazené mzdové listy klíč nemají — vznikly dřív, než
                // se doplatek ze zúčtování vyplácel.
                $this->nonNegativeInt(
                    $row + ['annual_settlement_minor_units' => 0],
                    'annual_settlement_minor_units',
                ),
                $taxDetail === PayrollSheetMonth::TAX_DETAIL_RECORDED
                    ? $this->nonNegativeInt($row, 'withholding_tax_base_minor_units')
                    : 0,
                $taxDetail === PayrollSheetMonth::TAX_DETAIL_RECORDED
                    ? $this->nonNegativeInt($row, 'tax_exempt_income_minor_units')
                    : 0,
                $taxDetail,
                $childDetail === PayrollSheetMonth::CHILD_DETAIL_RECORDED
                    ? $this->nonNegativeInt($row, 'child_entitlement_minor_units')
                    : 0,
                $childDetail,
                $creditDetail,
            );
        }
        $previousNames = $this->list($employee['previous_names'] ?? null, 'previous_names');
        foreach ($previousNames as $name) {
            if (!is_string($name)) {
                throw new \DomainException('Dřívější jméno v ročním snapshotu není text.');
            }
        }
        $employments = [];
        foreach ($this->list($snapshot['employments'] ?? [], 'employments') as $row) {
            $row = $this->object($row, 'employments[]');
            $employments[] = [
                'code' => $this->text($row, 'code'),
                'relation_type' => $this->text($row, 'relation_type'),
                'start_date' => $this->text($row, 'start_date'),
                'actual_start_date' => $this->nullableText($row, 'actual_start_date'),
                'end_date' => $this->nullableText($row, 'end_date'),
            ];
        }
        return new PayrollSheetDocumentData(
            $snapshotHash,
            $this->positiveInt($snapshot, 'tax_year'),
            $this->text($employer, 'name'),
            $this->text($employer, 'identification_number'),
            $this->text($employer, 'address'),
            $this->text($employee, 'name'),
            $previousNames,
            $this->text($employee, 'identifier_label'),
            $this->text($employee, 'identifier_value'),
            $this->text($employee, 'address'),
            $months,
            // Starší mapování stav ročního zúčtování nezjišťovalo a zapisovalo
            // natvrdo „neprovedeno". Vydat to slovo znovu by znamenalo tvrdit
            // něco, co revize nikdy neposoudila.
            $recordedSinceV3
                ? $this->text($snapshot, 'annual_settlement_status')
                : PayrollSheetDocumentData::ANNUAL_SETTLEMENT_NOT_RECORDED,
            $employments,
            $recordedSinceV3
                ? $this->nullableObject($snapshot['annual_settlement'] ?? null, 'annual_settlement')
                : null,
            $recordedSinceV3
                ? $this->nullableTextMap(
                    $snapshot['annual_settlement_evidence'] ?? null,
                    'annual_settlement_evidence',
                )
                : null,
        );
    }

    /**
     * Zmrazený výsledek ročního zúčtování za rok a doklad o posouzení podmínek.
     *
     * Nic se nepřepočítává: čtou se hodnoty zmrazené v revizi
     * `annual_settlement_result`, protože ta je závaznou pravdou o zúčtování
     * (rejstřík `payroll_annual_settlement_outcomes` je jen dotazovatelný
     * protějšek a sám o sobě údaje o VÝPOČTU daně nenese).
     *
     * Odmítnuté zúčtování se do revize nezmrazuje — proto se důvod bere
     * z evidence žádosti podle § 38ch odst. 1 a 3. Když ani ta neexistuje,
     * vrací se `null` a doklad to pojmenuje jako chybějící podklad, ne jako
     * „nepožádal".
     *
     * @return array{0:?array<string,mixed>,1:?array<string,string>}
     */
    private function annualSettlement(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $revision = $this->annualRevisions->latest(
            $supplierId,
            $employeeId,
            $taxYear,
            AnnualSettlementSnapshotBuilder::PURPOSE,
        );
        $evidence = $this->annualSettlementEvidence($supplierId, $employeeId, $taxYear);
        if ($revision === null) {
            return [null, $evidence];
        }

        $snapshot = $this->decryptAnnualSnapshot(
            $revision,
            AnnualSettlementSnapshotBuilder::PURPOSE,
        );
        if (($snapshot['schema_version'] ?? null)
            !== AnnualSettlementSnapshotBuilder::SCHEMA_VERSION
        ) {
            throw new \DomainException(
                'Revize ročního zúčtování má nepodporované schéma.',
            );
        }
        $result = $this->object($snapshot['result'] ?? null, 'annual_settlement.result');
        $trace = $this->object($result['trace'] ?? null, 'annual_settlement.result.trace');
        $external = is_array($trace['external_certificates'] ?? null)
            ? $trace['external_certificates']
            : [];

        return [[
            'revision_id' => (int) $revision['id'],
            'snapshot_hash' => $this->hash($revision, 'snapshot_hash'),
            'settled_on' => $this->text($snapshot, 'settled_on'),
            'completed_months' => $this->nonNegativeInt($trace, 'completed_months'),
            'advance_base_minor_units' =>
                $this->nonNegativeInt($trace, 'advance_base_minor_units'),
            'rounded_tax_base_minor_units' =>
                $this->nonNegativeInt($result, 'rounded_tax_base_minor_units'),
            'tax_before_credits_minor_units' =>
                $this->nonNegativeInt($result, 'tax_before_credits_minor_units'),
            'annual_credits_minor_units' =>
                $this->nonNegativeInt($result, 'annual_credits_minor_units'),
            'applied_credits_minor_units' =>
                $this->nonNegativeInt($result, 'applied_credits_minor_units'),
            'child_entitlement_minor_units' =>
                $this->nonNegativeInt($result, 'child_entitlement_minor_units'),
            'child_credit_minor_units' =>
                $this->nonNegativeInt($result, 'child_credit_minor_units'),
            'annual_tax_bonus_minor_units' =>
                $this->nonNegativeInt($result, 'annual_tax_bonus_minor_units'),
            'tax_after_all_credits_minor_units' =>
                $this->nonNegativeInt($result, 'tax_after_all_credits_minor_units'),
            'advance_tax_minor_units' =>
                $this->nonNegativeInt($trace, 'advance_tax_minor_units'),
            'monthly_tax_bonus_minor_units' =>
                $this->nonNegativeInt($trace, 'monthly_tax_bonus_minor_units'),
            'external_certificate_count' => is_int($external['count'] ?? null)
                ? $external['count']
                : 0,
            'tax_difference_minor_units' =>
                $this->integer($result, 'tax_difference_minor_units'),
            'bonus_difference_minor_units' =>
                $this->integer($result, 'bonus_difference_minor_units'),
            'settlement_difference_minor_units' =>
                $this->integer($result, 'settlement_difference_minor_units'),
            'payable_minor_units' => $this->nonNegativeInt($result, 'payable_minor_units'),
            'outcome' => $this->text($result, 'outcome'),
        ], $evidence];
    }

    /** @return ?array<string,string> */
    private function annualSettlementEvidence(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT request_status, prior_employers, filing_obligation, annual_claims
               FROM payroll_annual_settlement_requests
              WHERE supplier_id = ? AND employee_id = ? AND tax_year = ?'
        );
        $statement->execute([$supplierId, $employeeId, $taxYear]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'request_status' => (string) $row['request_status'],
            'prior_employers' => (string) $row['prior_employers'],
            'filing_obligation' => (string) $row['filing_obligation'],
            'annual_claims' => (string) $row['annual_claims'],
        ];
    }

    /**
     * @param array<string,mixed> $revision
     * @return array<string,mixed>
     */
    private function decryptAnnualSnapshot(array $revision, string $purpose): array
    {
        $json = $this->encryption->decryptFor(
            $this->text($revision, 'snapshot_ciphertext'),
            implode(':', [
                'payroll-annual-document',
                (string) (int) $revision['supplier_id'],
                (string) (int) $revision['employee_id'],
                (string) (int) $revision['tax_year'],
                $purpose,
                $this->hash($revision, 'source_manifest_hash'),
            ]),
        );
        $hash = $this->hash($revision, 'snapshot_hash');
        // Otisk se ověřuje pod doménou toho, KDO revizi vydal. Vlastní doména
        // mzdového listu sem nepatří — pod ní žádná skutečná revize ročního
        // zúčtování nevznikla, takže by písm. h) nešlo přečíst nikdy.
        if (!hash_equals(
            $hash,
            $this->sensitiveData->keyedFingerprint(
                $json,
                self::ANNUAL_FINGERPRINT_DOMAINS[$purpose]
                    ?? throw new \DomainException(
                        "Roční revize {$purpose} nemá známou doménu otisku.",
                    ),
                (int) $revision['supplier_id'],
            ),
        )) {
            throw new \DomainException('Otisk roční revize nesouhlasí s jejím obsahem.');
        }

        return $this->decodeObject($json, 'Roční revize ' . $purpose);
    }

    /** @return ?array<string,mixed> */
    private function nullableObject(mixed $value, string $field): ?array
    {
        return $value === null ? null : $this->object($value, $field);
    }

    /** @return ?array<string,string> */
    private function nullableTextMap(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        $row = $this->object($value, $field);
        $result = [];
        foreach ($row as $key => $item) {
            if (!is_string($item)) {
                throw new \DomainException("Pole {$field}.{$key} není text.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function encryptionContext(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        string $manifestHash,
    ): string {
        return implode(':', [
            'payroll-annual-document',
            (string) $supplierId,
            (string) $employeeId,
            (string) $taxYear,
            self::PURPOSE,
            $manifestHash,
        ]);
    }

    private function snapshotFingerprint(string $snapshotJson, int $supplierId): string
    {
        return $this->sensitiveData->keyedFingerprint(
            $snapshotJson,
            'annual-payroll-snapshot-v1',
            $supplierId,
        );
    }

    private function assertJsonHash(string $json, string $hash, string $context): void
    {
        if (!hash_equals($hash, hash('sha256', $json))) {
            throw new \DomainException("Otisk {$context} nesouhlasí.");
        }
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json, string $context): array
    {
        return $this->object(
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            $context,
        );
    }

    /** @param array<string,mixed> $row */
    private function hash(array $row, string $field): string
    {
        $value = $this->text($row, $field);
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \DomainException("Pole {$field} není platný SHA-256.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException("Chybí textové pole {$field}.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \DomainException("Pole {$field} není celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private function nullableText(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException("Pole {$field} není text ani prázdná hodnota.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private function positiveInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if ((is_int($value) || (is_string($value) && ctype_digit($value)))
            && (int) $value > 0
        ) {
            return (int) $value;
        }
        throw new \DomainException("Pole {$field} není kladné celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private function nonNegativeInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if ((is_int($value) || (is_string($value) && ctype_digit($value)))
            && (int) $value >= 0
        ) {
            return (int) $value;
        }
        throw new \DomainException("Pole {$field} není nezáporné celé číslo.");
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$context} není objekt.");
        }
        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("{$context} není seznam.");
        }
        return $value;
    }

    private function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new \OverflowException('Agregace mzdového listu přetekla.');
        }
        return $left + $right;
    }
}
