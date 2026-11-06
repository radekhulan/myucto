<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSourceRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Return\TaxReturnException;

final class NonprofitDppoDataProvider
{
    public function __construct(private readonly Connection $db,private readonly NonprofitTaxSourceRepository $sources,
        private readonly NonprofitTaxAssessmentRepository $assessments,private readonly J6FundingCapacity $fundingCapacity,private readonly \MyInvoice\Service\Accounting\Reports\FinancialStatementService $statements,private readonly \MyInvoice\Service\Accounting\Reports\Statement504Service $statements504,
        private readonly NonprofitDppoAssessmentSource $assessmentSource) {}

    public function gather(int $supplier,int $fiscalYear,array $inputs=[]):array
    {
        return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$fiscalYear,$inputs):array{
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
            $period=(new AccountingPeriodRepository($this->db))->findByYear($supplier,$fiscalYear)??throw new TaxReturnException('nonprofit_dppo_period_required','DPPO 504 vyžaduje skutečné účetní období.',422);
            if($this->assessmentSource->currentIsPagedOnly($supplier,(int)$period['id'])){
                // Stránkované posouzení se ověří proti otisku podkladů (úplnost členění včetně);
                // legacy snímek, jehož historie financování stránkované revize neumí, se nestaví.
                $assessment=$this->assessmentSource->currentView($supplier,(int)$period['id']);
                $j6Receipt=NonprofitDppoJ6Receipt::capturePaged($period,$assessment,$assessment['graph_receipt_hash']);
                $legal=$assessment['snapshot']['legal_profile']??null;
                $accountingResult=(int)$assessment['result']['accounting_result_cents'];$ledgerHash=$assessment['input_hash'];
            }else{
                $snapshot=$this->sources->snapshot($supplier,(int)$period['id'],true);
                if($snapshot['findings']!==[])throw new TaxReturnException('nonprofit_dppo_sources_incomplete','Účetní podklady vyžadují úplné průkazné sémantické členění.',422);
                $legal=$snapshot['legal_profile']??null;
                $accountingResult=$snapshot['accounting_result_cents'];$ledgerHash=NonprofitTaxRules::hash($snapshot['ledger']);
            }
            if($legal===null)throw new TaxReturnException('nonprofit_dppo_legal_profile_required','Pro období potvrďte doložený právní profil poplatníka.',422);
            if(isset($snapshot))[$assessment,$j6Receipt]=$this->assessmentSource->current($supplier,$period,$snapshot);
            if($assessment['result']['profile']!==$legal['profile']['kind'])throw new TaxReturnException('nonprofit_dppo_profile_conflict','Posouzení J6 nesouhlasí s právním profilem období.',409);
            $elections=$inputs['nonprofit']??[];if(!is_array($elections))$this->invalid();
            $bridge=(new NonprofitDppoClassificationBridge())->build($assessment['result'],$elections['documented_lines']??[]);
            $adjustments=$this->adjustments($supplier,$period,$assessment,$elections['adjustments']??[]);
            $savingDue=(new NonprofitTaxSavingsLedger($this->db))->due($supplier,(int)$period['id']);
            $context=NonprofitDppoComputation::seal(['schema'=>NonprofitDppoLegalRules::VERSION,'legal_profile'=>$legal,
                'assessment'=>['id'=>$assessment['id'],'period_id'=>$assessment['period_id'],'input_hash'=>$assessment['input_hash'],'request_hash'=>$assessment['request_hash'],'result'=>$assessment['result']],
                'j6_receipt'=>$j6Receipt,
                'bridge'=>$bridge,'adjustments'=>$adjustments,'unused_reduction_addback_kc'=>$savingDue['addback_kc'],'saving_due'=>$savingDue,
                'elections_hash'=>NonprofitTaxRules::hash($elections)]);
            $result=['accounting_decree'=>'504','period'=>['id'=>(int)$period['id'],'fiscal_year'=>$fiscalYear,'starts_on'=>$period['starts_on'],'ends_on'=>$period['ends_on']],
                'vh'=>$accountingResult/100,'non_deductible_costs'=>0.0,'depreciation'=>['tax'=>0.0,'accounting'=>0.0],
                'depreciation_by_group'=>['tangible'=>[],'intangible'=>0.0,'unclassified'=>0.0],
                'disposal_nondeductible_residual'=>0.0,'disposal_tax_increase'=>0.0,'disposal_tax_decrease'=>0.0,'disposal_decrease_groups'=>[],'disposals'=>[],
                'securities_cost_excess'=>0.0,'securities_sale'=>[],'closing_projection'=>null,'suggestions'=>['addbacks'=>[],'deductions'=>[]],
                'warnings'=>[],'nonprofit'=>$context,'j6_receipt'=>$j6Receipt,
                'source_manifest'=>['accounting_decree'=>'504','period'=>['id'=>(int)$period['id'],'starts_on'=>$period['starts_on'],'ends_on'=>$period['ends_on']],
                    'legal_profile'=>$legal,'assessment_id'=>$assessment['id'],'input_hash'=>$assessment['input_hash'],'request_hash'=>$assessment['request_hash'],'j6_receipt_hash'=>$j6Receipt['receipt_hash'],'nonprofit_proof_hash'=>$context['proof_hash'],'saving_due'=>$savingDue,'adjustments'=>$adjustments]];
            $balance=$this->statements->balanceSheet($supplier,(int)$period['id'],$period['ends_on'],'full');
            $income=$this->statements->incomeStatement($supplier,(int)$period['id'],$period['ends_on'],'full');
            self::assertStatementResult($income,$accountingResult);
            $result['statements']=['balance_sheet'=>$balance,'income_statement'=>$income,'epo_rows'=>(new NonprofitDppoStatementRows())->build($balance,$income)];
            $result['source_manifest']['statements_hash']=NonprofitTaxRules::hash($result['statements']);
            $criteria=$this->statements504->criteria($supplier,$period,$period['ends_on']);
            $result['net_turnover_annual_kc']=(int)\MyInvoice\Service\Tax\Return\TaxFormAmount::kc($criteria['net_turnover']);
            $result['source_manifest']['net_turnover']=['legal_basis'=>'zou_1d_5_2','annual_kc'=>$result['net_turnover_annual_kc'],'ledger_hash'=>$ledgerHash];
            NonprofitDppoComputation::validate($result,$inputs);return$result;
        });
    }

    /**
     * Výsledek J6 musí souhlasit s řádkem 62 (výsledek před zdaněním) nativní výsledovky 504.
     * Chybějící nebo nečíselný řádek je chyba výkazu, nikoli nula.
     */
    public static function assertStatementResult(array $income,int $accountingResultCents):void
    {
        $rows=array_column(is_array($income['rows']??null)?$income['rows']:[],null,'adis_row');
        $total=$rows[62]['total']??null;
        if(!is_int($total)&&!is_float($total)&&!(is_string($total)&&is_numeric($total))){
            throw new TaxReturnException('nonprofit_dppo_statement_reconciliation_failed','Nativní výsledovka 504 neobsahuje řádek 62 výsledku hospodaření před zdaněním.',422);
        }
        if((int)round((float)$total*100)!==$accountingResultCents){
            throw new TaxReturnException('nonprofit_dppo_statement_reconciliation_failed','Výsledek J6 nesouhlasí s nativní výsledovkou 504 před zdaněním.',422);
        }
    }

    private function adjustments(int $supplier,array $period,array $assessment,mixed $elections):array
    {
        if(!is_array($elections)||!array_is_list($elections))$this->invalid();$rows=array_column($assessment['result']['rows'],null,'id');$used=[];$out=[];
        foreach($elections as$election){
            if(is_array($election)&&($election['kind']??null)==='depreciation'){
                $adjustment=(new NonprofitDppoDepreciationAdjustment($this->db))->build($supplier,$period,$assessment,$election);
                foreach($adjustment['source_ids']as$id){if(isset($used[$id]))$this->invalid();$used[$id]=$rows[$id]['deductible_cents'];}
                $out[]=$adjustment;continue;
            }
            if(!is_array($election)||array_diff(array_keys($election),['kind','source_id','amount_cents','legal_basis','evidence'])!==[]||($election['kind']??null)!=='expense_addback'
                ||!is_string($election['source_id']??null)||!is_int($election['amount_cents']??null)||$election['amount_cents']<=0)$this->invalid();
            $basis=NonprofitTaxRules::evidence($election['legal_basis']??null);$evidence=NonprofitTaxRules::evidence($election['evidence']??null);$row=$rows[$election['source_id']]??null;
            if($row===null||$row['kind']!=='expense'||$row['deductible_cents']<=0)$this->invalid();
            $used[$row['id']]=($used[$row['id']]??0)+$election['amount_cents'];if($used[$row['id']]>$row['deductible_cents'])$this->invalid();
            $out[]=['kind'=>'expense_addback','source_id'=>$row['id'],'line'=>40,'amount_cents'=>$election['amount_cents'],'legal_basis'=>$basis,'evidence'=>$evidence,
                'proof'=>['assessment_id'=>$assessment['id'],'input_hash'=>$assessment['input_hash'],'request_hash'=>$assessment['request_hash'],'row'=>$row]];
        }
        return$out;
    }

    private function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_adjustment_invalid','Úprava musí vycházet z konkrétního původního řádku a nesmí opakovat již vyloučenou část nákladu.',422);}
}
