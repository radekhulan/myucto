<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRegzelAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollRegzelActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRegzelAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $officeId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollRegzelAction::class);
        if (!$this->db->hasTable('payroll_regzel_employer_profiles')) {
            $this->markTestSkipped('Migrace 1284 neproběhla.');
        }

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
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier
                SET payroll_enabled = 1,
                    workplace_code = "3001",
                    data_box_id = "abc1234"
              WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol)
             VALUES (?, "REGZEL", "Syntetická účtárna", "1234567890")',
        )->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id,
                 employer_registration_number, social_security_office_code)
             VALUES (?, ?, "123456789", "110")',
        )->execute([$this->supplierId, $this->officeId]);
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

    public function testProfileRequiresExplicitEvidenceConfirmationAndOptimisticLock(): void
    {
        $missing = $this->action->profile(
            $this->request('GET', '/api/payroll/submissions/regzel/profile'),
            new Response(),
        );
        self::assertSame(200, $missing->getStatusCode());
        $missingData = $this->json($missing);
        self::assertNull($missingData['profile']);
        self::assertSame('3001', $missingData['suggested_tax_office_workplace_code']);

        $unconfirmed = $this->action->saveProfile(
            $this->request('PUT', '/api/payroll/submissions/regzel/profile', [
                'row_version' => 0,
                'social_enterprise' => false,
                'employment_agency' => true,
                'protected_labor_market' => false,
                'tax_office_code' => '2000',
                'tax_office_workplace_code' => '2002',
                'evidence_confirmed' => false,
            ]),
            new Response(),
        );
        self::assertSame(422, $unconfirmed->getStatusCode());
        self::assertSame(
            'regzel_evidence_confirmation_required',
            $this->json($unconfirmed)['error']['code'],
        );

        $epoCode = $this->action->saveProfile(
            $this->request('PUT', '/api/payroll/submissions/regzel/profile', [
                'row_version' => 0,
                'social_enterprise' => false,
                'employment_agency' => true,
                'protected_labor_market' => false,
                'tax_office_code' => '451',
                'tax_office_workplace_code' => null,
                'evidence_confirmed' => true,
            ]),
            new Response(),
        );
        self::assertSame(422, $epoCode->getStatusCode());
        self::assertSame('regzel_tax_office_invalid', $this->json($epoCode)['error']['code']);

        $missingWorkplace = $this->action->saveProfile(
            $this->request('PUT', '/api/payroll/submissions/regzel/profile', [
                'row_version' => 0,
                'social_enterprise' => false,
                'employment_agency' => true,
                'protected_labor_market' => false,
                'tax_office_code' => '2000',
                'tax_office_workplace_code' => null,
                'evidence_confirmed' => true,
            ]),
            new Response(),
        );
        self::assertSame(422, $missingWorkplace->getStatusCode());
        self::assertSame(
            'regzel_tax_office_workplace_required',
            $this->json($missingWorkplace)['error']['code'],
        );

        $mismatchedWorkplace = $this->action->saveProfile(
            $this->request('PUT', '/api/payroll/submissions/regzel/profile', [
                'row_version' => 0,
                'social_enterprise' => false,
                'employment_agency' => true,
                'protected_labor_market' => false,
                'tax_office_code' => '2300',
                'tax_office_workplace_code' => '3001',
                'evidence_confirmed' => true,
            ]),
            new Response(),
        );
        self::assertSame(422, $mismatchedWorkplace->getStatusCode());
        self::assertSame(
            'regzel_tax_office_workplace_mismatch',
            $this->json($mismatchedWorkplace)['error']['code'],
        );

        $invalidPayerReference = $this->action->saveProfile(
            $this->request('PUT', '/api/payroll/submissions/regzel/profile', [
                'row_version' => 0,
                'social_enterprise' => false,
                'employment_agency' => true,
                'protected_labor_market' => false,
                'tax_office_code' => '2000',
                'tax_office_workplace_code' => '2002',
                'payer_reference_number' => '123456789',
                'evidence_confirmed' => true,
            ]),
            new Response(),
        );
        self::assertSame(422, $invalidPayerReference->getStatusCode());
        self::assertSame(
            'regzel_payer_reference_invalid',
            $this->json($invalidPayerReference)['error']['code'],
        );

        $saved = $this->action->saveProfile(
            $this->request('PUT', '/api/payroll/submissions/regzel/profile', [
                'row_version' => 0,
                'social_enterprise' => false,
                'employment_agency' => true,
                'protected_labor_market' => false,
                'tax_office_code' => '2000',
                'tax_office_workplace_code' => '2002',
                'payer_reference_number' => '612345678',
                'evidence_confirmed' => true,
            ]),
            new Response(),
        );
        self::assertSame(200, $saved->getStatusCode());
        $savedProfile = $this->json($saved)['profile'];
        self::assertSame(1, $savedProfile['row_version']);
        self::assertSame('2000', $savedProfile['tax_office_code']);
        self::assertSame('2002', $savedProfile['tax_office_workplace_code']);
        self::assertSame('612345678', $savedProfile['payer_reference_number']);
        self::assertTrue($savedProfile['is_complete']);

        $conflict = $this->action->saveProfile(
            $this->request('PUT', '/api/payroll/submissions/regzel/profile', [
                'row_version' => 0,
                'social_enterprise' => true,
                'employment_agency' => false,
                'protected_labor_market' => false,
                'tax_office_code' => '2000',
                'tax_office_workplace_code' => '2002',
                'evidence_confirmed' => true,
            ]),
            new Response(),
        );
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('row_version_conflict', $this->json($conflict)['error']['code']);
    }

    public function testPrepareListsAndDownloadsOnlyWithinTenantAndEnvironment(): void
    {
        $this->saveConfirmedProfile();
        $prepared = $this->action->prepare(
            $this->request('POST', '/api/payroll/submissions/regzel/prepare', [
                'office_id' => $this->officeId,
                'environment' => 'production',
                'evidence_confirmed' => true,
                'idempotency_key' => 'regzel-action-production',
            ]),
            new Response(),
        );
        self::assertSame(201, $prepared->getStatusCode());
        $snapshot = $this->json($prepared)['snapshot'];
        self::assertSame('production', $snapshot['environment']);
        self::assertArrayNotHasKey('xml', $snapshot);

        $listed = $this->action->snapshots(
            $this->request(
                'GET',
                '/api/payroll/submissions/regzel/snapshots?environment=production',
            ),
            new Response(),
        );
        self::assertSame(200, $listed->getStatusCode());
        self::assertCount(1, $this->json($listed)['items']);

        $download = $this->action->download(
            $this->request(
                'GET',
                '/api/payroll/submissions/regzel/snapshots/'
                    . $snapshot['id'] . '/xml?environment=production',
            ),
            new Response(),
            ['id' => (string) $snapshot['id']],
        );
        self::assertSame(200, $download->getStatusCode());
        self::assertSame(
            'application/xml; charset=utf-8',
            $download->getHeaderLine('Content-Type'),
        );
        self::assertStringContainsString('REGZELDOPL25-', $download->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('<REGZELDOPL', (string) $download->getBody());

        $foreign = $this->action->download(
            $this->request(
                'GET',
                '/api/payroll/submissions/regzel/snapshots/'
                    . $snapshot['id'] . '/xml?environment=production',
                [],
                $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $snapshot['id']],
        );
        self::assertSame(404, $foreign->getStatusCode());
    }

    public function testSessionPermissionEnvironmentAndConfirmationAreFailClosed(): void
    {
        $bearer = $this->action->profile(
            $this->request(
                'GET',
                '/api/payroll/submissions/regzel/profile',
                [],
                null,
                'bearer',
            ),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);

        $readonly = new EffectiveRole(
            77,
            'REGZEL čtení',
            'staff',
            true,
            ['payroll.submissions' => AccessLevel::READ->value],
        );
        $forbidden = $this->action->saveProfile(
            $this->request(
                'PUT',
                '/api/payroll/submissions/regzel/profile',
                [
                    'row_version' => 0,
                    'social_enterprise' => false,
                    'employment_agency' => false,
                    'protected_labor_market' => false,
                    'tax_office_code' => '2000',
                    'tax_office_workplace_code' => '2002',
                    'evidence_confirmed' => true,
                ],
                null,
                'session',
                $readonly,
            ),
            new Response(),
        );
        self::assertSame(403, $forbidden->getStatusCode());

        $this->saveConfirmedProfile();
        // Příprava XML potvrzení NEVYŽADUJE — evidence se stvrzuje jednou při
        // uložení profilu. Tělo bez `evidence_confirmed` proto musí projít.
        $withoutConfirmation = $this->action->prepare(
            $this->request('POST', '/api/payroll/submissions/regzel/prepare', [
                'office_id' => $this->officeId,
                'environment' => 'production',
                'idempotency_key' => 'regzel-action-unconfirmed',
            ]),
            new Response(),
        );
        self::assertContains(
            $withoutConfirmation->getStatusCode(),
            [200, 201],
            'Příprava XML nesmí vyžadovat druhé potvrzení evidence.',
        );

        $unsupported = $this->action->prepare(
            $this->request('POST', '/api/payroll/submissions/regzel/prepare', [
                'office_id' => $this->officeId,
                'environment' => 'staging',
                'evidence_confirmed' => true,
                'idempotency_key' => 'regzel-action-unsupported',
            ]),
            new Response(),
        );
        self::assertSame(422, $unsupported->getStatusCode());
        self::assertSame('regzel_environment_invalid', $this->json($unsupported)['error']['code']);
    }

    private function saveConfirmedProfile(): void
    {
        $response = $this->action->saveProfile(
            $this->request('PUT', '/api/payroll/submissions/regzel/profile', [
                'row_version' => 0,
                'social_enterprise' => true,
                'employment_agency' => false,
                'protected_labor_market' => true,
                'tax_office_code' => '2000',
                'tax_office_workplace_code' => '2002',
                'evidence_confirmed' => true,
            ]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string,mixed> $body
     */
    private function request(
        string $method,
        string $uri,
        array $body = [],
        ?int $supplierId = null,
        string $authMethod = 'session',
        ?EffectiveRole $role = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withParsedBody($body);
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }
        return $request;
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
