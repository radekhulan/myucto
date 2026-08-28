<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualDocumentRepository;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\IncomeTax\EuropeanEconomicAreaCountries;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

class AnnualTaxCertificateSnapshotBuilder
{
    public const SCHEMA_VERSION = 'annual-tax-certificate-snapshot.v5';
    public const MAPPING_VERSION = 'annual-tax-certificate-2026-mapping.v5';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAnnualDocumentRepository $annualRevisions,
        private readonly PayrollDocumentRepository $documents,
        private readonly PayrollDocumentEmployerSnapshotProvider $employers,
        private readonly AnnualTaxCertificatePaymentEvidenceProvider $payments,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly SecretEncryption $encryption,
    ) {}

    /**
     * @return array{
     *   revision:array<string,mixed>,
     *   document:AnnualTaxCertificateDocumentData,
     *   archived_document?:array<string,mixed>
     * }
     */
    public function build(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        PayrollDocumentKind $kind,
        ?int $actorUserId,
        ?int $supersedesDocumentId = null,
        ?string $correctionReason = null,
    ): array {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException(
                'Roční daňový snapshot vyžaduje aktivní transakci.',
            );
        }
        if ($supplierId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException(
                'Identita ročního daňového potvrzení není platná.',
            );
        }
        $form = AnnualTaxCertificateFormCatalog::resolve($taxYear, $kind);
        $sources = $this->annualRevisions->lockApprovedYearSources(
            $supplierId,
            $employeeId,
            $taxYear,
        );
        if ($sources === []) {
            throw new \DomainException(
                'Daňové potvrzení nelze vytvořit bez schváleného výsledku '
                . 'zaměstnance v daném roce.',
            );
        }
        $employer = ($this->employers)($supplierId);
        $cutoff = ($taxYear + 1) . '-01-31';
        [
            'months' => $months,
            'income_minor_units' => $incomeMinorUnits,
            'tax_minor_units' => $taxMinorUnits,
            'tax_bonus_minor_units' => $taxBonusMinorUnits,
            'last_payment_date' => $lastPaymentDate,
            'manifest_sources' => $manifestSources,
            'payment_evidence' => $paymentEvidence,
            'tax_declaration' => $taxDeclaration,
            'tax_residence' => $taxResidence,
            'disability_tax_credits' => $disabilityTaxCredits,
            'child_claim_months' => $childClaimMonths,
            'nonresident_insurance_minor_units' => $nonresidentInsuranceMinorUnits,
        ] = $this->certificateAmounts(
            $sources,
            $supplierId,
            $employeeId,
            $kind,
            $cutoff,
        );
        if ($months === [] || $lastPaymentDate === null) {
            throw new \DomainException(
                'Pro zvolený druh potvrzení neexistuje doložený zdanitelný příjem.',
            );
        }
        $profile = $this->profileSnapshot(
            $supplierId,
            $employeeId,
            $taxYear,
            $taxResidence,
        );
        $childTaxBenefits = $this->childTaxBenefits(
            $supplierId,
            $employeeId,
            $childClaimMonths,
        );
        $annualSettlementEvidence = $this->annualSettlementResult(
            $supplierId,
            $employeeId,
            $taxYear,
            $kind,
        );
        $annualSettlement = $annualSettlementEvidence['value'];

        $formSnapshot = self::formSnapshot($form);
        $profileHash = $this->fingerprint(
            $profile,
            'annual-tax-certificate-profile-v1',
            $supplierId,
        );
        $employerSnapshot = $employer->toArray();
        $employerHash = $this->fingerprint(
            $employerSnapshot,
            'annual-tax-certificate-employer-v1',
            $supplierId,
        );
        $correctionReason = self::correctionReason($correctionReason);
        if (($supersedesDocumentId === null) !== ($correctionReason === null)) {
            throw new \DomainException(
                'Oprava vyžaduje ID nahrazovaného potvrzení i konkrétní důvod.',
            );
        }
        if ($supersedesDocumentId !== null && $supersedesDocumentId <= 0) {
            throw new \DomainException(
                'ID nahrazovaného potvrzení není platné.',
            );
        }
        $supersededDocument = $supersedesDocumentId === null
            ? null
            : $this->documents->find($supplierId, $supersedesDocumentId);
        if ($supersedesDocumentId !== null
            && !$this->isCompatibleDocument(
                $supersededDocument,
                $employeeId,
                $taxYear,
                $kind,
            )
        ) {
            throw new \DomainException(
                'Nahrazované potvrzení nepatří zvolené osobě, roku a druhu.',
            );
        }
        $replacesIssuedAt = $supersededDocument === null
            ? null
            : $this->documentIssuedAt($supersededDocument);
        $issuanceManifest = [
            'supersedes_document_id' => $supersedesDocumentId,
            'replaces_issued_at' => $replacesIssuedAt,
            'correction_reason' => $correctionReason,
        ];
        $manifest = [
            'schema_version' =>
                'annual-tax-certificate-source-manifest.v3',
            'document_schema_version' =>
                AnnualTaxCertificateDocumentData::SCHEMA_VERSION,
            'renderer_version' => AnnualTaxCertificatePdfRenderer::VERSION,
            'snapshot_schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => $kind->value,
            'tax_year' => $taxYear,
            'employee_id' => $employeeId,
            'form' => $formSnapshot,
            'profile_snapshot_hash' => $profileHash,
            'employer_snapshot_hash' => $employerHash,
            'sources' => $manifestSources,
            'annual_settlement_source' => $annualSettlementEvidence['source'],
            'issuance' => $issuanceManifest,
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $manifestHash = hash('sha256', $manifestJson);
        $existing = $this->annualRevisions->findBySourceManifest(
            $supplierId,
            $employeeId,
            $taxYear,
            $kind->value,
            $manifestHash,
        );
        if ($existing !== null) {
            $archived = $this->documents->findAnnualArtifact(
                $supplierId,
                $this->positiveInt($existing, 'id'),
                $employeeId,
                $kind->value,
                AnnualTaxCertificateDocumentData::SCHEMA_VERSION,
                AnnualTaxCertificatePdfRenderer::VERSION,
            );
            $existingSnapshotHash = $this->hash(
                $existing,
                'snapshot_hash',
            );

            $prepared = [
                'revision' => $existing,
                'document' => $this->hydrate(
                    $this->decryptSnapshot($existing, $kind),
                    $existingSnapshotHash,
                    $kind,
                ),
            ];
            if ($archived !== null) {
                $prepared['archived_document'] = $archived;
            }

            return $prepared;
        }
        $latestDocument = $this->documents->latestForAnnualKind(
            $supplierId,
            $employeeId,
            $taxYear,
            $kind->value,
            $kind->value,
        );
        if ($latestDocument !== null && $supersedesDocumentId === null) {
            throw new \DomainException(
                'Opravné daňové potvrzení vyžaduje aktuální ID '
                . 'nahrazovaného dokumentu a konkrétní důvod.',
            );
        }
        if ($latestDocument === null && $supersedesDocumentId !== null) {
            throw new \DomainException(
                'Opravu nelze vytvořit bez předchozího vydaného potvrzení.',
            );
        }
        if ($latestDocument !== null
            && $this->positiveInt($latestDocument, 'id')
                !== $supersedesDocumentId
        ) {
            throw new \DomainException(
                'Nahrazované potvrzení již není aktuální; obnovte seznam dokumentů.',
            );
        }
        $issuedAt = $this->databaseTimestamp();
        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => $kind->value,
            'tax_year' => $taxYear,
            'form' => $formSnapshot,
            'employer' => $employerSnapshot,
            'employee' => $profile,
            'months' => $months,
            'tax_declaration' => $taxDeclaration,
            'tax_residence' => $taxResidence,
            'issued_at' => $issuedAt,
            'supersedes_document_id' => $supersedesDocumentId,
            'replaces_issued_at' => $replacesIssuedAt,
            'correction_reason' => $correctionReason,
            'employer_product_contributions_minor_units' => [
                'supplementary_pension' => 0,
                'pension_insurance' => 0,
                'private_life_insurance' => 0,
                'long_term_investment_product' => 0,
            ],
            'child_tax_benefits' => $childTaxBenefits,
            'disability_tax_credits' => $disabilityTaxCredits,
            'annual_settlement' => $annualSettlement,
            'nonresident_insurance_minor_units' =>
                $nonresidentInsuranceMinorUnits,
            'accrued_income_minor_units' => $incomeMinorUnits,
            'paid_income_minor_units' => $incomeMinorUnits,
            'advance_tax_minor_units' =>
                $kind
                === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                    ? $taxMinorUnits
                    : 0,
            'withholding_tax_minor_units' =>
                $kind
                === PayrollDocumentKind::TaxableIncomeWithholdingCertificate
                    ? $taxMinorUnits
                    : 0,
            'tax_bonus_minor_units' => $taxBonusMinorUnits,
            'payment_evidence_cutoff' => $cutoff,
            'last_proven_payment_date' => $lastPaymentDate,
            'payment_evidence' => $paymentEvidence,
        ];
        $snapshotJson = CanonicalJson::encode($snapshot);
        $snapshotHash = $this->sensitiveData->keyedFingerprint(
            $snapshotJson,
            'annual-tax-certificate-snapshot-v1',
            $supplierId,
        );

        $previous = $this->annualRevisions->latest(
            $supplierId,
            $employeeId,
            $taxYear,
            $kind->value,
        );
        $ciphertext = $this->encryption->encryptFor(
            $snapshotJson,
            $this->encryptionContext(
                $supplierId,
                $employeeId,
                $taxYear,
                $kind,
                $manifestHash,
            ),
        );
        $revision = $this->annualRevisions->insertApproved([
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'tax_year' => $taxYear,
            'purpose' => $kind->value,
            'revision_no' =>
                $previous === null
                    ? 1
                    : $this->positiveInt($previous, 'revision_no') + 1,
            'previous_revision_id' =>
                $previous === null
                    ? null
                    : $this->positiveInt($previous, 'id'),
            'snapshot_ciphertext' => $ciphertext,
            'snapshot_hash' => $snapshotHash,
            'source_manifest_json' => $manifestJson,
            'source_manifest_hash' => $manifestHash,
            'approved_by' => $actorUserId,
        ], $sources);

        return [
            'revision' => $revision,
            'document' => $this->hydrate($snapshot, $snapshotHash, $kind),
        ];
    }

    /**
     * @param list<array<string,mixed>> $sources
     * @return array{
     *   months:list<int>,
     *   income_minor_units:int,
     *   tax_minor_units:int,
     *   tax_bonus_minor_units:int,
     *   last_payment_date:?string,
     *   manifest_sources:list<array<string,mixed>>,
     *   payment_evidence:list<array<string,mixed>>,
     *   tax_declaration:?array{
     *     status:string,
     *     signed_months:list<int>,
     *     monthly_evidence:list<array{
     *       month:int,
     *       status:string,
     *       effective_from:string,
     *       effective_to:?string
     *     }>
     *   },
     *   tax_residence:array{status:string,country_code:string},
     *   disability_tax_credits:list<array{period:string,degree:string}>,
     *   child_claim_months:array<string,array<int,array{order:int,ztp_p:bool}>>,
     *   nonresident_insurance_minor_units:?int
     * }
     */
    private function certificateAmounts(
        array $sources,
        int $supplierId,
        int $employeeId,
        PayrollDocumentKind $kind,
        string $cutoff,
    ): array {
        $months = [];
        $income = 0;
        $tax = 0;
        $taxBonus = 0;
        $lastPaymentDate = null;
        $manifestSources = [];
        $paymentEvidence = [];
        $declarationEvidence = [];
        $taxResidence = null;
        $creditMonths = [];
        $childClaimMonths = [];
        $nonresidentInsurance = 0;
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

            $inputRoot = $this->decodeObject(
                $inputJson,
                'Vstupní snapshot schválené revize',
            );
            $resultRoot = $this->decodeObject(
                $resultJson,
                'Výsledek schválené revize',
            );
            $storedPerson = $this->decodeObject(
                $personJson,
                'Výsledek zaměstnance',
            );
            $resultPerson = $this->personFromResult(
                $resultRoot,
                $employeeId,
            );
            if (!hash_equals(
                $personHash,
                hash('sha256', CanonicalJson::encode($resultPerson)),
            ) || !hash_equals(
                $personHash,
                hash('sha256', CanonicalJson::encode($storedPerson)),
            )) {
                throw new \DomainException(
                    'Výsledek zaměstnance nesouhlasí se schválenou revizí.',
                );
            }
            $inputPerson = $this->personFromInput(
                $inputRoot,
                $employeeId,
            );
            $periodStart = $this->text($source, 'period_start');
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $periodStart) !== 1) {
                throw new \DomainException(
                    'Zdroj daňového potvrzení má neplatné období.',
                );
            }
            $monthNumber = (int) substr($periodStart, 5, 2);
            $amounts = $this->monthAmounts(
                $storedPerson,
                $inputPerson,
                $kind,
            );
            $proofHash = null;
            if ($amounts['income_minor_units'] > 0) {
                $proof = $this->payments->prove(
                    $supplierId,
                    $employeeId,
                    $this->positiveInt($source, 'run_id'),
                    $this->positiveInt($source, 'revision_id'),
                    $amounts['expected_net_minor_units'],
                    $cutoff,
                );
                $proofHash = $this->fingerprint(
                    $proof,
                    'annual-tax-certificate-payment-proof-v1',
                    $supplierId,
                );
                $paymentEvidence[] = $proof;
                $months[$monthNumber] = true;
                $monthTaxEvidence = $amounts['tax_evidence'];
                $monthResidence = $monthTaxEvidence['residence'];
                if ($taxResidence !== null
                    && $taxResidence !== $monthResidence
                ) {
                    throw new \DomainException(
                        'Daňová rezidence není ve zdrojových měsících jednotná.',
                    );
                }
                $taxResidence = $monthResidence;
                foreach ($monthTaxEvidence['credit_claims'] as $claim) {
                    $creditMonths[$claim['credit_kind']][$monthNumber] = true;
                }
                foreach ($monthTaxEvidence['child_claims'] as $claim) {
                    $reference = $claim['child_reference'];
                    if (isset($childClaimMonths[$reference][$monthNumber])) {
                        throw new \DomainException(
                            'Dítě je v jednom měsíci daňového potvrzení uplatněno vícekrát.',
                        );
                    }
                    $childClaimMonths[$reference][$monthNumber] = [
                        'order' => $claim['child_order'],
                        'ztp_p' => $claim['ztp_p'],
                    ];
                }
                if ($monthResidence['status'] === TaxResidence::NonResident->value) {
                    $nonresidentInsurance = $this->add(
                        $nonresidentInsurance,
                        $this->nonresidentInsurance($storedPerson),
                    );
                }
                if ($kind
                    === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                ) {
                    $declaration = $monthTaxEvidence['declaration'];
                    if ($declaration === null) {
                        throw new \DomainException(
                            'Prohlášení poplatníka ve zdrojovém měsíci chybí.',
                        );
                    }
                    $declarationEvidence[] = [
                        'month' => $monthNumber,
                        'status' => $declaration['status'],
                        'effective_from' => $declaration['effective_from'],
                        'effective_to' => $declaration['effective_to'],
                    ];
                }
                $income = $this->add(
                    $income,
                    $amounts['income_minor_units'],
                );
                $tax = $this->add($tax, $amounts['tax_minor_units']);
                $taxBonus = $this->add(
                    $taxBonus,
                    $amounts['tax_bonus_minor_units'],
                );
                if ($lastPaymentDate === null
                    || $proof['last_payment_date'] > $lastPaymentDate
                ) {
                    $lastPaymentDate = $proof['last_payment_date'];
                }
            }
            $manifestSources[] = [
                'period_start' => $periodStart,
                'run_id' => $this->positiveInt($source, 'run_id'),
                'revision_id' => $this->positiveInt($source, 'revision_id'),
                'input_snapshot_hash' => $inputHash,
                'result_snapshot_hash' => $resultHash,
                'person_result_hash' => $personHash,
                'payment_evidence_hash' => $proofHash,
            ];
        }
        $monthList = array_map(
            static fn (int|string $month): int => (int) $month,
            array_keys($months),
        );
        sort($monthList, SORT_NUMERIC);
        usort(
            $declarationEvidence,
            static fn (array $left, array $right): int =>
                $left['month'] <=> $right['month'],
        );
        $signedMonths = array_values(array_map(
            static fn (array $evidence): int => $evidence['month'],
            array_filter(
                $declarationEvidence,
                static fn (array $evidence): bool =>
                    $evidence['status'] === 'signed',
            ),
        ));
        $taxDeclaration = null;
        if ($kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate) {
            $status = match (count($signedMonths)) {
                0 => 'not-signed',
                count($monthList) => 'signed',
                default => 'mixed',
            };
            $taxDeclaration = [
                'status' => $status,
                'signed_months' => $signedMonths,
                'monthly_evidence' => $declarationEvidence,
            ];
        }
        if ($taxResidence === null) {
            throw new \DomainException(
                'Daňové potvrzení nemá doloženou daňovou rezidenci.',
            );
        }

        return [
            'months' => $monthList,
            'income_minor_units' => $income,
            'tax_minor_units' => $tax,
            'tax_bonus_minor_units' => $taxBonus,
            'last_payment_date' => $lastPaymentDate,
            'manifest_sources' => $manifestSources,
            'payment_evidence' => $paymentEvidence,
            'tax_declaration' => $taxDeclaration,
            'tax_residence' => $taxResidence,
            'disability_tax_credits' =>
                self::disabilityTaxCredits($creditMonths),
            'child_claim_months' => $childClaimMonths,
            'nonresident_insurance_minor_units' =>
                $kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                    && $taxResidence['status'] === TaxResidence::NonResident->value
                    ? $nonresidentInsurance
                    : null,
        ];
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $inputPerson
     * @return array{
     *   income_minor_units:int,
     *   tax_minor_units:int,
     *   tax_bonus_minor_units:int,
     *   expected_net_minor_units:int,
     *   tax_evidence:array{
     *     declaration:?array{
     *       status:string,
     *       effective_from:string,
     *       effective_to:?string
     *     },
     *     residence:array{status:string,country_code:string},
     *     credit_claims:list<array{credit_kind:string}>,
     *     child_claims:list<array{
     *       child_reference:string,
     *       child_order:int,
     *       ztp_p:bool
     *     }>
     *   }
     * }
     */
    private function monthAmounts(
        array $person,
        array $inputPerson,
        PayrollDocumentKind $kind,
    ): array {
        $statutory = $this->object(
            $person['statutory'] ?? null,
            'statutory',
        );
        if (($statutory['status'] ?? null) !== 'calculated') {
            throw new \DomainException(
                'Daňové potvrzení vyžaduje uzavřený zákonný výpočet.',
            );
        }
        $tax = $this->object(
            $statutory['income_tax'] ?? null,
            'income_tax',
        );
        if (($tax['status'] ?? null) !== 'calculated') {
            throw new \DomainException(
                'Daňové potvrzení vyžaduje uzavřený výpočet daně.',
            );
        }
        $net = $this->object(
            $statutory['net_pay'] ?? null,
            'net_pay',
        );
        $advance = ($tax['advance_tax'] ?? null) === null
            ? []
            : $this->object($tax['advance_tax'], 'advance_tax');
        $income = $kind
            === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                ? ($advance === []
                    ? 0
                    : $this->nonNegativeInt(
                        $advance,
                        'taxable_income_minor_units',
                    ))
                : $this->nonNegativeInt(
                    $tax,
                    'withholding_base_minor_units',
                );
        $taxAmount = $kind
            === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                ? $this->nonNegativeInt($net, 'advance_tax_minor_units')
                : $this->nonNegativeInt(
                    $tax,
                    'withholding_tax_minor_units',
                );
        $taxBonus = $kind
            === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                ? $this->nonNegativeInt($net, 'tax_bonus_minor_units')
                : 0;
        if ($income > 0) {
            if ($this->nonNegativeInt(
                $net,
                'non_cash_income_minor_units',
            ) > 0) {
                throw new \DomainException(
                    'Nepeněžní příjem nemá doložené datum skutečného obdržení.',
                );
            }
            $taxEvidence = $this->supportedInputEvidence(
                $inputPerson,
                $kind,
            );
            $this->assertNoBackpay($inputPerson);
            foreach ([
                'zdanitelný příjem' => $income,
                'skutečně sražená daň' => $taxAmount,
                'daňový bonus' => $taxBonus,
            ] as $label => $amount) {
                if ($amount % 100 !== 0) {
                    throw new \DomainException(
                        "Měsíční {$label} nelze vykázat v celých Kč.",
                    );
                }
            }
        }
        // Podepsaná částka: měsíc bez zdanitelného příjmu, ve kterém se odvedl
        // doplatek zdravotního pojištění do minimálního vyměřovacího základu
        // (§ 3 odst. 10 z. č. 592/1992 Sb.), skončí zápornou výplatou. Věcná
        // brána je podmínka níž — doložit VYPLACENÝ zdanitelný příjem nulovou
        // nebo zápornou výplatou pořád nejde — a ta zůstává beze změny.
        $expectedNet = $this->int(
            $person,
            'payable_after_enforcement_minor',
        );
        if ($income > 0 && $expectedNet <= 0) {
            throw new \DomainException(
                'Skutečnou výplatu zdanitelného příjmu nelze doložit '
                . 'nulovým závazkem čisté mzdy.',
            );
        }

        return [
            'income_minor_units' => $income,
            'tax_minor_units' => $taxAmount,
            'tax_bonus_minor_units' => $taxBonus,
            'expected_net_minor_units' => $expectedNet,
            'tax_evidence' => $taxEvidence ?? [
                'declaration' => null,
                'residence' => [
                    'status' => 'czech-resident',
                    'country_code' => 'CZ',
                ],
                'credit_claims' => [],
                'child_claims' => [],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $inputPerson
     * @return array{
     *   declaration:?array{
     *     status:string,
     *     effective_from:string,
     *     effective_to:?string
     *   },
     *   residence:array{status:string,country_code:string},
     *   credit_claims:list<array{credit_kind:string}>,
     *   child_claims:list<array{
     *     child_reference:string,
     *     child_order:int,
     *     ztp_p:bool
     *   }>
     * }
     */
    private function supportedInputEvidence(
        array $inputPerson,
        PayrollDocumentKind $kind,
    ): array {
        $statutory = $this->object(
            $inputPerson['statutory_evidence'] ?? null,
            'statutory_evidence',
        );
        $incomeTax = $this->object(
            $statutory['income_tax'] ?? null,
            'statutory_evidence.income_tax',
        );
        $residence = $this->object(
            $incomeTax['residence'] ?? null,
            'statutory_evidence.income_tax.residence',
        );
        $residenceValue = $residence['residence'] ?? null;
        $residenceStatus = is_string($residenceValue)
            ? TaxResidence::tryFrom($residenceValue)
            : null;
        $countryCode = $residence['country_code'] ?? null;
        if (!$residenceStatus instanceof TaxResidence
            || $residenceStatus === TaxResidence::Unverified
            || !is_string($countryCode)
            || preg_match('/^[A-Z]{2}$/D', $countryCode) !== 1
            || ($residenceStatus === TaxResidence::CzechResident
                && $countryCode !== 'CZ')
            || ($residenceStatus === TaxResidence::NonResident
                && $countryCode === 'CZ')
        ) {
            throw new \DomainException(
                'Daňové potvrzení nemá platně doloženou daňovou rezidenci.',
            );
        }
        if ($kind === PayrollDocumentKind::TaxableIncomeWithholdingCertificate
            && $residenceStatus === TaxResidence::NonResident
            && !EuropeanEconomicAreaCountries::contains($countryCode)
        ) {
            throw new \DomainException(
                'Srážkové potvrzení lze vystavit jen nerezidentovi EU nebo EHP.',
            );
        }
        $declaration = null;
        if ($kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate) {
            if (($incomeTax['declaration'] ?? null) === null) {
                throw new \DomainException(
                    'Prohlášení poplatníka ve zdrojovém měsíci chybí.',
                );
            }
            $declaration = $this->object(
                $incomeTax['declaration'],
                'statutory_evidence.income_tax.declaration',
            );
            $status = $declaration['status'] ?? null;
            if (!in_array($status, ['signed', 'not-signed'], true)) {
                throw new \DomainException(
                    'Prohlášení poplatníka nemá ověřený stav.',
                );
            }
            $effectiveFrom = $this->text(
                $declaration,
                'effective_from',
            );
            $effectiveTo = $declaration['effective_to'] ?? null;
            if ($effectiveTo !== null && !is_string($effectiveTo)) {
                throw new \DomainException(
                    'Konec účinnosti Prohlášení poplatníka není platný.',
                );
            }
            $declaration = [
                'status' => $status,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
            ];
        }
        $creditClaims = [];
        foreach ($this->list(
            $incomeTax['credit_claims'] ?? null,
            'statutory_evidence.income_tax.credit_claims',
        ) as $claim) {
            $claim = $this->object($claim, 'credit_claims[]');
            $creditKindValue = $claim['credit_kind'] ?? null;
            $creditKind = is_string($creditKindValue)
                ? TaxCreditKind::tryFrom($creditKindValue)
                : null;
            if (!$creditKind instanceof TaxCreditKind
                || ($claim['evidence_status'] ?? null) !== 'verified') {
                throw new \DomainException(
                    'Daňová sleva nemá doložený podporovaný druh.',
                );
            }
            $creditClaims[] = ['credit_kind' => $creditKind->value];
        }
        $childClaims = [];
        foreach ($this->list(
            $incomeTax['child_claims'] ?? null,
            'statutory_evidence.income_tax.child_claims',
        ) as $claim) {
            $claim = $this->object($claim, 'child_claims[]');
            $reference = $claim['child_reference'] ?? null;
            $order = $claim['child_order'] ?? null;
            if (!is_string($reference)
                || preg_match('/^dependant-[1-9][0-9]*$/D', $reference) !== 1
                || !is_int($order)
                || $order < 1
                || !is_bool($claim['ztp_p'] ?? null)
                || ($claim['evidence_status'] ?? null) !== 'verified'
                || ($claim['shared_household_confirmed'] ?? null) !== true
                || ($claim['other_claimant_excluded'] ?? null) !== true
            ) {
                throw new \DomainException(
                    'Daňové zvýhodnění na dítě nemá úplné doložené údaje.',
                );
            }
            $childClaims[] = [
                'child_reference' => $reference,
                'child_order' => $order,
                'ztp_p' => $claim['ztp_p'],
            ];
        }
        if ($residenceStatus === TaxResidence::NonResident
            && (array_filter(
                $creditClaims,
                static fn (array $claim): bool =>
                    $claim['credit_kind'] !== TaxCreditKind::Taxpayer->value,
            ) !== [] || $childClaims !== [])
        ) {
            throw new \DomainException(
                'Daňový nerezident nemůže v měsíčním potvrzení uplatnit invaliditu, ZTP/P ani dítě.',
            );
        }

        return [
            'declaration' => $declaration,
            'residence' => [
                'status' => $residenceStatus->value,
                'country_code' => $countryCode,
            ],
            'credit_claims' => $creditClaims,
            'child_claims' => $childClaims,
        ];
    }

    /** @param array<string,mixed> $person */
    private function nonresidentInsurance(array $person): int
    {
        $statutory = $this->object($person['statutory'] ?? null, 'statutory');
        $social = $this->object(
            $statutory['social_insurance'] ?? null,
            'statutory.social_insurance',
        );
        $health = $this->object(
            $statutory['health_insurance'] ?? null,
            'statutory.health_insurance',
        );

        return $this->add(
            $this->nonNegativeInt($social, 'employee_contribution_minor_units'),
            $this->nonNegativeInt($health, 'employee_contribution_minor_units'),
        );
    }

    /**
     * @param array<string,array<int,bool>> $creditMonths
     * @return list<array{period:string,degree:string}>
     */
    private static function disabilityTaxCredits(array $creditMonths): array
    {
        $rows = [];
        foreach ([
            TaxCreditKind::DisabilityBasic->value => 'I. nebo II. stupeň',
            TaxCreditKind::DisabilityExtended->value => 'III. stupeň',
            TaxCreditKind::ZtpP->value => 'průkaz ZTP/P',
        ] as $kind => $degree) {
            $months = array_map('intval', array_keys($creditMonths[$kind] ?? []));
            sort($months, SORT_NUMERIC);
            if ($months !== []) {
                $rows[] = [
                    'period' => self::monthRanges($months),
                    'degree' => $degree,
                ];
            }
        }

        return $rows;
    }

    /** @param list<int> $months */
    private static function monthRanges(array $months): string
    {
        if ($months === []) {
            return '';
        }
        $ranges = [];
        $start = $months[0];
        $previous = $start;
        foreach (array_slice($months, 1) as $month) {
            if ($month === $previous + 1) {
                $previous = $month;
                continue;
            }
            $ranges[] = $start === $previous
                ? (string) $start
                : "{$start}–{$previous}";
            $start = $month;
            $previous = $month;
        }
        $ranges[] = $start === $previous
            ? (string) $start
            : "{$start}–{$previous}";

        return implode(', ', $ranges);
    }

    /** @param array<string,mixed> $inputPerson */
    private function assertNoBackpay(array $inputPerson): void
    {
        foreach ($this->list(
            $inputPerson['employments'] ?? null,
            'input.employments',
        ) as $employment) {
            $employment = $this->object($employment, 'input.employments[]');
            foreach ($this->list(
                $employment['inputs'] ?? null,
                'input.employments[].inputs',
            ) as $input) {
                $input = $this->object($input, 'input');
                $component = $this->object(
                    $input['component'] ?? null,
                    'input.component',
                );
                $amount = $this->int($input, 'amount_minor');
                $componentKind = $component['kind'] ?? null;
                if ($amount !== 0) {
                    if ($componentKind === 'backpay') {
                        throw new \DomainException(
                            'Doplatek mzdy nelze bez rozlišení původního roku '
                            . 'správně rozdělit do řádků formuláře.',
                        );
                    }
                    if (in_array($componentKind, [
                        'benefit_pension',
                        'benefit_care',
                        'risky_savings',
                    ], true)) {
                        throw new \DomainException(
                            'Příspěvek zaměstnavatele na podporovaný produkt '
                            . 'vyžaduje samostatné mapování řádku 10 formuláře.',
                        );
                    }
                }
            }
        }
    }

    /**
     * @param array{status:string,country_code:string} $taxResidence
     * @return array{
     *   name:string,
     *   first_name:string,
     *   last_name:string,
     *   previous_names:list<string>,
     *   identifier_label:string,
     *   identifier_value:string,
     *   address:string
     * }
     */
    private function profileSnapshot(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        array $taxResidence,
    ): array {
        $from = sprintf('%04d-01-01', $taxYear);
        $to = sprintf('%04d-12-31', $taxYear);
        $identities = $this->db->pdo()->prepare(
            'SELECT id, full_name, first_name, last_name, birth_surname,
                    effective_from, effective_to
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from, id
              FOR UPDATE',
        );
        $identities->execute([$supplierId, $employeeId, $to, $from]);
        $fetchedIdentities = $identities->fetchAll(PDO::FETCH_ASSOC);
        if ($fetchedIdentities === []) {
            throw new \DomainException(
                'Pro daňové potvrzení chybí účinná historie jména.',
            );
        }
        $identityRows = array_map(
            $this->associativeRow(...),
            $fetchedIdentities,
        );
        $currentIdentity = $identityRows[array_key_last($identityRows)];
        $currentName = $this->text($currentIdentity, 'full_name');
        $firstName = $this->text($currentIdentity, 'first_name');
        $lastName = $this->text($currentIdentity, 'last_name');
        $names = [];
        foreach ($identityRows as $identity) {
            foreach ([
                $identity['full_name'] ?? null,
                $identity['birth_surname'] ?? null,
            ] as $name) {
                if (is_string($name)
                    && trim($name) !== ''
                    && trim($name) !== $currentName
                ) {
                    $names[trim($name)] = true;
                }
            }
        }

        $address = $this->db->pdo()->prepare(
            'SELECT id, street_line, city, postal_code, country_code
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ?
                AND address_type = "residence"
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1
              FOR UPDATE',
        );
        $address->execute([$supplierId, $employeeId, $to, $from]);
        $fetchedAddress = $address->fetch(PDO::FETCH_ASSOC);
        if ($fetchedAddress === false) {
            throw new \DomainException(
                'Pro daňové potvrzení chybí účinná adresa bydliště.',
            );
        }
        $addressRow = $this->associativeRow($fetchedAddress);
        $addressCountry = $addressRow['country_code'] ?? null;
        if (!is_string($addressCountry)
            || preg_match('/^[A-Z]{2}$/D', $addressCountry) !== 1
        ) {
            throw new \DomainException(
                'Adresa daňového potvrzení nemá platný kód země.',
            );
        }
        $identifier = $this->db->pdo()->prepare(
            'SELECT id, value_ciphertext
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?
                AND identifier_type = "birth_number"
              LIMIT 1
              FOR UPDATE',
        );
        $identifier->execute([$supplierId, $employeeId]);
        $fetchedIdentifier = $identifier->fetch(PDO::FETCH_ASSOC);
        if ($fetchedIdentifier === false
            && $taxResidence['status'] === TaxResidence::CzechResident->value
        ) {
            throw new \DomainException(
                'Pro daňové potvrzení českého rezidenta chybí rodné číslo.',
            );
        }
        $identifierLabel = 'Rodné číslo';
        if ($fetchedIdentifier !== false) {
            $identifierRow = $this->associativeRow($fetchedIdentifier);
            $identifierValue = $this->sensitiveData->reveal(
                $this->text($identifierRow, 'value_ciphertext'),
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
                $this->positiveInt($identifierRow, 'id'),
                PayrollRevealPurpose::DOCUMENT_ANNUAL_TAX_CERTIFICATE,
            );
        } else {
            $birth = $this->db->pdo()->prepare(
                'SELECT birth_date FROM payroll_employees
                  WHERE supplier_id = ? AND id = ?
                  FOR UPDATE',
            );
            $birth->execute([$supplierId, $employeeId]);
            $birthDate = $birth->fetchColumn();
            if (!is_string($birthDate)
                || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $birthDate) !== 1
            ) {
                throw new \DomainException(
                    'Pro daňové potvrzení nerezidenta chybí rodné číslo i datum narození.',
                );
            }
            $identifierLabel = 'Datum narození';
            $identifierValue = self::displayDate($birthDate);
        }

        return [
            'name' => $currentName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'previous_names' => array_keys($names),
            'identifier_label' => $identifierLabel,
            'identifier_value' => $identifierValue,
            'address' => implode(', ', [
                $this->text($addressRow, 'street_line'),
                trim(
                    $this->text($addressRow, 'postal_code')
                    . ' '
                    . $this->text($addressRow, 'city'),
                ),
                $addressCountry,
            ]),
        ];
    }

    /**
     * @param array<string,array<int,array{order:int,ztp_p:bool}>> $claims
     * @return list<array<string,string>>
     */
    private function childTaxBenefits(
        int $supplierId,
        int $employeeId,
        array $claims,
    ): array {
        ksort($claims);
        $rows = [];
        foreach ($claims as $reference => $months) {
            if (preg_match('/^dependant-([1-9][0-9]*)$/D', $reference, $match) !== 1) {
                throw new \DomainException(
                    'Odkaz dítěte v daňovém potvrzení není platný.',
                );
            }
            $dependantId = (int) $match[1];
            $statement = $this->db->pdo()->prepare(
                'SELECT id, full_name, birth_date, birth_number_ciphertext
                   FROM payroll_dependants
                  WHERE supplier_id = ? AND employee_id = ? AND id = ?
                  FOR UPDATE',
            );
            $statement->execute([$supplierId, $employeeId, $dependantId]);
            $fetched = $statement->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) {
                throw new \DomainException(
                    'Pro řádek 11 chybí identita uplatněného dítěte.',
                );
            }
            $dependant = $this->associativeRow($fetched);
            $identifier = $dependant['birth_number_ciphertext'] === null
                ? self::displayDate($this->text($dependant, 'birth_date'))
                : $this->sensitiveData->reveal(
                    $this->text($dependant, 'birth_number_ciphertext'),
                    PayrollSensitiveField::PERSONAL_IDENTIFIER,
                    $supplierId,
                    $dependantId,
                    PayrollRevealPurpose::DOCUMENT_ANNUAL_TAX_CERTIFICATE,
                );
            $orders = [1 => [], 2 => [], 3 => []];
            $ztpP = [];
            ksort($months, SORT_NUMERIC);
            foreach ($months as $month => $claim) {
                $bucket = min(3, $claim['order']);
                $orders[$bucket][] = (int) $month;
                if ($claim['ztp_p']) {
                    $ztpP[] = (int) $month;
                }
            }
            $rows[] = [
                'name' => $this->text($dependant, 'full_name'),
                'identifier' => $identifier,
                'ztpp_period' => self::monthRanges($ztpP),
                'first_child_period' => self::monthRanges($orders[1]),
                'second_child_period' => self::monthRanges($orders[2]),
                'third_child_period' => self::monthRanges($orders[3]),
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *   value:array{performed:bool,result:?array<string,mixed>},
     *   source:?array{revision_id:int,snapshot_hash:string,performed:bool}
     * }
     */
    private function annualSettlementResult(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        PayrollDocumentKind $kind,
    ): array {
        if ($kind !== PayrollDocumentKind::TaxableIncomeAdvanceCertificate) {
            return [
                'value' => ['performed' => false, 'result' => null],
                'source' => null,
            ];
        }
        $revision = $this->annualRevisions->latest(
            $supplierId,
            $employeeId,
            $taxYear,
            AnnualSettlementSnapshotBuilder::PURPOSE,
        );
        if ($revision === null) {
            return [
                'value' => ['performed' => false, 'result' => null],
                'source' => null,
            ];
        }
        $json = $this->encryption->decryptFor(
            $this->text($revision, 'snapshot_ciphertext'),
            implode(':', [
                'payroll-annual-document',
                (string) $supplierId,
                (string) $employeeId,
                (string) $taxYear,
                AnnualSettlementSnapshotBuilder::PURPOSE,
                $this->hash($revision, 'source_manifest_hash'),
            ]),
        );
        $snapshotHash = $this->hash($revision, 'snapshot_hash');
        if (!hash_equals(
            $snapshotHash,
            $this->sensitiveData->keyedFingerprint(
                $json,
                AnnualSettlementSnapshotBuilder::SNAPSHOT_FINGERPRINT_DOMAIN,
                $supplierId,
            ),
        )) {
            throw new \DomainException(
                'Otisk revize ročního zúčtování nesouhlasí s jejím obsahem.',
            );
        }
        $snapshot = $this->decodeObject($json, 'Roční zúčtování');
        if (($snapshot['schema_version'] ?? null)
            !== AnnualSettlementSnapshotBuilder::SCHEMA_VERSION
        ) {
            throw new \DomainException(
                'Revize ročního zúčtování má nepodporované schéma.',
            );
        }
        $result = $this->object($snapshot['result'] ?? null, 'result');
        $performed = $result['performed'] ?? null;
        if ($performed === false) {
            $blockers = $this->list($result['blockers'] ?? null, 'result.blockers');
            if ($blockers === []) {
                throw new \DomainException(
                    'Neprovedené roční zúčtování nemá doložený důvod.',
                );
            }
            foreach ($blockers as $blocker) {
                if (!is_string($blocker) || trim($blocker) === '') {
                    throw new \DomainException(
                        'Důvod neprovedeného ročního zúčtování není platný.',
                    );
                }
            }

            return [
                'value' => ['performed' => false, 'result' => null],
                'source' => [
                    'revision_id' => $this->positiveInt($revision, 'id'),
                    'snapshot_hash' => $snapshotHash,
                    'performed' => false,
                ],
            ];
        }
        if ($performed !== true) {
            throw new \DomainException(
                'Schválená revize nemá platný stav ročního zúčtování.',
            );
        }
        $trace = $this->object($result['trace'] ?? null, 'result.trace');
        $taxBeforeCredits = $this->nonNegativeInt(
            $result,
            'tax_before_credits_minor_units',
        );
        $appliedCredits = $this->nonNegativeInt(
            $result,
            'applied_credits_minor_units',
        );
        $childCredit = $this->nonNegativeInt(
            $result,
            'child_credit_minor_units',
        );
        $taxAfterAllCredits = $this->nonNegativeInt(
            $result,
            'tax_after_all_credits_minor_units',
        );
        if ($appliedCredits > $taxBeforeCredits
            || $childCredit > $taxBeforeCredits - $appliedCredits
            || $taxAfterAllCredits
                !== $taxBeforeCredits - $appliedCredits - $childCredit
        ) {
            throw new \DomainException(
                'Roční zúčtování nemá průkazný rozklad daně a slev.',
            );
        }
        $totalAdvanceTax = $this->nonNegativeInt(
            $trace,
            'total_advance_tax_minor_units',
        );
        $taxDifference = $this->int($result, 'tax_difference_minor_units');
        $bonusDifference = $this->int($result, 'bonus_difference_minor_units');
        if ($taxDifference !== $totalAdvanceTax - $taxAfterAllCredits) {
            throw new \DomainException(
                'Přeplatek na dani neodpovídá doloženým zálohám a slevám.',
            );
        }
        $settlementDifference = $this->int(
            $result,
            'settlement_difference_minor_units',
        );
        $payable = $this->nonNegativeInt($result, 'payable_minor_units');
        $payoutThreshold = $this->nonNegativeInt(
            $trace,
            'payout_threshold_minor_units',
        );
        if ($settlementDifference !== $taxDifference + $bonusDifference
            || $payable !== ($settlementDifference > $payoutThreshold
                ? $settlementDifference
                : 0)
        ) {
            throw new \DomainException(
                'Doplatek ročního zúčtování neodpovídá daňové a bonusové části.',
            );
        }

        return [
            'value' => [
                'performed' => true,
                'result' => [
                    'tax_overpayment_minor_units' => max(
                        0,
                        $totalAdvanceTax
                            - ($taxBeforeCredits - $appliedCredits),
                    ),
                    'settlement_supplement_minor_units' =>
                        $payable,
                    'tax_overpayment_after_credit_minor_units' =>
                        max(0, $taxDifference),
                    'tax_bonus_difference_minor_units' => $bonusDifference,
                    'taxpayer_product_deductions_minor_units' => [
                        'pension_supplementary' => 0,
                        'supplementary_pension' => 0,
                        'pension_insurance' => 0,
                        'private_life_insurance' => 0,
                        'long_term_investment_product' => 0,
                    ],
                ],
            ],
            'source' => [
                'revision_id' => $this->positiveInt($revision, 'id'),
                'snapshot_hash' => $snapshotHash,
                'performed' => true,
            ],
        ];
    }

    /** @param array<string,mixed> $root
     *  @return array<string,mixed>
     */
    private function personFromResult(array $root, int $employeeId): array
    {
        $match = null;
        foreach ($this->list($root['people'] ?? null, 'result.people') as $row) {
            $person = $this->object($row, 'result.people[]');
            if ($this->positiveInt($person, 'employee_id') === $employeeId) {
                if ($match !== null) {
                    throw new \DomainException(
                        'Schválená revize obsahuje zaměstnance vícekrát.',
                    );
                }
                $match = $person;
            }
        }
        if ($match === null) {
            throw new \DomainException(
                'Schválená revize neobsahuje zaměstnance.',
            );
        }

        return $match;
    }

    /** @param array<string,mixed> $root
     *  @return array<string,mixed>
     */
    private function personFromInput(array $root, int $employeeId): array
    {
        $match = null;
        foreach ($this->list($root['people'] ?? null, 'input.people') as $row) {
            $person = $this->object($row, 'input.people[]');
            $employee = $this->object(
                $person['employee'] ?? null,
                'input.people[].employee',
            );
            if ($this->positiveInt($employee, 'id') === $employeeId) {
                if ($match !== null) {
                    throw new \DomainException(
                        'Vstupní revize obsahuje zaměstnance vícekrát.',
                    );
                }
                $match = $person;
            }
        }
        if ($match === null) {
            throw new \DomainException(
                'Vstupní revize neobsahuje zaměstnance.',
            );
        }

        return $match;
    }

    /**
     * @param array<string,mixed> $revision
     * @return array<string,mixed>
     */
    private function decryptSnapshot(
        array $revision,
        PayrollDocumentKind $kind,
    ): array {
        $json = $this->encryption->decryptFor(
            $this->text($revision, 'snapshot_ciphertext'),
            $this->encryptionContext(
                $this->positiveInt($revision, 'supplier_id'),
                $this->positiveInt($revision, 'employee_id'),
                $this->positiveInt($revision, 'tax_year'),
                $kind,
                $this->hash($revision, 'source_manifest_hash'),
            ),
        );
        $hash = $this->hash($revision, 'snapshot_hash');
        $expected = $this->sensitiveData->keyedFingerprint(
            $json,
            'annual-tax-certificate-snapshot-v1',
            $this->positiveInt($revision, 'supplier_id'),
        );
        if (!hash_equals($hash, $expected)) {
            throw new \DomainException(
                'Otisk ročního daňového snapshotu nesouhlasí.',
            );
        }

        return $this->decodeObject($json, 'Roční daňový snapshot');
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function hydrate(
        array $snapshot,
        string $snapshotHash,
        PayrollDocumentKind $kind,
    ): AnnualTaxCertificateDocumentData {
        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($snapshot['mapping_version'] ?? null) !== self::MAPPING_VERSION
            || ($snapshot['purpose'] ?? null) !== $kind->value
        ) {
            throw new \DomainException(
                'Roční daňový snapshot má nepodporované schéma nebo účel.',
            );
        }
        $taxYear = $this->positiveInt($snapshot, 'tax_year');
        $expectedForm = self::formSnapshot(
            AnnualTaxCertificateFormCatalog::resolve($taxYear, $kind),
        );
        $storedForm = $this->object($snapshot['form'] ?? null, 'form');
        if (!hash_equals(
            CanonicalJson::encode($storedForm),
            CanonicalJson::encode($expectedForm),
        )) {
            throw new \DomainException(
                'Roční daňový snapshot neodpovídá katalogu formulářů.',
            );
        }
        $employer = $this->object($snapshot['employer'] ?? null, 'employer');
        $employerAddress = $this->object(
            $employer['address'] ?? null,
            'employer.address',
        );
        $issuer = $this->object(
            $employer['issuer'] ?? null,
            'employer.issuer',
        );
        $employee = $this->object($snapshot['employee'] ?? null, 'employee');
        $previousNames = $this->list(
            $employee['previous_names'] ?? null,
            'employee.previous_names',
        );
        foreach ($previousNames as $name) {
            if (!is_string($name)) {
                throw new \DomainException(
                    'Dřívější jméno v daňovém snapshotu není text.',
                );
            }
        }
        $months = $this->list($snapshot['months'] ?? null, 'months');
        foreach ($months as $month) {
            if (!is_int($month)) {
                throw new \DomainException(
                    'Měsíc v daňovém snapshotu není celé číslo.',
                );
            }
        }
        $taxResidence = $this->object(
            $snapshot['tax_residence'] ?? null,
            'tax_residence',
        );
        $taxDeclaration = $snapshot['tax_declaration'] ?? null;
        $taxDeclarationStatus = null;
        $taxDeclarationSignedMonths = [];
        if ($kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate) {
            $taxDeclaration = $this->object(
                $taxDeclaration,
                'tax_declaration',
            );
            $taxDeclarationStatus = $this->text(
                $taxDeclaration,
                'status',
            );
            $taxDeclarationSignedMonths = $this->list(
                $taxDeclaration['signed_months'] ?? null,
                'tax_declaration.signed_months',
            );
            foreach ($taxDeclarationSignedMonths as $month) {
                if (!is_int($month)) {
                    throw new \DomainException(
                        'Podepsaný měsíc Prohlášení není celé číslo.',
                    );
                }
            }
        } elseif ($taxDeclaration !== null) {
            throw new \DomainException(
                'Srážkový snapshot nesmí obsahovat Prohlášení poplatníka.',
            );
        }

        return new AnnualTaxCertificateDocumentData(
            sourceSnapshotSha256: $snapshotHash,
            kind: $kind,
            taxYear: $taxYear,
            form: AnnualTaxCertificateFormCatalog::resolve($taxYear, $kind),
            employer: new PayrollDocumentEmployerSnapshot(
                name: $this->text($employer, 'name'),
                identificationNumber:
                    $this->text($employer, 'identification_number'),
                taxIdentificationNumber:
                    $this->text($employer, 'tax_identification_number'),
                streetLine: $this->text($employerAddress, 'street_line'),
                city: $this->text($employerAddress, 'city'),
                postalCode: $this->text($employerAddress, 'postal_code'),
                countryCode: $this->text($employerAddress, 'country_code'),
                countryName: $this->text($employerAddress, 'country_name'),
                issuerName: $this->text($issuer, 'name'),
                issuerEmail: $this->text($issuer, 'email'),
                issuerPhone: $this->text($issuer, 'phone'),
            ),
            employeeName: $this->text($employee, 'name'),
            employeeFirstName: $this->text($employee, 'first_name'),
            employeeLastName: $this->text($employee, 'last_name'),
            previousNames: $previousNames,
            personalIdentifierLabel:
                $this->text($employee, 'identifier_label'),
            personalIdentifierValue:
                $this->text($employee, 'identifier_value'),
            employeeAddress: $this->text($employee, 'address'),
            months: $months,
            taxDeclarationStatus: $taxDeclarationStatus,
            taxDeclarationSignedMonths: $taxDeclarationSignedMonths,
            taxResidenceStatus: $this->text($taxResidence, 'status'),
            taxResidenceCountryCode:
                $this->text($taxResidence, 'country_code'),
            issuedAt: $this->timestamp($snapshot, 'issued_at'),
            replacesIssuedAt:
                $this->nullableTimestamp($snapshot, 'replaces_issued_at'),
            correctionReason:
                $this->nullableText($snapshot, 'correction_reason'),
            employerProductContributionsMinorUnits:
                $this->productContributions($snapshot),
            childTaxBenefits:
                $this->objectList(
                    $snapshot['child_tax_benefits'] ?? null,
                    'child_tax_benefits',
                ),
            disabilityTaxCredits:
                $this->objectList(
                    $snapshot['disability_tax_credits'] ?? null,
                    'disability_tax_credits',
                ),
            annualSettlement:
                $this->annualSettlement($snapshot),
            nonresidentInsuranceMinorUnits:
                $this->nullableNonNegativeInt(
                    $snapshot,
                    'nonresident_insurance_minor_units',
                ),
            accruedIncomeMinorUnits:
                $this->nonNegativeInt(
                    $snapshot,
                    'accrued_income_minor_units',
                ),
            paidIncomeMinorUnits:
                $this->nonNegativeInt($snapshot, 'paid_income_minor_units'),
            advanceTaxMinorUnits:
                $this->nonNegativeInt($snapshot, 'advance_tax_minor_units'),
            withholdingTaxMinorUnits:
                $this->nonNegativeInt(
                    $snapshot,
                    'withholding_tax_minor_units',
                ),
            taxBonusMinorUnits:
                $this->nonNegativeInt($snapshot, 'tax_bonus_minor_units'),
            paymentEvidenceCutoff:
                $this->text($snapshot, 'payment_evidence_cutoff'),
            lastProvenPaymentDate:
                $this->text($snapshot, 'last_proven_payment_date'),
        );
    }

    private function encryptionContext(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        PayrollDocumentKind $kind,
        string $manifestHash,
    ): string {
        return implode(':', [
            'payroll-annual-document',
            (string) $supplierId,
            (string) $employeeId,
            (string) $taxYear,
            $kind->value,
            $manifestHash,
        ]);
    }

    /** @param array<string,mixed>|null $document */
    private function isCompatibleDocument(
        ?array $document,
        int $employeeId,
        int $taxYear,
        PayrollDocumentKind $kind,
    ): bool {
        if ($document === null
            || ($document['employee_id'] ?? null) !== $employeeId
            || ($document['document_kind'] ?? null) !== $kind->value
            || !is_int($document['supplier_id'] ?? null)
            || !is_int($document['annual_revision_id'] ?? null)
        ) {
            return false;
        }
        $annual = $this->documents->approvedAnnualRevision(
            $document['supplier_id'],
            $document['annual_revision_id'],
        );

        return $annual !== null
            && ($annual['tax_year'] ?? null) === $taxYear
            && ($annual['purpose'] ?? null) === $kind->value;
    }

    /** @param array<string,mixed> $document */
    private function documentIssuedAt(array $document): string
    {
        $createdAt = $this->text($document, 'created_at');
        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/D',
            $createdAt,
            $matches,
        ) !== 1) {
            throw new \DomainException(
                'Datum vydání nahrazovaného daňového potvrzení není platné.',
            );
        }
        $timestamp = $matches[1] . ' ' . $matches[2];
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $timestamp,
        );
        if ($date === false
            || $date->format('Y-m-d H:i:s') !== $timestamp
        ) {
            throw new \DomainException(
                'Datum vydání nahrazovaného daňového potvrzení není platné.',
            );
        }

        return $timestamp;
    }

    private function databaseTimestamp(): string
    {
        $statement = $this->db->pdo()->query(
            'SELECT DATE_FORMAT(CURRENT_TIMESTAMP, "%Y-%m-%d %H:%i:%s")',
        );
        if ($statement === false) {
            throw new \RuntimeException(
                'Databáze neposkytla datum vydání daňového potvrzení.',
            );
        }
        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            throw new \RuntimeException(
                'Databáze neposkytla datum vydání daňového potvrzení.',
            );
        }

        return self::validatedTimestamp($value, 'issued_at');
    }

    /** @param array<string,mixed> $source */
    private function timestamp(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            throw new \DomainException(
                "Pole {$key} daňového snapshotu není platné datum a čas.",
            );
        }

        return self::validatedTimestamp($value, $key);
    }

    /** @param array<string,mixed> $source */
    private function nullableTimestamp(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \DomainException(
                "Pole {$key} daňového snapshotu není platné datum a čas.",
            );
        }

        return self::validatedTimestamp($value, $key);
    }

    private static function validatedTimestamp(string $value, string $key): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new \DomainException(
                "Pole {$key} daňového snapshotu není platné datum a čas.",
            );
        }

        return $value;
    }

    private static function displayDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \DomainException(
                'Datum osobního identifikátoru není platné.',
            );
        }

        return sprintf(
            '%d. %d. %s',
            (int) $date->format('d'),
            (int) $date->format('m'),
            $date->format('Y'),
        );
    }

    /** @param array<string,mixed> $source */
    private function nullableText(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || trim($value) === ''
            || trim($value) !== $value
        ) {
            throw new \DomainException(
                "Pole {$key} daňového snapshotu není platný text.",
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{
     *   supplementary_pension:int,
     *   pension_insurance:int,
     *   private_life_insurance:int,
     *   long_term_investment_product:int
     * }
     */
    private function productContributions(array $snapshot): array
    {
        $products = $this->object(
            $snapshot['employer_product_contributions_minor_units'] ?? null,
            'employer_product_contributions_minor_units',
        );

        return [
            'supplementary_pension' =>
                $this->nonNegativeInt($products, 'supplementary_pension'),
            'pension_insurance' =>
                $this->nonNegativeInt($products, 'pension_insurance'),
            'private_life_insurance' =>
                $this->nonNegativeInt($products, 'private_life_insurance'),
            'long_term_investment_product' =>
                $this->nonNegativeInt(
                    $products,
                    'long_term_investment_product',
                ),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{performed:bool,result:?array<string,mixed>}
     */
    private function annualSettlement(array $snapshot): array
    {
        $settlement = $this->object(
            $snapshot['annual_settlement'] ?? null,
            'annual_settlement',
        );
        $performed = $settlement['performed'] ?? null;
        $result = $settlement['result'] ?? null;
        if (!is_bool($performed)
            || !array_key_exists('result', $settlement)
            || (($performed === false && $result !== null)
                || ($performed === true
                    && (!is_array($result) || array_is_list($result))))
        ) {
            throw new \DomainException(
                'Roční zúčtování v daňovém snapshotu nemá platnou strukturu.',
            );
        }

        return [
            'performed' => $performed,
            'result' => $performed
                ? $this->object($result, 'annual_settlement.result')
                : null,
        ];
    }

    /** @param array<string,mixed> $source */
    private function nullableNonNegativeInt(
        array $source,
        string $key,
    ): ?int {
        if (($source[$key] ?? null) === null) {
            return null;
        }

        return $this->nonNegativeInt($source, $key);
    }

    private static function correctionReason(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === ''
            || mb_strlen($value) > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1
        ) {
            throw new \DomainException(
                'Důvod opravného potvrzení není platný.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(
        array $value,
        string $purpose,
        int $supplierId,
    ): string {
        return $this->sensitiveData->keyedFingerprint(
            CanonicalJson::encode($value),
            $purpose,
            $supplierId,
        );
    }

    /**
     * @param array{
     *   tax_year:int,
     *   document_kind:PayrollDocumentKind,
     *   form_number:string,
     *   ministry_form:string,
     *   pattern_number:int,
     *   valid_from:string,
     *   valid_to:string,
     *   official_url:string
     * } $form
     * @return array{
     *   tax_year:int,
     *   document_kind:string,
     *   form_number:string,
     *   ministry_form:string,
     *   pattern_number:int,
     *   valid_from:string,
     *   valid_to:string,
     *   official_url:string
     * }
     */
    private static function formSnapshot(array $form): array
    {
        $snapshot = $form;
        $snapshot['document_kind'] = $form['document_kind']->value;

        return $snapshot;
    }

    private function assertJsonHash(
        string $json,
        string $hash,
        string $context,
    ): void {
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

        return trim($value);
    }

    /** @param array<string,mixed> $row */
    private function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if ((is_int($value) || (is_string($value)
                && preg_match('/^-?\d+$/D', $value) === 1))
        ) {
            return (int) $value;
        }
        throw new \DomainException("Pole {$field} není celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private function positiveInt(array $row, string $field): int
    {
        $value = $this->int($row, $field);
        if ($value <= 0) {
            throw new \DomainException(
                "Pole {$field} není kladné celé číslo.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nonNegativeInt(array $row, string $field): int
    {
        $value = $this->int($row, $field);
        if ($value < 0) {
            throw new \DomainException(
                "Pole {$field} není nezáporné celé číslo.",
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$context} není objekt.");
        }

        return $this->associativeRow($value);
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("{$context} není seznam.");
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function objectList(mixed $value, string $context): array
    {
        $result = [];
        foreach ($this->list($value, $context) as $index => $row) {
            $result[] = $this->object($row, "{$context}.{$index}");
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function associativeRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný řádek daňového potvrzení.',
            );
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Objekt daňového potvrzení má neplatný klíč.',
                );
            }
            $row[$key] = $item;
        }

        return $row;
    }

    private function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new \OverflowException(
                'Roční součet daňového potvrzení přetekl.',
            );
        }

        return $left + $right;
    }
}
