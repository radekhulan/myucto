<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Closing\FxRevaluationService;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy výpočtu kurzových rozdílů k rozvahovému dni (Epic F4, §6.1 U6–U12).
 *
 * Očekávané hodnoty jsou ZÁVAZNÉ ručně spočtené ze spec F4 (R10, ČÚS 006,
 * §24/6+7 ZoÚ): pohledávka kurz↑ = zisk MD účet / D 663; pohledávka kurz↓ =
 * ztráta MD 563 / D účet; závazek kurz↑ (dluh v Kč vzrostl) = ztráta MD 563 /
 * D účet; závazek kurz↓ = zisk MD účet / D 663. DB závislosti jsou mockované
 * (bez DB — dg/bypass-finals v tests/bootstrap.php).
 */
final class FxDiffTest extends TestCase
{
    private const SUPPLIER = 42;
    private const ENDS_ON = '2098-12-31';
    private const PERIOD = ['id' => 1, 'starts_on' => '2098-01-01', 'ends_on' => self::ENDS_ON, 'fiscal_year' => 2098];

    // ── U6: pohledávka EUR 1 000, kurz dokladu 24,50 → ČNB 25,10 ─────────────

    public function testU6ReceivableRateUpIsGain(): void
    {
        $service = $this->service(
            [self::item('invoice', 1, '311', 'EUR', 24.50, 1000.00)],
            ['EUR' => 25.10],
        );

        $preview = $service->preview(self::SUPPLIER, self::PERIOD);

        $detail = $preview['saldo']['detail'][0];
        self::assertSame(self::cents(24500.00), self::cents($detail['booked_czk']));
        self::assertSame(self::cents(25100.00), self::cents($detail['new_czk']));
        self::assertSame(self::cents(600.00), self::cents($detail['diff']));
        self::assertSame('gain', $detail['direction']);
        self::assertSame(self::cents(600.00), self::cents($preview['totals']['gain']));
        self::assertSame(0, self::cents($preview['totals']['loss']));

        // MD 311 600 / D 663
        $entries = $service->buildEntries(self::SUPPLIER, $preview, '2099-12-31');
        $this->assertEntryPair($entries['saldo'], '311', 'debit', 600.00, '663', 'credit');
        // FX stopa na saldokontním řádku (R20): měna, kurz ČNB, amount_foreign 0
        $accountLine = $entries['saldo'][0];
        self::assertSame('EUR', $accountLine['currency_code']);
        self::assertSame(25.10, $accountLine['fx_rate']);
        self::assertSame(0.0, $accountLine['amount_foreign']);
    }

    // ── U7: pohledávka EUR 500 @ 25,00 → 24,20 ───────────────────────────────

    public function testU7ReceivableRateDownIsLoss(): void
    {
        $service = $this->service(
            [self::item('invoice', 2, '311', 'EUR', 25.00, 500.00)],
            ['EUR' => 24.20],
        );

        $preview = $service->preview(self::SUPPLIER, self::PERIOD);

        self::assertSame(self::cents(-400.00), self::cents($preview['saldo']['detail'][0]['diff']));
        self::assertSame('loss', $preview['saldo']['detail'][0]['direction']);

        // MD 563 400 / D 311
        $entries = $service->buildEntries(self::SUPPLIER, $preview, '2099-12-31');
        $this->assertEntryPair($entries['saldo'], '563', 'debit', 400.00, '311', 'credit');
    }

    // ── U8: závazek USD 2 000 @ 22,00 → 21,40 (dluh klesl = zisk) ────────────

    public function testU8PayableRateDownIsGain(): void
    {
        $service = $this->service(
            [self::item('purchase_invoice', 3, '321', 'USD', 22.00, 2000.00)],
            ['USD' => 21.40],
        );

        $preview = $service->preview(self::SUPPLIER, self::PERIOD);

        self::assertSame(self::cents(-1200.00), self::cents($preview['saldo']['detail'][0]['diff']));
        self::assertSame('gain', $preview['saldo']['detail'][0]['direction'], 'Dluh v Kč klesl o 1 200 = kurzový zisk.');

        // MD 321 1 200 / D 663
        $entries = $service->buildEntries(self::SUPPLIER, $preview, '2099-12-31');
        $this->assertEntryPair($entries['saldo'], '321', 'debit', 1200.00, '663', 'credit');
    }

    // ── U9: závazek EUR 1 000 @ 24,00 → 25,00 (dluh vzrostl = ztráta) ────────

    public function testU9PayableRateUpIsLoss(): void
    {
        $service = $this->service(
            [self::item('purchase_invoice', 4, '321', 'EUR', 24.00, 1000.00)],
            ['EUR' => 25.00],
        );

        $preview = $service->preview(self::SUPPLIER, self::PERIOD);

        self::assertSame(self::cents(1000.00), self::cents($preview['saldo']['detail'][0]['diff']));
        self::assertSame('loss', $preview['saldo']['detail'][0]['direction'], 'Dluh v Kč vzrostl o 1 000 = kurzová ztráta.');

        // MD 563 1 000 / D 321
        $entries = $service->buildEntries(self::SUPPLIER, $preview, '2099-12-31');
        $this->assertEntryPair($entries['saldo'], '563', 'debit', 1000.00, '321', 'credit');
    }

    // ── U10: částečná úhrada 40 % před rozvahovým dnem ───────────────────────

    public function testU10PartialPaymentRevaluesRemainderOnly(): void
    {
        $service = $this->service(
            [self::item('invoice', 5, '311', 'EUR', 24.50, 1000.00)],
            ['EUR' => 25.10],
            paidRatio: 0.4,
        );

        $preview = $service->preview(self::SUPPLIER, self::PERIOD);

        $detail = $preview['saldo']['detail'][0];
        self::assertSame(self::cents(600.00), self::cents($detail['remaining_foreign']), 'Zbytek 600 EUR (60 %).');
        self::assertSame(self::cents(360.00), self::cents($detail['diff']), 'diff = 600 × (25,10 − 24,50) = +360.');
        self::assertSame('gain', $detail['direction']);
    }

    // ── U11: banka — účet 221, ledger 50 000 Kč, zůstatek 2 000 EUR ──────────

    public function testU11BankBalanceRevaluation(): void
    {
        $service = $this->service([], ['EUR' => 25.10], ledgerBalance: 50000.00);

        $preview = $service->preview(self::SUPPLIER, self::PERIOD, [
            ['account_code' => '221', 'currency_code' => 'EUR', 'foreign_balance' => 2000.00],
        ]);

        $bank = $preview['bank']['lines'][0];
        self::assertSame(self::cents(50200.00), self::cents($bank['new_czk']));
        self::assertSame(self::cents(200.00), self::cents($bank['diff']));
        self::assertSame('gain', $bank['direction']);

        // MD 221 200 / D 663
        $entries = $service->buildEntries(self::SUPPLIER, $preview, '2099-12-31');
        self::assertSame([], $entries['saldo'], 'Bez saldokontních položek žádný slot 1.');
        $this->assertEntryPair($entries['bank'], '221', 'debit', 200.00, '663', 'credit');

        // MED→HIGH v roce 2: řádek účtu banky/pokladny NESE FX stopu (currency_code + fx_rate,
        // amount_foreign = 0), aby účet od 2. období nevypadl z bankProposals (currency_code
        // IS NULL) a přecenění se tiše nevynechalo. Result účet 663 stopu nenese.
        $bankByCode = [];
        foreach ($entries['bank'] as $l) {
            $bankByCode[(string) $l['account_code']] = $l;
        }
        self::assertSame('EUR', $bankByCode['221']['currency_code']);
        self::assertSame(25.10, $bankByCode['221']['fx_rate']);
        self::assertSame(0.0, $bankByCode['221']['amount_foreign']);
        self::assertArrayNotHasKey('currency_code', $bankByCode['663'], 'Výsledkový účet 663 FX stopu nenese.');
    }

    // ── EP-7: duplicitní bankovní účet v bank_rows → 0 zápisů ─────────────────

    /**
     * Dvakrát tentýž account_code (stejná měna) NESMÍ přecenit týž Kč zůstatek
     * dvakrát — služba to odmítne doménovou výjimkou dřív, než vznikne jakýkoli
     * řádek zápisu (buildEntries se vůbec nespustí).
     */
    public function testEp7DuplicateBankAccountSameCurrencyRejected(): void
    {
        $service = $this->service([], ['EUR' => 25.10], ledgerBalance: 50000.00);

        $this->expectException(\MyInvoice\Service\Accounting\Closing\ClosingException::class);
        $this->expectExceptionMessage('221');

        $service->preview(self::SUPPLIER, self::PERIOD, [
            ['account_code' => '221', 'currency_code' => 'EUR', 'foreign_balance' => 2000.00],
            ['account_code' => '221', 'currency_code' => 'EUR', 'foreign_balance' => 1500.00],
        ]);
    }

    /**
     * Tentýž account_code ve DVOU RŮZNÝCH měnách je ještě nebezpečnější (dva
     * protichůdné diffy proti jednomu zůstatku) — musí skončit stejně: výjimka,
     * ŽÁDNÝ zápis.
     */
    public function testEp7DuplicateBankAccountDifferentCurrencyRejected(): void
    {
        $service = $this->service([], ['EUR' => 25.10, 'USD' => 22.30], ledgerBalance: 50000.00);

        try {
            $service->preview(self::SUPPLIER, self::PERIOD, [
                ['account_code' => '221500', 'currency_code' => 'EUR', 'foreign_balance' => 2000.00],
                ['account_code' => '221500', 'currency_code' => 'USD', 'foreign_balance' => 1000.00],
            ]);
            self::fail('Duplicitní účet v různých měnách měl vyhodit ClosingException.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertSame('fx_duplicate_bank_account', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    /**
     * Dva RŮZNÉ účty vedle sebe zůstanou v pořádku (regrese k dedup logice —
     * dedup nesmí bránit legitimnímu vícenásobnému přecenění různých účtů).
     */
    public function testEp7DistinctBankAccountsBothRevalued(): void
    {
        $service = $this->service([], ['EUR' => 25.10], ledgerBalance: 50000.00);

        $preview = $service->preview(self::SUPPLIER, self::PERIOD, [
            ['account_code' => '221500', 'currency_code' => 'EUR', 'foreign_balance' => 2000.00],
            ['account_code' => '221510', 'currency_code' => 'EUR', 'foreign_balance' => 1000.00],
        ]);

        self::assertCount(2, $preview['bank']['lines'], 'Dva různé účty = dva řádky.');
        $entries = $service->buildEntries(self::SUPPLIER, $preview, '2099-12-31');
        self::assertCount(4, $entries['bank'], 'Dva přeceňovací páry (2× MD/D).');
    }

    // ── EP-17: nepeněžní záloha 314/324 se kurzem NEPŘECEŇUJE ─────────────────

    /**
     * Nepeněžní záloha (§4/12 ZoÚ, ČÚS 006) — přijatá záloha na budoucí plnění na
     * účtu 324 v cizí měně NESMÍ generovat kurzové přecenění saldokonta, zatímco
     * peněžní pohledávka 311 ve stejné měně přeceněna JE. Ověřuje, že služba
     * vyloučí celou účtovou skupinu 314/324 a peněžní položky nechá beze změny.
     */
    public function testEp17NonMonetaryAdvanceAccountsSkipped(): void
    {
        $service = $this->service(
            [
                self::item('purchase_invoice', 10, '324', 'EUR', 24.00, 1000.00), // přijatá záloha (nepeněžní)
                self::item('purchase_invoice', 11, '314', 'EUR', 24.00, 1000.00), // poskytnutá záloha (nepeněžní)
                self::item('invoice', 12, '311', 'EUR', 24.50, 1000.00),          // peněžní pohledávka
            ],
            ['EUR' => 25.10],
        );

        $preview = $service->preview(self::SUPPLIER, self::PERIOD);

        // Detail obsahuje jen peněžní pohledávku 311; zálohy 314/324 vypadly.
        self::assertCount(1, $preview['saldo']['detail'], 'Nepeněžní zálohy 314/324 se do FX detailu vůbec nedostanou.');
        self::assertSame('311', $preview['saldo']['detail'][0]['account_code']);
        foreach ($preview['saldo']['lines'] as $line) {
            self::assertStringStartsNotWith('314', (string) $line['account_code']);
            self::assertStringStartsNotWith('324', (string) $line['account_code']);
        }

        // Přeceňuje se jen 311 (kurz↑ 24,50 → 25,10 = +600 zisk); zálohy nepřispívají.
        self::assertSame(self::cents(600.00), self::cents($preview['totals']['gain']));
        self::assertSame(0, self::cents($preview['totals']['loss']));

        $entries = $service->buildEntries(self::SUPPLIER, $preview, '2099-12-31');
        $this->assertEntryPair($entries['saldo'], '311', 'debit', 600.00, '663', 'credit');
    }

    // ── U12: reversal builder — zrcadlo U6 ───────────────────────────────────

    public function testU12ReversalMirrorsSaldoLines(): void
    {
        $service = $this->service(
            [self::item('invoice', 1, '311', 'EUR', 24.50, 1000.00)],
            ['EUR' => 25.10],
        );
        $preview = $service->preview(self::SUPPLIER, self::PERIOD);
        $saldo = $service->buildEntries(self::SUPPLIER, $preview, '2099-12-31')['saldo'];

        $reversal = $service->buildReversal($saldo);

        // MD 663 600 / D 311 — prohozené strany, stejné částky (i FX stopa)
        $this->assertEntryPair($reversal, '663', 'debit', 600.00, '311', 'credit');
        self::assertCount(count($saldo), $reversal);
        foreach ($saldo as $i => $orig) {
            self::assertSame(
                $orig['side'] === 'debit' ? 'credit' : 'debit',
                $reversal[$i]['side'],
                'Strana řádku ' . $i . ' je prohozená.',
            );
            self::assertSame(self::cents((float) $orig['amount']), self::cents((float) $reversal[$i]['amount']));
            self::assertSame($orig['account_code'], $reversal[$i]['account_code']);
        }
        self::assertSame('EUR', $reversal[0]['currency_code'] ?? $reversal[1]['currency_code'], 'FX stopa zůstává.');
    }

    public function testRedStornoFxReversalKeepsSideAndBecomesOrdinary(): void
    {
        $service = $this->service([], []);
        $reversal = $service->buildReversal([[
            'account_code' => '311',
            'side' => 'debit',
            'amount' => 10.00,
            'is_red_storno' => true,
            'currency_code' => 'EUR',
            'amount_foreign' => 0.40,
        ]]);

        self::assertSame('debit', $reversal[0]['side']);
        self::assertFalse($reversal[0]['is_red_storno']);
        self::assertSame(10.00, $reversal[0]['amount']);
        self::assertSame(0.40, $reversal[0]['amount_foreign']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Sestaví službu s mockovanými závislostmi.
     *
     * @param list<array<string,mixed>> $openItems výstup ClosingRepository::openFxItems
     * @param array<string,float>       $rates     měna → kurz ČNB
     */
    private function service(array $openItems, array $rates, float $paidRatio = 0.0, float $ledgerBalance = 0.0): FxRevaluationService
    {
        $repo = $this->createStub(ClosingRepository::class);
        $repo->method('fxCaseSnapshot')->willReturn(['history' => [], 'entries' => [], 'items' => array_map(static fn (array $item): array => ['item' => $item, 'evidence' => [
            'complete' => true, 'findings' => [],
            'remaining_foreign' => number_format((float) $item['amount_foreign'] * (1 - $paidRatio), 2, '.', ''),
            'booked_czk' => number_format((float) $item['amount_foreign'] * (1 - $paidRatio) * (float) $item['fx_rate'], 2, '.', ''),
        ]], $openItems)]);
        $repo->method('accountBalance')->willReturn($ledgerBalance);

        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willReturnCallback(static function (string $code) use ($rates): ?array {
            if (!isset($rates[$code])) {
                return null;
            }
            return [
                'rate'          => $rates[$code],
                'rate_date'     => self::ENDS_ON,
                'fallback_used' => false,
                'source'        => 'cache',
            ];
        });

        $rules = $this->createStub(PostingRuleRepository::class);
        $rules->method('resolve')->willReturn(null); // fallback 563/663

        $accounts = $this->createStub(ChartOfAccountsRepository::class);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE supplier_accounting_frameworks (id INTEGER PRIMARY KEY, supplier_id INTEGER, effective_from TEXT, accounting_framework TEXT)');
        $db = $this->createStub(\MyInvoice\Infrastructure\Database\Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $roles = \MyInvoice\Service\Accounting\AccountRoleResolver::fromConnection($db);
        return new FxRevaluationService($repo, $cnb, $rules, $accounts, $roles);
    }

    /**
     * @return array<string,mixed> řádek openFxItems
     */
    private static function item(string $docType, int $docId, string $accountCode, string $currency, float $docRate, float $amountForeign): array
    {
        return [
            'doc_type'       => $docType,
            'doc_id'         => $docId,
            'varsymbol'      => 'VS' . $docId,
            'account_id'     => 100 + $docId,
            'account_code'   => $accountCode,
            'currency_code'  => $currency,
            'fx_rate'        => $docRate,
            'amount_foreign' => $amountForeign,
            'total_with_vat' => $amountForeign,
            'paid_at'        => null,
            'status'         => 'issued',
        ];
    }

    /**
     * Ověří dvojici řádků zápisu {MD kód+částka, D kód+částka}.
     *
     * @param list<array<string,mixed>> $lines
     */
    private function assertEntryPair(array $lines, string $codeA, string $sideA, float $amount, string $codeB, string $sideB): void
    {
        self::assertCount(2, $lines, 'Očekávám právě jeden pár řádků.');
        $byCode = [];
        foreach ($lines as $l) {
            $byCode[(string) $l['account_code']] = $l;
        }
        self::assertArrayHasKey($codeA, $byCode);
        self::assertArrayHasKey($codeB, $byCode);
        self::assertSame($sideA, $byCode[$codeA]['side']);
        self::assertSame($sideB, $byCode[$codeB]['side']);
        self::assertSame(self::cents($amount), self::cents((float) $byCode[$codeA]['amount']));
        self::assertSame(self::cents($amount), self::cents((float) $byCode[$codeB]['amount']));
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
