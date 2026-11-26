<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Bank\StaleSuggestionSweep;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Párování banky přes API token (MCP, integrace) nesmí zasáhnout do účetního deníku.
 *
 * Deník je pro token jednosměrný ({@see \MyInvoice\Middleware\ApiScopeMiddleware}), ale
 * `POST /bank-transactions/{id}/match` po spárování volal automatiku zaúčtování a podle
 * politiky firmy rovnou vytvořil zápis. Token proto jen páruje; zaúčtování převezme
 * přepočet fronty ({@see StaleSuggestionSweep}) podle politiky firmy nebo uživatel
 * v aplikaci. Zaúčtovaný pohyb token nepřepáruje ani neodpáruje.
 */
#[Group('integration')]
final class BankMatchTokenPostingTest extends BankPostingTestCase
{
    private BankStatementAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(BankStatementAction::class);
    }

    public function testTokenMatchDefersPostingAndSweepPostsByCompanyPolicy(): void
    {
        [$sessionTx, $sessionInvoice] = $this->paidInvoiceScenario('TOKSES1', 1210.00);
        $session = $this->match($sessionTx, $sessionInvoice, false);
        self::assertSame(200, $session['status'], json_encode($session['body']));
        self::assertSame('posted', $session['body']['posting']['action'] ?? null, 'kontrola prostředí: relace po spárování účtuje');
        self::assertSame(1, $this->entryCountForTx($sessionTx));

        [$tokenTx, $tokenInvoice] = $this->paidInvoiceScenario('TOKAPI1', 1331.00);
        $token = $this->match($tokenTx, $tokenInvoice, true);
        self::assertSame(200, $token['status'], json_encode($token['body']));
        self::assertSame(['action' => 'deferred', 'reason' => 'api_token'], $token['body']['posting'] ?? null);
        self::assertSame(0, $this->entryCountForTx($tokenTx), 'token páruje, ale do deníku nezapisuje');
        self::assertSame('manual', $this->txStatus($tokenTx));

        $sweep = $this->container->get(StaleSuggestionSweep::class)->run(false, $this->supplierId);
        self::assertGreaterThanOrEqual(1, $sweep['posted'], json_encode($sweep));
        self::assertSame(1, $this->entryCountForTx($tokenTx), 'plánovaný přepočet zaúčtuje podle politiky firmy');
    }

    public function testTokenSplitsPayoutAcrossBuyersAndRetryReadsExistingAllocation(): void
    {
        $a = $this->saleInvoice('TOKTERM1', $this->client('První odběratel terminálu'), 336);
        $b = $this->saleInvoice('TOKTERM2', $this->client('Druhý odběratel terminálu'), 224);
        $this->postPredpis('invoice', $a, '311', '602', 336);
        $this->postPredpis('invoice', $b, '311', '602', 224);
        $day = self::YEAR . '-06-14';
        $tx = $this->transaction($this->statement(), 560, ['posted_at' => $day]);
        $body = ['payout_allocations' => [
            ['invoice_id' => $a, 'amount_cents' => 33600],
            ['invoice_id' => $b, 'amount_cents' => 22400],
        ]];

        $matched = $this->call('manualMatch', $tx, $body, true);
        self::assertSame(200, $matched['status'], json_encode($matched['body']));
        self::assertSame(['action' => 'deferred', 'reason' => 'api_token'], $matched['body']['posting']);
        self::assertSame(0, $this->entryCountForTx($tx));
        $payments = $this->db->pdo()->prepare('SELECT invoice_id, amount, paid_on, source FROM invoice_payments WHERE bank_transaction_id = ? ORDER BY id');
        $payments->execute([$tx]);
        $rows = $payments->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([$a, $b], array_map('intval', array_column($rows, 'invoice_id')));
        self::assertSame([336.0, 224.0], array_map('floatval', array_column($rows, 'amount')));
        self::assertSame([$day, $day], array_column($rows, 'paid_on'));
        self::assertSame(['bank', 'bank'], array_column($rows, 'source'));

        self::assertTrue($this->call('manualMatch', $tx, $body, true)['body']['replayed']);
        $sweep = $this->container->get(StaleSuggestionSweep::class)->run(false, $this->supplierId);
        self::assertGreaterThanOrEqual(1, $sweep['posted'], json_encode($sweep));
        $again = $this->call('manualMatch', $tx, ['payout_allocations' => array_reverse($body['payout_allocations'])], true);
        self::assertSame(200, $again['status'], json_encode($again['body']));
        self::assertTrue($again['body']['replayed']);
        self::assertSame(['action' => 'posted'], $again['body']['posting']);
        self::assertSame(1, $this->entryCountForTx($tx));

        $conflict = $this->call('manualMatch', $tx, ['payout_allocations' => [
            ['invoice_id' => $a, 'amount_cents' => 33599],
            ['invoice_id' => $b, 'amount_cents' => 22401],
        ]], true);
        self::assertSame(409, $conflict['status']);
        self::assertSame('tx_already_paired', $conflict['body']['error']['code']);
        $payments->execute([$tx]);
        self::assertSame($rows, $payments->fetchAll(\PDO::FETCH_ASSOC));

        self::assertSame(200, $this->call('unmatch', $tx, [], false)['status']);
        self::assertSame('unmatched', $this->txStatus($tx));
        self::assertSame(0, $this->liveEntryCount($tx));
        $payments->execute([$tx]);
        self::assertSame([], $payments->fetchAll(\PDO::FETCH_ASSOC));
        $status = $this->db->pdo()->prepare('SELECT status FROM invoices WHERE id IN (?, ?) ORDER BY id');
        $status->execute([$a, $b]);
        self::assertSame(['issued', 'issued'], $status->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testPayoutRejectsWrongAllocationNonCzkAndUnavailableMovement(): void
    {
        $a = $this->saleInvoice('TOKTERM3', $this->client('Třetí odběratel terminálu'), 336);
        $b = $this->saleInvoice('TOKTERM4', $this->client('Čtvrtý odběratel terminálu'), 224);
        $statement = $this->statement();
        $tx = $this->transaction($statement, 560);
        $body = ['payout_allocations' => [
            ['invoice_id' => $a, 'amount_cents' => 33600],
            ['invoice_id' => $b, 'amount_cents' => 22400],
        ]];
        $call = fn (array $request, int $movement = 0, string $role = 'admin') => $this->callAction(
            $this->action, 'manualMatch', 'POST', $role, $request, ['id' => (string) ($movement ?: $tx)],
        );
        self::assertSame(403, $call($body, role: 'readonly')['status']);
        self::assertSame(422, $call($body + ['invoice_ids' => [$a, $b]])['status']);
        self::assertSame(422, $call(['payout_allocations' => [$body['payout_allocations'][0], $body['payout_allocations'][0]]])['status']);
        self::assertSame('sum_mismatch', $call(['payout_allocations' => [
            ['invoice_id' => $a, 'amount_cents' => 33600],
            ['invoice_id' => $b, 'amount_cents' => 22399],
        ]])['body']['error']['code']);
        $wrongRow = $call(['payout_allocations' => [
            ['invoice_id' => $a, 'amount_cents' => 33601],
            ['invoice_id' => $b, 'amount_cents' => 22399],
        ]]);
        self::assertSame(409, $wrongRow['status']);
        self::assertSame('payout_amount_mismatch', $wrongRow['body']['error']['code']);
        self::assertSame($a, $wrongRow['body']['error']['invoice_id']);
        $foreignCurrency = $this->transaction($statement, 560, ['currency' => 'EUR']);
        self::assertSame('invalid_payout_source', $call($body, $foreignCurrency)['body']['error']['code']);
        $ignored = $this->transaction($statement, 560, ['match_status' => 'ignored']);
        self::assertSame('payment_unavailable', $call($body, $ignored)['body']['error']['code']);

        $this->db->pdo()->prepare('UPDATE invoices SET supplier_id = ? WHERE id = ?')->execute([$this->otherSupplierId(), $b]);
        self::assertSame(404, $call($body)['status']);
        self::assertSame('unmatched', $this->txStatus($tx));
        $payments = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ?');
        $payments->execute([$tx]);
        self::assertSame(0, (int) $payments->fetchColumn());
    }

    public function testPayoutPaysLastDocumentPartiallyAndRejectsOverpayment(): void
    {
        $a = $this->saleInvoice('PARTPAY1', $this->client('Odběratel A částečné úhrady'), 1955.63);
        $b = $this->saleInvoice('PARTPAY2', $this->client('Odběratel B částečné úhrady'), 34011.77);
        $c = $this->saleInvoice('PARTPAY3', $this->client('Odběratel C částečné úhrady'), 500.00);
        $this->postPredpis('invoice', $a, '311', '602', 1955.63);
        $this->postPredpis('invoice', $b, '311', '602', 34011.77);
        $this->postPredpis('invoice', $c, '311', '602', 500.00);
        $statement = $this->statement();
        $tx = $this->transaction($statement, 29165.05);

        $matched = $this->call('manualMatch', $tx, ['payout_allocations' => [
            ['invoice_id' => $a, 'amount_cents' => 195563],
            ['invoice_id' => $b, 'amount_cents' => 2720942],
        ]], false);
        self::assertSame(200, $matched['status'], json_encode($matched['body']));
        self::assertSame('posted', $matched['body']['posting']['action'] ?? null, json_encode($matched['body']));

        $payments = $this->db->pdo()->prepare('SELECT invoice_id, amount, source FROM invoice_payments WHERE bank_transaction_id = ? ORDER BY id');
        $payments->execute([$tx]);
        $rows = $payments->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([$a, $b], array_map('intval', array_column($rows, 'invoice_id')));
        self::assertSame([1955.63, 27209.42], array_map('floatval', array_column($rows, 'amount')));
        self::assertSame(['paid', 1955.63], $this->invoiceState($a));
        self::assertSame(['issued', 27209.42], $this->invoiceState($b), 'částečně uhrazený doklad nesmí přejít do stavu uhrazeno');

        $entryId = (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId}
              AND source_type = 'bank' AND source_id = {$tx} AND reversed_by IS NULL"
        )->fetchColumn();
        $lines = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(29165.05, $lines['221']['debit'], 0.001);
        self::assertEqualsWithDelta(29165.05, $lines['311']['credit'], 0.001);

        $over = $this->transaction($statement, 7302.36);
        $rejected = $this->call('manualMatch', $over, ['payout_allocations' => [
            ['invoice_id' => $b, 'amount_cents' => 680236],
            ['invoice_id' => $c, 'amount_cents' => 50000],
        ]], false);
        self::assertSame(409, $rejected['status'], json_encode($rejected['body']));
        self::assertSame('payout_amount_mismatch', $rejected['body']['error']['code']);
        self::assertSame($b, $rejected['body']['error']['invoice_id']);
        $payments->execute([$over]);
        self::assertSame([], $payments->fetchAll(\PDO::FETCH_ASSOC));
        self::assertSame('unmatched', $this->txStatus($over));

        $rest = $this->transaction($statement, 7302.35);
        $settled = $this->call('manualMatch', $rest, ['payout_allocations' => [
            ['invoice_id' => $b, 'amount_cents' => 680235],
            ['invoice_id' => $c, 'amount_cents' => 50000],
        ]], false);
        self::assertSame(200, $settled['status'], json_encode($settled['body']));
        self::assertSame(['paid', 34011.77], $this->invoiceState($b));
        self::assertSame(['paid', 500.0], $this->invoiceState($c));
    }

    public function testPayoutRejectsPartialRestWithinSettlementTolerance(): void
    {
        $a = $this->saleInvoice('PARTTOL1', $this->client('Odběratel A tolerance'), 500.00);
        $b = $this->saleInvoice('PARTTOL2', $this->client('Odběratel B tolerance'), 100.00);
        $statement = $this->statement();
        foreach ([1, 5] as $restCents) {
            $tx = $this->transaction($statement, (60000 - $restCents) / 100);
            $rejected = $this->call('manualMatch', $tx, ['payout_allocations' => [
                ['invoice_id' => $b, 'amount_cents' => 10000],
                ['invoice_id' => $a, 'amount_cents' => 50000 - $restCents],
            ]], false);
            self::assertSame(409, $rejected['status'], json_encode($rejected['body']));
            self::assertSame('payout_amount_mismatch', $rejected['body']['error']['code']);
            self::assertSame($a, $rejected['body']['error']['invoice_id']);
            self::assertSame('unmatched', $this->txStatus($tx));
        }
        self::assertSame(['issued', 0.0], $this->invoiceState($a));
        self::assertSame(['issued', 0.0], $this->invoiceState($b));

        $tx = $this->transaction($statement, 599.94);
        $accepted = $this->call('manualMatch', $tx, ['payout_allocations' => [
            ['invoice_id' => $b, 'amount_cents' => 10000],
            ['invoice_id' => $a, 'amount_cents' => 49994],
        ]], false);
        self::assertSame(200, $accepted['status'], json_encode($accepted['body']));
        self::assertSame(['issued', 499.94], $this->invoiceState($a), 'zbytek 0,06 Kč nad tolerancí doklad neuzavře');
        self::assertSame(['paid', 100.0], $this->invoiceState($b));
    }

    public function testPayoutPaysProformaPartiallyWithoutFinalInvoice(): void
    {
        $proforma = $this->saleInvoice('PARTPRO1', $this->client('Odběratel zálohy'), 1000.00, 'proforma');
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                    vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Syntetická záloha', 1, 'ks', 1000, (SELECT id FROM vat_rates ORDER BY id LIMIT 1), 0, 1000, 0, 1000, 0)"
        )->execute([$proforma]);
        $invoice = $this->saleInvoice('PARTPRO2', $this->client('Odběratel faktury'), 200.00);
        $tx = $this->transaction($this->statement(), 700.00);

        $matched = $this->call('manualMatch', $tx, ['payout_allocations' => [
            ['invoice_id' => $invoice, 'amount_cents' => 20000],
            ['invoice_id' => $proforma, 'amount_cents' => 50000],
        ]], false);
        self::assertSame(200, $matched['status'], json_encode($matched['body']));
        self::assertArrayNotHasKey('final_draft_ids', $matched['body'], 'částečně uhrazená záloha nezakládá finální fakturu');
        self::assertSame(['issued', 500.0], $this->invoiceState($proforma));
        self::assertSame(['paid', 200.0], $this->invoiceState($invoice));
        $payments = $this->db->pdo()->prepare('SELECT invoice_id, amount FROM invoice_payments WHERE bank_transaction_id = ? ORDER BY id');
        $payments->execute([$tx]);
        self::assertSame([[$invoice, 200.0], [$proforma, 500.0]], array_map(
            static fn (array $row): array => [(int) $row['invoice_id'], (float) $row['amount']],
            $payments->fetchAll(\PDO::FETCH_ASSOC),
        ));
    }

    public function testPayoutRollsBackWhenPaymentWouldBeTrimmed(): void
    {
        $a = $this->saleInvoice('PARTTRIM1', $this->client('Odběratel A oříznutí'), 500.00);
        $b = $this->saleInvoice('PARTTRIM2', $this->client('Odběratel B oříznutí'), 100.00);
        // Platba bez přepočtu paid_total: kontrola pod zámkem vidí celý dluh, zápis platby jen zbytek 200 Kč.
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, 300.00, "CZK", "manual")'
        )->execute([$this->supplierId, $a, self::YEAR . '-06-12']);
        $tx = $this->transaction($this->statement(), 500.00);

        $result = $this->call('manualMatch', $tx, ['payout_allocations' => [
            ['invoice_id' => $b, 'amount_cents' => 10000],
            ['invoice_id' => $a, 'amount_cents' => 40000],
        ]], false);
        self::assertSame(409, $result['status'], json_encode($result['body']));
        self::assertSame('state_changed', $result['body']['error']['code']);
        self::assertSame($a, $result['body']['error']['invoice_id']);
        self::assertSame('unmatched', $this->txStatus($tx));
        $payments = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = ?');
        $payments->execute([$tx]);
        self::assertSame(0, (int) $payments->fetchColumn());
        self::assertSame(['issued', 0.0], $this->invoiceState($b), 'rollback vrátí i úhradu ostatních dokladů rozpadu');
    }

    /** @return array{0:string, 1:float} */
    private function invoiceState(int $invoiceId): array
    {
        $row = $this->db->pdo()->query("SELECT status, paid_total FROM invoices WHERE id = {$invoiceId}")->fetch(\PDO::FETCH_ASSOC);
        return [(string) $row['status'], (float) $row['paid_total']];
    }

    public function testTokenMatchSupersedesStalePendingSuggestion(): void
    {
        [$tx, $invoice] = $this->paidInvoiceScenario('TOKSUP1', 500.00);
        $this->db->pdo()->prepare(
            "INSERT INTO bank_posting_suggestions
                (supplier_id, bank_transaction_id, source, debit_account_code, credit_account_code, amount,
                 description, confidence, status, operation_type)
             VALUES (?, ?, 'rule', '221', '648', 500.00, 'Pravidlo', 0.90, 'pending', 'bank.rule')"
        )->execute([$this->supplierId, $tx]);
        $staleId = (int) $this->db->pdo()->lastInsertId();

        $token = $this->match($tx, $invoice, true);

        self::assertSame(200, $token['status'], json_encode($token['body']));
        self::assertSame('superseded', $this->suggestionRow($staleId)['status'], 'návrh z doby před spárováním nesmí jít schválit');
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testTokenCannotUnmatchPostedTransactionButCanUnmatchUnposted(): void
    {
        [$postedTx, $postedInvoice] = $this->paidInvoiceScenario('TOKUNP1', 300.00);
        self::assertSame(200, $this->match($postedTx, $postedInvoice, false)['status']);
        self::assertSame(1, $this->entryCountForTx($postedTx));

        $refused = $this->call('unmatch', $postedTx, [], true);
        self::assertSame(403, $refused['status'], json_encode($refused['body']));
        self::assertSame('token_write_forbidden', $refused['body']['error']['code'] ?? $refused['body']['code'] ?? null);
        self::assertSame('manual', $this->txStatus($postedTx), 'zaúčtovaný pohyb zůstává spárovaný');
        self::assertSame(1, $this->liveEntryCount($postedTx), 'zápis se nestornoval');

        $rematch = $this->match($postedTx, $postedInvoice, true);
        self::assertSame(403, $rematch['status'], 'přepárování zaúčtovaného pohybu by přepsalo zápis');

        [$tokenTx, $tokenInvoice] = $this->paidInvoiceScenario('TOKUNP2', 300.00);
        self::assertSame(200, $this->match($tokenTx, $tokenInvoice, true)['status']);
        $unmatched = $this->call('unmatch', $tokenTx, [], true);
        self::assertSame(200, $unmatched['status'], json_encode($unmatched['body']));
        self::assertSame('unmatched', $this->txStatus($tokenTx));
        self::assertSame(0, $this->entryCountForTx($tokenTx));
    }

    /** @return array{0:int, 1:int} [bank_transaction_id, invoice_id] */
    private function paidInvoiceScenario(string $vs, float $amount): array
    {
        $invoice = $this->saleInvoice($vs, $this->client('Odběratel ' . $vs), $amount);
        $this->postPredpis('invoice', $invoice, '311', '602', $amount);
        $tx = $this->transaction($this->statement(), $amount, ['variable_symbol' => $vs]);
        return [$tx, $invoice];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function match(int $txId, int $invoiceId, bool $token): array
    {
        return $this->call('manualMatch', $txId, ['invoice_id' => $invoiceId], $token);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, int $txId, array $body, bool $token): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $txId . '/' . ($method === 'unmatch' ? 'unmatch' : 'match'))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $token ? 'bearer' : 'session')
            ->withParsedBody($body);
        if ($token) {
            $request = $request->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['id' => 1, 'scope' => 'read_write']);
        }
        $response = $this->action->{$method}($request, new Psr7Response(), ['id' => (string) $txId]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function txStatus(int $txId): string
    {
        return (string) $this->db->pdo()->query("SELECT match_status FROM bank_transactions WHERE id = {$txId}")->fetchColumn();
    }

    private function liveEntryCount(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}
              AND source_type = 'bank' AND source_id = {$txId} AND reversed_by IS NULL"
        )->fetchColumn();
    }
}
