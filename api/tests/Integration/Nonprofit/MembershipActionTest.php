<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Action\Nonprofit\MembershipAction;
use MyInvoice\Action\Nonprofit\Catalog\MembershipResourceCatalogAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Membership\MembershipPublicCatalog;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class MembershipActionTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private MembershipAction $action;
    private MembershipResourceCatalogAction $publicAction;
    private int $supplier;
    private int $period;
    private int $actor;
    private int $client;
    private array $classification;
    private \Psr\Container\ContainerInterface $container;

    protected function setUp(): void
    {
        $container = $this->container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic action association','Synthetic street','Synthetic city','10000','membership-action@example.test',203,1,?,'double_entry')")->execute([$vat]);
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")->execute([$this->supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $this->supplier]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES('membership-action-actor@example.test',?,'Synthetic membership action actor','admin','cs',1)")
            ->execute([password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->actor = (int) $this->pdo->lastInsertId();
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $axes = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01', $this->actor);
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'MEMBERSHIP','Synthetic membership activity')")
            ->execute([$axes['activity_kind']['id'], $this->supplier]);
        $this->classification = ['activity_value_id' => $axes['activity']['values']['main'],
            'activity_kind_value_id' => (int) $this->pdo->lastInsertId(),
            'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        foreach (['315.100' => 'asset', '684.100' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
        }
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $this->period, ['expected_revision' => 0, 'idempotency_key' => 'membership-action-legal',
            'reason' => 'Synthetic reviewed association statutes', 'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association',
                'main_activity_nonbusiness' => true, 'legal_basis' => 'zdp_17a', 'evidence' => 'Synthetic association statutes']], $this->actor);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic action member','Synthetic street','Synthetic city','10000',203,'membership-action-client@example.test','cs',?,1,0)")
            ->execute([$this->supplier, $currency]);
        $this->client = (int) $this->pdo->lastInsertId();
        $this->action = new MembershipAction($this->db, $container->get(PostingService::class));
        $this->publicAction = new MembershipResourceCatalogAction(new MembershipPublicCatalog($this->db,
            new MembershipRepository($this->db), new MembershipChargeRepository($this->db), new MembershipBalanceRepository($this->db)));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testInternalReadAndSessionWriteContractsUseAccountingPermissions(): void
    {
        [$status, $deniedRead] = $this->call('GET', 'members', null, [], ['accounting' => 1], 'bearer');
        self::assertSame(403, $status);
        self::assertSame('token_endpoint_forbidden', $deniedRead['error']['code']);
        [$status, $empty] = $this->call('GET', 'members', null, [], ['accounting' => 1], 'session');
        self::assertSame(200, $status);
        self::assertSame([], $empty['items']);
        [$status] = $this->call('POST', 'createMember', ['client_id' => $this->client, 'member_number' => 'M-ACTION-001'], [], ['accounting' => 1], 'session');
        self::assertSame(403, $status);
        [$status, $denied] = $this->call('POST', 'createMember', ['client_id' => $this->client, 'member_number' => 'M-ACTION-001'], [], ['accounting.journal.post' => 2], 'bearer');
        self::assertSame(403, $status);
        self::assertSame('session_required', $denied['error']['code']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_members WHERE supplier_id=' . $this->supplier)->fetchColumn());

        [$status, $member] = $this->call('POST', 'createMember', ['client_id' => $this->client, 'member_number' => 'M-ACTION-001']);
        self::assertSame(201, $status);
        [$status, $revision] = $this->call('POST', 'appendRevision', ['status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0,
            'idempotency_key' => 'membership-action-active', 'legal_basis' => ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
                'reference' => 'Synthetic statutes article 5', 'evidence' => 'Synthetic signed membership application']], ['id' => (string) $member['id']]);
        self::assertSame(201, $status);
        self::assertSame(1, $revision['revision_no']);

        $charge = ['member_id' => $member['id'], 'title_key' => 'annual-2026', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'amount' => '18000.00', 'receivable_account' => '315.100',
            'revenue_account' => '684.100', 'title_reference' => 'Synthetic statutes article 5', 'evidence' => 'Synthetic annual resolution',
            'nonreciprocal' => true, 'reason' => 'Synthetic annual membership charge', 'classification' => $this->classification];
        [$status, $preview] = $this->call('POST', 'preview', ['operation' => 'issue', 'request' => $charge]);
        self::assertSame(200, $status);
        [$status, $confirmed] = $this->call('POST', 'confirm', ['operation' => 'issue', 'request' => $charge,
            'expected_case_version' => $preview['expected_case_version'], 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'membership-action-issue']);
        self::assertSame(200, $status);
        self::assertFalse($confirmed['idempotent_replay']);

        [$status, $publicRole] = $this->publicCall('roles', ['as_of' => '2026-06-30'], ['member_id' => (string) $member['id']]);
        self::assertSame(200, $status);
        self::assertSame($member['id'], $publicRole['items'][0]['member_id']);
        self::assertSame('proven_utc', $publicRole['items'][0]['recorded_time_status']);
        self::assertStringEndsWith('Z', $publicRole['items'][0]['recorded_at']);
        self::assertArrayNotHasKey('member_number', $publicRole['items'][0]);
        $claimId = (int) $this->pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $confirmed['operation']['id'])->fetchColumn();
        [$status, $publicClaim] = $this->publicCall('receivables', [], ['claim_id' => (string) $claimId]);
        self::assertSame(200, $status);
        self::assertNull($publicClaim['items'][0]['settlement']['open_cents']);
        self::assertSame('proven_utc', $publicClaim['items'][0]['recorded_time_status']);
        self::assertStringEndsWith('Z', $publicClaim['items'][0]['recorded_at']);
        [$status] = $this->publicCall('roles', ['as_of' => '2026-06-30'], ['member_id' => (string) $member['id']], []);
        self::assertSame(403, $status);

        [$status, $detail] = $this->call('GET', 'member', null, ['id' => (string) $member['id']], ['accounting' => 1], 'session');
        self::assertSame(200, $status);
        self::assertSame(1800000, $detail['balance']['page_active_claim_cents']);
        [$status, $list] = $this->call('GET', 'members', null, [], ['accounting' => 1], 'session');
        self::assertSame(200, $status);
        self::assertSame('active', $list['items'][0]['status']);
        [$status, $claim] = $this->call('GET', 'claim', null, ['id' => (string) $detail['balance']['items'][0]['claim_id']], ['accounting' => 1], 'session');
        self::assertSame(200, $status);
        self::assertSame('unreconciled', $claim['settlement']['status']);
        [$status, $reconciliation] = $this->call('GET', 'reconciliation', null, ['id' => (string) $this->period], ['accounting' => 1], 'session');
        self::assertSame(200, $status);
        self::assertSame('balanced', $reconciliation['status']);
        [$status, $error] = $this->call('POST', 'preview', ['operation' => 'issue', 'request' => $charge, 'unexpected' => true]);
        self::assertSame(422, $status);
        self::assertSame('nonprofit_membership_invalid', $error['error']['code']);
    }

    public function testInvalidIssueAmountsReturnValidationErrorsWithoutWriting(): void
    {
        [$status, $member] = $this->call('POST', 'createMember', ['client_id' => $this->client, 'member_number' => 'M-ACTION-AMOUNT']);
        self::assertSame(201, $status);
        [$status] = $this->call('POST', 'appendRevision', ['status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0,
            'idempotency_key' => 'membership-action-amount-active', 'legal_basis' => ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
                'reference' => 'Synthetic statutes article 5', 'evidence' => 'Synthetic signed membership application']], ['id' => (string) $member['id']]);
        self::assertSame(201, $status);

        $charge = ['member_id' => $member['id'], 'title_key' => 'annual-2026', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'amount' => '18000.00', 'receivable_account' => '315.100',
            'revenue_account' => '684.100', 'title_reference' => 'Synthetic statutes article 5', 'evidence' => 'Synthetic annual resolution',
            'nonreciprocal' => true, 'reason' => 'Synthetic annual membership charge', 'classification' => $this->classification];
        foreach ([null, ['18000.00'], 'not-a-number'] as $amount) {
            $invalid = $charge;
            if ($amount === null) unset($invalid['amount']);
            else $invalid['amount'] = $amount;
            [$status, $error] = $this->call('POST', 'preview', ['operation' => 'issue', 'request' => $invalid]);
            self::assertSame(422, $status);
            self::assertSame('nonprofit_membership_invalid', $error['error']['code']);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_membership_charge_cases WHERE supplier_id=' . $this->supplier)->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_membership_charge_operations WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testRevenueAccountMustBeMembershipRevenueRole(): void
    {
        $this->pdo->prepare("INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,'649.100','Synthetic other revenue','revenue')")->execute([$this->supplier]);
        $member = $this->activeMember('M-ACTION-ROLE');
        $charge = $this->charge($member, '2026-01-01', '2026-12-31', '2026-01-01', '1200.00');
        $charge['revenue_account'] = '649.100';
        [$status, $error] = $this->call('POST', 'preview', ['operation' => 'issue', 'request' => $charge]);
        self::assertSame(409, $status);
        self::assertSame('nonprofit_membership_revenue_account_invalid', $error['error']['code']);
        self::assertSame('684', $error['error']['required_account'] ?? null);

        $charge['revenue_account'] = '684.100';
        [$status, $preview] = $this->call('POST', 'preview', ['operation' => 'issue', 'request' => $charge]);
        self::assertSame(200, $status);
        self::assertNull($preview['deferral']);
    }

    public function testChargeCrossingBalanceSheetDateIsDeferredByClosingStep(): void
    {
        $member = $this->activeMember('M-ACTION-DEFER');
        $charge = $this->charge($member, '2026-07-01', '2027-06-30', '2026-07-01', '12000.00');
        [$status, $preview] = $this->call('POST', 'preview', ['operation' => 'issue', 'request' => $charge]);
        self::assertSame(200, $status);
        // 365 dní období, 184 z nich do 31. 12. 2026: odloží se 12 000 × 181 / 365.
        self::assertSame(['mechanism' => 'closing.deferred_revenue_accrual', 'balance_sheet_date' => '2026-12-31', 'deferred_amount' => '5950.68'], $preview['deferral']);

        [$status, $confirmed] = $this->call('POST', 'confirm', ['operation' => 'issue', 'request' => $charge,
            'expected_case_version' => $preview['expected_case_version'], 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'membership-action-defer']);
        self::assertSame(200, $status);

        $closing = $this->container->get(ClosingService::class);
        $closingPreview = $closing->deferredRevenueAccrualPreview($this->supplier, $this->period);
        $items = array_values(array_filter($closingPreview['items'], fn (array $item): bool => $item['source'] === 'nonprofit_membership'));
        self::assertCount(1, $items);
        self::assertSame((int) $confirmed['case']['id'], $items[0]['membership_case_id']);
        self::assertNull($items[0]['invoice_id']);
        self::assertSame('684.100', $items[0]['debit_account']);
        self::assertSame(5950.68, $items[0]['deferred_amount']);
        self::assertSame([['fiscal_year' => 2027, 'amount' => 5950.68]], $items[0]['release_schedule']);
        self::assertSame(5950.68, $closingPreview['by_account']['684.100']);

        [$status, $reversePreview] = $this->call('POST', 'preview', ['operation' => 'reverse',
            'request' => ['case_id' => (int) $confirmed['case']['id'], 'accounting_date' => '2026-08-01', 'reason' => 'Synthetic reversal']]);
        self::assertSame(200, $status);
        [$status] = $this->call('POST', 'confirm', ['operation' => 'reverse',
            'request' => ['case_id' => (int) $confirmed['case']['id'], 'accounting_date' => '2026-08-01', 'reason' => 'Synthetic reversal'],
            'expected_case_version' => $reversePreview['expected_case_version'], 'preview_hash' => $reversePreview['preview_hash'], 'idempotency_key' => 'membership-action-defer-reverse']);
        self::assertSame(200, $status);
        $afterReverse = $closing->deferredRevenueAccrualPreview($this->supplier, $this->period);
        self::assertSame([], array_values(array_filter($afterReverse['items'], fn (array $item): bool => $item['source'] === 'nonprofit_membership')));
    }

    private function activeMember(string $number): int
    {
        [$status, $member] = $this->call('POST', 'createMember', ['client_id' => $this->client, 'member_number' => $number]);
        self::assertSame(201, $status);
        [$status] = $this->call('POST', 'appendRevision', ['status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0,
            'idempotency_key' => 'active-' . $number, 'legal_basis' => ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
                'reference' => 'Synthetic statutes article 5', 'evidence' => 'Synthetic signed membership application']], ['id' => (string) $member['id']]);
        self::assertSame(201, $status);
        return (int) $member['id'];
    }

    private function charge(int $member, string $from, string $to, string $accountingDate, string $amount): array
    {
        return ['member_id' => $member, 'title_key' => 'dues-' . $from, 'period_from' => $from, 'period_to' => $to,
            'accounting_date' => $accountingDate, 'due_date' => $accountingDate, 'amount' => $amount, 'receivable_account' => '315.100',
            'revenue_account' => '684.100', 'title_reference' => 'Synthetic statutes article 5', 'evidence' => 'Synthetic annual resolution',
            'nonreciprocal' => true, 'reason' => 'Synthetic membership charge', 'classification' => $this->classification];
    }

    public function testForeignTenantClientIsRejectedAsInvalidReference(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id) VALUES('Synthetic foreign association','Synthetic street','Synthetic city','10000','membership-foreign@example.test',203,1,1)");
        $foreignSupplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic foreign member','Synthetic street','Synthetic city','10000',203,'membership-foreign-client@example.test','cs',1,1,0)")
            ->execute([$foreignSupplier]);
        $foreignClient = (int) $this->pdo->lastInsertId();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        [$status, $error] = $this->call('POST', 'createMember', ['client_id' => $foreignClient, 'member_number' => 'M-ACTION-FOREIGN']);
        self::assertSame(400, $status);
        self::assertSame('invalid_reference', $error['error']['code']);
        self::assertStringNotContainsString('Synthetic foreign member', json_encode($error, JSON_THROW_ON_ERROR));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_members WHERE supplier_id IN (' . $this->supplier . ',' . $foreignSupplier . ')')->fetchColumn());
    }

    private function call(string $httpMethod, string $method, ?array $body, array $args = [], ?array $permissions = null, string $auth = 'session'): array
    {
        $permissions ??= ['accounting' => 1, 'accounting.journal.post' => 2];
        $request = (new ServerRequestFactory())->createServerRequest($httpMethod, '/api/accounting/nonprofit-membership')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $auth)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(501, 'Synthetic membership role', 'staff', true, $permissions));
        if ($body !== null) $request = $request->withParsedBody($body);
        $response = $this->action->{$method}($request, new Response(), $args);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    private function publicCall(string $method, array $query, array $args, array $permissions = ['accounting' => 1]): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/accounting/catalog/nonprofit-membership/' . $method)
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(502, 'Synthetic public catalog role', 'staff', true, $permissions));
        $response = $this->publicAction->{$method}($request, new Response(), $args);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }
}
