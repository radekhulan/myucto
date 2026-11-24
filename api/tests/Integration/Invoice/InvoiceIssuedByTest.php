<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * „Vystavil" na faktuře: kdo doklad vystavil, se při vystavení zapíše na doklad
 * (`invoices.issued_by` + jméno jako snapshot `issued_by_name`) a PDF ho vytiskne,
 * jen když to firma zapne (`supplier.invoice_pdf_show_issued_by`, výchozí vypnuto).
 * Starší doklady bez zápisu tisknou autora dokladu (`created_by_name`).
 */
#[Group('integration')]
final class InvoiceIssuedByTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private InvoicePdfRenderer $renderer;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private string $userName = '';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->renderer = $container->get(InvoicePdfRenderer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $user = $pdo->query('SELECT id, name FROM users ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query(
            'SELECT id FROM vat_rates WHERE rate_percent = 21 AND is_reverse_charge = 0 ORDER BY valid_from DESC LIMIT 1'
        )->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $user === false || $this->currencyId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user/currency/vat_rate/country).');
        }
        $this->userId = (int) $user['id'];
        $this->userName = (string) $user['name'];

        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET stock_enabled = 0, accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);

        // Bez e-mailu: automatické vystavení pak nemá komu fakturu poslat a SMTP se nevolá.
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, currency_default_id,
                 main_email, language, is_customer, is_vendor)
             VALUES (?, "Odběratel Vystavil s.r.o.", "Testovací 1", "Praha", "11000", ?, ?, "", "cs", 1, 0)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $sid = $this->supplierId;
        $pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')
            ->execute([$sid]);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? AND parent_invoice_id IS NOT NULL')->execute([$sid]);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$sid]);
        $this->db->close();
    }

    public function testIssueRecordsIssuingUser(): void
    {
        $id = $this->draft();
        self::assertSame([null, null], $this->issuer($id), 'Koncept vystavitele nemá.');

        $result = $this->call(Bootstrap::buildContainer()->get(IssueInvoiceAction::class), 'issue', $id);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame([$this->userId, $this->userName], $this->issuer($id));
    }

    public function testAutomaticIssueRecordsUserWhoTriggeredIt(): void
    {
        $id = $this->draft();

        Bootstrap::buildContainer()->get(AutoIssueAndSendService::class)
            ->run($id, $this->userId, '127.0.0.1', 'phpunit');

        self::assertSame([$this->userId, $this->userName], $this->issuer($id));
    }

    /** Schválení výkazu klientem přes veřejný odkaz nemá přihlášeného uživatele. */
    public function testAutomaticIssueWithoutUserLeavesIssuerEmpty(): void
    {
        $id = $this->draft();

        Bootstrap::buildContainer()->get(AutoIssueAndSendService::class)
            ->run($id, null, '127.0.0.1', 'phpunit');

        self::assertSame('issued', $this->invoiceStatus($id));
        self::assertSame([null, null], $this->issuer($id));
    }

    /** Pravidelná fakturace volá automat s autorem šablony jen kvůli auditu, vystavitelem není. */
    public function testAutomaticIssueOnBehalfOfUserLeavesIssuerEmpty(): void
    {
        $id = $this->draft();

        Bootstrap::buildContainer()->get(AutoIssueAndSendService::class)
            ->run($id, $this->userId, '127.0.0.1', 'phpunit', userIsIssuer: false);

        self::assertSame('issued', $this->invoiceStatus($id));
        self::assertSame([null, null], $this->issuer($id));
    }

    public function testInternalCancellationRecordsUser(): void
    {
        $container = Bootstrap::buildContainer();
        $webhooks = $container->get(\MyInvoice\Service\Integration\WebhookEndpoints::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $endpoint = $webhooks->create($this->supplierId, [
            'name' => 'Vystavení a storno', 'url' => 'https://receiver.example.test/events',
            'event_types' => ['invoice.issued', 'invoice.cancelled'],
        ]);
        $pdo->commit();
        $id = $this->draft();
        self::assertSame(200, $this->call($container->get(IssueInvoiceAction::class), 'issue', $id)['status']);

        $result = $this->call($container->get(CancelInvoiceAction::class), 'cancel', $id, ['mode' => 'internal']);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE) ?: '');
        $stmt = $this->db->pdo()->prepare("SELECT id FROM invoices WHERE parent_invoice_id = ? AND invoice_type = 'cancellation'");
        $stmt->execute([$id]);
        $cancellationId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $cancellationId);
        self::assertSame([$this->userId, $this->userName], $this->issuer($cancellationId));
        $events = $webhooks->history($this->supplierId, $endpoint['id'], PHP_INT_MAX);
        self::assertSame(['invoice.cancelled', 'invoice.issued'], array_column($events, 'event_type'));
    }

    public function testPdfOmitsIssuerByDefault(): void
    {
        $html = $this->renderer->renderHtml($this->renderable('Jana Vystavující', 'Petr Autor'), includeCss: false);

        self::assertStringNotContainsString('Vystavil', $html);
        self::assertStringNotContainsString('Jana Vystavující', $html);
    }

    public function testPdfPrintsIssuerWhenEnabled(): void
    {
        $this->enable();

        $html = $this->renderer->renderHtml($this->renderable('Jana Vystavující', 'Petr Autor'), includeCss: false);

        self::assertMatchesRegularExpression('~Vystavil</td>\s*<td[^>]*>Jana Vystavující</td>~u', $html);
        self::assertStringNotContainsString('Petr Autor', $html);
    }

    public function testPdfFallsBackToAuthorForOlderInvoices(): void
    {
        $this->enable();

        $html = $this->renderer->renderHtml($this->renderable(null, 'Petr Autor'), includeCss: false);

        self::assertMatchesRegularExpression('~Vystavil</td>\s*<td[^>]*>Petr Autor</td>~u', $html);
    }

    public function testEnglishPdfUsesEnglishLabel(): void
    {
        $this->enable();

        $html = $this->renderer->renderHtml($this->renderable('Jana Vystavující', null, 'en'), includeCss: false);

        self::assertMatchesRegularExpression('~Issued by</td>\s*<td[^>]*>Jana Vystavující</td>~u', $html);
    }

    public function testPdfWithoutAnyNameHasNoIssuerRow(): void
    {
        $this->enable();

        $html = $this->renderer->renderHtml($this->renderable(null, null), includeCss: false);

        self::assertStringNotContainsString('Vystavil', $html);
    }

    public function testSettingDefaultsOffAndIsSavedFromSettings(): void
    {
        $action = Bootstrap::buildContainer()->get(SettingsAction::class);

        $before = $this->settings($action, 'getSupplier', 'GET');
        self::assertSame(200, $before['status']);
        self::assertFalse($before['body']['invoice_pdf_show_issued_by']);

        $saved = $this->settings($action, 'updateSupplier', 'PUT', ['invoice_pdf_show_issued_by' => true]);
        self::assertSame(200, $saved['status'], json_encode($saved['body'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertTrue($saved['body']['invoice_pdf_show_issued_by']);
        $stmt = $this->db->pdo()->prepare('SELECT invoice_pdf_show_issued_by FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function settings(SettingsAction $action, string $method, string $httpMethod, array $body = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        $response = $action->{$method}($request, new Psr7Response());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function enable(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET invoice_pdf_show_issued_by = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
    }

    private function draft(): int
    {
        $pdo = $this->db->pdo();
        $date = date('Y-m-d');
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, language, status, total_without_vat, total_vat, total_with_vat, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, ?, "cs", "draft", 100, 21, 121, ?)'
        )->execute([$this->supplierId, $this->clientId, $date, $date, $date, $this->currencyId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Testovací plnění", 1, "ks", 100, ?, 21, 100, 21, 121, 0)'
        )->execute([$id, $this->vatRateId]);
        return $id;
    }

    /** @return array{0:?int,1:?string} */
    private function issuer(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT issued_by, issued_by_name FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = (array) $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            $row['issued_by'] === null ? null : (int) $row['issued_by'],
            $row['issued_by_name'] === null ? null : (string) $row['issued_by_name'],
        ];
    }

    private function invoiceStatus(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function call(object $action, string $path, int $id, array $body = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/' . $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $response = $action($request, new Psr7Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @return array<string,mixed> */
    private function renderable(?string $issuedByName, ?string $createdByName, string $language = 'cs'): array
    {
        return [
            'id'                    => 0,
            'invoice_type'          => 'invoice',
            'status'                => 'issued',
            'language'              => $language,
            'currency'              => 'CZK',
            'currency_id'           => 0,
            'client_id'             => 0,
            'supplier_id'           => $this->supplierId,
            'varsymbol'             => 'VYSTTEST1',
            'issued_by_name'        => $issuedByName,
            'created_by_name'       => $createdByName,
            'issue_date'            => '2026-07-01',
            'tax_date'              => '2026-07-01',
            'due_date'              => '2026-07-15',
            'paid_at'               => null,
            'amount_to_pay'         => 121.0,
            'paid_total'            => 0.0,
            'parent_invoice_id'     => null,
            'payment_method'        => 'bank_transfer',
            'prices_include_vat'    => false,
            'reverse_charge'        => false,
            'branding_profile_id'   => null,
            'advance_paid_amount'   => 0.0,
            'czk_recap'             => null,
            'supplier_snapshot'     => json_encode([
                'company_name' => 'Testovací dodavatel',
                'street'       => 'Zkušební 1',
                'city'         => 'Praha',
                'zip'          => '11000',
                'is_vat_payer' => true,
            ], JSON_UNESCAPED_UNICODE),
            'client_snapshot'       => json_encode([
                'company_name' => 'Testovací odběratel',
                'street'       => 'Testovací 2',
                'city'         => 'Brno',
                'zip'          => '60200',
                'country_iso2' => 'CZ',
            ], JSON_UNESCAPED_UNICODE),
            'bank_snapshot'         => null,
            'items'                 => [[
                'description'            => 'Testovací plnění',
                'quantity'               => 1.0,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 100.0,
                'vat_rate_snapshot'      => 21.0,
                'total_without_vat'      => 100.0,
                'total_vat'              => 21.0,
                'total_with_vat'         => 121.0,
                'item_kind'              => 'standard',
                'oss_applicable'         => false,
                'oss_consumer_country'   => null,
            ]],
            'totals'                => ['without_vat' => 100.0, 'vat' => 21.0, 'with_vat' => 121.0],
            'vat_breakdown'         => [],
        ];
    }
}
