<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\OperationalSettingsAccess;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Supplier\SupplierInvoiceDefaults;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Delegovatelné výchozí hodnoty nových dokladů, bez přístupu k ostatním nastavením firmy. */
final class ClientInvoiceDefaultsSettingsAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        if (!OperationalSettingsAccess::invoiceDefaults($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit výchozí hodnoty dokladů.', 403);
        }
        return $this->respond($response, (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0));
    }

    public function update(Request $request, Response $response): Response
    {
        if (!OperationalSettingsAccess::invoiceDefaults($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit výchozí hodnoty dokladů.', 403);
        }
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);

        $body = (array) ($request->getParsedBody() ?? []);
        $unknown = array_values(array_diff(array_keys($body), SupplierInvoiceDefaults::FIELDS));
        if ($unknown !== []) {
            return Json::error($response, 'field_not_delegable', 'Toto nastavení nelze klientské roli delegovat.', 403, ['fields' => $unknown]);
        }
        try {
            $body = SupplierInvoiceDefaults::validate($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        if ($body === []) return $this->respond($response, $supplierId);

        $sets = [];
        $params = [];
        foreach (SupplierInvoiceDefaults::FIELDS as $field) {
            if (!array_key_exists($field, $body)) continue;
            $sets[] = $field . ' = ?';
            $params[] = $body[$field];
        }
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare('UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        $result = $this->respond($response, $supplierId);
        if ($result->getStatusCode() !== 200) return $result;

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log(
            'supplier.invoice_defaults_updated',
            isset($user['id']) ? (int) $user['id'] : null,
            'supplier',
            $supplierId,
            ['fields' => array_keys($body)],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return $result;
    }

    private function respond(Response $response, int $supplierId): Response
    {
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.' . implode(', s.', SupplierInvoiceDefaults::FIELDS) . ', cur.code AS default_currency
               FROM supplier s
          LEFT JOIN currencies cur ON cur.id = s.default_currency_id AND cur.supplier_id = s.id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        $row['id'] = (int) $row['id'];
        return Json::ok($response, SupplierInvoiceDefaults::normalizeResponse($row));
    }
}
