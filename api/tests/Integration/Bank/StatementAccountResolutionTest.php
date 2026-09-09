<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/**
 * Určení cílového bankovního účtu při GPC/ABO uploadu (#206).
 *
 * GPC hlavička 074 nese jen ČÍSLO účtu (kód banky vlastního účtu chybí; kód v 075
 * je banka protistrany). Když má dodavatel víc účtů se STEJNÝM číslem účtu, které
 * se liší jen kódem banky (např. Fio 1234567890/2010 a RB 1234567890/5500), nesmí
 * se výpis tiše přiřadit k prvnímu (typicky výchozímu) účtu — import musí skončit
 * 409 `ambiguous_account_currency` a vyžádat si ruční výběr. Po předání `account_id`
 * proběhne import pod zvoleným účtem.
 *
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class StatementAccountResolutionTest extends TestCase
{
    private Connection $db;
    private BankStatementAction $action;
    private int $supplierId = 0;
    private int $userId = 0;

    /** @var int[] */
    private array $currencyIds = [];
    /** @var int[] */
    private array $statementIds = [];
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container nedostupný.');
            }
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(BankStatementAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $this->userId = (int) ($this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($this->statementIds as $id) {
            $pdo->prepare('DELETE FROM bank_api_evidence_months WHERE evidence_statement_id = ? OR monthly_statement_id = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM bank_api_months WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_transaction_imports WHERE original_statement_id = ? OR statement_id = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
        }
        foreach ($this->currencyIds as $id) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        }
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) @unlink($f);
        }
        $this->db->close();
    }

    /**
     * #206: stejné číslo účtu u dvou bank (obojí CZK) → bez volby MUSÍ vrátit 409
     * a nabídnout kandidáty, ne tiše importovat pod první/výchozí variantou.
     */
    public function testSameAccountNumberDifferentBankRequiresChoice(): void
    {
        $account = '9912345678';
        // Výchozí RB účet je založen jako první → dřív by lookup vzal tuhle variantu.
        $this->registerCurrency('CZK', $account, '5500', isDefault: true);
        $this->registerCurrency('CZK', $account, '2010');

        [$resp, $body] = $this->upload($this->gpc($account));

        $this->assertSame(409, $resp->getStatusCode(), 'dvě banky se stejným číslem účtu musí být nejednoznačné');
        $this->assertSame('ambiguous_account_currency', $body['error']['code'] ?? null);
        $candidates = $body['error']['candidates'] ?? [];
        $this->assertCount(2, $candidates, 'oba účty musí být nabídnuty jako kandidáti');

        // Labely musí být odlišitelné — obsahují kód banky (obě varianty jsou CZK).
        $labels = array_map(static fn ($c) => (string) $c['label'], $candidates);
        $this->assertTrue(
            (bool) array_filter($labels, static fn ($l) => str_contains($l, '2010'))
            && (bool) array_filter($labels, static fn ($l) => str_contains($l, '5500')),
            'labely kandidátů musí rozlišit banky (2010 vs 5500), ne jen měnu'
        );

        // Žádný výpis se nesmí uložit.
        $this->assertSame(0, $this->countStatements($account), 'nejednoznačný upload nesmí nic importovat');
    }

    /**
     * S předaným `account_id` (volba Fio /2010) proběhne import pod zvoleným účtem
     * — statement.bank_code = 2010, ne výchozí 5500.
     */
    public function testExplicitAccountIdImportsUnderChosenBank(): void
    {
        $account = '9912345679';
        $this->registerCurrency('CZK', $account, '5500', isDefault: true);
        $fioId = $this->registerCurrency('CZK', $account, '2010');

        [$resp, $body] = $this->upload($this->gpc($account), accountId: $fioId);

        $this->assertSame(200, $resp->getStatusCode(), 's volbou účtu musí import projít: ' . json_encode($body));
        $sid = (int) ($body['statement_id'] ?? 0);
        $this->assertGreaterThan(0, $sid);
        $this->statementIds[] = $sid;

        $stmt = $this->db->pdo()->prepare('SELECT bank_code FROM bank_statements WHERE id = ?');
        $stmt->execute([$sid]);
        $this->assertSame('2010', (string) $stmt->fetchColumn(), 'výpis musí být pod zvoleným Fio účtem (2010), ne výchozím RB (5500)');

        $this->db->pdo()->prepare(
            'INSERT INTO bank_statements
                (source, file_name, file_hash, account_number, bank_code, currency,
                 statement_date, curr_balance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'pdf', 'TEST-206-filter-rb.pdf', hash('sha256', 'test-206-filter-rb'),
            $account, '5500', 'CZK', '2026-06-30', 2000.00,
        ]);
        $rbStatementId = (int) $this->db->pdo()->lastInsertId();
        $this->statementIds[] = $rbStatementId;

        // Regrese seznamu výpisů: list dříve přepsal správné bs.bank_code prvním
        // currencies.bank_code pro stejné číslo účtu (bez ORDER BY), takže zobrazil
        // např. /5500 společně s labelem Fio. Detail přitom správně ukazoval /2010.
        $listResponse = $this->action->list(
            $this->mockRequest($this->supplierId, 'admin', [], [], [
                'filter' => ['account' => $account, 'bank_code' => '2010'],
            ]),
            new Response()
        );
        /** @var array{items?:list<array<string,mixed>>} $listBody */
        $listBody = json_decode((string) $listResponse->getBody(), true) ?: [];
        $listed = array_values(array_filter(
            $listBody['items'] ?? [],
            static fn (array $item): bool => (int) ($item['id'] ?? 0) === $sid
        ));
        $this->assertCount(1, $listed, 'importovaný výpis musí být v seznamu');
        $this->assertSame('2010', (string) ($listed[0]['bank_code'] ?? ''), 'seznam musí respektovat autoritativní bank_code výpisu');
        $this->assertStringContainsString('2010', (string) ($listed[0]['account_label'] ?? ''), 'label seznamu musí patřit ke stejnému účtu');
        $listedIds = array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $listBody['items'] ?? []);
        $this->assertNotContains($rbStatementId, $listedIds, 'filtr Fio /2010 nesmí vrátit RB /5500 se stejným číslem účtu');
    }

    /**
     * Jediný odpovídající účet → import proběhne automaticky (regrese #167/#206 nesmí
     * rozbít běžný jednoúčtový případ).
     */
    public function testSingleMatchingAccountImportsAutomatically(): void
    {
        $account = '9912345680';
        $this->registerCurrency('CZK', $account, '2010');

        [$resp, $body] = $this->upload($this->gpc($account));

        $this->assertSame(200, $resp->getStatusCode(), 'jediný účet musí projít bez volby: ' . json_encode($body));
        $sid = (int) ($body['statement_id'] ?? 0);
        $this->assertGreaterThan(0, $sid);
        $this->statementIds[] = $sid;
    }

    public function testGpcReconciliationRequiresExplicitConfirmationAndRetriesSameUpload(): void
    {
        $account = '1000000005';
        $currencyId = $this->registerCurrency('CZK', $account, '0100');
        $apiStatementId = $this->insertStatement('bank_api', $account, '0100', '2026-03-31', 1337.00, 'reconciliation-api');
        $this->db->pdo()->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id = ?')
            ->execute([$this->supplierId, $apiStatementId]);
        $this->db->pdo()->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description, bank_ref)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$apiStatementId, '2026-03-12', 925.18, 'CZK', 'PRICHOZI TEST', 'RLZ-0000000001']);
        $apiTransactionId = (int) $this->db->pdo()->lastInsertId();

        $content = $this->gpc($account);
        [$conflictResponse, $conflictBody] = $this->upload($content, accountId: $currencyId);

        $this->assertSame(409, $conflictResponse->getStatusCode());
        $this->assertSame('statement_reconciliation_required', $conflictBody['error']['code'] ?? null);
        $candidates = $conflictBody['error']['reconciliation_candidates'] ?? [];
        $this->assertCount(1, $candidates);
        $this->assertSame([
            'confirmation_key',
            'posted_at',
            'amount',
            'currency',
            'existing_transaction_id',
            'existing_statement_id',
            'description',
            'existing_description',
            'counterparty_account',
            'existing_counterparty_account',
            'variable_symbol',
            'existing_variable_symbol',
        ], array_keys($candidates[0]));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $candidates[0]['confirmation_key']);
        $this->assertSame($apiTransactionId, $candidates[0]['existing_transaction_id']);
        $this->assertSame($apiStatementId, $candidates[0]['existing_statement_id']);
        $this->assertSame(0, $this->countStatementsBySource($account, 'gpc'), 'konfliktní pokus nesmí uložit GPC výpis');

        [$confirmedResponse, $confirmedBody] = $this->upload(
            $content,
            accountId: $currencyId,
            reconciliationConfirmations: json_encode([$candidates[0]['confirmation_key']], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(200, $confirmedResponse->getStatusCode(), json_encode($confirmedBody));
        $gpcStatementId = (int) ($confirmedBody['statement_id'] ?? 0);
        $this->assertGreaterThan(0, $gpcStatementId);
        $this->statementIds[] = $gpcStatementId;
        $this->statementIds[] = (int) $confirmedBody['evidence_statement_id'];
        $this->assertSame(0, $confirmedBody['transactions'] ?? null);
        $this->assertSame(1, $confirmedBody['skipped_duplicates'] ?? null);
        $alias = $this->db->pdo()->prepare(
            'SELECT bank_transaction_id FROM bank_transaction_imports WHERE statement_id = ?'
        );
        $alias->execute([$gpcStatementId]);
        $this->assertSame($apiTransactionId, (int) $alias->fetchColumn());
    }

    public function testGpcReconciliationConfirmationsRejectMalformedMultipartJson(): void
    {
        $account = '1000000005';
        $invalidValues = [
            'not-json',
            json_encode([str_repeat('a', 63)], JSON_THROW_ON_ERROR),
            json_encode([str_repeat('b', 64), str_repeat('b', 64)], JSON_THROW_ON_ERROR),
            str_repeat(' ', 40001),
        ];

        foreach ($invalidValues as $raw) {
            [$response, $body] = $this->upload($this->gpc($account), reconciliationConfirmations: $raw);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame('validation_failed', $body['error']['code'] ?? null);
        }
        $this->assertSame(0, $this->countStatements($account));
    }

    /**
     * Stavy na účtech musí rozlišit stejné číslo u různých bank a zahrnout jak GPC,
     * tak bankovní PDF. Starý výpis bez bank_code je při dvou bankách nejednoznačný
     * a nesmí se započítat do obou účtů.
     */
    public function testAccountBalancesSeparateBanksAndIncludePdfStatements(): void
    {
        $account = '9912345681';
        $fioId = $this->registerCurrency('CZK', $account, '2010');
        $rbId = $this->registerCurrency('CZK', $account, '5500');

        $this->insertStatement('gpc', $account, '2010', '2026-06-30', 1000.00, 'fio');
        $this->insertStatement('pdf', $account, '5500', '2026-06-30', 2000.00, 'rb');
        $this->insertStatement('gpc', $account, null, '2026-07-31', 9999.00, 'legacy-ambiguous');

        $response = $this->action->accountBalances(
            $this->mockRequest($this->supplierId, 'admin', [], []),
            new Response()
        );
        /** @var array<string,mixed> $body */
        $body = json_decode((string) $response->getBody(), true) ?: [];

        $this->assertSame(200, $response->getStatusCode());
        $accounts = [];
        foreach ($body['accounts'] as $item) {
            $accounts[(int) $item['id']] = $item;
        }
        $this->assertArrayHasKey($fioId, $accounts, 'GPC Fio musí mít vlastní řádek');
        $this->assertArrayHasKey($rbId, $accounts, 'PDF RB musí mít vlastní řádek');
        $this->assertSame(1000.0, (float) $accounts[$fioId]['current_balance']);
        $this->assertSame('2010', (string) $accounts[$fioId]['bank_code']);
        $this->assertSame('gpc', (string) $accounts[$fioId]['current_source']);
        $this->assertSame(2000.0, (float) $accounts[$rbId]['current_balance']);
        $this->assertSame('5500', (string) $accounts[$rbId]['bank_code']);
        $this->assertSame('pdf', (string) $accounts[$rbId]['current_source']);
        $series = [];
        foreach ($body['total_czk']['series'] ?? [] as $item) {
            $series[(int) $item['account_id']] = $item;
        }
        $this->assertArrayHasKey($fioId, $series, 'CZK graf musí obsahovat sérii Fio');
        $this->assertArrayHasKey($rbId, $series, 'CZK graf musí obsahovat sérii RB');
        $fioMonths = array_column($series[$fioId]['months'], 'balance_czk', 'month');
        $rbMonths = array_column($series[$rbId]['months'], 'balance_czk', 'month');
        $this->assertSame(1000.0, (float) ($fioMonths['2026-06'] ?? 0));
        $this->assertSame(2000.0, (float) ($rbMonths['2026-06'] ?? 0));
    }

    public function testAccountBalancesAndListUseCalculatedApiClosing(): void
    {
        $account = '1000000005';
        $currencyId = $this->registerCurrency('CZK', $account, '2250');
        $this->insertStatement('gpc', $account, '2250', '2099-06-30', 100.0, 'balance-anchor');
        $apiId = $this->insertStatement('bank_api', $account, '2250', '2099-07-15', 0.0, 'balance-api');
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE bank_statements SET curr_balance = NULL WHERE id = ?')->execute([$apiId]);
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, '2099-07-15', 25, 'CZK')")->execute([$apiId]);
        $request = $this->mockRequest($this->supplierId, 'admin', [], [], ['filter' => ['year' => 2099, 'account' => $account]]);
        $response = $this->action->accountBalances($request, new Response());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $accounts = array_column($body['accounts'], null, 'id');
        self::assertArrayHasKey($currencyId, $accounts);
        self::assertSame(125.0, (float) $accounts[$currencyId]['current_balance']);
        self::assertSame('2099-07-15', $accounts[$currencyId]['statement_date']);
        self::assertSame('bank_api', $accounts[$currencyId]['current_source']);
        $months = array_column($accounts[$currencyId]['months'], 'balance', 'month');
        self::assertSame(125.0, (float) $months['2099-07']);
        $series = array_column($body['total_czk']['series'], null, 'account_id');
        $czkMonths = array_column($series[$currencyId]['months'], 'balance_czk', 'month');
        self::assertSame(125.0, (float) $czkMonths['2099-07']);
        $list = json_decode((string) $this->action->list($request, new Response())->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $items = array_column($list['items'], null, 'id');
        self::assertArrayHasKey($apiId, $items);
        self::assertSame('calculated', $items[$apiId]['balance_calculation']['status']);
        self::assertSame(125.0, (float) $items[$apiId]['balance_calculation']['closing']);
        self::assertArrayNotHasKey('transactions', $items[$apiId]['balance_calculation']);
    }

    public function testApiWithoutInitialBalanceUsesMovementsInListAndAccountBalances(): void
    {
        $account = '1000000005';
        $currencyId = $this->registerCurrency('CZK', $account, '2250');
        $id = $this->insertStatement('bank_api', $account, '2250', '2099-07-15', 0.0, 'zero-start-api');
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE bank_statements SET curr_balance = NULL WHERE id = ?')->execute([$id]);
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, '2099-07-15', 25, 'CZK')")->execute([$id]);
        $request = $this->mockRequest($this->supplierId, 'admin', [], [], ['filter' => ['year' => 2099, 'account' => $account]]);
        $balances = json_decode((string) $this->action->accountBalances($request, new Response())->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $accounts = array_column($balances['accounts'], null, 'id');
        self::assertSame(25.0, (float) $accounts[$currencyId]['current_balance']);
        $list = json_decode((string) $this->action->list($request, new Response())->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $items = array_column($list['items'], null, 'id');
        self::assertSame('calculated', $items[$id]['balance_calculation']['status']);
        self::assertSame(0.0, (float) $items[$id]['balance_calculation']['opening']);
        self::assertSame(25.0, (float) $items[$id]['balance_calculation']['closing']);
        self::assertNull($pdo->query('SELECT curr_balance FROM bank_statements WHERE id = ' . $id)->fetchColumn());
    }

    public function testApiBalanceIsAuthoritativeOnlyWhenProvided(): void
    {
        $account = '1000000005';
        $currencyId = $this->registerCurrency('CZK', $account, '2250');
        $this->insertStatement('bank_api', $account, '2250', '2099-06-30', 100.0, 'api-known');
        $unknown = $this->insertStatement('bank_api', $account, '2250', '2099-07-31', 0.0, 'api-unknown');
        $this->db->pdo()->prepare('UPDATE bank_statements SET curr_balance=NULL WHERE id=?')->execute([$unknown]);
        $response = $this->action->accountBalances($this->mockRequest($this->supplierId, 'admin', [], []), new Response());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $accounts = array_column($body['accounts'], null, 'id');
        self::assertArrayHasKey($currencyId, $accounts);
        self::assertSame(100.0, (float) $accounts[$currencyId]['current_balance']);
        self::assertSame('bank_api', $accounts[$currencyId]['current_source']);
        self::assertSame('2099-06-30', $accounts[$currencyId]['statement_date']);
    }

    public function testReportedApiBalanceWinsOverSameDateCalculation(): void
    {
        $account = '1000000005';
        $currencyId = $this->registerCurrency('CZK', $account, '2250');
        $this->insertStatement('gpc', $account, '2250', '2099-06-30', 100.0, 'reported-anchor');
        $this->insertStatement('bank_api', $account, '2250', '2099-07-15', 200.0, 'reported-api');
        $calculated = $this->insertStatement('bank_api', $account, '2250', '2099-07-15', 0.0, 'reported-projection');
        $this->db->pdo()->prepare('UPDATE bank_statements SET curr_balance = NULL WHERE id = ?')->execute([$calculated]);
        $response = $this->action->accountBalances($this->mockRequest($this->supplierId, 'admin', [], []), new Response());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $accounts = array_column($body['accounts'], null, 'id');
        self::assertSame(200.0, (float) $accounts[$currencyId]['current_balance']);
        $months = array_column($accounts[$currencyId]['months'], 'balance', 'month');
        self::assertSame(200.0, (float) $months['2099-07']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    public function testLinkedApiMovementAppearsInGpcListFiltersAndSuggestions(): void
    {
        $account = '1000000005';
        $this->registerCurrency('CZK', $account, '2250');
        $apiId = $this->insertStatement('bank_api', $account, '2250', '2099-07-31', 100.0, 'alias-api');
        $gpcId = $this->insertStatement('gpc', $account, '2250', '2099-07-31', 100.0, 'alias-gpc');
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id IN (?, ?)')->execute([$this->supplierId, $apiId, $gpcId]);
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, '2099-07-15', 100, 'CZK')")->execute([$apiId]);
        $txId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO bank_transaction_imports (statement_id, bank_transaction_id, import_fingerprint, supplier_id, original_statement_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$gpcId, $txId, hash('sha256', 'synthetic-list-alias'), $this->supplierId, $apiId]);
        $request = $this->mockRequest($this->supplierId, 'admin', [], [], ['filter' => ['year' => 2099, 'month' => 7, 'amount' => 100]]);
        $response = $this->action->list($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $rows = array_column($body['items'], null, 'id');
        self::assertArrayHasKey($gpcId, $rows);
        self::assertSame(1, $rows[$gpcId]['transaction_count']);
        self::assertSame(0, $rows[$gpcId]['matched_count']);
        self::assertSame(1, $rows[$gpcId]['unposted_count']);
        $pdo->prepare("INSERT INTO bank_match_suggestions (supplier_id, bank_transaction_id, kind, reason, candidates_json, top_score) VALUES (?, ?, 'single', 'no_vs', '[]', 0)")->execute([$this->supplierId, $txId]);
        $suggestions = Bootstrap::buildContainer()->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class)->listForStatement($gpcId, $this->supplierId);
        self::assertSame([$txId], array_column($suggestions, 'bank_transaction_id'));

        $before = $pdo->query('SELECT * FROM bank_transactions WHERE id = ' . $txId)->fetch(PDO::FETCH_ASSOC);
        $pdo->beginTransaction();
        $monthly = new \MyInvoice\Service\Bank\BankApiMonthlyStatements($pdo);
        $mapping = $monthly->projectAccount($this->supplierId, $account, '2250', 'CZK');
        $pdo->commit();
        $monthId = $mapping[$apiId][0];
        $this->statementIds[] = $monthId;
        $response = $this->action->list($request, new Response());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $rows = array_column($body['items'], null, 'id');
        self::assertArrayNotHasKey($apiId, $rows);
        self::assertArrayHasKey($monthId, $rows);
        self::assertArrayNotHasKey($gpcId, $rows);
        self::assertSame('gpc', $rows[$monthId]['source']);
        self::assertSame(1, $rows[$monthId]['transaction_count']);
        self::assertSame(1, $rows[$monthId]['unposted_count']);
        self::assertSame($before, $pdo->query('SELECT * FROM bank_transactions WHERE id = ' . $txId)->fetch(PDO::FETCH_ASSOC));
        $pdo->beginTransaction();
        self::assertSame([], $monthly->projectAccount($this->supplierId, $account, '2250', 'CZK'));
        self::assertSame([$monthId], $monthly->monthIds($apiId, $this->supplierId));
        $pdo->commit();
        $pdo->prepare('UPDATE bank_statements SET statement_number = ? WHERE id = ?')->execute(['099', $gpcId]);
        $attachment = new \ReflectionMethod(BankStatementAction::class, 'findStatementForPdfAttachment');
        self::assertSame($monthId, $attachment->invoke($this->action, $this->supplierId, [
            'header' => ['statement_date' => '2099-07-31', 'statement_number' => '042'],
            'transactions' => [['posted_at' => '2099-07-15', 'amount' => 100]],
        ], null, $account));
        $deleted = $this->action->delete($request, new Response(), ['id' => $monthId]);
        self::assertSame(409, $deleted->getStatusCode());
        self::assertSame('monthly_api_statement', json_decode((string) $deleted->getBody(), true)['error']['code']);
    }

    public static function pdfEvidenceCases(): array
    {
        return [['gpc', false], ['bank_api', false], ['gpc', true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pdfEvidenceCases')]
    public function testCompleteEvidencePdfRemainsAccessibleOnMonthlyStatement(string $source, bool $partial): void
    {
        $account = '1000000005';
        $this->registerCurrency('CZK', $account, '2250');
        $apiId = $this->insertStatement('bank_api', $account, '2250', '2099-08-31', 100.0, 'pdf-api');
        $gpcId = $this->insertStatement($source, $account, '2250', '2099-08-31', 100.0, 'pdf-gpc');
        $pdo = $this->db->pdo();
        $pdf = '%PDF-1.4 synthetic monthly document';
        $hash = hash('sha256', $pdf);
        $pdo->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id IN (?, ?)')->execute([$this->supplierId, $apiId, $gpcId]);
        $pdo->prepare('UPDATE bank_statements SET pdf_content = ?, pdf_name = ?, pdf_hash = ?, pdf_size_bytes = ?, pdf_uploaded_at = NOW() WHERE id = ?')->execute([$pdf, 'synthetic.pdf', $hash, strlen($pdf), $gpcId]);
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, '2099-08-15', 100, 'CZK')")->execute([$apiId]);
        $txId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO bank_transaction_imports (statement_id, bank_transaction_id, import_fingerprint, supplier_id, original_statement_id) VALUES (?, ?, ?, ?, ?)')->execute([$gpcId, $txId, hash('sha256', 'synthetic-pdf-alias'), $this->supplierId, $apiId]);
        if ($partial) $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, '2099-08-16', 200, 'CZK')")->execute([$apiId]);
        $pdo->beginTransaction();
        $mapping = (new \MyInvoice\Service\Bank\BankApiMonthlyStatements($pdo))->projectAccount($this->supplierId, $account, '2250', 'CZK');
        $pdo->commit();
        $monthId = $mapping[$apiId][0];
        $this->statementIds[] = $monthId;
        $row = $pdo->query('SELECT pdf_content, pdf_name, pdf_hash, pdf_size_bytes FROM bank_statements WHERE id = ' . $monthId)->fetch(PDO::FETCH_ASSOC);
        if ($partial) {
            $monthly = new \MyInvoice\Service\Bank\BankApiMonthlyStatements($pdo);
            self::assertNull($row['pdf_content']);
            self::assertSame([['id' => $gpcId, 'pdf_name' => 'synthetic.pdf']], $monthly->evidencePdfs($monthId, $this->supplierId));
            self::assertSame([], $monthly->evidencePdfs($monthId, $this->supplierId + 1000000));
            $lookup = new \ReflectionMethod(BankStatementAction::class, 'statementWithPdfHash');
            self::assertSame($gpcId, $lookup->invoke($this->action, $hash, $this->supplierId));
            return;
        }
        self::assertSame($pdf, $row['pdf_content']);
        self::assertSame('synthetic.pdf', $row['pdf_name']);
        self::assertSame($hash, $row['pdf_hash']);
        self::assertSame(strlen($pdf), (int) $row['pdf_size_bytes']);
        $lookup = new \ReflectionMethod(BankStatementAction::class, 'statementWithPdfHash');
        self::assertSame($monthId, $lookup->invoke($this->action, $hash, $this->supplierId));
        $pdo->prepare('UPDATE bank_statements SET pdf_content = ? WHERE id = ?')->execute(['%PDF-1.4 manual attachment', $monthId]);
        $preserve = new \ReflectionMethod(\MyInvoice\Service\Bank\BankApiMonthlyStatements::class, 'preservePdf');
        $preserve->invoke(new \MyInvoice\Service\Bank\BankApiMonthlyStatements($pdo), $monthId, $this->supplierId);
        self::assertSame('%PDF-1.4 manual attachment', $pdo->query('SELECT pdf_content FROM bank_statements WHERE id = ' . $monthId)->fetchColumn());
    }

    private function registerCurrency(string $code, string $accountNumber, string $bankCode, bool $isDefault = false): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code, iban)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, ?, ?, ?, NULL)'
        )->execute([
            $this->supplierId, $code, "TEST {$code}/{$bankCode} #206", $code, $code, $code,
            $isDefault ? 1 : 0, $accountNumber, $bankCode,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->currencyIds[] = $id;
        return $id;
    }

    /**
     * Nahraje GPC obsah přes akci `upload` a vrátí [Response, decoded-body].
     *
     * @return array{0: Response, 1: array<string,mixed>}
     */
    private function upload(
        string $content,
        ?int $accountId = null,
        ?string $reconciliationConfirmations = null,
    ): array
    {
        $file = $this->uploadedFile($content);
        $parsedBody = $accountId !== null ? ['account_id' => $accountId] : [];
        if ($reconciliationConfirmations !== null) {
            $parsedBody['reconciliation_confirmations'] = $reconciliationConfirmations;
        }
        $req = $this->mockRequest($this->supplierId, 'admin', ['file' => $file], $parsedBody);
        $resp = $this->action->upload($req, new Response());
        /** @var array<string,mixed> $body */
        $body = json_decode((string) $resp->getBody(), true) ?: [];
        return [$resp, $body];
    }

    private function mockRequest(int $sid, string $role, array $files, array $parsedBody, array $queryParams = []): ServerRequestInterface
    {
        $req = $this->createStub(ServerRequestInterface::class);
        $req->method('getAttribute')->willReturnCallback(function (string $name, $default = null) use ($sid, $role) {
            if ($name === SupplierScopeMiddleware::ATTR_CURRENT_ID) return $sid;
            if ($name === AuthMiddleware::ATTR_USER) return ['id' => $this->userId, 'role' => $role];
            return $default;
        });
        $req->method('getUploadedFiles')->willReturn($files);
        $req->method('getParsedBody')->willReturn($parsedBody);
        $req->method('getQueryParams')->willReturn($queryParams);
        $req->method('getServerParams')->willReturn([]);
        $req->method('getHeaderLine')->willReturn('');
        return $req;
    }

    private function uploadedFile(string $content, string $name = 'vypis.gpc'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gpctest');
        file_put_contents($tmp, $content);
        $this->tmpFiles[] = $tmp;
        return new UploadedFile($tmp, $name, 'text/plain', strlen($content), UPLOAD_ERR_OK);
    }

    private function countStatements(string $account): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM bank_statements
              WHERE TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(account_number, ''), '[^0-9]', ''))
                  = TRIM(LEADING '0' FROM REGEXP_REPLACE(?, '[^0-9]', ''))"
        );
        $stmt->execute([$account]);
        $n = (int) $stmt->fetchColumn();
        // Uklidit případné (nemělo by nastat) osiřelé řádky, ať tearDown nenechá smetí.
        if ($n > 0) {
            $ids = $this->db->pdo()->prepare(
                "SELECT id FROM bank_statements
                  WHERE TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(account_number, ''), '[^0-9]', ''))
                      = TRIM(LEADING '0' FROM REGEXP_REPLACE(?, '[^0-9]', ''))"
            );
            $ids->execute([$account]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $this->statementIds[] = (int) $id;
            }
        }
        return $n;
    }

    private function countStatementsBySource(string $account, string $source): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM bank_statements
              WHERE source = ?
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(account_number, ''), '[^0-9]', ''))
                  = TRIM(LEADING '0' FROM REGEXP_REPLACE(?, '[^0-9]', ''))"
        );
        $stmt->execute([$source, $account]);
        return (int) $stmt->fetchColumn();
    }

    private function insertStatement(
        string $source,
        string $accountNumber,
        ?string $bankCode,
        string $date,
        float $balance,
        string $hashSuffix,
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO bank_statements
                (source, file_name, file_hash, account_number, bank_code, currency,
                 statement_date, curr_balance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $source,
            "TEST-206-{$hashSuffix}.{$source}",
            hash('sha256', "test-206-balances:{$hashSuffix}"),
            $accountNumber,
            $bankCode,
            'CZK',
            $date,
            $balance,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->statementIds[] = $id;
        return $id;
    }

    /**
     * Minimální validní GPC (074 header + 1× 075 transakce) pro dané číslo účtu.
     * Layout přesně dle {@see \MyInvoice\Service\Bank\GpcParser}.
     */
    private function gpc(string $account, string $stmtNo = '001'): string
    {
        $acc16 = str_pad($account, 16, '0', STR_PAD_LEFT);
        $header = '074' . $acc16
            . str_pad('TEST UCET 206', 20)
            . '010326'
            . str_pad('1337', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('133700', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('132363', 14, '0', STR_PAD_LEFT) . '+'
            . str_pad($stmtNo, 3, '0', STR_PAD_LEFT)
            . '310326'
            . 'FIO';

        $tx = '075' . $acc16
            . str_pad('', 16, '0')
            . str_pad('10000000001', 13, '0', STR_PAD_LEFT)
            . str_pad('92518', 12, '0', STR_PAD_LEFT)
            . '2'
            . str_pad('', 10, '0')
            . '00'
            . '0000'
            . '0000'
            . str_pad('', 10, '0')
            . '120326'
            . str_pad('PRICHOZI TEST', 20)
            . '00203'
            . '120326';

        return $header . "\r\n" . $tx . "\r\n";
    }
}
