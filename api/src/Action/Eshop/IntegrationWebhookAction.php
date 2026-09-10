<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Http\Json;
use MyInvoice\Service\Integration\IntegrationWebhookService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class IntegrationWebhookAction
{
    private const MAX_PAYLOAD_BYTES = 1048576;

    public function __construct(private readonly IntegrationWebhookService $webhooks) {}

    public function receive(Request $request, Response $response, array $args): Response
    {
        $body = (string) $request->getBody();
        if (strlen($body) > self::MAX_PAYLOAD_BYTES) {
            return Json::error($response, 'payload_too_large', 'Webhook payload je příliš velký.', 413);
        }
        try {
            $result = $this->webhooks->receive((string) $args['uuid'],
                $request->getHeaderLine('X-Integration-Timestamp'),
                $request->getHeaderLine('X-Integration-Signature'), $body);
        } catch (\JsonException|\InvalidArgumentException) {
            return Json::error($response, 'webhook_invalid', 'Webhook payload není platný.', 400);
        } catch (\Throwable) {
            return Json::error($response, 'webhook_unauthorized', 'Webhook nelze ověřit.', 401);
        }
        return Json::ok($response, $result, 202);
    }
}
