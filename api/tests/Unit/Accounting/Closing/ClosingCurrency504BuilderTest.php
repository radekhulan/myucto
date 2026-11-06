<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Closing\{ClosingCurrency504Builder, ClosingEntryBuilder, ClosingException, OpeningEntryExpectation};
use PHPUnit\Framework\TestCase;

final class ClosingCurrency504BuilderTest extends TestCase
{
    public function testTwoCurrenciesOnSameAccountAndTupleStaySeparateAcrossClosingAndOpening(): void
    {
        $rows = [self::row(1, 'EUR', 25000, 1000), self::row(2, 'USD', 22000, 1000)];
        $balances = ClosingCurrency504Builder::balances($rows);
        self::assertCount(2, $balances);
        $profile = new AccountingRoleProfile('cz_504');
        $built = (new ClosingEntryBuilder())->closingLines([
            ['account_id' => 20, 'account_code' => '645', 'name' => 'Synthetic', 'bal' => -47000, 'dimensions' => [1 => 11]],
        ], $balances, $profile);
        $lines = [];
        foreach ($built['lines'] as $index => $line) {
            if ($line['account_code'] !== '311') self::assertArrayNotHasKey('currency_code', $line);
            $lines[] = $line + ['id' => $index + 1, 'account_id' => $index + 1, 'account_type' => $line['account_code'] === '645' ? 'revenue' : ($line['account_code'] === '311' ? 'asset' : 'equity')];
        }
        $opening = OpeningEntryExpectation::fromClosingLines($lines, 47000, $profile);
        $receivables = array_values(array_filter($opening, static fn (array $line): bool => $line['account_code'] === '311'));
        self::assertSame(['EUR', 'USD'], array_column($receivables, 'currency_code'));
        self::assertSame([1000, 1000], array_column($receivables, 'amount_foreign'));
        self::assertSame([25000.0, 22000.0], array_column($receivables, 'amount'));
        self::assertSame([[1 => 11], [1 => 11]], array_column($receivables, 'dimensions'));
    }

    public function test500SerializationDoesNotChangeWhenInputIncludesFxMetadata(): void
    {
        $builder = new ClosingEntryBuilder();
        $bs = [['account_id' => 10, 'account_code' => '311', 'name' => 'Synthetic', 'bal' => 25000]];
        $foreign = [array_replace($bs[0], ['currency_code' => 'EUR', 'amount_foreign' => 1000, 'fx_rate' => 25])];
        $pl = [['account_id' => 20, 'account_code' => '602', 'name' => 'Synthetic', 'bal' => -25000]];
        self::assertSame(serialize($builder->closingLines($pl, $bs)), serialize($builder->closingLines($pl, $foreign)));
        self::assertSame(serialize($builder->openingLines($bs, 25000)), serialize($builder->openingLines($foreign, 25000)));
    }

    public function testCancellationRemovesBothCzkAndForeignBalance(): void
    {
        $row = self::row(1, 'EUR', 25000, 1000);
        self::assertSame([], ClosingCurrency504Builder::balances([$row, array_replace($row, ['id' => 2, 'side' => 'credit'])]));
    }

    public function testForeignBalanceWithoutCarryingAmountCannotSilentlyDisappear(): void
    {
        $this->expectException(ClosingException::class);
        ClosingCurrency504Builder::balances([self::row(1, 'EUR', 25000, 1000), array_replace(self::row(2, 'EUR', 25000, 0), ['side' => 'credit'])]);
    }

    private static function row(int $id, string $currency, int $amount, int $foreign): array
    {
        return ['id' => $id, 'account_id' => 10, 'account_code' => '311', 'account_name' => 'Synthetic',
            'side' => 'debit', 'signed_amount' => $amount, 'amount' => $amount, 'is_red_storno' => false,
            'currency_code' => $currency, 'amount_foreign' => $foreign, 'dimensions' => [1 => 11]];
    }
}
