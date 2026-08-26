<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Submission\Channel\Isds\IsdsAuthFlowStore;

final class InMemoryIsdsAuthFlowStore implements IsdsAuthFlowStore
{
    /** @var array<string,array<string,mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    public function create(
        string $tokenHash,
        int $supplierId,
        int $userId,
        string $environment,
        string $flowType,
        string $payloadCiphertext,
        int $ttlSeconds,
        int $maxAttempts,
    ): void {
        $this->rows[$tokenHash] = [
            'id' => $this->nextId++,
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'environment' => $environment,
            'flow_type' => $flowType,
            'payload_ciphertext' => $payloadCiphertext,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'expires_at' => time() + $ttlSeconds,
        ];
    }

    public function claim(
        string $tokenHash,
        int $supplierId,
        int $userId,
        string $environment,
        string $flowType,
    ): ?array {
        $row = $this->rows[$tokenHash] ?? null;
        if ($row === null
            || $row['supplier_id'] !== $supplierId
            || $row['user_id'] !== $userId
            || $row['environment'] !== $environment
            || $row['flow_type'] !== $flowType
            || $row['status'] !== 'pending'
            || $row['expires_at'] < time()
            || $row['attempts'] >= $row['max_attempts']
        ) {
            return null;
        }
        $this->rows[$tokenHash]['status'] = 'processing';
        $this->rows[$tokenHash]['attempts']++;
        return [
            'id' => $row['id'],
            'payload_ciphertext' => $row['payload_ciphertext'],
            'attempts' => $row['attempts'] + 1,
            'max_attempts' => $row['max_attempts'],
        ];
    }

    public function release(int $id): void
    {
        foreach ($this->rows as &$row) {
            if ($row['id'] === $id && $row['status'] === 'processing') {
                $blocked = $row['attempts'] >= $row['max_attempts'] || $row['expires_at'] < time();
                $row['status'] = $blocked ? 'blocked' : 'pending';
                if ($blocked) {
                    $row['payload_ciphertext'] = null;
                }
            }
        }
        unset($row);
    }

    public function consume(int $id): bool
    {
        foreach ($this->rows as &$row) {
            if ($row['id'] === $id && $row['status'] === 'processing') {
                $row['status'] = 'consumed';
                $row['payload_ciphertext'] = null;
                unset($row);
                return true;
            }
        }
        unset($row);
        return false;
    }
}
