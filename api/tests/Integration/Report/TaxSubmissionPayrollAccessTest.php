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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Archiv podání: mzdová vyúčtování jen s `payroll.reports`, celé XML v detailu
 * jen s `reports.export` (stejně jako jeho stažení).
 */
#[Group('integration')]
final class TaxSubmissionPayrollAccessTest extends TestCase
{
    private Connection $db;
    private TaxSubmissionRepository $repo;
    private TaxSubmissionAction $action;
    private int $supplierId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $repo = $container->get(TaxSubmissionRepository::class);
        $action = $container->get(TaxSubmissionAction::class);
        if (!$db instanceof Connection || !$repo instanceof TaxSubmissionRepository || !$action instanceof TaxSubmissionAction) {
            throw new \RuntimeException('Služby archivu podání nejsou dostupné.');
        }
        $this->db = $db;
        $this->repo = $repo;
        $this->action = $action;
        $pdo = $db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }
        $pdo->beginTransaction();
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

    public function testPayrollStatementsNeedPayrollReportsPermission(): void
    {
        $payrollId = $this->seed('dpzvd6');
        $vatId = $this->seed('dphdp3');
        $reportsOnly = new EffectiveRole(501, 'Jen sestavy', 'staff', true, ['reports' => 1, 'reports.export' => 1]);
        $payrollReports = new EffectiveRole(
            502,
            'Sestavy i mzdové',
            'staff',
            true,
            ['reports' => 1, 'reports.export' => 1, 'payroll.reports' => 1],
        );

        $list = $this->call('list', '/api/reports/submissions', $reportsOnly, ['limit' => '200']);
        self::assertSame(200, $list->getStatusCode());
        $body = $this->json($list);
        self::assertNotContains($payrollId, array_column($body['data'], 'id'));
        self::assertContains($vatId, array_column($body['data'], 'id'));
        self::assertNotContains('dpzvd6', $body['meta']['form_codes']);

        $scoped = $this->json($this->call('list', '/api/reports/submissions', $reportsOnly, ['scope' => 'payroll']));
        self::assertSame([], $scoped['data']);

        foreach (['detail' => '', 'downloadXml' => '/xml', 'payment' => '/payment'] as $method => $suffix) {
            self::assertSame(
                404,
                $this->call($method, "/api/reports/submissions/{$payrollId}{$suffix}", $reportsOnly, [], $payrollId)
                    ->getStatusCode(),
                $method,
            );
        }

        $allowed = $this->json($this->call('list', '/api/reports/submissions', $payrollReports, ['limit' => '200']));
        self::assertContains($payrollId, array_column($allowed['data'], 'id'));
        self::assertNotSame(
            404,
            $this->call('detail', "/api/reports/submissions/{$payrollId}", $payrollReports, [], $payrollId)
                ->getStatusCode(),
        );
    }

    public function testDetailOmitsXmlWithoutExportPermission(): void
    {
        $id = $this->seed('dphdp3');
        $readOnly = new EffectiveRole(503, 'Jen čtení sestav', 'staff', true, ['reports' => 1]);
        $exporter = new EffectiveRole(504, 'Export sestav', 'staff', true, ['reports' => 1, 'reports.export' => 1]);

        $limited = $this->call('detail', "/api/reports/submissions/{$id}", $readOnly, [], $id);
        self::assertSame(200, $limited->getStatusCode(), (string) $limited->getBody());
        self::assertArrayNotHasKey('xml_content', $this->json($limited));
        self::assertSame('dphdp3', $this->json($limited)['form_code']);

        $full = $this->call('detail', "/api/reports/submissions/{$id}", $exporter, [], $id);
        self::assertSame(200, $full->getStatusCode());
        self::assertStringContainsString('<Pisemnost', (string) $this->json($full)['xml_content']);
    }

    private function seed(string $formCode): int
    {
        return $this->repo->archive(
            $this->supplierId,
            $formCode,
            2011,
            1,
            null,
            '<?xml version="1.0"?><Pisemnost m="' . $formCode . '"/>',
            ['t' => 1],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
    }

    /** @param array<string,string> $query */
    private function call(string $method, string $path, EffectiveRole $role, array $query = [], ?int $id = null): ResponseInterface
    {
        $request = $this->request($path, $role)->withQueryParams($query);
        $response = (new ResponseFactory())->createResponse();

        return $id === null
            ? $this->action->{$method}($request, $response)
            : $this->action->{$method}($request, $response, ['id' => (string) $id]);
    }

    private function request(string $path, EffectiveRole $role): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', $role);
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
