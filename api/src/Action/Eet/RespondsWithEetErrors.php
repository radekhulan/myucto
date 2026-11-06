<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\IdempotencyConflictException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Společné mapování odmítnutí příkazů EET na JSON chyby (kódy dle kontraktu
 * aplikačního API: eet_validation, eet_not_configured, eet_unsupported_scenario,
 * idempotency_conflict, eet_state_conflict).
 */
trait RespondsWithEetErrors
{
    /** @param callable():array<string,mixed> $operation */
    private function respond(Response $response, callable $operation, int $status = 200): Response
    {
        try {
            return Json::ok($response, $operation(), $status);
        } catch (IdempotencyConflictException $e) {
            return Json::error($response, 'idempotency_conflict', $e->getMessage(), 409);
        } catch (EetCommandException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
        }
    }

    private static function userId(Request $request): int
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    /** @return array<string,mixed> */
    private static function body(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }
}
