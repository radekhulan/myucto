<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Infrastructure\Config\RuntimePaths;

final class J6CalculationChunkSpool
{
    private string $base;
    private string $path;
    private mixed $handle;
    private bool $closed = false;

    public function __construct(private readonly string $ownerToken, private readonly string $kind)
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $ownerToken) !== 1) throw new \InvalidArgumentException('J6 calculation spool owner token is invalid.');
        if (!in_array($kind, ['decision', 'result', 'coverage', 'usage', 'funding_reference', 'funding_history'], true)) {
            throw new \InvalidArgumentException('J6 calculation spool kind is invalid.');
        }
        $base = RuntimePaths::storage('tmp/j6-calculation-spool');
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) throw new \RuntimeException('J6 calculation spool directory cannot be created.');
        $real = realpath($base);
        if ($real === false) throw new \RuntimeException('J6 calculation spool directory cannot be resolved.');
        $this->base = rtrim($real, '/\\');
        $this->path = $this->base . DIRECTORY_SEPARATOR . "j6-{$ownerToken}-{$kind}.ndjson";
        $this->assertOwnedPath(false);
        $this->handle = fopen($this->path, 'x+b');
        if (!is_resource($this->handle)) throw new \RuntimeException('J6 calculation spool cannot be opened.');
    }

    public function append(int $chunkId, array $descriptor): void
    {
        if ($this->closed || $chunkId <= 0) throw new \LogicException('J6 calculation spool is closed.');
        $record = json_encode(['owner_token' => $this->ownerToken, 'kind' => $this->kind, 'chunk_id' => $chunkId,
            'descriptor' => $descriptor], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (fwrite($this->handle, $record) !== strlen($record)) throw new \RuntimeException('J6 calculation spool write was incomplete.');
    }

    public function records(): \Generator
    {
        if ($this->closed || !is_resource($this->handle) || !fflush($this->handle) || fseek($this->handle, 0) !== 0) {
            throw new \RuntimeException('J6 calculation spool cannot be replayed.');
        }
        $ordinal = 0;
        while (($line = fgets($this->handle)) !== false) {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (($record['owner_token'] ?? null) !== $this->ownerToken || ($record['kind'] ?? null) !== $this->kind
                || !is_int($record['chunk_id'] ?? null) || !is_array($record['descriptor'] ?? null)
                || ($record['descriptor']['ordinal'] ?? null) !== $ordinal++) {
                throw new \UnexpectedValueException('J6 calculation spool ownership or order is invalid.');
            }
            yield ['chunk_id' => $record['chunk_id'], 'descriptor' => $record['descriptor']];
        }
        if (!feof($this->handle)) throw new \RuntimeException('J6 calculation spool read failed.');
    }

    public function closeAndDelete(): void
    {
        if ($this->closed) return;
        $this->closed = true;
        if (is_resource($this->handle)) fclose($this->handle);
        $this->assertOwnedPath(true);
        if (is_file($this->path) && !unlink($this->path)) throw new \RuntimeException('J6 calculation spool cleanup failed.');
    }

    public function __destruct()
    {
        if ($this->closed) return;
        try { $this->closeAndDelete(); } catch (\Throwable) {}
    }

    private function assertOwnedPath(bool $mustExist): void
    {
        $expected = "j6-{$this->ownerToken}-{$this->kind}.ndjson";
        $parent = realpath(dirname($this->path));
        $resolved = $mustExist ? realpath($this->path) : $this->path;
        if ($parent === false || strtolower(rtrim($parent, '/\\')) !== strtolower($this->base) || basename($this->path) !== $expected
            || ($mustExist && $resolved === false) || ($resolved !== false && strtolower(dirname((string) $resolved)) !== strtolower($this->base))) {
            throw new \RuntimeException('J6 calculation spool path escaped its owned directory.');
        }
    }
}
