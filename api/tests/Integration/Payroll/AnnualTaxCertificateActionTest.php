<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\AnnualTaxCertificateAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class AnnualTaxCertificateActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private AnnualTaxCertificateAction $action;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        self::assertNotNull($container);
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(AnnualTaxCertificateAction::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        $this->userId = (int) $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn();
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id = ?',
        )->execute([$this->supplierId]);
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

    public function testRejectsBearerBeforeGeneratingSensitiveCertificate(): void
    {
        $response = $this->action->generate(
            $this->request('bearer'),
            new Response(),
            [
                'employeeId' => '1',
                'kind' => 'advance',
                'year' => '2026',
            ],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($response)['error']['code'] ?? null,
        );
    }

    public function testRejectsUnknownKindAndUnsupportedYearWithExplanation(): void
    {
        $invalidKind = $this->action->generate(
            $this->request('session'),
            new Response(),
            [
                'employeeId' => '1',
                'kind' => 'other',
                'year' => '2026',
            ],
        );
        self::assertSame(422, $invalidKind->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($invalidKind)['error']['code'] ?? null,
        );

        $unsupportedYear = $this->action->generate(
            $this->request('session'),
            new Response(),
            [
                'employeeId' => '1',
                'kind' => 'withholding',
                'year' => '2025',
            ],
        );
        self::assertSame(422, $unsupportedYear->getStatusCode());
        $error = $this->json($unsupportedYear)['error'] ?? [];
        self::assertSame('tax_certificate_incomplete', $error['code'] ?? null);
        self::assertStringContainsString(
            '2025',
            (string) ($error['message'] ?? ''),
        );
    }

    private function request(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                '/api/payroll/people/1/documents/tax-certificate/advance/2026',
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
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
        self::assertIsArray($decoded);

        return $decoded;
    }
}
