<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollQuickInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Component\PayrollInputImportService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dynamické sloupce mzdových složek v rychlém měsíčním vstupu a ruční přepis
 * importované hodnoty.
 *
 * Nejdůležitější vlastnost je daňová: přepsaná hodnota se do výpočtu smí
 * dostat JEN JEDNOU. Importní vstup zůstává jako doklad dávky, ale výpočet běhu
 * ({@see PayrollRunSnapshotBatchLoader::inputs()}) i náhled rychlého vstupu
 * musí vidět jen přepis.
 */
#[Group('integration')]
final class PayrollQuickInputComponentsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';
    private const PERIOD_START = '2026-06-01';
    private const BOZP = 'PRIPLATEK_BOZP';
    private const PROJECT = 'ODMENA_PROJEKT';

    private Connection $db;
    private PayrollQuickInputRepository $quickInputs;
    private PayrollInputImportService $imports;
    private PayrollInputRepository $inputs;
    private PayrollRunSnapshotBatchLoader $loader;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    /** @var array<string,int> */
    private array $employments = [];

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->quickInputs = $container->get(PayrollQuickInputRepository::class);
        $this->imports = $container->get(PayrollInputImportService::class);
        $this->inputs = $container->get(PayrollInputRepository::class);
        $this->loader = $container->get(PayrollRunSnapshotBatchLoader::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId('supplier');
        $this->userId = $this->firstId('users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        foreach (['A' => 'Adamová Syntetická', 'B' => 'Benešová Syntetická', 'C' => 'Cibulka Syntetický'] as $key => $name) {
            $this->employments[$key] = $this->employment($this->supplierId, $name, "SYN-DYN-{$key}");
        }
        $this->component($this->supplierId, self::BOZP, 'premium');
        $this->component($this->supplierId, self::PROJECT, 'bonus');
        $this->component($this->supplierId, 'MZDA_HODINOVA_DOCH', 'hourly_wage');
        $this->component($this->supplierId, 'STRAVNE_PAUSAL', 'benefit_meal', 'exempt', 'non_monetary');
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

    public function testColumnsAndTotalsCoverTheWholePeriodNotThePage(): void
    {
        $this->importAttendance([
            ['A', self::BOZP, 50_000],
            ['B', self::BOZP, 20_000],
        ]);

        $month = $this->quickInputs->month($this->supplierId, self::PERIOD, 1, 0);

        self::assertCount(1, $month['items'], 'Stránka má jeden řádek.');
        self::assertSame(3, $month['total']);
        $columns = [];
        foreach ($month['columns'] as $column) {
            $columns[$column['code']] = $column;
        }
        self::assertArrayHasKey(self::BOZP, $columns);
        self::assertSame(2, $columns[self::BOZP]['rows_with_value'], 'Počet je za celé období.');
        self::assertTrue($columns[self::BOZP]['editable_in_quick']);
        self::assertSame('premium', $columns[self::BOZP]['kind']);
        // Zadatelná složka bez hodnoty se nabízí, benefit bez hodnoty ne.
        self::assertArrayHasKey(self::PROJECT, $columns);
        self::assertSame(0, $columns[self::PROJECT]['rows_with_value']);
        self::assertArrayNotHasKey('STRAVNE_PAUSAL', $columns);
        // Pevná pole dynamický sloupec nemají.
        self::assertArrayNotHasKey('ODMENA', $columns);
        self::assertArrayNotHasKey('MZDA_MESICNI', $columns);

        $totals = $month['totals'];
        self::assertSame(3, $totals['rows']);
        self::assertSame(70_000, $totals['components'][self::BOZP]['amount_minor']);
        self::assertSame(2, $totals['components'][self::BOZP]['rows_with_value']);
        // 3 × 42 000 Kč základu + 700 Kč z importu.
        self::assertSame(3 * 4_200_000 + 70_000, $totals['gross_preview_minor']);
    }

    /**
     * Složka z importu docházky má vlastní sloupec a nesmí zamknout přesčas.
     * Hodinová mzda z docházky ale základ dál brzdí — jinak by uložení stránky
     * přidalo hodinovému zaměstnanci i měsíční mzdu.
     */
    public function testAttendanceImportGetsOwnColumnWithoutLockingOvertime(): void
    {
        $this->importAttendance([
            ['A', self::BOZP, 50_000],
            ['B', 'MZDA_HODINOVA_DOCH', 900_000],
        ]);

        $a = $this->item('A');
        self::assertFalse($a['overtime_managed_elsewhere'], 'PRIPLATEK_BOZP přesčas nezamyká.');
        self::assertNotContains('overtime_managed_elsewhere', $a['blockers']);
        self::assertSame('import', $a['components'][self::BOZP]['mode']);
        self::assertSame('import', $a['components'][self::BOZP]['source']);
        self::assertSame(50_000, $a['components'][self::BOZP]['amount_minor']);
        self::assertTrue($a['components'][self::BOZP]['entry_available']);
        self::assertSame(4_200_000 + 50_000, $a['gross_preview_minor']);

        $b = $this->item('B');
        self::assertTrue($b['base_managed_elsewhere'], 'Hodinová mzda z docházky základ dál spravuje.');
    }

    public function testSavesManualComponentThroughComponentsField(): void
    {
        $failures = $this->save('A', [self::PROJECT => ['amount_minor' => 123_456, 'row_version' => null]]);
        self::assertSame([], $failures);

        $input = $this->activeInput('A', 'quick-monthly:' . self::PROJECT);
        self::assertSame(123_456, (int) $input['amount_minor']);
        self::assertSame('manual', $input['source_kind']);
        $cell = $this->item('A')['components'][self::PROJECT];
        self::assertSame('manual', $cell['mode']);
        self::assertSame(123_456, $cell['amount_minor']);
        // Vlastní zadání do sloupce složky nezamyká pevné pole Odměna.
        self::assertFalse($this->item('A')['bonus_managed_elsewhere']);

        $failures = $this->save('A', [self::PROJECT => ['amount_minor' => 99_000, 'row_version' => $cell['row_version']]]);
        self::assertSame([], $failures);
        self::assertSame(99_000, $this->item('A')['components'][self::PROJECT]['amount_minor']);

        $cell = $this->item('A')['components'][self::PROJECT];
        $failures = $this->save('A', [self::PROJECT => ['amount_minor' => null, 'row_version' => $cell['row_version']]]);
        self::assertSame([], $failures);
        self::assertArrayNotHasKey(self::PROJECT, $this->item('A')['components']);
    }

    public function testComponentThatCannotBeEnteredIsRefusedPerField(): void
    {
        $failures = $this->save('A', ['STRAVNE_PAUSAL' => ['amount_minor' => 10_000, 'row_version' => null]]);

        self::assertCount(1, $failures);
        self::assertSame('component:STRAVNE_PAUSAL', $failures[0]['field']);
        self::assertSame(0, $this->countInputs('A'));
    }

    /**
     * Daňově kritické: po přepisu importované hodnoty jde do výpočtu běhu
     * i do náhledu JEN přepis. Importní vstup zůstává jako doklad (zrušený).
     */
    public function testOverrideOfImportedValueIsCountedExactlyOnce(): void
    {
        $this->importAttendance([['A', self::BOZP, 50_000]]);
        $import = $this->activeInput('A', $this->attendanceId('A', self::BOZP));
        // Importní vstup je schválený a počítá se do běhu.
        $this->inputs->approve($this->supplierId, (int) $import['id'], (int) $import['row_version'], $this->userId);
        self::assertSame([50_000], $this->runAmounts('A'));

        $cell = $this->item('A')['components'][self::BOZP];
        $failures = $this->save('A', [self::BOZP => ['amount_minor' => 70_000, 'row_version' => $cell['row_version']]]);
        self::assertSame([], $failures);

        self::assertSame([70_000], $this->runAmounts('A'), 'Do výpočtu běhu jde jen přepis.');
        $item = $this->item('A');
        self::assertSame(4_200_000 + 70_000, $item['gross_preview_minor'], 'Náhled nesčítá import i přepis.');
        self::assertSame(70_000, $item['components'][self::BOZP]['amount_minor']);
        self::assertSame('override', $item['components'][self::BOZP]['mode']);
        self::assertSame(50_000, $item['components'][self::BOZP]['override_of']['amount_minor']);
        // Součet za celé období: tři vztahy po 42 000 Kč a jediná hodnota 700 Kč.
        self::assertSame(
            3 * 4_200_000 + 70_000,
            $this->quickInputs->month($this->supplierId, self::PERIOD)['totals']['gross_preview_minor'],
        );

        // Doklad dávky zůstal: importní vstup existuje, je zrušený a má
        // původní částku i vazbu na import.
        $stored = $this->inputById((int) $import['id']);
        self::assertSame('cancelled', $stored['status']);
        self::assertSame(50_000, (int) $stored['amount_minor']);
        self::assertNotNull($stored['import_id']);
        // Přepis drží složku i období importu.
        $override = $this->activeInput('A', 'override:' . $import['id']);
        self::assertSame((int) $import['component_id'], (int) $override['component_id']);
        self::assertSame(self::PERIOD_START, $override['period_start']);
        self::assertSame('approved', $override['status']);
    }

    public function testRevertRestoresTheImportedValue(): void
    {
        $this->importAttendance([['A', self::BOZP, 50_000]]);
        $cell = $this->item('A')['components'][self::BOZP];
        self::assertSame([], $this->save('A', [self::BOZP => ['amount_minor' => 70_000, 'row_version' => $cell['row_version']]]));
        $override = $this->item('A')['components'][self::BOZP];

        $failures = $this->save('A', [self::BOZP => ['revert' => true, 'row_version' => $override['row_version']]]);
        self::assertSame([], $failures);

        $cell = $this->item('A')['components'][self::BOZP];
        self::assertSame('import', $cell['mode']);
        self::assertSame(50_000, $cell['amount_minor']);
        self::assertNull($cell['override_of']);
        self::assertSame([50_000], $this->runAmounts('A'));
        self::assertSame(1, $this->countInputs('A', active: true));
    }

    /** Přepis zpátky na importovanou částku je návrat k importu, ne druhý ruční vstup. */
    public function testOverridingBackToImportedAmountReverts(): void
    {
        $this->importAttendance([['A', self::BOZP, 50_000]]);
        $cell = $this->item('A')['components'][self::BOZP];
        self::assertSame([], $this->save('A', [self::BOZP => ['amount_minor' => 70_000, 'row_version' => $cell['row_version']]]));
        $override = $this->item('A')['components'][self::BOZP];

        self::assertSame([], $this->save('A', [self::BOZP => ['amount_minor' => 50_000, 'row_version' => $override['row_version']]]));

        self::assertSame('import', $this->item('A')['components'][self::BOZP]['mode']);
        self::assertSame(1, $this->countInputs('A', active: true));
    }

    public function testLockedImportedValueCanOnlyBeCorrectedByRevision(): void
    {
        $this->importAttendance([['A', self::BOZP, 50_000]]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET status = "locked", component_snapshot_json = "{}",
                    component_snapshot_hash = UNHEX(SHA2("{}", 256))
              WHERE supplier_id = ? AND external_id = ?'
        )->execute([$this->supplierId, $this->attendanceId('A', self::BOZP)]);
        $cell = $this->item('A')['components'][self::BOZP];
        self::assertFalse($cell['entry_available']);

        $failures = $this->save('A', [self::BOZP => ['amount_minor' => 70_000, 'row_version' => $cell['row_version']]]);

        self::assertCount(1, $failures);
        self::assertStringContainsString('opravnou revizí', $failures[0]['message']);
        self::assertSame(1, $this->countInputs('A', active: true));
    }

    /**
     * Opakovaný import ruční přepis NEPŘEPÍŠE a ohlásí ho. Novou hodnotu si
     * ale zapamatuje: „Vrátit na hodnotu z importu" vrátí poslední dávku.
     */
    public function testReimportKeepsManualOverrideAndReportsIt(): void
    {
        $this->importAttendance([['A', self::BOZP, 50_000]]);
        $cell = $this->item('A')['components'][self::BOZP];
        self::assertSame([], $this->save('A', [self::BOZP => ['amount_minor' => 70_000, 'row_version' => $cell['row_version']]]));

        $result = $this->importAttendance([['A', self::BOZP, 60_000]]);

        self::assertSame(1, $result['overridden_count']);
        self::assertSame(0, $result['updated_count']);
        self::assertSame(0, $result['accepted_count']);
        self::assertSame(1, $result['duplicate_count']);
        self::assertContains(
            '1 hodnota je ručně přepsaná v rychlém měsíčním vstupu; import ji nepřepsal.',
            $result['notices'],
        );
        $override = $this->item('A')['components'][self::BOZP];
        self::assertSame('override', $override['mode']);
        self::assertSame(70_000, $override['amount_minor']);
        self::assertSame(60_000, $override['override_of']['amount_minor']);
        self::assertSame([70_000], $this->runAmountsIncludingDrafts('A'));

        self::assertSame([], $this->save('A', [self::BOZP => ['revert' => true, 'row_version' => $override['row_version']]]));
        self::assertSame(60_000, $this->item('A')['components'][self::BOZP]['amount_minor']);
    }

    public function testReimportUpdatesChangedDraftWithoutOverride(): void
    {
        $this->importAttendance([['A', self::BOZP, 50_000]]);

        $same = $this->importAttendance([['A', self::BOZP, 50_000], ['B', self::BOZP, 1_000]]);
        self::assertSame(1, $same['duplicate_count'], 'Stejná hodnota je duplicita.');
        self::assertSame(0, $same['updated_count']);

        $changed = $this->importAttendance([['A', self::BOZP, 55_000]]);
        self::assertSame(1, $changed['updated_count']);
        self::assertSame(0, $changed['accepted_count'], 'Aktualizace není nově založený vstup.');
        self::assertSame('accepted', $changed['status']);
        self::assertSame(55_000, (int) $this->activeInput('A', $this->attendanceId('A', self::BOZP))['amount_minor']);
        self::assertSame(1, $this->countInputs('A', active: true), 'Změna nezaložila druhý vstup.');
    }

    public function testReimportNeverRewritesApprovedValue(): void
    {
        $this->importAttendance([['A', self::BOZP, 50_000]]);
        $import = $this->activeInput('A', $this->attendanceId('A', self::BOZP));
        $this->inputs->approve($this->supplierId, (int) $import['id'], (int) $import['row_version'], $this->userId);

        $result = $this->importAttendance([['A', self::BOZP, 55_000]]);

        self::assertSame(0, $result['updated_count']);
        self::assertSame(1, $result['duplicate_count']);
        self::assertSame('changed_after_approval', $result['rows'][0]['errors'][0]['code']);
        self::assertSame(50_000, (int) $this->activeInput('A', $this->attendanceId('A', self::BOZP))['amount_minor']);
    }

    /** Hranice mezi firmami: cizí vztah ani cizí data se do měsíce nedostanou. */
    public function testTenantIsolation(): void
    {
        $foreignEmployment = $this->employment($this->otherSupplierId, 'Cizí Syntetická', 'CIZI-DYN');
        $this->component($this->otherSupplierId, self::BOZP, 'premium');
        $this->importAttendance([['A', self::BOZP, 50_000]]);

        $foreignMonth = $this->quickInputs->month($this->otherSupplierId, self::PERIOD);
        self::assertSame(0, $foreignMonth['totals']['components'][self::BOZP]['amount_minor'] ?? 0);
        foreach ($foreignMonth['columns'] as $column) {
            self::assertSame(0, $column['rows_with_value']);
        }

        $failures = [];
        $this->quickInputs->save(
            $this->supplierId,
            self::PERIOD,
            [[
                'employment_id' => $foreignEmployment,
                'employment_row_version' => 1,
                'base_amount_minor' => null,
                'overtime_mode' => 'amount',
                'overtime_hours_milli' => null,
                'overtime_amount_minor' => 0,
                'overtime_average_snapshot_id' => null,
                'overtime_average_snapshot_version' => null,
                'bonus_amount_minor' => 0,
                'surcharges' => [],
                'components' => [self::BOZP => [
                    'amount_minor' => 1,
                    'quantity_milliunits' => null,
                    'quantity_provided' => false,
                    'row_version' => null,
                    'revert' => false,
                ]],
                'versions' => ['base' => null, 'overtime' => null, 'bonus' => null, 'surcharges' => []],
            ]],
            $this->userId,
            autoApprove: true,
            failures: $failures,
        );
        self::assertCount(1, $failures);
        self::assertSame('row', $failures[0]['field']);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payroll_inputs WHERE employment_id = ?');
        $stmt->execute([$foreignEmployment]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    /**
     * @param list<array{0:string,1:string,2:int}> $rows
     * @return array<string,mixed>
     */
    private function importAttendance(array $rows): array
    {
        $lines = ['employment_id;employment_code;component_code;amount_minor;external_id'];
        foreach ($rows as [$key, $code, $amount]) {
            $lines[] = implode(';', [
                (string) $this->employments[$key],
                "SYN-DYN-{$key}",
                $code,
                (string) $amount,
                $this->attendanceId($key, $code),
            ]);
        }

        return $this->imports->apply(
            $this->supplierId,
            self::PERIOD,
            'csv',
            'dochazka-' . self::PERIOD . '.csv',
            implode("\n", $lines),
            $this->userId,
        );
    }

    private function attendanceId(string $key, string $code): string
    {
        return 'attendance:' . self::PERIOD . ':' . $this->employments[$key] . ':' . $code;
    }

    /**
     * @param array<string,array<string,mixed>> $components
     * @return list<array<string,mixed>>
     */
    private function save(string $key, array $components, bool $autoApprove = true): array
    {
        $item = $this->item($key);
        $normalized = [];
        foreach ($components as $code => $entry) {
            $normalized[$code] = [
                'amount_minor' => $entry['amount_minor'] ?? null,
                'quantity_milliunits' => null,
                'quantity_provided' => false,
                'row_version' => $entry['row_version'] ?? null,
                'revert' => ($entry['revert'] ?? false) === true,
            ];
        }
        $failures = [];
        $this->quickInputs->save(
            $this->supplierId,
            self::PERIOD,
            [[
                'employment_id' => $this->employments[$key],
                'employment_row_version' => $item['employment_row_version'],
                'base_amount_minor' => null,
                'overtime_mode' => 'amount',
                'overtime_hours_milli' => null,
                'overtime_amount_minor' => $item['overtime_amount_minor'],
                'overtime_average_snapshot_id' => null,
                'overtime_average_snapshot_version' => null,
                'bonus_amount_minor' => $item['bonus_amount_minor'],
                'surcharges' => [],
                'components' => $normalized,
                'versions' => ['base' => null, 'overtime' => null, 'bonus' => null, 'surcharges' => []],
            ]],
            $this->userId,
            autoApprove: $autoApprove,
            failures: $failures,
        );

        return $failures ?? [];
    }

    /** @return array<string,mixed> */
    private function item(string $key): array
    {
        $month = $this->quickInputs->month(
            $this->supplierId,
            self::PERIOD,
            PayrollQuickInputRepository::LIST_MAX_LIMIT,
            0,
            $this->employments[$key],
        );
        self::assertCount(1, $month['items']);

        return PayrollTimeValue::row($month['items'][0], 'item');
    }

    /** Částky, které si výpočet běhu vezme (jen schválené a uzamčené vstupy). @return list<int> */
    private function runAmounts(string $key): array
    {
        $grouped = $this->loader->inputs($this->supplierId, [$this->employments[$key]], self::PERIOD_START);
        $amounts = array_map(
            static fn (array $input): int => (int) $input['amount_minor'],
            $grouped[$this->employments[$key]] ?? [],
        );
        sort($amounts);

        return $amounts;
    }

    /** Všechny živé vstupy vztahu (koncepty i schválené). @return list<int> */
    private function runAmountsIncludingDrafts(string $key): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT amount_minor FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND status <> "cancelled"
              ORDER BY amount_minor'
        );
        $stmt->execute([$this->supplierId, $this->employments[$key], self::PERIOD_START]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    private function activeInput(string $key, string $externalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND external_id = ?
                AND status <> "cancelled"'
        );
        $stmt->execute([$this->supplierId, $this->employments[$key], $externalId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, "Vstup {$externalId} má existovat právě jednou.");

        return $rows[0];
    }

    /** @return array<string,mixed> */
    private function inputById(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM payroll_inputs WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function countInputs(string $key, bool $active = false): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ? AND employment_id = ?'
            . ($active ? ' AND status <> "cancelled"' : '')
        );
        $stmt->execute([$this->supplierId, $this->employments[$key]]);

        return (int) $stmt->fetchColumn();
    }

    private function component(
        int $supplierId,
        string $code,
        string $kind,
        string $taxTreatment = 'included',
        string $valueKind = 'monetary',
    ): void {
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
             VALUES (?, ?, ?, ?, ?, "one_off", ?,
                     "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "521", "331", "2026-01-01", 1)'
        )->execute([$supplierId, $code, "Syntetická {$code}", $kind, $valueKind, $taxTreatment]);
    }

    private function employment(int $supplierId, string $name, string $code): int
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
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$supplierId, $employeeId, $code]);

        return (int) $pdo->lastInsertId();
    }

    private function firstId(string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná tabulka.');
        }
        $stmt = $this->db->pdo()->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }

        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
