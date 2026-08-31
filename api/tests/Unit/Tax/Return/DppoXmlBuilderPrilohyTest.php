<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * `Prilohy/PredepsanaPriloha` (dppdp9.xsd:6180/6234) — SKUTEČNĚ přiložené soubory.
 * VetaUA/UB/UD/UZ (strukturovaná data) NEŘEŠÍ EPO chybu 2602 „Není vložena příloha
 * účetní závěrky" (ověřeno proti zkušebnímu EPO, AUDIT-DPPO-XML.md §9.4c/§13) — ověřeno
 * i tady: appendix bez žádného z klíčů PREDEPSANA_PRILOHA_KODY žádnou `Prilohy` nepostaví,
 * i když VetaUA/UB/UD/UZ existují (viz testAppendixWithoutAttachmentBuildsNoPrilohy).
 *
 * Zadavatel ručně vyplněný vzor v EPO doložil snímkem, že „Příloha v účetní závěrce"
 * patří do PŘEDEPSANÉ přílohy s kódem `PP_OPISPUV` (jeden řádek v tabulce příloh EPO),
 * ne do obecné přílohy bez kódu — dřív se stavěla jako `ObecnaPriloha`, což byla špatná
 * přihrádka; testy tady ověřují novou, opravenou strukturu.
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

    private function build(array $appendix = [], array $meta = []): array
    {
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $this->calc(), $meta, $appendix);
    }

    public function testNoAttachmentBuildsNoPrilohy(): void
    {
        $xml = $this->build()['xml'];
        self::assertStringNotContainsString('<Prilohy', $xml);
        self::assertStringNotContainsString('<PredepsanaPriloha', $xml);
        self::assertStringNotContainsString('<ObecnaPriloha', $xml);
    }

    public function testStatementNotesAttachmentBuildsPredepsanaPrilohaWithOpispuvKod(): void
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
        self::assertStringNotContainsString('<ObecnaPriloha', $xml);
        self::assertStringContainsString('cislo="1"', $xml);
        self::assertStringContainsString('kod="PP_OPISPUV"', $xml);
        self::assertStringContainsString('jm_souboru="priloha-ucetni-zaverky-2025.pdf"', $xml);
        self::assertStringContainsString('kodovani="base64"', $xml);
        self::assertStringContainsString('nazev="Příloha v účetní závěrce za rok 2025"', $xml);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        self::assertSame(0, $dom->getElementsByTagName('ObecnaPriloha')->length);
        $nodes = $dom->getElementsByTagName('PredepsanaPriloha');
        self::assertSame(1, $nodes->length);
        $node = $nodes->item(0);
        self::assertNotNull($node);
        self::assertSame('PP_OPISPUV', $node->getAttribute('kod'));
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
     * Tři přílohy (OPISPUV/PTOK/ZVKAP) najednou — číslování `cislo` musí být PRŮBĚŽNÉ
     * (1, 2, 3) v pevném pořadí PREDEPSANA_PRILOHA_KODY, každá se svým vlastním `kod`,
     * a nikdy se `PP_UZMUS` (IFRS, nepodporujeme) nesmí objevit.
     */
    public function testAllThreeAttachmentsGetContinuousNumberingAndOwnKod(): void
    {
        $appendix = [
            'statement_notes_attachment' => ['content' => 'OPISPUV-DATA', 'filename' => 'priloha.pdf', 'label' => 'Příloha'],
            'cash_flow_attachment' => ['content' => 'PTOK-DATA', 'filename' => 'penezni-toky.pdf', 'label' => 'Peněžní toky'],
            'equity_changes_attachment' => ['content' => 'ZVKAP-DATA', 'filename' => 'vlastni-kapital.pdf', 'label' => 'Vlastní kapitál'],
        ];
        $xml = $this->build($appendix)['xml'];

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $nodes = $dom->getElementsByTagName('PredepsanaPriloha');
        self::assertSame(3, $nodes->length);

        $byKod = [];
        foreach ($nodes as $node) {
            $byKod[$node->getAttribute('kod')] = $node->getAttribute('cislo');
        }
        self::assertSame(['PP_OPISPUV' => '1', 'PP_PTOK' => '2', 'PP_ZVKAP' => '3'], $byKod);
        self::assertStringNotContainsString('PP_UZMUS', $xml);
    }

    /**
     * Chybí-li prostřední příloha (typicky peněžní toky se nepodařilo sestavit), číslo
     * se nesmí přeskočit — zbylé dvě dostanou 1 a 2, ne 1 a 3.
     */
    public function testMissingMiddleAttachmentLeavesNoGapInNumbering(): void
    {
        $appendix = [
            'statement_notes_attachment' => ['content' => 'OPISPUV-DATA', 'filename' => 'priloha.pdf', 'label' => 'Příloha'],
            // cash_flow_attachment chybí (nesestavilo se).
            'equity_changes_attachment' => ['content' => 'ZVKAP-DATA', 'filename' => 'vlastni-kapital.pdf', 'label' => 'Vlastní kapitál'],
        ];
        $xml = $this->build($appendix)['xml'];

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $nodes = $dom->getElementsByTagName('PredepsanaPriloha');
        self::assertSame(2, $nodes->length);
        $byKod = [];
        foreach ($nodes as $node) {
            $byKod[$node->getAttribute('kod')] = $node->getAttribute('cislo');
        }
        self::assertSame(['PP_OPISPUV' => '1', 'PP_ZVKAP' => '2'], $byKod);
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
        // Nic se nepřiložilo (statement_notes_attachment chybí) → žádost o sbírku listin
        // NEJDE odeslat, i když výchozí volba je ANO — nesmí ukazovat na neexistující dokument.
        self::assertStringContainsString('pr11_puz="N"', $xml);
    }

    /** @return array<string,mixed> */
    private function appendixWithBalanceAndNotes(): array
    {
        return [
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
    }

    /**
     * Rozhodnutí zadavatele 31. 8. 2026: pr11_puz je VÝCHOZÍ 'A', dokud je k dispozici
     * skutečně přiložená Příloha (statement_notes_attachment) — dřívější natvrdo 'N'
     * (dodatek 13 AUDIT-DPPO-XML.md) se tímhle obrací.
     */
    public function testPr11PuzDefaultsToYesWhenAttachmentPresent(): void
    {
        $xml = $this->build($this->appendixWithBalanceAndNotes())['xml'];
        self::assertStringContainsString('<Prilohy', $xml);
        self::assertStringContainsString('pr11_puz="A"', $xml);
    }

    /** Ruční přebití — `$meta['puz_to_registry'] = false` vypne žádost i s přílohou. */
    public function testPr11PuzCanBeOverriddenToNo(): void
    {
        $xml = $this->build($this->appendixWithBalanceAndNotes(), ['puz_to_registry' => false])['xml'];
        self::assertStringContainsString('<Prilohy', $xml);
        self::assertStringContainsString('pr11_puz="N"', $xml);
    }

    /**
     * Chce-li uživatel žádost (výchozí ANO), ale příloha se k přiznání nepřipojila
     * (nekompletní/nesestavená), žádost se NEODEŠLE a appendix o tom varuje — stejné
     * chování jako v dodatek 13 §13.4 zjištěná EPO výtka o nedostatečném počtu příloh,
     * teď hlídané appkou dřív, než se k EPO vůbec dostane.
     */
    public function testPr11PuzStaysNoAndWarnsWhenAttachmentMissingButWanted(): void
    {
        $appendix = $this->appendixWithBalanceAndNotes();
        unset($appendix['statement_notes_attachment']);
        $result = $this->build($appendix);
        self::assertStringNotContainsString('pr11_puz="A"', $result['xml']);
        self::assertStringContainsString('pr11_puz="N"', $result['xml']);
        $warningsText = implode(' ', $result['warnings']);
        self::assertStringContainsString('pr11_puz', $warningsText);
    }

    /**
     * pr11_ppt/pr11_pzvk (dodatek 14) NEJSOU uživatelská volba — musí sedět přesně na
     * to, co appendix SKUTEČNĚ přiložil jako `PP_PTOK`/`PP_ZVKAP`. Jen cash_flow chybí →
     * jen `pr11_ppt` zůstává 'N', `pr11_pzvk' je 'A' (equity_changes je přítomný).
     */
    public function testPr11PptAndPzvkFollowAttachmentPresenceIndependently(): void
    {
        $appendix = $this->appendixWithBalanceAndNotes();
        $appendix['equity_changes_attachment'] = ['content' => 'ZVKAP-DATA', 'filename' => 'vlastni-kapital.pdf', 'label' => 'Vlastní kapitál'];
        // cash_flow_attachment se vědomě NEPŘIDÁVÁ (nesestavilo se).
        $xml = $this->build($appendix)['xml'];
        self::assertStringContainsString('pr11_ppt="N"', $xml);
        self::assertStringContainsString('pr11_pzvk="A"', $xml);
    }

    public function testPr11PptAndPzvkBothYesWhenBothAttachmentsPresent(): void
    {
        $appendix = $this->appendixWithBalanceAndNotes();
        $appendix['cash_flow_attachment'] = ['content' => 'PTOK-DATA', 'filename' => 'penezni-toky.pdf', 'label' => 'Peněžní toky'];
        $appendix['equity_changes_attachment'] = ['content' => 'ZVKAP-DATA', 'filename' => 'vlastni-kapital.pdf', 'label' => 'Vlastní kapitál'];
        $xml = $this->build($appendix)['xml'];
        self::assertStringContainsString('pr11_ppt="A"', $xml);
        self::assertStringContainsString('pr11_pzvk="A"', $xml);
    }
}
