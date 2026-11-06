<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Bank\CreditCardAction;
use MyInvoice\Repository\CreditCardAccountRepository;
use MyInvoice\Service\Accounting\Card\CardClearingRegime;
use MyInvoice\Service\Accounting\CreditCard\CreditCardAccounts;
use MyInvoice\Service\Accounting\CreditCard\CreditCardConversionService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\CreditCard\CreditCardOverview;
use MyInvoice\Service\Bank\CreditCard\CreditCardStatementImportService;
use MyInvoice\Service\Bank\Pdf\CreditCard\CreditCardPdfText;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Úvěrový účet kreditní karty: vlastní noha pohybů z kreditního výpisu jde na 231.x
 * (krátkodobý úvěr), ne na banku 221 - ať pohyb zaúčtuje párování, pravidlo, ruční zápis,
 * detektor nebo vlastní převod. Úrok 562, poplatek 568, splátka 231.x/261.
 *
 * Izolace: rok 2099, sdílená transakce BankPostingTestCase (rollback v tearDown).
 * Čísla účtů, obchodníci i částky jsou syntetické.
 */
#[Group('integration')]
final class CreditCardPostingTest extends BankPostingTestCase
{
    private const CC_ACCOUNT = '19-1000000005';
    private const CC_BANK = '0100';

    private CreditCardAccountRepository $cards;
    private int $ccId = 0;
    private string $ccCode = '';

    #[\PHPUnit\Framework\Attributes\DataProvider('frameworkChargeCases')]
    public function testFrameworkRolesForCreditCardCharges(bool $nonprofit, string $description, string $expected): void
    {
        $this->db->pdo()->prepare('DELETE FROM supplier_accounting_frameworks WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, ?, ?), (?, ?, ?)')
            ->execute([$this->supplierId, '2098-01-01', 'cz_500', $this->supplierId, $nonprofit ? '2099-01-01' : '2099-07-01', 'cz_504']);
        if ($this->accounts->findByCode($this->supplierId, $expected) === null) {
            $this->accounts->insert($this->supplierId, ['account_code' => $expected, 'name' => 'Synthetic charge', 'account_type' => 'expense', 'normal_side' => 'debit']);
        }
        $tx = $this->ccTx(-125.5, $description);
        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $result['action'], json_encode($result));
        $lines = $this->linesByAccountCode((int) $result['entry_id']);
        self::assertSame(125.5, (float) ($lines[$expected]['debit'] ?? 0));
        self::assertSame(125.5, (float) $lines[$this->ccCode]['credit']);
    }

    public static function frameworkChargeCases(): array
    {
        return [[false, 'Úrok', '562'], [true, 'Úrok', '544'], [false, 'Poplatek', '568'], [true, 'Poplatek', '549.100']];
    }

    public function testFrameworkExplicitCreditCardAccountRequiresBinding(): void
    {
        $this->db->pdo()->prepare('INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, ?, ?)')
            ->execute([$this->supplierId, '2099-01-01', 'cz_504']);
        $id = (int) $this->accounts->findByCode($this->supplierId, '562')['id'];
        $this->container->get(\MyInvoice\Repository\CreditCardSettingsRepository::class)->save($this->supplierId, ['interest_account_id' => $id], $this->userId);
        $this->expectException(\MyInvoice\Service\Accounting\AccountingFrameworkException::class);
        $this->expectExceptionMessage('Vlastní účet nemá ověřenou vazbu');
        $this->container->get(\MyInvoice\Service\Accounting\CreditCard\CreditCardChargeDetector::class)->detect($this->supplierId, [
            'amount' => -125.5, 'description' => 'Úrok', 'recipient_account' => self::CC_ACCOUNT,
            'recipient_bank' => self::CC_BANK, 'posted_at' => '2099-06-15',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['bank.interest', 'bank.fee', 'bank.transfer.own', 'detector.own_transfer'] as $op) {
            $this->db->pdo()->prepare(
                "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
                 VALUES (?, ?, 'auto', ?)
                 ON DUPLICATE KEY UPDATE level = 'auto'"
            )->execute([$this->supplierId, $op, $this->userId]);
        }
        // Výchozí režim nákupů je napřímo; tyhle testy ověřují zapnutý mezičlen.
        $this->container->get(\MyInvoice\Service\Accounting\CreditCard\CreditCardSettingsService::class)
            ->save($this->supplierId, ['purchase_mode' => 'clearing'], $this->userId);
        $this->cards = $this->container->get(CreditCardAccountRepository::class);
        $this->ccId = $this->cards->create($this->supplierId, [
            'issuer'         => 'kb',
            'label'          => 'Testovací kreditka',
            'account_number' => self::CC_ACCOUNT,
            'bank_code'      => self::CC_BANK,
            'currency'       => 'CZK',
            'credit_limit'   => 50000.0,
            'is_verified'    => true,
        ], $this->userId);
        $code = $this->container->get(CreditCardAccounts::class)->ensureAnalytic($this->supplierId, (array) $this->cards->find($this->supplierId, $this->ccId));
        self::assertNotNull($code);
        $this->ccCode = $code;
    }

    /** Režim bez mezičlenu: spárovaný nákup jde rovnou 321/231.x (mezičlen viz CreditCardPurchaseModeTest). */
    public function testMatchedPurchasePostsPayableAgainstCreditCardLoan(): void
    {
        $this->container->get(\MyInvoice\Service\Accounting\CreditCard\CreditCardPostingService::class)
            ->setPurchaseMode($this->supplierId, $this->ccId, 'direct');
        $pi = $this->postedPurchase(500.00);
        $tx = $this->ccTx(-500.00, 'Nákup na internetu | d.tran. 14.06.2099');
        $this->paymentMatch($tx, $pi, 500.00);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$tx]);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(500.00, $lines['321']['debit'], 0.001);
        self::assertEqualsWithDelta(500.00, $lines[$this->ccCode]['credit'], 0.001, 'Nákup kartou zvyšuje dluh vůči bance na 231.x.');
        self::assertSame([], $this->bankCodes($lines), 'Pohyb kreditky nesmí jít na banku 221.');
        self::assertEqualsWithDelta(-500.00, $this->balance($this->ccCode), 0.001);
    }

    /**
     * Vypnutý úvěrový účet nesmí spadnout na 221 (dluh by se tvářil jako peníze):
     * API vypnutí odmítne a resolver kreditku najde i neaktivní.
     */
    public function testDeactivatedCreditCardAccountStillPostsTo231AndCannotBeDeactivated(): void
    {
        $bankAccountId = (int) $this->cards->find($this->supplierId, $this->ccId)['bank_account_id'];
        $action = $this->container->get(\MyInvoice\Action\Accounting\Bank\SupplierBankAccountAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/accounting/bank-accounts/' . $bankAccountId)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody(['is_active' => false]);
        $response = $action->update($request, new \Slim\Psr7\Response(), ['id' => (string) $bankAccountId]);
        self::assertSame(422, $response->getStatusCode(), 'Kreditka se nevypíná.');

        // Starší data / přímý zásah: vypnutý řádek účtu.
        $this->db->pdo()->prepare('UPDATE supplier_bank_accounts SET is_active = 0 WHERE id = ? AND supplier_id = ?')
            ->execute([$bankAccountId, $this->supplierId]);
        $tx = $this->ccTx(-80.00, 'Nákup u obchodníka | d.tran. 15.06.2099');
        $this->service->postManual($this->supplierId, $tx, ['debit_account_code' => '518', 'credit_account_code' => '221'], $this->meta());

        $lines = $this->linesByAccountCode((int) $this->journal->findBySource($this->supplierId, 'bank', $tx)['id']);
        self::assertEqualsWithDelta(80.00, $lines[$this->ccCode]['credit'], 0.001);
        self::assertSame([], $this->bankCodes($lines), 'Nic na 221.');
    }

    /** RED bez přesměrování v BankAnalyticResolver: ruční zápis 518/221 skončil na bance. */
    public function testManualPostingOfPurchaseWithoutDocumentGoesTo231(): void
    {
        $tx = $this->ccTx(-120.00, 'Nákup u obchodníka | d.tran. 14.06.2099');

        $this->service->postManual($this->supplierId, $tx, ['debit_account_code' => '518', 'credit_account_code' => '221'], $this->meta());

        $lines = $this->linesByAccountCode((int) $this->journal->findBySource($this->supplierId, 'bank', $tx)['id']);
        self::assertEqualsWithDelta(120.00, $lines['518']['debit'], 0.001);
        self::assertEqualsWithDelta(120.00, $lines[$this->ccCode]['credit'], 0.001);
        self::assertSame([], $this->bankCodes($lines));
    }

    public function testInterestIsDetectedAndPostedTo562(): void
    {
        $tx = $this->ccTx(-87.40, 'ÚROK Z ÚVĚRU');

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(87.40, $lines['562']['debit'], 0.001);
        self::assertEqualsWithDelta(87.40, $lines[$this->ccCode]['credit'], 0.001);
    }

    public function testFeeUsesConfiguredAccount(): void
    {
        $feeAccount = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '548'"
        )->fetchColumn();
        $this->container->get(\MyInvoice\Service\Accounting\CreditCard\CreditCardSettingsService::class)
            ->save($this->supplierId, ['fee_account_id' => $feeAccount], $this->userId);
        $tx = $this->ccTx(-29.00, 'POPLATEK ZA OSTATNÍ SLUŽBY | INKASO CELK.DLUŽNÉ ČÁSTKY');

        $res = $this->service->handleTransaction($tx, $this->userId);

        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(29.00, $lines['548']['debit'], 0.001);
        self::assertEqualsWithDelta(29.00, $lines[$this->ccCode]['credit'], 0.001);
    }

    public function testRepaymentWithoutCounterpartyGoesAgainst261(): void
    {
        $tx = $this->ccTx(3000.00, 'VAŠE PLATBA - DĚKUJEME | d.tran. 15.06.2099');

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(3000.00, $lines[$this->ccCode]['debit'], 0.001, 'Splátka snižuje dluh.');
        self::assertEqualsWithDelta(3000.00, $this->lineOnPrefix($lines, '261', 'credit'), 0.001);
    }

    public function testRepaymentFromOwnCurrentAccountIsOwnTransferOnBothLegs(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO supplier_bank_accounts (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, 'Běžný účet', ?, ?, ?, 'CZK', ?, 'current', 'manual', 1)
             ON DUPLICATE KEY UPDATE is_active = 1, kind = 'current'"
        )->execute([$this->supplierId, self::ACCOUNT, self::BANK_CODE, self::BANK_CODE, self::ACCOUNT]);
        $out = $this->transaction($this->statement(), -2000.00, [
            'counterparty_account' => self::CC_ACCOUNT, 'counterparty_bank' => self::CC_BANK,
            'counterparty_name' => 'Splátka kreditky', 'description' => 'Splátka kreditní karty',
        ]);
        $in = $this->ccTx(2000.00, 'SPLÁTKA ÚVĚRU/ÚROKU', [
            'counterparty_account' => self::ACCOUNT, 'counterparty_bank' => self::BANK_CODE,
        ]);

        $resOut = $this->service->handleTransaction($out, $this->userId);
        $resIn = $this->service->handleTransaction($in, $this->userId);

        self::assertSame('posted', $resOut['action'], json_encode($resOut));
        self::assertSame('posted', $resIn['action'], json_encode($resIn));
        $outLines = $this->linesByAccountCode((int) $resOut['entry_id']);
        $inLines = $this->linesByAccountCode((int) $resIn['entry_id']);
        self::assertEqualsWithDelta(2000.00, $this->lineOnPrefix($outLines, '261', 'debit'), 0.001);
        self::assertEqualsWithDelta(2000.00, $outLines['221']['credit'], 0.001, 'Běžný účet zůstává na 221.x.');
        self::assertEqualsWithDelta(2000.00, $inLines[$this->ccCode]['debit'], 0.001);
        self::assertEqualsWithDelta(2000.00, $this->lineOnPrefix($inLines, '261', 'credit'), 0.001);
    }

    /** RB: splátka jde na sběrný účet banky s VS - druhá strana se pozná podle údajů úvěrového účtu. */
    public function testRepaymentToCollectionAccountIsRecognizedOnlyWithMatchingVs(): void
    {
        $this->cards->update($this->supplierId, $this->ccId, [
            'label' => 'Testovací kreditka', 'credit_limit' => 50000.0, 'repayment_account' => '1000000005',
            'repayment_bank_code' => '5500', 'repayment_vs' => '0012345678', 'note' => null,
        ]);
        $ok = $this->transaction($this->statement(), -1500.00, [
            'counterparty_account' => '1000000005', 'counterparty_bank' => '5500', 'variable_symbol' => '12345678',
        ]);
        $other = $this->transaction($this->statement(), -1500.00, [
            'counterparty_account' => '1000000005', 'counterparty_bank' => '5500', 'variable_symbol' => '99999999',
        ]);
        $this->registerCurrentAccount();

        $res = $this->service->handleTransaction($ok, $this->userId);
        $miss = $this->service->handleTransaction($other, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(1500.00, $this->lineOnPrefix($lines, '261', 'debit'), 0.001);
        self::assertEqualsWithDelta(1500.00, $lines['221']['credit'], 0.001);
        self::assertNotSame('posted', $miss['action'], 'Platba s jiným VS není splátka této kreditky.');
    }

    public function testCardPurchaseOnCreditCardGoesThroughCardClearing(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic)
             VALUES (?, 1, '2099-01-01', '378')
             ON DUPLICATE KEY UPDATE enabled = 1, effective_from = '2099-01-01', clearing_synthetic = '378'"
        )->execute([$this->supplierId]);
        $this->container->get(CardClearingRegime::class)->forget($this->supplierId);
        $tx = $this->ccTx(-640.00, 'PLATBA U OBCHODNÍKA GOOGLE PAY | d.tran. 14.06.2099');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '1111' WHERE id = ?")->execute([$tx]);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(640.00, $this->lineOnPrefix($lines, '378', 'debit'), 0.001, 'Platba kartou jde přes mezičlen platebních karet.');
        self::assertEqualsWithDelta(640.00, $lines[$this->ccCode]['credit'], 0.001, '… ale proti úvěru, ne proti bance.');
        self::assertSame([], $this->bankCodes($lines));
    }

    /**
     * RED bez výjimky v přesměru „jediné analytiky": holé 231 (bankovní úvěr) by skončilo
     * na analytice kreditní karty, jen protože je pod 231 jediná.
     */
    public function testLoanPostingOnBare231IsNotRedirectedToCreditCardAnalytic(): void
    {
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 10000.00],
            ['account_code' => '231', 'side' => 'credit', 'amount' => 10000.00],
        ], ['entry_date' => '2099-06-15', 'description' => 'Čerpání bankovního úvěru', 'posted' => true, 'user_id' => $this->userId]);

        $lines = $this->linesByAccountCode($entryId);
        self::assertArrayNotHasKey($this->ccCode, $lines);
        self::assertEqualsWithDelta(10000.00, $lines['231']['credit'], 0.001);
    }

    public function testPreviewShowsCreditCardAnalytic(): void
    {
        $tx = $this->ccTx(-50.00, 'ÚROK Z ÚVĚRU');
        $this->db->pdo()->prepare(
            "UPDATE auto_posting_policy SET level = 'suggest' WHERE supplier_id = ? AND operation_type = 'bank.interest'"
        )->execute([$this->supplierId]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        $preview = $this->service->previewSuggestion($this->supplierId, (int) $res['suggestion_id']);

        self::assertSame('suggested', $res['action'], json_encode($res));
        self::assertContains($this->ccCode, array_column($preview['lines'], 'account_code'), 'Náhled ukazuje přesně to, co se zapíše.');
    }

    public function testOtherSupplierCannotSeeCreditCardAccount(): void
    {
        $other = $this->otherSupplierId();

        self::assertNull($this->cards->find($other, $this->ccId));
        self::assertNull($this->container->get(CreditCardOverview::class)->detail($other, $this->ccId));
        self::assertNotNull($this->container->get(CreditCardOverview::class)->detail($this->supplierId, $this->ccId));
    }

    public function testSettingsAndAnalyticRequireBankPostPermission(): void
    {
        $action = $this->container->get(CreditCardAction::class);

        $settings = $this->callAction($action, 'saveSettings', 'PUT', 'readonly', ['fee_account_id' => null]);
        $analytic = $this->callAction($action, 'setAnalytic', 'PUT', 'readonly', ['account_code' => $this->ccCode], ['id' => (string) $this->ccId]);

        self::assertSame(403, $settings['status']);
        self::assertSame(403, $analytic['status']);
    }

    // ── import výpisu ───────────────────────────────────────────────────────────

    public function testImportCreatesUnverifiedAccountAssignsItToSupplierAndPostsCharges(): void
    {
        $parsed = $this->parsed('19-3000000001', '0100', -1000.00, [
            CreditCardPdfText::transaction('2099-06-10', -200.00, 'CZK', 'Nákup na internetu | d.tran. 09.06.2099', 'FIKTIVNI OBCHOD', '4321'),
            CreditCardPdfText::transaction('2099-06-12', -55.00, 'CZK', 'ÚROK Z ÚVĚRU'),
            CreditCardPdfText::transaction('2099-06-15', 1000.00, 'CZK', 'VAŠE PLATBA - DĚKUJEME'),
        ]);

        $result = $this->importer()->importParsed($this->supplierId, $parsed, $this->pdf(), 'vypis.pdf', $this->userId);

        self::assertSame(3, (int) $result['transactions']);
        $account = $this->cards->find($this->supplierId, (int) $result['credit_card_account_id']);
        self::assertNotNull($account);
        self::assertFalse($account['is_verified'], 'Účet založený importem čeká na ověření.');
        self::assertSame('kb', $account['issuer']);
        self::assertEqualsWithDelta(70000.00, (float) $account['credit_limit'], 0.001);
        self::assertNotNull($account['analytic_suffix']);
        self::assertSame($this->supplierId, (int) $this->db->pdo()->query(
            'SELECT supplier_id FROM bank_statements WHERE id = ' . (int) $result['statement_id']
        )->fetchColumn());
        $code = CreditCardAccounts::codeFor((string) $account['analytic_suffix']);
        self::assertEqualsWithDelta(-200.00 - 55.00 + 1000.00, $this->balance($code), 0.001, 'Úrok, splátka i nákup (výchozí režim mezičlenu 378.x) se zaúčtovaly automaticky.');

        $again = $this->importer()->importParsed($this->supplierId, $parsed, $this->lastPdf, 'vypis.pdf', $this->userId);
        self::assertTrue($again['duplicate']);
        self::assertSame((int) $result['credit_card_account_id'], $again['credit_card_account_id']);
    }

    public function testImportRefusesAccountOfOtherSupplier(): void
    {
        $other = $this->otherSupplierId();
        $this->cards->create($other, [
            'issuer' => 'kb', 'label' => 'Cizí kreditka', 'account_number' => '19-4000000009',
            'bank_code' => '0100', 'currency' => 'CZK', 'credit_limit' => null, 'is_verified' => true,
        ], null);

        try {
            $this->importer()->importParsed($this->supplierId, $this->parsed('19-4000000009', '0100', 0.0, [
                CreditCardPdfText::transaction('2099-06-10', -10.00, 'CZK', 'Nákup na internetu'),
            ]), $this->pdf(), 'vypis.pdf', $this->userId);
            self::fail('Účet jiné firmy se nesmí naimportovat.');
        } catch (PostingException $e) {
            self::assertSame('account_foreign', $e->errorCode);
        }
    }

    /**
     * Kreditka v `currencies` není, firmu párování bere z hlavičky výpisu: nákup se spáruje
     * s přijatou fakturou, příchozí pohyb (splátka) se s vydanou fakturou nepáruje nikdy.
     */
    public function testPurchaseOnCreditCardReachesMatchingButRepaymentDoesNot(): void
    {
        // Samotný zápis spárování si matcher otevírá ve vlastní transakci (testovací třída
        // drží celý test v transakci), proto se tu ověřuje jen určení firmy: bez něj nákup
        // skončil hned na „neznámý účet" a k hledání dokladu se nikdy nedostal.
        $matcher = $this->container->get(\MyInvoice\Service\Bank\StatementMatcher::class);

        $purchase = $this->ccTx(-345.67, 'Nákup u dodavatele bez dokladu');
        $result = $matcher->match($purchase);
        self::assertSame('unmatched', $result['status'] ?? null, json_encode($result));
        self::assertNotSame('unknown_supplier_for_account', $result['reason'] ?? null, 'Nákup kreditkou se páruje za firmu výpisu.');

        $repayment = $this->ccTx(345.67, 'Splátka kreditní karty');
        self::assertSame('unknown_supplier_for_account', $matcher->match($repayment)['reason'] ?? null,
            'Příchozí pohyb na kreditce se s vydanou fakturou nepáruje.');

        // Cizí firma se výpisem kreditky nevydává: účet u ní jako kreditka evidovaný není.
        $foreign = $this->transaction($this->statement(self::CC_ACCOUNT, self::CC_BANK, $this->otherSupplierId()), -10.00);
        self::assertSame('unknown_supplier_for_account', $matcher->match($foreign)['reason'] ?? null);
    }

    /** Daňová evidence: výpis kreditky by v peněžním deníku vystupoval jako peníze. */
    public function testImportIsRefusedOutsideDoubleEntry(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        try {
            $this->importer()->importParsed($this->supplierId, $this->parsed('19-5000000007', '0100', 0.0, []), $this->pdf(), 'vypis.pdf', $this->userId);
            self::fail('Import kreditky mimo podvojné účetnictví musí být odmítnut.');
        } catch (PostingException $e) {
            self::assertSame('credit_card_requires_double_entry', $e->errorCode);
        }
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_statements WHERE supplier_id = ?');
        $count->execute([$this->supplierId]);
        self::assertSame(0, (int) $count->fetchColumn(), 'Nic se nenaimportovalo.');
    }

    public function testImportRefusesForeignCurrencyAccount(): void
    {
        $parsed = $this->parsed('19-5000000007', '0100', 0.0, []);
        $parsed['header']['currency'] = 'EUR';

        $this->expectException(PostingException::class);
        $this->importer()->importParsed($this->supplierId, $parsed, $this->pdf(), 'vypis.pdf', $this->userId);
    }

    /**
     * Účet dřív importovaný jako běžný (ČSOB tiskne kreditku layoutem běžného účtu):
     * import ho sám nepřepne, převod přeúčtuje živé zápisy NA MÍSTĚ z 221.x na 231.x.
     */
    public function testCurrentAccountWithHistoryIsConvertedAndItsPostingsMoveTo231(): void
    {
        $number = '6000000002';
        $this->db->pdo()->prepare(
            "INSERT INTO supplier_bank_accounts (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, 'Účet 6000000002', ?, '0300', '0300', 'CZK', ?, 'current', 'statement', 1)"
        )->execute([$this->supplierId, $number, $number]);
        $bankAccountId = (int) $this->db->pdo()->lastInsertId();
        $tx = $this->transaction($this->statement($number, '0300'), -300.00, ['description' => 'Čerpání úvěru platební kartou']);
        $this->service->postManual($this->supplierId, $tx, ['debit_account_code' => '518', 'credit_account_code' => '221'], $this->meta());
        $entryId = (int) $this->journal->findBySource($this->supplierId, 'bank', $tx)['id'];
        $bankCode = (string) array_values($this->bankCodes($this->linesByAccountCode($entryId)))[0];

        try {
            $this->importer()->importParsed($this->supplierId, $this->parsed($number, '0300', 0.0, [
                CreditCardPdfText::transaction('2099-07-10', -10.00, 'CZK', 'Čerpání úvěru platební kartou'),
            ], 'csob'), $this->pdf(), 'vypis.pdf', $this->userId);
            self::fail('Běžný účet s historií se nesmí přepnout sám.');
        } catch (PostingException $e) {
            self::assertSame('account_is_bank_account', $e->errorCode);
            self::assertSame($bankAccountId, $e->context['bank_account_id']);
        }

        $result = $this->container->get(CreditCardConversionService::class)->convert($this->supplierId, $bankAccountId, $this->userId, 'csob');

        self::assertSame(1, $result['reposted']);
        $account = $this->cards->find($this->supplierId, $result['credit_card_account_id']);
        $code = CreditCardAccounts::codeFor((string) $account['analytic_suffix']);
        $live = $this->journal->findBySource($this->supplierId, 'bank', $tx);
        self::assertSame($entryId, (int) $live['id'], 'Zápis se přepsal na místě, bez storna.');
        $lines = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(300.00, $lines[$code]['credit'], 0.001);
        self::assertEqualsWithDelta(300.00, $lines['518']['debit'], 0.001, 'Protiúčet zůstává.');
        self::assertSame([], $this->bankCodes($lines));
        self::assertEqualsWithDelta(0.00, $this->balance($bankCode), 0.001, 'Na bance po převodu nic nezůstane.');
        self::assertSame('credit_card', (string) $this->db->pdo()->query("SELECT kind FROM supplier_bank_accounts WHERE id = {$bankAccountId}")->fetchColumn());
    }

    public function testCurrentAccountConversionPreservesRedStornoEffect(): void
    {
        $number = '6000000010';
        $this->db->pdo()->prepare(
            "INSERT INTO supplier_bank_accounts (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, 'Účet 6000000010', ?, '0300', '0300', 'CZK', ?, 'current', 'statement', 1)"
        )->execute([$this->supplierId, $number, $number]);
        $bankAccountId = (int) $this->db->pdo()->lastInsertId();
        $tx = $this->transaction($this->statement($number, '0300'), -300.00, [
            'description' => 'Červené storno karetního pohybu',
        ]);
        $this->service->postManual($this->supplierId, $tx, [
            'lines' => [
                ['account_code' => '221', 'side' => 'debit', 'amount' => 300.00, 'is_red_storno' => true],
                ['account_code' => '518', 'side' => 'credit', 'amount' => 300.00, 'is_red_storno' => true],
            ],
        ], $this->meta());
        $entryId = (int) $this->journal->findBySource($this->supplierId, 'bank', $tx)['id'];
        self::assertSame([true, true], array_column($this->entryLinesWithRed($entryId), 'is_red_storno'));

        $result = $this->container->get(CreditCardConversionService::class)
            ->convert($this->supplierId, $bankAccountId, $this->userId, 'csob');

        self::assertSame(1, $result['reposted']);
        $account = $this->cards->find($this->supplierId, $result['credit_card_account_id']);
        $code = CreditCardAccounts::codeFor((string) $account['analytic_suffix']);
        $lines = $this->entryLinesWithRed($entryId);
        self::assertSame([true, true], array_column($lines, 'is_red_storno'), 'Převod 221 na 231 nesmí změnit červené storno na běžný řádek.');
        self::assertSame([
            ['account_code' => $code, 'side' => 'debit', 'amount' => 300.0, 'is_red_storno' => true],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 300.0, 'is_red_storno' => true],
        ], $lines);
        self::assertEqualsWithDelta(-300.00, $this->signedBalance($code), 0.001, 'Dluh na 231 má stejný účinek jako původní red řádek na 221.');
        self::assertEqualsWithDelta(300.00, $this->signedBalance('518'), 0.001, 'Protiúčet si zachová původní účinek.');
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private string $lastPdf = '';

    private function importer(): CreditCardStatementImportService
    {
        return $this->container->get(CreditCardStatementImportService::class);
    }

    private function pdf(): string
    {
        return $this->lastPdf = '%PDF-1.4 synthetic ' . bin2hex(random_bytes(8));
    }

    /**
     * @param list<array<string,mixed>> $transactions
     * @return array{header:array<string,mixed>, transactions:list<array<string,mixed>>}
     */
    private function parsed(string $account, string $bank, float $prev, array $transactions, string $issuer = 'kb'): array
    {
        $curr = $prev + array_sum(array_column($transactions, 'amount'));
        return [
            'header' => CreditCardPdfText::header($issuer, $account, $bank, '2099-06-30', '6', $prev, $curr, $transactions, 'CZK', 70000.0, '2099-06-01'),
            'transactions' => $transactions,
            'parser' => $issuer . '_credit_card',
        ];
    }

    /** @param array<string,mixed> $over */
    private function ccTx(float $amount, string $description, array $over = []): int
    {
        return $this->transaction($this->statement(self::CC_ACCOUNT, self::CC_BANK), $amount, $over + [
            'counterparty_name' => null,
            'description' => $description,
        ]);
    }

    private function registerCurrentAccount(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO supplier_bank_accounts (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, 'Běžný účet', ?, ?, ?, 'CZK', ?, 'current', 'manual', 1)
             ON DUPLICATE KEY UPDATE is_active = 1, kind = 'current'"
        )->execute([$this->supplierId, self::ACCOUNT, self::BANK_CODE, self::BANK_CODE, self::ACCOUNT]);
    }

    private function postedPurchase(float $total): int
    {
        $vendor = $this->client('Dodavatel kreditka ' . uniqid());
        $pi = $this->purchaseInvoice('PF-CC-' . uniqid(), $vendor, $total);
        $this->postPredpis('purchase_invoice', $pi, '518', '321', $total);
        return $pi;
    }

    /**
     * @param array<string,array{debit:float,credit:float}> $lines
     * @return list<string>
     */
    private function bankCodes(array $lines): array
    {
        return array_values(array_filter(array_map('strval', array_keys($lines)), static fn (string $c): bool => str_starts_with($c, '221')));
    }

    /** @param array<string,array{debit:float,credit:float}> $lines */
    private function lineOnPrefix(array $lines, string $prefix, string $side): float
    {
        $sum = 0.0;
        foreach ($lines as $code => $l) {
            if (str_starts_with((string) $code, $prefix)) {
                $sum += $l[$side];
            }
        }
        return $sum;
    }

    /** Zůstatek účtu (MD − D) ze zápisů roku 2099. */
    private function balance(string $code): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.entry_date BETWEEN '2099-01-01' AND '2099-12-31' AND a.account_code = ?"
        );
        $stmt->execute([$this->supplierId, $code]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** @return list<array{account_code:string,side:string,amount:float,is_red_storno:bool}> */
    private function entryLinesWithRed(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount, l.is_red_storno
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?
              ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        return array_map(static fn (array $line): array => [
            'account_code' => (string) $line['account_code'],
            'side' => (string) $line['side'],
            'amount' => (float) $line['amount'],
            'is_red_storno' => (bool) $line['is_red_storno'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function signedBalance(string $code): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM((CASE WHEN l.side = 'debit' THEN 1 ELSE -1 END) * l.signed_amount), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.entry_date BETWEEN '2099-01-01' AND '2099-12-31' AND a.account_code = ?"
        );
        $stmt->execute([$this->supplierId, $code]);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
