<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/**
 * Osoba, která už ve firmě je, podle identity ze zdroje převzatých mezd: rodné číslo,
 * pak OIČ. Stejné identifikátory a týž slepý index jako import registrací a hlášení
 * JMHZ ({@see \MyInvoice\Service\Payroll\Import\Registration\RegistrationImportPlanner}),
 * takže převod najde tutéž osobu jako ony a druhý vztah téže osoby nezaloží jako novou
 * osobu (rodné číslo je ve firmě unikátní).
 *
 * Shoda platí jen jednoznačná; víc kandidátů = `null` a o osobě rozhodne účetní.
 */
final class PayrollTakeoverPersonLookup
{
    private const ENVIRONMENT = 'production';

    public function __construct(
        private readonly RegistrationImportLookup $lookup,
        private readonly PayrollSensitiveData $sensitive,
    ) {}

    public function employeeId(int $supplierId, ?string $birthNumber, ?string $oic): ?int
    {
        $normalized = null;
        if (is_string($birthNumber) && trim($birthNumber) !== '') {
            try {
                $normalized = CzechBirthNumber::normalize($birthNumber);
            } catch (\InvalidArgumentException) {
                $normalized = null;
            }
        }
        if ($normalized !== null) {
            $ids = $this->lookup->employeesByIdentifierHash($supplierId, 'birth_number',
                $this->sensitive->lookupHash($normalized, PayrollSensitiveField::PERSONAL_IDENTIFIER, $supplierId));
            if (count($ids) === 1) {
                return $ids[0];
            }
            if ($ids !== []) {
                return null;
            }
        }
        if (is_string($oic) && preg_match('/^[0-9]{10}$/D', $oic) === 1) {
            $ids = $this->lookup->employeesByPersonExternalIdHash($supplierId, self::ENVIRONMENT,
                $this->sensitive->lookupHash($oic, PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER, $supplierId));
            if (count($ids) === 1) {
                return $ids[0];
            }
        }

        return null;
    }
}
