<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Reports\AccountStatementService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerService;
use MyInvoice\Service\Accounting\Reports\JournalLineContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Opis účtu firmy s jednoduchým zápisem (kritérium d): každý pohyb má jediný protiúčet,
 * filtr a součty jdou podle dvojic. Starší zápis 1 : N se zobrazí rozložený za běhu se
 * stejnými čísly jako po fyzickém rozdělení; M : N zůstane v původním tvaru s označením.
 */
#[Group('integration')]
final class AccountStatementPairsTest extends TestCase
{
    use PairReportFixture;

    private AccountStatementService $statement;

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->statement = $this->container->get(AccountStatementService::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testBulkPaymentHasOneMovementPerPairWithSingleCounterAccount(): void
    {
        $entries = $this->postScenario();
        $this->switchToSingle(self::YEAR . '-01-01');
        self::assertTrue($this->pairEntryInPlace($entries['bulk']));

        $data = $this->build('221');
        $bulk = array_values(array_filter($data['items'], static fn (array $i): bool => $i['entry_id'] === $entries['bulk']));
        self::assertCount(3, $bulk, 'Hromadná platba = tři pohyby na 221.');
        self::assertSame(['321', '321', '568'], array_column($bulk, 'counter_accounts'));
        self::assertSame([-1210.0, -605.0, -15.0], array_map(static fn (array $i): float => $i['side'] === 'credit' ? -$i['amount'] : $i['amount'], $bulk));
        foreach ($bulk as $item) {
            self::assertSame(JournalLineContext::PAIR_STATUS_PAIRED, $item['pair_status']);
            self::assertNotNull($item['pair_no']);
            self::assertSame($this->accountId($item['counter_accounts']), $item['counter_account_id']);
            self::assertNull($item['pair_piece']);
        }

        $filtered = $this->build('221', '568');
        self::assertSame(1, $filtered['total']);
        self::assertCount(1, $filtered['items']);
        self::assertSame(15.0, $filtered['items'][0]['amount']);
        self::assertSame(['md' => 0.0, 'd' => 15.0], ['md' => $filtered['counter_filter']['md'], 'd' => $filtered['counter_filter']['d']]);

        $this->assertCounterTotalsMatchTurnover($data);
        $totals = $this->totalsByCode($data);
        self::assertSame(['md' => 0, 'd' => 2181500], $totals['321'], '321 = 1 210 + 605 + 20 000 (stroj).');
        self::assertSame(['md' => 0, 'd' => 1500], $totals['568']);
    }

    public function testOlderOneToManyEntryIsExpandedLikePhysicalPairs(): void
    {
        $entries = $this->postScenario();
        $this->switchToSingle(self::YEAR . '-01-01');

        $derived = $this->build('221');
        $derivedFiltered = $this->build('221', '568');
        $bulk = array_values(array_filter($derived['items'], static fn (array $i): bool => $i['entry_id'] === $entries['bulk']));
        self::assertCount(3, $bulk, 'Řádek 221 hromadné platby se rozbalí na tři díly.');
        self::assertSame([1, 2, 3], array_column($bulk, 'pair_piece'));
        self::assertSame(['321', '321', '568'], array_column($bulk, 'counter_accounts'));
        self::assertSame(array_fill(0, 3, JournalLineContext::PAIR_STATUS_DERIVED), array_column($bulk, 'pair_status'));
        self::assertSame([1210.0, 605.0, 15.0], array_column($bulk, 'amount'));
        // Zůstatek po dílech: před platbou 1 210, po ní -620.
        self::assertSame([0.0, -605.0, -620.0], array_column($bulk, 'balance'));
        self::assertSame(1, $derivedFiltered['total'], 'Stránkování a počet jdou dál po řádcích deníku.');
        self::assertCount(1, $derivedFiltered['items']);
        self::assertSame(15.0, $derivedFiltered['items'][0]['amount']);
        self::assertSame(-620.0, $derivedFiltered['items'][0]['balance'], 'Zůstatek po dílu, ne součet vybraných dílů.');

        // Totéž po fyzickém rozdělení: pohyby, protiúčty, zůstatky i součty se nezmění.
        $this->pairAllInPlace($entries);
        $paired = $this->build('221');
        $pairedFiltered = $this->build('221', '568');
        self::assertSame(self::movements($derived), self::movements($paired));
        self::assertSame(self::movements($derivedFiltered), self::movements($pairedFiltered));
        self::assertSame($derived['counter_totals'], $paired['counter_totals']);
        self::assertSame($derivedFiltered['counter_filter'], $pairedFiltered['counter_filter']);
        self::assertSame($derived['closing_balance'], $paired['closing_balance']);
    }

    public function testInvoiceHubAndRedStornoOnReceivableAccount(): void
    {
        $entries = $this->postScenario();
        $this->switchToSingle(self::YEAR . '-01-01');

        $data = $this->build('311');
        $invoice = array_values(array_filter($data['items'], static fn (array $i): bool => $i['entry_id'] === $entries['sale2']));
        self::assertSame(['602', '343.200', '604', '343.200'], array_column($invoice, 'counter_accounts'));
        self::assertSame([10000.0, 2100.0, 1000.0, 120.0], array_column($invoice, 'amount'));

        $storno = array_values(array_filter($data['items'], static fn (array $i): bool => $i['entry_id'] === $entries['storno']));
        self::assertSame(['602', '343.200'], array_column($storno, 'counter_accounts'));
        self::assertSame([-100.0, -21.0], array_column($storno, 'amount'), 'Červené storno zůstává záporné i po dílech.');

        $filtered = $this->build('311', '343');
        $filteredInvoice = array_values(array_filter($filtered['items'], static fn (array $i): bool => $i['entry_id'] === $entries['sale2']));
        self::assertSame([2100.0, 120.0], array_column($filteredInvoice, 'amount'), 'Filtr syntetiky 343 vybere díly analytiky 343.200.');
        self::assertSame(['md' => 2409.0, 'd' => 0.0], ['md' => $filtered['counter_filter']['md'], 'd' => $filtered['counter_filter']['d']]);
        $this->assertCounterTotalsMatchTurnover($data);
    }

    public function testManyToManyAndAmbiguousEntriesStayCompositeUntilPaired(): void
    {
        $entries = $this->postScenario();
        $this->switchToSingle(self::YEAR . '-01-01');

        $data = $this->build('211');
        $byEntry = [];
        foreach ($data['items'] as $item) {
            $byEntry[$item['entry_id']][] = $item;
        }
        foreach ($byEntry[$entries['mn']] as $item) {
            self::assertSame(JournalLineContext::PAIR_STATUS_COMPOSITE, $item['pair_status']);
            self::assertNull($item['counter_account_id']);
            self::assertSame('512, 513', $item['counter_accounts'], 'M : N zůstává v původním tvaru.');
        }
        // Dvě dvojice se shodnou částkou 100: dvojici řádků nejde jednoznačně určit bez převodu.
        foreach ($byEntry[$entries['cash']] as $item) {
            self::assertSame(JournalLineContext::PAIR_STATUS_COMPOSITE, $item['pair_status']);
            self::assertSame('512, 513', $item['counter_accounts']);
        }

        self::assertTrue($this->pairEntryInPlace($entries['cash']));
        self::assertFalse($this->pairEntryInPlace($entries['mn']), 'M : N převod nerozdělí.');
        $after = $this->build('211');
        $cash = array_values(array_filter($after['items'], static fn (array $i): bool => $i['entry_id'] === $entries['cash']));
        self::assertSame(['512', '513'], array_column($cash, 'counter_accounts'));
        self::assertSame([JournalLineContext::PAIR_STATUS_PAIRED, JournalLineContext::PAIR_STATUS_PAIRED], array_column($cash, 'pair_status'));
        $filtered = $this->build('211', '512');
        $filteredCash = array_values(array_filter($filtered['items'], static fn (array $i): bool => $i['entry_id'] === $entries['cash']));
        self::assertCount(1, $filteredCash, 'Filtr 512 vybere jen řádek své dvojice.');
        self::assertSame($data['counter_totals'], $after['counter_totals'], 'Součty po protiúčtech se rozdělením nemění.');
        $this->assertCounterTotalsMatchTurnover($after);
    }

    public function testAccountStatementOfPairedFirmCarriesPairFieldsOnEveryMovement(): void
    {
        $entries = $this->postScenario();
        $this->switchToSingle(self::YEAR . '-01-01');
        $this->pairAllInPlace($entries);

        foreach (['221', '311', '321', '211', '343', '602'] as $code) {
            $data = $this->build($code);
            self::assertNotSame([], $data['items']);
            foreach ($data['items'] as $item) {
                self::assertArrayHasKey('pair_no', $item);
                self::assertArrayHasKey('counter_account_id', $item);
                self::assertArrayHasKey('pair_status', $item);
                if ($item['entry_id'] === $entries['mn']) {
                    continue;
                }
                self::assertSame(JournalLineContext::PAIR_STATUS_PAIRED, $item['pair_status'], $code . ' ' . $item['document_no']);
                self::assertStringNotContainsString(',', (string) $item['counter_accounts'], 'Jediný protiúčet: ' . $code);
            }
            $this->assertCounterTotalsMatchTurnover($data);
        }
    }

    public function testGeneralLedgerDetailShowsSingleCounterAccountForPairs(): void
    {
        $entries = $this->postScenario();
        $this->switchToSingle(self::YEAR . '-01-01');
        $this->pairAllInPlace($entries);
        $this->entryForms->forget();

        $ledger = $this->container->get(GeneralLedgerService::class)->build($this->supplierId, $this->periodId, null, null, false, [], false, true);
        $lines = [];
        foreach ($this->container->get(GeneralLedgerDetailService::class)->events($this->supplierId, $ledger, [], false) as $event) {
            if ($event['kind'] === 'line') {
                $lines[] = $event['item'];
            }
        }
        self::assertNotSame([], $lines);
        foreach ($lines as $line) {
            if ($line['entry_id'] === $entries['mn']) {
                continue;
            }
            self::assertStringNotContainsString(',', (string) $line['counter_accounts'], 'Rozpis hlavní knihy: jediný protiúčet u dvojice.');
        }
        $bulk = array_values(array_filter($lines, static fn (array $l): bool => $l['entry_id'] === $entries['bulk'] && $l['account_code'] === '221'));
        self::assertSame(['321', '321', '568'], array_column($bulk, 'counter_accounts'));
    }

    /** @return array<string,mixed> */
    private function build(string $code, ?string $counter = null): array
    {
        $this->entryForms->forget();
        return $this->statement->build(
            $this->supplierId,
            $this->accountId($code),
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
            1,
            200,
            false,
            $counter === null ? null : $this->accountId($counter),
        );
    }

    /** @param array<string,mixed> $data */
    private function assertCounterTotalsMatchTurnover(array $data): void
    {
        $md = 0;
        $d = 0;
        foreach ($data['counter_totals'] as $row) {
            $md += self::cents($row['md']);
            $d += self::cents($row['d']);
        }
        self::assertSame(self::cents($data['turnover_md']), $md, 'Σ protiúčtů MD = obrat MD');
        self::assertSame(self::cents($data['turnover_d']), $d, 'Σ protiúčtů D = obrat D');
        // Součet pohybů opisu (po dílech) = obrat.
        $itemsMd = 0;
        $itemsD = 0;
        foreach ($data['items'] as $item) {
            if ($item['side'] === 'debit') {
                $itemsMd += self::cents($item['amount']);
            } else {
                $itemsD += self::cents($item['amount']);
            }
        }
        self::assertSame([$md, $d], [$itemsMd, $itemsD], 'Σ pohybů opisu = obrat');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,array{md:int,d:int}>
     */
    private function totalsByCode(array $data): array
    {
        $out = [];
        foreach ($data['counter_totals'] as $row) {
            $out[(string) $row['account_code']] = ['md' => self::cents($row['md']), 'd' => self::cents($row['d'])];
        }
        return $out;
    }

    /**
     * Pohyby bez identity řádku (id, číslo dvojice, stav): co uživatel vidí.
     *
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private static function movements(array $data): array
    {
        return array_map(static fn (array $i): array => [
            'entry_id' => $i['entry_id'],
            'side'     => $i['side'],
            'amount'   => $i['amount'],
            'balance'  => $i['balance'],
            'counter'  => $i['counter_accounts'],
            'counter_id' => $i['counter_account_id'],
        ], $data['items']);
    }
}
