<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Konečnou fakturu z převzaté zálohy nejde sestavit, protože záloha nenese sazby DPH
 * a daňové doklady k platbám je nepokrývají ({@see ProformaVatRates}). Faktura by vyšla
 * s nulovou nebo zápornou DPH (409 `proforma_without_vat_rates`).
 */
final class ProformaWithoutVatRatesException extends \DomainException
{
    public const ERROR_CODE = ProformaVatRates::ERROR_CODE;
}
