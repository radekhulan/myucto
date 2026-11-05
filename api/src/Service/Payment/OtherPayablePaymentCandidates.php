<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Service\Accounting\OtherItemInstallmentCoverage;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use PDO;

/**
 * Ostatní závazky (other_items, strana payable) do platebního příkazu.
 *
 * Kandidát je potvrzený nebo zaúčtovaný korunový závazek se zbytkem k úhradě. Má-li
 * splátkový kalendář, nabízí se nejbližší otevřená splátka, která ještě není v příkazu
 * (částka = její zbytek podle {@see OtherItemInstallmentCoverage}); bez kalendáře celý
 * zbytek. Splátky už zařazené do nearchivovaného příkazu se vrací jen pro informaci
 * (`ordered_at`, nevybratelné), aby se nezaplatily dvakrát.
 *
 * Stav „v platebním příkazu" se odvozuje z payment_order_items, takže smazání příkazu
 * ho vrátí samo. Uhrazení zapisuje až párování bankovního pohybu, ne příkaz.
 *
 * Příjemce, VS i částku odvozuje server, klient posílá jen ID závazku (a případně
 * splátku, kterou viděl), takže si do příkazu nemůže podstrčit jiný účet ani částku.
 */
final class OtherPayablePaymentCandidates
{
    /** Strop počtu závazků v jednom výpisu kandidátů (seznam se nestránkuje). */
    private const MAX_ITEMS = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemRepository $items,
    ) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, ?string $today = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT oi.id, oi.kind, oi.kind_id, k.name AS kind_name, oi.title, oi.partner_name, oi.document_no,
                    oi.due_on, oi.variable_symbol, oi.counterparty_account, oi.counterparty_bank_code,
                    oi.amount - oi.settled_outside_amount
                      - COALESCE((SELECT SUM(a.amount) FROM other_item_allocations a
                                   WHERE a.other_item_id = oi.id AND a.supplier_id = oi.supplier_id
                                     AND a.reversed_on IS NULL), 0) AS remaining_amount
               FROM other_items oi
          LEFT JOIN other_item_kinds k ON k.id = oi.kind_id AND k.supplier_id = oi.supplier_id
              WHERE oi.supplier_id = ? AND oi.side = 'payable' AND oi.status IN ('confirmed', 'posted')
                AND oi.deleted_at IS NULL AND oi.currency = 'CZK'
             HAVING remaining_amount > 0.005
              ORDER BY oi.due_on, oi.id
              LIMIT " . self::MAX_ITEMS
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $coverage = $this->items->installmentCoverage($supplierId, $ids, $today);
        $ordered = $this->orderedAt($supplierId, $ids);

        $out = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['id'];
            $plan = $coverage[$itemId] ?? null;
            if ($plan === null) {
                $out[] = $this->candidate($row, null, 0, round((float) $row['remaining_amount'], 2),
                    (string) $row['due_on'], $ordered[$itemId][0] ?? null);
                continue;
            }
            $count = count($plan);
            foreach (OtherItemInstallmentCoverage::open($plan) as $installment) {
                $orderedAt = $ordered[$itemId][(int) $installment['id']] ?? null;
                $out[] = $this->candidate($row, $installment, $count, round((float) $installment['remaining_amount'], 2),
                    (string) $installment['due_on'], $orderedAt);
                if ($orderedAt === null) {
                    break;
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => [$a['due_date'], $a['other_item_id'], $a['installment_position'] ?? 0]
            <=> [$b['due_date'], $b['other_item_id'], $b['installment_position'] ?? 0]);
        return $out;
    }

    /**
     * Položky platebního příkazu pro vybrané závazky. Požadavek je ID závazku, nebo
     * `{id, installment_id}`: se splátkou projde jen tehdy, když je to pořád ta, kterou
     * seznam nabízí (jinak ji mezitím zařadil jiný příkaz nebo ji uhradila platba).
     *
     * @param list<mixed> $requested
     * @return array{0:list<array<string,mixed>>, 1:list<array<string,mixed>>}
     */
    public function items(int $supplierId, array $requested, string $orderCurrency, ?string $batchKs): array
    {
        $wanted = [];
        foreach ($requested as $entry) {
            $id = is_array($entry) ? (int) ($entry['id'] ?? $entry['other_item_id'] ?? 0) : (int) $entry;
            if ($id <= 0 || array_key_exists($id, $wanted)) {
                continue;
            }
            $installment = is_array($entry) && isset($entry['installment_id']) && $entry['installment_id'] !== null
                ? (int) $entry['installment_id'] : null;
            $wanted[$id] = $installment;
        }
        if ($wanted === []) {
            return [[], []];
        }

        $payable = [];
        $orderedOnly = [];
        foreach ($this->list($supplierId) as $candidate) {
            $id = (int) $candidate['other_item_id'];
            if ($candidate['ordered_at'] === null) {
                $payable[$id] = $candidate;
            } else {
                $orderedOnly[$id] = true;
            }
        }

        $items = [];
        $skipped = [];
        foreach ($wanted as $id => $installmentId) {
            $skip = static function (string $reason) use (&$skipped, $id): void {
                $skipped[] = ['id' => $id, 'reason' => $reason, 'document' => 'other_item'];
            };
            $candidate = $payable[$id] ?? null;
            if ($candidate === null) {
                $skip(isset($orderedOnly[$id]) ? 'already_ordered' : 'nothing_to_pay');
                continue;
            }
            if ($installmentId !== null && $candidate['installment_id'] !== $installmentId) {
                $skip('already_ordered');
                continue;
            }
            if ($orderCurrency !== 'CZK') {
                $skip('currency_mismatch');
                continue;
            }
            if (!$candidate['has_account']) {
                $skip('no_account');
                continue;
            }
            $items[] = [
                'purchase_invoice_id'       => null,
                'other_item_id'             => $id,
                'other_item_installment_id' => $candidate['installment_id'],
                'payee_name'                => $this->truncate($candidate['partner_name'] ?? null, 255),
                'payee_account_number'      => $candidate['account_number'],
                'payee_bank_code'           => $candidate['bank_code'],
                'payee_iban'                => $candidate['iban'],
                'payee_bic'                 => null,
                'amount'                    => $candidate['amount'],
                'currency'                  => 'CZK',
                'variable_symbol'           => $candidate['variable_symbol'],
                'constant_symbol'           => $batchKs,
                'specific_symbol'           => null,
                'message'                   => $this->truncate($this->message($candidate), 140),
                'account_verified'          => 'na',
            ];
        }
        return [$items, $skipped];
    }

    /**
     * @param array<string,mixed>      $row
     * @param array<string,mixed>|null $installment
     * @return array<string,mixed>
     */
    private function candidate(array $row, ?array $installment, int $installmentCount, float $amount, string $dueDate, ?string $orderedAt): array
    {
        $account = $row['counterparty_account'] !== null ? (string) $row['counterparty_account'] : '';
        $bank = $row['counterparty_bank_code'] !== null ? (string) $row['counterparty_bank_code'] : '';
        // Uložený tvar (OtherItemCounterpartyAccount): český účet s kódem banky, nebo
        // zahraniční IBAN bez kódu banky.
        $czech = $account !== '' && $bank !== '';
        $iban = $account !== '' && $bank === '' ? $account : null;
        $vs = VariableSymbolNormalizer::forPayment((string) ($row['variable_symbol'] ?? ''));
        $hasAccount = $czech || $iban !== null;

        return [
            'key'                  => 'other:' . (int) $row['id'] . ($installment !== null ? ':' . (int) $installment['id'] : ''),
            'other_item_id'        => (int) $row['id'],
            'installment_id'       => $installment !== null ? (int) $installment['id'] : null,
            'installment_position' => $installment !== null ? (int) $installment['position'] : null,
            'installment_count'    => $installment !== null ? $installmentCount : null,
            'side'                 => 'payable',
            'kind'                 => (string) $row['kind'],
            'kind_name'            => $row['kind_name'] !== null ? (string) $row['kind_name'] : null,
            'title'                => (string) $row['title'],
            'partner_name'         => $row['partner_name'] !== null ? (string) $row['partner_name'] : null,
            'document_no'          => $row['document_no'] !== null ? (string) $row['document_no'] : null,
            'due_date'             => $dueDate,
            'amount'               => $amount,
            'currency'             => 'CZK',
            'account_number'       => $czech ? $account : null,
            'bank_code'            => $czech ? $bank : null,
            'iban'                 => $iban,
            'variable_symbol'      => $vs !== '' ? $vs : null,
            'has_account'          => $hasAccount,
            'abo_eligible'         => $czech,
            'ordered_at'           => $orderedAt,
            'payable'              => $hasAccount && $orderedAt === null,
            'reason'               => $orderedAt !== null ? 'already_ordered' : ($hasAccount ? null : 'no_account'),
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function message(array $candidate): string
    {
        $parts = array_filter([
            $candidate['document_no'] ?? null,
            $candidate['title'] ?? null,
            $candidate['installment_position'] !== null
                ? $candidate['installment_position'] . '/' . $candidate['installment_count']
                : null,
        ], static fn ($part): bool => $part !== null && $part !== '');
        return implode(' ', $parts);
    }

    private function truncate(?string $value, int $length): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    /**
     * Kdy byl závazek (splátka) naposledy zařazen do nearchivovaného příkazu. Klíč 0 =
     * položka bez splátky (celý zbytek).
     *
     * @param list<int> $itemIds
     * @return array<int, array<int, string>>
     */
    private function orderedAt(int $supplierId, array $itemIds): array
    {
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT poi.other_item_id, COALESCE(poi.other_item_installment_id, 0) AS installment_id,
                    MAX(po.created_at) AS ordered_at
               FROM payment_order_items poi
               JOIN payment_orders po ON po.id = poi.payment_order_id
              WHERE poi.other_item_id IN ({$in}) AND po.supplier_id = ? AND po.archived_at IS NULL
              GROUP BY poi.other_item_id, COALESCE(poi.other_item_installment_id, 0)"
        );
        $stmt->execute([...$itemIds, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['other_item_id']][(int) $row['installment_id']] = (string) $row['ordered_at'];
        }
        return $out;
    }
}
