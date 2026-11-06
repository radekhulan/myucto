<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollMealShiftEvidenceService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vyloučení směny pracovní cestou musí sedět NA MINUTU — v letním i zimním čase.
 *
 * § 6 odst. 9 písm. b) ZDP: nárok na příspěvek na stravování nevzniká za směnu,
 * během které zaměstnanci vznikl nárok na stravné z cestovních náhrad. Směna je
 * uložená jako pravý UTC instant plus IANA zóna; pracovní cesta do migrace 1518
 * držela HOLÝ místní čas, který se pak četl jako UTC. Cesta se proto do směny
 * trefovala o 1 hodinu (SEČ) až 2 hodiny (SELČ) vedle a vylučovala směny, které
 * s ní nemají nic společného — nebo naopak nevyloučila tu pravou.
 *
 * Testy jsou proto stavěné NA DOTYK: cesta končí přesně v okamžiku, kdy směna
 * začíná (nebo začíná přesně tam, kde směna končí). O jedinou hodinu posunutá
 * interpretace překryv vyrobí, a test spadne. Bez opravy padají všechny tři
 * negativní případy; pozitivní kontrola drží, že se vyloučení nezrušilo úplně.
 */
#[Group('integration')]
final class PayrollMealShiftTripTimezoneTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollMealShiftEvidenceService $evidence;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $evidence = $container->get(PayrollMealShiftEvidenceService::class);
        if (!$db instanceof Connection || !$evidence instanceof PayrollMealShiftEvidenceService) {
            throw new \RuntimeException('Payroll služby nejsou dostupné.');
        }
        $this->db = $db;
        if (!$this->db->hasTable('payroll_business_trips')) {
            $this->markTestSkipped('Migrace mzdového modulu neproběhly.');
        }
        $this->evidence = $evidence;

        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type, is_active)
             VALUES (?, "Syntetický cestující", "employee", "hpp", 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, "SYN-TZ", "employment", "active",
                     "2025-01-01", "2025-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /**
     * LETNÍ ČAS (SELČ, +2). Cesta končí přesně v okamžiku, kdy směna začíná.
     *
     * Před opravou: cesta 02:00–06:00 čtená jako UTC proti směně 04:00–12:00 UTC
     * dala dvouhodinový překryv a směnu vyloučila → 0 nároků.
     */
    public function testSummerTripEndingWhenTheShiftStartsDoesNotExcludeIt(): void
    {
        $this->approveMonth('2026-07-01');
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00');
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 12:00');
        $this->seedTrip('2026-07-06 02:00', '2026-07-06 06:00', '2026-07-01');

        self::assertSame(1, $this->qualifying('2026-07-01'));
    }

    /**
     * ZIMNÍ ČAS (SEČ, +1). Tentýž dotyk o měsíce dřív — posun je jiný, výsledek
     * musí být stejný.
     *
     * Před opravou: cesta 03:00–06:00 čtená jako UTC proti směně 05:00–13:00 UTC
     * dala hodinový překryv a směnu vyloučila → 0 nároků.
     */
    public function testWinterTripEndingWhenTheShiftStartsDoesNotExcludeIt(): void
    {
        $this->approveMonth('2026-01-01');
        $this->seedShift('2026-01-15 06:00', '2026-01-15 14:00');
        $this->seedWorkedTime('2026-01-15 06:00', '2026-01-15 12:00');
        $this->seedTrip('2026-01-15 03:00', '2026-01-15 06:00', '2026-01-01');

        self::assertSame(1, $this->qualifying('2026-01-01'));
    }

    /**
     * HRANICE DNE. Noční směna z 6. na 7. 7. a cesta, která končí přesně v
     * okamžiku, kdy směna začíná. Vyloučení musí padnout na správný den —
     * a když nemá na co, nesmí padnout vůbec.
     *
     * Před opravou: cesta 18:00–22:00 čtená jako UTC proti směně 20:00–04:00 UTC
     * vyloučila noční směnu → 0 nároků.
     */
    public function testTripTouchingANightShiftAcrossMidnightDoesNotExcludeIt(): void
    {
        $this->approveMonth('2026-07-01');
        $this->seedShift('2026-07-06 22:00', '2026-07-07 06:00');
        $this->seedWorkedTime('2026-07-06 22:00', '2026-07-07 04:00');
        $this->seedTrip('2026-07-06 18:00', '2026-07-06 22:00', '2026-07-01');

        self::assertSame(1, $this->qualifying('2026-07-01'));
    }

    /**
     * Pozitivní kontrola: cesta, která do směny opravdu zasahuje, ji vyloučit
     * MUSÍ. Bez tohohle případu by testy nad ním prošly i tehdy, kdyby se
     * vyloučení vyplo úplně.
     */
    public function testTripOverlappingTheShiftStillExcludesIt(): void
    {
        $this->approveMonth('2026-07-01');
        $this->seedShift('2026-07-06 06:00', '2026-07-06 14:00');
        $this->seedWorkedTime('2026-07-06 06:00', '2026-07-06 12:00');
        $this->seedTrip('2026-07-06 05:00', '2026-07-06 09:00', '2026-07-01');

        self::assertSame(0, $this->qualifying('2026-07-01'));
    }

    private function qualifying(string $periodStart): int
    {
        $entitlement = $this->evidence->forPeriod(
            $this->supplierId,
            $this->employeeId,
            $periodStart,
        );
        self::assertTrue($entitlement->complete, implode(',', $entitlement->missing));

        return $entitlement->qualifyingCount;
    }

    private function approveMonth(string $periodStart): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, approved_at)
             VALUES (?, ?, ?, "approved", NOW())'
        )->execute([$this->supplierId, $this->employmentId, $periodStart]);
    }

    private function seedShift(string $localStart, string $localEnd): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc,
                 timezone_name, break_minutes, status, published_at)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", 0, "published", NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            bin2hex(random_bytes(16)),
            $this->utc($localStart),
            $this->utc($localEnd),
        ]);
    }

    private function seedWorkedTime(string $localStart, string $localEnd): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, category, starts_at_utc,
                 ends_at_utc, timezone_name, break_minutes, source_kind, source_hash,
                 status, approved_at)
             VALUES (?, ?, ?, "regular", ?, ?, "Europe/Prague", 0, "manual", ?,
                     "approved", NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            bin2hex(random_bytes(16)),
            $this->utc($localStart),
            $this->utc($localEnd),
            random_bytes(32),
        ]);
    }

    /** Cesta se ukládá stejně jako směna: UTC instant + zóna, ve které byla zadána. */
    private function seedTrip(string $localFrom, string $localTo, string $periodStart): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_business_trips
                (supplier_id, employee_id, employment_id, timezone_name,
                 departure_at_utc, arrival_at_utc,
                 origin_place, destination_place, purpose, settlement_period_start,
                 status, entitlement_total_minor, exempt_total_minor,
                 taxable_total_minor, ruleset_id, calculation_json, calculation_hash)
             VALUES (?, ?, ?, "Europe/Prague", ?, ?, "Praha", "Brno", "Jednání", ?,
                     "approved", 18500, 18500, 0,
                     "cz-payroll-2026.travel-allowances.v1", ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $this->utc($localFrom),
            $this->utc($localTo),
            $periodStart,
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

    private function firstId(PDO $pdo, string $table): int
    {
        $value = $pdo->query("SELECT id FROM {$table} ORDER BY id LIMIT 1")->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }
}
