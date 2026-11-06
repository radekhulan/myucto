<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;

final class TransactionSavepointToken
{
    private function __construct(private readonly LoggingPdo $pdo, private readonly object $marker) {}

    public static function capture(PDO $pdo, string $name): self
    {
        if (!$pdo instanceof LoggingPdo) throw new \LogicException('Savepoint tokens require tracked PDO.');
        return new self($pdo, $pdo->transactionMarker($name));
    }

    public function current(PDO $pdo): bool
    {
        return $pdo === $this->pdo && $this->pdo->hasTransactionMarker($this->marker);
    }

    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Transaction tokens cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Transaction tokens cannot be restored.'); }
}
