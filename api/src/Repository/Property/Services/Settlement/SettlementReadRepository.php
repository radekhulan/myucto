<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Services\Settlement;

use PDO;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\{PropertyInput,PropertyException,PropertyPublicProjection,PropertyTimeline};
use MyInvoice\Service\Property\Services\Settlement\{SettlementHistory,SettlementOriginAdapter,SettlementPublicView,SettlementEvidence};
use MyInvoice\Repository\Property\Payments\{PaymentRepository,DebtRepository};
use MyInvoice\Repository\Property\PropertyReadRepository;

final class SettlementReadRepository
{
    public function __construct(private readonly Connection $db, private readonly SettlementDocumentRepository $documents, private readonly SettlementOriginAdapter $origins, private readonly PaymentRepository $payments, private readonly SettlementDossierRepository $dossiers)
    {
    }
    public function page(int $supplier, int $after, int $limit, ?int $unit = null, ?int $service = null): array
    {
        $this->pagination($after, $limit);
        return $this->transaction($supplier, function () use ($supplier, $after, $limit, $unit, $service) {
            $where = 'supplier_id=? AND id>?';
            $args = [$supplier,$after];
            foreach (['unit_id' => $unit,'service_id' => $service] as $key => $id) {
                if ($id !== null) {
                    PropertyInput::id($id, $key);
                    $where .= ' AND '.$key.'=?';
                    $args[] = $id;
                }
            }$rows = $this->rows('SELECT id FROM property_service_settlements WHERE '.$where.' ORDER BY id LIMIT '.($limit + 1).' FOR UPDATE', $args);
            $more = count($rows) > $limit;
            if ($more) {
                array_pop($rows);
            }$items = [];
            foreach ($rows as $row) {
                $items[] = SettlementPublicView::document($this->detailLocked($supplier, (int)$row['id']));
            }return ['items' => $items,'next_cursor' => $more ? $items[array_key_last($items)]['id'] : null,'scope' => 'recorded_s8_documents','complete_financial_position' => false];
        });
    }
    public function detail(int $supplier, int $id, bool $public = false): array
    {
        return $this->transaction($supplier, fn () => $public ? SettlementPublicView::document($this->detailLocked($supplier, $id)) : $this->detailLocked($supplier, $id) + ['dossier' => $this->dossiers->readLocked($supplier, $id)]);
    }
    public function current(int $supplier, int $id): array
    {
        return $this->transaction($supplier, fn () => $this->detailLocked($supplier, $id)['current_summary']);
    }
    private function detailLocked(int $supplier, int $id): array
    {
        PropertyInput::id($id, 'id');
        if (!$this->rows('SELECT id FROM property_service_settlements WHERE supplier_id=? AND id=? FOR UPDATE', [$supplier,$id])) {
            throw new PropertyException('property_not_found', 'Vyúčtování nebylo nalezeno.', 404);
        }$document = $this->documents->find($supplier, $id, true);
        $facts = $document['document'];
        $labels = $this->labels($supplier, [$facts['unit_id'],$facts['service_id']]);
        $apps = [];
        $active = null;
        $head = $this->rows('SELECT row_version,application_id FROM property_service_settlement_heads WHERE supplier_id=? AND document_id=? FOR UPDATE', [$supplier,$id])[0] ?? ['row_version' => 0,'application_id' => null];
        foreach ($this->rows('SELECT id FROM property_service_settlement_applications WHERE supplier_id=? AND document_id=? ORDER BY id FOR UPDATE', [$supplier,$id]) as $row) {
            $app = (new SettlementHistory($this->db))->application($supplier, (int)$row['id'], true);
            $apps[] = $app;
            if ($app['active']) {
                $active = $app;
            }
        }
        $credits = [];
        $claims = [];
        if ($active !== null) {
            foreach ($active['credit_ids'] as $creditId) {
                $c = $this->payments->credit($supplier, $creditId);
                $credits[] = array_intersect_key($c, array_flip(['id','balance_cents','reserved_cents','available_cents']));
            }foreach ((new DebtRepository($this->db))->currentMany($supplier, $active['debt_ids']) as $debt) {
                $paid = '0';
                foreach ($debt['paid'] as $amount) {
                    $paid = bcadd($paid, $amount, 0);
                }$accessories = [];
                foreach ($debt['accessories'] as $a) {
                    $accessories[] = array_intersect_key($a, array_flip(['component','amount_cents']));
                }$claims[] = ['id' => $debt['id'],'active' => $debt['active'],'principal_cents' => $debt['snapshot']['amount_cents'],'paid_cents' => $paid,'accessories' => $accessories];
            }
        }
        $summary = ['document_id' => $id,'row_version' => (int)$head['row_version'],'application_id' => $active['id'] ?? null,'active' => $active !== null,'accounting_date' => $active['accounting_date'] ?? null,'credits' => $credits,'claims' => $claims,'scope' => 'recorded_s8_claims_and_credits','complete_financial_position' => false];
        return $document + ['unit' => $labels[$facts['unit_id']],'service' => $labels[$facts['service_id']],'applications' => $apps,'current_summary' => $summary];
    }
    public function catalog(int $supplier, string $kind, int $unit, int $service, string $date, int $after, int $limit, int $afterService = 0, ?int $s3Line = null): array
    {
        $this->pagination($after, $limit);
        if ($afterService < 0) {
            PropertyInput::invalid('after_service_id');
        }foreach ([$unit,$service] as $id) {
            PropertyInput::id($id, 'scope');
        }SettlementEvidence::date($date);
        return $this->transaction($supplier, function () use ($supplier, $kind, $unit, $service, $date, $after, $limit, $afterService, $s3Line) {
            $labels = $this->labels($supplier, [$unit,$service]);
            if (!in_array($kind, ['s3','opening','costs'], true)) {
                PropertyInput::invalid('collection');
            }
            $history = new SettlementHistory($this->db);
            $items = [];
            $cursors = [];
            $verified = [];
            $window = max(25, $limit + 1);
            $costPeriod = null;
            if ($kind === 'costs') {
                PropertyInput::id($s3Line, 's3_line_id');
                try {
                    $history->assertOriginAvailable($supplier, ['kind' => 's3_line','id' => $s3Line,'opening_advance_component_id' => null]);
                } catch (\DomainException $e) {
                    if ($e->getMessage() !== 'settlement_origin_already_disposed') {
                        throw $e;
                    }
                    return $this->catalogPage([], null);
                }
                $origin = $this->origins->current($supplier, $unit, $service, $date, [['kind' => 's3_line','id' => $s3Line]]);
                $costPeriod = $origin['origins'][0]['source_period'];
            }
            do {
                $rows = $this->catalogRows($supplier, $kind, $unit, $service, $date, $after, $afterService, $window);
                if ($kind === 'opening' && $rows !== []) {
                    $ids = array_values(array_unique(array_merge(array_column($rows, 'id'), array_column($rows, 'service_item_id'))));
                    $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => !isset($verified[$id])));
                    if ($ids !== []) {
                        $verified += (new \MyInvoice\Service\Property\Opening\OpeningReadProof($this->db))->items($supplier, $ids, true);
                    }
                }
                foreach ($rows as $row) {
                    $after = (int)$row['id'];
                    $afterService = (int)($row['service_item_id'] ?? 0);
                    $selector = match($kind) {
                        's3' => ['kind' => 's3_line','id' => $after],
                        'opening' => ['kind' => 'opening','advance_item_id' => $after,'service_item_id' => $afterService],
                        'costs' => ['kind' => 's3_line','id' => $s3Line,'cost_line_id' => $after]
                    };
                    if ($kind === 'opening') {
                        $selector = $this->openingSelector($supplier, $unit, $service, $verified[$after], $verified[$afterService], $selector, $date);
                        if ($selector === null) {
                            continue;
                        }
                    }
                    try {
                        if ($kind === 's3') {
                            $history->assertOriginAvailable($supplier, ['kind' => 's3_line','id' => $after,'opening_advance_component_id' => null]);
                        } elseif ($kind === 'costs') {
                            try{(new ServiceLifecycleRepository($this->db))->assertCostReady($supplier,$unit,$service,$after,$date,null,$costPeriod);}catch(\DomainException $failure){if(!in_array($failure->getMessage(),['settlement_lifecycle_final_proof_required','settlement_lifecycle_final_after_settlement'],true))throw $failure;$composite=$this->compositeSelector($supplier,$unit,$service,$date,$after,$costPeriod,$selector);if($composite===null)throw $failure;$selector=$composite;}
                            $history->assertCostAvailable($supplier, $after);
                        }
                    } catch (\DomainException $e) {
                        if (!in_array($e->getMessage(), ['settlement_origin_already_disposed','settlement_cost_already_disposed','settlement_lifecycle_final_proof_required','settlement_lifecycle_final_after_settlement'], true)) {
                            throw $e;
                        }
                        continue;
                    }
                    $proof = $this->origins->current($supplier, $unit, $service, $date, [$selector]);
                    $source = $proof['origins'][0];
                    $cost = $kind === 's3' ? null : array_values($proof['costs'])[0];
                    if(isset($cost['sources'])){try{foreach(SettlementEvidence::costSources($cost) as $part)$history->assertCostAvailable($supplier,$part['source_id']);}catch(\DomainException $failure){if($failure->getMessage()!=='settlement_cost_already_disposed')throw $failure;continue;}}
                    if ($kind === 'opening') {
                        try {
                            $history->assertOriginAvailable($supplier, $source);
                            $history->assertCostAvailable($supplier, $cost['source_id']);
                        } catch (\DomainException $e) {
                            if (!in_array($e->getMessage(), ['settlement_origin_already_disposed','settlement_cost_already_disposed'], true)) {
                                throw $e;
                            }
                            continue;
                        }
                    }
                    $items[] = ['id' => $after,'selector' => $selector,'unit' => $labels[$unit],'service' => $labels[$service],'description' => $row['description'] ?? ($labels[$unit]['label'].' / '.$labels[$service]['label']),'document_no' => $row['document_no'] ?? null,'accounting_date' => $row['accounting_date'],'period_from' => $row['period_from'] ?? null,'period_to' => $row['period_to'] ?? null,'prescribed_cents' => $source['prescribed_cents'],'carried_paid_cents' => $source['carried_paid_cents'],'cost_source_id' => $cost['source_id'] ?? null,'cost_cents' => $cost['amount_cents'] ?? null];
                    if(isset($cost['sources'])){$parts=[];foreach($cost['sources'] as $part){if($part['provenance']['kind']==='lifecycle'){$scope=$part['provenance']['source_input']['case'];$items[array_key_last($items)]['period_from']=$scope['period_from'];$items[array_key_last($items)]['period_to']=$scope['period_to'];}$entry=$this->rows('SELECT e.entry_date FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id WHERE l.supplier_id=? AND l.id=?',[$supplier,$part['source_id']]);$parts[]=['amount_cents'=>$part['amount_cents'],'accounting_date'=>$entry[0]['entry_date']];}$items[array_key_last($items)]['cost_parts']=$parts;}
                    $cursors[] = ['after_id' => $after,'after_service_id' => $afterService];
                    if (count($items) > $limit) {
                        array_pop($items);
                        array_pop($cursors);
                        return $this->catalogPage($items, $cursors[array_key_last($cursors)]);
                    }
                }
            } while (count($rows) === $window);
            return $this->catalogPage($items, null);
        });
    }
    private function catalogPage(array $items, ?array $cursor): array
    {
        return ['items' => $items,'next_cursor' => $cursor,'scope' => 'current_owner_verified_candidates','write_authorization' => false];
    }
    private function openingSelector(int $supplier, int $unit, int $service, array $advance, array $carry, array $selector, string $date): ?array
    {
        if (!$this->compatibleOpeningPair($advance, $carry)) {
            return null;
        }
        $snapshot = $carry['snapshot'];
        $cases = $this->rows('SELECT id FROM property_service_lifecycle_cases WHERE supplier_id=? AND unit_id=? AND service_id=? AND period_from<=? AND period_to>=? ORDER BY id FOR UPDATE', [$supplier,$unit,$service,$snapshot['period_to'],$snapshot['period_from']]);
        if ($cases === []) {
            return $snapshot['service_state']['profile'] === 'service_balance_sheet' ? $selector : null;
        }
        $owner = new ServiceLifecycleRepository($this->db);
        $matches = [];
        foreach ($cases as $case) {
            $state = $owner->current($supplier, (int)$case['id']);
            if ($state['final'] !== null && $state['final']['accounting_date'] <= $date && ($state['estimate']['opening_item_id'] ?? null) === $carry['id'] && $state['case']['policy'] === $snapshot['service_state']['profile']) {
                $matches[] = $state['final']['cost_line_id'];
            }
        }
        if(count($matches)===1)return $selector+['cost_line_id'=>$matches[0]];$known=array_values(array_filter($carry['components'],fn($part)=>$part['role']==='service_cost'));if(count($known)!==1||$snapshot['service_state']['profile']!=='service_balance_sheet')return null;$composites=[];foreach($cases as $case){$state=$owner->current($supplier,(int)$case['id']);if($state['final']===null||$state['case']['policy']!=='service_balance_sheet'||$state['final']['accounting_date']>$date)continue;$candidate=$this->compositeSelector($supplier,$unit,$service,$date,$known[0]['line']['id'],['from'=>$state['case']['period_from'],'to'=>$state['case']['period_to']],$selector);if($candidate!==null)$composites[]=$candidate;}return count($composites)===1?$composites[0]:null;
    }
    private function compositeSelector(int $supplier,int $unit,int $service,string $date,int $line,array $period,array $selector):?array
    {
        $rows=$this->rows("SELECT d.id FROM property_service_settlements d WHERE d.supplier_id=? AND d.unit_id=? AND d.service_id=? AND d.cost_line_id=? AND JSON_CONTAINS_PATH(d.document_json,'one','$.cost.sources')=0 AND NOT EXISTS (SELECT 1 FROM property_service_settlements n WHERE n.supplier_id=d.supplier_id AND n.unit_id=d.unit_id AND n.service_id=d.service_id AND n.segment_key=d.segment_key AND n.revision_no>d.revision_no) ORDER BY d.id FOR UPDATE",[$supplier,$unit,$service,$line]);$known=[];foreach($rows as $row){$doc=$this->documents->find($supplier,(int)$row['id'],true);$fact=$doc['document'];if($fact['period_from']===$period['from']&&$fact['period_to']===$period['to']&&!(new SettlementHistory($this->db))->hasActiveApplicationForDocument($supplier,(int)$row['id']))$known[]=(int)$row['id'];}if(count($known)!==1)return null;
        $cases=$this->rows("SELECT id FROM property_service_lifecycle_cases WHERE supplier_id=? AND unit_id=? AND service_id=? AND period_from=? AND period_to=? AND policy='service_balance_sheet' ORDER BY id FOR UPDATE",[$supplier,$unit,$service,$period['from'],$period['to']]);$final=[];foreach($cases as $case){$current=(new ServiceLifecycleRepository($this->db))->current($supplier,(int)$case['id']);if($current['final']!==null&&$current['final']['accounting_date']<=$date)$final[]=(int)$case['id'];}if(count($final)!==1)return null;unset($selector['cost_line_id']);return $selector+['cost_sources'=>[['kind'=>'document','id'=>$known[0]],['kind'=>'lifecycle','id'=>$final[0]]]];
    }
    private function compatibleOpeningPair(array $advance, array $carry): bool
    {
        $a = $advance['snapshot']['advance_state'];
        $s = $carry['snapshot']['service_state'];
        return $a['used_cents'] === '0' && $s['settled_cents'] === '0' && $s['cancelled_unpaid_cents'] === '0'
            && $a['prescribed_cents'] === $s['prescribed_cents'] && $a['paid_cents'] === $s['paid_cents']
            && PropertyInput::hash($a['unpaid_cases']) === PropertyInput::hash($s['unpaid_cases']);
    }
    private function catalogRows(int $supplier, string $kind, int $unit, int $service, string $date, int $after, int $afterService, int $limit): array
    {
        $args = [$supplier,$unit,$service,$date];
        if ($kind === 's3') {
            return $this->rows("SELECT l.id,c.accounting_date,c.period_from,c.period_to FROM unit_charge_lines l JOIN unit_charges c ON c.supplier_id=l.supplier_id AND c.id=l.charge_id JOIN unit_payment_debt_lines d ON d.supplier_id=l.supplier_id AND d.charge_line_id=l.id WHERE l.supplier_id=? AND c.unit_id=? AND l.service_id=? AND c.accounting_date<=? AND c.status='posted' AND l.amount_cents>0 AND l.id>? AND NOT EXISTS (SELECT 1 FROM unit_payment_debt_lines dl JOIN unit_payment_correction_anchors ca ON ca.supplier_id=dl.supplier_id AND ca.original_line_id=dl.charge_line_id JOIN unit_payment_corrections pc ON pc.supplier_id=ca.supplier_id AND pc.id=ca.correction_id WHERE dl.supplier_id=d.supplier_id AND dl.debt_id=d.debt_id AND pc.status='posted') ORDER BY l.id LIMIT ".$limit.' FOR UPDATE', [...$args,$after]);
        }
        if ($kind === 'opening') {
            return $this->rows("SELECT a.id,c.id service_item_id,b.accounting_date,a.period_from,a.period_to FROM property_opening_items a JOIN property_opening_items c ON c.supplier_id=a.supplier_id AND c.batch_id=a.batch_id AND c.unit_id=a.unit_id AND c.service_id=a.service_id AND c.kind='service_carry' JOIN property_opening_batches b ON b.supplier_id=a.supplier_id AND b.id=a.batch_id JOIN property_opening_heads h ON h.supplier_id=b.supplier_id AND h.batch_id=b.id WHERE a.supplier_id=? AND a.unit_id=? AND a.service_id=? AND b.accounting_date<=? AND a.kind='advance' AND b.status='posted' AND (a.id>? OR(a.id=? AND c.id>?)) ORDER BY a.id,c.id LIMIT ".$limit.' FOR UPDATE', [...$args,$after,$after,$afterService]);
        }
        return $this->rows("SELECT l.id,e.entry_date accounting_date,e.description,e.document_no FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts c ON c.supplier_id=l.supplier_id AND c.id=l.account_id JOIN property_objects u ON u.supplier_id=l.supplier_id AND u.id=? JOIN property_objects s ON s.supplier_id=l.supplier_id AND s.id=? JOIN journal_entry_line_dimensions du ON du.supplier_id=l.supplier_id AND du.line_id=l.id AND du.dimension_value_id=u.dimension_value_id JOIN journal_entry_line_dimensions ds ON ds.supplier_id=l.supplier_id AND ds.line_id=l.id AND ds.dimension_value_id=s.dimension_value_id WHERE l.supplier_id=? AND e.entry_date<=? AND (e.source_type='manual' OR (e.source_type IN ('purchase_invoice','property_service_lifecycle') AND EXISTS (SELECT 1 FROM property_service_lifecycle_events le JOIN property_service_lifecycle_cases lc ON lc.supplier_id=le.supplier_id AND lc.id=le.case_id WHERE le.supplier_id=l.supplier_id AND lc.unit_id=u.id AND lc.service_id=s.id AND le.event_key='final' AND JSON_VALUE(le.receipt_json,'$.cost_line_id')=l.id))) AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND l.side='debit' AND l.amount>0 AND l.is_red_storno=0 AND c.account_type='asset' AND l.currency_code IS NULL AND l.amount_foreign IS NULL AND l.fx_rate IS NULL AND REPLACE(c.account_code,'.','') LIKE '3152%' AND l.id>? ORDER BY l.id LIMIT ".$limit.' FOR UPDATE', [$unit,$service,$supplier,$date,$after]);
    }
    private function labels(int $supplier, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $objects = $this->rows('SELECT id,kind,code FROM property_objects WHERE supplier_id=? AND id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY id', [$supplier,...$ids]);
        if (count($objects) !== count($ids)) {
            throw new PropertyException('property_not_found', 'Záznam registru nebyl nalezen.', 404);
        }$histories = (new PropertyReadRepository($this->db))->histories($supplier, $ids, true);
        $out = [];
        foreach ($objects as $object) {
            $id = (int)$object['id'];
            $object['id'] = $id;
            $projection = PropertyPublicProjection::object($object + PropertyTimeline::at($histories[$id] ?? [], gmdate('Y-m-d\TH:i:s\Z')));
            $state = $projection['revision']['state'] ?? [];
            $out[$id] = ['id' => $id,'code' => $object['code'],'label' => $state['unit_no'] ?? $state['name'] ?? $object['code']];
        }return $out;
    }
    private function pagination(int $after, int $limit): void
    {
        if ($after < 0 || $limit < 1 || $limit > 100) {
            PropertyInput::invalid('pagination');
        }
    }
    private function rows(string $sql, array $args): array
    {
        $q = $this->db->pdo()->prepare($sql);
        $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function transaction(int $supplier,callable $work): mixed
    {
        return(new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier,$work) {
            (new PaymentLocks($this->db))->acquire($supplier);
            return $work();
        });
    }
}
