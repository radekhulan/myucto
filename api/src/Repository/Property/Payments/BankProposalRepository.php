<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Payments;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\PaymentException;
use PDO;

/**
 * Fronta návrhů úhrad jednotek z bankovních pohybů a vazby pohybů na úhrady SVJ.
 *
 * Návrh je jen podklad k potvrzení. Do fronty patří pohyb, dokud je volný stejně jako
 * pro ruční úhradu (nespárovaný, bez faktury, mezd ani úhrady SVJ); jakmile ho cokoli
 * použije, z fronty zmizí bez zápisu do návrhu.
 */
final class BankProposalRepository
{
    public const STATUSES = ['identified', 'review_required', 'unidentified'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Příchozí CZK pohyby firmy, které jsou volné pro úhradu SVJ. Vrací je ve stejném
     * pořadí jako vstupní id; cizí nebo použité pohyby vynechá.
     *
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public function freeIncoming(int $supplier, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->all('SELECT bt.id, bt.posted_at, bt.amount, COALESCE(NULLIF(bt.currency, \'\'), bs.currency) currency,
                    bt.variable_symbol, bt.specific_symbol, bt.constant_symbol, bt.counterparty_account, bt.counterparty_bank,
                    bt.counterparty_name, bt.description
                  FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
                 WHERE bt.id IN (' . $marks . ') AND ' . BankStatementOwnershipResolver::sql() . ' AND ' . self::freeSql() . '
                 ORDER BY bt.id', [...$chunk, ...BankStatementOwnershipResolver::params($supplier)]);
            foreach ($rows as $row) {
                if (($row['currency'] ?? 'CZK') !== 'CZK' || bccomp((string) $row['amount'], '0', 2) <= 0) continue;
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Uloží nebo přepočítá návrhy. Jediný INSERT … ON DUPLICATE KEY UPDATE nad unikátním
     * pohybem: souběžné importy téhož pohybu skončí jedním řádkem bez čtení předem.
     *
     * @param list<array<string,mixed>> $resolutions
     */
    public function save(int $supplier, array $resolutions): void
    {
        if ($resolutions === []) return;
        $stmt = $this->db->pdo()->prepare('INSERT INTO unit_payment_bank_proposals
                (supplier_id, bank_transaction_id, status, unit_id, score, resolver_version, resolution_json, resolution_hash, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE
                status = IF(supplier_id = VALUES(supplier_id) AND resolution_hash <> VALUES(resolution_hash), VALUES(status), status),
                unit_id = IF(supplier_id = VALUES(supplier_id) AND resolution_hash <> VALUES(resolution_hash), VALUES(unit_id), unit_id),
                score = IF(supplier_id = VALUES(supplier_id) AND resolution_hash <> VALUES(resolution_hash), VALUES(score), score),
                resolver_version = IF(supplier_id = VALUES(supplier_id) AND resolution_hash <> VALUES(resolution_hash), VALUES(resolver_version), resolver_version),
                resolution_json = IF(supplier_id = VALUES(supplier_id) AND resolution_hash <> VALUES(resolution_hash), VALUES(resolution_json), resolution_json),
                row_version = IF(supplier_id = VALUES(supplier_id) AND resolution_hash <> VALUES(resolution_hash), row_version + 1, row_version),
                updated_at = IF(supplier_id = VALUES(supplier_id) AND resolution_hash <> VALUES(resolution_hash), VALUES(updated_at), updated_at),
                resolution_hash = IF(supplier_id = VALUES(supplier_id), VALUES(resolution_hash), resolution_hash)');
        foreach ($resolutions as $resolution) {
            if (!in_array($resolution['status'], self::STATUSES, true)) throw new \LogicException('Unknown proposal status.');
            $json = json_encode($resolution, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $stmt->execute([$supplier, $resolution['bank_transaction_id'], $resolution['status'], $resolution['unit_id'], $resolution['score'],
                $resolution['resolver_version'], $json, PropertyInput::hash($resolution)]);
        }
    }

    /** Fronta k potvrzení: uložené návrhy nad pohyby, které jsou pořád volné. */
    public function queue(int $supplier, int $after = 0, int $limit = 25, ?string $status = null): array
    {
        if ($after < 0 || $limit < 1 || $limit > 100) PropertyInput::invalid('page');
        if ($status !== null && !in_array($status, self::STATUSES, true)) PropertyInput::invalid('status');
        $statuses = $status === null ? ['identified', 'review_required'] : [$status];
        $marks = implode(',', array_fill(0, count($statuses), '?'));
        $rows = $this->all('SELECT p.bank_transaction_id, p.status, p.unit_id, p.score, p.resolution_json, p.row_version, p.updated_at,
                bt.posted_at, bt.amount, bt.variable_symbol, bt.specific_symbol, bt.counterparty_name, bt.description
              FROM unit_payment_bank_proposals p
              JOIN bank_transactions bt ON bt.id = p.bank_transaction_id
              JOIN bank_statements bs ON bs.id = bt.statement_id
             WHERE p.supplier_id = ? AND p.status IN (' . $marks . ') AND p.bank_transaction_id > ?
               AND ' . BankStatementOwnershipResolver::sql() . ' AND ' . self::freeSql() . '
             ORDER BY p.bank_transaction_id LIMIT ' . ($limit + 1),
            [$supplier, ...$statuses, $after, ...BankStatementOwnershipResolver::params($supplier)]);
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        return ['items' => array_map(self::item(...), $rows), 'next_cursor' => $more ? (int) $rows[array_key_last($rows)]['bank_transaction_id'] : null];
    }

    public function find(int $supplier, int $transactionId): ?array
    {
        $row = $this->one('SELECT bank_transaction_id, status, unit_id, score, resolution_json, row_version, updated_at
            FROM unit_payment_bank_proposals WHERE supplier_id = ? AND bank_transaction_id = ?', [$supplier, $transactionId]);
        return $row === null ? null : self::item($row);
    }

    /**
     * Pro bankovní seznamy: který pohyb už použila úhrada SVJ a který má návrh.
     *
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    public function links(int $supplier, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if (count($ids) > 500) PropertyInput::invalid('ids');
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all('SELECT bank_transaction_id, id, consumed_cents, amount_cents FROM unit_payment_sources
                WHERE supplier_id = ? AND bank_transaction_id IN (' . $marks . ')', [$supplier, ...$ids]) as $row) {
            $out[(int) $row['bank_transaction_id']]['source'] = ['id' => (int) $row['id'], 'amount_cents' => $row['amount_cents'], 'consumed_cents' => $row['consumed_cents']];
        }
        foreach ($this->all("SELECT bank_transaction_id, MAX(id) id FROM unit_payment_workflows
                WHERE supplier_id = ? AND status = 'confirmed' AND bank_transaction_id IN (" . $marks . ') GROUP BY bank_transaction_id', [$supplier, ...$ids]) as $row) {
            $out[(int) $row['bank_transaction_id']]['workflow_id'] = (int) $row['id'];
        }
        foreach ($this->all('SELECT bank_transaction_id, status, unit_id, score FROM unit_payment_bank_proposals
                WHERE supplier_id = ? AND bank_transaction_id IN (' . $marks . ')', [$supplier, ...$ids]) as $row) {
            if (isset($out[(int) $row['bank_transaction_id']]['source'])) continue;
            $out[(int) $row['bank_transaction_id']]['proposal'] = ['status' => $row['status'], 'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'], 'score' => (int) $row['score']];
        }
        return $out;
    }

    /** Pohyb je pro úhradu SVJ volný: stejné podmínky jako výběr pohybu pro ruční úhradu. */
    public static function freeSql(string $alias = 'bt'): string
    {
        return "$alias.source = 'statement' AND $alias.match_status = 'unmatched' AND $alias.matched_invoice_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = $alias.id)
            AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = $alias.id)
            AND NOT EXISTS (SELECT 1 FROM payroll_payment_matches pp WHERE pp.bank_transaction_id = $alias.id)
            AND NOT EXISTS (SELECT 1 FROM unit_payment_sources ps WHERE ps.bank_transaction_id = $alias.id)";
    }

    private static function item(array $row): array
    {
        $resolution = json_decode($row['resolution_json'], true, flags: JSON_THROW_ON_ERROR);
        $item = ['bank_transaction_id' => (int) $row['bank_transaction_id'], 'status' => $row['status'],
            'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'], 'score' => (int) $row['score'],
            'row_version' => (int) $row['row_version'], 'updated_at' => $row['updated_at'], 'resolution' => $resolution];
        if (array_key_exists('posted_at', $row)) {
            $item['transaction'] = ['date' => $row['posted_at'], 'amount' => $row['amount'], 'variable_symbol' => $row['variable_symbol'],
                'specific_symbol' => $row['specific_symbol'], 'partner_name' => $row['counterparty_name'], 'description' => $row['description']];
        }
        if (($resolution['bank_transaction_id'] ?? null) !== $item['bank_transaction_id']) PaymentException::reject('history_corrupt');
        return $item;
    }

    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function all(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
