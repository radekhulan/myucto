<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Closing\ClosingEntryBuilder;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
final class FrameworkConversionNative500Adapter
{
    public function __construct(private readonly Connection $db){}
    public function snapshot(int $supplier,array $period,bool $current):array
    {
        if($current)E::need(defined(ClosingRepository::class.'::FRAMEWORK_CONVERSION_CURRENT_READ_VERSION')&&ClosingRepository::FRAMEWORK_CONVERSION_CURRENT_READ_VERSION===1,'conversion_current_native_reader_required');
        $native=new ClosingRepository($this->db);
        return(new ClosingEntryBuilder())->closingLines($native->plBalances($supplier,(int)$period['id'],$period['starts_on'],$period['ends_on'],$current),$native->bsBalances($supplier,(int)$period['id'],$period['ends_on'],$current),new AccountingRoleProfile('cz_500'));
    }
}
