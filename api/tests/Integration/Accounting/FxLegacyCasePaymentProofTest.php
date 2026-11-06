<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\{ChartOfAccountsSeeder, PostingService};
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\TestCase;

final class FxLegacyCasePaymentProofTest extends TestCase
{
    use \MyInvoice\Tests\Support\SharedClassFixture;

    private Connection $db;
    private int $committedSupplier = 0;
    private ?Connection $other = null;
    private ?int $snapshotIsolation = null;
    private array $previousRates = [];
    private const RATE_DATES = "'2098-06-15','2098-06-16','2098-09-15','2098-12-31','2099-06-15','2099-12-31'";
    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        $this->other?->close();
        if ($this->committedSupplier) {
            foreach (['activity_log', 'journal_entries', 'invoice_payments', 'bank_statements', 'invoices', 'clients', 'currencies'] as $table) $this->db->pdo()->prepare('DELETE FROM ' . $table . ' WHERE supplier_id=?')->execute([$this->committedSupplier]);
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->committedSupplier]);
            $this->db->pdo()->exec("DELETE FROM exchange_rates WHERE currency_code='EUR' AND rate_date IN (" . self::RATE_DATES . ')');
            foreach ($this->previousRates as $row) $this->db->pdo()->prepare('INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES (?,?,?)')->execute([$row['rate_date'],$row['currency_code'],$row['rate']]);
        }
        if ($this->snapshotIsolation !== null) $this->db->pdo()->exec('SET SESSION innodb_snapshot_isolation=' . $this->snapshotIsolation);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['cancel'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['partial_matches'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_legacy_matches'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_reverse'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_full'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_full_reverse'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_legacy_bad_detail'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_legacy_bad_opening'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_bad_reversal'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_bad_source'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_legacy_bad_tuple'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_legacy_bad_rate'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_current_step'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_mixed_czk'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_mixed_czk_reverse'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_mixed_czk_bad_fx'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_bad_foreign_opening'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_mixed_n2'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_mixed_n2_reverse'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_mixed_manual'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_bad_fx_foreign'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_closed_home'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['prior_native_closed_home_reverse'])]
    public function testNativePaymentAndHistoricalNetEvidence(string $mode): void
    {
        $c = Bootstrap::buildContainer();
        $this->db = $c->get(Connection::class);
        $pdo = $this->db->pdo();
        if (!str_starts_with($mode, 'prior_') || $mode === 'prior_native_current_step') {
            $pdo->beginTransaction();
            $s = $this->scenario($c, $mode);
        } else {
            // Uzavřený a otevřený rok 2098 sdílí režimy se stejnou přípravou; kurzy jsou
            // globální číselník, do sdílené fixture se necommitují.
            $s = $this->sharedFixture($pdo, self::priorKey($mode), function () use ($c, $mode, $pdo): array {
                $s = $this->scenario($c, $mode);
                $pdo->exec("DELETE FROM exchange_rates WHERE currency_code='EUR' AND rate_date IN (" . self::RATE_DATES . ')');
                foreach ($this->previousRates as $row) $pdo->prepare('INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES (?,?,?)')->execute([$row['rate_date'],$row['currency_code'],$row['rate']]);
                return [$s['sid'], $s];
            });
            $this->rates($pdo);
        }
        ['sid' => $sid, 'pid' => $pid, 'user' => $user, 'first' => $first, 'closedHome' => $closedHome, 'booked' => $booked,
            'opened' => $opened, 'oldState' => $oldState, 'target' => $target] = $s;
        $closing = $c->get(ClosingService::class);
        $date=str_starts_with($mode,'prior_')?'2099-06-15':'2098-09-15';
        $tx=$this->payment($sid,$first,$date,$user,str_contains($mode,'full') ? 1000 : 500);
        if (str_ends_with($mode,'_matches')) {
            $pdo->prepare("INSERT INTO payment_matches(supplier_id,bank_transaction_id,invoice_id,amount,match_type) VALUES (?,?,?,500,'manual')")->execute([$sid,$tx,$first]);
        }
        if ($mode === 'cancel') {
            $c->get(\MyInvoice\Service\Bank\BankTransactionReleaseService::class)->release($sid,$tx,\MyInvoice\Service\Bank\BankTransactionReleaseService::MODE_UNMATCH,$user);
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id='.$tx)->fetchColumn());
            self::assertGreaterThan(0,(int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=$sid AND reversed_by IS NOT NULL")->fetchColumn());
        }
        if (str_contains($mode, 'bad_')) {
            if (str_ends_with($mode, 'bad_detail')) $pdo->exec("UPDATE accounting_closing_steps SET payload=JSON_SET(payload,'$.detail[0].diff',999) WHERE supplier_id=$sid AND period_id=$pid AND step_key='fx_revaluation'");
            if (str_ends_with($mode, 'bad_rate')) $pdo->exec("UPDATE accounting_closing_steps SET payload=JSON_SET(payload,'$.detail[0].rate_cnb',25.1,'$.detail[0].new_czk',25100,'$.detail[0].diff',1100,'$.detail[1].rate_cnb',24.9,'$.detail[1].new_czk',24900,'$.detail[1].diff',-600) WHERE supplier_id=$sid AND period_id=$pid AND step_key='fx_revaluation'");
            if (str_ends_with($mode, 'bad_opening')) $pdo->exec("UPDATE journal_entry_lines SET amount=amount+1 WHERE supplier_id=$sid AND entry_id=".(int)$opened['entry_id']." AND side='debit'");
            if (str_ends_with($mode, 'bad_reversal')) $pdo->exec("UPDATE journal_entry_lines SET amount=amount+1 WHERE supplier_id=$sid AND entry_id=".(int)$opened['fx_reversal_entry_id']." AND side='credit'");
            if (str_ends_with($mode, 'bad_fx')) $pdo->exec("UPDATE journal_entry_lines SET currency_code=NULL,amount_foreign=NULL,fx_rate=NULL WHERE supplier_id=$sid AND entry_id=".(int)$booked['entry_ids']['saldo']." AND currency_code='EUR' LIMIT 1");
            if (str_ends_with($mode, 'bad_fx_foreign')) $pdo->exec("UPDATE journal_entry_lines SET amount_foreign=NULL WHERE supplier_id=$sid AND entry_id=".(int)$booked['entry_ids']['saldo']." AND currency_code='EUR' LIMIT 1");
            if (str_ends_with($mode, 'bad_source') || str_ends_with($mode, 'bad_tuple')) $pdo->exec("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id SET l.cost_center='CHANGED-SOURCE' WHERE e.supplier_id=$sid AND e.source_type='invoice' AND l.currency_code='EUR'");
            $this->expectException(\MyInvoice\Service\Accounting\Closing\ClosingException::class);
            $this->expectExceptionMessage(\MyInvoice\Service\Accounting\Closing\FxCaseCarryingProjection::findingMessage(match (true) {
                str_ends_with($mode, 'bad_detail') => 'historical_amount_changed',
                str_ends_with($mode, 'bad_opening') => 'historical_opening_anchor_mismatch',
                str_ends_with($mode, 'bad_source') => 'historical_source_tuple_changed',
                str_ends_with($mode, 'bad_tuple') => 'historical_source_tuple_missing',
                str_ends_with($mode, 'bad_rate') => 'historical_rate_changed',
                str_ends_with($mode, 'bad_fx') => 'historical_net_mismatch',
                str_ends_with($mode, 'bad_foreign_opening') => 'historical_case_currency_mismatch',
                str_ends_with($mode, 'bad_fx_foreign') => 'historical_fx_foreign_changed',
                default => 'historical_reversal_mismatch',
            }));
        }
        if ($mode === 'prior_native_current_step') {
            $pdo->commit();
            $this->committedSupplier = $sid;
            $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
            $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
            $other = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
            $this->other = Connection::withoutSharedTestConnection(fn () => $other->get(Connection::class));
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            self::assertSame(['loss'=>375.0,'gain'=>250.0], $closing->fxPreview($sid,$target)['totals']);
            $pdo->rollBack();
            $pdo->beginTransaction();
            self::assertSame(['loss'=>375.0,'gain'=>250.0], $closing->fxPreview($sid,$target)['totals']);
            $this->other->pdo()->exec("UPDATE accounting_closing_steps SET payload=JSON_SET(payload,'$.detail[0].diff',999) WHERE supplier_id=$sid AND period_id=$pid AND step_key='fx_revaluation'");
            $period = $c->get(AccountingPeriodRepository::class)->findById($sid,$target);
            $snapshot = $c->get(\MyInvoice\Repository\ClosingRepository::class)->fxCaseSnapshot($sid,$period,true);
            self::assertSame(['historical_amount_changed'], (new \MyInvoice\Service\Accounting\Closing\FxCaseCarryingProjection())->project($snapshot,$period)['findings']);
            self::assertTrue($pdo->inTransaction());
            self::assertSame(['loss'=>375.0,'gain'=>250.0], $closing->fxPreview($sid,$target)['totals']);
            return;
        }
        $preview=$closing->fxPreview($sid,$target);
        if (str_contains($mode, 'bad_')) return;
        self::assertCount($mode === 'prior_native_full_reverse' ? 1 : 2,$preview['saldo']['detail']);
        if ($oldState!==null) self::assertSame($oldState,$this->priorState($sid,$pid),'Read-only recovery must not rewrite closed books or the frozen legacy detail.');
        $remaining=array_column($preview['saldo']['detail'],'remaining_foreign','doc_id');
        self::assertSame($mode==='cancel'?1000.0:(str_contains($mode,'full')?0.0:500.0),($remaining[$first] ?? 0.0),json_encode(['remaining'=>$remaining,'totals'=>$preview['totals']]));
        $expected=match($mode){'partial','partial_matches'=>['loss'=>1000.0,'gain'=>500.0],'cancel'=>['loss'=>1000.0,'gain'=>1000.0],'prior_native_reverse','prior_native_mixed_czk_reverse','prior_native_mixed_n2_reverse','prior_native_closed_home_reverse'=>['loss'=>250.0,'gain'=>625.0], 'prior_native_full'=>['loss'=>1000.0,'gain'=>250.0], 'prior_native_full_reverse'=>['loss'=>250.0,'gain'=>0.0], default=>['loss'=>375.0,'gain'=>250.0]};
        self::assertSame($expected,$preview['totals']);
        if ($oldState !== null) {
            $proof = $preview['case_history_proof'][0];
            self::assertSame($pid, $proof['period_id']);
            self::assertSame($booked['entry_ids']['saldo'], $proof['fx_entry_id']);
            self::assertSame($opened['entry_id'], $proof['opening_entry_id']);
            $ids=$pdo->query("SELECT l.id FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id WHERE e.supplier_id=$sid AND e.source_type='invoice' AND e.source_id<>$closedHome AND l.currency_code='EUR' ORDER BY l.id")->fetchAll(\PDO::FETCH_COLUMN);
            self::assertSame(array_map('intval',$ids),$proof['source_line_ids']);
            if ($closedHome) {
                $closedIds=$pdo->query("SELECT l.id FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id WHERE e.supplier_id=$sid AND e.source_type='invoice' AND e.source_id=$closedHome AND l.currency_code='EUR' ORDER BY l.id")->fetchAll(\PDO::FETCH_COLUMN);
                self::assertCount(2,$closedIds);
                self::assertSameLineIds(array_map('intval',$closedIds),$proof['closed_home_source_line_ids']);
                $period = $c->get(AccountingPeriodRepository::class)->findById($sid,$target);
                $snapshot = $c->get(\MyInvoice\Repository\ClosingRepository::class)->fxCaseSnapshot($sid,$period);
                $projector = new \MyInvoice\Service\Accounting\Closing\FxCaseCarryingProjection();
                $original = $projector->project($snapshot,$period);
                foreach ([1,-1] as $direction) {
                    foreach ($snapshot['history'] as &$history) {
                        usort($history['items'],static fn(array $a,array $b):int => strcmp($a['item']['doc_type'],$b['item']['doc_type'])
                            ?: ($a['item']['doc_id'] <=> $b['item']['doc_id'])
                            ?: $direction * ($a['item']['source_line_id'] <=> $b['item']['source_line_id']));
                    }
                    unset($history);
                    $reordered = $projector->project($snapshot,$period);
                    self::assertTrue($reordered['complete']);
                    self::assertSame([],$reordered['findings']);
                    self::assertSame($original['carrying'],$reordered['carrying']);
                    self::assertSameLineIds(array_map('intval',$closedIds),$reordered['proof'][0]['closed_home_source_line_ids']);
                    $reordered['proof'][0]['closed_home_source_line_ids'] = $original['proof'][0]['closed_home_source_line_ids'];
                    self::assertSame($original['proof'],$reordered['proof']);
                }
            }
            $version = fn (): int => (int) $pdo->query('SELECT row_version FROM accounting_periods WHERE id='.$target)->fetchColumn();
            $distribution = $closing->profitDistributionPreview($sid, $pid);
            $closing->runProfitDistribution($sid, $pid, ['decision_date' => '2099-06-30', 'target_row_version' => $version(), 'allocations' => [['account_code' => '428', 'amount' => $distribution['available_profit'], 'kind' => 'retained']]]);
            $closing->start($sid,$target,$version());
            $posted=$closing->runFxRevaluation($sid,$target,[],$version());
            self::assertSame($expected,$posted['totals']);
            $again=$closing->runFxRevaluation($sid,$target,[],$version());
            self::assertSame($posted['entry_ids'],$again['entry_ids']);
            $totals=$pdo->query("SELECT a.account_code,SUM(l.signed_amount) AS amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=$sid AND l.entry_id=".(int)$posted['entry_ids']['saldo']." AND a.account_code IN ('563','663') GROUP BY a.account_code")->fetchAll(\PDO::FETCH_KEY_PAIR);
            self::assertSame($expected['loss'],(float)($totals['563']??0));
            self::assertSame($expected['gain'],(float)($totals['663']??0));
            self::assertSame($oldState,$this->priorState($sid,$pid));
            if (str_contains($mode, 'mixed_n2')) {
                $closing->runPrecheck($sid,$target,$version());
                foreach (['depreciation','estimates','deferrals','provisions','income_tax'] as $step) $closing->confirmStep($sid,$target,$step,'skipped',null,$version());
                $inventory=[];
                foreach ($closing->inventoryPreview($sid,$target)['rows'] as $row) $inventory[(int)$row['account_id']]=['counted_balance'=>(float)$row['book_balance'],'resolution'=>'resolved','note'=>null];
                $closing->saveInventory($sid,$target,$version(),['complete'=>true],$inventory);
                $closing->closeBooks($sid,$target,$version());
                $secondOpening=$closing->openNext($sid,$target,$version());
                $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2100-12-31','EUR',25.4) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");
                $third=$closing->fxPreview($sid,(int)$secondOpening['next_period_id']);
                self::assertCount(2,$third['case_history_proof']);
                self::assertSame(str_ends_with($mode,'reverse') ? ['loss'=>100.0,'gain'=>700.0] : ['loss'=>0.0,'gain'=>225.0],$third['totals']);
                self::assertSame($oldState,$this->priorState($sid,$pid));
            }
            if ($closedHome) {
                $pdo->exec("UPDATE accounting_closing_steps SET payload=JSON_SET(payload,'$.detail[0].doc_id',$closedHome) WHERE supplier_id=$sid AND period_id=$pid AND step_key='fx_revaluation'");
                $this->expectException(\MyInvoice\Service\Accounting\Closing\ClosingException::class);
                $this->expectExceptionMessage(\MyInvoice\Service\Accounting\Closing\FxCaseCarryingProjection::findingMessage('historical_membership_unproven'));
                $closing->fxPreview($sid,$target);
            }
        }
    }

    /** Režimy se stejnou přípravou uzavřeného roku (vše, co scenario() čte z režimu). */
    private static function priorKey(string $mode): string
    {
        return implode('|', [str_starts_with($mode, 'prior_legacy') ? 'legacy' : 'native', str_contains($mode, 'reverse') || str_contains($mode, 'reversal') ? 'reverse' : 'keep',
            str_contains($mode, 'mixed_') ? 'mixed' : '', $mode === 'prior_native_mixed_manual' ? 'manual' : '', str_contains($mode, 'closed_home') ? 'home' : '',
            $mode === 'prior_native_bad_foreign_opening' ? 'foreign_opening' : '']);
    }

    private function rates(\PDO $pdo): void
    {
        $pdo->exec("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES ('2098-06-15','EUR',24),('2098-06-16','EUR',26),('2098-09-15','EUR',24),('2098-12-31','EUR',25),('2099-06-15','EUR',24),('2099-12-31','EUR',25.25) ON DUPLICATE KEY UPDATE rate=VALUES(rate)");
    }

    /** Firma, dvě EUR faktury a u režimů prior_ uzavřený, otevřený a schválený rok 2098. */
    private function scenario(\Psr\Container\ContainerInterface $c, string $mode): array
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Synthetic FX historical proof','Test1','Test','10000','fx-proof@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $sid = (int) $pdo->lastInsertId();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($sid);
        $pid = $c->get(AccountingPeriodRepository::class)->create($sid,2098,'2098-01-01','2098-12-31');
        $user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->previousRates = $pdo->query("SELECT rate_date,currency_code,rate FROM exchange_rates WHERE currency_code='EUR' AND rate_date IN (" . self::RATE_DATES . ')')->fetchAll(\PDO::FETCH_ASSOC);
        $this->rates($pdo);
        $first = $this->invoice($sid,24,'2098-06-15','2098000701');
        $this->invoice($sid,str_starts_with($mode,'prior_')?25.5:26,'2098-06-16','2098000702');
        $closedHome = str_contains($mode, 'closed_home') ? $this->closedHomeDocument($sid, $user) : 0;
        if (str_contains($mode, 'mixed_')) $c->get(PostingService::class)->postDocument($sid,'manual',null,[
            ['account_code'=>'311','side'=>'debit','amount'=>1000],
            ['account_code'=>'602','side'=>'credit','amount'=>1000],
        ],['entry_date'=>'2098-07-01','posted'=>true]);
        if ($mode === 'prior_native_mixed_manual') {
            $entry = $c->get(PostingService::class)->postDocument($sid,'manual',null,[
                ['account_code'=>'311','side'=>'credit','amount'=>500],
                ['account_code'=>'395','side'=>'debit','amount'=>500],
            ],['entry_date'=>'2098-08-01','posted'=>true]);
            $c->get(\MyInvoice\Repository\JournalEntryDocumentLinkRepository::class)->add($entry,$sid,'invoice',$first,null,$user);
        }
        if ($mode === 'prior_native_bad_foreign_opening') $c->get(PostingService::class)->postDocument($sid,'opening',$pid,[
            ['account_code'=>'311','side'=>'debit','amount'=>4800,'currency_code'=>'EUR','amount_foreign'=>200,'fx_rate'=>24],
            ['account_code'=>'701','side'=>'credit','amount'=>4800],
            ['account_code'=>'701','side'=>'debit','amount'=>4800],
            ['account_code'=>'411','side'=>'credit','amount'=>4800],
        ],['entry_date'=>'2098-01-01','posted'=>true]);
        $closing = $c->get(ClosingService::class);
        $oldState = null;
        $booked = null;
        $opened = null;
        if (str_starts_with($mode, 'prior_')) {
            $pdo->prepare('INSERT INTO accounting_supplier_settings(supplier_id,fx_reversal_at_open) VALUES (?,0)')->execute([$sid]);
            if (str_contains($mode, 'reverse') || str_contains($mode, 'reversal')) $pdo->exec('UPDATE accounting_supplier_settings SET fx_reversal_at_open=1 WHERE supplier_id='.$sid);
            $version = fn (): int => (int) $pdo->query('SELECT row_version FROM accounting_periods WHERE id='.$pid)->fetchColumn();
            $closing->start($sid,$pid,$version());
            $closing->runPrecheck($sid,$pid,$version());
            foreach (['depreciation','estimates','deferrals','provisions','income_tax'] as $step) $closing->confirmStep($sid,$pid,$step,'skipped',null,$version());
            $booked = str_starts_with($mode, 'prior_legacy') ? \MyInvoice\Tests\Support\LegacyFxNetFixture::install($sid,$pid) : $closing->runFxRevaluation($sid,$pid,[],$version());
            self::assertSame(str_starts_with($mode, 'prior_legacy') ? ['loss'=>0.0,'gain'=>500.0] : ['loss'=>500.0,'gain'=>$mode === 'prior_native_mixed_manual' ? 1500.0 : 1000.0], $booked['totals']);
            $inventory=[];
            foreach ($closing->inventoryPreview($sid,$pid)['rows'] as $row) $inventory[(int)$row['account_id']]=['counted_balance'=>(float)$row['book_balance'],'resolution'=>'resolved','note'=>null];
            $closing->saveInventory($sid,$pid,$version(),['complete'=>true],$inventory);
            $closing->closeBooks($sid,$pid,$version());
            $opened=$closing->openNext($sid,$pid,$version());
            self::assertTrue($c->get(AccountingPeriodRepository::class)->setStatusCas($pid,$sid,'approved',$version(),$user,['body'=>'Synthetic approval','decision_ref'=>'SYN-FX-2098']));
            $oldState=$this->priorState($sid,$pid);
            $target=(int)$opened['next_period_id'];
        } else $target=$pid;
        return compact('sid', 'pid', 'user', 'first', 'closedHome', 'booked', 'opened', 'oldState', 'target');
    }

    private static function assertSameLineIds(array $expected,array $actual): void
    {
        sort($expected,SORT_NUMERIC);
        sort($actual,SORT_NUMERIC);
        self::assertSame($expected,$actual);
    }

    private function priorState(int $sid,int $pid): array
    {
        $stmt=$this->db->pdo()->prepare("SELECT s.payload,e.id,e.row_version,l.line_no,l.account_id,l.side,l.amount,l.amount_foreign FROM accounting_closing_steps s JOIN journal_entries e ON e.supplier_id=s.supplier_id AND e.period_id=s.period_id JOIN journal_entry_lines l ON l.entry_id=e.id WHERE s.supplier_id=? AND s.period_id=? AND s.step_key='fx_revaluation' ORDER BY e.id,l.line_no");
        $stmt->execute([$sid,$pid]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function closedHomeDocument(int $sid, int $user): int
    {
        $pdo = $this->db->pdo();
        $id = $this->invoice($sid, 24, '2098-06-15', '2098000703');
        $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES (?,'CZK','CZK','Kč','Koruna','Koruna',2,1,0,'1000000005','0100')")->execute([$sid]);
        $czk = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE invoices SET currency_id=?,exchange_rate=NULL,total_without_vat=24600,total_with_vat=24600 WHERE supplier_id=? AND id=?')->execute([$czk, $sid, $id]);
        $pdo->prepare('UPDATE invoice_items SET unit_price_without_vat=24600,total_without_vat=24600,total_with_vat=24600 WHERE invoice_id=?')->execute([$id]);
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($sid, 'invoice', $id, [
            ['account_code'=>'311','side'=>'debit','amount'=>9600,'currency_code'=>'EUR','amount_foreign'=>400,'fx_rate'=>24],
            ['account_code'=>'311','side'=>'debit','amount'=>15000,'currency_code'=>'EUR','amount_foreign'=>600,'fx_rate'=>25],
            ['account_code'=>'602','side'=>'credit','amount'=>24600],
        ], ['entry_date'=>'2098-06-15','posted'=>true]);
        $pdo->prepare("INSERT INTO auto_posting_policy(supplier_id,operation_type,level,updated_by) VALUES (?,'bank.payment.matched','auto',?) ON DUPLICATE KEY UPDATE level='auto'")->execute([$sid,$user]);
        $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES (?,'synthetic-home.csv',?,'1000000005','0100','CZK','2098-09-15',?)")->execute([$sid,hash('sha256','synthetic-home-'.$sid),$user]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,source,posted_at,amount,currency,description,match_status,matched_invoice_id) VALUES (?,'statement','2098-09-15',24600,'CZK','Synthetic exact home settlement','manual',?)")->execute([$statement,$id]);
        $bank = (int) $pdo->lastInsertId();
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class)->recordPayment($id,24600,'2098-09-15',['source'=>'bank','bank_transaction_id'=>$bank]);
        self::assertSame('posted', Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class)->handleTransaction($bank,$user)['action']);
        $rows = array_values(array_filter((new \MyInvoice\Repository\ClosingRepository($this->db))->fxItemsWithSettlement($sid,'2098-12-31'),static fn(array $row):bool=>$row['item']['doc_type']==='invoice' && $row['item']['doc_id']===$id));
        self::assertCount(2,$rows);
        foreach ($rows as $row) self::assertTrue($row['evidence']['closed_home_position_proven']);
        return $id;
    }

    private function payment(int $sid,int $invoice,string $date,int $user,int $amount=500): int
    {
        $pdo=$this->db->pdo();
        $pdo->prepare("UPDATE currencies SET account_number='1000000005',bank_code='0100' WHERE supplier_id=? AND code='EUR'")->execute([$sid]);
        $pdo->prepare("INSERT INTO auto_posting_policy(supplier_id,operation_type,level,updated_by) VALUES (?,'bank.payment.matched','auto',?) ON DUPLICATE KEY UPDATE level='auto'")->execute([$sid,$user]);
        $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by) VALUES (?,'synthetic-fx.csv',?,'1000000005','0100','EUR',?,?)")->execute([$sid,hash('sha256','synthetic-fx-'.$sid),$date,$user]);
        $statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,source,posted_at,amount,currency,description,match_status,matched_invoice_id) VALUES (?,'statement',?,?,'EUR','Synthetic partial payment','manual',?)")->execute([$statement,$date,$amount,$invoice]);
        $tx=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoice_payments(supplier_id,invoice_id,paid_on,amount,currency,source,bank_transaction_id) VALUES (?,?,?,?,'EUR','bank',?)")->execute([$sid,$invoice,$date,$amount,$tx]);
        $result=Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class)->handleTransaction($tx,$user);
        self::assertSame('posted',$result['action'],json_encode($result));
        $stmt=$pdo->prepare("SELECT l.amount,l.amount_foreign FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id=? AND a.account_code='311'");
        $stmt->execute([$sid,$tx]);
        self::assertSame(['amount'=>number_format($amount*24,2,'.',''),'amount_foreign'=>number_format($amount,2,'.','')],$stmt->fetch(\PDO::FETCH_ASSOC));
        return $tx;
    }

    private function invoice(int $sid, float $rate, string $date, string $symbol): int
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
            VALUES (?,?,'invoice',?,?,?,'2098-06-30',?,?,1000,0,1000,'issued','1',(SELECT id FROM users ORDER BY id LIMIT 1))")->execute([$sid, $symbol, $client, $date, $date, $eur, $rate]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index)
            VALUES (?,'Synthetic service',1,'ks',1000,(SELECT id FROM vat_rates ORDER BY id LIMIT 1),0,1000,0,1000,0)")->execute([$id]);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $posting->postDocument($sid, 'invoice', $id, $posting->buildFromInvoice($sid, $id), ['entry_date' => $date, 'posted' => true]);
        return $id;
    }
}
