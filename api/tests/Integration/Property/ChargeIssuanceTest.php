<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChargeIssuanceTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $fixture;
    private array $request;

    protected function setUp(): void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->fixture=$this->issuanceFixture($this->db);
        $this->request=$this->fixture['issue_request'];
    }

    protected function tearDown(): void
    {
        if(isset($this->db)&&$this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testHouseHasTwelveClaimsAndExactSingleLedgerMarginDespiteMultipleParties(): void
    {
        $f=$this->fixture;
        $preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$this->request);
        self::assertCount(12,$preview['snapshot']['charges']);
        self::assertSame('26400000',$preview['snapshot']['total_cents']);
        $repository=Bootstrap::buildContainer()->get(IssuanceRepository::class);
        $input=['request'=>$this->request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'synthetic-batch'];
        $batch=$repository->issue($f['supplier'],$input,$f['actor']);
        self::assertCount(12,$batch['charges']);
        self::assertSame($batch['id'],$repository->issue($f['supplier'],$input,$f['actor'])['id']);
        $posted=$repository->post($f['supplier'],$batch['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>1],$batch['charges']),$f['actor']);
        self::assertSame(['posted'],array_values(array_unique(array_column($posted['charges'],'status'))));
        $stmt=$this->db->pdo()->prepare("SELECT SUM(l.amount) FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id WHERE e.supplier_id=? AND e.source_type='unit_charge' AND l.side='debit'");
        $stmt->execute([$f['supplier']]);
        self::assertSame('264000.00',$stmt->fetchColumn());
        $stmt=$this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_charge_parties WHERE supplier_id=?'); $stmt->execute([$f['supplier']]);
        self::assertSame(15,(int)$stmt->fetchColumn());
        $stmt=$this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_charge_parties WHERE supplier_id=? AND client_id=?'); $stmt->execute([$f['supplier'],$f['clients'][13]]);
        self::assertSame(0,(int)$stmt->fetchColumn());
    }

    public function testPreviewHashPreventsChangedTitleAndPartialWrites(): void
    {
        $f=$this->fixture;
        $preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$this->request);
        $request=$this->request; $request['reason']='Different decision';
        try {
            Bootstrap::buildContainer()->get(IssuanceRepository::class)->issue($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'stale'],$f['actor']);
            self::fail('Stale preview accepted.');
        } catch(IssuanceException $e) { self::assertSame('property_charge_issue_preview_stale',$e->errorCode); }
        $stmt=$this->db->pdo()->prepare('SELECT COUNT(*) FROM unit_charge_batches WHERE supplier_id=?'); $stmt->execute([$f['supplier']]);
        self::assertSame(0,(int)$stmt->fetchColumn());
    }

    public function testMalformedLegalCollectionsReturnDomainValidationBeforeDatabaseMutation(): void
    {
        foreach(['group','members','delivery'] as $case) {
            $request=$this->request;
            if($case==='group') $request['units'][0]['liability_groups']=[false];
            elseif($case==='members') $request['units'][0]['liability_groups'][0]['members']='not-a-list';
            else $request['units'][0]['notices'][0]['notice']['deliveries']='not-a-list';
            try { (new IssuancePreviewService($this->db))->preview($this->fixture['supplier'],$request); self::fail('Malformed '.$case.' accepted.'); }
            catch(\MyInvoice\Service\Property\PropertyException|IssuanceException $e) { self::assertSame(422,$e->httpStatus); }
        }
    }

    public function testExtraMaterializedDebtorRejectsPostingBeforeAnyJournalWrite(): void
    {
        $batch=$this->issue(); $f=$this->fixture;
        $this->db->pdo()->prepare("INSERT INTO unit_charge_parties (supplier_id,charge_id,client_id,group_key,role,party_json) VALUES (?,?,?,'unexpected','debtor','{}')")
            ->execute([$f['supplier'],$batch['charges'][0]['id'],$f['clients'][18]]);
        try {
            $this->repository()->post($f['supplier'],$batch['id'],$this->expected($batch),$f['actor']);
            self::fail('Unexpected materialized debtor accepted.');
        } catch(IssuanceException $e) { self::assertSame('property_charge_issue_history_corrupt',$e->errorCode); }
        $stmt=$this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='unit_charge'");
        $stmt->execute([$f['supplier']]); self::assertSame(0,(int)$stmt->fetchColumn());
    }

    public function testForeignTenantPersonIsRejectedByPreviewAndCompositeDatabaseConstraint(): void
    {
        $batch=$this->issue(); $other=$this->issuanceFixture($this->db); $request=$this->request;
        $request['units'][0]['liability_groups'][0]['members']=[$other['clients'][1]];
        try { (new IssuancePreviewService($this->db))->preview($this->fixture['supplier'],$request); self::fail('Foreign tenant person accepted.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_party_unavailable',$e->errorCode); }
        try {
            $this->db->pdo()->prepare("INSERT INTO unit_charge_parties (supplier_id,charge_id,client_id,group_key,role,party_json) VALUES (?,?,?,'foreign','debtor','{}')")
                ->execute([$this->fixture['supplier'],$batch['charges'][0]['id'],$other['clients'][1]]);
            self::fail('Foreign tenant composite FK accepted.');
        } catch(\PDOException $e) { self::assertSame('23000',$e->getCode()); }
        self::assertCount(12,(new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($this->db))->batch($this->fixture['supplier'],$batch['id'])['charges']);
    }

    public function testFullReversalPreservesSnapshotAndRetryNeverCreatesAnotherMirror(): void
    {
        $f=$this->fixture; $batch=$this->issue();
        $posted=$this->repository()->post($f['supplier'],$batch['id'],$this->expected($batch),$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
        $charge=$posted['charges'][0];
        $input=['row_version'=>2,'accounting_date'=>'2026-02-01','effective_at'=>'2026-02-01T10:00:00+01:00',
            'reason'=>'Synthetic full correction','evidence_ref'=>'Synthetic correction deed','idempotency_key'=>'synthetic-reverse'];
        $reversed=$this->repository()->reverse($f['supplier'],$charge['id'],$input,$f['actor']);
        self::assertSame('reversed',$reversed['status']);
        self::assertSame($charge['snapshot_hash'],$reversed['snapshot_hash']);
        self::assertSame($reversed,$this->repository()->reverse($f['supplier'],$charge['id'],$input,$f['actor']));
        $stmt=$this->db->pdo()->prepare("SELECT SUM(CASE l.side WHEN 'debit' THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id IN (?,?) AND a.account_code='315'");
        $stmt->execute([$charge['journal_entry_id'],$reversed['reversal_entry_id']]); self::assertSame('0.00',$stmt->fetchColumn());
        $input['reason']='Changed evidence';
        try { $this->repository()->reverse($f['supplier'],$charge['id'],$input,$f['actor']); self::fail('Changed retry accepted.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_idempotency_conflict',$e->errorCode); }
    }

    public function testCancelledDraftRetainsHistoryWithoutLedgerAndAllowsExplicitReplacement(): void
    {
        $f=$this->fixture; $batch=$this->issue(); $charge=$batch['charges'][0];
        $input=['row_version'=>1,'accounting_date'=>'2026-01-01','effective_at'=>'2026-01-02T10:00:00+01:00',
            'reason'=>'Synthetic withdrawn draft','evidence_ref'=>'Synthetic correction deed','idempotency_key'=>'synthetic-cancel'];
        $cancelled=$this->repository()->reverse($f['supplier'],$charge['id'],$input,$f['actor']);
        self::assertSame('cancelled',$cancelled['status']); self::assertNull($cancelled['journal_entry_id']);
        $request=$this->request; $request['issue_mode']='replacement';
        $request['units']=array_values(array_filter($request['units'],fn($u)=>$u['unit_id']===$charge['unit_id']));
        $request['units'][0]['expected_revision']=1; $request['units'][0]['previous_charge_id']=$charge['id'];
        $preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$request);
        $next=$this->repository()->issue($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'replacement'],$f['actor']);
        self::assertSame(2,$next['charges'][0]['revision_no']);
        self::assertSame($charge['id'],$next['charges'][0]['previous_charge_id']);
    }

    public function testCancelledUnitDoesNotPreventPostingOtherUnitsOfOriginalBatch(): void
    {
        $f=$this->fixture; $batch=$this->issue(); $charge=$batch['charges'][0];
        $cancelled=$this->repository()->reverse($f['supplier'],$charge['id'],['row_version'=>1,'accounting_date'=>'2026-01-01',
            'effective_at'=>'2026-01-02T10:00:00+01:00','reason'=>'Synthetic withdrawn unit','evidence_ref'=>'Synthetic title',
            'idempotency_key'=>'cancel-one'],$f['actor']);
        $batch['charges'][0]=$cancelled;
        $posted=$this->repository()->post($f['supplier'],$batch['id'],$this->expected($batch),$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
        self::assertSame('cancelled',$posted['charges'][0]['status']);
        self::assertNull($posted['charges'][0]['journal_entry_id']);
        self::assertCount(11,array_filter($posted['charges'],fn($row)=>$row['status']==='posted'));
        self::assertSame($posted,$this->repository()->post($f['supplier'],$batch['id'],$this->expected($posted),$f['actor']));
    }

    public function testReminderAndAllocationFactsAppendWithoutChangingDebtOrInferringConsumer(): void
    {
        $f=$this->fixture; $batch=$this->issue(); $charge=$batch['charges'][0];
        $member=$charge['snapshot']['liability_groups'][0]['members'][0];
        $input=['request'=>['kind'=>'reminder','effective_at'=>'2026-02-02T10:00:00+01:00','expected_event_id'=>0,
            'reason'=>'Synthetic reminder','evidence_ref'=>'Synthetic reminder deed','facts'=>['recipient_id'=>$member,
                'represented_ids'=>[$member],'authority_ref'=>'','delivered_at'=>'2026-02-02T09:00:00+01:00','delivery_ref'=>'Synthetic delivery']],
            'idempotency_key'=>'reminder'];
        $event=$this->repository()->recordEvidence($f['supplier'],$charge['id'],$input,$f['actor']);
        self::assertSame($event,$this->repository()->recordEvidence($f['supplier'],$charge['id'],$input,$f['actor']));
        $history=(new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($this->db))->evidence($f['supplier'],$charge['id']);
        self::assertCount(1,$history); self::assertSame('reminder',$history[0]['request']['kind']);
        $input['idempotency_key']='other-reminder';
        try { $this->repository()->recordEvidence($f['supplier'],$charge['id'],$input,$f['actor']); self::fail('Stale evidence CAS accepted.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_revision_conflict',$e->errorCode); }
        $current=(new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($this->db))->find($f['supplier'],$charge['id']);
        self::assertSame($charge['snapshot_hash'],$current['snapshot_hash']);
        self::assertSame('unassessed',$batch['snapshot']['allocation_regime']);
    }

    public function testClosedSourceCanBeExactlyReversedIntoOpenYearAfterModuleAndDimensionRetirement(): void
    {
        $f=$this->fixture; $batch=$this->issue();
        $posted=$this->repository()->post($f['supplier'],$batch['id'],$this->expected($batch),$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL); $charge=$posted['charges'][0];
        (new AccountingPeriodRepository($this->db))->create($f['supplier'],2027,'2027-01-01','2027-12-31');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND starts_on='2026-01-01'")->execute([$f['supplier']]);
        (new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->save($f['supplier'],[
            'expected_revision'=>1,'effective_from'=>'2026-02-01T00:00:00+01:00','enabled'=>false,
            'reason'=>'Synthetic retired module','title_ref'=>'Synthetic decision','idempotency_key'=>'disable'],$f['actor']);
        $this->db->pdo()->prepare("UPDATE dimension_values v JOIN dimension_types t ON t.id=v.type_id SET v.is_active=0 WHERE v.supplier_id=? AND t.system_key IN ('unit','service')")->execute([$f['supplier']]);
        $input=['row_version'=>2,'accounting_date'=>'2026-02-01','effective_at'=>'2027-01-02T10:00:00+01:00',
            'reason'=>'Synthetic historical correction','evidence_ref'=>'Synthetic correction deed','idempotency_key'=>'historical-reverse'];
        try { $this->repository()->reverse($f['supplier'],$charge['id'],$input,$f['actor']); self::fail('Closed destination accepted.'); }
        catch(\MyInvoice\Service\Accounting\PostingException $e) { self::assertSame('period_not_open',$e->errorCode); }
        $reader=new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($this->db);
        self::assertSame('posted',$reader->find($f['supplier'],$charge['id'])['status']);
        $input['accounting_date']='2027-01-02';
        $reversed=$this->repository()->reverse($f['supplier'],$charge['id'],$input,$f['actor']);
        self::assertSame('reversed',$reversed['status']); self::assertSame($charge['snapshot_hash'],$reversed['snapshot_hash']);
        $stmt=$this->db->pdo()->prepare('SELECT entry_date FROM journal_entries WHERE id=?'); $stmt->execute([$reversed['reversal_entry_id']]);
        self::assertSame('2027-01-02',$stmt->fetchColumn());
    }

    public function testDifferenceKeepsLedgerAnchorsAndBlocksHiddenAccountReclassification(): void
    {
        $f=$this->fixture; $batch=$this->issue();
        $posted=$this->repository()->post($f['supplier'],$batch['id'],$this->expected($batch),$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
        $charge=array_values(array_filter($posted['charges'],fn($c)=>$c['unit_id']===$f['units']['G12']))[0];
        $request=$this->differenceRequest($charge,'1100');
        (new \MyInvoice\Repository\ChartOfAccountsRepository($this->db))->insert($f['supplier'],[
            'account_code'=>'324.001','name'=>'Synthetic other advances','account_type'=>'liability','normal_side'=>'credit']);
        foreach($request['accounts'] as &$account) if($account['component_id']===$f['components']['ADMIN']) $account['counter_account']='324.001'; unset($account);
        $this->expectException(IssuanceException::class);
        $this->expectExceptionMessage('Účty a dimenze');
        (new IssuancePreviewService($this->db))->preview($f['supplier'],$request);
    }

    public function testNegativeDifferenceReducesOriginalOnceAndCannotReverseAncestorFirst(): void
    {
        $f=$this->fixture; $batch=$this->issue();
        $posted=$this->repository()->post($f['supplier'],$batch['id'],$this->expected($batch),$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
        $charge=array_values(array_filter($posted['charges'],fn($c)=>$c['unit_id']===$f['units']['G12']))[0];
        $request=$this->differenceRequest($charge,'900');
        $preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$request);
        self::assertSame('-10000',$preview['snapshot']['total_cents']);
        $next=$this->repository()->issue($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'negative-difference'],$f['actor']);
        $next=$this->repository()->post($f['supplier'],$next['id'],$this->expected($next),$f['actor']);
        $stmt=$this->db->pdo()->prepare("SELECT SUM(CASE l.side WHEN 'debit' THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.entry_id IN (?,?) AND a.account_code='315'");
        $stmt->execute([$charge['journal_entry_id'],$next['charges'][0]['journal_entry_id']]);
        self::assertSame(bcdiv(bcsub($charge['amount_cents'],'10000',0),'100',2),$stmt->fetchColumn());
        try { $this->repository()->reverse($f['supplier'],$charge['id'],['row_version'=>2,'accounting_date'=>'2026-02-01','effective_at'=>'2026-02-01T10:00:00+01:00',
            'reason'=>'Synthetic ancestor correction','evidence_ref'=>'Synthetic deed','idempotency_key'=>'ancestor'],$f['actor']); self::fail('Active successor ignored.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_successor_active',$e->errorCode); }
    }

    private function differenceRequest(array $charge,string $adminAmount): array
    {
        $f=$this->fixture; $version=self::chargeRequest($f); $version['expected_revision']=1;
        foreach($version['rates'] as &$rate) if($rate['component_id']===$f['components']['ADMIN']) $rate['amount']=$adminAmount; unset($rate);
        $preview=(new ChargePreviewService($this->db))->preview($f['supplier'],$version);
        $saved=(new ChargeRepository($this->db))->saveVersion($f['supplier'],['request'=>$version,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'corrected-version'],$f['actor']);
        $request=$this->request; $request['version_id']=$saved['id']; $request['issue_mode']='difference';
        $request['units']=array_values(array_filter($request['units'],fn($u)=>$u['unit_id']===$charge['unit_id']));
        $request['units'][0]['expected_revision']=1; $request['units'][0]['previous_charge_id']=$charge['id'];
        return $request;
    }

    private function repository(): IssuanceRepository { return Bootstrap::buildContainer()->get(IssuanceRepository::class); }
    private function issue(): array
    {
        $preview=(new IssuancePreviewService($this->db))->preview($this->fixture['supplier'],$this->request);
        return $this->repository()->issue($this->fixture['supplier'],['request'=>$this->request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'synthetic-batch'],$this->fixture['actor']);
    }
    private function expected(array $batch): array { return array_map(fn($c)=>['id'=>$c['id'],'row_version'=>$c['row_version']],$batch['charges']); }
}
