<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Reports\CashFlowStatementService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přehled o peněžních tocích po dvojicích (návrh kap. 4.4): u jednoduchého zápisu se tok
 * zařadí podle protiúčtu dvojice, u starších složených zápisů zůstává dnešní výpočet.
 */
#[Group('integration')]
final class CashFlowPairsTest extends TestCase
{
    use PairReportFixture;

    private CashFlowStatementService $cashFlow;

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->cashFlow = $this->container->get(CashFlowStatementService::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testPairedEntryClassifiesFlowByCounterAccountOfCashPair(): void
    {
        $this->switchToSingle(self::YEAR . '-01-01');
        // Prodej za hotové s předpisem v jednom zápisu: jen dvojice 211 / 311 je pohyb peněz.
        $this->postPairs(self::YEAR . '-02-01', 'PD-9', 'Prodej za hotové', [
            ['311', '602', '1000.00'], ['311', '343.200', '210.00'], ['211', '311', '1210.00'],
        ]);

        $data = $this->build();
        self::assertSame([['account_code' => '311', 'amount' => 1210.0]], self::rows($data['breakdown']['operating']));
        self::assertSame(1210.0, $data['operating']);
        self::assertTrue($data['reconciles']);
    }

    public function testSameLinesWithoutPairsKeepTodaysComputation(): void
    {
        // Starší složený zápis (pořízený před přepnutím), který převod nerozdělil.
        $this->manual(self::YEAR . '-02-01', 'PD-9', 'Prodej za hotové', [
            ['311', 'debit', '1000.00'], ['602', 'credit', '1000.00'], ['311', 'debit', '210.00'],
            ['343.200', 'credit', '210.00'], ['211', 'debit', '1210.00'], ['311', 'credit', '1210.00'],
        ]);
        $this->switchToSingle(self::YEAR . '-01-01');

        $data = $this->build();
        self::assertSame([
            ['account_code' => '343.200', 'amount' => 210.0],
            ['account_code' => '602', 'amount' => 1000.0],
        ], self::rows($data['breakdown']['operating']), 'Složený zápis: rozklad přes všechny nepeněžní řádky jako dnes.');
        self::assertSame(1210.0, $data['operating']);
        self::assertTrue($data['reconciles']);
    }

    public function testOneToManyEntriesGiveSameFlowsWhenPaired(): void
    {
        $entries = $this->postScenario();
        $this->switchToSingle(self::YEAR . '-01-01');
        $before = $this->build();

        $this->pairAllInPlace($entries);
        $after = $this->build();

        self::assertSame($before, $after, 'Platby 1 : N po dvojicích dávají stejné toky po protiúčtech.');
        self::assertTrue($after['reconciles']);
    }

    /** @return array<string,mixed> */
    private function build(): array
    {
        $this->entryForms->forget();
        return $this->cashFlow->build($this->supplierId, $this->periodId);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{account_code:string, amount:float}>
     */
    private static function rows(array $rows): array
    {
        return array_map(static fn (array $r): array => ['account_code' => $r['account_code'], 'amount' => $r['amount']], $rows);
    }
}
