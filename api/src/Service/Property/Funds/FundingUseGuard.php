<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Funds;
use MyInvoice\Infrastructure\Database\Connection;
final class FundingUseGuard
{
    public const TABLES=['property_funding_claims','property_funding_effects','property_funding_operations','property_funding_heads'];
    public static function assertGenericInsert(array $header):void {if(($header['source_type']??null)==='property_funding')FundingException::reject('owner_required');}
    public static function assertSourceUnclaimed(Connection $db,int $supplier,string $kind,int $id):void
    {if(!$db->pdo()->inTransaction())throw new \LogicException('Funding source claims require owner locks.');$q=$db->pdo()->prepare('SELECT c.operation_id FROM property_funding_claims c JOIN property_funding_operations o ON o.supplier_id=c.supplier_id AND o.id=c.operation_id WHERE c.supplier_id=? AND c.source_kind=? AND c.source_id=? AND o.receipt_json IS NOT NULL LIMIT 1 FOR UPDATE');$q->execute([$supplier,$kind,$id]);if($q->fetchColumn()!==false)FundingException::reject('source_owned');}
    public static function entryReferenceSql():string
    {
        return "EXISTS(SELECT 1 FROM property_funding_operations fo WHERE fo.supplier_id=e.supplier_id AND fo.journal_entry_id=e.id FOR UPDATE)
            OR EXISTS(SELECT 1 FROM property_funding_claims fc JOIN journal_entry_lines fl ON fl.supplier_id=fc.supplier_id AND fl.id=fc.source_id WHERE fc.supplier_id=e.supplier_id AND fc.source_kind IN ('cost','coverage') AND fl.entry_id=e.id FOR UPDATE)
            OR EXISTS(SELECT 1 FROM property_funding_claims fc JOIN unit_charge_lines cl ON cl.supplier_id=fc.supplier_id AND cl.id=fc.source_id JOIN unit_charges ch ON ch.supplier_id=cl.supplier_id AND ch.id=cl.charge_id WHERE fc.supplier_id=e.supplier_id AND fc.source_kind='issued' AND (ch.journal_entry_id=e.id OR ch.reversal_entry_id=e.id) FOR UPDATE)
            OR EXISTS(SELECT 1 FROM property_funding_claims fc JOIN property_opening_components oc ON oc.supplier_id=fc.supplier_id AND oc.id=fc.source_id JOIN property_opening_items oi ON oi.supplier_id=oc.supplier_id AND oi.id=oc.item_id JOIN property_opening_batches ob ON ob.supplier_id=oi.supplier_id AND ob.id=oi.batch_id WHERE fc.supplier_id=e.supplier_id AND fc.source_kind='opening' AND (ob.journal_entry_id=e.id OR ob.reversal_entry_id=e.id) FOR UPDATE)";
    }
    public static function assertChargeMutable(Connection $db,int $supplier,int $charge):void
    {$q=$db->pdo()->prepare("SELECT fc.operation_id FROM property_funding_claims fc JOIN unit_charge_lines cl ON cl.supplier_id=fc.supplier_id AND cl.id=fc.source_id WHERE fc.supplier_id=? AND fc.source_kind='issued' AND cl.charge_id=? LIMIT 1 FOR UPDATE");$q->execute([$supplier,$charge]);if($q->fetchColumn()!==false)FundingException::reject('source_owned');}
    public static function assertEntriesMutable(Connection $db,int $supplier,array $ids):void
    {if($ids===[])return;$q=$db->pdo()->prepare(\MyInvoice\Infrastructure\Database\SubqueryCache::OFF.'SELECT e.id FROM journal_entries e WHERE e.supplier_id=? AND e.id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND ('.self::entryReferenceSql().') LIMIT 1 FOR UPDATE');$q->execute([$supplier,...$ids]);if($q->fetchColumn()!==false)FundingException::reject('source_owned');}
}
