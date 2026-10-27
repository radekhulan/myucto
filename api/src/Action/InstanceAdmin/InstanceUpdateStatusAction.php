<?php

declare(strict_types=1);

namespace MyInvoice\Action\InstanceAdmin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\UserSessionRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Update\VersionService;
use MyInvoice\Service\Update\UpdateStatusView;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Technické čtení posledního známého stavu bez interních logů a cest updateru. */
final class InstanceUpdateStatusAction
{
    public function __construct(private readonly VersionService $version, private readonly ManagedModeGuard $managed, private readonly UserSessionRepository $sessions) {}

    public function status(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        if (!RequestAuthorization::canManageInstance($request)) {
            return Json::error($response, 'forbidden_permission', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
        return Json::ok($response, UpdateStatusView::technical($this->version->getStatus(), $this->managed->isManaged(),
            $this->sessions->otherRecentCount((string) ($session['session_family_id'] ?? ''))));
    }
}
