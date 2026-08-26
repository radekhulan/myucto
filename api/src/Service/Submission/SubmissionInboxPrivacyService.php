<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Deletion\DocumentDeletionGuard;
use MyInvoice\Repository\DocumentFileRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

final readonly class SubmissionInboxPrivacyService
{
    public function __construct(
        private Connection $db,
        private SubmissionInboxRepository $inbox,
        private DocumentRepository $documents,
        private DocumentFileRepository $files,
        private DocumentDeletionGuard $deletionGuard,
        private DocumentStorage $storage,
    ) {}

    /** @return array<string,mixed> */
    public function hide(
        int $supplierId,
        int $messageId,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $this->assertEligible($supplierId, $messageId);
        if (!$this->inbox->setHidden($supplierId, $messageId, true, $expectedVersion, $userId)) {
            throw $this->conflict();
        }
        return $this->requireMessage($supplierId, $messageId);
    }

    /** @return array<string,mixed> */
    public function restore(
        int $supplierId,
        int $messageId,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $this->assertEligible($supplierId, $messageId);
        if (!$this->inbox->setHidden($supplierId, $messageId, false, $expectedVersion, $userId)) {
            throw $this->conflict();
        }
        return $this->requireMessage($supplierId, $messageId);
    }

    /** @return array<string,mixed> */
    public function purgeLocalContent(
        int $supplierId,
        int $messageId,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $this->assertEligible($supplierId, $messageId);
        $message = $this->requireMessage($supplierId, $messageId);
        if ((string) $message['local_content_state'] === 'purged') {
            throw $this->conflict();
        }

        $documentRows = [];
        $fileRows = [];
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $documentId = $message['document_id'] !== null
                ? (int) $message['document_id']
                : null;
            if ($documentId !== null) {
                $documentRows = $this->documents->privacyPurgeRows($supplierId, $documentId);
                $documentIds = array_column($documentRows, 'id');
                $fileRows = $this->files->listForPrivacyPurge($supplierId, $documentIds);
                if ($documentRows !== []) {
                    $this->documents->softDelete($documentId, $supplierId, $userId);
                    $blocked = $this->deletionGuard->blockedTrashDocuments($supplierId, $documentIds);
                    if ($blocked !== []) {
                        throw new SubmissionChannelException(
                            'isds_inbox_local_content_has_dependencies',
                            'Staženou zprávu nelze odstranit, protože její dokument nebo příloha už je navázaná na jinou agendu.',
                            409,
                        );
                    }
                    $survivors = $this->documents->hardDeleteTrashedByIds(
                        $supplierId,
                        DocumentViewerContext::admin($userId),
                        $documentIds,
                    );
                    if ($survivors !== []) {
                        throw new SubmissionChannelException(
                            'isds_inbox_local_content_delete_conflict',
                            'Staženou zprávu mezitím navázala jiná agenda. Odstranění nebylo provedeno.',
                            409,
                        );
                    }
                }
            }
            if (!$this->inbox->markLocalContentPurged(
                $supplierId,
                $messageId,
                $expectedVersion,
                $userId,
            )) {
                throw $this->conflict();
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($documentRows as $row) {
            $this->storage->deleteIfOrphan(
                $supplierId,
                $row['sha256'],
                $row['filename'],
                $row['thumb_path'],
                $this->documents,
                [],
            );
        }
        foreach ($fileRows as $row) {
            $this->storage->deleteIfOrphan(
                $supplierId,
                (string) $row['sha256'],
                (string) $row['filename'],
                null,
                $this->documents,
                [],
            );
        }
        $this->storage->pruneEmptyDirs($supplierId);

        return $this->requireMessage($supplierId, $messageId);
    }

    private function assertEligible(int $supplierId, int $messageId): void
    {
        $blockers = $this->inbox->privacyBlockers($supplierId, $messageId);
        if ($blockers === ['not_found']) {
            throw new SubmissionChannelException(
                'isds_inbox_message_not_found',
                'Příchozí zpráva nebyla nalezena.',
                404,
            );
        }
        if ($blockers !== []) {
            throw new SubmissionChannelException(
                'isds_inbox_message_has_business_link',
                'Zpráva je zařazená nebo navázaná na podání či výzvu. Její obsah a hlavičku proto nelze skrýt ani odstranit touto cestou.',
                409,
            );
        }
    }

    /** @return array<string,mixed> */
    private function requireMessage(int $supplierId, int $messageId): array
    {
        return $this->inbox->findById($supplierId, $messageId)
            ?? throw new SubmissionChannelException(
                'isds_inbox_message_not_found',
                'Příchozí zpráva nebyla nalezena.',
                404,
            );
    }

    private function conflict(): SubmissionChannelException
    {
        return new SubmissionChannelException(
            'isds_inbox_privacy_conflict',
            'Zprávu mezitím změnil jiný uživatel. Načtěte seznam znovu.',
            409,
        );
    }
}
