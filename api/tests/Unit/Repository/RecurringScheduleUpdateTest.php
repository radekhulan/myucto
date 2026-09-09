<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RecurringTemplateRepository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurringScheduleUpdateTest extends TestCase
{
    public static function edits(): array
    {
        return [
            'running template keeps manually chosen day' => ['2089-09-01', '2090-02-01', 1, '2090-02-15'],
            'unused template keeps manual date' => [null, '2090-02-01', 1, '2090-02-15'],
            'unused template changes anchor' => [null, '2090-04-01', 1, '2090-04-01'],
            'explicit day rule change' => ['2089-09-01', '2090-02-01', 20, '2090-02-20'],
        ];
    }

    #[DataProvider('edits')]
    public function testOrdinaryEditPreservesRescheduledDate(?string $lastRun, string $anchor, int $day, string $expected): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $columns = 'supplier_id, client_id, project_id, branding_profile_id, name, frequency, day_of_month, end_of_month, anchor_date, end_date, next_run_date, last_run_date, invoice_type, currency_id, language, payment_method, reverse_charge, prices_include_vat, discount_percent, revenue_category_id, payment_due_days, payment_due_unit, tax_date_mode, draft_open_mode, reminder_days_before, note_above_items, note_below_items, increment_month_in_descriptions, auto_issue, auto_send_email';
        $pdo->exec('CREATE TABLE recurring_invoice_templates (id INTEGER PRIMARY KEY, ' . $columns . ')');
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, branding_profiles_enabled, default_branding_profile_id)');
        $pdo->exec('INSERT INTO supplier VALUES (7, 0, NULL)');
        $pdo->prepare("INSERT INTO recurring_invoice_templates
            (id, supplier_id, anchor_date, next_run_date, last_run_date, day_of_month, end_of_month)
            VALUES (12, 7, '2090-02-01', '2090-02-15', ?, 1, 0)")->execute([$lastRun]);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        (new RecurringTemplateRepository($db))->update(12, [
            'client_id' => 8, 'name' => 'Test template', 'frequency' => 'annually',
            'day_of_month' => $day, 'end_of_month' => false, 'anchor_date' => $anchor, 'currency_id' => 1,
        ]);
        $row = $pdo->query('SELECT next_run_date, last_run_date FROM recurring_invoice_templates')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($expected, $row['next_run_date']);
        self::assertSame($lastRun, $row['last_run_date']);
    }
}
