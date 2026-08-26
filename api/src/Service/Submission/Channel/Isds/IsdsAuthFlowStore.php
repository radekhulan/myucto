<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

interface IsdsAuthFlowStore
{
    public function create(
        string $tokenHash,
        int $supplierId,
        int $userId,
        string $environment,
        string $flowType,
        string $payloadCiphertext,
        int $ttlSeconds,
        int $maxAttempts,
    ): void;

    /** @return array{id:int,payload_ciphertext:string,attempts:int,max_attempts:int}|null */
    public function claim(
        string $tokenHash,
        int $supplierId,
        int $userId,
        string $environment,
        string $flowType,
    ): ?array;

    public function release(int $id): void;

    public function consume(int $id): bool;
}
