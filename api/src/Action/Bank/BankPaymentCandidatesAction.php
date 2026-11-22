<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Bank\BankMatchSearch;
use MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as Psr7Response;

final class BankPaymentCandidatesAction
{
    private const BATCH_LIMIT = 100;
    private const BATCH_SAVEPOINT = 'bank_document_batch';

    public function __construct(
        private readonly Connection $db,
        private readonly BankMatchSearch $search,
        private readonly BankStatementAction $bank,
        private readonly DocumentLockService $locks,
    ) {}

    private function target(Request $request, array $input, bool $lock = false): array
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
        $document = $this->search->document(SupplierGuard::currentId($request), $type, max($invoiceId, $purchaseId), $lock);
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
        $search = is_string($query['search'] ?? null) ? mb_substr(trim($query['search']), 0, 100) : '';
        return Json::ok($response, $this->search->payments(
            SupplierGuard::currentId($request), $type, $document, $search, max(1, (int) ($query['page'] ?? 1)),
            batchOnly: ($query['batch'] ?? '') === '1',
        ));
    }

    public function batch(Request $request, Response $response): Response
    {
        if (RequestAuthorization::isClientType($request) || !RequestAuthorization::allows($request, 'bank.match', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění pro párování banky.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = $body['transaction_ids'] ?? null;
        $version = $body['confirm_version'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) < 2 || count($ids) > self::BATCH_LIMIT
            || array_filter($ids, static fn (mixed $id): bool => !is_int($id) || $id <= 0) !== []
            || count($ids) !== count(array_unique($ids))
            || ($version !== null && (!is_string($version) || preg_match('/^[a-f0-9]{64}$/D', $version) !== 1))) {
            return Json::error($response, 'validation_failed', 'Vyberte 2 až 100 různých bankovních pohybů a zkontrolujte náhled.', 422);
        }

        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($version !== null) {
            if ($own) $pdo->beginTransaction();
            else $pdo->exec('SAVEPOINT ' . self::BATCH_SAVEPOINT);
        }
        try {
            if ($version !== null) (new PaymentMutationContext($this->db))->acquire($supplierId);
            [$type, $document] = $this->target($request, $body, $version !== null);
            $rows = $this->search->selectedStatementPayments($supplierId, $ids, $version !== null);
            $plan = $this->batchPlan($supplierId, $type, $document, $rows, count($ids));
            if ($version === null) return Json::ok($response, $plan);

            if ($plan['blocked'] !== null || !hash_equals($plan['version'], $version)) {
                throw new \DomainException('Výběr nebo zůstatek faktury se změnil. Načtěte nový náhled.', 409);
            }
            $target = [$type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id' => (int) $document['id']];
            $results = [];
            foreach ($plan['items'] as $row) {
                $txId = $row['id'];
                $result = $this->bank->manualMatch($request->withParsedBody($target), new Psr7Response(), ['id' => (string) $txId]);
                $payload = json_decode((string) $result->getBody(), true, flags: JSON_THROW_ON_ERROR);
                if ($result->getStatusCode() >= 400 || ($payload['matched'] ?? false) !== true) {
                    $this->rollbackBatch($own);
                    if ($result->getStatusCode() < 400 || $result->getStatusCode() >= 500) {
                        return Json::error($response, 'batch_match_failed', 'Pohyby se nepodařilo spárovat.', 500, ['transaction_id' => $txId]);
                    }
                    return Json::error($response, $payload['error']['code'] ?? 'batch_match_failed',
                        $payload['error']['message'] ?? 'Pohyby se nepodařilo spárovat.', $result->getStatusCode(), ['transaction_id' => $txId]);
                }
                $results[] = ['id' => $txId, 'posting' => $payload['posting'] ?? null];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $paymentSource = $type === 'invoice' ? 'invoice_payments' : 'payment_matches';
            $documentKey = $type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
            $payments = $pdo->prepare("SELECT COUNT(DISTINCT bank_transaction_id) AS matched, SUM(amount) AS amount FROM $paymentSource
                WHERE supplier_id = ? AND $documentKey = ? AND bank_transaction_id IN ($placeholders)");
            $payments->execute([$supplierId, $document['id'], ...$ids]);
            $paid = $payments->fetch(\PDO::FETCH_ASSOC);
            if ((int) $paid['matched'] !== count($ids) || (int) round((float) $paid['amount'] * 100) !== $plan['selected_cents']) {
                throw new \DomainException('Výběr nebo zůstatek faktury se změnil. Načtěte nový náhled.', 409);
            }
            if ($own) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT ' . self::BATCH_SAVEPOINT);
            return Json::ok($response, ['items' => $results]);
        } catch (\Throwable $exception) {
            if ($version !== null) $this->rollbackBatch($own, $exception);
            if ($exception instanceof \DomainException) {
                return Json::error($response, 'payment_unavailable', $exception->getMessage(), $exception->getCode());
            }
            if ($own && TransactionFailure::lockConflict($exception)) {
                return Json::error($response, 'payment_unavailable', 'Výběr nebo zůstatek faktury se změnil. Načtěte nový náhled.', 409);
            }
            throw $exception;
        }
    }

    private function rollbackBatch(bool $own, ?\Throwable $failure = null): void
    {
        $pdo = $this->db->pdo();
        if ($failure !== null && TransactionFailure::rollbackIfAborted($pdo, $failure)) return;
        if (!$pdo->inTransaction()) return;
        if ($own) {
            $pdo->rollBack();
        } else {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::BATCH_SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT ' . self::BATCH_SAVEPOINT);
        }
    }

    private function batchPlan(int $supplierId, string $type, array $document, array $rows, int $count): array
    {
        $total = (int) round((float) $document['amount_to_pay'] * 100);
        $remaining = (int) round((float) $document['remaining'] * 100);
        if ($total <= 0 || $remaining <= 0 || $document['status'] === 'paid') {
            throw new \DomainException('Hromadné párování vyžaduje fakturu s kladným zůstatkem k úhradě.', 409);
        }
        if (count($rows) !== $count) {
            throw new \DomainException('Některý pohyb už není volný nebo nepatří k bankovnímu výpisu této firmy.', 409);
        }
        $currency = strtoupper((string) $document['currency']);
        $wrongCurrency = false;
        $selected = 0;
        foreach ($rows as &$row) {
            $amount = (int) round((float) $row['amount'] * 100);
            if ($amount === 0 || ($amount > 0) !== ($type === 'invoice')) {
                throw new \DomainException('Vybrané pohyby musí mít směr úhrady faktury.', 422);
            }
            $row['id'] = (int) $row['id'];
            $row['statement_id'] = (int) $row['statement_id'];
            $row['amount'] = (float) $row['amount'];
            $wrongCurrency = $wrongCurrency || $row['currency'] !== $currency;
            $selected += abs($amount);
        }
        unset($row);
        $plan = [
            'currency' => $currency,
            'paid_cents' => $total - $remaining,
            'remaining_cents' => $remaining,
            'selected_cents' => $wrongCurrency ? null : $selected,
            'blocked' => $wrongCurrency ? 'currency' : ($selected > $remaining ? 'overpayment' : null),
            'items' => $rows,
        ];
        return $plan + ['version' => hash('sha256', json_encode(
            [$supplierId, $type, (int) $document['id'], $plan], JSON_THROW_ON_ERROR,
        ))];
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
