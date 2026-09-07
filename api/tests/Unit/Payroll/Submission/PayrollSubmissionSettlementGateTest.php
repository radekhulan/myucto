<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionDeliveryProof;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionSettlementPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Brána ručního uzavření povinnosti.
 *
 * Uzavřít měsíc kliknutím je mocná páka a musí být ÚZKÁ: smí projít jen tam,
 * kde je doložené, že úřad výsledek zpracování nepošle. Kdyby prošla u JMHZ,
 * odklikla by účetní měsíc, o kterém ČSSZ teprve rozhoduje.
 */
final class PayrollSubmissionSettlementGateTest extends TestCase
{
    private PayrollSubmissionSettlementPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PayrollSubmissionSettlementPolicy(
            new PayrollDispatchCapabilityCatalog(),
        );
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * Podání odeslané MIMO aplikaci (portál úřadu)
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Běžné uzavření se u JMHZ vědomě zavírá, protože ČSSZ výsledek pošle sama.
     * Jenže na hlášení, které účetní vyplnila a odeslala na portálu ČSSZ, žádný
     * protokol NEDORAZÍ — aplikace ho nikdy neposlala. Povinnost by tak visela
     * navždy. Druhá brána proto `authorityReportsResult` neřeší; laťkou je
     * výslovné prohlášení účetní (den podání a poznámku vyžaduje služba).
     */
    public function testJmhzFiledOnAuthorityPortalCanBeClosed(): void
    {
        self::assertNotNull(
            $this->policy->blockedReason(
                JmhzSubmissionBridgeService::AGENDA_CODE,
                'submitted',
                'submitted',
                null,
            ),
            'Falzifikace: běžné uzavření musí u JMHZ dál blokovat.',
        );
        self::assertNull($this->policy->externalFilingBlockedReason(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'submitted',
            'submitted',
            null,
        ));
    }

    /**
     * Hlášení podané na portálu aplikace nikdy neodeslala, takže podání zůstane
     * `ready`. Kdyby brána brala jen `submitted` jako ta běžná, nešlo by
     * uzavřít právě ten případ, kvůli kterému vznikla.
     */
    public function testNeverDispatchedSubmissionCanBeClosedAsFiledExternally(): void
    {
        foreach (['prepared', 'ready', 'submitted', 'processing'] as $status) {
            self::assertNull(
                $this->policy->externalFilingBlockedReason(
                    JmhzSubmissionBridgeService::AGENDA_CODE,
                    'submitted',
                    $status,
                    null,
                ),
                'Stav „' . $status . '" musí jít uzavřít jako podaný jinudy.',
            );
        }
    }

    /** O čem už úřad rozhodl, není co potvrzovat. */
    public function testDecidedSubmissionCannotBeClosedAsFiledExternally(): void
    {
        foreach (['accepted', 'rejected', 'cancelled_in_time', 'superseded'] as $status) {
            self::assertNotNull($this->policy->externalFilingBlockedReason(
                JmhzSubmissionBridgeService::AGENDA_CODE,
                'submitted',
                $status,
                null,
            ));
        }
    }

    /** Uzavřená povinnost se znovu neuzavírá ani touhle cestou. */
    public function testClosedObligationCannotBeClosedAgainAsFiledExternally(): void
    {
        self::assertNotNull($this->policy->externalFilingBlockedReason(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'fulfilled',
            'submitted',
            null,
        ));
    }

    /**
     * Zpráva čekající ve frontě je tu stopka: účetní podala na portálu, a kdyby
     * totéž odešlo ještě datovkou, vznikla by u úřadu duplicita.
     */
    public function testQueuedUndeliveredMessageBlocksExternalFiling(): void
    {
        $reason = $this->policy->externalFilingBlockedReason(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'submitted',
            'submitted',
            ['dispatch_state' => 'queued', 'acceptance_state' => 'unknown'],
        );

        self::assertNotNull($reason);
        self::assertStringContainsString('podruhé', $reason);
    }

    public function testHealthOverviewSentByDataBoxCanBeSettled(): void
    {
        self::assertNull($this->policy->blockedReason(
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            'submitted',
            'submitted',
            ['dispatch_state' => 'delivered', 'acceptance_state' => 'unknown'],
        ));
    }

    /**
     * Podání bez řádku odchozí fronty (podané portálem pojišťovny) se posuzuje
     * jen podle stavu. Řádek odchozí fronty vzniká jen u datovky, takže jeho
     * absence nesmí uzavření zablokovat — jinak by přehled podaný portálem
     * neměl jak skončit.
     */
    public function testHealthOverviewWithoutOutboxRowCanBeSettled(): void
    {
        self::assertNull($this->policy->blockedReason(
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            'submitted',
            'submitted',
            null,
        ));
    }

    public function testJmhzCannotBeSettledByHand(): void
    {
        $reason = $this->policy->blockedReason(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'submitted',
            'submitted',
            ['dispatch_state' => 'delivered', 'acceptance_state' => 'unknown'],
        );

        self::assertIsString($reason);
        self::assertStringContainsString('od úřadu sám', $reason);
    }

    /**
     * ELDP má vlastní, přísnější cestu — dokládá se dokumentem. Kdyby prošel
     * i sem, stály by vedle sebe dvě brány s různou laťkou a nikdo by
     * nepoužíval tu vyšší.
     */
    public function testEldpKeepsItsOwnEvidencePath(): void
    {
        $reason = $this->policy->blockedReason(
            EldpStatementService::AGENDA_CODE,
            'submitted',
            'submitted',
            null,
        );

        self::assertIsString($reason);
        self::assertStringContainsString('neodesílá', $reason);
    }

    public function testUnsentQueuedMessageCannotBeSettled(): void
    {
        $reason = $this->policy->blockedReason(
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            'submitted',
            'submitted',
            ['dispatch_state' => 'ready', 'acceptance_state' => 'unknown'],
        );

        self::assertIsString($reason);
        self::assertStringContainsString('odchozí frontě', $reason);
    }

    public function testOnlyDispatchedSubmissionsCanBeSettled(): void
    {
        foreach (['ready', 'prepared', 'accepted', 'rejected'] as $status) {
            self::assertIsString(
                $this->policy->blockedReason(
                    HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
                    'submitted',
                    $status,
                    null,
                ),
                sprintf('Stav podání „%s" nesmí jít uzavřít ručně.', $status),
            );
        }
    }

    /**
     * Uzavřená povinnost se neuzavírá podruhé.
     *
     * Stav PODÁNÍ zůstává po uzavření schválně „odesláno" (úřad se nevyjádřil
     * a tvrdit opak by byla lež), takže sám o sobě neříká nic o tom, jestli je
     * měsíc hotový. Kdyby se brána ptala jen jeho, svítilo by tlačítko dál
     * i po uzavření.
     */
    public function testAlreadyClosedObligationCannotBeSettledAgain(): void
    {
        foreach (['fulfilled', 'cancelled'] as $obligationStatus) {
            $reason = $this->policy->blockedReason(
                HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
                $obligationStatus,
                'submitted',
                ['dispatch_state' => 'delivered', 'acceptance_state' => 'accepted'],
            );

            self::assertIsString($reason, sprintf(
                'Povinnost ve stavu „%s" nesmí jít uzavřít znovu.',
                $obligationStatus,
            ));
        }
    }

    /** Doklad o doručení: co se za něj bere a co ne. */
    public function testDeliveryProofRecognisesOnlyRealEvidence(): void
    {
        self::assertNull(PayrollSubmissionDeliveryProof::reason(null));
        self::assertNull(PayrollSubmissionDeliveryProof::reason([
            'dispatch_state' => 'ready',
            'acceptance_state' => 'unknown',
        ]));
        // `sent` samo o sobě důkaz NENÍ — zpráva odešla, o dodání se neví.
        self::assertNull(PayrollSubmissionDeliveryProof::reason([
            'dispatch_state' => 'sent',
            'acceptance_state' => 'unknown',
        ]));
        self::assertTrue(PayrollSubmissionDeliveryProof::hasLeftApplication([
            'dispatch_state' => 'sent',
        ]));
        self::assertFalse(PayrollSubmissionDeliveryProof::hasLeftApplication([
            'dispatch_state' => 'ready',
        ]));
        self::assertNotNull(PayrollSubmissionDeliveryProof::abandonBlockedReason([
            'dispatch_state' => 'delivered',
        ]));
        self::assertNull(PayrollSubmissionDeliveryProof::abandonBlockedReason([
            'dispatch_state' => 'sent',
            'acceptance_state' => 'unknown',
        ]));
    }
}
