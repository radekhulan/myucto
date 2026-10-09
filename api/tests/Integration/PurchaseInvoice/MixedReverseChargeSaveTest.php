<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceItemsAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatCrossCheckService;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Smíšený přijatý doklad uložený editorem: přenesení daně v hlavičce, řádek § 92a
 * (kód 5) a vedle něj tuzemský řádek kódu 40 s daní dodavatele.
 *
 * Uložení dřív vynulovalo daň všech řádků (přepočet podle hlavičky) a kód 40 přepsalo
 * na 5. Kód 40 se přepisuje dál jen tehdy, když uživatel přenesení v hlavičce teprve
 * zapíná (#119). Vše v transakci s rollbackem; data jsou syntetická.
 */
#[Group('integration')]
final class MixedReverseChargeSaveTest extends TestCase
{
    private const YEAR = 2096;
    private const MONTH = 10;
    private const DATE = '2096-10-12';

    private Connection $db;
    private CreatePurchaseInvoiceAction $create;
    private UpdatePurchaseInvoiceAction $update;
    private SetPurchaseInvoiceItemsAction $setItems;
    private KontrolniHlaseniBuilder $kh;
    private VatLedgerService $ledger;
    private VatCrossCheckService $crossCheck;
    private PostingService $posting;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $rate21 = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db         = $c->get(Connection::class);
            $this->create     = $c->get(CreatePurchaseInvoiceAction::class);
            $this->update     = $c->get(UpdatePurchaseInvoiceAction::class);
            $this->setItems   = $c->get(SetPurchaseInvoiceItemsAction::class);
            $this->kh         = $c->get(KontrolniHlaseniBuilder::class);
            $this->ledger     = $c->get(VatLedgerService::class);
            $this->crossCheck = $c->get(VatCrossCheckService::class);
            $this->posting    = $c->get(PostingService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rate21     = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->rate21 === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->currencyId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND is_active = 1 AND code = 'CZK'
              ORDER BY is_default DESC, id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->currencyId === 0) {
            $this->markTestSkipped('Dodavatel nemá aktivní CZK.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678' WHERE id = ?")
            ->execute([$this->supplierId]);
        if ($this->db->hasTable('supplier_vat_status_history')) {
            $pdo->prepare(
                'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
                 VALUES (?, ?, 1, 0)
                 ON DUPLICATE KEY UPDATE is_vat_payer = 1, is_identified = 0'
            )->execute([$this->supplierId, self::YEAR . '-01-01']);
        }

        // Dodavatel BEZ DIČ — CreatePurchaseInvoiceAction jinak sahá na CRPDPH (síť);
        // DIČ pro KH se doplní až po založení dokladu.
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_vat_payer)
             VALUES (?, "TEST smíšený doklad dodavatel (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "mixed-rc-vendor@example.test", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
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

    /**
     * BEZ OPRAVY PADÁ: uložení dokladu, který přenesení v hlavičce už měl, přepsalo kód 40
     * na 5 a vynulovalo daň dodavatele (závazek 70 000 místo 74 200).
     */
    public function testSavingMixedDocumentKeepsDomesticLineAndSupplierVat(): void
    {
        $id = $this->createInvoice(reverseCharge: true, items: [$this->item('Stavební práce § 92a', 50000.0, '5')]);
        $this->db->pdo()->prepare("UPDATE clients SET dic = 'CZ22222220' WHERE id = ?")->execute([$this->vendorId]);

        $res = $this->put($id, $this->payload(true, [
            $this->item('Stavební práce § 92a', 50000.0, '5'),
            $this->item('Materiál s daní dodavatele', 20000.0, '40'),
        ]));
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        self::assertSame([['5', 0.0], ['40', 4200.0]], $this->itemCodesAndVat($id));
        self::assertEqualsWithDelta(74200.0, $this->totalWithVat($id), 0.005, 'Závazek včetně daně dodavatele.');

        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'received' WHERE id = ?")->execute([$id]);

        // Evidence DPH: § 92a samovyměření ř. 10, tuzemský řádek odpočet ř. 40 s daní dodavatele.
        $rows = array_values(array_filter(
            $this->ledger->rows($this->supplierId, sprintf('%04d-%02d-01', self::YEAR, self::MONTH), sprintf('%04d-%02d-31', self::YEAR, self::MONTH)),
            static fn (array $r): bool => $r['source'] === 'purchase' && (int) $r['invoice_id'] === $id,
        ));
        $byCode = [];
        foreach ($rows as $r) {
            $byCode[(string) $r['code']] = $r;
        }
        self::assertTrue(VatLedgerService::isSelfAssessedRow($byCode['5']));
        self::assertSame(10500.0, $byCode['5']['vat_czk']);
        self::assertFalse(VatLedgerService::isSelfAssessedRow($byCode['40']));
        self::assertSame('40', $byCode['40']['dphdp3_line']);
        self::assertSame(4200.0, $byCode['40']['vat_czk']);

        // KH: § 92a do B.1, tuzemská část nad limitem do B.2.
        $placement = [];
        foreach ($this->kh->documentSections($this->supplierId, self::YEAR, self::MONTH) as $key => $sections) {
            if (str_starts_with((string) $key, 'purchase:') && str_ends_with((string) $key, ':' . $id)) {
                $placement = [...$placement, ...array_keys($sections)];
            }
        }
        sort($placement);
        self::assertSame(['B.1', 'B.2'], $placement);

        foreach ($this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly') as $f) {
            if ($f['check'] === 'reverse_charge_domestic_code') {
                self::assertNotContains($id, array_column($f['documents'], 'invoice_id'),
                    'Tuzemský řádek s daní dodavatele není pozůstatek #119.');
            }
        }

        // Zaúčtování: odpočet 10 500 + 4 200, výstupní daň jen z § 92a, závazek 74 200.
        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $id);
        self::assertSame(14700.0, $this->sum($lines, '343', 'debit'));
        self::assertSame(10500.0, $this->sum($lines, '343', 'credit'));
        self::assertSame(74200.0, $this->sum($lines, '321', 'credit'));
    }

    /** BEZ OPRAVY PADÁ: dedikovaný endpoint položek přepsal kód 40 i u dokladu s přenesením. */
    public function testItemsEndpointKeepsDomesticLineOnReverseChargeDocument(): void
    {
        $id = $this->createInvoice(reverseCharge: true, items: [$this->item('Stavební práce § 92a', 50000.0, '5')]);

        $res = self::decode(($this->setItems)($this->request('PUT', ['items' => [
            $this->item('Stavební práce § 92a', 50000.0, '5'),
            $this->item('Materiál s daní dodavatele', 20000.0, '40'),
        ]]), new Psr7Response(), ['id' => (string) $id]));
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        self::assertSame([['5', 0.0], ['40', 4200.0]], $this->itemCodesAndVat($id));
    }

    /** Záměrná funkčnost (#119): zapnutí přenesení v hlavičce přepne tuzemské kódy řádků. */
    public function testTurningReverseChargeOnStillRewritesDomesticCodes(): void
    {
        $id = $this->createInvoice(reverseCharge: false, items: [$this->item('Stavební práce', 50000.0, '40')]);
        self::assertSame([['40', 10500.0]], $this->itemCodesAndVat($id));

        $res = $this->put($id, $this->payload(true, [$this->item('Stavební práce', 50000.0, '40')]));
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        self::assertSame([['5', 0.0]], $this->itemCodesAndVat($id));
        self::assertEqualsWithDelta(50000.0, $this->totalWithVat($id), 0.005);
    }

    /** Zahraniční dodavatel s kódem 40 je pozůstatek omylem zvolené země (#119): přepis zůstává. */
    public function testForeignVendorDomesticCodeIsRewrittenEvenWhenReverseChargeWasOn(): void
    {
        $id = $this->createInvoice(reverseCharge: true, items: [$this->item('Služba', 10000.0, null)]);
        $deId = (int) ($this->db->pdo()->query("SELECT id FROM countries WHERE UPPER(iso2) = 'DE' LIMIT 1")->fetchColumn() ?: 0);
        $this->db->pdo()->prepare('UPDATE clients SET country_id = ? WHERE id = ?')->execute([$deId, $this->vendorId]);

        $res = $this->put($id, $this->payload(true, [$this->item('Služba', 10000.0, '40')]));
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        [[$code, $vat]] = $this->itemCodesAndVat($id);
        self::assertNotSame('40', $code);
        self::assertSame(0.0, $vat);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $items */
    private function createInvoice(bool $reverseCharge, array $items): int
    {
        $created = self::decode(($this->create)($this->request('POST', $this->payload($reverseCharge, $items)), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        return (int) $created['body']['id'];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function payload(bool $reverseCharge, array $items): array
    {
        return [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'MIXED-RC-' . self::YEAR,
            'document_kind'         => 'invoice',
            'issue_date'            => self::DATE,
            'tax_date'              => self::DATE,
            'due_date'              => self::DATE,
            'received_at'           => self::DATE,
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => $reverseCharge,
            'prices_include_vat'    => false,
            'items'                 => $items,
        ];
    }

    /** @return array<string,mixed> */
    private function item(string $description, float $price, ?string $code): array
    {
        return [
            'description'             => $description,
            'quantity'                => 1,
            'unit'                    => 'ks',
            'unit_price_without_vat'  => $price,
            'vat_rate_id'             => $this->rate21,
            'vat_classification_code' => $code,
        ];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function put(int $id, array $body): array
    {
        return self::decode(($this->update)($this->request('PUT', $body), new Psr7Response(), ['id' => (string) $id]));
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/purchase-invoices')
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

    /** @return list<array{0:?string,1:float}> */
    private function itemCodesAndVat(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_classification_code, total_vat FROM purchase_invoice_items
              WHERE purchase_invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$id]);
        return array_map(
            static fn (array $r): array => [$r['vat_classification_code'], (float) $r['total_vat']],
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    private function totalWithVat(int $id): float
    {
        $stmt = $this->db->pdo()->prepare('SELECT total_with_vat FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (float) $stmt->fetchColumn();
    }

    /** @param list<array<string,mixed>> $lines */
    private function sum(array $lines, string $prefix, string $side): float
    {
        $s = 0.0;
        foreach ($lines as $l) {
            if (str_starts_with((string) $l['account_code'], $prefix) && $l['side'] === $side) {
                $s += (float) $l['amount'];
            }
        }
        return round($s, 2);
    }
}
