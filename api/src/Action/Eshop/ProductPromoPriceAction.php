<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Eshop\ProductPromoPriceWriteService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Akční (promoční) ceny karty — časově a množstevně omezená sleva nad
 * standardní cenotvorbou (migrace 1328).
 *
 *   GET /api/eshop/products/{id}/promo-prices    — akce karty + dopočtený stav
 *   PUT /api/eshop/products/{id}/promo-prices    — bulk replace (jako u /prices)
 *   GET /api/eshop/products/{id}/effective-price — co teď zaplatí zákazník
 */
final class ProductPromoPriceAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly StockItemPromoPriceRepository $promos,
        private readonly ProductPromoPriceWriteService $writer,
        private readonly EffectivePriceResolver $resolver,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        $rows = $this->promos->listForItem($supplierId, $itemId);
        return Json::ok($response, $this->resolver->annotate($supplierId, $rows));
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop.write', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['promo_prices']) || !is_array($body['promo_prices']) || !array_is_list($body['promo_prices'])) {
            return Json::error($response, 'validation_failed', 'Pole promo_prices musí být seznam akčních cen.', 400);
        }

        try {
            $saved = $this->writer->save($supplierId, $itemId, $body['promo_prices']);
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }

        $this->log($request, 'eshop.promo_prices_updated', $itemId, ['count' => count($saved)]);
        return Json::ok($response, $this->resolver->annotate($supplierId, $saved));
    }

    /** Platná cena karty pro dané množství a měnu (co teď zaplatí zákazník). */
    public function effective(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $itemId = (int) $args['id'];
        if ($this->items->find($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Karta zboží nenalezena.', 404);
        }
        $q = $request->getQueryParams();
        $currency = strtoupper(trim((string) ($q['currency'] ?? 'CZK')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return Json::error($response, 'validation_failed', 'Neplatný kód měny (ISO 4217, 3 znaky).', 400);
        }
        $onDate = trim((string) ($q['on_date'] ?? ''));
        if ($onDate !== '' && !$this->isDate($onDate)) {
            return Json::error($response, 'validation_failed', 'Neplatné datum (formát RRRR-MM-DD).', 400);
        }

        return Json::ok($response, $this->resolver->resolve(
            $supplierId,
            $itemId,
            $currency,
            (string) ($q['qty'] ?? '1'),
            $onDate !== '' ? $onDate : null,
        ));
    }

    private function isDate(string $s): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y);
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_item',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
