<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\Repository\EntityTagRepository;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Vynechané pole zachová přiřazení při částečném zápisu i u klientské role. */
final class EntityTagInput
{
    public static function rejectInvalid(Request $request, Response $response, array $body): ?Response
    {
        if (!array_key_exists('tags', $body)) return null;
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Štítky jsou interní.', 403);
        }
        if (!EntityTagRepository::validNames($body['tags'])) {
            $message = 'Zadejte nejvýše 64 neprázdných štítků po nejvýše 64 znacích.';
            return Json::error($response, 'validation_failed', $message, 400, ['fields' => ['tags' => [$message]]]);
        }
        return null;
    }
}
