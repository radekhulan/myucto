<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\Oidc\ExternalIdentityRepository;
use MyInvoice\Service\Auth\Oidc\OidcInviteService;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa propojení účtů s poskytovateli identity (Nastavení → Uživatelé).
 *
 * Pozvánka se posílá jen e-mailem, odkaz se správci nevrací. Kdo pozvánku
 * použije, naváže na pozvaný účet svou identitu, takže by si s ní správce
 * mohl připojit vlastní identitu k cizímu účtu.
 */
final class UserExternalIdentityAdminAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ExternalIdentityRepository $identities,
        private readonly OidcInviteService $invites,
        private readonly OidcProviderRegistry $providers,
        private readonly Mailer $mailer,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
    ) {}

    public function list(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::canManageExternalIdentities($request)) {
            return Json::error($response, 'forbidden_permission', 'Pouze superadmin.', 403);
        }
        $user = $this->user((int) ($args['id'] ?? 0));
        if ($user === null) {
            return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        }
        return Json::ok($response, [
            'identities' => array_map(
                ExternalIdentityRepository::present(...),
                $this->identities->listForUser((int) $user['id']),
            ),
            'providers' => $this->providers->publicList(),
        ]);
    }

    public function invite(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::canManageExternalIdentities($request)) {
            return Json::error($response, 'forbidden_permission', 'Pouze superadmin.', 403);
        }
        $user = $this->user((int) ($args['id'] ?? 0));
        if ($user === null) {
            return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        }
        if ((int) $user['is_active'] !== 1) {
            return Json::error($response, 'user_inactive', 'Deaktivovanému uživateli pozvánku neposíláme.', 409);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $provider = $this->providers->isEnabled()
            ? $this->providers->get((string) ($body['provider'] ?? ''))
            : null;
        if ($provider === null) {
            return Json::error($response, 'sso_disabled', 'Poskytovatel identity není v této instalaci zapnutý.', 422);
        }

        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) $user['id'];
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $token = $this->invites->issue($userId, $provider->id, isset($actor['id']) ? (int) $actor['id'] : null);
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $locale = in_array($user['locale'], ['cs', 'en'], true) ? (string) $user['locale'] : 'cs';
        try {
            $smtp = $this->mailer->sendTemplate('user_sso_invite', $locale, [(string) $user['email']], [
                'name' => (string) ($user['name'] ?? ''),
                'provider' => $provider->label,
                'inviteLink' => $appUrl . '/api/auth/oidc/' . $provider->id . '/login?invite=' . $token,
                'expiresIn' => $locale === 'en' ? '7 days' : '7 dní',
            ]);
        } catch (\Throwable $e) {
            $this->activity->log('user.invite_mail_failed', isset($actor['id']) ? (int) $actor['id'] : null, 'user', $userId, [
                'to' => [(string) $user['email']], 'purpose' => 'sso_invite', 'error' => $e->getMessage(),
            ], $ip, $request->getHeaderLine('User-Agent'));
            return Json::error($response, 'mail_failed', 'Pozvánku se nepodařilo odeslat. Zkontrolujte nastavení odchozí pošty.', 502);
        }
        $this->activity->log('user.sso_invite_sent', isset($actor['id']) ? (int) $actor['id'] : null, 'user', $userId, [
            'to' => [(string) $user['email']], 'provider' => $provider->id, 'smtp_response' => $smtp,
        ], $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, ['ok' => true]);
    }

    public function unlink(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::canManageExternalIdentities($request)) {
            return Json::error($response, 'forbidden_permission', 'Pouze superadmin.', 403);
        }
        $userId = (int) ($args['id'] ?? 0);
        $identityId = (int) ($args['identityId'] ?? 0);
        $row = $this->identities->findForUser($userId, $identityId);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Propojení nenalezeno.', 404);
        }
        $this->identities->unlink($userId, $identityId);
        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->activity->log('auth.oidc_unlinked', isset($actor['id']) ? (int) $actor['id'] : null, 'user', $userId, [
            'provider' => $row['provider'], 'identity_id' => $identityId, 'by' => 'admin',
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));
        return Json::ok($response, ['ok' => true]);
    }

    /** @return array<string,mixed>|null */
    private function user(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, email, name, locale, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
