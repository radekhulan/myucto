<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přehled oznamovacích povinností za období — podklad obrazovky MZ-23.
 *
 * Testuje se to, co obrazovka slibuje: že filtr i stránka jdou na server,
 * že se povinnost bez doloženého kódu změny pojmenuje místo odhadu, a že se
 * vztah, u kterého povinnost odvodit nelze, nevypustí do prázdna.
 */
#[Group('integration')]
final class PayrollHealthNotificationPeriodTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private HealthInsuranceSubmissionService $service;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_employments',
            'payroll_employees',
            'payroll_absences',
            'payroll_person_health_coverage_history',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $service = $container->get(HealthInsuranceSubmissionService::class);
        if (!$service instanceof HealthInsuranceSubmissionService) {
            throw new \RuntimeException('Služba podání není dostupná.');
        }
        $this->service = $service;

        $pdo = $this->db->pdo();
        $statement = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        if ($statement === false) {
            throw new \RuntimeException('Výchozí firmu nelze načíst.');
        }
        $sourceSupplierId = (int) $statement->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->employee($pdo, 'Syntetická osoba ZP období');
        $this->employmentId = $this->employment(
            $pdo,
            $this->employeeId,
            'ZPP-1',
            '2026-06-03',
        );
        $this->coverage($pdo, $this->employeeId, '111', '2026-01-01');
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

    public function testPeriodOverviewReturnsTheStartWithAnEightDayDeadline(): void
    {
        $page = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
        );

        self::assertSame('2026-06', $page['period']);
        self::assertSame(1, $page['total']);
        self::assertCount(1, $page['items']);
        $item = $page['items'][0];
        self::assertSame('employment_start', $item['kind']);
        self::assertSame('111', $item['insurer_code']);
        self::assertSame('2026-06-03', $item['occurred_on']);
        self::assertTrue($item['reported_by_employer']);
        self::assertSame('2026-06-11', $item['deadline']['due_on']);
        // Kód „P" dokládá anotace připnutého XSD — nesmí být odhadnutý.
        self::assertTrue($item['change_code']['documented']);
        self::assertSame('P', $item['change_code']['code']);
        self::assertFalse($item['dispatch']['supported']);
        self::assertSame(1, $page['summary']['reported_by_employer']);
    }

    /**
     * Skutečnost mimo období se nevrací, i když vztah v období trvá — jinak
     * by přehled za červen připomínal lhůtu, která uplynula v březnu.
     */
    public function testFactsOutsideThePeriodAreNotReported(): void
    {
        $page = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-07',
        );

        self::assertSame(0, $page['total']);
        self::assertSame([], $page['items']);
    }

    /**
     * Mateřská a rodičovská se odvodí ze schválených absencí. Bez toho by se
     * doložené kódy „M" a „U" v přehledu nikdy neobjevily.
     */
    public function testApprovedMaternityLeaveBecomesADutyWithTheDocumentedCode(): void
    {
        $this->absence($this->employmentId, 'ppm', '2026-06-15', '2026-06-30', 'approved');

        $page = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
            ['kind' => 'maternity_leave_start'],
        );

        self::assertSame(1, $page['total']);
        $item = $page['items'][0];
        self::assertSame('2026-06-15', $item['occurred_on']);
        self::assertSame('M', $item['change_code']['code']);
        // Souhrnná lhůta k 20. dni následujícího měsíce, ne osm dnů.
        self::assertSame('2026-07-20', $item['deadline']['due_on']);
    }

    /**
     * Absence, kterou zaměstnavatel teprve zvažuje, není skutečnost. Oznámit
     * ji znamená podat větu o něčem, co nenastalo.
     */
    public function testRequestedAbsenceDoesNotCreateADuty(): void
    {
        $this->absence($this->employmentId, 'ppm', '2026-06-15', '2026-06-30', 'requested');

        $page = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
            ['kind' => 'maternity_leave_start'],
        );

        self::assertSame(0, $page['total']);
    }

    /**
     * Přestup se oznamuje OBĚMA pojišťovnám a ani u jedné z toho neplyne kód
     * změny — schéma ho váže na směr přestupu, který doména nezná.
     */
    public function testInsurerChangeYieldsTwoDutiesAndNamesTheMissingCode(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_person_health_coverage_history
                SET effective_to = "2026-06-09"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->coverage($pdo, $this->employeeId, '205', '2026-06-10');

        $page = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
            ['kind' => 'insurer_change'],
        );

        self::assertSame(2, $page['total']);
        $codes = array_column($page['items'], 'insurer_code');
        sort($codes);
        self::assertSame(['111', '205'], $codes);
        foreach ($page['items'] as $item) {
            self::assertFalse($item['change_code']['documented']);
            self::assertNull($item['change_code']['code']);
            self::assertNotNull(
                $item['change_code']['reason'],
                'Nedoložený kód musí mít KONKRÉTNÍ důvod, ne prázdno.',
            );
            self::assertStringContainsString(
                'přestup',
                mb_strtolower($item['change_code']['reason']),
            );
        }
    }

    /** Filtr i stránka platí na serveru — `total` popisuje filtrovaný seznam. */
    public function testFilterAndPagingAreBothServerSide(): void
    {
        $this->absence($this->employmentId, 'ppm', '2026-06-15', '2026-06-30', 'approved');
        $second = $this->employee($pdo = $this->db->pdo(), 'Druhá osoba ZP');
        $this->employment($pdo, $second, 'ZPP-2', '2026-06-04');
        $this->coverage($pdo, $second, '205', '2026-01-01');

        $all = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
        );
        self::assertSame(4, $all['total']);

        $filtered = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
            ['insurer_code' => '205'],
        );
        self::assertSame(1, $filtered['total']);
        self::assertSame('205', $filtered['items'][0]['insurer_code']);

        // Stránkuje se AŽ nad filtrovaným seznamem, jinak by `total` popisoval
        // jiný seznam, než uživatel vidí.
        $firstPage = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
            [],
            2,
            0,
        );
        $secondPage = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
            [],
            2,
            2,
        );
        self::assertSame(4, $firstPage['total']);
        self::assertCount(2, $firstPage['items']);
        self::assertCount(2, $secondPage['items']);
        self::assertNotSame(
            $firstPage['items'][0]['id'],
            $secondPage['items'][0]['id'],
        );
    }

    /**
     * Souhrn popisuje CELÉ období, `total` filtrovaný seznam. Kdyby souhrn
     * respektoval filtr, dal by se zúžením filtru schovat propadlý termín —
     * a to je přesně ta informace, kvůli které se na obrazovku chodí.
     */
    public function testSummaryDescribesTheWholePeriodWhileTotalFollowsTheFilter(): void
    {
        $this->absence($this->employmentId, 'ppm', '2026-06-15', '2026-06-30', 'approved');

        $unfiltered = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
        );
        $filtered = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
            ['kind' => 'maternity_leave_start'],
        );

        self::assertSame(3, $unfiltered['total']);
        self::assertSame(1, $filtered['total']);
        // Souhrn se filtrem NEZMĚNIL.
        self::assertSame($unfiltered['summary'], $filtered['summary']);
        self::assertSame(3, $filtered['summary']['total']);
    }

    /**
     * Vztah bez evidované pojišťovny se z přehledu NEVYPOUŠTÍ — oznámení by
     * nemělo komu odejít, a to je vada k opravě, ne prázdno v seznamu.
     */
    public function testEmploymentWithoutAnInsurerIsNamedInsteadOfDropped(): void
    {
        $pdo = $this->db->pdo();
        $orphan = $this->employee($pdo, 'Osoba bez pojišťovny');
        $this->employment($pdo, $orphan, 'ZPP-3', '2026-06-05');

        $page = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
        );

        self::assertCount(1, $page['unresolved_employments']);
        self::assertSame(
            'zp_insurer_code_missing',
            $page['unresolved_employments'][0]['reason_code'],
        );
        self::assertSame(
            'Osoba bez pojišťovny',
            $page['unresolved_employments'][0]['full_name'],
        );
        // Zbytek přehledu se kvůli jedné vadě nezahazuje.
        self::assertSame(1, $page['total']);
    }

    /**
     * Detail jednoho vztahu a přehled za období musí nad týmiž daty vydat
     * tutéž povinnost — jinak by účetní podle toho, kam klikne, viděla jinou
     * pravdu.
     */
    public function testSingleEmploymentEndpointAgreesWithThePeriodOverview(): void
    {
        $this->absence($this->employmentId, 'ppm', '2026-06-15', '2026-06-30', 'approved');

        $single = $this->service->duties(
            $this->supplierId,
            $this->employmentId,
            '2026-06-30',
        );
        $period = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
        );

        $singleKinds = array_column($single, 'kind');
        $periodKinds = array_column($period['items'], 'kind');
        sort($singleKinds);
        sort($periodKinds);
        self::assertSame($singleKinds, $periodKinds);
        self::assertContains('maternity_leave_start', $singleKinds);
    }

    public function testMalformedPeriodIsRefusedByName(): void
    {
        try {
            $this->service->dutiesForPeriod(
                $this->supplierId,
                'production',
                '2026-13',
            );
            self::fail('Neplatné období nesmí projít.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_period_invalid', $e->errorCode);
        }
    }

    /**
     * Zrušený nástup není pojištěnec. Vztah ve stavu `no_show` do práce nikdy
     * nenastoupil, takže přihlašovat ho k pojištění by znamenalo oznámit
     * pojišťovně člověka, který u firmy nikdy nepracoval.
     */
    public function testCancelledStartProducesNoDuty(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employments SET status = "no_show"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $page = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
        );

        self::assertSame(0, $page['total']);
        self::assertSame([], $page['items']);
    }

    /** Archivovaný vztah je vyřazený z evidence a povinnosti nevyrábí. */
    public function testArchivedEmploymentProducesNoDuty(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employments SET status = "archived"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $page = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
        );

        self::assertSame(0, $page['total']);
        self::assertSame([], $page['items']);
    }

    /**
     * Oznamuje se SKUTEČNÝ den nástupu. Dokud se bral plánovaný, dostala
     * pojišťovna datum, které se nestalo, kdykoli se nástup posunul — a s ním
     * i lhůtu počítanou od nesprávného dne.
     */
    public function testActualStartDateWinsOverThePlannedOne(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employments SET actual_start_date = "2026-06-15"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);

        $page = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-06',
        );

        self::assertSame(1, $page['total']);
        self::assertSame('2026-06-15', $page['items'][0]['occurred_on']);
        self::assertSame('2026-06-23', $page['items'][0]['deadline']['due_on']);
    }

    // --- fixtures --------------------------------------------------------

    private function employee(PDO $pdo, string $name): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 10000, 0, 1)',
        )->execute([$this->supplierId, $name]);

        return (int) $pdo->lastInsertId();
    }

    private function employment(
        PDO $pdo,
        int $employeeId,
        string $code,
        string $startDate,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 is_primary, start_date)
             VALUES (?, ?, ?, "employment", "active", 1, ?)',
        )->execute([$this->supplierId, $employeeId, $code, $startDate]);

        return (int) $pdo->lastInsertId();
    }

    private function coverage(
        PDO $pdo,
        int $employeeId,
        string $insurerCode,
        string $effectiveFrom,
    ): void {
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", ?,
                     "synteticky-doklad", ?)',
        )->execute([
            $this->supplierId,
            $employeeId,
            $insurerCode,
            $effectiveFrom,
        ]);
    }

    private function absence(
        int $employmentId,
        string $type,
        string $from,
        string $to,
        string $status,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 status)
             VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $employmentId,
            $type,
            $from,
            $to,
            $status,
        ]);
    }
}
