<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\OpeningDocuments;
use PHPUnit\Framework\TestCase;

/**
 * Doklady počátečního stavu z Money proti dokladům už navázaným na otevírací zápis:
 * převzetí jen při jednoznačné shodě identifikátoru, partnera i částky, sporná shoda
 * se nezakládá a krytí počátečního stavu odečte navázané doklady.
 */
final class OpeningDocumentsMatchTest extends TestCase
{
    public function testUniqueMatchByVariableSymbolIsAdopted(): void
    {
        $match = OpeningDocuments::matchLinked(
            ['ROK.003|FP23001' => self::candidate('FP23001', 2420.0, ['FP23001', '2023012', 'NJ-2023-012'], '22334455')],
            [self::linked(7, 2420.0, ['PI-0001', 'NJ-2023-012'], '22334455')],
            [],
        );

        self::assertSame(['ROK.003|FP23001' => 7], $match['adopted']);
        self::assertSame([], $match['new']);
        self::assertSame(2420.0, $match['covered']);
    }

    public function testDifferentPartnerIsNotAMatch(): void
    {
        $match = OpeningDocuments::matchLinked(
            ['ROK.003|FV23001' => self::candidate('FV23001', 1210.0, ['FV23001', '2023001'], '11223341')],
            [self::linked(7, 1210.0, ['2023001'], '99887766')],
            [],
        );

        self::assertSame(['ROK.003|FV23001'], $match['new']);
        self::assertSame(1210.0, $match['covered']);
    }

    public function testDocumentClaimedByTwoCandidatesIsAmbiguous(): void
    {
        $match = OpeningDocuments::matchLinked(
            [
                'ROK.002|FV001' => self::candidate('FV001', 500.0, ['FV001'], '', 2022),
                'ROK.003|FV001' => self::candidate('FV001', 500.0, ['FV001'], '', 2023),
            ],
            [self::linked(7, 500.0, ['FV001'], '11223341')],
            [],
        );

        self::assertSame([], $match['adopted']);
        self::assertSame(['ROK.002|FV001', 'ROK.003|FV001'], $match['ambiguous']);
    }

    public function testDocumentOfAnotherOpeningEntryIsAmbiguousAndDoesNotCover(): void
    {
        $match = OpeningDocuments::matchLinked(
            ['ROK.003|FV23001' => self::candidate('FV23001', 1210.0, ['FV23001'], '11223341')],
            [self::linked(7, 1210.0, ['FV23001'], '11223341', first: false)],
            [],
        );

        self::assertSame(['ROK.003|FV23001'], $match['ambiguous']);
        self::assertSame(0.0, $match['covered']);
    }

    public function testMappedCandidateStaysExistingAndItsDocumentIsNotOfferedToOthers(): void
    {
        $match = OpeningDocuments::matchLinked(
            [
                'ROK.003|FV23001' => self::candidate('FV23001', 1210.0, ['FV23001'], '11223341'),
                'ROK.002|FV23001' => self::candidate('FV23001', 1210.0, ['FV23001'], '11223341', 2022),
                'ROK.003|FV23009' => self::candidate('FV23009', 300.0, ['FV23009'], '11223341'),
            ],
            [self::linked(7, 1210.0, ['FV23001'], '11223341')],
            ['2023|FV23001' => 7, '2023|FV23009' => 9],
        );

        self::assertSame(['ROK.003|FV23001', 'ROK.003|FV23009'], $match['existing']);
        self::assertSame(['ROK.002|FV23001'], $match['new']);
        // Doklad z mapy, který na otevírací zápis navázaný není, převod naváže: krytí ho nese taky.
        self::assertSame(1510.0, $match['covered']);
    }

    /**
     * @param list<string> $ids
     * @return array{year:int,document_no:string,total:float,ids:array<string,true>,ico:string}
     */
    private static function candidate(string $docNo, float $total, array $ids, string $ico, int $year = 2023): array
    {
        return ['year' => $year, 'document_no' => $docNo, 'total' => $total, 'ids' => array_fill_keys($ids, true), 'ico' => $ico];
    }

    /**
     * @param list<string> $ids
     * @return array{id:int,total:float,ids:array<string,true>,ico:string,first:bool}
     */
    private static function linked(int $id, float $total, array $ids, string $ico, bool $first = true): array
    {
        return ['id' => $id, 'total' => $total, 'ids' => array_fill_keys($ids, true), 'ico' => $ico, 'first' => $first];
    }
}
