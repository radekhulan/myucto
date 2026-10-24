<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration;

use MyInvoice\Service\Migration\Shared\ImportedAccountCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportedAccountCodeTest extends TestCase
{
    #[DataProvider('codes')]
    public function testNormalizesOnlyNumericAnalytics(string $source, string $target): void
    {
        self::assertSame($target, ImportedAccountCode::normalize($source));
        self::assertSame($target, ImportedAccountCode::normalize($target));
    }

    public static function codes(): iterable
    {
        yield ['022024', '022.024'];
        yield ['082024', '082.024'];
        yield ['343100', '343.100'];
        yield ['551000', '551.000'];
        yield ['3211', '321.1'];
        yield ['321123456', '321.123456'];
        yield [' 022024 ', '022.024'];
        yield ['343', '343'];
        yield ['013', '013'];
        yield ['343.100', '343.100'];
        yield ['221kb', '221kb'];
        yield ['311D', '311D'];
    }

    public function testExplicitLegacyMappingKeepsExistingAccount(): void
    {
        self::assertSame('022024', ImportedAccountCode::target('022024', ['022024' => '022024']));
        self::assertSame('022.024', ImportedAccountCode::target('022024', []));
    }
}
