<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Receivables;
use MyInvoice\Service\Property\PropertyException;
final class ReceivableException
{
    public static function reject(string $reason,array $details=[]):never
    {
        throw new PropertyException('property_receivable_'.$reason,'Zkontrolujte doložený titul a aktuální stav salda.',409,$details);
    }
}
