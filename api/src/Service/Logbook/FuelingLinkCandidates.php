<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Návrhy dokladů, na které lze tankování navázat — pokladní doklad, bankovní pohyb
 * nebo účetní zápis kolem data tankování, seřazené podle shody částky a vzdálenosti data.
 * Vše vázané na firmu; bankovní pohyby přes vlastníka výpisu.
 */
final class FuelingLinkCandidates
{
    public const TYPES = ['cash_document', 'bank_transaction', 'journal_entry'];
    private const WINDOW_DAYS = 10;
    private const LIMIT = 20;

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function find(int $supplierId, string $type, string $date, ?float $amount, string $q = ''): array
    {
        if (!in_array($type, self::TYPES, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }
        $amount = $amount !== null && $amount > 0 ? round($amount, 2) : 0.0;
        $q = trim($q);
        return match ($type) {
            'cash_document'    => $this->cashDocuments($supplierId, $date, $amount, $q),
            'bank_transaction' => $this->bankTransactions($supplierId, $date, $amount, $q),
            default            => $this->journalEntries($supplierId, $date, $q),
        };
    }

    /** @return list<array<string,mixed>> */
    private function cashDocuments(int $supplierId, string $date, float $amount, string $q): array
    {
        $params = [$date, $amount, $supplierId, $date, self::WINDOW_DAYS, $date, self::WINDOW_DAYS];
        $like = '';
        if ($q !== '') {
            $like = ' AND (cd.doc_number LIKE ? OR cd.description LIKE ? OR cd.partner_name LIKE ?)';
            $needle = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $needle, $needle, $needle);
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT cd.id, cd.doc_number, cd.issue_date, cd.partner_name, cd.description, cd.total_amount, cd.currency_code,
                    ABS(DATEDIFF(cd.issue_date, ?)) AS day_diff, ABS(cd.total_amount - ?) AS amount_diff
               FROM cash_documents cd
              WHERE cd.supplier_id = ? AND cd.doc_type = 'out' AND cd.status <> 'reversed'
                AND cd.issue_date BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)" . $like . '
              ORDER BY amount_diff ASC, day_diff ASC, cd.id DESC
              LIMIT ' . self::LIMIT
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'label'       => (string) ($r['doc_number'] ?? ('#' . $r['id'])),
            'date'        => (string) $r['issue_date'],
            'amount'      => (float) $r['total_amount'],
            'currency'    => (string) $r['currency_code'],
            'description' => trim((string) ($r['partner_name'] ?? '') . ' ' . (string) $r['description']),
            'exact'       => (float) $r['amount_diff'] < 0.01,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function bankTransactions(int $supplierId, string $date, float $amount, string $q): array
    {
        $params = [$date, $amount, $supplierId, $date, self::WINDOW_DAYS, $date, self::WINDOW_DAYS];
        $like = '';
        if ($q !== '') {
            $like = ' AND (bt.counterparty_name LIKE ? OR bt.description LIKE ? OR bt.variable_symbol LIKE ?)';
            $needle = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $needle, $needle, $needle);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount, COALESCE(bt.currency, bs.currency) AS currency,
                    bt.counterparty_name, bt.description,
                    ABS(DATEDIFF(bt.posted_at, ?)) AS day_diff, ABS(ABS(bt.amount) - ?) AS amount_diff
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = ?
              WHERE bt.amount < 0
                AND bt.posted_at BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)' . $like . '
              ORDER BY amount_diff ASC, day_diff ASC, bt.id DESC
              LIMIT ' . self::LIMIT
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'statement_id' => (int) $r['statement_id'],
            'label'        => trim((string) ($r['counterparty_name'] ?? '')) ?: ('#' . $r['id']),
            'date'         => (string) $r['posted_at'],
            'amount'       => abs((float) $r['amount']),
            'currency'     => (string) ($r['currency'] ?? 'CZK'),
            'description'  => (string) ($r['description'] ?? ''),
            'exact'        => (float) $r['amount_diff'] < 0.01,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function journalEntries(int $supplierId, string $date, string $q): array
    {
        $params = [$date, $supplierId, $date, self::WINDOW_DAYS, $date, self::WINDOW_DAYS];
        $like = '';
        if ($q !== '') {
            $like = ' AND (je.document_no LIKE ? OR je.description LIKE ?)';
            $needle = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $needle, $needle);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT je.id, je.document_no, je.entry_date, je.description, ABS(DATEDIFF(je.entry_date, ?)) AS day_diff
               FROM journal_entries je
              WHERE je.supplier_id = ? AND je.posted_at IS NOT NULL
                AND je.entry_date BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)' . $like . '
              ORDER BY day_diff ASC, je.id DESC
              LIMIT ' . self::LIMIT
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'label'       => (string) ($r['document_no'] ?? ('#' . $r['id'])),
            'date'        => (string) $r['entry_date'],
            'amount'      => null,
            'currency'    => null,
            'description' => (string) ($r['description'] ?? ''),
            'exact'       => false,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
