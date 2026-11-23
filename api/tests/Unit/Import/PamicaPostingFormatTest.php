<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Import;

use MyInvoice\Service\Import\PamicaPosting\PamicaPostingException;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingFormat;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingParser;
use PHPUnit\Framework\TestCase;

/**
 * Detektor a parser zaúčtování mezd z PAMICY nad syntetickou fixture (Windows-1250).
 */
final class PamicaPostingFormatTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/pamica/zauctovani-mezd-2099-03.xml';

    public function testDetectsPamicaPayrollPostingOnly(): void
    {
        $xml = (string) file_get_contents(self::FIXTURE);
        self::assertTrue(PamicaPostingFormat::isPamicaDataPack($xml));
        self::assertTrue(PamicaPostingFormat::isPayrollPosting($xml));
        self::assertTrue(PamicaPostingFormat::isPayrollPosting("\xEF\xBB\xBF" . $xml));

        $pohoda = str_replace('application="PAMICA"', 'application="POHODA"', $xml);
        self::assertFalse(PamicaPostingFormat::isPamicaDataPack($pohoda));
        self::assertFalse(PamicaPostingFormat::isPayrollPosting($pohoda));

        $otherPamica = '<?xml version="1.0"?><dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" application="PAMICA" ico="87654321"></dat:dataPack>';
        self::assertTrue(PamicaPostingFormat::isPamicaDataPack($otherPamica));
        self::assertFalse(PamicaPostingFormat::isPayrollPosting($otherPamica));
        self::assertFalse(PamicaPostingFormat::isPayrollPosting("PK\x03\x04zip"));
    }

    public function testParsesAccountsAmountsAndWithoutPostingCommitments(): void
    {
        $parsed = (new PamicaPostingParser())->parse((string) file_get_contents(self::FIXTURE));
        self::assertSame('87654321', $parsed['ico']);
        self::assertSame('MZ209903', $parsed['sym_par']);
        self::assertSame('2099-03-31', $parsed['accounting_date']);
        self::assertSame('Mzdy 2099/03, zaúčtování mzdových nákladů - hrubá mzda', $parsed['internal_documents'][0]['title']);
        self::assertSame(['521000', '331000', 30000.0], [
            $parsed['internal_documents'][0]['lines'][0]['debit'],
            $parsed['internal_documents'][0]['lines'][0]['credit'],
            $parsed['internal_documents'][0]['lines'][0]['amount'],
        ]);
        $social = $parsed['commitments'][1];
        self::assertSame('336001', $social['credit']);
        self::assertSame(9570.0, $social['amount']);
        self::assertSame(['524000', '331000'], array_column($social['lines'], 'debit'));
        $wage = $parsed['commitments'][3];
        self::assertTrue($wage['without_posting']);
        self::assertSame('331000', $wage['credit']);
        self::assertSame(12000.0, $wage['amount']);
        self::assertSame(['name' => 'Testovací Jana', 'is_person' => true, 'street' => null, 'city' => null, 'zip' => null, 'ic' => null], $wage['partner']);
        self::assertSame('cash', $parsed['commitments'][5]['payment_type']);
        self::assertNull($parsed['commitments'][5]['partner']);
    }

    public function testRejectsDoctypeAndUnknownAccounting(): void
    {
        $xml = (string) file_get_contents(self::FIXTURE);
        $parser = new PamicaPostingParser();
        foreach ([
            'invalid_xml' => preg_replace('/\?>/', "?>\n<!DOCTYPE x [<!ENTITY e SYSTEM \"file:///etc/passwd\">]>", $xml, 1),
            'invalid_accounting' => str_replace('521000/331000</typ:ids>', '521000/331000/1</typ:ids>', $xml),
            'sym_par_mismatch' => preg_replace('/MZ209903/', 'MZ209904', $xml, 1),
            'not_payroll_posting' => str_replace('application="PAMICA"', 'application="POHODA"', $xml),
        ] as $code => $bytes) {
            try {
                $parser->parse((string) $bytes);
                self::fail('Očekávaná chyba ' . $code);
            } catch (PamicaPostingException $e) {
                self::assertSame($code, $e->errorCode, $e->getMessage());
                self::assertGreaterThanOrEqual(400, $e->httpStatus);
                self::assertLessThan(500, $e->httpStatus);
            }
        }
    }

    public function testUnmappedCodesAreCollectedWithSampleLines(): void
    {
        try {
            (new PamicaPostingParser())->parse(self::withCodes());
            self::fail('Zkratky bez mapování musí vrátit unmapped_accounting_codes.');
        } catch (PamicaPostingException $e) {
            self::assertSame('unmapped_accounting_codes', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            $codes = array_column($e->context['unmapped_codes'], null, 'code');
            self::assertSame(['7Int', '2Oz', '4Oz', '8Oz'], array_keys($codes));
            self::assertSame(['internal'], $codes['7Int']['usage']);
            self::assertSame(['commitment'], $codes['4Oz']['usage']);
            self::assertSame(30000.0, $codes['7Int']['samples'][0]['amount']);
            self::assertStringStartsWith('Interní doklad', $codes['7Int']['samples'][0]['document']);
            self::assertStringContainsString('7Int', $e->getMessage());
        }
    }

    public function testMappedCodesBecomeAccountPairs(): void
    {
        // Mapování firmy se porovnává bez ohledu na velikost písmen zkratky.
        $parsed = (new PamicaPostingParser())->parse(self::withCodes(), [
            '7INT' => ['debit' => '521', 'credit' => '331'],
            '2oz' => ['debit' => '331', 'credit' => '342'],
            '4OZ' => ['debit' => '524', 'credit' => '336.001'],
            '8OZ' => ['debit' => '331', 'credit' => '336.001'],
        ]);
        $line = $parsed['internal_documents'][0]['lines'][0];
        self::assertSame(['7Int', '521', '331', false, 30000.0],
            [$line['code'], $line['debit'], $line['credit'], $line['without_posting'], $line['amount']]);
        $social = $parsed['commitments'][1];
        self::assertSame('336.001', $social['credit']);
        self::assertSame(['524', '331'], array_column($social['lines'], 'debit'));
        self::assertSame(['4Oz', '8Oz'], array_column($social['lines'], 'code'));
        // Řádky s dvojicí účtů a bez zaúčtování zkratku nemají.
        self::assertNull($parsed['commitments'][2]['lines'][0]['code']);
        self::assertSame('331000', $parsed['commitments'][3]['credit']);
    }

    private static function withCodes(): string
    {
        return strtr((string) file_get_contents(self::FIXTURE), [
            '521000/331000' => '7Int',
            '331000/342000' => '2Oz',
            '524000/336001' => '4Oz',
            '331000/336001' => '8Oz',
        ]);
    }
}
