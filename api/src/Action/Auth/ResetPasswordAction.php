<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ResetPasswordAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoginSessionIssuer $loginIssuer,
        private readonly MfaPolicyService $mfaPolicy,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $tokenRaw = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $confirm  = (string) ($body['password_confirm'] ?? '');

        if ($tokenRaw === '' || $password === '') {
            return Json::error($response, 'invalid_token', 'Token nebo heslo chybí.', 400);
        }
        if ($password !== $confirm) {
            return Json::error($response, 'validation_failed', 'Hesla se neshodují.', 400);
        }

        $tokenHash = hash('sha256', $tokenRaw);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, purpose, expires_at, used_at FROM password_resets WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return Json::error($response, 'invalid_token', 'Neplatný token.', 400);
        }
        if ($row['used_at'] !== null) {
            return Json::error($response, 'token_already_used', 'Token už byl použit.', 410);
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return Json::error($response, 'token_expired', 'Platnost tokenu vypršela.', 410);
        }

        try {
            $hash = $this->hasher->hash($password);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $userId = (int) $row['user_id'];

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE users
                    SET password_hash = ?,
                        totp_secret = CASE WHEN totp_enabled = 0 THEN NULL ELSE totp_secret END
                  WHERE id = ?'
            )->execute([$hash, $userId]);
            $consume = $pdo->prepare(
                'UPDATE password_resets SET used_at = NOW()
                  WHERE id = ? AND used_at IS NULL AND expires_at > NOW()'
            );
            $consume->execute([(int) $row['id']]);
            if ($consume->rowCount() !== 1) {
                $pdo->rollBack();
                return Json::error($response, 'token_already_used', 'Token už není platný.', 410);
            }
            $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM login_otps WHERE user_id = ?')->execute([$userId]);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            // Endpoint je anonymní, takže text výjimky ven nesmí: `PDOException`
            // nese SQLSTATE, jméno tabulky i fragment dotazu — a nezávisle na
            // `app.debug`, takže by to teklo i z produkce. Detail patří do logu,
            // volajícímu stačí, že se změna nepovedla.
            error_log('Reset hesla selhal: ' . $e->getMessage());
            return Json::error($response, 'reset_failed', 'Heslo se nepodařilo změnit.', 500);
        }

        // Invaliduj všechny aktivní sessions
        //
        // ⚠️ API tokeny se tu VĚDOMĚ neruší (rozhodnutí produktu, stejně jako při
        // běžné změně hesla). Tokeny slouží integracím, které na hesle nezávisí;
        // jejich hromadné zrušení při každé obnově hesla by integrace rozbilo.
        // Tokeny se spravují a ruší samostatně v aplikaci; při ztrátě kontroly nad
        // účtem je zruší nouzový `api/bin/reset-mfa.php`.
        $invalidated = $this->sessions->destroyAllForUser($userId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('auth.password_reset', $userId, 'user', $userId, [
            'sessions_invalidated' => $invalidated,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // ⚠️ Sezení dostane JEN první nastavení hesla, ne obnova.
        //
        // U `setup` účet právě vzniká: zákazník otevřel jednorázový odkaz
        // z uvítacího e-mailu a heslo si vymyslel před vteřinou. Poslat ho na
        // přihlašovací formulář, aby ho opsal podruhé, je zbytečná překážka na
        // úplně první obrazovce produktu.
        //
        // U `reset` je to jiná situace a záměrně se nic nemění: účet už
        // existuje a odkaz mohl uniknout. Zůstává tedy `{ok:true}` a přihlášení
        // heslem, ať uniklý odkaz sám o sobě nikdy nedává živé sezení.
        // Rozlišuje se sloupcem `purpose` z migrace 1525, který je fail-closed
        // (výchozí `reset`), takže neznámý historický řádek sezení nedostane.
        if ((string) $row['purpose'] !== 'setup') {
            return Json::ok($response, ['ok' => true]);
        }

        $stmt = $pdo->prepare(
            'SELECT u.id, u.email, u.name, u.role, u.role_id, u.locale, u.password_hash,
                    u.is_active, u.totp_secret, u.totp_enabled, u.session_lock_after_minutes,
                    r.name AS role_name, r.role_type,
                    r.is_active AS role_active, r.system_key
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Neaktivní účet ani rozbitá vazba na roli sezení nedostanou. Heslo je
        // v tu chvíli už nastavené — což je správně, jen se uživatel musí
        // přihlásit normální cestou a narazit na standardní hlášku.
        if (!$user || (int) $user['is_active'] === 0) {
            return Json::ok($response, ['ok' => true]);
        }

        // Úroveň sezení se odvozuje stejně jako v `LoginAction`: heslo samo
        // o sobě dává `basic`, a když instalace MFA vyžaduje, sníží se na
        // `setup`, aby uživatel nejdřív prošel enrollmentem.
        $authContext = SessionAuthContext::basic('password');
        if ($this->mfaPolicy->isRequired()) {
            $authContext = SessionAuthContext::setup($authContext->authMethod);
        }

        return $this->loginIssuer->issue(
            $response,
            $user,
            $ip,
            $request->getHeaderLine('User-Agent'),
            $authContext,
            verifiedPasswordHash: $hash,
        );
    }
}
