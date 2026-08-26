<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\Submission\SubmissionInboxStorageSettingsRepository;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use PDOException;

final readonly class SubmissionInboxStorageSettingsService
{
    private const ENVIRONMENTS = ['production', 'test'];

    public function __construct(
        private SubmissionInboxStorageSettingsRepository $repository,
        private DocumentFolderRepository $folders,
        private DocumentIngestService $documents,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId): array
    {
        return $this->repository->list($supplierId);
    }

    /** @return array<string,mixed> */
    public function save(
        int $supplierId,
        string $environment,
        int $baseFolderId,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $this->assertEnvironment($environment);
        if ($baseFolderId <= 0 || $this->folders->find($baseFolderId, $supplierId) === null) {
            throw new SubmissionChannelException(
                'isds_inbox_archive_folder_not_found',
                'Vybraná složka archivu neexistuje, patří jiné firmě nebo je v koši.',
                404,
            );
        }

        $current = $this->repository->find($supplierId, $environment);
        if ($current === null) {
            if ($expectedVersion !== 0) {
                throw $this->conflict();
            }
            try {
                $this->repository->insert($supplierId, $environment, $baseFolderId, $userId);
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                throw $this->conflict();
            }
        } elseif ((int) $current['row_version'] !== $expectedVersion
            || !$this->repository->update($supplierId, $environment, $baseFolderId, $expectedVersion, $userId)) {
            throw $this->conflict();
        }

        $saved = $this->repository->find($supplierId, $environment);
        if ($saved === null) {
            throw new \RuntimeException('Nastavení archivu se uložilo, ale nelze ho znovu načíst.');
        }
        return $saved;
    }

    public function clear(int $supplierId, string $environment, int $expectedVersion): bool
    {
        $this->assertEnvironment($environment);
        $current = $this->repository->find($supplierId, $environment);
        if ($current === null) {
            if ($expectedVersion === 0) {
                return false;
            }
            throw $this->conflict();
        }
        if ((int) $current['row_version'] !== $expectedVersion
            || !$this->repository->delete($supplierId, $environment, $expectedVersion)) {
            throw $this->conflict();
        }
        return true;
    }

    public function resolveFolder(
        int $supplierId,
        string $environment,
        InboxMessageHeader $header,
        ?int $userId,
    ): ?int {
        $this->assertEnvironment($environment);
        $setting = $this->repository->find($supplierId, $environment);
        if ($setting === null) {
            return null;
        }

        $baseFolderId = (int) $setting['base_folder_id'];
        if ($this->folders->find($baseFolderId, $supplierId) === null) {
            throw new SubmissionChannelException(
                'isds_inbox_archive_folder_unavailable',
                'Nastavená složka archivu příchozí datové schránky už není dostupná. Vyberte jinou složku.',
                409,
            );
        }

        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $header->externalMessageId) !== 1) {
            throw new SubmissionChannelException(
                'isds_message_id_invalid',
                'Datová schránka vrátila neplatný identifikátor zprávy.',
                502,
            );
        }

        $segments = ['_bez-data-dodani'];
        if ($header->deliveredAt !== null) {
            $deliveredAt = $header->deliveredAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $segments = [$deliveredAt->format('Y'), $deliveredAt->format('m'), $deliveredAt->format('d')];
        }
        $segments[] = $header->externalMessageId;

        return $this->documents->ensureFolderPath($supplierId, $baseFolderId, $segments, $userId);
    }

    private function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new SubmissionChannelException('invalid_environment', 'Neznámé prostředí.', 400);
        }
    }

    private function conflict(): SubmissionChannelException
    {
        return new SubmissionChannelException(
            'isds_inbox_archive_settings_conflict',
            'Nastavení archivu mezitím změnil jiný uživatel. Načtěte stránku znovu.',
            409,
        );
    }
}
