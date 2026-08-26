<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Přímé čtení ISDS pro ručně vyžádané načtení inboxu.
 *
 * Podporuje systémový certifikát firmy, jednorázové jméno a heslo a krátkou
 * relaci Mobilního klíče nebo SMS. Odchozí zprávy záměrně neposílá: ty vede SetConcept,
 * kde konečný souhlas proběhne přímo v ISDS.
 */
final class DirectIsdsInboxTransport implements IsdsTransport
{
    private const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const NS_ISDS = 'http://isds.czechpoint.cz/v20';
    private const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 120;
    private const MAX_LIST_RESPONSE_BYTES = 4 * 1024 * 1024;
    private const MAX_MESSAGE_RESPONSE_BYTES = 40 * 1024 * 1024;
    private const LIST_PAGE_SIZE = 50;
    private const MAX_LIST_PAGES = 10;
    private const USER_AGENT = 'MyUcto-ISDS-Inbox/1.0';

    /** @param null|callable(string,string,ChannelContext):array{status:int,body:string} $httpDouble */
    public function __construct(private $httpDouble = null) {}

    public function checkRecipientBox(ChannelContext $context, string $boxId): IsdsBoxCheck
    {
        throw $this->outboundUnsupported();
    }

    public function createMessage(
        ChannelContext $context,
        string $recipientBoxId,
        string $subject,
        string $senderIdent,
        array $files,
    ): IsdsSendReceipt {
        throw $this->outboundUnsupported();
    }

    public function findSentBySenderIdent(ChannelContext $context, string $senderIdent): ?string
    {
        throw $this->outboundUnsupported();
    }

    public function messageState(ChannelContext $context, string $messageId): array
    {
        throw $this->outboundUnsupported();
    }

    public function listReceived(ChannelContext $context): array
    {
        $from = new \DateTimeImmutable('-90 days');
        $to = new \DateTimeImmutable('+1 day');
        $result = [];
        $seen = [];

        for ($page = 0; $page < self::MAX_LIST_PAGES; $page++) {
            $offset = ($page * self::LIST_PAGE_SIZE) + 1;
            $records = $this->listReceivedPage($context, $from, $to, $offset);
            foreach ($records as $record) {
                $messageId = (string) $record['message_id'];
                if (isset($seen[$messageId])) {
                    continue;
                }
                $seen[$messageId] = true;
                $result[] = $record;
            }
            if (count($records) < self::LIST_PAGE_SIZE) {
                return $result;
            }
        }

        throw new SubmissionChannelException(
            'isds_inbox_list_limit_reached',
            'Datová schránka vrátila více než 500 zpráv za posledních 90 dnů. Načtení bylo bezpečně zastaveno, aby se neprezentovalo jako úplné.',
            409,
        );
    }

    /** @return list<array<string,?string>> */
    private function listReceivedPage(
        ChannelContext $context,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $offset,
    ): array {
        $body = $this->envelope(static function (\XMLWriter $writer) use ($from, $to, $offset): void {
            $writer->startElementNS('isds', 'GetListOfReceivedMessages', self::NS_ISDS);
            $writer->writeElementNS('isds', 'dmFromTime', null, $from->format(DATE_ATOM));
            $writer->writeElementNS('isds', 'dmToTime', null, $to->format(DATE_ATOM));
            $writer->startElementNS('isds', 'dmRecipientOrgUnitNum', null);
            $writer->writeAttributeNS('xsi', 'nil', self::NS_XSI, 'true');
            $writer->endElement();
            $writer->writeElementNS('isds', 'dmStatusFilter', null, '-1');
            $writer->writeElementNS('isds', 'dmOffset', null, (string) $offset);
            $writer->writeElementNS('isds', 'dmLimit', null, (string) self::LIST_PAGE_SIZE);
            $writer->endElement();
        });

        $xpath = $this->request($context, 'dx', $body, self::MAX_LIST_RESPONSE_BYTES);
        $this->assertStatus($xpath, 'isds_inbox_list');
        $records = $xpath->query('//*[local-name()="dmRecord"]');
        if ($records === false) {
            throw new SubmissionChannelException('isds_inbox_list_malformed', 'Seznam zpráv z datové schránky se nepodařilo přečíst.', 502);
        }

        $result = [];
        foreach ($records as $record) {
            if (!$record instanceof \DOMElement) {
                continue;
            }
            $messageId = $this->childValue($xpath, $record, 'dmID') ?? '';
            if (preg_match('/^[0-9]{1,30}$/', $messageId) !== 1) {
                throw new SubmissionChannelException('isds_inbox_list_malformed', 'Seznam zpráv obsahuje neplatné ID datové zprávy.', 502);
            }
            $result[] = [
                'message_id' => $messageId,
                'sender_box_id' => $this->childValue($xpath, $record, 'dbIDSender'),
                'sender_name' => $this->childValue($xpath, $record, 'dmSender'),
                'subject' => $this->childValue($xpath, $record, 'dmAnnotation'),
                'sender_ident' => $this->childValue($xpath, $record, 'dmSenderIdent'),
                'delivered_at' => $this->childValue($xpath, $record, 'dmDeliveryTime'),
                'accepted_at' => $this->childValue($xpath, $record, 'dmAcceptanceTime'),
            ];
        }

        return $result;
    }

    public function downloadMessage(ChannelContext $context, string $messageId): string
    {
        $messageId = trim($messageId);
        if (preg_match('/^[0-9]{1,30}$/', $messageId) !== 1) {
            throw new SubmissionChannelException('isds_message_id_invalid', 'ID datové zprávy není platné.', 400);
        }
        $body = $this->envelope(static function (\XMLWriter $writer) use ($messageId): void {
            $writer->startElementNS('isds', 'SignedMessageDownload', self::NS_ISDS);
            $writer->writeElementNS('isds', 'dmID', null, $messageId);
            $writer->endElement();
        });

        $xpath = $this->request($context, 'dz', $body, self::MAX_MESSAGE_RESPONSE_BYTES);
        $this->assertStatus($xpath, 'isds_message_download');
        $encoded = $this->firstValue($xpath, 'dmSignature');
        $bytes = $encoded !== null ? base64_decode(preg_replace('/\s+/', '', $encoded) ?? '', true) : false;
        if ($bytes === false || $bytes === '') {
            throw new SubmissionChannelException('isds_message_empty', 'Datová schránka nevrátila obsah zprávy.', 502);
        }

        return $bytes;
    }

    public function downloadDeliveryReceipt(ChannelContext $context, string $messageId): ?string
    {
        throw $this->outboundUnsupported();
    }

    /** @param callable(\XMLWriter):void $body */
    private function envelope(callable $body): string
    {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElementNS('SOAP-ENV', 'Envelope', self::NS_SOAP);
        $writer->startElementNS('SOAP-ENV', 'Body', null);
        $body($writer);
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
        return $writer->outputMemory();
    }

    private function request(ChannelContext $context, string $endpoint, string $body, int $limit): \DOMXPath
    {
        $url = $this->baseUrl($context) . '/DS/' . $endpoint;
        if ($this->httpDouble !== null) {
            $response = ($this->httpDouble)($url, $body, $context);
            return $this->parse($response, $limit);
        }
        if (!function_exists('curl_init')) {
            throw new SubmissionChannelException('isds_curl_required', 'Pro připojení k datové schránce chybí rozšíření PHP cURL.', 503);
        }

        $clientCertificate = null;
        $handle = null;
        try {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new SubmissionChannelException('isds_connection_failed', 'Spojení s datovou schránkou se nepodařilo otevřít.', 502);
            }
            $responseBody = '';
            $tooLarge = false;
            $headers = [
                'Content-Type: text/xml; charset=utf-8',
                'Accept: text/xml, application/xml',
                'SOAPAction: ""',
                'User-Agent: ' . self::USER_AGENT,
                'Expect:',
            ];
            $options = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$tooLarge, $limit): int {
                    if (strlen($responseBody) + strlen($chunk) > $limit) {
                        $tooLarge = true;
                        return 0;
                    }
                    $responseBody .= $chunk;
                    return strlen($chunk);
                },
            ];

            $credentials = $context->credentials;
            if ($credentials->authMode === 'certificate') {
                try {
                    $clientCertificate = IsdsClientCertificate::fromBase64(
                        $credentials->certificate?->reveal() ?? '',
                        $credentials->certificatePassphrase?->reveal() ?? '',
                    );
                } catch (\UnexpectedValueException) {
                    throw new SubmissionChannelException('isds_certificate_unreadable', 'Systémový certifikát firmy se nepodařilo otevřít.', 500);
                }
            } elseif ($credentials->authMode === 'password') {
                if ($credentials->username === null || $credentials->password === null) {
                    throw new SubmissionChannelException('isds_credentials_missing', 'Chybí jednorázové přihlášení k datové schránce.', 400);
                }
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                $options[CURLOPT_USERPWD] = $credentials->username->reveal() . ':' . $credentials->password->reveal();
            } elseif (in_array($credentials->authMode, ['mobile_key', 'sms'], true)) {
                if ($credentials->sessionCookie === null) {
                    throw new SubmissionChannelException('isds_mobile_cookie_missing', 'Přihlášení Mobilním klíčem není dokončené.', 409);
                }
                $cookie = $this->safeCookie($credentials->sessionCookie->reveal());
                $options[CURLOPT_COOKIE] = 'IPCZ-X-COOKIE=' . $cookie;
            } else {
                throw new SubmissionChannelException('isds_auth_mode_invalid', 'Nepodporovaný způsob přihlášení k datové schránce.', 400);
            }

            curl_setopt_array($handle, $options);
            $clientCertificate?->applyTo($handle);
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            if ($tooLarge) {
                throw new SubmissionChannelException('isds_response_too_large', 'Datová schránka vrátila příliš velkou odpověď.', 502);
            }
            if ($ok === false || $responseBody === '') {
                throw new SubmissionChannelException('isds_connection_failed', 'Spojení s datovou schránkou se přerušilo' . ($error !== '' ? ' (' . $error . ')' : '') . '.', 502);
            }
            if ($status === 401 || $status === 403) {
                throw new SubmissionChannelException('isds_login_rejected', 'Datová schránka přihlášení odmítla. Zkontrolujte zvolený účet a způsob přihlášení.', 401);
            }
            if ($status < 200 || $status >= 300) {
                throw new SubmissionChannelException('isds_http_error', 'Datová schránka odpověděla chybou HTTP ' . $status . '.', 502);
            }
            return $this->parse(['status' => $status, 'body' => $responseBody], $limit);
        } finally {
            if ($handle instanceof \CurlHandle) {
                unset($handle);
            }
            $clientCertificate?->clear();
        }
    }

    private function baseUrl(ChannelContext $context): string
    {
        $test = $context->environment === 'test';
        return match ($context->credentials->authMode) {
            'certificate' => 'https://ws1c.' . ($test ? 'datovka-test.gov.cz' : 'datovka.gov.cz') . '/cert',
            'password' => 'https://ws1.' . ($test ? 'datovka-test.gov.cz' : 'datovka.gov.cz'),
            'mobile_key', 'sms' => 'https://www.' . ($test ? 'datovka-test.gov.cz' : 'datovka.gov.cz') . '/apps',
            default => throw new SubmissionChannelException('isds_auth_mode_invalid', 'Nepodporovaný způsob přihlášení k datové schránce.', 400),
        };
    }

    /** @param array{status:int,body:string} $response */
    private function parse(array $response, int $limit): \DOMXPath
    {
        if (strlen($response['body']) > $limit || stripos($response['body'], '<!DOCTYPE') !== false) {
            throw new SubmissionChannelException('isds_response_invalid', 'Odpověď datové schránky není bezpečný XML dokument.', 502);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $loaded = $document->loadXML($response['body'], LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($loaded === false) {
            throw new SubmissionChannelException('isds_response_malformed', 'Odpověď datové schránky se nepodařilo přečíst jako XML.', 502);
        }
        return new \DOMXPath($document);
    }

    private function assertStatus(\DOMXPath $xpath, string $prefix): void
    {
        $code = $this->firstValue($xpath, 'dmStatusCode');
        if ($code !== '0000') {
            $message = $this->firstValue($xpath, 'dmStatusMessage') ?? 'bez uvedení důvodu';
            throw new SubmissionChannelException($prefix . '_rejected', 'Datová schránka požadavek odmítla (' . $message . ').', 409);
        }
    }

    private function firstValue(\DOMXPath $xpath, string $name): ?string
    {
        $nodes = $xpath->query('//*[local-name()="' . $name . '"]');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $value = trim((string) $nodes->item(0)?->textContent);
        return $value !== '' ? $value : null;
    }

    private function childValue(\DOMXPath $xpath, \DOMElement $parent, string $name): ?string
    {
        $nodes = $xpath->query('.//*[local-name()="' . $name . '"]', $parent);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $value = trim((string) $nodes->item(0)?->textContent);
        return $value !== '' ? $value : null;
    }

    private function safeCookie(string $cookie): string
    {
        if (strlen($cookie) < 8 || strlen($cookie) > 4096 || preg_match('/[\x00-\x20;,\x7f]/', $cookie) === 1) {
            throw new SubmissionChannelException('isds_cookie_invalid', 'Relace ISDS není platná.', 409);
        }
        return $cookie;
    }

    private function outboundUnsupported(): SubmissionChannelException
    {
        return new SubmissionChannelException('isds_direct_outbound_unsupported', 'Přímý přístup se používá jen pro ručně vyžádané čtení. Podání odešlete přes odesílací bránu ISDS.', 409);
    }
}
