<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualDocumentRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementResult;
use MyInvoice\Service\Payroll\Document\AnnualSettlementSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificatePdfRenderer;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateService;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class AnnualTaxCertificateSnapshotBuilderIntegrationTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testStorageCleanupLockSerializesAnotherSupplierWriter(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $config = $container->get(Config::class);
        $service = $container->get(AnnualTaxCertificateService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(Config::class, $config);
        self::assertInstanceOf(AnnualTaxCertificateService::class, $service);
        $second = Connection::withoutSharedTestConnection(
            static fn (): Connection => new Connection($config),
        );
        $name = 'payroll-document-storage:supplier:987654';
        $acquire = new \ReflectionMethod($service, 'acquireStorageLock');
        $release = new \ReflectionMethod($service, 'releaseStorageLock');
        $acquire->invoke($service, $connection->pdo(), $name);
        try {
            $probe = $second->pdo()->prepare('SELECT GET_LOCK(?, 0)');
            $probe->execute([$name]);
            self::assertSame(
                0,
                (int) $probe->fetchColumn(),
                'Jiná transakce nesmí začít referencovat soubor před úklidem.',
            );
        } finally {
            $release->invoke($service, $connection->pdo(), $name);
        }
        $probe = $second->pdo()->prepare('SELECT GET_LOCK(?, 0)');
        $probe->execute([$name]);
        self::assertSame(1, (int) $probe->fetchColumn());
        $unlock = $second->pdo()->prepare('SELECT RELEASE_LOCK(?)');
        $unlock->execute([$name]);
        $second->close();
        $connection->close();
    }

    public function testBuildsAndArchivesBothImmutable2026Certificates(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $builder = $container->get(AnnualTaxCertificateSnapshotBuilder::class);
        $renderer = $container->get(AnnualTaxCertificatePdfRenderer::class);
        $service = $container->get(AnnualTaxCertificateService::class);
        $documents = $container->get(PayrollDocumentService::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(
            AnnualTaxCertificateSnapshotBuilder::class,
            $builder,
        );
        self::assertInstanceOf(AnnualTaxCertificatePdfRenderer::class, $renderer);
        self::assertInstanceOf(AnnualTaxCertificateService::class, $service);
        self::assertInstanceOf(PayrollDocumentService::class, $documents);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        if (!$connection->hasTable('payroll_payment_matches')) {
            $this->markTestSkipped('Migrace platební evidence mezd neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $scope = $documents->beginStorageScope();
        $supplierId = 0;
        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
            );
            $pdo->prepare(
                'UPDATE payroll_person_identity_history
                    SET first_name = NULL
                  WHERE supplier_id = ? AND employee_id = ?',
            )->execute([$supplierId, $employeeId]);
            try {
                $builder->build(
                    $supplierId,
                    $employeeId,
                    2026,
                    PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                    null,
                );
                self::fail(
                    'Potvrzení bez strukturovaného jména musí být odmítnuto.',
                );
            } catch (\DomainException $exception) {
                self::assertStringContainsString(
                    'first_name',
                    $exception->getMessage(),
                );
            }
            $pdo->prepare(
                'UPDATE payroll_person_identity_history
                    SET first_name = "Syntetická"
                  WHERE supplier_id = ? AND employee_id = ?',
            )->execute([$supplierId, $employeeId]);
            $annualIds = [];
            $archivedByKind = [];
            foreach ([
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
            ] as $kind) {
                $prepared = $builder->build(
                    $supplierId,
                    $employeeId,
                    2026,
                    $kind,
                    null,
                );
                $replayed = $builder->build(
                    $supplierId,
                    $employeeId,
                    2026,
                    $kind,
                    null,
                );
                self::assertSame(
                    $prepared['revision']['id'],
                    $replayed['revision']['id'],
                );
                self::assertSame(
                    $prepared['document']->sourceSnapshotSha256,
                    $replayed['document']->sourceSnapshotSha256,
                );
                self::assertSame(
                    'czech-resident',
                    $prepared['document']->taxResidenceStatus,
                );
                self::assertSame(
                    'CZ',
                    $prepared['document']->taxResidenceCountryCode,
                );
                if ($kind
                    === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                ) {
                    self::assertSame(
                        'signed',
                        $prepared['document']->taxDeclarationStatus,
                    );
                    self::assertSame(
                        [1],
                        $prepared['document']->taxDeclarationSignedMonths,
                    );
                }

                $artifact = $renderer->render($prepared['document']);
                $document = $documents->archiveAnnualPdf(
                    $supplierId,
                    (int) $prepared['revision']['id'],
                    $employeeId,
                    $artifact,
                    'synthetic-tax-certificate-' . $kind->value,
                    null,
                    $scope,
                    $prepared['document']->issuedAt,
                );
                $replayedDocument = $documents->archiveAnnualPdf(
                    $supplierId,
                    (int) $prepared['revision']['id'],
                    $employeeId,
                    $artifact,
                    'synthetic-tax-certificate-' . $kind->value,
                    null,
                    $scope,
                    $prepared['document']->issuedAt,
                );
                self::assertSame($document['id'], $replayedDocument['id']);
                $archivedByKind[$kind->value] = $document;
                self::assertSame($kind->value, $document['document_kind']);
                self::assertNull($document['run_id']);
                self::assertNull($document['revision_id']);
                self::assertSame(
                    $prepared['revision']['id'],
                    $document['annual_revision_id'],
                );
                self::assertStringStartsWith('%PDF-', $artifact->bytes);
                $annualIds[] = (int) $prepared['revision']['id'];
            }
            self::assertCount(2, array_unique($annualIds));

            $this->appendApprovedMonth(
                $pdo,
                $supplierId,
                $employeeId,
            );
            $previousAdvance = $archivedByKind[
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate->value
            ];
            try {
                $builder->build(
                    $supplierId,
                    $employeeId,
                    2026,
                    PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                    null,
                );
                self::fail('Opravné potvrzení bez důvodu musí být odmítnuto.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString(
                    'konkrétní důvod',
                    $exception->getMessage(),
                );
            }
            $replacement = $builder->build(
                $supplierId,
                $employeeId,
                2026,
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                null,
                (int) $previousAdvance['id'],
                'Doplnění dalšího doloženého měsíce příjmů.',
            );
            self::assertSame(
                (string) $previousAdvance['created_at'],
                $replacement['document']->replacesIssuedAt,
            );
            $replacementArtifact = $renderer->render(
                $replacement['document'],
            );
            $replacementDocument = $documents->archiveAnnualPdf(
                $supplierId,
                (int) $replacement['revision']['id'],
                $employeeId,
                $replacementArtifact,
                'synthetic-tax-certificate-correction',
                null,
                $scope,
                $replacement['document']->issuedAt,
            );
            self::assertSame(
                $previousAdvance['id'],
                $replacementDocument['supersedes_document_id'],
            );
            self::assertSame(
                $replacement['document']->issuedAt,
                $replacementDocument['created_at'],
            );
            $replacementText = (new \Smalot\PdfParser\Parser())
                ->parseContent($replacementArtifact->bytes)
                ->getText();
            self::assertStringContainsString(
                'Toto potvrzení nahrazuje potvrzení vydané dne',
                $replacementText,
            );
            self::assertStringContainsString(
                'Doplnění dalšího doloženého měsíce příjmů.',
                $replacementText,
            );
            self::assertStringNotContainsString(
                'První vydání – nenahrazuje dřívější potvrzení',
                $replacementText,
            );
            $replayedCorrection = $builder->build(
                $supplierId,
                $employeeId,
                2026,
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                null,
                (int) $previousAdvance['id'],
                'Doplnění dalšího doloženého měsíce příjmů.',
            );
            self::assertSame(
                $replacement['revision']['id'],
                $replayedCorrection['revision']['id'],
            );
            self::assertSame(
                $replacementDocument['id'],
                $replayedCorrection['archived_document']['id'] ?? null,
            );

            try {
                $pdo->prepare(
                    'UPDATE payroll_annual_document_revisions
                        SET snapshot_hash = ?
                      WHERE supplier_id = ? AND id = ?',
                )->execute([
                    str_repeat('f', 64),
                    $supplierId,
                    $annualIds[0],
                ]);
                self::fail('Roční daňový snapshot nesmí být měnitelný.');
            } catch (\PDOException $exception) {
                self::assertStringContainsString(
                    'immutable',
                    strtolower($exception->getMessage()),
                );
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($supplierId > 0) {
                $documents->cleanupStorageScope($supplierId, $scope);
            } else {
                $documents->commitStorageScope($scope);
            }
            $connection->close();
        }
    }

    public function testBuildsDocumentedChildrenDisabilityAndAnnualSettlement(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $builder = $container->get(AnnualTaxCertificateSnapshotBuilder::class);
        $settlementBuilder = $container->get(AnnualSettlementSnapshotBuilder::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
                'common-variants',
            );
            $result = AnnualSettlementResult::performed(
                2026,
                AnnualSettlementOutcome::Overpayment,
                10_000_000,
                1_500_000,
                500_000,
                500_000,
                220_000,
                200_000,
                20_000,
                800_000,
                450_000,
                20_000,
                470_000,
                470_000,
                true,
                [
                    'completed_months' => 1,
                    'advance_base_minor_units' => 10_000_000,
                    'advance_tax_minor_units' => 1_250_000,
                    'monthly_tax_bonus_minor_units' => 0,
                    'total_advance_tax_minor_units' => 1_250_000,
                    'payout_threshold_minor_units' => 5_000,
                ],
            );
            $settlementBuilder->build(
                $supplierId,
                $employeeId,
                2026,
                $result,
                '2027-03-15',
                [],
                [],
                null,
            );

            $prepared = $builder->build(
                $supplierId,
                $employeeId,
                2026,
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                null,
            );

            self::assertSame('Dítě Syntetické', $prepared['document']->childTaxBenefits[0]['name']);
            self::assertSame('1', $prepared['document']->childTaxBenefits[0]['first_child_period']);
            self::assertSame([
                ['period' => '1', 'degree' => 'III. stupeň'],
                ['period' => '1', 'degree' => 'průkaz ZTP/P'],
            ], $prepared['document']->disabilityTaxCredits);
            self::assertTrue($prepared['document']->annualSettlement['performed']);
            $settlement = $prepared['document']->annualSettlement['result'];
            self::assertSame(250_000, $settlement['tax_overpayment_minor_units']);
            self::assertSame(470_000, $settlement['settlement_supplement_minor_units']);
            self::assertSame(450_000, $settlement['tax_overpayment_after_credit_minor_units']);
            self::assertSame(20_000, $settlement['tax_bonus_difference_minor_units']);
        } finally {
            $pdo->rollBack();
            $connection->close();
        }
    }

    public function testBuildsNonresidentAdvanceCertificateFromFrozenInsurance(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $builder = $container->get(AnnualTaxCertificateSnapshotBuilder::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
                'nonresident',
            );

            $prepared = $builder->build(
                $supplierId,
                $employeeId,
                2026,
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                null,
            );

            self::assertSame('non-resident', $prepared['document']->taxResidenceStatus);
            self::assertSame('SK', $prepared['document']->taxResidenceCountryCode);
            self::assertSame('Datum narození', $prepared['document']->personalIdentifierLabel);
            self::assertSame('1. 1. 1990', $prepared['document']->personalIdentifierValue);
            self::assertSame(1_130_000, $prepared['document']->nonresidentInsuranceMinorUnits);
        } finally {
            $pdo->rollBack();
            $connection->close();
        }
    }

    public function testRefusedAnnualSettlementLeavesRowThirteenEmpty(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $builder = $container->get(AnnualTaxCertificateSnapshotBuilder::class);
        $annualRevisions = $container->get(PayrollAnnualDocumentRepository::class);
        $encryption = $container->get(SecretEncryption::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->fixture(
                $pdo,
                $sourceSupplierId,
                $sensitive,
            );
            $result = AnnualSettlementResult::refused(
                2026,
                [AnnualSettlementBlocker::NotRequested],
            );
            $snapshotJson = CanonicalJson::encode([
                'schema_version' => AnnualSettlementSnapshotBuilder::SCHEMA_VERSION,
                'tax_year' => 2026,
                'result' => $result->jsonSerialize(),
            ]);
            $manifestJson = CanonicalJson::encode([
                'schema_version' => 'synthetic-refused-settlement.v1',
                'result_hash' => hash('sha256', $snapshotJson),
            ]);
            $manifestHash = hash('sha256', $manifestJson);
            $snapshotHash = $sensitive->keyedFingerprint(
                $snapshotJson,
                AnnualSettlementSnapshotBuilder::SNAPSHOT_FINGERPRINT_DOMAIN,
                $supplierId,
            );
            $refused = $annualRevisions->insertApproved([
                'supplier_id' => $supplierId,
                'employee_id' => $employeeId,
                'tax_year' => 2026,
                'purpose' => AnnualSettlementSnapshotBuilder::PURPOSE,
                'revision_no' => 1,
                'previous_revision_id' => null,
                'snapshot_ciphertext' => $encryption->encryptFor(
                    $snapshotJson,
                    implode(':', [
                        'payroll-annual-document',
                        (string) $supplierId,
                        (string) $employeeId,
                        '2026',
                        AnnualSettlementSnapshotBuilder::PURPOSE,
                        $manifestHash,
                    ]),
                ),
                'snapshot_hash' => $snapshotHash,
                'source_manifest_json' => $manifestJson,
                'source_manifest_hash' => $manifestHash,
                'approved_by' => null,
            ], []);

            $prepared = $builder->build(
                $supplierId,
                $employeeId,
                2026,
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                null,
            );

            self::assertSame(
                ['performed' => false, 'result' => null],
                $prepared['document']->annualSettlement,
            );
            $manifest = json_decode(
                $prepared['revision']['source_manifest_json'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame(
                [
                    'performed' => false,
                    'revision_id' => $refused['id'],
                    'snapshot_hash' => $snapshotHash,
                ],
                $manifest['annual_settlement_source'],
            );
        } finally {
            $pdo->rollBack();
            $connection->close();
        }
    }

    /** @return array{int,int} */
    private function fixture(
        PDO $pdo,
        int $sourceSupplierId,
        PayrollSensitiveData $sensitive,
        string $variant = 'base',
    ): array {
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $countryId = (int) $pdo->query(
            'SELECT id FROM countries WHERE iso2 = "CZ" LIMIT 1',
        )->fetchColumn();
        self::assertGreaterThan(0, $countryId);
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetická společnost",
                    display_name = "Syntetický zaměstnavatel",
                    ic = "12345678",
                    dic = "CZ12345678",
                    street = "Testovací 12",
                    city = "Testov",
                    zip = "10000",
                    country_id = ?,
                    email = "firma@example.invalid",
                    phone = "+420 222 000 001"
              WHERE id = ?',
        )->execute([$countryId, $supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, is_active)
             VALUES (?, "CERT", "Syntetická účtárna", 1)',
        )->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, payroll_contact_name,
                 payroll_contact_email, payroll_contact_phone)
             VALUES (?, ?, "Syntetická mzdová účetní",
                     "mzdy@example.invalid", "+420 777 000 001")',
        )->execute([$supplierId, $officeId]);

        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba", "employee", 1)',
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        if ($variant === 'nonresident') {
            $pdo->prepare(
                'UPDATE payroll_employees SET birth_date = "1990-01-01"
                  WHERE supplier_id = ? AND id = ?',
            )->execute([$supplierId, $employeeId]);
        }
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 birth_surname, effective_from)
             VALUES (?, ?, "Syntetická osoba", "Syntetická", "Osoba",
                     "Dřívější Syntetická",
                     "2026-01-01")',
        )->execute([$supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Modelová 2", "Brno", "602 00",
                      ?, "2026-01-01")',
        )->execute([
            $supplierId,
            $employeeId,
            $variant === 'nonresident' ? 'SK' : 'CZ',
        ]);
        if ($variant !== 'nonresident') {
            $pdo->prepare(
                'INSERT INTO payroll_person_identifiers
                    (supplier_id, employee_id, identifier_type, value_ciphertext,
                     value_hash, value_masked)
                 VALUES (?, ?, "birth_number", "enc:v2:synthetic", ?, "••••0009")',
            )->execute([$supplierId, $employeeId, random_bytes(32)]);
            $identifierId = (int) $pdo->lastInsertId();
            $sealed = $sensitive->seal(
                '0001010009',
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
                $identifierId,
            );
            $pdo->prepare(
                'UPDATE payroll_person_identifiers
                    SET value_ciphertext = ?, value_hash = ?, value_masked = ?
                  WHERE supplier_id = ? AND id = ?',
            )->execute([
                $sealed->ciphertext,
                $sealed->lookupHash,
                $sealed->masked,
                $supplierId,
                $identifierId,
            ]);
        }

        $childClaims = [];
        $creditClaims = [[
            'credit_kind' => 'taxpayer',
            'evidence_status' => 'verified',
        ]];
        if ($variant === 'common-variants') {
            $pdo->prepare(
                'INSERT INTO payroll_dependants
                    (supplier_id, employee_id, relation, full_name, birth_date,
                     ztp_p, student, existence_from)
                 VALUES (?, ?, "child_own", "Dítě Syntetické", "2020-02-01",
                         1, 0, "2020-02-01")',
            )->execute([$supplierId, $employeeId]);
            $dependantId = (int) $pdo->lastInsertId();
            $creditClaims[] = [
                'credit_kind' => 'disability-extended',
                'evidence_status' => 'verified',
            ];
            $creditClaims[] = [
                'credit_kind' => 'ztp-p',
                'evidence_status' => 'verified',
            ];
            $childClaims[] = [
                'child_reference' => 'dependant-' . $dependantId,
                'child_order' => 1,
                'ztp_p' => true,
                'evidence_status' => 'verified',
                'shared_household_confirmed' => true,
                'other_claimant_excluded' => true,
            ];
        }

        $person = [
            'employee_id' => $employeeId,
            'statutory' => [
                'status' => 'calculated',
                'income_tax' => [
                    'status' => 'calculated',
                    'advance_tax' => [
                        'taxable_income_minor_units' => 10_000_000,
                    ],
                    'withholding_base_minor_units' => 2_000_000,
                    'withholding_tax_minor_units' => 300_000,
                ],
                'net_pay' => [
                    'non_cash_income_minor_units' => 0,
                    'advance_tax_minor_units' => 1_250_000,
                    'withholding_tax_minor_units' => 300_000,
                    'tax_bonus_minor_units' => 0,
                ],
                'social_insurance' => [
                    'employee_contribution_minor_units' =>
                        $variant === 'nonresident' ? 710_000 : 0,
                ],
                'health_insurance' => [
                    'employee_contribution_minor_units' =>
                        $variant === 'nonresident' ? 420_000 : 0,
                ],
            ],
            'payable_after_enforcement_minor' => 7_000_000,
        ];
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employee' => ['id' => $employeeId],
                'statutory_evidence' => [
                    'income_tax' => [
                        'declaration' => [
                            'status' => 'signed',
                            'effective_from' => '2026-01-01',
                            'effective_to' => '2026-12-31',
                        ],
                        'residence' => [
                            'residence' => $variant === 'nonresident'
                                ? 'non-resident'
                                : 'czech-resident',
                            'country_code' => $variant === 'nonresident'
                                ? 'SK'
                                : 'CZ',
                        ],
                        'credit_claims' => $creditClaims,
                        'child_claims' => $childClaims,
                    ],
                ],
                'employments' => [[
                    'inputs' => [[
                        'amount_minor' => 12_000_000,
                        'component' => ['kind' => 'monthly_wage'],
                    ]],
                ]],
            ]],
        ];
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'people' => [$person],
        ];
        $inputJson = CanonicalJson::encode($input);
        $resultJson = CanonicalJson::encode($result);
        $personJson = CanonicalJson::encode($person);

        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-01-01", "2026-02-15", "approved", 1)',
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash(
                'sha256',
                "synthetic-tax-certificate-revision-{$supplierId}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $personJson,
            hash('sha256', $personJson),
        ]);
        $this->paymentEvidence(
            $pdo,
            $supplierId,
            $employeeId,
            $revisionId,
            7_000_000,
            'first',
        );

        return [$supplierId, $employeeId];
    }

    private function paymentEvidence(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
        int $revisionId,
        int $amountMinor,
        string $discriminator,
    ): void {
        $snapshot = '{"schema":"synthetic-liability.v1"}';
        $pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "net-wage.tax-certificate", "net_wage",
                     "outgoing", "recipient:synthetic", "2026-02-15", "CZK",
                     ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $amountMinor,
            $snapshot,
            hash('sha256', $snapshot),
            hash(
                'sha256',
                "synthetic-certificate-liability-{$supplierId}-{$discriminator}",
                true,
            ),
        ]);
        $liabilityId = (int) $pdo->lastInsertId();
        $reference = "tax-certificate-{$liabilityId}";
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "bank", "manual", "outgoing", "2026-02-15",
                     "CZK", "payer:synthetic", ?, 1, ?, ?, ?)',
        )->execute([
            $supplierId,
            "{$reference}-batch",
            $amountMinor,
            'enc:v2:synthetic-batch',
            hash('sha256', "{$reference}-batch"),
            hash('sha256', "{$reference}-batch", true),
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "recipient:synthetic", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $batchId,
            "{$reference}-item",
            $amountMinor,
            'enc:v2:synthetic-instruction',
            hash('sha256', "{$reference}-item"),
            hash('sha256', "{$reference}-item", true),
        ]);
        $itemId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $itemId,
            $liabilityId,
            $amountMinor,
            hash('sha256', "{$reference}-allocation", true),
        ]);
        $allocationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", "2026-02-15")',
        )->execute([
            $supplierId,
            "synthetic-certificate-{$supplierId}.gpc",
            hash(
                'sha256',
                "synthetic-certificate-{$supplierId}-{$discriminator}",
            ),
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, "2026-02-15", ?, "CZK",
                     "Syntetická roční mzdová úhrada", ?)',
        )->execute([
            $statementId,
            number_format(-$amountMinor / 100, 2, '.', ''),
            hash(
                'sha256',
                "synthetic-certificate-transaction-{$supplierId}-{$discriminator}",
            ),
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id,
                 idempotency_key_hash)
             VALUES (?, ?, "matched", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $allocationId,
            $amountMinor,
            $statementId,
            $transactionId,
            hash(
                'sha256',
                "synthetic-certificate-match-{$supplierId}-{$discriminator}",
                true,
            ),
        ]);
    }

    private function appendApprovedMonth(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
    ): void {
        $source = $pdo->prepare(
            'SELECT revision.ruleset_manifest_hash,
                    revision.input_snapshot_json,
                    revision.input_snapshot_hash,
                    revision.result_snapshot_json,
                    revision.result_snapshot_hash,
                    person.result_json,
                    person.result_hash
               FROM payroll_run_revisions revision
               JOIN payroll_run_persons person
                 ON person.supplier_id = revision.supplier_id
                AND person.revision_id = revision.id
              WHERE revision.supplier_id = ?
                AND person.employee_id = ?
              ORDER BY revision.id
              LIMIT 1',
        );
        $source->execute([$supplierId, $employeeId]);
        $row = $source->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-02-01", "2026-03-15", "approved", 1)',
        )->execute([$supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $supplierId,
            $runId,
            $row['ruleset_manifest_hash'],
            $row['input_snapshot_json'],
            $row['input_snapshot_hash'],
            $row['result_snapshot_json'],
            $row['result_snapshot_hash'],
            hash(
                'sha256',
                "synthetic-tax-certificate-revision-{$supplierId}-second",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $row['result_json'],
            $row['result_hash'],
        ]);
        $this->paymentEvidence(
            $pdo,
            $supplierId,
            $employeeId,
            $revisionId,
            7_000_000,
            'second',
        );
    }
}
