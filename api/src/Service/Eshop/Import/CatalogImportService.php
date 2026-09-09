<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;

final class CatalogImportService
{
    public const STAGE_KIND = 'catalog_import_stage';
    public const APPLY_KIND = 'catalog_import_apply';

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogImportSourceStore $sources,
    ) {}

    public function preview(int $supplierId, int $sourceId, array $config, ?int $createdBy): array
    {
        $source = $this->sources->get($supplierId, $sourceId);
        $config = CatalogImportProfile::normalize($config);
        $id = $this->jobs->enqueue($supplierId, self::STAGE_KIND, ['source_id' => $sourceId,
            'source_sha256' => $source['sha256'], 'profile' => $config], CatalogImportReader::MAX_ROWS, createdBy: $createdBy);
        return $this->jobs->find($supplierId, $id);
    }

    public function apply(int $supplierId, int $previewId, ?int $createdBy): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Import apply requires its own transaction.');
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM catalog_jobs WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $stmt->execute([$supplierId, $previewId]);
            $source = $stmt->fetchColumn() === false ? null : $this->jobs->find($supplierId, $previewId);
            if ($source === null || $source['kind'] !== self::STAGE_KIND) {
                throw new EshopException('not_found', 'Náhled importu nenalezen.', 404);
            }
            if ($source['status'] !== 'completed') {
                throw new EshopException('job_state_conflict', 'Nejprve dokončete validaci celého souboru.', 409);
            }
            $stmt = $pdo->prepare("SELECT id FROM catalog_jobs WHERE supplier_id = ? AND kind = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.source_job_id')) = ? LIMIT 1");
            $stmt->execute([$supplierId, self::APPLY_KIND, (string) $previewId]);
            if ($stmt->fetchColumn() !== false) {
                throw new EshopException('job_state_conflict', 'Aplikace tohoto náhledu již existuje.', 409);
            }
            $id = $this->jobs->enqueue($supplierId, self::APPLY_KIND, $source['input'] + ['source_job_id' => $previewId], createdBy: $createdBy);
            $stmt = $pdo->prepare("INSERT INTO catalog_job_items
                (supplier_id, job_id, ordinal, stock_item_id, source_row, expected_version, input_json, before_json, after_json)
                SELECT ?, ?, ROW_NUMBER() OVER (ORDER BY ordinal), stock_item_id, source_row, expected_version,
                    input_json, before_json, after_json FROM catalog_job_items
                WHERE supplier_id = ? AND job_id = ? AND status = 'ready' ORDER BY ordinal");
            $stmt->execute([$supplierId, $id, $supplierId, $previewId]);
            $total = $stmt->rowCount();
            $pdo->prepare('UPDATE catalog_jobs SET total = ? WHERE supplier_id = ? AND id = ?')->execute([$total, $supplierId, $id]);
            $pdo->commit();
            return $this->jobs->find($supplierId, $id);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
