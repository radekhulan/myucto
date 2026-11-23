<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollSubmissionDetailAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollSubmissionDetailActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollSubmissionDetailAction $action;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_obligations')) {
            $this->markTestSkipped('Migrace 1279 neproběhla.');
        }
        $this->action = $container->get(PayrollSubmissionDetailAction::class);
        $this->obligations = $container->get(PayrollObligationService::class);
        $this->submissions = $container->get(PayrollSubmissionService::class);

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

    public function testReturnsSafeTenantScopedAggregateMetadata(): void
    {
        $obligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-08-01',
            '2026-08-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:synthetic:2026-08',
            str_repeat('a', 64),
            '2026-09-01',
            '2026-09-20',
            'calendar_days',
            'detail-test-ruleset',
            str_repeat('b', 64),
            'detail-obligation',
            environment: 'production',
        );
        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'manual_upload',
            str_repeat('c', 64),
            'detail-submission',
            createdBy: $this->userId,
        );
        $part = $this->submissions->addPart(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'jmhz-summary',
            'JMHZ',
            'office:synthetic',
            'run_revision',
            'revision:synthetic',
            str_repeat('d', 64),
        );
        $artifact = $this->submissions->storeArtifact(
            $this->supplierId,
            $submission['id'],
            $part['submission_row_version'],
            $part['id'],
            'outbound_xml',
            'outbound',
            'application/xml',
            '<synthetic/>',
            'test-xsd',
            'test-catalog',
            'manual_upload',
            'detail-artifact',
            $this->userId,
        );
        $this->submissions->recordIssue(
            $this->supplierId,
            $submission['id'],
            $artifact['submission_row_version'],
            $part['id'],
            'warning',
            'catalog',
            'SYNTHETIC_REVIEW',
            'office',
            'office:synthetic',
            ['sensitive_note' => 'never-return-this'],
            $this->userId,
        );

        $response = ($this->action)(
            $this->request($this->supplierId),
            new Response(),
            ['submissionId' => (string) $submission['id']],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'private, no-store',
            $response->getHeaderLine('Cache-Control'),
        );
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        $body = $this->json($response);
        self::assertSame('JMHZ', $body['submission']['agenda_code']);
        self::assertSame('draft', $body['submission']['status']);
        self::assertCount(1, $body['parts']);
        self::assertCount(1, $body['artifacts']);
        self::assertCount(1, $body['issues']);
        self::assertSame('outbound_xml', $body['artifacts'][0]['artifact_kind']);
        self::assertSame('SYNTHETIC_REVIEW', $body['issues'][0]['issue_code']);
        self::assertArrayNotHasKey(
            'content_ciphertext',
            $body['artifacts'][0],
        );
        self::assertArrayNotHasKey('artifact_sha256', $body['artifacts'][0]);
        self::assertArrayNotHasKey('details_ciphertext', $body['issues'][0]);
        self::assertStringNotContainsString(
            'never-return-this',
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    public function testRejectsBearerAndOtherTenant(): void
    {
        $bearer = ($this->action)(
            $this->request($this->supplierId, 'bearer'),
            new Response(),
            ['submissionId' => '1'],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($bearer)['error']['code'],
        );

        $otherTenant = ($this->action)(
            $this->request($this->otherSupplierId),
            new Response(),
            ['submissionId' => '1'],
        );
        self::assertSame(404, $otherTenant->getStatusCode());
        self::assertSame(
            'not_found',
            $this->json($otherTenant)['error']['code'],
        );
    }

    private function request(
        int $supplierId,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                '/api/payroll/submissions/1',
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
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
