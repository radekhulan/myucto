<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class EmbeddingWriter
{
    public function __construct(
        private readonly Connection $db,
        private readonly AiJobService $jobs,
        private readonly AiPayloadSanitizer $sanitizer,
        private readonly EmbeddingGatewayInterface $gateway,
    ) {}

    public function enqueueFromDecision(int $supplierId, string $entityType, int $entityId): void
    {
        $this->jobs->enqueue($supplierId, 'embed_write', $entityType, $entityId);
    }

    /** @return array<string,mixed> */
    public function write(int $supplierId, string $entityType, int $entityId): array
    {
        if (!$this->scopeEnabled($supplierId, $entityType)) {
            return ['ok' => false, 'error' => 'ai_disabled'];
        }
        $loaded = $entityType === 'bank_transaction'
            ? $this->bankEntity($supplierId, $entityId)
            : ($entityType === 'purchase_invoice' ? $this->purchaseEntity($supplierId, $entityId) : null);
        if ($loaded === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $sanitized = $entityType === 'bank_transaction'
            ? $this->sanitizer->sanitizeBankTx($supplierId, $loaded['entity'])
            : $this->sanitizer->sanitizePurchaseInvoice($supplierId, $loaded['entity']);
        if (($sanitized['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => $sanitized['error'] ?? 'sanitize_failed'];
        }
        $hash = hash('sha256', $sanitized['text']);
        $existing = $this->db->pdo()->prepare('SELECT content_hash FROM ai_embeddings WHERE supplier_id=? AND entity_type=? AND entity_id=?');
        $existing->execute([$supplierId, $entityType, $entityId]);
        $oldHash = $existing->fetchColumn();
        if (is_string($oldHash) && hash_equals($oldHash, $hash)) {
            $this->db->pdo()->prepare(
                'UPDATE ai_embeddings SET label_debit=?,label_credit=?,label_source=? WHERE supplier_id=? AND entity_type=? AND entity_id=?'
            )->execute([$loaded['debit'], $loaded['credit'], $loaded['label_source'], $supplierId, $entityType, $entityId]);
            return ['ok' => true];
        }
        $embedded = $this->gateway->embed($supplierId, [$sanitized['text']]);
        if (($embedded['ok'] ?? false) !== true || !is_array($embedded['embeddings'][0] ?? null)) {
            return ($embedded['ok'] ?? false) === true
                ? ['ok' => false, 'error' => 'invalid_embedding_response']
                : $embedded;
        }
        $vector = json_encode(array_map('floatval', $embedded['embeddings'][0]), JSON_THROW_ON_ERROR);
        $this->db->pdo()->prepare(
            'INSERT INTO ai_embeddings
                (supplier_id,entity_type,entity_id,content_hash,sanitized_text,embedding,label_debit,label_credit,label_source,embed_provider,embed_model,embed_region)
             VALUES (?,?,?,?,?,VEC_FromText(?),?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE content_hash=VALUES(content_hash),sanitized_text=VALUES(sanitized_text),
                embedding=VALUES(embedding),label_debit=VALUES(label_debit),label_credit=VALUES(label_credit),
                label_source=VALUES(label_source),embed_provider=VALUES(embed_provider),embed_model=VALUES(embed_model),embed_region=VALUES(embed_region)'
        )->execute([
            $supplierId, $entityType, $entityId, $hash, $sanitized['text'], $vector,
            $loaded['debit'], $loaded['credit'], $loaded['label_source'],
            $embedded['provider'], $embedded['model'], $embedded['region'],
        ]);
        return ['ok' => true];
    }

    /** @return array{entity:array<string,mixed>,debit:string,credit:string,label_source:string}|null */
    private function bankEntity(int $supplierId, int $entityId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.*,bs.account_number recipient_account,bs.bank_code recipient_bank,bs.currency statement_currency,
                    MAX(CASE WHEN jel.side='debit' THEN coa.account_code END) debit_code,
                    MAX(CASE WHEN jel.side='credit' THEN coa.account_code END) credit_code,
                    MAX(bps.debit_account_code) suggested_debit,
                    MAX(bps.credit_account_code) suggested_credit,
                    COUNT(DISTINCT jel.id) line_count
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id
               JOIN journal_entries je ON je.supplier_id=? AND je.source_type='bank' AND je.source_id=bt.id AND je.reversed_by IS NULL
               JOIN journal_entry_lines jel ON jel.entry_id=je.id JOIN chart_of_accounts coa ON coa.id=jel.account_id
               LEFT JOIN bank_posting_suggestions bps ON bps.supplier_id=je.supplier_id
                AND bps.journal_entry_id=je.id AND bps.status IN ('approved','auto_posted')
              WHERE bt.id=? AND bs.supplier_id=? GROUP BY bt.id HAVING line_count=2"
        );
        $stmt->execute([$supplierId, $entityId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $labelSource = $row['suggested_debit'] === null
            ? 'manual'
            : (((string) $row['suggested_debit'] === (string) $row['debit_code']
                && (string) $row['suggested_credit'] === (string) $row['credit_code']) ? 'approved' : 'corrected');
        return [
            'entity' => $row,
            'debit' => (string) $row['debit_code'],
            'credit' => (string) $row['credit_code'],
            'label_source' => $labelSource,
        ];
    }

    /** @return array{entity:array<string,mixed>,debit:string,credit:string,label_source:string}|null */
    private function purchaseEntity(int $supplierId, int $entityId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.*,c.company_name vendor_name,cur.code currency,
                    CONCAT_WS(' ',pi.note_above_items,pi.note_below_items) description,
                    je.id journal_entry_id
               FROM purchase_invoices pi JOIN journal_entries je ON je.supplier_id=pi.supplier_id
                AND je.source_type='purchase_invoice' AND je.source_id=pi.id AND je.reversed_by IS NULL
               LEFT JOIN clients c ON c.id=pi.vendor_id AND c.supplier_id=pi.supplier_id
               LEFT JOIN currencies cur ON cur.id=pi.currency_id AND cur.supplier_id=pi.supplier_id
              WHERE pi.supplier_id=? AND pi.id=? AND pi.document_kind='invoice'
                AND COALESCE(pi.is_fixed_asset,0)=0
                AND NOT EXISTS (
                    SELECT 1 FROM purchase_invoice_vat_allocations piva
                     WHERE piva.supplier_id=pi.supplier_id AND piva.purchase_invoice_id=pi.id
                )
              ORDER BY je.id DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $account = $this->db->pdo()->prepare(
            "SELECT coa.account_code
               FROM journal_entry_lines jel JOIN chart_of_accounts coa ON coa.id=jel.account_id
              WHERE jel.entry_id=? AND jel.supplier_id=? AND jel.side='debit'
                AND coa.account_code NOT LIKE '343%'
                AND coa.account_code NOT REGEXP '" . \MyInvoice\Service\Accounting\SettlementAccounts::sqlRegexp() . "'
              ORDER BY jel.amount DESC,jel.id LIMIT 1"
        );
        $account->execute([(int) $row['journal_entry_id'], $supplierId]);
        $debit = $account->fetchColumn();
        if (!is_string($debit) || $debit === '') {
            return null;
        }
        unset($row['journal_entry_id']);
        return ['entity' => $row, 'debit' => $debit, 'credit' => '', 'label_source' => 'approved'];
    }

    private function scopeEnabled(int $supplierId, string $entityType): bool
    {
        $scope = $entityType === 'bank_transaction' ? 'bank_tx' : ($entityType === 'purchase_invoice' ? 'purchase_invoices' : '');
        if ($scope === '') {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('SELECT ai_assist_enabled,ai_assist_scope FROM supplier WHERE id=?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && (bool) $row['ai_assist_enabled']
            && in_array($scope, array_filter(explode(',', (string) $row['ai_assist_scope'])), true);
    }
}
