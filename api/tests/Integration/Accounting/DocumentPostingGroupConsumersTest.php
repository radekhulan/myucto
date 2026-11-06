<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\Activation\PendingBackfillCounter;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Bank\BankTransactionReleaseService;
use MyInvoice\Service\Logbook\FuelExpenseReclassifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doklad se skupinou zaúčtování, jehož člen se zdrojem někdo v deníku stornoval,
 * drží předpis dál vlastněným zápisem DPH. Všechny cesty, které se ptají „je doklad
 * zaúčtovaný", ho proto musí brát jako zaúčtovaný. Nativní doklad bez skupiny je
 * po stornu jediného zápisu nezaúčtovaný jako dřív.
 *
 * Vše běží v transakci, kterou tearDown rollbackne.
 */
#[Group('integration')]
final class DocumentPostingGroupConsumersTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private \DI\Container $container;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->posting = $this->container->get(PostingService::class);
            $seeder = $this->container->get(ChartOfAccountsSeeder::class);
            $periods = $this->container->get(AccountingPeriodRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testDocumentLockTreatsRemainingOwnedMemberAsPosted(): void
    {
        $grouped = $this->purchaseWithReversedSource('LOCK', true);
        $native = $this->purchaseWithReversedSource('LOCK-N', false);
        $locks = $this->container->get(DocumentLockService::class);

        self::assertTrue($locks->forPurchaseInvoice($this->purchaseRow($grouped))->posted,
            'Vlastněná DPH drží doklad zamčený i po stornu členu se zdrojem.');
        self::assertFalse($locks->forPurchaseInvoice($this->purchaseRow($native))->posted);

        $fresh = $this->container->get(DocumentLockService::class);
        $map = $fresh->lockedMapForSources($this->supplierId, 'purchase_invoice', [$grouped, $native]);
        self::assertTrue($map[$grouped]->posted, 'Dávkový zámek pro seznamy rozhoduje stejně.');
        self::assertFalse($map[$native]->posted);
    }

    public function testInvoiceDeletionSeesPostingTraceOfOwnedMember(): void
    {
        $grouped = $this->invoiceWithReversedSource('DEL', true);
        $native = $this->invoiceWithReversedSource('DEL-N', false);
        $action = $this->container->get(DeleteInvoiceAction::class);
        $trace = new \ReflectionMethod($action, 'hasPostingTrace');

        self::assertTrue($trace->invoke($action, $this->supplierId, [$grouped]),
            'Smazání nesmí faktuře se živou DPH skupiny projít jako nezaúčtované.');
        self::assertFalse($trace->invoke($action, $this->supplierId, [$native]));
        self::assertTrue($this->container->get(DocumentLockService::class)
            ->forInvoice($this->invoiceRow($grouped))->posted);
    }

    public function testUnpostedCountsSkipDocumentWithOwnedMember(): void
    {
        $counter = new PendingBackfillCounter($this->db);
        $baseline = $counter->count($this->supplierId, self::YEAR . '-01-01');
        $groupedPurchase = $this->purchaseWithReversedSource('CNT', true);
        $nativePurchase = $this->purchaseWithReversedSource('CNT-N', false);
        $groupedInvoice = $this->invoiceWithReversedSource('CNT', true);
        $nativeInvoice = $this->invoiceWithReversedSource('CNT-N', false);
        $closing = new ClosingRepository($this->db);
        $from = self::YEAR . '-01-01';
        $to = self::YEAR . '-12-31';

        $purchases = array_column($closing->unpostedPurchases($this->supplierId, $from, $to), 'id');
        self::assertNotContains($groupedPurchase, $purchases, 'Uzávěrka nesmí hlásit doklad se živou DPH jako nezaúčtovaný.');
        self::assertContains($nativePurchase, $purchases);
        $invoices = array_column($closing->unpostedInvoices($this->supplierId, $from, $to), 'id');
        self::assertNotContains($groupedInvoice, $invoices);
        self::assertContains($nativeInvoice, $invoices);

        $counts = $counter->count($this->supplierId, self::YEAR . '-01-01');
        self::assertSame($baseline['purchase_invoices'] + 1, $counts['purchase_invoices'], 'Doúčtování počítá jen nativní doklad bez předpisu.');
        self::assertSame($baseline['invoices'] + 1, $counts['invoices']);
    }

    public function testReleasedPaymentRestoresBookedStatusForOwnedMember(): void
    {
        $grouped = $this->purchaseWithReversedSource('PAY', true);
        $native = $this->purchaseWithReversedSource('PAY-N', false);
        $paidAt = self::YEAR . '-06-25';
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE supplier_id = ? AND id IN (?, ?)")
            ->execute([$paidAt, $this->supplierId, $grouped, $native]);
        $release = $this->container->get(BankTransactionReleaseService::class);
        (new \ReflectionMethod($release, 'restorePurchaseInvoices'))
            ->invoke($release, $this->db->pdo(), $this->supplierId, [$grouped, $native], $paidAt);

        self::assertSame('booked', $this->purchaseRow($grouped)['status'],
            'Uvolněná platba vrací doklad se živou DPH mezi zaúčtované.');
        self::assertSame('received', $this->purchaseRow($native)['status']);
    }

    public function testAdvanceUnlinkAndFuelReclassificationSeeOwnedMember(): void
    {
        $grouped = $this->purchaseWithReversedSource('RCL', true);
        $native = $this->purchaseWithReversedSource('RCL-N', false);

        $fuel = $this->container->get(FuelExpenseReclassifier::class);
        $isPosted = new \ReflectionMethod($fuel, 'isPosted');
        self::assertTrue($isPosted->invoke($fuel, $this->supplierId, $grouped),
            'Reklasifikace paliva musí doklad se živou DPH přeúčtovat, ne čekat na auto-post.');
        self::assertFalse($isPosted->invoke($fuel, $this->supplierId, $native));

        $repo = $this->container->get(PurchaseInvoiceRepository::class);
        try {
            $repo->unlinkAdvance($grouped, $this->supplierId);
            self::fail('Zálohu nelze odpojit od dokladu se živou DPH skupiny.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Zaúčtovanou', $e->getMessage());
        }
    }

    public function testSharedSqlFragmentMatchesDirectAndOwnedPosting(): void
    {
        $grouped = $this->purchaseWithReversedSource('SQL', true);
        $native = $this->purchaseWithReversedSource('SQL-N', false);
        $live = $this->purchase('TEST-DPG-SQL-L', $this->vendor('Test živý nativní'));
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $live, $this->baseLines(), ['entry_date' => self::YEAR . '-06-20']);

        $stmt = $this->db->pdo()->prepare('SELECT pi.id FROM purchase_invoices pi WHERE pi.supplier_id = ? AND pi.id IN (?, ?, ?) AND '
            . DocumentPostingGroup::activePostingExistsSql("'purchase_invoice'", 'pi.id', 'pi.supplier_id') . ' ORDER BY pi.id');
        $stmt->execute([$this->supplierId, $grouped, $native, $live]);
        self::assertSame([$grouped, $live], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * Přijatá faktura: základ zaúčtovaný se zdrojem dokladu, DPH (u skupiny) jako
     * samostatný ruční zápis vlastněný dokladem. Člen se zdrojem se pak storuje.
     */
    private function purchaseWithReversedSource(string $tag, bool $grouped): int
    {
        $doc = $this->purchase('TEST-DPG-' . $tag, $this->vendor('Test skupiny ' . $tag));
        $base = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $doc, $this->baseLines(),
            ['entry_date' => self::YEAR . '-06-20']);
        if ($grouped) {
            $vat = $this->posting->postDocument($this->supplierId, 'manual', null, [
                ['account_code' => '343', 'side' => 'debit', 'amount' => 210.0],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 210.0],
            ], ['entry_date' => self::YEAR . '-06-20']);
            (new DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $doc, [$base, $vat]);
        }
        $this->posting->reverse($this->supplierId, $base);
        return $doc;
    }

    private function invoiceWithReversedSource(string $tag, bool $grouped): int
    {
        $client = $this->vendor('Test odběratel ' . $tag);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 1000, 210, 1210, "issued", "1", ?)'
        )->execute([$this->supplierId, 'TEST-DPG-' . $tag, $client, self::YEAR . '-06-15', self::YEAR . '-06-15',
            self::YEAR . '-06-29', $this->currencyId, $this->userId]);
        $doc = (int) $this->db->pdo()->lastInsertId();
        $this->item('invoice_items', 'invoice_id', $doc);
        $base = $this->posting->postDocument($this->supplierId, 'invoice', $doc, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.0],
        ], ['entry_date' => self::YEAR . '-06-20']);
        if ($grouped) {
            $vat = $this->posting->postDocument($this->supplierId, 'manual', null, [
                ['account_code' => '311', 'side' => 'debit', 'amount' => 210.0],
                ['account_code' => '343', 'side' => 'credit', 'amount' => 210.0],
            ], ['entry_date' => self::YEAR . '-06-20']);
            (new DocumentPostingGroup($this->db))->attach($this->supplierId, 'invoice', $doc, [$base, $vat]);
        }
        $this->posting->reverse($this->supplierId, $base);
        return $doc;
    }

    /** @return list<array<string,mixed>> */
    private function baseLines(): array
    {
        return [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.0],
        ];
    }

    private function vendor(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(string $number, int $vendorId): int
    {
        $issue = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 1000, 210, 1210, "received", "40", "full", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->item('purchase_invoice_items', 'purchase_invoice_id', $id);
        return $id;
    }

    private function item(string $table, string $fk, int $id): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', 1000, ?, 21, 1000, 210, 1210, 0)"
        )->execute([$id, $this->vatRateId]);
    }

    /** @return array<string,mixed> */
    private function purchaseRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM purchase_invoices WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function invoiceRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoices WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
