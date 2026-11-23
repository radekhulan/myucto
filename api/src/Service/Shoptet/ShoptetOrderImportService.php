<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Stock\SalesOrderException;
use MyInvoice\Service\Stock\SalesOrderService;
use MyInvoice\Service\Vat\VatRateResolver;
use PDO;

/**
 * Import objednávek ze Shoptetu do prodejních objednávek (sales_orders).
 *
 * Tok: soubor nebo stažený export → dávka ve stavu `preview` (obsah zkomprimovaný
 * v dávce, nic se nezakládá) → náhled „co se založí / aktualizuje / přeskočí" →
 * potvrzení → zápis. Po zápisu se obsah z dávky maže (osobní údaje zákazníků).
 *
 * Idempotence: objednávka má `external_source = 'shoptet'` a `external_id = kód`,
 * nad tím stojí UNIQUE (supplier_id, external_source, external_id). Opakovaný import
 * téhož kódu:
 *   - beze změny obsahu → přeskočí,
 *   - změna u rozpracované objednávky (draft, bez faktury) → přepíše ji,
 *   - změna u potvrzené, vyfakturované, stornované nebo uzavřené → nepřepíše, jen
 *     nahlásí a objednávku označí `pending_change`. Doklad se tím nikdy nepřepíše.
 *
 * DPH: Shoptet pracuje s cenami s DPH, objednávka proto nese `prices_include_vat = 1`
 * a jednotkovou cenu s DPH. Údaje Shoptetu o daňovém režimu (země doručení, DIČ,
 * sazby, případně režim DPH) se ukládají jako snapshot a porovnávají s vlastním
 * zařazením MyÚčta ({@see OssItemPlanner}). Neshoda objednávku označí k ruční
 * kontrole a fakturace z ní je zablokovaná ({@see ShoptetInvoicingGuard}).
 */
final class ShoptetOrderImportService
{
    public const SOURCE = 'shoptet';
    private const TOTAL_TOLERANCE = 1.00;
    private const PREVIEW_TTL_HOURS = 24;

    /** @var array<string,?array{id:int,rate:float}> */
    private array $rateCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ShoptetOrderFileParser $parser,
        private readonly ShoptetOrderFetcher $fetcher,
        private readonly ShoptetSettingsService $settings,
        private readonly SalesOrderService $orders,
        private readonly ClientResolver $clientResolver,
        private readonly ClientRepository $clients,
        private readonly OssItemPlanner $ossPlanner,
        private readonly VatRateResolver $vatRates,
    ) {}

    /** @return array<string,mixed> */
    public function previewUpload(int $supplierId, string $content, ?string $fileName, ?int $userId): array
    {
        $parsed = $this->parser->parse($content, $fileName);
        $batchId = $this->createBatch($supplierId, 'upload', $fileName, $content, null, null, $userId);

        return $this->storePreview($supplierId, $batchId, $parsed);
    }

    /** @return array<string,mixed> */
    public function previewFromUrl(int $supplierId, ?int $userId, ?\DateTimeImmutable $now = null): array
    {
        $url = $this->settings->orderUrl($supplierId);
        if ($url === null) {
            throw new ShoptetImportException('shoptet_order_url_missing', 'Není uložený odkaz na export objednávek.');
        }
        $now ??= new \DateTimeImmutable();
        $settings = $this->settings->get($supplierId);
        try {
            $fetched = $this->fetcher->fetch($url, $settings, $now);
        } catch (ShoptetImportException $e) {
            $this->settings->recordFetch($supplierId, [
                'last_fetch_at' => $now->format('Y-m-d H:i:s'),
                'last_fetch_status' => 'error',
                'last_fetch_message' => $e->getMessage(),
            ]);
            throw $e;
        }
        $record = [
            'last_fetch_at' => $now->format('Y-m-d H:i:s'),
            'last_fetch_status' => 'ok',
            'last_fetch_message' => null,
        ];
        if ($fetched['full']) {
            $record['last_full_fetch_at'] = $now->format('Y-m-d H:i:s');
        }
        $this->settings->recordFetch($supplierId, $record);

        $trimmed = trim($fetched['content']);
        $parsed = $trimmed === '' ? ['format' => 'xml', 'orders' => [], 'errors' => []] : $this->parser->parse($fetched['content'], null);
        $batchId = $this->createBatch(
            $supplierId,
            'url',
            'shoptet-export',
            $fetched['content'],
            $fetched['update_time_from'],
            $fetched['fetched_at'],
            $userId,
        );

        return $this->storePreview($supplierId, $batchId, $parsed);
    }

    /**
     * Zápis dávky po potvrzení náhledu.
     *
     * @return array<string,mixed>
     */
    public function apply(int $supplierId, int $batchId, ?int $userId): array
    {
        $batch = $this->lockPreviewBatch($supplierId, $batchId);
        $content = self::decompress((string) $batch['payload']);
        $parsed = trim($content) === '' ? ['orders' => [], 'errors' => []] : $this->parser->parse($content, $batch['file_name'] ?? null);
        $settings = $this->settings->get($supplierId);

        $report = [];
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => 0, 'failed' => 0, 'review' => 0];
        foreach ($parsed['errors'] as $error) {
            $report[] = ['code' => $error['ref'], 'status' => 'failed', 'messages' => [$error['message']]];
            $summary['failed']++;
        }
        foreach ($parsed['orders'] as $order) {
            try {
                $row = $this->applyOrder($supplierId, $batchId, $order, $settings, $userId);
            } catch (SalesOrderException | ShoptetImportException $e) {
                $row = ['code' => $order['code'], 'status' => 'failed', 'messages' => [$e->getMessage()]];
            } catch (\PDOException $e) {
                error_log('Shoptet import objednávky selhal: ' . $e->getMessage());
                $row = ['code' => $order['code'], 'status' => 'failed', 'messages' => ['Objednávku se nepodařilo uložit (chyba databáze).']];
            }
            $report[] = $row;
            $key = match ($row['status']) {
                'created' => 'created',
                'updated' => 'updated',
                'unchanged' => 'unchanged',
                'conflict' => 'conflicts',
                default => 'failed',
            };
            $summary[$key]++;
            if (!empty($row['review_required'])) {
                $summary['review']++;
            }
        }

        // Kurzor stahování se posune jen za dávku bez chyb. Shoptet při dalším stažení
        // pošle jen objednávky změněné od kurzoru, takže objednávka, která teď selhala
        // (chybí měna, sazba, vada v šabloně), by se po posunu už nikdy nenačetla. Dokud
        // chyba trvá, další stažení vezme znovu totéž okno a duplicity pohlídá kód objednávky.
        if ((string) $batch['source'] === 'url' && $batch['fetched_at'] !== null) {
            if ($summary['failed'] > 0) {
                $summary['cursor_held'] = 1;
            } else {
                $this->settings->recordFetch($supplierId, [
                    'fetch_cursor' => ShoptetOrderFetcher::nextCursor(new \DateTimeImmutable((string) $batch['fetched_at']))->format('Y-m-d H:i:s'),
                ]);
            }
        }

        $this->db->pdo()->prepare(
            "UPDATE shoptet_import_batches
                SET status = 'applied', payload = NULL, summary_json = ?, report_json = ?, applied_at = NOW()
              WHERE supplier_id = ? AND id = ?"
        )->execute([self::json($summary), self::json($report), $supplierId, $batchId]);

        return $this->batch($supplierId, $batchId) ?? throw new \LogicException('Dávka zmizela.');
    }

    public function discard(int $supplierId, int $batchId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE shoptet_import_batches SET status = 'discarded', payload = NULL
              WHERE supplier_id = ? AND id = ? AND status = 'preview'"
        )->execute([$supplierId, $batchId]);
    }

    /**
     * Automatické stažení a zápis (cron). Náhled se tu přeskakuje — pravidla zápisu
     * jsou stejná a nic vyfakturovaného se nepřepisuje.
     *
     * @return array<string,mixed>
     */
    public function autoFetch(int $supplierId, ?\DateTimeImmutable $now = null): array
    {
        $preview = $this->previewFromUrl($supplierId, null, $now);

        return $this->apply($supplierId, (int) $preview['id'], null);
    }

    /**
     * Zahodí objednávky, které dávka založila — jen ty, ze kterých ještě nic
     * nevzniklo (rozpracované nebo stornované, bez faktury, výdeje a vratky).
     *
     * @return array{deleted:int, skipped:list<array{code:string,reason:string}>}
     */
    public function erase(int $supplierId, int $batchId): array
    {
        $batch = $this->batch($supplierId, $batchId);
        if ($batch === null || $batch['kind'] !== 'orders') {
            throw new ShoptetImportException('shoptet_batch_not_found', 'Dávka nenalezena.', 404);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT so.order_id, so.shoptet_code, o.commercial_status,
                    (SELECT COUNT(*) FROM sales_order_invoice_links l WHERE l.order_id = o.id) AS invoices,
                    (SELECT COUNT(*) FROM sales_order_fulfillment_links f WHERE f.order_id = o.id) AS shipments,
                    (SELECT COUNT(*) FROM sales_order_returns r WHERE r.order_id = o.id) AS returns_count,
                    (SELECT COUNT(*) FROM sales_order_reservations rs WHERE rs.order_id = o.id AND rs.qty_consumed > 0) AS consumed
               FROM shoptet_orders so
               JOIN sales_orders o ON o.id = so.order_id AND o.supplier_id = so.supplier_id
              WHERE so.supplier_id = ? AND so.created_batch_id = ?'
        );
        $stmt->execute([$supplierId, $batchId]);
        $deleted = 0;
        $skipped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $reason = match (true) {
                (int) $row['invoices'] > 0 => 'k objednávce existuje faktura',
                (int) $row['shipments'] > 0 || (int) $row['consumed'] > 0 => 'z objednávky se už vydávalo zboží',
                (int) $row['returns_count'] > 0 => 'objednávka má vratku',
                !in_array((string) $row['commercial_status'], ['draft', 'cancelled'], true) => 'objednávka je potvrzená — nejdřív ji stornujte',
                default => null,
            };
            if ($reason !== null) {
                $skipped[] = ['code' => (string) $row['shoptet_code'], 'reason' => $reason];
                continue;
            }
            $this->db->pdo()->prepare('DELETE FROM sales_orders WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, (int) $row['order_id']]);
            $deleted++;
        }
        if ($skipped === []) {
            $this->db->pdo()->prepare(
                "UPDATE shoptet_import_batches SET status = 'erased', erased_at = NOW() WHERE supplier_id = ? AND id = ?"
            )->execute([$supplierId, $batchId]);
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    public function markReviewed(int $supplierId, int $orderId, ?int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE shoptet_orders SET review_required = 0, reviewed_by = ?, reviewed_at = NOW()
              WHERE supplier_id = ? AND order_id = ?'
        );
        $stmt->execute([$userId, $supplierId, $orderId]);
        if ($stmt->rowCount() === 0) {
            $exists = $this->db->pdo()->prepare('SELECT 1 FROM shoptet_orders WHERE supplier_id = ? AND order_id = ?');
            $exists->execute([$supplierId, $orderId]);
            if ($exists->fetchColumn() === false) {
                throw new ShoptetImportException('shoptet_order_not_found', 'Objednávka ze Shoptetu nenalezena.', 404);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function reviewQueue(int $supplierId, int $limit = 100): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT so.order_id, so.shoptet_code, so.shoptet_status, so.shoptet_total_with_vat, so.review_reasons,
                    so.pending_change, so.updated_at, o.order_number, o.commercial_status, o.total_with_vat,
                    o.currency_code, c.company_name AS client_name
               FROM shoptet_orders so
               JOIN sales_orders o ON o.id = so.order_id AND o.supplier_id = so.supplier_id
               JOIN clients c ON c.id = o.client_id AND c.supplier_id = o.supplier_id
              WHERE so.supplier_id = ? AND (so.review_required = 1 OR so.pending_change = 1)
           ORDER BY so.updated_at DESC LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([$supplierId]);

        return array_map(static fn (array $r): array => [
            'order_id' => (int) $r['order_id'],
            'code' => (string) $r['shoptet_code'],
            'order_number' => (string) $r['order_number'],
            'client_name' => (string) $r['client_name'],
            'commercial_status' => (string) $r['commercial_status'],
            'shoptet_status' => $r['shoptet_status'],
            'total_with_vat' => (string) $r['total_with_vat'],
            'shoptet_total_with_vat' => $r['shoptet_total_with_vat'],
            'currency_code' => (string) $r['currency_code'],
            'review_reasons' => json_decode((string) $r['review_reasons'], true) ?: [],
            'pending_change' => (bool) $r['pending_change'],
            'updated_at' => $r['updated_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function batches(int $supplierId, ?string $kind = null, int $limit = 20): array
    {
        $this->purgeStalePreviews();
        $sql = 'SELECT id FROM shoptet_import_batches WHERE supplier_id = ? AND status <> \'discarded\'';
        $args = [$supplierId];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $args[] = $kind;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        $stmt->execute($args);

        return array_values(array_filter(array_map(
            fn (mixed $id): ?array => $this->batch($supplierId, (int) $id, false),
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [],
        )));
    }

    /** @return array<string,mixed>|null */
    public function batch(int $supplierId, int $batchId, bool $withReport = true): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT b.id, b.kind, b.source, b.status, b.file_name, b.update_time_from, b.fetched_at, b.summary_json,
                    b.report_json, b.invoice_batch_id, b.created_at, b.applied_at, b.erased_at, u.email AS created_by_email
               FROM shoptet_import_batches b
          LEFT JOIN users u ON u.id = b.created_by
              WHERE b.supplier_id = ? AND b.id = ?'
        );
        $stmt->execute([$supplierId, $batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'source' => (string) $row['source'],
            'status' => (string) $row['status'],
            'file_name' => $row['file_name'],
            'update_time_from' => $row['update_time_from'],
            'fetched_at' => $row['fetched_at'],
            'summary' => json_decode((string) $row['summary_json'], true) ?: [],
            'report' => $withReport ? (json_decode((string) $row['report_json'], true) ?: []) : null,
            'invoice_batch_id' => $row['invoice_batch_id'],
            'created_at' => $row['created_at'],
            'applied_at' => $row['applied_at'],
            'erased_at' => $row['erased_at'],
            'created_by' => $row['created_by_email'],
        ];
    }

    /**
     * @param array{format?:string, orders:list<array<string,mixed>>, errors:list<array{ref:string,message:string}>} $parsed
     * @return array<string,mixed>
     */
    private function storePreview(int $supplierId, int $batchId, array $parsed): array
    {
        $report = [];
        $summary = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'conflict' => 0, 'failed' => 0, 'review' => 0, 'unmatched_lines' => 0];
        foreach ($parsed['errors'] as $error) {
            $report[] = ['code' => $error['ref'], 'action' => 'failed', 'messages' => [$error['message']]];
            $summary['failed']++;
        }
        foreach ($parsed['orders'] as $order) {
            $plan = $this->planOrder($supplierId, $order);
            $report[] = $plan;
            $summary[$plan['action']] = ($summary[$plan['action']] ?? 0) + 1;
            if (!empty($plan['review_reasons'])) {
                $summary['review']++;
            }
            $summary['unmatched_lines'] += (int) $plan['unmatched_lines'];
        }
        $summary['format'] = $parsed['format'] ?? null;
        $this->db->pdo()->prepare('UPDATE shoptet_import_batches SET summary_json = ?, report_json = ? WHERE supplier_id = ? AND id = ?')
            ->execute([self::json($summary), self::json($report), $supplierId, $batchId]);

        return $this->batch($supplierId, $batchId) ?? throw new \LogicException('Dávka zmizela.');
    }

    /**
     * Náhled jedné objednávky — bez zápisu, bez zakládání klientů.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function planOrder(int $supplierId, array $order): array
    {
        $existing = $this->existing($supplierId, (string) $order['code']);
        $hash = self::hash($order);
        $lines = $this->buildLines($supplierId, $order, $this->settings->get($supplierId));
        $review = $this->reviewReasons($supplierId, $order, $lines);
        $messages = $lines['warnings'];
        if ($lines['error'] !== null) {
            return [
                'code' => $order['code'], 'action' => 'failed', 'messages' => [$lines['error']],
                'review_reasons' => [], 'unmatched_lines' => 0,
            ];
        }
        $action = 'create';
        if ($existing !== null) {
            $blocked = self::blockedReason($existing);
            if ($existing['content_hash'] === $hash) {
                $action = 'unchanged';
            } elseif ($blocked !== null) {
                $action = 'conflict';
                $messages[] = 'Změnu ze Shoptetu nepřevezmeme: ' . $blocked . '.';
            } else {
                $action = 'update';
            }
        }

        return [
            'code' => $order['code'],
            'action' => $action,
            'date' => $order['date'],
            'status' => $order['status'],
            'customer' => self::customerLabel($order),
            'customer_match' => $this->customerMatch($supplierId, $order) !== null ? 'existing' : 'new',
            'currency' => $order['currency'],
            'total_with_vat' => round($lines['total_with_vat'], 2),
            'shoptet_total_with_vat' => self::shoptetTotal($order),
            'lines' => count($lines['lines']),
            'unmatched_lines' => $lines['unmatched'],
            'review_reasons' => $review,
            'messages' => $messages,
            'order_id' => $existing !== null ? (int) $existing['order_id'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function applyOrder(int $supplierId, int $batchId, array $order, array $settings, ?int $userId): array
    {
        $code = (string) $order['code'];
        $hash = self::hash($order);
        $existing = $this->existing($supplierId, $code);
        if ($existing !== null && $existing['content_hash'] === $hash) {
            $this->touchMeta($supplierId, (int) $existing['order_id'], $batchId);

            return ['code' => $code, 'status' => 'unchanged', 'order_id' => (int) $existing['order_id'], 'messages' => []];
        }
        if ($existing !== null && ($blocked = self::blockedReason($existing)) !== null) {
            $this->db->pdo()->prepare(
                'UPDATE shoptet_orders SET pending_change = 1, last_batch_id = ?, shoptet_status = ? WHERE supplier_id = ? AND order_id = ?'
            )->execute([$batchId, $order['status'], $supplierId, (int) $existing['order_id']]);

            return [
                'code' => $code,
                'status' => 'conflict',
                'order_id' => (int) $existing['order_id'],
                'messages' => ['Změnu ze Shoptetu jsme nepřevzali: ' . $blocked . '. Upravte objednávku ručně nebo vystavte opravný doklad.'],
            ];
        }

        $lines = $this->buildLines($supplierId, $order, $settings);
        if ($lines['error'] !== null) {
            throw new ShoptetImportException('shoptet_order_invalid', $lines['error']);
        }
        $currencyId = $this->currencyId($supplierId, (string) $order['currency']);
        $clientId = $this->resolveClient($supplierId, $order);
        $review = $this->reviewReasons($supplierId, $order, $lines);
        $review = array_values(array_unique(array_merge($review, $this->ossMismatch($supplierId, $clientId, $order, $lines))));

        $data = [
            'client_id' => $clientId,
            'currency_id' => $currencyId,
            'prices_include_vat' => true,
            'exchange_rate' => ($order['currency'] !== 'CZK' && ($order['exchange_rate'] ?? null) !== null)
                ? number_format((float) $order['exchange_rate'], 8, '.', '')
                : null,
            'external_source' => self::SOURCE,
            'external_id' => $code,
            'shipping_snapshot' => [
                'source' => 'shoptet',
                'delivery_address' => $order['delivery'] ?? [],
                'shipping' => $lines['shipping_names'],
                'payment' => $lines['payment_names'],
                'shoptet_status' => $order['status'],
                'ordered_at' => $order['date'],
            ],
            'lines' => $lines['lines'],
        ];

        $messages = $lines['warnings'];
        if ($existing === null) {
            $data['order_number'] = $this->orderNumber($supplierId, $code);
            $detail = $this->orders->create($supplierId, $data, $userId);
            $status = 'created';
        } else {
            $current = $this->orders->detail($supplierId, (int) $existing['order_id'])
                ?? throw new ShoptetImportException('shoptet_order_not_found', 'Objednávka zmizela.', 404);
            $detail = $this->orders->update($supplierId, (int) $existing['order_id'], (int) $current['row_version'], $data);
            $status = 'updated';
        }
        $orderId = (int) $detail['id'];
        $reviewed = $existing !== null && (int) $existing['review_required'] === 0 && $existing['reviewed_at'] !== null
            && $review === json_decode((string) $existing['review_reasons'], true);
        $this->saveMeta($supplierId, $orderId, $code, $batchId, $existing === null, $hash, $order, $review, !$reviewed && $review !== []);

        if ((bool) $settings['confirm_orders'] && (string) $detail['commercial_status'] === 'draft') {
            try {
                $this->orders->confirm($supplierId, $orderId, 'shoptet:' . $code . ':confirm');
            } catch (SalesOrderException $e) {
                $messages[] = 'Objednávku se nepodařilo potvrdit a rezervovat zásobu: ' . $e->getMessage();
            }
        }

        $diff = abs(round($lines['total_with_vat'], 2) - (float) (self::shoptetTotal($order) ?? $lines['total_with_vat']));
        if ($diff > 0.005 && $diff <= self::TOTAL_TOLERANCE) {
            $messages[] = sprintf('Součet se od Shoptetu liší o %s (zaokrouhlení).', number_format($diff, 2, ',', ' '));
        }

        return [
            'code' => $code,
            'status' => $status,
            'order_id' => $orderId,
            'order_number' => (string) $detail['order_number'],
            'review_required' => $review !== [] && !$reviewed,
            'review_reasons' => $review,
            'messages' => $messages,
        ];
    }

    /**
     * Řádky objednávky pro {@see SalesOrderService} a údaje pro kontrolu.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $settings
     * @return array{lines:list<array<string,mixed>>, warnings:list<string>, unmatched:int, total_with_vat:float,
     *               foreign_rates:list<float>, shipping_names:list<string>, payment_names:list<string>, error:?string}
     */
    private function buildLines(int $supplierId, array $order, array $settings): array
    {
        $date = substr((string) ($order['date'] ?? date('Y-m-d')), 0, 10);
        $domestic = $this->ossPlanner->domesticCountry($supplierId);
        $warehouseId = $this->warehouseId($supplierId, $settings);
        $out = ['lines' => [], 'warnings' => [], 'unmatched' => 0, 'total_with_vat' => 0.0, 'foreign_rates' => [],
            'shipping_names' => [], 'payment_names' => [], 'error' => null];

        foreach ($order['items'] as $index => $item) {
            $qty = (float) $item['quantity'];
            $rate = (float) $item['vat_rate'];
            $gross = match (true) {
                ($item['total_with_vat'] ?? null) !== null => (float) $item['total_with_vat'] / $qty,
                ($item['unit_price_with_vat'] ?? null) !== null => (float) $item['unit_price_with_vat'],
                default => (float) $item['unit_price_without_vat'] * (1 + $rate / 100),
            };
            $kind = (string) $item['kind'];
            $name = (string) ($item['name'] ?? '');
            $description = match ($kind) {
                'shipping' => 'Doprava' . ($name !== '' ? ': ' . $name : ''),
                'billing' => 'Platba' . ($name !== '' ? ': ' . $name : ''),
                default => $name !== '' ? $name : ($item['code'] ?? 'Položka ' . ($index + 1)),
            };
            if (($item['variant'] ?? null) !== null && !str_contains($description, (string) $item['variant'])) {
                $description .= ' – ' . $item['variant'];
            }
            if ($kind === 'shipping' && $name !== '') {
                $out['shipping_names'][] = $name;
            }
            if ($kind === 'billing' && $name !== '') {
                $out['payment_names'][] = $name;
            }

            $vat = $this->domesticRate($domestic, $rate, $date);
            if ($vat === null) {
                $out['foreign_rates'][] = $rate;
                $vat = $this->fallbackRate($supplierId);
                if ($vat === null) {
                    $out['error'] = sprintf('Sazba %s %% není v tuzemsku platná a firma nemá výchozí sazbu DPH.', self::fmt($rate));
                    return $out;
                }
            }

            $line = [
                'description' => mb_substr(trim((string) $description), 0, 500),
                'quantity' => number_format($qty, 3, '.', ''),
                'unit' => (string) ($item['unit'] ?? 'ks') ?: 'ks',
                'unit_price' => number_format(round($gross, 6), 6, '.', ''),
                'discount_percent' => '0',
                'vat_rate_id' => $vat['id'],
            ];
            if ($kind === 'product' && $gross >= 0.0) {
                $stockItemId = $this->matchItem($supplierId, $item['code'] ?? null, $item['ean'] ?? null);
                if ($stockItemId !== null && $warehouseId !== null) {
                    $line['stock_item_id'] = $stockItemId;
                    $line['warehouse_id'] = $warehouseId;
                } else {
                    $out['unmatched']++;
                    $out['warnings'][] = $stockItemId === null
                        ? sprintf('Položka „%s" (kód %s) nemá kartu v katalogu — převzata jako textový řádek bez skladu.',
                            $line['description'], $item['code'] ?? $item['ean'] ?? '—')
                        : 'Firma nemá aktivní prodejní sklad — skladové položky převzaty jako textové řádky.';
                }
            }
            $out['lines'][] = $line;
            $out['total_with_vat'] += round($qty * round($gross, 6), 2);
        }

        return $out;
    }

    /**
     * Důvody k ruční kontrole, které jde poznat ze samotné objednávky (bez klienta).
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $lines
     * @return list<string>
     */
    private function reviewReasons(int $supplierId, array $order, array $lines): array
    {
        $reasons = [];
        $domestic = $this->ossPlanner->domesticCountry($supplierId);
        foreach (array_unique(array_map(self::fmt(...), $lines['foreign_rates'] ?? [])) as $rate) {
            $reasons[] = sprintf(
                'Shoptet účtoval sazbu %s %%, která v tuzemsku (%s) k datu objednávky neplatí — nejspíš OSS nebo cizí sazba.',
                $rate,
                $domestic,
            );
        }
        $country = self::deliveryCountry($order);
        $vatId = trim((string) ($order['billing']['vat_id'] ?? ''));
        $hasVat = false;
        foreach ($order['items'] as $item) {
            if ((float) $item['vat_rate'] > 0.0) {
                $hasVat = true;
                break;
            }
        }
        if ($country !== null && $country !== $domestic) {
            if ($vatId !== '' && !str_starts_with(strtoupper($vatId), $domestic)) {
                $reasons[] = $hasVat
                    ? sprintf('Odběratel s DIČ %s ze státu %s, ale objednávka nese DPH — ověřte režim přenesení daně.', $vatId, $country)
                    : sprintf('Dodání do státu %s osobě s DIČ %s bez DPH — ověřte DIČ ve VIES a zařazení do souhrnného hlášení.', $country, $vatId);
            } else {
                $reasons[] = sprintf('Zásilka do státu %s bez DIČ odběratele — ověřte místo plnění a zařazení do OSS.', $country);
            }
        }
        $mode = mb_strtolower(trim((string) ($order['vat_mode'] ?? '')));
        if ($mode !== '' && !in_array($mode, ['normal', 'standard', 'standardni', 'běžný', 'bezny', 'domestic'], true)) {
            $reasons[] = sprintf('Shoptet uvádí zvláštní režim DPH „%s" — ověřte zařazení objednávky.', $order['vat_mode']);
        }
        $shoptetTotal = self::shoptetTotal($order);
        if ($shoptetTotal !== null && abs(round((float) $lines['total_with_vat'], 2) - $shoptetTotal) > self::TOTAL_TOLERANCE) {
            $reasons[] = sprintf(
                'Součet řádků %s se liší od celkové ceny Shoptetu %s o víc než %s.',
                number_format((float) $lines['total_with_vat'], 2, ',', ' '),
                number_format($shoptetTotal, 2, ',', ' '),
                number_format(self::TOTAL_TOLERANCE, 2, ',', ' '),
            );
        }

        return $reasons;
    }

    /**
     * Vlastní zařazení MyÚčta (OSS / místo plnění) proti tomu, co účtoval Shoptet.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $lines
     * @return list<string>
     */
    private function ossMismatch(int $supplierId, int $clientId, array $order, array $lines): array
    {
        $country = self::deliveryCountry($order);
        $context = $this->ossPlanner->clientContext($clientId, [
            'country_iso2' => $country,
            'dic' => $order['billing']['vat_id'] ?? null,
        ]);
        $date = substr((string) ($order['date'] ?? date('Y-m-d')), 0, 10);
        $domestic = $this->ossPlanner->domesticCountry($supplierId);
        $reasons = [];
        foreach ($order['items'] as $item) {
            $rate = (float) $item['vat_rate'];
            $plan = $this->ossPlanner->planIssuedItem($supplierId, $context, $rate, $item['unit'] ?? null, $date, false);
            if ($plan->isRejected()) {
                $reasons[] = 'MyÚčto by řádek „' . ($item['name'] ?? $item['code'] ?? '') . '" nevyfakturovalo: '
                    . ($plan->errorMessage() ?? 'daňové zařazení vyžaduje kontrolu') ;
                continue;
            }
            $oss = (int) ($plan->itemColumns()['oss_applicable'] ?? 0) === 1;
            $shoptetDomestic = $this->domesticRate($domestic, $rate, $date) !== null;
            if ($oss && $shoptetDomestic && $rate > 0.0) {
                $reasons[] = sprintf(
                    'MyÚčto řadí plnění do OSS (stát %s), ale Shoptet účtoval tuzemskou sazbu %s %%.',
                    (string) ($plan->itemColumns()['oss_consumer_country'] ?? $country ?? '?'),
                    self::fmt($rate),
                );
            } elseif (!$oss && !$shoptetDomestic) {
                $reasons[] = sprintf('Shoptet účtoval cizí sazbu %s %%, ale MyÚčto plnění do OSS neřadí.', self::fmt($rate));
            }
            if ($plan->needsManualReview()) {
                $reasons[] = 'Místo plnění je sporné — MyÚčto ho neurčilo jednoznačně.';
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @param array<string,mixed> $order */
    private function resolveClient(int $supplierId, array $order): int
    {
        $existing = $this->customerMatch($supplierId, $order);
        if ($existing !== null) {
            return $existing;
        }
        $billing = $order['billing'] ?? [];
        $parsed = [
            'company_name' => self::customerLabel($order),
            'ic' => $billing['company_id'] ?? null,
            'dic' => $billing['vat_id'] ?? null,
            'street' => $billing['street'] ?? null,
            'city' => $billing['city'] ?? null,
            'zip' => $billing['zip'] ?? null,
            'country_iso2' => $billing['country'] ?? self::deliveryCountry($order),
            'email' => $order['email'] ?? null,
            'phone' => $order['phone'] ?? null,
        ];
        if (($billing['company_id'] ?? null) !== null || ($billing['vat_id'] ?? null) !== null || ($order['email'] ?? null) === null) {
            return $this->clientResolver->resolve($parsed, $supplierId)['id'];
        }

        // Spotřebitel s e-mailem: zakládá se vlastní karta. ClientResolver by bez IČO
        // párovat podle shody jména a dva různé zákazníky „Jan Novák" by slil do jednoho.
        return $this->clients->create([
            'company_name' => $parsed['company_name'],
            'street' => $parsed['street'] ?? '—',
            'city' => $parsed['city'] ?? '—',
            'zip' => $parsed['zip'] ?? '00000',
            'country_iso2' => $parsed['country_iso2'] ?? 'CZ',
            'main_email' => $parsed['email'],
            'phone' => $parsed['phone'],
            'language' => 'cs',
            'is_customer' => true,
            'is_vendor' => false,
        ], $supplierId);
    }

    /** Existující karta zákazníka: IČO, DIČ, pak e-mail (jen karta bez IČO). @param array<string,mixed> $order */
    private function customerMatch(int $supplierId, array $order): ?int
    {
        $billing = $order['billing'] ?? [];
        $ic = \MyInvoice\Support\CompanyIdNormalizer::ic($billing['company_id'] ?? null);
        if ($ic !== null) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL
                    AND " . \MyInvoice\Support\CompanyIdNormalizer::icSql('ic') . " = ? LIMIT 1"
            );
            $stmt->execute([$supplierId, $ic]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        }
        $dic = \MyInvoice\Support\CompanyIdNormalizer::dic((string) ($billing['vat_id'] ?? ''));
        if ($dic !== null) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL
                    AND UPPER(REGEXP_REPLACE(dic, '[^A-Za-z0-9]', '')) = ? LIMIT 1"
            );
            $stmt->execute([$supplierId, $dic]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        }
        $email = trim((string) ($order['email'] ?? ''));
        if ($email !== '') {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL
                    AND (ic IS NULL OR ic = '') AND LOWER(main_email) = LOWER(?) ORDER BY id LIMIT 1"
            );
            $stmt->execute([$supplierId, $email]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        }

        return null;
    }

    private function matchItem(int $supplierId, ?string $code, ?string $ean): ?int
    {
        if ($code !== null && $code !== '') {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM stock_items WHERE supplier_id = ? AND sku = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$supplierId, $code]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        if ($ean !== null && $ean !== '') {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM stock_items WHERE supplier_id = ? AND ean = ? AND is_active = 1 ORDER BY id LIMIT 2');
            $stmt->execute([$supplierId, $ean]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (count($ids) === 1) {
                return (int) $ids[0];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $settings */
    private function warehouseId(int $supplierId, array $settings): ?int
    {
        $sellable = $this->db->hasColumn('warehouses', 'is_sellable') ? ' AND is_sellable = 1' : '';
        if (($settings['default_warehouse_id'] ?? null) !== null) {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM warehouses WHERE supplier_id = ? AND id = ? AND is_active = 1' . $sellable);
            $stmt->execute([$supplierId, (int) $settings['default_warehouse_id']]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM warehouses WHERE supplier_id = ? AND is_active = 1' . $sellable . ' ORDER BY is_default DESC, id LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /** @return array{id:int,rate:float}|null */
    private function domesticRate(string $country, float $rate, string $date): ?array
    {
        $key = $country . '|' . number_format($rate, 2, '.', '') . '|' . $date;
        if (!array_key_exists($key, $this->rateCache)) {
            $match = $this->vatRates->resolve($country, $rate, $date);
            $this->rateCache[$key] = $match->found() ? ['id' => (int) $match->id, 'rate' => (float) $match->ratePercent] : null;
        }

        return $this->rateCache[$key];
    }

    /** @return array{id:int,rate:float}|null */
    private function fallbackRate(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT v.id, v.rate_percent FROM supplier s JOIN vat_rates v ON v.id = s.default_vat_rate_id WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => (int) $row['id'], 'rate' => (float) $row['rate_percent']];
    }

    private function currencyId(int $supplierId, string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? AND is_active = 1 ORDER BY is_default DESC, id LIMIT 1'
        );
        $stmt->execute([$supplierId, strtoupper($code)]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new ShoptetImportException(
                'shoptet_currency_missing',
                sprintf('Měna %s není ve firmě aktivní. Přidejte ji v Nastavení → Měny a import opakujte.', strtoupper($code)),
            );
        }

        return (int) $id;
    }

    private function orderNumber(int $supplierId, string $code): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM sales_orders WHERE supplier_id = ? AND order_number = ?');
        foreach ([$code, 'SHOPTET-' . $code] as $candidate) {
            $candidate = mb_substr($candidate, 0, 50);
            $stmt->execute([$supplierId, $candidate]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
        }

        return mb_substr('SHOPTET-' . $code . '-' . bin2hex(random_bytes(3)), 0, 50);
    }

    /** @return array<string,mixed>|null */
    private function existing(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT o.id AS order_id, o.commercial_status, so.content_hash, so.review_required, so.review_reasons, so.reviewed_at,
                    (SELECT COUNT(*) FROM sales_order_invoice_links l WHERE l.order_id = o.id) AS invoices
               FROM sales_orders o
          LEFT JOIN shoptet_orders so ON so.order_id = o.id AND so.supplier_id = o.supplier_id
              WHERE o.supplier_id = ? AND o.external_source = ? AND o.external_id = ?"
        );
        $stmt->execute([$supplierId, self::SOURCE, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['content_hash'] ??= '';
        $row['review_required'] ??= 0;
        $row['review_reasons'] ??= '[]';

        return $row;
    }

    /** @param array<string,mixed> $existing */
    private static function blockedReason(array $existing): ?string
    {
        return match (true) {
            (int) $existing['invoices'] > 0 => 'objednávka je už vyfakturovaná',
            (string) $existing['commercial_status'] === 'cancelled' => 'objednávka je stornovaná',
            (string) $existing['commercial_status'] === 'completed' => 'objednávka je uzavřená',
            (string) $existing['commercial_status'] === 'confirmed' => 'objednávka je potvrzená a má rezervovanou zásobu',
            default => null,
        };
    }

    /** @param array<string,mixed> $order @param list<string> $review */
    private function saveMeta(int $supplierId, int $orderId, string $code, int $batchId, bool $created, string $hash, array $order, array $review, bool $reviewRequired): void
    {
        $snapshot = [
            'delivery_country' => self::deliveryCountry($order),
            'billing_country' => $order['billing']['country'] ?? null,
            'vat_id' => $order['billing']['vat_id'] ?? null,
            'company_id' => $order['billing']['company_id'] ?? null,
            'vat_mode' => $order['vat_mode'] ?? null,
            'rates' => array_values(array_unique(array_map(static fn (array $i): float => (float) $i['vat_rate'], $order['items']))),
            'currency' => $order['currency'],
            'exchange_rate' => $order['exchange_rate'] ?? null,
            'totals' => $order['totals'] ?? [],
            'paid' => $order['paid'] ?? null,
        ];
        $this->db->pdo()->prepare(
            'INSERT INTO shoptet_orders
                (order_id, supplier_id, shoptet_code, created_batch_id, last_batch_id, content_hash, shoptet_status,
                 shoptet_total_with_vat, vat_snapshot, review_required, review_reasons, pending_change)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE last_batch_id = VALUES(last_batch_id), content_hash = VALUES(content_hash),
                 shoptet_status = VALUES(shoptet_status), shoptet_total_with_vat = VALUES(shoptet_total_with_vat),
                 vat_snapshot = VALUES(vat_snapshot), review_required = VALUES(review_required),
                 review_reasons = VALUES(review_reasons), pending_change = 0'
        )->execute([
            $orderId, $supplierId, $code, $created ? $batchId : null, $batchId, $hash, $order['status'],
            self::shoptetTotal($order), self::json($snapshot), $reviewRequired ? 1 : 0, self::json($review),
        ]);
    }

    private function touchMeta(int $supplierId, int $orderId, int $batchId): void
    {
        $this->db->pdo()->prepare('UPDATE shoptet_orders SET last_batch_id = ? WHERE supplier_id = ? AND order_id = ?')
            ->execute([$batchId, $supplierId, $orderId]);
    }

    private function createBatch(int $supplierId, string $source, ?string $fileName, string $content, ?\DateTimeImmutable $from, ?\DateTimeImmutable $fetchedAt, ?int $userId): int
    {
        $this->purgeStalePreviews();
        $this->db->pdo()->prepare(
            "INSERT INTO shoptet_import_batches
                (supplier_id, kind, source, status, file_name, payload, payload_sha256, update_time_from, fetched_at, created_by)
             VALUES (?, 'orders', ?, 'preview', ?, ?, ?, ?, ?, ?)"
        )->execute([
            $supplierId,
            $source,
            $fileName !== null ? mb_substr(basename($fileName), 0, 255) : null,
            gzcompress($content, 6),
            hash('sha256', $content),
            $from?->format('Y-m-d H:i:s'),
            $fetchedAt?->format('Y-m-d H:i:s'),
            $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function lockPreviewBatch(int $supplierId, int $batchId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, status, source, file_name, payload, fetched_at FROM shoptet_import_batches
              WHERE supplier_id = ? AND id = ? AND kind = 'orders'"
        );
        $stmt->execute([$supplierId, $batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ShoptetImportException('shoptet_batch_not_found', 'Dávka nenalezena.', 404);
        }
        if ((string) $row['status'] !== 'preview' || $row['payload'] === null) {
            throw new ShoptetImportException('shoptet_batch_applied', 'Dávka už byla zpracovaná nebo zahozená.', 409);
        }
        $claim = $this->db->pdo()->prepare(
            "UPDATE shoptet_import_batches SET status = 'applied' WHERE supplier_id = ? AND id = ? AND status = 'preview'"
        );
        $claim->execute([$supplierId, $batchId]);
        if ($claim->rowCount() !== 1) {
            throw new ShoptetImportException('shoptet_batch_applied', 'Dávka už byla zpracovaná nebo zahozená.', 409);
        }

        return $row;
    }

    /** Náhledy starší než den se zahodí i s obsahem (osobní údaje zákazníků). */
    private function purgeStalePreviews(): void
    {
        $this->db->pdo()->exec(
            "UPDATE shoptet_import_batches SET status = 'discarded', payload = NULL
              WHERE status = 'preview' AND created_at < DATE_SUB(NOW(), INTERVAL " . self::PREVIEW_TTL_HOURS . ' HOUR)'
        );
    }

    /** @param array<string,mixed> $order */
    private static function deliveryCountry(array $order): ?string
    {
        return $order['delivery']['country'] ?? $order['billing']['country'] ?? null;
    }

    /** @param array<string,mixed> $order */
    private static function shoptetTotal(array $order): ?float
    {
        $totals = $order['totals'] ?? [];
        if (($totals['with_vat'] ?? null) !== null) {
            return round((float) $totals['with_vat'], 2);
        }
        if (($totals['to_pay'] ?? null) !== null) {
            return round((float) $totals['to_pay'] - (float) ($totals['rounding'] ?? 0), 2);
        }

        return null;
    }

    /** @param array<string,mixed> $order */
    private static function customerLabel(array $order): string
    {
        $billing = $order['billing'] ?? [];
        $label = $billing['company'] ?? $billing['name'] ?? $order['delivery']['name'] ?? $order['email'] ?? null;

        return $label !== null && $label !== '' ? (string) $label : 'Zákazník e-shopu ' . $order['code'];
    }

    /** @param array<string,mixed> $order */
    private static function hash(array $order): string
    {
        unset($order['ref']);

        return hash('sha256', self::json($order));
    }

    private static function fmt(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',');
    }

    private static function decompress(string $payload): string
    {
        $content = @gzuncompress($payload);
        if ($content === false) {
            throw new ShoptetImportException('shoptet_batch_corrupt', 'Obsah dávky nejde načíst.', 500);
        }

        return $content;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
