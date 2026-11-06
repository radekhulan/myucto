<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountMappingService;
use MyInvoice\Service\Accounting\Bank\BankAnalyticResolver;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Vat\VatClearingService;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxPayrollTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticNx1Archive.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxAccountingTables.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

#[Group('integration')]
final class StereoNxAccountingPaymentsOrchestrationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxAccountingImporter $importer;
    private int $supplierId;
    private int $userId;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(StereoNxAccountingImporter::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $identity = SyntheticStereoNxAccountingTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name=?, ic=?, dic=?, accounting_mode='double_entry',
            accounting_enabled=0, accounting_starts_on=NULL, accounting_activation_status='none', is_vat_payer=1 WHERE id=?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $pdo->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default)
            VALUES (?,"CZK","CZK","Kč","CZK","CZK",2,1,1)')->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int) $pdo->lastInsertId(), $this->supplierId]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testOrchestratorIncludesPaymentsDryActualAndRepeat(): void
    {
        $backup = $this->backup(self::tables());
        $before = $this->snapshot();
        $dry = $this->importer->run($backup, $this->supplierId, $this->userId, true, true);
        self::assertTrue($dry['ok'], json_encode($dry, JSON_UNESCAPED_UNICODE));
        self::assertSame($before, $this->snapshot());
        $activationBefore = $this->db->pdo()->prepare('SELECT accounting_activation_status FROM supplier WHERE id=?');
        $activationBefore->execute([$this->supplierId]);
        self::assertSame('none', $activationBefore->fetchColumn());
        self::assertSame(4, $dry['counts']['bank_transactions']);
        self::assertSame(1, $dry['counts']['skipped_bank_transactions']);
        self::assertSame(3, $dry['counts']['payments']);
        self::assertSame(5, $dry['counts']['requires_movement_review']);
        self::assertTrue($dry['partial']);
        self::assertContains(['table' => 'CBankap', 'count' => 1, 'reason' => 'skipped_bank_transactions'], $dry['not_transferred']);
        foreach ($dry['review_movements'] as $review) {
            self::assertNull($review['target_id']);
            self::assertNull($review['statement_id'] ?? null);
        }

        $actual = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($actual['ok'], json_encode($actual, JSON_UNESCAPED_UNICODE));
        $activation = $this->db->pdo()->prepare('SELECT accounting_enabled, accounting_starts_on, accounting_activation_status
            FROM supplier WHERE id=?');
        $activation->execute([$this->supplierId]);
        self::assertSame(['accounting_enabled' => 1, 'accounting_starts_on' => '2025-01-01',
            'accounting_activation_status' => 'completed'], $activation->fetch(\PDO::FETCH_ASSOC));
        self::assertSame(4, $actual['written']['bank_transactions']);
        self::assertSame(1, $actual['written']['cash_transactions']);
        self::assertSame(3, $actual['written']['payments']);
        self::assertSame(4, $this->scalar('SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=?'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM cash_documents WHERE supplier_id=?'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type IN ('cash','bank')"));
        $bankReview = array_values(array_filter($actual['review_movements'], static fn (array $r): bool => $r['kind'] === 'bank'))[0];
        self::assertGreaterThan(0, $bankReview['target_id']);
        self::assertGreaterThan(0, $bankReview['statement_id']);
        self::assertSame('BANK-4', $bankReview['document_no']);

        $after = $this->snapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($repeat['ok'], json_encode($repeat, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $repeat['written']['bank_transactions']);
        self::assertSame(0, $repeat['written']['cash_transactions']);
        self::assertSame(0, $repeat['written']['payments']);
        self::assertSame($after, $this->snapshot());
    }

    public function testRepeatRefreshesValidatedPaymentAfterDraftInvoiceWasIssued(): void
    {
        $tables = self::tables();
        $tables['Svfp'] = [];
        $backup = $this->backup($tables);
        $first = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($first['ok'], json_encode($first['errors'], JSON_UNESCAPED_UNICODE));
        $invoice = $this->issuedPaymentState();
        self::assertSame('draft', $invoice['status']);
        self::assertSame(121.0, (float) $invoice['paid_total']);
        self::assertSame((float) $invoice['amount_to_pay'], (float) $invoice['paid_total']);
        // Uživatel zkontroloval a vystavil převzatý koncept. Původní platba zůstala beze změny.
        $this->db->pdo()->prepare("UPDATE invoices SET status='issued',paid_at=NULL WHERE supplier_id=? AND id=?")
            ->execute([$this->supplierId, $invoice['id']]);
        $before = $this->paymentRecordSnapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($repeat['ok'], json_encode($repeat['errors'], JSON_UNESCAPED_UNICODE));
        $invoice = $this->issuedPaymentState();
        self::assertSame('paid', $invoice['status']);
        self::assertSame('2025-03-15', substr((string) $invoice['paid_at'], 0, 10));
        self::assertSame(121.0, (float) $invoice['paid_total']);
        self::assertSame(0, $repeat['written']['payments']);
        self::assertSame(0, $repeat['written']['bank_transactions']);
        self::assertSame(0, $repeat['written']['cash_transactions']);
        self::assertSame($before, $this->paymentRecordSnapshot(), 'Přepočet nesmí přidat platbu, pohyb, vazbu ani účetní zápis.');
    }

    public function testRepeatOfValidatedPaymentKeepsTrueDraftInvoiceForReview(): void
    {
        $tables = self::tables();
        $tables['Svfp'] = [];
        $backup = $this->backup($tables);
        $first = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($first['ok'], json_encode($first['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame('draft', $this->issuedPaymentState()['status']);
        $before = $this->paymentRecordSnapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($repeat['ok'], json_encode($repeat['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame('draft', $this->issuedPaymentState()['status']);
        self::assertSame(121.0, (float) $this->issuedPaymentState()['paid_total']);
        self::assertSame(0, $repeat['written']['payments']);
        self::assertSame($before, $this->paymentRecordSnapshot());
    }

    private function issuedPaymentState(): array
    {
        $query = $this->db->pdo()->prepare("SELECT id,status,paid_total,paid_at,amount_to_pay FROM invoices WHERE supplier_id=? AND varsymbol='VF-1'");
        $query->execute([$this->supplierId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function paymentRecordSnapshot(): array
    {
        $out = [];
        foreach (['invoice_payments', 'payment_matches', 'journal_entries', 'journal_entry_lines',
            'journal_entry_document_links', 'stereo_nx_import_map', 'cash_documents'] as $table) {
            $order = $table === 'stereo_nx_import_map' ? 'kind,source_key' : 'id';
            $q = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE supplier_id=? ORDER BY {$order}");
            $q->execute([$this->supplierId]);
            $out[$table] = $q->fetchAll(\PDO::FETCH_ASSOC);
        }
        $q = $this->db->pdo()->prepare('SELECT bt.* FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=? ORDER BY bt.id');
        $q->execute([$this->supplierId]);
        $out['bank_transactions'] = $q->fetchAll(\PDO::FETCH_ASSOC);
        return $out;
    }

    public function testReconciliationDetectsBalancedChangeToImportedJournal(): void
    {
        $backup = $this->backup(self::tables());
        $result = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($result['ok'], json_encode($result['errors']));
        self::assertTrue($result['reconciliation'][0]['ok']);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id
            SET l.amount=l.amount+1 WHERE e.supplier_id=? AND e.source_type<>'opening'")
            ->execute([$this->supplierId]);
        $container = Bootstrap::buildApp()->getContainer();
        $plan = $container->get(\MyInvoice\Service\Migration\StereoNx\StereoNxAccountingWriter::class)->prepare($backup);
        $checked = $container->get(\MyInvoice\Service\Migration\StereoNx\StereoNxReconciler::class)
            ->run($this->supplierId, $plan['accounting_plan']);
        self::assertFalse($checked['reconciliation'][0]['ok']);
        self::assertNotEmpty($checked['reconciliation'][0]['journal_diffs']);
        self::assertFalse($checked['criteria']['total']['K1']);
    }

    public function testConfirmedMappingRollsBackAndContinuesIntoNativeBankVatAndDocumentPosting(): void
    {
        $backup = $this->backup(self::mappingTables());
        $container = Bootstrap::buildApp()->getContainer();
        $proposal = $container->get(StereoNxAccountMappingService::class)->propose($backup, $this->supplierId);
        $configuration = ['accounts' => $proposal['accounts'], 'bank_accounts' => $proposal['bank_accounts'],
            'vat' => ['input' => '343.811', 'output' => '343.822', 'settlement' => '343.899', 'unresolved' => null]];
        $configuration['accounts']['518001'] = '518.777';
        $configuration['accounts']['221kb'] = '221.710';
        $configuration['accounts']['343021'] = '343.899';
        $configuration['accounts']['321001'] = '321.700';
        $configuration['bank_accounts']['B'] = '221.710';
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET vat_period='monthly' WHERE id=?")->execute([$this->supplierId]);

        $before = $this->mappingSnapshot();
        $dry = $this->importer->run($backup, $this->supplierId, $this->userId, true, true, $configuration);
        self::assertTrue($dry['ok'], json_encode($dry['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame($before, $this->mappingSnapshot(), 'Zkouška musí vrátit i bankovní analytiku a předkontace DPH.');
        $actual = $this->importer->run($backup, $this->supplierId, $this->userId, false, true, $configuration);
        self::assertTrue($actual['ok'], json_encode($actual['errors'], JSON_UNESCAPED_UNICODE));

        $mapped = $pdo->prepare("SELECT m.source_key, a.account_code FROM stereo_nx_import_map m
            JOIN chart_of_accounts a ON a.id=m.target_id AND a.supplier_id=m.supplier_id
            WHERE m.supplier_id=? AND m.kind='accounting_account' AND m.source_key IN ('518001','221kb','343021')
            ORDER BY m.source_key");
        $mapped->execute([$this->supplierId]);
        self::assertSame(['221kb' => '221.710', '343021' => '343.899', '518001' => '518.777'],
            $mapped->fetchAll(\PDO::FETCH_KEY_PAIR));
        $suffix = $pdo->prepare('SELECT analytic_suffix FROM supplier_bank_accounts WHERE supplier_id=?');
        $suffix->execute([$this->supplierId]);
        self::assertSame(['710'], $suffix->fetchAll(\PDO::FETCH_COLUMN));
        $chartBeforeResolver = $this->scalar('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id=?');
        $resolver = $container->get(BankAnalyticResolver::class);
        $ownBank = ['recipient_account' => '19-1000000005', 'recipient_bank' => '0100'];
        self::assertSame('221.710', $resolver->existingAnalyticCodeFor($this->supplierId, $ownBank));
        self::assertSame('221.710', $resolver->analyticCodeFor($this->supplierId, $ownBank));
        self::assertSame($chartBeforeResolver, $this->scalar('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id=?'));
        $bankLines = $pdo->prepare("SELECT DISTINCT a.account_code FROM journal_entry_lines l
            JOIN chart_of_accounts a ON a.id=l.account_id JOIN journal_entries e ON e.id=l.entry_id
            WHERE e.supplier_id=? AND a.account_code LIKE '221%' ORDER BY a.account_code");
        $bankLines->execute([$this->supplierId]);
        self::assertSame(['221.710'], $bankLines->fetchAll(\PDO::FETCH_COLUMN));

        $vatBalances = $pdo->prepare("SELECT a.account_code,
                SUM(CASE WHEN l.side='debit' THEN l.signed_amount ELSE -l.signed_amount END) AS net_debit
            FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id
            JOIN journal_entries e ON e.id=l.entry_id
            WHERE e.supplier_id=? AND e.entry_date >= '2025-03-01' AND e.entry_date < '2025-04-01'
                AND a.account_code IN ('343.811','343.822','343.899') GROUP BY a.account_code ORDER BY a.account_code");
        $vatBalances->execute([$this->supplierId]);
        $vatBalances = array_map('floatval', $vatBalances->fetchAll(\PDO::FETCH_KEY_PAIR));
        self::assertSame(['343.811' => 21.0, '343.822' => -21.0, '343.899' => 5.0], $vatBalances);
        $preview = $container->get(VatClearingService::class)->preview($this->supplierId, 2025, 3);
        self::assertSame(['input' => '343.811', 'output' => '343.822', 'settlement' => '343.899'], $preview['accounts']);
        self::assertSame($vatBalances['343.811'], $preview['input_vat']);
        self::assertSame(-$vatBalances['343.822'], $preview['output_vat']);
        self::assertSame(0.0, $preview['settlement']);

        $after = $this->mappingSnapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true, $configuration);
        self::assertTrue($repeat['ok'], json_encode($repeat['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame($after, $this->mappingSnapshot());
        $changed = $configuration;
        $changed['accounts']['518001'] = '518.778';
        $rejected = $this->importer->run($backup, $this->supplierId, $this->userId, false, true, $changed);
        self::assertFalse($rejected['ok']);
        self::assertContains('mapped_target_changed', array_column($rejected['errors'], 'code'));
        self::assertSame($after, $this->mappingSnapshot());

        $purchase = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND varsymbol='PF-1'");
        $purchase->execute([$this->supplierId]);
        $purchaseId = (int) $purchase->fetchColumn();
        self::assertGreaterThan(0, $purchaseId);
        $itemAccount = $pdo->prepare('SELECT expense_account_code FROM purchase_invoice_items WHERE purchase_invoice_id=?');
        $itemAccount->execute([$purchaseId]);
        self::assertSame(['518.777'], $itemAccount->fetchAll(\PDO::FETCH_COLUMN));
        $posting = $container->get(PostingService::class);
        $lines = $posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        self::assertContains('518.777', array_column($lines, 'account_code'));
        self::assertContains('343.811', array_column($lines, 'account_code'));
        self::assertContains('321.700', array_column($lines, 'account_code'));
        $pdo->prepare('UPDATE purchase_invoice_items SET unit_price_without_vat=200, total_without_vat=200,
            total_vat=42, total_with_vat=242 WHERE purchase_invoice_id=?')->execute([$purchaseId]);
        $pdo->prepare("UPDATE purchase_invoices SET total_without_vat=200, total_vat=42, total_with_vat=242,
            tax_date='2025-03-16' WHERE id=? AND supplier_id=?")->execute([$purchaseId, $this->supplierId]);
        $editedLines = $posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $expenseLines = array_values(array_filter($editedLines, static fn (array $line): bool => $line['account_code'] === '518.777'));
        self::assertCount(1, $expenseLines);
        self::assertSame(200.0, (float) $expenseLines[0]['amount']);
        self::assertContains('343.811', array_column($editedLines, 'account_code'));
        self::assertContains('321.700', array_column($editedLines, 'account_code'));
    }

    public function testChainedSourceAccountCodesDoNotTranslateNativeRulesAgainOnRepeat(): void
    {
        $tables = self::mappingTables();
        foreach (['518', '518.100'] as $code) {
            $tables['Lrozvrh'][] = ['Ucet' => $code, 'Nazev' => 'Syntetický řetězený účet ' . $code,
                'TypUctu' => 'N', 'Danovy' => true];
            $tables['Cdenik'][] = array_replace($tables['Cdenik'][2], [
                'Agenda' => 'U', 'DoklRada' => 'CHAIN', 'DoklCislo' => count($tables['Cdenik']),
                'UcetMD' => $code, 'UcetD' => '321001', 'Celkem' => 10.0]);
        }
        $backup = $this->backup($tables);
        $container = Bootstrap::buildApp()->getContainer();
        $proposal = $container->get(StereoNxAccountMappingService::class)->propose($backup, $this->supplierId);
        $configuration = ['accounts' => $proposal['accounts'], 'bank_accounts' => $proposal['bank_accounts'], 'vat' => $proposal['vat']];
        $configuration['accounts']['518'] = '518.100';
        $configuration['accounts']['518.100'] = '518.200';
        $rules = $container->get(PostingRuleRepository::class);
        self::assertSame('518', $rules->resolve($this->supplierId, 'invoice.services.received')['debit_account_code']);
        $first = $this->importer->run($backup, $this->supplierId, $this->userId, false, true, $configuration);
        self::assertTrue($first['ok'], json_encode($first['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame('518.100', $rules->resolve($this->supplierId, 'invoice.services.received')['debit_account_code']);
        $before = $this->mappingSnapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true, $configuration);
        self::assertTrue($repeat['ok'], json_encode($repeat['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame('518.100', $rules->resolve($this->supplierId, 'invoice.services.received')['debit_account_code'],
            '518.100 je již cílové pravidlo; opakování z něj nesmí udělat zdrojové 518.100 → 518.200.');
        self::assertSame($before, $this->mappingSnapshot());
    }

    public function testManualVatMappingRetainsProvenInputOutputAndSettlementInNativeRules(): void
    {
        $backup = $this->backup(self::mappingTables(true));
        $container = Bootstrap::buildApp()->getContainer();
        $proposal = $container->get(StereoNxAccountMappingService::class)->propose($backup, $this->supplierId);
        $configuration = ['accounts' => $proposal['accounts'], 'bank_accounts' => $proposal['bank_accounts'], 'vat' => null];
        foreach (['343101' => '343.811', '343201' => '343.822', '343901' => '343.899'] as $source => $target) {
            $configuration['accounts'][$source] = $target;
        }
        $configuration['accounts']['321001'] = '321.700';
        $before = $this->mappingSnapshot();
        $dry = $this->importer->run($backup, $this->supplierId, $this->userId, true, true, $configuration);
        self::assertTrue($dry['ok'], json_encode($dry['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame($before, $this->mappingSnapshot());
        $actual = $this->importer->run($backup, $this->supplierId, $this->userId, false, true, $configuration);
        self::assertTrue($actual['ok'], json_encode($actual['errors'], JSON_UNESCAPED_UNICODE));
        $rules = $container->get(PostingRuleRepository::class);
        self::assertSame('343.811', $rules->resolve($this->supplierId, 'invoice.vat.input')['debit_account_code']);
        self::assertSame('343.822', $rules->resolve($this->supplierId, 'invoice.vat.output')['credit_account_code']);
        self::assertSame('343.899', $rules->resolve($this->supplierId, 'vat.clearing.output')['credit_account_code']);
        $purchase = $this->db->pdo()->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND varsymbol='PF-1'");
        $purchase->execute([$this->supplierId]);
        $lines = $container->get(PostingService::class)->buildFromPurchaseInvoice($this->supplierId, (int) $purchase->fetchColumn());
        self::assertContains('343.811', array_column($lines, 'account_code'));
        self::assertContains('321.700', array_column($lines, 'account_code'));
        $after = $this->mappingSnapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true, $configuration);
        self::assertTrue($repeat['ok'], json_encode($repeat['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame($after, $this->mappingSnapshot());
    }

    public function testNegativeExpensesPreserveCreditSettlementReversalAndRedStornoLinks(): void
    {
        $tables = self::tables();
        foreach (['ZaklDPHz', 'DPHz', 'Celkem'] as $field) $tables['SPFH'][0][$field] *= -1;
        $tables['SPFH'][0]['TypDokladu'] = 'D';
        $tables['CBankap'][1]['Castka'] = $tables['CBankap'][1]['CastkaVlastni'] = -121.0;
        $tables['Cpz'][1]['Celkem'] = $tables['Cpz'][1]['Uhrazeno'] = -121.0;
        $tables['CBankap'][] = array_replace($tables['CBankap'][2],
            ['Klic' => 6, 'Castka' => -21.0, 'CastkaVlastni' => -21.0]);
        $tables['Cpz'][2]['Uhrazeno'] = 79.0;
        $tables['Cpz'][2]['UhrazenoVse'] = false;
        foreach ([1, 2, 5] as $index) {
            $row = $tables['CBankap'][$index];
            $tables['Cdenik'][] = array_replace($tables['Cdenik'][2], [
                'Agenda' => 'B', 'DoklRada' => 'B', 'DoklCislo' => 1, 'Klic' => $row['Klic'],
                'Poradi' => 0, 'KdyUcPripad' => '2025-03-15', 'Rok' => 2025, 'Mesic' => 3,
                'UcetMD' => '321', 'UcetD' => '221kb', 'Celkem' => $row['Castka'],
            ]);
        }
        $backup = $this->backup($tables);
        $before = $this->snapshot();
        $dry = $this->importer->run($backup, $this->supplierId, $this->userId, true, true);
        self::assertTrue($dry['ok'], json_encode($dry['errors']));
        self::assertSame($before, $this->snapshot());
        $actual = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($actual['ok'], json_encode($actual['errors']));
        self::assertSame(4, $actual['written']['payments']);
        $pdo = $this->db->pdo();
        $query = $pdo->prepare('SELECT pm.amount, bt.amount AS bank_amount, pi.paid_amount_invoice_ccy AS paid
            FROM payment_matches pm JOIN bank_transactions bt ON bt.id=pm.bank_transaction_id
            JOIN purchase_invoices pi ON pi.id=pm.purchase_invoice_id
            WHERE pm.supplier_id=? ORDER BY bt.id');
        $query->execute([$this->supplierId]);
        self::assertSame([
            ['amount' => '121.00', 'bank_amount' => '121.00', 'paid' => '121.0000'],
            ['amount' => '100.00', 'bank_amount' => '-100.00', 'paid' => '79.0000'],
            ['amount' => '21.00', 'bank_amount' => '21.00', 'paid' => '79.0000'],
        ], $query->fetchAll(\PDO::FETCH_ASSOC));
        $lines = $pdo->prepare("SELECT a.account_code, l.side, l.signed_amount
            FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id
            JOIN chart_of_accounts a ON a.id=l.account_id
            JOIN bank_transactions bt ON bt.id=e.source_id AND e.source_type='bank'
            JOIN payment_matches pm ON pm.bank_transaction_id=bt.id AND pm.supplier_id=e.supplier_id
            WHERE e.supplier_id=? AND l.is_red_storno=1 ORDER BY bt.id, l.side");
        $lines->execute([$this->supplierId]);
        self::assertSame([
            ['account_code' => '321', 'side' => 'debit', 'signed_amount' => '-121.00'],
            ['account_code' => '221kb', 'side' => 'credit', 'signed_amount' => '-121.00'],
            ['account_code' => '321', 'side' => 'debit', 'signed_amount' => '-21.00'],
            ['account_code' => '221kb', 'side' => 'credit', 'signed_amount' => '-21.00'],
        ], $lines->fetchAll(\PDO::FETCH_ASSOC));
        self::assertTrue($actual['reconciliation'][0]['ok']);
        $after = $this->snapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($repeat['ok'], json_encode($repeat['errors']));
        self::assertSame(0, $repeat['written']['payments']);
        self::assertSame($after, $this->snapshot());
    }

    public function testHistoricalPayrollSharesDryRunTransactionAndDoesNotPostAgain(): void
    {
        $this->assertHistoricalPayroll(false);
    }

    public function testHistoricalPayrollInitializesMissingStartAndRollsItBackInDryRun(): void
    {
        $this->assertHistoricalPayroll(true);
    }

    private function assertHistoricalPayroll(bool $missingStart): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        if (!$missingStart) $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
            VALUES (?, "setup", "2026-02-01", ?, NOW())')->execute([$this->supplierId, $this->userId]);
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
            VALUES (?, "SYN", "Syntetická účtárna", "1234567890", 1)')->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_office_registration_versions
            (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
            VALUES (?, ?, "2020-01-01", "1234567890", "synthetic:stereo")')->execute([$this->supplierId, $officeId]);
        $pdo->prepare('INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
            VALUES (?, ?, "P")')->execute([$this->supplierId, $officeId]);
        $payroll = SyntheticStereoNxPayrollTables::tables();
        $rates = $payroll['Gparrok'];
        unset($payroll['Gparrok']);
        $payroll['MZAMEST'][0] += ['KrestniJmeno' => 'Jana', 'Prijmeni' => 'Vzorová',
            'Vyrazen' => false, 'TydUvazHod' => 40.0, 'MesTarif' => 10000.0, 'HodTarif' => 0.0];
        $backup = $this->backup(array_replace(self::tables(), $payroll));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->files[array_key_last($this->files)]));
        $zip->addFromString('DataPrg/GDATA/Gparrok.nx1', SyntheticNx1Archive::table($rates));
        $zip->close();

        $before = $this->snapshot();
        $dry = $this->importer->run($backup, $this->supplierId, $this->userId, true, true);
        self::assertTrue($dry['ok'], json_encode($dry['errors']));
        self::assertSame(1, $dry['written']['historical_payroll_created']);
        if ($missingStart) {
            self::assertSame('2026-02', $dry['payroll_setup']['start_period']);
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_module_state WHERE supplier_id=?'));
        }
        self::assertSame($before, $this->snapshot());
        $actual = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($actual['ok'], json_encode($actual['errors']));
        self::assertSame(1, $actual['written']['historical_payroll_created']);
        $startQuery = $pdo->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id=?');
        $startQuery->execute([$this->supplierId]);
        self::assertSame('2026-02-01', $startQuery->fetchColumn());
        self::assertSame(0, $actual['written']['historical_payroll_skipped']);
        self::assertNotContains('historical_payroll_skipped', array_column($actual['warnings'], 'code'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_runs WHERE supplier_id=?'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='payroll'"));
        $after = $this->snapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($repeat['ok'], json_encode($repeat['errors']));
        self::assertSame(1, $repeat['written']['historical_payroll_existing']);
        self::assertSame($after, $this->snapshot());
    }

    public function testSourceMovementEntriesSurviveRepeatAndLaterBackfill(): void
    {
        $tables = self::tables();
        $template = $tables['Cdenik'][2];
        foreach (['CBankap' => 'B', 'CPokl' => 'P'] as $table => $agenda) {
            foreach ($tables[$table] as $row) {
                if ($row['Castka'] == 0 || ($row['Kurz'] ?? 1) != 1) continue;
                $entry = array_replace($template, [
                    'Agenda' => $agenda, 'DoklRada' => $row['DoklRada'], 'DoklCislo' => $row['DoklCislo'],
                    'Klic' => $row['Klic'] ?? 0, 'Poradi' => 0, 'KdyUcPripad' => $row['KdyUcPripad'],
                    'Rok' => (int) substr($row['KdyUcPripad'], 0, 4), 'Mesic' => (int) substr($row['KdyUcPripad'], 5, 2),
                    'Celkem' => (float) $row['Castka'], 'DPH' => 0.0,
                ]);
                $tables['Cdenik'][] = $entry;
                if ($agenda === 'P') {
                    // Split cash posting: both source entries must attach to the same document.
                    $tables['Cdenik'][array_key_last($tables['Cdenik'])]['Celkem'] = $entry['Celkem'] / 2;
                    $entry['Celkem'] /= 2;
                    $entry['Poradi'] = 1;
                    $tables['Cdenik'][] = $entry;
                }
            }
        }
        $backup = $this->backup($tables);
        $result = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame(4, $this->scalar("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='bank'"));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM cash_documents WHERE supplier_id=? AND journal_entry_id IS NOT NULL'));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM journal_entry_document_links WHERE supplier_id=? AND doc_type='cash'"));
        $before = $this->snapshot();
        $repeat = $this->importer->run($backup, $this->supplierId, $this->userId, false, true);
        self::assertTrue($repeat['ok'], json_encode($repeat));
        self::assertSame($before, $this->snapshot());
        $container = Bootstrap::buildApp()->getContainer();
        $pending = $container->get(\MyInvoice\Service\Accounting\Activation\PendingBackfillCounter::class)->count($this->supplierId);
        self::assertSame(0, $pending['bank_transactions']);
        self::assertSame(0, $pending['cash_documents']);
        $bank = $container->get(\MyInvoice\Service\Accounting\Bank\BankPostingBackfill::class)
            ->run($this->supplierId, null, true, false, $this->userId);
        self::assertSame(0, $bank['posted']);
        self::assertSame([], $bank['errors']);
        self::assertSame($before, $this->snapshot());
    }

    public function testUnlinkedMovementsCannotBePickedUpByLaterBackfill(): void
    {
        $report = $this->importer->run($this->backup(self::tables()), $this->supplierId, $this->userId, false, true);
        self::assertTrue($report['ok'], json_encode($report));
        $pending = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Service\Accounting\Activation\PendingBackfillCounter::class)
            ->count($this->supplierId);
        self::assertSame(0, $pending['bank_transactions'], 'A later activation must not post a second copy of imported source entries.');
        self::assertSame(0, $pending['cash_documents']);
    }

    public function testClosedDateOfSkippedMovementStillBlocksTheRun(): void
    {
        $tables = self::tables();
        $tables['CBankap'][4]['KdyUcPripad'] = '2024-12-31';
        $period = Bootstrap::buildApp()->getContainer()->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, 2024, '2024-01-01', '2024-12-31');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE id=? AND supplier_id=?")
            ->execute([$period, $this->supplierId]);

        $before = $this->snapshot();
        $report = $this->importer->run($this->backup($tables), $this->supplierId, $this->userId, false, true);
        self::assertFalse($report['ok']);
        self::assertContains('document_date_locked', array_column($report['errors'], 'code'));
        self::assertSame($before, $this->snapshot());
    }

    /** @return array<string,list<array<string,mixed>>> */
    private static function mappingTables(bool $separateVatSources = false): array
    {
        $tables = self::tables();
        $vatCodes = $separateVatSources ? ['343101', '343201', '343901'] : ['343021', '343021', '343021'];
        foreach ([['518001', 'Syntetický náklad', 'N'], ['602007', 'Syntetický výnos', 'V'],
            ['311', 'Syntetické pohledávky', 'A'], ['321001', 'Syntetický závazek', 'P'],
            ...array_map(static fn (string $code): array => [$code, 'Syntetická DPH ' . $code, 'R'], array_unique($vatCodes))]
            as [$code, $name, $type]) {
            $tables['Lrozvrh'][] = ['Ucet' => $code, 'Nazev' => $name, 'TypUctu' => $type, 'Danovy' => true];
        }
        foreach ($tables['Cdenik'] as &$entry) {
            if ($entry['UcetD'] === '321') $entry['UcetD'] = '321001';
        }
        unset($entry);
        $tables['Spfp'] = [[...$tables['Svfp'][0], 'DoklSRada' => 'PF', 'DoklSCislo' => 1]];
        $template = $tables['Cdenik'][2];
        foreach ([['PF', 'PF', 1, 1, '518001', '321001', 100.0],
            ['PF', 'PF', 1, 2, $vatCodes[0], '321001', 21.0],
            ['VF', 'VF', 1, 1, '311', '602007', 100.0],
            ['VF', 'VF', 1, 2, '311', $vatCodes[1], 21.0],
            ['B', 'B', 2, 1, $vatCodes[2], '221kb', 5.0]] as [$agenda, $series, $number, $order, $debit, $credit, $amount]) {
            $tables['Cdenik'][] = array_replace($template, ['Agenda' => $agenda, 'DoklRada' => $series,
                'DoklCislo' => $number, 'Klic' => 1, 'Poradi' => $order,
                'KdyUcPripad' => '2025-03-15', 'Rok' => 2025, 'Mesic' => 3,
                'UcetMD' => $debit, 'UcetD' => $credit, 'Celkem' => $amount]);
        }
        return $tables;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private static function tables(): array
    {
        $documents = SyntheticStereoNxTables::tables();
        $tables = array_replace($documents, SyntheticStereoNxAccountingTables::tables());
        $tables['LAdresy'] = $documents['LAdresy'];
        $tables['LFirmaUc'][0]['BaUcet'] = '19-1000000005';
        $tables['LFirmaUc'][0]['KodBanky'] = '0100';
        $tables['CBanka'][0]['Kurz'] = 1.0; $tables['CBanka'][0]['KurzMn'] = 1.0;
        foreach ($tables['CBankap'] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        $tables['CBankap'][3]['DPHz'] = 1.0;
        $tables['CBankap'][3]['DokladS'] = 'BANK-4';
        $tables['CBankap'][4]['Kurz'] = 25.0;
        $tables['CBankap'][4]['CastkaVlastni'] = 1250.0;
        foreach ($tables['CPokl'] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        foreach ($tables['CPZZ'] as &$row) $row['Mena'] = 'Kč';
        unset($row);
        foreach ($tables['Cpz'] as &$row) $row['Agenda'] = $row['DoklSRada'];
        unset($row);
        return $tables;
    }

    /** @param array<string,list<array<string,mixed>>> $tables */
    private function backup(array $tables): StereoNxBackup
    {
        $path = sys_get_temp_dir() . '/stereo-accounting-orchestration-' . bin2hex(random_bytes(6)) . '.zip';
        SyntheticNx1Archive::write($path, $tables, SyntheticStereoNxAccountingTables::identity());
        $this->files[] = $path;
        return StereoNxBackup::open($path, 0);
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->db->pdo()->prepare($sql); $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> */
    private function snapshot(): array
    {
        $out = [];
        foreach (['clients', 'invoices', 'purchase_invoices', 'bank_statements', 'cash_documents',
            'journal_entries', 'stereo_nx_import_map', 'payroll_employees', 'payroll_employments',
            'payroll_migration_reference_totals'] as $table) {
            $out[$table] = $this->scalar("SELECT COUNT(*) FROM {$table} WHERE supplier_id=?");
        }
        return $out;
    }

    /** Nastavení včetně obsahu musí při zkoušce i odmítnutém převodu zůstat shodné. */
    private function mappingSnapshot(): array
    {
        $out = $this->snapshot();
        foreach (['posting_rules', 'supplier_bank_accounts', 'chart_of_accounts'] as $table) {
            $stmt = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE supplier_id=? ORDER BY id");
            $stmt->execute([$this->supplierId]);
            $out[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $out;
    }
}
