<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollQuickInputRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hledání v rychlém měsíčním vstupu (`GET /payroll/quick-inputs?q=`).
 *
 * Stránka je stránkovaná serverem po 25 řádcích, takže hledat v prohlížeči
 * by našlo jen toho, kdo je náhodou na zobrazené stránce. Hledá proto server:
 * podle jména i osobního čísla (kódu vztahu), bez ohledu na velikost písmen
 * a diakritiku, a stránkování i `total` se týkají jen výsledku.
 */
#[Group('integration')]
final class PayrollQuickInputSearchTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';

    private Connection $db;
    private PayrollQuickInputsAction $action;
    private PayrollQuickInputRepository $quickInputs;
    private int $supplierId;
    private int $userId;
    /** @var array<string,int> kód vztahu → id vztahu */
    private array $employmentIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollQuickInputsAction::class);
            $this->quickInputs = $container->get(PayrollQuickInputRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_employments', 'payroll_inputs'] as $table) {
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

        $this->seed('Syntetická Žofie Nováková', 'SYN-QS-ALFA');
        $this->seed('Syntetický Petr Dvořák', 'SYN-QS-BETA');
        $this->seed('Syntetický Tomáš Černý', 'SYN-QS-GAMA');
        $this->seed('Syntetický Hledaný 001', 'SYN-QS-H1');
        $this->seed('Syntetický Hledaný 002', 'SYN-QS-H2');
        $this->seed('Syntetický Hledaný 003', 'SYN-QS-H3');
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

    public function testFindsByName(): void
    {
        $payload = $this->list(['q' => 'Nováková']);
        $month = (array) $payload['month'];

        self::assertSame([$this->employmentIds['SYN-QS-ALFA']], $this->ids($month));
        self::assertSame(1, $month['total']);
        self::assertSame(1, $payload['total']);
        self::assertSame('Nováková', $payload['q'], 'Odpověď hlásí uplatněné hledání.');
    }

    public function testFindsByPersonalNumberCaseInsensitively(): void
    {
        $month = (array) $this->list(['q' => 'qs-beta'])['month'];

        self::assertSame([$this->employmentIds['SYN-QS-BETA']], $this->ids($month));
        self::assertSame(1, $month['total']);
    }

    /** Účetní píše „novakova" i „CERNY" — diakritika ani velikost nesmí vadit. */
    public function testIgnoresDiacriticsAndCase(): void
    {
        self::assertSame(
            [$this->employmentIds['SYN-QS-ALFA']],
            $this->ids((array) $this->list(['q' => 'zofie novakova'])['month']),
        );
        self::assertSame(
            [$this->employmentIds['SYN-QS-GAMA']],
            $this->ids((array) $this->list(['q' => 'CERNY'])['month']),
        );
    }

    /** Stránkování i `total` se týkají jen výsledku hledání, ne celého měsíce. */
    public function testPaginatesAndCountsOnlyTheMatches(): void
    {
        $first = (array) $this->list(['q' => 'hledany', 'limit' => '2', 'offset' => '0'])['month'];
        $second = (array) $this->list(['q' => 'hledany', 'limit' => '2', 'offset' => '2'])['month'];

        self::assertCount(2, (array) $first['items']);
        self::assertCount(1, (array) $second['items']);
        self::assertSame(3, $first['total']);
        self::assertSame(3, $second['total']);
        self::assertSame([], array_intersect($this->ids($first), $this->ids($second)));
        self::assertEqualsCanonicalizing(
            [
                $this->employmentIds['SYN-QS-H1'],
                $this->employmentIds['SYN-QS-H2'],
                $this->employmentIds['SYN-QS-H3'],
            ],
            [...$this->ids($first), ...$this->ids($second)],
        );

        $all = (array) $this->list([])['month'];
        self::assertSame(6, $all['total'], 'Bez hledání zůstává celý měsíc.');
    }

    /** Napsané `%` a `_` jsou hledaný text, ne zástupné znaky. */
    public function testLikeWildcardsAreLiteral(): void
    {
        self::assertSame(0, ((array) $this->list(['q' => '%'])['month'])['total']);
        self::assertSame(0, ((array) $this->list(['q' => '_'])['month'])['total']);
    }

    /**
     * Uložení vrací stránku téhož hledání — a přitom uloží i řádek, který
     * hledání nevrací (rozepsaný na jiné stránce nebo před hledáním).
     */
    public function testSaveKeepsTheSearchAndStillSavesRowsOutsideIt(): void
    {
        $outside = $this->employmentIds['SYN-QS-BETA'];

        $saved = $this->quickInputs->save(
            $this->supplierId,
            self::PERIOD,
            [[
                'employment_id' => $outside,
                'employment_row_version' => $this->employmentRowVersion($outside),
                'base_amount_minor' => 3_300_000,
                'overtime_mode' => 'amount',
                'overtime_hours_milli' => null,
                'overtime_amount_minor' => null,
                'bonus_amount_minor' => 0,
                'overtime_average_snapshot_id' => null,
                'overtime_average_snapshot_version' => null,
                'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
            ]],
            $this->userId,
            25,
            0,
            null,
            false,
            $failures,
            'nováková',
        );

        self::assertSame([], $failures ?? []);
        self::assertSame([$this->employmentIds['SYN-QS-ALFA']], $this->ids($saved));
        self::assertSame(1, $saved['total']);

        $stored = (array) $this->list(['q' => 'SYN-QS-BETA'])['month'];
        $items = (array) $stored['items'];
        self::assertCount(1, $items);
        self::assertIsArray($items[0]);
        self::assertSame(3_300_000, $items[0]['base_amount_minor']);
    }

    public function testOverlongSearchIsRefused(): void
    {
        $response = $this->action->list(
            $this->request()->withQueryParams([
                'period' => self::PERIOD,
                'q' => str_repeat('a', 121),
            ]),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * @param array<string,mixed> $month
     * @return list<int>
     */
    private function ids(array $month): array
    {
        $ids = [];
        foreach ((array) $month['items'] as $item) {
            self::assertIsArray($item);
            $ids[] = (int) $item['employment_id'];
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
            $this->request()->withQueryParams(['period' => self::PERIOD, ...$query]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    private function seed(string $fullName, string $code): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        )->execute([$this->supplierId, $fullName]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", 3000000, 0)'
        )->execute([$this->supplierId, $employeeId, $code]);
        $this->employmentIds[$code] = (int) $pdo->lastInsertId();
    }

    private function employmentRowVersion(int $employmentId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $employmentId]);

        return (int) $statement->fetchColumn();
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/quick-inputs')
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
