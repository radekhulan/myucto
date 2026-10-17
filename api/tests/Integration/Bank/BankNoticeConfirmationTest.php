<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Service\Bank\BankNoticeConfirmationException;
use MyInvoice\Service\Bank\BankNoticeConfirmationScope;
use MyInvoice\Service\Bank\BankNoticeConfirmationService;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\Match\CounterpartyMapService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\WorkerLockWaitTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Reálné úhrady, izolovaný syntetický tenant, žádné tiché skipy prázdné DB. */
final class BankNoticeConfirmationTest extends TestCase
{
    use IsolatedSupplierTrait;
    use WorkerLockWaitTrait;

    private Connection $db;
    private BankNoticeConfirmationService $service;
    private \Psr\Container\ContainerInterface $container;
    private int $supplier;
    private int $client;
    private int $currency;
    private int $user;
    private const DATE = '2099-06-15';
    private const ACCOUNT = '1000000005';

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Testovací DB musí mít syntetický seed.');
        $this->supplier = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET company_name='Synthetic notice confirmation', accounting_enabled=0 WHERE id=?")->execute([$this->supplier]);
        $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,account_number,bank_code) VALUES (?,'CZK','Synthetic notice','Kč','Koruna','Koruna',?,'0100')")->execute([$this->supplier, self::ACCOUNT]);
        $this->currency = (int) $pdo->lastInsertId();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) VALUES (?,'Synthetic counterparty','Test 1','Test','11000',?,'',?)")->execute([$this->supplier, $country, $this->currency]);
        $this->client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO users (email,password_hash,name,role) VALUES (?,'synthetic-not-a-password','Synthetic notice tester','admin')")->execute(['notice-' . bin2hex(random_bytes(6)) . '@example.invalid']);
        $this->user = (int) $pdo->lastInsertId();
        $this->service = new BankNoticeConfirmationService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    private function movement(string $source, float $amount = 100.0, string $date = self::DATE, array $overrides = []): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO bank_statements (supplier_id,source,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$overrides['supplier'] ?? $this->supplier, $source, 'Synthetic notice.' . $source, hash('sha256', random_bytes(20)), $overrides['account'] ?? self::ACCOUNT, $overrides['bank'] ?? '0100', $overrides['currency'] ?? 'CZK', $date]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO bank_transactions (statement_id,source,source_ref,posted_at,amount,currency,variable_symbol,counterparty_account,bank_ref,balance) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$statement, $source === 'email_notice' ? 'email_notice' : 'statement', $overrides['source_ref'] ?? 'synthetic:' . bin2hex(random_bytes(10)), $date, $amount, $overrides['currency'] ?? 'CZK', $overrides['vs'] ?? '2099157', $overrides['counterparty'] ?? null, $overrides['bank_ref'] ?? null, $overrides['balance'] ?? null]);
        return (int) $pdo->lastInsertId();
    }

    private function statement(int $tx): int
    {
        return (int) $this->db->pdo()->query('SELECT statement_id FROM bank_transactions WHERE id=' . $tx)->fetchColumn();
    }

    private function invoice(float $amount = 100.0, string $type = 'invoice'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO invoices (supplier_id,client_id,currency_id,invoice_type,varsymbol,issue_date,tax_date,due_date,status,total_without_vat,total_with_vat,paid_total,created_by) VALUES (?,?,?,?,?,?,?,?,'issued',?,?,?,?)")
            ->execute([$this->supplier, $this->client, $this->currency, $type, '2099' . random_int(100000, 999999), self::DATE, self::DATE, self::DATE, $amount, $amount, $amount, $this->user]);
        return (int) $pdo->lastInsertId();
    }

    private function payment(int $tx, int $invoice, float $amount, ?int $taxDocument = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id,invoice_id,paid_on,amount,currency,source,bank_transaction_id,tax_document_invoice_id) VALUES (?,?,?,?,'CZK','bank',?,?)")
            ->execute([$this->supplier, $invoice, self::DATE, $amount, $tx, $taxDocument]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE bank_transactions SET match_status='manual',matched_invoice_id=?,matched_by=?,matched_at=NOW() WHERE id=?")->execute([$invoice, $this->user, $tx]);
        return $id;
    }

    public function testIssuedPaymentAndTaxDocumentKeepIdentityThroughConfirmationAndDeletion(): void
    {
        $notice = $this->movement('email_notice');
        $invoice = $this->invoice(100, 'proforma');
        $taxDocument = $this->invoice(100, 'tax_document');
        $payment = $this->payment($notice, $invoice, 100, $taxDocument);
        $before = $this->db->pdo()->query('SELECT * FROM invoice_payments WHERE id=' . $payment)->fetch(PDO::FETCH_ASSOC);
        $target = $this->movement('gpc');
        self::assertSame('confirmed', $this->service->reconcileNotice($this->supplier, $notice)['confirmation_state']);
        $after = $this->db->pdo()->query('SELECT * FROM invoice_payments WHERE id=' . $payment)->fetch(PDO::FETCH_ASSOC);
        $before['bank_transaction_id'] = $target;
        self::assertSame($before, $after, 'Přepojení nesmí měnit identitu, datum, částku ani daňový doklad.');
        self::assertSame('100.00', $this->db->pdo()->query('SELECT paid_total FROM invoices WHERE id=' . $invoice)->fetchColumn());
        $statement = $this->statement($notice);
        self::assertTrue($this->service->inspectStatement($this->supplier, $statement)['can_delete_notices']);
        $this->service->prepareNoticeDeletion($this->supplier, $statement, $this->user);
        $this->db->pdo()->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$statement]);
        self::assertSame($target, (int) $this->db->pdo()->query('SELECT bank_transaction_id FROM invoice_payments WHERE id=' . $payment)->fetchColumn());
        self::assertSame($taxDocument, (int) $this->db->pdo()->query('SELECT tax_document_invoice_id FROM invoice_payments WHERE id=' . $payment)->fetchColumn());
    }

    public function testPartialAndSplitIssuedPaymentsTransferWithoutRecreatingRows(): void
    {
        $notice = $this->movement('email_notice');
        $first = $this->payment($notice, $this->invoice(200), 40);
        $second = $this->payment($notice, $this->invoice(300), 60);
        $target = $this->movement('gpc');
        $this->service->reconcileNotice($this->supplier, $notice);
        $rows = $this->db->pdo()->query('SELECT id,amount,bank_transaction_id FROM invoice_payments WHERE id IN (' . $first . ',' . $second . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([['id'=>$first,'amount'=>'40.00','bank_transaction_id'=>$target],['id'=>$second,'amount'=>'60.00','bank_transaction_id'=>$target]], $rows);
        self::assertSame('confirmed', $this->service->reconcileNotice($this->supplier, $notice)['confirmation_state']);
    }

    public function testSplitPurchasePaymentsTransferWithoutChangingAmounts(): void
    {
        $notice = $this->movement('email_notice', -100);
        $pdo = $this->db->pdo();
        $ids = [];
        foreach ([40,60] as $amount) {
            $pdo->prepare("INSERT INTO purchase_invoices (supplier_id,vendor_id,vendor_invoice_number,issue_date,tax_date,due_date,received_at,currency_id,vendor_snapshot,total_without_vat,total_vat,total_with_vat,status,created_by) VALUES (?,?,?, ?,?,?,?,?,'{}',?,0,?,'received',?)")
                ->execute([$this->supplier,$this->client,'SYN-' . $amount,self::DATE,self::DATE,self::DATE,self::DATE,$this->currency,$amount,$amount,$this->user]);
            $purchase = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO payment_matches (supplier_id,bank_transaction_id,purchase_invoice_id,amount,match_type) VALUES (?,?,?,?,'manual')")->execute([$this->supplier,$notice,$purchase,$amount]);
            $ids[] = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE bank_transactions SET match_status='manual' WHERE id=?")->execute([$notice]);
        $target = $this->movement('gpc', -100);
        self::assertSame('confirmed', $this->service->reconcileNotice($this->supplier,$notice)['confirmation_state']);
        self::assertSame([['id'=>$ids[0],'amount'=>'40.00','bank_transaction_id'=>$target],['id'=>$ids[1],'amount'=>'60.00','bank_transaction_id'=>$target]], $pdo->query('SELECT id,amount,bank_transaction_id FROM payment_matches WHERE id IN (' . implode(',',$ids) . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testUnmatchedNoticeConfirmsAndBecomesInactiveIndependentlyOfInvoice(): void
    {
        $notice = $this->movement('email_notice');
        $target = $this->movement('gpc');
        $result = $this->service->reconcileAuthoritative($this->supplier, $target);
        self::assertSame('confirmed', $result[$notice]['confirmation_state']);
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_transactions bt WHERE bt.id=' . $notice . ' AND ' . BankNoticeConfirmationScope::activeSql())->fetchColumn());
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_statements bs WHERE bs.id=' . $this->statement($notice) . ' AND ' . BankNoticeConfirmationScope::visibleStatementSql())->fetchColumn());
        self::assertIsFloat($result[$notice]['candidates'][0]['amount'] ?? 100.0);
    }

    public function testStatementFirstNoticeIsSuppressedBeforeMatcherCreatesPaymentAndTombstoneBlocksRescan(): void
    {
        $target = $this->movement('gpc');
        $repo = $this->container->get(BankEmailNoticeRepository::class);
        $parsed = new ParsedBankEmailNotice('2099157',100,'CZK',self::DATE,self::ACCOUNT . '/0100');
        $sourceRef = 'synthetic-message-157@example.invalid';
        $result = $repo->createTransactionFromNotice($this->supplier,$parsed,$sourceRef,null,$this->container->get(StatementMatcher::class),'CZK');
        self::assertTrue($result['match_result']['notice_replaced']);
        $notice = $result['transaction_id'];
        self::assertSame('confirmed', $this->service->inspectNotice($this->supplier,$notice)['confirmation_state']);
        self::assertSame(0,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id=' . $notice)->fetchColumn());
        $duplicate = $repo->createTransactionFromNotice($this->supplier,$parsed,$sourceRef,null,$this->container->get(StatementMatcher::class),'CZK');
        self::assertSame($notice,$duplicate['transaction_id']);
        $this->service->prepareNoticeDeletion($this->supplier,$result['statement_id'],$this->user);
        $this->db->pdo()->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$result['statement_id']]);
        $again = $repo->createTransactionFromNotice($this->supplier,$parsed,$sourceRef,null,$this->container->get(StatementMatcher::class),'CZK');
        self::assertNull($again['transaction_id']);
        self::assertTrue($again['match_result']['notice_replaced']);
        self::assertSame(1,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM bank_transactions WHERE id=' . $target)->fetchColumn());
    }

    public function testAmbiguousNoticesNeverConsumeSameTarget(): void
    {
        $first=$this->movement('email_notice');
        $second=$this->movement('email_notice');
        $this->movement('gpc');
        foreach ([$first,$second] as $id) {
            $result=$this->service->reconcileNotice($this->supplier,$id);
            self::assertSame('review',$result['confirmation_state']);
            self::assertContains('ambiguous_notices',$result['blockers']);
        }
    }

    public function testAmbiguousTargetsNeedAuditedManualChoiceAndCannotBeRedirected(): void
    {
        $notice=$this->movement('email_notice');
        $first=$this->movement('gpc');
        $second=$this->movement('pdf');
        self::assertSame('ambiguous_candidates',$this->service->reconcileNotice($this->supplier,$notice)['confirmation_reason']);
        self::assertSame('confirmed',$this->service->confirmManually($this->supplier,$notice,$first,$this->user,'Synthetic evidence checked')['confirmation_state']);
        $row=$this->db->pdo()->query('SELECT method,confirmed_by,manual_reason FROM bank_notice_confirmations WHERE notice_transaction_id=' . $notice)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['method'=>'manual','confirmed_by'=>$this->user,'manual_reason'=>'Synthetic evidence checked'],$row);
        $this->expectException(BankNoticeConfirmationException::class);
        $this->service->confirmManually($this->supplier,$notice,$second,$this->user,'Try redirect');
    }

    public function testExistingConfirmedOrDeletedNoticeReservesTargetAgainstNewNotice(): void
    {
        $notice=$this->movement('email_notice');
        $target=$this->movement('gpc');
        $this->service->reconcileNotice($this->supplier,$notice);
        $this->service->prepareNoticeDeletion($this->supplier,$this->statement($notice));
        $this->db->pdo()->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$this->statement($notice)]);
        $other=$this->movement('email_notice');
        $result=$this->service->reconcileNotice($this->supplier,$other);
        self::assertSame('review',$result['confirmation_state']);
        self::assertContains('target_reserved',$result['blockers']);
        self::assertSame($target,(int)$this->db->pdo()->query('SELECT authoritative_transaction_id FROM bank_notice_confirmations WHERE deleted_at IS NOT NULL AND supplier_id=' . $this->supplier)->fetchColumn());
    }

    #[DataProvider('isolations')]
    public function testSupplierAccountCurrencyBankAndDirectionAreStrict(array $overrides,float $amount): void
    {
        $notice=$this->movement('email_notice');
        if (isset($overrides['supplier'])) $overrides['supplier']=$this->createIsolatedSupplier($this->db->pdo(),$this->supplier);
        $this->movement('gpc',$amount,self::DATE,$overrides);
        self::assertSame('pending',$this->service->reconcileNotice($this->supplier,$notice)['confirmation_state']);
    }

    public static function isolations(): array
    {
        return ['tenant'=>[['supplier'=>true],100],'bank'=>[['bank'=>'0300'],100],'account'=>[['account'=>'1000000013'],100],'currency'=>[['currency'=>'EUR'],100],'direction'=>[[],-100]];
    }

    public function testUnverifiedBankReferenceCannotResolveOtherwiseAmbiguousCandidates(): void
    {
        $notice=$this->movement('email_notice',100,self::DATE,['bank_ref'=>'SYN-REF-157']);
        $first=$this->movement('gpc',100,self::DATE,['bank_ref'=>'SYN-REF-157']);
        $this->movement('pdf',100,self::DATE,['bank_ref'=>'source-specific-reference']);
        self::assertSame('ambiguous_candidates',$this->service->reconcileNotice($this->supplier,$notice)['confirmation_reason']);
    }

    public function testReusedBankReferenceCannotOverrideConflictingVariableSymbol(): void
    {
        $notice=$this->movement('email_notice',100,self::DATE,['bank_ref'=>'1','vs'=>'111']);
        $this->movement('gpc',100,self::DATE,['bank_ref'=>'1','vs'=>'222']);
        self::assertSame('pending',$this->service->reconcileNotice($this->supplier,$notice)['confirmation_state']);
    }

    public function testDifferentAmountIsReviewAndCannotAutoCreateSecondPayment(): void
    {
        $notice=$this->movement('email_notice',-100);
        $this->payment($notice,$this->invoice(),100);
        $target=$this->movement('gpc',-101);
        self::assertTrue($this->service->blocksMatching($this->supplier,$target));
        self::assertSame('amount_difference',$this->service->inspectNotice($this->supplier,$notice)['confirmation_reason']);
        self::assertSame(1,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id=' . $notice)->fetchColumn());
    }

    public function testCrossPeriodPaymentDateTransfersWithoutChangingTaxDocument(): void
    {
        $notice=$this->movement('email_notice',100,'2099-12-31');
        $invoice=$this->invoice();
        $payment=$this->payment($notice,$invoice,100,$this->invoice(100,'tax_document'));
        $this->db->pdo()->prepare("UPDATE invoice_payments SET paid_on='2099-12-31' WHERE id=?")->execute([$payment]);
        $target=$this->movement('gpc',100,'2100-01-01');
        $before=$this->db->pdo()->query('SELECT * FROM invoice_payments WHERE id=' . $payment)->fetch(PDO::FETCH_ASSOC);
        $result=$this->service->reconcileNotice($this->supplier,$notice);
        self::assertSame('confirmed',$result['confirmation_state']);
        $before['bank_transaction_id'] = $target;
        self::assertSame($before,$this->db->pdo()->query('SELECT * FROM invoice_payments WHERE id=' . $payment)->fetch(PDO::FETCH_ASSOC));
        self::assertFalse($this->service->blocksMatching($this->supplier,$target));
        self::assertTrue($this->service->inspectStatement($this->supplier,$this->statement($notice))['can_delete_notices']);
    }

    public function testManualAmountAndDateDifferencePreservesOriginalPaymentAndTaxDocument(): void
    {
        $notice = $this->movement('email_notice', 100, '2099-12-31');
        $invoice = $this->invoice(100, 'proforma');
        $payment = $this->payment($notice, $invoice, 100, $this->invoice(100, 'tax_document'));
        $this->db->pdo()->prepare("UPDATE invoice_payments SET paid_on='2099-12-31' WHERE id=?")->execute([$payment]);
        $target = $this->movement('gpc', 102, '2100-01-12');
        $before = $this->db->pdo()->query('SELECT * FROM invoice_payments WHERE id=' . $payment)->fetch(PDO::FETCH_ASSOC);
        $result = $this->service->confirmManually($this->supplier, $notice, $target, $this->user, 'Synthetic bank settlement reviewed; original payment retained');
        self::assertSame('confirmed', $result['confirmation_state']);
        $before['bank_transaction_id'] = $target;
        self::assertSame($before, $this->db->pdo()->query('SELECT * FROM invoice_payments WHERE id=' . $payment)->fetch(PDO::FETCH_ASSOC));
        self::assertSame('100.00', $this->db->pdo()->query('SELECT paid_total FROM invoices WHERE id=' . $invoice)->fetchColumn());
        $audit = $this->db->pdo()->query('SELECT method,confirmed_by,manual_reason FROM bank_notice_confirmations WHERE notice_transaction_id=' . $notice)->fetch(PDO::FETCH_ASSOC);
        self::assertSame('manual', $audit['method']);
        self::assertSame($this->user, (int) $audit['confirmed_by']);
        self::assertStringContainsString('original payment retained', $audit['manual_reason']);
        self::assertTrue($this->service->inspectStatement($this->supplier, $this->statement($notice))['can_delete_notices']);
        $this->service->prepareNoticeDeletion($this->supplier, $this->statement($notice), $this->user);
        $this->db->pdo()->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$this->statement($notice)]);
        self::assertSame($before, $this->db->pdo()->query('SELECT * FROM invoice_payments WHERE id=' . $payment)->fetch(PDO::FETCH_ASSOC));
    }

    #[DataProvider('cardSettlementAllocations')]
    public function testCardFxConfirmationPreservesSingleAllocationOrRequiresManualSplit(array $amounts): void
    {
        $notice = $this->movement('email_notice', -100, '2099-06-14', ['vs'=>'']);
        $pdo = $this->db->pdo();
        $ids = [];
        foreach ($amounts as $amount) {
            $pdo->prepare("INSERT INTO purchase_invoices (supplier_id,vendor_id,vendor_invoice_number,issue_date,tax_date,due_date,received_at,currency_id,vendor_snapshot,total_without_vat,total_vat,total_with_vat,status,created_by) VALUES (?,?,?, ?,?,?,?,?,'{}',?,0,?,'received',?)")
                ->execute([$this->supplier,$this->client,'SYN-FX-' . $amount,self::DATE,self::DATE,self::DATE,self::DATE,$this->currency,$amount,$amount,$this->user]);
            $purchase = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO payment_matches (supplier_id,bank_transaction_id,purchase_invoice_id,amount,match_type) VALUES (?,?,?,?,'manual')")->execute([$this->supplier,$notice,$purchase,$amount]);
            $ids[] = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE bank_transactions SET match_status='manual' WHERE id=?")->execute([$notice]);
        $target = $this->movement('gpc', -102, self::DATE, ['vs'=>'']);
        $result = $this->service->reconcileNotice($this->supplier, $notice);
        if (count($amounts) === 1) {
            self::assertSame('confirmed', $result['confirmation_state']);
            self::assertSame('card_fx', $pdo->query('SELECT method FROM bank_notice_confirmations WHERE notice_transaction_id=' . $notice)->fetchColumn());
            self::assertSame('102.00', $pdo->query('SELECT amount FROM payment_matches WHERE id=' . $ids[0])->fetchColumn());
        } else {
            self::assertSame('review', $result['confirmation_state']);
            self::assertTrue($this->service->blocksMatching($this->supplier, $target));
            $result = $this->service->confirmManually($this->supplier, $notice, $target, $this->user, 'Synthetic FX split allocation retained');
            self::assertSame('confirmed', $result['confirmation_state']);
            foreach ($ids as $index => $id) self::assertSame(number_format($amounts[$index], 2, '.', ''), $pdo->query('SELECT amount FROM payment_matches WHERE id=' . $id)->fetchColumn());
        }
        foreach ($ids as $id) self::assertSame($target, (int) $pdo->query('SELECT bank_transaction_id FROM payment_matches WHERE id=' . $id)->fetchColumn());
        self::assertSame('unmatched', $pdo->query('SELECT match_status FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
        self::assertSame('superseded', $pdo->query('SELECT ignore_origin FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
        self::assertTrue($this->service->inspectStatement($this->supplier, $this->statement($notice))['can_delete_notices']);
    }

    public static function cardSettlementAllocations(): array
    {
        return ['single'=>[[100]], 'split'=>[[40, 60]]];
    }

    #[DataProvider('isolations')]
    public function testManualDifferencesStillRejectTenantAccountCurrencyBankAndDirection(array $overrides, float $amount): void
    {
        $notice = $this->movement('email_notice');
        if (isset($overrides['supplier'])) $overrides['supplier'] = $this->createIsolatedSupplier($this->db->pdo(), $this->supplier);
        $target = $this->movement('gpc', $amount, '2100-01-12', $overrides);
        $this->expectException(BankNoticeConfirmationException::class);
        try {
            $this->service->confirmManually($this->supplier, $notice, $target, $this->user, 'Synthetic unsafe account checked');
        } finally {
            self::assertSame('unmatched', $this->db->pdo()->query('SELECT match_status FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
            self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE notice_transaction_id=' . $notice)->fetchColumn());
        }
    }

    public function testNoticeOutsideCoveredPeriodIsNotSupersededButCanBeExplicitlyDeletedWhenUnlinked(): void
    {
        $notice = $this->movement('email_notice', -100, '2099-06-12', ['vs'=>'']);
        $this->movement('gpc', -100, '2099-06-15', ['vs'=>'']);
        $result = $this->service->reconcileNotice($this->supplier, $notice);
        self::assertSame('review', $result['confirmation_state']);
        self::assertContains('notice_outside_statement_period', $result['blockers']);
        self::assertSame('unmatched', $this->db->pdo()->query('SELECT match_status FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
        self::assertTrue($this->service->inspectStatement($this->supplier, $this->statement($notice))['can_delete_notices']);
        $this->service->prepareNoticeDeletion($this->supplier, $this->statement($notice));
        self::assertSame('unmatched', $this->db->pdo()->query('SELECT match_status FROM bank_transactions WHERE id=' . $notice)->fetchColumn(), 'Povolení ručního smazání nesmí samo avízo nahradit.');
    }

    public function testProvisionalBalanceNeedsAuthoritativeAnchorBeforeConfirmation(): void
    {
        $notice=$this->movement('email_notice',100,self::DATE,['balance'=>1000]);
        $target=$this->movement('gpc');
        self::assertSame('provisional_balance_not_covered',$this->service->reconcileNotice($this->supplier,$notice)['confirmation_reason']);
        $this->db->pdo()->prepare('UPDATE bank_statements SET curr_balance=1000 WHERE id=?')->execute([$this->statement($target)]);
        self::assertSame('confirmed',$this->service->reconcileNotice($this->supplier,$notice)['confirmation_state']);
    }

    public function testManualIgnoreIsNotProofButUnlinkedNoticeCanBeDeletedWithoutCandidate(): void
    {
        $notice=$this->movement('email_notice');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status='ignored',ignore_note='Nahrazeno bankovním výpisem' WHERE id=?")->execute([$notice]);
        self::assertSame('pending',$this->service->reconcileNotice($this->supplier,$notice)['confirmation_state']);
        self::assertTrue($this->service->inspectStatement($this->supplier,$this->statement($notice))['can_delete_notices']);
        $statement = $this->statement($notice);
        $this->service->prepareNoticeDeletion($this->supplier,$statement);
        $this->db->pdo()->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$statement]);
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
    }

    public function testInvalidationReturnsNoticeToVisibleReviewWithoutRestoringPayment(): void
    {
        $notice=$this->movement('email_notice');
        $payment=$this->payment($notice,$this->invoice(),100);
        $target=$this->movement('gpc');
        $this->service->reconcileNotice($this->supplier,$notice);
        $this->service->invalidateAuthoritativeTransactions($this->supplier,[$target],$this->user);
        $this->db->pdo()->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$this->statement($target)]);
        $result=$this->service->reconcileNotice($this->supplier,$notice);
        self::assertSame('review',$result['confirmation_state']);
        self::assertSame('authoritative_removed',$result['confirmation_reason']);
        self::assertNull($this->db->pdo()->query('SELECT bank_transaction_id FROM invoice_payments WHERE id=' . $payment)->fetchColumn());
        self::assertSame(1,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM bank_statements bs WHERE bs.id=' . $this->statement($notice) . ' AND ' . BankNoticeConfirmationScope::visibleStatementSql())->fetchColumn());
        self::assertSame(0,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM bank_transactions bt WHERE bt.id=' . $notice . ' AND ' . BankNoticeConfirmationScope::activeSql())->fetchColumn());
    }

    public function testConfirmedNoticeIsReadOnlyAndCannotBeMatchedAgain(): void
    {
        $notice=$this->movement('email_notice');
        $this->movement('gpc');
        $this->service->reconcileNotice($this->supplier,$notice);
        self::assertTrue($this->service->blocksMatching($this->supplier,$notice));
        $this->expectException(BankNoticeConfirmationException::class);
        $this->service->assertMutable($this->supplier,$notice);
    }

    public function testReconstructionPreviewDoesNotWriteAndBatchIsRepeatable(): void
    {
        $notice=$this->movement('email_notice');
        $this->movement('gpc');
        $statement=$this->statement($notice);
        $before=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE supplier_id=' . $this->supplier)->fetchColumn();
        $preview=$this->service->reconcileStatement($this->supplier,$statement,$this->user,true,1);
        self::assertTrue($preview['dry_run']);
        self::assertSame(1,$preview['processed']);
        self::assertSame($before,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE supplier_id=' . $this->supplier)->fetchColumn());
        $this->service->reconcileStatement($this->supplier,$statement,$this->user,false,1);
        $this->service->reconcileStatement($this->supplier,$statement,$this->user,false,1);
        self::assertSame(1,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testPartialMonthAndStoredStateFiltersStayConsistentUntilExplicitCheck(): void
    {
        $notice=$this->movement('email_notice');
        $missing=$this->movement('email_notice',200,self::DATE,['vs'=>'2099158']);
        $statement=$this->statement($notice);
        $this->db->pdo()->prepare('UPDATE bank_transactions SET statement_id=? WHERE id=?')->execute([$statement,$missing]);
        $this->movement('gpc');
        $this->movement('pdf');
        $before=$this->service->inspectStatement($this->supplier,$statement);
        self::assertSame(['pending'=>2,'review'=>0,'confirmed'=>0,'total'=>2],$before['confirmation_summary']);
        $preview=$this->service->reconcileStatement($this->supplier,$statement,$this->user,true);
        self::assertSame(['pending'=>1,'review'=>1,'confirmed'=>0,'total'=>2],$preview['proposed_summary']);
        self::assertSame($before['confirmation_summary'],$preview['confirmation_summary']);
        self::assertSame(2,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM bank_transactions bt WHERE bt.statement_id=' . $statement . ' AND ' . BankNoticeConfirmationScope::stateSql('pending'))->fetchColumn());
        $result=$this->service->reconcileStatement($this->supplier,$statement,$this->user);
        self::assertSame(['pending'=>1,'review'=>1,'confirmed'=>0,'total'=>2],$result['confirmation_summary']);
        self::assertTrue($result['can_delete_notices']);
        $this->service->prepareNoticeDeletion($this->supplier, $statement);
        foreach (['pending','review','confirmed'] as $state) {
            self::assertSame($result['confirmation_summary'][$state],(int)$this->db->pdo()->query('SELECT COUNT(*) FROM bank_transactions bt WHERE bt.statement_id=' . $statement . ' AND ' . BankNoticeConfirmationScope::stateSql($state))->fetchColumn());
        }
    }

    #[DataProvider('dependencies')]
    public function testDependenciesRemainVisibleAndBlockDeletion(string $kind): void
    {
        $notice=$this->movement('email_notice');
        $this->movement('gpc');
        $pdo=$this->db->pdo();
        switch ($kind) {
            case 'document_requests':
                $pdo->prepare("INSERT INTO document_requests (supplier_id,description,bank_transaction_id) VALUES (?,'Synthetic request',?)")->execute([$this->supplier,$notice]);
                break;
            case 'cash_journal_notes':
                $pdo->prepare("INSERT INTO cash_journal_notes (supplier_id,source_type,source_id,body) VALUES (?,'bank',?,'Synthetic note')")->execute([$this->supplier,$notice]);
                break;
            case 'tax_advance_schedules':
                $pdo->prepare("INSERT INTO tax_advance_schedules (supplier_id,advance_kind,taxpayer_type,period_year,seq_no,due_date,matched_transaction_id) VALUES (?,'tax','fo',2099,1,?,?)")->execute([$this->supplier,self::DATE,$notice]);
                break;
            case 'fuelings':
                $pdo->prepare('INSERT INTO fuelings (supplier_id,fueled_date,source_bank_transaction_id) VALUES (?,?,?)')->execute([$this->supplier,self::DATE,$notice]);
                break;
            case 'de_movement_classification':
                $pdo->prepare("INSERT INTO de_movement_classification (supplier_id,source_type,bank_transaction_id,tax_bucket) VALUES (?,'bank',?,'transfer')")->execute([$this->supplier,$notice]);
                break;
            case 'de_movement_classification_history':
                $pdo->prepare("INSERT INTO de_movement_classification_history (supplier_id,source_type,source_id,new_tax_bucket) VALUES (?,'bank',?,'transfer')")->execute([$this->supplier,$notice]);
                break;
            case 'document_dimensions':
            case 'document_dimension_splits':
                $pdo->prepare("INSERT INTO dimension_types (supplier_id,code,name) VALUES (?,'synthetic157','Synthetic dimension')")->execute([$this->supplier]);
                $type=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO dimension_values (supplier_id,type_id,code,name) VALUES (?,?,'synthetic157','Synthetic value')")->execute([$this->supplier,$type]);
                $value=(int)$pdo->lastInsertId();
                $share=$kind==='document_dimension_splits' ? ',share' : '';
                $valueShare=$kind==='document_dimension_splits' ? ',1' : '';
                $pdo->prepare("INSERT INTO {$kind} (supplier_id,doc_type,doc_id,dimension_type_id,dimension_value_id{$share}) VALUES (?,'bank_transaction',?,?,?{$valueShare})")->execute([$this->supplier,$notice,$type,$value]);
                break;
            case 'document_links':
            case 'purchase_invoice_submissions':
                $hash=hash('sha256','Synthetic document157');
                $pdo->prepare("INSERT INTO documents (supplier_id,filename,original_name,mime_type,sha256,size_bytes,title) VALUES (?,'synthetic157.pdf','synthetic157.pdf','application/pdf',?,1,'Synthetic document')")->execute([$this->supplier,$hash]);
                $document=(int)$pdo->lastInsertId();
                if ($kind==='document_links') {
                    $pdo->prepare("INSERT INTO document_links (supplier_id,document_id,entity_type,entity_id) VALUES (?,?,'bank_transaction',?)")->execute([$this->supplier,$document,$notice]);
                } else {
                    $pdo->prepare("INSERT INTO purchase_invoice_submissions (supplier_id,document_id,document_sha256,bank_transaction_id) VALUES (?,?,?,?)")->execute([$this->supplier,$document,$hash,$notice]);
                }
                break;
        }
        $result=$this->service->reconcileNotice($this->supplier,$notice);
        self::assertSame('review',$result['confirmation_state']);
        self::assertContains('dependency_' . $kind,$result['blockers']);
        self::assertFalse($this->service->inspectStatement($this->supplier,$this->statement($notice))['can_delete_notices']);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM bank_transactions bt WHERE bt.id=' . $notice . ' AND ' . BankNoticeConfirmationScope::activeSql())->fetchColumn());
    }

    public static function dependencies(): array
    {
        return array_map(static fn(string $kind):array=>[$kind],['document_requests','cash_journal_notes','tax_advance_schedules','fuelings','de_movement_classification','de_movement_classification_history','document_dimensions','document_dimension_splits','document_links','purchase_invoice_submissions']);
    }

    public function testFailedGoPayHandoverRollsBackPaymentAndConfirmationAsOneUnit(): void
    {
        $notice=$this->movement('email_notice');
        $payment=$this->payment($notice,$this->invoice(),100);
        $this->movement('gpc');
        $pdo=$this->db->pdo();
        $pdo->prepare("INSERT INTO gopay_clearings (supplier_id,clearing_id,variable_symbol,currency,account_name,performed_on,cleared_from,cleared_to,amount_gross,amount_sent,amount_transfer,file_content,file_hash,file_name,payout_match_transaction_id) VALUES (?,'SYN157','2099157','CZK','Synthetic',?,?,?,100,100,100,'',?,'synthetic157.xml',?)")
            ->execute([$this->supplier,self::DATE,self::DATE,self::DATE,hash('sha256','Synthetic GoPay157'),$notice]);
        $clearing=(int)$pdo->lastInsertId();
        // GoPay nemá konfiguraci k dokončení výplaty: odmítne ji až po přepojení
        // úhrad. Savepoint musí vrátit všechny již provedené změny.
        $service=$this->container->get(BankNoticeConfirmationService::class);
        try {
            $service->reconcileNotice($this->supplier,$notice);
            self::fail('Nedokončená GoPay výplata nesmí dostat potvrzení.');
        } catch (BankNoticeConfirmationException $e) {
            self::assertSame('dependency_gopay_payout',$e->errorCode);
        }
        self::assertSame($notice,(int)$pdo->query('SELECT bank_transaction_id FROM invoice_payments WHERE id=' . $payment)->fetchColumn());
        self::assertSame($notice,(int)$pdo->query('SELECT payout_match_transaction_id FROM gopay_clearings WHERE id=' . $clearing)->fetchColumn());
        self::assertSame('manual',$pdo->query('SELECT match_status FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE notice_transaction_id=' . $notice)->fetchColumn());
    }

    public function testLearningObservationTransfersWithoutChangingCountersOrCountingAgain(): void
    {
        $notice=$this->movement('email_notice');
        $maps=$this->container->get(CounterpartyMapService::class);
        $maps->record($this->supplier,self::ACCOUNT,'0100','incoming',$this->client,true,1.5,$notice);
        $pdo=$this->db->pdo();
        $before=$pdo->query('SELECT * FROM bank_counterparty_map WHERE supplier_id=' . $this->supplier)->fetch(PDO::FETCH_ASSOC);
        $observation=$pdo->query('SELECT * FROM bank_counterparty_observations WHERE bank_transaction_id=' . $notice)->fetch(PDO::FETCH_ASSOC);
        $target=$this->movement('gpc');
        self::assertSame('confirmed',$this->service->reconcileNotice($this->supplier,$notice)['confirmation_state']);
        $observation['bank_transaction_id']=$target;
        self::assertSame($observation,$pdo->query('SELECT * FROM bank_counterparty_observations WHERE bank_transaction_id=' . $target)->fetch(PDO::FETCH_ASSOC));
        $maps->record($this->supplier,self::ACCOUNT,'0100','incoming',$this->client,true,1.5,$target);
        $this->service->reconcileNotice($this->supplier,$notice);
        self::assertSame($before,$pdo->query('SELECT * FROM bank_counterparty_map WHERE supplier_id=' . $this->supplier)->fetch(PDO::FETCH_ASSOC));
        $this->service->prepareNoticeDeletion($this->supplier,$this->statement($notice));
        $pdo->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$this->statement($notice)]);
        self::assertSame($observation,$pdo->query('SELECT * FROM bank_counterparty_observations WHERE bank_transaction_id=' . $target)->fetch(PDO::FETCH_ASSOC));
    }

    public function testConflictingLearningObservationRequiresReviewWithoutLosingCounts(): void
    {
        $notice=$this->movement('email_notice');
        $target=$this->movement('gpc');
        $maps=$this->container->get(CounterpartyMapService::class);
        foreach ([$notice,$target] as $id) $maps->record($this->supplier,self::ACCOUNT,'0100','incoming',$this->client,true,null,$id);
        $before=$this->db->pdo()->query('SELECT * FROM bank_counterparty_map WHERE supplier_id=' . $this->supplier)->fetch(PDO::FETCH_ASSOC);
        $result=$this->service->reconcileNotice($this->supplier,$notice);
        self::assertSame('review',$result['confirmation_state']);
        self::assertContains('dependency_bank_counterparty_observations',$result['blockers']);
        self::assertSame($before,$this->db->pdo()->query('SELECT * FROM bank_counterparty_map WHERE supplier_id=' . $this->supplier)->fetch(PDO::FETCH_ASSOC));
    }

    public function testOneHundredNoticeConfirmationsDoNotRecheckUnrelatedConfirmedNotices(): void
    {
        $targets=[];
        for ($i=0;$i<100;$i++) {
            $identity=['vs'=>'2099157' . str_pad((string)$i,3,'0',STR_PAD_LEFT)];
            $this->movement('email_notice',100,self::DATE,$identity);
            $targets[]=$this->movement('gpc',100,self::DATE,$identity);
        }
        foreach ($targets as $target) {
            $result=$this->service->reconcileAuthoritative($this->supplier,$target);
            self::assertCount(1,$result);
            self::assertSame('confirmed',array_values($result)[0]['confirmation_state']);
        }
        self::assertSame(100,(int)$this->db->pdo()->query("SELECT COUNT(*) FROM bank_notice_confirmations WHERE state='confirmed' AND supplier_id=" . $this->supplier)->fetchColumn());
    }

    public function testConcurrentDependencyCommittedAfterOldSnapshotPreventsConfirmation(): void
    {
        $notice=$this->movement('email_notice');
        $this->movement('gpc');
        $pdo=$this->db->pdo();
        $pdo->commit();
        $control=tempnam(sys_get_temp_dir(),'notice-confirmation-');
        self::assertNotFalse($control);
        $process=null;
        try {
            $pdo->beginTransaction();
            (new BankNoticeMutationGuard($this->db))->mutate($this->supplier,$notice,function () use ($pdo,$notice): void {
                $pdo->prepare("INSERT INTO cash_journal_notes (supplier_id,source_type,source_id,body) VALUES (?,'bank',?,'Concurrent synthetic note')")->execute([$this->supplier,$notice]);
            });
            file_put_contents($control,json_encode(['database'=>$pdo->query('SELECT DATABASE()')->fetchColumn(),'supplier'=>$this->supplier,'notice'=>$notice],JSON_THROW_ON_ERROR));
            $command=[PHP_BINARY];
            $socket=(string)ini_get('pdo_mysql.default_socket');
            if ($socket!=='') array_push($command,'-d','pdo_mysql.default_socket=' . $socket);
            array_push($command,dirname(__DIR__,2) . '/Support/BankNoticeConfirmationWorker.php',$control);
            $process=new Process($command,dirname(__DIR__,4),null,null,30);
            $process->start();
            $ready=$this->awaitWorkerReady($process,$control . '.ready');
            $this->assertWorkerWaitsOnLock($pdo,$process,(int)$ready['connection_id'],'/SELECT bt\.\*.*FOR UPDATE/is','Potvrzení musí čekat na souběžně připojovanou vazbu.');
            $pdo->commit();
            $process->wait();
            self::assertSame(0,$process->getExitCode(),$process->getOutput() . $process->getErrorOutput());
            $result=json_decode((string)file_get_contents($control . '.result'),true,512,JSON_THROW_ON_ERROR);
            self::assertSame('review',$result['confirmation_state']);
            self::assertContains('dependency_cash_journal_notes',$result['blockers']);
            self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM bank_notice_confirmations WHERE state='confirmed' AND supplier_id=" . $this->supplier)->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($process?->isRunning()) $process->stop();
            foreach (['','.ready','.result'] as $suffix) if (is_file($control . $suffix)) unlink($control . $suffix);
            $pdo->prepare('DELETE FROM bank_notice_confirmations WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM cash_journal_notes WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM bank_statements WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM clients WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$this->user]);
        }
    }
}
