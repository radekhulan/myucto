<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Sets\ProductSetApiService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Support\Pagination;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sady (virtuální sety) ve veřejném API (issue #138).
 *
 *   GET  /api/stock/sets           — seznam sad se stránkováním (q, sku, active, updated_since)
 *   POST /api/stock/sets           — nová sada: karta bez skladové zásoby + definice
 *   POST /api/stock/sets/bulk      — dávkový upsert podle SKU, výsledek po položkách
 *   GET  /api/stock/sets/{id}      — detail sady
 *   PUT  /api/stock/sets/{id}      — nová definice s kontrolou row_version
 *
 * Oprávnění zrcadlí editor setu: čtení `eshop`, zápis `eshop.write`
 * a `stock.items.write`. Na rozdíl od `/api/eshop/sets` je API dostupné i přes token.
 */
final class StockSetAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly ProductSetApiService $sets,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->authorize($request, $response, false, $error)) return $error;
        $query = $request->getQueryParams();
        $filters = [];
        if (isset($query['q']) && trim((string) $query['q']) !== '') $filters['q'] = trim((string) $query['q']);
        if (isset($query['sku']) && trim((string) $query['sku']) !== '') $filters['sku'] = trim((string) $query['sku']);
        if (isset($query['active']) && $query['active'] !== '') {
            $active = filter_var($query['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active === null) return Json::error($response, 'validation_failed', 'active musí být true nebo false.', 400);
            $filters['active'] = $active;
        }
        if (isset($query['updated_since']) && $query['updated_since'] !== '') {
            $since = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $query['updated_since'])
                ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) $query['updated_since']);
            if ($since === false) return Json::error($response, 'validation_failed', 'updated_since musí být datum (YYYY-MM-DD) nebo čas v ISO 8601.', 400);
            $filters['updated_since'] = $since->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        }
        $page = Pagination::fromQuery($query, 50);
        [$rows, $total] = $this->sets->list($this->currentSupplierId($request), $filters, $page['per_page'], $page['offset']);
        return Json::ok($response, Pagination::envelope($rows, $total, $page['page'], $page['per_page']));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->authorize($request, $response, false, $error)) return $error;
        $set = $this->sets->find($this->currentSupplierId($request), (int) $args['id']);
        return $set === null
            ? Json::error($response, 'not_found', 'Sada nenalezena.', 404)
            : Json::ok($response, $set);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->authorize($request, $response, true, $error)) return $error;
        $body = $request->getParsedBody();
        if (!is_array($body)) return Json::error($response, 'validation_failed', 'Tělo požadavku musí být objekt.', 422);
        try {
            $set = $this->sets->create($this->currentSupplierId($request), $body);
        } catch (EshopException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus, $error->details);
        }
        $this->log($request, 'stock.set_created', $set['stock_item_id'], ['sku' => $set['sku'], 'row_version' => $set['row_version']]);
        return Json::ok($response, $set, 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->authorize($request, $response, true, $error)) return $error;
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_int($body['row_version'] ?? null) || $body['row_version'] < 0 || !is_array($body['definition'] ?? null)) {
            return Json::error($response, 'validation_failed', 'Zadejte verzi (row_version) a definici sady (definition).', 422);
        }
        try {
            $set = $this->sets->update($this->currentSupplierId($request), (int) $args['id'], $body['row_version'], $body['definition']);
        } catch (EshopException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus, $error->details);
        }
        $this->log($request, 'stock.set_saved', $set['stock_item_id'], ['row_version' => $set['row_version']]);
        return Json::ok($response, $set);
    }

    public function bulk(Request $request, Response $response): Response
    {
        if (!$this->authorize($request, $response, true, $error)) return $error;
        $body = $request->getParsedBody();
        $rows = is_array($body) ? ($body['sets'] ?? null) : null;
        if (!is_array($rows) || !array_is_list($rows) || $rows === []) {
            return Json::error($response, 'validation_failed', 'Pošlete neprázdné pole sad (sets).', 422);
        }
        if (count($rows) > ProductSetApiService::BULK_LIMIT) {
            return Json::error($response, 'batch_too_large', 'Dávka může obsahovat nejvýše ' . ProductSetApiService::BULK_LIMIT . ' sad.', 422, ['limit' => ProductSetApiService::BULK_LIMIT]);
        }
        $result = $this->sets->bulk($this->currentSupplierId($request), $rows);
        $this->log($request, 'stock.sets_bulk_saved', null, $result['summary']);
        return Json::ok($response, $result);
    }

    private function authorize(Request $request, Response $response, bool $write, ?Response &$error): bool
    {
        if (!$this->requirePermission($request, $response, $write ? 'eshop.write' : 'eshop', $write ? AccessLevel::WRITE : AccessLevel::READ, $error)) return false;
        if ($write && !$this->requirePermission($request, $response, 'stock.items.write', AccessLevel::WRITE, $error)) return false;
        return $this->guardStockEnabled($this->db, $this->currentSupplierId($request), $response, $error);
    }

    private function log(Request $request, string $action, ?int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_item',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
