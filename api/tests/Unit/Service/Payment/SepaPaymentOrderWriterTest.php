<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Payment;

use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payment\SepaPaymentOrderWriter;
use PHPUnit\Framework\TestCase;

/**
 * SEPA Credit Transfer (ISO 20022 pain.001.001.03) generátor — struktura ověřená
 * proti oficiálnímu XSD v `SepaPaymentOrderXsdValidationTest`. Zde jen syntetická
 * data (IBAN placeholder `CZ1801000000001000000005` = mod-11 ověřený testovací účet
 * `1000000005/0100` přepočtený na IBAN — viz AGENTS.md), žádné reálné doklady.
 */
final class SepaPaymentOrderWriterTest extends TestCase
{
    private const PAYER_IBAN = 'CZ1801000000001000000005';
    private const DE_IBAN = 'DE89370400440532013000';
    private const FR_IBAN = 'FR1420041010050500013M02606';

    private SepaPaymentOrderWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new SepaPaymentOrderWriter(new IbanValidator());
    }

    public function testBuildsValidSepaDocumentWithTwoTransactions(): void
    {
        $xml = $this->writer->build($this->orderWith([
            [
                'payee_name' => 'Dodavatel A s.r.o.', 'iban' => self::DE_IBAN, 'bic' => 'COBADEFFXXX',
                'amount' => 1234.56, 'variable_symbol' => '2026001', 'message' => 'FV-2026-001',
            ],
            [
                'payee_name' => 'Fournisseur B', 'iban' => self::FR_IBAN, 'bic' => null,
                'amount' => 500.0, 'variable_symbol' => null, 'message' => 'Facture B',
            ],
        ]));

        self::assertStringContainsString('xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03"', $xml);
        self::assertStringContainsString('<NbOfTxs>2</NbOfTxs>', $xml);
        self::assertStringContainsString('<CtrlSum>1734.56</CtrlSum>', $xml);
        self::assertStringContainsString('<IBAN>' . self::PAYER_IBAN . '</IBAN>', $xml);
        self::assertStringContainsString('<IBAN>' . self::DE_IBAN . '</IBAN>', $xml);
        self::assertStringContainsString('<IBAN>' . self::FR_IBAN . '</IBAN>', $xml);
        self::assertStringContainsString('Ccy="EUR"', $xml);
        self::assertStringContainsString('<Cd>SEPA</Cd>', $xml);
        self::assertStringContainsString('<ChrgBr>SLEV</ChrgBr>', $xml);
        // Chybějící BIC u druhé položky → CdtrAgt se vůbec nevynechá jen pro ni.
        self::assertStringNotContainsString('<BIC></BIC>', $xml);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        self::assertTrue(
            $document->schemaValidate(
                dirname(__DIR__, 4)
                    . '/xsd/pain.001.001.03.xsd',
            ),
            'Vygenerovaný SEPA příkaz musí projít oficiálním XSD.',
        );
    }

    public function testDiacriticsAreTransliteratedToAsciiLatinCharset(): void
    {
        $xml = $this->writer->build($this->orderWith([
            ['payee_name' => 'Škoda Příbram s.r.o.', 'iban' => self::DE_IBAN, 'amount' => 100, 'message' => 'Faktura č. 1'],
        ]));

        self::assertStringNotContainsString('Škoda', $xml);
        self::assertStringContainsString('Skoda Pribram', $xml);
    }

    public function testThrowsOnEmptyItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build([
            'payer_iban' => self::PAYER_IBAN, 'payment_date' => '2026-07-15', 'items' => [],
        ]);
    }

    public function testThrowsOnMissingOrNonArrayItems(): void
    {
        $rejected = 0;
        foreach ([null, 'not-an-array'] as $items) {
            $order = [
                'payer_iban' => self::PAYER_IBAN,
                'payment_date' => '2026-07-15',
            ];
            if ($items !== null) {
                $order['items'] = $items;
            }
            try {
                $this->writer->build($order);
                self::fail('Neplatné položky musí být odmítnuty.');
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        self::assertSame(2, $rejected);
    }

    public function testThrowsOnMissingPayerIbanKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build([
            'payment_date' => '2026-07-15',
            'items' => [[
                'payee_name' => 'X',
                'iban' => self::DE_IBAN,
                'amount_minor' => 10_000,
            ]],
        ]);
    }

    public function testThrowsOnInvalidPayerIban(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build([
            'payer_iban' => 'CZ0000000000000000000000', // špatný kontrolní součet
            'payment_date' => '2026-07-15',
            'items' => [['payee_name' => 'X', 'iban' => self::DE_IBAN, 'amount' => 100]],
        ]);
    }

    public function testThrowsOnMissingPayerIban(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build([
            'payer_iban' => '', 'payment_date' => '2026-07-15',
            'items' => [['payee_name' => 'X', 'iban' => self::DE_IBAN, 'amount' => 100]],
        ]);
    }

    public function testThrowsOnInvalidRecipientIban(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build($this->orderWith([
            ['payee_name' => 'X', 'iban' => 'DE00000000000000000000', 'amount' => 100],
        ]));
    }

    public function testThrowsOnNonPositiveAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build($this->orderWith([
            ['payee_name' => 'X', 'iban' => self::DE_IBAN, 'amount' => 0],
        ]));
    }

    public function testInvalidBicIsOmittedNotFatal(): void
    {
        // BIC je od 2016 v SEPA nepovinný — nevalidní/nesmyslný BIC se má tiše
        // vynechat, ne shodit celý export (na rozdíl od IBAN, který je povinný).
        // Plátce (orderWith) má platný BIC, takže DbtrAgt/BIC zůstává — ověřujeme,
        // že se nevytvoří CdtrAgt (příjemcova banka) s nevalidním obsahem.
        $xml = $this->writer->build($this->orderWith([
            ['payee_name' => 'X', 'iban' => self::DE_IBAN, 'bic' => 'not-a-bic', 'amount' => 100],
        ]));
        self::assertStringNotContainsString('<CdtrAgt>', $xml);
        self::assertStringNotContainsString('not-a-bic', $xml);
    }

    /**
     * Regresní test na HIGH nález (adversariální review): `DbtrAgt` (banka plátce)
     * je v pain.001.001.03 POVINNÝ element, na rozdíl od `CdtrAgt` (banka příjemce,
     * minOccurs="0"). Writer dřív `DbtrAgt` vynechával, když plátce neměl BIC —
     * FE přitom gatuje SEPA tlačítko jen na IBAN plátce, ne na BIC, takže běžný
     * CZ účet bez BIC prošel přes FE a vygeneroval strukturálně vadné XML.
     * Fallback: `Othr/Id=NOTPROVIDED` (EPC guideline pro nepovinný BIC).
     */
    public function testDbtrAgtIsAlwaysPresentEvenWithoutPayerBic(): void
    {
        $order = $this->orderWith([
            ['payee_name' => 'X', 'iban' => self::DE_IBAN, 'amount' => 100],
        ]);
        $order['payer_bic'] = null;

        $xml = $this->writer->build($order);

        self::assertStringContainsString('<DbtrAgt>', $xml);
        self::assertMatchesRegularExpression(
            '#<DbtrAgt>\s*<FinInstnId>\s*<Othr>\s*<Id>NOTPROVIDED</Id>\s*</Othr>\s*</FinInstnId>\s*</DbtrAgt>#',
            $xml,
        );
    }

    /**
     * Regresní test na MEDIUM nález (adversariální review): MsgId dřív obsahoval
     * timestamp, takže KAŽDÝ re-export téže dávky vypadal bance jako nová zpráva
     * a mařil bankovní duplicate-detection (riziko dvojí platby při 2× uploadu
     * stejného souboru). MsgId (i PmtInfId) musí být deterministický z order_id.
     */
    public function testMsgIdIsDeterministicAcrossReExportsOfSameOrder(): void
    {
        $order = $this->orderWith([
            ['payee_name' => 'X', 'iban' => self::DE_IBAN, 'amount' => 100],
        ]);

        $xml1 = $this->writer->build($order);
        $xml2 = $this->writer->build($order);

        preg_match('#<MsgId>(.*?)</MsgId>#', $xml1, $m1);
        preg_match('#<MsgId>(.*?)</MsgId>#', $xml2, $m2);

        self::assertSame(
            'MYUCTO-' . substr(hash('sha256', '1'), 0, 28),
            $m1[1] ?? null,
        );
        self::assertSame($m1[1], $m2[1], 'MsgId musí být stejné napříč re-exporty téže dávky (order_id).');
        self::assertSame(35, strlen((string) ($m1[1] ?? '')));
    }

    public function testMsgIdHashesFullOrderIdentifierBeforeTruncation(): void
    {
        $order = $this->orderWith([[
            'payee_name' => 'X',
            'iban' => self::DE_IBAN,
            'amount_minor' => 100,
        ]]);
        $order['creation_datetime'] = '2026-07-01T08:09:10+00:00';
        $order['order_id'] = str_repeat('A', 80) . '-LEFT';
        $left = $this->writer->build($order);
        $order['order_id'] = str_repeat('A', 80) . '-RIGHT';
        $right = $this->writer->build($order);

        preg_match('#<MsgId>(.*?)</MsgId>#', $left, $leftMatch);
        preg_match('#<MsgId>(.*?)</MsgId>#', $right, $rightMatch);

        self::assertNotSame(
            $leftMatch[1] ?? null,
            $rightMatch[1] ?? null,
        );
    }

    public function testExplicitEndToEndIdentifiersStayDistinct(): void
    {
        $xml = $this->writer->build($this->orderWith([
            [
                'payee_name' => 'A',
                'iban' => self::DE_IBAN,
                'amount_minor' => 100,
                'end_to_end_id' => 'MYUCTO-'
                    . str_repeat('a', 28),
            ],
            [
                'payee_name' => 'B',
                'iban' => self::FR_IBAN,
                'amount_minor' => 200,
                'end_to_end_id' => 'MYUCTO-'
                    . str_repeat('b', 28),
            ],
        ]));

        self::assertStringContainsString(
            '<EndToEndId>MYUCTO-' . str_repeat('a', 28)
                . '</EndToEndId>',
            $xml,
        );
        self::assertStringContainsString(
            '<EndToEndId>MYUCTO-' . str_repeat('b', 28)
                . '</EndToEndId>',
            $xml,
        );
    }

    public function testExactLargeMinorUnitsNeverPassThroughFloat(): void
    {
        $order = $this->orderWith([[
            'payee_name' => 'X',
            'iban' => self::DE_IBAN,
            'amount_minor' => 9_007_199_254_740_993,
        ]]);
        $order['creation_datetime'] = '2026-07-01T08:09:10+00:00';

        $xml = $this->writer->build($order);

        self::assertStringContainsString(
            '<CtrlSum>90071992547409.93</CtrlSum>',
            $xml,
        );
        self::assertStringContainsString(
            'Ccy="EUR">90071992547409.93</InstdAmt>',
            $xml,
        );
    }

    public function testRejectsAmountsBeyondXsdTotalDigits(): void
    {
        try {
            $this->writer->build($this->orderWith([[
                'payee_name' => 'X',
                'iban' => self::DE_IBAN,
                'amount_minor' => 1_000_000_000_000_000_000,
            ]]));
            self::fail('Položka nad XSD totalDigits musí být odmítnuta.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build($this->orderWith([
            [
                'payee_name' => 'X',
                'iban' => self::DE_IBAN,
                'amount_minor' => 500_000_000_000_000_000,
            ],
            [
                'payee_name' => 'Y',
                'iban' => self::FR_IBAN,
                'amount_minor' => 500_000_000_000_000_000,
            ],
        ]));
    }

    public function testExplicitCreationTimestampMakesWholeDocumentDeterministic(): void
    {
        $order = $this->orderWith([[
            'payee_name' => 'X',
            'iban' => self::DE_IBAN,
            'amount_minor' => 10_001,
        ]]);
        $order['creation_datetime'] = '2026-07-01T08:09:10+00:00';

        $first = $this->writer->build($order);
        $second = $this->writer->build($order);

        self::assertSame($first, $second);
        self::assertStringContainsString(
            '<CreDtTm>2026-07-01T08:09:10</CreDtTm>',
            $first,
        );
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    /**
     * C-08 — SEPA dřív VS, SS i KS zahodilo a eurová platba odešla bez
     * jakékoliv identifikace. Symboly se přenášejí v nestrukturované zprávě
     * v tuzemsky obvyklém tvaru `/VS/…/SS/…/KS/…`.
     */
    public function testCarriesCzechPaymentSymbolsInRemittanceInformation(): void
    {
        $xml = $this->writer->build($this->orderWith([
            [
                'payee_name' => 'Zdravotni pojistovna', 'iban' => self::DE_IBAN, 'bic' => null,
                'amount' => 100.0, 'variable_symbol' => '1234567890',
                'specific_symbol' => '0123456789', 'constant_symbol' => '0308',
                'message' => 'Zdravotni pojisteni 111',
            ],
        ]));

        self::assertStringContainsString(
            '<Ustrd>/VS/1234567890/SS/0123456789/KS/0308 Zdravotni pojisteni 111</Ustrd>',
            $xml,
        );
    }

    public function testOmitsSymbolTagsThatHaveNoValue(): void
    {
        $xml = $this->writer->build($this->orderWith([
            [
                'payee_name' => 'Dodavatel', 'iban' => self::DE_IBAN, 'bic' => null,
                'amount' => 100.0, 'variable_symbol' => '2026001',
                'specific_symbol' => null, 'constant_symbol' => null,
                'message' => null,
            ],
        ]));

        // Zpráva je jen opsaný VS → v referenci už je, neduplikuje se.
        self::assertStringContainsString('<Ustrd>/VS/2026001</Ustrd>', $xml);
        self::assertStringNotContainsString('/SS/', $xml);
        self::assertStringNotContainsString('/KS/', $xml);
    }

    public function testKeepsPlainMessageWhenNoSymbolIsGiven(): void
    {
        $xml = $this->writer->build($this->orderWith([
            [
                'payee_name' => 'Dodavatel', 'iban' => self::DE_IBAN, 'bic' => null,
                'amount' => 100.0, 'variable_symbol' => null,
                'message' => 'Facture B',
            ],
        ]));

        self::assertStringContainsString('<Ustrd>Facture B</Ustrd>', $xml);
    }

    private function orderWith(array $items): array
    {
        return [
            'order_id' => 1,
            'initiator_name' => 'MyUcto s.r.o.',
            'payer_name' => 'MyUcto s.r.o.',
            'payer_iban' => self::PAYER_IBAN,
            'payer_bic' => 'GIBACZPX',
            'payment_date' => '2026-07-15',
            'currency' => 'EUR',
            'items' => $items,
        ];
    }
}
