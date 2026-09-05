<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\DefectNoticeService;
use MyInvoice\Service\Submission\SubmissionInboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výzvy k odstranění vad podání (§ 74 daňového řádu) a přepočet doručení.
 *
 * Odpověď vždy nese `notice` — větu, která říká, co prázdný seznam znamená.
 * Bez ní by uživatel četl „žádné výzvy" jako „nic nepřišlo", zatímco ve
 * skutečnosti to znamená „žádná zaevidovaná": aplikace výzvy z datové schránky
 * sama nerozpoznává.
 */
final class SubmissionDefectNoticeAction
{
    public function __construct(
        private readonly DefectNoticeService $notices,
        private readonly SubmissionInboxService $inbox,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $params = $request->getQueryParams();

        try {
            $result = $this->notices->list(
                SupplierGuard::currentId($request),
                (string) ($params['environment'] ?? 'production'),
                ($params['open'] ?? '') === '1',
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $result);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            /** @var array<string,mixed> $body */
            $result = $this->notices->record(
                SupplierGuard::currentId($request),
                (string) ($body['environment'] ?? 'production'),
                $body,
                $this->userId($request),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $result);
    }

    /** @param array<string,string> $args */
    public function amend(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            /** @var array<string,mixed> $body */
            $result = $this->notices->amend(
                SupplierGuard::currentId($request),
                (int) ($args['id'] ?? 0),
                (int) ($body['row_version'] ?? 0),
                $body,
                $this->userId($request),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $result);
    }

    /** @param array<string,string> $args */
    public function respond(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->notices->recordResponse(
                SupplierGuard::currentId($request),
                (int) ($args['id'] ?? 0),
                (int) ($body['row_version'] ?? 0),
                (string) ($body['responded_on'] ?? ''),
                isset($body['response_outbox_id']) ? (int) $body['response_outbox_id'] : null,
                $this->userId($request),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $result);
    }

    /**
     * Přepočet rozhodného dne doručení.
     *
     * Nesahá na síť — jen znovu posoudí už stažené zprávy. Běžící lhůta fikce
     * se totiž mění pouhým během času a bez přepočtu by zpráva zůstala navěky
     * v „lhůta běží", i když už dávno uplynula.
     */
    public function refreshDelivery(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);

        return Json::ok($response, $this->inbox->refreshDelivery(
            SupplierGuard::currentId($request),
            (string) ($body['environment'] ?? 'production'),
            $this->userId($request),
        ));
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'settings.signing', $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Datová schránka se obsluhuje jen z webového rozhraní.');
        }

        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
