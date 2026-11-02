<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Bank\BankMatchSearch;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BankPaymentCandidatesAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly BankMatchSearch $search,
        private readonly BankStatementAction $bank,
        private readonly DocumentLockService $locks,
    ) {}

    private function target(Request $request, array $input): array
    {
        $invoiceId = (int) ($input['invoice_id'] ?? 0);
        $purchaseId = (int) ($input['purchase_invoice_id'] ?? 0);
        if (($invoiceId > 0) === ($purchaseId > 0)) throw new \DomainException('Vyberte právě jeden doklad.', 400);
        $type = $invoiceId > 0 ? 'invoice' : 'purchase_invoice';
        $permission = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        if (!RequestAuthorization::allows($request, 'bank', AccessLevel::READ)
            || !RequestAuthorization::allows($request, $permission, AccessLevel::READ)) {
            throw new \DomainException('Chybí oprávnění pro čtení banky nebo dokladu.', 403);
        }
        $document = $this->search->document(SupplierGuard::currentId($request), $type, max($invoiceId, $purchaseId));
        if ($document === null) throw new \DomainException('Doklad ke spárování nebyl nalezen.', 404);
        return [$type, $document];
    }

    public function list(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        try {
            [$type, $document] = $this->target($request, $query);
        } catch (\DomainException $exception) {
            return Json::error($response, 'payment_candidates_unavailable', $exception->getMessage(), $exception->getCode());
        }
        $preferred = null;
        if (array_key_exists('preferred_transaction_id', $query)) {
            $value = filter_var($query['preferred_transaction_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value === false) return Json::error($response, 'invalid_preferred_transaction', 'Doporučený pohyb musí mít kladné celočíselné ID.', 400);
            $preferred = $value;
        }
        $search = is_string($query['search'] ?? null) ? mb_substr(trim($query['search']), 0, 100) : '';
        return Json::ok($response, $this->search->payments(
            SupplierGuard::currentId($request), $type, $document, $search, max(1, (int) ($query['page'] ?? 1)), null, $preferred,
        ));
    }

    public function match(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'bank.match', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění pro párování banky.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            [$type, $document] = $this->target($request, $body);
        } catch (\DomainException $exception) {
            return Json::error($response, 'payment_candidates_unavailable', $exception->getMessage(), $exception->getCode());
        }
        $supplierId = SupplierGuard::currentId($request);
        $transactionId = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT bank_document_match');
        try {
            if ((new \MyInvoice\Service\Bank\BankTransactionContext($this->db))->lock($supplierId, $transactionId) === null) {
                throw new \DomainException('Bankovní pohyb nebyl nalezen.', 404);
            }
            $document = $this->search->document($supplierId, $type, (int) $document['id'], true);
            if ($document === null) throw new \DomainException('Stav dokladu se mezitím změnil.', 409);
            if (RequestAuthorization::isClientType($request)) {
                $documentLock = $type === 'invoice' ? $this->locks->forInvoice($document) : $this->locks->forPurchaseInvoice($document);
                if ($documentLock->lockedForClient()) throw new \DomainException('Doklad je uzamčený účetními pravidly.', 403);
            }
            if ($this->search->payments($supplierId, $type, $document, '', 1, $transactionId)['items'] === []) {
                throw new \DomainException('Bankovní pohyb již není volnou úhradou tohoto dokladu.', 409);
            }
            if ($type === 'purchase_invoice') {
                $proposal = $pdo->prepare("SELECT 1 FROM bank_match_audit a JOIN bank_match_suggestions s ON s.id = a.suggestion_id
                    WHERE a.supplier_id = ? AND a.purchase_invoice_id = ? AND s.bank_transaction_id = ? AND s.status = 'pending'
                    AND JSON_SEARCH(s.candidates_json, 'one', 'purchase_ready', NULL, '$[*].origin') IS NOT NULL LIMIT 1");
                $proposal->execute([$supplierId, (int) $document['id'], $transactionId]);
                if ($proposal->fetchColumn() !== false) {
                    $tx = (new \MyInvoice\Service\Bank\BankTransactionContext($this->db))->find($transactionId, true);
                    if ((new \MyInvoice\Service\Bank\PurchaseBankMatchingPeriodGuard($this->db))->datesLocked($supplierId,
                        [$document['issue_date'], $document['effective_cost_date'] ?? $document['issue_date'], substr((string) $tx['posted_at'], 0, 10)])) {
                        throw new \DomainException('Období dokladu nebo platby je uzamčené.', 409);
                    }
                }
            }
            $input = [$type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id' => (int) $document['id']];
            if (!empty($body['force_advance_final'])) {
                $input['force_advance_final'] = true;
            }
            $result = $this->bank->manualMatch($request->withParsedBody($input), $response, $args);
            if ($result->getStatusCode() >= 400) {
                if ($own) $pdo->rollBack();
                else { $pdo->exec('ROLLBACK TO SAVEPOINT bank_document_match'); $pdo->exec('RELEASE SAVEPOINT bank_document_match'); }
                return $result;
            }
            if ($type === 'purchase_invoice') {
                // Ruční potvrzení vyřídí návrh; úhradu i účetní zpracování výše
                // stále provádí původní manualMatch.
                $pdo->prepare("UPDATE bank_match_suggestions SET status = 'superseded', updated_at = NOW()
                    WHERE supplier_id = ? AND bank_transaction_id = ? AND status = 'pending'")
                    ->execute([$supplierId, $transactionId]);
            }
            if ($own) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT bank_document_match');
            return $result;
        } catch (\Throwable $exception) {
            if (\MyInvoice\Infrastructure\Database\TransactionFailure::rollbackIfAborted($pdo, $exception)) {
                throw $exception;
            }
            if ($pdo->inTransaction()) {
                if ($own) $pdo->rollBack();
                else { $pdo->exec('ROLLBACK TO SAVEPOINT bank_document_match'); $pdo->exec('RELEASE SAVEPOINT bank_document_match'); }
            }
            if ($exception instanceof \DomainException) {
                return Json::error($response, 'payment_unavailable', $exception->getMessage(), $exception->getCode());
            }
            throw $exception;
        }
    }
}
