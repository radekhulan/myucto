<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Closing\ClosingEntryBuilder;
use MyInvoice\Service\Accounting\Closing\OpeningTakeoverComparison;
use MyInvoice\Service\Accounting\Closing\OpeningEntryExpectation;
use PHPUnit\Framework\TestCase;

final class FrameworkClosingRolesTest extends TestCase
{
    public function test504ClosingUses933And962(): void
    {
        $result = (new ClosingEntryBuilder())->closingLines(
            [['account_code' => '501', 'bal' => 20], ['account_code' => '601', 'bal' => -120]],
            [['account_code' => '221', 'bal' => 100]],
            new AccountingRoleProfile('cz_504'),
        );
        self::assertSame(['933', '501', '601', '933', '962', '933', '933', '962', '962', '221'], array_column($result['lines'], 'account_code'));
        self::assertSame(100.0, $result['profit']);
    }

    public function test504OpeningTransfersBothSignsOf932To931(): void
    {
        foreach ([250.0, -250.0] as $balance) {
            $lines = (new ClosingEntryBuilder())->openingLines([
                ['account_code' => '932100', 'bal' => $balance, 'dimensions' => [7 => 10]],
                ['account_code' => '221', 'bal' => -$balance],
            ], 0.0, new AccountingRoleProfile('cz_504'));
            self::assertNotContains('932100', array_column($lines, 'account_code'));
            $pending = array_values(array_filter($lines, static fn (array $line): bool => $line['account_code'] === '931'));
            self::assertCount(1, $pending);
            self::assertSame($balance > 0 ? 'debit' : 'credit', $pending[0]['side']);
            self::assertSame([7 => 10], $pending[0]['dimensions']);
            self::assertContains('961', array_column($lines, 'account_code'));
        }
    }

    public function test500ExplicitProfileIsByteIdenticalToLegacyDefault(): void
    {
        $builder = new ClosingEntryBuilder();
        $pl = [['account_code' => '601', 'bal' => -300.22]];
        $bs = [['account_code' => '221', 'bal' => 400.22], ['account_code' => '428001', 'bal' => -100.0]];
        self::assertSame('c558451362b2d55980b9b7059241130921fddf30b3705caf787467ef6c767e28', hash('sha256', serialize($builder->closingLines($pl, $bs))));
        self::assertSame('93a38e20b908d52742ae8b4e842c550642df8241bb05d9e28c54957aaa618fa2', hash('sha256', serialize($builder->openingLines($bs, 300.22))));
        self::assertSame(serialize($builder->closingLines($pl, $bs)), serialize($builder->closingLines($pl, $bs, new AccountingRoleProfile())));
        self::assertSame(serialize($builder->openingLines($bs, 300.22)), serialize($builder->openingLines($bs, 300.22, new AccountingRoleProfile())));
    }

    public function test504TakeoverIgnores96AndAggregates931Analytics(): void
    {
        self::assertSame(['diff' => [], 'accounts' => 1], OpeningTakeoverComparison::compare(
            [['account_code' => '961', 'side' => 'debit', 'amount' => 15.0], ['account_code' => '931', 'side' => 'credit', 'amount' => 15.0]],
            ['961100' => 15.0, '931200' => -15.0],
            new AccountingRoleProfile('cz_504'),
        ));
    }
    public function test504SnapshotOpeningPreservesResultDimensionsAndExcludes933Equity(): void
    {
        $profile = new AccountingRoleProfile('cz_504');
        $builder = new ClosingEntryBuilder();
        $pl = [
            ['account_code' => '501', 'bal' => 0.01, 'dimension_splits' => [7 => [10 => 50, 11 => 50]]],
            ['account_code' => '601', 'bal' => -1.01, 'dimensions' => [7 => 10, 8 => 20]],
        ];
        $bs = [['account_code' => '221', 'bal' => 6.0], ['account_code' => '932100', 'bal' => -5.0, 'dimensions' => [7 => 11]]];
        $closed = $builder->closingLines($pl, $bs, $profile);
        $typed = array_map(static fn (array $line): array => $line + [
            'account_id' => (int) $line['account_code'],
            'account_type' => match ($line['account_code']) { '501' => 'expense', '601' => 'revenue', '221' => 'asset', default => 'equity' },
        ], $closed['lines']);
        $expected = $builder->openingLines($bs, 1.0, $profile, ClosingEntryBuilder::resultBalances($pl));
        $actual = OpeningEntryExpectation::fromClosingLines($typed, 1.0, $profile);
        self::assertSame($expected, $actual);
        self::assertNotContains('933', array_column($actual, 'account_code'));
        self::assertNotContains('962', array_column($actual, 'account_code'));
        $pending = array_values(array_filter($actual, static fn (array $line): bool => $line['account_code'] === '931'));
        self::assertCount(3, $pending);
        self::assertSame([7 => [10 => 50, 11 => 50]], $pending[1]['dimension_splits']);
        self::assertSame([7 => 10, 8 => 20], $pending[2]['dimensions']);
    }

    public function test504ZeroTotalResultRetainsOppositeActivityResults(): void
    {
        $profile = new AccountingRoleProfile('cz_504');
        $pl = [
            ['account_code' => '518', 'bal' => 100.0, 'dimensions' => [7 => 10]],
            ['account_code' => '602', 'bal' => -100.0, 'dimensions' => [7 => 11]],
        ];
        $builder = new ClosingEntryBuilder();
        $closed = $builder->closingLines($pl, [], $profile);
        self::assertSame(0.0, $closed['profit']);
        $opened = $builder->openingLines([], 0.0, $profile, ClosingEntryBuilder::resultBalances($pl));
        self::assertSame([
            ['account_code' => '931', 'side' => 'debit', 'amount' => 100.0, 'dimensions' => [7 => 10]],
            ['account_code' => '961', 'side' => 'credit', 'amount' => 100.0, 'dimensions' => [7 => 10]],
            ['account_code' => '961', 'side' => 'debit', 'amount' => 100.0, 'dimensions' => [7 => 11]],
            ['account_code' => '931', 'side' => 'credit', 'amount' => 100.0, 'dimensions' => [7 => 11]],
        ], $opened);
    }

}
