<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAnnualSettlementAction;
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
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
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

    /** Q8-43: seznam nese začátek vedení mezd, podle něj stránka volí výchozí rok. */
    public function testListCarriesThePayrollStartPeriod(): void
    {
        if (!$this->db->hasTable('payroll_module_state')) {
            self::markTestSkipped('Chybí tabulka payroll_module_state.');
        }
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, 'setup', ?)
             ON DUPLICATE KEY UPDATE status = 'setup', start_period = VALUES(start_period)"
        )->execute([$this->supplierId, '2026-06-01']);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/annual-settlements/2025')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(
                1,
                'Syntetická mzdová role',
                'staff',
                true,
                ['payroll.documents' => AccessLevel::WRITE->value, 'payroll' => AccessLevel::WRITE->value],
            ));

        $response = $this->action->list($request, new Response(), ['year' => '2025']);

        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('2026-06', substr((string) ($body['payroll_start_period'] ?? ''), 0, 7));
    }

    /**
     * Odkaz z kontroly před během zúží seznam na osoby hlášení (`employees`).
     * Neplatný výčet je validační chyba, ne chyba databáze.
     */
    public function testListNarrowsToEmployeesFromLinkAndRejectsInvalidList(): void
    {
        $list = function (string $employees): Response {
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/payroll/annual-settlements/2025')
                ->withQueryParams(['employees' => $employees])
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute('auth.effective_role', new EffectiveRole(
                    1,
                    'Syntetická mzdová role',
                    'staff',
                    true,
                    ['payroll.documents' => AccessLevel::WRITE->value, 'payroll' => AccessLevel::WRITE->value],
                ));

            return $this->action->list($request, new Response(), ['year' => '2025']);
        };

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická jiná osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        self::assertSame(2, $this->body($list(''))['total']);

        $response = $list((string) $this->employeeId);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertSame([$this->employeeId], $body['employees']);
        self::assertSame([$this->employeeId], array_map('intval', array_column($body['items'], 'employee_id')));

        foreach (['abc', '1,,2', '0', '-1', implode(',', range(1, 1001))] as $invalid) {
            self::assertSame(422, $list($invalid)->getStatusCode(), $invalid);
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

    /**
     * Regrese: náhled osoby bez založené žádosti vracel `row_version` 1. Klient
     * ji poslal zpět a první uložení skončilo 409 „mezitím zanikla", takže
     * stav žádosti nešel u nikoho poprvé zapsat a JMHZ zůstalo zablokované.
     */
    public function testFirstSaveWithVersionFromPreviewSucceeds(): void
    {
        $previewRequest = $this->saveRequestFor([])
            ->withMethod('GET')
            ->withAttribute('auth.effective_role', new EffectiveRole(
                1,
                'Syntetická mzdová role',
                'staff',
                true,
                ['payroll.documents' => AccessLevel::WRITE->value, 'payroll' => AccessLevel::WRITE->value],
            ));
        $preview = $this->action->preview(
            $previewRequest,
            new Response(),
            ['year' => '2025', 'employeeId' => (string) $this->employeeId],
        );
        self::assertSame(200, $preview->getStatusCode());
        $loaded = $this->body($preview)['request'] ?? null;
        self::assertIsArray($loaded);
        self::assertSame('unknown', $loaded['request_status'] ?? null);

        $body = [
            'request_status' => 'not_requested',
            'prior_employers' => 'unknown',
            'filing_obligation' => 'unknown',
            'annual_claims' => 'unknown',
        ];
        // Klient posílá verzi stejně jako PayrollAnnualSettlement.vue: jen kladnou.
        if ((int) ($loaded['row_version'] ?? 0) > 0) {
            $body['row_version'] = $loaded['row_version'];
        }
        $response = $this->action->saveRequest(
            $this->saveRequestFor($body),
            new Response(),
            ['year' => '2025', 'employeeId' => (string) $this->employeeId],
        );

        self::assertSame(200, $response->getStatusCode());
        $saved = $this->body($response)['request'] ?? null;
        self::assertIsArray($saved);
        self::assertSame('not_requested', $saved['request_status'] ?? null);
    }

    /** @param array<string,mixed> $body */
    /** Hromadné nastavení: založí chybějící řádky, existující jen přepíše stav a zvýší verzi. */
    public function testBulkSetsRequestStatusCreatesAndUpdatesOnlyTheStatus(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Druhá syntetická osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $secondId = (int) $pdo->lastInsertId();

        $existing = $this->action->saveRequest(
            $this->saveRequestFor([
                'request_status' => 'unknown',
                'prior_employers' => 'none',
                'filing_obligation' => 'none',
                'annual_claims' => 'none',
                'note' => 'Zůstane beze změny.',
            ]),
            new Response(),
            ['year' => '2026', 'employeeId' => (string) $this->employeeId],
        );
        self::assertSame(200, $existing->getStatusCode());
        $before = $this->body($existing)['request'];

        $response = $this->action->saveRequestsBulk(
            $this->saveRequestFor([
                'employee_ids' => [$this->employeeId, $secondId, $secondId],
                'request_status' => 'not_requested',
            ]),
            new Response(),
            ['year' => '2026'],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertSame(1, $body['created'] ?? null);
        self::assertSame(1, $body['updated'] ?? null);

        $repository = new \MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository($this->db);
        $after = $repository->findRequest($this->supplierId, $this->employeeId, 2026);
        self::assertIsArray($after);
        self::assertSame('not_requested', $after['request_status']);
        self::assertSame($before['row_version'] + 1, $after['row_version']);
        self::assertSame('Zůstane beze změny.', $after['note']);
        self::assertSame('none', $after['prior_employers']);

        $created = $repository->findRequest($this->supplierId, $secondId, 2026);
        self::assertIsArray($created);
        self::assertSame('not_requested', $created['request_status']);
        self::assertSame('unknown', $created['prior_employers']);
        self::assertSame(1, $created['row_version']);
    }

    /** Cizí ID celou dávku odmítne a nic nezapíše. */
    public function testBulkRejectsForeignEmployeeIdWithoutWritingAnything(): void
    {
        $response = $this->action->saveRequestsBulk(
            $this->saveRequestFor([
                'employee_ids' => [$this->employeeId, 999999999],
                'request_status' => 'requested',
            ]),
            new Response(),
            ['year' => '2026'],
        );

        self::assertSame(422, $response->getStatusCode());
        $repository = new \MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository($this->db);
        self::assertNull($repository->findRequest($this->supplierId, $this->employeeId, 2026));
    }

    public function testBulkRejectsInvalidInput(): void
    {
        foreach ([
            ['employee_ids' => [], 'request_status' => 'requested'],
            ['employee_ids' => ['x'], 'request_status' => 'requested'],
            ['employee_ids' => [$this->employeeId], 'request_status' => 'withdrawn'],
        ] as $body) {
            $response = $this->action->saveRequestsBulk(
                $this->saveRequestFor($body),
                new Response(),
                ['year' => '2026'],
            );
            self::assertSame(422, $response->getStatusCode());
        }
    }

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
