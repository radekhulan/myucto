<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\JournalDescriptionBuilder as B;
use PHPUnit\Framework\TestCase;

/**
 * Popis zápisu je jediné, podle čeho účetní v deníku pozná účetní případ (§13 ZoÚ).
 * Tahle brána drží čtyři vlastnosti, jejichž ztráta se pozná až na produkci, kde
 * stojí za sebou desítky řádků se shodným textem:
 *
 *  1. identifikace dokladu (číslo) a protistrana jsou v popisu VŽDY, ne jen volný
 *     text dokladu,
 *  2. chybějící údaj segment vynechá — nikdy nevznikne „— — ",
 *  3. ořez na šířku sloupce jde na hranici slova,
 *  4. skládání je idempotentní: už složený popis vrácený zpět jako detail dá týž
 *     výsledek (na tom stojí opakované spuštění rebuild-journal-descriptions.php).
 *
 * Data jsou syntetická (repo je veřejné).
 */
final class JournalDescriptionBuilderTest extends TestCase
{
    // ── skládání segmentů ───────────────────────────────────────────────────────

    public function testComposeJoinsNonEmptyPartsWithSeparator(): void
    {
        self::assertSame(
            'FV 2099000123 — Odběratel s.r.o. — pronájem místa',
            B::composeParts(['FV 2099000123', 'Odběratel s.r.o.', 'pronájem místa']),
        );
    }

    /** Chybějící protistrana (nespárovaná platba) nesmí nechat v popisu prázdný segment. */
    public function testMissingPartsLeaveNoEmptySegments(): void
    {
        $out = B::composeParts(['Banka 2099/004', null, '', '   ', 'příchozí platba']);
        self::assertSame('Banka 2099/004 — příchozí platba', $out);
        self::assertStringNotContainsString('—  —', $out);
        self::assertStringNotContainsString('— —', $out);
    }

    public function testEverythingEmptyGivesEmptyStringNotSeparators(): void
    {
        self::assertSame('', B::composeParts([null, '', '  ']));
    }

    /** Detail shodný s už umístěným segmentem se zahodí (nezdvojuje se protistrana). */
    public function testDuplicatePartsAreDropped(): void
    {
        self::assertSame(
            'Banka 2099/004 — příchozí platba — Odběratel s.r.o.',
            B::composeParts(['Banka 2099/004', 'příchozí platba', 'Odběratel s.r.o.', 'Odběratel s.r.o.']),
        );
    }

    /** Dedup nerozlišuje velikost písmen ani zdvojené mezery. */
    public function testDuplicateDetectionIgnoresCaseAndWhitespace(): void
    {
        self::assertSame(
            'FV 2099000123 — Odběratel s.r.o.',
            B::composeParts(['FV 2099000123', 'Odběratel s.r.o.', "odběratel   s.r.o.\n"]),
        );
    }

    /** Složený detail se rozpadne na segmenty, takže dedup funguje i na celém popisu. */
    public function testComposedDetailIsSplitAndDeduplicated(): void
    {
        self::assertSame(
            'FV 2099000123 — Odběratel s.r.o. — pronájem místa',
            B::composeParts([
                'FV 2099000123',
                'Odběratel s.r.o.',
                'FV 2099000123 — Odběratel s.r.o. — pronájem místa',
            ]),
        );
    }

    /**
     * IDEMPOTENCE: popis poslaný zpátky jako detail dá TÝŽ text. Bez toho by
     * opakované dogenerování popisů (CLI, dokončení převodu) pokaždé zápis znovu
     * přepsalo a bumplo row_version.
     */
    public function testComposingIsIdempotentEvenWhenTruncated(): void
    {
        $long = str_repeat('velmi dlouhý popis účetního případu ', 12);
        $head = ['FV 2099000123', 'Odběratel s.r.o. a synové a dcery v likvidaci'];

        $first  = B::composeParts([...$head, $long]);
        $second = B::composeParts([...$head, $first]);
        $third  = B::composeParts([...$head, $second]);

        self::assertSame($first, $second, 'Druhý průchod musí dát týž popis.');
        self::assertSame($second, $third, 'A třetí taky.');
    }

    /** Zástupné texty z převodů nenesou informaci a do popisu nepatří. */
    public function testNoiseTextIsDropped(): void
    {
        self::assertSame(
            'FV 2099000123',
            B::composeParts(['FV 2099000123', 'Účetní zápis z Pohody']),
        );
    }

    // ── ořez ────────────────────────────────────────────────────────────────────

    public function testShortTextIsNotTruncated(): void
    {
        self::assertSame('krátký popis', B::truncate('krátký popis'));
    }

    public function testTruncateRespectsColumnWidth(): void
    {
        $out = B::truncate(str_repeat('slovo ', 200));
        self::assertLessThanOrEqual(B::MAX_LENGTH, mb_strlen($out));
        self::assertSame(255, B::MAX_LENGTH, 'journal_entries.description je VARCHAR(255).');
    }

    /** Ořez jde na hranici slova — uříznuté slovo vypadá jako poškozený zápis. */
    public function testTruncateCutsOnWordBoundary(): void
    {
        $out = B::truncate('alfa beta gama delta epsilon', 14);
        self::assertSame('alfa beta…', $out);
    }

    /** Text bez mezer se ořezává tvrdě — ořez „na slově" by z popisu nenechal nic. */
    public function testTruncateFallsBackToHardCutWithoutSpaces(): void
    {
        $out = B::truncate(str_repeat('X', 40), 10);
        self::assertSame(str_repeat('X', 9) . '…', $out);
    }

    /** Za výpustkou nesmí zůstat viset pomlčka ani čárka z oddělovače segmentů. */
    public function testTruncateTrimsTrailingPunctuation(): void
    {
        $out = B::truncate('Odběratel s.r.o. — dlouhý text', 20);
        self::assertStringEndsWith('…', $out);
        self::assertStringNotContainsString('—…', $out);
        self::assertStringNotContainsString(' …', $out);
    }

    public function testMultibyteTextIsCountedInCharactersNotBytes(): void
    {
        $out = B::truncate(str_repeat('ěščřž', 100));
        self::assertLessThanOrEqual(B::MAX_LENGTH, mb_strlen($out));
    }

    // ── banka ───────────────────────────────────────────────────────────────────

    public function testBankIncomingPaymentNamesStatementDirectionPartnerAndSymbol(): void
    {
        self::assertSame(
            'Banka 2099/004 — příchozí platba — Odběratel s.r.o. (VS 2099000123) — Platba faktury',
            B::forBankRow([
                'id'                => 1,
                'amount'            => 1210.00,
                'variable_symbol'   => '2099000123',
                'counterparty_name' => 'Odběratel s.r.o.',
                'description'       => 'Platba faktury',
                'statement_number'  => '2099/004',
            ]),
        );
    }

    public function testBankOutgoingPaymentIsLabelledAsOutgoing(): void
    {
        self::assertStringContainsString('odchozí platba', B::forBankRow([
            'id'                => 2,
            'amount'            => -1210.00,
            'counterparty_name' => 'Dodavatel a.s.',
            'statement_number'  => '2099/004',
        ]));
    }

    /**
     * JÁDRO NÁLEZU: dvě platby od TÉŽE protistrany se stejnou zprávou se dřív
     * v deníku nedaly rozlišit — popis byl „protistrana — zpráva" a u obou stejný.
     */
    public function testTwoPaymentsFromSamePartnerDifferByVariableSymbol(): void
    {
        $tx = [
            'id' => 3, 'amount' => 1210.00, 'counterparty_name' => 'Odběratel s.r.o.',
            'description' => 'Platba', 'statement_number' => '2099/004',
        ];
        $a = B::forBankRow(array_merge($tx, ['variable_symbol' => '2099000123']));
        $b = B::forBankRow(array_merge($tx, ['id' => 4, 'variable_symbol' => '2099000124']));

        self::assertNotSame($a, $b, 'Zápisy se musí dát od sebe odlišit.');
    }

    /** Nespárovaná platba bez názvu protistrany: aspoň protiúčet a zpráva pro příjemce. */
    public function testUnmatchedPaymentFallsBackToCounterpartyAccount(): void
    {
        self::assertSame(
            'Banka 2099/004 — příchozí platba — 1000000005/0100 — Vratka přeplatku',
            B::forBankRow([
                'id'                   => 5,
                'amount'               => 500.00,
                'counterparty_account' => '1000000005',
                'counterparty_bank'    => '0100',
                'description'          => 'Vratka přeplatku',
                'statement_number'     => '2099/004',
            ]),
        );
    }

    /** Pohyb úplně bez údajů nesmí skončit na zástupném „BANK-123" jako dřív. */
    public function testBankRowWithoutAnyDetailStillIdentifiesDirection(): void
    {
        self::assertSame('Banka — odchozí platba', B::forBankRow(['id' => 6, 'amount' => -10.0]));
    }

    /** Popis z pravidla automatiky je věcný obsah, ne náhrada identifikace pohybu. */
    public function testRuleDescriptionBecomesTrailingDetail(): void
    {
        self::assertSame(
            'Banka 2099/004 — odchozí platba — Finanční úřad (VS 1234567890) — Záloha na daň z příjmů',
            B::forBankRow([
                'id'                => 7,
                'amount'            => -30000.00,
                'variable_symbol'   => '1234567890',
                'counterparty_name' => 'Finanční úřad',
                'description'       => 'Finanční úřad',
                'statement_number'  => '2099/004',
            ], 'Záloha na daň z příjmů'),
        );
    }

    /**
     * Pravidlo bez vlastního popisu posílá PRÁZDNÝ řetězec, ne null. Nesmí tím
     * umlčet zprávu pro příjemce — právě ta u nespárovaného pohybu nese obsah.
     */
    public function testEmptyDetailFallsBackToTransactionMessage(): void
    {
        self::assertSame(
            'Banka 2099/004 — odchozí platba — Dodavatel a.s. — Nájemné kancelář 3/2099',
            B::forBankRow([
                'id'                => 8,
                'amount'            => -12000.00,
                'counterparty_name' => 'Dodavatel a.s.',
                'description'       => 'Nájemné kancelář 3/2099',
                'statement_number'  => '2099/004',
            ], ''),
        );
    }

    // ── pokladna ────────────────────────────────────────────────────────────────

    public function testCashDocumentNamesNumberRegisterPartnerAndContent(): void
    {
        self::assertSame(
            'PPD-2099-0042 — Pokladna Hlavní — Jan Novák — nákup kancelářských potřeb',
            B::forCashRow([
                'id'           => 42,
                'doc_type'     => 'in',
                'doc_number'   => 'PPD-2099-0042',
                'register_name' => 'Pokladna Hlavní',
                'partner_name' => 'Jan Novák',
                'description'  => 'nákup kancelářských potřeb',
            ]),
        );
    }

    /**
     * JÁDRO NÁLEZU u pokladny: `cash_documents.description` je u celé řady dokladů
     * shodné („Tržba v hotovosti"), takže z něj samotného zápisy rozlišit nešlo.
     */
    public function testTwoCashDocumentsWithSameTextDifferByNumber(): void
    {
        $doc = ['doc_type' => 'in', 'register_name' => 'Pokladna Hlavní', 'description' => 'Tržba v hotovosti'];
        $a = B::forCashRow(array_merge($doc, ['id' => 1, 'doc_number' => 'PPD-2099-0001']));
        $b = B::forCashRow(array_merge($doc, ['id' => 2, 'doc_number' => 'PPD-2099-0002']));

        self::assertNotSame($a, $b);
    }

    /** Koncept ještě číslo nemá — popis nesmí začínat pomlčkou. */
    public function testDraftCashDocumentUsesPlaceholderNumber(): void
    {
        self::assertSame(
            'VPD #9 — Pokladna Hlavní — výběr hotovosti',
            B::forCashRow([
                'id'            => 9,
                'doc_type'      => 'out',
                'doc_number'    => null,
                'register_name' => 'Pokladna Hlavní',
                'description'   => 'výběr hotovosti',
            ]),
        );
    }

    // ── popisky typů dokladů ────────────────────────────────────────────────────

    public function testIssuedLabelsCoverEveryInvoiceType(): void
    {
        self::assertSame('FV', B::issuedLabel('invoice'));
        self::assertSame('Dobropis FV', B::issuedLabel('credit_note'));
        self::assertSame('Storno FV', B::issuedLabel('cancellation'));
        self::assertSame('Proforma', B::issuedLabel('proforma'));
        self::assertSame('DD k platbě', B::issuedLabel('tax_document'));
        self::assertSame('Penalizační FV', B::issuedLabel('penalty'));
        self::assertSame('Splátkový kalendář', B::issuedLabel('payment_calendar'));
        self::assertSame('FV', B::issuedLabel('cosi_noveho'), 'Neznámý typ nesmí popis rozbít.');
    }

    public function testReceivedLabelsCoverEveryDocumentKind(): void
    {
        self::assertSame('PF', B::receivedLabel('invoice'));
        self::assertSame('Dobropis PF', B::receivedLabel('credit_note'));
        self::assertSame('Účtenka', B::receivedLabel('receipt'));
        self::assertSame('Záloha PF', B::receivedLabel('advance'));
        self::assertSame('DD k platbě', B::receivedLabel('tax_document'));
        self::assertSame('PF', B::receivedLabel('cosi_noveho'));
    }

    // ── kontrakt s okolím ───────────────────────────────────────────────────────

    /** Builder skládá popis jen tam, kde má z čeho — jinde si text tvoří zdroj sám. */
    public function testBuildableTypesAreExactlyTheDocumentSources(): void
    {
        self::assertSame(['invoice', 'purchase_invoice', 'bank', 'cash'], B::BUILDABLE);
    }

    /** @return iterable<string, array{string, string, array<string,string>, array<string,?string>}> */
    public static function partsCases(): iterable
    {
        yield 'přijatá faktura' => [
            'PF PF-2099-0008 / dod. DOD-77 — Ukázka s.r.o. — Vývojářské licence', 'purchase_invoice',
            ['source_ref' => 'PF-2099-0008', 'document_no' => 'DOD-77', 'partner_name' => 'Ukázka s.r.o.'],
            ['kind' => null, 'partner' => 'Ukázka s.r.o.', 'vs' => null, 'text' => 'Vývojářské licence'],
        ];
        yield 'převzatá přijatá faktura opakuje číslo' => [
            'PF 99FP00013 / dod. 1784 — Dodavatel a.s. — PF 99FP00013 — tiskárna', 'purchase_invoice',
            ['source_ref' => '99FP00013', 'partner_name' => 'Dodavatel a.s.'],
            ['kind' => null, 'partner' => 'Dodavatel a.s.', 'vs' => null, 'text' => 'tiskárna'],
        ];
        yield 'dobropis nese druh' => [
            'Dobropis FV 2099005 — Odběratel s.r.o. — vrácení', 'invoice',
            ['source_ref' => '2099005', 'partner_name' => 'Odběratel s.r.o.'],
            ['kind' => 'Dobropis FV', 'partner' => 'Odběratel s.r.o.', 'vs' => null, 'text' => 'vrácení'],
        ];
        yield 'banka s VS' => [
            'Banka CSOB 017/2099 — odchozí platba — Dodavatel a.s. (VS 4028931144) — Banka CSOB0170001 — Úhrada FP č. 99FP0001', 'bank',
            ['document_no' => 'CSOB0170001', 'partner_name' => 'Dodavatel a.s.', 'variable_symbol' => '4028931144'],
            ['kind' => null, 'partner' => 'Dodavatel a.s.', 'vs' => '4028931144', 'text' => 'Úhrada FP č. 99FP0001'],
        ];
        yield 'banka jen s protiúčtem' => [
            'Banka 1000000005/0100 — odchozí platba — 2000000003/0300 (VS 102099) — úklid', 'bank',
            ['counterparty_account' => '2000000003/0300', 'variable_symbol' => '102099'],
            ['kind' => null, 'partner' => '2000000003/0300', 'vs' => '102099', 'text' => 'úklid'],
        ];
        yield 'pokladna' => [
            '99HV00006 — Hlavní pokladna — Obchod s.r.o. — Pokladna 99HV00006 — vybavení', 'cash',
            ['source_ref' => '99HV00006', 'partner_name' => 'Obchod s.r.o.'],
            ['kind' => null, 'partner' => 'Obchod s.r.o.', 'vs' => null, 'text' => 'vybavení'],
        ];
        yield 'interní doklad' => [
            'Interní doklady 99IN00067 — Přiznání DPH z poskytnutí služby ze státu EU', 'manual',
            ['document_no' => '99IN00067'],
            ['kind' => null, 'partner' => null, 'vs' => null, 'text' => 'Přiznání DPH z poskytnutí služby ze státu EU'],
        ];
        yield 'ruční popis zůstane celý' => [
            'Účetní odpis - M00002', 'manual', ['document_no' => 'ID-1'],
            ['kind' => null, 'partner' => null, 'vs' => null, 'text' => 'Účetní odpis - M00002'],
        ];
        yield 'faktura bez textu' => [
            'FV 2099008 — Odběratel s.r.o.', 'invoice', ['source_ref' => '2099008', 'partner_name' => 'Odběratel s.r.o.'],
            ['kind' => null, 'partner' => 'Odběratel s.r.o.', 'vs' => null, 'text' => null],
        ];
    }

    /**
     * @param array<string,string> $facts
     * @param array<string,?string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('partsCases')]
    public function testPartsSplitDescriptionIntoColumnsAndText(string $description, string $type, array $facts, array $expected): void
    {
        self::assertSame($expected, B::parts($description, $type, $facts));
    }
}
