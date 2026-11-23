<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Learning;

use MyInvoice\Action\Accounting\Bank\BankPostingRuleAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Repository\AccountingCorrectionRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Learning\LearningStatsProvider;
use MyInvoice\Service\Accounting\Learning\RuleMiner;
use MyInvoice\Service\Accounting\Learning\RulePromotionService;
use MyInvoice\Service\Ai\AiJobService;
use MyInvoice\Service\Ai\AiPayloadSanitizer;
use MyInvoice\Service\Ai\EmbeddingGatewayInterface;
use MyInvoice\Service\Ai\EmbeddingWriter;
use MyInvoice\Service\Bank\Match\MatchSuggestionService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class E7LearningFlowTest extends BankPostingTestCase
{
    private AccountingCorrectionRepository $corrections;
    private RulePromotionService $promotion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->corrections = $this->container->get(AccountingCorrectionRepository::class);
        $this->promotion = $this->container->get(RulePromotionService::class);
    }

    public function testCorrectionRecorderCoversOverrideRejectManualAndUnpostWithTenantScope(): void
    {
        $ruleId = $this->rule([
            'name' => 'Korekce', 'direction' => 'outgoing', 'counterparty_account' => '901001',
            'amount_min' => 100, 'amount_max' => 5000,
            'debit_account_code' => '518', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $statement = $this->statement();
        $tx = $this->transaction($statement, -1000, ['counterparty_account' => '901001']);
        $suggestion = (int) $this->service->handleTransaction($tx, $this->userId)['suggestion_id'];
        $entry = $this->service->approveSuggestion($this->supplierId, $suggestion, $this->meta(), [
            'debit_account_code' => '501', 'credit_account_code' => '221',
        ]);
        $events = $this->corrections->forEntity($this->supplierId, 'bank_transaction', $tx);
        self::assertSame('approve_override', $events[0]['event_type']);
        self::assertSame('518', $events[0]['suggested_debit']);
        self::assertSame('501', $events[0]['final_debit']);
        self::assertSame($ruleId, $events[0]['rule_id']);
        self::assertSame(1, $this->embeddingJobCount($tx));

        $this->service->unpost($this->supplierId, $tx, $this->meta());
        $events = $this->corrections->forEntity($this->supplierId, 'bank_transaction', $tx);
        self::assertSame('unpost', $events[0]['event_type']);
        self::assertSame($entry, (int) $this->suggestionRow($suggestion)['journal_entry_id']);

        $rejectTx = $this->transaction($statement, -1100, ['counterparty_account' => '901001']);
        $rejectSuggestion = (int) $this->service->handleTransaction($rejectTx, $this->userId)['suggestion_id'];
        $this->service->rejectSuggestion($this->supplierId, $rejectSuggestion, $this->meta(), 'nesedí');
        self::assertSame('reject', $this->corrections->forEntity($this->supplierId, 'bank_transaction', $rejectTx)[0]['event_type']);

        $manualTx = $this->transaction($statement, -1200, ['counterparty_account' => '901099']);
        $this->service->postManual($this->supplierId, $manualTx, [
            'debit_account_code' => '518', 'credit_account_code' => '221',
        ], $this->meta());
        self::assertSame('manual_post', $this->corrections->forEntity($this->supplierId, 'bank_transaction', $manualTx)[0]['event_type']);
        self::assertSame(1, $this->embeddingJobCount($manualTx));

        $other = $this->cloneSupplier('double_entry');
        self::assertSame([], $this->corrections->forEntity($other, 'bank_transaction', $tx));
    }

    private function embeddingJobCount(int $txId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM ai_jobs
              WHERE supplier_id=? AND job_type='embed_write' AND entity_type='bank_transaction' AND entity_id=?"
        );
        $stmt->execute([$this->supplierId, $txId]);
        return (int) $stmt->fetchColumn();
    }

    public function testEmbeddingWriterLabelsHumanDecisionAndRejectsForeignStatementOwner(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET ai_assist_enabled=1,ai_assist_scope='bank_tx',ai_pseudo_salt=? WHERE id=?"
        )->execute([random_bytes(32), $this->supplierId]);
        $gateway = new class implements EmbeddingGatewayInterface {
            public function embed(int $supplierId, array $texts): array
            {
                return [
                    'ok' => true,
                    'embeddings' => [array_fill(0, 1536, 0.01)],
                    'provider' => 'test',
                    'model' => 'test-embedding',
                    'region' => 'eu',
                ];
            }

            public function isAvailable(int $supplierId): bool
            {
                return true;
            }
        };
        $writer = new EmbeddingWriter(
            $this->db,
            new AiJobService($this->db),
            new AiPayloadSanitizer($this->db),
            $gateway,
        );
        $statement = $this->statement();
        $tx = $this->transaction($statement, -1234, ['counterparty_account' => '909001']);
        $this->service->postManual($this->supplierId, $tx, [
            'debit_account_code' => '518', 'credit_account_code' => '221',
        ], $this->meta());

        self::assertSame(['ok' => true], $writer->write($this->supplierId, 'bank_transaction', $tx));
        $label = $this->db->pdo()->prepare(
            "SELECT label_source FROM ai_embeddings
              WHERE supplier_id=? AND entity_type='bank_transaction' AND entity_id=?"
        );
        $label->execute([$this->supplierId, $tx]);
        self::assertSame('manual', $label->fetchColumn());

        $other = $this->cloneSupplier('double_entry');
        $this->db->pdo()->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')->execute([$other, $statement]);
        self::assertSame(
            ['ok' => false, 'error' => 'not_found'],
            $writer->write($this->supplierId, 'bank_transaction', $tx),
        );
    }

    public function testFiveCleanApprovalsCreateSinglePromotionCandidateThenHumanPromotesAndDemotes(): void
    {
        $ruleId = $this->rule([
            'name' => 'Povýšení', 'direction' => 'outgoing', 'counterparty_account' => '902001',
            'amount_min' => 100, 'amount_max' => 5000,
            'debit_account_code' => '518', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $statement = $this->statement();
        for ($i = 0; $i < 6; $i++) {
            $tx = $this->transaction($statement, -1000 - $i, ['counterparty_account' => '902001']);
            $sid = (int) $this->service->handleTransaction($tx, $this->userId)['suggestion_id'];
            $this->service->approveSuggestion($this->supplierId, $sid, $this->meta());
        }
        $rule = $this->ruleRow($ruleId);
        self::assertSame(6, $rule['approved_streak']);
        self::assertTrue(RulePromotionService::isCandidate($rule));
        $timeline = $this->corrections->forRule($this->supplierId, $ruleId);
        self::assertCount(1, array_filter($timeline, static fn (array $row): bool => $row['event_type'] === 'rule_promotion_suggested'));

        $promoted = $this->promotion->promote($this->supplierId, $ruleId, $this->userId);
        self::assertSame('auto', $promoted['mode']);
        $demoted = $this->promotion->demote($this->supplierId, $ruleId, $this->userId, 'manual');
        self::assertSame('suggest', $demoted['mode']);
        self::assertSame(0, $demoted['approved_streak']);
    }

    public function testManualForcedPromotionOfFreshRuleIsLoggedAndAutoPosts(): void
    {
        $ruleId = $this->rule([
            'name' => 'Vynucené', 'direction' => 'outgoing', 'counterparty_account' => '902101',
            'debit_account_code' => '518', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.rule.custom', 'auto', ?)
             ON DUPLICATE KEY UPDATE level=VALUES(level), updated_by=VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
        $fresh = $this->ruleRow($ruleId);
        self::assertFalse(RulePromotionService::isCandidate($fresh));
        self::assertNull($fresh['mode_set_manually_at']);

        $promoted = $this->promotion->promote($this->supplierId, $ruleId, $this->userId);
        self::assertSame('auto', $promoted['mode']);
        self::assertNotNull($promoted['mode_set_manually_at']);
        $events = array_values(array_filter(
            $this->corrections->forRule($this->supplierId, $ruleId),
            static fn (array $row): bool => $row['event_type'] === 'rule_promoted',
        ));
        self::assertCount(1, $events);
        self::assertSame('manual_forced', $events[0]['reason']);
        $log = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = 'bank_rule.promoted' AND entity_id = ?"
        );
        $log->execute([$this->supplierId, $ruleId]);
        $payload = json_decode((string) $log->fetchColumn(), true);
        self::assertTrue($payload['forced'] ?? null);
        self::assertSame(0, $payload['hit_count'] ?? null);

        $statement = $this->statement();
        $tx = $this->transaction($statement, -1500, ['counterparty_account' => '902101']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Ručně povýšené pravidlo účtuje samo i bez historie.');

        $demoted = $this->promotion->demote($this->supplierId, $ruleId, $this->userId, 'manual');
        self::assertSame('suggest', $demoted['mode']);
        self::assertNull($demoted['mode_set_manually_at']);
    }

    public function testCandidatePromotionHasNoForcedReasonAndAutoRuleWithoutManualFlagNeedsTrackRecord(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.rule.custom', 'auto', ?)
             ON DUPLICATE KEY UPDATE level=VALUES(level), updated_by=VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
        $templateRule = $this->rule([
            'name' => 'Šablona', 'direction' => 'outgoing', 'counterparty_account' => '902201',
            'debit_account_code' => '518', 'credit_account_code' => '221', 'mode' => 'auto',
        ]);
        $statement = $this->statement();
        $tx = $this->transaction($statement, -1500, ['counterparty_account' => '902201']);
        self::assertSame('suggested', $this->service->handleTransaction($tx, $this->userId)['action']);

        $candidate = $this->rule([
            'name' => 'Kandidát', 'direction' => 'outgoing', 'counterparty_account' => '902202',
            'amount_min' => 100, 'amount_max' => 5000,
            'debit_account_code' => '518', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $this->db->pdo()->prepare('UPDATE bank_posting_rules SET hit_count=5, approved_streak=5 WHERE id=?')
            ->execute([$candidate]);
        $this->promotion->promote($this->supplierId, $candidate, $this->userId);
        $events = array_values(array_filter(
            $this->corrections->forRule($this->supplierId, $candidate),
            static fn (array $row): bool => $row['event_type'] === 'rule_promoted',
        ));
        self::assertCount(1, $events);
        self::assertNull($events[0]['reason']);
        self::assertNotNull($this->ruleRow($candidate)['mode_set_manually_at']);
        self::assertNull($this->ruleRow($templateRule)['mode_set_manually_at']);
    }

    public function testUnpostDemotesAutoRuleWithoutChangingRejectMemory(): void
    {
        $ruleId = $this->rule([
            'name' => 'Storno', 'direction' => 'outgoing', 'counterparty_account' => '903001',
            'amount_min' => 100, 'amount_max' => 5000,
            'debit_account_code' => '518', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $statement = $this->statement();
        $tx = $this->transaction($statement, -1000, ['counterparty_account' => '903001']);
        $sid = (int) $this->service->handleTransaction($tx, $this->userId)['suggestion_id'];
        $this->service->approveSuggestion($this->supplierId, $sid, $this->meta());
        $this->ruleRepo->update($this->supplierId, $ruleId, ['mode' => 'auto']);
        $this->db->pdo()->prepare(
            'UPDATE bank_posting_rules SET rejected_streak=2, last_rejected_tx_id=777 WHERE id=?'
        )->execute([$ruleId]);

        $this->service->unpost($this->supplierId, $tx, $this->meta());
        $rule = $this->ruleRow($ruleId);
        self::assertSame('suggest', $rule['mode']);
        self::assertSame(0, $rule['approved_streak']);
        self::assertSame(2, $rule['rejected_streak']);
        self::assertSame(777, $rule['last_rejected_tx_id']);
    }

    public function testRuleMinerIsDryRunSafeSuggestOnlyAndIdempotent(): void
    {
        $statement = $this->statement();
        for ($i = 0; $i < 3; $i++) {
            $tx = $this->transaction($statement, -1000 - $i, [
                'counterparty_account' => '904001', 'counterparty_bank' => '0100',
                'counterparty_name' => 'Testovací dodavatel', 'description' => 'Pravidelná služba',
            ]);
            $this->service->postManual($this->supplierId, $tx, [
                'debit_account_code' => '518', 'credit_account_code' => '221',
            ], $this->meta());
        }
        $miner = $this->container->get(RuleMiner::class);
        $dry = $miner->run($this->supplierId, 180, false);
        self::assertSame(1, $dry['proposed']);
        self::assertSame(0, $dry['created']);

        $applied = $miner->run($this->supplierId, 180, true);
        self::assertSame(1, $applied['created']);
        $rule = $this->ruleRepo->find($this->supplierId, (int) $applied['proposals'][0]['rule_id']);
        self::assertSame('suggest', $rule['mode']);
        self::assertSame('rule_mined', $this->corrections->forRule($this->supplierId, (int) $rule['id'])[0]['event_type']);
        self::assertSame(0, $miner->run($this->supplierId, 180, true)['created']);
    }

    public function testRuleMinerTreatsRuleLimitedToStatementAccountAsCovering(): void
    {
        $statement = $this->statement();
        for ($i = 0; $i < 3; $i++) {
            $tx = $this->transaction($statement, -1000 - $i, [
                'counterparty_account' => '904011', 'counterparty_bank' => '0100',
                'counterparty_name' => 'Testovací dodavatel', 'description' => 'Pravidelná služba',
            ]);
            $this->service->postManual($this->supplierId, $tx, [
                'debit_account_code' => '518', 'credit_account_code' => '221',
            ], $this->meta());
        }
        $ruleId = $this->rule([
            'direction' => 'outgoing', 'message_contains' => 'pravidelna sluzba',
            'debit_account_code' => '501', 'credit_account_code' => '221',
        ]);
        $other = $this->db->pdo();
        $other->prepare(
            "INSERT INTO supplier_bank_accounts (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, '__TEST jiný účet', '3000000004', '0100', '0100', 'CZK', '3000000004', 'current', 'manual', 1)"
        )->execute([$this->supplierId]);
        $this->ruleRepo->update($this->supplierId, $ruleId, ['bank_account_id' => (int) $other->lastInsertId()]);
        $miner = $this->container->get(RuleMiner::class);
        self::assertSame(1, $miner->run($this->supplierId, 180, false)['proposed'], 'Pravidlo jiného vlastního účtu shluk nepokrývá.');

        $own = $this->db->pdo()->prepare(
            'SELECT a.id FROM supplier_bank_accounts a JOIN bank_statements s ON s.supplier_id = a.supplier_id
              WHERE s.id = ? AND a.supplier_id = ? AND a.account_number = s.account_number'
        );
        $own->execute([$statement, $this->supplierId]);
        $ownId = (int) $own->fetchColumn();
        self::assertGreaterThan(0, $ownId);
        $this->ruleRepo->update($this->supplierId, $ruleId, ['bank_account_id' => $ownId]);
        $report = $miner->run($this->supplierId, 180, false);
        self::assertSame(0, $report['proposed'], 'Pravidlo omezené na účet výpisu shluk pokrývá.');
        self::assertSame(1, $report['skip_reasons']['covered_by_rule'] ?? 0);
    }

    public function testRuleMinerProposesWhenSameRuleIsLimitedToAnotherAccount(): void
    {
        $statement = $this->statement();
        for ($i = 0; $i < 3; $i++) {
            $tx = $this->transaction($statement, -1000 - $i, [
                'counterparty_account' => '904012', 'counterparty_bank' => '0100',
                'counterparty_name' => 'Testovací dodavatel', 'description' => 'Servis',
            ]);
            $this->service->postManual($this->supplierId, $tx, ['debit_account_code' => '518', 'credit_account_code' => '221'], $this->meta());
        }
        $ruleId = $this->rule([
            'direction' => 'outgoing', 'counterparty_account' => '904012', 'counterparty_bank' => '0100',
            'debit_account_code' => '518', 'credit_account_code' => '221',
        ]);
        $miner = $this->container->get(RuleMiner::class);
        self::assertSame(1, $miner->run($this->supplierId, 180, false)['skip_reasons']['rule_exists'] ?? 0, 'Obecné pravidlo shluk už má.');

        $this->ruleRepo->update($this->supplierId, $ruleId, ['bank_account_id' => $this->otherOwnAccount()]);
        $report = $miner->run($this->supplierId, 180, false);
        self::assertSame(1, $report['proposed'], 'Pravidlo jiného vlastního účtu není pravidlo pro tento shluk: ' . json_encode($report['skip_reasons']));
    }

    public function testRuleMinerClusterSpanningTwoAccountsIsNotCoveredByOneAccountRule(): void
    {
        $statement = $this->statement();
        $other = $this->otherOwnAccount();
        $otherStatement = $this->statement('3000000004', '0100');
        foreach ([$statement, $statement, $otherStatement] as $i => $stmtId) {
            $tx = $this->transaction($stmtId, -1000 - $i, [
                'counterparty_account' => '904013', 'counterparty_bank' => '0100',
                'counterparty_name' => 'Testovací dodavatel', 'description' => 'Pravidelná služba',
            ]);
            $this->service->postManual($this->supplierId, $tx, ['debit_account_code' => '518', 'credit_account_code' => '221'], $this->meta());
        }
        $ruleId = $this->rule([
            'direction' => 'outgoing', 'message_contains' => 'pravidelna sluzba',
            'debit_account_code' => '501', 'credit_account_code' => '221',
        ]);
        $miner = $this->container->get(RuleMiner::class);
        self::assertSame(1, $miner->run($this->supplierId, 180, false)['skip_reasons']['covered_by_rule'] ?? 0, 'Obecné pravidlo pokryje pohyby obou účtů.');

        $own = $this->db->pdo()->prepare(
            'SELECT a.id FROM supplier_bank_accounts a JOIN bank_statements s ON s.supplier_id = a.supplier_id
              WHERE s.id = ? AND a.supplier_id = ? AND a.account_number = s.account_number'
        );
        $own->execute([$statement, $this->supplierId]);
        $ownId = (int) $own->fetchColumn();
        self::assertGreaterThan(0, $ownId);
        foreach ([$ownId => 'účtu prvních pohybů', $other => 'druhého účtu'] as $accountId => $label) {
            $this->ruleRepo->update($this->supplierId, $ruleId, ['bank_account_id' => $accountId]);
            self::assertSame(1, $miner->run($this->supplierId, 180, false)['proposed'], "Pravidlo {$label} shluk z obou účtů nepokryje.");
        }
    }

    private function otherOwnAccount(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO supplier_bank_accounts (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, '__TEST jiný účet', '3000000004', '0100', '0100', 'CZK', '3000000004', 'current', 'manual', 1)"
        )->execute([$this->supplierId]);
        return (int) $pdo->lastInsertId();
    }

    public function testLearningStatsAreTenantScoped(): void
    {
        $statement = $this->statement();
        $tx = $this->transaction($statement, -1000, ['counterparty_account' => '905001']);
        $this->service->postManual($this->supplierId, $tx, [
            'debit_account_code' => '518', 'credit_account_code' => '221',
        ], $this->meta());
        $stats = $this->container->get(LearningStatsProvider::class)->stats(
            $this->supplierId,
            date('Y-m-d', strtotime('-1 day')),
            date('Y-m-d', strtotime('+1 day')),
        );
        self::assertGreaterThanOrEqual(1, $stats['corrections']['by_event']['manual_post'] ?? 0);
        $other = $this->cloneSupplier('double_entry');
        $otherStats = $this->container->get(LearningStatsProvider::class)->stats(
            $other,
            date('Y-m-d', strtotime('-1 day')),
            date('Y-m-d', strtotime('+1 day')),
        );
        self::assertSame(0, $otherStats['corrections']['total']);
    }

    public function testNewerCorrectionsForOtherCounterpartiesDoNotHideApplicableCorrection(): void
    {
        $statement = $this->statement();
        $sourceTx = $this->transaction($statement, -700, [
            'counterparty_account' => '907001',
            'counterparty_bank' => '0100',
        ]);
        $this->service->postManual($this->supplierId, $sourceTx, [
            'debit_account_code' => '518', 'credit_account_code' => '221',
        ], $this->meta());

        for ($i = 0; $i < 6; $i++) {
            $noiseTx = $this->transaction($statement, -800 - $i, [
                'counterparty_account' => '90800' . $i,
                'counterparty_bank' => '0100',
            ]);
            $this->service->postManual($this->supplierId, $noiseTx, [
                'debit_account_code' => '501', 'credit_account_code' => '221',
            ], $this->meta());
        }

        $targetTx = $this->transaction($statement, -710, [
            'counterparty_account' => '000-907001',
            'counterparty_bank' => '0100',
        ]);
        $result = $this->service->handleTransaction($targetTx, $this->userId);
        self::assertSame('suggested', $result['action']);
        $suggestion = $this->suggestionRow((int) $result['suggestion_id']);
        self::assertSame('518', $suggestion['debit_account_code']);
        self::assertSame('corrected_from:#' . $sourceTx, $suggestion['note']);
    }

    public function testPromotionHistoryApiExposesCandidateAndHidesForeignRule(): void
    {
        $ruleId = $this->rule([
            'name' => 'API kandidát', 'direction' => 'outgoing', 'counterparty_account' => '906001',
            'amount_min' => 100, 'amount_max' => 5000,
            'debit_account_code' => '518', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $this->db->pdo()->prepare(
            'UPDATE bank_posting_rules SET hit_count=5, approved_streak=5, rejected_streak=0 WHERE id=?'
        )->execute([$ruleId]);
        $this->corrections->insert($this->supplierId, [
            'event_type' => 'approve_override', 'entity_type' => 'bank_transaction',
            'entity_id' => 900001, 'rule_id' => $ruleId,
            'suggested_debit' => '518', 'suggested_credit' => '221',
            'final_debit' => '501', 'final_credit' => '221',
        ]);
        for ($i = 0; $i < 101; $i++) {
            $this->corrections->insert($this->supplierId, [
                'event_type' => 'reject', 'entity_type' => 'bank_transaction',
                'entity_id' => 900100 + $i, 'rule_id' => $ruleId,
            ]);
        }
        $action = $this->container->get(BankPostingRuleAction::class);
        $list = $this->callAction($action, 'list', 'GET', 'accountant');
        self::assertSame(200, $list['status']);
        $candidate = array_values(array_filter($list['body']['items'], static fn (array $rule): bool => (int) $rule['id'] === $ruleId))[0];
        self::assertTrue($candidate['promotion_candidate']);

        $promote = $this->callAction($action, 'promote', 'POST', 'accountant', [], ['id' => (string) $ruleId]);
        self::assertSame(200, $promote['status']);
        self::assertSame('auto', $promote['body']['rule']['mode']);
        $history = $this->callAction($action, 'history', 'GET', 'accountant', [], ['id' => (string) $ruleId]);
        self::assertSame(200, $history['status']);
        self::assertSame('rule_promoted', $history['body']['events'][0]['event_type']);
        self::assertSame(103, $history['body']['total']);
        self::assertSame(25, count($history['body']['events']) + count($history['body']['corrections']));
        self::assertSame(1, $history['body']['page']);
        self::assertSame(25, $history['body']['per_page']);
        self::assertSame(1, $history['body']['stats']['override_count']);
        self::assertSame(0.0377, $history['body']['stats']['success_rate']);

        $other = $this->cloneSupplier('double_entry');
        $foreignId = $this->ruleRepo->insert($other, [
            'name' => 'Cizí', 'direction' => 'outgoing', 'counterparty_account' => '906099',
            'amount_min' => 100, 'amount_max' => 5000,
            'debit_account_code' => '518', 'credit_account_code' => '221', 'mode' => 'suggest',
        ], $this->userId);
        self::assertSame(404, $this->callAction($action, 'history', 'GET', 'accountant', [], ['id' => (string) $foreignId])['status']);
        self::assertSame(404, $this->callAction($action, 'promote', 'POST', 'accountant', [], ['id' => (string) $foreignId])['status']);
    }

    public function testBankStatementActionReceivesLearningServicesAndListsSuggestions(): void
    {
        $statementId = $this->statement();
        $action = $this->container->get(BankStatementAction::class);

        self::assertInstanceOf(
            MatchSuggestionService::class,
            (new \ReflectionProperty($action, 'matchV2'))->getValue($action),
        );
        self::assertInstanceOf(
            BankPostingService::class,
            (new \ReflectionProperty($action, 'bankPosting'))->getValue($action),
        );

        $response = $this->callAction(
            $action,
            'matchSuggestions',
            'GET',
            'accountant',
            [],
            ['id' => (string) $statementId],
        );
        self::assertSame(200, $response['status']);
        self::assertSame([], $response['body']['suggestions']);
    }
}
