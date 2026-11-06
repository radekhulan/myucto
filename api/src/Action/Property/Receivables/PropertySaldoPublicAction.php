<?php
declare(strict_types=1);
namespace MyInvoice\Action\Property\Receivables;
use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Receivables\PropertySaldoReadRepository;
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Property\{PropertyException,PropertyInput,PropertyInstant};
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
final class PropertySaldoPublicAction
{
    use AccountingActionSupport;
    public function __construct(private readonly PropertySaldoReadRepository $read){}
    public function __invoke(Request $request,Response $response):Response
    {
        if(!RequestAuthorization::allows($request,'property',AccessLevel::READ))return Json::error($response,'forbidden','Pro tuto operaci nemáte oprávnění.',403);
        try{
            $q=$request->getQueryParams();PropertyInput::fields($q,['as_of','known_at','cursor','limit','supplier_id']);
            $asOf=PropertyInstant::normalize($q['as_of']??null);$knownAt=PropertyInstant::normalize($q['known_at']??null);
            $limit=array_key_exists('limit',$q)?$q['limit']:25;
            if(is_bool($limit)||!is_scalar($limit)||filter_var($limit,FILTER_VALIDATE_INT)===false||(int)$limit<1||(int)$limit>100)PropertyInput::invalid('limit');
            if(array_key_exists('cursor',$q)&&!is_string($q['cursor']))PropertyInput::invalid('cursor');
            return Json::ok($response,$this->read->page($this->currentSupplierId($request),$asOf,$knownAt,(int)$limit,$q['cursor']??null));
        }catch(PropertyException|\MyInvoice\Service\Property\Opening\OpeningException|\MyInvoice\Service\Property\Charges\Issuance\IssuanceException|\MyInvoice\Service\Property\Payments\PaymentException $e){return Json::error($response,$e->errorCode,$e->getMessage(),$e->httpStatus,['details'=>$e->details]);}
    }
}
