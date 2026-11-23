<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\InvoiceSettlementService;
use MyInvoice\Service\Accounting\SettlementException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Úhrada faktury zápočtem proti zvolenému účtu (typicky 355/365).
 *
 *   GET    /api/accounting/settlements?doc_type=&doc_id=   — zápočty dokladu + předvolba účtu
 *   POST   /api/accounting/settlements                     — provést zápočet
 *   POST   /api/accounting/settlements/{id}/cancel         — zrušit (storno + odvolání úhrady)
 */
final class InvoiceSettlementAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly InvoiceSettlementService $settlements,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $docType = (string) ($q['doc_type'] ?? '');
        $docId = (int) ($q['doc_id'] ?? 0);
        if ($docId <= 0) {
            return Json::error($response, 'validation_failed', 'doc_id je povinný.', 422);
        }
        try {
            return Json::ok($response, [
                'items'           => $this->settlements->listForDocument($supplierId, $docType, $docId),
                'default_account' => $this->settlements->defaultAccount($supplierId, $docType),
            ]);
        } catch (\Throwable $e) {
            return $this->mapSettlementError($response, $e);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $docType = (string) ($body['doc_type'] ?? '');
        $docId = (int) ($body['doc_id'] ?? 0);
        if ($docId <= 0) {
            return Json::error($response, 'validation_failed', 'doc_id je povinný.', 422);
        }

        try {
            $result = $this->settlements->create($supplierId, $docType, $docId, [
                'settled_on' => (string) ($body['settled_on'] ?? ''),
                'amount'     => $body['amount'] ?? 0,
                'account_id' => (int) ($body['account_id'] ?? 0),
                'note'       => $body['note'] ?? null,
            ], $this->userId($request));
        } catch (\Throwable $e) {
            return $this->mapSettlementError($response, $e);
        }

        $this->log($request, 'accounting.settlement_created', $result['id'], [
            'doc_type'         => $result['doc_type'],
            'doc_id'           => $result['doc_id'],
            'amount'           => $result['amount'],
            'account_code'     => $result['account_code'],
            'journal_entry_id' => $result['journal_entry_id'],
        ], $supplierId);

        return Json::ok($response, $result);
    }

    /**
     * Doúčtuje zápočet, kterému chybí účetní zápis (viz
     * {@see \MyInvoice\Service\Accounting\InvoiceSettlementService::postMissingEntry()}).
     * Akce z detailu dokladu — účetní má před sebou konkrétní doklad se štítkem
     * „Nezaúčtováno" a potřebuje ho zavřít, ne spouštět opravu celé firmy z aktivace.
     */
    public function post(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $user = $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER);
        $userId = is_array($user) && isset($user['id']) ? (int) $user['id'] : null;

        try {
            $result = $this->settlements->postMissingEntry($supplierId, $id, $userId);
        } catch (\Throwable $e) {
            return $this->mapSettlementError($response, $e);
        }

        $this->log($request, 'accounting.settlement_posted', $id, [
            'journal_entry_id' => $result['journal_entry_id'],
        ], $supplierId);
        return Json::ok($response, $result);
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        try {
            $result = $this->settlements->cancel($supplierId, $id, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapSettlementError($response, $e);
        }

        $this->log($request, 'accounting.settlement_cancelled', $id, [
            'reversal_entry_id' => $result['reversal_entry_id'],
        ], $supplierId);
        return Json::ok($response, $result);
    }

    private function mapSettlementError(Response $response, \Throwable $e): Response
    {
        if ($e instanceof SettlementException) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return $this->mapPostingError($response, $e);
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload, int $supplierId): void
    {
        $this->activity->log(
            $action,
            $this->userId($request),
            'invoice_settlement',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}
