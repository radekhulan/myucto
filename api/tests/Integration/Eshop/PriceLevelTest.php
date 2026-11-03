<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Client\CreateClientAction;
use MyInvoice\Action\Client\ListClientsAction;
use MyInvoice\Action\Client\UpdateClientAction;
use MyInvoice\Action\Eshop\PriceLevelAction;
use MyInvoice\Action\Stock\StockItemPriceLevelAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemPromoPriceRepository;
use MyInvoice\Repository\StockPriceLevelRepository;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Eshop\Pricing\PriceCalculationService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockItemQuoteService;
use MyInvoice\Service\Stock\StockPriceLevelService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Cenové hladiny odběratelů (migrace 1833) — {@see EffectivePriceResolver} s `$clientId`,
 * nacenění dokladu, číselník hladin, výjimky na kartě a pole hladiny na kartě odběratele.
 *
 * Pořadí základu: individuální cena zákazníka → hladina (produkt / kategorie / výrobce,
 * jinak výchozí sleva) → standardní cena. Akce vyhraje jen tehdy, když je levnější.
 * Bez hladiny se výsledek nesmí změnit ani o bajt.
 */
#[Group('integration')]
final class PriceLevelTest extends StockTestCase
{
    private const TODAY = '2099-06-15';

    private EffectivePriceResolver $resolver;
    private StockItemPriceRepository $prices;
    private PriceCalculationService $calc;
    private StockItemPromoPriceRepository $promos;
    private StockPriceLevelRepository $priceLevelRepo;
    private StockPriceLevelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->container->get(EffectivePriceResolver::class);
        $this->prices = $this->container->get(StockItemPriceRepository::class);
        $this->calc = $this->container->get(PriceCalculationService::class);
        $this->promos = $this->container->get(StockItemPromoPriceRepository::class);
        $this->priceLevelRepo = $this->container->get(StockPriceLevelRepository::class);
        $this->service = $this->container->get(StockPriceLevelService::class);
    }

    // ── nulová regrese ──────────────────────────────────────────────────────

    public function testResultIsByteIdenticalWithoutClientOrActiveLevel(): void
    {
        $sid = $this->createSupplier();
        $a = $this->pricedItem($sid, 'PL-ZERO-A');
        $b = $this->pricedItem($sid, 'PL-ZERO-B', '499.99');
        $noPrice = $this->item($sid, 'PL-ZERO-C');
        $this->promo($sid, $b, '450.00');
        $plain = $this->client($sid, 'Bez hladiny');
        $ids = [$a, $b, $noPrice];
        $before = $this->resolver->resolveMany($sid, $ids, 'CZK', '1', self::TODAY);

        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '10');
        $this->rule($sid, $gold, 'product', $a, 'fixed', null, '700.00', 'CZK');
        $withGold = $this->client($sid, 'Gold odběratel');
        $this->assign($withGold, $gold);
        $inactive = $this->priceLevel($sid, 'OLD', 'Stará', '50', false);
        $withInactive = $this->client($sid, 'Neaktivní hladina');
        $this->assign($withInactive, $inactive);
        // Poškozená data: odběratel s id hladiny jiné firmy.
        $foreign = $this->priceLevel($this->createSupplier(), 'GOLD', 'Cizí', '30');
        $crossed = $this->client($sid, 'Cizí hladina');
        $this->assign($crossed, $foreign);

        self::assertSame($before, $this->resolver->resolveMany($sid, $ids, 'CZK', '1', self::TODAY), 'Bez klienta.');
        self::assertSame($before, $this->resolver->resolveMany($sid, $ids, 'CZK', '1', self::TODAY, $plain), 'Klient bez hladiny.');
        self::assertSame($before, $this->resolver->resolveMany($sid, $ids, 'CZK', '1', self::TODAY, $withInactive), 'Neaktivní hladina.');
        self::assertSame($before, $this->resolver->resolveMany($sid, $ids, 'CZK', '1', self::TODAY, $crossed), 'Hladina jiné firmy.');
        // Porovnání výše běží nad týmž kódem — klíč přidaný všem výsledkům by ho neviděl.
        $legacyKeys = ['stock_item_id', 'currency_code', 'base_price', 'unit_price', 'promo_applied', 'promo_reason', 'promo_qty_available', 'promo'];
        foreach ([null, $plain, $withInactive, $crossed] as $clientId) {
            foreach ($this->resolver->resolveMany($sid, $ids, 'CZK', '1', self::TODAY, $clientId) as $row) {
                self::assertSame($legacyKeys, array_keys($row), 'Bez hladiny žádné nové klíče.');
            }
        }
        self::assertNotSame($before, $this->resolver->resolveMany($sid, $ids, 'CZK', '1', self::TODAY, $withGold), 'Pojistka: aktivní hladina cenu mění.');

        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = 0 WHERE id = ?')->execute([$sid]);
        self::assertSame($before, $this->resolver->resolveMany($sid, $ids, 'CZK', '1', self::TODAY, $withGold), 'Firma bez skladu.');
    }

    public function testDiscountWithoutStandardPriceDoesNothing(): void
    {
        $sid = $this->createSupplier();
        $item = $this->item($sid, 'PL-NOPRICE');
        $client = $this->client($sid);
        $this->assign($client, $this->priceLevel($sid, 'GOLD', 'Gold', '10'));

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame($this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY), $r);
        self::assertNull($r['unit_price']);
    }

    // ── výpočet ─────────────────────────────────────────────────────────────

    public function testCustomerPriceBeatsLevel(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-CUSTOMER');
        $client = $this->client($sid);
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '10');
        $this->rule($sid, $gold, 'product', $item, 'discount_pct', '30', null, null);
        $this->assign($client, $gold);
        $this->customerPrice($sid, $item, $client, '850.00');

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame('850.00', $r['unit_price']);
        self::assertSame('customer_fixed', $r['price_source']);
        self::assertArrayNotHasKey('price_level', $r, 'Zákaznická cena má přednost — hladina se neuplatní.');
    }

    public function testProductFixedPriceAppliesOnlyInItsCurrency(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-FIX');
        $client = $this->client($sid);
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '10');
        $this->assign($client, $gold);
        $this->rule($sid, $gold, 'product', $item, 'discount_pct', '15', null, null);
        $this->rule($sid, $gold, 'product', $item, 'fixed', null, '30.00', 'EUR');

        $czk = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame('850.00', $czk['unit_price'], 'Pevná cena v EUR se v CZK nepoužije; platí sleva produktu.');
        self::assertSame('price_level_discount', $czk['price_source']);
        self::assertSame('15.000', $czk['discount_pct']);
        self::assertSame('1000.00', $czk['standard_price']);
        self::assertSame(['id' => $gold, 'code' => 'GOLD', 'name' => 'Gold'], $czk['price_level']);

        $eur = $this->resolver->resolve($sid, $item, 'EUR', '1', self::TODAY, $client);
        self::assertSame('30.00', $eur['unit_price'], 'Pravidlo pro konkrétní měnu má přednost před pravidlem bez měny.');
        self::assertSame('price_level_fixed', $eur['price_source']);
        self::assertNull($eur['discount_pct']);

        $this->rule($sid, $gold, 'product', $item, 'fixed', null, '700.00', 'CZK');
        $fixed = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame('700.00', $fixed['unit_price']);
        self::assertSame('price_level_fixed', $fixed['price_source']);
    }

    public function testProductAndDefaultDiscountRoundToHaler(): void
    {
        $sid = $this->createSupplier();
        $discounted = $this->pricedItem($sid, 'PL-DISC', '999.99');
        $plain = $this->pricedItem($sid, 'PL-DEFAULT');
        $excluded = $this->pricedItem($sid, 'PL-NODISC');
        $client = $this->client($sid);
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '10');
        $this->assign($client, $gold);
        $this->rule($sid, $gold, 'product', $discounted, 'discount_pct', '33.333', null, null);
        $this->rule($sid, $gold, 'product', $excluded, 'discount_pct', '0', null, null);

        $r = $this->resolver->resolveMany($sid, [$discounted, $plain, $excluded], 'CZK', '1', self::TODAY, $client);
        self::assertSame(['666.66', 'price_level_discount', '33.333'], [$r[$discounted]['unit_price'], $r[$discounted]['price_source'], $r[$discounted]['discount_pct']]);
        self::assertSame(['900.00', 'price_level_discount', '10.000'], [$r[$plain]['unit_price'], $r[$plain]['price_source'], $r[$plain]['discount_pct']]);
        self::assertSame(['1000.00', '0.000'], [$r[$excluded]['unit_price'], $r[$excluded]['discount_pct']], 'Nulová sleva produktu přebije výchozí slevu.');
    }

    public function testCategoryAndManufacturerFollowPricingRulePrecedence(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-PREC');
        $other = $this->pricedItem($sid, 'PL-OTHER');
        $category = $this->category($sid, 'NARADI');
        $manufacturer = $this->manufacturer($sid, 'ACME');
        $this->db->pdo()->prepare('INSERT INTO stock_item_categories (supplier_id, stock_item_id, category_id) VALUES (?, ?, ?)')
            ->execute([$sid, $item, $category]);
        $this->db->pdo()->prepare('UPDATE stock_items SET manufacturer_id = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$manufacturer, $sid, $item]);
        $client = $this->client($sid);
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '0');
        $this->assign($client, $gold);

        $this->rule($sid, $gold, 'category', $category, 'discount_pct', '5', null, null);
        $manufacturerRule = $this->rule($sid, $gold, 'manufacturer', $manufacturer, 'discount_pct', '20', null, null);
        self::assertSame('950.00', $this->unitPrice($sid, $item, $client), 'Kategorie a výrobce jsou rovnocenné — při shodné prioritě vyhraje nižší id.');

        $this->db->pdo()->prepare('UPDATE stock_price_level_rules SET priority = 1 WHERE id = ?')->execute([$manufacturerRule]);
        self::assertSame('800.00', $this->unitPrice($sid, $item, $client), 'Vyšší priorita vyhraje.');

        $this->rule($sid, $gold, 'product', $item, 'discount_pct', '1', null, null, -10);
        self::assertSame('990.00', $this->unitPrice($sid, $item, $client), 'Produkt přebije kategorii i výrobce bez ohledu na prioritu.');

        self::assertSame(
            $this->resolver->resolve($sid, $other, 'CZK', '1', self::TODAY),
            $this->resolver->resolve($sid, $other, 'CZK', '1', self::TODAY, $client),
            'Karta mimo kategorii a výrobce bez výchozí slevy zůstane beze změny.',
        );
    }

    public function testPromoWinsOnlyWhenCheaperThanLevelBaseline(): void
    {
        $sid = $this->createSupplier();
        $cheaper = $this->pricedItem($sid, 'PL-PROMO-WIN');
        $dearer = $this->pricedItem($sid, 'PL-PROMO-LOSE');
        $client = $this->client($sid);
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '10');
        $this->assign($client, $gold);
        $this->promo($sid, $cheaper, '850.00');
        $this->promo($sid, $dearer, '950.00');

        $r = $this->resolver->resolveMany($sid, [$cheaper, $dearer], 'CZK', '1', self::TODAY, $client);
        self::assertSame(['850.00', 'promo', '900.00'], [$r[$cheaper]['unit_price'], $r[$cheaper]['price_source'], $r[$cheaper]['base_price']]);
        self::assertSame($gold, $r[$cheaper]['price_level']['id']);
        self::assertSame(['900.00', 'price_level_discount', 'not_cheaper'], [$r[$dearer]['unit_price'], $r[$dearer]['price_source'], $r[$dearer]['promo_reason']]);

        self::assertSame('950.00', $this->resolver->resolve($sid, $dearer, 'CZK', '1', self::TODAY)['unit_price'], 'Bez klienta akce platí dál.');
    }

    // ── přirážka (záporná sleva) ──────────────────────────────────────────

    public function testLevelRuleMarkupRaisesPrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-MARKUP');
        $category = $this->category($sid, 'SKUP-D');
        $this->db->pdo()->prepare('INSERT INTO stock_item_categories (supplier_id, stock_item_id, category_id) VALUES (?, ?, ?)')
            ->execute([$sid, $item, $category]);
        $client = $this->client($sid);
        $retail = $this->priceLevel($sid, 'BEZNY', 'Běžný', '0');
        $this->assign($client, $retail);
        $this->rule($sid, $retail, 'category', $category, 'discount_pct', '-45', null, null);

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame(['1450.00', 'price_level_discount', '-45.000'], [$r['unit_price'], $r['price_source'], $r['discount_pct']]);
    }

    public function testDefaultMarkupAppliesAndProductZeroOverridesIt(): void
    {
        $sid = $this->createSupplier();
        $plain = $this->pricedItem($sid, 'PL-URG', '999.99');
        $excluded = $this->pricedItem($sid, 'PL-URG-NO', '999.99');
        $client = $this->client($sid);
        $urgent = $this->priceLevel($sid, 'URG', 'Urgentní', '-5');
        $this->assign($client, $urgent);
        $this->rule($sid, $urgent, 'product', $excluded, 'discount_pct', '0', null, null);

        $r = $this->resolver->resolveMany($sid, [$plain, $excluded], 'CZK', '1', self::TODAY, $client);
        self::assertSame(['1049.99', '-5.000'], [$r[$plain]['unit_price'], $r[$plain]['discount_pct']], 'Výchozí přirážka hladiny se použije.');
        self::assertSame(['999.99', '0.000'], [$r[$excluded]['unit_price'], $r[$excluded]['discount_pct']], 'Nula u produktu vyřadí kartu i z výchozí přirážky.');
    }

    public function testMarkupRoundsToHaler(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-MARKUP-ROUND', '999.99');
        $client = $this->client($sid);
        $level = $this->priceLevel($sid, 'SERVIS', 'Servis', '0');
        $this->assign($client, $level);
        $this->rule($sid, $level, 'product', $item, 'discount_pct', '-15', null, null);

        self::assertSame('1149.99', $this->unitPrice($sid, $item, $client), '999,99 × 1,15 = 1 149,9885 → 1 149,99');
    }

    public function testPromoBelowMarkedUpPriceApplies(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-MARKUP-PROMO');
        $client = $this->client($sid);
        $retail = $this->priceLevel($sid, 'BEZNY', 'Běžný', '-45');
        $this->assign($client, $retail);
        $this->promo($sid, $item, '1100.00');

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client);
        self::assertSame(['1100.00', 'promo', '1450.00'], [$r['unit_price'], $r['price_source'], $r['base_price']], 'Akce levnější než cena s přirážkou platí.');
        self::assertSame('1000.00', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY)['unit_price'], 'Bez hladiny akce dražší než standardní cena neplatí.');
    }

    public function testCodebookAcceptsMarkupAndRejectsOutOfRange(): void
    {
        $sid = $this->createSupplier();
        $action = $this->container->get(PriceLevelAction::class);

        $created = $action->create($this->request('POST', $sid, ['code' => 'SERVIS', 'name' => 'Servis', 'default_discount_pct' => -35]), new Psr7Response());
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame('-35.000', $this->body($created)['default_discount_pct']);

        foreach (['-999.999', '100', '−45'] as $ok) {
            $res = $action->create($this->request('POST', $sid, ['code' => 'OK' . md5($ok), 'name' => 'OK', 'default_discount_pct' => $ok]), new Psr7Response());
            self::assertSame(201, $res->getStatusCode(), 'Hranice / typografické minus: ' . $ok);
        }
        foreach ([-1000, '-999.9999', 100.5, '- 5', '--5', '+5', '–5'] as $bad) {
            $res = $action->create($this->request('POST', $sid, ['code' => 'X' . md5((string) $bad), 'name' => 'X', 'default_discount_pct' => $bad]), new Psr7Response());
            self::assertSame(400, $res->getStatusCode(), 'Neplatné: ' . $bad);
        }
        $zero = $action->create($this->request('POST', $sid, ['code' => 'ZERO', 'name' => 'Nula', 'default_discount_pct' => '-0']), new Psr7Response());
        self::assertSame('0.000', $this->body($zero)['default_discount_pct'], 'Záporná nula se uloží jako 0.');
    }

    public function testCustomerPriceStillRejectsMarkup(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-CUST-MARKUP');
        $client = $this->client($sid);
        try {
            $this->container->get(\MyInvoice\Service\Stock\StockItemCustomerPriceService::class)->save($sid, $item, [
                ['client_id' => $client, 'currency_code' => 'CZK', 'price_type' => 'discount_pct', 'discount_pct' => '-10'],
            ]);
            self::fail('Individuální cena zákazníka přirážku nepřijímá.');
        } catch (StockException $e) {
            self::assertSame('validation_failed', $e->errorCode);
        }
    }

    public function testItemOverviewShowsMarkedUpPrice(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-OVERVIEW-MARKUP');
        $level = $this->priceLevel($sid, 'BEZNY', 'Běžný', '-5');

        $row = $this->overviewRow($sid, $item, $level);
        self::assertSame(['1050.00', 'default'], [$row['resulting_price'], $row['source']], 'Výchozí přirážka hladiny na kartě.');

        $this->service->saveItem($sid, $item, [['price_level_id' => $level, 'rule_type' => 'discount_pct', 'discount_pct' => '-45']]);
        $row = $this->overviewRow($sid, $item, $level);
        self::assertSame(['1450.00', 'product', '1050.00'], [$row['resulting_price'], $row['source'], $row['inherited_price']]);
    }

    public function testRuleSetAcceptsMarkupAndRejectsOutOfRange(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-MARKUP-RULES');
        $level = $this->priceLevel($sid, 'BEZNY', 'Běžný', '0');

        $saved = $this->service->replaceRules($sid, $level, [['match_type' => 'product', 'match_id' => $item, 'rule_type' => 'discount_pct', 'discount_pct' => '-45']]);
        self::assertSame('-45.000', $saved[0]['discount_pct']);

        try {
            $this->service->replaceRules($sid, $level, [['match_type' => 'product', 'match_id' => $item, 'rule_type' => 'discount_pct', 'discount_pct' => '-1000.5']]);
            self::fail('Přirážka nad 999,999 % nesmí projít.');
        } catch (StockException $e) {
            self::assertSame('discount_pct', $e->details['field']);
        }
    }

    public function testQuoteCarriesPriceLevel(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-QUOTE');
        $client = $this->client($sid);
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '10');
        $this->assign($client, $gold);
        $quotes = $this->container->get(StockItemQuoteService::class);
        $lines = [['key' => 'a', 'stock_item_id' => $item, 'unit' => null, 'quantity' => '2']];

        $line = $quotes->quote($sid, ['client_id' => $client, 'currency' => 'CZK', 'date' => self::TODAY, 'lines' => $lines])['lines'][0];
        self::assertSame('900.00', $line['unit_price']);
        self::assertSame('price_level_discount', $line['price_source']);
        self::assertSame(['id' => $gold, 'code' => 'GOLD', 'name' => 'Gold'], $line['price_level']);
        self::assertSame('10.000', $line['discount_pct']);
        self::assertNull($line['customer_price_id']);
        self::assertSame('1000.00', $line['list_unit_price'], 'Ceníková cena = standardní cena karty před hladinou.');

        $plain = $quotes->quote($sid, ['client_id' => null, 'currency' => 'CZK', 'date' => self::TODAY, 'lines' => $lines])['lines'][0];
        self::assertSame(['1000.00', 'standard', null, null], [$plain['unit_price'], $plain['price_source'], $plain['price_level'], $plain['discount_pct']]);
        self::assertSame('1000.00', $plain['list_unit_price']);
    }

    public function testQuoteListPriceIsStandardPriceForEverySource(): void
    {
        $sid = $this->createSupplier();
        $quotes = $this->container->get(StockItemQuoteService::class);
        $quote = function (int $item, ?int $client) use ($sid, $quotes): array {
            $lines = [['key' => 'a', 'stock_item_id' => $item, 'unit' => null, 'quantity' => '1']];
            return $quotes->quote($sid, ['client_id' => $client, 'currency' => 'CZK', 'date' => self::TODAY, 'lines' => $lines])['lines'][0];
        };

        $markup = $this->pricedItem($sid, 'PL-LIST-MARKUP');
        $client = $this->assignedClient($sid, $this->priceLevel($sid, 'RETAIL', 'Maloobchod', '-5'));
        self::assertSame(['1050.00', '1000.00'], [$quote($markup, $client)['unit_price'], $quote($markup, $client)['list_unit_price']], 'Přirážka hladiny');

        $fixed = $this->pricedItem($sid, 'PL-LIST-FIXED');
        $vip = $this->client($sid);
        $this->customerPrice($sid, $fixed, $vip, '850.00');
        self::assertSame(['850.00', '1000.00'], [$quote($fixed, $vip)['unit_price'], $quote($fixed, $vip)['list_unit_price']], 'Individuální cena');

        $promo = $this->pricedItem($sid, 'PL-LIST-PROMO');
        $this->promo($sid, $promo, '799.00');
        self::assertSame(['799.00', '1000.00'], [$quote($promo, null)['unit_price'], $quote($promo, null)['list_unit_price']], 'Akční cena');

        $noPrice = $this->item($sid, 'PL-LIST-NONE');
        self::assertNull($quote($noPrice, null)['list_unit_price'], 'Karta bez ceny nemá ani ceníkovou.');
    }

    // ── hladina zvolená na dokladu (migrace 1880) ──────────────────────────

    public function testDocumentLevelReplacesClientLevelAndWorksWithoutClient(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-DOC');
        $standard = $this->priceLevel($sid, 'DEALER', 'Dealer', '30');
        $urgent = $this->priceLevel($sid, 'DEALER-URG', 'Dealer urgentní', '25');
        $client = $this->assignedClient($sid, $standard);

        self::assertSame('700.00', $this->unitPrice($sid, $item, $client));
        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client, $urgent);
        self::assertSame('750.00', $r['unit_price']);
        self::assertSame(['id' => $urgent, 'code' => 'DEALER-URG', 'name' => 'Dealer urgentní'], $r['price_level']);

        $walkIn = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, null, $urgent);
        self::assertSame('750.00', $walkIn['unit_price'], 'Hladina dokladu platí i bez odběratele.');
    }

    public function testCustomerPriceBeatsDocumentLevel(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-DOC-CUST');
        $client = $this->client($sid);
        $this->customerPrice($sid, $item, $client, '850.00');
        $level = $this->priceLevel($sid, 'DOC', 'Doklad', '40');

        $r = $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client, $level);
        self::assertSame('850.00', $r['unit_price']);
        self::assertSame('customer_fixed', $r['price_source']);
    }

    public function testInactiveOrForeignDocumentLevelIsIgnoredAndQuoteRejectsIt(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-DOC-BAD');
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '10');
        $client = $this->assignedClient($sid, $gold);
        $inactive = $this->priceLevel($sid, 'OLD', 'Stará', '50', false);
        $foreign = $this->priceLevel($this->createSupplier(), 'X', 'Cizí', '50');

        // Resolver neplatnou hladinu dokladu nepoužije a nespadne zpátky na hladinu odběratele.
        self::assertSame('1000.00', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client, $inactive)['unit_price']);
        self::assertSame('1000.00', $this->resolver->resolve($sid, $item, 'CZK', '1', self::TODAY, $client, $foreign)['unit_price']);

        $quotes = $this->container->get(StockItemQuoteService::class);
        $lines = [['key' => 'a', 'stock_item_id' => $item, 'unit' => null, 'quantity' => '1']];
        foreach ([$inactive, $foreign, -1, 'abc'] as $bad) {
            try {
                $quotes->quote($sid, ['client_id' => $client, 'price_level_id' => $bad, 'currency' => 'CZK', 'date' => self::TODAY, 'lines' => $lines]);
                self::fail('Neplatná hladina dokladu musí vrátit chybu: ' . $bad);
            } catch (StockException $e) {
                self::assertSame('invalid_price_level', $e->errorCode);
            }
        }
    }

    public function testQuoteUsesDocumentLevel(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-DOC-QUOTE');
        $client = $this->assignedClient($sid, $this->priceLevel($sid, 'GOLD', 'Gold', '10'));
        $urgent = $this->priceLevel($sid, 'URG', 'Urgentní', '5');
        $quotes = $this->container->get(StockItemQuoteService::class);
        $lines = [['key' => 'a', 'stock_item_id' => $item, 'unit' => null, 'quantity' => '1']];

        $line = $quotes->quote($sid, ['client_id' => $client, 'price_level_id' => $urgent, 'currency' => 'CZK', 'date' => self::TODAY, 'lines' => $lines])['lines'][0];
        self::assertSame(['950.00', 'price_level_discount'], [$line['unit_price'], $line['price_source']]);
        self::assertSame($urgent, $line['price_level']['id']);

        $default = $quotes->quote($sid, ['client_id' => $client, 'price_level_id' => null, 'currency' => 'CZK', 'date' => self::TODAY, 'lines' => $lines])['lines'][0];
        self::assertSame('900.00', $default['unit_price'], 'null = hladina odběratele.');
    }

    public function testInvoiceStoresDocumentLevelOnlyWhenKeyIsPresent(): void
    {
        $sid = $this->createSupplier();
        $level = $this->priceLevel($sid, 'URG', 'Urgentní', '5');
        $repo = $this->container->get(\MyInvoice\Repository\InvoiceRepository::class);
        $data = [
            'invoice_type' => 'invoice', 'client_id' => $this->client($sid), 'issue_date' => self::TODAY,
            'tax_date' => self::TODAY, 'due_date' => self::TODAY, 'currency_id' => $this->currencyIdFor($sid),
            'reverse_charge' => false, 'language' => 'cs',
        ];
        $id = $repo->createDraft($data + ['price_level_id' => $level], $this->userId);
        self::assertSame($level, $repo->find($id)['price_level_id']);

        $repo->updateDraft($id, $data);
        self::assertSame($level, $repo->find($id)['price_level_id'], 'Cesta bez klíče (import, opakovaná fakturace) hladinu nesmaže.');

        $repo->updateDraft($id, $data + ['price_level_id' => null]);
        self::assertNull($repo->find($id)['price_level_id']);

        $plain = $repo->createDraft($data, $this->userId);
        self::assertNull($repo->find($plain)['price_level_id']);
    }

    public function testCopiedInvoiceKeepsDocumentLevel(): void
    {
        $sid = $this->createSupplier();
        $level = $this->priceLevel($sid, 'URG', 'Urgentní', '5');
        $repo = $this->container->get(\MyInvoice\Repository\InvoiceRepository::class);
        $source = $repo->createDraft([
            'invoice_type' => 'invoice', 'client_id' => $this->client($sid), 'issue_date' => self::TODAY,
            'tax_date' => self::TODAY, 'due_date' => self::TODAY, 'currency_id' => $this->currencyIdFor($sid),
            'reverse_charge' => false, 'language' => 'cs', 'price_level_id' => $level,
        ], $this->userId);

        $copy = $this->container->get(\MyInvoice\Action\Invoice\BulkReissueAction::class)->cloneOne($source, self::TODAY, false, $this->userId);
        self::assertSame($level, $repo->find($copy)['price_level_id']);

        $proforma = $repo->createDraft([
            'invoice_type' => 'proforma', 'client_id' => $this->client($sid), 'issue_date' => self::TODAY,
            'tax_date' => self::TODAY, 'due_date' => self::TODAY, 'currency_id' => $this->currencyIdFor($sid),
            'reverse_charge' => false, 'language' => 'cs', 'price_level_id' => $level,
        ], $this->userId);
        $final = $this->container->get(\MyInvoice\Service\Invoice\FinalFromProformaCreator::class)
            ->create($proforma, $this->userId, self::TODAY, self::TODAY, 0.0);
        self::assertSame($level, $repo->find($final)['price_level_id'], 'Finální faktura z proformy převezme hladinu.');
    }

    public function testInvoiceActionsRejectForeignOrInactiveDocumentLevel(): void
    {
        $sid = $this->createSupplier();
        $inactive = $this->priceLevel($sid, 'OLD', 'Stará', '10', false);
        $foreign = $this->priceLevel($this->createSupplier(), 'X', 'Cizí', '10');
        $create = $this->container->get(\MyInvoice\Action\Invoice\CreateInvoiceAction::class);
        foreach ([$inactive, $foreign] as $bad) {
            $res = $create($this->request('POST', $sid, ['client_id' => $this->client($sid), 'price_level_id' => $bad]), new Psr7Response());
            self::assertSame(422, $res->getStatusCode());
            self::assertSame('invalid_price_level', $this->body($res)['error']['code']);
        }

        $repo = $this->container->get(\MyInvoice\Repository\InvoiceRepository::class);
        $invoiceId = $repo->createDraft([
            'invoice_type' => 'invoice', 'client_id' => $this->client($sid), 'issue_date' => self::TODAY,
            'tax_date' => self::TODAY, 'due_date' => self::TODAY, 'currency_id' => $this->currencyIdFor($sid),
            'reverse_charge' => false, 'language' => 'cs',
        ], $this->userId);
        $update = $this->container->get(\MyInvoice\Action\Invoice\UpdateInvoiceAction::class);
        $res = $update($this->request('PUT', $sid, ['price_level_id' => $foreign]), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(422, $res->getStatusCode());
        self::assertSame('invalid_price_level', $this->body($res)['error']['code']);

        // Hladina, kterou koncept už má a kterou někdo mezitím smazal, uložení neblokuje.
        $gone = $this->priceLevel($sid, 'GONE', 'Smazaná', '10');
        $this->db->pdo()->prepare('UPDATE invoices SET price_level_id = ? WHERE id = ?')->execute([$gone, $invoiceId]);
        $this->db->pdo()->prepare('DELETE FROM stock_price_levels WHERE id = ?')->execute([$gone]);
        $res = $update($this->request('PUT', $sid, ['price_level_id' => $gone]), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertNotSame('invalid_price_level', $this->body($res)['error']['code'] ?? null);
        self::assertSame($gone, $repo->find($invoiceId)['price_level_id'], 'Hladina na konceptu zůstala.');
    }

    // ── číselník hladin a pravidla ─────────────────────────────────────────

    public function testCodebookCrudAndDeleteInUseIsRejected(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-CRUD');
        $action = $this->container->get(PriceLevelAction::class);

        $created = $action->create($this->request('POST', $sid, ['code' => 'GOLD', 'name' => 'Gold', 'default_discount_pct' => 10]), new Psr7Response());
        self::assertSame(201, $created->getStatusCode());
        $id = (int) $this->body($created)['id'];
        self::assertSame('10.000', $this->body($created)['default_discount_pct']);

        $dup = $action->create($this->request('POST', $sid, ['code' => 'gold', 'name' => 'Gold 2']), new Psr7Response());
        self::assertSame(409, $dup->getStatusCode());
        self::assertSame('price_level_code_taken', $this->body($dup)['error']['code']);
        $invalid = $action->create($this->request('POST', $sid, ['code' => 'SILVER', 'name' => 'Silver', 'default_discount_pct' => 120]), new Psr7Response());
        self::assertSame(400, $invalid->getStatusCode());

        $rules = $action->replaceRules($this->request('PUT', $sid, [['match_type' => 'product', 'match_id' => $item, 'rule_type' => 'discount_pct', 'discount_pct' => 5]]), new Psr7Response(), ['id' => (string) $id]);
        self::assertSame(200, $rules->getStatusCode());
        self::assertSame('Karta PL-CRUD', $this->body($rules)[0]['match_label']);

        $client = $this->client($sid);
        $this->assign($client, $id);
        $list = $this->body($action->list($this->request('GET', $sid), new Psr7Response()));
        self::assertSame([1, 1], [$list[0]['client_count'], $list[0]['rule_count']]);

        $delete = $action->delete($this->request('DELETE', $sid), new Psr7Response(), ['id' => (string) $id]);
        self::assertSame(409, $delete->getStatusCode());
        self::assertSame('price_level_in_use', $this->body($delete)['error']['code']);
        self::assertSame(1, $this->body($delete)['error']['client_count']);

        $rename = $action->update($this->request('PUT', $sid, ['code' => 'ZLATA', 'is_active' => false]), new Psr7Response(), ['id' => (string) $id]);
        self::assertSame(200, $rename->getStatusCode(), 'Kód hladiny není cizí klíč — jde změnit i u používané hladiny.');
        self::assertSame(['ZLATA', false], [$this->body($rename)['code'], $this->body($rename)['is_active']]);

        $this->assign($client, null);
        self::assertSame(200, $action->delete($this->request('DELETE', $sid), new Psr7Response(), ['id' => (string) $id])->getStatusCode());
        $left = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_price_level_rules WHERE supplier_id = ?');
        $left->execute([$sid]);
        self::assertSame(0, (int) $left->fetchColumn(), 'Pravidla odejdou s hladinou.');
    }

    public function testRuleSetValidation(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-RULES');
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '0');
        $category = $this->category($sid, 'KAT');
        $foreignCategory = $this->category($this->createSupplier(), 'KAT');

        $cases = [
            'validation_failed' => [['match_type' => 'category', 'match_id' => $category, 'rule_type' => 'fixed', 'fixed_price' => '10', 'currency_code' => 'CZK']],
            'invalid_match' => [['match_type' => 'category', 'match_id' => $foreignCategory, 'rule_type' => 'discount_pct', 'discount_pct' => 5]],
            'price_level_rule_duplicate' => [
                ['match_type' => 'product', 'match_id' => $item, 'rule_type' => 'discount_pct', 'discount_pct' => 5],
                ['match_type' => 'product', 'match_id' => $item, 'rule_type' => 'discount_pct', 'discount_pct' => 6],
            ],
        ];
        foreach ($cases as $expected => $body) {
            try {
                $this->service->replaceRules($sid, $gold, $body);
                self::fail("Očekávám $expected.");
            } catch (StockException $e) {
                self::assertSame($expected, $e->errorCode);
            }
        }
        try {
            $this->service->replaceRules($sid, $gold, [['match_type' => 'product', 'match_id' => $item, 'rule_type' => 'fixed', 'fixed_price' => '10']]);
            self::fail('Pevná cena bez měny nesmí projít.');
        } catch (StockException $e) {
            self::assertSame('currency_code', $e->details['field']);
        }
        self::assertSame([], $this->service->rules($sid, $gold), 'Neplatné uložení nesmí sadu změnit.');
    }

    // ── karta zboží ─────────────────────────────────────────────────────────

    public function testItemEndpointManagesOnlyProductRules(): void
    {
        $sid = $this->createSupplier();
        $item = $this->pricedItem($sid, 'PL-ITEM');
        $category = $this->category($sid, 'KAT');
        $this->db->pdo()->prepare('INSERT INTO stock_item_categories (supplier_id, stock_item_id, category_id) VALUES (?, ?, ?)')
            ->execute([$sid, $item, $category]);
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '10');
        $this->rule($sid, $gold, 'category', $category, 'discount_pct', '5', null, null);
        $this->priceLevel($sid, 'OFF', 'Vypnutá', '50', false);
        $action = $this->container->get(StockItemPriceLevelAction::class);
        $args = ['id' => (string) $item];

        $rows = $this->body($action->get($this->request('GET', $sid), new Psr7Response(), $args));
        self::assertCount(1, $rows, 'Jen aktivní hladiny × měny karty (tady jen CZK).');
        self::assertSame(['CZK', '1000.00', '950.00', 'category', 'KAT'], [$rows[0]['currency_code'], $rows[0]['standard_price'], $rows[0]['resulting_price'], $rows[0]['source'], $rows[0]['rule']['match_code']]);
        self::assertNull($rows[0]['product_rule']);

        $saved = $action->put($this->request('PUT', $sid, [['price_level_id' => $gold, 'rule_type' => 'fixed', 'fixed_price' => '700', 'currency_code' => 'CZK']]), new Psr7Response(), $args);
        self::assertSame(200, $saved->getStatusCode());
        $row = $this->body($saved)[0];
        self::assertSame(['700.00', 'product', '950.00', 'category'], [$row['resulting_price'], $row['source'], $row['inherited_price'], $row['inherited_source']]);
        self::assertSame('fixed', $row['product_rule']['rule_type']);
        self::assertCount(2, $this->service->rules($sid, $gold), 'Pravidlo kategorie zůstalo.');
        self::assertSame('700.00', $this->unitPrice($sid, $item, $this->assignedClient($sid, $gold)), 'Náhled karty a nacenění dokladu dávají totéž číslo.');

        $removed = $this->body($action->put($this->request('PUT', $sid, [['price_level_id' => $gold, 'remove' => true]]), new Psr7Response(), $args));
        self::assertSame(['950.00', 'category'], [$removed[0]['resulting_price'], $removed[0]['source']]);
        $rules = $this->service->rules($sid, $gold);
        self::assertCount(1, $rules);
        self::assertSame('category', $rules[0]['match_type']);

        $noCurrency = $action->put($this->request('PUT', $sid, [['price_level_id' => $gold, 'rule_type' => 'fixed', 'fixed_price' => '700']]), new Psr7Response(), $args);
        self::assertSame(422, $noCurrency->getStatusCode());
        $foreign = $action->put($this->request('PUT', $sid, [['price_level_id' => $this->priceLevel($this->createSupplier(), 'GOLD', 'Cizí', '5'), 'rule_type' => 'discount_pct', 'discount_pct' => 5]]), new Psr7Response(), $args);
        self::assertSame(422, $foreign->getStatusCode());
        self::assertSame('invalid_price_level', $this->body($foreign)['error']['code']);
    }

    // ── karta odběratele ────────────────────────────────────────────────────

    public function testClientApiWritesPriceLevelOnlyWhenPresentAndValid(): void
    {
        $sid = $this->createSupplier();
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '10');
        $inactive = $this->priceLevel($sid, 'OLD', 'Stará', '10', false);
        $foreign = $this->priceLevel($this->createSupplier(), 'GOLD', 'Cizí', '10');
        $create = $this->container->get(CreateClientAction::class);
        $update = $this->container->get(UpdateClientAction::class);

        $rejected = $create($this->request('POST', $sid, $this->clientBody(['price_level_id' => $foreign])), new Psr7Response());
        self::assertSame(422, $rejected->getStatusCode());
        self::assertSame('invalid_price_level', $this->body($rejected)['error']['code']);

        $created = $create($this->request('POST', $sid, $this->clientBody(['price_level_id' => $gold])), new Psr7Response());
        self::assertSame(201, $created->getStatusCode());
        $client = $this->body($created);
        self::assertSame([$gold, 'Gold'], [$client['price_level_id'], $client['price_level_name']]);
        $args = ['id' => (string) $client['id']];

        $untouched = $update($this->request('PUT', $sid, $this->clientBody(['company_name' => 'Přejmenovaný'])), new Psr7Response(), $args);
        self::assertSame(200, $untouched->getStatusCode());
        self::assertSame($gold, $this->body($untouched)['price_level_id'], 'Bez klíče price_level_id se hladina nemění.');

        foreach ([$inactive, $foreign, 'abc'] as $bad) {
            $res = $update($this->request('PUT', $sid, $this->clientBody(['price_level_id' => $bad])), new Psr7Response(), $args);
            self::assertSame(422, $res->getStatusCode(), (string) $bad);
            self::assertSame('invalid_price_level', $this->body($res)['error']['code']);
        }

        $cleared = $update($this->request('PUT', $sid, $this->clientBody(['price_level_id' => null])), new Psr7Response(), $args);
        self::assertSame([null, null], [$this->body($cleared)['price_level_id'], $this->body($cleared)['price_level_name']]);

        // Hladina deaktivovaná po přiřazení nesmí zablokovat uložení ostatních údajů karty.
        $this->assign((int) $client['id'], $inactive);
        $kept = $update($this->request('PUT', $sid, $this->clientBody(['price_level_id' => $inactive])), new Psr7Response(), $args);
        self::assertSame(200, $kept->getStatusCode());
        self::assertSame($inactive, $this->body($kept)['price_level_id']);
    }

    public function testClientListFiltersByPriceLevel(): void
    {
        $sid = $this->createSupplier();
        $gold = $this->priceLevel($sid, 'GOLD', 'Gold', '10');
        $withGold = $this->client($sid, 'Odběratel Gold');
        $this->assign($withGold, $gold);
        $plain = $this->client($sid, 'Odběratel Default');
        $list = $this->container->get(ListClientsAction::class);
        $names = fn (array $query): array => array_column($this->body($list($this->request('GET', $sid, null, $query), new Psr7Response()))['data'], 'price_level_name', 'id');

        self::assertSame([$plain => null, $withGold => 'Gold'], $names([]), 'Bez parametru všichni, s názvem hladiny.');
        self::assertSame([$plain => null], $names(['price_level' => '0']), '0 = Default (bez hladiny).');
        self::assertSame([$withGold => 'Gold'], $names(['price_level' => (string) $gold]));

        foreach ([(string) $this->priceLevel($this->createSupplier(), 'GOLD', 'Cizí', '10'), 'x'] as $bad) {
            $res = $list($this->request('GET', $sid, null, ['price_level' => $bad]), new Psr7Response());
            self::assertSame(422, $res->getStatusCode(), $bad);
        }
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> řádek náhledu karty pro hladinu v CZK */
    private function overviewRow(int $supplierId, int $itemId, int $levelId): array
    {
        foreach ($this->service->itemOverview($supplierId, $itemId) as $row) {
            if ((int) $row['price_level_id'] === $levelId && $row['currency_code'] === 'CZK') {
                return $row;
            }
        }
        self::fail('Náhled karty nemá řádek hladiny.');
    }

    private function unitPrice(int $supplierId, int $itemId, int $clientId): ?string
    {
        return $this->resolver->resolve($supplierId, $itemId, 'CZK', '1', self::TODAY, $clientId)['unit_price'];
    }

    private function assignedClient(int $supplierId, int $levelId): int
    {
        $client = $this->client($supplierId, 'Odběratel ' . $levelId);
        $this->assign($client, $levelId);
        return $client;
    }

    private function pricedItem(int $supplierId, string $sku, string $price = '1000.00'): int
    {
        $item = $this->item($supplierId, $sku);
        $this->prices->upsert($supplierId, $item, 'CZK', [
            'price_mode' => 'fixed', 'markup_pct' => null, 'fixed_price' => $price,
            'rounding' => 'none', 'is_manual_override' => false,
        ]);
        $this->calc->recompute($supplierId, $item);
        return $item;
    }

    private function priceLevel(int $supplierId, string $code, string $name, string $defaultPct, bool $active = true): int
    {
        return $this->priceLevelRepo->insert($supplierId, [
            'code' => $code, 'name' => $name, 'default_discount_pct' => $defaultPct,
            'is_active' => $active, 'display_order' => 0,
        ]);
    }

    private function rule(int $supplierId, int $levelId, string $matchType, int $matchId, string $ruleType, ?string $pct, ?string $fixed, ?string $currency, int $priority = 0): int
    {
        $this->priceLevelRepo->insertRule($supplierId, $levelId, [
            'match_type' => $matchType, 'match_id' => $matchId, 'rule_type' => $ruleType,
            'discount_pct' => $pct, 'fixed_price' => $fixed, 'currency_code' => $currency, 'priority' => $priority,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function assign(int $clientId, ?int $levelId): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET price_level_id = ? WHERE id = ?')->execute([$levelId, $clientId]);
    }

    private function category(int $supplierId, string $code): int
    {
        $this->db->pdo()->prepare('INSERT INTO stock_categories (supplier_id, code, name) VALUES (?, ?, ?)')
            ->execute([$supplierId, $code, 'Kategorie ' . $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function manufacturer(int $supplierId, string $code): int
    {
        $this->db->pdo()->prepare('INSERT INTO manufacturers (supplier_id, code, name) VALUES (?, ?, ?)')
            ->execute([$supplierId, $code, 'Výrobce ' . $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function customerPrice(int $supplierId, int $itemId, int $clientId, string $fixed): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO stock_item_customer_prices (supplier_id, stock_item_id, client_id, currency_code, price_type, fixed_price)
             VALUES (?, ?, ?, 'CZK', 'fixed', ?)"
        )->execute([$supplierId, $itemId, $clientId, $fixed]);
    }

    private function promo(int $supplierId, int $itemId, string $price): void
    {
        $this->promos->insert($supplierId, $itemId, [
            'currency_code' => 'CZK', 'promo_price' => $price, 'label' => 'Akce', 'valid_from' => null,
            'valid_to' => null, 'qty_mode' => 'unlimited', 'qty_limit' => null, 'is_active' => true, 'note' => null,
        ]);
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function clientBody(array $over = []): array
    {
        return array_merge([
            'company_name' => 'Odběratel s hladinou',
            'street' => 'Testovací 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country_iso2' => 'CZ',
            'currency_default' => 'CZK',
        ], $over);
    }

    private function request(string $method, int $supplierId, ?array $body = null, array $query = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/test')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        return $body !== null ? $request->withParsedBody($body) : $request;
    }

    /** @return array<mixed> */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
