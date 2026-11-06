<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Funds;
use MyInvoice\Repository\Property\Funds\FundingRepository;
final class FundingWorkflow
{
    public function __construct(private readonly FundingRepository $owner){}
    public static function permission(string $kind):string {return $kind==='reverse'?'property.funds.reverse':'property.funds.post';}
    public function preview(int $supplier,array $command,int $actor):array {return $this->owner->preview($supplier,$command,$actor);}
    public function execute(int $supplier,array $command,string $hash,string $token,int $actor):array {if(!preg_match('/^[a-f0-9]{64}$/D',$hash))\MyInvoice\Service\Property\PropertyInput::invalid('preview_hash');return $this->owner->apply($supplier,$command,$hash,$token,$actor);}
}
