<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Action\OAuth\OAuthCallbackAction;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankOAuthCallbackHandler;
use MyInvoice\Service\Bank\Connector\CsasOnboardingService;
use MyInvoice\Service\OAuth\OAuthCallbackDispatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CsasOnboardingAction
{
    public function __construct(private readonly CsasOnboardingService $service,
        private readonly \Psr\Log\LoggerInterface $logger, private readonly OAuthCallbackDispatcher $callbacks) {}

    public function status(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request, AccessLevel::READ)) return Json::error($response, 'forbidden', 'Přístup zamítnut.', 403);
        try {
            return Json::ok($response, $this->service->status(SupplierGuard::currentId($request), (int) ($args['currencyId'] ?? 0)))
                ->withHeader('Cache-Control', 'no-store');
        } catch (BankConnectorOperationException $e) {
            return $this->error($response, $e);
        }
    }

    public function start(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request, AccessLevel::WRITE)) return Json::error($response, 'forbidden', 'Přístup zamítnut.', 403);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            return Json::ok($response, $this->service->start(SupplierGuard::currentId($request), (int) ($args['currencyId'] ?? 0),
                (int) ($user['id'] ?? 0), (array) ($request->getParsedBody() ?? [])), 201)->withHeader('Cache-Control', 'no-store');
        } catch (BankConnectorOperationException $e) {
            return $this->error($response, $e);
        }
    }

    /**
     * Redirect URI registrovaná u banky: alias společného callbacku /api/oauth/callback
     * pro přihlášení ČSAS. State, který společná vrstva nezná, patří přihlášení zahájenému
     * před přechodem na ni (bank_oauth_sessions) a dokončí se původní cestou.
     */
    public function callback(Request $request, Response $response): Response
    {
        if (!$this->allowed($request, AccessLevel::WRITE)) return Json::error($response, 'forbidden', 'Přístup zamítnut.', 403)
            ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        $location = $this->callbacks->dispatch($request, BankOAuthCallbackHandler::DOMAIN, CsasOnboardingService::PROVIDER);
        if ($location !== null) return OAuthCallbackAction::redirect($response, $location);
        $query = $request->getQueryParams();
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $currencyId = $this->service->complete(SupplierGuard::currentId($request), (int) ($user['id'] ?? 0),
                is_string($query['state'] ?? null) ? $query['state'] : '', is_string($query['code'] ?? null) ? $query['code'] : '');
            $location = BankOAuthCallbackHandler::csasLocation('connected', $currencyId);
        } catch (BankConnectorOperationException $e) {
            $remoteError = $query['error'] ?? null;
            $this->logger->warning('csas_oauth_callback_failed', [
                'reason' => $e->errorCode,
                'authorization_error' => in_array($remoteError, ['access_denied', 'invalid_request', 'unauthorized_client',
                    'unsupported_response_type', 'invalid_scope', 'server_error', 'temporarily_unavailable'], true) ? $remoteError : 'unspecified',
                'has_code' => is_string($query['code'] ?? null) && $query['code'] !== '',
                'has_state' => is_string($query['state'] ?? null) && $query['state'] !== '',
            ]);
            $location = BankOAuthCallbackHandler::csasLocation('error', null, $e->errorCode);
        }
        return OAuthCallbackAction::redirect($response, $location);
    }

    private function allowed(Request $request, AccessLevel $level): bool
    {
        return RequestAuthorization::isSessionAuth($request) && RequestAuthorization::allows($request, 'settings.bank_accounts', $level);
    }

    private function error(Response $response, BankConnectorOperationException $e): Response
    {
        return Json::error($response, $e->errorCode, 'Připojení České spořitelny se nepodařilo dokončit.', 422)
            ->withHeader('Cache-Control', 'no-store');
    }
}
