<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Submission\IsdsGatewaySessionRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsAgendaCatalog;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayDispatchService;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Odesílací brána datové schránky — zahájení, návrat z ISDS a registrace
 * provozovatele.
 *
 * ── Bezpečnostní režim ──────────────────────────────────────────────────────
 * Všechno jen z přihlášené webové relace, nikdy přes bearer token. Zahájení
 * odeslání vede k právnímu úkonu (schválení odeslání datové zprávy), takže se
 * na něj hledí stejně jako na trezor certifikátů.
 *
 * Návratový endpoint {@see complete()} je pro ISDS „návratové URL" registrace,
 * ale **není veřejný**: `appToken` z přesměrování slouží jen k dohledání relace
 * a o oprávnění rozhoduje přihlášená relace uživatele. Kdo přijde s cizím
 * tokenem, dostane 403 a žádné volání do ISDS se neuskuteční.
 *
 * ── Registrace brány je provozovatelská, ne zákaznická ──────────────────────
 * Certifikát platí provozovatel a je jeden pro celou službu, takže endpointy
 * `/api/settings/isds-gateway` jsou dostupné pouze provoznímu superadminovi.
 * Zákazník k odesílání přes bránu nepotřebuje nastavovat ani vidět její ATS ID,
 * hostitele nebo certifikát.
 */
final class IsdsGatewayAction
{
    public function __construct(
        private readonly IsdsGatewayDispatchService $dispatch,
        private readonly IsdsGatewayRegistrationService $registrations,
        private readonly IsdsGatewaySessionRepository $sessions,
        private readonly SubmissionOutboxRepository $outbox,
        private readonly ActivityLogger $logger,
        private readonly PayrollProductionGate $productionGate,
        private readonly PayrollIsdsAgendaCatalog $payrollIsdsAgendas
            = new PayrollIsdsAgendaCatalog(),
    ) {}

    /**
     * Zahájí odeslání přes bránu a vrátí adresu, kam přesměrovat prohlížeč.
     *
     * Server sám přesměrování nedělá: klient dostane URL a otevře ji, aby se
     * dalo uživateli nejdřív ukázat, co ho v datové schránce čeká.
     *
     * @param array<string,string> $args
     */
    public function start(Request $request, Response $response, array $args): Response
    {
        return $this->startForPermission(
            $request,
            $response,
            $args,
            'settings.signing',
        );
    }

    /** @param array<string,string> $args */
    public function payrollStart(Request $request, Response $response, array $args): Response
    {
        return $this->startForPermission(
            $request,
            $response,
            $args,
            'payroll.submissions',
        );
    }

    /** @param array<string,string> $args */
    private function startForPermission(
        Request $request,
        Response $response,
        array $args,
        string $permission,
    ): Response {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE, $permission)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $id = (int) ($args['id'] ?? 0);

        try {
            if ($permission === 'payroll.submissions') {
                $environment = $this->assertPayrollOutbox($supplierId, $id);
                $this->productionGate->assertEnvironmentActive(
                    $supplierId,
                    $environment,
                );
            }
            $result = $this->dispatch->start($supplierId, $id, $userId);
        } catch (PayrollProductionGateException $e) {
            return Json::error(
                $response,
                PayrollProductionGateException::ERROR_CODE,
                $e->getMessage(),
                409,
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        // `app_token` je v REDACT_KEYS — do payloadu se nedostane.
        $this->logger->log(
            'isds_gateway_start',
            $userId,
            'submission_outbox',
            $id,
            ['session_id' => $result['session_id']],
            null,
            null,
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    /** Návrat z ISDS. Fáze se pozná ze stavu relace, ne z parametru. */
    public function complete(Request $request, Response $response): Response
    {
        return $this->completeForPermission(
            $request,
            $response,
            'settings.signing',
        );
    }

    public function payrollComplete(Request $request, Response $response): Response
    {
        return $this->completeForPermission(
            $request,
            $response,
            'payroll.submissions',
        );
    }

    private function completeForPermission(
        Request $request,
        Response $response,
        string $permission,
    ): Response {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE, $permission)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $query = $request->getQueryParams();

        $appToken = trim((string) ($body['app_token'] ?? $query['appToken'] ?? ''));
        $sessionId = trim((string) ($body['session_id'] ?? $query['sessionId'] ?? ''));
        if ($appToken === '' || $sessionId === '') {
            return Json::error(
                $response,
                'isds_gateway_callback_incomplete',
                'Návrat z datové schránky nepřinesl potřebné údaje. Spusťte odeslání znovu.',
                400,
            );
        }

        try {
            if ($permission === 'payroll.submissions') {
                $session = $this->sessions->findByAppToken($appToken);
                if ($session !== null && (int) $session['supplier_id'] === $supplierId) {
                    $this->assertPayrollOutbox($supplierId, (int) $session['outbox_id']);
                }
            }
            $result = $this->dispatch->complete($supplierId, $userId, $appToken, $sessionId);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        if ($result['state'] === 'approved' && $result['external_message_id'] !== null) {
            $this->logger->log(
                'isds_gateway_dispatched',
                $userId,
                'submission_outbox',
                $result['outbox_id'],
                ['external_message_id' => $result['external_message_id']],
                null,
                null,
                $supplierId,
            );
        }

        return Json::ok($response, $result);
    }

    // ───────────────────────── registrace provozovatele ─────────────────────────

    /** Bezpečná capability projekce bez ATS ID, URL, certifikátu a hostitelů. */
    public function capability(Request $request, Response $response): Response
    {
        if (($denied = $this->guard(
            $request,
            $response,
            AccessLevel::READ,
            'settings.signing',
        )) !== null) {
            return $denied;
        }

        return Json::ok($response, [
            'items' => array_map(
                fn (string $environment): array => [
                    'environment' => $environment,
                    'available' => $this->registrations->isDispatchReady($environment),
                ],
                ['production', 'test'],
            ),
        ]);
    }

    public function settings(Request $request, Response $response): Response
    {
        if (($denied = $this->operatorGuard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        return Json::ok($response, [
            'items' => $this->registrations->listPublic(),
            'default_hosts' => IsdsGatewayRegistrationService::DEFAULT_HOSTS,
        ]);
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        if (($denied = $this->operatorGuard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $userId = $this->userId($request);

        $certificate = '';
        $file = $request->getUploadedFiles()['certificate'] ?? null;
        if ($file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK) {
            $certificate = (string) $file->getStream()->getContents();
        }

        try {
            $saved = $this->registrations->save(
                (string) ($body['environment'] ?? 'test'),
                (string) ($body['ats_id'] ?? ''),
                (string) ($body['label'] ?? ''),
                (string) ($body['return_url'] ?? ''),
                isset($body['error_url']) ? (string) $body['error_url'] : null,
                (int) ($body['concept_ttl_seconds'] ?? 900),
                isset($body['portal_host']) ? (string) $body['portal_host'] : null,
                isset($body['service_host']) ? (string) $body['service_host'] : null,
                (string) ($body['user_login_policy'] ?? 'unknown'),
                $certificate,
                isset($body['certificate_password']) ? (string) $body['certificate_password'] : null,
                $userId,
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $this->logger->log('isds_gateway_registration_save', $userId, 'isds_gateway', $saved['id']);

        return Json::ok($response, $saved);
    }

    public function setActive(Request $request, Response $response): Response
    {
        if (($denied = $this->operatorGuard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'test');
        $active = (bool) ($body['active'] ?? false);

        try {
            $changed = $this->registrations->setActive($environment, $active);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if (!$changed) {
            return Json::error($response, 'not_found', 'Taková registrace uložená není.', 404);
        }

        $this->logger->log(
            $active ? 'isds_gateway_registration_enable' : 'isds_gateway_registration_disable',
            $this->userId($request),
            'isds_gateway',
            0,
            ['environment' => $environment],
        );

        return Json::ok($response, ['environment' => $environment, 'active' => $active]);
    }

    /** @param array<string,string> $args */
    public function deleteSettings(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->operatorGuard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $environment = (string) ($args['environment'] ?? 'test');

        try {
            $deleted = $this->registrations->delete($environment);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if (!$deleted) {
            return Json::error($response, 'not_found', 'Taková registrace uložená není.', 404);
        }

        $this->logger->log('isds_gateway_registration_delete', $this->userId($request), 'isds_gateway', 0, [
            'environment' => $environment,
        ]);

        return Json::ok($response, ['deleted' => true]);
    }

    // ───────────────────────── interní ─────────────────────────

    private function assertPayrollOutbox(int $supplierId, int $outboxId): string
    {
        $row = $this->outbox->find($supplierId, $outboxId);
        if ($row === null) {
            throw new SubmissionChannelException(
                'submission_outbox_not_found',
                'Připravené podání nebylo nalezeno.',
                404,
            );
        }
        $agendaCode = strtoupper(trim((string) $row['agenda_code']));
        $artifactKind = trim((string) $row['artifact_kind']);
        // Rozsah mzdového oprávnění u brány. Agendy s doloženou datovou
        // schránkou drží {@see PayrollIsdsAgendaCatalog} — kdyby se tenhle
        // seznam psal znovu, dopadlo by to jako u NEMPRI a HZUPN: katalog
        // schopností jim kanál sliboval, zařazení do fronty fungovalo a brána
        // je pak odmítla s 403, tedy až v posledním kroku.
        $allowedAgenda = $this->payrollIsdsAgendas->has($agendaCode)
            || $agendaCode === 'PPZ'
            || str_starts_with($agendaCode, 'PPZ_');
        if (!$allowedAgenda || $artifactKind !== 'payroll_submission') {
            throw new SubmissionChannelException(
                'payroll_gateway_outbox_forbidden',
                'Mzdové oprávnění smí přes ISDS odeslat pouze připravené mzdové'
                    . ' podání s doloženou datovou schránkou (JMHZ, NEMPRI,'
                    . ' HZUPN nebo přehled zdravotní pojišťovně).',
                403,
            );
        }

        return (string) $row['environment'];
    }

    private function operatorGuard(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        if (($denied = $this->guard($request, $response, $level, 'settings.signing')) !== null) {
            return $denied;
        }
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error(
                $response,
                'forbidden',
                'Registraci odesílací brány smí spravovat pouze provozní superadmin.',
                403,
            );
        }

        return null;
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $level,
        string $permission,
    ): ?Response
    {
        if (!RequestAuthorization::allows($request, $permission, $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'forbidden_via_token',
                'Odesílání datovou schránkou lze ovládat jen z webového rozhraní.',
                403,
            );
        }

        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
