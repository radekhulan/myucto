<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Eshop\CatalogJobService;

final class CatalogMediaImportService
{
    public const KIND = 'catalog_import_media';

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly SecretEncryption $encryption,
        private readonly CatalogMediaFetcher $fetcher,
    ) {}

    /** @return list<array{index:int,source_ordinal:int,url_hash:string,url_ciphertext:string}> */
    public function sealUrls(int $supplierId, int $stageJobId, int $ordinal, array $urls): array
    {
        $sealed = [];
        foreach ($urls as $index => $url) {
            if (!is_string($url)) {
                throw new \InvalidArgumentException('media_url_invalid');
            }
            $this->fetcher->assertSyntax($url);
            $context = self::context($supplierId, $stageJobId, $ordinal, $index);
            $sealed[] = [
                'index' => $index,
                'source_ordinal' => $ordinal,
                'url_hash' => hash('sha256', $url),
                'url_ciphertext' => $this->encryption->encryptFor($url, $context),
            ];
        }
        return $sealed;
    }

    public function decryptUrl(int $supplierId, int $stageJobId, int $sourceOrdinal, array $entry): string
    {
        $index = (int) ($entry['index'] ?? -1);
        $sealedOrdinal = (int) ($entry['source_ordinal'] ?? 0);
        $ciphertext = $entry['url_ciphertext'] ?? null;
        if ($index < 0 || $sealedOrdinal < 1 || $sealedOrdinal !== $sourceOrdinal
            || !is_string($ciphertext) || !is_string($entry['url_hash'] ?? null)) {
            throw new \RuntimeException('media_input_invalid');
        }
        $url = $this->encryption->decryptFor($ciphertext, self::context($supplierId, $stageJobId, $sourceOrdinal, $index));
        if (!hash_equals((string) $entry['url_hash'], hash('sha256', $url))) {
            throw new \RuntimeException('media_input_invalid');
        }
        return $url;
    }

    public function enqueueFromApply(int $supplierId, array $applyJob): ?int
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Media job must be linked in the apply transaction.');
        }
        $stageJobId = (int) ($applyJob['input']['source_job_id'] ?? 0);
        if ($stageJobId < 1) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare("SELECT id FROM catalog_jobs WHERE supplier_id = ? AND kind = ?
            AND JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.source_job_id')) = ? LIMIT 1");
        $stmt->execute([$supplierId, self::KIND, (string) $applyJob['id']]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $count = $this->db->pdo()->prepare("SELECT COALESCE(SUM(JSON_LENGTH(JSON_EXTRACT(input_json, '$.media'))), 0)
            FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'applied'");
        $count->execute([$supplierId, $applyJob['id']]);
        $total = (int) $count->fetchColumn();
        if ($total === 0) {
            return null;
        }
        $jobId = $this->jobs->enqueue($supplierId, self::KIND, [
            'source_job_id' => (int) $applyJob['id'],
            'preview_job_id' => $stageJobId,
        ], $total, createdBy: $applyJob['created_by']);

        $pending = [];
        $ordinal = 0;
        $afterOrdinal = 0;
        $stmt = $this->db->pdo()->prepare("SELECT ordinal, source_row, after_json, input_json FROM catalog_job_items
            WHERE supplier_id = ? AND job_id = ? AND status = 'applied' AND ordinal > ?
            ORDER BY ordinal LIMIT 100");
        do {
            $stmt->execute([$supplierId, $applyJob['id'], $afterOrdinal]);
            $page = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($page as $row) {
                $afterOrdinal = (int) $row['ordinal'];
                $input = json_decode($row['input_json'], true, 512, JSON_THROW_ON_ERROR);
                $after = json_decode($row['after_json'], true, 512, JSON_THROW_ON_ERROR);
                foreach (($input['media'] ?? []) as $entry) {
                    $sourceOrdinal = (int) ($entry['source_ordinal'] ?? 0);
                    if ($sourceOrdinal < 1) {
                        throw new \RuntimeException('media_input_invalid');
                    }
                    $pending[] = [
                        'ordinal' => ++$ordinal,
                        'source_row' => isset($row['source_row']) ? (int) $row['source_row'] : null,
                        'stock_item_id' => (int) ($after['id'] ?? 0),
                        'input' => [
                            'stage_job_id' => $stageJobId,
                            'source_ordinal' => $sourceOrdinal,
                            'media' => $entry,
                        ],
                    ];
                    if (count($pending) === 1000) {
                        $this->items->append($supplierId, $jobId, $pending);
                        $pending = [];
                    }
                }
            }
        } while (count($page) === 100);
        if ($pending !== []) {
            $this->items->append($supplierId, $jobId, $pending);
        }
        if ($ordinal !== $total) {
            throw new \RuntimeException('media_job_total_mismatch');
        }
        return $jobId;
    }

    private static function context(int $supplierId, int $stageJobId, int $ordinal, int $index): string
    {
        return 'catalog-media-url:' . $supplierId . ':' . $stageJobId . ':' . $ordinal . ':' . $index;
    }
}
