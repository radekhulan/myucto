<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\ProductCardAction;
use MyInvoice\Action\Eshop\ProductPriceAction;
use MyInvoice\Action\Eshop\ProductPromoPriceAction;
use MyInvoice\Action\Eshop\ProductVendorAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class EshopEditorActionAuthorizationTest extends StockTestCase
{
    public function testAggregateEditorRequiresStockItemPermission(): void
    {
        $sid = $this->createSupplier();
        $id = $this->item($sid, 'ESHOP-STOCK-GUARD');
        $role = new EffectiveRole(1002, 'Eshop', 'staff', true, ['eshop.write' => 2]);
        $response = $this->container->get(ProductCardAction::class)->saveEditor(
            $this->request($sid, $role, []), new Response(), ['id' => $id],
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testEshopWriterWithoutAccountingPermissionCanUseEditorMutations(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'ESHOP-AUTH-WRITE');
        $vendorId = $this->client($supplierId, 'Dodavatel Eshop Role');
        $role = new EffectiveRole(1001, 'Editor e-shopu', 'staff', true, ['eshop.write' => 2, 'stock.items.write' => 2]);

        $priceAction = $this->container->get(ProductPriceAction::class);
        $version = $this->itemsRepo->find($supplierId, $itemId)['row_version'];
        $priceResponse = $priceAction->put($this->request($supplierId, $role, [
            'row_version' => $version,
            'prices' => [[
                'currency_code' => 'CZK',
                'price_mode' => 'fixed',
                'fixed_price' => '120.00',
                'rounding' => 'none',
                'is_manual_override' => false,
            ]],
        ]), new Response(), ['id' => (string) $itemId]);
        self::assertSame(200, $priceResponse->getStatusCode());
        $priceBody = $this->body($priceResponse);
        self::assertSame(
            $this->itemsRepo->find($supplierId, $itemId)['row_version'],
            $priceBody['row_version'],
        );
        $staleResponse = $priceAction->put($this->request($supplierId, $role, [
            'row_version' => $version,
            'prices' => [[
                'currency_code' => 'CZK',
                'price_mode' => 'fixed',
                'fixed_price' => '999.00',
                'rounding' => 'none',
                'is_manual_override' => false,
            ]],
        ]), new Response(), ['id' => (string) $itemId]);
        self::assertSame(409, $staleResponse->getStatusCode());
        self::assertSame('version_conflict', $this->body($staleResponse)['error']['code']);

        $vendorAction = $this->container->get(ProductVendorAction::class);
        $vendorResponse = $vendorAction->put($this->request($supplierId, $role, [
            'vendors' => [['client_id' => $vendorId, 'currency_code' => 'CZK']],
        ]), new Response(), ['id' => (string) $itemId]);
        self::assertSame(200, $vendorResponse->getStatusCode());

        $promoAction = $this->container->get(ProductPromoPriceAction::class);
        $promoResponse = $promoAction->put($this->request($supplierId, $role, [
            'promo_prices' => [[
                'currency_code' => 'CZK',
                'promo_price' => '99.00',
                'qty_mode' => 'unlimited',
            ]],
        ]), new Response(), ['id' => (string) $itemId]);
        self::assertSame(200, $promoResponse->getStatusCode());

        $cardAction = $this->container->get(ProductCardAction::class);
        $card = $this->itemsRepo->find($supplierId, $itemId);
        $editorResponse = $cardAction->saveEditor($this->request($supplierId, $role, [
            'row_version' => $card['row_version'],
            'item' => [
                'sku' => $card['sku'],
                'name' => $card['name'],
                'item_type' => $card['item_type'],
                'unit' => $card['unit'],
                'ean' => $card['ean'],
                'vat_rate_id' => $card['vat_rate_id'],
                'sale_price_without_vat' => $card['sale_price_without_vat'],
                'min_qty' => $card['min_qty'],
                'is_active' => $card['is_active'],
                'note' => $card['note'],
            ],
            'product' => [
                'manufacturer_id' => null,
                'warranty_months' => null,
                'delivery_days' => 3,
                'export_eshop' => false,
                'is_stocked' => true,
                'weight_g' => null,
                'pricing_base' => 'weighted_avg',
                'i18n' => [],
                'categories' => [],
                'tag_ids' => [],
                'attributes' => [],
                'fees' => [],
            ],
            'prices' => [],
            'promo_prices' => [],
            'vendors' => [],
        ]), new Response(), ['id' => (string) $itemId]);
        self::assertSame(200, $editorResponse->getStatusCode());
    }

    public function testAccountingPermissionAloneCannotUseEshopMutations(): void
    {
        $supplierId = $this->createSupplier();
        $itemId = $this->item($supplierId, 'ESHOP-AUTH-DENY');
        $role = new EffectiveRole(1002, 'Pouze účetní', 'staff', true, ['accounting' => 2]);

        foreach ([
            [$this->container->get(ProductPriceAction::class), 'put'],
            [$this->container->get(ProductPriceAction::class), 'recompute'],
            [$this->container->get(ProductPriceAction::class), 'delete'],
            [$this->container->get(ProductVendorAction::class), 'put'],
            [$this->container->get(ProductPromoPriceAction::class), 'put'],
            [$this->container->get(ProductCardAction::class), 'update'],
            [$this->container->get(ProductCardAction::class), 'saveEditor'],
        ] as [$action, $method]) {
            $args = $method === 'delete'
                ? ['id' => (string) $itemId, 'currency' => 'CZK']
                : ['id' => (string) $itemId];
            $response = $action->{$method}(
                $this->request($supplierId, $role, []),
                new Response(),
                $args,
            );
            self::assertSame(403, $response->getStatusCode(), $action::class . '::' . $method);
        }
    }

    /** @param array<string,mixed> $body */
    private function request(int $supplierId, EffectiveRole $role, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/eshop/products')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly'])
            ->withAttribute('auth.effective_role', $role)
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function body(Response $response): array
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        return is_array($body) ? $body : [];
    }
}
