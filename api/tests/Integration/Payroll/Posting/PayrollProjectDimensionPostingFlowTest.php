<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Posting;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Reports\ProjectProfitService;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Settings\PayrollDimensionService;
use MyInvoice\Service\Payroll\Settings\PayrollEmploymentDimensionService;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mzdy na zakázky celým tokem: mzdová dimenze Zakázka navázaná na hodnotu
 * firemní dimenze druhu Projekt, která nese zakázku → rozpad 50 / 50 →
 * mzdový běh → zaúčtování → `journal_entry_line_dimensions` a `project_id`
 * na řádcích 521 a 524 → výsledovka zakázek.
 *
 * Bez vazby na firemní dimenzi se zakázka do deníku nedostane; kontrola před
 * během to musí říct a uložení mzdové dimenze nesmí přijmout vazbu na
 * firemní dimenzi jiného druhu.
 *
 * Celý test běží v transakci fixture a na konci se vrací.
 */
#[Group('integration')]
final class PayrollProjectDimensionPostingFlowTest extends TestCase
{
    use EnforcementRunFixtureTrait;

    private int $projectTypeId;
    private int $centreTypeId;
    private int $projectA;
    private int $projectB;
    private int $valueA;
    private int $valueB;
    private int $centreValue;

    protected function setUp(): void
    {
        $this->bootEnforcementRun();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', dimensions_enabled = 1 WHERE id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_accounting_modes WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->service(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->service(AccountingPeriodRepository::class)
            ->create($this->supplierId, 2026, '2026-01-01', '2026-12-31');

        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK'")->fetchColumn();
        $countryId = (int) $pdo->query('SELECT MIN(id) FROM countries')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, is_customer)
             VALUES (?, "Syntetický odběratel", "Testovací 1", "Praha", "11000", ?, ?, 1)'
        )->execute([$this->supplierId, $countryId, $currencyId]);
        $clientId = (int) $pdo->lastInsertId();
        $project = $pdo->prepare('INSERT INTO projects (client_id, name, currency_id) VALUES (?, ?, ?)');
        $project->execute([$clientId, 'Zakázka A', $currencyId]);
        $this->projectA = (int) $pdo->lastInsertId();
        $project->execute([$clientId, 'Zakázka B', $currencyId]);
        $this->projectB = (int) $pdo->lastInsertId();

        $this->projectTypeId = $this->companyType('ZAK', 'Zakázky', 'project');
        $this->centreTypeId = $this->companyType('STR', 'Střediska', 'cost_center');
        $this->valueA = $this->companyValue($this->projectTypeId, 'ZAK-A', $this->projectA);
        $this->valueB = $this->companyValue($this->projectTypeId, 'ZAK-B', $this->projectB);
        $this->centreValue = $this->companyValue($this->centreTypeId, 'STR-1', null);
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    public function testFiftyFiftyProjectSplitReachesTheJournalAndProjectProfit(): void
    {
        $this->splitFiftyFifty(
            $this->payrollDimension('project', 'ZAK-A', $this->valueA),
            $this->payrollDimension('project', 'ZAK-B', $this->valueB),
        );
        self::assertSame([], $this->linkFindings(), 'Navázané zakázky kontrola před během nehlásí.');

        $lines = $this->postedLines();
        foreach (['521', '524'] as $account) {
            $byProject = $this->byProject($lines, $account);
            self::assertSame([$this->projectA, $this->projectB], array_keys($byProject), "Účet {$account} nese obě zakázky.");
            self::assertEqualsWithDelta($byProject[$this->projectA], $byProject[$this->projectB], 2, "Účet {$account} se dělí 50 / 50.");
            foreach ($lines as $line) {
                if (str_starts_with($line['account_code'], $account) && $line['side'] === 'debit') {
                    self::assertSame(
                        $line['project_id'] === $this->projectA ? $this->valueA : $this->valueB,
                        $line['dimensions'][$this->projectTypeId] ?? null,
                        'Řádek nese firemní dimenzi zakázky.',
                    );
                }
            }
        }
        self::assertSame(1_750_000, $this->byProject($lines, '521')[$this->projectA]);

        $profit = $this->service(ProjectProfitService::class)->overview($this->supplierId);
        self::assertSame('journal', $profit['source']);
        $costs = [];
        foreach ($profit['items'] as $item) {
            $costs[$item['id']] = (int) round($item['cost'] * 100);
        }
        ksort($costs);
        $expected = [];
        foreach ([$this->projectA, $this->projectB] as $projectId) {
            $expected[$projectId] = array_sum(array_map(
                static fn (array $line): int => $line['amount_minor'],
                array_filter(
                    $lines,
                    static fn (array $line): bool => $line['project_id'] === $projectId
                        && str_starts_with($line['account_code'], '5')
                        && $line['side'] === 'debit',
                ),
            ));
        }
        self::assertSame($expected, $costs, 'Výsledovka zakázek vidí mzdový náklad obou zakázek.');
        self::assertGreaterThan(1_750_000, $costs[$this->projectA], 'Náklad zakázky obsahuje mzdu i pojistné.');
    }

    /** Bez vazby na firemní dimenzi zůstane jen textové středisko. */
    public function testWithoutCompanyLinkOnlyTheTextCostCentreRemains(): void
    {
        $centre = $this->payrollDimension('cost_center', 'STR-1', null);
        $this->service(PayrollEmploymentDimensionService::class)->create(
            $this->supplierId,
            $this->employmentId,
            ['dimension_id' => $centre, 'valid_from' => '2026-01-01'],
            $this->actorId,
        );
        $this->splitFiftyFifty(
            $this->payrollDimension('project', 'ZAK-A', null),
            $this->payrollDimension('project', 'ZAK-B', null),
        );

        $findings = $this->linkFindings();
        self::assertSame(['payroll_dimension_company_link_missing'], array_column($findings, 'code'));
        self::assertSame('warning', $findings[0]['severity']);
        self::assertSame('revision', $findings[0]['impact']);
        self::assertSame(3, $findings[0]['count']);
        self::assertSame('/payroll/settings?tab=dimensions', $findings[0]['remediation_path']);
        self::assertStringContainsString('Zakázka ZAK-A', $findings[0]['message']);
        self::assertStringContainsString('Středisko STR-1', $findings[0]['message']);

        $lines = $this->postedLines();
        $gross = 0;
        foreach ($lines as $line) {
            self::assertNull($line['project_id'], 'Bez vazby se zakázka do deníku nedostane.');
            self::assertSame([], $line['dimensions']);
            if (str_starts_with($line['account_code'], '521') && $line['side'] === 'debit') {
                self::assertSame('STR-1', $line['cost_center']);
                $gross += $line['amount_minor'];
            }
        }
        self::assertSame(3_500_000, $gross);
        self::assertSame([], $this->service(ProjectProfitService::class)->overview($this->supplierId)['items']);
    }

    /** Firma bez zapnutých dimenzí: kontrola před během je beze změny. */
    public function testFirmWithoutDimensionsGetsNoLinkFinding(): void
    {
        $this->splitFiftyFifty(
            $this->payrollDimension('project', 'ZAK-A', null),
            $this->payrollDimension('project', 'ZAK-B', null),
        );
        $this->db->pdo()->prepare('UPDATE supplier SET dimensions_enabled = 0 WHERE id = ?')
            ->execute([$this->supplierId]);

        self::assertSame([], $this->linkFindings());
    }

    /** Dřív uložená vazba na jiný druh se nemění, jen ji ukáže kontrola před během. */
    public function testLegacyMismatchedLinkIsReportedAndStillEditable(): void
    {
        $dimension = $this->payrollDimension('project', 'ZAK-X', null);
        $this->db->pdo()->prepare(
            'UPDATE payroll_dimensions SET dimension_value_id = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->centreValue, $this->supplierId, $dimension]);
        $this->service(PayrollEmploymentDimensionService::class)->create(
            $this->supplierId,
            $this->employmentId,
            ['dimension_id' => $dimension, 'valid_from' => '2026-01-01'],
            $this->actorId,
        );

        $findings = $this->linkFindings();
        self::assertSame(['payroll_dimension_company_link_mismatch'], array_column($findings, 'code'));
        self::assertSame('warning', $findings[0]['severity']);
        self::assertStringContainsString('Zakázka ZAK-X', $findings[0]['message']);
        self::assertStringContainsString('Střediska', $findings[0]['message']);

        $current = $this->service(\MyInvoice\Repository\Payroll\PayrollDimensionRepository::class)
            ->find($this->supplierId, $dimension);
        self::assertNotNull($current);
        $renamed = $this->service(PayrollDimensionService::class)->save(
            $this->supplierId,
            $dimension,
            $this->dimensionInput('project', 'ZAK-X', $this->centreValue, 'Přejmenovaná zakázka'),
            $current['row_version'],
            $this->actorId,
        );
        self::assertSame($this->centreValue, $renamed['dimension_value_id'], 'Úprava názvu vazbu nemění.');
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function mismatchedLinks(): iterable
    {
        yield 'zakázka na středisko' => ['project', 'centre'];
        yield 'středisko na projekt' => ['cost_center', 'project'];
        yield 'činnost na projekt' => ['activity', 'project'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mismatchedLinks')]
    public function testLinkToCompanyValueOfAnotherKindIsRejected(string $payrollType, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lze navázat jen na hodnotu firemní dimenze druhu');
        if ($payrollType === 'activity') {
            $this->expectExceptionMessage('nelze navázat na firemní dimenzi druhu Projekt ani Středisko');
        }
        $this->payrollDimension($payrollType, 'DIM-X', $value === 'centre' ? $this->centreValue : $this->valueA);
    }

    public function testChangingTheTypeOfALinkedDimensionIsChecked(): void
    {
        $dimension = $this->payrollDimension('project', 'ZAK-A', $this->valueA);
        $current = $this->service(\MyInvoice\Repository\Payroll\PayrollDimensionRepository::class)
            ->find($this->supplierId, $dimension);
        self::assertNotNull($current);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('druhu Středisko');
        $this->service(PayrollDimensionService::class)->save(
            $this->supplierId,
            $dimension,
            $this->dimensionInput('cost_center', 'ZAK-A', $this->valueA),
            $current['row_version'],
            $this->actorId,
        );
    }

    public function testLinkToCompanyValueOfTheMatchingKindIsAccepted(): void
    {
        $this->payrollDimension('cost_center', 'STR-1', $this->centreValue);
        $this->payrollDimension('project', 'ZAK-A', $this->valueA);
        $this->addToAssertionCount(1);
    }

    /** @return list<array<string,mixed>> */
    private function linkFindings(): array
    {
        $result = $this->service(PayrollRunReadinessService::class)
            ->inspect($this->supplierId, '2026-06-01', '2026-07-15');

        return array_values(array_filter(
            $result['findings'],
            static fn (array $finding): bool => str_starts_with($finding['code'], 'payroll_dimension_company_link_'),
        ));
    }

    /**
     * @return list<array{account_code:string,side:string,amount_minor:int,cost_center:?string,project_id:?int,dimensions:array<int,int>}>
     */
    private function postedLines(): array
    {
        $run = $this->calculateEnforcementRun();
        $this->approveEnforcementRun($run);
        $stmt = $this->db->pdo()->prepare(
            'SELECT input_snapshot_json, result_snapshot_json
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $run['revision_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertNotNull($this->service(PayrollApprovedRevisionPostingService::class)->postManually(
            $this->supplierId,
            $run['revision_id'],
            json_decode((string) $row['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) $row['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR),
            $this->actorId,
        ));

        $stmt = $this->db->pdo()->prepare(
            "SELECT line.id, account.account_code, line.side,
                    CAST(ROUND(line.amount * 100) AS SIGNED) AS amount_minor,
                    line.cost_center, line.project_id,
                    GROUP_CONCAT(CONCAT(dim.dimension_type_id, ':', dim.dimension_value_id)) AS dimensions
               FROM journal_entry_lines line
               JOIN journal_entries entry
                 ON entry.supplier_id = line.supplier_id AND entry.id = line.entry_id
               JOIN chart_of_accounts account
                 ON account.supplier_id = line.supplier_id AND account.id = line.account_id
          LEFT JOIN journal_entry_line_dimensions dim
                 ON dim.supplier_id = line.supplier_id AND dim.line_id = line.id
              WHERE line.supplier_id = ?
                AND entry.source_type = 'payroll'
                AND entry.source_id = ?
              GROUP BY line.id, account.account_code, line.side, line.amount, line.cost_center, line.project_id
              ORDER BY line.id"
        );
        $stmt->execute([$this->supplierId, $run['revision_id']]);
        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $line) {
            $dimensions = [];
            foreach (array_filter(explode(',', (string) $line['dimensions'])) as $pair) {
                [$type, $value] = array_map('intval', explode(':', $pair));
                $dimensions[$type] = $value;
            }
            $result[] = [
                'account_code' => (string) $line['account_code'],
                'side' => (string) $line['side'],
                'amount_minor' => (int) $line['amount_minor'],
                'cost_center' => $line['cost_center'] === null ? null : (string) $line['cost_center'],
                'project_id' => $line['project_id'] === null ? null : (int) $line['project_id'],
                'dimensions' => $dimensions,
            ];
        }
        self::assertNotSame([], $result, 'Mzdový předpis se nezaúčtoval.');

        return $result;
    }

    /**
     * @param list<array{account_code:string,side:string,amount_minor:int,project_id:?int}> $lines
     * @return array<int,int> zakázka → částka MD
     */
    private function byProject(array $lines, string $accountPrefix): array
    {
        $result = [];
        foreach ($lines as $line) {
            if (str_starts_with($line['account_code'], $accountPrefix) && $line['side'] === 'debit') {
                $result[$line['project_id'] ?? 0] = ($result[$line['project_id'] ?? 0] ?? 0) + $line['amount_minor'];
            }
        }
        ksort($result);

        return $result;
    }

    private function splitFiftyFifty(int $dimensionA, int $dimensionB): void
    {
        $this->service(PayrollEmploymentDimensionService::class)->split(
            $this->supplierId,
            $this->employmentId,
            [
                'dimension_type' => 'project',
                'valid_from' => '2026-01-01',
                'shares' => [
                    ['dimension_id' => $dimensionA, 'share_percent' => 50],
                    ['dimension_id' => $dimensionB, 'share_percent' => 50],
                ],
            ],
            $this->actorId,
        );
    }

    private function companyType(string $code, string $name, string $kind): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO dimension_types (supplier_id, code, name, kind) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierId, $code, $name, $kind]);

        return (int) $pdo->lastInsertId();
    }

    private function companyValue(int $typeId, string $code, ?int $projectId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO dimension_values (type_id, supplier_id, code, name, project_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$typeId, $this->supplierId, $code, 'Hodnota ' . $code, $projectId]);

        return (int) $pdo->lastInsertId();
    }

    private function payrollDimension(string $type, string $code, ?int $valueId): int
    {
        $dimension = $this->service(PayrollDimensionService::class)->save(
            $this->supplierId,
            null,
            $this->dimensionInput($type, $code, $valueId),
            0,
            $this->actorId,
        );
        self::assertSame($valueId, $dimension['dimension_value_id']);

        return (int) $dimension['id'];
    }

    /** @return array<string,mixed> */
    private function dimensionInput(string $type, string $code, ?int $valueId, ?string $name = null): array
    {
        return [
            'dimension_type' => $type,
            'code' => $code,
            'name' => $name ?? $code,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'is_active' => true,
            'default_account_code' => null,
            'dimension_value_id' => $valueId,
        ];
    }
}
