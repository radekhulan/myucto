<?php
declare(strict_types=1);
namespace MyInvoice\Action\Property\Opening;
use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Property\{PropertyInput,PropertyException};
use MyInvoice\Service\Property\Opening\OpeningException;
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface as Response};
final class OpeningAction
{
    use AccountingActionSupport;
    public function __construct(private readonly OpeningRepository $owner){}
    public function list(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property',false,fn(int $supplier)=>['items'=>$this->owner->list($supplier),'bind_candidates'=>$this->owner->bindCandidates($supplier),'capabilities'=>['manage'=>RequestAuthorization::allows($request,'property.opening.manage',AccessLevel::WRITE),'post'=>RequestAuthorization::allows($request,'property.opening.post',AccessLevel::WRITE),'revise'=>RequestAuthorization::allows($request,'property.opening.revise',AccessLevel::WRITE)]]);
    }
    public function preview(Request $request,Response $response):Response{return $this->run($request,$response,'property.opening.manage',true,fn(int $supplier)=>$this->owner->preview($supplier,$this->body($request)));}
    public function confirm(Request $request,Response $response):Response{return $this->commit($request,$response,false);}
    public function revise(Request $request,Response $response):Response{return $this->commit($request,$response,true);}
    public function catalog(Request $request,Response $response):Response
    {
        if(!RequestAuthorization::allows($request,'property',AccessLevel::READ))return Json::error($response,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        return $this->handle($request,$response,function(int $supplier)use($request){$q=$request->getQueryParams();PropertyInput::fields($q,['after_id','limit','supplier_id']);$after=$q['after_id']??0;$limit=$q['limit']??25;foreach([$after,$limit] as $value)if(is_bool($value)||filter_var($value,FILTER_VALIDATE_INT)===false)PropertyInput::invalid('pagination');if((int)$after<0||(int)$limit<1||(int)$limit>100)PropertyInput::invalid('pagination');return $this->owner->catalog($supplier,(int)$after,(int)$limit);});
    }
    private function commit(Request $request,Response $response,bool $revision):Response
    {
        return $this->run($request,$response,$revision?'property.opening.revise':'property.opening.post',true,function(int $supplier)use($request,$revision){$body=$this->body($request);PropertyInput::fields($body,['request','preview_hash','idempotency_key']);if(!is_array($body['request']??null))PropertyInput::invalid('request');if(in_array($body['request']['mode']??null,['revision','reversal'],true)!==$revision)PropertyInput::invalid('mode');return $this->owner->apply($supplier,$body['request'],PropertyInput::text($body['preview_hash']??null,'preview_hash',64),PropertyInput::code($body['idempotency_key']??null,'idempotency_key'),$this->userId($request));});
    }
    private function run(Request $request,Response $response,string $permission,bool $write,callable $work):Response
    {
        if(!RequestAuthorization::isSessionAuth($request))return Json::sessionRequired($response);
        if(!RequestAuthorization::allows($request,$permission,$write?AccessLevel::WRITE:AccessLevel::READ))return Json::error($response,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        return $this->handle($request,$response,$work);
    }
    private function handle(Request $request,Response $response,callable $work):Response
    {
        try{return Json::ok($response,$work($this->currentSupplierId($request)));}
        catch(OpeningException|PropertyException|\MyInvoice\Service\Property\Payments\PaymentException|\MyInvoice\Service\Property\Charges\Issuance\IssuanceException|\MyInvoice\Service\Property\Receivables\ReceivableException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus,['details'=>$e->details]);}
        catch(\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Dimension\DimensionException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
    private function body(Request $request):array{$body=$request->getParsedBody();if(!is_array($body))PropertyInput::invalid('body');return $body;}
}
