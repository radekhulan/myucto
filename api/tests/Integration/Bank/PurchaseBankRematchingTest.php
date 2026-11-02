<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Match\MatchCandidateProvider;
use MyInvoice\Service\Bank\PurchaseBankMatching;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceReceiver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PDO;

/** Syntetická firma, bankovní pohyb před dokladem; žádná závislost na zákaznických datech. */
#[Group('integration')]
final class PurchaseBankRematchingTest extends TestCase
{
    private \Psr\Container\ContainerInterface $container;
    private bool $committed = false;
    private PDO $pdo;
    private int $supplier;
    private int $user;
    private int $currency;
    private string $account;
    private int $vendor;
    private int $statement;
    private int $invoice;
    private int $transaction;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->prepare("INSERT INTO supplier (company_name,street,city,zip,country_id,email,default_currency_id,default_vat_rate_id)
            VALUES ('TEST Rematching','Test 1','Testov','10000',?,'',0,?)")->execute([$country,$vat]);
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        // Každá firma má vlastní syntetický účet s platným mod-11 prefixem.
        $prefix = $this->supplier * 100;
        while (true) {
            $digits = str_pad((string)$prefix,6,'0',STR_PAD_LEFT);
            $sum = 0;
            foreach ([10,5,8,4,2,1] as $i => $weight) $sum += (int)$digits[$i] * $weight;
            if ($sum % 11 === 0) break;
            ++$prefix;
        }
        $this->account = $prefix . '-1000000005';
        $this->pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,account_number,bank_code)
            VALUES (?,'CZK','TEST CZK','Kč','koruna','crown',?,'0100')")->execute([$this->supplier,$this->account]);
        $this->currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$this->currency,$this->supplier]);
        $this->pdo->prepare("INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            VALUES (?,'TEST Vendor Alpha','Test 1','Testov','10000',?,'',?)")->execute([$this->supplier,$country,$this->currency]);
        $this->vendor = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date)
            VALUES (?,'synthetic-rematch.json',?,?,'0100','CZK','2099-06-15')")
            ->execute([$this->supplier,hash('sha256', 'rematch-'.$this->supplier),$this->account]);
        $this->statement = (int) $this->pdo->lastInsertId();
        $this->transaction = $this->payment();
        $this->pdo->prepare("INSERT INTO users (email,password_hash,name,role_id) SELECT ?, '', 'TEST Rematch', id FROM roles WHERE system_key='superadmin'")
            ->execute(['rematch-'.$this->supplier.'@example.invalid']);
        $this->user = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id,vendor_id,currency_id,document_kind,status,varsymbol,vendor_invoice_number,
            created_by,vendor_snapshot,received_at,payment_variable_symbol,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat)
            VALUES (?,?,?,'invoice','received','PF20990001','TEST-INV-001',?,'{}','2099-06-15','20990001','2099-06-15','2099-06-15','2099-06-15',812.34,0,812.34)")
            ->execute([$this->supplier,$this->vendor,$this->currency,$this->user]);
        $this->invoice = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($this->committed) $this->cleanup();
    }

    private function payment(?string $vs = '20990001'): int
    {
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,variable_symbol,description,counterparty_name)
            VALUES (?,'2099-06-15',-812.34,'CZK',?,'TEST merchant Beta','TEST Beta')")
            ->execute([$this->statement,$vs]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testReceivedReceiptIsProposedEvenWithDifferentMerchantAndNoCard(): void
    {
        $this->pdo->exec("UPDATE purchase_invoices SET document_kind='receipt', status='paid' WHERE id={$this->invoice}");
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol='9999' WHERE id={$this->transaction}");
        $tx = $this->pdo->query("SELECT * FROM bank_transactions WHERE id={$this->transaction}")->fetch(PDO::FETCH_ASSOC);
        $candidates = $this->container->get(MatchCandidateProvider::class)->candidatesFor($this->supplier,$tx,$this->invoice);
        self::assertContains($this->invoice, array_column($candidates,'purchase_invoice_id'));
        self::assertSame(0, $this->paymentCount());
    }

    public function testAcceptingInvoiceSchedulesMatchingOnlyAfterCommit(): void
    {
        $this->container->get(PurchaseInvoiceReceiver::class)->afterReceived($this->supplier,$this->invoice,null);
        self::assertSame(0, $this->paymentCount());
        // Callback je nutné ověřit na skutečném commitu, data se uklidí explicitně.
        $this->commitFixture();
        self::assertSame(1, $this->paymentCount(), json_encode($this->read()));
    }

    public static function paidImporters(): iterable
    {
        yield 'Fakturoid' => [\MyInvoice\Service\Import\FakturoidImportService::class];
        yield 'iDoklad' => [\MyInvoice\Service\Import\IdokladImportService::class];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paidImporters')]
    public function testPaidImportWaitsForImportCompletionAndCommit(string $importer): void
    {
        $this->pdo->exec("UPDATE purchase_invoices SET status='draft' WHERE id={$this->invoice}");
        $method = new \ReflectionMethod($importer, 'applyPurchasePaymentState');
        $method->invoke($this->container->get($importer), $this->invoice, $this->supplier,
            ['status' => 'paid', 'paid_at' => '2099-06-15'], '2099-06-15', $this->user);
        self::assertSame(0, $this->paymentCount());
        $this->commitFixture();
        self::assertSame(0, $this->paymentCount(), 'Importní smyčka nesmí spouštět dohledání.');
        $jobs = $this->container->get(\MyInvoice\Repository\ImportJobRepository::class);
        $jobId = $jobs->create($this->supplier, str_contains($importer, 'Fakturoid') ? 'fakturoid' : 'idoklad', [
            'include_clients' => false, 'include_issued' => false, 'include_received' => false,
            'include_receipts' => false, 'include_bank_accounts' => false, 'include_bank_transactions' => false,
        ], $this->user);
        $this->container->get($importer)->run($jobId);
        self::assertSame('completed', $jobs->find($jobId, $this->supplier)['status']);
        self::assertSame(1, $this->paymentCount(), json_encode($this->read()));
    }

    private function commitFixture(): void
    {
        $this->pdo->commit();
        $this->committed = true;
    }

    private function cleanup(): void
    {
        foreach (['import_jobs','tax_submissions','tax_evidence_closings','bank_match_audit','bank_counterparty_map','payment_matches','bank_match_suggestions','bank_statements','purchase_invoices','invoices','clients'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE supplier_id=?")->execute([$this->supplier]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$this->user]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->pdo->prepare('DELETE FROM currencies WHERE supplier_id=?')->execute([$this->supplier]);
        } finally { $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); }
        $this->pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
    }

    private function retry(): array
    {
        return $this->container->get(PurchaseBankMatching::class)->retry($this->supplier, $this->invoice, $this->user);
    }

    private function read(): array
    {
        return $this->container->get(PurchaseBankMatching::class)->read($this->supplier, $this->invoice);
    }

    public function testExactMatchAndRetryAreIdempotent(): void
    {
        self::assertSame('searched', $this->retry()['outcome']);
        $this->retry();
        self::assertSame(1, $this->paymentCount());
        self::assertCount(1, $this->read()['matches']);
    }

    public function testForeignCardPaymentUsesNegativeOriginalAmount(): void
    {
        $this->pdo->exec("UPDATE currencies SET code='EUR' WHERE id={$this->currency}");
        $this->pdo->exec("UPDATE purchase_invoices SET exchange_rate=25, card_last4='1234' WHERE id={$this->invoice}");
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol=NULL, amount=-22000, original_amount=-812.34, original_currency='EUR', card_last4='1234' WHERE id={$this->transaction}");
        $this->retry();
        self::assertSame(1, $this->paymentCount());
    }

    public function testOwnTransferCannotBecomeInvoicePayment(): void
    {
        $this->pdo->prepare("INSERT INTO supplier_bank_accounts (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
            VALUES (?, 'TEST own second account', '19-1000000005', '0100', '0100', 'CZK', '191000000005', 'current', 'manual', 1)")->execute([$this->supplier]);
        $this->pdo->prepare("UPDATE bank_transactions SET counterparty_account='19-1000000005', counterparty_bank='0100' WHERE id=?")
            ->execute([$this->transaction]);
        $this->retry();
        self::assertSame(0, $this->paymentCount());
        self::assertSame([], $this->read()['suggestions']);
    }

    public function testInvoiceFirstStillMatchesOnBankImport(): void
    {
        $result = $this->container->get(\MyInvoice\Service\Bank\StatementMatcher::class)->match($this->transaction);
        self::assertSame('auto_exact', $result['status'],json_encode($result));
        self::assertSame(1, $this->paymentCount());
    }

    public function testExactSymbolOutsideDateWindowIsFound(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET posted_at='2098-01-01' WHERE id={$this->transaction}");
        $this->retry();
        self::assertSame(1, $this->paymentCount());
    }

    public function testPaidWithoutSettlementCanMatchButSettledCannot(): void
    {
        $this->pdo->exec("UPDATE purchase_invoices SET status='paid' WHERE id={$this->invoice}");
        $this->retry();
        self::assertSame(1, $this->paymentCount());
        $this->payment();
        $this->retry();
        self::assertSame(1, $this->paymentCount());
    }

    public function testExistingOutsideSettlementPreventsDuplicatePayment(): void
    {
        $this->pdo->exec("UPDATE purchase_invoices SET status='paid', settled_outside_amount=812.34 WHERE id={$this->invoice}");
        self::assertSame('ineligible',$this->retry()['outcome']);
        self::assertSame(0,$this->paymentCount());
    }

    public function testPartialSettlementMatchesOnlyRemainingAmount(): void
    {
        $this->pdo->exec("UPDATE purchase_invoices SET settled_outside_amount=300 WHERE id={$this->invoice}");
        $this->pdo->exec("UPDATE bank_transactions SET amount=-512.34 WHERE id={$this->transaction}");
        $this->retry();
        self::assertSame(1,$this->paymentCount());
        self::assertSame('512.34',$this->pdo->query("SELECT amount FROM payment_matches WHERE purchase_invoice_id={$this->invoice}")->fetchColumn());
    }

    public function testTwoPaymentsAreProposedWithoutGuessing(): void
    {
        $this->payment();
        $this->retry();
        self::assertSame(0,$this->paymentCount());
        self::assertCount(2,$this->read()['suggestions']);
    }

    public function testRejectedReceiptProposalIsNotRevived(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol=NULL WHERE id={$this->transaction}");
        $this->retry();
        $suggestion = $this->read()['suggestions'][0];
        $this->commitFixture();
        $this->container->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class)
            ->reject((int)$suggestion['id'],$this->supplier,$this->user,null);
        $this->retry();
        self::assertCount(0,$this->read()['suggestions']);
        self::assertSame(0,$this->paymentCount());
    }

    public static function completedSuggestionStates(): iterable
    {
        yield 'accepted' => ['accepted'];
        yield 'superseded' => ['superseded'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('completedSuggestionStates')]
    public function testFreePaymentCanGetNewProposalAfterEarlierMatchWasRemoved(string $status): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol='9999' WHERE id={$this->transaction}");
        $this->pdo->exec("UPDATE clients SET company_name='Alfa Services' WHERE id={$this->vendor}");
        $this->pdo->exec("UPDATE bank_transactions SET counterparty_name='Harbor Marketplace', description='Card purchase' WHERE id={$this->transaction}");
        $this->retry();
        $shown = $this->read()['suggestions'][0];
        self::assertArrayNotHasKey('name_fuzzy', $shown['candidate']['signals']);
        // Stav po odpárování: pohyb je volný, ale historie přijatého návrhu zůstala.
        $this->pdo->prepare("UPDATE bank_match_suggestions SET status=?, accepted_candidate=0, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$status, $this->user, $shown['id']]);
        $this->retry();
        $rows = $this->read()['suggestions'];
        self::assertCount(1, $rows);
        self::assertSame($this->invoice, $rows[0]['candidate']['purchase_invoice_id']);
        self::assertSame(0, $this->paymentCount());
        self::assertNotSame($shown['id'], $rows[0]['id']);
        $this->retry();
        self::assertCount(1, $this->read()['suggestions']);
        $review = $this->pdo->query("SELECT accepted_candidate,reviewed_by,reviewed_at FROM bank_match_suggestions WHERE id=".(int)$shown['id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, $review['accepted_candidate']);
        self::assertSame($this->user, $review['reviewed_by']);
        self::assertNotNull($review['reviewed_at']);
    }

    public function testReceiptSuggestionCanBeAcceptedOnce(): void
    {
        $this->pdo->exec("UPDATE purchase_invoices SET document_kind='receipt', status='paid' WHERE id={$this->invoice}");
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol='9999' WHERE id={$this->transaction}");
        $this->retry();
        $suggestion = $this->read()['suggestions'][0];
        $this->commitFixture();
        $this->container->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class)
            ->accept((int)$suggestion['id'],$this->supplier,$this->user,(int)$suggestion['candidate_index']);
        self::assertSame(1,$this->paymentCount());
        $this->retry();
        self::assertSame(1,$this->paymentCount());
    }

    public function testLaterInvoiceDoesNotChangeCandidateIndexInOpenDetail(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol=NULL WHERE id={$this->transaction}");
        $this->retry();
        $shown = $this->read()['suggestions'][0];
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id,vendor_id,currency_id,document_kind,status,varsymbol,vendor_invoice_number,
            created_by,vendor_snapshot,received_at,payment_variable_symbol,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat)
            SELECT supplier_id,vendor_id,currency_id,document_kind,status,'PF20990002','TEST-INV-002',created_by,vendor_snapshot,
            received_at,payment_variable_symbol,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat FROM purchase_invoices WHERE id=?")
            ->execute([$this->invoice]);
        $other = (int) $this->pdo->lastInsertId();
        $this->container->get(PurchaseBankMatching::class)->retry($this->supplier, $other, $this->user);
        self::assertSame($shown['candidate_index'], $this->read()['suggestions'][0]['candidate_index']);
        $this->commitFixture();
        $this->container->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class)
            ->accept($shown['id'], $this->supplier, $this->user, $shown['candidate_index']);
        self::assertSame(1, $this->paymentCount());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id={$other}")->fetchColumn());
    }

    public function testStaleSuggestionCannotAddSecondSettlement(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol=NULL WHERE id={$this->transaction}");
        $this->retry();
        $suggestion = $this->read()['suggestions'][0];
        $this->pdo->exec("UPDATE purchase_invoices SET settled_outside_amount=812.34 WHERE id={$this->invoice}");
        $this->commitFixture();
        try {
            $this->container->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class)
                ->accept((int)$suggestion['id'],$this->supplier,$this->user,(int)$suggestion['candidate_index']);
            self::fail('Zastaralý návrh se nesmí potvrdit.');
        } catch (\MyInvoice\Service\Bank\Match\MatchSuggestionException $e) {
            self::assertSame('candidate_stale',$e->errorCode);
        }
        self::assertSame(0,$this->paymentCount());
    }

    public function testIgnoredPaymentIsNeverUsed(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET match_status='ignored' WHERE id={$this->transaction}");
        $this->retry();
        self::assertSame(0,$this->paymentCount());
        self::assertCount(0,$this->read()['suggestions']);
    }

    public function testOtherSupplierCannotReadOrMatch(): void
    {
        $service = $this->container->get(PurchaseBankMatching::class);
        self::assertSame(['matches'=>[],'suggestions'=>[],'last_attempt'=>null], $service->read($this->supplier+100000,$this->invoice));
        self::assertSame(0,$this->paymentCount());
    }

    public function testMissingUserRightsDoNotTriggerMatching(): void
    {
        $result = $this->container->get(PurchaseBankMatching::class)->retry($this->supplier,$this->invoice,PHP_INT_MAX);
        self::assertSame('forbidden',$result['outcome']);
        self::assertSame(0,$this->paymentCount());
    }

    public function testDraftAndTaxDocumentAreNotPaymentTargets(): void
    {
        $this->pdo->exec("UPDATE purchase_invoices SET status='draft' WHERE id={$this->invoice}");
        self::assertSame('ineligible',$this->retry()['outcome']);
        $this->pdo->exec("UPDATE purchase_invoices SET status='received', document_kind='tax_document' WHERE id={$this->invoice}");
        self::assertSame('ineligible',$this->retry()['outcome']);
        self::assertSame(0,$this->paymentCount());
    }

    public function testAdvanceIsMatchedByExactSymbol(): void
    {
        $this->pdo->exec("UPDATE purchase_invoices SET document_kind='advance' WHERE id={$this->invoice}");
        $this->retry();
        self::assertSame(1,$this->paymentCount());
    }

    public function testCurrencyMismatchCannotCreatePayment(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET currency='EUR' WHERE id={$this->transaction}");
        $this->retry();
        self::assertSame(0,$this->paymentCount());
        self::assertCount(0,$this->read()['suggestions']);
    }

    public function testCandidateLimitNeverAppliesPartialSelection(): void
    {
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,variable_symbol)
            SELECT ?, '2099-06-15',-812.34,'CZK','20990001' FROM seq_1_to_200")->execute([$this->statement]);
        self::assertSame('limited',$this->retry()['outcome']);
        self::assertSame(0,$this->paymentCount());
    }

    public function testRollbackDropsDeferredMatching(): void
    {
        $this->container->get(PurchaseBankMatching::class)->afterReady($this->supplier,$this->invoice,$this->user);
        $this->pdo->rollBack();
        self::assertSame(0,$this->paymentCount());
        $this->pdo->beginTransaction();
        $this->pdo->commit();
        self::assertSame(0,$this->paymentCount());
    }

    public function testReadDoesNotPerformMatching(): void
    {
        $this->read();
        self::assertSame(0,$this->paymentCount());
        self::assertCount(0,$this->read()['suggestions']);
    }

    public function testClosedPeriodPreventsMatching(): void
    {
        $this->pdo->prepare("INSERT INTO accounting_periods(supplier_id,fiscal_year,starts_on,ends_on,status)
            VALUES (?,2099,'2099-01-01','2099-12-31','closed')")->execute([$this->supplier]);
        self::assertSame('locked',$this->retry()['outcome']);
        self::assertSame(0,$this->paymentCount());
    }

    public function testNewDocumentCannotMatchAnUnrelatedInvoice(): void
    {
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id,vendor_id,currency_id,document_kind,status,varsymbol,vendor_invoice_number,
            created_by,vendor_snapshot,received_at,payment_variable_symbol,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat)
            SELECT supplier_id,vendor_id,currency_id,document_kind,status,'PF20990002','TEST-INV-002',created_by,vendor_snapshot,
            received_at,payment_variable_symbol,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat FROM purchase_invoices WHERE id=?")
            ->execute([$this->invoice]);
        $other = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("UPDATE purchase_invoices SET payment_variable_symbol='7777', varsymbol='PF7777' WHERE id={$this->invoice}");
        $this->retry();
        self::assertSame(0,$this->paymentCount());
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id={$other}")->fetchColumn());
        self::assertCount(1,$this->read()['suggestions']);
    }

    public function testTwoInvoicesWithSameSymbolAreNeverAutomaticallyMatched(): void
    {
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id,vendor_id,currency_id,document_kind,status,varsymbol,vendor_invoice_number,
            created_by,vendor_snapshot,received_at,payment_variable_symbol,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat)
            SELECT supplier_id,vendor_id,currency_id,document_kind,status,'PF20990002','TEST-INV-002',created_by,vendor_snapshot,
            received_at,payment_variable_symbol,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat FROM purchase_invoices WHERE id=?")
            ->execute([$this->invoice]);
        $this->retry();
        self::assertSame(0,$this->paymentCount());
        self::assertCount(1,$this->read()['suggestions']);
    }

    public function testCandidateSearchUsesIndexWithLargeHistory(): void
    {
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,variable_symbol)
            SELECT ?, '2080-01-01',-9999,'CZK','00008888' FROM seq_1_to_100000")->execute([$this->statement]);
        $service = $this->container->get(PurchaseBankMatching::class);
        $document = (new \ReflectionMethod($service,'document'))->invoke($service,$this->supplier,$this->invoice);
        [$sql,$params] = (new \ReflectionMethod($service,'candidateQuery'))->invoke($service,$this->supplier,$document);
        $explain = $this->pdo->prepare('EXPLAIN '.$sql);
        $explain->execute($params);
        $plans = array_values(array_filter($explain->fetchAll(PDO::FETCH_ASSOC),static fn(array $r):bool=>$r['table']==='bt'));
        self::assertNotEmpty($plans);
        foreach ($plans as $plan) {
            self::assertNotSame('ALL',$plan['type'],json_encode($plan));
            self::assertNotNull($plan['key'],json_encode($plan));
        }
        self::assertSame('searched',$this->retry()['outcome']);
        self::assertSame(1,$this->paymentCount());
    }

    public function testDetailUsesIndexedStoredSuggestionsWithLargeBankHistory(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol=NULL WHERE id={$this->transaction}");
        $this->retry();
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,variable_symbol)
            SELECT ?, '2080-01-01',-9999,'CZK','00008888' FROM seq_1_to_100000")->execute([$this->statement]);
        $service = $this->container->get(PurchaseBankMatching::class);
        [$sql,$params] = (new \ReflectionMethod($service,'suggestionQuery'))->invoke($service,$this->supplier,$this->invoice);
        $explain = $this->pdo->prepare('EXPLAIN '.$sql);
        $explain->execute($params);
        $plans = $explain->fetchAll(PDO::FETCH_ASSOC);
        foreach ($plans as $plan) {
            if (in_array($plan['table'], ['bank_match_audit','s','bt','bs'], true)) {
                self::assertNotSame('ALL', $plan['type'], json_encode($plan));
                self::assertNotNull($plan['key'], json_encode($plan));
                self::assertLessThan(100, (int) $plan['rows'], json_encode($plan));
            }
        }
        self::assertCount(1, $this->read()['suggestions']);
        self::assertSame(0, $this->paymentCount());
    }

    public function testFiledPeriodAlsoBlocksConfirmationOfExistingSuggestion(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol=NULL WHERE id={$this->transaction}");
        $this->retry();
        $suggestion = $this->read()['suggestions'][0];
        $this->filePeriod();
        $this->commitFixture();
        try {
            $this->container->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class)
                ->accept((int) $suggestion['id'], $this->supplier, $this->user, (int) $suggestion['candidate_index']);
            self::fail('Podané období nelze změnit potvrzením návrhu.');
        } catch (\MyInvoice\Service\Bank\Match\MatchSuggestionException $e) {
            self::assertSame('period_locked', $e->errorCode);
        }
        self::assertSame(0, $this->paymentCount());
    }

    public function testMatchingFailureDoesNotUndoAcceptedDocument(): void
    {
        $matcher = $this->createMock(\MyInvoice\Service\Bank\StatementMatcher::class);
        $matcher->expects(self::once())->method('matchForPurchase')->willThrowException(new \RuntimeException('synthetic failure'));
        $service = new PurchaseBankMatching($this->container->get(Connection::class),$matcher,
            $this->container->get(\MyInvoice\Security\PermissionResolver::class),
            $this->container->get(\MyInvoice\Security\PermissionChecker::class),new \Psr\Log\NullLogger());
        self::assertSame('failed',$service->retry($this->supplier,$this->invoice,$this->user)['outcome']);
        self::assertSame('received',$this->pdo->query("SELECT status FROM purchase_invoices WHERE id={$this->invoice}")->fetchColumn());
        self::assertSame(0,$this->paymentCount());
    }

    public function testActualCashSettlementCannotBePaidAgain(): void
    {
        $this->pdo->prepare("INSERT INTO cash_registers (supplier_id,name) VALUES (?,'TEST Cash')")->execute([$this->supplier]);
        $register = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO cash_documents (supplier_id,register_id,doc_type,purpose,issue_date,description,total_amount,purchase_invoice_id,status)
            VALUES (?,?,'out','purchase_payment','2099-06-15','TEST Payment',812.34,?,'posted')")
            ->execute([$this->supplier,$register,$this->invoice]);
        self::assertSame('ineligible',$this->retry()['outcome']);
        self::assertSame(0,$this->paymentCount());
    }

    public function testConcurrentBankImportAndRetryCreateOnePayment(): void
    {
        $this->commitFixture();
        $workers = [];
        foreach (['bank','retry'] as $mode) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, dirname(__DIR__,2).'/Support/purchase-bank-rematching-worker.php',
                $mode,(string)$this->supplier,(string)$this->invoice,(string)$this->user,(string)$this->transaction],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,3));
            self::assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [$process,$pipes];
        }
        foreach ($workers as [$process,$pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0,proc_close($process),$out.$err);
        }
        self::assertSame(1,$this->paymentCount());
    }

    public function testReceivingWaitsForUncommittedBankImportInsteadOfMissingItsPayment(): void
    {
        $this->pdo->prepare('DELETE FROM bank_transactions WHERE id=?')->execute([$this->transaction]);
        $this->commitFixture();
        $pipes = [];
        $process = proc_open([PHP_BINARY, dirname(__DIR__,2).'/Support/purchase-bank-rematching-worker.php',
            'late_bank',(string)$this->supplier,(string)$this->invoice,(string)$this->user,(string)$this->transaction,(string)$this->statement],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,3));
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            self::assertSame('READY', rtrim((string) fgets($pipes[1]), "\r\n"));
            $result = $this->retry();
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            self::assertSame(0,proc_close($process),$out.$err);
            $process = null;
            self::assertSame(1,$result['matched_count']);
            self::assertSame(1,$this->paymentCount());
        } finally {
            foreach (array_slice($pipes,1) as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }

    public function testEndpointRequiresBankReadAndRetryRejectsBearer(): void
    {
        $action = $this->container->get(\MyInvoice\Action\PurchaseInvoice\PurchaseBankMatchingAction::class);
        $factory = new \Slim\Psr7\Factory\ServerRequestFactory();
        $request = $factory->createServerRequest('GET','/api/purchase-invoices/'.$this->invoice.'/bank-matching')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,'session')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$this->user,'role'=>'admin'])
            ->withAttribute('auth.effective_role',new \MyInvoice\Security\EffectiveRole(1,'TEST','staff',true,['purchase_invoices'=>1]));
        self::assertSame(403,$action($request,new \Slim\Psr7\Response(),['id'=>$this->invoice])->getStatusCode());
        $request = $request->withAttribute('auth.effective_role',new \MyInvoice\Security\EffectiveRole(1,'TEST','staff',true,['purchase_invoices'=>1,'bank'=>1]));
        self::assertSame(200,$action($request,new \Slim\Psr7\Response(),['id'=>$this->invoice])->getStatusCode());
        $request = $request->withMethod('POST')->withAttribute('auth.effective_role',new \MyInvoice\Security\EffectiveRole(1,'TEST','superadmin',true,[],'superadmin'))
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,'bearer');
        self::assertSame(403,$action($request,new \Slim\Psr7\Response(),['id'=>$this->invoice])->getStatusCode());
        self::assertSame(403,$action($request->withMethod('GET'),new \Slim\Psr7\Response(),['id'=>$this->invoice])->getStatusCode());
        self::assertSame(0,$this->paymentCount());
    }

    public static function legacyBankImportCases(): iterable
    {
        yield 'receipt' => ['receipt', 'received', false, 0.0, false];
        yield 'paid without payment' => ['invoice', 'paid', false, 0.0, false];
        yield 'rounding and issue proximity' => ['invoice', 'received', false, 0.49, true];
        yield 'assigned cash register' => ['invoice', 'received', true, 0.0, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyBankImportCases')]
    public function testOrdinaryBankImportKeepsOriginalCandidatesAndScoring(string $kind, string $status, bool $cash, float $rounding, bool $expected): void
    {
        $register = null;
        if ($cash) {
            $this->pdo->prepare("INSERT INTO cash_registers (supplier_id,name) VALUES (?,'TEST cash preference')")->execute([$this->supplier]);
            $register = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare("UPDATE purchase_invoices SET document_kind=?, status=?, cash_register_id=?, rounding=?, due_date='2099-07-15' WHERE id=?")
            ->execute([$kind, $status, $register, $rounding, $this->invoice]);
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol=NULL WHERE id={$this->transaction}");
        $tx = $this->pdo->query("SELECT * FROM bank_transactions WHERE id={$this->transaction}")->fetch(PDO::FETCH_ASSOC);
        $candidates = $this->container->get(MatchCandidateProvider::class)->candidatesFor($this->supplier, $tx);
        self::assertCount($expected ? 1 : 0, $candidates);
        if ($expected) {
            self::assertSame(812.34, $candidates[0]['display']['amount']);
            self::assertArrayNotHasKey('due_proximity', $candidates[0]['signals']);
            self::assertSame(0.4, $candidates[0]['score']);
        }
        self::assertSame(0, $this->paymentCount());
    }

    public function testDiagnosticSearchDoesNotTouchInvoiceModificationTime(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol=NULL, amount=-9999 WHERE id={$this->transaction}");
        $this->pdo->exec("UPDATE purchase_invoices SET updated_at='2090-01-01 00:00:00' WHERE id={$this->invoice}");
        $this->retry();
        self::assertSame('2090-01-01 00:00:00', $this->pdo->query("SELECT updated_at FROM purchase_invoices WHERE id={$this->invoice}")->fetchColumn());
        self::assertSame('searched', $this->read()['last_attempt']['outcome']);
    }

    public function testForeignDocumentCannotOverwriteDiagnosticAttempt(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol=NULL, amount=-9999 WHERE id={$this->transaction}");
        $this->retry();
        $before = $this->read()['last_attempt'];
        $this->container->get(PurchaseBankMatching::class)->retry($this->supplier + 100000, $this->invoice, $this->user);
        self::assertSame($before, $this->read()['last_attempt']);
    }

    public function testAllStatementsActuallyMatchedAreRecounted(): void
    {
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date)
            VALUES (?,'second-synthetic.json',?,?,'0100','CZK','2099-06-15')")
            ->execute([$this->supplier,hash('sha256','second-'.$this->supplier),$this->account]);
        $second = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,variable_symbol)
            VALUES (?,'2099-06-15',-812.34,'CZK','20990001')")->execute([$second]);
        $secondTx = (int) $this->pdo->lastInsertId();
        $matcher = $this->createMock(\MyInvoice\Service\Bank\StatementMatcher::class);
        $matcher->expects(self::exactly(2))->method('matchForPurchase')->willReturnCallback(function (int $supplier, int $invoice, array $tx) use ($secondTx): array {
            if ((int) $tx['id'] !== $secondTx) return ['status' => 'unmatched'];
            $this->pdo->prepare("UPDATE bank_transactions SET match_status='auto_exact' WHERE id=?")->execute([$secondTx]);
            return ['status' => 'auto_exact'];
        });
        $service = new PurchaseBankMatching($this->container->get(Connection::class), $matcher,
            $this->container->get(\MyInvoice\Security\PermissionResolver::class),
            $this->container->get(\MyInvoice\Security\PermissionChecker::class), new \Psr\Log\NullLogger());
        self::assertSame(1, $service->retry($this->supplier,$this->invoice,$this->user)['matched_count']);
        self::assertSame(1, (int) $this->pdo->query("SELECT matched_count FROM bank_statements WHERE id={$second}")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT matched_count FROM bank_statements WHERE id={$this->statement}")->fetchColumn());
    }

    public function testFiledPeriodBlocksOriginalDialogConfirmationOfNewProposal(): void
    {
        $this->pdo->exec("UPDATE bank_transactions SET variable_symbol=NULL WHERE id={$this->transaction}");
        $this->retry();
        self::assertCount(1, $this->read()['suggestions']);
        $this->filePeriod();
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/api/bank-transactions/'.$this->transaction.'/match-document')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,'session')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$this->user,'role'=>'admin'])
            ->withAttribute('auth.effective_role',new \MyInvoice\Security\EffectiveRole(1,'TEST','superadmin',true,[],'superadmin'))
            ->withParsedBody(['purchase_invoice_id'=>$this->invoice]);
        $response = $this->container->get(\MyInvoice\Action\Bank\BankPaymentCandidatesAction::class)
            ->match($request,new \Slim\Psr7\Response(),['id'=>$this->transaction]);
        self::assertSame(409,$response->getStatusCode(),(string)$response->getBody());
        self::assertSame(0,$this->paymentCount());
    }

    public function testFinalTaxEvidenceYearPreventsRematching(): void
    {
        $this->pdo->prepare("INSERT INTO tax_evidence_closings (supplier_id,year,status,checklist,source_snapshot,source_hash,row_version,finalized_at,finalized_by)
            VALUES (?,2099,'final','{}','{}',?,1,NOW(),?)")->execute([$this->supplier,hash('sha256','{}'),$this->user]);
        self::assertSame('locked', $this->retry()['outcome']);
        self::assertSame(0, $this->paymentCount());
    }

    public function testFiledVatPeriodPreventsRematching(): void
    {
        $this->filePeriod();
        self::assertSame('locked', $this->retry()['outcome']);
        self::assertSame(0, $this->paymentCount());
    }

    private function filePeriod(): void
    {
        $this->pdo->prepare("INSERT INTO tax_submissions (supplier_id,form_code,period_year,period_month,form_variant,
            xml_content,xml_size_bytes,xml_sha256,validation_status,status,submitted_at,summary_json)
            VALUES (?,'dphdp3',2099,6,'B','<test/>',7,?,'passed','submitted',NOW(),'{}')")
            ->execute([$this->supplier,hash('sha256','synthetic')]);
    }

    private function paymentCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id={$this->invoice}")->fetchColumn();
    }
}
