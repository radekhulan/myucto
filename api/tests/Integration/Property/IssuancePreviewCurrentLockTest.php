<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class IssuancePreviewCurrentLockTest extends TestCase
{
    use ChargeIssuanceFixture;
    use OwnedSandboxDatabase;

    private const SANDBOX='issue_current_lock';

    private array $connections=[];

    protected function tearDown():void
    {
        foreach($this->connections as $db){if($db->pdo()->inTransaction())$db->pdo()->rollBack();$db->close();}
        $this->connections=[];
    }

    public function testCurrentPreviewSeesRevisionCommittedAfterRepeatableReadSnapshot():void
    {
        $a=$this->connection();$a->pdo()->beginTransaction();$fixture=$this->issuanceFixture($a);$a->pdo()->commit();
        $base=$fixture['issue_request'];$base['units']=[$base['units'][0]];$base['source_key']='CONCURRENT-FAMILY-B';
        $b=$this->connection();$b->pdo()->beginTransaction();$basePreview=(new IssuancePreviewService($b))->preview($fixture['supplier'],$base);
        $family=$this->repository($b)->issue($fixture['supplier'],['request'=>$base,'preview_hash'=>$basePreview['preview_hash'],'idempotency_key'=>'current-family-base'],$fixture['actor']);$b->pdo()->commit();

        $request=$fixture['issue_request'];$request['units']=[$request['units'][0]];$request['source_key']='CONCURRENT-FAMILY-A';
        $request['case_identity']['case_ref']='SYNTHETIC-CONCURRENT-A';
        $request['case_identity']['title_ref']='Synthetic concurrent independent title A';
        $request['units'][0]['case_distinction']=['against_source_keys'=>['CONCURRENT-FAMILY-B'],'legal_reason'=>'Separate synthetic legal title','evidence_ref'=>'Synthetic independent decision evidence'];

        $a->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$a->pdo()->beginTransaction();
        $stale=(new IssuancePreviewService($a))->preview($fixture['supplier'],$request);
        self::assertSame(1,$stale['snapshot']['charges'][0]['case_candidates'][0]['latest_revision']);

        $b->pdo()->beginTransaction();$this->appendRevision($b,$family['charges'][0]);$b->pdo()->commit();
        try{
            $this->repository($a)->issue($fixture['supplier'],['request'=>$request,'preview_hash'=>$stale['preview_hash'],'idempotency_key'=>'stale-current-preview'],$fixture['actor']);
            self::fail('Current preview accepted a family revision committed after its repeatable-read snapshot.');
        }catch(IssuanceException $e){self::assertSame('property_charge_issue_preview_stale',$e->errorCode);}
    }

    public function testCurrentPreviewTargetsNewestChargeCommittedAfterSnapshot():void
    {
        $a=$this->connection();$a->pdo()->beginTransaction();$fixture=$this->issuanceFixture($a);$a->pdo()->commit();
        $base=$fixture['issue_request'];$base['units']=[$base['units'][0]];$base['source_key']='CONCURRENT-TARGET-FAMILY';
        $b=$this->connection();$b->pdo()->beginTransaction();$basePreview=(new IssuancePreviewService($b))->preview($fixture['supplier'],$base);
        $family=$this->repository($b)->issue($fixture['supplier'],['request'=>$base,'preview_hash'=>$basePreview['preview_hash'],'idempotency_key'=>'current-target-base'],$fixture['actor']);$b->pdo()->commit();

        $a->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$a->pdo()->beginTransaction();
        $q=$a->pdo()->prepare('SELECT COUNT(*) FROM unit_charges WHERE supplier_id=?');$q->execute([$fixture['supplier']]);self::assertGreaterThan(0,(int)$q->fetchColumn());
        $b->pdo()->beginTransaction();$latest=$this->appendRevision($b,$family['charges'][0],false);$b->pdo()->commit();
        $history=(new IssuanceReadRepository($a))->previewHistory($fixture['supplier'],[
            'source_key'=>'CONCURRENT-MISSING','period_from'=>'2026-01-01','period_to'=>'2026-12-31','units'=>[['unit_id'=>$family['charges'][0]['unit_id']]],
        ],true);
        self::assertSame(2,$history['families'][0]['latest_revision']);
        self::assertSame($latest,$history['families'][0]['latest_charge_id']);
        self::assertSame($latest,$history['targets'][0]['id']);
    }

    private function appendRevision(Connection $db,array $charge,bool $future=true):int
    {
        $pdo=$db->pdo();$snapshot=$charge['snapshot'];$snapshot['revision_no']=2;$snapshot['previous_charge_id']=$charge['id'];
        if($future){$snapshot['period_from']='2027-01-01';$snapshot['period_to']='2027-12-31';}
        $q=$pdo->prepare('SELECT * FROM unit_charge_batches WHERE supplier_id=? AND id=?');$q->execute([$charge['supplier_id'],$charge['batch_id']]);$batch=$q->fetch(PDO::FETCH_ASSOC);
        $request=json_decode($batch['request_json'],true,flags:JSON_THROW_ON_ERROR);$token='current-family-revision';
        $requestHash=PropertyInput::hash(['request'=>$request,'preview_hash'=>$batch['preview_hash'],'idempotency_key'=>$token,'actor'=>(int)$batch['created_by']]);
        $pdo->prepare('INSERT INTO unit_charge_batches (supplier_id,version_id,request_json,snapshot_json,preview_hash,request_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$charge['supplier_id'],$batch['version_id'],$batch['request_json'],$batch['snapshot_json'],$batch['preview_hash'],$requestHash,$token,$batch['created_by'],$batch['recorded_at']]);$batchId=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO unit_charges (supplier_id,batch_id,unit_id,source_key,revision_no,previous_charge_id,title,issue_mode,period_from,period_to,claim_origin_at,issued_at,accounting_date,due_date,amount_cents,snapshot_json,snapshot_hash,status,row_version) VALUES (?,?,?,?,2,?,?,?,?,?,?,?,?,?,?,?,?,\'draft\',1)')
            ->execute([$charge['supplier_id'],$batchId,$charge['unit_id'],$charge['source_key'],$charge['id'],$snapshot['title'],$snapshot['issue_mode'],$snapshot['period_from'],$snapshot['period_to'],$charge['claim_origin_at'],$charge['issued_at'],$snapshot['accounting_date'],$snapshot['due_date'],$snapshot['amount_cents'],IssuanceRequest::json($snapshot),PropertyInput::hash($snapshot)]);
        $id=(int)$pdo->lastInsertId();
        if(!$future)foreach(['lines','parties','notices']as$kind){
            $q=$pdo->prepare('SELECT * FROM unit_charge_'.$kind.' WHERE supplier_id=? AND charge_id=? ORDER BY id');$q->execute([$charge['supplier_id'],$charge['id']]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row){unset($row['id']);$row['charge_id']=$id;$columns=array_keys($row);$pdo->prepare('INSERT INTO unit_charge_'.$kind.' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));}
        }
        return $id;
    }

    private function repository(Connection $db):IssuanceRepository
    {
        $container=Bootstrap::buildContainer();$container->set(Config::class,self::sandboxConfig(self::SANDBOX));$container->set(Connection::class,$db);return $container->get(IssuanceRepository::class);
    }

    private function connection():Connection
    {
        // Test commituje podvrženou revizi předpisu, kterou append-only triggery nedovolí
        // smazat; ve sdílené DB by shodila každý další klon celé databáze.
        $db=self::sandboxConnection(self::SANDBOX);$db->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');$this->connections[]=$db;return $db;
    }
}
