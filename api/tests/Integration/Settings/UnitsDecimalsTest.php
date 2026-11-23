<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Codebook\CodebookAction;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Support\PaymentMethods;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Desetinná místa množství podle měrné jednotky (migrace 2036).
 *
 * `units.quantity_decimals` NULL = jako dosud (bez omezení, ukládá se až na 3 místa).
 * Nastavená hodnota 0 až 3 omezí množství na řádku faktury s touto jednotkou;
 * přesnější vstup API odmítne 400 `validation_failed` se seznamem řádků, nic
 * potichu nezaokrouhlí. Jednotka se páruje podle kódu bez ohledu na velikost písmen.
 *
 * Číselník jednotek je globální, test proto pracuje jen s vlastními kódy a po sobě
 * je smaže. Data jsou syntetická.
 */
#[Group('integration')]
final class UnitsDecimalsTest extends TestCase
{
    private const ISSUE_DATE = '2096-05-10';
    private const DUE_DATE   = '2096-06-10';

    private Connection $db;
    private SettingsAction $settings;
    private CodebookAction $codebooks;
    private CreateInvoiceAction $create;
    private UpdateInvoiceAction $update;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private string $prefix = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db        = $c->get(Connection::class);
            $this->settings  = $c->get(SettingsAction::class);
            $this->codebooks = $c->get(CodebookAction::class);
            $this->create    = $c->get(CreateInvoiceAction::class);
            $this->update    = $c->get(UpdateInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE UPPER(COALESCE(country, 'CZ')) = 'CZ' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users / vat_rates).');
        }
        $this->prefix     = 'ud' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->currencyId = $this->currency();
        $this->clientId   = $this->client();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        if ($this->prefix !== '') {
            $pdo->prepare('DELETE FROM units WHERE code LIKE ?')->execute([$this->prefix . '%']);
        }
        $this->db->close();
    }

    // ── číselník ─────────────────────────────────────────────────────────────

    public function testUnitCrudCarriesQuantityDecimals(): void
    {
        $created = $this->call('POST', fn ($req, $res) => $this->settings->createUnit($req, $res), [
            'code' => $this->prefix . 'kg', 'label_cs' => 'kilogram', 'label_en' => 'kilogram', 'quantity_decimals' => 2,
        ]);
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $created['body']['id'];

        self::assertSame(2, $this->listed($id)['quantity_decimals']);
        self::assertSame(2, $this->codebookRow($id)['quantity_decimals'], 'Editory čtou jednotky z /codebooks/units.');

        $updated = $this->call('PUT', fn ($req, $res) => $this->settings->updateUnit($req, $res, ['id' => (string) $id]), [
            'quantity_decimals' => null,
        ]);
        self::assertSame(200, $updated['status'], json_encode($updated['body'], JSON_UNESCAPED_UNICODE));
        self::assertNull($this->listed($id)['quantity_decimals'], 'null = bez omezení, jako dosud.');

        $updated = $this->call('PUT', fn ($req, $res) => $this->settings->updateUnit($req, $res, ['id' => (string) $id]), [
            'quantity_decimals' => '0',
        ]);
        self::assertSame(200, $updated['status']);
        self::assertSame(0, $this->listed($id)['quantity_decimals']);
    }

    public function testNewUnitWithoutSettingIsUnlimited(): void
    {
        $id = $this->unit('m', null);
        self::assertNull($this->listed($id)['quantity_decimals']);
        self::assertNull($this->codebookRow($id)['quantity_decimals']);
    }

    public function testQuantityDecimalsOutsideZeroToThreeAreRejected(): void
    {
        $id = $this->unit('bal', null);
        foreach ([4, -1, 1.5, 'x', true] as $bad) {
            $res = $this->call('PUT', fn ($req, $res) => $this->settings->updateUnit($req, $res, ['id' => (string) $id]), [
                'quantity_decimals' => $bad,
            ]);
            self::assertSame(400, $res['status'], 'Hodnota ' . var_export($bad, true) . ' nesmí projít.');
            self::assertSame('validation_failed', $res['body']['error']['code'] ?? null);
        }
        $res = $this->call('POST', fn ($req, $res) => $this->settings->createUnit($req, $res), [
            'code' => $this->prefix . 'x', 'quantity_decimals' => 5,
        ]);
        self::assertSame(400, $res['status']);
        self::assertNull($this->listed($id)['quantity_decimals'], 'Odmítnutá úprava nesmí nic zapsat.');
    }

    // ── faktura ──────────────────────────────────────────────────────────────

    /**
     * BEZ ZMĚNY PADÁ: 1,5 ks se uložilo, i když jednotka dovoluje jen celé kusy.
     */
    public function testCreateInvoiceRejectsQuantityOverUnitDecimals(): void
    {
        $this->unit('ks', 0);
        $body = $this->payload([
            $this->item('Celé kusy', 2, $this->prefix . 'ks'),
            $this->item('Půl kusu', 1.5, strtoupper($this->prefix . 'KS')),
        ]);

        $res = $this->call('POST', fn ($req, $res) => ($this->create)($req, $res), $body);

        self::assertSame(400, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('validation_failed', $res['body']['error']['code'] ?? null);
        self::assertSame(
            [['index' => 1, 'unit' => strtoupper($this->prefix . 'KS'), 'decimals' => 0]],
            $res['body']['error']['items'] ?? null,
        );
        self::assertSame(0, $this->invoiceCount(), 'Odmítnutý doklad nesmí vzniknout.');
    }

    /** BEZ ZMĚNY PADÁ: úprava řádku na 2,5 ks prošla. */
    public function testUpdateInvoiceRejectsQuantityOverUnitDecimals(): void
    {
        $this->unit('ks', 0);
        $created = $this->call('POST', fn ($req, $res) => ($this->create)($req, $res),
            $this->payload([$this->item('Kusy', 2, $this->prefix . 'ks')]));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $created['body']['id'];

        $res = $this->call('PUT', fn ($req, $res) => ($this->update)($req, $res, ['id' => (string) $id]),
            $this->payload([$this->item('Kusy', 2.5, $this->prefix . 'ks')]));

        self::assertSame(400, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('validation_failed', $res['body']['error']['code'] ?? null);
        self::assertSame([['index' => 0, 'unit' => $this->prefix . 'ks', 'decimals' => 0]], $res['body']['error']['items'] ?? null);
        self::assertSame('2.000', $this->storedQuantity($id), 'Odmítnutá úprava nesmí přepsat řádek.');
    }

    /**
     * BEZ OPRAVY PADÁ: řádek bez klíče `unit` (token, MCP) kontrolu obešel, ale uloží
     * se s výchozí jednotkou dokladu. Výchozí jednotka je globální, proto se jí nastavení
     * jen dočasně změní a vrátí.
     */
    public function testItemWithoutUnitKeyIsCheckedAsDefaultUnit(): void
    {
        $pdo = $this->db->pdo();
        $default = \MyInvoice\Repository\InvoiceRepository::DEFAULT_ITEM_UNIT;
        $stmt = $pdo->prepare('SELECT id, quantity_decimals FROM units WHERE code = ?');
        $stmt->execute([$default]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            self::markTestSkipped("Výchozí jednotka '$default' není v číselníku.");
        }
        $pdo->prepare('UPDATE units SET quantity_decimals = 0 WHERE id = ?')->execute([(int) $row['id']]);
        try {
            $item = $this->item('Bez jednotky', 1.5, 'x');
            unset($item['unit']);
            $res = $this->call('POST', fn ($req, $res) => ($this->create)($req, $res), $this->payload([$item]));

            self::assertSame(400, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
            self::assertSame([['index' => 0, 'unit' => $default, 'decimals' => 0]], $res['body']['error']['items'] ?? null);
        } finally {
            $pdo->prepare('UPDATE units SET quantity_decimals = ? WHERE id = ?')
                ->execute([$row['quantity_decimals'], (int) $row['id']]);
        }
    }

    public function testQuantityWithinUnitDecimalsAndUnitsWithoutSettingStayAsToday(): void
    {
        $this->unit('kg', 2);
        $this->unit('m', null);
        $res = $this->call('POST', fn ($req, $res) => ($this->create)($req, $res), $this->payload([
            $this->item('Na dvě místa', '1.250', $this->prefix . 'kg'),
            $this->item('Jednotka bez nastavení', 1.235, $this->prefix . 'm'),
            $this->item('Jednotka mimo číselník', 0.125, $this->prefix . 'nic'),
        ]));

        self::assertSame(201, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $stmt = $this->db->pdo()->prepare('SELECT quantity FROM invoice_items WHERE invoice_id = ? ORDER BY order_index, id');
        $stmt->execute([(int) $res['body']['id']]);
        self::assertSame(['1.250', '1.235', '0.125'], $stmt->fetchAll(\PDO::FETCH_COLUMN), 'Nic se nezaokrouhluje.');
    }

    // ── PDF ──────────────────────────────────────────────────────────────────

    /**
     * BEZ ZMĚNY PADÁ: PDF tisklo 1,5 i u jednotky nastavené na dvě místa.
     * Nastavená jednotka tiskne pevný počet míst, bez nastavení zůstává dnešní
     * adaptivní formát. Víc míst, než je nastaveno (starý doklad), se neschová.
     */
    public function testPdfPrintsFixedDecimalsOnlyForUnitsWithSetting(): void
    {
        $this->unit('kg', 2);
        $this->unit('m', null);
        $this->unit('ks', 0);
        $res = $this->call('POST', fn ($req, $res) => ($this->create)($req, $res), $this->payload([
            $this->item('Váha', '1.5', $this->prefix . 'KG'),
            $this->item('Délka', '1.5', $this->prefix . 'm'),
            $this->item('Kusy', 3, $this->prefix . 'ks'),
        ]));
        self::assertSame(201, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $res['body']['id'];
        // Starý řádek uložený dřív, než jednotka dostala nastavení.
        $this->db->pdo()->prepare('UPDATE invoice_items SET quantity = 2.125 WHERE invoice_id = ? AND description = ?')
            ->execute([$id, 'Kusy']);

        $c = Bootstrap::buildContainer();
        $invoice = $c->get(\MyInvoice\Repository\InvoiceRepository::class)->find($id);
        $html = $c->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class)
            ->renderHtml($invoice, includeCss: false, includeWorkReport: false);

        self::assertMatchesRegularExpression('~<td class="num nowrap">1,50</td>\s*<td>kg</td>~', $html);
        self::assertMatchesRegularExpression('~<td class="num nowrap">1,5</td>\s*<td>m</td>~', $html);
        self::assertMatchesRegularExpression('~<td class="num nowrap">2,125</td>\s*<td>ks</td>~', $html);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function unit(string $suffix, ?int $decimals): int
    {
        $res = $this->call('POST', fn ($req, $res) => $this->settings->createUnit($req, $res), array_filter([
            'code'              => $this->prefix . $suffix,
            'label_cs'          => $suffix,
            'label_en'          => $suffix,
            'quantity_decimals' => $decimals,
        ], static fn ($v) => $v !== null));
        self::assertSame(201, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        return (int) $res['body']['id'];
    }

    /** @return array<string,mixed> */
    private function listed(int $id): array
    {
        $res = $this->call('GET', fn ($req, $res) => $this->settings->listUnits($req, $res), []);
        foreach ($res['body'] as $row) {
            if ((int) $row['id'] === $id) {
                self::assertArrayHasKey('quantity_decimals', $row);
                return $row;
            }
        }
        self::fail("Jednotka #$id chybí v seznamu.");
    }

    /** @return array<string,mixed> */
    private function codebookRow(int $id): array
    {
        $res = $this->call('GET', fn ($req, $res) => $this->codebooks->units($req, $res), []);
        foreach ($res['body'] as $row) {
            if ((int) $row['id'] === $id) {
                self::assertArrayHasKey('quantity_decimals', $row);
                return $row;
            }
        }
        self::fail("Jednotka #$id chybí v číselníku.");
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function payload(array $items): array
    {
        return [
            'invoice_type'       => 'invoice',
            'client_id'          => $this->clientId,
            'issue_date'         => self::ISSUE_DATE,
            'tax_date'           => self::ISSUE_DATE,
            'due_date'           => self::DUE_DATE,
            'currency_id'        => $this->currencyId,
            'reverse_charge'     => false,
            'prices_include_vat' => false,
            'payment_method'     => PaymentMethods::DEFAULT,
            'language'           => 'cs',
            'items'              => $items,
        ];
    }

    /** @return array<string,mixed> */
    private function item(string $description, int|float|string $quantity, string $unit): array
    {
        return [
            'description'            => $description,
            'quantity'               => $quantity,
            'unit'                   => $unit,
            'unit_price_without_vat' => 100.0,
            'vat_rate_id'            => $this->vatRateId,
        ];
    }

    /**
     * @param callable(\Psr\Http\Message\ServerRequestInterface, Psr7Response): \Psr\Http\Message\ResponseInterface $action
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<mixed>}
     */
    private function call(string $method, callable $action, array $body): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $response = $action($request, new Psr7Response());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function invoiceCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = ?');
        $stmt->execute([$this->clientId]);

        return (int) $stmt->fetchColumn();
    }

    private function storedQuantity(int $invoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT quantity FROM invoice_items WHERE invoice_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$invoiceId]);

        return (string) $stmt->fetchColumn();
    }

    private function currency(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        return $id;
    }

    private function client(): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "TEST desetinná místa jednotek (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "unit-decimals@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }
}
