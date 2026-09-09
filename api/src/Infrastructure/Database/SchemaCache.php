<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

final class SchemaCache
{
    private const FORMAT = 2;
    private ?array $entries = null;
    private ?array $snapshot = null;
    private ?string $generation = null;
    private bool $dirty = false;
    private bool $snapshotDirty = false;
    private int $snapshotLoadedAt = 0;
    private int $loadedAt = 0;

    public function __construct(
        private readonly string $path,
        private readonly string $database,
        private readonly int $ttlSeconds = 300,
    ) {}

    public static function pathFor(?string $baseDir, string $database, string $identity = ''): ?string
    {
        if ($baseDir === null || trim($baseDir) === '' || trim($database) === '') {
            return null;
        }
        $dir = rtrim($baseDir, "\\/") . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $database) ?? 'db';
        $suffix = $identity === '' ? '' : '-' . hash('sha256', $identity);
        return $dir . DIRECTORY_SEPARATOR . 'schema-' . $safe . $suffix . '.json';
    }

    public function generation(): ?string
    {
        $lock = self::lock($this->path);
        if ($lock === null) {
            return null;
        }
        try {
            return self::readGeneration($lock);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function get(string $key): ?bool
    {
        $this->load();
        return $this->entries[$key] ?? null;
    }

    public function put(string $key, bool $value): void
    {
        if ($this->entries === null) {
            $this->load();
        }
        if (($this->entries[$key] ?? null) !== $value) {
            $this->entries[$key] = $value;
            $this->dirty = true;
        }
    }

    public function snapshot(): ?array
    {
        $this->load();
        if ($this->snapshot !== null && ($this->ttlSeconds <= 0 || time() - $this->snapshotLoadedAt <= $this->ttlSeconds)) {
            return $this->snapshot;
        }
        $lock = self::lock($this->path);
        if ($lock === null) {
            return null;
        }
        try {
            if (self::readGeneration($lock) !== $this->generation) {
                return null;
            }
            $data = $this->readPayload(true);
            $this->snapshot = $data['snapshot'] ?? null;
            $this->snapshotLoadedAt = $data['written_at'] ?? time();
            return $this->snapshot;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function putSnapshot(array $snapshot, ?string $generation): void
    {
        $this->load();
        if ($generation === null || $generation !== $this->generation) {
            return;
        }
        $this->snapshot = $snapshot;
        $this->snapshotLoadedAt = time();
        $this->snapshotDirty = true;
    }

    public function flush(): void
    {
        if ((!$this->dirty && !$this->snapshotDirty) || $this->generation === null) {
            return;
        }
        $lock = self::lock($this->path);
        if ($lock === null) {
            return;
        }
        try {
            if (self::readGeneration($lock) !== $this->generation) {
                $this->entries = null;
                $this->snapshot = null;
                $this->dirty = false;
                $this->snapshotDirty = false;
                return;
            }
            if ($this->dirty) {
                $disk = $this->readPayload();
                $entries = array_replace($disk['entries'] ?? [], $this->entries ?? []);
                if ($this->writePayload($this->path, [
                    'format' => self::FORMAT,
                    'database' => $this->database,
                    'generation' => $this->generation,
                    'written_at' => $disk['written_at'] ?? time(),
                    'entries' => $entries,
                ])) {
                    $this->dirty = false;
                }
            }
            if ($this->snapshotDirty && $this->writePayload($this->path . '.snapshot.json', [
                'format' => self::FORMAT,
                'database' => $this->database,
                'generation' => $this->generation,
                'written_at' => $this->snapshotLoadedAt,
                'snapshot' => $this->snapshot,
                'snapshot_hash' => hash('sha256', serialize($this->snapshot)),
            ])) {
                $this->snapshotDirty = false;
            }
        } catch (\Throwable) {
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function invalidate(?string $path): bool
    {
        SchemaMetadataProvider::invalidate();
        if ($path === null) {
            return false;
        }
        $lock = self::lock($path);
        if ($lock === null) {
            return false;
        }
        try {
            $generation = bin2hex(random_bytes(16));
            rewind($lock);
            ftruncate($lock, 0);
            fwrite($lock, $generation);
            fflush($lock);
            $removed = is_file($path) && @unlink($path);
            $snapshotRemoved = is_file($path . '.snapshot.json') && @unlink($path . '.snapshot.json');
            return $removed || $snapshotRemoved;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(): void
    {
        $lock = self::lock($this->path);
        if ($lock === null) {
            $this->entries = [];
            $this->snapshot = null;
            $this->generation = null;
            return;
        }
        try {
            $generation = self::readGeneration($lock);
            if ($this->entries !== null && $this->generation === $generation
                && ($this->ttlSeconds <= 0 || time() - $this->loadedAt <= $this->ttlSeconds)) {
                return;
            }
            $this->generation = $generation;
            $data = $this->readPayload();
            $this->entries = $data['entries'] ?? [];
            $this->snapshot = null;
            $this->snapshotDirty = false;
            $this->loadedAt = $data['written_at'] ?? time();
            $this->dirty = false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readPayload(bool $snapshot = false): array
    {
        $raw = @file_get_contents($this->path . ($snapshot ? '.snapshot.json' : ''));
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT
            || ($data['database'] ?? null) !== $this->database
            || ($data['generation'] ?? null) !== $this->generation
            || !is_int($data['written_at'] ?? null)
            || ($this->ttlSeconds > 0 && time() - $data['written_at'] > $this->ttlSeconds)
            || (!$snapshot && !is_array($data['entries'] ?? null))
            || (isset($data['entries']) && !is_array($data['entries']))) {
            return [];
        }
        $data['entries'] = array_filter($data['entries'] ?? [], static fn ($value, $key): bool => is_string($key) && is_bool($value), ARRAY_FILTER_USE_BOTH);
        if (isset($data['snapshot']) && (!is_array($data['snapshot'])
            || ($data['snapshot_hash'] ?? null) !== hash('sha256', serialize($data['snapshot'])))) {
            $data['snapshot'] = null;
        }
        return $data;
    }

    private function writePayload(string $path, array $data): bool
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmp, $payload) !== false && @rename($tmp, $path)) {
            return true;
        }
        @unlink($tmp);
        return false;
    }

    private static function lock(string $path): mixed
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return null;
        }
        $lock = @fopen($path . '.lock', 'c+');
        if ($lock === false) {
            return null;
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    private static function readGeneration(mixed $lock): string
    {
        rewind($lock);
        $generation = stream_get_contents($lock);
        if (!is_string($generation) || !preg_match('/^[a-f0-9]{32}$/D', $generation)) {
            $generation = bin2hex(random_bytes(16));
            rewind($lock);
            ftruncate($lock, 0);
            fwrite($lock, $generation);
            fflush($lock);
        }
        return $generation;
    }
}
