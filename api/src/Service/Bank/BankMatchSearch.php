<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Invoice\RefundDocument;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

final class BankMatchSearch
{
    public function __construct(private readonly Connection $db) {}

    public function document(int $supplierId, string $type, int $id, bool $lock = false): ?array
    {
        if (!in_array($type, ['invoice', 'purchase_invoice'], true)) return null;
        $issued = $type === 'invoice';
        $table = $issued ? 'invoices' : 'purchase_invoices';
        $settled = $issued ? 'i.paid_total' : PurchaseSettledExpr::settled('i');
        $where = $issued
            ? "i.status IN ('issued','sent','reminded','paid') AND i.invoice_type IN ('invoice','proforma','credit_note')"
            : "i.status IN ('received','booked','paid')";
        $query = $this->db->pdo()->prepare("SELECT i.*, cur.code AS currency,
                i.amount_to_pay - ($settled) AS remaining
            FROM $table i JOIN currencies cur ON cur.id = i.currency_id
            WHERE i.id = ? AND i.supplier_id = ? AND $where AND i.amount_to_pay <> 0" . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id, $supplierId]);
        $document = $query->fetch(PDO::FETCH_ASSOC);
        if ($document === false || ($issued && (float) $document['amount_to_pay'] < 0 && !RefundDocument::isRefundDocument($document))) return null;
        return $document;
    }

    /** @param non-empty-list<int> $ids */
    public function selectedStatementPayments(int $supplierId, array $ids, bool $lock = false): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $ownership = BankStatementOwnershipResolver::sql('bs', $lock);
        $available = BankPaymentCandidateScope::sql($supplierId);
        $query = $this->db->pdo()->prepare("SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount, bt.counterparty_name,
                bt.variable_symbol, bt.description, bt.bank_ref, bs.account_number, bs.bank_code,
                UPPER(COALESCE(NULLIF(bt.currency,''), NULLIF(bs.currency,''), 'CZK')) AS currency
            FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
            WHERE bt.id IN ($placeholders) AND bt.source = 'statement' AND $ownership AND $available
            ORDER BY bt.posted_at, bt.id" . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([...$ids, ...BankStatementOwnershipResolver::params($supplierId)]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchDocuments(int $supplierId, float $amount, string $currency, string $search, array $types, bool $batchOnly = false): array
    {
        $params = [];
        $branches = [];
        $like = '%' . $search . '%';
        $number = BankAmountSearch::normalize($search);
        foreach (['invoice', 'purchase_invoice'] as $type) {
            if (!in_array($type, $types, true)) continue;
            $issued = $type === 'invoice';
            $table = $issued ? 'invoices' : 'purchase_invoices';
            $partner = $issued ? 'client_id' : 'vendor_id';
            $ref = $issued ? 'i.varsymbol' : "COALESCE(NULLIF(i.vendor_invoice_number,''), i.varsymbol)";
            $extra = $issued ? 'i.payment_variable_symbol' : 'i.vendor_invoice_number';
            $settled = $issued ? 'i.paid_total' : PurchaseSettledExpr::settled('i');
            $status = $issued
                ? "i.status IN ('issued','sent','reminded','paid') AND i.invoice_type IN ('invoice','proforma','credit_note')"
                : "i.status IN ('received','booked','paid')";
            $direction = ($amount > 0) === $issued ? '>' : '<';
            if ($batchOnly && $direction !== '>') continue;
            $refund = $issued ? ' AND (i.amount_to_pay > 0 OR ' . RefundDocument::refundDocumentSql('i') . ')' : '';
            $filter = "(i.varsymbol LIKE ? OR $extra LIKE ? OR c.company_name LIKE ?";
            array_push($params, $supplierId, $like, $like, $like);
            if ($number !== null) {
                $filter .= " OR ABS(i.amount_to_pay) = ? OR ABS(i.total_with_vat) = ? OR ABS(i.amount_to_pay - ($settled)) = ?";
                array_push($params, $number, $number, $number);
            }
            $filter .= ')';
            if ($batchOnly) {
                $filter .= " AND i.status <> 'paid' AND i.amount_to_pay - ($settled) > 0 AND cur.code = ?";
                $params[] = $currency;
            }
            $branches[] = "SELECT '$type' AS type, i.id, $ref AS ref, i.amount_to_pay AS amount,
                    i.exchange_rate, cur.code AS currency, i.issue_date, i.due_date,
                    c.company_name AS party, i.status = 'paid' AS paid
                FROM $table i JOIN currencies cur ON cur.id = i.currency_id
                LEFT JOIN clients c ON c.id = i.$partner AND c.supplier_id = i.supplier_id
                WHERE i.supplier_id = ? AND $status AND i.amount_to_pay $direction 0 $refund AND $filter";
        }
        if ($branches === [] || $amount === 0.0) return [];
        $query = $this->db->pdo()->prepare('SELECT * FROM (' . implode(' UNION ALL ', $branches) . ') documents ORDER BY paid, due_date DESC, id DESC LIMIT 50');
        $query->execute($params);
        return array_map(static function (array $row) use ($currency): array {
            $rate = max(0.0, (float) $row['exchange_rate']);
            $cross = strtoupper($row['currency']) !== $currency;
            $converted = $cross && $currency === FxPaymentSettlement::LOCAL_CURRENCY && $rate > 0
                ? round(FxPaymentSettlement::expectedLocalAmount(abs((float) $row['amount']), $rate), 2) : null;
            unset($row['exchange_rate']);
            $row['id'] = (int) $row['id'];
            $row['amount'] = (float) $row['amount'];
            $row['paid'] = (bool) $row['paid'];
            $row['converted_amount'] = $converted;
            $row['converted_currency'] = $converted !== null ? $currency : null;
            $row['currency_mismatch'] = $cross && $converted === null;
            return $row;
        }, $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function payments(int $supplierId, string $type, array $document, string $search, int $page, ?int $transactionId = null, bool $batchOnly = false): array
    {
        $direction = ((float) $document['amount_to_pay'] > 0) === ($type === 'invoice') ? '>' : '<';
        $where = BankStatementOwnershipResolver::sql('bs') . ' AND ' . BankPaymentCandidateScope::sql($supplierId) . " AND bt.amount $direction 0";
        $params = BankStatementOwnershipResolver::params($supplierId);
        if ($transactionId !== null) { $where .= ' AND bt.id = ?'; $params[] = $transactionId; }
        $currency = strtoupper((string) $document['currency']);
        $effectiveCurrency = "UPPER(COALESCE(NULLIF(bt.currency,''), NULLIF(bs.currency,''), 'CZK'))";
        if ($batchOnly) {
            $where .= " AND bt.source = 'statement' AND $effectiveCurrency = ?";
            $params[] = $currency;
        }
        if ($search !== '') {
            $where .= ' AND (bt.counterparty_name LIKE ? OR bt.variable_symbol LIKE ? OR bt.description LIKE ? OR bt.bank_ref LIKE ?';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
            $number = BankAmountSearch::normalize($search);
            if ($number !== null) { $where .= ' OR ABS(bt.amount) = ?'; $params[] = $number; }
            $where .= ')';
        }
        $from = " FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE $where";
        $count = $this->db->pdo()->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $page = max(1, $page);
        $limit = 25;
        $offset = ($page - 1) * $limit;
        $expected = abs((float) $document['remaining']);
        if ($expected < 0.005 || $document['status'] === 'paid') $expected = abs((float) $document['amount_to_pay']);
        $rate = (float) $document['exchange_rate'];
        if ($rate <= 0) $rate = 1.0;
        $query = $this->db->pdo()->prepare("SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount,
            $effectiveCurrency AS currency, bt.counterparty_name, bt.variable_symbol, bt.description, bt.bank_ref,
            bs.account_number, bs.bank_code" . $from . " ORDER BY
            CASE WHEN $effectiveCurrency = ? THEN 0 WHEN $effectiveCurrency = 'CZK' THEN 1 ELSE 2 END,
            ABS(ABS(bt.amount) - CASE WHEN $effectiveCurrency = 'CZK' AND ? <> 'CZK' THEN ? ELSE ? END),
            ABS(DATEDIFF(bt.posted_at, ?)), bt.id DESC LIMIT $limit OFFSET $offset");
        $query->execute([...$params, $currency, $currency, $expected * $rate, $expected, $document['due_date'] ?? $document['issue_date']]);
        $items = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['statement_id'] = (int) $row['statement_id'];
            $row['amount'] = (float) $row['amount'];
            return $row;
        }, $query->fetchAll(PDO::FETCH_ASSOC));
        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => (int) ceil($total / $limit)];
    }
}
