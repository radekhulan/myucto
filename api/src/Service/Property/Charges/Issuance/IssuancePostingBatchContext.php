<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class IssuancePostingBatchContext
{
    private bool $active = true;
    private array $documents = [];

    private function __construct(
        private readonly Connection $db,
        private readonly PDO $pdo,
        private readonly int $supplierId,
        private readonly string $savepoint,
        private readonly array $periodStatuses,
        private readonly ?string $lockedUntil,
        array $documents,
    ) {
        foreach ($documents as $document) {
            $id = (int) $document['source_id'];
            if (isset($this->documents[$id])) {
                throw new \LogicException('Duplicate issuance posting capability source.');
            }
            $this->documents[$id] = $document + ['state' => 'ready'];
        }
        $this->pdo->exec('SAVEPOINT ' . $this->savepoint);
    }

    public static function begin(
        Connection $db,
        int $supplierId,
        array $periodStatuses,
        ?string $lockedUntil,
        array $documents,
    ): self {
        $pdo = $db->pdo();
        if (!$pdo->inTransaction() || $documents === []) {
            throw new \LogicException('Issuance batch capability requires an owning transaction and documents.');
        }
        $savepoint = 'issuance_batch_' . bin2hex(random_bytes(12));
        return new self($db, $pdo, $supplierId, $savepoint, $periodStatuses, $lockedUntil, $documents);
    }

    public function claim(Connection $db, int $supplierId, int $sourceId, array $lines, array $meta): array
    {
        $this->assertLive($db);
        $document = $this->documents[$sourceId] ?? null;
        if ($supplierId !== $this->supplierId || $document === null || $document['state'] !== 'ready'
            || !hash_equals($document['request_hash'], self::requestHash($supplierId, $sourceId, $lines, $meta))) {
            throw new \LogicException('Stale, reused, or foreign issuance batch capability.');
        }
        $this->rotateSavepoint();
        $this->documents[$sourceId]['state'] = 'claimed';
        return $document;
    }

    public function assertJournalAfterCore(Connection $db, array $header, array $lines, array $previousLines): void
    {
        $this->assertLive($db);
        $sourceId = isset($header['source_id']) ? (int) $header['source_id'] : 0;
        $document = $this->documents[$sourceId] ?? null;
        if ($previousLines !== [] || $document === null || $document['state'] !== 'claimed'
            || (int) ($header['supplier_id'] ?? 0) !== $this->supplierId
            || ($header['source_type'] ?? null) !== 'unit_charge'
            || ($header['entry_date'] ?? null) !== $document['entry_date']
            || (int) ($header['period_id'] ?? 0) !== (int) $document['header']['period_id']
            || ($header['document_date'] ?? null) !== $document['header']['document_date']
            || ($header['document_no'] ?? null) !== $document['header']['document_no']
            || ($header['description'] ?? null) !== $document['header']['description']
            || ($header['posted_by'] ?? null) !== $document['header']['posted_by']
            || ($header['posted_at'] ?? null) === null
            || PropertyInput::hash($lines) !== $document['resolved_hash']) {
            throw new \LogicException('Issuance journal does not match its batch capability.');
        }
        $this->rotateSavepoint();
        $this->documents[$sourceId]['state'] = 'journal_validated';
    }

    public function complete(Connection $db, int $sourceId): void
    {
        $this->assertLive($db);
        if (($this->documents[$sourceId]['state'] ?? null) !== 'journal_validated') {
            throw new \LogicException('Issuance batch capability was not validated by the journal owner.');
        }
        $this->rotateSavepoint();
        $this->documents[$sourceId]['state'] = 'complete';
    }

    public function periodStatuses(Connection $db): array
    {
        $this->assertLive($db);
        return $this->periodStatuses;
    }

    public function lockedUntil(Connection $db): ?string
    {
        $this->assertLive($db);
        return $this->lockedUntil;
    }

    public function close(): void
    {
        if (!$this->active) return;
        $this->active = false;
        try {
            if ($this->pdo->inTransaction()) $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint);
        } catch (\Throwable) {
        }
    }

    public static function requestHash(int $supplierId, int $sourceId, array $lines, array $meta): string
    {
        return PropertyInput::hash([$supplierId, 'unit_charge', $sourceId, $lines, $meta]);
    }

    private function assertLive(Connection $db): void
    {
        if (!$this->active || $db !== $this->db || $db->pdo() !== $this->pdo || !$this->pdo->inTransaction()) {
            throw new \LogicException('Issuance batch capability is outside its owning transaction.');
        }
    }

    private function rotateSavepoint(): void
    {
        try {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint);
            $this->pdo->exec('SAVEPOINT ' . $this->savepoint);
        } catch (\Throwable $e) {
            $this->active = false;
            throw new \LogicException('Issuance batch transaction generation changed.', 0, $e);
        }
    }

    private function __clone() {}

    public function __serialize(): array
    {
        throw new \LogicException('Issuance batch capability cannot be serialized.');
    }

    public function __destruct()
    {
        $this->close();
    }
}
