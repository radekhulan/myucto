<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Reconciliation;
final class ServiceReconciliationException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int $status;
    public function __construct(string $reason){$this->errorCode='property_service_reconciliation_'.$reason;$this->status=409;parent::__construct($this->errorCode);}
}
