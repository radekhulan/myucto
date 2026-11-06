<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property\Payments;

use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowCapacity;
use PHPUnit\Framework\TestCase;

final class PaymentWorkflowCapacityTest extends TestCase
{
    public function testTwoIndividuallyValidPartsCannotSpendTheSameGuaranteeTwice(): void
    {
        $capacity = $this->capacity();
        $capacity->add($this->snapshot(), $this->request('4000'));
        try {
            $capacity->add($this->snapshot(), $this->request('4000'));
            self::fail('Two 40 CZK parts exceeded the proved 60 CZK guarantee.');
        } catch (PaymentException $e) {
            self::assertSame('property_payment_workflow_part_capacity', $e->errorCode);
            self::assertSame('guarantee:1:previous-owner', $e->details['reference']);
            self::assertSame('6000', $e->details['available_cents']);
            self::assertSame('8000', $e->details['requested_cents']);
        }
    }

    public function testDebtorPaymentDoesNotSpendAnUnrelatedGuarantee(): void
    {
        $capacity = $this->capacity(); $request = $this->request('4000');
        $request['payment'] = ['payer_id' => 20, 'debtor_id' => 20];
        $capacity->add($this->snapshot(), $request); $capacity->add($this->snapshot(), $request);
        self::assertTrue(true);
    }

    private function capacity(): PaymentWorkflowCapacity
    {
        return new PaymentWorkflowCapacity(['receipt' => ['amount_cents' => '10000', 'clearing_cells' => [['key' => 'source', 'amount_cents' => '10000']]], 'cell_consumed' => [], 'consumed_cents' => '0']);
    }

    private function snapshot(): array
    {
        $group = ['group_key' => 'current-owner', 'role' => 'debtor', 'members' => [20], 'remaining_cap_cents' => '10000'];
        return ['clearing_selection' => [['key' => 'source', 'amount_cents' => '4000']],
            'allocations' => [['debt_id' => 1, 'charge_line_id' => 10, 'accessory_id' => null, 'amount_cents' => '4000']],
            'debt_receipts' => [['debt' => ['id' => 1], 'group' => $group, 'lines' => [['id' => 10, 'remaining_cents' => '10000']], 'accessories' => [],
                'liability_projection' => ['groups' => [$group, ['group_key' => 'previous-owner', 'role' => 'guarantor', 'members' => [30], 'remaining_cap_cents' => '6000']]]]]];
    }

    private function request(string $amount): array
    {
        return ['amount_cents' => $amount, 'payment' => ['debtor_id' => 20, 'payer_id' => 30, 'third_party' => ['basis' => 'guarantor', 'evidence' => 'Synthetic proved guarantee']]];
    }
}
