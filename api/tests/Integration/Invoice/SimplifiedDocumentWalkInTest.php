<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Action\Invoice\PublicInvoiceGetAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * § 30 ZDPH — zjednodušený daňový doklad bez odběratele.
 *
 * Zjednodušený doklad nemusí nést údaje o odběrateli (§ 30a). Doklad bez klienta se
 * naváže na systémového koncového zákazníka firmy, který se v adresáři neukazuje
 * a na PDF se netiskne. Běžný daňový doklad s ním vystavit nejde — § 29 odběratele
 * vyžaduje.
 */
#[Group('integration')]
final class SimplifiedDocumentWalkInTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ClientRepository $clients;
    private InvoiceRepository $invoices;
    private IssueInvoiceAction $issue;
    private CreateInvoiceAction $create;
    private InvoicePdfRenderer $pdf;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db       = $c->get(Connection::class);
            $this->clients  = $c->get(ClientRepository::class);
            $this->invoices = $c->get(InvoiceRepository::class);
            $this->issue    = $c->get(IssueInvoiceAction::class);
            $this->create   = $c->get(CreateInvoiceAction::class);
            $this->pdf      = $c->get(InvoicePdfRenderer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if (in_array(0, [$source, $this->userId, $this->currencyId, $this->vatRateId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET stock_enabled = 0, is_vat_payer = 1 WHERE id = ?')->execute([$this->supplierId]);

        // Izolovaná firma nemá vlastní měny — klient (i koncový zákazník) musí mít měnu firmy.
        $columns = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'currencies'
                AND COLUMN_NAME NOT IN ('id', 'supplier_id') AND EXTRA NOT LIKE '%auto_increment%'
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $list = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
        $pdo->prepare("INSERT INTO currencies (supplier_id, {$list}) SELECT ?, {$list} FROM currencies WHERE id = ?")
            ->execute([$this->supplierId, $this->currencyId]);
        $this->currencyId = (int) $pdo->lastInsertId();
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

    public function testWalkInCustomerIsSingleHiddenAndQuiet(): void
    {
        $first = $this->clients->findOrCreateWalkInCustomer($this->supplierId);
        $second = $this->clients->findOrCreateWalkInCustomer($this->supplierId);

        self::assertSame($first, $second, 'Koncový zákazník je jeden na firmu.');
        self::assertTrue($this->clients->isWalkInCustomer($first));
        $row = $this->clients->find($first);
        self::assertTrue($row['is_walk_in']);
        self::assertFalse((bool) $row['auto_send_reminders'], 'Koncovému zákazníkovi se upomínky neposílají.');

        $regular = $this->clients->create([
            'company_name' => 'Běžný odběratel', 'street' => 'Test 1', 'city' => 'Praha', 'zip' => '11000',
            'country_iso2' => 'CZ', 'currency_default_id' => $this->currencyId,
        ], $this->supplierId);
        $listed = array_column($this->clients->list(['supplier_id' => $this->supplierId], 1, 500)['data'], 'id');
        self::assertContains($regular, $listed, 'Běžný klient v adresáři je — kontrola není naprázdno.');
        self::assertNotContains($first, $listed, 'V adresáři klientů se neukazuje.');
        self::assertSame([], $this->clients->searchQuick('Koncový', $this->supplierId));
    }

    public function testOrdinaryTaxDocumentWithWalkInCustomerIsRejected(): void
    {
        $id = $this->draft(simplified: false);

        $result = $this->issueDraft($id);

        self::assertSame(422, $result['status']);
        self::assertSame('walk_in_customer_requires_simplified', $result['code']);
    }

    public function testSimplifiedDocumentWithWalkInCustomerPassesTheCustomerRule(): void
    {
        $id = $this->draft(simplified: true);

        $result = $this->issueDraft($id);

        self::assertNotSame('walk_in_customer_requires_simplified', $result['code']);
        self::assertSame(200, $result['status'], (string) $result['code']);
    }

    public function testSimplifiedPdfHasTitleAndNoCustomerBlock(): void
    {
        $id = $this->draft(simplified: true);

        $html = $this->pdf->renderHtml($this->invoices->find($id), includeCss: false);

        self::assertStringContainsString('Zjednodušený daňový doklad', $html);
        self::assertStringNotContainsString('Odběratel', $html, 'Koncový zákazník se jako odběratel netiskne.');
        self::assertStringNotContainsString(ClientRepository::WALK_IN_CUSTOMER_NAME, $html);
        self::assertStringNotContainsString('class="party-customer"', $html, 'Ani prázdný box odběratele.');
    }

    public function testInvoiceDetailFlagsWalkInCustomer(): void
    {
        $id = $this->draft(simplified: true);

        self::assertTrue($this->invoices->find($id)['client_is_walk_in']);
    }


    // ── vlastní řada a netisknutý odběratel (migrace 2037) ───────────────────

    public function testSimplifiedDocumentTakesNumberFromOwnSeries(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET invoice_number_format = 'FV{YYYY}{CCCC}', simplified_number_format = 'ZD{YYYY}{CCCC}',
                    invoice_number_period = 'year' WHERE id = ?"
        )->execute([$this->supplierId]);

        $simplified = $this->draft(simplified: true);
        $ordinary = $this->draft(simplified: false, clientId: $this->regularClient());

        self::assertSame(200, $this->issueDraft($simplified)['status']);
        self::assertSame(200, $this->issueDraft($ordinary)['status']);

        self::assertSame('ZD20990001', $this->invoices->find($simplified)['varsymbol']);
        self::assertSame('FV20990001', $this->invoices->find($ordinary)['varsymbol'], 'Faktura zůstala v řadě faktur.');
    }

    public function testWithoutOwnSeriesSimplifiedDocumentKeepsInvoiceSeries(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET invoice_number_format = 'FV{YYYY}{CCCC}', simplified_number_format = NULL,
                    invoice_number_period = 'year' WHERE id = ?"
        )->execute([$this->supplierId]);

        $ordinary = $this->draft(simplified: false, clientId: $this->regularClient());
        $simplified = $this->draft(simplified: true);
        $this->issueDraft($ordinary);
        $this->issueDraft($simplified);

        self::assertSame('FV20990002', $this->invoices->find($simplified)['varsymbol']);
    }

    public function testHiddenCustomerIsNotPrintedOnSimplifiedDocument(): void
    {
        $id = $this->draft(simplified: true, clientId: $this->regularClient(), hideCustomer: true);

        $html = $this->pdf->renderHtml($this->invoices->find($id), includeCss: false);

        self::assertStringContainsString('Zjednodušený daňový doklad', $html);
        self::assertStringNotContainsString('Stálý odběratel s.r.o.', $html);
        self::assertStringNotContainsString('class="party-customer"', $html);
    }

    public function testCustomerIsPrintedWhenNotHidden(): void
    {
        $id = $this->draft(simplified: true, clientId: $this->regularClient(), hideCustomer: false);

        $html = $this->pdf->renderHtml($this->invoices->find($id), includeCss: false);

        self::assertStringContainsString('Stálý odběratel s.r.o.', $html);
    }

    public function testOrdinaryInvoiceAlwaysPrintsCustomer(): void
    {
        // Běžný daňový doklad odběratele nést musí (§ 29), volba na něm nic nemění.
        $id = $this->draft(simplified: false, clientId: $this->regularClient(), hideCustomer: true);

        $html = $this->pdf->renderHtml($this->invoices->find($id), includeCss: false);

        self::assertStringContainsString('Stálý odběratel s.r.o.', $html);
        self::assertStringContainsString('class="party-customer"', $html);
    }

    public function testPublicInvoiceHidesCustomerLikePdf(): void
    {
        $client = ['company_name' => 'Stálý odběratel s.r.o.', 'is_walk_in' => false];
        $supplier = ['company_name' => 'Dodavatel', 'is_vat_payer' => true];

        $hidden = PublicInvoiceGetAction::buildPayload(
            ['invoice_type' => 'invoice', 'is_simplified' => true, 'hide_customer_on_pdf' => true],
            $supplier, $client, null, null,
        );
        $shown = PublicInvoiceGetAction::buildPayload(
            ['invoice_type' => 'invoice', 'is_simplified' => false, 'hide_customer_on_pdf' => true],
            $supplier, $client, null, null,
        );

        self::assertNull($hidden['client']);
        self::assertSame('Stálý odběratel s.r.o.', $shown['client']['company_name']);
    }

    public function testNewSimplifiedDraftTakesCompanyDefault(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET simplified_hide_customer_default = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $clientId = $this->regularClient();

        $simplified = $this->createDraft(['is_simplified' => true, 'client_id' => $clientId]);
        $ordinary = $this->createDraft(['is_simplified' => false, 'client_id' => $clientId]);
        $explicit = $this->createDraft(['is_simplified' => true, 'client_id' => $clientId, 'hide_customer_on_pdf' => false]);

        self::assertTrue($this->invoices->find($simplified)['hide_customer_on_pdf']);
        self::assertFalse($this->invoices->find($ordinary)['hide_customer_on_pdf']);
        self::assertFalse($this->invoices->find($explicit)['hide_customer_on_pdf'], 'Volba v dokladu má přednost.');
    }

    public function testHideFlagIsNotStoredOnOrdinaryInvoice(): void
    {
        $id = $this->createDraft(['is_simplified' => false, 'client_id' => $this->regularClient(), 'hide_customer_on_pdf' => true]);

        self::assertFalse($this->invoices->find($id)['hide_customer_on_pdf'], 'Běžná faktura volbu neuloží.');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function regularClient(): int
    {
        return $this->clients->create([
            'company_name' => 'Stálý odběratel s.r.o.', 'street' => 'Test 1', 'city' => 'Praha', 'zip' => '11000',
            'country_iso2' => 'CZ', 'currency_default_id' => $this->currencyId,
        ], $this->supplierId);
    }

    /** @param array<string,mixed> $overrides */
    private function createDraft(array $overrides): int
    {
        $body = $overrides + [
            'invoice_type' => 'invoice',
            'currency_id'  => $this->currencyId,
            'issue_date'   => '2099-01-05',
            'tax_date'     => '2099-01-05',
            'due_date'     => '2099-01-19',
            'items'        => [[
                'description' => 'Drobný prodej', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => 80, 'vat_rate_id' => $this->vatRateId,
            ]],
        ];
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $res = ($this->create)($req, new Psr7Response());
        $res->getBody()->rewind();
        $json = json_decode((string) $res->getBody(), true);
        self::assertContains($res->getStatusCode(), [200, 201], (string) json_encode($json));

        return (int) ($json['data']['id'] ?? $json['id'] ?? 0);
    }

    private function draft(bool $simplified, ?int $clientId = null, bool $hideCustomer = false): int
    {
        $clientId ??= $this->clients->findOrCreateWalkInCustomer($this->supplierId);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, invoice_type, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, client_snapshot, supplier_snapshot, is_simplified, hide_customer_on_pdf,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", "2099-01-05", "2099-01-05", "2099-01-05", ?, 0, "{}", "{}", ?, ?,
                     80, 0, 80, "draft", "1", ?)'
        )->execute([$this->supplierId, $clientId, $this->currencyId, $simplified ? 1 : 0, $hideCustomer ? 1 : 0, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Drobný prodej", 1, 80, ?, 0.00, 80, 0, 80, 1)'
        )->execute([$id, $this->vatRateId]);

        return $id;
    }

    /** @return array{status:int, code:?string} */
    private function issueDraft(int $id): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/issue')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);

        try {
            $res = ($this->issue)($req, new Psr7Response(), ['id' => (string) $id]);
        } catch (\PDOException $e) {
            // Vystavení po validaci pokračuje vlastní transakcí, kterou uvnitř testovací
            // transakce dokončit nelze. Kontroly odběratele už proběhly.
            if ((int) ($e->errorInfo[1] ?? 0) === 1568
                || str_contains($e->getMessage(), 'already an active transaction')) {
                return ['status' => 200, 'code' => null];
            }
            throw $e;
        }
        $res->getBody()->rewind();
        $body = json_decode((string) $res->getBody(), true);

        return ['status' => $res->getStatusCode(), 'code' => $body['error']['code'] ?? null];
    }
}
