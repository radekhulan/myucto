<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPersonSensitiveRevealAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollPersonSensitiveRevealApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPersonSensitiveRevealAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private int $actorId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        self::assertNotNull($container);
        $connection = $container->get(Connection::class);
        $action = $container->get(PayrollPersonSensitiveRevealAction::class);
        $profiles = $container->get(PayrollPersonProfileRepository::class);
        $validator = $container->get(PayrollPersonProfileValidator::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(
            PayrollPersonSensitiveRevealAction::class,
            $action,
        );
        self::assertInstanceOf(PayrollPersonProfileRepository::class, $profiles);
        self::assertInstanceOf(PayrollPersonProfileValidator::class, $validator);
        $this->db = $connection;
        $this->action = $action;
        $pdo = $connection->pdo();
        $source = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $source);
        $sourceSupplierId = (int) $source->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
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
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)'
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $this->actorId = $this->createActor($pdo);
        $this->employeeId = $this->createEmployee(
            $pdo,
            $this->supplierId,
            'Syntetická endpoint osoba',
        );
        $this->otherEmployeeId = $this->createEmployee(
            $pdo,
            $this->otherSupplierId,
            'Syntetická endpoint cizí osoba',
        );
        $profiles->save(
            $this->supplierId,
            $this->employeeId,
            $validator->validate($this->profilePayload()),
            0,
            $this->actorId,
            '192.0.2.1',
            'synthetic-api-setup',
        );
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

    public function testSessionRevealReturnsNoStorePayloadAndSafeAudit(): void
    {
        $response = $this->post(
            $this->supplierId,
            $this->employeeId,
            ['reason' => 'Kontrola podkladů před registrací zaměstnance.'],
            $this->sensitiveReader(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'private, no-store',
            $response->getHeaderLine('Cache-Control'),
        );
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        $payload = $this->json($response);
        $sensitive = $this->object($payload['sensitive'] ?? null);
        $identifiers = $this->list($sensitive['identifiers'] ?? null);
        $identifier = $this->object($identifiers[0] ?? null);
        $contacts = $this->list($sensitive['contacts'] ?? null);
        $contact = $this->object($contacts[0] ?? null);
        $accounts = $this->list($sensitive['accounts'] ?? null);
        $account = $this->object($accounts[0] ?? null);
        self::assertSame(
            $this->employeeId,
            $sensitive['employee_id'],
        );
        self::assertSame('900000001', $identifier['value']);
        self::assertSame(
            'endpoint.person@example.invalid',
            $contact['value'],
        );
        self::assertSame(
            '1000000005/0100',
            $account['bank_account'],
        );

        $audit = $this->revealAudit();
        self::assertSame(
            'Kontrola podkladů před registrací zaměstnance.',
            $this->auditPayload($audit)['reason'],
        );
        $auditJson = json_encode($audit, JSON_THROW_ON_ERROR);
        foreach ([
            '900000001',
            'endpoint.person@example.invalid',
            '1000000005/0100',
            'enc:v2:',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $auditJson);
        }
    }

    public function testBroadPayrollPermissionCannotReveal(): void
    {
        $role = new EffectiveRole(
            102,
            'Syntetická obecná mzdová role',
            'staff',
            true,
            ['payroll' => AccessLevel::WRITE->value],
        );
        $response = $this->post(
            $this->supplierId,
            $this->employeeId,
            ['reason' => 'Kontrola podkladů před registrací zaměstnance.'],
            $role,
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($response));
        self::assertSame(0, $this->revealAuditCount());
    }

    public function testBearerAndInvalidReasonAreRejectedBeforeReveal(): void
    {
        $bearer = $this->post(
            $this->supplierId,
            $this->employeeId,
            ['reason' => 'Kontrola podkladů před registrací zaměstnance.'],
            $this->sensitiveReader(),
            'bearer',
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->errorCode($bearer),
        );

        $invalid = $this->post(
            $this->supplierId,
            $this->employeeId,
            ['reason' => 'krátké'],
            $this->sensitiveReader(),
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->errorCode($invalid),
        );
        self::assertSame(0, $this->revealAuditCount());
    }

    public function testOtherTenantAndDisabledModuleFailClosed(): void
    {
        $foreign = $this->post(
            $this->supplierId,
            $this->otherEmployeeId,
            ['reason' => 'Kontrola zaměstnance jiné syntetické firmy.'],
            $this->sensitiveReader(),
        );
        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame('not_found', $this->errorCode($foreign));

        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);
        $disabled = $this->post(
            $this->supplierId,
            $this->employeeId,
            ['reason' => 'Kontrola při vypnutém mzdovém modulu.'],
            $this->sensitiveReader(),
        );
        self::assertSame(403, $disabled->getStatusCode());
        self::assertSame(
            'payroll_disabled',
            $this->errorCode($disabled),
        );
        self::assertSame(0, $this->revealAuditCount());
    }

    private function sensitiveReader(): EffectiveRole
    {
        return new EffectiveRole(
            101,
            'Syntetický citlivý čtenář',
            'staff',
            true,
            ['payroll.person.read_sensitive' => AccessLevel::READ->value],
        );
    }

    /** @param array<string,mixed> $body */
    private function post(
        int $supplierId,
        int $employeeId,
        array $body,
        EffectiveRole $role,
        string $authMethod = 'session',
    ): ResponseInterface {
        return $this->action->post(
            $this->request(
                $supplierId,
                $employeeId,
                $body,
                $role,
                $authMethod,
            ),
            new Response(),
            ['id' => (string) $employeeId],
        );
    }

    /** @param array<string,mixed> $body */
    private function request(
        int $supplierId,
        int $employeeId,
        array $body,
        EffectiveRole $role,
        string $authMethod,
    ): ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                "/api/payroll/people/{$employeeId}/sensitive-reveal",
                ['REMOTE_ADDR' => '192.0.2.80'],
            )
            ->withHeader('User-Agent', 'synthetic-sensitive-reveal-api')
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId,
            )
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->actorId,
                'role' => 'readonly',
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute('auth.effective_role', $role)
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \UnexpectedValueException(
                'Endpoint nevrátil JSON objekt.',
            );
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'JSON objekt nemá textové klíče.',
                );
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function errorCode(ResponseInterface $response): string
    {
        $payload = $this->json($response);
        $error = $this->object($payload['error'] ?? null);
        $code = $error['code'] ?? null;
        if (!is_string($code)) {
            throw new \UnexpectedValueException(
                'Chybová odpověď nemá textový kód.',
            );
        }

        return $code;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException('Hodnota není JSON objekt.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'JSON objekt nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Hodnota není JSON seznam.');
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function profilePayload(): array
    {
        return [
            'profile_status' => 'setup',
            'payout_method' => 'bank',
            'cash_allocation_basis_points' => 0,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
            'identity_history' => [[
                'full_name' => 'Syntetická endpoint osoba',
                'first_name' => 'Syntetická',
                'last_name' => 'Osoba',
                'birth_surname' => null,
                'effective_from' => '2026-01-01',
            ]],
            'addresses' => [],
            'contacts' => [[
                'contact_type' => 'email',
                'value' => 'endpoint.person@example.invalid',
                'is_primary' => true,
                'is_active' => true,
            ]],
            'identifiers' => [[
                'identifier_type' => 'ecp',
                'value' => '900000001',
            ]],
            'accounts' => [[
                'label' => 'Syntetický endpoint účet',
                'bank_account' => '1000000005/0100',
                'allocation_basis_points' => 10000,
                'effective_from' => '2026-01-01',
                'is_active' => true,
            ]],
        ];
    }

    private function createEmployee(
        PDO $pdo,
        int $supplierId,
        string $fullName,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 10000, 0, 1)'
        )->execute([$supplierId, $fullName]);

        return (int) $pdo->lastInsertId();
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický endpoint uživatel",
                     "readonly", "cs", 1)'
        )->execute([
            'payroll-sensitive-api-' . bin2hex(random_bytes(6))
                . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function revealAudit(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT supplier_id, user_id, entity_id, payload
               FROM activity_log
              WHERE supplier_id = ? AND action = ?
              ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([
            $this->supplierId,
            'payroll.person_sensitive.revealed',
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || array_is_list($row)) {
            throw new \UnexpectedValueException(
                'Auditní událost nebyla nalezena.',
            );
        }
        $result = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Auditní událost nemá textové klíče.',
                );
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function revealAuditCount(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = ? AND action = ?'
        );
        $statement->execute([
            $this->supplierId,
            'payroll.person_sensitive.revealed',
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string,mixed> $audit
     * @return array<string,mixed>
     */
    private function auditPayload(array $audit): array
    {
        $payload = $audit['payload'] ?? null;
        if (!is_string($payload)) {
            throw new \UnexpectedValueException(
                'Auditní payload není text.',
            );
        }

        return $this->object(json_decode(
            $payload,
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }
}
