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
use MyInvoice\Service\Bank\Connector\KbPlusOnboardingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class KbPlusOnboardingAction
{
    private const PERMISSION = 'settings.bank_accounts';

    public function __construct(
        private readonly KbPlusOnboardingService $service,
        private readonly Config $config,
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
        try {
            $result = $this->service->completeRegistration(
                $supplierId,
                (int) ($user['id'] ?? 0),
                $state,
                [
                    'state' => $state,
                    'salt' => is_string($query['salt'] ?? null) ? $query['salt'] : '',
                    'encryptedData' => is_string($query['encryptedData'] ?? null) ? $query['encryptedData'] : '',
                ],
            );
            $this->assertBankRedirect((string) $result['redirect_url']);
            return $this->redirect($response, (string) $result['redirect_url']);
        } catch (BankConnectorOperationException $e) {
            return $this->redirect($response, $this->cleanReturn('error', $currencyId, $e->errorCode));
        }
    }

    public function oauthCallback(Request $request, Response $response): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::WRITE)) {
            return $denied;
        }
        $query = $request->getQueryParams();
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $supplierId = SupplierGuard::currentId($request);
        $userId = (int) ($user['id'] ?? 0);
        $currencyId = $this->service->currencyForState($supplierId, $userId, $state);
        try {
            $currencyId = $this->service->completeOAuth($supplierId, $userId, $state, $code);
            return $this->redirect($response, $this->cleanReturn('connected', $currencyId));
        } catch (BankConnectorOperationException $e) {
            return $this->redirect($response, $this->cleanReturn('error', $currencyId, $e->errorCode));
        }
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
            'bank_connection_busy', 'bank_rate_limited', 'kb_plus_onboarding_used_or_expired' => 409,
            'provider_account_mismatch', 'account_inactive', 'kb_plus_registration_input_invalid',
            'kb_plus_onboarding_invalid', 'kb_plus_account_not_found', 'kb_plus_account_ambiguous' => 422,
            'app_url_invalid', 'encryption_key_unavailable', 'kb_plus_contact_missing',
            'kb_plus_callback_query_redaction_required' => 503,
            default => 502,
        };
        return Json::error($response, $e->errorCode, 'Onboarding KB+ se nepodařilo bezpečně dokončit.', $status);
    }

    private function cleanReturn(string $status, ?int $currencyId, ?string $errorCode = null): string
    {
        $query = ['tab' => 'accounts', 'kb_plus' => $status];
        if ($currencyId !== null && $currencyId > 0) {
            $query['currency_id'] = $currencyId;
        }
        if ($errorCode !== null && preg_match('/^[a-z0-9_]{1,80}$/D', $errorCode)) {
            $query['code'] = $errorCode;
        }
        $base = $this->baseUrl();
        return ($base === '' ? '' : $base) . '/bank?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
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

    private function baseUrl(): string
    {
        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        $parts = parse_url($base);
        if (($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            return '';
        }
        return $base;
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
