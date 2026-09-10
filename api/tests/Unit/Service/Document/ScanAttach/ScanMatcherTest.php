<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document\ScanAttach;

use MyInvoice\Service\Document\ScanAttach\ScanMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Párování skenů na existující doklady — syntetické skeny a doklady, bez DB a bez AI.
 */
final class ScanMatcherTest extends TestCase
{
    private const OWN_ICO = '12345678';
    private const VENDOR_ICO = '87654321';
    private const CLIENT_ICO = '11223344';

    private ScanMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ScanMatcher();
    }

    public function testBarcodeInFileNameAttachesWithCertainty(): void
    {
        $r = $this->matchWith(
            [$this->file('f1', '4400123456.pdf', null)],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10', ['barcode' => '4400123456'])],
        );

        self::assertSame(['f1'], $r['targets']['purchase_invoice:1']['files']);
        self::assertSame('barcode', $r['targets']['purchase_invoice:1']['method']);
        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $r['targets']['purchase_invoice:1']['level']);
        self::assertTrue($r['targets']['purchase_invoice:1']['attach']);
        self::assertSame(ScanMatcher::OUTCOME_ATTACHED, $r['files']['f1']['outcome']);
    }

    public function testBarcodeMatchReportsAmountMismatch(): void
    {
        $r = $this->matchWith(
            [$this->file('f1', 'sken.pdf', $this->received(['barcode' => '4400123456', 'total_with_vat' => 999.0]))],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10', ['barcode' => '4400123456'])],
        );

        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $r['targets']['purchase_invoice:1']['level']);
        self::assertSame(ScanMatcher::NOTE_AMOUNT_MISMATCH, $r['targets']['purchase_invoice:1']['note']);
    }

    /**
     * Pravidelná faktura téhož dodavatele na stejnou částku v roce 2025 i 2026.
     * Sken dokladu 2026 nese čárový kód; sken dokladu 2025 v dávce vůbec není.
     * Starší doklad si nesmí vzít podle obsahu sken, který kódem patří novějšímu.
     */
    public function testTwoYearsSameAmountKeepsBarcodedScanForNewerDocument(): void
    {
        $scan2026 = $this->file('f2026', '4400123456.pdf', $this->received(['total_with_vat' => 2904.0, 'issue_date' => '2026-03-10', 'tax_date' => '2026-03-10']));
        $targets = [
            // Starší doklad je v seznamu PRVNÍ — při párování doklad po dokladu by sken dostal on.
            $this->target('purchase_invoice:2025', 2904.0, '2025-03-10'),
            $this->target('purchase_invoice:2026', 2904.0, '2026-03-10', ['barcode' => '4400123456']),
        ];

        $r = $this->matchWith([$scan2026], $targets);

        self::assertSame(['f2026'], $r['targets']['purchase_invoice:2026']['files']);
        self::assertSame('barcode', $r['targets']['purchase_invoice:2026']['method']);
        self::assertSame([], $r['targets']['purchase_invoice:2025']['files'], 'doklad 2025 nesmí dostat sken dokladu 2026');
        self::assertSame(ScanMatcher::LEVEL_NONE, $r['targets']['purchase_invoice:2025']['level']);
    }

    /** Oba skeny v dávce: každý doklad dostane svůj rok, i bez čárového kódu. */
    public function testTwoYearsSameAmountEachDocumentGetsItsOwnYearByContent(): void
    {
        $files = [
            $this->file('fa', 'sken-a.pdf', $this->received(['total_with_vat' => 2904.0, 'issue_date' => '2026-03-10', 'tax_date' => '2026-03-10'])),
            $this->file('fb', 'sken-b.pdf', $this->received(['total_with_vat' => 2904.0, 'issue_date' => '2025-03-10', 'tax_date' => '2025-03-10'])),
        ];
        $targets = [
            $this->target('purchase_invoice:2025', 2904.0, '2025-03-10'),
            $this->target('purchase_invoice:2026', 2904.0, '2026-03-10'),
        ];

        $r = $this->matchWith($files, $targets);

        self::assertSame(['fb'], $r['targets']['purchase_invoice:2025']['files']);
        self::assertSame(['fa'], $r['targets']['purchase_invoice:2026']['files']);
        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $r['targets']['purchase_invoice:2025']['level']);
        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $r['targets']['purchase_invoice:2026']['level']);
    }

    public function testContentWithOwnBuyerVendorAndDateIsCertain(): void
    {
        $r = $this->matchWith(
            [$this->file('f1', 'faktura.pdf', $this->received())],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-12')],
        );

        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $r['targets']['purchase_invoice:1']['level']);
        self::assertSame('content', $r['targets']['purchase_invoice:1']['method']);
        self::assertTrue($r['targets']['purchase_invoice:1']['attach']);
    }

    public function testVendorAndAmountWithoutDateIsOnlyLikelyAndWaitsForConfirmation(): void
    {
        $files = [$this->file('f1', 'faktura.pdf', $this->received(['issue_date' => '2026-07-01', 'tax_date' => null]))];
        $targets = [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')];

        $r = $this->matchWith($files, $targets);
        self::assertSame(ScanMatcher::LEVEL_LIKELY, $r['targets']['purchase_invoice:1']['level']);
        self::assertFalse($r['targets']['purchase_invoice:1']['attach']);
        self::assertSame(ScanMatcher::OUTCOME_PROPOSED, $r['files']['f1']['outcome']);

        $accepted = $this->matchWith($files, $targets, ['accept_likely' => true]);
        self::assertTrue($accepted['targets']['purchase_invoice:1']['attach']);
        self::assertSame(ScanMatcher::OUTCOME_ATTACHED, $accepted['files']['f1']['outcome']);
    }

    public function testDocumentNumberInFileNameNeedsConfirmation(): void
    {
        $targets = [$this->target('purchase_invoice:1', 1210.0, '2026-03-10', ['doc_numbers' => ['PF-2026-0268']])];

        $unconfirmed = $this->matchWith([$this->file('f1', 'PF20260268 foto1.jpg', null)], $targets);
        self::assertSame('doc_no', $unconfirmed['targets']['purchase_invoice:1']['method']);
        self::assertSame(ScanMatcher::LEVEL_CANDIDATE, $unconfirmed['targets']['purchase_invoice:1']['level']);
        self::assertFalse($unconfirmed['targets']['purchase_invoice:1']['attach']);

        $confirmed = $this->matchWith([$this->file('f1', 'PF20260268 foto1.jpg', $this->received())], $targets);
        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $confirmed['targets']['purchase_invoice:1']['level']);
        self::assertSame(ScanMatcher::NOTE_DOC_NO_CONFIRMED, $confirmed['targets']['purchase_invoice:1']['note']);

        $trusted = $this->matchWith([$this->file('f1', 'PF20260268 foto1.jpg', null)], $targets, ['trust_doc_no' => true]);
        self::assertTrue($trusted['targets']['purchase_invoice:1']['attach']);
    }

    public function testSeveralPhotosOfOneDocumentAttachTogether(): void
    {
        $r = $this->matchWith(
            [
                $this->file('f1', 'PF20260268 foto1.jpg', $this->received()),
                $this->file('f2', 'PF20260268 foto2.jpg', null),
            ],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10', ['doc_numbers' => ['PF20260268']])],
        );

        self::assertSame(['f1', 'f2'], $r['targets']['purchase_invoice:1']['files']);
        self::assertTrue($r['targets']['purchase_invoice:1']['attach']);
    }

    public function testScanOfAnotherCompanyIsNotMatched(): void
    {
        $r = $this->matchWith(
            [$this->file('f1', 'faktura.pdf', $this->received(['buyer_ico' => '99887766']))],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')],
        );

        self::assertSame([], $r['targets']['purchase_invoice:1']['files']);
        self::assertSame(ScanMatcher::OUTCOME_FOREIGN, $r['files']['f1']['outcome']);
    }

    public function testBuyerNameDecidesWhenIcoIsMissing(): void
    {
        $target = [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')];

        $other = $this->matchWith([$this->file('f1', 'uctenka.pdf', $this->received(['buyer_ico' => null, 'buyer_name' => 'Jiná firma a.s.']))], $target);
        self::assertSame([], $other['targets']['purchase_invoice:1']['files'], 'cizí jméno odběratele = cizí doklad');

        $own = $this->matchWith([$this->file('f1', 'uctenka.pdf', $this->received(['buyer_ico' => null, 'buyer_name' => 'VLASTNÍ FIRMA, s.r.o.']))], $target);
        self::assertSame(['f1'], $own['targets']['purchase_invoice:1']['files']);
        // Jméno je slabší důkaz než IČO — samo jistotu nedá.
        self::assertSame(ScanMatcher::LEVEL_LIKELY, $own['targets']['purchase_invoice:1']['level']);
    }

    public function testLicensePlateOfCompanyVehicleIdentifiesBuyerOfReceipt(): void
    {
        $receipt = $this->received(['buyer_ico' => null, 'buyer_name' => null, 'license_plate' => '1AB 23-45']);

        $withoutPlates = $this->matchWith([$this->file('f1', 'uctenka.pdf', $receipt)], [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')]);
        self::assertSame(ScanMatcher::LEVEL_LIKELY, $withoutPlates['targets']['purchase_invoice:1']['level']);

        $withPlates = $this->matchWith(
            [$this->file('f1', 'uctenka.pdf', $receipt)],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')],
            ['own_plates' => ['1AB2345']],
        );
        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $withPlates['targets']['purchase_invoice:1']['level']);
    }

    public function testPlateInFileNameCountsWhenExtractionHasNone(): void
    {
        $receipt = $this->received(['buyer_ico' => null, 'buyer_name' => null]);
        $r = $this->matchWith(
            [$this->file('f1', 'Tankování 1AB 2345 - 10.3.2026.pdf', $receipt)],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')],
            ['own_plates' => ['1AB 2345']],
        );

        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $r['targets']['purchase_invoice:1']['level']);
    }

    public function testCardEndingOfCompanyCardIdentifiesBuyer(): void
    {
        $receipt = $this->received(['buyer_ico' => null, 'buyer_name' => null, 'card_last4' => '**** **** **** 4242']);
        $r = $this->matchWith(
            [$this->file('f1', 'uctenka.pdf', $receipt)],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')],
            ['own_cards' => ['4242']],
        );

        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $r['targets']['purchase_invoice:1']['level']);
    }

    /** Koncovka mohla patřit jiné kartě firmy — rozhoduje, zda karta k datu dokladu platila. */
    public function testCardEndingCountsOnlyWithinCardValidity(): void
    {
        $receipt = $this->received(['buyer_ico' => null, 'buyer_name' => null, 'card_last4' => '4242']);
        $target = [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')];

        $expired = $this->matchWith([$this->file('f1', 'uctenka.pdf', $receipt)], $target, [
            'own_cards' => [['last4' => '4242', 'valid_from' => '2024-01-01', 'valid_to' => '2025-12-31']],
        ]);
        self::assertSame(ScanMatcher::LEVEL_LIKELY, $expired['targets']['purchase_invoice:1']['level'], 'karta k datu dokladu neplatila');

        $valid = $this->matchWith([$this->file('f1', 'uctenka.pdf', $receipt)], $target, [
            'own_cards' => [['last4' => '4242', 'valid_from' => '2026-01-01', 'valid_to' => null]],
        ]);
        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $valid['targets']['purchase_invoice:1']['level']);
    }

    public function testIssuedInvoiceMatchesOnlyIssuedDocumentOfTheSameAmount(): void
    {
        $issued = [
            'vendor_ico' => self::OWN_ICO, 'buyer_ico' => self::CLIENT_ICO,
            'total_with_vat' => 5000.0, 'issue_date' => '2026-04-01', 'tax_date' => '2026-04-01',
            'company_role' => 'vendor',
        ];
        $r = $this->matchWith(
            [$this->file('f1', 'vydana.pdf', $issued)],
            [
                $this->target('purchase_invoice:1', 5000.0, '2026-04-01'),
                $this->target('invoice:7', 5000.0, '2026-04-01', ['direction' => ScanMatcher::DIRECTION_ISSUED, 'counterparty_ico' => self::CLIENT_ICO]),
            ],
        );

        self::assertSame([], $r['targets']['purchase_invoice:1']['files'], 'vlastní vydaná faktura není přijatý doklad');
        self::assertSame(['f1'], $r['targets']['invoice:7']['files']);
        self::assertSame(ScanMatcher::LEVEL_CERTAIN, $r['targets']['invoice:7']['level']);
    }

    public function testTwoEquallyGoodScansAreCandidatesNotAttached(): void
    {
        $r = $this->matchWith(
            [
                $this->file('f1', 'a.pdf', $this->received()),
                $this->file('f2', 'b.pdf', $this->received()),
            ],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')],
        );

        self::assertSame(ScanMatcher::LEVEL_CANDIDATE, $r['targets']['purchase_invoice:1']['level']);
        self::assertSame(ScanMatcher::NOTE_AMBIGUOUS, $r['targets']['purchase_invoice:1']['note']);
        self::assertEqualsCanonicalizing(['f1', 'f2'], $r['targets']['purchase_invoice:1']['files']);
        self::assertFalse($r['targets']['purchase_invoice:1']['attach']);
        self::assertSame(ScanMatcher::OUTCOME_PROPOSED, $r['files']['f1']['outcome']);
    }

    public function testRejectedPairIsNotProposedAgain(): void
    {
        $r = $this->matchWith(
            [$this->file('f1', 'faktura.pdf', $this->received())],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')],
            ['rejected' => ['f1|purchase_invoice:1']],
        );

        self::assertSame([], $r['targets']['purchase_invoice:1']['files']);
        self::assertSame(ScanMatcher::OUTCOME_ORPHAN, $r['files']['f1']['outcome']);
    }

    public function testFileAttachedEarlierIsNotReused(): void
    {
        $r = $this->matchWith(
            [$this->file('f1', 'faktura.pdf', $this->received())],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')],
            ['claimed_files' => ['f1']],
        );

        self::assertSame([], $r['targets']['purchase_invoice:1']['files']);
        self::assertSame(ScanMatcher::OUTCOME_ATTACHED, $r['files']['f1']['outcome']);
    }

    public function testOwnScanWithoutDocumentIsOrphanAndUnreadableIsReported(): void
    {
        $r = $this->matchWith(
            [
                $this->file('own', 'naklad.pdf', $this->received(['total_with_vat' => 777.0])),
                $this->file('blank', 'nic.pdf', null),
                $this->file('unknown', 'uctenka.pdf', ['vendor_ico' => self::VENDOR_ICO, 'total_with_vat' => 55.0]),
            ],
            [$this->target('purchase_invoice:1', 1210.0, '2026-03-10')],
        );

        self::assertSame(ScanMatcher::OUTCOME_ORPHAN, $r['files']['own']['outcome']);
        self::assertSame('own', $r['files']['own']['ownership']);
        self::assertSame(ScanMatcher::OUTCOME_UNREADABLE, $r['files']['blank']['outcome']);
        self::assertSame(ScanMatcher::OUTCOME_UNKNOWN, $r['files']['unknown']['outcome'], 'účtenka bez odběratele není cizí');
    }

    /**
     * Výkon na 2 000 skenech a 2 000 dokladech (akceptace). Obsahové kolo hledá
     * kandidáty přes koše podle částky, takže nejde o 4 milióny porovnání.
     */
    public function testPerformanceOnTwoThousandItems(): void
    {
        mt_srand(20260910);
        $files = [];
        $targets = [];
        for ($i = 0; $i < 2000; $i++) {
            $amount = round(100 + $i * 37.13 + mt_rand(0, 99) / 100, 2);
            $date = date('Y-m-d', strtotime('2026-01-01') + ($i % 360) * 86400);
            $vendor = (string) (70000000 + $i % 150);
            $barcode = $i % 2 === 0 ? (string) (440000000 + $i) : null;
            $targets[] = $this->target('purchase_invoice:' . $i, $amount, $date, [
                'barcode' => $barcode,
                'counterparty_ico' => $vendor,
                'counterparty_doc_no' => 'FV' . $i,
            ]);
            $x = $this->received([
                'vendor_ico' => $vendor, 'total_with_vat' => $amount,
                'issue_date' => $date, 'tax_date' => $date,
                'document_number' => $i % 3 === 0 ? 'FV' . $i : null,
            ]);
            $name = match ($i % 4) {
                0 => $barcode . '.pdf',
                1 => 'sken-' . $i . '.pdf',
                2 => 'foto-' . $i . '.jpg',
                default => 'dalsi-' . $i . '.pdf',
            };
            $files[] = $this->file('f' . $i, $name, $i % 10 === 9 ? null : $x);
        }

        $start = hrtime(true);
        $r = $this->matchWith($files, $targets);
        $ms = (hrtime(true) - $start) / 1e6;
        fwrite(STDERR, sprintf("\n[ScanMatcher] 2000 skenů × 2000 dokladů: %.1f ms\n", $ms));

        $attached = count(array_filter($r['targets'], static fn (array $t): bool => $t['attach']));
        self::assertGreaterThan(1500, $attached);
        self::assertLessThan(5000, $ms, 'párování 2 000 položek musí doběhnout do 5 s');
    }

    /**
     * @param list<array<string,mixed>> $files
     * @param list<array<string,mixed>> $targets
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function matchWith(array $files, array $targets, array $context = []): array
    {
        return $this->matcher->match($files, $targets, $context + [
            'own_ico' => self::OWN_ICO,
            'own_name' => 'Vlastní firma s.r.o.',
        ]);
    }

    /** @param array<string,mixed>|null $extraction */
    private function file(string $key, string $name, ?array $extraction): array
    {
        return ['key' => $key, 'name' => $name, 'extraction' => $extraction];
    }

    /**
     * Přijatý doklad: firma je odběratel, dodavatel je protistrana.
     *
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function received(array $over = []): array
    {
        return $over + [
            'buyer_ico' => self::OWN_ICO,
            'buyer_name' => 'Vlastní firma s.r.o.',
            'vendor_ico' => self::VENDOR_ICO,
            'vendor_name' => 'Syntetický dodavatel s.r.o.',
            'total_with_vat' => 1210.0,
            'issue_date' => '2026-03-10',
            'tax_date' => '2026-03-10',
            'company_role' => 'buyer',
        ];
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function target(string $key, float $total, string $date, array $over = []): array
    {
        return $over + [
            'key' => $key,
            'direction' => ScanMatcher::DIRECTION_RECEIVED,
            'doc_numbers' => [],
            'barcode' => null,
            'counterparty_ico' => self::VENDOR_ICO,
            'counterparty_doc_no' => null,
            'vs' => null,
            'total' => $total,
            'date' => $date,
            'tax_date' => $date,
        ];
    }
}
