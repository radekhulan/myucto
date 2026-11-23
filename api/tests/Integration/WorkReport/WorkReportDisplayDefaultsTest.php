<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\WorkReport;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Action\Settings\ClientBrandingSettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\WorkReport\WorkReportDisplayOptions;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class WorkReportDisplayDefaultsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private function createSupplier(\PDO $pdo): int
    {
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Test vyžaduje výchozí syntetickou firmu.');
        $id = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET work_report_display_options = NULL WHERE id = ?')->execute([$id]);
        return $id;
    }

    public static function settingsPaths(): array
    {
        return ['běžné nastavení' => [false], 'delegovaný vzhled' => [true]];
    }

    #[DataProvider('settingsPaths')]
    public function testDefaultsAreValidatedAndIsolatedPerCompany(bool $delegated): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $settings = $container->get($delegated ? ClientBrandingSettingsAction::class : SettingsAction::class);
        $updateMethod = $delegated ? 'update' : 'updateSupplier';
        $getMethod = $delegated ? 'get' : 'getSupplier';
        $pdo->beginTransaction();
        try {
            $supplier = $this->createSupplier($pdo);
            $other = $this->createSupplier($pdo);
            $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn(), 'role' => 'admin']);
            $response = $settings->$updateMethod($request->withParsedBody([
                'work_report_display_options' => ['compact' => true, 'rate' => false, 'amount' => false],
            ]), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $read = static fn ($response): array => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $actual = $read($settings->$getMethod($request, new Response()))['work_report_display_options'];
            self::assertTrue($actual['compact']);
            self::assertFalse($actual['rate']);
            self::assertFalse($actual['amount']);
            self::assertSame(WorkReportDisplayOptions::DEFAULTS,
                $read($settings->$getMethod($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $other), new Response()))['work_report_display_options']);

            $invalid = $settings->$updateMethod($request->withParsedBody(['work_report_display_options' => ['rate' => 'false']]), new Response());
            self::assertSame(400, $invalid->getStatusCode());
            self::assertSame($actual, $read($settings->$getMethod($request, new Response()))['work_report_display_options']);

            $response = $settings->$updateMethod($request->withParsedBody(['work_report_display_options' => null]), new Response());
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(WorkReportDisplayOptions::DEFAULTS,
                $read($settings->$getMethod($request, new Response()))['work_report_display_options']);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    /** BEZ OPRAVY PADÁ: uložení formuláře s výchozí podobou zapsalo firmě bez nastavení výslovnou hodnotu. */
    #[DataProvider('settingsPaths')]
    public function testSavingDefaultOptionsKeepsCompanyWithoutSetting(bool $delegated): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $settings = $container->get($delegated ? ClientBrandingSettingsAction::class : SettingsAction::class);
        $updateMethod = $delegated ? 'update' : 'updateSupplier';
        $pdo->beginTransaction();
        try {
            $supplier = $this->createSupplier($pdo);
            $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn(), 'role' => 'admin']);
            $stored = static function () use ($pdo, $supplier): mixed {
                $stmt = $pdo->prepare('SELECT work_report_display_options FROM supplier WHERE id = ?');
                $stmt->execute([$supplier]);
                return $stmt->fetchColumn();
            };
            foreach ([WorkReportDisplayOptions::DEFAULTS, ['compact' => false, 'amount' => true]] as $options) {
                $response = $settings->$updateMethod($request->withParsedBody(['work_report_display_options' => $options]), new Response());
                self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
                self::assertNull($stored());
            }
            $settings->$updateMethod($request->withParsedBody(['work_report_display_options' => ['compact' => true]]), new Response());
            self::assertSame(['compact' => true], json_decode((string) $stored(), true));
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    /**
     * BEZ OPRAVY PADÁ: výkaz vystavené faktury dědil výchozí podobu firmy živě, takže po
     * její změně by znovu vygenerované PDF vystaveného dokladu zpětně změnilo sloupce.
     */
    #[DataProvider('settingsPaths')]
    public function testChangingCompanyDefaultsDoesNotRestyleIssuedInvoices(bool $delegated): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $settings = $container->get($delegated ? ClientBrandingSettingsAction::class : SettingsAction::class);
        $updateMethod = $delegated ? 'update' : 'updateSupplier';
        $repo = $container->get(WorkReportRepository::class);
        $pdf = $container->get(InvoicePdfRenderer::class);
        $invoices = $container->get(InvoiceRepository::class);
        $pdo->beginTransaction();
        try {
            $supplier = $this->createSupplier($pdo);
            $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)")->execute([$supplier]);
            $currency = (int) $pdo->lastInsertId();
            $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
            $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
                VALUES (?, 'Syntetický odběratel', 'Ulice 1', 'Praha', '11000', ?, 'odberatel@example.test', ?)")
                ->execute([$supplier, $country, $currency]);
            $client = (int) $pdo->lastInsertId();
            $user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
            $invoice = static function (string $status, string $vs) use ($pdo, $supplier, $client, $currency, $user, $repo): int {
                $pdo->prepare("INSERT INTO invoices (supplier_id, client_id, issue_date, tax_date, due_date, currency_id, created_by, status, invoice_type, varsymbol)
                    VALUES (?, ?, '2096-03-10', '2096-03-10', '2096-03-24', ?, ?, ?, 'invoice', ?)")
                    ->execute([$supplier, $client, $currency, $user, $status, $vs]);
                $id = (int) $pdo->lastInsertId();
                $repo->save($id, $supplier, null, 'Syntetický výkaz', [
                    ['description' => 'Analýza', 'work_date' => '2096-03-02', 'hours' => 2, 'rate' => 1234],
                ]);
                return $id;
            };
            // Vystaveno před zavedením voleb: v DB je všude NULL.
            $legacyIssued = $invoice('sent', '2096000001');
            $draft = $invoice('draft', '2096000002');

            $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn(), 'role' => 'admin']);
            $update = static function (?array $options) use ($settings, $request, $updateMethod): void {
                $response = $settings->$updateMethod($request->withParsedBody(['work_report_display_options' => $options]), new Response());
                self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            };
            $effective = static fn (int $id): array => $repo->findByInvoice($id, $supplier)['effective_display_options'];

            $update(['amount' => false]);
            self::assertSame(WorkReportDisplayOptions::DEFAULTS, $effective($legacyIssued));
            self::assertFalse($effective($draft)['amount']);
            $html = $pdf->renderHtml($invoices->find($legacyIssued), includeCss: false);
            self::assertStringContainsString('2' . "\u{00A0}" . '468', $html, 'Vystavená faktura musí dál tisknout částku práce.');

            // Vystaveno za platnosti {amount:false}; další změna firmy ho nesmí přestylovat.
            $pdo->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$draft]);
            $update(['rate' => false]);
            self::assertSame(WorkReportDisplayOptions::DEFAULTS, $effective($legacyIssued));
            self::assertFalse($effective($draft)['amount']);
            self::assertTrue($effective($draft)['rate']);

            $newDraft = $invoice('draft', '2096000003');
            self::assertFalse($effective($newDraft)['rate']);
            self::assertTrue($effective($newDraft)['amount']);

            // Stejná výsledná podoba (jen jinak zapsaná) nic nepřipíná.
            $pdo->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$newDraft]);
            $update(['rate' => false, 'date' => true]);
            self::assertNull($repo->findByInvoice($newDraft, $supplier)['display_options']);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
}
