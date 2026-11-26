<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemOffsetService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Vyrovnání ostatní položky zápočtem ({@see OtherItemOffsetService}).
 *
 *   GET    /api/accounting/other-items/{id}/offset                         — účet položky, zbývá, předvolby protiúčtu
 *   GET    /api/accounting/other-items/{id}/offset/lines?q=                 — volné řádky deníku na účtu položky
 *   POST   /api/accounting/other-items/{id}/offset                         — zápočet novým interním dokladem
 *   POST   /api/accounting/other-items/{id}/offset/attach                  — přiřazení existujícího řádku deníku
 *   DELETE /api/accounting/other-items/{id}/offset/{allocation_id}         — odebrání přiřazení (zápis zůstává)
 *   POST   /api/accounting/other-items/{id}/offset/{allocation_id}/cancel  — storno zápočtu z položky
 *
 * Práva: RoutePermissionMap pouští `other_items` (čtení / zápis). Zápočet zaúčtuje interní
 * doklad, proto zaúčtování a storno chtějí navíc `accounting.journal.post` (jako zaúčtování
 * položky). Přiřazení a odebrání řádku je rozpis zápisu v deníku, chce `accounting.journal.write`
 * (jako rozpad v saldokontu); výběr řádků deníku `accounting` ke čtení.
 */
final class OtherItemOffsetAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly OtherItemOffsetService $offsets,
        private readonly OtherItemService $items,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function context(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return $this->run($response, fn () => $this->offsets->context($this->currentSupplierId($request), (int) $args['id']));
    }

    public function lines(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting', AccessLevel::READ, $err)) return $err;
        $q = $request->getQueryParams();
        $search = is_scalar($q['q'] ?? null) ? trim((string) $q['q']) : '';
        return $this->run($response, fn () => ['items' => $this->offsets->lineCandidates(
            $this->currentSupplierId($request), (int) $args['id'], $search !== '' ? $search : null)]);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args) {
            $supplierId = $this->currentSupplierId($request);
            $id = (int) $args['id'];
            $body = (array) ($request->getParsedBody() ?? []);
            $result = $this->offsets->offset($supplierId, $id, [
                'account_id' => $body['account_id'] ?? null,
                'date'       => $body['date'] ?? null,
                'amount'     => $body['amount'] ?? null,
                'note'       => $body['note'] ?? null,
            ], $this->auditMeta($request));
            $this->log($request, 'other_item.offset_created', $id, $result + ['amount' => $body['amount'] ?? null]);
            return $this->items->get($supplierId, $id);
        }, 201);
    }

    public function attach(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args) {
            $supplierId = $this->currentSupplierId($request);
            $id = (int) $args['id'];
            $body = (array) ($request->getParsedBody() ?? []);
            $result = $this->offsets->attach($supplierId, $id, [
                'line_id' => $body['line_id'] ?? null,
                'amount'  => $body['amount'] ?? null,
            ], $this->userId($request));
            $this->log($request, 'other_item.offset_attached', $id, $result + ['amount' => $body['amount'] ?? null]);
            return $this->items->get($supplierId, $id);
        }, 201);
    }

    public function detach(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args) {
            $supplierId = $this->currentSupplierId($request);
            $id = (int) $args['id'];
            $removed = $this->offsets->detach($supplierId, $id, (int) $args['allocation_id']);
            $this->log($request, 'other_item.offset_detached', $id, [
                'allocation_id' => $removed['id'], 'entry_id' => $removed['entry_id'], 'amount' => $removed['amount'],
            ]);
            return $this->items->get($supplierId, $id);
        });
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args) {
            $supplierId = $this->currentSupplierId($request);
            $id = (int) $args['id'];
            $body = (array) ($request->getParsedBody() ?? []);
            $date = is_scalar($body['entry_date'] ?? null) ? trim((string) $body['entry_date']) : '';
            $cancelled = $this->offsets->cancel($supplierId, $id, (int) $args['allocation_id'],
                is_scalar($body['reason'] ?? null) ? (string) $body['reason'] : '', $date !== '' ? $date : null,
                $this->auditMeta($request));
            $this->log($request, 'other_item.offset_cancelled', $id, [
                'allocation_id' => $cancelled['id'], 'internal_document_id' => $cancelled['internal_document_id'],
                'reversal_entry_id' => $cancelled['reversal_entry_id'], 'amount' => $cancelled['amount'],
            ]);
            return $this->items->get($supplierId, $id);
        });
    }

    private function guard(Request $request, Response $response, string $permission, AccessLevel $level, ?Response &$err): bool
    {
        if (!$this->requirePermission($request, $response, $permission, $level, $err)) return false;
        return $this->requireDoubleEntry($this->db, $this->currentSupplierId($request), $response, $err);
    }

    private function run(Response $response, callable $call, int $status = 200): Response
    {
        try {
            return Json::ok($response, $call(), $status);
        } catch (OtherItemException $e) {
            // `details` nese podrobnost hlášky (zbývající částka, datum vzniku, číslo zápisu),
            // aby ji přeložená hláška v UI uměla ukázat.
            return Json::error($response, 'other_items.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus,
                array_filter(['field' => $e->field, 'details' => $e->details !== [] ? $e->details : null], static fn ($v): bool => $v !== null));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    /** @param array<string,mixed> $context */
    private function log(Request $request, string $action, int $id, array $context = []): void
    {
        $this->logger->log($action, $this->userId($request), 'other_item', $id, $context,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
