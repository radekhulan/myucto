<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Subjekt nemá datový klíč. U dokumentů uložených před zavedením šifrování je
 * to normální stav — čtou se legacy větví z původní cesty.
 */
final class PayrollDocumentKeyMissingException extends \RuntimeException {}
