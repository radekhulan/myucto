<?php

declare(strict_types=1);

namespace MyInvoice\Action\Property\Catalog;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Property\Catalog\PropertyLegalRoleCatalog;
use MyInvoice\Service\Property\PropertyException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PropertyLegalRoleCatalogAction
{
    use AccountingActionSupport;

    public function __construct(private readonly PropertyLegalRoleCatalog $catalog) {}

    public function list(Request $request, Response $response): Response
    {
        return $this->run($request, $response, null);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $id = $this->integer($args['unit_id'] ?? null);
        if ($id === null || $id <= 0) return Json::error($response, 'property_invalid_input', 'Zkontrolujte identifikátor jednotky.', 422);
        return $this->run($request, $response, $id);
    }

    private function run(Request $request, Response $response, ?int $unitId): Response
    {
        if (!RequestAuthorization::allows($request, 'property', AccessLevel::READ)) return Json::error($response, 'forbidden', 'Pro tuto operaci nemáte oprávnění.', 403);
        $query = $request->getQueryParams();
        $allowed = $unitId === null ? ['as_of', 'known_at', 'cursor', 'limit', 'supplier_id'] : ['as_of', 'known_at', 'supplier_id'];
        $limit = $query['limit'] ?? 25;
        if (array_diff(array_keys($query), $allowed) !== [] || !is_string($query['as_of'] ?? null) || !is_string($query['known_at'] ?? null)
            || isset($query['cursor']) && !is_string($query['cursor']) || $this->integer($limit) === null || (int) $limit < 1 || (int) $limit > 100) {
            return Json::error($response, 'property_invalid_input', 'Zkontrolujte okamžiky a stránkování právních rolí.', 422);
        }
        try {
            return Json::ok($response, $this->catalog->read($this->currentSupplierId($request), $query['as_of'], $query['known_at'],
                $query['cursor'] ?? null, (int) $limit, $unitId));
        } catch (PropertyException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus, ['details' => $error->details]);
        }
    }

    private function integer(mixed $value): ?int
    {
        return !is_bool($value) && (is_int($value) || is_string($value)) && filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
    }
}
