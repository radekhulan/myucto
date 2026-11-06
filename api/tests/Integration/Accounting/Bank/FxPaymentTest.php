<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\FixedExchangeRateRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cizoměnové úhrady faktur/PF z banky s kurzovým rozdílem 563/663 (Fáze B, B6).
 *
 * Saldokonto (311/321) se odúčtuje v CZK hodnotě PŘEDPISU (cizí částka × kurz předpisu
 * ze stopy řádku deníku), banka (221) v CZK hodnotě SKUTEČNÉ úhrady (cizí částka × kurz
 * ČNB dne banky ze seedu exchange_rates), rozdíl → kurzová ztráta 563 MD / kurzový zisk
 * 663 D. Ověřuje: plnou úhradu se ziskem i ztrátou, částečnou úhradu, FX PF (odchozí) a
 * no-op při shodě kurzu úhrady a předpisu (žádný řádek 563/663).
 *
 * Sdílí bankovní fixtury a rollback-per-test z {@see BankPostingTestCase}.
 */
#[Group('integration')]
final class FxPaymentTest extends BankPostingTestCase
{
    private int $eurId = 0;

    #[\PHPUnit\Framework\Attributes\DataProvider('frameworkFxCases')]
    public function testFrameworkRolesForRealizedFx(bool $nonprofit, float $paymentRate, string $role): void
    {
        $this->financialFramework($nonprofit);
        $invoice = $this->fxSaleInvoice('ROLE-FX', $this->client('Synthetic FX roles'), 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', $paymentRate);
        $tx = $this->fxIncoming($invoice, 1000.00);
        $lines = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        $expected = $nonprofit ? ($role === 'gain' ? '645' : '545') : ($role === 'gain' ? '663.123' : '563.123');
        self::assertArrayHasKey($expected, $lines);
        self::assertSame(1000.0, (float) $lines[$expected][$role === 'gain' ? 'credit' : 'debit']);
        self::assertSame(25000.0, (float) $lines['311']['credit']);
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    public static function frameworkFxCases(): array
    {
        return [[false, 26.0, 'gain'], [false, 24.0, 'loss'], [true, 26.0, 'gain'], [true, 24.0, 'loss']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('frameworkRoundingCases')]
    public function testFrameworkRolesForBankRounding(bool $nonprofit, float $difference): void
    {
        $this->financialFramework($nonprofit);
        $invoice = $this->saleInvoice('ROLE-ROUND', $this->client('Synthetic rounding'), 100.0);
        $this->postPredpis('invoice', $invoice, '311', '602', 100.0);
        $tx = $this->transaction($this->statement(), 100.0 + $difference, ['match_status' => 'manual']);
        $this->invoicePayment($invoice, $tx, 100.0);
        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $result['action'], json_encode($result));
        $lines = $this->linesByAccountCode((int) $result['entry_id']);
        $expected = $nonprofit ? ($difference > 0 ? '649' : '549') : ($difference > 0 ? '648' : '548');
        self::assertSame(0.5, (float) ($lines[$expected][$difference > 0 ? 'credit' : 'debit'] ?? 0));
        $this->assertBalanced((int) $result['entry_id']);
    }

    public static function frameworkRoundingCases(): array
    {
        return [[false, 0.5], [false, -0.5], [true, 0.5], [true, -0.5]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('frameworkRevaluationCases')]
    public function testFrameworkRolesForRevaluationAtFiscalPeriodEnd(bool $nonprofit): void
    {
        $this->financialFramework($nonprofit);
        $this->fxSaleInvoice('ROLE-REVALUE', $this->client('Synthetic revaluation'), 1000.0, 25.0);
        $endsOn = self::YEAR . '-06-30';
        $this->seedRate('EUR', $endsOn, 26.0);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET starts_on = ?, ends_on = ?, status = 'closing' WHERE id = ?")
            ->execute(['2098-07-01', $endsOn, $this->periodId]);
        $period = $this->periods->findById($this->supplierId, $this->periodId);
        $closing = $this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $bankPositions = [
            ['account_code' => '221', 'currency_code' => 'EUR', 'foreign_balance' => 100.0],
            ['account_code' => '211', 'currency_code' => 'EUR', 'foreign_balance' => 100.0],
        ];
        $decision = [];
        if ($nonprofit) {
            $map = $this->accounts->codeToIdMap($this->supplierId);
            $this->journal->insert([
                'supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'entry_date' => $endsOn,
                'document_no' => 'SYN-FX-MONEY', 'source_type' => 'manual', 'source_id' => null,
                'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $this->userId,
            ], [
                ['account_id' => $map['221']['id'], 'side' => 'debit', 'amount' => '2500.00', 'currency_code' => 'EUR', 'amount_foreign' => '100.00', 'fx_rate' => '25.00'],
                ['account_id' => $map['211']['id'], 'side' => 'debit', 'amount' => '2500.00', 'currency_code' => 'EUR', 'amount_foreign' => '100.00', 'fx_rate' => '25.00'],
                ['account_id' => $map['379']['id'], 'side' => 'credit', 'amount' => '5000.00'],
            ]);
            $catalog = (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($this->supplierId, $endsOn);
            $this->db->pdo()->prepare('INSERT INTO dimension_values(supplier_id,type_id,code,name) VALUES (?,?,?,?)')
                ->execute([$this->supplierId, $catalog['activity_kind']['id'], 'SYN_FX', 'Synthetic foreign currency activity']);
            $dimensions = [$catalog['activity']['id'] => $catalog['activity']['values']['economic'],
                $catalog['activity_kind']['id'] => (int) $this->db->pdo()->lastInsertId(), $catalog['tax_regime']['id'] => $catalog['tax_regime']['values']['subject']];
            $preview = $closing->fxPreview($this->supplierId, $this->periodId);
            $allocations = [];
            foreach ($preview['positions'] as $position) {
                if ($position['diff_cents'] === 0) continue;
                $allocations[$position['key']] = ['reason' => 'Synthetic documented attribution of monetary revaluation',
                    'cells' => [['amount' => \MyInvoice\Service\Accounting\Closing\Fx504Builder::decimal(abs($position['diff_cents'])), 'dimensions' => $dimensions]]];
            }
            $decision = ['snapshot_hash' => $preview['snapshot_hash'], 'case_decisions' => $preview['case_decisions'], 'decisions' => $allocations];
            $bankPositions = [];
        }
        $closing->runFxRevaluation($this->supplierId, $this->periodId, $bankPositions, (int) $period['row_version'], [], $decision);
        $entry = $this->journal->findActiveBySourceForUpdate($this->supplierId, 'fx_revaluation', \MyInvoice\Service\Accounting\Closing\ClosingSourceId::fxSaldo($this->periodId));
        self::assertNotNull($entry);
        $lines = $this->linesByAccountCode((int) $entry['id']);
        $expected = $nonprofit ? '645' : '663.123';
        self::assertSame(1000.0, (float) ($lines[$expected]['credit'] ?? 0));
        $this->assertBalanced((int) $entry['id']);
        $bank = $this->journal->findActiveBySourceForUpdate($this->supplierId, 'fx_revaluation', \MyInvoice\Service\Accounting\Closing\ClosingSourceId::fxBank($this->periodId));
        self::assertNotNull($bank);
        $bankLines = $this->linesByAccountCode((int) $bank['id']);
        self::assertSame($nonprofit ? 200.0 : 5200.0, (float) ($bankLines[$expected]['credit'] ?? 0));
        self::assertSame($nonprofit ? 100.0 : 2600.0, (float) $bankLines['211']['debit']);
        self::assertSame($nonprofit ? 100.0 : 2600.0, (float) $bankLines['221']['debit']);
        $this->assertBalanced((int) $bank['id']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('frameworkRevaluationCases')]
    public function testFrameworkRolesForGeneratedFeeGap(bool $nonprofit): void
    {
        $this->financialFramework($nonprofit);
        $invoice = $this->saleInvoice('ROLE-FEE', $this->client('Synthetic fee'), 100.0);
        $this->postPredpis('invoice', $invoice, '311', '602', 100.0);
        $tx = $this->transaction($this->statement(), 95.0, ['match_status' => 'manual', 'matched_invoice_id' => $invoice]);
        $this->invoicePayment($invoice, $tx, 100.0);
        $suggestion = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId, 'bank_transaction_id' => $tx, 'rule_id' => null,
            'source' => 'payment_match', 'debit_account_code' => '568', 'credit_account_code' => '311',
            'amount' => 5.0, 'description' => 'Synthetic fee', 'note' => 'fee_gap',
        ]);
        $entry = $this->service->approveSuggestion($this->supplierId, (int) $suggestion['id'], $this->meta());
        $lines = $this->linesByAccountCode($entry);
        self::assertSame(5.0, (float) ($lines[$nonprofit ? '549.100' : '568']['debit'] ?? 0));
        self::assertSame(95.0, (float) $lines['221']['debit']);
        self::assertSame(100.0, (float) $lines['311']['credit']);
        $this->assertBalanced($entry);
    }

    public static function frameworkRevaluationCases(): array
    {
        return [[false], [true]];
    }

    public function testNonprofitFxWithoutRequiredActivityLeavesNoBankJournal(): void
    {
        $this->financialFramework(true);
        $invoice = $this->fxSaleInvoice('ROLE-FX-NO-ACT', $this->client('Synthetic missing activity'), 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);
        $catalog = (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))
            ->ensureForFramework($this->supplierId, self::YEAR . '-06-15', $this->userId);
        self::assertArrayHasKey('activity', $catalog);
        $tx = $this->transaction($this->statement(), 1000.00, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->fxInvoicePayment($invoice, $tx, 1000.00);

        $result = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('skipped', $result['action']);
        self::assertSame('error', $result['reason']);
        self::assertStringContainsString('povinných systémových dimenzí', $result['message']);
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'bank' AND source_id = ?");
        $stmt->execute([$this->supplierId, $tx]);
        self::assertSame(0, (int) $stmt->fetchColumn());

    }

    private function financialFramework(bool $nonprofit): void
    {
        $this->db->pdo()->prepare('DELETE FROM supplier_accounting_frameworks WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, ?, ?), (?, ?, ?)')
            ->execute([$this->supplierId, '2098-01-01', 'cz_500', $this->supplierId, $nonprofit ? '2098-07-01' : '2099-07-01', 'cz_504']);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        foreach (['545', '645', '549', '649', '549.100', '563.123', '663.123'] as $code) {
            if (!isset($map[$code])) {
                $this->accounts->insert($this->supplierId, ['account_code' => $code, 'name' => 'Synthetic role ' . $code,
                    'account_type' => $code[0] === '5' ? 'expense' : 'revenue', 'normal_side' => $code[0] === '5' ? 'debit' : 'credit', 'is_synthetic' => !str_contains($code, '.')]);
            }
        }
        $rules = $this->container->get(\MyInvoice\Repository\PostingRuleRepository::class);
        $rules->upsertOverride($this->supplierId, 'fx.loss', '563.123', null, 'Synthetic legacy loss');
        $rules->upsertOverride($this->supplierId, 'fx.gain', null, '663.123', 'Synthetic legacy gain');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->eurId = $this->ensureEur();
    }

    // (a) — plná úhrada FX faktury s kurzovým ZISKem (banka > předpis).
    public function testIncomingFullPaymentBooksExchangeGain663(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-GAIN', $client, 1000.00, 25.00); // předpis 25 000 CZK
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);                 // banka 26 000 CZK

        $tx = $this->fxIncoming($inv, 1000.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));

        self::assertEqualsWithDelta(26000.00, $byAcc['221']['debit'], 0.001, '221 MD = skutečná úhrada v CZK (kurz banky).');
        self::assertEqualsWithDelta(25000.00, $byAcc['311']['credit'], 0.001, '311 D = odúčtování pohledávky v kurzu předpisu.');
        self::assertEqualsWithDelta(1000.00, $byAcc['663']['credit'], 0.001, '663 D = kurzový zisk.');
        self::assertArrayNotHasKey('563', $byAcc, 'Zisk nemá kurzovou ztrátu.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (b) — plná úhrada FX faktury s kurzovou ZTRÁTOU (banka < předpis).
    public function testIncomingFullPaymentBooksExchangeLoss563(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-LOSS', $client, 1000.00, 26.00); // předpis 26 000 CZK
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00);                 // banka 25 000 CZK

        $tx = $this->fxIncoming($inv, 1000.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));

        self::assertEqualsWithDelta(25000.00, $byAcc['221']['debit'], 0.001, '221 MD = skutečná úhrada v CZK.');
        self::assertEqualsWithDelta(26000.00, $byAcc['311']['credit'], 0.001, '311 D = odúčtování pohledávky v kurzu předpisu.');
        self::assertEqualsWithDelta(1000.00, $byAcc['563']['debit'], 0.001, '563 MD = kurzová ztráta.');
        self::assertArrayNotHasKey('663', $byAcc, 'Ztráta nemá kurzový zisk.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // CZK pohyb hradí cizoměnovou FV: banka zůstává ve skutečných CZK,
    // saldokonto se odúčtuje kurzem předpisu a rozdíl je kurzová ztráta.
    public function testIncomingCzkPaymentOfForeignInvoiceBooksExchangeLoss(): void
    {
        $client = $this->client('CZK úhrada EUR faktury');
        $inv = $this->fxSaleInvoice('FV-EUR-CZK-LOSS', $client, 1000.00, 24.50);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 24300.00, [
            'match_status' => 'manual',
            'currency' => 'CZK',
            'matched_invoice_id' => $inv,
        ]);
        $this->fxInvoicePayment($inv, $tx, 1000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'CZK úhrada EUR faktury se má zaúčtovat: ' . ($res['reason'] ?? ''));

        $entryId = $this->entryIdForBankTx($tx);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(24300.00, $byAcc['221']['debit'], 0.001, '221 MD = skutečný CZK pohyb.');
        self::assertEqualsWithDelta(24500.00, $byAcc['311']['credit'], 0.001, '311 D = 1 000 EUR × kurz předpisu 24,50.');
        self::assertEqualsWithDelta(200.00, $byAcc['563']['debit'], 0.001, '563 MD = kurzová ztráta.');
        self::assertArrayNotHasKey('663', $byAcc);
        $this->assertBalanced($entryId);

        $saldo = $this->lineForAccount($entryId, '311');
        self::assertSame('EUR', $saldo['currency_code']);
        self::assertEqualsWithDelta(24.50, (float) $saldo['fx_rate'], 0.0001);
        self::assertEqualsWithDelta(1000.00, (float) $saldo['amount_foreign'], 0.001);
    }

    /**
     * Převzaté účetnictví vede saldokonto na analytice (předpis 311.100) a převod zakládá
     * doklad v cizí měně. CZK úhrada musí odúčtovat tutéž analytiku jako korunová větev -
     * jinak předpis na 311.100 zůstane otevřený a úhrada visí na syntetice 311.
     */
    public function testCzkPaymentOfForeignDocumentSettlesAnalyticOfItsPredpis(): void
    {
        $this->analytic('311.100');
        $this->analytic('311.200');
        $inv = $this->fxSaleInvoice('FV-EUR-ANALYTIC', $this->client('EUR na analytice'), 1000.00, 24.50, '311.100');
        $tx = $this->transaction($this->statement(), 24300.00, ['match_status' => 'manual', 'currency' => 'CZK', 'matched_invoice_id' => $inv]);
        $this->fxInvoicePayment($inv, $tx, 1000.00);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], (string) ($res['reason'] ?? ''));
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(24500.00, $byAcc['311.100']['credit'] ?? 0.0, 0.001, json_encode($byAcc));
        self::assertArrayNotHasKey('311', $byAcc);

        $this->analytic('321.100');
        $this->analytic('321.200');
        $pf = $this->fxPurchaseInvoice('PF-EUR-ANALYTIC', $this->client('EUR dodavatel na analytice'), 500.00, 25.00, '321.100');
        $out = $this->transaction($this->statement(), -12000.00, ['match_status' => 'manual', 'currency' => 'CZK']);
        $this->paymentMatch($out, $pf, 12000.00);
        $res = $this->service->handleTransaction($out, $this->userId);
        self::assertSame('posted', $res['action'], (string) ($res['reason'] ?? ''));
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($out));
        self::assertEqualsWithDelta(12500.00, $byAcc['321.100']['debit'] ?? 0.0, 0.001, json_encode($byAcc));
        self::assertArrayNotHasKey('321', $byAcc);
    }

    // Korunová platba, která zbytek cizoměnové PF v kurzové toleranci nepokryje, je částečná
    // úhrada: doklad zůstává otevřený, takže závazek se nesmí odúčtovat celý s rozdílem na 563.
    public function testOutgoingCzkPartialPaymentOfForeignPurchaseRoutesToReview(): void
    {
        $pf = $this->fxPurchaseInvoice('PF-EUR-CZK-PART', $this->client('CZK částečně EUR dodavatel'), 500.00, 25.00);
        $out = $this->transaction($this->statement(), -6000.00, ['match_status' => 'manual', 'currency' => 'CZK']);
        $this->paymentMatch($out, $pf, 6000.00);

        $res = $this->service->handleTransaction($out, $this->userId);

        self::assertSame('suggested', $res['action'], json_encode($res));
        self::assertSame('cross_currency', $res['reason'] ?? '');
        self::assertSame(0, $this->entryCountForTx($out), 'Částečná korunová úhrada nesmí odúčtovat celý nominál 321.');
    }

    // Druhá korunová platba už uhrazené cizoměnové PF (duplicita) nesmí závazek odúčtovat znovu.
    public function testOutgoingCzkSecondPaymentOfSettledForeignPurchaseRoutesToReview(): void
    {
        $pf = $this->fxPurchaseInvoice('PF-EUR-CZK-DUP', $this->client('CZK duplicita EUR dodavatel'), 500.00, 25.00);
        $first = $this->transaction($this->statement(), -12400.00, ['match_status' => 'manual', 'currency' => 'CZK']);
        $this->paymentMatch($first, $pf, 12400.00);
        self::assertSame('posted', $this->service->handleTransaction($first, $this->userId)['action']);

        $second = $this->transaction($this->statement(), -12400.00, ['match_status' => 'manual', 'currency' => 'CZK']);
        $this->paymentMatch($second, $pf, 12400.00);
        $res = $this->service->handleTransaction($second, $this->userId);

        self::assertSame('suggested', $res['action'], json_encode($res));
        self::assertSame(0, $this->entryCountForTx($second));
    }

    /**
     * Karta z korunového účtu za cizoměnovou službu: karetní schéma přidá k ČNB kurzu přirážku
     * přes 4 %. Pohyb kartou se vypořádá celý a rozdíl jde na 563, převod mimo kartu zůstane
     * k ručnímu ověření.
     */
    public function testOutgoingCzkCardPaymentWithSchemeMarkupSettlesForeignPurchase(): void
    {
        $pf = $this->fxPurchaseInvoice('PF-EUR-CARD-MARKUP', $this->client('Karetní služba EUR'), 100.00, 25.00);
        $card = $this->transaction($this->statement(), -2610.00, ['match_status' => 'manual', 'currency' => 'CZK']);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '6801' WHERE id = ?")->execute([$card]);
        $this->paymentMatch($card, $pf, 2610.00);

        $res = $this->service->handleTransaction($card, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $entryId = $this->entryIdForBankTx($card);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(2500.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(2610.00, $byAcc['221']['credit'], 0.001);
        self::assertEqualsWithDelta(110.00, $byAcc['563']['debit'], 0.001);
        $this->assertBalanced($entryId);

        $pfTransfer = $this->fxPurchaseInvoice('PF-EUR-TRANSFER-MARKUP', $this->client('Převod EUR'), 100.00, 25.00);
        $transfer = $this->transaction($this->statement(), -2610.00, ['match_status' => 'manual', 'currency' => 'CZK']);
        $this->paymentMatch($transfer, $pfTransfer, 2610.00);

        $res = $this->service->handleTransaction($transfer, $this->userId);

        self::assertSame('suggested', $res['action'], json_encode($res));
        self::assertSame('cross_currency', $res['reason'] ?? '');
    }

    /**
     * Doklad v jedné cizí měně zaplacený kartou z účtu v jiné cizí měně: banka uvádí původní
     * částku nákupu v měně dokladu. Závazek se odúčtuje v nominálu kurzem předpisu, banka
     * kurzem dne úhrady a rozdíl je kurzový.
     */
    public function testOutgoingPaymentFromOtherForeignCurrencyBooksExchangeDifference(): void
    {
        $pf = $this->fxPurchaseInvoice('PF-EUR-FROM-USD', $this->client('Služba v EUR'), 100.00, 25.00);
        $this->seedRate('USD', self::YEAR . '-06-15', 23.00);
        $tx = $this->transaction($this->statement(), -110.00, ['match_status' => 'manual', 'currency' => 'USD']);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '8783', original_amount = -100.00, original_currency = 'EUR' WHERE id = ?")
            ->execute([$tx]);
        $this->paymentMatch($tx, $pf, 110.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $entryId = $this->entryIdForBankTx($tx);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(2500.00, $byAcc['321']['debit'], 0.001, 'Závazek v nominálu kurzem předpisu.');
        self::assertEqualsWithDelta(2530.00, $byAcc['221']['credit'], 0.001, 'Banka = 110 USD kurzem dne úhrady.');
        self::assertEqualsWithDelta(30.00, $byAcc['563']['debit'], 0.001);
        $this->assertBalanced($entryId);

        $saldo = $this->lineForAccount($entryId, '321');
        self::assertSame('EUR', $saldo['currency_code']);
        self::assertEqualsWithDelta(100.00, (float) $saldo['amount_foreign'], 0.001);
        $bank = $this->lineForAccount($entryId, '221');
        self::assertSame('USD', $bank['currency_code']);
        self::assertEqualsWithDelta(110.00, (float) $bank['amount_foreign'], 0.001);
    }

    /** Původní částka nákupu menší než zbytek dokladu = částečná úhrada, ne vypořádání celého závazku. */
    public function testOutgoingPartialPaymentFromOtherForeignCurrencyRoutesToReview(): void
    {
        $pf = $this->fxPurchaseInvoice('PF-EUR-FROM-USD-PART', $this->client('Služba v EUR částečně'), 100.00, 25.00);
        $this->seedRate('USD', self::YEAR . '-06-15', 23.00);
        $tx = $this->transaction($this->statement(), -66.00, ['match_status' => 'manual', 'currency' => 'USD']);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '8783', original_amount = -60.00, original_currency = 'EUR' WHERE id = ?")
            ->execute([$tx]);
        $this->paymentMatch($tx, $pf, 66.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('suggested', $res['action'], json_encode($res));
        self::assertSame('cross_currency', $res['reason'] ?? '');
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    private function analytic(string $code): void
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        if (isset($map[$code])) {
            return;
        }
        $parent = $map[substr($code, 0, 3)];
        $this->db->pdo()->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             SELECT supplier_id, ?, ?, account_type, normal_side, 0, id, 1 FROM chart_of_accounts WHERE id = ?'
        )->execute([$code, 'Analytika ' . $code, $parent['id']]);
    }

    // (c) — částečná úhrada FX faktury: poměrná část salda i kurzového rozdílu.
    public function testIncomingPartialPaymentBooksProportionalDifference(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-PART', $client, 1000.00, 25.00); // celý předpis 25 000 CZK
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        // Zaplaceno jen 400 EUR: saldo 400×25=10 000, banka 400×26=10 400, zisk 400.
        $tx = $this->fxIncoming($inv, 400.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));

        self::assertEqualsWithDelta(10400.00, $byAcc['221']['debit'], 0.001, '221 MD = 400 EUR × 26.');
        self::assertEqualsWithDelta(10000.00, $byAcc['311']['credit'], 0.001, '311 D = 400 EUR × 25 (kurz předpisu).');
        self::assertEqualsWithDelta(400.00, $byAcc['663']['credit'], 0.001, '663 D = poměrný kurzový zisk.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (d) — odchozí úhrada přijaté FX faktury (PF): 321 v kurzu předpisu, banka v kurzu dne.
    public function testOutgoingPurchasePaymentBooksExchangeDifference(): void
    {
        $vendor = $this->client('EUR Dodavatel');
        $pf = $this->fxPurchaseInvoice('PF-EUR', $vendor, 500.00, 25.00); // závazek 12 500 CZK
        $this->seedRate('EUR', self::YEAR . '-06-15', 24.00);             // banka 12 000 CZK

        $tx = $this->fxOutgoing($pf, 500.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));

        self::assertEqualsWithDelta(12500.00, $byAcc['321']['debit'], 0.001, '321 MD = odúčtování závazku v kurzu předpisu.');
        self::assertEqualsWithDelta(12000.00, $byAcc['221']['credit'], 0.001, '221 D = skutečná úhrada v CZK.');
        // Zaplaceno v CZK méně, než činil závazek → kurzový zisk.
        self::assertEqualsWithDelta(500.00, $byAcc['663']['credit'], 0.001, '663 D = kurzový zisk (zaplaceno méně CZK).');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    public function testOutgoingForeignPaymentCanSettleTwoPurchaseInvoices(): void
    {
        $pf1 = $this->fxPurchaseInvoice('PF-EUR-SPLIT-1', $this->client('EUR Dodavatel 1'), 20.00, 25.00);
        $pf2 = $this->fxPurchaseInvoice('PF-EUR-SPLIT-2', $this->client('EUR Dodavatel 2'), 30.00, 24.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -50.00, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->paymentMatch($tx, $pf1, 20.00);
        $this->paymentMatch($tx, $pf2, 30.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $entryId = $this->entryIdForBankTx($tx);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(1220.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(1300.00, $byAcc['221']['credit'], 0.001);
        self::assertEqualsWithDelta(80.00, $byAcc['563']['debit'], 0.001);
        self::assertCount(2, array_filter(
            $this->entryLines($entryId),
            static fn (array $line): bool => $line['account_code'] === '321',
        ), 'Každý závazek musí mít vlastní 321 nohu a vlastní FX stopu.');
        $this->assertBalanced($entryId);
    }

    public function testOutgoingCzkPaymentCanSettleTwoForeignPurchaseInvoices(): void
    {
        $pf1 = $this->fxPurchaseInvoice('PF-CZK-SPLIT-1', $this->client('CZK Dodavatel EUR 1'), 20.00, 25.00);
        $pf2 = $this->fxPurchaseInvoice('PF-CZK-SPLIT-2', $this->client('CZK Dodavatel EUR 2'), 30.00, 24.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1240.00, ['match_status' => 'manual', 'currency' => 'CZK']);
        $this->paymentMatch($tx, $pf1, 508.20);
        $this->paymentMatch($tx, $pf2, 731.80);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id IN (?, ?)")
            ->execute([self::YEAR . '-06-15', $pf1, $pf2]);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $entryId = $this->entryIdForBankTx($tx);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(1220.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(1240.00, $byAcc['221']['credit'], 0.001);
        self::assertEqualsWithDelta(20.00, $byAcc['563']['debit'], 0.001);
        self::assertCount(2, array_filter(
            $this->entryLines($entryId),
            static fn (array $line): bool => $line['account_code'] === '321',
        ));
        $this->assertBalanced($entryId);
    }

    public function testOutgoingCzkPaymentForTwoForeignPurchasesBooksExchangeGain(): void
    {
        $pf1 = $this->fxPurchaseInvoice('PF-CZK-GAIN-1', $this->client('CZK Gain Dodavatel 1'), 20.00, 25.00);
        $pf2 = $this->fxPurchaseInvoice('PF-CZK-GAIN-2', $this->client('CZK Gain Dodavatel 2'), 30.00, 24.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1200.00, ['match_status' => 'manual', 'currency' => 'CZK']);
        $this->paymentMatch($tx, $pf1, 491.80);
        $this->paymentMatch($tx, $pf2, 708.20);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id IN (?, ?)")
            ->execute([self::YEAR . '-06-15', $pf1, $pf2]);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $entryId = $this->entryIdForBankTx($tx);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(1220.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(1200.00, $byAcc['221']['credit'], 0.001);
        self::assertEqualsWithDelta(20.00, $byAcc['663']['credit'], 0.001, 'Nižší CZK úhrada než hodnota předpisů je kurzový zisk.');
        self::assertArrayNotHasKey('563', $byAcc);
        $this->assertBalanced($entryId);
        self::assertEqualsWithDelta(0.00, $this->purchaseLiabilityBalance([$pf1, $pf2], $tx), 0.001);
    }

    public function testOutgoingForeignSplitKeepsSmallUnallocatedDifferenceOutOfLiabilities(): void
    {
        $pf1 = $this->fxPurchaseInvoice('PF-EUR-REM-1', $this->client('EUR Remainder Dodavatel 1'), 20.00, 25.00);
        $pf2 = $this->fxPurchaseInvoice('PF-EUR-REM-2', $this->client('EUR Remainder Dodavatel 2'), 30.00, 24.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -50.50, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->paymentMatch($tx, $pf1, 20.00);
        $this->paymentMatch($tx, $pf2, 30.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $entryId = $this->entryIdForBankTx($tx);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(1220.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(1313.00, $byAcc['221']['credit'], 0.001);
        self::assertEqualsWithDelta(80.00, $byAcc['563']['debit'], 0.001, 'Kurzový rozdíl se počítá jen z alokovaných 50 EUR.');
        self::assertEqualsWithDelta(13.00, $byAcc['548']['debit'], 0.001, 'Zbývajících 0,50 EUR se oddělí od saldokonta.');
        self::assertArrayNotHasKey('663', $byAcc);
        $this->assertBalanced($entryId);
        self::assertEqualsWithDelta(0.00, $this->purchaseLiabilityBalance([$pf1, $pf2], $tx), 0.001);
    }

    // (e) — kurz úhrady == kurz předpisu → žádný řádek 563/663.
    public function testNoDifferenceWhenBankRateEqualsPredpisRate(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-NOOP', $client, 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00); // shoda kurzů

        $tx = $this->fxIncoming($inv, 1000.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));

        self::assertEqualsWithDelta(25000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(25000.00, $byAcc['311']['credit'], 0.001);
        self::assertArrayNotHasKey('563', $byAcc, 'Shoda kurzů → žádná kurzová ztráta.');
        self::assertArrayNotHasKey('663', $byAcc, 'Shoda kurzů → žádný kurzový zisk.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (f) — nealokovaný zbytek nad toleranci (1 jednotka měny) = přeplatek/nedoplatek,
    //       ne kurzový rozdíl → allocation_mismatch (skip, ruční zaúčtování), žádný zápis.
    public function testIncomingOverpaymentBeyondToleranceIsNotPosted(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-OVER', $client, 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        // tx 1010 EUR, ale alokováno jen 1000 EUR → zbytek 10 EUR ≫ tolerance → skip.
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1010.00, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->fxInvoicePayment($inv, $tx, 1000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action'], 'Přeplatek nad toleranci se nezaúčtuje.');
        self::assertSame('allocation_mismatch', $res['reason'] ?? '');
        self::assertSame(0, $this->entryCountForTx($tx), 'Zbytek cizí měny se nesmí zavléct do 563/663.');
    }

    public function testIncomingFxRemainderWithinToleranceUses648(): void
    {
        $client = $this->client('EUR Odběratel EP8');
        $inv = $this->fxSaleInvoice('FV-EUR-EP8-REM', $client, 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.50, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->fxInvoicePayment($inv, $tx, 1000.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action']);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(26013.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(25000.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['663']['credit'], 0.001, '563/663 obsahuje jen kurzovou část alokace.');
        self::assertEqualsWithDelta(13.00, $byAcc['648']['credit'], 0.001, '0,50 EUR přeplatku patří na 648.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    public function testFixedMonthlyRateIsUsedForBankLeg(): void
    {
        $settings = $this->container->get(AccountingSupplierSettingsRepository::class);
        $rates = $this->container->get(FixedExchangeRateRepository::class);
        $rates->upsert($this->supplierId, 'EUR', self::YEAR, 6, 24.00, 'manual');
        $settings->setFxRateMode($this->supplierId, 'fixed_monthly');

        $client = $this->client('EUR Odběratel fixed');
        $inv = $this->fxSaleInvoice('FV-EUR-EP8-FIX', $client, 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $tx = $this->fxIncoming($inv, 1000.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(24000.00, $byAcc['221']['debit'], 0.001, 'Banka respektuje pevný kurz firmy, ne denní ČNB 26.');
        self::assertEqualsWithDelta(1000.00, $byAcc['563']['debit'], 0.001);
        self::assertArrayNotHasKey('663', $byAcc);
    }

    // (g) — fallback matched_invoice_id (bez invoice_payments řádku) zaúčtuje FX korektně.
    public function testIncomingFallbackMatchedInvoiceBooksFx(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-FB', $client, 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $stmt = $this->statement();
        // Žádný invoice_payments řádek → fallback na matched_invoice_id.
        $tx = $this->transaction($stmt, 1000.00, ['match_status' => 'manual', 'currency' => 'EUR', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Fallback matched_invoice_id se má zaúčtovat: ' . ($res['reason'] ?? ''));
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(26000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(25000.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['663']['credit'], 0.001);
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (h) — fallback na PAID fakturu bez evidované platby → ověření člověkem (parita s CZK
    //       cestou), NE dvojité odúčtování 311 automatikou.
    public function testIncomingFallbackOnPaidInvoiceRoutesToVerify(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-PAID', $client, 1000.00, 25.00);
        $this->db->pdo()->prepare('UPDATE invoices SET status = "paid" WHERE id = ?')->execute([$inv]);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, ['match_status' => 'manual', 'currency' => 'EUR', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action'], 'Paid faktura bez platby → návrh k ověření, ne auto-zaúčtování.');
        self::assertSame('already_paid_verify', $res['reason'] ?? '');
        self::assertSame(0, $this->entryCountForTx($tx), 'Nesmí vzniknout dvojité odúčtování 311.');
    }

    // (i) — CROSS-CURRENCY: korunová faktura uhrazená přes cizoměnový (EUR) účet. Reálný případ
    //       AVYX (bt 999, FV 2409012): 311 D v NOMINÁLNÍ CZK hodnotě faktury, 221 MD v přepočtu
    //       EUR úhrady kurzem ČNB dne, rozdíl → 563. Dřív fx_not_supported (ručně), teď auto (B6+).
    //       Výsledek musí přesně sednout na ručně zaúčtované hodnoty (221 111 904,96 / 311 114 345
    //       / 563 2 440,04).
    public function testCrossCurrencyIncomingCzkInvoiceBooksExchangeLoss563(): void
    {
        $client = $this->client('AVYX DISTRIBUTION');
        $inv = $this->saleInvoice('FV-AVYX-CC', $client, 114345.00); // korunová FV
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4420.50, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->invoicePayment($inv, $tx, 114345.00); // evidovaná úhrada v měně faktury (CZK)

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Křížová měna CZK faktury se má zaúčtovat: ' . ($res['reason'] ?? ''));

        $entryId = $this->entryIdForBankTx($tx);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(111904.96, $byAcc['221']['debit'], 0.001, '221 MD = 4420,50 EUR × 25,315 (kurz ČNB dne).');
        self::assertEqualsWithDelta(114345.00, $byAcc['311']['credit'], 0.001, '311 D = nominální CZK hodnota faktury.');
        self::assertEqualsWithDelta(2440.04, $byAcc['563']['debit'], 0.001, '563 MD = realizovaná kurzová ztráta (sedí na ruční zápis bt 999).');
        self::assertArrayNotHasKey('663', $byAcc);
        $this->assertBalanced($entryId);

        // §4/12 — cizoměnová stopa patří na bankovní nohu (jediná reálně v EUR); saldokonto je nativně CZK.
        $bank = $this->lineForAccount($entryId, '221');
        self::assertSame('EUR', $bank['currency_code']);
        self::assertEqualsWithDelta(25.315, (float) $bank['fx_rate'], 0.0001);
        self::assertEqualsWithDelta(4420.50, (float) $bank['amount_foreign'], 0.001);
        $saldo = $this->lineForAccount($entryId, '311');
        self::assertNull($saldo['currency_code'], 'Korunový předpis cizoměnovou stopu nemá.');
    }

    // (i2) — křížová měna se ziskem (banka přinesla víc CZK, než činil nominál faktury) → 663.
    public function testCrossCurrencyIncomingCzkInvoiceBooksExchangeGain663(): void
    {
        $client = $this->client('EUR na CZK fakturu');
        $inv = $this->saleInvoice('FV-CC-GAIN', $client, 100000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 100000.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4100.00, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->invoicePayment($inv, $tx, 100000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], $res['reason'] ?? '');
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(102500.00, $byAcc['221']['debit'], 0.001, '221 MD = 4100 EUR × 25.');
        self::assertEqualsWithDelta(100000.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(2500.00, $byAcc['663']['credit'], 0.001, '663 D = kurzový zisk.');
        self::assertArrayNotHasKey('563', $byAcc);
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (i3) — částečná křížová úhrada: saldo se odúčtuje evidovanou CZK částkou platby, banka
    //        poměrnou EUR částkou; rozdíl je kurzový.
    public function testCrossCurrencyPartialCzkInvoiceBooksProportionalDifference(): void
    {
        $client = $this->client('AVYX partial');
        $inv = $this->saleInvoice('FV-CC-PART', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        // 2000 EUR × 25,315 = 50 630 CZK, evidováno 51 000 CZK ze salda → ztráta 370.
        $tx = $this->transaction($stmt, 2000.00, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->invoicePayment($inv, $tx, 51000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], $res['reason'] ?? '');
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(50630.00, $byAcc['221']['debit'], 0.001, '221 MD = 2000 EUR × 25,315.');
        self::assertEqualsWithDelta(51000.00, $byAcc['311']['credit'], 0.001, '311 D = evidovaná CZK část salda.');
        self::assertEqualsWithDelta(370.00, $byAcc['563']['debit'], 0.001, '563 MD = kurzová ztráta částečné úhrady.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (i4) — fallback bez invoice_payments (jen matched_invoice_id): plná úhrada korunové faktury
    //        cizí měnou → 311 D = zbytek dluhu faktury. Reprodukuje reálnou vazbu AVYX bt 999.
    public function testCrossCurrencyFallbackMatchedInvoiceBooksFullSettlement(): void
    {
        $client = $this->client('AVYX fallback');
        $inv = $this->saleInvoice('FV-CC-FB', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        // Žádný invoice_payments řádek → fallback na matched_invoice_id (plná úhrada).
        $tx = $this->transaction($stmt, 4420.50, ['match_status' => 'manual', 'currency' => 'EUR', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], $res['reason'] ?? '');
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(111904.96, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(114345.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(2440.04, $byAcc['563']['debit'], 0.001);
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (i5) — idempotence + rozpárování: opakovaný běh nevytvoří druhý živý zápis; unpost stornuje.
    public function testCrossCurrencyIsIdempotentAndReversible(): void
    {
        $client = $this->client('AVYX idem');
        $inv = $this->saleInvoice('FV-CC-IDEM', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4420.50, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->invoicePayment($inv, $tx, 114345.00);

        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        self::assertSame(1, $this->entryCountForTx($tx), 'Idempotence: na pohyb je právě jeden zápis (in-place přepis).');

        $this->service->unpost($this->supplierId, $tx, $this->meta());
        self::assertSame(0, $this->entryIdForBankTx($tx), 'Po rozpárování není živý zápis.');
    }

    // (i6) — nejistá křížová měna (hrubá odchylka kurzu = nejspíš špatně spárovaný doklad) →
    //        blokovaný návrh k ručnímu ověření, NE automatické zaúčtování.
    public function testCrossCurrencyGrossRateMismatchRoutesToReview(): void
    {
        $client = $this->client('AVYX mismatch');
        $inv = $this->saleInvoice('FV-CC-BAD', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        // 2000 EUR (≈50 630 CZK) proti evidované úhradě 114 345 CZK → odchylka ~56 % → review.
        $tx = $this->transaction($stmt, 2000.00, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->invoicePayment($inv, $tx, 114345.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('cross_currency', $res['reason'] ?? '');
        self::assertSame(0, $this->entryCountForTx($tx), 'Nejistá křížová měna se nezaúčtuje automaticky.');
        $sug = $this->suggestionRow((int) $res['suggestion_id']);
        self::assertSame('blocked', (string) $sug['status'], 'Návrh je blokovaný k ručnímu ověření.');
    }

    // (i7) — dvojí měnová konverze (EUR faktura hrazená USD) → ruční ověření (blokovaný návrh),
    //        ne tichý skip. payment/predpis jsou v různých cizích měnách — auto by dvakrát převáděl.
    public function testDoubleForeignCurrencyRoutesToReview(): void
    {
        $client = $this->client('EUR Odběratel USD');
        $inv = $this->fxSaleInvoice('FV-EUR-USD', $client, 1000.00, 25.00); // EUR faktura
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1100.00, ['match_status' => 'manual', 'currency' => 'USD', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('cross_currency', $res['reason'] ?? '');
        self::assertSame(0, $this->entryCountForTx($tx), 'Dvojí konverze se nezaúčtuje automaticky.');
    }

    // Ruční rozúčtování cizoměnové úhrady korunové faktury (postManual) zůstává funkční jako
    // override i po zavedení automatiky — invariant „221 = CZK ekvivalent výpisu" platí dál.
    public function testManualLinesPostForeignPaymentOfCzkInvoiceStillWorks(): void
    {
        $client = $this->client('AVYX manual');
        $inv = $this->saleInvoice('FV-AVYX-MAN', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4420.50, ['match_status' => 'unmatched', 'currency' => 'EUR']);

        $bankCzk = round(4420.50 * 25.315, 2); // 111 904,96
        $res = $this->service->postManual($this->supplierId, $tx, [
            'lines' => [
                ['account_code' => '221', 'side' => 'debit',  'amount' => $bankCzk],
                ['account_code' => '563', 'side' => 'debit',  'amount' => round(114345.00 - $bankCzk, 2)],
                ['account_code' => '311', 'side' => 'credit', 'amount' => 114345.00],
            ],
        ], $this->meta());

        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(111904.96, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(114345.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(2440.04, $byAcc['563']['debit'], 0.001);
        $this->assertBalanced((int) $res['entry_id']);
    }

    // Invariant multi-line u cizí měny: 221 se poměřuje s CZK ekvivalentem výpisu,
    // ne s cizoměnovým nominálem (jinak by šlo zaúčtovat 4 420,50 Kč místo 111 904,96 Kč).
    public function testManualLinesForeignBankLegMustMatchCzkEquivalent(): void
    {
        $client = $this->client('AVYX DISTRIBUTION');
        $inv = $this->saleInvoice('FV-AVYX-2', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4420.50, [
            'match_status' => 'manual', 'currency' => 'EUR', 'matched_invoice_id' => $inv,
        ]);

        $this->expectException(\MyInvoice\Service\Accounting\PostingException::class);
        $this->service->postManual($this->supplierId, $tx, [
            'lines' => [
                ['account_code' => '221', 'side' => 'debit',  'amount' => 4420.50], // nominál, ne CZK
                ['account_code' => '311', 'side' => 'credit', 'amount' => 4420.50],
            ],
        ], $this->meta());
    }

    // Blacklist saldokont drží tam, kam H2 míří — na pravidla. Z rozúčtování (které
    // saldokonta smí) nesmí jít pravidlo založit, jinak by šel blacklist obejít.
    public function testManualLinesCannotCreateRule(): void
    {
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 100.00, ['match_status' => 'unmatched', 'currency' => 'EUR']);

        $this->expectException(\MyInvoice\Service\Accounting\PostingException::class);
        $this->service->postManual($this->supplierId, $tx, [
            'lines' => [
                ['account_code' => '221', 'side' => 'debit',  'amount' => 2531.50],
                ['account_code' => '311', 'side' => 'credit', 'amount' => 2531.50],
            ],
            'create_rule' => [
                'direction' => 'incoming', 'variable_symbol' => '2409012',
                'debit_account_code' => '221', 'credit_account_code' => '311',
            ],
        ], $this->meta());
    }

    // #59 — rozúčtování zadané v měně pohybu. Jednotlivě zaokrouhlené řádky dávají
    // 35 352,87 Kč, banka 35 352,86 Kč; haléř se musí vyrovnat na největším řádku.
    public function testManualLinesInForeignCurrencyAreConvertedAndBalanced(): void
    {
        $this->seedRate('EUR', self::YEAR . '-06-15', 24.25);
        $tx = $this->transaction($this->statement(), 1457.85, ['match_status' => 'unmatched', 'currency' => 'EUR']);

        $res = $this->service->postManual($this->supplierId, $tx, [
            'amounts_in_foreign' => true,
            'lines' => [
                ['account_code' => '221', 'side' => 'debit',  'amount' => 1457.85],
                ['account_code' => '644', 'side' => 'credit', 'amount' => 1000.12],
                ['account_code' => '644', 'side' => 'credit', 'amount' => 180.98],
                ['account_code' => '648', 'side' => 'credit', 'amount' => 120.00],
                ['account_code' => '648', 'side' => 'credit', 'amount' => 156.75],
            ],
        ], $this->meta());

        $rows = $this->entryLines((int) $res['entry_id']);
        $bank = array_values(array_filter($rows, static fn (array $r): bool => str_starts_with($r['account_code'], '221')));
        $counter = array_values(array_filter($rows, static fn (array $r): bool => !str_starts_with($r['account_code'], '221')));

        self::assertCount(1, $bank);
        self::assertEqualsWithDelta(35352.86, (float) $bank[0]['amount'], 0.001);
        self::assertSame('EUR', $bank[0]['currency_code']);
        self::assertEqualsWithDelta(1457.85, (float) $bank[0]['amount_foreign'], 0.001);
        self::assertEqualsCanonicalizing(
            [24252.90, 4388.77, 2910.00, 3801.19],
            array_map(static fn (array $r): float => round((float) $r['amount'], 2), $counter),
        );
        foreach ($counter as $r) {
            self::assertNull($r['amount_foreign'], 'Protiúčty zůstávají korunové.');
        }
        $this->assertBalanced((int) $res['entry_id']);
    }

    // Saldokonto se odúčtovává kurzem předpisu — v režimu částek v cizí měně ho nepustíme.
    public function testManualLinesInForeignCurrencyRejectSaldo(): void
    {
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);
        $tx = $this->transaction($this->statement(), 100.00, ['match_status' => 'unmatched', 'currency' => 'EUR']);

        $this->expectException(\MyInvoice\Service\Accounting\PostingException::class);
        $this->expectExceptionMessage('saldokonto rozúčtuj v korunách');
        $this->service->postManual($this->supplierId, $tx, [
            'amounts_in_foreign' => true,
            'lines' => [
                ['account_code' => '221', 'side' => 'debit',  'amount' => 100.00],
                ['account_code' => '311', 'side' => 'credit', 'amount' => 100.00],
            ],
        ], $this->meta());
    }

    /** @return list<array{account_code:string, side:string, amount:string, currency_code:?string, amount_foreign:?string}> */
    private function entryLines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount, l.currency_code, l.amount_foreign
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ?
              ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$entryId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Kurz pro UI musí být týž, jakým se pohyb zaúčtuje — jinak by fronta předvyplnila
    // jinou částku, než jakou zápis dostane.
    public function testCzkRateForMatchesPostingRate(): void
    {
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);
        self::assertSame(1.0, $this->service->czkRateFor($this->supplierId, 'CZK', self::YEAR . '-06-15'));
        self::assertEqualsWithDelta(
            25.315,
            (float) $this->service->czkRateFor($this->supplierId, 'EUR', self::YEAR . '-06-15'),
            0.0001,
        );
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /**
     * Řádek zápisu na daném účtu. U bankovní nohy ('221') se hledá i analytika
     * vlastního účtu (221100, 221200 …) — tam noha od migrace 1318 reálně padá.
     * Musí být právě jedna, jinak by nebylo jasné, o kterou banku jde.
     *
     * @return array<string,mixed>
     */
    private function lineForAccount(int $entryId, string $code): array
    {
        $lines = $this->journal->find($entryId, $this->supplierId)['lines'];
        $matches = [];
        foreach ($lines as $l) {
            $lineCode = $this->accountCode((int) $l['account_id']);
            if ($lineCode === $code
                || ($code === '221' && str_starts_with($lineCode, '221'))
            ) {
                $matches[] = $l;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        self::fail(count($matches) > 1
            ? 'Účet ' . $code . ' je v zápisu víckrát — nelze rozhodnout, který řádek je bankovní noha.'
            : 'Řádek s účtem ' . $code . ' v zápisu není.');
    }

    private function fxIncoming(int $invoiceId, float $foreignAmount): int
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, $foreignAmount, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->fxInvoicePayment($invoiceId, $tx, $foreignAmount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'FX úhrada FV se má zaúčtovat: ' . ($res['reason'] ?? ''));
        return $tx;
    }

    private function fxOutgoing(int $pfId, float $foreignAmount): int
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -$foreignAmount, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->paymentMatch($tx, $pfId, $foreignAmount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'FX úhrada PF se má zaúčtovat: ' . ($res['reason'] ?? ''));
        return $tx;
    }

    /** Vydaná faktura v EUR + zaúčtovaný předpis 311/602 s FX stopou na saldokontu (guard H1). */
    private function fxSaleInvoice(string $vs, int $clientId, float $foreignTotal, float $rate, string $receivable = '311'): int
    {
        $issue = self::YEAR . '-06-10';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        )->execute([
            $this->supplierId, $vs, $clientId, $issue, $issue, $issue, $this->eurId, $rate,
            $foreignTotal, $foreignTotal, $this->userId,
        ]);
        $invId = (int) $this->db->pdo()->lastInsertId();

        $czk = round($foreignTotal * $rate, 2);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => $issue,
            'document_no' => 'PREDPIS-' . $vs,
            'description' => 'Předpis FV EUR',
            'source_type' => 'invoice',
            'source_id'   => $invId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map[$receivable]['id'], 'side' => 'debit', 'amount' => $czk,
             'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $foreignTotal],
            ['account_id' => $map['602']['id'], 'side' => 'credit', 'amount' => $czk],
        ]);
        return $invId;
    }

    /** Přijatá faktura v EUR + zaúčtovaný předpis 518/321 s FX stopou na saldokontu (guard H1). */
    private function fxPurchaseInvoice(string $number, int $vendorId, float $foreignTotal, float $rate, string $payable = '321'): int
    {
        $issue = self::YEAR . '-06-10';
        $snapshot = json_encode(['company_name' => 'EUR Dodavatel'], JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, "invoice", "full", ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, "booked", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $snapshot, $issue, $issue, $issue, $issue, $this->eurId, $rate,
            $foreignTotal, $foreignTotal, $this->userId,
        ]);
        $pfId = (int) $this->db->pdo()->lastInsertId();

        $czk = round($foreignTotal * $rate, 2);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => $issue,
            'document_no' => 'PREDPIS-' . $number,
            'description' => 'Předpis PF EUR',
            'source_type' => 'purchase_invoice',
            'source_id'   => $pfId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['518']['id'], 'side' => 'debit', 'amount' => $czk],
            ['account_id' => $map[$payable]['id'], 'side' => 'credit', 'amount' => $czk,
             'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $foreignTotal],
        ]);
        return $pfId;
    }

    private function fxInvoicePayment(int $invoiceId, int $txId, float $foreignAmount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, ?, "EUR", "bank", ?)'
        )->execute([$this->supplierId, $invoiceId, self::YEAR . '-06-15', $foreignAmount, $txId]);
    }

    private function seedRate(string $currency, string $date, float $rate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$date, $currency, $rate]);
    }

    private function ensureEur(): int
    {
        $id = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code='EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        return $id !== 0 ? $id : $this->currencyRow($this->supplierId, 'EUR');
    }

    private function entryIdForBankTx(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id={$this->supplierId}
              AND source_type='bank' AND source_id={$txId} AND reversed_by IS NULL LIMIT 1"
        )->fetchColumn();
    }

    /** @param list<int> $purchaseIds */
    private function purchaseLiabilityBalance(array $purchaseIds, int $txId): float
    {
        $placeholders = implode(',', array_fill(0, count($purchaseIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE e.supplier_id = ? AND e.reversed_by IS NULL AND a.account_code = '321'
                AND ((e.source_type = 'purchase_invoice' AND e.source_id IN ({$placeholders}))
                     OR (e.source_type = 'bank' AND e.source_id = ?))"
        );
        $stmt->execute([$this->supplierId, ...$purchaseIds, $txId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function assertBalanced(int $entryId): void
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        $debit = 0;
        $credit = 0;
        foreach ($entry['lines'] as $l) {
            $cents = (int) round((float) $l['amount'] * 100);
            $l['side'] === 'debit' ? $debit += $cents : $credit += $cents;
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
    }
}
