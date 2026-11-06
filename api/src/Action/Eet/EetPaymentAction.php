<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Eet\PaymentEventService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přijetí platby s evidencí EET a QR platba na místě.
 *
 *   POST /api/eet/payments/preview                       náhled politiky bez zápisu
 *   POST /api/eet/payments                               přijetí platby (Idempotency-Key)
 *   GET  /api/eet/payments/{publicId}                    detail události s oddělenými stavy
 *   POST /api/eet/payment-intents                        QR záměr + QR data (není inkaso ani tržba)
 *   POST /api/eet/payment-intents/{publicId}/confirm     „Zákazník zadal platbu" (Idempotency-Key)
 *   POST /api/eet/payment-intents/{publicId}/cancel      zrušení neprovedeného záměru
 *
 * Firma se bere ze scope požadavku; doklad i zařízení z těla ověřuje příkaz proti
 * firmě (EetPaymentCommand::invoice / EetRecordFactory::lockDevice).
 */
final class EetPaymentAction
{
    use RespondsWithEetErrors;

    public function __construct(
        private readonly EetPaymentCommand $command,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return $this->respond($response, fn (): array => $this->command->preview($supplierId, self::body($request)));
    }

    public function accept(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $userId = self::userId($request);
        return $this->respond($response, function () use ($request, $supplierId, $userId): array {
            $key = PaymentEventService::validateKey($request->getHeaderLine('Idempotency-Key'));
            $result = $this->command->accept($supplierId, $userId, $key, self::body($request));
            if (!$result['idempotent_replay']) {
                $this->audit($request, 'eet.payment_accepted', $supplierId, $userId, $result);
            }
            return $result;
        });
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return $this->respond($response, fn (): array => $this->command->detail($supplierId, (string) ($args['publicId'] ?? '')));
    }

    public function createIntent(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $userId = self::userId($request);
        return $this->respond($response, function () use ($request, $supplierId, $userId): array {
            $key = $request->getHeaderLine('Idempotency-Key');
            $result = $this->command->createIntent($supplierId, $userId, $key !== '' ? $key : null, self::body($request));
            if (!$result['idempotent_replay']) {
                $this->audit($request, 'eet.payment_intent_created', $supplierId, $userId, $result);
            }
            return $result;
        }, 201);
    }

    public function confirmIntent(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $userId = self::userId($request);
        return $this->respond($response, function () use ($request, $args, $supplierId, $userId): array {
            $key = PaymentEventService::validateKey($request->getHeaderLine('Idempotency-Key'));
            $result = $this->command->confirmIntent($supplierId, $userId, (string) ($args['publicId'] ?? ''), $key, self::body($request));
            if (!$result['idempotent_replay']) {
                $this->audit($request, 'eet.payment_intent_confirmed', $supplierId, $userId, $result);
            }
            return $result;
        });
    }

    public function cancelIntent(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $userId = self::userId($request);
        return $this->respond($response, function () use ($request, $args, $supplierId, $userId): array {
            $result = $this->command->cancelIntent($supplierId, $userId, (string) ($args['publicId'] ?? ''));
            $this->audit($request, 'eet.payment_intent_cancelled', $supplierId, $userId, $result);
            return $result;
        });
    }

    /** @param array<string,mixed> $result */
    private function audit(Request $request, string $action, int $supplierId, int $userId, array $result): void
    {
        $this->logger->log($action, $userId > 0 ? $userId : null, 'payment_event', null, [
            'payment_event_id' => $result['payment_event_id'] ?? null,
            'amount' => $result['amount'] ?? null,
            'invoice_id' => $result['invoice_id'] ?? null,
            'eet_decision' => $result['eet_decision']['decision'] ?? null,
            'eet_record_id' => $result['eet']['record_id'] ?? null,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);
    }
}
