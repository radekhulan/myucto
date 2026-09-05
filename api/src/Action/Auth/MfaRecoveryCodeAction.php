<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa záložních jednorázových kódů (migrace 1176).
 *
 *   GET  /api/auth/mfa/recovery-codes   stav sady (nikdy kódy samotné)
 *   POST /api/auth/mfa/recovery-codes   vygeneruje novou sadu, starou zahodí
 *
 * Generování vyžaduje čerstvý step-up proof s účelem `mfa.recovery_codes`: kdo se
 * zmocní odemčené session, nesmí si tiše vytisknout vlastní vstupenku zpět. Ze
 * stejného důvodu proof NEJDE získat záložním kódem — viz MfaStepUpService.
 *
 * Session-only, stejně jako zbytek self-service MFA API: API token je dlouhodobé
 * pověření a nemá co sahat na faktory, kterými se jeho vlastník přihlašuje.
 */
final class MfaRecoveryCodeAction
{
    public function __construct(
        private readonly MfaRecoveryCodeService $codes,
        private readonly MfaStepUpService $stepUp,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $userId = $this->sessionUserId($request);
        if ($userId === null) {
            return Json::sessionRequired($response, 'Tento endpoint je dostupný pouze z přihlášené webové session.');
        }

        return Json::ok($response, $this->codes->status($userId) + ['batch_size' => MfaRecoveryCodeService::BATCH_SIZE]);
    }

    public function generate(Request $request, Response $response): Response
    {
        $userId = $this->sessionUserId($request);
        if ($userId === null) {
            return Json::sessionRequired($response, 'Tento endpoint je dostupný pouze z přihlášené webové session.');
        }
        $sessionToken = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');

        $body = (array) ($request->getParsedBody() ?? []);
        $proof = trim((string) ($body['step_up_token'] ?? ''));
        if ($proof === '') {
            return Json::error($response, 'step_up_required', 'Vygenerování záložních kódů vyžaduje čerstvé ověření.', 403);
        }

        try {
            $this->stepUp->consume(
                $proof,
                $userId,
                $sessionToken,
                MfaStepUpService::OPERATION_RECOVERY_CODES,
            );
        } catch (StepUpOperationException) {
            return Json::error($response, 'step_up_invalid', 'Ověření vypršelo nebo není platné. Zkuste to znovu.', 403);
        }

        $previous = $this->codes->status($userId);
        $codes = $this->codes->generate($userId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        // Do auditu jde POČET, nikdy kódy — activity log čte kdekdo s právem na audit.
        $this->logger->log(
            'auth.recovery_codes_generated',
            $userId,
            'user',
            $userId,
            [
                'issued'            => count($codes),
                'replaced_total'    => $previous['total'],
                'replaced_unused'   => $previous['remaining'],
            ],
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        // Jediná chvíle, kdy kódy existují mimo hlavu uživatele. Server si plaintext
        // neponechává, takže druhé zobrazení neexistuje — UI to musí říct nahlas.
        return Json::ok($response, [
            'codes'      => $codes,
            'batch_size' => MfaRecoveryCodeService::BATCH_SIZE,
        ] + $this->codes->status($userId));
    }

    private function sessionUserId(Request $request): ?int
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return null;
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        return $userId > 0 ? $userId : null;
    }
}
