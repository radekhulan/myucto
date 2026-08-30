<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPaymentAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Payment\PayrollEnforcementLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollHealthInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollIncomeTaxLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollNetWageLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentDownloadGrantService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentExportService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentQueryService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationQueryService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService;
use MyInvoice\Service\Payroll\Payment\PayrollPersonAccountVerificationService;
use MyInvoice\Service\Payroll\Payment\PayrollSocialInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollRiskySavingsLiabilityMaterializer;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollPaymentApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPaymentAction $action;
    private ContainerInterface $container;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $this->container = $container;
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollPaymentAction::class);
        $pdo = $this->db->pdo();
        $sourceSupplier = $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        );
        $sourceUser = $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        );
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        self::assertInstanceOf(\PDOStatement::class, $sourceUser);
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
        $this->userId = (int) $sourceUser->fetchColumn();
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
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);
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

    public function testListsEmptyPeriodWithPrivateNoStoreResponse(): void
    {
        $response = $this->action->listLiabilities(
            $this->request('session')->withQueryParams(['period' => '2026-08']),
            new Response(),
            [],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([
            'period' => '2026-08',
            'items' => [],
            'total' => 0,
            'totals' => [
                'amount_minor' => 0,
                'allocated_minor' => 0,
                'settled_minor' => 0,
            ],
            'limit' => 50,
            'offset' => 0,
        ], $this->json($response));
    }

    public function testListsEmptyReconciliationPeriodWithPrivateNoStoreResponse(): void
    {
        $response = $this->action->listReconciliation(
            $this->request('session')->withQueryParams(['period' => '2026-08']),
            new Response(),
            [],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([
            'period' => '2026-08',
            'allocations' => [],
            'allocations_truncated' => false,
            'incoming_liabilities' => [],
            'incoming_liabilities_truncated' => false,
            'offered_limit' => 50,
            'matches' => [],
            'matches_total' => 0,
            'matches_limit' => 25,
            'matches_offset' => 0,
            'reversible_matches' => [],
            'bank_evidence' => [],
            'bank_evidence_truncated' => false,
            'cash_evidence' => [],
            'cash_evidence_truncated' => false,
        ], $this->json($response));
    }

    public function testReconciliationWriteValidatesBodyAndRequiresSession(): void
    {
        $invalid = $this->action->matchPayment(
            $this->request('session', 'POST')->withParsedBody([
                'allocation_id' => '1',
                'amount_minor' => 100,
                'evidence' => [],
                'idempotency_key' => 'synthetic-invalid',
            ]),
            new Response(),
            [],
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($invalid)['error']['code'] ?? null,
        );

        $bearer = $this->action->reversePayment(
            $this->request('bearer', 'POST')->withParsedBody([
                'source_match_id' => 1,
                'amount_minor' => 100,
                'evidence' => [
                    'kind' => 'cash',
                    'cash_document_id' => 1,
                ],
                'idempotency_key' => 'synthetic-bearer',
            ]),
            new Response(),
            [],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($bearer)['error']['code'] ?? null,
        );

        $invalidIncoming = $this->action->matchIncomingRefund(
            $this->request('session', 'POST')->withParsedBody([
                'liability_id' => '1',
                'amount_minor' => 100,
                'evidence' => [],
                'idempotency_key' => 'synthetic-invalid-incoming',
            ]),
            new Response(),
            [],
        );
        self::assertSame(422, $invalidIncoming->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($invalidIncoming)['error']['code'] ?? null,
        );

        $incomingBearer = $this->action->reverseIncomingRefund(
            $this->request('bearer', 'POST')->withParsedBody([
                'source_match_id' => 1,
                'amount_minor' => 100,
                'evidence' => [
                    'kind' => 'cash',
                    'cash_document_id' => 1,
                ],
                'idempotency_key' => 'synthetic-incoming-bearer',
            ]),
            new Response(),
            [],
        );
        self::assertSame(403, $incomingBearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($incomingBearer)['error']['code'] ?? null,
        );
    }

    public function testListsPayerOptionsAndEmptyBatchesWithoutSecrets(): void
    {
        $optionsResponse = $this->action->listPayerOptions(
            $this->request('session'),
            new Response(),
            [],
        );
        self::assertSame(200, $optionsResponse->getStatusCode());
        self::assertSame(
            'private, no-store',
            $optionsResponse->getHeaderLine('Cache-Control'),
        );
        $options = $this->json($optionsResponse)['items'] ?? null;
        self::assertIsArray($options);
        foreach ($options as $option) {
            self::assertIsArray($option);
            self::assertArrayNotHasKey('account_number', $option);
            self::assertArrayNotHasKey('iban', $option);
            self::assertArrayNotHasKey('bic', $option);
        }

        $batchesResponse = $this->action->listBatches(
            $this->request('session')->withQueryParams([
                'period' => '2026-08',
            ]),
            new Response(),
            [],
        );
        self::assertSame(200, $batchesResponse->getStatusCode());
        self::assertSame([
            'period' => '2026-08',
            'items' => [],
        ], $this->json($batchesResponse));
    }

    public function testBatchCreationValidatesExactRequestShape(): void
    {
        $response = $this->action->createBatch(
            $this->request('session', 'POST')->withParsedBody([
                'export_format' => 'abo',
                'payer_reference' => 'currency:1',
                'items' => [[
                    'liability_id' => '1',
                    'amount_minor' => 100,
                ]],
            ]),
            new Response(),
            [],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($response)['error']['code'] ?? null,
        );
        self::assertStringContainsString(
            'celá čísla',
            (string) ($this->json($response)['error']['message'] ?? ''),
        );
    }

    public function testGenericMaterializationReportsBlockedKindsWithoutPartialErrorResponse(): void
    {
        $response = $this->action->materializeLiabilities(
            $this->request('session', 'POST'),
            new Response(),
            ['revisionId' => '999999999'],
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = $this->json($response);
        self::assertSame([], $payload['liability_ids'] ?? null);
        self::assertSame(0, $payload['created_count'] ?? null);
        self::assertSame(
            [
                'net_wage',
                'health_insurance',
                'social_insurance',
                'income_tax',
                'insolvency',
                'enforcement',
                'risky_savings',
                'statutory_insurance',
            ],
            array_column($payload['preparation_issues'] ?? [], 'liability_kind'),
        );
        self::assertStringNotContainsString(
            'ciphertext',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $bearer = $this->action->materializeLiabilities(
            $this->request('bearer', 'POST'),
            new Response(),
            ['revisionId' => '999999999'],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($bearer)['error']['code'] ?? null,
        );
    }

    public function testDownloadRejectsBearerBeforeReadingToken(): void
    {
        $readonly = $this->action->downloadExport(
            $this->request('session', 'POST', 'readonly')->withParsedBody([
                'token' => str_repeat('A', 43),
            ]),
            new Response(),
            [],
        );
        self::assertSame(403, $readonly->getStatusCode());
        self::assertSame(
            'forbidden',
            $this->json($readonly)['error']['code'] ?? null,
        );

        $writeAllowed = $this->action->downloadExport(
            $this->request('session', 'POST')->withParsedBody([
                'token' => 5,
            ]),
            new Response(),
            [],
        );
        self::assertSame(422, $writeAllowed->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($writeAllowed)['error']['code'] ?? null,
        );

        $response = $this->action->downloadExport(
            $this->request('bearer', 'POST')->withParsedBody([
                'token' => str_repeat('A', 43),
            ]),
            new Response(),
            [],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($response)['error']['code'] ?? null,
        );
    }

    public function testRejectsBearerAndInvalidPeriod(): void
    {
        $bearer = $this->action->listLiabilities(
            $this->request('bearer')->withQueryParams(['period' => '2026-08']),
            new Response(),
            [],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($bearer)['error']['code'] ?? null,
        );

        $invalid = $this->action->listLiabilities(
            $this->request('session')->withQueryParams(['period' => '08/2026']),
            new Response(),
            [],
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->json($invalid)['error']['code'] ?? null,
        );
    }

    public function testVerifiesEmployeeAccountWithoutReturningSensitiveValue(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická ověřovaná osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked,
                 allocation_basis_points, effective_from, is_active,
                 row_version)
             VALUES (?, ?, "Syntetický účet", "enc:v2:synthetic",
                     UNHEX(?), "••••0005", 10000, "2026-01-01", 1, 1)',
        )->execute([
            $this->supplierId,
            $employeeId,
            hash(
                'sha256',
                "synthetic-account:{$this->supplierId}:{$employeeId}",
            ),
        ]);
        $accountId = (int) $this->db->pdo()->lastInsertId();

        $response = $this->action->verifyPersonAccount(
            $this->request('session', 'POST')->withParsedBody([
                'verification_source' => 'employee_confirmation',
                'verified_on' => '2026-08-04',
                'row_version' => 1,
            ]),
            new Response(),
            [
                'employeeId' => (string) $employeeId,
                'accountId' => (string) $accountId,
            ],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $account = $this->json($response)['account'];
        self::assertSame($accountId, $account['id']);
        self::assertSame(
            'employee_confirmation',
            $account['verification_source'],
        );
        self::assertSame('2026-08-04', $account['verified_on']);
        self::assertSame(2, $account['row_version']);
        self::assertArrayNotHasKey('bank_account_ciphertext', $account);
        self::assertArrayNotHasKey('bank_account_hash', $account);

        $stale = $this->action->verifyPersonAccount(
            $this->request('session', 'POST')->withParsedBody([
                'verification_source' => 'user_verified',
                'verified_on' => '2026-08-04',
                'row_version' => 1,
            ]),
            new Response(),
            [
                'employeeId' => (string) $employeeId,
                'accountId' => (string) $accountId,
            ],
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame(2, $this->json($stale)['error']['current_row_version']);
    }

    public function testAuditFailureRollsBackAccountVerification(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická auditní osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked,
                 allocation_basis_points, effective_from, is_active,
                 row_version)
             VALUES (?, ?, "Auditní účet", "enc:v2:synthetic",
                     UNHEX(?), "••••0013", 10000, "2026-01-01", 1, 1)',
        )->execute([
            $this->supplierId,
            $employeeId,
            hash(
                'sha256',
                "synthetic-audit-account:{$this->supplierId}:{$employeeId}",
            ),
        ]);
        $accountId = (int) $this->db->pdo()->lastInsertId();
        $failingLogger = new class($this->db) extends ActivityLogger {
            public function log(
                string $action,
                ?int $userId = null,
                ?string $entityType = null,
                ?int $entityId = null,
                ?array $payload = null,
                ?string $ip = null,
                ?string $userAgent = null,
                ?int $supplierId = null,
            ): void {
                throw new \RuntimeException('synthetic payroll audit failure');
            }
        };
        $action = new PayrollPaymentAction(
            $this->container->get(PayrollPaymentQueryService::class),
            $this->container->get(
                PayrollPaymentReconciliationQueryService::class,
            ),
            $this->container->get(
                PayrollPaymentReconciliationService::class,
            ),
            $this->container->get(PayrollNetWageLiabilityMaterializer::class),
            $this->container->get(
                PayrollHealthInsuranceLiabilityMaterializer::class,
            ),
            $this->container->get(
                PayrollSocialInsuranceLiabilityMaterializer::class,
            ),
            $this->container->get(
                PayrollIncomeTaxLiabilityMaterializer::class,
            ),
            $this->container->get(
                \MyInvoice\Service\Payroll\Payment\PayrollInsolvencyLiabilityMaterializer::class,
            ),
            $this->container->get(
                PayrollEnforcementLiabilityMaterializer::class,
            ),
            $this->container->get(
                PayrollRiskySavingsLiabilityMaterializer::class,
            ),
            $this->container->get(
                \MyInvoice\Service\Payroll\Payment\PayrollAccidentInsuranceLiabilityMaterializer::class,
            ),
            $this->container->get(PayrollPersonAccountVerificationService::class),
            $this->container->get(PayrollPaymentBatchBuilder::class),
            $this->container->get(PayrollPaymentExportService::class),
            $this->container->get(PayrollPaymentDownloadGrantService::class),
            $this->container->get(PayrollModuleAccess::class),
            $this->container->get(\MyInvoice\Service\Payroll\PayrollProductionGate::class),
            $failingLogger,
            $this->container->get(IpMatcher::class),
            $this->db,
        );

        try {
            $action->verifyPersonAccount(
                $this->request('session', 'POST')->withParsedBody([
                    'verification_source' => 'bank_document',
                    'verified_on' => '2026-08-04',
                    'row_version' => 1,
                ]),
                new Response(),
                [
                    'employeeId' => (string) $employeeId,
                    'accountId' => (string) $accountId,
                ],
            );
            self::fail('Selhání auditu musí shodit ověření účtu.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'synthetic payroll audit failure',
                $exception->getMessage(),
            );
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT verification_source, verified_on, verified_by, row_version
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        );
        $statement->execute([
            $this->supplierId,
            $employeeId,
            $accountId,
        ]);
        self::assertSame([
            'verification_source' => null,
            'verified_on' => null,
            'verified_by' => null,
            'row_version' => 1,
        ], $statement->fetch(\PDO::FETCH_ASSOC));
    }

    private function request(
        string $authMethod,
        string $method = 'GET',
        string $role = 'admin',
    ): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/payments/liabilities')
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
        $value = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException('API odpověď není objekt.');
        }

        return $value;
    }
}
