<?php
declare(strict_types=1);

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository;
use MyInvoice\Service\Property\Services\Settlement\{SettlementOriginAdapter,SettlementApplicationIntent,SettlementReversalIntent};
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class SettlementIntentEpochRollbackAdversarialTest extends TestCase
{
    use ChargeIssuanceFixture;
    use \MyInvoice\Tests\Support\PaymentCorrectionFixture;
    private Connection $db;
    private array $f;
    private string $trigger;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $name = (string) getenv('MYINVOICE_DB_NAME');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D', $name);
        self::assertSame($name, (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame($this->db->pdo(), Bootstrap::buildContainer()->get(Connection::class)->pdo());
        self::assertFalse($this->db->pdo()->inTransaction());
        $this->trigger = 's8_intent_late_' . bin2hex(random_bytes(8));
        $this->db->pdo()->exec("CREATE TRIGGER `{$this->trigger}` BEFORE INSERT ON property_service_settlement_applications FOR EACH ROW BEGIN
            IF COALESCE(@s8_intent_fail_late,0)=1 THEN
                SET @s8_intent_seen_entry=NEW.journal_entry_id;
                SET @s8_intent_seen_lines=(SELECT COUNT(*) FROM journal_entry_lines WHERE entry_id=NEW.journal_entry_id AND supplier_id=NEW.supplier_id);
                SET @s8_intent_seen_credit=(SELECT COUNT(*) FROM unit_credit_lots WHERE supplier_id=NEW.supplier_id);
                SET @s8_intent_seen_debt=(SELECT COUNT(*) FROM unit_payment_debts WHERE supplier_id=NEW.supplier_id);
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_s8_late_owner_failure';
            END IF;
        END");
        $this->db->pdo()->exec('SET @s8_intent_fail_late=0,@s8_intent_seen_entry=NULL,@s8_intent_seen_lines=NULL');
        $this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        $this->db->pdo()->exec('SET @s8_intent_fail_late=0');
        if (isset($this->trigger)) $this->db->pdo()->exec('DROP TRIGGER IF EXISTS `' . $this->trigger . '`');
    }

 private function openingFixture():array {
  $f=$this->chargeFixture($this->db);$this->f=$f;$supplier=$f['supplier'];
  (new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($supplier,2026,'2026-01-01','2026-12-31');
  (new \MyInvoice\Service\Accounting\ChartOfAccountsSeeder($this->db))->seedForSupplier($supplier,'2026-01-01');
  $accounts=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);
  foreach(['315.100','315.210','324.110','379.900','379.910'] as $code){if($accounts->findByCode($supplier,$code)!==null)continue;$parent=$accounts->findByCode($supplier,substr($code,0,3));$accounts->insert($supplier,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
  $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-15','occurrence_ref'=>'2026','title_ref'=>'Synthetic title','evidence_ref'=>'Synthetic takeover reconciliation'];
  $links=[['case_ref'=>'unpaid-service','occurrence_ref'=>'2026','amount_cents'=>'40000']];
  $debt=$common+['case_ref'=>'unpaid-service','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'40000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
  $advance=$common+['case_ref'=>'service-advance','kind'=>'advance','components'=>[['key'=>'advance','role'=>'service_advance','account_code'=>'324.110','amount_cents'=>'100000']],'advance_state'=>['purpose_ref'=>'Synthetic services','settlement_due_on'=>'2027-04-30','prescribed_cents'=>'100000','paid_cents'=>'60000','used_cents'=>'0','unpaid_cases'=>$links]];
  $cost=$common+['case_ref'=>'service-cost','kind'=>'service_carry','components'=>[['key'=>'cost','role'=>'service_cost','account_code'=>'315.210','amount_cents'=>'80000']],'service_state'=>['profile'=>'service_balance_sheet','allocation_ref'=>'Synthetic service key','counterpart_ref'=>'Synthetic supplier evidence','prescribed_cents'=>'100000','paid_cents'=>'60000','settled_cents'=>'0','cancelled_unpaid_cents'=>'0','unpaid_cases'=>$links,'recipient_ids'=>[$f['clients'][1]]]];
  $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$advance,$cost],'other_balances'=>[['account_code'=>'321','side'=>'credit','amount_cents'=>'20000','dimensions'=>[]]]];
  $repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Opening\OpeningRepository::class);$preview=$repo->preview($supplier,$request);$result=$repo->apply($supplier,$request,$preview['preview_hash'],'native-s8-opening',$f['actor']);
  $q=$this->db->pdo()->prepare('SELECT id,kind FROM property_opening_items WHERE supplier_id=? AND batch_id=?');$q->execute([$supplier,$result['id']]);$items=array_column($q->fetchAll(PDO::FETCH_ASSOC),'id','kind');
  return $f+['selector'=>['kind'=>'opening','advance_item_id'=>(int)$items['advance'],'service_item_id'=>(int)$items['service_carry']],'opening'=>$result];
 }
 private function openingDocument(array $f):array {
  $current=Bootstrap::buildContainer()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'SYNTHETIC-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-01-31T23:59:59+01:00','known_at'=>'2026-10-17T12:00:00+02:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic service cost and cutoff dossier','prescribed_cents'=>'100000','paid_cents'=>'60000','actual_cents'=>'80000','origins'=>$current['origins'],'cutoff_allocations'=>[],'cost'=>array_values($current['costs'])[0]];
  return ['document'=>\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts),'current'=>$current];
 }
 private function appliedFixture(bool $apply=true):array {
  $f=$this->openingFixture();$document=$this->openingDocument($f)['document'];
  $docRepo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);
  $saved=$docRepo->save($f['supplier'],$document,[$f['selector']],'2026-12-31',0,'s8-owner-document',$f['actor']);
  $source=$document->snapshot()['origins'][0];$debt=$source['debt_id'];$bank=$this->correctionBank('300.00');
  $r=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'30000','accounting_date'=>'2026-02-01','reason'=>'Synthetic late payment','debts'=>[$debt],'payment'=>['payer_id'=>$f['clients'][1],'debtor_id'=>$f['clients'][1],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$debt],'evidence'=>'Synthetic debtor disposition']]];
  $payments=$this->correctionPayments();$op=$payments->apply($f['supplier'],$r,$payments->preview($f['supplier'],$r)['preview_hash'],'s8-owner-late',$f['actor']);
  $allocation=(int)$this->db->pdo()->query('SELECT id FROM unit_payment_allocations WHERE operation_id='.$op['id'])->fetchColumn();
  $owner=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $request=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic final service disposition','advances'=>[['origin_key'=>$source['kind'].':'.$source['id'],'applied_cents'=>'80000']],'reliefs'=>[['allocation_id'=>$allocation,'amount_cents'=>'10000','entitlement'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic documented creditor','due_date'=>'2027-04-30','account_code'=>'379.910']]],'opening_credits'=>[],'new_claim'=>null];
  $preview=$owner->preview($f['supplier'],$saved['id'],$request,0);

  $result=$apply?$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'s8-apply',$f['actor']):null;

 return compact('f','document','docRepo','saved','source','debt','bank','r','payments','op','allocation','owner','request','preview','result');
 }

    private function pendingFixture(bool $reverse): array
    {
        $fixture = $this->appliedFixture($reverse);
        extract($fixture);
        $token = $reverse ? 'intent-adversarial-reverse' : 'intent-adversarial-apply';
        if ($reverse) {
            $envelope = ['application_id'=>$result['id'],'accounting_date'=>'2026-12-31','expected_version'=>1,'reason'=>'Synthetic intent reversal','token'=>$token,'actor'=>$f['actor']];
            $preview = (new ReflectionMethod($owner, 'reversalPreview'))->invoke($owner, $f['supplier'], $result['id'], '2026-12-31', 1, $envelope['reason']);
        } else {
            $envelope = ['document_id'=>$saved['id'],'request'=>$request,'expected_version'=>0,'preview_hash'=>$preview['preview_hash'],'token'=>$token,'actor'=>$f['actor']];
        }
        $requestId = (new ReflectionMethod($owner, 'request'))->invoke($owner, $f['supplier'], $saved['id'], $reverse ? $result['id'] : null, $reverse ? 1 : 0, $envelope, $preview, $token, $f['actor']);
        $class = $reverse ? SettlementReversalIntent::class : SettlementApplicationIntent::class;
        return $fixture + ['pending_id'=>$requestId,'intent_class'=>$class];
    }

    private function financialState(int $supplier): array
    {
        $state = [];
        foreach (['journal_entries','journal_entry_lines','property_service_settlement_requests','property_service_settlement_applications','property_service_settlement_effects','property_service_settlement_heads','unit_credit_lots','unit_credit_parties','unit_payment_debts','unit_payment_allocations'] as $table) {
            $query = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=?');
            $query->execute([$supplier]);
            $state[$table] = $query->fetchAll(PDO::FETCH_ASSOC);
            usort($state[$table], static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        }
        return $state;
    }

    private function epochRefused(callable $work): void
    {
        try {
            $work();
            self::fail('A phase from an expired transaction epoch was accepted.');
        } catch (LogicException $error) {
            self::assertSame('Intent transaction epoch expired.', $error->getMessage());
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testUnclaimedNativeIntentRejectsLostEpochWithPendingStillCurrent(bool $reverse): void
    {
        extract($this->pendingFixture($reverse));
        $before = $this->financialState($f['supplier']);
        $this->db->pdo()->exec('SAVEPOINT adversarial_before_intent');
        $intent = $intent_class::fromPending($this->db, $owner, $f['supplier'], $pending_id);
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT adversarial_before_intent');
        self::assertSame($pending_id, (int) $owner->pending($this->db, $f['supplier'], $pending_id)['row']['id']);
        $this->epochRefused(fn () => $intent->claim($this->db));
        self::assertSame($before, $this->financialState($f['supplier']));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testClaimedNativeIntentCannotStartJournalAfterEpochRollback(bool $reverse): void
    {
        extract($this->pendingFixture($reverse));
        $before = $this->financialState($f['supplier']);
        $this->db->pdo()->exec('SAVEPOINT adversarial_before_claim');
        $intent = $intent_class::fromPending($this->db, $owner, $f['supplier'], $pending_id);
        $intent->claim($this->db);
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT adversarial_before_claim');
        self::assertSame($pending_id, (int) $owner->pending($this->db, $f['supplier'], $pending_id)['row']['id']);
        try {
            $this->epochRefused(fn () => $intent->journal($this->db));
        } finally {
            self::assertSame($before, $this->financialState($f['supplier']));
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testNativeIntentCompletionRejectsExpiredJournalEpoch(bool $reverse): void
    {
        extract($this->pendingFixture($reverse));
        $before = $this->financialState($f['supplier']);
        $intent = $intent_class::fromPending($this->db, $owner, $f['supplier'], $pending_id);
        $intent->claim($this->db);
        $this->db->pdo()->exec('SAVEPOINT adversarial_before_journal');
        $intent->journal($this->db);
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT adversarial_before_journal');
        self::assertSame($pending_id, (int) $owner->pending($this->db, $f['supplier'], $pending_id)['row']['id']);
        $this->epochRefused(fn () => $intent->complete($this->db, $op['journal_entry_id']));
        self::assertSame($before, $this->financialState($f['supplier']));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testNativeJournalConsumesTypedIntentExactlyOnce(bool $reverse): void
    {
        extract($this->pendingFixture($reverse));
        $intent = $intent_class::fromPending($this->db, $owner, $f['supplier'], $pending_id);
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $method = $reverse ? 'insertPropertyServiceSettlementReversal' : 'insertPropertyServiceSettlement';
        $entry = $journal->$method($intent);
        self::assertGreaterThan(0, $entry);
        $before = $this->financialState($f['supplier']);
        try {
            $journal->$method($intent);
            self::fail('The same typed intent was consumed twice.');
        } catch (LogicException $error) {
            self::assertSame('Intent already consumed.', $error->getMessage());
        }
        self::assertSame($before, $this->financialState($f['supplier']));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testForgedNativeLedgerCompletionCannotCreateSettlementEffects(bool $reverse): void
    {
        extract($this->pendingFixture($reverse));
        $before = $this->financialState($f['supplier']);
        $intent = $intent_class::fromPending($this->db, $owner, $f['supplier'], $pending_id);
        $intent->claim($this->db);
        $intent->journal($this->db);
        try {
            $intent->complete($this->db, $op['journal_entry_id']);
            self::fail('An unrelated real payment journal forged settlement completion.');
        } catch (DomainException $error) {
            self::assertSame('settlement_ledger_mismatch', $error->getMessage());
        }
        self::assertSame($before, $this->financialState($f['supplier']));
    }

    public function testApplicationCarrierRejectsExpiredJournalEpoch(): void
    {
        extract($this->pendingFixture(false));
        $before = $this->financialState($f['supplier']);
        $intent = $intent_class::fromPending($this->db, $owner, $f['supplier'], $pending_id);
        $intent->claim($this->db);
        $this->db->pdo()->exec('SAVEPOINT adversarial_before_carrier');
        $intent->journal($this->db);
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT adversarial_before_carrier');
        self::assertSame($pending_id, (int) $owner->pending($this->db, $f['supplier'], $pending_id)['row']['id']);
        try {
            $this->epochRefused(fn () => $intent->carrier($this->db));
        } finally {
            self::assertSame($before, $this->financialState($f['supplier']));
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testNativeOwnerRollsBackJournalAndAllFinancialEffectsOnLateFailure(bool $reverse): void
    {
        extract($this->appliedFixture($reverse));
        $before = $this->financialState($f['supplier']);
        $this->db->pdo()->exec('SET @s8_intent_fail_late=1');
        try {
            if ($reverse) $owner->reverse($f['supplier'], $result['id'], '2026-12-31', 1, 'Synthetic late failure', 'intent-late-reverse', $f['actor']);
            else $owner->apply($f['supplier'], $saved['id'], $request, 0, $preview['preview_hash'], 'intent-late-apply', $f['actor']);
            self::fail('Injected late owner failure did not fire.');
        } catch (PDOException $error) {
            self::assertStringContainsString('synthetic_s8_late_owner_failure', $error->getMessage());
        } finally {
            $this->db->pdo()->exec('SET @s8_intent_fail_late=0');
        }
        $seen = $this->db->pdo()->query('SELECT @s8_intent_seen_entry AS entry_id,@s8_intent_seen_lines AS line_count,@s8_intent_seen_credit AS credits,@s8_intent_seen_debt AS debts')->fetch(PDO::FETCH_ASSOC);
        self::assertGreaterThan(0, (int) $seen['entry_id']);
        self::assertNotContains((int) $seen['entry_id'], array_map('intval', array_column($before['journal_entries'], 'id')));
        self::assertGreaterThan(0, (int) $seen['line_count']);
        self::assertSame(count($before['unit_credit_lots']), (int) $seen['credits']);
        self::assertSame(count($before['unit_payment_debts']), (int) $seen['debts']);
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertSame($before, $this->financialState($f['supplier']));
    }
}
