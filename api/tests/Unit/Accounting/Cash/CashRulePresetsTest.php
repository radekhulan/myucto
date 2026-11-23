<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Cash;

use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Cash\CashRulePresets;
use PHPUnit\Framework\TestCase;

/**
 * Sada pravidel odpovídá naseedovaným kontacím (migrace 1006/1019) — včetně
 * `cash.withdrawal.banktocash`, což je past: nemá nohu na 211 (261/221), a kdyby
 * se do nabídky dostala, odvodil by se z ní nesmyslný protiúčet.
 */
final class CashRulePresetsTest extends TestCase
{
    private function presets(): CashRulePresets
    {
        $rules = $this->createStub(PostingRuleRepository::class);
        $rules->method('effectiveMap')->willReturn([
            'cash.revenue'               => self::rule('211', '602', 'Tržba v hotovosti'),
            'cash.purchase'              => self::rule('501', '211', 'Nákup v hotovosti'),
            'cash.transfer.frombank'     => self::rule('211', '261', 'Dotace pokladny z banky'),
            'cash.deposit.cashtobank'    => self::rule('261', '211', 'Vklad hotovosti na účet'),
            'cash.withdrawal.banktocash' => self::rule('261', '221', 'Výběr z banky do pokladny'),
            'payment.receivable.cash'    => self::rule('211', '311', 'Úhrada vydané faktury hotově'),
            'payment.payable.cash'       => self::rule('321', '211', 'Úhrada přijaté faktury hotově'),
            'inventory.shortage.cash'    => self::rule('569', '211', 'Schodek pokladny'),
            'inventory.surplus.cash'     => self::rule('211', '668', 'Přebytek pokladny'),
            'inventory.shortage.stock'   => self::rule('549', '112', 'Manko na zásobách'),
        ]);
        return new CashRulePresets($rules);
    }

    /** @return array<string,mixed> */
    private static function rule(string $debit, string $credit, string $description): array
    {
        return [
            'rule_key'            => 'x',
            'description'         => $description,
            'debit_account_code'  => $debit,
            'credit_account_code' => $credit,
            'is_active'           => true,
        ];
    }

    public function testOffersOnlyCashRulesNotHandledByOwnPurpose(): void
    {
        $keys = array_column($this->presets()->listForOther(1), 'rule_key');

        self::assertSame([
            'inventory.shortage.cash',
            'inventory.surplus.cash',
            'payment.payable.cash',
            'payment.receivable.cash',
        ], $keys);
    }

    /** Kontace bez nohy na 211 se do nabídky nesmí dostat. */
    public function testExcludesRulesWithoutCashLeg(): void
    {
        $keys = array_column($this->presets()->listForOther(1), 'rule_key');

        self::assertNotContains('cash.withdrawal.banktocash', $keys, '261/221 je bankovní strana převodu');
        self::assertNotContains('inventory.shortage.stock', $keys, '549/112 se pokladny netýká');
    }

    /** Kontace s vlastním purpose (tržba/nákup/převod) do „ostatní" nepatří. */
    public function testExcludesRulesHandledByDedicatedPurpose(): void
    {
        $keys = array_column($this->presets()->listForOther(1), 'rule_key');

        foreach (['cash.revenue', 'cash.purchase', 'cash.transfer.frombank', 'cash.deposit.cashtobank'] as $key) {
            self::assertNotContains($key, $keys);
        }
    }

    /** 211 na MD = peníze přibývají = příjmový doklad; na D = výdajový. */
    public function testDirectionFilterAndDerivedCounterAccount(): void
    {
        $in = $this->presets()->listForOther(1, 'in');
        self::assertSame(['inventory.surplus.cash', 'payment.receivable.cash'], array_column($in, 'rule_key'));
        self::assertSame(['668', '311'], array_column($in, 'counter_account_code'));

        $out = $this->presets()->listForOther(1, 'out');
        self::assertSame(['inventory.shortage.cash', 'payment.payable.cash'], array_column($out, 'rule_key'));
        self::assertSame(['569', '321'], array_column($out, 'counter_account_code'));
    }

    public function testIsAllowedForOther(): void
    {
        $p = $this->presets();

        self::assertTrue($p->isAllowedForOther(1, 'payment.payable.cash'));
        self::assertFalse($p->isAllowedForOther(1, 'cash.withdrawal.banktocash'));
        self::assertFalse($p->isAllowedForOther(1, 'cash.revenue'));
        self::assertFalse($p->isAllowedForOther(1, 'neexistujici.kontace'));
    }

    /** Analytika pokladny (211001) se musí chovat jako 211. */
    public function testMatchesAnalyticCashAccount(): void
    {
        $rules = $this->createStub(PostingRuleRepository::class);
        $rules->method('effectiveMap')->willReturn([
            'cash.custom' => self::rule('518', '211001', 'Nájem hrazený z pokladny'),
        ]);

        $out = (new CashRulePresets($rules))->listForOther(1, 'out');
        self::assertSame('518', $out[0]['counter_account_code']);
    }

    /**
     * Předkontace převzaté z POHODY mají číselné klíče (513000). PHP z nich v poli
     * udělá int a řazení přes strcmp() pak shodilo celý seznam předvoleb i editor
     * pokladního dokladu.
     */
    public function testNumericRuleKeysFromMigration(): void
    {
        $rules = $this->createStub(PostingRuleRepository::class);
        $rules->method('effectiveMap')->willReturn([
            '513000' => self::rule('513', '211', 'Reprezentace'),
            '3Pv'    => self::rule('211', '602', 'Tržba'),
            '538'    => self::rule('538', '211', 'Poplatky'),
        ]);
        $p = new CashRulePresets($rules);

        self::assertSame(['3Pv', '513000', '538'], array_column($p->listForOther(1), 'rule_key'));
        self::assertTrue($p->isAllowedForOther(1, '513000'));
    }
}
