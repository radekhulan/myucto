<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Service\Accounting\PostingOriginService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla, na kterých stojí odpověď „podle jaké šablony ten zápis vznikl".
 *
 * Obojí je čistá funkce právě proto, aby se dalo ověřit bez databáze — a bez
 * ověření by šlo o tvrzení v UI, které nikdo nekontroluje.
 */
final class PostingOriginRulesTest extends TestCase
{
    /**
     * Odvození předkontací přijaté faktury musí kopírovat PostingService: druh výdaje
     * na řádcích vyhrává, každý druh dá svůj klíč a duplicity se slučují.
     */
    public function testReceivedPresetsFollowExpenseKindsOnItems(): void
    {
        $keys = PostingOriginService::receivedPresetKeys(['service', 'material', 'service'], false);

        self::assertSame(
            ['invoice.services.received' => 'expense_kind', 'invoice.material.received' => 'expense_kind'],
            $keys,
            'Každý druh výdaje na dokladu má vlastní předkontaci; dvakrát týž druh je jedna.',
        );
    }

    /** Drobný nehmotný majetek je vlastní druh (518 vs. 501) — nesmí splynout s hmotným. */
    public function testSmallIntangibleHasOwnPreset(): void
    {
        self::assertSame(
            ['invoice.small_asset.received' => 'expense_kind', 'invoice.small_intangible.received' => 'expense_kind'],
            PostingOriginService::receivedPresetKeys(['small_asset', 'small_intangible'], false),
        );
    }

    /**
     * Bez klasifikace řádků spadne doklad na JEDINÝ výchozí klíč — a ten se liší podle
     * příznaku pořízení dlouhodobého majetku (042 místo 518). Zaměnit je znamená
     * poslat účetní opravovat předkontaci, podle které se neúčtovalo.
     */
    public function testUnclassifiedFallsBackToSingleDefaultKey(): void
    {
        self::assertSame(
            [PostingOriginService::DEFAULT_RECEIVED_KEY => 'default'],
            PostingOriginService::receivedPresetKeys([''], false),
        );
        self::assertSame(
            [PostingOriginService::DEFAULT_ASSET_KEY => 'default'],
            PostingOriginService::receivedPresetKeys([], true),
        );
    }

    /** Neznámý druh (starší data, ručně upravená DB) se ignoruje a doklad spadne na default. */
    public function testUnknownExpenseKindDoesNotInventAPreset(): void
    {
        self::assertSame(
            [PostingOriginService::DEFAULT_RECEIVED_KEY => 'default'],
            PostingOriginService::receivedPresetKeys(['nesmysl'], false),
        );
    }

    /**
     * Zápis nese analytiku, předkontace syntetiku — porovnání MUSÍ být prefixové.
     * Kdyby bylo na rovnost, hlásilo by UI „předkontace se nepoužila" prakticky
     * u každého dokladu firmy s analytickou osnovou.
     */
    public function testPresetAccountMatchesAnalyticAccountInEntry(): void
    {
        self::assertTrue(PostingOriginService::accountPresent('518', ['518.100', '321.000']));
        self::assertTrue(PostingOriginService::accountPresent('518', ['518']));
        self::assertFalse(PostingOriginService::accountPresent('518', ['501.100', '321.000']));
    }

    /** Prázdná strana předkontace je záměr (protiúčet doplní služba) → nebrání shodě. */
    public function testEmptyPresetSideCountsAsMatched(): void
    {
        self::assertTrue(PostingOriginService::accountPresent(null, ['563.000']));
        self::assertTrue(PostingOriginService::accountPresent('', ['563.000']));
    }

    /**
     * Výchozí klíče předkontací drží účtovací jádro; čtecí model je jen přebírá.
     * Kdyby se rozešly, ukazovalo by UI předkontaci, podle které se neúčtovalo —
     * a poznalo by se to až tím, že oprava šablony nic neudělá.
     */
    public function testDefaultKeysComeFromThePostingCore(): void
    {
        self::assertSame(PostingService::DEFAULT_ISSUED_RULE_KEY, PostingOriginService::DEFAULT_ISSUED_KEY);
        self::assertSame(PostingService::DEFAULT_RECEIVED_RULE_KEY, PostingOriginService::DEFAULT_RECEIVED_KEY);
        self::assertSame(PostingService::DEFAULT_RECEIVED_ASSET_RULE_KEY, PostingOriginService::DEFAULT_ASSET_KEY);
    }

    /**
     * Segment URL → zdroj zápisu. Bankovní pohyb má v deníku `bank`, ne
     * `bank_transaction`; kdyby se zápis hledal pod špatným zdrojem, přeúčtovala by
     * se faktura téhož id (nebo by se nenašlo nic).
     */
    public function testUrlSegmentMapsToJournalSourceType(): void
    {
        self::assertSame('bank', JournalAction::repostSourceType('bank-transactions'));
        self::assertSame('purchase_invoice', JournalAction::repostSourceType('purchase-invoices'));
        self::assertSame('invoice', JournalAction::repostSourceType('invoices'));
    }
}
