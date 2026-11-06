<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Payroll\Garnishment\EnforcementTerminationNoticeDocument;
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
        private EnforcementTerminationNoticeDocument $enforcementNotices,
    ) {}

    public function resolve(int $supplierId, string $artifactKind, int $artifactId): ?array
    {
        return match ($artifactKind) {
            'tax_submission' => $this->taxSubmission($supplierId, $artifactId),
            'payroll_submission' => $this->payrollArtifact($supplierId, $artifactId),
            'payroll_xmlzam' => $this->xmlzam->resolve($supplierId, $artifactId),
            'document' => $this->document($supplierId, $artifactId),
            // Oznámení soudu / exekutorovi o skončení poměru (§ 295 odst. 2 o. s. ř.).
            'payroll_enforcement_notice' => $this->enforcementNotices->pdf($supplierId, $artifactId),
            default => null,
        };
    }

    /**
     * @return array{
     *   filename:string,mime:string,bytes:string,
     *   authority:array{
     *     kind:string,form_code:string,status:string,validation_status:string,
     *     sha256_match:bool,epo_attempt_active:bool
     *   }
     * }|null
     */
    private function taxSubmission(int $supplierId, int $id): ?array
    {
        if (!$this->db->hasTable('tax_submissions')) {
            return null;
        }
        (new AnonymizedCloneSubmissionProvenance($this->db))->assertDispatchable($supplierId, $id);
        // `epo_attempt_active` kopíruje podmínku TaxSubmissionEpoRepository::activeAttempt()
        // pro ostré prostředí: rozpracované asistované předání, nedořešený nebo potvrzený
        // přímý pokus. Jeden snapshot jde ven jen jednou cestou.
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.form_code, s.period_year, s.period_month, s.period_quarter,
                    s.xml_content, s.xml_sha256, s.status, s.validation_status,
                    EXISTS(
                        SELECT 1 FROM tax_submission_attempts a
                         WHERE a.tax_submission_id = s.id AND a.supplier_id = s.supplier_id
                           AND a.epo_environment = 'production'
                           AND (
                             (a.channel = 'epo_assisted'
                               AND a.status IN ('prepared','handoff_created','awaiting_confirmation')
                               AND (a.handoff_expires_at IS NULL OR a.handoff_expires_at > CURRENT_TIMESTAMP))
                             OR (a.channel = 'epo_direct'
                               AND a.status IN ('submitting','processing','uncertain','confirmed'))
                           )
                    ) AS epo_attempt_active
               FROM tax_submissions s WHERE s.id = ? AND s.supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        try {
            (new \MyInvoice\Service\Tax\Return\AccountingDecreeService($this->db))->assertDppoSubmissionSupported($supplierId, $row, true);
        } catch (\MyInvoice\Service\Tax\Return\TaxReturnException $e) {
            throw new \MyInvoice\Service\Submission\Channel\SubmissionChannelException($e->errorCode, $e->getMessage(), $e->httpStatus);
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
            'authority' => [
                'kind' => 'tax_submission',
                'form_code' => (string) $row['form_code'],
                'status' => (string) $row['status'],
                'validation_status' => (string) $row['validation_status'],
                'sha256_match' => hash_equals(
                    (string) $row['xml_sha256'],
                    hash('sha256', (string) $row['xml_content']),
                ),
                'epo_attempt_active' => (bool) $row['epo_attempt_active'],
            ],
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
            'filename' => $this->payroll->artifactFilename($supplierId, $id)
                ?? 'mzdove-podani-' . (int) $row['submission_id'] . '-' . $id . '.' . $extension,
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
