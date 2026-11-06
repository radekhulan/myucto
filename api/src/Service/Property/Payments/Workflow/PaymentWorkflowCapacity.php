<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Service\Property\Payments\PaymentException;

final class PaymentWorkflowCapacity
{
    private array $cellUsed;
    private array $cellNativeUsed;
    private array $used = [];
    private array $limits = [];
    private array $refundCounts = [];

    public function __construct(private readonly array $source)
    {
        $this->cellUsed = $source['cell_consumed'] ?? ['original' => $source['consumed_cents']];
        $this->cellNativeUsed = $source['cell_native_consumed'] ?? [];
    }

    public function add(array $snapshot, array $request): array
    {
        $receipt = $this->source['receipt'];
        $cells = $this->source['clearing_cells'] ?? $receipt['clearing_cells'] ?? [['key' => 'original', 'amount_cents' => $receipt['amount_cents'], 'line' => $receipt['clearing_line']]];
        foreach (ClearingCells::consume($cells, $this->cellUsed, $request['amount_cents'], $snapshot['clearing_selection'] ?? null, $this->cellNativeUsed) as $part) {
            $this->cellUsed[$part['key']] = bcadd($this->cellUsed[$part['key']] ?? '0', $part['amount_cents'], 0);
            if (isset($part['native_minor'])) $this->cellNativeUsed[$part['key']] = bcadd($this->cellNativeUsed[$part['key']] ?? '0', $part['native_minor'], 0);
        }
        $details = [];
        foreach ($snapshot['debt_receipts'] ?? [] as $detail) $details[$detail['debt']['id']] = $detail;
        foreach ($snapshot['allocations'] ?? [] as $allocation) {
            $detail = $details[$allocation['debt_id']] ?? null;
            if ($detail === null) PaymentException::reject('workflow_capacity_corrupt');
            if ($allocation['charge_line_id'] !== null) {
                $rows = array_column($detail['lines'], null, 'id');
                $row = $rows[$allocation['charge_line_id']] ?? null;
                $key = 'principal:' . $allocation['charge_line_id'];
            } else {
                $rows = array_column($detail['accessories'], null, 'id');
                $row = $rows[$allocation['accessory_id']] ?? null;
                $key = 'accessory:' . $allocation['accessory_id'];
            }
            if ($row === null) PaymentException::reject('workflow_capacity_corrupt');
            $this->consume($key, $row['remaining_cents'], $allocation['amount_cents']);
            $group = $detail['group'];
            if (isset($detail['liability_projection'])) {
                $groups = array_column($detail['liability_projection']['groups'], null, 'group_key');
                $cap = $groups[$group['group_key']]['remaining_cap_cents'] ?? null;
                if (!is_string($cap)) PaymentException::reject('workflow_capacity_corrupt');
            } else {
                $basis = '0';
                foreach ($detail['lines'] as $line) $basis = bcadd($basis, bcsub($line['line']['amount_cents'], $detail['debt']['reductions'][$line['id']] ?? '0', 0), 0);
                foreach ($detail['accessories'] as $accessory) $basis = bcadd($basis, $accessory['amount_cents'], 0);
                $numerator = bcmul($basis, $group['extent']['num'], 0);
                if (bcmod($numerator, $group['extent']['den'], 0) !== '0') PaymentException::reject('workflow_capacity_corrupt');
                $cap = bcsub(bcdiv($numerator, $group['extent']['den'], 0), $detail['debt']['group_paid'][$group['group_key']] ?? '0', 0);
            }
            $this->consume('group:' . $allocation['debt_id'] . ':' . $group['group_key'], $cap, $allocation['amount_cents']);
            // ClaimAllocationPlanner ověřil každou část; zde sčítáme čerpání téhož doloženého ručení napříč částmi.
            if (isset($detail['liability_projection']) && ($request['payment']['third_party']['basis'] ?? null) === 'guarantor') {
                $guarantee = null;
                foreach ($detail['liability_projection']['groups'] as $candidate) {
                    if ($candidate['role'] === 'guarantor' && in_array($request['payment']['payer_id'], $candidate['members'], true)) $guarantee = $candidate;
                }
                if ($guarantee === null || !is_string($guarantee['remaining_cap_cents'] ?? null)) PaymentException::reject('workflow_capacity_corrupt');
                $this->consume('guarantee:' . $allocation['debt_id'] . ':' . $guarantee['group_key'], $guarantee['remaining_cap_cents'], $allocation['amount_cents']);
            }
        }
        if (isset($snapshot['reservation'])) {
            $reservation = $snapshot['reservation'];
            $credit = $snapshot['credit'];
            $reservationKey = 'reservation:' . $reservation['id'];
            $creditKey = 'credit:' . $credit['id'];
            $reservationPaid = $this->used[$reservationKey] ?? '0';
            $creditPaid = $this->used[$creditKey] ?? '0';
            $this->consume($reservationKey, bcsub($reservation['amount_cents'], $reservation['paid_cents'], 0), $request['amount_cents']);
            $this->consume($creditKey, $credit['balance_cents'], $request['amount_cents']);
            $snapshot['reservation']['paid_cents'] = bcadd($reservation['paid_cents'], $reservationPaid, 0);
            $snapshot['reservation']['row_version'] += $this->refundCounts[$reservationKey] ?? 0;
            $snapshot['credit']['balance_cents'] = bcsub($credit['balance_cents'], $creditPaid, 0);
            $snapshot['credit']['reserved_cents'] = bcsub($credit['reserved_cents'], $creditPaid, 0);
            $snapshot['credit']['available_cents'] = bcsub($snapshot['credit']['balance_cents'], $snapshot['credit']['reserved_cents'], 0);
            $snapshot['credit']['row_version'] += $this->refundCounts[$creditKey] ?? 0;
            $snapshot['request']['expected_reservation_version'] = $snapshot['reservation']['row_version'];
            $this->refundCounts[$reservationKey] = ($this->refundCounts[$reservationKey] ?? 0) + 1;
            $this->refundCounts[$creditKey] = ($this->refundCounts[$creditKey] ?? 0) + 1;
        }
        return $snapshot;
    }

    private function consume(string $key, string $limit, string $amount): void
    {
        if (isset($this->limits[$key]) && $this->limits[$key] !== $limit) PaymentException::reject('workflow_capacity_corrupt');
        $this->limits[$key] = $limit;
        $this->used[$key] = bcadd($this->used[$key] ?? '0', $amount, 0);
        if (bccomp($this->used[$key], $limit, 0) > 0) PaymentException::reject('workflow_part_capacity', ['reference' => $key, 'available_cents' => $limit, 'requested_cents' => $this->used[$key]]);
    }
}
