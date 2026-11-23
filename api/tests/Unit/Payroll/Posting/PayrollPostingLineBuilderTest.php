<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Posting;

use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder;
use MyInvoice\Service\Payroll\Travel\BusinessTripMaterializer;
use PHPUnit\Framework\TestCase;

final class PayrollPostingLineBuilderTest extends TestCase
{
    private PayrollPostingLineBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PayrollPostingLineBuilder();
    }

    public function testKeepsDirectorEmploymentPartnerIncomeAndOfficeRewardSeparate(): void
    {
        $preview = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame([
            '331|credit' => 100_000,
            '331|debit' => 22_333,
            '336.100|credit' => 190_800,
            '336.200|credit' => 81_000,
            '342.100|credit' => 50_000,
            '366|credit' => 500_000,
            '366|debit' => 111_667,
            // Ú-14: dobrovolné srážky a exekuce už nesdílí jedinou 379.
            '379.100|credit' => 10_000,
            '379.200|credit' => 5_000,
            '521|debit' => 100_000,
            '522|debit' => 200_000,
            '523|debit' => 300_000,
            '524|debit' => 202_800,
        ], $this->lineMap($preview->lines));
        self::assertSame(936_800, $preview->debitTotalMinor);
        self::assertSame(936_800, $preview->creditTotalMinor);
        $deductionLines = array_values(array_filter(
            $preview->lines,
            static fn (array $line): bool =>
                str_starts_with($line['account_code'], '379')
                && $line['side'] === 'credit',
        ));
        self::assertCount(2, $deductionLines);
        self::assertCount(2, array_unique(array_column(
            $deductionLines,
            'cost_center',
        )));

        $gross = array_values(array_filter(
            $preview->targetAllocations,
            static fn (array $allocation): bool =>
                str_starts_with($allocation['allocation_key'], 'gross:'),
        ));
        self::assertSame([
            ['521', 100_000],
            ['331', -100_000],
            ['522', 200_000],
            ['366', -200_000],
            ['523', 300_000],
            ['366', -300_000],
        ], array_map(
            static fn (array $allocation): array => [
                $allocation['account_code'],
                $allocation['signed_minor'],
            ],
            $gross,
        ));
    }

    /**
     * Odměna za výkon funkce jednatele, který není společníkem, jde proti 331
     * (523/331), u společníka (příznak vztahu) zůstává 523/366. Platí jen se
     * zmrazenou předkontací; bez ní zápis jako dřív (523/366 pro všechny, viz
     * testKeepsDirectorEmploymentPartnerIncomeAndOfficeRewardSeparate).
     */
    public function testStatutoryBodyWithoutPartnerShareIsPostedAgainstEmployeePayable(): void
    {
        $accounts = PayrollAccountingDefaults::codes()
            + ['statutory_nonpartner_gross_credit' => '331'];
        $preview = $this->builder->build($this->snapshot(), $this->calculatedResult(), $this->statutorySets(), $accounts);
        self::assertSame(
            [['523', 300_000], ['331', -300_000]],
            $this->grossOf($preview->targetAllocations, 103),
        );
        self::assertSame(
            ['331|credit' => 400_000, '366|credit' => 200_000],
            array_intersect_key($this->lineMap($preview->lines), ['331|credit' => 0, '366|credit' => 0]),
        );

        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][2]['employment']['statutory_partner'] = true;
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $partner = $this->builder->build($snapshot, $result, $this->statutorySets(), $accounts);
        self::assertSame(
            [['523', 300_000], ['366', -300_000]],
            $this->grossOf($partner->targetAllocations, 103),
        );

        $legacy = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        self::assertSame(
            [['523', 300_000], ['366', -300_000]],
            $this->grossOf($legacy->targetAllocations, 103),
            'Snímek bez předkontace (nenastavená se nezmrazí) = dosavadní zápis.',
        );
    }

    /**
     * Dvojice společníků a jednatelů ve formě single, souhrnně i po zaměstnancích:
     * 522/366 předpis, 366/336 pojistné, 366/342 daň, 523/366 odměna jednatele
     * společníka, 366/365 zápočet čisté mzdy na účet společníka.
     */
    public function testPartnerPostingPairsInSingleForm(): void
    {
        $snapshot = $this->snapshotWithSettlementRule();
        $snapshot['people'][0]['employments'][2]['employment']['statutory_partner'] = true;
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $accounts = PayrollAccountingDefaults::codes()
            + ['statutory_nonpartner_gross_credit' => '331'];
        $preview = $this->builder->build($snapshot, $result, $this->statutorySets(), $accounts);

        foreach ([null, \MyInvoice\Service\Payroll\Posting\PayrollPostingEmployees::fromSnapshot($snapshot)] as $employees) {
            $lines = $this->builder->singleEntryLines($preview->targetAllocations, [], $employees);
            self::assertNotNull($lines);
            $this->assertValidPairsWithTheSameTotals($preview->lines, $lines);
            $pairs = [];
            for ($i = 0; $i < count($lines); $i += 2) {
                $pairs[$lines[$i]['account_code'] . '/' . $lines[$i + 1]['account_code']] = true;
            }
            foreach (['522/366', '523/366', '366/336.100', '366/336.200', '366/342.100', '366/365.100'] as $expected) {
                self::assertArrayHasKey($expected, $pairs, $expected);
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $allocations
     * @return list<array{string,int}>
     */
    private function grossOf(array $allocations, int $employmentId): array
    {
        $result = [];
        foreach ($allocations as $allocation) {
            if (str_starts_with($allocation['allocation_key'], "gross:employment:{$employmentId}:")) {
                $result[] = [$allocation['account_code'], $allocation['signed_minor']];
            }
        }

        return $result;
    }

    public function testUsesTenantRelationAccountsAsSingleSourceOfDefaults(): void
    {
        $accounts = PayrollAccountingDefaults::codes();
        $accounts['employment_gross_debit'] = '521.100';
        $accounts['employment_gross_credit'] = '331.100';
        $accounts['partner_gross_debit'] = '522.100';
        $accounts['partner_gross_credit'] = '366.100';
        $accounts['statutory_gross_debit'] = '523.100';
        $accounts['statutory_gross_credit'] = '366.200';

        $preview = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            $accounts,
        );
        $grossAccounts = [];
        foreach ($preview->targetAllocations as $allocation) {
            if (str_starts_with($allocation['allocation_key'], 'gross:')) {
                $grossAccounts[] = $allocation['account_code'];
            }
        }

        self::assertSame([
            '521.100',
            '331.100',
            '522.100',
            '366.100',
            '523.100',
            '366.200',
        ], $grossAccounts);
    }

    public function testExplicitComponentPairOverridesRelationDefaultAndFeedsSettlement(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][2]['inputs'][0]['component'][
            'accounting_debit_code'
        ] = '528';
        $snapshot['people'][0]['employments'][2]['inputs'][0]['component'][
            'accounting_credit_code'
        ] = '366.523';
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][2]['inputs'][0]['accounting'] = [
            'debit_code' => '528',
            'credit_code' => '366.523',
            'amount_minor' => 300_000,
        ];
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $lineMap = $this->lineMap($preview->lines);

        self::assertSame(300_000, $lineMap['528|debit']);
        self::assertSame(300_000, $lineMap['366.523|credit']);
        self::assertArrayHasKey('366.523|debit', $lineMap);
        self::assertArrayNotHasKey('523|debit', $lineMap);
    }

    public function testNonCashComponentWithoutExplicitPairFailsClosed(): void
    {
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][0]['inputs'][0]['totals'] = [
            'source_amount_minor' => 100_000,
            'cash_payable_minor' => 80_000,
        ];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nepeněžní část');
        $this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    /**
     * Nepeněžní složka bez předkontace (1 % z vozidla, přechodné ubytování)
     * je jen OCENĚNÍ příjmu pro základ daně a pojistného. Náklad je v knihách
     * už ze zdrojového dokladu, takže se neúčtuje NIC — dosud se místo toho
     * shodilo schválení celého mzdového běhu.
     */
    public function testAccountingNeutralNonCashComponentPostsNothing(): void
    {
        [$snapshot, $result] = $this->nonCashBenefitRevision();

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->nonCashBenefitStatutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $lineMap = $this->lineMap($preview->lines);

        self::assertArrayNotHasKey('521|debit', $lineMap);
        self::assertArrayNotHasKey('331|credit', $lineMap);
        self::assertSame(
            $preview->debitTotalMinor,
            $preview->creditTotalMinor,
        );
        self::assertSame(
            [],
            array_values(array_filter(
                $preview->targetAllocations,
                static fn (array $allocation): bool =>
                    str_starts_with(
                        $allocation['allocation_key'],
                        'gross:employment:101:',
                    ),
            )),
        );
    }

    /** Nepeněžní složka s vlastní dvojicí účtů se účtuje dál — neutralita platí jen bez ní. */
    public function testNonCashComponentWithExplicitPairIsStillPosted(): void
    {
        [$snapshot, $result] = $this->nonCashBenefitRevision();
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'accounting_debit_code'
        ] = '528';
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'accounting_credit_code'
        ] = '331.900';
        $result['people'][0]['employments'][0]['inputs'][0]['accounting'] = [
            'debit_code' => '528',
            'credit_code' => '331.900',
            'amount_minor' => 100_000,
        ];
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->nonCashBenefitStatutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['528|debit']);
        self::assertSame(100_000, $lineMap['331.900|credit']);
    }

    /**
     * Povinný příspěvek na spoření u rizikové práce (z. 324/2025 Sb.) se dosud
     * zaplatil, ale nezaúčtoval. Náklad 527 i závazek 379 musí vzniknout.
     */
    public function testRiskySavingsContributionCreatesExpenseAndLiability(): void
    {
        $result = $this->calculatedResult();
        $result['statutory']['risky_savings_period_start'] = '2026-06-01';
        $result['statutory']['risky_savings'] = [
            $this->riskySavingsRow(101, 4_000),
        ];

        $preview = $this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $lineMap = $this->lineMap($preview->lines);

        self::assertSame(4_000, $lineMap['527|debit']);
        // Ú-14: tři věcně různé závazky vůči třem věřitelům, tři analytiky.
        // 10 000 srážka, 5 000 exekuce, 4 000 povinné spoření.
        self::assertSame(10_000, $lineMap['379.100|credit']);
        self::assertSame(5_000, $lineMap['379.200|credit']);
        self::assertSame(4_000, $lineMap['379.300|credit']);
        self::assertArrayNotHasKey('379|credit', $lineMap);
        self::assertSame($preview->debitTotalMinor, $preview->creditTotalMinor);
        self::assertSame(940_800, $preview->debitTotalMinor);
    }

    /**
     * Ú-14, ZPĚTNÁ KOMPATIBILITA: revize zmrazená dřív, než firma o rozpadu 379
     * věděla, se musí zaúčtovat BYTE-IDENTICKY.
     *
     * Snapshot, který klíč `enforcement_deductions_credit` ani
     * `risky_savings_credit` nenese, nesmí dostat nové analytiky — jinak by
     * opakované zaúčtování dřív schválené revize spadlo na kontrolu cílového
     * otisku v PayrollPostingAdapter a reconciliace by u zaúčtovaného období
     * ukázala rozdíl proti deníku.
     *
     * Rizikové spoření se přitom degraduje na DOSLOVNOU historickou '379'
     * (PayrollAccountingDefaults::PRE_SPLIT_ACCOUNTS), ne na firemní účet
     * srážek — proto je tu srážkám nastavená vlastní analytika 379.900, na
     * které spoření skončit NESMÍ.
     */
    public function testLegacySnapshotWithoutSplitKeysPostsEverythingOnOneAccount(): void
    {
        $accounts = PayrollAccountingDefaults::codes();
        $accounts['other_deductions_credit'] = '379.900';
        unset(
            $accounts['enforcement_deductions_credit'],
            $accounts['risky_savings_credit'],
        );

        $result = $this->calculatedResult();
        $result['statutory']['risky_savings_period_start'] = '2026-06-01';
        $result['statutory']['risky_savings'] = [
            $this->riskySavingsRow(101, 4_000),
        ];

        $lineMap = $this->lineMap($this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            $accounts,
        )->lines);

        // Srážka i exekuce na firemním účtu srážek, přesně jako dosud.
        self::assertSame(15_000, $lineMap['379.900|credit']);
        // Spoření na historické konstantě, ne na 379.900 ani na 379.300.
        self::assertSame(4_000, $lineMap['379|credit']);
        self::assertArrayNotHasKey('379.100|credit', $lineMap);
        self::assertArrayNotHasKey('379.200|credit', $lineMap);
        self::assertArrayNotHasKey('379.300|credit', $lineMap);
    }

    /**
     * Paušál plátce mzdy (§ 270 odst. 2 o. s. ř.) se oprávněnému neposílá —
     * plátce si ho odečte. Bez převodu zůstával na 379.200 a účet se žádnou
     * platbou nevyrovnal: 50 Kč za každý měsíc s exekucí.
     */
    public function testEmployerFlatFeeMovesFromEnforcementLiabilityToRevenue(): void
    {
        $result = $this->calculatedResult();
        $result['people'][0]['enforcement']['result']['employer_flat_fee_minor_units'] = 5_000;

        $preview = $this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $lineMap = $this->lineMap($preview->lines);

        // Sraženo 50 Kč, celé je paušál: závazek vůči oprávněným je nula.
        self::assertSame(5_000, $lineMap['379.200|credit']);
        self::assertSame(5_000, $lineMap['379.200|debit']);
        self::assertSame(5_000, $lineMap['648|credit']);
        self::assertSame($preview->debitTotalMinor, $preview->creditTotalMinor);

        // Obě strany 379.200 nesou touž analytiku oprávněného, výnos žádnou.
        $centers = [];
        foreach ($preview->lines as $line) {
            $centers[$line['account_code'] . '|' . $line['side']] = $line['cost_center'] ?? null;
        }
        self::assertStringStartsWith('MZ-EX-', (string) $centers['379.200|credit']);
        self::assertSame($centers['379.200|credit'], $centers['379.200|debit']);
        self::assertNull($centers['648|credit']);
    }

    /** Revize zmrazená bez výnosového účtu se zaúčtuje byte-identicky jako dřív. */
    public function testSnapshotWithoutFeeRevenueAccountKeepsTheFeeOnTheLiability(): void
    {
        $accounts = PayrollAccountingDefaults::codes();
        unset($accounts['enforcement_fee_revenue_credit']);
        $result = $this->calculatedResult();
        $result['people'][0]['enforcement']['result']['employer_flat_fee_minor_units'] = 5_000;

        $lineMap = $this->lineMap($this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            $accounts,
        )->lines);

        self::assertSame(5_000, $lineMap['379.200|credit']);
        self::assertArrayNotHasKey('379.200|debit', $lineMap);
        self::assertArrayNotHasKey('648|credit', $lineMap);
    }

    /** Nedopočítaný podklad se neúčtuje — schvalování hlídá jiná brána. */
    public function testRiskySavingsInManualReviewIsNotPosted(): void
    {
        $result = $this->calculatedResult();
        $result['statutory']['risky_savings'] = [
            [
                'employment_id' => 101,
                'status' => 'manual_review',
                'issues' => ['risky_savings_evidence_not_approved'],
                'assessment_base_minor' => null,
                'contribution_minor' => null,
            ],
        ];

        $lineMap = $this->lineMap($this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertArrayNotHasKey('527|debit', $lineMap);
    }

    /** Opravná revize příspěvku účtuje jen rozdíl, a to vyrovnaně. */
    public function testRiskySavingsCorrectionPostsOnlyTheDelta(): void
    {
        $first = $this->calculatedResult();
        $first['statutory']['risky_savings'] = [$this->riskySavingsRow(101, 4_000)];
        $target = $this->builder->build(
            $this->snapshot(),
            $first,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        $corrected = $this->calculatedResult();
        $corrected['statutory']['risky_savings'] = [
            $this->riskySavingsRow(101, 6_500),
        ];
        $correction = $this->builder->build(
            $this->snapshot(),
            $corrected,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
            $target->targetAllocations,
        );

        self::assertSame(
            ['379.300|credit' => 2_500, '527|debit' => 2_500],
            $this->lineMap($correction->lines),
        );
    }

    /** Náklad příspěvku patří středisku pracovního vztahu, závazek zůstává jeden. */
    public function testRiskySavingsExpenseCarriesTheCostCentre(): void
    {
        $snapshot = $this->snapshotWithCostCentres();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $result['statutory']['risky_savings'] = [$this->riskySavingsRow(101, 4_000)];

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame(
            ['VYROBA' => 4_000],
            $this->costCentreMap($preview->lines, '527', 'debit'),
        );
    }

    /** Příspěvek cizího pracovního vztahu je nedůvěryhodný podklad, ne řádek zápisu. */
    public function testRiskySavingsOfForeignEmploymentIsRejected(): void
    {
        $result = $this->calculatedResult();
        $result['statutory']['risky_savings'] = [$this->riskySavingsRow(999, 4_000)];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nepatří do revize');
        $this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    /**
     * Zmrazené snapshoty starších revizí nové předkontace nikdy mít nebudou.
     * Kdyby se vyžadovaly tvrdě, opakované zaúčtování dřív schválené revize
     * by spadlo na „chybí firemní účetní předkontace".
     */
    public function testAccountsAddedLaterFallBackToTheChartDefaults(): void
    {
        $accounts = PayrollAccountingDefaults::codes();
        unset(
            $accounts['risky_savings_debit'],
            $accounts['risky_savings_credit'],
            $accounts['employee_receivable_debit'],
        );
        $result = $this->calculatedResult();
        $result['statutory']['risky_savings'] = [$this->riskySavingsRow(101, 4_000)];

        $lineMap = $this->lineMap($this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            $accounts,
        )->lines);

        self::assertSame(4_000, $lineMap['527|debit']);
    }

    /** Chybějící PŮVODNÍ předkontace zůstává tvrdou chybou. */
    public function testMissingLegacyAccountStillFailsClosed(): void
    {
        $accounts = PayrollAccountingDefaults::codes();
        unset($accounts['income_tax_credit']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Chybí firemní účetní předkontace income_tax_credit');
        $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            $accounts,
        );
    }

    /**
     * Zaměstnanec celý měsíc na neplaceném volnu platí doplatek ZP do
     * minimálního vyměřovacího základu (§ 3 odst. 10 z. 592/1992 Sb.). Nemá
     * peněžní příjem, takže srážku není do čeho rozpustit — dosud to shodilo
     * schválení běhu. Účtuje se proti závazkovému účtu jeho vztahu a přeplatek
     * se překlopí na pohledávku za zaměstnancem.
     */
    public function testChargeWithoutCashIncomePostsAgainstTheRelationLiability(): void
    {
        $preview = $this->builder->build(
            $this->unpaidLeaveSnapshot(),
            $this->unpaidLeaveResult(),
            $this->unpaidLeaveStatutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame([
            '331|credit' => 5_000,
            '331|debit' => 5_000,
            '335|debit' => 5_000,
            '336.200|credit' => 5_000,
        ], $this->lineMap($preview->lines));
        self::assertSame(10_000, $preview->debitTotalMinor);
        self::assertSame(10_000, $preview->creditTotalMinor);
    }

    /** Přeplatek u společníka jde proti 366, ne proti 331. */
    public function testEmployeeReceivableUsesTheRelationLiabilityAccount(): void
    {
        $snapshot = $this->unpaidLeaveSnapshot();
        $snapshot['people'][0]['employments'][0]['employment']['relation_type'] =
            'partner_dependent';
        $result = $this->unpaidLeaveResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->unpaidLeaveStatutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(5_000, $lineMap['366|credit']);
        self::assertSame(5_000, $lineMap['335|debit']);
        self::assertArrayNotHasKey('331|debit', $lineMap);
    }

    /** Opravná revize musí pohledávku za zaměstnancem umět i odúčtovat. */
    public function testEmployeeReceivableIsReversedByACorrection(): void
    {
        $target = $this->builder->build(
            $this->unpaidLeaveSnapshot(),
            $this->unpaidLeaveResult(),
            $this->unpaidLeaveStatutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $correction = $this->builder->build(
            $this->unpaidLeaveSnapshot(),
            $this->unpaidLeaveResult(0),
            $this->unpaidLeaveStatutorySets(0),
            PayrollAccountingDefaults::codes(),
            $target->targetAllocations,
        );

        self::assertSame([
            '331|credit' => 5_000,
            '331|debit' => 5_000,
            '335|credit' => 5_000,
            '336.200|debit' => 5_000,
        ], $this->lineMap($correction->lines));
    }

    /**
     * W7/Ú-06 — nadlimitní benefit se dosud celý zaúčtoval na jeden účet.
     *
     * Nedaňová je podle § 25 odst. 1 písm. h) ZDP OSVOBOZENÁ část („v rozsahu,
     * ve kterém je toto plnění u zaměstnance osvobozeno od daně"); nadlimitní
     * část se zaměstnanci zdaní a zaměstnavateli je podle § 24 odst. 2 písm. j)
     * bodu 4 uznatelná. Dělí se proto opačně, než by se čekalo.
     */
    public function testExemptBenefitPartGoesToTheNonDeductibleAccount(): void
    {
        [$snapshot, $result] = $this->benefitBasketRevision(60_000, 40_000);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $lineMap = $this->lineMap($preview->lines);

        self::assertSame(60_000, $lineMap['528|debit'], 'osvobozená část je nedaňová');
        self::assertSame(40_000, $lineMap['521|debit'], 'nadlimitní část zůstává mzdovým nákladem');
        self::assertSame(
            100_000,
            $lineMap['528|debit'] + $lineMap['521|debit'],
            'součet obou částí je zdrojová částka na korunu',
        );
        self::assertSame($preview->debitTotalMinor, $preview->creditTotalMinor);
        self::assertSame(100_000, $lineMap['331|credit'], 'protiúčet se nedělí');
    }

    /** Celý benefit v limitu = celý nedaňový; prázdná nadlimitní dvojice nevzniká. */
    public function testFullyExemptBenefitPostsOnlyTheNonDeductibleAccount(): void
    {
        [$snapshot, $result] = $this->benefitBasketRevision(100_000, 0);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['528|debit']);
        self::assertArrayNotHasKey('521|debit', $lineMap);
    }

    /** Benefit celý nad limitem je celý zdanitelný — a tedy celý uznatelný. */
    public function testFullyTaxableBenefitStaysOnTheWageCostAccount(): void
    {
        [$snapshot, $result] = $this->benefitBasketRevision(0, 100_000);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['521|debit']);
        self::assertArrayNotHasKey('528|debit', $lineMap);
    }

    /**
     * Uznatelné koše se NEDĚLÍ: příspěvek na stravování je podle § 24 odst. 2
     * písm. j) bodu 4 daňově uznatelný celý. Rozdělit ho na 528 by firmě
     * z uznatelného nákladu udělalo neuznatelný.
     */
    public function testDeductibleBenefitBasketIsNotSplit(): void
    {
        [$snapshot, $result] = $this->benefitBasketRevision(
            60_000,
            40_000,
            'meal_per_shift',
        );

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['521|debit']);
        self::assertArrayNotHasKey('528|debit', $lineMap);
    }

    /**
     * Zmrazený snapshot bez klíče `non_deductible_benefit_debit` se musí
     * zaúčtovat PŘESNĚ jako dřív — jinak by opakované zaúčtování dřív schválené
     * revize spadlo na kontrolu cílového otisku v PayrollPostingAdapter.
     */
    public function testOlderSnapshotWithoutTheKeyPostsExactlyAsBefore(): void
    {
        [$snapshot, $result] = $this->benefitBasketRevision(60_000, 40_000);
        $legacyAccounts = PayrollAccountingDefaults::codes();
        unset($legacyAccounts['non_deductible_benefit_debit']);

        $legacy = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            $legacyAccounts,
        );
        $lineMap = $this->lineMap($legacy->lines);

        self::assertSame(100_000, $lineMap['521|debit']);
        self::assertArrayNotHasKey('528|debit', $lineMap);
    }

    /** Opravná revize odúčtuje rozdělený benefit rozdílem na obou účtech. */
    public function testBenefitSplitIsReversedByACorrection(): void
    {
        [$snapshot, $result] = $this->benefitBasketRevision(60_000, 40_000);
        $target = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        [$correctedSnapshot, $correctedResult] =
            $this->benefitBasketRevision(100_000, 0);

        $correction = $this->builder->build(
            $correctedSnapshot,
            $correctedResult,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
            $target->targetAllocations,
        );
        $lineMap = $this->lineMap($correction->lines);

        self::assertSame(40_000, $lineMap['528|debit']);
        self::assertSame(40_000, $lineMap['521|credit']);
        self::assertSame(
            $correction->debitTotalMinor,
            $correction->creditTotalMinor,
        );
    }

    /** Nesouhlasný rozpad koše se nesmí zaúčtovat — částky by se rozešly. */
    public function testInconsistentBasketSplitFailsClosed(): void
    {
        [$snapshot, $result] = $this->benefitBasketRevision(60_000, 30_000);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Rozpad koše osvobození');
        $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    /**
     * W7/Ú-07 — cestovní náhrada není mzda. Náhrada výdaje podle části sedmé
     * zákoníku práce patří na 512 (cestovné), ne na 521.
     */
    public function testTravelReimbursementPostsToTravelExpenseAccount(): void
    {
        [$snapshot, $result] = $this->travelRevision();

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $lineMap = $this->lineMap($preview->lines);

        self::assertSame(100_000, $lineMap['512|debit']);
        self::assertArrayNotHasKey('521|debit', $lineMap);
        self::assertSame(
            100_000,
            $lineMap['331|credit'],
            'protiúčet zůstává závazek vůči zaměstnanci — náhrada se vyplácí se mzdou',
        );
        self::assertSame($preview->debitTotalMinor, $preview->creditTotalMinor);
    }

    /**
     * 512 musí být v množině nákladových účtů hrubé mzdy, kterou reconciliace
     * čte z klíčů alokací `gross:%:debit` — jinak by kategorie `gross_wages`
     * hlásila rozdíl proti kontrolním součtům.
     */
    public function testTravelExpenseKeepsTheGrossAllocationKey(): void
    {
        [$snapshot, $result] = $this->travelRevision();

        $travel = array_values(array_filter(
            $this->builder->build(
                $snapshot,
                $result,
                $this->statutorySets(),
                PayrollAccountingDefaults::codes(),
            )->targetAllocations,
            static fn (array $allocation): bool =>
                $allocation['account_code'] === '512',
        ));

        self::assertCount(1, $travel);
        self::assertSame(
            'gross:employment:101:input:1:debit',
            $travel[0]['allocation_key'],
        );
    }

    /**
     * Odpočet zálohy na pracovní cestu (§ 183 ZP) vyrovná pohledávku za
     * zaměstnancem, na které záloha visí od výplaty z pokladny: MD 331 / D 335.
     * Náklad cestovného zůstává v plné výši nároku a odpočet nesmí nést klíč
     * `gross:`, jinak by ho reconciliace četla jako hrubou mzdu.
     */
    public function testTravelAdvanceOffsetClearsTheEmployeeReceivable(): void
    {
        [$snapshot, $result] = $this->travelRevision();
        $snapshot['people'][0]['employments'][0]['inputs'][] = [
            'id' => 4,
            'amount_minor' => -30_000,
            'component' => [
                'code' => BusinessTripMaterializer::COMPONENT_ADVANCE,
                'component_kind' => 'travel_reimbursement',
                'accounting_debit_code' => null,
                'accounting_credit_code' => null,
            ],
        ];
        $result['people'][0]['employments'][0]['inputs'][] = [
            'input_id' => 4,
            'totals' => [
                'source_amount_minor' => -30_000,
                'cash_payable_minor' => -30_000,
            ],
            'accounting' => [
                'debit_code' => null,
                'credit_code' => null,
                'amount_minor' => -30_000,
            ],
        ];
        $result['people'][0]['employments'][0]['totals']['cash_payable_minor'] = 70_000;
        $result['people'][0]['totals']['cash_payable_minor'] = 570_000;
        $result['people'][0]['payable_after_enforcement_minor'] = 436_000;
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $sets = $this->statutorySets();
        $sets['net_pay']['people'][0]['result_snapshot']['net_payable_minor_units'] = 441_000;

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $sets,
            PayrollAccountingDefaults::codes(),
        );
        $lineMap = $this->lineMap($preview->lines);

        self::assertSame(100_000, $lineMap['512|debit'], 'náklad cestovného zůstává celý');
        self::assertSame(30_000, $lineMap['335|credit']);
        self::assertSame($preview->debitTotalMinor, $preview->creditTotalMinor);
        $advance = array_values(array_filter(
            $preview->targetAllocations,
            static fn (array $allocation): bool => $allocation['account_code'] === '335',
        ));
        self::assertCount(1, $advance);
        self::assertStringStartsWith('travel-advance:', $advance[0]['allocation_key']);
    }

    /** Vlastní předkontace složky přebíjí i účet cestovného. */
    public function testExplicitComponentPairBeatsTheTravelAccount(): void
    {
        [$snapshot, $result] = $this->travelRevision();
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'accounting_debit_code'
        ] = '512.100';
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'accounting_credit_code'
        ] = '331.900';
        $result['people'][0]['employments'][0]['inputs'][0]['accounting'] = [
            'debit_code' => '512.100',
            'credit_code' => '331.900',
            'amount_minor' => 100_000,
        ];
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['512.100|debit']);
        self::assertArrayNotHasKey('512|debit', $lineMap);
    }

    /** Zmrazený snapshot bez klíče `travel_expense_debit` účtuje jako dřív. */
    public function testOlderSnapshotPostsTravelAsWageCost(): void
    {
        [$snapshot, $result] = $this->travelRevision();
        $legacyAccounts = PayrollAccountingDefaults::codes();
        unset($legacyAccounts['travel_expense_debit']);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            $legacyAccounts,
        )->lines);

        self::assertSame(100_000, $lineMap['521|debit']);
        self::assertArrayNotHasKey('512|debit', $lineMap);
    }

    /**
     * Zmrazený mzdový vstup v koši osvobození. Rozpad je ZMRAZENÝ (migrace
     * 1480), takže ho účetní můstek jen čte — nepřepočítává ho.
     *
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function benefitBasketRevision(
        int $exemptMinor,
        int $taxableMinor,
        string $basket = 'non_cash_leisure',
    ): array {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['inputs'][0]['benefit_basket'] =
            $basket;
        $snapshot['people'][0]['employments'][0]['inputs'][0]['benefit_exempt_minor'] =
            $exemptMinor;
        $snapshot['people'][0]['employments'][0]['inputs'][0]['benefit_taxable_minor'] =
            $taxableMinor;
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        return [$snapshot, $result];
    }

    /**
     * Revize s cestovní náhradou místo mzdy na prvním pracovním vztahu.
     *
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function travelRevision(): array
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'component_kind'
        ] = 'travel_reimbursement';
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        return [$snapshot, $result];
    }

    /** @return array<string,mixed> */
    private function riskySavingsRow(int $employmentId, int $contributionMinor): array
    {
        return [
            'employment_id' => $employmentId,
            'status' => 'calculated',
            'issues' => [],
            'assessment_base_minor' => $contributionMinor * 25,
            'contribution_minor' => $contributionMinor,
        ];
    }

    /**
     * Revize, kde je první vstup NEPENĚŽNÍ benefit bez předkontace.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function nonCashBenefitRevision(): array
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'value_kind'
        ] = 'non_monetary';
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][0]['inputs'][0]['totals'] = [
            'source_amount_minor' => 100_000,
            'cash_payable_minor' => 0,
        ];
        $result['people'][0]['employments'][0]['totals']['cash_payable_minor'] = 0;
        $result['people'][0]['totals']['cash_payable_minor'] = 500_000;
        $result['people'][0]['payable_after_enforcement_minor'] = 366_000;
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        return [$snapshot, $result];
    }

    /** @return array<string,array<string,mixed>> */
    private function nonCashBenefitStatutorySets(): array
    {
        $sets = $this->statutorySets();
        $sets['net_pay']['people'][0]['result_snapshot'][
            'net_payable_minor_units'
        ] = 371_000;

        return $sets;
    }

    /** @return array<string,mixed> */
    private function unpaidLeaveSnapshot(): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 1,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'people' => [[
                'employee' => ['id' => 21],
                'employments' => [[
                    'employment' => [
                        'id' => 201,
                        'employee_id' => 21,
                        'relation_type' => 'employment',
                    ],
                    'inputs' => [[
                        'id' => 9,
                        'amount_minor' => 0,
                        'component' => [
                            'code' => 'SLOZKA_9',
                            'accounting_debit_code' => null,
                            'accounting_credit_code' => null,
                        ],
                    ]],
                ]],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function unpaidLeaveResult(int $healthMinor = 5_000): array
    {
        return [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => $this->snapshotHash($this->unpaidLeaveSnapshot()),
            'statutory' => ['status' => 'calculated'],
            'people' => [[
                'employee_id' => 21,
                'totals' => ['cash_payable_minor' => 0],
                'employments' => [$this->resultEmployment(201, 9, 0)],
                'enforcement' => [
                    'result' => [
                        'status' => 'supported',
                        'total_withheld_minor_units' => 0,
                    ],
                ],
                'payable_after_enforcement_minor' => -$healthMinor,
            ]],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function unpaidLeaveStatutorySets(int $healthMinor = 5_000): array
    {
        $sets = $this->statutorySets();
        $sets['social_insurance']['result_snapshot'][
            'employer_contribution_minor_units'
        ] = 0;
        $sets['health_insurance']['result_snapshot'][
            'employer_contribution_minor_units'
        ] = 0;
        foreach (['social_insurance', 'health_insurance', 'income_tax', 'net_pay'] as $kind) {
            $sets[$kind]['people'][0]['employee_id'] = 21;
        }
        $sets['social_insurance']['people'][0]['result_snapshot'][
            'employee_contribution_minor_units'
        ] = 0;
        $sets['health_insurance']['people'][0]['result_snapshot'][
            'employee_contribution_minor_units'
        ] = $healthMinor;
        $sets['income_tax']['people'][0]['result_snapshot'] = [
            'advance_tax' => [
                'tax_after_credits_minor_units' => 0,
                'tax_bonus_minor_units' => 0,
            ],
            'withholding_tax_minor_units' => 0,
        ];
        $sets['net_pay']['people'][0]['result_snapshot'] = [
            'deducted_minor_units' => 0,
            'net_payable_minor_units' => -$healthMinor,
            'deductions' => [],
        ];

        return $sets;
    }

    /**
     * Ú-11: opravná revize, která zaměstnance JEN PŘEŘADÍ na jiné středisko.
     *
     * Částka i účet zůstávají stejné, mění se výhradně `cost_center`. Dokud
     * středisko nebylo součástí klíče rozdílového vektoru, vyšla delta nulově
     * a v deníku zůstalo navždy staré středisko.
     */
    public function testCostCentreChangeAloneProducesReclassificationDelta(): void
    {
        $snapshot = $this->snapshotWithCostCentres();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $first = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        $moved = $this->snapshotWithCostCentres();
        $moved['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'MONTAZ', null),
        ];
        $movedResult = $this->calculatedResult();
        $movedResult['source_snapshot_hash'] = $this->snapshotHash($moved);

        $correction = $this->builder->build(
            $moved,
            $movedResult,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
            $first->targetAllocations,
        );

        self::assertNotSame([], $correction->lines);
        self::assertSame(
            ['MONTAZ' => 100_000],
            $this->costCentreMap($correction->lines, '521', 'debit'),
        );
        self::assertSame(
            ['VYROBA' => 100_000],
            $this->costCentreMap($correction->lines, '521', 'credit'),
        );
        self::assertSame(
            $correction->debitTotalMinor,
            $correction->creditTotalMinor,
        );
        self::assertNotSame($first->targetHash, $correction->targetHash);
    }

    /**
     * Ú-19: daňový bonus OBRACÍ stranu zápisu daně — 342 MD proti závazku mzdy.
     * Všechny dosavadní fixtures měly bonus nulový, takže tahle větev nebyla
     * pokrytá vůbec.
     */
    public function testTaxBonusPostsTaxAccountOnTheDebitSide(): void
    {
        $preview = $this->builder->build(
            $this->snapshot(),
            $this->bonusResult(),
            $this->bonusStatutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $lineMap = $this->lineMap($preview->lines);

        // Záloha je nula, takže na 342 zůstane JEN bonus — a to na straně MD.
        self::assertSame(30_000, $lineMap['342.100|debit']);
        self::assertArrayNotHasKey('342.100|credit', $lineMap);
        // Protistrana bonusu jde do závazku mzdy poměrem peněžního příjmu
        // (100 000 : 500 000), takže zaměstnanci se čistá mzda ZVYŠUJE.
        self::assertSame(105_000, $lineMap['331|credit']);
        self::assertSame(525_000, $lineMap['366|credit']);
        self::assertSame(
            $preview->debitTotalMinor,
            $preview->creditTotalMinor,
        );
    }

    /**
     * Ú-13: srážková daň má vlastní účet, ne týž jako záloha.
     *
     * Do teď obě daně končily na `income_tax_credit` a rozlišoval je jen
     * `allocation_key`, který se do deníku nepromítá — saldo 342 tak neslo obě
     * daně slité, přestože se odvádějí dvěma platbami a platební vrstva je
     * rozlišuje.
     */
    public function testWithholdingTaxPostsToItsOwnAccount(): void
    {
        $sets = $this->statutorySets();
        $sets['income_tax']['people'][0]['result_snapshot']['advance_tax'][
            'tax_after_credits_minor_units'
        ] = 40_000;
        $sets['income_tax']['people'][0]['result_snapshot'][
            'withholding_tax_minor_units'
        ] = 10_000;

        $lineMap = $this->lineMap($this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $sets,
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(40_000, $lineMap['342.100|credit'], 'zálohová daň');
        self::assertSame(10_000, $lineMap['342.200|credit'], 'srážková daň');
    }

    /**
     * Zmrazený snapshot, který o rozpadu neví, se musí zaúčtovat BYTE-IDENTICKY
     * jako dřív — jinak by opakované zaúčtování dřív schválené revize spadlo na
     * kontrolu cílového otisku v PayrollPostingAdapter. Rozhoduje SUROVÁ sada
     * předkontací ze snapshotu, ne doplněné výchozí účty.
     */
    public function testOlderSnapshotKeepsBothTaxesOnTheAdvanceAccount(): void
    {
        $sets = $this->statutorySets();
        $sets['income_tax']['people'][0]['result_snapshot']['advance_tax'][
            'tax_after_credits_minor_units'
        ] = 40_000;
        $sets['income_tax']['people'][0]['result_snapshot'][
            'withholding_tax_minor_units'
        ] = 10_000;
        $accounts = PayrollAccountingDefaults::codes();
        unset($accounts['withholding_tax_credit']);

        $lineMap = $this->lineMap($this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $sets,
            $accounts,
        )->lines);

        self::assertSame(50_000, $lineMap['342.100|credit'], 'obě daně pohromadě');
        self::assertArrayNotHasKey('342.200|credit', $lineMap);
    }

    /**
     * Od roku 2027 je daň ze samostatného základu podle § 6 odst. 4 ZDP
     * zálohou (zák. č. 360/2025 Sb.), odvádí se se zálohami (§ 38hb) a patří na
     * účet zálohové daně, ne na analytiku srážkové daně 342.200.
     */
    public function testSeparateBaseAdvance2027PostsToTheAdvanceAccount(): void
    {
        $sets = $this->statutorySets();
        $person = &$sets['income_tax']['people'][0]['result_snapshot'];
        $person['advance_tax']['tax_after_credits_minor_units'] = 40_000;
        $person['withholding_tax_minor_units'] = 10_000;
        $person['calculation_date'] = '2027-01-31';
        $person['separate_base_regime'] = 'separate-advance';
        $person['separate_base_income_minor_units'] = 66_600;
        unset($person);

        $lines = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $sets,
            PayrollAccountingDefaults::codes(),
        )->lines;
        $lineMap = $this->lineMap($lines);

        self::assertSame(50_000, $lineMap['342.100|credit'], 'záloha i záloha ze samostatného základu');
        self::assertArrayNotHasKey('342.200|credit', $lineMap);
    }

    /**
     * Ú-19: ZÁPORNÁ delta opravné revize. Snížení pojistného musí obrátit obě
     * strany zápisu — 524 na D a 336.100 na MD — jinak by oprava směrem dolů
     * náklad ještě zvýšila.
     */
    public function testCorrectionThatLowersAmountReversesBothSides(): void
    {
        $first = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $sets = $this->statutorySets();
        $sets['social_insurance']['result_snapshot'][
            'employer_contribution_minor_units'
        ] = 138_800;

        $correction = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $sets,
            PayrollAccountingDefaults::codes(),
            $first->targetAllocations,
        );

        self::assertSame([
            '336.100|debit' => 10_000,
            '524|credit' => 10_000,
        ], $this->lineMap($correction->lines));
        self::assertSame(10_000, $correction->debitTotalMinor);
        self::assertSame(10_000, $correction->creditTotalMinor);
    }

    /**
     * Výsledek, kde záloha na daň vyšla nula a uplatnil se měsíční daňový bonus
     * 300 Kč. Čistá mzda je proto o bonus VYŠŠÍ než peněžní příjem po srážkách.
     *
     * @return array<string,mixed>
     */
    private function bonusResult(): array
    {
        $result = $this->calculatedResult();
        // 600 000 − 42 000 − 27 000 − 10 000 + 30 000 = 551 000
        $result['people'][0]['payable_after_enforcement_minor'] = 546_000;

        return $result;
    }

    /** @return array<string,array<string,mixed>> */
    private function bonusStatutorySets(): array
    {
        $sets = $this->statutorySets();
        $sets['income_tax']['people'][0]['result_snapshot']['advance_tax'] = [
            'tax_after_credits_minor_units' => 0,
            'tax_bonus_minor_units' => 30_000,
        ];
        $sets['net_pay']['people'][0]['result_snapshot'][
            'net_payable_minor_units'
        ] = 551_000;

        return $sets;
    }

    public function testCorrectionProducesOnlyBalancedDeltaAgainstPreviousTarget(): void
    {
        $first = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $sets = $this->statutorySets();
        $sets['social_insurance']['result_snapshot'][
            'employer_contribution_minor_units'
        ] = 158_800;

        $correction = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $sets,
            PayrollAccountingDefaults::codes(),
            $first->targetAllocations,
        );

        self::assertSame([
            '336.100|credit' => 10_000,
            '524|debit' => 10_000,
        ], $this->lineMap($correction->lines));
        self::assertSame(10_000, $correction->debitTotalMinor);
        self::assertSame(10_000, $correction->creditTotalMinor);
        self::assertNotSame($first->targetHash, $correction->targetHash);
    }

    public function testRejectsResultThatDoesNotReconcileToNetAndEnforcement(): void
    {
        $result = $this->calculatedResult();
        $result['people'][0]['payable_after_enforcement_minor']++;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('čistou výplatou');
        $this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    public function testPartnerSettlementClearsWagePayableAgainstShareholderAccount(): void
    {
        $snapshot = $this->snapshotWithSettlementRule();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        // Celá čistá výplata po exekucích (466 000) se přeúčtuje z mzdových
        // závazků na účet společníka. 331 i 366 tak jdou na nulu — přesně to,
        // proč se to dělá měsíčně místo ročního ručního zápočtu.
        self::assertSame([
            '331|credit' => 100_000,
            '331|debit' => 100_000,
            '336.100|credit' => 190_800,
            '336.200|credit' => 81_000,
            '342.100|credit' => 50_000,
            '365.100|credit' => 466_000,
            '366|credit' => 500_000,
            '366|debit' => 500_000,
            '379.100|credit' => 10_000,
            '379.200|credit' => 5_000,
            '521|debit' => 100_000,
            '522|debit' => 200_000,
            '523|debit' => 300_000,
            '524|debit' => 202_800,
        ], $this->lineMap($preview->lines));
        self::assertSame(1_402_800, $preview->debitTotalMinor);
        self::assertSame(1_402_800, $preview->creditTotalMinor);

        $settlement = array_values(array_filter(
            $preview->targetAllocations,
            static fn (array $allocation): bool => str_contains(
                $allocation['allocation_key'],
                ':partner-settlement:',
            ),
        ));
        self::assertSame(
            [['331', 77_667], ['366', 155_333], ['366', 233_000], ['365.100', -466_000]],
            array_map(
                static fn (array $allocation): array => [
                    $allocation['account_code'],
                    $allocation['signed_minor'],
                ],
                $settlement,
            ),
        );
        self::assertSame(0, array_sum(array_column($settlement, 'signed_minor')));
    }

    public function testPartnerSettlementRefusesOrdinaryEmployee(): void
    {
        $snapshot = $this->snapshotWithSettlementRule();
        foreach (array_keys($snapshot['people'][0]['employments']) as $index) {
            $snapshot['people'][0]['employments'][$index]['employment'][
                'relation_type'
            ] = 'employment';
        }
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Zápočtem na účet společníka');
        $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    public function testPayoutRulesWithoutSettlementLeavePostingUnchanged(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['payout_rules'] = [[
            'id' => 21,
            'allocation_reference' => 'cash',
            'destination_kind' => 'cash',
            'destination_reference' => null,
            'allocation_kind' => 'remainder',
            'amount_minor' => null,
            'basis_points' => null,
            'priority_no' => 10,
            'row_version' => 1,
        ]];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $baseline = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame(
            $this->lineMap($baseline->lines),
            $this->lineMap($preview->lines),
        );
        self::assertSame($baseline->targetHash, $preview->targetHash);
    }

    /** @return array<string,mixed> */
    private function snapshotWithSettlementRule(): array
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['payout_rules'] = [[
            'id' => 31,
            'allocation_reference' => 'partner-settlement',
            'destination_kind' => 'partner_settlement',
            'destination_reference' => '365.100',
            'allocation_kind' => 'remainder',
            'amount_minor' => null,
            'basis_points' => null,
            'priority_no' => 10,
            'row_version' => 1,
        ]];

        return $snapshot;
    }

    /**
     * @param list<array{
     *   account_code:string,
     *   side:'debit'|'credit',
     *   amount_minor:int,
     *   description:string
     * }> $lines
     * @return array<string,int>
     */
    private function lineMap(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $key = $line['account_code'] . '|' . $line['side'];
            $result[$key] = ($result[$key] ?? 0) + $line['amount_minor'];
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * `payroll_dimensions.default_account_code` se dal nastavit od migrace 1307,
     * validoval se ve službě i v DB — a zaúčtování ho nečetlo nikde. Uživatel
     * nastavil středisku nákladový účet a mzda se zaúčtovala na výchozí
     * předkontaci zaměstnavatele, bez jediného hlášení.
     */
    public function testDimensionDefaultAccountReplacesTheEmployerDefaultCostAccount(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'VYROBA', '521.100'),
        ];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['521.100|debit'], 'Náklad jde na účet střediska.');
        self::assertArrayNotHasKey('521|debit', $lineMap, 'Výchozí účet zaměstnavatele se už nepoužije.');
        self::assertSame(
            100_000,
            $lineMap['331|credit'],
            'Dimenze mění NÁKLAD, ne závazek vůči zaměstnanci.',
        );
        self::assertSame(
            202_800,
            $lineMap['524|debit'],
            'Zákonné odvody zaměstnavatele dimenze nepřebíjí — jeden kód na dvě nákladové skupiny nesedí.',
        );
    }

    /**
     * `default_account_code` je podle svého jména i podle komentáře migrace 1307
     * „analytika k VÝCHOZÍM kontacím". Explicitní volba u složky je konkrétnější
     * a musí vyhrát — jinak by dimenze tiše rozbila ručně nastavenou předkontaci.
     */
    public function testExplicitComponentAccountBeatsTheDimensionDefault(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'VYROBA', '521.100'),
        ];
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'accounting_debit_code'
        ] = '518.900';
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'accounting_credit_code'
        ] = '331.900';
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][0]['inputs'][0]['accounting'] = [
            'debit_code' => '518.900',
            'credit_code' => '331.900',
            'amount_minor' => 100_000,
        ];
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['518.900|debit']);
        self::assertArrayNotHasKey('521.100|debit', $lineMap);
    }

    /**
     * Vztah může mít středisko, zakázku i činnost současně a účet smí nést každá.
     * Pořadí musí být pevné, jinak by tytéž vstupy zaúčtovaly různě podle toho,
     * v jakém pořadí je někdo zadal.
     */
    public function testDimensionPriorityIsFixedCostCenterThenProjectThenActivity(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('activity', 'MONTAZ', '521.300'),
            $this->dimension('project', 'ZAKAZKA1', '521.200'),
            $this->dimension('cost_center', 'VYROBA', '521.100'),
        ];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['521.100|debit'], 'Středisko je primární nositel nákladové analytiky.');
        self::assertArrayNotHasKey('521.200|debit', $lineMap);
        self::assertArrayNotHasKey('521.300|debit', $lineMap);
    }

    /** Dimenze bez účtu je jen sledovací značka — na zaúčtování nesmí mít vliv. */
    public function testDimensionWithoutDefaultAccountFallsBackToTheEmployerDefault(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'VYROBA', null),
            $this->dimension('project', 'ZAKAZKA1', '521.200'),
        ];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(
            100_000,
            $lineMap['521.200|debit'],
            'Středisko účet nemá, takže rozhoduje zakázka.',
        );
    }

    /**
     * Revize zmrazené dřív, než dimenze začaly do snapshotu vstupovat, klíč
     * `dimensions` vůbec nemají. Nesmí se přeúčtovat jinak než původně — proto
     * absence znamená „žádná dimenze", ne dohledání dnešního přiřazení.
     */
    public function testHistoricalSnapshotWithoutDimensionsPostsExactlyAsBefore(): void
    {
        $snapshot = $this->snapshot();
        self::assertArrayNotHasKey('dimensions', $snapshot['people'][0]['employments'][0]);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['521|debit']);
    }

    /** Nesmyslný účet v dimenzi zaúčtování zastaví, místo aby ho zapsal do deníku. */
    public function testInvalidDimensionAccountIsRejected(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'VYROBA', 'nesmysl'),
        ];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $this->expectException(\DomainException::class);
        $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    /** Účet jiné mzdové kategorie nesmí dimenze podsunout jako hrubou mzdu. */
    public function testReservedPayrollAccountCannotBeUsedAsDimensionCostAccount(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'VYROBA', '524'),
        ];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('kolizní');
        $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    /** Stejná ochrana platí i pro výslovný nákladový účet mzdové složky. */
    public function testReservedPayrollAccountCannotBeUsedAsExplicitGrossDebit(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'accounting_debit_code'
        ] = '524';
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'accounting_credit_code'
        ] = '331';
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][0]['inputs'][0]['accounting'] = [
            'debit_code' => '524',
            'credit_code' => '331',
            'amount_minor' => 100_000,
        ];
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('kolizní');
        $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    /**
     * `04-UCETNI-MUSTEK.md` slibuje analytiku 524 podle střediska; kód účtoval
     * jednu firemní dvojici 524/336 a středisko neznal vůbec.
     *
     * Dělí se jen NÁKLAD. Závazek vůči ČSSZ/pojišťovně zůstává jednou částkou —
     * pojistné zaměstnavatele je podle § 5a odst. 1 z. č. 589/1992 Sb. firemní
     * veličina z úhrnu základů, takže podíl střediska je alokace, ne osobní
     * zákonná částka. Součet alokací proto musí sednout na závazek na korunu.
     */
    public function testEmployerInsuranceCostSplitsByCostCentreAndMatchesTheLiability(): void
    {
        $snapshot = $this->snapshotWithCostCentres();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame([
            '' => 101_400,
            'SPRAVA' => 67_600,
            'VYROBA' => 33_800,
        ], $this->costCentreMap($preview->lines, '524', 'debit'));
        self::assertSame(
            202_800,
            array_sum($this->costCentreMap($preview->lines, '524', 'debit')),
            'Součet nákladu po střediscích = závazek vůči ČSSZ a pojišťovně.',
        );
        self::assertSame(
            ['' => 190_800],
            $this->costCentreMap($preview->lines, '336.100', 'credit'),
            'Závazek se na střediska nedělí — dluží se jako celek.',
        );
        self::assertSame($preview->debitTotalMinor, $preview->creditTotalMinor);
    }

    /**
     * Zbytek po celočíselném dělení nesmí zmizet ani vzniknout: podíly by daly
     * o korunu míň a účetní můstek by se rozešel s platbou.
     */
    public function testEmployerInsuranceSplitKeepsTheOddHallerOnTheLiability(): void
    {
        $snapshot = $this->snapshotWithCostCentres();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $sets = $this->statutorySetsWithRelationships();
        $sets['social_insurance']['result_snapshot'][
            'employer_contribution_minor_units'
        ] = 148_801;
        $sets['social_insurance']['result_snapshot'][
            'employer_contribution_before_discount_minor_units'
        ] = 148_801;
        $sets['social_insurance']['result_snapshot']['employer_categories'][0][
            'contribution_minor_units'
        ] = 148_801;

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $sets,
            PayrollAccountingDefaults::codes(),
        );

        $split = $this->costCentreMap($preview->lines, '524', 'debit');
        self::assertSame(202_801, array_sum($split));
        self::assertSame([
            // 148 801 × 3/6 = 74 400,5 — největší zbytek bere haléř navíc.
            '' => 101_401,
            'SPRAVA' => 67_600,
            'VYROBA' => 33_800,
        ], $split);
    }

    /**
     * § 5a odst. 1 dělá ze zaměstnanců tři vyměřovací základy a § 7 odst. 1 na
     * každý pouští jinou sazbu. Rozpustit firemní součet poměrem VŠECH základů
     * by středisku se sazbou 24,8 % přisoudilo pojistné vzniklé sazbou 29,8 %.
     */
    public function testEmployerSocialSplitStaysInsideItsRateCategory(): void
    {
        $snapshot = $this->snapshotWithCostCentres();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $sets = $this->statutorySetsWithRelationships();
        // 101 (VYROBA) je záchranář se sazbou 29,8 %, zbytek běžná sazba.
        $sets['social_insurance']['people'][0]['relationships'][0]['result_snapshot'][
            'employer_rate_category'
        ] = 'rescue_and_company_fire_service';
        $sets['social_insurance']['result_snapshot']['employer_categories'] = [
            ['category' => 'ordinary', 'contribution_minor_units' => 124_000],
            [
                'category' => 'rescue_and_company_fire_service',
                'contribution_minor_units' => 29_800,
            ],
        ];
        $sets['social_insurance']['result_snapshot'][
            'employer_contribution_before_discount_minor_units'
        ] = 153_800;
        $sets['social_insurance']['result_snapshot'][
            'employer_contribution_minor_units'
        ] = 153_800;

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $sets,
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame([
            // 124 000 × 300 000/500 000 + 27 000 zdravotního.
            '' => 101_400,
            // 124 000 × 200 000/500 000 + 18 000 zdravotního.
            'SPRAVA' => 67_600,
            // 29 800 celé z kategorie b) + 9 000 zdravotního.
            'VYROBA' => 38_800,
        ], $this->costCentreMap($preview->lines, '524', 'debit'));
        self::assertSame(
            207_800,
            array_sum($this->costCentreMap($preview->lines, '524', 'debit')),
        );
    }

    /** Středisko dimenze se musí projevit i na nákladu hrubé mzdy, ne jen na 524. */
    public function testGrossCostLineCarriesTheCostCentreOfTheEmployment(): void
    {
        $snapshot = $this->snapshotWithCostCentres();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame(
            ['VYROBA' => 100_000],
            $this->costCentreMap($preview->lines, '521', 'debit'),
        );
        self::assertSame(
            ['' => 100_000],
            $this->costCentreMap($preview->lines, '331', 'credit'),
            'Středisko říká, kam patří NÁKLAD, ne komu se dluží.',
        );
    }

    /**
     * Revize bez dimenzí se nesmí zaúčtovat jinak než dosud — jinak by se
     * zaúčtovaná revize při opakovaném zaúčtování ohlásila jiným cílovým otiskem.
     */
    public function testRevisionWithoutCostCentresKeepsTheSingleEmployerInsurancePair(): void
    {
        $withRelationships = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );
        $baseline = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame($baseline->targetHash, $withRelationships->targetHash);
        self::assertSame(
            ['' => 202_800],
            $this->costCentreMap($withRelationships->lines, '524', 'debit'),
        );
    }

    /**
     * Zákonný výsledek bez rozpadu na vztahy (revize zmrazené dřív) rozdělit
     * nejde. Zastavit kvůli tomu schválení běhu by ale byla regrese — středisko
     * je analytika navíc, ne podmínka zaúčtování.
     */
    public function testCostCentreWithoutRelationshipResultsPostsOneUnsplitLine(): void
    {
        $snapshot = $this->snapshotWithCostCentres();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame(
            ['' => 202_800],
            $this->costCentreMap($preview->lines, '524', 'debit'),
        );
    }

    /**
     * F3 (mzdy na firemní dimenze) je opt-in: bez vazby na firemní dimenzi
     * a bez procentního rozpadu musí vyjít alokace i řádky BAJTOVĚ stejně
     * jako před ní. Otisky jsou spočtené kódem před zavedením F3 — jakákoli
     * změna tvaru alokací (nový klíč, jiné pořadí, jiný klíč seskupení) by
     * zaúčtovanou revizi při opakovaném zaúčtování odmítla jiným otiskem.
     */
    public function testPostingWithoutCompanyDimensionsIsByteIdenticalToBeforeF3(): void
    {
        $plain = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );
        $snapshot = $this->snapshotWithCostCentres();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $withCostCentres = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame(
            [
                self::PRE_F3_PLAIN_TARGET_HASH,
                self::PRE_F3_PLAIN_DELTA_HASH,
                self::PRE_F3_COST_CENTRE_TARGET_HASH,
                self::PRE_F3_COST_CENTRE_DELTA_HASH,
            ],
            [
                $plain->targetHash,
                $plain->deltaHash,
                $withCostCentres->targetHash,
                $withCostCentres->deltaHash,
            ],
        );
    }

    /**
     * Tentýž otisk přes všechny cesty, které F3 přepojila na `addCostPair()`:
     * cestovní náhrada (512), nedaňová část benefitu podle § 25 odst. 1
     * písm. h) ZDP (528), příspěvek na spoření u rizikové práce (527),
     * výslovná předkontace složky (528 / 366.523) a stavba proti předchozím
     * alokacím (opravná dávka). Otisky spočtené kódem před F3.
     */
    public function testEveryCostPathWithoutCompanyDimensionsIsByteIdenticalToBeforeF3(): void
    {
        [$snapshot, $result] = $this->everyCostPathRevision(true);
        $sets = $this->statutorySetsWithRelationships();
        $target = $this->builder->build(
            $snapshot,
            $result,
            $sets,
            PayrollAccountingDefaults::codes(),
        );
        [$plainSnapshot, $plainResult] = $this->everyCostPathRevision(false);
        $previous = $this->builder->build(
            $plainSnapshot,
            $plainResult,
            $sets,
            PayrollAccountingDefaults::codes(),
        );
        $correction = $this->builder->build(
            $snapshot,
            $result,
            $sets,
            PayrollAccountingDefaults::codes(),
            $previous->targetAllocations,
        );

        self::assertSame(
            [
                self::PRE_F3_EVERY_PATH_TARGET_HASH,
                self::PRE_F3_EVERY_PATH_DELTA_HASH,
                self::PRE_F3_EVERY_PATH_CORRECTION_HASH,
            ],
            [$target->targetHash, $target->deltaHash, $correction->deltaHash],
        );
    }

    /**
     * Zaúčtování po zaměstnancích zmrazené ve snapshotu rozdělí náklad 524 na
     * pracovní vztahy i bez středisek. Snapshot bez volby (souhrnně) dává dál
     * byte-identické otisky jako dřív.
     */
    public function testPostingByEmployeeSplitsEmployerInsuranceOnlyWhenFrozen(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['employer'] = ['posting_granularity' => 'employee'];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $byEmployee = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );
        $keys = array_column($byEmployee->targetAllocations, 'allocation_key');
        self::assertContains('employer-insurance:social:employment:101:debit', $keys);
        self::assertContains('employer-insurance:health:employment:103:debit', $keys);
        self::assertNotContains('employer-insurance:social:debit', $keys);

        $summary = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );
        self::assertSame(
            [self::PRE_F3_PLAIN_TARGET_HASH, self::PRE_F3_PLAIN_DELTA_HASH],
            [$summary->targetHash, $summary->deltaHash],
        );
        self::assertSame($this->lineTotals($summary->lines), $this->lineTotals($byEmployee->lines));
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,int> účet|strana => haléře
     */
    private function lineTotals(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $key = $line['account_code'] . '|' . $line['side'];
            $map[$key] = ($map[$key] ?? 0) + $line['amount_minor'];
        }
        ksort($map);

        return $map;
    }

    private const PRE_F3_EVERY_PATH_TARGET_HASH = '5d0b8703cbcdef5c84af0b47f061a6c9a7109c4ea058f20b9069277e9666fb34';
    private const PRE_F3_EVERY_PATH_DELTA_HASH = '07b9765b167129a27fc6c49f2877afc6f8fec1f86749b6513dc4786e5377c1cc';
    private const PRE_F3_EVERY_PATH_CORRECTION_HASH = '9fd4870ad7ac306252552a65cd630916f5a80c71ca117e352d8c05ad40688d19';

    /**
     * Revize se všemi nákladovými cestami: 101 cestovné, 102 benefit v koši
     * osvobození, 103 výslovná předkontace, k tomu rizikové spoření 101 a 103.
     *
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function everyCostPathRevision(bool $withCostCentres): array
    {
        $snapshot = $withCostCentres ? $this->snapshotWithCostCentres() : $this->snapshot();
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component']['component_kind'] =
            'travel_reimbursement';
        $snapshot['people'][0]['employments'][1]['inputs'][0]['benefit_basket'] = 'non_cash_leisure';
        $snapshot['people'][0]['employments'][1]['inputs'][0]['benefit_exempt_minor'] = 120_000;
        $snapshot['people'][0]['employments'][1]['inputs'][0]['benefit_taxable_minor'] = 80_000;
        $snapshot['people'][0]['employments'][2]['inputs'][0]['component']['accounting_debit_code'] = '528';
        $snapshot['people'][0]['employments'][2]['inputs'][0]['component']['accounting_credit_code'] = '366.523';
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][2]['inputs'][0]['accounting'] = [
            'debit_code' => '528',
            'credit_code' => '366.523',
            'amount_minor' => 300_000,
        ];
        $result['statutory']['risky_savings_period_start'] = '2026-06-01';
        $result['statutory']['risky_savings'] = [
            $this->riskySavingsRow(101, 4_000),
            $this->riskySavingsRow(103, 2_001),
        ];
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        return [$snapshot, $result];
    }

    private const PRE_F3_PLAIN_TARGET_HASH = '83f9879b7d1982ffa5879edad388eb311cdf07cec6963a5601557080395d4440';
    private const PRE_F3_PLAIN_DELTA_HASH = 'a85dc49650d9ab963b062fe9b74c6ec86685e7a2fffc4d6da68276a65ba96b89';
    private const PRE_F3_COST_CENTRE_TARGET_HASH = '974b145d7ba5e1fd38ce504670cf1593f261fba60a38376a109c2b5674bc3b4f';
    private const PRE_F3_COST_CENTRE_DELTA_HASH = 'ced8875b9c68a894b59b94241d0194f98aceccabd2fa8d190e8dc11b212eec81';

    /**
     * Vazba mzdového střediska na firemní dimenzi jen přidá řádkům dimenzi,
     * částky ani účty nemění. Závazky (331, 336) dimenzi nenesou.
     */
    public function testLinkedCompanyDimensionTagsOnlyCostLines(): void
    {
        $snapshot = $this->snapshotWithCostCentres();
        $snapshot['people'][0]['employments'][0]['dimensions'][0] += [
            'dimension_type_id' => 7,
            'dimension_value_id' => 71,
        ];
        $snapshot['people'][0]['employments'][1]['dimensions'][0] += [
            'dimension_type_id' => 7,
            'dimension_value_id' => 72,
        ];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame(
            ['7:71' => 100_000],
            $this->dimensionMap($preview->lines, '521', 'debit'),
        );
        self::assertSame(
            ['' => 101_400, '7:71' => 33_800, '7:72' => 67_600],
            $this->dimensionMap($preview->lines, '524', 'debit'),
        );
        self::assertSame(['' => 100_000], $this->dimensionMap($preview->lines, '331', 'credit'));
        self::assertSame(['' => 190_800], $this->dimensionMap($preview->lines, '336.100', 'credit'));
        self::assertSame($preview->debitTotalMinor, $preview->creditTotalMinor);
    }

    /**
     * 70 / 30 na dvě střediska: hrubá mzda i pojistné zaměstnavatele (524)
     * se rozpadnou podle podílů, součty sedí na haléř a každá část nese
     * středisko i firemní dimenzi svého podílu. Závazek se nedělí.
     */
    public function testSeventyThirtySplitDividesWagesAndEmployerInsurance(): void
    {
        $snapshot = $this->snapshotWithSplit(7_000, 3_000);
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame(
            ['STR-A' => 70_000, 'STR-B' => 30_000],
            $this->costCentreMap($preview->lines, '521', 'debit'),
        );
        self::assertSame(
            ['7:71' => 70_000, '7:72' => 30_000],
            $this->dimensionMap($preview->lines, '521', 'debit'),
        );
        // Vztah 101 nese 33 800 pojistného; 70 % = 23 660, 30 % = 10 140.
        self::assertSame(
            ['' => 169_000, '7:71' => 23_660, '7:72' => 10_140],
            $this->dimensionMap($preview->lines, '524', 'debit'),
        );
        self::assertSame(202_800, array_sum($this->dimensionMap($preview->lines, '524', 'debit')));
        self::assertSame(['' => 100_000], $this->dimensionMap($preview->lines, '331', 'credit'));
        self::assertSame(['' => 190_800], $this->dimensionMap($preview->lines, '336.100', 'credit'));
        self::assertSame($preview->debitTotalMinor, $preview->creditTotalMinor);
    }

    /** Haléř, který po dělení zbude, dostane podíl s největším zbytkem. */
    public function testSplitKeepsTheOddHallerOnTheLargestRemainder(): void
    {
        $snapshot = $this->snapshotWithSplit(3_333, 6_667);
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame(
            ['7:71' => 33_330, '7:72' => 66_670],
            $this->dimensionMap($preview->lines, '521', 'debit'),
        );
        // 33 800 × 33,33 % = 11 265,54 a × 66,67 % = 22 534,46 — haléř navíc
        // dostane první podíl (zbytek 0,54 > 0,46), součet zůstává 33 800.
        self::assertSame(
            ['' => 169_000, '7:71' => 11_266, '7:72' => 22_534],
            $this->dimensionMap($preview->lines, '524', 'debit'),
        );
    }

    /** Podíly, které nedají 100 %, by část nákladu tiše ztratily. */
    public function testSplitThatDoesNotSumToHundredPercentFailsClosed(): void
    {
        $snapshot = $this->snapshotWithSplit(7_000, 2_000);
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nedávají dohromady 100 %');
        $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );
    }

    /**
     * Opravná revize, která jen změní podíly, odúčtuje staré části a zaúčtuje
     * nové — rozdíl je čistě přeúčtování mezi středisky, celkový náklad stojí.
     */
    public function testChangedSplitInCorrectionReclassifiesOnlyTheShares(): void
    {
        $before = $this->snapshotWithSplit(7_000, 3_000);
        $beforeResult = $this->calculatedResult();
        $beforeResult['source_snapshot_hash'] = $this->snapshotHash($before);
        $previous = $this->builder->build(
            $before,
            $beforeResult,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );

        $after = $this->snapshotWithSplit(5_000, 5_000);
        $afterResult = $this->calculatedResult();
        $afterResult['source_snapshot_hash'] = $this->snapshotHash($after);
        $correction = $this->builder->build(
            $after,
            $afterResult,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
            $previous->targetAllocations,
        );

        $net = [];
        foreach ($correction->lines as $line) {
            $key = $line['account_code'] . '|' . self::dimensionsKey($line['dimensions'] ?? []);
            $net[$key] = ($net[$key] ?? 0)
                + ($line['side'] === 'debit' ? $line['amount_minor'] : -$line['amount_minor']);
        }
        ksort($net);
        self::assertSame([
            '521|7:71' => -20_000,
            '521|7:72' => 20_000,
            '524|7:71' => -6_760,
            '524|7:72' => 6_760,
        ], $net);
    }

    /**
     * Rozpad dvou typů najednou (středisko 70/30 × zakázka 50/50) dá čtyři
     * části s vahami 35/35/15/15 a každá nese obě firemní dimenze.
     */
    public function testSplitOfTwoTypesCombinesAsACartesianProduct(): void
    {
        $snapshot = $this->snapshotWithSplit(7_000, 3_000);
        $snapshot['people'][0]['employments'][0]['dimensions'][] =
            $this->dimension('project', 'ZAK-1', null)
            + ['share_bp' => 5_000, 'dimension_type_id' => 8, 'dimension_value_id' => 81];
        $snapshot['people'][0]['employments'][0]['dimensions'][] =
            $this->dimension('project', 'ZAK-2', null)
            + ['share_bp' => 5_000, 'dimension_type_id' => 8, 'dimension_value_id' => 82];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame([
            '7:71,8:81' => 35_000,
            '7:71,8:82' => 35_000,
            '7:72,8:81' => 15_000,
            '7:72,8:82' => 15_000,
        ], $this->dimensionMap($preview->lines, '521', 'debit'));
        self::assertSame(
            ['STR-A' => 70_000, 'STR-B' => 30_000],
            $this->costCentreMap($preview->lines, '521', 'debit'),
        );
        self::assertSame([
            '' => 169_000,
            '7:71,8:81' => 11_830,
            '7:71,8:82' => 11_830,
            '7:72,8:81' => 5_070,
            '7:72,8:82' => 5_070,
        ], $this->dimensionMap($preview->lines, '524', 'debit'));
        self::assertSame($preview->debitTotalMinor, $preview->creditTotalMinor);
    }

    /**
     * Záporná složka (oprava minulého měsíce) se rozdělí stejně jako kladná,
     * jen se znaménkem: −30 001 na 70/30 = −21 001 / −9 000 (haléř navíc
     * dostane podíl s větším zbytkem). Náklad po střediscích sedí na haléř.
     */
    public function testNegativeAmountSplitsLikeAPositiveOne(): void
    {
        $snapshot = $this->snapshotWithSplit(7_000, 3_000);
        $snapshot['people'][0]['employments'][0]['inputs'][] = [
            'id' => 4,
            'amount_minor' => -30_001,
            'component' => [
                'code' => 'OPRAVA',
                'accounting_debit_code' => null,
                'accounting_credit_code' => null,
            ],
        ];
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][0]['inputs'][] = [
            'input_id' => 4,
            'totals' => ['source_amount_minor' => -30_001, 'cash_payable_minor' => -30_001],
            'accounting' => ['debit_code' => null, 'credit_code' => null, 'amount_minor' => -30_001],
        ];
        $result['people'][0]['employments'][0]['totals']['cash_payable_minor'] = 69_999;
        $result['people'][0]['totals']['cash_payable_minor'] = 569_999;
        $result['people'][0]['payable_after_enforcement_minor'] = 435_999;
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $sets = $this->statutorySetsWithRelationships();
        $sets['net_pay']['people'][0]['result_snapshot']['net_payable_minor_units'] = 440_999;

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $sets,
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame(
            ['7:71' => 21_001, '7:72' => 9_000],
            $this->dimensionMap($preview->lines, '521', 'credit'),
        );
        self::assertSame(
            ['7:71' => 70_000, '7:72' => 30_000],
            $this->dimensionMap($preview->lines, '521', 'debit'),
        );
        self::assertSame($preview->debitTotalMinor, $preview->creditTotalMinor);
    }

    /**
     * Opravná dávka nad dávkou zaúčtovanou bez dimenzí: náklad se jen
     * přeúčtuje z „bez dimenze" na střediska — nezdvojí se ani neztratí.
     * Závazky (331, 336) se nemění, takže na nich nevznikne žádný řádek.
     */
    public function testCorrectionFromBatchWithoutDimensionsOnlyReclassifiesTheCost(): void
    {
        $previous = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
        );
        $snapshot = $this->snapshotWithSplit(7_000, 3_000);
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $correction = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySetsWithRelationships(),
            PayrollAccountingDefaults::codes(),
            $previous->targetAllocations,
        );

        $net = [];
        foreach ($correction->lines as $line) {
            $key = $line['account_code'] . '|' . self::dimensionsKey($line['dimensions'] ?? []);
            $net[$key] = ($net[$key] ?? 0)
                + ($line['side'] === 'debit' ? $line['amount_minor'] : -$line['amount_minor']);
        }
        ksort($net);
        self::assertSame([
            '521|' => -100_000,
            '521|7:71' => 70_000,
            '521|7:72' => 30_000,
            '524|' => -33_800,
            '524|7:71' => 23_660,
            '524|7:72' => 10_140,
        ], $net);
        foreach ($correction->lines as $line) {
            self::assertFalse(
                str_starts_with($line['account_code'], '331') || str_starts_with($line['account_code'], '336'),
                'Závazky se přeřazením na střediska nemění.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function snapshotWithSplit(int $firstShareBp, int $secondShareBp): array
    {
        $snapshot = $this->snapshotWithCostCentres();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'STR-A', null) + [
                'share_bp' => $firstShareBp,
                'dimension_type_id' => 7,
                'dimension_value_id' => 71,
            ],
            $this->dimension('cost_center', 'STR-B', null) + [
                'share_bp' => $secondShareBp,
                'dimension_type_id' => 7,
                'dimension_value_id' => 72,
            ],
        ];
        $snapshot['people'][0]['employments'][1]['dimensions'] = [];

        return $snapshot;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,int> vektor firemních dimenzí („typ:hodnota") → částka
     */
    private function dimensionMap(array $lines, string $account, string $side): array
    {
        $result = [];
        foreach ($lines as $line) {
            if ($line['account_code'] !== $account || $line['side'] !== $side) {
                continue;
            }
            $key = self::dimensionsKey($line['dimensions'] ?? []);
            $result[$key] = ($result[$key] ?? 0) + $line['amount_minor'];
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @param array<int,int> $dimensions */
    private static function dimensionsKey(array $dimensions): string
    {
        $pairs = [];
        foreach ($dimensions as $typeId => $valueId) {
            $pairs[] = "{$typeId}:{$valueId}";
        }

        return implode(',', $pairs);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,int>
     */
    private function costCentreMap(
        array $lines,
        string $account,
        string $side,
        bool $sort = true,
    ): array {
        $result = [];
        foreach ($lines as $line) {
            if ($line['account_code'] !== $account || $line['side'] !== $side) {
                continue;
            }
            $key = (string) ($line['cost_center'] ?? '');
            $result[$key] = ($result[$key] ?? 0) + $line['amount_minor'];
        }
        if ($sort) {
            ksort($result, SORT_STRING);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function snapshotWithCostCentres(): array
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'VYROBA', null),
        ];
        $snapshot['people'][0]['employments'][1]['dimensions'] = [
            $this->dimension('cost_center', 'SPRAVA', null),
        ];

        return $snapshot;
    }

    /**
     * Zákonné sady s rozpadem na pracovní vztahy — tak, jak je vrací
     * `PayrollStatutoryResultRepository::find()`.
     *
     * @return array<string,array<string,mixed>>
     */
    private function statutorySetsWithRelationships(): array
    {
        $sets = $this->statutorySets();
        $sets['social_insurance']['result_snapshot'] += [
            'employer_contribution_before_discount_minor_units' => 148_800,
            'part_time_discount_minor_units' => 0,
            'employer_categories' => [
                ['category' => 'ordinary', 'contribution_minor_units' => 148_800],
            ],
        ];
        $sets['social_insurance']['people'][0]['relationships'] = [
            $this->relationship(101, [
                'capped_assessment_base_minor_units' => 100_000,
                'employer_rate_category' => 'ordinary',
                'part_time_employer_discount' => 'not_claimed',
            ]),
            $this->relationship(102, [
                'capped_assessment_base_minor_units' => 200_000,
                'employer_rate_category' => 'ordinary',
                'part_time_employer_discount' => 'not_claimed',
            ]),
            $this->relationship(103, [
                'capped_assessment_base_minor_units' => 300_000,
                'employer_rate_category' => 'ordinary',
                'part_time_employer_discount' => 'not_claimed',
            ]),
        ];
        $sets['health_insurance']['people'][0]['result_snapshot'][
            'employer_contribution_minor_units'
        ] = 54_000;
        $sets['health_insurance']['people'][0]['relationships'] = [
            $this->relationship(101, [
                'participating_assessment_base_minor_units' => 100_000,
            ]),
            $this->relationship(102, [
                'participating_assessment_base_minor_units' => 200_000,
            ]),
            $this->relationship(103, [
                'participating_assessment_base_minor_units' => 300_000,
            ]),
        ];

        return $sets;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function relationship(int $employmentId, array $snapshot): array
    {
        return [
            'employment_id' => $employmentId,
            'result_status' => 'calculated',
            'result_snapshot' => $snapshot,
        ];
    }

    /** @return array<string,mixed> */
    private function dimension(string $type, string $code, ?string $account): array
    {
        return [
            'type' => $type,
            'code' => $code,
            'name' => $code,
            'default_account_code' => $account,
        ];
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 1,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'people' => [[
                'employee' => ['id' => 11],
                'employments' => [
                    $this->snapshotEmployment(101, 'employment', 1, 100_000),
                    $this->snapshotEmployment(102, 'partner_dependent', 2, 200_000),
                    $this->snapshotEmployment(103, 'statutory_body', 3, 300_000),
                ],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotEmployment(
        int $employmentId,
        string $relationType,
        int $inputId,
        int $amountMinor,
    ): array {
        return [
            'employment' => [
                'id' => $employmentId,
                'employee_id' => 11,
                'relation_type' => $relationType,
            ],
            'inputs' => [[
                'id' => $inputId,
                'amount_minor' => $amountMinor,
                'component' => [
                    'code' => "SLOZKA_{$inputId}",
                    'accounting_debit_code' => null,
                    'accounting_credit_code' => null,
                ],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function calculatedResult(): array
    {
        return [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => $this->snapshotHash($this->snapshot()),
            'statutory' => ['status' => 'calculated'],
            'people' => [[
                'employee_id' => 11,
                'totals' => ['cash_payable_minor' => 600_000],
                'employments' => [
                    $this->resultEmployment(101, 1, 100_000),
                    $this->resultEmployment(102, 2, 200_000),
                    $this->resultEmployment(103, 3, 300_000),
                ],
                'enforcement' => [
                    'result' => [
                        'status' => 'supported',
                        'total_withheld_minor_units' => 5_000,
                    ],
                ],
                'payable_after_enforcement_minor' => 466_000,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function resultEmployment(
        int $employmentId,
        int $inputId,
        int $amountMinor,
    ): array {
        return [
            'employment_id' => $employmentId,
            'totals' => ['cash_payable_minor' => $amountMinor],
            'inputs' => [[
                'input_id' => $inputId,
                'totals' => [
                    'source_amount_minor' => $amountMinor,
                    'cash_payable_minor' => $amountMinor,
                ],
                'accounting' => [
                    'debit_code' => null,
                    'credit_code' => null,
                    'amount_minor' => $amountMinor,
                ],
            ]],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function statutorySets(): array
    {
        return [
            'social_insurance' => [
                'result_status' => 'calculated',
                'result_snapshot' => [
                    'employer_contribution_minor_units' => 148_800,
                ],
                'people' => [[
                    'employee_id' => 11,
                    'result_status' => 'calculated',
                    'result_snapshot' => [
                        'employee_contribution_minor_units' => 42_000,
                    ],
                ]],
            ],
            'health_insurance' => [
                'result_status' => 'calculated',
                'result_snapshot' => [
                    'employer_contribution_minor_units' => 54_000,
                ],
                'people' => [[
                    'employee_id' => 11,
                    'result_status' => 'calculated',
                    'result_snapshot' => [
                        'employee_contribution_minor_units' => 27_000,
                    ],
                ]],
            ],
            'income_tax' => [
                'result_status' => 'calculated',
                'result_snapshot' => ['status' => 'calculated'],
                'people' => [[
                    'employee_id' => 11,
                    'result_status' => 'calculated',
                    'result_snapshot' => [
                        'advance_tax' => [
                            'tax_after_credits_minor_units' => 50_000,
                            'tax_bonus_minor_units' => 0,
                        ],
                        'withholding_tax_minor_units' => 0,
                    ],
                ]],
            ],
            'net_pay' => [
                'result_status' => 'calculated',
                'result_snapshot' => ['status' => 'calculated'],
                'people' => [[
                    'employee_id' => 11,
                    'result_status' => 'calculated',
                    'result_snapshot' => [
                        'deducted_minor_units' => 10_000,
                        'net_payable_minor_units' => 471_000,
                        'deductions' => [[
                            'deduction_reference' => 'agreement:7',
                            'applied_minor_units' => 10_000,
                        ]],
                    ],
                ]],
            ],
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function snapshotHash(array $snapshot): string
    {
        return hash(
            'sha256',
            \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode($snapshot),
        );
    }

    /**
     * Jednoduchá forma zápisu: předpis po dvojicích předkontací. Součty po účtech,
     * stranách, střediscích a dimenzích musí sedět na složené řádky, párovač je
     * převezme beze zbytku a každá dvojice má dva různé účty.
     *
     * @return iterable<string, array{\Closure(self):array{0:array<string,mixed>,1:list<array<string,mixed>>}}>
     */
    public static function singleEntryScenarios(): iterable
    {
        $codes = PayrollAccountingDefaults::codes();
        yield 'základní předpis' => [static fn (self $t): array => [
            $t->builder->build($t->snapshot(), $t->calculatedResult(), $t->statutorySets(), $codes), [],
        ]];
        yield 'střediska a rozpad 524' => [static function (self $t) use ($codes): array {
            $snapshot = $t->snapshotWithCostCentres();
            $result = $t->calculatedResult();
            $result['source_snapshot_hash'] = $t->snapshotHash($snapshot);
            return [$t->builder->build($snapshot, $result, $t->statutorySetsWithRelationships(), $codes), []];
        }];
        yield 'procentní rozpad 70/30' => [static function (self $t) use ($codes): array {
            $snapshot = $t->snapshotWithSplit(7_000, 3_000);
            $result = $t->calculatedResult();
            $result['source_snapshot_hash'] = $t->snapshotHash($snapshot);
            return [$t->builder->build($snapshot, $result, $t->statutorySetsWithRelationships(), $codes), []];
        }];
        yield 'daňový bonus' => [static fn (self $t): array => [
            $t->builder->build($t->snapshot(), $t->bonusResult(), $t->bonusStatutorySets(), $codes), [],
        ]];
        yield 'zápočet se společníkem' => [static function (self $t) use ($codes): array {
            $snapshot = $t->snapshotWithSettlementRule();
            $result = $t->calculatedResult();
            $result['source_snapshot_hash'] = $t->snapshotHash($snapshot);
            return [$t->builder->build($snapshot, $result, $t->statutorySets(), $codes), []];
        }];
        yield 'cestovné' => [static function (self $t) use ($codes): array {
            [$snapshot, $result] = $t->travelRevision();
            return [$t->builder->build($snapshot, $result, $t->statutorySets(), $codes), []];
        }];
        yield 'benefit v koši osvobození' => [static function (self $t) use ($codes): array {
            [$snapshot, $result] = $t->benefitBasketRevision(60_000, 40_000);
            return [$t->builder->build($snapshot, $result, $t->statutorySets(), $codes), []];
        }];
        yield 'všechny nákladové cesty se středisky' => [static function (self $t) use ($codes): array {
            [$snapshot, $result] = $t->everyCostPathRevision(true);
            return [$t->builder->build($snapshot, $result, $t->statutorySetsWithRelationships(), $codes), []];
        }];
        yield 'opravná revize snižuje pojistné' => [static function (self $t) use ($codes): array {
            $first = $t->builder->build($t->snapshot(), $t->calculatedResult(), $t->statutorySets(), $codes);
            $sets = $t->statutorySets();
            $sets['social_insurance']['result_snapshot']['employer_contribution_minor_units'] = 138_800;
            return [$t->builder->build($t->snapshot(), $t->calculatedResult(), $sets, $codes, $first->targetAllocations), $first->targetAllocations];
        }];
        yield 'opravná revize zvýší mzdu' => [static function (self $t) use ($codes): array {
            $first = $t->builder->build($t->snapshot(), $t->calculatedResult(), $t->statutorySets(), $codes);
            $sets = $t->statutorySets();
            $sets['social_insurance']['result_snapshot']['employer_contribution_minor_units'] = 158_800;
            return [$t->builder->build($t->snapshot(), $t->calculatedResult(), $sets, $codes, $first->targetAllocations), $first->targetAllocations];
        }];
    }

    /** @param \Closure(self):array{0:\MyInvoice\Service\Payroll\Posting\PayrollPostingPreview,1:list<array<string,mixed>>} $scenario */
    #[\PHPUnit\Framework\Attributes\DataProvider('singleEntryScenarios')]
    public function testSingleEntryLinesArePairsWithTheSameTotals(\Closure $scenario): void
    {
        [$preview, $previous] = $scenario($this);
        $lines = $this->builder->singleEntryLines($preview->targetAllocations, $previous);

        self::assertNotNull($lines, 'Předpis musí jít rozdělit na dvojice.');
        self::assertSame($this->detailedMap($preview->lines), $this->detailedMap($lines));

        $resolved = array_map(static fn (array $line): array => [
            'account_code' => $line['account_code'],
            'side' => $line['side'],
            'amount' => number_format($line['amount_minor'] / 100, 2, '.', ''),
            'pair_group' => $line['pair_group'],
        ], $lines);
        $paired = \MyInvoice\Service\Accounting\Pair\JournalPairer::pair($resolved);
        self::assertSame('paired', $paired['status']);
        self::assertCount(count($lines), $paired['lines'], 'Každá skupina je přesně jedna dvojice.');
        \MyInvoice\Service\Accounting\Pair\JournalPairInvariant::assert(
            ['entry_date' => '2026-06-30'],
            $paired['lines'],
            \MyInvoice\Service\Accounting\EntryForm\EntryForm::Single,
        );
    }

    /** Dvojice předkontací ze Zadání: 521/331, 524/336, 331/336, 331/342 souhrnně. */
    public function testSingleEntryLinesFollowThePostingPairs(): void
    {
        $preview = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $pairs = [];
        $lines = $this->builder->singleEntryLines($preview->targetAllocations, []);
        self::assertNotNull($lines);
        for ($i = 0; $i < count($lines); $i += 2) {
            $pairs[$lines[$i]['account_code'] . '/' . $lines[$i + 1]['account_code']] =
                ($pairs[$lines[$i]['account_code'] . '/' . $lines[$i + 1]['account_code']] ?? 0) + $lines[$i]['amount_minor'];
        }

        self::assertSame(100_000, $pairs['521/331']);
        self::assertSame(202_800, ($pairs['524/336.100'] ?? 0) + ($pairs['524/336.200'] ?? 0));
        self::assertArrayHasKey('331/342.100', $pairs);
        self::assertArrayHasKey('331/336.100', $pairs);
        self::assertSame(['debit', 'credit'], [$lines[0]['side'], $lines[1]['side']]);
    }

    /**
     * Opravná revize, která jen přeřadí středisko na stejném nákladovém účtu, by rozdílem
     * dala MD 521 / Dal 521, a to dvojicí být nemůže. Jednotka se proto zaúčtuje jako
     * storno původní dvojice (331/521 se starým střediskem) a nová dvojice (521/331
     * s novým střediskem): platné dvojice se stejnými součty po účtech a střediscích.
     */
    public function testCostCentreMoveOnTheSameAccountIsStornoAndNewPair(): void
    {
        $snapshot = $this->snapshotWithCostCentres();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);
        $first = $this->builder->build($snapshot, $result, $this->statutorySets(), PayrollAccountingDefaults::codes());
        $moved = $this->snapshotWithCostCentres();
        $moved['people'][0]['employments'][0]['dimensions'] = [$this->dimension('cost_center', 'MONTAZ', null)];
        $movedResult = $this->calculatedResult();
        $movedResult['source_snapshot_hash'] = $this->snapshotHash($moved);
        $correction = $this->builder->build($moved, $movedResult, $this->statutorySets(), PayrollAccountingDefaults::codes(), $first->targetAllocations);

        $lines = $this->builder->singleEntryLines($correction->targetAllocations, $first->targetAllocations);
        self::assertNotNull($lines);
        $this->assertValidPairsWithTheSameTotals($correction->lines, $lines);
        $pairs = [];
        for ($i = 0; $i < count($lines); $i += 2) {
            $pairs[] = $lines[$i]['account_code'] . ($lines[$i]['cost_center'] ?? '')
                . '/' . $lines[$i + 1]['account_code'] . ($lines[$i + 1]['cost_center'] ?? '');
        }
        self::assertContains('521MONTAZ/331', $pairs);
        self::assertContains('331/521VYROBA', $pairs);

        [$plainSnapshot, $plainResult] = $this->everyCostPathRevision(false);
        $sets = $this->statutorySetsWithRelationships();
        $previous = $this->builder->build($plainSnapshot, $plainResult, $sets, PayrollAccountingDefaults::codes());
        [$centred, $centredResult] = $this->everyCostPathRevision(true);
        $added = $this->builder->build($centred, $centredResult, $sets, PayrollAccountingDefaults::codes(), $previous->targetAllocations);
        $addedLines = $this->builder->singleEntryLines($added->targetAllocations, $previous->targetAllocations);
        self::assertNotNull($addedLines);
        $this->assertValidPairsWithTheSameTotals($added->lines, $addedLines);
    }

    /**
     * @param list<array<string,mixed>> $composite řádky složeného zápisu z build()
     * @param list<array<string,mixed>> $lines řádky dvojic
     */
    private function assertValidPairsWithTheSameTotals(array $composite, array $lines): void
    {
        // Storno a nová dvojice dají na protiúčtu obrat na obou stranách; zůstatek
        // po účtech, střediscích a dimenzích musí sedět na složený zápis.
        $net = function (array $rows): array {
            $map = [];
            foreach ($rows as $line) {
                $key = $line['account_code'] . '|' . ($line['cost_center'] ?? '')
                    . '|' . self::dimensionsKey($line['dimensions'] ?? []);
                $map[$key] = ($map[$key] ?? 0) + ($line['side'] === 'debit' ? 1 : -1) * $line['amount_minor'];
            }
            $map = array_filter($map, static fn (int $value): bool => $value !== 0);
            ksort($map);
            return $map;
        };
        self::assertSame($net($composite), $net($lines));
        $resolved = array_map(static fn (array $line): array => [
            'account_code' => $line['account_code'],
            'side' => $line['side'],
            'amount' => number_format($line['amount_minor'] / 100, 2, '.', ''),
            'pair_group' => $line['pair_group'],
        ], $lines);
        $paired = \MyInvoice\Service\Accounting\Pair\JournalPairer::pair($resolved);
        self::assertSame('paired', $paired['status']);
        self::assertCount(count($lines), $paired['lines']);
        \MyInvoice\Service\Accounting\Pair\JournalPairInvariant::assert(
            ['entry_date' => '2026-06-30'],
            $paired['lines'],
            \MyInvoice\Service\Accounting\EntryForm\EntryForm::Single,
        );
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,int> účet|strana|středisko|dimenze => haléře
     */
    private function detailedMap(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $key = $line['account_code'] . '|' . $line['side'] . '|' . ($line['cost_center'] ?? '')
                . '|' . self::dimensionsKey($line['dimensions'] ?? []);
            $map[$key] = ($map[$key] ?? 0) + $line['amount_minor'];
        }
        ksort($map);
        return $map;
    }
}
