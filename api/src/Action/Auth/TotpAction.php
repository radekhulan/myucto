<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Auth\TotpEnrollmentException;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Self-service TOTP (2FA) endpointy pro přihlášeného uživatele:
 *   POST /api/auth/totp/setup   — vygeneruje secret + QR (pending — uloží do DB ale enabled=0)
 *   POST /api/auth/totp/enable  — ověří kód a flipne totp_enabled=1
 *   GET  /api/auth/totp/status  — vrátí { enabled: bool }
 *
 * Disable: CLI `php api/bin/reset-2fa.php <email>` (fallback ručně v DB).
 *
 * ⚠️ Zřízení TOTP je „přidání silného faktoru" a samotná session na něj nestačí —
 * jinak by unesená session (ukradená cookie, odemčené zařízení) útočníkovi
 * založila vlastní druhý faktor, kterým by pak prošel každou step-up bránou
 * ({@see \MyInvoice\Action\Auth\Tokens\CreateTokenAction} vydá trvalý API token
 * na TOTP místo hesla, {@see MfaStepUpService} pustí regeneraci záložních kódů
 * i zobrazení hesla k zálohám). Proto `setup()` vyžaduje totéž co registrace
 * passkey ({@see PasskeyAction::registerOptions()}): má-li uživatel aktivní
 * passkey, jednorázový step-up proof pro {@see MfaStepUpService::OPERATION_TOTP_ENABLE};
 * jinak aktuální heslo (s brute-force ochranou jako u vydání API tokenu).
 * `enable()` pak aktivuje výhradně secret, který takto autorizovaný `setup()`
 * uložil, a to atomicky — souběžná výměna secretu nebo opakovaná aktivace selže.
 */
final class TotpAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly TotpService $totp,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SecretEncryption $crypto,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly MfaRecoveryCodeService $recoveryCodes,
        private readonly SessionManager $sessions,
        private readonly SessionCookieFactory $sessionCookies,
        private readonly ClockInterface $clock,
        private readonly PasswordHasher $passwords,
        private readonly BruteForceGuard $bruteForce,
        private readonly MfaProtectedOperationService $protectedOperations,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly \MyInvoice\Service\Auth\LoginPolicyService $loginPolicy,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if ($user === null) return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);

        return Json::ok($response, [
            'enabled' => (bool) $user['totp_enabled'],
        ]);
    }

    public function setup(Request $request, Response $response): Response
    {
        // Správa faktorů je jen pro webovou session — API token je nesmí měnit,
        // stejně jako u passkey. BEARER_ALLOWED tenhle prefix nepouští, ale gate
        // nesmí záviset jen na allowlistu jinde.
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Zřízení TOTP je dostupné pouze z přihlášené webové session.');
        }
        $user = $this->user($request);
        if ($user === null) return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        if (!$this->mfaPolicy->isMethodAllowed('totp')) {
            return Json::error($response, 'mfa_method_not_allowed', 'TOTP není v této instalaci povolené.', 403);
        }

        // Nový secret pokaždé — pokud už totp_enabled=1, vrať 409 (reset přes CLI)
        if ((int) $user['totp_enabled'] === 1) {
            return Json::error($response, 'already_enabled', 'TOTP už je aktivní. Pro reset použij: php api/bin/reset-2fa.php <email>.', 409);
        }

        $authorization = $this->authorizeSetup($request, $response, $user);
        if ($authorization instanceof Response) {
            return $authorization;
        }

        $secret = TotpService::generateSecret();
        try {
            $encrypted = $this->crypto->encrypt($secret);
        } catch (\RuntimeException) {
            return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
        }
        try {
            $reauthMethod = $this->protectedOperations->storePendingTotpSecret(
                (int) $user['id'],
                (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                (string) $user['password_hash'],
                $encrypted,
                $authorization['step_up_token'],
                $authorization['enrollment_token'] ?? null,
            );
        } catch (OneTimeTokenException|StepUpOperationException) {
            if (($authorization['enrollment_token'] ?? null) !== null) {
                return Json::error($response, 'enrollment_authorization_invalid', 'Zadej znovu aktuální heslo.', 403);
            }
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('auth.totp_setup_reauth_failed', (int) $user['id'], 'user', (int) $user['id'], ['reason' => 'step_up'], $ip, $request->getHeaderLine('User-Agent'));
            return Json::error($response, 'step_up_proof_invalid', 'Je vyžadováno nové ověření silným faktorem.', 403);
        } catch (TotpEnrollmentException $e) {
            if ($e->reason === TotpEnrollmentException::ALREADY_ENABLED) {
                return Json::error($response, 'already_enabled', 'TOTP už je aktivní. Pro reset použij: php api/bin/reset-2fa.php <email>.', 409);
            }
            if ($e->reason === TotpEnrollmentException::SESSION_INVALID) {
                return Json::error($response, 'session_expired', 'Přihlášení už není platné.', 401);
            }
            return Json::error($response, 'enrollment_stale', 'Autorizace zřízení TOTP mezitím přestala platit. Začni znovu.', 409);
        }
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('auth.totp_setup', (int) $user['id'], 'user', (int) $user['id'], ['reauth' => $reauthMethod], $ip, $request->getHeaderLine('User-Agent'));

        $issuer = parse_url((string) $this->config->get('app.url', 'MyUcto.cz'), PHP_URL_HOST) ?: 'MyUcto.cz';
        $uri = $this->totp->provisioningUri($secret, (string) $user['email'], $issuer);

        // QR kód jako data URI (PNG base64) — frontend vloží do <img src>
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel'        => EccLevel::M,
            'scale'           => 6,
            'imageBase64'     => true,
            'quietzoneSize'   => 2,
        ]);
        $qrDataUri = (new QRCode($options))->render($uri);

        // Provisioning materiál je tajemství — nesmí skončit v žádné cache.
        return Json::ok($response, [
            'secret'      => $secret,        // pro manuální vložení do app
            'uri'         => $uri,
            'qr_data_uri' => $qrDataUri,
            'issuer'      => $issuer,
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Opětovné prokázání identity před zřízením TOTP. Passkey proof se spotřebuje
     * až spolu se zápisem secretu v jedné transakci.
     *
     * @param array<string,mixed> $user
     * @return Response|array{step_up_token:?string,enrollment_token?:string}
     */
    private function authorizeSetup(Request $request, Response $response, array $user): Response|array
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $userId = (int) $user['id'];
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $userAgent = $request->getHeaderLine('User-Agent');

        // TOTP tu aktivní být nemůže (409 výš), takže jediný silný faktor je passkey.
        // Počítá se bez ohledu na aktuální MFA politiku: zakázaný passkey nesmí
        // tiše přepnout na slabší heslovou větev.
        // Proof z přihlášení u poskytovatele identity zastoupí heslo i u účtu bez
        // passkey; spotřebuje se stejně jako passkey proof (MfaProtectedOperationService).
        $stepUpToken = trim((string) ($body['step_up_token'] ?? ''));
        if ($this->credentials->countActiveForUser($userId) > 0 || $stepUpToken !== '') {
            return ['step_up_token' => $stepUpToken];
        }

        $enrollmentToken = trim((string) ($body['enrollment_token'] ?? ''));
        if ($enrollmentToken !== '') {
            return ['step_up_token' => null, 'enrollment_token' => $enrollmentToken];
        }

        // Účet bez silného faktoru: heslo, stejně jako u vydání API tokenu
        // ({@see Tokens\CreateTokenAction}) — včetně brute-force brány, protože
        // generický rate limit na hádání hesla nestačí.
        if ($this->loginPolicy->isEnforced()) {
            return Json::error(
                $response,
                'sso_required',
                'Operaci potvrďte přihlášením přes poskytovatele identity organizace.',
                403,
            );
        }
        $email = (string) ($user['email'] ?? '');
        $bfState = $this->bruteForce->check($email, (string) $ip);
        if (in_array($bfState, [BruteForceGuard::STATE_LOCKED_15M, BruteForceGuard::STATE_LOCKED_24H], true)) {
            return Json::error($response, 'too_many_attempts', 'Příliš mnoho pokusů. Zkus to později.', 429);
        }
        $password = (string) ($body['current_password'] ?? '');
        if ($password === '' || !$this->passwords->verify($password, (string) ($user['password_hash'] ?? ''))) {
            $this->passwords->dummyVerify();
            $this->bruteForce->recordFailure($email, (string) $ip);
            $this->logger->log('auth.totp_setup_reauth_failed', $userId, 'user', $userId, ['reason' => 'password'], $ip, $userAgent);
            return Json::error($response, 'current_password_invalid', 'Aktuální heslo není správné.', 403);
        }
        return ['step_up_token' => null];
    }

    public function enable(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if ($user === null) return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        if (!$this->mfaPolicy->isMethodAllowed('totp')) {
            return Json::error($response, 'mfa_method_not_allowed', 'TOTP není v této instalaci povolené.', 403);
        }

        // Opakovaná aktivace by znovu ověřila kód, znovu vydala záložní kódy
        // a znovu povýšila session — aktivní faktor se neaktivuje podruhé.
        if ((int) $user['totp_enabled'] === 1) {
            return Json::error($response, 'already_enabled', 'TOTP už je aktivní.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '') {
            return Json::error($response, 'validation_failed', 'Chybí kód.', 400);
        }
        if (empty($user['totp_secret'])) {
            return Json::error($response, 'no_secret', 'Nejdřív zavolej /setup pro vygenerování secretu.', 400);
        }
        $storedSecret = (string) $user['totp_secret'];
        try {
            $secret = $this->crypto->decrypt($storedSecret);
        } catch (\RuntimeException) {
            return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
        }
        if (!$this->totp->verifyAndConsume($this->db, $secret, $code)) {
            return Json::error($response, 'invalid_code', 'Neplatný TOTP kód.', 400);
        }

        // Atomicky: aktivuje se jen ten secret, proti kterému kód prošel. Když ho
        // mezitím vyměnil souběžný setup() (nebo už proběhla aktivace), zápis
        // nezasáhne žádný řádek a neověřený secret zůstane vypnutý.
        $stmt = $this->db->pdo()->prepare('UPDATE users SET totp_enabled = 1 WHERE id = ? AND totp_enabled = 0 AND totp_secret = ?');
        $stmt->execute([(int) $user['id'], $storedSecret]);
        if ($stmt->rowCount() !== 1) {
            return Json::error($response, 'enrollment_stale', 'Zřízení TOTP mezitím změnil jiný požadavek. Začni znovu od /setup.', 409);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('auth.totp_enabled', (int) $user['id'], 'user', (int) $user['id'], null, $ip, $request->getHeaderLine('User-Agent'));

        $recovery = $this->issueFirstRecoveryCodes((int) $user['id'], $ip, $request->getHeaderLine('User-Agent'));

        $session = $request->getAttribute(AuthMiddleware::ATTR_SESSION);
        if (!is_array($session) || ($session['assurance_level'] ?? 'legacy') !== 'setup') {
            return Json::ok($response, ['enabled' => true] + $recovery);
        }
        $token = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        try {
            $completed = $this->sessions->completeSetup(
                (int) $user['id'],
                $token,
                $ip,
                $request->getHeaderLine('User-Agent'),
                SessionAuthContext::strong('totp', $this->clock->now()),
            );
        } catch (\DomainException) {
            return Json::error($response, 'session_expired', 'Setup session vypršela.', 401);
        }

        return Json::ok($response, [
            'enabled' => true,
            'csrf_token' => $completed['csrf_token'],
            'session_state' => 'active',
            'must_setup_mfa' => false,
        ] + $recovery)->withHeader('Set-Cookie', $this->sessionCookies->create(
            $completed['token'],
            $completed['expires_at'],
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function user(Request $request): ?array
    {
        $u = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (empty($u)) return null;
        // Načti čerstvý záznam (auth middleware nedává totp_*)
        $stmt = $this->db->pdo()->prepare('SELECT id, email, password_hash, totp_secret, totp_enabled FROM users WHERE id = ?');
        $stmt->execute([(int) $u['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    /**
     * První sada záložních kódů se vydá HNED se zapnutím TOTP.
     *
     * ⚠️ Dokud se nevydávala, končilo zapnutí druhého faktoru bez jakéhokoliv
     * break-glass: uživatel, který přišel o autentikátor, se do instalace
     * nedostal vůbec a zbýval jedině zásah na serveru. Nabídnout kódy až
     * v nastavení bezpečnosti nestačí — tam je uživatel po prvním přihlášení
     * neuvidí a nedojde tam právě ten, komu by pomohly nejvíc.
     *
     * Rozhodnutí „vydat, nebo nechat být" i ošetření chyb drží
     * {@see MfaRecoveryCodeService::issueFirstBatch()} — sdílí ho i registrace
     * passkey, aby se obě cesty nemohly rozejít.
     *
     * @return array{recovery_codes?:list<string>}
     */
    private function issueFirstRecoveryCodes(int $userId, ?string $ip, string $userAgent): array
    {
        $codes = $this->recoveryCodes->issueFirstBatch($userId);
        if ($codes === []) {
            return [];
        }

        $this->logger->log('auth.recovery_codes_generated', $userId, 'user', $userId, ['reason' => 'totp_enabled'], $ip, $userAgent);

        return ['recovery_codes' => $codes];
    }

}
