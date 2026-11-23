<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Closing\AccrualDeferralService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rozdělení odkladu a tranší rozpuštění časového rozlišení po účtech a dimenzích na haléř.
 */
final class AccrualDeferralAllocationTest extends TestCase
{
    /** @return iterable<string,array{0:float}> */
    public static function amounts(): iterable
    {
        foreach ([0.03, 0.05, 1.01, 10.01, 333.33, 1234.57, 36600.00] as $amount) {
            yield (string) $amount => [$amount];
        }
    }

    /**
     * Víceleté rozpouštění s rozpadem dimenzí: tranše všech let se po každé dimenzi sečtou
     * přesně na odloženou částku, takže na dimenzi nezůstane haléř.
     */
    #[DataProvider('amounts')]
    public function testReleaseTranchesSumToDeferralPerDimension(float $net): void
    {
        $allocations = [
            self::allocation('518', 1.0, 0.5, 10),
            self::allocation('518', 1.0, 0.5, 11),
        ];
        [$from, $to] = ['2091-07-01', '2094-06-30'];
        $deferral = AccrualDeferralService::spreadByAllocation(AccrualDeferralService::deferredAmount($net, $from, $to, '2091-12-31'), $allocations);

        $released = [];
        foreach (['2091-12-31' => '2092-12-31', '2092-12-31' => '2093-12-31', '2093-12-31' => '2094-12-31'] as $lower => $upper) {
            $before = round($net - AccrualDeferralService::cumRecognized($net, $from, $to, $lower), 2);
            $after = round($net - AccrualDeferralService::cumRecognized($net, $from, $to, $upper < $to ? $upper : $to), 2);
            foreach (AccrualDeferralService::releaseByAllocation($before, $after, $allocations) as $key => $line) {
                $released[$key] = round(($released[$key] ?? 0.0) + $line['amount'], 2);
            }
        }

        foreach ($deferral as $key => $line) {
            self::assertEqualsWithDelta($line['amount'], $released[$key] ?? 0.0, 0.0001, 'Dimenze ' . $key . ' se rozpustí přesně na odloženou částku.');
        }
    }

    /** Bez rozpadu dimenzí je tranše stejná jako dosud: rozdíl kumulativně uznané částky. */
    #[DataProvider('amounts')]
    public function testSingleAllocationTrancheEqualsCumulativeDifference(float $net): void
    {
        [$from, $to] = ['2091-07-01', '2093-06-30'];
        $lower = AccrualDeferralService::cumRecognized($net, $from, $to, '2091-12-31');
        $upper = AccrualDeferralService::cumRecognized($net, $from, $to, '2092-12-31');

        $lines = AccrualDeferralService::releaseByAllocation(round($net - $lower, 2), round($net - $upper, 2), [self::allocation('602', 1.0, 1.0, null)]);

        self::assertSame(['602'], array_map('strval', array_keys($lines)));
        self::assertEqualsWithDelta(round($upper - $lower, 2), $lines['602']['amount'], 0.0001);
    }

    /**
     * Součty po účtech jsou stejné jako {@see AccrualDeferralService::spreadByAccount()} nad
     * původními podíly účtů (výkazy s průběžným rozlišením a náhled DPPO), i když se účet dál
     * dělí na dimenze.
     */
    #[DataProvider('amounts')]
    public function testAccountTotalsMatchSpreadByAccount(float $amount): void
    {
        $third = 1 / 3;
        $allocations = [];
        foreach (['518.100', '518.200', '518.300'] as $account) {
            $allocations[] = self::allocation($account, $third, 0.7, 10);
            $allocations[] = self::allocation($account, $third, 0.3, 11);
        }

        $byAccount = AccrualDeferralService::amountsByAccount(AccrualDeferralService::spreadByAllocation($amount, $allocations));

        $expected = AccrualDeferralService::spreadByAccount($amount, ['518.100' => $third, '518.200' => $third, '518.300' => $third]);
        foreach ($expected as $account => $part) {
            self::assertEqualsWithDelta($part, $byAccount[(string) $account], 0.0001);
        }
    }

    /** @return array<string,mixed> */
    private static function allocation(string $account, float $accountShare, float $part, ?int $value): array
    {
        return ['account_code' => $account, 'account_share' => $accountShare, 'part' => $part]
            + ($value === null ? [] : ['dimensions' => [1 => $value]]);
    }
}
