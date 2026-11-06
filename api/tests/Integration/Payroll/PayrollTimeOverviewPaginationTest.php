<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `GET /payroll/time/month` nesmí postavit celý měsíc firmy naráz.
 *
 * Přehled docházky staví na KAŽDÝ pracovní vztah vlastní fond kalendáře,
 * náhled JMHZ a stav limitů přesčasu. U firmy se stovkou lidí to nebyl jeden
 * pomalý dotaz, ale stovka sad dotazů v jedné odpovědi — a nic to nestropovalo.
 *
 * Test hlídá, že zúžení „jen nedokončené" i stránkování padne PŘED stavbou
 * řádků (jinak by strop nic neušetřil), že `total` počítá všechny odpovídající
 * vztahy a že poslední stránka nepřeteče.
 */
#[Group('integration')]
final class PayrollTimeOverviewPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';

    private Connection $db;
    private PayrollTimeService $time;
    private int $supplierId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->time = $container->get(PayrollTimeService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_employments', 'payroll_work_calendars'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
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

    /** Strop je tvrdý a `total` počítá všechny vztahy měsíce. */
    public function testCapCannotBeLiftedByAParameter(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->seedEmployment();
        }

        $page = $this->time->overview($this->supplierId, self::PERIOD, false, 2, 0);

        self::assertCount(2, $page['items'], 'Limit musí přehled skutečně omezit.');
        self::assertSame(6, $page['total'], 'Total je počet všech vztahů, ne velikost stránky.');
        self::assertSame(2, $page['limit']);
        self::assertSame(0, $page['offset']);

        $overLimit = $this->time->overview($this->supplierId, self::PERIOD, false, 10_000, 0);
        self::assertSame(
            PayrollTimeService::LIST_MAX_LIMIT,
            $overLimit['limit'],
            'Strop nejde obejít vyšším limitem.',
        );
    }

    /** Druhá stránka musí vrátit jiné vztahy a poslední nesmí přetéct. */
    public function testOffsetShiftsThePage(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->seedEmployment();
        }

        $first = $this->time->overview($this->supplierId, self::PERIOD, false, 2, 0);
        $second = $this->time->overview($this->supplierId, self::PERIOD, false, 2, 2);
        $last = $this->time->overview($this->supplierId, self::PERIOD, false, 2, 4);

        self::assertCount(2, $first['items']);
        self::assertCount(2, $second['items']);
        self::assertCount(1, $last['items'], 'Poslední stránka nesmí přetéct.');
        self::assertSame(5, $second['total'], 'Total se posunem stránky nemění.');
        self::assertSame(
            [],
            array_intersect($this->ids($first), $this->ids($second)),
            'Stránky se nesmí překrývat.',
        );
        self::assertSame(
            [],
            $this->time->overview($this->supplierId, self::PERIOD, false, 2, 5)['items'],
            'Za koncem seznamu je prázdno, ne zopakovaná poslední stránka.',
        );
    }

    /**
     * Zúžení „jen nedokončené" musí zúžit stránku i `total` shodně.
     *
     * Kdyby se filtrovalo až nad postavenou stránkou, hlásil by přehled počet
     * všech vztahů a stránky by se náhodně vyprazdňovaly.
     */
    public function testIncompleteFilterNarrowsBothPageAndTotal(): void
    {
        $withCalendar = [];
        for ($i = 0; $i < 3; ++$i) {
            $withCalendar[] = $this->seedEmployment(true);
        }
        for ($i = 0; $i < 4; ++$i) {
            $this->seedEmployment();
        }

        $all = $this->time->overview($this->supplierId, self::PERIOD, false, 100, 0);
        self::assertSame(7, $all['total']);

        $incomplete = $this->time->overview($this->supplierId, self::PERIOD, true, 100, 0);
        self::assertSame(4, $incomplete['total'], 'Vztah s kalendářem není nedokončený.');
        self::assertCount(4, $incomplete['items']);
        self::assertSame(
            [],
            array_intersect($withCalendar, $this->ids($incomplete)),
            'Vztahy s kalendářem se do zúžení nesmí dostat.',
        );
        foreach ($incomplete['items'] as $item) {
            self::assertIsArray($item);
            self::assertTrue(
                $item['summary']['incomplete'],
                'Zúžený přehled smí obsahovat jen nedokončené řádky.',
            );
        }

        $secondPage = $this->time->overview($this->supplierId, self::PERIOD, true, 2, 2);
        self::assertCount(2, $secondPage['items']);
        self::assertSame(4, $secondPage['total'], 'Total zúžení se posunem stránky nemění.');
    }

    /**
     * Zúžení na jeden vztah musí platit i pro vztah, který na první stránce není.
     *
     * Dokud filtroval prohlížeč nad načtenou stránkou, vypadalo zúžení na
     * vztah z druhé strany jako prázdný výsledek: lišta zmizela a seznam se
     * tiše nezúžil. Test proto sáhne na POSLEDNÍ vztah v pořadí a ptá se na
     * první stránku o dvou řádcích — bez serverového filtru by tam nebyl.
     */
    public function testEmploymentNarrowingReachesBeyondTheFirstPage(): void
    {
        $seeded = [];
        for ($i = 0; $i < 5; ++$i) {
            $seeded[] = $this->seedEmployment();
        }
        $all = $this->time->overview($this->supplierId, self::PERIOD, false, 100, 0);
        $ordered = $this->ids($all);
        $offPageId = $ordered[count($ordered) - 1];

        $firstPage = $this->time->overview($this->supplierId, self::PERIOD, false, 2, 0);
        self::assertNotContains(
            $offPageId,
            $this->ids($firstPage),
            'Předpoklad testu: hledaný vztah na první stránce být nesmí.',
        );

        $narrowed = $this->time->overview(
            $this->supplierId,
            self::PERIOD,
            false,
            2,
            0,
            $offPageId,
        );

        self::assertSame([$offPageId], $this->ids($narrowed), 'Zúžení musí vrátit hledaný vztah.');
        self::assertSame(1, $narrowed['total'], 'Total musí být zúžený stejně jako stránka.');
        self::assertSame($offPageId, $narrowed['employment_id'], 'Odpověď hlásí uplatněné zúžení.');
        self::assertContains($offPageId, $seeded);
    }

    /**
     * Cizí ani neexistující vztah nesmí vrátit celý seznam.
     *
     * Prázdný výsledek je poznatelný stav (`total = 0` s ohlášeným zúžením);
     * tiché zobrazení všech je lež, ze které uživatel usoudí, že filtr nezabral.
     */
    public function testUnknownEmploymentNarrowsToNothingInsteadOfEverything(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->seedEmployment();
        }

        $page = $this->time->overview(
            $this->supplierId,
            self::PERIOD,
            false,
            100,
            0,
            999_000_111,
        );

        self::assertSame([], $page['items']);
        self::assertSame(0, $page['total']);
        self::assertSame(999_000_111, $page['employment_id']);
    }

    /**
     * @param array<string,mixed> $page
     * @return list<int>
     */
    private function ids(array $page): array
    {
        $ids = [];
        foreach ((array) $page['items'] as $item) {
            self::assertIsArray($item);
            $ids[] = (int) $item['employment']['id'];
        }

        return $ids;
    }

    private function seedEmployment(bool $withCalendar = false): int
    {
        $pdo = $this->db->pdo();
        $ordinal = ++$this->sequence;
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 30000, 0, 1)'
        )->execute([$this->supplierId, sprintf('Synteticka Osoba %02d', $ordinal)]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01")'
        )->execute([$this->supplierId, $employeeId, sprintf('ZAM-%02d', $ordinal)]);
        $employmentId = (int) $pdo->lastInsertId();

        if ($withCalendar) {
            $pdo->prepare(
                'INSERT INTO payroll_work_calendars
                    (supplier_id, employment_id, name, timezone_name, schedule_type,
                     week_pattern, weekly_minutes, valid_from, valid_to)
                 VALUES (?, ?, "Standard", "Europe/Prague", "regular",
                         ?, 2400, "2026-01-01", NULL)'
            )->execute([
                $this->supplierId,
                $employmentId,
                '{"1":480,"2":480,"3":480,"4":480,"5":480,"6":0,"7":0}',
            ]);
        }

        return $employmentId;
    }
}
