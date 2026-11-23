<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\PayrollEmploymentMergeService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sloučení vztahů musí o každé tabulce s `employment_id` výslovně rozhodnout:
 * přesunout, blokovat, nebo nechat zmizet se zdrojem. Nová tabulka bez
 * rozhodnutí by se při sloučení tiše smazala kaskádou (nebo by sloučení
 * spadlo na cizím klíči) a nikdo by si toho nevšiml.
 */
#[Group('architecture')]
final class PayrollEmploymentMergeTablesTest extends TestCase
{
    public function testEveryTableWithEmploymentIdHasMergeDecision(): void
    {
        $tables = $this->employmentTables();
        self::assertGreaterThan(50, count($tables), 'Otisk schématu má podezřele málo tabulek s employment_id, test hledá špatně.');

        $known = array_keys(PayrollEmploymentMergeService::TABLES);
        sort($known);

        self::assertSame(
            [],
            array_values(array_diff(array_keys($tables), $known)),
            'Tabulka s employment_id nemá rozhodnutí v PayrollEmploymentMergeService::TABLES (přesunout / blokovat / smazat se zdrojem).',
        );
        self::assertSame(
            [],
            array_values(array_diff($known, array_keys($tables))),
            'PayrollEmploymentMergeService::TABLES zná tabulku, která ve schématu není nebo nemá employment_id.',
        );
    }

    public function testDecisionsReferToExistingColumns(): void
    {
        $tables = $this->employmentTables();
        foreach (PayrollEmploymentMergeService::TABLES as $table => $decision) {
            self::assertContains(
                $decision['action'],
                [PayrollEmploymentMergeService::MOVE, PayrollEmploymentMergeService::BLOCK, PayrollEmploymentMergeService::DROP],
                $table,
            );
            if ($decision['action'] === PayrollEmploymentMergeService::BLOCK) {
                self::assertNotSame('', $decision['reason'] ?? '', "{$table}: blokující tabulka musí mít důvod.");
            }
            foreach ($decision['unique'] ?? [] as $column) {
                self::assertArrayHasKey($column, $tables[$table] ?? [], "{$table}: sloupec {$column} pro kontrolu kolize neexistuje.");
            }
            if (isset($decision['blocks'])) {
                preg_match_all('/\{t\}\.([a-z_]+)/', $decision['blocks']['where'], $columns);
                foreach ($columns[1] as $column) {
                    self::assertArrayHasKey($column, $tables[$table] ?? [], "{$table}: sloupec {$column} v podmínce blokace neexistuje.");
                }
            }
        }
    }

    /** @return array<string,array<string,string>> tabulka => sloupce */
    private function employmentTables(): array
    {
        $snapshot = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.snapshot.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $tables = [];
        foreach ((array) $snapshot['tables'] as $name => $table) {
            if (($table['type'] ?? 'BASE TABLE') === 'BASE TABLE' && isset($table['columns']['employment_id'])) {
                $tables[(string) $name] = (array) $table['columns'];
            }
        }
        ksort($tables);

        return $tables;
    }
}
