<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Eet;

use MyInvoice\Service\Eet\EetDelegation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla vyplnění eic_poverujiciho a povereni_vice_popl podle situací 1 až 5
 * dokumentu FS „Popis položek datové zprávy s příklady jejich vyplnění“ v1.0.
 */
final class EetDelegationTest extends TestCase
{
    private const TAXPAYER = 'CZ683555118';

    /** @return iterable<string, array{EetDelegation, ?string}> */
    public static function cases(): iterable
    {
        yield 'situace 1 až 3: bez pověření' => [EetDelegation::none(), null];
        yield 'bez pověření, ale s EIČ pověřujícího' => [new EetDelegation('none', 'CZ00000019'), 'delegating_eic'];
        yield 'situace 4: pověření jedním poplatníkem' => [new EetDelegation('single', 'CZ00000019'), null];
        yield 'situace 4 bez EIČ pověřujícího' => [new EetDelegation('single', null), 'delegating_eic'];
        yield 'situace 5, příklad 1: člen společenství' => [new EetDelegation('multiple', null), null];
        yield 'situace 5, příklad 2: třetí osoba s EIČ' => [new EetDelegation('multiple', 'CZ8551015704'), null];
        yield 'shodné EIČ pověřujícího a poplatníka' => [new EetDelegation('single', self::TAXPAYER), 'delegating_eic'];
        yield 'shodné EIČ i u více poplatníků' => [new EetDelegation('multiple', self::TAXPAYER), 'delegating_eic'];
        yield 'EIČ bez předpony CZ' => [new EetDelegation('single', '00000019'), 'delegating_eic'];
        yield 'neznámý druh' => [new EetDelegation('joint', null), 'delegation'];
    }

    #[DataProvider('cases')]
    public function testRules(EetDelegation $delegation, ?string $field): void
    {
        self::assertSame($field, $delegation->violation(self::TAXPAYER)['field'] ?? null);
    }

    public function testMissingTaxpayerEicSkipsOnlyTheEqualityCheck(): void
    {
        self::assertNull((new EetDelegation('single', self::TAXPAYER))->violation(null));
        self::assertSame('delegating_eic', (new EetDelegation('single', null))->violation(null)['field'] ?? null);
    }

    public function testItemsMapToModes(): void
    {
        self::assertSame(['delegation' => 'none', 'delegating_eic' => null, 'multiple_delegators' => false], EetDelegation::fromItems(null, false)->toArray());
        self::assertSame(['delegation' => 'single', 'delegating_eic' => 'CZ00000019', 'multiple_delegators' => false], EetDelegation::fromItems(' cz00000019 ', false)->toArray());
        self::assertSame(['delegation' => 'multiple', 'delegating_eic' => null, 'multiple_delegators' => true], EetDelegation::fromItems('', true)->toArray());
        self::assertSame('multiple', EetDelegation::fromRecord(['delegating_eic' => 'CZ00000019', 'multiple_delegators' => '1'])->mode);
        self::assertSame('single', EetDelegation::fromDevice(['delegation' => 'single', 'delegating_eic' => 'CZ00000019'])->mode);
    }
}
