<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

use MyInvoice\Repository\Payroll\PayrollRegzelRepository;

final readonly class RegzelSubmissionPayloadAssembler
{
    public function __construct(
        private PayrollRegzelRepository $repository,
        private EmployerRegistrationService $registration,
    ) {}

    public function assemble(
        int $supplierId,
        int $snapshotId,
        string $environment,
    ): RegzelSubmissionPayload {
        if ($supplierId <= 0 || $snapshotId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a REGZEL snapshot musí být kladná čísla.',
            );
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new RegzelValidationException(
                'regzel_environment_invalid',
                'Prostředí REGZEL není platné.',
            );
        }

        $snapshot = $this->repository->findSnapshot(
            $supplierId,
            $snapshotId,
            $environment,
        );
        if ($snapshot === null) {
            throw new \OutOfBoundsException(
                'REGZEL snapshot nebyl nalezen ve stejné firmě a prostředí.',
            );
        }
        if ($snapshot['document_type'] !== 'REGZELDOPL25'
            || $snapshot['interaction_code'] !== 'supplemental_information'
            || !in_array($snapshot['mapping_version'], [
                RegzelPayloadSnapshot::LEGACY_MAPPING_VERSION,
                RegzelPayloadSnapshot::MAPPING_VERSION,
            ], true)
            || $snapshot['xsd_version'] !== RegzelPayloadSnapshot::XSD_VERSION
        ) {
            throw new \DomainException(
                'REGZEL snapshot nemá podporovaný dokument, interakci nebo verzi.',
            );
        }

        $download = $this->registration->snapshotXml(
            $supplierId,
            $snapshotId,
            $environment,
        );
        $xml = $download['xml'];
        $xmlHash = hash('sha256', $xml);
        if (!hash_equals($snapshot['xml_sha256'], $xmlHash)
            || strlen($xml) !== $snapshot['xml_byte_size']
        ) {
            throw new \UnexpectedValueException(
                'Přesné REGZEL XML neodpovídá archivovanému snapshotu.',
            );
        }

        return new RegzelSubmissionPayload(
            $supplierId,
            $snapshotId,
            $snapshot['office_id'],
            $environment,
            $snapshot['document_type'],
            $snapshot['interaction_code'],
            $snapshot['mapping_version'],
            $snapshot['xsd_version'],
            $snapshot['source_snapshot_hash'],
            $xmlHash,
            $xml,
        );
    }
}
