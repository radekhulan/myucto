<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EpoStepUpService
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

    /** @param array<string,mixed> $body */
    public function verify(Request $request, int $userId, array $body, string $purpose): void
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            // Stejný kód jako Json::sessionRequired() — tahle cesta jen letí
            // ven výjimkou místo odpovědi, takže se sjednocuje ručně.
            throw new EpoSubmissionException(
                'session_required',
                'Certifikát a přímé EPO podání lze spravovat jen z webového rozhraní.',
                403,
            );
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
            throw new EpoSubmissionException(
                'too_many_attempts',
                'Příliš mnoho pokusů. Zkuste to později.',
                429,
            );
        }

        // Passkey je zkratka, ne náhrada: jednorázový proof vázaný na tuhle operaci
        // nahradí celé heslo + TOTP. Kdo klíč nemá (nebo ho zrovna nemá po ruce),
        // pokračuje původní cestou heslo + případný TOTP — ta zůstává beze změny.
        $stepUpToken = trim((string) ($body['step_up_token'] ?? ''));
        if ($stepUpToken !== '') {
            try {
                $this->stepUp->consume(
                    $stepUpToken,
                    $userId,
                    (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                    MfaStepUpService::OPERATION_EPO_CERTIFICATE,
                );
            } catch (OneTimeTokenException | StepUpOperationException) {
                $this->logFailure($userId, $purpose, 'step_up', $ip, $userAgent);
                throw new EpoSubmissionException(
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
            $this->logFailure($userId, $purpose, 'password', $ip, $userAgent);
            throw new EpoSubmissionException('invalid_password', 'Neplatné heslo.', 401);
        }
        $this->bruteForce->recordSuccess($email, $ip);

        if ((int) ($user['totp_enabled'] ?? 0) !== 1) {
            return;
        }
        if ($this->bruteForce->isTotpLocked($userId)) {
            throw new EpoSubmissionException(
                'too_many_attempts',
                'Příliš mnoho pokusů. Zkuste to později.',
                429,
            );
        }

        $code = trim((string) ($body['totp_code'] ?? ''));
        if ($code === '') {
            throw new EpoSubmissionException(
                'totp_required',
                'Pro tuto operaci zadejte kód z autentikátoru.',
                401,
            );
        }
        try {
            $secret = $this->crypto->decrypt((string) ($user['totp_secret'] ?? ''));
        } catch (\RuntimeException) {
            throw new EpoSubmissionException(
                'server_configuration_error',
                'Nelze ověřit dvoufaktorové přihlášení.',
                500,
            );
        }
        if (!$this->totp->verify($secret, $code)) {
            $this->bruteForce->recordTotpFailure($userId);
            $this->logFailure($userId, $purpose, 'totp', $ip, $userAgent);
            throw new EpoSubmissionException('invalid_code', 'Neplatný TOTP kód.', 401);
        }
        $this->bruteForce->recordTotpSuccess($userId);
    }

    private function logFailure(
        int $userId,
        string $purpose,
        string $reason,
        string $ip,
        string $userAgent,
    ): void {
        $this->activity->log(
            'report.epo_reauth_failed',
            $userId,
            'user',
            $userId,
            ['purpose' => $purpose, 'reason' => $reason],
            $ip,
            $userAgent,
        );
    }
}
