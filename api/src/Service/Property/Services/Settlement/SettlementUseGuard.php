<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Payments\PaymentException;
final class SettlementUseGuard
{
    use SettlementSql;
    public const TABLES=['property_service_settlements','property_service_settlement_origins','property_service_settlement_requests','property_service_settlement_heads','property_service_settlement_applications','property_service_settlement_effects'];
    public function __construct(private readonly Connection $db){}
    public static function assertGenericInsert(array $header):void
    {
        if(in_array($header['source_type']??null,['property_service_settlement','property_service_lifecycle'],true))throw new \MyInvoice\Service\Accounting\PostingException('property_service_settlement_owner_required','Vyúčtování služeb musí zaúčtovat jeho vlastník.',409);
    }
    public static function entryReferenceSql():string
    {
        return "EXISTS (SELECT 1 FROM property_service_lifecycle_events le WHERE le.supplier_id=e.supplier_id AND le.journal_entry_id=e.id FOR UPDATE) OR EXISTS (SELECT 1 FROM property_service_lifecycle_claims lc JOIN journal_entry_lines ll ON ll.id=lc.line_id AND ll.supplier_id=lc.supplier_id WHERE lc.supplier_id=e.supplier_id AND ll.entry_id=e.id FOR UPDATE) OR EXISTS (SELECT 1 FROM property_service_settlement_applications sa WHERE sa.supplier_id=e.supplier_id AND sa.journal_entry_id=e.id FOR UPDATE)
        OR EXISTS (SELECT 1 FROM property_service_settlements sd JOIN journal_entry_lines sl ON sl.supplier_id=sd.supplier_id AND sl.id=sd.cost_line_id JOIN property_service_settlement_applications sa ON sa.supplier_id=sd.supplier_id AND sa.document_id=sd.id WHERE sd.supplier_id=e.supplier_id AND sl.entry_id=e.id FOR UPDATE)
        OR EXISTS (SELECT 1 FROM property_service_settlements sd JOIN JSON_TABLE(sd.document_json,'$.cost.sources[*]' COLUMNS(line_id BIGINT PATH '$.source_id')) cs JOIN journal_entry_lines sl ON sl.supplier_id=sd.supplier_id AND sl.id=cs.line_id JOIN property_service_settlement_applications sa ON sa.supplier_id=sd.supplier_id AND sa.document_id=sd.id WHERE sd.supplier_id=e.supplier_id AND sl.entry_id=e.id FOR UPDATE)
        OR EXISTS (SELECT 1 FROM property_service_settlement_origins so JOIN property_service_settlement_applications sa ON sa.supplier_id=so.supplier_id AND sa.document_id=so.document_id JOIN unit_charge_lines cl ON cl.supplier_id=so.supplier_id AND cl.id=so.charge_line_id JOIN unit_charges c ON c.supplier_id=cl.supplier_id AND c.id=cl.charge_id WHERE so.supplier_id=e.supplier_id AND c.journal_entry_id=e.id FOR UPDATE)
        OR EXISTS (SELECT 1 FROM property_service_settlement_origins so JOIN property_service_settlement_applications sa ON sa.supplier_id=so.supplier_id AND sa.document_id=so.document_id JOIN property_opening_components pc ON pc.supplier_id=so.supplier_id AND pc.id=so.opening_advance_component_id JOIN property_opening_items pi ON pi.supplier_id=pc.supplier_id AND pi.id=pc.item_id JOIN property_opening_batches pb ON pb.supplier_id=pi.supplier_id AND pb.id=pi.batch_id WHERE so.supplier_id=e.supplier_id AND pb.journal_entry_id=e.id FOR UPDATE)";
    }
    public function assertDescriptionMutable(int $supplier,int $entry):void
    {
        (new \MyInvoice\Service\Property\Payments\PaymentLocks($this->db))->acquire($supplier);
        try{$this->assertEntriesMutable($supplier,[$entry]);}catch(IssuanceException $e){throw new \MyInvoice\Service\Accounting\PostingException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details);}
    }
    public function assertEntriesMutable(int $supplier,array $ids):void
    {
        $this->transaction();foreach(array_chunk(array_values(array_unique($ids)),500) as $chunk){$row=$this->one(\MyInvoice\Infrastructure\Database\SubqueryCache::OFF.'SELECT e.id FROM journal_entries e WHERE e.supplier_id=? AND e.id IN ('.implode(',',array_fill(0,count($chunk),'?')).') AND ('.self::entryReferenceSql().') ORDER BY e.id FOR UPDATE',[$supplier,...$chunk]);if($row)self::rejectEntry((int)$row['id']);}
    }
    public function mutableAssignmentEntries(int $supplier,array $lineIds):array
    {
        $this->transaction();if($lineIds===[])return [];
        // Odkaz závisí jen na zápisu: nejdřív zápisy řádků, potom jedno vyhodnocení za zápis.
        $entries=array_map(fn($row)=>(int)$row['id'],$this->rows('SELECT DISTINCT e.id FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id WHERE l.supplier_id=? AND l.id IN ('.implode(',',array_fill(0,count($lineIds),'?')).') ORDER BY e.id FOR UPDATE',[$supplier,...$lineIds]));
        if($entries===[])return [];
        $rows=$this->rows(\MyInvoice\Infrastructure\Database\SubqueryCache::OFF.'SELECT e.id,('.self::entryReferenceSql().') AS settlement_used FROM journal_entries e WHERE e.supplier_id=? AND e.id IN ('.implode(',',array_fill(0,count($entries),'?')).') ORDER BY e.id FOR UPDATE',[$supplier,...$entries]);
        foreach($rows as $row)if((int)$row['settlement_used']!==0)self::rejectEntry((int)$row['id']);
        return array_map(fn($row)=>(int)$row['id'],$rows);
    }
    private static function rejectEntry(int $id):never
    {
        throw new IssuanceException('property_service_settlement_owner_required','Zápis je podkladem neměnného vyúčtování služeb.',409,['entry_id'=>$id]);
    }
    public function assertOperationReversible(int $supplier,int $operation):void
    {
        $this->transaction();if($this->one('SELECT e.id FROM property_service_settlement_effects e JOIN property_service_settlement_applications s ON s.supplier_id=e.supplier_id AND s.id=e.application_id JOIN unit_payment_allocations a ON a.supplier_id=e.supplier_id AND a.id=e.allocation_id WHERE e.supplier_id=? AND a.operation_id=? AND NOT EXISTS (SELECT 1 FROM property_service_settlement_applications r WHERE r.supplier_id=s.supplier_id AND r.reversal_of=s.id) LIMIT 1 FOR UPDATE',[$supplier,$operation]))PaymentException::reject('allocation_has_service_settlement');
    }
    public function assertChargeLinesMutable(int $supplier,array $ids):void
    {
        $this->transaction();if($ids===[])return;
        if($this->one('SELECT o.document_id FROM property_service_settlement_origins o JOIN property_service_settlement_applications a ON a.supplier_id=o.supplier_id AND a.document_id=o.document_id WHERE o.supplier_id=? AND o.charge_line_id IN ('.implode(',',array_fill(0,count($ids),'?')).') LIMIT 1 FOR UPDATE',[$supplier,...$ids]))PaymentException::reject('claim_has_service_settlement');
    }
    public function assertChargeMutable(int $supplier,int $id):void
    {
        $this->transaction();$rows=$this->rows('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? ORDER BY id FOR UPDATE',[$supplier,$id]);$this->assertChargeLinesMutable($supplier,array_map(fn($r)=>(int)$r['id'],$rows));
    }
    private function transaction():void{if(!$this->db->pdo()->inTransaction())throw new \LogicException('Settlement use guard requires period-first owner context.');}
}
