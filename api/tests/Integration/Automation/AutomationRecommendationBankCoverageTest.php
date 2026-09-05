<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Automation;

use MyInvoice\Service\Automation\AutomationRecommendationService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;

final class AutomationRecommendationBankCoverageTest extends BankPostingTestCase
{
    public function testCoverageRespectsCurrencyPrefixAndEntireAmountRange(): void
    {
        $statement = $this->statement();
        foreach ([740, 760] as $amount) {
            $id = $this->transaction($statement, -$amount, [
                'counterparty_account' => '1000000013', 'counterparty_bank' => '0100',
                'counterparty_name' => '__TEST Coverage vendor', 'description' => '__TEST recurring service',
            ]);
            $this->postPredpis('bank', $id, '518', '221', $amount);
        }
        self::assertCount(1, $this->ideas());
        $this->coverageRule(['applies_currency' => 'EUR']);
        self::assertCount(1, $this->ideas(), 'A EUR rule does not cover CZK history.');
        $this->coverageRule(['counterparty_prefix' => '123']);
        self::assertCount(1, $this->ideas(), 'An unrelated prefix does not cover the proposed account.');
        $this->coverageRule(['amount_min' => 740, 'amount_max' => 745]);
        self::assertCount(1, $this->ideas(), 'Covering one sample is not coverage of the entire group.');
        $this->coverageRule(['amount_min' => 700, 'amount_max' => 800]);
        self::assertSame([], $this->ideas(), 'A broader active rule makes the new rule redundant.');
    }

    private function ideas(): array
    {
        return array_values(array_filter(
            $this->container->get(AutomationRecommendationService::class)->snapshotForSupplier($this->supplierId)['items'],
            static fn (array $item): bool => $item['type'] === 'bank_rule',
        ));
    }

    private function coverageRule(array $fields): void
    {
        $this->ruleRepo->insert($this->supplierId, array_replace([
            'name' => '__TEST Coverage rule', 'direction' => 'outgoing', 'is_active' => true,
            'counterparty_account' => null, 'counterparty_bank' => '0100', 'counterparty_prefix' => null,
            'variable_symbol' => null, 'message_contains' => null, 'amount_min' => null, 'amount_max' => null,
            'debit_account_code' => '518', 'credit_account_code' => '221', 'priority' => 100,
            'applies_currency' => 'CZK', 'mode' => 'suggest',
        ], $fields), $this->userId);
    }
}
