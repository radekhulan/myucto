<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Receivables;
final readonly class PropertySaldoReadSelection
{
    public function __construct(public array $rows,public array $debtIds,public array $chargeIds,public array $keys){}
}
