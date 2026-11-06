<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Repository\OtherItemSettingsRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Nastavení ostatních pohledávek a závazků (migrace 2249).
 *
 *   GET /api/accounting/other-items/settings  — { document_folder, document_folder_default, is_default }
 *   PUT /api/accounting/other-items/settings  — { document_folder } (prázdné = výchozí)
 */
final class OtherItemSettingsAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly OtherItemSettingsRepository $settings,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return Json::ok($response, $this->settings->get($this->currentSupplierId($request)));
    }

    public function update(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $supplierId = $this->currentSupplierId($request);
        try {
            $before = $this->settings->get($supplierId);
            $after = $this->settings->save($supplierId, isset($body['document_folder']) ? (string) $body['document_folder'] : null, $this->userId($request));
        } catch (OtherItemException $e) {
            return Json::error($response, 'other_items.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if ($before['document_folder'] !== $after['document_folder']) {
            $this->logger->log('other_item_settings.updated', $this->userId($request), 'supplier', $supplierId,
                ['document_folder' => ['from' => $before['document_folder'], 'to' => $after['document_folder']]],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'), $supplierId);
        }
        return Json::ok($response, $after);
    }
}
