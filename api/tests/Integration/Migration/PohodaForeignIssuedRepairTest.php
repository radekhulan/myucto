<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Repository\CashJournalRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Migration\Check\Checks\ForeignIssuedInCzkCheck;
use MyInvoice\Service\Migration\Check\MigrationDataCheckRegistry;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignIssuedRepair;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPayments;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\TaxEvidence\CashJournalService;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class PohodaForeignIssuedRepairTest extends TestCase
{
    use OwnedSandboxDatabase;

    private Connection $db;
    private ContainerInterface $container;
    private string $tmp = '';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $container->set(Config::class, self::sandboxConfig('pohoda_fx_issued_repair'));
        $container->set(Connection::class, $this->db = self::sandboxConnection('pohoda_fx_issued_repair'));
        $this->container = $container;
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_issued_fx_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tmp);
        }
    }

    public function testIssuedRepairKeepsCrownAmountsAndSupportsDryRun(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();

        $ledger = $this->container->get(VatLedgerService::class);
        $dph = $this->container->get(DphPriznaniBuilder::class);
        $kh = $this->container->get(KontrolniHlaseniBuilder::class);
        $dphBefore = $dph->build($supplierId, SyntheticPohodaExport::YEAR, 2, 'monthly')['summary']['lines'];
        $khBefore = $kh->sectionDocuments($supplierId, SyntheticPohodaExport::YEAR, 2)['sections'];
        $journalBefore = $this->journal($supplierId, $id);
        self::assertNotEmpty($journalBefore);

        $registry = $this->container->get(MigrationDataCheckRegistry::class);
        $check = $registry->get(ForeignIssuedInCzkCheck::KEY);
        self::assertInstanceOf(ForeignIssuedInCzkCheck::class, $check);
        $found = $check->detect($supplierId);
        self::assertSame([$id], array_column($found->items, 'entityId'));
        self::assertSame('needs_export', $found->items[0]->details['outcome']);
        self::assertNotEmpty($found->notes);

        $repair = $this->container->get(PohodaForeignIssuedRepair::class);
        self::assertContains($supplierId, $repair->affectedSuppliers());
        $oldInvoice = $this->invoice($id);
        $oldItem = $this->item($id);
        $dryRun = $repair->run($supplierId, $export, false);
        self::assertSame(['convert'], array_column($dryRun, 'status'), json_encode($dryRun, JSON_UNESCAPED_UNICODE));
        self::assertSame([398.09], array_column($dryRun, 'total_foreign'));
        self::assertSame($oldInvoice, $this->invoice($id));
        self::assertSame($oldItem, $this->item($id));
        self::assertSame(0, $this->auditCount($supplierId, $id));

        $committed = $repair->run($supplierId, $export, true);
        self::assertSame(['converted'], array_column($committed, 'status'), json_encode($committed, JSON_UNESCAPED_UNICODE));
        $invoice = $this->invoice($id);
        self::assertSame(['EUR', '25.120000', '398.09', '10000.00', '10000.00'], array_slice(array_values($invoice), 0, 5));
        self::assertStringContainsString('převzat v měně dokladu kurzem 25,12 Kč', $invoice['note_below_items']);
        self::assertSame(['329.00', '69.09', '8264.45', '1735.55'], $this->item($id));
        self::assertSame(1, $this->auditCount($supplierId, $id));

        $rows = iterator_to_array($ledger->iterateRows($supplierId, '2026-02-01', '2026-02-28', false, ['sale' => [$id]]), false);
        self::assertCount(1, $rows);
        self::assertSame(8264.45, $rows[0]['base_czk']);
        self::assertSame(1735.55, $rows[0]['vat_czk']);
        self::assertSame($dphBefore, $dph->build($supplierId, SyntheticPohodaExport::YEAR, 2, 'monthly')['summary']['lines']);
        self::assertSame($khBefore, $kh->sectionDocuments($supplierId, SyntheticPohodaExport::YEAR, 2)['sections']);
        self::assertSame($journalBefore, $this->journal($supplierId, $id));
        self::assertSame([], $repair->run($supplierId, $export, true));
        self::assertSame([], $check->detect($supplierId)->items);
    }

    public function testIssuedRepairLeavesFiledPeriodUnchanged(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, xml_content, xml_size_bytes, xml_sha256, status, submitted_at)
             VALUES (?, 'dphkh1', ?, 2, '<x/>', 4, ?, 'submitted', NOW())"
        )->execute([$supplierId, SyntheticPohodaExport::YEAR, hash('sha256', '<x/>')]);
        $before = $this->invoice($id);

        $repair = $this->container->get(PohodaForeignIssuedRepair::class);
        $result = $repair->run($supplierId, $export, true);
        self::assertSame(['locked'], array_column($result, 'status'));
        self::assertStringContainsString('podaném období DPH', $result[0]['reason']);
        self::assertSame($before, $this->invoice($id));
        self::assertSame(0, $this->auditCount($supplierId, $id));
    }

    public function testIssuedRepairRejectsExistingCrownPaymentWithoutChangingIt(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $pdo = $this->db->pdo();
        $payment = $pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, '2026-02-25', 10, 'CZK', 'manual')"
        );
        $payment->execute([$supplierId, $id]);
        $before = $this->invoice($id);
        $itemBefore = $this->item($id);

        $repair = $this->container->get(PohodaForeignIssuedRepair::class);
        $result = $repair->run($supplierId, $export, true);
        self::assertSame(['review'], array_column($result, 'status'));
        self::assertStringContainsString('evidovanou úhradu', $result[0]['reason']);
        self::assertSame($before, $this->invoice($id));
        self::assertSame($itemBefore, $this->item($id));
        $stmt = $pdo->prepare('SELECT amount, currency FROM invoice_payments WHERE invoice_id = ?');
        $stmt->execute([$id]);
        self::assertSame([['10.00', 'CZK']], $stmt->fetchAll(PDO::FETCH_NUM));
    }

    public function testPaidInvoiceUsesTheWholeEuroMovementAndEntersTheNormalFxPath(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $txId = $this->bankPayment($supplierId, 'EUR', 398.09, [$id => 10000.00], 50.0);
        $bank = $this->container->get(BankPostingService::class);
        self::assertSame('cross_currency', $bank->previewTransaction($supplierId, $txId)['reason']);
        $suggestions = $this->container->get(BankPostingSuggestionRepository::class);
        $suggestion = $suggestions->createIfNoPending([
            'supplier_id' => $supplierId, 'bank_transaction_id' => $txId, 'source' => 'payment_match',
            'debit_account_code' => '221', 'credit_account_code' => '311', 'amount' => 398.09,
            'note' => 'cross_currency', 'status' => 'blocked',
        ]);
        $paymentBefore = $this->payments($id)[0];

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['converted'], array_column($result, 'status'), json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame([1], array_column($result, 'payments_converted'));
        self::assertSame([array_replace($paymentBefore, ['amount' => '398.09', 'currency' => 'EUR'])], $this->payments($id));
        self::assertSame(['paid', '398.09', '0.00', '2026-02-25'], $this->paymentState($id));
        self::assertSame('superseded', $suggestions->find($supplierId, $suggestion['id'])['status']);
        self::assertContains($txId, array_column($suggestions->matchedUnpostedWithoutSuggestion($supplierId), 'tx_id'));
        $preview = $bank->previewTransaction($supplierId, $txId);
        self::assertNull($preview['reason']);
        self::assertNotEmpty($preview['lines']);
        self::assertContains('EUR', array_column($preview['lines'], 'currency_code'));
    }

    public function testSharedBankMovementIsConvertedTogetherAndKeepsThePostedEntry(): void
    {
        [$supplierId, $first, $export] = $this->legacyInvoice();
        $second = $this->makeLegacy($supplierId, SyntheticPohodaExport::FOREIGN_ISSUED, 3516.80, 738.53);
        $txId = $this->bankPayment($supplierId, 'EUR', 567.49, [$first => 10000.0, $second => 4255.33]);
        $entry = $this->takeOverBankEntry($supplierId, $first, $txId);
        $before = $this->bankJournal($entry);
        self::assertCount(2, $before);
        $bank = $this->container->get(BankPostingService::class);
        self::assertSame('posted', $bank->transactionPostingInfo($supplierId, [$txId])[$txId]['status']);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['converted', 'converted'], array_column($result, 'status'), json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame([1, 1], array_column($result, 'payments_converted'));
        self::assertSame('398.09', $this->payments($first)[0]['amount']);
        self::assertSame('169.40', $this->payments($second)[0]['amount']);
        self::assertSame('0.00', $this->paymentState($first)[2]);
        self::assertSame('0.00', $this->paymentState($second)[2]);
        self::assertSame('taken_over', $bank->handleTransaction($txId)['reason']);
        self::assertSame($before, $this->bankJournal($entry));
        self::assertSame('posted', $bank->transactionPostingInfo($supplierId, [$txId])[$txId]['status']);
        $suggestions = $this->container->get(BankPostingSuggestionRepository::class);
        self::assertNull($suggestions->pendingForTx($supplierId, $txId));
        self::assertNotContains($txId, array_column($suggestions->matchedUnpostedWithoutSuggestion($supplierId), 'tx_id'));
    }

    public function testPartialPriorYearPaymentIsConvertedByShare(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE invoices SET paid_total = 5023.99 WHERE id = ?')->execute([$id]);
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, note)
            VALUES (?, ?, '2025-12-31', 5023.99, 'CZK', 'settlement', ?)")->execute([$supplierId, $id, PohodaPriorYearPayments::NOTE]);
        $before = $this->payments($id)[0];

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['converted'], array_column($result, 'status'), json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame([1], array_column($result, 'payments_converted'));
        self::assertSame([array_replace($before, ['amount' => '200.00', 'currency' => 'EUR'])], $this->payments($id));
        self::assertSame(['sent', '200.00', '198.09', null], $this->paymentState($id));
    }

    public function testPaidDocumentWithoutPaymentRowPreservesStatusAndDate(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_total = amount_to_pay, paid_at = '2026-02-25' WHERE id = ?")
            ->execute([$id]);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['converted'], array_column($result, 'status'), json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame([0], array_column($result, 'payments_converted'));
        self::assertSame([], $this->payments($id));
        self::assertSame(['paid', '398.09', '0.00', '2026-02-25'], $this->paymentState($id));
    }

    public function testPaymentFromCrownBankMovementRemainsForReview(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $this->bankPayment($supplierId, 'CZK', 10000.0, [$id => 10000.0]);
        $before = $this->payments($id);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['review'], array_column($result, 'status'));
        self::assertStringContainsString('bankovní pohyb v CZK', $result[0]['reason']);
        self::assertSame($before, $this->payments($id));
        self::assertSame('CZK', $this->invoice($id)['code']);
    }

    public function testIssuedRepairRollsBackIfCrownBalanceChangesByOneCent(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE invoices SET paid_total = 2512.00 WHERE id = ?')->execute([$id]);
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, note)
            VALUES (?, ?, '2025-12-31', 2512.00, 'CZK', 'settlement', ?)")->execute([$supplierId, $id, PohodaPriorYearPayments::NOTE]);
        $before = $this->invoice($id);
        $payments = $this->payments($id);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['review'], array_column($result, 'status'));
        self::assertStringContainsString('saldo dokladu v Kč. Zápis byl vrácen.', $result[0]['reason']);
        self::assertSame($before, $this->invoice($id));
        self::assertSame($payments, $this->payments($id));
        self::assertSame(0, $this->auditCount($supplierId, $id));
    }

    public function testIssuedRepairRollsBackIfEvenOneCentOfVatWouldChange(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $before = $this->invoice($id);
        $itemBefore = $this->item($id);
        $pdo = $this->db->pdo();
        $pdo->exec('CREATE TRIGGER test_issued_fx_vat_difference BEFORE UPDATE ON invoice_items FOR EACH ROW
            SET NEW.import_tax_vat_czk = NEW.import_tax_vat_czk + 0.01');
        try {
            $repair = $this->container->get(PohodaForeignIssuedRepair::class);
            $result = $repair->run($supplierId, $export, true);
            self::assertSame(['review'], array_column($result, 'status'));
            self::assertStringContainsString('evidenci DPH', $result[0]['reason']);
            self::assertSame($before, $this->invoice($id));
            self::assertSame($itemBefore, $this->item($id));
            self::assertSame(0, $this->auditCount($supplierId, $id));
        } finally {
            $pdo->exec('DROP TRIGGER test_issued_fx_vat_difference');
        }
    }

    public function testNewImportUsesTheSamePaymentConversion(): void
    {
        [$supplierId, $id] = $this->legacyInvoice(paidInExport: true, restoreLegacy: false);

        self::assertSame('EUR', $this->invoice($id)['code']);
        self::assertSame(['paid', '398.09', '0.00', null], $this->paymentState($id));
        self::assertSame(1, $this->auditCount($supplierId, $id));
    }

    public function testCrownAdvanceAndFinalInvoiceAreConvertedWithFullDeduction(): void
    {
        [$supplierId, $advance, $final, $export, $bankEntry] = $this->legacyPair();
        $before = $this->groupReports($supplierId, $final, $bankEntry);
        $repair = $this->container->get(PohodaForeignIssuedRepair::class);

        self::assertSame(['convert', 'convert'], array_column($repair->run($supplierId, $export, false), 'status'));
        self::assertSame('CZK', $this->invoice($advance)['code']);
        self::assertSame('CZK', $this->invoice($final)['code']);
        self::assertSame('CZK', $this->payments($advance)[0]['currency']);

        $result = $repair->run($supplierId, $export, true);

        self::assertSame(['converted', 'converted'], array_column($result, 'status'), json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame('96.80', $this->invoice($advance)['total_with_vat']);
        self::assertSame('96.80', $this->payments($advance)[0]['amount']);
        self::assertSame('EUR', $this->payments($advance)[0]['currency']);
        self::assertSame(['96.80', '2420.00', '145.20', '3872.00'], $this->deductionState($final));
        self::assertSame($before, $this->groupReports($supplierId, $final, $bankEntry));
        self::assertSame([], $repair->run($supplierId, $export, true));
    }

    public function testOnlyFinalInvoiceChangesWhenAdvanceIsAlreadyInTheDocumentCurrency(): void
    {
        [$supplierId, $advance, $final, $export, $bankEntry] = $this->legacyPair(advanceInForeign: true);
        $before = $this->groupReports($supplierId, $final, $bankEntry);
        $advanceBefore = $this->invoice($advance);
        $paymentsBefore = $this->payments($advance);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame([$final], array_column($result, 'id'));
        self::assertSame(['converted'], array_column($result, 'status'), json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame($advanceBefore, $this->invoice($advance));
        self::assertSame($paymentsBefore, $this->payments($advance));
        self::assertSame(['96.80', '2420.00', '145.20', '3872.00'], $this->deductionState($final));
        self::assertSame($before, $this->groupReports($supplierId, $final, $bankEntry));
    }

    public function testPartialDeductionUsesTheShareOfTheOriginalAdvance(): void
    {
        [$supplierId, , $final, $export, $bankEntry] = $this->legacyPair(drawCzk: 1000.0);
        $before = $this->groupReports($supplierId, $final, $bankEntry);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['converted', 'converted'], array_column($result, 'status'), json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(['40.00', '1000.00', '202.00', '5292.00'], $this->deductionState($final));
        self::assertSame($before, $this->groupReports($supplierId, $final, $bankEntry));
    }

    public function testPartialDeductionPrefersExplicitAmountInTheExport(): void
    {
        [$supplierId, , $final, $export] = $this->legacyPair(drawCzk: 1000.0, drawForeign: 39.99);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['converted', 'converted'], array_column($result, 'status'), json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(['39.99', '1000.00', '202.01', '5292.00'], $this->deductionState($final));
    }

    public function testPartialDeductionMustRoundBackToTheSourceCent(): void
    {
        [$supplierId, $advance, $final, $export] = $this->legacyPair(drawCzk: 1000.01);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['review', 'review'], array_column($result, 'status'));
        self::assertStringContainsString('na haléř', $result[0]['reason']);
        self::assertSame('CZK', $this->invoice($advance)['code']);
        self::assertSame('CZK', $this->invoice($final)['code']);
    }

    public function testTaxDocumentAndTaxedDeductionAreConvertedWithTheAdvance(): void
    {
        [$supplierId, $advance, $final, $export, $bankEntry, $tax] = $this->legacyPair(withDdkp: true);
        $before = $this->groupReports($supplierId, $final, $bankEntry);
        $taxJournal = $this->journal($supplierId, $tax);
        self::assertNotEmpty($taxJournal);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['converted', 'converted', 'converted'], array_column($result, 'status'), json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(['EUR', 'EUR', 'EUR'], array_map(fn (int $id): string => $this->invoice($id)['code'], [$advance, $final, $tax]));
        self::assertSame($tax, (int) $this->payments($advance)[0]['tax_document_invoice_id']);
        $stmt = $this->db->pdo()->prepare('SELECT total_without_vat, total_vat, import_tax_base_czk, import_tax_vat_czk
            FROM invoice_items WHERE invoice_id = ? AND advance_invoice_id = ?');
        $stmt->execute([$final, $advance]);
        self::assertSame(['-80.00', '-16.80', '-2000.00', '-420.00'], $stmt->fetch(PDO::FETCH_NUM));
        self::assertSame($before, $this->groupReports($supplierId, $final, $bankEntry));
        self::assertSame($taxJournal, $this->journal($supplierId, $tax));
    }

    public function testUndocumentedTaxDocumentBlocksTheWholeGroup(): void
    {
        [$supplierId, $advance, $final, $export, , $tax] = $this->legacyPair(withDdkp: true);
        $path = $export->dir . '/27_interni_doklady.xml';
        $xml = (string) file_get_contents($path);
        file_put_contents($path, str_replace('<typ:sourceAgenda>issuedAdvanceInvoice</typ:sourceAgenda>', '', $xml, $count));
        self::assertSame(1, $count);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, PohodaExport::open($export->dir), true);

        self::assertSame(['review', 'review', 'review'], array_column($result, 'status'));
        self::assertStringContainsString('Vazbu DDKP', $result[0]['reason']);
        self::assertSame(['CZK', 'CZK', 'CZK'], array_map(fn (int $id): string => $this->invoice($id)['code'], [$advance, $final, $tax]));
    }

    public function testVatDifferenceOfOneCentRollsBackTheEntireGroupAndTheBankPayment(): void
    {
        [$supplierId, $advance, $final, $export, $bankEntry, $tax] = $this->legacyPair(withDdkp: true);
        $ids = [$advance, $final, $tax];
        $invoices = array_map($this->invoice(...), $ids);
        $payments = $this->payments($advance);
        $before = $this->groupReports($supplierId, $final, $bankEntry);
        $pdo = $this->db->pdo();
        $pdo->exec("CREATE TRIGGER test_advance_fx_vat_difference BEFORE UPDATE ON invoice_items FOR EACH ROW
            SET NEW.import_tax_vat_czk = IF(NEW.invoice_id = {$tax}, NEW.import_tax_vat_czk + 0.01, NEW.import_tax_vat_czk)");
        try {
            $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

            self::assertSame(['review', 'review', 'review'], array_column($result, 'status'));
            foreach ($result as $row) {
                self::assertStringContainsString('Zápis byl vrácen', $row['reason']);
                self::assertSame(0, $this->auditCount($supplierId, $row['id']));
            }
            self::assertSame($invoices, array_map($this->invoice(...), $ids));
            self::assertSame($payments, $this->payments($advance));
            self::assertSame($before, $this->groupReports($supplierId, $final, $bankEntry));
        } finally {
            $pdo->exec('DROP TRIGGER test_advance_fx_vat_difference');
        }
    }

    public function testDocumentBeforeAccountingStartIsNotReportedInMigrationCheck(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $this->db->pdo()->prepare("UPDATE invoices SET issue_date = '2025-12-15', tax_date = '2025-12-15' WHERE id = ?")->execute([$id]);
        $before = $this->invoice($id);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame([PohodaForeignIssuedRepair::PAST_PERIOD], array_column($result, 'status'));
        self::assertSame($before, $this->invoice($id));
        self::assertSame(0, $this->auditCount($supplierId, $id));
        self::assertSame([], $this->container->get(ForeignIssuedInCzkCheck::class)->detect($supplierId)->items);
    }

    public function testFullPaymentMismatchShowsInvoiceAndBankAmounts(): void
    {
        [$supplierId, $id, $export] = $this->legacyInvoice();
        $this->bankPayment($supplierId, 'EUR', 399.0, [$id => 10000.0]);

        $result = $this->container->get(PohodaForeignIssuedRepair::class)->run($supplierId, $export, true);

        self::assertSame(['review'], array_column($result, 'status'));
        self::assertStringContainsString('398,09 EUR', $result[0]['reason']);
        self::assertStringContainsString('399,00 EUR', $result[0]['reason']);
    }

    /** @return array{int,int,int,PohodaExport,int,?int} */
    private function legacyPair(float $drawCzk = 2420.0, bool $advanceInForeign = false, bool $withDdkp = false, ?float $drawForeign = null): array
    {
        [$supplierId, , $export] = $this->legacyInvoice(restoreLegacy: false,
            addDocuments: static fn (string $dir) => SyntheticPohodaExport::withForeignIssuedAdvancePair($dir, $drawCzk, $advanceInForeign, $withDdkp, $drawForeign));
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$supplierId, SyntheticPohodaExport::FOREIGN_ADVANCE]);
        $advance = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $advance);
        self::assertSame($advanceInForeign ? 'EUR' : 'CZK', $this->invoice($advance)['code']);
        $stmt->execute([$supplierId, SyntheticPohodaExport::FOREIGN_ADVANCE_FINAL]);
        $final = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $final);
        $tax = null;
        if ($withDdkp) {
            $stmt->execute([$supplierId, SyntheticPohodaExport::FOREIGN_ADVANCE_DDKP]);
            $tax = (int) $stmt->fetchColumn();
            self::assertGreaterThan(0, $tax);
            self::assertSame('CZK', $this->invoice($tax)['code']);
            $this->makeLegacy($supplierId, SyntheticPohodaExport::FOREIGN_ADVANCE_FINAL, 3000.0, 630.0);
        }
        self::assertSame('CZK', $this->invoice($final)['code']);
        $link = $pdo->prepare('SELECT parent_invoice_id FROM invoices WHERE id = ?');
        $link->execute([$final]);
        self::assertSame($advance, (int) $link->fetchColumn());
        $txId = $this->bankPayment($supplierId, 'EUR', 96.80, [$advance => 2420.0], 25.0);
        $bankEntry = $this->takeOverBankEntry($supplierId, $final, $txId, 2420.0, '324');
        if ($advanceInForeign) {
            $pdo->prepare('UPDATE invoice_payments SET amount = 96.80, currency = ? WHERE invoice_id = ?')->execute(['EUR', $advance]);
            $pdo->prepare('UPDATE invoices SET paid_total = 96.80 WHERE id = ?')->execute([$advance]);
        }
        if ($tax !== null) {
            $link->execute([$tax]);
            self::assertSame($advance, (int) $link->fetchColumn());
            $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE invoice_id = ?')->execute([$tax, $advance]);
            $pdo->prepare("UPDATE invoice_items SET advance_invoice_id = ? WHERE invoice_id = ? AND total_with_vat < 0")->execute([$advance, $final]);
        }
        return [$supplierId, $advance, $final, $export, $bankEntry, $tax];
    }

    private function deductionState(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT advance_paid_amount, import_advance_paid_czk, amount_to_pay, import_amount_to_pay_czk FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_NUM);
    }

    private function groupReports(int $supplierId, int $final, int $bankEntry): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT period_id FROM journal_entries WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $bankEntry]);
        $periodId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $periodId);
        $saldo = $this->container->get(SaldoService::class)->build($supplierId, $periodId, '2026-02-28')['accounts'];
        $balances = array_map(static fn (array $account): array => [$account['gl_balance'], $account['open_items_total'], $account['difference']], $saldo);
        $cash = $this->container->get(CashJournalRepository::class)->movements($supplierId, '2026-02-01', '2026-02-28', true);
        foreach ($cash as &$movement) {
            if ($movement['source_type'] === 'bank' && $movement['bank_class'] === 'income' && empty($movement['override_bucket'])) {
                $buckets = CashJournalService::bankIncomeAllocation($movement);
                unset($buckets['ratio'], $movement['bank_income_base'], $movement['bank_income_exempt'],
                    $movement['bank_alloc_czk'], $movement['bank_alloc_native'], $movement['bank_alloc_cross_ccy']);
                $movement['bank_allocation'] = $buckets;
            }
        }
        unset($movement);
        return [$this->container->get(KontrolniHlaseniBuilder::class)->sectionDocuments($supplierId, 2026, 2)['sections'],
            $balances, $cash, $this->journal($supplierId, $final), $this->bankJournal($bankEntry)];
    }

    /** @return array{int,int,PohodaExport} */
    private function legacyInvoice(bool $paidInExport = false, bool $restoreLegacy = true, ?\Closure $addDocuments = null): array
    {
        $pdo = $this->db->pdo();
        $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $anyCurrencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        foreach ([$userId, $czId, $anyCurrencyId, $vatRateId] as $required) {
            self::assertGreaterThan(0, $required, 'Chybí základní data testovací databáze.');
        }
        $ico = SyntheticPohodaExport::ICO;
        $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer,
                                   default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, 'Účetní 12', 'Brno', '60200', ?, 'prevod@example.invalid', ?, ?, 1, ?, ?, 'tax_evidence')"
        )->execute([SyntheticPohodaExport::NAME, $czId, $ico, 'CZ' . $ico, $anyCurrencyId, $vatRateId]);
        $supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$supplierId]);
        $czk = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$czk, $supplierId]);
        $pdo->prepare("INSERT INTO pohoda_imports (supplier_id, mode, status, agenda_ico, agenda_year) VALUES (?, 'import', 'completed', ?, ?)")
            ->execute([$supplierId, $ico, SyntheticPohodaExport::YEAR]);
        $runId = (int) $pdo->lastInsertId();

        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withForeignCurrencyDocuments($dir);
        $addDocuments?->__invoke($dir);
        if ($paidInExport) {
            $path = $dir . '/12_faktury_issuedInvoice.xml';
            $xml = file_get_contents($path);
            self::assertIsString($xml);
            $xml = str_replace('<typ:amountHome>10000.00</typ:amountHome>', '<typ:amountHome>0</typ:amountHome>', $xml, $count);
            self::assertSame(1, $count);
            file_put_contents($path, $xml);
        }
        $export = PohodaExport::open($dir);
        $protocol = $this->container->get(PohodaImporter::class)->run($supplierId, $userId, $export, false, $runId);
        self::assertFalse($protocol->hasErrors(), json_encode($protocol->toArray(), JSON_UNESCAPED_UNICODE));

        if ($restoreLegacy) {
            $id = $this->makeLegacy($supplierId, SyntheticPohodaExport::FOREIGN_MISMATCH, 8264.45, 1735.55);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
            $stmt->execute([$supplierId, SyntheticPohodaExport::FOREIGN_MISMATCH]);
            $id = (int) $stmt->fetchColumn();
            self::assertGreaterThan(0, $id);
        }
        return [$supplierId, $id, $export];
    }

    private function makeLegacy(int $supplierId, string $number, float $base, float $vat): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$supplierId, $number]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id);
        $pdo->prepare(
            "UPDATE invoices SET currency_id = (SELECT default_currency_id FROM supplier WHERE id = ?), exchange_rate = NULL,
                    total_without_vat = ?, total_vat = ?, total_with_vat = ?,
                    import_total_with_vat_czk = NULL, import_total_without_vat_czk = NULL, import_total_vat_czk = NULL,
                    import_amount_to_pay_czk = NULL, import_advance_paid_czk = NULL, note_below_items = ?
              WHERE supplier_id = ? AND id = ?"
        )->execute([$supplierId, $base, $vat, round($base + $vat, 2),
            "Převzato z Pohody, doklad {$number}; doklad v EUR, převzat v Kč (odchylka v přepočtu částek ze zdroje)", $supplierId, $id]);
        $pdo->prepare(
            'UPDATE invoice_items SET unit_price_without_vat = ROUND(import_tax_base_czk / quantity, 2),
                    total_without_vat = import_tax_base_czk, total_vat = import_tax_vat_czk,
                    total_with_vat = import_tax_base_czk + import_tax_vat_czk,
                    import_tax_base_czk = NULL, import_tax_vat_czk = NULL WHERE invoice_id = ?'
        )->execute([$id]);
        return $id;
    }

    private function bankPayment(int $supplierId, string $currency, float $amount, array $shares, float $rate = 25.12): int
    {
        $pdo = $this->db->pdo();
        $account = '1000000005';
        $pdo->prepare('UPDATE currencies SET account_number = ?, bank_code = ? WHERE supplier_id = ? AND code = ?')
            ->execute([$account, '0100', $supplierId, $currency]);
        if ($currency === 'EUR') {
            $pdo->prepare("INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES ('2026-02-25', 'EUR', ?)
                ON DUPLICATE KEY UPDATE rate = VALUES(rate)")->execute([$rate]);
        }
        $pdo->prepare("INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
            VALUES (?, 'test.gpc', ?, ?, '0100', ?, '2026-02-25')")->execute([$supplierId, hash('sha256', 'test-' . $supplierId), $account, $currency]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, amount, currency, posted_at, matched_invoice_id, match_status)
            VALUES (?, ?, ?, '2026-02-25', ?, 'manual')")->execute([$statementId, $amount, $currency, array_key_first($shares)]);
        $txId = (int) $pdo->lastInsertId();
        $payment = $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, bank_transaction_id, paid_on, amount, currency, source)
            VALUES (?, ?, ?, '2026-02-25', ?, 'CZK', 'bank')");
        $header = $pdo->prepare("UPDATE invoices SET status = 'paid', paid_total = ?, paid_at = '2026-02-25' WHERE id = ?");
        foreach ($shares as $id => $share) {
            $payment->execute([$supplierId, $id, $txId, $share]);
            $header->execute([$share, $id]);
        }
        return $txId;
    }

    private function takeOverBankEntry(int $supplierId, int $invoiceId, int $txId, float $amount = 14255.33, string $counter = '311.001'): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT period_id FROM journal_entries WHERE supplier_id = ? AND source_type = 'invoice' AND source_id = ?");
        $stmt->execute([$supplierId, $invoiceId]);
        $periodId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $periodId);
        $entry = $this->container->get(PostingService::class)->postDocument($supplierId, 'bank', $txId, [
            ['account_code' => '221.001', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $counter, 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => '2026-02-25', 'description' => 'Převzatá úhrada']);
        $this->container->get(PohodaImportRepository::class)->put($supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY, 'test-eur-bank', $entry, null);
        return $entry;
    }

    private function bankJournal(int $entry): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT l.* FROM journal_entry_lines l WHERE l.entry_id = ? ORDER BY l.line_no');
        $stmt->execute([$entry]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function payments(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, amount, currency, paid_on, bank_transaction_id, tax_document_invoice_id FROM invoice_payments WHERE invoice_id = ? ORDER BY id'
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function paymentState(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_total, amount_to_pay - paid_total, paid_at FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_NUM);
    }

    private function invoice(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.code, i.exchange_rate, i.total_with_vat, i.import_total_with_vat_czk, i.import_amount_to_pay_czk, i.note_below_items
               FROM invoices i JOIN currencies c ON c.id = i.currency_id WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function item(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT total_without_vat, total_vat, import_tax_base_czk, import_tax_vat_czk FROM invoice_items WHERE invoice_id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_NUM);
    }

    private function journal(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.* FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id
              WHERE e.supplier_id = ? AND e.source_type = 'invoice' AND e.source_id = ? ORDER BY l.line_no"
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function auditCount(int $supplierId, int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ? AND entity_id = ?');
        $stmt->execute([$supplierId, PohodaForeignIssuedRepair::AUDIT_ACTION, $id]);
        return (int) $stmt->fetchColumn();
    }
}
