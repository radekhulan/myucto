<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\ProductPriceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CatalogPricingExchangeRateRepository;
use MyInvoice\Repository\CatalogPricingProfileRepository;
use MyInvoice\Repository\CatalogPricingRuleRepository;
use MyInvoice\Repository\StockCurrencyRepository;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Pricing\CatalogPriceJobService;
use MyInvoice\Service\Eshop\Pricing\CatalogPricingPolicyService;
use MyInvoice\Service\Eshop\Pricing\PriceWriteService;
use MyInvoice\Service\Eshop\Pricing\PricingInputException;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class CatalogPricingPolicyTest extends StockTestCase
{
    public function testHighValueMarginDoesNotTruncateIntermediateFactor(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $this->currency($supplierId, 'EUR');
        $itemId = $this->item($supplierId, 'POLICY-PRECISE');
        $this->receiveStock($supplierId, $this->warehouse($supplierId), $itemId, '1.000', 100000000.0);
        foreach (['CZK', 'EUR'] as $currency) {
            $profile = $this->profile($supplierId, 'precise-' . strtolower($currency), $currency, 'target_margin', '25', 'commercial');
            $this->rule($supplierId, $profile, 'default', null, 0);
        }
        $this->container->get(CatalogPricingExchangeRateRepository::class)
            ->upsert($supplierId, 'EUR', date('Y-m-d'), 'commercial', '23');
        $writer = $this->container->get(PriceWriteService::class);
        $writer->save($supplierId, $itemId, [
            ['currency_code' => 'CZK', 'price_mode' => 'markup', 'use_pricing_rules' => true],
            ['currency_code' => 'EUR', 'price_mode' => 'markup', 'use_pricing_rules' => true],
        ]);
        $prices = $this->container->get(StockItemPriceRepository::class);
        self::assertSame('133333333.33', $prices->findByCurrency($supplierId, $itemId, 'CZK')['computed_price']);
        self::assertSame('5797101.45', $prices->findByCurrency($supplierId, $itemId, 'EUR')['computed_price']);
    }

    public function testProductMarginRuleWinsAndUsesDifferentMathThanMarkup(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $itemId = $this->item($supplierId, 'POLICY-MARGIN');
        $this->receiveStock($supplierId, $this->warehouse($supplierId), $itemId, '1.000', 100.0);
        $markup = $this->profile($supplierId, 'markup', 'CZK', 'markup', '25');
        $margin = $this->profile($supplierId, 'margin', 'CZK', 'target_margin', '25');
        $this->rule($supplierId, $markup, 'default', null, 999);
        $productRule = $this->rule($supplierId, $margin, 'product', $itemId, -999);

        $prices = $this->container->get(PriceWriteService::class)->save($supplierId, $itemId, [[
            'currency_code' => 'CZK',
            'price_mode' => 'markup',
            'markup_pct' => '0',
            'use_pricing_rules' => true,
        ]]);

        self::assertSame('133.33', $prices[0]['computed_price']);
        self::assertSame('target_margin', $prices[0]['computed_calculation_mode']);
        self::assertSame($productRule, $prices[0]['computed_rule_id']);
        self::assertSame($margin, $prices[0]['computed_profile_id']);
        self::assertSame('weighted_avg', $prices[0]['computed_cost_source']);
        self::assertSame('100.000000', $prices[0]['computed_context']['cost']['base_czk']);
        self::assertSame('CZK', $prices[0]['computed_context']['output']['currency_code']);
        self::assertFalse($prices[0]['computed_context']['output']['prices_include_vat']);
        self::assertSame(date('Y-m-d'), $prices[0]['computed_context']['output']['calculation_date']);
    }

    public function testZeroForeignVendorOfferFallsBackToLastPurchaseWithPricingRules(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $this->currency($supplierId, 'XTS');
        $itemId = $this->item($supplierId, 'POLICY-ZERO-VENDOR');
        $this->receiveStock($supplierId, $this->warehouse($supplierId), $itemId, '1.000', 100.0);
        $this->db->pdo()->prepare('UPDATE stock_items SET pricing_base = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['manual', $supplierId, $itemId]);
        $this->container->get(StockItemVendorRepository::class)->add($supplierId, $itemId, [
            'client_id' => $this->client($supplierId, 'Dodavatel s nulovou nabídkou'),
            'purchase_price' => '0',
            'currency_code' => 'XTS',
            'is_preferred' => true,
        ]);
        $profileId = $this->profile(
            $supplierId,
            'zero-vendor',
            'CZK',
            'markup',
            '25',
            'commercial',
        );
        $this->rule($supplierId, $profileId, 'default', null, 0);
        $this->container->get(CatalogPricingExchangeRateRepository::class)
            ->upsert($supplierId, 'XTS', date('Y-m-d'), 'commercial', '25');

        $prices = $this->container->get(PriceWriteService::class)->save($supplierId, $itemId, [[
            'currency_code' => 'CZK',
            'price_mode' => 'markup',
            'use_pricing_rules' => true,
        ]]);

        self::assertSame('125.00', $prices[0]['computed_price']);
        self::assertSame('100.000000', $prices[0]['computed_base']);
        self::assertSame('last_purchase', $prices[0]['computed_cost_source']);
        self::assertSame('100.000000', $prices[0]['computed_context']['cost']['base_czk']);
    }

    public function testJobFreezesBusinessRateAndRuleWhileManualPriceRemainsLocked(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'EUR');
        $itemId = $this->item($supplierId, 'POLICY-SNAPSHOT');
        $this->receiveStock($supplierId, $this->warehouse($supplierId), $itemId, '1.000', 100.0);
        $profileId = $this->profile($supplierId, 'eur', 'EUR', 'markup', '25', 'commercial', 0);
        $this->rule($supplierId, $profileId, 'default', null, 0);
        $rates = $this->container->get(CatalogPricingExchangeRateRepository::class);
        $rates->upsert($supplierId, 'EUR', date('Y-m-d'), 'commercial', '25');
        $writer = $this->container->get(PriceWriteService::class);
        $writer->save($supplierId, $itemId, [[
            'currency_code' => 'EUR',
            'price_mode' => 'markup',
            'use_pricing_rules' => true,
        ]]);

        $worker = $this->container->get(CatalogPriceJobService::class);
        $jobId = $worker->enqueue($supplierId, [$itemId]);
        $rates->upsert($supplierId, 'EUR', date('Y-m-d'), 'commercial', '20');
        $profile = $this->container->get(CatalogPricingProfileRepository::class)->find($supplierId, $profileId);
        $this->container->get(CatalogPricingProfileRepository::class)->update(
            $supplierId,
            $profileId,
            array_replace($profile, ['percentage' => '50.000']),
        );
        $this->db->pdo()->prepare('UPDATE stock_item_prices SET computed_price = 99
            WHERE supplier_id = ? AND stock_item_id = ? AND currency_code = ?')
            ->execute([$supplierId, $itemId, 'EUR']);

        $job = $worker->tick($supplierId);
        $price = $this->container->get(StockItemPriceRepository::class)->findByCurrency($supplierId, $itemId, 'EUR');
        self::assertSame($jobId, $job['id']);
        self::assertSame('5.00', $price['computed_price']);
        self::assertSame('25.000000', $price['computed_rate']);
        self::assertSame(date('Y-m-d'), $price['computed_rate_date']);
        self::assertSame('commercial', $price['computed_rate_source']);
        self::assertSame('25.000', $price['computed_percentage']);
        self::assertSame('25.000000', $job['input']['pricing_policy']['rates']['commercial:EUR']['rate']);

        $writer->save($supplierId, $itemId, [[
            'currency_code' => 'EUR',
            'price_mode' => 'fixed',
            'fixed_price' => '49.90',
            'is_manual_override' => true,
            'use_pricing_rules' => true,
        ]]);
        $worker->enqueue($supplierId, [$itemId]);
        $worker->tick($supplierId);
        self::assertSame(
            '49.90',
            $this->container->get(StockItemPriceRepository::class)
                ->findByCurrency($supplierId, $itemId, 'EUR')['computed_price'],
        );
    }

    public function testAccountingRateCannotSubstituteMissingOrStaleBusinessRate(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'XTS');
        $itemId = $this->item($supplierId, 'POLICY-RATE');
        $this->receiveStock($supplierId, $this->warehouse($supplierId), $itemId, '1.000', 100.0);
        $profileId = $this->profile($supplierId, 'rate', 'XTS', 'markup', '0', 'commercial', 1);
        $this->rule($supplierId, $profileId, 'default', null, 0);
        $this->db->pdo()->prepare('INSERT INTO exchange_rates (rate_date, currency_code, rate)
            VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rate = VALUES(rate)')
            ->execute([date('Y-m-d'), 'XTS', '25']);

        try {
            $this->saveRulePrice($supplierId, $itemId, 'XTS');
            self::fail('Účetní kurz nesmí nahradit chybějící obchodní kurz.');
        } catch (PricingInputException $error) {
            self::assertSame('missing_pricing_exchange_rate', $error->errorCode);
        }

        $oldDate = date('Y-m-d', strtotime('-5 days'));
        $this->container->get(CatalogPricingExchangeRateRepository::class)
            ->upsert($supplierId, 'XTS', $oldDate, 'commercial', '25');
        try {
            $this->saveRulePrice($supplierId, $itemId, 'XTS');
            self::fail('Starý obchodní kurz musí být konkrétní chyba.');
        } catch (PricingInputException $error) {
            self::assertSame('stale_pricing_exchange_rate', $error->errorCode);
            self::assertSame($oldDate, $error->details['rate_date']);
        }

        self::assertNull(
            $this->container->get(StockItemPriceRepository::class)
                ->findByCurrency($supplierId, $itemId, 'XTS'),
        );
    }

    public function testDirectPriceWriteReturnsConcreteBusinessRateError(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'XTS');
        $itemId = $this->item($supplierId, 'POLICY-ACTION-RATE');
        $this->receiveStock($supplierId, $this->warehouse($supplierId), $itemId, '1.000', 100.0);
        $profileId = $this->profile($supplierId, 'action-rate', 'XTS', 'markup', '0', 'commercial', 1);
        $this->rule($supplierId, $profileId, 'default', null, 0);
        $version = $this->itemsRepo->find($supplierId, $itemId)['row_version'];
        $role = new EffectiveRole(1001, 'Editor', 'staff', true, [
            'eshop.write' => 2,
            'stock.items.write' => 2,
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/eshop/products/' . $itemId . '/prices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly'])
            ->withAttribute('auth.effective_role', $role)
            ->withParsedBody([
                'row_version' => $version,
                'prices' => [[
                    'currency_code' => 'XTS',
                    'price_mode' => 'markup',
                    'use_pricing_rules' => true,
                ]],
            ]);

        $response = $this->container->get(ProductPriceAction::class)
            ->put($request, new Response(), ['id' => (string) $itemId]);
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('missing_pricing_exchange_rate', $body['error']['code']);
        self::assertSame('commercial', $body['error']['source']);
        self::assertNull(
            $this->container->get(StockItemPriceRepository::class)
                ->findByCurrency($supplierId, $itemId, 'XTS'),
        );
    }

    public function testInvalidTargetMarginProfileIsRejected(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');

        $this->expectException(\InvalidArgumentException::class);
        $this->container->get(CatalogPricingPolicyService::class)->saveProfile($supplierId, null, [
            'code' => 'invalid-margin',
            'name' => 'Neplatná marže',
            'currency_code' => 'CZK',
            'calculation_mode' => 'target_margin',
            'percentage' => '100',
            'rounding' => 'none',
            'fx_source' => 'cnb',
            'max_rate_age_days' => 7,
            'is_active' => true,
        ]);
    }

    public function testActiveDefaultsOnlyWhenFieldIsAbsent(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $pricing = $this->container->get(CatalogPricingPolicyService::class);
        $profileInput = $this->profileInput('active-default');
        unset($profileInput['is_active']);

        $profileResult = $pricing->saveProfile($supplierId, null, $profileInput);
        self::assertTrue($profileResult['profile']['is_active']);
        $profileId = (int) $profileResult['profile']['id'];

        $this->assertInvalidPolicyInput(fn () => $pricing->saveProfile(
            $supplierId,
            $profileId,
            array_replace($profileInput, ['is_active' => null]),
        ));
        self::assertTrue($pricing->profiles($supplierId)[0]['is_active']);

        $ruleInput = [
            'profile_id' => $profileId,
            'match_type' => 'default',
            'match_id' => null,
            'priority' => 0,
        ];
        $ruleResult = $pricing->saveRule($supplierId, null, $ruleInput);
        self::assertTrue($ruleResult['rule']['is_active']);
        $ruleId = (int) $ruleResult['rule']['id'];

        $this->assertInvalidPolicyInput(fn () => $pricing->saveRule(
            $supplierId,
            $ruleId,
            array_replace($ruleInput, ['is_active' => null]),
        ));
        self::assertTrue($pricing->rules($supplierId)[0]['is_active']);
    }

    public function testDecimalsWithExcessPrecisionAndMalformedDefaultRuleAreRejected(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $this->currency($supplierId, 'EUR');
        $pricing = $this->container->get(CatalogPricingPolicyService::class);

        foreach (['12.3456', '12.3450'] as $percentage) {
            $this->assertInvalidPolicyInput(fn () => $pricing->saveProfile(
                $supplierId,
                null,
                array_replace($this->profileInput('precision'), ['percentage' => $percentage]),
            ));
        }

        foreach (['23.1234567', '23.1234560'] as $rate) {
            $this->assertInvalidPolicyInput(fn () => $pricing->saveExchangeRate($supplierId, [
                'currency_code' => 'EUR',
                'rate_date' => date('Y-m-d'),
                'source' => 'commercial',
                'rate' => $rate,
            ]));
        }

        $profile = $pricing->saveProfile($supplierId, null, array_replace(
            $this->profileInput('valid-precision'),
            ['percentage' => '12.345'],
        ));
        self::assertSame('12.345', $profile['profile']['percentage']);

        $this->assertInvalidPolicyInput(fn () => $pricing->saveRule($supplierId, null, [
            'profile_id' => $profile['profile']['id'],
            'match_type' => 'default',
            'match_id' => 123,
            'priority' => 0,
            'is_active' => true,
        ]));
        self::assertSame([], $pricing->rules($supplierId));
        self::assertSame([], $pricing->exchangeRates($supplierId));
    }

    public function testUnchangedPolicyWritesDoNotEnqueueRecomputation(): void
    {
        $supplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $this->currency($supplierId, 'EUR');
        $itemId = $this->item($supplierId, 'POLICY-NOOP');
        $this->container->get(PriceWriteService::class)->save($supplierId, $itemId, [[
            'currency_code' => 'CZK',
            'price_mode' => 'fixed',
            'fixed_price' => '10',
        ]]);
        $pricing = $this->container->get(CatalogPricingPolicyService::class);

        $profileInput = $this->profileInput('no-op');
        $createdProfile = $pricing->saveProfile($supplierId, null, $profileInput);
        self::assertNotNull($createdProfile['recompute_job_id']);
        $unchangedProfile = $pricing->saveProfile(
            $supplierId,
            (int) $createdProfile['profile']['id'],
            $profileInput,
        );
        self::assertNull($unchangedProfile['recompute_job_id']);

        $ruleInput = [
            'profile_id' => $createdProfile['profile']['id'],
            'match_type' => 'default',
            'match_id' => null,
            'priority' => 0,
            'is_active' => true,
        ];
        $createdRule = $pricing->saveRule($supplierId, null, $ruleInput);
        self::assertNotNull($createdRule['recompute_job_id']);
        $unchangedRule = $pricing->saveRule(
            $supplierId,
            (int) $createdRule['rule']['id'],
            $ruleInput,
        );
        self::assertNull($unchangedRule['recompute_job_id']);

        $rateInput = [
            'currency_code' => 'EUR',
            'rate_date' => date('Y-m-d'),
            'source' => 'commercial',
            'rate' => '23.5',
        ];
        $createdRate = $pricing->saveExchangeRate($supplierId, $rateInput);
        self::assertNotNull($createdRate['recompute_job_id']);
        $unchangedRate = $pricing->saveExchangeRate($supplierId, $rateInput);
        self::assertNull($unchangedRate['recompute_job_id']);
    }

    public function testForeignTenantProfileCannotBeUsedByRule(): void
    {
        $supplierId = $this->createSupplier();
        $foreignSupplierId = $this->createSupplier();
        $this->currency($supplierId, 'CZK');
        $this->currency($foreignSupplierId, 'CZK');
        $foreignProfileId = $this->profile(
            $foreignSupplierId,
            'foreign',
            'CZK',
            'markup',
            '10',
        );

        try {
            $this->container->get(CatalogPricingPolicyService::class)->saveRule($supplierId, null, [
                'profile_id' => $foreignProfileId,
                'match_type' => 'default',
                'match_id' => null,
                'priority' => 0,
                'is_active' => true,
            ]);
            self::fail('Cizí cenový profil nesmí být použit v pravidle firmy.');
        } catch (EshopException $error) {
            self::assertSame('not_found', $error->errorCode);
            self::assertSame(404, $error->httpStatus);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->prepare("DELETE FROM exchange_rates WHERE currency_code = 'XTS'")
                ->execute();
        }
        parent::tearDown();
    }

    private function currency(int $supplierId, string $code): void
    {
        $currencies = $this->container->get(StockCurrencyRepository::class);
        if ($currencies->findByCode($supplierId, $code) === null) {
            $currencies->insert($supplierId, [
                'code' => $code,
                'name' => $code,
                'is_default' => $code === 'CZK',
            ]);
        }
    }

    private function profileInput(string $code): array
    {
        return [
            'code' => $code,
            'name' => $code,
            'currency_code' => 'CZK',
            'calculation_mode' => 'markup',
            'percentage' => '12.5',
            'rounding' => 'none',
            'fx_source' => 'cnb',
            'max_rate_age_days' => 7,
            'is_active' => true,
        ];
    }

    private function assertInvalidPolicyInput(callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatný vstup cenové politiky musí být odmítnut.');
        } catch (\InvalidArgumentException) {
        }
    }

    private function profile(
        int $supplierId,
        string $code,
        string $currency,
        string $mode,
        string $percentage,
        string $source = 'cnb',
        int $maxAge = 7,
    ): int {
        return $this->container->get(CatalogPricingProfileRepository::class)->insert($supplierId, [
            'code' => $code,
            'name' => $code,
            'currency_code' => $currency,
            'calculation_mode' => $mode,
            'percentage' => $percentage,
            'rounding' => 'none',
            'fx_source' => $source,
            'max_rate_age_days' => $maxAge,
            'is_active' => true,
        ]);
    }

    private function rule(
        int $supplierId,
        int $profileId,
        string $type,
        ?int $matchId,
        int $priority,
    ): int {
        return $this->container->get(CatalogPricingRuleRepository::class)->insert($supplierId, [
            'profile_id' => $profileId,
            'match_type' => $type,
            'match_id' => $matchId,
            'priority' => $priority,
            'is_active' => true,
        ]);
    }

    private function saveRulePrice(int $supplierId, int $itemId, string $currency): void
    {
        $this->container->get(PriceWriteService::class)->save($supplierId, $itemId, [[
            'currency_code' => $currency,
            'price_mode' => 'markup',
            'use_pricing_rules' => true,
        ]]);
    }
}
