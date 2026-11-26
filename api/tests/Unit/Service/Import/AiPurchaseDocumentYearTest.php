<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AiPurchaseDocumentYear;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiPurchaseDocumentYearTest extends TestCase
{
    public function testCorrectsSplitYearUsingFullDateFromTerminalSlip(): void
    {
        $data = [
            'issue_date' => '2020-11-17',
            'tax_date' => '2020-11-17',
            'received_at' => '2020-11-17',
            'due_date' => '2020-11-19',
            'delivery_date' => '2020-11-16',
        ];
        $result = AiPurchaseDocumentYear::check(
            $data,
            '2032-11-19',
            static fn (): string => "Datum vystavení:17.11.20\n32 12:01:03\nTerminál: 17.11.2032 12:01:01",
            'Test 17.11.2032.pdf',
            '2031-01-01',
        );

        self::assertSame([
            'issue_date' => '2032-11-17',
            'tax_date' => '2032-11-17',
            'received_at' => '2032-11-17',
            'due_date' => '2032-11-19',
            'delivery_date' => '2032-11-16',
        ], $result['data']);
        self::assertSame(
            'Rok data dokladu AI načetla jako 2020, podle data 17.11.2032 na dokladu je opraven na 2032. Zkontrolujte prosím data.',
            $result['warning'],
        );
    }

    public function testCorrectsFromFilenameAndKeepsOtherYears(): void
    {
        $result = AiPurchaseDocumentYear::check(
            ['issue_date' => '2020-11-17', 'received_at' => '2032-11-19', 'due_date' => null],
            '2032-11-19',
            static fn (): ?string => null,
            'Testovací účtenka 17. 11. 2032.pdf',
        );

        self::assertSame(
            ['issue_date' => '2032-11-17', 'received_at' => '2032-11-19', 'due_date' => null],
            $result['data'],
        );
        self::assertSame(
            'Rok data dokladu AI načetla jako 2020, podle data 17.11.2032 v názvu souboru je opraven na 2032. Zkontrolujte prosím data.',
            $result['warning'],
        );
    }

    public function testWarnsWhenTextAndFilenameDisagree(): void
    {
        $data = ['issue_date' => '2020-11-17', 'due_date' => '2020-11-19'];
        $result = AiPurchaseDocumentYear::check(
            $data,
            '2032-11-17',
            static fn (): string => 'Terminál: 17.11.2031 12:01:01',
            'Testovací účtenka 17.11.2032.pdf',
        );

        self::assertSame($data, $result['data']);
        self::assertSame(
            'Datum dokladu 17. 11. 2020 je o víc než rok starší než nahrání dokladu, zkontrolujte prosím rok.',
            $result['warning'],
        );
    }

    public function testWarnsAboutOldDocumentWithoutMatchingEvidence(): void
    {
        $data = ['issue_date' => '2020-11-17', 'tax_date' => '2020-11-17'];
        $result = AiPurchaseDocumentYear::check(
            $data,
            '2032-11-17',
            static fn (): string => 'Vystaveno 17.11.2020, vytištěno 18.11.2032',
            'Testovací účtenka 17.11.20321.pdf',
        );

        self::assertSame($data, $result['data']);
        self::assertSame(
            'Datum dokladu 17. 11. 2020 je o víc než rok starší než nahrání dokladu, zkontrolujte prosím rok.',
            $result['warning'],
        );
    }

    public function testWarnsAboutFutureTaxDateEvenWhenIssueDateIsPlausible(): void
    {
        $data = ['issue_date' => '2032-11-17', 'tax_date' => '2032-11-21'];
        $result = AiPurchaseDocumentYear::check($data, '2032-11-17', static fn (): ?string => null);

        self::assertSame($data, $result['data']);
        self::assertSame(
            'Datum dokladu 21. 11. 2032 je více než tři dny po nahrání dokladu, zkontrolujte prosím rok.',
            $result['warning'],
        );
    }

    public function testWarnsAboutDateBeforeAccountingStartEvenWithinYearOfUpload(): void
    {
        $data = ['received_at' => '2032-10-17'];
        $result = AiPurchaseDocumentYear::check(
            $data, '2032-11-17', static fn (): string => '17.10.2031', accountingStart: '2032-11-01',
        );

        self::assertSame($data, $result['data']);
        self::assertSame(
            'Datum dokladu 17. 10. 2032 předchází začátku účetnictví firmy, zkontrolujte prosím rok.',
            $result['warning'],
        );
    }

    public function testLeavesAllDatesWhenCorrectionWouldCreateInvalidLeapDay(): void
    {
        $data = ['issue_date' => '2020-02-27', 'due_date' => '2020-02-29'];
        $result = AiPurchaseDocumentYear::check($data, '2033-03-01', static fn (): string => '27.02.2033');

        self::assertSame($data, $result['data']);
        self::assertSame(
            'Datum dokladu 27. 2. 2020 je o víc než rok starší než nahrání dokladu, zkontrolujte prosím rok.',
            $result['warning'],
        );
    }

    public function testLeavesAllDatesWhenCorrectionWouldMoveIssueDateTooFarIntoFuture(): void
    {
        $data = ['issue_date' => '2031-12-31', 'tax_date' => '2031-01-01'];
        $result = AiPurchaseDocumentYear::check($data, '2032-01-02', static fn (): string => '1. 1. 2032');

        self::assertSame($data, $result['data']);
        self::assertSame(
            'Datum dokladu 1. 1. 2031 je o víc než rok starší než nahrání dokladu, zkontrolujte prosím rok.',
            $result['warning'],
        );
    }

    #[DataProvider('plausibleDates')]
    public function testKeepsPlausibleDateWithoutReadingDocumentText(string $uploadedOn, string $issueDate): void
    {
        $data = ['issue_date' => $issueDate, 'due_date' => '2033-11-25'];
        $result = AiPurchaseDocumentYear::check($data, $uploadedOn, static fn () => self::fail('Text se nemá načítat.'));

        self::assertSame(['data' => $data, 'warning' => null], $result);
    }

    public static function plausibleDates(): iterable
    {
        yield 'běžné datum' => ['2032-11-19', '2032-11-17'];
        yield 'přesně rok zpět' => ['2032-11-19', '2031-11-19'];
        yield 'tři dny dopředu' => ['2032-11-19', '2032-11-22'];
        yield 'rok zpět z přestupného dne' => ['2032-02-29', '2031-02-28'];
    }
}
