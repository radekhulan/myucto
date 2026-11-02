<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Invoice;

use MyInvoice\Service\Invoice\DocumentItemsPayload;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla čtení klíče `items` v těle requestu — SSOT pro vydané i přijaté doklady.
 *
 * @see \MyInvoice\Service\Invoice\DocumentItemsPayload
 */
final class DocumentItemsPayloadTest extends TestCase
{
    public function testMissingKeyMeansDoNotTouchItems(): void
    {
        self::assertFalse(DocumentItemsPayload::replaces(['tax_date' => '2026-01-01']));
        self::assertFalse(DocumentItemsPayload::replaces(['items' => null]));
        self::assertTrue(DocumentItemsPayload::replaces(['items' => []]));
        self::assertTrue(DocumentItemsPayload::replaces(['items' => [['description' => 'X']]]));
    }

    public function testEmptiesExistingOnlyForExplicitEmptyArrayOnDocumentWithItems(): void
    {
        $stored = [['description' => 'X', 'quantity' => 1.0, 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 1]];

        self::assertTrue(DocumentItemsPayload::emptiesExisting(['items' => []], $stored));
        self::assertFalse(DocumentItemsPayload::emptiesExisting([], $stored),
            'Chybějící klíč není příkaz k vyprázdnění.');
        self::assertFalse(DocumentItemsPayload::emptiesExisting(['items' => null], $stored),
            '`items: null` je artefakt serializace, ne příkaz k mazání.');
        self::assertFalse(DocumentItemsPayload::emptiesExisting(['items' => []], []),
            'Doklad bez položek nemá co ztratit — prázdné pole je no-op.');
    }

    public function testChangedComparesContentNotFormatting(): void
    {
        // Uložený řádek přichází z DB jako float, tělo requestu jako string z formuláře.
        $stored = [[
            'description' => 'Konzultace', 'quantity' => 1.0, 'unit' => 'ks',
            'unit_price_without_vat' => 1000.0, 'vat_rate_id' => 3,
            'total_with_vat' => 1210.0, 'order_index' => 0, 'vat_classification_code' => 'A1',
        ]];
        $sameFromForm = [[
            'description' => 'Konzultace', 'quantity' => '1.000', 'unit' => 'ks',
            'unit_price_without_vat' => '1000.00', 'vat_rate_id' => '3',
        ]];

        self::assertFalse(DocumentItemsPayload::changed($stored, $sameFromForm),
            'Formátová neshoda ani dopočtené sloupce nejsou změna položek.');

        $priceChanged = $sameFromForm;
        $priceChanged[0]['unit_price_without_vat'] = '1000.01';
        self::assertTrue(DocumentItemsPayload::changed($stored, $priceChanged));

        $rowAdded = [...$sameFromForm, ['description' => 'Licence', 'quantity' => 1, 'vat_rate_id' => 3]];
        self::assertTrue(DocumentItemsPayload::changed($stored, $rowAdded));

        self::assertTrue(DocumentItemsPayload::changed($stored, []));
        self::assertFalse(DocumentItemsPayload::changed([], []));
    }

    public function testExactDurationUsesMinutesInsteadOfLossyQuantityProjection(): void
    {
        $stored = [[
            'description' => 'Konzultace',
            'quantity' => 0.017,
            'duration_minutes' => 1,
            'unit' => 'h',
            'unit_price_without_vat' => 1000,
            'vat_rate_id' => 3,
        ]];
        $sameFromEditor = $stored;
        $sameFromEditor[0]['quantity'] = 1 / 60;

        self::assertFalse(DocumentItemsPayload::changed($stored, $sameFromEditor));

        $legacy = $stored;
        $legacy[0]['duration_minutes'] = null;
        self::assertTrue(DocumentItemsPayload::changed($stored, $legacy));

        $twoMinutes = $stored;
        $twoMinutes[0]['duration_minutes'] = 2;
        self::assertTrue(DocumentItemsPayload::changed($stored, $twoMinutes));
    }

    /**
     * Uložený OSS řádek tak, jak ho vrací `InvoiceRepository::find()` — čísla jako float,
     * příznaky jako bool.
     *
     * @return list<array<string,mixed>>
     */
    private static function storedOssRow(): array
    {
        return [[
            'description' => 'Předplatné', 'quantity' => 1.0, 'unit' => 'ks',
            'unit_price_without_vat' => 1000.0, 'vat_rate_id' => 7,
            'oss_applicable' => true,
            'oss_consumer_country' => 'DE',
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'services',
            'oss_exchange_rate' => 0.04,
            'oss_exchange_rate_date' => '2026-01-31',
            'oss_taxable_amount_return' => 40.0,
            'oss_vat_amount_return' => 7.6,
            'oss_original_period' => '2025Q4',
            'oss_needs_manual_review' => false,
            'total_with_vat' => 1210.0, 'order_index' => 0,
        ]];
    }

    /**
     * Země spotřeby určuje MÍSTO PLNĚNÍ — jiná země je jiný řádek OSS podání i jiné
     * zaúčtování daně, takže notes_only takovou editaci pustit nesmí.
     */
    public function testChangedDetectsOssConsumerCountryAlone(): void
    {
        $moved = self::storedOssRow();
        $moved[0]['oss_consumer_country'] = 'AT';

        self::assertTrue(DocumentItemsPayload::changed(self::storedOssRow(), $moved));
    }

    /** Typ sazby vybírá sazbu z číselníku členských států — mění odvedenou daň. */
    public function testChangedDetectsOssRateTypeAlone(): void
    {
        $reduced = self::storedOssRow();
        $reduced[0]['oss_rate_type'] = 'reduced';

        self::assertTrue(DocumentItemsPayload::changed(self::storedOssRow(), $reduced));
    }

    /** Ruční částky do podání i kurz jsou peníze — a NULL („dopočti") není nula. */
    public function testChangedDetectsOssReturnAmountsAndExchangeRate(): void
    {
        $otherVat = self::storedOssRow();
        $otherVat[0]['oss_vat_amount_return'] = 7.7;
        self::assertTrue(DocumentItemsPayload::changed(self::storedOssRow(), $otherVat));

        $otherRate = self::storedOssRow();
        $otherRate[0]['oss_exchange_rate'] = 0.041;
        self::assertTrue(DocumentItemsPayload::changed(self::storedOssRow(), $otherRate));

        $autoAmounts = self::storedOssRow();
        $autoAmounts[0]['oss_taxable_amount_return'] = null;
        $autoAmounts[0]['oss_vat_amount_return'] = null;
        self::assertTrue(DocumentItemsPayload::changed(self::storedOssRow(), $autoAmounts),
            'Vyprázdnění ručních částek přepne podání na dopočet — to není formátová neshoda.');
    }

    /**
     * Týž OSS řádek z formuláře: JSON nese stringy, zaškrtávátko int, zemi i období
     * repozitář kanonizuje na velká písmena. Nic z toho není účetní změna.
     */
    public function testOssFieldsAreComparedCanonically(): void
    {
        $sameFromForm = [[
            'description' => 'Předplatné', 'quantity' => '1', 'unit' => 'ks',
            'unit_price_without_vat' => '1000.00', 'vat_rate_id' => '7',
            'oss_applicable' => 1,
            'oss_consumer_country' => ' de ',
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'services',
            'oss_exchange_rate' => '0.0400',
            'oss_exchange_rate_date' => '2026-01-31',
            'oss_taxable_amount_return' => '40',
            'oss_vat_amount_return' => '7.60',
            'oss_original_period' => '2025q4',
            'oss_needs_manual_review' => false,
        ]];

        self::assertFalse(DocumentItemsPayload::changed(self::storedOssRow(), $sameFromForm));
    }

    /**
     * Poslaný kód členění přesouvá řádek v přiznání a KH, takže v podaném období nesmí
     * projít jako poznámková úprava. Vynechaný klíč dál změnou není.
     */
    public function testSentVatClassificationIsContentMissingKeyIsNot(): void
    {
        $stored = [[
            'description' => 'Zboží', 'quantity' => 1.0, 'unit' => 'ks',
            'unit_price_without_vat' => 1000.0, 'vat_rate_id' => 3, 'vat_classification_code' => '22',
        ]];
        $form = [[
            'description' => 'Zboží', 'quantity' => '1', 'unit' => 'ks',
            'unit_price_without_vat' => '1000.00', 'vat_rate_id' => '3',
        ]];

        self::assertFalse(DocumentItemsPayload::changed($stored, $form), 'Vynechaný kód se dopočítá.');
        self::assertFalse(DocumentItemsPayload::changed($stored, [[...$form[0], 'vat_classification_code' => '22']]),
            'Stejný kód poslaný zpět není změna.');
        self::assertTrue(DocumentItemsPayload::changed($stored, [[...$form[0], 'vat_classification_code' => '20']]),
            'Jiný kód je jiný řádek přiznání.');
        self::assertTrue(DocumentItemsPayload::changed($stored, [[...$form[0], 'vat_classification_code' => null]]),
            'Výslovný návrat na automatiku může kód změnit.');

        $noCode = [[...$stored[0], 'vat_classification_code' => null]];
        self::assertFalse(DocumentItemsPayload::changed($noCode, [[...$form[0], 'vat_classification_code' => null]]));
        self::assertFalse(DocumentItemsPayload::changed($noCode, [[...$form[0], 'vat_classification_code' => '']]));
    }

    /**
     * Zhasnutý přepínač zapíše repozitář jako samé NULL, takže osiřelá země v těle
     * nesmí vypadat jako změna — uložení ji stejně zahodí.
     */
    public function testDisabledOssIgnoresLeftoverFields(): void
    {
        $stored = [[
            'description' => 'Konzultace', 'quantity' => 1.0, 'unit' => 'ks',
            'unit_price_without_vat' => 1000.0, 'vat_rate_id' => 3,
            'oss_applicable' => false, 'oss_consumer_country' => null, 'oss_rate_type' => null,
        ]];
        $leftover = [[
            'description' => 'Konzultace', 'quantity' => 1, 'unit' => 'ks',
            'unit_price_without_vat' => 1000, 'vat_rate_id' => 3,
            'oss_applicable' => false, 'oss_consumer_country' => 'DE', 'oss_rate_type' => 'standard',
        ]];

        self::assertFalse(DocumentItemsPayload::changed($stored, $leftover));

        $switchedOn = $leftover;
        $switchedOn[0]['oss_applicable'] = true;
        self::assertTrue(DocumentItemsPayload::changed($stored, $switchedOn),
            'Zapnutí OSS je změna místa plnění, ne poznámka.');
    }

    /**
     * `oss_needs_manual_review` i `oss_document_contradiction` dopočítává server při
     * každém uložení ({@see \MyInvoice\Service\Oss\OssItemPlanner}). V otisku by z běžného
     * průchodu plánovačem udělaly „změnu položek" — a zhasnutí příznaku je přesně ta
     * neúčetní editace, kterou notes_only povolovat má.
     */
    public function testServerDerivedOssFlagsAreNotAnItemChange(): void
    {
        $stored = self::storedOssRow();
        $stored[0]['oss_needs_manual_review'] = true;

        $reviewed = self::storedOssRow();
        $reviewed[0]['oss_needs_manual_review'] = false;
        $reviewed[0]['oss_document_contradiction'] = 1;

        self::assertFalse(DocumentItemsPayload::changed($stored, $reviewed));
    }

    /**
     * Výběr sériových čísel / šarží (2016) je změna položek: u vystaveného dokladu ho
     * notes_only nesmí pustit a audit ho má zaznamenat. Uložený řádek nese dekódovaný
     * `tracking_allocations`, požadavek po normalizaci `tracking_json`.
     */
    public function testChangedDetectsTrackingSelection(): void
    {
        $stored = [['description' => 'Motocykl', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 100, 'vat_rate_id' => 1,
            'stock_item_id' => 7, 'tracking_allocations' => [['quantity' => '1.000', 'serial_number' => 'VIN-A', 'lot_code' => null, 'expires_on' => null]]]];
        $same = $stored;
        unset($same[0]['tracking_allocations']);
        $same[0]['tracking_json'] = '[{"quantity":"1.000","serial_number":"VIN-A","lot_code":null,"expires_on":null}]';
        self::assertFalse(DocumentItemsPayload::changed($stored, $same));

        $other = $same;
        $other[0]['tracking_json'] = '[{"quantity":"1.000","serial_number":"VIN-B","lot_code":null,"expires_on":null}]';
        self::assertTrue(DocumentItemsPayload::changed($stored, $other), 'Jiné sériové číslo je změna položky.');
    }
    /**
     * Textový řádek se ukládá vynulovaný (jen popis a sazba). Otisk ho proto porovnává
     * jen podle druhu a textu: editor smí poslat cokoli jiného (jednotku, sazbu, množství)
     * a „jen poznámková" úprava vystaveného dokladu nesmí spadnout na změně položek.
     * Změna textu, druhu nebo pořadí řádků změnou je.
     */
    public function testTextRowFingerprintIsKindAndDescription(): void
    {
        $stored = [
            ['description' => 'Licence', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 1],
            ['description' => 'Oddíl B', 'item_kind' => 'text', 'quantity' => 0.0, 'unit' => '', 'unit_price_without_vat' => 0.0, 'vat_rate_id' => 1],
        ];
        $sameText = $stored;
        $sameText[1] = ['description' => ' Oddíl B ', 'item_kind' => 'text', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 0, 'vat_rate_id' => 2];
        self::assertFalse(DocumentItemsPayload::changed($stored, $sameText));

        $otherText = $sameText;
        $otherText[1]['description'] = 'Oddíl C';
        self::assertTrue(DocumentItemsPayload::changed($stored, $otherText));

        $asStandard = $stored;
        $asStandard[1]['item_kind'] = 'standard';
        self::assertTrue(DocumentItemsPayload::changed($stored, $asStandard));

        self::assertTrue(DocumentItemsPayload::changed($stored, [$stored[0]]));
    }
}
