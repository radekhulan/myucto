<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\StockLocaleRepository;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\ProductCardService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic ESHOP — číselník jazyků (migrace 1370). Jazyky karty zboží dřív držel
 * pevný seznam ve frontendu; teď je vede číselník per supplier a zápisová
 * cesta mimo něj nepustí.
 */
#[Group('integration')]
final class EshopLocaleCodebookTest extends StockTestCase
{
    private StockLocaleRepository $locales;
    private ProductCardService $cards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->locales = $this->container->get(StockLocaleRepository::class);
        $this->cards = $this->container->get(ProductCardService::class);
    }

    public function testCodebookIsScopedPerSupplier(): void
    {
        $a = $this->createSupplier();
        $b = $this->createSupplier();

        $this->locales->insert($a, ['code' => 'hu', 'name' => 'Magyar']);

        self::assertSame(['hu'], $this->locales->codes($a));
        self::assertSame([], $this->locales->codes($b), 'Jazyky firmy A nesmí prosáknout do firmy B.');
    }

    public function testSameCodeMayExistForTwoSuppliers(): void
    {
        $a = $this->createSupplier();
        $b = $this->createSupplier();

        $this->locales->insert($a, ['code' => 'de', 'name' => 'Deutsch']);
        $this->locales->insert($b, ['code' => 'de', 'name' => 'Deutsch']);

        self::assertNotNull($this->locales->findByCode($a, 'de'));
        self::assertNotNull($this->locales->findByCode($b, 'de'));
    }

    public function testArchivedLocaleStaysWritable(): void
    {
        // Archivace jazyk jen stáhne z nabídky pro nové řádky. Formulář posílá
        // celou sadu překladů zpátky, takže archivovaný jazyk musí projít —
        // jinak by se karta s takovým překladem přestala dát uložit.
        $sid = $this->createSupplier();
        $id = $this->locales->insert($sid, ['code' => 'pl', 'name' => 'Polski', 'archived' => true]);
        self::assertNotSame(0, $id);

        self::assertSame(['pl'], $this->locales->codes($sid));
        self::assertSame([], $this->locales->listForSupplier($sid, true), 'activeOnly nesmí archivovaný jazyk vrátit.');
    }

    public function testProductTranslationRequiresLocaleFromCodebook(): void
    {
        $sid = $this->createSupplier();
        $itemId = $this->item($sid, 'LOC-1');
        $this->locales->insert($sid, ['code' => 'cs', 'name' => 'Čeština', 'is_default' => true]);

        // Jazyk z číselníku projde…
        $this->cards->update($sid, $itemId, [
            'row_version' => 1,
            'i18n' => [['locale' => 'cs', 'name' => 'Zboží']],
        ]);
        $card = $this->cards->get($sid, $itemId);
        self::assertSame('cs', $card['i18n'][0]['locale']);
        self::assertSame('Zboží', $card['i18n'][0]['name']);

        // …jazyk mimo číselník ne.
        try {
            $this->cards->update($sid, $itemId, [
                'row_version' => 2,
                'i18n' => [['locale' => 'cs', 'name' => 'Zboží'], ['locale' => 'no', 'name' => 'Vare']],
            ]);
            self::fail('Jazyk mimo číselník musí skončit chybou unknown_locale.');
        } catch (EshopException $e) {
            self::assertSame('unknown_locale', $e->errorCode);
            self::assertSame(400, $e->httpStatus);
        }

        // Odmítnutý zápis nesmí nechat půlku uloženou.
        $after = $this->cards->get($sid, $itemId);
        self::assertCount(1, $after['i18n'], 'Po odmítnutí musí zůstat původní jediný překlad.');
        self::assertSame('cs', $after['i18n'][0]['locale']);
    }

    public function testReferencedLocaleIsDetected(): void
    {
        $sid = $this->createSupplier();
        $itemId = $this->item($sid, 'LOC-2');
        $this->locales->insert($sid, ['code' => 'en', 'name' => 'English']);
        $this->locales->insert($sid, ['code' => 'sk', 'name' => 'Slovenčina']);

        $this->cards->update($sid, $itemId, [
            'row_version' => 1,
            'i18n' => [['locale' => 'en', 'name' => 'Goods']],
        ]);

        self::assertTrue($this->locales->isReferenced($sid, 'en'), 'Jazyk s překladem je referencovaný — nesmí jít smazat.');
        self::assertFalse($this->locales->isReferenced($sid, 'sk'));
    }

    public function testClearDefaultExceptLeavesSingleDefault(): void
    {
        $sid = $this->createSupplier();
        $cs = $this->locales->insert($sid, ['code' => 'cs', 'name' => 'Čeština', 'is_default' => true]);
        $en = $this->locales->insert($sid, ['code' => 'en', 'name' => 'English', 'is_default' => true]);

        $this->locales->clearDefaultExcept($sid, $en);

        self::assertFalse($this->locales->find($sid, $cs)['is_default']);
        self::assertTrue($this->locales->find($sid, $en)['is_default']);
    }
}
