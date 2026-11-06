<?php

declare(strict_types=1);

namespace MyInvoice\Action\Property\Services\Settlement;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Services\Settlement\{ServiceLifecycleReadRepository,ServiceLifecyclePolicyRepository};
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Property\{PropertyInput,PropertyException};
use MyInvoice\Service\Property\Services\Settlement\{ServiceLifecycleWorkflow,SettlementEvidence};
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface as Response};

final class ServiceLifecycleAction
{
    use AccountingActionSupport;
    public function __construct(private readonly ServiceLifecycleReadRepository $read,private readonly ServiceLifecycleWorkflow $workflow,private readonly ServiceLifecyclePolicyRepository $policies) {}
    public function page(Request $q,Response $r): Response
    {
        return $this->run($q,$r,'property',false,function(int $supplier)use($q){$a=$this->query($q,['after_id']);return $this->read->page($supplier,$this->integer($a['after_id']??0))+['capabilities'=>$this->capabilities($q)];});
    }
    public function detail(Request $q,Response $r,array $args): Response
    {
        return $this->run($q,$r,'property',false,function(int $supplier)use($q,$args){$this->query($q,[]);return $this->read->detail($supplier,PropertyInput::id($this->integer($args['id']),'id'))+['capabilities'=>$this->capabilities($q)];});
    }
    public function choices(Request $q,Response $r): Response
    {
        return $this->run($q,$r,'property',false,function(int $supplier)use($q){$a=$this->query($q,['date']);return $this->read->choices($supplier,SettlementEvidence::date($a['date']??null));});
    }
    public function sources(Request $q,Response $r,array $args): Response
    {
        return $this->run($q,$r,'property',false,function(int $supplier)use($q,$args){$a=$this->query($q,['unit_id','service_id','from','to','after_id']);return $this->read->sources($supplier,$args['kind'],$this->integer($a['unit_id']??null),$this->integer($a['service_id']??null),SettlementEvidence::date($a['from']??null),SettlementEvidence::date($a['to']??null),$this->integer($a['after_id']??0));});
    }
    public function policies(Request $q,Response $r): Response
    {
        return $this->run($q,$r,'property',false,function(int $supplier)use($q){
            $a=$this->query($q,['service_id','after_id']);
            $service=isset($a['service_id'])?PropertyInput::id($this->integer($a['service_id']),'service_id'):null;
            return $this->policies->list($supplier,$service,$this->integer($a['after_id']??0))+['capabilities'=>$this->capabilities($q)];
        });
    }
    public function declarePolicy(Request $q,Response $r): Response
    {
        return $this->run($q,$r,'property.settlements.manage',true,function(int $supplier)use($q){
            $b=$q->getParsedBody();if(!is_array($b))PropertyInput::invalid('body');
            SettlementEvidence::fields($b,['service_id','valid_from','policy','evidence']);
            return $this->policies->declare($supplier,PropertyInput::id($b['service_id'],'service_id'),SettlementEvidence::date($b['valid_from']),is_string($b['policy'])?$b['policy']:PropertyInput::invalid('policy'),is_string($b['evidence'])?$b['evidence']:PropertyInput::invalid('evidence'),PropertyInput::id($this->userId($q),'actor'));
        });
    }
    public function preview(Request $q,Response $r,array $args): Response {return $this->command($q,$r,$args,false);}
    public function execute(Request $q,Response $r,array $args): Response {return $this->command($q,$r,$args,true);}
    private function command(Request $q,Response $r,array $args,bool $execute): Response
    {
        $op=$args['operation'];
        return $this->run($q,$r,ServiceLifecycleWorkflow::permission($op),true,function(int $supplier)use($q,$op,$execute){
            $b=$q->getParsedBody();if(!is_array($b))PropertyInput::invalid('body');
            SettlementEvidence::fields($b,$execute?['command','expected_state','preview_hash','idempotency_key']:['command']);
            if(!is_array($b['command'])||($b['command']['operation']??null)!==$op)PropertyInput::invalid('operation');
            $actor=PropertyInput::id($this->userId($q),'actor');
            return $execute?$this->workflow->execute($supplier,$b['command'],$actor,SettlementEvidence::hash($b['expected_state']),SettlementEvidence::hash($b['preview_hash']),PropertyInput::code($b['idempotency_key'],'idempotency_key')):$this->workflow->preview($supplier,$b['command'],$actor);
        });
    }
    private function capabilities(Request $q): array
    {
        $out=[];foreach(['manage','post','reverse','unwind'] as $cap)$out[$cap]=RequestAuthorization::allows($q,'property.settlements.'.$cap,AccessLevel::WRITE);return $out;
    }
    private function query(Request $q,array $fields): array
    {
        $args=$q->getQueryParams();PropertyInput::fields($args,$fields);return $args;
    }
    private function integer(mixed $value): int
    {
        if(is_bool($value)||(!is_int($value)&&(!is_string($value)||!preg_match('/^(0|[1-9][0-9]*)$/D',$value)))||filter_var($value,FILTER_VALIDATE_INT)===false)PropertyInput::invalid('integer');
        return (int)$value;
    }
    private function run(Request $q,Response $r,string $permission,bool $write,callable $work): Response
    {
        if(!RequestAuthorization::isSessionAuth($q))return Json::sessionRequired($r);
        if(!RequestAuthorization::allows($q,$permission,$write?AccessLevel::WRITE:AccessLevel::READ))return Json::error($r,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        try{return Json::ok($r,$work(PropertyInput::id($this->currentSupplierId($q),'supplier_id')));}
        catch(PropertyException|\MyInvoice\Service\Property\Opening\OpeningException|\MyInvoice\Service\Property\Payments\PaymentException|\MyInvoice\Service\Property\Charges\Issuance\IssuanceException|\MyInvoice\Service\Property\Receivables\ReceivableException $e){return Json::error($r,$e->errorCode,$e->getMessage(),$e->httpStatus);}
        catch(\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Dimension\DimensionException $e){return Json::error($r,$e->errorCode,$e->getMessage(),$e->httpStatus);}
        catch(\DomainException $e){if(!str_starts_with($e->getMessage(),'settlement_'))throw $e;return Json::error($r,$e->getMessage(),'Podklady nejsou aktuální nebo doložené.',409);}
    }
}
