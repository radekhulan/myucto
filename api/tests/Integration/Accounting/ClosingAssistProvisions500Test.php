<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Closing\ClosingAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Asistovaný zápis zákonné rezervy přes HTTP (`step=provisions`) je funkce uzávěrky
 * podle vyhlášky 504. Firma na rámci 500 ho přes API nedostala ani před větví 504
 * a krok rezerv u ní vlastní průvodce opravných položek.
 */
#[Group('integration')]
final class ClosingAssistProvisions500Test extends TestCase
{
    private Connection $db;
    private int $supplier;
    private int $period;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic commercial closing','Test 1','Test','10000','commercial-closing@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'1900-01-01','cz_500')")
            ->execute([$this->supplier]);
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2096, '2096-01-01', '2096-12-31');
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier, '2096-12-31');
        $pdo->prepare("UPDATE accounting_periods SET status='closing' WHERE id=?")->execute([$this->period]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testCommercialFrameworkCannotUseAssistedReserveEndpoint(): void
    {
        $version = (new AccountingPeriodRepository($this->db))->findById($this->supplier, $this->period)['row_version'];
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withParsedBody([
                'step' => 'provisions',
                'rule_key' => 'reserve.repairs.create',
                'amount' => '100.00',
                'description' => 'Synthetic commercial reserve',
                'row_version' => $version,
            ]);

        $action = Bootstrap::buildContainer()->get(ClosingAction::class);
        $response = $action->createEntry($request, new Response(), ['id' => $this->period]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('validation_failed', (string) $response->getBody());
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $count->execute([$this->supplier]);
        self::assertSame(0, (int) $count->fetchColumn());
    }
}
