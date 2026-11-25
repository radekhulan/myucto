<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Service\TaxEvidence\TaxEvidenceBankRules;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pravidla bankovních pohybů v daňové evidenci (issue #140, migrace 1990).
 *
 * Převod z vlastního účtu bez dokladu je v peněžním deníku nezařazený a příchozí blokuje
 * přiznání. Pravidlo podle protiúčtu ho označí jako ignorovaný a zařadí jako převod.
 * Ruční zařazení ani spárovaný pohyb pravidlo nepřepíše, v podvojném účetnictví nedělá nic.
 */
#[Group('integration')]
final class TaxEvidenceBankRulesTest extends CashJournalTestCase
{
    private TaxEvidenceBankRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = $this->container->get(TaxEvidenceBankRules::class);
    }

    public function testOwnAccountTransferRuleIgnoresAndClassifiesMovement(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTxFrom($st, 5000.0, '19-2000145399', 'Převod z vlastního účtu');
        $this->createRule(['name' => 'Převod ze spořicího účtu', 'counterparty_account' => '19-2000145399',
            'action_ignore' => true, 'tax_bucket' => 'transfer']);

        self::assertNotEmpty($this->fullYear($this->supplierId)['warnings'], 'bez pravidla pohyb blokuje');

        $result = $this->rules->apply($this->supplierId, null, $this->userId);

        self::assertSame(['applied' => 1, 'ignored' => 1, 'classified' => 1], $result);
        self::assertSame('ignored', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$tx]));
        self::assertSame('rule', $this->scalar('SELECT ignore_origin FROM bank_transactions WHERE id = ?', [$tx]));
        $res = $this->fullYear($this->supplierId);
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.01);
        self::assertEmpty($res['warnings'], 'převod zařazený pravidlem už přiznání neblokuje');
        self::assertSame(1, $this->countRows($res, 'bank', $tx), 'ignorovaný pohyb v deníku zůstává');
    }

    public function testRuleDoesNotOverrideManualClassificationOrOtherSupplierMode(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $manual = $this->bankTxFrom($st, 1000.0, '', 'Vklad majitele');
        $this->classifyOverride($this->supplierId, 'bank', $manual, 'private');
        $this->createRule(['name' => 'Vklad', 'text_contains' => 'vklad', 'tax_bucket' => 'income_nontax']);

        self::assertSame(0, $this->rules->apply($this->supplierId)['applied'], 'ruční zařazení má přednost');
        self::assertSame('private', $this->scalar(
            'SELECT tax_bucket FROM de_movement_classification WHERE supplier_id = ? AND bank_transaction_id = ?',
            [$this->supplierId, $manual],
        ));

        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->bankTxFrom($st, 2000.0, '', 'Druhý vklad');
        self::assertSame(0, $this->rules->apply($this->supplierId)['applied'], 'mimo daňovou evidenci nic');
    }

    #[DataProvider('applyScopes')]
    public function testRuleSkipsClosedYearWithoutIgnoringItsMovements(bool $oneStatement): void
    {
        $statement = $this->statement($this->supplierId, $this->accountA);
        $closed = $this->bankTx($statement, -100.0, [
            'posted_at' => self::YEAR . '-12-31', 'description' => 'Testovací poplatek',
        ]);
        $open = $this->bankTx($statement, -200.0, [
            'posted_at' => (self::YEAR + 1) . '-01-01', 'description' => 'Testovací poplatek',
        ]);
        $this->db->pdo()->prepare(
            "INSERT INTO tax_evidence_closings (supplier_id, year, status) VALUES (?, ?, 'final')"
        )->execute([$this->supplierId, self::YEAR]);
        $rule = $this->createRule([
            'name' => 'Poplatky', 'text_contains' => 'poplatek', 'action_ignore' => true, 'tax_bucket' => 'expense_taxable',
        ]);

        $scope = $oneStatement ? $statement : null;
        self::assertSame(['applied' => 1, 'ignored' => 1, 'classified' => 1], $this->rules->apply($this->supplierId, $scope));
        self::assertSame('unmatched', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$closed]));
        self::assertFalse($this->scalar(
            'SELECT tax_bucket FROM de_movement_classification WHERE supplier_id = ? AND bank_transaction_id = ?',
            [$this->supplierId, $closed],
        ));
        self::assertSame('ignored', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$open]));
        self::assertSame('expense_taxable', $this->scalar(
            'SELECT tax_bucket FROM de_movement_classification WHERE supplier_id = ? AND bank_transaction_id = ?',
            [$this->supplierId, $open],
        ));
        self::assertSame(1, $this->rules->find($this->supplierId, $rule)['hit_count']);
        self::assertSame(['applied' => 0, 'ignored' => 0, 'classified' => 0], $this->rules->apply($this->supplierId, $scope));
    }

    public static function applyScopes(): iterable
    {
        yield 'všechny výpisy firmy' => [false];
        yield 'jeden výpis' => [true];
    }

    public static function accountCriteria(): iterable
    {
        yield 'bank only' => ['*/5050'];
        yield 'account and bank' => ['1000000005/5050'];
    }

    #[DataProvider('accountCriteria')]
    public function testRuleUsesBankCodeFromStatement(string $criterion): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $matching = $this->bankTxFrom($st, -100.0, '1000000005', 'Test banky');
        $other = $this->bankTxFrom($st, -100.0, '1000000005', 'Jiná banka');
        $update = $this->db->pdo()->prepare('UPDATE bank_transactions SET counterparty_bank = ? WHERE id = ?');
        $update->execute(['5050', $matching]);
        $update->execute(['0100', $other]);
        $this->createRule(['name' => 'Banka', 'counterparty_account' => $criterion, 'action_ignore' => true]);

        self::assertSame(['applied' => 1, 'ignored' => 1, 'classified' => 0], $this->rules->apply($this->supplierId));
        self::assertSame('ignored', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$matching]));
        self::assertSame('unmatched', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$other]));
    }

    public function testValidationRequiresCriterionAndAction(): void
    {
        $v = TaxEvidenceBankRules::validate(['name' => 'Prázdné']);
        self::assertArrayHasKey('criteria', $v['errors']);
        self::assertArrayHasKey('action', $v['errors']);
    }

    public function testConstantSymbolIsPersistedListedUpdatedAndCleared(): void
    {
        $id = $this->createRule(['name' => 'KS', 'constant_symbol' => '0308', 'action_ignore' => true]);
        self::assertSame('0308', $this->rules->find($this->supplierId, $id)['constant_symbol']);
        self::assertSame('0308', $this->rules->list($this->supplierId)[0]['constant_symbol']);

        $updated = TaxEvidenceBankRules::validate(['name' => 'Nový KS', 'constant_symbol' => '0000', 'action_ignore' => true]);
        self::assertSame([], $updated['errors']);
        self::assertTrue($this->rules->update($this->supplierId, $id, $updated['data']));
        self::assertSame('0000', $this->rules->find($this->supplierId, $id)['constant_symbol']);

        $cleared = TaxEvidenceBankRules::validate(['name' => 'Bez KS', 'text_contains' => 'poplatek', 'action_ignore' => true]);
        self::assertSame([], $cleared['errors']);
        self::assertTrue($this->rules->update($this->supplierId, $id, $cleared['data']));
        self::assertNull($this->rules->list($this->supplierId)[0]['constant_symbol']);
    }

    public function testConstantSymbolOnlyRuleUsesTransactionSymbolAndCountsHits(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $matching = [];
        $unmatched = [];
        foreach (['0308', '308', '0558', null, '', 'abc', '03 08'] as $symbol) {
            $tx = $this->bankTxFrom($st, -100.0, '', 'Test KS');
            $this->db->pdo()->prepare('UPDATE bank_transactions SET constant_symbol = ? WHERE id = ?')->execute([$symbol, $tx]);
            if (in_array($symbol, ['0308', '308'], true)) {
                $matching[] = $tx;
            } else {
                $unmatched[] = $tx;
            }
        }
        $id = $this->createRule(['name' => 'KS', 'constant_symbol' => '0308', 'action_ignore' => true,
            'tax_bucket' => 'expense_taxable']);

        self::assertSame(['applied' => 2, 'ignored' => 2, 'classified' => 2], $this->rules->apply($this->supplierId, $st, $this->userId));
        foreach ($matching as $tx) {
            self::assertSame('ignored', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$tx]));
            self::assertSame('expense_taxable', $this->scalar(
                'SELECT tax_bucket FROM de_movement_classification WHERE supplier_id = ? AND bank_transaction_id = ?',
                [$this->supplierId, $tx],
            ));
        }
        foreach ($unmatched as $tx) {
            self::assertSame('unmatched', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$tx]));
        }
        self::assertSame(2, $this->rules->find($this->supplierId, $id)['hit_count']);
        self::assertNotNull($this->rules->find($this->supplierId, $id)['last_hit_at']);
        self::assertSame(0, $this->rules->apply($this->supplierId)['applied'], 'zařazené pohyby se podruhé nemění');
    }

    public function testZeroConstantSymbolDoesNotMatchMissingSymbol(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $zero = $this->bankTxFrom($st, -100.0, '', 'Nulový KS');
        $missing = $this->bankTxFrom($st, -100.0, '', 'Bez KS');
        $this->db->pdo()->prepare('UPDATE bank_transactions SET constant_symbol = ? WHERE id = ?')->execute(['0', $zero]);
        $this->createRule(['name' => 'Nulový KS', 'constant_symbol' => '0000', 'action_ignore' => true]);

        self::assertSame(['applied' => 1, 'ignored' => 1, 'classified' => 0], $this->rules->apply($this->supplierId));
        self::assertSame('ignored', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$zero]));
        self::assertSame('unmatched', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$missing]));
    }

    public function testConstantSymbolRespectsSupplierScopeManualClassificationAndMatchedStatus(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $manual = $this->bankTxFrom($st, -100.0, '', 'Ručně zařazený');
        $matched = $this->bankTxFrom($st, -100.0, '', 'Spárovaný');
        $otherSupplier = $this->cloneSupplier('tax_evidence', true);
        $this->currencyRow($otherSupplier, 'CZK', '1000000005', '2010');
        $other = $this->bankTxFrom($this->statement($otherSupplier, '1000000005'), -100.0, '', 'Jiná firma');
        $update = $this->db->pdo()->prepare('UPDATE bank_transactions SET constant_symbol = ? WHERE id = ?');
        foreach ([$manual, $matched, $other] as $tx) {
            $update->execute(['0308', $tx]);
        }
        $this->classifyOverride($this->supplierId, 'bank', $manual, 'private');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'auto_exact' WHERE id = ?")->execute([$matched]);
        $this->createRule(['name' => 'KS', 'constant_symbol' => '0308', 'action_ignore' => true, 'tax_bucket' => 'expense_taxable']);

        self::assertSame(['applied' => 0, 'ignored' => 0, 'classified' => 0], $this->rules->apply($this->supplierId));
        self::assertSame('private', $this->scalar(
            'SELECT tax_bucket FROM de_movement_classification WHERE supplier_id = ? AND bank_transaction_id = ?',
            [$this->supplierId, $manual],
        ));
        self::assertSame('auto_exact', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$matched]));
        self::assertSame('unmatched', $this->scalar('SELECT match_status FROM bank_transactions WHERE id = ?', [$other]));
    }

    /** @param array<string,mixed> $body */
    private function createRule(array $body): int
    {
        $v = TaxEvidenceBankRules::validate($body);
        self::assertSame([], $v['errors']);
        return $this->rules->create($this->supplierId, $v['data'], $this->userId);
    }

    private function bankTxFrom(int $statementId, float $amount, string $account, string $description): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, counterparty_account, counterparty_name,
                 description, match_status)
             VALUES (?, "statement", ?, ?, "CZK", ?, "Protistrana", ?, "unmatched")'
        )->execute([$statementId, self::YEAR . '-06-15', $amount, $account === '' ? null : $account, $description]);
        return (int) $pdo->lastInsertId();
    }

    /** @param list<int|string> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
