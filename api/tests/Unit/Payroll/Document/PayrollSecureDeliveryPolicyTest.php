<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollSecureDeliveryBlockedException;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollSecureDeliveryPolicy;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Brána odchozí cesty k zaměstnanci. Test drží to hlavní: každá z pěti podmínek
 * sama o sobě stačí k tomu, aby se výplatnice NEODESLALA.
 *
 * Bez DB — repozitáře jsou doubly, takže tenhle test nemůže nic odeslat ani při
 * chybě v konfiguraci prostředí.
 */
#[Group('unit')]
final class PayrollSecureDeliveryPolicyTest extends TestCase
{
    private const PERIOD = '2026-07-01';

    public function testDisabledInstanceSwitchBlocksEverything(): void
    {
        // Právě tohle drží vývojovou instanci nad ostrými daty v bezpečí:
        // dokud přepínač chybí, brána spadne dřív, než se dotkne čehokoli dalšího.
        $policy = $this->policy(channelEnabled: false, released: true);

        self::assertFalse($policy->isChannelEnabled());
        $this->expectException(PayrollSecureDeliveryBlockedException::class);
        $policy->assertDispatchAllowed(1, self::PERIOD);
    }

    public function testDefaultConfigurationHasChannelOff(): void
    {
        // Výchozí hodnota, ne jen dokumentovaný záměr: prázdná konfigurace
        // NESMÍ kanál zapnout.
        $policy = new PayrollSecureDeliveryPolicy(
            new Config([]),
            $this->gate(true),
            $this->employerPolicies($this->activePolicy()),
        );

        self::assertFalse($policy->isChannelEnabled());
    }

    public function testUnreleasedProductionGateBlocksDispatch(): void
    {
        $policy = $this->policy(channelEnabled: true, released: false);

        $this->expectException(PayrollProductionGateException::class);
        $policy->assertDispatchAllowed(1, self::PERIOD);
    }

    public function testMissingEmployerPolicyBlocksDispatch(): void
    {
        $policy = $this->policy(channelEnabled: true, released: true, employerPolicy: null);

        try {
            $policy->assertDispatchAllowed(1, self::PERIOD);
            self::fail('Bez zaměstnavatelské politiky se odesílat nesmí.');
        } catch (PayrollSecureDeliveryBlockedException $exception) {
            self::assertSame('employer_policy_missing', $exception->reasonCode());
        }
    }

    public function testEmployerChannelOtherThanPortalBlocksDispatch(): void
    {
        $policy = $this->policy(
            channelEnabled: true,
            released: true,
            employerPolicy: ['delivery_channel' => 'manual_handover', 'delivery_verified_on' => '2026-01-01'],
        );

        try {
            $policy->assertDispatchAllowed(1, self::PERIOD);
            self::fail('Jiný než portálový kanál se nesmí odesílat e-mailem.');
        } catch (PayrollSecureDeliveryBlockedException $exception) {
            self::assertSame('employer_channel_not_portal', $exception->reasonCode());
        }
    }

    public function testUnverifiedEmployerChannelBlocksDispatch(): void
    {
        $policy = $this->policy(
            channelEnabled: true,
            released: true,
            employerPolicy: ['delivery_channel' => 'employee_portal', 'delivery_verified_on' => null],
        );

        try {
            $policy->assertDispatchAllowed(1, self::PERIOD);
            self::fail('Nepotvrzený kanál se nesmí použít.');
        } catch (PayrollSecureDeliveryBlockedException $exception) {
            self::assertSame('employer_channel_unverified', $exception->reasonCode());
        }
    }

    public function testFullyOpenGatePasses(): void
    {
        $policy = $this->policy(channelEnabled: true, released: true);

        $policy->assertDispatchAllowed(1, self::PERIOD);
        $policy->assertEmployeeOptedIn('portal');
        self::assertTrue(true);
    }

    public function testEmployeeChoosingPaperIsNeverOverridden(): void
    {
        $policy = $this->policy(channelEnabled: true, released: true);

        foreach ([null, 'paper', '', 'portal_maybe'] as $channel) {
            try {
                $policy->assertEmployeeOptedIn($channel);
                self::fail('Souhlas osoby se nesmí odvodit z ničeho jiného než "portal".');
            } catch (PayrollSecureDeliveryBlockedException $exception) {
                self::assertSame('employee_prefers_paper', $exception->reasonCode());
            }
        }
    }

    /** Lhůty se drží v rozumných mezích i při nesmyslné konfiguraci. */
    public function testTtlValuesAreClamped(): void
    {
        $policy = new PayrollSecureDeliveryPolicy(
            new Config(['payroll' => ['secure_delivery' => [
                'enabled' => true,
                'link_ttl_days' => 100000,
                'code_ttl_seconds' => 1,
                'session_ttl_seconds' => 999999,
                'max_code_attempts' => 999,
                'max_dispatch_attempts' => 0,
            ]]]),
            $this->gate(true),
            $this->employerPolicies($this->activePolicy()),
        );

        self::assertSame(90, $policy->linkTtlDays());
        self::assertSame(60, $policy->codeTtlSeconds());
        self::assertSame(43200, $policy->sessionTtlSeconds());
        self::assertSame(10, $policy->maxCodeAttempts());
        self::assertSame(1, $policy->maxDispatchAttempts());
    }

    /** @param array<string,mixed>|null $employerPolicy */
    private function policy(
        bool $channelEnabled,
        bool $released,
        ?array $employerPolicy = null,
    ): PayrollSecureDeliveryPolicy {
        return new PayrollSecureDeliveryPolicy(
            new Config(['payroll' => ['secure_delivery' => ['enabled' => $channelEnabled]]]),
            $this->gate($released),
            $this->employerPolicies(
                func_num_args() >= 3 ? $employerPolicy : $this->activePolicy(),
            ),
        );
    }

    /** @return array<string,mixed> */
    private function activePolicy(): array
    {
        return [
            'delivery_channel' => 'employee_portal',
            'delivery_verified_on' => '2026-01-01',
        ];
    }

    private function gate(bool $released): PayrollProductionGate
    {
        $states = $this->createStub(PayrollModuleStateRepository::class);
        $states->method('get')->willReturn(['status' => 'active']);

        return new PayrollProductionGate($states, $released);
    }

    /** @param array<string,mixed>|null $policy */
    private function employerPolicies(?array $policy): PayrollEmployerPolicyRepository
    {
        $repository = $this->createStub(PayrollEmployerPolicyRepository::class);
        $repository->method('findEffective')->willReturn($policy);

        return $repository;
    }
}
