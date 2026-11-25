<?php

declare(strict_types=1);

namespace MyInvoice\Action\Tag;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\EntityTagRepository;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SuggestEntityTagsAction
{
    public function __construct(private readonly EntityTagRepository $tags) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Štítky jsou interní.', 403);
        }
        $ids = $request->getQueryParams()['ids'] ?? null;
        if ($ids !== null) {
            if (!EntityTagRepository::validFilter($ids) || $ids === '' || $ids === 'none') {
                return Json::error($response, 'validation_failed', 'Neplatný výběr štítků.', 422);
            }
            return Json::ok($response, $this->tags->byIds(SupplierGuard::currentId($request), explode(',', $ids)));
        }
        $prefix = $request->getQueryParams()['q'] ?? '';
        if (!is_string($prefix) || mb_strlen($prefix) > 64) {
            return Json::error($response, 'validation_failed', 'Neplatný hledaný štítek.', 422);
        }
        return Json::ok($response, $this->tags->suggest(SupplierGuard::currentId($request), trim($prefix)));
    }
}
