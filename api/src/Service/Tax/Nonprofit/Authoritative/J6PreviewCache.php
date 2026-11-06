<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonProfile;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class J6PreviewCache
{
    public const int TTL_SECONDS = 900;
    public const int MAX_PER_SCOPE = 3;

    private string $base;

    public function __construct(
        private readonly CanonicalJsonSpool $canonical,
        ?string $base = null,
    ) {
        $base ??= RuntimePaths::storage('cache/j6-preview');
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) throw new \RuntimeException('J6 preview cache cannot be created.');
        $real = realpath($base);
        if ($real === false) throw new \RuntimeException('J6 preview cache cannot be resolved.');
        $this->base = rtrim($real, '/\\');
    }

    public function createSink(J6PreviewIdentity $identity, ?int $now = null): J6PreviewSpoolSink
    {
        return new J6PreviewSpoolSink($this, $this->canonical, $identity, $now ?? time());
    }

    public function read(string $token, J6PreviewIdentity $identity, string $kind, int $page, ?int $now = null): array
    {
        if (in_array($kind, ['funding_reference', 'funding_history'], true)) J6SourceEvidencePageHasher::kind($kind);
        else J6CalculationPageHasher::kind($kind);
        if ($page < 0) throw new \InvalidArgumentException('J6 preview page is invalid.');
        $directory = $this->previewPath($token, true);
        $metadata = $this->metadata($directory);
        if (!is_array($metadata['identity'] ?? null)
            || !hash_equals(NonprofitTaxRules::canonicalJson($metadata['identity']), NonprofitTaxRules::canonicalJson($identity->toArray()))
            || !is_int($metadata['expires_at'] ?? null)
            || $metadata['expires_at'] < ($now ?? time())) {
            throw new \UnexpectedValueException('J6 preview was not found.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . sprintf('%s-%08d.json', $kind, $page);
        $this->assertChild($directory, $path, false);
        if (!is_file($path)) return ['metadata' => $metadata, 'rows' => [], 'next_page' => null];
        $this->assertChild($directory, $path, true);
        $stored = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $payload = $stored['payload'] ?? null;
        $descriptor = $stored['descriptor'] ?? null;
        if (!is_array($payload) || !is_array($descriptor) || ($payload['kind'] ?? null) !== $kind
            || ($payload['ordinal'] ?? null) !== $page) throw new \UnexpectedValueException('J6 preview page is invalid.');
        $canonical = $this->canonical->canonicalize([NonprofitTaxRules::canonicalJson($payload)], CanonicalJsonProfile::NONPROFIT);
        if (!hash_equals((string) ($descriptor['chunk_hash'] ?? ''), $canonical->sha256)
            || ($descriptor['row_count'] ?? null) !== count($payload['rows'] ?? [])) {
            throw new \UnexpectedValueException('J6 preview page hash is invalid.');
        }
        $nextPath = $directory . DIRECTORY_SEPARATOR . sprintf('%s-%08d.json', $kind, $page + 1);
        $this->assertChild($directory, $nextPath, false);
        $next = is_file($nextPath) ? $page + 1 : null;
        return ['metadata' => $metadata, 'rows' => $payload['rows'], 'next_page' => $next];
    }

    public function cleanup(?int $now = null, int $limit = 1000): int
    {
        if ($limit < 1 || $limit > 10000) throw new \InvalidArgumentException('J6 preview cleanup limit is invalid.');
        $now ??= time();
        $removed = 0;
        foreach (new \DirectoryIterator($this->base) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || $removed >= $limit) continue;
            $name = $entry->getFilename();
            if (preg_match('/^(preview|work)-[a-f0-9]{64}$/D', $name) !== 1) continue;
            $path = $entry->getPathname();
            try {
                $marker = $this->metadata($path, '.j6-preview-owner');
                if (($marker['token'] ?? null) !== substr($name, strpos($name, '-') + 1)) continue;
                $expired = str_starts_with($name, 'work-') ? ($marker['created_at'] ?? PHP_INT_MAX) + self::TTL_SECONDS < $now
                    : ($this->metadata($path)['expires_at'] ?? PHP_INT_MAX) < $now;
                if ($expired) {
                    $this->removeOwnedDirectory($path);
                    ++$removed;
                }
            } catch (\Throwable) {}
        }
        return $removed;
    }

    public function base(): string { return $this->base; }

    public function previewPath(string $token, bool $mustExist): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) throw new \UnexpectedValueException('J6 preview was not found.');
        $path = $this->base . DIRECTORY_SEPARATOR . 'preview-' . $token;
        $this->assertChild($this->base, $path, $mustExist);
        if ($mustExist && !is_dir($path)) throw new \UnexpectedValueException('J6 preview was not found.');
        return $path;
    }

    public function assertChild(string $parent, string $path, bool $mustExist): void
    {
        $parentReal = realpath($parent);
        $pathReal = $mustExist ? realpath($path) : $path;
        if ($parentReal === false || ($mustExist && $pathReal === false)
            || strtolower(rtrim(dirname((string) $pathReal), '/\\')) !== strtolower(rtrim($parentReal, '/\\'))) {
            throw new \RuntimeException('J6 preview path escaped its owned directory.');
        }
    }

    public function metadata(string $directory, string $file = 'metadata.json'): array
    {
        $path = $directory . DIRECTORY_SEPARATOR . $file;
        $this->assertChild($directory, $path, true);
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \UnexpectedValueException('J6 preview metadata is invalid.');
        return $data;
    }

    public function removeOwnedDirectory(string $directory): void
    {
        $name = basename($directory);
        if (preg_match('/^(preview|work)-[a-f0-9]{64}$/D', $name) !== 1) throw new \RuntimeException('J6 preview directory is not owned.');
        $this->assertChild($this->base, $directory, true);
        $marker = $this->metadata($directory, '.j6-preview-owner');
        if (($marker['token'] ?? null) !== substr($name, strpos($name, '-') + 1)) throw new \RuntimeException('J6 preview owner marker is invalid.');
        $iterator = new \DirectoryIterator($directory);
        foreach ($iterator as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isLink() || !$entry->isFile()) throw new \RuntimeException('J6 preview directory contains an unsupported entry.');
            $this->assertChild($directory, $entry->getPathname(), true);
            if (!unlink($entry->getPathname())) throw new \RuntimeException('J6 preview cache cleanup failed.');
        }
        unset($entry, $iterator);
        if (!rmdir($directory)) throw new \RuntimeException('J6 preview cache cleanup failed.');
    }
}
