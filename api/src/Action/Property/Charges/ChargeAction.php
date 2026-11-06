<?php

declare(strict_types=1);

namespace MyInvoice\Action\Property\Charges;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\ChargeReadRepository;
use MyInvoice\Service\Property\Charges\ChargeException;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Service\Property\Charges\ChargeRegistrySnapshot;
use MyInvoice\Service\Property\Charges\ChargePublicProjection;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

final class ChargeAction
{
    use AccountingActionSupport;
    public function __construct(private readonly ChargeRepository $write,private readonly ChargeReadRepository $read,
        private readonly ChargePreviewService $preview,private readonly ChargeRegistrySnapshot $registry) {}
    public function components(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>['items'=>$this->read->components($supplier),
            'capabilities'=>['manage'=>RequestAuthorization::isSessionAuth($request)&&RequestAuthorization::allows($request,'property.charges.manage',AccessLevel::WRITE),
            'confirm'=>RequestAuthorization::isSessionAuth($request)&&RequestAuthorization::allows($request,'property.charges.confirm',AccessLevel::WRITE)]]);
    }
    public function createComponent(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property.charges.manage',true,fn($supplier)=>$this->write->createComponent($supplier,$this->body($request),$this->userId($request)));
    }
    public function catalogComponents(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>[
            'items'=>array_map(ChargePublicProjection::component(...),$this->read->components($supplier))]);
    }
    public function catalogVersions(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property',false,function($supplier) use($request): array {
            $q=$request->getQueryParams();$house=$this->queryId($q['house_id']??null);
            $at=PropertyInstant::normalize($q['as_of']??null);$known=isset($q['known_at'])?PropertyInstant::normalize($q['known_at']):null;
            $selected=$this->read->at($supplier,$house,$at,$known);
            return ['as_of'=>$at,'known_at'=>$known,
                'items'=>array_map(ChargePublicProjection::version(...),$this->read->versions($supplier,$house)),
                'selected'=>$selected===null?null:ChargePublicProjection::version($selected)];
        });
    }
    public function catalogDetail(Request $request,Response $response,array $args): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>
            ChargePublicProjection::version($this->read->find($supplier,$this->queryId($args['id']??null))));
    }
    public function versions(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property',false,function($supplier) use($request): array {
            $q=$request->getQueryParams(); $house=$this->queryId($q['house_id']??null);
            $at=PropertyInstant::normalize($q['as_of']??null);$known=isset($q['known_at'])?PropertyInstant::normalize($q['known_at']):null;
            return ['items'=>$this->read->versions($supplier,$house),'selected'=>$this->read->at($supplier,$house,$at,$known),'as_of'=>$at,'known_at'=>$known];
        });
    }
    public function detail(Request $request,Response $response,array $args): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>$this->read->find($supplier,$this->queryId($args['id']??null)));
    }
    public function context(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property',false,fn($supplier)=>$this->registry->capture($supplier,$this->queryId($request->getQueryParams()['house_id']??null),PropertyInstant::normalize($request->getQueryParams()['as_of']??null)));
    }
    public function preview(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property.charges.manage',true,fn($supplier)=>$this->preview->preview($supplier,$this->body($request)));
    }
    public function saveVersion(Request $request,Response $response): Response
    {
        return $this->run($request,$response,'property.charges.confirm',true,fn($supplier)=>$this->write->saveVersion($supplier,$this->body($request),$this->userId($request)));
    }
    private function run(Request $request,Response $response,string $permission,bool $write,callable $work): Response
    {
        if($write&&!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        if(!RequestAuthorization::allows($request,$permission,$write?AccessLevel::WRITE:AccessLevel::READ)) return Json::error($response,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        try { return Json::ok($response,$work($this->currentSupplierId($request))); }
        catch(ChargeException|PropertyException $e) { return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus,['details'=>$e->details]); }
    }
    private function body(Request $request): array
    {
        $body=$request->getParsedBody(); if(!is_array($body)) PropertyInput::invalid('body'); return $body;
    }
    private function queryId(mixed $id): int
    {
        if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,14}$/D',$id)) PropertyInput::invalid('id'); return (int)$id;
    }
}
