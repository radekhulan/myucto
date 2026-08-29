<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmployerPolicyAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollEmployerPolicyApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmployerPolicyAction $action;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        $action = $container->get(PayrollEmployerPolicyAction::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollEmployerPolicyAction::class, $action);
        $this->db = $connection;
        $this->action = $action;

        $pdo = $connection->pdo();
        $supplierQuery = $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        );
        $userQuery = $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        );
        self::assertInstanceOf(\PDOStatement::class, $supplierQuery);
        self::assertInstanceOf(\PDOStatement::class, $userQuery);
        $sourceSupplierId = (int) $supplierQuery->fetchColumn();
        $this->userId = (int) $userQuery->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        self::assertGreaterThan(0, $this->userId);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier
                SET payroll_enabled = 1
              WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $this->createEmployerSettings($this->supplierId);
        $this->createEmployerSettings($this->otherSupplierId);
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

    public function testCreateListDetailUpdateAndTenantIsolation(): void
    {
        $createdResponse = $this->action->create(
            $this->request('POST', $this->supplierId)
                ->withParsedBody($this->payload()),
            new Response(),
        );
        self::assertSame(201, $createdResponse->getStatusCode());
        $created = $this->row(
            $this->json($createdResponse)['policy'] ?? null,
        );
        $id = $this->int($created, 'id');
        self::assertSame(1, $created['row_version']);

        $listResponse = $this->action->list(
            $this->request(
                'GET',
                $this->supplierId,
                query: ['effective_on' => '2026-06-01'],
            ),
            new Response(),
        );
        self::assertSame(200, $listResponse->getStatusCode());
        self::assertCount(
            1,
            $this->rows($this->json($listResponse)['policies'] ?? null),
        );

        $detail = $this->action->detail(
            $this->request('GET', $this->supplierId),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame(
            $id,
            $this->int(
                $this->row($this->json($detail)['policy'] ?? null),
                'id',
            ),
        );

        $foreign = $this->action->detail(
            $this->request('GET', $this->otherSupplierId),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame(
            'not_found',
            $this->row($this->json($foreign)['error'] ?? null)['code'],
        );

        $update = $this->payload([
            'row_version' => 1,
            'payday_day' => 12,
        ]);
        $updated = $this->action->update(
            $this->request('PUT', $this->supplierId)
                ->withParsedBody($update),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(200, $updated->getStatusCode());
        $updatedPolicy = $this->row(
            $this->json($updated)['policy'] ?? null,
        );
        self::assertSame(2, $updatedPolicy['row_version']);
        self::assertSame(12, $updatedPolicy['payday_day']);
    }

    public function testOverlapAndStaleVersionHaveExactConflictCodes(): void
    {
        $first = $this->action->create(
            $this->request('POST', $this->supplierId)->withParsedBody(
                $this->payload(['valid_to' => '2026-06-30']),
            ),
            new Response(),
        );
        self::assertSame(201, $first->getStatusCode());
        $id = $this->int(
            $this->row($this->json($first)['policy'] ?? null),
            'id',
        );

        $overlap = $this->action->create(
            $this->request('POST', $this->supplierId)->withParsedBody(
                $this->payload([
                    'valid_from' => '2026-06-30',
                    'valid_to' => null,
                ]),
            ),
            new Response(),
        );
        self::assertSame(409, $overlap->getStatusCode());
        self::assertSame(
            'employer_policy_interval_overlap',
            $this->row($this->json($overlap)['error'] ?? null)['code'],
        );

        $updated = $this->action->update(
            $this->request('PUT', $this->supplierId)->withParsedBody(
                $this->payload([
                    'row_version' => 1,
                    'valid_to' => '2026-05-31',
                ]),
            ),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(200, $updated->getStatusCode());

        $stale = $this->action->update(
            $this->request('PUT', $this->supplierId)->withParsedBody(
                $this->payload([
                    'row_version' => 1,
                    'valid_to' => '2026-04-30',
                ]),
            ),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(409, $stale->getStatusCode());
        $staleError = $this->row($this->json($stale)['error'] ?? null);
        self::assertSame(
            'row_version_conflict',
            $staleError['code'],
        );
        self::assertSame(
            2,
            $staleError['current_row_version'],
        );
    }

    public function testSetupCheckUsesPolicyAndVerifiedGlobalAvailability(): void
    {
        $this->action->create(
            $this->request('POST', $this->supplierId)->withParsedBody(
                $this->payload([
                    'home_office_policy' => 'manual_review',
                ]),
            ),
            new Response(),
        );

        $response = $this->action->setupCheck(
            $this->request(
                'GET',
                $this->supplierId,
                query: ['effective_on' => '2026-06-01'],
            ),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
        $setup = $this->row($this->json($response)['setup'] ?? null);
        self::assertFalse($setup['ready']);
        self::assertContains(
            'home_office_policy',
            $this->stringList($setup['blockers'] ?? null),
        );
        $codes = array_column(
            $this->rows($setup['checks'] ?? null),
            'code',
        );
        // JMHZ se týká každého zaměstnavatele ze zákona, takže obě kontroly
        // v seznamu JSOU — dřív se nezobrazovaly vůbec, protože připravenost
        // byla natvrdo `false`. Vývojářská poznámka `jmhz_feature_source` se
        // objeví jen tehdy, když se nedá přečíst matice funkcí.
        self::assertContains('jmhz_registry', $codes);
        self::assertContains('jmhz_certificate', $codes);
        self::assertNotContains('jmhz_feature_source', $codes);

        // Chybějící certifikát nesmí zastavit nastavení: produkční endpoint
        // VREP není doložený, takže se ostře stejně podat nedá.
        $blockers = $this->stringList($setup['blockers'] ?? null);
        self::assertContains('jmhz_registry', $blockers);
        self::assertNotContains('jmhz_certificate', $blockers);
    }

    public function testValidationSessionPermissionAndDisabledModuleErrors(): void
    {
        $invalid = $this->action->create(
            $this->request('POST', $this->supplierId)->withParsedBody(
                $this->payload(['payday_day' => 0]),
            ),
            new Response(),
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->row($this->json($invalid)['error'] ?? null)['code'],
        );

        $bearer = $this->action->list(
            $this->request(
                'GET',
                $this->supplierId,
                authMethod: 'bearer',
            ),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->row($this->json($bearer)['error'] ?? null)['code'],
        );

        $readonly = $this->action->create(
            $this->request(
                'POST',
                $this->supplierId,
                role: 'readonly',
            )->withParsedBody($this->payload()),
            new Response(),
        );
        self::assertSame(403, $readonly->getStatusCode());
        self::assertSame(
            'forbidden',
            $this->row($this->json($readonly)['error'] ?? null)['code'],
        );

        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?',
        )->execute([$this->supplierId]);
        $disabled = $this->action->setupCheck(
            $this->request(
                'GET',
                $this->supplierId,
                query: ['effective_on' => '2026-06-01'],
            ),
            new Response(),
        );
        self::assertSame(403, $disabled->getStatusCode());
        self::assertSame(
            'payroll_disabled',
            $this->row($this->json($disabled)['error'] ?? null)['code'],
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'row_version' => 0,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'automatic_posting_enabled' => false,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:http-policy',
        ], $overrides);
    }

    private function createEmployerSettings(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, is_active)
             VALUES (?, "HTTP", "Syntetická HTTP účtárna", 1)',
        )->execute([$supplierId]);
        $officeId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id)
             VALUES (?, ?)',
        )->execute([$supplierId, $officeId]);
    }

    /**
     * @param array<string,string> $query
     */
    private function request(
        string $method,
        int $supplierId,
        string $role = 'admin',
        string $authMethod = 'session',
        array $query = [],
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                $method,
                '/api/payroll/settings/policies',
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId,
            )
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role' => $role,
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);

        return $query === [] ? $request : $request->withQueryParams($query);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return $this->row($decoded);
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                'Testovací HTTP DTO není pole.',
            );
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Testovací HTTP DTO nemá textové klíče.',
                );
            }
            $row[$key] = $item;
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                'Testovací seznam HTTP DTO není pole.',
            );
        }
        $result = [];
        foreach ($value as $row) {
            $result[] = $this->row($row);
        }

        return $result;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                'Testovací seznam kódů není pole.',
            );
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException(
                    'Testovací seznam kódů neobsahuje text.',
                );
            }
            $result[] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException(
                "Testovací HTTP DTO nemá celé pole {$field}.",
            );
        }

        return $value;
    }
}
