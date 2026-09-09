<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Pricing\PricingInputException;

final class CatalogImportWorker
{
    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly CatalogImportSourceStore $sources,
        private readonly CatalogImportReader $reader,
        private readonly CatalogImportWriter $writer,
    ) {}

    public function tickKind(int $supplierId, string $kind, int $maxBatches = 10): ?array
    {
        if (!in_array($kind, [CatalogImportService::STAGE_KIND, CatalogImportService::APPLY_KIND], true)) {
            throw new \InvalidArgumentException('import_kind_invalid');
        }
        $job = $this->jobs->claim($supplierId, $kind);
        if ($job === null) {
            return null;
        }
        $token = $job['lease_token'];
        try {
            $rows = null;
            $header = [];
            if ($kind === CatalogImportService::STAGE_KIND) {
                $source = $this->sources->get($supplierId, $job['input']['source_id']);
                $header = $job['report']['header'] ?? [];
                $resume = $job['checkpoint'] > 0 && $header !== [];
                $rows = $this->reader->rows($this->sources->path($supplierId, $source['id']), $source['format'],
                    $job['input']['profile']['reader'], $resume ? $job['checkpoint'] + 1 : 0);
                if (!$resume) {
                    if (!$rows->valid()) {
                        throw new \InvalidArgumentException('import_file_empty');
                    }
                    $header = $rows->current();
                    $rows->next();
                    while ($rows->valid() && $rows->key() <= $job['checkpoint'] + 1) {
                        $rows->next();
                    }
                }
            }
            for ($batch = 0; $batch < max(1, min(10, $maxBatches)); $batch++) {
                $job = $this->jobs->batch($supplierId, $job['id'], $token,
                    fn (array $current): array => $kind === CatalogImportService::STAGE_KIND
                        ? $this->stage($supplierId, $current, $header, $rows)
                        : $this->apply($supplierId, $current));
                if ($job['status'] !== 'running') {
                    return $job;
                }
            }
            $this->jobs->release($supplierId, $job['id'], $token);
            return $this->jobs->find($supplierId, $job['id']);
        } catch (\Throwable $e) {
            $this->jobs->fail($supplierId, $job['id'], $token, 'catalog_import_failed');
            throw $e;
        }
    }

    private function stage(int $supplierId, array $job, array $header, \Generator $rows): array
    {
        $checkpoint = $job['checkpoint'];
        $profile = $job['input']['profile'];
        for ($i = 0; $i < 100 && $rows->valid(); $i++, $rows->next()) {
            $checkpoint = $rows->key() - 1;
            $raw = $rows->current();
            $before = null;
            $after = null;
            $values = [];
            $identity = null;
            $error = null;
            $status = 'failed';
            try {
                $values = CatalogImportProfile::map($profile, $header, $raw);
                $identity = $profile['identity'] === 'sku' ? $values['sku'] : hash('sha256', (string) $values[$profile['identity']]);
                $existing = $this->writer->identify($supplierId, $profile, $values);
                $before = $existing === null ? null : $this->writer->state($supplierId, (int) $existing['id']);
                $after = $this->trial($supplierId, $profile, $values, $before);
                $status = $before !== null && $this->writer->comparable($before) === $this->writer->comparable($after) ? 'unchanged' : 'ready';
            } catch (EshopException|PricingInputException $e) {
                $error = $e->errorCode;
            } catch (\InvalidArgumentException $e) {
                $error = preg_match('/^[a-z0-9_]{1,100}$/D', $e->getMessage()) ? $e->getMessage() : 'import_validation_failed';
            } catch (\PDOException) {
                $error = 'import_database_validation_failed';
            }
            $this->items->append($supplierId, $job['id'], [['ordinal' => $checkpoint,
                'source_row' => $rows->key(), 'stock_item_id' => $before['id'] ?? null,
                'expected_version' => $before['row_version'] ?? null,
                'input' => ['raw' => $raw, 'values' => $values, 'identity' => $identity]]]);
            $this->items->finish($supplierId, $job['id'], $checkpoint, $status, $before, $after, $error);
        }
        $done = !$rows->valid();
        if ($done) {
            $pdo = $this->db->pdo();
            $pdo->prepare("UPDATE catalog_job_items target JOIN (
                SELECT ordinal, COUNT(*) OVER (PARTITION BY JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.identity')) COLLATE utf8mb4_unicode_ci) AS copies
                FROM catalog_job_items WHERE supplier_id = ? AND job_id = ?
                    AND JSON_TYPE(JSON_EXTRACT(input_json, '$.identity')) = 'STRING'
            ) duplicates ON duplicates.ordinal = target.ordinal
                SET target.status = 'failed', target.error_code = 'import_duplicate_identity'
                WHERE target.supplier_id = ? AND target.job_id = ? AND duplicates.copies > 1")
                ->execute([$supplierId, $job['id'], $supplierId, $job['id']]);
            $pdo->prepare('UPDATE catalog_jobs SET total = ? WHERE supplier_id = ? AND id = ?')->execute([$checkpoint, $supplierId, $job['id']]);
        }
        return ['checkpoint' => $checkpoint, 'done' => $done,
            'report' => ['processed' => $checkpoint, 'stage' => 'validation', 'header' => $header, 'counts' => $this->items->counts($supplierId, $job['id'])]];
    }

    private function trial(int $supplierId, array $profile, array $values, ?array $before): array
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT catalog_import_trial');
        try {
            return $this->writer->write($supplierId, $profile, $values, $before['id'] ?? null, $before['row_version'] ?? null);
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT catalog_import_trial');
            $pdo->exec('RELEASE SAVEPOINT catalog_import_trial');
        }
    }

    private function apply(int $supplierId, array $job): array
    {
        $batch = $this->items->batch($supplierId, $job['id'], $job['checkpoint'], 100);
        foreach ($batch as $item) {
            if ($item['status'] !== 'pending') {
                continue;
            }
            $pdo = $this->db->pdo();
            $pdo->exec('SAVEPOINT catalog_import_apply');
            try {
                $after = $this->writer->write($supplierId, $job['input']['profile'], $item['input']['values'], $item['stock_item_id'], $item['expected_version']);
                if ($this->writer->comparable($after) !== $this->writer->comparable($item['after'])) {
                    throw new EshopException('import_input_changed', 'Výsledek již neodpovídá náhledu. Vytvořte nový náhled.', 409);
                }
                $this->items->finish($supplierId, $job['id'], $item['ordinal'], 'applied', $item['before'], $after);
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT catalog_import_apply');
                $code = $e instanceof EshopException || $e instanceof PricingInputException ? $e->errorCode : 'import_write_failed';
                $this->items->finish($supplierId, $job['id'], $item['ordinal'], in_array($code, ['version_conflict', 'import_input_changed'], true) ? 'conflict' : 'failed', $item['before'], $item['after'], $code);
            } finally {
                $pdo->exec('RELEASE SAVEPOINT catalog_import_apply');
            }
        }
        $checkpoint = $batch === [] ? $job['checkpoint'] : end($batch)['ordinal'];
        return ['checkpoint' => $checkpoint, 'done' => $checkpoint === $job['total'],
            'report' => ['processed' => $checkpoint, 'counts' => $this->items->counts($supplierId, $job['id'])]];
    }
}
