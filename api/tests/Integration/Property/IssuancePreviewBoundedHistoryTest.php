<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PDO;
use PHPUnit\Framework\TestCase;

final class IssuancePreviewBoundedHistoryTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $fixture;
    private array $charge;

    protected function setUp():void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);$pdo=$this->db->pdo();
        self::assertStringEndsWith('_test',(string)$pdo->query('SELECT DATABASE()')->fetchColumn());$pdo->beginTransaction();
        $this->fixture=$this->issuanceFixture($this->db);$preview=(new IssuancePreviewService($this->db))->preview($this->fixture['supplier'],$this->fixture['issue_request']);
        $batch=Bootstrap::buildContainer()->get(IssuanceRepository::class)->issue($this->fixture['supplier'],[
            'request'=>$this->fixture['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'bounded-history-base'],$this->fixture['actor']);
        $this->charge=$batch['charges'][0];
    }

    protected function tearDown():void
    {
        if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();
    }

    public function testQueryCountStaysBoundedAsSyntheticFamiliesGrow():void
    {
        [$small,$smallSelects]=$this->read();self::assertCount(1,$small['families']);self::assertCount(1,$small['targets']);
        for($n=1;$n<=120;$n++)$this->cloneFamily($n);
        [$large,$largeSelects]=$this->read();
        self::assertCount(121,$large['families']);self::assertCount(1,$large['targets']);self::assertSame([],$large['source']);
        self::assertSame($smallSelects,$largeSelects,'History growth must not add SELECT statements or materialize every historical child graph.');
        self::assertLessThanOrEqual(8,$largeSelects);
    }

    private function read():array
    {
        $pdo=$this->db->pdo();$before=$this->selects();
        $history=(new IssuanceReadRepository($this->db))->previewHistory($this->fixture['supplier'],[
            'source_key'=>'SYNTHETIC-MISSING','period_from'=>'2026-01-01','period_to'=>'2026-12-31',
            'units'=>[['unit_id'=>$this->charge['unit_id']]]],true);
        return [$history,$this->selects()-$before];
    }

    private function selects():int
    {
        $row=$this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM);return (int)$row[1];
    }

    private function cloneFamily(int $number):void
    {
        $pdo=$this->db->pdo();$supplier=$this->fixture['supplier'];$source='SYNTHETIC-HISTORY-'.str_pad((string)$number,3,'0',STR_PAD_LEFT);
        $batch=$pdo->prepare('SELECT * FROM unit_charge_batches WHERE supplier_id=? AND id=?');$batch->execute([$supplier,$this->charge['batch_id']]);$batch=$batch->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare('INSERT INTO unit_charge_batches (supplier_id,version_id,request_json,snapshot_json,preview_hash,request_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$supplier,$batch['version_id'],$batch['request_json'],$batch['snapshot_json'],$batch['preview_hash'],PropertyInput::hash([$source]),'bounded-'.$number,$batch['created_by'],$batch['recorded_at']]);
        $batchId=(int)$pdo->lastInsertId();$snapshot=$this->charge['snapshot'];$snapshot['source_key']=$source;
        $pdo->prepare('INSERT INTO unit_charges (supplier_id,batch_id,unit_id,source_key,revision_no,previous_charge_id,title,issue_mode,period_from,period_to,claim_origin_at,issued_at,accounting_date,due_date,amount_cents,snapshot_json,snapshot_hash,status,row_version) VALUES (?,?,?,?,1,NULL,?,?,?,?,?,?,?,?,?,?,?,\'draft\',1)')
            ->execute([$supplier,$batchId,$this->charge['unit_id'],$source,$snapshot['title'],$snapshot['issue_mode'],$snapshot['period_from'],$snapshot['period_to'],
                $this->charge['claim_origin_at'],$this->charge['issued_at'],$snapshot['accounting_date'],$snapshot['due_date'],$snapshot['amount_cents'],IssuanceRequest::json($snapshot),PropertyInput::hash($snapshot)]);
        $chargeId=(int)$pdo->lastInsertId();
        foreach(['lines','parties','notices']as$kind){
            $rows=$pdo->prepare('SELECT * FROM unit_charge_'.$kind.' WHERE supplier_id=? AND charge_id=? ORDER BY id');$rows->execute([$supplier,$this->charge['id']]);
            foreach($rows->fetchAll(PDO::FETCH_ASSOC)as$row){unset($row['id']);$row['charge_id']=$chargeId;$columns=array_keys($row);$pdo->prepare('INSERT INTO unit_charge_'.$kind.' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));}
        }
    }
}
