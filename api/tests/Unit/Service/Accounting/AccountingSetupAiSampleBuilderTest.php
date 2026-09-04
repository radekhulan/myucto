<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Setup\AccountingSetupAiSampleBuilder;
use PHPUnit\Framework\TestCase;

final class AccountingSetupAiSampleBuilderTest extends TestCase
{
    public function testBuildKeepsOnlyRepeatedAnonymizedDescriptions(): void
    {
        $builder = new AccountingSetupAiSampleBuilder();
        $rows = [
            $this->row(1, 10, 'CloudPro s.r.o. licence pro user@example.test, objednávka 202612345 za 1 999 Kč', 'CloudPro s.r.o.'),
            $this->row(2, 11, 'CloudPro s.r.o. licence pro user@example.test, objednávka 202612345 za 1 999 Kč', 'CloudPro s.r.o.'),
            $this->row(3, 12, 'Jednorázová konzultace Jan Novák', 'Poradce s.r.o.'),
        ];

        $result = $builder->build($rows);

        self::assertCount(1, $result['samples']);
        self::assertSame('s01', $result['samples'][0]['sample_id']);
        self::assertSame(2, $result['samples'][0]['occurrences']);
        self::assertStringNotContainsString('CloudPro', $result['samples'][0]['text']);
        self::assertStringNotContainsString('user@example.test', $result['samples'][0]['text']);
        self::assertStringNotContainsString('202612345', $result['samples'][0]['text']);
        self::assertStringNotContainsString('1 999', $result['samples'][0]['text']);
        self::assertCount(2, $result['rows_by_sample']['s01']);
    }

    public function testBuildIsDeterministicAndLimitedToFiftySamples(): void
    {
        $rows = [];
        $id = 1;
        for ($i = 1; $i <= 55; $i++) {
            $description = 'Opakovaná služba ' . str_repeat('x', $i);
            $rows[] = $this->row($id++, 100 + $i * 2, $description, null);
            $rows[] = $this->row($id++, 101 + $i * 2, $description, null);
        }

        $builder = new AccountingSetupAiSampleBuilder();
        $first = $builder->build($rows);
        $second = $builder->build(array_reverse($rows));

        self::assertCount(50, $first['samples']);
        self::assertSame($first['samples'], $second['samples']);
        self::assertSame(array_map(
            static fn (int $i): string => sprintf('s%02d', $i),
            range(1, 50),
        ), array_column($first['samples'], 'sample_id'));
    }

    public function testBuildSupportsExplicitBudgetUpToTwoHundredSamples(): void
    {
        $rows = [];
        $id = 1;
        for ($i = 1; $i <= 120; $i++) {
            $description = 'Opakovaná položka ' . str_repeat('x', $i);
            $rows[] = $this->row($id++, 1000 + $i * 2, $description, null);
            $rows[] = $this->row($id++, 1001 + $i * 2, $description, null);
        }

        $result = (new AccountingSetupAiSampleBuilder())->build($rows, 100);

        self::assertCount(100, $result['samples']);
        self::assertSame('s100', $result['samples'][99]['sample_id']);
    }

    public function testBuildNormalizesCzechAndSlovakDiacritics(): void
    {
        $builder = new AccountingSetupAiSampleBuilder();
        $rows = [
            $this->row(1, 10, 'Školení a účtovné služby', null),
            $this->row(2, 11, 'Školení a účtovné služby', null),
        ];

        $result = $builder->build($rows);

        self::assertSame('skoleni uctovne sluzby', $result['samples'][0]['text']);
    }

    /** @return array<string,mixed> */
    private function row(int $id, int $invoiceId, string $description, ?string $vendor): array
    {
        return [
            'id' => $id,
            'purchase_invoice_id' => $invoiceId,
            'description' => $description,
            'vendor_name' => $vendor,
            'unit_price_without_vat' => 1250.50,
            'total_without_vat' => 1250.50,
            'exchange_rate' => 1,
            'acq_year' => 2026,
        ];
    }
}
