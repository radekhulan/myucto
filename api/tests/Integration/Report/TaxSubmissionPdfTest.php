<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Action\Report\TaxSubmissionAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Unit\Service\Report\TaxSubmissionPrintoutTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * GET /api/reports/submissions/{id}/pdf — PDF odeslaného přiznání DPH a KH z uloženého
 * XML včetně údajů o doručení. Cizí firma podání nevidí (404), mzdové a placené výkazy
 * mimo tisk vrací 422. Izolace: vše v transakci s rollbackem, syntetická data.
 */
#[Group('integration')]
final class TaxSubmissionPdfTest extends TestCase
{
    private Connection $db;
    private TaxSubmissionRepository $repo;
    private \DI\Container|\Psr\Container\ContainerInterface $container;

    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db   = $this->container->get(Connection::class);
            $this->repo = $this->container->get(TaxSubmissionRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $ids = array_map('intval', $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        $this->supplierId = $ids[0] ?? 0;
        $this->otherSupplierId = $ids[1] ?? 0;
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->otherSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (dvě firmy a uživatel) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    private function seed(int $supplierId, string $formCode, string $xml, string $status = 'submitted'): int
    {
        return $this->repo->archive(
            $supplierId, $formCode, 2011, 3, null, $xml, ['t' => 1], 'passed', [], $this->userId, 'B', $status,
        );
    }

    private function call(string $method, int $id, int $supplierId, bool $client = false): ResponseInterface
    {
        $action = $this->container->get(TaxSubmissionAction::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/reports/submissions/' . $id . '/pdf')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        if ($client) {
            $request = $request->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Klient podání', 'client', true, ['reports.submissions' => 1]));
        }
        return $action->{$method}($request, (new ResponseFactory())->createResponse(), ['id' => (string) $id]);
    }

    public function testVatReturnAndControlStatementDownloadAsPdf(): void
    {
        foreach (['dphdp3' => TaxSubmissionPrintoutTest::DP3_XML, 'dphkh1' => TaxSubmissionPrintoutTest::KH_XML] as $form => $xml) {
            $id = $this->seed($this->supplierId, $form, $xml);
            $response = $this->call('downloadPdf', $id, $this->supplierId);

            self::assertSame(200, $response->getStatusCode(), $form . ': ' . $response->getBody());
            self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString('.pdf"', $response->getHeaderLine('Content-Disposition'));
            self::assertStringContainsString(strtoupper($form), $response->getHeaderLine('Content-Disposition'));
            self::assertStringStartsWith('%PDF', (string) $response->getBody());
        }
    }

    public function testClientWithSubmissionAccessDownloadsPdf(): void
    {
        $id = $this->seed($this->supplierId, 'dphdp3', TaxSubmissionPrintoutTest::DP3_XML);
        $response = $this->call('downloadPdf', $id, $this->supplierId, true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
    }

    public function testOtherCompanySubmissionIsNotFound(): void
    {
        $foreignId = $this->seed($this->otherSupplierId, 'dphdp3', TaxSubmissionPrintoutTest::DP3_XML);

        $response = $this->call('downloadPdf', $foreignId, $this->supplierId);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('%PDF', (string) $response->getBody());

        self::assertSame(200, $this->call('downloadPdf', $foreignId, $this->otherSupplierId)->getStatusCode());
    }

    public function testUnsupportedFormIsRejected(): void
    {
        $id = $this->seed($this->supplierId, 'dpfdp7', '<?xml version="1.0"?><Pisemnost><DPFDP7/></Pisemnost>');

        $response = $this->call('downloadPdf', $id, $this->supplierId);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('pdf_unsupported_form', json_decode((string) $response->getBody(), true)['error']['code'] ?? null);
    }

    public function testDetailExplainsMissingReceipt(): void
    {
        $id = $this->seed($this->supplierId, 'dphdp3', TaxSubmissionPrintoutTest::DP3_XML);

        $body = json_decode((string) $this->call('detail', $id, $this->supplierId)->getBody(), true);
        $evidence = $body['receipt_evidence'] ?? $body['data']['receipt_evidence'] ?? null;
        self::assertIsArray($evidence);
        self::assertFalse($evidence['available']);
        self::assertSame('submitted_outside', $evidence['reason']);
    }
}
