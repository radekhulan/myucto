<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Daň v Kč ze zdroje převodu na položce přijaté faktury (`import_tax_base_czk`): převod
 * z POHODY tam drží základ samovyměření vyměřený kurzem ke dni plnění (2 500 Kč u faktury
 * 100 EUR kurzem 25,30). Uložení dokladu přes API položky maže a vkládá znovu; dřív tím
 * základ zmizel a evidence DPH vyměřila daň z 2 530 Kč, i když uživatel změnil jen popis.
 *
 * Základ zůstává u položky s nezměněným množstvím, cenou, sazbou a kódem zařazení a při
 * nezměněné měně, kurzu a režimu dokladu. Změna kurzu nebo ceny ho zahodí a tělo požadavku
 * ho nastavit nesmí.
 */
#[Group('integration')]
final class PurchaseSourceTaxSnapshotTest extends TestCase
{
    private const TAX_DATE = '2099-02-15';
    private const RATE = 25.3;

    private ContainerInterface $container;
    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private int $supplierId = 0;
    private int $eurId = 0;
    private int $vendorId = 0;
    private int $userId = 0;
    private int $rateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->repo = $this->container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $base = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rateId = (int) ($pdo->query("SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $de = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'DE' LIMIT 1")->fetchColumn() ?: 0);
        if ($base === 0 || $this->userId === 0 || $this->rateId === 0 || $de === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací 1", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id FROM supplier WHERE id = ?'
        )->execute(['Zdrojová daň test s.r.o.', 'zdroj-dan@example.com', $base]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $cur = $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active) VALUES (?, ?, ?, ?, ?, ?, 2, 1)');
        $cur->execute([$this->supplierId, 'EUR', 'EUR', '€', 'euro', 'euro']);
        $this->eurId = (int) $pdo->lastInsertId();
        $cur->execute([$this->supplierId, 'CZK', 'CZK', 'Kč', 'koruna', 'koruna']);
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, dic, street, city, zip, country_id, main_email, language, currency_default_id, is_vendor, is_vat_payer)
             VALUES (?, "Lieferant Test GmbH", "DE999999999", "Teststraße 1", "Berlin", "10115", ?, "l@example.com", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $de, $this->eurId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testDescriptionChangeKeepsSourceTaxBaseAndVat(): void
    {
        $id = $this->invoice();
        self::assertSame([2500.0, 525.0], $this->ledger($id));

        $result = $this->update($id, $this->body($id, description: 'Licence software - opravený popis', injected: 1.0));
        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('2500.00', $this->taxBase($id), 'Tělo požadavku základ nenastaví, uložený zůstane.');
        self::assertSame([2500.0, 525.0], $this->ledger($id));
    }

    public function testPriceChangeDropsSourceTaxBase(): void
    {
        $id = $this->invoice();
        self::assertSame(200, $this->update($id, $this->body($id, price: 120.0))['status']);
        self::assertNull($this->taxBase($id));
        self::assertSame([3036.0, 637.56], $this->ledger($id));
    }

    public function testRateChangeDropsSourceTaxBase(): void
    {
        $id = $this->invoice();
        self::assertSame(200, $this->update($id, $this->body($id, rate: 25.5))['status']);
        self::assertNull($this->taxBase($id));
        self::assertSame([2550.0, 535.5], $this->ledger($id));
    }

    private function invoice(): int
    {
        $id = $this->repo->createDraft([
            'vendor_id' => $this->vendorId, 'vendor_invoice_number' => 'RE-' . bin2hex(random_bytes(3)),
            'issue_date' => self::TAX_DATE, 'tax_date' => self::TAX_DATE, 'due_date' => '2099-03-01',
            'currency_id' => $this->eurId, 'exchange_rate' => self::RATE, 'exchange_rate_date' => self::TAX_DATE,
            'exchange_rate_source' => 'manual', 'reverse_charge' => 1, 'items' => [],
        ], $this->userId, $this->supplierId);
        $this->repo->replaceItems($id, [[
            'description' => 'Licence software', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 100.0,
            'vat_rate_id' => $this->rateId, 'vat_classification_code' => '24e',
        ]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET total_without_vat = 100, total_with_vat = 100, import_tax_base_czk = 2500 WHERE purchase_invoice_id = ?')->execute([$id]);
        $this->repo->updateTotals($id, 100.0, 0.0, 100.0, 0.0);
        return $id;
    }

    /** @return array<string,mixed> */
    private function body(int $id, string $description = 'Licence software', float $price = 100.0, float $rate = self::RATE, ?float $injected = null): array
    {
        $itemId = (int) $this->db->pdo()->query("SELECT id FROM purchase_invoice_items WHERE purchase_invoice_id = {$id}")->fetchColumn();
        $item = ['id' => $itemId, 'description' => $description, 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => $price,
            'vat_rate_id' => $this->rateId, 'vat_classification_code' => '24e'];
        if ($injected !== null) {
            $item['import_tax_base_czk'] = $injected;
        }
        return [
            'vendor_id' => $this->vendorId, 'vendor_invoice_number' => 'RE-UPDATED', 'document_kind' => 'invoice',
            'issue_date' => self::TAX_DATE, 'tax_date' => self::TAX_DATE, 'due_date' => '2099-03-01',
            'currency_id' => $this->eurId, 'exchange_rate' => $rate, 'exchange_rate_date' => self::TAX_DATE,
            'exchange_rate_source' => 'manual', 'reverse_charge' => 1, 'items' => [$item],
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function update(int $id, array $body): array
    {
        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willReturn(['rate' => self::RATE, 'rate_date' => self::TAX_DATE, 'fallback_used' => false, 'source' => 'fresh']);
        $this->container->set(CnbExchangeRateClient::class, $cnb);
        $action = $this->container->get(UpdatePurchaseInvoiceAction::class);
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/purchase-invoices/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        $resp = $action($req, new Psr7Response(), ['id' => (string) $id]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function taxBase(int $id): ?string
    {
        $v = $this->db->pdo()->query("SELECT import_tax_base_czk FROM purchase_invoice_items WHERE purchase_invoice_id = {$id}")->fetchColumn();
        return $v === null || $v === false ? null : (string) $v;
    }

    /** @return array{float,float} základ a daň samovyměření dokladu v evidenci DPH */
    private function ledger(int $id): array
    {
        $base = 0.0;
        $vat = 0.0;
        foreach ($this->container->get(VatLedgerService::class)->iterateRows($this->supplierId, '2099-02-01', '2099-02-28', true, ['purchase' => [$id]]) as $r) {
            $base += $r['base_czk'];
            $vat += $r['vat_czk'];
        }
        return [round($base, 2), round($vat, 2)];
    }
}
