<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\OtherItemAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Obligations\OtherItemForecastService;
use MyInvoice\Service\Accounting\OtherItemBankMatchService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemInstallmentBackfill;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Účet protistrany, kandidáti úhrady podle splátky a účtu a vazba úhrady na splátku
 * (migrace 2237). Syntetická firma v daňové evidenci, účty 1000000005/0100 a
 * 19-1000000005/0100 (projdou mod 11), období 2099.
 */
#[Group('integration')]
final class OtherItemPaymentMatchingTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const ACCOUNT = '1000000005';
    private const BANK = '0100';

    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private OtherItemScheduleService $schedules;
    private OtherItemBankMatchService $bankMatch;
    private int $supplierId;
    private int $statementId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->items = $this->container->get(OtherItemService::class);
        $this->schedules = $this->container->get(OtherItemScheduleService::class);
        $this->bankMatch = $this->container->get(OtherItemBankMatchService::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)),
            '1000000005', '0100', '2099-03-31', 'CZK']);
        $this->statementId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testCounterpartyAccountIsValidatedAndStoredCanonically(): void
    {
        $czech = $this->items->create($this->supplierId, $this->input(['counterparty_account' => '0000001000000005 / 0100']), null);
        self::assertSame([self::ACCOUNT, self::BANK], [$czech['counterparty_account'], $czech['counterparty_bank_code']]);

        $prefixed = $this->items->create($this->supplierId, $this->input([
            'counterparty_account' => '19-1000000005', 'counterparty_bank_code' => '0100']), null);
        self::assertSame(['19-1000000005', '0100'], [$prefixed['counterparty_account'], $prefixed['counterparty_bank_code']]);

        $czIban = $this->items->create($this->supplierId, $this->input(['counterparty_account' => 'CZ04 0100 0000 1910 0000 0005']), null);
        self::assertSame(['19-1000000005', '0100'], [$czIban['counterparty_account'], $czIban['counterparty_bank_code']],
            'Český IBAN se uloží jako národní účet s kódem banky.');

        $foreign = $this->items->create($this->supplierId, $this->input(['counterparty_account' => 'de06 1234 5678 0000 0000 05']), null);
        self::assertSame(['DE06123456780000000005', null], [$foreign['counterparty_account'], $foreign['counterparty_bank_code']]);

        $cleared = $this->items->update($this->supplierId, (int) $czech['id'], ['counterparty_account' => ''], null);
        self::assertNull($cleared['counterparty_account']);
        self::assertNull($cleared['counterparty_bank_code']);

        foreach ([
            ['counterparty_account' => '1000000006/0100'],
            ['counterparty_account' => '1000000005'],
            ['counterparty_account' => '1000000005/0100', 'counterparty_bank_code' => '0300'],
            ['counterparty_bank_code' => '0100'],
            ['counterparty_account' => 'DE00123456780000000005'],
            ['counterparty_account' => 'DE06123456780000000005', 'counterparty_bank_code' => '0100'],
        ] as $invalid) {
            try {
                $this->items->create($this->supplierId, $this->input($invalid), null);
                self::fail('Neplatný účet protistrany musí skončit validační chybou: ' . json_encode($invalid));
            } catch (OtherItemException $e) {
                self::assertSame('invalid_counterparty_account', $e->errorCode);
                self::assertSame(422, $e->httpStatus);
            }
        }
    }

    public function testApiRejectsInvalidCounterpartyAccountWithValidationError(): void
    {
        $action = $this->container->get(OtherItemAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, ['other_items' => 2], 'custom'));

        $bad = $action->create($request->withParsedBody($this->input(['counterparty_account' => '1000000006/0100'])), new Response());
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('other_items.error.invalid_counterparty_account', (string) $bad->getBody());

        $ok = $action->create($request->withParsedBody($this->input(['counterparty_account' => '1000000005/0100'])), new Response());
        self::assertSame(201, $ok->getStatusCode());
        $body = json_decode((string) $ok->getBody(), true);
        $item = $body['data'] ?? $body;
        self::assertSame(self::ACCOUNT, $item['counterparty_account']);
        self::assertSame(self::BANK, $item['counterparty_bank_code']);
    }

    public function testRecurringScheduleCopiesCounterpartyAccount(): void
    {
        $source = $this->items->create($this->supplierId, $this->input([
            'counterparty_account' => self::ACCOUNT . '/' . self::BANK, 'issued_on' => '2099-01-05', 'due_on' => '2099-01-15']), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null);
        self::assertCount(1, $result['created_ids']);
        $copy = $this->items->get($this->supplierId, $result['created_ids'][0]);
        self::assertSame([self::ACCOUNT, self::BANK], [$copy['counterparty_account'], $copy['counterparty_bank_code']]);
    }

    public function testBankCandidatesAreRankedByVariableSymbolAccountAndInstallment(): void
    {
        $full = $this->confirmed(['title' => 'VS, účet a splátka', 'variable_symbol' => '2099001',
            'counterparty_account' => self::ACCOUNT . '/' . self::BANK], [['2099-03-10', 1000], ['2099-04-10', 2000]]);
        $vsOnly = $this->confirmed(['title' => 'Jen VS', 'variable_symbol' => '2099001', 'amount' => 5000]);
        $accountInstallment = $this->confirmed(['title' => 'Účet a splátka',
            'counterparty_account' => self::ACCOUNT . '/' . self::BANK], [['2099-06-10', 1000], ['2099-07-10', 2000]]);
        $nearDue = $this->confirmed(['title' => 'Splátka u splatnosti'], [['2099-03-20', 1000], ['2099-04-20', 2000]]);
        $remaining = $this->confirmed(['title' => 'Celý dluh', 'amount' => 1000]);
        $farInstallment = $this->confirmed(['title' => 'Splátka daleko'], [['2099-09-20', 1000], ['2099-10-20', 2000]]);
        $accountOnly = $this->confirmed(['title' => 'Jen účet', 'amount' => 7777,
            'counterparty_account' => 'CZ18 0100 0000 0010 0000 0005']);
        $this->confirmed(['title' => 'Bez shody', 'amount' => 4321]);

        $txId = $this->transaction(-1000, '2099-03-12', '2099001', '0000001000000005', '0100');
        $candidates = $this->bankMatch->candidates($this->supplierId, $txId);

        self::assertSame([$full, $vsOnly, $accountInstallment, $nearDue, $remaining, $farInstallment, $accountOnly],
            array_column($candidates, 'id'));
        self::assertSame([100, 80, 70, 60, 50, 40, 30], array_column($candidates, 'match_score'));
        self::assertSame(['variable_symbol', 'counterparty_account', 'installment_amount', 'installment_due'],
            $candidates[0]['match_reasons']);
        self::assertSame(1, $candidates[0]['installment']['position']);
        self::assertSame(1.0, $candidates[0]['match_confidence']);
        self::assertSame(['counterparty_account'], $candidates[6]['match_reasons'], 'IBAN položky odpovídá GPC účtu pohybu.');
        self::assertEqualsWithDelta(3000.0, $candidates[0]['amount'], 0.001, 'Částka kandidáta zůstává zbývajícím dluhem.');
    }

    public function testPaidInstallmentNoLongerMatchesByAmount(): void
    {
        $item = $this->confirmed(['title' => 'Splátky'], [['2099-03-10', 1000], ['2099-04-10', 2000]]);
        $this->items->allocate($this->supplierId, $item,
            ['bank_transaction_id' => $this->transaction(-1000, '2099-03-10'), 'amount' => 1000], null);

        self::assertSame([], $this->bankMatch->candidates($this->supplierId, $this->transaction(-1000, '2099-03-11')),
            'Uhrazená splátka 1000 už platbu 1000 nenabízí.');
        $next = $this->bankMatch->candidates($this->supplierId, $this->transaction(-2000, '2099-04-09'));
        self::assertSame([$item], array_column($next, 'id'));
        self::assertSame(2, $next[0]['installment']['position']);
        self::assertContains('remaining_amount', $next[0]['match_reasons']);
    }

    public function testPaymentCandidatesPreferAccountAndInstallmentOverNewerPayments(): void
    {
        $item = $this->confirmed(['title' => 'Úvěr', 'variable_symbol' => '2099777',
            'counterparty_account' => self::ACCOUNT . '/' . self::BANK], [['2099-03-10', 1000], ['2099-04-10', 2000]]);
        $installment = $this->transaction(-1000, '2099-03-09', null, '1000000005', '0100');
        $byVs = $this->transaction(-450, '2099-03-01', '2099777');
        $newest = $this->transaction(-999, '2099-03-30');

        $rows = $this->items->paymentCandidates($this->supplierId, $item, '', 20);
        self::assertSame([$byVs, $installment, $newest], array_column($rows, 'id'));
        self::assertSame(['variable_symbol'], $rows[0]['match_reasons']);
        self::assertSame(['counterparty_account', 'installment_amount', 'installment_due'], $rows[1]['match_reasons']);
        self::assertSame(75, $rows[1]['match_score']);
        self::assertSame(0, $rows[2]['match_score'], 'Bez hledání zůstávají nabízené i nejnovější platby bez shody.');
    }

    public function testAllocationIsAssignedToExactOrFirstOpenInstallment(): void
    {
        $item = $this->confirmed(['title' => 'Splátky'], [['2099-01-10', 1000], ['2099-02-10', 1500], ['2099-03-10', 500]]);
        $ids = $this->installmentIds($item);

        $this->items->allocate($this->supplierId, $item,
            ['bank_transaction_id' => $this->transaction(-1500, '2099-01-08'), 'amount' => 1500], null);
        self::assertSame([$ids[1]], $this->allocationInstallments($item), 'Přesná částka trefí svou splátku, i když není první.');

        $this->items->allocate($this->supplierId, $item,
            ['bank_transaction_id' => $this->transaction(-400, '2099-01-09'), 'amount' => 400], null);
        self::assertSame([$ids[1], $ids[0]], $this->allocationInstallments($item), 'Bez přesné shody první otevřená splátka.');

        $coverage = $this->items->installmentCoverage($this->supplierId, $item);
        self::assertSame(['partial', 'paid', 'unpaid'], array_column($coverage, 'status'));
        self::assertSame([400.0, 1500.0, 0.0], array_column($coverage, 'paid_amount'));
        self::assertSame([600.0, 0.0, 500.0], array_column($coverage, 'remaining_amount'));
    }

    public function testPaymentCoveringSeveralInstallmentsKeepsOneRowAndCoversFollowingInstallments(): void
    {
        $item = $this->confirmed(['title' => 'Splátky'], [['2099-01-10', 1000], ['2099-02-10', 1000], ['2099-03-10', 1000]]);
        $ids = $this->installmentIds($item);
        $result = $this->items->allocate($this->supplierId, $item,
            ['bank_transaction_id' => $this->transaction(-2500, '2099-01-10'), 'amount' => 2500], null);

        self::assertEqualsWithDelta(500.0, (float) $result['remaining_amount'], 0.001, 'Saldo položky se nemění.');
        self::assertSame([$ids[0]], $this->allocationInstallments($item), 'Jedna platba = jeden řádek, nese první splátku.');
        $coverage = $this->items->installmentCoverage($this->supplierId, $item);
        self::assertSame(['paid', 'paid', 'partial'], array_column($coverage, 'status'));
        self::assertSame([0.0, 0.0, 500.0], array_column($coverage, 'remaining_amount'));

        try {
            $this->items->allocate($this->supplierId, $item,
                ['bank_transaction_id' => $this->transaction(-600, '2099-03-10'), 'amount' => 600], null);
            self::fail('Přeplatek zůstává odmítnutý.');
        } catch (OtherItemException $e) {
            self::assertSame('overpayment', $e->errorCode);
        }
    }

    public function testOverdueInstallmentStatusAndInstallmentsEndpointShape(): void
    {
        $item = $this->confirmed(['title' => 'Splátky'], [['2000-01-10', 1000], ['2099-02-10', 2000]]);
        $rows = $this->schedules->installments($this->supplierId, $item);
        self::assertSame(['overdue', 'unpaid'], array_column($rows, 'status'));
        self::assertSame([1, 2], array_column($rows, 'position'));
        self::assertSame([1000.0, 2000.0], array_column($rows, 'remaining_amount'));
    }

    public function testReactivatedAllocationGetsInstallmentAfterCalendarChange(): void
    {
        $item = $this->confirmed(['title' => 'Splátky'], [['2099-01-10', 1000], ['2099-02-10', 2000]]);
        $txId = $this->transaction(-2000, '2099-01-10');
        $this->items->allocate($this->supplierId, $item, ['bank_transaction_id' => $txId, 'amount' => 2000], null);
        $this->pdo->prepare("UPDATE other_item_allocations SET reversed_on = '2099-01-11' WHERE other_item_id = ?")->execute([$item]);
        $this->schedules->setInstallments($this->supplierId, $item, [
            ['due_on' => '2099-01-20', 'amount' => 2000], ['due_on' => '2099-02-20', 'amount' => 1000]]);
        self::assertSame([null], $this->allocationInstallments($item), 'Smazaná splátka vazbu stornované úhrady vynuluje.');

        $this->items->allocate($this->supplierId, $item, ['bank_transaction_id' => $txId, 'amount' => 2000], null);
        self::assertSame([$this->installmentIds($item)[0]], $this->allocationInstallments($item));
    }

    /**
     * Backfill doplní splátky dřívějších úhrad tak, že předpověď i stav splátek vychází
     * stejně jako původní výpočet podle pořadí (SQL CTE před migrací 2237).
     */
    public function testBackfillMatchesPreviousFifoDerivation(): void
    {
        $plans = [
            ['settled' => 0, 'installments' => [['2099-05-10', 1000], ['2099-06-10', 1000], ['2099-07-10', 1000]],
                'payments' => [['2099-04-01', 1500], ['2099-04-05', 700]]],
            ['settled' => 300, 'installments' => [['2099-05-15', 400], ['2099-06-15', 1600], ['2099-07-15', 1000]],
                'payments' => [['2099-04-02', 100], ['2099-04-03', 1600], ['2099-04-03', 0.5]]],
            ['settled' => 0, 'installments' => [['2099-05-20', 999.99], ['2099-06-20', 1000.01]],
                'payments' => [['2099-04-04', 999.99], ['2099-04-04', 1000.01]]],
            ['settled' => 250, 'installments' => [['2099-05-25', 500], ['2099-06-25', 500]], 'payments' => []],
        ];
        $items = [];
        foreach ($plans as $plan) {
            $total = array_sum(array_column($plan['installments'], 1));
            $id = $this->confirmed(['title' => 'Převzaté splátky', 'amount' => $total], $plan['installments']);
            $this->pdo->prepare('UPDATE other_items SET settled_outside_amount = ? WHERE id = ?')->execute([$plan['settled'], $id]);
            foreach ($plan['payments'] as [$date, $amount]) {
                // Úhrada z doby před migrací: bez splátky, vložená mimo allocate().
                $this->pdo->prepare('INSERT INTO other_item_allocations (supplier_id, other_item_id, bank_transaction_id, amount, payment_on)
                    VALUES (?, ?, ?, ?, ?)')->execute([$this->supplierId, $id, $this->transaction(-$amount, $date), $amount, $date]);
            }
            $items[] = $id;
        }
        $expected = $this->previousFifoForecast();
        self::assertNotEmpty($expected);
        $forecast = new OtherItemForecastService($this->db);
        self::assertSame($expected, $this->planned($forecast), 'Bez backfillu se úhrada bez splátky rozpočítá podle pořadí.');

        $backfill = new OtherItemInstallmentBackfill($this->pdo);
        self::assertSame(7, $backfill->pending($this->supplierId));
        self::assertSame(['found' => 7, 'updated' => 7], $backfill->run(true, null, $this->supplierId));
        self::assertSame(0, $backfill->pending($this->supplierId));
        self::assertSame(['found' => 0, 'updated' => 0], $backfill->run(true, null, $this->supplierId), 'Opakovaný běh nic nemění.');

        self::assertSame($expected, $this->planned($forecast), 'Po backfillu vychází předpověď stejně.');
        $first = $this->installmentIds($items[0]);
        self::assertSame([$first[0], $first[1]], $this->allocationInstallments($items[0]));
        $second = $this->installmentIds($items[1]);
        self::assertSame([$second[0], $second[1], $second[2]], $this->allocationInstallments($items[1]),
            'Mimo banku uhrazená část se započte dřív než úhrady.');
        self::assertSame(['paid', 'paid', 'partial'],
            array_column($this->items->installmentCoverage($this->supplierId, $items[1]), 'status'));
    }

    /** @return list<array{id:int,due_on:string,remaining:float}> */
    private function planned(OtherItemForecastService $forecast): array
    {
        $rows = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'due_on' => $row['due_on'], 'remaining' => round($row['remaining'], 2),
        ], $forecast->dueBetween($this->supplierId, '2099-01-01', '2099-12-31'));
        return $rows;
    }

    /**
     * Původní výpočet zbytku splátek z OtherItemForecastService před migrací 2237.
     *
     * @return list<array{id:int,due_on:string,remaining:float}>
     */
    private function previousFifoForecast(): array
    {
        $stmt = $this->pdo->prepare('WITH planned AS (
            SELECT oi.id, i.due_on, i.amount,
                   SUM(i.amount) OVER (PARTITION BY oi.id ORDER BY i.position) cumulative,
                   COALESCE(a.paid_amount, 0) + oi.settled_outside_amount paid_amount
              FROM other_item_installments i
              JOIN other_items oi ON oi.id = i.other_item_id AND oi.supplier_id = i.supplier_id
              LEFT JOIN (SELECT supplier_id, other_item_id, SUM(amount) paid_amount
                           FROM other_item_allocations WHERE reversed_on IS NULL
                           GROUP BY supplier_id, other_item_id) a
                ON a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id
             WHERE oi.supplier_id = ? AND oi.deleted_at IS NULL
               AND oi.status IN (\'draft\',\'confirmed\',\'posted\')
            ) SELECT id, due_on, GREATEST(LEAST(amount, cumulative - paid_amount), 0) remaining
              FROM planned WHERE due_on BETWEEN ? AND ? ORDER BY due_on, id');
        $stmt->execute([$this->supplierId, '2099-01-01', '2099-12-31']);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((float) $row['remaining'] <= 0) continue;
            $rows[] = ['id' => (int) $row['id'], 'due_on' => (string) $row['due_on'], 'remaining' => round((float) $row['remaining'], 2)];
        }
        return $rows;
    }

    /** @return list<int> */
    private function installmentIds(int $item): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM other_item_installments WHERE other_item_id = ? ORDER BY position');
        $stmt->execute([$item]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<?int> */
    private function allocationInstallments(int $item): array
    {
        $stmt = $this->pdo->prepare('SELECT installment_id FROM other_item_allocations WHERE other_item_id = ? ORDER BY payment_on, id');
        $stmt->execute([$item]);
        return array_map(static fn ($id): ?int => $id === null ? null : (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Potvrzený závazek, případně se splátkami [[datum, částka], …] (součet = částka položky).
     *
     * @param array<string,mixed> $changes
     * @param list<array{0:string,1:float|int}> $installments
     */
    private function confirmed(array $changes, array $installments = []): int
    {
        if ($installments !== []) $changes['amount'] ??= array_sum(array_column($installments, 1));
        $draft = $this->items->create($this->supplierId, $this->input($changes), null);
        $id = (int) $draft['id'];
        if ($installments !== []) {
            $this->schedules->setInstallments($this->supplierId, $id, array_map(
                static fn (array $row): array => ['due_on' => $row[0], 'amount' => $row[1]], $installments));
        }
        $this->items->post($this->supplierId, $id, null);
        return $id;
    }

    /** @param array<string,mixed> $changes */
    private function input(array $changes = []): array
    {
        return array_replace([
            'side' => 'payable', 'kind' => 'loan', 'title' => 'Syntetický úvěr',
            'issued_on' => '1999-12-01', 'accounting_on' => '1999-12-01',
            'due_on' => '2099-12-31', 'currency' => 'CZK', 'amount' => 3000,
        ], $changes);
    }

    private function transaction(float $amount, string $date, ?string $vs = null, ?string $account = null, ?string $bank = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, match_status, variable_symbol, counterparty_account,
                 counterparty_bank, counterparty_name, description)
             VALUES (?, ?, ?, ?, "unmatched", ?, ?, ?, "Syntetický partner", "Syntetická platba")'
        )->execute([$this->statementId, $date, $amount, 'CZK', $vs, $account, $bank]);
        return (int) $this->pdo->lastInsertId();
    }
}
