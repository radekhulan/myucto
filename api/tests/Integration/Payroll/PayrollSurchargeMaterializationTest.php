<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeInputMaterializer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * W19 — zákonné příplatky § 114 až § 118 ZP se musí dostat do mzdy.
 *
 * Do W19 se spočítaly a zahodily. Testy tady drží tři věci, na kterých to celé
 * stojí: opakované promítnutí nesmí zdvojit nárok, změna docházky musí vyrobit
 * ROZDÍL (ne novou plnou částku) a chybějící podklad nesmí skončit nulou.
 */
#[Group('integration')]
final class PayrollSurchargeMaterializationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06-01';

    private Connection $db;
    private PayrollSurchargeInputMaterializer $materializer;
    private int $supplierId;
    private int $userId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $this->db = $container->get(Connection::class);
        $this->materializer = $container->get(PayrollSurchargeInputMaterializer::class);
        foreach ([
            'payroll_time_entries',
            'payroll_time_months',
            'payroll_surcharge_input_materializations',
            'payroll_employment_surcharge_policies',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $container->get(PayrollComponentRepository::class)->ensureDefaults($this->supplierId);
        $this->employmentId = $this->employment();
        $this->approvedAverage(20_000);
        $this->approvedMonth(1);
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

    public function testFirstMaterializationCreatesApprovedInput(): void
    {
        // Dvě hodiny přesčasu 10.–12. hodiny 2026-06-10.
        $this->entry('overtime', '2026-06-10 08:00', '2026-06-10 10:00');

        $result = $this->materialize();

        self::assertSame(1, $result['written_count']);
        // 25 % z 200 Kč/h × 2 h = 100 Kč.
        self::assertSame(10_000, $result['total_minor']);
        $input = $this->input('PRIPLATEK_PRESCAS');
        self::assertIsArray($input);
        self::assertSame('approved', $input['status']);
        self::assertSame(10_000, (int) $input['amount_minor']);
        self::assertSame('time', $input['source_kind']);
        // Zmrazená klasifikace složky je podmínkou schváleného vstupu.
        self::assertNotNull($input['component_snapshot_json']);
        self::assertNotNull($input['component_snapshot_hash']);
        self::assertSame(1, $this->ledgerCount());
    }

    public function testRepeatedMaterializationIsIdempotent(): void
    {
        $this->entry('overtime', '2026-06-10 08:00', '2026-06-10 10:00');
        $this->materialize();

        $again = $this->materialize();

        self::assertSame(0, $again['written_count']);
        self::assertSame(1, $again['unchanged_count']);
        self::assertSame('replayed', $again['unchanged'][0]['outcome']);
        self::assertSame(1, $this->ledgerCount());
        self::assertSame(10_000, $this->approvedTotal('PRIPLATEK_PRESCAS'));
    }

    public function testChangedAttendanceProducesDifferenceNotDuplicate(): void
    {
        $this->entry('overtime', '2026-06-10 08:00', '2026-06-10 10:00');
        $this->materialize();

        // Přesčas se po znovuotevření měsíce prodloužil na čtyři hodiny.
        $this->db->pdo()->prepare('DELETE FROM payroll_time_entries WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->entry('overtime', '2026-06-10 08:00', '2026-06-10 12:00');
        $this->approvedMonth(2);

        $corrected = $this->materialize();

        self::assertSame(1, $corrected['written_count']);
        self::assertSame('correction', $corrected['written'][0]['materialization_kind']);
        // Rozdíl, ne nová plná částka: 200 Kč − 100 Kč = 100 Kč.
        self::assertSame(10_000, $corrected['written'][0]['amount_minor']);
        self::assertSame(20_000, $corrected['written'][0]['cumulative_minor']);
        // Souhrn schválených vstupů odpovídá nároku, ne jeho dvojnásobku.
        self::assertSame(20_000, $this->approvedTotal('PRIPLATEK_PRESCAS'));
        self::assertSame(2, $this->ledgerCount());
    }

    public function testVanishedClaimIsReversedNotDeleted(): void
    {
        $this->entry('overtime', '2026-06-10 08:00', '2026-06-10 10:00');
        $this->materialize();

        $this->db->pdo()->prepare('DELETE FROM payroll_time_entries WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->approvedMonth(2);

        $reversed = $this->materialize();

        self::assertSame('reversal', $reversed['written'][0]['materialization_kind']);
        self::assertSame(-10_000, $reversed['written'][0]['amount_minor']);
        self::assertSame(0, $reversed['written'][0]['cumulative_minor']);
        // Původní vstup zůstal; nárok se ruší dalším řádkem, ne mazáním.
        self::assertSame(2, $this->inputCount('PRIPLATEK_PRESCAS'));
        self::assertSame(0, $this->approvedTotal('PRIPLATEK_PRESCAS'));
    }

    public function testLockedInputsRefuseMaterialization(): void
    {
        $this->entry('overtime', '2026-06-10 08:00', '2026-06-10 10:00');
        $this->materialize();
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET status = "locked"
              WHERE supplier_id = ? AND period_start = ?'
        )->execute([$this->supplierId, self::PERIOD]);

        $this->db->pdo()->prepare('DELETE FROM payroll_time_entries WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->entry('overtime', '2026-06-10 08:00', '2026-06-10 12:00');
        $this->approvedMonth(2);

        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/uzamčené/');
        $this->materialize();
    }

    public function testQuickOvertimeInputBlocksMaterialization(): void
    {
        $this->entry('overtime', '2026-06-10 08:00', '2026-06-10 10:00');
        $this->quickOvertimeInput();

        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/dvakrát/');
        $this->materialize();
    }

    public function testUnapprovedMonthFailsClosed(): void
    {
        $this->entry('overtime', '2026-06-10 08:00', '2026-06-10 10:00');
        $this->db->pdo()->prepare(
            'UPDATE payroll_time_months
                SET status = "open", approved_at = NULL, approved_by = NULL
              WHERE supplier_id = ?'
        )->execute([$this->supplierId]);

        $this->expectException(PayrollSurchargeException::class);
        $this->materialize();
    }

    public function testMissingAverageEarningFailsClosedInsteadOfZero(): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_average_earning_snapshots WHERE supplier_id = ?'
        )->execute([$this->supplierId]);
        $this->entry('overtime', '2026-06-10 08:00', '2026-06-10 10:00');

        $this->expectException(PayrollSurchargeException::class);
        $this->expectExceptionMessageMatches('/průměrného výdělku/');
        $this->materialize();
    }

    /**
     * Kategorie docházky jsou PŘEKRYVNÉ PŘÍZNAKY. Hodina odpracovaná v noci
     * o víkendu jako přesčas nese tři příplatky současně a sčítají se.
     */
    public function testThreeSurchargesOnTheSameHourAreCumulative(): void
    {
        // Sobota 2026-06-13, 23:00–24:00 místního času.
        $this->entry('overtime', '2026-06-13 23:00', '2026-06-14 00:00');
        $this->entry('night', '2026-06-13 23:00', '2026-06-14 00:00');
        $this->entry('weekend', '2026-06-13 23:00', '2026-06-14 00:00');

        $result = $this->materialize();

        self::assertSame(3, $result['written_count']);
        self::assertSame(5_000, $this->approvedTotal('PRIPLATEK_PRESCAS'));
        self::assertSame(2_000, $this->approvedTotal('PRIPLATEK_NOCNI'));
        self::assertSame(2_000, $this->approvedTotal('PRIPLATEK_VIKEND'));
        self::assertSame(9_000, $result['total_minor']);
    }

    /** @return array<string,mixed> */
    private function materialize(): array
    {
        return $this->materializer->materialize(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            $this->userId,
        );
    }

    private function entry(string $category, string $localStart, string $localEnd): void
    {
        $prague = new \DateTimeZone('Europe/Prague');
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($localStart, $prague);
        $end = new \DateTimeImmutable($localEnd, $prague);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, revision_no, category,
                 starts_at_utc, ends_at_utc, timezone_name, break_minutes,
                 source_kind, source_hash, status, approved_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, "Europe/Prague", 0,
                     "manual", ?, "approved", NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            bin2hex(random_bytes(16)),
            $category,
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            random_bytes(32),
        ]);
    }

    private function approvedMonth(int $revisionNo): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no,
                 row_version, last_changed_by, approved_by, approved_at)
             VALUES (?, ?, ?, "approved", ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = "approved", revision_no = VALUES(revision_no),
                                     row_version = VALUES(row_version)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            $revisionNo,
            $revisionNo,
            $this->userId,
            $this->userId,
        ]);
    }

    private function approvedAverage(int $hourlyMinor): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year, applicable_quarter,
                 revision_no, source_kind, decisive_from, decisive_to,
                 gross_earnings_minor, longer_period_allocated_minor,
                 worked_minutes, worked_days, average_hourly_minor,
                 support_status, status, ruleset_id, ruleset_hash,
                 input_hash, input_trace)
             VALUES (?, ?, 2026, 2, 1, "actual", "2026-01-01", "2026-03-31",
                     1000000, 0, 6000, 21, ?,
                     "supported", "approved", "synthetic-2026",
                     REPEAT("a", 64), UNHEX(SHA2("synthetic", 256)), "{}")'
        )->execute([$this->supplierId, $this->employmentId, $hourlyMinor]);
    }

    private function quickOvertimeInput(): void
    {
        $pdo = $this->db->pdo();
        $employeeId = (int) $pdo->query(
            'SELECT employee_id FROM payroll_employments WHERE id = ' . $this->employmentId
        )->fetchColumn();
        $componentId = $this->componentId('PREMIE_PRIPLATKY');
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start,
                 amount_minor, source_kind, external_id, status)
             VALUES (?, ?, ?, ?, ?, 50000, "manual", "quick-monthly:PREMIE_PRIPLATKY", "draft")'
        )->execute([
            $this->supplierId,
            $employeeId,
            $this->employmentId,
            $componentId,
            self::PERIOD,
        ]);
    }

    private function componentId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND is_active = 1
              ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $code]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            $this->markTestSkipped("Chybí mzdová složka {$code}.");
        }

        return $id;
    }

    /** @return array<string,mixed>|null */
    private function input(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.* FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = ? AND component.code = ?
              ORDER BY input.id'
        );
        $stmt->execute([$this->supplierId, $this->employmentId, self::PERIOD, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function approvedTotal(string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(input.amount_minor), 0) FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = ? AND component.code = ?
                AND input.status IN ("approved", "locked")'
        );
        $stmt->execute([$this->supplierId, $this->employmentId, self::PERIOD, $code]);

        return (int) $stmt->fetchColumn();
    }

    private function inputCount(string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = ? AND component.code = ?'
        );
        $stmt->execute([$this->supplierId, $this->employmentId, self::PERIOD, $code]);

        return (int) $stmt->fetchColumn();
    }

    private function ledgerCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_surcharge_input_materializations
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $stmt->execute([$this->supplierId, $this->employmentId]);

        return (int) $stmt->fetchColumn();
    }

    private function employment(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Příplatková osoba", "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, "SYN-PRIPLATKY", "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }
}
