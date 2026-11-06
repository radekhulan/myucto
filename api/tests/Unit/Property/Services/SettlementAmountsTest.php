<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property\Services;

use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\Services\Settlement\SettlementAmounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettlementAmountsTest extends TestCase
{
    private const KEYS = ['advance_applied_cents', 'new_claim_cents', 'cancelled_unpaid_cents', 'new_credit_cents', 'remaining_old_claim_cents'];

    #[DataProvider('examples')]
    public function testExactExamples(string $z, string $p, string $s, array $expected): void
    {
        $result = SettlementAmounts::calculate($z, $p, $s);
        self::assertSame(array_combine(self::KEYS, $expected), $result);
        $this->assertInvariants($z, $p, $s, $result);
    }

    public static function examples(): array
    {
        return [
            'partly paid, unused unpaid cancelled' => ['100000', '60000', '80000', ['80000', '0', '20000', '0', '20000']],
            'fully paid, actual credit' => ['100000', '100000', '80000', ['80000', '0', '0', '20000', '0']],
            'cancel unpaid and credit paid separately' => ['100000', '90000', '80000', ['80000', '0', '10000', '10000', '0']],
            'methodology 4.2 and 4.3' => ['12000000', '10000000', '11200000', ['11200000', '0', '800000', '0', '1200000']],
            'methodology fully paid' => ['12000000', '12000000', '11200000', ['11200000', '0', '0', '800000', '0']],
            'no advance, actual new claim' => ['0', '0', '35', ['0', '35', '0', '0', '0']],
            'empty segment' => ['0', '0', '0', ['0', '0', '0', '0', '0']],
            'above signed bigint' => ['9223372036854775808', '9223372036854775807', '9223372036854775806', ['9223372036854775806', '0', '1', '1', '0']],
            '30 digit cents' => ['999999999999999999999999999999', '999999999999999999999999999990', '999999999999999999999999999995', ['999999999999999999999999999995', '0', '4', '0', '5']],
            'maximum new claim' => ['0', '0', '999999999999999999999999999999', ['0', '999999999999999999999999999999', '0', '0', '0']],
            'maximum credit' => ['999999999999999999999999999999', '999999999999999999999999999999', '0', ['0', '0', '0', '999999999999999999999999999999', '0']],
            'maximum cancelled unpaid' => ['999999999999999999999999999999', '0', '0', ['0', '0', '999999999999999999999999999999', '0', '0']],
        ];
    }

    public function testAllTwelveReferenceUnitsAreCalculatedFromFourteenLegalSegments(): void
    {
        $fixture = require dirname(__DIR__, 3) . '/Fixtures/Property/ServiceSettlementReference.php';
        $totals = array_fill_keys(self::KEYS, '0');
        $inputs = ['0', '0', '0'];
        $units = [];
        foreach ($fixture['segments'] as $segment => [$z, $p, $s]) {
            $result = SettlementAmounts::calculate($z, $p, $s);
            $this->assertInvariants($z, $p, $s, $result);
            $unit = explode(':', $segment)[0];
            $units[$unit] ??= array_fill_keys(self::KEYS, '0');
            foreach (self::KEYS as $key) {
                $units[$unit][$key] = bcadd($units[$unit][$key], $result[$key], 0);
                $totals[$key] = bcadd($totals[$key], $result[$key], 0);
            }
            foreach ([$z, $p, $s] as $index => $amount) $inputs[$index] = bcadd($inputs[$index], $amount, 0);
        }
        self::assertCount(14, $fixture['segments']);
        self::assertCount(12, $units);
        foreach ($fixture['expected_units'] as $unit => $expected) self::assertSame(array_combine(self::KEYS, $expected), $units[$unit], $unit);
        self::assertSame(['13200000', '12300000', '13200000'], $inputs);
        self::assertSame(['12750000', '450000', '200000', '250000', '700000'], array_values($totals));
        self::assertSame('1150000', bcadd($totals['remaining_old_claim_cents'], $totals['new_claim_cents'], 0));
        self::assertSame('900000', bcsub('1150000', $totals['new_credit_cents'], 0));
        $global = SettlementAmounts::calculate(...$inputs);
        self::assertNotSame($totals['new_claim_cents'], $global['new_claim_cents']);
        self::assertNotSame($totals['new_credit_cents'], $global['new_credit_cents']);
    }

    public function testU05AndQ06KeepOldClaimsAndDifferentCreditorsSeparate(): void
    {
        $a = SettlementAmounts::calculate('400000', '400000', '350000');
        $b = SettlementAmounts::calculate('400000', '200000', '450000');
        $c = SettlementAmounts::calculate('400000', '400000', '400000');
        self::assertSame(['350000', '0', '0', '50000', '0'], array_values($a));
        self::assertSame(['400000', '50000', '0', '0', '200000'], array_values($b));
        self::assertSame(['400000', '0', '0', '0', '0'], array_values($c));
        $oldU05Claim = '300000';
        self::assertSame('500000', bcadd($oldU05Claim, $b['remaining_old_claim_cents'], 0));
        self::assertSame('550000', bcadd(bcadd($oldU05Claim, $b['remaining_old_claim_cents'], 0), $b['new_claim_cents'], 0));
        self::assertSame('50000', $a['new_credit_cents']);
        $p06 = SettlementAmounts::calculate('1200000', '800000', '1000000');
        self::assertSame('200000', $p06['remaining_old_claim_cents']);
        self::assertSame('0', $p06['new_credit_cents']);
        $q06ExistingCredit = '600000';
        self::assertSame('350000', bcsub($q06ExistingCredit, '250000', 0));
        self::assertSame('200000', $p06['remaining_old_claim_cents']);
        self::assertSame(self::KEYS, array_keys($p06));
    }

    public function testRangePropertiesForEverySmallValidTriple(): void
    {
        for ($z = 0; $z <= 12; ++$z) {
            for ($p = 0; $p <= $z; ++$p) {
                for ($s = 0; $s <= 18; ++$s) {
                    $this->assertInvariants((string) $z, (string) $p, (string) $s, SettlementAmounts::calculate((string) $z, (string) $p, (string) $s));
                }
            }
        }
    }

    public function testDoesNotDependOnGlobalBcScale(): void
    {
        $previous = bcscale(9);
        try {
            self::assertSame(['8', '0', '1', '1', '0'], array_values(SettlementAmounts::calculate('10', '9', '8')));
        } finally {
            bcscale($previous);
        }
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidCentsWithoutCoercion(int $index, mixed $value, string $field): void
    {
        $arguments = ['100', '50', '80'];
        $arguments[$index] = $value;
        try {
            SettlementAmounts::calculate(...$arguments);
            self::fail('Invalid cents accepted.');
        } catch (PropertyException $e) {
            self::assertSame('property_invalid_input', $e->errorCode);
            self::assertSame(['field' => $field], $e->details);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public static function invalidInputs(): iterable
    {
        foreach (['', '00', '01', '-0', '-1', '1.0', '1e3', ' 1', '1 ', '+1', "1\n", '١', null, true, false, 0, 1, 0.0, 1.5, INF, NAN, [], str_repeat('9', 31), str_repeat('1', 4096)] as $case => $value) {
            foreach (['prescribed_cents', 'paid_cents', 'actual_cents'] as $index => $field) yield $case . ':' . $field => [$index, $value, $field];
        }
    }

    public function testPaymentAbovePrescriptionRequiresAnotherCreditTitle(): void
    {
        $this->expectException(PropertyException::class);
        $this->expectExceptionMessage('Zkontrolujte označený vstup registru.');
        SettlementAmounts::calculate('100000', '100001', '80000');
    }

    private function assertInvariants(string $z, string $p, string $s, array $result): void
    {
        [$a, $d, $u, $c, $remaining] = array_values($result);
        self::assertSame(self::KEYS, array_keys($result));
        foreach ($result as $amount) self::assertMatchesRegularExpression('/^(0|[1-9][0-9]{0,29})$/D', $amount);
        self::assertSame('0', bcsub(bcsub(bcsub($z, $a, 0), $u, 0), $c, 0));
        self::assertSame($s, bcadd($a, $d, 0));
        self::assertSame(bcsub(bcsub($z, $p, 0), $u, 0), $remaining);
        self::assertSame(bcsub($s, $p, 0), bcsub(bcadd($remaining, $d, 0), $c, 0));
        self::assertLessThanOrEqual(0, bccomp($a, $z, 0));
        self::assertLessThanOrEqual(0, bccomp($a, $s, 0));
        self::assertLessThanOrEqual(0, bccomp($u, bcsub($z, $p, 0), 0));
        self::assertLessThanOrEqual(0, bccomp($c, $p, 0));
        self::assertSame('0', bcmul($d, $c, 0));
        self::assertSame('0', bcmul($d, $u, 0));
    }
}
