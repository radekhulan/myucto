<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ChangePasswordAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly BruteForceGuard $bruteForce,
        private readonly ApiTokenService $tokens,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $token = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        $body = (array) ($request->getParsedBody() ?? []);
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        $confirm = (string) ($body['new_password_confirm'] ?? '');

        if ($new !== $confirm) {
            return Json::error($response, 'validation_failed', 'Nová hesla se neshodují.', 400);
        }

        // Ověř current
        $stmt = $this->db->pdo()->prepare('SELECT email, password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return Json::error($response, 'invalid_current_password', 'Aktuální heslo není správné.', 400);
        }

        // Ověření aktuálního hesla z cizí session nesmí být neomezené hádání:
        // počítá se do stejného brute-force zámku jako přihlášení a tvorba tokenu.
        $email = (string) ($row['email'] ?? '');
        $bfState = $this->bruteForce->check($email, $ip);
        if (in_array($bfState, [BruteForceGuard::STATE_LOCKED_15M, BruteForceGuard::STATE_LOCKED_24H], true)) {
            return Json::error($response, 'too_many_attempts', 'Příliš mnoho pokusů. Zkus to později.', 429);
        }
        if (!$this->hasher->verify($current, (string) $row['password_hash'])) {
            $this->bruteForce->recordFailure($email, $ip);
            $this->logger->log('auth.password_change_failed', $userId, 'user', $userId, [
                'reason' => 'current_password',
            ], $ip, $request->getHeaderLine('User-Agent'));
            return Json::error($response, 'invalid_current_password', 'Aktuální heslo není správné.', 400);
        }

        try {
            $newHash = $this->hasher->hash($new);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $this->db->pdo()->prepare(
            'UPDATE users
                SET password_hash = ?,
                    totp_secret = CASE WHEN totp_enabled = 0 THEN NULL ELSE totp_secret END
              WHERE id = ?'
        )
            ->execute([$newHash, $userId]);

        // Invaliduj všechny ostatní sessions kromě této
        $invalidated = $this->sessions->destroyAllForUser($userId, $token);

        // ⚠️ API tokeny se při změně hesla VĚDOMĚ neruší (rozhodnutí produktu).
        // Slouží integracím, které na hesle nezávisí, a jejich zrušení by je
        // rozbilo. Tokeny se spravují a ruší samostatně v aplikaci; počet
        // aktivních tokenů se proto vrací, aby je uživatel mohl zkontrolovat.
        $activeTokens = $this->tokens->countActiveForUser($userId);

        $this->logger->log('auth.password_changed', $userId, 'user', $userId, [
            'sessions_invalidated' => $invalidated,
            'active_api_tokens' => $activeTokens,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'ok' => true,
            'sessions_invalidated' => $invalidated,
            'active_api_tokens' => $activeTokens,
        ]);
    }
}
