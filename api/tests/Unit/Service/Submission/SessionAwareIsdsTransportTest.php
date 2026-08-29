<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport;
use MyInvoice\Service\Submission\Channel\Isds\SessionAwareIsdsTransport;
use MyInvoice\Service\Submission\Channel\Isds\UnavailableIsdsTransport;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use PHPUnit\Framework\TestCase;

final class SessionAwareIsdsTransportTest extends TestCase
{
    public function testConfirmedSessionGoesDirectlyToIsds(): void
    {
        $sent = 0;
        $transport = new SessionAwareIsdsTransport(
            new DirectIsdsInboxTransport(
                static function (string $url, string $body) use (&$sent): array {
                    if (str_contains($body, 'CreateMessage')) {
                        $sent++;
                        return ['status' => 200, 'body' => self::sendResponse()];
                    }
                    return ['status' => 200, 'body' => self::emptySentList()];
                },
            ),
            new UnavailableIsdsTransport(),
        );

        $receipt = $transport->createMessage(
            $this->context('mobile_key', 'synthetic-session-cookie'),
            'abcdefg',
            'Věc',
            'MU-1',
            [['filename' => 'podani.xml', 'mime' => 'application/xml', 'bytes' => '<xml/>']],
        );

        self::assertSame('123456789', $receipt->messageId);
        self::assertSame(1, $sent);
    }

    public function testWithoutConfirmedSessionTheFallbackKeepsItsOwnObstacle(): void
    {
        $transport = new SessionAwareIsdsTransport(
            new DirectIsdsInboxTransport(static fn (): array => self::fail('Přímý transport se tu nesmí použít.')),
            new UnavailableIsdsTransport(),
        );

        try {
            $transport->createMessage(
                $this->context('certificate', null),
                'abcdefg',
                'Věc',
                'MU-1',
                [['filename' => 'podani.xml', 'mime' => 'application/xml', 'bytes' => '<xml/>']],
            );
            self::fail('Bez potvrzené relace musí rozhodnout náhradní cesta.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_transport_unavailable', $e->errorCode);
        }
    }

    public function testStartedButUnconfirmedSessionIsNotTreatedAsLive(): void
    {
        $transport = new SessionAwareIsdsTransport(
            new DirectIsdsInboxTransport(static fn (): array => self::fail('Přímý transport se tu nesmí použít.')),
            new UnavailableIsdsTransport(),
        );

        $this->expectException(SubmissionChannelException::class);
        $transport->listReceived($this->context('mobile_key', null));
    }

    private function context(string $authMode, ?string $cookie): ChannelContext
    {
        return new ChannelContext(
            7,
            'test',
            new ChannelCredentials(
                boxId: '',
                authMode: $authMode,
                sessionCookie: $cookie === null
                    ? null
                    : SensitiveValue::fromProducer(static fn (): string => $cookie),
            ),
        );
    }

    private static function sendResponse(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20"><soap:Body>'
            . '<isds:CreateMessageResponse><isds:dmID>123456789</isds:dmID>'
            . '<isds:dmStatus><isds:dmStatusCode>0000</isds:dmStatusCode><isds:dmStatusMessage>OK</isds:dmStatusMessage></isds:dmStatus>'
            . '</isds:CreateMessageResponse></soap:Body></soap:Envelope>';
    }

    private static function emptySentList(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20"><soap:Body>'
            . '<isds:GetListOfSentMessagesResponse><isds:dmRecords/>'
            . '<isds:dmStatus><isds:dmStatusCode>0000</isds:dmStatusCode><isds:dmStatusMessage>OK</isds:dmStatusMessage></isds:dmStatus>'
            . '</isds:GetListOfSentMessagesResponse></soap:Body></soap:Envelope>';
    }
}
