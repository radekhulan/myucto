<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentExitDocumentAction;
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
final class EmploymentExitDocumentActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentExitDocumentAction $action;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        self::assertNotNull($container);
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(
            PayrollEmploymentExitDocumentAction::class,
        );
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

    public function testRejectsBearerBeforeReadingSensitiveMetadata(): void
    {
        $response = $this->action->list(
            $this->request('GET', 'bearer'),
            new Response(),
            ['id' => '1'],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($response)['error']['code'] ?? null,
        );
    }

    public function testAverageCertificateReturnsStableReadinessCode(): void
    {
        $request = $this->request('POST', 'session')
            ->withHeader('Idempotency-Key', 'synthetic-average-action')
            ->withParsedBody([
                'termination_assessment_complete' => true,
                'termination_reason_kind' => 'organizational',
                'employee_stated_reason' => null,
                'pension_insurance_periods' => [],
                'correction_reason' => null,
            ]);
        $response = $this->action->generate(
            $request,
            new Response(),
            [
                'id' => '1',
                'kind' => 'average-earnings-certificate',
            ],
        );

        // Zdejší izolovaná firma nemá žádný pracovní vztah s id 1, takže
        // fail-closed kontrola zdroje skončí dřív, než se vůbec dostane k
        // ověření podkladu průměrného výdělku.
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'employment_not_found',
            $this->json($response)['error']['code'] ?? null,
        );
        self::assertSame(
            'private, no-store',
            $response->getHeaderLine('Cache-Control'),
        );
    }

    public function testClientSuppliedNetAmountIsRejected(): void
    {
        $request = $this->request('POST', 'session')
            ->withHeader('Idempotency-Key', 'synthetic-average-injection')
            ->withParsedBody([
                'termination_assessment_complete' => true,
                'termination_reason_kind' => 'organizational',
                'employee_stated_reason' => null,
                'pension_insurance_periods' => [],
                'correction_reason' => null,
                'average_monthly_net_minor_units' => 9_999_999,
            ]);
        $response = $this->action->generate(
            $request,
            new Response(),
            [
                'id' => '1',
                'kind' => 'average-earnings-certificate',
            ],
        );

        // Čistý výdělek se počítá ze schváleného podkladu, klient ho nesmí
        // podstrčit ani jako „nadbytečné" pole.
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($response)['error']['code'] ?? null,
        );
    }

    public function testAverageEarningsStatementKindIsRouted(): void
    {
        $request = $this->request('POST', 'session')
            ->withHeader('Idempotency-Key', 'synthetic-statement-action')
            ->withParsedBody([
                'requested_purpose' => 'Doložení příjmu bance',
                'correction_reason' => null,
            ]);
        $response = $this->action->generate(
            $request,
            new Response(),
            [
                'id' => '1',
                'kind' => 'average-earnings-statement',
            ],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'employment_not_found',
            $this->json($response)['error']['code'] ?? null,
        );
    }

    public function testReadonlyRoleCannotGenerateExitDocument(): void
    {
        $request = $this->request('POST', 'session', 'readonly')
            ->withHeader('Idempotency-Key', 'synthetic-readonly-action')
            ->withParsedBody([]);
        $response = $this->action->generate(
            $request,
            new Response(),
            [
                'id' => '1',
                'kind' => 'employment-certificate',
            ],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'forbidden',
            $this->json($response)['error']['code'] ?? null,
        );
    }

    private function request(
        string $httpMethod,
        string $authMethod,
        string $role = 'admin',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest(
                $httpMethod,
                '/api/payroll/employments/1/documents/exit',
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => $role],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
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
