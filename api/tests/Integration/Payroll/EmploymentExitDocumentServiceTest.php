<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Service\Payroll\Document\AverageEarningsSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\EmploymentExitDocumentService;
use MyInvoice\Service\Payroll\Document\EmploymentExitReadinessException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class EmploymentExitDocumentServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private EmploymentExitDocumentService $service;
    private PayrollDocumentRepository $documents;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $userId;
    private int $foreignSupplierId;
    private string $dataDir;
    private string|false $previousDataDir;

    protected function setUp(): void
    {
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir()
            . '/myucto-employment-exit-service-'
            . bin2hex(random_bytes(6));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(EmploymentExitDocumentService::class);
        $this->documents = $container->get(PayrollDocumentRepository::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        $this->userId = (int) $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $this->foreignSupplierId = $sourceSupplierId;

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier
                SET payroll_enabled = 1,
                    company_name = "Syntetický zaměstnavatel s.r.o.",
                    display_name = "Syntetický zaměstnavatel s.r.o.",
                    ic = "00000000",
                    dic = "CZ00000000",
                    street = "Testovací 1",
                    city = "Praha",
                    zip = "10000",
                    email = "synthetic@example.test",
                    phone = "+420 200 000 000"
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, is_active)
             VALUES (?, "EXIT", "Výstupní účtárna", 1)',
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, payroll_contact_name,
                 payroll_contact_email, payroll_contact_phone)
             VALUES (?, ?, "Syntetická účetní", "payroll@example.test",
                     "+420 200 000 001")',
        )->execute([$this->supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, birth_date, taxpayer_type, is_active)
             VALUES (?, "Syntetická Zaměstnankyně", "1990-05-17",
                     "employee", 0)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 effective_from)
             VALUES (?, ?, "Syntetická Zaměstnankyně", "Syntetická",
                     "Zaměstnankyně", "2022-04-01")',
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Testovací 10", "Praha", "10000",
                     "CZ", "2022-04-01")',
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type,
                 status, start_date, actual_start_date, end_date,
                 is_legacy_projection)
             VALUES (?, ?, ?, "SYNTH-EXIT", "employment", "ended",
                     "2022-04-01", "2022-04-01", "2026-07-31", 0)',
        )->execute([$this->supplierId, $this->employeeId, $officeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on, actual_start_on, weekly_hours,
                 workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime, risky_work,
                 tax_declaration_signed, is_primary, change_reason)
             VALUES (?, ?, ?, "2022-04-01", "2022-04-01", "2022-04-01",
                     40.00, 10000, "automatic", "automatic", "advance",
                     0, 1, 0, "Syntetický podklad")',
        )->execute([$this->supplierId, $this->employmentId, $officeId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        self::removeDirectory($this->dataDir ?? '');
        $this->previousDataDir === false
            ? putenv('MYINVOICE_DATA_DIR')
            : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
    }

    public function testCreatesApprovedEmploymentCertificateAndReplaysIdempotently(): void
    {
        $document = $this->service->generateEmploymentCertificate(
            $this->supplierId,
            $this->employmentId,
            self::completeEvidence(),
            'synthetic-employment-exit',
            $this->userId,
        );
        $replayed = $this->service->generateEmploymentCertificate(
            $this->supplierId,
            $this->employmentId,
            self::completeEvidence(),
            'synthetic-employment-exit',
            $this->userId,
        );

        self::assertSame($document['id'], $replayed['id']);
        self::assertSame('employment_certificate', $document['document_kind']);
        self::assertNull($document['run_id']);
        self::assertNull($document['annual_revision_id']);
        self::assertGreaterThan(0, $document['employment_exit_revision_id']);

        $revision = $this->db->pdo()->query(
            'SELECT snapshot_ciphertext, source_manifest_json, approved_by
               FROM payroll_employment_exit_revisions
              WHERE id = ' . (int) $document['employment_exit_revision_id'],
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($revision);
        self::assertStringNotContainsString(
            'Syntetická Zaměstnankyně',
            (string) $revision['snapshot_ciphertext'],
        );
        self::assertStringNotContainsString(
            'Syntetická Zaměstnankyně',
            (string) $revision['source_manifest_json'],
        );
        self::assertStringNotContainsString(
            'Účetní specialista',
            (string) $revision['source_manifest_json'],
        );
        self::assertStringNotContainsString(
            'synthetic@example.test',
            (string) $revision['source_manifest_json'],
        );
        self::assertSame($this->userId, (int) $revision['approved_by']);

        $listed = $this->documents->listEmploymentExitDocuments(
            $this->supplierId,
            $this->employmentId,
        );
        self::assertCount(1, $listed);
        self::assertSame($this->employmentId, $listed[0]['employment_id']);
        self::assertSame(1, $listed[0]['employment_exit_revision_no']);
        self::assertSame('2026-07-31', $listed[0]['employment_end_date']);
    }

    public function testFailsClosedBeforeWritingWhenDeductionAssessmentIsMissing(): void
    {
        $evidence = self::completeEvidence();
        $evidence['deduction_assessment_complete'] = false;

        try {
            $this->service->generateEmploymentCertificate(
                $this->supplierId,
                $this->employmentId,
                $evidence,
                'synthetic-incomplete-evidence',
                $this->userId,
            );
            self::fail('Neúplné posouzení srážek muselo generování zablokovat.');
        } catch (EmploymentExitReadinessException $exception) {
            self::assertSame(
                'deduction_assessment_incomplete',
                $exception->readinessCode,
            );
        }

        self::assertSame(
            0,
            (int) $this->db->pdo()->query(
                'SELECT COUNT(*) FROM payroll_employment_exit_revisions
                  WHERE supplier_id = ' . $this->supplierId,
            )->fetchColumn(),
        );
    }

    public function testAverageEarningsCertificateBlockedWhenApprovedSnapshotIsMissing(): void
    {
        $readiness = $this->service->readiness(
            $this->supplierId,
            $this->employmentId,
        )['average_earnings_certificate'];
        self::assertFalse($readiness['available']);
        self::assertSame(
            'average_earnings_snapshot_missing',
            $readiness['readiness_code'],
        );
        self::assertSame(2026, $readiness['decisive_year']);
        self::assertSame(3, $readiness['decisive_quarter']);

        try {
            $this->service->generateAverageEarningsDocument(
                $this->supplierId,
                $this->employmentId,
                AverageEarningsSnapshotBuilder::CERTIFICATE_PURPOSE,
                self::certificateEvidence(),
                'synthetic-average-exit',
                $this->userId,
            );
            self::fail('Potvrzení pro ÚP nesmí vzniknout bez schváleného podkladu MZ-07.');
        } catch (EmploymentExitReadinessException $exception) {
            self::assertSame(
                'average_earnings_snapshot_missing',
                $exception->readinessCode,
            );
            self::assertStringContainsString('2026/Q3', $exception->getMessage());
        }
    }

    public function testAverageEarningsCertificateBlockedWithoutTaxDeclarationEvidence(): void
    {
        $this->insertApprovedAverageEarningSnapshot(2026, 3);

        $readiness = $this->service->readiness(
            $this->supplierId,
            $this->employmentId,
        )['average_earnings_certificate'];
        self::assertFalse($readiness['available']);
        self::assertSame(
            'tax_declaration_evidence_missing',
            $readiness['readiness_code'],
        );

        try {
            $this->service->generateAverageEarningsDocument(
                $this->supplierId,
                $this->employmentId,
                AverageEarningsSnapshotBuilder::CERTIFICATE_PURPOSE,
                self::certificateEvidence(),
                'synthetic-average-exit-with-snapshot',
                $this->userId,
            );
            self::fail(
                'Potvrzení pro ÚP nesmí vzniknout bez doložené evidence daňového prohlášení.',
            );
        } catch (EmploymentExitReadinessException $exception) {
            self::assertSame(
                'tax_declaration_evidence_missing',
                $exception->readinessCode,
            );
        }
        self::assertSame(
            0,
            (int) $this->db->pdo()->query(
                'SELECT COUNT(*) FROM payroll_employment_exit_revisions
                  WHERE supplier_id = ' . $this->supplierId,
            )->fetchColumn(),
        );
    }

    public function testAverageEarningsCertificateIsIssuedFromApprovedSnapshotAndEvidence(): void
    {
        $this->insertApprovedAverageEarningSnapshot(2026, 3);
        $this->insertTaxDeclaration('signed');

        $readiness = $this->service->readiness(
            $this->supplierId,
            $this->employmentId,
        )['average_earnings_certificate'];
        self::assertTrue(
            $readiness['available'],
            'Blokátor: ' . (string) $readiness['readiness_code'],
        );

        $document = $this->service->generateAverageEarningsDocument(
            $this->supplierId,
            $this->employmentId,
            AverageEarningsSnapshotBuilder::CERTIFICATE_PURPOSE,
            self::certificateEvidence(),
            'synthetic-average-exit-issued',
            $this->userId,
        );
        $replayed = $this->service->generateAverageEarningsDocument(
            $this->supplierId,
            $this->employmentId,
            AverageEarningsSnapshotBuilder::CERTIFICATE_PURPOSE,
            self::certificateEvidence(),
            'synthetic-average-exit-issued',
            $this->userId,
        );

        self::assertSame($document['id'], $replayed['id']);
        self::assertSame(
            'average_earnings_certificate',
            $document['document_kind'],
        );
        self::assertGreaterThan(0, $document['employment_exit_revision_id']);

        $purpose = $this->db->pdo()->query(
            'SELECT purpose FROM payroll_employment_exit_revisions
              WHERE id = ' . (int) $document['employment_exit_revision_id'],
        )->fetchColumn();
        self::assertSame('average_earnings_certificate', $purpose);
    }

    public function testAverageEarningsStatementIsIssuedWithoutTaxEvidence(): void
    {
        $this->insertApprovedAverageEarningSnapshot(2026, 3);

        $readiness = $this->service->readiness(
            $this->supplierId,
            $this->employmentId,
        );
        self::assertTrue(
            $readiness['average_earnings_statement']['available'],
            'Blokátor: ' . (string) $readiness['average_earnings_statement']['readiness_code'],
        );
        self::assertFalse(
            $readiness['average_earnings_certificate']['available'],
            'Hrubé potvrzení nesmí odblokovat i čisté potvrzení pro ÚP.',
        );

        $document = $this->service->generateAverageEarningsDocument(
            $this->supplierId,
            $this->employmentId,
            AverageEarningsSnapshotBuilder::STATEMENT_PURPOSE,
            ['requested_purpose' => 'Žádost o hypoteční úvěr', 'correction_reason' => null],
            'synthetic-average-statement',
            $this->userId,
        );

        self::assertSame(
            'average_earnings_statement',
            $document['document_kind'],
        );
    }

    public function testAverageEarningsBlockedWhenWeeklyHoursAreMissing(): void
    {
        $this->insertApprovedAverageEarningSnapshot(2026, 3);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET weekly_hours = NULL
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $readiness = $this->service->readiness(
            $this->supplierId,
            $this->employmentId,
        )['average_earnings_statement'];
        self::assertFalse($readiness['available']);
        self::assertSame(
            'weekly_hours_evidence_missing',
            $readiness['readiness_code'],
        );
    }

    public function testAverageEarningsCertificateBlockedByChildTaxCredit(): void
    {
        $this->insertApprovedAverageEarningSnapshot(2026, 3);
        $this->insertTaxDeclaration('signed');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_child_claims
                (supplier_id, employee_id, child_reference, child_order, ztp_p,
                 evidence_status, shared_household_confirmed,
                 other_claimant_excluded, effective_from, evidence_reference)
             VALUES (?, ?, "CHILD-1", 1, 0, "verified", 1, 1, "2022-04-01",
                     "synthetic-child-evidence")',
        )->execute([$this->supplierId, $this->employeeId]);

        $readiness = $this->service->readiness(
            $this->supplierId,
            $this->employmentId,
        )['average_earnings_certificate'];
        self::assertFalse($readiness['available']);
        self::assertSame(
            'average_earnings_child_credit_not_supported',
            $readiness['readiness_code'],
        );
    }

    /**
     * W30 / C-13 + C-14 — zápočtový list se SONDUJE a jeho vada NEBLOKUJE
     * potvrzení pro Úřad práce.
     *
     * DPP bez pokračující srážky nikdy nesplní § 313 odst. 1 ZP ve spojení
     * s § 142 odst. 1 (jediný podporovaný důvod vydání jsou srážky ze mzdy),
     * takže zápočtový list vydat nejde. Dřív o tom `readiness()` mlčela —
     * `employment_certificate.available` byla natvrdo `true` a uživatel dostal
     * 422 až po vyplnění celého formuláře.
     *
     * Zároveň platí opačný směr: vada podkladu ZÁPOČTOVÉHO LISTU nesmí zhasnout
     * potvrzení o průměrném výdělku podle § 313 odst. 2 ZP. To je podklad pro
     * podporu v nezaměstnanosti a s exekučním ledgerem nemá nic společného.
     */
    public function testDeductionLedgerGateBlocksOnlyTheEmploymentCertificate(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET relation_type = "dpp"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->insertApprovedAverageEarningSnapshot(2026, 3);
        $this->insertTaxDeclaration('signed');

        $readiness = $this->service->readiness(
            $this->supplierId,
            $this->employmentId,
        );

        self::assertFalse(
            $readiness['employment_certificate']['available'],
            'Zápočtový list se musí sondovat, ne hlásit dostupnost natvrdo.',
        );
        self::assertSame(
            'dpp_wage_deduction_missing',
            $readiness['employment_certificate']['readiness_code'],
        );
        self::assertSame(
            [],
            $readiness['employment_certificate']['deduction_claim_ids'],
        );

        self::assertTrue(
            $readiness['average_earnings_certificate']['available'],
            'Potvrzení pro ÚP nesmí padnout kvůli podkladu zápočtového listu.',
        );
        self::assertNull(
            $readiness['average_earnings_certificate']['readiness_code'],
        );
        self::assertSame(
            2026,
            $readiness['average_earnings_certificate']['decisive_year'],
        );
    }

    private function insertTaxDeclaration(string $status): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from,
                 evidence_reference)
             VALUES (?, ?, ?, "2022-04-01", "synthetic-declaration")',
        )->execute([$this->supplierId, $this->employeeId, $status]);
    }

    /** @return array<string,mixed> */
    private static function certificateEvidence(): array
    {
        return [
            'termination_assessment_complete' => true,
            'termination_reason_kind' => 'organizational',
            'employee_stated_reason' => null,
            'pension_insurance_periods' => [
                ['from' => '2022-04-01', 'to' => '2026-07-31'],
            ],
            'correction_reason' => null,
        ];
    }

    private function insertApprovedAverageEarningSnapshot(int $year, int $quarter): void
    {
        $trace = json_encode(['rule' => 'synthetic-test-fixture'], JSON_THROW_ON_ERROR);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year, applicable_quarter,
                 revision_no, source_kind, decisive_from, decisive_to,
                 gross_earnings_minor, longer_period_allocated_minor,
                 worked_minutes, worked_days, average_hourly_minor,
                 support_status, status, ruleset_id, ruleset_hash,
                 input_hash, input_trace, approved_by, approved_at)
             VALUES (?, ?, ?, ?, 1, "actual", "2026-04-01", "2026-06-30",
                     6000000, 0, 60000, 63, 25000,
                     "supported", "approved", "cz-2026-average-earning", ?,
                     UNHEX(SHA2("synthetic", 256)), ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $year,
            $quarter,
            str_repeat('b', 64),
            $trace,
            $this->userId,
        ]);
    }

    public function testArchivedEmploymentWithEndedLifecycleEvidenceAllowsCorrection(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status,
                 to_status, effective_on, created_by)
             VALUES (?, ?, "status_changed", "active", "ended",
                     "2026-07-31", ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->userId,
        ]);
        $original = $this->service->generateEmploymentCertificate(
            $this->supplierId,
            $this->employmentId,
            self::completeEvidence(),
            'synthetic-employment-exit-before-archive',
            $this->userId,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET status = "archived", archived_at = NOW(),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status,
                 to_status, effective_on, created_by)
             VALUES (?, ?, "status_changed", "ended", "archived",
                     "2026-08-01", ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->userId,
        ]);

        self::assertTrue(
            $this->service->readiness(
                $this->supplierId,
                $this->employmentId,
            )['employment_certificate']['available'],
        );
        $evidence = self::completeEvidence();
        $evidence['work_description'] = 'Vedoucí účetní specialista';
        $evidence['correction_reason'] =
            'Oprava popisu práce po archivaci pracovního vztahu.';
        $corrected = $this->service->generateEmploymentCertificate(
            $this->supplierId,
            $this->employmentId,
            $evidence,
            'synthetic-employment-exit-after-archive',
            $this->userId,
        );

        self::assertSame($original['id'], $corrected['supersedes_document_id']);
    }

    public function testArchivedEmploymentWithoutEndedLifecycleEvidenceStaysBlocked(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET status = "archived", archived_at = NOW(),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $readiness = $this->service->readiness(
            $this->supplierId,
            $this->employmentId,
        );
        self::assertFalse(
            $readiness['employment_certificate']['available'],
        );
        self::assertSame(
            'employment_not_ended',
            $readiness['employment_certificate']['readiness_code'],
        );

        try {
            $this->service->generateEmploymentCertificate(
                $this->supplierId,
                $this->employmentId,
                self::completeEvidence(),
                'synthetic-archived-without-ended-evidence',
                $this->userId,
            );
            self::fail(
                'Prostá archivace bez historického ukončení musí zůstat blokovaná.',
            );
        } catch (EmploymentExitReadinessException $exception) {
            self::assertSame(
                'employment_not_ended',
                $exception->readinessCode,
            );
        }
    }

    public function testSmallScaleEmploymentUsesEmploymentDocumentKind(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET relation_type = "small_scale_employment"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        self::assertTrue(
            $this->service->readiness(
                $this->supplierId,
                $this->employmentId,
            )['employment_certificate']['available'],
        );
        $document = $this->service->generateEmploymentCertificate(
            $this->supplierId,
            $this->employmentId,
            self::completeEvidence(),
            'synthetic-small-scale-employment-exit',
            $this->userId,
        );

        self::assertSame('employment_certificate', $document['document_kind']);
    }

    public function testUnsupportedRelationsHaveSameReadinessAndGenerationBlocker(): void
    {
        foreach (['partner_dependent', 'statutory_body'] as $relationType) {
            $this->db->pdo()->prepare(
                'UPDATE payroll_employments
                    SET relation_type = ?
                  WHERE supplier_id = ? AND id = ?'
            )->execute([
                $relationType,
                $this->supplierId,
                $this->employmentId,
            ]);

            $readiness = $this->service->readiness(
                $this->supplierId,
                $this->employmentId,
            );
            self::assertFalse(
                $readiness['employment_certificate']['available'],
            );
            self::assertSame(
                'relationship_kind_not_supported',
                $readiness['employment_certificate']['readiness_code'],
            );

            try {
                $this->service->generateEmploymentCertificate(
                    $this->supplierId,
                    $this->employmentId,
                    self::completeEvidence(),
                    "synthetic-{$relationType}-exit",
                    $this->userId,
                );
                self::fail('Nepodporovaný vztah musí generování zablokovat.');
            } catch (EmploymentExitReadinessException $exception) {
                self::assertSame(
                    'relationship_kind_not_supported',
                    $exception->readinessCode,
                );
            }
        }
    }

    public function testChangedSourceRequiresReasonAndCreatesChainedCorrection(): void
    {
        $original = $this->service->generateEmploymentCertificate(
            $this->supplierId,
            $this->employmentId,
            self::completeEvidence(),
            'synthetic-employment-exit-original',
            $this->userId,
        );
        $correctedEvidence = self::completeEvidence();
        $correctedEvidence['work_description'] =
            'Vedoucí účetní specialista';

        try {
            $this->service->generateEmploymentCertificate(
                $this->supplierId,
                $this->employmentId,
                $correctedEvidence,
                'synthetic-employment-exit-without-reason',
                $this->userId,
            );
            self::fail('Změněný zdroj musí vyžadovat důvod opravy.');
        } catch (EmploymentExitReadinessException $exception) {
            self::assertSame(
                'correction_reason_required',
                $exception->readinessCode,
            );
        }

        $correctedEvidence['correction_reason'] =
            'Oprava popisu sjednaného druhu práce.';
        $corrected = $this->service->generateEmploymentCertificate(
            $this->supplierId,
            $this->employmentId,
            $correctedEvidence,
            'synthetic-employment-exit-correction',
            $this->userId,
        );
        self::assertNotSame($original['id'], $corrected['id']);
        self::assertSame(
            $original['id'],
            $corrected['supersedes_document_id'],
        );
        $revision = $this->db->pdo()->prepare(
            'SELECT revision_no, previous_revision_id
               FROM payroll_employment_exit_revisions
              WHERE id = ?',
        );
        $revision->execute([$corrected['employment_exit_revision_id']]);
        $row = $revision->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(2, (int) $row['revision_no']);
        self::assertSame(
            (int) $original['employment_exit_revision_id'],
            (int) $row['previous_revision_id'],
        );
    }

    public function testReusedIdempotencyKeyRollsBackDifferentCorrection(): void
    {
        $this->service->generateEmploymentCertificate(
            $this->supplierId,
            $this->employmentId,
            self::completeEvidence(),
            'synthetic-employment-exit-reused-key',
            $this->userId,
        );
        $changed = self::completeEvidence();
        $changed['work_description'] = 'Jiný syntetický druh práce';
        $changed['correction_reason'] = 'Syntetická oprava vstupu.';

        try {
            $this->service->generateEmploymentCertificate(
                $this->supplierId,
                $this->employmentId,
                $changed,
                'synthetic-employment-exit-reused-key',
                $this->userId,
            );
            self::fail(
                'Stejný idempotency key nesmí přijmout jiný snapshot.',
            );
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'idempotency',
                mb_strtolower($exception->getMessage()),
            );
        }
        self::assertSame(
            1,
            (int) $this->db->pdo()->query(
                'SELECT COUNT(*)
                   FROM payroll_employment_exit_revisions
                  WHERE supplier_id = ' . $this->supplierId,
            )->fetchColumn(),
        );
    }

    public function testEmploymentCannotCrossTenantBoundary(): void
    {
        try {
            $this->service->generateEmploymentCertificate(
                $this->foreignSupplierId,
                $this->employmentId,
                self::completeEvidence(),
                'synthetic-foreign-employment-exit',
                $this->userId,
            );
            self::fail('Cizí pracovní vztah nesmí být dostupný.');
        } catch (EmploymentExitReadinessException $exception) {
            self::assertSame(
                'employment_not_found',
                $exception->readinessCode,
            );
        }
        self::assertSame(
            0,
            (int) $this->db->pdo()->query(
                'SELECT COUNT(*)
                   FROM payroll_employment_exit_revisions
                  WHERE supplier_id = ' . $this->foreignSupplierId
                    . ' AND employment_id = ' . $this->employmentId,
            )->fetchColumn(),
        );
    }

    /** @return array<string,mixed> */
    private static function completeEvidence(): array
    {
        return [
            'work_description' => 'Účetní specialista',
            'achieved_qualification' => 'Úplné střední odborné vzdělání',
            'exposure_assessment_complete' => true,
            'exposure_facts' => [],
            'deduction_assessment_complete' => true,
            'deductions' => [],
            'pension_category_assessment_complete' => true,
            'pre1993_pension_category_periods' => [],
            'dpp_issuance_basis' => null,
            'correction_reason' => null,
        ];
    }

    private static function removeDirectory(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
