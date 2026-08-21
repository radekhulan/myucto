<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Fingerprint;

use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\IncompleteTenantDataRegistryCoverage;

/** Fingerprintuje pouze tabulky explicitně zapsané v úplném tenantovém registru. */
final class TenantSchemaFingerprintProvider
{
    public const FORMAT = 'myucto-registered-tenant-schema';
    public const VERSION = 1;

    private readonly TenantDataRegistryCoverageValidator $coverage;

    public function __construct(
        private readonly TenantSchemaMetadataSource $source,
        ?TenantDataRegistryCoverageValidator $coverage = null,
    ) {
        $this->coverage = $coverage ?? new TenantDataRegistryCoverageValidator();
    }

    public function current(TenantDataRegistry $registry): string
    {
        if (!$registry->isComplete(TenantDataRegistry::TRANSFER_PROFILE)) {
            throw new TenantSchemaUnavailable(
                'tenant_registry_incomplete',
                'Registrované tenantové schéma nelze otisknout z neúplného registru.',
            );
        }

        try {
            $this->coverage->assertComplete($registry, $this->source->inventory());
        } catch (IncompleteTenantDataRegistryCoverage $exception) {
            throw new TenantSchemaUnavailable(
                'tenant_registry_schema_coverage_incomplete',
                'Tenantový registr nepokrývá aktuální databázové schéma.',
                $exception,
            );
        }

        $tableNames = [];
        $seen = [];
        foreach ($registry->definitionsFor(TenantDataRegistry::TRANSFER_PROFILE) as $definition) {
            if ($definition->kind !== TenantDataObjectKind::Table) {
                continue;
            }
            if (!str_starts_with($definition->key, 'table:')) {
                throw new TenantSchemaUnavailable(
                    'invalid_table_definition',
                    'Tabulková definice tenantového registru nemá prefix table:.',
                );
            }
            $tableName = substr($definition->key, strlen('table:'));
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $tableName) !== 1) {
                throw new TenantSchemaUnavailable(
                    'invalid_table_definition',
                    'Tenantový registr obsahuje neplatný název tabulky.',
                );
            }
            $folded = strtolower($tableName);
            if (isset($seen[$folded])) {
                throw new TenantSchemaUnavailable(
                    'duplicate_table_definition',
                    'Tenantový registr obsahuje duplicitní tabulku.',
                );
            }
            $seen[$folded] = true;
            $tableNames[] = $tableName;
        }
        if ($tableNames === []) {
            throw new TenantSchemaUnavailable(
                'registered_tables_missing',
                'Úplný tenantový registr neobsahuje žádnou tabulku.',
            );
        }
        sort($tableNames, SORT_STRING);

        return CanonicalJson::sha256([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'tables' => $this->source->describe($tableNames),
        ]);
    }
}
