<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Action\Auth\Tokens\CreateTokenAction;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\LoginFailure;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasswordLoginVerifier;
use MyInvoice\Service\Demo\DemoMode;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\License\LicenseSeatLimitExceeded;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/v1/auth/password-token — výměna e-mailu a hesla (a kódu TOTP, je-li
 * zapnutý) za osobní API token. Pro aplikace, které neumí webové přihlášení
 * (mobilní aplikace): uživatel zadá stejné údaje jako ve webu a token se mu
 * objeví v seznamu API tokenů pod názvem zařízení, odkud ho může zrušit.
 *
 * Body: { email, password, totp?, device_name? }
 *
 * Funkce je ve výchozím stavu vypnutá (`auth.password_token.enabled`), vypnutý
 * endpoint odpovídá 404.
 *
 * Ochrana místo Turnstile, který nativní aplikace projít nemůže:
 *   - BruteForceGuard se stejným počítadlem (e-mail, IP) jako přihlášení webu,
 *     pokusy přes web a přes API se sčítají. Stav, kdy web začne chtít CAPTCHA,
 *     tady znamená dočasné odmítnutí (429), protože CAPTCHA ověřit nejde.
 *   - limit na IP (RateLimitMiddleware, stejný kbelík jako `/api/auth/login`);
 *   - strop neúspěchů jednoho účtu ze všech IP a strop neúspěchů celého endpointu.
 *     Počítají se jen neúspěšné pokusy, takže běžné požadavky vydávání tokenů
 *     ostatním uživatelům nezablokují;
 *   - druhý faktor jako ve webu: zapnuté TOTP je povinné, účet jen s passkey
 *     a instalace s e-mailovým kódem token heslem nedostanou, vynucené MFA bez
 *     silného faktoru také ne, vynucený poskytovatel identity heslo nepřijme vůbec.
 *     Záložní kód se nepřijímá: ve webu otevře jen relaci na opravu faktorů,
 *     token na 90 dní by z něj udělal plnohodnotný přístup;
 *   - stejná práva jako tvorba tokenu ve webu (`profile.tokens` zápis) a stejný
 *     limit vydaných tokenů na uživatele (`rate_limits.token_create_per_hour`);
 *     nový token stejného zařízení nahradí předchozí;
 *   - neexistující účet i špatné heslo vrací stejnou odpověď se srovnatelnou
 *     dobou (PasswordLoginVerifier);
 *   - pokusy jdou do auditu s kanálem a názvem zařízení, heslo ani kód nikdy.
 *
 * Token: rozsah read_write, bez vazby na firmu, platnost jako u tokenu
 * vytvořeného ve webu (ApiTokenService::DEFAULT_EXPIRY_DAYS).
 */
final class PasswordTokenAction
{
    private const DEFAULT_DEVICE_NAME = 'Přihlášení heslem přes API';
    private const LOG_CHANNEL = 'password_token';

    public function __construct(
        private readonly Connection $db,
        private readonly PasswordLoginVerifier $passwordLogin,
        private readonly BruteForceGuard $bruteForce,
        private readonly ApiTokenService $tokens,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly LoginPolicyService $loginPolicy,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly RateLimitMiddleware $rateLimit,
        private readonly PermissionResolver $permissions,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if ($this->config->get('auth.password_token.enabled', false) !== true) {
            return Json::error($response, 'not_found', 'Endpoint nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $totpCode = isset($body['totp']) ? trim((string) $body['totp']) : '';
        $deviceName = self::deviceName((string) ($body['device_name'] ?? ''));

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $userAgent = $request->getHeaderLine('User-Agent');
        $logContext = ['channel' => self::LOG_CHANNEL, 'device_name' => $deviceName];

        // Veřejná ukázka vydá token jen účtům z `demo.password_token_emails` (účet pro review
        // mobilní aplikace). Ostatní dostanou stejnou odpověď jako jiné zápisy ukázky, a to
        // dřív, než se ověří heslo: ukázka nesmí pomáhat hádat hesla ostatních účtů.
        if ($this->demoEnabled($request) && !(new DemoMode($this->config))->allowsPasswordToken($email)) {
            return Json::error($response, 'demo_read_only', DemoReadOnlyMiddleware::READ_ONLY_MESSAGE, 403);
        }

        // Vynucený poskytovatel identity heslo nepřijímá (stejně jako LoginAction).
        $requiredProvider = $this->loginPolicy->requiredProvider();
        if ($requiredProvider !== null) {
            return Json::error(
                $response,
                'sso_required',
                'Přihlaste se přes poskytovatele identity organizace.',
                403,
                ['provider' => $requiredProvider],
            );
        }

        if ($email === '' || $password === '') {
            return self::failure($response, LoginFailure::invalidCredentials());
        }

        // Stropy neúspěchů: brzdí hádání hesel rozložené přes mnoho IP adres, na které
        // per-IP limit ani zámek (e-mail, IP) nedosáhnou. Slot odebere až neúspěch.
        $retryAfter = null;
        foreach ($this->failureBuckets($email) as [$key, $limit, $window]) {
            $retryAfter ??= $this->rateLimit->blockedFor($key, $limit, $window);
        }
        if ($retryAfter !== null) {
            $this->logger->log('auth.login_locked', null, null, null, [
                'email' => $email, 'ip' => $ip, 'state' => 'password_token_failures',
            ] + $logContext, $ip, $userAgent);
            return Json::error($response, 'rate_limited', 'Příliš mnoho neúspěšných pokusů. Zkuste to později.', 429)
                ->withHeader('Retry-After', (string) $retryAfter);
        }

        $state = $this->bruteForce->check($email, $ip);
        if ($state !== BruteForceGuard::STATE_OK) {
            $this->logger->log('auth.login_locked', null, null, null, [
                'email' => $email, 'ip' => $ip, 'state' => $state,
            ] + $logContext, $ip, $userAgent);
            return self::lockedResponse($response, $state);
        }

        $user = $this->passwordLogin->findUser($email);
        $failure = $this->passwordLogin->verifyPassword($user, $email, $password, $ip, $userAgent, $logContext);
        if ($failure !== null || $user === null) {
            $this->recordFailure($email);
            return self::failure($response, $failure ?? LoginFailure::invalidCredentials());
        }
        $userId = (int) $user['id'];

        // Stejná práva jako tvorba tokenu ve webu (RoutePermissionMap: POST /api/auth/tokens
        // = profile.tokens zápis). Klient portálu, technický správce instalace ani
        // neaktivní role token nedostanou.
        $role = $this->permissions->forRoleId((int) $user['role_id']);
        if ($role->isClientType()
            || $role->isInstanceAdmin()
            || !$role->isActive
            || !$role->level('profile.tokens')->allows(AccessLevel::WRITE)) {
            $this->logger->log('auth.login_failed', $userId, 'user', $userId, [
                'email' => $email, 'reason' => 'token_forbidden',
            ] + $logContext, $ip, $userAgent);
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }

        $totpActive = (int) $user['totp_enabled'] === 1 && !empty($user['totp_secret']);
        $hasPasskey = $this->credentials->countActiveForUser($userId) > 0;
        if ($totpActive && $hasPasskey && !$this->mfaPolicy->isMethodAllowed('totp')) {
            // Web by po TOTP chtěl ještě passkey (TOTP tu jako faktor nestačí).
            return $this->unsupportedSecondFactor($response, $user, $email, 'passkey', $ip, $userAgent, $logContext);
        }
        if ($totpActive) {
            if ($totpCode === '') {
                // Heslo prošlo, chybí kód. Není to neúspěch, do zámku se nepočítá.
                return Json::error($response, 'totp_required', 'TOTP kód požadován.', 401, [
                    'methods' => $this->passwordLogin->totpStepMethods($userId),
                ]);
            }
            $failure = $this->passwordLogin->verifyTotp($user, $email, $totpCode, $ip, $userAgent, $logContext);
            if ($failure !== null) {
                $this->recordFailure($email);
                return self::failure($response, $failure, $failure->code === 'invalid_totp' ? [
                    'methods' => $this->passwordLogin->totpStepMethods($userId),
                ] : []);
            }
            $authMethod = 'totp';
            $strong = $this->mfaPolicy->isMethodAllowed('totp');
        } elseif ($hasPasskey) {
            // Účet chráněný passkey: heslo samo by jeho druhý faktor obešlo.
            return $this->unsupportedSecondFactor($response, $user, $email, 'passkey', $ip, $userAgent, $logContext);
        } elseif ((bool) $this->config->get('auth.email_otp.enabled', false)) {
            // Instalace s kódem z e-mailu: bez něj by heslo stačilo tam, kde web chce víc.
            return $this->unsupportedSecondFactor($response, $user, $email, 'email_otp', $ip, $userAgent, $logContext);
        } else {
            $authMethod = 'password';
            $strong = false;
        }

        // Vynucené MFA: web by pustil jen do nastavení druhého faktoru. Token by
        // tu povinnost obešel, proto nevznikne.
        if ($this->mfaPolicy->isRequired() && !$strong) {
            $this->logger->log('auth.login_failed', $userId, 'user', $userId, [
                'email' => $email, 'reason' => 'mfa_setup_required',
            ] + $logContext, $ip, $userAgent);
            return Json::error(
                $response,
                'mfa_setup_required',
                'Instalace vyžaduje dvoufázové ověření. Nastavte si ho ve webu a pak se přihlaste znovu.',
                403,
            );
        }

        $retryAfter = $this->rateLimit->consume(
            'rl:pat:user:' . $userId,
            max(1, (int) $this->config->get('rate_limits.token_create_per_hour', 10)),
            3600,
        );
        if ($retryAfter !== null) {
            return Json::error($response, 'rate_limited', 'Příliš mnoho nových tokenů. Zkuste to později.', 429)
                ->withHeader('Retry-After', (string) $retryAfter);
        }

        $this->passwordLogin->rehashPasswordIfNeeded($user, $password);

        $expiresAt = (new \DateTimeImmutable())->modify('+' . ApiTokenService::DEFAULT_EXPIRY_DAYS . ' days');
        try {
            $token = $this->tokens->generate($userId, null, $deviceName, 'read_write', $expiresAt);
        } catch (LicenseSeatLimitExceeded) {
            return CreateTokenAction::seatLimitError($response);
        }
        // Způsob ověření při vydání: politika přihlášení (AuthMiddleware,
        // LoginPolicyService::authMethodAllowed) podle něj token později odmítne.
        $this->db->pdo()->prepare('UPDATE api_tokens SET issued_auth_method = ? WHERE id = ?')
            ->execute([$authMethod, $token['id']]);
        $replaced = $this->revokePreviousDeviceTokens($userId, $deviceName, (int) $token['id']);

        $this->bruteForce->recordSuccess($email, $ip);
        $this->logger->log('auth.login', $userId, 'user', $userId, [
            'email' => $email, 'auth_method' => $authMethod, 'api_token_id' => $token['id'],
        ] + $logContext, $ip, $userAgent);
        $this->logger->log('api_token.created', $userId, 'api_token', $token['id'], [
            'name' => $deviceName,
            'scope' => 'read_write',
            'supplier_id' => null,
            'prefix' => $token['prefix'],
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'never_expires' => false,
            'replaced_tokens' => $replaced,
        ] + $logContext, $ip, $userAgent);

        return Json::ok($response, [
            'token' => $token['plaintext'],
            'id' => $token['id'],
            'prefix' => $token['prefix'],
            'name' => $deviceName,
            'scope' => 'read_write',
            'expires_at' => $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ], 201)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Stropy neúspěšných pokusů: jeden účet ze všech IP a celý endpoint.
     *
     * @return list<array{0:string,1:int,2:int}>
     */
    private function failureBuckets(string $email): array
    {
        return [
            [
                'rl:password-token:fail:email:' . sha1(mb_strtolower($email)),
                max(1, (int) $this->config->get('rate_limits.password_token_failures_per_hour_per_account', 20)),
                3600,
            ],
            [
                'rl:password-token:fail:all',
                max(1, (int) $this->config->get('rate_limits.password_token_failures_per_min', 60)),
                60,
            ],
        ];
    }

    private function recordFailure(string $email): void
    {
        foreach ($this->failureBuckets($email) as [$key, $limit, $window]) {
            $this->rateLimit->consume($key, $limit, $window);
        }
    }

    /**
     * Nové přihlášení stejného zařízení nahradí jeho předchozí token, aby se
     * opakovaným přihlášením z mobilu nehromadily platné tokeny. Týká se jen
     * nefiremních read_write tokenů téhož uživatele se stejným názvem.
     */
    private function revokePreviousDeviceTokens(int $userId, string $deviceName, int $keepId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE api_tokens SET revoked_at = NOW()
              WHERE user_id = ? AND name = ? AND id <> ? AND supplier_id IS NULL
                AND scope = 'read_write' AND revoked_at IS NULL"
        );
        $stmt->execute([$userId, $deviceName, $keepId]);
        return $stmt->rowCount();
    }

    /**
     * Název tokenu v seznamu API tokenů: bez řídicích znaků, nejvýš 100 znaků.
     */
    private static function deviceName(string $raw): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{C}]/u', ' ', $raw)));
        if ($name === '') {
            return self::DEFAULT_DEVICE_NAME;
        }
        return mb_substr($name, 0, 100);
    }

    /**
     * Zámek nebo stav, kdy by web chtěl CAPTCHA. Retry-After podle okna, ve kterém
     * zámek drží (BruteForceGuard: 5 min, 15 min, 24 h).
     */
    private static function lockedResponse(Response $response, string $state): Response
    {
        [$message, $retryAfter] = match ($state) {
            BruteForceGuard::STATE_LOCKED_24H => ['Účet je zablokovaný na 24 hodin kvůli mnoha selháním.', 86400],
            BruteForceGuard::STATE_LOCKED_15M => ['Účet je zablokovaný na 15 minut kvůli mnoha selháním.', 900],
            default => ['Příliš mnoho neúspěšných pokusů. Zkuste to znovu za několik minut.', 300],
        };

        return Json::error($response, 'too_many_attempts', $message, 429)
            ->withHeader('Retry-After', (string) $retryAfter);
    }

    /**
     * Druhý faktor, který přes API ověřit nejde (passkey, kód z e-mailu). Heslo
     * prošlo, ale token nevznikne; uživatel použije token vytvořený ve webu.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $logContext
     */
    private function unsupportedSecondFactor(
        Response $response,
        array $user,
        string $email,
        string $method,
        string $ip,
        string $userAgent,
        array $logContext,
    ): Response {
        $userId = (int) $user['id'];
        $this->logger->log('auth.login_failed', $userId, 'user', $userId, [
            'email' => $email, 'reason' => 'second_factor_unsupported', 'method' => $method,
        ] + $logContext, $ip, $userAgent);

        return Json::error(
            $response,
            'second_factor_unsupported',
            'Účet se ověřuje způsobem, který přihlášení heslem přes API nepodporuje. Vytvořte si API token ve webu.',
            403,
            ['methods' => [$method]],
        );
    }

    /**
     * Ukázkový režim: atribut z {@see DemoReadOnlyMiddleware}, bez middleware (testy, jiné
     * zapojení) přímo konfigurace se zámkem spravované instalace.
     */
    private function demoEnabled(Request $request): bool
    {
        return $request->getAttribute(DemoReadOnlyMiddleware::ATTR_ENABLED) === true
            || (new DemoMode($this->config))->enabled();
    }

    /**
     * @param array<string,mixed> $extra
     */
    private static function failure(Response $response, LoginFailure $failure, array $extra = []): Response
    {
        $result = Json::error($response, $failure->code, $failure->message, $failure->status, $extra + $failure->extra);
        return $failure->status === 429 ? $result->withHeader('Retry-After', '600') : $result;
    }
}
