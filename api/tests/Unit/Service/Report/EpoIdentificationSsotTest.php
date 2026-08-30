<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\EpoSupplierBlockBuilder;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * SSOT identifikačního bloku EPO podání (F1 auditu, private/checks/SSOT-REGISTR.md).
 *
 * `EpoSupplierBlockBuilder` je určený jediný zdroj pravdy pro `VetaP`, ale dva
 * koncepty se z něj vydrolily:
 *
 *   1. **`stat` jako ISO2 místo názvu z číselníku** — přesně chyba #201, opravená
 *      v SSOT, ale v `DppoXmlBuilder` přežila vlastní kopií. Zahraniční subjekt
 *      dostal do přiznání k dani z příjmů PO `'SK'` místo `'SLOVENSKO'`, tuzemský
 *      `'Česká republika'` místo číselníkového `'ČESKÁ REPUBLIKA'`.
 *   2. **normalizace DIČ** — SSOT trhal jen prefix `CZ` a nečíslice pouštěl dál,
 *      ačkoli vlastní komentář odkazoval na XSD pattern `[0-9]{1,10}`. Úplnou
 *      normalizaci měl jen `KontrolniHlaseniBuilder::cleanDic()`, tedy zrovna
 *      to místo, které SSOT NENÍ.
 *
 * Obojí je stejná třída chyby jako #200/23f4dfef: jeden koncept, víc implementací,
 * oprava dopadne jen na některé.
 */
final class EpoIdentificationSsotTest extends TestCase
{
    /** DIČ se do XML dostane vždy jako holé číslice — XSD pattern `[0-9]{1,10}`. */
    public function testNormalizeDicStripsPrefixAndNonDigits(): void
    {
        // Dvojice, ne mapa — PHP by číselný klíč '12345678' přetypoval na int.
        foreach ([
            ['CZ12345678', '12345678'],
            ['cz12345678', '12345678'],
            ['CZ 123 456 789', '123456789'],
            ['CZ-1234-5678', '12345678'],
            [' CZ12345678 ', '12345678'],
            ['12345678', '12345678'],
            ['', ''],
        ] as [$input, $expected]) {
            self::assertSame($expected, EpoSupplierBlockBuilder::normalizeDic($input), 'DIČ: ' . $input);
        }

        self::assertSame('', EpoSupplierBlockBuilder::normalizeDic(null));
    }

    /** `VetaP` sestavená SSOT nese DIČ už normalizované. */
    public function testFillVetaPWritesNormalisedDic(): void
    {
        $vetaP = $this->buildVetaP(['dic' => 'CZ 123 456 789']);

        self::assertSame('123456789', $vetaP->getAttribute('dic'));
        self::assertMatchesRegularExpression('/^[0-9]{1,10}$/', $vetaP->getAttribute('dic'));
    }

    /**
     * `stat` je NÁZEV státu z číselníku, ne ISO2 — a musí to platit ve VŠECH
     * podáních, ne jen v těch, která volají SSOT.
     */
    public function testCountryNameIsUsedInsteadOfIsoCode(): void
    {
        self::assertSame('ČESKÁ REPUBLIKA', EpoSupplierBlockBuilder::countryName('CZ'));
        self::assertSame('SLOVENSKO', EpoSupplierBlockBuilder::countryName('SK'));
        // Mimo číselník → null, aby volající atribut raději vynechal.
        self::assertNull(EpoSupplierBlockBuilder::countryName('XX'));
    }

    /** DPPO (`DPHDP9`) měla vlastní kopii — tenhle test ji drží na SSOT. */
    public function testDppoWritesCatalogueCountryNameNotIsoCode(): void
    {
        foreach ([
            'SK' => 'SLOVENSKO',
            'DE' => 'NĚMECKO',
        ] as $iso2 => $expected) {
            $vetaP = $this->buildDppoVetaP(['country_iso2' => $iso2]);

            self::assertSame($iso2, $vetaP->getAttribute('k_stat'), 'k_stat zůstává ISO2');
            self::assertSame($expected, $vetaP->getAttribute('stat'), 'stat je název z číselníku');
        }

        // Tuzemská PO stát nevyplňuje vůbec — zkušební EPO to 30. 8. 2026 vytklo
        // jako propustnou chybu 300 „Kód státu vyplňují pouze zahraniční právnické osoby".
        $domestic = $this->buildDppoVetaP(['country_iso2' => 'CZ']);
        self::assertFalse($domestic->hasAttribute('k_stat'));
        self::assertFalse($domestic->hasAttribute('stat'));
    }

    /** Neznámý stát: atribut je optional → radši vynechat než poslat neplatnou hodnotu. */
    public function testDppoOmitsCountryNameOutsideCatalogue(): void
    {
        $vetaP = $this->buildDppoVetaP(['country_iso2' => 'XX']);

        self::assertSame('XX', $vetaP->getAttribute('k_stat'));
        self::assertFalse($vetaP->hasAttribute('stat'), 'Neznámý stát se do `stat` psát nesmí.');
    }

    /**
     * Úplný řádek dodavatele — `fillVetaP()` od fáze F1 vyžaduje všechny sloupce, které
     * čte, a chybějící KLÍČ hlásí jako chybu volajícího (dřív tiše vynechal atribut
     * a podání odešlo neúplné). Prázdná HODNOTA zůstává legitimní.
     *
     * @param array<string, mixed> $overrides
     */
    private function supplier(array $overrides = []): array
    {
        return $overrides + [
            'name'                  => 'Testovací s.r.o.',
            'company_name'          => 'Testovací s.r.o.',
            'dic'                   => 'CZ12345678',
            'ic'                    => '12345678',
            'street'                => 'Dlouhá 123',
            'city'                  => 'Praha',
            'zip'                   => '110 00',
            'country_iso2'          => 'CZ',
            'taxpayer_type'         => 'po',
            'financial_office_code' => '451',
            'opr_jmeno'             => 'Jan',
            'opr_prijmeni'          => 'Novák',
        ] + array_fill_keys(EpoSupplierBlockBuilder::REQUIRED_SUPPLIER_KEYS, '');
    }

    /** @param array<string, mixed> $overrides */
    private function buildVetaP(array $overrides = []): \DOMElement
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $vetaP = $doc->createElement('VetaP');
        $doc->appendChild($vetaP);
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $this->supplier($overrides));

        return $vetaP;
    }

    /**
     * DPPO si `VetaP` skládá vlastní privátní metodou — sáhneme na ni reflexí,
     * ať test měří skutečný výstup builderu, ne jeho okolí.
     *
     * @param array<string, mixed> $overrides
     */
    private function buildDppoVetaP(array $overrides = []): \DOMElement
    {
        $ref = new \ReflectionClass(DppoXmlBuilder::class);
        $method = $this->findVetaPMethod($ref);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $builder = $ref->newInstanceWithoutConstructor();

        return $method->invoke($builder, $doc, $this->supplier($overrides));
    }

    private function findVetaPMethod(\ReflectionClass $ref): \ReflectionMethod
    {
        foreach ($ref->getMethods() as $m) {
            if (stripos($m->getName(), 'vetap') !== false) {
                return $m;
            }
        }

        self::fail('DppoXmlBuilder nemá metodu skládající VetaP — přepiš test podle nové struktury.');
    }
}
