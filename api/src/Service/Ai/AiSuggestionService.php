<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Service\Accounting\Bank\BankRuleMatcher;
use PDO;

final class AiSuggestionService
{
    /** Pod touto jistotou se automatický návrh kontace od LLM neukládá. */
    private const MIN_LLM_CONFIDENCE = 0.2;

    public function __construct(
        private readonly Connection $db,
        private readonly AiPayloadSanitizer $sanitizer,
        private readonly EmbeddingGatewayInterface $embeddings,
        private readonly KnnSuggester $knn,
        private readonly LlmClassifierInterface $classifier,
        private readonly BankPostingSuggestionRepository $bankSuggestions,
        private readonly AiSuggestionRepository $suggestions,
        private readonly AiKillSwitchService $killSwitch,
        private readonly AiJobService $jobs,
        private readonly BankPostingRuleRepository $bankRules,
        private readonly BankRuleMatcher $ruleMatcher,
    ) {}

    public function enqueueBank(int $supplierId, int $txId): bool
    {
        $knnAvailable = !$this->killSwitch->isMuted($supplierId, 'knn')
            && !$this->bankSuggestions->hasRejectedSource($supplierId, $txId, 'knn');
        $llmAvailable = !$this->killSwitch->isMuted($supplierId, 'llm')
            && !$this->bankSuggestions->hasRejectedSource($supplierId, $txId, 'llm');
        return $this->scopeEnabled($supplierId, 'bank_tx')
            && ($knnAvailable || $llmAvailable)
            && $this->jobs->enqueue($supplierId, 'classify_bank_tx', 'bank_transaction', $txId);
    }

    public function enqueuePurchase(int $supplierId, int $purchaseId): bool
    {
        return $this->scopeEnabled($supplierId, 'purchase_invoices')
            && $this->jobs->enqueue($supplierId, 'classify_purchase', 'purchase_invoice', $purchaseId);
    }

    /** @return array<string,mixed> */
    public function suggestBankNow(int $supplierId, int $txId, ?string $userQuery = null): array
    {
        if (!$this->scopeEnabled($supplierId, 'bank_tx')) {
            return ['ok' => false, 'error' => 'ai_disabled'];
        }
        $tx = $this->bankTransaction($supplierId, $txId);
        if ($tx === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        // Job ve frontě je jen fotka stavu v okamžiku zařazení. Než ho worker vezme,
        // může tx dávno zaúčtovat pravidlo/detektor/ruční kontace (u ostrých dat i o
        // dny později) — klasifikovat ji pak znamená utratit tokeny za návrh, který je
        // duplicitní vůči živému zápisu a v UI vypadá jako práce navíc. Obě kontroly
        // běží PŘED sanitizací i rezervací limitu, takže stálý job nestojí ani token.
        if ($this->hasLiveEntry($supplierId, $txId)) {
            return ['ok' => false, 'error' => 'already_posted'];
        }
        if ($this->matchesActiveRule($supplierId, $tx)) {
            return ['ok' => false, 'error' => 'rule_matched'];
        }
        // Pohyb, ke kterému existuje doklad (spárovaný, nebo navržený k párování), se účtuje
        // přes doklad. Kontace z popisu by tu byla jen šum, typicky náklad místo 321.
        if ($userQuery === null && $this->awaitsDocument($supplierId, $tx)) {
            return ['ok' => false, 'error' => 'document_match'];
        }
        $pending = $this->bankSuggestions->pendingForTx($supplierId, $txId);
        if ($pending !== null && $userQuery === null) {
            return ['ok' => true, 'suggestion' => $pending];
        }
        $knnRejected = $this->bankSuggestions->hasRejectedSource($supplierId, $txId, 'knn');
        $llmRejected = $this->bankSuggestions->hasRejectedSource($supplierId, $txId, 'llm');
        if ($userQuery === null && $knnRejected && $llmRejected) {
            return ['ok' => false, 'error' => 'previously_rejected'];
        }
        $sanitized = $this->sanitizer->sanitizeBankTx($supplierId, $tx, $userQuery);
        if (($sanitized['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => $sanitized['error'] ?? 'sanitize_failed'];
        }
        if (!$this->jobs->tryReserveClassification($supplierId)) {
            return ['ok' => false, 'error' => 'daily_limit'];
        }

        $result = null;
        $source = 'llm';
        if ($userQuery === null && !$knnRejected && !$this->killSwitch->isMuted($supplierId, 'knn')
            && $this->embeddings->isAvailable($supplierId) && $this->knn->isWarm($supplierId, 'bank_transaction')) {
            $embedded = $this->embeddings->embed($supplierId, [$sanitized['text']]);
            if (($embedded['ok'] ?? false) === true && is_array($embedded['embeddings'][0] ?? null)) {
                $vector = json_encode(array_map('floatval', $embedded['embeddings'][0]), JSON_THROW_ON_ERROR);
                $neighbor = $this->knn->suggestForVector($supplierId, 'bank_transaction', $vector);
                if ($neighbor !== null && $this->validBankPair($supplierId, $neighbor['debit'], $neighbor['credit'], (string) $sanitized['data']['direction'])) {
                    $source = 'knn';
                    $result = [
                        'ok' => true, 'debit' => $neighbor['debit'], 'credit' => $neighbor['credit'],
                        'confidence' => $neighbor['confidence'], 'reasoning' => 'looks_like:#' . $neighbor['neighbor_tx_id'],
                        'model' => $embedded['model'] ?? null, 'provider' => $embedded['provider'] ?? null,
                        'operation_type' => 'other',
                    ];
                }
            }
        }
        if ($result === null) {
            if ($userQuery === null && $llmRejected) {
                return ['ok' => false, 'error' => 'previously_rejected'];
            }
            if ($this->killSwitch->isMuted($supplierId, 'llm')) {
                return ['ok' => false, 'error' => $knnRejected ? 'previously_rejected' : 'source_muted'];
            }
            $result = $this->classifier->classifyBankTransaction($supplierId, $sanitized['data'], $this->fewShot($supplierId, 'bank_transaction'));
        }
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }
        // Model sám přiznal, že účel platby nepozná. Takový návrh účetní jen odmítá,
        // proto se automaticky neukládá; na výslovný dotaz se ukáže vždy.
        if ($userQuery === null && $source === 'llm' && (float) ($result['confidence'] ?? 0) < self::MIN_LLM_CONFIDENCE) {
            return ['ok' => false, 'error' => 'low_confidence'];
        }
        // §12a — i uživatelský dotaz „Zeptat se AI" se persistuje stejnou cestou (createIfNoPending),
        // aby existoval audit trail (co AI navrhla + na jaký dotaz v note). Idempotence pending návrhů
        // zůstává: když už pending existuje, createIfNoPending vrátí ten stávající (žádná duplicita).
        $note = $userQuery !== null
            ? mb_substr(trim($userQuery), 0, 500)
            : ($source === 'knn' ? $result['reasoning'] : null);
        $created = $this->bankSuggestions->createIfNoPending([
            'supplier_id' => $supplierId,
            'bank_transaction_id' => $txId,
            'rule_id' => null,
            'source' => $source,
            'debit_account_code' => $result['debit'],
            'credit_account_code' => $result['credit'],
            'amount' => round(abs((float) $tx['amount']), 2),
            'description' => (string) ($tx['description'] ?? $tx['counterparty_name'] ?? ''),
            'status' => 'pending',
            'note' => $note,
            'confidence' => min(0.40, (float) $result['confidence']),
            'operation_type' => 'ai.' . (string) ($result['operation_type'] ?? 'other'),
            'ai_reasoning' => $source === 'llm' ? (string) ($result['reasoning'] ?? '') : null,
            'ai_model' => $result['model'] ?? null,
            'ai_provider' => $result['provider'] ?? null,
            'ai_prompt_version' => 'clf-tx-v1',
        ]);
        if ($created['created']) {
            $this->metric($supplierId, $source, 'suggested_count', $this->estimatedCost($result));
        }
        $row = $this->bankSuggestions->find($supplierId, $created['id']);
        return ['ok' => true, 'suggestion' => $row];
    }

    /**
     * `$userQuery` = dotaz účetní ze zaúčtovacího popupu („platba za nájem serverovny").
     * Vlastní dotaz obchází čekající návrh i KNN: existující návrh je odpovědí na doklad
     * BEZ té souvislosti a soused z KNN se hledá podle podobnosti dokladů, kterou text
     * dotazu nezmění. Zeptat se a dostat zpátky starou odpověď by z tlačítka udělalo atrapu.
     *
     * @return array<string,mixed>
     */
    public function suggestPurchaseNow(int $supplierId, int $purchaseId, ?string $userQuery = null): array
    {
        if (!$this->scopeEnabled($supplierId, 'purchase_invoices')) {
            return ['ok' => false, 'error' => 'ai_disabled'];
        }
        $invoice = $this->purchaseInvoice($supplierId, $purchaseId);
        if ($invoice === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $pending = $this->suggestions->pendingForEntity($supplierId, 'purchase_invoice', $purchaseId);
        if ($pending !== null && $userQuery === null) {
            return ['ok' => true, 'suggestion' => $pending];
        }
        $sanitized = $this->sanitizer->sanitizePurchaseInvoice($supplierId, $invoice, $userQuery);
        if (($sanitized['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => $sanitized['error'] ?? 'sanitize_failed'];
        }
        if (!$this->jobs->tryReserveClassification($supplierId)) {
            return ['ok' => false, 'error' => 'daily_limit'];
        }
        $result = null;
        $source = 'llm';
        if ($userQuery === null && !$this->killSwitch->isMuted($supplierId, 'knn')
            && $this->embeddings->isAvailable($supplierId) && $this->knn->isWarm($supplierId, 'purchase_invoice')) {
            $embedded = $this->embeddings->embed($supplierId, [$sanitized['text']]);
            if (($embedded['ok'] ?? false) === true && is_array($embedded['embeddings'][0] ?? null)) {
                $vector = json_encode(array_map('floatval', $embedded['embeddings'][0]), JSON_THROW_ON_ERROR);
                $neighbor = $this->knn->suggestForVector($supplierId, 'purchase_invoice', $vector);
                if ($neighbor !== null && $this->validPurchaseDebit($supplierId, $neighbor['debit'])) {
                    $source = 'knn';
                    $result = [
                        'ok' => true, 'debit' => $neighbor['debit'], 'expense_category_id' => null,
                        'confidence' => $neighbor['confidence'], 'reasoning' => 'looks_like:#' . $neighbor['neighbor_tx_id'],
                        'model' => $embedded['model'] ?? null, 'provider' => $embedded['provider'] ?? null,
                    ];
                }
            }
        }
        $categories = $this->expenseCategories($supplierId);
        if ($result === null) {
            if ($this->killSwitch->isMuted($supplierId, 'llm')) {
                return ['ok' => false, 'error' => 'source_muted'];
            }
            $result = $this->classifier->suggestPurchasePosting($supplierId, $sanitized['data'], $this->fewShot($supplierId, 'purchase_invoice'), $categories);
        }
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }
        $current = $this->purchaseInvoice($supplierId, $purchaseId);
        if ($current === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        // Kontrola hlídá, jestli se doklad nezměnil, než odpověděl model. Dotaz proto musí
        // do obou stran srovnání — jinak by ho druhá strana neměla, hashe by se rozešly
        // a KAŽDÝ dotaz by skončil na „stale_document".
        $currentSanitized = $this->sanitizer->sanitizePurchaseInvoice($supplierId, $current, $userQuery);
        if (($currentSanitized['ok'] ?? false) !== true || !hash_equals(hash('sha256', $sanitized['text']), hash('sha256', $currentSanitized['text']))) {
            return ['ok' => false, 'error' => 'stale_document'];
        }
        // Vlastní dotaz nahrazuje dřívější čekající návrh — jinak by ho unikátní index
        // „jeden pending na doklad" odmítl a vrátila by se stará odpověď (viz repository).
        if ($userQuery !== null) {
            $this->suggestions->supersedePending($supplierId, 'purchase_invoice', $purchaseId);
        }
        $created = $this->suggestions->create([
            'supplier_id' => $supplierId, 'entity_type' => 'purchase_invoice', 'entity_id' => $purchaseId,
            'source' => $source, 'payload' => [
                'debit_account_code' => $result['debit'], 'expense_category_id' => $result['expense_category_id'] ?? null,
            ],
            'input_hash' => hash('sha256', $currentSanitized['text']),
            'confidence' => $result['confidence'], 'model' => $result['model'] ?? null,
            'provider' => $result['provider'] ?? null, 'prompt_version' => $source === 'llm' ? 'clf-pf-v1' : null,
            // Dotaz účetní se ukládá do zdůvodnění, aby v auditní stopě zůstalo, NA CO
            // model odpovídal — bez toho je návrh s vlastním dotazem zpětně nečitelný.
            'reasoning' => $userQuery !== null
                ? mb_substr('dotaz: ' . trim($userQuery) . ' | ' . (string) ($result['reasoning'] ?? ''), 0, 500)
                : ($result['reasoning'] ?? null),
        ]);
        if ($created['created']) {
            $this->metric($supplierId, $source, 'suggested_count', $this->estimatedCost($result));
        }
        return ['ok' => true, 'suggestion' => $this->suggestions->find($supplierId, $created['id'])];
    }

    public function purchaseInputHash(int $supplierId, int $purchaseId): ?string
    {
        $invoice = $this->purchaseInvoice($supplierId, $purchaseId, false);
        if ($invoice === null) {
            return null;
        }
        $sanitized = $this->sanitizer->sanitizePurchaseInvoice($supplierId, $invoice);
        return ($sanitized['ok'] ?? false) === true ? hash('sha256', $sanitized['text']) : null;
    }

    public function invalidatePurchase(int $supplierId, int $purchaseId): void
    {
        $this->suggestions->expireForEntity($supplierId, 'purchase_invoice', $purchaseId);
    }

    public function scopeEnabled(int $supplierId, string $scope): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT ai_assist_enabled,ai_assist_scope FROM supplier WHERE id=?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !(bool) $row['ai_assist_enabled']) {
            return false;
        }
        return in_array($scope, array_filter(explode(',', (string) $row['ai_assist_scope'])), true);
    }

    public function metric(int $supplierId, string $source, string $column, float $cost = 0.0): void
    {
        if (!in_array($source, ['knn', 'llm', 'anomaly'], true)
            || !in_array($column, ['suggested_count', 'accepted_count', 'overridden_count', 'rejected_count'], true)) {
            return;
        }
        $this->db->pdo()->prepare(
            "INSERT INTO ai_metrics (supplier_id,source,metric_date,{$column},cost_czk)
             VALUES (?,?,CURDATE(),1,?)
             ON DUPLICATE KEY UPDATE {$column}={$column}+1,cost_czk=cost_czk+VALUES(cost_czk)"
        )->execute([$supplierId, $source, $cost]);
    }

    /** @return list<array<string,string>> */
    private function fewShot(int $supplierId, string $entityType): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT sanitized_text,label_debit,label_credit FROM ai_embeddings
              WHERE supplier_id=? AND entity_type=? AND label_debit IS NOT NULL ORDER BY created_at DESC LIMIT 5'
        );
        $stmt->execute([$supplierId, $entityType]);
        return array_map(static fn (array $row): array => [
            'text' => (string) $row['sanitized_text'], 'debit' => (string) $row['label_debit'],
            'credit' => (string) ($row['label_credit'] ?? ''),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Má tx živý (nestornovaný) zápis v deníku? Stejná podmínka jako guard
     * `already_posted` v BankPostingService::handleTransactionInTransaction().
     */
    /**
     * Čeká pohyb na doklad? Už spárovaný, nebo k němu párování našlo kandidáta ke kontrole
     * (důvod `*_requires_review`), případně je otevřený návrh párování.
     *
     * @param array<string,mixed> $tx
     */
    private function awaitsDocument(int $supplierId, array $tx): bool
    {
        if ((string) ($tx['match_status'] ?? 'unmatched') !== 'unmatched'
            || str_ends_with((string) ($tx['match_reason'] ?? ''), '_requires_review')) {
            return true;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ?
             UNION ALL
             SELECT 1 FROM bank_match_suggestions WHERE supplier_id = ? AND bank_transaction_id = ? AND status = 'pending'
             LIMIT 1"
        );
        $stmt->execute([$supplierId, (int) $tx['id'], $supplierId, (int) $tx['id']]);
        return $stmt->fetchColumn() !== false;
    }

    private function hasLiveEntry(int $supplierId, int $txId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries
              WHERE supplier_id=? AND source_type='bank' AND source_id=? AND reversed_by IS NULL
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $txId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Sedí na tx některé aktivní pravidlo? Pravidlo mohlo vzniknout až po zařazení
     * jobu do fronty (typicky ho uživatel založil právě proto, aby AI tenhle druh
     * pohybu nemusela řešit) — deterministická kontace má vždy přednost před LLM.
     *
     * @param array<string,mixed> $tx
     */
    private function matchesActiveRule(int $supplierId, array $tx): bool
    {
        $amount = (float) $tx['amount'];
        if ($amount === 0.0) {
            return false;
        }
        $currency = strtoupper((string) ($tx['currency'] ?? $tx['statement_currency'] ?? 'CZK'));
        $matchTx = [
            'amount'               => $amount,
            'variable_symbol'      => isset($tx['variable_symbol']) ? (string) $tx['variable_symbol'] : null,
            'counterparty_account' => isset($tx['counterparty_account']) ? (string) $tx['counterparty_account'] : null,
            'counterparty_bank'    => isset($tx['counterparty_bank']) ? (string) $tx['counterparty_bank'] : null,
            'description'          => isset($tx['description']) ? (string) $tx['description'] : null,
            'counterparty_name'    => isset($tx['counterparty_name']) ? (string) $tx['counterparty_name'] : null,
            'card_last4'           => isset($tx['card_last4']) ? (string) $tx['card_last4'] : null,
        ];
        $matchTx = (new \MyInvoice\Service\Accounting\Bank\StatementOwnAccount($this->db))->withOwnAccount($supplierId, $matchTx, $tx);
        foreach ($this->bankRules->findActive($supplierId, $amount > 0 ? 'incoming' : 'outgoing') as $rule) {
            if (strtoupper((string) ($rule['applies_currency'] ?? 'CZK')) !== $currency) {
                continue;
            }
            if ($this->bankSuggestions->hasRejected($supplierId, (int) $tx['id'], (int) $rule['id'])) {
                continue; // M3a — odmítnutou dvojici (tx, pravidlo) nenabízet, AI smí zaskočit
            }
            if ($this->ruleMatcher->matching($rule, $matchTx)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function bankTransaction(int $supplierId, int $txId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.*,bs.account_number recipient_account,bs.bank_code recipient_bank,bs.currency statement_currency
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id
              WHERE bt.id=? AND bs.supplier_id=?'
        );
        $stmt->execute([$txId, $supplierId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($tx) ? $tx : null;
    }

    private function purchaseInvoice(int $supplierId, int $purchaseId, bool $draftOnly = true): ?array
    {
        $statusClause = $draftOnly ? ' AND pi.status="draft"' : ' AND pi.status<>"cancelled"';
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.*,c.company_name vendor_name,cur.code currency,
                    CONCAT_WS(" ",pi.note_above_items,pi.note_below_items) description
               FROM purchase_invoices pi
               LEFT JOIN clients c ON c.id=pi.vendor_id AND c.supplier_id=pi.supplier_id
               LEFT JOIN currencies cur ON cur.id=pi.currency_id AND cur.supplier_id=pi.supplier_id
              WHERE pi.supplier_id=? AND pi.id=?' . $statusClause . ' AND COALESCE(pi.is_fixed_asset,0)=0
                AND NOT EXISTS (
                    SELECT 1 FROM purchase_invoice_vat_allocations piva
                     WHERE piva.supplier_id=pi.supplier_id AND piva.purchase_invoice_id=pi.id
                )'
        );
        $stmt->execute([$supplierId, $purchaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function validBankPair(int $supplierId, string $debit, string $credit, string $direction): bool
    {
        if ((str_starts_with($debit, '221') === str_starts_with($credit, '221'))
            || ($direction === 'in' && !str_starts_with($debit, '221'))
            || ($direction === 'out' && !str_starts_with($credit, '221'))
            || preg_match('/^(?:311|321|314|324|325|33|34)/', $debit) === 1
            || preg_match('/^(?:311|321|314|324|325|33|34)/', $credit) === 1) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id=? AND account_code IN (?,?) AND is_active=1'
        );
        $stmt->execute([$supplierId, $debit, $credit]);
        return (int) $stmt->fetchColumn() === ($debit === $credit ? 1 : 2);
    }

    private function validPurchaseDebit(int $supplierId, string $debit): bool
    {
        if (\MyInvoice\Service\Accounting\SettlementAccounts::isSettlement($debit)) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM chart_of_accounts WHERE supplier_id=? AND account_code=? AND is_active=1'
        );
        $stmt->execute([$supplierId, $debit]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    private function expenseCategories(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id,code FROM expense_categories WHERE supplier_id=? AND archived=0 ORDER BY code');
        $stmt->execute([$supplierId]);
        $categories = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $safeCode = $this->sanitizer->sanitizeUserContext((string) $row['code']);
            if ($safeCode !== '') {
                $categories[] = ['id' => (int) $row['id'], 'code' => $safeCode];
            }
        }
        return $categories;
    }

    /** @param array<string,mixed> $result */
    private function estimatedCost(array $result): float
    {
        $input = (int) ($result['usage']['input_tokens'] ?? 0);
        $output = (int) ($result['usage']['output_tokens'] ?? 0);
        return round(($input * 0.000004 + $output * 0.00002) * 23.5, 4);
    }
}
