<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Auth\SecretEncryption;

final class PayrollPeriodExportStorage
{
    public function __construct(
        private readonly SecretEncryption $encryption,
    ) {}

    /**
     * @return array{
     *   storage_key:string,file_sha256:string,size_bytes:int
     * }
     */
    public function store(int $supplierId, string $bytes): array
    {
        if ($supplierId <= 0 || $bytes === '') {
            throw new \InvalidArgumentException(
                'Obsah exportu mezd není platný.',
            );
        }
        $hash = hash('sha256', $bytes);
        $directory = $this->verifiedDirectory($supplierId, $hash);
        $path = $directory . '/' . $hash;
        if (is_file($path)) {
            $this->readVerified($supplierId, $hash);
        } else {
            $temporary = $directory . '/.tmp-' . bin2hex(random_bytes(12));
            $ciphertext = $this->encryption->encryptFor(
                $bytes,
                $this->context($supplierId, $hash),
            );
            try {
                if (@file_put_contents($temporary, $ciphertext, LOCK_EX)
                    !== strlen($ciphertext)
                ) {
                    throw new \RuntimeException(
                        'Export mezd se nepodařilo uložit.',
                    );
                }
                @chmod($temporary, 0640);
                if (!@rename($temporary, $path) && !is_file($path)) {
                    throw new \RuntimeException(
                        'Export mezd se nepodařilo dokončit.',
                    );
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
            $this->readVerified($supplierId, $hash);
        }

        return [
            'storage_key' => $hash,
            'file_sha256' => $hash,
            'size_bytes' => strlen($bytes),
        ];
    }

    public function readVerified(int $supplierId, string $storageKey): string
    {
        $path = $this->verifiedPath($supplierId, $storageKey);
        if ($path === null) {
            throw new \RuntimeException('Export mezd nebyl nalezen.');
        }
        $ciphertext = file_get_contents($path);
        if (!is_string($ciphertext)
            || !str_starts_with($ciphertext, 'enc:v2:')
        ) {
            throw new \RuntimeException(
                'Archivovaný export mezd není bezpečně zašifrovaný.',
            );
        }
        $bytes = $this->encryption->decryptFor(
            $ciphertext,
            $this->context($supplierId, $storageKey),
        );
        if (!hash_equals($storageKey, hash('sha256', $bytes))) {
            throw new \RuntimeException(
                'Integrita archivovaného exportu mezd nesouhlasí.',
            );
        }

        return $bytes;
    }

    public static function baseDir(int $supplierId): string
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma exportu mezd není platná.',
            );
        }

        return RuntimePaths::storage(
            'payroll-period-exports/sup-' . $supplierId,
        );
    }

    private function verifiedPath(
        int $supplierId,
        string $storageKey,
        bool $required = true,
    ): ?string {
        if (preg_match('/^[a-f0-9]{64}$/D', $storageKey) !== 1) {
            throw new \InvalidArgumentException(
                'Klíč exportu mezd není platný.',
            );
        }
        $base = self::baseDir($supplierId);
        $path = $base . '/' . substr($storageKey, 0, 2)
            . '/' . $storageKey;
        if (!is_file($path)) {
            if ($required) {
                throw new \RuntimeException('Export mezd nebyl nalezen.');
            }

            return null;
        }
        $realPath = realpath($path);
        $realRoot = $this->canonicalRoot(false);
        $realBase = realpath($base);
        $realDirectory = realpath(dirname($path));
        if ($realPath === false
            || $realBase === false
            || $realDirectory === false
            || !$this->inside($realBase, $realRoot)
            || !$this->inside($realDirectory, $realBase)
            || !$this->inside($realPath, $realDirectory)
        ) {
            throw new \RuntimeException(
                'Cesta exportu mezd není bezpečná.',
            );
        }

        return $realPath;
    }

    private function verifiedDirectory(
        int $supplierId,
        string $storageKey,
    ): string {
        $realRoot = $this->canonicalRoot(true);
        $base = self::baseDir($supplierId);
        if (!is_dir($base)
            && !@mkdir($base, 0750)
            && !is_dir($base)
        ) {
            throw new \RuntimeException(
                'Úložiště exportů mezd není dostupné.',
            );
        }
        $realBase = realpath($base);
        if ($realBase === false || !$this->inside($realBase, $realRoot)) {
            throw new \RuntimeException(
                'Kořen úložiště exportů mezd není bezpečný.',
            );
        }
        $directory = $base . '/' . substr($storageKey, 0, 2);
        if (!is_dir($directory)
            && !@mkdir($directory, 0750)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Úložiště exportů mezd není dostupné.',
            );
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false
            || !$this->inside($realDirectory, $realBase)
        ) {
            throw new \RuntimeException(
                'Cesta úložiště exportu mezd není bezpečná.',
            );
        }

        return $realDirectory;
    }

    private function canonicalRoot(bool $create): string
    {
        $base = realpath(RuntimePaths::base());
        if ($base === false) {
            throw new \RuntimeException(
                'Kořen runtime dat exportů mezd není bezpečný.',
            );
        }
        $storage = RuntimePaths::storage();
        if ($create && !is_dir($storage)
            && !@mkdir($storage, 0750)
            && !is_dir($storage)
        ) {
            throw new \RuntimeException(
                'Úložiště runtime dat exportů mezd není dostupné.',
            );
        }
        $realStorage = realpath($storage);
        if ($realStorage === false || !$this->inside($realStorage, $base)) {
            throw new \RuntimeException(
                'Úložiště runtime dat exportů mezd není bezpečné.',
            );
        }
        $root = RuntimePaths::storage('payroll-period-exports');
        if ($create && !is_dir($root)
            && !@mkdir($root, 0750)
            && !is_dir($root)
        ) {
            throw new \RuntimeException(
                'Kořen úložiště exportů mezd není dostupný.',
            );
        }
        $realRoot = realpath($root);
        if ($realRoot === false
            || !$this->inside($realRoot, $realStorage)
        ) {
            throw new \RuntimeException(
                'Kořen úložiště exportů mezd není bezpečný.',
            );
        }

        return $realRoot;
    }

    private function context(int $supplierId, string $storageKey): string
    {
        return "payroll-period-export-storage:{$supplierId}:{$storageKey}";
    }

    private function inside(string $path, string $base): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $base = strtolower(rtrim(str_replace('\\', '/', $base), '/'));

        return str_starts_with($path, $base . '/');
    }
}
