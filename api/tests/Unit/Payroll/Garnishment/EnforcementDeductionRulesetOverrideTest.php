<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment;

use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\EnforcementDeductionPolicy2026;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeItem;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeKind;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResolver;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use PHPUnit\Framework\TestCase;

/**
 * MZ-14-W11 — nezabavitelné částky jsou administrovatelné.
 *
 * Nařízení vlády č. 595/2006 Sb. mění životní minimum i normativní náklady na
 * bydlení několikrát za rok. Test drží tři vlastnosti, na kterých to celé stojí:
 * administrátorská změna se PROJEVÍ ve výpočtu, výchozí sada z kódu zůstává
 * integritně pinnutá a historický výsledek si podrží zmrazené hodnoty.
 */
final class EnforcementDeductionRulesetOverrideTest extends TestCase
{
    private const RULESET_ID = 'cz-payroll-2026.enforcement-deductions.v1';

    public function testAdminOverrideChangesTheWithheldAmountAndTheEmployeePayout(): void
    {
        $byDefault = $this->calculate($this->defaultProvider());
        $overridden = $this->calculate($this->overriddenProvider([
            'life_minimum.monthly' => ['type' => 'money_minor', 'value' => 586_000],
            'protected_amount.calculation_base.monthly' =>
                ['type' => 'money_minor', 'value' => 1_759_000],
            'protected_amount.debtor_base.monthly' =>
                ['type' => 'money_minor', 'value' => 1_495_150],
        ]));

        self::assertSame(GarnishmentStatus::Supported, $byDefault->status);
        self::assertSame(GarnishmentStatus::Supported, $overridden->status);
        // Nezabavitelná částka se zaokrouhluje nahoru na celé koruny (§ 278 o. s. ř.).
        self::assertSame(1_410_200, $byDefault->protectedAmountMinorUnits);
        self::assertSame(1_495_200, $overridden->protectedAmountMinorUnits);
        self::assertGreaterThan(
            $overridden->totalWithheldMinorUnits,
            $byDefault->totalWithheldMinorUnits,
        );
        self::assertLessThan(
            $overridden->employeePaymentMinorUnits,
            $byDefault->employeePaymentMinorUnits,
        );
        self::assertNotSame($byDefault->rulesetHash, $overridden->rulesetHash);
        self::assertSame(self::RULESET_ID, $overridden->rulesetId);
    }

    public function testAdminOverrideOfTheEmployerFlatFeeReachesTheCalculation(): void
    {
        $byDefault = $this->calculate($this->defaultProvider());
        $overridden = $this->calculate($this->overriddenProvider([
            'employer_flat_fee.maximum.monthly' => ['type' => 'money_minor', 'value' => 15_000],
        ]));

        self::assertSame(5_000, $byDefault->employerFlatFeeMinorUnits);
        self::assertSame(15_000, $overridden->employerFlatFeeMinorUnits);
    }

    public function testShippedDefaultStillMatchesItsPinnedHash(): void
    {
        $shipped = EnforcementDeductionPolicy2026::shipped();

        // Pin je nad OBSAHEM: lifecycle ani schvalovatel (ten je vlastností
        // instalace) ho hýbat nesmějí. Identita uložená do výsledku srážky
        // zůstává plným snapshotem, protože ta má popsat účinnou verzi.
        self::assertSame(
            CzechPayrollRulesets2026::ENFORCEMENT_DEDUCTIONS_HASH,
            $shipped->ruleset->contentHash,
        );
        self::assertSame($this->defaultVersion()->canonicalHash, $shipped->rulesetHash());
        self::assertSame(486_000, $shipped->money('life_minimum.monthly'));
        self::assertSame(943_000, $shipped->money('normative_rent.monthly'));
        self::assertSame(230_000, $shipped->money('energy_flat.monthly'));
        self::assertSame(1_410_150, $shipped->money('protected_amount.debtor_base.monthly'));
        self::assertSame(3_152_100, $shipped->money('fully_attachable.threshold.monthly'));
        self::assertSame(108_900, $shipped->money('four_enforcement_rule.pension_exception_limit'));
        self::assertSame('2022-01-01', $shipped->text('employer_flat_fee.order_effective_from'));
    }

    public function testHistoricalResultKeepsItsFrozenRulesetAfterTheAdminChangesTheCurrentOne(): void
    {
        $historical = $this->calculate($this->defaultProvider());
        $frozen = json_decode(
            json_encode($historical, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($frozen);

        $overriddenProvider = $this->overriddenProvider([
            'protected_amount.debtor_base.monthly' =>
                ['type' => 'money_minor', 'value' => 1_495_150],
        ]);
        $current = $this->calculate($overriddenProvider);
        $reloaded = GarnishmentResult::fromCanonicalArray($frozen);

        self::assertSame($historical->rulesetHash, $reloaded->rulesetHash);
        self::assertSame(
            $historical->protectedAmountMinorUnits,
            $reloaded->protectedAmountMinorUnits,
        );
        self::assertSame(
            $historical->totalWithheldMinorUnits,
            $reloaded->totalWithheldMinorUnits,
        );
        self::assertNotSame($reloaded->rulesetHash, $current->rulesetHash);
        self::assertNotSame(
            $reloaded->protectedAmountMinorUnits,
            $current->protectedAmountMinorUnits,
        );

        // Manifest zmrazený do snapshotu běhu odliší obě sady na úrovni identity.
        self::assertNotSame(
            $this->defaultProvider()->canonicalManifestJson(),
            $overriddenProvider->canonicalManifestJson(),
        );
    }

    public function testBrokenOverrideFailsClosedInsteadOfSilentlyUsingTheCodeDefault(): void
    {
        $result = $this->calculate($this->overriddenProvider([
            'protected_amount.debtor_base.monthly' => [
                'type' => 'manual_review',
                'value' => 'Nezabavitelnou částku je nutné ověřit ručně.',
            ],
        ]));

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains('enforcement_ruleset_incomplete', $result->issues);
        self::assertSame(0, $result->totalWithheldMinorUnits);
    }

    private function calculate(PayrollRulesetProvider $rulesets): GarnishmentResult
    {
        $income = (new GarnishableIncomeResolver())->resolve([
            new GarnishableIncomeItem(
                'wage-1',
                GarnishableIncomeKind::Wage,
                4_000_000,
                'employer-1',
            ),
        ], true);

        return (new GarnishmentCalculator($rulesets))->calculate(new GarnishmentInput(
            '2026-08',
            '2026-08-10',
            $income,
            [
                new DeductionClaim(
                    'claim-1',
                    DeductionLegalBasis::Statutory,
                    ClaimCategory::NonPriority,
                    10_000_000,
                    '2026-01-02',
                    legalTitleVerified: true,
                    orderOrNoticeDelivered: true,
                    orderIssuedOn: '2022-01-01',
                    priorityClassificationVerified: true,
                    dueMonetaryClaimVerified: true,
                    enforcementOrderId: 'order-1',
                ),
            ],
            0,
            true,
            false,
            true,
            PensionEvidence::None,
            false,
            null,
            InsolvencyInstruction::none(),
            false,
            true,
        ));
    }

    private function defaultProvider(): PayrollRulesetProvider
    {
        return new PayrollRulesetProvider([$this->defaultVersion()]);
    }

    /**
     * Override se skládá přesně tak, jak ho po uložení administrační službou
     * čte {@see PayrollRulesetRegistry} — merge po klíčích nad defaultem z kódu.
     *
     * @param array<string, array<string, mixed>> $parameters
     */
    private function overriddenProvider(array $parameters): PayrollRulesetProvider
    {
        return new PayrollRulesetProvider([
            PayrollRulesetRegistry::merge($this->defaultVersion(), [
                'ruleset_id' => self::RULESET_ID,
                'reason' => 'Syntetická administrátorská změna pro deterministický test.',
                'created_by' => 900_001,
                'updated_by' => 900_001,
                'data' => json_encode(
                    ['parameters' => $parameters],
                    JSON_THROW_ON_ERROR,
                ),
            ]),
        ]);
    }

    private function defaultVersion(): PayrollRulesetVersion
    {
        // Podle ID, ne podle domény: od doplnění ročníku 2025 drží výchozí registry
        // exekučních srážek DVĚ verze a „první v doméně“ by vrátilo tu starší,
        // takže by se test o roku 2026 tiše počítal hodnotami roku 2025.
        foreach (PayrollRulesetRegistry::defaults()->versions() as $version) {
            if (
                $version->domain === PayrollRulesetDomain::EnforcementDeductions
                && $version->id === self::RULESET_ID
            ) {
                return $version;
            }
        }

        self::fail('Výchozí ruleset exekučních srážek chybí.');
    }
}
