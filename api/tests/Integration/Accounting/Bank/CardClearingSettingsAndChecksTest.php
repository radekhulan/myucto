<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Repository\CardClearingSettingsRepository;
use MyInvoice\Service\Accounting\Card\CardClearingOverview;
use MyInvoice\Service\Accounting\Card\CardClearingRegime;
use MyInvoice\Service\Accounting\Card\CardClearingSettingsService;
use MyInvoice\Service\Accounting\Card\CardClearingWriteOffService;
use MyInvoice\Service\Accounting\Closing\CheckFindingNormalizer;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Reports\CashFlowStatementService;
use MyInvoice\Service\Bank\Card\CardPaymentOverview;
use MyInvoice\Service\Bank\Match\PaymentMatchAuditChecker;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Nastavení účtování karet, uzavření platby bez dokladu a kontroly mezičlenu
 * (předuzávěrková, měsíční, průběžné účty, peněžní toky, audit párování).
 *
 * Izolace: rok 2099, sdílená transakce BankPostingTestCase.
 */
#[Group('integration')]
final class CardClearingSettingsAndChecksTest extends BankPostingTestCase
{
    private const DAY = '2099-06-15';

    private CardClearingSettingsService $settingsService;
    private int $cardSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsService = $this->container->get(CardClearingSettingsService::class);
    }

    // ── nastavení ─────────────────────────────────────────────────────────────

    public function testSettingsAreSavedAndLoaded(): void
    {
        $writeoff = $this->accountId('548.990');
        $holder = $this->accountId('335');

        $this->settingsService->saveSettings($this->supplierId, [
            'enabled' => true, 'effective_from' => '2099-02-01', 'clearing_synthetic' => '378',
            'writeoff_account_id' => $writeoff, 'holder_account_id' => $holder,
            'auto_create_cards' => false, 'unmatched_alert_days' => 14,
        ], $this->userId);

        $loaded = $this->settingsService->settings($this->supplierId)['settings'];
        self::assertTrue($loaded['enabled']);
        self::assertSame('2099-02-01', $loaded['effective_from']);
        self::assertSame('378', $loaded['clearing_synthetic']);
        self::assertSame($writeoff, $loaded['writeoff_account_id']);
        self::assertSame('548.990', $loaded['writeoff_account_code']);
        self::assertSame($holder, $loaded['holder_account_id']);
        self::assertFalse($loaded['auto_create_cards']);
        self::assertSame(14, $loaded['unmatched_alert_days']);
    }

    public function testEnablingWithoutDateUsesStartOfFirstOpenPeriod(): void
    {
        $this->settingsService->saveSettings($this->supplierId, ['enabled' => true], $this->userId);

        $expected = $this->settingsService->defaultEffectiveFrom($this->supplierId);
        self::assertNotNull($expected);
        self::assertSame($expected, $this->settingsService->settings($this->supplierId)['settings']['effective_from']);
    }

    public function testSaldoAccount325IsRejected(): void
    {
        try {
            $this->settingsService->saveSettings($this->supplierId, ['enabled' => true, 'clearing_synthetic' => '325'], $this->userId);
            self::fail('325 je saldokonto — jako mezičlen karet se nesmí uložit.');
        } catch (PostingException $e) {
            self::assertSame('invalid_synthetic', $e->errorCode);
        }
        self::assertFalse($this->container->get(CardClearingSettingsRepository::class)->find($this->supplierId)['configured']
            && $this->container->get(CardClearingSettingsRepository::class)->find($this->supplierId)['clearing_synthetic'] === '325');
    }

    public function testAccountOfOtherSupplierIsRejected(): void
    {
        $other = $this->cloneSupplier('double_entry');
        $foreignId = $this->accounts->insert($other, [
            'account_code' => '548', 'name' => 'Cizí 548', 'account_type' => 'expense',
            'normal_side' => 'debit', 'is_synthetic' => true, 'parent_id' => null, 'is_active' => true,
        ]);

        try {
            $this->settingsService->saveSettings($this->supplierId, ['enabled' => true, 'writeoff_account_id' => $foreignId], $this->userId);
            self::fail('Účet cizí firmy se nesmí uložit.');
        } catch (PostingException $e) {
            self::assertSame('invalid_account', $e->errorCode);
        }
    }

    public function testAccountOfWrongClassIsRejected(): void
    {
        try {
            $this->settingsService->saveSettings($this->supplierId, [
                'enabled' => true, 'writeoff_account_id' => $this->accountId('335'),
            ], $this->userId);
            self::fail('Pohledávka za zaměstnancem není nákladový účet.');
        } catch (PostingException $e) {
            self::assertSame('invalid_account', $e->errorCode);
        }
    }

    public function testSettingsAreTenantScoped(): void
    {
        $other = $this->cloneSupplier('double_entry');
        $this->settingsService->saveSettings($this->supplierId, ['enabled' => true, 'effective_from' => '2099-01-01'], $this->userId);

        $repo = $this->container->get(CardClearingSettingsRepository::class);
        self::assertFalse($repo->find($other)['enabled'], 'Nastavení jedné firmy se druhé nesmí projevit.');
        self::assertTrue($repo->find($this->supplierId)['enabled']);
    }

    /** Změna syntetiky se zůstatkem chce potvrzení a nic nepřeúčtuje — platí jen pro nové platby. */
    public function testSyntheticChangeNeedsConfirmAndDoesNotRepost(): void
    {
        $this->enable('2099-01-01');
        $card = $this->card('4321');
        $first = $this->cardTx(-500.00, '4321');
        $this->service->handleTransaction($first, $this->userId);
        $before = $this->fingerprint();

        try {
            $this->settingsService->saveSettings($this->supplierId, ['enabled' => true, 'clearing_synthetic' => '261'], $this->userId);
            self::fail('Změna syntetiky se zůstatkem musí chtít potvrzení.');
        } catch (PostingException $e) {
            self::assertSame('confirm_required', $e->errorCode);
            self::assertEqualsWithDelta(500.00, $e->context['balance'], 0.001);
        }
        $this->settingsService->saveSettings($this->supplierId, ['enabled' => true, 'clearing_synthetic' => '261', 'confirm' => true], $this->userId);
        $this->forget();

        $this->service->handleTransaction($first, $this->userId);
        self::assertSame($before, $this->fingerprint(), 'Zaúčtovaná platba zůstává na staré analytice.');
        $second = $this->cardTx(-100.00, '4321');
        $res = $this->service->handleTransaction($second, $this->userId);
        $suffix = $this->db->pdo()->query("SELECT analytic_suffix FROM payment_cards WHERE id = {$card}")->fetchColumn();
        self::assertArrayHasKey('261.' . $suffix, $this->linesByAccountCode((int) $res['entry_id']));
    }

    public function testCardAnalyticCanBeChosenManually(): void
    {
        $this->enable('2099-01-01');
        $card = $this->card('4321');
        $this->chartAccount('378.150', 'Karta ruční analytika');

        $this->settingsService->setCardAnalytic($this->supplierId, $card, '378.150', false);
        $tx = $this->cardTx(-90.00, '4321');
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertArrayHasKey('378.150', $this->linesByAccountCode((int) $res['entry_id']));
        try {
            $this->chartAccount('378.160', 'Jiná analytika');
            $this->settingsService->setCardAnalytic($this->supplierId, $card, '378.160', false);
            self::fail('Změna analytiky se zůstatkem musí chtít potvrzení.');
        } catch (PostingException $e) {
            self::assertSame('confirm_required', $e->errorCode);
        }
    }

    public function testAnalyticOfOtherCardCannotBeTaken(): void
    {
        $this->enable('2099-01-01');
        $a = $this->card('1111');
        $b = $this->card('2222');
        $this->service->handleTransaction($this->cardTx(-10.00, '1111'), $this->userId);
        $suffixA = $this->db->pdo()->query("SELECT analytic_suffix FROM payment_cards WHERE id = {$a}")->fetchColumn();

        try {
            $this->settingsService->setCardAnalytic($this->supplierId, $b, '378.' . $suffixA, true);
            self::fail('Analytiku jiné karty nelze převzít.');
        } catch (PostingException $e) {
            self::assertSame('analytic_taken', $e->errorCode);
        }
    }

    // ── uzavření bez dokladu ──────────────────────────────────────────────────

    public function testWriteOffAsNonDeductibleExpense(): void
    {
        $this->enable('2099-01-01');
        $card = $this->card('4321');
        $tx = $this->cardTx(-300.00, '4321');
        $this->service->handleTransaction($tx, $this->userId);

        $res = $this->container->get(CardClearingWriteOffService::class)->writeOff($this->supplierId, $tx, 'expense', null, $this->userId);

        self::assertSame('548.990', $res['account_code'], 'Výchozí je nedaňová analytika 548.');
        $lines = $this->linesByAccountCode($res['entry_id']);
        $code = $this->cardCode($card);
        self::assertEqualsWithDelta(300.00, $lines['548.990']['debit'], 0.001);
        self::assertEqualsWithDelta(300.00, $lines[$code]['credit'], 0.001);
        self::assertEqualsWithDelta(0.00, $this->balance($code), 0.001);
        $overview = $this->container->get(CardPaymentOverview::class)->unmatched($this->supplierId, '2099-01-01', '2099-12-31');
        self::assertNotContains($tx, $this->overviewTxIds($overview), 'Uzavřená platba už doklad nečeká.');
    }

    public function testWriteOffToCardHolder(): void
    {
        $this->enable('2099-01-01');
        $this->card('4321');
        $tx = $this->cardTx(-300.00, '4321');
        $this->service->handleTransaction($tx, $this->userId);

        $res = $this->container->get(CardClearingWriteOffService::class)
            ->writeOff($this->supplierId, $tx, 'holder', $this->accountId('335'), $this->userId);

        self::assertSame('335', $res['account_code']);
        self::assertEqualsWithDelta(300.00, $this->linesByAccountCode($res['entry_id'])['335']['debit'], 0.001);
    }

    public function testDocumentArrivingAfterWriteOffReplacesIt(): void
    {
        $this->enable('2099-01-01');
        $card = $this->card('4321');
        $tx = $this->cardTx(-300.00, '4321');
        $this->service->handleTransaction($tx, $this->userId);
        $this->container->get(CardClearingWriteOffService::class)->writeOff($this->supplierId, $tx, 'expense', null, $this->userId);
        $pi = $this->postedPurchase(300.00);
        $this->match($tx, $pi, 300.00);

        $this->service->handleTransaction($tx, $this->userId);

        $writeoff = $this->journal->findBySource($this->supplierId, 'card_writeoff', $tx);
        self::assertTrue($writeoff === null || $writeoff['reversed_by'] !== null, 'Uzavření musí ustoupit vypořádání.');
        self::assertEqualsWithDelta(0.00, $this->balance($this->cardCode($card)), 0.001);
        self::assertEqualsWithDelta(0.00, $this->balance('548'), 0.001, 'Náklad z uzavření se vrátil.');
    }

    // ── kontroly ──────────────────────────────────────────────────────────────

    public function testClosingCheckExplainsBalanceByOpenPayments(): void
    {
        $this->enable('2099-01-01');
        $card = $this->card('4321');
        $tx = $this->cardTx(-500.00, '4321');
        $this->service->handleTransaction($tx, $this->userId);
        $overview = $this->container->get(CardClearingOverview::class);

        $check = $overview->closingCheck($this->supplierId, '2099-12-31');
        self::assertFalse($check['ok']);
        $row = $check['accounts'][0];
        self::assertSame($this->cardCode($card), $row['account_code']);
        self::assertEqualsWithDelta(500.00, $row['balance'], 0.001);
        self::assertEqualsWithDelta(500.00, $row['explained'], 0.001);
        self::assertEqualsWithDelta(0.00, $row['unexplained'], 0.001);
        self::assertSame($tx, $row['items'][0]['tx_id']);

        $pi = $this->postedPurchase(500.00);
        $this->match($tx, $pi, 500.00);
        $this->service->handleTransaction($tx, $this->userId);
        self::assertTrue($overview->closingCheck($this->supplierId, '2099-12-31')['ok']);
    }

    public function testClosingCheckReportsUnexplainedManualEntry(): void
    {
        $this->enable('2099-01-01');
        $card = $this->card('4321');
        $this->service->handleTransaction($this->cardTx(-500.00, '4321'), $this->userId);
        $code = $this->cardCode($card);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $code, 'side' => 'debit', 'amount' => 40.0],
            ['account_code' => '395', 'side' => 'credit', 'amount' => 40.0],
        ], ['entry_date' => self::DAY, 'description' => 'Ruční zápis na kartu']);

        $row = $this->container->get(CardClearingOverview::class)->closingCheck($this->supplierId, '2099-12-31')['accounts'][0];

        self::assertEqualsWithDelta(540.00, $row['balance'], 0.001);
        self::assertEqualsWithDelta(40.00, $row['unexplained'], 0.001);
    }

    /** Karta pod 261: zůstatek hlídá jen card_clearing_open, ne peníze na cestě ani průběžné účty. */
    public function testCardAnalyticsUnder261AreNotReportedTwice(): void
    {
        $this->enable('2099-01-01', '261');
        $this->card('4321');
        $this->service->handleTransaction($this->cardTx(-500.00, '4321'), $this->userId);

        $checks = $this->checks(['transit_261_open', 'clearing_accounts_open', 'card_clearing_open']);

        self::assertTrue($checks['transit_261_open']['ok'], 'Peníze na cestě nesmí hlásit platbu kartou.');
        self::assertTrue($checks['clearing_accounts_open']['ok'], 'Průběžné účty nesmí hlásit analytiku karty.');
        self::assertFalse($checks['card_clearing_open']['ok']);
    }

    public function testMonthlyCheckListsUnmatchedCardPaymentsOlderThanSetting(): void
    {
        $this->enable('2099-01-01');
        $this->db->pdo()->exec("UPDATE card_clearing_settings SET unmatched_alert_days = 10 WHERE supplier_id = {$this->supplierId}");
        $this->card('4321');
        $tx = $this->cardTx(-500.00, '4321');
        $overview = $this->container->get(CardClearingOverview::class);

        self::assertSame(0, $overview->unmatchedOlderThan($this->supplierId, '2099-06-20')['count']);
        $late = $overview->unmatchedOlderThan($this->supplierId, '2099-06-30');
        self::assertSame(1, $late['count']);
        self::assertSame($tx, $late['items'][0]['tx_id']);

        $checks = $this->checks(['card_payments_unmatched'], '2099-06-30');
        self::assertFalse($checks['card_payments_unmatched']['ok']);
    }

    /**
     * RED bez vyloučení analytik karet: s mezičlenem pod 261 se platba bez dokladu brala
     * jako přesun mezi penězi (221 → 261) a z výkazu peněžních toků zmizela úplně.
     */
    public function testCashFlowShowsCardPaymentAsOperatingOutflowOnPaymentDay(): void
    {
        $this->enable('2099-01-01', '261');
        $this->card('4321');
        $tx = $this->cardTx(-500.00, '4321');
        $this->service->handleTransaction($tx, $this->userId);

        $cf = $this->container->get(CashFlowStatementService::class)->build($this->supplierId, $this->periodId);

        self::assertTrue($cf['reconciles']);
        self::assertEqualsWithDelta(-500.00, $cf['net_change'], 0.001);
        self::assertEqualsWithDelta(-500.00, $cf['operating'], 0.001, 'Platba kartou je provozní výdaj v den platby, i s mezičlenem pod 261.');
    }

    public function testPaymentMatchAuditCountsExchangeDifferenceFromSettlement(): void
    {
        $this->enable('2099-01-01');
        $this->card('4321');
        $eur = $this->currencyRow($this->supplierId, 'EUR');
        $pi = $this->postedPurchase(500.00, $eur, 25.0, 20.00);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid' WHERE id = ?")->execute([$pi]);
        $tx = $this->cardTx(-520.00, '4321');
        $this->match($tx, $pi, 520.00);
        $this->service->handleTransaction($tx, $this->userId);

        $items = $this->container->get(PaymentMatchAuditChecker::class)->audit($this->supplierId, '2099-01-01', '2099-12-31');

        foreach ($items as $item) {
            if ($item['doc_id'] === $pi) {
                self::assertNotContains('amount_mismatch', $item['issues'], 'Kurzový rozdíl je zaúčtovaný ve vypořádání karty.');
            }
        }
        self::assertTrue(true);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function enable(string $from, string $synthetic = '378'): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic)
             VALUES (?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = 1, effective_from = VALUES(effective_from), clearing_synthetic = VALUES(clearing_synthetic)'
        )->execute([$this->supplierId, $from, $synthetic]);
        $this->forget();
    }

    private function forget(): void
    {
        $this->container->get(CardClearingRegime::class)->forget($this->supplierId);
    }

    /** @param list<string> $keys @return array<string,array<string,mixed>> */
    private function checks(array $keys, string $to = '2099-12-31'): array
    {
        $period = $this->periods->findById($this->supplierId, $this->periodId);
        $out = [];
        foreach ($this->container->get(ClosingService::class)->buildChecks(
            $this->supplierId, $period, '2099-01-01', $to, CheckFindingNormalizer::CAP, $keys,
        ) as $check) {
            $out[$check['key']] = $check;
        }
        return $out;
    }

    private function accountId(string $code): int
    {
        $row = $this->accounts->findByCode($this->supplierId, $code);
        self::assertNotNull($row, 'Chybí účet ' . $code);
        return (int) $row['id'];
    }

    private function chartAccount(string $code, string $name): void
    {
        $parent = $this->accounts->findByCode($this->supplierId, substr($code, 0, 3));
        $this->accounts->insert($this->supplierId, [
            'account_code' => $code, 'name' => $name, 'account_type' => 'asset', 'normal_side' => 'debit',
            'is_synthetic' => false, 'parent_id' => $parent !== null ? (int) $parent['id'] : null, 'is_active' => true,
        ]);
    }

    private function card(string $last4): int
    {
        $this->db->pdo()->prepare('INSERT INTO payment_cards (supplier_id, label, last4) VALUES (?, ?, ?)')
            ->execute([$this->supplierId, 'Karta ' . (++$this->cardSeq), $last4]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function cardCode(int $cardId): string
    {
        $row = $this->db->pdo()->query(
            "SELECT pc.analytic_suffix, COALESCE(s.clearing_synthetic, '378') AS syn
               FROM payment_cards pc LEFT JOIN card_clearing_settings s ON s.supplier_id = pc.supplier_id
              WHERE pc.id = {$cardId}"
        )->fetch(PDO::FETCH_ASSOC);
        return $row['syn'] . '.' . $row['analytic_suffix'];
    }

    private function cardTx(float $amount, string $last4, string $date = self::DAY): int
    {
        $tx = $this->transaction($this->statement(), $amount, [
            'posted_at' => $date, 'counterparty_name' => 'OBCHOD TEST', 'description' => 'PK: 000000******' . $last4,
        ]);
        $this->db->pdo()->prepare('UPDATE bank_transactions SET card_last4 = ? WHERE id = ?')->execute([$last4, $tx]);
        return $tx;
    }

    private function postedPurchase(float $total, ?int $currencyId = null, ?float $rate = null, ?float $foreignTotal = null): int
    {
        $vendor = $this->client('Dodavatel karta ' . uniqid());
        $pi = $this->purchaseInvoice('PF-CARD-' . uniqid(), $vendor, $foreignTotal ?? $total);
        if ($currencyId !== null) {
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET currency_id = ?, exchange_rate = ? WHERE id = ?')
                ->execute([$currencyId, $rate, $pi]);
        }
        $this->postPredpis('purchase_invoice', $pi, '518', '321', $total);
        return $pi;
    }

    private function match(int $txId, int $purchaseId, float $amount): void
    {
        $this->paymentMatch($txId, $purchaseId, $amount);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$txId]);
    }

    private function balance(string $code): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.entry_date BETWEEN '2099-01-01' AND '2099-12-31'
                AND (a.account_code = ? OR a.account_code LIKE CONCAT(?, '.%'))"
        );
        $stmt->execute([$this->supplierId, $code, $code]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** @param array<string,mixed> $overview @return list<int> */
    private function overviewTxIds(array $overview): array
    {
        $ids = [];
        foreach ($overview['groups'] as $group) {
            foreach ($group['transactions'] as $t) {
                $ids[] = (int) $t['id'];
            }
        }
        return $ids;
    }

    private function fingerprint(): string
    {
        $rows = $this->db->pdo()->query(
            "SELECT e.id, e.source_type, e.source_id, e.reversed_by, a.account_code, l.side, l.amount
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = {$this->supplierId} AND e.entry_date BETWEEN '2099-01-01' AND '2099-12-31'
              ORDER BY e.id, l.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256', json_encode($rows));
    }
}
