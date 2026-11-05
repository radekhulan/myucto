<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\OtherItemAutoMatchPolicy;
use MyInvoice\Service\Accounting\OtherItemInstallmentCoverage;
use PHPUnit\Framework\TestCase;

/**
 * Jistá shoda pro automatické přiřazení ostatní položce: VS s účtem, příští splátkou nebo
 * celým zbytkem, nebo účet s příští splátkou v okně splatnosti. Přeplatek nikdy.
 */
final class OtherItemAutoMatchPolicyTest extends TestCase
{
    private const ACCOUNT = '1000000005';
    private const BANK = '0100';

    public function testVariableSymbolWithAccountIsSure(): void
    {
        $decision = OtherItemAutoMatchPolicy::decide($this->payment(500, '2099001', true),
            [$this->item(1, '2099001', true, 3000)]);
        self::assertSame(['allocate', 1, 'other_item_vs_account'],
            [$decision['decision'], $decision['item_id'], $decision['reason']]);
    }

    public function testVariableSymbolWithNextInstallmentOrRemainingIsSure(): void
    {
        $plan = $this->plan([['2099-03-10', 1000], ['2099-04-10', 2000]]);
        self::assertSame('other_item_vs_installment', OtherItemAutoMatchPolicy::decide(
            $this->payment(1000, '2099001'), [$this->item(1, '2099001', false, 3000, $plan)])['reason']);
        self::assertSame('other_item_vs_remaining', OtherItemAutoMatchPolicy::decide(
            $this->payment(3000, '2099001'), [$this->item(1, '2099001', false, 3000)])['reason']);
        self::assertSame('suggest', OtherItemAutoMatchPolicy::decide(
            $this->payment(2000, '2099001'), [$this->item(1, '2099001', false, 3000, $plan)])['decision'],
            'Pozdější splátka s VS bez účtu není příští splátka.');
    }

    public function testAccountWithNextInstallmentNeedsDueWindow(): void
    {
        $plan = $this->plan([['2099-03-10', 1000], ['2099-04-10', 2000]]);
        $item = $this->item(1, null, true, 3000, $plan);
        self::assertSame('other_item_account_installment',
            OtherItemAutoMatchPolicy::decide($this->payment(1000, null, true, '2099-03-20'), [$item])['reason']);
        self::assertSame('suggest',
            OtherItemAutoMatchPolicy::decide($this->payment(1000, null, true, '2099-05-20'), [$item])['decision'],
            'Mimo okno splatnosti je to jen návrh.');
        self::assertSame('suggest',
            OtherItemAutoMatchPolicy::decide($this->payment(3000, null, true, '2099-03-10'), [$this->item(1, null, true, 3000)])['decision'],
            'Účet s celým zbytkem bez splátek není jistá shoda.');
    }

    public function testTwoSureCandidatesAreAmbiguous(): void
    {
        $decision = OtherItemAutoMatchPolicy::decide($this->payment(500, '2099001', true),
            [$this->item(1, '2099001', true, 3000), $this->item(2, '2099001', true, 800)]);
        self::assertSame(['suggest', null], [$decision['decision'], $decision['item_id']]);
    }

    public function testOverpaymentIsNeverSure(): void
    {
        $decision = OtherItemAutoMatchPolicy::decide($this->payment(3000.02, '2099001', true),
            [$this->item(1, '2099001', true, 3000)]);
        self::assertSame('suggest', $decision['decision']);
    }

    public function testWeakMatchIsNone(): void
    {
        self::assertSame('none', OtherItemAutoMatchPolicy::decide($this->payment(700, null, true),
            [$this->item(1, null, true, 3000)])['decision'], 'Jen účet protistrany (score 30) nic nenabízí.');
        self::assertSame('none', OtherItemAutoMatchPolicy::decide($this->payment(700), [])['decision']);
    }

    /** @return array<string,mixed> */
    private function payment(float $amount, ?string $vs = null, bool $account = false, string $on = '2099-03-12'): array
    {
        return [
            'amount' => $amount, 'variable_symbol' => $vs,
            'counterparty_account' => $account ? '000000' . self::ACCOUNT : null,
            'counterparty_bank' => $account ? self::BANK : null, 'payment_on' => $on,
        ];
    }

    /**
     * @param list<array<string,mixed>> $installments
     * @return array<string,mixed>
     */
    private function item(int $id, ?string $vs, bool $account, float $remaining, array $installments = []): array
    {
        return [
            'id' => $id, 'variable_symbol' => $vs,
            'counterparty_account' => $account ? self::ACCOUNT : null, 'counterparty_bank_code' => $account ? self::BANK : null,
            'remaining_amount' => $remaining, 'installments' => $installments,
        ];
    }

    /**
     * @param list<array{0:string,1:float|int}> $rows
     * @return list<array<string,mixed>>
     */
    private function plan(array $rows): array
    {
        $plan = [];
        foreach ($rows as $index => [$due, $amount]) {
            $plan[] = ['id' => $index + 1, 'position' => $index + 1, 'due_on' => $due, 'amount' => $amount];
        }
        return OtherItemInstallmentCoverage::compute($plan, [], 0.0, '2099-01-01');
    }
}
