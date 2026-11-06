<?php

declare(strict_types=1);
namespace MyInvoice\Action\Nonprofit\Catalog;
use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Nonprofit\Catalog\FundingCatalogEvidenceRead;
use MyInvoice\Service\Nonprofit\Catalog\NonprofitResourceCatalog;
use MyInvoice\Service\Nonprofit\Capital\CapitalException;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Accounting\{PostingException,Dimension\DimensionException};
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface as Response};

final class NonprofitResourceCatalogAction
{
    use AccountingActionSupport;
    public function __construct(private readonly NonprofitResourceCatalog $catalog,private readonly FundingCatalogEvidenceRead $evidence) {}

    /** Ověřitelné podklady zdroje z potvrzeného posouzení J6 (účel, souhrn čerpání, čerpané náklady). */
    public function fundingEvidence(Request $request,Response $response,array $args): Response
    {
        if(!RequestAuthorization::allows($request,'accounting',AccessLevel::READ))return Json::error($response,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        $q=$request->getQueryParams();
        foreach(['date','as_of','known_at']as$key)if(array_key_exists($key,$q))return Json::error($response,'nonprofit_catalog_time_unsupported','Katalog dokládá současný stav, nikoli historický okamžik.',409);
        $invalid=static fn():Response=>Json::error($response,'nonprofit_funding_catalog_query_invalid','Neplatný filtr nebo stránkování potvrzeného účelu a použití.',422);
        if(array_diff(array_keys($q),['period_id','assessment_id','kind','after_ordinal','offset','limit','supplier_id'])!==[])return $invalid();
        $int=static function(mixed $value,int $min):?int{
            if(is_bool($value)||filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<$min)return null;
            return (int)$value;
        };
        $period=$int($q['period_id']??null,1);$assessment=$int($q['assessment_id']??null,1);$limit=$int($q['limit']??25,1);
        $kind=$q['kind']??null;
        if($period===null||$assessment===null||$limit===null||!is_string($kind)||!isset(FundingCatalogEvidenceRead::CHUNKS[$kind]))return $invalid();
        $cursor=null;
        if(array_key_exists('after_ordinal',$q)){
            $after=$int($q['after_ordinal'],-1);$offset=$int($q['offset']??0,0);
            if($after===null||$offset===null)return $invalid();
            $cursor=['after_ordinal'=>$after,'offset'=>$offset];
        }elseif(array_key_exists('offset',$q))return $invalid();
        try{
            return Json::ok($response,$this->evidence->read($this->currentSupplierId($request),(int)$args['id'],$period,$assessment,$kind,$cursor,$limit));
        }catch(NonprofitTaxAssessmentException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
    public function funding(Request $request,Response $response,array $args=[]): Response{return $this->read($request,$response,$args,false);}
    public function capital(Request $request,Response $response,array $args=[]): Response{return $this->read($request,$response,$args,true);}
    public function events(Request $request,Response $response,array $args=[]): Response{return $this->read($request,$response,$args,true,true);}
    private function read(Request $request,Response $response,array $args,bool $capital,bool $events=false): Response
    {
        if(!RequestAuthorization::allows($request,'accounting',AccessLevel::READ))return Json::error($response,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        $q=$request->getQueryParams();
        foreach(['date','as_of','known_at']as$key)if(array_key_exists($key,$q))return Json::error($response,'nonprofit_catalog_time_unsupported','Katalog dokládá současný stav, nikoli historický okamžik.',409);
        $detail=isset($args['id'])&&!$events;$allowed=$detail?['supplier_id']:['after_id','limit','supplier_id'];
        $after=$q['after_id']??0;$limit=$q['limit']??25;
        if(array_diff(array_keys($q),$allowed)!==[]||is_bool($after)||is_bool($limit)||filter_var($after,FILTER_VALIDATE_INT)===false||filter_var($limit,FILTER_VALIDATE_INT)===false||(int)$after<0||(int)$limit<1||(int)$limit>100)return Json::error($response,'nonprofit_catalog_query_invalid','Neplatný filtr nebo stránkování.',422);
        try{
            $supplier=$this->currentSupplierId($request);$id=isset($args['id'])?(int)$args['id']:null;
            return Json::ok($response,$capital?$this->catalog->capital($supplier,(int)$after,(int)$limit,$id,$events):$this->catalog->funding($supplier,(int)$after,(int)$limit,$id));
        }catch(NonprofitTaxAssessmentException|CapitalException|PostingException|DimensionException|PaymentException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
}
