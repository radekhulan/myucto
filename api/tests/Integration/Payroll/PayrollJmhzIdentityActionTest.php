<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollJmhzIdentityAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollJmhzIdentityActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollJmhzIdentityAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_person_external_ids')
            || !$this->db->hasTable('payroll_employment_external_ids')
        ) {
            $this->markTestSkipped('Migrace externích identifikátorů JMHZ neproběhly.');
        }
        $this->action = $container->get(PayrollJmhzIdentityAction::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT MIN(id) FROM users',
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $this->employmentId = $this->createEmployment($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testSessionCanSaveAndReadOnlyMaskedIdentifiers(): void
    {
        $saved = $this->action->put(
            $this->request('PUT', $this->validBody()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame('private, no-store', $saved->getHeaderLine('Cache-Control'));
        $savedJson = $this->json($saved);
        self::assertArrayNotHasKey(
            'value',
            $savedJson['assigned']['person_external_identifier'],
        );
        self::assertStringNotContainsString(
            '1000000001',
            json_encode($savedJson, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(
            '200000000000000000002',
            json_encode($savedJson, JSON_THROW_ON_ERROR),
        );

        $shown = $this->action->show(
            $this->request('GET', null, query: [
                'environment' => 'test',
                'on_date' => '2026-08-04',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );

        self::assertSame(200, $shown->getStatusCode(), (string) $shown->getBody());
        self::assertSame('private, no-store', $shown->getHeaderLine('Cache-Control'));
        $shownJson = $this->json($shown);
        self::assertSame('test', $shownJson['identity']['environment']);
        self::assertNotNull($shownJson['identity']['person_external_identifier']);
        self::assertNotNull($shownJson['identity']['employment_external_identifier']);
        self::assertStringNotContainsString(
            '1000000001',
            json_encode($shownJson, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(
            '200000000000000000002',
            json_encode($shownJson, JSON_THROW_ON_ERROR),
        );

        $production = $this->action->show(
            $this->request('GET', null, query: [
                'environment' => 'production',
                'on_date' => '2026-08-04',
            ]),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        $productionJson = $this->json($production);
        self::assertNull($productionJson['identity']['person_external_identifier']);
        self::assertNull($productionJson['identity']['employment_external_identifier']);
    }

    public function testWriteRequiresSessionActorAndBothPermissions(): void
    {
        $bearer = $this->action->put(
            $this->request('PUT', $this->validBody(), authMethod: 'bearer'),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);

        $personOnly = new EffectiveRole(
            71,
            'Personalista',
            'staff',
            true,
            ['payroll.person.write' => AccessLevel::WRITE->value],
        );
        $forbidden = $this->action->put(
            $this->request('PUT', $this->validBody(), role: $personOnly),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', $this->json($forbidden)['error']['code']);

        $payrollWriter = new EffectiveRole(
            72,
            'Mzdová účetní',
            'staff',
            true,
            [
                'payroll.person.write' => AccessLevel::WRITE->value,
                'payroll.employment.write' => AccessLevel::WRITE->value,
            ],
        );
        $actorMissing = $this->action->put(
            $this->request(
                'PUT',
                $this->validBody(),
                role: $payrollWriter,
                includeActor: false,
            ),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $actorMissing->getStatusCode());
        self::assertSame('actor_required', $this->json($actorMissing)['error']['code']);
        self::assertSame(0, $this->storedIdentifiers());
    }

    public function testInvalidEvidenceAndOtherTenantDoNotMutate(): void
    {
        $body = $this->validBody();
        $body['evidence_confirmed'] = false;
        $unconfirmed = $this->action->put(
            $this->request('PUT', $body),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $unconfirmed->getStatusCode());
        self::assertSame('validation_failed', $this->json($unconfirmed)['error']['code']);

        $body = $this->validBody();
        $body['employment_external_identifier'] = 'není číslo';
        $invalid = $this->action->put(
            $this->request('PUT', $body),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(422, $invalid->getStatusCode());

        $otherTenant = $this->action->put(
            $this->request(
                'PUT',
                $this->validBody(),
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(404, $otherTenant->getStatusCode());
        self::assertSame('not_found', $this->json($otherTenant)['error']['code']);
        self::assertSame(0, $this->storedIdentifiers());
    }

    private function createEmployment(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická Osoba", "employee", "hpp",
                     1, 1, 0, 40000, 0, 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, "jmhz-identity-action", "employment", "active",
                     "2026-08-01", 0)',
        )->execute([$this->supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function validBody(): array
    {
        return [
            'environment' => 'test',
            'person_external_identifier' => '1000000001',
            'employment_external_identifier' => '200000000000000000002',
            'valid_from' => '2026-08-01',
            'source_reference' => null,
            'evidence_confirmed' => true,
        ];
    }

    /** @param array<string,mixed>|null $body @param array<string,string> $query */
    private function request(
        string $method,
        ?array $body,
        ?EffectiveRole $role = null,
        string $authMethod = 'session',
        ?int $supplierId = null,
        bool $includeActor = true,
        array $query = [],
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                $method,
                "/api/payroll/jmhz/identities/{$this->employmentId}",
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withQueryParams($query);
        if ($includeActor) {
            $request = $request->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            );
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }

        return $request;
    }

    private function storedIdentifiers(): int
    {
        return (int) $this->db->pdo()->query(
            'SELECT
                (SELECT COUNT(*) FROM payroll_person_external_ids
                  WHERE supplier_id = ' . $this->supplierId . ')
              + (SELECT COUNT(*) FROM payroll_employment_external_ids
                  WHERE supplier_id = ' . $this->supplierId . ')',
        )->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
