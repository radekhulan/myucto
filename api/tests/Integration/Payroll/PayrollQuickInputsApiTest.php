<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollQuickInputsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollQuickInputsApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollQuickInputsAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $employmentId;
    private int $otherEmploymentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollQuickInputsAction::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId('supplier');
        $this->userId = $this->firstId('users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->employmentId = $this->employment($this->supplierId, 'Syntetická osoba', 'SYN-RYCHLE');
        $this->otherEmploymentId = $this->employment(
            $this->otherSupplierId,
            'Cizí osoba',
            'CIZI-RYCHLE',
        );
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

    public function testListsMaskedMonthlyRowsAndAtomicallyUpsertsDrafts(): void
    {
        $before = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        self::assertSame(200, $before->getStatusCode(), (string) $before->getBody());
        $month = PayrollTimeValue::row($this->json($before)['month'] ?? null, 'month');
        $rows = $month['items'] ?? null;
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertSame(4_200_000, $rows[0]['base_amount_minor']);
        self::assertArrayHasKey('birth_number_masked', $rows[0]);
        self::assertArrayNotHasKey('birth_number', $rows[0]);
        self::assertSame(1, $rows[0]['employment_row_version']);
        self::assertNull($rows[0]['overtime_hourly_rate_minor']);
        self::assertFalse($rows[0]['overtime_hours_available']);

        $saved = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 35_000,
                    'bonus_amount_minor' => 80_000,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $savedRows = PayrollTimeValue::row($this->json($saved)['month'] ?? null, 'month')['items'];
        self::assertIsArray($savedRows);
        self::assertSame(4_315_000, $savedRows[0]['gross_preview_minor']);
        self::assertSame(1, $savedRows[0]['inputs']['base']['row_version']);

        $replay = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 35_000,
                    'bonus_amount_minor' => 80_000,
                    'versions' => ['base' => 1, 'overtime' => 1, 'bonus' => 1],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(200, $replay->getStatusCode(), (string) $replay->getBody());

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ? AND period_start = "2026-06-01"'
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testRejectsHoursWithoutApprovedAverageAndNeverTouchesForeignOrApprovedInput(): void
    {
        $hours = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'hours',
                    'overtime_hours_milli' => 2_000,
                    'overtime_amount_minor' => null,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(422, $hours->getStatusCode());

        $foreign = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->otherEmploymentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 1,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(422, $foreign->getStatusCode());

        $initial = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'bonus_amount_minor' => 10_000,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(200, $initial->getStatusCode());
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs
                SET status = "approved", component_snapshot_json = "{}",
                    component_snapshot_hash = UNHEX(SHA2("{}", 256))
              WHERE supplier_id = ? AND employment_id = ? AND period_start = "2026-06-01"
                AND external_id = "quick-monthly:ODMENA"'
        )->execute([$this->supplierId, $this->employmentId]);
        $approved = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'bonus_amount_minor' => 20_000,
                    'versions' => ['base' => 1, 'overtime' => 1, 'bonus' => 1],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(409, $approved->getStatusCode());
    }

    /**
     * § 114 odst. 1 ZP — přesčas zadaný hodinami se rozpadá na DOSAŽENOU MZDU
     * a PŘÍPLATEK, každé do vlastní složky.
     *
     * Do W19 tady stálo jediné číslo 50 000 = průměrný výdělek × 1,25 × 2 hodiny
     * ve sběrné složce `PREMIE_PRIPLATKY`. Bylo špatně hned dvakrát: dosažená
     * mzda není průměrný výdělek (ten se podle § 353 zjišťuje z předchozího
     * čtvrtletí) a ze sběrné složky nešlo doložit, který nárok byl uspokojen.
     */
    public function testSplitsHourlyOvertimeIntoAchievedWageAndSurcharge(): void
    {
        $this->workCalendar('2026-06-01');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year, applicable_quarter,
                 revision_no, source_kind, decisive_from, decisive_to,
                 gross_earnings_minor, longer_period_allocated_minor,
                 worked_minutes, worked_days, average_hourly_minor,
                 support_status, status, ruleset_id, ruleset_hash,
                 input_hash, input_trace)
             VALUES (?, ?, 2026, 2, 1, "actual", "2026-01-01", "2026-03-31",
                     1000000, 0, 6000, 21, 20000,
                     "supported", "approved", "synthetic-2026",
                     REPEAT("a", 64), UNHEX(SHA2("synthetic", 256)), "{}")'
        )->execute([$this->supplierId, $this->employmentId]);
        $averageSnapshotId = (int) $this->db->pdo()->lastInsertId();

        $saved = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'hours',
                    'overtime_hours_milli' => 2_000,
                    'overtime_amount_minor' => null,
                    'overtime_average_snapshot_id' => $averageSnapshotId,
                    'overtime_average_snapshot_version' => 1,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $items = PayrollTimeValue::row($this->json($saved)['month'] ?? null, 'month')['items'];
        self::assertIsArray($items);
        self::assertTrue($items[0]['overtime_hours_available']);
        self::assertSame(2_000, $items[0]['overtime_hours_milli']);
        // Červen 2026: 22 pracovních dnů × 8 hodin = 10 560 minut fondu.
        // Dosažená mzda 42 000 Kč / 176 h × 2 h = 477,27 Kč.
        self::assertSame(47_727, $items[0]['overtime_wage_minor']);
        // Příplatek 25 % z průměrného výdělku 200 Kč/h × 2 h = 100 Kč.
        self::assertSame(10_000, $items[0]['overtime_premium_minor']);
        self::assertSame(57_727, $items[0]['overtime_amount_minor']);
        self::assertSame($averageSnapshotId, $items[0]['overtime_average_snapshot_id']);
        self::assertSame(1, $items[0]['overtime_average_snapshot_version']);

        // Sběrná složka se u hodinového režimu už NEPOUŽÍVÁ.
        self::assertNull($this->quickInput('PREMIE_PRIPLATKY'));

        $premium = $this->quickInput('PRIPLATEK_PRESCAS');
        self::assertIsArray($premium);
        self::assertSame(10_000, (int) $premium['amount_minor']);
        self::assertSame(2_000, (int) $premium['quantity_milliunits']);
        $snapshot = json_decode(
            (string) $premium['source_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame($averageSnapshotId, $snapshot['average_snapshot_id']);
        self::assertSame(20_000, $snapshot['average_hourly_minor']);
        self::assertSame(2_000, $snapshot['overtime_hours_milli']);
        // Sazba se čte ze sady pravidel, ne z konstanty v kódu.
        self::assertSame(2_500, $snapshot['premium_basis_points']);
        self::assertFalse($snapshot['premium_rate_is_agreed']);

        $wage = $this->quickInput('MZDA_HODINOVA');
        self::assertIsArray($wage);
        self::assertSame(47_727, (int) $wage['amount_minor']);
        $wageSnapshot = json_decode(
            (string) $wage['source_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(10_560, $wageSnapshot['fund_minutes']);
        self::assertSame(4_200_000, $wageSnapshot['monthly_base_minor']);
    }

    /** Sjednaná vyšší sazba se musí propsat i do rychlého zadání. */
    public function testHourlyOvertimeUsesAgreedSurchargeRate(): void
    {
        $this->workCalendar('2026-06-01');
        $averageSnapshotId = $this->approvedAverage(20_000);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_surcharge_policies
                (supplier_id, employment_id, valid_from, overtime_mode, holiday_mode,
                 overtime_rate_bp)
             VALUES (?, ?, "2020-01-01", "surcharge", "compensatory_time_off", 4000)'
        )->execute([$this->supplierId, $this->employmentId]);

        $saved = $this->saveHourlyOvertime($averageSnapshotId, 2_000);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $items = PayrollTimeValue::row($this->json($saved)['month'] ?? null, 'month')['items'];
        // 40 % z 200 Kč/h × 2 h = 160 Kč.
        self::assertSame(16_000, $items[0]['overtime_premium_minor']);
        self::assertSame(47_727, $items[0]['overtime_wage_minor']);
    }

    /**
     * § 114 odst. 2 — je-li sjednáno náhradní volno, příplatek NENÁLEŽÍ.
     * Dosažená mzda za odpracované hodiny ale ano.
     */
    public function testCompensatoryTimeOffPaysAchievedWageWithoutSurcharge(): void
    {
        $this->workCalendar('2026-06-01');
        $averageSnapshotId = $this->approvedAverage(20_000);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_surcharge_policies
                (supplier_id, employment_id, valid_from, overtime_mode, holiday_mode)
             VALUES (?, ?, "2020-01-01", "compensatory_time_off", "compensatory_time_off")'
        )->execute([$this->supplierId, $this->employmentId]);

        $saved = $this->saveHourlyOvertime($averageSnapshotId, 2_000);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $items = PayrollTimeValue::row($this->json($saved)['month'] ?? null, 'month')['items'];
        self::assertSame(0, $items[0]['overtime_premium_minor']);
        self::assertSame(47_727, $items[0]['overtime_wage_minor']);
    }

    /**
     * § 114 odst. 3 — mzda sjednaná s přihlédnutím k práci přesčas. Ani
     * příplatek, ani náhradní volno; tichá nula by ale vypadala jako výpočet.
     */
    public function testWageIncludingOvertimeRefusesHourlyEntry(): void
    {
        $this->workCalendar('2026-06-01');
        $averageSnapshotId = $this->approvedAverage(20_000);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_surcharge_policies
                (supplier_id, employment_id, valid_from, overtime_mode, holiday_mode)
             VALUES (?, ?, "2020-01-01", "included_in_wage", "compensatory_time_off")'
        )->execute([$this->supplierId, $this->employmentId]);

        $saved = $this->saveHourlyOvertime($averageSnapshotId, 2_000);
        self::assertSame(409, $saved->getStatusCode());
        self::assertStringContainsString(
            '§ 114 odst. 3',
            (string) $saved->getBody(),
        );
    }

    /** Bez kalendáře nejde určit fond, a tedy ani dosažená mzda. Fail-closed. */
    public function testHourlyOvertimeWithoutWorkCalendarFailsClosed(): void
    {
        $averageSnapshotId = $this->approvedAverage(20_000);
        $saved = $this->saveHourlyOvertime($averageSnapshotId, 2_000);
        self::assertSame(409, $saved->getStatusCode());
        self::assertStringContainsString('pracovní kalendář', (string) $saved->getBody());
    }

    /** Přepnutí z hodin na celkovou částku nesmí nechat viset řádky rozpadu. */
    public function testSwitchingBackToAmountClearsSplitRows(): void
    {
        $this->workCalendar('2026-06-01');
        $averageSnapshotId = $this->approvedAverage(20_000);
        self::assertSame(200, $this->saveHourlyOvertime($averageSnapshotId, 2_000)->getStatusCode());
        $premium = $this->quickInput('PRIPLATEK_PRESCAS');
        self::assertIsArray($premium);

        $saved = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 35_000,
                    'bonus_amount_minor' => 0,
                    'versions' => [
                        'base' => 1,
                        'overtime' => (int) $premium['row_version'],
                        'bonus' => null,
                    ],
                ]],
            ]),
            new Response(),
        );

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertNull($this->quickInput('PRIPLATEK_PRESCAS'));
        self::assertNull($this->quickInput('MZDA_HODINOVA'));
        $legacy = $this->quickInput('PREMIE_PRIPLATKY');
        self::assertIsArray($legacy);
        self::assertSame(35_000, (int) $legacy['amount_minor']);
    }

    private function saveHourlyOvertime(int $averageSnapshotId, int $hours): ResponseInterface
    {
        return $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'hours',
                    'overtime_hours_milli' => $hours,
                    'overtime_amount_minor' => null,
                    'overtime_average_snapshot_id' => $averageSnapshotId,
                    'overtime_average_snapshot_version' => 1,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
    }

    private function approvedAverage(int $hourlyMinor): int
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

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function workCalendar(string $validFrom): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_work_calendars
                (supplier_id, employment_id, name, week_pattern, weekly_minutes, valid_from)
             VALUES (?, ?, "Pondělí až pátek", ?, 2400, ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            json_encode([1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 0, 7 => 0]),
            $validFrom,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function quickInput(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.*
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = "2026-06-01"
                AND input.status <> "cancelled"
                AND input.external_id = ?
                AND component.code = ?'
        );
        $stmt->execute([
            $this->supplierId,
            $this->employmentId,
            'quick-monthly:' . $code,
            $code,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function testEffectiveRecurringBaseIsManagedElsewhereBeforeMaterialization(): void
    {
        $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        $component = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = "MZDA_MESICNI"'
        );
        $component->execute([$this->supplierId]);
        $componentId = (int) $component->fetchColumn();
        self::assertGreaterThan(0, $componentId);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, calculation_kind,
                 amount_minor, valid_from, allocation_rule, is_active)
             VALUES (?, ?, ?, "fixed_amount", 4100000, "2026-01-01", "full_month", 1)'
        )->execute([$this->supplierId, $this->employmentId, $componentId]);

        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $item = PayrollTimeValue::row(
            PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month')['items'][0],
            'item',
        );
        self::assertTrue($item['base_managed_elsewhere']);
        self::assertSame(4_100_000, $item['base_amount_minor']);
        self::assertNotEmpty($item['blockers']);

        $saved = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );
        self::assertSame(409, $saved->getStatusCode());
    }

    public function testPartialMonthRequiresExplicitBaseInsteadOfPrefillingFullMonthlyWage(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-06-15", actual_start_date = "2026-06-15"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $item = PayrollTimeValue::row(
            PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month')['items'][0],
            'item',
        );
        self::assertTrue($item['partial_month']);
        self::assertTrue($item['base_requires_entry']);
        self::assertSame(0, $item['base_amount_minor']);
        self::assertNotEmpty($item['blockers']);
    }

    public function testPartialMonthDoesNotPreviewFullRecurringBase(): void
    {
        $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        $component = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = "MZDA_MESICNI"'
        );
        $component->execute([$this->supplierId]);
        $componentId = (int) $component->fetchColumn();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, calculation_kind,
                 amount_minor, valid_from, allocation_rule, is_active)
             VALUES (?, ?, ?, "fixed_amount", 4100000,
                     "2026-01-01", "full_month", 1)'
        )->execute([$this->supplierId, $this->employmentId, $componentId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-06-15", actual_start_date = "2026-06-15"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        $item = PayrollTimeValue::row(
            PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month')['items'][0],
            'item',
        );

        self::assertTrue($item['partial_month']);
        self::assertSame(0, $item['base_amount_minor']);
        self::assertContains('base_recurring_manual_review', $item['blockers']);
    }

    public function testGrossPreviewClassifiesAllTaxableAndExcludedComponents(): void
    {
        $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        $employee = $this->db->pdo()->prepare(
            'SELECT employee_id FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $employee->execute([$this->supplierId, $this->employmentId]);
        $employeeId = (int) $employee->fetchColumn();
        $component = $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment, valid_from)
             VALUES (?, ?, ?, ?, ?, "one_off", ?,
                     "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "2026-01-01")'
        );
        $component->execute([
            $this->supplierId,
            'SYN_NEPO',
            'Syntetický nepeněžní příjem',
            'non_cash',
            'non_monetary',
            'included',
        ]);
        $nonCashId = (int) $this->db->pdo()->lastInsertId();
        $component->execute([
            $this->supplierId,
            'SYN_CESTA',
            'Syntetická cestovní náhrada',
            'travel_reimbursement',
            'monetary',
            'exempt',
        ]);
        $travelId = (int) $this->db->pdo()->lastInsertId();
        $input = $this->db->pdo()->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, external_id)
             VALUES (?, ?, ?, ?, "2026-06-01", ?, "manual", ?)'
        );
        $input->execute([
            $this->supplierId,
            $employeeId,
            $this->employmentId,
            $nonCashId,
            10_000,
            'synthetic-noncash',
        ]);
        $input->execute([
            $this->supplierId,
            $employeeId,
            $this->employmentId,
            $travelId,
            20_000,
            'synthetic-travel',
        ]);

        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        $item = PayrollTimeValue::row(
            PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month')['items'][0],
            'item',
        );

        self::assertSame(10_000, $item['other_amount_minor']);
        self::assertSame(10_000, $item['non_monetary_amount_minor']);
        self::assertSame(20_000, $item['excluded_from_gross_amount_minor']);
        self::assertSame(4_210_000, $item['gross_preview_minor']);
    }

    public function testListsHistoricallyEffectiveEmploymentAfterItWasArchived(): void
    {
        $this->employmentEvent($this->employmentId, 'created', null, 'planned', '2026-01-01');
        $this->employmentEvent(
            $this->employmentId,
            'status_changed',
            'planned',
            'active',
            '2026-01-01',
        );
        $this->employmentEvent(
            $this->employmentId,
            'status_changed',
            'active',
            'ended',
            '2026-06-30',
        );
        $this->employmentEvent(
            $this->employmentId,
            'status_changed',
            'ended',
            'archived',
            '2026-07-15',
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET status = "archived", end_date = "2026-06-30", row_version = 5
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $items = PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month')['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertSame('ended', $items[0]['effective_status']);
        self::assertSame(5, $items[0]['employment_row_version']);
    }

    public function testSuspensionInMonthRequiresExplicitBase(): void
    {
        $this->employmentEvent($this->employmentId, 'created', null, 'planned', '2026-01-01');
        $this->employmentEvent(
            $this->employmentId,
            'status_changed',
            'planned',
            'active',
            '2026-01-01',
        );
        $this->employmentEvent(
            $this->employmentId,
            'status_changed',
            'active',
            'suspended',
            '2026-06-10',
        );
        $this->employmentEvent(
            $this->employmentId,
            'status_changed',
            'suspended',
            'active',
            '2026-06-20',
        );

        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $item = PayrollTimeValue::row(
            PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month')['items'][0],
            'item',
        );
        self::assertTrue($item['suspended_in_month']);
        self::assertTrue($item['base_requires_entry']);
        self::assertSame(0, $item['base_amount_minor']);
        self::assertContains('suspended_month_base_required', $item['blockers']);
    }

    public function testSuspensionInMonthBlocksFullRecurringBase(): void
    {
        $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        $component = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = "MZDA_MESICNI"'
        );
        $component->execute([$this->supplierId]);
        $componentId = (int) $component->fetchColumn();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, calculation_kind,
                 amount_minor, valid_from, allocation_rule, is_active)
             VALUES (?, ?, ?, "fixed_amount", 4100000,
                     "2026-01-01", "full_month", 1)'
        )->execute([$this->supplierId, $this->employmentId, $componentId]);
        $this->employmentEvent($this->employmentId, 'created', null, 'active', '2026-01-01');
        $this->employmentEvent(
            $this->employmentId,
            'status_changed',
            'active',
            'suspended',
            '2026-06-10',
        );
        $this->employmentEvent(
            $this->employmentId,
            'status_changed',
            'suspended',
            'active',
            '2026-06-20',
        );

        $response = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        $item = PayrollTimeValue::row(
            PayrollTimeValue::row($this->json($response)['month'] ?? null, 'month')['items'][0],
            'item',
        );

        self::assertTrue($item['suspended_in_month']);
        self::assertSame(0, $item['base_amount_minor']);
        self::assertContains('base_recurring_manual_review', $item['blockers']);
    }

    public function testRejectsStaleEmploymentVersionBeforeSavingInputs(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET monthly_gross_minor = 4300000, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $response = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $this->employmentId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'amount',
                    'overtime_hours_milli' => null,
                    'overtime_amount_minor' => 0,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = PayrollTimeValue::row($this->json($response)['error'] ?? null, 'error');
        self::assertSame('employment_row_version_conflict', $error['code']);
        self::assertSame(2, $error['current_row_version']);
    }

    public function testOnlyEmploymentCanUseOvertimeHours(): void
    {
        $statutoryId = $this->employment(
            $this->supplierId,
            'Syntetický jednatel',
            'SYN-JEDNATEL',
            'statutory_body',
        );
        $agreementId = $this->employment(
            $this->supplierId,
            'Syntetická dohoda',
            'SYN-DPP',
            'dpp',
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year, applicable_quarter,
                 revision_no, source_kind, decisive_from, decisive_to,
                 gross_earnings_minor, longer_period_allocated_minor,
                 worked_minutes, worked_days, average_hourly_minor,
                 support_status, status, ruleset_id, ruleset_hash,
                 input_hash, input_trace)
             VALUES (?, ?, 2026, 2, 1, "actual", "2026-01-01", "2026-03-31",
                     1000000, 0, 6000, 21, 20000,
                     "supported", "approved", "synthetic-2026",
                     REPEAT("a", 64), UNHEX(SHA2("synthetic", 256)), "{}")'
        )->execute([$this->supplierId, $statutoryId]);
        $averageSnapshotId = (int) $this->db->pdo()->lastInsertId();

        $listed = $this->action->list(
            $this->request('GET')->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        self::assertSame(200, $listed->getStatusCode(), (string) $listed->getBody());
        $items = PayrollTimeValue::row($this->json($listed)['month'] ?? null, 'month')['items'];
        self::assertIsArray($items);
        $statutory = array_values(array_filter(
            $items,
            static fn(array $item): bool => $item['employment_id'] === $statutoryId,
        ))[0];
        self::assertFalse($statutory['overtime_hours_available']);
        $agreement = array_values(array_filter(
            $items,
            static fn(array $item): bool => $item['employment_id'] === $agreementId,
        ))[0];
        self::assertFalse($agreement['overtime_hours_relation_supported']);
        self::assertFalse($agreement['overtime_hours_available']);

        $response = $this->action->save(
            $this->request('PUT')->withParsedBody([
                'period' => '2026-06',
                'rows' => [[
                    'employment_id' => $statutoryId,
                    'employment_row_version' => 1,
                    'base_amount_minor' => 4_200_000,
                    'overtime_mode' => 'hours',
                    'overtime_hours_milli' => 2_000,
                    'overtime_amount_minor' => null,
                    'overtime_average_snapshot_id' => $averageSnapshotId,
                    'overtime_average_snapshot_version' => 1,
                    'bonus_amount_minor' => 0,
                    'versions' => ['base' => null, 'overtime' => null, 'bonus' => null],
                ]],
            ]),
            new Response(),
        );

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = PayrollTimeValue::row($this->json($response)['error'] ?? null, 'error');
        self::assertStringContainsString('nelze přesčas zadat podle hodin', $error['message']);
    }

    private function employment(
        int $supplierId,
        string $name,
        string $code,
        string $relationType = 'employment',
    ): int
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
             VALUES (?, ?, ?, ?, "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$supplierId, $employeeId, $code, $relationType]);
        return (int) $pdo->lastInsertId();
    }

    private function employmentEvent(
        int $employmentId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        string $effectiveOn,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status, to_status,
                 effective_on, diff_json)
             VALUES (?, ?, ?, ?, ?, ?, "{}")'
        )->execute([
            $this->supplierId,
            $employmentId,
            $eventType,
            $fromStatus,
            $toStatus,
            $effectiveOn,
        ]);
    }

    private function request(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/quick-inputs')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
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

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        return PayrollTimeValue::row($body, 'response');
    }
}
