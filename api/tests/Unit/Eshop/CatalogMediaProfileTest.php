<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\Import\CatalogImportProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogMediaProfileTest extends TestCase
{
    public function testMapsAtMostTenHttpsMediaUrls(): void
    {
        $profile = CatalogImportProfile::normalize([
            'mapping' => ['sku' => 'sku', 'media_urls' => 'media'],
        ]);
        self::assertSame([
            'media_urls' => ['https://cdn.example.test/a.png', 'https://cdn.example.test/b.pdf'],
            'sku' => 'MEDIA-1',
        ], CatalogImportProfile::map(
            $profile,
            ['sku', 'media'],
            ['MEDIA-1', '["https://cdn.example.test/a.png","https://cdn.example.test/b.pdf"]'],
        ));
    }

    #[DataProvider('invalidMedia')]
    public function testRejectsUnsafeOrOversizedMediaCollections(string $json): void
    {
        $profile = CatalogImportProfile::normalize([
            'mapping' => ['sku' => 'sku', 'media_urls' => 'media'],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        CatalogImportProfile::map($profile, ['sku', 'media'], ['MEDIA-1', $json]);
    }

    public static function invalidMedia(): iterable
    {
        yield 'plain http' => ['["http://cdn.example.test/a.png"]'];
        yield 'credentials whitespace' => ['["https://user:pass@cdn.example.test/a.png "]'];
        yield 'more than ten' => [json_encode(array_fill(0, 11, 'https://cdn.example.test/a.png'), JSON_THROW_ON_ERROR)];
        yield 'non-string' => ['[123]'];
    }

    public function testMediaCannotBeClearedBecauseImportIsAdditive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CatalogImportProfile::normalize([
            'mapping' => ['sku' => 'sku', 'media_urls' => 'media'],
            'operations' => ['media_urls' => 'clear'],
        ]);
    }
}
