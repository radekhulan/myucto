<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use PDO;

final class BankApiMonthlyStatements
{
    public function __construct(private readonly PDO $pdo) {}

    public static function visibleSql(string $alias = 'bs'): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $alias) !== 1) throw new \InvalidArgumentException('Invalid statement alias.');
        return "NOT EXISTS (SELECT 1 FROM bank_api_evidence_months apiem WHERE apiem.evidence_statement_id = $alias.id)";
    }

    public function projectAccount(int $supplierId, string $account, string $bank, string $currency, ?int $userId = null): array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Monthly projection requires an account import transaction.');
        $key = AuthoritativeTransactionReconciler::account($account, $bank);
        if ($key === null) throw new \InvalidArgumentException('Invalid monthly statement account.');
        if (!$this->hasApiAccount($supplierId, $account, $bank, $currency)) return [];
        $query = $this->pdo->prepare("SELECT bs.id, bs.account_number, bs.bank_code, bs.statement_date
            FROM bank_statements bs WHERE bs.supplier_id = ? AND bs.currency = ? AND bs.source IN ('bank_api', 'gpc')
            AND NOT EXISTS (SELECT 1 FROM bank_api_months m WHERE m.statement_id = bs.id)
            AND " . self::visibleSql() . ' ORDER BY bs.id');
        $query->execute([$supplierId, $currency]);
        $evidence = array_filter($query->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool =>
            AuthoritativeTransactionReconciler::account((string) $row['account_number'], (string) $row['bank_code']) === $key);
        $link = $this->pdo->prepare('INSERT INTO bank_api_evidence_months (evidence_statement_id, monthly_statement_id, supplier_id) VALUES (?, ?, ?)');
        $hasTx = $this->pdo->prepare('SELECT 1 FROM bank_transaction_imports WHERE statement_id = ? AND bank_transaction_id = ?');
        $linkTx = $this->pdo->prepare('INSERT INTO bank_transaction_imports (statement_id, bank_transaction_id, import_fingerprint, supplier_id, original_statement_id) VALUES (?, ?, ?, ?, ?)');
        $result = [];
        $affected = [];
        foreach ($evidence as $row) {
            $id = (int) $row['id'];
            $result[$id] = [];
            $transactions = $this->pdo->query('SELECT bt.id, bt.statement_id, bt.posted_at, bt.import_fingerprint FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql($id))->fetchAll(PDO::FETCH_ASSOC);
            $groups = [];
            foreach ($transactions as $tx) $groups[substr((string) $tx['posted_at'], 0, 7) . '-01'][] = $tx;
            if ($groups === []) $groups[substr((string) $row['statement_date'], 0, 7) . '-01'] = [];
            ksort($groups);
            foreach ($groups as $month => $rows) {
                $monthId = $this->month($supplierId, $key, $account, $bank, $currency, $month, $userId);
                foreach ($rows as $tx) {
                    $hasTx->execute([$monthId, $tx['id']]);
                    if ($hasTx->fetchColumn() === false) {
                        $linkTx->execute([$monthId, $tx['id'], $tx['import_fingerprint'] ?? hash('sha256', 'bank-api-transaction:' . $tx['id']), $supplierId, $tx['statement_id']]);
                    }
                }
                $link->execute([$id, $monthId, $supplierId]);
                $result[$id][] = $monthId;
                $affected[$monthId] = true;
                $end = (new \DateTimeImmutable($month))->format('Y-m-t');
                $covered = max($month, min($end, (string) $row['statement_date']));
                foreach ($rows as $tx) $covered = max($covered, (string) $tx['posted_at']);
                $update = $this->pdo->prepare('UPDATE bank_statements SET statement_date = CASE WHEN statement_date < ? THEN ? ELSE statement_date END WHERE id = ?');
                $update->execute([$covered, $covered, $monthId]);
            }
        }
        foreach (array_keys($affected) as $id) {
            $totals = $this->pdo->query("SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN bt.amount > 0 THEN bt.amount ELSE 0 END), 0) AS credit,
                COALESCE(SUM(CASE WHEN bt.amount < 0 THEN -bt.amount ELSE 0 END), 0) AS debit,
                COALESCE(SUM(CASE WHEN bt.match_status IN ('auto_exact', 'auto_partial', 'manual') THEN 1 ELSE 0 END), 0) AS matched
                FROM bank_transactions bt WHERE " . StatementTransactionScope::sql($id))->fetch(PDO::FETCH_ASSOC);
            $update = $this->pdo->prepare('UPDATE bank_statements SET transaction_count = ?, matched_count = ?, credit_total = ?, debit_total = ? WHERE id = ?');
            $update->execute([$totals['total'], $totals['matched'], $totals['credit'], $totals['debit'], $id]);
            $this->useBankDocument($id, $supplierId);
            $this->preservePdf($id, $supplierId);
        }
        return $result;
    }

    public function hasApiAccount(int $supplierId, string $account, string $bank, string $currency): bool
    {
        $key = AuthoritativeTransactionReconciler::account($account, $bank);
        if ($key === null) return false;
        $monthly = $this->pdo->prepare('SELECT 1 FROM bank_api_months WHERE supplier_id = ? AND account_key = ? AND currency = ? LIMIT 1');
        $monthly->execute([$supplierId, $key, $currency]);
        if ($monthly->fetchColumn() !== false) return true;
        $query = $this->pdo->prepare("SELECT account_number, bank_code FROM bank_statements WHERE supplier_id = ? AND currency = ? AND source = 'bank_api'");
        $query->execute([$supplierId, $currency]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (AuthoritativeTransactionReconciler::account((string) $row['account_number'], (string) $row['bank_code']) === $key) return true;
        }
        return false;
    }

    private function useBankDocument(int $monthId, int $supplierId): void
    {
        $ids = $this->pdo->query('SELECT bt.id FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql($monthId) . ' ORDER BY bt.id')->fetchAll(PDO::FETCH_COLUMN);
        $query = $this->pdo->prepare("SELECT bs.* FROM bank_statements bs JOIN bank_api_evidence_months e ON e.evidence_statement_id = bs.id
            WHERE e.monthly_statement_id = ? AND bs.supplier_id = ? AND bs.source = 'gpc'
            AND NOT EXISTS (SELECT 1 FROM bank_api_evidence_months other WHERE other.evidence_statement_id = bs.id AND other.monthly_statement_id <> ?)
            ORDER BY bs.statement_date DESC, bs.id DESC");
        $query->execute([$monthId, $supplierId, $monthId]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $document) {
            $documentIds = $this->pdo->query('SELECT bt.id FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql((int) $document['id']) . ' ORDER BY bt.id')->fetchAll(PDO::FETCH_COLUMN);
            if (array_map('intval', $ids) !== array_map('intval', $documentIds)) continue;
            $update = $this->pdo->prepare("UPDATE bank_statements SET source = 'gpc', file_name = ?, file_content = ?, statement_number = ?, prev_balance = ?, curr_balance = ? WHERE id = ?");
            $update->execute([$document['file_name'], $document['file_content'], $document['statement_number'], $document['prev_balance'], $document['curr_balance'], $monthId]);
            return;
        }
        $this->pdo->prepare("UPDATE bank_statements SET source = 'bank_api', file_name = ?, file_content = NULL, statement_number = NULL, prev_balance = NULL, curr_balance = NULL WHERE id = ?")
            ->execute(['API-' . substr((string) $this->pdo->query('SELECT month_start FROM bank_api_months WHERE statement_id = ' . $monthId)->fetchColumn(), 0, 7), $monthId]);
    }

    private function preservePdf(int $monthId, int $supplierId): void
    {
        $ids = $this->pdo->query('SELECT bt.id FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql($monthId) . ' ORDER BY bt.id')->fetchAll(PDO::FETCH_COLUMN);
        $query = $this->pdo->prepare("SELECT bs.* FROM bank_statements bs JOIN bank_api_evidence_months e ON e.evidence_statement_id = bs.id
            WHERE e.monthly_statement_id = ? AND bs.supplier_id = ? AND OCTET_LENGTH(bs.pdf_content) > 0
            AND NOT EXISTS (SELECT 1 FROM bank_api_evidence_months other WHERE other.evidence_statement_id = bs.id AND other.monthly_statement_id <> ?)
            ORDER BY bs.statement_date DESC, bs.id DESC");
        $query->execute([$monthId, $supplierId, $monthId]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $document) {
            $documentIds = $this->pdo->query('SELECT bt.id FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql((int) $document['id']) . ' ORDER BY bt.id')->fetchAll(PDO::FETCH_COLUMN);
            if (array_map('intval', $ids) !== array_map('intval', $documentIds)) continue;
            $this->pdo->prepare('UPDATE bank_statements SET pdf_content = ?, pdf_name = ?, pdf_hash = ?, pdf_size_bytes = ?, pdf_uploaded_at = ? WHERE id = ? AND (pdf_content IS NULL OR OCTET_LENGTH(pdf_content) = 0)')
                ->execute([$document['pdf_content'], $document['pdf_name'], $document['pdf_hash'], $document['pdf_size_bytes'], $document['pdf_uploaded_at'], $monthId]);
            return;
        }
    }

    public function monthIds(int $evidenceId, int $supplierId): array
    {
        $query = $this->pdo->prepare('SELECT e.monthly_statement_id FROM bank_api_evidence_months e JOIN bank_api_months m ON m.statement_id = e.monthly_statement_id WHERE e.evidence_statement_id = ? AND m.supplier_id = ? ORDER BY m.month_start');
        $query->execute([$evidenceId, $supplierId]);
        return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    public function evidencePdfs(int $monthId, int $supplierId): array
    {
        $query = $this->pdo->prepare('SELECT bs.id, bs.pdf_name FROM bank_api_evidence_months e
            JOIN bank_statements bs ON bs.id = e.evidence_statement_id
            JOIN bank_statements monthly ON monthly.id = e.monthly_statement_id
            WHERE monthly.id = ? AND monthly.supplier_id = ? AND bs.supplier_id = ?
            AND OCTET_LENGTH(bs.pdf_content) > 0
            AND (COALESCE(OCTET_LENGTH(monthly.pdf_content), 0) = 0 OR NOT (bs.pdf_hash <=> monthly.pdf_hash))
            ORDER BY bs.statement_date, bs.id');
        $query->execute([$monthId, $supplierId, $supplierId]);
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'pdf_name' => $row['pdf_name']], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    private function month(int $supplierId, string $key, string $account, string $bank, string $currency, string $month, ?int $userId): int
    {
        $query = $this->pdo->prepare('SELECT statement_id FROM bank_api_months WHERE supplier_id = ? AND account_key = ? AND currency = ? AND month_start = ?');
        $query->execute([$supplierId, $key, $currency, $month]);
        $id = $query->fetchColumn();
        if ($id !== false) return (int) $id;
        $hash = hash('sha256', json_encode(['bank-api-month', $supplierId, $key, $currency, $month], JSON_THROW_ON_ERROR));
        $number = 'API-' . substr($month, 0, 7);
        $insert = $this->pdo->prepare("INSERT INTO bank_statements
            (source, file_name, file_hash, supplier_id, account_number, bank_code, currency, statement_number, statement_date, transaction_count, matched_count, imported_by)
            VALUES ('bank_api', ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)");
        $insert->execute([$number, $hash, $supplierId, $account, $bank, $currency, null, $month, $userId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO bank_api_months (supplier_id, account_key, currency, month_start, statement_id) VALUES (?, ?, ?, ?, ?)')->execute([$supplierId, $key, $currency, $month, $id]);
        return $id;
    }
}
