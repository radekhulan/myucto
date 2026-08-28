<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Submission;

use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * W13/C-11. Fronta datové schránky mzdové podklady proti XSD nevaliduje —
 * ověřují se proti připnutému schématu už při MRAZENÍ, kde ještě existuje
 * kontext a kde se dá hlásit srozumitelná chyba, a zmrazený artefakt je pak
 * hash-pinned. Tichý `skipped` u mzdových agend tedy není díra.
 *
 * Poslední brána ale musí vyžadovat DŮKAZ, že se ověření stalo: zapsanou verzi
 * schématu. Bez ní by se do datové schránky dostal nezkontrolovaný soubor a
 * vada by se ozvala až výzvou k odstranění vad podle § 74 DŘ — po několika
 * dnech, kdy už lhůta může být pryč.
 */
final class SubmissionArtifactValidatorTest extends TestCase
{
    public function testPayrollXmlWithoutRecordedSchemaVersionIsRefused(): void
    {
        try {
            $this->validator()->assertTransportAuthority(
                'payroll_submission',
                ['authority' => $this->authority(['xsd_version' => null])],
                'test',
                'JMHZ25',
            );
            self::fail('Datová věta bez zapsaného XSD neměla projít.');
        } catch (SubmissionChannelException $exception) {
            self::assertSame(
                'payroll_artifact_schema_unrecorded',
                $exception->errorCode,
            );
        }
    }

    public function testPayrollXmlWithRecordedSchemaVersionPasses(): void
    {
        $this->validator()->assertTransportAuthority(
            'payroll_submission',
            ['authority' => $this->authority()],
            'test',
            'JMHZ25',
        );

        self::assertTrue(true);
    }

    /** PDF příloha schéma nemá a mít nemůže — na tu se nečeká. */
    public function testPayrollPdfAttachmentNeedsNoSchemaVersion(): void
    {
        $this->validator()->assertTransportAuthority(
            'payroll_submission',
            ['authority' => $this->authority([
                'artifact_kind' => 'outbound_pdf',
                'xsd_version' => null,
            ])],
            'test',
            'JMHZ25',
        );

        self::assertTrue(true);
    }

    /** Mzdové agendy do mapy schémat vědomě nepatří — kontroluje se dřív. */
    public function testPayrollAgendasHaveNoQueueSchema(): void
    {
        $validator = $this->validator();

        self::assertFalse($validator->hasSchemaFor('JMHZ25'));
        self::assertSame(
            ['status' => 'skipped', 'errors' => []],
            $validator->validateArtifact('JMHZ25', [
                'filename' => 'jmhz.xml',
                'mime' => 'application/xml',
                'bytes' => '<jmhz/>',
            ]),
        );
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function authority(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'payroll_submission',
            'environment' => 'test',
            'agenda_code' => 'JMHZ25',
            'status' => 'ready',
            'channel' => 'isds',
            'artifact_kind' => 'outbound_xml',
            'direction' => 'outbound',
            'xsd_version' => 'jmhz-2026.v1',
        ], $overrides);
    }

    private function validator(): SubmissionArtifactValidator
    {
        // Skutečný validátor: čte jen `api/xsd/`, nic nezapisuje a pro mzdové
        // agendy tam schéma není — což je přesně to, co se tu ověřuje.
        return new SubmissionArtifactValidator(new XmlSchemaValidator());
    }
}
