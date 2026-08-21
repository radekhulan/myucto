<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Fingerprint;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Read-only zdroj úplného lokálního migračního stavu pro transfer preflight. */
final class MigrationSetStateProvider
{
    private readonly string $migrationDirectory;

    public function __construct(
        private readonly Connection $db,
        private readonly MigrationSetFingerprint $fingerprint,
        ?string $migrationDirectory = null,
    ) {
        $this->migrationDirectory = $migrationDirectory ?? Bootstrap::rootDir() . '/db/migrations';
    }

    public function current(): MigrationSetState
    {
        try {
            if (!$this->db->hasTable('migrations')) {
                throw new MigrationSetUnavailable('Tabulka migrations není dostupná.');
            }
            $statement = $this->db->pdo()->query('SELECT filename FROM migrations ORDER BY filename');
            if ($statement === false) {
                throw new MigrationSetUnavailable('Seznam aplikovaných migrací není dostupný.');
            }
            $applied = [];
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $filename) {
                if (!is_string($filename)) {
                    throw new MigrationSetUnavailable('Seznam aplikovaných migrací není platný.');
                }
                $applied[] = $filename;
            }
            return $this->fingerprint->inspectDirectory(
                $this->migrationDirectory,
                $applied,
            );
        } catch (MigrationSetUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new MigrationSetUnavailable('Migrační stav není dostupný.', 0, $exception);
        }
    }
}
