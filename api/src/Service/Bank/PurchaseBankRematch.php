<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Platba odešla dřív, než byl přijatý doklad přijatý: import výpisu ji tehdy neměl s čím
 * spárovat a další import už známý pohyb přeskočí. Po přijetí dokladu se proto nad
 * nespárovanými odchozími pohyby firmy se shodným symbolem nebo částkou spustí běžné
 * automatické párování ({@see TransactionRematcher}), stejně jako „Přepárovat výpis".
 * O spárování rozhodují pravidla párování, tady se jen vybírají pohyby k přepárování.
 */
final class PurchaseBankRematch
{
    /** Nejvýš tolik pohybů se po přijetí jednoho dokladu přepáruje. */
    private const LIMIT = 50;

    /** Jak daleko před datem vystavení dokladu může platba ležet. */
    private const DAYS_BEFORE_ISSUE = 90;

    public function __construct(
        private readonly Connection $db,
        private readonly TransactionRematcher $rematcher,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return array{considered:int, newly_matched:int} */
    public function afterReceived(int $supplierId, int $purchaseInvoiceId, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        try {
            return TransactionFailure::retrying($pdo, fn (): array => $this->run($supplierId, $purchaseInvoiceId, $userId));
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            try {
                $this->activity->log('purchase_invoice.bank_rematch_failed', $userId, 'purchase_invoice', $purchaseInvoiceId,
                    ['message' => $e->getMessage()], supplierId: $supplierId);
            } catch (\Throwable $auditFailure) {
                if (TransactionFailure::rollbackIfAborted($pdo, $auditFailure)) {
                    throw $auditFailure;
                }
            }
            return ['considered' => 0, 'newly_matched' => 0];
        }
    }

    /** @return array{considered:int, newly_matched:int} */
    private function run(int $supplierId, int $purchaseInvoiceId, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $doc = $pdo->prepare(
            "SELECT status, issue_date, payment_variable_symbol, varsymbol, vendor_invoice_number,
                    COALESCE(amount_to_pay, total_with_vat, 0) + COALESCE(rounding, 0) AS amount_to_pay
               FROM purchase_invoices WHERE id = ? AND supplier_id = ?"
        );
        $doc->execute([$purchaseInvoiceId, $supplierId]);
        $pi = $doc->fetch(PDO::FETCH_ASSOC);
        if ($pi === false || in_array((string) $pi['status'], ['draft', 'cancelled'], true)) {
            return ['considered' => 0, 'newly_matched' => 0];
        }

        $keys = [];
        foreach (['payment_variable_symbol', 'varsymbol', 'vendor_invoice_number'] as $column) {
            $key = VariableSymbolNormalizer::matchKey((string) ($pi[$column] ?? ''));
            if ($key !== '') $keys[$key] = true;
        }
        $keys = array_keys($keys);
        $amount = round((float) $pi['amount_to_pay'], 2);
        if ($keys === [] && $amount <= 0.0) {
            return ['considered' => 0, 'newly_matched' => 0];
        }

        $conditions = [];
        $params = [...BankStatementOwnershipResolver::params($supplierId), (string) ($pi['issue_date'] ?: date('Y-m-d')), self::DAYS_BEFORE_ISSUE];
        if ($keys !== []) {
            $conditions[] = "(bt.variable_symbol REGEXP '[1-9]' AND REGEXP_REPLACE(REGEXP_REPLACE(bt.variable_symbol, '[^0-9]', ''), '^0+', '') IN ("
                . implode(',', array_fill(0, count($keys), '?')) . '))';
            array_push($params, ...$keys);
        }
        if ($amount > 0.0) {
            $conditions[] = 'ROUND(-bt.amount, 2) = ?';
            $params[] = $amount;
        }
        $stmt = $pdo->prepare(
            "SELECT bt.id, bt.statement_id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE " . BankStatementOwnershipResolver::sql('bs') . "
                AND bt.amount < 0
                AND bt.match_status IN ('unmatched', 'auto_partial')
                AND " . BankNoticeLifecycle::activeSql('bt') . "
                AND bt.posted_at >= DATE_SUB(?, INTERVAL ? DAY)
                AND (" . implode(' OR ', $conditions) . ")
              ORDER BY bt.posted_at, bt.id
              LIMIT " . self::LIMIT
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return ['considered' => 0, 'newly_matched' => 0];
        }

        $result = $this->rematcher->rematch(array_map(static fn (array $r): int => (int) $r['id'], $rows), $userId);
        $this->rematcher->recountStatements(array_map(static fn (array $r): int => (int) $r['statement_id'], $rows));
        if ($result['newly_matched'] > 0 || $result['newly_partial'] > 0) {
            $this->activity->log('purchase_invoice.bank_rematched', $userId, 'purchase_invoice', $purchaseInvoiceId, [
                'considered'    => count($rows),
                'newly_matched' => $result['newly_matched'],
                'newly_partial' => $result['newly_partial'],
            ], supplierId: $supplierId);
        }
        return ['considered' => count($rows), 'newly_matched' => $result['newly_matched']];
    }
}
