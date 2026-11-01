<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Anonymization\AnonymizationPolicy;
use MyInvoice\Service\Anonymization\AnonymizationPolicyAudit;
use MyInvoice\Service\System\Schema\SchemaSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Každý textový sloupec databáze musí mít v {@see AnonymizationPolicy} rozhodnutí.
 *
 * Anonymizovaná kopie instance (`api/bin/anonymize-clone.php`) smí vzniknout jen
 * tehdy, když o každém sloupci, který může nést osobní nebo obchodní údaj, někdo
 * rozhodl: pseudonymizovat (a jak), vyprázdnit, nebo ponechat. Nová migrace, která
 * přidá tabulku nebo textový sloupec, tenhle test shodí, dokud rozhodnutí nepřibude.
 */
final class AnonymizationPolicyCoverageTest extends TestCase
{
    public function testEveryTextColumnHasAnonymizationDecision(): void
    {
        $root = dirname(__DIR__, 3);
        $snapshot = SchemaSnapshot::load($root . '/' . SchemaSnapshot::RELATIVE_PATH);
        self::assertNotNull($snapshot, 'Chybí nebo je neplatný ' . SchemaSnapshot::RELATIVE_PATH . '.');

        [$tables, $foreignKeys] = AnonymizationPolicyAudit::fromSnapshot($snapshot);
        self::assertGreaterThan(100, count($tables), 'Otisk struktury nemá tabulky — test by tiše prošel.');

        $problems = AnonymizationPolicyAudit::problems($tables, $foreignKeys);

        self::assertSame([], $problems, "Politika anonymizace nepokrývá strukturu databáze:\n  "
            . implode("\n  ", $problems)
            . "\nDoplň rozhodnutí do api/src/Service/Anonymization/AnonymizationPolicy.php.");
    }

    public function testAuditDetectsUndecidedColumn(): void
    {
        $tables = [
            'clients' => array_fill_keys(array_keys(AnonymizationPolicy::COLUMNS['clients']), 'varchar(50) NULL'),
        ];
        $tables['clients']['secret_nickname'] = 'varchar(100) NULL DEFAULT NULL';
        $tables['brand_new_table'] = ['id' => 'int(11) NOT NULL', 'owner_name' => 'varchar(190) NOT NULL'];

        $problems = implode("\n", AnonymizationPolicyAudit::problems($tables));

        self::assertStringContainsString('clients.secret_nickname', $problems);
        self::assertStringContainsString('brand_new_table', $problems);
    }

    public function testBooleanWorkReportDisplayOptionsArePreserved(): void
    {
        self::assertSame('keep', AnonymizationPolicy::COLUMNS['supplier']['work_report_display_options']);
        self::assertSame('keep', AnonymizationPolicy::COLUMNS['work_reports']['display_options']);
    }

    public function testGeneratedAndEnumColumnsAreNotTextColumns(): void
    {
        self::assertFalse(AnonymizationPolicyAudit::isTextColumn("enum('a','b') NOT NULL"));
        self::assertFalse(AnonymizationPolicyAudit::isTextColumn('varchar(32) NULL DEFAULT NULL STORED GENERATED AS (x)'));
        self::assertTrue(AnonymizationPolicyAudit::isTextColumn('longtext NULL DEFAULT NULL COLLATE utf8mb4_bin'));
        self::assertTrue(AnonymizationPolicyAudit::isTextColumn('varbinary(16) NULL'));
    }
}
