<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth\Tokens;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/auth/tokens — vytvoří nový API token.
 *
 * Body: { name, password, supplier_id?, scope: 'read'|'read_write',
 *         expires_at?, never_expires?, totp_code? }
 *
 * Vyžaduje:
 *   - aktivní session (NE bearer — token by si nemohl vytvořit další token)
 *   - re-auth SOUČASNÝM HESLEM — samotná session nestačí. Bez toho šla ukradená
 *     session (XSS, nezamčený počítač) vyměnit za dlouhodobý bearer token, který
 *     přežije i odhlášení a změnu hesla.
 *   - navíc `totp_code`, pokud má user `totp_enabled=1` (step-up auth)
 *
 * Chybné heslo i chybný TOTP se počítají do BruteForceGuard (sdílený lockout
 * s /login), takže se tenhle endpoint nedá použít jako orákulum na hádání hesla.
 *
 * Response: plaintext token JEN v této response, nikdy už znovu.
 */
final class CreateTokenAction
{
    /** Výchozí životnost tokenu, když volající expiraci neuvede. */
    private const DEFAULT_EXPIRY_DAYS = 90;

    /** Horní strop explicitní expirace — delší = fakticky věčný token. */
    private const MAX_EXPIRY_DAYS = 730;

    public function __construct(
        private readonly Connection $db,
        private readonly ApiTokenService $tokens,
        private readonly TotpService $totp,
        private readonly SecretEncryption $crypto,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly UserSupplierRepository $memberships,
        private readonly PasswordHasher $hasher,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly BruteForceGuard $bruteForce,
        private readonly MfaProtectedOperationService $protectedOperations,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Bearer auth nesmí vytvářet další tokeny (escalation guard)
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'API tokeny lze spravovat jen z webového rozhraní.');
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        // Epic F6: klient nesmí razit API tokeny (PermissionMiddleware to už blokuje —
        // defense-in-depth pro případ změny pravidel; fail-open BC větev membershipu
        // níže by jinak pro klienta bez řádků byla eskalace).
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $supplierId = isset($body['supplier_id']) && $body['supplier_id'] !== ''
            ? (int) $body['supplier_id']
            : null;
        // Default least-privilege: bez explicitního scope je token jen pro čtení.
        $scope = (string) ($body['scope'] ?? 'read');
        // ⚠️ Také least-privilege: mzdovou evidenci podání token nedostane, dokud
        // ji člověk výslovně nezaškrtne. Zbytek mzdové evidence nejde odemknout
        // vůbec — viz RequestAuthorization::tokenAllowsPayrollSubmissionDocs().
        $allowPayrollSubmissionDocs = !empty($body['allow_payroll_submission_docs']);
        $expiresRaw = trim((string) ($body['expires_at'] ?? ''));
        $neverExpires = ($body['never_expires'] ?? false) === true;
        $totpCode = trim((string) ($body['totp_code'] ?? ''));
        $stepUpToken = trim((string) ($body['step_up_token'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            return Json::error($response, 'validation_failed', 'Název tokenu musí mít 1–100 znaků.', 400);
        }
        if (!in_array($scope, ['read', 'read_write'], true)) {
            return Json::error($response, 'validation_failed', 'Neplatný scope.', 400);
        }

        $ip = $this->ipMatcher->clientIp($request->getServerParams(), [], 'X-Forwarded-For');
        $userAgent = $request->getHeaderLine('User-Agent');

        // Validace supplier_id musí předcházet spotřebě jednorázového proofu.
        if ($supplierId !== null) {
            $check = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ?');
            $check->execute([$supplierId]);
            if ($check->fetchColumn() === false) {
                return Json::error($response, 'validation_failed', 'Supplier nenalezen.', 400);
            }

            // Epic F0 bezpečnost: uživatel s membershipem nesmí vytvořit token bound
            // na firmu mimo své přiřazení — jinak by tím obešel supplier scope
            // (bound PAT jinak firmu forcuje). Bez membershipu = BC (bez omezení).
            $allowed = $this->memberships->allowedSupplierIds($userId);
            if (($user['is_superadmin'] ?? false) !== true && !in_array($supplierId, $allowed, true)) {
                return Json::error($response, 'forbidden_supplier', 'K této firmě nemáš oprávnění.', 403);
            }
        }

        // Expirace. Default je konečná — dřív token bez `expires_at` platil věčně,
        // takže typický "klikni a vytvoř" scénář razil nesmrtelné read_write klíče.
        // Věčný token je teď vědomá volba (`never_expires`) a pro read_write ho
        // smí zvolit jen superadmin. Formát: ISO-8601 nebo YYYY-MM-DD.
        $now = new \DateTimeImmutable();
        $expiresAt = $now->modify('+' . self::DEFAULT_EXPIRY_DAYS . ' days');

        if ($expiresRaw !== '') {
            try {
                $expiresAt = new \DateTimeImmutable($expiresRaw);
            } catch (\Exception) {
                return Json::error($response, 'validation_failed', 'Neplatný formát expires_at.', 400);
            }
            if ($expiresAt <= $now) {
                return Json::error($response, 'validation_failed', 'expires_at musí být v budoucnu.', 400);
            }
            if ($expiresAt > $now->modify('+' . self::MAX_EXPIRY_DAYS . ' days')) {
                return Json::error($response, 'validation_failed', sprintf(
                    'Expirace tokenu smí být nejvýš %d dní v budoucnu.', self::MAX_EXPIRY_DAYS
                ), 400);
            }
        } elseif ($neverExpires) {
            // Věčný token smí razit JEN superadmin — a to bez ohledu na scope.
            // Dřív se omezení vztahovalo jen na `read_write`, takže si běžný
            // uživatel pořád vyrobil nesmrtelný `read` token: kompletní čtecí
            // přístup k účetnictví firmy, který přežije odhlášení i změnu hesla
            // a nedá se odvolat ničím jiným než ruční revokací.
            if (($user['is_superadmin'] ?? false) !== true) {
                return Json::error(
                    $response,
                    'expiry_required',
                    'Token bez expirace smí vytvořit jen superadmin. Zadej expires_at.',
                    403,
                );
            }
            $expiresAt = null;
        }

        // Účelový step-up: jednorázový passkey/TOTP proof, nebo kompatibilní
        // přímé TOTP ověření v tomto requestu.
        $stmt = $this->db->pdo()->prepare(
            'SELECT email, password_hash, totp_secret, totp_enabled FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $u = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        // Zaregistrovaný TOTP je step-up požadavek bez ohledu na allowed_mfa_methods.
        // Jinak by zúžení seznamu na ['passkey'] u uživatele bez passkey step-up
        // úplně zrušilo a token by vznikl jen z heslové session.
        $totpAvailable = (int) ($u['totp_enabled'] ?? 0) === 1
            && !empty($u['totp_secret']);
        $passkeyAvailable = $this->mfaPolicy->isMethodAllowed('passkey')
            && $this->credentials->countActiveForUser($userId) > 0;

        $out = null;
        if ($stepUpToken !== '') {
            try {
                $out = $this->protectedOperations->createApiToken(
                    $userId,
                    (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                    $stepUpToken,
                    $supplierId,
                    $name,
                    $scope,
                    $expiresAt,
                );
            } catch (OneTimeTokenException|StepUpOperationException) {
                return Json::error(
                    $response,
                    'step_up_proof_invalid',
                    'Step-up ověření je neplatné nebo již bylo použito.',
                    403,
                );
            } catch (\DomainException) {
                return Json::error($response, 'session_expired', 'Session vypršela.', 401);
            }
        } elseif ($totpAvailable) {
            if ($totpCode === '') {
                return Json::error(
                    $response,
                    $passkeyAvailable ? 'step_up_required' : 'totp_required',
                    'Pro vytvoření tokenu je vyžadováno nové ověření.',
                    401,
                    ['methods' => $passkeyAvailable ? ['passkey', 'totp'] : ['totp']],
                );
            }
            if ($this->bruteForce->isTotpLocked($userId)) {
                return Json::error(
                    $response,
                    'too_many_attempts',
                    'Příliš mnoho TOTP pokusů. Zkus to později.',
                    429,
                );
            }
            try {
                $secret = $this->crypto->decrypt((string) $u['totp_secret']);
            } catch (\RuntimeException) {
                return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
            }
            if (!$this->totp->verify($secret, $totpCode)) {
                $this->bruteForce->recordTotpFailure($userId);
                return Json::error($response, 'invalid_code', 'Neplatný TOTP kód.', 401);
            }
            $this->bruteForce->recordTotpSuccess($userId);
        } elseif ($passkeyAvailable) {
            return Json::error(
                $response,
                'step_up_required',
                'Pro vytvoření tokenu je vyžadováno nové ověření.',
                401,
                ['methods' => ['passkey']],
            );
        } else {
            // Účet bez jakéhokoli silného faktoru: upstream by token vydal ze
            // samotné session. MyÚčto tuhle mezeru zavřelo re-authem heslem —
            // jinak stačí unesená session na vyražení trvalého read_write klíče.
            $email = (string) ($u['email'] ?? '');
            $bfState = $this->bruteForce->check($email, $ip);
            if (in_array($bfState, [BruteForceGuard::STATE_LOCKED_15M, BruteForceGuard::STATE_LOCKED_24H], true)) {
                return Json::error($response, 'too_many_attempts', 'Příliš mnoho pokusů. Zkus to později.', 429);
            }
            if ($password === '' || !$this->hasher->verify($password, (string) ($u['password_hash'] ?? ''))) {
                $this->hasher->dummyVerify();
                $this->bruteForce->recordFailure($email, $ip);
                $this->activity->log('api_token.reauth_failed', $userId, 'user', $userId, [
                    'reason' => 'password',
                ], $ip, $userAgent);
                return Json::error($response, 'invalid_password', 'Neplatné heslo.', 401);
            }
        }

        $out ??= $this->tokens->generate(
            $userId,
            $supplierId,
            $name,
            $scope,
            $expiresAt,
            $allowPayrollSubmissionDocs,
        );

        $this->activity->log(
            'api_token.created',
            $userId,
            'api_token',
            $out['id'],
            [
                'name' => $name,
                'scope' => $scope,
                'supplier_id' => $supplierId,
                'prefix' => $out['prefix'],
                'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
                'never_expires' => $expiresAt === null,
                'allow_payroll_submission_docs' => $allowPayrollSubmissionDocs,
            ],
            $ip,
            $userAgent,
        );

        return Json::ok($response, [
            'token' => $out['plaintext'],
            'id'    => $out['id'],
            'prefix' => $out['prefix'],
            'warning' => 'Plain-text token se zobrazí pouze jednou. Ulož si ho — později už ho znovu neuvidíš.',
        ], 201);
    }
}
