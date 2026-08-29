<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Time\PayrollTimeCsvImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Import docházky z CSV je druhá vstupní brána mzdových dat (nález C-19) a
 * dosud na ni nebyl žádný test. Odsud jde přímo do `payroll_time_entries`, ze
 * kterých se počítají příplatky a přesčasy — chybný řádek se v číslech schová.
 *
 * Testy drží čtyři věci, na kterých import stojí: povinné sloupce, výčet
 * kategorií, deduplikaci (v souboru i proti už uloženým záznamům) a to, že
 * odmítnutý řádek skončí v protokolu, ne jako výjimka.
 */
#[Group('integration')]
final class PayrollTimeCsvImportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';
    private const HEADER =
        'employment_code;starts_at;ends_at;timezone;category;break_minutes;external_id';

    private Connection $db;
    private ContainerInterface $container;
    private PayrollTimeCsvImportService $imports;
    private int $supplierId;
    private int $userId;
    private int $employmentId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $db = $this->container->get(Connection::class);
        $imports = $this->container->get(PayrollTimeCsvImportService::class);
        if (!$db instanceof Connection || !$imports instanceof PayrollTimeCsvImportService) {
            throw new \RuntimeException('Služba importu docházky není dostupná.');
        }
        $this->db = $db;
        $this->imports = $imports;
        foreach ([
            'payroll_employees',
            'payroll_employments',
            'payroll_time_entries',
            'payroll_time_months',
            'payroll_time_imports',
            'payroll_time_import_errors',
        ] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }

        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->employmentId = $this->createEmployment('DOCH-1');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * Šťastná cesta: náhled i import se musí shodnout na počtu přijatých řádků
     * a import je opravdu uloží — včetně převodu místního času na UTC.
     */
    public function testHappyPathImportStoresEntries(): void
    {
        $csv = $this->csv([
            $this->row('2026-06-01T08:00:00+02:00', '2026-06-01T16:30:00+02:00', 'regular', '30', 'doch-1'),
            $this->row('2026-06-02T18:00:00+02:00', '2026-06-02T22:00:00+02:00', 'overtime', '0', 'doch-2'),
        ]);

        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka.csv',
            $csv,
        );
        self::assertTrue($preview['supported']);
        self::assertSame('preview', $preview['status']);
        self::assertSame('2026-06-01', $preview['period_start']);
        self::assertSame(2, $preview['total_rows']);
        self::assertSame(2, $preview['accepted_rows']);
        self::assertSame(0, $preview['rejected_rows']);
        self::assertSame(0, $preview['duplicate_rows']);
        // Interní hashe se z náhledu nesmí dostat ven — jsou to deduplikační klíče.
        self::assertArrayNotHasKey(
            'source_hash',
            PayrollTimeValue::rows($preview['rows'], 'rows')[0],
        );
        self::assertSame(0, $this->countEntries());

        $import = $this->imports->import(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka.csv',
            $csv,
            $this->userId,
        );
        self::assertFalse($import['replayed']);
        self::assertSame('imported', $import['status']);
        self::assertSame(2, $import['accepted_rows']);
        self::assertSame(0, $import['rejected_rows']);
        self::assertSame(2, $this->countEntries());

        $entry = $this->fetchEntry('doch-1');
        self::assertSame('regular', $entry['category']);
        self::assertSame('2026-06-01 06:00:00', $entry['starts_at_utc']);
        self::assertSame('2026-06-01 14:30:00', $entry['ends_at_utc']);
        self::assertSame('Europe/Prague', $entry['timezone_name']);
        self::assertSame(30, (int) $entry['break_minutes']);
        self::assertSame('import', $entry['source_kind']);
    }

    /**
     * Povinné sloupce se kontrolují na hlavičce, ne po řádcích. Kdyby chyběl
     * `category` a import ho jen doplnil prázdnem, tichým výsledkem by byla
     * docházka bez kategorie — tedy bez příplatků.
     */
    public function testMissingRequiredColumnIsRejectedOnHeader(): void
    {
        $csv = implode("\n", [
            'employment_code;starts_at;ends_at;timezone;break_minutes;external_id',
            'DOCH-1;2026-06-01T08:00:00+02:00;2026-06-01T16:00:00+02:00;Europe/Prague;30;doch-1',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hlavička neobsahuje povinný sloupec category.');
        $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka.csv',
            $csv,
        );
    }

    /**
     * Kategorie je uzavřený výčet, protože na ni visí příplatky (§ 114–118).
     * Neznámá hodnota MUSÍ skončit v chybách řádku — ne projít jako „regular"
     * a ne shodit celý soubor.
     */
    public function testUnknownCategoryIsRejectedPerRow(): void
    {
        $csv = $this->csv([
            $this->row('2026-06-01T08:00:00+02:00', '2026-06-01T16:00:00+02:00', 'regular', '30', 'doch-ok'),
            $this->row('2026-06-02T08:00:00+02:00', '2026-06-02T16:00:00+02:00', 'brigada', '30', 'doch-bad'),
            $this->row('2026-06-03T08:00:00+02:00', '2026-06-03T16:00:00+02:00', 'REGULAR', '30', 'doch-case'),
        ]);

        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka.csv',
            $csv,
        );

        self::assertSame(1, $preview['accepted_rows']);
        self::assertSame(2, $preview['rejected_rows']);
        foreach (PayrollTimeValue::rows($preview['errors'], 'errors') as $error) {
            self::assertSame('invalid_category', $error['error_code']);
            self::assertSame('category', $error['field_name']);
        }
    }

    /**
     * Ostatní odmítnutí řádku: mimo importované období, přestávka delší než
     * směna, prázdné external_id a neznámý pracovní vztah. Všechno to musí
     * projít jako chyba v protokolu, ne jako výjimka z importu.
     */
    public function testInvalidRowsAreCollectedWithSpecificErrorCodes(): void
    {
        $csv = $this->csv([
            $this->row('2026-05-31T08:00:00+02:00', '2026-05-31T16:00:00+02:00', 'regular', '30', 'doch-mimo'),
            $this->row('2026-06-02T08:00:00+02:00', '2026-06-02T09:00:00+02:00', 'regular', '90', 'doch-pauza'),
            $this->row('2026-06-03T08:00:00+02:00', '2026-06-03T16:00:00+02:00', 'regular', '30', ''),
            $this->row('2026-06-04T08:00:00+02:00', '2026-06-04T16:00:00+02:00', 'regular', 'x', 'doch-break'),
            [
                'NEEXISTUJICI-VZTAH',
                '2026-06-05T08:00:00+02:00',
                '2026-06-05T16:00:00+02:00',
                'Europe/Prague',
                'regular',
                '30',
                'doch-vztah',
            ],
        ]);

        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka.csv',
            $csv,
        );

        self::assertSame(0, $preview['accepted_rows']);
        self::assertSame(5, $preview['rejected_rows']);
        $codes = array_map(
            static fn (array $error): string => PayrollTimeValue::string(
                $error['error_code'] ?? null,
                'error_code',
            ),
            PayrollTimeValue::rows($preview['errors'], 'errors'),
        );
        sort($codes);
        self::assertSame(
            [
                'employment_not_unique',
                'invalid_break',
                'invalid_break',
                'invalid_external_id',
                'period_mismatch',
            ],
            $codes,
        );
    }

    /**
     * Deduplikace uvnitř souboru: `source_hash` se počítá ze vztahu a
     * `external_id`, takže druhý výskyt téhož identifikátoru je duplicita —
     * i když má jiné časy. Bez toho by kopírovaný řádek v Excelu znamenal
     * dvakrát proplacenou směnu.
     */
    public function testDuplicateExternalIdWithinFileIsNotImportedTwice(): void
    {
        $csv = $this->csv([
            $this->row('2026-06-01T08:00:00+02:00', '2026-06-01T16:00:00+02:00', 'regular', '30', 'doch-dup'),
            $this->row('2026-06-08T08:00:00+02:00', '2026-06-08T18:00:00+02:00', 'regular', '30', 'doch-dup'),
        ]);

        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka.csv',
            $csv,
        );
        self::assertSame(1, $preview['accepted_rows']);
        self::assertSame(1, $preview['duplicate_rows']);

        $import = $this->imports->import(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka.csv',
            $csv,
            $this->userId,
        );
        self::assertSame('imported', $import['status']);
        self::assertSame(1, $import['accepted_rows']);
        self::assertSame(1, $import['duplicate_rows']);
        self::assertSame(1, $this->countEntries());
        self::assertSame('2026-06-01 06:00:00', $this->fetchEntry('doch-dup')['starts_at_utc']);
    }

    /**
     * Deduplikace proti už uloženým záznamům: jiný soubor se stejným
     * `external_id` nesmí založit druhý zápis. Tohle je běžný scénář „poslali
     * jsme opravenou docházku" a bez kontroly by se směny sčítaly.
     */
    public function testAlreadyImportedExternalIdIsDuplicateInLaterFile(): void
    {
        $this->imports->import(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka.csv',
            $this->csv([
                $this->row('2026-06-01T08:00:00+02:00', '2026-06-01T16:00:00+02:00', 'regular', '30', 'doch-znovu'),
            ]),
            $this->userId,
        );

        $second = $this->imports->import(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka-oprava.csv',
            $this->csv([
                $this->row('2026-06-09T08:00:00+02:00', '2026-06-09T20:00:00+02:00', 'regular', '30', 'doch-znovu'),
            ]),
            $this->userId,
        );

        self::assertFalse($second['replayed']);
        self::assertSame('failed', $second['status']);
        self::assertSame(0, $second['accepted_rows']);
        self::assertSame(1, $second['duplicate_rows']);
        self::assertSame(1, $this->countEntries());
    }

    /**
     * Idempotence na sha256 obsahu: dvojklik nebo zopakovaný požadavek po
     * timeoutu musí vrátit původní protokol, ne založit druhý import.
     */
    public function testIdenticalContentReplaysExistingImport(): void
    {
        $csv = $this->csv([
            $this->row('2026-06-01T08:00:00+02:00', '2026-06-01T16:00:00+02:00', 'regular', '30', 'doch-idem'),
        ]);

        $first = $this->imports->import(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka.csv',
            $csv,
            $this->userId,
        );
        $second = $this->imports->import(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'jiny-nazev.csv',
            $csv,
            null,
        );

        self::assertFalse($first['replayed']);
        self::assertTrue($second['replayed']);
        self::assertSame(
            PayrollTimeValue::int($first['id'] ?? null, 'import_id'),
            PayrollTimeValue::int($second['id'] ?? null, 'import_id'),
        );
        self::assertSame(1, $this->countEntries());
        self::assertSame(1, $this->countImports());
    }

    /**
     * `row_hash` je otisk kanonizovaného řádku, který drží protokol chyb
     * spojený s konkrétním řádkem souboru. Musí být stabilní mezi běhy (jinak
     * by účetní po znovunahrání nepoznala tutéž chybu) a různý pro různé řádky.
     */
    public function testRowHashIsStablePerRowAndDistinctBetweenRows(): void
    {
        $csv = $this->csv([
            $this->row('2026-06-01T08:00:00+02:00', '2026-06-01T16:00:00+02:00', 'brigada', '30', 'doch-h1'),
            $this->row('2026-06-02T08:00:00+02:00', '2026-06-02T16:00:00+02:00', 'brigada', '30', 'doch-h2'),
        ]);

        $first = $this->importedRowHashes($csv, 'dochazka.csv');
        // Jiný název souboru, tentýž obsah řádků — hash se počítá z řádku.
        $second = $this->importedRowHashes(
            $csv . "\n" . implode(';', [
                'DOCH-1',
                '2026-06-03T08:00:00+02:00',
                '2026-06-03T16:00:00+02:00',
                'Europe/Prague',
                'brigada',
                '30',
                'doch-h3',
            ]),
            'dochazka-2.csv',
        );

        self::assertCount(2, $first);
        self::assertCount(3, $second);
        self::assertCount(2, array_unique($first));
        self::assertSame($first, array_slice($second, 0, 2));
    }

    /**
     * Kód vztahu nemusí být v rámci firmy jedinečný, takže se `employment_code`
     * bez `employment_id` nesmí uhodnout. Radši odmítnutý řádek než docházka
     * připsaná cizímu člověku.
     */
    public function testAmbiguousEmploymentCodeIsRejected(): void
    {
        $this->createEmployment('DOCH-1');

        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka.csv',
            $this->csv([
                $this->row('2026-06-01T08:00:00+02:00', '2026-06-01T16:00:00+02:00', 'regular', '30', 'doch-ambig'),
            ]),
        );

        self::assertSame(0, $preview['accepted_rows']);
        self::assertSame(
            'employment_not_unique',
            PayrollTimeValue::rows($preview['errors'], 'errors')[0]['error_code'],
        );
    }

    public function testRejectsUnsupportedFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Formát musí být csv nebo xlsx.');
        $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'ods',
            'dochazka.ods',
            self::HEADER,
        );
    }

    /**
     * XLSX chodí zakódovaný v Base64. Neplatný vstup se musí zastavit dřív,
     * než ho uvidí parser tabulek — jinak by chyba vypadala jako „soubor není
     * XLSX", i když je problém v přenosu.
     */
    public function testXlsxContentMustBeValidBase64(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Obsah XLSX není platné Base64.');
        $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'xlsx',
            'dochazka.xlsx',
            '!!!nejde o base64!!!',
        );
    }

    /**
     * @param list<string> $rowHashes
     * @return list<string>
     */
    private function importedRowHashes(string $csv, string $name): array
    {
        $import = $this->imports->import(
            $this->supplierId,
            self::PERIOD,
            'csv',
            $name,
            $csv,
            $this->userId,
        );
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_hash FROM payroll_time_import_errors
              WHERE supplier_id = ? AND import_id = ?
              ORDER BY csv_row_number'
        );
        $stmt->execute([
            $this->supplierId,
            PayrollTimeValue::int($import['id'] ?? null, 'import_id'),
        ]);
        return array_map(
            static fn (mixed $hash): string => bin2hex((string) $hash),
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        return implode("\n", [
            self::HEADER,
            ...array_map(
                static fn (array $row): string => implode(';', $row),
                $rows,
            ),
        ]);
    }

    /** @return list<string> */
    private function row(
        string $startsAt,
        string $endsAt,
        string $category,
        string $breakMinutes,
        string $externalId,
        string $employmentCode = 'DOCH-1',
        string $timezone = 'Europe/Prague',
    ): array {
        return [
            $employmentCode,
            $startsAt,
            $endsAt,
            $timezone,
            $category,
            $breakMinutes,
            $externalId,
        ];
    }

    private function createEmployment(string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId, "Docházková osoba {$code}"]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $employeeId, $code]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function fetchEntry(string $externalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_time_entries
              WHERE supplier_id = ? AND source_reference = ?
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Docházkový zápis {$externalId} nebyl uložen.");
        return $row;
    }

    private function countEntries(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_time_entries WHERE supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function countImports(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_time_imports WHERE supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function firstId(PDO $pdo, string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $pdo->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : PayrollTimeValue::int($value, "{$table}.id");
    }
}
