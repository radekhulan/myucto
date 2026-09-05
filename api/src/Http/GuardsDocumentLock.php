<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLock;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Guard helper zámku dokladů (Epic F6, §4.2). Vyžaduje na akci `$this->logger`
 * (ActivityLogger) a `$this->ipMatcher` (IpMatcher).
 *
 *   client + lockedForClient()                          → 403 'document_locked'
 *   non-client + inClosedPeriod && !(admin && ?force=1) → 409 'period_closed'
 *   admin + ?force=1 + inClosedPeriod                   → null + ActivityLogger warning
 *   client + ?force=1                                   → force IGNOROVÁN (M6)
 *   jinak                                               → null (pokračuj)
 *
 * `$clientOnly = true` = 409 větev se neaplikuje (např. DELETE attachments — nejsou
 * účetní data, staff projde i v zavřeném období).
 */
trait GuardsDocumentLock
{
    private function denyIfLocked(
        Request $request,
        Response $response,
        DocumentLock $lock,
        string $entityType,
        ?int $entityId,
        bool $clientOnly = false,
    ): ?Response {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (RequestAuthorization::isClientType($request)) {
            // ?force=1 je pro klienta VŽDY ignorován (M6) — žádná admin větev.
            if ($lock->lockedForClient()) {
                return Json::error(
                    $response,
                    'document_locked',
                    'Doklad je uzamčený účetními pravidly — změny vyřídí vaše účetní.',
                    403,
                );
            }
            return null;
        }

        if ($clientOnly || !$lock->inClosedPeriod) {
            return null;
        }

        if (RequestAuthorization::isCompanyAdmin($request) && !empty($request->getQueryParams()['force'])) {
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log(
                'document_lock.force_override',
                isset($user['id']) ? (int) $user['id'] : null,
                $entityType,
                ($entityId !== null && $entityId > 0) ? $entityId : null,
                ['reasons' => $lock->reasons(), 'period_status' => $lock->periodStatus],
                $ip,
                $request->getHeaderLine('User-Agent'),
                SupplierGuard::currentId($request),
            );
            return null;
        }

        return Json::error($response, 'period_closed', 'Doklad spadá do uzavřeného účetního období.', 409);
    }
}
