<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementConflictException;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementRepository;
use MyInvoice\Repository\Payroll\PayrollNetRepository;
use MyInvoice\Service\Payroll\Net\DeductionAgreementCommand;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use MyInvoice\Service\Payroll\Net\NetRelationshipIncome;
use MyInvoice\Service\Payroll\Net\PayrollDeductionRequest;
use MyInvoice\Service\Payroll\Net\PayrollNetCalculator;
use MyInvoice\Service\Payroll\Net\PayrollNetInput;
use MyInvoice\Service\Payroll\Net\PayrollNetResultQueryService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollDeductionAgreementLifecycleTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollDeductionAgreementRepository $repository;
    private PayrollNetRepository $net;
    private PayrollNetResultQueryService $results;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private int $runId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        $repository = $container->get(PayrollDeductionAgreementRepository::class);
        $net = $container->get(PayrollNetRepository::class);
        $results = $container->get(PayrollNetResultQueryService::class);
        if (!$db instanceof Connection
            || !$repository instanceof PayrollDeductionAgreementRepository
            || !$net instanceof PayrollNetRepository
            || !$results instanceof PayrollNetResultQueryService
        ) {
            throw new \RuntimeException('Služby dohod o srážkách nejsou dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_deduction_agreements',
            'payroll_deduction_agreement_versions',
            'payroll_deduction_ledger',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped('Migrace 1310 neproběhla.');
            }
        }
        $this->repository = $repository;
        $this->net = $net;
        $this->results = $results;

        $pdo = $db->pdo();
        $stmt = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $sourceSupplierId = $stmt === false ? 0 : (int) $stmt->fetchColumn();
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->createEmployee($pdo, $this->supplierId, 'Synteticka Osoba A');
        $this->otherEmployeeId = $this->createEmployee($pdo, $this->supplierId, 'Synteticka Osoba B');
        $this->runId = $this->createRun($pdo, $this->supplierId);
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

    public function testChangingAgreementAfterApprovedPayrollLeavesHistoricalResultIntact(): void
    {
        $agreement = $this->createAgreement('Stravenky', 50_000);
        $revisionId = $this->approveRevision([
            $this->personFixture($this->employeeId, 'Synteticka Osoba A', $agreement, 50_000, 7),
        ]);

        $before = $this->results->breakdown($this->supplierId, $revisionId, $this->employeeId);
        self::assertSame('Stravenky', $before['deductions'][0]['title']);
        self::assertSame(50_000, $before['deductions'][0]['applied_minor']);

        $updated = $this->repository->update(
            $this->supplierId,
            (int) $agreement['id'],
            DeductionAgreementTerms::fromRequest([
                'agreement_reference' => $agreement['agreement_reference'],
                'title' => 'Stravenky — nová sazba',
                'deduction_kind' => 'meal',
                'priority_no' => 100,
                'requested_minor' => 90_000,
                'valid_from' => '2026-06-01',
            ]),
            (int) $agreement['row_version'],
            '2026-07-01',
            'Změna ceny stravenek',
            null,
        );

        $after = $this->results->breakdown($this->supplierId, $revisionId, $this->employeeId);
        self::assertSame($before, $after);
        self::assertSame(90_000, $updated['requested_minor']);
        self::assertSame(2, $updated['version_no']);
        self::assertCount(2, $updated['versions']);
        self::assertSame('created', $updated['versions'][0]['change_kind']);
        self::assertSame('Stravenky', $updated['versions'][0]['title']);
        self::assertSame('updated', $updated['versions'][1]['change_kind']);
        self::assertSame('Stravenky — nová sazba', $updated['versions'][1]['title']);
        self::assertSame('2026-07-01', $updated['versions'][1]['effective_from']);
    }

    public function testPauseAndEndStopFutureDeductionsButKeepLedgerHistory(): void
    {
        $agreement = $this->createAgreement('Spoření', 20_000);
        $agreementId = (int) $agreement['id'];
        $revisionId = $this->approveRevision([
            $this->personFixture($this->employeeId, 'Synteticka Osoba A', $agreement, 20_000, 7),
        ]);
        $this->net->appendLedgerMovement(
            $this->supplierId,
            $agreementId,
            $revisionId,
            $this->employeeId,
            'withheld',
            20_000,
            "test-deduction:{$revisionId}:{$agreementId}",
            null,
            ['source' => 'test'],
            null,
        );

        $current = $this->repository->find($this->supplierId, $agreementId);
        self::assertNotNull($current);
        self::assertSame(20_000, $current['withheld_total_minor']);

        $paused = $this->repository->transition(
            $this->supplierId,
            $agreementId,
            DeductionAgreementCommand::Pause,
            (int) $current['row_version'],
            '2026-07-01',
            null,
            null,
        );
        self::assertSame('paused', $paused['status']);
        self::assertFalse($paused['enters_payroll_run']);

        $ended = $this->repository->transition(
            $this->supplierId,
            $agreementId,
            DeductionAgreementCommand::End,
            (int) $paused['row_version'],
            '2026-07-31',
            'Doplaceno',
            null,
        );
        self::assertSame('ended', $ended['status']);
        self::assertSame('2026-07-31', $ended['valid_to']);
        self::assertSame(20_000, $ended['withheld_total_minor']);
        self::assertCount(1, $ended['ledger']);
        self::assertSame(20_000, $ended['ledger'][0]['amount_minor']);
    }

    public function testAgreementWithLedgerHistoryCannotBeCancelled(): void
    {
        $agreement = $this->createAgreement('Náhrada škody', 15_000);
        $agreementId = (int) $agreement['id'];
        $revisionId = $this->approveRevision([
            $this->personFixture($this->employeeId, 'Synteticka Osoba A', $agreement, 15_000, 7),
        ]);
        $this->net->appendLedgerMovement(
            $this->supplierId,
            $agreementId,
            $revisionId,
            $this->employeeId,
            'withheld',
            15_000,
            "test-cancel:{$revisionId}:{$agreementId}",
            null,
            ['source' => 'test'],
            null,
        );
        $current = $this->repository->find($this->supplierId, $agreementId);
        self::assertNotNull($current);

        $this->expectException(\DomainException::class);
        $this->repository->transition(
            $this->supplierId,
            $agreementId,
            DeductionAgreementCommand::Cancel,
            (int) $current['row_version'],
            null,
            null,
            null,
        );
    }

    public function testLimitCannotDropBelowAlreadyWithheldAmount(): void
    {
        $agreement = $this->createAgreement('Záloha', 30_000, 100_000);
        $agreementId = (int) $agreement['id'];
        $revisionId = $this->approveRevision([
            $this->personFixture($this->employeeId, 'Synteticka Osoba A', $agreement, 30_000, 7),
        ]);
        $this->net->appendLedgerMovement(
            $this->supplierId,
            $agreementId,
            $revisionId,
            $this->employeeId,
            'withheld',
            30_000,
            "test-limit:{$revisionId}:{$agreementId}",
            null,
            ['source' => 'test'],
            null,
        );
        $current = $this->repository->find($this->supplierId, $agreementId);
        self::assertNotNull($current);
        self::assertSame(70_000, $current['remaining_limit_minor']);

        $this->expectException(\DomainException::class);
        $this->repository->update(
            $this->supplierId,
            $agreementId,
            DeductionAgreementTerms::fromRequest([
                'agreement_reference' => $current['agreement_reference'],
                'title' => 'Záloha',
                'deduction_kind' => 'advance',
                'priority_no' => 100,
                'requested_minor' => 30_000,
                'total_limit_minor' => 10_000,
                'valid_from' => '2026-06-01',
            ]),
            (int) $current['row_version'],
            null,
            null,
            null,
        );
    }

    public function testStaleRowVersionIsRejectedInsteadOfLastWriteWins(): void
    {
        $agreement = $this->createAgreement('Příspěvek', 10_000);
        $agreementId = (int) $agreement['id'];
        $terms = DeductionAgreementTerms::fromRequest([
            'agreement_reference' => $agreement['agreement_reference'],
            'title' => 'Příspěvek na penzijní připojištění',
            'deduction_kind' => 'contribution',
            'priority_no' => 100,
            'requested_minor' => 11_000,
            'valid_from' => '2026-06-01',
        ]);
        $this->repository->update(
            $this->supplierId,
            $agreementId,
            $terms,
            (int) $agreement['row_version'],
            null,
            null,
            null,
        );

        try {
            $this->repository->update(
                $this->supplierId,
                $agreementId,
                $terms,
                (int) $agreement['row_version'],
                null,
                null,
                null,
            );
            self::fail('Zastaralá row_version měla skončit konfliktem.');
        } catch (PayrollDeductionAgreementConflictException $e) {
            self::assertSame(2, $e->currentVersion);
        }

        $stored = $this->repository->find($this->supplierId, $agreementId);
        self::assertNotNull($stored);
        self::assertSame(2, $stored['version_no']);
    }

    public function testVoluntaryAgreementCannotClaimStatutoryPriorityBand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeductionAgreementTerms::fromRequest([
            'title' => 'Obcházení pořadí',
            'deduction_kind' => 'other',
            'priority_no' => 1,
            'requested_minor' => 1_000,
            'valid_from' => '2026-06-01',
        ]);
    }

    public function testPercentageIsResolvedIntoDeterministicFixedAmount(): void
    {
        $agreement = $this->repository->create(
            $this->supplierId,
            $this->employeeId,
            DeductionAgreementTerms::fromRequest([
                'title' => 'Spoření 10 %',
                'deduction_kind' => 'contribution',
                'priority_no' => 120,
                'basis_points' => 1_000,
                'basis_amount_minor' => 3_450_000,
                'valid_from' => '2026-06-01',
            ]),
            DeductionAgreementStatus::Active,
            null,
        );

        self::assertSame(1_000, $agreement['basis_points']);
        self::assertSame(3_450_000, $agreement['basis_amount_minor']);
        self::assertSame(345_000, $agreement['requested_minor']);
    }

    public function testAgreementsAreScopedToTheirTenant(): void
    {
        $agreement = $this->createAgreement('Stravenky', 50_000);
        $agreementId = (int) $agreement['id'];

        self::assertNull($this->repository->find($this->otherSupplierId, $agreementId));
        $foreignPage = $this->repository->listAgreements($this->otherSupplierId);
        self::assertSame([], $foreignPage['items']);
        self::assertSame(0, $foreignPage['total']);

        $this->expectException(\OutOfBoundsException::class);
        $this->repository->transition(
            $this->otherSupplierId,
            $agreementId,
            DeductionAgreementCommand::Pause,
            (int) $agreement['row_version'],
            null,
            null,
            null,
        );
    }

    public function testNetResultApiExposesOnlyTheRequestedPersonAndMaskedAccounts(): void
    {
        $agreementA = $this->createAgreement('Stravenky', 50_000);
        $agreementB = $this->createAgreement(
            'Spoření',
            20_000,
            null,
            $this->otherEmployeeId,
        );
        $revisionId = $this->approveRevision([
            $this->personFixture($this->employeeId, 'Synteticka Osoba A', $agreementA, 50_000, 7),
            $this->personFixture(
                $this->otherEmployeeId,
                'Synteticka Osoba B',
                $agreementB,
                20_000,
                8,
            ),
        ]);

        $breakdown = $this->results->breakdown(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
        );
        $encoded = CanonicalJson::encode($breakdown);

        self::assertSame($this->employeeId, $breakdown['person']['employee_id']);
        self::assertStringNotContainsString('Synteticka Osoba B', $encoded);
        self::assertStringNotContainsString('Spoření', $encoded);
        self::assertStringNotContainsString(str_repeat('a', 64), $encoded);
        self::assertStringNotContainsString('bank_account_hash', $encoded);
        self::assertStringNotContainsString('1000000005/0100', $encoded);
        self::assertSame('****0005/0100', $breakdown['allocations'][0]['destination_masked']);
        self::assertSame(7, $breakdown['allocations'][0]['payout_account_id']);
    }

    public function testPayoutAllocationsSumExactlyToNetPay(): void
    {
        $agreement = $this->createAgreement('Stravenky', 50_000);
        $revisionId = $this->approveRevision([
            $this->personFixture(
                $this->employeeId,
                'Synteticka Osoba A',
                $agreement,
                50_000,
                7,
                splitPayout: true,
            ),
        ]);

        $breakdown = $this->results->breakdown(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
        );

        $sum = 0;
        foreach ($breakdown['allocations'] as $allocation) {
            $sum += $allocation['amount_minor'];
        }
        self::assertCount(2, $breakdown['allocations']);
        self::assertSame($breakdown['payable_after_enforcement_minor'], $sum);
        self::assertSame($breakdown['allocations_total_minor'], $sum);
        self::assertSame($breakdown['net_payable_minor'], $sum);
    }

    /**
     * Nesražená dohoda bez důvodu je nečitelná: „nevešlo se to do nezabavitelné
     * částky" se řeší penězi, kdežto „nezabavitelná částka stojí na nedoloženém
     * nároku" se řeší doložením nároku. V číslech vypadají obě stejně, takže
     * rozklad musí nést i rozsah evidence ze zmrazeného snímku.
     */
    public function testNetResultCarriesTheFrozenEnforcementEvidenceScope(): void
    {
        $agreement = $this->createAgreement('Stravenky', 50_000);
        $revisionId = $this->approveRevision([
            $this->personFixture(
                $this->employeeId,
                'Synteticka Osoba A',
                $agreement,
                50_000,
                7,
                evidenceSource: [
                    'claim_register' => 'not_applicable',
                    'dependants' => 'nothing_withheld',
                    'spouse' => 'not_applicable',
                ],
            ),
        ]);

        $breakdown = $this->results->breakdown(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
        );

        self::assertSame([
            'claim_register' => 'not_applicable',
            'dependants' => 'nothing_withheld',
            'spouse' => 'not_applicable',
        ], $breakdown['enforcement_evidence_source']);
    }

    /**
     * Revizi spočtenou dřív, než se rozsah začal ukládat, se dopočítat NESMÍ:
     * tehdejší kód evidenci vyžadoval bezpodmínečně, takže o jejím rozsahu
     * netvrdil nic. Obrazovka o důvodu radši mlčí, než aby si nějaký domyslela.
     */
    public function testNetResultLeavesTheScopeUnsetForOlderRevisions(): void
    {
        $agreement = $this->createAgreement('Stravenky', 50_000);
        $revisionId = $this->approveRevision([
            $this->personFixture($this->employeeId, 'Synteticka Osoba A', $agreement, 50_000, 7),
        ]);

        $breakdown = $this->results->breakdown(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
        );

        self::assertArrayHasKey('enforcement_evidence_source', $breakdown);
        self::assertNull($breakdown['enforcement_evidence_source']);
    }

    /**
     * Nález E-17: pozastavená dohoda nesmí v rozkladu vypadat jako schodek.
     *
     * `unapplied_minor_units` ve zmrazeném snímku je ÚČETNÍ zbytek, na kterém
     * stojí invariant `unapplied === requested − applied` — u pozastavené dohody
     * se proto rovná celé nárokované částce. Read model to vypisoval syrově,
     * takže obrazovka tvrdila „neuplatněno 500 Kč", ačkoli se v tom měsíci
     * srážet vůbec nemělo. Schodek vůči věřiteli je nula; účetní zbytek se
     * vydává zvlášť, aby se dal invariant ověřit i z odpovědi.
     */
    public function testSuspendedDeductionReportsNoShortfallInTheReadModel(): void
    {
        $agreement = $this->createAgreement('Stravenky', 50_000);
        $revisionId = $this->approveRevision([
            $this->personFixture(
                $this->employeeId,
                'Synteticka Osoba A',
                $agreement,
                50_000,
                7,
                activeDeduction: false,
            ),
        ]);

        $breakdown = $this->results->breakdown(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
        );

        self::assertCount(1, $breakdown['deductions']);
        $deduction = $breakdown['deductions'][0];
        self::assertFalse($deduction['active']);
        self::assertSame(50_000, $deduction['requested_minor']);
        self::assertSame(0, $deduction['applied_minor']);
        self::assertSame(0, $deduction['unapplied_minor']);
        self::assertSame(50_000, $deduction['accounting_unapplied_minor']);
    }

    /**
     * Aktivní dohoda, na kterou nezbylo, schodek vykázat MUSÍ — jinak by oprava
     * E-17 umlčela i případ, kvůli kterému údaj existuje.
     */
    public function testActiveDeductionStillReportsItsShortfall(): void
    {
        $agreement = $this->createAgreement('Stravenky', 5_000_000);
        $revisionId = $this->approveRevision([
            $this->personFixture(
                $this->employeeId,
                'Synteticka Osoba A',
                $agreement,
                5_000_000,
                7,
            ),
        ]);

        $breakdown = $this->results->breakdown(
            $this->supplierId,
            $revisionId,
            $this->employeeId,
        );

        $deduction = $breakdown['deductions'][0];
        self::assertTrue($deduction['active']);
        self::assertGreaterThan(0, $deduction['unapplied_minor']);
        self::assertSame(
            $deduction['accounting_unapplied_minor'],
            $deduction['unapplied_minor'],
        );
        self::assertSame(
            $deduction['requested_minor'] - $deduction['applied_minor'],
            $deduction['accounting_unapplied_minor'],
        );
    }

    public function testNetResultApiRejectsPersonOutsideTheRevision(): void
    {
        $agreement = $this->createAgreement('Stravenky', 50_000);
        $revisionId = $this->approveRevision([
            $this->personFixture($this->employeeId, 'Synteticka Osoba A', $agreement, 50_000, 7),
        ]);

        $this->expectException(\OutOfBoundsException::class);
        $this->results->breakdown($this->supplierId, $revisionId, $this->otherEmployeeId);
    }

    /** @return array<string,mixed> */
    private function createAgreement(
        string $title,
        int $requestedMinor,
        ?int $limitMinor = null,
        ?int $employeeId = null,
    ): array {
        return $this->repository->create(
            $this->supplierId,
            $employeeId ?? $this->employeeId,
            DeductionAgreementTerms::fromRequest([
                'title' => $title,
                'deduction_kind' => match ($title) {
                    'Stravenky' => 'meal',
                    'Záloha' => 'advance',
                    'Náhrada škody' => 'damage',
                    'Příspěvek' => 'contribution',
                    default => 'other',
                },
                'priority_no' => 100,
                'requested_minor' => $requestedMinor,
                'total_limit_minor' => $limitMinor,
                'valid_from' => '2026-06-01',
            ]),
            DeductionAgreementStatus::Active,
            null,
        );
    }

    /**
     * @param array<string,mixed> $agreement
     * @param array<string,string>|null $evidenceSource `null` = starší revize,
     *        která rozsah exekuční evidence vůbec neukládala.
     * @return array{input:array<string,mixed>,result:array<string,mixed>}
     */
    private function personFixture(
        int $employeeId,
        string $fullName,
        array $agreement,
        int $requestedMinor,
        int $accountId,
        bool $splitPayout = false,
        ?array $evidenceSource = null,
        bool $activeDeduction = true,
    ): array {
        // Kanonický tvar reference z pipeline. Fixtura tu dřív psala holé id,
        // takže rozklad čisté mzdy vycházel zeleně na tvaru, jaký ostrý běh
        // nikdy nevyrobí — a v produkci padal na kontrole identity osoby.
        $net = (new PayrollNetCalculator())->calculate(new PayrollNetInput(
            personReference: "employee:{$employeeId}",
            relationships: [
                new NetRelationshipIncome("employment-{$employeeId}", 1_000_000, 0),
            ],
            employeeSocialMinorUnits: 71_000,
            employeeHealthMinorUnits: 45_000,
            advanceTaxMinorUnits: 80_000,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            correctionMinorUnits: 0,
            voluntaryDeductionCapacityMinorUnits: 804_000,
            deductions: [
                new PayrollDeductionRequest(
                    'agreement:' . (int) $agreement['id'],
                    100,
                    $requestedMinor,
                    null,
                    $activeDeduction,
                ),
            ],
        ));

        $rules = [[
            'id' => $accountId * 10,
            'allocation_reference' => 'primary',
            'destination_kind' => 'bank',
            'destination_reference' => "account:{$accountId}",
            'allocation_kind' => 'remainder',
            'amount_minor' => null,
            'basis_points' => null,
            'priority_no' => 100,
            'row_version' => 1,
        ]];
        if ($splitPayout) {
            array_unshift($rules, [
                'id' => $accountId * 10 + 1,
                'allocation_reference' => 'cash-part',
                'destination_kind' => 'cash',
                'destination_reference' => null,
                'allocation_kind' => 'fixed',
                'amount_minor' => 100_003,
                'basis_points' => null,
                'priority_no' => 50,
                'row_version' => 1,
            ]);
        }

        return [
            'input' => [
                'employee' => [
                    'id' => $employeeId,
                    'full_name' => $fullName,
                    'profile_status' => 'complete',
                    'is_active' => true,
                ],
                'deduction_agreements' => [[
                    'id' => (int) $agreement['id'],
                    'agreement_reference' => (string) $agreement['agreement_reference'],
                    'title' => (string) $agreement['title'],
                    'deduction_kind' => (string) $agreement['deduction_kind'],
                    'priority_no' => 100,
                    'requested_minor' => $requestedMinor,
                    'total_limit_minor' => $agreement['total_limit_minor'],
                    'withheld_total_minor' => 0,
                    'valid_from' => '2026-06-01',
                    'valid_to' => null,
                    'row_version' => (int) $agreement['row_version'],
                ]],
                'payout_rules' => $rules,
                'payout_accounts' => [[
                    'id' => $accountId,
                    'label' => "Účet {$accountId}",
                    'bank_account_hash' => str_repeat('a', 64),
                    'bank_account_masked' => '****0005/0100',
                    'allocation_basis_points' => 10_000,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'verification_source' => 'user_verified',
                    'verified_on' => '2026-01-02',
                    'verified_by' => 1,
                ]],
                'employments' => [],
            ],
            'result' => [
                'employee_id' => $employeeId,
                'payable_after_enforcement_minor' => $net->netPayableMinorUnits,
                'statutory' => [
                    'status' => 'calculated',
                    'net_payable_minor_units' => $net->netPayableMinorUnits,
                    'net_pay' => $net->jsonSerialize(),
                ],
            ] + ($evidenceSource === null ? [] : [
                'enforcement' => ['result' => ['evidence_source' => $evidenceSource]],
            ]),
        ];
    }

    /**
     * @param list<array{input:array<string,mixed>,result:array<string,mixed>}> $people
     */
    private function approveRevision(array $people): int
    {
        $input = ['people' => array_map(
            static fn (array $person): array => $person['input'],
            $people,
        )];
        $result = ['people' => array_map(
            static fn (array $person): array => $person['result'],
            $people,
        )];
        $inputJson = CanonicalJson::encode($input);
        $resultJson = CanonicalJson::encode($result);

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "regular", "approved", "payroll-run-input.v1",
                     ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->runId,
            $this->nextRevisionNo(),
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            random_bytes(32),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function nextRevisionNo(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(revision_no), 0) + 1 FROM payroll_run_revisions
              WHERE supplier_id = ? AND run_id = ?'
        );
        $stmt->execute([$this->supplierId, $this->runId]);

        return (int) $stmt->fetchColumn();
    }

    private function createEmployee(PDO $pdo, int $supplierId, string $fullName): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 10000, 0, 1)'
        )->execute([$supplierId, $fullName]);

        return (int) $pdo->lastInsertId();
    }

    private function createRun(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2026-06-01", "2026-06-30")'
        )->execute([$supplierId]);

        return (int) $pdo->lastInsertId();
    }
}
