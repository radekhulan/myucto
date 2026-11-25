<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\EntityTagInput;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\EntityTagRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TagTarget;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Bulk\BulkTransaction;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/tags — interní štítky vydané faktury v libovolném stavu.
 *
 * Protějšek {@see SetInvoiceProjectAction}: štítky jsou interní třídění bez vlivu na
 * částky, deník a DPH, proto je lze měnit i u vystaveného, zaúčtovaného nebo stornovaného
 * dokladu a v uzavřeném období, bez vynucené úpravy (`invoices.edit_issued` + force).
 * Stačí zápis do faktur; klientská role štítky nevidí ani nemění.
 *
 * Body: { tags: string[] } — klíč je povinný, prázdné pole štítky odebere.
 */
final class SetInvoiceTagsAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Štítky jsou interní.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id);
        if ($existing === null || (int) ($existing['supplier_id'] ?? 0) !== $supplierId) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        // Prázdné tělo nesmí štítky tiše odebrat (#113) — odebírá jen explicitní [].
        if (!array_key_exists('tags', $body)) {
            return Json::error($response, 'validation_failed', 'Chybí tags (prázdné pole štítky odebere).', 400, [
                'fields' => ['tags' => ['Pole tags je povinné.']],
            ]);
        }
        if ($error = EntityTagInput::rejectInvalid($request, $response, $body)) {
            return $error;
        }

        $tags = new EntityTagRepository($this->db);
        $before = array_column($tags->forEntities(TagTarget::Invoice, $supplierId, [$id])[$id] ?? [], 'name');
        $pdo = $this->db->pdo();
        TransactionFailure::retrying($pdo, fn () => BulkTransaction::run(
            $pdo,
            fn () => $tags->replace(TagTarget::Invoice, $supplierId, $id, $body['tags']),
        ));
        $current = $tags->forEntities(TagTarget::Invoice, $supplierId, [$id])[$id] ?? [];
        $after = array_column($current, 'name');

        if ($before !== $after) {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('invoice.tags_changed', $user['id'] ?? null, 'invoice', $id, [
                'from' => $before,
                'to'   => $after,
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        $invoice = $this->repo->find($id) ?? $existing;
        $invoice['tags'] = $current;
        return Json::ok($response, $invoice);
    }
}
