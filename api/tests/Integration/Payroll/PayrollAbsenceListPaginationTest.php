<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `GET /payroll/time/absences` nesmí vrátit celý filtrovaný rozsah naráz.
 *
 * Počet řádků roste součinem počtu zaměstnanců a délky rozsahu, takže roční
 * filtr u větší firmy vracel neomezenou odpověď. Strop stránky je proto tvrdý:
 * nesmí ho zvednout parametr z URL.
 */
#[Group('integration')]
final class PayrollAbsenceListPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAbsenceAction $action;
    private int $supplierId;
    private int $employmentId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollAbsenceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_employments', 'payroll_absences'] as $table) {
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
        $this->employmentId = $this->employment();
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

    /** Strop je tvrdý a `total` je počet VŠECH absencí v rozsahu, ne velikost stránky. */
    public function testCapCannotBeLiftedFromTheUrlAndTotalCountsEverything(): void
    {
        $seeded = PayrollAbsenceRepository::LIST_MAX_LIMIT + 3;
        $this->seedAbsences($seeded);

        $payload = $this->listAbsences(['limit' => '10000']);

        self::assertLessThanOrEqual(
            PayrollAbsenceRepository::LIST_MAX_LIMIT,
            count((array) $payload['absences']),
            'Strop nejde obejít vyšším limitem z URL.',
        );
        self::assertSame(
            $seeded,
            $payload['total'],
            'Total je počet všech odpovídajících absencí, ne velikost stránky.',
        );
        self::assertSame(PayrollAbsenceRepository::LIST_MAX_LIMIT, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    /** Offset musí seznam skutečně posunout, ne vrátit tutéž stránku. */
    public function testOffsetShiftsThePage(): void
    {
        $this->seedAbsences(5);

        $first = $this->listAbsences(['limit' => '2', 'offset' => '0']);
        $second = $this->listAbsences(['limit' => '2', 'offset' => '2']);

        self::assertCount(2, (array) $first['absences']);
        self::assertCount(2, (array) $second['absences']);
        self::assertSame(5, $first['total']);
        self::assertSame(2, $second['offset']);
        self::assertSame(
            [],
            array_intersect($this->ids($first), $this->ids($second)),
            'Druhá stránka nesmí zopakovat řádky z první.',
        );
    }

    /** Klíč `absences` zůstává, aby stávající volající nespadli. */
    public function testCollectionKeyIsPreserved(): void
    {
        $this->seedAbsences(1);

        $payload = $this->listAbsences([]);

        self::assertArrayHasKey('absences', $payload);
        self::assertCount(1, (array) $payload['absences']);
        self::assertSame(1, $payload['total']);
        self::assertSame(PayrollAbsenceRepository::LIST_DEFAULT_LIMIT, $payload['limit']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function ids(array $payload): array
    {
        $ids = [];
        foreach ((array) $payload['absences'] as $absence) {
            self::assertIsArray($absence);
            $ids[] = (int) $absence['id'];
        }

        return $ids;
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function listAbsences(array $query): array
    {
        $response = $this->action->list(
            $this->request()->withQueryParams([
                'from' => '2026-01-01',
                'to' => '2026-12-31',
                ...$query,
            ]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /**
     * Absence se zakládají přímo v SQL — endpoint hlídá překryv, a překrývat
     * se nesmějící řádky by tolik dat nešlo nasypat. Každá absence je proto
     * jiný den téhož roku.
     */
    private function seedAbsences(int $count): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 timezone_name, note, requested_by)
             VALUES (?, ?, "vacation", ?, ?, "Europe/Prague",
                     "Syntetický stránkovací test.", ?)'
        );
        $day = new \DateTimeImmutable('2026-01-01');
        for ($i = 0; $i < $count; $i++) {
            $date = $day->modify("+{$i} days")->format('Y-m-d');
            $stmt->execute([
                $this->supplierId,
                $this->employmentId,
                $date,
                $date,
                $this->userId,
            ]);
        }
    }

    private function employment(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický nepřítomný", "employee", 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, "SYN-ABS-PAG", "employment", "active", "2026-01-01", 0)'
        )->execute([$this->supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/time/absences')
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
