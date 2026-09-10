<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Integration\IntegrationChangeFeedService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class IntegrationChangeFeedAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly IntegrationChangeFeedService $changes,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop', AccessLevel::READ, $error)) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $error)) {
            return $error;
        }
        $query = $request->getQueryParams();
        foreach (['after_cursor' => 0, 'limit' => 250] as $key => $default) {
            if (isset($query[$key]) && (!is_scalar($query[$key]) || !ctype_digit((string) $query[$key]))) {
                return Json::error($response, 'validation_failed', 'Neplatný cursor nebo limit.', 400);
            }
        }
        $result = $this->changes->read($supplierId, (int) ($query['after_cursor'] ?? 0), (int) ($query['limit'] ?? 250));
        return Json::ok($response, $result, $result['cursor_expired'] ? 410 : 200);
    }
}
