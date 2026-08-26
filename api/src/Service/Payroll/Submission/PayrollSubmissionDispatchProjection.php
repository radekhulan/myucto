<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use Psr\Log\LoggerInterface;

/** Promítne doložené odeslání obecné fronty do mzdového podání. */
final readonly class PayrollSubmissionDispatchProjection
{
    public function __construct(
        private PayrollSubmissionRepository $repository,
        private PayrollSubmissionService $submissions,
        private LoggerInterface $logger,
    ) {}

    public function project(
        int $supplierId,
        string $artifactKind,
        int $artifactId,
        string $externalMessageId,
    ): void {
        if ($artifactKind !== 'payroll_submission') {
            return;
        }

        try {
            $artifact = $this->repository->findArtifact(
                $supplierId,
                $artifactId,
            );
            if ($artifact === null) {
                throw new \DomainException(
                    'Mzdový artefakt odeslaného podání nebyl nalezen.',
                );
            }
            $submission = $this->submissions->get(
                $supplierId,
                (int) $artifact['submission_id'],
            );
            if ($submission['status'] !== 'ready') {
                return;
            }
            $this->submissions->transition(
                $supplierId,
                (int) $submission['id'],
                (int) $submission['row_version'],
                'submitted',
                $externalMessageId,
            );
        } catch (\Throwable $exception) {
            // Odeslání už proběhlo a nesmí se kvůli chybě projekce opakovat.
            // Fronta zůstává závazným důkazem a incident je dohledatelný v logu.
            $this->logger->error(
                'Sent payroll submission could not be projected to payroll state',
                [
                    'supplier_id' => $supplierId,
                    'artifact_id' => $artifactId,
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }
}
