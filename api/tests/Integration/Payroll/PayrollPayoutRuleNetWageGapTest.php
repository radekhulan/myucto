<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPayoutRulesAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Service\Payroll\Net\PayoutAllocationService;
use MyInvoice\Service\Payroll\Payment\PayrollNetWageLiabilityMaterializer;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Důkaz, že díra je zavřená: zaměstnanec s výplatním pravidlem se DÁ vyplatit.
 *
 * Do teď byla `payroll_payout_rules` bez zapisovací cesty a tedy prázdná.
 * PayoutAllocationService::allocate() na prázdné sadě vyhodí výjimku, takže
 * PayrollNetWageLiabilityMaterializer neuměl vyrobit závazek čisté mzdy — plný
 * mzdový modul neuměl zaplatit NIKOHO.
 *
 * Test jde CELÝM řetězem přes skutečné komponenty, ne přes ručně poskládaná
 * pravidla jako PayrollNetWageLiabilityMaterializerTest:
 *
 *   osobní karta (payout_method = bank + ověřený účet)
 *     → PayrollPayoutRulesAction::applyDefaults()   [nová zapisovací cesta]
 *       → řádek v payroll_payout_rules              [skutečný, ne dopočet]
 *         → PayrollRunSnapshotBatchLoader::payoutRules()  [jediná čtecí cesta]
 *           → zmrazený input_snapshot revize
 *             → PayrollNetWageLiabilityMaterializer::materialize()
 *               → payroll_payment_liabilities (net_wage)
 *
 * Snapshot revize se skládá ručně (PayrollRunSnapshotBuilder by potřeboval celý
 * mzdový běh s docházkou, rulesetem a výpočtem), ale pravidla i účty do něj
 * vstupují z REÁLNÉHO loaderu a normalizují se přesně tak, jak to dělá
 * PayrollRunSnapshotBuilder::payoutRules()/payoutAccounts(). Kdyby se
 * zapisovací cesta rozešla se čtecí, test spadne.
 */
#[Group('integration')]
final class PayrollPayoutRuleNetWageGapTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PAYMENT_DATE = '2026-02-10';

    private Connection $db;
    private PayrollPayoutRulesAction $action;
    private PayrollNetWageLiabilityMaterializer $materializer;
    private PayrollRunSnapshotBatchLoader $loader;
    private int $supplierId;
    private int $userId;
    private int $employeeId;
    private int $accountId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollPayoutRulesAction::class);
        $this->materializer = new PayrollNetWageLiabilityMaterializer(
            new PayrollPaymentLiabilityRepository($this->db),
            new PayoutAllocationService(),
        );
        $this->loader = new PayrollRunSnapshotBatchLoader($this->db);

        $pdo = $this->db->pdo();
        $sourceSupplier = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $sourceUser = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1');
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        self::assertInstanceOf(\PDOStatement::class, $sourceUser);
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
        $this->userId = (int) $sourceUser->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->employeeId = $this->createEmployee();
        $this->accountId = $this->createVerifiedAccount();
        $this->setBankProfile();
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
     * Regresní pojistka na samotnou díru: bez pravidla to spadne.
     *
     * Kdyby někdo v budoucnu zavedl tichý fallback „když pravidlo chybí, pošli
     * to na živý účet", tenhle test spadne — a právě to MZ-17 zakazuje, protože
     * by nebylo dohledatelné, podle čeho se platilo.
     */
    public function testWithoutAnyPayoutRuleNetWageCannotBeMaterialized(): void
    {
        $revisionId = $this->createApprovedRevision(payableMinor: 2_500_000);

        try {
            $this->materializer->materialize(
                $this->supplierId,
                $revisionId,
                $this->userId,
            );
            self::fail('Bez výplatního pravidla nesmí závazek vzniknout.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'alokační pravidla',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->netWageLiabilities($revisionId));
    }

    public function testDefaultRuleFromPersonCardMakesTheEmployeePayable(): void
    {
        // 1) Nová zapisovací cesta: z osobní karty vznikne SKUTEČNÝ řádek pravidla.
        $applied = $this->action->applyDefaults(
            $this->request(),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );
        self::assertSame(201, $applied->getStatusCode(), (string) $applied->getBody());
        self::assertSame(1, $this->storedRuleCount());

        // 2) Jediná čtecí cesta (batch loader) pravidlo skutečně vidí a zmrazí.
        $revisionId = $this->createApprovedRevision(payableMinor: 2_500_000);

        // 3) Materializace závazku čisté mzdy — dřív nemožná, teď projde.
        $result = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->userId,
        );

        self::assertSame(1, $result['created_count']);
        $rows = $this->netWageLiabilities($revisionId);
        self::assertCount(1, $rows);
        self::assertSame(2_500_000, $rows[0]['amount_minor']);
        self::assertSame('outgoing', $rows[0]['direction']);
        self::assertSame(
            "employee-account:{$this->accountId}",
            $rows[0]['recipient_reference'],
        );
        self::assertSame(self::PAYMENT_DATE, $rows[0]['due_on']);
        self::assertSame('net_wage', $rows[0]['liability_kind']);

        // Idempotence zůstává: opakované volání nic nepřidá.
        $replay = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->userId,
        );
        self::assertSame(0, $replay['created_count']);
        self::assertSame($result['liability_ids'], $replay['liability_ids']);
    }

    /**
     * Rozdělení na fixní bankovní část a hotovostní zbytek — dvě pravidla
     * zadaná přes API dají dva ekonomicky správné závazky.
     */
    public function testManualSplitRulesProduceBothPaymentTargets(): void
    {
        $this->createRule([
            'destination_kind' => 'bank',
            'destination_reference' => "account:{$this->accountId}",
            'allocation_kind' => 'fixed',
            'amount_minor' => 1_000_000,
            'priority_no' => 10,
        ]);
        $this->createRule([
            'destination_kind' => 'cash',
            'allocation_kind' => 'remainder',
            'priority_no' => 20,
        ]);

        $revisionId = $this->createApprovedRevision(payableMinor: 2_500_000);
        $result = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->userId,
        );

        self::assertSame(2, $result['created_count']);
        $rows = $this->netWageLiabilities($revisionId);
        self::assertSame(
            [
                "employee-account:{$this->accountId}",
                "employee-cash:{$this->employeeId}",
            ],
            array_column($rows, 'recipient_reference'),
        );
        self::assertSame(
            [1_000_000, 1_500_000],
            array_column($rows, 'amount_minor'),
        );
    }

    /** @param array<string,mixed> $body */
    private function createRule(array $body): void
    {
        $response = $this->action->create(
            $this->request($body),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * Zmrazená schválená revize, jejíž `payout_rules` a `payout_accounts`
     * pocházejí z produkční čtecí cesty (PayrollRunSnapshotBatchLoader) a jsou
     * normalizované stejně jako v PayrollRunSnapshotBuilder.
     */
    private function createApprovedRevision(int $payableMinor): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-02-01", ?, "approved", 1)'
        )->execute([$this->supplierId, self::PAYMENT_DATE]);
        $runId = (int) $pdo->lastInsertId();

        $rules = $this->loader->payoutRules(
            $this->supplierId,
            [$this->employeeId],
        )[$this->employeeId] ?? [];
        $accounts = $this->loader->payoutAccounts(
            $this->supplierId,
            [$this->employeeId],
            self::PAYMENT_DATE,
        )[$this->employeeId] ?? [];

        $personInput = [
            'employee' => ['id' => $this->employeeId],
            'employments' => [],
            'payout_rules' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'allocation_reference' => (string) $row['allocation_reference'],
                    'destination_kind' => (string) $row['destination_kind'],
                    'destination_reference' => $row['destination_reference'],
                    'allocation_kind' => (string) $row['allocation_kind'],
                    'amount_minor' => $row['amount_minor'] === null
                        ? null
                        : (int) $row['amount_minor'],
                    'basis_points' => $row['basis_points'] === null
                        ? null
                        : (int) $row['basis_points'],
                    'priority_no' => (int) $row['priority_no'],
                    'row_version' => (int) $row['row_version'],
                ],
                $rules,
            ),
            'payout_accounts' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'label' => (string) $row['label'],
                    'bank_account_hash' => (string) $row['bank_account_hash'],
                    'bank_account_masked' => (string) $row['bank_account_masked'],
                    'allocation_basis_points' =>
                        (int) $row['allocation_basis_points'],
                    'effective_from' => (string) $row['effective_from'],
                    'effective_to' => $row['effective_to'],
                    'row_version' => (int) $row['row_version'],
                    'verification_source' => $row['verification_source'],
                    'verified_on' => $row['verified_on'],
                    'verified_by' => $row['verified_by'] === null
                        ? null
                        : (int) $row['verified_by'],
                ],
                $accounts,
            ),
        ];
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'payment_date' => self::PAYMENT_DATE,
            'people' => [$personInput],
        ];
        $inputJson = CanonicalJson::encode($input);
        $inputHash = hash('sha256', $inputJson);
        $personResult = [
            'employee_id' => $this->employeeId,
            'payable_after_enforcement_minor' => $payableMinor,
            'employments' => [],
        ];
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => $inputHash,
            'people' => [$personResult],
        ];
        $resultJson = CanonicalJson::encode($result);
        $personJson = CanonicalJson::encode($personResult);

        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version, ruleset_manifest_hash,
                 input_snapshot_json, input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, NULL, "regular", "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $inputJson,
            $inputHash,
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', "synthetic-payout-rule-gap:{$runId}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json, result_hash,
                 status)
             VALUES (?, ?, ?, ?, ?, "calculated")'
        )->execute([
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            $personJson,
            hash('sha256', $personJson),
        ]);

        return $revisionId;
    }

    private function createEmployee(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická vyplácená osoba", "employee", 1)'
        )->execute([$this->supplierId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createVerifiedAccount(): int
    {
        $hash = hash(
            'sha256',
            "synthetic-tenant-safe:{$this->supplierId}:payout-rule-gap",
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked, allocation_basis_points,
                 effective_from, is_active, row_version, verification_source,
                 verified_on, verified_by)
             VALUES (?, ?, "Syntetický účet", "enc:v2:synthetic", UNHEX(?),
                     "••••0000", 10000, "2026-01-01", 1, 1, "user_verified",
                     "2026-01-05", ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $hash,
            $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function setBankProfile(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status, payout_method,
                 cash_allocation_basis_points, payout_effective_on,
                 secure_delivery_channel)
             VALUES (?, ?, "ready", "bank", 0, "2026-01-01", "portal")'
        )->execute([$this->supplierId, $this->employeeId]);
    }

    private function storedRuleCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_payout_rules
              WHERE supplier_id = ? AND employee_id = ? AND is_active = 1'
        );
        $stmt->execute([$this->supplierId, $this->employeeId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function netWageLiabilities(int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT liability_kind, direction, recipient_reference, due_on,
                    amount_minor
               FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND revision_id = ?
                AND liability_kind = "net_wage"
              ORDER BY recipient_reference'
        );
        $stmt->execute([$this->supplierId, $revisionId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'liability_kind' => (string) $row['liability_kind'],
                'direction' => (string) $row['direction'],
                'recipient_reference' => (string) $row['recipient_reference'],
                'due_on' => (string) $row['due_on'],
                'amount_minor' => (int) $row['amount_minor'],
            ];
        }

        return $rows;
    }

    /** @param array<string,mixed>|null $body */
    private function request(?array $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                "/api/payroll/people/{$this->employeeId}/payout-rules",
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');

        return $body === null ? $request : $request->withParsedBody($body);
    }
}
