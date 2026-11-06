<?php
declare(strict_types=1);
namespace MyInvoice\Action\Property\Funds;
use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Property\Funds\{FundingRead,FundingWorkflow,FundingException};
use MyInvoice\Service\Property\{PropertyInput,PropertyException};
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface as Response};
final class FundingAction
{
    use AccountingActionSupport;
    public function __construct(private readonly FundingRepository $owner,private readonly FundingRead $read,private readonly FundingWorkflow $workflow,private readonly \MyInvoice\Repository\Property\Funds\FundingReadRepository $catalog){}
    private function integer(mixed $id):int {if(is_bool($id)||(!is_int($id)&&(!is_string($id)||!preg_match('/^[1-9][0-9]*$/D',$id))))PropertyInput::invalid('id');if(filter_var($id,FILTER_VALIDATE_INT)===false)PropertyInput::invalid('id');return PropertyInput::id((int)$id,'id');}
    public function page(Request $q,Response $r):Response {return $this->run($q,$r,'property',false,function(int $s)use($q){$a=$q->getQueryParams();PropertyInput::fields($a,['house_id']);return $this->owner->list($s,$this->integer($a['house_id']??null));});}
    public function balances(Request $q,Response $r):Response {return $this->run($q,$r,'property',false,function(int $s)use($q){$a=$q->getQueryParams();PropertyInput::fields($a,['house_id','unit_id','date','known_at']);if(isset($a['known_at'])&&!is_string($a['known_at']))PropertyInput::invalid('known_at');return $this->read->at($s,isset($a['house_id'])?$this->integer($a['house_id']):null,\MyInvoice\Service\Property\Services\Settlement\SettlementEvidence::date($a['date']??null),$a['known_at']??null,isset($a['unit_id'])?$this->integer($a['unit_id']):null);});}
    public function choices(Request $q,Response $r):Response {return $this->run($q,$r,'property',false,function(int $s)use($q){$a=$q->getQueryParams();PropertyInput::fields($a,['date']);return $this->catalog->choices($s,\MyInvoice\Service\Property\Services\Settlement\SettlementEvidence::date($a['date']??null));});}
    public function sources(Request $q,Response $r,array $args):Response {return $this->run($q,$r,'property',false,function(int $s)use($q,$args){$a=$q->getQueryParams();PropertyInput::fields($a,['date','after_id','classification']);if(isset($a['classification'])&&!is_string($a['classification']))PropertyInput::invalid('classification');return $this->catalog->sources($s,$args['kind'],\MyInvoice\Service\Property\Services\Settlement\SettlementEvidence::date($a['date']??null),isset($a['after_id'])?$this->integer($a['after_id']):0,$a['classification']??null);});}
    public function preview(Request $q,Response $r):Response {return $this->command($q,$r,false);}
    public function execute(Request $q,Response $r):Response {return $this->command($q,$r,true);}
    private function command(Request $q,Response $r,bool $execute):Response
    {
        $body=$q->getParsedBody();$kind=is_array($body)&&is_array($body['command']??null)?($body['command']['kind']??''):'';
        return $this->run($q,$r,FundingWorkflow::permission(is_string($kind)?$kind:''),true,function(int $s)use($q,$body,$execute){if(!is_array($body))PropertyInput::invalid('body');PropertyInput::fields($body,$execute?['command','preview_hash','idempotency_key']:['command']);if(!is_array($body['command']??null))PropertyInput::invalid('command');if($execute&&!is_string($body['preview_hash']??null))PropertyInput::invalid('preview_hash');$actor=PropertyInput::id($this->userId($q),'actor');return $execute?$this->workflow->execute($s,$body['command'],$body['preview_hash']??'',PropertyInput::code($body['idempotency_key']??null,'idempotency_key'),$actor):$this->workflow->preview($s,$body['command'],$actor);});
    }
    private function run(Request $q,Response $r,string $permission,bool $write,callable $work):Response
    {
        if(!RequestAuthorization::isSessionAuth($q))return Json::sessionRequired($r);if(!RequestAuthorization::allows($q,$permission,$write?AccessLevel::WRITE:AccessLevel::READ))return Json::error($r,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        try{return Json::ok($r,$work(PropertyInput::id($this->currentSupplierId($q),'supplier_id')));}
        catch(\MyInvoice\Service\Accounting\AccountingFrameworkException|FundingException|PropertyException|\MyInvoice\Service\Property\Opening\OpeningException|\MyInvoice\Service\Property\Payments\PaymentException|\MyInvoice\Service\Property\Charges\Issuance\IssuanceException|\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Dimension\DimensionException $e){return Json::error($r,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
}
