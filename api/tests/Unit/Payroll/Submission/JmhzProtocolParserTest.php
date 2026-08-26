<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolError;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolErrorOrigin;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolKind;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSubmissionStatus;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

final class JmhzProtocolParserTest extends TestCase
{
    private function parser(): JmhzProtocolParser
    {
        return new JmhzProtocolParser();
    }

    public function testCleanPartialProtocolIsProcessedAndComplete(): void
    {
        $report = $this->parser()->parse(JmhzTransportSample::partialProtocol(
            'OK',
            [['guid' => JmhzTransportSample::FORM_GUID, 'result' => 'OK']],
            correlationId: 'CID0000000001',
        ));

        self::assertSame(JmhzProtocolKind::PartialSubmission, $report->kind);
        self::assertSame('CSSZ_JMHZ', $report->submissionClass);
        self::assertSame(JmhzSubmissionStatus::ProcessedAndComplete, $report->status);
        self::assertSame('CID0000000001', $report->correlationReference);
        self::assertSame(
            [JmhzTransportSample::FORM_GUID => JmhzSubmissionStatus::ProcessedAndComplete],
            $report->formStatuses,
        );
    }

    public function testWarningsOnAnAcceptedProtocolMeanPassableErrors(): void
    {
        $report = $this->parser()->parse(JmhzTransportSample::partialProtocol(
            'OK',
            [['guid' => JmhzTransportSample::FORM_GUID, 'result' => 'OK']],
            warnings: 2,
        ));

        self::assertSame(JmhzSubmissionStatus::ContainsPassableErrors, $report->status);
        self::assertSame('accepted', $report->status->payrollRemoteStatus());
    }

    public function testGeneralCheckFailureRejectsTheWholeSubmission(): void
    {
        $report = $this->parser()->parse(JmhzTransportSample::partialProtocol(
            'ERROR',
            [['guid' => JmhzTransportSample::FORM_GUID, 'result' => 'OK']],
            qualifier: 'error',
            errMsg: 'JMHZ25_LT: 62 - Podání musí obsahovat právě jeden formulář.',
            errNumber: '62',
            generalResult: 'ERROR',
        ));

        self::assertSame(JmhzSubmissionStatus::Rejected, $report->status);
        self::assertSame('rejected', $report->status->payrollRemoteStatus());
        self::assertCount(1, $report->errors);
        self::assertSame(JmhzProtocolErrorOrigin::Platform, $report->errors[0]->origin);
        self::assertNull($report->errors[0]->controlId);
    }

    public function testOneFailedFormMeansPartialAcceptanceAndDerivesTheDisControlId(): void
    {
        $report = $this->parser()->parse(JmhzTransportSample::partialProtocol(
            'ERROR',
            [
                ['guid' => JmhzTransportSample::FORM_GUID, 'result' => 'OK'],
                [
                    'guid' => JmhzTransportSample::OTHER_FORM_GUID,
                    'result' => 'ERROR',
                    'errMsg' => 'JMHZ25_LT: 20118 - Chybná hodnota výše sociálního'
                        . ' pojištění; 20119 - Chybná hodnota výše zdravotního pojištění',
                    'errNum' => '20118',
                ],
            ],
            errMsg: 'JMHZ25_LT: 20118 - Chybná hodnota výše sociálního pojištění',
            errNumber: '20118',
        ));

        self::assertSame(JmhzSubmissionStatus::PartiallyAccepted, $report->status);
        self::assertSame('partially_accepted', $report->status->payrollRemoteStatus());
        self::assertSame(
            [
                JmhzTransportSample::FORM_GUID => JmhzSubmissionStatus::ProcessedAndComplete,
                JmhzTransportSample::OTHER_FORM_GUID => JmhzSubmissionStatus::Rejected,
            ],
            $report->formStatuses,
        );

        $errors = $report->errorsForForm(JmhzTransportSample::OTHER_FORM_GUID);
        self::assertCount(2, $errors);
        self::assertSame(JmhzProtocolErrorOrigin::Dis, $errors[0]->origin);
        self::assertSame(118, $errors[0]->requireControlId()->value);
        self::assertSame(119, $errors[1]->requireControlId()->value);
    }

    public function testAllFormsFailedRejectsOnePackageButNotSeveral(): void
    {
        $xml = JmhzTransportSample::partialProtocol(
            'ERROR',
            [[
                'guid' => JmhzTransportSample::FORM_GUID,
                'result' => 'ERROR',
                'errMsg' => 'JMHZ25_LT: 20099 - eldp - datum je mimo měsíc',
                'errNum' => '20099',
            ]],
            errMsg: 'JMHZ25_LT: 20099 - eldp - datum je mimo měsíc',
            errNumber: '20099',
        );

        self::assertSame(
            JmhzSubmissionStatus::Rejected,
            $this->parser()->parse($xml, 1)->status,
        );
        self::assertSame(
            JmhzSubmissionStatus::PartiallyAccepted,
            $this->parser()->parse($xml, 3)->status,
        );
    }

    public function testCompletenessProtocolReadsTheCjmhzStatusAndControlId(): void
    {
        $report = $this->parser()->parse(JmhzTransportSample::completenessProtocol(
            '4',
            'Hlášení je částečně přijato',
            failures: [[
                'kod' => '40042',
                'popis' => 'Nesoulad počtu součástí s registrem.',
                'typChyby' => 'zpracovani',
                'castPodani' => 'form',
                'idFormulare' => JmhzTransportSample::FORM_GUID,
            ]],
        ));

        self::assertSame(JmhzProtocolKind::Completeness, $report->kind);
        self::assertSame(JmhzSubmissionStatus::PartiallyAccepted, $report->status);
        self::assertSame('CID0000000001', $report->correlationReference);
        self::assertCount(1, $report->errors);
        self::assertSame(JmhzProtocolErrorOrigin::Cjmhz, $report->errors[0]->origin);
        self::assertSame(42, $report->errors[0]->requireControlId()->value);
        // Odpověď DZMH nedokládá stav jednotlivého formuláře, jen jeho chyby.
        self::assertSame([], $report->formStatuses);
        self::assertCount(1, $report->errorsForForm(JmhzTransportSample::FORM_GUID));
    }

    /**
     * Obecná vada zamítá celé dílčí podání bez ohledu na to, kolikátou položku
     * ČSSZ vypsala. Hledat ji na prvním indexu znamenalo, že přeházené pořadí
     * změkčilo zamítnutí na částečné přijetí.
     */
    public function testGeneralRejectionIsFoundRegardlessOfItsPosition(): void
    {
        $report = $this->parser()->parse(JmhzTransportSample::partialProtocol(
            'ERROR',
            [['guid' => JmhzTransportSample::FORM_GUID, 'result' => 'OK']],
            qualifier: 'error',
            errMsg: 'JMHZ25_LT: 20118 - Chybná hodnota',
            errNumber: '20118',
            generalResult: 'ERROR',
        ));

        self::assertSame(JmhzSubmissionStatus::Rejected, $report->status);
    }

    /**
     * Souhrnný výsledek OK a zamítnutá součást uvnitř si odporují. Vzít souhrn
     * a zamítnutí zahodit by přeneslo přijetí na podání, které ČSSZ nepřijala
     * celé.
     */
    public function testOverallOkWithARejectedItemIsRefused(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->parser()->parse(JmhzTransportSample::partialProtocol(
            'OK',
            [
                [
                    'guid' => JmhzTransportSample::FORM_GUID,
                    'result' => 'ERROR',
                    'errMsg' => 'JMHZ25_LT: 20118 - Chybná hodnota',
                    'errNum' => '20118',
                ],
            ],
        ));
    }

    /**
     * Obálka s příznakem zamítnutí smí doprovázet částečné přijetí — z pohledu
     * odesílatele je to pořád odmítnutá zpráva, jen ne celá.
     */
    public function testRejectedQualifierMayAccompanyPartialAcceptance(): void
    {
        $report = $this->parser()->parse(JmhzTransportSample::partialProtocol(
            'ERROR',
            [
                ['guid' => JmhzTransportSample::FORM_GUID, 'result' => 'OK'],
                [
                    'guid' => JmhzTransportSample::OTHER_FORM_GUID,
                    'result' => 'ERROR',
                    'errMsg' => 'JMHZ25_LT: 20118 - Chybná hodnota',
                    'errNum' => '20118',
                ],
            ],
            qualifier: 'error',
            errMsg: 'JMHZ25_LT: 20118 - Chybná hodnota',
            errNumber: '20118',
        ));

        self::assertSame(JmhzSubmissionStatus::PartiallyAccepted, $report->status);
    }

    /**
     * Odpověď DZMH nese protokoly všech podání za období. Brát první znamenalo
     * přenést stav cizího podání na naše.
     */
    public function testCompletenessAnswerPicksTheProtocolOfTheExpectedSubmission(): void
    {
        $xml = str_replace(
            '<protokoly><protokol>',
            '<protokoly><protokol>'
                . '<idKonkretnihoPodani>CID0000000009</idKonkretnihoPodani>'
                . '<kod>3</kod><nazev>Podání bylo zamítnuto</nazev>'
                . '<chybySeznam></chybySeznam></protokol><protokol>',
            JmhzTransportSample::completenessProtocol('4', 'Hlášení je částečně přijato', '1'),
        );

        $report = $this->parser()->parse($xml, 1, 'CID0000000001');

        self::assertSame('CID0000000001', $report->correlationReference);
        self::assertSame(JmhzSubmissionStatus::ProcessedAndComplete, $report->status);
    }

    public function testCompletenessAnswerWithSeveralProtocolsIsAmbiguousWithoutExpectation(): void
    {
        $xml = str_replace(
            '<protokoly><protokol>',
            '<protokoly><protokol>'
                . '<idKonkretnihoPodani>CID0000000009</idKonkretnihoPodani>'
                . '<kod>3</kod><nazev>Podání bylo zamítnuto</nazev>'
                . '<chybySeznam></chybySeznam></protokol><protokol>',
            JmhzTransportSample::completenessProtocol('4', 'Hlášení je částečně přijato', '1'),
        );

        try {
            $this->parser()->parse($xml);
            self::fail('Víc protokolů bez očekávaného CorrelationID musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_ambiguous', $e->errorCode);
        }
    }

    /**
     * Stav NAŠEHO podání je stav jeho protokolu, ne souhrnný stav celého
     * hlášení. Úplné hlášení může obsahovat zamítnuté podání.
     */
    public function testSubmissionStatusComesFromItsOwnProtocolNotFromTheOverallReport(): void
    {
        $report = $this->parser()->parse(
            JmhzTransportSample::completenessProtocol(
                '1',
                'Hlášení je zpracováno a je úplné',
                '3',
            ),
            1,
            'CID0000000001',
        );

        self::assertSame(JmhzSubmissionStatus::Rejected, $report->status);
    }

    public function testAllSixDocumentedStatusesAreRecognisedByCodeAndLabel(): void
    {
        $documented = [
            1 => 'Hlášení je zpracováno a je úplné',
            2 => 'Hlášení nebylo přijato',
            3 => 'Hlášení bylo zamítnuto',
            4 => 'Hlášení je částečně přijato',
            5 => 'Hlášení je ve zpracování',
            6 => 'Hlášení obsahuje propustné chyby',
        ];

        $parsed = [];
        foreach ($documented as $code => $label) {
            // Stav NAŠEHO podání je stav jeho protokolu, ne souhrnný stav
            // celého hlášení — proto se mění obojí zároveň.
            $report = $this->parser()->parse(
                JmhzTransportSample::completenessProtocol(
                    (string) $code,
                    $label,
                    (string) $code,
                ),
            );
            self::assertSame(
                JmhzSubmissionStatus::fromCode($code),
                $report->status,
            );
            self::assertSame(
                JmhzSubmissionStatus::fromDocumentedLabel($label),
                $report->status,
            );
            $parsed[] = $report->status->payrollRemoteStatus();
        }

        self::assertSame(
            [
                'accepted',
                'rejected',
                'rejected',
                'partially_accepted',
                'processing',
                'accepted',
            ],
            $parsed,
        );
    }

    public function testStatusCodeAndLabelMustAgree(): void
    {
        try {
            $this->parser()->parse(JmhzTransportSample::completenessProtocol(
                '5',
                'Hlášení je částečně přijato',
            ));
            self::fail('Rozpor kódu a názvu stavu musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_status_conflict', $e->errorCode);
        }
    }

    public function testUnknownStatusCodeIsRefused(): void
    {
        try {
            $this->parser()->parse(
                JmhzTransportSample::completenessProtocol('7', 'Hlášení je zvláštní'),
            );
            self::fail('Sedmý stav neexistuje.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_status_unknown', $e->errorCode);
        }
    }

    public function testUnknownErrorCodeIsRefusedInsteadOfBeingFiledAsUnclassified(): void
    {
        try {
            $this->parser()->parse(JmhzTransportSample::partialProtocol(
                'ERROR',
                [[
                    'guid' => JmhzTransportSample::FORM_GUID,
                    'result' => 'ERROR',
                    'errMsg' => 'JMHZ25_LT: 999 - Neznámá chyba',
                    'errNum' => '999',
                ]],
            ));
            self::fail('Neznámý kód chyby musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_error_code_unknown', $e->errorCode);
        }
    }

    public function testUnknownResultValueIsRefused(): void
    {
        try {
            $this->parser()->parse(JmhzTransportSample::partialProtocol('WARNING'));
            self::fail('Neznámý výsledek musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_result_unknown', $e->errorCode);
        }
    }

    public function testPartialProtocolFormReferenceMustBeAGuid(): void
    {
        try {
            $this->parser()->parse(JmhzTransportSample::partialProtocol(
                'OK',
                [['guid' => 'FORM-1', 'result' => 'OK']],
            ));
            self::fail('Neplatný GUID formuláře nesmí projít do evidence výsledků.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_form_unidentified', $e->errorCode);
        }
    }

    public function testUnknownQualifierIsRefused(): void
    {
        try {
            $this->parser()->parse(
                JmhzTransportSample::partialProtocol('OK', [], 'acknowledgement'),
            );
            self::fail('Neznámý Qualifier musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_qualifier_unknown', $e->errorCode);
        }
    }

    public function testQualifierMustAgreeWithTheProcessingResult(): void
    {
        try {
            $this->parser()->parse(JmhzTransportSample::partialProtocol(
                'OK',
                [['guid' => JmhzTransportSample::FORM_GUID, 'result' => 'OK']],
                qualifier: 'error',
            ));
            self::fail('Rozpor obálky a protokolu musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_qualifier_conflict', $e->errorCode);
        }
    }

    public function testDeclaredFirstErrorCodeMustMatchTheMessage(): void
    {
        try {
            $this->parser()->parse(JmhzTransportSample::partialProtocol(
                'ERROR',
                [],
                errMsg: 'JMHZ25_LT: 20118 - Chybná hodnota',
                errNumber: '20119',
            ));
            self::fail('Rozpor errNumber a errMsg musí padnout.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_error_code_conflict', $e->errorCode);
        }
    }

    public function testUnreadableXmlAndUnknownRootAreRefused(): void
    {
        $codes = [];
        foreach (['', 'tohle není XML', '<Neco/>'] as $input) {
            try {
                $this->parser()->parse($input);
            } catch (JmhzTransportException $e) {
                $codes[] = $e->errorCode;
            }
        }

        self::assertSame(
            ['jmhz_protocol_unreadable', 'jmhz_protocol_unreadable', 'jmhz_protocol_kind_unknown'],
            $codes,
        );
    }

    public function testCjmhzAndDisOffsetsBothResolveBackToTheSameControlId(): void
    {
        $dis = JmhzProtocolError::fromCode(20_226, 'Nesoulad počtu součástí');
        $cjmhz = JmhzProtocolError::fromCode(40_226, 'Nesoulad počtu součástí');

        self::assertSame(226, $dis->requireControlId()->value);
        self::assertSame(226, $cjmhz->requireControlId()->value);
        self::assertSame(JmhzProtocolErrorOrigin::Dis, $dis->origin);
        self::assertSame(JmhzProtocolErrorOrigin::Cjmhz, $cjmhz->origin);
        self::assertSame(20_226, $dis->controlId?->disErrorCode());
        self::assertSame(40_226, $cjmhz->controlId?->cjmhzErrorCode());
    }

    public function testRoundTechnicalWrapperCodesCarryNoControlId(): void
    {
        $wrapper = JmhzProtocolError::fromCode(20_000, 'Technická chyba.');

        self::assertNull($wrapper->controlId);
        try {
            $wrapper->requireControlId();
            self::fail('Obálková technická chyba není kontrola.');
        } catch (JmhzTransportException $e) {
            self::assertSame('jmhz_protocol_error_not_a_control', $e->errorCode);
        }
    }
}
