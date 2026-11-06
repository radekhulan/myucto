<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Údaje tržby nebo hlavičky neprojdou formálními kontrolami (formát, rozsah, XSD,
 * velikost zprávy). Zpráva se v takovém případě vůbec neodešle.
 */
final class EetValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
