<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Repository\StockCurrencyRepository;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\CatalogSelectionService;
use MyInvoice\Service\Eshop\EshopException;

final class PriceMatrixService
{
    public const PREVIEW_KIND = 'price_matrix_preview';
    public const APPLY_KIND = 'price_matrix_apply';

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogSelectionService $selection,
        private readonly CatalogJobItemRepository $items,
        private readonly StockCurrencyRepository $currencies,
        private readonly CatalogPriceJobService $priceJobs,
    ) {}

    public function preview(int $supplierId, array $selection, array $options, ?int $createdBy = null): array
    {
        $request = $this->normalize($supplierId, $options);
        $normalizedSelection = CatalogSelectionService::normalize($selection);
        $currencyCount = count($request['currencies']);
        if (!$normalizedSelection['all_matching'] && count($normalizedSelection['ids']) * $currencyCount > PriceMatrixCsv::MAX_ROWS) {
            throw new \InvalidArgumentException('Jeden náhled smí obsahovat nejvýše 90 000 buněk cenové matice.');
        }
        $snapshot = $this->priceJobs->snapshot($supplierId, $request['on_date']);
        $id = $this->selection->enqueue($supplierId, self::PREVIEW_KIND, [
            'request' => $request,
            'snapshot' => [
                'on_date' => $snapshot->onDate,
                'rates' => $snapshot->rates,
                'pricing_policy' => $snapshot->pricingPolicy,
            ],
        ], $normalizedSelection, $createdBy, min(
            CatalogSelectionService::MAXIMUM,
            intdiv(PriceMatrixCsv::MAX_ROWS, $currencyCount),
        ));
        return $this->jobs->find($supplierId, $id) ?? [];
    }

    public function apply(int $supplierId, int $previewId, ?int $createdBy = null): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Odvození cenové matice vyžaduje samostatnou transakci.');
        }
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM catalog_jobs WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $previewId]);
            $source = $lock->fetchColumn() === false ? null : $this->jobs->find($supplierId, $previewId);
            if ($source === null || $source['kind'] !== self::PREVIEW_KIND) {
                throw new EshopException('not_found', 'Náhled cenové matice nebyl nalezen.', 404);
            }
            if ($source['status'] !== 'completed') {
                throw new EshopException('job_state_conflict', 'Náhled cenové matice ještě není dokončen.', 409);
            }
            $duplicate = $pdo->prepare("SELECT id FROM catalog_jobs WHERE supplier_id = ? AND kind = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.source_job_id')) = ?
                AND status IN ('queued','running','completed') LIMIT 1");
            $duplicate->execute([$supplierId, self::APPLY_KIND, (string) $previewId]);
            if ($duplicate->fetchColumn() !== false) {
                throw new EshopException('job_state_conflict', 'Tento náhled už má navazující zápis.', 409);
            }
            $id = $this->jobs->enqueue($supplierId, self::APPLY_KIND, [
                'source_job_id' => $previewId,
                'request' => $source['input']['request'],
                'snapshot' => $source['input']['snapshot'],
            ], createdBy: $createdBy);
            $copy = $pdo->prepare('INSERT INTO catalog_job_items
                (supplier_id, job_id, ordinal, stock_item_id, source_row, expected_version, input_json)
                SELECT ?, ?, ROW_NUMBER() OVER (ORDER BY source.ordinal), source.stock_item_id, source.ordinal,
                    source.expected_version, JSON_OBJECT("before", JSON_EXTRACT(source.before_json, "$"),
                    "after", JSON_EXTRACT(source.after_json, "$"))
                FROM catalog_job_items source
                WHERE source.supplier_id = ? AND source.job_id = ? AND source.status = "ready"
                ORDER BY source.ordinal');
            $copy->execute([$supplierId, $id, $supplierId, $previewId]);
            $total = $copy->rowCount();
            $pdo->prepare('UPDATE catalog_jobs SET total = ? WHERE supplier_id = ? AND id = ?')
                ->execute([$total, $supplierId, $id]);
            $pdo->commit();
            return $this->jobs->find($supplierId, $id) ?? [];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function items(
        int $supplierId,
        int $jobId,
        int $page,
        int $limit,
        ?string $status = null,
        ?string $currency = null,
        ?string $issue = null,
    ): array
    {
        $job = $this->requireJob($supplierId, $jobId);
        if ($page < 1 || $limit < 1 || $limit > 500 || ($status !== null && !in_array($status, CatalogJobItemRepository::STATUSES, true))) {
            throw new \InvalidArgumentException('Neplatné stránkování reportu.');
        }
        if ($currency !== null) {
            $currency = strtoupper(trim($currency));
            if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
                throw new \InvalidArgumentException('Neplatný filtr měny.');
            }
        }
        if ($issue !== null && !in_array($issue, ['missing_price', 'manual_override', 'deviation'], true)) {
            throw new \InvalidArgumentException('Neplatný filtr problému.');
        }
        $where = ['supplier_id = ?', 'job_id = ?'];
        $params = [$supplierId, $jobId];
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($currency !== null) {
            $where[] = '(JSON_CONTAINS_PATH(before_json, "one", ?) OR JSON_CONTAINS_PATH(after_json, "one", ?))';
            $path = '$.cells.' . $currency;
            $params[] = $path;
            $params[] = $path;
        }
        if ($issue !== null) {
            $where[] = 'JSON_SEARCH(JSON_EXTRACT(after_json, ?), "one", ?) IS NOT NULL';
            $params[] = $currency === null ? '$.issues' : '$.issues.' . $currency;
            $params[] = $issue;
        }
        $sqlWhere = implode(' AND ', $where);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM catalog_job_items WHERE ' . $sqlWhere);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $stmt = $this->db->pdo()->prepare('SELECT * FROM catalog_job_items WHERE ' . $sqlWhere
            . ' ORDER BY ordinal LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit));
        $stmt->execute($params);
        $items = array_map($this->castItem(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
        return [
            'job' => $this->present($job),
            'items' => $items,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)],
        ];
    }

    public function requireJob(int $supplierId, int $jobId): array
    {
        $job = $this->jobs->find($supplierId, $jobId);
        if ($job === null || !in_array($job['kind'], [self::PREVIEW_KIND, self::APPLY_KIND], true)) {
            throw new EshopException('not_found', 'Cenová matice nebyla nalezena.', 404);
        }
        return $job;
    }

    public function present(array $job): array
    {
        $job['currencies'] = array_values((array) ($job['input']['request']['currencies'] ?? []));
        $job['apply_job_id'] = null;
        if ($job['kind'] === self::PREVIEW_KIND) {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM catalog_jobs WHERE supplier_id = ? AND kind = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(input_json, "$.source_job_id")) = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$job['supplier_id'], self::APPLY_KIND, (string) $job['id']]);
            $applyId = $stmt->fetchColumn();
            $job['apply_job_id'] = $applyId === false ? null : (int) $applyId;
        }
        unset($job['input']);
        return $job;
    }

    private function normalize(int $supplierId, array $options): array
    {
        $allowed = ['currencies', 'on_date', 'ensure_missing', 'reprice', 'deviation_threshold_pct', 'overrides'];
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new \InvalidArgumentException('Cenová matice obsahuje neznámé pole.');
        }
        $codes = $options['currencies'] ?? null;
        if (!is_array($codes) || !array_is_list($codes) || $codes === []) {
            throw new \InvalidArgumentException('Vyberte alespoň jednu měnu.');
        }
        $configured = array_flip($this->currencies->codes($supplierId));
        $currencies = [];
        foreach ($codes as $code) {
            if (!is_string($code) || !isset($configured[strtoupper(trim($code))])) {
                throw new \InvalidArgumentException('Cenová matice obsahuje neplatnou měnu.');
            }
            $currencies[strtoupper(trim($code))] = true;
        }
        foreach (['ensure_missing', 'reprice'] as $flag) {
            if (isset($options[$flag]) && !is_bool($options[$flag])) {
                throw new \InvalidArgumentException($flag . ' musí být boolean.');
            }
        }
        $onDate = $options['on_date'] ?? date('Y-m-d');
        if (!is_string($onDate)) {
            throw new \InvalidArgumentException('Neplatné datum cenové matice.');
        }
        $overrides = $options['overrides'] ?? [];
        if (!is_array($overrides) || !array_is_list($overrides) || count($overrides) > CatalogSelectionService::MAXIMUM * count($currencies)) {
            throw new \InvalidArgumentException('Neplatné cenové výjimky.');
        }
        $normalized = [];
        foreach ($overrides as $override) {
            if (!is_array($override) || !is_int($override['item_id'] ?? null) || $override['item_id'] < 1 || !is_string($override['currency_code'] ?? null)) {
                throw new \InvalidArgumentException('Neplatná cenová výjimka.');
            }
            $currency = strtoupper(trim($override['currency_code']));
            $operation = $override['operation'] ?? null;
            if (!isset($currencies[$currency]) || !in_array($operation, ['lock_current', 'set_fixed', 'unlock_to_rules', 'delete', 'upsert'], true)) {
                throw new \InvalidArgumentException('Neplatná operace cenové výjimky.');
            }
            $key = $override['item_id'] . ':' . $currency;
            if (isset($normalized[$key])) {
                throw new \InvalidArgumentException('Duplicitní cenová výjimka.');
            }
            $entry = ['operation' => $operation];
            if ($operation === 'set_fixed') {
                $entry['fixed_price'] = $override['fixed_price'] ?? null;
                if (isset($override['rounding'])) {
                    $entry['rounding'] = $override['rounding'];
                }
            } elseif ($operation === 'upsert') {
                if (!is_array($override['definition'] ?? null)) {
                    throw new \InvalidArgumentException('Importovaná cenová definice chybí.');
                }
                $entry['definition'] = $override['definition'];
            }
            $normalized[$key] = $entry;
        }
        $threshold = $options['deviation_threshold_pct'] ?? '10';
        if ((!is_string($threshold) && !is_int($threshold))
            || !preg_match('/^(?:0|[1-9]\d{0,2}|1000)(?:\.\d{1,3})?$/D', (string) $threshold)
            || bccomp((string) $threshold, '1000', 3) > 0) {
            throw new \InvalidArgumentException('Prahová odchylka musí být od 0 do 1000 procent.');
        }
        return [
            'currencies' => array_keys($currencies),
            'on_date' => $onDate,
            'ensure_missing' => $options['ensure_missing'] ?? false,
            'reprice' => $options['reprice'] ?? true,
            'deviation_threshold_pct' => (string) $threshold,
            'overrides' => $normalized,
        ];
    }

    private function castItem(array $row): array
    {
        foreach (['job_id', 'supplier_id', 'ordinal', 'stock_item_id', 'source_row', 'expected_version'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        foreach (['input', 'before', 'after'] as $key) {
            $row[$key] = $row[$key . '_json'] === null ? null : json_decode($row[$key . '_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($row[$key . '_json']);
        }
        return $row;
    }
}
