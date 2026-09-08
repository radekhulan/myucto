<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Přesný duplikát přijaté faktury (stejný dodavatel + číslo dokladu + datum vystavení)
 * musí skončit srozumitelnou 409, ne holou 500 z porušení UNIQUE `uq_pi_vendor_invoice`.
 * Regrese k auditnímu nálezu 0.10 — viz HandlesVarsymbolDuplicate::vendorInvoiceDuplicateMessage.
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PurchaseInvoiceDuplicateTest extends TestCase
{
    private Connection $db;
    private CreatePurchaseInvoiceAction $createAction;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $vendorId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db           = $container->get(Connection::class);
            $this->createAction = $container->get(CreatePurchaseInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Dup Test Vendor", "Test 1", "Praha", "11000", ?, "10000001",
                     "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        if ($this->vendorId !== 0) {
            $ids = $pdo->query(
                'SELECT id FROM purchase_invoices WHERE vendor_id = ' . $this->vendorId
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($ids as $id) {
                $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->vendorId]);
        }
        $this->db->close();
    }

    public function testExactDuplicateReturns409NotUnhandledException(): void
    {
        $body = [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'DUP-2098-001',
            'document_kind'         => 'invoice',
            'issue_date'            => '2098-03-15',
            'tax_date'              => '2098-03-15',
            'due_date'              => '2098-03-29',
            'currency_id'           => $this->currencyId,
            'items'                 => [],
        ];

        $first = $this->create($body);
        self::assertSame(201, $first->getStatusCode(), 'první PF se vytvoří (201)');

        // Přesný duplikát: stejný dodavatel + číslo dokladu + datum vystavení.
        $second = $this->create($body);
        self::assertSame(409, $second->getStatusCode(),
            'přesný duplikát musí vrátit srozumitelnou 409, ne 500 / neošetřenou výjimku');

        $second->getBody()->rewind();
        $payload = json_decode((string) $second->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('vendor_invoice_duplicate', $payload['error']['code'] ?? null,
            'chybový kód identifikuje duplicitní přijatou fakturu');
    }

    public function testSuccessfulSavePromotesCustomerAndFailedDuplicateDoesNot(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE clients SET is_customer = 1, is_vendor = 0 WHERE id = ?')->execute([$this->vendorId]);
        $body = [
            'vendor_id' => $this->vendorId,
            'vendor_invoice_number' => 'ROLE-2098-001',
            'document_kind' => 'invoice',
            'issue_date' => '2098-03-15',
            'tax_date' => '2098-03-15',
            'due_date' => '2098-03-29',
            'currency_id' => $this->currencyId,
            'items' => [],
        ];
        self::assertSame(201, $this->create($body)->getStatusCode());
        $flags = fn (): array => $pdo->query('SELECT is_customer, is_vendor FROM clients WHERE id = ' . $this->vendorId)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $flags()['is_vendor']);
        self::assertSame(1, (int) $flags()['is_customer']);
        $pdo->prepare('UPDATE clients SET is_vendor = 0 WHERE id = ?')->execute([$this->vendorId]);
        self::assertSame(409, $this->create($body)->getStatusCode());
        self::assertSame(0, (int) $flags()['is_vendor']);
    }

    public function testUpdatePromotesCustomerOnlyWhenWholeSaveSucceeds(): void
    {
        $pdo = $this->db->pdo();
        $body = [
            'vendor_id' => $this->vendorId,
            'vendor_invoice_number' => 'ROLE-UPDATE-2098-001',
            'document_kind' => 'invoice',
            'issue_date' => '2098-03-15',
            'tax_date' => '2098-03-15',
            'due_date' => '2098-03-29',
            'currency_id' => $this->currencyId,
            'items' => [],
        ];
        $created = $this->create($body);
        self::assertSame(201, $created->getStatusCode());
        $id = (int) $pdo->query('SELECT id FROM purchase_invoices WHERE vendor_id = ' . $this->vendorId)->fetchColumn();
        $pdo->prepare('UPDATE clients SET is_customer = 1, is_vendor = 0 WHERE id = ?')->execute([$this->vendorId]);
        $action = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/purchase-invoices/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $invalid = $action($request->withParsedBody($body + ['vat_allocations' => [[]]]), new Psr7Response(), ['id' => $id]);
        self::assertSame(400, $invalid->getStatusCode(), (string) $invalid->getBody());
        self::assertSame(0, (int) $pdo->query('SELECT is_vendor FROM clients WHERE id = ' . $this->vendorId)->fetchColumn());
        $valid = $action($request->withParsedBody($body), new Psr7Response(), ['id' => $id]);
        self::assertSame(200, $valid->getStatusCode(), (string) $valid->getBody());
        self::assertSame(1, (int) $pdo->query('SELECT is_vendor FROM clients WHERE id = ' . $this->vendorId)->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT is_customer FROM clients WHERE id = ' . $this->vendorId)->fetchColumn());
    }

    private function create(array $body): Psr7Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/purchase-invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);

        return ($this->createAction)($req, new Psr7Response());
    }
}
