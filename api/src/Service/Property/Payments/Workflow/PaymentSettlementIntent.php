<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\PaymentRepository;

final class PaymentSettlementIntent
{
    private function __construct(private readonly Connection $db, private readonly PaymentRepository $payments,
        public readonly int $supplier, public readonly int $operationId) {}

    public static function fromPending(Connection $db, PaymentRepository $payments, int $supplier, int $id): self
    {
        $intent = new self($db, $payments, $supplier, $id);
        $intent->journal($db);
        return $intent;
    }

    public function __serialize(): array { throw new \LogicException('Settlement intents cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Settlement intents cannot be restored.'); }
    private function __clone() {}

    public function journal(Connection $db): array
    {
        if ($db->pdo() !== $this->db->pdo() || !$db->pdo()->inTransaction()) throw new \LogicException('Settlement intent requires the same owner transaction.');
        return $this->payments->provePendingSettlement($db, $this->supplier, $this->operationId);
    }
}
