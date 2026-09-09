<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\StockLocaleRepository;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\ProductCardService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ProductCardCzechLocaleBootstrapTest extends StockTestCase
{
    private ProductCardService $cards;
    private StockLocaleRepository $locales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cards = $this->container->get(ProductCardService::class);
        $this->locales = $this->container->get(StockLocaleRepository::class);
    }

    public function testFirstCzechDescriptionCreatesMissingLocaleOnlyWhenSaved(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'CZ-LOCALE-BOOTSTRAP');

        self::assertSame([], $this->locales->codes($supplierId));
        $this->cards->get($supplierId, $itemId);
        self::assertSame([], $this->locales->codes($supplierId), 'Pouhé načtení karty nesmí měnit číselník.');

        $saved = $this->cards->update($supplierId, $itemId, [
            'row_version' => 1,
            'i18n' => [[
                'locale' => 'cs',
                'name' => 'Český název',
                'description' => 'Český popis',
            ]],
        ]);

        self::assertSame(['cs'], $this->locales->codes($supplierId));
        $czech = $this->locales->findByCode($supplierId, 'cs');
        self::assertNotNull($czech);
        self::assertSame('Čeština', $czech['name']);
        self::assertTrue($czech['is_default']);
        self::assertFalse($czech['archived']);
        self::assertSame('cs', $saved['i18n'][0]['locale']);
        self::assertSame('Český název', $saved['i18n'][0]['name']);
        self::assertSame('Český popis', $saved['i18n'][0]['description']);
    }

    public function testUnknownNonCzechLocaleRemainsRejected(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'UNKNOWN-LOCALE');

        try {
            $this->cards->update($supplierId, $itemId, [
                'row_version' => 1,
                'i18n' => [
                    ['locale' => 'cs', 'name' => 'Český název'],
                    ['locale' => 'de', 'name' => 'Unbekannter Artikel'],
                ],
            ]);
            self::fail('Neznámý jazyk mimo češtinu musí zůstat odmítnutý.');
        } catch (EshopException $e) {
            self::assertSame('unknown_locale', $e->errorCode);
            self::assertSame(400, $e->httpStatus);
        }

        self::assertSame([], $this->locales->codes($supplierId), 'Odmítnutí musí vrátit zpět i automaticky založenou češtinu.');
        self::assertSame([], $this->cards->get($supplierId, $itemId)['i18n']);
    }
}
