<?php

declare(strict_types=1);

namespace MyInvoice\Action\Ai;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Ai\AiKillSwitchService;
use MyInvoice\Service\Ai\AiSuggestionRepository;
use MyInvoice\Service\Ai\AiSuggestionService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AiSuggestionAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly AiSuggestionRepository $suggestions,
        private readonly AiSuggestionService $service,
        private readonly AiKillSwitchService $killSwitch,
    ) {}

    public function accept(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění potvrdit návrh.', 403);
        }
        $supplierId = $this->supplierId($request);
        $id = (int) $args['id'];
        $row = $this->suggestions->find($supplierId, $id);
        if ($row === null) return Json::error($response, 'not_found', 'Návrh nebyl nalezen.', 404);
        if ($row['status'] !== 'pending') return Json::error($response, 'not_pending', 'Návrh už byl vyřízen.', 409);
        $body = (array) ($request->getParsedBody() ?? []);
        $override = is_array($body['override'] ?? null) ? $body['override'] : [];
        $payload = (array) $row['payload'];
        foreach (['debit_account_code', 'credit_account_code', 'expense_category_id'] as $key) {
            if (array_key_exists($key, $override)) $payload[$key] = $override[$key];
        }
        if (!$this->validPayload($supplierId, $payload)) {
            return Json::error($response, 'invalid_override', 'Navrženou kontaci nelze použít.', 422);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if ($row['entity_type'] === 'purchase_invoice') {
                $document = $pdo->prepare('SELECT status FROM purchase_invoices WHERE supplier_id=? AND id=? FOR UPDATE');
                $document->execute([$supplierId, (int) $row['entity_id']]);
                if ($document->fetchColumn() !== 'draft') {
                    $pdo->rollBack();
                    return Json::error($response, 'document_locked', 'Návrh lze použít jen na rozpracovaný doklad.', 409);
                }
                $currentHash = $this->service->purchaseInputHash($supplierId, (int) $row['entity_id']);
                if (!is_string($row['input_hash'] ?? null) || $currentHash === null
                    || !hash_equals($row['input_hash'], $currentHash)) {
                    $pdo->rollBack();
                    try {
                        $this->service->invalidatePurchase($supplierId, (int) $row['entity_id']);
                        $this->service->enqueuePurchase($supplierId, (int) $row['entity_id']);
                    } catch (\Throwable) {
                    }
                    return Json::error($response, 'document_changed', 'Doklad se od vytvoření návrhu změnil. Nechte návrh přepočítat.', 409);
                }
            }
            if (!$this->suggestions->accept($supplierId, $id, $userId, $payload)) {
                $pdo->rollBack();
                return Json::error($response, 'not_pending', 'Návrh už byl vyřízen.', 409);
            }
            if ($row['entity_type'] === 'purchase_invoice' && isset($payload['expense_category_id'])) {
                $pdo->prepare('UPDATE purchase_invoices SET expense_category_id=? WHERE supplier_id=? AND id=?')
                    ->execute([$payload['expense_category_id'], $supplierId, (int) $row['entity_id']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        try {
            $overridden = $override !== [];
            $this->service->metric($supplierId, (string) $row['source'], $overridden ? 'overridden_count' : 'accepted_count');
            $this->killSwitch->evaluate($supplierId, (string) $row['source']);
        } catch (\Throwable) {
        }
        return Json::ok($response, ['status' => 'accepted', 'applied' => $payload]);
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění odmítnout návrh.', 403);
        }
        $supplierId = $this->supplierId($request);
        $row = $this->suggestions->find($supplierId, (int) $args['id']);
        if ($row === null) return Json::error($response, 'not_found', 'Návrh nebyl nalezen.', 404);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!$this->suggestions->reject($supplierId, (int) $args['id'], (int) ($user['id'] ?? 0))) {
            return Json::error($response, 'not_pending', 'Návrh už byl vyřízen.', 409);
        }
        try {
            $this->service->metric($supplierId, (string) $row['source'], 'rejected_count');
            $this->killSwitch->evaluate($supplierId, (string) $row['source']);
        } catch (\Throwable) {
        }
        return Json::ok($response, ['status' => 'rejected']);
    }

    /** @param array<string,mixed> $payload */
    private function validPayload(int $supplierId, array $payload): bool
    {
        $debit = trim((string) ($payload['debit_account_code'] ?? ''));
        if ($debit === '' || \MyInvoice\Service\Accounting\SettlementAccounts::isSettlement($debit)) return false;
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM chart_of_accounts WHERE supplier_id=? AND account_code=? AND is_active=1');
        $stmt->execute([$supplierId, $debit]);
        if ($stmt->fetchColumn() === false) return false;
        if (array_key_exists('credit_account_code', $payload)) {
            $credit = trim((string) $payload['credit_account_code']);
            if ($credit === '' || \MyInvoice\Service\Accounting\SettlementAccounts::isSettlement($credit)) return false;
            $stmt->execute([$supplierId, $credit]);
            if ($stmt->fetchColumn() === false) return false;
        }
        if (isset($payload['expense_category_id'])) {
            $cat = $this->db->pdo()->prepare('SELECT 1 FROM expense_categories WHERE supplier_id=? AND id=? AND archived=0');
            $cat->execute([$supplierId, (int) $payload['expense_category_id']]);
            if ($cat->fetchColumn() === false) return false;
        }
        return true;
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }
}
