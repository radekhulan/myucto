<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
final class FrameworkConversionJournalAdapter
{
    public function __construct(private readonly Connection $db,private readonly JournalEntryRepository $entries){}
    public function cancel(int $supplier,int $conversion,int $actor,string $receiptHash):void
    {
        E::need(method_exists($this->entries,'cancelFrameworkConversion'),'conversion_integration_required');
        $this->entries->cancelFrameworkConversion($supplier,$conversion,$actor,$receiptHash);
    }
    public function insert(int $supplier,int $conversion):int
    {
        E::need(defined(JournalEntryRepository::class.'::FRAMEWORK_CONVERSION_OWNER_VERSION')&&JournalEntryRepository::FRAMEWORK_CONVERSION_OWNER_VERSION===1&&method_exists($this->entries,'insertFrameworkConversion'),'conversion_integration_required');
        return $this->entries->insertFrameworkConversion($supplier,$conversion);
    }
}
