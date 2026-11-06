<?php

declare(strict_types=1);
namespace MyInvoice\Action\Property\Payments;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Payments\{PaymentOperationsReadRepository,PaymentWorkflowReadRepository,PaymentRepository,DebtRepository,CreditSetoffRepository,ReliefCorrectionRepository,PaymentExportRepository};
use MyInvoice\Service\Property\{PropertyInput,PropertyException};
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

final class PaymentOperationsAction
{
    use AccountingActionSupport;
    public function __construct(private readonly PaymentOperationsReadRepository $read,private readonly PaymentWorkflowReadRepository $sources,
        private readonly PaymentRepository $payments,private readonly DebtRepository $debts,private readonly CreditSetoffRepository $setoffs,
        private readonly ReliefCorrectionRepository $corrections,private readonly PaymentExportRepository $exports) {}

    public function page(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property',false,function(int $supplier)use($request,$args):array{
            $q=$request->getQueryParams();return $this->read->page($supplier,(string)($args['collection']??''),$this->integer($q,'after_id',0),$this->integer($q,'limit',25),isset($q['unit_id'])?$this->integer($q,'unit_id'):null);
        });
    }
    public function detail(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property',false,fn(int $supplier):array=>$this->read->detail($supplier,(string)($args['collection']??''),$this->integer($args,'id')));
    }
    public function candidates(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property',false,function(int $supplier)use($request):array{
            $q=$request->getQueryParams();$kind=(string)($q['kind']??'');$permission=$kind==='bank'?'bank.post':'cash.document.write';
            $allowNative=RequestAuthorization::isSessionAuth($request)&&RequestAuthorization::allows($request,$permission,AccessLevel::WRITE);
            $search=is_string($q['q']??null)?$q['q']:'';
            $id=isset($q['id'])?$this->integer($q,'id'):null;
            return $this->sources->candidates($supplier,$kind,$this->integer($q,'after_id',0),$this->integer($q,'limit',25),$allowNative,$search,$id);
        });
    }
    public function parties(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property',false,function(int $supplier)use($request):array{$q=$request->getQueryParams();return $this->read->parties($supplier,(string)($q['query']??''),$this->integer($q,'after_id',0),$this->integer($q,'limit',25));});
    }
    public function card(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property',false,function(int $supplier)use($request):array{$q=$request->getQueryParams();$scope=[];foreach(['unit_id','client_id'] as $key)if(isset($q[$key]))$scope[$key]=$this->integer($q,$key);return $this->read->card($supplier,(string)($q['as_of']??''),(string)($q['known_at']??''),$scope);});
    }
    public function correctionOptions(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property',false,fn(int $supplier):array=>$this->read->correctionOptions($supplier,$this->integer($request->getQueryParams(),'adjustment_charge_id')));
    }
    public function bankAccounts(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property',false,fn(int $supplier):array=>$this->read->bankAccounts($supplier));
    }
    public function accessoryCandidates(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property',false,function(int $supplier)use($request):array{$q=$request->getQueryParams();return $this->read->accessoryCandidates($supplier,$this->integer($q,'after_id',0),$this->integer($q,'limit',25));});
    }
    public function bindAccessory(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,function(int $supplier)use($request,$args):array{$result=$this->debts->bindAccessory($supplier,$this->integer($args,'id'),$this->body($request),$this->userId($request));return ['id'=>$result['id']];});
    }
    public function exportPreview(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,fn(int $supplier):array=>$this->exports->preview($supplier,$this->body($request)));
    }
    public function exportConfirm(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,function(int $supplier)use($request):array{$body=$this->body($request,['request','preview_hash','idempotency_key']);return $this->exports->confirm($supplier,$this->input($body),$this->hash($body),$this->token($body),$this->userId($request));});
    }
    public function exportMetadata(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property',false,fn(int $supplier):array=>['export'=>$this->exports->metadataForReservation($supplier,$this->integer($args,'id'))]);
    }
    public function exportDownload(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,fn(int $supplier):array=>$this->exports->find($supplier,$this->integer($args,'id')));
    }
    public function cancelExport(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,function(int $supplier)use($request,$args):array{$body=$this->body($request,['expected_version','reason','evidence','idempotency_key']);$result=$this->exports->cancelSubmission($supplier,$this->integer($args,'id'),$this->integer($body,'expected_version'),PropertyInput::text($body['reason']??null,'reason',4000),PropertyInput::text($body['evidence']??null,'evidence',4000),$this->token($body),$this->userId($request));return ['id'=>$result['id'],'row_version'=>$result['row_version'],'status'=>$result['status'],'cancel_requested'=>$result['cancel_requested']];});
    }
    public function releaseUnexecuted(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,function(int $supplier)use($request,$args):array{
            $body=$this->body($request,['expected_version','statement_id','evidence','idempotency_key']);
            $result=$this->exports->releaseUnexecuted($supplier,$this->integer($args,'id'),$this->integer($body,'expected_version'),$this->integer($body,'statement_id'),
                PropertyInput::text($body['evidence']??null,'evidence',4000),$this->token($body),$this->userId($request));
            return ['id'=>$result['id'],'row_version'=>$result['row_version'],'status'=>$result['status'],'cancel_requested'=>$result['cancel_requested']];
        });
    }
    public function registerDebt(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,function(int $supplier)use($request):array{$body=$this->body($request,['request','idempotency_key']);$result=$this->debts->register($supplier,$this->input($body),$this->token($body),$this->userId($request));return ['id'=>$result['id']];});
    }
    public function reserve(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,function(int $supplier)use($request,$args):array{$body=$this->body($request,['expected_version','request','idempotency_key']);$result=$this->payments->reserve($supplier,$this->integer($args,'id'),$this->integer($body,'expected_version'),$this->input($body),$this->token($body),$this->userId($request));return ['id'=>$result['id'],'row_version'=>$result['row_version']];});
    }
    public function cancelReservation(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,function(int $supplier)use($request,$args):array{$body=$this->body($request,['expected_version','reason','idempotency_key']);$result=$this->payments->cancelReservation($supplier,$this->integer($args,'id'),$this->integer($body,'expected_version'),PropertyInput::text($body['reason']??null,'reason',4000),$this->token($body),$this->userId($request));return ['id'=>$result['id'],'row_version'=>$result['row_version']];});
    }
    public function setoffPreview(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,fn(int $supplier):array=>$this->setoffs->preview($supplier,$this->body($request)));
    }
    public function setoffConfirm(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property.payments.post',true,function(int $supplier)use($request):array{$body=$this->body($request,['request','preview_hash','idempotency_key']);$result=$this->setoffs->apply($supplier,$this->input($body),$this->hash($body),$this->token($body),$this->userId($request));return ['id'=>$result['id'],'row_version'=>$result['row_version']];});
    }
    public function correctionPreview(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property.payments.manage',true,fn(int $supplier):array=>$this->corrections->preview($supplier,$this->body($request)));
    }
    public function correctionConfirm(Request $request,Response $response):Response
    {
        return $this->run($request,$response,'property.payments.post',true,function(int $supplier)use($request):array{$body=$this->body($request,['request','preview_hash','idempotency_key']);$result=$this->corrections->apply($supplier,$this->input($body),$this->hash($body),$this->token($body),$this->userId($request));return ['id'=>$result['id'],'row_version'=>$result['row_version']];});
    }
    public function reverse(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property.payments.reverse',true,function(int $supplier)use($request,$args):array{$body=$this->body($request,['expected_version','accounting_date','reason','idempotency_key']);$result=$this->payments->reverse($supplier,$this->integer($args,'id'),$this->integer($body,'expected_version'),PropertyInput::text($body['accounting_date']??null,'accounting_date',10),PropertyInput::text($body['reason']??null,'reason',4000),$this->token($body),$this->userId($request));return ['id'=>$result['id'],'row_version'=>$result['row_version']];});
    }
    public function correctionReverse(Request $request,Response $response,array $args):Response
    {
        return $this->run($request,$response,'property.payments.reverse',true,function(int $supplier)use($request,$args):array{$body=$this->body($request,['request','idempotency_key']);$result=$this->corrections->reverse($supplier,$this->integer($args,'id'),$this->input($body),$this->token($body),$this->userId($request));return ['id'=>$result['id'],'row_version'=>$result['row_version']];});
    }
    private function run(Request $request,Response $response,string $permission,bool $write,callable $work):Response
    {
        if($write&&!RequestAuthorization::isSessionAuth($request))return Json::sessionRequired($response);
        if(!RequestAuthorization::allows($request,$permission,$write?AccessLevel::WRITE:AccessLevel::READ))return Json::error($response,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        try{return Json::ok($response,$work($this->currentSupplierId($request)));}
        catch(PaymentException|\MyInvoice\Service\Property\Opening\OpeningException|PropertyException|\MyInvoice\Service\Property\Receivables\ReceivableException|\MyInvoice\Service\Property\Charges\Issuance\IssuanceException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus,['details'=>$e->details]);}
        catch(\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Dimension\DimensionException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
    private function body(Request $request,?array $fields=null):array {$body=$request->getParsedBody();if(!is_array($body))PropertyInput::invalid('body');if($fields!==null)PropertyInput::fields($body,$fields);return $body;}
    private function input(array $body):array {if(!is_array($body['request']??null))PropertyInput::invalid('request');return $body['request'];}
    private function token(array $body):string {return PropertyInput::code($body['idempotency_key']??null,'idempotency_key');}
    private function hash(array $body):string {return PropertyInput::text($body['preview_hash']??null,'preview_hash',64);}
    private function integer(array $data,string $key,?int $default=null):int {$value=$data[$key]??$default;if(is_bool($value)||filter_var($value,FILTER_VALIDATE_INT)===false)PropertyInput::invalid($key);return (int)$value;}
}
