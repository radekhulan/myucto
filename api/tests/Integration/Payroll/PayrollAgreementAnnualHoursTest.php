<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessImpact;
use MyInvoice\Service\Payroll\Time\PayrollAgreementAnnualHours;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 75 zákoníku práce: dohoda o provedení práce nejvýš 300 hodin v roce
 * u téhož zaměstnavatele, včetně jiných DPP. Hlídané to nebylo vůbec — a u
 * firmy po přechodu leží část hodin jen v převzatých mzdách.
 */
#[Group('integration')]
final class PayrollAgreementAnnualHoursTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private PayrollPeopleRepository $people;
    private int $supplierId;
    private int $employeeId;
    private int $firstAgreement;
    private int $secondAgreement;

    protected function setUp(): void
    {
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->container = $container;
            $this->db = $container->get(Connection::class);
            $this->people = $container->get(PayrollPeopleRepository::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, "active", "2026-10-01")')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická brigádnice", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $this->firstAgreement = $this->agreement('DPP-1');
        $this->secondAgreement = $this->agreement('DPP-2');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testTakenOverHoursOfAllAgreementsCountTowardsTheLimit(): void
    {
        // Leden až září 2× 20 h měsíčně na dvou dohodách = 360 h převzatých.
        for ($month = 1; $month <= 9; ++$month) {
            $this->takeover($this->firstAgreement, $month, 20 * 60);
            $this->takeover($this->secondAgreement, $month, 20 * 60);
        }
        $service = new PayrollAgreementAnnualHours($this->db);

        $hours = $service->yearToDate($this->supplierId, [$this->employeeId], '2026-10-01');
        self::assertSame(360 * 60, $hours[$this->employeeId]['takeover_minutes']);

        $validations = $service->validations($this->supplierId, [[
            'employment_id' => $this->firstAgreement,
            'employee_id' => $this->employeeId,
            'relation_type' => 'dpp',
            'full_name' => 'Syntetická brigádnice',
        ]], '2026-10-01');

        self::assertCount(1, $validations);
        self::assertSame('dpp_annual_hours_exceeded', $validations[0]->code);
        self::assertStringContainsString('Syntetická brigádnice', $validations[0]->message);
        self::assertStringContainsString('360 h', $validations[0]->message);
        self::assertStringContainsString('převzatých', $validations[0]->message);
        self::assertStringContainsString("person={$this->employeeId}", (string) $validations[0]->remediationPath);
        // Varování, ne závora: odpracovanou práci je potřeba zaplatit.
        self::assertSame('warning', PayrollRunReadinessImpact::describe('dpp_annual_hours_exceeded')['severity']);
    }

    public function testApprovedManualFractionCountsWithoutJmhzAndCrossesAnnualLimitExactly(): void
    {
        $this->manualMonth($this->firstAgreement, 900003);
        $service = new PayrollAgreementAnnualHours($this->db);
        $summary = $service->yearSummaries($this->supplierId, [$this->employeeId], 2026)[$this->employeeId];
        self::assertSame('exceeded', $summary['status']);
        self::assertSame(900003, $summary['total_duration_units']);
        self::assertNull($summary['total_minutes']);
        self::assertSame(0, $summary['remaining_minutes']);
        self::assertSame('dpp_annual_hours_exceeded', $this->validations()[0]->code);
        self::assertStringContainsString('300,001 h', $this->validations()[0]->message);
        self::assertContains($this->employeeId, $service->employeesNearLimit($this->supplierId, 2026));
    }

    public function testSelectedManualZeroReplacesArchivedJmhzHours(): void
    {
        $this->ownMonth($this->firstAgreement, 10, 350000);
        $this->manualMonth($this->firstAgreement, 0);
        $summary = (new PayrollAgreementAnnualHours($this->db))->yearSummaries($this->supplierId, [$this->employeeId], 2026)[$this->employeeId];
        self::assertSame('ok', $summary['status']);
        self::assertSame(0, $summary['total_minutes']);
        self::assertSame(0, $summary['total_duration_units']);
    }

    public function testReopenedManualRevisionDoesNotReuseTheOldKnownHours(): void
    {
        $this->manualMonth($this->firstAgreement, 900003);
        $this->db->pdo()->prepare("UPDATE payroll_time_months SET status = 'open', revision_no = 2, approved_at = NULL, approved_by = NULL WHERE supplier_id = ? AND employment_id = ? AND period_start = '2026-10-01'")->execute([$this->supplierId, $this->firstAgreement]);
        $summary = (new PayrollAgreementAnnualHours($this->db))->yearSummaries($this->supplierId, [$this->employeeId], 2026)[$this->employeeId];
        self::assertSame('manual_review_required', $summary['status']);
        self::assertNull($summary['total_duration_units']);
        self::assertNull($summary['total_minutes']);
    }

    public function testManualSummaryMakesAnnualLimitExplicitlyUnknown(): void
    {
        $this->db->pdo()->prepare("INSERT INTO payroll_time_months (supplier_id, employment_id, period_start, work_source) VALUES (?, ?, '2026-10-01', 'manual_summary')")
            ->execute([$this->supplierId, $this->firstAgreement]);
        $service = new PayrollAgreementAnnualHours($this->db);
        $summary = $service->yearSummaries($this->supplierId, [$this->employeeId], 2026)[$this->employeeId];
        self::assertSame('manual_review_required', $summary['status']);
        self::assertNull($summary['total_minutes']);
        self::assertNull($summary['remaining_minutes']);
        self::assertSame('dpp_annual_hours_manual_review_required', $this->validations()[0]->code);
        $stored = $service->yearWithoutMonth($this->supplierId, [$this->employeeId], '2026-03-01', [$this->firstAgreement]);
        self::assertTrue($stored[$this->employeeId]['manual_review_required']);
        $persons = AttendanceImportService::annotateAgreementHours([[
            'display_name' => 'Syntetická brigádnice',
            'match' => ['status' => 'linked', 'employment_id' => $this->firstAgreement, 'employee_id' => $this->employeeId],
            'warnings' => [], '_metrics' => ['worked_hours' => ['millihours' => 70_000, 'source' => 'A1']],
        ]], [['employment_id' => $this->firstAgreement, 'relation_type' => 'dpp']], $stored, 2026);
        self::assertSame('manual_review_required', $persons[0]['dpp_annual_hours']['status']);
        self::assertNull($persons[0]['dpp_annual_hours']['total_minutes']);
        self::assertStringContainsString('ověřte', mb_strtolower($persons[0]['warnings'][0]));
    }

    public function testHoursBelowTheWarningThresholdProduceNoWarning(): void
    {
        // 8 × 30 h = 240 h, jedna minuta pod prahem upozornění.
        for ($month = 1; $month <= 8; ++$month) {
            $this->takeover($this->firstAgreement, $month, 30 * 60 - ($month === 8 ? 1 : 0));
        }

        self::assertSame([], $this->validations());
    }

    /**
     * Upozornění předem: od 80 % limitu (240 h) včetně se hlásí, že se osoba
     * blíží limitu, přesně 300 h je pořád jen upozornění, ne překročení.
     */
    public function testReachingTheWarningThresholdWarnsInAdvance(): void
    {
        for ($month = 1; $month <= 8; ++$month) {
            $this->takeover($this->firstAgreement, $month, 30 * 60);
        }

        $validations = $this->validations();

        self::assertCount(1, $validations);
        self::assertSame('dpp_annual_hours_approaching', $validations[0]->code);
        self::assertSame('warning', $validations[0]->severity);
        self::assertStringContainsString('240 h', $validations[0]->message);
        self::assertStringContainsString('Zbývá 60 h', $validations[0]->message);
        self::assertStringContainsString("person={$this->employeeId}", (string) $validations[0]->remediationPath);
    }

    public function testExactlyThreeHundredHoursIsStillOnlyApproaching(): void
    {
        for ($month = 1; $month <= 6; ++$month) {
            $this->takeover($this->firstAgreement, $month, 50 * 60);
        }

        $validations = $this->validations();

        self::assertCount(1, $validations);
        self::assertSame('dpp_annual_hours_approaching', $validations[0]->code);
        self::assertStringContainsString('Zbývá 0 h', $validations[0]->message);
    }

    public function testYearSummaryAndNearLimitSetComeFromTheSameTotals(): void
    {
        for ($month = 1; $month <= 5; ++$month) {
            $this->takeover($this->firstAgreement, $month, 50 * 60);
        }
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický brigádník bez hodin", "employee", 1)',
        )->execute([$this->supplierId]);
        $idleEmployee = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, "DPP-3", "dpp", "active", "2026-01-01", "2026-01-01", 0, 0, 0)',
        )->execute([$this->supplierId, $idleEmployee]);
        $service = new PayrollAgreementAnnualHours($this->db);

        $summaries = $service->yearSummaries($this->supplierId, [$this->employeeId, $idleEmployee], 2026);

        self::assertSame(250 * 60, $summaries[$this->employeeId]['total_minutes']);
        self::assertSame(50 * 60, $summaries[$this->employeeId]['remaining_minutes']);
        self::assertSame('approaching', $summaries[$this->employeeId]['status']);
        self::assertSame('ok', $summaries[$idleEmployee]['status']);
        self::assertSame(300 * 60, $summaries[$idleEmployee]['remaining_minutes']);
        self::assertSame([$this->employeeId], $service->employeesNearLimit($this->supplierId, 2026));
        self::assertSame([], $service->employeesNearLimit($this->supplierId, 2025));

        $people = $this->people;
        $page = $people->listForTenant(
            $this->supplierId,
            50,
            0,
            PayrollPeopleRepository::FILTER_DPP_HOURS_LIMIT,
            '',
            $service->employeesNearLimit($this->supplierId, 2026),
        );
        self::assertSame(1, $page['total']);
        self::assertSame($this->employeeId, (int) $page['items'][0]['id']);
        self::assertSame(
            0,
            $people->listForTenant($this->supplierId, 50, 0, PayrollPeopleRepository::FILTER_DPP_HOURS_LIMIT)['total'],
        );
    }

    /** @return list<\MyInvoice\Service\Payroll\Run\PayrollRunValidation> */
    private function validations(): array
    {
        return (new PayrollAgreementAnnualHours($this->db))->validations($this->supplierId, [[
            'employment_id' => $this->firstAgreement,
            'employee_id' => $this->employeeId,
            'relation_type' => 'dpp',
            'full_name' => 'Syntetická brigádnice',
        ]], '2026-10-01');
    }

    /**
     * Převod zakládá pracovní souhrn i za převzaté měsíce. Tytéž hodiny se nesmí
     * sečíst dvakrát (TOPTAX 7/2026: 550 h místo 285,5 h a falešné varování).
     */
    public function testTakenOverMonthIsNotCountedAgainFromOwnWorkSummary(): void
    {
        $hours = PayrollAgreementAnnualHours::combine(
            [7],
            [
                ['employee_id' => 7, 'period_start' => '2026-05-01', 'minutes' => 40 * 60],
                ['employee_id' => 7, 'period_start' => '2026-06-01', 'minutes' => 50 * 60],
            ],
            [
                ['employee_id' => 7, 'period_start' => '2026-05-01', 'millihours' => 40_000],
                ['employee_id' => 7, 'period_start' => '2026-06-01', 'millihours' => 50_000],
                ['employee_id' => 7, 'period_start' => '2026-07-01', 'millihours' => 10_500],
            ],
        );

        self::assertSame(90 * 60, $hours[7]['takeover_minutes']);
        self::assertSame(630, $hours[7]['own_minutes']);
        self::assertSame(90 * 60 + 630, $hours[7]['total_minutes']);
    }

    /**
     * Náhled importu staršího měsíce (březen), když duben až červen už hodiny
     * mají: roční součet bere celý rok bez uloženého březnového souhrnu
     * importovaného vztahu, jiná dohoda téže osoby za březen se počítá.
     * Dřív se bral součet jen do února a varování nepřišlo.
     */
    public function testAttendancePreviewOfOlderMonthCountsLaterMonthsAndOtherAgreement(): void
    {
        for ($month = 4; $month <= 6; ++$month) {
            $this->ownMonth($this->firstAgreement, $month, 60_000);
        }
        $this->ownMonth($this->firstAgreement, 3, 30_000);
        $this->ownMonth($this->secondAgreement, 3, 10_000);
        $service = new PayrollAgreementAnnualHours($this->db);

        $stored = $service->yearWithoutMonth($this->supplierId, [$this->employeeId], '2026-03-01', [$this->firstAgreement]);
        self::assertSame(190 * 60, $stored[$this->employeeId]['total_minutes']);
        self::assertSame(220 * 60, $service->yearToDate($this->supplierId, [$this->employeeId], '2026-12-01')[$this->employeeId]['total_minutes']);

        $import = $this->container->get(AttendanceImportService::class);
        $method = new \ReflectionMethod(AttendanceImportService::class, 'withAgreementHours');
        $persons = $method->invoke($import, $this->supplierId, '2026-03-01', [[
            'display_name' => 'Syntetická brigádnice',
            'match' => [
                'status' => 'linked',
                'employment_id' => $this->firstAgreement,
                'employee_id' => $this->employeeId,
                'employee_name' => 'Syntetická brigádnice',
            ],
            'warnings' => [],
            '_metrics' => ['worked_hours' => ['millihours' => 70_000, 'source' => 'A1']],
        ]], [
            ['employment_id' => $this->firstAgreement, 'relation_type' => 'dpp'],
            ['employment_id' => $this->secondAgreement, 'relation_type' => 'dpp'],
        ]);

        self::assertSame(260 * 60, $persons[0]['dpp_annual_hours']['total_minutes']);
        self::assertSame(70 * 60, $persons[0]['dpp_annual_hours']['imported_minutes']);
        self::assertSame('approaching', $persons[0]['dpp_annual_hours']['status']);
        self::assertCount(1, $persons[0]['warnings']);
        self::assertStringContainsString('260 h', $persons[0]['warnings'][0]);
    }

    private function manualMonth(int $employmentId, int $units): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO payroll_time_months (supplier_id, employment_id, period_start, status, revision_no, work_source, approved_at, approved_by) SELECT ?, ?, '2026-10-01', 'approved', 1, 'manual_summary', NOW(), MIN(id) FROM users ON DUPLICATE KEY UPDATE work_source = 'manual_summary'")->execute([$this->supplierId, $employmentId]);
        $statement = $pdo->prepare("SELECT id, revision_no FROM payroll_time_months WHERE supplier_id = ? AND employment_id = ? AND period_start = '2026-10-01'");
        $statement->execute([$this->supplierId, $employmentId]);
        $month = $statement->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO payroll_time_month_manual_summaries (supplier_id, time_month_id, time_month_revision_no, employment_id, period_start, content_sha256, reason) VALUES (?, ?, ?, ?, '2026-10-01', REPEAT('a', 64), 'Syntetický ruční výkaz')")->execute([$this->supplierId, $month['id'], $month['revision_no'], $employmentId]);
        $summary = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payroll_time_month_manual_segments (supplier_id, summary_id, sequence_no, date_from, date_to, worked_duration_units) VALUES (?, ?, 1, '2026-10-01', '2026-10-31', ?)")->execute([$this->supplierId, $summary, $units]);
    }

    private function ownMonth(int $employmentId, int $month, int $workedMillihours): void
    {
        $pdo = $this->db->pdo();
        $period = sprintf('2026-%02d-01', $month);
        $pdo->prepare(
            "INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no, approved_at, approved_by)
             SELECT ?, ?, ?, 'approved', 1, NOW(), MIN(id) FROM users",
        )->execute([$this->supplierId, $employmentId, $period]);
        $timeMonthId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_jmhz_work_month_revisions
                (supplier_id, employment_id, time_month_id, time_month_revision_no, period_start,
                 spec_package_id, spec_manifest_sha256, scenario_catalog_key, scenario_manifest_sha256,
                 derivation_version, source_snapshot_json, source_snapshot_sha256,
                 standard_fund_millihours, agreed_fund_millihours, weekly_work_centihours, evidence_days,
                 worked_millihours, confirmation_note, provenance_json, summary_sha256, approved_at)
             SELECT ?, ?, ?, 1, ?, MIN(id), REPEAT('a', 64), 'synthetic', REPEAT('a', 64),
                    'jmhz-work-month-core.v1', '{}', REPEAT('a', 64), 0, 0, 0, 30,
                    ?, 'Syntetický souhrn', '{}', REPEAT('b', 64), NOW()
               FROM payroll_jmhz_spec_packages",
        )->execute([$this->supplierId, $employmentId, $timeMonthId, $period, $workedMillihours]);
    }

    private function agreement(string $code): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "dpp", "active", "2026-01-01", "2026-01-01", 0, 0, 0)',
        )->execute([$this->supplierId, $this->employeeId, $code]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function takeover(int $employmentId, int $month, int $minutes): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref, external_relationship_ref,
                 employee_id, employment_id, gross_minor, worked_minutes)
             VALUES (?, "other", ?, ?, ?, ?, ?, 400000, ?)',
        )->execute([
            $this->supplierId,
            sprintf('2026-%02d-01', $month),
            'employee:' . $this->employeeId,
            'employment:' . $employmentId,
            $this->employeeId,
            $employmentId,
            $minutes,
        ]);
    }
}
