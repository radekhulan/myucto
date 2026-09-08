<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\Connector\BankConnectionService;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\StatementReconciliationConfirmation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BankConnectionAction
{
    private const PERMISSION = 'settings.bank_accounts';

    public function __construct(private readonly BankConnectionService $service,
        private readonly \Psr\Log\LoggerInterface $diagnostics,
        private readonly ActivityLogger $activity) {}

    public function list(Request $request, Response $response): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::READ)) {
            return $denied;
        }
        return Json::ok($response, $this->service->overview(SupplierGuard::currentId($request)));
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::WRITE)) {
            return $denied;
        }
        $currencyId = (int) ($args['currencyId'] ?? 0);
        if ($currencyId <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatný bankovní účet.', 422);
        }
        try {
            $connection = $this->service->configure(
                SupplierGuard::currentId($request),
                $currencyId,
                (array) ($request->getParsedBody() ?? []),
            );
            return Json::ok($response, $connection);
        } catch (BankConnectorOperationException $e) {
            return $this->operationError($response, $e);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::WRITE)) {
            return $denied;
        }
        $currencyId = (int) ($args['currencyId'] ?? 0);
        if ($currencyId <= 0) {
            return Json::error($response, 'not_found', 'Bankovní spojení nebylo nalezeno.', 404);
        }
        try {
            if (!$this->service->disconnect(SupplierGuard::currentId($request), $currencyId)) {
                return Json::error($response, 'not_found', 'Bankovní spojení nebylo nalezeno.', 404);
            }
        } catch (BankConnectorOperationException $e) {
            return $this->operationError($response, $e);
        }
        return $response->withStatus(204);
    }

    public function sync(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::WRITE)) {
            return $denied;
        }
        $currencyId = (int) ($args['currencyId'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $confirmations = StatementReconciliationConfirmation::parse(
                $body['reconciliation_confirmations'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', 'Neplatná potvrzení shod výpisu.', 422);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $supplierId = SupplierGuard::currentId($request);
        try {
            $result = $this->service->sync(
                $supplierId,
                $currencyId,
                isset($body['from']) ? (string) $body['from'] : null,
                isset($body['to']) ? (string) $body['to'] : null,
                (int) ($user['id'] ?? 0) ?: null,
                $confirmations,
            );
            if ($confirmations !== []) {
                $statementId = (int) ($result['imported_statement_id'] ?? 0);
                $this->activity->log(
                    'bank.statement_reconciliation_confirmed',
                    (int) ($user['id'] ?? 0) ?: null,
                    'bank_statement',
                    $statementId > 0 ? $statementId : null,
                    ['confirmation_count' => count($confirmations)],
                    null,
                    $request->getHeaderLine('User-Agent'),
                    $supplierId,
                );
            }
            return Json::ok($response, $result);
        } catch (BankConnectorOperationException $e) {
            return $this->operationError($response, $e);
        }
    }

    private function denied(Request $request, Response $response, AccessLevel $minimum): ?Response
    {
        return RequestAuthorization::allows($request, self::PERMISSION, $minimum)
            ? null
            : Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
    }

    private function operationError(Response $response, BankConnectorOperationException $e): Response
    {
        $this->diagnostics->warning('bank_connection_operation_failed', ['code' => $e->errorCode]);
        $status = match ($e->errorCode) {
            'connection_not_found' => 404,
            'bank_connection_busy', 'bank_rate_limited', 'history_gap',
            'statement_reconciliation_required' => 409,
            'provider_required', 'provider_not_implemented', 'provider_account_mismatch',
            'token_required', 'token_invalid', 'account_inactive', 'account_currency_missing',
            'statement_account_mismatch', 'account_changed_revalidation_required',
            'period_incomplete', 'period_invalid' => 422,
            default => 502,
        };
        $message = $e->errorCode === 'statement_reconciliation_required'
            ? 'Synchronizace vyžaduje potvrzení shod s dříve načtenými bankovními pohyby.'
            : ($e->errorCode === 'account_inactive'
                ? 'Bankovní účet je v MyÚčto neaktivní. V nastavení Měny a účty zapněte Aktivní účet a ověření zopakujte.'
                : 'Bankovní operaci se nepodařilo bezpečně dokončit (' . $e->errorCode . ').');
        return Json::error(
            $response,
            $e->errorCode,
            $message,
            $status,
            $e->details,
        );
    }
}
