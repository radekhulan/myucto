<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Souhrn JMHZ u DPP s nástupem uprostřed měsíce a docházkou v minutách.
 *
 * Reálný stav z provozu (syntetizovaný): DPP od 7. do 28. 2. 2026, už ukončená,
 * v podmínkách týdně 40 h, bez pracovního kalendáře. Mřížka docházky má čtyři
 * dny a 32 h 25 min. Náhled dřív vrátil prázdné odpracované hodiny i sjednaný
 * fond, protože 1 945 minut nejde přesně vyjádřit v tisícinách hodiny, a měsíc
 * tak nešel schválit hromadně ani bez ručního zaokrouhlování.
 *
 * Pokyny MH 1.4.14: 10268 i 10260 jsou povinné „desetinné číslo s maximálně
 * 3 desetinnými místy"; 10265 je u dohody „nulová hodnota"; 10261 u toho, kdo se
 * do povinného podílu OZP nezapočítává, „missingová hodnota 99".
 */
#[Group('integration')]
final class PayrollJmhzAgreementMidMonthEntriesTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollJmhzWorkMonthSummaryBuilder $builder;
    private int $supplierId;
    private int $userId;
    private int $employmentId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->builder = $container->get(PayrollJmhzWorkMonthSummaryBuilder::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_employments', 'payroll_employment_terms', 'payroll_time_entries', 'payroll_time_months'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $this->employmentId = $this->createEndedMidMonthAgreement();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, work_source, revision_no)
             VALUES (?, ?, "2026-02-01", "open", "entries", 2)'
        )->execute([$this->supplierId, $this->employmentId]);
        // Tři dny po 8 h a jeden 8 h 25 min (místní čas, přestávka 30 min);
        // opravená směna má předchozí revizi nahrazenou.
        $this->entry('a', 1, 'approved', '2026-02-20 07:00:00', '2026-02-20 15:30:00');
        $this->entry('b', 1, 'approved', '2026-02-23 07:00:00', '2026-02-23 15:30:00');
        $this->entry('c', 1, 'approved', '2026-02-24 07:00:00', '2026-02-24 15:30:00');
        $this->entry('d', 1, 'superseded', '2026-02-26 07:00:00', '2026-02-26 15:45:00');
        $this->entry('d', 2, 'draft', '2026-02-26 07:00:00', '2026-02-26 15:55:00');
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

    public function testPreviewSuggestsWorkedHoursAndAgreedFundFromEntries(): void
    {
        $preview = $this->builder->preview($this->supplierId, $this->employmentId, '2026-02-01');

        self::assertSame([], $preview['issues']);
        self::assertSame('jmhz-work-month.v9', $preview['derivation_version']);
        // 32 h 25 min = 32,41666… h → nejbližší tisícina hodiny.
        self::assertSame('32.417', $preview['suggestions']['worked_hours']);
        self::assertSame(4, $preview['suggestions']['worked_days']);
        // Dohoda bez rozvrhu: předpokládaný rozsah = odpracovaná doba (bez dovolené).
        self::assertSame('32.417', $preview['suggestions']['agreed_fund_hours']);
        // 10265 u dohody 0, 10261 kód 99, ne týdenní úvazek z karty.
        self::assertSame(0, $preview['suggestions']['evidence_days']);
        self::assertSame('99', $preview['suggestions']['weekly_work_hours']);
        // Fond stanovený jen za pracovní dny trvání dohody (9.–27. 2.: 15 dní × 8 h).
        self::assertSame('120', $preview['suggestions']['standard_fund_hours']);
        self::assertSame(['agreed_fund_hours', 'worked_hours'], $preview['rounded_suggestions']);
    }

    public function testSuggestionsConfirmWithoutTypingAndRecordRounding(): void
    {
        $preview = $this->builder->preview($this->supplierId, $this->employmentId, '2026-02-01');
        $suggestions = $preview['suggestions'];

        $summary = $this->builder->confirm($preview, [
            'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
            'standard_fund_hours' => $suggestions['standard_fund_hours'],
            'agreed_fund_hours' => $suggestions['agreed_fund_hours'],
            'weekly_work_hours' => $suggestions['weekly_work_hours'],
            'worked_hours' => $suggestions['worked_hours'],
            'unworked_hours_occurred' => false,
            'work_obstacles_occurred' => false,
        ]);

        self::assertSame(32_417, $summary['values']['worked_millihours']);
        self::assertSame(32_417, $summary['values']['agreed_fund_millihours']);
        self::assertSame(9_900, $summary['values']['weekly_work_centihours']);
        self::assertSame(0, $summary['values']['evidence_days']);
        self::assertSame(
            ['policy' => 'time_entry_minutes_half_up_to_millihour', 'fields' => ['agreed_fund_hours', 'worked_hours']],
            $summary['provenance']['suggestions_rounding'],
        );
    }

    private function createEndedMidMonthAgreement(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba DPP", "employee", "dpp", 1, 0, 0, 0, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, is_primary,
                 start_date, actual_start_date, end_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, "DPP-1", "dpp", "ended", 0, "2026-02-07", "2026-02-07", "2026-02-28", 18000, 0)'
        )->execute([$this->supplierId, $employeeId]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on,
                 fixed_term_end_on, monthly_gross_minor, weekly_hours, workload_basis_points,
                 social_insurance_participation, health_insurance_participation,
                 tax_regime, tax_declaration_signed, is_primary)
             VALUES (?, ?, "2026-02-07", "2026-02-07", "2026-02-28", 18000, 40, 10000,
                     "automatic", "automatic", "advance", 1, 0)'
        )->execute([$this->supplierId, $employmentId]);

        return $employmentId;
    }

    private function entry(string $series, int $revision, string $status, string $startsAtUtc, string $endsAtUtc): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, revision_no, category,
                 starts_at_utc, ends_at_utc, timezone_name, break_minutes,
                 source_kind, source_hash, status, created_by, approved_by, approved_at)
             VALUES (?, ?, ?, ?, "regular", ?, ?, "Europe/Prague", 30, "manual", ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            md5('dpp-mid-month-' . $series),
            $revision,
            $startsAtUtc,
            $endsAtUtc,
            hash('sha256', $series . $revision, true),
            $status,
            $this->userId,
            $status === 'approved' ? $this->userId : null,
            $status === 'approved' ? '2026-02-28 12:00:00' : null,
        ]);
    }
}
