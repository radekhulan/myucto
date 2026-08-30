<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Obsah jednoho e-podání HZUPN20.
 *
 * Hlášení nese údaje, ze kterých ČSSZ vyplatí POSLEDNÍ dávku nemocenského:
 * jestli se zaměstnanec vrátil do práce a kdy, kolik hodin odpracoval
 * v poslední den neschopnosti proti své pracovní době, a ve kterých dnech
 * během neschopnosti pracoval.
 *
 * Datová věta HZUPN má na rozdíl od NEMPRI logické hodnoty jako `A`/`N`
 * (`simpleLType`, resp. `simpleLSType` v `baseTypes.xsd`), ne `xs:boolean`.
 * Překlad dělá serializer; payload drží PHP `bool`, aby se ta dvě „ne"
 * nemohla splést.
 */
final readonly class HzupnXmlPayload
{
    /** @param list<array{from:string,to:string}> $workIntervals */
    public function __construct(
        public bool $employerReport,
        public bool $personReport,
        public bool $foreignCase,
        public ?string $confirmationNumber,
        public int $osszCode,
        public ?string $osszName,
        public string $issuedOn,
        public bool $correction,
        public string $insuredFirstName,
        public string $insuredLastName,
        public ?string $insuredTitle,
        public ?string $insuredBirthNumber,
        public ?string $insuredBirthDate,
        public string $employerName,
        public ?string $employerIdentificationNumber,
        public string $employerVariableSymbol,
        public ?bool $returnedToWork,
        public ?string $returnReason,
        public ?string $returnedOn,
        public ?string $hoursWorkedLastDay,
        public ?string $shiftHoursLastDay,
        public array $workIntervals,
        public string $productName,
        public string $productVersion,
        public string $payloadVersion,
        public ?string $notificationEmail = null,
    ) {}
}
