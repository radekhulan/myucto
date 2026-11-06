<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PaymentScheduleRepository;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\InvoiceCalendar;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Splátkový (§ 31) a platební (§ 31a) kalendář: daň po obdobích podle splátek, ne jedním
 * dokladem, číslo kalendáře v kontrolním hlášení a pohledávka nesená splátkami.
 */
#[Group('integration')]
final class InvoiceCalendarFlowTest extends BankPostingTestCase
{
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $this->vatRateId = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 AND is_reverse_charge = 0 ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí sazba DPH 21 %.');
        }
        $pdo->prepare("UPDATE supplier SET stock_enabled = 0, is_vat_payer = 1 WHERE id = ?")->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'document.invoice', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = 'auto', updated_by = VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
    }

    public function testRentCalendarEntersVatByInstalmentTaxDateUnderCalendarNumber(): void
    {
        $calendar = $this->issuedCalendar(InvoiceCalendar::RENT);
        $number = $this->number($calendar);

        foreach (['-02' => 10000.0, '-03' => 10000.0, '-04' => 10000.0] as $month => $base) {
            $rows = $this->vatRows(self::YEAR . $month . '-01', self::YEAR . $month . '-31');
            self::assertCount(1, $rows, "Období {$month} nese jednu splátku.");
            self::assertEqualsWithDelta($base, (float) $rows[0]['base_czk'], 0.001);
            self::assertEqualsWithDelta(2100.0, (float) $rows[0]['vat_czk'], 0.001);
            self::assertSame($number, $rows[0]['doc_number'], 'KH hlásí číslo kalendáře.');
            self::assertSame(self::YEAR . $month . '-15', $rows[0]['tax_date'], 'DPPD je DUZP splátky.');
            self::assertEqualsWithDelta(36300.0, (float) $rows[0]['document_total'], 0.001, 'Limit KH se posuzuje podle celého kalendáře.');
        }
        self::assertSame([], $this->vatRows(self::YEAR . '-01-01', self::YEAR . '-01-31'), 'Datum vystavení kalendáře daň nezakládá.');
    }

    public function testRentInstalmentsArePostedOneByOneAndCalendarNot(): void
    {
        $calendar = $this->issuedCalendar(InvoiceCalendar::RENT);
        $pdo = $this->db->pdo();
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM journal_entries WHERE source_type = 'invoice' AND source_id = {$calendar} AND reversed_by IS NULL"
        )->fetchColumn());
        foreach ($this->instalments($calendar) as $instalment) {
            $entry = (int) $pdo->query(
                "SELECT id FROM journal_entries WHERE source_type = 'invoice' AND source_id = {$instalment['id']} AND reversed_by IS NULL"
            )->fetchColumn();
            self::assertGreaterThan(0, $entry, 'Splátka se zaúčtuje jako faktura.');
            self::assertEqualsWithDelta(12100.0, (float) ($this->linesByAccountCode($entry)['311']['debit'] ?? 0), 0.001);
            self::assertSame($instalment['tax_date'], (string) $pdo->query("SELECT entry_date FROM journal_entries WHERE id = {$entry}")->fetchColumn());
        }
    }

    public function testBankPaysOldestInstalmentAndCalendarBecomesPaidAfterLast(): void
    {
        $calendar = $this->issuedCalendar(InvoiceCalendar::RENT);
        $instalments = $this->instalments($calendar);
        $matcher = $this->container->get(StatementMatcher::class);
        $vs = (string) $this->db->pdo()->query("SELECT payment_variable_symbol FROM invoices WHERE id = {$instalments[0]['id']}")->fetchColumn();

        $tx = $this->transaction($this->statement(), 12100.00, ['variable_symbol' => $vs]);
        $match = $matcher->matchBatch([$tx])[$tx] ?? [];
        self::assertContains($match['status'] ?? null, ['auto_exact', 'auto_partial'], json_encode($match));
        self::assertSame('paid', $this->docStatus($instalments[0]['id']), 'Trvalý příkaz hradí nejstarší splátku.');
        self::assertSame('issued', $this->docStatus($instalments[1]['id']));
        self::assertSame('issued', $this->docStatus($calendar));

        $payments = $this->container->get(InvoicePaymentService::class);
        $payments->recordPayment($instalments[1]['id'], 12100.00, self::YEAR . '-03-20');
        self::assertSame('issued', $this->docStatus($calendar), 'Zbývá poslední splátka.');
        $payments->recordPayment($instalments[2]['id'], 12100.00, self::YEAR . '-04-20');
        self::assertSame('paid', $this->docStatus($calendar));
        self::assertEqualsWithDelta(36300.0, (float) $this->db->pdo()->query("SELECT paid_total FROM invoices WHERE id = {$calendar}")->fetchColumn(), 0.001);
    }

    public function testAdvanceCalendarHasNoVatUntilPaymentAndThenOnPaymentDay(): void
    {
        $calendar = $this->issuedCalendar(InvoiceCalendar::ADVANCE);
        $number = $this->number($calendar);
        $instalments = $this->instalments($calendar);
        self::assertSame(['proforma', 'proforma', 'proforma'], array_column($instalments, 'invoice_type'));
        self::assertSame([], $this->vatRows(self::YEAR . '-01-01', self::YEAR . '-12-31'), 'Nezaplacená záloha daň nezakládá.');

        // Platba po splatnosti: daň patří do období přijetí, ne splatnosti.
        $this->container->get(InvoicePaymentService::class)->recordPayment($instalments[0]['id'], 12100.00, self::YEAR . '-05-07');

        self::assertSame([], $this->vatRows(self::YEAR . '-02-01', self::YEAR . '-02-28'));
        $rows = $this->vatRows(self::YEAR . '-05-01', self::YEAR . '-05-31');
        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(10000.0, (float) $rows[0]['base_czk'], 0.001);
        self::assertEqualsWithDelta(2100.0, (float) $rows[0]['vat_czk'], 0.001);
        self::assertSame(self::YEAR . '-05-07', $rows[0]['tax_date']);
        self::assertSame($number, $rows[0]['doc_number']);
        self::assertSame('tax_document', $rows[0]['document_kind']);

        // Daňový doklad se zaúčtuje hned, bez čekání na vystavení.
        $tax = (int) $rows[0]['invoice_id'];
        $entry = (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE source_type = 'invoice' AND source_id = {$tax} AND reversed_by IS NULL"
        )->fetchColumn();
        self::assertGreaterThan(0, $entry, 'Daňový doklad se má zaúčtovat automaticky.');
        self::assertEqualsWithDelta(2100.0, (float) ($this->linesByAccountCode($entry)['343.200']['credit'] ?? 0), 0.001);
    }

    public function testControlStatementUsesCalendarTotalAndRepeatsNumberPerInstalment(): void
    {
        // Každá splátka pod 10 000 Kč, kalendář nad: FS posuzuje limit podle součtu dokladu.
        $id = $this->draftCalendar(InvoiceCalendar::RENT, 4000.0, 840.0);
        $client = (int) $this->db->pdo()->query("SELECT client_id FROM invoices WHERE id = {$id}")->fetchColumn();
        $this->db->pdo()->prepare("UPDATE clients SET dic = 'CZ12345679' WHERE id = ?")->execute([$client]);
        self::assertLessThan(300, $this->issue($id)['status']);

        $kh = $this->container->get(KontrolniHlaseniBuilder::class)->build($this->supplierId, self::YEAR, 2);
        $xml = new \SimpleXMLElement((string) $kh['xml']);
        $dates = [];
        foreach ($xml->xpath('//*[local-name()="VetaA4"]') as $line) {
            if ((string) $line['c_evid_dd'] === $this->number($id)) {
                $dates[] = (string) $line['dppd'];
            }
        }

        self::assertSame(['15.02.' . self::YEAR], $dates, 'Splátka pod limitem patří do A.4, protože limit překračuje kalendář.');
    }

    public function testAdvanceInstalmentNeverCreatesFinalDraftEvenInFinalMode(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET proforma_payment_document = 'final_on_full_payment' WHERE id = ?")->execute([$this->supplierId]);
        $calendar = $this->issuedCalendar(InvoiceCalendar::ADVANCE);
        $instalment = $this->instalments($calendar)[0]['id'];

        $this->container->get(InvoicePaymentService::class)->recordPayment($instalment, 12100.00, self::YEAR . '-02-15');

        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoices WHERE parent_invoice_id = {$instalment} AND invoice_type = 'invoice'"
        )->fetchColumn(), 'Vyúčtovací faktura ze splátky by plnění uskutečnila předčasně.');
        self::assertCount(1, $this->vatRows(self::YEAR . '-02-01', self::YEAR . '-02-28'));
    }

    public function testCalendarIsNotReceivableItself(): void
    {
        $calendar = $this->issuedCalendar(InvoiceCalendar::RENT);
        $pdo = $this->db->pdo();
        $sql = \MyInvoice\Support\Sql\ReceivablePredicate::overdueOpen('i', '<', "'2200-01-01'");
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i WHERE i.id = ? AND {$sql}");
        $stmt->execute([$calendar]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testIssueRejectsRentCalendarWithoutContractAndLegacyType(): void
    {
        $draft = $this->draftCalendar(InvoiceCalendar::RENT);
        $this->db->pdo()->exec("UPDATE invoices SET calendar_contract_ref = NULL WHERE id = {$draft}");
        $res = $this->issue($draft);
        self::assertSame(422, $res['status']);
        self::assertSame('calendar_contract_required', $res['body']['error']['code'] ?? $res['body']['code'] ?? null);
        self::assertSame('draft', $this->docStatus($draft));

        $legacy = $this->draftCalendar(InvoiceCalendar::LEGACY);
        $res = $this->issue($legacy);
        self::assertSame(422, $res['status']);
        self::assertSame('calendar_type_required', $res['body']['error']['code'] ?? $res['body']['code'] ?? null);
    }

    public function testIssueRejectsScheduleNotMatchingVatOfDocument(): void
    {
        $draft = $this->draftCalendar(InvoiceCalendar::RENT);
        $this->db->pdo()->exec("UPDATE invoice_payment_schedule SET vat_amount = vat_amount + 1, base_amount = base_amount - 1 WHERE invoice_id = {$draft}");
        $res = $this->issue($draft);
        self::assertSame(422, $res['status']);
        self::assertSame('payment_schedule_mismatch', $res['body']['error']['code'] ?? $res['body']['code'] ?? null);
    }

    public function testCancelCalendarCancelsInstalmentsAndRefusesWhenPaid(): void
    {
        $calendar = $this->issuedCalendar(InvoiceCalendar::RENT);
        $instalments = $this->instalments($calendar);
        $this->container->get(InvoicePaymentService::class)->recordPayment($instalments[0]['id'], 12100.00, self::YEAR . '-02-15');
        $res = $this->cancel($calendar);
        self::assertSame(409, $res['status']);
        self::assertSame('issued', $this->docStatus($calendar));

        $other = $this->issuedCalendar(InvoiceCalendar::RENT);
        $res = $this->cancel($other);
        self::assertLessThan(300, $res['status'], json_encode($res['body']));
        self::assertSame('cancelled', $this->docStatus($other));
        foreach ($this->instalments($other) as $instalment) {
            self::assertSame('cancelled', $instalment['status']);
        }
    }

    public function testPdfOfRentCalendarHasSection31AndTaxDatePerInstalmentButNoHeaderDate(): void
    {
        $html = $this->pdfHtml($this->issuedCalendar(InvoiceCalendar::RENT));

        self::assertStringContainsString('Splátkový kalendář', $html);
        self::assertStringContainsString('§ 31 zákona o DPH', $html);
        self::assertStringNotContainsString('§ 31a', $html);
        self::assertStringContainsString('Nájemní smlouva 1/2099', $html);
        self::assertStringContainsString('dílčím plněním', $html);
        self::assertSame(1, substr_count($html, '>DUZP<'), 'DUZP je jen v záhlaví sloupce rozpisu.');
    }

    public function testPdfOfAdvanceCalendarHasSection31aAndNoTaxDate(): void
    {
        $html = $this->pdfHtml($this->issuedCalendar(InvoiceCalendar::ADVANCE));

        self::assertStringContainsString('Platební kalendář', $html);
        self::assertStringContainsString('§ 31a zákona o DPH', $html);
        self::assertStringNotContainsString('DUZP', $html);
        self::assertStringContainsString('Nezaplacená záloha povinnost přiznat daň nezakládá', $html);
        self::assertStringNotContainsString('ke dni její splatnosti', $html);
    }

    public function testInternalCancelOfInstalmentIsRefused(): void
    {
        $calendar = $this->issuedCalendar(InvoiceCalendar::RENT);
        $res = $this->cancel($this->instalments($calendar)[0]['id']);
        self::assertSame(409, $res['status']);
        self::assertSame('issued', $this->docStatus($this->instalments($calendar)[0]['id']));
    }

    private function pdfHtml(int $id): string
    {
        $invoice = $this->container->get(InvoiceRepository::class)->find($id);

        return $this->container->get(InvoicePdfRenderer::class)->renderHtml($invoice, includeCss: false);
    }

    private function draftCalendar(string $type, float $base = 10000.0, float $vat = 2100.0): int
    {
        $pdo = $this->db->pdo();
        $gross = $base + $vat;
        $client = $this->client('Nájemce ' . bin2hex(random_bytes(3)));
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, reverse_charge,
                 prices_include_vat, total_without_vat, total_vat, total_with_vat, paid_total, status, calendar_contract_ref, created_by)
             VALUES (?, ?, ?, ?, NULL, ?, ?, 0, 0, ?, ?, ?, 0, "draft", "Nájemní smlouva 1/2099", ?)'
        )->execute([$this->supplierId, $type, $client, self::YEAR . '-01-20', self::YEAR . '-02-15', $this->currencyId, 3 * $base, 3 * $vat, 3 * $gross, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Nájem', 3, 'měs', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, 3 * $base, 3 * $vat, 3 * $gross]);
        $rows = [];
        foreach (['02', '03', '04'] as $month) {
            $date = self::YEAR . "-{$month}-15";
            $rows[] = ['due_on' => $date, 'tax_date' => $type === InvoiceCalendar::RENT ? $date : null, 'base_amount' => $base, 'vat_amount' => $vat, 'total_amount' => $gross];
        }
        $this->container->get(PaymentScheduleRepository::class)->replaceForInvoice($this->supplierId, $id, $rows);

        return $id;
    }

    private function issuedCalendar(string $type): int
    {
        $id = $this->draftCalendar($type);
        $res = $this->issue($id);
        self::assertLessThan(300, $res['status'], json_encode($res['body']));

        return $id;
    }

    /** @return array{status:int, body:mixed} */
    private function issue(int $id): array
    {
        return $this->callAction($this->container->get(IssueInvoiceAction::class), '__invoke', 'POST', 'admin', [], ['id' => (string) $id]);
    }

    /** @return array{status:int, body:mixed} */
    private function cancel(int $id): array
    {
        return $this->callAction($this->container->get(CancelInvoiceAction::class), '__invoke', 'POST', 'admin', ['mode' => 'internal', 'reason' => 'test'], ['id' => (string) $id]);
    }

    /** @return list<array{id:int, status:string, invoice_type:string, tax_date:?string}> */
    private function instalments(int $calendar): array
    {
        $rows = $this->db->pdo()->query(
            "SELECT id, status, invoice_type, tax_date FROM invoices
              WHERE calendar_id = {$calendar} AND invoice_type IN ('invoice', 'proforma') ORDER BY due_date, id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(3, $rows);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id']] + $r, $rows);
    }

    private function docStatus(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$id}")->fetchColumn();
    }

    private function number(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT varsymbol FROM invoices WHERE id = {$id}")->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function vatRows(string $start, string $end): array
    {
        return array_values(array_filter(
            $this->container->get(VatLedgerService::class)->rows($this->supplierId, $start, $end),
            static fn (array $r): bool => $r['source'] === 'sale',
        ));
    }
}
