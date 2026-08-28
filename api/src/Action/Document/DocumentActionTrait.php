<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Service\Document\DocumentViewerResolver;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Sdílené pomocné metody pro Document akce (supplier scope, user, IP). */
trait DocumentActionTrait
{
    private function supplierId(Request $request): int
    {
        return SupplierGuard::currentId($request);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    /**
     * Per-user scope guard kontext (Epic F7, §4.2). Superadmin vidí vše tenanta;
     * ostatní jen firemní a vlastní user dokumenty. Efektivní roli dodává
     * PermissionMiddleware jako request-scoped EffectiveRole.
     */
    private function viewer(Request $request): DocumentViewerContext
    {
        return DocumentViewerResolver::fromRequest($request);
    }

    private function clientIp(Request $request): ?string
    {
        $params = $request->getServerParams();
        $ip = $params['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /** Volitelný int z parsed body / query (NULL když chybí nebo prázdné). */
    private function optInt(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === 'null') return null;
        return (int) $v;
    }
}
