<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository;

final class SettlementWorkflow
{
    public function __construct(private readonly Connection $db, private readonly SettlementOriginAdapter $origins, private readonly SettlementDocumentRepository $documents)
    {
    }
    public function prepare(int $supplier, array $request, int $expected): array
    {
        return $this->transaction($supplier, function () use ($supplier, $request, $expected) {
            $request = $this->normalize($request);
            $document = $this->assemble($supplier, $request);
            return ['document' => $document->snapshot(),'preview_hash' => $this->digest($request, $expected, $document)];
        });
    }
    public function save(int $supplier, array $request, int $expected, string $previewHash, string $token, int $actor): array
    {
        return $this->transaction($supplier, function () use ($supplier, $request, $expected, $previewHash, $token, $actor) {
            $request = $this->normalize($request);
            $q = $this->db->pdo()->prepare('SELECT id FROM property_service_settlements WHERE supplier_id=? AND idempotency_key=? FOR UPDATE');
            $q->execute([$supplier,$token]);
            $old = $q->fetchColumn();
            if ($old !== false) {
                $stored = $this->documents->find($supplier, (int)$old, true);
                $facts = $stored['document'];
                unset($facts['amounts']);
                $prior = array_intersect_key($facts, array_flip(['unit_id','service_id','segment_key','period_from','period_to','cutoff_at','known_at','recipient_ids','evidence','external_dossier']));
                $prior += ['selectors' => $stored['origin_receipt']['request']['selectors'],'accounting_date' => $stored['origin_receipt']['request']['accounting_date'],'cost_source_id' => $facts['cost']['source_id']];
                if (PropertyInput::hash($request) !== PropertyInput::hash($prior)) {
                    SettlementEvidence::reject('idempotency_conflict');
                }$document = SettlementDocument::fromEvidence($facts);
            } else {
                $document = $this->assemble($supplier, $request);
            }
            if (!hash_equals($this->digest($request, $expected, $document), $previewHash)) {
                SettlementEvidence::reject('preview_stale');
            }return $this->documents->save($supplier, $document, $request['selectors'], $request['accounting_date'], $expected, $token, $actor);
        });
    }
    private function normalize(array $request): array
    {
        SettlementEvidence::fields($request, ['unit_id','service_id','accounting_date','selectors','cost_source_id','segment_key','period_from','period_to','cutoff_at','known_at','recipient_ids','evidence',...(array_key_exists('external_dossier',$request)?['external_dossier']:[])]);
        $request['recipient_ids'] = SettlementEvidence::ids($request['recipient_ids']);
        if(array_key_exists('external_dossier',$request)){if(!is_array($request['external_dossier']))PropertyInput::invalid('external_dossier');$request['external_dossier']=SettlementDossier::normalize($request['external_dossier'],$request['period_from'],$request['period_to']);}
        foreach (['cutoff_at','known_at'] as $key) {
            $request[$key] = PropertyInstant::normalize($request[$key]);
        }return $request;
    }
    private function assemble(int $supplier, array $request): SettlementDocument
    {
        SettlementEvidence::fields($request, ['unit_id','service_id','accounting_date','selectors','cost_source_id','segment_key','period_from','period_to','cutoff_at','known_at','recipient_ids','evidence',...(array_key_exists('external_dossier',$request)?['external_dossier']:[])]);
        $unit = PropertyInput::id($request['unit_id'], 'unit_id');
        $service = PropertyInput::id($request['service_id'], 'service_id');
        $date = SettlementEvidence::date($request['accounting_date']);
        $selectors = PropertyInput::list($request['selectors'], 'selectors', 100);
        $costId = PropertyInput::id($request['cost_source_id'], 'cost_source_id');
        $cutoff = PropertyInstant::normalize($request['cutoff_at']);
        $cutoffDate = (new \DateTimeImmutable($cutoff))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
        if ($cutoffDate > $date) {
            PropertyInput::invalid('cutoff_at');
        }
        $current = $this->origins->current($supplier, $unit, $service, $date, $selectors);
        $costs = array_values(array_filter($current['costs'], static fn ($c) => $c['source_id'] === $costId));
        if (count($costs) !== 1) {
            SettlementEvidence::reject('documented_cost_source_required');
        }$parties = SettlementEvidence::ids($request['recipient_ids']);
        if (count((new PropertyReadRepository($this->db))->parties($supplier, $parties, true)) !== count($parties)) {
            PropertyInput::invalid('recipient_ids');
        }
        if(isset($request['external_dossier'])){
            $dossierParties=SettlementDossier::partyIds($request['external_dossier']);
            if(count((new PropertyReadRepository($this->db))->parties($supplier,$dossierParties,true))!==count($dossierParties))PropertyInput::invalid('dossier_parties');
        }
        $prescribed = '0';
        $paid = '0';
        foreach ($current['origins'] as $source) {
            $prescribed = bcadd($prescribed, $source['prescribed_cents'], 0);
            $paid = bcadd($paid, $source['carried_paid_cents'], 0);
        }$allocations = [];
        foreach ($current['allocations'] as $allocation) {
            if ($allocation['active'] && $allocation['accounting_date'] <= $cutoffDate) {
                $allocations[] = array_intersect_key($allocation, array_flip(['id','origin_key','source_hash','amount_cents']));
                $paid = bcadd($paid, $allocation['amount_cents'], 0);
            }
        }
        $facts = array_intersect_key($request, array_flip(['segment_key','period_from','period_to','cutoff_at','known_at','evidence','external_dossier']));
        return SettlementDocument::fromEvidence($facts + ['supplier_id' => $supplier,'unit_id' => $unit,'service_id' => $service,'recipient_ids' => $parties,'prescribed_cents' => $prescribed,'paid_cents' => $paid,'actual_cents' => $costs[0]['amount_cents'],'origins' => $current['origins'],'cutoff_allocations' => $allocations,'cost' => $costs[0]]);
    }
    private function digest(array $request, int $expected, SettlementDocument $document): string
    {
        if ($expected < 0) {
            PropertyInput::invalid('expected_revision');
        }return PropertyInput::hash(['request' => $request,'expected_revision' => $expected,'document_hash' => $document->hash()]);
    }
    private function transaction(int $supplier, callable $work): mixed
    {
        return(new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier,$work) {
            (new PaymentLocks($this->db))->acquire($supplier);
            return $work();
        });
    }
}
