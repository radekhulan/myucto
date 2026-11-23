<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollTaxDeclarationBulkSignAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\PayrollTaxDeclarationBulkSign;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadný zápis podepsaných prohlášení poplatníka.
 *
 * Hlídá, že zápis jde stejnou cestou jako karta osoby, nepřepíše podepsané
 * prohlášení, nesáhne do schváleného období ani k osobě jiné firmy a že výpočet
 * daně z hromadně zapsaného podpisu vychází stejně jako z ručního. Pouze
 * syntetická data.
 */
#[Group('integration')]
final class PayrollTaxDeclarationBulkSignTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2026;
    private const SIGNED_ON = '2026-04-10';

    private Connection $db;
    private PayrollTaxDeclarationBulkSign $bulk;
    private PayrollTaxDeclarationBulkSignAction $action;
    private PayrollPersonStatutoryEvidenceRepository $repository;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->bulk = $container->get(PayrollTaxDeclarationBulkSign::class);
            $this->action = $container->get(PayrollTaxDeclarationBulkSignAction::class);
            $this->repository = $container->get(PayrollPersonStatutoryEvidenceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('payroll_person_tax_declarations')) {
            $this->markTestSkipped('Mzdové migrace neproběhly.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
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

    /**
     * § 38k odst. 7 ZDP: stávající zaměstnanec, který podepíše po 15. 2.,
     * má slevu až od měsíce následujícího po podpisu.
     */
    public function testContinuingEmployeeSigningDuringTheYearStartsTheFollowingMonth(): void
    {
        $employeeId = $this->person();

        $preview = $this->bulk->preview($this->supplierId, self::YEAR, self::SIGNED_ON, [$employeeId]);
        self::assertSame('2026-05-01', $preview['effective_from']);
        self::assertSame('following_month', $preview['effective_from_basis']);
        self::assertSame([$employeeId], $preview['ready_employee_ids']);
        self::assertSame('add', $preview['people'][0]['change']);
        self::assertSame('2026-05-01', $preview['people'][0]['effective_from']);

        $result = $this->apply([$employeeId]);

        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts']);
        $rows = $this->declarations($employeeId);
        self::assertCount(1, $rows);
        self::assertSame('signed', $rows[0]['status']);
        self::assertSame('2026-05-01', $rows[0]['effective_from']);
        self::assertNull($rows[0]['effective_to']);
        self::assertSame('declaration:38k-signed', $rows[0]['evidence_reference']);
        self::assertStringStartsWith('Prohlášení poplatníka podepsáno 10. 4. 2026', (string) $rows[0]['evidence_note']);

        // Sleva na poplatníka plyne z podpisu — stejné pravidlo jako karta.
        $view = $this->repository->editorView($this->supplierId, $employeeId, '2026-05-01');
        self::assertNotNull($view);
        self::assertTrue($view['derived']['taxpayer_credit']);
        self::assertSame([], $view['sections']['tax_credit_claims']);
        self::assertSame(1, $this->activityCount('payroll.person_statutory_evidence.bulk_declaration_signed'));
        self::assertSame(1, $this->activityCount('payroll.person_statutory_evidence.saved'));
    }

    public function testSignatureBeforeTheYearStartsInJanuary(): void
    {
        $employeeId = $this->person();

        $preview = $this->bulk->preview($this->supplierId, self::YEAR, '2025-12-15', [$employeeId]);

        self::assertSame('2026-01-01', $preview['effective_from']);
        self::assertSame('year_start', $preview['effective_from_basis']);
    }

    /**
     * § 38k odst. 1 a 4 ZDP: brigádník na DPP nastoupí 25. 6. a prohlášení
     * podepíše 2. 7., tedy do 30 dnů po nástupu. Za červen se proto daní
     * zálohou se slevou, ne srážkou.
     */
    public function testDppStarterSigningWithinThirtyDaysGetsTheStartMonth(): void
    {
        $employeeId = $this->person(relationType: 'dpp', start: '2026-06-25');

        $result = $this->apply([$employeeId], '2026-07-02');

        self::assertSame(1, $result['counts']['applied']);
        self::assertSame('2026-06-01', $result['applied'][0]['effective_from']);
        self::assertSame('employment_start', $result['applied'][0]['effective_from_basis']);
        self::assertFalse($result['applied'][0]['annual_settlement']);
        $result = $this->dppResult($employeeId, '2026-06-30');
        self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime);
        self::assertSame(0, $result->withholdingTaxMinorUnits);
        $view = $this->repository->editorView($this->supplierId, $employeeId, '2026-06-01');
        self::assertNotNull($view);
        self::assertTrue($view['derived']['taxpayer_credit']);
    }

    public function testStarterSigningAfterThirtyDaysStartsTheFollowingMonth(): void
    {
        $employeeId = $this->person(relationType: 'dpp', start: '2026-06-25');

        $result = $this->apply([$employeeId], '2026-07-27');

        self::assertSame('2026-08-01', $result['applied'][0]['effective_from']);
        self::assertSame('following_month', $result['applied'][0]['effective_from_basis']);
        self::assertNull($this->repository->snapshot($this->supplierId, $employeeId, '2026-07-01')['income_tax']['declaration'] ?? null);
    }

    /** Karta osoby větu uloží, ale upozorní, že začíná dřív, než zákon dovoluje. */
    public function testEditorWarnsAboutRowStartingBeforeTheLegalMonth(): void
    {
        $employeeId = $this->person(relationType: 'dpp', start: '2026-06-25');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, signed_on, effective_from, evidence_reference)
             VALUES (?, ?, "signed", "2026-07-27", "2026-06-01", "declaration:38k-signed")'
        )->execute([$this->supplierId, $employeeId]);

        $view = $this->repository->editorView($this->supplierId, $employeeId, '2026-06-01');

        self::assertNotNull($view);
        $checks = $view['derived']['tax_declaration_checks'];
        self::assertCount(1, $checks);
        self::assertSame('earlier_than_allowed', $checks[0]['warning']);
        self::assertSame('2026-08-01', $checks[0]['legal_from']);
    }

    /** § 38k odst. 4 ZDP: podpis do 15. 2. platí od ledna, dokud leden není uzavřený. */
    public function testSignatureBeforeTheAnnualDeadlineStartsInJanuary(): void
    {
        $employeeId = $this->person();

        $result = $this->apply([$employeeId], '2026-02-10');

        self::assertSame('2026-01-01', $result['applied'][0]['effective_from']);
        self::assertSame('year_start', $result['applied'][0]['effective_from_basis']);
        self::assertFalse($result['applied'][0]['annual_settlement']);
    }

    /** Uzavřený leden se nepřepočítává: od února a leden dorovná roční zúčtování. */
    public function testClosedJanuaryMovesTheSignatureToFebruaryWithSettlementWarning(): void
    {
        $employeeId = $this->person();
        $this->approveRun('2026-01-01');

        $preview = $this->bulk->preview($this->supplierId, self::YEAR, '2026-02-10', [$employeeId]);
        self::assertSame('2026-02-01', $preview['people'][0]['effective_from']);
        self::assertSame('after_frozen_period', $preview['people'][0]['effective_from_basis']);
        self::assertTrue($preview['people'][0]['annual_settlement']);

        $result = $this->apply([$employeeId], '2026-02-10');

        self::assertSame('2026-02-01', $result['applied'][0]['effective_from']);
        self::assertTrue($result['applied'][0]['annual_settlement']);
        self::assertSame('2026-02-01', $this->declarations($employeeId)[0]['effective_from']);
    }

    public function testUnsignedDeclarationIsClosedBeforeTheSignature(): void
    {
        $closed = $this->person();
        $this->declaration($closed, 'not-signed', '2025-01-01');
        $replaced = $this->person();
        $replacedId = $this->declaration($replaced, 'unverified', '2026-05-01');

        $result = $this->apply([$closed, $replaced]);

        self::assertSame(['applied' => 2, 'skipped' => 0, 'failed' => 0], $result['counts']);
        self::assertSame(['close_previous', 'replace'], array_column($result['applied'], 'change'));
        $rows = $this->declarations($closed);
        self::assertSame(
            [['not-signed', '2025-01-01', '2026-04-30'], ['signed', '2026-05-01', null]],
            array_map(static fn (array $row): array => [$row['status'], $row['effective_from'], $row['effective_to']], $rows),
        );
        $rows = $this->declarations($replaced);
        self::assertCount(1, $rows);
        self::assertSame($replacedId, (int) $rows[0]['id']);
        self::assertSame('signed', $rows[0]['status']);
    }

    public function testExistingSignedDeclarationIsSkippedNotRewritten(): void
    {
        $employeeId = $this->person();
        $this->declaration($employeeId, 'signed', '2026-01-01', note: 'Ruční zápis');
        $before = $this->declarations($employeeId);

        $preview = $this->bulk->preview($this->supplierId, self::YEAR, self::SIGNED_ON, [$employeeId]);
        self::assertSame('already_signed', $preview['people'][0]['state']);
        self::assertSame([], $preview['ready_employee_ids']);

        $result = $this->apply([$employeeId]);

        self::assertSame(['applied' => 0, 'skipped' => 1, 'failed' => 0], $result['counts']);
        self::assertSame(['already_signed'], $result['skipped'][0]['reasons']);
        self::assertSame($before, $this->declarations($employeeId));
        self::assertSame(0, $this->activityCount('payroll.person_statutory_evidence.saved'));
    }

    public function testApprovedPeriodIsNeverWrittenInto(): void
    {
        $employeeId = $this->person();
        $this->declaration($employeeId, 'not-signed', '2025-01-01');
        $this->approveRun('2026-06-01');

        $preview = $this->bulk->preview($this->supplierId, self::YEAR, self::SIGNED_ON, [$employeeId]);
        self::assertSame('2026-07-01', $preview['effective_from']);
        self::assertSame('after_frozen_period', $preview['effective_from_basis']);
        self::assertSame('2026-06-30', $preview['frozen_through']);

        $result = $this->apply([$employeeId]);

        self::assertSame(1, $result['counts']['applied']);
        self::assertSame(
            [['not-signed', '2025-01-01', '2026-06-30'], ['signed', '2026-07-01', null]],
            array_map(
                static fn (array $row): array => [$row['status'], $row['effective_from'], $row['effective_to']],
                $this->declarations($employeeId),
            ),
        );

        // Celý rok uzavřený schválenou mzdou: nikdo se nezapíše.
        $this->approveRun('2026-12-01');
        $frozen = $this->bulk->preview($this->supplierId, self::YEAR, self::SIGNED_ON, [$employeeId]);
        self::assertNull($frozen['effective_from']);
        self::assertSame('period_frozen', $frozen['people'][0]['state']);
        $result = $this->apply([$employeeId]);
        self::assertSame(['period_frozen'], $result['skipped'][0]['reasons']);
    }

    public function testGapInTimelineIsLeftToTheCard(): void
    {
        $employeeId = $this->person();
        $this->declaration($employeeId, 'not-signed', '2025-01-01', '2025-10-31');

        $result = $this->apply([$employeeId]);

        self::assertSame(['timeline_gap'], $result['skipped'][0]['reasons']);
        self::assertCount(1, $this->declarations($employeeId));
    }

    public function testPersonOfAnotherCompanyIsNotTouched(): void
    {
        $foreign = $this->person(supplierId: $this->otherSupplierId);

        $preview = $this->bulk->preview($this->supplierId, self::YEAR, self::SIGNED_ON, [$foreign]);
        self::assertSame('employee_not_found', $preview['people'][0]['state']);
        self::assertNull($preview['people'][0]['full_name']);

        $result = $this->apply([$foreign]);

        self::assertSame(['employee_not_found'], $result['skipped'][0]['reasons']);
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_person_tax_declarations WHERE employee_id = ?'
        );
        $statement->execute([$foreign]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testCandidatesAreThePeopleEmployedInTheYear(): void
    {
        $employed = $this->person();
        $this->person(start: '2024-01-01', end: '2025-06-30');

        $preview = $this->bulk->preview($this->supplierId, self::YEAR, self::SIGNED_ON, null);

        self::assertSame([$employed], array_column($preview['people'], 'employee_id'));
    }

    /**
     * Dopad na výpočet: DPP pod rozhodnou částkou se bez podpisu sráží, po
     * hromadném podpisu se daní zálohou — přesně jako po ručním zápisu na kartě.
     */
    public function testBulkSignatureSwitchesDppFromWithholdingToAdvanceLikeTheCard(): void
    {
        $bulkPerson = $this->person(relationType: 'dpp', start: '2026-01-01');
        $this->declaration($bulkPerson, 'not-signed', '2026-01-01');
        $cardPerson = $this->person(relationType: 'dpp', start: '2026-01-01');
        $this->declaration($cardPerson, 'not-signed', '2026-01-01');

        self::assertSame(TaxRegime::Withholding, $this->dppRegime($bulkPerson, '2026-05-31'));

        $this->apply([$bulkPerson]);
        $view = $this->repository->editorView($this->supplierId, $cardPerson, '2026-04-01');
        self::assertNotNull($view);
        $sections = $view['sections'];
        $sections['tax_declarations'][0]['effective_to'] = '2026-04-30';
        $sections['tax_declarations'][] = [
            'status' => 'signed',
            'evidence_reference' => 'declaration:38k-signed',
            'effective_from' => '2026-05-01',
            'effective_to' => null,
            'evidence_note' => null,
            'signed_on' => self::SIGNED_ON,
        ];
        $this->repository->save(
            $this->supplierId,
            $cardPerson,
            ['sections' => $sections],
            '2026-04-01',
            $this->userId,
            null,
            null,
        );

        foreach ([$bulkPerson, $cardPerson] as $employeeId) {
            self::assertSame(TaxRegime::Withholding, $this->dppRegime($employeeId, '2026-04-30'));
            self::assertSame(TaxRegime::Advance, $this->dppRegime($employeeId, '2026-05-31'));
        }
        $withoutId = static fn (array $row): array => array_diff_key($row, ['id' => true]);
        self::assertSame(
            $withoutId($this->snapshotDeclaration($cardPerson, '2026-05-01')),
            $withoutId($this->snapshotDeclaration($bulkPerson, '2026-05-01')),
        );
    }

    public function testActionRejectsSignatureInTheFuture(): void
    {
        $employeeId = $this->person();
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        $response = $this->action->apply(
            $this->request([
                'year' => (int) substr($tomorrow, 0, 4),
                'signed_on' => $tomorrow,
                'employee_ids' => [$employeeId],
            ]),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->declarations($employeeId));
    }

    public function testActionRequiresSession(): void
    {
        $response = $this->action->preview(
            $this->request(['year' => self::YEAR, 'signed_on' => self::SIGNED_ON], 'api_token'),
            new Response(),
        );

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertLessThan(500, $response->getStatusCode());
    }

    private function dppRegime(int $employeeId, string $calculationDate): TaxRegime
    {
        return $this->dppResult($employeeId, $calculationDate)->relationships[0]->regime;
    }

    private function dppResult(int $employeeId, string $calculationDate): MonthlyEmploymentIncomeTaxResult
    {
        $declaration = $this->snapshotDeclaration($employeeId, substr($calculationDate, 0, 8) . '01');
        $result = (new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([
                CzechPayrollRulesets2026::provider()->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-31'),
            ]),
        ))->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: $calculationDate,
            employeeReference: 'synthetic-employee',
            relationships: [new EmploymentRelationshipTaxInput(
                'dpp',
                'synthetic-payer',
                EmploymentRelationshipKind::Dpp,
                [new IncomeTaxComponent('synthetic-income', 400_000)],
            )],
            declarations: [new TaxDeclarationEvidence(
                TaxDeclarationStatus::from((string) $declaration['status']),
                (string) $declaration['effective_from'],
                $declaration['effective_to'] === null ? null : (string) $declaration['effective_to'],
                $declaration['evidence_reference'] === null ? null : (string) $declaration['evidence_reference'],
            )],
            residence: new TaxResidenceEvidence(TaxResidence::CzechResident, '2026-01-01', null, 'synthetic-residence'),
        ));
        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));

        return $result;
    }

    /** @return array<string,mixed> */
    private function snapshotDeclaration(int $employeeId, string $effectiveOn): array
    {
        $snapshot = $this->repository->snapshot($this->supplierId, $employeeId, $effectiveOn);
        self::assertIsArray($snapshot);
        $declaration = $snapshot['income_tax']['declaration'] ?? null;
        self::assertIsArray($declaration);

        return $declaration;
    }

    /**
     * @param list<int> $employeeIds
     * @return array<string,mixed>
     */
    private function apply(array $employeeIds, string $signedOn = self::SIGNED_ON): array
    {
        return $this->bulk->apply(
            $this->supplierId,
            self::YEAR,
            $signedOn,
            $employeeIds,
            $this->userId,
            null,
            null,
        );
    }

    /** @return list<array<string,mixed>> */
    private function declarations(int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, effective_from, effective_to, evidence_reference, evidence_note, row_version
               FROM payroll_person_tax_declarations
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY effective_from, id'
        );
        $statement->execute([$this->supplierId, $employeeId]);

        /** @var list<array<string,mixed>> */
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function declaration(
        int $employeeId,
        string $status,
        string $from,
        ?string $to = null,
        ?string $note = null,
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, effective_to,
                 evidence_reference, evidence_note)
             VALUES (?, ?, ?, ?, ?, NULL, ?)'
        )->execute([$this->supplierId, $employeeId, $status, $from, $to, $note]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function person(
        string $relationType = 'employment',
        string $start = '2025-01-01',
        ?string $end = null,
        ?int $supplierId = null,
    ): int {
        $supplierId ??= $this->supplierId;
        $pdo = $this->db->pdo();
        $name = 'Syntetický brigádník ' . (++$this->sequence);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        )->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, end_date, is_primary)
             VALUES (?, ?, ?, ?, "active", ?, ?, ?, 1)'
        )->execute([
            $supplierId,
            $employeeId,
            'SIGN-' . $this->sequence,
            $relationType,
            $start,
            $start,
            $end,
        ]);

        return $employeeId;
    }

    private function activityCount(string $action): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ?'
        );
        $statement->execute([$this->supplierId, $action]);

        return (int) $statement->fetchColumn();
    }

    private function approveRun(string $periodStart): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)'
        )->execute([$this->supplierId, $periodStart, substr($periodStart, 0, 8) . '15']);
        $runId = (int) $pdo->lastInsertId();
        $snapshot = json_encode(['schema_version' => 'payroll-run-input.v2'], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash,
                 approved_at)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('c', 64),
            $snapshot,
            hash('sha256', $snapshot),
            $snapshot,
            hash('sha256', $snapshot),
            random_bytes(32),
        ]);
    }

    /** @param array<string,mixed> $body */
    private function request(array $body, string $authMethod = 'session'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/statutory-evidence/declaration-signed-bulk/apply')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withParsedBody($body);
    }
}
