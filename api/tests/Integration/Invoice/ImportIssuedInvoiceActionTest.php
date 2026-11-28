<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\ImportIssuedInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Import už vystaveného dokladu přes API (issue #145): doklad z e-shopu se uloží
 * s původním číslem a DOSLOVA s jeho částkami, bez přepočtu a bez nového vystavení.
 *
 * U každé odmítnuté cesty se ověřuje i to, že doklad NEVZNIKL — test, který by
 * kontroloval jen návratový kód, by svítil zeleně i nad akcí, která doklad zapsala
 * a jen o tom lhala.
 *
 * Data jsou syntetická, v daleké budoucnosti (2096–2098), aby se nepotkala se
 * skutečnými doklady ani podáními; tearDown vrací dodavatele do původního stavu.
 */
#[Group('integration')]
final class ImportIssuedInvoiceActionTest extends TestCase
{
    private const TAX_DATE = '2096-05-15';

    private Connection $db;
    private ImportIssuedInvoiceAction $action;
    private VatLedgerService $ledger;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private string $prefix = '';

    /** @var array<string,mixed> */
    private array $supplierBackup = [];

    /** @var list<int> */
    private array $historyIds = [];

    /** @var list<int> */
    private array $submissionIds = [];

    /** @var list<int> */
    private array $periodIds = [];

    /** @var array<int,int> */
    private array $dimensionTypes = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(ImportIssuedInvoiceAction::class);
            $this->ledger = $c->get(VatLedgerService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('invoices', 'corrective_delivered_on')) {
            $this->markTestSkipped('Chybí migrace 1159.');
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }
        $stmt = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1");
        $stmt->execute([$this->supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        if ($this->currencyId === 0) {
            $this->markTestSkipped('Dodavatel nemá měnu CZK.');
        }

        $stmt = $pdo->prepare('SELECT accounting_mode, dimensions_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $this->supplierBackup = $stmt->fetch(PDO::FETCH_ASSOC);
        // Daňová evidence: bez deníku a bez automatického zaúčtování, které by po sobě
        // nechalo zápisy. Uzavřené období si test zapne sám.
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);

        $this->prefix = 'ESHOP-' . strtoupper(bin2hex(random_bytes(3)));
        $this->clientId = $this->createClient('CZ99999999');
        $this->vatStatus('2096-01-01', payer: true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();

        // Activity log se ZÁMĚRNĚ neuklízí: řádky drží hash chain.
        if ($this->clientId > 0) {
            $ids = $this->invoiceIds();
            if ($ids !== []) {
                $in = implode(',', $ids);
                $pdo->exec("DELETE FROM credit_note_offsets WHERE invoice_id IN ({$in}) OR credit_note_id IN ({$in})");
                $pdo->exec("DELETE FROM invoice_payments WHERE invoice_id IN ({$in})");
                $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ({$in})");
                $pdo->prepare("DELETE FROM document_dimensions WHERE supplier_id = ? AND doc_type = 'invoice' AND doc_id IN ({$in})")
                    ->execute([$this->supplierId]);
                foreach (array_reverse($ids) as $id) {
                    $pdo->exec("DELETE FROM invoices WHERE id = {$id}");
                }
            }
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        foreach ($this->historyIds as $id) {
            $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE id = ?')->execute([$id]);
        }
        foreach ($this->submissionIds as $id) {
            $pdo->prepare('DELETE FROM tax_submissions WHERE id = ?')->execute([$id]);
        }
        foreach ($this->periodIds as $id) {
            $pdo->prepare('DELETE FROM accounting_periods WHERE id = ?')->execute([$id]);
        }
        foreach ($this->dimensionTypes as $typeId => $supplierId) {
            $pdo->prepare('DELETE FROM dimension_types WHERE supplier_id = ? AND id = ?')->execute([$supplierId, $typeId]);
        }
        if ($this->supplierBackup !== []) {
            $pdo->prepare('UPDATE supplier SET accounting_mode = ?, dimensions_enabled = ? WHERE id = ?')
                ->execute([$this->supplierBackup['accounting_mode'], $this->supplierBackup['dimensions_enabled'], $this->supplierId]);
        }

        $this->db->close();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Šťastná cesta
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Jádro issue: e-shop zaokrouhlil daň po DOKLADU, takže první řádek nese 20,99 místo
     * 21,00, které by z něj spočítal přepočet po řádcích. Uložit se musí to, co přišlo.
     */
    public function testAmountsAreStoredExactlyAsSentWithoutRecompute(): void
    {
        $result = $this->call($this->payload());

        self::assertSame(201, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $result['body']['id'];

        // Bez téhle kontroly by test netvrdil nic: kdyby přepočet dal totéž, nepoznali
        // bychom, jestli akce přepočítává.
        $naive = InvoiceMath::compute([['quantity' => 3, 'unit_price_without_vat' => 33.33, 'vat_rate_snapshot' => 21.0]]);
        self::assertSame(21.0, (float) $naive['items'][0]['vat'], 'Přepočet po řádku má dát 21,00 — jinak je fixtura bezzubá.');

        $row = $this->invoice($id);
        self::assertSame('issued', $row['status']);
        self::assertSame($this->prefix . '-1', $row['varsymbol']);
        self::assertSame('199.99', $row['total_without_vat']);
        self::assertSame('42.00', $row['total_vat']);
        self::assertSame('242.00', $row['total_with_vat']);
        self::assertSame('0.01', $row['rounding']);
        self::assertSame(self::TAX_DATE, $row['tax_date']);
        self::assertNotNull($row['client_snapshot']);
        self::assertNotNull($row['supplier_snapshot'], 'Vystavený doklad bez snapshotu dodavatele se nevytiskne.');
        self::assertNull($row['corrective_delivered_on']);

        $items = $this->items($id);
        self::assertCount(2, $items);
        self::assertSame('99.99', $items[0]['total_without_vat']);
        self::assertSame('20.99', $items[0]['total_vat'], 'Daň řádku se přepočítala — import ji má převzít doslova.');
        self::assertSame('120.98', $items[0]['total_with_vat']);
        self::assertSame('21.01', $items[1]['total_vat']);
        // Členění z téhož SSOT jako u dokladu z aplikace: tuzemský odběratel, základní sazba.
        self::assertSame('1', $items[0]['vat_classification_code']);
        self::assertSame(0, (int) $items[0]['oss_applicable']);

        self::assertTrue($this->hasActivityLog('invoice.imported', $id));
    }

    /** Evidence DPH (a z ní přiznání i KH) čte převzaté částky řádků, ne přepočet. */
    public function testVatLedgerReadsImportedLineAmounts(): void
    {
        $result = $this->call($this->payload());
        self::assertSame(201, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $result['body']['id'];

        $rows = array_values(array_filter(
            $this->ledger->rows($this->supplierId, '2096-05-01', '2096-05-31'),
            static fn (array $r): bool => ($r['source'] ?? '') === 'sale' && (int) $r['invoice_id'] === $id,
        ));
        self::assertNotSame([], $rows, 'Převzatý doklad v evidenci DPH chybí.');
        self::assertEqualsWithDelta(199.99, array_sum(array_column($rows, 'base_czk')), 0.001);
        self::assertEqualsWithDelta(42.00, array_sum(array_column($rows, 'vat_czk')), 0.001);
        self::assertContains(20.99, array_map(static fn (array $r): float => round((float) $r['vat_czk'], 2), $rows));
    }

    /**
     * Dobropis: záporné řádky jako u dobropisu z aplikace, vazba na opravovanou fakturu
     * podle jejího čísla a § 42 odst. 3 — den doručení se stane DUZP.
     */
    public function testCreditNoteLinkedByParentVarsymbolWithDeliveryDateAsTaxDate(): void
    {
        $parent = $this->call($this->payload());
        self::assertSame(201, $parent['status'], json_encode($parent['body'], JSON_UNESCAPED_UNICODE));

        $result = $this->call([
            'invoice_type'            => 'credit_note',
            'varsymbol'               => $this->prefix . '-D1',
            'client_id'               => $this->clientId,
            'issue_date'              => '2096-05-20',
            'tax_date'                => '2096-05-20',
            'due_date'                => '2096-06-03',
            'corrective_delivered_on' => '2096-06-02',
            'parent_varsymbol'        => $this->prefix . '-1',
            'prices_include_vat'      => false,
            'total_without_vat'       => -100.00,
            'total_vat'               => -21.00,
            'total_with_vat'          => -121.00,
            'items'                   => [[
                'description' => 'Vrácené zboží', 'quantity' => -1, 'unit' => 'ks',
                'unit_price_without_vat' => 100.00, 'vat_rate' => 21,
                'total_without_vat' => -100.00, 'total_vat' => -21.00, 'total_with_vat' => -121.00,
            ]],
        ]);

        self::assertSame(201, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $row = $this->invoice((int) $result['body']['id']);
        self::assertSame('credit_note', $row['invoice_type']);
        self::assertSame((int) $parent['body']['id'], (int) $row['parent_invoice_id']);
        self::assertSame('2096-06-02', $row['tax_date'], '§ 42 odst. 3: období opravy určuje den doručení.');
        self::assertSame('2096-06-02', $row['corrective_delivered_on']);
        self::assertSame('-121.00', $row['total_with_vat']);
        self::assertSame('-21.00', $this->items((int) $row['id'])[0]['total_vat']);
    }

    public function testOrdinaryApiTokenImportsInvoiceAndCreditNoteWithDifferentHeaderDimensions(): void
    {
        $this->setDimensionsEnabled(true);
        $type = $this->createDimensionType($this->supplierId);
        $firstShop = $this->createDimensionValue($this->supplierId, $type, 'SHOP-A');
        $secondShop = $this->createDimensionValue($this->supplierId, $type, 'SHOP-B');

        $invoice = $this->call($this->payload() + ['dimensions' => [$type => $firstShop]], 'read_write');
        self::assertSame(201, $invoice['status'], json_encode($invoice['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame([$type => $firstShop], $this->headerDimensions((int) $invoice['body']['id']));

        $payload = $this->payload();
        $payload['invoice_type'] = 'credit_note';
        $payload['varsymbol'] .= '-D';
        $payload['parent_invoice_id'] = $invoice['body']['id'];
        $payload['dimensions'] = [$type => $secondShop];
        foreach (['total_without_vat', 'total_vat', 'total_with_vat', 'rounding'] as $key) {
            $payload[$key] = -$payload[$key];
        }
        foreach ($payload['items'] as &$item) {
            foreach (['quantity', 'total_without_vat', 'total_vat', 'total_with_vat'] as $key) {
                $item[$key] = -$item[$key];
            }
        }
        unset($item);
        $creditNote = $this->call($payload, 'read_write');
        self::assertSame(201, $creditNote['status'], json_encode($creditNote['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame([$type => $secondShop], $this->headerDimensions((int) $creditNote['body']['id']));
        self::assertSame([$type => $firstShop], $this->headerDimensions((int) $invoice['body']['id']));
    }

    public function testImportRejectsForeignDimensionAndAllowsRetry(): void
    {
        $this->setDimensionsEnabled(true);
        $pdo = $this->db->pdo();
        $other = $pdo->prepare('SELECT id FROM supplier WHERE id <> ? ORDER BY id LIMIT 1');
        $other->execute([$this->supplierId]);
        $otherSupplierId = (int) $other->fetchColumn();
        self::assertGreaterThan(0, $otherSupplierId, 'Test vyžaduje druhou firmu z testovacích dat.');
        $foreignType = $this->createDimensionType($otherSupplierId);
        $foreignValue = $this->createDimensionValue($otherSupplierId, $foreignType, 'FOREIGN');

        $rejected = $this->call($this->payload() + ['dimensions' => [$foreignType => $foreignValue]], 'read_write');
        self::assertSame(400, $rejected['status']);
        self::assertSame('invalid_dimension', $rejected['body']['error']['code']);
        self::assertSame([], $this->invoiceIds());

        $type = $this->createDimensionType($this->supplierId);
        $value = $this->createDimensionValue($this->supplierId, $type, 'SHOP-A');
        $corrected = $this->call($this->payload() + ['dimensions' => [$type => $value]], 'read_write');
        self::assertSame(201, $corrected['status']);
        self::assertSame([$type => $value], $this->headerDimensions((int) $corrected['body']['id']));
    }

    public function testInvalidDimensionRollsBackImportInsideExistingTransaction(): void
    {
        $this->setDimensionsEnabled(true);
        $type = $this->createDimensionType($this->supplierId);
        $value = $this->createDimensionValue($this->supplierId, $type, 'CLOSED', false);
        $term = 'importdimension' . bin2hex(random_bytes(6));
        $valid = $this->payload();
        $valid['varsymbol'] .= '-P';
        $valid['items'][0]['description'] = $term;
        $invalid = $this->payload();
        $invalid['items'][0]['description'] = $term;
        $invalid['dimensions'] = [$type => $value];

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $first = $this->call($valid, 'read_write');
            self::assertSame(201, $first['status']);
            $firstId = (int) $first['body']['id'];

            $result = $this->call($invalid, 'read_write');
            self::assertSame(422, $result['status']);
            self::assertSame('dimension_closed', $result['body']['error']['code']);
            self::assertTrue($pdo->inTransaction());
            self::assertSame([$firstId], $this->invoiceIds());
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $search = $pdo->prepare('SELECT ii.invoice_id FROM invoice_items ii
            JOIN invoices i ON i.id = ii.invoice_id
            WHERE i.supplier_id = ? AND MATCH(ii.description) AGAINST (? IN BOOLEAN MODE)');
        $search->execute([$this->supplierId, $term]);
        self::assertSame([$firstId], array_map('intval', $search->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testImportWithoutDimensionsWorksWhenDimensionsAreDisabled(): void
    {
        $this->setDimensionsEnabled(false);
        $type = $this->createDimensionType($this->supplierId);
        $value = $this->createDimensionValue($this->supplierId, $type, 'SHOP-A');
        $invalid = $this->call($this->payload() + ['dimensions' => [$type => ['value_id' => $value]]], 'read_write');
        self::assertSame(422, $invalid['status']);
        self::assertArrayHasKey('dimensions', $invalid['body']['error']['fields']);
        $disabled = $this->call($this->payload() + ['dimensions' => [$type => $value]], 'read_write');
        self::assertSame(409, $disabled['status']);
        self::assertSame('dimensions_disabled', $disabled['body']['error']['code']);
        self::assertSame([], $this->invoiceIds());

        $imported = $this->call($this->payload(), 'read_write');
        self::assertSame(201, $imported['status']);
        self::assertSame([], $this->headerDimensions((int) $imported['body']['id']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Odmítnutí — u každého se ověřuje, že doklad nevznikl
    // ─────────────────────────────────────────────────────────────────────────

    /** Opakovaný import (retry e-shopu) se pozná podle id existujícího dokladu. */
    public function testDuplicateNumberReturns409WithExistingId(): void
    {
        $first = $this->call($this->payload());
        self::assertSame(201, $first['status']);

        $second = $this->call($this->payload());

        self::assertSame(409, $second['status']);
        self::assertSame('varsymbol_duplicate', $second['body']['error']['code']);
        self::assertSame((int) $first['body']['id'], $second['body']['error']['existing_invoice_id']);
        self::assertCount(1, $this->invoiceIds());
    }

    public function testHeaderNotMatchingLinesIsRejected(): void
    {
        $payload = $this->payload();
        $payload['total_vat'] = 42.05;
        $payload['total_with_vat'] = 242.05;

        $result = $this->call($payload);

        self::assertSame(422, $result['status']);
        self::assertSame('validation_failed', $result['body']['error']['code']);
        self::assertArrayHasKey('total_vat', $result['body']['error']['fields']);
        self::assertSame([], $this->invoiceIds());
    }

    /** Daň řádku, která se sazbou nemá nic společného, není zaokrouhlení, ale chyba. */
    public function testLineVatImplausibleForRateIsRejected(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['total_vat'] = 5.00;
        $payload['items'][0]['total_with_vat'] = 104.99;
        $payload['total_vat'] = 26.01;
        $payload['total_with_vat'] = 226.01;

        $result = $this->call($payload);

        self::assertSame(422, $result['status']);
        self::assertArrayHasKey('items.0.total_vat', $result['body']['error']['fields']);
        self::assertSame([], $this->invoiceIds());
    }

    public function testCreditNoteWithPositiveTotalIsRejected(): void
    {
        $payload = $this->payload();
        $payload['invoice_type'] = 'credit_note';

        $result = $this->call($payload);

        self::assertSame(422, $result['status']);
        self::assertArrayHasKey('total_with_vat', $result['body']['error']['fields']);
        self::assertSame([], $this->invoiceIds());
    }

    public function testClosedAccountingPeriodIsRejected(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->accountingPeriod(2098, 'closed');
        $this->vatStatus('2098-01-01', payer: true);

        $payload = $this->payload();
        $payload['issue_date'] = $payload['tax_date'] = $payload['due_date'] = '2098-03-10';

        $result = $this->call($payload);

        self::assertSame(409, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('period_closed', $result['body']['error']['code']);
        self::assertSame([], $this->invoiceIds());
    }

    /** Převzatý doklad nesmí dodatečně přibýt do odevzdaného přiznání. */
    public function testFiledVatReturnPeriodIsRejected(): void
    {
        $this->fileVatReturn(2096, 5);

        $result = $this->call($this->payload());

        self::assertSame(409, $result['status']);
        self::assertSame('period_filed', $result['body']['error']['code']);
        self::assertStringContainsString('5/2096', $result['body']['error']['message']);
        self::assertSame([], $this->invoiceIds());
    }

    public function testNonVatPayerAtTaxDateCannotImportVat(): void
    {
        $this->vatStatus('2097-01-01', payer: false);

        $payload = $this->payload();
        $payload['issue_date'] = $payload['tax_date'] = $payload['due_date'] = '2097-03-10';

        $result = $this->call($payload);

        self::assertSame(422, $result['status']);
        self::assertSame('not_vat_payer_at_date', $result['body']['error']['code']);
        self::assertSame([], $this->invoiceIds());
    }

    public function testReverseChargeWithoutClientDicIsRejected(): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET dic = NULL WHERE id = ?')->execute([$this->clientId]);
        $payload = $this->payload();
        $payload['reverse_charge'] = true;
        foreach ($payload['items'] as &$item) {
            $item['total_vat'] = 0.0;
            $item['total_with_vat'] = $item['total_without_vat'];
        }
        unset($item);
        $payload['total_vat'] = 0.0;
        $payload['total_with_vat'] = 199.99;
        $payload['rounding'] = 0.0;

        $result = $this->call($payload);

        self::assertSame(422, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('reverse_charge_dic_missing', $result['body']['error']['code']);
        self::assertSame([], $this->invoiceIds());
    }

    /** Import zakládá rovnou VYSTAVENÝ doklad — route mapa ho drží pod oprávněním k vystavení. */
    public function testRouteRequiresIssuePermission(): void
    {
        $perm = (new RoutePermissionMap())->match('POST', '/api/invoices/import');

        self::assertNotNull($perm);
        self::assertSame('invoices.issue', $perm->key);
        self::assertSame(AccessLevel::WRITE, $perm->minimum);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pomocné
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'invoice_type'       => 'invoice',
            'varsymbol'          => $this->prefix . '-1',
            'client_id'          => $this->clientId,
            'issue_date'         => self::TAX_DATE,
            'tax_date'           => self::TAX_DATE,
            'due_date'           => '2096-05-29',
            'currency'           => 'CZK',
            'prices_include_vat' => false,
            'payment_method'     => 'card',
            'rounding'           => 0.01,
            'total_without_vat'  => 199.99,
            'total_vat'          => 42.00,
            'total_with_vat'     => 242.00,
            'items'              => [
                [
                    'description' => 'Zboží A', 'quantity' => 3, 'unit' => 'ks',
                    'unit_price_without_vat' => 33.33, 'vat_rate' => 21,
                    'total_without_vat' => 99.99, 'total_vat' => 20.99, 'total_with_vat' => 120.98,
                ],
                [
                    'description' => 'Zboží B', 'quantity' => 1, 'unit' => 'ks',
                    'unit_price_without_vat' => 100.00, 'vat_rate' => 21,
                    'total_without_vat' => 100.00, 'total_vat' => 21.01, 'total_with_vat' => 121.01,
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(array $body, ?string $tokenScope = null): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', $tokenScope === null ? '/api/invoices/import' : '/api/v1/invoices/import')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);

        if ($tokenScope !== null) {
            $responseFactory = new ResponseFactory();
            $app = AppFactory::create($responseFactory);
            $app->post('/api/invoices/import', $this->action);
            $app->add(new ApiScopeMiddleware($responseFactory));
            $app->add(new ApiVersionRewriteMiddleware());
            $response = $app->handle($request
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
                ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => $tokenScope]));
        } else {
            $response = ($this->action)($request, new Psr7Response());
        }
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function setDimensionsEnabled(bool $enabled): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET dimensions_enabled = ? WHERE id = ?')
            ->execute([(int) $enabled, $this->supplierId]);
    }

    private function createDimensionType(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO dimension_types (supplier_id, code, name) VALUES (?, ?, ?)')
            ->execute([$supplierId, $this->prefix . '-' . count($this->dimensionTypes), 'Zdrojový e-shop']);
        $typeId = (int) $pdo->lastInsertId();
        $this->dimensionTypes[$typeId] = $supplierId;
        return $typeId;
    }

    private function createDimensionValue(int $supplierId, int $type, string $code, bool $active = true): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO dimension_values (supplier_id, type_id, code, name, is_active) VALUES (?, ?, ?, ?, ?)')
            ->execute([$supplierId, $type, $code, $code, (int) $active]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int,int> */
    private function headerDimensions(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT dimension_type_id, dimension_value_id FROM document_dimensions
            WHERE supplier_id = ? AND doc_type = 'invoice' AND doc_id = ? AND item_no = 0");
        $stmt->execute([$this->supplierId, $invoiceId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return list<int> */
    private function invoiceIds(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE client_id = ? ORDER BY id');
        $stmt->execute([$this->clientId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    private function invoice(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function items(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY order_index');
        $stmt->execute([$invoiceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hasActivityLog(string $action, int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE action = ? AND entity_type = "invoice" AND entity_id = ?'
        );
        $stmt->execute([$action, $invoiceId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function vatStatus(string $from, bool $payer): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier_vat_status_history
                (supplier_id, effective_from, is_vat_payer, is_identified, annual_deduction_percent)
             VALUES (?, ?, ?, 0, 100)'
        )->execute([$this->supplierId, $from, $payer ? 1 : 0]);
        $this->historyIds[] = (int) $pdo->lastInsertId();
    }

    private function accountingPeriod(int $year, string $status): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $year, "{$year}-01-01", "{$year}-12-31", $status]);
        $this->periodIds[] = (int) $pdo->lastInsertId();
    }

    private function fileVatReturn(int $year, int $month): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter, form_variant,
                 xml_content, xml_size_bytes, xml_sha256, validation_status, status, submitted_at,
                 summary_json, generated_by)
             VALUES (?, 'dphdp3', ?, ?, NULL, 'B', '<test/>', 7, ?, 'passed', 'submitted', '2096-06-20 09:00:00', '{}', ?)"
        )->execute([$this->supplierId, $year, $month, hash('sha256', 'test'), $this->userId]);
        $this->submissionIds[] = (int) $pdo->lastInsertId();
    }

    private function createClient(?string $dic): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, dic,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Testovací odběratel e-shopu (PHPUnit)", "Ulice 1", "Praha", "11000", ?,
                     "odberatel@example.test", ?, "cs", ?, 0, 0)'
        )->execute([$this->supplierId, $countryId, $dic, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }
}
