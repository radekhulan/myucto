<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Import;

use MyInvoice\Service\Import\PamicaPosting\PamicaPostingException;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingParser;
use PHPUnit\Framework\TestCase;

final class PamicaPostingParserTest extends TestCase
{
    public function testMissingAccountingIsCollectedAndMappedByDocumentKind(): void
    {
        $xml = self::pack(self::internal(), self::commitment());
        $parser = new PamicaPostingParser();
        try {
            $parser->parse($xml);
            self::fail('Chybějící předkontace musí průvodce nabídnout k přiřazení.');
        } catch (PamicaPostingException $e) {
            self::assertSame('unmapped_accounting_codes', $e->errorCode);
            self::assertStringContainsString('bez předkontace (závazek)', $e->getMessage());
            $codes = array_column($e->context['unmapped_codes'], null, 'code');
            self::assertSame(['@EMPTY_INTERNAL', '@EMPTY_COMMITMENT'], array_keys($codes));
            self::assertSame(['internal'], $codes['@EMPTY_INTERNAL']['usage']);
            self::assertNull($codes['@EMPTY_INTERNAL']['suggested_accounts']);
            self::assertSame(['debit' => '331', 'credit' => '379.100'], $codes['@EMPTY_COMMITMENT']['suggested_accounts']);
            self::assertSame([['document' => 'Závazek 005', 'text' => 'Srážka ze mzdy', 'amount' => 580.0]],
                $codes['@EMPTY_COMMITMENT']['samples']);
        }
        $parsed = $parser->parse($xml, [
            '@empty_internal' => ['debit' => '521', 'credit' => '331'],
            '@EMPTY_COMMITMENT' => ['debit' => '331', 'credit' => '379'],
        ]);
        $internal = $parsed['internal_documents'][0]['lines'][0];
        self::assertSame(['@EMPTY_INTERNAL', '521', '331'], [$internal['code'], $internal['debit'], $internal['credit']]);
        $commitment = $parsed['commitments'][0];
        self::assertFalse($commitment['without_posting']);
        self::assertSame('379', $commitment['credit']);
        self::assertSame('331', $commitment['lines'][0]['debit']);
    }

    public function testItemAccountingOverridesHeaderAndMissingItemInheritsHeader(): void
    {
        $xml = self::pack(
            self::internal(details: self::line('int', '521000/331000', '100')),
            self::commitment(header: self::accounting('inv', '331000/336001'), details:
                self::line('inv', '524000/336001', '80') . self::line('inv', '', '20')),
        );
        $parsed = (new PamicaPostingParser())->parse($xml);
        self::assertSame('521000', $parsed['internal_documents'][0]['lines'][0]['debit']);
        self::assertSame(['524000', '331000'], array_column($parsed['commitments'][0]['lines'], 'debit'));
        self::assertSame([null, null], array_column($parsed['commitments'][0]['lines'], 'code'));
    }

    public function testOnlyNonzeroItemsWithoutAccountingNeedMapping(): void
    {
        $commitment = self::commitment(details: self::line('inv', '524000/336001', '80')
            . self::line('inv', '', '0') . self::line('inv', '', '20'), title: 'Pojistné');
        $xml = self::pack(self::internal(self::accounting('int', '521000/331000')), $commitment);
        $parser = new PamicaPostingParser();
        try {
            $parser->parse($xml);
            self::fail('Nenulová položka bez předkontace potřebuje přiřazení.');
        } catch (PamicaPostingException $e) {
            self::assertSame('unmapped_accounting_codes', $e->errorCode);
            [$entry] = $e->context['unmapped_codes'];
            self::assertSame('@EMPTY_COMMITMENT', $entry['code']);
            self::assertNull($entry['suggested_accounts']);
            self::assertSame(1, $entry['count']);
            self::assertSame(20.0, $entry['samples'][0]['amount']);
        }
        $parsed = $parser->parse($xml, ['@EMPTY_COMMITMENT' => ['debit' => '331', 'credit' => '336.001']]);
        self::assertSame(['524000', '331'], array_column($parsed['commitments'][0]['lines'], 'debit'));
        self::assertSame(100.0, $parsed['commitments'][0]['amount']);
    }

    public function testOtherMissingCommitmentsSuppressTheDeductionSuggestion(): void
    {
        $xml = self::pack(self::commitment(), str_replace('id="005"', 'id="006"', self::commitment(title: 'Pojistné')));
        try {
            (new PamicaPostingParser())->parse($xml);
            self::fail('Chybějící předkontace musí průvodce nabídnout k přiřazení.');
        } catch (PamicaPostingException $e) {
            self::assertSame('unmapped_accounting_codes', $e->errorCode);
            [$entry] = $e->context['unmapped_codes'];
            self::assertSame(2, $entry['count']);
            self::assertNull($entry['suggested_accounts']);
        }
    }

    public function testMixedCommitmentCanMapMissingLineToUnsplitAccount(): void
    {
        $xml = self::pack(self::commitment(details: self::line('inv', '524000/336000', '80') . self::line('inv', '', '20')));
        $parsed = (new PamicaPostingParser())->parse($xml, ['@EMPTY_COMMITMENT' => ['debit' => '331', 'credit' => '336']]);
        self::assertSame(['336000', '336'], array_column($parsed['commitments'][0]['lines'], 'credit'));
    }

    public function testReservedKeyCannotAppearAsARealPostingCode(): void
    {
        $xml = self::pack(self::commitment(self::accounting('inv', '@EMPTY_COMMITMENT')));
        $this->expectExceptionMessage('Opravte ji v PAMICE a export zopakujte.');
        (new PamicaPostingParser())->parse($xml);
    }

    public function testBezKeepsSourceAccountsUntilChartResolution(): void
    {
        $xml = self::pack(self::commitment(details: self::line('inv', 'BEZ331', '80') . self::line('inv', 'BEZ331000', '20')));
        $commitment = (new PamicaPostingParser())->parse($xml)['commitments'][0];
        self::assertTrue($commitment['without_posting']);
        self::assertSame(['331', '331000'], array_column($commitment['lines'], 'credit'));
        self::assertSame(100.0, $commitment['amount']);
    }

    public function testDifferentBezAnalyticsStillRequireCorrection(): void
    {
        $xml = self::pack(self::commitment(details: self::line('inv', 'BEZ331001', '80') . self::line('inv', 'BEZ331002', '20')));
        $this->expectExceptionMessage('Opravte předkontace v PAMICE.');
        (new PamicaPostingParser())->parse($xml);
    }

    public function testWindows1250EmptyPartnerAndOverlongVariableSymbol(): void
    {
        $xml = iconv('UTF-8', 'Windows-1250', self::pack(self::commitment(
            header: self::accounting('inv', '331000/379000')
                . '<inv:partnerIdentity><typ:address/></inv:partnerIdentity><inv:symVar>123456789012345678901</inv:symVar>',
        ), encoding: 'Windows-1250'));
        self::assertIsString($xml);
        self::assertFalse(mb_check_encoding($xml, 'UTF-8'));
        $commitment = (new PamicaPostingParser())->parse($xml)['commitments'][0];
        self::assertSame('Srážka ze mzdy', $commitment['title']);
        self::assertNull($commitment['partner']);
        self::assertNull($commitment['variable_symbol']);
        self::assertSame('123456789012345678901', $commitment['source_variable_symbol']);
    }

    public function testNegativeInternalLineReversesDebitAndCredit(): void
    {
        $xml = self::pack(self::internal(details: self::line('int', '521000/331000', '-100')));
        $line = (new PamicaPostingParser())->parse($xml)['internal_documents'][0]['lines'][0];
        self::assertSame(['331000', '521000', 100.0], [$line['debit'], $line['credit'], $line['amount']]);
    }

    public function testPartnerAddressFitsTheClientFields(): void
    {
        $address = '<inv:partnerIdentity><typ:address><typ:company>Syntetická firma</typ:company>'
            . '<typ:street>' . str_repeat('ř', 191) . '</typ:street>'
            . '<typ:city>' . str_repeat('ě', 121) . '</typ:city>'
            . '<typ:zip>12345678901</typ:zip></typ:address></inv:partnerIdentity>';
        $xml = self::pack(self::commitment(self::accounting('inv', '331/379') . $address));
        $partner = (new PamicaPostingParser())->parse($xml)['commitments'][0]['partner'];
        self::assertSame(190, mb_strlen($partner['street']));
        self::assertSame(120, mb_strlen($partner['city']));
        self::assertSame('1234567890', $partner['zip']);
    }

    public function testNegativeCommitmentExplainsHowToImportTheRest(): void
    {
        $xml = self::pack(self::commitment(header: self::accounting('inv', '331000/379000'), amount: '-580'));
        $this->expectExceptionMessage('Opravu zpracujte samostatně v MyÚčtu');
        (new PamicaPostingParser())->parse($xml);
    }

    public function testDifferentAccountingMonthsRequireSeparateExports(): void
    {
        $xml = self::pack(
            self::internal(self::accounting('int', '521000/331000')),
            self::commitment(header: self::accounting('inv', '331000/379000'), date: '2099-04-30'),
        );
        $this->expectExceptionMessage('Vyvezte z PAMICY každý měsíc do samostatného XML.');
        (new PamicaPostingParser())->parse($xml);
    }

    public function testDifferentPayrollSymbolsRequireSeparateExports(): void
    {
        $xml = self::pack(
            self::internal(self::accounting('int', '521000/331000')),
            self::commitment(header: self::accounting('inv', '331000/379000'), symPar: 'MZ209904'),
        );
        $this->expectExceptionMessage('Vyvezte z PAMICY každý měsíc do samostatného XML.');
        (new PamicaPostingParser())->parse($xml);
    }

    private static function pack(string $first, string $second = '', string $encoding = 'UTF-8'): string
    {
        return <<<XML
            <?xml version="1.0" encoding="{$encoding}"?>
            <dat:dataPack application="PAMICA" ico="87654321"
                xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                xmlns:int="http://www.stormware.cz/schema/version_2/intDoc.xsd"
                xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd">
                {$first}{$second}
            </dat:dataPack>
            XML;
    }

    private static function internal(string $header = '', string $details = ''): string
    {
        $body = $details !== '' ? "<int:intDocDetail>{$details}</int:intDocDetail>"
            : '<int:intDocSummary><int:homeCurrency><typ:priceNone>100</typ:priceNone></int:homeCurrency></int:intDocSummary>';
        return <<<XML
            <dat:dataPackItem id="001"><int:intDoc>
                <int:intDocHeader><int:symPar>MZ209903</int:symPar><int:date>2099-03-31</int:date>
                    <int:text>Hrubá mzda</int:text>{$header}
                </int:intDocHeader>
                {$body}
            </int:intDoc></dat:dataPackItem>
            XML;
    }

    private static function commitment(string $header = '', string $details = '', string $title = 'Srážka ze mzdy',
        string $amount = '580', string $date = '2099-03-31', string $symPar = 'MZ209903'): string
    {
        $body = $details !== '' ? "<inv:invoiceDetail>{$details}</inv:invoiceDetail>"
            : "<inv:invoiceSummary><inv:homeCurrency><typ:priceNone>{$amount}</typ:priceNone></inv:homeCurrency></inv:invoiceSummary>";
        return <<<XML
            <dat:dataPackItem id="005"><inv:invoice>
                <inv:invoiceHeader><inv:invoiceType>commitment</inv:invoiceType>
                    <inv:symPar>{$symPar}</inv:symPar><inv:date>{$date}</inv:date>
                    <inv:text>{$title}</inv:text>{$header}
                </inv:invoiceHeader>
                {$body}
            </inv:invoice></dat:dataPackItem>
            XML;
    }

    private static function line(string $ns, string $code, string $amount): string
    {
        $tag = $ns === 'int' ? 'int:intDocItem' : 'inv:invoiceItem';
        $accounting = self::accounting($ns, $code);
        return "<{$tag}><{$ns}:homeCurrency><typ:unitPrice>{$amount}</typ:unitPrice></{$ns}:homeCurrency>{$accounting}</{$tag}>";
    }

    private static function accounting(string $ns, string $code): string
    {
        return $code === '' ? '' : "<{$ns}:accounting><typ:ids>{$code}</typ:ids></{$ns}:accounting>";
    }
}
