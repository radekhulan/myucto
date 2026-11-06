<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Bank\BankRuleMatcher;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\BankTransactionPostingScope;
use PDO;

/**
 * Návrhy zaúčtování pohybů bez zápisu v deníku POHODY, které nejsou úhradou faktury
 * ({@see UnbookedBankPayments}). Nic se tu neúčtuje, rozhoduje účetní.
 *
 * Kontace se odvodí z už zaúčtovaných pohybů téže firmy se stejným směrem a stejným protiúčtem;
 * kde účet na důkaz nestačí, se stejným partnerem. Musí se shodovat aspoň {@see MIN_MATCHES}
 * pohybů a aspoň {@see MIN_SHARE_PERCENT} % z nich, složený zápis se počítá jako neshoda.
 *
 * Návrh je návrhové pravidlo (`mode = suggest`) a čekající návrh pohybu v tomtéž mechanismu,
 * který používá běžná automatika. Pravidlo v tomto režimu po obnovení automatiky nezaúčtuje
 * nic samo ({@see \MyInvoice\Service\Accounting\AutoPostingPolicyService::decide()}) a bez
 * rozsahu částky ho nenabídne ani povýšení na automatiku
 * ({@see \MyInvoice\Service\Accounting\Learning\RulePromotionService::isCandidate()}).
 *
 * Pravidla eviduje mapa převodu ({@see PohodaImportRepository::KIND_BANK_PROPOSAL_RULE}), takže
 * opakovaný převod je nezdvojí a nevrací, co účetní mezitím smazala nebo vypnula.
 */
final class UnbookedBankProposals
{
    private const STEP = DocumentLinker::STEP_PAYMENTS;
    private const MIN_MATCHES = 3;
    private const MIN_SHARE_PERCENT = 90;
    /** Kratší jméno by v `message_contains` chytalo cizí pohyby. */
    private const MIN_NAME_LENGTH = 4;
    private const NAME_LENGTH = 120;
    private const COMPOUND = '*';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly DocumentLinker $linker,
        private readonly BankPostingService $bankPosting,
        private readonly BankPostingRuleRepository $rules,
        private readonly BankPostingSuggestionRepository $suggestions,
        private readonly BankRuleMatcher $matcher,
        private readonly ActivityLogger $activity,
    ) {}

    public function propose(PohodaContext $ctx): void
    {
        $ourRules = array_fill_keys($this->map->targets($ctx->supplierId, PohodaImportRepository::KIND_BANK_PROPOSAL_RULE), true);
        $this->retire($ctx, array_keys($ourRules));

        $unbooked = $this->linker->bankBooking($ctx)['unbooked'];
        $candidates = $unbooked === [] ? [] : $this->candidates($ctx->supplierId, $unbooked);
        if ($candidates !== []) {
            $clusters = $this->clusters($ctx, $candidates, $this->history($ctx->supplierId), $ourRules);
            foreach ($clusters as $key => $cluster) {
                $this->proposeCluster($ctx, (string) $key, $cluster);
            }
        }
        $this->report($ctx);
    }

    /**
     * Čekající návrh pohybu, který mezitím zaúčtovala POHODA nebo se spároval s fakturou,
     * už neplatí: jeho schválení by skončilo chybou nebo by úhradu zaúčtovalo dvakrát.
     *
     * @param list<int> $ruleIds
     */
    private function retire(PohodaContext $ctx, array $ruleIds): void
    {
        if ($ruleIds === []) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.bank_transaction_id
               FROM bank_posting_suggestions s
               JOIN bank_transactions t ON t.id = s.bank_transaction_id
              WHERE s.supplier_id = ? AND s.status = 'pending' AND s.rule_id IN (" . implode(',', array_fill(0, count($ruleIds), '?')) . ")
                AND (t.match_status <> 'unmatched' OR " . BankTransactionPostingScope::existsSql($ctx->supplierId, 's.bank_transaction_id') . ')'
        );
        $stmt->execute(array_merge([$ctx->supplierId], $ruleIds));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $txId) {
            $this->suggestions->supersedePendingForTx($ctx->supplierId, (int) $txId, 'pohoda_booked');
            $ctx->protocol->count(self::STEP, 'unbooked_proposal_retired');
        }
    }

    /**
     * Pohyby, které o návrh můžou žádat: v Kč, bez zápisu a bez vazby na doklad. Nejistá shoda
     * s fakturou má přednost (návrh párování), odmítnutý nebo čekající návrh se neopakuje.
     *
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function candidates(int $supplierId, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT t.id, t.amount, t.variable_symbol, t.counterparty_account, t.counterparty_bank, t.counterparty_name, t.description,
                        s.account_number AS recipient_account, s.bank_code AS recipient_bank
                   FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                  WHERE s.supplier_id = ? AND t.source = 'statement' AND t.match_status = 'unmatched' AND t.amount <> 0
                    AND t.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    AND COALESCE(NULLIF(t.currency, ''), NULLIF(s.currency, ''), 'CZK') = 'CZK'
                    AND " . BankTransactionPostingScope::noMigrationReviewSql('t') . '
                    AND NOT ' . BankTransactionPostingScope::existsSql($supplierId, 't.id') . "
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.supplier_id = s.supplier_id AND pm.bank_transaction_id = t.id)
                    AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.supplier_id = s.supplier_id AND ip.bank_transaction_id = t.id)
                    AND NOT EXISTS (SELECT 1 FROM bank_match_suggestions m WHERE m.supplier_id = s.supplier_id AND m.bank_transaction_id = t.id AND m.status = 'pending')
                    AND NOT EXISTS (SELECT 1 FROM bank_posting_suggestions p WHERE p.supplier_id = s.supplier_id AND p.bank_transaction_id = t.id
                                     AND p.status IN ('pending', 'needs_input', 'blocked', 'rejected'))
                  ORDER BY t.posted_at, t.id"
            );
            $stmt->execute(array_merge([$supplierId], $chunk));
            array_push($out, ...$stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        return $out;
    }

    /**
     * Kontace živých bankovních zápisů firmy po protiúčtech a partnerech. Čte se po řádcích
     * a v paměti zůstávají jen čítače.
     *
     * @return array{account:array<string,array<string,int>>,name:array<string,array<string,int>>}
     */
    private function history(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.amount, t.counterparty_account, t.counterparty_bank, t.counterparty_name,
                    COUNT(DISTINCT CASE WHEN l.side = 'debit' THEN a.account_code END) AS debit_accounts,
                    COUNT(DISTINCT CASE WHEN l.side = 'credit' THEN a.account_code END) AS credit_accounts,
                    MIN(CASE WHEN l.side = 'debit' THEN a.account_code END) AS debit_code,
                    MIN(CASE WHEN l.side = 'credit' THEN a.account_code END) AS credit_code
               FROM journal_entries e
               JOIN bank_transactions t ON t.id = e.source_id
               JOIN bank_statements s ON s.id = t.statement_id AND s.supplier_id = e.supplier_id
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.source_type = 'bank' AND e.reversed_by IS NULL AND e.posted_at IS NOT NULL
                AND t.source = 'statement' AND t.amount <> 0
              GROUP BY e.id"
        );
        $stmt->execute([$supplierId]);
        $history = ['account' => [], 'name' => []];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $kontace = self::COMPOUND;
            if ((int) $row['debit_accounts'] === 1 && (int) $row['credit_accounts'] === 1) {
                $kontace = implode('/', BankPostingService::genericBankLeg((string) $row['debit_code'], (string) $row['credit_code']));
            }
            $accountKey = self::accountKey($row);
            if ($accountKey !== null) {
                $history['account'][$accountKey][$kontace] = ($history['account'][$accountKey][$kontace] ?? 0) + 1;
            }
            $nameKey = self::nameKey($row);
            if ($nameKey !== null) {
                $history['name'][$nameKey][$kontace] = ($history['name'][$nameKey][$kontace] ?? 0) + 1;
            }
        }
        return $history;
    }

    /**
     * Rozřadí pohyby podle protiúčtu nebo partnera a vybere kontaci. Účet má přednost: má-li
     * dost historie a ta si odporuje, jméno už návrh nezachrání. Pohyb, který chytá jiné aktivní
     * pravidlo firmy, zůstává na něm.
     *
     * @param list<array<string,mixed>> $candidates
     * @param array{account:array<string,array<string,int>>,name:array<string,array<string,int>>} $history
     * @param array<int,true> $ourRules
     * @return array<string,array{debit:string,credit:string,basis:string,matches:int,total:int,tx:array<string,mixed>,txs:list<int>}>
     */
    private function clusters(PohodaContext $ctx, array $candidates, array $history, array $ourRules): array
    {
        $ownAccounts = new \MyInvoice\Service\Accounting\Bank\StatementOwnAccount($this->db);
        $activeRules = [];
        $clusters = [];
        foreach ($candidates as $tx) {
            $accountKey = self::accountKey($tx);
            $nameKey = self::nameKey($tx);
            $accountEvidence = $accountKey !== null ? ($history['account'][$accountKey] ?? []) : [];
            if (array_sum($accountEvidence) >= self::MIN_MATCHES) {
                [$key, $basis, $evidence] = [$accountKey, 'account', $accountEvidence];
            } elseif ($nameKey !== null && array_sum($history['name'][$nameKey] ?? []) >= self::MIN_MATCHES) {
                [$key, $basis, $evidence] = [$nameKey, 'name', $history['name'][$nameKey]];
            } else {
                $ctx->protocol->count(self::STEP, 'unbooked_proposal_no_history');
                continue;
            }
            $incoming = (float) $tx['amount'] > 0;
            $top = self::consensus($evidence);
            if ($top === null) {
                $ctx->protocol->count(self::STEP, 'unbooked_proposal_inconsistent');
                continue;
            }
            [$debit, $credit] = explode('/', $top, 2);
            if (!BankPostingService::isProposableKontace($incoming, $debit, $credit)) {
                $ctx->protocol->count(self::STEP, 'unbooked_proposal_saldo');
                continue;
            }
            $direction = $incoming ? 'incoming' : 'outgoing';
            $activeRules[$direction] ??= $this->rules->findActive($ctx->supplierId, $direction);
            foreach ($activeRules[$direction] as $rule) {
                if (!isset($ourRules[$rule['id']]) && strtoupper((string) $rule['applies_currency']) === 'CZK'
                    && $this->matcher->matching($rule, $ownAccounts->withOwnAccount($ctx->supplierId, $tx, $tx))) {
                    $ctx->protocol->count(self::STEP, 'unbooked_proposal_covered_by_rule');
                    continue 2;
                }
            }
            $clusters[$key] ??= [
                'debit' => $debit,
                'credit' => $credit,
                'basis' => $basis,
                'matches' => (int) $evidence[$top],
                'total' => (int) array_sum($evidence),
                'tx' => $tx,
                'txs' => [],
            ];
            $clusters[$key]['txs'][] = (int) $tx['id'];
        }
        return $clusters;
    }

    /**
     * Pravidlo uživatele nepřepisuje: smazané, vypnuté nebo přepnuté pravidlo a jinou kontaci
     * nechává být. Nové pravidlo, ke kterému nakonec žádný návrh nevznikl (vlastní převod,
     * mezičlen karty), se nezakládá.
     *
     * @param array{debit:string,credit:string,basis:string,matches:int,total:int,tx:array<string,mixed>,txs:list<int>} $cluster
     */
    private function proposeCluster(PohodaContext $ctx, string $key, array $cluster): void
    {
        $ruleId = $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_BANK_PROPOSAL_RULE, $key);
        $isNew = $ruleId === null;
        if ($isNew) {
            $ruleId = $this->rules->insert($ctx->supplierId, $this->ruleData($cluster), $ctx->userOrNull());
        } else {
            $rule = $this->rules->find($ctx->supplierId, $ruleId);
            if ($rule === null || !$rule['is_active'] || (string) $rule['mode'] !== 'suggest'
                || (string) $rule['debit_account_code'] !== $cluster['debit'] || (string) $rule['credit_account_code'] !== $cluster['credit']) {
                $ctx->protocol->count(self::STEP, 'unbooked_proposal_rule_changed', count($cluster['txs']));
                return;
            }
        }

        $created = 0;
        foreach ($cluster['txs'] as $txId) {
            $res = $this->bankPosting->proposeRuleForMigration($ctx->supplierId, $txId, $ruleId);
            if (($res['action'] ?? '') === 'suggested' && !empty($res['created'])) {
                $created++;
            } else {
                $ctx->protocol->count(self::STEP, 'unbooked_proposal_skipped');
            }
        }
        if ($isNew && $created === 0) {
            $this->rules->delete($ctx->supplierId, $ruleId);
            return;
        }
        if ($isNew) {
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_BANK_PROPOSAL_RULE, $key, $ruleId, $ctx->runId);
            $this->activity->log('bank_rule.mined', $ctx->userOrNull(), 'bank_posting_rule', $ruleId, [
                'source' => 'pohoda', 'basis' => $cluster['basis'], 'matches' => $cluster['matches'], 'total' => $cluster['total'],
            ], supplierId: $ctx->supplierId);
            $ctx->protocol->count(self::STEP, 'unbooked_proposal_rules');
        }
        $ctx->protocol->count(self::STEP, 'unbooked_proposed', $created);
    }

    /**
     * Bez rozsahu částky: pravidlo tak návrh nepodmiňuje výší platby a automatika ho nenabídne
     * k povýšení. Popis zůstává prázdný, zápis po schválení nese popis pohybu.
     *
     * @param array{debit:string,credit:string,basis:string,tx:array<string,mixed>} $cluster
     * @return array<string,mixed>
     */
    private function ruleData(array $cluster): array
    {
        $tx = $cluster['tx'];
        $byAccount = $cluster['basis'] === 'account';
        $partner = trim((string) ($tx['counterparty_name'] ?? ''));
        $account = AccountNumberNormalizer::canonical((string) ($tx['counterparty_account'] ?? ''));
        return [
            'name' => mb_substr('POHODA: ' . ($partner !== '' ? $partner : (string) $account), 0, self::NAME_LENGTH),
            'direction' => (float) $tx['amount'] > 0 ? 'incoming' : 'outgoing',
            'counterparty_account' => $byAccount ? $account : null,
            'counterparty_bank' => $byAccount ? AccountNumberNormalizer::canonicalBankCode((string) ($tx['counterparty_bank'] ?? '')) : null,
            'message_contains' => $byAccount ? null : mb_substr(BankMessageNormalizer::normalize($partner), 0, self::NAME_LENGTH),
            'debit_account_code' => $cluster['debit'],
            'credit_account_code' => $cluster['credit'],
            'mode' => 'suggest',
            'priority' => 100,
            'applies_currency' => 'CZK',
        ];
    }

    private function report(PohodaContext $ctx): void
    {
        $ruleIds = $this->map->targets($ctx->supplierId, PohodaImportRepository::KIND_BANK_PROPOSAL_RULE);
        if ($ruleIds === []) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*), COUNT(DISTINCT rule_id), COALESCE(SUM(amount), 0) FROM bank_posting_suggestions
              WHERE supplier_id = ? AND status = 'pending' AND rule_id IN (" . implode(',', array_fill(0, count($ruleIds), '?')) . ')'
        );
        $stmt->execute(array_merge([$ctx->supplierId], $ruleIds));
        [$pending, $rules, $amount] = $stmt->fetch(PDO::FETCH_NUM);
        if ((int) $pending === 0) {
            return;
        }
        $ctx->protocol->info(self::STEP, 'unbooked_proposals', sprintf(
            '%d pohybů bez zápisu v deníku POHODY (%s Kč) má návrh zaúčtování podle %d návrhových pravidel, kontace je odvozená z ostatních pohybů téhož protiúčtu nebo partnera. Nic se nezaúčtovalo: návrhy schvalte nebo odmítněte v Bance na záložce K zaúčtování.',
            (int) $pending, number_format((float) $amount, 2, ',', ' '), (int) $rules,
        ), ['pending' => (int) $pending, 'rules' => (int) $rules, 'amount' => round((float) $amount, 2)]);
    }

    /**
     * Převažující kontace, nebo null, když nemá dost shod. Složený zápis návrh nikdy nedá.
     *
     * @param array<string,int> $evidence
     */
    private static function consensus(array $evidence): ?string
    {
        arsort($evidence);
        $top = (string) array_key_first($evidence);
        $matches = (int) reset($evidence);
        if ($top === self::COMPOUND || $matches < self::MIN_MATCHES
            || $matches * 100 < array_sum($evidence) * self::MIN_SHARE_PERCENT) {
            return null;
        }
        return $top;
    }

    /** @param array<string,mixed> $row */
    private static function accountKey(array $row): ?string
    {
        $account = AccountNumberNormalizer::canonical((string) ($row['counterparty_account'] ?? ''));
        if ($account === null) {
            return null;
        }
        $bank = AccountNumberNormalizer::canonicalBankCode((string) ($row['counterparty_bank'] ?? '')) ?? '';
        return self::direction($row) . '|account|' . $account . '|' . $bank;
    }

    /** @param array<string,mixed> $row */
    private static function nameKey(array $row): ?string
    {
        $name = BankMessageNormalizer::normalize((string) ($row['counterparty_name'] ?? ''));
        return strlen($name) >= self::MIN_NAME_LENGTH ? self::direction($row) . '|name|' . $name : null;
    }

    /** @param array<string,mixed> $row */
    private static function direction(array $row): string
    {
        return (float) $row['amount'] > 0 ? 'in' : 'out';
    }
}
