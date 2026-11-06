<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use MyInvoice\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Data zakázek a jejich přiřazování k dokladům patří k oprávnění `projects`.
 *
 * Route mapa pouští zařazení dokladu k zakázce (`POST /api/invoices/{id}/project`,
 * `POST /api/purchase-invoices/{id}/project`) i detail odběratele pod právem dokladu
 * nebo klienta. Výchozí klientská role `projects` nemá, a přesto přes tyto cesty
 * četla zakázky odběratele a přeřazovala je na zaúčtovaných dokladech. Tahle
 * třída je jediné místo, kde se to rozhoduje.
 */
final class ProjectAssignmentAccess
{
    public static function canReadProjects(Request $request): bool
    {
        return RequestAuthorization::allows($request, 'projects', AccessLevel::READ);
    }

    public static function denyWithoutProjects(Request $request, Response $response): ?Response
    {
        if (self::canReadProjects($request)) {
            return null;
        }

        return Json::error($response, 'forbidden_permission', 'Zařazení k zakázce vyžaduje oprávnění k zakázkám.', 403);
    }
}
