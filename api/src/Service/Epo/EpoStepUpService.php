<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\ReauthException;
use MyInvoice\Service\Auth\SensitiveOperationReauth;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Opakované ověření před prací s podpisovým certifikátem a přímým EPO podáním.
 *
 * Vlastní mechanika (passkey proof, heslo, TOTP, brute-force počítadlo, auditní
 * stopa) žije v {@see SensitiveOperationReauth} — sdíleně s ostatními citlivými
 * operacemi. Tady zůstává jen to, co je opravdu EPO: účel proofu, název auditní
 * události a hláška pro volání mimo webové rozhraní.
 */
final class EpoStepUpService
{
    public function __construct(private readonly SensitiveOperationReauth $reauth) {}

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

        try {
            $this->reauth->verify(
                $request,
                $userId,
                $body,
                $purpose,
                MfaStepUpService::OPERATION_EPO_CERTIFICATE,
                'report.epo_reauth_failed',
            );
        } catch (ReauthException $e) {
            throw new EpoSubmissionException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }
}
