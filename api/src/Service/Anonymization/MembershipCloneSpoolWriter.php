<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class MembershipCloneSpoolWriter
{
    private string $token;
    private string $path;
    private mixed $handle;
    private mixed $fileHash;
    private string $chainHash;
    private int $sequence = 0;
    private array $counts = ['member' => 0, 'revision' => 0, 'operation' => 0];
    private bool $finished = false;

    public function __construct(private readonly string $database)
    {
        if ($database === '') throw new \InvalidArgumentException('Membership clone spool requires a database identity.');
        $directory = MembershipCloneCapture::directory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Membership clone spool directory could not be created.');
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false || !is_writable($realDirectory)) throw new \RuntimeException('Membership clone spool directory is not writable.');
        $this->token = bin2hex(random_bytes(16));
        $this->path = MembershipCloneCapture::pathForToken($this->token);
        $this->handle = fopen($this->path, 'x+b');
        if ($this->handle === false) throw new \RuntimeException('Membership clone spool could not be created.');
        $realPath = realpath($this->path);
        $base = strtolower(str_replace('\\', '/', $realDirectory));
        $candidate = $realPath === false ? '' : strtolower(str_replace('\\', '/', $realPath));
        if (!str_starts_with($candidate, rtrim($base, '/') . '/')) {
            fclose($this->handle);
            @unlink($this->path);
            throw new \LogicException('Membership clone spool escaped its runtime directory.');
        }
        $this->fileHash = hash_init('sha256');
        $this->chainHash = str_repeat('0', 64);
    }

    public function append(string $type, array $data): void
    {
        if ($this->finished || !array_key_exists($type, $this->counts)) throw new \LogicException('Membership clone spool writer state is invalid.');
        $record = ['sequence' => ++$this->sequence, 'type' => $type, 'data' => $data];
        $hash = hash('sha256', $this->chainHash . NonprofitTaxRules::canonicalJson($record));
        $record['previous_hash'] = $this->chainHash;
        $record['record_hash'] = $hash;
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (fwrite($this->handle, $line) !== strlen($line)) throw new \RuntimeException('Membership clone spool write was incomplete.');
        hash_update($this->fileHash, $line);
        $this->chainHash = $hash;
        ++$this->counts[$type];
    }

    public function finish(string $financialHash): MembershipCloneCapture
    {
        if ($this->finished || preg_match('/^[a-f0-9]{64}$/D', $financialHash) !== 1) throw new \LogicException('Membership clone spool cannot be finalized.');
        if (!fflush($this->handle)) throw new \RuntimeException('Membership clone spool could not be flushed.');
        fclose($this->handle);
        $this->finished = true;
        return new MembershipCloneCapture($this->database, $this->token, hash_final($this->fileHash), $this->chainHash, $financialHash, $this->counts);
    }

    public function abort(): void
    {
        if (!$this->finished && is_resource($this->handle)) fclose($this->handle);
        $this->finished = true;
        if (isset($this->path) && is_file($this->path)) @unlink($this->path);
    }

    public function __destruct()
    {
        if (!$this->finished) $this->abort();
    }
}
