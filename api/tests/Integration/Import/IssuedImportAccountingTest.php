<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class IssuedImportAccountingTest extends StockTestCase
{
    use IsolatedSupplierTrait;

    private const SUPPLIER_IC = '00000001';
    private const CUSTOMER_IC = '00000002';
    private const CUSTOMER = 'Syntetický odběratel importu';

    protected function tearDown(): void
    {
        foreach ($this->supplierIds as $supplierId) {
            foreach (['invoice_counters', 'tax_submissions'] as $table) {
                $this->db->pdo()->prepare("DELETE FROM {$table} WHERE supplier_id = ?")->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    public function testImportPostsOverdueInvoiceInAccountingPeriod(): void
    {
        $supplierId = $this->company();
        $report = $this->import($supplierId, ['240001' => ['2024-04-10', '2024-04-09']]);
        $id = (int) $report['results'][0]['invoice_id'];

        $entry = $this->journal($supplierId, $id);
        self::assertNotNull($entry);
        self::assertSame('2024-04-09', $entry['entry_date']);
        self::assertNotNull($entry['posted_at']);
        self::assertSame('sent', $report['results'][0]['imported_status']);
        $invoice = $this->invoice($id);
        self::assertSame('sent', $invoice['status']);
        self::assertNull($invoice['paid_at']);
        self::assertSame('0.00', $invoice['paid_total']);
        self::assertNotNull($invoice['booked_at']);
        self::assertSame($report['batch_id'], $invoice['import_batch_id']);

        $lines = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.entry_id = ?',
        );
        $lines->execute([$entry['id']]);
        $amounts = [];
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $key = substr($line['account_code'], 0, 3) . ':' . $line['side'];
            $amounts[$key] = ($amounts[$key] ?? 0.0) + (float) $line['amount'];
        }
        self::assertSame(1210.0, $amounts['311:debit']);
        self::assertSame(1000.0, $amounts['602:credit']);
        self::assertSame(210.0, $amounts['343:credit']);
    }

    public function testImportKeepsPreAccountingDocumentUnpostedEvenWithPeriod(): void
    {
        $supplierId = $this->company();
        $this->container->get(AccountingPeriodRepository::class)->create($supplierId, 2023, '2023-01-01', '2023-12-31');
        $report = $this->import($supplierId, ['240002' => ['2024-01-02', '2023-12-31']]);
        $id = (int) $report['results'][0]['invoice_id'];

        self::assertNull($this->journal($supplierId, $id));
        self::assertSame('paid', $this->invoice($id)['status']);
        self::assertStringStartsWith('2023-12-31', $this->invoice($id)['paid_at']);
    }

    public static function modesWithoutAccounting(): array
    {
        return ['daňová evidence' => ['tax_evidence', true], 'jen fakturace' => ['double_entry', false]];
    }

    #[DataProvider('modesWithoutAccounting')]
    public function testImportWithoutActiveDoubleEntryKeepsAgeHeuristic(string $mode, bool $enabled): void
    {
        $supplierId = $this->company($mode, $enabled);
        $report = $this->import($supplierId, ['240003' => ['2024-04-10', '2024-04-10']]);
        $id = (int) $report['results'][0]['invoice_id'];

        self::assertNull($this->journal($supplierId, $id));
        self::assertSame('paid', $this->invoice($id)['status']);
    }

    public function testDisabledAutoPostingStillLeavesInvoiceUnpaid(): void
    {
        $supplierId = $this->company(auto: false);
        $report = $this->import($supplierId, ['240004' => ['2024-04-10', '2024-04-10']]);
        $id = (int) $report['results'][0]['invoice_id'];

        self::assertSame('sent', $this->invoice($id)['status']);
        self::assertNull($this->journal($supplierId, $id));
    }

    public function testImportReportsPostingFailureAndKeepsInvoice(): void
    {
        $supplierId = $this->company();
        $this->container->get(AccountingSupplierSettingsRepository::class)->setLockedUntil($supplierId, '2024-02-29');
        $report = $this->import($supplierId, ['240005' => ['2024-02-10', '2024-02-09']]);
        $id = (int) $report['results'][0]['invoice_id'];

        self::assertSame(1, $report['summary']['with_warnings']);
        self::assertStringContainsString('uzamčené k 2024-02-29', implode(' ', $report['results'][0]['warnings']));
        self::assertSame('sent', $this->invoice($id)['status']);
        self::assertNull($this->journal($supplierId, $id));
    }

    public function testCliDryRunAndCommitSkipDateLockAndFiledVat(): void
    {
        $supplierId = $this->company(auto: false);
        $report = $this->import($supplierId, [
            '240006' => ['2024-04-10', '2024-04-10'],
            '240007' => ['2024-02-10', '2024-02-10'],
            '240008' => ['2024-03-10', '2024-03-10'],
        ]);
        [$ready, $dateLocked, $filed] = array_map('intval', array_column($report['results'], 'invoice_id'));
        $other = $this->import($supplierId, ['240009' => ['2024-04-11', '2024-04-11']]);
        $otherId = (int) $other['results'][0]['invoice_id'];
        $this->markLegacyPaid($ready);
        $this->markLegacyPaid($dateLocked);
        $this->container->get(AccountingSupplierSettingsRepository::class)->setLockedUntil($supplierId, '2024-02-29');
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, status, xml_content, xml_sha256, xml_size_bytes)
             VALUES (?, 'DPHDP3', 2024, 3, 'submitted', '<x/>', ?, 4)",
        )->execute([$supplierId, hash('sha256', '<x/>')]);
        $args = ["--supplier={$supplierId}", '--batch=' . $report['batch_id']];

        $out = $this->cli([...$args, '--reset-paid']);
        self::assertStringContainsString('[NANEČISTO]', $out);
        self::assertStringContainsString('Celkem dokladů: 3', $out);
        self::assertStringContainsString('Součet CZK: základ 3 000,00, DPH 630,00', $out);
        self::assertStringContainsString('Zaplaceno bez úhrady: 2', $out);
        self::assertStringContainsString('Doklady v zamčených nebo podaných obdobích', $out);
        self::assertStringContainsString('Zámek účtování k 2024-02-29', $out);
        self::assertStringContainsString('přiznání k DPH za 3/2024', $out);
        self::assertStringNotContainsString('240009', $out);
        self::assertNull($this->journal($supplierId, $ready));
        self::assertSame('paid', $this->invoice($ready)['status']);

        $out = $this->cli([...$args, '--commit']);
        self::assertStringContainsString('Zaúčtováno: 1', $out);
        self::assertNotNull($this->journal($supplierId, $ready));
        self::assertSame('paid', $this->invoice($ready)['status']);
        self::assertNull($this->journal($supplierId, $dateLocked));
        self::assertSame('paid', $this->invoice($dateLocked)['status']);
        self::assertNull($this->journal($supplierId, $filed));
        self::assertNull($this->journal($supplierId, $otherId));
        $retry = $this->cli([...$args, '--commit', '--reset-paid']);
        self::assertStringContainsString('Zaúčtováno: 0', $retry);
        self::assertStringContainsString('Opravený stav úhrady: 0', $retry);
        self::assertSame('paid', $this->invoice($dateLocked)['status']);
    }

    public function testCliResetPaidPreservesRealPayments(): void
    {
        $supplierId = $this->company(auto: false);
        $report = $this->import($supplierId, [
            '240010' => ['2024-04-10', '2024-04-10'],
            '240011' => ['2024-04-11', '2024-04-11'],
            '240012' => ['2024-04-12', '2024-04-12'],
        ]);
        [$sent, $issued, $paid] = array_map('intval', array_column($report['results'], 'invoice_id'));
        foreach ([$sent, $issued, $paid] as $id) {
            $this->markLegacyPaid($id);
        }
        $this->db->pdo()->prepare('UPDATE invoices SET sent_at = NULL WHERE id = ?')->execute([$issued]);
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, '2024-04-20', 1210, 'CZK', 'manual')",
        )->execute([$supplierId, $paid]);
        $this->db->pdo()->prepare('UPDATE invoices SET paid_total = 1210 WHERE id = ?')->execute([$paid]);

        $out = $this->cli(["--supplier={$supplierId}", '--commit', '--reset-paid']);
        self::assertStringContainsString('Opravený stav úhrady: 2', $out);
        self::assertSame('sent', $this->invoice($sent)['status']);
        self::assertSame('issued', $this->invoice($issued)['status']);
        self::assertNull($this->invoice($issued)['paid_at']);
        self::assertSame('paid', $this->invoice($paid)['status']);
        self::assertSame('1210.00', $this->invoice($paid)['paid_total']);
        self::assertNotNull($this->journal($supplierId, $sent));
    }

    private function company(string $mode = 'double_entry', bool $enabled = true, bool $auto = true): int
    {
        $id = $this->createSupplier($mode, false, false);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET ic = ?, accounting_enabled = ?, auto_post_invoices = ?, accounting_starts_on = '2024-01-01' WHERE id = ?")
            ->execute([self::SUPPLIER_IC, (int) $enabled, (int) $auto, $id]);
        $this->container->get(AccountingModeRepository::class)->record($id, '2024-01-01', $mode);
        $this->setVatPayerAt($pdo, $id, '2023-01-01', true);
        $clientId = $this->client($id, self::CUSTOMER);
        $pdo->prepare('UPDATE clients SET ic = ? WHERE id = ?')->execute([self::CUSTOMER_IC, $clientId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($id, '2024-01-01');
        $this->container->get(AccountingPeriodRepository::class)->create($id, 2024, '2024-01-01', '2024-12-31');
        return $id;
    }

    private function import(int $supplierId, array $datesByNumber): array
    {
        $files = [];
        foreach ($datesByNumber as $number => [$issueDate, $taxDate]) {
            $files[] = ['name' => $number . '.xml', 'content' => $this->pohodaInvoice((string) $number, $issueDate, $taxDate)];
        }
        $report = $this->container->get(InvoiceImportService::class)->importBundle($files, $supplierId, $this->userId, 'issued');
        self::assertSame(count($files), $report['summary']['created'], json_encode($report['results'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $report['summary']['failed']);
        return $report;
    }

    private function invoice(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_at, paid_total, booked_at, import_batch_id FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function journal(int $supplierId, int $id): ?array
    {
        return $this->container->get(JournalEntryRepository::class)->findBySource($supplierId, 'invoice', $id);
    }

    private function markLegacyPaid(int $id): void
    {
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_at = tax_date WHERE id = ?")->execute([$id]);
    }

    private function cli(array $args): string
    {
        $script = dirname(__DIR__, 3) . '/bin/repair-imported-issued-unposted.php';
        $env = getenv();
        $env['MYINVOICE_DB_NAME'] = (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $env['MYINVOICE_DB_NAME']);
        $process = proc_open([PHP_BINARY, $script, ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $out);
        return $out;
    }

    private function pohodaInvoice(string $number, string $issueDate, string $taxDate): string
    {
        $supplier = self::SUPPLIER_IC;
        $customer = self::CUSTOMER;
        $customerIc = self::CUSTOMER_IC;
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" version="2.0" ico="{$supplier}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>issuedInvoice</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$number}</inv:symVar>
                    <inv:date>{$issueDate}</inv:date><inv:dateTax>{$taxDate}</inv:dateTax><inv:dateDue>{$issueDate}</inv:dateDue>
                    <inv:partnerIdentity><typ:address><typ:company>{$customer}</typ:company><typ:ico>{$customerIc}</typ:ico>
                      <typ:street>Testovací 1</typ:street><typ:city>Praha</typ:city><typ:zip>11000</typ:zip>
                      <typ:country><typ:ids>CZ</typ:ids></typ:country>
                    </typ:address></inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail><inv:invoiceItem>
                    <inv:text>Konzultace</inv:text><inv:quantity>1</inv:quantity><inv:unit>ks</inv:unit><inv:rateVAT>high</inv:rateVAT>
                    <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                  </inv:invoiceItem></inv:invoiceDetail>
                  <inv:invoiceSummary><inv:homeCurrency>
                    <typ:priceHigh>1000</typ:priceHigh><typ:priceHighVAT rate="21">210</typ:priceHighVAT>
                  </inv:homeCurrency></inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }
}
