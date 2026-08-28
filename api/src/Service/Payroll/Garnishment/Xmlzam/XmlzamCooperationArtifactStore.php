<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment\Xmlzam;

use MyInvoice\Repository\Payroll\XmlzamCooperationRepository;
use MyInvoice\Service\Auth\SecretEncryption;

final readonly class XmlzamCooperationArtifactStore
{
    public function __construct(
        private XmlzamCooperationRepository $repository,
        private SecretEncryption $encryption,
    ) {}

    /** @return array{filename:string,mime:string,bytes:string}|null */
    public function resolve(int $supplierId, int $responseId): ?array
    {
        foreach (['production', 'test'] as $environment) {
            $row = $this->repository->findResponse($supplierId, $environment, $responseId);
            if ($row === null) {
                continue;
            }
            $context = self::responseXmlContext(
                $supplierId,
                $environment,
                (int) $row['request_id'],
                (string) $row['snapshot_fingerprint'],
            );
            $bytes = $this->encryption->decryptFor((string) $row['xml_ciphertext'], $context);
            if (!hash_equals((string) $row['xml_sha256'], hash('sha256', $bytes))) {
                throw new \DomainException('Otisk uložené odpovědi XMLZAM nesouhlasí.');
            }
            return [
                'filename' => 'xmlzam-' . (string) $row['response_identifier'] . '.xml',
                'mime' => 'application/xml',
                'bytes' => $bytes,
            ];
        }
        return null;
    }

    public static function responseXmlContext(int $supplierId, string $environment, int $requestId, string $fingerprint): string
    {
        return "payroll:xmlzam:response-xml:{$supplierId}:{$environment}:{$requestId}:{$fingerprint}";
    }
}
