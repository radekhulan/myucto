<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Repository\OtherItemSettingsRepository;
use MyInvoice\Service\Accounting\OtherItemException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OtherItemSettingsRepositoryTest extends TestCase
{
    /** @return iterable<string,array{?string,string}> */
    public static function folders(): iterable
    {
        yield 'prázdné = výchozí' => ['', OtherItemSettingsRepository::DEFAULT_DOCUMENT_FOLDER];
        yield 'null = výchozí' => [null, OtherItemSettingsRepository::DEFAULT_DOCUMENT_FOLDER];
        yield 'jen lomítka = výchozí' => [' / / ', OtherItemSettingsRepository::DEFAULT_DOCUMENT_FOLDER];
        yield 'zástupné znaky zůstanou' => ['Smlouvy/Pozemky/{YYYY}', 'Smlouvy/Pozemky/{YYYY}'];
        yield 'zpětná lomítka a mezery' => ['\\Smlouvy\\ Pozemky //{MM}/ ', 'Smlouvy/Pozemky/{MM}'];
    }

    #[DataProvider('folders')]
    public function testNormalizeFolder(?string $input, string $expected): void
    {
        self::assertSame($expected, OtherItemSettingsRepository::normalizeFolder($input));
    }

    /** @return iterable<string,array{string}> */
    public static function invalid(): iterable
    {
        yield 'nadřazená složka' => ['Smlouvy/../Mzdy'];
        yield 'aktuální složka' => ['./Smlouvy'];
        yield 'řídicí znak' => ["Smlouvy/\x01"];
        yield 'příliš dlouhá' => [str_repeat('a', 501)];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidFolder(string $input): void
    {
        $this->expectException(OtherItemException::class);
        OtherItemSettingsRepository::normalizeFolder($input);
    }
}
