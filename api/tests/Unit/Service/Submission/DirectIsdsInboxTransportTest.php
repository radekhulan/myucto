<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
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

    private static function downloadResponse(string $bytes): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:isds="http://isds.czechpoint.cz/v20"><soap:Body>'
            . '<isds:SignedMessageDownloadResponse><isds:dmSignature>' . base64_encode($bytes) . '</isds:dmSignature>'
            . '<isds:dmStatus><isds:dmStatusCode>0000</isds:dmStatusCode><isds:dmStatusMessage>OK</isds:dmStatusMessage></isds:dmStatus>'
            . '</isds:SignedMessageDownloadResponse></soap:Body></soap:Envelope>';
    }
}
