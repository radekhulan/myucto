<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\SecuritiesDebt504Repository;
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Accounting\Closing\{ClosingException,SecuritiesDebt504Terms,SecuritiesDebt504UseGuard,SecuritiesDebt504Workflow};
use MyInvoice\Service\Property\PropertyInput;
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface as Response};

final class SecuritiesDebt504Action
{
    use AccountingActionSupport;
    public function __construct(private readonly SecuritiesDebt504Repository $lots,private readonly SecuritiesDebt504Workflow $workflow) {}
    public function page(Request $q,Response $r): Response
    {
        return $this->handle($q,$r,false,function(int $supplier)use($q){$query=$q->getQueryParams();PropertyInput::fields($query,['after_id']);return ['rows'=>$this->lots->page($supplier,$this->integer($query['after_id']??0)),'financial_enabled'=>SecuritiesDebt504UseGuard::integrated(),'framework_supported'=>$this->lots->frameworkSupported($supplier,gmdate('Y-m-d')),'can_write'=>RequestAuthorization::allows($q,'accounting.journal.post',AccessLevel::WRITE)];});
    }
    public function detail(Request $q,Response $r,array $args): Response
    {
        return $this->handle($q,$r,false,function(int $supplier)use($q,$args){$query=$q->getQueryParams();PropertyInput::fields($query,['date']);SecuritiesDebt504Terms::need(is_string($query['date']??null),'debt_date_invalid');return $this->workflow->detail($supplier,PropertyInput::id($this->integer($args['id']),'id'),$query['date']);});
    }
    public function sources(Request $q,Response $r,array $args): Response
    {
        return $this->handle($q,$r,false,function(int $supplier)use($q,$args){$query=$q->getQueryParams();PropertyInput::fields($query,['date','after_entry_id','after_line_id']);SecuritiesDebt504Terms::need(is_string($query['date']??null),'debt_date_invalid');$found=$this->workflow->sources($supplier,$query['date'],$args['kind'],$this->integer($query['after_entry_id']??0),$this->integer($query['after_line_id']??0));$rows=array_slice($found,0,100);$last=$rows===[]?null:$rows[array_key_last($rows)];return ['rows'=>$rows,'next_cursor'=>count($found)>100?['entry_id'=>(int)$last['id'],'line_id'=>(int)$last['line_id']]:null];});
    }
    public function profileChoices(Request $q,Response $r): Response
    {
        return $this->handle($q,$r,false,function(int $supplier)use($q){$query=$q->getQueryParams();PropertyInput::fields($query,['date']);SecuritiesDebt504Terms::need(is_string($query['date']??null),'debt_date_invalid');return $this->workflow->profileChoices($supplier,$query['date']);});
    }
    public function preview(Request $q,Response $r): Response{return $this->command($q,$r,false);}
    public function execute(Request $q,Response $r): Response{return $this->command($q,$r,true);}
    private function command(Request $q,Response $r,bool $execute): Response
    {
        return $this->handle($q,$r,true,function(int $supplier)use($q,$execute){
            SecuritiesDebt504Terms::need(SecuritiesDebt504UseGuard::integrated(),'debt_mutation_guard_unavailable');
            $b=$q->getParsedBody();SecuritiesDebt504Terms::need(is_array($b),'debt_command_invalid');PropertyInput::fields($b,$execute?['command','state_hash','preview_hash','idempotency_key']:['command']);
            SecuritiesDebt504Terms::need(is_array($b['command']??null),'debt_command_invalid');$actor=PropertyInput::id($this->userId($q),'actor');
            if(!$execute)return $this->workflow->preview($supplier,$b['command'],$actor);
            foreach(['state_hash','preview_hash','idempotency_key'] as $key)SecuritiesDebt504Terms::need(is_string($b[$key]??null),'debt_command_invalid');
            return $this->workflow->execute($supplier,$b['command'],$actor,$b['state_hash'],$b['preview_hash'],$b['idempotency_key']);
        });
    }
    private function integer(mixed $value): int
    {
        SecuritiesDebt504Terms::need(is_int($value)||(is_string($value)&&preg_match('/^(0|[1-9][0-9]*)$/D',$value)===1 && filter_var($value,FILTER_VALIDATE_INT)!==false),'debt_command_invalid');return(int)$value;
    }
    private function handle(Request $q,Response $r,bool $write,callable $work): Response
    {
        if(!RequestAuthorization::isSessionAuth($q))return Json::sessionRequired($r);
        if(!$this->requirePermission($q,$r,$write?'accounting.journal.post':'accounting',$write?AccessLevel::WRITE:AccessLevel::READ,$error))return $error;
        try{return Json::ok($r,$work($this->currentSupplierId($q)));}
        catch(ClosingException|\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Dimension\DimensionException|\MyInvoice\Service\Property\PropertyException|\MyInvoice\Service\Property\Payments\PaymentException $e){return Json::error($r,$e->errorCode,$e->getMessage(),$e->httpStatus);}
        catch(\DomainException $e){if(!str_starts_with($e->getMessage(),'debt_')&&!str_starts_with($e->getMessage(),'securities_'))throw $e;return Json::error($r,$e->getMessage(),'Podklady cenného papíru nejsou úplné nebo aktuální.',409);}
    }
}
