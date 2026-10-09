<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Service\TaxEvidence\TaxEvidenceBankRules;
use PHPUnit\Framework\Attributes\Group;

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

    public function testValidationRequiresCriterionAndAction(): void
    {
        $v = TaxEvidenceBankRules::validate(['name' => 'Prázdné']);
        self::assertArrayHasKey('criteria', $v['errors']);
        self::assertArrayHasKey('action', $v['errors']);
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
