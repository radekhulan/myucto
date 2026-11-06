<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class WebAuthnBodyLimitMiddleware implements MiddlewareInterface
{
    private const MAX_BYTES = 65_536;

    private const VERIFY_PATHS = [
        '/api/auth/webauthn/register/verify',
        '/api/auth/webauthn/login/verify',
        '/api/auth/webauthn/step-up/verify',
        '/api/auth/session/unlock/verify',
    ];

    public function __construct(private readonly ResponseFactory $responseFactory) {}

    public function process(Request $request, Handler $handler): Response
    {
        // Normalizovaná cesta (jedno rawurldecode jako router) — jinak stačil jeden
        // `%XX` a limit velikosti těla se na ceremonii vůbec neuplatnil.
        $path = RequestPath::normalize($request->getUri()->getPath());
        if (!in_array($path, self::VERIFY_PATHS, true)
            && preg_match('#^/api/auth/webauthn/credentials/[0-9]+/test/verify$#D', $path) !== 1
        ) {
            return $handler->handle($request);
        }

        $body = $request->getBody();
        $size = $body->getSize();
        if ($size === null) {
            $size = strlen((string) $body);
        }
        if ($size <= self::MAX_BYTES) {
            return $handler->handle($request);
        }

        return Json::error(
            $this->responseFactory->createResponse(413),
            'request_too_large',
            'WebAuthn požadavek je příliš velký.',
            413,
        );
    }
}
