<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\ImportedTaxDatePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportedTaxDatePolicyTest extends TestCase
{
    /** @return array<string, array{0:mixed, 1:mixed, 2:mixed, 3:string, 4:?string}> */
    public static function cases(): array
    {
        return [
            'účtenka s jediným datem'   => [null, null, '2026-11-01', 'receipt', '2026-11-01'],
            'faktura s jediným datem'   => [null, null, '2026-11-01', 'invoice', '2026-11-01'],
            'prázdný text jako chybějící' => ['  ', '', '2026-11-01', 'invoice', '2026-11-01'],
            'DUZP z dokladu má přednost' => ['2026-10-31', null, '2026-11-01', 'invoice', '2026-10-31'],
            'záloha DUZP nemá'          => [null, null, '2026-11-01', 'advance', null],
            'dobropis se řídí § 42'     => [null, null, '2026-11-01', 'credit_note', null],
            'vytěžené datum dodání'     => [null, '2026-10-30', '2026-11-01', 'invoice', null],
            'neplatné datum vystavení'  => [null, null, '1. 11. 2026', 'invoice', null],
        ];
    }

    #[DataProvider('cases')]
    public function testResolve(mixed $tax, mixed $delivery, mixed $issue, string $kind, ?string $expected): void
    {
        self::assertSame($expected, ImportedTaxDatePolicy::resolve($tax, $delivery, $issue, $kind));
    }
}
