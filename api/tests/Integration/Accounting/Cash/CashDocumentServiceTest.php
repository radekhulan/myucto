<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy CashDocumentService (mini-epic POKLADNA #14, §7.2). Podvojnost
 * per účel, přesná rovnost DPH rekapitulace (O6), řady, idempotence dvojkliku,
 * úhrady FV/FP, storno + cleanup, zavřené období, tenant izolace, převody,
 * zůstatek/warning. Vše v transakci → rollback.
 */
#[Group('integration')]
final class CashDocumentServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private CashDocumentService $service;
    private CashRegisterService $registers;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private DocumentLockService $documentLocks;
    private PostingService $posting;
    private DocumentSeriesService $documentSeries;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db        = $container->get(Connection::class);
            $this->service   = $container->get(CashDocumentService::class);
            $this->registers = $container->get(CashRegisterService::class);
            $this->journal   = $container->get(JournalEntryRepository::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $this->documentLocks = $container->get(DocumentLockService::class);
            $this->posting   = $container->get(PostingService::class);
            $this->documentSeries = $container->get(DocumentSeriesService::class);
            $seeder          = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testDoubleEntryWithVatBreakdown(): void
    {
        $reg = $this->makeRegister();

        $sale = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'partner_dic' => 'CZ12345678',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
        ], $reg), $this->userId);
        self::assertSame('posted', $sale['status']);

        $byAcc = $this->linesByAccountCode($sale['journal_entry_id']);
        self::assertEqualsWithDelta(1210.00, $byAcc['211']['debit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['602']['credit'], 0.001);
        self::assertEqualsWithDelta(210.00, $byAcc['343.200']['credit'], 0.001);

        $purchase = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($purchase['journal_entry_id']);
        self::assertEqualsWithDelta(1000.00, $byAcc['501']['debit'], 0.001);
        self::assertEqualsWithDelta(210.00, $byAcc['343.100']['debit'], 0.001);
        self::assertEqualsWithDelta(1210.00, $byAcc['211']['credit'], 0.001);
    }

    public function testPurchasePostsExplicitExpenseAnalyticWithoutChangingVat(): void
    {
        $reg = $this->makeRegister();
        $this->makeExpenseAccount('518.200');
        $purchase = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'counter_account_code' => '518.200',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
        ], $reg), $this->userId);

        self::assertSame([
            '518.200' => ['debit' => 1000.0, 'credit' => 0.0],
            '343.100' => ['debit' => 210.0, 'credit' => 0.0],
            '211' => ['debit' => 0.0, 'credit' => 1210.0],
        ], $this->linesByAccountCode($purchase['journal_entry_id']));
    }

    public function testPurchaseWithoutExpenseOverrideKeepsDefaultAndAllowsExplicitSynthetic(): void
    {
        $reg = $this->makeRegister();
        foreach ([[], ['counter_account_code' => null], ['counter_account_code' => '501']] as $override) {
            $purchase = $this->service->create($this->supplierId, $this->doc(array_merge([
                'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 100.00,
            ], $override), $reg), $this->userId);
            self::assertSame([
                '501' => ['debit' => 100.0, 'credit' => 0.0],
                '211' => ['debit' => 0.0, 'credit' => 100.0],
            ], $this->linesByAccountCode($purchase['journal_entry_id']));
        }
    }

    public function testPurchaseWithoutExpenseOverrideKeepsConfiguredPurchaseRule(): void
    {
        $reg = $this->makeRegister();
        $this->makeExpenseAccount('518.100');
        (new PostingRuleRepository($this->db))->upsertOverride($this->supplierId, 'cash.purchase', '518.100', '211', 'Výchozí nákup');
        foreach ([[], ['counter_account_code' => null]] as $override) {
            $purchase = $this->service->create($this->supplierId, $this->doc(array_merge([
                'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 100.00,
            ], $override), $reg), $this->userId);
            self::assertSame([
                '518.100' => ['debit' => 100.0, 'credit' => 0.0],
                '211' => ['debit' => 0.0, 'credit' => 100.0],
            ], $this->linesByAccountCode($purchase['journal_entry_id']));
        }
    }

    public function testPurchaseRejectsForeignInactiveAndNonExpenseAccounts(): void
    {
        $reg = $this->makeRegister();
        $foreignSupplier = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        $this->makeExpenseAccount('518.987', true, $foreignSupplier);
        $this->makeExpenseAccount('518.299', false);
        $this->makeExpenseAccount('518.298', true, null, 'asset');

        foreach (['518.987', '518.299', '518.298', '321', '602'] as $code) {
            try {
                $this->service->create($this->supplierId, $this->doc([
                    'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 100.00,
                    'counter_account_code' => $code,
                ], $reg), $this->userId);
                self::fail('Neplatný nákladový účet ' . $code . ' musí selhat.');
            } catch (CashException $e) {
                self::assertSame('counter_account_invalid', $e->errorCode);
            }
            self::assertSame(0, $this->countDocuments($reg));
        }
    }

    public function testPurchaseDraftRetainsExpenseOverrideWhenOmittedAndClearsExplicitNull(): void
    {
        $reg = $this->makeRegister();
        $this->makeExpenseAccount('518.200');
        foreach ([[], ['counter_account_code' => null]] as $update) {
            $draft = $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 100.00,
                'counter_account_code' => '518.200', 'post' => false,
            ], $reg), $this->userId);
            $this->service->updateDraft($this->supplierId, $draft['id'], $update + ['description' => 'Upravený nákup']);
            $posted = $this->service->post($this->supplierId, $draft['id'], $this->userId);
            $expense = $update === [] ? '518.200' : '501';
            self::assertSame([
                $expense => ['debit' => 100.0, 'credit' => 0.0],
                '211' => ['debit' => 0.0, 'credit' => 100.0],
            ], $this->linesByAccountCode($posted['journal_entry_id']));
        }
    }

    public function testPdfPostingSerializesRedStornoAmountsWithTheirAccountingSign(): void
    {
        $reg = $this->makeRegister();
        $cash = $this->service->create($this->supplierId, $this->sale($reg, 100.00), $this->userId);
        $entryId = (int) $cash['journal_entry_id'];

        $rewritten = $this->posting->postDocument($this->supplierId, 'cash', (int) $cash['id'], [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00, 'is_red_storno' => true],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 100.00, 'is_red_storno' => true],
        ], [
            'entry_date' => self::YEAR . '-06-15',
            'description' => 'Červené storno pokladního zápisu',
            'posted' => true,
            'posted_by' => $this->userId,
            'user_id' => $this->userId,
        ]);
        self::assertSame($entryId, $rewritten);

        $posting = $this->service->pdfData($this->supplierId, (int) $cash['id'])['posting'];
        self::assertSame([
            ['side' => 'debit', 'amount' => -100.0, 'account_code' => '211'],
            ['side' => 'credit', 'amount' => -100.0, 'account_code' => '602'],
        ], array_map(static fn (array $line): array => [
            'side' => $line['side'],
            'amount' => $line['amount'],
            'account_code' => $line['account_code'],
        ], $posting));
    }

    public function testExactVatEqualityInsteadOfRounding(): void
    {
        $reg = $this->makeRegister();
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1000.00, 'vat_mode' => 'vat',
                'vat_lines' => [['vat_rate' => 21, 'base_amount' => 826.45, 'vat_amount' => 173.54]],
            ], $reg), $this->userId);
            self::fail('Nesouhlasící Σ(base+vat) musí selhat.');
        } catch (CashException $e) {
            self::assertSame('vat_lines_mismatch', $e->errorCode);
        }
        self::assertSame(0, $this->countDocuments($reg), 'Při chybě se nic nezaúčtuje ani neuloží.');
    }

    public function testMultiRateBreakdown(): void
    {
        $reg = $this->makeRegister();
        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 2330.00, 'vat_mode' => 'vat',
            'vat_lines' => [
                ['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00],
                ['vat_rate' => 12, 'base_amount' => 1000.00, 'vat_amount' => 120.00],
            ],
        ], $reg), $this->userId);

        $entry = $this->journal->find($res['journal_entry_id'], $this->supplierId);
        $lines343 = array_filter($entry['lines'], fn ($l) => $this->code((int) $l['account_id']) === '343.200');
        self::assertCount(2, $lines343, 'Dva řádky 343 (per sazba).');
        $byAcc = $this->linesByAccountCode($res['journal_entry_id']);
        self::assertEqualsWithDelta(330.00, $byAcc['343.200']['credit'], 0.001);
        self::assertEqualsWithDelta(2000.00, $byAcc['602']['credit'], 0.001);
    }

    public function testVatRateValidation(): void
    {
        $reg = $this->makeRegister();
        // Sazba 0.
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1000.00, 'vat_mode' => 'vat',
                'vat_lines' => [['vat_rate' => 0, 'base_amount' => 1000.00, 'vat_amount' => 0.00]],
            ], $reg), $this->userId);
            self::fail('Sazba 0 musí selhat.');
        } catch (CashException $e) {
            self::assertSame('vat_rate_invalid', $e->errorCode);
        }
        // Sazba mimo číselník roku.
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1150.00, 'vat_mode' => 'vat',
                'vat_lines' => [['vat_rate' => 15, 'base_amount' => 1000.00, 'vat_amount' => 150.00]],
            ], $reg), $this->userId);
            self::fail('Sazba mimo číselník musí selhat.');
        } catch (CashException $e) {
            self::assertSame('vat_rate_invalid', $e->errorCode);
        }
    }

    public function testVatOnlyForSaleOrPurchase(): void
    {
        $reg = $this->makeRegister();
        foreach ([['other', 'in', ['counter_account_code' => '668']], ['transfer', 'in', []]] as [$purpose, $type, $extra]) {
            try {
                $this->service->create($this->supplierId, $this->doc(array_merge([
                    'purpose' => $purpose, 'doc_type' => $type, 'total_amount' => 1210.00, 'vat_mode' => 'vat',
                    'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
                ], $extra), $reg), $this->userId);
                self::fail('DPH je povolena jen pro sale/purchase (' . $purpose . ').');
            } catch (CashException $e) {
                self::assertSame('vat_purpose_not_allowed', $e->errorCode);
            }
        }
    }

    public function testPurchaseVatOver10kRejected(): void
    {
        $reg = $this->makeRegister();
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 10000.01, 'vat_mode' => 'vat',
                'vat_lines' => [['vat_rate' => 21, 'base_amount' => 8264.46, 'vat_amount' => 1735.55]],
            ], $reg), $this->userId);
            self::fail('Daňový nákup nad 10 000 musí selhat.');
        } catch (CashException $e) {
            self::assertSame('purchase_vat_over_10k', $e->errorCode);
        }
        // Přesně 10 000 projde — § 30 ZDPH je inkluzivní a KH B.2 je až NAD práh.
        $atThreshold = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 10000.00, 'vat_mode' => 'vat',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 8264.46, 'vat_amount' => 1735.54]],
        ], $reg), $this->userId);
        self::assertSame('posted', $atThreshold['status']);
        // Těsně pod prahem projde.
        $ok = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 9999.99, 'vat_mode' => 'vat',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 8264.45, 'vat_amount' => 1735.54]],
        ], $reg), $this->userId);
        self::assertSame('posted', $ok['status']);
    }

    public function testSeriesNumbering(): void
    {
        $reg = $this->makeRegister();
        $a = $this->service->create($this->supplierId, $this->sale($reg, 1000.00), $this->userId);
        $b = $this->service->create($this->supplierId, $this->sale($reg, 1000.00), $this->userId);
        self::assertSame('PPD-2099-0001', $a['doc_number']);
        self::assertSame('PPD-2099-0002', $b['doc_number']);

        $vpd = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 500.00,
        ], $reg), $this->userId);
        self::assertSame('VPD-2099-0001', $vpd['doc_number'], 'VPD řada nezávislá na PPD.');

        $draft = $this->service->create($this->supplierId, array_merge($this->sale($reg, 300.00), ['post' => false]), $this->userId);
        self::assertNull($draft['doc_number'], 'Draft číslo nemá.');
        self::assertSame('draft', $draft['status']);
    }

    public function testCreateAndPostInOneTransactionAndDraftGuards(): void
    {
        $reg = $this->makeRegister();
        $posted = $this->service->create($this->supplierId, $this->sale($reg, 500.00), $this->userId);
        self::assertNotNull($posted['doc_number']);
        self::assertNotNull($posted['journal_entry_id']);

        $draft = $this->service->create($this->supplierId, array_merge($this->sale($reg, 500.00), ['post' => false]), $this->userId);
        self::assertNull($draft['journal_entry_id']);

        // PUT/DELETE fungují jen na draft.
        $this->service->updateDraft($this->supplierId, $draft['id'], $this->sale($reg, 700.00));
        $reloaded = $this->service->get($this->supplierId, $draft['id']);
        self::assertEqualsWithDelta(700.00, $reloaded['total_amount'], 0.001);

        try {
            $this->service->updateDraft($this->supplierId, $posted['id'], $this->sale($reg, 999.00));
            self::fail('Úprava zaúčtovaného dokladu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('doc_not_draft', $e->errorCode);
        }
        try {
            $this->service->deleteDraft($this->supplierId, $posted['id']);
            self::fail('Smazání zaúčtovaného dokladu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('doc_not_draft', $e->errorCode);
        }
    }

    public function testIdempotentDoublePost(): void
    {
        $reg = $this->makeRegister();
        $draft = $this->service->create($this->supplierId, array_merge($this->sale($reg, 500.00), ['post' => false]), $this->userId);

        $first = $this->service->post($this->supplierId, $draft['id'], $this->userId);
        $entry = $this->journal->find($first['journal_entry_id'], $this->supplierId);
        $versionBefore = (int) $entry['row_version'];

        $second = $this->service->post($this->supplierId, $draft['id'], $this->userId);
        self::assertSame($first['doc_number'], $second['doc_number'], 'Dvojklik → stejné číslo.');
        self::assertSame($first['journal_entry_id'], $second['journal_entry_id'], 'Dvojklik → stejný zápis.');

        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}
              AND source_type = 'cash' AND source_id = {$draft['id']}"
        )->fetchColumn();
        self::assertSame(1, $count, 'Právě jeden zápis pro doklad.');

        $entryAfter = $this->journal->find($first['journal_entry_id'], $this->supplierId);
        self::assertSame($versionBefore, (int) $entryAfter['row_version'], 'Zápis se nepřebudoval (žádný rebuild).');
    }

    public function testPartialInvoicePayment(): void
    {
        $reg = $this->makeRegister();
        $client = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-100', $client, 10000.00, $this->currencyId);

        $p1 = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 4000.00, 'invoice_id' => $invoiceId,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($p1['journal_entry_id']);
        self::assertEqualsWithDelta(4000.00, $byAcc['211']['debit'], 0.001);
        self::assertEqualsWithDelta(4000.00, $byAcc['311']['credit'], 0.001);

        $inv = $this->invoiceRow($invoiceId);
        self::assertEqualsWithDelta(4000.00, (float) $inv['paid_total'], 0.001);
        self::assertNotSame('paid', $inv['status']);
        $payments = $this->paymentRows($invoiceId);
        self::assertCount(1, $payments);
        self::assertSame('cash', $payments[0]['source']);

        $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 6000.00, 'invoice_id' => $invoiceId,
        ], $reg), $this->userId);
        $inv = $this->invoiceRow($invoiceId);
        self::assertEqualsWithDelta(10000.00, (float) $inv['paid_total'], 0.001);
        self::assertSame('paid', $inv['status']);
    }

    /**
     * Pokladní cesta musí zakládat TÉŽE doklady jako banka — proto se režim firmy
     * nastavuje výslovně: test hlídá shodu obou cest, ne to, co je zrovna výchozí.
     * Výchozí hodnota je od migrace 1567 `always_tax_document` (§ 20a + § 28), takže
     * pro větev s vyúčtovací fakturou se musí přepnout.
     */
    public function testCashProformaPaymentCreatesSameDraftDocumentsAsBank(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET proforma_payment_document = ? WHERE id = ?')
            ->execute([\MyInvoice\Service\Invoice\ProformaPaymentDocuments::MODE_FINAL_ON_FULL_PAYMENT, $this->supplierId]);
        $reg = $this->makeRegister();
        $client = $this->client('Odběratel zálohy s.r.o.', true, false);
        $proformaId = $this->proforma('PRO-2099-EP8', $client, 12100.00);

        $partial = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 6050.00, 'invoice_id' => $proformaId,
        ], $reg), $this->userId);
        self::assertSame('posted', $partial['status']);
        $paymentId = (int) $this->db->pdo()->query(
            "SELECT invoice_payment_id FROM cash_documents WHERE id = {$partial['id']}"
        )->fetchColumn();
        self::assertGreaterThan(0, $paymentId);

        $taxDocId = $this->db->pdo()->query(
            "SELECT tax_document_invoice_id FROM invoice_payments WHERE id = {$paymentId}"
        )->fetchColumn();
        if ($taxDocId !== false && $taxDocId !== null) {
            self::assertSame('draft', $this->db->pdo()->query(
                'SELECT status FROM invoices WHERE id = ' . (int) $taxDocId
            )->fetchColumn());
        }

        $full = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 6050.00, 'invoice_id' => $proformaId,
        ], $reg), $this->userId);
        self::assertSame('posted', $full['status']);
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoices WHERE parent_invoice_id = {$proformaId}
              AND invoice_type = 'invoice' AND status = 'draft'"
        )->fetchColumn(), 'Plná hotovostní úhrada vytvoří právě jeden draft finálu.');
    }

    public function testInvoicePaymentOverpayAndForeignCurrency(): void
    {
        $reg = $this->makeRegister();
        $client = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-101', $client, 1000.00, $this->currencyId);
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 2000.00, 'invoice_id' => $invoiceId,
            ], $reg), $this->userId);
            self::fail('Přeplatek musí selhat.');
        } catch (CashException $e) {
            self::assertSame('amount_exceeds_remaining', $e->errorCode);
        }

        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Měna EUR není v číselníku.');
        }
        $eurInvoice = $this->saleInvoice('FV-2099-102', $client, 100.00, $eurId);
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 100.00, 'invoice_id' => $eurInvoice,
            ], $reg), $this->userId);
            self::fail('Úhrada cizoměnové FV musí selhat.');
        } catch (CashException $e) {
            self::assertSame('foreign_currency_invoice', $e->errorCode);
        }
    }

    public function testPurchaseInvoicePayment(): void
    {
        $reg = $this->makeRegister();
        $vendor = $this->client('Dodavatel a.s.', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-100', $vendor, 3000.00, $this->currencyId);

        // Plná výše → 321/211 + status paid.
        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 3000.00, 'purchase_invoice_id' => $pfId,
            'counter_account_code' => '518.200',
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($res['journal_entry_id']);
        self::assertEqualsWithDelta(3000.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(3000.00, $byAcc['211']['credit'], 0.001);
        self::assertSame('paid', $this->purchaseStatus($pfId));

        // Částečná úhrada → chyba.
        $pf2 = $this->purchaseInvoice('PF-2099-101', $vendor, 5000.00, $this->currencyId);
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 2500.00, 'purchase_invoice_id' => $pf2,
            ], $reg), $this->userId);
            self::fail('Částečná úhrada PF musí selhat.');
        } catch (CashException $e) {
            self::assertSame('partial_purchase_payment', $e->errorCode);
        }

        // PF v EUR → chyba.
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId !== 0) {
            $pfEur = $this->purchaseInvoice('PF-2099-102', $vendor, 100.00, $eurId);
            try {
                $this->service->create($this->supplierId, $this->doc([
                    'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 100.00, 'purchase_invoice_id' => $pfEur,
                ], $reg), $this->userId);
                self::fail('Úhrada cizoměnové PF musí selhat.');
            } catch (CashException $e) {
                self::assertSame('foreign_currency_invoice', $e->errorCode);
            }
        }
    }

    /**
     * DDKP NAVÁZANÝ NA ZÁLOHOVOU FAKTURU se neplatí samostatně — peníze odešly už na té
     * záloze a doklad účtuje jen 343/314, závazek na 321 nikdy nezaložil. Bez guardu
     * spadl do běžné větve a zaúčtoval fantomové 321 MD / 211 D. Zrcadlo
     * BankPostingService::ddkp_not_payable.
     */
    public function testAdvanceVatDocumentCannotBePaidInCash(): void
    {
        $reg = $this->makeRegister();
        $vendor = $this->client('Dodavatel DDKP a.s.', false, true);
        $advanceId = $this->purchaseInvoice('PF-2099-109', $vendor, 12100.00, $this->currencyId);
        $pfId = $this->purchaseInvoice('PF-2099-110', $vendor, 2100.00, $this->currencyId);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'advance' WHERE id = ?")
            ->execute([$advanceId]);
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET document_kind = 'tax_document', parent_purchase_invoice_id = ? WHERE id = ?"
        )->execute([$advanceId, $pfId]);

        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 2100.00,
                'purchase_invoice_id' => $pfId,
            ], $reg), $this->userId);
            self::fail('Úhrada DDKP hotově musí selhat.');
        } catch (CashException $e) {
            self::assertSame('ddkp_not_payable', $e->errorCode);
        }
    }

    /**
     * SAMOSTATNÝ daňový doklad k platbě (bez zálohové faktury) se naopak zaplatit DÁ —
     * je to jediný doklad, který k té platbě existuje. Účtuje se jako záloha na 314,
     * kam pak přijde i odpočet DPH z téhož dokladu; zbylý základ čeká na konečnou fakturu.
     *
     * Dokud tahle větev chyběla, platba spadla na `ddkp_not_payable`, doklad zůstal
     * neuhrazený a na 314 visel jen kredit z DPH bez protistrany.
     */
    public function testStandaloneAdvanceVatDocumentIsPaidAgainstAdvancesAccount(): void
    {
        $reg = $this->makeRegister();
        $vendor = $this->client('Dodavatel karta s.r.o.', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-111', $vendor, 2100.00, $this->currencyId);
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET document_kind = 'tax_document', parent_purchase_invoice_id = NULL WHERE id = ?"
        )->execute([$pfId]);

        $doc = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 2100.00,
            'purchase_invoice_id' => $pfId,
        ], $reg), $this->userId);

        $stmt = $this->db->pdo()->prepare(
            'SELECT ca.account_code, l.side FROM journal_entry_lines l
               JOIN chart_of_accounts ca ON ca.id = l.account_id
               JOIN journal_entries e ON e.id = l.entry_id
              WHERE e.source_type = ? AND e.source_id = ? ORDER BY ca.account_code'
        );
        $stmt->execute(['cash', (int) $doc['id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $debit = array_values(array_filter($rows, static fn (array $r): bool => $r['side'] === 'debit'));

        self::assertNotEmpty($debit, 'Úhrada musí vzniknout.');
        self::assertStringStartsWith('314', (string) $debit[0]['account_code'],
            'Platba samostatného DDKP je poskytnutá záloha, ne závazek na 321.');
    }

    /** Našeptávač k úhradě hotově nesmí DDKP vůbec nabídnout. */
    public function testUnpaidSearchExcludesAdvanceVatDocument(): void
    {
        $vendor = $this->client('Dodavatel hledani s.r.o.', false, true);
        $plain = $this->purchaseInvoice('PF-2099-120', $vendor, 1000.00, $this->currencyId);
        $ddkp  = $this->purchaseInvoice('PF-2099-121', $vendor, 2100.00, $this->currencyId);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'tax_document' WHERE id = ?")
            ->execute([$ddkp]);

        $found = $this->service->searchUnpaid($this->supplierId, 'purchase_invoice', 'PF-2099-12');
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $found);

        self::assertContains($plain, $ids, 'Běžná PF se nabídnout má.');
        self::assertNotContains($ddkp, $ids, 'DDKP není platební cíl — v našeptávači být nesmí.');
    }

    /**
     * H-1: platebním cílem přijaté faktury kryté zálohou je ZBYTEK (`amount_to_pay`
     * = brutto − advance_paid_amount), ne brutto. Dřív se porovnávalo proti brutto:
     * správná částka skončila na `partial_purchase_payment` a přijala se ta, která
     * na 321 nadělá fantomový debetní zůstatek ve výši zálohy.
     */
    public function testPurchasePaymentUsesRemainingAfterAdvance(): void
    {
        $reg = $this->makeRegister();
        $vendor = $this->client('Dodavatel se zalohou s.r.o.', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-130', $vendor, 121000.00, $this->currencyId);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_paid_amount = 100000.00 WHERE id = ?')
            ->execute([$pfId]);

        // Našeptávač musí ukázat zbytek 21 000, ne brutto 121 000.
        $found = $this->service->searchUnpaid($this->supplierId, 'purchase_invoice', 'PF-2099-130');
        self::assertCount(1, $found);
        self::assertEqualsWithDelta(21000.00, (float) $found[0]['remaining'], 0.001, 'H-1: zbývá po záloze 21 000.');
        self::assertEqualsWithDelta(100000.00, (float) $found[0]['paid'], 0.001, 'H-1: `paid` se počítá, nevrací se 0.');

        // Brutto (121 000) je nově chyba — na 321 tolik závazku nikdy nebylo.
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 121000.00,
                'purchase_invoice_id' => $pfId,
            ], $reg), $this->userId);
            self::fail('Úhrada v plném brutto přes zaplacenou zálohu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('partial_purchase_payment', $e->errorCode);
        }

        // Zbytek 21 000 projde a zaúčtuje se 321 MD / 211 D právě na zbytek.
        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 21000.00,
            'purchase_invoice_id' => $pfId,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($res['journal_entry_id']);
        self::assertEqualsWithDelta(21000.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(21000.00, $byAcc['211']['credit'], 0.001);
        self::assertSame('paid', $this->purchaseStatus($pfId));
    }

    /** H-1: faktura plně krytá zálohou (amount_to_pay = 0) se v našeptávači nesmí objevit. */
    public function testUnpaidSearchExcludesFullyAdvancedPurchaseInvoice(): void
    {
        $vendor = $this->client('Dodavatel plne kryty s.r.o.', false, true);
        $covered = $this->purchaseInvoice('PF-2099-131', $vendor, 12100.00, $this->currencyId);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_paid_amount = 12100.00 WHERE id = ?')
            ->execute([$covered]);

        $found = $this->service->searchUnpaid($this->supplierId, 'purchase_invoice', 'PF-2099-131');

        self::assertSame([], $found, 'PF na 0 Kč po záloze nemá a nemá mít pokladní úhradu.');
    }

    /**
     * H-2: storno bez zadaného data se NESMÍ datovat dneškem. `PostingService::reverse()`
     * (B8) je stavěný tak, že bez explicitního data použije datum původního zápisu a na
     * dnešek ho posune až u zamčeného období. Pokladna dosazovala dnešek sama, takže
     * protizápis k březnovému dokladu padal do listopadu, zatímco DPH se opravila v březnu.
     */
    public function testReverseWithoutDateKeepsOriginalEntryDate(): void
    {
        $reg = $this->makeRegister();
        $sale = $this->service->create($this->supplierId, $this->sale($reg, 1500.00), $this->userId);
        $original = $this->journal->find((int) $sale['journal_entry_id'], $this->supplierId);

        $rev = $this->service->reverse($this->supplierId, $sale['id'], ['reason' => 'Chybny doklad'], $this->userId);

        $reversal = $this->journal->find((int) $rev['reversal_entry_id'], $this->supplierId);
        self::assertSame(
            substr((string) $original['entry_date'], 0, 10),
            substr((string) $reversal['entry_date'], 0, 10),
            'H-2: protizápis dědí datum původního zápisu, ne dnešek.',
        );
        self::assertNotSame(date('Y-m-d'), substr((string) $reversal['entry_date'], 0, 10));
    }

    public function testReverseRejectsCashDocumentAllocatedToOtherItem(): void
    {
        $reg = $this->makeRegister();
        $cash = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'other', 'doc_type' => 'in', 'total_amount' => 500.00,
            'counter_account_code' => '315',
        ], $reg), $this->userId);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO other_items (supplier_id, side, kind, title, issued_on, due_on, amount)
             VALUES (?, 'receivable', 'other', 'Syntetická pohledávka', '2099-06-01', '2099-06-30', 500)"
        )->execute([$this->supplierId]);
        $itemId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO other_item_allocations (supplier_id, other_item_id, cash_document_id, amount, payment_on)
             VALUES (?, ?, ?, 500, '2099-06-15')"
        )->execute([$this->supplierId, $itemId, $cash['id']]);

        try {
            $this->service->reverse($this->supplierId, $cash['id'], ['reason' => 'Oprava platby'], $this->userId);
            self::fail('Nejdříve je nutné zrušit alokaci platby.');
        } catch (CashException $e) {
            self::assertSame('payment_allocated', $e->errorCode);
        }
    }

    public function testReverseWithCleanup(): void
    {
        $reg = $this->makeRegister();

        // (a) sale → protizápis + reversed.
        $sale = $this->service->create($this->supplierId, $this->sale($reg, 1000.00), $this->userId);
        $rev = $this->service->reverse($this->supplierId, $sale['id'], ['reason' => 'Chybný doklad', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
        self::assertGreaterThan(0, $rev['reversal_entry_id']);
        $reversal = $this->journal->find($rev['reversal_entry_id'], $this->supplierId);
        self::assertStringContainsString('Chybný doklad', (string) $reversal['description']);
        self::assertSame('reversed', $this->docStatus($sale['id']));

        // (b) úhrada FV → deletePayment + status zpět.
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-200', $client, 1000.00, $this->currencyId);
        $pay = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 1000.00, 'invoice_id' => $invoiceId,
        ], $reg), $this->userId);
        self::assertSame('paid', $this->invoiceRow($invoiceId)['status']);
        $this->service->reverse($this->supplierId, $pay['id'], ['reason' => 'Storno úhrady', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
        self::assertCount(0, $this->paymentRows($invoiceId), 'Platba smazána.');
        $inv = $this->invoiceRow($invoiceId);
        self::assertEqualsWithDelta(0.0, (float) $inv['paid_total'], 0.001);
        self::assertNotSame('paid', $inv['status']);

        // (c) úhrada PF → status zpět booked.
        $vendor = $this->client('Dodavatel', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-200', $vendor, 2000.00, $this->currencyId);
        $pfPay = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 2000.00, 'purchase_invoice_id' => $pfId,
        ], $reg), $this->userId);
        self::assertSame('paid', $this->purchaseStatus($pfId));
        $this->service->reverse($this->supplierId, $pfPay['id'], ['reason' => 'Storno úhrady PF', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
        self::assertSame('booked', $this->purchaseStatus($pfId));

        // (d) storno storna → chyba.
        try {
            $this->service->reverse($this->supplierId, $sale['id'], ['reason' => 'Znovu', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
            self::fail('Storno storna musí selhat.');
        } catch (CashException $e) {
            self::assertSame('doc_not_posted', $e->errorCode);
        }

        // (e) bez reason → chyba.
        $sale2 = $this->service->create($this->supplierId, $this->sale($reg, 100.00), $this->userId);
        try {
            $this->service->reverse($this->supplierId, $sale2['id'], ['reason' => 'ab'], $this->userId);
            self::fail('Storno bez důvodu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('reason_required', $e->errorCode);
        }
    }

    public function testDeleteDocumentRemovesJournalEntries(): void
    {
        $reg = $this->makeRegister();

        // (a) zaúčtovaný doklad → zmizí doklad i jeho zápis v deníku.
        $sale = $this->service->create($this->supplierId, $this->sale($reg, 1000.00), $this->userId);
        $entryId = (int) $this->service->get($this->supplierId, $sale['id'])['journal_entry_id'];
        $res = $this->service->deleteDocument($this->supplierId, $sale['id']);
        self::assertSame([$entryId], $res['deleted_entry_ids']);
        self::assertNull($this->journal->find($entryId, $this->supplierId));
        self::assertSame(0, $this->countDocuments($reg));

        // (b) stornovaný doklad → zmizí i protizápis.
        $sale2 = $this->service->create($this->supplierId, $this->sale($reg, 500.00), $this->userId);
        $entry2 = (int) $this->service->get($this->supplierId, $sale2['id'])['journal_entry_id'];
        $rev = $this->service->reverse($this->supplierId, $sale2['id'], ['reason' => 'Chybný doklad', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
        $res2 = $this->service->deleteDocument($this->supplierId, $sale2['id']);
        self::assertEqualsCanonicalizing([$entry2, (int) $rev['reversal_entry_id']], $res2['deleted_entry_ids']);
        self::assertNull($this->journal->find($entry2, $this->supplierId));
        self::assertNull($this->journal->find((int) $rev['reversal_entry_id'], $this->supplierId));

        // (c) úhrada FV → platba se odmaže a faktura přestane být zaplacená.
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-300', $client, 1000.00, $this->currencyId);
        $pay = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 1000.00, 'invoice_id' => $invoiceId,
        ], $reg), $this->userId);
        self::assertSame('paid', $this->invoiceRow($invoiceId)['status']);
        $this->service->deleteDocument($this->supplierId, $pay['id']);
        self::assertCount(0, $this->paymentRows($invoiceId), 'Platba smazána.');
        self::assertNotSame('paid', $this->invoiceRow($invoiceId)['status']);

        // (d) neexistující doklad → 404.
        try {
            $this->service->deleteDocument($this->supplierId, $sale['id']);
            self::fail('Smazání neexistujícího dokladu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('validation', $e->errorCode);
        }
    }

    /**
     * Tvrdé smazání pokladního dokladu mazalo zápis i s okruhem párování a rozpadem
     * saldokonta. Platí stejné brány jako mazání zápisu v deníku.
     */
    public function testDeleteDocumentRefusesPairedOrAllocatedEntry(): void
    {
        $reg = $this->makeRegister();
        $sale = $this->service->create($this->supplierId, $this->sale($reg, 1000.00), $this->userId);
        $other = $this->service->create($this->supplierId, $this->sale($reg, 1000.00), $this->userId);
        $entryId = (int) $this->service->get($this->supplierId, $sale['id'])['journal_entry_id'];
        $otherEntryId = (int) $this->service->get($this->supplierId, $other['id'])['journal_entry_id'];
        $pdo = $this->db->pdo();
        $line = $pdo->prepare('SELECT line_no, account_id, side FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no LIMIT 1');
        $line->execute([$this->supplierId, $entryId]);
        $cashLine = $line->fetch(PDO::FETCH_ASSOC);
        $line->execute([$this->supplierId, $otherEntryId]);
        $otherLine = $line->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare('INSERT INTO journal_line_pairings (supplier_id, account_id) VALUES (?, ?)')->execute([$this->supplierId, $cashLine['account_id']]);
        $pairingId = (int) $pdo->lastInsertId();
        $item = $pdo->prepare('INSERT INTO journal_line_pairing_items (supplier_id, entry_id, line_no, pairing_id, account_id) VALUES (?, ?, ?, ?, ?)');
        $item->execute([$this->supplierId, $entryId, $cashLine['line_no'], $pairingId, $cashLine['account_id']]);
        $item->execute([$this->supplierId, $otherEntryId, $otherLine['line_no'], $pairingId, $otherLine['account_id']]);
        try {
            $this->service->deleteDocument($this->supplierId, $sale['id']);
            self::fail('Spárovaný zápis pokladního dokladu se nesmí smazat.');
        } catch (PostingException $e) {
            self::assertSame('entry_paired', $e->errorCode);
        }
        self::assertNotNull($this->journal->find($entryId, $this->supplierId));
        self::assertSame(2, $this->countDocuments($reg));

        $pdo->prepare('DELETE FROM journal_line_pairings WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $pairingId]);
        $pdo->prepare(
            "INSERT INTO journal_settlement_allocations (supplier_id, entry_id, line_no, account_id, side, doc_type, doc_id, amount, kind)
             VALUES (?, ?, ?, ?, ?, 'other_item', 1, 100, 'settlement')"
        )->execute([$this->supplierId, $entryId, $cashLine['line_no'], $cashLine['account_id'], $cashLine['side']]);
        try {
            $this->service->deleteDocument($this->supplierId, $sale['id']);
            self::fail('Zápis s rozpadem saldokonta se nesmí smazat.');
        } catch (PostingException $e) {
            self::assertSame('allocation_saldo_in_use', $e->errorCode);
        }
        self::assertNotNull($this->journal->find($entryId, $this->supplierId));
        self::assertSame(2, $this->countDocuments($reg));
    }

    public function testDeleteDocumentInClosedPeriodRefused(): void
    {
        $reg = $this->makeRegister();
        $posted = $this->service->create($this->supplierId, $this->sale($reg, 500.00), $this->userId);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        try {
            $this->service->deleteDocument($this->supplierId, $posted['id']);
            self::fail('Smazání v zavřeném období musí selhat.');
        } catch (CashException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }
        self::assertSame(1, $this->countDocuments($reg));
    }

    public function testClosedPeriodRefused(): void
    {
        $reg = $this->makeRegister();
        $posted = $this->service->create($this->supplierId, $this->sale($reg, 500.00), $this->userId);

        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        // Post do zavřeného období.
        $this->expectException(PostingException::class);
        $this->service->create($this->supplierId, $this->sale($reg, 600.00), $this->userId);
    }

    public function testReverseInClosedPeriodRefused(): void
    {
        $reg = $this->makeRegister();
        $posted = $this->service->create($this->supplierId, $this->sale($reg, 500.00), $this->userId);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $this->expectException(PostingException::class);
        $this->service->reverse($this->supplierId, $posted['id'], ['reason' => 'Storno', 'entry_date' => self::YEAR . '-06-30'], $this->userId);
    }

    /**
     * H-3: doklad v UZAMČENÉM období stornovat LZE — protizápis se posune do prvního
     * otevřeného data. Kontrola zámku se dělá nad datem PROTIZÁPISU, ne nad datem
     * původního dokladu.
     *
     * Proč vlastní období místo výchozí fixtury: ta běží v roce 2099, a tam scénář
     * „datum původního dokladu zamčené, datum protizápisu otevřené" NEEXISTUJE —
     * zámek, který pokryje rok 2099, pokryje nutně i dnešek a `PostingService`
     * storno odmítne (`date_locked`, zámek zasahuje aktuální datum). Test si proto
     * staví období v MINULOSTI (tam zámek doklad pokryje) plus období pro aktuální
     * rok (kam protizápis spadne).
     */
    public function testReverseInLockedPeriodShiftsCounterEntryToOpenDate(): void
    {
        $today = date('Y-m-d');
        $currentYear = (int) date('Y');
        $pastYear = $currentYear - 3;

        $this->periods->create($this->supplierId, $pastYear, $pastYear . '-01-01', $pastYear . '-12-31');
        $this->periods->create($this->supplierId, $currentYear, $currentYear . '-01-01', $currentYear . '-12-31');

        $reg = $this->makeRegister();
        $sale = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1000.00,
            'issue_date' => $pastYear . '-03-15',
        ], $reg), $this->userId);
        self::assertSame('posted', $sale['status']);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        );
        $stmt->execute([$this->supplierId, $pastYear . '-12-31']);
        // `locked_until` si `DocumentLockService` i `PostingService` cachují per instanci
        // (v produkci = per request). Zaúčtování dokladu výše cache naplnilo hodnotou
        // „bez zámku", takže bez tohohle kroku by test tvrdil, že storno zamčeného
        // dokladu prochází, i kdyby zámek nikdo nekontroloval. Simuluje se tím stav,
        // v jakém do storna vstupuje reálný request: zámek už v DB je, cache prázdná.
        $this->forgetLockCaches();

        $rev = $this->service->reverse(
            $this->supplierId,
            $sale['id'],
            ['reason' => 'Chyba zjištěná až po uzamčení období'],
            $this->userId,
        );

        self::assertNotNull($rev['reversal_entry_id'], 'H-3: doklad v zamčeném období musí jít stornovat.');
        $reversal = $this->journal->find((int) $rev['reversal_entry_id'], $this->supplierId);
        self::assertSame(
            $today,
            substr((string) $reversal['entry_date'], 0, 10),
            'H-3: protizápis patří do otevřeného data, ne do zamčeného období původního dokladu.',
        );
        self::assertSame('reversed', $this->docStatus($sale['id']));
        self::assertContains(
            'cash.warning.reversal_date_shifted',
            $rev['warnings'],
            'M-14: posun data protizápisu se nesmí odehrát potichu.',
        );
    }

    /**
     * M-14: storno, které pošle pokladnu do mínusu, musí varovat. Vznikne, když se
     * stornuje příjmový doklad, ze kterého už byly vydané peníze dalším výdajem.
     */
    public function testReverseWarnsAboutNegativeBalance(): void
    {
        $reg = $this->makeRegister();
        $income = $this->service->create($this->supplierId, $this->sale($reg, 5000.00), $this->userId);
        $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 4000.00,
        ], $reg), $this->userId);

        $rev = $this->service->reverse(
            $this->supplierId,
            $income['id'],
            ['reason' => 'Chybně zaevidovaný příjem', 'entry_date' => self::YEAR . '-06-30'],
            $this->userId,
        );

        self::assertContains('cash.warning.negative_balance_after_reverse', $rev['warnings']);
    }

    /** Bez záporného zůstatku a bez posunu data žádné varování nevzniká. */
    public function testReverseWithoutProblemsHasNoWarnings(): void
    {
        $reg = $this->makeRegister();
        $income = $this->service->create($this->supplierId, $this->sale($reg, 5000.00), $this->userId);

        $rev = $this->service->reverse(
            $this->supplierId,
            $income['id'],
            ['reason' => 'Chybně zaevidovaný příjem', 'entry_date' => self::YEAR . '-06-30'],
            $this->userId,
        );

        self::assertSame([], $rev['warnings']);
    }

    /**
     * M-9: pokladní prodej s českou sazbou DPH je tuzemské zdanitelné plnění a nad
     * prahem KH míří do A.4, kam patří jen české DIČ. Cizí VAT ID by se tam osekalo
     * na číslice a vydávalo za české — varování, ne blokace.
     */
    public function testForeignVatIdOnTaxableSaleWarns(): void
    {
        $reg = $this->makeRegister();

        $foreign = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 121000.00, 'vat_mode' => 'vat',
            'partner_dic' => 'DE123456789',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 100000.00, 'vat_amount' => 21000.00]],
        ], $reg), $this->userId);
        self::assertContains('cash.warning.dic_not_czech', $foreign['warnings']);
        self::assertNotContains('cash.warning.dic_missing_over_10k', $foreign['warnings']);

        $czech = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 121000.00, 'vat_mode' => 'vat',
            'partner_dic' => 'CZ12345678',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 100000.00, 'vat_amount' => 21000.00]],
        ], $reg), $this->userId);
        self::assertNotContains('cash.warning.dic_not_czech', $czech['warnings']);
    }

    /**
     * L-2: invariant „částka pokladního dokladu je kladná" (znaménko nese `doc_type`)
     * drží i DB, ne jen `validateDoc()`. Test jde ZÁMĚRNĚ přímým SQL — služba by
     * takový řádek nepustila, takže by se constraint vůbec nedostal ke slovu.
     *
     * Vratka úhrady přijaté faktury (`in` + `purchase_payment`) tu je schválně: je to
     * kombinace, kterou `PURPOSE_MATRIX` zakazuje, ale peněžní deník s ní počítá.
     * Constraint podle matice proto v migraci 1505 NENÍ a tenhle řádek musí projít.
     */
    public function testDatabaseRefusesNonPositiveCashAmount(): void
    {
        $reg = $this->makeRegister();
        $pdo = $this->db->pdo();

        $insert = static fn (): \PDOStatement => $pdo->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, issue_date, description,
                 total_amount, currency_code, vat_mode, status)
             VALUES (?, ?, ?, ?, ?, 'Přímý SQL zápis', ?, 'CZK', 'none', 'draft')"
        );

        // Savepoint: porušení CHECK zabije jen vnořený blok, ne celou testovací transakci.
        foreach (['nulová částka' => 0.00, 'záporná částka' => -100.00] as $label => $amount) {
            $pdo->exec('SAVEPOINT chk_probe');
            try {
                $insert()->execute([$this->supplierId, $reg, 'in', 'sale', self::YEAR . '-06-15', $amount]);
                self::fail('DB musí odmítnout: ' . $label);
            } catch (\PDOException $e) {
                self::assertStringContainsString('constraint', strtolower($e->getMessage()), $label);
            } finally {
                $pdo->exec('ROLLBACK TO SAVEPOINT chk_probe');
            }
        }

        $insert()->execute([$this->supplierId, $reg, 'in', 'purchase_payment', self::YEAR . '-06-15', 100.00]);
        self::assertSame(1, $this->countDocuments($reg), 'Vratka úhrady PF projít musí.');
    }

    public function testTenantIsolation(): void
    {
        $reg = $this->makeRegister();
        $draft = $this->service->create($this->supplierId, array_merge($this->sale($reg, 500.00), ['post' => false]), $this->userId);
        $other = $this->supplierId + 99999;

        try {
            $this->service->get($other, $draft['id']);
            self::fail('Doklad cizí firmy nesmí být viditelný.');
        } catch (CashException $e) {
            self::assertSame('validation', $e->errorCode);
            self::assertSame(404, $e->httpStatus);
        }
        try {
            $this->service->post($other, $draft['id'], $this->userId);
            self::fail('Doklad cizí firmy nesmí jít zaúčtovat.');
        } catch (CashException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        // Úhrada faktury, která firmě nepatří / neexistuje → invoice_not_found.
        try {
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 100.00, 'invoice_id' => 999999999,
            ], $reg), $this->userId);
            self::fail('Úhrada neexistující/cizí FV musí selhat.');
        } catch (CashException $e) {
            self::assertSame('invoice_not_found', $e->errorCode);
        }
    }

    public function testTransferLegsThrough261(): void
    {
        $reg = $this->makeRegister();
        $in = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'transfer', 'doc_type' => 'in', 'total_amount' => 5000.00,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($in['journal_entry_id']);
        self::assertEqualsWithDelta(5000.00, $byAcc['211']['debit'], 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['261']['credit'], 0.001);

        $out = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'transfer', 'doc_type' => 'out', 'total_amount' => 2000.00,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($out['journal_entry_id']);
        self::assertEqualsWithDelta(2000.00, $byAcc['261']['debit'], 0.001);
        self::assertEqualsWithDelta(2000.00, $byAcc['211']['credit'], 0.001);
    }

    public function testBalanceFromLedgerAndNegativeWarning(): void
    {
        $reg = $this->makeRegister();
        // Záporný zůstatek → warning, post projde (R7).
        $out = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'other', 'doc_type' => 'out', 'total_amount' => 500.00, 'counter_account_code' => '648',
        ], $reg), $this->userId);
        self::assertContains('cash.warning.negative_balance', $out['warnings']);
        self::assertSame('posted', $out['status']);

        // Doplnění příjmem → zůstatek z ledgeru = 1000 − 500 = 500.
        $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'other', 'doc_type' => 'in', 'total_amount' => 1000.00, 'counter_account_code' => '668',
        ], $reg), $this->userId);
        $balance = $this->registers->balance($this->supplierId, '211', self::YEAR . '-12-31');
        self::assertEqualsWithDelta(500.00, $balance, 0.001, 'Zůstatek = obrat analytiky v ledgeru (R6).');
    }

    public function testForeignCashRegisterAutoPostingWithCnbRateAndDualBalance(): void
    {
        // Kurz ČNB naseedovaný do cache → getRate() nesahá na síť.
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00);
        $reg = $this->registers->create($this->supplierId, [
            'name' => 'EUR pokladna', 'account_code' => '211500', 'currency_code' => 'EUR', 'is_default' => true,
        ]);

        // Příjem 100 EUR × 25 = 2 500 CZK (bez zadaného kurzu → ČNB).
        $sale = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'amount_foreign' => 100.00,
        ], $reg), $this->userId);
        self::assertSame('posted', $sale['status']);

        $byAcc = $this->linesByAccountCode($sale['journal_entry_id']);
        self::assertEqualsWithDelta(2500.00, $byAcc['211500']['debit'], 0.001, 'CZK ekvivalent = amount_foreign × kurz ČNB.');
        self::assertEqualsWithDelta(2500.00, $byAcc['602']['credit'], 0.001);

        // Cizoměnová stopa na řádku analytiky pokladny (nosič přecenění §24/6).
        $entry = $this->journal->find($sale['journal_entry_id'], $this->supplierId);
        $cashLine = null;
        foreach ($entry['lines'] as $l) {
            if ($this->code((int) $l['account_id']) === '211500') {
                $cashLine = $l;
            }
        }
        self::assertNotNull($cashLine);
        self::assertSame('EUR', $cashLine['currency_code']);
        self::assertEqualsWithDelta(100.00, (float) $cashLine['amount_foreign'], 0.001);
        self::assertEqualsWithDelta(25.00, (float) $cashLine['fx_rate'], 0.0001);

        // Výdej 40 EUR × 25 = 1 000 CZK.
        $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'amount_foreign' => 40.00,
        ], $reg), $this->userId);

        // Duální zůstatek: 60 EUR / 1 500 CZK.
        $detail = $this->registers->get($this->supplierId, $reg, self::YEAR . '-12-31');
        self::assertEqualsWithDelta(60.00, (float) $detail['balance_foreign'], 0.001, 'Cizoměnový zůstatek 100 − 40.');
        self::assertEqualsWithDelta(1500.00, (float) $detail['balance'], 0.001, 'CZK zůstatek z ledgeru 2500 − 1000.');
    }

    public function testForeignCashManualRateOverride(): void
    {
        $this->seedRate('USD', self::YEAR . '-06-15', 29.00);
        $reg = $this->registers->create($this->supplierId, [
            'name' => 'USD pokladna', 'account_code' => '211510', 'currency_code' => 'USD',
        ]);
        // Ruční kurz 30 → 50 USD = 1 500 CZK, nikoli kurzem ČNB 29.
        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'amount_foreign' => 50.00, 'fx_rate' => 30.0,
        ], $reg), $this->userId);
        $byAcc = $this->linesByAccountCode($res['journal_entry_id']);
        self::assertEqualsWithDelta(1500.00, $byAcc['211510']['credit'], 0.001);
        self::assertEqualsWithDelta(1500.00, $byAcc['501']['debit'], 0.001);
    }

    public function testForeignRegisterRejectsInvoicePayment(): void
    {
        $reg = $this->registers->create($this->supplierId, [
            'name' => 'EUR pokladna', 'account_code' => '211500', 'currency_code' => 'EUR',
        ]);
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-900', $client, 1000.00, $this->currencyId);
        try {
            // BEZ ručního kurzu ZÁMĚRNĚ: nepodporovaný účel musí padnout dřív, než se
            // vůbec řeší kurz ČNB (jinak by uživatel dostal technické fx_rate_unavailable
            // místo skutečného důvodu).
            $this->service->create($this->supplierId, $this->doc([
                'purpose' => 'invoice_payment', 'doc_type' => 'in', 'amount_foreign' => 40.00,
                'invoice_id' => $invoiceId,
            ], $reg), $this->userId);
            self::fail('Úhrada faktury z valutové pokladny musí selhat (v1).');
        } catch (CashException $e) {
            self::assertSame('foreign_register_purpose_unsupported', $e->errorCode);
        }
    }

    public function testCzkRegisterUnchangedNoForeignTrace(): void
    {
        // Regrese: CZK pokladna beze změny — amount_foreign NULL, řádky bez cizoměnové stopy.
        $reg = $this->makeRegister();
        $sale = $this->service->create($this->supplierId, $this->sale($reg, 1210.00), $this->userId);
        $stored = $this->service->get($this->supplierId, $sale['id']);
        self::assertNull($stored['amount_foreign']);
        self::assertSame('CZK', $stored['currency_code']);
        $entry = $this->journal->find($sale['journal_entry_id'], $this->supplierId);
        foreach ($entry['lines'] as $l) {
            self::assertNull($l['currency_code'] ?? null, 'CZK doklad nemá cizoměnovou stopu.');
        }
    }

    public function testForeignSaleWithVatConvertsBreakdownToCzk(): void
    {
        // §4/12: DPH rozpad zadaný v cizí měně se převede kurzem dokladu na CZK, Σ(základ+daň)
        // sedí PŘESNĚ na total_amount CZK → doklad se založí a zaúčtuje automaticky (HIGH 1).
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00);
        $reg = $this->registers->create($this->supplierId, [
            'name' => 'EUR pokladna', 'account_code' => '211500', 'currency_code' => 'EUR', 'is_default' => true,
        ]);
        // 100 EUR: základ 82,64 + daň 17,36 (Σ 100 EUR). × 25 = 2066,00 / 434,00 → total 2500,00 CZK.
        $sale = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'amount_foreign' => 100.00, 'vat_mode' => 'vat',
            'partner_dic' => 'CZ12345678',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 82.64, 'vat_amount' => 17.36]],
        ], $reg), $this->userId);
        self::assertSame('posted', $sale['status'], 'Valutový DPH doklad se musí založit (žádný vat_lines_mismatch).');

        $byAcc = $this->linesByAccountCode($sale['journal_entry_id']);
        self::assertEqualsWithDelta(2500.00, $byAcc['211500']['debit'], 0.001);
        self::assertEqualsWithDelta(2066.00, $byAcc['602']['credit'], 0.001);
        self::assertEqualsWithDelta(434.00, $byAcc['343.200']['credit'], 0.001);

        // Uložený rozpad je v CZK a Σ(základ+daň) == total_amount.
        $stored = $this->service->get($this->supplierId, $sale['id']);
        self::assertEqualsWithDelta(2500.00, (float) $stored['total_amount'], 0.001);
        $sum = 0.0;
        foreach ($stored['vat_lines'] as $vl) {
            $sum += (float) $vl['base_amount'] + (float) $vl['vat_amount'];
        }
        self::assertEqualsWithDelta(2500.00, $sum, 0.001, 'Σ(základ+daň)_CZK == total_amount_CZK.');
    }

    public function testForeignRegisterRejectsOtherWithMoneyCounter(): void
    {
        // Gate účelů nesmí jít obejít přes purpose=other s protiúčtem saldokonta/peněz na cestě.
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00);
        $reg = $this->registers->create($this->supplierId, [
            'name' => 'EUR pokladna', 'account_code' => '211500', 'currency_code' => 'EUR',
        ]);
        foreach (['311', '321', '261', '314', '324'] as $counter) {
            try {
                $this->service->create($this->supplierId, $this->doc([
                    'purpose' => 'other', 'doc_type' => 'in', 'amount_foreign' => 50.00, 'counter_account_code' => $counter,
                ], $reg), $this->userId);
                self::fail('Valutový „other" s protiúčtem ' . $counter . ' musí selhat.');
            } catch (CashException $e) {
                self::assertSame('foreign_register_purpose_unsupported', $e->errorCode, 'counter ' . $counter);
            }
        }
        // Běžný nefinanční protiúčet (648) projde.
        $ok = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'other', 'doc_type' => 'out', 'amount_foreign' => 20.00, 'counter_account_code' => '648',
        ], $reg), $this->userId);
        self::assertSame('posted', $ok['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedRate(string $code, string $date, float $rate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$date, strtoupper($code), $rate]);
    }

    /**
     * Zahodí per-instance cache `locked_until` v `DocumentLockService` a `PostingService`.
     * Obě se plní při prvním čtení a v produkci žijí jen po dobu requestu; v testu ale
     * jedna instance přežije celý scénář, takže by zámek nastavený uprostřed testu
     * zůstal neviditelný.
     */
    private function forgetLockCaches(): void
    {
        // `DocumentLockService` cachuje dvakrát: syrové `locked_until` i hotové
        // `DocumentLock` per (supplier, datum). Vyprázdnit se musí obojí, jinak
        // se vrátí zámek spočítaný ještě před jeho nastavením.
        foreach ([[$this->documentLocks, 'lockedUntilCache'], [$this->documentLocks, 'cache'],
                  [$this->posting, 'lockedUntilCache']] as [$service, $property]) {
            (new \ReflectionProperty($service, $property))->setValue($service, []);
        }
    }

    /**
     * L-3: dvě pokladny sdílejí jednu firemní řadu — dokud si jedna z nich vlastní
     * řadu nezapne. Výchozí stav se nesmí změnit nikomu, kdo o to nepožádal.
     */
    public function testRegistersShareCompanySeriesByDefault(): void
    {
        $regA = $this->makeRegister('211');
        $regB = $this->registers->create($this->supplierId, ['name' => 'Vedlejší', 'account_code' => $this->makeCashAnalytic('211.200')]);

        $first  = $this->service->create($this->supplierId, $this->sale($regA, 100.0), $this->userId);
        $second = $this->service->create($this->supplierId, $this->sale($regB, 200.0), $this->userId);

        self::assertSame('PPD-' . self::YEAR . '-0001', $first['doc_number']);
        self::assertSame('PPD-' . self::YEAR . '-0002', $second['doc_number'], 'Bez vlastní řady čerpají obě pokladny z jedné firemní.');
    }

    /**
     * Pokladna s vlastní řadou čerpá čísla ze své řady a dostane volný prefix, aby
     * nekolidovala s doklady firemní řady (uq_cashdoc_supplier_number).
     */
    public function testOwnSeriesNumbersRegisterSeparately(): void
    {
        $regA = $this->makeRegister('211');
        $regB = $this->registers->create($this->supplierId, [
            'name' => 'Vedlejší', 'account_code' => $this->makeCashAnalytic('211.200'), 'own_series' => true,
        ]);

        $shared = $this->service->create($this->supplierId, $this->sale($regA, 100.0), $this->userId);
        $own    = $this->service->create($this->supplierId, $this->sale($regB, 200.0), $this->userId);

        self::assertSame('PPD-' . self::YEAR . '-0001', $shared['doc_number']);
        self::assertStringStartsWith('PPD2-', (string) $own['doc_number'], 'Vlastní řada musí mít volný prefix, ne PPD.');
        self::assertStringEndsWith('-0001', (string) $own['doc_number'], 'Vlastní řada začíná od jedničky.');

        // A firemní řada pokračuje, jako by se nic nestalo.
        $next = $this->service->create($this->supplierId, $this->sale($regA, 300.0), $this->userId);
        self::assertSame('PPD-' . self::YEAR . '-0002', $next['doc_number']);
    }

    /**
     * Ruční editace prefixu v Nástrojích nesmí dát dvěma pokladním řadám tentýž
     * prefix — obě začínají od jedničky, takže by vydaly totéž číslo dokladu.
     */
    public function testCashSeriesPrefixCannotCollideWithAnotherRegister(): void
    {
        $regA = $this->makeRegister('211');
        $regB = $this->registers->create($this->supplierId, [
            'name' => 'Vedlejší', 'account_code' => $this->makeCashAnalytic('211.200'), 'own_series' => true,
        ]);
        $this->service->create($this->supplierId, $this->sale($regB, 100.0), $this->userId);

        $this->expectException(ClosingException::class);
        $this->expectExceptionMessageMatches('/patří společné řadě firmy/');
        $this->documentSeries->updateSeries($this->supplierId, 'cash_in', self::YEAR, ['prefix' => 'PPD'], $regB);
    }

    /** Táž kontrola platí i proti existující vlastní řadě jiné pokladny. */
    public function testCashSeriesPrefixCannotCollideWithExistingRegisterSeries(): void
    {
        $this->makeRegister('211');
        $regB = $this->registers->create($this->supplierId, [
            'name' => 'Vedlejší', 'account_code' => $this->makeCashAnalytic('211.200'), 'own_series' => true,
        ]);
        $regC = $this->registers->create($this->supplierId, [
            'name' => 'Třetí', 'account_code' => $this->makeCashAnalytic('211.300'), 'own_series' => true,
        ]);
        $this->service->create($this->supplierId, $this->sale($regB, 100.0), $this->userId);
        $this->service->create($this->supplierId, $this->sale($regC, 100.0), $this->userId);

        $takenPrefix = null;
        foreach ($this->documentSeries->list($this->supplierId) as $row) {
            if ((int) $row['register_id'] === $regB && (string) $row['series_code'] === 'cash_in') {
                $takenPrefix = (string) $row['prefix'];
            }
        }
        self::assertNotNull($takenPrefix);

        $this->expectException(ClosingException::class);
        $this->expectExceptionMessageMatches('/už používá jiná pokladní řada/');
        $this->documentSeries->updateSeries($this->supplierId, 'cash_in', self::YEAR, ['prefix' => $takenPrefix], $regC);
    }

    /**
     * Přepnutí na vlastní řadu nesmí zpětně zlomit rok, ve kterém pokladna už čísla
     * z firemní řady vydala — třeba doúčtováním konceptu z prosince v lednu.
     */
    public function testOwnSeriesDoesNotSplitYearAlreadyNumberedFromCompanySeries(): void
    {
        $reg = $this->makeRegister('211');
        $first = $this->service->create($this->supplierId, $this->sale($reg, 100.0), $this->userId);
        self::assertSame('PPD-' . self::YEAR . '-0001', $first['doc_number']);

        // Zapnutí vlastní řady (guard hlídá jen aktuální rok, fixtura běží v roce 2099).
        $this->registers->update($this->supplierId, $reg, ['own_series' => true]);

        $second = $this->service->create($this->supplierId, $this->sale($reg, 200.0), $this->userId);
        self::assertSame(
            'PPD-' . self::YEAR . '-0002',
            $second['doc_number'],
            'Rok s čísly z firemní řady v ní musí pokračovat, jinak má kniha dvě řady.',
        );

        // Rok bez firemních čísel vlastní řadu naopak dostane (účetní období pro
        // následující rok fixtura nemá, takže se ptáme rovnou služby).
        self::assertTrue(
            $this->registers->ensureOwnSeries($this->supplierId, $reg, self::YEAR + 1),
            'Rok, ve kterém pokladna ještě nečíslovala, vlastní řadu dostane.',
        );
        self::assertFalse(
            $this->registers->ensureOwnSeries($this->supplierId, $reg, self::YEAR),
            'Rok s čísly z firemní řady vlastní řadu nedostane ani opakovaným voláním.',
        );
    }

    /** Přepnout řadu uprostřed roku, ve kterém už pokladna vydala číslo, nejde (§11 ZoÚ). */
    public function testSeriesSwitchIsLockedAfterFirstNumberInYear(): void
    {
        $reg = $this->makeRegister('211');
        // Doklad rovnou do DB: guard se ptá na AKTUÁLNÍ rok, pro který fixtura nemá
        // účetní období, takže přes službu by ho zaúčtovat nešlo.
        $this->db->pdo()->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, issue_date, tax_date,
                                         description, doc_number, total_amount, status, created_by)
             VALUES (?, ?, 'in', 'sale', ?, ?, 'Loňský doklad', 'PPD-TEST-0001', 100.00, 'posted', ?)"
        )->execute([$this->supplierId, $reg, date('Y') . '-06-15', date('Y') . '-06-15', $this->userId]);

        $this->expectException(CashException::class);
        $this->expectExceptionMessageMatches('/přepnout jen v roce/');
        $this->registers->update($this->supplierId, $reg, ['own_series' => true]);
    }

    /** Aktivní analytika 211 v osnově — druhá pokladna nemůže sdílet účet s první. */
    /**
     * Vratka úhrady přijaté faktury: příjmový doklad s účelem `purchase_payment`.
     * Peněžní deník s tímhle scénářem počítal odjakživa (snižuje daňový výdaj), ale
     * PURPOSE_MATRIX ho zakazovala, takže vzniknout mohl jen mimo aplikaci.
     */
    public function testPurchaseRefundIsPostedInReverseAndReopensInvoice(): void
    {
        $reg = $this->makeRegister('211');
        $pf = $this->purchaseInvoice('PF-2099-900', $this->client('Dodavatel vratka a.s.', false, true), 5000.00, $this->currencyId);

        $payment = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 5000.0,
            'purchase_invoice_id' => $pf,
        ], $reg), $this->userId);
        self::assertSame('posted', $payment['status']);
        self::assertSame('paid', $this->purchaseStatus($pf));

        $refund = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'in', 'total_amount' => 2000.0,
            'purchase_invoice_id' => $pf, 'issue_date' => self::YEAR . '-06-25',
        ], $reg), $this->userId);

        $byAcc = $this->linesByAccountCode($refund['journal_entry_id']);
        self::assertEqualsWithDelta(2000.00, $byAcc['211']['debit'], 0.001, 'Vrácená hotovost jde do pokladny.');
        self::assertEqualsWithDelta(2000.00, $byAcc['321']['credit'], 0.001, 'Závazek se o vrácenou částku obnoví.');
        self::assertNotSame('paid', $this->purchaseStatus($pf), 'Po vratce už faktura není uhrazená v plné výši.');
    }

    /** Vrátit nelze víc, než kolik je na faktuře zaplaceno. */
    public function testPurchaseRefundCannotExceedPaidAmount(): void
    {
        $reg = $this->makeRegister('211');
        $pf = $this->purchaseInvoice('PF-2099-901', $this->client('Dodavatel vratka 2 a.s.', false, true), 5000.00, $this->currencyId);
        $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 5000.0,
            'purchase_invoice_id' => $pf,
        ], $reg), $this->userId);

        $this->expectException(CashException::class);
        $this->expectExceptionMessageMatches('/nesmí přesáhnout uhrazenou částku/');
        $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'in', 'total_amount' => 5001.0,
            'purchase_invoice_id' => $pf, 'issue_date' => self::YEAR . '-06-25',
        ], $reg), $this->userId);
    }

    /**
     * Ruční úhrada faktury bez partnera dostane odběratele faktury (název, IČ, DIČ)
     * stejně jako automatické hotovostní vyrovnání. Platí i pro koncept a jeho úpravu,
     * kde explicitní null partnera dřív uložený údaj smazal.
     */
    public function testInvoicePaymentWithoutPartnerTakesClientOfInvoice(): void
    {
        $reg = $this->makeRegister();
        $client = $this->client('Odběratel hotově s.r.o.', true, false);
        $this->setClientIc($client, '12345679');
        $invoiceId = $this->saleInvoice('FV-2099-300', $client, 2000.00, $this->currencyId);

        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 500.00, 'invoice_id' => $invoiceId,
            'partner_name' => null, 'partner_ic' => null, 'partner_dic' => null,
        ], $reg), $this->userId);
        $this->assertPartner($res['id'], 'Odběratel hotově s.r.o.', '12345679', 'CZ12345678');

        $draft = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 500.00, 'invoice_id' => $invoiceId,
            'post' => false,
        ], $reg), $this->userId);
        $this->assertPartner($draft['id'], 'Odběratel hotově s.r.o.', '12345679', 'CZ12345678');

        $this->service->updateDraft($this->supplierId, $draft['id'], [
            'partner_name' => null, 'partner_ic' => null, 'partner_dic' => null, 'total_amount' => 400.00,
        ]);
        $this->assertPartner($draft['id'], 'Odběratel hotově s.r.o.', '12345679', 'CZ12345678');
    }

    /** Partner zadaný uživatelem má přednost před odběratelem faktury. */
    public function testInvoicePaymentKeepsExplicitPartner(): void
    {
        $reg = $this->makeRegister();
        $client = $this->client('Odběratel faktury s.r.o.', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-301', $client, 2000.00, $this->currencyId);

        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 500.00, 'invoice_id' => $invoiceId,
            'partner_name' => 'Jan Plátce', 'partner_ic' => null, 'partner_dic' => null,
        ], $reg), $this->userId);
        $this->assertPartner($res['id'], 'Jan Plátce', null, null);
    }

    /** Úhrada i vratka přijaté faktury bez partnera dostanou dodavatele faktury. */
    public function testPurchasePaymentAndRefundWithoutPartnerTakeVendor(): void
    {
        $reg = $this->makeRegister();
        $vendor = $this->client('Dodavatel hotově a.s.', false, true);
        $this->setClientIc($vendor, '87654321');
        $pf = $this->purchaseInvoice('PF-2099-300', $vendor, 3000.00, $this->currencyId);

        $payment = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 3000.00, 'purchase_invoice_id' => $pf,
        ], $reg), $this->userId);
        $this->assertPartner($payment['id'], 'Dodavatel hotově a.s.', '87654321', 'CZ12345678');

        $refund = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'purchase_payment', 'doc_type' => 'in', 'total_amount' => 1000.00,
            'purchase_invoice_id' => $pf, 'issue_date' => self::YEAR . '-06-25',
        ], $reg), $this->userId);
        $this->assertPartner($refund['id'], 'Dodavatel hotově a.s.', '87654321', 'CZ12345678');
    }

    /**
     * Starší doklad uložený bez partnera dostane na PDF odběratele faktury. Uložený
     * doklad se tím nemění (zaúčtovaný doklad se nepřepisuje).
     */
    public function testPdfDataOfOlderPaymentWithoutPartnerShowsClientOfInvoice(): void
    {
        $reg = $this->makeRegister();
        $client = $this->client('Odběratel PDF s.r.o.', true, false);
        $this->setClientIc($client, '11223344');
        $invoiceId = $this->saleInvoice('FV-2099-302', $client, 2000.00, $this->currencyId);
        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 500.00, 'invoice_id' => $invoiceId,
        ], $reg), $this->userId);
        $this->db->pdo()->prepare('UPDATE cash_documents SET partner_name = NULL, partner_ic = NULL, partner_dic = NULL WHERE id = ?')
            ->execute([$res['id']]);

        $document = $this->service->pdfData($this->supplierId, $res['id'])['document'];
        self::assertSame('Odběratel PDF s.r.o.', $document['partner_name']);
        self::assertSame('11223344', $document['partner_ic']);
        self::assertSame('CZ12345678', $document['partner_dic']);
        $this->assertPartner($res['id'], null, null, null);
    }

    /** Našeptávač nese IČ a DIČ partnera, aby je formulář předvyplnil spolu s názvem. */
    public function testUnpaidSearchReturnsPartnerIcAndDic(): void
    {
        $client = $this->client('Odběratel IČ s.r.o.', true, false);
        $this->setClientIc($client, '12345679');
        $invoiceId = $this->saleInvoice('FV-2099-310', $client, 1000.00, $this->currencyId);
        $vendor = $this->client('Dodavatel IČ a.s.', false, true);
        $pf = $this->purchaseInvoice('PF-2099-310', $vendor, 1000.00, $this->currencyId);

        $invoices = $this->service->searchUnpaid($this->supplierId, 'invoice', 'FV-2099-310');
        self::assertSame($invoiceId, $invoices[0]['id']);
        self::assertSame('12345679', $invoices[0]['partner_ic']);
        self::assertSame('CZ12345678', $invoices[0]['partner_dic']);

        $purchases = $this->service->searchUnpaid($this->supplierId, 'purchase_invoice', 'PF-2099-310');
        self::assertSame($pf, $purchases[0]['id']);
        self::assertNull($purchases[0]['partner_ic']);
        self::assertSame('CZ12345678', $purchases[0]['partner_dic']);
    }

    private function setClientIc(int $clientId, string $ic): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET ic = ? WHERE id = ?')->execute([$ic, $clientId]);
    }

    private function assertPartner(int $docId, ?string $name, ?string $ic, ?string $dic): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT partner_name, partner_ic, partner_dic FROM cash_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $row = (array) $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['partner_name' => $name, 'partner_ic' => $ic, 'partner_dic' => $dic], $row);
    }

    private function makeExpenseAccount(string $code, bool $active = true, ?int $supplierId = null, string $type = 'expense'): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
             VALUES (?, ?, 'Nákladový účet', ?, 'debit', 0, ?)"
        )->execute([$supplierId ?? $this->supplierId, $code, $type, $active ? 1 : 0]);
    }

    private function makeCashAnalytic(string $code): string
    {
        $this->db->pdo()->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
             VALUES (?, ?, 'Pokladna vedlejší', 'asset', 'debit', 0, 1)
             ON DUPLICATE KEY UPDATE is_active = 1"
        )->execute([$this->supplierId, $code]);
        return $code;
    }

    private function makeRegister(string $code = '211'): int
    {
        return $this->registers->create($this->supplierId, ['name' => 'Pokladna ' . $code, 'account_code' => $code, 'is_default' => true]);
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function doc(array $over, int $registerId): array
    {
        return array_merge([
            'register_id' => $registerId,
            'issue_date'  => self::YEAR . '-06-15',
            'description' => 'Pokladní pohyb',
            'post'        => true,
        ], $over);
    }

    /** @return array<string,mixed> */
    private function sale(int $registerId, float $total): array
    {
        return $this->doc(['purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => $total], $registerId);
    }

    private function countDocuments(int $registerId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM cash_documents WHERE supplier_id = ? AND register_id = ?');
        $stmt->execute([$this->supplierId, $registerId]);
        return (int) $stmt->fetchColumn();
    }

    private function docStatus(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM cash_documents WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array<string,array{debit:float,credit:float}>
     */
    private function linesByAccountCode(int $entryId): array
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        $out = [];
        foreach ($entry['lines'] as $l) {
            $code = $this->code((int) $l['account_id']);
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$l['side']] += (float) $l['amount'];
        }
        return $out;
    }

    private function code(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$accountId, $this->supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false ? '?' : (string) $v;
    }

    private function client(string $name, bool $customer, bool $vendor): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function saleInvoice(string $varsymbol, int $clientId, float $total, int $currencyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        );
        $issue = self::YEAR . '-06-10';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $currencyId, $total, $total, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function proforma(string $varsymbol, int $clientId, float $total): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, total_without_vat, total_vat,
                 total_with_vat, paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "proforma", ?, ?, NULL, ?, ?, 0, 1, 10000, 2100, ?, 0, "issued", "1", ?)'
        );
        $date = self::YEAR . '-06-10';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $date, $date, $this->currencyId, $total, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Záloha", 1, "ks", 10000, ?, 21, 10000, 2100, 12100, 0)'
        )->execute([$id, $this->vatRateId]);
        return $id;
    }

    private function purchaseInvoice(string $number, int $vendorId, float $total, int $currencyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", "40", "full", ?)'
        );
        $issue = self::YEAR . '-06-10';
        $stmt->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $currencyId, $total, $total, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function invoiceRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_total, amount_to_pay FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function paymentRows(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, source, amount FROM invoice_payments WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function purchaseStatus(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }
}
