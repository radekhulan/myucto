<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Repository\VatClassificationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Kód předmětu plnění (KH A.1/B.1) má v číselníku MFČR i hodnoty s písmenným sufixem
 * (`1a` odpad a šrot § 92c, `3a`). Validace v `VatClassificationsAction` je pouští,
 * jenže normalizace v repository z nich nechávala jen číslice — `1a` se tiše uložilo
 * jako `1` (dodání zlata) a do kontrolního hlášení šel systematicky jiný režim § 92,
 * než jaký admin vybral. Tichý přepis vstupu je horší než odmítnutí.
 */
final class VatClassificationKodPredPlTest extends TestCase
{
    /**
     * @return list<array{0: mixed, 1: ?string}>
     */
    public static function values(): array
    {
        return [
            ['1a', '1a'],
            ['3a', '3a'],
            [' 1A ', '1a'],
            ['4', '4'],
            ['', null],
            [null, null],
            // Text nezačínající číslicí není zkomolený kód — dřív z „A.1" tiše vzniklo
            // `1` (dodání zlata). Zůstává kontrakt migrace: XSD `maxLength=3`.
            ['abc', null],
            ['A.1', null],
            ['12345', '123'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function testKodPredPlKeepsLetterSuffix(mixed $input, ?string $expected): void
    {
        $method = new \ReflectionMethod(VatClassificationRepository::class, 'normalizeKodPredPl');

        self::assertSame($expected, $method->invoke(null, $input));
    }
}
