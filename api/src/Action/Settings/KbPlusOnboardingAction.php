<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankOAuthCallbackHandler;
use MyInvoice\Service\Bank\Connector\KbPlusOnboardingService;
use MyInvoice\Service\OAuth\OAuthCallbackDispatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class KbPlusOnboardingAction
{
    private const PERMISSION = 'settings.bank_accounts';

    public function __construct(
        private readonly KbPlusOnboardingService $service,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly OAuthCallbackDispatcher $callbacks,
    ) {}

    public function status(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::READ)) {
            return $denied;
        }
        try {
            return Json::ok($response, $this->service->status(
                SupplierGuard::currentId($request),
                (int) ($args['currencyId'] ?? 0),
            ));
        } catch (BankConnectorOperationException $e) {
            return $this->operationError($response, $e);
        }
    }

    public function start(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::WRITE)) {
            return $denied;
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $result = $this->service->start(
                SupplierGuard::currentId($request),
                (int) ($args['currencyId'] ?? 0),
                (int) ($user['id'] ?? 0),
                (array) ($request->getParsedBody() ?? []),
            );
            $this->assertBankRedirect((string) $result['redirect_url']);
            return Json::ok($response, $result, 201);
        } catch (BankConnectorOperationException $e) {
            return $this->operationError($response, $e);
        }
    }

    public function plan(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::WRITE)) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            return Json::ok($response, $this->service->changePlan(
                SupplierGuard::currentId($request),
                (int) ($args['currencyId'] ?? 0),
                $body['api_plan'] ?? null,
            ));
        } catch (BankConnectorOperationException $e) {
            return $this->operationError($response, $e);
        }
    }

    public function registrationCallback(Request $request, Response $response): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::WRITE)) {
            return $denied;
        }
        $query = $request->getQueryParams();
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $supplierId = SupplierGuard::currentId($request);
        $currencyId = $this->service->currencyForState($supplierId, (int) ($user['id'] ?? 0), $state);
        $this->logCallback('registration', $request, $supplierId, (int) ($user['id'] ?? 0), $currencyId);
        try {
            $result = $this->service->completeRegistration(
                $supplierId,
                (int) ($user['id'] ?? 0),
                $state,
                [
                    'salt' => is_string($query['salt'] ?? null) ? $query['salt'] : '',
                    'encryptedData' => is_string($query['encryptedData'] ?? null) ? $query['encryptedData'] : '',
                ] + (array_key_exists('state', $query) ? ['state' => $state] : []),
            );
            $this->assertBankRedirect((string) $result['redirect_url']);
            return $this->redirect($response, (string) $result['redirect_url']);
        } catch (BankConnectorOperationException $e) {
            return $this->redirect($response, $this->cleanReturn('error', $currencyId, $e->errorCode));
        }
    }

    /**
     * Redirect URI registrovaná u KB při registraci klienta: alias společného callbacku
     * /api/oauth/callback pro přihlášení KB+. State, který společná vrstva nezná, patří
     * přihlášení zahájenému před přechodem na ni (bank_oauth_sessions).
     */
    public function oauthCallback(Request $request, Response $response): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::WRITE)) {
            return $denied;
        }
        $location = $this->callbacks->dispatch($request, BankOAuthCallbackHandler::DOMAIN, KbPlusOnboardingService::PROVIDER);
        if ($location !== null) {
            return $this->redirect($response, $location);
        }
        $query = $request->getQueryParams();
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $supplierId = SupplierGuard::currentId($request);
        $userId = (int) ($user['id'] ?? 0);
        $currencyId = $this->service->currencyForState($supplierId, $userId, $state);
        $this->logCallback('oauth', $request, $supplierId, $userId, $currencyId);
        try {
            $currencyId = $this->service->completeOAuth($supplierId, $userId, $state, $code);
            return $this->redirect($response, $this->cleanReturn('connected', $currencyId));
        } catch (BankConnectorOperationException $e) {
            return $this->redirect($response, $this->cleanReturn('error', $currencyId, $e->errorCode));
        }
    }

    private function logCallback(string $stage, Request $request, int $supplierId, int $userId, ?int $currencyId): void
    {
        if ($this->config->get('app.env') !== 'development') {
            return;
        }
        $query = $request->getQueryParams();
        $state = $query['state'] ?? null;
        $this->logger->info('kb_plus_callback_received', [
            'stage' => $stage,
            'method' => $request->getMethod(),
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'session_matched' => $currencyId !== null,
            'state_present' => array_key_exists('state', $query),
            'state_length' => is_string($state) ? strlen($state) : null,
            'state_format_valid' => is_string($state) && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $state) === 1,
            'salt_present' => array_key_exists('salt', $query),
            'encrypted_data_present' => array_key_exists('encryptedData', $query),
            'code_present' => array_key_exists('code', $query),
            'body_present' => $request->getParsedBody() !== null,
        ]);
    }

    private function denied(Request $request, Response $response, AccessLevel $minimum): ?Response
    {
        return RequestAuthorization::isSessionAuth($request)
            && RequestAuthorization::allows($request, self::PERMISSION, $minimum)
            ? null
            : Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
    }

    private function operationError(Response $response, BankConnectorOperationException $e): Response
    {
        $status = match ($e->errorCode) {
            'connection_not_found' => 404,
            'bank_connection_busy', 'bank_rate_limited', 'kb_plus_onboarding_used_or_expired', 'kb_plus_not_connected' => 409,
            'provider_account_mismatch', 'account_inactive', 'kb_plus_registration_input_invalid', 'kb_plus_plan_invalid',
            'kb_plus_onboarding_invalid', 'kb_plus_account_not_found', 'kb_plus_account_ambiguous' => 422,
            'app_url_invalid', 'encryption_key_unavailable', 'kb_plus_contact_missing',
            'kb_plus_callback_query_redaction_required' => 503,
            default => 502,
        };
        return Json::error($response, $e->errorCode, 'Onboarding KB+ se nepodařilo bezpečně dokončit.', $status);
    }

    private function cleanReturn(string $status, ?int $currencyId, ?string $errorCode = null): string
    {
        return BankOAuthCallbackHandler::kbPlusLocation(BankOAuthCallbackHandler::appBaseUrl($this->config), $status, $currencyId, $errorCode);
    }

    private function assertBankRedirect(string $url): void
    {
        $parts = parse_url($url);
        $allowed = [
            'api-gateway.kb.cz' => '/client-registration-ui/v2/saml/register',
            'login.kb.cz' => '/autfe/ssologin',
        ];
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? null) !== 'https' || !isset($allowed[$host])
            || ($parts['path'] ?? '') !== $allowed[$host]
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            throw new BankConnectorOperationException('kb_plus_redirect_invalid');
        }
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response
            ->withHeader('Location', $location)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withStatus(302);
    }
}
