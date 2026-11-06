<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final readonly class MembershipCloneCapture
{
    public const BATCH_SIZE = 500;
    private const TYPES = ['member', 'revision', 'operation'];

    public function __construct(
        public string $database,
        public string $token,
        public string $fileHash,
        public string $chainHash,
        public string $financialHash,
        public array $counts,
    ) {
        if ($database === '' || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $fileHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $chainHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $financialHash) !== 1
            || array_keys($counts) !== self::TYPES
            || array_filter($counts, static fn (mixed $count): bool => !is_int($count) || $count < 0) !== []) {
            throw new \InvalidArgumentException('Membership clone capture handle is invalid.');
        }
    }

    public function maximumBatchRows(): int
    {
        return self::BATCH_SIZE;
    }

    public function batches(string $type): \Generator
    {
        if (!in_array($type, self::TYPES, true)) throw new \InvalidArgumentException('Membership clone spool record type is invalid.');
        $batch = [];
        foreach ($this->records() as $record) {
            if ($record['type'] !== $type) continue;
            $batch[] = $record['data'];
            if (count($batch) === self::BATCH_SIZE) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch !== []) yield $batch;
    }

    public function assertReadable(): void
    {
        $path = $this->ownedPath();
        if (!is_file($path) || !hash_equals($this->fileHash, hash_file('sha256', $path))) {
            throw new \LogicException('Membership clone capture spool is missing or changed.');
        }
    }

    public function cleanup(): void
    {
        $path = self::pathForToken($this->token);
        if (!is_file($path)) return;
        $path = $this->ownedPath();
        if (!unlink($path)) throw new \RuntimeException('Membership clone capture spool could not be removed.');
    }

    public function __destruct()
    {
        try {
            $path = self::pathForToken($this->token);
            if (is_file($path)) @unlink($this->ownedPath());
        } catch (\Throwable) {
        }
    }

    public static function directory(): string
    {
        return RuntimePaths::storage('cache/membership-clone');
    }

    public static function pathForToken(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) throw new \InvalidArgumentException('Membership clone capture token is invalid.');
        return self::directory() . DIRECTORY_SEPARATOR . 'membership-' . $token . '.ndjson';
    }

    private function records(): \Generator
    {
        $path = $this->ownedPath();
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('Membership clone capture spool could not be opened.');
        $file = hash_init('sha256');
        $previous = str_repeat('0', 64);
        $sequence = 0;
        $counts = array_fill_keys(self::TYPES, 0);
        try {
            while (($line = fgets($handle)) !== false) {
                hash_update($file, $line);
                $record = json_decode(rtrim($line, "\r\n"), true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($record) || array_keys($record) !== ['sequence', 'type', 'data', 'previous_hash', 'record_hash']
                    || !is_int($record['sequence']) || $record['sequence'] !== ++$sequence
                    || !in_array($record['type'], self::TYPES, true) || !is_array($record['data'])
                    || !is_string($record['previous_hash']) || !hash_equals($previous, $record['previous_hash'])) {
                    throw new \LogicException('Membership clone capture spool structure is invalid.');
                }
                $expected = hash('sha256', $previous . NonprofitTaxRules::canonicalJson([
                    'sequence' => $record['sequence'], 'type' => $record['type'], 'data' => $record['data'],
                ]));
                if (!is_string($record['record_hash']) || !hash_equals($expected, $record['record_hash'])) {
                    throw new \LogicException('Membership clone capture spool hash chain is invalid.');
                }
                $previous = $expected;
                ++$counts[$record['type']];
                yield $record;
            }
            if (!feof($handle) || !hash_equals($this->fileHash, hash_final($file))
                || !hash_equals($this->chainHash, $previous) || $counts !== $this->counts) {
                throw new \LogicException('Membership clone capture spool manifest is invalid.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function ownedPath(): string
    {
        $directory = realpath(self::directory());
        $path = realpath(self::pathForToken($this->token));
        if ($directory === false || $path === false) throw new \LogicException('Membership clone capture spool path is unavailable.');
        $directory = strtolower(str_replace('\\', '/', $directory));
        $path = strtolower(str_replace('\\', '/', $path));
        if (!str_starts_with($path, rtrim($directory, '/') . '/')) throw new \LogicException('Membership clone capture spool escaped its runtime directory.');
        return realpath(self::pathForToken($this->token)) ?: throw new \LogicException('Membership clone capture spool path is unavailable.');
    }
}
