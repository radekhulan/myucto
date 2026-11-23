<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Recurring;

use MyInvoice\Action\Recurring\RecurringTemplateAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * OSS na položce ŠABLONY opakované faktury přes API (migrace 1297).
 *
 * Repozitář i generátor OSS sloupce umí ({@see \MyInvoice\Tests\Integration\RecurringGeneratorTest}),
 * jenže mezi formulářem a repozitářem stojí ještě {@see RecurringTemplateAction} a
 * {@see \MyInvoice\Service\Invoice\RecurringPriceListService::prepareForSave()}, který
 * položky PŘESKLÁDÁ. Kdyby se OSS klíče cestou ztratily, formulář by pole nabídl,
 * uživatel by je vyplnil a šablona by je tiše zahodila — na vygenerované faktuře by
 * nebylo poznat nic, protože derivace u ryze českého číselníku odpoví „tuzemsko".
 * Proto se tady jede CELÁ cesta: POST → GET → generování dokladu.
 *
 * Jen syntetická data; test po sobě uklízí a vrací dodavateli původní OSS/DPH nastavení.
 */
#[Group('integration')]
final class RecurringTemplateOssApiTest extends TestCase
{
    private Connection $db;
    private ContainerInterface $container;
    private PDO $pdo;
    private RecurringTemplateAction $action;
    private RecurringInvoiceGenerator $generator;
    private RecurringTemplateRepository $repo;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;

    /** @var list<int> */
    private array $createdTemplateIds = [];
    /** @var list<int> */
    private array $createdInvoiceIds = [];
    private ?array $origOssFlags = null;
    private ?array $origVatFlags = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->pdo = $this->db->pdo();
            $this->action = $this->container->get(RecurringTemplateAction::class);
            $this->generator = $this->container->get(RecurringInvoiceGenerator::class);
            $this->repo = $this->container->get(RecurringTemplateRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasColumn('recurring_invoice_template_items', 'oss_applicable')) {
            $this->markTestSkipped('Migrace 1297 na téhle DB neproběhla.');
        }

        $this->supplierId = (int) ($this->pdo->query(
            'SELECT id FROM supplier WHERE auto_generate_recurring = 1 ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Žádný dodavatel s auto_generate_recurring = 1.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL ORDER BY id LIMIT 1'
        );
        $stmt->execute([$this->supplierId]);
        $this->clientId = (int) ($stmt->fetchColumn() ?: 0);
        if ($this->clientId === 0) {
            $this->markTestSkipped('Dodavatel nemá žádného odběratele.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 ORDER BY id LIMIT 1'
        );
        $stmt->execute([$this->supplierId]);
        $this->currencyId = (int) ($stmt->fetchColumn() ?: 0);
        if ($this->currencyId === 0) {
            $this->markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        $this->vatRateId = (int) ($this->pdo->query(
            'SELECT id FROM vat_rates WHERE is_reverse_charge = 0 AND rate_percent > 0
              ORDER BY is_default DESC, rate_percent DESC LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            $this->markTestSkipped('Žádná použitelná sazba DPH.');
        }

        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

        // Generátor u neplátce autoritativně přepíná řádky na 0 % — test potřebuje plátce
        // (a plátcovství K DATU, ne jen živou cache).
        $this->origVatFlags = $this->pdo->query(
            "SELECT is_vat_payer, is_identified FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->pdo->prepare('UPDATE supplier SET is_vat_payer = 1, is_identified = 0 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->pdo->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer)
             VALUES (?, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE is_vat_payer = VALUES(is_vat_payer)'
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach ($this->createdInvoiceIds as $id) {
            $this->pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->createdTemplateIds as $id) {
            $this->pdo->prepare('DELETE FROM recurring_invoice_template_items WHERE template_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')->execute([$id]);
        }
        if ($this->origOssFlags !== null) {
            $this->pdo->prepare(
                'UPDATE supplier SET oss_enabled = ?, oss_valid_from = ?, oss_valid_to = ? WHERE id = ?'
            )->execute([
                (int) ($this->origOssFlags['oss_enabled'] ?? 0),
                $this->origOssFlags['oss_valid_from'] ?? null,
                $this->origOssFlags['oss_valid_to'] ?? null,
                $this->supplierId,
            ]);
        }
        if ($this->origVatFlags !== null) {
            $this->pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ? WHERE id = ?')->execute([
                (int) ($this->origVatFlags['is_vat_payer'] ?? 1),
                (int) ($this->origVatFlags['is_identified'] ?? 0),
                $this->supplierId,
            ]);
            $this->pdo->prepare(
                'DELETE FROM supplier_vat_status_history WHERE supplier_id = ? AND effective_from = CURDATE()'
            )->execute([$this->supplierId]);
        }
        $this->db->close();
    }

    /**
     * POST /api/recurring s OSS na položce → GET /api/recurring/{id} musí OSS vrátit
     * a vygenerovaná faktura ho musí nést.
     */
    public function testOssOnTemplateItemSurvivesApiRoundTripAndReachesGeneratedInvoice(): void
    {
        $this->setSupplierOss(true, '2020-01-01', null);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $created = $this->call('create', 'POST', $this->payload($today, [
            'oss_applicable' => true,
            'oss_consumer_country' => 'PL',
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'goods',
        ]));
        self::assertSame(201, $created['status'], 'Založení šablony selhalo: ' . json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $tplId = (int) ($created['body']['id'] ?? 0);
        self::assertGreaterThan(0, $tplId);
        $this->createdTemplateIds[] = $tplId;

        // 1) Odpověď POSTu už OSS nese — formulář po uložení nesmí přijít o rozhodnutí.
        $createdItem = $created['body']['items'][0] ?? [];
        self::assertTrue((bool) ($createdItem['oss_applicable'] ?? false), 'POST odpověď zahodila oss_applicable');
        self::assertSame('PL', $createdItem['oss_consumer_country'] ?? null);

        // 2) Čtecí cesta formuláře (GET detail) — bez ní by editace šablony OSS zrušila.
        $fetched = $this->call('get', 'GET', [], $tplId);
        self::assertSame(200, $fetched['status']);
        $item = $fetched['body']['items'][0] ?? [];
        self::assertTrue((bool) ($item['oss_applicable'] ?? false), 'GET detail zahodil oss_applicable');
        self::assertSame('PL', $item['oss_consumer_country'] ?? null);
        self::assertSame('standard', $item['oss_rate_type'] ?? null);
        self::assertSame('goods', $item['oss_supply_type'] ?? null);

        // 3) Uložení beze změny (PUT s tím, co vrátil GET) nesmí OSS ztratit — přesně tohle
        //    dělá formulář při každé editaci jiného pole šablony.
        $updated = $this->call('update', 'PUT', $this->payload($today, [
            'oss_applicable' => $item['oss_applicable'],
            'oss_consumer_country' => $item['oss_consumer_country'],
            'oss_rate_type' => $item['oss_rate_type'],
            'oss_supply_type' => $item['oss_supply_type'],
        ]), $tplId);
        self::assertSame(200, $updated['status'], json_encode($updated['body'], JSON_UNESCAPED_UNICODE));
        self::assertTrue((bool) ($updated['body']['items'][0]['oss_applicable'] ?? false), 'PUT zahodil oss_applicable');
        self::assertSame('PL', $updated['body']['items'][0]['oss_consumer_country'] ?? null);

        // 4) A hlavně: doklad, který z toho cron vyrobí.
        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = (int) $result['invoice_id'];

        $stmt = $this->pdo->prepare(
            'SELECT oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type
               FROM invoice_items WHERE invoice_id = ? ORDER BY order_index LIMIT 1'
        );
        $stmt->execute([(int) $result['invoice_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        self::assertSame(1, (int) ($row['oss_applicable'] ?? 0), 'OSS zadané přes API se na fakturu nepřeneslo');
        self::assertSame('PL', $row['oss_consumer_country'] ?? null);
        self::assertSame('standard', $row['oss_rate_type'] ?? null);
        self::assertSame('goods', $row['oss_supply_type'] ?? null);
    }

    /**
     * Šablona bez OSS se nesmí přes API tvářit jako OSS a naopak: neúplný řádek
     * ({@see \MyInvoice\Service\Oss\OssTemplateItemPolicy::storedColumns()} — zaškrtnuto,
     * ale bez země spotřeby) se ukládá jako NE-OSS, protože takovou položku by cron při
     * každém běhu vyrobil jako neplatnou.
     */
    public function testManualPriceFlagDoesNotLetNonPositiveTemplateThrough(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $payload = $this->payload($today, ['manual_price' => true]);
        $payload['items'][0]['unit_price_without_vat'] = 0;

        $created = $this->call('create', 'POST', $payload);

        self::assertSame(400, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        self::assertArrayHasKey('amount_to_pay', $created['body']['error']['fields'] ?? []);
    }

    public function testOssWithoutConsumerCountryIsStoredAsNonOss(): void
    {
        $this->setSupplierOss(true, '2020-01-01', null);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $created = $this->call('create', 'POST', $this->payload($today, [
            'oss_applicable' => true,
            'oss_consumer_country' => '',
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'services',
        ]));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $tplId = (int) $created['body']['id'];
        $this->createdTemplateIds[] = $tplId;

        $item = $this->call('get', 'GET', [], $tplId)['body']['items'][0] ?? [];
        self::assertArrayHasKey('oss_consumer_country', $item, 'GET detail OSS sloupce vůbec nevrací');
        self::assertFalse((bool) ($item['oss_applicable'] ?? true), 'Řádek bez země spotřeby nesmí zůstat OSS');
        self::assertNull($item['oss_consumer_country']);
        self::assertNull($item['oss_rate_type']);
    }

    /**
     * Šablona žije roky, registrace do OSS ne. Uložené rozhodnutí se k datu plnění mimo
     * registraci NEPOUŽIJE a přeřazení musí být vidět v datech — formulář na to uživatele
     * upozorňuje textem, tenhle test hlídá, že to tak backend opravdu dělá i pro data
     * zadaná přes API.
     */
    public function testExpiredRegistrationOverridesTemplateDecisionFromApi(): void
    {
        if (!$this->db->hasColumn('invoice_items', 'oss_needs_manual_review')) {
            $this->markTestSkipped('Migrace 1293 na téhle DB neproběhla.');
        }
        $this->setSupplierOss(true, '2020-01-01', (new \DateTimeImmutable('-1 year'))->format('Y-m-d'));
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $created = $this->call('create', 'POST', $this->payload($today, [
            'oss_applicable' => true,
            'oss_consumer_country' => 'PL',
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'goods',
        ]));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $tplId = (int) $created['body']['id'];
        $this->createdTemplateIds[] = $tplId;

        // Na šabloně rozhodnutí ZŮSTÁVÁ — registrace se vyhodnocuje až k datu dokladu.
        $item = $this->call('get', 'GET', [], $tplId)['body']['items'][0] ?? [];
        self::assertTrue((bool) ($item['oss_applicable'] ?? false));

        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = (int) $result['invoice_id'];

        $stmt = $this->pdo->prepare(
            'SELECT oss_applicable, oss_needs_manual_review FROM invoice_items
              WHERE invoice_id = ? ORDER BY order_index LIMIT 1'
        );
        $stmt->execute([(int) $result['invoice_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        self::assertSame(0, (int) ($row['oss_applicable'] ?? 1), 'Mimo registraci nesmí řádek nést OSS');
        self::assertSame(1, (int) ($row['oss_needs_manual_review'] ?? 0), 'Přebití rozhodnutí musí být vidět v datech');
    }

    /**
     * Šablona jen z textových řádků (item_kind 'text') by se uložila a každý běh
     * generátoru by pak spadl. Uložení ji musí odmítnout validační chybou na items.
     */
    public function testTemplateWithOnlyTextRowsIsRejected(): void
    {
        $body = $this->payload((new \DateTimeImmutable('today'))->format('Y-m-d'), []);
        $body['items'] = [['item_kind' => 'text', 'description' => 'Jen text', 'order_index' => 0]];

        $created = $this->call('create', 'POST', $body);
        if (isset($created['body']['id'])) {
            $this->createdTemplateIds[] = (int) $created['body']['id'];
        }

        self::assertSame(400, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        self::assertArrayHasKey('items', $created['body']['error']['fields'] ?? []);
    }

    /** @param array<string,mixed> $oss @return array<string,mixed> */
    private function payload(string $anchorDate, array $oss): array
    {
        return [
            'client_id' => $this->clientId,
            'name' => 'PHPUnit OSS šablona',
            'frequency' => 'monthly',
            'end_of_month' => false,
            'anchor_date' => $anchorDate,
            'next_run_date' => $anchorDate,
            'invoice_type' => 'invoice',
            'currency_id' => $this->currencyId,
            'language' => 'cs',
            'payment_method' => 'bank_transfer',
            'payment_due_days' => 14,
            'auto_issue' => true,
            'auto_send_email' => false,
            'increment_month_in_descriptions' => false,
            'items' => [[
                'description' => 'Zásilka spotřebiteli do EU',
                'quantity' => 1,
                'unit' => 'ks',
                'unit_price_without_vat' => 1000,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 0,
            ] + $oss],
        ];
    }

    private function setSupplierOss(bool $enabled, ?string $validFrom, ?string $validTo): void
    {
        if ($this->origOssFlags === null) {
            $this->origOssFlags = $this->pdo->query(
                "SELECT oss_enabled, oss_valid_from, oss_valid_to FROM supplier WHERE id = {$this->supplierId}"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $this->pdo->prepare('UPDATE supplier SET oss_enabled = ?, oss_valid_from = ?, oss_valid_to = ? WHERE id = ?')
            ->execute([$enabled ? 1 : 0, $validFrom, $validTo, $this->supplierId]);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $httpMethod, array $body, ?int $id = null): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/recurring')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);

        /** @var ResponseInterface $response */
        $response = $id === null
            ? $this->action->{$method}($request, new Psr7Response())
            : $this->action->{$method}($request, new Psr7Response(), ['id' => $id]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
