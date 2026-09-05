<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\DomainLoginException;
use MyInvoice\Service\Auth\DomainLoginService;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DomainLoginAction
{
    public function __construct(
        private readonly DomainLoginService $login,
        private readonly SessionCookieFactory $cookies,
        private readonly IpMatcher $ipMatcher,
        private readonly ActivityLogger $activity,
    ) {}

    public function start(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            return Json::ok($response, $this->login->start(
                $request,
                trim((string) ($body['code_challenge'] ?? '')),
                (string) ($body['return_path'] ?? '/portal'),
                $this->ip($request),
                (string) ($body['handoff_path'] ?? ''),
            ), 201);
        } catch (DomainLoginException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function authorize(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Je nutné přihlášení browserovou session.');
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->login->authorize(
                $request,
                (string) ($body['request_token'] ?? ''),
                (string) ($body['state'] ?? ''),
                (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []),
                (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []),
            );
            return Json::ok($response, $result);
        } catch (DomainLoginException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function exchange(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->login->exchange(
                $request,
                (string) ($body['request_token'] ?? ''),
                (string) ($body['code'] ?? ''),
                (string) ($body['state'] ?? ''),
                (string) ($body['code_verifier'] ?? ''),
                $this->ip($request),
            );
            $session = $result['session'];
            $this->activity->log(
                'auth.domain_login',
                $result['user_id'],
                'supplier_domain',
                $result['domain_id'],
                ['supplier_id' => $result['supplier_id']],
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
                $result['supplier_id'],
            );
            return Json::ok($response, [
                'csrf_token' => $session['csrf_token'],
                'return_path' => $result['return_path'],
                'supplier_id' => $result['supplier_id'],
            ])->withHeader('Set-Cookie', $this->cookies->create(
                (string) $session['token'],
                (int) $session['expires_at'],
            ));
        } catch (DomainLoginException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private function ip(Request $request): string
    {
        return $this->ipMatcher->clientIpFromRequest($request->getServerParams());
    }
}
