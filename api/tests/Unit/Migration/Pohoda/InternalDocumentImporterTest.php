<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\InternalDocumentImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaJournal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InternalDocumentImporterTest extends TestCase
{
    /** @param list<array{debit:string,credit:string}> $postings */
    #[DataProvider('kinds')]
    public function testClassify(string $expected, string $text, array $postings, bool $settles): void
    {
        self::assertSame($expected, InternalDocumentImporter::classify($text, $postings, $settles));
    }

    public static function kinds(): iterable
    {
        $p = static fn (string $debit, string $credit): array => ['debit' => $debit, 'credit' => $credit];
        yield 'bez kontace' => ['other', 'Přeúčtování', [], false];
        yield 'běžný doklad' => ['other', 'Přeúčtování', [$p('518000', '321001')], false];
        yield 'úhrada dokladů' => ['offset', 'Vyrovnání', [$p('395000', '311001')], true];
        yield 'text zápočet' => ['offset', 'Zápočet faktur', [$p('321001', '311001')], false];
        yield 'text bez diakritiky a velkými' => ['offset', 'ZAPOCET 2026', [$p('321001', '311001')], false];
        yield 'text skloněný' => ['offset', 'Zápočtem faktur', [$p('321001', '311001')], false];
        yield 'zápočet jinde v textu nerozhoduje' => ['other', 'Oprava po zápočtu', [$p('321001', '311001')], false];
        yield 'zápočet má přednost před kurzem' => ['offset', 'Zápočet', [$p('563000', '321001')], false];
        yield 'kurzová ztráta' => ['fx_difference', 'Kurz', [$p('563000', '321001')], false];
        yield 'kurzový zisk' => ['fx_difference', 'Kurz', [$p('311001', '663000')], false];
        yield 'kurz na více řádcích' => ['fx_difference', 'Kurz', [$p('563', '325100'), $p('378001', '663100')], false];
        yield 'kurz proti bance není saldokonto' => ['other', 'Kurz', [$p('563000', '221001')], false];
        yield 'kurz s dalším řádkem' => ['other', 'Kurz', [$p('563000', '321001'), $p('518000', '321001')], false];
        yield 'kurz mezi 563 a 663' => ['other', 'Kurz', [$p('563000', '663000')], false];
        foreach (['381', '383', '384', '385', '388', '389'] as $account) {
            yield 'časové rozlišení ' . $account => ['accrual', 'Rozlišení', [$p($account . '000', '518000')], false];
        }
        yield 'rozlišení na jednom z řádků' => ['accrual', 'Dohad', [$p('518000', '321001'), $p('518000', '389001')], false];
        yield 'účet 386 není v seznamu' => ['other', 'Jiné', [$p('386000', '518000')], false];
        yield 'mzdy i s dohadem' => ['other', 'Mzdy 2026/03', [$p('521000', '389000')], false];
        yield 'mzdy i s úhradou' => ['other', 'mzdy 2026/3', [$p('521000', '331000')], true];
        yield 'text o mzdách bez období' => ['accrual', 'Mzdy dohad', [$p('521000', '389000')], false];
    }

    /** @param list<array{pohoda_id:string,name:string,ico:string}> $partners */
    #[DataProvider('partners')]
    public function testSolePartner(?string $expectedName, array $partners): void
    {
        self::assertSame($expectedName, InternalDocumentImporter::solePartner($partners, '12345678')['name'] ?? null);
    }

    public static function partners(): iterable
    {
        $p = static fn (string $name, string $ico = '', string $id = ''): array => ['pohoda_id' => $id, 'name' => $name, 'ico' => $ico];
        yield 'žádný' => [null, []];
        yield 'jen prázdné řádky' => [null, [$p(''), $p('')]];
        yield 'jeden' => ['A', [$p('A', '11111111')]];
        yield 'prázdné řádky se nepočítají' => ['A', [$p(''), $p('A', '11111111'), $p('')]];
        yield 'stejné IČO, jiný zápis názvu' => ['A', [$p('A', '11111111'), $p('A s.r.o.', '11111111')]];
        yield 'různá IČO' => [null, [$p('A', '11111111'), $p('A', '22222222')]];
        yield 'stejný adresář' => ['A', [$p('A', '', '5'), $p('B', '', '5')]];
        yield 'různý adresář' => [null, [$p('A', '', '5'), $p('A', '', '6')]];
        yield 'stejný název bez IČO, jiná velikost' => ['Firma', [$p('Firma'), $p('FIRMA')]];
        yield 'různé názvy' => [null, [$p('A'), $p('B')]];
        yield 'neporovnatelné řádky' => [null, [$p('', '11111111'), $p('', '', '5')]];
        yield 'doplnění chybějícího názvu' => ['A', [$p('', '11111111'), $p('A', '11111111')]];
        yield 'vlastní firma se nepočítá' => ['A', [$p('Já', '12345678'), $p('A', '11111111')]];
        yield 'jen vlastní firma' => [null, [$p('Já', '12345678')]];
    }

    public function testJournalPartnerIsReadFromAddressAndMdbShape(): void
    {
        $xml = PohodaJournal::partner(['address' => ['company' => 'Test s.r.o.', 'ico' => '1234567']]);
        self::assertSame(['', 'Test s.r.o.', '01234567'], [$xml['pohoda_id'], $xml['name'], $xml['ico']]);

        $mdb = PohodaJournal::partner(['address' => ['company' => '', 'name' => 'Jan Test', 'ico' => ''], 'addressbookId' => '7']);
        self::assertSame(['7', 'Jan Test', ''], [$mdb['pohoda_id'], $mdb['name'], $mdb['ico']]);

        self::assertSame(['', '', ''], array_values(array_intersect_key(PohodaJournal::partner([]), ['pohoda_id' => 1, 'name' => 1, 'ico' => 1])));
    }
}
