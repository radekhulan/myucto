<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\SqlStatementSplitter;
use MyInvoice\Tests\Support\PayrollTestContainer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 2338 (návrat ukazatele revize po zahození opravy) jde pustit
 * opakovaně. Guard nad `payroll_runs` po druhém běhu existuje jednou a nese
 * jedinou výjimku; samotné chování hlídá {@see PayrollRunPersistenceTest}.
 *
 * Bez transakce: DROP/CREATE TRIGGER transakci implicitně potvrdí.
 */
#[Group('integration')]
final class PayrollRunDiscardCorrectionMigrationTest extends TestCase
{
    public function testMigrationRunsTwiceAndKeepsSingleGuard(): void
    {
        $db = PayrollTestContainer::build()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $sql = (string) file_get_contents(
            \dirname(__DIR__, 4) . '/db/migrations/2338_payroll_run_discard_correction.sql',
        );
        foreach ([1, 2] as $_) {
            foreach (SqlStatementSplitter::split($sql) as $statement) {
                $db->pdo()->exec($statement);
            }
        }

        $rows = $db->pdo()->query(
            'SELECT ACTION_STATEMENT
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE()
                AND EVENT_OBJECT_TABLE = "payroll_runs"
                AND ACTION_TIMING = "BEFORE"
                AND EVENT_MANIPULATION = "UPDATE"',
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $rows);
        $body = (string) $rows[0];
        self::assertStringContainsString('live_revisions_above', $body);
        self::assertStringContainsString("revision.status = 'approved'", $body);
        self::assertStringContainsString("revision.status <> 'abandoned'", $body);
        self::assertStringContainsString('Payroll run identity is immutable', $body);
        self::assertStringContainsString('Approved payroll run period and payment date are frozen', $body);
        $db->close();
    }
}
