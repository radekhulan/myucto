<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Repository\UserSessionRepository;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Update\VersionService;
use MyInvoice\Service\Update\UpdateStatusView;
use MyInvoice\Service\Update\NativeUpdateService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin endpointy pro správu upgradu:
 *   GET  /api/admin/update/status     — plný stav (s release notes)
 *   GET  /api/admin/update/preflight  — může nativní instalace updatovat sama?
 *   POST /api/admin/update/refresh    — fresh fetch z GitHub Releases API
 *   POST /api/admin/update/trigger    — zařadit upgrade do fronty (Docker)
 *                                       nebo spustit nativní worker
 */
final class UpdateAction
{
    public function __construct(
        private readonly VersionService $version,
        private readonly ActivityLogger $logger,
        // H-02: na spravované flotile nasazuje verzi provozovatel. Self-update
        // by ji rozešel, proto tady končí všechno kromě čtení stavu — a končí
        // to na endpointu, ne až v UI.
        private readonly ManagedModeGuard $managed,
        private readonly UserSessionRepository $sessions,
    ) {}

    /**
     * Stav zůstává čitelný i ve spravované instalaci — UI z něj skládá větu
     * „běží verze X, aktualizuje provozovatel". Skrytá obrazovka by vypadala
     * jako rozbitá funkce.
     */
    public function status(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        $status = $this->version->getStatus();
        $status['other_recent_sessions'] = $this->otherRecentSessions($request);
        return Json::ok($response, $status);
    }

    /**
     * Preflight nativního upgradu. Samostatný endpoint (ne součást `status`),
     * protože zapisuje probe soubory pro kontrolu práv — nemá běžet při
     * každém 5s pollu.
     */
    public function preflight(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        if (($locked = $this->denyManaged($response)) !== null) return $locked;
        $target = $request->getQueryParams()['target_version'] ?? null;
        return Json::ok($response, $this->version->nativePreflight(
            is_string($target) && $target !== '' ? $target : null
        ));
    }

    public function refresh(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err, true)) return $err;
        if (($locked = $this->denyManaged($response)) !== null) return $locked;
        $status = $this->version->refreshLatestVersion();
        $status['other_recent_sessions'] = $this->otherRecentSessions($request);
        return Json::ok($response, RequestAuthorization::isInstanceAdmin($request)
            ? UpdateStatusView::technical($status, $this->managed->isManaged(), $status['other_recent_sessions']) : $status);
    }

    public function trigger(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err, true)) return $err;
        if (($locked = $this->denyManaged($response)) !== null) return $locked;
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $body = (array) ($request->getParsedBody() ?? []);
        $technical = RequestAuthorization::isInstanceAdmin($request);
        if ($technical) {
            $status = $this->version->getStatus();
            $target = $status['latest'] ?? null;
            if (!is_string($target) || !NativeUpdateService::isValidVersion($target)
                || empty($status['has_update']) || !empty($status['cache_stale'])
                || !empty($status['last_check_error']) || !empty($status['upgrade_in_progress'])) {
                return Json::error($response, 'update_not_available', 'Nejprve ověřte dostupnost aktualizace.', 409);
            }
            if (array_key_exists('target_version', $body) && $body['target_version'] !== $target) {
                return Json::error($response, 'validation_failed', 'Lze spustit pouze aktuálně nabízenou aktualizaci.', 400);
            }
        } else $target = isset($body['target_version']) ? (string) $body['target_version'] : null;

        if (array_key_exists('confirm_active_sessions', $body) && !is_bool($body['confirm_active_sessions'])) {
            return Json::error($response, 'validation_failed', 'Potvrzení aktivních relací musí být boolean.', 400);
        }
        $otherSessions = $this->otherRecentSessions($request);
        if ($otherSessions > 0 && ($body['confirm_active_sessions'] ?? false) !== true) {
            return Json::error($response, 'active_sessions', 'Jsou připojeni další uživatelé. Potvrďte přerušení práce.', 409,
                ['other_recent_sessions' => $otherSessions]);
        }

        $result = $this->version->triggerUpgrade($target, (string) ($user['email'] ?? 'unknown'));

        $this->logger->log(
            'system.upgrade.trigger',
            (int) ($user['id'] ?? 0),
            null,
            null,
            [
                'environment'    => $result['environment'] ?? null,
                'target_version' => $result['target_version'] ?? null,
                'status'         => $result['status'] ?? null,
            ]
        );

        return Json::ok($response, $technical
            ? array_intersect_key($result, array_flip(['status', 'environment', 'target_version'])) : $result);
    }

    public function cancel(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        if (($locked = $this->denyManaged($response)) !== null) return $locked;
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $result = $this->version->cancelUpgrade();
        $this->logger->log('system.upgrade.cancel', (int) ($user['id'] ?? 0), null, null, [
            'cleared' => $result['cleared'] ?? false,
        ]);
        return Json::ok($response, $result);
    }

    private function otherRecentSessions(Request $request): int
    {
        $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
        return $this->sessions->otherRecentCount((string) ($session['session_family_id'] ?? ''));
    }

    private function denyManaged(Response $response): ?Response
    {
        return $this->managed->deny($response, ManagedModeGuard::CAPABILITY_SELF_UPDATE);
    }

    private function isAdmin(Request $request, Response $response, ?Response &$err, bool $allowTechnical = false): bool
    {
        if (!RequestAuthorization::isSuperadmin($request)
            && (!$allowTechnical || !RequestAuthorization::canManageInstance($request))) {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }
}
