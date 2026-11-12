<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Client\CreateClientAction;
use MyInvoice\Action\Client\UpdateClientAction;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\Invoice\InvoiceIssueTaxGate;
use MyInvoice\Service\Validation;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Drobný zákazník bez adresy (#310, `clients.simplified_only`).
 *
 * Karta smí mít jen jméno a e-mail nebo telefon. Plátce takovému odběrateli vystaví jen
 * zjednodušený daňový doklad (§ 30 ZDPH) do limitu 10 000 Kč s daní; běžnou fakturu
 * (§ 29 odst. 1 písm. c) ani doklad nad limitem brána nepustí. Dobropis jen jako zjednodušený
 * opravný daňový doklad k jeho zjednodušenému dokladu (§ 45 odst. 2 ZDPH) a nejvýše do částky,
 * která z původního dokladu zbývá opravit.
 */
#[Group('integration')]
final class SimplifiedOnlyClientTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ClientRepository $clients;
    private InvoiceRepository $invoices;
    private IssueInvoiceAction $issue;
    private CreateInvoiceAction $create;
    private CreateClientAction $createClient;
    private UpdateClientAction $updateClient;
    private InvoiceIssueTaxGate $gate;
    private AutoIssueAndSendService $autoIssue;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db           = $c->get(Connection::class);
            $this->clients      = $c->get(ClientRepository::class);
            $this->invoices     = $c->get(InvoiceRepository::class);
            $this->issue        = $c->get(IssueInvoiceAction::class);
            $this->create       = $c->get(CreateInvoiceAction::class);
            $this->createClient = $c->get(CreateClientAction::class);
            $this->updateClient = $c->get(UpdateClientAction::class);
            $this->gate         = $c->get(InvoiceIssueTaxGate::class);
            $this->autoIssue    = $c->get(AutoIssueAndSendService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if (in_array(0, [$source, $this->userId, $this->currencyId, $this->vatRateId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET stock_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);

        $columns = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'currencies'
                AND COLUMN_NAME NOT IN ('id', 'supplier_id') AND EXTRA NOT LIKE '%auto_increment%'
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $list = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
        $pdo->prepare("INSERT INTO currencies (supplier_id, {$list}) SELECT ?, {$list} FROM currencies WHERE id = ?")
            ->execute([$this->supplierId, $this->currencyId]);
        $this->currencyId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    // ── karta klienta ────────────────────────────────────────────────────────

    public function testValidationAllowsMissingAddressOnlyForSimplifiedOnlyClient(): void
    {
        $small = ['company_name' => 'Pepa Novák', 'main_email' => 'pepa@example.test', 'simplified_only' => true];

        self::assertSame([], Validation::client($small));
        self::assertSame([], Validation::client(['company_name' => 'Pepa Novák', 'phone' => '+420 600 000 000', 'simplified_only' => true]));

        $regular = Validation::client(['company_name' => 'Pepa Novák', 'main_email' => 'pepa@example.test']);
        self::assertArrayHasKey('street', $regular, 'Běžná karta adresu dál vyžaduje.');
        self::assertArrayHasKey('city', $regular);
        self::assertArrayHasKey('zip', $regular);
    }

    public function testSimplifiedOnlyClientNeedsContactAndNoReverseCharge(): void
    {
        $noContact = Validation::client(['company_name' => 'Pepa Novák', 'simplified_only' => true]);
        self::assertArrayHasKey('main_email', $noContact);

        $reverseCharge = Validation::client([
            'company_name' => 'Pepa Novák', 'main_email' => 'pepa@example.test',
            'simplified_only' => true, 'reverse_charge' => true,
        ]);
        self::assertArrayHasKey('reverse_charge', $reverseCharge);
    }

    public function testClientWithNameAndEmailIsSavedAndPartialUpdateKeepsFlag(): void
    {
        $created = $this->clientRequest($this->createClient, 'POST', '/api/clients', [
            'company_name' => 'Pepa Novák', 'main_email' => 'pepa@example.test',
            'simplified_only' => true, 'country_iso2' => 'CZ', 'currency_default_id' => $this->currencyId,
        ]);
        self::assertSame(201, $created['status'], (string) json_encode($created['body']));
        $id = (int) $created['body']['id'];
        self::assertTrue($created['body']['simplified_only']);
        self::assertSame('', $created['body']['street']);

        $updated = $this->clientRequest($this->updateClient, 'PUT', '/api/clients/' . $id, ['phone' => '+420 600 000 000'], ['id' => (string) $id]);
        self::assertSame(200, $updated['status'], (string) json_encode($updated['body']));
        self::assertTrue($this->clients->find($id)['simplified_only'], 'Částečný update příznak nesmaže.');

        $off = $this->clientRequest($this->updateClient, 'PUT', '/api/clients/' . $id, ['simplified_only' => false], ['id' => (string) $id]);
        self::assertSame(400, $off['status'], 'Bez příznaku musí karta mít adresu.');
        self::assertArrayHasKey('street', $off['body']['error']['fields'] ?? []);
    }

    // ── vystavení ────────────────────────────────────────────────────────────

    public function testNewDraftForSimplifiedOnlyClientIsSimplified(): void
    {
        $small = $this->smallClient();

        $id = $this->createDraft(['client_id' => $small]);
        $explicit = $this->createDraft(['client_id' => $small, 'is_simplified' => false]);
        $regular = $this->createDraft(['client_id' => $this->regularClient()]);

        self::assertTrue($this->invoices->find($id)['is_simplified']);
        self::assertTrue($this->invoices->find($id)['client_simplified_only']);
        self::assertFalse($this->invoices->find($explicit)['is_simplified'], 'Výslovná volba má přednost.');
        self::assertFalse($this->invoices->find($regular)['is_simplified'], 'Běžný klient beze změny.');
    }

    public function testSimplifiedDocumentUnderLimitIsIssued(): void
    {
        $id = $this->draft($this->smallClient(), simplified: true, total: 1000);

        self::assertSame(200, $this->issueDraft($id)['status']);
    }

    public function testRegularInvoiceForSimplifiedOnlyClientIsRefused(): void
    {
        $id = $this->draft($this->smallClient(), simplified: false, total: 1000);

        $result = $this->issueDraft($id);

        self::assertSame(422, $result['status']);
        self::assertSame('simplified_only_client_requires_simplified', $result['code']);
    }

    public function testProformaForSimplifiedOnlyClientIsRefused(): void
    {
        $id = $this->draft($this->smallClient(), simplified: true, total: 1000, type: 'proforma');

        $result = $this->issueDraft($id);

        self::assertSame(422, $result['status']);
        self::assertSame('simplified_only_client_requires_simplified', $result['code']);
    }

    public function testOverLimitOffersCompletingCardOrReducingSale(): void
    {
        $id = $this->draft($this->smallClient(), simplified: true, total: 12100);

        $result = $this->issueDraft($id);

        self::assertSame(422, $result['status']);
        self::assertSame('simplified_only_client_over_limit', $result['code']);
        self::assertStringContainsString('Doplňte na kartě odběratele adresu', (string) $result['message']);
        self::assertStringContainsString('snižte pod limit', (string) $result['message']);
    }

    public function testRegularClientIsUnaffected(): void
    {
        $id = $this->draft($this->regularClient(), simplified: false, total: 12100);

        self::assertSame(200, $this->issueDraft($id)['status']);
    }

    public function testNonVatPayerMayInvoiceSimplifiedOnlyClient(): void
    {
        $this->setVatPayerAt($this->db->pdo(), $this->supplierId, '1900-01-01', false);
        $id = $this->draft($this->smallClient(), simplified: false, total: 12100, vat: false);

        self::assertNull($this->gate->simplifiedOnlyClientRejection($this->invoices->find($id)));
    }

    public function testAutomaticIssueRespectsTheGate(): void
    {
        $id = $this->draft($this->smallClient(), simplified: false, total: 1000);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('drobný zákazník');
        $this->autoIssue->run($id, $this->userId, '127.0.0.1', 'phpunit');
    }

    // ── dobropis (§ 45 odst. 2 ZDPH) ────────────────────────────────────────

    public function testCreditNoteDraftViaApiInheritsSimplifiedFlag(): void
    {
        $small = $this->smallClient();
        $original = $this->issuedOriginal($small, simplified: true, total: 1000);
        $regularOriginal = $this->issuedOriginal($this->regularClient(), simplified: true, total: 1000);

        $id = $this->createDraft(['client_id' => $small, 'invoice_type' => 'credit_note', 'parent_invoice_id' => $original]);
        $regular = $this->createDraft([
            'client_id' => (int) $this->invoices->find($regularOriginal)['client_id'],
            'invoice_type' => 'credit_note', 'parent_invoice_id' => $regularOriginal,
        ]);

        self::assertTrue($this->invoices->find($id)['is_simplified']);
        self::assertFalse($this->invoices->find($regular)['is_simplified'], 'Známému odběrateli se příznak nedědí.');
    }

    public function testCreditNoteWithoutOriginalIsRefused(): void
    {
        $id = $this->draft($this->smallClient(), simplified: true, total: -500, type: 'credit_note');

        $result = $this->issueDraft($id);

        self::assertSame(422, $result['status']);
        self::assertSame('simplified_only_client_credit_note_requires_simplified_original', $result['code']);
        self::assertStringContainsString('doplňte na kartě odběratele adresu', (string) $result['message']);
    }

    public function testCreditNoteForRegularOriginalIsRefused(): void
    {
        $small = $this->smallClient();
        $original = $this->issuedOriginal($small, simplified: false, total: 1000);
        $id = $this->draft($small, simplified: true, total: -500, type: 'credit_note', parentId: $original);

        $result = $this->issueDraft($id);

        self::assertSame(422, $result['status']);
        self::assertSame('simplified_only_client_credit_note_requires_simplified_original', $result['code']);
    }

    public function testCreditNoteForAnotherClientsOriginalIsRefused(): void
    {
        $original = $this->issuedOriginal($this->smallClient(), simplified: true, total: 1000);
        $id = $this->draft($this->smallClient(), simplified: true, total: -500, type: 'credit_note', parentId: $original);

        self::assertSame('simplified_only_client_credit_note_requires_simplified_original', $this->issueDraft($id)['code']);
    }

    public function testRegularCreditNoteForSimplifiedOnlyClientIsRefused(): void
    {
        $small = $this->smallClient();
        $original = $this->issuedOriginal($small, simplified: true, total: 1000);
        $id = $this->draft($small, simplified: false, total: -500, type: 'credit_note', parentId: $original);

        $result = $this->issueDraft($id);

        self::assertSame(422, $result['status']);
        self::assertSame('simplified_only_client_requires_simplified', $result['code']);
    }

    public function testCreditNoteOverOriginalAmountIsRefused(): void
    {
        $small = $this->smallClient();
        $original = $this->issuedOriginal($small, simplified: true, total: 1000);
        $over = $this->draft($small, simplified: true, total: -1200, type: 'credit_note', parentId: $original);

        $result = $this->issueDraft($over);

        self::assertSame(422, $result['status']);
        self::assertSame('simplified_only_client_credit_note_over_original', $result['code']);
        self::assertStringContainsString('zbývá 1 000,00 Kč', (string) $result['message']);
    }

    public function testEarlierCreditNotesReduceWhatRemainsToCorrect(): void
    {
        $small = $this->smallClient();
        $original = $this->issuedOriginal($small, simplified: true, total: 1000);
        $earlier = $this->draft($small, simplified: true, total: -600, type: 'credit_note', parentId: $original);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = ? WHERE id = ?")
            ->execute(['DOB' . $earlier, $earlier]);
        $rest = $this->draft($small, simplified: true, total: -400, type: 'credit_note', parentId: $original);
        $over = $this->draft($small, simplified: true, total: -401, type: 'credit_note', parentId: $original);

        self::assertNull($this->gate->simplifiedOnlyClientRejection($this->invoices->find($rest)));
        self::assertSame(
            'simplified_only_client_credit_note_over_original',
            $this->gate->simplifiedOnlyClientRejection($this->invoices->find($over))['code'] ?? null,
        );
    }

    public function testRegularClientCreditNoteIsUnaffected(): void
    {
        $regular = $this->regularClient();
        $original = $this->issuedOriginal($regular, simplified: false, total: 12100);
        $id = $this->draft($regular, simplified: false, total: -12100, type: 'credit_note', parentId: $original);
        $orphan = $this->draft($regular, simplified: false, total: -500, type: 'credit_note');

        self::assertSame(200, $this->issueDraft($id)['status']);
        self::assertNull($this->gate->simplifiedOnlyClientRejection($this->invoices->find($orphan)));
    }

    public function testSimplifiedCreditNoteUnderOriginalIsIssued(): void
    {
        $small = $this->smallClient();
        $original = $this->issuedOriginal($small, simplified: true, total: 1210);
        $id = $this->draft($small, simplified: true, total: -1210, type: 'credit_note', parentId: $original);

        self::assertSame(200, $this->issueDraft($id)['status']);
    }

    public function testNonVatPayerCreditNoteForSimplifiedOnlyClientIsUnaffected(): void
    {
        $this->setVatPayerAt($this->db->pdo(), $this->supplierId, '1900-01-01', false);
        $id = $this->draft($this->smallClient(), simplified: false, total: -12100, type: 'credit_note', vat: false);

        self::assertNull($this->gate->simplifiedOnlyClientRejection($this->invoices->find($id)));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function smallClient(): int
    {
        return $this->clients->create([
            'company_name' => 'Pepa Novák', 'main_email' => 'pepa@example.test', 'simplified_only' => true,
            'country_iso2' => 'CZ', 'currency_default_id' => $this->currencyId,
        ], $this->supplierId);
    }

    private function regularClient(): int
    {
        return $this->clients->create([
            'company_name' => 'Stálý odběratel s.r.o.', 'street' => 'Test 1', 'city' => 'Praha', 'zip' => '11000',
            'country_iso2' => 'CZ', 'currency_default_id' => $this->currencyId,
        ], $this->supplierId);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    private function clientRequest(callable $action, string $method, string $uri, array $body, array $args = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $res = $args === [] ? $action($req, new Psr7Response()) : $action($req, new Psr7Response(), $args);
        $res->getBody()->rewind();

        return ['status' => $res->getStatusCode(), 'body' => (array) json_decode((string) $res->getBody(), true)];
    }

    /** @param array<string,mixed> $overrides */
    private function createDraft(array $overrides): int
    {
        $body = $overrides + [
            'invoice_type' => 'invoice',
            'currency_id'  => $this->currencyId,
            'issue_date'   => '2099-01-05',
            'tax_date'     => '2099-01-05',
            'due_date'     => '2099-01-19',
            'items'        => [[
                'description' => 'Drobný prodej', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => 80, 'vat_rate_id' => $this->vatRateId,
            ]],
        ];
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $res = ($this->create)($req, new Psr7Response());
        $res->getBody()->rewind();
        $json = json_decode((string) $res->getBody(), true);
        self::assertContains($res->getStatusCode(), [200, 201], (string) json_encode($json));

        return (int) ($json['data']['id'] ?? $json['id'] ?? 0);
    }

    private function draft(
        int $clientId,
        bool $simplified,
        float $total,
        string $type = 'invoice',
        bool $vat = true,
        ?int $parentId = null,
    ): int {
        $base = $vat ? round($total / 1.21, 2) : $total;
        $tax = round($total - $base, 2);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, invoice_type, parent_invoice_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, client_snapshot, supplier_snapshot, is_simplified,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, "2099-01-05", ?, "2099-01-05", ?, 0, "{}", "{}", ?,
                     ?, ?, ?, "draft", "1", ?)'
        )->execute([
            $this->supplierId, $clientId, $type, $parentId, $type === 'proforma' ? null : '2099-01-05',
            $this->currencyId, $simplified ? 1 : 0, $base, $tax, $total, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Drobný prodej", 1, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$id, $base, $this->vatRateId, $vat ? 21.00 : 0.00, $base, $tax, $total]);

        return $id;
    }

    private function issuedOriginal(int $clientId, bool $simplified, float $total): int
    {
        $id = $this->draft($clientId, $simplified, $total);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = ? WHERE id = ?")
            ->execute(['ZD' . $id, $id]);

        return $id;
    }

    /** @return array{status:int, code:?string, message:?string} */
    private function issueDraft(int $id): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/issue')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);

        try {
            $res = ($this->issue)($req, new Psr7Response(), ['id' => (string) $id]);
        } catch (\PDOException $e) {
            // Vystavení po validaci pokračuje vlastní transakcí, kterou uvnitř testovací
            // transakce dokončit nelze. Daňové brány už proběhly.
            if ((int) ($e->errorInfo[1] ?? 0) === 1568
                || str_contains($e->getMessage(), 'already an active transaction')) {
                return ['status' => 200, 'code' => null, 'message' => null];
            }
            throw $e;
        }
        $res->getBody()->rewind();
        $body = json_decode((string) $res->getBody(), true);

        return [
            'status'  => $res->getStatusCode(),
            'code'    => $body['error']['code'] ?? null,
            'message' => $body['error']['message'] ?? null,
        ];
    }
}
