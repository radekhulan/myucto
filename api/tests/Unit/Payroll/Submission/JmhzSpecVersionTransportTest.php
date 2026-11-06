<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsAgendaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkRequestShape;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\PayrollAgendaCorrectionPolicy;
use MyInvoice\Service\Payroll\Submission\PayrollAgendaGroupCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Ročník specifikace v transportu a katalozích agend: `eType` obálky se bere
 * z jmenného prostoru zmrazené věty, kódy `JMHZ27`/`REGZEC27` znamenají
 * tutéž agendu jako `JMHZ25`/`REGZEC25` a historické řádky `JMHZ25` se
 * chovají beze změny.
 */
final class JmhzSpecVersionTransportTest extends TestCase
{
    public function testEnvelopeTypeIsTakenFromTheFrozenPayloadNamespace(): void
    {
        self::assertSame(
            'JMHZ25',
            JmhzGovTalkRequestShape::forPayload('CSSZ_JMHZ', self::jmhz(JmhzSchemaCatalog::NS_PODANI))->bodyEnvelopeType,
        );
        self::assertSame(
            'JMHZ27',
            JmhzGovTalkRequestShape::forPayload('CSSZ_JMHZ', self::jmhz(JmhzSchemaCatalog::NS_PODANI_2027))->bodyEnvelopeType,
        );
        self::assertSame(
            'REGZEC27',
            JmhzGovTalkRequestShape::forPayload('CSSZ_REGZEC', self::regzec(PayrollRegistrationSchemaCatalog::NS_REGZEC_2027))
                ->bodyEnvelopeType,
        );
        self::assertSame(
            'REGZEC25',
            JmhzGovTalkRequestShape::forPayload('CSSZ_REGZEC', self::regzec(PayrollRegistrationSchemaCatalog::NS_REGZEC_2026))
                ->bodyEnvelopeType,
        );
        // PREZEC nese v 2027 pořád `.../PREZEC/2026`; ročník hlídá už sestavení.
        self::assertSame(
            'PREZEC26',
            JmhzGovTalkRequestShape::forPayload('CSSZ_PREZEC', '<PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026"/>')
                ->bodyEnvelopeType,
        );
    }

    public function testHistoricalClassLookupStaysOn2026(): void
    {
        self::assertSame('JMHZ25', JmhzGovTalkRequestShape::envelopeTypeFor('CSSZ_JMHZ'));
        self::assertSame('JMHZ25', JmhzGovTalkRequestShape::documented()->bodyEnvelopeType);
        self::assertSame('REGZEC25', JmhzGovTalkRequestShape::forSubmissionClass('CSSZ_REGZEC')->bodyEnvelopeType);
        self::assertSame('OZUSPOJ23', JmhzGovTalkRequestShape::envelopeTypeFor('CSSZ_OZUSPOJ'));
        self::assertNull(JmhzGovTalkRequestShape::envelopeTypeFor('CSSZ_NEM_PRI'));
        self::assertSame('CSSZ_JMHZ', JmhzGovTalkRequestShape::classForForm('JMHZ27'));
        self::assertSame('CSSZ_REGZEC', JmhzGovTalkRequestShape::classForForm('REGZEC27'));
    }

    public function testUndocumentedPrezec27EnvelopeFailsClosed(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessage('není doložený `eType`');

        JmhzGovTalkRequestShape::forSubmissionClass('CSSZ_PREZEC', JmhzSpecVersion::V2027);
    }

    public function testEnvelopeRefusesBodyOfAnotherYear(): void
    {
        $software = new JmhzSoftwareIdentification('MyUcto', '1.0');
        $built = (new JmhzGovTalkEnvelope(JmhzGovTalkRequestShape::forForm('JMHZ27')))->build(
            self::jmhz(JmhzSchemaCatalog::NS_PODANI_2027),
            JmhzTransportSample::VARIABLE_SYMBOL,
            'CSSZ_JMHZ',
            'test',
            $software,
        );
        self::assertStringContainsString('eType="JMHZ27"', $built->unsignedXml);

        foreach ([
            ['JMHZ25', 'CSSZ_JMHZ', self::jmhz(JmhzSchemaCatalog::NS_PODANI_2027), 'jmhz_govtalk_payload_invalid'],
            ['JMHZ27', 'CSSZ_JMHZ', self::jmhz(JmhzSchemaCatalog::NS_PODANI), 'jmhz_govtalk_payload_invalid'],
            [
                'REGZEC25',
                'CSSZ_REGZEC',
                self::regzec(PayrollRegistrationSchemaCatalog::NS_REGZEC_2027),
                'jmhz_govtalk_envelope_type_mismatch',
            ],
        ] as [$form, $class, $body, $code]) {
            try {
                (new JmhzGovTalkEnvelope(JmhzGovTalkRequestShape::forForm($form)))->build(
                    $body,
                    JmhzTransportSample::VARIABLE_SYMBOL,
                    $class,
                    'test',
                    $software,
                );
                self::fail("Obálka {$form} přijala tělo jiného ročníku.");
            } catch (JmhzTransportException $exception) {
                self::assertSame($code, $exception->errorCode, $form);
            }
        }
    }

    public function testVersionedCodesShareTheAgendaInEveryCatalog(): void
    {
        $dispatch = new PayrollDispatchCapabilityCatalog();
        self::assertSame(
            PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ,
            $dispatch->forAgenda('JMHZ27')->mode,
        );
        self::assertSame(
            PayrollDispatchCapabilityCatalog::MODE_VREP_REGISTRATION,
            $dispatch->forAgenda('REGZEC27')->mode,
        );
        self::assertSame(PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ, $dispatch->forAgenda('JMHZ25')->mode);
        self::assertSame(PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ, $dispatch->forAgenda('JMHZ')->mode);

        $isds = new PayrollIsdsAgendaCatalog();
        self::assertTrue($isds->has('JMHZ27'));
        self::assertTrue($isds->has('JMHZ25'));
        self::assertSame('JMHZ25', $isds->require('JMHZ27')->code);

        foreach (['JMHZ25', 'JMHZ27', 'REGZEC25', 'REGZEC27', 'PREZEC26'] as $code) {
            self::assertSame(PayrollAgendaGroupCatalog::GROUP_CSSZ, PayrollAgendaGroupCatalog::groupOf($code), $code);
        }

        foreach (['JMHZ25', 'JMHZ27'] as $code) {
            self::assertSame(['accepted', 'partially_accepted'], PayrollAgendaCorrectionPolicy::correctableStatuses($code));
            self::assertFalse(PayrollAgendaCorrectionPolicy::supersedesPredecessorOnAcceptance($code, 'correction'));
            self::assertTrue(PayrollAgendaCorrectionPolicy::supersedesCorrectionChainOnAcceptance($code, 'cancellation'));
        }
        self::assertTrue(PayrollAgendaCorrectionPolicy::supersedesPredecessorOnAcceptance('REGZEC25', 'correction'));
        self::assertFalse(PayrollAgendaCorrectionPolicy::supersedesCorrectionChainOnAcceptance('REGZEC25', 'cancellation'));
    }

    /**
     * Povinnost se eviduje pod kódem agendy: `JMHZ27` by minul jedinečnost
     * řádného hlášení za období (generovaný sloupec migrace 1731).
     */
    public function testObligationRefusesEnvelopeOnlyAgendaCode(): void
    {
        $service = new PayrollObligationService(
            $this->createStub(PayrollSubmissionRepository::class),
            new MockClock('2027-02-05 10:00:00 Europe/Prague'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JMHZ25');

        $service->registrationRows(
            7,
            'JMHZ27',
            'payroll_run',
            'payroll_run:1',
            '2027-01-01',
            '2027-01-31',
            'regular',
            'vrep_apep',
            'jmhz_preparation_snapshot',
            'jmhz_preparation_snapshot:1',
            str_repeat('a', 64),
            '2027-02-01',
            '2027-02-20',
            'calendar_days',
            'jmhz-deadline',
            str_repeat('b', 64),
            'idem-key',
        );
    }

    private static function jmhz(string $namespace): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<jmhz xmlns="' . $namespace . '">'
            . '<VENDOR productName="MyUcto" productVersion="1.0"/>'
            . '<hlavicka><variabilniSymbol>' . JmhzTransportSample::VARIABLE_SYMBOL . '</variabilniSymbol></hlavicka>'
            . '</jmhz>';
    }

    private static function regzec(string $namespace): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><REGZEC xmlns="' . $namespace . '"/>';
    }
}
