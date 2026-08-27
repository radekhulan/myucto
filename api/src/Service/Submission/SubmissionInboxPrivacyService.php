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
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $message = $this->inbox->findByIdForUpdate($supplierId, $messageId);
            if ($message === null) {
                throw new SubmissionChannelException(
                    'isds_inbox_message_not_found',
                    'Příchozí zpráva nebyla nalezena.',
                    404,
                );
            }
            $contentState = self::rowString($message, 'local_content_state');
            if ($contentState === 'purged') {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $message;
            }
            if (self::rowInt($message, 'lifecycle_row_version') !== $expectedVersion) {
                throw $this->conflict();
            }
            $this->assertEligible($supplierId, $messageId);

            if ($contentState === 'available') {
                $documentRows = [];
                $fileRows = [];
                $documentId = self::rowNullableInt($message, 'document_id');
                if ($documentId !== null) {
                    $documentRows = $this->documents->privacyPurgeRows($supplierId, $documentId);
                    $documentIds = array_column($documentRows, 'id');
                    $fileRows = $this->files->listForPrivacyPurge($supplierId, $documentIds);
                    $entries = [];
                    foreach ($fileRows as $row) {
                        if (!is_array($row)) {
                            throw new \UnexpectedValueException('Soubor manifestu nemá očekávaný tvar.');
                        }
                        $sha256 = $row['sha256'] ?? null;
                        $filename = $row['filename'] ?? null;
                        if (!is_string($sha256) || !is_string($filename)) {
                            throw new \UnexpectedValueException('Soubor manifestu nemá očekávaná pole.');
                        }
                        $entries[] = [
                            'sha256' => $sha256,
                            'filename' => $filename,
                            'thumb_path' => null,
                        ];
                    }
                    $this->inbox->createPurgeManifest(
                        $supplierId,
                        $messageId,
                        array_merge($documentRows, $entries),
                    );
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
                            DocumentViewerContext::internalInboxPrivacyPurge(
                                $userId,
                            ),
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
                if (!$this->inbox->beginLocalContentPurge(
                    $supplierId,
                    $messageId,
                    $expectedVersion,
                )) {
                    throw $this->conflict();
                }
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($this->inbox->pendingPurgeManifest($supplierId, $messageId) as $entry) {
            $result = $this->storage->resolvePrivacyPurgeEntry(
                $supplierId,
                $entry['sha256'],
                $entry['internal_filename'],
                $entry['thumb_filename'],
                $this->documents,
            );
            $this->inbox->resolvePurgeManifestEntry(
                $supplierId,
                $entry['id'],
                $result['status'],
                $result['error'],
            );
        }
        $this->storage->pruneEmptyDirs($supplierId);
        $this->inbox->finishLocalContentPurge($supplierId, $messageId, $userId);

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

    /** @param array<string,mixed> $row */
    private static function rowString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Pole {$field} není text.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function rowInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new \UnexpectedValueException("Pole {$field} není celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private static function rowNullableInt(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : self::rowInt($row, $field);
    }
}
