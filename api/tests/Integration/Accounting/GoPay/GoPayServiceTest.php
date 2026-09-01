<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\GoPay;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\GoPay\GoPayService;
use MyInvoice\Service\Accounting\JournalLinkService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Export\ExportPeriod;
use MyInvoice\Service\Export\MonthlyExportService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[Group('integration')]
final class GoPayServiceTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private GoPayService $service;
    private BankPostingService $bankPosting;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private JournalLinkService $links;
    private EmailNoticeReconciler $reconciler;
    private AccountingPeriodRepository $periods;
    private ImportJobRepository $jobs;
    private MonthlyExportService $monthlyExport;
    private int $supplierId;
    private int $userId;
    private int $currencyId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 5);
        if (!is_file($root . '/cfg.php')) {
            $this->markTestSkipped('Test vyžaduje lokální databázi.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(GoPayService::class);
        $this->bankPosting = $container->get(BankPostingService::class);
        $this->posting = $container->get(PostingService::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->links = $container->get(JournalLinkService::class);
        $this->reconciler = $container->get(EmailNoticeReconciler::class);
        $this->periods = $container->get(AccountingPeriodRepository::class);
        $this->jobs = $container->get(ImportJobRepository::class);
        $this->monthlyExport = $container->get(MonthlyExportService::class);
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query("SELECT id FROM supplier WHERE accounting_mode='double_entry' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí firma s podvojným účetnictvím nebo uživatel.');
        }
        $currency = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id=? AND code='CZK' ORDER BY id LIMIT 1");
        $currency->execute([$this->supplierId]);
        $this->currencyId = (int) ($currency->fetchColumn() ?: 0);
        if ($this->currencyId === 0) {
            $this->markTestSkipped('Firma nemá CZK měnu.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $period = $this->periods->findForDate($this->supplierId, self::YEAR . '-01-15');
        if ($period === null) {
            $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        } elseif ($period['status'] !== 'open') {
            $pdo->prepare('UPDATE accounting_periods SET status="open" WHERE id=?')->execute([(int) $period['id']]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testXmlImportMatchesPostsAndDeduplicatesWholeClearing(): void
    {
        $ids = $this->configureAccounts();
        [$invoiceId, $creditNoteId] = $this->documents();
        [$invoiceEntryId] = $this->postDocuments($invoiceId, $creditNoteId);
        $this->payment($invoiceId);
        $bankTransactionId = $this->bankPayout();

        $result = $this->service->import($this->supplierId, $this->userId, 'synthetic.xml', $this->xml());
        self::assertFalse($result['duplicate']);
        self::assertSame('processed', $result['clearing']['status']);
        self::assertSame(5, $result['clearing']['posted_count']);
        self::assertSame(0, $result['clearing']['issue_count']);
        self::assertSame($bankTransactionId, $result['clearing']['bank_transaction_id']);

        $movements = $result['clearing']['movements'];
        self::assertSame($invoiceId, $movements[0]['invoice_id']);
        self::assertSame($creditNoteId, $movements[1]['credit_note_id']);
        foreach ($movements as $movement) {
            self::assertSame('posted', $movement['status']);
            self::assertNotNull($movement['journal_entry_id']);
        }

        $entryCount = $this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='gopay'");
        $entryCount->execute([$this->supplierId]);
        self::assertSame(5, (int) $entryCount->fetchColumn());
        $this->assertPair((int) $movements[0]['journal_entry_id'], $ids['gopay'], '311', 1000.00);
        $this->assertPair((int) $movements[1]['journal_entry_id'], '311', $ids['gopay'], 100.00);
        $this->assertPair((int) $movements[3]['journal_entry_id'], '568', $ids['gopay'], 20.00);
        $this->assertPair((int) $movements[4]['journal_entry_id'], '261', $ids['gopay'], 875.00);

        $invoiceEntry = $this->journal->find($invoiceEntryId, $this->supplierId);
        self::assertIsArray($invoiceEntry);
        $fromInvoice = $this->links->related($this->supplierId, $invoiceEntry);
        self::assertCount(1, $fromInvoice['items']);
        self::assertSame('gopay', $fromInvoice['items'][0]['source_type']);
        self::assertSame('payment', $fromInvoice['items'][0]['relation']);
        self::assertSame((int) $movements[0]['journal_entry_id'], $fromInvoice['items'][0]['entry_id']);

        $gopayEntry = $this->journal->find((int) $movements[0]['journal_entry_id'], $this->supplierId);
        self::assertIsArray($gopayEntry);
        $fromGoPay = $this->links->related($this->supplierId, $gopayEntry);
        self::assertCount(1, $fromGoPay['items']);
        self::assertSame('invoice', $fromGoPay['items'][0]['source_type']);
        self::assertSame('document', $fromGoPay['items'][0]['relation']);
        self::assertSame($invoiceEntryId, $fromGoPay['items'][0]['entry_id']);

        $relatedMap = $this->links->hasRelatedMap($this->supplierId, [$invoiceEntry, $gopayEntry]);
        self::assertArrayHasKey($invoiceEntryId, $relatedMap);
        self::assertArrayHasKey((int) $movements[0]['journal_entry_id'], $relatedMap);

        $duplicate = $this->service->import($this->supplierId, $this->userId, 'synthetic.xml', $this->xml());
        self::assertTrue($duplicate['duplicate']);
        $entryCount->execute([$this->supplierId]);
        self::assertSame(5, (int) $entryCount->fetchColumn());
    }

    public function testEmailNoticeAssociationIsTransferredAndPostedByOfficialStatement(): void
    {
        $this->configureAccounts();
        [$invoiceId, $creditNoteId] = $this->documents();
        $this->postDocuments($invoiceId, $creditNoteId);
        $this->payment($invoiceId);
        $noticeTransactionId = $this->bankPayout('email_notice');

        $import = $this->service->import($this->supplierId, $this->userId, 'synthetic-notice.xml', $this->xml());
        $clearingId = (int) $import['clearing']['id'];
        self::assertSame('needs_review', $import['clearing']['status']);
        self::assertNull($import['clearing']['bank_journal_entry_id']);

        $candidate = $this->service->payoutCandidateForTransaction($this->supplierId, $noticeTransactionId);
        self::assertNotNull($candidate);
        self::assertSame($clearingId, $candidate['id']);

        $associated = $this->service->associatePayoutTransaction(
            $this->supplierId,
            $clearingId,
            $noticeTransactionId,
            $this->userId,
        );
        self::assertSame($noticeTransactionId, $associated['payout_match_transaction_id']);
        self::assertSame('email_notice_provisional', $associated['payout_issue_code']);
        self::assertNull($associated['bank_journal_entry_id']);

        $officialTransactionId = $this->bankPayout('statement');
        $takeover = $this->reconciler->takeOverFromEmailNotice($officialTransactionId);
        self::assertNotNull($takeover);

        $completed = $this->service->detail($this->supplierId, $clearingId);
        self::assertSame('processed', $completed['status']);
        self::assertSame($officialTransactionId, $completed['payout_match_transaction_id']);
        self::assertSame($officialTransactionId, $completed['bank_transaction_id']);
        self::assertNotNull($completed['bank_journal_entry_id']);
        self::assertNull($completed['payout_issue_code']);

        $notice = $this->db->pdo()->prepare('SELECT match_status FROM bank_transactions WHERE id=?');
        $notice->execute([$noticeTransactionId]);
        self::assertSame('unmatched', $notice->fetchColumn());
    }

    public function testPdfLifecycleAndMonthlyExportPreserveXmlAndAccounting(): void
    {
        $this->configureAccounts();
        [$invoiceId, $creditNoteId] = $this->documents();
        $this->postDocuments($invoiceId, $creditNoteId);
        $this->payment($invoiceId);
        $this->bankPayout();
        $pdf = "%PDF-1.4\nsynthetic GoPay clearing\n%%EOF";

        $import = $this->service->import(
            $this->supplierId,
            $this->userId,
            'synthetic-with-pdf.xml',
            $this->xml(),
            ['file_name' => 'synthetic.pdf', 'content' => $pdf],
        );
        $clearingId = (int) $import['clearing']['id'];
        self::assertTrue($import['clearing']['has_pdf']);
        self::assertSame('synthetic.pdf', $import['clearing']['pdf_name']);
        self::assertSame(strlen($pdf), $import['clearing']['pdf_size_bytes']);
        self::assertArrayNotHasKey('pdf_content', $import['clearing']);
        self::assertArrayNotHasKey('pdf_hash', $import['clearing']);
        $listed = array_values(array_filter(
            $this->service->listClearings($this->supplierId),
            static fn (array $row): bool => (int) $row['id'] === $clearingId,
        ));
        self::assertCount(1, $listed);
        self::assertTrue($listed[0]['has_pdf']);
        self::assertSame(
            ['content' => $pdf, 'file_name' => 'synthetic.pdf'],
            $this->service->downloadPdf($this->supplierId, $clearingId),
        );

        try {
            $this->service->downloadPdf($this->supplierId + 100000, $clearingId);
            self::fail('Cizí firma nesmí stáhnout GoPay PDF.');
        } catch (\MyInvoice\Service\Accounting\GoPay\GoPayException $e) {
            self::assertSame('pdf_not_found', $e->errorCode);
        }

        $replacement = "%PDF-1.7\nreplacement\n%%EOF";
        $updated = $this->service->uploadPdf(
            $this->supplierId,
            $clearingId,
            '../replacement.pdf',
            $replacement,
        );
        self::assertSame('replacement.pdf', $updated['pdf_name']);
        self::assertSame($replacement, $this->service->downloadPdf($this->supplierId, $clearingId)['content']);

        $entryCount = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type="gopay"'
        );
        $entryCount->execute([$this->supplierId]);
        $entriesBeforeDelete = (int) $entryCount->fetchColumn();
        $withoutPdf = $this->service->deletePdf($this->supplierId, $clearingId);
        self::assertFalse($withoutPdf['has_pdf']);
        self::assertNull($withoutPdf['pdf_name']);
        self::assertSame($this->xml(), $this->service->download($this->supplierId, $clearingId)['content']);
        $entryCount->execute([$this->supplierId]);
        self::assertSame($entriesBeforeDelete, (int) $entryCount->fetchColumn());

        $this->service->uploadPdf($this->supplierId, $clearingId, 'export.pdf', $pdf);
        $january = new ExportPeriod('monthly', self::YEAR, 1, null, self::YEAR . '-01-01', self::YEAR . '-02-01', self::YEAR . '-01');
        $february = new ExportPeriod('monthly', self::YEAR, 2, null, self::YEAR . '-02-01', self::YEAR . '-03-01', self::YEAR . '-02');
        self::assertSame(1, $this->monthlyExport->previewCounts($this->supplierId, $january)['gopay_pdf']);
        self::assertSame(1, $this->monthlyExport->previewCounts($this->supplierId, $january)['gopay_xml']);
        self::assertSame(0, $this->monthlyExport->previewCounts($this->supplierId, $february)['gopay_pdf']);
        self::assertSame(0, $this->monthlyExport->previewCounts($this->supplierId, $february)['gopay_xml']);

        $jobId = $this->jobs->create($this->supplierId, 'monthly_export', [
            'period' => 'monthly',
            'year' => self::YEAR,
            'month' => 1,
            'parts' => ['gopay_pdf', 'gopay_xml'],
        ], $this->userId);
        $this->monthlyExport->run($jobId);
        $job = $this->jobs->find($jobId, $this->supplierId);
        self::assertIsArray($job);
        self::assertSame('completed', $job['status']);
        $zipPath = $this->monthlyExport->resolveResultPath((string) $job['result_path']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath));
        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false) $entries[] = $name;
            }
            $pdfEntry = array_values(array_filter($entries, static fn (string $name): bool => str_starts_with($name, 'GoPay/PDF/')));
            $xmlEntry = array_values(array_filter($entries, static fn (string $name): bool => str_starts_with($name, 'GoPay/XML/')));
            self::assertCount(1, $pdfEntry);
            self::assertCount(1, $xmlEntry);
            self::assertSame($pdf, $zip->getFromName($pdfEntry[0]));
            self::assertSame($this->xml(), $zip->getFromName($xmlEntry[0]));
        } finally {
            $zip->close();
            if (is_file($zipPath)) unlink($zipPath);
        }
    }

    public function testDeleteRemovesImportedXmlAndOwnedEntriesButKeepsMatchedDocuments(): void
    {
        $this->configureAccounts();
        [$invoiceId, $creditNoteId] = $this->documents();
        [$invoiceEntryId, $creditNoteEntryId] = $this->postDocuments($invoiceId, $creditNoteId);
        $this->payment($invoiceId);
        $bankTransactionId = $this->bankPayout();

        $import = $this->service->import($this->supplierId, $this->userId, 'synthetic-delete.xml', $this->xml());
        $clearingId = (int) $import['clearing']['id'];
        $bankEntryId = (int) $import['clearing']['bank_journal_entry_id'];
        $this->service->associatePayoutTransaction($this->supplierId, $clearingId, $bankTransactionId, $this->userId);
        $ownership = $this->db->pdo()->prepare('SELECT bank_journal_entry_id,bank_journal_entry_owned FROM gopay_clearings WHERE id=?');
        $ownership->execute([$clearingId]);
        self::assertSame(
            ['bank_journal_entry_id' => $bankEntryId, 'bank_journal_entry_owned' => 1],
            array_map('intval', $ownership->fetch(PDO::FETCH_ASSOC)),
        );
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE gopay_clearings SET bank_journal_entry_owned=0 WHERE id=?')->execute([$clearingId]);
        $ownedEntryIds = array_map(
            static fn (array $movement): int => (int) $movement['journal_entry_id'],
            $import['clearing']['movements'],
        );
        $ownedEntryIds[] = $bankEntryId;

        try {
            $this->service->delete($this->supplierId + 100000, $clearingId, $this->userId);
            self::fail('Cizí firma nesmí GoPay vyúčtování smazat.');
        } catch (\MyInvoice\Service\Accounting\GoPay\GoPayException $e) {
            self::assertSame('not_found', $e->errorCode);
        }

        $result = $this->service->delete($this->supplierId, $clearingId, $this->userId);
        self::assertTrue($result['deleted']);
        self::assertEqualsCanonicalizing($ownedEntryIds, $result['deleted_entry_ids']);
        self::assertNull($result['preserved_bank_entry_id']);

        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM gopay_clearings WHERE id={$clearingId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM gopay_movements WHERE clearing_id={$clearingId}")->fetchColumn());
        foreach ($ownedEntryIds as $entryId) {
            self::assertNull($this->journal->find($entryId, $this->supplierId));
        }
        self::assertNotNull($this->journal->find($invoiceEntryId, $this->supplierId));
        self::assertNotNull($this->journal->find($creditNoteEntryId, $this->supplierId));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id={$invoiceId}")->fetchColumn());
        self::assertSame('unmatched', $pdo->query("SELECT match_status FROM bank_transactions WHERE id={$bankTransactionId}")->fetchColumn());

        $reimport = $this->service->import($this->supplierId, $this->userId, 'synthetic-delete.xml', $this->xml());
        self::assertFalse($reimport['duplicate']);
        self::assertSame('processed', $reimport['clearing']['status']);
    }

    public function testDeletePreservesExistingBankPostingAndItsMatchStatus(): void
    {
        $accounts = $this->configureAccounts();
        [$invoiceId, $creditNoteId] = $this->documents();
        $this->postDocuments($invoiceId, $creditNoteId);
        $this->payment($invoiceId);
        $bankTransactionId = $this->bankPayout();
        $posting = $this->bankPosting->postManual($this->supplierId, $bankTransactionId, [
            'debit_account_code' => $accounts['bank'],
            'credit_account_code' => '261',
            'description' => 'Test existujícího bankovního zápisu',
        ], ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        $bankEntryId = (int) $posting['entry_id'];

        $import = $this->service->import($this->supplierId, $this->userId, 'synthetic-preserve.xml', $this->xml());
        $clearingId = (int) $import['clearing']['id'];
        self::assertSame($bankEntryId, $import['clearing']['bank_journal_entry_id']);

        $result = $this->service->delete($this->supplierId, $clearingId, $this->userId);

        self::assertSame($bankEntryId, $result['preserved_bank_entry_id']);
        self::assertNotContains($bankEntryId, $result['deleted_entry_ids']);
        self::assertNotNull($this->journal->find($bankEntryId, $this->supplierId));
        $status = $this->db->pdo()->prepare('SELECT match_status FROM bank_transactions WHERE id=?');
        $status->execute([$bankTransactionId]);
        self::assertSame('manual', $status->fetchColumn());
    }

    /** @return array{gopay:string,bank:string} */
    private function configureAccounts(): array
    {
        $pdo = $this->db->pdo();
        $parent = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id={$this->supplierId} AND account_code='221'")->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type,normal_side,is_synthetic,parent_id)
             VALUES (?,?,?,"asset","debit",0,?)'
        );
        $insert->execute([$this->supplierId, '221.GP98', 'GoPay test', $parent]);
        $gopayId = (int) $pdo->lastInsertId();
        $insert->execute([$this->supplierId, '221.BK98', 'Banka test', $parent]);
        $bankId = (int) $pdo->lastInsertId();
        $id = fn (string $code): int => (int) $pdo->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id={$this->supplierId} AND account_code=" . $pdo->quote($code)
        )->fetchColumn();

        $this->service->saveSettings($this->supplierId, [
            'currency' => 'CZK',
            'gopay_account_id' => $gopayId,
            'receivable_account_id' => $id('311'),
            'fee_account_id' => $id('568'),
            'clearing_account_id' => $id('261'),
            'destination_bank_account_id' => $bankId,
            'payout_account_number' => '1000000005',
            'payout_bank_code' => '0100',
            'payout_date_tolerance_days' => 3,
        ], $this->userId);
        return ['gopay' => '221.GP98', 'bank' => '221.BK98'];
    }

    /** @return array{int,int} */
    private function documents(): array
    {
        $pdo = $this->db->pdo();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor)
             VALUES (?,"Test GoPay","Test 1","Praha","11000",?,"test@example.test","cs",?,1,0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);
        $clientId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id,varsymbol,invoice_type,parent_invoice_id,client_id,issue_date,tax_date,due_date,
                 currency_id,reverse_charge,total_without_vat,total_vat,total_with_vat,paid_total,status,
                 supplier_order_number,note_below_items,vat_classification_code,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,0,?,0,?,0,?,?,?,"1",?)'
        );
        $insert->execute([$this->supplierId, '20980001', 'invoice', null, $clientId, self::YEAR . '-01-10', self::YEAR . '-01-10', self::YEAR . '-01-20',
            $this->currencyId, 1000, 1000, 'paid', 'TEST000001', null, $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        $insert->execute([$this->supplierId, '20980002', 'credit_note', $invoiceId, $clientId, self::YEAR . '-01-20', self::YEAR . '-01-20', self::YEAR . '-01-20',
            $this->currencyId, -100, -100, 'sent', 'TEST000001', null, $this->userId]);
        return [$invoiceId, (int) $pdo->lastInsertId()];
    }

    /** @return array{int,int} */
    private function postDocuments(int $invoiceId, int $creditNoteId): array
    {
        $invoiceEntryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000],
        ], ['entry_date' => self::YEAR . '-01-10', 'document_no' => 'FV-TEST', 'description' => 'Test faktura', 'posted_by' => $this->userId]);
        $creditNoteEntryId = $this->posting->postDocument($this->supplierId, 'invoice', $creditNoteId, [
            ['account_code' => '602', 'side' => 'debit', 'amount' => 100],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 100],
        ], ['entry_date' => self::YEAR . '-01-20', 'document_no' => 'DB-TEST', 'description' => 'Test dobropis', 'posted_by' => $this->userId]);
        return [$invoiceEntryId, $creditNoteEntryId];
    }

    private function payment(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id,invoice_id,paid_on,amount,currency,bank_reference,source,created_by)
             VALUES (?,?,?,?,"CZK","GOPAY:1000000001","mark_paid",?)'
        )->execute([$this->supplierId, $invoiceId, self::YEAR . '-01-15', 1000, $this->userId]);
    }

    private function bankPayout(string $source = 'statement'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id,source,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by)
             VALUES (?,?,?, ?,"1000000005","0100","CZK",?,?)'
        )->execute([
            $this->supplierId,
            $source === 'email_notice' ? 'email_notice' : 'gpc',
            'synthetic-' . $source . '-' . uniqid('', true) . '.gpc',
            hash('sha256', uniqid('gopay', true)),
            self::YEAR . '-02-01',
            $this->userId,
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id,source,posted_at,amount,currency,variable_symbol,counterparty_account,
                 counterparty_bank,counterparty_name,description,match_status)
             VALUES (?,?,?,875,"CZK","20980001","1000000005","0100","GoPay","Clearing","unmatched")'
        )->execute([$statementId, $source, self::YEAR . '-02-01']);
        return (int) $pdo->lastInsertId();
    }

    private function assertPair(int $entryId, string $debit, string $credit, float $amount): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT coa.account_code,jel.side,jel.amount FROM journal_entry_lines jel
             JOIN chart_of_accounts coa ON coa.id=jel.account_id WHERE jel.entry_id=? AND jel.supplier_id=?'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        self::assertContains(['account_code' => $debit, 'side' => 'debit', 'amount' => number_format($amount, 2, '.', '')], $rows);
        self::assertContains(['account_code' => $credit, 'side' => 'credit', 'amount' => number_format($amount, 2, '.', '')], $rows);
    }

    private function xml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<clearing xmlns="https://www.gopay.cz/clearing" accountName="Test CZK" amount="1000.00"
 amountCreditNote="0.00" amountFee="20.00" amountFeeExternal="10.00" amountSent="875.00"
 amountStorno="100.00" amountStornoFee="5.00" amountTransfer="875.00"
 clearingId="TEST-CLEARING-2098" dateClearedFrom="01.01.2098" dateClearedTo="31.01.2098"
 datePerformed="01.02.2098" variableSymbol="20980001">
 <paymentChannel fee="10.00" transactionFee="10.00" type="test" volumeFee="0.00"><movements>
  <movement accountMovementId="TEST-MOVE-2098-1" amount="1000.00" counterpartyName="test"
   datePerformed="15.01.2098" orderId="TEST000001" paymentSessionId="1000000001" type="credit"/>
 </movements></paymentChannel>
 <storno>
  <stornoMovement accountMovementId="TEST-MOVE-2098-2" amount="-100.00" counterpartyName="GOPAY"
   datePerformed="20.01.2098" orderId="TEST000001" paymentSessionId="1000000001" type="storno"/>
  <stornoMovement accountMovementId="TEST-MOVE-2098-3" amount="-5.00" counterpartyName="GOPAY"
   datePerformed="20.01.2098" orderId="TEST000001" paymentSessionId="1000000001" type="stornoFee"/>
 </storno>
</clearing>
XML;
    }
}
