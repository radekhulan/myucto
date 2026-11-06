<?php
declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository;
use MyInvoice\Service\Property\Services\Settlement\{SettlementCostProof,SettlementEvidence,SettlementOriginAdapter};
use MyInvoice\Service\Property\PropertyInput;
use PDO;
final class SettlementCostCloneProof
{
    public static function captureInputs(Connection $db):array
    {
        self::context($db);$inputs=[];self::each($db,function(array $row,array $doc,SettlementOriginAdapter $adapter)use($db,&$inputs):void{self::native($db,$row,$doc,$adapter);$inputs[]=['service_composite_cost'=>self::marker($row,$doc)];});return $inputs;
    }
    public static function verify(Connection $db,array $inputs):void
    {
        self::context($db);$markers=[];foreach($inputs as $input)if(isset($input['service_composite_cost'])){$marker=$input['service_composite_cost'];$markers[$marker['supplier_id'].':'.$marker['document_id']]=$marker;}
        self::each($db,function(array $row,array $doc,SettlementOriginAdapter $adapter)use($db,$markers):void{$marker=self::marker($row,$doc);$expected=$markers[$marker['supplier_id'].':'.$marker['document_id']]??null;if($expected===null||PropertyInput::hash($marker)!==PropertyInput::hash($expected))throw new \LogicException('Composite service clone requires its original native proof capture.');self::native($db,$row,$doc,$adapter);});
    }
    private static function context(Connection $db):void
    {
        if(!$db->pdo()->inTransaction()||TriggerMetadata::any($db->pdo()))throw new \LogicException('Composite service clone requires the transaction before trigger installation.');
    }
    private static function each(Connection $db,callable $callback):void
    {
        $container=Bootstrap::buildContainer();$container->set(Connection::class,$db);$documents=$container->get(SettlementDocumentRepository::class);$adapter=$container->get(SettlementOriginAdapter::class);foreach($db->pdo()->query("SELECT supplier_id,id FROM property_service_settlements WHERE JSON_CONTAINS_PATH(document_json,'one','$.cost.sources') ORDER BY supplier_id,id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC) as $row){$doc=$documents->find((int)$row['supplier_id'],(int)$row['id'],true);$callback($row,$doc,$adapter);}
    }
    private static function native(Connection $db,array $row,array $doc,SettlementOriginAdapter $adapter):void
    {
        $facts=$doc['document'];(new SettlementCostProof($db))->assertRecorded((int)$row['supplier_id'],$facts['unit_id'],$facts['service_id'],$doc['origin_receipt']['request']['accounting_date'],['from'=>$facts['period_from'],'to'=>$facts['period_to']],$facts['cost'],$adapter);
    }
    private static function marker(array $row,array $doc):array
    {
        return ['supplier_id'=>(int)$row['supplier_id'],'document_id'=>(int)$row['id'],'amount_cents'=>$doc['document']['cost']['amount_cents'],'source_ids'=>array_column(SettlementEvidence::costSources($doc['document']['cost']),'source_id')];
    }
}
