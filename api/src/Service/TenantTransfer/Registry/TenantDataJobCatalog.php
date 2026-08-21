<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Technické fronty, průběh workerů a regenerovatelné exportní artefakty. */
final class TenantDataJobCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::runtime(
                'accounting_backfill_jobs',
                'accounting',
                'runtime_accounting_activation_job',
            ),
            self::runtime(
                'import_jobs',
                'operations',
                'runtime_unified_import_export_job_queue',
            ),
        ];
    }

    private static function runtime(
        string $table,
        string $featureGroup,
        string $reason,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::RuntimeDerived,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => $featureGroup,
                'reason' => $reason,
                'secrets' => [],
            ],
        );
    }
}
