<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\ApprovedRevisionPayslipBatchService;
use MyInvoice\Service\Payroll\Document\PayslipDocumentData;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Fixtures\Payroll\SyntheticPayslipFixture;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regrese: po opravné revizi nešla přegenerovat výplatní páska nikomu, kdo už
 * ji z předchozí revize měl.
 *
 * `PayrollDocumentService::archive()` ověřoval revizi NAHRAZOVANÉHO dokladu
 * toutéž metodou jako revizi vydávanou — a ta z definice pouští jen poslední
 * schválenou revizi. Předchůdce ji nikdy nesplňuje, protože ho přebila právě
 * ta oprava, kvůli které se páska vydává znovu. Výsledkem byla hláška
 * „Only an approved payroll revision can produce documents." a měsíční
 * dokumentová fronta se zasekla na `retry_wait`, dokud ji někdo nezrušil.
 */
#[Group('integration')]
final class PayslipRegenerationAfterCorrectionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ApprovedRevisionPayslipBatchService $payslips;
    private int $supplierId;
    private string $dataDir;
    private string|false $previousDataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir()
            . '/myucto-payslip-regeneration-' . bin2hex(random_bytes(6));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->payslips = $container->get(ApprovedRevisionPayslipBatchService::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
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
        $this->removeDirectory($this->dataDir);
        $this->previousDataDir === false
            ? putenv('MYINVOICE_DATA_DIR')
            : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        parent::tearDown();
    }

    public function testCorrectionRevisionRegeneratesPayslipAsNextChainLink(): void
    {
        [$runId, $employeeId] = $this->runWithEmployee();

        // Původní páska vzniká, dokud je revize 1 poslední schválená.
        $first = $this->approvedRevision($runId, $employeeId, 1, 'puvodni');
        $original = $this->payslips->generateEmployee($this->supplierId, $runId, $first, $employeeId, null);
        self::assertSame(1, (int) $original['document_revision_no']);

        // Teprve pak přijde oprava mzdy — a přegenerování musí projít.
        $second = $this->approvedRevision($runId, $employeeId, 2, 'oprava');
        $corrected = $this->payslips->generateEmployee($this->supplierId, $runId, $second, $employeeId, null);

        self::assertSame(2, (int) $corrected['document_revision_no'], 'Oprava má být dalším článkem řetězu.');
        self::assertSame($second, (int) $corrected['revision_id']);
        self::assertNotSame((int) $original['id'], (int) $corrected['id'], 'Původní PDF se nesmí přepsat.');
    }

    public function testPayslipStillCannotBeIssuedFromSupersededRevision(): void
    {
        [$runId, $employeeId] = $this->runWithEmployee();
        $first = $this->approvedRevision($runId, $employeeId, 1, 'puvodni');
        $this->approvedRevision($runId, $employeeId, 2, 'oprava');

        // Uvolnění předchůdce se smí týkat jen NAHRAZOVANÉHO dokladu. Vydat
        // pásku ze staré revize musí zůstat zakázané, jinak by zaměstnanec
        // dostal předkorekční částku.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Výplatní pásku lze připravit pouze ze schválené mzdové revize.');
        $this->payslips->generateEmployee($this->supplierId, $runId, $first, $employeeId, null);
    }

    /** @return array{0:int,1:int} */
    private function runWithEmployee(): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, "2026-07-01", "2026-07-31", "approved")'
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Opravovaná osoba", "employee", 1)'
        )->execute([$this->supplierId]);

        return [$runId, (int) $pdo->lastInsertId()];
    }

    private function approvedRevision(
        int $runId,
        int $employeeId,
        int $revisionNo,
        string $marker,
    ): int {
        $pdo = $this->db->pdo();
        $payslip = $this->snapshot(
            SyntheticPayslipFixture::document(),
            'Opravovaná osoba (' . $marker . ')',
        );
        $person = [
            'employee_id' => $employeeId,
            'employments' => [],
            'totals' => [],
            'payslip_document' => $payslip,
        ];
        $resultJson = CanonicalJson::encode([
            'schema_version' => 'payroll-run-result.v2',
            'people' => [$person],
            'totals' => [],
        ]);
        $inputJson = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'people' => [],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "approved", "payroll-run-input.v2", ?, ?, ?, ?, ?, UNHEX(?))'
        )->execute([
            $this->supplierId,
            $runId,
            $revisionNo,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', 'regeneration-' . $runId . '-' . $revisionNo),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $personJson = CanonicalJson::encode($person);
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status, result_json, result_hash)
             VALUES (?, ?, ?, "calculated", ?, ?)'
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $personJson,
            hash('sha256', $personJson),
        ]);

        return $revisionId;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    private function snapshot(PayslipDocumentData $document, string $displayName): array
    {
        return [
            'schema_version' => 'payroll-payslip-document.v1',
            'employer_name' => $document->employerName,
            'employer_identification_number' => $document->employerIdentificationNumber,
            'employee_display_name' => $displayName,
            'employment_label' => $document->employmentLabel,
            'income_lines' => array_map(
                static fn ($line): array => $line->toTemplateData(),
                $document->incomeLines,
            ),
            'gross_minor_units' => $document->grossMinorUnits,
            'employee_social_minor_units' => $document->employeeSocialMinorUnits,
            'employee_health_minor_units' => $document->employeeHealthMinorUnits,
            'health_minimum_top_up_minor_units' => $document->healthMinimumTopUpMinorUnits,
            'tax_base_minor_units' => $document->taxBaseMinorUnits,
            'tax_before_credits_minor_units' => $document->taxBeforeCreditsMinorUnits,
            'tax_non_refundable_credits_minor_units' => $document->taxNonRefundableCreditsMinorUnits,
            'tax_child_credit_minor_units' => $document->taxChildCreditMinorUnits,
            'tax_bonus_eligible' => $document->taxBonusEligible,
            'tax_after_credits_minor_units' => $document->taxAfterCreditsMinorUnits,
            'tax_bonus_minor_units' => $document->taxBonusMinorUnits,
            'other_deduction_lines' => array_map(
                static fn ($line): array => $line->toTemplateData(),
                $document->otherDeductionLines,
            ),
            'rounding_adjustment_minor_units' => $document->roundingAdjustmentMinorUnits,
            'net_minor_units' => $document->netMinorUnits,
            'employer_social_minor_units' => $document->employerSocialMinorUnits,
            'employer_health_minor_units' => $document->employerHealthMinorUnits,
            'gross_expense_account' => $document->grossExpenseAccount,
            'gross_liability_account' => $document->grossLiabilityAccount,
            'insurance_expense_account' => $document->insuranceExpenseAccount,
            'insurance_liability_account' => $document->insuranceLiabilityAccount,
            'currency' => $document->currency,
        ];
    }
}
