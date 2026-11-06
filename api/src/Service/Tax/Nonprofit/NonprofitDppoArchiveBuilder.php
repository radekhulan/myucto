<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;
use MyInvoice\Service\Pdf\StatementNotesPdfRenderer;
use MyInvoice\Service\Tax\Return\{DppoXmlBuilder,TaxPeriodShape,TaxReturnException};

final class NonprofitDppoArchiveBuilder
{
    public function __construct(private readonly DppoXmlBuilder $xml,private readonly StatementNotesPdfRenderer $notesPdf) {}

    public function build(array $record,array $computed,array $meta=[]):array
    {
        NonprofitDppoStoredProof::assertCalculation($computed,$record);
        $data=$record['data'];$entity=$record['entity_proof'];$period=$data['period'];$profile=$data['nonprofit']['legal_profile']['profile'];$notes=$entity['notes'];
        if(($notes['missing']??null)!==[]||($notes['accounting_framework']??null)!=='cz_504')throw new TaxReturnException('nonprofit_dppo_notes_required','Doplňte úplnou přílohu účetní závěrky 504 před sestavením podání.',422);
        $year=(int)$period['fiscal_year'];$supplier=$entity['supplier'];$supplier['tax_accounting_decree']='504';
        $meta=array_replace($meta,$entity['representation'],[
            'accounting_decree'=>'504','nonprofit_calculation'=>$record,'puz_to_registry'=>false,
            'typ_popldpp'=>in_array($profile['kind'],['narrow_vpp','broad_vpp'],true)?'3':'1',
            'zdobd_od'=>(new \DateTimeImmutable($period['starts_on']))->format('d.m.Y'),'zdobd_do'=>(new \DateTimeImmutable($period['ends_on']))->format('d.m.Y'),
            'typ_zo'=>TaxPeriodShape::typZo(TaxPeriodShape::classify($period['starts_on'],$period['ends_on'])),
            'net_turnover_annual_kc'=>$data['net_turnover_annual_kc'],
        ]);
        $appendix=$data['statements']+['settings'=>$entity['settings'],'category'=>['scope'=>'full'],'loss_appendix'=>$data['loss_appendix']??null];
        $appendix['statement_notes_attachment']=[
            'content'=>$this->notesPdf->render(['notes'=>$notes,'entity'=>$entity['entity_header'],'period'=>['starts_on'=>$period['starts_on'],'ends_on'=>$period['ends_on']]]),
            'filename'=>sprintf('priloha-ucetni-zaverky-%d.pdf',$year),'label'=>sprintf('Příloha v účetní závěrce za rok %d (vyhl. 504/2002 Sb.)',$year),
        ];
        return$this->xml->build($supplier,$year,$computed,$meta,$appendix);
    }
}
