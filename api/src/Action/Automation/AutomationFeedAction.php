<?php

declare(strict_types=1);

namespace MyInvoice\Action\Automation;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Automation\AutomationFeedService;
use MyInvoice\Service\Automation\FeedQuery;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AutomationFeedAction
{
    private const TABS = ['auto', 'pending', 'needs_input'];
    private const SOURCES = ['rule', 'learned', 'payment_match', 'transfer', 'detector', 'schedule', 'knn', 'llm', 'ai', 'document'];
    private const SORTS = ['default', 'date', 'confidence', 'amount', 'operation_type', 'source'];

    public function __construct(private readonly AutomationFeedService $feed) {}

    public function feed(Request $request, Response $response): Response
    {
        $query = $this->query($request);
        if ($query === null) return Json::error($response, 'invalid_query', 'Neplatné parametry fronty.', 422);
        [$userId, $isSuperadmin, $bound] = $this->identity($request);
        return Json::ok($response, $this->feed->feed($userId, $isSuperadmin, $query, $bound));
    }

    public function counts(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        if (!$this->validDate($q['from'] ?? null) || !$this->validDate($q['to'] ?? null)) {
            return Json::error($response, 'invalid_date', 'Datum musí být ve formátu RRRR-MM-DD.', 422);
        }
        [$userId, $isSuperadmin, $bound] = $this->identity($request);
        return Json::ok($response, $this->feed->counts(
            $userId,
            $isSuperadmin,
            isset($q['from']) && $q['from'] !== '' ? (string) $q['from'] : null,
            isset($q['to']) && $q['to'] !== '' ? (string) $q['to'] : null,
            $this->supplierIds($q['suppliers'] ?? ''),
            $bound,
        ));
    }

    public function stats(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $supplierId = isset($q['supplier_id']) && ctype_digit((string) $q['supplier_id'])
            ? (int) $q['supplier_id']
            : (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        [$userId, $isSuperadmin, $bound] = $this->identity($request);
        if (!in_array($supplierId, $this->feed->allowedSupplierIds($userId, $isSuperadmin, $bound), true)) {
            return Json::error($response, 'not_found', 'Firma nebyla nalezena.', 404);
        }
        $from = isset($q['from']) && $q['from'] !== '' ? (string) $q['from'] : date('Y-01-01');
        $to = isset($q['to']) && $q['to'] !== '' ? (string) $q['to'] : date('Y-m-d');
        if (!$this->validDate($from) || !$this->validDate($to) || $from > $to) {
            return Json::error($response, 'invalid_date', 'Neplatné období.', 422);
        }
        return Json::ok($response, $this->feed->stats($supplierId, $from, $to));
    }

    public function overview(Request $request, Response $response): Response
    {
        $supplierId = $this->authorizedSupplier($request);
        if ($supplierId === null) return Json::error($response, 'not_found', 'Firma nebyla nalezena.', 404);
        return Json::ok($response, $this->feed->overview($supplierId));
    }

    public function checklist(Request $request, Response $response): Response
    {
        $supplierId = $this->authorizedSupplier($request);
        if ($supplierId === null) return Json::error($response, 'not_found', 'Firma nebyla nalezena.', 404);
        $q = $request->getQueryParams();
        $scope = in_array(($q['scope'] ?? 'daily'), ['daily', 'month_end', 'vat_return'], true) ? (string) ($q['scope'] ?? 'daily') : 'daily';
        [$userId, $isSuperadmin, $bound] = $this->identity($request);
        return Json::ok($response, $this->feed->checklist(
            $userId, $isSuperadmin, $supplierId, $scope,
            $this->validDate($q['from'] ?? null) && ($q['from'] ?? '') !== '' ? (string) $q['from'] : null,
            $this->validDate($q['to'] ?? null) && ($q['to'] ?? '') !== '' ? (string) $q['to'] : null,
            $bound,
        ));
    }

    public function history(Request $request, Response $response): Response
    {
        $query = $this->query($request, true);
        if ($query === null) return Json::error($response, 'invalid_query', 'Neplatné parametry historie.', 422);
        [$userId, $isSuperadmin, $bound] = $this->identity($request);
        return Json::ok($response, $this->feed->history($userId, $isSuperadmin, $query, $bound));
    }

    private function query(Request $request, bool $history = false): ?FeedQuery
    {
        $q = $request->getQueryParams();
        $tab = $history ? 'auto' : (string) ($q['tab'] ?? 'pending');
        $source = isset($q['source']) && $q['source'] !== '' ? (string) $q['source'] : null;
        $sort = isset($q['sort']) && $q['sort'] !== '' ? (string) $q['sort'] : 'default';
        $direction = isset($q['direction']) && $q['direction'] !== '' ? strtolower((string) $q['direction']) : 'asc';
        $minConfidence = $this->number($q['min_confidence'] ?? null);
        $maxConfidence = $this->number($q['max_confidence'] ?? null);
        $minAmount = $this->number($q['min_amount'] ?? null);
        $maxAmount = $this->number($q['max_amount'] ?? null);
        if ((!$history && !in_array($tab, self::TABS, true))
            || ($source !== null && !in_array($source, self::SOURCES, true))
            || !in_array($sort, self::SORTS, true)
            || !in_array($direction, ['asc', 'desc'], true)
            || !$this->validDate($q['from'] ?? null) || !$this->validDate($q['to'] ?? null)
            || ($minConfidence !== null && ($minConfidence < 0 || $minConfidence > 1))
            || ($maxConfidence !== null && ($maxConfidence < 0 || $maxConfidence > 1))
            || ($minConfidence !== null && $maxConfidence !== null && $minConfidence > $maxConfidence)
            || ($minAmount !== null && $minAmount < 0) || ($maxAmount !== null && $maxAmount < 0)
            || ($minAmount !== null && $maxAmount !== null && $minAmount > $maxAmount)) return null;
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($q['per_page'] ?? 50)));
        return new FeedQuery(
            $tab,
            $this->supplierIds($q['suppliers'] ?? ''),
            $source,
            isset($q['operation_type']) && $q['operation_type'] !== '' ? (string) $q['operation_type'] : null,
            isset($q['from']) && $q['from'] !== '' ? (string) $q['from'] : null,
            isset($q['to']) && $q['to'] !== '' ? (string) $q['to'] : null,
            $page,
            $perPage,
            $minConfidence,
            $maxConfidence,
            $minAmount,
            $maxAmount,
            $sort,
            $direction,
        );
    }

    /**
     * Uživatel, globální admin a firma, na kterou je požadavek vázaný (token pro
     * jednu firmu, vlastní doména). Vázaný požadavek vidí jen tuto firmu.
     *
     * @return array{int,bool,?int}
     */
    private function identity(Request $request): array
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return [
            (int) ($user['id'] ?? 0),
            RequestAuthorization::isSuperadmin($request),
            RequestAuthorization::boundSupplierId($request),
        ];
    }

    private function authorizedSupplier(Request $request): ?int
    {
        $q = $request->getQueryParams();
        $supplierId = isset($q['supplier_id']) && ctype_digit((string) $q['supplier_id'])
            ? (int) $q['supplier_id']
            : (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        [$userId, $isSuperadmin, $bound] = $this->identity($request);
        return in_array($supplierId, $this->feed->allowedSupplierIds($userId, $isSuperadmin, $bound), true) ? $supplierId : null;
    }

    /** @return list<int> */
    private function supplierIds(mixed $raw): array
    {
        if (is_array($raw)) $raw = implode(',', $raw);
        $ids = [];
        foreach (explode(',', (string) $raw) as $part) {
            $part = trim($part);
            if (ctype_digit($part) && (int) $part > 0) $ids[] = (int) $part;
        }
        return array_values(array_unique($ids));
    }

    private function validDate(mixed $value): bool
    {
        if ($value === null || $value === '') return true;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        return $date !== false && $date->format('Y-m-d') === (string) $value;
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        return is_numeric($value) ? (float) $value : -INF;
    }
}
