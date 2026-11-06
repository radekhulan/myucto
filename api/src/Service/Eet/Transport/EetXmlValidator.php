<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Validace elementu `Trzba` proti připnutému XSD 4.1 (`api/resources/eet/`) ještě
 * před podpisem. Schéma nemá importy, takže validace nesahá na síť.
 */
final class EetXmlValidator
{
    private ?string $schema = null;

    public function __construct(
        private readonly string $schemaPath = '',
    ) {}

    public static function defaultSchemaPath(): string
    {
        return dirname(__DIR__, 4) . '/resources/eet/EETXMLSchema.xsd';
    }

    public function validate(\DOMDocument $trzba): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $valid = $trzba->schemaValidateSource($this->schema());
            $errors = array_map(
                static fn (\LibXMLError $error): string => trim($error->message),
                libxml_get_errors(),
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$valid) {
            throw new EetValidationException(
                'xsd',
                'Datová zpráva nevyhověla schématu EET 4.1: ' . ($errors === [] ? 'neznámá chyba' : implode('; ', array_slice($errors, 0, 3))),
            );
        }
    }

    private function schema(): string
    {
        if ($this->schema === null) {
            $path = $this->schemaPath !== '' ? $this->schemaPath : self::defaultSchemaPath();
            $content = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($content) || $content === '') {
                throw new \RuntimeException('Schéma EET (EETXMLSchema.xsd) chybí v instalaci.');
            }
            $this->schema = $content;
        }

        return $this->schema;
    }
}
