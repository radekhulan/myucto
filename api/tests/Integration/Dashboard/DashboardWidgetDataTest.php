<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Dashboard;

use DI\Container;
use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Action\Portal\PortalSummaryAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Dashboard\DashboardWidgetDataService;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Syntetické doklady i firmy vznikají v transakci, včetně čisté DB bez seedovaných firem. */
#[Group('integration')]
final class DashboardWidgetDataTest extends TestCase
{
    use \MyInvoice\Tests\Support\IsolatedSupplierTrait;
    private Container $container;
    private Connection $db;
    private int $sid;
    private int $user;
    private int $currency;
    private int $client;
    private int $draft;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $vat));
        // První firma a vlastní měna mají cyklický FK, stejně jako při prvotním setupu.
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $pdo->prepare("INSERT INTO supplier (company_name,street,city,zip,country_id,email,default_currency_id,default_vat_rate_id,
                taxpayer_type,is_vat_payer,vat_period,accounting_mode,accounting_activation_status)
                VALUES ('Synthetic widget company','Test 1','Testov','10000',?,'widgets@example.invalid',0,?,'fo',1,'monthly','double_entry','completed')")
                ->execute([$country, $vat]);
            $this->sid = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en) VALUES (?,'CZK','Synthetic CZK','Kč','Koruna','Crown')")->execute([$this->sid]);
            $this->currency = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$this->currency, $this->sid]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $pdo->prepare("INSERT INTO supplier_vat_status_history (supplier_id,effective_from,is_vat_payer,is_identified) VALUES (?,'1900-01-01',1,0)")->execute([$this->sid]);
        $pdo->prepare("INSERT INTO users (email,password_hash,name,role) VALUES (?,?,'Synthetic widget actor','admin')")
            ->execute(['widgets-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('Unused synthetic test password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->user = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,currency_default_id)
            VALUES (?,'Synthetic secret partner','Test 2','Testov','10000',?,?)")->execute([$this->sid, $country, $this->currency]);
        $this->client = (int) $pdo->lastInsertId();
        foreach ([['issued', '-1 day', 1210], ['issued', '+3 days', 2420], ['draft', '+4 days', 605]] as [$status, $due, $amount]) {
            $pdo->prepare("INSERT INTO invoices (supplier_id,invoice_type,client_id,varsymbol,issue_date,tax_date,due_date,currency_id,
                total_without_vat,total_vat,total_with_vat,status,approval_status)
                VALUES (?,'invoice',?,?,CURDATE(),CURDATE(),?,?,?,?,?,?,'requested')")
                ->execute([$this->sid, $this->client, 'SYNTH-' . $amount, (new \DateTimeImmutable('today'))->modify($due)->format('Y-m-d'), $this->currency,
                    $amount / 1.21, $amount - $amount / 1.21, $amount, $status]);
            if ($status === 'draft') $this->draft = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO purchase_invoices (supplier_id,vendor_id,vendor_invoice_number,issue_date,tax_date,due_date,received_at,currency_id,
            vendor_snapshot,total_without_vat,total_vat,total_with_vat,status,created_by)
            VALUES (?,?,'SYNTH-PURCHASE',CURDATE(),CURDATE(),DATE_ADD(CURDATE(),INTERVAL 3 DAY),CURDATE(),?,'{}',200,42,242,'received',?)")
            ->execute([$this->sid, $this->client, $this->currency, $this->user]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testEveryRegisteredProviderReturnsAHealthyResultOnSyntheticCompany(): void
    {
        $registry = $this->container->get(DashboardWidgetRegistry::class);
        $widgets = [];
        foreach ($registry->all() as $i => $definition) {
            if ($definition['type'] === 'layout.divider') continue;
            $widgets[] = ['id' => 'test-' . $i, 'type' => $definition['type'], 'span' => $definition['defaultSpan'], 'options' => (object) []];
        }
        $widgets = $registry->validate(['schemaVersion' => 1, 'presetVersion' => 1, 'widgets' => $widgets])['widgets'];
        self::assertCount(23, $widgets);
        $result = (array) $this->container->get(DashboardWidgetDataService::class)->query($this->request('admin'), $widgets)['widgets'];
        foreach ($widgets as $widget) {
            self::assertContains($result[$widget['id']]['status'], ['ok', 'empty'], $widget['type'] . ': ' . json_encode($result[$widget['id']]));
        }
        self::assertSame(3000.0, $result['test-0']['data']['rows'][0]['invoiced']);
        self::assertSame(200.0, $result['test-0']['data']['rows'][0]['costs']);
    }

    public function testAdminAndMaximumClientShareBusinessValuesAndDeniedDetailsNeverLeak(): void
    {
        $registry = $this->container->get(DashboardWidgetRegistry::class);
        $types = ['business.kpi', 'business.monthly', 'sales.summary', 'purchases.summary', 'receivables.aging', 'payables.aging',
            'cashflow.weekly', 'cashflow.collections', 'receivables.due', 'invoices.overdue', 'invoices.upcoming', 'clients.top', 'invoices.drafts', 'invoices.approvals'];
        $widgets = [];
        foreach ($types as $type) $widgets[] = ['id' => str_replace('.', '-', $type), 'type' => $type, 'span' => $registry->get($type)['defaultSpan'], 'options' => (object) []];
        $widgets = $registry->validate(['schemaVersion' => 1, 'presetVersion' => 1, 'widgets' => $widgets])['widgets'];
        $service = $this->container->get(DashboardWidgetDataService::class);
        $admin = (array) $service->query($this->request('admin'), $widgets)['widgets'];
        $client = (array) $service->query($this->request('client'), $widgets)['widgets'];
        self::assertSame($admin, $client);
        $denied = (array) $service->query($this->request('client', []), $widgets)['widgets'];
        foreach (['sales-summary', 'invoices-overdue', 'clients-top', 'invoices-drafts'] as $id) self::assertSame(['status' => 'unavailable', 'data' => null], $denied[$id]);
        self::assertSame($admin['business-kpi'], $denied['business-kpi']);
        self::assertStringNotContainsString('Synthetic secret partner', json_encode($denied, JSON_THROW_ON_ERROR));
    }

    public function testDraftsHideProjectsAndContactLinksWithoutTargetPermissions(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO projects (client_id,name,currency_id) VALUES (?,'Synthetic secret project',?)")->execute([$this->client, $this->currency]);
        $project = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE invoices SET project_id=? WHERE id=?')->execute([$project, $this->draft]);
        $service = $this->container->get(DashboardWidgetDataService::class);
        $widget = [['id' => 'drafts', 'type' => 'invoices.drafts', 'span' => 6, 'options' => (object) []]];
        $limited = (array) $service->query($this->request('client', ['invoices' => 1]), $widget)['widgets'];
        $item = $limited['drafts']['data']['items'][0];
        self::assertSame('Synthetic secret partner', $item['client_company_name']);
        self::assertArrayNotHasKey('project_name', $item);
        self::assertArrayNotHasKey('project_id', $item);
        self::assertArrayNotHasKey('client', $item['links']);
        self::assertSame('/invoices/' . $this->draft, $item['links']['detail']);
        $full = (array) $service->query($this->request('admin'), $widget)['widgets'];
        self::assertSame('Synthetic secret project', $full['drafts']['data']['items'][0]['project_name']);
    }

    public function testApprovalsLinkNeedsOnlyApprovalPermissionAndVatLinkKeepsReportPeriod(): void
    {
        $service = $this->container->get(DashboardWidgetDataService::class);
        $widgets = [['id' => 'approvals', 'type' => 'invoices.approvals', 'options' => (object) []],
            ['id' => 'todo', 'type' => 'actions.todo', 'options' => (object) []],
            ['id' => 'vat', 'type' => 'vat.current', 'options' => (object) []]];
        $approvalOnly = (array) $service->query($this->request('client', ['invoices.approval' => 1]), $widgets)['widgets'];
        self::assertSame('/admin/approvals', $approvalOnly['approvals']['data']['links']['list']);
        self::assertSame('/admin/approvals', $approvalOnly['todo']['data']['items'][0]['link']);
        self::assertArrayNotHasKey('link', $approvalOnly['vat']['data']);
        $admin = (array) $service->query($this->request('admin'), $widgets)['widgets'];
        $link = $admin['vat']['data']['link'];
        self::assertStringStartsWith('/reports/dph?', $link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        self::assertSame(date('Y'), $query['year']);
        self::assertSame((string) ((int) date('n')), $query['month']);
        self::assertSame('monthly', $query['period']);
        self::assertSame('0', $query['remember']);
    }

    public function testTopClientsExposeCzkConversionAndSupplierSwitchDoesNotReuseData(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en) VALUES (?,'EUR','Synthetic EUR','€','Euro','Euro')")->execute([$this->sid]);
        $eur = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoices (supplier_id,invoice_type,client_id,varsymbol,issue_date,due_date,currency_id,
            exchange_rate,total_without_vat,total_vat,total_with_vat,status)
            VALUES (?,'invoice',?,'SYNTH-EUR',CURDATE(),CURDATE(),?,25,100,21,121,'issued')")->execute([$this->sid, $this->client, $eur]);
        $widgets = [['id' => 'top', 'type' => 'clients.top', 'options' => (object) []]];
        $service = $this->container->get(DashboardWidgetDataService::class);
        $first = (array) $service->query($this->request('admin'), $widgets)['widgets'];
        self::assertSame(5500.0, $first['top']['data']['items'][0]['total_czk']);
        self::assertSame('CZK', $first['top']['data']['items'][0]['currency']);
        self::assertSame('CZK,EUR', $first['top']['data']['items'][0]['currencies']);
        $other = $this->createIsolatedSupplier($pdo, $this->sid);
        $second = $service->query($this->request('admin')->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $other), $widgets);
        self::assertSame('empty', ((array) $second['widgets'])['top']['status']);
        self::assertSame(['hasDocuments' => false], $second['businessActivity']);
        self::assertStringNotContainsString('Synthetic secret partner', json_encode($second, JSON_THROW_ON_ERROR));
    }

    public function testBusinessActivityDoesNotDependOnSelectedWidgets(): void
    {
        $service = $this->container->get(DashboardWidgetDataService::class);
        self::assertSame(['hasDocuments' => true], $service->query($this->request('client', []), [])['businessActivity']);
        $catalog = $this->container->get(\MyInvoice\Service\Dashboard\DashboardWidgetAccess::class)->catalog($this->request('client', []), 'portal');
        self::assertSame(['hasDocuments' => true], $catalog['businessActivity']);
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id=?')->execute([$this->sid]);
        self::assertSame(['hasDocuments' => true], $service->query($this->request('client', []), [])['businessActivity'], 'Pouze přijaté doklady také představují aktivitu firmy.');
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id=?')->execute([$this->sid]);
        self::assertSame(['hasDocuments' => false], $service->query($this->request('client', []), [])['businessActivity']);
    }

    public function testStatisticsHonorCurrencyWithoutChangingOtherWidgetVariants(): void
    {
        $service = $this->container->get(DashboardWidgetDataService::class);
        $widgets = [['id' => 'sales', 'type' => 'sales.summary', 'options' => (object) ['currency' => 'EUR']],
            ['id' => 'purchases', 'type' => 'purchases.summary', 'options' => (object) ['currency' => 'EUR']]];
        $result = (array) $service->query($this->request('admin'), $widgets)['widgets'];
        self::assertSame('ok', $result['sales']['status']);
        self::assertSame(0, $result['sales']['data']['issued_count_ytd']);
        self::assertSame(0, $result['purchases']['data']['purchase_count_ytd']);
        self::assertSame(0.0, $result['purchases']['data']['purchase_unpaid_total']);
        $widgets[0]['options'] = (object) ['currency' => 'CZK'];
        $widgets[1]['options'] = (object) ['currency' => 'CZK'];
        $result = (array) $service->query($this->request('admin'), $widgets)['widgets'];
        self::assertSame(2, $result['sales']['data']['issued_count_ytd']);
        self::assertSame(1, $result['purchases']['data']['purchase_count_ytd']);
    }

    public function testSelectedKpisAndStatisticsMatchOriginalEndpoints(): void
    {
        $request = $this->request('admin');
        $summary = json_decode((string) ($this->container->get(SummaryAction::class))($request, new Response())->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $service = $this->container->get(DashboardWidgetDataService::class);
        $widgets = [['id' => 'sales', 'type' => 'sales.summary', 'options' => (object) []], ['id' => 'purchases', 'type' => 'purchases.summary', 'options' => (object) []]];
        $result = (array) $service->query($request, $widgets)['widgets'];
        foreach ($result['sales']['data'] as $key => $value) self::assertEquals($summary['kpi'][$key], $value, $key);
        foreach ($result['purchases']['data'] as $key => $value) self::assertEquals($summary['kpi'][$key], $value, $key);
        $portal = json_decode((string) ($this->container->get(PortalSummaryAction::class))($request, new Response())->getBody(), true, 512, JSON_THROW_ON_ERROR);
        foreach (['current_month', 'last_month', 'ytd', 'prev_year_ytd', 'last_12m'] as $period) {
            $data = (array) $service->query($request, [['id' => 'kpi', 'type' => 'business.kpi', 'options' => (object) ['period' => $period]]])['widgets'];
            self::assertEquals($portal['kpi'][$period], $data['kpi']['data']['rows'], $period);
        }
        $serialized = json_encode($portal, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Synthetic secret partner', $serialized);
        self::assertStringNotContainsString('SYNTH-1210', $serialized);
        self::assertStringNotContainsString('SYNTH-PURCHASE', $serialized);
    }

    private function request(string $role, ?array $permissions = null): \Psr\Http\Message\ServerRequestInterface
    {
        if ($permissions === null) {
            $catalog = new PermissionCatalog();
            $permissions = [];
            foreach ($catalog->all() as $key => $definition) if ($role === 'admin' || in_array('client', $definition['role_types'], true)) $permissions[$key] = 2;
        }
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/dashboard-widgets/query')
            ->withAttribute(\MyInvoice\Middleware\LicenseMiddleware::ATTR_STATE, $this->container->get(\MyInvoice\Service\License\LicenseService::class)->current())
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => $role])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid)
            ->withAttribute('auth.effective_role', new EffectiveRole(71, 'Synthetic widget role', $role === 'client' ? 'client' : 'staff', true, $permissions));
    }
}
