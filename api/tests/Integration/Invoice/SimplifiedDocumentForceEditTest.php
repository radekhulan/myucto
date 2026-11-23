<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Úprava vystaveného dokladu správcem (?force=1) nesmí obejít pravidla zjednodušeného
 * daňového dokladu (§ 30 ZDPH), která jinak hlídá až vystavení: limit 10 000 Kč včetně
 * daně, zákaz u přenesené daňové povinnosti a u ř. 20/25. Výsledný vystavený doklad se
 * posuzuje stejně jako při vystavení.
 *
 * Volba „netisknout odběratele" mění tištěný doklad: uložená PDF verze se archivuje.
 * Bez zjednodušeného dokladu se volba neukládá.
 *
 * Izolace: rok 2099, vlastní čísla dokladů, úklid v tearDown.
 */
#[Group('integration')]
final class SimplifiedDocumentForceEditTest extends TestCase
{
    private Connection $db;
    private UpdateInvoiceAction $action;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    /** @var int[] */
    private array $created = [];
    /** @var string[] */
    private array $files = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje: test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(UpdateInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier WHERE is_vat_payer = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí plátce DPH.');
        }
        $this->clientId   = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} AND is_walk_in = 0 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$this->clientId, $this->currencyId, $this->vatRateId, $this->userId], true)) {
            $this->markTestSkipped('Chybí client/CZK/sazba 21 %/admin.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** Vystavený doklad s jednou položkou za 100 Kč bez DPH. */
    private function issued(string $varsymbol, bool $simplified): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, is_simplified, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_vat, total_with_vat, vat_classification_code, created_by)
             VALUES ('invoice', ?, ?, ?, ?, '2099-03-10', '2099-03-10', '2099-03-24', ?, 'issued', 100, 21, 121, '1', ?)"
        )->execute([$simplified ? 1 : 0, $varsymbol, $this->clientId, $this->supplierId, $this->currencyId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->created[] = $id;
        $pdo->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Drobný prodej', 1, 'ks', 100, ?, 21.00, 100, 21, 121, 1)"
        )->execute([$id, $this->vatRateId]);
        return $id;
    }

    /** @return array<string,mixed> */
    private function body(float $unitPrice, array $extra = []): array
    {
        return $extra + [
            'invoice_type' => 'invoice',
            'client_id'    => $this->clientId,
            'currency_id'  => $this->currencyId,
            'issue_date'   => '2099-03-10',
            'tax_date'     => '2099-03-10',
            'due_date'     => '2099-03-24',
            'items'        => [[
                'description'            => 'Drobný prodej',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => $unitPrice,
                'vat_rate_id'            => $this->vatRateId,
            ]],
        ];
    }

    /** @return array{status:int, code:?string, body:string} */
    private function forcePut(int $id, array $body): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/invoices/' . $id)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withQueryParams(['force' => '1'])
            ->withParsedBody($body);
        $res = ($this->action)($req, new Psr7Response(), ['id' => (string) $id]);
        $res->getBody()->rewind();
        $raw = (string) $res->getBody();
        $json = json_decode($raw, true);
        return ['status' => $res->getStatusCode(), 'code' => $json['error']['code'] ?? null, 'body' => $raw];
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        return $this->db->pdo()->query(
            "SELECT is_simplified, hide_customer_on_pdf, reverse_charge, discount_percent, total_with_vat, pdf_path FROM invoices WHERE id = {$id}"
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function testForceEditCannotTurnOverLimitDocumentIntoSimplified(): void
    {
        $id = $this->issued('P7FE-0001', simplified: false);

        $res = $this->forcePut($id, $this->body(50000, ['is_simplified' => true]));

        self::assertSame(422, $res['status'], $res['body']);
        self::assertSame('simplified_document_not_allowed', $res['code']);
        self::assertSame(0, (int) $this->row($id)['is_simplified'], 'Doklad se nezměnil.');
        self::assertSame(121.0, (float) $this->row($id)['total_with_vat']);
    }

    public function testForceEditCannotRaiseSimplifiedDocumentOverLimit(): void
    {
        $id = $this->issued('P7FE-0002', simplified: true);

        $res = $this->forcePut($id, $this->body(50000));

        self::assertSame(422, $res['status'], $res['body']);
        self::assertSame('simplified_document_not_allowed', $res['code']);
        self::assertSame(121.0, (float) $this->row($id)['total_with_vat']);
    }

    public function testForceEditAllowsInvoiceBelowLimitAfterHeaderDiscount(): void
    {
        $id = $this->issued('P7FE-0006', simplified: false);

        $res = $this->forcePut($id, $this->body(10000, [
            'is_simplified' => true,
            'discount_percent' => 20,
        ]));

        self::assertSame(200, $res['status'], $res['body']);
        self::assertSame(9680.0, (float) $this->row($id)['total_with_vat']);
    }

    public function testHeaderOnlyForceEditReplacesStaleGeneratedDiscountForLimitCheck(): void
    {
        $id = $this->issued('P7FE-0007', simplified: true);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE invoice_items SET unit_price_without_vat = 10000, total_without_vat = 10000,
                    total_vat = 2100, total_with_vat = 12100 WHERE invoice_id = ? AND order_index = 1'
        )->execute([$id]);
        $pdo->prepare(
            'UPDATE invoices SET discount_percent = 10, total_without_vat = 9000,
                    total_vat = 1890, total_with_vat = 10890 WHERE id = ?'
        )->execute([$id]);
        $pdo->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, item_kind)
             VALUES (?, 'Sleva 10 %', 1, 'ks', -1000, ?, 21.00, -1000, -210, -1210, 2, 'discount')"
        )->execute([$id, $this->vatRateId]);

        $res = $this->forcePut($id, [
            'discount_percent' => 20,
            'is_simplified' => true,
        ]);

        self::assertSame(200, $res['status'], $res['body']);
        self::assertSame(9680.0, (float) $this->row($id)['total_with_vat']);
    }
    public function testHeaderOnlyForceEditCannotReduceDiscountBelowSimplifiedLimit(): void
    {
        $id = $this->issued('P7FE-0008', simplified: true);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE invoice_items SET unit_price_without_vat = 10000, total_without_vat = 10000,
                    total_vat = 2100, total_with_vat = 12100 WHERE invoice_id = ? AND order_index = 1'
        )->execute([$id]);
        $pdo->prepare(
            'UPDATE invoices SET discount_percent = 20, total_without_vat = 8000,
                    total_vat = 1680, total_with_vat = 9680 WHERE id = ?'
        )->execute([$id]);
        $pdo->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, item_kind)
             VALUES (?, 'Sleva 20 %', 1, 'ks', -2000, ?, 21.00, -2000, -420, -2420, 2, 'discount')"
        )->execute([$id, $this->vatRateId]);

        $res = $this->forcePut($id, ['discount_percent' => 10]);

        self::assertSame(422, $res['status'], $res['body']);
        self::assertSame('simplified_document_not_allowed', $res['code']);
        self::assertSame(9680.0, (float) $this->row($id)['total_with_vat']);
        self::assertSame(20.0, (float) $this->row($id)['discount_percent']);
    }

    public function testForceEditUsesPersistedUnitPricePrecisionForSimplifiedLimit(): void
    {
        $id = $this->issued('P7FE-0009', simplified: false);
        $body = $this->body(1652.89, ['is_simplified' => true]);
        $body['items'][0]['quantity'] = 5;

        $res = $this->forcePut($id, $body);

        self::assertSame(200, $res['status'], $res['code'] ?? '');
        self::assertSame(9999.98, (float) $this->row($id)['total_with_vat']);
    }

    public function testSubCentUnitPriceCountsTowardsSimplifiedLimit(): void
    {
        $id = $this->issued('P7FE-0010', simplified: false);
        $before = (float) $this->row($id)['total_with_vat'];
        $body = $this->body(1652.893, ['is_simplified' => true]);
        $body['items'][0]['quantity'] = 5;

        $res = $this->forcePut($id, $body);

        self::assertSame(422, $res['status'], $res['body']);
        self::assertSame('simplified_document_not_allowed', $res['code']);
        self::assertSame($before, (float) $this->row($id)['total_with_vat']);
    }

    public function testForceEditCannotMakeSimplifiedDocumentReverseCharge(): void
    {
        $id = $this->issued('P7FE-0003', simplified: true);

        $res = $this->forcePut($id, $this->body(100, ['reverse_charge' => true]));

        self::assertSame(422, $res['status'], $res['body']);
        self::assertSame('simplified_document_not_allowed', $res['code']);
        self::assertSame(0, (int) $this->row($id)['reverse_charge']);
    }

    public function testHidingCustomerArchivesPrintedVersion(): void
    {
        $id = $this->issued('P7FE-0004', simplified: true);
        $dir = RuntimePaths::storage('invoices') . '/sup-' . $this->supplierId . '/2099-03';
        @mkdir($dir, 0775, true);
        $file = $dir . '/P7FE-0004-test.pdf';
        file_put_contents($file, '%PDF-1.4 testovací verze');
        $this->files[] = $file;
        $this->db->pdo()->prepare('UPDATE invoices SET pdf_path = ? WHERE id = ?')->execute([$file, $id]);

        $res = $this->forcePut($id, $this->body(100, ['is_simplified' => true, 'hide_customer_on_pdf' => true]));

        self::assertSame(200, $res['status'], $res['body']);
        $row = $this->row($id);
        self::assertSame(1, (int) $row['hide_customer_on_pdf']);
        self::assertNull($row['pdf_path'], 'Uložená PDF verze se zneplatnila.');
        $archived = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_pdfs WHERE invoice_id = {$id} AND reason = 'invalidate_update'"
        )->fetchColumn();
        self::assertSame(1, $archived, 'Vytištěná verze s odběratelem zůstala v archivu.');
    }

    public function testHideFlagIsNotStoredWithoutSimplifiedDocument(): void
    {
        $id = $this->issued('P7FE-0005', simplified: true);
        $this->db->pdo()->prepare('UPDATE invoices SET hide_customer_on_pdf = 1 WHERE id = ?')->execute([$id]);

        $res = $this->forcePut($id, $this->body(100, ['is_simplified' => false]));
        self::assertSame(200, $res['status'], $res['body']);
        self::assertSame(0, (int) $this->row($id)['hide_customer_on_pdf'], 'Vypnutý zjednodušený doklad volbu ruší.');

        $res = $this->forcePut($id, $this->body(100, ['is_simplified' => false, 'hide_customer_on_pdf' => true]));
        self::assertSame(200, $res['status'], $res['body']);
        self::assertSame(0, (int) $this->row($id)['hide_customer_on_pdf'], 'Běžná faktura volbu neuloží.');
    }
}
