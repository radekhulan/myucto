<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Automation\AutomationFeedService;
use MyInvoice\Service\Tenant\TenantDomainContext;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * `scope=group` u sestav po dimenzi: globální typ (sdílený skupinou firem) se sečte za
 * všechny firmy skupiny, ke kterým má uživatel účetní přístup (stejné pravidlo jako
 * přehled automatizace napříč firmami). Firmy, které nevidí, se do součtu nedostanou
 * a odpověď je vyjmenuje jen počtem.
 */
trait DimensionGroupScope
{
    /**
     * @param array<string,mixed> $type typ dimenze z {@see DimensionRepository::findType()}
     * @return array{0:list<int>, 1:int} firmy do součtu (aktuální první) a počet skrytých
     */
    protected function groupScopeSuppliers(
        Request $request,
        DimensionRepository $dimensions,
        AutomationFeedService $feed,
        int $supplierId,
        array $type,
    ): array {
        $supplierIds = [$supplierId];
        $hidden = 0;
        if (($request->getQueryParams()['scope'] ?? '') !== 'group' || ($type['level'] ?? null) !== 'global') {
            return [$supplierIds, 0];
        }
        $members = array_column($dimensions->groupMembers((int) $type['supplier_group_id']), 'id');
        if (self::isSupplierBoundRequest($request)) {
            return [[$supplierId], count(array_diff($members, [$supplierId]))];
        }
        $allowed = $feed->allowedSupplierIds((int) ($this->userId($request) ?? 0), RequestAuthorization::isSuperadmin($request), RequestAuthorization::boundSupplierId($request), $request);
        foreach ($members as $member) {
            if ($member === $supplierId) {
                continue;
            }
            if (in_array($member, $allowed, true)) {
                $supplierIds[] = $member;
            } else {
                $hidden++;
            }
        }
        return [$supplierIds, $hidden];
    }

    protected static function isSupplierBoundRequest(Request $request): bool
    {
        $token = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);
        if (is_array($token) && ($token['supplier_id'] ?? null) !== null) {
            return true;
        }
        $domain = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        return $domain instanceof TenantDomainContext && $domain->locksSupplier();
    }

    private static function isReportDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
