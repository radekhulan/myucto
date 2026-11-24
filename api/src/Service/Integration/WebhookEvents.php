<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class WebhookEvents
{
    public const PURCHASE_RECEIVED = 'purchase_invoice.received';
    public const PURCHASE_APPROVED = 'purchase_invoice.approved';
    public const PURCHASE_CANCELLED = 'purchase_invoice.cancelled';
    public const STOCK_POSTED = 'stock_document.posted';
    public const STOCK_REVERSED = 'stock_document.reversed';
    public const SALES_ORDER_CONFIRMED = 'sales_order.confirmed';

    public const TYPES = [
        ...InvoiceWebhookEvents::TYPES,
        self::PURCHASE_RECEIVED,
        self::PURCHASE_APPROVED,
        self::PURCHASE_CANCELLED,
        self::STOCK_POSTED,
        self::STOCK_REVERSED,
        self::SALES_ORDER_CONFIRMED,
    ];

    public static function enqueue(
        PDO $pdo,
        int $supplierId,
        string $type,
        array $data,
        ?int $templateId = null,
        bool $requiresAdvanceOptIn = false,
    ): void {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Webhook vyžaduje doménovou transakci.');
        }
        $eventId = self::eventId();
        $body = [
            'type' => $type,
            'version' => 1,
            'event_id' => $eventId,
            'occurred_at' => new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'supplier_id' => $supplierId,
        ] + $data;
        $pdo->prepare(
            "INSERT INTO webhook_deliveries (supplier_id, endpoint_id, event_id, event_type, body_json)
             SELECT supplier_id, id, ?, ?, ? FROM webhook_endpoints
              WHERE supplier_id = ? AND enabled = 1 AND (template_id IS NULL OR template_id = ?)
                AND JSON_CONTAINS(event_types, JSON_QUOTE(?)) AND (? = 0 OR include_advance = 1)"
        )->execute([$eventId, $type, json_encode($body, JSON_THROW_ON_ERROR), $supplierId, $templateId, $type, (int) $requiresAdvanceOptIn]);
    }

    public static function eventId(): string
    {
        return 'evt_' . bin2hex(random_bytes(16));
    }
}
