<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportAvailabilityResolver;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use PHPUnit\Framework\TestCase;

/**
 * Sdílený výpočet dostupnosti ISDS transportu — dřív okopírovaný slovo od
 * slova v `JmhzIsdsSubmissionService` a `HealthInsuranceIsdsSubmissionService`,
 * a přitom obě kopie věděly jen o odesílací bráně, ne o Mobilním klíči.
 */
final class IsdsTransportAvailabilityResolverTest extends TestCase
{
    public function testGatewayWinsWhenUsable(): void
    {
        $gateway = $this->createStub(IsdsGatewayRegistrationService::class);
        $gateway->method('isUsable')->willReturn(true);
        $credentials = $this->createStub(SubmissionCredentialService::class);
        $credentials->method('hasDataBox')->willReturn(true);

        $result = (new IsdsTransportAvailabilityResolver($gateway, $credentials))
            ->resolve(7, 'production');

        self::assertTrue($result['automatic']);
        self::assertSame('gateway', $result['channel']);
        self::assertNull($result['reason']);
    }

    /**
     * Firma bez brány, ale s doloženou datovou schránkou: nabídne se cesta po
     * potvrzení v mobilu, ne rovnou „automaticky". Rozdíl mezi „jde to po
     * potvrzení" a „jde to samo" se nesmí ztratit.
     */
    public function testMobileKeyWhenNoGatewayButCompanyHasADataBox(): void
    {
        $gateway = $this->createStub(IsdsGatewayRegistrationService::class);
        $gateway->method('isUsable')->willReturn(false);
        $credentials = $this->createStub(SubmissionCredentialService::class);
        $credentials->method('hasDataBox')->willReturn(true);

        $result = (new IsdsTransportAvailabilityResolver($gateway, $credentials))
            ->resolve(7, 'test');

        self::assertFalse($result['automatic']);
        self::assertSame('mobile_key', $result['channel']);
        self::assertNull($result['reason']);
    }

    public function testManualWhenNeitherGatewayNorDataBoxAreThere(): void
    {
        $gateway = $this->createStub(IsdsGatewayRegistrationService::class);
        $gateway->method('isUsable')->willReturn(false);
        $credentials = $this->createStub(SubmissionCredentialService::class);
        $credentials->method('hasDataBox')->willReturn(false);

        $result = (new IsdsTransportAvailabilityResolver($gateway, $credentials))
            ->resolve(7, 'test');

        self::assertFalse($result['automatic']);
        self::assertSame('manual_upload', $result['channel']);
        self::assertSame('isds_transport_unavailable', $result['reason']);
    }

    /** Bez nasazené brány vůbec (null) se to nesmí spadnout ani hádat. */
    public function testMissingGatewayServiceStillLeavesMobileKeyReachable(): void
    {
        $credentials = $this->createStub(SubmissionCredentialService::class);
        $credentials->method('hasDataBox')->willReturn(true);

        $result = (new IsdsTransportAvailabilityResolver(null, $credentials))
            ->resolve(7, 'test');

        self::assertSame('mobile_key', $result['channel']);
    }
}
