<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolErrorOrigin;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolPartKind;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifierInterface;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzReceiptVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSubmissionStatus;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

/**
 * Protokoly ČSSZ k registracím zaměstnance (PREZEC, REGZEC).
 *
 * Tvar odpovídá protokolům zkušebních podání do testovacího prostředí ČSSZ
 * z 8. 10. 2026 a MPSV „Interpretace protokolů REGZEC" v1.0; hodnoty osob,
 * identifikátorů a CorrelationID jsou syntetické.
 */
final class JmhzRegistrationProtocolParserTest extends TestCase
{
    private const CORRELATION = 'A0000000000000000000000000000001';
    private const FORM_GUID = '0A0A0A0A-1111-4222-8333-444455556666';
    private const BIRTH_NUMBER = '7001010001';
    private const OIC = '1000000001';
    private const ID_PPV = '4000000000001';

    public function testAcceptedPrezecKeepsTheFormGuidFromTheIdentifier(): void
    {
        $report = (new JmhzProtocolParser())->parse(self::protocol(
            'CSSZ_PREZEC',
            'response',
            'OK',
            [
                self::item('PREZEC26', '', '', 'OK'),
                self::item('PREZEC26', '1', self::BIRTH_NUMBER . ';' . self::FORM_GUID, 'OK'),
            ],
        ));

        self::assertSame('CSSZ_PREZEC', $report->submissionClass);
        self::assertSame(JmhzSubmissionStatus::ProcessedAndComplete, $report->status);
        self::assertSame(self::CORRELATION, $report->correlationReference);
        self::assertSame(
            [self::FORM_GUID => JmhzSubmissionStatus::ProcessedAndComplete],
            $report->formStatuses,
        );
        self::assertSame('accepted', $report->payrollRemoteStatus());
    }

    /**
     * Přesně tenhle protokol (odmítnutí A1 kódem post DIS validace) nechal
     * pokus navždy čekat: kód 103901602 není v katalogu kontrol MH a `errNum`
     * položky nese jen jeho poslední trojčíslí.
     */
    public function testRejectedRegzecA1WithPostDisValidationCodeIsRejected(): void
    {
        $message = 'REGZEC25_LT: 103901602 - Osoba bez uvedeného IK MPSV nebyla'
            . ' nalezena v evidenci ČSSZ podle uvedeného RČ, jmenných údajů a dokladu.';
        $report = (new JmhzProtocolParser())->parse(self::protocol(
            'CSSZ_REGZEC',
            'error',
            'ERROR',
            [
                self::item('REGZEC25', '', '', 'OK'),
                self::item('REGZEC25', '1', self::BIRTH_NUMBER . ';;', 'ERROR', $message, '602'),
            ],
            $message,
            '5',
        ));

        self::assertSame(JmhzSubmissionStatus::Rejected, $report->status);
        self::assertSame('rejected', $report->payrollRemoteStatus());
        self::assertCount(1, $report->errors);
        self::assertSame(103901602, $report->errors[0]->code);
        self::assertSame(JmhzProtocolErrorOrigin::Platform, $report->errors[0]->origin);
        self::assertNull($report->errors[0]->controlId);
        $form = self::onlyForm($report->parts);
        self::assertSame(JmhzSubmissionStatus::Rejected, $form->status);
        self::assertNull($form->ikMpsv);
        self::assertSame(103901602, $form->errors[0]->code);
        self::assertStringStartsWith('Osoba bez uvedeného IK MPSV', $form->errors[0]->message);
    }

    public function testAcceptedRegzecA1CarriesOicAndIdPpvUnderAStableFormKey(): void
    {
        $xml = self::protocol('CSSZ_REGZEC', 'response', 'OK', [
            self::item('REGZEC25', '', '', 'OK'),
            self::item(
                'REGZEC25',
                '1',
                self::BIRTH_NUMBER . ';' . self::OIC . ';' . self::ID_PPV,
                'OK',
            ),
        ]);
        $first = (new JmhzProtocolParser())->parse($xml);
        $again = (new JmhzProtocolParser())->parse($xml);
        $other = (new JmhzProtocolParser())->parse(str_replace(
            self::CORRELATION,
            'A0000000000000000000000000000002',
            $xml,
        ));

        $form = self::onlyForm($first->parts);
        self::assertSame(JmhzSubmissionStatus::ProcessedAndComplete, $first->status);
        self::assertSame(self::OIC, $form->ikMpsv);
        self::assertSame(self::ID_PPV, $form->idPpv);
        self::assertMatchesRegularExpression(
            '/^[0-9A-F]{8}-[0-9A-F]{4}-8[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/D',
            (string) $form->formGuid,
        );
        self::assertSame($form->formGuid, self::onlyForm($again->parts)->formGuid);
        self::assertNotSame($form->formGuid, self::onlyForm($other->parts)->formGuid);
    }

    /** Testovací prostředí ČSSZ identifikátory nevrací („;;"): přijetí platí, čísla ne. */
    public function testAcceptedRegzecWithoutIdentifiersAssignsNothing(): void
    {
        $report = (new JmhzProtocolParser())->parse(self::protocol(
            'CSSZ_REGZEC',
            'response',
            'OK',
            [self::item('REGZEC25', '', '', 'OK'), self::item('REGZEC25', '1', ';;', 'OK')],
        ));

        $form = self::onlyForm($report->parts);
        self::assertSame(JmhzSubmissionStatus::ProcessedAndComplete, $report->status);
        self::assertNull($form->ikMpsv);
        self::assertNull($form->idPpv);
    }

    /** Ukázka částečného přijetí z Interpretace REGZEC: holý text, kód jen v `errNum`. */
    public function testPartialAcceptanceWithPlainTextErrorReadsTheCodeFromErrNum(): void
    {
        $text = 'Období trvání PV se překrývá s jiným již evidovaným PV.';
        $report = (new JmhzProtocolParser())->parse(self::protocol(
            'CSSZ_REGZEC',
            'response',
            'ERROR',
            [
                self::item('REGZEC25', '', '', 'OK'),
                self::item('REGZEC25', '1', self::BIRTH_NUMBER . ';' . self::OIC . ';' . self::ID_PPV, 'OK'),
                self::item('REGZEC25', '2', '7001010002', 'ERROR', $text, '604'),
            ],
            $text,
            '604',
        ));

        self::assertSame(JmhzSubmissionStatus::PartiallyAccepted, $report->status);
        self::assertSame('partially_accepted', $report->payrollRemoteStatus());
        $forms = array_values(array_filter(
            $report->parts,
            static fn ($part): bool => $part->kind === JmhzProtocolPartKind::Form,
        ));
        self::assertCount(2, $forms);
        self::assertSame(JmhzSubmissionStatus::ProcessedAndComplete, $forms[0]->status);
        self::assertSame(JmhzSubmissionStatus::Rejected, $forms[1]->status);
        self::assertSame(604, $forms[1]->errors[0]->code);
        self::assertSame($text, $forms[1]->errors[0]->message);
        self::assertNull($forms[1]->ikMpsv);
    }

    /** Zamítnuté podání se posílá celé znovu, takže ani formulář „OK" není přijatý. */
    public function testGeneralRejectionMarksEveryFormRejected(): void
    {
        $report = (new JmhzProtocolParser())->parse(self::protocol(
            'CSSZ_PREZEC',
            'error',
            'ERROR',
            [
                self::item('PREZEC26', '', '', 'ERROR', 'PREZEC26_LT_G: 061 - Podání neprošlo validací.', '61'),
                self::item('PREZEC26', '1', self::BIRTH_NUMBER . ';' . self::FORM_GUID, 'OK'),
            ],
            'PREZEC26_LT_G: 061 - Podání neprošlo validací.',
            '61',
        ));

        self::assertSame(JmhzSubmissionStatus::Rejected, $report->status);
        self::assertSame(
            [self::FORM_GUID => JmhzSubmissionStatus::Rejected],
            $report->formStatuses,
        );
        self::assertSame(JmhzSubmissionStatus::Rejected, self::onlyForm($report->parts)->status);
    }

    public function testQualifierMustAgreeWithTheRegistrationResult(): void
    {
        self::assertRefused('jmhz_protocol_qualifier_conflict', self::protocol(
            'CSSZ_REGZEC',
            'response',
            'ERROR',
            [
                self::item('REGZEC25', '', '', 'OK'),
                self::item('REGZEC25', '1', '', 'ERROR', 'REGZEC25_LT: 306 - Neplatné RČ.', '306'),
            ],
        ));
    }

    public function testAcceptedPrezecWithoutFormGuidIsRefused(): void
    {
        self::assertRefused('jmhz_protocol_form_unidentified', self::protocol(
            'CSSZ_PREZEC',
            'response',
            'OK',
            [self::item('PREZEC26', '', '', 'OK'), self::item('PREZEC26', '1', self::BIRTH_NUMBER, 'OK')],
        ));
    }

    public function testJmhzSubtypeInsideARegistrationProtocolIsRefused(): void
    {
        self::assertRefused('jmhz_protocol_part_unknown', self::protocol(
            'CSSZ_REGZEC',
            'response',
            'OK',
            [self::item('REGZEC25', '', '', 'OK'), self::item('FORM', '1', '', 'OK')],
        ));
    }

    /** REGZEC27 (události od 1. 1. 2027) má tentýž tvar protokolu, jen jiný ročník. */
    public function testRegzec27ProtocolIsReadLikeRegzec25(): void
    {
        $report = (new JmhzProtocolParser())->parse(self::protocol('CSSZ_REGZEC', 'response', 'OK', [
            self::item('REGZEC27', '', '', 'OK'),
            self::item('REGZEC27', '1', self::BIRTH_NUMBER . ';' . self::OIC . ';' . self::ID_PPV, 'OK'),
        ]));

        self::assertSame(JmhzSubmissionStatus::ProcessedAndComplete, $report->status);
        self::assertSame(self::OIC, self::onlyForm($report->parts)->ikMpsv);
    }

    public function testRegistrationProtocolMixingYearsIsRefused(): void
    {
        self::assertRefused('jmhz_protocol_part_unknown', self::protocol(
            'CSSZ_REGZEC',
            'response',
            'OK',
            [self::item('REGZEC25', '', '', 'OK'), self::item('REGZEC27', '1', ';;', 'OK')],
        ));
    }

    public function testDeclaredItemCodeMustMatchTheMessage(): void
    {
        self::assertRefused('jmhz_protocol_error_code_conflict', self::protocol(
            'CSSZ_REGZEC',
            'error',
            'ERROR',
            [
                self::item('REGZEC25', '', '', 'OK'),
                self::item('REGZEC25', '1', '', 'ERROR', 'REGZEC25_LT: 103901602 - Osoba nenalezena.', '603'),
            ],
        ));
    }

    public function testMalformedRegzecIdentifierIsRefused(): void
    {
        self::assertRefused('jmhz_protocol_identifier_unreadable', self::protocol(
            'CSSZ_REGZEC',
            'response',
            'OK',
            [self::item('REGZEC25', '', '', 'OK'), self::item('REGZEC25', '1', 'a;b', 'OK')],
        ));
    }

    /**
     * Ověřený registrační protokol vydá výsledek formuláře s OIČ a ID PPV.
     * Z něj platforma podání převezme identifikátory vztahu
     * ({@see \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationReceiptIdentityService}).
     */
    public function testReceiptVerifierPassesOicAndIdPpvToThePlatform(): void
    {
        $xml = self::protocol('CSSZ_REGZEC', 'response', 'OK', [
            self::item('REGZEC25', '', '', 'OK'),
            self::item('REGZEC25', '1', self::BIRTH_NUMBER . ';' . self::OIC . ';' . self::ID_PPV, 'OK'),
        ]);
        $signatures = new class () implements JmhzProtocolSignatureVerifierInterface {
            public function verifiedProtocolXml(string $bytes, string $environment): string
            {
                return $bytes;
            }
        };

        $verified = (new JmhzReceiptVerifier($signatures))
            ->verify($xml, 'vrep_apep', 'test', self::CORRELATION);

        self::assertSame('accepted', $verified->remoteStatus);
        self::assertCount(1, $verified->formOutcomes);
        self::assertSame('accepted', $verified->formOutcomes[0]->remoteStatus);
        self::assertSame(self::OIC, $verified->formOutcomes[0]->externalPersonReference);
        self::assertSame(self::ID_PPV, $verified->formOutcomes[0]->externalEmploymentReference);
    }

    /**
     * Protokol k NEMPRI25/HZUPN20 (`CSSZ_NEM_PRI`) nebo OZUSPOJ23
     * (`CSSZ_OZUSPOJ`), který neodpovídá doloženému tvaru (přijetí bez
     * formuláře, cizí druh formuláře), parser nesmí vyložit podle JMHZ ani
     * mlčky zahodit — pojmenuje ho. Doložený tvar kryje
     * {@see JmhzCsszFormProtocolParserTest}.
     */
    public function testUndocumentedProtocolShapeIsNamedNotGuessed(): void
    {
        foreach (['CSSZ_NEM_PRI', 'CSSZ_OZUSPOJ'] as $class) {
            try {
                (new JmhzProtocolParser())->parse(self::protocol($class, 'response', 'OK', [
                    self::item('NEMPRI25', '', '', 'OK'),
                ]));
                self::fail("Protokol {$class} prošel bez doloženého tvaru.");
            } catch (JmhzTransportException $exception) {
                self::assertSame(JmhzProtocolParser::UNDOCUMENTED_SHAPE_CODE, $exception->errorCode);
            }
        }
    }

    private static function assertRefused(string $code, string $xml): void
    {
        try {
            (new JmhzProtocolParser())->parse($xml);
        } catch (JmhzTransportException $exception) {
            self::assertSame($code, $exception->errorCode);

            return;
        }
        self::fail("Protokol prošel, čekalo se odmítnutí {$code}.");
    }

    /** @param list<\MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolPart> $parts */
    private static function onlyForm(array $parts): \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolPart
    {
        $forms = array_values(array_filter(
            $parts,
            static fn ($part): bool => $part->kind === JmhzProtocolPartKind::Form,
        ));
        self::assertCount(1, $forms);

        return $forms[0];
    }

    private static function item(
        string $subtype,
        string $sequence,
        string $identifier,
        string $result,
        string $errMsg = '',
        string $errNum = '',
    ): string {
        return sprintf(
            '<Item sqnr="%s" identifier="%s" subtype="%s" period="" result="%s" errMsg="%s" errNum="%s" />',
            $sequence,
            $identifier,
            $subtype,
            $result,
            htmlspecialchars($errMsg, ENT_XML1 | ENT_QUOTES),
            $errNum,
        );
    }

    /** @param list<string> $items */
    private static function protocol(
        string $class,
        string $qualifier,
        string $result,
        array $items,
        string $errMsg = '',
        string $errNumber = '0',
    ): string {
        $errors = array_filter($items, static fn (string $item): bool => str_contains($item, 'result="ERROR"'));

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
            . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
            . "<Class>{$class}</Class><Qualifier>{$qualifier}</Qualifier><Function>submit</Function>"
            . '<TransactionID /><CorrelationID>' . self::CORRELATION . '</CorrelationID>'
            . '<GatewayTimestamp>2026-10-08T01:28:00.000</GatewayTimestamp>'
            . '</MessageDetails></Header><GovTalkDetails><Keys><Key Type="SpokeName">VREP</Key></Keys></GovTalkDetails>'
            . '<Body Id="1"><Message xmlns="http://www.cssz.cz/XMLSchema/envelope" version="1.2" eType="response">'
            . '<Header /><Body>'
            . sprintf(
                '<ProcessingResult type="%s" version="1,0" result="%s" errMsg="%s" errNumber="%s" count="%d" countErr="%d" countWar="0">',
                $class,
                $result,
                htmlspecialchars($errMsg, ENT_XML1 | ENT_QUOTES),
                $errNumber,
                max(0, count($items) - 1),
                count($errors),
            )
            . '<Error><RaisedBy /><Number>' . $errNumber . '</Number><Type>' . $class . '</Type><Text /></Error>'
            . '<Details>' . implode('', $items) . '</Details></ProcessingResult>'
            . '</Body></Message></Body></GovTalkMessage>';
    }
}
