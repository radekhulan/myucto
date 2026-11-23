<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineChecklistBulkService;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Seskupený přehled zákonných termínů a hromadné vyřízení lhůt u lidí.
 *
 * `GET /deadlines/groups` — skupiny s počty (bez seznamu lidí),
 * `GET /deadlines/items` — stránka lidí jedné skupiny s hledáním,
 * `POST /deadlines/checklist/complete` — odškrtnutí vybraných položek nebo
 * celé skupiny po dávkách s kurzorem.
 *
 * Čtení jede na stejné právo jako plochý přehled (`payroll.submissions`),
 * zápis na stejné právo jako odškrtnutí jedné položky na kartě vztahu
 * (`payroll.employment.write`) — je to tatáž operace, jen pro víc lidí.
 */
final class PayrollDeadlineGroupsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollDeadlineOverviewService $service,
        private readonly PayrollDeadlineChecklistBulkService $bulk,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function groups(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.submissions', AccessLevel::READ)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        try {
            $result = $this->service->groupedOverview(
                $this->currentSupplierId($request),
                self::stringParam($query, 'environment', 'production'),
                self::horizon($query),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $result);
    }

    public function items(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.submissions', AccessLevel::READ)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        try {
            $result = $this->service->groupItems(
                $this->currentSupplierId($request),
                self::stringParam($query, 'environment', 'production'),
                self::horizon($query),
                self::stringParam($query, 'phase', ''),
                self::stringParam($query, 'source', ''),
                self::stringParam($query, 'title', ''),
                mb_substr(self::stringParam($query, 'q', ''), 0, 100),
                (int) self::stringParam($query, 'offset', '0'),
                (int) self::stringParam(
                    $query,
                    'limit',
                    (string) PayrollDeadlineOverviewService::GROUP_ITEMS_DEFAULT_LIMIT,
                ),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $result);
    }

    /**
     * Tělo: `note` (povinná) a buď `item_ids`, nebo skupina `phase` +
     * `item_key` (+ `horizon_days`, `q`). Volitelně `after_id` z předchozí
     * odpovědi. Idempotentní: vyřízená položka se hlásí jako přeskočená.
     */
    public function completeChecklist(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.employment.write', AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $ip = $this->ip($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        $filter = null;
        $ids = null;
        try {
            if (array_key_exists('item_ids', $body)) {
                if (!is_array($body['item_ids'])) {
                    throw new \InvalidArgumentException('Pole item_ids musí být seznam id položek.');
                }
                $ids = [];
                foreach ($body['item_ids'] as $id) {
                    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                        throw new \InvalidArgumentException('Pole item_ids musí být seznam id položek.');
                    }
                    $ids[] = (int) $id;
                }
            } else {
                $filter = [
                    'phase' => self::stringParam($body, 'phase', ''),
                    'item_key' => self::stringParam($body, 'item_key', ''),
                    'horizon_days' => self::horizon($body),
                    'q' => mb_substr(self::stringParam($body, 'q', ''), 0, 100),
                ];
            }
            $note = $body['note'] ?? '';
            $result = $this->bulk->complete(
                $supplierId,
                $ids,
                $filter,
                is_string($note) ? $note : '',
                $userId,
                $ip,
                $userAgent,
                max(0, (int) ($body['after_id'] ?? 0)),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.deadlines.checklist_completed_batch',
            $userId,
            'payroll_employment_checklist_item',
            null,
            [
                'completed_count' => count($result['completed']),
                'skipped_count' => count($result['skipped']),
                'failed_count' => count($result['failed']),
                'remaining' => $result['remaining'],
                'filter' => $filter,
                'ids_count' => $ids === null ? null : count($ids),
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    private function authorize(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
    ): ?Response {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $error ?? throw new \LogicException('Chybí odpověď pro zamítnuté oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí odpověď pro vypnutý modul mezd.');
        }

        return null;
    }

    private function ip(Request $request): string
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $this->ipMatcher->clientIpFromRequest($params);
    }

    /** @param array<array-key,mixed> $params */
    private static function horizon(array $params): int
    {
        $value = $params['horizon_days'] ?? null;

        return $value === null || $value === ''
            ? PayrollDeadlineOverviewService::DEFAULT_HORIZON_DAYS
            : (int) $value;
    }

    /** @param array<array-key,mixed> $params */
    private static function stringParam(array $params, string $name, string $default): string
    {
        $value = $params[$name] ?? $default;
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim($value) : '';
    }
}
