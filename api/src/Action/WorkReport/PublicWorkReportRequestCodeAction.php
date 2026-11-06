<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\AfterResponseTasks;
use MyInvoice\Http\Json;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Captcha\TurnstileVerifier;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\WorkReport\WorkReportLinkService;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/public/work-report/{token}/request-code
 *
 * Body: { email, cf_turnstile_response?, resend? }
 *
 * Pošle 6místný ověřovací kód na e-mail (jen je-li mezi povolenými adresami).
 * Odpověď je VŽDY generická ({ ok:true, cooldown_remaining:<nastavený cooldown> })
 * proti enumeraci adres: stejné tělo pro povolenou i cizí adresu a podobná doba
 * odpovědi. E-mail se posílá až po odeslání odpovědi ({@see AfterResponseTasks});
 * kde to SAPI neumí, posílá se synchronně a odpověď se srovná na společné minimum.
 */
final class PublicWorkReportRequestCodeAction
{
    /**
     * Minimální délka odpovědi (ms), když e-mail nejde odložit za odpověď.
     * Stejná hodnota jako u obnovy hesla, musí pokrýt běžnou SMTP transakci.
     */
    public const MIN_SYNC_RESPONSE_MS = 1500;

    public function __construct(
        private readonly WorkReportLinkService $service,
        private readonly TurnstileVerifier $captcha,
        private readonly IpMatcher $ipMatcher,
        private readonly ActivityLogger $logger,
        private readonly PublicTenantGuard $tenantGuard,
        private readonly AfterResponseTasks $afterResponse,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $started = hrtime(true);
        $token = (string) ($args['token'] ?? '');
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }
        $link = $this->service->findActiveLink($token);
        if ($link === null) {
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz není platný nebo byl zneplatněn.', 404);
        }
        if (!$this->tenantGuard->allows($request, (int) $link['supplier_id'])) {
            return Json::error($response, 'not_found', 'Tento odkaz není platný.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        $captchaToken = (string) ($body['cf_turnstile_response'] ?? '');
        if (!$this->captcha->verify($captchaToken, $ip, 'work_report')) {
            return Json::error($response, 'captcha_failed',
                'Ověření CAPTCHA selhalo, zkuste to prosím znovu.', 422);
        }

        $email = trim((string) ($body['email'] ?? ''));
        $force = !empty($body['resend']);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result = $this->service->issueCode($link, $email, $ip, $force, $this->afterResponse);
            if ($result['sent']) {
                $this->logger->log('work_report_link.code_sent', null, 'work_report_link', (int) $link['id'], [
                    'email_masked' => WorkReportLinkService::maskEmail(mb_strtolower($email)),
                ], $ip, $request->getHeaderLine('User-Agent'));
            }
        }

        // Generická odpověď bez ohledu na to, zda byla adresa povolená. Zbývající
        // cooldown by povolenou adresu prozradil, proto se vrací vždy nastavený.
        if (!$this->afterResponse->canDetach()) {
            // usleep může na Windows skončit o pár milisekund dřív; spí se, dokud minimum
            // skutečně neuplyne.
            while (($remainingUs = self::MIN_SYNC_RESPONSE_MS * 1000 - intdiv(hrtime(true) - $started, 1000)) > 0) {
                usleep($remainingUs);
            }
        }
        return Json::ok($response, ['ok' => true, 'cooldown_remaining' => $this->service->resendCooldownSeconds()]);
    }
}
