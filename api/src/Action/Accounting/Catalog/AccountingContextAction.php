<?php

declare(strict_types=1);
namespace MyInvoice\Action\Accounting\Catalog;
use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Service\Accounting\AccountDefinitionException;
use MyInvoice\Service\Accounting\Catalog\AccountingContextProjection;
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface as Response};

final class AccountingContextAction
{
    use AccountingActionSupport;
    public function __construct(private readonly AccountingContextProjection $projection) {}

    public function get(Request $request,Response $response): Response
    {
        if(!RequestAuthorization::allows($request,'accounting',AccessLevel::READ))return Json::error($response,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        $query=$request->getQueryParams();
        if(array_key_exists('known_at',$query))return Json::error($response,'accounting_context_known_at_unsupported','Účetní kontext podporuje účinné datum podle současné znalosti, nikoli historický okamžik znalosti.',409);
        if(array_diff(array_keys($query),['date','supplier_id'])!==[]||!is_string($query['date']??null))return Json::error($response,'accounting_context_query_invalid','Je vyžadováno jedno účetní datum ve formátu YYYY-MM-DD.',422);
        try{return Json::ok($response,$this->projection->forDate($this->currentSupplierId($request),$query['date'])->jsonSerialize());}
        catch(AccountDefinitionException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus);}
    }
}
