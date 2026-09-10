<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockMediaRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Eshop\ProductMediaIngestService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

/**
 * Média karty zboží — obrázky/dokumenty (Epic ESHOP).
 *
 *   GET/POST /api/eshop/products/{id}/media
 *   PUT      /api/eshop/products/{id}/media/reorder
 *   PUT/DELETE /api/eshop/media/{mid}
 *
 * Bajty leží v témže hardened content-addressed sup-{id} sha stromu jako DMS
 * (reuse {@see DocumentStorage} — MIME z obsahu, blocklist spustitelných, size
 * cap, path-traversal guard, dedup). storage_key = sha256. Karta se scope-guarduje
 * (StockItemRepository::find je per tenant) PŘED sáhnutím na média.
 */
final class ProductMediaAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const MAX_FILES_PER_REQUEST = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly StockMediaRepository $media,
        private readonly DocumentStorage $storage,
        private readonly ProductMediaIngestService $ingest,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        return Json::ok($response, $this->media->listForItem($supplierId, $itemId));
    }

    public function upload(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }

        $uploaded = $request->getUploadedFiles();
        $list = [];
        if (isset($uploaded['file'])) {
            $list = is_array($uploaded['file']) ? array_values($uploaded['file']) : [$uploaded['file']];
        }
        if ($list === []) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán.', 400);
        }
        if (count($list) > self::MAX_FILES_PER_REQUEST) {
            return Json::error($response, 'too_many_files', 'Příliš mnoho souborů najednou (max ' . self::MAX_FILES_PER_REQUEST . ').', 413);
        }

        $created = [];
        $errors = [];
        foreach ($list as $file) {
            if (!$file instanceof UploadedFileInterface) {
                continue;
            }
            $originalName = trim((string) $file->getClientFilename());
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $errors[] = ['name' => $originalName ?: '?', 'reason' => 'upload_error_' . $file->getError()];
                continue;
            }
            if ($originalName === '') {
                $errors[] = ['name' => '?', 'reason' => 'no_filename'];
                continue;
            }

            $tmp = $this->storage->tmpPath($supplierId);
            try {
                $file->moveTo($tmp);
            } catch (\Throwable) {
                @unlink($tmp);
                $errors[] = ['name' => $originalName, 'reason' => 'move_failed'];
                continue;
            }

            try {
                $result = $this->ingest->ingestTemp($supplierId, $itemId, $tmp, $originalName);
            } catch (DocumentException $e) {
                @unlink($tmp);
                $errors[] = ['name' => $originalName, 'reason' => $e->errorCode];
                continue;
            } catch (\Throwable) {
                @unlink($tmp);
                $errors[] = ['name' => $originalName, 'reason' => 'store_failed'];
                continue;
            }
            $created[] = $result['media'];
        }

        $this->log($request, 'eshop.media_uploaded', $itemId, ['count' => count($created)]);
        return Json::ok($response, ['created' => $created, 'errors' => $errors], $created === [] ? 400 : 201);
    }

    public function reorder(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $order = is_array($body['order'] ?? null) ? $body['order'] : [];
        // Přijmeme jen id, která patří této kartě (guard přes find per tenant).
        $ownIds = array_map(static fn (array $m): int => (int) $m['id'], $this->media->listForItem($supplierId, $itemId));
        $ownSet = array_flip($ownIds);
        $pos = 0;
        foreach ($order as $mid) {
            $mid = (int) $mid;
            if (isset($ownSet[$mid])) {
                $this->media->setDisplayOrder($supplierId, $mid, $pos++);
            }
        }
        return Json::ok($response, $this->media->listForItem($supplierId, $itemId));
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $mid = (int) $args['mid'];
        $existing = $this->media->find($supplierId, $mid);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Médium nenalezeno.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);

        $this->media->updateMeta($supplierId, $mid, [
            'title'         => isset($body['title']) && trim((string) $body['title']) !== '' ? trim((string) $body['title']) : null,
            'alt_text'      => isset($body['alt_text']) && trim((string) $body['alt_text']) !== '' ? trim((string) $body['alt_text']) : null,
            'export_eshop'  => array_key_exists('export_eshop', $body) ? (bool) $body['export_eshop'] : (bool) $existing['export_eshop'],
            'display_order' => array_key_exists('display_order', $body) ? (int) $body['display_order'] : (int) $existing['display_order'],
        ]);

        // Nastavení hlavního obrázku (max 1 na kartu) — v transakci.
        if (array_key_exists('is_primary', $body) && (bool) $body['is_primary'] && !$existing['is_primary']) {
            $pdo = $this->db->pdo();
            $pdo->beginTransaction();
            try {
                $this->media->clearPrimary($supplierId, (int) $existing['stock_item_id']);
                $this->media->setPrimaryFlag($supplierId, $mid, true);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        $this->log($request, 'eshop.media_updated', $mid, []);
        return Json::ok($response, $this->media->find($supplierId, $mid) ?? []);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $mid = (int) $args['mid'];
        $existing = $this->media->find($supplierId, $mid);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Médium nenalezeno.', 404);
        }
        $this->media->delete($supplierId, $mid);
        // Bajty jsou content-addressed a sdílené (DMS i jiné karty mohou referencovat
        // týž sha) — mazání bajtů NEprovádíme zde (bezpečné: cizí referenci bychom
        // rozbili). Osiřelé bajty uklidí údržbová GC úloha. countByStorageKey lze
        // využít později pro bezpečný orphan prune napříč stock_media i document_files.
        $this->log($request, 'eshop.media_deleted', $mid, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * GET /api/eshop/media/{mid}/file — servíruje bajty média. Obrázky inline
     * (pro <img>), ostatní jako attachment. Vždy nosniff + CSP sandbox (obrana
     * proti záměně typu / vloženému skriptu). storage_key = sha256 = jméno na disku.
     */
    public function file(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $mid = (int) $args['mid'];
        $media = $this->media->find($supplierId, $mid);
        if ($media === null) {
            return Json::error($response, 'not_found', 'Médium nenalezeno.', 404);
        }
        $sha = (string) $media['storage_key'];
        $path = $this->storage->pathFor($supplierId, $sha, $sha);
        if (!is_file($path)) {
            return Json::error($response, 'not_found', 'Soubor nenalezen na disku.', 404);
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return Json::error($response, 'not_found', 'Soubor nelze otevřít.', 404);
        }

        $isImage = $media['media_type'] === 'image';
        $mime = (string) ($media['mime_type'] ?? '');
        // Inline jen skutečné obrázky; cokoliv jiného octet-stream + attachment.
        $canInline = $isImage && str_starts_with($mime, 'image/');
        $serveMime = $canInline ? $mime : 'application/octet-stream';
        $safe = preg_replace('/[\r\n"\\\\]/', '_', (string) ($media['original_name'] ?? 'file'));
        $disposition = ($canInline ? 'inline' : 'attachment') . "; filename=\"{$safe}\"";

        $stream = new Stream($fh);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', $serveMime)
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox; style-src 'unsafe-inline'")
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_media',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
