<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment\Xmlzam;

final readonly class XmlzamCooperationRequest
{
    /** @param list<string> $requestedScopes */
    public function __construct(
        public string $identifier,
        public string $caseReference,
        public string $issuedOn,
        public array $requestedScopes,
        public string $debtorGivenName,
        public string $debtorFamilyName,
        public string $debtorBirthDate,
        public string $debtorBirthNumber,
        public string $executorDataBoxId,
    ) {}
}
