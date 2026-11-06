<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPersonProfileAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Security\PayrollIdentifierProbeGuard;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Jedinečnost identifikátoru (rodné číslo, EČP) jako orákulum: uživatel bez
 * `payroll.person.read_sensitive` se dál dozví, že osobu s hodnotou už eviduje,
 * ale bez jména té osoby, s auditním záznamem i u zkoušky nanečisto a s limitem
 * na tiché zkoušky.
 */
#[Group('integration')]
final class PayrollIdentifierProbeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPersonProfileAction $action;
    private Config $config;
    private int $userId;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollPersonProfileAction::class);
        $config = $container->get(Config::class);
        if (!$db instanceof Connection || !$action instanceof PayrollPersonProfileAction || !$config instanceof Config) {
            throw new \RuntimeException('Služby osobní karty nejsou dostupné.');
        }
        $this->db = $db;
        $this->action = $action;
        $this->config = $config;
        if (!$db->hasTable('payroll_person_identifiers')) {
            $this->markTestSkipped('Migrace 1191 neproběhla.');
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET payroll_enabled = 1, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
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

    public function testDuplicateIdentifierIsReportedWithoutNamingOwnerAndAuditedEvenOnDryRun(): void
    {
        $owner = $this->employee('Vlastník Hodnoty');
        $prober = $this->employee('Zkušební Karta');
        $saved = $this->put($owner, $this->payload('123456789'), $this->writer());
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());

        $miss = $this->put($prober, $this->payload('987654321') + ['dry_run' => true], $this->writer());
        self::assertSame(200, $miss->getStatusCode(), (string) $miss->getBody());
        self::assertSame(0, $this->auditCount($prober));

        $hit = $this->put($prober, $this->payload('123456789') + ['dry_run' => true], $this->writer());
        self::assertSame(422, $hit->getStatusCode());
        $error = $this->json($hit)['error'];
        self::assertSame('validation_failed', $error['code']);
        self::assertSame('Zaměstnance se stejným identifikátorem už evidujete.', $error['message']);
        self::assertStringNotContainsString('Vlastník', (string) $hit->getBody());

        // Zkouška se vrátila zpět, auditní záznam o shodě ale zůstal.
        self::assertSame(1, $this->auditCount($prober));
        $stmt = $this->db->pdo()->prepare(
            'SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = ? AND entity_id = ?'
        );
        $stmt->execute([$this->supplierId, PayrollIdentifierProbeGuard::AUDIT_ACTION, $prober]);
        $payload = json_decode((string) $stmt->fetchColumn(), true);
        self::assertIsArray($payload);
        self::assertSame('ecp', $payload['identifier_type']);
        self::assertTrue($payload['dry_run']);
        self::assertFalse($payload['may_reveal_sensitive']);
        self::assertStringNotContainsString('123456789', json_encode($payload, JSON_THROW_ON_ERROR));

        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_person_identifiers WHERE supplier_id = ? AND employee_id = ?'
        );
        $count->execute([$this->supplierId, $prober]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testDryRunIdentifierProbesAreRateLimitedWithoutRevealPermission(): void
    {
        if ($this->config->get('rate_limits.enabled', true) === false) {
            $this->markTestSkipped('Limiter je v této instalaci vypnutý.');
        }
        $prober = $this->employee('Zkušební Karta');
        // Vlastní identita, aby bucket nesdílel jiný test ani předchozí běh.
        $probeUserId = 1_900_000_000 + random_int(1, 99_999_999);
        $limit = (int) (((array) $this->config->get('rate_limits', []))['payroll_identifier_writes_per_5min_per_user'] ?? 20);

        $last = null;
        for ($i = 0; $i <= $limit; $i++) {
            $last = $this->put(
                $prober,
                $this->payload(sprintf('5%08d', $i)) + ['dry_run' => true],
                $this->writer(),
                $probeUserId,
            );
            if ($i < $limit) {
                self::assertSame(200, $last->getStatusCode(), (string) $last->getBody());
            }
        }
        self::assertNotNull($last);
        self::assertSame(429, $last->getStatusCode());
        self::assertSame('rate_limited', $this->json($last)['error']['code']);

        // Kdo smí hodnotu odhalit, toho limit nebrzdí.
        $revealer = new EffectiveRole(
            302,
            'Mzdová účetní s odhalením',
            'staff',
            true,
            ['payroll' => 2, 'payroll.person.write' => 2, 'payroll.person.read_sensitive' => 1],
        );
        $allowed = $this->put($prober, $this->payload('599999999') + ['dry_run' => true], $revealer, $probeUserId);
        self::assertSame(200, $allowed->getStatusCode(), (string) $allowed->getBody());
    }

    private function writer(): EffectiveRole
    {
        return new EffectiveRole(
            301,
            'Mzdová účetní bez odhalení',
            'staff',
            true,
            ['payroll' => 2, 'payroll.person.write' => 2],
        );
    }

    /** @return array<string,mixed> */
    private function payload(string $ecp): array
    {
        return [
            'row_version' => 0,
            'profile_status' => 'setup',
            'payout_method' => 'bank',
            'cash_allocation_basis_points' => 0,
            'payout_effective_on' => '2026-01-01',
            'secure_delivery_channel' => 'portal',
            'identity_history' => [],
            'addresses' => [],
            'contacts' => [],
            'identifiers' => [[
                'identifier_type' => 'ecp',
                'value' => $ecp,
            ]],
            'accounts' => [],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function put(int $employeeId, array $payload, EffectiveRole $role, ?int $userId = null): ResponseInterface
    {
        return $this->action->put(
            $this->request("/api/payroll/people/{$employeeId}/profile", $userId ?? $this->userId)
                ->withAttribute('auth.effective_role', $role)
                ->withParsedBody($payload),
            new Response(),
            ['id' => (string) $employeeId],
        );
    }

    private function request(string $path, int $userId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PUT', $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function employee(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 32000, 0, 1)'
        )->execute([$this->supplierId, $name]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function auditCount(int $employeeId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ? AND entity_id = ?'
        );
        $stmt->execute([$this->supplierId, PayrollIdentifierProbeGuard::AUDIT_ACTION, $employeeId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }
}
