<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Snapshot;

use MyInvoice\Infrastructure\Config\RuntimePaths;
final class SnapshotChunkSpool
{
    private string $base;
    private string $path;
    private mixed $handle;
    private bool $closed = false;

    public function __construct(private readonly string $ownerToken)
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $ownerToken) !== 1) throw new \InvalidArgumentException('Snapshot spool owner token is invalid.');
        $base = RuntimePaths::storage('tmp/j6-snapshot-spool');
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) throw new \RuntimeException('Snapshot spool directory cannot be created.');
        $real = realpath($base);
        if ($real === false) throw new \RuntimeException('Snapshot spool directory cannot be resolved.');
        $this->base = rtrim($real, '/\\');
        $this->path = $this->base . DIRECTORY_SEPARATOR . 'j6-' . $ownerToken . '.ndjson';
        $this->assertOwnedPath($this->path, false);
        $this->handle = fopen($this->path, 'x+b');
        if ($this->handle === false) throw new \RuntimeException('Snapshot spool cannot be opened.');
    }

    public static function create(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public function append(int $chunkId, array $descriptor): void
    {
        if ($this->closed || $chunkId <= 0) throw new \LogicException('Snapshot spool is closed or the chunk id is invalid.');
        $record = json_encode(
            ['owner_token' => $this->ownerToken, 'chunk_id' => $chunkId, 'descriptor' => $descriptor],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) . "\n";
        if (fwrite($this->handle, $record) !== strlen($record)) throw new \RuntimeException('Snapshot spool write was incomplete.');
    }

    /** @return \Generator<int,array{chunk_id:int,descriptor:array<string,mixed>}> */
    public function records(): \Generator
    {
        if ($this->closed || !is_resource($this->handle) || fflush($this->handle) === false || fseek($this->handle, 0) !== 0) {
            throw new \RuntimeException('Snapshot spool cannot be replayed.');
        }
        $expected = 0;
        while (($line = fgets($this->handle)) !== false) {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (($record['owner_token'] ?? null) !== $this->ownerToken || !is_int($record['chunk_id'] ?? null)
                || !is_array($record['descriptor'] ?? null) || ($record['descriptor']['ordinal'] ?? null) !== $expected++) {
                throw new \UnexpectedValueException('Snapshot spool ownership or order is invalid.');
            }
            yield ['chunk_id' => $record['chunk_id'], 'descriptor' => $record['descriptor']];
        }
        if (!feof($this->handle)) throw new \RuntimeException('Snapshot spool read failed.');
    }

    public function closeAndDelete(): void
    {
        if ($this->closed) return;
        $this->closed = true;
        if (is_resource($this->handle)) fclose($this->handle);
        $this->assertOwnedPath($this->path, true);
        if (is_file($this->path) && !unlink($this->path)) throw new \RuntimeException('Snapshot spool cleanup failed.');
    }

    public function __destruct()
    {
        if ($this->closed) return;
        try { $this->closeAndDelete(); } catch (\Throwable) {}
    }

    private function assertOwnedPath(string $path, bool $mustExist): void
    {
        $parent = realpath(dirname($path));
        $resolved = $mustExist ? realpath($path) : $path;
        $prefix = 'j6-' . $this->ownerToken . '.ndjson';
        if ($parent === false || strtolower(rtrim($parent, '/\\')) !== strtolower($this->base)
            || basename($path) !== $prefix || ($mustExist && $resolved === false)
            || ($resolved !== false && strtolower(dirname((string) $resolved)) !== strtolower($this->base))) {
            throw new \RuntimeException('Snapshot spool path escaped its owned directory.');
        }
    }
}
