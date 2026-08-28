<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * § 6 odst. 9 písm. b) a i) ZDP — příspěvek na stravování a přechodné ubytování.
 *
 * Obě složky zůstaly po migraci 1590 na `manual_review`, protože limit je za
 * SMĚNU resp. za MĚSÍC a aplikace ho neuměla rozpadnout. Následek: na
 * nejběžnějším benefitu vůbec padlo schválení každého měsíce, ve kterém ho
 * firma poskytuje.
 *
 * Test drží doslovné znění účinné pro rok 2026, včetně toho, kde je nerovnost
 * ostrá a kde ne:
 *
 *  - „pokud během této směny zaměstnanec vykonával práci ALESPOŇ 3 hodiny" —
 *    NEOSTŘE, přesně 180 minut nárok zakládá,
 *  - „pokud její délka v úhrnu s přestávkou … je DELŠÍ NEŽ 11 hodin" — OSTŘE,
 *    přesně 11 hodin druhý příspěvek nezakládá, a měří se HRUBÝ interval směny,
 *  - „v úhrnu DO VÝŠE 70 % horní hranice stravného" — NEOSTŘE, částka rovná
 *    stropu je celá osvobozená a zdaňuje se až koruna nad ním,
 *  - „MAXIMÁLNĚ DO VÝŠE 3 500 Kč MĚSÍČNĚ" — NEOSTŘE a za kalendářní měsíc, do
 *    dalšího měsíce se nepřenáší nic.
 */
#[Group('integration')]
final class PayrollMealShiftExemptionTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** 70 % ze 185,00 Kč, tedy horní hranice stravného za cestu 5 až 12 hodin. */
    private const MEAL_LIMIT_MINOR = 12_950;

    /** § 6 odst. 9 písm. i) ZDP — 3 500 Kč měsíčně. */
    private const ACCOMMODATION_LIMIT_MINOR = 350_000;

    private const PERIOD = '2026-07';

    private const PERIOD_START = '2026-07-01';

    private Connection $db;
    private PayrollComponentsAction $components;
    private PayrollEmploymentAction $employments;
    private PayrollInputsAction $inputs;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $components = $container->get(PayrollComponentsAction::class);
        $employments = $container->get(PayrollEmploymentAction::class);
        $inputs = $container->get(PayrollInputsAction::class);
        if (!$db instanceof Connection
            || !$components instanceof PayrollComponentsAction
            || !$employments instanceof PayrollEmploymentAction
            || !$inputs instanceof PayrollInputsAction
        ) {
            throw new \RuntimeException('Payroll služby nejsou dostupné.');
        }
        $this->db = $db;
        if (!$this->db->hasTable('payroll_shifts')) {
            $this->markTestSkipped('Migrace docházky neproběhly.');
        }
        $this->components = $components;
        $this->employments = $employments;
        $this->inputs = $inputs;

        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická zaměstnankyně", "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, "SYN-STRAVNE", "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
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

    /**
     * „vykonával práci ALESPOŇ 3 hodiny" — nerovnost je NEOSTRÁ, takže přesně
     * 180 odpracovaných minut nárok zakládá. O minutu míň už ne.
     */
    public function testExactlyThreeWorkedHoursGrantOneEntitlement(): void
    {
        $this->approveAttendanceMonth();
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 30);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 09:30', 30);

        $component = $this->createMealComponent('STRAVNE_HRANICE');
        $preview = $this->basket($this->preview($component, self::MEAL_LIMIT_MINOR));

        self::assertSame('meal_per_shift', $preview['basket']);
        self::assertSame(1, $preview['shift_entitlements']);
        self::assertSame(self::MEAL_LIMIT_MINOR, $preview['limit_minor']);
        self::assertSame(self::MEAL_LIMIT_MINOR, $preview['exempt_minor']);
        self::assertSame(0, $preview['taxable_minor']);
    }

    public function testOneMinuteShortOfThreeWorkedHoursGrantsNothing(): void
    {
        $this->approveAttendanceMonth();
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 30);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 09:29', 30);

        $component = $this->createMealComponent('STRAVNE_KRATKA');
        $preview = $this->basket($this->preview($component, self::MEAL_LIMIT_MINOR));

        self::assertSame(0, $preview['shift_entitlements']);
        self::assertSame(0, $preview['limit_minor']);
        self::assertSame(0, $preview['exempt_minor']);
        self::assertSame(self::MEAL_LIMIT_MINOR, $preview['taxable_minor']);
    }

    /**
     * „pokud její délka v úhrnu s přestávkou … je DELŠÍ NEŽ 11 hodin" — ostře.
     * Směna přesně jedenáctihodinová druhý příspěvek nezakládá, o minutu delší ano.
     */
    public function testShiftLongerThanElevenHoursGrantsTheSecondContribution(): void
    {
        $this->approveAttendanceMonth();
        $this->seedShift('2026-07-06 06:00', '2026-07-06 17:00', 30);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 12:00', 0);

        $exactlyEleven = $this->createMealComponent('STRAVNE_11H');
        self::assertSame(
            1,
            $this->basket($this->preview($exactlyEleven, 100))['shift_entitlements'],
        );

        $this->seedShift('2026-07-07 06:00', '2026-07-07 17:01', 30);
        $this->seedWorkedTime('2026-07-07 06:00', '2026-07-07 12:00', 0);

        $preview = $this->basket($this->preview(
            $exactlyEleven,
            3 * (self::MEAL_LIMIT_MINOR + 100),
        ));
        // Dvě směny: první dá jeden nárok, druhá dva (základní a další příspěvek).
        self::assertSame(3, $preview['shift_entitlements']);
        self::assertSame(3 * self::MEAL_LIMIT_MINOR, $preview['limit_minor']);
        self::assertSame(300, $preview['taxable_minor']);
    }

    /**
     * „v úhrnu DO VÝŠE" — částka rovná stropu je celá osvobozená, zdaňuje se až
     * koruna (tady haléř) nad ním.
     */
    public function testContributionOneUnitOverTheLimitTaxesOnlyTheExcess(): void
    {
        $this->approveAttendanceMonth();
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 30);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 12:00', 0);

        $component = $this->createMealComponent('STRAVNE_PRESAH');
        self::assertSame(
            200,
            $this->approve($component, self::MEAL_LIMIT_MINOR + 1, 'stravne-presah')
                ->getStatusCode(),
        );
        self::assertSame(
            ['meal_per_shift', self::MEAL_LIMIT_MINOR, 1],
            $this->frozenSplit('stravne-presah'),
        );
        self::assertEquals([
            'mode' => 'uniform_per_entitlement',
            'entitlement_count' => 1,
            'amount_per_entitlement_minor' => self::MEAL_LIMIT_MINOR + 1,
            'limit_per_entitlement_minor' => self::MEAL_LIMIT_MINOR,
            'exempt_per_entitlement_minor' => self::MEAL_LIMIT_MINOR,
            'taxable_per_entitlement_minor' => 1,
            'entitlement_basis' => 'shift',
            'entitlement_snapshot' => [
                'period_start' => self::PERIOD_START,
                'basis' => 'shift',
                'qualifying_count' => 1,
                'second_contribution_count' => 0,
                'count' => 1,
                'complete' => true,
                'missing' => [],
            ],
        ], $this->frozenAllocation('stravne-presah'));
    }

    public function testApprovedMealInputFreezesBasisAndEntitlementSnapshot(): void
    {
        $this->approveAttendanceMonth();
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 30);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 09:30', 30);

        $component = $this->createMealComponent('STRAVNE_SNAPSHOT_REZIMU');
        self::assertSame(
            200,
            $this->approve($component, self::MEAL_LIMIT_MINOR, 'stravne-snapshot-rezimu')
                ->getStatusCode(),
        );
        $allocation = $this->frozenAllocation('stravne-snapshot-rezimu');

        self::assertSame('shift', $allocation['entitlement_basis'] ?? null);
        self::assertEquals([
            'period_start' => self::PERIOD_START,
            'basis' => 'shift',
            'qualifying_count' => 1,
            'second_contribution_count' => 0,
            'count' => 1,
            'complete' => true,
            'missing' => [],
        ], $allocation['entitlement_snapshot'] ?? null);
    }

    public function testApprovedActiveMealInputLocksEmploymentBasis(): void
    {
        $this->approveAttendanceMonth();
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 30);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 09:30', 30);

        $component = $this->createMealComponent('STRAVNE_ZAMEK_REZIMU');
        self::assertSame(
            200,
            $this->approve($component, self::MEAL_LIMIT_MINOR, 'stravne-zamek-rezimu')
                ->getStatusCode(),
        );

        $response = $this->employments->setMealEntitlementBasis(
            $this->request(
                'PATCH',
                "/api/payroll/employments/{$this->employmentId}/meal-entitlement-basis",
            )->withParsedBody([
                'row_version' => 1,
                'meal_entitlement_basis' => 'calendar_day',
            ]),
            new Response(),
            ['id' => (string) $this->employmentId],
        );

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = PayrollTimeValue::row($this->json($response)['error'] ?? null, 'error');
        self::assertSame('meal_entitlement_basis_locked', $error['code'] ?? null);
        self::assertStringContainsString('schválený příspěvek', (string) ($error['message'] ?? ''));
        self::assertSame(
            'shift',
            $this->db->pdo()->query(
                'SELECT meal_entitlement_basis FROM payroll_employments'
                . ' WHERE id = ' . $this->employmentId,
            )->fetchColumn(),
        );
    }

    /**
     * Měsíc, ve kterém zaměstnanec neodpracoval jedinou směnu, ale docházka je
     * UZAVŘENÁ. To je doložený podklad — a znamená nula nároků, tedy celý
     * příspěvek zdanitelný. Blokovat schválení by tu bylo špatně: chybí nárok,
     * ne podklad.
     */
    public function testClosedMonthWithoutAWorkedShiftTaxesTheWholeContribution(): void
    {
        $this->approveAttendanceMonth();

        $component = $this->createMealComponent('STRAVNE_BEZ_SMEN');
        self::assertSame(
            200,
            $this->approve($component, self::MEAL_LIMIT_MINOR, 'stravne-bez-smen')
                ->getStatusCode(),
        );
        self::assertSame(
            ['meal_per_shift', 0, self::MEAL_LIMIT_MINOR],
            $this->frozenSplit('stravne-bez-smen'),
        );
    }

    /**
     * Fail-closed: neuzavřená docházka není podklad. Osvobodit odhadem by
     * znamenalo osvobodit i nadlimitní část — a hláška musí říct, CO chybí.
     */
    public function testOpenAttendanceMonthBlocksApprovalWithANamedReason(): void
    {
        $this->seedAttendanceMonth('open');
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 30);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 12:00', 0);

        $component = $this->createMealComponent('STRAVNE_OTEVRENO');
        $response = $this->approve($component, self::MEAL_LIMIT_MINOR, 'stravne-otevreno');

        self::assertSame(409, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('meal_shift_evidence_incomplete', $body['error']['code'] ?? null);
        self::assertStringContainsString('attendance_month_open', (string) ($body['error']['message'] ?? ''));
    }

    /**
     * Docházka za období neexistuje vůbec. Nula nároků by tu byla tvrzení, ne
     * zjištění — a tiše by zdanila celý příspěvek.
     */
    public function testCompletelyMissingAttendanceBlocksApproval(): void
    {
        $component = $this->createMealComponent('STRAVNE_BEZ_DOCHAZKY');
        $response = $this->approve($component, self::MEAL_LIMIT_MINOR, 'stravne-bez-doch');

        self::assertSame(409, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('meal_shift_evidence_incomplete', $body['error']['code'] ?? null);
        self::assertStringContainsString('attendance_missing', (string) ($body['error']['message'] ?? ''));
    }

    /**
     * „nevznikl mu během této směny nárok na stravné v rámci cestovních náhrad" —
     * směna s pracovní cestou, ze které stravné plyne, nárok nezakládá.
     */
    public function testShiftCoveredByABusinessTripMealAllowanceGrantsNothing(): void
    {
        $this->approveAttendanceMonth();
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 30);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 12:00', 0);
        $this->seedBusinessTrip('2026-07-06 05:00', '2026-07-06 15:00', 18_500);

        $component = $this->createMealComponent('STRAVNE_CESTA');
        $preview = $this->basket($this->preview($component, self::MEAL_LIMIT_MINOR));

        self::assertSame(0, $preview['shift_entitlements']);
        self::assertSame(self::MEAL_LIMIT_MINOR, $preview['taxable_minor']);
    }

    /**
     * Větev pro výkon práce nerozvržený na směny: jednotkou je KALENDÁŘNÍ DEN
     * a druhý příspěvek je podmíněn tím, že „během tohoto dne zaměstnanec
     * vykonával práci ALESPOŇ 11 hodin" — tedy NEOSTŘE a o odpracované době.
     */
    public function testWithoutPublishedShiftsTheCalendarDayBranchApplies(): void
    {
        $this->setMealBasis($this->employmentId, 'calendar_day');
        $this->approveAttendanceMonth();
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 09:00', 0);
        $this->seedWorkedTime('2026-07-07 06:00', '2026-07-07 17:00', 0);

        $component = $this->createMealComponent('STRAVNE_BEZ_ROZVRHU');
        $preview = $this->basket($this->preview($component, 300));

        // 6. 7. přesně 3 hodiny → jeden nárok. 7. 7. přesně 11 hodin → dva.
        self::assertSame(3, $preview['shift_entitlements']);
        self::assertSame(3 * self::MEAL_LIMIT_MINOR, $preview['limit_minor']);
    }

    /**
     * Podmínka „nevznikl mu … nárok na stravné v rámci cestovních náhrad" platí
     * i ve větvi kalendářních dnů — zákon ji tam opakuje, a to ještě přísněji
     * (vylučuje i stravné sjednané smlouvou).
     */
    public function testCalendarDayCoveredByABusinessTripMealAllowanceGrantsNothing(): void
    {
        $this->setMealBasis($this->employmentId, 'calendar_day');
        $this->approveAttendanceMonth();
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 14:00', 0);
        $this->seedWorkedTime('2026-07-07 06:00', '2026-07-07 14:00', 0);
        $this->seedBusinessTrip('2026-07-06 05:00', '2026-07-06 15:00', 18_500);

        $component = $this->createMealComponent('STRAVNE_DEN_CESTA');
        $preview = $this->basket($this->preview($component, 100));

        // Zůstane jediný den bez pracovní cesty.
        self::assertSame(1, $preview['shift_entitlements']);
    }

    public function testCalendarDayWorkIsSplitAtLocalMidnight(): void
    {
        $this->setMealBasis($this->employmentId, 'calendar_day');
        $this->approveAttendanceMonth();
        $this->seedWorkedTime('2026-07-06 22:00', '2026-07-07 01:00', 0);

        $component = $this->createMealComponent('STRAVNE_PULNOC');
        $preview = $this->basket($this->preview($component, self::MEAL_LIMIT_MINOR));

        self::assertSame(0, $preview['shift_entitlements']);
        self::assertSame(0, $preview['limit_minor']);
        self::assertSame(self::MEAL_LIMIT_MINOR, $preview['taxable_minor']);
    }

    public function testCalendarDayCrossMidnightBreakRequiresPreciseAllocation(): void
    {
        $this->setMealBasis($this->employmentId, 'calendar_day');
        $this->approveAttendanceMonth();
        $this->seedWorkedTime('2026-07-06 22:00', '2026-07-07 01:00', 30);

        $component = $this->createMealComponent('STRAVNE_PULNOC_PRESTAVKA');
        $preview = $this->preview($component, self::MEAL_LIMIT_MINOR);

        self::assertSame('manual_review', $preview['support_status']);
        self::assertStringContainsString(
            'calendar_day_break_allocation_missing',
            (string) $preview['blocker'],
        );
        self::assertNull($preview['exemption_basket']);
        $entitlement = PayrollTimeValue::row(
            $preview['meal_entitlement'] ?? null,
            'meal_entitlement',
        );
        self::assertFalse($entitlement['complete']);
        self::assertContains(
            'calendar_day_break_allocation_missing',
            $entitlement['missing'],
        );
    }

    public function testCalendarDayIncludesTargetMonthPartOfCrossBoundaryWork(): void
    {
        $this->setMealBasis($this->employmentId, 'calendar_day');
        $this->approveAttendanceMonth();
        $this->seedWorkedTime('2026-06-30 22:00', '2026-07-01 01:00', 0);
        $this->seedWorkedTime('2026-07-01 01:00', '2026-07-01 03:00', 0);

        $component = $this->createMealComponent('STRAVNE_HRANICE_MESICE');
        $preview = $this->basket($this->preview($component, self::MEAL_LIMIT_MINOR));

        self::assertSame(1, $preview['shift_entitlements']);
        self::assertSame(self::MEAL_LIMIT_MINOR, $preview['limit_minor']);
        self::assertSame(0, $preview['taxable_minor']);
    }

    public function testWorkedMinutesAreNeverBorrowedAcrossEmployments(): void
    {
        $otherEmploymentId = $this->createEmployment('SYN-STRAVNE-DPP');
        $this->setMealBasis($otherEmploymentId, 'calendar_day');
        $this->approveAttendanceMonth();
        $this->approveAttendanceMonth($otherEmploymentId);
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 0);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 08:59', 0);
        $this->seedWorkedTime(
            '2026-07-06 08:59',
            '2026-07-06 09:00',
            0,
            $otherEmploymentId,
        );

        $component = $this->createMealComponent('STRAVNE_VZTAHY_MINUTY');
        $preview = $this->basket($this->preview($component, self::MEAL_LIMIT_MINOR));

        self::assertSame(0, $preview['shift_entitlements']);
        self::assertSame(0, $preview['limit_minor']);
        self::assertSame('mixed', $preview['entitlement']['basis'] ?? null);
    }

    public function testMixedEmploymentsUseTheirOwnStatutoryBranch(): void
    {
        $otherEmploymentId = $this->createEmployment('SYN-STRAVNE-MIMO-SMENY');
        $this->setMealBasis($otherEmploymentId, 'calendar_day');
        $this->approveAttendanceMonth();
        $this->approveAttendanceMonth($otherEmploymentId);
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 0);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 09:00', 0);
        $this->seedWorkedTime(
            '2026-07-07 06:00',
            '2026-07-07 17:00',
            0,
            $otherEmploymentId,
        );

        $component = $this->createMealComponent('STRAVNE_VZTAHY_VETVE');
        $preview = $this->basket($this->preview($component, 3 * self::MEAL_LIMIT_MINOR));
        $entitlement = PayrollTimeValue::row(
            $preview['entitlement'] ?? null,
            'meal_entitlement',
        );

        self::assertSame('mixed', $entitlement['basis']);
        self::assertSame(2, $entitlement['qualifying_count']);
        self::assertSame(1, $entitlement['second_contribution_count']);
        self::assertSame(3, $entitlement['count']);
        self::assertSame(3, $preview['shift_entitlements']);
    }

    public function testShiftBasisDoesNotFallBackToCalendarDayWithoutSchedule(): void
    {
        $this->approveAttendanceMonth();
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 14:00', 0);

        $component = $this->createMealComponent('STRAVNE_CHYBI_ROZVRH');
        $preview = $this->preview($component, self::MEAL_LIMIT_MINOR);

        self::assertSame('manual_review', $preview['support_status']);
        self::assertStringContainsString('shift_schedule_missing', (string) $preview['blocker']);
        self::assertNull($preview['exemption_basket']);
    }

    public function testShiftBasisIgnoresPriorMonthWorkWhenCheckingJulySchedule(): void
    {
        $this->approveAttendanceMonth();
        $this->seedWorkedTime('2026-06-30 20:00', '2026-07-01 01:00', 0);

        $component = $this->createMealComponent('STRAVNE_SMENA_HRANICE_MESICE');
        $preview = $this->basket($this->preview($component, self::MEAL_LIMIT_MINOR));

        self::assertSame(0, $preview['shift_entitlements']);
        self::assertSame(0, $preview['limit_minor']);
        self::assertSame(self::MEAL_LIMIT_MINOR, $preview['taxable_minor']);
    }

    public function testActiveSecondEmploymentWithoutAttendanceFailsClosed(): void
    {
        $this->createEmployment('SYN-STRAVNE-BEZ-PODKLADU');
        $this->approveAttendanceMonth();
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 0);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 09:00', 0);

        $component = $this->createMealComponent('STRAVNE_DRUHY_VZTAH');
        $preview = $this->preview($component, self::MEAL_LIMIT_MINOR);

        self::assertSame('manual_review', $preview['support_status']);
        self::assertStringContainsString('attendance_missing', (string) $preview['blocker']);
        self::assertNull($preview['exemption_basket']);
    }

    public function testNonUniformMealAmountIsANamedPreviewBlocker(): void
    {
        $this->approveAttendanceMonth();
        foreach (['2026-07-06', '2026-07-07'] as $day) {
            $this->seedShift($day . ' 06:00', $day . ' 14:00', 0);
            $this->seedWorkedTime($day . ' 06:00', $day . ' 09:00', 0);
        }

        $component = $this->createMealComponent('STRAVNE_NEROVNOMERNE');
        $preview = $this->preview($component, (2 * self::MEAL_LIMIT_MINOR) + 1);

        self::assertSame('manual_review', $preview['support_status']);
        self::assertStringContainsString('rovnoměrně', (string) $preview['blocker']);
        self::assertNull($preview['exemption_basket']);

        $approval = $this->approve(
            $component,
            (2 * self::MEAL_LIMIT_MINOR) + 1,
            'stravne-nerovnomerne',
        );
        self::assertSame(409, $approval->getStatusCode());
        self::assertStringContainsString(
            'rovnoměrně',
            (string) ($this->json($approval)['error']['message'] ?? ''),
        );
    }

    public function testMealAllowanceTripOnlyExcludesItsOwnEmployment(): void
    {
        $otherEmploymentId = $this->createEmployment('SYN-STRAVNE-CESTA');
        $this->approveAttendanceMonth();
        $this->approveAttendanceMonth($otherEmploymentId);
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 0);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 09:00', 0);
        $this->seedBusinessTrip(
            '2026-07-06 05:00',
            '2026-07-06 15:00',
            18_500,
            $otherEmploymentId,
        );

        $component = $this->createMealComponent('STRAVNE_VZTAHY_CESTA');
        $preview = $this->basket($this->preview($component, self::MEAL_LIMIT_MINOR));

        self::assertSame(1, $preview['shift_entitlements']);
        self::assertSame(self::MEAL_LIMIT_MINOR, $preview['exempt_minor']);
    }

    /**
     * Příspěvek na stravování a roční koš § 6 odst. 9 písm. d) jsou samostatná
     * ustanovení s vlastními limity — nesmí se sčítat ani navzájem ukrajovat.
     */
    public function testMealBasketDoesNotConsumeTheAnnualLeisureBasket(): void
    {
        $this->approveAttendanceMonth();
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00', 30);
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 12:00', 0);

        $meal = $this->createMealComponent('STRAVNE_SOUBEH');
        self::assertSame(
            200,
            $this->approve($meal, self::MEAL_LIMIT_MINOR, 'stravne-soubeh')->getStatusCode(),
        );

        $leisure = $this->createComponent('REKREACE_SOUBEH', [
            'component_kind' => 'benefit_recreation',
            'value_kind' => 'non_monetary',
            'exemption_basket' => 'non_cash_leisure',
            'exemption_basis' => 'benefit_basket',
        ]);
        $preview = $this->basket($this->preview($leisure, 100_000));

        self::assertSame('non_cash_leisure', $preview['basket']);
        self::assertSame(0, $preview['used_before_minor']);
        self::assertSame(100_000, $preview['exempt_minor']);
        self::assertNull($preview['shift_entitlements']);
    }

    /**
     * § 6 odst. 9 písm. i) ZDP — limit je MĚSÍČNÍ. Do dalšího měsíce se
     * nepřenáší nevyčerpaný zbytek ani vyčerpání.
     */
    public function testTemporaryAccommodationLimitResetsEveryMonth(): void
    {
        $component = $this->createComponent('UBYTOVANI_SOUBEH', [
            'component_kind' => 'benefit_accommodation',
            'value_kind' => 'non_monetary',
            'exemption_basket' => 'temporary_accommodation',
            'exemption_basis' => 'periodic_benefit_limit',
        ]);

        self::assertSame(
            200,
            $this->approve($component, self::ACCOMMODATION_LIMIT_MINOR, 'ubyt-7')->getStatusCode(),
        );
        self::assertSame(
            ['temporary_accommodation', self::ACCOMMODATION_LIMIT_MINOR, 0],
            $this->frozenSplit('ubyt-7'),
        );

        // Druhé plnění téhož měsíce už je celé nadlimitní…
        self::assertSame(
            200,
            $this->approve($component, 100, 'ubyt-7b')->getStatusCode(),
        );
        self::assertSame(['temporary_accommodation', 0, 100], $this->frozenSplit('ubyt-7b'));

        // …a srpen začíná znovu na plném stropu.
        self::assertSame(
            200,
            $this->approve($component, self::ACCOMMODATION_LIMIT_MINOR, 'ubyt-8', '2026-08')
                ->getStatusCode(),
        );
        self::assertSame(
            ['temporary_accommodation', self::ACCOMMODATION_LIMIT_MINOR, 0],
            $this->frozenSplit('ubyt-8'),
        );
    }

    private function approveAttendanceMonth(?int $employmentId = null): void
    {
        $this->seedAttendanceMonth('approved', $employmentId);
    }

    private function seedAttendanceMonth(string $status, ?int $employmentId = null): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, approved_at)
             VALUES (?, ?, ?, ?, IF(? = "approved", NOW(), NULL))'
        )->execute([
            $this->supplierId,
            $employmentId ?? $this->employmentId,
            self::PERIOD_START,
            $status,
            $status,
        ]);
    }

    private function seedShift(
        string $localStart,
        string $localEnd,
        int $breakMinutes,
        ?int $employmentId = null,
    ): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc,
                 timezone_name, break_minutes, status, published_at)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", ?, "published", NOW())'
        )->execute([
            $this->supplierId,
            $employmentId ?? $this->employmentId,
            bin2hex(random_bytes(16)),
            $this->utc($localStart),
            $this->utc($localEnd),
            $breakMinutes,
        ]);
    }

    private function seedWorkedTime(
        string $localStart,
        string $localEnd,
        int $breakMinutes,
        ?int $employmentId = null,
    ): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, category, starts_at_utc,
                 ends_at_utc, timezone_name, break_minutes, source_kind, source_hash,
                 status, approved_at)
             VALUES (?, ?, ?, "regular", ?, ?, "Europe/Prague", ?, "manual", ?,
                     "approved", NOW())'
        )->execute([
            $this->supplierId,
            $employmentId ?? $this->employmentId,
            bin2hex(random_bytes(16)),
            $this->utc($localStart),
            $this->utc($localEnd),
            $breakMinutes,
            random_bytes(32),
        ]);
    }

    private function seedBusinessTrip(
        string $from,
        string $to,
        int $entitlementMinor,
        ?int $employmentId = null,
    ): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_business_trips
                (supplier_id, employee_id, employment_id, timezone_name,
                 departure_at_utc, arrival_at_utc,
                 origin_place, destination_place, purpose, settlement_period_start,
                 status, entitlement_total_minor, exempt_total_minor,
                 taxable_total_minor, ruleset_id, calculation_json, calculation_hash)
             VALUES (?, ?, ?, "Europe/Prague", ?, ?, "Praha", "Brno", "Jednání", ?,
                     "approved", ?, ?, 0,
                     "cz-payroll-2026.travel-allowances.v1", ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $employmentId ?? $this->employmentId,
            // Cesta nese UTC instant + zónu, stejně jako směna výše.
            $this->utc($from),
            $this->utc($to),
            self::PERIOD_START,
            $entitlementMinor,
            $entitlementMinor,
            '{"synthetic":true}',
            hash('sha256', '{"synthetic":true}', true),
        ]);
    }

    private function utc(string $local): string
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Prague')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function createEmployment(string $code): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, ?, "dpp", "active",
                     "2026-01-01", "2026-01-01", 1000000, 0)'
        )->execute([$this->supplierId, $this->employeeId, $code]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function setMealBasis(int $employmentId, string $basis): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET meal_entitlement_basis = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$basis, $this->supplierId, $employmentId]);
    }

    private function createMealComponent(string $code): int
    {
        return $this->createComponent($code, [
            'component_kind' => 'benefit_meal',
            'value_kind' => 'monetary',
            'exemption_basket' => 'meal_per_shift',
            'exemption_basis' => 'periodic_benefit_limit',
        ]);
    }

    /** @param array<string,string> $overrides */
    private function createComponent(string $code, array $overrides): int
    {
        $response = $this->components->create(
            $this->request('POST', '/api/payroll/components')->withParsedBody([
                'code' => $code,
                'name' => 'Syntetická složka ' . $code,
                'frequency_kind' => 'regular',
                'tax_treatment' => 'exempt',
                'social_participation_treatment' => 'excluded',
                'social_treatment' => 'excluded',
                'health_participation_treatment' => 'excluded',
                'health_treatment' => 'excluded',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'excluded',
                'jmhz_treatment' => 'excluded',
                'statistics_treatment' => 'included',
                'accounting_debit_code' => null,
                'accounting_credit_code' => null,
                'annual_limit_minor' => null,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
                ...$overrides,
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $component = PayrollTimeValue::row($this->json($response)['component'] ?? null, 'component');

        return PayrollTimeValue::int($component['id'] ?? null, 'component_id');
    }

    /**
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function basket(array $preview): array
    {
        return PayrollTimeValue::row($preview['exemption_basket'] ?? null, 'exemption_basket');
    }

    /** @return array{0:?string,1:?int,2:?int} */
    private function frozenSplit(string $externalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT benefit_basket, benefit_exempt_minor, benefit_taxable_minor
               FROM payroll_inputs
              WHERE supplier_id = ? AND external_id = ?'
        );
        $stmt->execute([$this->supplierId, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Vstup {$externalId} v databázi není.");

        return [
            $row['benefit_basket'] === null ? null : (string) $row['benefit_basket'],
            $row['benefit_exempt_minor'] === null ? null : (int) $row['benefit_exempt_minor'],
            $row['benefit_taxable_minor'] === null ? null : (int) $row['benefit_taxable_minor'],
        ];
    }

    /** @return array<string,mixed> */
    private function frozenAllocation(string $externalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT benefit_allocation_json
               FROM payroll_inputs
              WHERE supplier_id = ? AND external_id = ?'
        );
        $stmt->execute([$this->supplierId, $externalId]);
        $json = $stmt->fetchColumn();
        self::assertIsString($json);
        $allocation = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($allocation);

        return $allocation;
    }

    /** @return array<string,mixed> */
    private function preview(int $componentId, int $amountMinor): array
    {
        $response = $this->inputs->preview(
            $this->request('POST', '/api/payroll/inputs/preview')->withParsedBody(
                $this->inputPayload($componentId, $amountMinor, 'preview', self::PERIOD),
            ),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return PayrollTimeValue::row($this->json($response)['preview'] ?? null, 'preview');
    }

    private function approve(
        int $componentId,
        int $amountMinor,
        string $externalId,
        string $period = self::PERIOD,
    ): ResponseInterface {
        $created = $this->inputs->create(
            $this->request('POST', '/api/payroll/inputs')->withParsedBody(
                $this->inputPayload($componentId, $amountMinor, $externalId, $period),
            ),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $input = PayrollTimeValue::row($this->json($created)['input'] ?? null, 'input');
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');

        return $this->inputs->approve(
            $this->request('POST', "/api/payroll/inputs/{$inputId}/approve")
                ->withParsedBody([
                    'row_version' => PayrollTimeValue::int(
                        $input['row_version'] ?? null,
                        'row_version',
                    ),
                ]),
            new Response(),
            ['id' => (string) $inputId],
        );
    }

    /** @return array<string,mixed> */
    private function inputPayload(
        int $componentId,
        int $amountMinor,
        string $externalId,
        string $period,
    ): array {
        return [
            'employee_id' => $this->employeeId,
            'employment_id' => $this->employmentId,
            'component_id' => $componentId,
            'period' => $period,
            'source_period' => null,
            'amount_minor' => $amountMinor,
            'quantity_milliunits' => null,
            'source_kind' => 'manual',
            'external_id' => $externalId,
        ];
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

    private function request(string $method, string $uri): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return PayrollTimeValue::row(json_decode((string) $response->getBody(), true), 'response');
    }
}
