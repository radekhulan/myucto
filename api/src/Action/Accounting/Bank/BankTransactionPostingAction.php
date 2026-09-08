<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Bank;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ruční zaúčtování / storno bankovní transakce — REST API (mini-epic
 * AUTOMATIZACE, §5). POST /api/bank-transactions/{id}/post|unpost.
 */
final class BankTransactionPostingAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly BankPostingService $service,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', \MyInvoice\Security\AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        try {
            return Json::ok($response, $this->service->previewTransaction($supplierId, (int) $args['id']));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    public function post(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $txId = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);

        $input = [
            'debit_account_code'  => trim((string) ($body['debit_account_code'] ?? '')),
            'credit_account_code' => trim((string) ($body['credit_account_code'] ?? '')),
            'description'         => isset($body['description']) ? (string) $body['description'] : null,
        ];
        // Rozúčtování na víc řádků; tvar řádků validuje až service (manualLines).
        if (isset($body['lines']) && is_array($body['lines']) && $body['lines'] !== []) {
            $input['lines'] = array_values(array_filter($body['lines'], 'is_array'));
        }
        if (isset($body['create_rule']) && is_array($body['create_rule'])) {
            $input['create_rule'] = $body['create_rule'];
        }

        try {
            $res = $this->service->postManual($supplierId, $txId, $input, $this->auditMeta($request));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'bank.tx_posted', $txId, ['entry_id' => $res['entry_id'], 'rule_id' => $res['rule_id']]);

        $out = [
            'journal_entry_id' => $res['entry_id'],
            'document_no'      => $this->documentNo($supplierId, (int) $res['entry_id']),
            'rule_id'          => $res['rule_id'],
        ];
        if ($res['rule_hint'] !== null) {
            $out['similar'] = [
                'count'         => (int) ($res['rule_hint']['previous_count'] ?? 0),
                'period_months' => 13,
                'last_seen'     => null,
            ];
        }
        return Json::ok($response, $out);
    }

    public function unpost(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $txId = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        $meta = $this->auditMeta($request);
        if (isset($body['note']) && trim((string) $body['note']) !== '') {
            $meta['description'] = mb_substr(trim((string) $body['note']), 0, 255);
        }
        try {
            $reversalId = $this->service->unpost($supplierId, $txId, $meta);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'bank.tx_unposted', $txId, ['reversal_entry_id' => $reversalId]);
        return Json::ok($response, ['reversed' => true, 'reversal_entry_id' => $reversalId]);
    }

    private function documentNo(int $supplierId, int $entryId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT document_no FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$entryId, $supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'bank_transaction', $id, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
