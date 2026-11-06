<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPaymentAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentQueryService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `GET /payroll/payments/liabilities` nesmí vrátit celý měsíc naráz.
 *
 * Za jedno období vzniká závazek na každou čistou mzdu plus odvod za každou
 * instituci a každou exekuci; u větší firmy jsou to tisíce řádků v jediné
 * odpovědi. Strop stránky je proto tvrdý a `total` hlásí všechny závazky.
 */
#[Group('integration')]
final class PayrollPaymentLiabilityListPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';

    private Connection $db;
    private PayrollPaymentAction $action;
    private int $supplierId;
    private int $userId;
    private int $revisionId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollPaymentAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_payment_liabilities',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->revisionId = $this->seedApprovedRevision();
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

    /** Strop je tvrdý a `total` počítá všechny závazky období. */
    public function testCapCannotBeLiftedFromTheUrl(): void
    {
        $seeded = 8;
        $this->seedLiabilities($seeded);

        $payload = $this->listLiabilities(['limit' => '10000']);

        self::assertLessThanOrEqual(
            PayrollPaymentQueryService::LIST_MAX_LIMIT,
            count((array) $payload['items']),
        );
        self::assertSame(PayrollPaymentQueryService::LIST_MAX_LIMIT, $payload['limit']);
        self::assertSame($seeded, $payload['total']);
    }

    /** Offset musí seznam skutečně posunout. */
    public function testOffsetShiftsThePage(): void
    {
        $this->seedLiabilities(5);

        $first = $this->listLiabilities(['limit' => '2', 'offset' => '0']);
        $second = $this->listLiabilities(['limit' => '2', 'offset' => '2']);

        self::assertCount(2, (array) $first['items']);
        self::assertCount(2, (array) $second['items']);
        self::assertSame(5, $first['total']);
        self::assertSame(5, $second['total']);
        self::assertSame(2, $second['offset']);
        self::assertSame(
            [],
            array_intersect($this->ids($first), $this->ids($second)),
            'Druhá stránka nesmí zopakovat řádky z první.',
        );
    }

    /**
     * Součty jsou za CELÉ období, ne za stránku.
     *
     * Frontend je dřív sčítal z `items`, což bez stránkování vycházelo. Se
     * stránkou by se z „celkem k úhradě" tiše stalo „celkem na téhle stránce"
     * — a to je u peněz horší než žádné číslo.
     */
    public function testTotalsCoverTheWholePeriodNotJustThePage(): void
    {
        $this->seedLiabilities(5);

        $page = $this->listLiabilities(['limit' => '2', 'offset' => '0']);
        $totals = (array) $page['totals'];

        self::assertCount(2, (array) $page['items']);
        // Naseedované částky jsou 1001…1005, všechny odchozí.
        self::assertSame(5015, $totals['amount_minor']);
        self::assertSame(0, $totals['allocated_minor']);
        self::assertSame(0, $totals['settled_minor']);
        self::assertSame(
            $totals,
            (array) $this->listLiabilities(['limit' => '2', 'offset' => '2'])['totals'],
            'Součty nesmí záviset na tom, kterou stránku uživatel čte.',
        );
    }

    /** Klíč `items` zůstává, aby stávající volající nespadli. */
    public function testCollectionKeyIsPreserved(): void
    {
        $this->seedLiabilities(1);

        $payload = $this->listLiabilities([]);

        self::assertArrayHasKey('items', $payload);
        self::assertSame(self::PERIOD, $payload['period']);
        self::assertCount(1, (array) $payload['items']);
        self::assertSame(1, $payload['total']);
        self::assertSame(PayrollPaymentQueryService::LIST_DEFAULT_LIMIT, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function ids(array $payload): array
    {
        $ids = [];
        foreach ((array) $payload['items'] as $liability) {
            self::assertIsArray($liability);
            $ids[] = (int) $liability['id'];
        }

        return $ids;
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function listLiabilities(array $query): array
    {
        $response = $this->action->listLiabilities(
            $this->request()->withQueryParams(['period' => self::PERIOD, ...$query]),
            new Response(),
            [],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /**
     * Závazky se zakládají bez osoby (odvod instituci). Trigger u osobního
     * závazku vyžaduje spočítaný osobní výsledek, který k testu stránkování
     * není potřeba.
     */
    private function seedLiabilities(int $count): void
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, NULL, ?, "social_insurance", "outgoing", ?, ?, "CZK",
                     ?, "{}", ?, UNHEX(?))'
        );
        for ($i = 0; $i < $count; ++$i) {
            $ordinal = ++$this->sequence;
            $statement->execute([
                $this->supplierId,
                $this->revisionId,
                'odvod:' . $ordinal,
                'institution:' . $ordinal . ':account:' . $ordinal,
                self::PERIOD . '-20',
                1000 + $ordinal,
                hash('sha256', 'snapshot-' . $ordinal),
                hash('sha256', 'idem-' . $ordinal),
            ]);
        }
    }

    private function seedApprovedRevision(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)'
        )->execute([$this->supplierId, self::PERIOD . '-01', self::PERIOD . '-15']);
        $runId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "approved", "v1", ?, "{}", ?, "{}", ?,
                     UNHEX(?))'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/payments/liabilities')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
