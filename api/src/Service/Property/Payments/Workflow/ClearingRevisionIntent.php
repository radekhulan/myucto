<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\ClearingRevisionRepository;

final class ClearingRevisionIntent
{
    private function __construct(private readonly Connection $db, private readonly ClearingRevisionRepository $owner,
        public readonly int $supplier, public readonly int $revisionId) {}

    public static function pending(Connection $db, ClearingRevisionRepository $owner, int $supplier, int $id): self
    {
        $intent = new self($db, $owner, $supplier, $id); $intent->journal($db); return $intent;
    }

    public function __serialize(): array { throw new \LogicException('Source revision intents cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Source revision intents cannot be restored.'); }
    private function __clone() {}

    public function journal(Connection $db): array
    {
        if ($db->pdo() !== $this->db->pdo() || !$db->pdo()->inTransaction()) throw new \LogicException('Source revision requires the same owner transaction.');
        return $this->owner->provePending($db, $this->supplier, $this->revisionId);
    }

    public function complete(Connection $db, int $entry): void
    {
        if ($db->pdo() !== $this->db->pdo() || !$db->pdo()->inTransaction()) throw new \LogicException('Source revision requires the same owner transaction.');
        $this->owner->completePending($this, $entry);
    }
}
