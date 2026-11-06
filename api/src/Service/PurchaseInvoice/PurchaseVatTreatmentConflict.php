<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

/**
 * Změnu nároku na odpočet v hlavičce nejde jednoznačně promítnout do rozpisu DPH.
 * Volající ji vrací jako validační chybu 422, nic se neuloží.
 */
final class PurchaseVatTreatmentConflict extends \DomainException
{
    public const ERROR_CODE = 'vat_allocations_deduction_conflict';
}
