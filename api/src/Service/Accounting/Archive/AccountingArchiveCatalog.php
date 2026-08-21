<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Archive;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;

/** Typově bezpečný pohled účetního archivu nad společným tenantovým SSOT. */
final class AccountingArchiveCatalog
{
    /** @var array<string,AccountingArchiveTable>|null */
    private ?array $tables = null;

    public function __construct(private readonly TenantDataRegistry $registry) {}

    /** @return list<AccountingArchiveTable> */
    public function forExport(): array
    {
        $tables = array_values($this->tables());
        usort(
            $tables,
            static fn (
                AccountingArchiveTable $left,
                AccountingArchiveTable $right,
            ): int => $left->exportOrder <=> $right->exportOrder,
        );
        return $tables;
    }

    /** @return list<AccountingArchiveTable> */
    public function forRestore(): array
    {
        $tables = array_values($this->tables());
        usort(
            $tables,
            static fn (
                AccountingArchiveTable $left,
                AccountingArchiveTable $right,
            ): int => $left->restoreOrder <=> $right->restoreOrder,
        );
        return $tables;
    }

    public function get(string $table): ?AccountingArchiveTable
    {
        return $this->tables()[$table] ?? null;
    }

    public function has(string $table): bool
    {
        return isset($this->tables()[$table]);
    }

    /** @return array<string,AccountingArchiveTable> */
    private function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }
        if (!$this->registry->isComplete(
            TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
        )) {
            throw new \LogicException(
                'Účetní archivní profil tenantového registru není úplný.',
            );
        }

        $tables = [];
        $exportOrders = [];
        $restoreOrders = [];
        foreach ($this->registry->definitionsFor(
            TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
        ) as $definition) {
            $table = AccountingArchiveTable::fromDefinition($definition);
            if (isset($tables[$table->name])
                || isset($exportOrders[$table->exportOrder])
                || isset($restoreOrders[$table->restoreOrder])
            ) {
                throw new \LogicException(
                    'Účetní archivní profil obsahuje duplicitní tabulku nebo pořadí.',
                );
            }
            $tables[$table->name] = $table;
            $exportOrders[$table->exportOrder] = true;
            $restoreOrders[$table->restoreOrder] = true;
        }
        if ($tables === []) {
            throw new \LogicException(
                'Účetní archivní profil neobsahuje žádné tabulky.',
            );
        }

        return $this->tables = $tables;
    }
}
