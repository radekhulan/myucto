<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

enum HealthInsurerIsdsAttachmentFormat: string
{
    case Xml = 'xml';
    case TextPdf = 'text_pdf';
    case None = 'none';
}
