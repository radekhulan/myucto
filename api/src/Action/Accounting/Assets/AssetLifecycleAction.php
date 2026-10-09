<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Assets;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle majetku (Epic F3) — zařazení, technické zhodnocení, vyřazení, revert.
 * Vše účetní|admin; byznys pravidla (stavy, zámky, období) vynucuje AssetService.
 *
 *   POST   /api/accounting/assets/{id}/put-into-use          — zařazení do užívání (MD 02x / D 042)
 *   POST   /api/accounting/assets/{id}/improvements          — technické zhodnocení §33
 *   DELETE /api/accounting/assets/{id}/improvements/{impId}  — smazání TZ (jen nepotvrzený rok)
 *   POST   /api/accounting/assets/{id}/dispose               — vyřazení (prodej/likvidace/dar/škoda)
 *   POST   /api/accounting/assets/{id}/dispose/revert        — vrácení vyřazení (R24)
 */
final class AssetLifecycleAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const DISPOSAL_TYPES = ['sold', 'liquidated', 'donated', 'damaged'];

    public function __construct(
        private readonly AssetService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function putIntoUse(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        $date = trim((string) ($body['date'] ?? ''));
        if (!$this->isDate($date)) {
            return Json::error($response, 'validation_failed', 'date je povinný a musí být datum (YYYY-MM-DD).', 422);
        }
        $bookEntry = array_key_exists('book_entry', $body) ? (bool) $body['book_entry'] : true;

        try {
            $result = $this->service->putIntoUse($supplierId, $id, $date, $bookEntry, $this->auditMeta($request));
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (UnbalancedEntryException | PostingException $e) {
            return $this->mapPostingError($response, $e);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Zařazení majetku do užívání selhalo');
        }

        $this->logEvent($request, 'asset.put_into_use', $id, ['date' => $date, 'book_entry' => $bookEntry]);
        return Json::ok($response, $result);
    }

    public function addImprovement(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        $completedOn = trim((string) ($body['completed_on'] ?? ''));
        if (!$this->isDate($completedOn)) {
            return Json::error($response, 'validation_failed', 'completed_on je povinný a musí být datum (YYYY-MM-DD).', 422);
        }
        $amount = (float) ($body['amount'] ?? 0);
        if (!is_numeric($body['amount'] ?? null) || $amount <= 0) {
            return Json::error($response, 'validation_failed', 'amount musí být kladné číslo.', 422);
        }
        $description = trim((string) ($body['description'] ?? ''));
        $purchaseInvoiceId = (int) ($body['purchase_invoice_id'] ?? 0);

        try {
            $result = $this->service->addImprovement($supplierId, $id, [
                'completed_on'        => $completedOn,
                'amount'              => round($amount, 2),
                'description'         => $description !== '' ? $description : null,
                'purchase_invoice_id' => $purchaseInvoiceId > 0 ? $purchaseInvoiceId : null,
            ]);
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Technické zhodnocení se nepodařilo přidat');
        }

        $this->logEvent($request, 'asset.improvement_added', $id, [
            'completed_on' => $completedOn,
            'amount'       => round($amount, 2),
        ]);
        return Json::ok($response, $result, 201);
    }

    public function deleteImprovement(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $impId = (int) ($args['impId'] ?? 0);

        try {
            $this->service->deleteImprovement($supplierId, $id, $impId);
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Technické zhodnocení se nepodařilo smazat');
        }

        return Json::ok($response, ['deleted' => true]);
    }

    public function dispose(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        $date = trim((string) ($body['date'] ?? ''));
        if (!$this->isDate($date)) {
            return Json::error($response, 'validation_failed', 'date je povinný a musí být datum (YYYY-MM-DD).', 422);
        }
        $type = trim((string) ($body['type'] ?? ''));
        if (!in_array($type, self::DISPOSAL_TYPES, true)) {
            return Json::error($response, 'validation_failed', "type musí být 'sold', 'liquidated', 'donated' nebo 'damaged'.", 422);
        }
        $price = null;
        if (isset($body['price']) && $body['price'] !== '' && $body['price'] !== null) {
            if ($type !== 'sold') {
                return Json::error($response, 'validation_failed', 'price lze zadat jen u prodeje (type=sold).', 422);
            }
            if (!is_numeric($body['price']) || (float) $body['price'] < 0) {
                return Json::error($response, 'validation_failed', 'price musí být nezáporné číslo.', 422);
            }
            $price = round((float) $body['price'], 2);
        }
        $saleInvoiceId = null;
        if (isset($body['sale_invoice_id']) && $body['sale_invoice_id'] !== '' && $body['sale_invoice_id'] !== null) {
            if ($type !== 'sold') {
                return Json::error($response, 'validation_failed', 'sale_invoice_id lze zadat jen u prodeje (type=sold).', 422);
            }
            $saleInvoiceId = (int) $body['sale_invoice_id'];
            if ($saleInvoiceId <= 0) {
                return Json::error($response, 'validation_failed', 'sale_invoice_id musí být kladné číslo.', 422);
            }
        }

        // book_entry=false: vyřazení už zaúčtoval deník (převod, ruční zápis): karta se
        // vyřadí bez zaúčtování a naváže na zápis (entry_id, jinak jediný zápis ke dni).
        $bookEntry = array_key_exists('book_entry', $body) ? (bool) $body['book_entry'] : true;
        $entryId = null;
        if (isset($body['entry_id']) && $body['entry_id'] !== '' && $body['entry_id'] !== null) {
            if ($bookEntry) {
                return Json::error($response, 'validation_failed', 'entry_id lze zadat jen u vyřazení bez zaúčtování (book_entry=false).', 422);
            }
            $entryId = (int) $body['entry_id'];
            if ($entryId <= 0) {
                return Json::error($response, 'validation_failed', 'entry_id musí být kladné číslo.', 422);
            }
        }

        try {
            $input = [
                'date'            => $date,
                'type'            => $type,
                'price'           => $price,
                'sale_invoice_id' => $saleInvoiceId,
            ];
            $result = $bookEntry
                ? $this->service->dispose($supplierId, $id, $input, $this->auditMeta($request))
                : $this->service->disposeFromJournal($supplierId, $id, $input + ['entry_id' => $entryId], $this->auditMeta($request));
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (UnbalancedEntryException | PostingException $e) {
            return $this->mapPostingError($response, $e);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Vyřazení majetku selhalo');
        }

        $this->logEvent($request, 'asset.disposed', $id, $bookEntry
            ? ['date' => $date, 'type' => $type]
            : ['date' => $date, 'type' => $type, 'book_entry' => false, 'entry_id' => $result['asset']['disposal_entry_id'] ?? null]);
        return Json::ok($response, $result);
    }

    public function revertDisposal(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        try {
            $result = $this->service->revertDisposal($supplierId, $id, $this->auditMeta($request));
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (UnbalancedEntryException | PostingException $e) {
            return $this->mapPostingError($response, $e);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Vrácení vyřazení majetku selhalo');
        }

        $this->logEvent($request, 'asset.disposal_reverted', $id, []);
        return Json::ok($response, $result);
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    private function serverError(Response $response, \Throwable $e, string $logPrefix): Response
    {
        $this->log->error($logPrefix . ': ' . $e->getMessage(), ['exception' => $e]);
        return Json::error($response, 'operation_failed', 'Operaci se nepodařilo dokončit.', 500);
    }

    private function logEvent(Request $request, string $action, int $entityId, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'asset', $entityId, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
