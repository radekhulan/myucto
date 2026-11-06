<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollTravelAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollBusinessTripRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `GET /payroll/travel/trips` nesmí vrátit všechny cesty firmy naráz.
 *
 * Filtr na období je volitelný, takže volání bez parametru četlo úplně všechny
 * pracovní cesty od vzniku firmy — a ke KAŽDÉ z nich ještě její položky
 * a bezplatná jídla dvěma dalšími dotazy. Strop stránky je proto tvrdý:
 * nesmí ho zvednout parametr z URL.
 */
#[Group('integration')]
final class PayrollTravelListPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTravelAction $travel;
    private int $supplierId;
    private int $employeeId;
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
            $this->travel = $container->get(PayrollTravelAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('payroll_business_trips')) {
            $this->markTestSkipped('Migrace 1308 neproběhla.');
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
        [$this->employeeId, $this->employmentId] = $this->employment();
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

    /** Strop je tvrdý a `total` je počet VŠECH cest, ne velikost stránky. */
    public function testCapCannotBeLiftedFromTheUrlAndTotalCountsEverything(): void
    {
        $seeded = PayrollBusinessTripRepository::LIST_MAX_LIMIT + 3;
        $this->seedTrips($seeded);

        $payload = $this->listTrips(['limit' => '10000']);

        self::assertLessThanOrEqual(
            PayrollBusinessTripRepository::LIST_MAX_LIMIT,
            count((array) $payload['trips']),
            'Strop nejde obejít vyšším limitem z URL.',
        );
        self::assertSame(
            $seeded,
            $payload['total'],
            'Total je počet všech odpovídajících cest, ne velikost stránky.',
        );
        self::assertSame(PayrollBusinessTripRepository::LIST_MAX_LIMIT, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    /** Offset musí seznam skutečně posunout, ne vrátit tutéž stránku. */
    public function testOffsetShiftsThePage(): void
    {
        $this->seedTrips(5);

        $first = $this->listTrips(['limit' => '2', 'offset' => '0']);
        $second = $this->listTrips(['limit' => '2', 'offset' => '2']);

        self::assertCount(2, (array) $first['trips']);
        self::assertCount(2, (array) $second['trips']);
        self::assertSame(5, $first['total']);
        self::assertSame(2, $second['offset']);
        self::assertSame(
            [],
            array_intersect($this->ids($first), $this->ids($second)),
            'Druhá stránka nesmí zopakovat řádky z první.',
        );
    }

    /**
     * Zúžení na jeden vztah musí najít i cestu, která na první stránce není.
     *
     * Dokud zužoval prohlížeč nad načtenou dávkou, vypadalo zúžení na cestu
     * z jiné strany jako „ten člověk žádnou cestu nemá". Test proto zadá cestu
     * druhému vztahu jako POSLEDNÍ v pořadí (řadí se podle odjezdu sestupně)
     * a ptá se na první stránku o dvou řádcích.
     */
    public function testNarrowingReachesATripBeyondTheFirstPage(): void
    {
        $this->seedTrips(4);
        [$otherEmployeeId, $otherEmploymentId] = $this->employment('SYN-TRV-FOCUS', 'Syntetický zúžený');
        $offPageTripId = $this->seedTrip($otherEmployeeId, $otherEmploymentId, '2026-06-01 05:00:00');

        $firstPage = $this->listTrips(['limit' => '2', 'offset' => '0']);
        self::assertNotContains(
            $offPageTripId,
            $this->ids($firstPage),
            'Předpoklad testu: hledaná cesta na první stránce být nesmí.',
        );

        $narrowed = $this->listTrips([
            'limit' => '2',
            'offset' => '0',
            'employment_id' => (string) $otherEmploymentId,
        ]);

        self::assertSame([$offPageTripId], $this->ids($narrowed), 'Zúžení musí vrátit hledanou cestu.');
        self::assertSame(1, $narrowed['total'], 'Total musí být zúžený stejně jako stránka.');
        self::assertSame($otherEmploymentId, $narrowed['employment_id']);
    }

    /**
     * Cizí ani neexistující vztah nesmí vrátit všechny cesty firmy.
     *
     * Prázdný výsledek je poznatelný stav; tiché zobrazení všech je lež,
     * ze které uživatel usoudí, že filtr nezabral.
     */
    public function testUnknownNarrowingReturnsNothingInsteadOfEverything(): void
    {
        $this->seedTrips(3);

        $payload = $this->listTrips(['employment_id' => (string) ($this->employmentId + 10_000)]);

        self::assertSame([], $this->ids($payload));
        self::assertSame(0, $payload['total']);
    }

    /** Klíč `trips` zůstává, aby stávající volající nespadli. */
    public function testCollectionKeyIsPreserved(): void
    {
        $this->seedTrips(1);

        $payload = $this->listTrips([]);

        self::assertArrayHasKey('trips', $payload);
        self::assertCount(1, (array) $payload['trips']);
        self::assertSame(1, $payload['total']);
        self::assertSame(PayrollBusinessTripRepository::LIST_DEFAULT_LIMIT, $payload['limit']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function ids(array $payload): array
    {
        $ids = [];
        foreach ((array) $payload['trips'] as $trip) {
            self::assertIsArray($trip);
            $ids[] = (int) $trip['id'];
        }

        return $ids;
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function listTrips(array $query): array
    {
        $response = $this->travel->list(
            $this->request()->withQueryParams($query),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    private function seedTrips(int $count): void
    {
        // Od druhého dne, aby si test zúžení mohl založit STARŠÍ cestu, která
        // se v řazení podle odjezdu sestupně spolehlivě propadne za první stránku.
        for ($i = 0; $i < $count; $i++) {
            $day = 2 + intdiv($i, 24);
            $hour = $i % 24;
            $this->seedTrip(
                $this->employeeId,
                $this->employmentId,
                sprintf('2026-06-%02d %02d:00:00', $day, $hour),
            );
        }
    }

    private function seedTrip(int $employeeId, int $employmentId, string $departureAt): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_business_trips
                (supplier_id, employee_id, employment_id, country_code,
                 departure_at_utc, arrival_at_utc, timezone_name,
                 origin_place, destination_place,
                 purpose, transport_mode, advance_minor,
                 settlement_period_start, created_by)
             VALUES (?, ?, ?, "CZ", ?, ?, "Europe/Prague", "Praha", "Brno", "Syntetická cesta",
                     "public_transport", 0, "2026-06-01", ?)'
        )->execute([
            $this->supplierId,
            $employeeId,
            $employmentId,
            $departureAt,
            substr($departureAt, 0, 14) . '30:00',
            $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} */
    private function employment(
        string $code = 'SYN-TRV-PAG',
        string $name = 'Syntetický cestující',
    ): array {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", 0)'
        )->execute([$this->supplierId, $employeeId, $code]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/travel/trips')
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
