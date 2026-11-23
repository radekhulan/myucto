<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollOpeningBalanceAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Import\OpeningBalance\OpeningBalanceTabularImportService;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Prázdný měsíc počátečních stavů není nula.
 *
 * Mřížka převáděla nevyplněné buňky na nulu a chybějící měsíc prostě nechala
 * chybět; tabulkový import totéž. Obojí prošlo bez varování a roční zúčtování,
 * vyúčtování daně i roční maximum pojistného pak počítaly s „doloženým" rokem,
 * ve kterém nikdo nic nedoložil.
 */
#[Group('integration')]
final class PayrollOpeningBalanceExplicitMonthsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CODE = 'HPP-EXP';

    private Connection $db;
    private PayrollOpeningBalanceService $service;
    private OpeningBalanceTabularImportService $imports;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private int $supplierId;
    private int $employeeId;
    private int $userId;

    protected function setUp(): void
    {
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(PayrollOpeningBalanceService::class);
            $this->imports = $container->get(OpeningBalanceTabularImportService::class);
            $this->accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        // Mzdy vede MyÚčto od dubna; vztah trvá od loňska, takže leden až
        // březen jsou převzaté měsíce, ve kterých vztah trval.
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, "active", "2026-04-01")')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Převzatá osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "employment", "active", "2025-05-01", "2025-05-01", 4000000, 0, 1)',
        )->execute([$this->supplierId, $this->employeeId, self::CODE]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testUnconfirmedEmptyMonthIsRejected(): void
    {
        $months = [$this->month(1), $this->zero(2), $this->month(3)];

        try {
            $this->service->save($this->supplierId, $this->employeeId, 2026, $months, '', null, true);
            self::fail('Prázdný měsíc se nesmí uložit jako nula.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Měsíc 2 nemá vyplněnou žádnou částku', $exception->getMessage());
        }
        self::assertNull($this->accumulators->openingBalance($this->supplierId, $this->employeeId, 2026, 'income_tax'));
    }

    public function testConfirmedZeroMonthIsSavedWithoutTheFlag(): void
    {
        $zero = $this->zero(2);
        $zero['confirmed_zero'] = true;

        $saved = $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(1), $zero, $this->month(3)],
            '',
            null,
            true,
        );

        self::assertSame([1, 2, 3], array_column($saved['months'], 'month'));
        self::assertArrayNotHasKey('confirmed_zero', $saved['months'][1]);
        self::assertSame(3, $this->accumulators
            ->openingBalance($this->supplierId, $this->employeeId, 2026, 'income_tax')['values']['completed_months']);
    }

    public function testMonthOfLastingEmploymentMustNotBeMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('v měsících 1');
        // Únor a březen vyplněné, leden chybí — vztah přitom trval.
        $this->service->save(
            $this->supplierId,
            $this->employeeId,
            2026,
            [$this->month(2), $this->month(3)],
            '',
            null,
            true,
        );
    }

    public function testGridEndpointRequiresExplicitMonths(): void
    {
        $action = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(PayrollOpeningBalanceAction::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/payroll/people/' . $this->employeeId . '/statutory-openings')
            ->withParsedBody([
                'year' => 2026,
                'source_reference' => '',
                'months' => [$this->month(1), $this->zero(2), $this->month(3)],
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);

        $response = $action->save($request, new Response(), ['id' => (string) $this->employeeId]);
        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Měsíc 2', (string) $payload['error']['message']);

        $confirmed = $this->zero(2);
        $confirmed['confirmed_zero'] = true;
        $ok = $action->save(
            $request->withParsedBody([
                'year' => 2026,
                'source_reference' => '',
                'months' => [$this->month(1), $confirmed, $this->month(3)],
            ]),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(200, $ok->getStatusCode());
    }

    public function testCsvEmptyRowIsNotZeroButExplicitZerosAre(): void
    {
        $empty = $this->csvRow(2);
        foreach (PayrollOpeningBalanceService::monthFields() as $field) {
            $empty[$field] = '';
        }
        $preview = $this->imports->preview(
            $this->supplierId,
            'csv',
            'prevod.csv',
            $this->csv([$this->csvRow(1), $empty, $this->csvRow(3)]),
        );
        self::assertCount(1, $preview['errors']);
        self::assertStringContainsString('Prázdný řádek není nula', $preview['errors'][0]['error_message']);

        $zeros = $this->csvRow(2);
        foreach (PayrollOpeningBalanceService::monthFields() as $field) {
            $zeros[$field] = '0';
        }
        $result = $this->imports->apply(
            $this->supplierId,
            'csv',
            'prevod.csv',
            $this->csv([$this->csvRow(1), $zeros, $this->csvRow(3)]),
            null,
        );
        self::assertSame(1, $result['saved']);
    }

    public function testCsvMissingMonthBlocksThePerson(): void
    {
        $preview = $this->imports->preview(
            $this->supplierId,
            'csv',
            'prevod.csv',
            $this->csv([$this->csvRow(2), $this->csvRow(3)]),
        );

        self::assertSame([], $preview['errors']);
        self::assertSame('blocked', $preview['people'][0]['status']);
        self::assertStringContainsString('v měsících 1', (string) $preview['people'][0]['reason']);
    }

    /** @return array<string,int> */
    private function month(int $month): array
    {
        return [
            'month' => $month,
            'social_assessment_base_minor_units' => 40_000_00,
            'health_assessment_base_minor_units' => 40_000_00,
            'health_employee_contribution_minor_units' => 1_800_00,
            'health_employer_contribution_minor_units' => 3_600_00,
            'health_minimum_top_up_minor_units' => 0,
            'advance_base_minor_units' => 40_000_00,
            'advance_tax_minor_units' => 3_430_00,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'applied_non_refundable_credits_minor_units' => 2_570_00,
            'applied_child_credit_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'bonus_qualifying_income_minor_units' => 40_000_00,
        ];
    }

    /** @return array<string,int> */
    private function zero(int $month): array
    {
        return ['month' => $month] + array_map(static fn (int $value): int => 0, $this->month($month));
    }

    /** @return array<string,string|int> */
    private function csvRow(int $month): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employment_code' => self::CODE,
            'year' => 2026,
        ] + $this->month($month);
    }

    /** @param list<array<string,string|int>> $rows */
    private function csv(array $rows): string
    {
        $columns = OpeningBalanceTabularImportService::columns();
        $lines = [implode(';', $columns)];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(
                static fn (string $column): string => (string) ($row[$column] ?? '0'),
                $columns,
            ));
        }

        return implode("\r\n", $lines) . "\r\n";
    }
}
