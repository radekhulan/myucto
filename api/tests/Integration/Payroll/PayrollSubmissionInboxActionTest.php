<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollSubmissionInboxAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollSubmissionInboxActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollSubmissionInboxAction $action;
    private PayrollObligationService $obligations;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_submission_inbox_items')) {
            $this->markTestSkipped('Migrace 1309 neproběhla.');
        }
        $this->action = $container->get(PayrollSubmissionInboxAction::class);
        $this->obligations = $container->get(PayrollObligationService::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

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
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
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

    public function testListAcknowledgeAndSnoozeRoundTrip(): void
    {
        $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-08-01',
            '2026-08-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:synthetic:action-test',
            hash('sha256', 'event:action-test'),
            '2026-08-01',
            (new \DateTimeImmutable('+2 days'))->format('Y-m-d'),
            'calendar_days',
            'inbox-action-ruleset',
            hash('sha256', 'ruleset:action-test'),
            'inbox-action-test',
            createdBy: $this->userId,
        );

        $listResponse = ($this->action)->list(
            $this->request($this->supplierId, 'GET', '/api/payroll/submissions/inbox'),
            new Response(),
        );
        self::assertSame(200, $listResponse->getStatusCode());
        $body = $this->json($listResponse);
        self::assertGreaterThanOrEqual(1, count($body['items']));
        $item = $body['items'][0];
        self::assertArrayNotHasKey('birth_number', $item);
        self::assertArrayNotHasKey('bank_account', $item);

        $ackResponse = ($this->action)->acknowledge(
            $this->request(
                $this->supplierId,
                'POST',
                "/api/payroll/submissions/inbox/{$item['id']}/acknowledge",
                ['row_version' => $item['row_version']],
            ),
            new Response(),
            ['itemId' => (string) $item['id']],
        );
        self::assertSame(200, $ackResponse->getStatusCode());
        $acked = $this->json($ackResponse);
        self::assertSame('acknowledged', $acked['status']);

        $snoozeUntil = (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM);
        $snoozeResponse = ($this->action)->snooze(
            $this->request(
                $this->supplierId,
                'POST',
                "/api/payroll/submissions/inbox/{$item['id']}/snooze",
                [
                    'row_version' => $acked['row_version'],
                    'snoozed_until' => $snoozeUntil,
                    'reason' => 'Čeká na podklad od klienta.',
                ],
            ),
            new Response(),
            ['itemId' => (string) $item['id']],
        );
        self::assertSame(200, $snoozeResponse->getStatusCode());
        self::assertSame(
            'snoozed',
            $this->json($snoozeResponse)['status'],
        );

        // Chybějící důvod je odmítnut jako neplatný požadavek.
        $rejected = ($this->action)->snooze(
            $this->request(
                $this->supplierId,
                'POST',
                "/api/payroll/submissions/inbox/{$item['id']}/snooze",
                [
                    'row_version' => $this->json($snoozeResponse)['row_version'],
                    'snoozed_until' => $snoozeUntil,
                    'reason' => '',
                ],
            ),
            new Response(),
            ['itemId' => (string) $item['id']],
        );
        self::assertSame(422, $rejected->getStatusCode());
    }

    public function testRejectsBearerAndOtherTenant(): void
    {
        $bearer = ($this->action)->list(
            $this->request(
                $this->supplierId,
                'GET',
                '/api/payroll/submissions/inbox',
                null,
                'bearer',
            ),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($bearer)['error']['code'],
        );

        $otherTenantAck = ($this->action)->acknowledge(
            $this->request(
                $this->otherSupplierId,
                'POST',
                '/api/payroll/submissions/inbox/1/acknowledge',
                ['row_version' => 1],
            ),
            new Response(),
            ['itemId' => '1'],
        );
        self::assertSame(404, $otherTenantAck->getStatusCode());
    }

    /** @param array<string,mixed>|null $body */
    private function request(
        int $supplierId,
        string $method,
        string $uri,
        ?array $body = null,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);

        return $body === null ? $request : $request->withParsedBody($body);
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
