<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

/**
 * Kontrola dat převzatých z jiného účetního programu (POHODA, Money S3, ABRA a další).
 *
 * Každá kontrola hledá jednu známou chybu převodu, jen čte a vrací nález po dokladech
 * ({@see CheckResult}). Kontrola s jednoznačnou opravou implementuje
 * {@see FixableMigrationDataCheck}; ostatní jen hlásí s odkazem na postup v manuálu.
 * Spouští je {@see MigrationDataCheckService}, seznam drží {@see MigrationDataCheckRegistry}.
 */
interface MigrationDataCheck
{
    /** Stabilní klíč kontroly (API, CLI, MCP, překlady). */
    public function key(): string;

    /** Název kontroly pro CLI a auditní stopu (UI ho bere z překladů podle klíče). */
    public function title(): string;

    /** Kotva kapitoly manuálu s postupem (`126_Kontrola_dat_po_prevodu#…`). */
    public function manual(): string;

    /** Týká se kontrola firmy podle zdrojů jejího převodu ({@see MigrationSources})? */
    public function appliesTo(int $supplierId): bool;

    /** Jen čtení: stav, počty, částky a doklady s nálezem. */
    public function detect(int $supplierId): CheckResult;
}
