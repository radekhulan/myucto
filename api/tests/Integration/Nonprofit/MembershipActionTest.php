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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->action = new MembershipAction($this->db, $container->get(PostingService::class), $container->get(\MyInvoice\Service\ActivityLogger::class), $container->get(\MyInvoice\Service\IpMatcher::class));
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
        self::assertSame('1800000', $publicClaim['items'][0]['settlement']['open_cents']);
        self::assertSame('proven_utc', $publicClaim['items'][0]['recorded_time_status']);
        self::assertStringEndsWith('Z', $publicClaim['items'][0]['recorded_at']);
        [$status] = $this->publicCall('roles', ['as_of' => '2026-06-30'], ['member_id' => (string) $member['id']], []);
        self::assertSame(403, $status);

        [$status, $detail] = $this->call('GET', 'member', null, ['id' => (string) $member['id']], ['accounting' => 1], 'session');
        self::assertSame(200, $status);
        self::assertSame(1800000, $detail['balance']['page_active_claim_cents']);
        // Samostatná stránka detailu zobrazí jméno kontaktu bez seznamu.
        self::assertSame('Synthetic action member', $detail['member']['company_name']);
        self::assertArrayHasKey('first_name', $detail['member']);
        self::assertArrayHasKey('last_name', $detail['member']);
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

    public function testRateCatalogUsesSessionAndExistingAccountingPermissions(): void
    {
        [$status] = $this->call('GET', 'rates', null, [], ['accounting' => 1], 'bearer');
        self::assertSame(403, $status);
        [$status, $empty] = $this->call('GET', 'rates', null, [], ['accounting' => 1]);
        self::assertSame(200, $status);
        self::assertSame([], $empty['items']);
        $input = ['name' => 'Synthetic standard', 'amount' => '500.00', 'receivable_account' => '315.100', 'revenue_account' => '684.100',
            'title_key' => 'synthetic-standard', 'period_months' => 12, 'title_reference' => 'Synthetic resolution', 'evidence' => 'Synthetic evidence',
            'classification' => $this->classification, 'is_active' => true, 'row_version' => 0];
        [$status] = $this->call('POST', 'createRate', $input, [], ['accounting' => 1]);
        self::assertSame(403, $status);
        [$status] = $this->call('POST', 'createRate', $input, [], ['accounting.journal.post' => 2], 'bearer');
        self::assertSame(403, $status);
        [$status, $rate] = $this->call('POST', 'createRate', $input);
        self::assertSame(201, $status);
        [$status, $detail] = $this->call('GET', 'rate', null, ['id' => (string) $rate['id']]);
        self::assertSame(200, $status);
        self::assertSame($rate['id'], $detail['id']);
        [$status] = $this->call('GET', 'rate', null, ['id' => (string) $rate['id']], ['accounting' => 1], 'bearer');
        self::assertSame(403, $status);
        $input['row_version'] = 1; $input['is_active'] = false;
        [$status] = $this->call('PUT', 'updateRate', $input, ['id' => (string) $rate['id']]);
        self::assertSame(200, $status);
        [$status, $page] = $this->call('GET', 'rates', null);
        self::assertSame(200, $status);
        self::assertSame([], $page['items']);
        $input['row_version'] = 2; $input['amount'] = '-1';
        [$status] = $this->call('PUT', 'updateRate', $input, ['id' => (string) $rate['id']]);
        self::assertSame(422, $status);
    }

    #[DataProvider('rateOverflowPaths')]
    public function testOverflowingRateAmountIsValidationErrorWithoutChangingData(bool $update): void
    {
        $input = ['name' => 'Synthetic standard', 'amount' => '500.00', 'receivable_account' => '315.100', 'revenue_account' => '684.100',
            'title_key' => 'synthetic-standard', 'period_months' => 12, 'title_reference' => 'Synthetic resolution', 'evidence' => 'Synthetic evidence',
            'classification' => $this->classification, 'is_active' => true, 'row_version' => 0];
        $args = [];
        if ($update) {
            [$status, $rate] = $this->call('POST', 'createRate', $input);
            self::assertSame(201, $status);
            $args = ['id' => (string) $rate['id']];
            $input['row_version'] = 1;
        }
        $q = $this->pdo->prepare('SELECT * FROM nonprofit_membership_rates WHERE supplier_id=? ORDER BY id');
        $q->execute([$this->supplier]);
        $before = $q->fetchAll(PDO::FETCH_ASSOC);
        $input['amount'] = '100000000000000000000';
        [$status, $result] = $this->call($update ? 'PUT' : 'POST', $update ? 'updateRate' : 'createRate', $input, $args);
        self::assertSame(422, $status);
        self::assertSame('nonprofit_membership_rate_invalid', $result['error']['code']);
        $q->execute([$this->supplier]);
        self::assertSame($before, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function rateOverflowPaths(): iterable
    {
        yield 'založení' => [false];
        yield 'úprava' => [true];
    }

    public function testBatchRoutesEnforceSessionWritePermissionAndApprovalToken(): void
    {
        [$status, $empty] = $this->call('GET', 'batches', null, [], ['accounting' => 1]);
        self::assertSame(200, $status); self::assertSame([], $empty['items']);
        [$status] = $this->call('GET', 'batches', null, [], ['accounting' => 1], 'bearer');
        self::assertSame(403, $status);
        $member = $this->activeMember('SYN-BATCH-ACTION');
        [$status, $rate] = $this->call('POST', 'createRate', ['name' => 'Synthetic batch rate', 'amount' => '500.00',
            'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_key' => 'synthetic-batch-action', 'period_months' => 12,
            'title_reference' => 'Synthetic resolution', 'evidence' => 'Synthetic evidence', 'classification' => $this->classification, 'is_active' => true, 'row_version' => 0]);
        self::assertSame(201, $status);
        $template = $this->charge($member, '2026-01-01', '2026-12-31', '2026-01-01', '500.00');
        unset($template['member_id']); $template['rate_id'] = $rate['id'];
        $body = ['template' => $template, 'selection_mode' => 'selected', 'member_ids' => [$member], 'idempotency_key' => 'synthetic-action-batch'];
        [$status] = $this->call('POST', 'createBatch', $body, [], ['accounting' => 1]);
        self::assertSame(403, $status);
        [$status] = $this->call('POST', 'createBatch', $body, [], ['accounting.journal.post' => 2], 'bearer');
        self::assertSame(403, $status);
        [$status, $created] = $this->call('POST', 'createBatch', $body);
        self::assertSame(201, $status);
        $args = ['id' => (string) $created['batch']['id']];
        [$status] = $this->call('POST', 'confirmBatch', ['approval_token' => str_repeat('a', 64)], $args);
        self::assertSame(409, $status);
        [$status] = $this->call('POST', 'prepareBatch', [], $args, ['accounting' => 1]);
        self::assertSame(403, $status);
        [$status, $preview] = $this->call('POST', 'prepareBatch', [], $args);
        self::assertSame(200, $status);
        self::assertSame('ready', $preview['batch']['phase']);
        [$readStatus,$readDetail]=$this->call('GET','batch',null,$args,['accounting'=>1]);
        self::assertSame(200,$readStatus);
        self::assertSame('selected',$readDetail['batch']['selection_mode']);
        self::assertSame($this->supplier,$readDetail['batch']['supplier_id']);
        foreach(['approval_token','request_hash','idempotency_key','batch_uid'] as $field) self::assertArrayNotHasKey($field,$readDetail['batch']);
        [$status] = $this->call('POST', 'confirmBatch', ['approval_token' => $preview['batch']['approval_token']], $args, ['accounting' => 1]);
        self::assertSame(403, $status);
        [$status, $done] = $this->call('POST', 'confirmBatch', ['approval_token' => $preview['batch']['approval_token']], $args);
        self::assertSame(200, $status); self::assertSame('finished', $done['batch']['phase']);
        [$status] = $this->call('GET', 'batch', null, ['id' => (string) ($created['batch']['id'] + 100000)]);
        self::assertSame(404, $status);
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
        $own = $this->activeMember('M-ACTION-OWN');
        [$status, $error] = $this->call('PUT', 'updateMember', ['client_id' => $foreignClient, 'member_number' => 'M-ACTION-OWN',
            'expected_client_id' => $this->client, 'expected_member_number' => 'M-ACTION-OWN'], ['id' => (string) $own]);
        self::assertSame(400, $status);
        self::assertSame('invalid_reference', $error['error']['code']);
        self::assertSame($this->client, (int) $this->pdo->query('SELECT client_id FROM nonprofit_members WHERE id=' . $own)->fetchColumn());
    }

    public function testRateManagementUsesTemplatePermissionInsteadOfPostingPermission(): void
    {
        [$status]=$this->call('POST','createRate',[],[],['accounting.journal.post'=>2]);
        self::assertSame(403,$status);
        [$status]=$this->call('POST','createRate',[],[],['accounting.templates'=>2]);
        self::assertSame(422,$status,'Oprávněný uživatel musí projít až k validaci vstupu.');
        [$status]=$this->call('POST','createRate',[],[],['accounting.templates'=>2],'bearer');
        self::assertSame(403,$status);
    }

    public function testSelectionRefreshRequiresReadSessionButNotJournalWrite(): void
    {
        $body = ['member_ids' => [], 'filters' => ['year' => 2026]];
        [$status, $result] = $this->call('POST', 'selectionCheck', $body, [], ['accounting' => 1]);
        self::assertSame(200, $status);
        self::assertSame([], $result['member_ids']);
        [$status] = $this->call('POST', 'selectionCheck', $body, [], ['accounting' => 1], 'bearer');
        self::assertSame(403, $status);
        [$status] = $this->call('POST', 'selectionCheck', $body, [], []);
        self::assertSame(403, $status);
        [$status] = $this->call('GET', 'titles', null, [], ['accounting' => 1]);
        self::assertSame(200, $status);
        [$status, $denied] = $this->call('GET', 'titles', null, [], ['accounting' => 1], 'bearer');
        self::assertSame(403, $status);
        self::assertSame('token_endpoint_forbidden', $denied['error']['code']);
        // Token s plným zápisem (system_write) se chová jako relace i u nových čtecích akcí.
        [$status] = $this->call('GET', 'titles', null, [], ['accounting' => 1], 'bearer', ['system_write' => true]);
        self::assertSame(200, $status);
        [$status] = $this->call('POST', 'selectionCheck', $body, [], ['accounting' => 1], 'bearer', ['system_write' => true]);
        self::assertSame(200, $status);
    }

    public function testContainerWiresSealedAuditLoggerIntoCorrections(): void
    {
        $action = \MyInvoice\Bootstrap::buildContainer()->get(MembershipAction::class);
        $corrections = (new \ReflectionProperty($action, 'corrections'))->getValue($action);
        $logger = (new \ReflectionProperty($corrections, 'activity'))->getValue($corrections);
        // Bez řetězu hashů by audit oprav nebyl zapečetěný (volitelný parametr autowire nevyplní).
        self::assertNotNull((new \ReflectionProperty($logger, 'hashChain'))->getValue($logger));
    }

    public function testCorrectionRoutesRequireSessionAndJournalWriteAndReportAvailability(): void
    {
        $id = $this->activeMember('M-ACTION-FIX');
        $args = ['id' => (string) $id];
        // Role jen ke čtení dostupnost oprav nedostane (ani se nepočítá); s právem zápisu ano.
        [$status, $readOnly] = $this->call('GET', 'member', null, $args, ['accounting' => 1]);
        self::assertSame(200, $status);
        self::assertArrayNotHasKey('corrections', $readOnly);
        self::assertArrayHasKey('balance', $readOnly);
        [$status, $detail] = $this->call('GET', 'member', null, $args);
        self::assertSame(200, $status);
        self::assertTrue($detail['corrections']['can_edit_identity']);
        self::assertSame('nonprofit_membership_member_has_revisions', $detail['corrections']['delete_block']);
        $revision = $detail['corrections']['voidable_revision_id'];
        self::assertSame($detail['history'][0]['id'], $revision);

        $identity = ['client_id' => $this->client, 'member_number' => 'M-ACTION-FIX-2', 'expected_client_id' => $this->client, 'expected_member_number' => 'M-ACTION-FIX'];
        $void = ['expected_revision' => 1, 'reason' => 'Synthetic entry mistake', 'idempotency_key' => 'action-void'];
        foreach ([['PUT', 'updateMember', $identity, $args], ['DELETE', 'deleteMember', null, $args], ['POST', 'voidRevision', $void, $args + ['revisionId' => (string) $revision]]] as [$http, $method, $body, $routeArgs]) {
            [$status] = $this->call($http, $method, $body, $routeArgs, ['accounting' => 1]);
            self::assertSame(403, $status, $method . ' bez práva zápisu');
            [$status, $denied] = $this->call($http, $method, $body, $routeArgs, ['accounting.journal.post' => 2], 'bearer');
            self::assertSame(403, $status, $method . ' přes API token');
            self::assertSame('session_required', $denied['error']['code']);
        }
        // Token s plným zápisem (system_write) jedná jako relace: projde bránou a narazí až na doménovou blokaci.
        $expected = ['expected_client_id' => (string) $this->client, 'expected_member_number' => 'M-ACTION-FIX'];
        [$status, $viaToken] = $this->call('DELETE', 'deleteMember', null, $args, null, 'bearer', ['system_write' => true], $expected);
        self::assertSame(409, $status);
        self::assertSame('nonprofit_membership_member_has_revisions', $viaToken['error']['code']);
        [$status, $blocked] = $this->call('DELETE', 'deleteMember', null, $args, query: $expected);
        self::assertSame(409, $status);
        self::assertSame('nonprofit_membership_member_has_revisions', $blocked['error']['code']);
        // Smazání potvrzuje kontakt a číslo: bez nich 422, se zastaralými 409 dřív než doménová blokace.
        foreach ([null, ['expected_client_id' => (string) $this->client], ['expected_client_id' => '0', 'expected_member_number' => 'M-ACTION-FIX'],
            $expected + ['force' => '1'], ['expected_member_number' => ' '] + $expected, ['expected_member_number' => str_repeat('A', 81)] + $expected,
            ['expected_client_id' => 'abc'] + $expected, ['expected_client_id' => ['1']] + $expected,
            ['expected_client_id' => '9999999999999999999'] + $expected] as $query) {
            [$status, $missing] = $this->call('DELETE', 'deleteMember', null, $args, query: $query);
            self::assertSame([422, 'nonprofit_membership_invalid'], [$status, $missing['error']['code']], json_encode($query));
        }
        [$status, $stale] = $this->call('DELETE', 'deleteMember', null, $args, query: ['expected_member_number' => 'M-ACTION-OLD'] + $expected);
        self::assertSame([409, 'nonprofit_membership_member_changed'], [$status, $stale['error']['code']]);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor)
            SELECT supplier_id,'Synthetic other contact',street,city,zip,country_id,'other-contact@example.test',language,currency_default_id,1,0 FROM clients WHERE supplier_id=? AND id=?")
            ->execute([$this->supplier, $this->client]);
        [$status, $staleContact] = $this->call('DELETE', 'deleteMember', null, $args, query: ['expected_client_id' => (string) $this->pdo->lastInsertId()] + $expected);
        self::assertSame([409, 'nonprofit_membership_member_changed'], [$status, $staleContact['error']['code']], 'souběžná změna kontaktu');
        [$status, $invalid] = $this->call('PUT', 'updateMember', ['client_id' => $this->client], $args);
        self::assertSame(422, $status);
        self::assertSame('nonprofit_membership_invalid', $invalid['error']['code']);

        [$status, $updated] = $this->call('PUT', 'updateMember', $identity, $args);
        self::assertSame(200, $status);
        self::assertSame('M-ACTION-FIX-2', $updated['member_number']);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) SELECT supplier_id,'Synthetic second member',street,city,zip,country_id,'membership-second@example.test',language,currency_default_id,1,0 FROM clients WHERE id=?")
            ->execute([$this->client]);
        [, $foreignMember] = $this->call('POST', 'createMember', ['client_id' => (int) $this->pdo->lastInsertId(), 'member_number' => 'M-ACTION-FOREIGN-REV']);
        $this->call('POST', 'appendRevision', ['status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0, 'idempotency_key' => 'foreign-rev',
            'legal_basis' => ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership', 'reference' => 'Synthetic statutes article 5', 'evidence' => 'Synthetic application']], ['id' => (string) $foreignMember['id']]);
        [, $foreignDetail] = $this->call('GET', 'member', null, ['id' => (string) $foreignMember['id']], ['accounting' => 1]);
        [$status, $notFound] = $this->call('POST', 'voidRevision', ['idempotency_key' => 'action-void-foreign'] + $void, $args + ['revisionId' => (string) $foreignDetail['history'][0]['id']]);
        self::assertSame([404, 'nonprofit_membership_revision_not_found'], [$status, $notFound['error']['code']]);
        [$status, $voiding] = $this->call('POST', 'voidRevision', $void, $args + ['revisionId' => (string) $revision]);
        self::assertSame(201, $status);
        self::assertSame($revision, $voiding['voids_revision_id']);
        $q = $this->pdo->prepare("SELECT action FROM activity_log WHERE supplier_id=? AND entity_type='nonprofit_member' AND entity_id=? ORDER BY id");
        $q->execute([$this->supplier, $id]);
        self::assertSame(['nonprofit_membership_member_update', 'nonprofit_membership_revision_void'], $q->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testErasureRoutesRequireSessionAndErasurePermission(): void
    {
        $erasure = $this->container->get(\MyInvoice\Action\Nonprofit\MembershipErasureAction::class);
        $read = ['accounting' => 1, 'accounting.journal.post' => 2, 'accounting.membership_erasure' => 1];
        $write = ['accounting.membership_erasure' => 2] + $read;
        [$status] = $this->call('GET', 'proposals', null, [], null, action: $erasure);
        self::assertSame(403, $status, 'Bez práva výmazu nejde ani číst návrhy se jmény.');
        [$status, $token] = $this->call('GET', 'proposals', null, [], $read, 'bearer', action: $erasure);
        self::assertSame([403, 'token_endpoint_forbidden'], [$status, $token['error']['code']]);
        [$status, $list] = $this->call('GET', 'proposals', null, [], $read, action: $erasure);
        self::assertSame([200, []], [$status, $list['items']]);
        [$status, $assessment] = $this->call('GET', 'assessment', null, [], $read, action: $erasure);
        self::assertSame(200, $status);
        self::assertSame([], $assessment['items']);
        [$status] = $this->call('POST', 'create', [], [], $read, action: $erasure);
        self::assertSame(403, $status, 'Čtení nestačí k sestavení návrhu.');
        [$status, $denied] = $this->call('POST', 'create', [], [], $write, 'bearer', action: $erasure);
        self::assertSame([403, 'session_required'], [$status, $denied['error']['code']]);
        [$status, $nothing] = $this->call('POST', 'create', [], [], $write, action: $erasure);
        self::assertSame([409, 'nonprofit_membership_erasure_nothing'], [$status, $nothing['error']['code']]);
        [$status, $invalid] = $this->call('POST', 'create', ['note' => 5], [], $write, action: $erasure);
        self::assertSame([422, 'nonprofit_membership_invalid'], [$status, $invalid['error']['code']]);
        [$status, $missing] = $this->call('POST', 'execute', ['confirmation' => 'TRVALE SMAZAT'], ['id' => '999999999'], $write, action: $erasure);
        self::assertSame([404, 'nonprofit_membership_erasure_not_found'], [$status, $missing['error']['code']]);
        [$status, $phrase] = $this->call('POST', 'execute', ['confirmation' => 'smazat'], ['id' => '1'], $write, action: $erasure);
        self::assertSame([422, 'nonprofit_membership_erasure_confirmation'], [$status, $phrase['error']['code']]);
    }

    private function call(string $httpMethod, string $method, ?array $body, array $args = [], ?array $permissions = null, string $auth = 'session', ?array $apiToken = null, ?array $query = null, ?object $action = null): array
    {
        $permissions ??= ['accounting' => 1, 'accounting.journal.post' => 2, 'accounting.templates' => 2];
        $request = (new ServerRequestFactory())->createServerRequest($httpMethod, '/api/accounting/nonprofit-membership')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $auth)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(501, 'Synthetic membership role', 'staff', true, $permissions));
        if ($apiToken !== null) $request = $request->withAttribute(AuthMiddleware::ATTR_API_TOKEN, $apiToken);
        if ($body !== null) $request = $request->withParsedBody($body);
        if ($query !== null) $request = $request->withQueryParams($query);
        $response = ($action ?? $this->action)->{$method}($request, new Response(), $args);
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
