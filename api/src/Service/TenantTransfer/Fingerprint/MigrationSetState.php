<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Fingerprint;

final readonly class MigrationSetState
{
    /**
     * @param list<string> $appliedMigrations
     * @param list<string> $pendingMigrations
     * @param list<string> $missingMigrationFiles
     */
    public function __construct(
        public ?string $hash,
        public array $appliedMigrations,
        public array $pendingMigrations,
        public array $missingMigrationFiles,
    ) {
    }

    public function isReady(): bool
    {
        return $this->hash !== null && $this->pendingMigrations === [] && $this->missingMigrationFiles === [];
    }
}
