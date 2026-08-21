<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\TenantTransfer\Grant\TenantTransferGrantService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/** Autorizace vyhrazená výhradně inter-instance tenant transfer API. */
final class TenantTransferGrantMiddleware implements MiddlewareInterface
{
    public const HEADER_NAME = 'X-MyUcto-Transfer-Grant';
    public const ATTR_GRANT = 'tenant_transfer.grant';
    public const ATTR_SUPPLIER_ID = 'tenant_transfer.supplier_id';
    public const AUTH_METHOD = 'tenant_transfer_grant';

    public function __construct(
        private readonly Config $config,
        private readonly TenantTransferGrantService $grants,
        private readonly IpMatcher $ipMatcher,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if ($this->config->get('tenant_transfer.enabled', false) !== true) {
            return Json::error(
                $this->responseFactory->createResponse(404),
                'transfer_api_disabled',
                'Přenos firem není na této instanci zapnutý.',
                404,
            );
        }

        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        $ip = $this->ipMatcher->clientIpFromRequest(self::serverParams($request));
        $configuredCookieName = $this->config->get(
            'session.cookie_name',
            '__Host-myinvoice_session',
        );
        $sessionCookieName = is_string($configuredCookieName)
            && $configuredCookieName !== ''
            ? $configuredCookieName
            : '__Host-myinvoice_session';
        $sessionCookie = $request->getCookieParams()[$sessionCookieName] ?? null;
        if (trim($request->getHeaderLine('Authorization')) !== ''
            || (is_string($sessionCookie) && trim($sessionCookie) !== '')
            || in_array(
                $request->getAttribute(AuthMiddleware::ATTR_METHOD),
                ['bearer', 'session'],
                true,
            )
        ) {
            $rejection = $this->grants->rejectAuthenticationAttempt(
                'ordinary_authorization_not_allowed',
                $method,
                $path,
                $ip,
            );
            $response = $this->responseFactory->createResponse($rejection->httpStatus);
            if ($rejection->retryAfterSeconds !== null) {
                $response = $response->withHeader(
                    'Retry-After',
                    (string) $rejection->retryAfterSeconds,
                );
            }
            return Json::error(
                $response,
                $rejection->errorCode ?? 'transfer_authorization_required',
                'Běžný API token ani browser session nejsou transfer oprávnění.',
                $rejection->httpStatus,
            );
        }

        $headerValues = $request->getHeader(self::HEADER_NAME);
        $plaintext = count($headerValues) === 1 ? trim($headerValues[0]) : '';
        $validation = $this->grants->authenticate($plaintext, $method, $path, $ip);
        if (!$validation->isAllowed()) {
            $response = $this->responseFactory->createResponse($validation->httpStatus);
            if ($validation->retryAfterSeconds !== null) {
                $response = $response->withHeader(
                    'Retry-After',
                    (string) $validation->retryAfterSeconds,
                );
            }
            return Json::error(
                $response,
                $validation->errorCode ?? 'invalid_transfer_grant',
                'Transfer oprávnění je neplatné, expirované nebo odvolané.',
                $validation->httpStatus,
            );
        }

        $grant = $validation->grant;
        $supplierId = is_array($grant)
            ? self::positiveInt($grant['supplier_id'] ?? null)
            : 0;
        if ($supplierId < 1) {
            throw new \UnexpectedValueException('Transfer grant nemá platnou vazbu na firmu.');
        }

        return $handler->handle(
            $request
                ->withAttribute(self::ATTR_GRANT, $grant)
                ->withAttribute(self::ATTR_SUPPLIER_ID, $supplierId)
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, self::AUTH_METHOD),
        );
    }

    /** @return array<string,mixed> */
    private static function serverParams(Request $request): array
    {
        $serverParams = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $serverParams[$key] = $value;
            }
        }
        return $serverParams;
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
}
