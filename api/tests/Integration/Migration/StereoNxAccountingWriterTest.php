<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingWriter;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportMap;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StereoNxAccountingWriterTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxAccountingWriter $writer;
    private int $supplierId;
    private int $userId;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php') && !getenv('MYINVOICE_DB_NAME')) {
            self::markTestSkipped('Integration database is not configured.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->writer = new StereoNxAccountingWriter(
            $this->db,
            $container->get(ChartOfAccountsRepository::class),
            $container->get(ChartOfAccountsSeeder::class),
            $container->get(AccountingPeriodRepository::class),
            $container->get(JournalEntryRepository::class),
            $container->get(StereoNxImportMap::class),
        );
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Run ci-seed.php in the isolated test database.');
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $identity = SyntheticStereoNxAccountingTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name = ?, ic = ?, dic = ?, accounting_mode = 'double_entry', is_vat_payer = 1 WHERE id = ?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testWritesExactChartJournalOpeningBalancesAndIsIdempotent(): void
    {
        $prepared = $this->writer->prepare($this->backup(1250.50, -50.25));
        self::assertTrue($prepared['ok'], json_encode($prepared['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame(4, $prepared['counts']['journal_entries']);
        self::assertSame(2, $prepared['counts']['opening_entries']);
        self::assertCount(1, $prepared['warnings']);
        self::assertSame('journal_year_mismatch', $prepared['warnings'][0]['code']);
        self::assertSame(4, $prepared['warnings'][0]['count']);
        self::assertSame('Účetní období určeno podle data účetního případu; pomocný rok se u 4 řádků liší.', $prepared['warnings'][0]['message']);

        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT stereo_writer_rollback');
        $rolledBack = $this->writer->write($prepared, $this->supplierId, $this->userId);
        self::assertTrue($pdo->inTransaction(), 'Writer must not commit the caller transaction.');
        self::assertSame(4, $rolledBack['journal_entries_created']);
        self::assertSame(8, $rolledBack['journal_lines']);
        $pdo->exec('ROLLBACK TO SAVEPOINT stereo_writer_rollback');
        $pdo->exec('RELEASE SAVEPOINT stereo_writer_rollback');
        self::assertSame(0, $this->rowCount('journal_entries'));
        self::assertSame(0, $this->mapCount());

        $first = $this->writer->write($prepared, $this->supplierId, $this->userId);
        self::assertSame(4, $first['journal_entries_created']);
        self::assertGreaterThanOrEqual(3, $first['accounts_created']);
        self::assertSame(1, $first['periods_created']);
        self::assertSame(4, $this->rowCount('journal_entries'));
        self::assertSame(8, $this->rowCount('journal_entry_lines'));
        self::assertSame(2, (int) $this->value(
            'SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = ? AND is_red_storno = 1',
            [$this->supplierId],
        ));
        self::assertSame(2120025, (int) $this->value(
            "SELECT ROUND(SUM(signed_amount) * 100) FROM journal_entry_lines WHERE supplier_id = ? AND side = 'debit'",
            [$this->supplierId],
        ));
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'opening'", [$this->supplierId]));
        self::assertSame(2026, (int) $this->value('SELECT fiscal_year FROM accounting_periods WHERE supplier_id = ?', [$this->supplierId]));
        self::assertNotFalse($this->value("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '221kb'", [$this->supplierId]));
        self::assertSame([
            '221kb' => 120025,
            '321' => -120025,
            '353.001' => 1000000,
            '411.010' => -1000000,
            '701' => 0,
        ], $this->balances());

        $mappedCodes = Bootstrap::buildContainer()->get(StereoNxImportMap::class)->accountCodes(
            $this->supplierId, $prepared['identity']['ico'], $prepared['company_index'],
        );
        self::assertSame('353.001', $mappedCodes['353001']);
        self::assertSame('411.010', $mappedCodes['411010']);
        self::assertSame('221kb', $mappedCodes['221kb']);
        self::assertArrayNotHasKey('353.001', $mappedCodes, 'Identita importu zůstává ve zdrojovém tvaru.');

        $before = $this->snapshot();
        $repeat = $this->writer->write($prepared, $this->supplierId, $this->userId);
        self::assertSame(0, $repeat['journal_entries_created']);
        self::assertSame(4, $repeat['journal_entries_existing']);
        self::assertSame($before, $this->snapshot());
    }

    public function testRepeatOfLegacyUndottedImportKeepsOriginalAccountsAndEntries(): void
    {
        $prepared = $this->writer->prepare($this->backup());
        $this->writer->write($prepared, $this->supplierId, $this->userId);
        $this->db->pdo()->prepare("UPDATE chart_of_accounts SET account_code = REPLACE(account_code, '.', '')
            WHERE supplier_id = ? AND account_code IN ('353.001', '411.010')")->execute([$this->supplierId]);
        $before = $this->snapshot();
        $repeat = $this->writer->write($prepared, $this->supplierId, $this->userId);
        self::assertSame(0, $repeat['journal_entries_created']);
        self::assertSame(4, $repeat['journal_entries_existing']);
        self::assertSame($before, $this->snapshot());
        self::assertArrayHasKey(353001, $this->balances());
        self::assertArrayNotHasKey('353.001', $this->balances());
    }

    public function testUnmappedUndottedTargetIsNotSilentlyMergedOrRenamed(): void
    {
        $container = Bootstrap::buildContainer();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $accounts = $container->get(ChartOfAccountsRepository::class);
        $parent = $accounts->findByCode($this->supplierId, '353');
        (new \MyInvoice\Service\Migration\Shared\ChartAccountCreator($accounts))->createAnalytic(
            $this->supplierId, '353001', 'Syntetický nepřiřazený účet', $parent,
        );
        $prepared = $this->writer->prepare($this->backup());
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('bez importní vazby');
        $this->writer->write($prepared, $this->supplierId, $this->userId);
    }

    public function testChangedSourceAndChangedTargetAreRejectedWithoutAdditionalRows(): void
    {
        $prepared = $this->writer->prepare($this->backup(1250.50, -50.25));
        $this->writer->write($prepared, $this->supplierId, $this->userId);
        $before = $this->snapshot();
        $pdo = $this->db->pdo();

        $changedSource = $this->writer->prepare($this->backup(1250.50, 50.25));
        $pdo->exec('SAVEPOINT stereo_writer_changed_source');
        try {
            $this->writer->write($changedSource, $this->supplierId, $this->userId);
            self::fail('Changed source row must be rejected.');
        } catch (StereoNxException $e) {
            self::assertSame('source_changed', $e->errorCode);
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT stereo_writer_changed_source');
            $pdo->exec('RELEASE SAVEPOINT stereo_writer_changed_source');
        }
        self::assertSame($before, $this->snapshot());

        $entryId = (int) $this->value(
            "SELECT DISTINCT l.entry_id FROM journal_entry_lines l
              WHERE l.supplier_id = ? AND l.is_red_storno = 1 LIMIT 1",
            [$this->supplierId],
        );
        $pdo->exec('SAVEPOINT stereo_writer_changed_target');
        $pdo->prepare('UPDATE journal_entry_lines SET amount = amount + 1 WHERE entry_id = ? AND line_no = 1')
            ->execute([$entryId]);
        try {
            $this->writer->write($prepared, $this->supplierId, $this->userId);
            self::fail('Changed target posting must be rejected.');
        } catch (StereoNxException $e) {
            self::assertSame('mapped_target_changed', $e->errorCode);
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT stereo_writer_changed_target');
            $pdo->exec('RELEASE SAVEPOINT stereo_writer_changed_target');
        }
        self::assertSame($before, $this->snapshot());

        $pdo->exec('SAVEPOINT stereo_writer_changed_red_flag');
        $pdo->prepare('UPDATE journal_entry_lines SET is_red_storno = 0 WHERE entry_id = ?')
            ->execute([$entryId]);
        try {
            $this->writer->write($prepared, $this->supplierId, $this->userId);
            self::fail('Changed red storno flag must be rejected.');
        } catch (StereoNxException $e) {
            self::assertSame('mapped_target_changed', $e->errorCode);
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT stereo_writer_changed_red_flag');
            $pdo->exec('RELEASE SAVEPOINT stereo_writer_changed_red_flag');
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testNativeTaxFlagsPreserveDeductibilityOfExpenseAndRevenueAccounts(): void
    {
        $tables = SyntheticStereoNxAccountingTables::tables();
        foreach ([['501', 'N', true], ['501001', 'N', false], ['602', 'V', true], ['602001', 'V', false]] as [$code, $type, $tax]) {
            $tables['Lrozvrh'][] = ['Ucet' => $code, 'Nazev' => 'Syntetický účet ' . $code,
                'TypUctu' => $type, 'Danovy' => $tax];
        }
        $backup = $this->backup(tables: $tables);
        $decoded = array_column(iterator_to_array($backup->rows('Lrozvrh'), false), 'Danovy', 'Ucet');
        foreach (['501' => true, '501001' => false, '602' => true, '602001' => false] as $code => $expected) {
            self::assertSame($expected, $decoded[$code], 'Native tax flag of synthetic account ' . $code);
        }
        $prepared = $this->writer->prepare($backup);
        self::assertTrue($prepared['ok'], json_encode($prepared['errors'], JSON_UNESCAPED_UNICODE));
        $this->writer->write($prepared, $this->supplierId, $this->userId);
        $query = $this->db->pdo()->prepare("SELECT account_code, tax_deductibility FROM chart_of_accounts
            WHERE supplier_id = ? AND account_code IN ('501', '501.001', '602', '602.001') ORDER BY account_code");
        $query->execute([$this->supplierId]);
        self::assertSame([
            ['account_code' => '501', 'tax_deductibility' => 'deductible'],
            ['account_code' => '501.001', 'tax_deductibility' => 'non_deductible'],
            ['account_code' => '602', 'tax_deductibility' => 'deductible'],
            ['account_code' => '602.001', 'tax_deductibility' => 'non_deductible'],
        ], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testNativeNullTaxFlagStillBlocksChartImport(): void
    {
        $tables = SyntheticStereoNxAccountingTables::tables();
        $tables['Lrozvrh'][] = ['Ucet' => '501', 'Nazev' => 'Syntetický účet s neurčeným příznakem',
            'TypUctu' => 'N', 'Danovy' => null];
        $backup = $this->backup(tables: $tables);
        $decoded = array_column(iterator_to_array($backup->rows('Lrozvrh'), false), 'Danovy', 'Ucet');
        self::assertNull($decoded['501']);
        $prepared = $this->writer->prepare($backup);
        self::assertFalse($prepared['ok']);
        $invalidFlags = array_values(array_filter($prepared['errors'],
            static fn (array $error): bool => $error['code'] === 'chart_tax_flag_invalid'));
        self::assertCount(1, $invalidFlags);
        self::assertSame(1, $invalidFlags[0]['count']);
        self::assertCount(1, $prepared['errors']);
        $before = $this->snapshot();
        try {
            $this->writer->write($prepared, $this->supplierId, $this->userId);
            self::fail('An unknown tax flag must prevent chart import.');
        } catch (StereoNxException $e) {
            self::assertSame('accounting_source_invalid', $e->errorCode);
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testNegativeRedStornoKeepsOriginalSidesAndUsesSignedAmounts(): void
    {
        $prepared = $this->writer->prepare($this->backup(1250.50, -50.25));
        self::assertTrue($prepared['ok'], json_encode($prepared['errors'], JSON_UNESCAPED_UNICODE));
        $this->writer->write($prepared, $this->supplierId, $this->userId);

        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, l.side, l.amount, l.signed_amount, l.is_red_storno
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.is_red_storno = 1
              ORDER BY l.line_no"
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame([
            ['account_code' => '221kb', 'side' => 'debit', 'amount' => '50.25', 'signed_amount' => '-50.25', 'is_red_storno' => 1],
            ['account_code' => '321', 'side' => 'credit', 'amount' => '50.25', 'signed_amount' => '-50.25', 'is_red_storno' => 1],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testFiscalSourceIntoFiscalCompanyCreatesPeriodFromAugustToJuly(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$this->supplierId]);
        $calendar = $this->writer->calendar($this->supplierId);
        $prepared = $this->writer->prepare($this->backup(tables: self::fiscalTables()), $calendar);
        self::assertTrue($prepared['ok'], json_encode($prepared['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame([], $prepared['warnings'], 'Rok zdroje odpovídá období firmy');
        self::assertSame([2025], array_values(array_unique(array_column($prepared['accounting_plan']['entries'], 'posting_year'))));

        $written = $this->writer->write($prepared, $this->supplierId, $this->userId);
        self::assertSame(1, $written['periods_created']);
        self::assertSame(4, $written['journal_entries_created']);
        $period = $pdo->prepare('SELECT id, fiscal_year, starts_on, ends_on, created_reason FROM accounting_periods WHERE supplier_id = ?');
        $period->execute([$this->supplierId]);
        $rows = $period->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame(['2025', '2025-08-01', '2026-07-31', 'import'],
            [(string) $rows[0]['fiscal_year'], $rows[0]['starts_on'], $rows[0]['ends_on'], $rows[0]['created_reason']]);
        self::assertSame([(int) $rows[0]['id']], array_map('intval', $pdo->query(
            'SELECT DISTINCT period_id FROM journal_entries WHERE supplier_id = ' . $this->supplierId
        )->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testFiscalSourceIntoCalendarCompanyStopsWithSuggestedMonth(): void
    {
        $calendar = $this->writer->calendar($this->supplierId);
        $prepared = $this->writer->prepare($this->backup(tables: self::fiscalTables()), $calendar);
        self::assertFalse($prepared['ok']);
        $mismatch = array_values(array_filter($prepared['errors'], static fn (array $e): bool => $e['code'] === 'fiscal_year_mismatch'));
        self::assertCount(1, $mismatch);
        self::assertStringContainsString('Účetní rok 2025 ve zdroji', $mismatch[0]['message']);
        self::assertStringContainsString('měsíc srpen', $mismatch[0]['message']);

        // Plán sestavený bez kalendáře firmy zastaví i zápis (pojistka writeru).
        $unchecked = $this->writer->prepare($this->backup(tables: self::fiscalTables()));
        self::assertTrue($unchecked['ok'], json_encode($unchecked['errors'], JSON_UNESCAPED_UNICODE));
        try {
            $this->writer->write($unchecked, $this->supplierId, $this->userId);
            self::fail('Hospodářský rok zdroje nesmí projít do kalendářní firmy.');
        } catch (StereoNxException $e) {
            self::assertSame('fiscal_year_mismatch', $e->errorCode);
        }
        self::assertSame(0, $this->rowCount('accounting_periods'));
    }

    /** Syntetický hospodářský rok 1. 8. 2025 – 31. 7. 2026, pomocný Rok = 2025. */
    private static function fiscalTables(): array
    {
        $tables = SyntheticStereoNxAccountingTables::tables();
        $dates = ['2025-08-01', '2025-08-01', '2025-11-03', '2026-02-04'];
        foreach ($tables['Cdenik'] as $index => &$row) {
            $row['KdyUcPripad'] = $dates[$index];
            $row['Rok'] = 2025;
            $row['Mesic'] = (int) substr($dates[$index], 5, 2);
        }
        unset($row);
        return $tables;
    }

    private function backup(float $ordinaryAmount = 1250.50, float $correctionAmount = 50.25, ?array $tables = null): StereoNxBackup
    {
        $path = sys_get_temp_dir() . '/stereo_accounting_' . bin2hex(random_bytes(8)) . '.zip';
        SyntheticNx1Archive::write(
            $path,
            $tables ?? SyntheticStereoNxAccountingTables::tables($ordinaryAmount, $correctionAmount),
            SyntheticStereoNxAccountingTables::identity(),
        );
        $this->files[] = $path;
        return StereoNxBackup::open($path, 0);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->value("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?", [$this->supplierId]);
    }

    private function mapCount(): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM stereo_nx_import_map WHERE supplier_id = ?', [$this->supplierId]);
    }

    /** @return array<string,int> */
    private function balances(): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code,
                    ROUND(SUM(CASE l.side WHEN 'debit' THEN l.signed_amount ELSE -l.signed_amount END) * 100) balance_cents
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ?
              GROUP BY a.id, a.account_code
              ORDER BY a.account_code"
        );
        $stmt->execute([$this->supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['account_code']] = (int) $row['balance_cents'];
        }
        return $out;
    }

    /** @return array<string,int> */
    private function snapshot(): array
    {
        return [
            'accounts' => $this->rowCount('chart_of_accounts'),
            'periods' => $this->rowCount('accounting_periods'),
            'entries' => $this->rowCount('journal_entries'),
            'lines' => $this->rowCount('journal_entry_lines'),
            'maps' => $this->mapCount(),
        ];
    }

    /** @param list<mixed> $params */
    private function value(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
