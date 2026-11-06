<?php
declare(strict_types=1);
namespace MyInvoice\Action\Nonprofit;
use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Nonprofit\Funding\FundingPolicy;
use MyInvoice\Service\Nonprofit\Funding\FundingService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
final class FundingAction
{
    use AccountingActionSupport;
    public function __construct(private readonly FundingService $service) {}
    public function read(Request $request,Response $response):Response
    {
        return $this->handle($request,$response,false,fn(int $supplier)=>$this->service->read($supplier));
    }
    public function choices(Request $request,Response $response,array $args):Response
    {
        return $this->handle($request,$response,false,function(int $supplier)use($request,$args):array{
            $search=$request->getQueryParams()['search']??'';if(!is_string($search))FundingPolicy::invalid('Hledaný poskytovatel musí být textový.');
            return $this->service->choices($supplier,(int)$args['id'],$search);
        });
    }
    public function preview(Request $request,Response $response,array $args):Response
    {
        return $this->handle($request,$response,true,function(int $supplier)use($request,$args):array{
            $body=$this->body($request,false);
            return $this->service->preview($supplier,(int)$args['id'],$body['source_key'],$body['policy']);
        });
    }
    public function confirm(Request $request,Response $response,array $args):Response
    {
        return $this->handle($request,$response,true,function(int $supplier)use($request,$args):array{
            $body=$this->body($request,true);
            return $this->service->confirm($supplier,(int)$args['id'],$body['source_key'],$body['policy'],$body['input_hash'],$body['expected_revision'],$body['reason'],$body['idempotency_key'],$this->userId($request)??0);
        });
    }
    private function body(Request $request,bool $confirm):array
    {
        $body=$request->getParsedBody();
        $allowed=$confirm?['source_key','policy','input_hash','expected_revision','reason','idempotency_key']:['source_key','policy'];
        if(!is_array($body)||array_diff(array_keys($body),$allowed)!==[]||array_diff($allowed,array_keys($body))!==[])FundingPolicy::invalid('Požadavek musí obsahovat právě podporovaná pole.');
        foreach($allowed as $field){
            if($field==='policy'){if(!is_array($body[$field]))FundingPolicy::invalid('Účelový zdroj vyžaduje strukturované podklady.');}
            elseif($field==='expected_revision'){if(!is_int($body[$field])||$body[$field]<0)FundingPolicy::invalid('Neplatná očekávaná revize.');}
            elseif(!is_string($body[$field]))FundingPolicy::invalid('Identifikátor, otisk a podklady musí být textové.');
        }
        return $body;
    }
    private function handle(Request $request,Response $response,bool $write,callable $work):Response
    {
        if($write&&!RequestAuthorization::isSessionAuth($request))return Json::error($response,'nonprofit_funding_session_only','Potvrzení účelu a rozpočtu vyžaduje přihlášenou relaci.',403);
        if($write?!$this->requireClose($request,$response,$error):!$this->requirePermission($request,$response,'accounting',AccessLevel::READ,$error))return $error;
        try{return Json::ok($response,$work($this->currentSupplierId($request)));}
        catch(NonprofitTaxAssessmentException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
}
