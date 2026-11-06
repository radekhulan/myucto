<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\Fx504Builder;
use MyInvoice\Service\Accounting\Closing\Fx504CaseProjection;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;

final class Fx504CaseReadRepository
{
    public function __construct(private readonly Connection $db) {}

    public function snapshot(int $supplierId, array $period, array $rows, array $headers, ?string $anchor, bool $currentRead): array
    {
        $native = (new FxCaseOriginReadRepository($this->db))->snapshot($supplierId, $rows, $currentRead);
        $stmt = $this->db->pdo()->prepare("SELECT s.id,s.period_id,s.step_key,s.status,s.payload,p.ends_on
            FROM accounting_closing_steps s JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id
            WHERE s.supplier_id=? AND s.step_key IN ('fx_revaluation','open_next') AND p.ends_on<=?
                AND s.period_id<>? ORDER BY p.ends_on,s.id" . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $period['ends_on'], $period['id']]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byEntry = [];
        foreach ($rows as $row) $byEntry[$row['entry_id']][(int) $row['line_no']] = $row;
        $head = array_column($headers, null, 'id');
        $removed = [];
        $findings = [];
        foreach (array_reverse($headers) as $header) {
            $id = (int) $header['id'];
            $reverseId = (int) ($header['reversed_by'] ?? 0);
            if (!$reverseId || !isset($head[$reverseId]) || isset($removed[$id]) || isset($removed[$reverseId])) continue;
            $original = $byEntry[$id] ?? [];
            $reverse = $byEntry[$reverseId] ?? [];
            if ($original === [] && $reverse === []) continue;
            if (count($original) !== count($reverse)) { $findings[] = ['code' => 'case_cancellation_changed', 'entry_id' => $id]; continue; }
            $same = true;
            foreach ($original as $lineNo => $row) {
                $mirror = $reverse[$lineNo] ?? null;
                if ($mirror === null || !self::same($mirror, JournalLineAmount::reversal($row))) { $same = false; break; }
            }
            if (!$same) { $findings[] = ['code' => 'case_cancellation_changed', 'entry_id' => $id]; continue; }
            $removed[$id] = $removed[$reverseId] = true;
        }
        $origins = $native['origins'];
        $openings = [];
        $priorDecisions = [];
        foreach ($steps as $step) {
            if ($step['status'] !== 'done') continue;
            $payload = json_decode((string) $step['payload'], true, 512, JSON_THROW_ON_ERROR);
            $carry = $payload['carry']['open_next'] ?? null;
            if ($step['step_key'] === 'open_next' && is_array($carry) && ($carry['status'] ?? null) === 'written') {
                $id = (int) ($carry['entry_id'] ?? 0);
                $header = $head[$id] ?? null;
                if ($header && $header['source_type'] === 'opening' && (int) $header['period_id'] === (int) $carry['target_period_id']
                    && (int) $header['row_version'] === (int) $carry['entry_version'] && $header['entry_date'] === $carry['entry_date']) $openings[$id] = true;
                elseif ($id && !isset($removed[$id])) $findings[] = ['code' => 'case_opening_changed', 'entry_id' => $id];
            }
            $record = $payload['fx504'] ?? null;
            if ($step['step_key'] !== 'fx_revaluation' || !is_array($record)) continue;
            if (isset($record['case_materialization']) && hash('sha256', json_encode($record['case_materialization'], JSON_THROW_ON_ERROR)) !== ($record['case_materialization_hash'] ?? null)) {
                $findings[] = ['code' => 'case_fx_payload_changed', 'step_id' => (int) $step['id']];
                continue;
            }
            if (is_array($record['case_decisions'] ?? null)) $priorDecisions = $record['case_decisions'];
            foreach (['saldo', 'bank', 'reversal'] as $slot) {
                $id = (int) ($record['slots'][$slot]['entry_id'] ?? 0);
                if (!$id || isset($removed[$id]) || !isset($byEntry[$id])) continue;
                $plan = $record['case_materialization'][$slot === 'reversal' ? 'saldo' : $slot] ?? [];
                foreach ($byEntry[$id] as $lineNo => $row) {
                    $proof = $plan[$lineNo] ?? null;
                    $expected = $proof['line'] ?? null;
                    if ($slot === 'reversal' && is_array($expected)) $expected = JournalLineAmount::reversal($expected);
                    if (!is_array($expected) || !is_array($proof['origin'] ?? null) || !self::same($row, $expected)) {
                        $findings[] = ['code' => 'case_fx_origin_changed', 'line_id' => (int) $row['id']];
                        continue;
                    }
                    $origins[(int) $row['id']] = [Fx504CaseProjection::origin($proof['origin'])];
                }
            }
        }
        $history = array_values(array_filter($rows, static fn (array $row): bool => !isset($removed[$row['entry_id']])
            && $row['source_type'] !== 'closing' && !isset($openings[$row['entry_id']])));
        $conversion=(new \MyInvoice\Service\Accounting\Conversion\FrameworkConversionFxAdapter($this->db))->project($supplierId,$period,$history,$origins,$currentRead);
        $history=$conversion['rows'];$origins=$conversion['origins'];
        $current = array_values(array_filter($rows, static fn (array $row): bool => ($anchor === null || $row['entry_date'] >= $anchor)
            && $row['source_type'] !== 'closing'));
        if (self::balances($history) !== self::balances($current)) $findings[] = ['code' => 'case_opening_reconciliation'];
        $cellKeys = array_keys(Fx504CaseProjection::cells($history));
        $prior = [];
        foreach ($priorDecisions as $decision) {
            $decision['contributions'] = array_values(array_filter($decision['contributions'] ?? [], static fn (array $part): bool => in_array($part['cell_key'] ?? null, $cellKeys, true)));
            if ($decision['contributions'] !== []) $prior[] = $decision;
        }
        return ['rows' => $history, 'origins' => $origins, 'prior_decisions' => $prior, 'findings' => $findings,
            'hash' => hash('sha256', json_encode([$native['facts_hash'], $steps, $removed, $openings, $history, $origins,$conversion['proof']], JSON_THROW_ON_ERROR))];
    }

    public static function same(array $row, array $expected): bool
    {
        foreach (['account_code', 'currency_code', 'cost_center', 'project_id'] as $key) if (($row[$key] ?? null) !== ($expected[$key] ?? null)) return false;
        if (($row['dimensions'] ?? []) !== ($expected['dimensions'] ?? []) || ($row['dimension_splits'] ?? []) !== ($expected['dimension_splits'] ?? [])) return false;
        $sign = $row['side'] === 'debit' ? 1 : -1;
        $expectedSign = $expected['side'] === 'debit' ? 1 : -1;
        return $sign * JournalLineAmount::signedCents($row) === $expectedSign * JournalLineAmount::signedCents($expected)
            && $sign * JournalLineAmount::signedCents(['amount' => $row['amount_foreign'] ?? '0.00', 'is_red_storno' => $row['is_red_storno'] ?? false])
            === $expectedSign * JournalLineAmount::signedCents(['amount' => $expected['amount_foreign'] ?? '0.00', 'is_red_storno' => $expected['is_red_storno'] ?? false]);
    }

    private static function balances(array $rows): array
    {
        $balances = [];
        foreach (Fx504Builder::positions($rows) as $position) $balances[$position['key']] = [$position['czk_cents'], $position['foreign_cents']];
        ksort($balances);
        return $balances;
    }
}
