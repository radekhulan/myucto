<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Service\Export\Instance\InstanceRestoreTriggers;
use MyInvoice\Service\System\Schema\SchemaIntegrityService;
use MyInvoice\Service\System\Schema\SchemaSnapshot;
use PHPUnit\Framework\TestCase;

final class SchemaIntegrityServiceTest extends TestCase
{
    public function testIdenticalSnapshotsHaveNoFindings(): void
    {
        self::assertSame([], SchemaIntegrityService::compare(self::snapshot(), self::snapshot()));
        self::assertSame('ok', SchemaIntegrityService::summarize([])['status']);
    }

    public function testMissingTableFailsAndExtraTableOnlyWarns(): void
    {
        $actual = self::snapshot();
        unset($actual['tables']['journal_entries']);
        $actual['tables']['leftover'] = self::table();
        $actual['tables'][InstanceRestoreTriggers::RECOVERY_TABLE] = self::table();

        self::assertSame(
            ['fail table_missing journal_entries', 'warn table_extra leftover'],
            self::codes(SchemaIntegrityService::compare(self::snapshot(), $actual)),
        );
    }

    /**
     * Objekt, který po sobě nechala starší verze a který současný kód nepoužívá,
     * není odchylka od migrací, jen úklid. Varování by vedlo k hledání chyby tam,
     * kde žádná není. Neznámá tabulka navíc ale varováním zůstává.
     */
    public function testKnownLeftoverTableIsInfoButUnknownExtraTableWarns(): void
    {
        $actual = self::snapshot();
        $actual['tables']['bank_statement_owners'] = self::table();
        $actual['tables']['leftover'] = self::table();

        self::assertSame(
            ['warn table_extra leftover', 'info table_leftover bank_statement_owners'],
            self::codes(SchemaIntegrityService::compare(self::snapshot(), $actual)),
        );
    }

    public function testKnownLeftoverAloneKeepsStructureOkAndOffersRemoval(): void
    {
        $actual = self::snapshot();
        $actual['tables']['bank_statement_owners'] = self::table();

        $summary = SchemaIntegrityService::summarize(SchemaIntegrityService::compare(self::snapshot(), $actual));

        self::assertSame('ok', $summary['status']);
        self::assertSame(['fail' => 0, 'warn' => 0, 'info' => 1], $summary['counts']);
        self::assertSame('DROP TABLE IF EXISTS `bank_statement_owners`;', $summary['findings'][0]['actual']);
    }

    public function testRemovedMigrationOfOlderVersionIsInfoButUnknownMigrationWarns(): void
    {
        $findings = SchemaIntegrityService::unknownMigrations(
            [
                '0001_init.sql',
                '1110_tax_evidence_dpfo_audit.sql',
                '1720_gopay_payout_account_no_default.sql',
                '1999_neznama.sql',
            ],
            ['0001_init.sql'],
        );

        self::assertSame(
            [
                'warn migration_unknown 1999_neznama.sql',
                'info migration_leftover 1110_tax_evidence_dpfo_audit.sql',
                'info migration_leftover 1720_gopay_payout_account_no_default.sql',
            ],
            self::codes($findings),
        );
    }

    public function testLostSystemVersioningFails(): void
    {
        $actual = self::snapshot();
        $actual['tables']['journal_entries']['type'] = 'BASE TABLE';

        self::assertSame(['fail table_type journal_entries'], self::codes(SchemaIntegrityService::compare(self::snapshot(), $actual)));
    }

    public function testColumnTypeChangeFailsButDefaultChangeOnlyWarns(): void
    {
        $actual = self::snapshot();
        $actual['tables']['journal_entries']['columns']['amount'] = SchemaSnapshot::column('decimal(12,2)', false, null);
        $actual['tables']['journal_entries']['columns']['note'] = SchemaSnapshot::column('varchar(40)', true, "'x'");
        unset($actual['tables']['journal_entries']['columns']['source_id']);

        self::assertSame(
            [
                'fail column_changed journal_entries.amount',
                'fail column_missing journal_entries.source_id',
                'warn column_attributes journal_entries.note',
            ],
            self::codes(SchemaIntegrityService::compare(self::snapshot(), $actual)),
        );
    }

    public function testMissingUniqueIndexFailsAndPlainIndexOnlyWarns(): void
    {
        $actual = self::snapshot();
        unset($actual['tables']['journal_entries']['indexes']['uq_source'], $actual['tables']['journal_entries']['indexes']['idx_date']);

        self::assertSame(
            ['fail index_missing journal_entries.uq_source', 'warn index_missing journal_entries.idx_date'],
            self::codes(SchemaIntegrityService::compare(self::snapshot(), $actual)),
        );
    }

    public function testForeignKeyUnderDifferentNameIsRenamedNotMissing(): void
    {
        $actual = self::snapshot();
        $definition = $actual['tables']['journal_entries']['foreign_keys']['fk_period'];
        unset($actual['tables']['journal_entries']['foreign_keys']['fk_period']);
        $actual['tables']['journal_entries']['foreign_keys']['journal_entries_ibfk_1'] = $definition;

        self::assertSame(
            ['warn foreign_key_renamed journal_entries.fk_period'],
            self::codes(SchemaIntegrityService::compare(self::snapshot(), $actual)),
        );
    }

    public function testMissingTriggerFailsAndChangedTriggerOnlyWarns(): void
    {
        $actual = self::snapshot();
        unset($actual['triggers']['trg_guard']);
        $actual['triggers']['trg_audit'] = 'AFTER UPDATE ON journal_entries #other';

        self::assertSame(
            ['fail trigger_missing trg_guard', 'warn trigger_changed trg_audit'],
            self::codes(SchemaIntegrityService::compare(self::snapshot(), $actual)),
        );
    }

    public function testNormalizationDropsCommentsButKeepsStringLiterals(): void
    {
        $withComments = "BEGIN\n  -- vysvětlení\n  /* blok */ SET x = '-- není komentář'; # konec\nEND";
        $stripped = "BEGIN\nSET x = '-- není komentář';\nEND";

        self::assertSame(SchemaSnapshot::normalizeSql($stripped), SchemaSnapshot::normalizeSql($withComments));
        self::assertStringContainsString("'-- není komentář'", SchemaSnapshot::normalizeSql($withComments));
    }

    /** @return array<string,mixed> */
    private static function snapshot(): array
    {
        return [
            'format' => SchemaSnapshot::FORMAT,
            'database_collation' => 'utf8mb4_unicode_ci',
            'migrations' => ['0001_init.sql'],
            'tables' => [
                'journal_entries' => self::table('SYSTEM VERSIONED', [
                    'amount' => SchemaSnapshot::column('decimal(15,2)', false, null),
                    'note' => SchemaSnapshot::column('varchar(40)', true, 'NULL'),
                    'source_id' => SchemaSnapshot::column('bigint(20) unsigned', true, 'NULL'),
                ], [
                    'PRIMARY' => 'UNIQUE BTREE (id)',
                    'uq_source' => 'UNIQUE BTREE (supplier_id, source_type, source_id)',
                    'idx_date' => 'BTREE (entry_date)',
                ], [
                    'fk_period' => '(period_id) -> accounting_periods (id) ON UPDATE RESTRICT ON DELETE RESTRICT',
                ]),
            ],
            'triggers' => [
                'trg_guard' => 'BEFORE UPDATE ON journal_entries #a',
                'trg_audit' => 'AFTER UPDATE ON journal_entries #b',
            ],
            'routines' => [],
        ];
    }

    /**
     * @param array<string,string> $columns
     * @param array<string,string> $indexes
     * @param array<string,string> $foreignKeys
     * @return array<string,mixed>
     */
    private static function table(string $type = 'BASE TABLE', array $columns = [], array $indexes = [], array $foreignKeys = []): array
    {
        return [
            'type' => $type,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
            'checks' => [],
        ];
    }

    /**
     * @param list<array{severity:string,code:string,object:string}> $findings
     * @return list<string>
     */
    private static function codes(array $findings): array
    {
        return array_map(
            static fn (array $f): string => $f['severity'] . ' ' . $f['code'] . ' ' . $f['object'],
            SchemaIntegrityService::summarize($findings)['findings'],
        );
    }
}
