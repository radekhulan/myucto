<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Posting;

use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\Pair\JournalPairer;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use MyInvoice\Service\Payroll\Posting\PayrollPostingEmployees;
use MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Volba firmy zaúčtovat mzdový předpis po zaměstnancích: tytéž částky, jen každá
 * osoba má vlastní dvojice (single) nebo řádky (složená forma) s osobním číslem.
 */
final class PayrollPostingByEmployeeTest extends TestCase
{
    private PayrollPostingLineBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PayrollPostingLineBuilder();
    }

    public function testEmployeesOwnTheirAllocationsByEmploymentOrPerson(): void
    {
        $employees = PayrollPostingEmployees::fromSnapshot($this->snapshot());

        self::assertSame(11, $employees->employeeOf('gross:employment:101:input:1:debit'));
        self::assertSame(12, $employees->employeeOf('employer-insurance:social:employment:201:part:0:debit'));
        self::assertSame(12, $employees->employeeOf('employee:12:social-insurance:liability'));
        self::assertSame(11, $employees->employeeOf('risky-savings:employment:102:credit'));
        self::assertNull($employees->employeeOf('employer-insurance:social:credit'));
        self::assertNull($employees->employeeOf('gross:employment:999:input:1:debit'));
        self::assertSame([11 => 'A-2', 12 => 'B-1'], $employees->codes());
    }

    public function testSingleFormGivesEveryEmployeeOwnPairs(): void
    {
        $employees = PayrollPostingEmployees::fromSnapshot($this->snapshot());
        $lines = $this->builder->singleEntryLines($this->allocations(), [], $employees);
        self::assertNotNull($lines);

        $pairs = [];
        for ($i = 0; $i < count($lines); $i += 2) {
            self::assertSame($lines[$i]['line_text'] ?? null, $lines[$i + 1]['line_text'] ?? null);
            $key = ($lines[$i]['line_text'] ?? '-') . ' ' . $lines[$i]['account_code'] . '/' . $lines[$i + 1]['account_code'];
            $pairs[$key] = $lines[$i]['amount_minor'];
        }
        self::assertSame([
            'Os. č. A-2 331/336.100' => 10_000,
            'Os. č. A-2 521/331' => 100_000,
            'Os. č. A-2 524/336.100' => 30_000,
            'Os. č. B-1 331/336.100' => 20_000,
            'Os. č. B-1 521/331' => 200_000,
            'Os. č. B-1 524/336.100' => 60_000,
        ], $pairs);
        $this->assertPairs($lines);

        $summary = $this->builder->singleEntryLines($this->allocations(), []);
        self::assertNotNull($summary);
        self::assertSame($this->net($summary), $this->net($lines));
        foreach ($summary as $line) {
            self::assertArrayNotHasKey('line_text', $line, 'Souhrnné zaúčtování osobu nenese.');
        }
    }

    public function testCompositeFormSplitsLinesByEmployeeAndKeepsFirmLiability(): void
    {
        $employees = PayrollPostingEmployees::fromSnapshot($this->snapshot());
        $lines = $this->builder->employeeDeltaLines($this->allocations(), [], $employees);

        $map = [];
        foreach ($lines as $line) {
            $map[($line['line_text'] ?? '-') . ' ' . $line['account_code'] . ' ' . $line['side']] = $line['amount_minor'];
        }
        self::assertSame([
            'Os. č. A-2 331 credit' => 100_000,
            'Os. č. A-2 331 debit' => 10_000,
            'Os. č. A-2 336.100 credit' => 10_000,
            'Os. č. A-2 521 debit' => 100_000,
            'Os. č. A-2 524 debit' => 30_000,
            'Os. č. B-1 331 credit' => 200_000,
            'Os. č. B-1 331 debit' => 20_000,
            'Os. č. B-1 336.100 credit' => 20_000,
            'Os. č. B-1 521 debit' => 200_000,
            'Os. č. B-1 524 debit' => 60_000,
            '- 336.100 credit' => 90_000,
        ], $map);
    }

    public function testCorrectionPostsOnlyTheEmployeeWhoseAmountsChanged(): void
    {
        $employees = PayrollPostingEmployees::fromSnapshot($this->snapshot());
        $previous = $this->allocations();
        $target = $this->allocations(210_000);

        $lines = $this->builder->singleEntryLines($target, $previous, $employees);
        self::assertNotNull($lines);
        self::assertSame([
            ['account_code' => '521', 'side' => 'debit', 'amount_minor' => 10_000, 'line_text' => 'Os. č. B-1'],
            ['account_code' => '331', 'side' => 'credit', 'amount_minor' => 10_000, 'line_text' => 'Os. č. B-1'],
        ], array_map(static fn (array $line): array => [
            'account_code' => $line['account_code'],
            'side' => $line['side'],
            'amount_minor' => $line['amount_minor'],
            'line_text' => $line['line_text'] ?? null,
        ], $lines));
    }

    public function testOnlyTheFrozenChoiceSwitchesPostingToEmployees(): void
    {
        self::assertFalse(PayrollPostingLineBuilder::postsByEmployee(['employer' => ['name' => 'X']]));
        self::assertFalse(PayrollPostingLineBuilder::postsByEmployee(['employer' => ['posting_granularity' => 'summary']]));
        self::assertFalse(PayrollPostingLineBuilder::postsByEmployee([]));
        self::assertTrue(PayrollPostingLineBuilder::postsByEmployee(['employer' => ['posting_granularity' => 'employee']]));
    }

    /** @param list<array<string,mixed>> $lines */
    private function assertPairs(array $lines): void
    {
        $resolved = array_map(static fn (array $line): array => [
            'account_code' => $line['account_code'],
            'side' => $line['side'],
            'amount' => number_format($line['amount_minor'] / 100, 2, '.', ''),
            'pair_group' => $line['pair_group'],
            'line_text' => $line['line_text'] ?? null,
        ], $lines);
        $paired = JournalPairer::pair($resolved);
        self::assertSame('paired', $paired['status']);
        self::assertCount(count($lines), $paired['lines']);
        JournalPairInvariant::assert(['entry_date' => '2026-06-30'], $paired['lines'], EntryForm::Single);
        foreach ($paired['lines'] as $line) {
            self::assertStringStartsWith('Os. č. ', (string) $line['line_text']);
        }
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,int>
     */
    private function net(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $map[$line['account_code']] = ($map[$line['account_code']] ?? 0)
                + ($line['side'] === 'debit' ? 1 : -1) * $line['amount_minor'];
        }
        ksort($map);

        return $map;
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return [
            'employer' => ['posting_granularity' => 'employee'],
            'people' => [
                ['employee' => ['id' => 11], 'employments' => [
                    ['employment' => ['id' => 101, 'code' => 'A-1', 'is_primary' => false]],
                    ['employment' => ['id' => 102, 'code' => 'A-2', 'is_primary' => true]],
                ]],
                ['employee' => ['id' => 12], 'employments' => [
                    ['employment' => ['id' => 201, 'code' => 'B-1', 'is_primary' => null]],
                ]],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function allocations(int $secondGross = 200_000): array
    {
        $rows = [
            ['gross:employment:101:input:1:debit', '521', 100_000],
            ['gross:employment:101:input:1:credit', '331', -100_000],
            ['gross:employment:201:input:2:debit', '521', $secondGross],
            ['gross:employment:201:input:2:credit', '331', -$secondGross],
            ['employee:11:social-insurance:settlement:employment:101:input:1', '331', 10_000],
            ['employee:11:social-insurance:liability', '336.100', -10_000],
            ['employee:12:social-insurance:settlement:employment:201:input:2', '331', 20_000],
            ['employee:12:social-insurance:liability', '336.100', -20_000],
            ['employer-insurance:social:employment:101:debit', '524', 30_000],
            ['employer-insurance:social:employment:201:debit', '524', 60_000],
            ['employer-insurance:social:credit', '336.100', -90_000],
        ];

        return array_map(static fn (array $row): array => [
            'allocation_key' => $row[0],
            'account_code' => $row[1],
            'signed_minor' => $row[2],
            'description' => 'test',
        ], $rows);
    }
}
