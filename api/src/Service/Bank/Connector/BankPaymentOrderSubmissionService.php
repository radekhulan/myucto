<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\PaymentOrderRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Payment\PaymentOrderService;

final class BankPaymentOrderSubmissionService
{
    public function __construct(
        private readonly BankPaymentSubmissionService $submissions,
        private readonly PaymentOrderRepository $orders,
        private readonly PaymentOrderService $paymentOrders,
    ) {}

    public function find(int $supplierId, int $orderId): ?array
    {
        return $this->submissions->find($supplierId, $orderId);
    }

    public function submit(int $supplierId, int $orderId, int $connectionId, ?int $userId): array
    {
        return $this->submissions->submit(
            $supplierId,
            $orderId,
            $connectionId,
            $userId,
            function (array $connection) use ($supplierId, $orderId): string {
                $order = $this->orders->find($orderId, $supplierId);
                if ($order === null) {
                    throw new BankConnectorOperationException('payment_order_not_found');
                }
                $this->assertOrder($order, $connection);
                $file = $this->paymentOrders->download($orderId, $supplierId, 'abo');
                if ($file === null) {
                    throw new BankConnectorOperationException('payment_order_not_found');
                }
                return (string) $file['bytes'];
            },
        );
    }

    private function assertOrder(array $order, array $connection): void
    {
        if (
            strtoupper((string) $order['currency']) !== 'CZK'
            || (int) ($order['payer_currency_id'] ?? 0) !== (int) $connection['currency_id']
            || (string) ($order['payer_bank_code'] ?? '') !== (string) ($connection['verified_bank_code'] ?? '')
            || !AccountNumberNormalizer::equalsCzech(
                (string) ($order['payer_account_number'] ?? ''),
                (string) ($connection['verified_account_number'] ?? ''),
            )
        ) {
            throw new BankConnectorOperationException('payment_order_account_mismatch');
        }
        if ((bool) ($order['mark_paid'] ?? false)) {
            throw new BankConnectorOperationException('payment_order_mark_paid_forbidden');
        }
        if ((string) ($order['payment_date'] ?? '') < date('Y-m-d')) {
            throw new BankConnectorOperationException('payment_order_date_expired');
        }
        if (!$this->orders->allItemsStillPayable((int) $order['id'], (int) $order['supplier_id'], 'CZK')) {
            throw new BankConnectorOperationException('payment_order_items_not_payable');
        }
    }

}
