<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
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
 * POST /api/invoices/{id}/project — zařazení vydané faktury k zakázce (issue #29).
 *
 * Protějšek {@see \MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceProjectAction};
 * důvody, proč zakázka smí na už zaúčtovaný doklad, jsou popsané tam. Bez téhle
 * cesty by se u akce dala doplnit jen nákladová strana a marže by lhala.
 *
 * Body: { project_id: number|null } — klíč je povinný, null zakázku odebere.
 *
 * Oprávnění a zámek řeší {@see ProjectAssignmentAccess}: zařazení je práce se
 * zakázkami (`projects`), klientská role navíc zamčený doklad nepřeřadí.
 */
final class SetInvoiceProjectAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
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
        $existing = $this->repo->find($id);
        if ($existing === null || (int) ($existing['supplier_id'] ?? 0) !== $supplierId) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (($existing['status'] ?? '') === 'cancelled') {
            return Json::error($response, 'not_editable', 'Stornovaný doklad nelze zařadit k zakázce.', 409);
        }
        // Klientská role zamčený doklad nepřeřadí: přeřazení přerazítkuje zaúčtované
        // řádky deníku. Uzavřené období pro ostatní hlídá DimensionPeriodGuard níže.
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($existing), 'invoice', $id, true)) {
            return $deny;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        // Prázdné tělo nesmí zakázku tiše odebrat (#113) — odebírá jen explicitní null.
        if (!array_key_exists('project_id', $body)) {
            return Json::error($response, 'validation_failed', 'Chybí project_id (null zakázku odebere).', 400, [
                'fields' => ['project_id' => ['Pole project_id je povinné.']],
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
            return Json::ok($response, $this->repo->find($id));
        }

        try {
            $restamped = (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $id, $projectId): int {
                $updated = $this->posting->restampProjectDimension($supplierId, 'invoice', $id, $projectId);
                $this->db->pdo()
                    ->prepare('UPDATE invoices SET project_id = ? WHERE id = ? AND supplier_id = ?')
                    ->execute([$projectId, $id, $supplierId]);
                return $updated;
            });
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.project_changed', $user['id'] ?? null, 'invoice', $id, [
            'from'                  => $before,
            'to'                    => $projectId,
            'journal_lines_updated' => $restamped,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }
}
