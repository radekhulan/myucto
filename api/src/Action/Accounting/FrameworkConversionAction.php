<?php

declare(strict_types=1);
namespace MyInvoice\Action\Accounting;
use MyInvoice\Http\Json;
use MyInvoice\Repository\{ClosingRepository,FrameworkConversionRepository as R,JournalEntryRepository};
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Accounting\Conversion\{FrameworkConversionWorkflow,FrameworkConversionResultDecision,FrameworkConversionException as E};
use MyInvoice\Service\Property\PropertyInput;
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface as Response};
final class FrameworkConversionAction
{
    use AccountingActionSupport;
    public function __construct(private readonly R $read,private readonly FrameworkConversionWorkflow $workflow,private readonly FrameworkConversionResultDecision $decisions){}
    public static function integrated():bool
    {return defined(JournalEntryRepository::class.'::FRAMEWORK_CONVERSION_OWNER_VERSION')&&JournalEntryRepository::FRAMEWORK_CONVERSION_OWNER_VERSION===1&&defined(ClosingRepository::class.'::FRAMEWORK_CONVERSION_CURRENT_READ_VERSION')&&ClosingRepository::FRAMEWORK_CONVERSION_CURRENT_READ_VERSION===1&&method_exists(JournalEntryRepository::class,'cancelFrameworkConversion');}
    public function page(Request $q,Response $r):Response
    {return $this->handle($q,$r,false,fn(int $supplier)=>['periods'=>$this->read->periods($supplier),'rows'=>$this->read->rows('SELECT id,source_period_id,target_period_id,effective_from,status,opening_entry_id,recorded_at FROM accounting_framework_conversions WHERE supplier_id=? ORDER BY id DESC',[$supplier]),'financial_enabled'=>self::integrated(),'can_write'=>RequestAuthorization::allows($q,'accounting.journal.post',AccessLevel::WRITE)]);}
    public function choices(Request $q,Response $r):Response
    {return $this->handle($q,$r,false,function(int $supplier)use($q){$b=$q->getQueryParams();PropertyInput::fields($b,['source_period_id','target_period_id']);return $this->workflow->choices($supplier,$this->id($b['source_period_id']??null),$this->id($b['target_period_id']??null));});}
    public function preview(Request $q,Response $r):Response{return $this->command($q,$r,false);}
    public function confirm(Request $q,Response $r):Response{return $this->command($q,$r,true);}
    private function command(Request $q,Response $r,bool $confirm):Response
    {
        return $this->handle($q,$r,true,function(int $supplier)use($q,$confirm){$b=$this->body($q,$confirm?['command','state_hash','preview_hash','command_token']:['command']);E::need(is_array($b['command']??null),'conversion_command_invalid',422);$actor=PropertyInput::id($this->userId($q),'actor');if(!$confirm)return $this->workflow->preview($supplier,$b['command'],$actor);foreach(['state_hash','preview_hash','command_token'] as $key)E::need(is_string($b[$key]??null),'conversion_command_invalid',422);return $this->workflow->confirm($supplier,$b['command'],$actor,$b['state_hash'],$b['preview_hash'],$b['command_token']);});
    }
    public function cancellationPreview(Request $q,Response $r,array $args):Response
    {return $this->handle($q,$r,true,function(int $supplier)use($q,$args){$this->body($q,[]);return $this->workflow->cancellationPreview($supplier,$this->id($args['id']),$this->userId($q));});}
    public function cancel(Request $q,Response $r,array $args):Response
    {return $this->handle($q,$r,true,function(int $supplier)use($q,$args){$b=$this->body($q,['receipt_hash','preview_hash','command_token']);foreach(['receipt_hash','preview_hash','command_token'] as $key)E::need(is_string($b[$key]??null),'conversion_command_invalid',422);return $this->workflow->cancel($supplier,$this->id($args['id']),$this->userId($q),$b['receipt_hash'],$b['preview_hash'],$b['command_token']);});}
    public function decisionChoices(Request $q,Response $r,array $args):Response
    {return $this->handle($q,$r,false,function(int $supplier)use($q,$args){$b=$q->getQueryParams();PropertyInput::fields($b,['date']);E::need(is_string($b['date']??null),'conversion_command_invalid',422);return $this->decisions->choices($supplier,$this->id($args['id']),$b['date']);});}
    public function decisionPreview(Request $q,Response $r,array $args):Response{return $this->decision($q,$r,$args,false);}
    public function decisionConfirm(Request $q,Response $r,array $args):Response{return $this->decision($q,$r,$args,true);}
    private function decision(Request $q,Response $r,array $args,bool $confirm):Response
    {return $this->handle($q,$r,true,function(int $supplier)use($q,$args,$confirm){$b=$this->body($q,$confirm?['command','state_hash','preview_hash','command_token']:['command']);E::need(is_array($b['command']??null),'conversion_command_invalid',422);$id=$this->id($args['id']);$actor=$this->userId($q);if(!$confirm)return $this->decisions->preview($supplier,$id,$b['command'],$actor);foreach(['state_hash','preview_hash','command_token'] as $key)E::need(is_string($b[$key]??null),'conversion_command_invalid',422);return $this->decisions->confirm($supplier,$id,$b['command'],$actor,$b['state_hash'],$b['preview_hash'],$b['command_token']);});}
    private function id(mixed $value):int
    {E::need(is_int($value)||(is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value)===1&&filter_var($value,FILTER_VALIDATE_INT)!==false),'conversion_id_invalid',422);return PropertyInput::id((int)$value,'id');}
    private function body(Request $q,array $fields):array
    {$b=$q->getParsedBody();E::need(is_array($b),'conversion_command_invalid',422);PropertyInput::fields($b,$fields);return $b;}
    private function handle(Request $q,Response $r,bool $write,callable $work):Response
    {
        if(!RequestAuthorization::isSessionAuth($q))return Json::sessionRequired($r);
        if(!$this->requirePermission($q,$r,$write?'accounting.journal.post':'accounting',$write?AccessLevel::WRITE:AccessLevel::READ,$error))return $error;
        try{if($write)E::need(self::integrated(),'conversion_integration_required');return Json::ok($r,$work($this->currentSupplierId($q)));}
        catch(E|\MyInvoice\Service\Accounting\AccountDefinitionException|\MyInvoice\Service\Accounting\AccountingFrameworkException|\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Closing\ClosingException|\MyInvoice\Service\Accounting\Dimension\DimensionException|\MyInvoice\Service\Property\PropertyException $e){return Json::error($r,$e->errorCode,'Převod vyžaduje úplný, aktuální a výslovně potvrzený podklad.',$e->httpStatus);}
    }
}
