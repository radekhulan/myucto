<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\DimensionAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Hromadné nastavení dimenzí na seznamu vydaných faktur
 * (POST /api/accounting/dimensions/documents/invoices/bulk).
 *
 * Koncept i zaúčtovaná faktura jdou toutéž cestou jako panel Dimenze na detailu:
 * hlavička dokladu se změní jen ve zvolených typech a promítne se do zaúčtovaných
 * řádků deníku. Doklad v uzavřeném období a doklad jiné firmy se přeskočí s důvodem.
 * Vše v jedné transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class DimensionBulkInvoiceTest extends TestCase
{
    private const YEAR = 2094;

    private Connection $db;
    private PostingService $posting;
    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    private AccountingPeriodRepository $periods;
    private DimensionAction $action;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $clientId = 0;
    private int $previousPeriodId = 0;
    private int $projectType = 0;
    private int $centerType = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->dimensions = $container->get(DimensionService::class);
            $this->assignments = $container->get(DimensionAssignmentRepository::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->action = $container->get(DimensionAction::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierId = $this->supplier('Hromadné dimenze s.r.o.');
        $seeder->seedForSupplier($this->supplierId);
        $this->previousPeriodId = $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->clientId = $this->client($this->supplierId);
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        $this->projectType = $types['project'];
        $this->centerType = $types['cost_center'];
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

    public function testSetsDimensionOnDraftAndPostedInvoiceIncludingJournalLines(): void
    {
        $project = $this->value($this->projectType, 'BP-1');
        $center = $this->value($this->centerType, 'BC-1');
        $draft = $this->invoice('BULK-DRAFT', 'draft', self::YEAR . '-03-10');
        $posted = $this->invoice('BULK-POSTED', 'sent', self::YEAR . '-03-10');
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $posted, [$this->projectType => $project], null);
        $entryId = $this->post($posted, self::YEAR . '-03-10');

        $res = $this->payload($this->bulk([$draft, $posted], [$this->centerType => $center]));

        self::assertSame([$draft, $posted], $res['updated']);
        self::assertSame([], $res['skipped']);
        self::assertSame([$this->centerType => $center], $this->assignments->documentDimensions($this->supplierId, 'invoice', $draft)['header']);
        $header = $this->assignments->documentDimensions($this->supplierId, 'invoice', $posted)['header'];
        $expected = [$this->projectType => $project, $this->centerType => $center];
        ksort($expected);
        ksort($header);
        self::assertSame($expected, $header, 'Nezvolený typ (projekt) zůstává.');

        $revenue = $this->revenueLineDimensions($entryId);
        self::assertNotEmpty($revenue);
        foreach ($revenue as $dims) {
            self::assertSame($center, $dims[$this->centerType] ?? null, 'Dimenze se promítne do zaúčtovaných řádků.');
            self::assertSame($project, $dims[$this->projectType] ?? null);
        }

        $log = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = 'dimension.document_updated' AND entity_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $log->execute([$this->supplierId, $posted]);
        $payload = json_decode((string) $log->fetchColumn(), true);
        self::assertSame('bulk', $payload['via'] ?? null, 'Hromadná změna je v auditním logu jako u jednotlivé.');
        self::assertGreaterThan(0, $payload['restamped_lines']);

        $again = $this->payload($this->bulk([$posted], [$this->centerType => $center]));
        self::assertSame([], $again['updated']);
        self::assertSame([$posted], $again['unchanged']);
    }

    public function testRemovesTypeFromHeaderAndPostedLines(): void
    {
        $project = $this->value($this->projectType, 'RP-1');
        $center = $this->value($this->centerType, 'RC-1');
        $posted = $this->invoice('BULK-REMOVE', 'sent', self::YEAR . '-04-10');
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $posted, [$this->projectType => $project, $this->centerType => $center], null);
        $entryId = $this->post($posted, self::YEAR . '-04-10');

        $res = $this->payload($this->bulk([$posted], [$this->centerType => null]));

        self::assertSame([$posted], $res['updated']);
        self::assertSame([$this->projectType => $project], $this->assignments->documentDimensions($this->supplierId, 'invoice', $posted)['header']);
        foreach ($this->revenueLineDimensions($entryId) as $dims) {
            self::assertArrayNotHasKey($this->centerType, $dims, 'Odebraný typ zmizí i z řádků deníku.');
            self::assertSame($project, $dims[$this->projectType] ?? null);
        }
    }

    public function testRemovalDropsHeaderSplitAndSettingReplacesIt(): void
    {
        $a = $this->value($this->centerType, 'SP-A');
        $b = $this->value($this->centerType, 'SP-B');
        $split = [0 => [$this->centerType => [['value_id' => $a, 'share' => 0.5], ['value_id' => $b, 'share' => 0.5]]]];
        $removed = $this->invoice('BULK-SPLIT-RM', 'draft', self::YEAR . '-04-12');
        $replaced = $this->invoice('BULK-SPLIT-SET', 'draft', self::YEAR . '-04-12');
        foreach ([$removed, $replaced] as $id) {
            $this->dimensions->saveDocument($this->supplierId, 'invoice', $id, [], null, false, $split);
        }

        self::assertSame([$removed], $this->payload($this->bulk([$removed], [$this->centerType => null]))['updated']);
        self::assertSame([], $this->assignments->documentSplits($this->supplierId, 'invoice', $removed), 'Odebraný typ nenechá rozpad hlavičky.');

        self::assertSame([$replaced], $this->payload($this->bulk([$replaced], [$this->centerType => $a]))['updated']);
        self::assertSame([], $this->assignments->documentSplits($this->supplierId, 'invoice', $replaced));
        self::assertSame([$this->centerType => $a], $this->assignments->documentDimensions($this->supplierId, 'invoice', $replaced)['header']);
    }

    public function testSkipsClosedPeriodAndForeignInvoiceWithReason(): void
    {
        $center = $this->value($this->centerType, 'SC-1');
        $old = $this->value($this->centerType, 'SC-OLD');
        $closed = $this->invoice('BULK-CLOSED', 'sent', (self::YEAR - 1) . '-11-10');
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $closed, [$this->centerType => $old], null);
        $closedEntry = $this->post($closed, (self::YEAR - 1) . '-11-10');
        $this->periods->setStatus($this->previousPeriodId, $this->supplierId, 'closed');
        $open = $this->invoice('BULK-OPEN', 'sent', self::YEAR . '-05-10');
        $this->post($open, self::YEAR . '-05-10');

        $foreignSupplier = $this->supplier('Cizí firma s.r.o.');
        $this->dimensions->setEnabled($foreignSupplier, true);
        $foreign = $this->invoice('BULK-FOREIGN', 'draft', self::YEAR . '-05-10', $foreignSupplier, $this->client($foreignSupplier));
        $linesBefore = $this->revenueLineDimensions($closedEntry);

        $res = $this->payload($this->bulk([$closed, $open, $foreign], [$this->centerType => $center]));

        self::assertSame([$open], $res['updated']);
        $skipped = [];
        foreach ($res['skipped'] as $row) {
            $skipped[(int) $row['id']] = $row;
        }
        self::assertSame([$closed, $foreign], array_keys($skipped));
        self::assertSame('period_not_open', $skipped[$closed]['code']);
        self::assertSame('BULK-CLOSED', $skipped[$closed]['varsymbol']);
        self::assertNotSame('', (string) $skipped[$closed]['message']);
        self::assertSame('not_found', $skipped[$foreign]['code']);
        self::assertNull($skipped[$foreign]['varsymbol'], 'Číslo cizího dokladu se neprozradí.');
        self::assertSame([$this->centerType => $old], $this->assignments->documentDimensions($this->supplierId, 'invoice', $closed)['header']);
        self::assertSame($linesBefore, $this->revenueLineDimensions($closedEntry));
        self::assertSame([], $this->assignments->documentDimensions($foreignSupplier, 'invoice', $foreign)['header']);
    }

    public function testRejectsInvalidRequest(): void
    {
        $draft = $this->invoice('BULK-INVALID', 'draft', self::YEAR . '-06-10');
        $center = $this->value($this->centerType, 'IC-1');

        self::assertSame(400, $this->bulk([$draft], [])['status'], 'Bez zvolené dimenze není co měnit.');
        self::assertSame(400, $this->bulk([], [$this->centerType => $center])['status']);
        self::assertSame(400, $this->bulk([$draft], [$this->projectType => $center])['status'], 'Hodnota jiného typu.');
        self::assertSame(400, $this->bulk([$draft], [$this->centerType + 999_999 => null])['status'], 'Neznámý typ.');
        self::assertSame([], $this->assignments->documentDimensions($this->supplierId, 'invoice', $draft)['header']);

        $this->dimensions->setEnabled($this->supplierId, false);
        self::assertSame(409, $this->bulk([$draft], [$this->centerType => $center])['status'], 'Vypnuté dimenze firmy.');
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /**
     * @param list<int> $ids
     * @param array<int,int|null> $dimensions
     * @return array{status:int, body:array<string,mixed>}
     */
    private function bulk(array $ids, array $dimensions): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting/dimensions/documents/invoices/bulk')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody(['ids' => $ids, 'dimensions' => $dimensions]);
        $resp = $this->action->bulkDocuments($req, new Psr7Response(), ['doc' => 'invoices']);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * @param array{status:int, body:array<string,mixed>} $res
     * @return array{updated:list<int>, unchanged:list<int>, needs_repost:list<int>, skipped:list<array<string,mixed>>}
     */
    private function payload(array $res): array
    {
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_THROW_ON_ERROR));
        return $res['body'];
    }

    /** @return array<int,array<int,int>> řádek výnosu => typ => hodnota */
    private function revenueLineDimensions(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND a.account_code LIKE '6%' ORDER BY l.id"
        );
        $stmt->execute([$this->supplierId, $entryId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $all = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $all[$id] ?? [];
        }
        return $out;
    }

    private function post(int $invoiceId, string $date): int
    {
        return $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.0],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    private function supplier(string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute([$name, $this->czId, 'bulk-dimensions-' . uniqid() . '@example.invalid', $this->currencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET supplier_group_id = NULL WHERE id = ?')->execute([$id]);
        return $id;
    }

    private function client(int $supplierId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Odběratel", "Test 1", "Praha", "11000", ?, "customer@example.invalid", "cs", ?, 1, 0)'
        )->execute([$supplierId, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoice(string $number, string $status, string $date, ?int $supplierId = null, ?int $clientId = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status,
                 vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 1000, 0, 1000, 0, ?, "1", ?)'
        )->execute([
            $supplierId ?? $this->supplierId, $number, $clientId ?? $this->clientId, $date, $date, $date,
            $this->currencyId, $status, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Služba", 1, "ks", 1000, ?, 0, 1000, 0, 1000, 0)'
        )->execute([$id, $this->vatRateId]);
        return $id;
    }

    private function value(int $typeId, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code])['id'];
    }
}
