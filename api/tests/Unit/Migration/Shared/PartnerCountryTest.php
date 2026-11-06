<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Shared;

use MyInvoice\Service\Migration\Shared\PartnerCountry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Země partnera z DIČ při převodu: jen předpona členského státu u tvarově platného DIČ.
 * Syntetické hodnoty.
 */
final class PartnerCountryTest extends TestCase
{
    /** @return array<string,array{0:?string,1:?string}> */
    public static function cases(): array
    {
        return [
            'irské DIČ'                 => ['IE4276970QH', 'IE'],
            'české DIČ'                 => ['CZ12345678', 'CZ'],
            'Řecko EL → ISO GR'         => ['EL123456789', 'GR'],
            'oddělovače'                => ['de 123 456 789', 'DE'],
            // Číslo zvláštního režimu OSS pro osoby mimo EU zemi neurčuje.
            'OSS číslo mimo EU'         => ['EU372041333', null],
            'britské DIČ po Brexitu'    => ['GB123456789', null],
            'volný text'                => ['DEutschland s.r.o.', null],
            'bez předpony'              => ['12345678', null],
            'prázdné'                   => [null, null],
        ];
    }

    #[DataProvider('cases')]
    public function testCountryFromVatId(?string $vatId, ?string $expected): void
    {
        self::assertSame($expected, PartnerCountry::fromVatId($vatId));
    }
}
