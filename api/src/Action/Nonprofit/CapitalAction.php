<?php
declare(strict_types=1);
namespace MyInvoice\Action\Nonprofit;
use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Capital\CapitalChoices;
use MyInvoice\Service\Nonprofit\Capital\CapitalException;
use MyInvoice\Service\Nonprofit\Capital\CapitalPolicy;
use MyInvoice\Service\Nonprofit\Capital\CapitalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
final class CapitalAction
{
    use AccountingActionSupport;
    private readonly CapitalService $service;
    public function __construct(private readonly Connection $db,PostingService $posting){$this->service=new CapitalService($db,$posting);}
    public function read(Request $request,Response $response):Response{return $this->handle($request,$response,false,fn(int $supplier)=>$this->service->read($supplier));}
    public function choices(Request $request,Response $response,array $args):Response{return $this->handle($request,$response,false,fn(int $supplier)=>(new CapitalChoices($this->db))->read($supplier,(int)$args['id'],$request->getQueryParams()));}
    public function preview(Request $request,Response $response):Response{return $this->change($request,$response,false);}
    public function confirm(Request $request,Response $response):Response{return $this->change($request,$response,true);}
    private function change(Request $request,Response $response,bool $confirm):Response
    {
        return $this->handle($request,$response,true,function(int $supplier)use($request,$confirm):array{
            $b=$this->body($request,$confirm);$actor=$this->userId($request)??0;
            if($b['operation']==='register')return $confirm?$this->service->confirmSource($supplier,$b['policy'],$b['input_hash'],$b['reason'],$b['idempotency_key'],$actor):$this->service->previewSource($supplier,$b['policy']);
            if($b['operation']==='fund')return $confirm?$this->service->confirmFund($supplier,$b['source_id'],$b['cost_line_id'],$b['amount_cents'],$b['date'],$b['input_hash'],$b['reason'],$b['idempotency_key'],$actor):$this->service->previewFund($supplier,$b['source_id'],$b['cost_line_id'],$b['amount_cents'],$b['date']);
            return $confirm?$this->service->confirmReverse($supplier,$b['event_id'],$b['date'],$b['input_hash'],$b['reason'],$b['idempotency_key'],$actor):$this->service->previewReverse($supplier,$b['event_id'],$b['date']);
        });
    }
    private function body(Request $request,bool $confirm):array
    {
        $b=$request->getParsedBody();if(!is_array($b)||!in_array($b['operation']??null,['register','fund','reverse'],true))CapitalPolicy::invalid('Neplatný druh kapitálové operace.');
        $fields=match($b['operation']){'register'=>['operation','policy'],'fund'=>['operation','source_id','cost_line_id','amount_cents','date'],'reverse'=>['operation','event_id','date']};if($confirm)$fields=array_merge($fields,['input_hash','reason','idempotency_key']);
        if(array_diff(array_keys($b),$fields)!==[]||array_diff($fields,array_keys($b))!==[])CapitalPolicy::invalid('Požadavek musí obsahovat právě podporovaná pole.');
        foreach($fields as$key){if($key==='policy'){if(!is_array($b[$key]))CapitalPolicy::invalid('Zdroj vyžaduje strukturovanou politiku.');}elseif(in_array($key,['source_id','cost_line_id','event_id','amount_cents'],true)){if(!is_int($b[$key])||$b[$key]<=0)CapitalPolicy::invalid('Vazby a částka musí být kladná celá čísla.');}elseif(!is_string($b[$key]))CapitalPolicy::invalid('Datum, otisk a podklady musí být textové.');}return $b;
    }
    private function handle(Request $request,Response $response,bool $write,callable $work):Response
    {
        if($write&&!RequestAuthorization::isSessionAuth($request))return Json::error($response,'nonprofit_capital_session_only','Potvrzení kapitálových zdrojů vyžaduje přihlášenou relaci.',403);
        if($write?!$this->requireClose($request,$response,$error):!$this->requirePermission($request,$response,'accounting',AccessLevel::READ,$error))return $error;
        try{return Json::ok($response,$work($this->currentSupplierId($request)));}catch(CapitalException|PostingException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
}
