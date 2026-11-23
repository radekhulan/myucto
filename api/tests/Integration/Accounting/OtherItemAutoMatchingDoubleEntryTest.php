<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingRuleValidator;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Automatické párování ostatních položek a pravidlo účtování banky s cílovou položkou
 * v podvojném účetnictví: pohyb se zaúčtuje jen jednou, na účet položky. Syntetická firma
 * s vlastním účtem výpisu, nájemné 325/518, účet protistrany 1000000005/0100, rok 2099.
 */
#[Group('integration')]
final class OtherItemAutoMatchingDoubleEntryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PARTNER_ACCOUNT = '1000000005';
    private const PARTNER_BANK = '0100';
    private const VS = '2099001';

    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private BankPostingService $bankPosting;
    private int $supplierId;
    private int $statementId;
    private int $userId;
    private string $ownAccount;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->items = $this->container->get(OtherItemService::class);
        $this->bankPosting = $this->container->get(BankPostingService::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', auto_match_other_items = 1 WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $this->ownAccount = '2099' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, account_number, bank_code)
             VALUES (?, "CZK", "Kč", "Kč", "koruna česká", "Czech koruna", ?, "0800")'
        )->execute([$this->supplierId, $this->ownAccount]);
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date, currency)
             VALUES (?, ?, ?, ?, "0800", ?, "CZK")'
        )->execute([$this->supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)), $this->ownAccount, '2099-03-31']);
        $this->statementId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testAutoMatchPostsUnpostedPaymentOnItemAccountOnlyOnce(): void
    {
        $item = $this->postedItem();
        $txId = $this->transaction(-1200);

        $result = $this->container->get(StatementMatcher::class)->matchBatch([$txId])[$txId];
        self::assertSame($item, $result['other_item_id'] ?? null);
        self::assertSame(['325:debit:1200.00', '221:credit:1200.00'], $this->bankLines($txId));
        self::assertEqualsWithDelta(0.0, (float) $this->items->get($this->supplierId, $item)['remaining_amount'], 0.001);

        $handled = $this->bankPosting->handleTransaction($txId, null);
        self::assertSame(['skipped', 'already_posted'], [$handled['action'], $handled['reason'] ?? null],
            'Pravidla ani detektory pohyb podruhé nezaúčtují.');
        self::assertSame(1, $this->entryCount($txId));
    }

    public function testAutoMatchInClosedPeriodLeavesPaymentUntouched(): void
    {
        $this->postedItem();
        $txId = $this->transaction(-1200);
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND fiscal_year = 2099")
            ->execute([$this->supplierId]);

        $result = $this->container->get(StatementMatcher::class)->matchBatch([$txId])[$txId];

        self::assertSame('other_item_candidates', $result['reason']);
        self::assertSame(0, $this->entryCount($txId));
        self::assertSame(0, $this->allocationCount($txId));
    }

    public function testRuleWithOtherItemTargetPostsAndAllocatesWithoutSecondEntry(): void
    {
        $this->pdo->prepare('UPDATE supplier SET auto_match_other_items = 0 WHERE id = ?')->execute([$this->supplierId]);
        $item = $this->postedItem(['variable_symbol' => null, 'counterparty_account' => null]);
        $rule = $this->autoRule($item);
        $txId = $this->transaction(-1200);

        $handled = $this->bankPosting->handleTransaction($txId, $this->userId);

        self::assertSame(['posted', 'auto_rule'], [$handled['action'], $handled['reason'] ?? null]);
        self::assertSame(['325:debit:1200.00', '221:credit:1200.00'], $this->bankLines($txId));
        self::assertSame(1, $this->entryCount($txId));
        $stmt = $this->pdo->prepare('SELECT other_item_id, amount, auto_match_reason FROM other_item_allocations WHERE bank_transaction_id = ?');
        $stmt->execute([$txId]);
        self::assertSame([[$item, 1200.0, 'bank_rule']], array_map(static fn (array $row): array =>
            [(int) $row[0], (float) $row[1], $row[2]], $stmt->fetchAll(PDO::FETCH_NUM)));
        self::assertSame('already_posted', $this->bankPosting->handleTransaction($txId, $this->userId)['reason'] ?? null);
        self::assertGreaterThan(0, $rule);
    }

    public function testRuleWithSettledItemBecomesSuggestionWithoutPosting(): void
    {
        $this->pdo->prepare('UPDATE supplier SET auto_match_other_items = 0 WHERE id = ?')->execute([$this->supplierId]);
        $item = $this->postedItem(['variable_symbol' => null, 'counterparty_account' => null]);
        $this->autoRule($item);
        $first = $this->transaction(-1200);
        self::assertSame('posted', $this->bankPosting->handleTransaction($first, $this->userId)['action']);
        $second = $this->transaction(-1200, '2099-03-13', '2099002');

        $handled = $this->bankPosting->handleTransaction($second, $this->userId);

        self::assertSame(['suggested', 'other_item_unavailable'], [$handled['action'], $handled['reason'] ?? null]);
        self::assertSame(0, $this->entryCount($second), 'Zápis bez přiřazení by na účtu položky založil přeplatek.');
        self::assertSame(0, $this->allocationCount($second));
    }

    public function testApprovedSuggestionOfRuleWithOtherItemAllocates(): void
    {
        $this->pdo->prepare('UPDATE supplier SET auto_match_other_items = 0 WHERE id = ?')->execute([$this->supplierId]);
        $item = $this->postedItem(['variable_symbol' => null, 'counterparty_account' => null]);
        $rule = $this->autoRule($item);
        $this->pdo->prepare("UPDATE bank_posting_rules SET mode = 'suggest' WHERE id = ?")->execute([$rule]);
        $txId = $this->transaction(-1200);

        $handled = $this->bankPosting->handleTransaction($txId, $this->userId);
        self::assertSame('suggested', $handled['action']);
        self::assertSame(0, $this->allocationCount($txId));

        $this->bankPosting->approveSuggestion($this->supplierId, (int) $handled['suggestion_id'], ['user_id' => $this->userId]);

        self::assertSame(['325:debit:1200.00', '221:credit:1200.00'], $this->bankLines($txId));
        self::assertSame(1, $this->allocationCount($txId));
        self::assertEqualsWithDelta(0.0, (float) $this->items->get($this->supplierId, $item)['remaining_amount'], 0.001);
    }

    public function testRuleValidatorChecksOtherItemTarget(): void
    {
        $validator = $this->container->get(BankPostingRuleValidator::class);
        $item = $this->postedItem();
        $rule = ['direction' => 'outgoing', 'counterparty_account' => self::PARTNER_ACCOUNT,
            'debit_account_code' => '325', 'credit_account_code' => '221', 'other_item_id' => $item];

        self::assertSame($item, $validator->normalizeRule($this->supplierId, $rule)['other_item_id'],
            'Účet položky 325 smí pravidlo s cílovou položkou použít.');
        $this->assertRuleRejected($validator, ['other_item_id' => null], 'rule_saldo_forbidden');
        $this->assertRuleRejected($validator, $rule + ['debit_account_code' => '518'], 'rule_other_item_account', ['debit_account_code' => '518']);
        $this->assertRuleRejected($validator, $rule, 'rule_other_item_direction',
            ['direction' => 'incoming', 'debit_account_code' => '221', 'credit_account_code' => '325']);
        $draft = (int) $this->items->create($this->supplierId, $this->itemInput([]), null)['id'];
        $this->assertRuleRejected($validator, $rule, 'rule_other_item_invalid', ['other_item_id' => $draft]);
        $foreign = $this->createIsolatedSupplier($this->pdo, $this->supplierId);
        $this->assertRuleRejected($validator, $rule, 'rule_other_item_invalid', [], $foreign);
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $changes
     */
    private function assertRuleRejected(BankPostingRuleValidator $validator, array $rule, string $code, array $changes = [], ?int $supplierId = null): void
    {
        $base = ['direction' => 'outgoing', 'counterparty_account' => self::PARTNER_ACCOUNT,
            'debit_account_code' => '325', 'credit_account_code' => '221'];
        try {
            $validator->normalizeRule($supplierId ?? $this->supplierId, array_replace($base, $rule, $changes));
            self::fail('Pravidlo mělo skončit chybou ' . $code);
        } catch (PostingException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    private function autoRule(int $item): int
    {
        $rules = $this->container->get(BankPostingRuleRepository::class);
        $id = $rules->insert($this->supplierId, [
            'name' => 'Nájemné', 'direction' => 'outgoing', 'counterparty_account' => self::PARTNER_ACCOUNT,
            'amount_min' => 1000.0, 'amount_max' => 1500.0, 'debit_account_code' => '325', 'credit_account_code' => '221',
            'mode' => 'auto', 'other_item_id' => $item,
        ], $this->userId);
        $this->pdo->prepare('UPDATE bank_posting_rules SET hit_count = 3 WHERE id = ?')->execute([$id]);
        $this->pdo->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.rule.custom', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = 'auto', updated_by = VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
        return $id;
    }

    /** @param array<string,mixed> $changes */
    private function postedItem(array $changes = []): int
    {
        $id = (int) $this->items->create($this->supplierId, $this->itemInput($changes), null)['id'];
        $this->items->post($this->supplierId, $id, null);
        return $id;
    }

    /** @param array<string,mixed> $changes */
    private function itemInput(array $changes): array
    {
        return array_replace([
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'issued_on' => '2099-01-01', 'accounting_on' => '2099-01-01', 'due_on' => '2099-03-10',
            'currency' => 'CZK', 'amount' => 1200, 'counter_account_code' => '518', 'variable_symbol' => self::VS,
            'counterparty_account' => self::PARTNER_ACCOUNT . '/' . self::PARTNER_BANK,
        ], $changes);
    }

    private function transaction(float $amount, string $date = '2099-03-12', string $vs = self::VS): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, match_status, variable_symbol, counterparty_account,
                 counterparty_bank, counterparty_name, description)
             VALUES (?, ?, ?, "CZK", "unmatched", ?, ?, ?, "Syntetický pronajímatel", "Syntetické nájemné")'
        )->execute([$this->statementId, $date, $amount, $vs, self::PARTNER_ACCOUNT, self::PARTNER_BANK]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<string> řádky živého bankovního zápisu „účet:strana:částka" (bez analytiky) */
    private function bankLines(int $txId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CONCAT(SUBSTRING_INDEX(a.account_code, '.', 1), ':', l.side, ':', CAST(l.amount AS CHAR))
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.source_type = 'bank' AND e.source_id = ? AND e.reversed_by IS NULL
              ORDER BY l.id"
        );
        $stmt->execute([$this->supplierId, $txId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function entryCount(int $txId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'bank' AND source_id = ?");
        $stmt->execute([$this->supplierId, $txId]);
        return (int) $stmt->fetchColumn();
    }

    private function allocationCount(int $txId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM other_item_allocations WHERE bank_transaction_id = ?');
        $stmt->execute([$txId]);
        return (int) $stmt->fetchColumn();
    }
}
