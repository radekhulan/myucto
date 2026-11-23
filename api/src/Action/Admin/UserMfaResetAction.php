<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\MfaResetService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/users/{id}/mfa-reset — superadmin resetuje MFA jinému uživateli.
 *
 * Typicky ztracený nebo vyměněný telefon. Superadmin reset potvrzuje vlastním
 * druhým faktorem (passkey, TOTP, nebo účtem organizace při vynuceném
 * poskytovateli) přes jednorázový step-up proof vázaný na resetovaný účet.
 * Heslo ani záložní kód nestačí: kdo sám MFA nemá, cizí resetovat nesmí.
 *
 * Reset zruší faktory a přihlášení uživatele, API tokeny a MCP připojení nechá.
 * Do auditu jde kdo, komu, proč a čím to potvrdil; uživateli přijde e-mail.
 */
final class UserMfaResetAction
{
    private const REASON_MIN = 10;
    private const REASON_MAX = 500;
    /** Pojistka proti hromadnému odebírání faktorů z jednoho účtu superadmina. */
    private const HOURLY_LIMIT = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly MfaStepUpService $stepUp,
        private readonly MfaResetService $reset,
        private readonly LoginPolicyService $loginPolicy,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {}

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (RequestAuthorization::isBearerAuth($request)) {
            return Json::sessionRequired($response);
        }
        if (!RequestAuthorization::canResetUserMfa($request)) {
            return Json::error($response, 'forbidden_permission', 'MFA jiného uživatele může resetovat jen superadmin.', 403);
        }
        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $actorId = (int) ($actor['id'] ?? 0);
        $targetId = (int) ($args['id'] ?? 0);
        if ($targetId === $actorId) {
            return Json::error(
                $response,
                'mfa_reset_self',
                'Vlastní dvoufázové ověření si spravujete v profilu, ne resetem.',
                409,
            );
        }
        if (!$this->loginPolicy->localFactorsUsed()) {
            return Json::error(
                $response,
                'mfa_managed_by_provider',
                'Instalace se přihlašuje účtem organizace, dvoufázové ověření spravuje poskytovatel identity.',
                409,
            );
        }

        $stmt = $this->db->pdo()->prepare('SELECT id, email, name, locale FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($target === false) {
            return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $reason = trim((string) ($body['reason'] ?? ''));
        $length = mb_strlen($reason);
        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            return Json::error(
                $response,
                'validation_error',
                sprintf('Uveďte důvod resetu (%d až %d znaků), zapíše se do auditu.', self::REASON_MIN, self::REASON_MAX),
                422,
                ['field' => 'reason'],
            );
        }
        if ($this->recentResets($actorId) >= self::HOURLY_LIMIT) {
            return Json::error($response, 'too_many_attempts', 'Příliš mnoho resetů za poslední hodinu. Zkuste to později.', 429);
        }

        $operation = MfaStepUpService::userMfaResetOperation($targetId);
        try {
            $proof = $this->stepUp->consume(
                trim((string) ($body['step_up_token'] ?? '')),
                $actorId,
                (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                $operation,
            );
        } catch (OneTimeTokenException|StepUpOperationException $e) {
            return Json::error($response, 'step_up_required', $e->getMessage(), 403, ['operation' => $operation]);
        }

        try {
            $result = $this->reset->reset($targetId, false);
        } catch (\DomainException) {
            return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        }
        $mail = $this->notify($target, (string) ($actor['name'] ?? $actor['email'] ?? ''));

        $this->activity->log(
            'user.mfa_reset',
            $actorId,
            'user',
            $targetId,
            $result + [
                'reason' => $reason,
                'confirmed_by' => $proof->authMethod,
                'target_email' => (string) $target['email'],
                'mail_sent' => $mail['sent'],
                'mail_error' => $mail['error'],
                'via' => 'admin',
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, [
            'ok' => true,
            'mail_sent' => $mail['sent'],
            'passkeys_revoked' => $result['passkeys_revoked'],
            'sessions_invalidated' => $result['sessions_invalidated'],
            'totp_was_enabled' => $result['totp_was_enabled'],
        ]);
    }

    private function recentResets(int $actorId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log
              WHERE user_id = ? AND action = 'user.mfa_reset' AND created_at >= NOW() - INTERVAL 1 HOUR"
        );
        $stmt->execute([$actorId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Upozornění resetovanému uživateli. Reset už proběhl, takže chyba pošty
     * odpověď nezmění; jen se zapíše do auditu a vrátí správci.
     *
     * @param array<string,mixed> $target
     * @return array{sent:bool,error:?string}
     */
    private function notify(array $target, string $actorName): array
    {
        $locale = in_array($target['locale'] ?? 'cs', ['cs', 'en'], true) ? (string) $target['locale'] : 'cs';
        try {
            $this->mailer->sendTemplate('mfa_reset', $locale, [(string) $target['email']], [
                'name' => (string) ($target['name'] ?? ''),
                'actorName' => $actorName,
                'resetAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('j. n. Y H:i'),
                'loginUrl' => rtrim((string) $this->config->get('app.url', ''), '/') . '/login',
            ]);

            return ['sent' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['sent' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
    }
}
