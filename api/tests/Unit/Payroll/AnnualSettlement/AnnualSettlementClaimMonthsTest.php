<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementClaimMonths;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use PHPUnit\Framework\TestCase;

/**
 * Převod evidovaných intervalů na měsíce podle § 35ba odst. 3 a § 35c odst. 10.
 *
 * Podmínka „na jehož POČÁTKU byly splněny podmínky" je tu to jediné podstatné —
 * a zároveň to, co se nejsnáz udělá špatně.
 */
final class AnnualSettlementClaimMonthsTest extends TestCase
{
    private const YEAR = 2026;

    public function testFullYearClaimCountsTwelveMonths(): void
    {
        $result = (new AnnualSettlementClaimMonths())->credits(
            [$this->creditRow('taxpayer', '2020-01-01', null)],
            self::YEAR,
        );

        self::assertSame([], $result['blockers']);
        self::assertCount(1, $result['credits']);
        self::assertSame(TaxCreditKind::Taxpayer, $result['credits'][0]->kind);
        self::assertSame(12, $result['credits'][0]->months);
    }

    /**
     * Nárok vzniklý uprostřed měsíce se do toho měsíce NEPOČÍTÁ — § 35ba
     * odst. 3 testuje počátek měsíce. Nárok od 20. března proto začíná dubnem.
     */
    public function testClaimStartingMidMonthSkipsThatMonth(): void
    {
        $result = (new AnnualSettlementClaimMonths())->credits(
            [$this->creditRow('ztp-p', sprintf('%04d-03-20', self::YEAR), null)],
            self::YEAR,
        );

        self::assertSame([], $result['blockers']);
        self::assertSame(9, $result['credits'][0]->months);
    }

    /** Nárok, který skončil 31. srpna, se počítá včetně srpna. */
    public function testClaimEndingAtMonthEndCountsThatMonth(): void
    {
        $result = (new AnnualSettlementClaimMonths())->credits(
            [$this->creditRow(
                'disability-basic',
                sprintf('%04d-01-01', self::YEAR),
                sprintf('%04d-08-31', self::YEAR),
            )],
            self::YEAR,
        );

        self::assertSame(8, $result['credits'][0]->months);
    }

    /** Dva navazující intervaly téže slevy se sčítají bez dvojího započtení. */
    public function testOverlappingIntervalsAreCountedOnce(): void
    {
        $result = (new AnnualSettlementClaimMonths())->credits(
            [
                $this->creditRow(
                    'taxpayer',
                    sprintf('%04d-01-01', self::YEAR),
                    sprintf('%04d-06-30', self::YEAR),
                ),
                $this->creditRow(
                    'taxpayer',
                    sprintf('%04d-05-01', self::YEAR),
                    null,
                ),
            ],
            self::YEAR,
        );

        self::assertSame([], $result['blockers']);
        self::assertCount(1, $result['credits']);
        self::assertSame(12, $result['credits'][0]->months);
    }

    /** § 38l: nedoložený nárok se nepočítá ani jako nula — zastaví zúčtování. */
    public function testUnverifiedCreditEvidenceBlocks(): void
    {
        $result = (new AnnualSettlementClaimMonths())->credits(
            [$this->creditRow('taxpayer', '2020-01-01', null, 'unverified')],
            self::YEAR,
        );

        self::assertSame(
            [AnnualSettlementBlocker::CreditEvidenceUnverified->value],
            self::codes($result['blockers']),
        );
        self::assertSame([], $result['credits']);
    }

    /** § 35ba odst. 1 písm. c) a d): obě invalidity současně nejdou. */
    public function testOverlappingDisabilityCreditsBlock(): void
    {
        $result = (new AnnualSettlementClaimMonths())->credits(
            [
                $this->creditRow('disability-basic', '2020-01-01', null),
                $this->creditRow('disability-extended', '2020-01-01', null),
            ],
            self::YEAR,
        );

        self::assertSame(
            [AnnualSettlementBlocker::CreditEvidenceUnverified->value],
            self::codes($result['blockers']),
        );
    }

    /** Interval mimo zdaňovací období nepřidá ani měsíc, ani překážku. */
    public function testClaimOutsideTheYearIsIgnored(): void
    {
        $result = (new AnnualSettlementClaimMonths())->credits(
            [$this->creditRow(
                'taxpayer',
                sprintf('%04d-01-01', self::YEAR + 1),
                null,
                'unverified',
            )],
            self::YEAR,
        );

        self::assertSame([], $result['blockers']);
        self::assertSame([], $result['credits']);
    }

    /** § 35c odst. 7: měsíce s průkazem ZTP/P jsou podmnožinou měsíců nároku. */
    public function testChildZtpPMonthsAreASubsetOfClaimedMonths(): void
    {
        $result = (new AnnualSettlementClaimMonths())->children(
            [
                $this->childRow(
                    'child-a',
                    1,
                    sprintf('%04d-01-01', self::YEAR),
                    sprintf('%04d-06-30', self::YEAR),
                    ztpP: 0,
                ),
                $this->childRow(
                    'child-a',
                    1,
                    sprintf('%04d-07-01', self::YEAR),
                    null,
                    ztpP: 1,
                ),
            ],
            self::YEAR,
        );

        self::assertSame([], $result['blockers']);
        self::assertCount(1, $result['children']);
        self::assertSame(12, $result['children'][0]->months);
        self::assertSame(6, $result['children'][0]->ztpPMonths);
        self::assertSame(range(1, 12), $result['children'][0]->claimedMonths);
        self::assertSame(range(7, 12), $result['children'][0]->ztpPClaimedMonths);
        self::assertSame(range(1, 12), $result['children'][0]->toArray()['claimed_months']);
        self::assertSame(range(7, 12), $result['children'][0]->toArray()['ztp_p_claimed_months']);
    }

    /** Pořadí pro určení výše se v rámci roku nesmí měnit (§ 35c odst. 1). */
    public function testChangingChildOrderWithinTheYearBlocks(): void
    {
        $result = (new AnnualSettlementClaimMonths())->children(
            [
                $this->childRow(
                    'child-a',
                    1,
                    sprintf('%04d-01-01', self::YEAR),
                    sprintf('%04d-06-30', self::YEAR),
                ),
                $this->childRow('child-a', 2, sprintf('%04d-07-01', self::YEAR), null),
            ],
            self::YEAR,
        );

        self::assertSame(
            [AnnualSettlementBlocker::ChildClaimConflict->value],
            self::codes($result['blockers']),
        );
    }

    /** Mezera v pořadí znamená, že se na dítě zapomnělo — částka by byla jiná. */
    public function testGapInChildOrderBlocks(): void
    {
        $result = (new AnnualSettlementClaimMonths())->children(
            [
                $this->childRow('child-a', 1, '2020-01-01', null),
                $this->childRow('child-c', 3, '2020-01-01', null),
            ],
            self::YEAR,
        );

        self::assertSame(
            [AnnualSettlementBlocker::ChildClaimConflict->value],
            self::codes($result['blockers']),
        );
    }

    /** § 35c odst. 9 a § 38l odst. 3 písm. c): bez potvrzení nárok doložený není. */
    public function testUnconfirmedHouseholdBlocks(): void
    {
        $result = (new AnnualSettlementClaimMonths())->children(
            [$this->childRow(
                'child-a',
                1,
                '2020-01-01',
                null,
                sharedHousehold: 0,
            )],
            self::YEAR,
        );

        self::assertSame(
            [AnnualSettlementBlocker::ChildClaimConflict->value],
            self::codes($result['blockers']),
        );
        self::assertSame([], $result['children']);
    }

    /** @return array<string,mixed> */
    private function creditRow(
        string $kind,
        string $from,
        ?string $to,
        string $evidence = 'verified',
    ): array {
        return [
            'credit_kind' => $kind,
            'evidence_status' => $evidence,
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }

    /** @return array<string,mixed> */
    private function childRow(
        string $reference,
        int $order,
        string $from,
        ?string $to,
        int $ztpP = 0,
        int $sharedHousehold = 1,
        int $otherExcluded = 1,
        string $evidence = 'verified',
    ): array {
        return [
            'child_reference' => $reference,
            'child_order' => $order,
            'ztp_p' => $ztpP,
            'evidence_status' => $evidence,
            'shared_household_confirmed' => $sharedHousehold,
            'other_claimant_excluded' => $otherExcluded,
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }

    /**
     * @param list<AnnualSettlementBlocker> $blockers
     * @return list<string>
     */
    private static function codes(array $blockers): array
    {
        return array_map(
            static fn (AnnualSettlementBlocker $blocker): string => $blocker->value,
            $blockers,
        );
    }
}
