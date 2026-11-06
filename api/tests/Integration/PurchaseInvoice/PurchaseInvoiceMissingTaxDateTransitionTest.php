<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction;
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
 * FR1 (vendor audit 2026-08): `tax_date` (DUZP) u přijaté faktury dřív protekl
 * bez povšimnutí až do podkladů DPH — PurchaseInvoiceValidation kontrolovala jen formát,
 * když byl vyplněný. Regrese k tvrdému bloku v TransitionPurchaseInvoiceStatusAction:
 * přechod `received → booked` bez DUZP musí padnout na 422, `received → paid`/jiné cesty
 * ne (jinak by upgrade zablokoval uzávěrku existujících, historicky migrovaných dokladů
 * bez DUZP, které jsou ALE už zaúčtované z doby před opravou).
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PurchaseInvoiceMissingTaxDateTransitionTest extends TestCase
{
    private Connection $db;
    private CreatePurchaseInvoiceAction $createAction;
    private TransitionPurchaseInvoiceStatusAction $transitionAction;

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
            $container              = Bootstrap::buildContainer();
            $this->db               = $container->get(Connection::class);
            $this->createAction     = $container->get(CreatePurchaseInvoiceAction::class);
            $this->transitionAction = $container->get(TransitionPurchaseInvoiceStatusAction::class);
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
             VALUES (?, "DUZP Test Vendor", "Test 1", "Praha", "11000", ?, "10000002",
                     "duzp@example.com", "cs", ?, 0, 1)'
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

    public function testTransitionToBookedWithoutTaxDateIsBlocked(): void
    {
        $id = $this->createInvoice('DUZP-2098-001', null);

        $received = $this->transition($id, 'received');
        self::assertSame(200, $received->getStatusCode(), 'draft → received nesmí DUZP kontrolovat.');

        $booked = $this->transition($id, 'booked');
        self::assertSame(422, $booked->getStatusCode(),
            'received → booked bez DUZP musí být tvrdě zablokován.');

        $booked->getBody()->rewind();
        $payload = json_decode((string) $booked->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('missing_tax_date', $payload['error']['code'] ?? null);

        $status = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $status->execute([$id]);
        self::assertSame('received', $status->fetchColumn(), 'Zamítnutý přechod nesmí doklad ve skutečnosti přepnout.');
    }

    public function testTransitionToBookedWithTaxDateSucceeds(): void
    {
        $id = $this->createInvoice('DUZP-2098-002', '2098-03-15');

        self::assertSame(200, $this->transition($id, 'received')->getStatusCode());
        $booked = $this->transition($id, 'booked');
        self::assertSame(200, $booked->getStatusCode(), 'S vyplněným DUZP musí zaúčtování projít.');

        $status = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $status->execute([$id]);
        self::assertSame('booked', $status->fetchColumn());
    }

    /**
     * Klíčová pojistka proti regresi „upgrade zablokuje uzávěrku existujícího období":
     * doklad, který byl zaúčtovaný ještě PŘED touto opravou (typicky migrace historie),
     * může v DB mít `tax_date IS NULL`. Nová kontrola se dívá jen na PŘECHOD do 'booked',
     * ne retroaktivně — takový doklad musí dál volně pokračovat na 'paid' i 'cancelled'.
     */
    public function testLegacyBookedInvoiceWithoutTaxDateStillTransitionsToPaid(): void
    {
        $id = $this->createInvoice('DUZP-2098-003', '2098-03-15');
        self::assertSame(200, $this->transition($id, 'received')->getStatusCode());
        self::assertSame(200, $this->transition($id, 'booked')->getStatusCode());

        // Simulace legacy stavu z doby před opravou: DUZP se v DB smaže, doklad zůstává booked.
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET tax_date = NULL WHERE id = ? AND supplier_id = ?'
        )->execute([$id, $this->supplierId]);

        $paid = $this->transition($id, 'paid', '2098-04-01');
        self::assertSame(200, $paid->getStatusCode(),
            'Legacy zaúčtovaný doklad bez DUZP nesmí uváznout — přechod na paid kontrolu DUZP neprovádí.');
    }

    /** DDKP (§ 28 ZDPH) se neuhrazuje: úhrada patří na zálohu, ruční „uhrazeno" 409. */
    public function testTaxDocumentCannotBeMarkedPaid(): void
    {
        $id = $this->createInvoice('DUZP-2098-004', '2098-03-15');
        self::assertSame(200, $this->transition($id, 'received')->getStatusCode());
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET document_kind = 'tax_document' WHERE id = ? AND supplier_id = ?"
        )->execute([$id, $this->supplierId]);

        $paid = $this->transition($id, 'paid', '2098-04-01');
        self::assertSame(409, $paid->getStatusCode());
        $paid->getBody()->rewind();
        $payload = json_decode((string) $paid->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('tax_document_not_payable', $payload['error']['code'] ?? null);

        $status = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $status->execute([$id]);
        self::assertSame('received', $status->fetchColumn());
    }

    private function createInvoice(string $vendorInvoiceNumber, ?string $taxDate): int
    {
        $body = [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => $vendorInvoiceNumber,
            'document_kind'         => 'invoice',
            'issue_date'            => '2098-03-01',
            'due_date'              => '2098-03-29',
            'currency_id'           => $this->currencyId,
            'items'                 => [],
        ];
        if ($taxDate !== null) {
            $body['tax_date'] = $taxDate;
        }

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/purchase-invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);

        $res = ($this->createAction)($req, new Psr7Response());
        self::assertSame(201, $res->getStatusCode(), 'Založení testovací faktury musí projít.');

        $res->getBody()->rewind();
        $payload = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return (int) $payload['id'];
    }

    private function transition(int $id, string $target, ?string $paidDate = null): \Psr\Http\Message\ResponseInterface
    {
        $body = ['target' => $target];
        if ($paidDate !== null) {
            $body['paid_date'] = $paidDate;
        }
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/purchase-invoices/{$id}/transition")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);

        return ($this->transitionAction)($req, new Psr7Response(), ['id' => (string) $id]);
    }
}
