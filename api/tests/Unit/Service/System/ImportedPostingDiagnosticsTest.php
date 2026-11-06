<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\ImportedPostingDiagnostics;
use PDO;
use PHPUnit\Framework\TestCase;

final class ImportedPostingDiagnosticsTest extends TestCase
{
    public function testMissingOwnershipMigrationIsUnavailableRatherThanEmptySuccess(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (['stereo_nx_import_map', 'premier_import_map', 'money_s3_import_map', 'pohoda_import_map',
            'abra_flexi_import_map', 'journal_entry_document_links', 'journal_entries', 'invoices', 'purchase_invoices'] as $table) {
            $pdo->exec("CREATE TABLE {$table} (id INTEGER)");
        }
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
        $result = (new ImportedPostingDiagnostics($db))->report(7);
        self::assertFalse($result['available']);
        self::assertNull($result['total']);
        self::assertSame([], $result['entries']);
    }

    public function testMissingCompanyScopeDoesNotOpenDatabaseConnection(): void
    {
        $result = (new ImportedPostingDiagnostics(new Connection(new Config([]))))->report(0);
        self::assertFalse($result['available']);
        self::assertNull($result['total']);
        self::assertSame(0, $result['supplier_id']);
    }
}
