<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
final class OpeningUseGuard
{
    public function __construct(private readonly Connection $db){}
    public static function entryReferenceSql():string
    {
        return 'EXISTS (SELECT 1 FROM property_opening_batches ob WHERE ob.supplier_id=e.supplier_id AND (ob.journal_entry_id=e.id OR ob.reversal_entry_id=e.id) FOR UPDATE)';
    }
    public function assertDescriptionMutable(int $supplier,int $entry):void
    {
        (new \MyInvoice\Service\Property\Payments\PaymentLocks($this->db))->acquire($supplier);
        $q=$this->db->pdo()->prepare(\MyInvoice\Infrastructure\Database\SubqueryCache::OFF.'SELECT e.id FROM journal_entries e WHERE e.supplier_id=? AND e.id=? AND ('.self::entryReferenceSql().') FOR UPDATE');$q->execute([$supplier,$entry]);if($q->fetchColumn()!==false)throw new \MyInvoice\Service\Accounting\PostingException('property_opening_owner_required','Počáteční stav lze změnit jen doloženou revizí převzetí.',409);
    }
    public function assertGenericJournal(array $header):void
    {
        if(($header['source_type']??null)!=='opening')return;
        $q=$this->db->pdo()->prepare('SELECT h.batch_id FROM property_opening_heads h JOIN accounting_periods p ON p.id=h.period_id AND p.supplier_id=h.supplier_id WHERE h.supplier_id=? AND p.starts_on<=? AND p.ends_on>=? AND h.batch_id IS NOT NULL FOR UPDATE');$q->execute([$header['supplier_id'],$header['entry_date'],$header['entry_date']]);if($q->fetchColumn()!==false)throw new \MyInvoice\Service\Accounting\PostingException('property_opening_owner_required','Počáteční stav lze změnit jen doloženou revizí převzetí.',409);
    }
    public function assertUnused(int $supplier,int $batch):void
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Opening use checks require owner locks.');
        $q=$this->db->pdo()->prepare('SELECT journal_entry_id FROM property_opening_batches WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$batch]);$entry=(int)$q->fetchColumn();
        (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertEntriesMutable($supplier,[$entry]);
        $q=$this->db->pdo()->prepare('SELECT e.id FROM journal_entries e WHERE e.supplier_id=? AND e.id=? AND ('.\MyInvoice\Service\Property\Payments\PaymentUseGuard::externalOpeningReferencesSql().') FOR UPDATE');$q->execute([$supplier,$entry]);if($q->fetchColumn()!==false)OpeningException::reject('external_source_use');
        $q=$this->db->pdo()->prepare(\MyInvoice\Infrastructure\Database\SubqueryCache::OFF.'SELECT e.id FROM journal_entries e WHERE e.supplier_id=? AND e.id=? AND ('.\MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard::entryReferenceSql().') FOR UPDATE');$q->execute([$supplier,$entry]);if($q->fetchColumn()!==false)OpeningException::reject('already_used');
        $queries=[
            "SELECT a.id FROM unit_payment_allocations a JOIN unit_payment_operations o ON o.id=a.operation_id AND o.supplier_id=a.supplier_id JOIN unit_payment_debts d ON d.id=a.debt_id AND d.supplier_id=a.supplier_id JOIN property_opening_items i ON i.id=d.opening_item_id AND i.supplier_id=d.supplier_id WHERE i.supplier_id=? AND i.batch_id=? AND o.status='posted' ORDER BY a.id FOR UPDATE",
            "SELECT e.id FROM unit_credit_events e JOIN unit_payment_operations o ON o.id=e.operation_id AND o.supplier_id=e.supplier_id JOIN unit_credit_lots c ON c.id=e.credit_id AND c.supplier_id=e.supplier_id JOIN property_opening_items i ON i.id=c.opening_item_id AND i.supplier_id=c.supplier_id WHERE i.supplier_id=? AND i.batch_id=? AND o.status='posted' ORDER BY e.id FOR UPDATE",
            "SELECT r.id FROM unit_credit_reservations r JOIN unit_credit_lots c ON c.id=r.credit_id AND c.supplier_id=r.supplier_id JOIN property_opening_items i ON i.id=c.opening_item_id AND i.supplier_id=c.supplier_id WHERE i.supplier_id=? AND i.batch_id=? AND r.status IN ('reserved','submitted') ORDER BY r.id FOR UPDATE",
            'SELECT p.debt_id FROM property_claim_party_event_debts p JOIN unit_payment_debts d ON d.id=p.debt_id AND d.supplier_id=p.supplier_id JOIN property_opening_items i ON i.id=d.opening_item_id AND i.supplier_id=d.supplier_id WHERE i.supplier_id=? AND i.batch_id=? ORDER BY p.debt_id FOR UPDATE',
            'SELECT r.id FROM unit_claim_accessory_receipts r JOIN unit_payment_debts d ON d.id=r.debt_id AND d.supplier_id=r.supplier_id JOIN property_opening_items i ON i.id=d.opening_item_id AND i.supplier_id=d.supplier_id WHERE i.supplier_id=? AND i.batch_id=? AND r.opening_component_id IS NULL ORDER BY r.id FOR UPDATE',
        ];
        foreach($queries as $sql){$q=$this->db->pdo()->prepare($sql);$q->execute([$supplier,$batch]);if($q->fetchColumn()!==false)OpeningException::reject('already_used');}
    }
}
