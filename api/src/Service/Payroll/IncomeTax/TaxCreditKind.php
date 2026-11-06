<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum TaxCreditKind: string
{
    case Taxpayer = 'taxpayer';
    case DisabilityBasic = 'disability-basic';
    case DisabilityExtended = 'disability-extended';
    case ZtpP = 'ztp-p';
    /** § 35ba odst. 1 písm. f) a § 35bc odst. 2 písm. a) ZDP, od 1. 1. 2027 — viz {@see StudentTaxCredit}. */
    case Student = 'student';
    /** § 35bc odst. 2 písm. b) ZDP: prezenční doktorské studium do 28 let. */
    case StudentDoctoral = 'student-doctoral';
}
