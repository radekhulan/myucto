<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Service\ActivityLogger;

final class PurchaseInvoiceSubmissionDeletionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly DocumentRepository $documents,
        private readonly DocumentRequestRepository $requests,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Smí klient v portálu své předání smazat? Ano, dokud ho účetní nezačala
     * zpracovávat, a také když ho odmítla nebo vrátila k doplnění: z takového
     * předání doklad nevznikl a klient ho jinak nemá jak z přehledu odklidit.
     * Nikdy ne, když na originál vede doklad, vazba nebo vyřízený požadavek.
     */
    public static function canClientDelete(array $row, ?int $ownerId): bool
    {
        $status = (string) ($row['status'] ?? '');
        return ($ownerId === null || ($ownerId > 0 && (int) ($row['submitted_by'] ?? 0) === $ownerId))
            && (in_array($status, ['rejected', 'needs_information'], true)
                || ($status === 'submitted' && empty($row['processing_started_at'])))
            && empty($row['purchase_invoice_id'])
            && (int) ($row['document_link_count'] ?? 0) === 0
            && (int) ($row['resolved_request_count'] ?? 0) === 0
            && ($row['extraction_status'] ?? '') !== 'running';
    }

    public function delete(
        int $id,
        int $supplierId,
        int $userId,
        ?int $ownerId,
        bool $clientScope,
        ?string $ip,
        string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT submission_delete');
        try {
            $row = $this->submissions->findForUpdate($id, $supplierId);
            if ($row === null || ($ownerId !== null && ($ownerId <= 0 || (int) ($row['submitted_by'] ?? 0) !== $ownerId))) {
                throw new PurchaseInvoiceSubmissionException('not_found', 'Podání nebylo nalezeno.', 404);
            }
            if ($clientScope && !self::canClientDelete($row, $ownerId)) {
                throw new PurchaseInvoiceSubmissionException('invalid_status', 'Předání, které účetní zpracovává nebo z něj vznikl doklad, smazat nelze.', 409);
            }
            $this->requests->reopenBySubmission($id, $supplierId);
            $deleted = $this->submissions->deleteFromQueue($id, $supplierId, $ownerId, $clientScope);
            if ($deleted === null) {
                throw new PurchaseInvoiceSubmissionException('invalid_status', 'Zpracované ani právě zpracovávané podání smazat nelze.', 409);
            }
            $this->documents->softDelete((int) $deleted['document_id'], $supplierId, $userId ?: null);
            $this->logger->log('purchase_invoice_submission.deleted', $userId ?: null, 'purchase_invoice_submission', $id, [
                'document_id' => (int) $deleted['document_id'],
                'filename' => (string) $deleted['original_name'],
                'status' => (string) $deleted['status'],
                'via' => (string) $deleted['submitted_via'],
            ], $ip, $userAgent, $supplierId);
            if ($ownTransaction) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT submission_delete');
            return ['ok' => true, 'document_id' => (int) $deleted['document_id']];
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            elseif (!$ownTransaction && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT submission_delete');
                $pdo->exec('RELEASE SAVEPOINT submission_delete');
            }
            throw $e;
        }
    }
}
