<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Service\Property\PropertyInput;

final class IssuancePreviewHistory
{
    private array $last=[];
    private array $replacementReady=[];
    private array $oldLines=[];
    private array $oldAnchors=[];
    private array $families=[];
    private array $targets=[];

    public function __construct(iterable $charges,array $request,?array $families=null,?iterable $targets=null)
    {
        $units=array_fill_keys(array_column($request['units'],'unit_id'),true);
        if($families!==null&&$targets!==null){
            foreach($charges as$charge)if(isset($units[$charge['unit_id']]))$this->rememberHistory($charge);
            foreach($targets as$charge)if(isset($units[$charge['unit_id']]))ServiceNoticePolicy::rememberTarget($this->targets,$charge,$request['period_from']);
            foreach($families as$family)if(isset($units[$family['unit_id']]))$this->families[$family['unit_id']][]=$family;
            return;
        }
        $observed=(function() use($charges,$request,$units): \Generator {
            foreach($charges as $charge) {
                if(isset($units[$charge['unit_id']])) {
                    if($charge['source_key']===$request['source_key']) $this->rememberHistory($charge);
                    ServiceNoticePolicy::rememberTarget($this->targets,$charge,$request['period_from']);
                }
                yield $charge;
            }
        })();
        foreach(IssuanceCaseIdentity::families($observed) as $family)
            if(isset($units[$family['unit_id']])) $this->families[$family['unit_id']][]=$family;
    }

    private function rememberHistory(array $charge): void
    {
        $unit=$charge['unit_id'];
        $this->last[$unit]=$charge;
        $this->replacementReady[$unit]=($this->replacementReady[$unit]??true)&&in_array($charge['status'],['reversed','cancelled'],true);
        if($charge['status']!=='posted') return;
        foreach($charge['snapshot']['lines'] as $line) {
            $component=$line['component_id'];
            $this->oldLines[$unit][$component]=bcadd($this->oldLines[$unit][$component]??'0',$line['amount_cents'],0);
            $anchor=['account'=>['receivable_account'=>$line['account']['receivable_account'],'counter_account'=>$line['account']['counter_account']],
                'dimensions'=>$line['dimensions']];
            $this->oldAnchors[$unit][$component][PropertyInput::hash($anchor)]=$anchor;
        }
    }

    public function last(int $unit): ?array
    {
        return $this->last[$unit]??null;
    }

    public function replacementReady(int $unit): bool
    {
        return $this->replacementReady[$unit]??true;
    }

    public function oldLines(int $unit): array
    {
        return $this->oldLines[$unit]??[];
    }

    public function oldAnchors(int $unit): array
    {
        return $this->oldAnchors[$unit]??[];
    }

    public function families(int $unit): array
    {
        return $this->families[$unit]??[];
    }

    public function changed(int $unit,int $component,string $amount,string $frequency): bool
    {
        return ServiceNoticePolicy::targetChanged($this->targets[$unit][$component]??null,$amount,$frequency);
    }
}
