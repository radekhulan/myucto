<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollInputsExportAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollInputFilter;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Export\PayrollInputExportService;
use MyInvoice\Service\Payroll\Export\PayrollInputXlsxExporter;
use MyInvoice\Service\Pdf\PayrollInputsPdfRenderer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Export mzdových vstupů do XLSX a PDF podle filtru stránky.
 *
 * Export musí obsahovat přesně tu množinu, kterou uživatel vidí ve výpisu
 * (tentýž filtr), nic z cizí firmy a žádný text, který by Excel spustil
 * jako vzorec.
 */
#[Group('integration')]
final class PayrollInputsExportApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';
    private const PERIOD_START = '2026-06-01';

    private Connection $db;
    private PayrollComponentsAction $components;
    private PayrollInputsExportAction $export;
    private PayrollInputExportService $service;
    private PayrollInputsPdfRenderer $renderer;
    private int $sourceSupplierId;
    private int $supplierId;
    private int $userId;
    /** @var array{int,int} */
    private array $alfa;
    /** @var array{int,int} */
    private array $beta;
    /** @var array{int,int} */
    private array $gama;
    private int $bonusA;
    private int $bonusB;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $components = $container->get(PayrollComponentsAction::class);
        $export = $container->get(PayrollInputsExportAction::class);
        $service = $container->get(PayrollInputExportService::class);
        $renderer = $container->get(PayrollInputsPdfRenderer::class);
        if (!$db instanceof Connection
            || !$components instanceof PayrollComponentsAction
            || !$export instanceof PayrollInputsExportAction
            || !$service instanceof PayrollInputExportService
            || !$renderer instanceof PayrollInputsPdfRenderer
        ) {
            throw new \RuntimeException('Payroll služby nejsou dostupné.');
        }
        $this->db = $db;
        $this->components = $components;
        $this->export = $export;
        $this->service = $service;
        $this->renderer = $renderer;
        foreach (['payroll_inputs', 'payroll_input_imports'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $this->sourceSupplierId = $this->firstId('supplier');
        $this->userId = $this->firstId('users');
        if ($this->sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->payrollSupplier();
        $this->alfa = $this->employment($this->supplierId, 'Syntetická Alfa', 'SYN-ALFA-1');
        $this->beta = $this->employment($this->supplierId, 'Syntetický Beta', 'SYN-BETA-2');
        // Jméno i osobní číslo začínají znakem, který by Excel vyhodnotil jako vzorec.
        $this->gama = $this->employment($this->supplierId, '=1+1 Syntetická Gama', '+SYN-GAMA-3');
        $this->bonusA = $this->createComponent($this->supplierId, 'SYN_EXP_A');
        $this->bonusB = $this->createComponent($this->supplierId, 'SYN_EXP_B');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testXlsxContainsExactlyTheFilteredRowsWithTotalsAndHiddenKeys(): void
    {
        $alfaA = $this->insertDrafts($this->alfa, $this->bonusA, 2, 10_000);
        $alfaB = $this->insertDrafts($this->alfa, $this->bonusB, 1, 20_000);
        $importId = $this->createImport();
        $betaImported = $this->insertDrafts($this->beta, $this->bonusA, 3, 5_000, $importId);
        $this->insertDrafts($this->gama, $this->bonusB, 1, 7_000);

        $book = $this->exportXlsx(['q' => 'Alfa']);
        $sheet = $this->inputsSheet($book);
        self::assertSame($this->sorted([...$alfaA, ...$alfaB]), $this->rowKeys($sheet));
        self::assertSame('Celkem (3 vstupů)', $sheet->getCell('A6')->getValue());
        self::assertEqualsWithDelta(400.0, $sheet->getCell('I6')->getValue(), 0.0001);
        self::assertSame(1, (int) $sheet->getCell('N2')->getValue(), 'row_version odpovídá databázi.');
        self::assertFalse($sheet->getColumnDimension('M')->getVisible());
        self::assertFalse($sheet->getColumnDimension('N')->getVisible());
        self::assertSame('SYN-ALFA-1', $sheet->getCell('A2')->getValue());
        self::assertSame('Koncept', $sheet->getCell('J2')->getValue());
        self::assertSame('Ruční vstup', $sheet->getCell('K2')->getValue());
        self::assertSame('Alfa', $this->infoValue($book, 'Filtr: Hledaný text'));
        self::assertSame('06/2026', $this->infoValue($book, 'Období'));

        $byImport = $this->inputsSheet($this->exportXlsx(['import_id' => (string) $importId]));
        self::assertSame($betaImported, $this->rowKeys($byImport));
        self::assertSame('#' . $importId . ' dochazka-syntetika.csv', $byImport->getCell('L2')->getValue());
        self::assertEqualsWithDelta(150.0, $byImport->getCell('I6')->getValue(), 0.0001);

        $combined = $this->inputsSheet($this->exportXlsx([
            'q' => 'Beta',
            'status' => 'draft',
            'component_code' => 'SYN_EXP_A',
        ]));
        self::assertSame($betaImported, $this->rowKeys($combined));

        // Seskupení na stránce export nemění.
        $grouped = $this->inputsSheet($this->exportXlsx(['q' => 'Alfa', 'group_by' => 'employee']));
        self::assertSame($this->sorted([...$alfaA, ...$alfaB]), $this->rowKeys($grouped));
    }

    /**
     * Export přes rozsah měsíců (historie jednoho vztahu) musí obsahovat všechny
     * měsíce rozsahu a NESMÍ se tvářit jako jediný měsíc: hlavička i název
     * souboru odvozené jen ze začátku rozsahu tvrdily „06/2026" o sestavě za
     * půl roku.
     */
    public function testRangeExportCoversEveryMonthAndNamesTheWholeRange(): void
    {
        $june = $this->insertDrafts($this->alfa, $this->bonusA, 1, 10_000);
        $march = $this->insertDrafts($this->alfa, $this->bonusA, 1, 30_000, null, null, '2026-03-01');
        $january = $this->insertDrafts($this->alfa, $this->bonusB, 1, 20_000, null, null, '2026-01-01');
        // Měsíc před rozsahem do sestavy patřit nesmí.
        $this->insertDrafts($this->alfa, $this->bonusB, 1, 90_000, null, null, '2025-12-01');

        $range = ['period' => '2026-01', 'period_to' => '2026-06'];
        $book = $this->exportXlsx($range);
        self::assertSame(
            $this->sorted([...$january, ...$march, ...$june]),
            $this->rowKeys($this->inputsSheet($book)),
        );
        self::assertSame('01/2026 – 06/2026', $this->infoValue($book, 'Období'));
        self::assertStringContainsString(
            'mzdove-vstupy-2026-01_2026-06.xlsx',
            $this->callExport('xlsx', $range)->getHeaderLine('Content-Disposition'),
        );
        self::assertStringContainsString(
            'mzdove-vstupy-2026-01_2026-06.pdf',
            $this->callExport('pdf', $range)->getHeaderLine('Content-Disposition'),
        );

        // Bez rozsahu zůstává všechno při starém — jeden měsíc, jeden popisek.
        $single = $this->exportXlsx([]);
        self::assertSame($june, $this->rowKeys($this->inputsSheet($single)));
        self::assertSame('06/2026', $this->infoValue($single, 'Období'));
        self::assertStringContainsString(
            'mzdove-vstupy-2026-06.xlsx',
            $this->callExport('xlsx', [])->getHeaderLine('Content-Disposition'),
        );

        // Obrácený rozsah je chyba vstupu, ne tiše prázdná sestava.
        self::assertSame(
            422,
            $this->callExport('xlsx', ['period' => '2026-06', 'period_to' => '2026-01'])->getStatusCode(),
        );
    }

    /**
     * Rozsah „vše" se na server posílá jako dokořán otevřené meze, protože
     * `period` je povinné. Ty meze jsou technický sentinel — do hlavičky
     * sestavy ani do názvu souboru nepatří. Období se proto odvozuje ze
     * SKUTEČNÝCH řádků, což je i věcně přesnější.
     */
    public function testWideRangeIsLabelledByTheRealDataSpanNotBySentinelBounds(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, 1, 10_000, null, null, '2026-02-01');
        $this->insertDrafts($this->alfa, $this->bonusB, 1, 20_000, null, null, '2026-05-01');

        $wide = ['period' => '1990-01', 'period_to' => '2099-12'];
        self::assertSame('02/2026 – 05/2026', $this->infoValue($this->exportXlsx($wide), 'Období'));
        $disposition = $this->callExport('xlsx', $wide)->getHeaderLine('Content-Disposition');
        self::assertStringContainsString('mzdove-vstupy-2026-02_2026-05.xlsx', $disposition);
        self::assertStringNotContainsString('1990', $disposition);
        self::assertStringNotContainsString('2099', $disposition);

        // Rozsah, ve kterém nic není, žádné rozpětí nemá — meze filtru by
        // tvrdily, že sestava pokrývá devadesátá léta.
        $empty = ['period' => '1990-01', 'period_to' => '1999-12'];
        self::assertSame('bez vstupů', $this->infoValue($this->exportXlsx($empty), 'Období'));
        self::assertStringContainsString(
            'mzdove-vstupy-bez-vstupu.xlsx',
            $this->callExport('xlsx', $empty)->getHeaderLine('Content-Disposition'),
        );

        // Jediný měsíc je plnohodnotný údaj i bez vstupů; tam se nic nemění.
        $quiet = ['period' => '2026-09'];
        self::assertSame('09/2026', $this->infoValue($this->exportXlsx($quiet), 'Období'));
        self::assertStringContainsString(
            'mzdove-vstupy-2026-09.xlsx',
            $this->callExport('xlsx', $quiet)->getHeaderLine('Content-Disposition'),
        );
    }

    public function testFormulaLikeNamesAreExportedAsLiteralText(): void
    {
        $gama = $this->insertDrafts($this->gama, $this->bonusB, 1, 7_000)[0];
        $this->insertDrafts($this->alfa, $this->bonusA, 1, 1_000);

        $sheet = $this->inputsSheet($this->exportXlsx([]));
        $row = $this->rowOf($sheet, $gama);
        foreach (['A' => '+SYN-GAMA-3', 'B' => '=1+1 Syntetická Gama'] as $column => $value) {
            $cell = $column . $row;
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), $cell);
            self::assertSame($value, $sheet->getCell($cell)->getValue());
            self::assertTrue($sheet->getStyle($cell)->getQuotePrefix(), $cell);
        }
    }

    public function testForeignSupplierInputsAreNeverExported(): void
    {
        $mine = $this->insertDrafts($this->alfa, $this->bonusA, 1, 1_000);

        $foreignSupplierId = $this->payrollSupplier();
        $foreign = $this->employment($foreignSupplierId, 'Syntetická Alfa Cizí', 'SYN-CIZI-1');
        $foreignComponent = $this->createComponent($foreignSupplierId, 'SYN_EXP_A');
        $this->insertDrafts($foreign, $foreignComponent, 2, 9_000, null, $foreignSupplierId);

        self::assertSame($mine, $this->rowKeys($this->inputsSheet($this->exportXlsx([]))));
        self::assertSame([], $this->rowKeys($this->inputsSheet($this->exportXlsx(['q' => 'Cizí']))));
        $byForeignPerson = $this->inputsSheet($this->exportXlsx(['employee_id' => (string) $foreign[0]]));
        self::assertSame([], $this->rowKeys($byForeignPerson));
        self::assertSame('Celkem (0 vstupů)', $byForeignPerson->getCell('A3')->getValue());
        self::assertSame(
            [],
            $this->rowKeys($this->inputsSheet($this->exportXlsx(['component_id' => (string) $foreignComponent]))),
        );
    }

    public function testPdfIsGroupedByEmployeeWithSubtotalsAndComponentRecap(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, 2, 10_000);
        $this->insertDrafts($this->alfa, $this->bonusB, 1, 20_000);
        $this->insertDrafts($this->beta, $this->bonusA, 1, 5_000);

        $response = $this->callExport('pdf', ['status' => 'draft']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('mzdove-vstupy-2026-06.pdf', $response->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $response->getBody());

        $filter = PayrollInputFilter::fromArray(self::PERIOD_START, ['status' => 'draft']);
        $data = $this->service->pdfData(
            $this->service->context($this->supplierId, $filter),
            $this->service->rows($this->supplierId, $filter, PayrollInputExportService::PDF_MAX_ROWS),
        );
        // Jako přehled mezd: příjmení napřed, podle české abecedy.
        self::assertSame(['Alfa Syntetická', 'Beta Syntetický'], array_column($data['groups'], 'name'));
        self::assertSame(['400,00', '50,00'], array_column($data['groups'], 'amount'));
        self::assertSame(
            [['SYN_EXP_A', 3, '250,00'], ['SYN_EXP_B', 1, '200,00']],
            array_map(static fn (array $item): array => [$item['code'], $item['count'], $item['amount']], $data['recap']),
        );
        self::assertSame('450,00', $data['total']);

        $html = $this->renderer->renderHtml($data);
        self::assertStringContainsString('Alfa Syntetická', $html);
        self::assertStringContainsString('Rekapitulace podle složky', $html);
        self::assertStringContainsString('Stav: Koncept', $html);

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log
              WHERE action = "payroll.inputs.exported" AND supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'Export osobních údajů se eviduje.');
    }

    public function testPdfOverTheLimitAsksToNarrowTheFilter(): void
    {
        $this->insertDrafts($this->alfa, $this->bonusA, PayrollInputExportService::PDF_MAX_ROWS + 1, 100);

        $response = $this->callExport('pdf', []);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        self::assertStringContainsString('export_too_large', $body);
        self::assertStringContainsString('Zužte filtr nebo použijte Excel', $body);
    }

    public function testInvalidRequestsAreRejected(): void
    {
        self::assertSame(422, $this->callExport('csv', [])->getStatusCode());
        self::assertSame(422, $this->callExport('xlsx', ['period' => '2026-13'])->getStatusCode());
        self::assertSame(422, $this->callExport('xlsx', ['status' => 'cancelled'])->getStatusCode());

        $withoutPayroll = $this->request('/api/payroll/inputs/export.xlsx', $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(44, 'Bez mezd', 'staff', true, []))
            ->withQueryParams(['period' => self::PERIOD]);
        self::assertSame(403, $this->export->export($withoutPayroll, new Response(), ['format' => 'xlsx'])->getStatusCode());
    }

    /** @param array<string,string> $query */
    private function callExport(string $format, array $query): ResponseInterface
    {
        $response = $this->export->export(
            $this->request('/api/payroll/inputs/export.' . $format, $this->supplierId)
                ->withQueryParams(['period' => self::PERIOD, ...$query]),
            new Response(),
            ['format' => $format],
        );
        $response->getBody()->rewind();

        return $response;
    }

    /** @param array<string,string> $query */
    private function exportXlsx(array $query): Spreadsheet
    {
        $response = $this->callExport('xlsx', $query);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(PayrollInputXlsxExporter::MIME, $response->getHeaderLine('Content-Type'));
        $file = tempnam(sys_get_temp_dir(), 'payinp_it_') . '.xlsx';
        file_put_contents($file, (string) $response->getBody());
        try {
            return IOFactory::load($file);
        } finally {
            @unlink($file);
        }
    }

    private function inputsSheet(Spreadsheet $book): Worksheet
    {
        $sheet = $book->getSheetByName(PayrollInputXlsxExporter::SHEET_INPUTS);
        self::assertNotNull($sheet);

        return $sheet;
    }

    private function infoValue(Spreadsheet $book, string $label): mixed
    {
        $info = $book->getSheetByName(PayrollInputXlsxExporter::SHEET_INFO);
        self::assertNotNull($info);
        foreach ($info->toArray(null, false, false) as $line) {
            if ($line[0] === $label) {
                return $line[1];
            }
        }
        self::fail("Na listu Info chybí řádek {$label}.");
    }

    /** @return list<int> row_key všech datových řádků, seřazené */
    private function rowKeys(Worksheet $sheet): array
    {
        $keys = [];
        for ($row = 2; ($value = $sheet->getCell('M' . $row)->getValue()) !== null; ++$row) {
            $keys[] = (int) $value;
        }

        return $this->sorted($keys);
    }

    private function rowOf(Worksheet $sheet, int $inputId): int
    {
        for ($row = 2; ($value = $sheet->getCell('M' . $row)->getValue()) !== null; ++$row) {
            if ((int) $value === $inputId) {
                return $row;
            }
        }
        self::fail("Vstup {$inputId} v exportu chybí.");
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function payrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);

        return $supplierId;
    }

    /**
     * @param array{int,int} $person
     * @return list<int>
     */
    private function insertDrafts(
        array $person,
        int $componentId,
        int $count,
        int $amountMinor,
        ?int $importId = null,
        ?int $supplierId = null,
        ?string $periodStart = null,
    ): array {
        $supplierId ??= $this->supplierId;
        $periodStart ??= self::PERIOD_START;
        $pdo = $this->db->pdo();
        $values = [];
        $params = [];
        for ($index = 0; $index < $count; ++$index) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $params,
                $supplierId,
                $person[0],
                $person[1],
                $componentId,
                $periodStart,
                $amountMinor,
                $importId === null ? 'manual' : 'import',
                $importId === null ? null : 'syn-export-' . $componentId . '-' . $index,
                $importId,
                $this->userId,
            );
        }
        $before = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM payroll_inputs')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start,
                 amount_minor, source_kind, external_id, import_id, created_by)
             VALUES ' . implode(', ', $values)
        )->execute($params);
        $stmt = $pdo->prepare(
            'SELECT id FROM payroll_inputs
              WHERE supplier_id = ? AND id > ? AND employment_id = ? AND component_id = ?
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $before, $person[1], $componentId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function createImport(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_input_imports
                (supplier_id, period_start, source_kind, source_name, content_hash,
                 status, row_count, accepted_count)
             VALUES (?, ?, "csv", "dochazka-syntetika.csv", UNHEX(SHA2(?, 256)), "accepted", 1, 1)'
        )->execute([$this->supplierId, self::PERIOD_START, 'syntetika-export-' . microtime(true)]);

        return (int) $pdo->lastInsertId();
    }

    private function createComponent(int $supplierId, string $code): int
    {
        $request = $this->request('/api/payroll/components', $supplierId)
            ->withMethod('POST')
            ->withParsedBody([
                'code' => $code,
                'name' => 'Syntetická složka ' . $code,
                'component_kind' => 'bonus',
                'value_kind' => 'monetary',
                'frequency_kind' => 'one_off',
                'tax_treatment' => 'included',
                'social_participation_treatment' => 'included',
                'social_treatment' => 'included',
                'health_participation_treatment' => 'included',
                'health_treatment' => 'included',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'included',
                'jmhz_treatment' => 'included',
                'statistics_treatment' => 'included',
                'accounting_debit_code' => null,
                'accounting_credit_code' => null,
                'annual_limit_minor' => null,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
            ]);
        $response = $this->components->create($request, new Response());
        $response->getBody()->rewind();
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = PayrollTimeValue::row(json_decode((string) $response->getBody(), true), 'response');
        $component = PayrollTimeValue::row($payload['component'] ?? null, 'component');

        return PayrollTimeValue::int($component['id'] ?? null, 'component.id');
    }

    /** @return array{int,int} */
    private function employment(int $supplierId, string $name, string $code): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "legacy")'
        )->execute([$supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$supplierId, $employeeId, $code]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function firstId(string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $this->db->pdo()->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    private function request(string $uri, int $supplierId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }
}
