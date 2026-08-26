<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;

/**
 * Striktní lokální dry-run. Neposílá nic ven a nezapisuje do platformy podání
 * — ověřuje jen, že by vyrobené XML prošlo připnutým schématem.
 *
 * Vstupem je celé rozhodnutí resolveru, ne jen kandidát: XML smí vzniknout
 * výhradně z dokumentu bez blockerů. Kdyby validátor bral rovnou dokument,
 * dala by se fail-closed vrstva obejít tím, že se resolver prostě nezavolá.
 */
final readonly class JmhzScenario1XmlValidator
{
    public function __construct(
        private JmhzSchemaCatalog $schemas = new JmhzSchemaCatalog(),
        private JmhzScenario1XmlSerializer $serializer = new JmhzScenario1XmlSerializer(),
    ) {}

    /** @return array{xml:string,sha256:string,schema:array<string,string>} */
    public function dryRun(
        JmhzScenario1Resolution $resolution,
        JmhzSubmissionEnvelope $envelope,
    ): array {
        if ($resolution->status() !== 'resolved' || $resolution->blockers !== []) {
            throw new JmhzXmlException(
                'jmhz_xml_resolution_blocked',
                'Blokovaný dokument nelze serializovat do podání.',
            );
        }
        $document = $resolution->requireResolvedDocument();
        $xml = $this->serializer->serialize($document, $envelope);
        $this->assertByteStable($document, $envelope, $xml);
        $schema = $this->schemas->entryPoint();
        $this->assertSchemaValid($xml, $schema['path']);

        return [
            'xml' => $xml,
            'sha256' => hash('sha256', $xml),
            'schema' => [
                'package_key' => $schema['package_key'],
                'data_version' => $schema['data_version'],
                'bundle_sha256' => $schema['bundle_sha256'],
                'document_sha256' => $document->sha256(),
            ],
        ];
    }

    /** @return array{xml:string,sha256:string,schema:array<string,string>} */
    public function dryRunCorrection(
        JmhzScenario1Resolution $resolution,
        JmhzSubmissionEnvelope $envelope,
        JmhzContentCorrectionPlan $plan,
    ): array {
        if ($resolution->status() !== 'resolved' || $resolution->blockers !== []) {
            throw new JmhzXmlException(
                'jmhz_xml_resolution_blocked',
                'Blokovaný dokument nelze serializovat do obsahové opravy.',
            );
        }
        $document = $resolution->requireResolvedDocument();
        $xml = $this->serializer->serializeCorrection($document, $envelope, $plan);
        $repeated = $this->serializer->serializeCorrection($document, $envelope, $plan);
        if (!hash_equals(hash('sha256', $repeated), hash('sha256', $xml))) {
            throw new JmhzXmlException(
                'jmhz_xml_not_byte_stable',
                'Serializace téže obsahové opravy nevrátila shodné bajty.',
            );
        }
        $schema = $this->schemas->entryPoint();
        $this->assertSchemaValid($xml, $schema['path']);

        return [
            'xml' => $xml,
            'sha256' => hash('sha256', $xml),
            'schema' => [
                'package_key' => $schema['package_key'],
                'data_version' => $schema['data_version'],
                'bundle_sha256' => $schema['bundle_sha256'],
                'document_sha256' => $document->sha256(),
            ],
        ];
    }

    private function assertByteStable(
        JmhzScenario1NormalizedDocument $document,
        JmhzSubmissionEnvelope $envelope,
        string $xml,
    ): void {
        $repeated = $this->serializer->serialize($document, $envelope);
        if (!hash_equals(hash('sha256', $repeated), hash('sha256', $xml))) {
            throw new JmhzXmlException(
                'jmhz_xml_not_byte_stable',
                'Serializace téhož dokumentu nevrátila shodné bajty.',
            );
        }
    }

    private function assertSchemaValid(string $xml, string $schemaPath): void
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        // `schemaValidate()` druhý parametr pro `LIBXML_NONET` nemá, takže síť
        // tu vypnout nejde. Offline běh drží `JmhzSchemaCatalog`: každý
        // `schemaLocation` v balíčku míří na relativní soubor uvnitř téhož
        // adresáře a všech čtrnáct se ověřuje na SHA-256, takže vzdálený odkaz
        // by musel projít změnou otisku.
        $valid = $loaded && $dom->schemaValidate($schemaPath);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            $messages = array_unique(array_map(
                static fn (\LibXMLError $error): string => trim($error->message),
                $errors,
            ));
            throw new JmhzXmlException(
                'jmhz_xsd_validation_failed',
                'XML měsíčního hlášení neprošlo připnutým XSD: '
                    . implode('; ', $messages),
            );
        }
    }
}
