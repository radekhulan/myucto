<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

enum PayrollSensitiveField: string
{
    case PERSONAL_IDENTIFIER = 'personal_identifier';
    case FOREIGN_TAX_IDENTIFIER = 'foreign_tax_identifier';
    case PERSON_EXTERNAL_IDENTIFIER = 'person_external_identifier';
    case EMPLOYMENT_EXTERNAL_IDENTIFIER = 'employment_external_identifier';
    case BANK_ACCOUNT = 'bank_account';
    case CONTACT_EMAIL = 'contact_email';
    case CONTACT_PHONE = 'contact_phone';
    case REGISTRATION_A1_PROFILE = 'registration_a1_profile';
    /** Obsah podání ČSSZ, které podal předchozí mzdový program (atributy JMHZ jako JSON). */
    case EXTERNAL_JMHZ_PAYLOAD = 'external_jmhz_payload';
    /**
     * Rodné číslo dítěte nebo manžela/manželky v daňovém profilu poplatníka
     * (přiznání DPFO). Normalizuje a maskuje se jako osobní identifikátor, ale má
     * vlastní kontext šifry i otisku: šifra z mzdové evidence se pod řádek daňového
     * profilu nedá podstrčit a otisky obou evidencí se nedají spárovat.
     */
    case TAX_FAMILY_IDENTIFIER = 'tax_family_identifier';

    /** Strukturovaný dokument (JSON) místo jednoho identifikátoru: nenormalizuje se a nemaskuje. */
    public function isDocument(): bool
    {
        return $this === self::REGISTRATION_A1_PROFILE || $this === self::EXTERNAL_JMHZ_PAYLOAD;
    }
}
