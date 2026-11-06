<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxOpeningMovementProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StereoNxOpeningMovementProofTest extends TestCase
{
    #[DataProvider('instruments')]
    public function testPublicProofVerifiesIdentityWithoutRelyingOnCallerIndex(string $kind, string $prefix, string $agenda): void
    {
        [$movement, $entry] = self::fixture($kind, $prefix, $agenda);
        $result = StereoNxOpeningMovementProof::prove($movement, [$entry], $prefix, $prefix . '.100');
        self::assertSame(['opening_cents' => 12300, 'entry_keys' => [$entry['source_key']],
            'account_code' => $prefix . '.100', 'date' => '2026-01-01'], $result);
        foreach ([[$agenda, 'OTHER', '18', '4', '0'], [$agenda, 'S', 'OTHER', '4', '0'],
            [$agenda === 'P' ? 'B' : 'P', 'S', '18', '4', '0']] as $parts) {
            $other = array_replace($entry, ['source_key' => json_encode($parts, JSON_THROW_ON_ERROR)]);
            self::assertNull(StereoNxOpeningMovementProof::prove($movement, [$other], $prefix));
        }
        self::assertNull(StereoNxOpeningMovementProof::prove($movement, [$entry], $prefix, $prefix . '.101'));
        self::assertNull(StereoNxOpeningMovementProof::prove($movement, [$entry], '701'));
    }

    #[DataProvider('instruments')]
    public function testIndexPreservesOrdinaryEntriesSoProofCannotDiscardMixedGroup(string $kind, string $prefix, string $agenda): void
    {
        [$movement, $entry] = self::fixture($kind, $prefix, $agenda);
        $ordinary = array_replace($entry, ['source_key' => json_encode([$agenda, 'S', '18', '4', '1'], JSON_THROW_ON_ERROR),
            'is_opening' => false]);
        $index = StereoNxOpeningMovementProof::index(['accounting_plan' => ['entries' => [$entry, $ordinary]]]);
        self::assertSame([$entry, $ordinary], $index[$kind][$movement['source_key']]);
        self::assertNull(StereoNxOpeningMovementProof::prove($movement, $index[$kind][$movement['source_key']], $prefix));
    }

    public function testBankLineIdentityCannotBeExchangedWhileCashGroupsAllPostingLines(): void
    {
        [$bank, $entry] = self::fixture('bank', '221', 'B');
        $entry['source_key'] = '["B","S","18","5","0"]';
        self::assertNull(StereoNxOpeningMovementProof::prove($bank, [$entry], '221'));
        [$cash, $entry] = self::fixture('cash', '211', 'P');
        $entry['source_key'] = '["P","S","18","5","0"]';
        self::assertNotNull(StereoNxOpeningMovementProof::prove($cash, [$entry], '211'));
    }

    public static function instruments(): iterable
    {
        yield 'banka' => ['bank', '221', 'B'];
        yield 'pokladna' => ['cash', '211', 'P'];
    }

    private static function fixture(string $kind, string $prefix, string $agenda): array
    {
        return [['source_key' => $kind === 'bank' ? '["2025","S","18","4"]' : '["2025","S","18"]',
            'date' => '2026-01-01', 'amount' => 123.0, 'currency' => 'CZK'],
            ['source_key' => json_encode([$agenda, 'S', '18', '4', '0'], JSON_THROW_ON_ERROR),
                'date' => '2026-01-01', 'posting_year' => 2025, 'source_year' => 2026,
                'is_opening' => true, 'is_red_storno' => false, 'debit' => $prefix . '100', 'credit' => '701',
                'amount_cents' => 12300]];
    }
}
