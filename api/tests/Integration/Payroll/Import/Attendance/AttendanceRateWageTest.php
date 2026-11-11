<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollHourlyWageCollisionGuard;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Agentura posílá podklady jen s hodinami a sazbou, bez částky mzdy. Import
 * dřív ze sazby udělal jen pravděpodobný výdělek a mzda nevznikla vůbec,
 * bez upozornění („Složky celkem 0,00 Kč"). Teď ji dopočte (sazba × hodiny)
 * a náhled i výsledek řeknou, jaká mzda vznikla, nebo proč ne.
 */
#[Group('integration')]
final class AttendanceRateWageTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = AttendanceFixture::PERIOD;

    private Connection $db;
    private AttendanceImportService $service;
    private PayrollPersonCreateService $people;
    private PayrollHourlyWageCollisionGuard $guard;
    private int $supplierId;
    private int $userId;
    private int $profileId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(AttendanceImportService::class);
        $this->people = $container->get(PayrollPersonCreateService::class);
        $this->guard = $container->get(PayrollHourlyWageCollisionGuard::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "IMP", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$this->supplierId, $officeId]);
        $this->profileId = (int) $this->service->restoreSampleProfile($this->supplierId, $this->userId, 'myucto')['profile']['id'];
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

    public function testHoursAndRateWithoutAmountBecomeWageInPreviewAndInputs(): void
    {
        $existing = $this->employment('B030', 'Hodinová Hana');
        $file = $this->file([
            ['B030', 'Hodinová Hana', 30.5, 200, null],
            ['B031', 'Částková Čeňka', 10, 200, 2_500],
            ['B032', 'Bezhodinová Bára', null, 200, null],
        ]);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $byNumber = array_column($preview['persons'], null, 'personal_number');

        // 30,5 h × 200 Kč = 6 100 Kč.
        self::assertSame(
            ['status' => 'computed', 'component_code' => 'MZDA_HODINOVA_DOCH', 'hours' => '30.50', 'hourly_rate_minor' => 20_000, 'amount_minor' => 610_000],
            $byNumber['B030']['rate_wage'] ?? null,
        );
        self::assertSame([['MZDA_HODINOVA_DOCH', 610_000]], array_map(
            static fn (array $component): array => [$component['component_code'], $component['amount_minor']],
            $byNumber['B030']['components'],
        ));
        // Částka v podkladech má přednost, sazba zůstává jen pravděpodobným výdělkem.
        self::assertSame('amount_in_source', $byNumber['B031']['rate_wage']['status'] ?? null);
        self::assertSame([['MZDA_HODINOVA_DOCH', 250_000]], array_map(
            static fn (array $component): array => [$component['component_code'], $component['amount_minor']],
            $byNumber['B031']['components'],
        ));
        self::assertSame('no_hours', $byNumber['B032']['rate_wage']['status'] ?? null);
        self::assertSame([], $byNumber['B032']['components']);
        self::assertStringContainsString('mzda ze sazby nevznikne', implode(' ', $byNumber['B032']['warnings']));
        self::assertSame(
            ['computed' => 1, 'amount_minor' => 610_000, 'no_hours' => 1, 'hourly_terms' => 0],
            $preview['summary']['rate_wages'],
        );

        $result = $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            [$file],
            null,
            [],
            false,
            true,
            $this->userId,
            createComponents: true,
            profileId: $this->profileId,
        );
        self::assertSame([], $result['inputs']['errors']);
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.amount_minor FROM payroll_inputs input
               JOIN payroll_component_definitions component ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ? AND component.code = "MZDA_HODINOVA_DOCH" AND input.status <> "cancelled"',
        );
        $stmt->execute([$this->supplierId, $existing]);
        self::assertSame([610_000], array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testHourlyTermsGetExplanationInsteadOfImportedWage(): void
    {
        $employmentId = $this->employment('B033', 'Sazbová Saša');
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET remuneration_type = "hourly", hourly_rate_minor = 20000, monthly_gross_minor = NULL,
                    remuneration_basis_kind = "agreement", remuneration_basis = "Dohoda o provedení práce"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);

        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$this->file([['B033', 'Sazbová Saša', 12, 200, null]])], null, $this->profileId);
        $person = $preview['persons'][0];
        self::assertSame('hourly_terms', $person['rate_wage']['status'] ?? null);
        self::assertSame([], $person['components']);
        self::assertStringContainsString('přepněte způsob odměňování na měsíční', implode(' ', $person['warnings']));
        self::assertSame(1, $preview['summary']['rate_wages']['hourly_terms']);
    }

    /**
     * Vztah s hodinami z importovaného souhrnu nesmí jít přepnout na hodinové
     * odměňování bez výslovného potvrzení (karta) a hromadná úprava sazbu
     * nenastaví: běh by mzdu z importovaného souhrnu nespočítal a schválit
     * nešel (slepá ulička agentury).
     */
    public function testImportedHoursSummaryBlocksHourlySwitch(): void
    {
        $employmentId = $this->employment('B034', 'Souhrnová Soňa');
        self::assertSame([], $this->guard->employmentsBlockingHourlySwitch($this->supplierId, [$employmentId], '2026-06-01'));
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months (supplier_id, employment_id, period_start, status, work_source, revision_no)
             VALUES (?, ?, "2026-06-01", "open", "import_summary", 1)',
        )->execute([$this->supplierId, $employmentId]);
        self::assertSame([$employmentId], $this->guard->employmentsBlockingHourlySwitch($this->supplierId, [$employmentId], '2026-06-01'));
        self::assertSame([$employmentId], $this->guard->employmentsBlockingHourlySwitch($this->supplierId, [$employmentId], '2026-08-01'));
        self::assertSame([], $this->guard->employmentsBlockingHourlySwitch($this->supplierId, [$employmentId], '2026-10-01'), 'Starší import než tři měsíce přepnutí nebrání.');
    }

    private function employment(string $code, string $name): int
    {
        [$last, $first] = explode(' ', $name, 2);
        $person = $this->people->create($this->supplierId, [
            'full_name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-05-01',
            'employment_code' => $code,
        ], $this->userId, null, null);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?');
        $stmt->execute([$this->supplierId, (int) $person['id']]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array{0:string,1:string,2:float|int|null,3:int,4:?int}> $rows číslo, jméno, hodiny, sazba, částka
     * @return array{name:string,content:string,sha256:string,extension:string}
     */
    private function file(array $rows): array
    {
        $sheet = [1 => ['A' => 'Osobní číslo', 'B' => 'Příjmení a jméno', 'C' => 'Druh vztahu', 'D' => 'Odpracováno (hod)', 'E' => 'Hodinová sazba (Kč/h)', 'F' => 'Hodinová mzda celkem (Kč)']];
        foreach ($rows as $index => [$number, $name, $hours, $rate, $amount]) {
            $sheet[$index + 2] = array_filter(
                ['A' => $number, 'B' => $name, 'C' => 'DPP', 'D' => $hours, 'E' => $rate, 'F' => $amount],
                static fn (mixed $value): bool => $value !== null,
            );
        }

        return AttendanceFixture::file('mesic.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => $sheet],
        ]));
    }
}
