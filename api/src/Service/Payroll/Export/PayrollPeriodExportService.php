<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

use MyInvoice\Repository\Payroll\PayrollPeriodExportRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;

final class PayrollPeriodExportService
{
    private const ANNUAL_FINGERPRINT_DOMAINS = [
        'payroll_sheet' => 'annual-payroll-snapshot-v1',
        'annual_settlement_result' => 'annual-settlement-snapshot-v1',
        'taxable_income_advance_certificate' =>
            'annual-tax-certificate-snapshot-v1',
        'taxable_income_withholding_certificate' =>
            'annual-tax-certificate-snapshot-v1',
    ];

    public function __construct(
        private readonly PayrollPeriodExportRepository $repository,
        private readonly PayrollPeriodExportArchiveBuilder $builder,
        private readonly PayrollPeriodExportStorage $storage,
        private readonly PayrollDocumentStorage $documents,
        private readonly PayrollSubmissionService $submissions,
        private readonly SecretEncryption $encryption,
        private readonly PayrollSensitiveData $sensitiveData,
    ) {}

    /** @return array{id:int,export_scope:string,period_start:string,period_end:string,file_sha256:string,size_bytes:int,storage_key:string,suggested_filename:string,source_manifest_hash:string,manifest_json:string,mime_type:string,created_at:string} */
    public function createMonthly(
        int $supplierId,
        string $period,
        int $userId,
    ): array {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException(
                'Měsíční export vyžaduje období ve tvaru RRRR-MM.',
            );
        }
        $periodStart = $period . '-01';
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');

        return $this->create(
            $supplierId,
            PayrollPeriodExportScope::Monthly,
            $periodStart,
            $periodEnd,
            $userId,
        );
    }

    /** @return array{id:int,export_scope:string,period_start:string,period_end:string,file_sha256:string,size_bytes:int,storage_key:string,suggested_filename:string,source_manifest_hash:string,manifest_json:string,mime_type:string,created_at:string} */
    public function createAnnual(
        int $supplierId,
        int $year,
        int $userId,
    ): array {
        if ($year < 2000 || $year > 2199) {
            throw new \InvalidArgumentException(
                'Rok exportu mezd není platný.',
            );
        }

        return $this->create(
            $supplierId,
            PayrollPeriodExportScope::Annual,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year),
            $userId,
        );
    }

    /**
     * @param null|callable(array<string,mixed>):void $beforeCommit
     * @return array{grant_id:int,export_id:int,token:string,expires_at:string}
     */
    public function issueDownloadGrant(
        int $supplierId,
        int $exportId,
        int $userId,
        int $ttlSeconds = 300,
        ?callable $beforeCommit = null,
    ): array {
        if ($supplierId <= 0 || $exportId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, export a uživatel musí být kladná čísla.',
            );
        }
        if ($ttlSeconds < 30 || $ttlSeconds > 900) {
            throw new \InvalidArgumentException(
                'Platnost stažení musí být 30 až 900 sekund.',
            );
        }
        if ($this->repository->find($supplierId, $exportId) === null) {
            throw new \DomainException(
                'Export mezd nebyl nalezen ve stejné firmě.',
            );
        }
        $token = rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '=',
        );
        $tokenHash = hash('sha256', $token, true);
        $issued = $this->repository->transaction(function () use (
            $supplierId,
            $exportId,
            $userId,
            $ttlSeconds,
            $tokenHash,
            $beforeCommit,
        ): array {
            if ($this->repository->find($supplierId, $exportId) === null) {
                throw new \DomainException(
                    'Export mezd nebyl nalezen ve stejné firmě.',
                );
            }
            $now = $this->repository->currentUtcDateTime();
            $expiresAt = $now->modify("+{$ttlSeconds} seconds");
            $grantId = $this->repository->insertGrant(
                $supplierId,
                $exportId,
                $userId,
                $tokenHash,
                $now->format('Y-m-d H:i:s.u'),
                $expiresAt->format('Y-m-d H:i:s.u'),
            );
            $event = [
                'grant_id' => $grantId,
                'export_id' => $exportId,
                'ttl_seconds' => $ttlSeconds,
            ];
            if ($beforeCommit !== null) {
                $beforeCommit($event);
            }

            return [$grantId, $expiresAt];
        });

        return [
            'grant_id' => $issued[0],
            'export_id' => $exportId,
            'token' => $token,
            'expires_at' => $issued[1]->format(DATE_ATOM),
        ];
    }

    /**
     * ZIP se načte a ověří PŘED krátkou transakcí spotřeby tokenu. Dva
     * souběžné requesty mohou paralelně číst stejné bajty, ale podmíněný UPDATE
     * dovolí stažení dokončit jen jednomu z nich.
     *
     * @param null|callable(array<string,mixed>):void $beforeCommit
     * @return array{export_id:int,bytes:string,file_sha256:string,size_bytes:int,mime_type:string,suggested_filename:string}
     */
    public function consumeDownload(
        int $supplierId,
        int $userId,
        string $token,
        ?callable $beforeCommit = null,
    ): array {
        if ($supplierId <= 0 || $userId <= 0
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', trim($token)) !== 1
        ) {
            throw new \DomainException(
                'Odkaz ke stažení není platný nebo již vypršel.',
            );
        }
        $tokenHash = hash('sha256', trim($token), true);
        $grant = $this->repository->grantMetadata(
            $supplierId,
            $userId,
            $tokenHash,
        );
        if ($grant === null || $grant['used_at'] !== null) {
            throw new \DomainException(
                'Odkaz ke stažení není platný nebo již vypršel.',
            );
        }
        $now = $this->repository->currentUtcDateTime();
        if ($now > new \DateTimeImmutable(
            $this->stringField($grant, 'expires_at'),
            new \DateTimeZone('UTC'),
        )) {
            throw new \DomainException(
                'Odkaz ke stažení není platný nebo již vypršel.',
            );
        }

        $bytes = $this->storage->readVerified(
            $supplierId,
            $this->stringField($grant, 'storage_key'),
        );
        if (strlen($bytes) !== $this->integerField($grant, 'size_bytes')
            || !hash_equals(
                $this->stringField($grant, 'file_sha256'),
                hash('sha256', $bytes),
            )
        ) {
            throw new \DomainException(
                'Archivovaný export mezd nemá platnou integritu.',
            );
        }

        $this->repository->transaction(function () use (
            $grant,
            $tokenHash,
            $now,
            $beforeCommit,
        ): void {
            if (!$this->repository->consumeGrant(
                $this->integerField($grant, 'grant_id'),
                $tokenHash,
                $now->format('Y-m-d H:i:s.u'),
            )) {
                throw new \DomainException(
                    'Odkaz ke stažení není platný nebo již vypršel.',
                );
            }
            if ($beforeCommit !== null) {
                $beforeCommit([
                    'export_id' => $this->integerField($grant, 'export_id'),
                    'file_sha256' => $this->stringField($grant, 'file_sha256'),
                    'size_bytes' => $this->integerField($grant, 'size_bytes'),
                ]);
            }
        });

        return [
            'export_id' => $this->integerField($grant, 'export_id'),
            'bytes' => $bytes,
            'file_sha256' => $this->stringField($grant, 'file_sha256'),
            'size_bytes' => $this->integerField($grant, 'size_bytes'),
            'mime_type' => $this->stringField($grant, 'mime_type'),
            'suggested_filename' => $this->stringField(
                $grant,
                'suggested_filename',
            ),
        ];
    }

    /** @return array{id:int,export_scope:string,period_start:string,period_end:string,file_sha256:string,size_bytes:int,storage_key:string,suggested_filename:string,source_manifest_hash:string,manifest_json:string,mime_type:string,created_at:string} */
    private function create(
        int $supplierId,
        PayrollPeriodExportScope $scope,
        string $periodStart,
        string $periodEnd,
        int $userId,
    ): array {
        if ($supplierId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a uživatel exportu musí být kladná čísla.',
            );
        }

        $source = $this->repository->source(
            $supplierId,
            $scope->value,
            $periodStart,
            $periodEnd,
        );
        $annualRevisions = $source['data']['annual_revisions'] ?? null;
        if (!is_array($annualRevisions)) {
            throw new \UnexpectedValueException(
                'Seznam ročních revizí exportu mezd není platný.',
            );
        }
        foreach ($annualRevisions as &$annualRevision) {
            if (!is_array($annualRevision)) {
                throw new \UnexpectedValueException(
                    'Roční revize exportu mezd není platná.',
                );
            }
            $employeeId = $this->integerField(
                $annualRevision,
                'employee_id',
            );
            $taxYear = $this->integerField($annualRevision, 'tax_year');
            $purpose = $this->stringField($annualRevision, 'purpose');
            $manifestHash = $this->stringField(
                $annualRevision,
                'source_manifest_hash',
            );
            $snapshotJson = $this->encryption->decryptFor(
                $this->stringField(
                    $annualRevision,
                    'snapshot_ciphertext',
                ),
                implode(':', [
                    'payroll-annual-document',
                    (string) $supplierId,
                    (string) $employeeId,
                    (string) $taxYear,
                    $purpose,
                    $manifestHash,
                ]),
            );
            $expectedHash = $this->sensitiveData->keyedFingerprint(
                $snapshotJson,
                $this->annualFingerprintDomain($purpose),
                $supplierId,
            );
            if (!hash_equals(
                $this->stringField($annualRevision, 'snapshot_hash'),
                $expectedHash,
            )) {
                throw new \UnexpectedValueException(
                    'Otisk ročního snapshotu exportu mezd nesouhlasí.',
                );
            }
            $snapshot = json_decode(
                $snapshotJson,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            if (!is_array($snapshot) || array_is_list($snapshot)) {
                throw new \UnexpectedValueException(
                    'Roční snapshot exportu mezd není objekt JSON.',
                );
            }
            $annualRevision['snapshot_json'] = $snapshot;
            unset($annualRevision['snapshot_ciphertext']);
        }
        unset($annualRevision);
        $source['data']['annual_revisions'] = $annualRevisions;
        $entries = [];
        foreach ($source['documents'] as $document) {
            $documentId = $this->integerField($document, 'id');
            $storageKey = $this->stringField($document, 'storage_key');
            $fileHash = $this->stringField($document, 'file_sha256');
            $fileSize = $this->integerField($document, 'size_bytes');
            $mimeType = $this->stringField($document, 'mime_type');
            $bytes = $this->documents->readVerified(
                $supplierId,
                $storageKey,
            );
            $this->assertArchivedBytes(
                $bytes,
                $fileHash,
                $fileSize,
            );
            $entries[] = new PayrollPeriodExportEntry(
                sprintf(
                    'documents/document-%012d.%s',
                    $documentId,
                    $this->extension($mimeType),
                ),
                $bytes,
                $mimeType,
                'payroll_document',
                $documentId,
            );
            unset($document['storage_key']);
        }
        foreach ($source['artifacts'] as $artifact) {
            $artifactId = $this->integerField($artifact, 'id');
            $submissionId = $this->integerField(
                $artifact,
                'submission_id',
            );
            $environment = $this->stringField($artifact, 'environment');
            $artifactHash = $this->stringField(
                $artifact,
                'artifact_sha256',
            );
            $artifactSize = $this->integerField($artifact, 'byte_size');
            $mimeType = $this->stringField($artifact, 'mime_type');
            $bytes = $this->submissions->artifactBytes(
                $supplierId,
                $artifactId,
            );
            $this->assertArchivedBytes(
                $bytes,
                $artifactHash,
                $artifactSize,
            );
            $entries[] = new PayrollPeriodExportEntry(
                sprintf(
                    'submissions/%s/submission-%012d/artifact-%012d.%s',
                    $environment,
                    $submissionId,
                    $artifactId,
                    $this->extension($mimeType),
                ),
                $bytes,
                $mimeType,
                'submission_artifact',
                $artifactId,
            );
        }
        foreach ($source['protocols'] as &$protocol) {
            $payload = $protocol['payload_xml'] ?? null;
            if (!is_string($payload) || $payload === '') {
                throw new \UnexpectedValueException(
                    'Archivovaný protokol nemá platný obsah.',
                );
            }
            $protocolId = $this->integerField($protocol, 'id');
            $environment = $this->stringField($protocol, 'environment');
            $this->assertArchivedBytes(
                $payload,
                $this->stringField($protocol, 'payload_sha256'),
                strlen($payload),
            );
            $entries[] = new PayrollPeriodExportEntry(
                sprintf(
                    'protocols/%s/jmhz-protocol-%012d.xml',
                    $environment,
                    $protocolId,
                ),
                $payload,
                'application/xml',
                'submission_protocol',
                $protocolId,
            );
            unset($protocol['payload_xml']);
        }
        unset($protocol);

        $source['data']['documents'] = array_map(
            static function (array $document): array {
                unset($document['storage_key']);

                return $document;
            },
            $source['documents'],
        );
        $source['data']['submission_artifacts'] = $source['artifacts'];
        $source['data']['imported_protocols'] = $source['protocols'];
        $archive = $this->builder->build($source['data'], $entries);

        $stored = $this->storage->store($supplierId, $archive['bytes']);
        // Content-addressed blob může ve stejný okamžik převzít jiný request.
        // Při DB chybě jej proto nemažeme; bezpečný šifrovaný orphan později
        // odstraní GC, zatímco smazání by mohlo rozbít již zapsaný cizí export.
        $record = $this->repository->insertOrGet([
            'supplier_id' => $supplierId,
            'export_scope' => $scope->value,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'source_manifest_hash' => $archive['source_manifest_hash'],
            'manifest_json' => CanonicalJson::encode($archive['manifest']),
            'file_sha256' => $stored['file_sha256'],
            'size_bytes' => $stored['size_bytes'],
            'mime_type' => 'application/zip',
            'storage_key' => $stored['storage_key'],
            'suggested_filename' => $archive['suggested_filename'],
            'created_by' => $userId,
        ]);

        return $record;
    }

    private function assertArchivedBytes(
        string $bytes,
        string $expectedHash,
        int $expectedSize,
    ): void {
        if (strlen($bytes) !== $expectedSize
            || !hash_equals($expectedHash, hash('sha256', $bytes))
        ) {
            throw new \UnexpectedValueException(
                'Archivovaný mzdový podklad nemá platnou integritu.',
            );
        }
    }

    private function extension(string $mimeType): string
    {
        return match (strtolower(trim(explode(';', $mimeType)[0]))) {
            'application/pdf' => 'pdf',
            'application/xml', 'text/xml' => 'xml',
            'application/json' => 'json',
            'application/zip' => 'zip',
            'text/plain' => 'txt',
            default => 'bin',
        };
    }

    private function annualFingerprintDomain(string $purpose): string
    {
        $domain = self::ANNUAL_FINGERPRINT_DOMAINS[$purpose] ?? null;
        if ($domain === null) {
            throw new \UnexpectedValueException(
                'Účel ročního snapshotu exportu mezd není podporovaný.',
            );
        }

        return $domain;
    }

    /** @param array<array-key,mixed> $row */
    private function integerField(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Pole {$field} exportu mezd není celé číslo.",
            );
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new \UnexpectedValueException(
                "Pole {$field} exportu mezd není celé číslo.",
            );
        }

        return $integer;
    }

    /** @param array<array-key,mixed> $row */
    private function stringField(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Pole {$field} exportu mezd není neprázdný text.",
            );
        }

        return $value;
    }
}
