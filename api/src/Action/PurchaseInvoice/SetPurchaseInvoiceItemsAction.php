<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceLinkException;
use MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceLinks;
use MyInvoice\Service\PurchaseInvoice\StoredPurchaseItemColumns;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/purchase-invoices/{id}/items
 *
 * Replace items pro draft. Pro forms, které dělají edit items odděleně od hlavičky.
 * Pro draft only — vystavené faktury jsou immutable bez force.
 */
final class SetPurchaseInvoiceItemsAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
        private readonly \MyInvoice\Service\Accounting\Dimension\DraftItemDimensionRealign $itemDimensions,
        private readonly \MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceDeduction $advanceDeduction,
        private readonly PurchaseAdvanceLinks $advanceLinks,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        // Zámek dokladu (Epic F6) — PŘED status guardem (klient dostane 403 document_locked).
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forPurchaseInvoice($existing), 'purchase_invoice', $id)) {
            return $deny;
        }

        if ($existing['status'] !== 'draft') {
            return Json::error($response, 'not_editable', 'Items lze upravovat pouze u konceptu.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        // Tady je `items` CELÝ obsah požadavku, takže chybějící klíč není „položky neměň"
        // (jak ho čte hlavičkový PUT — {@see \MyInvoice\Service\Invoice\DocumentItemsPayload}),
        // ale vada požadavku. Bez toho by `{}` tiše smazalo všechny řádky. Explicitní
        // prázdné pole naopak PROJDE: je to jednoznačný pokyn a endpoint jede jen nad
        // draftem, kde je doklad bez řádků pracovní stav, ne tichá nula v účetnictví.
        if (!array_key_exists('items', $body) || $body['items'] === null) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400,
                ['fields' => ['items' => ['items je povinné']]]);
        }
        $items = $body['items'];
        if (!is_array($items)) {
            return Json::error($response, 'validation_failed', 'items musí být pole', 400, ['fields' => ['items' => ['items musí být pole']]]);
        }

        $vatRates = $this->repo->vatRateMap();
        $errors = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) {
                $errors["items.{$i}"][] = 'Neplatná položka';
                continue;
            }
            $errors = array_merge($errors, InvoiceAmountPolicy::validateItem($item, $i));
            $rateId = (int) ($item['vat_rate_id'] ?? 0);
            if ($rateId === 0 || !array_key_exists($rateId, $vatRates)) {
                $errors["items.{$i}.vat_rate_id"][] = 'Neznámá DPH sazba';
            }
        }
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Hlavička se tu nemění: přenesení v ní už bylo, kód 40/41 je vědomá tuzemská
        // položka smíšeného dokladu ({@see PurchaseInvoiceRepository::reconcileReverseChargeItemCodes()}).
        $items = StoredPurchaseItemColumns::carryOver($items, (array) ($existing['items'] ?? []));
        // Dimenze položek konceptu vrátí k položkám podle `id` z těla ({@see DraftItemDimensionRealign}).
        $isDraft = (string) ($existing['status'] ?? '') === 'draft';
        $dimensionsBefore = $isDraft ? $this->itemDimensions->snapshot('purchase_invoice', $id) : [];
        // Řádky a přepočet záloh faktury (PurchaseAdvanceLinks::sync) v jedné transakci:
        // odpočet zálohy nad její částku řádky neuloží a doklad zůstane beze změny.
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $this->repo->replaceItems($id, $items, (bool) ($existing['reverse_charge'] ?? false));
            if ($isDraft) {
                $this->itemDimensions->realign($supplierId, 'purchase_invoice', $id, $dimensionsBefore, $items);
            }
            $this->calc->recompute($id);
            $this->advanceDeduction->clearHeaderAdvanceWhenDeducted($supplierId, $id);
            $this->advanceLinks->sync($supplierId, $id);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            // Vnořený zápis řádků s FULLTEXT odvolává vlastník celé transakce.
            // Částečný rollback může při dalším přepisu řádků shodit MariaDB 12.3.3.
            if (!$ownTx) {
                throw $e;
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PurchaseAdvanceLinkException) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->status);
            }
            throw $e;
        }

        $user =(array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.items_updated', $user['id'] ?? null, 'purchase_invoice', $id,
            ['count' => count($items)], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
