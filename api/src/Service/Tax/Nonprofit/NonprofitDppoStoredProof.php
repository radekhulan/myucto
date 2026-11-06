<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Tax\Return\{Dppo504CapabilityService,DppoLossAppendix,DppoReturnCalculator,DppoXmlBuilder,TaxReturnException};
use MyInvoice\Service\Validation\XmlSchemaValidator;

final class NonprofitDppoStoredProof
{
    private const HASH_MODE='stable_presentation_v1';

    public static function capture(array $data,array $inputs,array $constants,array $entityProof):array
    {
        $record=['version'=>3,'data'=>$data,'inputs'=>$inputs,'constants'=>$constants,'entity_proof'=>$entityProof,
            'form_manifest'=>(new Dppo504CapabilityService())->manifest(),'line_coverage'=>self::lineCoverage($data),'proof_hash_mode'=>self::HASH_MODE];
        self::constantsForRecord($record);
        self::assertVersionThree($record,false);
        return$record+['filing_proof_hash'=>self::filingProofHash($record)];
    }

    public static function filingProofHash(array $record):string
    {
        unset($record['filing_proof_hash']);
        return NonprofitTaxRules::hash(self::hashMode($record)===self::HASH_MODE?self::normalizePresentation($record):$record);
    }

    public static function comparisonHash(array $record,?string $profile=null):string
    {
        $profile??=self::comparisonProfile($record);if(!in_array($profile,['v3','v2','legacy'],true))self::invalid();
        unset($record['filing_proof_hash']);self::hashMode($record);unset($record['proof_hash_mode']);$record['constants']=self::constantsForRecord($record);
        if($profile!=='v3')$record=self::withoutVersionThree($record,$profile);
        return NonprofitTaxRules::hash(self::normalizePresentation($record));
    }

    public static function comparisonProfile(array $record):string
    {
        if(!array_key_exists('version',$record))return'legacy';
        return match($record['version']){3=>'v3',2=>'v2',default=>self::invalid()};
    }

    /**
     * Přenese zmrazený důkaz 504 na pseudonymizované podklady anonymizované kopie.
     *
     * Verze záznamu se zachová (v3 si nechá původní form_manifest a přepočte jen
     * line_coverage, v2 ani neverzovaný záznam pole v3 nikdy nedostanou). Nový záznam
     * musí dát přesně stejnou finanční projekci, stejné buňky výkazů, stejnou přílohu E,
     * období i vazby na posouzení a profil; jinak jde o podvržený výpočet, ne o pseudonymizaci.
     */
    public static function rebaseHistorical(array $original,array $data,array $inputs,array $entityProof):array
    {
        $profile=self::comparisonProfile($original);
        if(!is_array($original['data']??null)||!is_array($original['inputs']??null)||!is_array($original['entity_proof']??null))self::invalid();
        $constants=self::constantsForRecord($original);
        $calculator=new DppoReturnCalculator();
        $before=$calculator->compute($original['data'],$original['inputs'],$constants);
        self::assertCalculation($before,$original);
        if($profile!=='v3'&&self::hasVersionThreeFields($original))self::invalid();

        $record=$original;
        unset($record['filing_proof_hash']);
        $record['data']=$data;
        $record['inputs']=$inputs;
        $record['entity_proof']=$entityProof;
        if($profile==='v3'){
            $record['line_coverage']=self::lineCoverage($data);
            self::assertVersionThree($record,false);
        }elseif(self::hasVersionThreeFields($record))self::invalid();

        $after=$calculator->compute($record['data'],$record['inputs'],$constants);
        if(self::financial($before)!==self::financial($after)||self::semanticProjection($original)!==self::semanticProjection($record))self::invalid();
        $record['filing_proof_hash']=self::filingProofHash($record);
        self::assertCalculation($after,$record);
        return$record;
    }

    /**
     * Typované hodnoty záznamu, které pseudonymizace nesmí změnit: období, příloha E,
     * buňky výkazů, řádky můstku, úpravy, úspora a identita posouzení i profilu.
     */
    public static function semanticProjection(array $record):array
    {
        $data=$record['data']??null;
        $nonprofit=is_array($data)?($data['nonprofit']??null):null;
        if(!is_array($data)||!is_array($nonprofit))self::invalid();
        $adjustments=[];
        foreach($nonprofit['adjustments']??[]as$adjustment){
            if(!is_array($adjustment))self::invalid();
            $adjustments[]=['kind'=>$adjustment['kind']??null,'line'=>$adjustment['line']??null,'amount_cents'=>$adjustment['amount_cents']??null,
                'source_id'=>$adjustment['source_id']??null,'source_ids'=>$adjustment['source_ids']??null,'asset_id'=>$adjustment['asset_id']??null];
        }
        $receipt=$data['j6_receipt']??null;
        return[
            'version'=>$record['version']??null,
            'period'=>$data['period']??null,
            'loss_appendix'=>$data['loss_appendix']??null,
            'statements'=>self::statementProjection($data),
            'vh'=>$data['vh']??null,
            'net_turnover_annual_kc'=>$data['net_turnover_annual_kc']??null,
            'line_cents'=>$nonprofit['bridge']['line_cents']??null,
            'source_lines'=>$nonprofit['bridge']['source_lines']??null,
            'adjustments'=>$adjustments,
            'unused_reduction_addback_kc'=>$nonprofit['unused_reduction_addback_kc']??null,
            'saving_due_addback_kc'=>$nonprofit['saving_due']['addback_kc']??null,
            'assessment_id'=>$nonprofit['assessment']['id']??null,
            'assessment_period_id'=>$nonprofit['assessment']['period_id']??null,
            'legal_profile_id'=>$nonprofit['legal_profile']['id']??null,
            'legal_profile_kind'=>$nonprofit['legal_profile']['profile']['kind']??null,
            'j6_receipt'=>is_array($receipt)?['version'=>$receipt['version']??null,'assessment_id'=>$receipt['assessment_id']??null,'period'=>$receipt['period']??null]:null,
            'constants'=>$record['constants']??null,
            'form_manifest'=>$record['form_manifest']??null,
        ];
    }

    public static function assertCalculation(array $computed,array $record):void
    {
        $hash=$record['filing_proof_hash']??null;unset($record['filing_proof_hash']);
        if(!is_string($hash)||!hash_equals($hash,self::filingProofHash($record))||($record['data']['accounting_decree']??null)!=='504')self::invalid();
        if(($record['version']??null)===3)self::assertVersionThree($record,false);
        $expected=(new DppoReturnCalculator())->compute($record['data'],$record['inputs'],self::constantsForRecord($record));
        if(($record['version']??null)===3)DppoLossAppendix::verify($record['data']['loss_appendix']??[],(float)($expected['summary']['loss_applied']??0),self::yearLoss($expected));
        if(self::financial($computed)!==self::financial($expected))self::invalid();
    }

    public static function verify(array $snapshot,array $manifest,string $xml):array
    {
        $record=$snapshot['podklady']['nonprofit_calculation']??null;
        if(!is_array($record)||!is_array($record['data']??null)||!is_array($record['inputs']??null)||!is_array($record['constants']??null)||!is_array($record['entity_proof']??null))self::invalid();
        $hash=$record['filing_proof_hash']??null;unset($record['filing_proof_hash']);
        if(!is_string($hash)||!hash_equals($hash,self::filingProofHash($record))||($manifest['nonprofit_filing_proof_hash']??null)!==$hash||($record['data']['accounting_decree']??null)!=='504')self::invalid();
        if(($record['version']??null)===3)self::assertVersionThree($record,true);
        if(NonprofitTaxRules::hash($record['data']['source_manifest']??[])!==($manifest['nonprofit_data_manifest_hash']??null))self::invalid();
        $expected=(new DppoReturnCalculator())->compute($record['data'],$record['inputs'],self::constantsForRecord($record));$actual=$snapshot['computed']??null;if(!is_array($actual)||self::financial($actual)!==self::financial($expected))self::invalid();
        if(($record['version']??null)===3)DppoLossAppendix::verify($record['data']['loss_appendix']??[],(float)($expected['summary']['loss_applied']??0),self::yearLoss($expected));
        if(($expected['nonprofit']['proof_hash']??null)!==($manifest['nonprofit_proof_hash']??null)||($record['data']['source_manifest']['statements_hash']??null)!==NonprofitTaxRules::hash($record['data']['statements']??[]))self::invalid();
        $previous=libxml_use_internal_errors(true);try{$dom=new \DOMDocument();$loaded=$dom->loadXML($xml,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}if(!$loaded||(new XmlSchemaValidator())->validate($xml,'dppdp9')['status']!=='passed')self::invalid();
        $xp=new \DOMXPath($dom);$header=$xp->query('/Pisemnost/DPPDP9/VetaD')?->item(0);$form=$xp->query('/Pisemnost/DPPDP9/VetaO')?->item(0);
        if(!$header instanceof \DOMElement||!$form instanceof \DOMElement||$header->getAttribute('uv_vyhl')!=='504')self::invalid();
        if($xp->query('/Pisemnost/DPPDP9/VetaUA|/Pisemnost/DPPDP9/VetaUB|/Pisemnost/DPPDP9/VetaUD|/Pisemnost/DPPDP9/VetaUZ')->length!==0)self::invalid();
        if(($record['version']??null)===3)DppoLossAppendix::assertXml($record['data']['loss_appendix'],$xp);
        $profile=$expected['nonprofit']['legal_profile']['profile'];$type=in_array($profile['kind'],['narrow_vpp','broad_vpp'],true)?'3':'1';if($header->getAttribute('typ_popldpp')!==$type)self::invalid();
        $period=$record['data']['period'];foreach(['zdobd_od'=>'starts_on','zdobd_do'=>'ends_on']as$attribute=>$key){try{$date=new \DateTimeImmutable($header->getAttribute($attribute));}catch(\Throwable){self::invalid();}if($date->format('Y-m-d')!==$period[$key])self::invalid();}
        foreach($expected['lines']as$line){$attribute=DppoXmlBuilder::LINE_ATTR[$line['line']]??null;if($attribute!==null&&(string)(int)$line['value']!==(string)(int)$form->getAttribute($attribute))self::invalid();}
        $rows=(new NonprofitDppoStatementRows())->build($record['data']['statements']['balance_sheet'],$record['data']['statements']['income_statement']);
        foreach(['UR','US','UT']as$sentence){$actualRows=[];foreach($xp->query('/Pisemnost/DPPDP9/Veta'.$sentence)as$node){$row=[];foreach($node->attributes as$attribute)$row[$attribute->name]=(int)$attribute->value;$actualRows[]=$row;}if(NonprofitTaxRules::hash($actualRows)!==NonprofitTaxRules::hash($rows[$sentence]))self::invalid();}
        return['filing_proof_hash'=>$hash,'comparison_hash'=>self::comparisonHash($record),'comparison_profile'=>self::comparisonProfile($record),'nonprofit'=>$expected['nonprofit'],'period'=>$period,'financial'=>self::financial($expected)];
    }

    public static function constantsForRecord(array $record): array
    {
        $constants=$record['constants']??null;if(!is_array($constants))self::invalid();
        $versioned=array_key_exists('version',$record);if($versioned&&!in_array($record['version'],[2,3],true))self::invalid();
        $keys=['nonprofit_reduction_rate','nonprofit_reduction_min','nonprofit_reduction_max','nonprofit_reduction_university_min','nonprofit_reduction_university_max'];
        $missing=array_diff($keys,array_keys($constants));
        if($missing!==[]){
            $year=$constants['year']??null;$ruleset=$record['data']['nonprofit']['schema']??null;
            if($versioned||count($missing)!==count($keys)||!is_int($year)||!is_string($ruleset)||($record['data']['accounting_decree']??null)!=='504'||($record['data']['period']['fiscal_year']??null)!==$year)self::invalid();
            try{$legacy=\MyInvoice\Service\Tax\TaxConstants::legacyNonprofitReduction($ruleset,$year);}catch(\OutOfRangeException){self::invalid();}
            $constants+=$legacy;
        }
        NonprofitDppoReduction::configuration($constants);
        return$constants;
    }

    private static function assertVersionThree(array $record,bool $requireVerifiedSchema):void
    {
        self::hashMode($record);
        $data=$record['data']??null;$period=is_array($data)?($data['period']??null):null;$receipt=is_array($data)?($data['j6_receipt']??null):null;
        if(!is_array($data)||!is_array($period)||!is_array($receipt)||!is_array($record['form_manifest']??null)||!is_array($record['line_coverage']??null))self::invalid();
        $result=$data['nonprofit']['assessment']['result']??null;if(!is_array($result))self::invalid();
        try{NonprofitDppoJ6Receipt::verify($receipt,$period,$result);(new Dppo504CapabilityService())->assertManifest($record['form_manifest'],$requireVerifiedSchema);}catch(\Throwable){self::invalid();}
        if(($data['source_manifest']['j6_receipt_hash']??null)!==($receipt['receipt_hash']??null)||$record['line_coverage']!==self::lineCoverage($data))self::invalid();
    }

    private static function lineCoverage(array $data):array
    {
        $rows=$data['nonprofit']['assessment']['result']['rows']??null;$mapped=$data['nonprofit']['bridge']['source_lines']??null;
        if(!is_array($rows)||!array_is_list($rows)||!is_array($mapped)||!array_is_list($mapped))self::invalid();
        $sourceIds=[];foreach($rows as$row){$id=is_array($row)?($row['id']??null):null;if(!is_string($id)||$id===''||isset($sourceIds[$id]))self::invalid();$sourceIds[$id]=true;}
        $byId=array_column($rows,null,'id');$mappedIds=[];foreach($mapped as$line){
            $id=is_array($line)?($line['source_id']??null):null;if(!is_string($id)||!isset($sourceIds[$id])||isset($mappedIds[$id])||!array_key_exists('line',$line)||!is_int($line['amount_cents']??null))self::invalid();
            $row=$byId[$id];$expected=($row['kind']??null)==='expense'?($row['adjustment_cents']??null):($row['amount_cents']??null);
            if(!is_int($expected)||$line['amount_cents']!==$expected||(($row['kind']??null)==='expense'&&$line['line']!==40)||(($row['kind']??null)==='revenue'&&!in_array($line['line'],[null,100,101,109,110,120],true)))self::invalid();
            $mappedIds[$id]=true;
        }
        if(array_keys($sourceIds)!==array_keys($mappedIds))self::invalid();$ids=array_keys($sourceIds);sort($ids,SORT_STRING);
        return['count'=>count($ids),'source_ids'=>$ids,'source_lines_hash'=>NonprofitTaxRules::hash($mapped),'j6_rows_hash'=>NonprofitTaxRules::hash($rows)];
    }

    private static function hashMode(array $record):?string
    {
        if(!array_key_exists('proof_hash_mode',$record))return null;
        if(($record['version']??null)!==3||$record['proof_hash_mode']!==self::HASH_MODE)self::invalid();
        return self::HASH_MODE;
    }

    private static function normalizePresentation(array $record):array
    {
        unset($record['data']['statements']['balance_sheet']['entity']['prepared_at'],$record['data']['statements']['income_statement']['entity']['prepared_at'],$record['entity_proof']['entity_header']['prepared_at']);
        if(is_array($record['data']['statements']??null)&&is_array($record['data']['source_manifest']??null))$record['data']['source_manifest']['statements_hash']=NonprofitTaxRules::hash($record['data']['statements']);
        return$record;
    }

    private static function withoutVersionThree(array $record,string $profile):array
    {
        unset($record['form_manifest'],$record['line_coverage'],$record['data']['j6_receipt'],$record['data']['loss_appendix'],$record['data']['nonprofit']['j6_receipt'],$record['data']['source_manifest']['j6_receipt_hash'],$record['data']['source_manifest']['loss_appendix_hash']);
        if(is_array($record['data']['nonprofit']??null)){
            $record['data']['nonprofit']=NonprofitDppoComputation::seal($record['data']['nonprofit']);
            if(is_array($record['data']['source_manifest']??null))$record['data']['source_manifest']['nonprofit_proof_hash']=$record['data']['nonprofit']['proof_hash'];
        }
        if($profile==='v2')$record['version']=2;else unset($record['version']);
        return$record;
    }

    private static function financial(array $computed):array
    {
        $lines=[];foreach($computed['lines']??[]as$row){if(!is_array($row)||!is_int($row['line']??null)||!is_numeric($row['value']??null)||isset($lines[$row['line']]))self::invalid();$lines[$row['line']]=(string)(int)$row['value'];}ksort($lines,SORT_NUMERIC);
        return['lines'=>$lines,'tax'=>(string)(int)($computed['tax']??-1),'advances_paid'=>(string)(int)($computed['advances_paid']??-1),'balance_due'=>(string)(int)($computed['balance_due']??-1),'saving'=>$computed['nonprofit']['saving']??null,'filing_obligation'=>$computed['nonprofit']['filing_obligation']??null];
    }
    private static function statementProjection(array $data):array
    {
        $statements=$data['statements']??null;
        if(!is_array($statements)||!is_array($statements['balance_sheet']??null)||!is_array($statements['income_statement']??null))self::invalid();
        $cells=$statements;
        unset($cells['balance_sheet']['entity'],$cells['income_statement']['entity']);
        return['cells'=>$cells,'epo'=>(new NonprofitDppoStatementRows())->build($statements['balance_sheet'],$statements['income_statement'])];
    }

    private static function hasVersionThreeFields(array $record):bool
    {
        return array_key_exists('form_manifest',$record)||array_key_exists('line_coverage',$record)||array_key_exists('proof_hash_mode',$record)
            ||array_key_exists('j6_receipt',$record['data']??[])||array_key_exists('loss_appendix',$record['data']??[]);
    }

    private static function yearLoss(array $computed):float
    {$base=(float)($computed['summary']['base']??0);return$base<0?round(-$base,2):0.0;}
    private static function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_stored_proof_invalid','Finální přiznání 504 nemá úplný shodný důkaz výpočtu a nativního XML. Ověřte původní snapshot.',409);}
}
