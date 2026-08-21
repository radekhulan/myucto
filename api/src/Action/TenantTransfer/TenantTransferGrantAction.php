<?php

declare(strict_types=1);

namespace MyInvoice\Action\TenantTransfer;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\ProtectedOperationAuthenticationException;
use MyInvoice\Service\Auth\ProtectedOperationRateLimitedException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\TenantTransfer\Grant\TenantTransferGrantService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TenantTransferGrantAction
{
    public function __construct(
        private readonly Config $config,
        private readonly TenantTransferGrantService $grants,
        private readonly MfaProtectedOperationService $protectedOperations,
        private readonly BruteForceGuard $bruteForce,
        private readonly PasswordHasher $passwords,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $context = $this->context($request, $response, AccessLevel::READ);
        if ($context instanceof Response) {
            return $context;
        }

        return Json::ok($response, [
            'grants' => $this->grants->listForSupplier($context['supplier_id']),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $context = $this->context($request, $response, AccessLevel::WRITE);
        if ($context instanceof Response) {
            return $context;
        }

        $parsedBody = $request->getParsedBody();
        $body = is_array($parsedBody) ? $parsedBody : [];
        try {
            $issued = $this->protectedOperations->createTenantTransferGrant(
                $this->grants,
                $this->bruteForce,
                $this->passwords,
                $context['user_id'],
                $context['session_token'],
                self::stringField($body, 'password'),
                self::stringField($body, 'step_up_token'),
                $context['supplier_id'],
                $context['ip'],
            );
        } catch (ProtectedOperationRateLimitedException) {
            return $this->reject(
                $request,
                $response->withHeader('Retry-After', '900'),
                $context['supplier_id'],
                $context['user_id'],
                'reauthentication_rate_limited',
                'transfer_reauthentication_rate_limited',
                'Opětovné ověření se nezdařilo. Zkus to později.',
                429,
            );
        } catch (ProtectedOperationAuthenticationException $exception) {
            if ($exception->reason === 'missing_step_up') {
                $this->auditRejection(
                    $request,
                    $context['supplier_id'],
                    $context['user_id'],
                    $exception->reason,
                    $context['ip'],
                );
                return Json::error(
                    $response,
                    'step_up_required',
                    'Pro přenos firmy je vyžadováno nové MFA ověření.',
                    401,
                    ['methods' => $this->mfaPolicy->allowedMethods()],
                );
            }
            return $this->reject(
                $request,
                $response,
                $context['supplier_id'],
                $context['user_id'],
                $exception->reason,
                'transfer_reauthentication_failed',
                'Opětovné ověření se nezdařilo.',
                403,
            );
        } catch (\DomainException) {
            return $this->reject(
                $request,
                $response,
                $context['supplier_id'],
                $context['user_id'],
                'session_unavailable',
                'transfer_reauthentication_failed',
                'Opětovné ověření se nezdařilo.',
                403,
            );
        }

        $data = [
            'id' => $issued['id'],
            'public_id' => $issued['public_id'],
            'code' => $issued['plaintext'],
            'code_prefix' => $issued['prefix'],
            'supplier_id' => $issued['supplier_id'],
            'expires_at' => $issued['expires_at'],
            'warning' => 'Přenosový kód se zobrazí pouze jednou. Vlož jej do cílového MyÚčta a neposílej jej v URL.',
        ];
        $configuredUrl = $this->config->get('app.url', '');
        $sourceUrl = is_string($configuredUrl) ? rtrim($configuredUrl, '/') : '';
        if ($sourceUrl !== '') {
            $data['source_url'] = $sourceUrl;
        }

        return Json::ok($response, $data, 201);
    }

    /** @param array<string,string> $args */
    public function revoke(Request $request, Response $response, array $args): Response
    {
        $context = $this->context($request, $response, AccessLevel::WRITE);
        if ($context instanceof Response) {
            return $context;
        }

        $grantId = (int) ($args['id'] ?? 0);
        if ($grantId < 1) {
            return $this->reject(
                $request,
                $response,
                $context['supplier_id'],
                $context['user_id'],
                'invalid_grant_id',
                'validation_failed',
                'Chybí ID transfer grantu.',
                400,
            );
        }

        $this->grants->revoke(
            $grantId,
            $context['supplier_id'],
            $context['user_id'],
            $context['ip'],
        );
        return Json::ok($response, ['ok' => true]);
    }

    /**
     * @return array{user_id:int,supplier_id:int,session_token:string,ip:string}|Response
     */
    private function context(
        Request $request,
        Response $response,
        AccessLevel $minimum,
    ): array|Response {
        if ($this->config->get('tenant_transfer.enabled', false) !== true) {
            return Json::error(
                $response,
                'transfer_api_disabled',
                'Přenos firem není na této instanci zapnutý.',
                404,
            );
        }

        $ip = $this->clientIp($request);
        $userAttribute = $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $user = is_array($userAttribute) ? $userAttribute : [];
        $userId = self::positiveInt($user['id'] ?? null);
        $supplierId = self::positiveInt($request->getAttribute(
            SupplierScopeMiddleware::ATTR_CURRENT_ID,
            0,
        ));
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return $this->reject(
                $request,
                $response,
                $supplierId > 0 ? $supplierId : null,
                $userId > 0 ? $userId : null,
                'session_required',
                'session_required',
                'Správa transfer grantů vyžaduje přihlášenou browser session.',
                403,
                $ip,
            );
        }
        $sessionAttribute = $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        $sessionToken = is_string($sessionAttribute) ? $sessionAttribute : '';
        if ($userId < 1 || $sessionToken === '') {
            return $this->reject(
                $request,
                $response,
                $supplierId > 0 ? $supplierId : null,
                $userId > 0 ? $userId : null,
                'unauthenticated',
                'unauthenticated',
                'Nepřihlášený uživatel.',
                401,
                $ip,
            );
        }
        if ($supplierId < 1) {
            return $this->reject(
                $request,
                $response,
                null,
                $userId,
                'supplier_required',
                'supplier_required',
                'Nejprve vyber firmu, kterou chceš přenést.',
                409,
                $ip,
            );
        }
        if (!RequestAuthorization::allows($request, 'tenant.transfer.export', $minimum)) {
            return $this->reject(
                $request,
                $response,
                $supplierId,
                $userId,
                'forbidden_permission',
                'forbidden',
                'Pro přenos této firmy nemáš oprávnění.',
                403,
                $ip,
            );
        }

        return [
            'user_id' => $userId,
            'supplier_id' => $supplierId,
            'session_token' => $sessionToken,
            'ip' => $ip,
        ];
    }

    private function reject(
        Request $request,
        Response $response,
        ?int $supplierId,
        ?int $actorUserId,
        string $auditReason,
        string $publicCode,
        string $publicMessage,
        int $status,
        ?string $knownIp = null,
    ): Response {
        $this->auditRejection(
            $request,
            $supplierId,
            $actorUserId,
            $auditReason,
            $knownIp ?? $this->clientIp($request),
        );
        return Json::error($response, $publicCode, $publicMessage, $status);
    }

    private function auditRejection(
        Request $request,
        ?int $supplierId,
        ?int $actorUserId,
        string $reason,
        string $ip,
    ): void {
        $this->grants->auditManagementRejection(
            $supplierId,
            $actorUserId,
            $reason,
            $request->getMethod(),
            $request->getUri()->getPath(),
            $ip,
        );
    }

    /** @param array<array-key,mixed> $body */
    private static function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    private static function positiveInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int) $value;
        }
        return 0;
    }

    private function clientIp(Request $request): string
    {
        $serverParams = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $serverParams[$key] = $value;
            }
        }
        return $this->ipMatcher->clientIpFromRequest($serverParams);
    }
}
