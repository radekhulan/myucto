<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Snímek běhu nejde sestavit kvůli údaji, který účetní opraví sama.
 *
 * Hláška říká, co chybí a kde se to doplní; `remediationPath` vede přímo
 * na to místo. Kontrola před během z ní udělá nález s odkazem místo obecné
 * výzvy „obnovte stránku", založení a přepočet běhu ji vrátí jako 422.
 */
final class PayrollRunSnapshotFixableException extends \DomainException
{
    public function __construct(
        public readonly string $findingCode,
        string $message,
        public readonly string $remediationPath,
    ) {
        parent::__construct($message);
    }
}
