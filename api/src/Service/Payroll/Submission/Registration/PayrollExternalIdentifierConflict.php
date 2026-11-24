<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Číslo od ČSSZ (OIČ nebo ID PPV) už v evidenci patří jiné osobě nebo jinému
 * pracovnímu vztahu. Jedno číslo smí v jedné firmě a prostředí nést jediný
 * záznam, takže zápis se odmítne s hláškou, která držitele jmenuje.
 *
 * Je to {@see \DomainException}: akce ji vrací jako 409 a importy ji zapíší
 * k řádku jako nález, místo aby skončily chybou databáze. Vyhazuje se PŘED
 * vložením řádku, takže v evidenci nezůstane rozepsaný záznam.
 */
final class PayrollExternalIdentifierConflict extends \DomainException
{
    public function __construct(
        string $message,
        public readonly string $identifierType,
        public readonly int $holderEmployeeId,
        public readonly ?int $holderEmploymentId,
    ) {
        parent::__construct($message);
    }
}
