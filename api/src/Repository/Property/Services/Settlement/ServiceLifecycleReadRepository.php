<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\Services\Settlement\SettlementEvidence;
use MyInvoice\Service\Property\{PropertyInput,PropertyException};
use PDO;

final class ServiceLifecycleReadRepository
{
    public function __construct(private readonly Connection $db,private readonly ServiceLifecycleRepository $owner) {}
    public function page(int $supplier,int $after=0): array
    {
        return $this->transaction($supplier,function () use ($supplier,$after) {
            $q=$this->db->pdo()->prepare('SELECT c.id,c.unit_id,u.code unit_label,c.service_id,s.code service_label,c.period_from,c.period_to,c.policy FROM property_service_lifecycle_cases c JOIN property_objects u ON u.id=c.unit_id AND u.supplier_id=c.supplier_id JOIN property_objects s ON s.id=c.service_id AND s.supplier_id=c.supplier_id WHERE c.supplier_id=? AND c.id>? ORDER BY c.id LIMIT 26');$q->execute([$supplier,$after]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>25;$rows=array_slice($rows,0,25);
            foreach($rows as &$row) foreach(['id','unit_id','service_id'] as $key) $row[$key]=(int)$row[$key];
            return ['items'=>$rows,'next_cursor'=>$more?$rows[array_key_last($rows)]['id']:null];
        });
    }
    public function detail(int $supplier,int $id): array
    {
        return $this->transaction($supplier,function () use ($supplier,$id) {
            PropertyInput::id($id,'case_id');$state=$this->owner->current($supplier,$id);
            $q=$this->db->pdo()->prepare('SELECT event_key,journal_entry_id,recorded_at FROM property_service_lifecycle_events WHERE supplier_id=? AND case_id=? ORDER BY id FOR UPDATE');$q->execute([$supplier,$id]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
            $unwound=count(array_filter($events,fn($e)=>str_starts_with($e['event_key'],'unwind-')));
            $required=count($state['estimate']['sources']??[]);
            return ['id'=>$id,'case'=>$state['case'],'estimate_cents'=>$state['estimate']['amount_cents']??null,'final_cents'=>$state['final']['amount_cents']??null,'cost_line_id'=>$state['final']['cost_line_id']??null,'phase'=>$state['final']!==null?'finalized':($required>0&&$unwound===$required?'unwound':'estimated'),'events'=>$events];
        });
    }
    public function choices(int $supplier,string $date): array
    {
        SettlementEvidence::date($date);
        return $this->transaction($supplier,function () use ($supplier,$date) {
            $registry=new PropertyReadRepository($this->db);$day=new \DateTimeImmutable($date.' 23:59:59.999999',new \DateTimeZone('Europe/Prague'));$at=\MyInvoice\Service\Property\PropertyInstant::normalize($day->format('Y-m-d\TH:i:s.uP'));$result=['as_of'=>$at];
            foreach(['unit','service'] as $kind) {
                $result[$kind.'s']=array_map(static fn($o)=>['id'=>$o['id'],'label'=>$o['code'].' '.($o['revision']['state']['name']??$o['revision']['state']['unit_no']??'')],$registry->list($supplier,$kind,$at));
            }
            $q=$this->db->pdo()->prepare("SELECT id,account_code code,name label FROM chart_of_accounts WHERE supplier_id=? AND is_active=1 AND account_type='asset' ORDER BY account_code");$q->execute([$supplier]);
            $result['accounts']=array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),fn($a)=>preg_match(\MyInvoice\Service\Property\Opening\OpeningRolePolicy::ROLES['service_cost'][2],$a['code'])===1));
            return $result;
        });
    }
    public function sources(int $supplier,string $kind,int $unit,int $service,string $from,string $to,int $after=0): array
    {
        foreach([$unit,$service] as $id) PropertyInput::id($id,'source_scope');SettlementEvidence::date($from);SettlementEvidence::date($to);
        if($from>$to||!in_array($kind,['estimates','invoices','openings'],true)) PropertyInput::invalid('source_kind');
        return $this->transaction($supplier,function () use($supplier,$kind,$unit,$service,$from,$to,$after) {
            if($kind==='openings') {
                $q=$this->db->pdo()->prepare("SELECT i.id,i.snapshot_json FROM property_opening_items i WHERE i.supplier_id=? AND i.unit_id=? AND i.service_id=? AND i.kind='service_carry' AND i.period_from<=? AND i.period_to>=? AND i.id>? ORDER BY i.id LIMIT 51");$q->execute([$supplier,$unit,$service,$to,$from,$after]);$rows=[];
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$s=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$rows[]=['id'=>(int)$row['id'],'label'=>($s['case_ref']??'S6').' '.($s['period_from']??'').' - '.($s['period_to']??''),'amount'=>null];}
            } else {
                $type=$kind==='estimates'?'manual':'purchase_invoice';
                $q=$this->db->pdo()->prepare("SELECT e.id,e.source_id,e.document_no,e.description,e.entry_date,p.vendor_invoice_number,v.company_name FROM journal_entries e LEFT JOIN purchase_invoices p ON e.source_type='purchase_invoice' AND p.id=e.source_id AND p.supplier_id=e.supplier_id LEFT JOIN clients v ON v.id=p.vendor_id AND v.supplier_id=p.supplier_id WHERE e.supplier_id=? AND e.id>? AND e.source_type=? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date BETWEEN ? AND ? AND EXISTS(SELECT 1 FROM journal_entry_lines l JOIN journal_entry_line_dimensions ud ON ud.line_id=l.id AND ud.supplier_id=l.supplier_id JOIN property_objects u ON u.dimension_value_id=ud.dimension_value_id AND u.supplier_id=ud.supplier_id JOIN journal_entry_line_dimensions sd ON sd.line_id=l.id AND sd.supplier_id=l.supplier_id JOIN property_objects s ON s.dimension_value_id=sd.dimension_value_id AND s.supplier_id=sd.supplier_id WHERE l.supplier_id=e.supplier_id AND l.entry_id=e.id AND u.id=? AND u.kind='unit' AND s.id=? AND s.kind='service') ORDER BY e.id LIMIT 51");$q->execute([$supplier,$after,$type,$from,$to,$unit,$service]);$rows=[];
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $entry){$lines=$this->db->pdo()->prepare('SELECT l.id,a.account_code,l.side,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.supplier_id=? AND l.entry_id=? ORDER BY l.line_no,l.id');$lines->execute([$supplier,$entry['id']]);$lineRows=$lines->fetchAll(PDO::FETCH_ASSOC);$rows[]=['id'=>(int)$entry['id'],'invoice_id'=>$entry['source_id']===null?null:(int)$entry['source_id'],'label'=>$entry['entry_date'].' '.($entry['vendor_invoice_number']??$entry['document_no']??$entry['description']??'').' '.($entry['company_name']??''),'lines'=>array_map(static function($l){$l['id']=(int)$l['id'];return $l;},$lineRows)];}
            }
            $more=count($rows)>50;$rows=array_slice($rows,0,50);return ['items'=>$rows,'next_cursor'=>$more?$rows[array_key_last($rows)]['id']:null,'selection_is_authorization'=>false];
        });
    }
    private function transaction(int $supplier,callable $work): mixed
    {
        PropertyInput::id($supplier,'supplier');return(new DimensionPeriodGuard($this->db))->transaction(function () use($supplier,$work){(new DimensionPeriodGuard($this->db))->lockPeriods($supplier);(new PaymentLocks($this->db))->acquire($supplier);return $work();});
    }
}
