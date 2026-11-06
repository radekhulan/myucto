<?php

declare(strict_types=1);
namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Http\SessionOnlyAccountingAct;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Tax\Nonprofit\{NonprofitDppoAcceptanceService,NonprofitLegalProfileService,NonprofitTaxSavingsService,NonprofitDppoDataProvider,NonprofitTaxAssessmentException};
use MyInvoice\Service\Tax\Return\{AccountingDecreeService,Dppo504CapabilityService,TaxReturnException};
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NonprofitDppoAction
{
    use AccountingActionSupport;
    public function __construct(private readonly NonprofitLegalProfileService $profiles,private readonly NonprofitTaxSavingsService $savings,
        private readonly NonprofitDppoDataProvider $provider,private readonly Connection $db,
        private readonly NonprofitDppoAcceptanceService $acceptances) {}

    /**
     * GET profilu je levné čtení bez zamykání období: nesestavuje podklady DPPO.
     * Připravenost výpočtu ověřuje samostatný požadavek readiness, který podklady
     * sestaví pod zámky jen na výslovnou žádost uživatele.
     */
    public function profile(Request $q,Response $r,array $args):Response
    {
        return$this->handle($q,$r,false,function(int$s)use($q,$args):array{
            $result=$this->profiles->read($s,$this->id($args,'id'));
            return$result+['capabilities'=>$this->profileCapabilities($q,$s,$result['period'],false)];
        });
    }
    public function readiness(Request $q,Response $r,array $args):Response
    {
        return$this->handle($q,$r,false,function(int$s)use($q,$args):array{
            $result=$this->profiles->read($s,$this->id($args,'id'));
            return['period'=>$result['period'],'capabilities'=>$this->profileCapabilities($q,$s,$result['period'],true)];
        });
    }
    public function acceptance(Request $q,Response $r,array $args=[]):Response
    {
        return$this->handle($q,$r,false,fn(int$s):array=>$this->acceptanceView($q,$s));
    }
    public function changeAcceptance(Request $q,Response $r,array $args):Response
    {
        return$this->handle($q,$r,true,function(int$s)use($q,$args):array{
            if(!RequestAuthorization::canAcceptNonprofitDppoCalculation($q))throw new TaxReturnException('nonprofit_dppo_acceptance_forbidden','Akceptaci výpočtu DPPO 504 smí měnit jen správce firmy.',403);
            $b=$this->body($q);$this->fields($b,['confirmed','verification_reference','reason','idempotency_key','expected_head_id']);
            $operation=(string)($args['operation']??'');if(!in_array($operation,[NonprofitDppoAcceptanceService::ACCEPT,NonprofitDppoAcceptanceService::REVOKE],true))$this->invalid();
            $expected=$b['expected_head_id']??null;if($expected!==null&&(!is_int($expected)||$expected<=0))$this->invalid();
            if(array_key_exists('confirmed',$b)&&!is_bool($b['confirmed']))$this->invalid();
            $reference=$b['verification_reference']??'';if(!is_string($reference))$this->invalid();
            $this->acceptances->change($s,$operation,($b['confirmed']??false)===true,$reference,$this->text($b,'reason'),$this->text($b,'idempotency_key'),$expected,$this->userId($q)??0);
            return$this->acceptanceView($q,$s);
        });
    }
    private function acceptanceView(Request $q,int $s):array
    {
        return$this->acceptances->state($s)+['history'=>$this->acceptances->history($s,20),
            'can_change'=>RequestAuthorization::isSessionAuth($q)&&RequestAuthorization::canAcceptNonprofitDppoCalculation($q)];
    }
    private function profileCapabilities(Request $q,int $s,array $period,bool $gather):array
    {
        $accepted=$this->acceptances->isAccepted($s);
        $operations=(new Dppo504CapabilityService())->capabilities($period['starts_on'],$period['ends_on'],'A',$accepted);
        $manifest=$operations['manifest'];unset($operations['manifest']);
        $caps=$this->capabilities($q)+['native_dppo'=>false,'calculation_ready'=>$gather?false:null,'readiness_checked'=>$gather,'filing_ready'=>false,
            'calculation_accepted'=>$accepted,'operations'=>$operations,'form_manifest'=>$manifest,'preparation_findings'=>[]];
        try{
            if((new AccountingDecreeService($this->db))->assertDppoSupported($s,(int)$period['fiscal_year'])!=='504'){
                foreach($caps['operations']as&$operation)$operation=['allowed'=>false,'code'=>'nonprofit_legal_profile_framework_required','message'=>'DPPO 504 vyžaduje stejný účetní rámec v celém období.'];unset($operation);
                throw new TaxReturnException('nonprofit_legal_profile_framework_required','DPPO 504 vyžaduje stejný účetní rámec v celém období.',422);
            }
            $caps['native_dppo']=$operations['compute']['allowed'];
            if(!$gather)return$caps;
            $this->provider->gather($s,(int)$period['fiscal_year']);
            $caps['calculation_ready']=true;$caps['filing_ready']=$operations['finalize']['allowed'];
            if(!$caps['filing_ready'])$caps['preparation_findings'][]=['code'=>$operations['finalize']['code'],'message'=>$operations['finalize']['message']];
        }
        catch(TaxReturnException|NonprofitTaxAssessmentException $e){$caps['preparation_findings'][]=['code'=>$e->errorCode,'message'=>$e->getMessage()];}
        return$caps;
    }
    public function confirmProfile(Request $q,Response $r,array $args):Response
    {return$this->handle($q,$r,true,fn(int$s):array=>$this->profiles->confirm($s,$this->id($args,'id'),$this->body($q),$this->userId($q)??0));}
    public function savings(Request $q,Response $r,array $args=[]):Response
    {return$this->handle($q,$r,false,fn(int$s):array=>$this->savings->context($s)+['capabilities'=>$this->capabilities($q)]);}
    public function previewUse(Request $q,Response $r,array $args):Response
    {return$this->handle($q,$r,false,fn(int$s):array=>$this->savings->previewUse($s,$this->id($args,'id'),$this->body($q)));}
    public function confirmUse(Request $q,Response $r,array $args):Response
    {
        return$this->handle($q,$r,true,function(int$s)use($q,$args):array{
            $b=$this->body($q);$this->fields($b,['input','preview_hash','expected_head_hash','idempotency_key']);if(!is_array($b['input']??null))$this->invalid();
            return$this->savings->confirmUse($s,$this->id($args,'id'),$b['input'],$this->text($b,'preview_hash'),$this->text($b,'expected_head_hash'),$this->text($b,'idempotency_key'),$this->userId($q)??0);
        });
    }
    public function previewReverse(Request $q,Response $r,array $args):Response
    {return$this->handle($q,$r,false,function(int$s)use($q,$args):array{$b=$this->body($q);$this->fields($b,['reason']);return$this->savings->previewReverse($s,$this->id($args,'id'),$this->id($args,'event'),$this->text($b,'reason'));});}
    public function confirmReverse(Request $q,Response $r,array $args):Response
    {
        return$this->handle($q,$r,true,function(int$s)use($q,$args):array{
            $b=$this->body($q);$this->fields($b,['reason','preview_hash','expected_head_hash','idempotency_key']);return$this->savings->confirmReverse($s,$this->id($args,'id'),$this->id($args,'event'),$this->text($b,'reason'),$this->text($b,'preview_hash'),$this->text($b,'expected_head_hash'),$this->text($b,'idempotency_key'),$this->userId($q)??0);
        });
    }
    private function capabilities(Request $q):array
    {return['can_confirm'=>RequestAuthorization::isSessionAuth($q)&&RequestAuthorization::allows($q,'accounting.periods.close',AccessLevel::WRITE),'session_only'=>true,'can_edit_reduction'=>RequestAuthorization::isSessionAuth($q)&&RequestAuthorization::allows($q,'reports',AccessLevel::WRITE)];}
    private function handle(Request $q,Response $r,bool $write,callable $work):Response
    {
        if(!$write&&!RequestAuthorization::isSessionAuth($q))return Json::error($r,'token_endpoint_forbidden','API token má přístup pouze k veřejnému API (/api/v1). Tento endpoint je dostupný jen z webového rozhraní.',403);
        if($write&&($deny=SessionOnlyAccountingAct::deny($q,$r,'Potvrzení právního profilu nebo použití daňové úspory'))!==null)return$deny;
        $allowed=$write?$this->requireClose($q,$r,$error):$this->requirePermission($q,$r,'accounting',AccessLevel::READ,$error);if(!$allowed)return$error;
        try{return Json::ok($r,$work($this->currentSupplierId($q)));}catch(TaxReturnException|NonprofitTaxAssessmentException|PostingException $e){return Json::error($r,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
    private function body(Request $q):array
    {$b=$q->getParsedBody();if(!is_array($b))$this->invalid();return$b;}
    private function fields(array $b,array $allowed):void
    {if(array_diff(array_keys($b),$allowed)!==[])$this->invalid();}
    private function text(array $b,string $key):string
    {if(!is_string($b[$key]??null))$this->invalid();return$b[$key];}
    private function id(array $a,string $key):int
    {$v=$a[$key]??null;if((!is_int($v)&&!is_string($v))||preg_match('/^[1-9][0-9]{0,17}$/D',(string)$v)!==1)$this->invalid();return(int)$v;}
    private function invalid():never
    {throw new TaxReturnException('nonprofit_dppo_request_invalid','Požadavek musí obsahovat platné strukturované podklady a identifikátory.',422);}
}
