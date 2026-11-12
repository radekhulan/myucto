<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Shoptet\ShoptetDocumentImportService;
use MyInvoice\Service\Shoptet\ShoptetFeedService;
use MyInvoice\Service\Shoptet\ShoptetImportException;
use MyInvoice\Service\Shoptet\ShoptetOrderFileParser;
use MyInvoice\Service\Shoptet\ShoptetOrderImportService;
use MyInvoice\Service\Shoptet\ShoptetSettingsService;
use MyInvoice\Service\Stock\SalesOrderException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Napojení Shoptetu bez API — nastavení, import objednávek a dokladů, feed zásob.
 *
 * Oprávnění zrcadlí RoutePermissionMap: čtení `eshop`, zápis `eshop.write`;
 * zakládání objednávek navíc `stock.orders.write`, import dokladů `invoices.create`.
 */
final class ShoptetAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private const MAX_DOCUMENT_FILES = 50;
    private const MAX_DOCUMENT_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly Connection $db,
        private readonly ShoptetSettingsService $settings,
        private readonly ShoptetOrderImportService $orders,
        private readonly ShoptetDocumentImportService $documents,
        private readonly ShoptetFeedService $feed,
    ) {}

    public function settings(Request $request, Response $response): Response
    {
        return $this->run($request, $response, ['eshop' => AccessLevel::READ], fn (int $sid): array => $this->settingsPayload($sid));
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        return $this->run($request, $response, ['eshop.write' => AccessLevel::WRITE], function (int $sid) use ($request): array {
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                throw new ShoptetImportException('validation_failed', 'Neplatný požadavek.');
            }
            $this->settings->save($sid, $body, $this->userId($request));

            return $this->settingsPayload($sid);
        });
    }

    public function setOrderUrl(Request $request, Response $response): Response
    {
        return $this->run($request, $response, ['eshop.write' => AccessLevel::WRITE], function (int $sid) use ($request): array {
            $body = $request->getParsedBody();
            $url = is_array($body) && is_string($body['url'] ?? null) ? $body['url'] : '';
            $this->settings->setOrderUrl($sid, $url, $this->userId($request));

            return $this->settingsPayload($sid);
        });
    }

    public function clearOrderUrl(Request $request, Response $response): Response
    {
        return $this->run($request, $response, ['eshop.write' => AccessLevel::WRITE], function (int $sid) use ($request): array {
            $this->settings->clearOrderUrl($sid, $this->userId($request));

            return $this->settingsPayload($sid);
        });
    }

    public function previewOrders(Request $request, Response $response): Response
    {
        return $this->run($request, $response, self::ORDER_WRITE, function (int $sid) use ($request): array {
            $file = $request->getUploadedFiles()['file'] ?? null;
            if ($file instanceof UploadedFileInterface) {
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    throw new ShoptetImportException('shoptet_file_required', 'Soubor se nepodařilo nahrát.');
                }
                if ((int) ($file->getSize() ?? 0) > ShoptetOrderFileParser::MAX_BYTES) {
                    throw new ShoptetImportException('shoptet_file_too_large', 'Soubor je větší než 20 MB.');
                }

                return $this->orders->previewUpload($sid, (string) $file->getStream(), $file->getClientFilename(), $this->userId($request));
            }
            $body = $request->getParsedBody();
            if (is_array($body) && ($body['source'] ?? null) === 'url') {
                return $this->orders->previewFromUrl($sid, $this->userId($request));
            }
            throw new ShoptetImportException('shoptet_file_required', 'Nahrajte export objednávek, nebo zvolte stažení z odkazu.');
        }, 201);
    }

    /** @param array<string,string> $args */
    public function applyOrders(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, self::ORDER_WRITE,
            fn (int $sid): array => $this->orders->apply($sid, (int) $args['id'], $this->userId($request)));
    }

    /** @param array<string,string> $args */
    public function discardOrders(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, self::ORDER_WRITE, function (int $sid) use ($args): array {
            $this->orders->discard($sid, (int) $args['id']);

            return ['ok' => true];
        });
    }

    /** @param array<string,string> $args */
    public function eraseOrders(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, self::ORDER_WRITE,
            fn (int $sid): array => $this->orders->erase($sid, (int) $args['id']));
    }

    public function batches(Request $request, Response $response): Response
    {
        return $this->run($request, $response, ['eshop' => AccessLevel::READ], function (int $sid) use ($request): array {
            $kind = (string) ($request->getQueryParams()['kind'] ?? '');

            return ['items' => $this->orders->batches($sid, in_array($kind, ['orders', 'documents'], true) ? $kind : null)];
        });
    }

    /** @param array<string,string> $args */
    public function batch(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, ['eshop' => AccessLevel::READ], fn (int $sid): array =>
            $this->orders->batch($sid, (int) $args['id'])
                ?? throw new ShoptetImportException('shoptet_batch_not_found', 'Dávka nenalezena.', 404));
    }

    public function reviewQueue(Request $request, Response $response): Response
    {
        return $this->run($request, $response, ['eshop' => AccessLevel::READ],
            fn (int $sid): array => ['items' => $this->orders->reviewQueue($sid)]);
    }

    /** @param array<string,string> $args */
    public function markReviewed(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, self::ORDER_WRITE, function (int $sid) use ($request, $args): array {
            $this->orders->markReviewed($sid, (int) $args['id'], $this->userId($request));

            return ['ok' => true];
        });
    }

    public function importDocuments(Request $request, Response $response): Response
    {
        return $this->run($request, $response, [
            'eshop.write' => AccessLevel::WRITE,
            'invoices.create' => AccessLevel::WRITE,
        ], function (int $sid) use ($request): array {
            $files = [];
            $total = 0;
            $walk = function (mixed $node) use (&$walk, &$files, &$total): void {
                if ($node instanceof UploadedFileInterface) {
                    if ($node->getError() !== UPLOAD_ERR_OK) {
                        return;
                    }
                    if (count($files) >= self::MAX_DOCUMENT_FILES) {
                        throw new ShoptetImportException('shoptet_too_many_files', 'Najednou lze nahrát nejvýš 50 souborů.');
                    }
                    $total += (int) ($node->getSize() ?? 0);
                    if ($total > self::MAX_DOCUMENT_BYTES) {
                        throw new ShoptetImportException('shoptet_file_too_large', 'Soubory jsou dohromady větší než 50 MB.');
                    }
                    $files[] = ['name' => (string) ($node->getClientFilename() ?? 'doklad.isdoc'), 'content' => (string) $node->getStream()];
                } elseif (is_array($node)) {
                    foreach ($node as $child) {
                        $walk($child);
                    }
                }
            };
            $walk($request->getUploadedFiles());

            return $this->documents->import($sid, $files, (int) $this->userId($request));
        }, 201);
    }

    public function rotateFeed(Request $request, Response $response): Response
    {
        return $this->run($request, $response, ['eshop.write' => AccessLevel::WRITE], function (int $sid) use ($request): array {
            $token = $this->settings->rotateFeedToken($sid, $this->userId($request));

            return ['token' => $token, 'path' => '/api/public/shoptet/feed/' . $token] + $this->settingsPayload($sid);
        });
    }

    public function disableFeed(Request $request, Response $response): Response
    {
        return $this->run($request, $response, ['eshop.write' => AccessLevel::WRITE], function (int $sid) use ($request): array {
            $this->settings->disableFeed($sid, $this->userId($request));

            return $this->settingsPayload($sid);
        });
    }

    public function downloadFeed(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop', AccessLevel::READ, $error)) {
            return $error;
        }
        $sid = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $sid, $response, $error)) {
            return $error;
        }
        $format = (string) ($request->getQueryParams()['format'] ?? 'xml');
        $settings = $this->settings->get($sid);
        if ($format === 'csv') {
            $out = $this->feed->csv($sid, $settings);
            $body = $out['csv'];
            $type = 'text/csv; charset=utf-8';
            $name = 'shoptet-produkty.csv';
        } else {
            $out = $this->feed->xml($sid, $settings);
            $body = $out['xml'];
            $type = 'application/xml; charset=utf-8';
            $name = 'shoptet-feed.xml';
        }
        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('X-Shoptet-Items', (string) $out['items'])
            ->withHeader('X-Shoptet-Skipped', (string) count($out['skipped']))
            ->withHeader('Cache-Control', 'no-store');
    }

    /** Požadovaná práva pro zakládání objednávek. */
    private const ORDER_WRITE = [
        'eshop.write' => AccessLevel::WRITE,
        'stock.orders.write' => AccessLevel::WRITE,
    ];

    /** @return array<string,mixed> */
    private function settingsPayload(int $sid): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, name, is_default FROM warehouses WHERE supplier_id = ? AND is_active = 1 ORDER BY is_default DESC, name'
        );
        $stmt->execute([$sid]);

        return [
            'settings' => $this->settings->present($sid),
            'warehouses' => array_map(static fn (array $w): array => [
                'id' => (int) $w['id'], 'code' => (string) $w['code'], 'name' => (string) $w['name'], 'is_default' => (bool) $w['is_default'],
            ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []),
        ];
    }

    /** @param array<string,AccessLevel> $permissions */
    private function run(Request $request, Response $response, array $permissions, callable $handler, int $status = 200): Response
    {
        foreach ($permissions as $permission => $level) {
            if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
                return $error;
            }
        }
        if ($request->getMethod() !== 'GET' && !RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $sid = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $sid, $response, $error)) {
            return $error;
        }
        try {
            return Json::ok($response, $handler($sid), $status);
        } catch (ShoptetImportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (SalesOrderException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }
}
