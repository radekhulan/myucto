<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentSurchargePolicyAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Oprava a ukončení sjednané zásady příplatků § 114 až § 118 ZP.
 *
 * Než tyhle dvě operace vznikly, šlo zásadu jen ZALOŽIT: překlep v sazbě se
 * „opravoval" novou verzí od dalšího dne a v historii po sobě nechal den, kdy
 * prý platila sazba, kterou nikdo nesjednal. Test drží hranici, kde oprava
 * končí a začíná přepis historie.
 */
#[Group('integration')]
final class PayrollEmploymentSurchargePolicyApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentSurchargePolicyAction $action;
    private int $userId;
    private int $supplierId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        $action = $container->get(PayrollEmploymentSurchargePolicyAction::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollEmploymentSurchargePolicyAction::class, $action);
        $this->db = $connection;
        $this->action = $action;

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        self::assertGreaterThan(0, $this->userId);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->employmentId = $this->createEmployment($this->supplierId);
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

    public function testUpdateFixesOpenVersionUnderOptimisticLock(): void
    {
        $created = $this->create(['valid_from' => '2026-01-01', 'overtime_rate_bp' => 2500]);
        self::assertSame(1, (int) $created['row_version']);

        $update = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload([
                'overtime_rate_bp' => 3000,
                'note' => 'Oprava překlepu v sazbě.',
                'row_version' => 1,
            ])),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(200, $update->getStatusCode());
        $policy = $this->row($this->json($update)['policy'] ?? null);
        self::assertSame(3000, (int) $policy['overtime_rate_bp']);
        self::assertSame('2026-01-01', (string) $policy['valid_from']);
        self::assertSame(2, (int) $policy['row_version']);

        // Zastaralá verze musí narazit, jinak by dva uživatelé tiše přepsali
        // jeden druhého a na výplatní pásce by skončila sazba, kterou nikdo nevidí.
        $stale = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload([
                'overtime_rate_bp' => 4000,
                'row_version' => 1,
            ])),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(409, $stale->getStatusCode());
        $error = $this->row($this->json($stale)['error'] ?? null);
        self::assertSame('row_version_conflict', $error['code']);
    }

    /**
     * Účinnost je hranice proti předchozí verzi, jejíž konec je z ní dopočítaný.
     * Oprava ji proto nesmí posunout ani tehdy, když ji klient v těle pošle.
     */
    public function testUpdateIgnoresValidFromFromRequestBody(): void
    {
        $created = $this->create(['valid_from' => '2026-01-01']);

        $update = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload([
                'valid_from' => '2026-09-01',
                'row_version' => 1,
            ])),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(200, $update->getStatusCode());
        self::assertSame(
            '2026-01-01',
            (string) $this->row($this->json($update)['policy'] ?? null)['valid_from'],
        );
    }

    public function testClosedAndSupersededVersionsRefuseCorrection(): void
    {
        $first = $this->create(['valid_from' => '2026-01-01']);
        // Novou verzí se ta první uzavře; od té chvíle je to historie.
        $second = $this->create(['valid_from' => '2026-04-01']);

        $locked = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload([
                'overtime_rate_bp' => 9000,
                'row_version' => 2,
            ])),
            new Response(),
            $this->args((int) $first['id']),
        );
        self::assertSame(409, $locked->getStatusCode());
        self::assertSame(
            'surcharge_policy_history_locked',
            $this->row($this->json($locked)['error'] ?? null)['code'],
        );

        // Poslední, otevřená verze se opravit dá — hranice je právě tady.
        $open = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload([
                'overtime_rate_bp' => 3000,
                'row_version' => 1,
            ])),
            new Response(),
            $this->args((int) $second['id']),
        );
        self::assertSame(200, $open->getStatusCode());
    }

    public function testCloseSetsValidToAndRefusesInvertedInterval(): void
    {
        $created = $this->create(['valid_from' => '2026-01-01']);

        $inverted = $this->action->close(
            $this->request('POST')
                ->withParsedBody(['valid_to' => '2025-12-31', 'row_version' => 1]),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(422, $inverted->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->row($this->json($inverted)['error'] ?? null)['code'],
        );

        $closed = $this->action->close(
            $this->request('POST')
                ->withParsedBody(['valid_to' => '2026-06-30', 'row_version' => 1]),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(200, $closed->getStatusCode());
        $policy = $this->row($this->json($closed)['policy'] ?? null);
        self::assertSame('2026-06-30', (string) $policy['valid_to']);
        self::assertSame(2, (int) $policy['row_version']);

        // Podruhé už ne: posunout konec zpětně by změnilo období, podle kterého
        // se počítaly hotové mzdy.
        $again = $this->action->close(
            $this->request('POST')
                ->withParsedBody(['valid_to' => '2026-07-31', 'row_version' => 2]),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(409, $again->getStatusCode());
        self::assertSame(
            'surcharge_policy_history_locked',
            $this->row($this->json($again)['error'] ?? null)['code'],
        );
    }

    public function testMissingPolicyIsNotFound(): void
    {
        $response = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload(['row_version' => 1])),
            new Response(),
            $this->args(987654321),
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'not_found',
            $this->row($this->json($response)['error'] ?? null)['code'],
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function create(array $overrides): array
    {
        $response = $this->action->create(
            $this->request('POST')->withParsedBody($this->payload($overrides)),
            new Response(),
            ['id' => (string) $this->employmentId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->row($this->json($response)['policy'] ?? null);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'valid_from' => '2026-01-01',
            'overtime_mode' => 'surcharge',
            'holiday_mode' => 'surcharge',
            'difficult_environment_factors' => 2,
            'overtime_rate_bp' => null,
            'holiday_rate_bp' => null,
            'night_rate_bp' => null,
            'weekend_rate_bp' => null,
            'difficult_environment_rate_bp' => null,
            'agreement_reference' => 'KS čl. 12',
            'note' => null,
        ];
    }

    /** @return array<string,string> */
    private function args(int $policyId): array
    {
        return ['id' => (string) $this->employmentId, 'policyId' => (string) $policyId];
    }

    private function createEmployment(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba příplatků", "employee", 1)'
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, "surcharge-policy-synthetic", "employment", "active", "2026-01-01")'
        )->execute([$supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }

    private function request(string $method): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest(
                $method,
                '/api/payroll/employments/' . $this->employmentId . '/surcharge-policies',
            )
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role' => 'admin',
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return $this->row(json_decode((string) $response->getBody(), true));
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Testovací HTTP DTO není pole.');
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Testovací HTTP DTO nemá textové klíče.');
            }
            $row[$key] = $item;
        }

        return $row;
    }
}
