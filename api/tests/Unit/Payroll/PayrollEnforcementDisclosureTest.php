<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Security\PayrollEnforcementDisclosure;
use PHPUnit\Framework\TestCase;

/**
 * Exekuční a insolvenční data, která protékají mimo vlastní agendu (snímek běhu,
 * blokátor smazání zaměstnance, podklady zápočtového listu), se volajícímu bez
 * `payroll.enforcement` / `payroll.insolvency` zúží. S oprávněním zůstávají
 * beze změny.
 */
final class PayrollEnforcementDisclosureTest extends TestCase
{
    public function testRunSnapshotKeepsEverythingForFullPermission(): void
    {
        $snapshot = self::snapshot();

        self::assertSame(
            $snapshot,
            PayrollEnforcementDisclosure::redactRunSnapshot($snapshot, true, true),
        );
    }

    public function testRunSnapshotDropsClaimRegisterWithoutEnforcementPermission(): void
    {
        $redacted = PayrollEnforcementDisclosure::redactRunSnapshot(self::snapshot(), false, false);
        self::assertIsArray($redacted);
        $people = $redacted['people'];
        self::assertIsArray($people);

        foreach ($people as $person) {
            self::assertIsArray($person);
            $enforcement = $person['enforcement'];
            self::assertIsArray($enforcement);
            self::assertArrayNotHasKey('input', $enforcement);
            self::assertTrue($enforcement['redacted']);
            self::assertSame(['total_withheld_minor_units'], array_keys($enforcement['result']));
        }
        self::assertSame(1051400, $people[0]['enforcement']['result']['total_withheld_minor_units']);
        // Zbytek snímku (mzda, odvody, výplata) zůstává tak, jak byl.
        self::assertSame(42000, $people[0]['totals']['gross_minor']);
        self::assertSame(30000, $people[0]['payable_after_enforcement_minor']);
        self::assertSame(self::snapshot()['totals'], $redacted['totals']);
        $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('maintenance_arrears', $encoded);
        self::assertStringNotContainsString('eligible_dependants', $encoded);
        self::assertStringNotContainsString('approved_standard', $encoded);
    }

    public function testRunSnapshotHidesOnlyInsolvencyPeopleWithoutInsolvencyPermission(): void
    {
        $redacted = PayrollEnforcementDisclosure::redactRunSnapshot(self::snapshot(), true, false);
        self::assertIsArray($redacted);

        self::assertSame(self::snapshot()['people'][0], $redacted['people'][0]);
        self::assertTrue($redacted['people'][1]['enforcement']['redacted']);
        self::assertStringNotContainsString(
            'insolvency-administrator',
            json_encode($redacted, JSON_THROW_ON_ERROR),
        );
    }

    public function testRunListAndHistoryHideGarnishmentTotals(): void
    {
        $list = PayrollEnforcementDisclosure::redactRunTotals(['totals' => [
            'cash_payable_minor' => 100_000,
            'enforcement_withheld_minor' => 10_000,
            'payable_after_enforcement_minor' => 90_000,
        ]]);
        self::assertNull($list['totals']['enforcement_withheld_minor']);
        self::assertSame(90_000, $list['totals']['payable_after_enforcement_minor']);

        $history = PayrollEnforcementDisclosure::redactRunHistory([
            'run_id' => 1,
            'revisions' => [[
                'id' => 2,
                'totals' => ['cash_payable_minor' => 100_000, 'enforcement_withheld_minor' => 10_000],
                'diff_from_previous' => ['totals' => [
                    'cash_payable_minor' => ['before' => 90_000, 'after' => 100_000, 'delta' => 10_000],
                    'enforcement_withheld_minor' => ['before' => 0, 'after' => 10_000, 'delta' => 10_000],
                ]],
            ]],
            'events' => [],
        ]);
        $revision = $history['revisions'][0];
        self::assertNull($revision['totals']['enforcement_withheld_minor']);
        self::assertSame(['cash_payable_minor'], array_keys($revision['diff_from_previous']['totals']));
    }

    public function testRunWithoutSnapshotIsLeftAlone(): void
    {
        self::assertNull(PayrollEnforcementDisclosure::redactRunSnapshot(null, false, false));
    }

    public function testEnforcementDeleteBlockerIsGenericWithoutPermission(): void
    {
        $blocker = [
            'code' => 'payroll_employee_has_enforcement',
            'message' => 'Na zaměstnance je vedená exekuce nebo insolvence.',
            'employment_id' => null,
            'employment_code' => null,
        ];

        $redacted = PayrollEnforcementDisclosure::redactDeleteBlocker($blocker, false);
        self::assertIsArray($redacted);
        self::assertSame(PayrollEnforcementDisclosure::RESTRICTED_DELETE_BLOCKER, $redacted['code']);
        self::assertStringNotContainsString('exekuc', mb_strtolower((string) $redacted['message']));
        self::assertSame($blocker, PayrollEnforcementDisclosure::redactDeleteBlocker($blocker, true));

        $other = ['code' => 'payroll_employee_in_run', 'message' => 'V běhu.'];
        self::assertSame($other, PayrollEnforcementDisclosure::redactDeleteBlocker($other, false));
        self::assertNull(PayrollEnforcementDisclosure::redactDeleteBlocker(null, false));
        self::assertTrue(PayrollEnforcementDisclosure::isRestrictedDeleteBlockerCode(
            'payroll_employee_has_enforcement',
            false,
        ));
        self::assertFalse(PayrollEnforcementDisclosure::isRestrictedDeleteBlockerCode(
            'payroll_employee_has_enforcement',
            true,
        ));
    }

    public function testExitReadinessHidesEnforcementSourcesAndBlocksCertificate(): void
    {
        $readiness = self::readiness();

        $redacted = PayrollEnforcementDisclosure::redactExitReadiness($readiness, false, false);
        $certificate = $redacted['employment_certificate'];
        self::assertFalse($certificate['available']);
        self::assertSame(
            PayrollEnforcementDisclosure::DEDUCTION_PERMISSION_REQUIRED,
            $certificate['readiness_code'],
        );
        self::assertSame([], $certificate['deduction_claim_ids']);
        self::assertSame(['deduction_agreement'], array_column($certificate['deduction_sources'], 'source_kind'));
        // Potvrzení pro Úřad práce na srážkách nezávisí a zůstává dostupné.
        self::assertSame($readiness['average_earnings_certificate'], $redacted['average_earnings_certificate']);
        self::assertTrue(PayrollEnforcementDisclosure::hasRestrictedDeductions($readiness, false, false));

        $enforcementOnly = PayrollEnforcementDisclosure::redactExitReadiness($readiness, true, false);
        self::assertFalse($enforcementOnly['employment_certificate']['available']);
        self::assertSame([7], $enforcementOnly['employment_certificate']['deduction_claim_ids']);
        self::assertSame(
            ['enforcement_claim', 'deduction_agreement'],
            array_column($enforcementOnly['employment_certificate']['deduction_sources'], 'source_kind'),
        );
        self::assertTrue(PayrollEnforcementDisclosure::hasRestrictedDeductions($readiness, true, false));

        self::assertSame($readiness, PayrollEnforcementDisclosure::redactExitReadiness($readiness, true, true));
        self::assertFalse(PayrollEnforcementDisclosure::hasRestrictedDeductions($readiness, true, true));
    }

    public function testExitReadinessWithAgreementOnlyStaysAvailable(): void
    {
        $readiness = self::readiness();
        $readiness['employment_certificate']['deduction_claim_ids'] = [];
        $readiness['employment_certificate']['deduction_sources'] = [
            $readiness['employment_certificate']['deduction_sources'][1],
        ];

        self::assertSame($readiness, PayrollEnforcementDisclosure::redactExitReadiness($readiness, false, false));
        self::assertFalse(PayrollEnforcementDisclosure::hasRestrictedDeductions($readiness, false, false));
    }

    /** @return array<string,mixed> */
    private static function snapshot(): array
    {
        return [
            'schema_version' => 'payroll-run-result.v1',
            'totals' => ['enforcement_withheld_minor' => 1061400, 'payable_after_enforcement_minor' => 60000],
            'people' => [
                [
                    'employee_id' => 1,
                    'totals' => ['gross_minor' => 42000],
                    'payable_after_enforcement_minor' => 30000,
                    'enforcement' => [
                        'input' => [
                            'claims' => [[
                                'category' => 'maintenance_arrears',
                                'outstanding_minor_units' => 18765400,
                            ]],
                            'evidence' => ['eligible_dependants' => 1],
                            'insolvency' => ['mode' => 'none', 'payment_instruction_id' => null],
                        ],
                        'result' => [
                            'allocations' => [['claim_id' => 'claim-1', 'total_minor_units' => 1051400]],
                            'insolvency_applied' => false,
                            'total_withheld_minor_units' => 1051400,
                        ],
                    ],
                ],
                [
                    'employee_id' => 2,
                    'totals' => ['gross_minor' => 40000],
                    'payable_after_enforcement_minor' => 30000,
                    'enforcement' => [
                        'input' => [
                            'claims' => [],
                            'evidence' => ['eligible_dependants' => 0],
                            'insolvency' => ['mode' => 'approved_standard', 'payment_instruction_id' => 1],
                        ],
                        'result' => [
                            'allocations' => [[
                                'claim_id' => 'insolvency-administrator',
                                'total_minor_units' => 10000,
                            ]],
                            'insolvency_applied' => true,
                            'total_withheld_minor_units' => 10000,
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function readiness(): array
    {
        return [
            'employment_certificate' => [
                'available' => true,
                'readiness_code' => null,
                'deduction_claim_ids' => [7],
                'deduction_sources' => [
                    ['source_kind' => 'enforcement_claim', 'source_claim_id' => 7],
                    ['source_kind' => 'deduction_agreement', 'source_claim_id' => 3],
                    ['source_kind' => 'insolvency', 'source_claim_id' => 2],
                ],
            ],
            'average_earnings_certificate' => [
                'available' => true,
                'readiness_code' => null,
                'decisive_year' => 2026,
                'decisive_quarter' => 2,
            ],
            'average_earnings_statement' => [
                'available' => true,
                'readiness_code' => null,
                'decisive_year' => 2026,
                'decisive_quarter' => 2,
            ],
        ];
    }
}
