<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementPaymentRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollInsolvencyPaymentRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentBatchRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentMatchRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseLifecycle;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthRequest;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentAllocation;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentCalculation;
use MyInvoice\Service\Payroll\Garnishment\PayrollInsolvencyPaymentInstructionService;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Payment\PayrollEnforcementLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollInsolvencyLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentEvidenceReference;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * MZ-14-W08 — skutečné odeslání sražených exekučních částek příjemcům.
 *
 * Nejtvrdší pravidlo řezu: částka v depozitu (`held`) se nikdy nedostane do
 * odchozí platební dávky. Druhé pravidlo: zůstatek pohledávky klesá až po
 * potvrzené úhradě, nikoli po sražení ze mzdy.
 */
#[Group('integration')]
final class PayrollEnforcementLiabilityMaterializerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';
    private const PAYMENT_DATE = '2026-07-10';

    private Connection $db;
    private PayrollEnforcementRepository $enforcement;
    private PayrollEnforcementPaymentRepository $enforcementPayments;
    private PayrollEnforcementLiabilityMaterializer $materializer;
    private PayrollInsolvencyLiabilityMaterializer $insolvencyMaterializer;
    private PayrollInstitutionAccountRepository $institutions;
    private PayrollPaymentBatchBuilder $batches;
    private PayrollPaymentReconciliationService $reconciliation;
    private SecretEncryption $encryption;
    private PayrollSensitiveData $sensitiveData;
    private int $supplierId;
    private int $actorId;
    private int $employeeId;
    private int $employmentId;
    private int $decisionDocumentId;
    private int $recipientAccountId;
    private int $runId;
    private int $recipientInstitutionId;
    private int $payerCurrencyId;
    private int $revisionSequence = 0;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $this->sensitiveData = $sensitive;
        $this->encryption = $encryption;
        if (!$connection->hasTable('payroll_enforcement_cases')) {
            self::markTestSkipped('Migrace exekučních případů neproběhla.');
        }
        $pdo = $connection->pdo();
        $sourceSupplier = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->actorId = $this->createActor($pdo);
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->actorId]);
        $this->employeeId = $this->createEmployee($this->supplierId);
        $this->employmentId = $this->createEmployment();
        $this->decisionDocumentId = $this->createDecisionDocument(
            $this->supplierId,
            'approved-standard',
        );
        $institutions = new PayrollInstitutionAccountRepository(
            $connection,
            $sensitive,
            new PayrollInstitutionAccountDeletionRepository(
                $connection,
                new ActivityLogger($connection),
            ),
        );
        $this->institutions = $institutions;
        $institutions->create($this->supplierId, [
            'institution_type' => 'other_recipient',
            'institution_code' => 'EXEK1',
            'institution_name' => 'Syntetický soudní exekutor',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '1234567890',
            'specific_symbol' => null,
            'constant_symbol' => '0558',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:enforcement-recipient',
            'verified_on' => '2026-06-15',
        ], $this->actorId);
        $this->recipientInstitutionId = $this->institutionId('EXEK1');
        $this->recipientAccountId = $this->institutionAccountId('EXEK1');
        $this->payerCurrencyId = $this->createPayerCurrency($pdo);
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-06-01", ?, "approved", 1)',
        )->execute([$this->supplierId, self::PAYMENT_DATE]);
        $this->runId = (int) $pdo->lastInsertId();

        $this->enforcement = new PayrollEnforcementRepository(
            $connection,
            new PayrollInsolvencyPaymentInstructionService(
                $connection,
                new DocumentRepository($connection),
            ),
        );
        $this->enforcementPayments =
            new PayrollEnforcementPaymentRepository($connection);
        $this->materializer = new PayrollEnforcementLiabilityMaterializer(
            new PayrollPaymentLiabilityRepository($connection),
            $this->enforcementPayments,
            $institutions,
            $sensitive,
        );
        $this->insolvencyMaterializer = new PayrollInsolvencyLiabilityMaterializer(
            new PayrollPaymentLiabilityRepository($connection),
            new PayrollInsolvencyPaymentRepository($connection),
            $sensitive,
        );
        $this->batches = new PayrollPaymentBatchBuilder(
            new PayrollPaymentBatchRepository($connection),
            $sensitive,
            $encryption,
            new IbanValidator(),
            new CzechBankAccountValidator(),
            new MockClock('2026-07-01 10:00:00 Europe/Prague'),
            $container->get(PayrollProductionGate::class),
        );
        $this->reconciliation = new PayrollPaymentReconciliationService(
            new PayrollPaymentMatchRepository($connection),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testHeldDepositNeverReachesOutgoingLiabilityOrBatch(): void
    {
        $remitCase = $this->createCase();
        $remitClaim = $this->createClaim($remitCase, 'non_priority', 500_00, '2026-05-01');
        $heldCase = $this->createCase();
        $heldClaim = $this->createClaim($heldCase, 'non_priority', 900_00, '2026-05-02');
        $this->setCaseStatus($remitCase, 'remit');
        $this->setCaseStatus($heldCase, 'withhold_and_hold');
        $revisionId = $this->createRevision(1, 'regular', null);
        $this->storeMonthResult($revisionId, [
            $remitClaim['claim_key'] => 300_00,
            $heldClaim['claim_key'] => 200_00,
        ], 'synthetic-held-vs-remit');

        $result = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );

        self::assertSame(1, $result['created_count']);
        $liability = $this->liability($result['liability_ids'][0]);
        self::assertSame('enforcement', $liability['liability_kind']);
        self::assertSame('outgoing', $liability['direction']);
        self::assertSame(self::PAYMENT_DATE, $liability['due_on']);
        self::assertNull($liability['employee_id']);
        self::assertSame(
            "enforcement:c{$remitCase}:cl{$remitClaim['id']}",
            $liability['liability_reference'],
        );
        self::assertSame(
            300_00,
            $this->integerValue($liability, 'amount_minor'),
        );
        self::assertSame(0, $this->countLiabilitiesFor($heldCase));

        // Depozitum nesmí projít ani ručním pokusem o zařazení do dávky.
        $batch = $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $result['liability_ids'][0],
                'amount_minor' => 300_00,
            ]],
            $this->actorId,
        );
        self::assertSame(300_00, $batch['declared_total_minor']);
        self::assertSame(1, $batch['declared_item_count']);
    }

    public function testApprovedStandardInsolvencyCreatesExplicitPayableInstruction(): void
    {
        $evidence = $this->saveApprovedInsolvencyEvidence();
        self::assertSame($this->employmentId, $evidence['insolvency_employment_id']);
        self::assertSame(
            $this->recipientAccountId,
            $evidence['insolvency_institution_account_id'],
        );
        self::assertSame(
            $this->decisionDocumentId,
            $evidence['insolvency_decision_document_id'],
        );
        $instruction = $this->enforcement->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            self::PAYMENT_DATE,
        )->insolvency;
        self::assertTrue($instruction->hasImmutablePaymentInstruction());

        $revisionId = $this->createRevision(1, 'regular', null);
        $this->storeInsolvencyMonthResult(
            $revisionId,
            $instruction,
            325_00,
            'synthetic-approved-standard-insolvency',
        );
        $created = $this->insolvencyMaterializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
        self::assertSame(1, $created['created_count']);
        $replayed = $this->insolvencyMaterializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
        self::assertSame(0, $replayed['created_count']);
        self::assertSame($created['liability_ids'], $replayed['liability_ids']);

        $liability = $this->liability($created['liability_ids'][0]);
        self::assertSame('insolvency', $liability['liability_kind']);
        self::assertSame('outgoing', $liability['direction']);
        self::assertSame(325_00, $this->integerValue($liability, 'amount_minor'));
        self::assertNull($liability['employee_id']);
        self::assertSame(
            "insolvency:p{$this->employeeId}:e{$this->employmentId}",
            $liability['liability_reference'],
        );
        $serialized = $this->stringValue($liability, 'source_snapshot_json');
        self::assertStringContainsString(
            'payroll-payment-insolvency-source.v1',
            $serialized,
        );
        self::assertStringNotContainsString('1000000005', $serialized);
        self::assertStringNotContainsString('bank_account_ciphertext', $serialized);

        $batch = $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $created['liability_ids'][0],
                'amount_minor' => 325_00,
            ]],
            $this->actorId,
        );
        $payment = $this->batchInstruction($batch['batch_id']);
        self::assertSame('1234567890', $payment['variable_symbol']);
        self::assertSame('Srazka pri oddluzeni', $payment['payment_message']);

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_insolvency_payment_instructions
                SET institution_code = "CHANGED"
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $this->supplierId,
            $instruction->paymentInstructionId,
        ]);
    }

    public function testApprovedInsolvencyRequiresExplicitCancellation(): void
    {
        $approved = $this->saveApprovedInsolvencyEvidence();
        $payload = $this->approvedInsolvencyPayload();
        $payload['insolvency_mode'] = 'none';
        $payload['insolvency_decision_verified'] = false;
        $payload['insolvency_recipient_verified'] = false;
        unset(
            $payload['insolvency_employment_id'],
            $payload['insolvency_institution_account_id'],
            $payload['insolvency_decision_document_id'],
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('výslovným zrušením');
        $this->enforcement->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            $payload,
            $this->actorId,
            (int) $approved['row_version'],
        );
    }

    public function testUnusedInsolvencyTargetCanChangeAndThenBeCancelledExplicitly(): void
    {
        $approved = $this->saveApprovedInsolvencyEvidence();
        $oldInstructionId = (int) $approved['insolvency_payment_instruction_id'];
        $replacementDocumentId = $this->createDecisionDocument(
            $this->supplierId,
            'replacement-before-use',
        );
        $payload = $this->approvedInsolvencyPayload();
        $payload['insolvency_decision_document_id'] = $replacementDocumentId;
        $changed = $this->enforcement->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            $payload,
            $this->actorId,
            (int) $approved['row_version'],
        );
        self::assertNotSame(
            $oldInstructionId,
            (int) $changed['insolvency_payment_instruction_id'],
        );

        $cancelled = $this->enforcement->cancelInsolvency(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            (int) $changed['row_version'],
            $this->actorId,
        );
        self::assertSame('none', $cancelled['insolvency_mode']);
        self::assertNull($cancelled['insolvency_payment_instruction_id']);
        self::assertFalse($cancelled['insolvency_decision_verified']);
        self::assertFalse($cancelled['insolvency_recipient_verified']);
    }

    public function testUsedInsolvencyInstructionCannotChangeOrBeCancelled(): void
    {
        $approved = $this->saveApprovedInsolvencyEvidence();
        $instruction = $this->enforcement->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            self::PAYMENT_DATE,
        )->insolvency;
        $revisionId = $this->createRevision(1, 'regular', null);
        $this->storeInsolvencyMonthResult(
            $revisionId,
            $instruction,
            200_00,
            'synthetic-used-insolvency-instruction',
        );

        try {
            $this->enforcement->cancelInsolvency(
                $this->supplierId,
                $this->employeeId,
                self::PERIOD,
                (int) $approved['row_version'],
                $this->actorId,
            );
            self::fail('Použitý pokyn oddlužení se nesmí zrušit změnou evidence.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('opravnou revizi', $exception->getMessage());
        }

        $replacementDocumentId = $this->createDecisionDocument(
            $this->supplierId,
            'replacement-after-use',
        );
        $payload = $this->approvedInsolvencyPayload();
        $payload['insolvency_decision_document_id'] = $replacementDocumentId;
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('opravnou revizi');
        $this->enforcement->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            $payload,
            $this->actorId,
            (int) $approved['row_version'],
        );
    }

    public function testInsolvencyTargetSelectionAndChangedAccountFailClosed(): void
    {
        $foreignDocument = $this->createDecisionDocument(
            $this->createIsolatedSupplier(
                $this->db->pdo(),
                $this->supplierId,
            ),
            'foreign-decision',
        );
        try {
            $this->saveApprovedInsolvencyEvidence($foreignDocument);
            self::fail('Rozhodnutí jiné firmy nesmí vytvořit platební pokyn.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'firemních dokumentech',
                $exception->getMessage(),
            );
        }

        $this->saveApprovedInsolvencyEvidence();
        $instruction = $this->enforcement->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            self::PAYMENT_DATE,
        )->insolvency;
        $revisionId = $this->createRevision(1, 'regular', null);
        $this->storeInsolvencyMonthResult(
            $revisionId,
            $instruction,
            200_00,
            'synthetic-changed-insolvency-target',
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->recipientAccountId]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('už neodpovídá ověřenému účtu');
        $this->insolvencyMaterializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    public function testNonStandardInsolvencyCannotAttachPaymentTarget(): void
    {
        $payload = $this->approvedInsolvencyPayload();
        $payload['insolvency_mode'] = 'alert_only';

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('jen ke standardnímu schválenému oddlužení');
        $this->enforcement->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            $payload,
            $this->actorId,
            null,
        );
    }

    public function testApprovedInsolvencyRejectsAccountOutsideReportedMonth(): void
    {
        $account = $this->institutions->create($this->supplierId, [
            'institution_type' => 'other_recipient',
            'institution_code' => 'EXPIRED',
            'institution_name' => 'Syntetický bývalý insolvenční správce',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '1234567890',
            'specific_symbol' => null,
            'constant_symbol' => '0558',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-05-31',
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:expired-insolvency-recipient',
            'verified_on' => '2026-05-31',
        ], $this->actorId);
        $payload = $this->approvedInsolvencyPayload();
        $payload['insolvency_institution_account_id'] = (int) $account['id'];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nebyl v měsíci účinný');
        $this->enforcement->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            $payload,
            $this->actorId,
            null,
        );
    }

    public function testPersonalDocumentCannotAuthorizeCompanyInsolvencyPayment(): void
    {
        $personalDocumentId = $this->createDecisionDocument(
            $this->supplierId,
            'personal-decision',
            'user',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('firemních dokumentech');
        $this->saveApprovedInsolvencyEvidence($personalDocumentId);
    }

    public function testNonStandardSnapshotCannotMaterializeEvenWithInstructionId(): void
    {
        $this->saveApprovedInsolvencyEvidence();
        $approved = $this->enforcement->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            self::PAYMENT_DATE,
        )->insolvency;
        $alert = new InsolvencyInstruction(
            InsolvencyMode::AlertOnly,
            true,
            true,
            null,
            $approved->paymentInstructionId,
            $approved->paymentInstructionHash,
            $approved->employmentId,
        );
        $revisionId = $this->createRevision(1, 'regular', null);
        $this->storeInsolvencyMonthResult(
            $revisionId,
            $alert,
            200_00,
            'synthetic-alert-with-forged-result',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('jen z neměnného pokynu schváleného');
        $this->insolvencyMaterializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    public function testDeferredCaseBlocksMaterializationFailClosed(): void
    {
        $caseId = $this->createCase();
        $claim = $this->createClaim($caseId, 'non_priority', 500_00, '2026-05-01');
        $this->setCaseStatus($caseId, 'remit');
        $revisionId = $this->createRevision(1, 'regular', null);
        $this->storeMonthResult(
            $revisionId,
            [$claim['claim_key'] => 300_00],
            'synthetic-deferred-after-approval',
        );
        $this->setCaseStatus($caseId, 'deferred_hold');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('zůstává v depozitu');
        $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    public function testUnverifiedRecipientAndMissingCatalogueEntryFailClosed(): void
    {
        $caseId = $this->createCase(recipientInstitutionId: null);
        $claim = $this->createClaim($caseId, 'non_priority', 500_00, '2026-05-01');
        $this->setCaseStatus($caseId, 'remit');
        $revisionId = $this->createRevision(1, 'regular', null);
        $this->storeMonthResult(
            $revisionId,
            [$claim['claim_key'] => 300_00],
            'synthetic-missing-recipient',
        );

        try {
            $this->materializer->materialize(
                $this->supplierId,
                $revisionId,
                $this->actorId,
            );
            self::fail('Případ bez příjemce z katalogu nesmí vytvořit závazek.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'katalogu platebních účtů',
                $exception->getMessage(),
            );
        }

        $this->db->pdo()->prepare(
            'UPDATE payroll_enforcement_cases
                SET recipient_institution_id = ?, recipient_verified = 0
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->recipientInstitutionId, $this->supplierId, $caseId]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ověřeného příjemce');
        $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    public function testIdempotentReplayAndCorrectionDifferenceWithReversal(): void
    {
        $caseId = $this->createCase();
        $claim = $this->createClaim($caseId, 'non_priority', 900_00, '2026-05-01');
        $this->setCaseStatus($caseId, 'remit');
        $regularRevision = $this->createRevision(1, 'regular', null);
        $this->storeMonthResult(
            $regularRevision,
            [$claim['claim_key'] => 300_00],
            'synthetic-regular',
        );
        $regular = $this->materializer->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        self::assertSame(1, $regular['created_count']);

        $replay = $this->materializer->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        self::assertSame(0, $replay['created_count']);
        self::assertSame($regular['liability_ids'], $replay['liability_ids']);
        self::assertSame(1, $this->countLiabilitiesFor($caseId));

        $increaseRevision = $this->createRevision(2, 'correction', $regularRevision);
        $this->storeMonthResult(
            $increaseRevision,
            [$claim['claim_key'] => 450_00],
            'synthetic-increase',
        );
        $increase = $this->materializer->materialize(
            $this->supplierId,
            $increaseRevision,
            $this->actorId,
        );
        $increaseRow = $this->liability($increase['liability_ids'][0]);
        self::assertSame('outgoing', $increaseRow['direction']);
        self::assertSame(150_00, $this->integerValue($increaseRow, 'amount_minor'));
        self::assertSame(
            $regular['liability_ids'][0],
            $this->integerValue($increaseRow, 'previous_liability_id'),
        );

        $decreaseRevision = $this->createRevision(3, 'correction', $increaseRevision);
        $this->storeMonthResult(
            $decreaseRevision,
            [$claim['claim_key'] => 100_00],
            'synthetic-decrease',
        );
        $decrease = $this->materializer->materialize(
            $this->supplierId,
            $decreaseRevision,
            $this->actorId,
        );
        $decreaseRow = $this->liability($decrease['liability_ids'][0]);
        self::assertSame('incoming', $decreaseRow['direction']);
        self::assertSame(350_00, $this->integerValue($decreaseRow, 'amount_minor'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Příchozí opravný závazek');
        $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $decrease['liability_ids'][0],
                'amount_minor' => 350_00,
            ]],
            $this->actorId,
        );
    }

    public function testClaimBalanceDropsOnlyAfterConfirmedPayment(): void
    {
        $caseId = $this->createCase();
        $claim = $this->createClaim($caseId, 'non_priority', 300_00, '2026-05-01');
        $this->setCaseStatus($caseId, 'remit');
        $revisionId = $this->createRevision(1, 'regular', null);
        $this->storeMonthResult(
            $revisionId,
            [$claim['claim_key'] => 300_00],
            'synthetic-settlement',
        );
        $liabilityId = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        )['liability_ids'][0];

        // Sraženo, ale ještě neodesláno: zůstatek pohledávky se nesmí hnout.
        $beforeBatch = $this->settlement($caseId);
        self::assertSame(300_00, $beforeBatch['withheld_minor']);
        self::assertSame(0, $beforeBatch['held_minor']);
        self::assertSame(0, $beforeBatch['settled_minor']);
        self::assertSame(300_00, $beforeBatch['remaining_minor']);
        $this->assertMarkPaidBlocked($caseId);

        $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [['liability_id' => $liabilityId, 'amount_minor' => 300_00]],
            $this->actorId,
        );

        // Zařazení do dávky ani export také nejsou úhradou.
        $afterBatch = $this->settlement($caseId);
        self::assertSame(300_00, $afterBatch['liability_minor']);
        self::assertSame(0, $afterBatch['settled_minor']);
        self::assertSame(300_00, $afterBatch['remaining_minor']);
        $this->assertMarkPaidBlocked($caseId);

        $allocationId = $this->allocationFor($liabilityId);
        $this->reconciliation->match(new PayrollPaymentReconciliationCommand(
            $this->supplierId,
            $allocationId,
            100_00,
            PayrollPaymentEvidenceReference::bank(
                ...$this->bankEvidence('-100.00', 'partial'),
            ),
            'synthetic-partial-match',
            $this->actorId,
        ));

        $partial = $this->settlement($caseId);
        self::assertSame(100_00, $partial['settled_minor']);
        self::assertSame(200_00, $partial['remaining_minor']);
        $this->assertMarkPaidBlocked($caseId);

        $this->reconciliation->match(new PayrollPaymentReconciliationCommand(
            $this->supplierId,
            $allocationId,
            200_00,
            PayrollPaymentEvidenceReference::bank(
                ...$this->bankEvidence('-200.00', 'rest'),
            ),
            'synthetic-final-match',
            $this->actorId,
        ));

        $settled = $this->settlement($caseId);
        self::assertSame(300_00, $settled['settled_minor']);
        self::assertSame(0, $settled['remaining_minor']);

        $paid = $this->enforcement->transition(
            $this->supplierId,
            $caseId,
            EnforcementCaseCommand::MarkPaid,
            $this->caseVersion($caseId),
            null,
            null,
            $this->actorId,
            new EnforcementCaseLifecycle(),
        );
        self::assertSame('paid', $paid['status']);
    }

    public function testPriorityClaimsAreMaterializedBeforeNonPriorityOnes(): void
    {
        $caseId = $this->createCase();
        $nonPriority = $this->createClaim($caseId, 'non_priority', 900_00, '2026-01-01');
        $maintenance = $this->createClaim(
            $caseId,
            'current_maintenance',
            900_00,
            '2026-05-01',
            weight: 400_00,
        );
        $this->setCaseStatus($caseId, 'remit');
        $revisionId = $this->createRevision(1, 'regular', null);
        $this->storeMonthResult($revisionId, [
            $nonPriority['claim_key'] => 100_00,
            $maintenance['claim_key'] => 400_00,
        ], 'synthetic-priority-order');

        $result = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );

        self::assertSame(2, $result['created_count']);
        self::assertSame(
            [
                "enforcement:c{$caseId}:cl{$maintenance['id']}",
                "enforcement:c{$caseId}:cl{$nonPriority['id']}",
            ],
            array_map(
                fn (int $id): string => $this->stringValue(
                    $this->liability($id),
                    'liability_reference',
                ),
                $result['liability_ids'],
            ),
        );
    }

    public function testTenantIsolationAndNoSensitiveDataInLiabilityOrInstruction(): void
    {
        $sourceSupplier = $this->db->pdo()->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        $otherSupplierId = $this->createIsolatedSupplier(
            $this->db->pdo(),
            (int) $sourceSupplier->fetchColumn(),
        );
        $caseId = $this->createCase();
        $claim = $this->createClaim($caseId, 'non_priority', 500_00, '2026-05-01');
        $this->setCaseStatus($caseId, 'remit');
        $revisionId = $this->createRevision(1, 'regular', null);
        $this->storeMonthResult(
            $revisionId,
            [$claim['claim_key'] => 300_00],
            'synthetic-privacy',
        );
        $liabilityId = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        )['liability_ids'][0];

        self::assertSame([], $this->enforcementPayments->remittableForRevision(
            $otherSupplierId,
            $revisionId,
        ));
        self::assertSame([], $this->enforcementPayments->settlementForCase(
            $otherSupplierId,
            $caseId,
        ));

        $caseKey = $this->caseKey($caseId);
        $row = $this->liability($liabilityId);
        $serialized = $this->stringValue($row, 'liability_reference')
            . '|' . $this->stringValue($row, 'recipient_reference')
            . '|' . $this->stringValue($row, 'source_snapshot_json');
        self::assertStringNotContainsString($caseKey, $serialized);
        self::assertStringNotContainsString($claim['claim_key'], $serialized);
        self::assertStringNotContainsString('1000000005', $serialized);
        self::assertStringNotContainsString('bank_account_ciphertext', $serialized);

        $batch = $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [['liability_id' => $liabilityId, 'amount_minor' => 300_00]],
            $this->actorId,
        );
        $instruction = $this->batchInstruction($batch['batch_id']);
        self::assertSame('1234567890', $instruction['variable_symbol']);
        self::assertSame('Srazka ze mzdy', $instruction['payment_message']);
        $encodedInstruction = json_encode(
            $instruction,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString($caseKey, $encodedInstruction);
        self::assertStringNotContainsString($claim['claim_key'], $encodedInstruction);
        $storedItem = $this->db->pdo()->prepare(
            'SELECT recipient_reference, instruction_ciphertext
               FROM payroll_payment_items
              WHERE supplier_id = ? AND batch_id = ?',
        );
        $storedItem->execute([$this->supplierId, $batch['batch_id']]);
        $stored = $this->row($storedItem->fetch(PDO::FETCH_ASSOC));
        self::assertStringNotContainsString(
            '1000000005',
            $this->stringValue($stored, 'recipient_reference'),
        );
        self::assertStringStartsWith(
            'enc:v2:',
            $this->stringValue($stored, 'instruction_ciphertext'),
        );
    }

    private function assertMarkPaidBlocked(int $caseId): void
    {
        try {
            $this->enforcement->transition(
                $this->supplierId,
                $caseId,
                EnforcementCaseCommand::MarkPaid,
                $this->caseVersion($caseId),
                null,
                null,
                $this->actorId,
                new EnforcementCaseLifecycle(),
            );
            self::fail('Případ s neuhrazenou pohledávkou nesmí přejít na uhrazený.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'nenulovým zůstatkem',
                $exception->getMessage(),
            );
        }
    }

    /** @return array{withheld_minor:int,held_minor:int,liability_minor:int,settled_minor:int,remaining_minor:int} */
    private function settlement(int $caseId): array
    {
        $totals = [
            'withheld_minor' => 0,
            'held_minor' => 0,
            'liability_minor' => 0,
            'settled_minor' => 0,
            'remaining_minor' => 0,
        ];
        foreach ($this->enforcementPayments->settlementForCase(
            $this->supplierId,
            $caseId,
        ) as $claim) {
            foreach (array_keys($totals) as $field) {
                $totals[$field] += $claim[$field];
            }
        }

        return $totals;
    }

    /** @return array{0:int,1:int} */
    private function bankEvidence(string $amount, string $reference): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, "synthetic-enforcement.gpc", ?, "1000000005",
                     "0100", "CZK", "2026-07-31")',
        )->execute([
            $this->supplierId,
            hash('sha256', "enforcement-statement:{$this->supplierId}:{$reference}"),
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, "2026-07-10", ?, "CZK", "Syntetická úhrada srážky", ?)',
        )->execute([
            $statementId,
            $amount,
            hash('sha256', "enforcement-transaction:{$this->supplierId}:{$reference}"),
        ]);

        return [$statementId, (int) $pdo->lastInsertId()];
    }

    private function allocationFor(int $liabilityId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_payment_allocations
              WHERE supplier_id = ? AND liability_id = ?',
        );
        $statement->execute([$this->supplierId, $liabilityId]);

        return PayrollTimeValue::int($statement->fetchColumn(), 'allocation_id');
    }

    private function createCase(?int $recipientInstitutionId = -1): int
    {
        $institutionId = $recipientInstitutionId === -1
            ? $this->recipientInstitutionId
            : $recipientInstitutionId;
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status,
                 effective_from, evidence_complete, recipient_verified,
                 recipient_institution_id, created_by, updated_by)
             VALUES (?, ?, ?, "enforcement", "received", "2026-01-01", 1, 1,
                     ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            'case_' . bin2hex(random_bytes(16)),
            $institutionId,
            $this->actorId,
            $this->actorId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{id:int,claim_key:string} */
    private function createClaim(
        int $caseId,
        string $category,
        int $outstanding,
        string $priorityDate,
        ?int $weight = null,
    ): array {
        $claim = $this->enforcement->addClaim($this->supplierId, $caseId, [
            'legal_basis' => 'statutory',
            'category' => $category,
            'outstanding_minor_units' => $outstanding,
            'maintenance_weight_minor_units' => $weight,
            'priority_date' => $priorityDate,
            'order_issued_on' => '2026-01-02',
            'legal_title_verified' => true,
            'order_or_notice_delivered' => true,
            'priority_classification_verified' => true,
            'agreement_verified' => false,
            'due_monetary_claim_verified' => true,
        ]);
        $id = PayrollTimeValue::int($claim['id'] ?? null, 'id');
        $statement = $this->db->pdo()->prepare(
            'SELECT claim_key FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $id]);

        return [
            'id' => $id,
            'claim_key' => PayrollTimeValue::string(
                $statement->fetchColumn(),
                'claim_key',
            ),
        ];
    }

    private function setCaseStatus(int $caseId, string $status): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_enforcement_cases
                SET status = ?, evidence_complete = 1, recipient_verified = 1,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?',
        )->execute([$status, $this->supplierId, $caseId]);
    }

    private function caseVersion(int $caseId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_enforcement_cases
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $caseId]);

        return PayrollTimeValue::int($statement->fetchColumn(), 'row_version');
    }

    private function caseKey(int $caseId): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT case_key FROM payroll_enforcement_cases
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $caseId]);

        return PayrollTimeValue::string($statement->fetchColumn(), 'case_key');
    }

    private function createRevision(
        int $revisionNo,
        string $revisionKind,
        ?int $previousRevisionId,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_runs SET current_revision_no = ?, status = "approved"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$revisionNo, $this->supplierId, $this->runId]);
        $input = '{"schema_version":"payroll-run-input.v2"}';
        $result = '{"schema_version":"payroll-run-result.v2"}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version, ruleset_manifest_hash,
                 input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash, approved_at)
             VALUES (?, ?, ?, ?, ?, "approved", "payroll-run-input.v2", ?,
                     ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $this->runId,
            $revisionNo,
            $previousRevisionId,
            $revisionKind,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash(
                'sha256',
                "synthetic-enforcement-revision:{$this->runId}:"
                    . ++$this->revisionSequence,
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")',
        )->execute([$this->supplierId, $revisionId, $this->employeeId]);

        return $revisionId;
    }

    /** @param array<string,int> $allocations */
    private function storeMonthResult(
        int $revisionId,
        array $allocations,
        string $idempotencyKey,
    ): int {
        $withheld = array_sum($allocations);
        $income = $withheld + 10_000_00;
        $request = new EnforcementPersonMonthRequest(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            self::PAYMENT_DATE,
            [],
            true,
        );
        $input = new GarnishmentInput(
            self::PERIOD,
            self::PAYMENT_DATE,
            new GarnishableIncomeResult(
                GarnishmentStatus::Supported,
                $income,
                0,
                [],
                [],
            ),
            [],
            0,
            true,
            false,
            true,
            PensionEvidence::None,
            false,
            null,
            InsolvencyInstruction::none(),
            false,
            true,
        );
        $garnishmentAllocations = [];
        foreach ($allocations as $claimKey => $amount) {
            $garnishmentAllocations[] = new GarnishmentAllocation(
                (string) $claimKey,
                $amount,
                0,
            );
        }

        return $this->enforcement->store(
            $request,
            new PayrollGarnishmentCalculation(
                $this->supplierId,
                $this->employeeId,
                $input,
                new GarnishmentResult(
                    self::PERIOD,
                    GarnishmentStatus::Supported,
                    $income,
                    5_000_00,
                    2_000_00,
                    0,
                    0,
                    $withheld,
                    $income - $withheld,
                    false,
                    false,
                    $garnishmentAllocations,
                    [],
                    [],
                    'enforcement-2026',
                    str_repeat('e', 64),
                ),
            ),
            $revisionId,
            $idempotencyKey,
        );
    }

    private function storeInsolvencyMonthResult(
        int $revisionId,
        InsolvencyInstruction $instruction,
        int $amount,
        string $idempotencyKey,
    ): int {
        $income = $amount + 10_000_00;
        $request = new EnforcementPersonMonthRequest(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            self::PAYMENT_DATE,
            [],
            true,
        );
        $input = new GarnishmentInput(
            self::PERIOD,
            self::PAYMENT_DATE,
            new GarnishableIncomeResult(
                GarnishmentStatus::Supported,
                $income,
                0,
                [],
                [],
            ),
            [],
            0,
            true,
            false,
            true,
            PensionEvidence::None,
            false,
            null,
            $instruction,
            false,
            true,
        );

        return $this->enforcement->store(
            $request,
            new PayrollGarnishmentCalculation(
                $this->supplierId,
                $this->employeeId,
                $input,
                new GarnishmentResult(
                    self::PERIOD,
                    GarnishmentStatus::Supported,
                    $income,
                    5_000_00,
                    2_000_00,
                    0,
                    0,
                    $amount,
                    $income - $amount,
                    false,
                    true,
                    [new GarnishmentAllocation(
                        'insolvency-administrator',
                        0,
                        $amount,
                    )],
                    [],
                    [],
                    'enforcement-2026',
                    str_repeat('e', 64),
                ),
            ),
            $revisionId,
            $idempotencyKey,
        );
    }

    /** @return array<string,mixed> */
    private function saveApprovedInsolvencyEvidence(
        ?int $decisionDocumentId = null,
    ): array {
        $payload = $this->approvedInsolvencyPayload();
        if ($decisionDocumentId !== null) {
            $payload['insolvency_decision_document_id'] = $decisionDocumentId;
        }

        return $this->enforcement->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            self::PERIOD,
            $payload,
            $this->actorId,
            null,
        );
    }

    /** @return array<string,mixed> */
    private function approvedInsolvencyPayload(): array
    {
        return [
            'claim_register_evidence_complete' => true,
            'dependants_evidence_complete' => true,
            'spouse_evidence_complete' => true,
            'pension_evidence' => 'none',
            'has_multiple_payers' => false,
            'protected_amount_override_minor_units' => null,
            'protected_amount_override_verified' => false,
            'insolvency_mode' => 'approved_standard',
            'insolvency_decision_verified' => true,
            'insolvency_recipient_verified' => true,
            'insolvency_employment_id' => $this->employmentId,
            'insolvency_institution_account_id' => $this->recipientAccountId,
            'insolvency_decision_document_id' => $this->decisionDocumentId,
            'court_determined_amount_minor_units' => null,
        ];
    }

    private function countLiabilitiesFor(int $caseId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND liability_kind = "enforcement"
                AND liability_reference LIKE ?',
        );
        $statement->execute([$this->supplierId, "enforcement:c{$caseId}:cl%"]);

        return (int) $statement->fetchColumn();
    }

    private function institutionId(string $code): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_institutions
              WHERE supplier_id = ? AND institution_type = "other_recipient"
                AND institution_code = ?',
        );
        $statement->execute([$this->supplierId, $code]);

        return PayrollTimeValue::int($statement->fetchColumn(), 'institution_id');
    }

    private function institutionAccountId(string $code): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT account.id
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
              WHERE institution.supplier_id = ?
                AND institution.institution_type = "other_recipient"
                AND institution.institution_code = ?',
        );
        $statement->execute([$this->supplierId, $code]);

        return PayrollTimeValue::int(
            $statement->fetchColumn(),
            'institution_account_id',
        );
    }

    /** @return array<string,mixed> */
    private function liability(int $id): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $id]);

        return $this->row($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private function batchInstruction(int $batchId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT item_reference, instruction_ciphertext
               FROM payroll_payment_items
              WHERE supplier_id = ? AND batch_id = ?',
        );
        $statement->execute([$this->supplierId, $batchId]);
        $normalized = $this->row($statement->fetch(PDO::FETCH_ASSOC));
        $json = $this->encryption->decryptFor(
            $this->stringValue($normalized, 'instruction_ciphertext'),
            "payroll-payment-item:{$this->supplierId}:"
                . $this->stringValue($normalized, 'item_reference'),
        );

        return $this->row(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    private function createEmployee(int $supplierId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická povinná osoba", "employee", "hpp",
                     1, 1, 0, 42000, 0, 1)',
        )->execute([$supplierId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createEmployment(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_primary)
             VALUES (?, ?, "SYN-INS", "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 1)',
        )->execute([$this->supplierId, $this->employeeId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createDecisionDocument(
        int $supplierId,
        string $seed,
        string $scope = 'company',
    ): int
    {
        $hash = hash('sha256', "insolvency-decision:{$supplierId}:{$seed}");
        $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope, owner_user_id)
             VALUES (?, "Syntetické rozhodnutí oddlužení", "decision.pdf",
                     ?, ?, "application/pdf", 1, "pdf", "manual", ?, ?, ?)',
        )->execute([
            $supplierId,
            "{$hash}.pdf",
            $hash,
            $this->actorId,
            $scope,
            $scope === 'user' ? $this->actorId : null,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createPayerCurrency(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en,
                 decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, "CZK", "Syntetický CZK účet", "Kč",
                     "Česká koruna", "Czech koruna", 2, 1, 1,
                     "1000000005", "0100")',
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický exekuční uživatel",
                     "accountant", "cs", 1)',
        )->execute([
            'payroll-enforcement-' . bin2hex(random_bytes(6))
                . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                'Testovací databázový řádek není pole.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Testovací databázový řádek nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function stringValue(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function integerValue(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není číslo.",
            );
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není platné číslo.",
            );
        }

        return $integer;
    }
}
