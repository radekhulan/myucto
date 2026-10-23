<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Repository\VatCoefficientRepository;
use MyInvoice\Service\Invoice\CreditNoteOffsetService;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Úhrada zbytku faktury po zápočtu dobropisu u plátce DPH: daňová část a DPH úhrady
 * musí odpovídat faktuře minus dobropis, ne poměru DPH celé faktury.
 *
 * Zápočet dobropisu není peněžní tok (migrace 1988): do peněžního deníku vstupuje jen
 * skutečně zaplacený zbytek faktury. Má-li faktura položky s různými sazbami DPH a dobropis
 * vrací jen některé (celé nebo část kusů), zbytek nemá složení celé faktury. Rozdělení
 * zbytku poměrem DPH celé faktury pak nadsadí nebo podhodnotí daňový příjem a výdaj.
 * U faktury s jednou sazbou má dobropis týž poměr a rozdělení sedí.
 *
 * Peněžní deník sazby nerozlišuje (zná jen daňovou část a DPH). Po sazbách se proto
 * kontroluje evidence DPH, ze které se skládá přiznání i kontrolní hlášení: dobropis smí
 * snížit jen sazby svých položek, ostatní sazby zůstanou přesně jako na faktuře, a zápočet
 * ani úhrada na evidenci DPH nic nemění.
 *
 * Stejné rozdělení žije na každé cestě úhrady do peněžního deníku, proto se ověřují
 * všechny: banka, pokladna a ruční úhrada, na vydané i přijaté straně.
 */
#[Group('integration')]
final class CreditNoteOffsetVatSplitTest extends CashJournalTestCase
{
    private CreditNoteOffsetService $offsets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->offsets = $this->container->get(CreditNoteOffsetService::class);
    }

    /**
     * Položky faktury a dobropisu [sazba %, počet kusů, cena za kus bez DPH] (dobropis vrací
     * kusy záporně), očekávaná evidence DPH faktury a dobropisu dohromady po sazbách
     * [sazba => [základ, DPH]] a rozdělení zaplaceného zbytku [daňová část, DPH].
     *
     * @return array<string,array{invoice:list<array{0:int,1:float,2:float}>,credit_note:list<array{0:int,1:float,2:float}>,by_rate:array<int,array{0:float,1:float}>,payment:array{0:float,1:float}}>
     */
    private static function shapes(): array
    {
        return [
            // Faktura: služba 21 % 1 000 + 210 a položka 0 % 1 000 = 2 210. Dobropis stornuje
            // celou službu. Zaplacený zbytek 1 000 je jen položka bez DPH.
            'sazby 21 % a 0 %, dobropis stornuje položku 21 %' => [
                'invoice' => [[21, 1.0, 1000.0], [0, 1.0, 1000.0]],
                'credit_note' => [[21, -1.0, 1000.0]],
                'by_rate' => [0 => [1000.0, 0.0], 21 => [0.0, 0.0]],
                'payment' => [1000.0, 0.0],
            ],
            // Faktura: zboží 21 % 10 ks × 100 = 1 000 + 210 a položka 0 % 1 000 = 2 210.
            // Dobropis vrací 4 ks zboží: −400 − 84. Zaplacený zbytek 1 726 = 600 + 1 000 + DPH 126.
            'sazby 21 % a 0 %, dobropis vrací 4 z 10 ks zboží 21 %' => [
                'invoice' => [[21, 10.0, 100.0], [0, 1.0, 1000.0]],
                'credit_note' => [[21, -4.0, 100.0]],
                'by_rate' => [0 => [1000.0, 0.0], 21 => [600.0, 126.0]],
                'payment' => [1600.0, 126.0],
            ],
            // Faktura: dvě položky 12 % (500 + 60 a 3 ks × 100 = 300 + 36) a položka 21 %
            // 1 000 + 210 = 2 106. Dobropis stornuje druhou položku 12 %: −300 − 36. Sazba 21 %
            // zůstane nedotčená. Zaplacený zbytek 1 770 = 500 + 1 000 + DPH 60 + 210.
            'dvě položky 12 % a položka 21 %, dobropis stornuje jednu položku 12 %' => [
                'invoice' => [[12, 1.0, 500.0], [12, 3.0, 100.0], [21, 1.0, 1000.0]],
                'credit_note' => [[12, -3.0, 100.0]],
                'by_rate' => [12 => [500.0, 60.0], 21 => [1000.0, 210.0]],
                'payment' => [1500.0, 270.0],
            ],
            // Faktura: položka 0 % 1 000 a služba 21 % 1 000 + 210 = 2 210. Opravný dobropis
            // vrací položku 0 % (−1 000) a doúčtuje službu 21 % 500 + 105: brutto −395, DPH +105,
            // tedy DPH a brutto s opačným znaménkem. Zaplacený zbytek 1 815 = 1 500 + DPH 315.
            'sazby 0 % a 21 %, opravný dobropis s opačným znaménkem DPH a brutto' => [
                'invoice' => [[0, 1.0, 1000.0], [21, 1.0, 1000.0]],
                'credit_note' => [[0, -1.0, 1000.0], [21, 1.0, 500.0]],
                'by_rate' => [0 => [0.0, 0.0], 21 => [1500.0, 315.0]],
                'payment' => [1500.0, 315.0],
            ],
            // Kontrola: faktura jen se sazbou 21 %, 10 ks × 100 = 1 000 + 210. Dobropis vrací
            // 4 ks: −400 − 84, týž poměr DPH. Zaplacený zbytek 726 = 600 + DPH 126.
            'jen sazba 21 %, dobropis vrací 4 z 10 ks' => [
                'invoice' => [[21, 10.0, 100.0]],
                'credit_note' => [[21, -4.0, 100.0]],
                'by_rate' => [21 => [600.0, 126.0]],
                'payment' => [600.0, 126.0],
            ],
        ];
    }

    /** @return iterable<string,array{0:string,1:string}> strana dokladu a cesta úhrady do peněžního deníku */
    public static function paths(): iterable
    {
        foreach (['issued' => 'vydaná', 'purchase' => 'přijatá'] as $side => $sideLabel) {
            foreach (['bank' => 'banka', 'cash' => 'pokladna', 'manual' => 'ruční úhrada'] as $instrument => $instrumentLabel) {
                yield "{$sideLabel}, {$instrumentLabel}" => [$side, $instrument];
            }
        }
    }

    /** @return iterable<string,array{0:string,1:string,2:array<string,mixed>}> */
    public static function cases(): iterable
    {
        foreach (self::shapes() as $shape => $documents) {
            foreach (self::paths() as $path => [$side, $instrument]) {
                yield "{$path}, {$shape}" => [$side, $instrument, $documents];
            }
        }
    }

    /**
     * @param array{invoice:list<array{0:int,1:float,2:float}>,credit_note:list<array{0:int,1:float,2:float}>,by_rate:array<int,array{0:float,1:float}>,payment:array{0:float,1:float}} $shape
     */
    #[DataProvider('cases')]
    public function testRemainderAfterCreditNoteOffsetIsSplitAsInvoiceMinusCreditNote(string $side, string $instrument, array $shape): void
    {
        $issued = $side === 'issued';
        $invoiceId = $this->document($issued, $shape['invoice'], false);
        $creditNoteId = $this->document($issued, $shape['credit_note'], true);
        $this->db->pdo()->prepare($issued
            ? 'UPDATE invoices SET parent_invoice_id = ? WHERE id = ?'
            : 'UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')->execute([$invoiceId, $creditNoteId]);

        // Evidence DPH po sazbách: faktura minus dobropis, sazby mimo dobropis jako na faktuře.
        $vatByRate = $this->vatByRate($issued, [$invoiceId, $creditNoteId]);
        self::assertSame($shape['by_rate'], $vatByRate, 'evidence DPH po sazbách [základ, DPH] = faktura minus dobropis');
        $invoiceOnly = $this->vatByRate($issued, [$invoiceId]);
        $creditNoteRates = array_map(static fn (array $item): int => $item[0], $shape['credit_note']);
        foreach (array_diff(array_keys($invoiceOnly), $creditNoteRates) as $rate) {
            self::assertSame($invoiceOnly[$rate], $vatByRate[$rate], "sazba {$rate} %: dobropis na ni nesahá, zůstane jako na faktuře");
        }

        $result = $issued
            ? $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId)
            : $this->offsets->applyForPurchase($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);

        [$taxable, $vat] = $shape['payment'];
        $this->pay($issued, $instrument, $invoiceId, round($taxable + $vat, 2));

        $res = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta($taxable, $res['totals'][$bucket . '_danovy'], 0.005,
            'daňová část úhrady = základ faktury minus základ dobropisu');
        self::assertEqualsWithDelta($vat, $res['totals'][$bucket . '_nedanovy'], 0.005,
            'DPH úhrady = DPH faktury minus DPH dobropisu');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);

        self::assertSame($vatByRate, $this->vatByRate($issued, [$invoiceId, $creditNoteId]),
            'zápočet ani úhrada evidenci DPH po sazbách nemění');
    }

    /**
     * Zápočet se od brutto dobropisu liší, když má dobropis odečtenou zálohu: zápočet je
     * částka dobropisu k úhradě (CreditNoteOffsetService). Zbytek faktury je pak brutto minus
     * částka zápočtu, tedy táž veličina, kterou od faktury odečítá noha C2 peněžního deníku,
     * a DPH minus poměrná část DPH dobropisu podle částky zápočtu.
     */
    #[DataProvider('paths')]
    public function testRemainderSplitFollowsOffsetAmount(string $side, string $instrument): void
    {
        $issued = $side === 'issued';
        // Faktura: služba 21 % 1 000 + 210 a položka 0 % 1 000 = 2 210. Dobropis stornuje
        // službu (−1 210); jako převzatý z cizího systému má polovinu vyrovnanou odečtenou
        // zálohou (−605), k úhradě zbývá −605. Zápočet 605 = polovina služby, 500 + DPH 105.
        $invoiceId = $this->document($issued, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false);
        $creditNoteId = $this->document($issued, [[21, -1.0, 1000.0]], true);
        $this->db->pdo()->prepare($issued
            ? 'UPDATE invoices SET parent_invoice_id = ?, advance_paid_amount = -605 WHERE id = ?'
            : 'UPDATE purchase_invoices SET parent_purchase_invoice_id = ?, advance_paid_amount = -605 WHERE id = ?')
            ->execute([$invoiceId, $creditNoteId]);

        $result = $issued
            ? $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId)
            : $this->offsets->applyForPurchase($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);
        $offsets = $this->offsets->forDocument($this->supplierId, $issued ? 'invoice' : 'purchase_invoice', $invoiceId)['offsets'];
        self::assertSame([605.0], array_column($offsets, 'amount'), 'zápočet = částka dobropisu k úhradě, ne jeho brutto');

        // Zaplacený zbytek 1 605 = položka 0 % 1 000 + zbylá polovina služby 500 + DPH 105.
        $this->pay($issued, $instrument, $invoiceId, 1605.0);

        $res = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta(1500.0, $res['totals'][$bucket . '_danovy'], 0.005,
            'daňová část úhrady = základ faktury minus poměrná část základu dobropisu podle zápočtu');
        self::assertEqualsWithDelta(105.0, $res['totals'][$bucket . '_nedanovy'], 0.005,
            'DPH úhrady = DPH faktury minus poměrná část DPH dobropisu podle zápočtu');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /** @return iterable<string,array{0:string}> cesty úhrady přijaté faktury */
    public static function purchasePaths(): iterable
    {
        foreach (self::paths() as $path => [$side, $instrument]) {
            if ($side === 'purchase') {
                yield $path => [$instrument];
            }
        }
    }

    /**
     * Přijatý dobropis, který není daňovým výdajem, vrací nedaňovou část faktury a daňovou
     * část zbytku nesnižuje. Vydaná strana obdobu nemá: osvobození příjmu nese faktura celá.
     */
    #[DataProvider('purchasePaths')]
    public function testNonDeductibleCreditNoteKeepsTaxableRemainder(string $instrument): void
    {
        // Faktura s řádkovým rozpisem: služba 21 % 1 000 + 210 (daňový výdaj) a soukromá
        // položka 0 % 1 000 (není výdajem) = 2 210. Dobropis vrací soukromou položku (−1 000)
        // a jako ona není daňovým výdajem. Zaplacený zbytek 1 210 je celá služba.
        $invoiceId = $this->document(false, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false);
        $creditNoteId = $this->document(false, [[0, -1.0, 1000.0]], true);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET tax_deductible = 0 WHERE id = ?')->execute([$creditNoteId]);
        $this->allocate($invoiceId, [['Služba', 21.0, 1000.0, 210.0, 'deductible'], ['Soukromá položka', 0.0, 1000.0, 0.0, 'not_expense']]);
        $this->offset(false, $invoiceId, $creditNoteId);

        $this->pay(false, $instrument, $invoiceId, 1210.0);

        $res = $this->fullYear($this->supplierId, true);
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta(1000.0, $res['totals']['vydaj_danovy'], 0.005,
            'nedaňový dobropis daňovou část zbytku nesnižuje: zbytek je celá služba');
        self::assertEqualsWithDelta(210.0, $res['totals']['vydaj_nedanovy'], 0.005, 'mimo daňový výdaj je jen DPH služby');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /**
     * Faktura s řádkovým rozpisem a daňový dobropis: daňová část zbytku je daňová část
     * rozpisu minus daňová část dobropisu. Položka mimo daňové výdaje zůstane mimo celá.
     */
    #[DataProvider('purchasePaths')]
    public function testLineAllocationsRemainderIsAllocationsMinusCreditNote(string $instrument): void
    {
        // Faktura s rozpisem: služba 21 % 1 000 + 210 (daňový výdaj) a soukromá položka 0 %
        // 1 000 (není výdajem) = 2 210. Dobropis vrací polovinu služby: −500 − 105. Zaplacený
        // zbytek 1 605 = zbylá služba 500 + DPH 105 a soukromá položka 1 000.
        $invoiceId = $this->document(false, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false);
        $this->allocate($invoiceId, [['Služba', 21.0, 1000.0, 210.0, 'deductible'], ['Soukromá položka', 0.0, 1000.0, 0.0, 'not_expense']]);
        $this->offset(false, $invoiceId, $this->document(false, [[21, -1.0, 500.0]], true));

        $this->pay(false, $instrument, $invoiceId, 1605.0);

        $res = $this->fullYear($this->supplierId, true);
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta(500.0, $res['totals']['vydaj_danovy'], 0.005,
            'daňová část zbytku = služba z rozpisu 1 000 minus dobropis 500');
        self::assertEqualsWithDelta(1105.0, $res['totals']['vydaj_nedanovy'], 0.005,
            'mimo daňové výdaje: DPH zbylé služby 105 a celá soukromá položka 1 000');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /** @return iterable<string,array{0:string,1:string}> režim kráceného odpočtu a cesta úhrady */
    public static function reducedDeductionCases(): iterable
    {
        foreach (['koeficient § 76' => 'reduced', 'poměrný odpočet § 75' => 'proportional'] as $modeLabel => $mode) {
            foreach (self::purchasePaths() as $path => [$instrument]) {
                yield "{$modeLabel}, {$path}" => [$mode, $instrument];
            }
        }
    }

    /**
     * Krácený odpočet: neodečtená část DPH je daňovým výdajem. Faktura i dobropis se krátí
     * stejně, takže daňová část zbytku nese neodečtenou část jen z DPH, které na zbytku zůstalo.
     */
    #[DataProvider('reducedDeductionCases')]
    public function testReducedDeductionAppliesToInvoiceAndCreditNote(string $mode, string $instrument): void
    {
        // Odpočet 60 %: u kráceného zálohový koeficient roku, u poměrného procento dokladu.
        $this->container->get(VatCoefficientRepository::class)->setProvisionalPercent($this->supplierId, self::YEAR, 60);
        // Faktura: 10 ks zboží 21 % (1 000 + 210) a položka 0 % 1 000 = 2 210. Dobropis vrací
        // 4 ks: −400 − 84. Zbytek 1 726 = 1 600 + DPH 126; odečte se 60 % DPH, tedy 75,60, a 40 %,
        // 50,40, je daňovým výdajem. Daňová část 1 650,40, mimo daňové výdaje 75,60.
        $invoiceId = $this->document(false, [[21, 10.0, 100.0], [0, 1.0, 1000.0]], false);
        $creditNoteId = $this->document(false, [[21, -4.0, 100.0]], true);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET vat_deduction = ?, vat_deduction_percent = 60 WHERE id IN (?, ?)')
            ->execute([$mode, $invoiceId, $creditNoteId]);
        $this->offset(false, $invoiceId, $creditNoteId);

        $this->pay(false, $instrument, $invoiceId, 1726.0);

        $res = $this->fullYear($this->supplierId, true);
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta(1650.40, $res['totals']['vydaj_danovy'], 0.005,
            'daňová část zbytku = základ 1 600 a neodečtených 40 % z DPH zbytku 126');
        self::assertEqualsWithDelta(75.60, $res['totals']['vydaj_nedanovy'], 0.005, 'mimo daňové výdaje je jen odečtená DPH');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /**
     * Cesty, kterými lze fakturu platit po částech, před zápočtem dobropisu i po něm. Ruční
     * úhrada přijaté faktury je jen stav „zaplaceno" celé faktury: částečnou úhradu nezná
     * a zaplacenou fakturu už zápočet nepřipustí.
     *
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function partialPaths(): iterable
    {
        foreach (self::paths() as $path => [$side, $instrument]) {
            if ($side === 'issued' || $instrument !== 'manual') {
                yield $path => [$side, $instrument];
            }
        }
    }

    /** @return iterable<string,array{0:string,1:string,2:array<string,mixed>}> */
    public static function partialCases(): iterable
    {
        foreach (self::shapes() as $shape => $documents) {
            foreach (self::partialPaths() as $path => [$side, $instrument]) {
                yield "{$path}, {$shape}" => [$side, $instrument, $documents];
            }
        }
    }

    /**
     * Úhrada zaplacená PŘED zápočtem se dělí podle faktury, jak byla v den úhrady, a pozdější
     * zápočet ji nepřepíše. Rozdíl, který zápočet do ní vnese, nese opravný řádek ke dni
     * zápočtu (částka 0, daňová část +Δ, DPH −Δ). Úhrada a oprava dohromady dají totéž
     * rozdělení jako úhrada po zápočtu: faktura minus dobropis.
     *
     * @param array{invoice:list<array{0:int,1:float,2:float}>,credit_note:list<array{0:int,1:float,2:float}>,by_rate:array<int,array{0:float,1:float}>,payment:array{0:float,1:float}} $shape
     */
    #[DataProvider('partialCases')]
    public function testPaymentBeforeOffsetKeepsItsSplitAndOffsetDayCarriesCorrection(string $side, string $instrument, array $shape): void
    {
        $issued = $side === 'issued';
        $invoiceId = $this->document($issued, $shape['invoice'], false);
        [$taxable, $vat] = $shape['payment'];
        $paid = round($taxable + $vat, 2);
        $this->pay($issued, $instrument, $invoiceId, $paid, self::YEAR . '-06-03', false);
        $creditNoteId = $this->document($issued, $shape['credit_note'], true);
        $this->offset($issued, $invoiceId, $creditNoteId);

        $res = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        [$invoiceBase, $invoiceVat] = self::totals($shape['invoice']);
        $paymentBase = round($paid * $invoiceBase / ($invoiceBase + $invoiceVat), 2);
        $payment = $this->onlyRow($res, static fn (array $row): bool => $row['source_type'] !== 'credit_note_offset');
        self::assertEqualsWithDelta($paymentBase, $payment['base'], 0.005,
            'úhrada před zápočtem se dělí podle celé faktury, jak byla v den úhrady');

        $correction = round($taxable - $paymentBase, 2);
        if ($correction == 0.0) {
            self::assertSame(0, $this->countRows($res, 'credit_note_offset'), 'dobropis se složením faktury nic nepřerozdělí');
        } else {
            $row = $this->onlyRow($res, static fn (array $row): bool => $row['source_type'] === 'credit_note_offset');
            self::assertSame(self::YEAR . '-06-05', $row['date'], 'oprava je ke dni zápočtu');
            self::assertEqualsWithDelta(0.0, (float) ($issued ? $row['income'] : $row['expense']), 0.005, 'oprava nehýbe penězi');
            self::assertEqualsWithDelta($correction, $row['base'], 0.005, 'oprava = rozdělení podle zbytku minus rozdělení úhrady');
            self::assertEqualsWithDelta(-$correction, $row['vat'], 0.005, 'o tolik se sníží DPH');
        }
        self::assertEqualsWithDelta($taxable, $res['totals'][$bucket . '_danovy'], 0.005,
            'úhrada a oprava dohromady: daňová část = faktura minus dobropis');
        self::assertEqualsWithDelta($vat, $res['totals'][$bucket . '_nedanovy'], 0.005,
            'úhrada a oprava dohromady: DPH = faktura minus dobropis');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /**
     * Zbytek po zápočtu zaplacený ve dvou částech: každá část se dělí poměrem zbytku (faktura
     * minus dobropis), ne celé faktury. Obě úhrady jsou po zápočtu, opravný řádek nevzniká.
     *
     * @param array{invoice:list<array{0:int,1:float,2:float}>,credit_note:list<array{0:int,1:float,2:float}>,by_rate:array<int,array{0:float,1:float}>,payment:array{0:float,1:float}} $shape
     */
    #[DataProvider('partialCases')]
    public function testRemainderPaidInPartsSplitsEachPartAsRemainder(string $side, string $instrument, array $shape): void
    {
        $issued = $side === 'issued';
        $invoiceId = $this->document($issued, $shape['invoice'], false);
        $this->offset($issued, $invoiceId, $this->document($issued, $shape['credit_note'], true));

        [$taxable, $vat] = $shape['payment'];
        $half = round(($taxable + $vat) / 2, 2);
        $this->pay($issued, $instrument, $invoiceId, $half, self::YEAR . '-06-15', false);
        $this->pay($issued, $instrument, $invoiceId, $half, self::YEAR . '-07-15');

        $res = $this->fullYear($this->supplierId, true);
        self::assertSame([self::YEAR . '-06-15', self::YEAR . '-07-15'], array_column($res['rows'], 'date'),
            'v deníku jsou jen obě úhrady, zápočet ani oprava ne');
        foreach ($res['rows'] as $row) {
            self::assertEqualsWithDelta($taxable / 2, $row['base'], 0.005, 'daňová část každé úhrady = polovina daňové části zbytku');
            self::assertEqualsWithDelta($vat / 2, $row['vat'], 0.005, 'DPH každé úhrady = polovina DPH zbytku');
        }
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertEqualsWithDelta($taxable, $res['totals'][$bucket . '_danovy'], 0.005);
        self::assertEqualsWithDelta($vat, $res['totals'][$bucket . '_nedanovy'], 0.005);
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    /**
     * Zápočet v dalším roce nesmí změnit deník roku úhrady (ten může mít dokončenou uzávěrku
     * a podané přiznání). Oprava padne do roku zápočtu a součet obou let sedí na fakturu minus
     * dobropis. Zrušením zápočtu oprava zmizí a rok úhrady se ani tehdy nepohne.
     */
    #[DataProvider('partialPaths')]
    public function testOffsetInLaterYearDoesNotChangeYearOfPayment(string $side, string $instrument): void
    {
        $issued = $side === 'issued';
        $previous = self::YEAR - 1;
        // Faktura: služba 21 % 1 000 + 210 a položka 0 % 1 000 = 2 210. V prosinci se zaplatí
        // 1 000, službu odběratel reklamuje; dobropis ji v červnu dalšího roku stornuje.
        $invoiceId = $this->document($issued, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false, $previous . '-12-01');
        $this->pay($issued, $instrument, $invoiceId, 1000.0, $previous . '-12-15', false);
        $before = $this->year($previous);
        self::assertEqualsWithDelta(904.98, $before['totals'][$issued ? 'prijem_danovy' : 'vydaj_danovy'], 0.005);

        $creditNoteId = $this->document($issued, [[21, -1.0, 1000.0]], true);
        $offsetId = $this->offset($issued, $invoiceId, $creditNoteId);

        $after = $this->year($previous);
        self::assertSame($before['totals'], $after['totals'], 'zápočet v dalším roce rok úhrady nezmění');
        self::assertSame($this->splits($before), $this->splits($after), 'ani jeho řádky');

        $res = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        $row = $this->onlyRow($res, static fn (array $row): bool => true);
        self::assertSame(['credit_note_offset', $offsetId, self::YEAR . '-06-05'], [$row['source_type'], $row['source_id'], $row['date']]);
        self::assertEqualsWithDelta(95.02, $res['totals'][$bucket . '_danovy'], 0.005, 'oprava v roce zápočtu: 1 000 − 904,98');
        self::assertEqualsWithDelta(-95.02, $res['totals'][$bucket . '_nedanovy'], 0.005);
        self::assertEqualsWithDelta(1000.0, $before['totals'][$bucket . '_danovy'] + $res['totals'][$bucket . '_danovy'], 0.005,
            'za oba roky: zaplacená položka bez DPH je celá daňovou částí');

        $this->offsets->revertForDocument($this->supplierId, $issued ? 'invoice' : 'purchase_invoice', $invoiceId);
        self::assertSame(0, $this->countRows($this->fullYear($this->supplierId, true), 'credit_note_offset'), 'zrušením zápočtu oprava zmizí');
        self::assertSame($before['totals'], $this->year($previous)['totals'], 'a rok úhrady se nepohne');
    }

    /**
     * Dva dobropisy k jedné faktuře, oba započtené až po úhradě: každý zápočet nese jen svůj
     * díl rozdílu (zbytek před ním a po něm), dohromady faktura minus oba dobropisy.
     */
    #[DataProvider('partialPaths')]
    public function testEachOffsetCorrectsOnlyItsOwnShare(string $side, string $instrument): void
    {
        $issued = $side === 'issued';
        // Faktura 21 % 1 000 + 210, 12 % 1 000 + 120 a 0 % 1 000 = 3 330. Zaplatí se 1 000
        // (položka 0 %), 5. 6. dobropis stornuje položku 21 %, 10. 6. položku 12 %.
        $invoiceId = $this->document($issued, [[21, 1.0, 1000.0], [12, 1.0, 1000.0], [0, 1.0, 1000.0]], false);
        $this->pay($issued, $instrument, $invoiceId, 1000.0, self::YEAR . '-06-03', false);
        $first = $this->offset($issued, $invoiceId, $this->document($issued, [[21, -1.0, 1000.0]], true));
        $second = $this->offset($issued, $invoiceId, $this->document($issued, [[12, -1.0, 1000.0]], true, self::YEAR . '-06-10'));

        $res = $this->fullYear($this->supplierId, true);
        $bases = [];
        foreach ($res['rows'] as $row) {
            $bases[$row['source_type'] === 'credit_note_offset' ? $row['source_id'] : 'payment'] = $row['base'];
        }
        // Úhrada 1 000 × 3 000 / 3 330; po 1. dobropisu 1 000 × 2 000 / 2 120; po 2. celá 1 000.
        self::assertEqualsWithDelta(900.90, $bases['payment'], 0.005);
        self::assertEqualsWithDelta(943.40 - 900.90, $bases[$first], 0.005);
        self::assertEqualsWithDelta(1000.0 - 943.40, $bases[$second], 0.005);
        self::assertEqualsWithDelta(1000.0, $res['totals'][$issued ? 'prijem_danovy' : 'vydaj_danovy'], 0.005);
        self::assertEqualsWithDelta(0.0, $res['totals'][$issued ? 'prijem_nedanovy' : 'vydaj_nedanovy'], 0.005);
    }

    /** Neplátce DPH úhradu nedělí, zápočet proto nic neopravuje. */
    public function testNonVatPayerHasNoCorrection(): void
    {
        $invoiceId = $this->document(true, [[21, 1.0, 1000.0], [0, 1.0, 1000.0]], false);
        $this->pay(true, 'bank', $invoiceId, 1000.0, self::YEAR . '-06-03', false);
        $this->offset(true, $invoiceId, $this->document(true, [[21, -1.0, 1000.0]], true));

        $res = $this->fullYear($this->supplierId, false);
        self::assertSame(0, $this->countRows($res, 'credit_note_offset'));
        self::assertEqualsWithDelta(1000.0, $res['totals']['prijem_danovy'], 0.005);
    }

    /** @return iterable<string,array{0:string}> cesty úhrady vydané faktury */
    public static function issuedPaths(): iterable
    {
        foreach (self::paths() as $path => [$side, $instrument]) {
            if ($side === 'issued') {
                yield $path => [$instrument];
            }
        }
    }

    /**
     * Faktura osvobozená od daně z příjmů (§ 4 ZDP) se dělí stejně jako zdanitelná, jen místo
     * daňového příjmu jde do osvobozeného: úhrada před zápočtem, opravný řádek i úhrada zbytku.
     * DPH z osvobozené faktury příjmem není.
     */
    #[DataProvider('issuedPaths')]
    public function testExemptInvoiceSplitsIntoExemptIncome(string $instrument): void
    {
        // Faktura: služba 21 % 1 000 + 210 a položka 0 % 2 000 = 3 210, osvobozená. Před
        // zápočtem se zaplatí 1 000, dobropis pak stornuje službu a zbytek 1 000 se zaplatí po
        // zápočtu. Zaplacené 2 000 je položka bez DPH, celá osvobozeným příjmem.
        $invoiceId = $this->document(true, [[21, 1.0, 1000.0], [0, 1.0, 2000.0]], false);
        $creditNoteId = $this->document(true, [[21, -1.0, 1000.0]], true);
        $this->db->pdo()->prepare('UPDATE invoices SET income_tax_exempt = 1 WHERE id IN (?, ?)')->execute([$invoiceId, $creditNoteId]);
        $this->pay(true, $instrument, $invoiceId, 1000.0, self::YEAR . '-06-03', false);
        $this->offset(true, $invoiceId, $creditNoteId);
        $this->pay(true, $instrument, $invoiceId, 1000.0);

        $res = $this->fullYear($this->supplierId, true);
        $before = $this->onlyRow($res, static fn (array $row): bool => $row['date'] === self::YEAR . '-06-03');
        $correction = $this->onlyRow($res, static fn (array $row): bool => $row['source_type'] === 'credit_note_offset');
        $after = $this->onlyRow($res, static fn (array $row): bool => $row['date'] === self::YEAR . '-06-15');
        foreach ([$before, $correction, $after] as $row) {
            self::assertSame('income_exempt', $row['bucket']);
            self::assertEqualsWithDelta(0.0, $row['base'], 0.005, 'osvobozený příjem nemá daňový základ');
        }
        self::assertEqualsWithDelta(65.42, $before['vat'], 0.005, 'úhrada před zápočtem: DPH podle celé faktury, 1 000 × 210 / 3 210');
        self::assertEqualsWithDelta(-65.42, $correction['vat'], 0.005, 'oprava převede DPH, které na zbytku nezůstalo, do osvobozeného příjmu');
        self::assertEqualsWithDelta(0.0, $after['vat'], 0.005, 'úhrada po zápočtu: zbytek je bez DPH');
        self::assertEqualsWithDelta(2000.0, $res['totals']['prijem_osvobozeny'], 0.005);
        self::assertEqualsWithDelta(0.0, $res['totals']['prijem_danovy'], 0.005);
        self::assertEqualsWithDelta(0.0, $res['totals']['prijem_nedanovy'], 0.005);
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Řádkový daňový rozpis přijaté faktury.
     *
     * @param list<array{0:string,1:float,2:float,3:float,4:string}> $lines [popis, sazba %, základ, DPH, daňové zacházení]
     */
    private function allocate(int $invoiceId, array $lines): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
             VALUES (?, '518', 'Ostatní služby', 'expense', 'debit', 1, 1)"
        )->execute([$this->supplierId]);
        $stmt = $pdo->prepare(
            "INSERT INTO purchase_invoice_vat_allocations
                (supplier_id, purchase_invoice_id, description, vat_rate, base_amount, vat_amount, total_amount,
                 tax_treatment, account_code, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, '518', ?)"
        );
        foreach ($lines as $index => [$description, $rate, $base, $vat, $treatment]) {
            $stmt->execute([$this->supplierId, $invoiceId, $description, $rate, $base, $vat, round($base + $vat, 2), $treatment, $index + 1]);
        }
    }

    /** Zápočet dobropisu proti faktuře; vrací id zápočtu. */
    private function offset(bool $issued, int $invoiceId, int $creditNoteId): int
    {
        $this->db->pdo()->prepare($issued
            ? 'UPDATE invoices SET parent_invoice_id = ? WHERE id = ?'
            : 'UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')->execute([$invoiceId, $creditNoteId]);
        $result = $issued
            ? $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId)
            : $this->offsets->applyForPurchase($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);
        return (int) $result['offset_id'];
    }

    /** Peněžní deník plátce DPH za jiný rok než self::YEAR. @return array<string,mixed> */
    private function year(int $year): array
    {
        $this->setVatPayer($this->supplierId, true);
        return $this->service->build($this->supplierId, $year . '-01-01', $year . '-12-31', ['year' => $year]);
    }

    /**
     * Rozdělení řádků deníku [zdroj, id, datum, daňová část, DPH].
     *
     * @param array<string,mixed> $res
     * @return list<array{0:string,1:int,2:string,3:float,4:float}>
     */
    private function splits(array $res): array
    {
        return array_map(static fn (array $row): array => [$row['source_type'], $row['source_id'], $row['date'], $row['base'], $row['vat']], $res['rows']);
    }

    /**
     * Jediný řádek deníku splňující podmínku.
     *
     * @param array<string,mixed> $res
     * @return array<string,mixed>
     */
    private function onlyRow(array $res, callable $match): array
    {
        $rows = array_values(array_filter($res['rows'], $match));
        self::assertCount(1, $rows, 'čekám právě jeden takový řádek deníku');
        return $rows[0];
    }

    /**
     * Základ a DPH dokladu z položek, zaokrouhlené stejně jako {@see document()}.
     *
     * @param list<array{0:int,1:float,2:float}> $items
     * @return array{0:float,1:float}
     */
    private static function totals(array $items): array
    {
        $base = 0.0;
        $vat = 0.0;
        foreach ($items as [$rate, $quantity, $unitPrice]) {
            $line = round($quantity * $unitPrice, 2);
            $base += $line;
            $vat += round($line * $rate / 100, 2);
        }
        return [round($base, 2), round($vat, 2)];
    }

    /**
     * Faktura nebo dobropis s položkami; součty hlavičky jsou součty položek.
     *
     * @param list<array{0:int,1:float,2:float}> $items [sazba %, počet kusů, cena za kus bez DPH]
     * @param string|null $date datum vystavení; bez něj faktura 1. 6. a dobropis 5. 6. roku self::YEAR
     */
    private function document(bool $issued, array $items, bool $creditNote, ?string $date = null): int
    {
        $lines = array_map(static function (array $item): array {
            [$rate, $quantity, $unitPrice] = $item;
            $base = round($quantity * $unitPrice, 2);
            return ['rate' => $rate, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'base' => $base, 'vat' => round($base * $rate / 100, 2)];
        }, $items);
        $base = round(array_sum(array_column($lines, 'base')), 2);
        $vat = round(array_sum(array_column($lines, 'vat')), 2);
        $opts = ['without' => $base, 'with' => round($base + $vat, 2), 'issue_date' => $date ?? self::YEAR . ($creditNote ? '-06-05' : '-06-01')];
        $id = $issued
            ? $this->saleInvoice($this->supplierId, $opts + ['type' => $creditNote ? 'credit_note' : 'invoice'])
            : $this->purchaseInvoice($this->supplierId, $opts + ['document_kind' => $creditNote ? 'credit_note' : 'invoice']);

        $stmt = $this->db->pdo()->prepare(sprintf(
            'INSERT INTO %s
                (%s, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, ?, "ks", ?, ?, ?, ?, ?, ?, ?)',
            $issued ? 'invoice_items' : 'purchase_invoice_items',
            $issued ? 'invoice_id' : 'purchase_invoice_id',
        ));
        foreach ($lines as $index => $line) {
            $stmt->execute([$id, "Položka {$line['rate']} %", $line['quantity'], $line['unit_price'], $this->vatRateId($line['rate']),
                $line['rate'], $line['base'], $line['vat'], round($line['base'] + $line['vat'], 2), $index]);
        }
        return $id;
    }

    /** Česká sazba DPH z číselníku (položka nese sazbu i ve snapshotu). */
    private function vatRateId(int $rate): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM vat_rates WHERE country = 'CZ' ORDER BY rate_percent = ? DESC, valid_to IS NULL DESC, id LIMIT 1"
        );
        $stmt->execute([$rate]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Evidence DPH dokladů za rok po sazbách [sazba => [základ, DPH]] v Kč se znaménkem
     * dokladu (dobropis záporně) — řádky, ze kterých se skládá přiznání i kontrolní hlášení.
     *
     * @param list<int> $documentIds
     * @return array<int,array{0:float,1:float}>
     */
    private function vatByRate(bool $issued, array $documentIds): array
    {
        $out = [];
        foreach ($this->container->get(VatLedgerService::class)->rows($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31') as $row) {
            if ($row['source'] !== ($issued ? 'sale' : 'purchase') || !in_array($row['invoice_id'], $documentIds, true)) {
                continue;
            }
            $rate = (int) round((float) $row['vat_rate']);
            $out[$rate] = [round(($out[$rate][0] ?? 0.0) + (float) $row['base_czk'], 2), round(($out[$rate][1] ?? 0.0) + (float) $row['vat_czk'], 2)];
        }
        ksort($out);
        return $out;
    }

    /**
     * Úhrada faktury zvolenou cestou do peněžního deníku. Úhrada zbytku ($settles) fakturu
     * označí jako zaplacenou; částečná úhrada před zápočtem ji nechá otevřenou, jinak by
     * dobropis nešel započíst.
     */
    private function pay(bool $issued, string $instrument, int $invoiceId, float $amount, ?string $date = null, bool $settles = true): void
    {
        $date ??= self::YEAR . '-06-15';
        if ($issued) {
            if ($instrument === 'bank') {
                $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), $amount, ['posted_at' => $date]);
                $this->invoicePayment($this->supplierId, $invoiceId, $amount, 'bank', $tx, $date);
            } elseif ($instrument === 'cash') {
                // Pokladní úhrada zapíše pokladní doklad i platbu faktury (source = cash).
                $this->cashDoc('in', 'invoice_payment', $amount, ['invoice_id' => $invoiceId], null, $date);
                $this->invoicePayment($this->supplierId, $invoiceId, $amount, 'cash', null, $date);
            } else {
                $this->invoicePayment($this->supplierId, $invoiceId, $amount, 'manual', null, $date);
            }
            if ($settles) {
                $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?")->execute([$date, $invoiceId]);
            }
            return;
        }
        if ($instrument === 'bank') {
            $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), -$amount, ['posted_at' => $date]);
            $this->paymentMatch($this->supplierId, $tx, $invoiceId, $amount);
        } elseif ($instrument === 'cash') {
            $this->cashDoc('out', 'purchase_payment', $amount, ['purchase_invoice_id' => $invoiceId], null, $date);
        }
        // Ruční úhrada přijaté faktury je jen stav „zaplaceno" s datem úhrady (virtuální noha).
        if ($settles) {
            $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")->execute([$date, $invoiceId]);
        }
    }
}
