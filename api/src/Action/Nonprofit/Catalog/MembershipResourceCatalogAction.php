<?php

declare(strict_types=1);

namespace MyInvoice\Action\Nonprofit\Catalog;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipPublicCatalog;
use MyInvoice\Service\Property\PropertyException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MembershipResourceCatalogAction
{
    use AccountingActionSupport;

    public function __construct(private readonly MembershipPublicCatalog $catalog) {}

    public function roles(Request $request, Response $response, array $args = []): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) return Json::error($response, 'forbidden', 'Pro tuto operaci nemáte oprávnění.', 403);
        $query = $request->getQueryParams();
        $detail = isset($args['member_id']);
        $allowed = $detail ? ['as_of', 'known_at', 'supplier_id'] : ['as_of', 'known_at', 'cursor', 'limit', 'supplier_id'];
        $limit = $query['limit'] ?? 25;
        if (array_diff(array_keys($query), $allowed) !== [] || !is_string($query['as_of'] ?? null)
            || (isset($query['known_at']) && !is_string($query['known_at'])) || (isset($query['cursor']) && !is_string($query['cursor']))
            || is_bool($limit) || filter_var($limit, FILTER_VALIDATE_INT) === false || (int) $limit < 1 || (int) $limit > 100) {
            return Json::error($response, 'nonprofit_membership_catalog_query_invalid', 'Neplatný filtr nebo stránkování.', 422);
        }
        try {
            return Json::ok($response, $this->catalog->roles($this->currentSupplierId($request), $query['as_of'], $query['known_at'] ?? null,
                $query['cursor'] ?? null, (int) $limit, $detail ? (int) $args['member_id'] : null));
        } catch (MembershipException|PropertyException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus);
        }
    }

    public function receivables(Request $request, Response $response, array $args = []): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) return Json::error($response, 'forbidden', 'Pro tuto operaci nemáte oprávnění.', 403);
        $query = $request->getQueryParams();
        $detail = isset($args['claim_id']);
        $allowed = $detail ? ['supplier_id'] : ['member_id', 'cursor', 'limit', 'supplier_id'];
        $limit = $query['limit'] ?? 25;
        $member = $query['member_id'] ?? null;
        if (array_diff(array_keys($query), $allowed) !== [] || (!$detail && (is_bool($member) || filter_var($member, FILTER_VALIDATE_INT) === false || (int) $member <= 0))
            || isset($query['cursor']) && !is_string($query['cursor']) || is_bool($limit) || filter_var($limit, FILTER_VALIDATE_INT) === false
            || (int) $limit < 1 || (int) $limit > 100) {
            return Json::error($response, 'nonprofit_membership_catalog_query_invalid', 'Neplatný filtr nebo stránkování.', 422);
        }
        try {
            return Json::ok($response, $this->catalog->receivables($this->currentSupplierId($request), $detail ? null : (int) $member,
                $query['cursor'] ?? null, (int) $limit, $detail ? (int) $args['claim_id'] : null));
        } catch (MembershipException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus);
        }
    }
}
