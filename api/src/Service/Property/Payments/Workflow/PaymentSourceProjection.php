<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class PaymentSourceProjection
{
    private function __construct(
        private readonly PaymentSourceProjectionRoot $root,
        private readonly int $supplier,
        private readonly array $state,
        private readonly array $prefix,
    ) {}

    public static function begin(Connection $db, int $supplier, PaymentSourcePreview $base): self
    {
        $state = $base->source($supplier);
        $receipt = $state['receipt'] ?? null;
        $native = is_array($receipt) ? ($receipt['native'] ?? null) : null;
        if (!is_array($receipt) || ($receipt['currency'] ?? null) !== 'EUR' || !isset($receipt['ledger'])
            || !is_array($native) || ($native['currency'] ?? null) !== 'EUR' || ($native['minor_unit_decimals'] ?? null) !== 2
            || !is_string($native['amount_minor'] ?? null) || !is_string($native['fx_rate'] ?? null)) {
            throw new \LogicException('Only a proved two-decimal EUR receipt can be projected.');
        }
        return new self(new PaymentSourceProjectionRoot($db, $supplier, $base, $state), $supplier, $state, []);
    }

    public function source(int $supplier): array
    {
        $this->root->assertLive($supplier);
        if ($supplier !== $this->supplier) PaymentException::reject('source_not_found');
        return $this->state;
    }

    public function revalidated(Connection $db, int $supplier): self
    {
        $state = $this->root->revalidatedBase($db, $supplier);
        foreach ($this->prefix as $part) {
            $state = self::apply($state, $part['amount_cents'], $part['selection'], $part['source_native_minor']);
            if (!hash_equals($part['after_hash'], PropertyInput::hash($state))) PaymentException::reject('workflow_plan_changed');
        }
        if (!hash_equals(PropertyInput::hash($state), PropertyInput::hash($this->state))) PaymentException::reject('workflow_plan_changed');
        return new self($this->root, $this->supplier, $state, $this->prefix);
    }

    public function advance(string $key, array $request, array $snapshot): self
    {
        $this->root->assertGeneration($this->supplier);
        foreach ($this->prefix as $part) if ($part['key'] === $key) PaymentException::reject('workflow_plan_changed');
        if (($snapshot['request'] ?? null) !== $request || ($snapshot['source'] ?? null) !== $this->state
            || ($snapshot['source_amount_cents'] ?? null) !== ($request['amount_cents'] ?? null)
            || !isset($snapshot['clearing_selection']) || !is_array($snapshot['clearing_selection'])) {
            PaymentException::reject('workflow_plan_changed');
        }
        $native = $snapshot['source_native_minor'] ?? null;
        if (!is_string($native)) PaymentException::reject('workflow_plan_changed');
        $state = self::apply($this->state, $request['amount_cents'], $snapshot['clearing_selection'], $native);
        $prefix = $this->prefix;
        $prefix[] = [
            'key' => $key,
            'request_hash' => PropertyInput::hash($request),
            'snapshot_hash' => PropertyInput::hash($snapshot),
            'amount_cents' => $request['amount_cents'],
            'source_native_minor' => $native,
            'selection' => $snapshot['clearing_selection'],
            'after_hash' => PropertyInput::hash($state),
        ];
        return new self($this->root, $this->supplier, $state, $prefix);
    }

    public function hash(): string
    {
        $this->root->assertLive($this->supplier);
        return PropertyInput::hash(['base' => $this->root->baseHash(), 'prefix' => $this->prefix, 'state' => $this->state]);
    }

    private static function apply(array $source, string $amount, array $selection, string $expectedNative): array
    {
        $receipt = $source['receipt'];
        $cells = $source['clearing_cells'] ?? $receipt['clearing_cells'] ?? [['key' => 'original', 'amount_cents' => $receipt['amount_cents'], 'line' => $receipt['clearing_line']]];
        $parts = ClearingCells::consume($cells, $source['cell_consumed'] ?? [], $amount, $selection, $source['cell_native_consumed'] ?? []);
        $native = '0';
        foreach ($parts as $part) {
            $key = $part['key'];
            $source['cell_consumed'][$key] = bcadd($source['cell_consumed'][$key] ?? '0', $part['amount_cents'], 0);
            if (!isset($part['native_minor'])) PaymentException::reject('workflow_plan_changed');
            $source['cell_native_consumed'][$key] = bcadd($source['cell_native_consumed'][$key] ?? '0', $part['native_minor'], 0);
            $native = bcadd($native, $part['native_minor'], 0);
        }
        if ($native !== $expectedNative) PaymentException::reject('workflow_plan_changed');
        $source['consumed_cents'] = bcadd($source['consumed_cents'], $amount, 0);
        $source['available_cents'] = bcsub($source['receipt']['amount_cents'], $source['consumed_cents'], 0);
        $source['consumed_native_minor'] = bcadd($source['consumed_native_minor'], $native, 0);
        $source['available_native_minor'] = bcsub($source['receipt']['native']['amount_minor'], $source['consumed_native_minor'], 0);
        $source['version']++;
        return $source;
    }

    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Payment source projections cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Payment source projections cannot be restored.'); }
}

final class PaymentSourceProjectionRoot
{
    private readonly PDO $pdo;
    private readonly string $savepoint;
    private readonly string $baseHash;
    private bool $active = true;

    public function __construct(
        private readonly Connection $db,
        private readonly int $supplier,
        private readonly PaymentSourcePreview $base,
        private readonly array $baseState,
    ) {
        $this->pdo = $db->pdo();
        if (!$this->pdo->inTransaction()) throw new \LogicException('Payment source projection requires an owning transaction.');
        $this->baseHash = PropertyInput::hash([$base->facts, $base->cells, $baseState]);
        $this->savepoint = 'payment_projection_' . bin2hex(random_bytes(10));
        $this->pdo->exec('SAVEPOINT ' . $this->savepoint);
    }

    public function assertLive(int $supplier): void
    {
        if (!$this->active || $supplier !== $this->supplier || !$this->pdo->inTransaction()) throw new \LogicException('Payment source projection is outside its owning transaction.');
    }

    public function assertGeneration(int $supplier): void
    {
        $this->assertLive($supplier);
        try {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint);
            $this->pdo->exec('SAVEPOINT ' . $this->savepoint);
        } catch (\Throwable $e) {
            $this->active = false;
            throw new \LogicException('Payment source projection transaction generation changed.', 0, $e);
        }
    }

    public function revalidatedBase(Connection $db, int $supplier): array
    {
        if ($db !== $this->db || $db->pdo() !== $this->pdo) throw new \LogicException('Payment source projection belongs to another connection.');
        $this->assertLive($supplier);
        $current = $this->base->revalidated($db, $supplier);
        $state = $current->source($supplier);
        if (!hash_equals($this->baseHash, PropertyInput::hash([$current->facts, $current->cells, $state]))
            || !hash_equals(PropertyInput::hash($state), PropertyInput::hash($this->baseState))) PaymentException::reject('preview_stale');
        return $state;
    }

    public function baseHash(): string { return $this->baseHash; }

    public function __destruct()
    {
        if (!$this->active) return;
        $this->active = false;
        try { if ($this->pdo->inTransaction()) $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint); } catch (\Throwable) {}
    }
}
