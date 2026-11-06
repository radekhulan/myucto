<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\FxSettlementEvidenceRepository;
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder, PostingService};
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\TestCase;

final class FxSettlementChannelsTest extends TestCase
{
    private Connection $db;
    private int $sid;
    private int $pid;
    private int $user;
    private bool $committed = false;
    private ?\Psr\Container\ContainerInterface $other = null;
    private ?int $snapshotIsolation = null;

    protected function setUp(): void
    {
        $c=Bootstrap::buildContainer();
        $this->db=$c->get(Connection::class);
        $pdo=$this->db->pdo();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic FX channels','Test1','Test','10000','fx-channels@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->sid=(int)$pdo->lastInsertId();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->sid);
        $this->pid=$c->get(AccountingPeriodRepository::class)->create($this->sid,2098,'2098-01-01','2098-12-31');
        $this->user=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2098-06-15','EUR',24),('2098-09-15','EUR',24),('2098-12-31','EUR',25) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if ($this->other !== null) $this->other->get(Connection::class)->close();
        if ($this->committed) {
            foreach (['activity_log', 'journal_entries', 'invoice_settlements', 'invoice_payments', 'bank_statements', 'invoices', 'purchase_invoices', 'clients', 'currencies'] as $table) {
                $this->db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->sid]);
            }
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->sid]);
        }
        if ($this->snapshotIsolation !== null) $this->db->pdo()->exec('SET SESSION innodb_snapshot_isolation=' . $this->snapshotIsolation);
    }

    public static function channels(): iterable
    {
        foreach (['invoice','purchase_invoice'] as $type) {
            foreach ($type === 'invoice' ? ['bank'] : ['bank','settlement'] as $channel) {
                foreach ([500,1000] as $amount) yield $type.'_'.$channel.'_'.$amount => [$type,$channel,$amount];
            }
        }
        foreach ([500,1000] as $amount) yield 'invoice_manual_'.$amount => ['invoice','manual',$amount];
    }

    public static function manualHeaderCases(): iterable
    {
        foreach (['invoice', 'purchase_invoice'] as $type) {
            foreach (['partial', 'full', 'czk', 'future', 'reversed', 'full_unpaid_header'] as $mode) yield $type . '_' . $mode => [$type, $mode];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('manualHeaderCases')]
    public function testLinkedManualEvidencePrecedesPaidHeader(string $type, string $mode): void
    {
        $id = $this->document($type);
        $c = Bootstrap::buildContainer();
        $pdo = $this->db->pdo();
        $foreign = str_starts_with($mode, 'full') ? 1000 : 500;
        $amount = $mode === 'czk' ? 240 : $foreign * 24;
        $line = ['account_code' => $type === 'invoice' ? '311' : '321', 'side' => $type === 'invoice' ? 'credit' : 'debit', 'amount' => $amount];
        if ($mode !== 'czk') $line += ['currency_code' => 'EUR', 'fx_rate' => 24, 'amount_foreign' => $foreign];
        $entry = $c->get(PostingService::class)->postDocument($this->sid, 'manual', null, [$line, ['account_code' => '395', 'side' => $type === 'invoice' ? 'debit' : 'credit', 'amount' => $amount]], ['entry_date' => '2098-09-15', 'posted_by' => $this->user]);
        $c->get(\MyInvoice\Repository\JournalEntryDocumentLinkRepository::class)->add($entry, $this->sid, $type, $id, null, $this->user);
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        if ($mode !== 'full_unpaid_header') $pdo->prepare("UPDATE {$table} SET paid_at='2098-08-01',status='paid' WHERE supplier_id=? AND id=?")->execute([$this->sid, $id]);
        if ($mode === 'reversed') $c->get(PostingService::class)->reverse($this->sid, $entry, ['entry_date' => '2098-10-01']);
        $asOf = $mode === 'future' ? '2098-08-31' : '2098-12-31';
        $evidence = $this->evidence($type, $id, $asOf);
        self::assertTrue($evidence['complete'], json_encode($evidence['findings']));
        $expected = match ($mode) { 'partial' => '500.00', 'full', 'full_unpaid_header' => '0.00', default => '1000.00' };
        self::assertSame($expected, $evidence['remaining_foreign']);
        self::assertSame(match ($mode) { 'partial' => '12000.00', 'full', 'full_unpaid_header' => '0.00', 'czk' => '23760.00', default => '24000.00' }, $evidence['booked_czk']);
        self::assertFalse($evidence['legacy_paid_status']);
        if ($mode === 'full') self::assertNotContains('payment_header_divergence', $evidence['diagnostics']);
        else self::assertContains('payment_header_divergence', $evidence['diagnostics']);
        if ($mode === 'reversed') {
            $before = $this->evidence($type, $id, '2098-09-30');
            self::assertTrue($before['complete']);
            self::assertSame('500.00', $before['remaining_foreign']);
        }
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['native'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['legacy_invoice'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['legacy_purchase'])]
    public function testFutureCurrencyConflictDoesNotChangeHistoricalBalance(string $representation): void
    {
        $type = $representation === 'legacy_purchase' ? 'purchase_invoice' : 'invoice';
        $id = $this->document($type);
        $pdo = $this->db->pdo();
        if ($representation === 'native') {
            $pdo->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source) VALUES (?,?,'2098-09-15',500,'USD','manual')")->execute([$this->sid,$id]);
        } else {
            $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES (?,'synthetic-future.csv',?,'1000000005','0100','USD','2098-09-15',?)")->execute([$this->sid,hash('sha256','future-'.$this->sid),$this->user]);
            $statement=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_transactions(statement_id,source,posted_at,amount,currency,description,match_status) VALUES (?,'statement','2098-09-15',500,'USD','Synthetic future payment','manual')")->execute([$statement]);
            $bank=(int)$pdo->lastInsertId();
            $column=$type==='invoice'?'invoice_id':'purchase_invoice_id';
            $pdo->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,{$column},amount,match_type) VALUES (?,?,?,500,'manual')")->execute([$this->sid,$bank,$id]);
        }
        $before = $this->evidence($type,$id,'2098-08-31');
        self::assertTrue($before['complete'],json_encode($before['findings']));
        self::assertSame('1000.00',$before['remaining_foreign']);
        self::assertSame('24000.00',$before['booked_czk']);
        $after = $this->evidence($type,$id,'2098-09-30');
        self::assertFalse($after['complete']);
        self::assertContains($representation==='native'?'payment_currency_conflict':'legacy_payment_currency_unproven',$after['findings']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice'])]
    public function testManualCurrencyFindingFollowsActualAsOfAndReversal(string $type): void
    {
        $id=$this->document($type);
        $c=Bootstrap::buildContainer();
        $entry=$c->get(PostingService::class)->postDocument($this->sid,'manual',null,[
            ['account_code'=>$type==='invoice'?'311':'321','side'=>$type==='invoice'?'credit':'debit','amount'=>12000,'currency_code'=>'USD','amount_foreign'=>500,'fx_rate'=>24],
            ['account_code'=>'395','side'=>$type==='invoice'?'debit':'credit','amount'=>12000],
        ],['entry_date'=>'2098-09-15','posted_by'=>$this->user]);
        $c->get(\MyInvoice\Repository\JournalEntryDocumentLinkRepository::class)->add($entry,$this->sid,$type,$id,null,$this->user);
        $c->get(PostingService::class)->reverse($this->sid,$entry,['entry_date'=>'2098-10-01']);
        foreach (['2098-08-31','2098-10-31'] as $asOf) {
            $evidence=$this->evidence($type,$id,$asOf);
            self::assertTrue($evidence['complete'],json_encode($evidence['findings']));
            self::assertSame('1000.00',$evidence['remaining_foreign']);
        }
        $effective=$this->evidence($type,$id,'2098-09-30');
        self::assertFalse($effective['complete']);
        self::assertContains('manual_currency_conflict',$effective['findings']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice'])]
    public function testCurrentManualLifecycleOverridesStaleHeaderSnapshot(string $type): void
    {
        $id=$this->document($type);
        $table=$type==='invoice'?'invoices':'purchase_invoices';
        $this->db->pdo()->prepare("UPDATE {$table} SET status='paid',paid_at='2098-08-01' WHERE supplier_id=? AND id=?")->execute([$this->sid,$id]);
        $this->snapshot();
        self::assertSame('0.00',$this->evidence($type,$id,'2098-12-31')['remaining_foreign']);
        $entry=$this->other->get(PostingService::class)->postDocument($this->sid,'manual',null,[
            ['account_code'=>$type==='invoice'?'311':'321','side'=>$type==='invoice'?'credit':'debit','amount'=>12000,'currency_code'=>'EUR','amount_foreign'=>500,'fx_rate'=>24],
            ['account_code'=>'395','side'=>$type==='invoice'?'debit':'credit','amount'=>12000],
        ],['entry_date'=>'2098-09-15','posted_by'=>$this->user]);
        $this->other->get(\MyInvoice\Repository\JournalEntryDocumentLinkRepository::class)->add($entry,$this->sid,$type,$id,null,$this->user);
        $current=$this->evidence($type,$id,'2098-12-31',true);
        self::assertTrue($current['complete'],json_encode($current['findings']));
        self::assertSame('500.00',$current['remaining_foreign']);
        self::assertContains('payment_header_divergence',$current['diagnostics']);
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame('0.00',$this->evidence($type,$id,'2098-12-31')['remaining_foreign']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice','native'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['invoice','legacy'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice','legacy'])]
    public function testCurrencyFindingIsRemovedByNativeBankUnmatch(string $type, string $representation): void
    {
        $id=$this->document($type);
        $bank=$this->settle($type,$id,'bank',500);
        $pdo=$this->db->pdo();
        if ($representation==='native') $pdo->prepare("UPDATE invoice_payments SET currency='USD' WHERE supplier_id=? AND bank_transaction_id=?")->execute([$this->sid,$bank]);
        else {
            $pdo->prepare("UPDATE bank_transactions SET currency='USD' WHERE id=?")->execute([$bank]);
            if ($type==='invoice') $pdo->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,invoice_id,amount,match_type) VALUES (?,?,?,500,'manual')")->execute([$this->sid,$bank,$id]);
        }
        self::assertTrue($this->evidence($type,$id,'2098-08-31')['complete']);
        $effective=$this->evidence($type,$id,'2098-09-30');
        self::assertFalse($effective['complete']);
        self::assertContains($representation==='native'?'payment_currency_conflict':'legacy_payment_currency_unproven',$effective['findings']);
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Bank\BankTransactionReleaseService::class)->release($this->sid,$bank,\MyInvoice\Service\Bank\BankTransactionReleaseService::MODE_UNMATCH,$this->user);
        $after=$this->evidence($type,$id,'2098-12-31');
        self::assertTrue($after['complete'],json_encode($after['findings']));
        self::assertSame('1000.00',$after['remaining_foreign']);
        self::assertSame('24000.00',$after['booked_czk']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('channels')]
    public function testNativeChannelKeepsDocumentCurrencySeparateFromCzk(string $type,string $channel,int $amount): void
    {
        $id=$this->document($type);
        $this->settle($type,$id,$channel,$amount);
        $before=$this->evidence($type,$id,'2098-08-31');
        $after=$this->evidence($type,$id,'2098-12-31');
        self::assertTrue($before['complete'],json_encode($before));
        self::assertSame('1000.00',$before['remaining_foreign']);
        self::assertTrue($after['complete'],json_encode($after));
        self::assertSame(number_format(1000-$amount,2,'.',''),$after['remaining_foreign']);
        self::assertSame(number_format($amount,2,'.',''),$after['settled_document']);
        self::assertSame('0.00',$after['manual_settlement_czk']);
        $preview=Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->sid,$this->pid);
        self::assertSame((float)(1000-$amount),$preview['totals'][$type==='invoice'?'gain':'loss']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice','cash','foreign_currency_invoice'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice','cash','foreign_currency_invoice'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['invoice','settlement','foreign_currency'])]
    public function testNativeUnsupportedChannelFailsExplicitly(string $type,string $channel,string $code): void
    {
        $id=$this->document($type);
        try {
            $this->settle($type,$id,$channel,500);
            self::fail('Native unsupported channel must not post.');
        } catch (\MyInvoice\Service\Accounting\Cash\CashException|\MyInvoice\Service\Accounting\SettlementException $e) {
            self::assertSame($code,$e->errorCode);
        }
        self::assertSame('1000.00',$this->evidence($type,$id,'2098-12-31')['remaining_foreign']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice','bank'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['invoice','manual'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice','settlement'])]
    public function testNativePartialPreview(string $type,string $channel): void
    {
        $id=$this->document($type);
        $this->settle($type,$id,$channel,500);
        $preview=Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->sid,$this->pid);
        self::assertSame(500.0,$preview['saldo']['detail'][0]['remaining_foreign']);
        self::assertSame(500.0,$preview['totals'][$type==='invoice'?'gain':'loss']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith([500, '2098-08-01', '2098-12-31', '500.00'])]
    #[\PHPUnit\Framework\Attributes\TestWith([1000, '2098-12-31', '2098-10-01', '0.00'])]
    #[\PHPUnit\Framework\Attributes\TestWith([1000, '2098-08-01', '2098-08-31', '1000.00'])]
    public function testPaymentMembersOverrideConflictingHeader(int $amount, string $paidAt, string $asOf, string $remaining): void
    {
        $id = $this->document('invoice');
        $this->settle('invoice', $id, 'bank', $amount);
        $this->db->pdo()->prepare("UPDATE invoices SET status='paid',paid_at=? WHERE supplier_id=? AND id=?")
            ->execute([$paidAt, $this->sid, $id]);
        $evidence = $this->evidence('invoice', $id, $asOf);
        self::assertTrue($evidence['complete'], json_encode($evidence));
        self::assertSame($remaining, $evidence['remaining_foreign']);
        self::assertFalse($evidence['legacy_paid_status']);
        self::assertContains('payment_header_divergence', $evidence['diagnostics'] ?? []);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['issued'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['paid'])]
    public function testLegacyPaidDateRemainsAuthoritativeWithoutMembers(string $status): void
    {
        $id = $this->document('invoice');
        $this->db->pdo()->prepare('UPDATE invoices SET status=?,paid_at=? WHERE supplier_id=? AND id=?')
            ->execute([$status, '2098-09-15', $this->sid, $id]);
        self::assertSame('1000.00', $this->evidence('invoice', $id, '2098-08-31')['remaining_foreign']);
        $evidence = $this->evidence('invoice', $id, '2098-12-31');
        self::assertTrue($evidence['complete']);
        self::assertTrue($evidence['legacy_paid_status']);
        self::assertSame('0.00', $evidence['remaining_foreign']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice', false, 1000.0, 1240.0])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice', false, 1000.0, 1240.0])]
    #[\PHPUnit\Framework\Attributes\TestWith(['invoice', true, 500.0, 500.0])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice', true, 500.0, 500.0])]
    public function testLinkedManualCzkAndForeignAreIndependent(string $type, bool $foreign, float $remaining, float $difference): void
    {
        $id = $this->document($type);
        $amount = $foreign ? '12000.00' : '240.00';
        $line = ['account_code' => $type === 'invoice' ? '311' : '321', 'side' => $type === 'invoice' ? 'credit' : 'debit', 'amount' => $amount];
        if ($foreign) $line += ['currency_code' => 'EUR', 'fx_rate' => 24, 'amount_foreign' => '500.00'];
        $c = Bootstrap::buildContainer();
        $entry = $c->get(PostingService::class)->postDocument($this->sid, 'manual', null, [
            $line, ['account_code' => '395', 'side' => $type === 'invoice' ? 'debit' : 'credit', 'amount' => $amount],
        ], ['entry_date' => '2098-09-15', 'posted_by' => $this->user, 'user_id' => $this->user]);
        $c->get(\MyInvoice\Repository\JournalEntryDocumentLinkRepository::class)->add($entry, $this->sid, $type, $id, null, $this->user);
        $preview = $c->get(ClosingService::class)->fxPreview($this->sid, $this->pid);
        self::assertSame($remaining, $preview['saldo']['detail'][0]['remaining_foreign']);
        self::assertSame($difference, $preview['totals'][$type === 'invoice' ? 'gain' : 'loss']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice', 'bank'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice', 'settlement'])]
    public function testCurrentPaymentMembershipDoesNotReuseCallerSnapshot(string $type, string $channel): void
    {
        $id = $this->document($type);
        $this->snapshot();
        self::assertSame('1000.00', $this->evidence($type, $id, '2098-12-31')['remaining_foreign']);
        $this->settle($type, $id, $channel, 500, $this->other);
        self::assertSame('1000.00', $this->evidence($type, $id, '2098-12-31')['remaining_foreign']);
        self::assertSame('500.00', $this->evidence($type, $id, '2098-12-31', true)['remaining_foreign']);
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame('1000.00', $this->evidence($type, $id, '2098-12-31')['remaining_foreign']);
    }

    private function financialSnapshot(): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_document_series', 'accounting_closing_steps', 'accounting_periods', 'activity_log'] as $table) {
            $q = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=? ORDER BY id');
            $q->execute([$this->sid]);
            $out[$table] = $q->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $out;
    }

    private function snapshot(): void
    {
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->committed = true;
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
        $this->other = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
        Connection::withoutSharedTestConnection(fn () => $this->other->get(Connection::class));
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $pdo->query('SELECT COUNT(*) FROM invoice_payments')->fetchColumn();
    }

    #[\PHPUnit\Framework\Attributes\TestWith([500])]
    #[\PHPUnit\Framework\Attributes\TestWith([1000])]
    public function testManuallyPostedAdvanceUsesRemainingDocumentCurrency(int $paid): void
    {
        $this->advanceCycle($paid);
        $preview = Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->sid, $this->pid);
        if ($paid === 1000) self::assertSame([], $preview['saldo']['detail']);
        else self::assertSame(500.0, $preview['saldo']['detail'][0]['remaining_foreign']);
        self::assertSame((float) (1000 - $paid), $preview['totals']['gain']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith([500])]
    #[\PHPUnit\Framework\Attributes\TestWith([1000])]
    public function testPostedPurchaseAdvanceUsesRemainingDocumentCurrency(int $paid): void
    {
        $parent = $this->document('purchase_invoice', false);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind='advance',varsymbol='2098000903' WHERE supplier_id=? AND id=?")->execute([$this->sid, $parent]);
        $bank = $this->settle('purchase_invoice', $parent, 'bank', $paid, null, true);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->postDocument($this->sid, 'bank', $bank, [
            ['account_code' => '314', 'side' => 'debit', 'amount' => $paid * 24],
            ['account_code' => '221', 'side' => 'credit', 'amount' => $paid * 24, 'currency_code' => 'EUR', 'amount_foreign' => $paid, 'fx_rate' => 24],
        ], ['entry_date' => '2098-09-15', 'posted' => true]);
        $final = $this->document('purchase_invoice', false);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET advance_purchase_invoice_id=?,advance_paid_amount=?,issue_date='2098-09-15',tax_date='2098-09-15',received_at='2098-09-15' WHERE supplier_id=? AND id=?")
            ->execute([$parent, $paid, $this->sid, $final]);
        $posting->postDocument($this->sid, 'purchase_invoice', $final, $posting->buildFromPurchaseInvoice($this->sid, $final), ['entry_date' => '2098-09-15', 'posted' => true]);
        $evidence = $this->evidence('purchase_invoice', $final, '2098-12-31');
        self::assertTrue($evidence['complete'], json_encode($evidence));
        self::assertSame(number_format(1000 - $paid, 2, '.', ''), $evidence['remaining_foreign']);
        self::assertSame(number_format($paid * 24, 2, '.', ''), $evidence['advance_evidence']['applied_czk']);
        $preview = Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->sid, $this->pid);
        self::assertSame((float) (1000 - $paid), $preview['totals']['loss']);
    }

    public function testAdvanceForeignAmountComesFromReceiptAndActualCzkFromLedger(): void
    {
        [, $final, $bank] = $this->advanceCycle(500);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->postDocument($this->sid, 'bank', $bank, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 12500, 'currency_code' => 'EUR', 'amount_foreign' => 500, 'fx_rate' => 25],
            ['account_code' => '324', 'side' => 'credit', 'amount' => 12500],
        ], ['entry_date' => '2098-09-15', 'posted' => true]);
        $lines = $posting->buildFromInvoice($this->sid, $final);
        foreach ($lines as &$line) {
            if (($line['account_code'] === '311' && $line['side'] === 'credit') || $line['account_code'] === '324') $line['amount'] = 12500;
        }
        unset($line);
        $posting->postDocument($this->sid, 'invoice', $final, $lines, ['entry_date' => '2098-09-15', 'posted' => true]);
        $evidence = $this->evidence('invoice', $final, '2098-12-31');
        self::assertTrue($evidence['complete'], json_encode($evidence));
        self::assertSame('500.00', $evidence['remaining_foreign']);
        self::assertSame('11500.00', $evidence['booked_czk']);
    }

    public function testAdvanceSourceCannotBeClaimedTwiceAcrossDifferentParents(): void
    {
        [, $final, $bank] = $this->advanceCycle(500);
        $otherParent = $this->invoice($this->sid, 24, '2098-06-15', '2098000991', false, 'proforma');
        $this->db->pdo()->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source,bank_transaction_id) VALUES (?,?,'2098-09-15',500,'EUR','bank',?)")
            ->execute([$this->sid, $otherParent, $bank]);
        $evidence = $this->evidence('invoice', $final, '2098-12-31');
        self::assertFalse($evidence['complete']);
        self::assertContains('advance_receipt_pool_conflict', $evidence['findings']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith([24.0, true])]
    #[\PHPUnit\Framework\Attributes\TestWith([0.01, false])]
    public function testAdvanceTaxDocumentDrawUsesPostedCurrencyUnits(float $rate, bool $unique): void
    {
        $pdo = $this->db->pdo();
        $parent = $this->invoice($this->sid, $rate, '2098-06-15', '2098000970', false, 'proforma');
        $pdo->prepare('UPDATE invoices SET total_without_vat=1000,total_vat=210,total_with_vat=1210 WHERE id=?')->execute([$parent]);
        $pdo->prepare("UPDATE invoice_items SET vat_rate_id=(SELECT id FROM vat_rates WHERE country='CZ' AND rate_percent=21 AND is_reverse_charge=0 ORDER BY id LIMIT 1),vat_rate_snapshot=21,total_vat=210,total_with_vat=1210 WHERE invoice_id=?")->execute([$parent]);
        $bank = $this->settle('invoice', $parent, 'bank', 605, null, true);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->postDocument($this->sid, 'bank', $bank, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 605 * $rate, 'currency_code' => 'EUR', 'amount_foreign' => 605, 'fx_rate' => $rate],
            ['account_code' => '324', 'side' => 'credit', 'amount' => 605 * $rate],
        ], ['entry_date' => '2098-09-15', 'posted' => true]);
        $tax = $this->invoice($this->sid, $rate, '2098-09-15', '2098000971', false, 'tax_document');
        $pdo->prepare('UPDATE invoices SET parent_invoice_id=?,total_without_vat=500,total_vat=105,total_with_vat=605 WHERE id=?')->execute([$parent, $tax]);
        $pdo->prepare("UPDATE invoice_items SET vat_rate_id=(SELECT id FROM vat_rates WHERE country='CZ' AND rate_percent=21 AND is_reverse_charge=0 ORDER BY id LIMIT 1),vat_rate_snapshot=21,unit_price_without_vat=500,total_without_vat=500,total_vat=105,total_with_vat=605 WHERE invoice_id=?")->execute([$tax]);
        $posting->postDocument($this->sid, 'invoice', $tax, $posting->buildFromInvoice($this->sid, $tax), ['entry_date' => '2098-09-15', 'posted' => true]);
        $final = $this->invoice($this->sid, $rate, '2098-09-15', '2098000972', false);
        $pdo->prepare('UPDATE invoices SET parent_invoice_id=?,total_without_vat=500,total_vat=105,total_with_vat=605 WHERE id=?')->execute([$parent, $final]);
        $pdo->prepare("UPDATE invoice_items SET vat_rate_id=(SELECT id FROM vat_rates WHERE country='CZ' AND rate_percent=21 AND is_reverse_charge=0 ORDER BY id LIMIT 1),vat_rate_snapshot=21,total_vat=210,total_with_vat=1210 WHERE invoice_id=?")->execute([$final]);
        $pdo->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,'Synthetic advance deduction',1,'ks',-500,(SELECT id FROM vat_rates WHERE country='CZ' AND rate_percent=21 AND is_reverse_charge=0 ORDER BY id LIMIT 1),21,-500,-105,-605,1)")->execute([$final]);
        $posting->postDocument($this->sid, 'invoice', $final, $posting->buildFromInvoice($this->sid, $final), ['entry_date' => '2098-09-15', 'posted' => true]);
        $evidence = $this->evidence('invoice', $final, '2098-12-31');
        if (!$unique) {
            self::assertFalse($evidence['complete']);
            self::assertContains('advance_tax_valuation_unproven', $evidence['findings']);
            return;
        }
        self::assertTrue($evidence['complete'], json_encode($evidence));
        self::assertSame('605.00', $evidence['remaining_foreign']);
        self::assertSame('14520.00', $evidence['booked_czk']);
        $preview = Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->sid, $this->pid);
        self::assertSame(605.0, $preview['totals']['gain']);
    }

    public function testAdvanceRefundReducesAvailableReceiptProof(): void
    {
        [$parent, $final] = $this->advanceCycle(1000);
        $pdo = $this->db->pdo();
        $statement = (int) $pdo->query('SELECT id FROM bank_statements WHERE supplier_id=' . $this->sid)->fetchColumn();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,source,posted_at,amount,currency,description,match_status) VALUES (?,'statement','2098-10-01',-500,'EUR','Synthetic advance refund','manual')")->execute([$statement]);
        $bank = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,invoice_id,amount,match_type) VALUES (?,?,?,500,'manual')")->execute([$this->sid, $bank, $parent]);
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->sid, 'bank', $bank, [
            ['account_code' => '324', 'side' => 'debit', 'amount' => 12000],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 12000, 'currency_code' => 'EUR', 'amount_foreign' => 500, 'fx_rate' => 24],
        ], ['entry_date' => '2098-10-01', 'posted' => true]);
        self::assertTrue($this->evidence('invoice', $final, '2098-09-30')['complete']);
        $after = $this->evidence('invoice', $final, '2098-12-31');
        self::assertFalse($after['complete']);
        self::assertContains('advance_settlement_exceeds_receipts', $after['findings']);
    }

    public function testCurrentAdvancePoolMembershipDoesNotReuseSnapshot(): void
    {
        [, $final, $bank] = $this->advanceCycle(500);
        $otherParent = $this->invoice($this->sid, 24, '2098-06-15', '2098000991', false, 'proforma');
        $this->snapshot();
        self::assertTrue($this->evidence('invoice', $final, '2098-12-31')['complete']);
        $this->other->get(Connection::class)->pdo()->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source,bank_transaction_id) VALUES (?,?,'2098-09-15',500,'EUR','bank',?)")
            ->execute([$this->sid, $otherParent, $bank]);
        self::assertTrue($this->evidence('invoice', $final, '2098-12-31')['complete']);
        $current = $this->evidence('invoice', $final, '2098-12-31', true);
        self::assertFalse($current['complete']);
        self::assertContains('advance_receipt_pool_conflict', $current['findings']);
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testCurrentLinkedManualSettlementDoesNotReuseCallerSnapshot(): void
    {
        $id = $this->document('invoice');
        $this->snapshot();
        self::assertSame('1000.00', $this->evidence('invoice', $id, '2098-12-31')['remaining_foreign']);
        $entry = $this->other->get(PostingService::class)->postDocument($this->sid, 'manual', null, [
            ['account_code' => '311', 'side' => 'credit', 'amount' => 12000, 'currency_code' => 'EUR', 'amount_foreign' => 500, 'fx_rate' => 24],
            ['account_code' => '395', 'side' => 'debit', 'amount' => 12000],
        ], ['entry_date' => '2098-09-15', 'posted' => true]);
        $this->other->get(\MyInvoice\Repository\JournalEntryDocumentLinkRepository::class)->add($entry, $this->sid, 'invoice', $id, null, $this->user);
        self::assertSame('1000.00', $this->evidence('invoice', $id, '2098-12-31')['remaining_foreign']);
        $current = $this->evidence('invoice', $id, '2098-12-31', true);
        self::assertTrue($current['complete'], json_encode($current));
        self::assertSame('500.00', $current['remaining_foreign']);
        self::assertSame('12000.00', $current['booked_czk']);
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testReadOnlyPreviewHasNoFinancialWrites(): void
    {
        $id = $this->document('invoice');
        $this->settle('invoice', $id, 'bank', 500);
        $this->db->pdo()->commit();
        $this->committed = true;
        // Až po commitu: ten auditní stopu zapečetí, náhled pak už nesmí měnit nic.
        $before = $this->financialSnapshot();
        self::assertSame('500.00', $this->evidence('invoice', $id, '2098-12-31')['remaining_foreign']);
        self::assertFalse($this->db->pdo()->inTransaction());
        $this->db->pdo()->exec('SET TRANSACTION READ ONLY');
        $this->db->pdo()->beginTransaction();
        $preview = Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->sid, $this->pid);
        self::assertSame(500.0, $preview['saldo']['detail'][0]['remaining_foreign']);
        self::assertSame($before, $this->financialSnapshot());
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice'])]
    public function testHistoricalCzkCashMemberKeepsDocumentCurrencyAndAsOf(string $type): void
    {
        $id = $this->document($type);
        $c = Bootstrap::buildContainer();
        $register = $c->get(\MyInvoice\Repository\CashRegisterRepository::class)->create($this->sid, ['name' => 'Synthetic CZK', 'currency_code' => 'CZK', 'account_code' => '211']);
        $cash = $c->get(\MyInvoice\Service\Accounting\Cash\CashDocumentService::class)->create($this->sid, [
            'register_id' => $register, 'purpose' => 'other', 'doc_type' => $type === 'invoice' ? 'in' : 'out',
            'issue_date' => '2098-09-15', 'description' => 'Synthetic historical cash', 'vat_mode' => 'none',
            'total_amount' => 12000, 'counter_account_code' => $type === 'invoice' ? '311' : '321',
        ], $this->user);
        if ($type === 'invoice') {
            $payment = $c->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)->recordPayment($id, 500, '2098-09-15', ['source' => 'cash']);
            $this->db->pdo()->prepare('UPDATE cash_documents SET invoice_id=?,invoice_payment_id=? WHERE supplier_id=? AND id=?')->execute([$id, $payment['payment_id'], $this->sid, $cash['id']]);
        } else {
            $this->db->pdo()->prepare('UPDATE cash_documents SET purchase_invoice_id=? WHERE supplier_id=? AND id=?')->execute([$id, $this->sid, $cash['id']]);
        }
        self::assertSame('1000.00', $this->evidence($type, $id, '2098-08-31')['remaining_foreign']);
        $after = $this->evidence($type, $id, '2098-12-31');
        self::assertTrue($after['complete'], json_encode($after));
        self::assertSame('500.00', $after['remaining_foreign']);
        self::assertSame('12000.00', $after['booked_czk']);
    }

    public function testNativePurchaseSettlementCancellationIsAsOfAware(): void
    {
        $id = $this->document('purchase_invoice');
        $this->settle('purchase_invoice', $id, 'settlement', 500);
        $settlement = (int) $this->db->pdo()->query('SELECT id FROM invoice_settlements WHERE supplier_id=' . $this->sid)->fetchColumn();
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\InvoiceSettlementService::class)->cancel($this->sid, $settlement, ['entry_date' => '2098-10-01']);
        self::assertSame('500.00', $this->evidence('purchase_invoice', $id, '2098-09-30')['remaining_foreign']);
        $after = $this->evidence('purchase_invoice', $id, '2098-12-31');
        self::assertTrue($after['complete'], json_encode($after));
        self::assertSame('1000.00', $after['remaining_foreign']);
    }

    public function testUnlinkedAdvanceIsAnExplicitFinding(): void
    {
        [, $final] = $this->advanceCycle(500);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id=NULL WHERE supplier_id=? AND id=?')->execute([$this->sid, $final]);
        $evidence = $this->evidence('invoice', $final, '2098-12-31');
        self::assertFalse($evidence['complete']);
        self::assertContains('advance_lineage_missing', $evidence['findings']);
    }

    public function testReversedAdvanceCannotSupportStaleFinalSettlement(): void
    {
        [, $final, $bank] = $this->advanceCycle(500);
        $c = Bootstrap::buildContainer();
        try {
            $c->get(\MyInvoice\Service\Bank\BankTransactionReleaseService::class)->release($this->sid, $bank, \MyInvoice\Service\Bank\BankTransactionReleaseService::MODE_UNMATCH, $this->user);
            self::fail('An existing advance child must prevent normal bank unmatch.');
        } catch (\MyInvoice\Service\Bank\BankTransactionReleaseException $e) {
            self::assertSame('has_tax_document', $e->errorCode);
        }
        $entry = $c->get(\MyInvoice\Repository\JournalEntryRepository::class)->findBySource($this->sid, 'bank', $bank);
        $c->get(PostingService::class)->reverse($this->sid, (int) $entry['id'], ['entry_date' => '2098-10-01']);
        self::assertSame('500.00', $this->evidence('invoice', $final, '2098-09-30')['remaining_foreign']);
        $evidence = $this->evidence('invoice', $final, '2098-12-31');
        self::assertFalse($evidence['complete']);
        self::assertContains('advance_settlement_pair_unproven', $evidence['findings']);
    }

    public function testFutureAdvanceCannotSupportEarlierSettlement(): void
    {
        [, $final, $bank] = $this->advanceCycle(500);
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->sid, 'bank', $bank, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 12000, 'currency_code' => 'EUR', 'amount_foreign' => 500, 'fx_rate' => 24],
            ['account_code' => '324', 'side' => 'credit', 'amount' => 12000],
        ], ['entry_date' => '2099-01-01', 'posted' => true]);
        $evidence = $this->evidence('invoice', $final, '2098-12-31');
        self::assertFalse($evidence['complete']);
        self::assertContains('advance_settlement_pair_unproven', $evidence['findings']);
    }

    public function testCurrentAdvanceReversalDoesNotReuseCallerSnapshot(): void
    {
        [, $final, $bank] = $this->advanceCycle(500);
        $entry = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class)->findBySource($this->sid, 'bank', $bank);
        $this->snapshot();
        self::assertSame('500.00', $this->evidence('invoice', $final, '2098-12-31')['remaining_foreign']);
        $this->other->get(PostingService::class)->reverse($this->sid, (int) $entry['id'], ['entry_date' => '2098-10-01']);
        self::assertTrue($this->evidence('invoice', $final, '2098-12-31')['complete']);
        $current = $this->evidence('invoice', $final, '2098-12-31', true);
        self::assertFalse($current['complete']);
        self::assertContains('advance_settlement_pair_unproven', $current['findings']);
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertTrue($this->evidence('invoice', $final, '2098-12-31')['complete']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice', false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['invoice', true])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice', false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice', true])]
    public function testNativeUnpaidCreditNotePreviewKeepsLedgerDirection(string $type, bool $red): void
    {
        $id = $this->document($type, false);
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $itemTable = $type === 'invoice' ? 'invoice_items' : 'purchase_invoice_items';
        $kind = $type === 'invoice' ? 'invoice_type' : 'document_kind';
        $fk = $type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $this->db->pdo()->prepare("UPDATE {$table} SET {$kind}='credit_note',total_without_vat=-1000,total_with_vat=-1000 WHERE supplier_id=? AND id=?")->execute([$this->sid, $id]);
        $this->db->pdo()->prepare("UPDATE {$itemTable} SET unit_price_without_vat=-1000,total_without_vat=-1000,total_with_vat=-1000 WHERE {$fk}=?")->execute([$id]);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $lines = $type === 'invoice' ? $posting->buildFromInvoice($this->sid, $id) : $posting->buildFromPurchaseInvoice($this->sid, $id);
        if ($red) {
            foreach ($lines as &$line) {
                $line['side'] = $line['side'] === 'debit' ? 'credit' : 'debit';
                $line['is_red_storno'] = true;
            }
            unset($line);
        }
        $posting->postDocument($this->sid, $type, $id, $lines, ['entry_date' => '2098-06-15', 'posted' => true]);
        $preview = Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->sid, $this->pid);
        self::assertSame(-1000.0, $preview['saldo']['detail'][0]['remaining_foreign']);
        self::assertSame(1000.0, $preview['totals'][$type === 'invoice' ? 'loss' : 'gain']);
    }

    public function testConflictingPaymentRepresentationsDoNotBecomeNumericPreview(): void
    {
        $id = $this->document('invoice');
        $bank = $this->settle('invoice', $id, 'bank', 500);
        $this->db->pdo()->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,invoice_id,amount,match_type) VALUES (?,?,?,400,'manual')")->execute([$this->sid, $bank, $id]);
        try {
            Bootstrap::buildContainer()->get(ClosingService::class)->fxPreview($this->sid, $this->pid);
            self::fail('Conflicting representations require a finding, not a numeric preview.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertSame('fx_settlement_unproven', $e->errorCode);
            self::assertStringContainsString('Bankovní párování a evidence úhrady uvádějí rozdílné částky.', $e->getMessage());
        }
    }

    public function testNativeMutualOffsetDoesNotAcceptForeignDocuments(): void
    {
        $invoice = $this->document('invoice');
        $purchase = $this->document('purchase_invoice');
        $partner = (int) $this->db->pdo()->query('SELECT client_id FROM invoices WHERE id=' . $invoice)->fetchColumn();
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET vendor_id=? WHERE supplier_id=? AND id=?')->execute([$partner, $this->sid, $purchase]);
        try {
            Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\OffsetService::class)->create($this->sid, $partner, '2098-09-15', [
                ['doc_type' => 'invoice', 'doc_id' => $invoice, 'amount' => 500],
                ['doc_type' => 'purchase_invoice', 'doc_id' => $purchase, 'amount' => 500],
            ], null, $this->user);
            self::fail('Native offset keeps its CZK-only scope.');
        } catch (\MyInvoice\Service\Accounting\OffsetException $e) {
            self::assertSame('doc_not_open', $e->errorCode);
        }
        self::assertSame('1000.00', $this->evidence('invoice', $invoice, '2098-12-31')['remaining_foreign']);
        self::assertSame('1000.00', $this->evidence('purchase_invoice', $purchase, '2098-12-31')['remaining_foreign']);
    }

    public static function refundCases(): iterable
    {
        foreach (['invoice', 'purchase_invoice'] as $type) {
            foreach ([false, true] as $red) {
                foreach ([500, 1000] as $paid) yield [$type, $red, $paid];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refundCases')]
    public function testDocumentedRefundMembersOverrideFullRefundHeader(string $type, bool $red, int $paid): void
    {
        $id = $this->document($type, false);
        $pdo = $this->db->pdo();
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $itemTable = $type === 'invoice' ? 'invoice_items' : 'purchase_invoice_items';
        $kind = $type === 'invoice' ? 'invoice_type' : 'document_kind';
        $fk = $type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $pdo->prepare("UPDATE {$table} SET {$kind}='credit_note',total_without_vat=-1000,total_with_vat=-1000 WHERE supplier_id=? AND id=?")->execute([$this->sid, $id]);
        $pdo->prepare("UPDATE {$itemTable} SET unit_price_without_vat=-1000,total_without_vat=-1000,total_with_vat=-1000 WHERE {$fk}=?")->execute([$id]);
        $c = Bootstrap::buildContainer();
        $posting = $c->get(PostingService::class);
        $lines = $type === 'invoice' ? $posting->buildFromInvoice($this->sid, $id) : $posting->buildFromPurchaseInvoice($this->sid, $id);
        if ($red) {
            foreach ($lines as &$line) {
                $line['side'] = $line['side'] === 'debit' ? 'credit' : 'debit';
                $line['is_red_storno'] = true;
            }
            unset($line);
        }
        $posting->postDocument($this->sid, $type, $id, $lines, ['entry_date' => '2098-06-15', 'posted' => true]);
        $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES (?,'synthetic-refund.csv',?,'1000000005','0100','EUR','2098-09-15',?)")->execute([$this->sid, hash('sha256', 'refund-' . $this->sid), $this->user]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,source,posted_at,amount,currency,description,match_status) VALUES (?,'statement','2098-09-15',?,'EUR','Synthetic refund','manual')")->execute([$statement, $type === 'invoice' ? -$paid : $paid]);
        $bank = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,{$fk},amount,match_type) VALUES (?,?,?,?,'manual')")->execute([$this->sid, $bank, $id, $paid]);
        $posting->postDocument($this->sid, 'bank', $bank, [
            ['account_code' => $type === 'invoice' ? '311' : '321', 'side' => $type === 'invoice' ? 'debit' : 'credit', 'amount' => $paid * 24, 'currency_code' => 'EUR', 'amount_foreign' => $paid, 'fx_rate' => 24],
            ['account_code' => '221', 'side' => $type === 'invoice' ? 'credit' : 'debit', 'amount' => $paid * 24, 'currency_code' => 'EUR', 'amount_foreign' => $paid, 'fx_rate' => 24],
        ], ['entry_date' => '2098-09-15', 'posted' => true]);
        if ($type === 'invoice') $c->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)->markRefunded($id, $this->sid, '2098-09-15');
        else $pdo->prepare("UPDATE purchase_invoices SET status='paid',paid_at='2098-09-15' WHERE supplier_id=? AND id=?")->execute([$this->sid, $id]);
        self::assertSame('-1000.00', $this->evidence($type, $id, '2098-08-31')['remaining_foreign']);
        $evidence = $this->evidence($type, $id, '2098-12-31');
        self::assertTrue($evidence['complete'], json_encode($evidence));
        self::assertSame(number_format($paid - 1000, 2, '.', ''), $evidence['remaining_foreign']);
        if ($paid === 500) self::assertContains('payment_header_divergence', $evidence['diagnostics']);
        $preview = $c->get(ClosingService::class)->fxPreview($this->sid, $this->pid);
        self::assertSame((float) (1000 - $paid), $preview['totals'][$type === 'invoice' ? 'loss' : 'gain']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice', 500])]
    #[\PHPUnit\Framework\Attributes\TestWith(['invoice', 1000])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice', 500])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice', 1000])]
    public function testFxPrecheckUsesProvenRemainingAmount(string $type, int $paid): void
    {
        $id = $this->document($type);
        $this->settle($type, $id, 'bank', $paid);
        $checks = Bootstrap::buildContainer()->get(ClosingService::class)->buildChecks($this->sid,
            ['id' => $this->pid, 'fiscal_year' => 2098, 'starts_on' => '2098-01-01', 'ends_on' => '2098-12-31'], onlyKeys: ['fx_open_items']);
        self::assertCount(1, $checks);
        self::assertSame($paid === 1000 ? 0 : 1, $checks[0]['value']['count']);
        if ($paid === 500) self::assertSame(500.0, $checks[0]['value']['findings'][0]['amount']);
    }

    public function testFxPrecheckRetainsUnprovenItemWithoutNumericRemaining(): void
    {
        $id = $this->document('invoice');
        $bank = $this->settle('invoice', $id, 'bank', 500);
        $this->db->pdo()->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,invoice_id,amount,match_type) VALUES (?,?,?,400,'manual')")->execute([$this->sid, $bank, $id]);
        $checks = Bootstrap::buildContainer()->get(ClosingService::class)->buildChecks($this->sid,
            ['id' => $this->pid, 'fiscal_year' => 2098, 'starts_on' => '2098-01-01', 'ends_on' => '2098-12-31'], onlyKeys: ['fx_open_items']);
        self::assertSame(1, $checks[0]['value']['count']);
        $finding = $checks[0]['value']['findings'][0];
        self::assertNull($finding['amount']);
        self::assertContains('fx_settlement_unproven', $finding['issues']);
        self::assertContains('payment_representation_conflict', $finding['detail']['settlement_findings']);
    }

    public function testPreviewKeepsOriginalItemsAndPaymentsInOneSnapshot(): void
    {
        $id = $this->document('invoice');
        $this->snapshot();
        $this->db->pdo()->rollBack();
        $c = Bootstrap::buildContainer();
        $realRepo = $c->get(ClosingRepository::class);
        $repo = $this->getMockBuilder(ClosingRepository::class)->setConstructorArgs([$this->db])->onlyMethods(['openFxItems'])->getMock();
        $repo->expects(self::once())->method('openFxItems')->willReturnCallback(function (int $supplier, string $asOf, bool $current) use ($realRepo, $id): array {
            $items = $realRepo->openFxItems($supplier, $asOf, $current);
            $this->settle('invoice', $id, 'manual', 500, $this->other);
            return $items;
        });
        $service = new \MyInvoice\Service\Accounting\Closing\FxRevaluationService(
            $repo, $c->get(\MyInvoice\Service\Currency\CnbExchangeRateClient::class),
            $c->get(\MyInvoice\Repository\PostingRuleRepository::class), $c->get(\MyInvoice\Repository\ChartOfAccountsRepository::class),
            $c->get(\MyInvoice\Service\Accounting\AccountRoleResolver::class),
        );
        $preview = $service->preview($this->sid, ['id' => $this->pid, 'ends_on' => '2098-12-31']);
        self::assertSame(1000.0, $preview['saldo']['detail'][0]['remaining_foreign']);
        self::assertFalse($this->db->pdo()->inTransaction());
        self::assertSame('500.00', $this->evidence('invoice', $id, '2098-12-31')['remaining_foreign']);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['invoice'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['purchase_invoice'])]
    public function testRetainedPaymentMemberWithReversedLedgerRequiresReview(string $type): void
    {
        $id = $this->document($type);
        $bank = $this->settle($type, $id, 'bank', 500);
        $entry = (int) $this->db->pdo()->query("SELECT id FROM journal_entries WHERE supplier_id={$this->sid} AND source_type='bank' AND source_id={$bank} AND reversed_by IS NULL")->fetchColumn();
        Bootstrap::buildContainer()->get(PostingService::class)->reverse($this->sid, $entry, ['entry_date' => '2098-10-15', 'description' => 'Synthetic reversal']);
        self::assertSame('500.00', $this->evidence($type, $id, '2098-09-30')['remaining_foreign']);
        $evidence = $this->evidence($type, $id, '2098-12-31');
        self::assertFalse($evidence['complete']);
        self::assertContains('payment_posting_reversed', $evidence['findings']);
    }

    private function advanceCycle(int $paid): array
    {
        $parent = $this->invoice($this->sid, 24, '2098-06-15', '2098000920', false, 'proforma');
        $bank = $this->settle('invoice', $parent, 'bank', $paid, null, true);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->postDocument($this->sid, 'bank', $bank, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => $paid * 24, 'currency_code' => 'EUR', 'amount_foreign' => $paid, 'fx_rate' => 24],
            ['account_code' => '324', 'side' => 'credit', 'amount' => $paid * 24],
        ], ['entry_date' => '2098-09-15', 'posted' => true]);
        $final = $this->invoice($this->sid, 24, '2098-09-15', '2098000921', false);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id=?,advance_paid_amount=? WHERE supplier_id=? AND id=?')
            ->execute([$parent, $paid, $this->sid, $final]);
        $posting->postDocument($this->sid, 'invoice', $final, $posting->buildFromInvoice($this->sid, $final), ['entry_date' => '2098-09-15', 'posted' => true]);
        return [$parent, $final, $bank];
    }

    private function document(string $type, bool $post = true): int
    {
        if ($type==='invoice') return $this->invoice($this->sid,24,'2098-06-15','2098000901',$post);
        $pdo=$this->db->pdo();
        $find = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id=? AND code='EUR'");
        $find->execute([$this->sid]);
        $eur = (int) $find->fetchColumn();
        if (!$eur) {
            $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default) VALUES (?,'EUR','EUR','EUR','Euro','Euro',2,1,0)")->execute([$this->sid]);
            $eur=(int)$pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT id,'Synthetic FX vendor','Test','Test','10000',country_id,'fx-vendor@example.com',? FROM supplier WHERE id=?")->execute([$eur,$this->sid]);
        $vendor=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO purchase_invoices(supplier_id,varsymbol,vendor_id,vendor_invoice_number,document_kind,issue_date,tax_date,due_date,received_at,currency_id,exchange_rate,vendor_snapshot,total_without_vat,total_with_vat,status,created_by) VALUES (?,'2098000902',?,CONCAT('SYN-FX-PF-',UUID()),'invoice','2098-06-15','2098-06-15','2098-06-30','2098-06-15',?,24,'{}',1000,1000,'received',?)")->execute([$this->sid,$vendor,$eur,$this->user]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO purchase_invoice_items(purchase_invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,'Synthetic service',1,'ks',1000,(SELECT id FROM vat_rates ORDER BY id LIMIT 1),0,1000,0,1000,0)")->execute([$id]);
        if ($post) Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->sid,'purchase_invoice',$id,[
            ['account_code'=>'518','side'=>'debit','amount'=>'24000.00'],
            ['account_code'=>'321','side'=>'credit','amount'=>'24000.00','currency_code'=>'EUR','fx_rate'=>24,'amount_foreign'=>'1000.00'],
        ],['entry_date'=>'2098-06-15','posted'=>true]);
        return $id;
    }

    private function evidence(string $type,int $id,string $asOf,bool $current=false): array
    {
        $c=Bootstrap::buildContainer();
        $items=array_values(array_filter($c->get(ClosingRepository::class)->openFxItems($this->sid,$asOf,$current),static fn(array $r):bool=>$r['doc_type']===$type && $r['doc_id']===$id));
        self::assertCount(1,$items);
        return $c->get(FxSettlementEvidenceRepository::class)->read($this->sid,$items[0],$asOf,$current);
    }

    private function settle(string $type,int $id,string $channel,int $amount, ?\Psr\Container\ContainerInterface $container = null, bool $expectUnsupportedAdvance = false): ?int
    {
        $c=$container ?? Bootstrap::buildContainer();$pdo=$c->get(Connection::class)->pdo();
        if ($channel==='manual') {
            $c->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)->recordPayment($id,$amount,'2098-09-15',['source'=>'manual']);
        } elseif ($channel==='settlement') {
            $account=(int)$pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id={$this->sid} AND account_code='395'")->fetchColumn();
            $c->get(\MyInvoice\Service\Accounting\InvoiceSettlementService::class)->create($this->sid,$type,$id,['settled_on'=>'2098-09-15','amount'=>$amount,'account_id'=>$account],$this->user);
        } elseif ($channel==='cash') {
            $register=$c->get(\MyInvoice\Repository\CashRegisterRepository::class)->create($this->sid,['name'=>'Synthetic CZK','currency_code'=>'CZK','account_code'=>'211']);
            $c->get(\MyInvoice\Service\Accounting\Cash\CashDocumentService::class)->create($this->sid,[
                'register_id'=>$register,'purpose'=>$type==='invoice'?'invoice_payment':'purchase_payment','doc_type'=>$type==='invoice'?'in':'out',
                'issue_date'=>'2098-09-15','description'=>'Synthetic payment','vat_mode'=>'none','total_amount'=>$amount,'fx_rate'=>24,
                $type==='invoice'?'invoice_id':'purchase_invoice_id'=>$id,
            ],$this->user);
        } else {
            $pdo->prepare("UPDATE currencies SET account_number='1000000005',bank_code='0100' WHERE supplier_id=? AND code='EUR'")->execute([$this->sid]);
            $pdo->prepare("INSERT INTO auto_posting_policy(supplier_id,operation_type,level,updated_by) VALUES (?,'bank.payment.matched','auto',?)")->execute([$this->sid,$this->user]);
            $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES (?,'synthetic-fx.csv',?,'1000000005','0100','EUR','2098-09-15',?)")->execute([$this->sid,hash('sha256','synthetic-channels-'.$this->sid),$this->user]);
            $statement=(int)$pdo->lastInsertId();
            $column='matched_invoice_id';
            $pdo->prepare("INSERT INTO bank_transactions(statement_id,source,posted_at,amount,currency,description,match_status,{$column}) VALUES (?,'statement','2098-09-15',?,'EUR','Synthetic payment','manual',?)")->execute([$statement,$type==='invoice'?$amount:-$amount,$type==='invoice'?$id:null]);
            $tx=(int)$pdo->lastInsertId();
            if ($type==='invoice') $c->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)->recordPayment($id,$amount,'2098-09-15',['source'=>'bank','bank_transaction_id'=>$tx]);
            else $pdo->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,purchase_invoice_id,amount,match_type) VALUES (?,?,?,?,'manual')")->execute([$this->sid,$tx,$id,$amount]);
            $result=$c->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class)->handleTransaction($tx,$this->user);
            if ($expectUnsupportedAdvance) self::assertSame(['action' => 'skipped', 'reason' => 'fx_not_supported'], $result);
            else self::assertSame('posted',$result['action'],json_encode($result));
            return $tx;
        }
        return null;
    }

    private function invoice(int $sid, float $rate, string $date, string $symbol, bool $post = true, string $kind = 'invoice'): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id=? AND code='EUR'");
        $stmt->execute([$sid]);
        $eur = (int) $stmt->fetchColumn();
        if (!$eur) {
            $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default) VALUES (?,'EUR','EUR','EUR','Euro','Euro',2,1,0)")->execute([$sid]);
            $eur = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)
            SELECT id,'Synthetic FX client','Test','Test','10000',country_id,'fx-client@example.com',? FROM supplier WHERE id=?")->execute([$eur, $sid]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoices(supplier_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,currency_id,exchange_rate,total_without_vat,total_vat,total_with_vat,status,vat_classification_code,created_by)
            VALUES (?,?,?,?,?,?,'2098-06-30',?,?,1000,0,1000,'issued','1',(SELECT id FROM users ORDER BY id LIMIT 1))")->execute([$sid, $symbol, $kind, $client, $date, $date, $eur, $rate]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index)
            VALUES (?,'Synthetic service',1,'ks',1000,(SELECT id FROM vat_rates ORDER BY id LIMIT 1),0,1000,0,1000,0)")->execute([$id]);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        if ($post) $posting->postDocument($sid, 'invoice', $id, $posting->buildFromInvoice($sid, $id), ['entry_date' => $date, 'posted' => true]);
        return $id;
    }
}
