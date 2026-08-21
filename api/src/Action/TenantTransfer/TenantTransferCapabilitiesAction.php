<?php

declare(strict_types=1);

namespace MyInvoice\Action\TenantTransfer;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\TenantTransferGrantMiddleware;
use MyInvoice\Service\TenantTransfer\Capabilities\TenantTransferCapabilitiesService;
use MyInvoice\Service\TenantTransfer\Capabilities\TenantTransferCapabilitiesUnavailable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TenantTransferCapabilitiesAction
{
    public function __construct(
        private readonly Config $config,
        private readonly TenantTransferCapabilitiesService $capabilities,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if ($this->config->get('tenant_transfer.enabled', false) !== true) {
            return Json::error(
                $response,
                'transfer_api_disabled',
                'Přenos firem není na této instanci zapnutý.',
                404,
            );
        }
        $grant = $request->getAttribute(TenantTransferGrantMiddleware::ATTR_GRANT);
        if (!is_array($grant) || self::positiveInt($grant['supplier_id'] ?? null) < 1) {
            return Json::error(
                $response,
                'transfer_authorization_required',
                'Chybí platné transfer oprávnění.',
                401,
            );
        }

        try {
            return Json::ok($response, $this->capabilities->current()->toArray());
        } catch (TenantTransferCapabilitiesUnavailable $exception) {
            return Json::error(
                $response,
                'source_not_ready',
                'Zdroj zatím není připravený na bezpečný přenos firmy.',
                503,
                ['reason' => $exception->reason],
            );
        } catch (\Throwable) {
            return Json::error(
                $response,
                'capabilities_unavailable',
                'Capability metadata instance nyní nelze bezpečně sestavit.',
                503,
            );
        }
    }

    private static function positiveInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int) $value;
        }
        return 0;
    }
}
