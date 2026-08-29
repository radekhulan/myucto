<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Component\PayrollInputImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Hromadný import mzdových vstupů je vstupní brána dat, která pak jdou rovnou
 * do výplaty. Až dosud na ni nebyl žádný test (nález C-19), takže se dalo bez
 * povšimnutí rozbít cokoliv z toho, co tady drží: že vadný řádek skončí v
 * chybách a nezabije celou dávku, že se stejný soubor nedá naimportovat dvakrát
 * a že se cizí vztah ani neúčinná složka nedostanou do `payroll_inputs`.
 *
 * Vše běží v transakci nad testovací databází; tearDown ji vrací zpět, takže
 * testy na sobě nezávisí ani při souběžném běhu jiných sad.
 */
#[Group('integration')]
final class PayrollInputImportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';
    private const COMPONENT_CODE = 'ODMENA_IMP';
    private const EMPLOYMENT_CODE = 'IMP-1';

    private Connection $db;
    private ContainerInterface $container;
    private PayrollInputImportService $imports;
    private int $supplierId;
    private int $userId;
    private int $employeeId;
    private int $employmentId;
    private int $componentId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $db = $this->container->get(Connection::class);
        $imports = $this->container->get(PayrollInputImportService::class);
        if (!$db instanceof Connection || !$imports instanceof PayrollInputImportService) {
            throw new \RuntimeException('Služba importu mzdových vstupů není dostupná.');
        }
        $this->db = $db;
        $this->imports = $imports;
        foreach ([
            'payroll_employees',
            'payroll_employments',
            'payroll_component_definitions',
            'payroll_inputs',
            'payroll_input_imports',
            'payroll_input_import_rows',
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
        [$this->employeeId, $this->employmentId] = $this->createEmployment(self::EMPLOYMENT_CODE);
        $this->componentId = $this->createComponent(self::COMPONENT_CODE);
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
     * Základní tvar: náhled musí říct totéž, co pak `apply()` opravdu zapíše.
     * Kdyby se rozešly, účetní odsouhlasí jedno číslo a do mezd půjde jiné.
     */
    public function testPreviewAndApplyAgreeOnAcceptedRow(): void
    {
        $csv = $this->csv([
            [self::EMPLOYMENT_CODE, self::COMPONENT_CODE, '25000', 'imp-happy-1'],
        ]);

        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $csv,
        );
        self::assertSame(1, $preview['row_count']);
        self::assertSame(1, $preview['accepted_count']);
        self::assertSame(0, $preview['rejected_count']);
        self::assertSame(0, $preview['duplicate_count']);
        self::assertSame('2026-06', $preview['period']);
        self::assertSame('odmeny.csv', $preview['source_name']);
        $row = PayrollTimeValue::row($preview['rows'][0]['payload'] ?? null, 'payload');
        self::assertSame($this->employmentId, $row['employment_id']);
        self::assertSame($this->employeeId, $row['employee_id']);
        self::assertSame($this->componentId, $row['component_id']);
        self::assertSame(25000, $row['amount_minor']);
        self::assertSame('import', $row['source_kind']);

        // Náhled sám o sobě NESMÍ nic zapsat — jinak by „jen se podívám"
        // založilo vstup do výplaty.
        self::assertSame(0, $this->countInputs());

        $applied = $this->imports->apply(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $csv,
            $this->userId,
        );
        self::assertFalse($applied['replayed']);
        self::assertSame('accepted', $applied['status']);
        self::assertSame(1, $applied['accepted_count']);
        self::assertSame(0, $applied['rejected_count']);
        self::assertSame(0, $applied['duplicate_count']);
        self::assertSame(1, $this->countInputs());

        $stored = $this->fetchInput('imp-happy-1');
        self::assertSame($this->employmentId, (int) $stored['employment_id']);
        self::assertSame(25000, (int) $stored['amount_minor']);
        self::assertSame('import', $stored['source_kind']);
        self::assertSame('2026-06-01', $stored['period_start']);
        self::assertSame(
            PayrollTimeValue::int($applied['id'] ?? null, 'import_id'),
            (int) $stored['import_id'],
        );
    }

    /**
     * Formát je jediné, co se kontroluje ještě před parserem. Kdyby prošlo
     * cokoliv jiného než csv/xlsx, spadlo by to až uvnitř parseru hláškou,
     * ze které volající nepozná, že si jen spletl příponu.
     */
    public function testRejectsUnsupportedFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Formát musí být csv nebo xlsx.');
        $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'json',
            'odmeny.json',
            '{}',
        );
    }

    public function testRejectsPeriodThatIsNotMonth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Období musí být měsíc YYYY-MM.');
        $this->imports->preview(
            $this->supplierId,
            '2026-06-01',
            'csv',
            'odmeny.csv',
            $this->csv([[self::EMPLOYMENT_CODE, self::COMPONENT_CODE, '1', 'x']]),
        );
    }

    /**
     * Nejdůležitější vlastnost dávkového importu: jeden pokažený řádek nesmí
     * shodit celý soubor. Účetní dostane 50 řádků z 51 a seznam toho, co
     * neprošlo — ne výjimku a nulu zapsaných vstupů.
     */
    public function testMalformedRowsBecomeErrorsInsteadOfExceptions(): void
    {
        $csv = implode("\n", [
            'employment_id;employment_code;component_code;amount_minor;external_id',
            // v pořádku
            "{$this->employmentId};" . self::EMPLOYMENT_CODE . ';' . self::COMPONENT_CODE . ';25000;imp-ok',
            // amount_minor není celé číslo
            "{$this->employmentId};" . self::EMPLOYMENT_CODE . ';' . self::COMPONENT_CODE . ';250,50;imp-amount',
            // neexistující složka
            "{$this->employmentId};" . self::EMPLOYMENT_CODE . ';NEEXISTUJE;25000;imp-component',
            // employment_code neodpovídá employment_id
            "{$this->employmentId};JINY-KOD;" . self::COMPONENT_CODE . ';25000;imp-employment',
            // external_id začíná znakem, kterým tabulkový procesor spouští vzorec
            "{$this->employmentId};" . self::EMPLOYMENT_CODE . ';' . self::COMPONENT_CODE . ';25000;=cmd|calc',
            // méně sloupců než hlavička — chyba z parseru, ne z validace řádku
            "{$this->employmentId};" . self::EMPLOYMENT_CODE . ';' . self::COMPONENT_CODE,
        ]);

        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $csv,
        );

        self::assertSame(1, $preview['accepted_count']);
        self::assertSame(5, $preview['rejected_count']);
        self::assertSame(0, $preview['duplicate_count']);
        self::assertSame(6, $preview['row_count']);
        $codes = array_map(
            static fn (array $error): string => PayrollTimeValue::string(
                $error['error_code'] ?? null,
                'error_code',
            ),
            PayrollTimeValue::rows($preview['errors'], 'errors'),
        );
        self::assertContains('column_count', $codes);
        self::assertSame(4, count(array_filter(
            $codes,
            static fn (string $code): bool => $code === 'row_validation_failed',
        )));

        // A totéž musí platit i po zápisu: protokol si chyby zapamatuje,
        // ale do mezd projde jen ten jeden platný řádek.
        $applied = $this->imports->apply(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $csv,
            $this->userId,
        );
        self::assertSame('partial', $applied['status']);
        self::assertSame(1, $applied['accepted_count']);
        self::assertSame(5, $applied['rejected_count']);
        self::assertSame(1, $this->countInputs());
        self::assertCount(6, PayrollTimeValue::rows($applied['rows'] ?? null, 'rows'));
    }

    /**
     * Opakovaný `apply()` téhož obsahu je v praxi běžný — účetní klikne dvakrát,
     * nebo se požadavek zopakuje po timeoutu. Import se pozná podle sha256 obsahu
     * a MUSÍ vrátit ten původní protokol, ne založit druhou sadu vstupů.
     */
    public function testReapplyingIdenticalContentReturnsExistingImport(): void
    {
        $csv = $this->csv([
            [self::EMPLOYMENT_CODE, self::COMPONENT_CODE, '25000', 'imp-idem-1'],
        ]);

        $first = $this->imports->apply(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $csv,
            $this->userId,
        );
        self::assertFalse($first['replayed']);
        self::assertSame(1, $this->countInputs());

        // Podruhé i s jiným názvem souboru a jiným uživatelem: rozhoduje obsah.
        $second = $this->imports->apply(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny-znovu.csv',
            $csv,
            null,
        );
        self::assertTrue($second['replayed']);
        self::assertSame(
            PayrollTimeValue::int($first['id'] ?? null, 'import_id'),
            PayrollTimeValue::int($second['id'] ?? null, 'import_id'),
        );
        self::assertSame($first['content_hash'], $second['content_hash']);
        self::assertSame(1, $this->countInputs());
        self::assertSame(1, $this->countImports());
    }

    /**
     * Idempotence stojí na hashi OBSAHU, ne na názvu souboru. Jiný obsah (byť
     * o jediný haléř) je nový import — jinak by oprava překlepu tiše propadla.
     */
    public function testDifferentContentIsANewImport(): void
    {
        $first = $this->imports->apply(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $this->csv([[self::EMPLOYMENT_CODE, self::COMPONENT_CODE, '25000', 'imp-a']]),
            $this->userId,
        );
        $second = $this->imports->apply(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $this->csv([[self::EMPLOYMENT_CODE, self::COMPONENT_CODE, '25001', 'imp-b']]),
            $this->userId,
        );

        self::assertFalse($second['replayed']);
        self::assertNotSame(
            PayrollTimeValue::int($first['id'] ?? null, 'import_id'),
            PayrollTimeValue::int($second['id'] ?? null, 'import_id'),
        );
        self::assertSame(2, $this->countInputs());
        self::assertSame(2, $this->countImports());
    }

    /**
     * Dvojí výskyt téhož `external_id` v JEDNOM souboru je typický důsledek
     * kopírování řádku v Excelu. Druhý výskyt patří mezi duplicity, ne mezi
     * přijaté vstupy — a rozhodně se nesmí zapsat podruhé.
     */
    public function testDuplicateExternalIdWithinFileIsReportedNotImported(): void
    {
        $csv = $this->csv([
            [self::EMPLOYMENT_CODE, self::COMPONENT_CODE, '25000', 'imp-dup'],
            [self::EMPLOYMENT_CODE, self::COMPONENT_CODE, '31000', 'imp-dup'],
        ]);

        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $csv,
        );
        self::assertSame(1, $preview['accepted_count']);
        self::assertSame(1, $preview['duplicate_count']);
        self::assertSame(
            'duplicate_in_file',
            PayrollTimeValue::rows($preview['duplicates'], 'duplicates')[0]['error_code'],
        );

        $applied = $this->imports->apply(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $csv,
            $this->userId,
        );
        self::assertSame('partial', $applied['status']);
        self::assertSame(1, $applied['accepted_count']);
        self::assertSame(1, $applied['duplicate_count']);
        self::assertSame(1, $this->countInputs());
        self::assertSame(25000, (int) $this->fetchInput('imp-dup')['amount_minor']);
    }

    /**
     * Vstup, který v tomto vztahu a měsíci už existuje z dřívějšího importu,
     * se pozná ještě v náhledu — a to i tehdy, když je soubor jinak úplně nový.
     */
    public function testExternalIdAlreadyStoredIsReportedAsDuplicate(): void
    {
        $this->imports->apply(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $this->csv([[self::EMPLOYMENT_CODE, self::COMPONENT_CODE, '25000', 'imp-old']]),
            $this->userId,
        );

        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny-2.csv',
            $this->csv([[self::EMPLOYMENT_CODE, self::COMPONENT_CODE, '99000', 'imp-old']]),
        );

        self::assertSame(0, $preview['accepted_count']);
        self::assertSame(1, $preview['duplicate_count']);
        self::assertSame(
            'duplicate_external_id',
            PayrollTimeValue::rows($preview['duplicates'], 'duplicates')[0]['error_code'],
        );
        // Původní částka se nepřepsala.
        self::assertSame(25000, (int) $this->fetchInput('imp-old')['amount_minor']);
    }

    /**
     * Multi-tenant izolace: vztah cizí firmy se nesmí naimportovat ani tehdy,
     * když útočník zná jeho `employment_id` i kód. `resolveEmployment()` filtruje
     * podle supplier_id, ale test to drží — je to hranice mezi firmami.
     */
    public function testEmploymentOfAnotherSupplierIsRejected(): void
    {
        $pdo = $this->db->pdo();
        $foreignSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $this->firstId($pdo, 'supplier'),
        );
        $previous = $this->supplierId;
        $this->supplierId = $foreignSupplierId;
        [, $foreignEmploymentId] = $this->createEmployment('CIZI-1');
        $this->supplierId = $previous;

        $csv = implode("\n", [
            'employment_id;employment_code;component_code;amount_minor;external_id',
            "{$foreignEmploymentId};CIZI-1;" . self::COMPONENT_CODE . ';25000;imp-cizi',
        ]);
        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $csv,
        );

        self::assertSame(0, $preview['accepted_count']);
        self::assertSame(1, $preview['rejected_count']);
        self::assertStringContainsString(
            'Pracovní vztah nebyl v této firmě nalezen',
            PayrollTimeValue::rows($preview['errors'], 'errors')[0]['error_message'],
        );
    }

    /**
     * Import umí jen jednorázové složky. Měsíční předpis se do měsíce dostává
     * materializací opakovaných složek, ne CSV — kdyby to import pustil, vznikl
     * by druhý zdroj pravdy pro tutéž částku.
     */
    public function testRecurringComponentCannotBeImported(): void
    {
        $this->createComponent('MZDA_IMP', frequency: 'regular', kind: 'base_wage');

        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'odmeny.csv',
            $this->csv([[self::EMPLOYMENT_CODE, 'MZDA_IMP', '4200000', 'imp-mzda']]),
        );

        self::assertSame(0, $preview['accepted_count']);
        self::assertStringContainsString(
            'Jednorázová mzdová složka není v období jednoznačně účinná.',
            PayrollTimeValue::rows($preview['errors'], 'errors')[0]['error_message'],
        );
    }

    /**
     * Název souboru jde do protokolu a odtud do UI. Cesta se musí useknout na
     * holé jméno, jinak by šlo do jména propašovat adresářovou navigaci.
     */
    public function testSourceNameIsReducedToBasename(): void
    {
        $preview = $this->imports->preview(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'C:\\Users\\ucetni\\..\\odmeny.csv',
            $this->csv([[self::EMPLOYMENT_CODE, self::COMPONENT_CODE, '25000', 'imp-name']]),
        );

        self::assertSame('odmeny.csv', $preview['source_name']);
    }

    /** @param list<array{0:string,1:string,2:string,3:string}> $rows */
    private function csv(array $rows): string
    {
        $lines = ['employment_id;employment_code;component_code;amount_minor;external_id'];
        foreach ($rows as [$employmentCode, $componentCode, $amount, $externalId]) {
            $lines[] = implode(';', [
                (string) $this->employmentId,
                $employmentCode,
                $componentCode,
                $amount,
                $externalId,
            ]);
        }
        return implode("\n", $lines);
    }

    /** @return array{0:int,1:int} */
    private function createEmployment(string $code): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId, "Importovaná osoba {$code}"]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $employeeId, $code]);
        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function createComponent(
        string $code,
        string $frequency = 'one_off',
        string $kind = 'bonus',
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code,
                 valid_from, is_active)
             VALUES (?, ?, ?, ?, "monetary", ?, "included",
                     "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "521", "331", "2026-01-01", 1)'
        )->execute([$this->supplierId, $code, "Importní {$code}", $kind, $frequency]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function fetchInput(string $externalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_inputs
              WHERE supplier_id = ? AND external_id = ? AND source_kind = "import"'
        );
        $stmt->execute([$this->supplierId, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Vstup {$externalId} nebyl zapsán.");
        return $row;
    }

    private function countInputs(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function countImports(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_input_imports WHERE supplier_id = ?'
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
