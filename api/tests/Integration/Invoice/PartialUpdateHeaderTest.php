<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\SetInvoiceProjectAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
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
 * PUT /api/invoices/{id} s částečnou hlavičkou (issue #113).
 *
 * Chybějící klíč znamená „ponech uloženou hodnotu", explicitní null/"" hodnotu maže.
 * Dřív se chybějící pole doplnila defaulty NOVÉHO dokladu (vystavení dnes, cena bez
 * DPH, sleva 0, upomínky zapnuté …), takže např. uložení výkazu práce, který hlavičku
 * posílá jen zčásti, překlopilo brutto fakturu na netto.
 *
 * Bez obalové transakce (akce končí přepočtem revenue cache s vlastní transakcí),
 * úklid v tearDown. Data jsou syntetická.
 */
#[Group('integration')]
final class PartialUpdateHeaderTest extends TestCase
{
    private const ISSUE_DATE = '2096-04-10';
    private const TAX_DATE   = '2096-04-12';
    private const DUE_DATE   = '2096-05-25';

    private Connection $db;
    private CreateInvoiceAction $create;
    private UpdateInvoiceAction $update;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db     = $c->get(Connection::class);
            $this->create = $c->get(CreateInvoiceAction::class);
            $this->update = $c->get(UpdateInvoiceAction::class);
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

        $this->currencyId = $this->currency();
        $this->clientId   = $this->client();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ([...$this->extraClients, $this->clientId] as $clientId) {
            if ($clientId <= 0) {
                continue;
            }
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$clientId]);
            $pdo->prepare('DELETE FROM project_revenue_cache WHERE project_id IN (SELECT id FROM projects WHERE client_id = ?)')
                ->execute([$clientId]);
            $pdo->prepare('DELETE FROM projects WHERE client_id = ?')->execute([$clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$clientId]);
        }
        $this->db->close();
    }

    /** BEZ OPRAVY PADÁ: tělo jen s poznámkou bylo odmítnuto (client_id povinný) a s ním resetovalo hlavičku. */
    public function testOmittedHeaderFieldsKeepStoredValues(): void
    {
        $id = $this->createInvoice();
        $before = $this->row($id);

        $res = $this->put($id, ['client_id' => $this->clientId, 'note_above_items' => 'Jen nová poznámka']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $after = $this->row($id);
        self::assertSame('Jen nová poznámka', $after['note_above_items']);
        foreach ([
            'issue_date', 'tax_date', 'due_date', 'currency_id', 'reverse_charge', 'prices_include_vat',
            'language', 'note_below_items', 'supplier_order_number', 'advance_paid_amount',
            'discount_percent', 'income_tax_exempt', 'income_tax_exempt_reason', 'auto_send_reminders',
            'vat_classification_code', 'total_with_vat', 'total_without_vat',
        ] as $col) {
            self::assertSame($before[$col], $after[$col], "Vynechané pole `$col` se nesmí změnit.");
        }
        self::assertSame('1', (string) $after['prices_include_vat']);
        self::assertSame('0', (string) $after['auto_send_reminders']);
    }

    /** BEZ OPRAVY PADÁ: bez client_id vracel PUT 400 validation_failed. */
    public function testClientIdIsNotRequiredOnUpdate(): void
    {
        $id = $this->createInvoice();
        $before = $this->row($id);

        $res = $this->put($id, ['supplier_order_number' => 'OBJ-2']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $after = $this->row($id);
        self::assertSame('OBJ-2', $after['supplier_order_number']);
        self::assertSame($before['client_id'], $after['client_id']);
        self::assertSame($before['prices_include_vat'], $after['prices_include_vat']);
    }

    public function testExplicitNullClears(): void
    {
        $id = $this->createInvoice();
        $before = $this->row($id);

        $res = $this->put($id, ['supplier_order_number' => null, 'note_below_items' => null, 'income_tax_exempt_reason' => '']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $after = $this->row($id);
        self::assertNull($after['supplier_order_number']);
        self::assertNull($after['note_below_items']);
        self::assertNull($after['income_tax_exempt_reason']);
        self::assertSame($before['note_above_items'], $after['note_above_items']);
        self::assertSame($before['discount_percent'], $after['discount_percent']);
    }

    /** Změna data vystavení bez due_date zachová uloženou lhůtu splatnosti. */
    public function testIssueDateChangeKeepsStoredPaymentTerm(): void
    {
        $id = $this->createInvoice();

        $res = $this->put($id, ['issue_date' => '2096-04-20']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $after = $this->row($id);
        self::assertSame('2096-04-20', $after['issue_date']);
        self::assertSame('2096-06-04', $after['due_date'], 'Lhůta 45 dní od vystavení zůstává.');
        self::assertSame(self::TAX_DATE, $after['tax_date']);
    }

    /** BEZ OPRAVY PADÁ: PUT bez project_id zakázku z dokladu odebral. */
    public function testOmittedProjectIsKept(): void
    {
        $projectId = $this->project();
        $id = $this->createInvoice($projectId);

        $res = $this->put($id, ['note_above_items' => 'Změna bez zakázky v těle']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame($projectId, (int) $this->row($id)['project_id']);
    }

    /** BEZ OPRAVY PADÁ: POST /invoices/{id}/project s prázdným tělem zakázku odebral (200). */
    public function testSetProjectRequiresKey(): void
    {
        $projectId = $this->project();
        $id = $this->createInvoice($projectId);
        $action = Bootstrap::buildContainer()->get(SetInvoiceProjectAction::class);

        $res = self::decode($action($this->request('POST', []), new Psr7Response(), ['id' => (string) $id]));
        self::assertSame(400, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('validation_failed', $res['body']['error']['code'] ?? null);
        self::assertSame($projectId, (int) $this->row($id)['project_id'], 'Prázdné tělo nesmí zakázku odebrat.');

        $res = self::decode($action($this->request('POST', ['project_id' => null]), new Psr7Response(), ['id' => (string) $id]));
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertNull($this->row($id)['project_id'], 'Explicitní null zakázku odebere.');
    }

    /** BEZ OPRAVY PADÁ: změna slevy bez položek přepsala jen hlavičku, slevový řádek zůstal na 10 %. */
    public function testDiscountChangeWithoutItemsRegeneratesDiscountLine(): void
    {
        $id = $this->createInvoice();
        $before = $this->discountLineTotal($id);
        self::assertLessThan(0.0, $before);

        $res = $this->put($id, ['discount_percent' => 20]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        self::assertEqualsWithDelta(2 * $before, $this->discountLineTotal($id), 0.011);
        self::assertSame(1, $this->standardLineCount($id), 'Uživatelské položky zůstávají.');
        $row = $this->row($id);
        self::assertSame('20.00', (string) $row['discount_percent']);
        self::assertSame('1', (string) $row['prices_include_vat']);
    }

    /** BEZ OPRAVY PADÁ: nový klient bez project_id převzal zakázku starého → 400 integrity_violation. */
    public function testClientChangeDropsStoredProject(): void
    {
        $projectId = $this->project();
        $id = $this->createInvoice($projectId);
        $other = $this->client();
        $this->extraClients[] = $other;

        $res = $this->put($id, ['client_id' => $other]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $row = $this->row($id);
        self::assertSame($other, (int) $row['client_id']);
        self::assertNull($row['project_id']);
    }

    /** BEZ OPRAVY PADÁ: revenue_category_id: null nechal uložený legacy text kategorie. */
    public function testRevenueCategoryPairIsClearedTogether(): void
    {
        $id = $this->createInvoice();
        $this->db->pdo()->prepare("UPDATE invoices SET revenue_category = 'Syntetická kategorie' WHERE id = ?")->execute([$id]);

        $res = $this->put($id, ['revenue_category_id' => null]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertNull($this->row($id)['revenue_category']);
    }

    /** BEZ OPRAVY PADÁ: změna reverse charge bez kódu ponechala uložený tuzemský kód hlavičky. */
    public function testReverseChargeChangeRederivesHeaderClassification(): void
    {
        $id = $this->createInvoice();
        $before = (string) $this->row($id)['vat_classification_code'];
        self::assertNotSame('', $before);

        $res = $this->put($id, ['reverse_charge' => true]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $after = $this->row($id)['vat_classification_code'];
        self::assertNotNull($after);
        self::assertNotSame($before, (string) $after);

        // Bez změny vstupu klasifikace zůstává uložený kód.
        $this->db->pdo()->prepare("UPDATE invoices SET vat_classification_code = '1' WHERE id = ?")->execute([$id]);
        $res = $this->put($id, ['note_above_items' => 'Jen poznámka']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('1', $this->row($id)['vat_classification_code']);
    }

    /**
     * BEZ OPRAVY PADÁ: notes_only porovnává jen klíče v těle, takže tělo bez
     * prices_include_vat / discount_percent bránou prošlo a pole pak zresetovalo.
     * Po sloučení s uloženou hlavičkou je tělo úplné a brána vidí skutečný stav.
     */
    public function testNotesOnlyGuardSeesMergedHeader(): void
    {
        $merge = new \ReflectionMethod(UpdateInvoiceAction::class, 'mergeStoredHeader');
        $guard = new \ReflectionMethod(UpdateInvoiceAction::class, 'financialFieldsChanged');
        $existing = [
            'client_id' => 5, 'issue_date' => self::ISSUE_DATE, 'tax_date' => self::TAX_DATE, 'due_date' => self::DUE_DATE,
            'currency_id' => 1, 'reverse_charge' => 0, 'prices_include_vat' => 1, 'discount_percent' => '10.00',
            'advance_paid_amount' => '100.00', 'vat_classification_code' => '1', 'note_above_items' => 'původní',
            'items' => [],
        ];

        $merged = $merge->invoke(null, ['note_above_items' => 'nová'], $existing);
        self::assertSame(1, $merged['prices_include_vat']);
        self::assertSame('10.00', $merged['discount_percent']);
        self::assertSame([], $guard->invoke(null, $merged, $existing));

        $merged = $merge->invoke(null, ['note_above_items' => 'nová', 'prices_include_vat' => false], $existing);
        self::assertContains('prices_include_vat', $guard->invoke(null, $merged, $existing));
    }

    /** BEZ OPRAVY PADÁ: tělo bez currency_id/issue_date se tvářilo jako změna a přefetchovalo kurz. */
    public function testPartialBodyDoesNotRefetchForeignRate(): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 AND code = 'EUR' ORDER BY id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $eur = (int) $stmt->fetchColumn();
        if ($eur === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní EUR.');
        }
        $pdo = $this->db->pdo();
        $cached = $pdo->prepare("SELECT * FROM exchange_rates WHERE currency_code = 'EUR' AND rate_date IN (?, ?)");
        $cached->execute([self::ISSUE_DATE, self::TAX_DATE]);
        $originalRates = $cached->fetchAll(\PDO::FETCH_ASSOC);
        $insert = $pdo->prepare("REPLACE INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, 'EUR', 25.0)");
        try {
            foreach ([self::ISSUE_DATE, self::TAX_DATE] as $date) {
                $insert->execute([$date]);
            }
            $id = $this->createInvoice(null, $eur);
            self::assertSame(25.0, (float) $this->row($id)['exchange_rate']);
            $pdo->prepare("UPDATE invoices SET exchange_rate = 33.3333, exchange_rate_date = '2096-04-01' WHERE id = ?")
                ->execute([$id]);

            $res = $this->put($id, ['note_above_items' => 'Jen poznámka']);
            self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
            $row = $this->row($id);
            self::assertEqualsWithDelta(33.3333, (float) $row['exchange_rate'], 0.00001);
            self::assertSame('2096-04-01', $row['exchange_rate_date']);
        } finally {
            $pdo->prepare("DELETE FROM exchange_rates WHERE currency_code = 'EUR' AND rate_date IN (?, ?)")
                ->execute([self::ISSUE_DATE, self::TAX_DATE]);
            $restore = $pdo->prepare('INSERT INTO exchange_rates (rate_date, currency_code, rate, fetched_at) VALUES (?, ?, ?, ?)');
            foreach ($originalRates as $rate) {
                $restore->execute([$rate['rate_date'], $rate['currency_code'], $rate['rate'], $rate['fetched_at']]);
            }
        }
    }

    /** BEZ OPRAVY PADÁ: POST /invoices ruční kurz z těla zahodil a uložil kurz ČNB. */
    public function testCreateKeepsManualExchangeRate(): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 AND code = 'EUR' ORDER BY id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $eur = (int) $stmt->fetchColumn();
        if ($eur === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní EUR.');
        }
        $pdo = $this->db->pdo();
        $cached = $pdo->prepare("SELECT * FROM exchange_rates WHERE currency_code = 'EUR' AND rate_date IN (?, ?)");
        $cached->execute([self::ISSUE_DATE, self::TAX_DATE]);
        $originalRates = $cached->fetchAll(\PDO::FETCH_ASSOC);
        $insert = $pdo->prepare("REPLACE INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, 'EUR', 25.0)");
        try {
            foreach ([self::ISSUE_DATE, self::TAX_DATE] as $date) {
                $insert->execute([$date]);
            }
            $id = $this->createInvoice(null, $eur, ['exchange_rate' => 24.125]);
            $row = $this->row($id);
            self::assertEqualsWithDelta(24.125, (float) $row['exchange_rate'], 0.00001, 'Ruční kurz z POST se musí uložit.');
            self::assertSame(self::TAX_DATE, $row['exchange_rate_date'], 'Rozhodný den ručního kurzu je DUZP.');

            $id = $this->createInvoice(null, $eur);
            self::assertSame(25.0, (float) $this->row($id)['exchange_rate'], 'Bez ručního kurzu platí ČNB.');
        } finally {
            $pdo->prepare("DELETE FROM exchange_rates WHERE currency_code = 'EUR' AND rate_date IN (?, ?)")
                ->execute([self::ISSUE_DATE, self::TAX_DATE]);
            $restore = $pdo->prepare('INSERT INTO exchange_rates (rate_date, currency_code, rate, fetched_at) VALUES (?, ?, ?, ?)');
            foreach ($originalRates as $rate) {
                $restore->execute([$rate['rate_date'], $rate['currency_code'], $rate['rate'], $rate['fetched_at']]);
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @var list<int> */
    private array $extraClients = [];

    private function discountLineTotal(int $id): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(total_without_vat), 0) FROM invoice_items WHERE invoice_id = ? AND item_kind = 'discount'"
        );
        $stmt->execute([$id]);

        return (float) $stmt->fetchColumn();
    }

    private function standardLineCount(int $id): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ? AND item_kind = 'standard'"
        );
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    private function project(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO projects (client_id, name, currency_id) VALUES (?, ?, ?)')
            ->execute([$this->clientId, 'Syntetická zakázka (PHPUnit)', $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $extra */
    private function createInvoice(?int $projectId = null, ?int $currencyId = null, array $extra = []): int
    {
        $created = self::decode(($this->create)($this->request('POST', $extra + [
            'project_id'               => $projectId,
            'invoice_type'             => 'invoice',
            'client_id'                => $this->clientId,
            'issue_date'               => self::ISSUE_DATE,
            'tax_date'                 => self::TAX_DATE,
            'due_date'                 => self::DUE_DATE,
            'currency_id'              => $currencyId ?? $this->currencyId,
            'reverse_charge'           => false,
            'prices_include_vat'       => true,
            'payment_method'           => PaymentMethods::DEFAULT,
            'language'                 => 'en',
            'note_above_items'         => 'Nad položkami',
            'note_below_items'         => 'Pod položkami',
            'supplier_order_number'    => 'OBJ-1',
            'advance_paid_amount'      => 100,
            'discount_percent'         => 10,
            'income_tax_exempt'        => true,
            'income_tax_exempt_reason' => 'Syntetický důvod',
            'auto_send_reminders'      => false,
            'items'                    => [[
                'description'            => 'Konzultace (PHPUnit)',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 1210.0,
                'vat_rate_id'            => $this->vatRateId,
            ]],
        ]), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));

        return (int) $created['body']['id'];
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);

        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function put(int $id, array $body): array
    {
        return self::decode(
            ($this->update)($this->request('PUT', $body), new Psr7Response(), ['id' => (string) $id])
        );
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function currency(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 AND code = 'CZK'
              ORDER BY is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní CZK.');
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
             VALUES (?, "TEST partial header (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "partial-header@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }
}
