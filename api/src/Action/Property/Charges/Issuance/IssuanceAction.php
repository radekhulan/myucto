<?php

declare(strict_types=1);

namespace MyInvoice\Action\Property\Charges\Issuance;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceContextService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePublicProjection;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\Charges\ChargeException;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

final class IssuanceAction
{
    use AccountingActionSupport;
    public function __construct(private readonly IssuanceRepository $write,private readonly IssuanceReadRepository $read,
        private readonly IssuancePreviewService $preview,private readonly IssuanceContextService $context) {}

    public function list(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>['items'=>$this->read->charges($supplier),'capabilities'=>[
            'issue'=>$this->can($request,'issue'),'post'=>$this->can($request,'post'),'reverse'=>$this->can($request,'reverse')]]);
    }
    public function detail(Request $request,Response $response,array $args): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>['charge'=>$this->read->find($supplier,$this->id($args['id']??null)),
            'evidence'=>$this->read->evidence($supplier,$this->id($args['id']??null))]);
    }
    public function catalogList(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>[
            'items'=>array_map(IssuancePublicProjection::charge(...),$this->read->charges($supplier))]);
    }
    public function catalogDetail(Request $request,Response $response,array $args): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>
            IssuancePublicProjection::charge($this->read->find($supplier,$this->id($args['id']??null))));
    }
    public function batch(Request $request,Response $response,array $args): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>$this->read->batch($supplier,$this->id($args['id']??null)));
    }
    public function context(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>$this->context->context($supplier,
            $this->id($request->getQueryParams()['version_id']??null),PropertyInstant::normalize($request->getQueryParams()['as_of']??null)));
    }
    public function preview(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property.charges.issue',true,fn($supplier)=>$this->preview->preview($supplier,$this->body($request)));
    }
    public function issue(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property.charges.issue',true,fn($supplier)=>$this->write->issue($supplier,$this->body($request),$this->userId($request)));
    }
    public function post(Request $request,Response $response,array $args): Response
    {
        return $this->run($request,$response,'property.charges.post',true,function($supplier) use($request,$args): array {
            $body=$this->body($request); PropertyInput::fields($body,['expected','debt_registration']);
            return $this->write->post($supplier,$this->id($args['id']??null),PropertyInput::list($body['expected']??null,'expected',1000),$this->userId($request),
                ChargeDebtRegistrar::mode($body['debt_registration']??null));
        });
    }
    public function reverse(Request $request,Response $response,array $args): Response
    {
        return $this->run($request,$response,'property.charges.reverse',true,fn($supplier)=>$this->write->reverse($supplier,$this->id($args['id']??null),$this->body($request),$this->userId($request)));
    }
    public function recordEvidence(Request $request,Response $response,array $args): Response
    {
        return $this->run($request,$response,'property.charges.issue',true,fn($supplier)=>$this->write->recordEvidence($supplier,$this->id($args['id']??null),$this->body($request),$this->userId($request)));
    }
    private function can(Request $request,string $operation): bool
    {
        return RequestAuthorization::isSessionAuth($request)&&RequestAuthorization::allows($request,'property.charges.'.$operation,AccessLevel::WRITE);
    }
    private function run(Request $request,Response $response,string $permission,bool $write,callable $work): Response
    {
        if($write&&!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        if(!RequestAuthorization::allows($request,$permission,$write?AccessLevel::WRITE:AccessLevel::READ)) return Json::error($response,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        try { return Json::ok($response,$work($this->currentSupplierId($request))); }
        catch(IssuanceException|PropertyException|ChargeException $e) { return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus,['details'=>$e->details]); }
        catch(PostingException|DimensionException $e) { return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus); }
    }
    private function body(Request $request): array { $body=$request->getParsedBody(); if(!is_array($body)) PropertyInput::invalid('body'); return $body; }
    private function id(mixed $value): int { if(!is_string($value)||!preg_match('/^[1-9][0-9]{0,14}$/D',$value)) PropertyInput::invalid('id'); return (int)$value; }
}
