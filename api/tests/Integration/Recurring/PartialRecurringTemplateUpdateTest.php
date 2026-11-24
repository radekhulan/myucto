<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Recurring;

use MyInvoice\Action\Recurring\RecurringTemplateAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * PUT /api/recurring/{id} s částečným tělem (issue #113): vynechaný klíč drží uloženou
 * hodnotu šablony (dřív spadl na default — proforma → faktura, brutto → netto, konec
 * platnosti zmizel …), vynechané items zůstávají, explicitní null maže.
 * Jen syntetická data, úklid v tearDown.
 */
#[Group('integration')]
final class PartialRecurringTemplateUpdateTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private RecurringTemplateAction $action;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $templateId = 0;

    private const KEPT = [
        'client_id', 'name', 'frequency', 'day_of_month', 'end_of_month', 'anchor_date', 'end_date',
        'next_run_date', 'invoice_type', 'currency_id', 'language', 'payment_method', 'reverse_charge',
        'prices_include_vat', 'discount_percent', 'payment_due_days', 'payment_due_unit', 'tax_date_mode',
        'draft_open_mode', 'reminder_days_before', 'note_above_items', 'note_below_items',
        'increment_month_in_descriptions', 'auto_issue', 'auto_send_email', 'branding_profile_id',
    ];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->pdo = $this->db->pdo();
            $this->action = $c->get(RecurringTemplateAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($this->pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1")->fetchColumn() ?: 0);
        $stmt = $this->pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1");
        $stmt->execute([$this->supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        $czId = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (!$this->supplierId || !$this->userId || !$this->vatRateId || !$this->currencyId || !$czId) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'TEST partial recurring (PHPUnit)', 'Ulice 1', 'Praha', '11000', $czId, 'partial-rec@example.test', $this->currencyId]);
        $this->clientId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->templateId > 0) {
            $this->pdo->prepare('DELETE FROM recurring_invoice_template_items WHERE template_id = ?')->execute([$this->templateId]);
            $this->pdo->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')->execute([$this->templateId]);
        }
        if ($this->clientId > 0) {
            $this->pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    public function testTagIntersectionEmptySelectionAndPartialUpdates(): void
    {
        $prefix = 'PHPUnit-' . bin2hex(random_bytes(6));
        $names = ["$prefix hosting", "$prefix roční"];
        try {
            $created = $this->call('create', ['tags' => [...$names, strtoupper($names[0])]] + $this->payload());
            self::assertSame(201, $created['status']);
            $this->templateId = (int) $created['body']['id'];
            $tags = $created['body']['tags'];
            self::assertCount(2, $tags);

            $repo = new \MyInvoice\Repository\RecurringTemplateRepository($this->db);
            $filters = ['supplier_id' => $this->supplierId, 'client_id' => $this->clientId];
            $intersection = implode(',', array_column($tags, 'id'));
            self::assertSame(1, $repo->list($filters + ['tag_ids' => $intersection])['meta']['total']);
            self::assertSame(0, $repo->list($filters + ['tag_ids' => 'none'])['meta']['total']);
            self::assertSame($tags, $this->call('update', ['name' => 'Paušál'])['body']['tags']);
            self::assertSame(400, $this->call('update', ['tags' => [null]])['status']);
            self::assertSame(403, $this->call('update', ['tags' => []], 'client')['status']);
            self::assertSame(200, $this->call('update', ['tags' => [$names[0]]])['status']);
            self::assertSame(0, $repo->list($filters + ['tag_ids' => $intersection])['meta']['total']);
            self::assertSame([], $this->call('update', ['tags' => []])['body']['tags']);
            self::assertSame(1, $repo->list($filters + ['tag_ids' => 'none'])['meta']['total']);
        } finally {
            $this->pdo->prepare('DELETE FROM entity_tags WHERE supplier_id = ? AND name IN (?, ?)')
                ->execute([$this->supplierId, ...$names]);
        }
    }

    /** BEZ OPRAVY PADÁ: tělo jen s názvem bylo odmítnuto (client_id, items … povinné). */
    public function testOmittedKeysKeepStoredValues(): void
    {
        $before = $this->createTemplate();

        $res = $this->call('update', ['name' => 'Přejmenovaná šablona']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $after = $res['body'];

        self::assertSame('Přejmenovaná šablona', $after['name']);
        foreach (self::KEPT as $col) {
            if ($col === 'name') continue;
            self::assertSame($before[$col] ?? null, $after[$col] ?? null, "Vynechané pole `$col` se nesmí změnit.");
        }
        self::assertCount(2, $after['items'], 'Vynechané items zůstávají.');
        self::assertSame(
            array_column($before['items'], 'description'),
            array_column($after['items'], 'description'),
        );
    }

    /** BEZ OPRAVY PADÁ: s celou hlavičkou bez dotčených polí spadly na defaulty. */
    public function testFullHeaderWithoutSomeKeysKeepsThem(): void
    {
        $before = $this->createTemplate();

        $body = $this->payload();
        unset($body['prices_include_vat'], $body['invoice_type'], $body['end_date'], $body['auto_issue'],
            $body['auto_send_email'], $body['tax_date_mode'], $body['payment_method'], $body['discount_percent']);
        $res = $this->call('update', $body);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        foreach (['prices_include_vat', 'invoice_type', 'end_date', 'auto_issue', 'auto_send_email',
                  'tax_date_mode', 'payment_method', 'discount_percent'] as $col) {
            self::assertSame($before[$col], $res['body'][$col], "Vynechané pole `$col` se nesmí změnit.");
        }
    }

    public function testExplicitNullClearsAndDayRuleSwitches(): void
    {
        $this->createTemplate();

        $res = $this->call('update', ['end_date' => null, 'note_above_items' => null, 'end_of_month' => true]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertNull($res['body']['end_date']);
        self::assertNull($res['body']['note_above_items']);
        self::assertTrue($res['body']['end_of_month']);
        self::assertNull($res['body']['day_of_month'], 'Poslední den měsíce nahrazuje uložený den v měsíci.');
        self::assertSame('Pod položkami', $res['body']['note_below_items']);
        self::assertCount(2, $res['body']['items']);
    }

    /** @return array<string,mixed> */
    private function createTemplate(): array
    {
        $res = $this->call('create', $this->payload());
        self::assertSame(201, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $this->templateId = (int) $res['body']['id'];

        return $res['body'];
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'client_id'                       => $this->clientId,
            'name'                            => 'PHPUnit částečná šablona',
            'frequency'                       => 'monthly',
            'day_of_month'                    => 15,
            'end_of_month'                    => false,
            'anchor_date'                     => '2096-01-15',
            'end_date'                        => '2097-12-31',
            'invoice_type'                    => 'proforma',
            'currency_id'                     => $this->currencyId,
            'language'                        => 'en',
            'payment_method'                  => 'cash',
            'reverse_charge'                  => false,
            'prices_include_vat'              => true,
            'discount_percent'                => 5,
            'payment_due_days'                => 30,
            'payment_due_unit'                => 'days',
            'tax_date_mode'                   => 'previous_month_last_day',
            'draft_open_mode'                 => 'at_issue',
            'reminder_days_before'            => 3,
            'note_above_items'                => 'Nad položkami',
            'note_below_items'                => 'Pod položkami',
            'increment_month_in_descriptions' => true,
            'auto_issue'                      => true,
            'auto_send_email'                 => true,
            'items'                           => [
                ['description' => 'Pronájem', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 1210, 'vat_rate_id' => $this->vatRateId, 'order_index' => 0],
                ['description' => 'Služby', 'quantity' => 2, 'unit' => 'ks', 'unit_price_without_vat' => 121, 'vat_rate_id' => $this->vatRateId, 'order_index' => 1],
            ],
        ];
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, array $body, string $role = 'admin'): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method === 'create' ? 'POST' : 'PUT', '/api/recurring')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withParsedBody($body);
        $response = $method === 'create'
            ? $this->action->create($request, new Psr7Response())
            : $this->action->update($request, new Psr7Response(), ['id' => $this->templateId]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
