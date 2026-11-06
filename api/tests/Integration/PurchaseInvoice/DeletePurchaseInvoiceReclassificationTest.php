<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentJournalPurge;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Přijatá faktura a její zápis v přeúčtování historie. Záznam kontroly ani provedeného
 * přeúčtování mazání dokladu ani zápisu nebrání, dříve skončilo cizím klíčem a chybou 500.
 * Vše v transakci s rollbackem.
 */
#[Group('integration')]
final class DeletePurchaseInvoiceReclassificationTest extends TestCase
{
    private const AMOUNT = 1000.0;

    private ContainerInterface $container;
    private Connection $db;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $vatRateId = 0;
    private int $czkId = 0;
    private int $vendorId = 0;
    private int $jobId = 0;
    private int $bundleId = 0;
    private string $date = '';
    private bool $inTx = false;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();

        $this->supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId     = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->vatRateId  = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $czId             = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->czkId      = (int) $pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' LIMIT 1")->fetchColumn();
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $czId === 0 || $this->czkId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $year = (int) date('Y');
        $this->date = $year . '-03-10';

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $periods = $this->container->get(AccountingPeriodRepository::class);
        if ($periods->findByYear($this->supplierId, $year) === null) {
            $periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
        }
        $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')
            ->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_customer, is_vat_payer)
             VALUES (?, "Přeúčtování partner s.r.o. (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "reclass-delete@example.test", "cs", ?, 1, 0, 1)'
        )->execute([$this->supplierId, $czId, $this->czkId]);
        $this->vendorId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO import_jobs (supplier_id, source, created_by) VALUES (?, 'accounting_history_reclassification', ?)")
            ->execute([$this->supplierId, $this->userId]);
        $this->jobId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO accounting_setup_runs (supplier_id, job_id, scope_json, catalog_version, created_by) VALUES (?, ?, '{}', 1, ?)")
            ->execute([$this->supplierId, $this->jobId, $this->userId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO accounting_setup_rule_bundles (run_id, supplier_id, bundle_hash, input_hash, payload_json, approved_by) VALUES (?, ?, ?, ?, '{}', ?)")
            ->execute([$runId, $this->supplierId, str_repeat('a', 64), str_repeat('b', 64), $this->userId]);
        $this->bundleId = (int) $pdo->lastInsertId();
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

    public function testDryRunItemDoesNotBlockDeleteAndGoesWithTheDocument(): void
    {
        $id = $this->postedPurchase();
        $this->reclassItem($id, 'would_change');

        $res = $this->delete($id);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertFalse($this->exists('purchase_invoices', 'id', $id));
        self::assertFalse($this->exists('accounting_reclassification_items', 'purchase_invoice_id', $id));
    }

    public function testAppliedReclassificationDoesNotBlockDelete(): void
    {
        $id = $this->postedPurchase();
        $this->reclassItem($id, 'applied');

        $res = $this->delete($id);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertFalse($this->exists('purchase_invoices', 'id', $id));
        self::assertFalse($this->exists('accounting_reclassification_items', 'purchase_invoice_id', $id));
    }

    public function testDryRunItemDoesNotBlockJournalEntryDelete(): void
    {
        $id = $this->postedPurchase();
        $entryId = $this->entryId($id);
        $this->reclassItem($id, 'would_change', $entryId);

        $res = $this->callJournal('delete', $entryId);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertFalse($this->exists('journal_entries', 'id', $entryId));
        self::assertTrue($this->exists('accounting_reclassification_items', 'purchase_invoice_id', $id), 'Záznam kontroly zůstává.');
        self::assertFalse($this->exists('accounting_reclassification_items', 'correction_entry_id', $entryId));
    }

    public function testDryRunItemDoesNotBlockReversalPairDelete(): void
    {
        $id = $this->postedPurchase();
        $entryId = $this->entryId($id);
        $reversalId = $this->reverse($entryId);
        $this->reclassItem($id, 'would_change', $entryId);

        $res = $this->callJournal('deleteReversalPair', $entryId);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertFalse($this->exists('journal_entries', 'id', $entryId));
        self::assertFalse($this->exists('journal_entries', 'id', $reversalId));
    }

    public function testAppliedReclassificationDoesNotBlockJournalDelete(): void
    {
        $id = $this->postedPurchase();
        $entryId = $this->entryId($id);
        $this->reclassItem($id, 'applied', $entryId);

        $res = $this->callJournal('delete', $entryId);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertFalse($this->exists('journal_entries', 'id', $entryId));
        self::assertTrue($this->exists('accounting_reclassification_items', 'purchase_invoice_id', $id), 'Podklad přeúčtování zůstává.');
    }

    public function testAppliedReclassificationDoesNotBlockReversalPairDelete(): void
    {
        $id = $this->postedPurchase();
        $entryId = $this->entryId($id);
        $reversalId = $this->reverse($entryId);
        $this->reclassItem($id, 'applied', $entryId);

        $res = $this->callJournal('deleteReversalPair', $entryId);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertFalse($this->exists('journal_entries', 'id', $entryId));
        self::assertFalse($this->exists('journal_entries', 'id', $reversalId));
    }

    public function testJournalPurgeDetachesReclassificationItems(): void
    {
        $purge = $this->container->get(DocumentJournalPurge::class);

        foreach (['would_change', 'applied'] as $status) {
            $id = $this->postedPurchase();
            $entryId = $this->entryId($id);
            $this->reclassItem($id, $status, $entryId);
            $result = $purge->purge($this->supplierId, 'purchase_invoice', [$id], ['user_id' => $this->userId], 'test');
            self::assertNull($result['blocked'], $status);
            self::assertFalse($this->exists('journal_entries', 'id', $entryId), $status);
        }
    }

    private function entryId(int $purchaseId): int
    {
        $entry = $this->container->get(JournalEntryRepository::class)->findBySource($this->supplierId, 'purchase_invoice', $purchaseId);
        self::assertNotNull($entry);
        return (int) $entry['id'];
    }

    private function reverse(int $entryId): int
    {
        return $this->container->get(PostingService::class)->reverse($this->supplierId, $entryId, [
            'user_id' => $this->userId,
            'posted_by' => $this->userId,
            'entry_date' => $this->date,
        ]);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function callJournal(string $method, int $entryId): array
    {
        $action = $this->container->get(JournalAction::class);
        $req = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/accounting/journal/' . $entryId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $response = $action->{$method}($req, new Psr7Response(), ['id' => (string) $entryId]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function postedPurchase(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, 1, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $this->vendorId, 'RECLASS-' . bin2hex(random_bytes(3)),
            $this->date, $this->date, $this->date, $this->date, $this->czkId,
            self::AMOUNT, self::AMOUNT, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code)
             VALUES (?, "Služba (PHPUnit)", 1, "ks", ?, ?, 0, ?, 0, ?, 0, "40")'
        )->execute([$id, self::AMOUNT, $this->vatRateId, self::AMOUNT, self::AMOUNT]);

        $posting = $this->container->get(PostingService::class);
        $posting->postDocument($this->supplierId, 'purchase_invoice', $id, $posting->buildFromPurchaseInvoice($this->supplierId, $id), [
            'entry_date' => $this->date,
            'posted' => true,
            'posted_by' => $this->userId,
            'user_id' => $this->userId,
        ]);

        return $id;
    }

    private function reclassItem(int $invoiceId, string $status, ?int $entryId = null): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_reclassification_items (job_id, bundle_id, supplier_id, purchase_invoice_id, status, correction_entry_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$this->jobId, $this->bundleId, $this->supplierId, $invoiceId, $status, $entryId]);
    }

    private function exists(string $table, string $column, int $id): bool
    {
        return $this->db->pdo()->query("SELECT 1 FROM $table WHERE $column = $id LIMIT 1")->fetchColumn() !== false;
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function delete(int $id): array
    {
        $action = $this->container->get(DeletePurchaseInvoiceAction::class);
        $req = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/test/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withQueryParams(['force' => '1']);
        $response = $action($req, new Psr7Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
