<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment;

use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPriorityResolver;
use PHPUnit\Framework\TestCase;

final class EnforcementPriorityResolverTest extends TestCase
{
    public function testNewOlderOrderReevaluatesEveryActiveOrderInNewSnapshot(): void
    {
        $resolver = new EnforcementPriorityResolver();
        $firstSnapshot = [
            $this->claim('order-middle', '2026-02-10'),
            $this->claim('order-latest', '2026-03-10'),
        ];

        self::assertSame(
            [['order-middle'], ['order-latest']],
            $this->orderGroups($resolver->resolve($firstSnapshot)),
        );

        $secondSnapshot = [
            ...$firstSnapshot,
            $this->claim('order-oldest', '2026-01-10'),
        ];

        self::assertSame(
            [['order-oldest'], ['order-middle'], ['order-latest']],
            $this->orderGroups($resolver->resolve($secondSnapshot)),
        );
    }

    public function testOrdersDeliveredOnSameDateSharePriorityGroup(): void
    {
        $resolver = new EnforcementPriorityResolver();

        self::assertSame(
            [['order-a', 'order-b'], ['order-c']],
            $this->orderGroups($resolver->resolve([
                $this->claim('order-c', '2026-02-11'),
                $this->claim('order-b', '2026-02-10'),
                $this->claim('order-a', '2026-02-10'),
            ])),
        );
    }

    public function testClaimsWithExistingOrderKeyStayInOnePriorityGroup(): void
    {
        $resolver = new EnforcementPriorityResolver();

        $groups = $resolver->resolve([
            $this->claim('claim-current', '2026-02-10', 'shared-order'),
            $this->claim('claim-arrears', '2026-02-11', 'shared-order'),
            $this->claim('other-order', '2026-02-12'),
        ]);

        self::assertSame(
            [['shared-order'], ['other-order']],
            $this->orderGroups($groups),
        );
        self::assertSame(
            ['claim-arrears', 'claim-current'],
            array_map(
                static fn (DeductionClaim $claim): string => $claim->id,
                $groups[0],
            ),
        );
    }

    /**
     * @param list<list<DeductionClaim>> $groups
     * @return list<list<string>>
     */
    private function orderGroups(array $groups): array
    {
        return array_map(
            static function (array $claims): array {
                $orderIds = [];
                foreach ($claims as $claim) {
                    $orderIds[$claim->enforcementOrderId ?? $claim->id] = true;
                }

                return array_keys($orderIds);
            },
            $groups,
        );
    }

    private function claim(
        string $id,
        string $priorityDate,
        ?string $enforcementOrderId = null,
    ): DeductionClaim {
        return new DeductionClaim(
            $id,
            DeductionLegalBasis::Statutory,
            ClaimCategory::NonPriority,
            1_000_000,
            $priorityDate,
            legalTitleVerified: true,
            orderOrNoticeDelivered: true,
            orderIssuedOn: '2026-01-01',
            priorityClassificationVerified: true,
            dueMonetaryClaimVerified: true,
            enforcementOrderId: $enforcementOrderId ?? $id,
        );
    }
}
