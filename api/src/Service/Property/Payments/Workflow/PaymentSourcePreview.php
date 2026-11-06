<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Service\Property\Payments\PaymentException;

final class PaymentSourcePreview
{
    private function __construct(public readonly array $facts, public readonly array $cells, private readonly PaymentSourceEligibility $eligibility, private readonly array $request) {}

    public static function capture(PaymentSourceEligibility $eligibility, int $supplier, array $source, array $cells): self
    {
        $facts = $eligibility->current($supplier, $source);
        $normalized = ClearingCells::normalize($facts['amount_cents'], $cells);
        if ($facts['existing'] !== null) {
            $receipt = $facts['existing']['receipt'];
            $actual = $facts['existing']['clearing_cells'] ?? $receipt['clearing_cells'] ?? [['key' => 'original', 'amount_cents' => $receipt['amount_cents'], 'line' => $receipt['clearing_line']]];
            $expected = [];
            foreach ($actual as $cell) {
                if ($cell['amount_cents'] === '0') continue;
                if (($cell['line']['dimension_splits'] ?? []) !== []) PaymentException::reject('clearing_revision_required');
                $expected[] = ['key' => $cell['key'], 'amount_cents' => $cell['amount_cents'], 'dimensions' => $cell['line']['dimensions']];
            }
            if (\MyInvoice\Service\Property\PropertyInput::hash($normalized) !== \MyInvoice\Service\Property\PropertyInput::hash(ClearingCells::normalize($facts['amount_cents'], $expected))) PaymentException::reject('clearing_revision_required');
        }
        return new self($facts, $normalized, $eligibility, $source);
    }

    public function revalidated(\MyInvoice\Infrastructure\Database\Connection $db, int $supplier): self
    {
        $this->eligibility->assertConnection($db);
        $current = self::capture($this->eligibility, $supplier, $this->request, $this->cells);
        if (\MyInvoice\Service\Property\PropertyInput::hash([$current->facts, $current->cells]) !== \MyInvoice\Service\Property\PropertyInput::hash([$this->facts, $this->cells])) PaymentException::reject('preview_stale');
        return $current;
    }

    public function source(int $supplier): array
    {
        if ($supplier !== $this->facts['supplier_id']) PaymentException::reject('source_not_found');
        if ($this->facts['existing'] !== null) return $this->facts['existing'];
        $receipt = ['kind' => $this->facts['kind'], 'id' => $this->facts['id'], 'direction' => $this->facts['direction'],
            'amount_cents' => $this->facts['amount_cents'], 'date' => $this->facts['date'], 'currency' => 'CZK',
            'counterparty_account' => $this->facts['raw']['counterparty_account'] ?? null,
            'counterparty_bank' => $this->facts['raw']['counterparty_bank'] ?? null,
            'partner_name' => $this->facts['native_draft']['partner_name'] ?? null];
        $receipt['clearing_cells'] = [];
        foreach ($this->cells as $cell) {
            $origins = [];
            foreach ($cell['dimensions'] as $type => $value) $origins[$type] = ['origin' => 'explicit', 'ref' => null];
            $receipt['clearing_cells'][] = ['key' => $cell['key'], 'amount_cents' => $cell['amount_cents'], 'line' => [
                'account_code' => '379.900', 'side' => $this->facts['direction'] === 'in' ? 'credit' : 'debit',
                'amount' => bcdiv($cell['amount_cents'], '100', 2), 'currency_code' => null, 'amount_foreign' => null,
                'fx_rate' => null, 'is_red_storno' => false, 'dimensions' => $cell['dimensions'], 'dimension_splits' => [],
                'dimension_origins' => $origins, 'dimension_split_origins' => [],
            ]];
        }
        return ['id' => null, 'version' => 0, 'consumed_cents' => '0', 'available_cents' => $this->facts['amount_cents'],
            'consumed_native_minor' => null, 'available_native_minor' => null, 'receipt' => $receipt, 'cell_consumed' => [], 'cell_native_consumed' => []];
    }

    public static function clearingLines(array $source, string $amount, ?array $selection): array
    {
        $receipt = $source['receipt'];
        if (isset($source['clearing_cells'])) {
            $cells = $source['clearing_cells']; $used = $source['cell_consumed'];
        } elseif (!isset($receipt['clearing_cells'])) {
            $cells = [['key' => 'original', 'amount_cents' => $receipt['amount_cents'], 'line' => $receipt['clearing_line']]];
            $used = ['original' => $source['consumed_cents']];
        } else {
            $cells = $receipt['clearing_cells'];
            if (!isset($source['cell_consumed']) || !is_array($source['cell_consumed'])) PaymentException::reject('clearing_capacity_corrupt');
            $used = $source['cell_consumed'];
        }
        $selected = ClearingCells::consume($cells, $used, $amount, $selection, $source['cell_native_consumed'] ?? []);
        $byKey = array_column($cells, null, 'key');
        $lines = [];
        foreach ($selected as $part) {
            $line = $byKey[$part['key']]['line'];
            unset($line['id']);
            $line['side'] = $receipt['direction'] === 'in' ? 'debit' : 'credit';
            $line['amount'] = bcdiv($part['amount_cents'], '100', 2);
            if (isset($part['native_minor'])) $line['amount_foreign'] = bcdiv($part['native_minor'], '100', 2);
            $lines[] = $line;
        }
        return ['lines' => $lines, 'selection' => $selected];
    }
}
