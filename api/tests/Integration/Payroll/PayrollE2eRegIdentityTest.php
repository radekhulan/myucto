<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ručně opsané OIČ a ID PPV u brigádníka s více dohodami: posun platnosti
 * OIČ dozadu, OIČ k pozdější dohodě a kolize s jiným OIČ. OIČ převzaté
 * z protokolu ČSSZ kryje {@see PayrollE2eReg2IdentityTest}.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eRegIdentityTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private PayrollRegistrationIdentityService $identities;

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertInstanceOf(PayrollRegistrationIdentityService::class, $identities);
        $this->identities = $identities;
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * OIČ opsané k pozdější dohodě platnost neposune dopředu: dřívější den
     * zůstane, pozdější dohoda dostane jen své ID PPV.
     */
    public function testSameOicOnLaterAgreementKeepsEarlierValidity(): void
    {
        [$employeeId, $first] = $this->regPerson('Opsané', 'Dřív', 81, 'dpp', '2026-02-02');
        $this->assign($first, '1000000012', '200000000000000000081', '2026-02-02');
        $later = $this->regAdditionalEmployment($employeeId, 'DPP-L', 'dpp', '2026-06-01', '2026-06-30');

        $this->assign($later, '1000000012', '200000000000000000082', '2026-06-01');

        self::assertSame('2026-02-02', $this->personValidFrom($employeeId));
        self::assertSame('2026-06-01', $this->regIdentifier($later, 'id_ppv'));
        self::assertSame('2026-02-02', $this->regIdentifier($first, 'id_ppv'));
    }

    /** Jiné OIČ pro tutéž osobu je kolize se srozumitelnou hláškou, nic se nezapíše. */
    public function testDifferentOicForTheSamePersonIsRefused(): void
    {
        [$employeeId, $first] = $this->regPerson('Opsané', 'Kolize', 83, 'dpp', '2026-06-01');
        $this->assign($first, '1000000012', '200000000000000000083', '2026-06-01');
        $earlier = $this->regAdditionalEmployment($employeeId, 'DPP-E', 'dpp', '2026-02-02', '2026-02-27', 'ended');

        try {
            $this->assign($earlier, '1000000023', '200000000000000000084', '2026-02-02');
            self::fail('Jiné OIČ téže osoby se zapsalo.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('jinou hodnotu', $exception->getMessage());
        }
        self::assertSame('2026-06-01', $this->personValidFrom($employeeId));
        self::assertNull($this->regIdentifier($earlier, 'id_ppv'));
    }

    private function assign(int $employmentId, string $oic, string $idPpv, string $validFrom): void
    {
        $this->identities->assignManualJmhzIdentity(
            $this->supplierId,
            $employmentId,
            'test',
            $oic,
            $idPpv,
            $validFrom,
            null,
            true,
            $this->actors[0],
        );
    }

    private function personValidFrom(int $employeeId): ?string
    {
        $value = $this->scalar(
            'SELECT valid_from FROM payroll_person_external_ids
              WHERE supplier_id = ? AND employee_id = ? AND environment = "test" AND valid_to IS NULL
              ORDER BY id DESC LIMIT 1',
            [$this->supplierId, $employeeId],
        );

        return $value === false || $value === null ? null : (string) $value;
    }
}
