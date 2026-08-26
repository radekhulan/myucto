<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifierInterface;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzReceiptVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

final class JmhzReceiptVerifierTest extends TestCase
{
    private function signatures(): JmhzProtocolSignatureVerifierInterface
    {
        return new class implements JmhzProtocolSignatureVerifierInterface {
            public function verifiedProtocolXml(string $bytes, string $environment): string
            {
                return $bytes;
            }
        };
    }

    private function protocol(string $formResult = 'OK'): string
    {
        return JmhzTransportSample::partialProtocol(
            $formResult === 'OK' ? 'OK' : 'ERROR',
            [
                ['guid' => JmhzTransportSample::FORM_GUID, 'result' => 'OK'],
                [
                    'guid' => JmhzTransportSample::OTHER_FORM_GUID,
                    'result' => $formResult,
                    'errMsg' => $formResult === 'OK'
                        ? ''
                        : 'JMHZ25_LT: 20118 - Chybná hodnota',
                    'errNum' => $formResult === 'OK' ? '' : '20118',
                ],
            ],
            errMsg: $formResult === 'OK' ? '' : 'JMHZ25_LT: 20118 - Chybná hodnota',
            errNumber: $formResult === 'OK' ? '0' : '20118',
            correlationId: 'CID0000000001',
        );
    }

    public function testProtocolWithoutSignatureVerificationIsNeverTrusted(): void
    {
        try {
            (new JmhzReceiptVerifier())->verify(
                $this->protocol(),
                'vrep_apep',
                'test',
                null,
            );
            self::fail('Bez ověření podpisu nesmí verifier nic vrátit.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_signature_verifier_missing', $e->errorCode);
        }
    }

    public function testVerifiedProtocolBecomesAReceiptWithPartStatuses(): void
    {
        $verifier = (new JmhzReceiptVerifier($this->signatures()))->withFormPartIds([
            JmhzTransportSample::FORM_GUID => 11,
            JmhzTransportSample::OTHER_FORM_GUID => 12,
        ]);

        $receipt = $verifier->verify(
            $this->protocol('ERROR'),
            'vrep_apep',
            'test',
            'CID0000000001',
        );

        self::assertSame('partially_accepted', $receipt->remoteStatus);
        self::assertSame('CID0000000001', $receipt->correlationReference);
        self::assertSame([11 => 'accepted', 12 => 'rejected'], $receipt->partStatuses);
        self::assertCount(2, $receipt->formOutcomes);
        self::assertSame(JmhzTransportSample::FORM_GUID, $receipt->formOutcomes[0]->formReference);
        self::assertSame(11, $receipt->formOutcomes[0]->partId);
        self::assertSame(1, $receipt->formOutcomes[0]->protocolStatusCode);
        self::assertSame('ProcessedAndComplete', $receipt->formOutcomes[0]->protocolStatusName);
        self::assertSame('accepted', $receipt->formOutcomes[0]->remoteStatus);
        self::assertSame([], $receipt->formOutcomes[0]->errors);
        self::assertSame(JmhzTransportSample::OTHER_FORM_GUID, $receipt->formOutcomes[1]->formReference);
        self::assertSame(12, $receipt->formOutcomes[1]->partId);
        self::assertSame(3, $receipt->formOutcomes[1]->protocolStatusCode);
        self::assertSame('Rejected', $receipt->formOutcomes[1]->protocolStatusName);
        self::assertSame('rejected', $receipt->formOutcomes[1]->remoteStatus);
        self::assertCount(1, $receipt->formOutcomes[1]->errors);
        self::assertSame(20118, $receipt->formOutcomes[1]->errors[0]->code);
        self::assertSame('dis', $receipt->formOutcomes[1]->errors[0]->origin);
        self::assertSame(118, $receipt->formOutcomes[1]->errors[0]->controlId);
    }

    public function testReceiptWithoutAFormMapCarriesOnlyTheOverallStatus(): void
    {
        $receipt = (new JmhzReceiptVerifier($this->signatures()))->verify(
            $this->protocol(),
            'vrep_apep',
            'test',
            'CID0000000001',
        );

        self::assertSame('accepted', $receipt->remoteStatus);
        self::assertSame([], $receipt->partStatuses);
        self::assertCount(2, $receipt->formOutcomes);
        self::assertNull($receipt->formOutcomes[0]->partId);
    }

    public function testCompletenessProtocolDoesNotInventAFormStatusFromItsError(): void
    {
        $receipt = (new JmhzReceiptVerifier($this->signatures()))->verify(
            JmhzTransportSample::completenessProtocol(
                '4',
                'Hlášení je částečně přijato',
                '4',
                'CID0000000001',
                [[
                    'kod' => '40042',
                    'popis' => 'Nesoulad počtu součástí s registrem.',
                    'typChyby' => 'zpracovani',
                    'castPodani' => 'form',
                    'idFormulare' => JmhzTransportSample::FORM_GUID,
                ]],
            ),
            'vrep_apep',
            'test',
            'CID0000000001',
        );

        self::assertSame('partially_accepted', $receipt->remoteStatus);
        self::assertCount(1, $receipt->formOutcomes);
        self::assertNull($receipt->formOutcomes[0]->protocolStatusCode);
        self::assertNull($receipt->formOutcomes[0]->protocolStatusName);
        self::assertNull($receipt->formOutcomes[0]->remoteStatus);
        self::assertCount(1, $receipt->formOutcomes[0]->errors);
        self::assertSame(40042, $receipt->formOutcomes[0]->errors[0]->code);
        self::assertSame('cjmhz', $receipt->formOutcomes[0]->errors[0]->origin);
        self::assertSame(42, $receipt->formOutcomes[0]->errors[0]->controlId);
    }

    /**
     * Protokol bez očekávaného CorrelationID nemá na podání žádnou vazbu —
     * parser variabilní symbol nikde nečte, takže by stačil platně podepsaný
     * protokol jiného zaměstnavatele, aby se z něj přenesl stav „přijato".
     */
    public function testProtocolCannotBePairedWhenTheSubmissionHasNoCorrelation(): void
    {
        try {
            (new JmhzReceiptVerifier($this->signatures()))->verify(
                $this->protocol(),
                'vrep_apep',
                'test',
                null,
            );
            self::fail('Bez uloženého CorrelationID nesmí verifier nic vrátit.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_correlation_unknown', $e->errorCode);
        }
    }

    public function testProtocolForAnotherSubmissionIsRefused(): void
    {
        try {
            (new JmhzReceiptVerifier($this->signatures()))->verify(
                $this->protocol(),
                'vrep_apep',
                'test',
                'CID0000000009',
            );
            self::fail('Cizí protokol musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_correlation_mismatch', $e->errorCode);
        }
    }

    /**
     * Protokol bez CorrelationID se nesmí propašovat jako protokol očekávaného
     * podání. Vzít z něj stav by znamenalo přijmout rozhodnutí o dokumentu,
     * u kterého nevíme, ke kterému podání patří.
     */
    public function testProtocolWithoutCorrelationCannotClaimAnExpectedSubmission(): void
    {
        try {
            (new JmhzReceiptVerifier($this->signatures()))->verify(
                JmhzTransportSample::partialProtocol(correlationId: ''),
                'vrep_apep',
                'test',
                'CID0000000001',
            );
            self::fail('Protokol bez CorrelationID musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_correlation_missing', $e->errorCode);
        }
    }

    public function testForeignFormGuidIsRefusedWhenAMapIsDeclared(): void
    {
        $verifier = (new JmhzReceiptVerifier($this->signatures()))->withFormPartIds([
            JmhzTransportSample::FORM_GUID => 11,
        ]);

        try {
            $verifier->verify($this->protocol(), 'vrep_apep', 'test', 'CID0000000001');
            self::fail('Neznámý GUID formuláře musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_form_unmapped', $e->errorCode);
        }
    }

    /**
     * Podepsaný protokol je tentýž dokument bez ohledu na to, kterou cestou
     * přišel — protokol ČSSZ v1.47, str. 47. ISDS je pro JMHZ rovnocenný kanál,
     * takže jeho protokol se musí dát ověřit stejně jako ten z VREP.
     */
    public function testProtocolFromDataBoxIsVerifiedLikeTheOneFromVrep(): void
    {
        $receipt = (new JmhzReceiptVerifier($this->signatures()))->verify(
            $this->protocol(),
            'isds',
            'test',
            'CID0000000001',
        );

        self::assertSame('CID0000000001', $receipt->correlationReference);
    }

    /**
     * Rozšíření o ISDS nesmí obejít ostatní brány: kanál nikdy nebyl to, co
     * protokol dělá důvěryhodným, ale podpis a CorrelationID pořád platí.
     */
    public function testDataBoxProtocolStillNeedsSignatureVerification(): void
    {
        try {
            (new JmhzReceiptVerifier())->verify($this->protocol(), 'isds', 'test', 'CID0000000001');
            self::fail('Bez ověření podpisu nesmí projít ani protokol z datovky.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_signature_verifier_missing', $e->errorCode);
        }
    }

    public function testDataBoxProtocolOfAnotherSubmissionIsRefused(): void
    {
        try {
            (new JmhzReceiptVerifier($this->signatures()))->verify(
                $this->protocol(),
                'isds',
                'test',
                'CID9999999999',
            );
            self::fail('Protokol jiného podání musí padnout i u datovky.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_correlation_mismatch', $e->errorCode);
        }
    }

    /**
     * Kanály bez doloženého tvaru protokolu zůstávají odmítnuté. Přijmout od
     * nich stav by uzavřelo povinnost podle dokumentu neznámého původu.
     */
    public function testChannelsWithoutADocumentedProtocolAreStillRefused(): void
    {
        foreach (['manual_upload', 'pikr', 'health_portal', 'other'] as $channel) {
            try {
                (new JmhzReceiptVerifier($this->signatures()))->verify(
                    $this->protocol(),
                    $channel,
                    'test',
                    'CID0000000001',
                );
                self::fail("Kanál {$channel} musí padnout.");
            } catch (JmhzTransportException $e) {
                self::assertSame('jmhz_protocol_channel_unsupported', $e->errorCode);
            }
        }
    }
}
