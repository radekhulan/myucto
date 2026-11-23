<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Čerpání zálohy, jejíž úhrada není zaúčtovaná bankou ani pokladnou ani převzatá
 * z počátečních stavů ({@see AdvancePartialDrawdown}). Konečnou fakturu by s takovým
 * čerpáním nešlo zaúčtovat, proto se odmítne už při čerpání (409 `advance_receipt_not_posted`).
 */
final class AdvanceReceiptNotPostedException extends \DomainException
{
    public const ERROR_CODE = 'advance_receipt_not_posted';
}
