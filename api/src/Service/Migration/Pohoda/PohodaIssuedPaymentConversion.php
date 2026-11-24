<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Service\Bank\BankTransactionPostingScope;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use PDO;

final class PohodaIssuedPaymentConversion
{
    public function __construct(
        private readonly Connection $db,
        private readonly MigrationCheckLocks $locks,
    ) {}

    public static function proportional(float $paidCzk, float $dueCzk, float $dueForeign): float
    {
        if ($dueCzk === 0.0 || $paidCzk === 0.0) {
            return 0.0;
        }
        return $paidCzk === $dueCzk ? $dueForeign : round($paidCzk / $dueCzk * $dueForeign, 2);
    }

    /** @param array<int,float> $amountsCzk id úhrady => alokace v Kč, v pořadí id */
    public static function distribute(float $foreign, array $amountsCzk): array
    {
        $total = array_sum($amountsCzk);
        $running = $previous = 0.0;
        $out = [];
        foreach ($amountsCzk as $id => $amount) {
            $running = round($running + $amount, 2);
            $allocated = self::proportional($running, round($total, 2), $foreign);
            $out[$id] = round($allocated - $previous, 2);
            $previous = $allocated;
        }
        return $out;
    }

    /** @return array{array<int,array>,array<int,string>,array<int,list<int>>} */
    public function plan(int $supplierId, array $plans, array $unchanged = []): array
    {
        $pdo = $this->db->pdo();
        $ids = implode(',', array_map('intval', array_keys($plans + $unchanged)));
        $rows = $pdo->query(
            "SELECT p.id, p.invoice_id, p.amount, p.currency, p.source, p.note, p.paid_on,
                    p.bank_transaction_id, p.tax_document_invoice_id
               FROM invoice_payments p FORCE INDEX (idx_invpay_invoice)
              WHERE p.supplier_id = {$supplierId} AND p.invoice_id IN ({$ids})
              ORDER BY p.id FOR UPDATE"
        )->fetchAll(PDO::FETCH_ASSOC);
        $payments = $byBank = [];
        foreach ($rows as $row) {
            $payments[(int) $row['invoice_id']][] = $row;
            if ($row['bank_transaction_id'] !== null && isset($plans[$row['invoice_id']])) {
                $byBank[(int) $row['bank_transaction_id']][(int) $row['id']] = $row;
            }
        }

        $banks = $this->banks($supplierId, $byBank);
        $allocations = $problems = $groups = [];
        foreach ($byBank as $txId => $rows) {
            $members = array_map('intval', array_column($rows, 'invoice_id'));
            $groups[$txId] = $members;
            $bank = $banks[$txId] ?? null;
            $reason = $this->bankProblem($supplierId, $bank, $rows, $plans);
            if ($reason !== null) {
                foreach ($members as $id) {
                    $problems[$id] = $reason;
                }
                continue;
            }
            $allocated = self::distribute(abs((float) $bank['amount']), array_map('floatval', array_column($rows, 'amount', 'id')));
            foreach ($allocated as $paymentId => $amount) {
                $allocations[(int) $paymentId] = $amount;
            }
        }

        $out = [];
        foreach ($plans as $id => $plan) {
            if (isset($problems[$id])) {
                continue;
            }
            $result = $this->forInvoice($supplierId, $plan, $payments[$id] ?? [], $allocations);
            if (is_string($result)) {
                $problems[$id] = $result;
            } else {
                foreach ($result['bank_ids'] as $txId) {
                    $result['dates'][] = substr($banks[$txId]['posted_at'], 0, 10);
                }
                $out[$id] = $result;
            }
        }
        foreach ($unchanged as $id => $doc) {
            $rows = $payments[$id] ?? [];
            $out[$id] = [
                'links' => array_filter(array_column($rows, 'tax_document_invoice_id', 'id')),
                'rows' => array_map('floatval', array_column(array_filter($rows, static fn (array $row): bool => $row['currency'] === $doc['currency']), 'amount', 'id')),
                'dates' => array_column($rows, 'paid_on'),
                'bank_ids' => array_values(array_unique(array_map('intval', array_filter(array_column($rows, 'bank_transaction_id'))))),
            ];
        }
        return [$out, $problems, $groups];
    }

    /** @return array<int,array> */
    private function banks(int $supplierId, array $byBank): array
    {
        if ($byBank === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $ids = implode(',', array_map('intval', array_keys($byBank)));
        $posted = BankTransactionPostingScope::existsSql($supplierId, 'b.id');
        $takenOver = TakenOverRecord::liveBankEntrySql((string) $supplierId, 'b.id');
        $banks = $pdo->query(
            "SELECT b.id, b.amount, b.posted_at, b.match_status, b.source, b.matched_invoice_id, s.supplier_id,
                    UPPER(COALESCE(NULLIF(b.currency, ''), NULLIF(s.currency, ''), 'CZK')) AS currency,
                    {$posted} AS posted, {$takenOver} AS taken_over,
                    (EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = b.id
                              AND (pm.supplier_id <> {$supplierId} OR pm.invoice_id IS NULL OR NOT EXISTS (
                                  SELECT 1 FROM invoice_payments p WHERE p.bank_transaction_id = b.id AND p.invoice_id = pm.invoice_id)))
                     OR EXISTS (SELECT 1 FROM other_item_allocations a WHERE a.supplier_id = {$supplierId}
                                AND a.bank_transaction_id = b.id AND a.reversed_on IS NULL)) AS other_allocation
               FROM bank_transactions b JOIN bank_statements s ON s.id = b.statement_id
              WHERE b.id IN ({$ids}) ORDER BY b.id FOR UPDATE"
        )->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        foreach ($pdo->query(
            "SELECT bank_transaction_id, id FROM invoice_payments FORCE INDEX (uq_invpay_bank_tx_invoice)
              WHERE bank_transaction_id IN ({$ids}) ORDER BY bank_transaction_id, id FOR UPDATE"
        ) as $row) {
            $banks[(int) $row['bank_transaction_id']]['payment_ids'][] = (int) $row['id'];
        }
        return $banks;
    }

    private function bankProblem(int $supplierId, ?array $bank, array $rows, array $plans): ?string
    {
        if ($bank === null || $bank['source'] !== 'statement' || $bank['match_status'] === 'ignored'
            || ($bank['supplier_id'] !== null && (int) $bank['supplier_id'] !== $supplierId)) {
            return 'Bankovní pohyb evidované úhrady nelze doložit.';
        }
        foreach ($rows as $row) {
            $doc = $plans[(int) $row['invoice_id']]['doc'];
            if ($row['source'] !== 'bank' || $row['currency'] !== 'CZK' || $bank['currency'] !== $doc['currency']) {
                return sprintf('Evidovanou úhradu tvoří bankovní pohyb v %s, očekává se %s.', $bank['currency'], $doc['currency']);
            }
            if ((float) $bank['amount'] === 0.0 || ((float) $bank['amount'] < 0) !== ($doc['invoice_type'] === 'credit_note')) {
                return 'Bankovní pohyb má nulovou částku nebo nesouhlasí směr úhrady.';
            }
        }
        if (($bank['payment_ids'] ?? []) !== array_keys($rows) || (bool) $bank['other_allocation']
            || ($bank['matched_invoice_id'] !== null && !in_array((int) $bank['matched_invoice_id'], array_map('intval', array_column($rows, 'invoice_id')), true))) {
            return 'Bankovní pohyb má další alokaci, kterou nelze převést společně s dokladem.';
        }
        if ((float) $bank['amount'] < 0 && count($rows) > 1 && !(bool) $bank['taken_over']) {
            return 'Rozdělenou vratku nelze po změně měny automaticky zaúčtovat.';
        }
        if ((bool) $bank['posted'] && !(bool) $bank['taken_over']) {
            return 'Bankovní pohyb má vlastní účetní zápis. Automatickou kontaci po převodu nelze bezpečně doložit.';
        }
        return $this->locks->reason($supplierId, $bank['posted_at'], true);
    }

    private function forInvoice(int $supplierId, array $plan, array $rows, array $bankAmounts): array|string
    {
        $doc = $plan['doc'];
        $amounts = $plan['amounts'];
        $dueCzk = (float) $doc['amount_to_pay'];
        $dueForeign = round($amounts['total'] - $amounts['advance'], 2);
        $paidCzk = (float) $doc['paid_total'];
        $changed = [];
        $recordedCzk = 0.0;
        foreach ($rows as $row) {
            if (($reason = $this->locks->reason($supplierId, $row['paid_on'], true)) !== null) {
                return $reason;
            }
            $id = (int) $row['id'];
            $home = (float) $row['amount'];
            if ($row['currency'] !== 'CZK' || $home <= 0.0) {
                return 'Doklad má evidovanou úhradu s jinou měnou nebo nepodporovanou částkou.';
            }
            $amount = $bankAmounts[$id] ?? null;
            if ($amount === null && $row['source'] === PohodaPriorYearPayments::SOURCE && $row['bank_transaction_id'] === null
                && in_array($row['note'], [PohodaPriorYearPayments::NOTE, DocumentLinker::REMAINDER_NOTE], true)) {
                $amount = self::proportional($home, abs($dueCzk), abs($dueForeign));
            }
            if ($amount === null || $amount < 0.01) {
                return 'Doklad má evidovanou úhradu, jejíž částku v měně dokladu nelze bezpečně doložit.';
            }
            $recordedCzk += $home;
            $changed[$id] = $amount;
        }
        $sign = $dueCzk < 0 ? -1 : 1;
        $recordedCzk = round($sign * $recordedCzk, 2);
        $unrecorded = round($paidCzk - $recordedCzk, 2);
        if (($dueCzk === 0.0 && $paidCzk !== 0.0) || ($rows !== [] && $sign * $unrecorded < 0)) {
            return 'Evidované úhrady nesouhlasí s uhrazenou částkou dokladu.';
        }
        $paid = round($sign * array_sum($changed) + self::proportional($unrecorded, $dueCzk, $dueForeign), 2);
        if ($paidCzk === $dueCzk && $paid !== $dueForeign) {
            $bankPaid = round($sign * array_sum(array_intersect_key($changed, $bankAmounts)), 2);
            $currency = $doc['currency'];
            $reason = sprintf('Plná úhrada v Kč a úhrady v měně dokladu se rozcházejí. Celkem k úhradě %s %s, úhrady v měně celkem %s %s (z toho bankovní %s %s).',
                number_format($dueForeign, 2, ',', ' '), $currency, number_format($paid, 2, ',', ' '), $currency,
                number_format($bankPaid, 2, ',', ' '), $currency);
            if ($amounts['total'] !== $dueForeign) {
                $reason .= sprintf(' Celkem dokladu %s %s.', number_format($amounts['total'], 2, ',', ' '), $currency);
            }
            return $reason;
        }
        return ['paid' => $paid, 'rows' => $changed, 'links' => array_filter(array_column($rows, 'tax_document_invoice_id', 'id')),
            'dates' => array_column($rows, 'paid_on'),
            'bank_ids' => array_values(array_unique(array_map('intval', array_filter(array_column($rows, 'bank_transaction_id')))))];
    }
}
