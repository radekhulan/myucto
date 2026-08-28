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
}
