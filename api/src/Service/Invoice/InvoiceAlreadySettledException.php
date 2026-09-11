<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Bankovní platba míří na fakturu, kterou evidované platby už plně kryjí.
 *
 * Vyhazuje ji jediné místo, {@see InvoicePaymentService::recordPayment()}, a to jen
 * pro zdroj `bank`. Ruční párování ji překládá na HTTP 409 — druhá platba na plnou
 * částku by jinak fakturu přeplatila a bankovní zápis by odúčtoval 311 podruhé.
 */
final class InvoiceAlreadySettledException extends \RuntimeException
{
    public const CODE = 'invoice_already_settled';

    public function __construct(
        public readonly int $invoiceId,
        public readonly float $remaining,
    ) {
        parent::__construct(
            'Faktura je už plně uhrazená evidovanými platbami, další bankovní platbu na ni nelze zaevidovat. '
            . 'Pokud jde o dvojí úhradu, zkontrolujte platby v detailu faktury.'
        );
    }
}
