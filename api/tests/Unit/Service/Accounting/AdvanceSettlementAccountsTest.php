<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\AbortedTransactionTrait;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class AdvanceSettlementAccountsTest extends TestCase
{
    use AbortedTransactionTrait;

    #[TestWith(['sale', '324', '311', '311.300', '324', '311.300'])]
    #[TestWith(['purchase', '321', '314', '321.200', '321.200', '314'])]
    #[TestWith(['sale', '311', '311', '311.300', '311', '311'])]
    #[TestWith(['purchase', '321', '321', '321.200', '321', '321'])]
    public function testAdvanceAccountAndDirectSaldoFollowTheRule(
        string $side,
        string $ruleDebit,
        string $ruleCredit,
        string $saldo,
        string $debit,
        string $credit,
    ): void {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE posting_rules (
            id INTEGER PRIMARY KEY, supplier_id INTEGER, rule_key TEXT, description TEXT,
            debit_account_code TEXT, credit_account_code TEXT, priority INTEGER, is_active INTEGER
        )');
        $rule = $side === 'sale' ? 'advance.received.settlement' : 'advance.paid.settlement';
        $pdo->prepare('INSERT INTO posting_rules VALUES (1, NULL, ?, ?, ?, ?, 0, 1)')
            ->execute([$rule, 'Testovací předkontace', $ruleDebit, $ruleCredit]);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $posting = self::serviceWith(PostingService::class, ['rules' => new PostingRuleRepository($db)]);

        $accounts = $posting->advanceSettlementAccounts(1, $side, $saldo);
        self::assertSame($debit, $accounts['debit']);
        self::assertSame($credit, $accounts['credit']);
        self::assertSame($ruleDebit === $ruleCredit, $accounts['on_saldo'] ?? null);
    }
}
