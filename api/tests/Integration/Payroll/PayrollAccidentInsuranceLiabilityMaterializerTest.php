<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAccidentInsuranceRateRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Payment\PayrollAccidentInsuranceCalculator;
use MyInvoice\Service\Payroll\Payment\PayrollAccidentInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentQueryService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollAccidentInsuranceLiabilityMaterializerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAccidentInsuranceLiabilityMaterializer $materializer;
    private PayrollAccidentInsuranceRateRepository $rates;
    private int $supplierId;
    private int $actorId;
    private int $officeId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $sensitiveData = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitiveData);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $source = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $source);
        $sourceSupplierId = (int) $source->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->actorId = $this->createActor($pdo);
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->actorId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol,
                 is_active, row_version)
             VALUES (?, "VZOROV", "Syntetická úrazová účtárna", "0012345678", 1, 1)',
        )->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$this->supplierId, $this->officeId]);
        (new PayrollInstitutionAccountRepository(
            $connection,
            $sensitiveData,
            new PayrollInstitutionAccountDeletionRepository(
                $connection,
                new ActivityLogger($connection),
            ),
        ))->create($this->supplierId, [
            'institution_type' => 'statutory_insurance',
            'institution_code' => 'KOOP',
            'institution_name' => 'Syntetická Kooperativa',
            'bank_account' => '1000000009/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '9988776655',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:koop-account-notice',
            'verified_on' => '2026-01-05',
        ], $this->actorId);
        $this->rates = new PayrollAccidentInsuranceRateRepository($connection);
        $this->materializer = new PayrollAccidentInsuranceLiabilityMaterializer(
            new PayrollPaymentLiabilityRepository($connection),
            new PayrollStatutoryResultRepository($connection),
            $this->rates,
            new PayrollInstitutionAccountRepository(
                $connection,
                $sensitiveData,
                new PayrollInstitutionAccountDeletionRepository(
                    $connection,
                    new ActivityLogger($connection),
                ),
            ),
            $sensitiveData,
            $connection,
            new PayrollLevyDeadlinePolicy(),
            new PayrollAccidentInsuranceCalculator(),
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

    public function testMaterializesQuarterlyLiabilityFromThreeMonthsAndAppearsOnPaymentsList(): void
    {
        $this->rates->insert($this->supplierId, 'KOOP', '4.20', '2026-01-01', $this->actorId);
        $this->createMonth('2026-01-01', 20_000_00);
        $this->createMonth('2026-02-01', 30_000_00);
        $marchRevisionId = $this->createMonth('2026-03-01', 50_000_00);

        // Nekoncový měsíc čtvrtletí je no-op.
        $februaryRevisionId = $this->revisionIdFor('2026-02-01');
        $noop = $this->materializer->materialize(
            $this->supplierId,
            $februaryRevisionId,
            $this->actorId,
        );
        self::assertSame(0, $noop['created_count']);
        self::assertSame([], $noop['liability_ids']);

        $result = $this->materializer->materialize(
            $this->supplierId,
            $marchRevisionId,
            $this->actorId,
        );
        self::assertSame(1, $result['created_count']);
        self::assertCount(1, $result['liability_ids']);

        $replay = $this->materializer->materialize(
            $this->supplierId,
            $marchRevisionId,
            $this->actorId,
        );
        self::assertSame(0, $replay['created_count']);
        self::assertSame($result['liability_ids'], $replay['liability_ids']);

        $row = $this->liability($result['liability_ids'][0]);
        self::assertSame('statutory_insurance', $row['liability_kind']);
        self::assertSame('outgoing', $row['direction']);
        self::assertSame('2026-04-30', $row['due_on']);
        // (20 000 + 30 000 + 50 000) Kč × 4,20 ‰ = 420 Kč.
        self::assertSame(42_000, (int) $row['amount_minor']);

        $listed = (new PayrollPaymentQueryService($this->db))
            ->listForPeriod($this->supplierId, '2026-03')['items'];
        $accident = array_values(array_filter(
            $listed,
            static fn (array $item): bool => $item['liability_kind'] === 'statutory_insurance',
        ));
        self::assertCount(1, $accident);
        self::assertSame(42_000, $accident[0]['amount_minor']);
        self::assertSame('2026-04-30', $accident[0]['due_on']);
    }

    /**
     * Pod ročním maximem se oba základy rovnají — tenhle test je kontrolní
     * skupina pro tři následující. Kdyby padl, není chyba v maximu, ale
     * v základu jako takovém.
     */
    public function testBelowAnnualCapBothBasesAgree(): void
    {
        $this->rates->insert($this->supplierId, 'KOOP', '4.20', '2026-01-01', $this->actorId);
        $this->createMonth('2026-01-01', 20_000_00, 20_000_00);
        $this->createMonth('2026-02-01', 30_000_00, 30_000_00);
        $marchRevisionId = $this->createMonth('2026-03-01', 50_000_00, 50_000_00);

        $result = $this->materializer->materialize(
            $this->supplierId,
            $marchRevisionId,
            $this->actorId,
        );

        // 100 000 Kč × 4,20 ‰ = 420 Kč.
        self::assertSame(42_000, (int) $this->liability($result['liability_ids'][0])['amount_minor']);
    }

    /**
     * Měsíc, ve kterém se maximum překročí: sociální základ se usekne, základ
     * zákonného pojištění zůstává celý. Pojistné proto musí vyjít z vyšší
     * částky — právě tenhle rozdíl se dřív tiše ztrácel.
     */
    public function testMonthThatCrossesAnnualCapUsesUncappedBase(): void
    {
        $this->rates->insert($this->supplierId, 'KOOP', '4.20', '2026-01-01', $this->actorId);
        $this->createMonth('2026-01-01', 20_000_00, 20_000_00);
        $this->createMonth('2026-02-01', 30_000_00, 30_000_00);
        // Březen: do maxima se vejde jen 10 000 z 50 000.
        $marchRevisionId = $this->createMonth('2026-03-01', 50_000_00, 10_000_00);

        $result = $this->materializer->materialize(
            $this->supplierId,
            $marchRevisionId,
            $this->actorId,
        );

        // Ze zastropovaného základu by vyšlo (20+30+10) × 4,20 ‰ = 252 Kč.
        self::assertSame(42_000, (int) $this->liability($result['liability_ids'][0])['amount_minor']);
    }

    /**
     * Měsíce PO překročení maxima. Sociální základ je nulový, ale zaměstnanec
     * se do zákonného pojištění počítá dál celý rok — celé čtvrtletí by jinak
     * vyšlo na minimálních 100 Kč místo skutečného pojistného.
     */
    public function testMonthsAfterCapKeepFullLiabilityBaseEvenWhenSocialBaseIsZero(): void
    {
        $this->rates->insert($this->supplierId, 'KOOP', '4.20', '2026-01-01', $this->actorId);
        $this->createMonth('2026-04-01', 60_000_00, 0);
        $this->createMonth('2026-05-01', 60_000_00, 0);
        $juneRevisionId = $this->createMonth('2026-06-01', 60_000_00, 0);

        $result = $this->materializer->materialize(
            $this->supplierId,
            $juneRevisionId,
            $this->actorId,
        );

        // 180 000 Kč × 4,20 ‰ = 756 Kč. Ze zastropovaného základu by zbylo
        // minimální čtvrtletní pojistné 100 Kč.
        self::assertSame(75_600, (int) $this->liability($result['liability_ids'][0])['amount_minor']);
    }

    /**
     * Reset maxima od ledna. Prosincové čtvrtletí jede na useknutém sociálním
     * základu, lednové už zase na plném — a základ zákonného pojištění je
     * v obou stejný, protože roční maximum se ho netýká vůbec.
     */
    public function testAnnualCapResetInJanuaryDoesNotChangeLiabilityBase(): void
    {
        $this->rates->insert($this->supplierId, 'KOOP', '4.20', '2026-01-01', $this->actorId);
        $this->createMonth('2026-10-01', 60_000_00, 0);
        $this->createMonth('2026-11-01', 60_000_00, 0);
        $decemberRevisionId = $this->createMonth('2026-12-01', 60_000_00, 0);
        $december = $this->materializer->materialize(
            $this->supplierId,
            $decemberRevisionId,
            $this->actorId,
        );

        $this->createMonth('2027-01-01', 60_000_00, 60_000_00);
        $this->createMonth('2027-02-01', 60_000_00, 60_000_00);
        $marchRevisionId = $this->createMonth('2027-03-01', 60_000_00, 60_000_00);
        $march = $this->materializer->materialize(
            $this->supplierId,
            $marchRevisionId,
            $this->actorId,
        );

        self::assertSame(
            (int) $this->liability($december['liability_ids'][0])['amount_minor'],
            (int) $this->liability($march['liability_ids'][0])['amount_minor'],
        );
    }

    /**
     * Otisk musí prozradit, kterým pravidlem závazek vznikl. Bez toho by po
     * opravě výkladu nešlo v datech rozeznat čtvrtletí spočtená ze
     * zastropovaného základu od těch správných.
     */
    public function testSourceSnapshotNamesTheLiabilityBaseAndItsSchema(): void
    {
        $this->rates->insert($this->supplierId, 'KOOP', '4.20', '2026-01-01', $this->actorId);
        $this->createMonth('2026-01-01', 20_000_00, 20_000_00);
        $this->createMonth('2026-02-01', 30_000_00, 30_000_00);
        $marchRevisionId = $this->createMonth('2026-03-01', 50_000_00, 10_000_00);

        $result = $this->materializer->materialize(
            $this->supplierId,
            $marchRevisionId,
            $this->actorId,
        );
        $source = json_decode(
            (string) $this->liability($result['liability_ids'][0])['source_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('payroll-payment-accident-insurance-source.v2', $source['schema_reference']);
        self::assertSame(100_000_00, $source['employer_liability_assessment_base_minor_units']);
        self::assertArrayNotHasKey('assessment_base_minor_units', $source);
    }

    public function testAppliesMinimumQuarterlyPremiumWhenComputedAmountIsLower(): void
    {
        $this->rates->insert($this->supplierId, 'KOOP', '0.10', '2026-01-01', $this->actorId);
        $this->createMonth('2026-01-01', 1_000_00);
        $this->createMonth('2026-02-01', 1_000_00);
        $marchRevisionId = $this->createMonth('2026-03-01', 1_000_00);

        $result = $this->materializer->materialize(
            $this->supplierId,
            $marchRevisionId,
            $this->actorId,
        );

        $row = $this->liability($result['liability_ids'][0]);
        self::assertSame(
            PayrollAccidentInsuranceCalculator::MINIMUM_QUARTERLY_PREMIUM_MINOR,
            (int) $row['amount_minor'],
        );
    }

    public function testFailsClosedWhenAnEarlierMonthOfTheQuarterIsMissing(): void
    {
        $this->rates->insert($this->supplierId, 'KOOP', '4.20', '2026-01-01', $this->actorId);
        // Leden se záměrně vynechává.
        $this->createMonth('2026-02-01', 30_000_00);
        $marchRevisionId = $this->createMonth('2026-03-01', 50_000_00);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/2026-01-01/');
        $this->materializer->materialize(
            $this->supplierId,
            $marchRevisionId,
            $this->actorId,
        );
    }

    public function testFailsClosedWhenRateIsNotConfigured(): void
    {
        $this->createMonth('2026-01-01', 20_000_00);
        $this->createMonth('2026-02-01', 30_000_00);
        $marchRevisionId = $this->createMonth('2026-03-01', 50_000_00);

        $this->expectException(\DomainException::class);
        $this->materializer->materialize(
            $this->supplierId,
            $marchRevisionId,
            $this->actorId,
        );
    }

    private function revisionIdFor(string $periodStart): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ? AND run.period_start = ?',
        );
        $statement->execute([$this->supplierId, $periodStart]);
        $id = $statement->fetchColumn();
        self::assertNotFalse($id);

        return (int) $id;
    }

    /**
     * Oba základy zvlášť. Zákonné pojištění odpovědnosti stojí na
     * `participating_…` (bez ročního maxima podle § 15a), sociální pojištění na
     * `capped_…`. Dokud si je pomocník držel stejné, nemohl žádný test poznat,
     * že materializér bere ten druhý.
     */
    private function createMonth(
        string $periodStart,
        int $participatingAssessmentBaseMinor,
        ?int $cappedAssessmentBaseMinor = null,
    ): int {
        $cappedAssessmentBaseMinor ??= $participatingAssessmentBaseMinor;
        $pdo = $this->db->pdo();
        $paymentDate = (new \DateTimeImmutable($periodStart))
            ->modify('+1 month')->modify('10 days')->format('Y-m-d');
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, office_id, period_start, payment_date,
                 status, current_revision_no)
             VALUES (?, ?, ?, ?, "approved", 1)',
        )->execute([$this->supplierId, $this->officeId, $periodStart, $paymentDate]);
        $runId = (int) $pdo->lastInsertId();

        $input = json_encode([
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => $periodStart,
            'payment_date' => $paymentDate,
            'office_id' => $this->officeId,
            'people' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $result = '{"schema_version":"payroll-run-result.v2"}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, NULL, "regular", "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash('sha256', "synthetic-accident-revision:{$runId}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $socialResult = [
            'calculation_date' => (new \DateTimeImmutable($periodStart))
                ->modify('last day of this month')->format('Y-m-d'),
            'status' => 'calculated',
            'participating_assessment_base_minor_units' => $participatingAssessmentBaseMinor,
            'capped_assessment_base_minor_units' => $cappedAssessmentBaseMinor,
            'employee_contribution_minor_units' => 0,
            'employer_contribution_before_discount_minor_units' => 0,
            'part_time_discount_assessment_base_minor_units' => 0,
            'part_time_discount_minor_units' => 0,
            'employer_contribution_minor_units' => 0,
            'issues' => [],
            'ruleset_id' => 'cz-social-2026',
            'ruleset_hash' => str_repeat('b', 64),
        ];
        (new PayrollStatutoryResultRepository($this->db))->store(
            $this->supplierId,
            $revisionId,
            'social_insurance',
            'payroll-social-result.v1',
            'calculated',
            'cz-social-2026',
            str_repeat('b', 64),
            json_decode($input, true, flags: JSON_THROW_ON_ERROR),
            $socialResult,
            [],
            $this->actorId,
        );

        return $revisionId;
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický úrazový uživatel",
                     "accountant", "cs", 1)',
        )->execute([
            'payroll-accident-' . bin2hex(random_bytes(6)) . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function liability(int $id): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_payment_liabilities WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
