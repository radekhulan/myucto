<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\Bank\DocumentMatchingRulePrecedence;
use MyInvoice\Service\Bank\StatementMatcher;

/**
 * Pravidlo účtování s předností před párováním s doklady (faktoring, inkasní agentura,
 * leasing platí s VS naší faktury) a podmínka platby kartou.
 *
 * Pohyb, na který sedí aktivní pravidlo s `skip_document_matching`, automatické párování
 * nespáruje s dokladem (vystaveným ani přijatým) a zaúčtuje ho pravidlo. Bez volby se
 * páruje beze změny.
 */
final class BankRuleSkipDocumentMatchingTest extends BankPostingTestCase
{
    private const FACTOR_ACCOUNT = '1000000005';
    private const FACTOR_BANK = '0100';

    private StatementMatcher $matcher;
    private DocumentMatchingRulePrecedence $precedence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = $this->container->get(StatementMatcher::class);
        $this->precedence = $this->container->get(DocumentMatchingRulePrecedence::class);
    }

    /** @param array<string,mixed> $data */
    private function factorRule(string $direction, bool $skip, array $data = []): int
    {
        return $this->ruleRepo->insert($this->supplierId, $data + [
            'name'                   => 'Faktoring',
            'direction'              => $direction,
            'counterparty_account'   => self::FACTOR_ACCOUNT,
            'counterparty_bank'      => self::FACTOR_BANK,
            'amount_min'             => 1000.00,
            'amount_max'             => 50000.00,
            'debit_account_code'     => $direction === 'incoming' ? '221' : '379',
            'credit_account_code'    => $direction === 'incoming' ? '379' : '221',
            'mode'                   => 'auto',
            'mode_set_manually_at'   => self::YEAR . '-01-01 00:00:00',
            'skip_document_matching' => $skip ? 1 : 0,
        ], $this->userId);
    }

    private function enableAutoRules(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.rule.custom', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = VALUES(level), updated_by = VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
    }

    private function invoiceStatus(int $invoiceId): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$invoiceId}")->fetchColumn();
    }

    public function testFactoringPaymentWithInvoiceVsIsPostedByRuleNotMatched(): void
    {
        $this->factorRule('incoming', true);
        $this->enableAutoRules();
        $invoiceId = $this->saleInvoice('2099770001', $this->client('Odběratel s.r.o.'), 12100.00);
        $txId = $this->transaction($this->statement(), 12100.00, [
            'variable_symbol' => '2099770001',
            'counterparty_account' => self::FACTOR_ACCOUNT, 'counterparty_bank' => self::FACTOR_BANK,
            'counterparty_name' => 'Faktoring a.s.',
        ]);

        $result = $this->matcher->matchBatch([$txId])[$txId];

        self::assertSame('unmatched', $result['status']);
        self::assertSame(DocumentMatchingRulePrecedence::REASON, $result['reason']);
        self::assertSame('issued', $this->invoiceStatus($invoiceId), 'Faktura zůstane neuhrazená.');
        $row = $this->db->pdo()->query("SELECT match_status, matched_invoice_id, match_reason FROM bank_transactions WHERE id = {$txId}")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('unmatched', $row['match_status']);
        self::assertNull($row['matched_invoice_id']);
        self::assertSame(DocumentMatchingRulePrecedence::REASON, $row['match_reason']);

        $posted = $this->service->handleTransaction($txId, $this->userId);
        self::assertSame('posted', $posted['action'], json_encode($posted, JSON_UNESCAPED_UNICODE) ?: '');
        $lines = $this->linesByAccountCode((int) $posted['entry_id']);
        self::assertEqualsWithDelta(12100.00, $lines['221']['debit'], 0.001);
        self::assertEqualsWithDelta(12100.00, $lines['379']['credit'], 0.001);
        self::assertArrayNotHasKey('311', $lines);
    }

    /** Výchozí stav (volba vypnutá) páruje bajtově stejně jako dřív. */
    public function testWithoutOptionThePaymentIsMatchedAsBefore(): void
    {
        $this->factorRule('incoming', false);
        $invoiceId = $this->saleInvoice('2099770002', $this->client('Odběratel s.r.o.'), 12100.00);
        $txId = $this->transaction($this->statement(), 12100.00, [
            'variable_symbol' => '2099770002',
            'counterparty_account' => self::FACTOR_ACCOUNT, 'counterparty_bank' => self::FACTOR_BANK,
        ]);

        $result = $this->matcher->matchBatch([$txId])[$txId];

        self::assertSame('auto_exact', $result['status']);
        self::assertSame($invoiceId, (int) $result['invoice_id']);
        self::assertSame('paid', $this->invoiceStatus($invoiceId));
    }

    public function testOutgoingPaymentIsNotMatchedToReceivedInvoice(): void
    {
        $this->factorRule('outgoing', true);
        $purchaseId = $this->purchaseInvoice('FV2099771', $this->client('Dodavatel s.r.o.'), 5000.00);
        $txId = $this->transaction($this->statement(), -5000.00, [
            'variable_symbol' => '2099771',
            'counterparty_account' => self::FACTOR_ACCOUNT, 'counterparty_bank' => self::FACTOR_BANK,
        ]);

        $result = $this->matcher->matchBatch([$txId])[$txId];

        self::assertSame('unmatched', $result['status']);
        self::assertSame(DocumentMatchingRulePrecedence::REASON, $result['reason']);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$txId} OR purchase_invoice_id = {$purchaseId}"
        )->fetchColumn());
    }

    public function testRuleConditionsDecideAsUsual(): void
    {
        $ruleId = $this->factorRule('incoming', true);
        $base = [
            'id' => 0, 'amount' => 12100.00, 'currency' => 'CZK', 'statement_currency' => 'CZK',
            'variable_symbol' => '1', 'counterparty_account' => self::FACTOR_ACCOUNT,
            'counterparty_bank' => self::FACTOR_BANK, 'description' => null, 'counterparty_name' => null,
            'recipient_account' => self::ACCOUNT, 'recipient_bank' => self::BANK_CODE,
        ];

        self::assertSame($ruleId, (int) ($this->precedence->ruleFor($this->supplierId, $base)['id'] ?? 0));
        self::assertNull($this->precedence->ruleFor($this->supplierId, ['amount' => -12100.00] + $base), 'Jiný směr.');
        self::assertNull($this->precedence->ruleFor($this->supplierId, ['amount' => 60000.00] + $base), 'Mimo rozsah částky.');
        self::assertNull($this->precedence->ruleFor($this->supplierId, ['currency' => 'EUR'] + $base), 'Jiná měna.');
        self::assertNull($this->precedence->ruleFor($this->supplierId, ['counterparty_account' => '2000000003'] + $base), 'Jiná protistrana.');

        $this->db->pdo()->exec("UPDATE bank_posting_rules SET is_active = 0 WHERE id = {$ruleId}");
        self::assertNull($this->precedence->ruleFor($this->supplierId, $base), 'Neaktivní pravidlo.');
    }

    public function testRulesAreLoadedOncePerBatch(): void
    {
        $ruleId = $this->factorRule('incoming', true);
        $base = [
            'id' => 0, 'amount' => 12100.00, 'currency' => 'CZK',
            'counterparty_account' => self::FACTOR_ACCOUNT, 'counterparty_bank' => self::FACTOR_BANK,
        ];

        $this->precedence->withinBatch(function () use ($ruleId, $base): void {
            self::assertNotNull($this->precedence->ruleFor($this->supplierId, $base));
            // V dávce se pravidla nečtou znovu: deaktivace se projeví až v další dávce.
            $this->db->pdo()->exec("UPDATE bank_posting_rules SET is_active = 0 WHERE id = {$ruleId}");
            self::assertNotNull($this->precedence->ruleFor($this->supplierId, $base));
        });
        self::assertNull($this->precedence->ruleFor($this->supplierId, $base));
    }

    public function testCardOnlyRuleAppliesToCardPaymentNotToTransferWithSameText(): void
    {
        $ruleId = $this->ruleRepo->insert($this->supplierId, [
            'name' => 'Tankování kartou', 'direction' => 'outgoing', 'message_contains' => 'shell',
            'card_payment' => 1, 'debit_account_code' => '501', 'credit_account_code' => '221',
        ], $this->userId);
        $statementId = $this->statement();
        $card = $this->transaction($statementId, -850.00, ['description' => 'SHELL PRAHA 4', 'counterparty_name' => 'SHELL']);
        $transfer = $this->transaction($statementId, -850.00, ['description' => 'SHELL PRAHA 4', 'counterparty_name' => 'SHELL']);
        $this->db->pdo()->exec("UPDATE bank_transactions SET card_last4 = '4242' WHERE id = {$card}");

        $cardResult = $this->service->handleTransaction($card, $this->userId);
        self::assertSame('suggested', $cardResult['action'], json_encode($cardResult) ?: '');
        self::assertSame($ruleId, (int) $this->suggestionRow((int) $cardResult['suggestion_id'])['rule_id']);

        $this->service->handleTransaction($transfer, $this->userId);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions WHERE bank_transaction_id = {$transfer} AND rule_id = {$ruleId}"
        )->fetchColumn(), 'Převod se stejným textem pravidlo jen pro karty nepoužije.');
    }
}
