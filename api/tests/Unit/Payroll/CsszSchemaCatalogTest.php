<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use DOMDocument;
use DOMXPath;
use LibXMLError;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsszSchemaCatalogTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function documentTypes(): array
    {
        return [
            'NEMPRI25' => [CsszSchemaCatalog::NEMPRI25],
            'HZUPN20' => [CsszSchemaCatalog::HZUPN20],
        ];
    }

    /** @return array<string,array{string,string,string,string}> */
    public static function documentVersions(): array
    {
        return [
            'NEMPRI25' => [CsszSchemaCatalog::NEMPRI25, '1.0', '1.0', '1.0'],
            'HZUPN20' => [CsszSchemaCatalog::HZUPN20, '1.2', '1.1', '20201.01'],
        ];
    }

    #[DataProvider('documentVersions')]
    public function testPinnedBundleHasVerifiedOfficialSourceMetadata(
        string $documentType,
        string $packageVersion,
        string $xsdVersion,
        string $payloadVersion,
    ): void {
        $catalog = new CsszSchemaCatalog();
        $manifest = $catalog->manifestFor($documentType);
        $schema = $catalog->schemaFor($documentType);

        self::assertTrue($manifest['available']);
        self::assertSame($manifest['path'], $schema['path']);
        self::assertSame($manifest['namespace'], $schema['namespace']);
        self::assertSame($manifest['root'], $schema['root']);
        self::assertSame($packageVersion, $manifest['package_version']);
        self::assertSame($xsdVersion, $manifest['xsd_version']);
        self::assertSame($payloadVersion, $manifest['payload_version']);
        self::assertSame($manifest['package_version'], $schema['package_version']);
        self::assertSame($manifest['xsd_version'], $schema['xsd_version']);
        self::assertSame($manifest['payload_version'], $schema['payload_version']);
        self::assertMatchesRegularExpression('#^https://www\\.cssz\\.gov\\.cz/#', $manifest['entry_url']);
        self::assertMatchesRegularExpression('#^https://www\\.cssz\\.gov\\.cz/#', $manifest['dependency_url']);
        self::assertSame($manifest['entry_sha256'], hash_file('sha256', $manifest['path']));
        self::assertSame(
            $manifest['dependency_sha256'],
            hash_file('sha256', $manifest['dependency_path']),
        );
    }

    #[DataProvider('documentTypes')]
    public function testEntrypointResolvesItsPinnedRelativeImport(
        string $documentType,
    ): void {
        $catalog = new CsszSchemaCatalog();
        $manifest = $catalog->manifestFor($documentType);
        $schema = $catalog->schemaFor($documentType);

        $document = new DOMDocument();
        self::assertTrue($document->load($schema['path']));
        self::assertSame(
            $manifest['xsd_version'],
            $document->documentElement?->getAttribute('version'),
        );
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $imports = $xpath->query('/xs:schema/xs:import');

        self::assertNotFalse($imports);
        self::assertCount(1, $imports);
        self::assertSame(
            $manifest['dependency_filename'],
            $imports->item(0)?->attributes?->getNamedItem('schemaLocation')?->nodeValue,
        );
        self::assertSame(
            $manifest['dependency_path'],
            dirname($schema['path']) . '/' . $manifest['dependency_filename'],
        );
        self::assertFileExists($manifest['dependency_path']);
    }

    #[DataProvider('documentTypes')]
    public function testLibxmlLoadsTheWholeSchemaBundleBeforeRejectingAnInvalidDocument(
        string $documentType,
    ): void {
        $schema = (new CsszSchemaCatalog())->schemaFor($documentType);
        $document = new DOMDocument();
        self::assertTrue($document->loadXML('<invalid xmlns="' . $schema['namespace'] . '"/>'));

        $wasUsingInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            self::assertFalse($document->schemaValidate($schema['path']));
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($wasUsingInternalErrors);
        }

        self::assertNotEmpty($errors);
        $messages = implode("\n", array_map(
            static fn (LibXMLError $error): string => strtolower(trim($error->message)),
            $errors,
        ));
        self::assertDoesNotMatchRegularExpression(
            '/failed to (load|locate|parse|compile|import)|could not load/',
            $messages,
        );
    }
}
