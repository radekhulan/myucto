<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\NumberSeries\NativeSeriesCompleteness;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Číslo nového přijatého dokladu nese měsíc a rok data podle nastavení firmy
 * „Číslovat přijaté doklady podle data“ (`supplier.purchase_numbering_date_basis`).
 *
 * - Výchozí datum vystavení: doklad vystavený 28. 10. a přijatý 3. 11. má číslo října.
 * - Datum přijetí: týž doklad má číslo listopadu.
 * - DUZP: číslo měsíce DUZP, doklad bez DUZP podle data vystavení.
 * - Nová řada číselníku: číslo i kontrola úplnosti podle téhož data.
 * - Nastavení firmy se ukládá a vrací přes nastavení firmy, cizí hodnotu odmítne.
 *
 * Izolace: rok 2099, doklady a řady se zakládají a mažou v testu.
 */
#[Group('integration')]
final class PurchaseInvoiceNumberingDateTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private NativeSeriesCompleteness $completeness;
    private SettingsAction $settings;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    /** @var int[] */
    private array $created = [];
    private ?int $seriesId = null;
    private int $seq = 0;
    /** @var array<string,mixed> */
    private array $origSupplier = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->repo = $c->get(PurchaseInvoiceRepository::class);
            $this->completeness = $c->get(NativeSeriesCompleteness::class);
            $this->settings = $c->get(SettingsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vendorId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->vendorId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/vendor/CZK currency/user.');
        }
        $this->origSupplier = (array) $pdo->query(
            "SELECT purchase_invoice_number_format, purchase_invoice_numbering, purchase_numbering_date_basis
               FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC);
        $this->setSupplier(['purchase_invoice_number_format' => null, 'purchase_invoice_numbering' => 'shared', 'purchase_numbering_date_basis' => 'issue']);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->cleanup();
        $this->setSupplier($this->origSupplier);
        $this->db->close();
    }

    /** @param array<string,mixed> $values */
    private function setSupplier(array $values): void
    {
        foreach ($values as $col => $value) {
            $this->db->pdo()->prepare("UPDATE supplier SET {$col} = ? WHERE id = ?")->execute([$value, $this->supplierId]);
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        if ($this->seriesId !== null) {
            $pdo->prepare('DELETE FROM number_series_counters WHERE series_id = ?')->execute([$this->seriesId]);
            $pdo->prepare('DELETE FROM number_series WHERE id = ?')->execute([$this->seriesId]);
            $this->seriesId = null;
        }
        $pdo->prepare("DELETE FROM purchase_invoice_counters WHERE supplier_id = ? AND period LIKE '%2099%'")
            ->execute([$this->supplierId]);
    }

    private function receive(string $issueDate, string $receivedAt, ?string $taxDate = null): string
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, vendor_snapshot, status, created_by, number_series_id)
             VALUES (?, ?, ?, 'invoice', ?, ?, ?, ?, ?, '{}', 'draft', ?, ?)"
        )->execute([
            $this->supplierId, $this->vendorId, 'NUMDATE-' . (++$this->seq), $issueDate, $taxDate,
            $issueDate, $receivedAt, $this->currencyId, $this->userId, $this->seriesId,
        ]);
        $id = $this->created[] = (int) $pdo->lastInsertId();
        return $this->repo->ensureVarsymbol($id, $this->supplierId);
    }

    public function testDefaultNumbersByIssueDate(): void
    {
        self::assertSame('PF9910001', $this->receive('2099-10-28', '2099-11-03'),
            'Výchozí nastavení čísluje podle data vystavení (dosavadní chování).');
    }

    public function testNumbersByReceivedDate(): void
    {
        $this->setSupplier(['purchase_numbering_date_basis' => 'received']);

        self::assertSame('PF9911001', $this->receive('2099-10-28', '2099-11-03'),
            'Doklad přijatý 3. 11. s vystavením 28. 10. dostane číslo listopadu.');
        self::assertSame('PF9910001', $this->receive('2099-11-02', '2099-10-30'));
    }

    public function testNumbersByTaxDateWithIssueDateFallback(): void
    {
        $this->setSupplier(['purchase_numbering_date_basis' => 'tax']);

        self::assertSame('PF9912001', $this->receive('2099-11-28', '2099-11-29', '2099-12-01'));
        self::assertSame('PF9911001', $this->receive('2099-11-20', '2099-12-02'),
            'Doklad bez DUZP se čísluje podle data vystavení.');
    }

    public function testNativeSeriesNumbersAndCompletenessByReceivedDate(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO number_series (supplier_id, doc_kind, scope_type, scope_id, storage, name, prefix, template, period, is_default)
             VALUES (?, 'purchase', 'company', 0, 'native', 'Číslování podle data test', 'PX', '{PREFIX}{YY}{MM}{CCC}', 'month', 0)"
        )->execute([$this->supplierId]);
        $this->seriesId = (int) $this->db->pdo()->lastInsertId();
        $this->setSupplier(['purchase_numbering_date_basis' => 'received']);

        self::assertSame('PX9911001', $this->receive('2099-10-28', '2099-11-03'));

        $row = $this->db->pdo()->query("SELECT * FROM number_series WHERE id = {$this->seriesId}")->fetch(PDO::FETCH_ASSOC);
        $row['id'] = (int) $row['id'];
        $buckets = $this->completeness->buckets($this->supplierId, $row, 2099);
        self::assertSame(['209911'], array_column($buckets, 'period_key'),
            'Kontrola úplnosti řadí číslo do měsíce data přijetí, stejně jako výdej čísla.');
        self::assertSame(0, $buckets[0]['missing_total']);
    }

    public function testSettingIsSavedAndDrivesNewNumbers(): void
    {
        $get = $this->callSettings('GET', []);
        self::assertSame('issue', $get['body']['purchase_numbering_date_basis'] ?? null);

        $put = $this->callSettings('PUT', ['purchase_numbering_date_basis' => 'received']);
        self::assertSame(200, $put['status']);
        self::assertSame('received', $put['body']['purchase_numbering_date_basis'] ?? null);
        self::assertSame('PF9911001', $this->receive('2099-10-28', '2099-11-03'));

        $bad = $this->callSettings('PUT', ['purchase_numbering_date_basis' => 'due']);
        self::assertSame(400, $bad['status']);
        self::assertSame('validation_failed', $bad['body']['error']['code'] ?? null);
        self::assertSame('received', $this->callSettings('GET', [])['body']['purchase_numbering_date_basis'] ?? null);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function callSettings(string $method, array $body): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $resp = $method === 'GET'
            ? $this->settings->getSupplier($req, new Psr7Response())
            : $this->settings->updateSupplier($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
