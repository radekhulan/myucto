<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027\JmhzScenario1Xml2027Serializer;

/**
 * Striktní lokální dry-run. Neposílá nic ven a nezapisuje do platformy podání
 * — ověřuje jen, že by vyrobené XML prošlo připnutým schématem.
 *
 * Vstupem je celé rozhodnutí resolveru, ne jen kandidát: XML smí vzniknout
 * výhradně z dokumentu bez blockerů. Kdyby validátor bral rovnou dokument,
 * dala by se fail-closed vrstva obejít tím, že se resolver prostě nezavolá.
 *
 * Serializér a schéma volí vykazované období dokumentu ({@see JmhzSpecVersion}):
 * do 12/2026 verze 2026 beze změny, od 01/2027 serializér XSD 1.5.0.3. Verze,
 * kterou instalace ještě neumí sestavit, se zastaví branou
 * {@see JmhzSpecPackageCatalog::requireBuildable()}; testy ji nahrazují
 * vlastní bránou v konstruktoru.
 */
final readonly class JmhzScenario1XmlValidator
{
    /** @var \Closure(JmhzSpecVersion):void */
    private \Closure $buildableGate;

    /**
     * @param (\Closure(JmhzSpecVersion):void)|null $buildableGate brána sestavitelnosti
     *        verze 2027; `null` = {@see JmhzSpecPackageCatalog::requireBuildable()}
     */
    public function __construct(
        private JmhzSchemaCatalog $schemas = new JmhzSchemaCatalog(),
        private JmhzScenario1XmlSerializer $serializer = new JmhzScenario1XmlSerializer(),
        private JmhzScenario1Xml2027Serializer $serializer2027 = new JmhzScenario1Xml2027Serializer(),
        ?\Closure $buildableGate = null,
    ) {
        $this->buildableGate = $buildableGate
            ?? static fn (JmhzSpecVersion $version) => JmhzSpecPackageCatalog::requireBuildable($version);
    }

    public function packageFormLimit(): int
    {
        return $this->serializer->packageFormLimit();
    }

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
        $version = $this->versionOf($document);
        $xml = $version === JmhzSpecVersion::V2027
            ? $this->serializer2027->serialize($document, $envelope)
            : $this->serializer->serialize($document, $envelope);
        $this->assertByteStable($document, $envelope, $xml, $version);
        $schema = $this->schemaFor($version);
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

    /**
     * Řádné hlášení jako dílčí balíky. Do 1500 formulářů jediný balík, bajtově
     * shodný s {@see self::dryRun()}; nad 1500 každý balík zvlášť ověřený XSD.
     *
     * @return array{packages:list<array{ordinal:int,xml:string,sha256:string}>,schema:array<string,string>}
     */
    public function dryRunPackages(
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
        $version = $this->versionOf($document);
        $serializer = $version === JmhzSpecVersion::V2027 ? $this->serializer2027 : $this->serializer;
        $packages = $serializer->serializePackages($document, $envelope);
        $repeated = $serializer->serializePackages($document, $envelope);
        $schema = $this->schemaFor($version);
        $result = [];
        foreach ($packages as $index => $xml) {
            if (!hash_equals(hash('sha256', $repeated[$index] ?? ''), hash('sha256', $xml))) {
                throw new JmhzXmlException(
                    'jmhz_xml_not_byte_stable',
                    'Serializace téhož dokumentu nevrátila shodné bajty.',
                );
            }
            $this->assertSchemaValid($xml, $schema['path']);
            $result[] = ['ordinal' => $index + 1, 'xml' => $xml, 'sha256' => hash('sha256', $xml)];
        }

        return [
            'packages' => $result,
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
        $version = $this->versionOf($document);
        $serializer = $version === JmhzSpecVersion::V2027 ? $this->serializer2027 : $this->serializer;
        $xml = $serializer->serializeCorrection($document, $envelope, $plan);
        $repeated = $serializer->serializeCorrection($document, $envelope, $plan);
        if (!hash_equals(hash('sha256', $repeated), hash('sha256', $xml))) {
            throw new JmhzXmlException(
                'jmhz_xml_not_byte_stable',
                'Serializace téže obsahové opravy nevrátila shodné bajty.',
            );
        }
        $schema = $this->schemaFor($version);
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

    /**
     * Obsahová oprava jako dílčí balíky. Do 1500 opravených součástí jediný
     * balík, bajtově shodný s {@see self::dryRunCorrection()}; nad 1500 každý
     * balík zvlášť ověřený XSD.
     *
     * @return array{packages:list<array{ordinal:int,xml:string,sha256:string}>,schema:array<string,string>}
     */
    public function dryRunCorrectionPackages(
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
        $version = $this->versionOf($document);
        $serializer = $version === JmhzSpecVersion::V2027 ? $this->serializer2027 : $this->serializer;
        $packages = $serializer->serializeCorrectionPackages($document, $envelope, $plan);
        $repeated = $serializer->serializeCorrectionPackages($document, $envelope, $plan);
        $schema = $this->schemaFor($version);
        $result = [];
        foreach ($packages as $index => $xml) {
            if (!hash_equals(hash('sha256', $repeated[$index] ?? ''), hash('sha256', $xml))) {
                throw new JmhzXmlException(
                    'jmhz_xml_not_byte_stable',
                    'Serializace téže obsahové opravy nevrátila shodné bajty.',
                );
            }
            $this->assertSchemaValid($xml, $schema['path']);
            $result[] = ['ordinal' => $index + 1, 'xml' => $xml, 'sha256' => hash('sha256', $xml)];
        }

        return [
            'packages' => $result,
            'schema' => [
                'package_key' => $schema['package_key'],
                'data_version' => $schema['data_version'],
                'bundle_sha256' => $schema['bundle_sha256'],
                'document_sha256' => $document->sha256(),
            ],
        ];
    }

    /**
     * Ověření HOTOVÉ datové věty proti připnutému XSD, bez serializace. Pro
     * znovu zmrazené podání, u kterého se měnily jen GUIDy a čas vyplnění.
     *
     * @return array{package_key:string,data_version:string,bundle_sha256:string}
     */
    public function validateFrozen(string $xml): array
    {
        $schema = $this->schemaFor($this->frozenVersion($xml));
        $this->assertSchemaValid($xml, $schema['path']);

        return [
            'package_key' => $schema['package_key'],
            'data_version' => $schema['data_version'],
            'bundle_sha256' => $schema['bundle_sha256'],
        ];
    }

    /**
     * Verze dokumentu podle vykazovaného období v hlavičce. Dokument bez
     * platného období jde cestou 2026, kde ho serializér odmítne stejně jako dřív.
     */
    private function versionOf(JmhzScenario1NormalizedDocument $document): JmhzSpecVersion
    {
        $header = $document->payload['header'] ?? null;
        $year = is_array($header) ? ($header['year'] ?? null) : null;
        $month = is_array($header) ? ($header['month'] ?? null) : null;
        if (!is_int($year) || !is_int($month) || $month < 1 || $month > 12 || $year < 2000 || $year > 2999) {
            return JmhzSpecVersion::V2026;
        }
        $version = JmhzSpecVersion::forPeriod($year, $month);
        if ($version !== JmhzSpecVersion::V2026) {
            ($this->buildableGate)($version);
        }

        return $version;
    }

    /** Verze hotové datové věty podle jmenného prostoru kořene. */
    private function frozenVersion(string $xml): JmhzSpecVersion
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $version = $loaded
            ? JmhzSpecVersion::fromDocumentNamespace((string) $dom->documentElement?->namespaceURI)
            : null;
        if ($version === null || $version === JmhzSpecVersion::V2026) {
            return JmhzSpecVersion::V2026;
        }
        ($this->buildableGate)($version);

        return $version;
    }

    /** @return array{path:string,package_key:string,data_version:string,bundle_sha256:string} */
    private function schemaFor(JmhzSpecVersion $version): array
    {
        return $version === JmhzSpecVersion::V2026
            ? $this->schemas->entryPoint()
            : $this->schemas->entryPointFor($version);
    }

    private function assertByteStable(
        JmhzScenario1NormalizedDocument $document,
        JmhzSubmissionEnvelope $envelope,
        string $xml,
        JmhzSpecVersion $version,
    ): void {
        $repeated = $version === JmhzSpecVersion::V2027
            ? $this->serializer2027->serialize($document, $envelope)
            : $this->serializer->serialize($document, $envelope);
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
