<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\CardPaymentOverviewAction;
use MyInvoice\Action\Bank\PaymentCardAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PaymentCardRepository;
use MyInvoice\Service\Bank\Card\CardPaymentOverview;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Platební karty jsou per firma: cizí bankovní účet, cizí karta ani cizí platba
 * kartou se nesmí dostat do odpovědi jiné firmy. A celé číslo karty se neuloží
 * žádnou cestou.
 */
#[Group('integration')]
final class PaymentCardTenantScopeTest extends TestCase
{
    private const LABEL = '__cardtest2093__';
    private const MARKER = '__cardscope2093__';

    private Connection $db;
    private PaymentCardAction $action;
    private CardPaymentOverviewAction $overviewAction;
    private PaymentCardRepository $cards;
    private CardPaymentOverview $overview;
    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $currencyA = 0;
    private int $currencyB = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->action = $c->get(PaymentCardAction::class);
            $this->overviewAction = $c->get(CardPaymentOverviewAction::class);
            $this->cards = $c->get(PaymentCardRepository::class);
            $this->overview = $c->get(CardPaymentOverview::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $cur = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $this->markTestSkipped('Chybí CZK currency s account_number.');
        }
        $this->currencyA = (int) $cur['id'];
        $this->supplierA = (int) $cur['supplier_id'];
        $this->account = (string) $cur['account_number'];
        $this->bankCode = $cur['bank_code'] !== null ? (string) $cur['bank_code'] : null;
        $b = $pdo->prepare("SELECT c.supplier_id, c.id FROM currencies c WHERE c.supplier_id <> ? AND c.code = 'CZK' ORDER BY c.supplier_id LIMIT 1");
        $b->execute([$this->supplierA]);
        $rowB = $b->fetch(PDO::FETCH_ASSOC);
        if (!$rowB) {
            $this->markTestSkipped('Chybí druhá firma s CZK měnou.');
        }
        $this->supplierB = (int) $rowB['supplier_id'];
        $this->currencyB = (int) $rowB['id'];
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testForeignBankAccountIsRejected(): void
    {
        [$status, $body] = $this->call(fn ($req, $res) => $this->action->create($req, $res), $this->supplierA, [
            'label' => self::LABEL . ' A', 'last4' => '1111', 'currency_id' => $this->currencyB,
        ]);

        self::assertSame(422, $status);
        self::assertArrayHasKey('currency_id', $body['error']['errors'] ?? []);
        self::assertSame(0, $this->countCards());
    }

    public function testUnknownHolderReferencesAreRejected(): void
    {
        [$status, $body] = $this->call(fn ($req, $res) => $this->action->create($req, $res), $this->supplierA, [
            'label' => self::LABEL . ' B', 'last4' => '1111', 'employee_id' => 999999999, 'user_id' => 999999999,
        ]);

        self::assertSame(422, $status);
        self::assertArrayHasKey('employee_id', $body['error']['errors'] ?? []);
        self::assertArrayHasKey('user_id', $body['error']['errors'] ?? []);
    }

    public function testCardOfOtherCompanyIsInvisible(): void
    {
        [$status, $body] = $this->call(fn ($req, $res) => $this->action->create($req, $res), $this->supplierA, [
            'label' => self::LABEL . ' C', 'last4' => '2468', 'currency_id' => $this->currencyA, 'valid_from' => '2093-01-01',
        ]);
        self::assertSame(201, $status);
        $id = (int) $body['card']['id'];

        [$foreignStatus] = $this->call(fn ($req, $res) => $this->action->get($req, $res, ['id' => $id]), $this->supplierB);
        self::assertSame(404, $foreignStatus);
        [$archiveStatus] = $this->call(fn ($req, $res) => $this->action->archive($req, $res, ['id' => $id]), $this->supplierB);
        self::assertSame(404, $archiveStatus);

        self::assertNull($this->cards->findByLast4OnDate($this->supplierB, '2468', '2093-06-15'));
        self::assertSame($id, $this->cards->findByLast4OnDate($this->supplierA, '2468', '2093-06-15')['id'] ?? null);
        self::assertNotContains($id, array_column($this->cards->listForSupplier($this->supplierB, true), 'id'));
    }

    public function testFullCardNumberIsNeverStored(): void
    {
        [$status, $body] = $this->call(fn ($req, $res) => $this->action->create($req, $res), $this->supplierA, [
            'label' => self::LABEL . ' D', 'last4' => '4111 1111 1111 1111',
        ]);

        self::assertSame(201, $status);
        self::assertTrue($body['last4_truncated']);
        $stored = $this->db->pdo()->prepare('SELECT last4 FROM payment_cards WHERE id = ?');
        $stored->execute([(int) $body['card']['id']]);
        self::assertSame('1111', $stored->fetchColumn());

        // Ani přímý zápis do databáze neprojde — CHECK hlídá přesně čtyři číslice.
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            "INSERT INTO payment_cards (supplier_id, label, last4) VALUES (?, ?, 'x1x1')"
        )->execute([$this->supplierA, self::LABEL . ' raw']);
    }

    public function testOverlappingCardWithSameLast4IsRejected(): void
    {
        $create = fn (array $body) => $this->call(fn ($req, $res) => $this->action->create($req, $res), $this->supplierA, $body);

        self::assertSame(201, $create(['label' => self::LABEL . ' E1', 'last4' => '5555', 'valid_from' => '2093-01-01', 'valid_to' => '2093-12-31'])[0]);
        self::assertSame(409, $create(['label' => self::LABEL . ' E2', 'last4' => '5555', 'valid_from' => '2093-06-01'])[0]);
        self::assertSame(201, $create(['label' => self::LABEL . ' E3', 'last4' => '5555', 'valid_from' => '2094-01-01'])[0]);
        // Stejná koncovka u JINÉ firmy není konflikt.
        self::assertSame(201, $this->call(fn ($req, $res) => $this->action->create($req, $res), $this->supplierB, [
            'label' => self::LABEL . ' E4', 'last4' => '5555', 'valid_from' => '2093-06-01',
        ])[0]);
    }

    public function testArchivedCardStillOwnsItsHistoricalPayments(): void
    {
        [, $body] = $this->call(fn ($req, $res) => $this->action->create($req, $res), $this->supplierA, [
            'label' => self::LABEL . ' F', 'last4' => '9753', 'valid_from' => '2020-01-01',
        ]);
        $id = (int) $body['card']['id'];

        [$status, $archived] = $this->call(fn ($req, $res) => $this->action->archive($req, $res, ['id' => $id]), $this->supplierA);

        self::assertSame(200, $status);
        self::assertTrue($archived['card']['archived']);
        self::assertSame(date('Y-m-d'), $archived['card']['valid_to']);
        self::assertSame($id, $this->cards->findByLast4OnDate($this->supplierA, '9753', '2021-06-15')['id'] ?? null);
        self::assertNull($this->cards->findByLast4OnDate($this->supplierA, '9753', date('Y-m-d', strtotime('+1 day'))));
    }

    public function testCardPaymentOverviewIsTenantScoped(): void
    {
        $statement = $this->seedStatement();
        $this->db->pdo()->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, card_last4, description)
             VALUES (?, '2093-06-15', -99.00, 'CZK', '7777', 'PK: 000000******7777')"
        )->execute([$statement]);
        $txId = (int) $this->db->pdo()->lastInsertId();

        $own = $this->overview->unmatched($this->supplierA, '2093-06-01', '2093-06-30');
        $ownIds = array_merge(...array_map(static fn ($g) => array_column($g['transactions'], 'id'), $own['groups'] ?: [['transactions' => []]]));
        self::assertContains($txId, $ownIds);

        $foreign = $this->overview->unmatched($this->supplierB, '2093-06-01', '2093-06-30');
        self::assertSame(0, $foreign['count']);
        self::assertNull($this->overview->findCardTransaction($this->supplierB, $txId));

        [$status] = $this->call(fn ($req, $res) => $this->overviewAction->rematch($req, $res, ['id' => $txId]), $this->supplierB);
        self::assertSame(404, $status);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0:int, 1:array<string,mixed>} */
    private function call(callable $fn, int $supplierId, array $body = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payment-cards')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        /** @var ServerRequestInterface $request */
        /** @var ResponseInterface $response */
        $response = $fn($request, (new ResponseFactory())->createResponse());
        return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
    }

    private function countCards(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payment_cards WHERE label LIKE ?');
        $stmt->execute([self::LABEL . '%']);
        return (int) $stmt->fetchColumn();
    }

    private function seedStatement(): int
    {
        $name = self::MARKER . '.gpc';
        $this->db->pdo()->prepare(
            "INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, ?, 'CZK', '2093-06-30')"
        )->execute([$this->supplierA, $name, hash('sha256', $name), $this->account, $this->bankCode]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM payment_cards WHERE label LIKE ?')->execute([self::LABEL . '%']);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')->execute(['%' . self::MARKER . '%']);
    }
}
