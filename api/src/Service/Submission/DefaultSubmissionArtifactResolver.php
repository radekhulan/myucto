<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamCooperationArtifactStore;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Vydá bajty artefaktu z toho místa, kde už v aplikaci žijí.
 *
 * Nic nekopíruje ani nepředělává: daňové přiznání čte z `tax_submissions`,
 * mzdový artefakt přes {@see PayrollSubmissionService::artifactBytes()}
 * (a tedy i s jeho dešifrováním a kontrolou otisku), dokument ze složky
 * Dokumenty. Fronta podání tak nemá vlastní úložiště, které by mohlo
 * zestárnout proti originálu.
 */
final readonly class DefaultSubmissionArtifactResolver implements SubmissionArtifactResolver
{
    public function __construct(
        private Connection $db,
        private DocumentStorage $storage,
        private PayrollSubmissionService $payroll,
        private XmlzamCooperationArtifactStore $xmlzam,
        private LoggerInterface $logger,
    ) {}

    public function resolve(int $supplierId, string $artifactKind, int $artifactId): ?array
    {
        return match ($artifactKind) {
            'tax_submission' => $this->taxSubmission($supplierId, $artifactId),
            'payroll_submission' => $this->payrollArtifact($supplierId, $artifactId),
            'payroll_xmlzam' => $this->xmlzam->resolve($supplierId, $artifactId),
            'document' => $this->document($supplierId, $artifactId),
            default => null,
        };
    }

    /** @return array{filename:string,mime:string,bytes:string}|null */
    private function taxSubmission(int $supplierId, int $id): ?array
    {
        if (!$this->db->hasTable('tax_submissions')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT form_code, period_year, period_month, period_quarter, xml_content, xml_sha256
               FROM tax_submissions WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $period = (string) $row['period_year'];
        if ($row['period_month'] !== null) {
            $period .= '-' . str_pad((string) $row['period_month'], 2, '0', STR_PAD_LEFT);
        } elseif ($row['period_quarter'] !== null) {
            $period .= '-Q' . $row['period_quarter'];
        }

        return [
            'filename' => strtolower((string) $row['form_code']) . '-' . $period . '.xml',
            'mime' => 'application/xml',
            'bytes' => (string) $row['xml_content'],
        ];
    }

    /**
     * @return array{
     *   filename:string,mime:string,bytes:string,
     *   authority:array{
     *     kind:string,environment:string,agenda_code:string,status:string,
     *     channel:string,artifact_kind:string,direction:string
     *   }
     * }|null
     */
    private function payrollArtifact(int $supplierId, int $id): ?array
    {
        if (!$this->db->hasTable('payroll_submission_artifacts')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT artifact.artifact_kind, artifact.direction,
                    artifact.mime_type, artifact.submission_id,
                    artifact.xsd_version,
                    submission.environment, submission.status,
                    submission.channel, obligation.agenda_code
               FROM payroll_submission_artifacts artifact
               JOIN payroll_submissions submission
                 ON submission.supplier_id = artifact.supplier_id
                AND submission.environment = artifact.environment
                AND submission.id = artifact.submission_id
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE artifact.id = ? AND artifact.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        try {
            // Dešifrování i kontrola otisku zůstávají tam, kde už jsou —
            // druhá implementace téhož by se rozešla.
            $bytes = $this->payroll->artifactBytes($supplierId, $id);
        } catch (\Throwable $e) {
            $this->logger->warning('Payroll artifact could not be read for submission outbox', [
                'supplier_id' => $supplierId,
                'artifact_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $mime = (string) $row['mime_type'];
        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            default => 'xml',
        };

        return [
            'filename' => 'mzdove-podani-' . (int) $row['submission_id'] . '-' . $id . '.' . $extension,
            'mime' => $mime !== '' ? $mime : 'application/xml',
            'bytes' => $bytes,
            'authority' => [
                'kind' => 'payroll_submission',
                'environment' => (string) $row['environment'],
                'agenda_code' => (string) $row['agenda_code'],
                'status' => (string) $row['status'],
                'channel' => (string) $row['channel'],
                'artifact_kind' => (string) $row['artifact_kind'],
                'direction' => (string) $row['direction'],
                // Verze XSD, proti kterému se artefakt ověřil při MRAZENÍ.
                // Nese ji {@see SubmissionArtifactValidator::assertTransportAuthority()}
                // jako důkaz, že podklad prošel schématem dřív, než se zmrazil.
                'xsd_version' => $row['xsd_version'] === null
                    ? null
                    : (string) $row['xsd_version'],
            ],
        ];
    }

    /** @return array{filename:string,mime:string,bytes:string}|null */
    private function document(int $supplierId, int $id): ?array
    {
        if (!$this->db->hasTable('documents')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT original_name, filename, sha256, mime_type
               FROM documents WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $path = $this->storage->pathFor($supplierId, (string) $row['sha256'], (string) $row['filename']);
        if (!is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        return [
            'filename' => (string) ($row['original_name'] ?: $row['filename']),
            'mime' => (string) ($row['mime_type'] ?: 'application/octet-stream'),
            'bytes' => $bytes,
        ];
    }
}
