<?php

declare(strict_types=1);
namespace MyInvoice\Action\Property\Catalog;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Property\Catalog\PropertyAccountingCatalog;
use MyInvoice\Service\Property\{PropertyException,PropertyInput};
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface as Response};

final class PropertyAccountingCatalogAction
{
    use AccountingActionSupport;
    public function __construct(private readonly PropertyAccountingCatalog $read){}
    private function query(Request $q,array $allowed):array{$args=$q->getQueryParams();PropertyInput::fields($args,$allowed);return $args;}
    private function integer(mixed $value,bool $zero=false):int
    {
        if(is_bool($value)||(!is_int($value)&&!is_string($value))||filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<($zero?0:1))PropertyInput::invalid('id');return(int)$value;
    }
    private function optional(array $q,string $key):?int{return isset($q[$key])?$this->integer($q[$key]):null;}
    private function known(array $q):?string{if(!array_key_exists('known_at',$q))return null;if(!is_string($q['known_at']))PropertyInput::invalid('known_at');return $q['known_at'];}
    private function date(array $q):string{if(!is_string($q['date']??null))PropertyInput::invalid('date');return $q['date'];}
    public function lifecycles(Request $q,Response $r):Response{return $this->run($q,$r,function(int $s)use($q):array{$a=$this->query($q,['after_id','limit','unit_id','service_id','known_at']);return $this->read->lifecycles($s,$this->integer($a['after_id']??0,true),$this->integer($a['limit']??25),$this->optional($a,'unit_id'),$this->optional($a,'service_id'),$this->known($a));});}
    public function lifecycle(Request $q,Response $r,array $args):Response{return $this->run($q,$r,function(int $s)use($q,$args):array{$a=$this->query($q,['known_at']);return $this->read->lifecycle($s,$this->integer($args['id']),$this->known($a));});}
    public function reconciliation(Request $q,Response $r):Response{return $this->run($q,$r,function(int $s)use($q):array{$a=$this->query($q,['date','unit_id','service_id','known_at']);return $this->read->reconciliation($s,$this->date($a),$this->optional($a,'unit_id'),$this->optional($a,'service_id'),$this->known($a));});}
    public function operations(Request $q,Response $r):Response{return $this->run($q,$r,function(int $s)use($q):array{$a=$this->query($q,['house_id','after_id','limit']);return $this->read->operations($s,$this->optional($a,'house_id'),$this->integer($a['after_id']??0,true),$this->integer($a['limit']??25));});}
    public function balances(Request $q,Response $r):Response{return $this->run($q,$r,function(int $s)use($q):array{$a=$this->query($q,['date','house_id','unit_id','known_at']);return $this->read->balances($s,$this->date($a),$this->optional($a,'house_id'),$this->optional($a,'unit_id'),$this->known($a));});}
    private function run(Request $q,Response $r,callable $read):Response
    {
        if(!RequestAuthorization::allows($q,'property',AccessLevel::READ))return Json::error($r,'forbidden','forbidden',403);
        try{return Json::ok($r,$read($this->currentSupplierId($q)));}
        catch(\Throwable $e){
            if(property_exists($e,'errorCode')&&is_string($e->errorCode))return Json::error($r,$e->errorCode,$e->errorCode,$e->httpStatus??$e->status??409);
            if($e instanceof \JsonException)return Json::error($r,'property_catalog_proof_invalid','property_catalog_proof_invalid',409);
            if($e instanceof \DomainException&&preg_match('/^(settlement|property)_[a-z0-9_]+$/D',$e->getMessage()))return Json::error($r,$e->getMessage(),$e->getMessage(),409);
            throw $e;
        }
    }
}
