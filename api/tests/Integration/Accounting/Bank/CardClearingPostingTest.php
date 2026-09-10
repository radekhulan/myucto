<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\Card\CardClearingRegime;
use MyInvoice\Service\Bank\PurchasePaymentMatchWriter;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Platby firemní kartou přes mezičlen 378 s analytikou pro každou kartu.
 *
 * Bankovní zápis platby kartou je vždy MD 378.x / D 221 — bez ohledu na to, zda k ní
 * už je doklad. Spárování s dokladem je SAMOSTATNÝ zápis vypořádání MD 321 / D 378.x
 * (kurzový rozdíl 563/663, haléře 548/648). Zrušení párování stornuje jen vypořádání.
 *
 * Izolace: rok 2099, sdílená transakce BankPostingTestCase (rollback v tearDown).
 */
#[Group('integration')]
final class CardClearingPostingTest extends BankPostingTestCase
{
    private const DAY = '2099-06-15';

    private int $cardSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCards('2099-01-01');
    }

    public function testCardPaymentWithoutDocumentPostsClearingAgainstBank(): void
    {
        $card = $this->card('4321');
        $tx = $this->cardTx(-500.00, '4321');

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $code = $this->cardCode($card);
        self::assertSame('378.101', $code, 'První karta dostane analytiku 378.101.');
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(500.00, $lines[$code]['debit'], 0.001);
        self::assertEqualsWithDelta(500.00, $lines['221']['credit'], 0.001);
        self::assertEqualsWithDelta(500.00, $this->balance($code), 0.001);
        self::assertNull($this->liveSettlement($tx));
    }

    /** RED bez režimu karet: platba kartou bez dokladu nešla přes 378, pravidlo 548/221 ji odepsalo do nákladů. */
    public function testRuleForCardPaymentsWithoutDocumentIsReplacedByClearing(): void
    {
        $this->rule([
            'direction' => 'outgoing', 'message_contains' => 'PK:', 'mode' => 'auto',
            'debit_account_code' => '548', 'credit_account_code' => '221',
            'amount_min' => 1, 'amount_max' => 100000,
        ]);
        $card = $this->card('4321');
        $tx = $this->cardTx(-120.00, '4321');

        $res = $this->service->handleTransaction($tx, $this->userId);

        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertArrayHasKey($this->cardCode($card), $lines);
        self::assertArrayNotHasKey('548', $lines);
    }

    public function testMatchedDocumentCreatesSeparateSettlement(): void
    {
        $card = $this->card('4321');
        $pi = $this->postedPurchase(500.00);
        $tx = $this->cardTx(-500.00, '4321');
        $this->match($tx, $pi, 500.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        $code = $this->cardCode($card);
        $bank = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(500.00, $bank[$code]['debit'], 0.001, 'Bankovní zápis zůstává 378.x/221 i u spárované platby.');
        self::assertArrayNotHasKey('321', $bank);

        $settlement = $this->liveSettlement($tx);
        self::assertNotNull($settlement, 'Spárování musí založit samostatné vypořádání.');
        $sl = $this->linesByAccountCode($settlement);
        self::assertEqualsWithDelta(500.00, $sl['321']['debit'], 0.001);
        self::assertEqualsWithDelta(500.00, $sl[$code]['credit'], 0.001);
        self::assertEqualsWithDelta(0.00, $this->balance($code), 0.001);
        self::assertEqualsWithDelta(0.00, $this->balance('321'), 0.001);
    }

    public function testRepeatedRunDoesNotDuplicateAnything(): void
    {
        $this->card('4321');
        $pi = $this->postedPurchase(500.00);
        $tx = $this->cardTx(-500.00, '4321');
        $this->match($tx, $pi, 500.00);

        $this->service->handleTransaction($tx, $this->userId);
        $before = $this->fingerprint();
        $this->service->handleTransaction($tx, $this->userId);
        $this->service->syncCardSettlement($this->supplierId, $tx, $this->userId);

        self::assertSame($before, $this->fingerprint());
    }

    public function testReleaseMatchReversesOnlySettlement(): void
    {
        $card = $this->card('4321');
        $pi = $this->postedPurchase(500.00);
        $tx = $this->cardTx(-500.00, '4321');
        $this->match($tx, $pi, 500.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        $this->service->releaseMatch($this->supplierId, $tx, ['user_id' => $this->userId, 'reason' => 'unmatch']);

        self::assertNull($this->liveSettlement($tx));
        $bank = $this->journal->findBySource($this->supplierId, 'bank', $tx);
        self::assertSame((int) $res['entry_id'], (int) $bank['id']);
        self::assertNull($bank['reversed_by'], 'Bankovní zápis platby kartou po zrušení párování zůstává.');
        self::assertEqualsWithDelta(500.00, $this->balance($this->cardCode($card)), 0.001, 'Zůstatek 378.x se vrátí.');
        self::assertEqualsWithDelta(-500.00, $this->balance('321'), 0.001, 'Doklad je znovu neuhrazený.');
    }

    public function testCzkCardPaymentOfForeignDocumentBooksExchangeDifference(): void
    {
        $card = $this->card('4321');
        $eur = $this->currencyRow($this->supplierId, 'EUR');
        $pi = $this->postedPurchase(500.00, $eur, 25.0, 20.00);
        $tx = $this->cardTx(-520.00, '4321');
        $this->match($tx, $pi, 520.00);

        $this->service->handleTransaction($tx, $this->userId);

        $code = $this->cardCode($card);
        $sl = $this->linesByAccountCode((int) $this->liveSettlement($tx));
        self::assertEqualsWithDelta(500.00, $sl['321']['debit'], 0.001, '321 kurzem předpisu.');
        self::assertEqualsWithDelta(520.00, $sl[$code]['credit'], 0.001, '378.x částkou z banky.');
        self::assertEqualsWithDelta(20.00, $sl['563']['debit'], 0.001, 'Kurzová ztráta.');
        self::assertEqualsWithDelta(0.00, $this->balance($code), 0.001);
    }

    public function testRoundingDifferenceGoesTo548(): void
    {
        $card = $this->card('4321');
        $pi = $this->postedPurchase(500.40);
        $tx = $this->cardTx(-500.00, '4321');
        $this->match($tx, $pi, 500.00);

        $this->service->handleTransaction($tx, $this->userId);

        $sl = $this->linesByAccountCode((int) $this->liveSettlement($tx));
        self::assertEqualsWithDelta(500.40, $sl['321']['debit'], 0.001);
        self::assertEqualsWithDelta(500.00, $sl[$this->cardCode($card)]['credit'], 0.001);
        self::assertEqualsWithDelta(0.40, $sl['648']['credit'] ?? 0.0, 0.001, 'Doklad vyšší než platba → 648.');
    }

    public function testRefundToCardClearsAgainstCreditNote(): void
    {
        $card = $this->card('4321');
        $vendor = $this->client('Dodavatel vratka');
        $cn = $this->purchaseInvoice('DB-CARD-' . uniqid(), $vendor, -200.00, 'credit_note');
        $this->postPredpis('purchase_invoice', $cn, '321', '518', 200.00);
        $tx = $this->cardTx(200.00, '4321');
        $this->match($tx, $cn, 200.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        $code = $this->cardCode($card);
        $bank = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(200.00, $bank['221']['debit'], 0.001);
        self::assertEqualsWithDelta(200.00, $bank[$code]['credit'], 0.001);
        $sl = $this->linesByAccountCode((int) $this->liveSettlement($tx));
        self::assertEqualsWithDelta(200.00, $sl[$code]['debit'], 0.001);
        self::assertEqualsWithDelta(200.00, $sl['321']['credit'], 0.001);
        self::assertEqualsWithDelta(0.00, $this->balance($code), 0.001);
    }

    public function testUnknownCardCreatesUnverifiedCardWithAnalytic(): void
    {
        $tx = $this->cardTx(-80.00, '9876');

        $res = $this->service->handleTransaction($tx, $this->userId);

        $row = $this->db->pdo()->query(
            "SELECT id, is_verified, analytic_suffix, label FROM payment_cards
              WHERE supplier_id = {$this->supplierId} AND last4 = '9876'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Neznámá koncovka založí kartu.');
        self::assertSame(0, (int) $row['is_verified']);
        $code = '378.' . $row['analytic_suffix'];
        self::assertSame('Karta ****9876', (string) $row['label']);
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(80.00, $lines[$code]['debit'], 0.001);
        self::assertStringContainsString('****9876', $this->accountName($code));
    }

    /**
     * RED před opravou: už výpočet návrhu (i náhled) založil neověřenou kartu a analytiku.
     * Náhledy, návrhy a kontroly nesmí zapisovat nic — karta a analytika vzniknou až
     * zaúčtováním, a to přesně ta analytika, kterou náhled ohlásil.
     */
    public function testReadOnlyPathsDoNotCreateCardButPostingDoes(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE auto_posting_policy SET level = 'suggest' WHERE supplier_id = ? AND operation_type = 'bank.payment.matched'"
        )->execute([$this->supplierId]);
        $tx = $this->cardTx(-80.00, '9876');
        $before = $this->rowCounts();

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action'], json_encode($res));
        $preview = $this->service->previewTransaction($this->supplierId, $tx);
        $suggestionPreview = $this->service->previewSuggestion($this->supplierId, (int) $res['suggestion_id']);
        $period = $this->periods->findById($this->supplierId, $this->periodId);
        $this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class)->buildChecks($this->supplierId, $period);
        $this->container->get(\MyInvoice\Service\Accounting\Reports\CashFlowStatementService::class)->build($this->supplierId, $this->periodId);
        $this->container->get(\MyInvoice\Service\Bank\Card\CardPaymentOverview::class)->unmatched($this->supplierId, '2099-01-01', '2099-12-31');

        self::assertSame($before, $this->rowCounts(), 'Náhled, návrh ani kontroly nesmí založit kartu ani analytiku.');
        self::assertSame('new_card', $preview['card_clearing']['pending'] ?? null);
        $planned = (string) $preview['card_clearing']['code'];
        self::assertContains($planned, array_column($suggestionPreview['lines'], 'account_code'));

        $entryId = $this->service->approveSuggestion($this->supplierId, (int) $res['suggestion_id'], $this->meta());

        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_cards WHERE supplier_id = {$this->supplierId} AND last4 = '9876' AND is_verified = 0"
        )->fetchColumn(), 'Zaúčtování kartu založí.');
        self::assertSame($planned, '378.' . $this->db->pdo()->query(
            "SELECT analytic_suffix FROM payment_cards WHERE supplier_id = {$this->supplierId} AND last4 = '9876'"
        )->fetchColumn());
        self::assertEqualsWithDelta(80.00, $this->linesByAccountCode($entryId)[$planned]['debit'], 0.001);
    }

    public function testUnknownCardGoesToFallbackWhenAutoCreateIsOff(): void
    {
        $this->db->pdo()->exec("UPDATE card_clearing_settings SET auto_create_cards = 0 WHERE supplier_id = {$this->supplierId}");
        $this->forgetSettings();
        $tx = $this->cardTx(-80.00, '9876');

        $res = $this->service->handleTransaction($tx, $this->userId);

        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertArrayHasKey('378.199', $lines, 'Bez zakládání karet jde platba na záchrannou analytiku.');
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_cards WHERE supplier_id = {$this->supplierId} AND last4 = '9876'"
        )->fetchColumn());
    }

    public function testTwoCardsWithSameLast4InDifferentPeriodsUseOwnAnalytics(): void
    {
        $old = $this->card('5555', 'Stará karta', null, '2099-03-31');
        $new = $this->card('5555', 'Nová karta', '2099-04-01', null);
        $txOld = $this->cardTx(-100.00, '5555', '2099-02-10');
        $txNew = $this->cardTx(-200.00, '5555', '2099-05-10');

        $resOld = $this->service->handleTransaction($txOld, $this->userId);
        $resNew = $this->service->handleTransaction($txNew, $this->userId);

        $codeOld = $this->cardCode($old);
        $codeNew = $this->cardCode($new);
        self::assertNotSame($codeOld, $codeNew);
        self::assertArrayHasKey($codeOld, $this->linesByAccountCode((int) $resOld['entry_id']));
        self::assertArrayHasKey($codeNew, $this->linesByAccountCode((int) $resNew['entry_id']));
    }

    public function testPaymentBeforeEffectiveDateStaysOnPayableAgainstBank(): void
    {
        $this->enableCards('2099-07-01');
        $this->card('4321');
        $pi = $this->postedPurchase(500.00);
        $tx = $this->cardTx(-500.00, '4321');
        $this->match($tx, $pi, 500.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(500.00, $lines['321']['debit'], 0.001);
        self::assertEqualsWithDelta(500.00, $lines['221']['credit'], 0.001);
        self::assertNull($this->liveSettlement($tx));
        self::assertSame(0, $this->countLines('378'));
    }

    public function testTaxEvidenceSupplierGetsNothingFrom378(): void
    {
        $this->db->pdo()->exec("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = {$this->supplierId}");
        $this->forgetSettings();
        $tx = $this->cardTx(-500.00, '4321');

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('skipped', $res['action']);
        self::assertSame(0, $this->countLines('378'));
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_cards WHERE supplier_id = {$this->supplierId} AND last4 = '4321'"
        )->fetchColumn(), 'Daňová evidence karty nezakládá.');
    }

    public function testCardOfOtherSupplierIsNeverUsed(): void
    {
        $other = $this->cloneSupplier('double_entry');
        $this->db->pdo()->prepare(
            "INSERT INTO payment_cards (supplier_id, label, last4, analytic_suffix) VALUES (?, 'Cizí karta', '4321', '101')"
        )->execute([$other]);
        $tx = $this->cardTx(-500.00, '4321');

        $this->service->handleTransaction($tx, $this->userId);

        $own = $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_cards WHERE supplier_id = {$this->supplierId} AND last4 = '4321'"
        )->fetchColumn();
        self::assertSame(1, (int) $own, 'Cizí karta se stejnou koncovkou se nepoužije — vznikne vlastní.');
        self::assertSame('Cizí karta', (string) $this->db->pdo()->query(
            "SELECT label FROM payment_cards WHERE supplier_id = {$other}"
        )->fetchColumn());
    }

    public function testSettingsOfOtherSupplierDoNotEnableCardsHere(): void
    {
        $other = $this->cloneSupplier('double_entry');
        $this->db->pdo()->exec("UPDATE card_clearing_settings SET enabled = 0 WHERE supplier_id = {$this->supplierId}");
        $this->db->pdo()->prepare(
            "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from) VALUES (?, 1, '2099-01-01')"
        )->execute([$other]);
        $this->forgetSettings();
        $this->card('4321');
        $pi = $this->postedPurchase(500.00);
        $tx = $this->cardTx(-500.00, '4321');
        $this->match($tx, $pi, 500.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertArrayHasKey('321', $this->linesByAccountCode((int) $res['entry_id']));
    }

    public function testAutomationOffPostsNothing(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE auto_posting_policy SET level = 'off' WHERE supplier_id = ? AND operation_type = 'bank.payment.matched'"
        )->execute([$this->supplierId]);
        $this->card('4321');
        $pi = $this->postedPurchase(500.00);
        $tx = $this->cardTx(-500.00, '4321');
        $this->match($tx, $pi, 500.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('skipped', $res['action']);
        self::assertSame(0, $this->entryCountForTx($tx));
        self::assertNull($this->liveSettlement($tx));
    }

    /** Souhra s PurchasePaymentMatchWriter: opakované párování = jediná alokace = jediná noha 321. */
    public function testRepeatedMatchWriterKeepsSingleAllocationInSettlement(): void
    {
        $this->card('4321');
        $pi = $this->postedPurchase(500.00);
        $tx = $this->cardTx(-500.00, '4321');
        $pdo = $this->db->pdo();
        PurchasePaymentMatchWriter::record($pdo, $this->supplierId, $tx, $pi, 500.00, 'auto', 90);
        PurchasePaymentMatchWriter::record($pdo, $this->supplierId, $tx, $pi, 500.00, 'manual', null, $this->userId);
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$tx]);

        $this->service->handleTransaction($tx, $this->userId);

        $sl = $this->linesByAccountCode((int) $this->liveSettlement($tx));
        self::assertEqualsWithDelta(500.00, $sl['321']['debit'], 0.001, 'Dvojí párování nesmí uhradit doklad dvakrát.');
    }

    /**
     * Historie se nepřeúčtovává: pohyby zaúčtované před zapnutím režimu (321/221 i 548/221)
     * zůstanou bajtově stejné i po zapnutí s datem účinnosti PŘED nimi a opakovaném běhu.
     */
    public function testEnablingDoesNotRepostHistory(): void
    {
        $this->db->pdo()->exec("UPDATE card_clearing_settings SET enabled = 0 WHERE supplier_id = {$this->supplierId}");
        $this->forgetSettings();
        $this->card('4321');
        $pi = $this->postedPurchase(500.00);
        $matched = $this->cardTx(-500.00, '4321');
        $this->match($matched, $pi, 500.00);
        $unmatched = $this->cardTx(-77.00, '4321');
        $this->service->handleTransaction($matched, $this->userId);
        $this->service->postManual($this->supplierId, $unmatched, [
            'debit_account_code' => '548', 'credit_account_code' => '221',
        ], $this->meta());
        $legacy = $this->linesByAccountCode((int) $this->journal->findBySource($this->supplierId, 'bank', $unmatched)['id']);
        self::assertArrayHasKey('548', $legacy, 'Předpoklad: historický pohyb je zaúčtovaný pravidlem 548/221.');
        $before = $this->fingerprint();

        $this->enableCards('2099-01-01');
        // Znovu párování, přeúčtování, dávkové doúčtování i uzávěrkové kontroly.
        $matcher = $this->container->get(\MyInvoice\Service\Bank\StatementMatcher::class);
        $matcher->match($matched);
        $matcher->match($unmatched);
        $this->service->handleTransaction($matched, $this->userId);
        $this->service->handleTransaction($unmatched, $this->userId);
        $this->service->syncCardSettlement($this->supplierId, $matched, $this->userId);
        $this->backfill->run($this->supplierId, '2099-01-01', true, true, $this->userId);
        $this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class)
            ->buildChecks($this->supplierId, $this->periods->findById($this->supplierId, $this->periodId));

        self::assertSame($before, $this->fingerprint(), 'Zapnutí režimu nesmí změnit historické zápisy.');
        self::assertSame(0, $this->countLines('378'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function enableCards(string $from): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic)
             VALUES (?, 1, ?, '378')
             ON DUPLICATE KEY UPDATE enabled = 1, effective_from = VALUES(effective_from), clearing_synthetic = '378'"
        )->execute([$this->supplierId, $from]);
        $this->forgetSettings();
    }

    private function forgetSettings(): void
    {
        $this->container->get(CardClearingRegime::class)->forget($this->supplierId);
    }

    private function card(string $last4, string $label = 'Firemní karta', ?string $from = null, ?string $to = null): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payment_cards (supplier_id, label, last4, valid_from, valid_to) VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $label . ' ' . (++$this->cardSeq), $last4, $from, $to]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function cardCode(int $cardId): string
    {
        $suffix = $this->db->pdo()->query("SELECT analytic_suffix FROM payment_cards WHERE id = {$cardId}")->fetchColumn();
        self::assertNotEmpty($suffix, 'Karta nemá přidělenou analytiku.');
        return '378.' . $suffix;
    }

    private function cardTx(float $amount, string $last4, string $date = self::DAY): int
    {
        $tx = $this->transaction($this->statement(), $amount, [
            'posted_at' => $date,
            'counterparty_name' => 'OBCHOD TEST',
            'description' => 'PK: 000000******' . $last4,
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

    private function liveSettlement(int $txId): ?int
    {
        $e = $this->journal->findBySource($this->supplierId, 'card_settlement', $txId);
        return $e !== null && $e['reversed_by'] === null ? (int) $e['id'] : null;
    }

    /** Zůstatek účtu (MD − D) ze zápisů roku 2099, storna se vyruší. */
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

    /** @return array{cards:int, accounts:int} */
    private function rowCounts(): array
    {
        return [
            'cards'    => (int) $this->db->pdo()->query("SELECT COUNT(*) FROM payment_cards WHERE supplier_id = {$this->supplierId}")->fetchColumn(),
            // Jen analytiky mezičlenu karet: zpracování pohybu smí mimo jiné založit
            // analytiku bankovního účtu (221.x), která s kartou nesouvisí — na čisté
            // databázi (CI) ji test jinak započítal jako porušení.
            'accounts' => (int) $this->db->pdo()->query("SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code LIKE '378.%'")->fetchColumn(),
        ];
    }

    private function countLines(string $prefix): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.entry_date BETWEEN '2099-01-01' AND '2099-12-31'
                AND a.account_code LIKE CONCAT(?, '%')"
        );
        $stmt->execute([$this->supplierId, $prefix]);
        return (int) $stmt->fetchColumn();
    }

    private function accountName(string $code): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT name FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);
        return (string) $stmt->fetchColumn();
    }

    /** Otisk deníku roku 2099: id zápisu, zdroj, stav storna a řádky (účet, strana, částka). */
    private function fingerprint(): string
    {
        $rows = $this->db->pdo()->query(
            "SELECT e.id, e.source_type, e.source_id, e.reversed_by, e.entry_date,
                    a.account_code, l.side, l.amount, l.currency_code, l.amount_foreign
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = {$this->supplierId} AND e.entry_date BETWEEN '2099-01-01' AND '2099-12-31'
              ORDER BY e.id, l.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256', json_encode($rows));
    }
}
