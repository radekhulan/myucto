<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Hranice mezi agendou Časové rozlišení a automatickým časovým rozlišením uzávěrky.
 *
 * 1. Uzávěrka (krok Časové rozlišení) a otevření roku vynechají řádky dokladů, ke kterým
 *    existuje položka agendy (`managed_by = agenda`): tu rozpouští agenda, uzávěrka by ji
 *    odložila a rozpustila podruhé ({@see excludesRow()}, volá
 *    {@see \MyInvoice\Service\Accounting\Closing\AccrualDeferralService::withoutAgendaItems()}).
 * 2. Průběžný výkaz mimo deník ({@see interimDeltas()}) a projekce DPPO
 *    ({@see projectionPending()}) přidají jen to, co agenda podle plánu ještě nezaúčtovala.
 *    Co agenda zaúčtovala, je v deníku a výkaz ho čte odtud.
 */
final class AccrualAgendaGuard
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Doklady a řádky dokladů spravované agendou: klíče `druh:id:řádek` (řádek faktury) a
     * `other_item:id` (ostatní položka celá). Koncept položky doklad ještě nespravuje: dokud ho
     * účetní neaktivuje, odkládá doklad dál uzávěrka.
     *
     * @return array<string,true>
     */
    public function managedKeys(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT source_type, source_id, source_item_id
               FROM accrual_items
              WHERE supplier_id = ? AND managed_by = 'agenda' AND status IN ('active', 'completed', 'terminated')
                AND source_type IN ('invoice', 'purchase_invoice', 'other_item') AND source_id IS NOT NULL"
        );
        $stmt->execute([$supplierId]);
        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = $r['source_type'] . ':' . (int) $r['source_id'];
            if ($r['source_item_id'] !== null) {
                $keys[$key . ':' . (int) $r['source_item_id']] = true;
            } elseif ($r['source_type'] === 'other_item') {
                // Ostatní položka nemá řádky s vlastním obdobím: spravuje ji celá položka.
                $keys[$key] = true;
            }
            // Faktura bez určeného řádku: položka nespravuje žádný řádek s obdobím, ty dál
            // odkládá uzávěrka.
        }
        return $keys;
    }

    /**
     * Patří řádek výběru uzávěrky (tvar řádků AccrualDeferralService: `source`, id dokladu,
     * `item_id`) položce agendy?
     *
     * @param array<string,true> $keys
     * @param array<string,mixed> $row
     */
    public static function excludesRow(array $keys, array $row): bool
    {
        if ($keys === []) {
            return false;
        }
        $source = (string) ($row['source'] ?? '');
        $docId = match ($source) {
            'purchase_invoice' => $row['purchase_invoice_id'] ?? null,
            'invoice' => $row['invoice_id'] ?? null,
            'other_item' => $row['other_item_id'] ?? null,
            default => null,
        };
        if ($docId === null) {
            return false;
        }
        $key = $source . ':' . (int) $docId;
        return isset($keys[$key]) || ($source !== 'other_item' && isset($keys[$key . ':' . (int) ($row['item_id'] ?? 0)]));
    }

    /**
     * Počet nezaúčtovaných řádků plánu aktivních položek agendy splatných v období (pojistka
     * uzavření knih).
     */
    public function pendingRowsCount(int $supplierId, string $startsOn, string $endsOn): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM accrual_schedule s
               JOIN accrual_items ai ON ai.id = s.accrual_item_id AND ai.supplier_id = s.supplier_id
          LEFT JOIN journal_entries je ON je.id = s.journal_entry_id AND je.supplier_id = s.supplier_id
              WHERE s.supplier_id = ? AND s.kind = 'release' AND s.due_on >= ? AND s.due_on <= ?
                AND ai.status = 'active' AND ai.managed_by = 'agenda'
                AND (s.status = 'planned' OR (s.status IN ('posted', 'linked') AND (je.id IS NULL OR je.reversed_by IS NOT NULL)))"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Část odkladu uzávěrky (zápis kroku období `$periodId`), kterou tvoří doklady spravované
     * agendou: otevření roku je nerozpouští, rozpouští je agenda. Projekce DPPO ji proto
     * nesmí hlásit jako nezaúčtované rozpuštění minulého období.
     *
     * @param list<array<string,mixed>> $snapshotRows řádky ze záznamu kroku ve tvaru výběru uzávěrky
     */
    public function managedDeferredAmount(int $supplierId, array $snapshotRows, string $endsOn): float
    {
        $keys = $this->managedKeys($supplierId);
        $sum = 0.0;
        foreach ($snapshotRows as $row) {
            if (self::excludesRow($keys, $row)) {
                $snap = $row['snapshot'];
                $sum = round($sum + AccrualScheduleCalculator::deferredAmount((float) $snap['basis'], (string) $snap['from'], (string) $snap['to'], $endsOn), 2);
            }
        }
        return $sum;
    }

    /**
     * Rozdíly zůstatků (MD − D po účtech) průběžného výkazu k datu za položky agendy.
     *
     * Roční metoda: zápis vzniká až k 31. 12., výkaz proto přidá poměrnou část za dny od
     * posledního pokrytého dne do data výkazu (stejný poměr dnů jako uzávěrka). Měsíční
     * metody: přidají se jen řádky plánu splatné do data výkazu, které ještě nejsou
     * zaúčtované; rozpracovaný měsíc ne. Účetní u měsíčních položek zaúčtuje každý měsíc
     * a výkaz má ukázat stav, jako by to udělal včas, ne jiný poměr, než jaký bude v knihách.
     * Ruční metoda nemá plán a nepřidá nic.
     *
     * @return array{deltas: array<string,float>, items:int, total:float}
     */
    public function interimDeltas(int $supplierId, string $periodStartsOn, string $asOf): array
    {
        $deltas = [];
        $count = 0;
        $total = 0.0;
        foreach ($this->pendingByItem($supplierId, $periodStartsOn, $asOf, true) as $p) {
            if ((int) round($p['amount'] * 100) === 0) {
                continue;
            }
            foreach (AccrualKinds::releaseLines($p['kind'], $p['accrual_account_code'], $p['counter_account_code'], $p['amount']) as $line) {
                $signed = $line['side'] === 'debit' ? $line['amount'] : -$line['amount'];
                $deltas[$line['account_code']] = round(($deltas[$line['account_code']] ?? 0.0) + $signed, 2);
            }
            $count++;
            $total = round($total + $p['amount'], 2);
        }
        return ['deltas' => $deltas, 'items' => $count, 'total' => $total];
    }

    /**
     * Projekce DPPO: nezaúčtované rozpuštění položek agendy do konce období (náklad 381 jako
     * kladné `expense`, výnos 384 jako kladné `revenue`). K rozvahovému dni jsou splatné všechny
     * řádky plánu období, roční i měsíční, a uzávěrka je zaúčtuje (roční sama, měsíční účetní).
     *
     * @return array{expense: float, revenue: float, items: int}
     */
    public function projectionPending(int $supplierId, string $startsOn, string $endsOn): array
    {
        $out = ['expense' => 0.0, 'revenue' => 0.0, 'items' => 0];
        foreach ($this->pendingByItem($supplierId, $startsOn, $endsOn, false) as $p) {
            if ((int) round($p['amount'] * 100) === 0) {
                continue;
            }
            $key = AccrualKinds::normalSign($p['kind']) === 1 ? 'expense' : 'revenue';
            $out[$key] = round($out[$key] + $p['amount'], 2);
            $out['items']++;
        }
        return $out;
    }

    /**
     * Nezaúčtované rozpuštění aktivních položek agendy v období [from..asOf].
     *
     * @return list<array{kind:string, accrual_account_code:string, counter_account_code:string, amount:float}>
     */
    private function pendingByItem(int $supplierId, string $from, string $asOf, bool $proRataYearly): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ai.id, ai.kind, ai.method, ai.basis_amount, ai.period_from, ai.period_to, ai.opening_as_of,
                    ai.accrual_account_code, ai.counter_account_code,
                    s.kind AS row_kind, s.due_on, s.from_on, s.to_on, s.amount_planned,
                    CASE WHEN s.status IN ('posted', 'linked') AND (je.id IS NULL OR je.reversed_by IS NOT NULL) THEN 'planned' ELSE s.status END AS row_status
               FROM accrual_items ai
               JOIN accrual_schedule s ON s.accrual_item_id = ai.id AND s.supplier_id = ai.supplier_id
          LEFT JOIN journal_entries je ON je.id = s.journal_entry_id AND je.supplier_id = s.supplier_id
              WHERE ai.supplier_id = ? AND ai.managed_by = 'agenda' AND ai.status = 'active'
                AND ai.counter_account_code IS NOT NULL AND ai.method <> 'manual'
                AND s.kind = 'release'
              ORDER BY ai.id, s.due_on"
        );
        $stmt->execute([$supplierId]);
        $byItem = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byItem[(int) $r['id']][] = $r;
        }
        $out = [];
        foreach ($byItem as $rows) {
            $item = $rows[0];
            $amount = 0.0;
            if ($proRataYearly && $item['method'] === 'yearly') {
                $schedule = array_map(static fn (array $r): array => ['kind' => $r['row_kind'], 'status' => $r['row_status'],
                    'to_on' => $r['to_on'], 'due_on' => $r['due_on']], $rows);
                $covered = AccrualItemService::coveredThrough($item, array_values(array_filter($schedule,
                    static fn (array $r): bool => (string) ($r['to_on'] ?? $r['due_on']) <= $asOf)));
                $start = max($covered ?? AccrualPlanBuilder::dayBefore($from), AccrualPlanBuilder::dayBefore($from));
                $amount = AccrualPlanBuilder::releaseThrough('yearly', (float) $item['basis_amount'],
                    (string) $item['period_from'], (string) $item['period_to'], $start, min($asOf, (string) $item['period_to']));
                // Položka plánem dorovnaná na převzatý zůstatek: nepřekročit nezaúčtovaný plán.
                $planned = 0.0;
                foreach ($rows as $r) {
                    if ($r['row_status'] === 'planned' && $r['due_on'] >= $from) {
                        $planned = round($planned + (float) $r['amount_planned'], 2);
                    }
                }
                if (abs($amount) > abs($planned)) {
                    $amount = $planned;
                }
            } else {
                foreach ($rows as $r) {
                    if ($r['row_status'] === 'planned' && $r['due_on'] >= $from && $r['due_on'] <= $asOf) {
                        $amount = round($amount + (float) $r['amount_planned'], 2);
                    }
                }
            }
            $out[] = [
                'kind' => (string) $item['kind'],
                'accrual_account_code' => (string) $item['accrual_account_code'],
                'counter_account_code' => (string) $item['counter_account_code'],
                'amount' => round($amount, 2),
            ];
        }
        return $out;
    }
}
