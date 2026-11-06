<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\ProjectAssignmentAccess;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/{id}/project — zařazení dokladu k zakázce (issue #29).
 *
 * Body: { project_id: number|null }
 *
 * ## Proč to není součást PUT /api/purchase-invoices/{id}
 *
 * Zařazení k akci přichází typicky AŽ po zaúčtování: účetní došlou fakturu zaúčtuje,
 * projektový vedoucí ji pak přiřadí k akci. Plný PUT je u zaúčtovaného dokladu
 * dostupný jen adminovi s `?force=1` (§35 — doklad × deník se nesmí rozejít), takže
 * přes něj by ekonomika akcí v praxi nikdy nevznikla.
 *
 * Zakázka se promítá do dimenzovaných závěrkových a počátečních zůstatků.
 * V uzavřeném období se proto změna odmítne; zámek data otevřeného období ji dovoluje.
 * Stornovaný doklad se k akci nepřiřazuje.
 *
 * Přeřazení po zaúčtování je práce účetní a projektového vedoucího, ne klienta:
 * bez `projects` se zakázka nepřiřadí vůbec a klientská role zamčený doklad
 * nepřeřadí ({@see ProjectAssignmentAccess}).
 */
final class SetPurchaseInvoiceProjectAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PostingService $posting,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        if ($deny = ProjectAssignmentAccess::denyWithoutProjects($request, $response)) {
            return $deny;
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if (($existing['status'] ?? '') === 'cancelled') {
            return Json::error($response, 'not_editable', 'Stornovaný doklad nelze zařadit k zakázce.', 409);
        }
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forPurchaseInvoice($existing), 'purchase_invoice', $id, true)) {
            return $deny;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        // Jednoúčelový endpoint: chybějící klíč není „odeber zakázku" (to je explicitní null).
        if (!array_key_exists('project_id', $body)) {
            return Json::error($response, 'validation_failed', 'Chybí project_id (null zakázku odebere).', 400, [
                'fields' => ['project_id' => ['Povinný klíč; null zakázku odebere.']],
            ]);
        }
        $projectId = ($body['project_id'] ?? null) !== null && (int) $body['project_id'] > 0
            ? (int) $body['project_id']
            : null;

        if ($projectId !== null) {
            $bad = $this->tenantRefs->violations($supplierId, ['project_id' => $projectId], ['project_id']);
            if ($bad !== []) {
                return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($bad), 400);
            }
        }

        $before = ($existing['project_id'] ?? null) !== null ? (int) $existing['project_id'] : null;
        if ($before === $projectId) {
            return Json::ok($response, $this->repo->find($id, $supplierId));
        }

        try {
            $restamped = (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $id, $projectId): int {
                $updated = $this->posting->restampProjectDimension($supplierId, 'purchase_invoice', $id, $projectId);
                $this->repo->updateProject($id, $supplierId, $projectId);
                return $updated;
            });
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.project_changed', $user['id'] ?? null, 'purchase_invoice', $id, [
            'from'                 => $before,
            'to'                   => $projectId,
            'journal_lines_updated' => $restamped,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
