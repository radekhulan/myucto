<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Vat;

use MyInvoice\Service\Accounting\Vat\VatClearingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Čistá aritmetika zúčtovacího dokladu DPH — hranice období, deterministické
 * `source_id` a sestavení řádků. Bez DB: tohle je ta část, kde se dá splést směr
 * zápisu, a chyba ve směru by prošla i vyvážená (Σ MD = Σ D by seděla).
 */
final class VatClearingLinesTest extends TestCase
{
    public function testMonthlyPeriodBoundsCoverExactlyTheMonth(): void
    {
        self::assertSame(['2026-02-01', '2026-02-28', 2, 2], VatClearingService::periodBounds(2026, 2, 'monthly'));
        // Přestupný rok — konec měsíce se počítá z kalendáře, ne z konstanty.
        self::assertSame(['2024-02-01', '2024-02-29', 2, 2], VatClearingService::periodBounds(2024, 2, 'monthly'));
    }

    #[DataProvider('quarterProvider')]
    public function testQuarterlyPeriodBoundsSnapToWholeQuarter(int $month, string $start, string $end): void
    {
        [$actualStart, $actualEnd] = VatClearingService::periodBounds(2026, $month, 'quarterly');
        self::assertSame($start, $actualStart);
        self::assertSame($end, $actualEnd);
    }

    /** @return list<array{0:int,1:string,2:string}> */
    public static function quarterProvider(): array
    {
        return [
            [1, '2026-01-01', '2026-03-31'],
            [2, '2026-01-01', '2026-03-31'],
            [3, '2026-01-01', '2026-03-31'],
            [4, '2026-04-01', '2026-06-30'],
            [8, '2026-07-01', '2026-09-30'],
            [12, '2026-10-01', '2026-12-31'],
        ];
    }

    /**
     * Klíč idempotence. Měsíční leden a čtvrtletní Q1 sdílejí první měsíc, takže bez
     * příznaku zdaňovacího období by si po jeho změně navzájem přepsaly zápis.
     */
    public function testSourceIdIsDeterministicAndDistinguishesPeriodType(): void
    {
        self::assertSame(2026010, VatClearingService::sourceIdFor(2026, 1, 'monthly'));
        self::assertSame(2026011, VatClearingService::sourceIdFor(2026, 1, 'quarterly'));
        self::assertSame(2026011, VatClearingService::sourceIdFor(2026, 3, 'quarterly'), 'Celé Q1 má jedno source_id.');
        self::assertSame(2026030, VatClearingService::sourceIdFor(2026, 3, 'monthly'));
        self::assertSame(2026120, VatClearingService::sourceIdFor(2026, 12, 'monthly'));
        self::assertNotSame(
            VatClearingService::sourceIdFor(2026, 1, 'monthly'),
            VatClearingService::sourceIdFor(2025, 1, 'monthly'),
            'Roky se nesmí potkat.',
        );
    }

    public function testPeriodLabel(): void
    {
        self::assertSame('03/2026', VatClearingService::periodLabel(2026, 3, 'monthly'));
        self::assertSame('Q1/2026', VatClearingService::periodLabel(2026, 3, 'quarterly'));
        self::assertSame('Q4/2026', VatClearingService::periodLabel(2026, 11, 'quarterly'));
    }

    public function testPreviousPeriodSurvivesLongMonths(): void
    {
        // 31. 3. − 1 měsíc by bez `first day of this month` přeteklo na 3. 3.
        self::assertSame([2026, 2], VatClearingService::previousPeriod(new \DateTimeImmutable('2026-03-31')));
        self::assertSame([2025, 12], VatClearingService::previousPeriod(new \DateTimeImmutable('2026-01-05')));
    }

    public function testQuarterlyPeriodIsClosedOnlyAfterItEnds(): void
    {
        $inQuarter = new \DateTimeImmutable('2026-03-01');
        self::assertFalse(
            VatClearingService::isPeriodClosed(2026, 1, 'quarterly', $inQuarter),
            'Q1 se v březnu ještě zúčtovat nesmí — chybí mu poslední měsíc.',
        );
        self::assertTrue(VatClearingService::isPeriodClosed(2026, 1, 'monthly', $inQuarter));
        self::assertTrue(VatClearingService::isPeriodClosed(2026, 1, 'quarterly', new \DateTimeImmutable('2026-04-01')));
    }

    /**
     * Směry z deníku účetní:  MD 343.200 / D 343.900  a  MD 343.900 / D 343.100.
     */
    public function testBuildLinesFollowsAccountantsDirections(): void
    {
        $lines = self::buildLines(4000.0, 10000.0);

        self::assertSame([
            ['account_code' => '343.200', 'side' => 'debit',  'amount' => 10000.0],
            ['account_code' => '343.900', 'side' => 'credit', 'amount' => 10000.0],
            ['account_code' => '343.100', 'side' => 'credit', 'amount' => 4000.0],
            ['account_code' => '343.900', 'side' => 'debit',  'amount' => 4000.0],
        ], $lines);

        // Po dokladu je saldo na 343.900 přesně to, co se odvádí (10 000 − 4 000).
        $settlement = 0.0;
        foreach ($lines as $l) {
            if ($l['account_code'] !== '343.900') {
                continue;
            }
            $settlement += $l['side'] === 'credit' ? $l['amount'] : -$l['amount'];
        }
        self::assertEqualsWithDelta(6000.0, $settlement, 0.001);
    }

    public function testBuildLinesAreBalancedInEveryCombination(): void
    {
        foreach ([[4000.0, 10000.0], [-500.0, 250.0], [0.0, 900.0], [900.0, 0.0], [-100.0, -200.0]] as [$in, $out]) {
            $lines = self::buildLines($in, $out);
            $debit = 0;
            $credit = 0;
            foreach ($lines as $l) {
                self::assertGreaterThan(0, $l['amount'], 'Záporná částka by narazila na chk_jel_amount_positive.');
                if ($l['side'] === 'debit') {
                    $debit += (int) round($l['amount'] * 100);
                } else {
                    $credit += (int) round($l['amount'] * 100);
                }
            }
            self::assertSame($debit, $credit, 'Doklad musí být vyvážený v haléřích.');
        }
    }

    /** Převaha dobropisů obrací strany, ale nikdy neúčtuje zápornou částku. */
    public function testNegativeTurnoverFlipsSides(): void
    {
        $lines = self::buildLines(-300.0, -1200.0);

        self::assertSame([
            ['account_code' => '343.200', 'side' => 'credit', 'amount' => 1200.0],
            ['account_code' => '343.900', 'side' => 'debit',  'amount' => 1200.0],
            ['account_code' => '343.100', 'side' => 'debit',  'amount' => 300.0],
            ['account_code' => '343.900', 'side' => 'credit', 'amount' => 300.0],
        ], $lines);
    }

    public function testZeroTurnoverProducesNoDocument(): void
    {
        self::assertSame([], self::buildLines(0.0, 0.0));
        // Haléřová nula je taky nula (peníze se porovnávají v haléřích, ne přes float).
        self::assertSame([], self::buildLines(0.001, -0.002));
    }

    public function testOnlyOneSideStillBalances(): void
    {
        $lines = self::buildLines(0.0, 2100.0);
        self::assertCount(2, $lines, 'Nulová strana se nezakládá — žádný řádek na 0 Kč.');
        self::assertSame('343.200', $lines[0]['account_code']);
        self::assertSame('343.900', $lines[1]['account_code']);
    }

    public function testOppositeAmountsOnDifferentAccountsStillClearBothAccounts(): void
    {
        $lines = VatClearingService::buildLines([
            ['account_code' => '343.310', 'direction' => 'output', 'amount' => 120.0],
            ['account_code' => '343.320', 'direction' => 'output', 'amount' => -120.0],
        ], '343.900');

        self::assertSame(['343.310', '343.900', '343.320', '343.900'], array_column($lines, 'account_code'));
        self::assertSame(['debit', 'credit', 'credit', 'debit'], array_column($lines, 'side'));
        self::assertSame([120.0, 120.0, 120.0, 120.0], array_column($lines, 'amount'));
    }

    private static function buildLines(float $input, float $output): array
    {
        return VatClearingService::buildLines([
            ['account_code' => '343.200', 'direction' => 'output', 'amount' => $output],
            ['account_code' => '343.100', 'direction' => 'input', 'amount' => $input],
        ], '343.900');
    }
}
