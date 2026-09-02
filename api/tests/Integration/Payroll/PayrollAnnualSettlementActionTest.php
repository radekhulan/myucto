<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAnnualSettlementAction;
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
final class PayrollAnnualSettlementActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAnnualSettlementAction $action;
    private int $supplierId;
    private int $userId;
    private int $employeeId = 1;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('Aplikační kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollAnnualSettlementAction::class);
        if (!$db instanceof Connection || !$action instanceof PayrollAnnualSettlementAction) {
            throw new \RuntimeException('Roční zúčtování není v kontejneru dostupné.');
        }
        $this->db = $db;
        $this->action = $action;

        $pdo = $this->db->pdo();
        $supplierQuery = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $userQuery = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1');
        if ($supplierQuery === false || $userQuery === false) {
            throw new \RuntimeException('Výchozí syntetická data nelze načíst.');
        }
        $sourceSupplierId = (int) ($supplierQuery->fetchColumn() ?: 0);
        $this->userId = (int) ($userQuery->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická roční osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
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

    public function testRejectsCaregiverListBeyondDatabasePositionLimit(): void
    {
        $caregiver = [
            'given_name' => 'Jana',
            'family_name' => 'Syntetická',
            'birth_date' => '1990-01-01',
            'months_mask' => 'ANNNNNNNNNNN',
        ];
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'PUT',
                '/api/payroll/annual-settlement/2026/employees/x/request',
            )
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(
                1,
                'Syntetická mzdová role',
                'staff',
                true,
                ['payroll.documents' => AccessLevel::WRITE->value],
            ))
            ->withParsedBody([
                'request_status' => 'unknown',
                'prior_employers' => 'unknown',
                'filing_obligation' => 'unknown',
                'annual_claims' => 'unknown',
                'other_household_caregiver_status' => 'present',
                'other_household_caregivers' => array_fill(0, 101, $caregiver),
            ]);

        $response = $this->action->saveRequest(
            $request,
            new Response(),
            ['year' => '2026', 'employeeId' => (string) $this->employeeId],
        );

        self::assertSame(422, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        $error = $body['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('validation_failed', $error['code'] ?? null);
    }

    /**
     * Regrese: zaškrtnutá „podaná žádost“ bez data odmítla uložení celého
     * formuláře, takže účetní přišla i o to, co vyplnila správně. Datum je
     * podmínka PROVEDENÍ (§ 38ch odst. 1), ne podmínka evidence.
     */
    public function testRequestedStatusSavesEvenWithoutTheRequestDate(): void
    {
        $response = $this->action->saveRequest(
            $this->saveRequestFor([
                'request_status' => 'requested',
                'prior_employers' => 'unknown',
                'filing_obligation' => 'unknown',
                'annual_claims' => 'unknown',
                'note' => 'Rozdělaná evidence, datum dopíšu z papíru.',
            ]),
            new Response(),
            ['year' => '2026', 'employeeId' => (string) $this->employeeId],
        );

        self::assertSame(200, $response->getStatusCode());
        $saved = $this->body($response)['request'] ?? null;
        self::assertIsArray($saved);
        self::assertSame('requested', $saved['request_status'] ?? null);
        self::assertArrayHasKey('requested_on', $saved);
        self::assertNull($saved['requested_on']);
        self::assertSame(
            'Rozdělaná evidence, datum dopíšu z papíru.',
            $saved['note'] ?? null,
        );
    }

    /** Totéž pro doklady předchozích plátců (§ 38ch odst. 3). */
    public function testDocumentedPriorEmployersSaveEvenWithoutTheReceiptDate(): void
    {
        $response = $this->action->saveRequest(
            $this->saveRequestFor([
                'request_status' => 'unknown',
                'prior_employers' => 'all_documented',
                'filing_obligation' => 'unknown',
                'annual_claims' => 'unknown',
            ]),
            new Response(),
            ['year' => '2026', 'employeeId' => (string) $this->employeeId],
        );

        self::assertSame(200, $response->getStatusCode());
        $saved = $this->body($response)['request'] ?? null;
        self::assertIsArray($saved);
        self::assertSame('all_documented', $saved['prior_employers'] ?? null);
        self::assertArrayHasKey('prior_documents_received_on', $saved);
        self::assertNull($saved['prior_documents_received_on']);
    }

    /**
     * Regrese: povinnost podat přiznání bez důvodu a ročně uplatňované
     * položky bez popisu odmítaly uložení. Obě jsou přitom samy o sobě
     * překážkou provedení, takže neúplný popis nic nemění — jen mizela práce.
     */
    public function testFilingObligationAndAnnualClaimsSaveWithoutTheirFreeText(): void
    {
        $response = $this->action->saveRequest(
            $this->saveRequestFor([
                'request_status' => 'unknown',
                'prior_employers' => 'unknown',
                'filing_obligation' => 'required',
                'annual_claims' => 'present_unsupported',
            ]),
            new Response(),
            ['year' => '2026', 'employeeId' => (string) $this->employeeId],
        );

        self::assertSame(200, $response->getStatusCode());
        $saved = $this->body($response)['request'] ?? null;
        self::assertIsArray($saved);
        self::assertSame('required', $saved['filing_obligation'] ?? null);
        self::assertSame('present_unsupported', $saved['annual_claims'] ?? null);
    }

    /**
     * Regrese: jeden nedopsaný jiný pečující shodil uložení celého formuláře.
     * Nově se uloží úplné řádky a o vynechaných se řekne vedle výsledku.
     */
    public function testIncompleteCaregiverRowIsReportedInsteadOfLosingTheWholeForm(): void
    {
        $response = $this->action->saveRequest(
            $this->saveRequestFor([
                'request_status' => 'unknown',
                'prior_employers' => 'unknown',
                'filing_obligation' => 'unknown',
                'annual_claims' => 'unknown',
                'other_household_caregiver_status' => 'present',
                'other_household_caregivers' => [
                    [
                        'given_name' => 'Jana',
                        'family_name' => 'Syntetická',
                        'birth_date' => '1990-01-01',
                        'months_mask' => 'ANNNNNNNNNNN',
                    ],
                    [
                        'given_name' => 'Petr',
                        'family_name' => 'Syntetický',
                        'birth_date' => null,
                        'months_mask' => 'ANNNNNNNNNNN',
                    ],
                ],
            ]),
            new Response(),
            ['year' => '2026', 'employeeId' => (string) $this->employeeId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertIsArray($body['request'] ?? null);
        $warnings = $body['warnings'] ?? null;
        self::assertIsArray($warnings);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('datum narození', (string) $warnings[0]);
    }

    /** „Ano“ bez jediné osoby je rozdělaná práce, ne chyba k odmítnutí. */
    public function testCaregiverPresentWithoutAnyPersonStillSaves(): void
    {
        $response = $this->action->saveRequest(
            $this->saveRequestFor([
                'request_status' => 'unknown',
                'prior_employers' => 'unknown',
                'filing_obligation' => 'unknown',
                'annual_claims' => 'unknown',
                'other_household_caregiver_status' => 'present',
                'other_household_caregivers' => [],
            ]),
            new Response(),
            ['year' => '2026', 'employeeId' => (string) $this->employeeId],
        );

        self::assertSame(200, $response->getStatusCode());
        $warnings = $this->body($response)['warnings'] ?? null;
        self::assertIsArray($warnings);
        self::assertCount(1, $warnings);
    }

    /** @param array<string,mixed> $body */
    private function saveRequestFor(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'PUT',
                '/api/payroll/annual-settlement/2026/employees/x/request',
            )
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(
                1,
                'Syntetická mzdová role',
                'staff',
                true,
                ['payroll.documents' => AccessLevel::WRITE->value],
            ))
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function body(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
