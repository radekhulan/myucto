<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmployerSettingsAction;
use MyInvoice\Action\Payroll\PayrollOfficeRegistrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollEmployerSettingsApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmployerSettingsAction $action;
    private PayrollOfficeRegistrationAction $registrationAction;
    private PayrollEmployerSettingsRepository $repository;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollEmployerSettingsAction::class);
            $this->registrationAction = $container->get(PayrollOfficeRegistrationAction::class);
            $this->repository = $container->get(PayrollEmployerSettingsRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasTable('payroll_employer_settings')
            || !$this->db->hasTable('payroll_offices')) {
            $this->markTestSkipped('Migrace 1189 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1, accounting_mode = 'double_entry'
              WHERE id IN (?, ?)"
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $seeder->seedForSupplier($this->otherSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testDefaultsIncludeEmployeesPartnerStatutoryAndInstitutionAccounts(): void
    {
        $response = $this->action->get(
            $this->request('GET', $this->supplierId),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        $settings = $this->json($response)['settings'];
        self::assertSame(0, $settings['row_version']);
        self::assertSame([], $settings['offices']);
        self::assertSame([
            'employment_gross_debit' => '521',
            'employment_gross_credit' => '331',
            'partner_gross_debit' => '522',
            'partner_gross_credit' => '366',
            'statutory_gross_debit' => '523',
            'statutory_gross_credit' => '366',
            'employer_insurance_debit' => '524',
            'social_insurance_credit' => '336.100',
            'health_insurance_credit' => '336.200',
            'income_tax_credit' => '342.100',
            'withholding_tax_credit' => '342.200',
            'other_deductions_credit' => '379',
            'partner_settlement_credit' => '365',
            'risky_savings_debit' => '527',
            'risky_savings_credit' => '379',
            'employee_receivable_debit' => '335',
            'non_deductible_benefit_debit' => '528',
            'travel_expense_debit' => '512',
        ], $settings['accounts']);
    }

    public function testSettingsAreSavedAndTenantIsolated(): void
    {
        $first = $this->put($this->supplierId, $this->payload('VZOROV', 'Vzorová účtárna'));
        self::assertSame(200, $first->getStatusCode());
        $saved = $this->json($first)['settings'];
        self::assertSame(1, $saved['row_version']);
        self::assertSame('VZOROV', $saved['default_office_code']);
        self::assertSame('P12345678', $saved['employer_registration_number']);
        self::assertArrayNotHasKey('health_insurance_payer_number', $saved);
        self::assertCount(1, $saved['offices']);

        $updatedPayload = $this->payload('BRNO', 'Brněnská účtárna');
        $updatedPayload['row_version'] = 1;
        $updatedPayload['offices'][] = [
            'code' => 'VZOROV',
            'name' => 'Vzorová účtárna',
            'is_active' => false,
        ];
        $updated = $this->put($this->supplierId, $updatedPayload);
        self::assertSame(200, $updated->getStatusCode());
        $updatedSettings = $this->json($updated)['settings'];
        self::assertSame(2, $updatedSettings['row_version']);
        self::assertSame('BRNO', $updatedSettings['default_office_code']);
        self::assertFalse(array_column($updatedSettings['offices'], null, 'code')['VZOROV']['is_active']);

        $other = $this->action->get(
            $this->request('GET', $this->otherSupplierId),
            new Response(),
        );
        self::assertSame(200, $other->getStatusCode());
        $otherSettings = $this->json($other)['settings'];
        self::assertSame(0, $otherSettings['row_version']);
        self::assertSame([], $otherSettings['offices']);

        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_offices WHERE supplier_id = ?'
        );
        $count->execute([$this->otherSupplierId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testLegacyOfficeVariableSymbolCannotBeOverwrittenByBulkSettings(): void
    {
        $created = $this->put($this->supplierId, $this->payload('MAIN', 'Mzdová účtárna'));
        self::assertSame(200, $created->getStatusCode());
        $this->db->pdo()->prepare(
            'UPDATE payroll_offices SET social_security_variable_symbol = ? WHERE supplier_id = ? AND code = ?'
        )->execute(['0012345678', $this->supplierId, 'MAIN']);

        $withoutNewField = $this->payload('MAIN', 'Přejmenovaná účtárna');
        $withoutNewField['row_version'] = 1;
        $updated = $this->put($this->supplierId, $withoutNewField);
        self::assertSame(200, $updated->getStatusCode());
        $office = $this->json($updated)['settings']['offices'][0];
        self::assertSame('0012345678', $office['social_security_variable_symbol']);

        $overwrite = $this->payload('MAIN', 'Mzdová účtárna');
        $overwrite['row_version'] = 2;
        $overwrite['offices'][0]['social_security_variable_symbol'] = '0099999999';
        $rejected = $this->put($this->supplierId, $overwrite);
        self::assertSame(422, $rejected->getStatusCode());
        self::assertSame('validation_failed', $this->json($rejected)['error']['code']);
        $stored = $this->db->pdo()->prepare(
            'SELECT social_security_variable_symbol FROM payroll_offices WHERE supplier_id = ? AND code = ?'
        );
        $stored->execute([$this->supplierId, 'MAIN']);
        self::assertSame('0012345678', $stored->fetchColumn());
    }

    public function testEffectiveOfficeRegistrationAcceptsEvidencedPastAndIsSessionTenantScoped(): void
    {
        if (!$this->db->hasTable('payroll_office_registration_versions')) {
            self::markTestSkipped('Migrace 1595 neproběhla.');
        }
        $created = $this->put($this->supplierId, $this->payload('MAIN', 'Mzdová účtárna'));
        self::assertSame(200, $created->getStatusCode());
        $officeId = (int) $this->json($created)['settings']['offices'][0]['id'];
        $body = [
            'effective_from' => '2026-01-01',
            'social_security_variable_symbol' => '0012345678',
            'source_reference' => 'synthetic:cssz-confirmation',
        ];

        $saved = $this->registrationAction->create(
            $this->request('POST', $this->supplierId)->withParsedBody($body),
            new Response(),
            ['officeId' => (string) $officeId],
        );
        self::assertSame(201, $saved->getStatusCode());
        self::assertSame('2026-01-01', $this->json($saved)['registration']['effective_from']);

        $foreign = $this->registrationAction->list(
            $this->request('GET', $this->otherSupplierId),
            new Response(),
            ['officeId' => (string) $officeId],
        );
        self::assertSame([], $this->json($foreign)['registrations']);

        $bearer = $this->registrationAction->list(
            $this->request('GET', $this->supplierId, 'admin', 'bearer'),
            new Response(),
            ['officeId' => (string) $officeId],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
    }

    public function testCompositeForeignKeyRejectsOfficeFromAnotherTenant(): void
    {
        self::assertSame(
            200,
            $this->put($this->supplierId, $this->payload('MAIN', 'První účtárna'))->getStatusCode(),
        );
        self::assertSame(
            200,
            $this->put($this->otherSupplierId, $this->payload('OTHER', 'Druhá účtárna'))->getStatusCode(),
        );
        $foreignOffice = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_offices WHERE supplier_id = ? AND code = ?'
        );
        $foreignOffice->execute([$this->otherSupplierId, 'OTHER']);
        $foreignOfficeId = (int) $foreignOffice->fetchColumn();

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings
                SET default_office_id = ?
              WHERE supplier_id = ?'
        )->execute([$foreignOfficeId, $this->supplierId]);
    }

    public function testStaleVersionReturns409AndPreservesFirstWrite(): void
    {
        self::assertSame(
            200,
            $this->put($this->supplierId, $this->payload('MAIN', 'Původní účtárna'))->getStatusCode(),
        );

        $stale = $this->put(
            $this->supplierId,
            $this->payload('NEW', 'Přepsaná účtárna'),
        );
        self::assertSame(409, $stale->getStatusCode());
        $error = $this->json($stale)['error'];
        self::assertSame('row_version_conflict', $error['code']);
        self::assertSame(1, $error['current_row_version']);

        $current = $this->action->get(
            $this->request('GET', $this->supplierId),
            new Response(),
        );
        self::assertSame('MAIN', $this->json($current)['settings']['default_office_code']);
    }

    public function testMissingTenantIsLockedAndRejectedBeforeAggregateWrite(): void
    {
        $missingSupplierId = (int) $this->db->pdo()
            ->query('SELECT COALESCE(MAX(id), 0) + 1 FROM supplier')
            ->fetchColumn();
        $normalized = $this->payload('MAIN', 'Mzdová účtárna');
        unset($normalized['row_version']);

        try {
            $this->repository->save($missingSupplierId, $normalized, 0);
            self::fail('Neexistující tenant nesmí založit mzdové nastavení.');
        } catch (\RuntimeException $e) {
            self::assertSame('Firma pro nastavení mezd neexistuje.', $e->getMessage());
        }

        $officeCount = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_offices WHERE supplier_id = ?'
        );
        $officeCount->execute([$missingSupplierId]);
        self::assertSame(0, (int) $officeCount->fetchColumn());

        $settingsCount = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_employer_settings WHERE supplier_id = ?'
        );
        $settingsCount->execute([$missingSupplierId]);
        self::assertSame(0, (int) $settingsCount->fetchColumn());
    }

    public function testBearerAndDisabledPayrollAreRejected(): void
    {
        $bearer = $this->action->get(
            $this->request('GET', $this->supplierId, 'admin', 'bearer'),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);

        $accountant = $this->action->put(
            $this->request('PUT', $this->supplierId, 'accountant')
                ->withParsedBody($this->payload('MAIN', 'Mzdová účtárna')),
            new Response(),
        );
        self::assertSame(403, $accountant->getStatusCode());
        self::assertSame('forbidden', $this->json($accountant)['error']['code']);

        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);
        $disabled = $this->action->get(
            $this->request('GET', $this->supplierId),
            new Response(),
        );
        self::assertSame(403, $disabled->getStatusCode());
        self::assertSame('payroll_disabled', $this->json($disabled)['error']['code']);
    }

    public function testInactiveOrWrongTypeAccountIsRejected(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE chart_of_accounts
                SET is_active = 0
              WHERE supplier_id = ? AND account_code = ?'
        )->execute([$this->supplierId, '523']);
        $inactive = $this->put(
            $this->supplierId,
            $this->payload('MAIN', 'Mzdová účtárna'),
        );
        self::assertSame(422, $inactive->getStatusCode());
        self::assertSame('validation_failed', $this->json($inactive)['error']['code']);

        $this->db->pdo()->prepare(
            'UPDATE chart_of_accounts
                SET is_active = 1
              WHERE supplier_id = ? AND account_code = ?'
        )->execute([$this->supplierId, '523']);
        $payload = $this->payload('MAIN', 'Mzdová účtárna');
        $payload['accounts']['statutory_gross_debit'] = '331';
        $wrongType = $this->put($this->supplierId, $payload);
        self::assertSame(422, $wrongType->getStatusCode());
        self::assertSame('validation_failed', $this->json($wrongType)['error']['code']);
    }

    /** @return array<string,mixed> */
    private function payload(string $officeCode, string $officeName): array
    {
        return [
            'row_version' => 0,
            'default_office_code' => $officeCode,
            'employer_registration_number' => 'P12345678',
            'social_security_office_code' => '110',
            'default_health_insurer_code' => '111',
            'payroll_contact_name' => 'Testovací účetní',
            'payroll_contact_email' => 'mzdy@example.invalid',
            'payroll_contact_phone' => '+420 777 000 000',
            'accounts' => PayrollAccountingDefaults::codes(),
            'offices' => [[
                'code' => $officeCode,
                'name' => $officeName,
                'is_active' => true,
            ]],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function put(int $supplierId, array $payload): Response
    {
        return $this->action->put(
            $this->request('PUT', $supplierId)->withParsedBody($payload),
            new Response(),
        );
    }

    private function request(
        string $method,
        int $supplierId,
        string $role = 'admin',
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/settings/employer')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
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
