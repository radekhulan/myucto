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
 *   admin + ?force=1 + inClosedPeriod přes API token    → 403 'token_write_forbidden'
 *   token s plným zápisem + ?force=1 v uzavřeném,
 *     uzavíraném i k datu zamčeném období               → 409 'period_closed' (i správce)
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

        $force = !empty($request->getQueryParams()['force']);
        // Token s plným zápisem (#371) jedná jako uživatel v aplikaci, ale uzavřené, uzavírané
        // ani k datu zamčené období vynucením nepřebije nikdy, ani jako správce firmy.
        if (!$clientOnly && $force && RequestAuthorization::isSystemWriteToken($request)
            && ($lock->inClosedPeriod || $lock->inClosingPeriod || $lock->dateLocked)) {
            return self::periodClosedForToken($response);
        }

        if ($clientOnly || !$lock->inClosedPeriod) {
            return null;
        }

        if (RequestAuthorization::isCompanyAdmin($request) && $force) {
            // Přebití uzavřeného období je účetní úkon; token ho neprovede ani jako admin.
            if (!RequestAuthorization::mayOverridePeriodProtection($request)) {
                return SessionOnlyAccountingAct::deny($request, $response, 'Úprava dokladu v uzavřeném účetním období')
                    ?? self::periodClosedForToken($response);
            }
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

    private static function periodClosedForToken(Response $response): Response
    {
        return Json::error(
            $response,
            'period_closed',
            'Doklad spadá do uzavřeného, uzavíraného nebo zamčeného účetního období; přes API token ho nelze přebít.',
            409,
        );
    }
}
