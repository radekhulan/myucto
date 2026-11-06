<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\ChargeReadRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\PropertyTimeline;
use PDO;

final class IssuancePreviewService
{
    public function __construct(private readonly Connection $db) {}

    public function preview(int $supplier,array $input,bool $current=false): array
    {
        $pdo=$this->db->pdo();
        if($current||$pdo->inTransaction()) return $this->build($supplier,$input,$current);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION READ ONLY');
        try {
            $result=$this->build($supplier,$input,false);
            $pdo->commit();
            return $result;
        } catch(\Throwable $e) {
            if($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function build(int $supplier,array $input,bool $current): array
    {
        $request=IssuanceRequest::normalize($input);
        $reader=new ChargeReadRepository($this->db);
        $version=$reader->find($supplier,$request['version_id'],$current);
        $this->period($request,$version,$reader->versions($supplier,$version['house_id'],$current));
        $registry=new PropertyReadRepository($this->db);
        $objects=array_column($registry->objects($supplier,null,$current),null,'id');
        $histories=$registry->histories($supplier,array_keys($objects),$current);
        if(!(PropertyTimeline::at($histories[$version['house_id']]??[],$request['claim_origin_at'])['revision']['state']['active']??false))
            throw new IssuanceException('property_charge_issue_registry_unproven','Dům není v rozhodném okamžiku aktivní.',409);
        $profile=(new PropertyProfileRepository($this->db))->at($supplier,$request['issued_at'],$current);
        if(!($profile['enabled']??false)) throw new IssuanceException('property_charge_issue_profile_required','Nový předpis vyžaduje účinný potvrzený profil SVJ.',409);
        $history=(new IssuanceReadRepository($this->db))->previewHistory($supplier,$request,$current);
        $previewHistory=new IssuancePreviewHistory($history['source'],$request,$history['families'],$history['targets']);
        $clientIds=[];
        foreach($request['units'] as $unit) {
            foreach($unit['liability_groups'] as $group) foreach($group['members']??[] as $id) $clientIds[]=$id;
            foreach($unit['notices'] as $notice) foreach($notice['notice']['deliveries']??[] as $proof) $clientIds[]=$proof['recipient_id'];
        }
        $parties=$registry->parties($supplier,$clientIds,$current); $partyIds=array_column($parties,'id');
        if(count(array_unique($clientIds))!==count($partyIds)) throw new IssuanceException('property_charge_issue_party_unavailable','Osoba nepatří do této firmy.',422);
        $stmt=$this->db->pdo()->prepare('SELECT id,account_code,account_type,is_active FROM '.\MyInvoice\Repository\AccountDefinitionRepository::relation($request['accounting_date'],$supplier,$current).' a WHERE supplier_id=? ORDER BY id'.($current?' FOR UPDATE':''));
        $stmt->execute([$supplier]); $accounts=array_column($stmt->fetchAll(PDO::FETCH_ASSOC),null,'account_code');
        $configured=array_column($request['accounts'],null,'component_id');
        $container=\MyInvoice\Bootstrap::buildContainer();$container->set(Connection::class,$this->db);$redirects=$container->get(\MyInvoice\Service\Accounting\PostingService::class)->singleAnalyticRedirects($supplier, $request['accounting_date'], $current);
        foreach($configured as &$selection)foreach(['receivable_account','counter_account'] as $accountField)$selection[$accountField]=$redirects[$selection[$accountField]]??$selection[$accountField];unset($selection);
        // Jen dimenze firmy a její skupiny (SSOT DimensionRepository::visibleSql), ne dimenze cizích skupin.
        // Dimenze firmy výhradně zamyká IssuanceLocks; skupinové sdílí více firem, proto jen sdílený
        // zámek: výhradní by serializoval vydání předpisů všech firem skupiny.
        $dimensions=new \MyInvoice\Repository\DimensionRepository($this->db);
        [$typeScope,$typeParams]=$dimensions->visibleSql($supplier,'t');
        [$valueScope,$valueParams]=$dimensions->visibleSql($supplier,'v');
        $stmt=$this->db->pdo()->prepare("SELECT t.id type_id,v.id value_id,t.system_key FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id WHERE {$typeScope} AND {$valueScope} AND t.is_active=1 AND v.is_active=1 ORDER BY t.id,v.id".($current?' LOCK IN SHARE MODE':''));
        $stmt->execute([...$typeParams,...$valueParams]); $values=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $values[(int)$row['value_id']]=['type_id'=>(int)$row['type_id'],'system_key'=>$row['system_key']];
        $calculations=[];
        foreach($version['preview']['calculation']['rates'] as $key=>$rate) $calculations[$key]=array_column($rate['calculation']['lines'],null,'unit_id');
        $charges=[]; $total='0';
        foreach($request['units'] as $unit) {
            $object=$objects[$unit['unit_id']]??null;
            $snapshot=PropertyTimeline::at($histories[$unit['unit_id']]??[],$request['claim_origin_at']);
            if($object===null||$object['kind']!=='unit'||$object['parent_id']!==$version['house_id']||!($snapshot['revision']['state']['active']??false)
                ||$snapshot['ownership_review_required']) throw new IssuanceException('property_charge_issue_registry_unproven','Jednotka vyžaduje potvrzený historický stav a vlastnický titul.',409,['unit_id'=>$unit['unit_id']]);
            $state=$snapshot['revision']['state'];
            $groups=LiabilityPolicy::validate($unit['liability_groups'],$state,$request['claim_origin_at'],$partyIds);
            $families=$previewHistory->families($unit['unit_id']);
            $last=$previewHistory->last($unit['unit_id']);
            if(($last['revision_no']??0)!==$unit['expected_revision']) throw new IssuanceException('property_charge_issue_revision_conflict','Předpis se mezitím změnil.',409);
            $this->previous($request,$unit,$last,$groups,$previewHistory->replacementReady($unit['unit_id']));
            $caseCandidates=[];
            if($request['issue_mode']==='initial') $caseCandidates=IssuanceCaseIdentity::assertInitial($supplier,$request,$unit,$families);
            else IssuanceCaseIdentity::assertContinuation($supplier,$request,$unit,$families);
            $oldLines=$previewHistory->oldLines($unit['unit_id']);
            $oldAnchors=$previewHistory->oldAnchors($unit['unit_id']);
            $lines=[]; $notices=[]; $unitTotal='0'; $givenNotices=array_column($unit['notices'],'notice','component_id');
            foreach($version['preview']['calculation']['rates'] as $key=>$rate) {
                $calculated=$calculations[$key][$unit['unit_id']]??null;
                if($calculated===null) continue;
                $component=$rate['component']; $componentId=$component['id'];
                $amount=$calculated['amount_cents'];
                if(bccomp($amount,'0',0)<0) throw new IssuanceException('property_charge_issue_negative_target','Předpis nemůže vytvářet záporný nárok. Doložte snížení do nuly nebo samostatný závazek z kreditu.');
                if($request['issue_mode']==='difference') $amount=bcsub($amount,$oldLines[$componentId]??'0',0);
                $account=$configured[$componentId]??throw new IssuanceException('property_charge_issue_account_required','Potvrďte účty a právní podklad všech složek.');
                $this->account($component,$account,$accounts,$request['accounting_date'],$redirects);
                $dims=$account['dimensions'];
                foreach($dims as $typeId=>$valueId) if(!isset($values[$valueId])||$values[$valueId]['type_id']!==(int)$typeId||in_array($values[$valueId]['system_key'],['unit','service'],true)) throw new IssuanceException('property_charge_issue_dimension_invalid','Použijte platné dimenze firmy; jednotku a službu určuje předpis.');
                if($object['dimension_value_id']===null||($component['service_id']!==null&&(!isset($objects[$component['service_id']]['dimension_value_id'])
                    ||!(PropertyTimeline::at($histories[$component['service_id']]??[],$request['claim_origin_at'])['revision']['state']['active']??false))))
                    throw new IssuanceException('property_charge_issue_dimension_invalid','Chybí doložená vazba aktivní jednotky nebo služby.',409);
                foreach([$object['dimension_value_id'],$component['service_id']===null?null:$objects[$component['service_id']]['dimension_value_id']] as $valueId) {
                    if($valueId===null) continue;
                    if(!isset($values[$valueId])) throw new IssuanceException('property_charge_issue_dimension_invalid','Chybí aktivní vazba jednotky nebo služby.',409);
                    $dims[$values[$valueId]['type_id']]=$valueId;
                }
                ksort($dims,SORT_NUMERIC);
                if($request['issue_mode']==='difference') foreach($oldAnchors[$componentId]??[] as $oldLine) {
                    if($oldLine['account']['receivable_account']!==$account['receivable_account']||$oldLine['account']['counter_account']!==$account['counter_account']
                        ||PropertyInput::hash($oldLine['dimensions'])!==PropertyInput::hash($dims))
                        throw new IssuanceException('property_charge_issue_full_reversal_required','Účty a dimenze původního předpisu nelze změnit pouhým rozdílem. Použijte úplné storno a nový předpis.',409);
                }
                if($amount==='0') continue;
                if($component['kind']==='service_advance') {
                    if(!in_array($component['service_id'],$state['services'],true)) throw new IssuanceException('property_charge_issue_service_unavailable','Služba jednotce v daném okamžiku nepatří.',409);
                    $changed=$previewHistory->changed($unit['unit_id'],$componentId,$calculated['amount_cents'],$version['request']['frequency']);
                    $recipients=[];
                    foreach($state['ownerships'] as $ownership) foreach($ownership['members'] as $id) $recipients[]=$id;
                    $notices[]=['component_id'=>$componentId,'notice'=>ServiceNoticePolicy::validate($givenNotices[$componentId]??[],$recipients,
                        $request['period_from'],$request['period_to'],$changed,$request['issued_at'],$version['request']['frequency'])];
                }
                $lines[]=['component_id'=>$componentId,'component'=>$component,'service_id'=>$component['service_id'],'amount_cents'=>$amount,
                    'target_cents'=>$calculated['amount_cents'],'calculation'=>$calculated,'account'=>$account,'dimensions'=>$dims];
                $unitTotal=bcadd($unitTotal,$amount,0);
            }
            if($request['issue_mode']==='difference') foreach($oldLines as $id=>$amount) if($amount!=='0') {
                $present=false; foreach($version['preview']['calculation']['rates'] as $key=>$rate)
                    if($rate['component']['id']===$id&&isset($calculations[$key][$unit['unit_id']])) $present=true;
                if(!$present) throw new IssuanceException('property_charge_issue_full_reversal_required','Odstranění složky opravte úplným stornem a novým předpisem.',409);
            }
            if($lines===[]||($request['issue_mode']!=='difference'&&bccomp($unitTotal,'0',0)<=0)) throw new IssuanceException('property_charge_issue_empty','Jednotka nemá kladný předpis ani doložený rozdíl.');
            $unitPartyIds=[];
            foreach($groups as $group) foreach($group['members'] as $id) $unitPartyIds[$id]=true;
            foreach($notices as $notice) foreach($notice['notice']['deliveries'] as $proof) $unitPartyIds[$proof['recipient_id']]=true;
            $charge=['unit_id'=>$unit['unit_id'],'revision_no'=>$unit['expected_revision']+1,'previous_charge_id'=>$unit['previous_charge_id'],
                'source_key'=>$request['source_key'],'title'=>$request['title'],'issue_mode'=>$request['issue_mode'],'period_from'=>$request['period_from'],'period_to'=>$request['period_to'],
                'claim_origin_at'=>$request['claim_origin_at'],'issued_at'=>$request['issued_at'],'accounting_date'=>$request['accounting_date'],'due_date'=>$request['due_date'],
                'amount_cents'=>$unitTotal,'frequency'=>$version['request']['frequency'],'version_id'=>$version['id'],'version_preview_hash'=>$version['preview_hash'],
                'registry'=>['object'=>$object,'revision'=>$snapshot['revision']],'liability_groups'=>$groups,
                'parties'=>array_values(array_filter($parties,fn($party)=>isset($unitPartyIds[$party['id']]))),'lines'=>$lines,'notices'=>$notices];
            if(isset($request['case_identity'])) {
                $charge['case_identity']=$request['case_identity'];
                if(isset($unit['case_distinction'])) $charge['case_distinction']=$unit['case_distinction'];
                if($request['issue_mode']==='initial') $charge['case_candidates']=$caseCandidates;
            }
            $charges[]=$charge; $total=bcadd($total,$unitTotal,0);
        }
        $snapshot=['request'=>$request,'version_id'=>$version['id'],'version_preview_hash'=>$version['preview_hash'],'profile_revision_id'=>$profile['id'],
            'charges'=>$charges,'total_cents'=>$total,'allocation_regime'=>'unassessed','security_basis'=>'unassessed'];
        return ['snapshot'=>$snapshot,'preview_hash'=>PropertyInput::hash($snapshot)];
    }

    private function previous(array $request,array $unit,?array $last,array $groups,bool $replacementReady): void
    {
        if($request['issue_mode']==='initial') {
            if($last!==null) throw new IssuanceException('property_charge_issue_source_exists','Tento případ již existuje; použijte doloženou opravu.',409);
            return;
        }
        if($last===null||$last['id']!==$unit['previous_charge_id']) throw new IssuanceException('property_charge_issue_revision_conflict','Oprava musí navazovat na poslední revizi.',409);
        if($request['issue_mode']==='replacement'&&!$replacementReady)
            throw new IssuanceException('property_charge_issue_reverse_first','Nejprve úplně stornujte předchozí předpis.',409);
        if($request['issue_mode']==='difference'&&($last['status']!=='posted'||$request['period_from']!==$last['period_from']||$request['period_to']!==$last['period_to']
            ||$request['claim_origin_at']!==$last['snapshot']['claim_origin_at']||PropertyInput::hash($groups)!==PropertyInput::hash($last['snapshot']['liability_groups'])))
            throw new IssuanceException('property_charge_issue_difference_title','Rozdíl musí zachovat původní případ, období a zavázané osoby.',409);
    }

    private function period(array $request,array $version,array $versions): void
    {
        $from=new \DateTimeImmutable($request['period_from'].'T00:00:00',new \DateTimeZone('Europe/Prague'));
        $end=(new \DateTimeImmutable($request['period_to'].'T00:00:00',new \DateTimeZone('Europe/Prague')))->modify('+1 day');
        $frequency=$version['request']['frequency'];
        $months=['monthly'=>1,'quarterly'=>3,'annual'=>12][$frequency]??null;
        if($months!==null&&($from->format('d')!=='01'||$from->modify('+'.$months.' months')->format('Y-m-d')!==$end->format('Y-m-d')))
            throw new IssuanceException('property_charge_issue_period_invalid','Období musí odpovídat potvrzené četnosti. Automatická poměrná část se nepočítá.');
        $start=PropertyInstant::normalize($from->format('c')); $stop=PropertyInstant::normalize($end->format('c'));
        $points=[$start];
        foreach($versions as $v) foreach([$v['effective_from'],$v['effective_to']] as $point) if($point!==null&&$point>$start&&$point<$stop) $points[]=$point;
        foreach($points as $point) {
            $active=null; foreach($versions as $v) if(PropertyInstant::contains($v['effective_from'],$v['effective_to'],$point)) {$active=$v['id'];break;}
            if($active!==$version['id']) throw new IssuanceException('property_charge_issue_period_split','Vybraná verze nepokrývá celé období. Doložte samostatné rozhodnutí bez automatické poměrné části.',409);
        }
        if($request['claim_origin_at']>$request['issued_at']) throw new IssuanceException('property_charge_issue_origin_after_issue','Vznik pohledávky nesmí následovat po vyhotovení předpisu.');
    }

    private function account(array $component,array $profile,array $accounts,string $date,array $redirects): void
    {
        $receivable=$accounts[$profile['receivable_account']]??null; $counter=$accounts[$profile['counter_account']]??null;
        if(!$receivable||!$counter||!$receivable['is_active']||!$counter['is_active']||$receivable['account_type']!=='asset'||substr($profile['receivable_account'],0,3)!=='315')
            throw new IssuanceException('property_charge_issue_account_invalid','Předpis vyžaduje aktivní pohledávkový účet 315 a doložený protiúčet.');
        if($component['kind']==='service_advance'&&str_starts_with($profile['counter_account'],'324.2'))throw new IssuanceException('property_charge_issue_account_invalid','Záloha na službu nemůže být přesměrována na účet záloh správy. Vyberte samostatnou analytiku služby.');
        $expected=match($component['kind']) {'service_advance','admin_advance'=>'324','long_term_advance'=>
            $profile['settlement_due_on']!==null&&$profile['settlement_due_on']>(new \DateTimeImmutable($date))->modify('+1 year')->format('Y-m-d')?'955':'324',default=>null};
        if(($expected!==null&&(substr($profile['counter_account'],0,3)!==$expected||$counter['account_type']!=='liability'))
            ||($component['kind']==='contribution'&&$counter['account_type']!=='revenue')
            ||($component['account_code']!==''&&($redirects[$component['account_code']]??$component['account_code'])!==$profile['counter_account']))
            throw new IssuanceException('property_charge_issue_account_invalid','Účet neodpovídá potvrzenému druhu složky a době vypořádání.');
    }
}
