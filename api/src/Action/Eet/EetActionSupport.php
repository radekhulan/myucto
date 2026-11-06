<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Eet\EetException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Společné kousky akcí EET: druhá vrstva oprávnění, uživatel, tělo a mapování chyb. */
final class EetActionSupport
{
    public const PERMISSIONS = ['eet', 'eet.accept_payment', 'eet.correct', 'eet.settings', 'eet.certificates', 'eet.export'];

    /** Druhá vrstva za RoutePermissionMap — pro volání mimo middleware. */
    public static function deny(Request $request, Response $response, string $permission, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, $permission, $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        return null;
    }

    public static function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** @return array<string,mixed> */
    public static function body(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @param array<string,mixed> $args */
    public static function id(array $args): int
    {
        return (int) ($args['id'] ?? 0);
    }

    public static function error(Response $response, EetException $exception): Response
    {
        return Json::error($response, $exception->errorCode, $exception->getMessage(), $exception->httpStatus, $exception->details);
    }

    /**
     * Efektivní práva uživatele k modulu EET: `none` | `read` | `write`.
     *
     * @return array<string,string>
     */
    public static function permissions(Request $request): array
    {
        $out = [];
        foreach (self::PERMISSIONS as $key) {
            $out[$key] = RequestAuthorization::allows($request, $key, AccessLevel::WRITE)
                ? 'write'
                : (RequestAuthorization::allows($request, $key, AccessLevel::READ) ? 'read' : 'none');
        }
        return $out;
    }
}
