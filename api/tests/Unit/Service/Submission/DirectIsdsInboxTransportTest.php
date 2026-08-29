<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportTimeout;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use PHPUnit\Framework\TestCase;

final class DirectIsdsInboxTransportTest extends TestCase
{
    public function testPasswordLoginUsesTestEndpointAndParsesInbox(): void
    {
        $seenUrl = '';
        $seenBody = '';
        $transport = new DirectIsdsInboxTransport(
            static function (string $url, string $body) use (&$seenUrl, &$seenBody): array {
                $seenUrl = $url;
                $seenBody = $body;
                return ['status' => 200, 'body' => self::listResponse()];
            },
        );

        $rows = $transport->listReceived($this->passwordContext());

        self::assertSame('https://ws1.datovka-test.gov.cz/DS/dx', $seenUrl);
        self::assertStringContainsString('GetListOfReceivedMessages', $seenBody);
        self::assertStringContainsString('<isds:dmLimit>50</isds:dmLimit>', $seenBody);
        self::assertCount(1, $rows);
        self::assertSame('123456789', $rows[0]['message_id']);
        self::assertSame('Finanční úřad', $rows[0]['sender_name']);
    }

    public function testReceivedMessagesArePagedAndDeduplicatedPastFirstFifty(): void
    {
        $offsets = [];
        $transport = new DirectIsdsInboxTransport(
            static function (string $url, string $body) use (&$offsets): array {
                preg_match('/<isds:dmOffset>(\d+)<\/isds:dmOffset>/', $body, $match);
                $offset = (int) ($match[1] ?? 0);
                $offsets[] = $offset;
                $ids = $offset === 1
                    ? range(100000001, 100000050)
                    : [100000050, ...range(100000051, 100000075)];
                return ['status' => 200, 'body' => self::listResponse($ids)];
            },
        );

        $rows = $transport->listReceived($this->passwordContext());

        self::assertSame([1, 51], $offsets);
        self::assertCount(75, $rows);
        self::assertSame('100000001', $rows[0]['message_id']);
        self::assertSame('100000075', $rows[74]['message_id']);
    }

    public function testFullTenthPageFailsClosedInsteadOfClaimingInboxIsComplete(): void
    {
        $calls = 0;
        $transport = new DirectIsdsInboxTransport(
            static function (string $url, string $body) use (&$calls): array {
                $calls++;
                preg_match('/<isds:dmOffset>(\d+)<\/isds:dmOffset>/', $body, $match);
                $offset = (int) ($match[1] ?? 1);
                return ['status' => 200, 'body' => self::listResponse(range(100000000 + $offset, 100000000 + $offset + 49))];
            },
        );

        try {
            $transport->listReceived($this->passwordContext());
            self::fail('Plný bezpečnostní limit nesmí být vydáván za kompletní seznam.');
        } catch (\MyInvoice\Service\Submission\Channel\SubmissionChannelException $e) {
            self::assertSame('isds_inbox_list_limit_reached', $e->errorCode);
        }
        self::assertSame(10, $calls);
    }

    public function testSignedMessageIsDecodedAsZfoBytes(): void
    {
        $zfo = "synthetic-zfo\0bytes";
        $transport = new DirectIsdsInboxTransport(
            static fn (): array => ['status' => 200, 'body' => self::downloadResponse($zfo)],
        );

        self::assertSame($zfo, $transport->downloadMessage($this->passwordContext(), '123456789'));
    }

    private function passwordContext(): ChannelContext
    {
        return new ChannelContext(
            7,
            'test',
            new ChannelCredentials(
                boxId: '',
                authMode: 'password',
                username: SensitiveValue::fromProducer(static fn (): string => 'synthetic-user'),
                password: SensitiveValue::fromProducer(static fn (): string => 'synthetic-password'),
            ),
        );
    }

    /** @param list<int|string> $messageIds */
    private static function listResponse(array $messageIds = [123456789]): string
    {
        $records = '';
        foreach ($messageIds as $messageId) {
            $records .= '<isds:dmRecord><isds:dmID>' . $messageId . '</isds:dmID>'
                . '<isds:dbIDSender>abcdefg</isds:dbIDSender><isds:dmSender>Finanční úřad</isds:dmSender>'
                . '<isds:dmAnnotation>Syntetická zpráva</isds:dmAnnotation>'
                . '<isds:dmDeliveryTime>2026-08-25T12:00:00+02:00</isds:dmDeliveryTime></isds:dmRecord>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20">'
            . '<soap:Body><isds:GetListOfReceivedMessagesResponse>' . $records
            . '<isds:dmStatus><isds:dmStatusCode>0000</isds:dmStatusCode><isds:dmStatusMessage>OK</isds:dmStatusMessage></isds:dmStatus>'
            . '</isds:GetListOfReceivedMessagesResponse></soap:Body></soap:Envelope>';
    }

    // ───────────────────── odesílání v potvrzené relaci ─────────────────────

    public function testSendIsRefusedWithoutConfirmedHumanSession(): void
    {
        $calls = 0;
        $transport = new DirectIsdsInboxTransport(
            static function () use (&$calls): array {
                $calls++;
                return ['status' => 200, 'body' => self::listResponse()];
            },
        );

        try {
            $transport->createMessage($this->passwordContext(), 'abcdefg', 'Věc', 'MU-1', self::attachment());
            self::fail('Bez relace potvrzené člověkem se nesmí odesílat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_send_requires_confirmed_session', $e->errorCode);
        }
        self::assertSame(0, $calls, 'Odmítnutí musí padnout dřív, než se cokoliv odešle.');
    }

    public function testSendIsRefusedWhenMobileKeySessionIsNotFinished(): void
    {
        $transport = new DirectIsdsInboxTransport(static fn (): array => ['status' => 200, 'body' => '']);

        try {
            $transport->createMessage($this->mobileKeyContext(null), 'abcdefg', 'Věc', 'MU-1', self::attachment());
            self::fail('Nedokončené přihlášení nesmí odeslat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_session_missing', $e->errorCode);
        }
    }

    public function testExpiredMobileKeySessionFailsWithItsOwnErrorAndIsNotRenewed(): void
    {
        $calls = 0;
        $transport = new DirectIsdsInboxTransport(
            static function () use (&$calls): array {
                $calls++;
                // ISDS na mrtvou relaci odpovídá přesměrováním na přihlášení.
                return ['status' => 302, 'body' => ''];
            },
        );

        try {
            $transport->createMessage($this->mobileKeyContext(), 'abcdefg', 'Věc', 'MU-1', self::attachment());
            self::fail('Vypršelá relace musí selhat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_session_expired', $e->errorCode);
        }
        self::assertSame(1, $calls, 'Relace se nesmí obnovovat ani zkoušet znovu.');
    }

    public function testConfirmedSessionSendsCreateMessageAndReturnsReceipt(): void
    {
        $urls = [];
        $bodies = [];
        $transport = new DirectIsdsInboxTransport(
            static function (string $url, string $body) use (&$urls, &$bodies): array {
                $urls[] = $url;
                $bodies[] = $body;
                return str_contains($body, 'CreateMessage')
                    ? ['status' => 200, 'body' => self::sendResponse('987654321')]
                    : ['status' => 200, 'body' => self::sentListResponse([])];
            },
        );

        $receipt = $transport->createMessage(
            $this->mobileKeyContext(),
            'ABCDEFG',
            'Přiznání k DPH',
            'MU-DPH-2026-07',
            self::attachment(),
        );

        self::assertSame('987654321', $receipt->messageId);
        self::assertSame('0000', $receipt->statusCode);
        // Odesílá se službou `dz`, ne `dm` — ta v ISDS neexistuje.
        self::assertSame('https://www.datovka-test.gov.cz/apps/DS/dz', $urls[1]);
        self::assertStringContainsString('https://www.datovka-test.gov.cz/apps/DS/dx', $urls[0]);
        self::assertStringContainsString('<isds:dbIDRecipient>abcdefg</isds:dbIDRecipient>', $bodies[1]);
        self::assertStringContainsString('<isds:dmSenderIdent>MU-DPH-2026-07</isds:dmSenderIdent>', $bodies[1]);
        self::assertStringContainsString('dmFileMetaType="main"', $bodies[1]);
        self::assertStringContainsString(base64_encode('<xml/>'), $bodies[1]);
    }

    public function testSecondSendOfTheSameSubmissionDoesNotCreateASecondMessage(): void
    {
        $created = 0;
        $transport = new DirectIsdsInboxTransport(
            static function (string $url, string $body) use (&$created): array {
                if (str_contains($body, 'CreateMessage')) {
                    $created++;
                    return ['status' => 200, 'body' => self::sendResponse('111222333')];
                }
                // Po prvním odeslání už zpráva v odeslaných je.
                return [
                    'status' => 200,
                    'body' => self::sentListResponse($created > 0 ? [['111222333', 'MU-DPH-2026-07']] : []),
                ];
            },
        );

        $first = $transport->createMessage($this->mobileKeyContext(), 'abcdefg', 'Věc', 'MU-DPH-2026-07', self::attachment());
        $second = $transport->createMessage($this->mobileKeyContext(), 'abcdefg', 'Věc', 'MU-DPH-2026-07', self::attachment());

        self::assertSame('111222333', $first->messageId);
        self::assertSame('111222333', $second->messageId);
        self::assertSame(1, $created, 'Dvojí odeslání nesmí vyrobit druhou datovou zprávu.');
    }

    public function testRejectedSendIsAProvenFailureNotUncertainty(): void
    {
        $transport = new DirectIsdsInboxTransport(
            static fn (string $url, string $body): array => str_contains($body, 'CreateMessage')
                ? ['status' => 200, 'body' => self::sendResponse(null, '1201', 'Schránka odesílatele je znepřístupněna')]
                : ['status' => 200, 'body' => self::sentListResponse([])],
        );

        try {
            $transport->createMessage($this->mobileKeyContext(), 'abcdefg', 'Věc', 'MU-1', self::attachment());
            self::fail('Odmítnutí musí být hlášeno.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_send_rejected', $e->errorCode);
            self::assertStringContainsString('znepřístupněna', $e->getMessage());
        }
    }

    public function testAcceptedSendWithoutMessageIdIsUncertainty(): void
    {
        $transport = new DirectIsdsInboxTransport(
            static fn (string $url, string $body): array => str_contains($body, 'CreateMessage')
                ? ['status' => 200, 'body' => self::sendResponse(null)]
                : ['status' => 200, 'body' => self::sentListResponse([])],
        );

        $this->expectException(IsdsTransportTimeout::class);
        $transport->createMessage($this->mobileKeyContext(), 'abcdefg', 'Věc', 'MU-1', self::attachment());
    }

    public function testInterruptedSendIsUncertaintyNotFailure(): void
    {
        $transport = new DirectIsdsInboxTransport(
            static function (string $url, string $body): array {
                if (str_contains($body, 'CreateMessage')) {
                    throw new SubmissionChannelException('isds_connection_failed', 'Spojení se přerušilo.', 502);
                }
                return ['status' => 200, 'body' => self::sentListResponse([])];
            },
        );

        try {
            $transport->createMessage($this->mobileKeyContext(), 'abcdefg', 'Věc', 'MU-1', self::attachment());
            self::fail('Přerušené odeslání nesmí být hlášeno jako selhání.');
        } catch (IsdsTransportTimeout $e) {
            self::assertSame('isds_send_interrupted', $e->errorCode);
        }
    }

    public function testMissingSenderIdentIsNeverReportedAsFound(): void
    {
        $transport = new DirectIsdsInboxTransport(
            static fn (): array => ['status' => 200, 'body' => self::sentListResponse([['555', 'MU-JINE']])],
        );

        self::assertNull($transport->findSentBySenderIdent($this->mobileKeyContext(), 'MU-DPH-2026-07'));
        self::assertSame('555', $transport->findSentBySenderIdent($this->mobileKeyContext(), 'MU-JINE'));
    }

    public function testMessageStateTranslatesNumericIsdsStatus(): void
    {
        $transport = new DirectIsdsInboxTransport(
            static fn (): array => ['status' => 200, 'body' => self::deliveryInfoResponse('6')],
        );

        $state = $transport->messageState($this->mobileKeyContext(), '987654321');

        self::assertSame('RECEIVED', $state['state']);
        self::assertSame('2026-08-25T12:00:00+02:00', $state['delivered_at']);
    }

    public function testRecipientBoxIsUsableOnlyInStateOne(): void
    {
        $usable = new DirectIsdsInboxTransport(static fn (): array => ['status' => 200, 'body' => self::checkBoxResponse('1')]);
        $revoked = new DirectIsdsInboxTransport(static fn (): array => ['status' => 200, 'body' => self::checkBoxResponse('3')]);

        self::assertTrue($usable->checkRecipientBox($this->mobileKeyContext(), 'abcdefg')->usable);
        self::assertFalse($revoked->checkRecipientBox($this->mobileKeyContext(), 'abcdefg')->usable);
    }

    private function mobileKeyContext(?string $cookie = 'synthetic-session-cookie'): ChannelContext
    {
        return new ChannelContext(
            7,
            'test',
            new ChannelCredentials(
                boxId: '',
                authMode: 'mobile_key',
                sessionCookie: $cookie === null
                    ? null
                    : SensitiveValue::fromProducer(static fn (): string => $cookie),
            ),
        );
    }

    /** @return list<array{filename:string,mime:string,bytes:string}> */
    private static function attachment(): array
    {
        return [['filename' => 'podani.xml', 'mime' => 'application/xml', 'bytes' => '<xml/>']];
    }

    private static function sendResponse(?string $messageId, string $code = '0000', string $message = 'OK'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20"><soap:Body>'
            . '<isds:CreateMessageResponse>'
            . ($messageId !== null ? '<isds:dmID>' . $messageId . '</isds:dmID>' : '')
            . '<isds:dmStatus><isds:dmStatusCode>' . $code . '</isds:dmStatusCode>'
            . '<isds:dmStatusMessage>' . $message . '</isds:dmStatusMessage></isds:dmStatus>'
            . '</isds:CreateMessageResponse></soap:Body></soap:Envelope>';
    }

    /** @param list<array{0:string,1:string}> $messages dvojice dmID + dmSenderIdent */
    private static function sentListResponse(array $messages): string
    {
        $records = '';
        foreach ($messages as [$messageId, $senderIdent]) {
            $records .= '<isds:dmRecord><isds:dmID>' . $messageId . '</isds:dmID>'
                . '<isds:dmSenderIdent>' . $senderIdent . '</isds:dmSenderIdent>'
                . '<isds:dmMessageStatus>4</isds:dmMessageStatus></isds:dmRecord>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20"><soap:Body>'
            . '<isds:GetListOfSentMessagesResponse><isds:dmRecords>' . $records . '</isds:dmRecords>'
            . '<isds:dmStatus><isds:dmStatusCode>0000</isds:dmStatusCode><isds:dmStatusMessage>OK</isds:dmStatusMessage></isds:dmStatus>'
            . '</isds:GetListOfSentMessagesResponse></soap:Body></soap:Envelope>';
    }

    private static function deliveryInfoResponse(string $status): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20"><soap:Body>'
            . '<isds:GetDeliveryInfoResponse><isds:dmDelivery>'
            . '<isds:dmDeliveryTime>2026-08-25T12:00:00+02:00</isds:dmDeliveryTime>'
            . '<isds:dmAcceptanceTime>2026-08-26T09:00:00+02:00</isds:dmAcceptanceTime>'
            . '<isds:dmMessageStatus>' . $status . '</isds:dmMessageStatus>'
            . '</isds:dmDelivery>'
            . '<isds:dmStatus><isds:dmStatusCode>0000</isds:dmStatusCode><isds:dmStatusMessage>OK</isds:dmStatusMessage></isds:dmStatus>'
            . '</isds:GetDeliveryInfoResponse></soap:Body></soap:Envelope>';
    }

    private static function checkBoxResponse(string $state): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20"><soap:Body>'
            . '<isds:CheckDataBoxResponse><isds:dbState>' . $state . '</isds:dbState>'
            . '<isds:dbStatus><isds:dbStatusCode>0000</isds:dbStatusCode><isds:dbStatusMessage>OK</isds:dbStatusMessage></isds:dbStatus>'
            . '</isds:CheckDataBoxResponse></soap:Body></soap:Envelope>';
    }

    private static function downloadResponse(string $bytes): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20"><soap:Body>'
            . '<isds:SignedMessageDownloadResponse><isds:dmSignature>' . base64_encode($bytes) . '</isds:dmSignature>'
            . '<isds:dmStatus><isds:dmStatusCode>0000</isds:dmStatusCode><isds:dmStatusMessage>OK</isds:dmStatusMessage></isds:dmStatus>'
            . '</isds:SignedMessageDownloadResponse></soap:Body></soap:Envelope>';
    }
}
