<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{InvoiceRepository, RecurringTemplateRepository, SupplierPaymentQrSettingsRepository};
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use MyInvoice\Service\Mail\{InvoiceEmailVarsBuilder, Mailer, PaymentThanksMailer, RecipientResolver};
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Druhá firma se musí zpracovat i při vypnuté automatizaci první firmy. */
final class BusinessCronSupplierScopeTest extends TestCase
{
    public function testRecurringReminderSelectionUsesEachSuppliersOwnSwitch(): void
    {
        [$pdo, $db] = $this->database();
        $pdo->createFunction('CURDATE', static fn (): string => '2026-10-21');
        $pdo->createFunction('DATEDIFF', static fn (string $a, string $b): int =>
            (int) (new \DateTimeImmutable($b))->diff(new \DateTimeImmutable($a))->format('%r%a'));
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, auto_generate_recurring INTEGER)');
        $pdo->exec('INSERT INTO supplier VALUES (1,0),(2,1)');
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, supplier_id INTEGER, company_name TEXT, main_email TEXT, language TEXT)');
        $pdo->exec("INSERT INTO clients VALUES (11,1,'Synthetic A','a@example.test','cs'),(22,2,'Synthetic B','b@example.test','cs')");
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, client_id INTEGER, name TEXT)');
        $pdo->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, code TEXT, symbol TEXT)');
        $pdo->exec("INSERT INTO currencies VALUES (1,'CZK','Kč')");
        $pdo->exec('CREATE TABLE revenue_categories (id INTEGER PRIMARY KEY, supplier_id INTEGER, label TEXT, code TEXT)');
        $pdo->exec('CREATE TABLE recurring_invoice_templates (id INTEGER PRIMARY KEY, supplier_id INTEGER, client_id INTEGER, project_id INTEGER, currency_id INTEGER, revenue_category_id INTEGER, status TEXT, draft_open_mode TEXT, reminder_days_before INTEGER, next_run_date TEXT, last_reminder_date TEXT)');
        $pdo->exec("INSERT INTO recurring_invoice_templates VALUES
            (101,1,11,NULL,1,NULL,'active','period_start',3,'2026-10-23',NULL),
            (202,2,22,NULL,1,NULL,'active','period_start',3,'2026-10-23',NULL),
            (203,2,22,NULL,1,NULL,'active','period_start',0,'2026-10-23',NULL),
            (204,2,22,NULL,1,NULL,'active','period_start',3,'2026-10-23','2026-10-23')");
        $pdo->exec('CREATE TABLE recurring_invoice_template_items (id INTEGER PRIMARY KEY, template_id INTEGER, description TEXT, quantity REAL, duration_minutes INTEGER, unit TEXT, unit_price_without_vat REAL, vat_rate_id INTEGER, vat_classification_code TEXT, order_index INTEGER, stock_item_id INTEGER, warehouse_id INTEGER, revenue_account_code TEXT)');
        $pdo->exec('CREATE TABLE vat_rates (id INTEGER PRIMARY KEY, code TEXT, rate_percent REAL)');

        $repo = new RecurringTemplateRepository($db);
        $due = $repo->findReminderDue();
        self::assertSame([202], array_column($due, 'id'));
        self::assertSame([2], array_column($due, 'supplier_id'));

        // Přepnutí první firmy nesmí měnit připravenost druhé.
        $pdo->exec('UPDATE supplier SET auto_generate_recurring=1 WHERE id=1');
        self::assertSame([101, 202], array_column($repo->findReminderDue(), 'id'));
    }

    public function testDeferredPaymentThanksUsesInvoiceSupplierWhenFirstSupplierIsDisabled(): void
    {
        [$pdo, $db] = $this->database();
        // SQLite poskytuje stejné skalární funkce; SQL produkční fronty se nemění.
        $pdo->createFunction('NOW', static fn (...$args): string => '2026-10-21 10:00:00');
        $pdo->createFunction('DATABASE', static fn (): string => 'synthetic_test');
        $pdo->createFunction('GET_LOCK', static fn (string $name, int $timeout): int => 1);
        $pdo->createFunction('RELEASE_LOCK', static fn (string $name): int => 1);
        $pdo->createFunction('IF', static fn ($condition, $yes, $no) => $condition ? $yes : $no);
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, payment_thanks_enabled INTEGER, payment_thanks_auto_send INTEGER, payment_thanks_attach_paid_pdf INTEGER, payment_thanks_max_age_days INTEGER, branding_profiles_enabled INTEGER, email_branding_enabled INTEGER, email_accent_color TEXT, logo_path TEXT)');
        $pdo->exec("INSERT INTO supplier VALUES (1,0,0,0,0,0,0,'#000000',NULL),(2,1,1,0,0,0,0,'#223344',NULL)");
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, payment_thanks_sent_at TEXT, payment_thanks_sent_to TEXT, payment_thanks_suppressed_at TEXT)');
        $pdo->exec('INSERT INTO invoices (id,supplier_id) VALUES (101,1),(202,2)');
        $pdo->exec('CREATE TABLE mail_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, not_before TEXT, status TEXT, attempts INTEGER, template TEXT, locale TEXT, recipients INTEGER, defer_reason TEXT, payload TEXT, sent_at TEXT, last_error TEXT)');
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, email_subject_format TEXT, email_attachment_name_format TEXT)');
        $pdo->exec('CREATE TABLE client_email_contacts (client_id INTEGER, is_active INTEGER, email TEXT, label TEXT, usages TEXT, sort_order INTEGER, id INTEGER)');
        $repo = $this->createStub(InvoiceRepository::class);
        $repo->method('find')->willReturnCallback(static fn (int $id): array => [
            'id' => $id, 'supplier_id' => $id === 101 ? 1 : 2, 'client_id' => $id,
            'status' => 'paid', 'invoice_type' => 'invoice', 'varsymbol' => (string) $id,
            'paid_at' => '2026-10-21', 'amount_to_pay' => 100, 'currency' => 'CZK',
            'client_main_email' => "recipient-{$id}@example.test", 'language' => 'cs',
            'supplier_snapshot' => ['company_name' => "Synthetic {$id}", 'display_name' => "Synthetic {$id}"],
        ]);
        $config = new Config([]);
        $vars = new InvoiceEmailVarsBuilder($db, new QrPaymentGenerator($config, new NullLogger()),
            new InvoicePublicLinkService($config, $repo), new SupplierPaymentQrSettingsRepository($db));
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::once())->method('sendTemplateDetailed')->willReturnCallback(
            static function (...$args): array {
                self::assertSame('invoice_payment_thanks', $args[0]);
                self::assertSame(['recipient-202@example.test'], $args[2]);
                self::assertSame(2, $args[3]['supplier']['id']);
                self::assertSame(2, $args[10]);
                return ['smtp_response' => 'Synthetic acceptance', 'deferred' => false];
            },
        );
        $renderer = $this->createMock(InvoicePdfRenderer::class);
        $renderer->expects(self::never())->method('render');
        $service = new PaymentThanksMailer($db, $repo, $mailer, $vars, $renderer,
            $this->createStub(ActivityLogger::class), new RecipientResolver($db, $config));
        $pdo->beginTransaction();
        self::assertSame('queued', $service->sendForInvoice(101, 'bank_match', requireUnsent: true)['status']);
        self::assertSame('queued', $service->sendForInvoice(202, 'bank_match', requireUnsent: true)['status']);
        $pdo->commit();

        self::assertSame(['sent' => 1, 'skipped' => 1, 'failed' => 0, 'queued' => 0], $service->flushDeferred());
        self::assertSame(['requeued', 'sent'], $pdo->query('SELECT status FROM mail_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertNull($pdo->query('SELECT payment_thanks_sent_at FROM invoices WHERE id=101')->fetchColumn());
        self::assertSame('recipient-202@example.test', $pdo->query('SELECT payment_thanks_sent_to FROM invoices WHERE id=202')->fetchColumn());
        self::assertSame(0, $service->flushDeferred()['sent']);
    }

    /** @return array{\Pdo\Sqlite,Connection} */
    private function database(): array
    {
        $pdo = new \Pdo\Sqlite('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $db->method('hasColumn')->willReturn(false);
        return [$pdo, $db];
    }
}
