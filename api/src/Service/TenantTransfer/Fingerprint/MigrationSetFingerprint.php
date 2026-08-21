<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Fingerprint;

final class MigrationSetFingerprint
{
    public const FORMAT = 'myucto-migration-set';
    public const VERSION = 1;

    /** @param list<string> $appliedMigrations */
    public function inspectDirectory(string $migrationDirectory, array $appliedMigrations): MigrationSetState
    {
        if (!is_dir($migrationDirectory)) {
            throw new \InvalidArgumentException('Adresář migrací neexistuje.');
        }
        $files = glob(rtrim($migrationDirectory, '/\\') . DIRECTORY_SEPARATOR . '*.sql');
        if ($files === false) {
            throw new \RuntimeException('Sadu migračních souborů se nepodařilo načíst.');
        }
        $checksums = [];
        foreach ($files as $path) {
            $filename = basename($path);
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new \RuntimeException('Nelze spočítat checksum migrace ' . $filename . '.');
            }
            $checksums[$filename] = $checksum;
        }
        return $this->fromChecksums($checksums, $appliedMigrations);
    }

    /**
     * @param array<mixed> $availableChecksums
     * @param array<mixed> $appliedMigrations
     */
    public function fromChecksums(array $availableChecksums, array $appliedMigrations): MigrationSetState
    {
        $availableChecksums = $this->validatedChecksums($availableChecksums);
        $appliedMigrations = $this->validatedAppliedMigrations($appliedMigrations);
        $availableNames = array_keys($availableChecksums);

        $pending = array_values(array_diff($availableNames, $appliedMigrations));
        $missing = array_values(array_diff($appliedMigrations, $availableNames));
        sort($pending, SORT_STRING);
        sort($missing, SORT_STRING);

        $hash = null;
        if ($missing === []) {
            $entries = [];
            foreach ($appliedMigrations as $filename) {
                $entries[] = [
                    'filename' => $filename,
                    'sha256' => $availableChecksums[$filename],
                ];
            }
            $hash = CanonicalJson::sha256([
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'migrations' => $entries,
            ]);
        }

        return new MigrationSetState($hash, $appliedMigrations, $pending, $missing);
    }

    /**
     * @param array<mixed> $checksums
     * @return array<string,string>
     */
    private function validatedChecksums(array $checksums): array
    {
        $caseInsensitiveNames = [];
        $validated = [];
        foreach ($checksums as $filename => $checksum) {
            if (!is_string($filename) || !is_string($checksum)) {
                throw new \InvalidArgumentException('Název a checksum migrace musí být řetězce.');
            }
            $this->assertFilename($filename);
            if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
                throw new \InvalidArgumentException('Migrace ' . $filename . ' nemá kanonický SHA-256 checksum.');
            }
            $folded = strtolower($filename);
            if (isset($caseInsensitiveNames[$folded])) {
                throw new \InvalidArgumentException('Názvy migračních souborů kolidují bez ohledu na velikost písmen.');
            }
            $caseInsensitiveNames[$folded] = true;
            $validated[$filename] = $checksum;
        }
        ksort($validated, SORT_STRING);
        return $validated;
    }

    /**
     * @param array<mixed> $migrations
     * @return list<string>
     */
    private function validatedAppliedMigrations(array $migrations): array
    {
        if (!array_is_list($migrations)) {
            throw new \InvalidArgumentException('Aplikované migrace musí být seznam.');
        }
        $seen = [];
        $validated = [];
        foreach ($migrations as $filename) {
            if (!is_string($filename)) {
                throw new \InvalidArgumentException('Název aplikované migrace musí být řetězec.');
            }
            $this->assertFilename($filename);
            $folded = strtolower($filename);
            if (isset($seen[$folded])) {
                throw new \InvalidArgumentException('Seznam aplikovaných migrací obsahuje duplicitu.');
            }
            $seen[$folded] = true;
            $validated[] = $filename;
        }
        sort($validated, SORT_STRING);
        return $validated;
    }

    private function assertFilename(string $filename): void
    {
        if ($filename !== basename($filename)
            || strlen($filename) > 190
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sql$/D', $filename) !== 1
        ) {
            throw new \InvalidArgumentException('Neplatný název migračního souboru.');
        }
    }
}
