<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Submission\Channel\Isds\MobileKeyIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\IsdsMobileCredentialService;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Fronta odchozích podání.
 *
 * Odesílat smí jen přihlášený člověk z webového rozhraní — proto stejný
 * `forbidden_via_token` guard jako u trezoru. Automat (cron, import) do fronty
 * zařazuje přes službu, ne přes tuhle akci, a odeslat nemůže vůbec.
 */
final class SubmissionOutboxAction
{
    public function __construct(
        private readonly SubmissionOutboxService $outbox,
        private readonly SubmissionCredentialService $credentials,
        private readonly ActivityLogger $logger,
        private readonly MobileKeyIsdsAuthenticator $mobileKey,
        private readonly IsdsMobileCredentialService $mobileCredentials,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $params = $request->getQueryParams();
        $environment = (string) ($params['environment'] ?? 'production');

        try {
            $items = $this->outbox->listForSupplier(SupplierGuard::currentId($request), $environment);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, ['items' => $items]);
    }

    /** @param array<string,string> $args */
    public function attempts(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        return Json::ok($response, [
            'items' => $this->outbox->attemptsFor(SupplierGuard::currentId($request), (int) ($args['id'] ?? 0)),
        ]);
    }

    public function enqueue(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $supplierId = SupplierGuard::currentId($request);

        try {
            $result = $this->outbox->enqueue(
                $supplierId,
                (string) ($body['environment'] ?? 'production'),
                (string) ($body['channel'] ?? 'isds'),
                (string) ($body['agenda_code'] ?? ''),
                (string) ($body['artifact_kind'] ?? ''),
                (int) ($body['artifact_id'] ?? 0),
                isset($body['recipient_id']) ? (int) $body['recipient_id'] : null,
                isset($body['subject']) ? (string) $body['subject'] : null,
                $this->userId($request),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'enqueue_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    /**
     * Potvrzení a odeslání člověkem.
     *
     * Opakované volání nevytvoří druhé podání — vrátí `dispatched: false`
     * a aktuální stav. Idempotenci drží podmínka `dispatch_state = 'ready'`
     * v UPDATE, ne kontrola v aplikaci.
     *
     * @param array<string,string> $args
     */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');

        try {
            $context = $this->credentials->unlock($supplierId, $environment);
            $result = $this->outbox->confirmAndSend($supplierId, $id, $userId, $context);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        if ($result['dispatched']) {
            $this->logger->log('submission_outbox_sent', $userId, 'submission_outbox', $id, null, null, null, $supplierId);
        }

        return Json::ok($response, $result);
    }

    /**
     * Zahájí přihlášení Mobilním klíčem pro ODESLÁNÍ konkrétního podání.
     *
     * Proč vlastní cesta, a ne `confirm()` s uloženým certifikátem: přímý
     * transport odesílá výhradně v relaci, kterou člověk právě potvrdil
     * ({@see \MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport::hasConfirmedSession()}).
     * Uložený certifikát ani heslo takovou relaci nepředstavují — u nich by
     * u odeslání nestál nikdo — takže `SessionAwareIsdsTransport` sáhne po
     * náhradní cestě a podání skončí větou „odešlete si to sami".
     *
     * @param array<string,string> $args
     */
    public function mobileKeyStart(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        if ((int) ($args['id'] ?? 0) <= 0) {
            return Json::error($response, 'submission_not_found', 'Podání neexistuje.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $supplierId = SupplierGuard::currentId($request);
            $userId = $this->userId($request);
            $environment = (string) ($body['environment'] ?? 'production');
            $result = ($body['use_saved_credentials'] ?? false) === true
                ? $this->mobileKey->startWithCredentials(
                    $supplierId,
                    $userId,
                    $environment,
                    $this->mobileCredentials->unlock($supplierId, $userId, $environment),
                )
                : $this->mobileKey->start(
                    $supplierId,
                    $userId,
                    $environment,
                    (string) ($body['username'] ?? ''),
                    (string) ($body['communication_code'] ?? ''),
                );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $result);
    }

    /**
     * Ověří potvrzení relace a hned v ní podání odešle.
     *
     * Odeslání musí proběhnout TÍMTO voláním, ne dalším: `continue()` průchod
     * při potvrzení spotřebuje, takže relaci už podruhé vyzvednout nejde.
     * Rozdělit to na „zjisti stav" a „teď odešli" by znamenalo držet session
     * cookie někde mezi požadavky — a to je přesně to, co tenhle model
     * (krátká relace potvrzená člověkem) nechce.
     *
     * @param array<string,string> $args
     */
    public function mobileKeyConfirm(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'submission_not_found', 'Podání neexistuje.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $flowToken = (string) ($body['flow_token'] ?? '');
        if ($flowToken === '' || strlen($flowToken) > 8192) {
            return Json::error($response, 'isds_mobile_flow_invalid', 'Přihlášení Mobilním klíčem není platné. Spusťte ho znovu.', 400);
        }

        try {
            $result = $this->mobileKey->continue(
                $flowToken,
                $supplierId,
                $userId,
                (string) ($body['environment'] ?? 'production'),
            );
            $context = $result['context'];
            if ($context === null) {
                // Čeká se na potvrzení v mobilu — podání se nedotýkáme.
                return Json::ok($response, [
                    'state' => $result['state'],
                    'description' => $result['description'],
                    'result' => null,
                ]);
            }
            $sendResult = $this->outbox->confirmAndSend($supplierId, $id, $userId, $context);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        if ($sendResult['dispatched']) {
            $this->logger->log('submission_outbox_sent', $userId, 'submission_outbox', $id, null, null, null, $supplierId);
        }

        return Json::ok($response, [
            'state' => $result['state'],
            'description' => $result['description'],
            'result' => $sendResult,
        ]);
    }

    /**
     * Dořešení podání, u kterého se odeslání přerušilo.
     *
     * @param array<string,string> $args
     */
    public function resolve(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $context = $this->credentials->unlock($supplierId, (string) ($body['environment'] ?? 'production'));
            $row = $this->outbox->resolveUncertain($supplierId, (int) ($args['id'] ?? 0), $context);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $row);
    }

    /** @param array<string,string> $args */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        try {
            $row = $this->outbox->cancel(SupplierGuard::currentId($request), (int) ($args['id'] ?? 0));
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $row);
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'settings.signing', $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'forbidden_via_token',
                'Podání se odesílá jen z webového rozhraní.',
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
