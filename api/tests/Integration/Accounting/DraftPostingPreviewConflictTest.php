<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\DraftPostingPreviewAction;
use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentExtractionRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Document\AttachmentCheck\AttachmentCheckService;
use MyInvoice\Service\Document\ScanAttach\ScanExtractionNormalizer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Náhled zápisu přijaté faktury s přílohou při souběžném zápisu týchž řádků
 * (produkce 6.71.0: AI vytěžování přepsalo `attachment_checks` a náhled skončil 500).
 *
 * Konflikt se vyvolá deterministicky druhým spojením: snapshot transakce náhledu
 * vznikne dřív, než druhé spojení řádek kontroly přílohy změní, a zápis téhož řádku
 * pak MariaDB se snapshot isolation odmítne chybou 1020 a transakci odvolá.
 * Data jsou syntetická a potvrzená (druhé spojení je musí vidět), tearDown je smaže.
 */
#[Group('integration')]
final class DraftPostingPreviewConflictTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const DATE = '2026-03-02';
    private const STALE_CHECK = '2000-01-01 00:00:00';

    private Connection $db;
    private PDO $other;
    private DraftPostingPreviewAction $preview;
    private UpdatePurchaseInvoiceAction $updatePurchase;
    private AttachmentCheckService $checks;

    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $rate21 = 0;
    private int $purchaseId = 0;
    private string $sha = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->preview = $c->get(DraftPostingPreviewAction::class);
            $this->updatePurchase = $c->get(UpdatePurchaseInvoiceAction::class);
            $this->checks = $c->get(AttachmentCheckService::class);
            $createPurchase = $c->get(CreatePurchaseInvoiceAction::class);
            $seeder = $c->get(ChartOfAccountsSeeder::class);
            $extractions = $c->get(DocumentExtractionRepository::class);
            $config = $c->get(Config::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rate21 = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $sourceCurrency = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$source, $this->userId, $this->rate21, $czId, $sourceCurrency], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '2020-01-01', true);
        $seeder->seedForSupplier($this->supplierId);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             SELECT ?, code, label, symbol, name_cs, name_en, decimals, 1, 1 FROM currencies WHERE id = ?'
        )->execute([$this->supplierId, $sourceCurrency]);
        $this->currencyId = (int) $pdo->lastInsertId();
        // Bez DIČ: uložení přijaté faktury jinak sahá na CRPDPH (síť).
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                                  language, currency_default_id, is_customer, is_vendor, ic)
             VALUES (?, "TEST dodavatel náhledu", "Testovací 1", "Praha", "11000", ?, "vendor@example.test", "cs", ?, 0, 1, "11220033")'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();

        $created = self::decode($createPurchase($this->request('POST', $this->payload()), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $this->purchaseId = (int) $created['body']['id'];

        // Příloha ve slotu PDF faktury s uloženým vytěžením a uloženou kontrolou.
        $this->sha = hash('sha256', 'synthetic-preview-conflict-' . bin2hex(random_bytes(8)));
        $pdo->prepare('UPDATE purchase_invoices SET pdf_hash = ? WHERE id = ?')->execute([$this->sha, $this->purchaseId]);
        $extractions->save($this->supplierId, null, $this->sha, ScanExtractionNormalizer::SCHEMA_VERSION, 'ok', [
            'company_role' => 'buyer', 'document_kind' => 'invoice', 'vendor_ico' => '11220033', 'buyer_ico' => null,
            'variable_symbol' => null, 'tax_date' => self::DATE, 'total_with_vat' => 12100.0, 'amount_due' => null, 'currency' => 'CZK',
        ], ['synthetic' => true], 'fake', 'fake-model', null);
        $this->checks->recheckEntity($this->supplierId, 'purchase_invoice', $this->purchaseId);
        $pdo->prepare('UPDATE attachment_checks SET checked_at = ? WHERE supplier_id = ? AND entity_id = ?')
            ->execute([self::STALE_CHECK, $this->supplierId, $this->purchaseId]);
        self::assertSame(1, $this->checkCount(), 'kontrola přílohy je uložená');

        $this->other = new PDO(
            'mysql:host=' . $config->get('db.host', '127.0.0.1') . ';port=' . (int) $config->get('db.port', 3306)
                . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4',
            (string) $config->get('db.user'),
            (string) $config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->other) && $this->other->inTransaction()) {
            $this->other->rollBack();
        }
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        if ($this->sha !== '') {
            $pdo->prepare('DELETE FROM document_extractions WHERE sha256 = ?')->execute([$this->sha]);
        }
        if ($this->supplierId > 0) {
            $this->deleteSupplier($pdo);
        }
        $this->db->close();
    }

    /**
     * BEZ OPRAVY PADÁ: uložení v náhledu přepočítalo kontrolu přílohy uvnitř transakce
     * náhledu, zápis `attachment_checks` dostal 1020, catch chybu spolkl a náhled skončil
     * LogicException (v cizí transakci 1305 z ROLLBACK TO SAVEPOINT). Náhled kontrolu
     * přílohy nepřepočítává vůbec, takže souběžná změna jejího řádku mu nevadí a
     * transakce volajícího zůstane otevřená.
     */
    public function testConcurrentAttachmentCheckWriteDoesNotBreakPreview(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->takeSnapshot();
        $this->touchCheckFromOtherConnection();

        $preview = $this->draftPreview($this->payload(['note_below_items' => 'Náhled při souběhu']));

        self::assertSame(200, $preview['status'], json_encode($preview['body'], JSON_UNESCAPED_UNICODE));
        self::assertTrue($preview['body']['balanced']);
        self::assertTrue($pdo->inTransaction(), 'transakce volajícího zůstala otevřená');
        $pdo->rollBack();

        self::assertSame('draft', $this->purchaseStatus(), 'náhled nic neuložil');
        self::assertSame('2001-01-01 00:00:00', $this->checkedAt(), 'náhled kontrolu přílohy nepřepsal');
    }

    /**
     * BEZ OPRAVY PADÁ (500): souběh na řádku dokladu ve vlastní transakci náhledu
     * (vypršený zámek, odvolaný snapshot) je „zkuste znovu", ne chyba aplikace.
     */
    public function testLockConflictInOwnPreviewTransactionIsRetryableConflict(): void
    {
        $pdo = $this->db->pdo();
        $this->other->beginTransaction();
        $this->other->prepare('SELECT id FROM purchase_invoices WHERE id = ? FOR UPDATE')->execute([$this->purchaseId]);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $preview = $this->draftPreview($this->payload(['note_below_items' => 'Náhled při zámku']));

        self::assertSame(409, $preview['status'], json_encode($preview['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(DraftPostingPreviewAction::CONFLICT_CODE, $preview['body']['error']['code'] ?? null);
        self::assertSame('Doklad se právě mění jinde, zkuste náhled znovu.', $preview['body']['error']['message'] ?? null);
        self::assertFalse($pdo->inTransaction(), 'transakce náhledu je vrácená');
        $this->other->rollBack();
        self::assertSame('draft', $this->purchaseStatus());
    }

    /**
     * BEZ OPRAVY PADÁ: běžné uložení v cizí transakci (hromadná oprava) přepočítávalo
     * kontrolu přílohy uvnitř ní; 1020 spolkl catch a transakce volajícího tiše skončila.
     * Přepočet běží až po commitu a uložení dokladu nezmaří.
     */
    public function testAttachmentCheckConflictDoesNotSpoilSave(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->takeSnapshot();
        $this->touchCheckFromOtherConnection();

        $saved = self::decode(($this->updatePurchase)(
            $this->request('PUT', $this->payload(['note_below_items' => 'Uloženo při souběhu'])),
            new Psr7Response(),
            ['id' => (string) $this->purchaseId],
        ));

        self::assertSame(200, $saved['status'], json_encode($saved['body'], JSON_UNESCAPED_UNICODE));
        self::assertTrue($pdo->inTransaction(), 'transakce volajícího zůstala otevřená');
        self::assertSame('2001-01-01 00:00:00', $this->checkedAt(), 'kontrola přílohy čeká na commit');
        $pdo->commit();

        $note = $pdo->prepare('SELECT note_below_items FROM purchase_invoices WHERE id = ?');
        $note->execute([$this->purchaseId]);
        self::assertSame('Uloženo při souběhu', $note->fetchColumn());
        self::assertNotSame('2001-01-01 00:00:00', $this->checkedAt(), 'kontrola přílohy se přepočítala po commitu');
    }

    /** Syntetická firma je potvrzená: smaže se se vším, co nese její supplier_id. */
    private function deleteSupplier(PDO $pdo): void
    {
        $pdo->prepare('DELETE i FROM purchase_invoice_items i JOIN purchase_invoices p ON p.id = i.purchase_invoice_id WHERE p.supplier_id = ?')
            ->execute([$this->supplierId]);
        $tables = $pdo->query(
            "SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'supplier_id' AND t.TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ?')->execute([$this->supplierId]);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
    }

    private function takeSnapshot(): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT checked_at FROM attachment_checks WHERE supplier_id = ? AND entity_id = ?');
        $stmt->execute([$this->supplierId, $this->purchaseId]);
        self::assertSame(self::STALE_CHECK, $stmt->fetchColumn());
    }

    private function touchCheckFromOtherConnection(): void
    {
        $this->other->prepare("UPDATE attachment_checks SET checked_at = '2001-01-01 00:00:00' WHERE supplier_id = ? AND entity_id = ?")
            ->execute([$this->supplierId, $this->purchaseId]);
    }

    private function checkedAt(): ?string
    {
        $stmt = $this->other->prepare('SELECT checked_at FROM attachment_checks WHERE supplier_id = ? AND entity_id = ?');
        $stmt->execute([$this->supplierId, $this->purchaseId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function checkCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM attachment_checks WHERE supplier_id = ? AND entity_id = ?');
        $stmt->execute([$this->supplierId, $this->purchaseId]);

        return (int) $stmt->fetchColumn();
    }

    private function purchaseStatus(): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$this->purchaseId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function payload(array $override = []): array
    {
        return $override + [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'PREVIEW-CONFLICT-1',
            'document_kind'         => 'invoice',
            'issue_date'            => self::DATE,
            'tax_date'              => self::DATE,
            'due_date'              => self::DATE,
            'received_at'           => self::DATE,
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => false,
            'prices_include_vat'    => false,
            'items'                 => [[
                'description'            => 'Služba',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 10000,
                'vat_rate_id'            => $this->rate21,
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $document
     * @return array{status:int, body:array<string,mixed>}
     */
    private function draftPreview(array $document): array
    {
        return self::decode(($this->preview)(
            $this->request('POST', ['doc_id' => $this->purchaseId, 'document' => $document]),
            new Psr7Response(),
            ['source' => 'purchase-invoices'],
        ));
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
