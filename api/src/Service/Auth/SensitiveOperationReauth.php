<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Opakované ověření přihlášeného uživatele před citlivou operací —
 * **passkey NEBO heslo (+ TOTP, má-li ho účet zapnutý)**.
 *
 * Sdílené jádro. Vzniklo vytažením z `Service\Epo\EpoStepUpService`, kde tahle
 * logika bydlela pod EPO názvem, ačkoli s daňovými podáními nemá nic společného:
 * je to obecná brána „prokaž, že u klávesnice sedí pořád týž člověk". Druhý
 * konzument (odhalení hesla k šifrovaným zálohám) by jinak znamenal druhou kopii
 * brute-force počítadla, konzumace TOTP a auditní stopy — přesně ten druh
 * duplikace, po které se pravidlo opraví jen na jedné větvi.
 *
 * Passkey je zkratka, ne náhrada: jednorázový proof vázaný na konkrétní operaci
 * nahradí celé heslo + TOTP. Kdo klíč nemá (nebo ho zrovna nemá po ruce),
 * pokračuje cestou heslo + případný TOTP.
 *
 * Volající drží tři věci: `$stepUpOperation` (účel proofu, musí projít
 * {@see MfaStepUpService::assertAllowed()}), `$purpose` (co se chrání — jde do
 * auditu) a `$auditEvent` (pod jakým jménem se neúspěch zapíše).
 */
final class SensitiveOperationReauth
{
    public function __construct(
        private readonly Connection $db,
        private readonly TotpService $totp,
        private readonly SecretEncryption $crypto,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly PasswordHasher $hasher,
        private readonly BruteForceGuard $bruteForce,
        private readonly MfaStepUpService $stepUp,
    ) {}

    /**
     * @param array<string,mixed> $body  očekává `step_up_token`, nebo `password` (+ `totp_code`)
     * @throws ReauthException
     */
    public function verify(
        Request $request,
        int $userId,
        array $body,
        string $purpose,
        string $stepUpOperation,
        string $auditEvent,
        string $sessionRequiredMessage = 'Tuhle operaci lze potvrdit jen z webového rozhraní.',
    ): void {
        if (!RequestAuthorization::isSessionAuth($request)) {
            throw new ReauthException('session_required', $sessionRequiredMessage, 403);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT email, password_hash, totp_secret, totp_enabled
               FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $email = (string) ($user['email'] ?? '');
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $userAgent = $request->getHeaderLine('User-Agent');
        $state = $this->bruteForce->check($email, $ip);
        if (in_array(
            $state,
            [BruteForceGuard::STATE_LOCKED_15M, BruteForceGuard::STATE_LOCKED_24H],
            true,
        )) {
            throw new ReauthException('too_many_attempts', 'Příliš mnoho pokusů. Zkuste to později.', 429);
        }

        $stepUpToken = trim((string) ($body['step_up_token'] ?? ''));
        if ($stepUpToken !== '') {
            try {
                $this->stepUp->consume(
                    $stepUpToken,
                    $userId,
                    (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                    $stepUpOperation,
                );
            } catch (OneTimeTokenException | StepUpOperationException) {
                $this->logFailure($auditEvent, $userId, $purpose, 'step_up', $ip, $userAgent);
                throw new ReauthException(
                    'step_up_proof_invalid',
                    'Step-up ověření je neplatné nebo již bylo použito.',
                    403,
                );
            }
            return;
        }

        $password = (string) ($body['password'] ?? '');
        if (
            $password === ''
            || !$this->hasher->verify($password, (string) ($user['password_hash'] ?? ''))
        ) {
            $this->hasher->dummyVerify();
            $this->bruteForce->recordFailure($email, $ip);
            $this->logFailure($auditEvent, $userId, $purpose, 'password', $ip, $userAgent);
            throw new ReauthException('invalid_password', 'Neplatné heslo.', 401);
        }
        $this->bruteForce->recordSuccess($email, $ip);

        if ((int) ($user['totp_enabled'] ?? 0) !== 1) {
            return;
        }
        if ($this->bruteForce->isTotpLocked($userId)) {
            throw new ReauthException('too_many_attempts', 'Příliš mnoho pokusů. Zkuste to později.', 429);
        }

        $code = trim((string) ($body['totp_code'] ?? ''));
        if ($code === '') {
            throw new ReauthException('totp_required', 'Pro tuto operaci zadejte kód z autentikátoru.', 401);
        }
        try {
            $secret = $this->crypto->decrypt((string) ($user['totp_secret'] ?? ''));
        } catch (\RuntimeException) {
            throw new ReauthException(
                'server_configuration_error',
                'Nelze ověřit dvoufaktorové přihlášení.',
                500,
            );
        }
        if (!$this->totp->verifyAndConsume($this->db, $secret, $code)) {
            $this->bruteForce->recordTotpFailure($userId);
            $this->logFailure($auditEvent, $userId, $purpose, 'totp', $ip, $userAgent);
            throw new ReauthException('invalid_code', 'Neplatný TOTP kód.', 401);
        }
        $this->bruteForce->recordTotpSuccess($userId);
    }

    private function logFailure(
        string $event,
        int $userId,
        string $purpose,
        string $reason,
        string $ip,
        string $userAgent,
    ): void {
        $this->activity->log(
            $event,
            $userId,
            'user',
            $userId,
            ['purpose' => $purpose, 'reason' => $reason],
            $ip,
            $userAgent,
        );
    }
}
