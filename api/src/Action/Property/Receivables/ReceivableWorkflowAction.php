<?php
declare(strict_types=1);
namespace MyInvoice\Action\Property\Receivables;
use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Receivables\{PartyEventRepository,DebtCertificateRepository,ReceivableWorkflowReadRepository};
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Property\{PropertyInput,PropertyException};
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
final class ReceivableWorkflowAction
{
    use AccountingActionSupport;
    public function __construct(private readonly PartyEventRepository $parties,private readonly DebtCertificateRepository $certificates,private readonly ReceivableWorkflowReadRepository $read) {}
    public function context(Request $r,Response $s):Response
    {
        return $this->run($r,$s,'property',false,function(int $supplier)use($r):array{
            $q=$r->getQueryParams();PropertyInput::fields($q,['unit_id','as_of','known_at']);
            $result=$this->read->context($supplier,$this->id($q['unit_id']??null),PropertyInput::text($q['as_of']??null,'as_of',100),PropertyInput::text($q['known_at']??null,'known_at',100));
            $result['capabilities']=[];foreach(['manage','confirm','certificates'] as $key)$result['capabilities'][$key]=RequestAuthorization::isSessionAuth($r)&&RequestAuthorization::allows($r,'property.receivables.'.$key,AccessLevel::WRITE);
            return $result;
        });
    }
    public function transferPreview(Request $r,Response $s):Response{return $this->run($r,$s,'property.receivables.manage',true,fn(int $supplier)=>$this->parties->preview($supplier,$this->transferInput($this->body($r))));}
    public function transferConfirm(Request $r,Response $s):Response{return $this->run($r,$s,'property.receivables.confirm',true,function(int $supplier)use($r){[$input,$hash,$token]=$this->confirmation($r);return $this->parties->confirm($supplier,$this->transferInput($input),$hash,$token,$this->userId($r));});}
    public function certificatePreview(Request $r,Response $s):Response{return $this->run($r,$s,'property.receivables.certificates',true,fn(int $supplier)=>$this->certificates->preview($supplier,$this->body($r)));}
    public function certificateIssue(Request $r,Response $s):Response{return $this->run($r,$s,'property.receivables.certificates',true,function(int $supplier)use($r){[$input,$hash,$token]=$this->confirmation($r);return $this->certificates->issue($supplier,$input,$hash,$token,$this->userId($r));});}
    public function certificate(Request $r,Response $s,array $args):Response{return $this->run($r,$s,'property',false,fn(int $supplier)=>$this->certificates->find($supplier,$this->id($args['id']??null)));}
    private function run(Request $r,Response $s,string $permission,bool $write,callable $work):Response
    {
        if($write&&!RequestAuthorization::isSessionAuth($r))return Json::sessionRequired($s);
        if(!RequestAuthorization::allows($r,'property',AccessLevel::READ)||!RequestAuthorization::allows($r,$permission,$write?AccessLevel::WRITE:AccessLevel::READ))return Json::error($s,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        try{return Json::ok($s,$work($this->currentSupplierId($r)));}
        catch(PropertyException|\MyInvoice\Service\Property\Payments\PaymentException|\MyInvoice\Service\Property\Charges\Issuance\IssuanceException $e){return Json::error($s,$e->errorCode,$e->getMessage(),$e->httpStatus,['details'=>$e->details]);}
        catch(\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Dimension\DimensionException $e){return Json::error($s,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
    private function transferInput(array $input):array
    {
        foreach(PropertyInput::list($input['debts']??null,'debts') as $debt){
            if(!is_array($debt))PropertyInput::invalid('debt');
            foreach(PropertyInput::list($debt['groups']??null,'groups') as $group){
                if(!is_array($group))PropertyInput::invalid('group');
                PropertyInput::list($group['members']??null,'members');
            }
        }
        return $input;
    }
    private function body(Request $r):array{$b=$r->getParsedBody();if(!is_array($b))PropertyInput::invalid('body');return $b;}
    private function confirmation(Request $r):array{$b=$this->body($r);PropertyInput::fields($b,['request','preview_hash','idempotency_key']);if(!is_array($b['request']??null))PropertyInput::invalid('request');return [$b['request'],PropertyInput::text($b['preview_hash']??null,'preview_hash',64),PropertyInput::code($b['idempotency_key']??null,'idempotency_key')];}
    private function id(mixed $v):int{if(is_bool($v)||!is_scalar($v)||filter_var($v,FILTER_VALIDATE_INT)===false)PropertyInput::invalid('id');return PropertyInput::id((int)$v,'id');}
}
