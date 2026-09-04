<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Accounting\Bank\BankPostingRuleAction;
use MyInvoice\Service\Automation\RuleProposalService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Životní cyklus pravidla: ruční zaúčtování → learned hint → pravidlo (suggest) →
 * návrh od 2. výskytu; konflikt → suggest, nikdy auto; validace H2 a auditovatelná
 * změna režimu přes samostatný promotion endpoint. §8.
 */
#[Group('integration')]
final class BankPostingRulesFlowTest extends BankPostingTestCase
{
    public function testSetupAnalysisLearnsRuleFromConsistentlyPostedHistory(): void
    {
        $statement = $this->statement();
        foreach ([420.0, 440.0] as $amount) {
            $transactionId = $this->transaction($statement, -$amount, [
                'counterparty_account' => '1000000005',
                'counterparty_bank' => '0100',
                'description' => 'Syntetický pravidelný poplatek',
            ]);
            $this->service->postManual($this->supplierId, $transactionId, [
                'debit_account_code' => '568',
                'credit_account_code' => '221',
            ], $this->meta());
        }
        $reversedTransactionId = $this->transaction($statement, -460.0, [
            'counterparty_account' => '1000000005',
            'counterparty_bank' => '0100',
            'description' => 'Syntetický pravidelný poplatek',
        ]);
        $reversed = $this->service->postManual($this->supplierId, $reversedTransactionId, [
            'debit_account_code' => '518',
            'credit_account_code' => '221',
        ], $this->meta());
        $this->posting->reverse($this->supplierId, (int) $reversed['entry_id'], [
            'entry_date' => self::YEAR . '-06-20',
            'user_id' => $this->userId,
            'posted_by' => $this->userId,
        ]);

        $proposalService = $this->container->get(RuleProposalService::class);
        $operationalAnalysis = $proposalService->analyze($this->supplierId, 60);
        self::assertSame([], array_values(array_filter(
            $operationalAnalysis['clusters'],
            static fn (array $item): bool => ($item['proposal']['counterparty_account'] ?? null) === '1000000005',
        )));

        $analysis = $proposalService->analyze($this->supplierId, 60, true);
        $cluster = array_values(array_filter(
            $analysis['clusters'],
            static fn (array $item): bool => ($item['proposal']['counterparty_account'] ?? null) === '1000000005',
        ));

        self::assertCount(1, $cluster);
        self::assertSame('568', $cluster[0]['proposal']['debit_account_code']);
        self::assertStringStartsWith('221', $cluster[0]['proposal']['credit_account_code']);
        self::assertSame([], $cluster[0]['proposal']['tx_ids']);
    }

    public function testManualPostThenLearnedHintThenRuleSuggestThenAuto(): void
    {
        $stmt = $this->statement();

        // 1. výskyt: ruční zaúčtování bez předchozích shod → žádný hint.
        $tx1 = $this->transaction($stmt, -24836.00, ['counterparty_account' => '77621', 'variable_symbol' => '7712', 'description' => 'Odvod OSSZ 05/2026']);
        $r1 = $this->service->postManual($this->supplierId, $tx1, [
            'debit_account_code' => '336', 'credit_account_code' => '221',
        ], $this->meta());
        self::assertNull($r1['rule_hint'], 'První výskyt nemá učební předlohu.');

        // 2. výskyt: ruční zaúčtování → learned hint z prvního výskytu (§4.2).
        $tx2 = $this->transaction($stmt, -24840.00, ['counterparty_account' => '77621', 'variable_symbol' => '7712', 'description' => 'Odvod OSSZ 06/2026']);
        $r2 = $this->service->postManual($this->supplierId, $tx2, [
            'debit_account_code' => '336', 'credit_account_code' => '221',
        ], $this->meta());
        self::assertNotNull($r2['rule_hint']);
        self::assertSame('336', $r2['rule_hint']['prefill']['debit_account_code']);
        self::assertSame('221', $r2['rule_hint']['prefill']['credit_account_code']);
        self::assertSame('outgoing', $r2['rule_hint']['prefill']['direction']);
    }

    public function testCreateRuleFromManualForcesSuggestThenSecondOccurrenceSuggests(): void
    {
        $stmt = $this->statement();
        $tx1 = $this->transaction($stmt, -24836.00, ['counterparty_account' => '77630', 'variable_symbol' => '7713']);
        $res = $this->service->postManual($this->supplierId, $tx1, [
            'debit_account_code' => '336', 'credit_account_code' => '221',
            'create_rule' => [
                'name' => 'OSSZ', 'direction' => 'outgoing', 'counterparty_account' => '77630',
                'amount_min' => 20000.00, 'amount_max' => 30000.00,
                'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'auto', // vynuceno na suggest
            ],
        ], $this->meta());
        self::assertNotNull($res['rule_id']);
        $rule = $this->ruleRow((int) $res['rule_id']);
        self::assertSame('suggest', $rule['mode'], 'Nové pravidlo je vždy suggest (H4e).');

        // 2. výskyt → pravidlo suggest → pending návrh.
        $tx2 = $this->transaction($stmt, -24840.00, ['counterparty_account' => '77630']);
        $r2 = $this->service->handleTransaction($tx2, $this->userId);
        self::assertSame('suggested', $r2['action']);
        self::assertSame('336', $this->suggestionRow((int) $r2['suggestion_id'])['debit_account_code']);
    }

    public function testRuleConflictSuggestsNeverAuto(): void
    {
        // Dvě aktivní auto pravidla chytají tutéž tx → suggestion rule_conflict.
        $this->rule([
            'name' => 'A', 'direction' => 'outgoing', 'counterparty_account' => '77640',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'auto',
        ]);
        $this->rule([
            'name' => 'B', 'direction' => 'outgoing', 'variable_symbol' => '5555',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '518', 'credit_account_code' => '221', 'mode' => 'auto',
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['counterparty_account' => '77640', 'variable_symbol' => '5555']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('rule_conflict', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx), 'Konflikt se nikdy neúčtuje automaticky.');
    }

    // ── Action-level validace ────────────────────────────────────────────────

    public function testCreateRuleWithSaldoAccountRejected422(): void
    {
        $action = $this->container->get(BankPostingRuleAction::class);
        $res = $this->callAction($action, 'create', 'POST', 'accountant', [
            'name' => 'Špatné', 'direction' => 'incoming', 'counterparty_account' => '12345',
            'debit_account_code' => '221', 'credit_account_code' => '311', // saldokonto na ne-bankovní straně
        ]);
        self::assertSame(422, $res['status']);
        self::assertSame('rule_saldo_forbidden', $res['body']['error']['code'] ?? null);
    }

    /**
     * Špatná bankovní strana má vlastní kód, ne sdílený se saldokontem. Dokud
     * sdílely jeden, dostal uživatel u nebankovního účtu na bankovní straně
     * hlášku o párování faktur — a neměl podle čeho pravidlo opravit.
     */
    public function testCreateRuleWithNonBankSideRejectedWithOwnCode(): void
    {
        $action = $this->container->get(BankPostingRuleAction::class);
        $res = $this->callAction($action, 'create', 'POST', 'accountant', [
            'name' => 'Špatná strana', 'direction' => 'incoming', 'counterparty_account' => '12345',
            'debit_account_code' => '518', 'credit_account_code' => '602', // na MD chybí 221
        ]);
        self::assertSame(422, $res['status']);
        self::assertSame('rule_bank_side_required', $res['body']['error']['code'] ?? null);
    }

    public function testGenericUpdateCannotBypassPromotionWorkflow(): void
    {
        $ruleId = $this->rule([
            'name' => 'OSSZ', 'direction' => 'outgoing', 'counterparty_account' => '77650',
            'amount_min' => null, 'amount_max' => null,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $action = $this->container->get(BankPostingRuleAction::class);
        $res = $this->callAction($action, 'update', 'PUT', 'accountant', ['mode' => 'auto'], ['id' => (string) $ruleId]);
        self::assertSame(409, $res['status']);
        self::assertSame('rule_promotion_required', $res['body']['error']['code'] ?? null);
    }

    public function testExistingRuleBackfillCreatesOnlyItsSuggestionAndIsIdempotent(): void
    {
        $ruleId = $this->rule([
            'name' => 'Historické poplatky', 'direction' => 'outgoing',
            'counterparty_account' => '77660',
            'amount_min' => 100.00, 'amount_max' => 2000.00,
            'debit_account_code' => '568', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $this->rule([
            'name' => 'Jiné překrývající pravidlo', 'direction' => 'outgoing',
            'message_contains' => 'platba',
            'amount_min' => 100.00, 'amount_max' => 2000.00,
            'debit_account_code' => '518', 'credit_account_code' => '221', 'mode' => 'suggest',
            'priority' => 1,
        ]);
        $stmt = $this->statement();
        $txId = $this->transaction($stmt, -500.00, ['counterparty_account' => '77660']);

        $action = $this->container->get(BankPostingRuleAction::class);
        $first = $this->callAction($action, 'backfillRule', 'POST', 'accountant', [], ['id' => (string) $ruleId]);
        self::assertSame(200, $first['status']);
        self::assertSame(1, $first['body']['backfilled'] ?? null);

        $suggestion = $this->db->pdo()->query(
            'SELECT rule_id, status FROM bank_posting_suggestions WHERE bank_transaction_id = ' . $txId
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame((string) $ruleId, (string) ($suggestion['rule_id'] ?? ''));
        self::assertSame('pending', $suggestion['status'] ?? null);
        self::assertSame(0, $this->entryCountForTx($txId));

        $second = $this->callAction($action, 'backfillRule', 'POST', 'accountant', [], ['id' => (string) $ruleId]);
        self::assertSame(200, $second['status']);
        self::assertSame(0, $second['body']['backfilled'] ?? null);
    }

    public function testRuleBackfillRejectsTransactionOwnedByAnotherSupplier(): void
    {
        $otherSupplier = (int) ($this->db->pdo()->query(
            'SELECT id FROM supplier WHERE id <> ' . $this->supplierId . ' ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($otherSupplier === 0) self::markTestSkipped('Chybí druhá firma pro tenantový test.');
        $ruleId = $this->rule([
            'name' => 'Tenant guard', 'direction' => 'outgoing',
            'counterparty_account' => '77661',
            'debit_account_code' => '568', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $statement = $this->statement();
        $txId = $this->transaction($statement, -500.00, ['counterparty_account' => '77661']);
        $this->db->pdo()->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')
            ->execute([$otherSupplier, $statement]);

        $result = $this->service->suggestRuleForBackfill($this->supplierId, $txId, $ruleId, $this->userId);

        self::assertSame('skipped', $result['action']);
        self::assertSame('transaction_not_found', $result['reason']);
        self::assertNull($this->suggestionRepo->pendingForTx($this->supplierId, $txId));
    }
}
