<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Service\Invoice\RecurringPriceListService;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssClientContext;
use MyInvoice\Service\Oss\OssItemDecision;
use MyInvoice\Service\Oss\OssDerivationReason;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\Stock\StockException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurringManualScheduleTest extends TestCase
{
    public static function schedules(): array
    {
        return [
            'stock failure keeps extra invoice schedule' => [false, false, '2026-09-08', '2027-02-01', null, 'active', true],
            'stock failure advances planned occurrence' => [true, false, '2026-09-08', '2028-02-01', null, 'active', true],
            'early annual invoice' => [true, false, '2026-09-08', '2028-02-01', null, 'active'],
            'early annual draft' => [true, true, '2026-09-08', '2028-02-01', null, 'active'],
            'late annual invoice' => [true, false, '2027-09-08', '2028-02-01', null, 'active'],
            'extra invoice' => [false, false, '2026-09-08', '2027-02-01', null, 'active'],
            'extra draft' => [false, true, '2026-09-08', '2027-02-01', null, 'active'],
            'last occurrence' => [true, true, '2026-09-08', '2028-02-01', '2027-12-31', 'expired'],
            'extra does not expire' => [false, true, '2027-09-08', '2027-02-01', '2027-12-31', 'active'],
        ];
    }

    #[DataProvider('schedules')]
    public function testManualGeneration(bool $advance, bool $draft, string $issueDate, string $next, ?string $end, string $status, bool $stockFailure = false): void
    {
        // Výhradně syntetická in-memory databáze; žádné připojení k aplikační DB.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $columns = 'invoice_type, client_id, project_id, supplier_id, branding_profile_id, issue_date, tax_date, due_date, currency_id, reverse_charge, prices_include_vat, language, note_above_items, note_below_items, payment_method, discount_percent, recurring_template_id, revenue_category_id, status, created_by';
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, ' . $columns . ')');
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, is_vat_payer INTEGER, default_payment_due_unit TEXT)');
        $pdo->exec('INSERT INTO supplier VALUES (7, 1, NULL)');
        $pdo->exec('CREATE TABLE supplier_vat_status_history (id INTEGER, supplier_id INTEGER, effective_from TEXT, is_vat_payer INTEGER)');
        $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, supplier_id INTEGER, payment_due_unit TEXT)');
        $pdo->exec('INSERT INTO clients VALUES (8, 7, NULL)');
        $pdo->exec('CREATE TABLE vat_rates (id INTEGER PRIMARY KEY, code TEXT, label_cs TEXT, valid_from TEXT, valid_to TEXT)');
        $pdo->exec("INSERT INTO vat_rates VALUES (1, 'TEST', 'Test', '2000-01-01', NULL)");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $items = [['description' => 'Test service', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 121, 'vat_rate_id' => 1, 'order_index' => 0]];
        $template = [
            'id' => 12, 'supplier_id' => 7, 'client_id' => 8, 'currency_id' => 1,
            'frequency' => 'annually', 'next_run_date' => '2027-02-01', 'day_of_month' => 1,
            'end_of_month' => false, 'end_date' => $end, 'status' => 'active',
            'auto_issue' => $stockFailure, 'auto_send_email' => $stockFailure, 'created_by' => 3,
            'payment_due_days' => 14, 'reverse_charge' => false, 'prices_include_vat' => true,
            'revenue_category_id' => 1, 'note_above_items' => null, 'note_below_items' => null,
            'increment_month_in_descriptions' => false, 'items' => $items,
        ];
        $repo = $this->createMock(RecurringTemplateRepository::class);
        $repo->method('find')->willReturn($template);
        if ($advance) {
            $repo->expects(self::once())->method('advanceSchedule')->with(12, $next, $issueDate, $status);
        } else {
            $repo->expects(self::never())->method('advanceSchedule');
        }
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('vatRateMap')->willReturn([1 => 21.0]);
        $invoices->expects(self::once())->method('replaceItems')->with(1, self::callback(static fn (array $actual): bool => count($actual) === 1
            && array_intersect_key($actual[0], $items[0]) == $items[0]
            && $actual[0]['stock_item_id'] === null && $actual[0]['warehouse_id'] === null));
        $priceList = $this->createStub(RecurringPriceListService::class);
        $priceList->method('resolveForGeneration')->willReturn($items);
        $oss = $this->createStub(OssItemDeriver::class);
        $oss->method('clientContext')->willReturn(new OssClientContext('CZ', true, null));
        $oss->method('derive')->willReturn(OssItemDecision::notApplicable(OssDerivationReason::ClientDomestic));
        $issueAndSend = $this->createStub(AutoIssueAndSendService::class);
        if ($stockFailure) {
            $issueAndSend->method('run')->willThrowException(new StockException('insufficient_stock', 'Test stock shortage'));
            $repo->expects(self::once())->method('setLastError');
        }
        $reflection = new \ReflectionClass(RecurringInvoiceGenerator::class);
        $args = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType()->getName();
            $args[] = match ($type) {
                OssItemDeriver::class => $oss,
                AutoIssueAndSendService::class => $issueAndSend,
                Connection::class => $db,
                RecurringTemplateRepository::class => $repo,
                InvoiceRepository::class => $invoices,
                RecurringPriceListService::class => $priceList,
                default => $this->createStub($type),
            };
        }
        $generator = $reflection->newInstanceArgs($args);
        if ($stockFailure) {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('Test stock shortage');
        }
        $result = $generator->generate(12, $issueDate, 3, '127.0.0.1', 'phpunit', $draft, $advance);
        self::assertSame($next, $result['new_next_run_date']);
        self::assertSame($status, $result['template_status']);
        $invoice = $pdo->query('SELECT * FROM invoices')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($issueDate, $invoice['issue_date']);
        self::assertSame($issueDate, $invoice['tax_date']);
        self::assertSame(1, (int) $invoice['prices_include_vat']);
        self::assertSame(12, (int) $invoice['recurring_template_id']);
    }
}
