<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Datový klíč subjektu byl zahozen krypto-výmazem — dokumenty jsou nevratně
 * nečitelné. Není to chyba úložiště, ale očekávaný výsledek výmazu osobních
 * údajů podle čl. 17 GDPR.
 */
final class PayrollDocumentKeyDestroyedException extends \RuntimeException {}
