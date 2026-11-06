<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class J6LegacyParitySink implements J6CalculationSink
{
    private J6ExternalSortQueue $expectedRows;
    private J6ExternalSortQueue $actualRows;
    private J6ExternalSortQueue $expectedUsage;
    private J6ExternalSortQueue $actualUsage;
    private bool $closed = false;

    public function __construct(
        private readonly J6CalculationSink $inner,
        private readonly J6LegacyAssessmentInputProvider $legacy,
    ) {
        $this->expectedRows = new J6ExternalSortQueue();
        $this->actualRows = new J6ExternalSortQueue();
        $this->expectedUsage = new J6ExternalSortQueue();
        $this->actualUsage = new J6ExternalSortQueue();
        foreach ($legacy->resultRows() as $row) $this->expectedRows->append(self::rowKey($row), self::financialRecord($row));
        foreach ($legacy->resultFundingUsagePages() as $page) {
            foreach ($page as $row) $this->expectedUsage->append(self::usageKey($row), $row);
        }
    }

    public function begin(J6CalculationHeader $header): void { $this->inner->begin($header); }
    public function appendDecisionPage(array $rows): void { $this->inner->appendDecisionPage($rows); }

    public function appendResultPage(array $rows): void
    {
        foreach ($rows as $row) {
            unset($row['result_row_hash']);
            $this->actualRows->append(self::rowKey($row), self::financialRecord($row));
        }
        $this->inner->appendResultPage($rows);
    }

    public function appendCoveragePage(array $rows): void { $this->inner->appendCoveragePage($rows); }

    public function appendFundingUsagePage(array $rows): void
    {
        foreach ($rows as $row) {
            unset($row['usage_record_hash']);
            $this->actualUsage->append(self::usageKey($row), $row);
        }
        $this->inner->appendFundingUsagePage($rows);
    }

    public function appendFundingReferencePage(array $rows): void { $this->inner->appendFundingReferencePage($rows); }
    public function appendFundingHistoryPage(array $rows): void { $this->inner->appendFundingHistoryPage($rows); }

    public function complete(array $summary): J6CalculationReceipt
    {
        try {
            foreach (['accounting_result_cents', 'excluded_revenue_cents', 'cost_addbacks_cents', 'assessed_result_cents'] as $field) {
                if ($summary[$field] !== $this->legacy->resultScalar($field)) {
                    throw new \UnexpectedValueException('Legacy J6 financial summary changed during conversion.');
                }
            }
            self::assertSame($this->expectedRows->records(), $this->actualRows->records(), 'result');
            self::assertSame($this->expectedUsage->records(), $this->actualUsage->records(), 'usage');
            $receipt = $this->inner->complete($summary);
            $this->closed = true;
            return $receipt;
        } catch (\Throwable $failure) {
            $this->abort();
            throw $failure;
        }
    }

    public function abort(): void
    {
        if ($this->closed) return;
        $this->inner->abort();
        foreach ([$this->expectedRows, $this->actualRows, $this->expectedUsage, $this->actualUsage] as $queue) {
            try { $queue->closeAndDelete(); } catch (\Throwable) {}
        }
        $this->closed = true;
    }

    public function __destruct() { if (!$this->closed) $this->abort(); }

    private static function assertSame(iterable $expected, iterable $actual, string $kind): void
    {
        $left = $expected instanceof \Iterator ? $expected : (function () use ($expected) { yield from $expected; })();
        $right = $actual instanceof \Iterator ? $actual : (function () use ($actual) { yield from $actual; })();
        $left->rewind();
        $right->rewind();
        $mismatch = false;
        while ($left->valid() || $right->valid()) {
            if (!$left->valid() || !$right->valid()
                || !hash_equals(NonprofitTaxRules::canonicalJson($left->current()), NonprofitTaxRules::canonicalJson($right->current()))) {
                $mismatch = true;
            }
            if ($left->valid()) $left->next();
            if ($right->valid()) $right->next();
        }
        if ($mismatch) throw new \UnexpectedValueException('Legacy J6 ' . $kind . ' changed during conversion.');
    }

    private static function rowKey(array $row): string
    {
        $id = $row['id'] ?? null;
        if (!is_string($id) || $id === '') throw new \UnexpectedValueException('Legacy J6 result row identity is invalid.');
        return $id;
    }

    private static function usageKey(array $row): string
    {
        $anchor = $row['anchor'] ?? null;
        $member = $row['member_id'] ?? null;
        if (!is_string($anchor) || $anchor === '' || !is_string($member) || $member === '') {
            throw new \UnexpectedValueException('Legacy J6 usage identity is invalid.');
        }
        return $anchor . "\0" . $member;
    }

    private static function financialRecord(array $record): array
    {
        foreach ($record as $key => $value) {
            if (in_array((string) $key, ['evidence', 'reason', 'note', 'description', 'name'], true)) {
                unset($record[$key]);
            } elseif (is_array($value)) {
                $record[$key] = self::financialRecord($value);
            }
        }
        return $record;
    }
}
