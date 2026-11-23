<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRecurringComponentsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentRepository;
use MyInvoice\Tests\Fixtures\Payroll\PayrollRunScaleFixture;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `GET /payroll/recurring-components` bez filtru čte celou firmu.
 *
 * `employment_id` je VOLITELNÝ, takže bez něj je seznam součin „počet
 * pracovních vztahů × počet předpisů". Strop stránky je proto tvrdý a `total`
 * hlásí všechny předpisy — jinak by uživatel neměl jak poznat, že za stránkou
 * ještě nějaké jsou.
 */
#[Group('integration')]
final class PayrollRecurringComponentListScaleTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRecurringComponentsAction $action;
    private int $supplierId;
    private int $userId;
    private int $componentId;
    /** @var list<int> */
    private array $employmentIds = [];
    private int $sequence = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollRecurringComponentsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_employments',
            'payroll_component_definitions',
            'payroll_recurring_components',
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
        $this->componentId = $this->seedComponent();
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

    /** Strop je tvrdý a `total` počítá všechny předpisy firmy. */
    public function testCapCannotBeLiftedFromTheUrl(): void
    {
        $seeded = $this->seedRecurring(2, 3);

        $payload = $this->list(['limit' => '10000']);

        self::assertLessThanOrEqual(
            PayrollRecurringComponentRepository::LIST_MAX_LIMIT,
            count((array) $payload['recurring_components']),
        );
        self::assertSame(
            PayrollRecurringComponentRepository::LIST_MAX_LIMIT,
            $payload['limit'],
        );
        self::assertSame($seeded, $payload['total']);
    }

    /** Offset musí seznam skutečně posunout. */
    public function testOffsetShiftsThePage(): void
    {
        $this->seedRecurring(2, 3);

        $first = $this->list(['limit' => '2', 'offset' => '0']);
        $second = $this->list(['limit' => '2', 'offset' => '2']);

        self::assertCount(2, (array) $first['recurring_components']);
        self::assertCount(2, (array) $second['recurring_components']);
        self::assertSame(6, $first['total']);
        self::assertSame(
            [],
            array_intersect($this->ids($first), $this->ids($second)),
        );
    }

    /** Filtr na jeden vztah musí ubrat ze stránky i z `total`. */
    public function testEmploymentFilterNarrowsPageAndTotalTogether(): void
    {
        $this->seedRecurring(3, 2);

        $all = $this->list([]);
        $filtered = $this->list(['employment_id' => (string) $this->employmentIds[0]]);

        self::assertSame(6, $all['total']);
        self::assertSame(2, $filtered['total'], 'Filtr musí ubrat i z celkového počtu.');
        self::assertCount(2, (array) $filtered['recurring_components']);
    }

    /** Klíč `recurring_components` zůstává, aby stávající volající nespadli. */
    public function testCollectionKeyIsPreserved(): void
    {
        $this->seedRecurring(1, 1);

        $payload = $this->list([]);

        self::assertArrayHasKey('recurring_components', $payload);
        self::assertSame(1, $payload['total']);
        self::assertSame(
            PayrollRecurringComponentRepository::LIST_DEFAULT_LIMIT,
            $payload['limit'],
        );
        self::assertSame(0, $payload['offset']);
        $first = ((array) $payload['recurring_components'])[0];
        self::assertIsArray($first);
        self::assertArrayHasKey('can_delete', $first, 'Rozhodnutí o smazání musí zůstat v seznamu.');
    }

    /**
     * Cena stránky se neřídí velikostí firmy.
     *
     * Rozhodnutí o smazatelnosti se doplňuje JEDNÍM dotazem na celou stránku.
     * Rovnost je tvrdší tvrzení než horní mez: chytí i to, kdyby se do seznamu
     * vrátil jediný dotaz na řádek, protože ten by u dvaceti předpisů utekl.
     */
    public function testQueryCountDoesNotGrowWithTheNumberOfPrescriptions(): void
    {
        $pdo = $this->db->pdo();
        // Obě měření vracejí PLNOU stránku o deseti řádcích, takže se
        // neporovnává jablko s hruškou: liší se jen velikost firmy.
        $repository = $this->repository();
        $this->seedRecurring(2, 5);
        $before = PayrollRunScaleFixture::statementRoundTrips($pdo);
        $small = $repository->list($this->supplierId, null, 10, 0);
        $smallCost = PayrollRunScaleFixture::statementRoundTrips($pdo) - $before;

        $this->seedRecurring(6, 5);
        $before = PayrollRunScaleFixture::statementRoundTrips($pdo);
        $large = $repository->list($this->supplierId, null, 10, 0);
        $largeCost = PayrollRunScaleFixture::statementRoundTrips($pdo) - $before;

        self::assertCount(10, $small['items']);
        self::assertCount(10, $large['items']);
        self::assertSame(10, $small['total']);
        self::assertSame(40, $large['total']);
        self::assertSame(
            $smallCost,
            $largeCost,
            'Další předpisy nesmějí zdražit stránku o deseti řádcích ani o jeden dotaz.',
        );
    }

    private function repository(): PayrollRecurringComponentRepository
    {
        $repository = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ->get(PayrollRecurringComponentRepository::class);
        self::assertInstanceOf(PayrollRecurringComponentRepository::class, $repository);

        return $repository;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function ids(array $payload): array
    {
        $ids = [];
        foreach ((array) $payload['recurring_components'] as $row) {
            self::assertIsArray($row);
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function list(array $query): array
    {
        $response = $this->action->list(
            $this->request()->withQueryParams($query),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** @return int kolik předpisů celkem vzniklo */
    private function seedRecurring(int $employments, int $perEmployment): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, calculation_kind,
                 amount_minor, valid_from, allocation_rule, is_active)
             VALUES (?, ?, ?, "fixed_amount", ?, ?, "full_month", 1)'
        );
        for ($e = 0; $e < $employments; ++$e) {
            $employmentId = $this->seedEmployment();
            for ($i = 0; $i < $perEmployment; ++$i) {
                $ordinal = ++$this->sequence;
                $statement->execute([
                    $this->supplierId,
                    $employmentId,
                    $this->componentId,
                    1000 + $ordinal,
                    '2026-01-' . str_pad((string) (($ordinal % 28) + 1), 2, '0', STR_PAD_LEFT),
                ]);
            }
        }

        return $this->sequence;
    }

    private function seedEmployment(): int
    {
        $pdo = $this->db->pdo();
        $ordinal = count($this->employmentIds) + 1;
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        )->execute([$this->supplierId, 'Syntetický předpisář ' . $ordinal]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", 0)'
        )->execute([$this->supplierId, $employeeId, 'SYN-REC-' . $ordinal]);
        $employmentId = (int) $pdo->lastInsertId();
        $this->employmentIds[] = $employmentId;

        return $employmentId;
    }

    private function seedComponent(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment, social_participation_treatment,
                 social_treatment, health_participation_treatment,
                 health_treatment, average_earning_treatment,
                 enforcement_treatment, jmhz_treatment, statistics_treatment,
                 valid_from, is_active)
             VALUES (?, "SYN_REC", "Syntetická složka", "bonus", "monetary",
                     "regular", "included", "included", "included", "included",
                     "included", "included", "included", "included", "included",
                     "2026-01-01", 1)'
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/recurring-components')
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
