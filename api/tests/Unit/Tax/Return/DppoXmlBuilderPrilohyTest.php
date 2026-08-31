<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * `Prilohy/ObecnaPriloha` (dppdp9.xsd:6180) — SKUTEČNĚ přiložený soubor, jediná věc,
 * která podle zkušebního EPO (AUDIT-DPPO-XML.md §9.4c) odstraní chybu 2602 „Není
 * vložena příloha účetní závěrky". VetaUA/UB/UD/UZ (strukturovaná data) ji neřeší —
 * ověřeno i tady: appendix bez `statement_notes_attachment` žádnou `Prilohy` nepostaví,
 * i když VetaUA/UB/UD/UZ existují (viz testAppendixWithoutAttachmentBuildsNoPrilohy).
 */
final class DppoXmlBuilderPrilohyTest extends TestCase
{
    private function sampleSupplier(): array
    {
        return [
            'company_name' => 'Ukázková firma s.r.o.', 'street' => 'Zkušební 123/4',
            'city' => 'Vzorov', 'zip' => '100 00', 'country_iso2' => 'CZ',
            'ic' => '12345678', 'dic' => 'CZ12345678', 'taxpayer_type' => 'po',
            'financial_office_code' => '451', 'cz_nace_code' => '62020',
        ];
    }

    private function calc(): array
    {
        return (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );
    }

    private function build(array $appendix = []): array
    {
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $this->calc(), [], $appendix);
    }

    public function testNoAttachmentBuildsNoPrilohy(): void
    {
        $xml = $this->build()['xml'];
        self::assertStringNotContainsString('<Prilohy', $xml);
        self::assertStringNotContainsString('<ObecnaPriloha', $xml);
    }

    public function testAttachmentBuildsPrilohyWithBase64RoundTrip(): void
    {
        // Bajt \xFF vědomě, ať test ověří skutečné base64 kódování, ne jen ASCII text.
        $content = "%PDF-1.4 test obsah p\xC5\x99\xC3\xADlohy \xFF";
        $xml = $this->build([
            'statement_notes_attachment' => [
                'content'  => $content,
                'filename' => 'priloha-ucetni-zaverky-2025.pdf',
                'label'    => 'Příloha v účetní závěrce za rok 2025',
            ],
        ])['xml'];

        self::assertStringContainsString('<Prilohy>', $xml);
        self::assertStringContainsString('cislo="1"', $xml);
        self::assertStringContainsString('jm_souboru="priloha-ucetni-zaverky-2025.pdf"', $xml);
        self::assertStringContainsString('kodovani="base64"', $xml);
        self::assertStringContainsString('nazev="Příloha v účetní závěrce za rok 2025"', $xml);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $nodes = $dom->getElementsByTagName('ObecnaPriloha');
        self::assertSame(1, $nodes->length);
        $node = $nodes->item(0);
        self::assertNotNull($node);
        self::assertSame($content, base64_decode((string) $node->textContent, true));
    }

    public function testEmptyContentBuildsNoPrilohy(): void
    {
        $xml = $this->build([
            'statement_notes_attachment' => ['content' => '', 'filename' => 'x.pdf', 'label' => 'x'],
        ])['xml'];
        self::assertStringNotContainsString('<Prilohy', $xml);
    }

    public function testPrilohyIsLastElementInDocument(): void
    {
        // XSD sekvence (dppdp9.xsd:5957-6180): Prilohy je AŽ ZA VetaNP, poslední element
        // celého podání — appendChild pořadí v build() to musí respektovat.
        $xml = $this->build([
            'statement_notes_attachment' => ['content' => 'PDFDATA', 'filename' => 'x.pdf', 'label' => 'x'],
        ])['xml'];
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $dppdp9 = $dom->getElementsByTagName('DPPDP9')->item(0);
        self::assertNotNull($dppdp9);
        $lastElement = null;
        foreach ($dppdp9->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $lastElement = $child;
            }
        }
        self::assertNotNull($lastElement);
        self::assertSame('Prilohy', $lastElement->localName);
    }

    /**
     * Appendix (VetaUA/UB/UD/UZ) a Prilohy jsou NEZÁVISLÉ zdroje — appendix bez
     * statement_notes_attachment (typicky: příloha v účetní závěrce nekompletní)
     * nesmí Prilohy vyrobit jen proto, že VetaUA/UB/UD/UZ existují.
     */
    public function testAppendixWithoutAttachmentBuildsNoPrilohy(): void
    {
        $appendix = [
            'balance_sheet' => [
                'period' => ['id' => 1, 'fiscal_year' => 2025, 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31'],
                'assets' => [['row_code' => 'AKTIVA', 'gross' => 1000.0, 'correction' => 0.0, 'net' => 1000.0, 'prev_net' => 0.0]],
                'liabilities' => [['row_code' => 'PASIVA', 'amount' => 1000.0, 'prev_amount' => 0.0]],
            ],
            'income_statement' => ['rows' => [['row_code' => 'VH', 'amount' => 500000.0, 'prev_amount' => 0.0]]],
            'category' => ['category' => 'micro'],
            'settings' => ['statutory_audit' => false],
        ];
        $xml = $this->build($appendix)['xml'];
        self::assertStringContainsString('<VetaUZ', $xml);
        self::assertStringNotContainsString('<Prilohy', $xml);
    }

    public function testPr11PuzStaysNoEvenWithAttachment(): void
    {
        // pr11_puz = žádost o předání DO SBÍRKY LISTIN, jiná otázka než připojení
        // souboru k podání (viz DppoXmlBuilder::buildVetaUZ docblock) — auto-přiložení
        // souboru nesmí tiše zapnout tuhle samostatnou žádost.
        $appendix = [
            'balance_sheet' => [
                'period' => ['id' => 1, 'fiscal_year' => 2025, 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31'],
                'assets' => [['row_code' => 'AKTIVA', 'gross' => 1000.0, 'correction' => 0.0, 'net' => 1000.0, 'prev_net' => 0.0]],
                'liabilities' => [['row_code' => 'PASIVA', 'amount' => 1000.0, 'prev_amount' => 0.0]],
            ],
            'income_statement' => ['rows' => [['row_code' => 'VH', 'amount' => 500000.0, 'prev_amount' => 0.0]]],
            'category' => ['category' => 'micro'],
            'settings' => ['statutory_audit' => false],
            'statement_notes_attachment' => ['content' => 'PDFDATA', 'filename' => 'x.pdf', 'label' => 'x'],
        ];
        $xml = $this->build($appendix)['xml'];
        self::assertStringContainsString('<Prilohy', $xml);
        self::assertStringContainsString('pr11_puz="N"', $xml);
    }
}
