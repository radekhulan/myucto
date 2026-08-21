<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;

/** Tenantové vazby se skládají z remapovaných rozhodnutí, nikdy raw insertem. */
final class TenantDataRelationCoverageValidator
{
    /** @return list<string> */
    public function issues(
        TenantDataDefinition $definition,
        TenantSchemaTableInventory $table,
    ): array {
        if ($definition->policy !== TenantDataPolicy::TenantRelation) {
            return [];
        }

        $policy = $definition->details['relation_import'] ?? null;
        if (!is_array($policy)
            || array_is_list($policy)
            || ($policy['strategy'] ?? null)
                !== 'recreate_from_mapped_references'
        ) {
            return ['invalid_relation_import_policy:' . $table->name];
        }

        $issues = [];
        if (($policy['raw_insert'] ?? null) !== false) {
            $issues[] = 'relation_raw_insert_not_forbidden:' . $table->name;
        }
        if (($policy['unresolved_row'] ?? null) !== 'skip') {
            $issues[] = 'relation_unresolved_row_not_skipped:'
                . $table->name;
        }
        return $issues;
    }
}
