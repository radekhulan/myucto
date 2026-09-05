<?php

declare(strict_types=1);

namespace MyInvoice\Action\Automation;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Automation\AutomationRecommendationCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AutomationRecommendationAction
{
    private const TYPES = ['post_invoice', 'post_purchase', 'classify_purchase', 'bank_rule'];

    public function __construct(private readonly AutomationRecommendationCache $recommendations) {}

    public function recommendations(Request $request, Response $response): Response
    {
        $query = $this->query($request);
        if ($query === null) {
            return Json::error($response, 'invalid_query', 'Neplatné parametry doporučení.', 422);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return Json::ok($response, $this->recommendations->recommendations(
            (int) ($user['id'] ?? 0),
            RequestAuthorization::isSuperadmin($request),
            $query,
        ));
    }

    public function refresh(Request $request, Response $response): Response
    {
        $query = $this->query($request);
        if ($query === null) return Json::error($response, 'invalid_query', 'Neplatné parametry doporučení.', 422);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return Json::ok($response, $this->recommendations->requestRefresh(
            (int) ($user['id'] ?? 0), RequestAuthorization::isSuperadmin($request), $query['suppliers'],
        ), 202);
    }

    /** @return array{suppliers:list<int>,from:?string,to:?string,type:?string,page:int,per_page:int}|null */
    private function query(Request $request): ?array
    {
        $q = $request->getQueryParams();
        $suppliers = $this->supplierIds($q['suppliers'] ?? null);
        if ($suppliers === null || !$this->positiveInteger($q['page'] ?? null) || !$this->positiveInteger($q['per_page'] ?? null)) {
            return null;
        }
        if ((isset($q['from']) && !is_string($q['from']))
            || (isset($q['to']) && !is_string($q['to']))
            || (isset($q['type']) && !is_string($q['type']))) {
            return null;
        }
        $from = isset($q['from']) && $q['from'] !== '' ? (string) $q['from'] : null;
        $to = isset($q['to']) && $q['to'] !== '' ? (string) $q['to'] : null;
        $type = isset($q['type']) && $q['type'] !== '' ? (string) $q['type'] : null;
        if (!$this->validDate($from) || !$this->validDate($to) || ($from !== null && $to !== null && $from > $to)
            || ($type !== null && !in_array($type, self::TYPES, true))) {
            return null;
        }
        return [
            'suppliers' => $suppliers,
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'page' => max(1, (int) ($q['page'] ?? 1)),
            'per_page' => max(1, min(200, (int) ($q['per_page'] ?? 50))),
        ];
    }

    /** @return list<int>|null */
    private function supplierIds(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (!is_string($value) && !is_int($value)) {
                    return null;
                }
            }
            $raw = implode(',', $raw);
        }
        $ids = [];
        foreach (explode(',', (string) $raw) as $part) {
            $part = trim($part);
            if (!ctype_digit($part) || (int) $part <= 0) {
                return null;
            }
            $ids[] = (int) $part;
        }
        return array_values(array_unique($ids));
    }

    private function positiveInteger(mixed $value): bool
    {
        return $value === null || $value === ''
            || ((is_string($value) || is_int($value)) && ctype_digit((string) $value) && (int) $value > 0);
    }

    private function validDate(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
