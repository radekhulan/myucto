<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Bank\TransferAutoPolicyInterface;
use MyInvoice\Service\Accounting\Bank\TransferPairService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class OwnTransferDetectionTest extends BankPostingTestCase
{
    private const SECOND_ACCOUNT = '1000000005';
    private const SECOND_BANK = '0100';
    private int $initialTransferMatchCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, ?, 'suggest', ?)
             ON DUPLICATE KEY UPDATE level='suggest', updated_by=VALUES(updated_by)"
        );
        foreach (['bank.transfer.own', 'detector.own_transfer'] as $operationType) {
            $stmt->execute([$this->supplierId, $operationType, $this->userId]);
        }
        $this->initialTransferMatchCount = $this->transferMatchCount();
    }

    public function testBothLegsAreSuggestedPairedAndPostedThrough261(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $outStatement = $this->statement();
        $inStatement = $this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK);
        $amount = 98765.43;

        $outTx = $this->transaction($outStatement, -$amount, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $inTx = $this->transaction($inStatement, $amount, [
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
            'posted_at' => self::YEAR . '-06-16',
        ]);

        $out = $this->service->handleTransaction($outTx, $this->userId);
        $in = $this->service->handleTransaction($inTx, $this->userId);

        self::assertSame(['suggested', 'own_transfer'], [$out['action'], $out['reason']]);
        self::assertSame(['suggested', 'own_transfer'], [$in['action'], $in['reason']]);
        self::assertSame('transfer', $this->suggestionRow((int) $out['suggestion_id'])['source']);
        self::assertSame('transfer', $this->suggestionRow((int) $in['suggestion_id'])['source']);

        $pair = $this->db->pdo()->prepare(
            'SELECT out_transaction_id, in_transaction_id FROM bank_transfer_matches
              WHERE supplier_id = ? AND out_transaction_id = ? AND in_transaction_id = ?'
        );
        $pair->execute([$this->supplierId, $outTx, $inTx]);
        self::assertSame(
            ['out_transaction_id' => $outTx, 'in_transaction_id' => $inTx],
            array_map('intval', $pair->fetch(\PDO::FETCH_ASSOC)),
        );

        $outEntry = $this->service->approveSuggestion($this->supplierId, (int) $out['suggestion_id'], $this->meta());
        $inEntry = $this->service->approveSuggestion($this->supplierId, (int) $in['suggestion_id'], $this->meta());
        self::assertEqualsWithDelta($amount, $this->linesByAccountCode($outEntry)['261']['debit'], 0.001);
        self::assertEqualsWithDelta($amount, $this->linesByAccountCode($inEntry)['261']['credit'], 0.001);
    }

    /**
     * Peníze na cestě jdou na analytiku 261.100, když ji firma vede.
     *
     * TransferPairService staví nohu natvrdo jako '261' (kontace se tam nepoužije),
     * takže přesměr na jedinou analytiku musí zabrat až v
     * {@see \MyInvoice\Service\Accounting\PostingService::resolveLines()} — tudy jde
     * KAŽDÝ zápis. Bez toho by 261 zůstalo na syntetice, přestože pod ní visí
     * analytika, a součet analytik by neseděl na syntetiku.
     */
    public function testTransitLegLandsOnTheSingleAnalyticWhenTenantHasOne(): void
    {
        $transit = $this->accounts->findByCode($this->supplierId, '261');
        self::assertNotNull($transit, 'Syntetika 261 musí být v osnově.');
        $this->accounts->insert($this->supplierId, [
            'account_code' => '261.100',
            'name'         => 'Peníze na cestě — převody',
            'account_type' => $transit['account_type'],
            'normal_side'  => $transit['normal_side'],
            'is_synthetic' => false,
            'parent_id'    => (int) $transit['id'],
            'is_active'    => true,
        ]);

        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $amount = 12345.67;
        $outTx = $this->transaction($this->statement(), -$amount, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $inTx = $this->transaction($this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK), $amount, [
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
            'posted_at' => self::YEAR . '-06-16',
        ]);

        $out = $this->service->handleTransaction($outTx, $this->userId);
        $in = $this->service->handleTransaction($inTx, $this->userId);
        $outEntry = $this->service->approveSuggestion($this->supplierId, (int) $out['suggestion_id'], $this->meta());
        $inEntry = $this->service->approveSuggestion($this->supplierId, (int) $in['suggestion_id'], $this->meta());

        $outLines = $this->linesByAccountCode($outEntry);
        $inLines = $this->linesByAccountCode($inEntry);
        self::assertArrayNotHasKey('261', $outLines, 'Na holé syntetice 261 nesmí zůstat nic.');
        self::assertArrayNotHasKey('261', $inLines);
        self::assertEqualsWithDelta($amount, $outLines['261.100']['debit'], 0.001);
        self::assertEqualsWithDelta($amount, $inLines['261.100']['credit'], 0.001);
    }

    /**
     * #9 — okno párování nohou převodu rozšířeno z ±3 na ±7 dní (PAIR_WINDOW_DAYS). Mezi
     * odepsáním z jednoho účtu a připsáním na druhý bývá i víc než 3 dny (mezibankovní, víkend).
     */
    public function testTransfersSixDaysApartArePairedWithinSevenDayWindow(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $outStatement = $this->statement();
        $inStatement = $this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK);
        $amount = 45678.90;

        // 6 dní mezi nohama — nad starým oknem ±3, uvnitř nového ±7.
        $outTx = $this->transaction($outStatement, -$amount, [
            'posted_at' => self::YEAR . '-06-15',
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $inTx = $this->transaction($inStatement, $amount, [
            'posted_at' => self::YEAR . '-06-21',
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
        ]);

        $this->service->handleTransaction($outTx, $this->userId);
        $this->service->handleTransaction($inTx, $this->userId);

        $pair = $this->db->pdo()->prepare(
            'SELECT out_transaction_id, in_transaction_id FROM bank_transfer_matches
              WHERE supplier_id = ? AND out_transaction_id = ? AND in_transaction_id = ?'
        );
        $pair->execute([$this->supplierId, $outTx, $inTx]);
        self::assertSame(
            ['out_transaction_id' => $outTx, 'in_transaction_id' => $inTx],
            array_map('intval', $pair->fetch(\PDO::FETCH_ASSOC) ?: []),
            'Nohy 6 dní od sebe se musí ve fixtuře ±7 dní spárovat.',
        );
    }

    public function testCrossCurrencyTransferCannotBeApproved(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'EUR');
        $statement = $this->statement();
        $tx = $this->transaction($statement, -12345.67, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        self::assertSame('cross_currency', $result['reason']);
        self::assertSame('cross_currency', $this->suggestionRow((int) $result['suggestion_id'])['note']);

        try {
            $this->service->approveSuggestion($this->supplierId, (int) $result['suggestion_id'], $this->meta());
            self::fail('Převod mezi měnami se nesmí schválit automatickou kontací.');
        } catch (PostingException $e) {
            self::assertSame('cross_currency_manual_only', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testMissingRegisteredAccountCurrencyFailsClosedAsCrossCurrency(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, null);
        $statement = $this->statement();
        $tx = $this->transaction($statement, -12345.67, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        self::assertSame('cross_currency', $result['reason']);
    }

    public function testExistingManual261EntryCreatesDuplicateWarning(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $amount = 54321.09;
        $manualEntry = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '261', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => '221', 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date' => self::YEAR . '-06-08',
            'posted' => true,
            'posted_by' => $this->userId,
        ]);
        $statement = $this->statement();
        $tx = $this->transaction($statement, -$amount, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        self::assertSame('duplicate_suspect:#' . $manualEntry, $result['reason']);
        self::assertSame('duplicate_suspect:#' . $manualEntry, $this->suggestionRow((int) $result['suggestion_id'])['note']);
    }

    public function testRedStornoManual261EntryDoesNotCreateDuplicateWarning(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $amount = 54321.09;
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '261', 'side' => 'debit', 'amount' => $amount, 'is_red_storno' => true],
            ['account_code' => '221', 'side' => 'credit', 'amount' => $amount, 'is_red_storno' => true],
        ], [
            'entry_date' => self::YEAR . '-06-08',
            'posted' => true,
            'posted_by' => $this->userId,
        ]);
        $tx = $this->transaction($this->statement(), -$amount, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        self::assertSame('own_transfer', $result['reason']);
        self::assertNull($this->suggestionRow((int) $result['suggestion_id'])['note']);
    }

    public function testPeriodBoundaryTreatsPairedTransitBalanceAsDocumented(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $nextPeriodId = $this->periods->create(
            $this->supplierId,
            self::YEAR + 1,
            (self::YEAR + 1) . '-01-01',
            (self::YEAR + 1) . '-12-31',
        );
        self::assertGreaterThan(0, $nextPeriodId);
        $outStatement = $this->statement();
        $inStatement = $this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK);
        $amount = 87654.32;
        $outTx = $this->transaction($outStatement, -$amount, [
            'posted_at' => self::YEAR . '-12-31',
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $inTx = $this->transaction($inStatement, $amount, [
            'posted_at' => (self::YEAR + 1) . '-01-02',
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
        ]);

        $out = $this->service->handleTransaction($outTx, $this->userId);
        $in = $this->service->handleTransaction($inTx, $this->userId);
        $this->service->approveSuggestion($this->supplierId, (int) $out['suggestion_id'], $this->meta());
        $this->service->approveSuggestion($this->supplierId, (int) $in['suggestion_id'], $this->meta());

        $period = $this->periods->findById($this->supplierId, $this->periodId);
        $precheck = $this->container->get(ClosingService::class)->runPrecheck(
            $this->supplierId,
            $this->periodId,
            (int) $period['row_version'],
            ['user_id' => $this->userId],
        );
        $transit = null;
        foreach ($precheck['checks'] as $check) {
            if ($check['key'] === 'transit_261_open') $transit = $check;
        }
        self::assertNotNull($transit);
        self::assertContains($outTx, array_map(
            static fn (array $row): int => (int) $row['tx_id'],
            $transit['value']['documented'],
        ));
    }

    public function testSameCurrencyFxPairUsesFirstLegRateOnBothLegs(): void
    {
        $this->registerAccount(self::ACCOUNT, self::BANK_CODE, 'EUR');
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'EUR');
        $outStatement = $this->statement();
        $inStatement = $this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK);
        $this->db->pdo()->prepare('UPDATE bank_statements SET currency = "EUR" WHERE id IN (?, ?)')
            ->execute([$outStatement, $inStatement]);
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, "EUR", 25.00), (?, "EUR", 27.00)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([self::YEAR . '-06-15', self::YEAR . '-06-16']);
        $foreignAmount = 432.10;
        $outTx = $this->transaction($outStatement, -$foreignAmount, [
            'currency' => 'EUR',
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $inTx = $this->transaction($inStatement, $foreignAmount, [
            'currency' => 'EUR',
            'posted_at' => self::YEAR . '-06-16',
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
        ]);

        $out = $this->service->handleTransaction($outTx, $this->userId);
        $in = $this->service->handleTransaction($inTx, $this->userId);
        $outEntry = $this->service->approveSuggestion($this->supplierId, (int) $out['suggestion_id'], $this->meta());
        $inEntry = $this->service->approveSuggestion($this->supplierId, (int) $in['suggestion_id'], $this->meta());
        $expectedCzk = round($foreignAmount * 25.00, 2);
        self::assertEqualsWithDelta($expectedCzk, $this->linesByAccountCode($outEntry)['261']['debit'], 0.001);
        self::assertEqualsWithDelta($expectedCzk, $this->linesByAccountCode($inEntry)['261']['credit'], 0.001);

        $rates = $this->db->pdo()->prepare(
            'SELECT DISTINCT fx_rate FROM journal_entry_lines WHERE supplier_id = ? AND entry_id IN (?, ?) ORDER BY fx_rate'
        );
        $rates->execute([$this->supplierId, $outEntry, $inEntry]);
        self::assertSame([25.0], array_map('floatval', $rates->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testAutoPolicyPostsWhileOffPolicyDefersToFollowingDetectors(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $outStatement = $this->statement();
        $inStatement = $this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK);
        $amount = 76543.21;
        $outTx = $this->transaction($outStatement, -$amount, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $inTx = $this->transaction($inStatement, $amount, [
            'posted_at' => self::YEAR . '-06-16',
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
        ]);

        $off = $this->transferService('off')->handle($this->supplierId, $this->loadTransferTx($outTx), $this->userId, false);
        self::assertNull($off);
        self::assertSame($this->initialTransferMatchCount, $this->transferMatchCount());

        $auto = $this->transferService('auto');
        $out = $auto->handle($this->supplierId, $this->loadTransferTx($outTx), $this->userId, false);
        $in = $auto->handle($this->supplierId, $this->loadTransferTx($inTx), $this->userId, false);
        self::assertSame(['posted', 'own_transfer'], [$out['action'], $out['reason']]);
        self::assertSame(['posted', 'own_transfer'], [$in['action'], $in['reason']]);
        self::assertSame($this->initialTransferMatchCount + 1, $this->transferMatchCount());
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'bank', $outTx));
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'bank', $inTx));
    }

    public static function historicalPeriods(): array
    {
        return [
            'rok před převodem po odstranění období' => [self::YEAR . '-01-01', false],
            'starší automaticky založené období před setupem' => [null, true],
        ];
    }

    #[DataProvider('historicalPeriods')]
    public function testGpcOwnTransferBeforeAccountingStartStaysUnpostedOnRetry(?string $startsOn, bool $legacyPeriod): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET accounting_starts_on = ? WHERE id = ?')
            ->execute([$startsOn, $this->supplierId]);
        $pdo->prepare("UPDATE accounting_periods SET created_reason = CASE WHEN id = ? THEN 'setup' ELSE 'posting' END WHERE supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);
        $year = self::YEAR - 1;
        $periodId = $legacyPeriod ? $this->periods->create(
            $this->supplierId, $year, $year . '-01-01', $year . '-12-31', 'posting',
        ) : null;
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $pdo->prepare("UPDATE auto_posting_policy SET level = 'auto' WHERE supplier_id = ? AND operation_type IN ('bank.transfer.own', 'detector.own_transfer')")
            ->execute([$this->supplierId]);
        $statementId = $this->statement();
        $pdo->prepare("UPDATE bank_statements SET source = 'gpc' WHERE id = ?")->execute([$statementId]);
        $tx = $this->transaction($statementId, -12345.67, [
            'posted_at' => $year . '-12-10',
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);

        $first = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame(['suggested', 'period_before_start'], [$first['action'], $first['reason']]);
        self::assertSame('blocked', $this->suggestionRow((int) $first['suggestion_id'])['status']);
        $retry = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame(['suggested', 'period_before_start', $first['suggestion_id']], [
            $retry['action'], $retry['reason'], $retry['suggestion_id'],
        ]);
        try {
            $this->service->approveSuggestion($this->supplierId, (int) $first['suggestion_id'], $this->meta());
            self::fail('Schválení nesmí zaúčtovat převod před začátkem účetnictví.');
        } catch (PostingException $e) {
            self::assertSame('period_before_start', $e->errorCode);
        }
        self::assertSame(0, $this->entryCountForTx($tx));
        $period = $this->periods->findByYear($this->supplierId, $year);
        self::assertSame($periodId, $period === null ? null : (int) $period['id']);
        if (!$legacyPeriod) {
            $this->periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
            $entryId = $this->service->approveSuggestion($this->supplierId, (int) $first['suggestion_id'], $this->meta());
            self::assertSame($entryId, (int) $this->journal->findBySource($this->supplierId, 'bank', $tx)['id']);
        }
    }

    /**
     * Účet faktoringu je kvůli fakturám v registru vlastních účtů. Pravidlo s prioritou
     * pod 50 musí mít přednost před vlastním převodem i při schválení návrhu, nejen při importu.
     */
    public function testPriorityRuleSuggestionIsApprovedByRuleNotReplacedByTransfer(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $ruleId = $this->priorityFactoringRule();
        $tx = $this->transaction($this->statement(), 8712.00, $this->factoringTx());

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        $sug = $this->suggestionRow((int) $result['suggestion_id']);
        self::assertSame(['rule', $ruleId], [$sug['source'], (int) $sug['rule_id']]);

        $entryId = $this->service->approveSuggestion($this->supplierId, (int) $result['suggestion_id'], $this->meta());
        $lines = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(8712.00, $lines['379']['credit'], 0.001);
        self::assertArrayNotHasKey('261', $lines);
    }

    public function testPriorityRuleBackfillSuggestsRuleInsteadOfTransfer(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $ruleId = $this->priorityFactoringRule();
        $tx = $this->transaction($this->statement(), 5445.00, $this->factoringTx());

        $result = $this->service->suggestRuleForBackfill($this->supplierId, $tx, $ruleId, $this->userId);

        self::assertSame('suggested', $result['action']);
        $sug = $this->suggestionRow((int) $result['suggestion_id']);
        self::assertSame(['rule', $ruleId], [$sug['source'], (int) $sug['rule_id']]);
    }

    public function testOffTransferPolicyDoesNotReplaceRuleSuggestionOnApproval(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $this->db->pdo()->prepare(
            "UPDATE auto_posting_policy SET level = 'off' WHERE supplier_id = ? AND operation_type = 'detector.own_transfer'"
        )->execute([$this->supplierId]);
        $ruleId = $this->priorityFactoringRule(100);
        $tx = $this->transaction($this->statement(), 76.06, $this->factoringTx());

        $result = $this->service->handleTransaction($tx, $this->userId);
        $sug = $this->suggestionRow((int) $result['suggestion_id']);
        self::assertSame(['rule', $ruleId], [$sug['source'], (int) $sug['rule_id']]);

        $entryId = $this->service->approveSuggestion($this->supplierId, (int) $result['suggestion_id'], $this->meta());
        self::assertEqualsWithDelta(76.06, $this->linesByAccountCode($entryId)['379']['credit'], 0.001);
    }

    private function priorityFactoringRule(int $priority = 10): int
    {
        return $this->rule([
            'name' => 'Faktoring - záloha',
            'direction' => 'incoming',
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
            'debit_account_code' => '221',
            'credit_account_code' => '379',
            'priority' => $priority,
        ]);
    }

    /** @return array<string,string> */
    private function factoringTx(): array
    {
        return [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
            'description' => 'Platba zalohy:1234567890',
        ];
    }

    private function registerAccount(string $number, string $bank, ?string $currency): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, "Druhý testovací účet", ?, ?, ?, ?, ?, "savings", "manual", 1)'
            . ' ON DUPLICATE KEY UPDATE currency = VALUES(currency), is_active = 1'
        )->execute([$this->supplierId, $number, $bank, $bank, $currency, $number]);
    }

    private function transferService(string $level): TransferPairService
    {
        $policy = new class($level) implements TransferAutoPolicyInterface {
            public function __construct(private readonly string $level) {}
            public function level(int $supplierId): string { return $this->level; }
        };
        return new TransferPairService(
            $this->db,
            $this->posting,
            $this->container->get(\MyInvoice\Repository\PostingRuleRepository::class),
            $this->journal,
            $this->suggestionRepo,
            $this->container->get(\MyInvoice\Service\Accounting\Bank\OwnTransferDetector::class),
            $policy,
            $this->container->get(\MyInvoice\Service\ActivityLogger::class),
            $this->container->get(\MyInvoice\Service\Currency\CnbExchangeRateClient::class),
        );
    }

    private function transferMatchCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_transfer_matches WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function loadTransferTx(int $txId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.currency AS statement_currency
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $stmt->execute([$txId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
