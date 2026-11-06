<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Funds;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Property\Funds\FundingRead;
use MyInvoice\Tests\Support\FundingFixture;
use PHPUnit\Framework\TestCase;
final class FundingNativeTest extends TestCase
{
    use FundingFixture;
    public function testReferenceLongAdvancesAdministrationAndOwnerIncome():void
    {
        $this->fundingOpening();$this->fundingIssue();$s=$this->f['supplier'];$house=$this->f['house']['id'];$read=new FundingRead($this->db);
        $r=$read->at($s,$house,'2026-01-01');self::assertTrue($r['matches'],json_encode($r['differences']));self::assertSame('42000000',$r['totals']['955.100']);self::assertSame('1200000',$r['totals']['324.200']);
        $repair=$this->fundingCost('2026-03-01','549.900','90000.00');$repairRequest=$this->fundingDraw('2026-03-01','repair','9000000',$repair);$owner=new FundingRepository($this->db);$preview=$owner->preview($s,$repairRequest,$this->f['actor']);$draw=$owner->apply($s,$repairRequest,$preview['preview_hash'],'repair',$this->f['actor']);self::assertSame($draw['id'],$owner->apply($s,$repairRequest,$preview['preview_hash'],'repair',$this->f['actor'])['id']);
        $improvement=$this->fundingCost('2026-06-01','549.910','150000.00');$this->fundingApply($this->fundingDraw('2026-06-01','improvement','15000000',$improvement),'improvement');
        $admin=$this->fundingCost('2026-09-01','518.200','10000.00');$this->fundingApply($this->fundingDraw('2026-09-01','administration','1000000',$admin,'issued'),'administration');
        $r=$this->fundingRequest('income','2026-12-01','6000000')+['bank_id'=>$this->fundingBank('2026-12-01','60000.00'),'key'=>$this->fundingKey('2026-12-01')];$income=$this->fundingApply($r,'roof-income');self::assertSame('bank',$income['receipt']['ledger']['entry']['source_type']);
        $dispositions=[];foreach($income['receipt']['effects'] as $effect)$dispositions[]=['source_id'=>$effect['id'],'amount_cents'=>bcdiv(bcmul($effect['amount_cents'],'3',0),'4',0),'party_ids'=>$effect['parties']['members'],'evidence_ref'=>'Synthetic separate owner contribution consent'];
        $this->fundingApply($this->fundingRequest('transfer','2026-12-02','4500000')+['dispositions'=>$dispositions,'target'=>'long_term','purpose_ref'=>'Synthetic future roof repairs','settlement_due_on'=>'2029-12-31'],'owner-contribution');
        $dispositions=[];foreach($income['receipt']['effects'] as $effect)$dispositions[]=['source_id'=>$effect['id'],'amount_cents'=>bcdiv($effect['amount_cents'],'6',0),'party_ids'=>$effect['parties']['members'],'evidence_ref'=>'Synthetic owner payout instruction','recipient_authority_ref'=>'Synthetic owner mandate to recipient'];
        $this->fundingApply($this->fundingRequest('payout','2026-12-03','1000000')+['dispositions'=>$dispositions,'bank_id'=>$this->fundingBank('2026-12-03','-10000.00'),'recipient_id'=>$this->f['clients'][1],'recipient_account'=>'1000000005/0100'],'owner-payout');
        $final=$read->at($s,null,'2026-12-31');self::assertTrue($final['matches'],json_encode($final['differences']));self::assertSame('22500000',$final['totals']['955.100']);self::assertSame('200000',$final['totals']['324.200']);self::assertSame('500000',$final['totals']['379.920']);self::assertSame($final['totals'],$final['ledger_totals']);self::assertSame('30000000',$final['component_totals']['opening']['955.100']);self::assertSame('12000000',$final['component_totals']['issued']['955.100']);self::assertSame('-24000000',$final['component_totals']['draw']['955.100']);
        $before=$read->at($s,$house,'2026-02-28');self::assertTrue($before['matches']);self::assertSame('42000000',$before['totals']['955.100']);$unit=$read->at($s,null,'2026-12-31',null,$this->f['units']['G12']);self::assertTrue($unit['matches']);self::assertSame('450000',$unit['totals']['955.100']);
        $q=$this->db->pdo()->prepare("SELECT c.account_code,SUM(CASE l.side WHEN 'debit' THEN l.amount ELSE -l.amount END) balance FROM journal_entry_lines l JOIN chart_of_accounts c ON c.supplier_id=l.supplier_id AND c.id=l.account_id WHERE l.supplier_id=? AND (LEFT(c.account_code,1)='0' OR c.account_code IN ('549.900','549.910','518.200','649.100','649.200')) GROUP BY c.account_code");$q->execute([$s]);self::assertSame(['518.200'=>'10000.00','549.900'=>'90000.00','549.910'=>'150000.00','649.100'=>'-240000.00','649.200'=>'-10000.00'],array_column($q->fetchAll(\PDO::FETCH_ASSOC),'balance','account_code'));
    }
}
