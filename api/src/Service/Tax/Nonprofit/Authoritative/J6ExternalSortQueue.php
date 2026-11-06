<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Infrastructure\Config\RuntimePaths;

final class J6ExternalSortQueue
{
    private const int PAGE = 500;
    private const int FAN_IN = 16;
    private const int LEVELS = 64;

    private string $base;
    private string $directory;
    private string $token;
    private array $buffer = [];
    private array $levels = [];
    private int $sequence = 0;
    private int $fileSequence = 0;
    private bool $sealed = false;
    private bool $closed = false;

    public function __construct(?string $base = null)
    {
        $base ??= RuntimePaths::storage('tmp/j6-calculation-work');
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) throw new \RuntimeException('J6 work spool cannot be created.');
        $real = realpath($base);
        if ($real === false) throw new \RuntimeException('J6 work spool cannot be resolved.');
        $this->base = rtrim($real, '/\\');
        $this->token = bin2hex(random_bytes(16));
        $this->directory = $this->base . DIRECTORY_SEPARATOR . 'sort-' . $this->token;
        $this->assertPath($this->directory, false, $this->base);
        if (!mkdir($this->directory, 0700)) throw new \RuntimeException('J6 sort spool cannot be created.');
    }

    public function append(string $key, array $record): void
    {
        if ($this->sealed || $this->closed || $key === '' || strlen($key) > 512) throw new \LogicException('J6 sort spool does not accept this record.');
        $this->buffer[] = ['key' => $key, 'sequence' => $this->sequence++, 'record' => $record];
        if (count($this->buffer) === self::PAGE) $this->flush();
    }

    public function records(): \Generator
    {
        if ($this->sealed || $this->closed) throw new \LogicException('J6 sort spool can only be replayed once.');
        $this->sealed = true;
        if ($this->buffer !== []) $this->flush();
        $paths = [];
        foreach ($this->levels as $level) foreach ($level as $path) $paths[] = $path;
        $final = $paths === [] ? null : (count($paths) === 1 ? $paths[0] : $this->merge($paths));
        try {
            if ($final === null) return;
            $handle = fopen($final, 'rb');
            if (!is_resource($handle)) throw new \RuntimeException('J6 sort spool cannot be read.');
            try {
                while (($record = $this->read($handle)) !== null) yield $record['record'];
            } finally {
                fclose($handle);
            }
        } finally {
            $this->closeAndDelete();
        }
    }

    public function closeAndDelete(): void
    {
        if ($this->closed) return;
        $this->closed = true;
        if (!is_dir($this->directory)) return;
        $this->assertPath($this->directory, true, $this->base);
        $iterator = new \DirectoryIterator($this->directory);
        foreach ($iterator as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isLink() || !$entry->isFile() || preg_match('/^run-[0-9]{8}\.ndjson$/D', $entry->getFilename()) !== 1) {
                throw new \RuntimeException('J6 sort spool contains an unsupported entry.');
            }
            $this->assertPath($entry->getPathname(), true, $this->directory);
            if (!unlink($entry->getPathname())) throw new \RuntimeException('J6 sort spool cleanup failed.');
        }
        unset($entry, $iterator);
        if (!rmdir($this->directory)) throw new \RuntimeException('J6 sort spool cleanup failed.');
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try { $this->closeAndDelete(); } catch (\Throwable) {}
        }
    }

    private function flush(): void
    {
        usort($this->buffer, self::compare(...));
        $path = $this->newPath();
        $handle = fopen($path, 'x+b');
        if (!is_resource($handle)) throw new \RuntimeException('J6 sort run cannot be opened.');
        try {
            foreach ($this->buffer as $record) $this->write($handle, $record);
        } finally {
            fclose($handle);
        }
        $this->buffer = [];
        $this->addRun(0, $path);
    }

    private function addRun(int $level, string $path): void
    {
        if ($level >= self::LEVELS) throw new \OverflowException('J6 sort spool exceeded its fixed merge depth.');
        $this->levels[$level][] = $path;
        if (count($this->levels[$level]) < self::FAN_IN) return;
        $merged = $this->merge($this->levels[$level]);
        $this->levels[$level] = [];
        $this->addRun($level + 1, $merged);
    }

    private function merge(array $paths): string
    {
        if ($paths === [] || count($paths) > self::FAN_IN * self::LEVELS) throw new \LogicException('J6 sort merge fan-in is invalid.');
        $target = $this->newPath();
        $output = fopen($target, 'x+b');
        if (!is_resource($output)) throw new \RuntimeException('J6 sort merge output cannot be opened.');
        $handles = [];
        $queue = new class extends \SplPriorityQueue {
            public function compare(mixed $priority1, mixed $priority2): int
            {
                return -strcmp($priority1[0], $priority2[0]) ?: -($priority1[1] <=> $priority2[1]);
            }
        };
        $queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        try {
            foreach (array_values($paths) as $index => $path) {
                $this->assertPath($path, true, $this->directory);
                $handle = fopen($path, 'rb');
                if (!is_resource($handle)) throw new \RuntimeException('J6 sort merge input cannot be opened.');
                $handles[$index] = $handle;
                $record = $this->read($handle);
                if ($record !== null) $queue->insert(['index' => $index, 'record' => $record], [$record['key'], $record['sequence']]);
            }
            while (!$queue->isEmpty()) {
                $item = $queue->extract();
                $this->write($output, $item['data']['record']);
                $index = $item['data']['index'];
                $record = $this->read($handles[$index]);
                if ($record !== null) $queue->insert(['index' => $index, 'record' => $record], [$record['key'], $record['sequence']]);
            }
        } finally {
            foreach ($handles as $handle) if (is_resource($handle)) fclose($handle);
            fclose($output);
        }
        foreach ($paths as $path) if (is_file($path) && !unlink($path)) throw new \RuntimeException('J6 sort merge cleanup failed.');
        return $target;
    }

    private function newPath(): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . sprintf('run-%08d.ndjson', $this->fileSequence++);
        $this->assertPath($path, false, $this->directory);
        return $path;
    }

    private function write($handle, array $record): void
    {
        $line = json_encode(['owner_token' => $this->token] + $record,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (fwrite($handle, $line) !== strlen($line)) throw new \RuntimeException('J6 sort spool write was incomplete.');
    }

    private function read($handle): ?array
    {
        $line = fgets($handle);
        if ($line === false) {
            if (!feof($handle)) throw new \RuntimeException('J6 sort spool read failed.');
            return null;
        }
        $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (($record['owner_token'] ?? null) !== $this->token || !is_string($record['key'] ?? null)
            || !is_int($record['sequence'] ?? null) || !is_array($record['record'] ?? null)) {
            throw new \UnexpectedValueException('J6 sort spool ownership or record is invalid.');
        }
        unset($record['owner_token']);
        return $record;
    }

    private static function compare(array $left, array $right): int
    {
        return strcmp($left['key'], $right['key']) ?: ($left['sequence'] <=> $right['sequence']);
    }

    private function assertPath(string $path, bool $mustExist, string $parent): void
    {
        $parentReal = realpath($parent);
        $resolved = $mustExist ? realpath($path) : $path;
        if ($parentReal === false || ($mustExist && $resolved === false)
            || strtolower(rtrim(dirname((string) $resolved), '/\\')) !== strtolower(rtrim($parentReal, '/\\'))) {
            throw new \RuntimeException('J6 sort spool path escaped its owned directory.');
        }
    }
}
