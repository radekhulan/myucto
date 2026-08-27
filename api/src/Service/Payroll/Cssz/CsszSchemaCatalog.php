<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Cssz;

use RuntimeException;

/**
 * Připnuté XSD balíčky ČSSZ pro agendy, které zatím zůstávají v ručním režimu.
 *
 * Katalog neposkytuje serializaci ani transport. Zajišťuje pouze to, aby
 * budoucí implementace nemohla validovat proti jinému než ověřenému zdroji.
 */
final class CsszSchemaCatalog
{
    public const NEMPRI25 = 'NEMPRI25';
    public const HZUPN20 = 'HZUPN20';

    /**
     * @var array<string,array{
     *   package_version:string,xsd_version:string,payload_version:string,
     *   entry_filename:string,entry_sha256:string,entry_url:string,
     *   dependency_filename:string,dependency_sha256:string,dependency_url:string,
     *   namespace:string,root:string
     * }>
     */
    private const MANIFEST = [
        self::NEMPRI25 => [
            'package_version' => '1.0',
            'xsd_version' => '1.0',
            'payload_version' => '1.0',
            'entry_filename' => 'NEMPRI25.xsd',
            'entry_sha256' =>
                'c381ca7560eed2aae5ceb91c6a26a1904b4e17c85755921fe02316167af2c8ca',
            'entry_url' => 'https://www.cssz.gov.cz/documents/20143/2739697/'
                . 'NEMPRI25.xsd/ccb22dda-af2d-8752-1ba2-7b6742052fc5',
            'dependency_filename' => 'baseTypes2.xsd',
            'dependency_sha256' =>
                '579888e1dd29a60eb66b26dcf32031658ead51618c74f9f96fabf8d7a1305747',
            'dependency_url' => 'https://www.cssz.gov.cz/documents/20143/99647/'
                . 'baseTypes2.xsd/9f54f063-f1de-2ce6-ae8c-4d2dc7542af4',
            'namespace' => 'http://schemas.cssz.cz/nem/NEMPRI25',
            'root' => 'NEMPRI',
        ],
        self::HZUPN20 => [
            'package_version' => '1.2',
            'xsd_version' => '1.1',
            'payload_version' => '20201.01',
            'entry_filename' => 'HZUPN20 v1.2.xsd',
            'entry_sha256' =>
                '5cea60ea30e5f6872c7b67324274abd1591af5e1c33fa2f42ef0c1e8ff740621',
            'entry_url' => 'https://www.cssz.gov.cz/documents/20143/284890/'
                . 'HZUPN20%2Bv1.2.xsd/0d032732-e46d-f5b2-eb5f-037c7555a799',
            'dependency_filename' => 'baseTypes.xsd',
            'dependency_sha256' =>
                '021d63bd7d2432ab431d225e980e6919b36616a02cd11fcafea9de6172294f30',
            'dependency_url' => 'https://www.cssz.gov.cz/documents/20143/99647/'
                . 'baseTypes.xsd/e2c9cd79-5b72-02be-b959-9aa63fd02044',
            'namespace' => 'http://schemas.cssz.cz/nem/HZUPN20',
            'root' => 'PodaniHZUPN',
        ],
    ];

    /** @return list<string> */
    public function documentTypes(): array
    {
        return array_keys(self::MANIFEST);
    }

    /**
     * @return array{
     *   package_version:string,xsd_version:string,payload_version:string,
     *   entry_filename:string,entry_sha256:string,entry_url:string,
     *   dependency_filename:string,dependency_sha256:string,dependency_url:string,
     *   namespace:string,root:string,path:string,dependency_path:string,available:bool
     * }
     */
    public function manifestFor(string $documentType): array
    {
        $entry = self::MANIFEST[$documentType] ?? null;
        if ($entry === null) {
            throw new RuntimeException('Požadovaná agenda ČSSZ nemá připnuté XSD.');
        }

        $directory = $this->directory($documentType);
        $path = $directory . '/' . $entry['entry_filename'];
        $dependencyPath = $directory . '/' . $entry['dependency_filename'];

        return $entry + [
            'path' => $path,
            'dependency_path' => $dependencyPath,
            'available' => $this->matchesPin($path, $entry['entry_sha256'])
                && $this->matchesPin($dependencyPath, $entry['dependency_sha256']),
        ];
    }

    /**
     * @return array{
     *   path:string,namespace:string,root:string,package_version:string,
     *   xsd_version:string,payload_version:string
     * }
     */
    public function schemaFor(string $documentType): array
    {
        $manifest = $this->manifestFor($documentType);
        if (!$manifest['available']) {
            throw new RuntimeException(
                sprintf(
                    'Připnutý XSD balíček ČSSZ %s chybí nebo nemá ověřený otisk.',
                    $documentType,
                ),
            );
        }

        return [
            'path' => $manifest['path'],
            'namespace' => $manifest['namespace'],
            'root' => $manifest['root'],
            'package_version' => $manifest['package_version'],
            'xsd_version' => $manifest['xsd_version'],
            'payload_version' => $manifest['payload_version'],
        ];
    }

    private function directory(string $documentType): string
    {
        return dirname(__DIR__, 4) . '/xsd/cssz/' . match ($documentType) {
            self::NEMPRI25 => 'nempri25-1.0',
            self::HZUPN20 => 'hzupn20-1.2',
            default => throw new RuntimeException('Požadovaná agenda ČSSZ nemá připnuté XSD.'),
        };
    }

    private function matchesPin(string $path, string $expectedHash): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $actualHash = hash_file('sha256', $path);

        return is_string($actualHash) && hash_equals($expectedHash, $actualHash);
    }
}
