<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Přímý přístup k ISDS pro úkony, které spouští člověk.
 *
 * Čtení podporuje systémový certifikát firmy, jednorázové jméno a heslo
 * i krátkou relaci Mobilního klíče nebo SMS.
 *
 * ── Proč tahle třída nově SMÍ i odesílat ───────────────────────────────────
 * Původně odchozí operace odmítala s odkazem na odesílací bránu, protože
 * u strojového odeslání chyběl souhlas člověka. U Mobilního klíče (a SMS)
 * je ale ten souhlas **už součástí přihlášení**: účetní relaci schválí ve své
 * mobilní aplikaci a ISDS ji vydá jen na pár minut. Odeslání v takové relaci
 * tedy není „bez vědomí člověka" — je to jeho vlastní úkon, jen provedený
 * odsud místo z webu datové schránky. Odesílat proto smí VÝHRADNĚ kontext
 * s takovou živou relací; viz {@see hasConfirmedSession()} a
 * {@see assertConfirmedSession()}. Systémový certifikát ani uložené heslo
 * odeslání neotevírají — ty by znamenaly odeslání bez člověka u toho.
 *
 * Čtení schránky zůstává beze změny právně významným úkonem (§ 17 odst. 3
 * zák. 300/2008 Sb.) a bránu na výslovný souhlas drží dál
 * {@see \MyInvoice\Service\Submission\SubmissionInboxService}.
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
    private const MAX_SEND_RESPONSE_BYTES = 1024 * 1024;
    private const LIST_PAGE_SIZE = 50;
    private const MAX_LIST_PAGES = 10;
    private const USER_AGENT = 'MyUcto-ISDS-Inbox/1.0';

    /** Způsoby přihlášení, u kterých relaci právě potvrdil člověk. */
    private const CONFIRMED_SESSION_MODES = ['mobile_key', 'sms'];

    /** Rozsah dohledávání v odeslaných: rekonciliace vs. ochrana před dvojím kliknutím. */
    public const PROBE_LOOKBACK = '-90 days';
    public const SEND_DEDUPE_LOOKBACK = '-2 days';

    /**
     * Chyby, po kterých je JISTÉ, že zpráva neodešla — ISDS je hlásí ještě
     * v přihlášení, tedy dřív, než se požadavek dostane ke zpracování. Cokoliv
     * jiného je při odesílání nevědomost, ne selhání.
     */
    private const PROVEN_NOT_SENT = [
        'isds_session_expired',
        'isds_login_rejected',
        'isds_cookie_invalid',
        'isds_auth_mode_invalid',
        'isds_curl_required',
        'isds_mobile_cookie_missing',
    ];

    /** Limity `dmBaseTypes.xsd`; shodné s {@see Gateway\IsdsConceptMessage}. */
    private const MAX_ATTACHMENTS = 50;
    private const MAX_TOTAL_ATTACHMENT_BYTES = 20 * 1024 * 1024;
    private const MAX_ANNOTATION_CHARS = 255;
    private const MAX_SENDER_IDENT_CHARS = 50;

    /** @param null|callable(string,string,ChannelContext):array{status:int,body:string} $httpDouble */
    public function __construct(private $httpDouble = null) {}

    /**
     * Nese kontext živou relaci, kterou právě potvrdil člověk?
     *
     * Jediné pravidlo, podle kterého se rozhoduje, jestli se smí odeslat —
     * používá ho i {@see SessionAwareIsdsTransport} při volbě transportu, aby
     * odpověď byla na obou místech stejná.
     */
    public static function hasConfirmedSession(ChannelContext $context): bool
    {
        return in_array($context->credentials->authMode, self::CONFIRMED_SESSION_MODES, true)
            && $context->credentials->sessionCookie !== null;
    }

    public function checkRecipientBox(ChannelContext $context, string $boxId): IsdsBoxCheck
    {
        $boxId = strtolower(trim($boxId));
        if (preg_match('/^[a-z0-9]{7}$/', $boxId) !== 1) {
            throw new SubmissionChannelException(
                'isds_recipient_box_invalid',
                'ID datové schránky příjemce nemá platný tvar (7 znaků, písmena a číslice).',
                422,
            );
        }
        $body = $this->envelope(static function (\XMLWriter $writer) use ($boxId): void {
            $writer->startElementNS('isds', 'CheckDataBox', self::NS_ISDS);
            $writer->writeElementNS('isds', 'dbID', null, $boxId);
            $writer->endElement();
        });

        // Vyhledávání schránek je vlastní služba `df` a odpovídá `dbStatus`,
        // ne `dmStatus` — jiné jméno prvku, jinak stejná logika.
        $xpath = $this->request($context, 'df', $body, self::MAX_SEND_RESPONSE_BYTES);
        $this->assertStatus($xpath, 'isds_recipient_check', 'dbStatusCode', 'dbStatusMessage');
        $state = $this->firstValue($xpath, 'dbState');
        if ($state === null) {
            // Nevědomost se nesmí vydávat ani za „schránka je v pořádku",
            // ani za opak — proto výjimka, ne IsdsBoxCheck::unusable().
            throw new SubmissionChannelException(
                'isds_recipient_check_malformed',
                'Datová schránka neodpověděla na dotaz, jestli je schránka příjemce použitelná.',
                502,
            );
        }
        // dbState 1 = přístupná. Cokoliv jiného (2 dočasně znepřístupněná,
        // 3 znepřístupněná, 5 zrušená, 6 vyřazená) znamená, že by podání
        // spadlo do prázdna.
        if ($state !== '1') {
            return IsdsBoxCheck::unusable($boxId, 'stav schránky v ISDS je ' . $state);
        }

        return IsdsBoxCheck::usable($boxId);
    }

    /**
     * Skutečné odeslání zprávy (`CreateMessage`).
     *
     * Smí se jen v relaci potvrzené člověkem — viz docblock třídy. Relace je
     * krátká a tahle metoda ji NIKDY neobnovuje: vypršelou relaci hlásí
     * `isds_session_expired`, aby o novém odeslání znovu rozhodl člověk.
     *
     * Idempotenci nese `dmSenderIdent` (spisová značka podání), protože ISDS
     * žádný idempotency token nemá. Proto se před odesláním ověří, že zpráva
     * s toutéž značkou v odeslaných ještě není — druhé kliknutí tak nevyrobí
     * druhou zprávu, ale vrátí `dmID` té první.
     */
    public function createMessage(
        ChannelContext $context,
        string $recipientBoxId,
        string $subject,
        string $senderIdent,
        array $files,
    ): IsdsSendReceipt {
        $this->assertConfirmedSession($context);
        $recipientBoxId = strtolower(trim($recipientBoxId));
        $senderIdent = trim($senderIdent);
        $this->assertSendable($recipientBoxId, $subject, $senderIdent, $files);

        // Krátké okno schválně: dvojí kliknutí i opakovaný pokus po přerušení
        // se dějí v řádu minut, kdežto plných 90 dnů by u vytížené schránky
        // naráželo na stránkovací limit a odesílání by se zaseklo úplně.
        $alreadySent = $this->findSentBySenderIdent($context, $senderIdent, self::SEND_DEDUPE_LOOKBACK);
        if ($alreadySent !== null) {
            // Zpráva s touhle značkou už v odeslaných je. Poslat ji podruhé by
            // znamenalo druhé podání u úřadu — vracíme tu první.
            return IsdsSendReceipt::accepted($alreadySent, IsdsSendReceipt::STATUS_ACCEPTED);
        }

        $body = $this->envelope(static function (\XMLWriter $writer) use ($recipientBoxId, $subject, $senderIdent, $files): void {
            $writer->startElementNS('isds', 'CreateMessage', self::NS_ISDS);
            $writer->writeAttributeNS('xmlns', 'xsi', null, self::NS_XSI);

            // Pořadí je dané xs:sequence v dmBaseTypes.xsd a nesmí se měnit;
            // prázdné prvky se zapisují jako xsi:nil, ne vynecháním — stejná
            // past, jakou popisuje SetConceptRequestWriter.
            $writer->startElementNS('isds', 'dmEnvelope', null);
            self::nilElement($writer, 'dmSenderOrgUnit');
            self::nilElement($writer, 'dmSenderOrgUnitNum');
            $writer->writeElementNS('isds', 'dbIDRecipient', null, $recipientBoxId);
            self::nilElement($writer, 'dmRecipientOrgUnit');
            self::nilElement($writer, 'dmRecipientOrgUnitNum');
            self::nilElement($writer, 'dmToHands');
            $writer->writeElementNS('isds', 'dmAnnotation', null, $subject);
            self::nilElement($writer, 'dmRecipientRefNumber');
            self::nilElement($writer, 'dmSenderRefNumber');
            self::nilElement($writer, 'dmRecipientIdent');
            $writer->writeElementNS('isds', 'dmSenderIdent', null, $senderIdent);
            self::nilElement($writer, 'dmLegalTitleLaw');
            self::nilElement($writer, 'dmLegalTitleYear');
            self::nilElement($writer, 'dmLegalTitleSect');
            self::nilElement($writer, 'dmLegalTitlePar');
            self::nilElement($writer, 'dmLegalTitlePoint');
            self::nilElement($writer, 'dmPersonalDelivery');
            self::nilElement($writer, 'dmAllowSubstDelivery');
            $writer->endElement();

            $writer->startElementNS('isds', 'dmFiles', null);
            foreach (array_values($files) as $index => $file) {
                $writer->startElementNS('isds', 'dmFile', null);
                $writer->writeAttribute('dmMimeType', $file['mime']);
                $writer->writeAttribute('dmFileMetaType', $index === 0 ? 'main' : 'enclosure');
                $writer->writeAttribute('dmFileDescr', $file['filename']);
                $writer->startElementNS('isds', 'dmEncodedContent', null);
                $writer->text(base64_encode($file['bytes']));
                $writer->endElement();
                $writer->endElement();
            }
            $writer->endElement();

            $writer->endElement();
        });

        try {
            // Pozor na past: `CreateMessage` NENÍ na `/DS/dm` (ta služba
            // neexistuje), ale na `/DS/dz` spolu se stahováním zpráv. ISDS
            // dělí služby podle toho, jestli nesou přílohy, ne podle směru.
            $xpath = $this->request($context, 'dz', $body, self::MAX_SEND_RESPONSE_BYTES);
        } catch (SubmissionChannelException $e) {
            if (in_array($e->errorCode, self::PROVEN_NOT_SENT, true)) {
                // ISDS odmítlo už v přihlášení, takže se zpráva ke zpracování
                // vůbec nedostala. Opakovat je bezpečné.
                throw $e;
            }
            throw new IsdsTransportTimeout(
                'isds_send_interrupted',
                'Spojení s datovou schránkou se přerušilo a není jisté, jestli zpráva odešla.',
                $e,
            );
        } catch (\Throwable $e) {
            throw new IsdsTransportTimeout(
                'isds_send_unexpected',
                'Datová schránka odpověděla nečekaně a není jisté, jestli zpráva odešla.',
                $e,
            );
        }

        $code = $this->firstValue($xpath, 'dmStatusCode');
        if ($code === null) {
            // Odpověď bez stavu není odmítnutí — je to nevědomost, a přijde až
            // POTÉ, co zpráva mohla odejít.
            throw new IsdsTransportTimeout(
                'isds_send_status_missing',
                'Datová schránka odpověděla bez stavu, takže není jisté, jestli zpráva odešla.',
            );
        }
        if ($code !== IsdsSendReceipt::STATUS_ACCEPTED) {
            $message = $this->firstValue($xpath, 'dmStatusMessage') ?? 'bez uvedení důvodu';
            throw new SubmissionChannelException(
                'isds_send_rejected',
                'Datová schránka zprávu nepřijala (' . $message . ').',
                409,
            );
        }
        $messageId = $this->firstValue($xpath, 'dmID') ?? '';
        if (preg_match('/^[0-9]{1,30}$/', $messageId) !== 1) {
            throw new IsdsTransportTimeout(
                'isds_send_id_missing',
                'Datová schránka potvrdila přijetí, ale nevrátila ID zprávy. '
                . 'Dohledejte ji v odeslaných podle spisové značky.',
            );
        }

        return IsdsSendReceipt::accepted($messageId, $code);
    }

    public function findSentBySenderIdent(ChannelContext $context, string $senderIdent, string $lookback = self::PROBE_LOOKBACK): ?string
    {
        $senderIdent = trim($senderIdent);
        if ($senderIdent === '' || mb_strlen($senderIdent) > self::MAX_SENDER_IDENT_CHARS) {
            throw new SubmissionChannelException(
                'isds_sender_ident_invalid',
                'Spisová značka podání musí být vyplněná a nejvýš 50 znaků dlouhá.',
                400,
            );
        }
        $from = new \DateTimeImmutable($lookback);
        $to = new \DateTimeImmutable('+1 day');

        for ($page = 0; $page < self::MAX_LIST_PAGES; $page++) {
            $records = $this->listPage(
                $context,
                'GetListOfSentMessages',
                'dmSenderOrgUnitNum',
                'isds_sent_list',
                $from,
                $to,
                ($page * self::LIST_PAGE_SIZE) + 1,
            );
            foreach ($records as $record) {
                if ($record['sender_ident'] === $senderIdent) {
                    return $record['message_id'];
                }
            }
            if (count($records) < self::LIST_PAGE_SIZE) {
                // Prošli jsme celý rozsah a značka tam není. Až tohle smí
                // vrátit null — „nevím" se sem nesmí propašovat.
                return null;
            }
        }

        throw new SubmissionChannelException(
            'isds_sent_list_limit_reached',
            'V odeslaných zprávách je ve sledovaném období více než 500 položek a spisová značka '
            . 'mezi nimi zatím nebyla. Dohledání bylo bezpečně zastaveno, aby netvrdilo, že zpráva neodešla.',
            409,
        );
    }

    /**
     * Stav naší odeslané zprávy z doručenky (`GetDeliveryInfo`).
     *
     * Dotaz na doručenku k VLASTNÍ odeslané zprávě není přihlášením do schránky
     * ve smyslu § 17 odst. 3 — nespouští doručení ničeho a je bezpečné ho volat
     * opakovaně.
     */
    public function messageState(ChannelContext $context, string $messageId): array
    {
        $messageId = trim($messageId);
        if (preg_match('/^[0-9]{1,30}$/', $messageId) !== 1) {
            throw new SubmissionChannelException('isds_message_id_invalid', 'ID datové zprávy není platné.', 400);
        }
        $body = $this->envelope(static function (\XMLWriter $writer) use ($messageId): void {
            $writer->startElementNS('isds', 'GetDeliveryInfo', self::NS_ISDS);
            $writer->writeElementNS('isds', 'dmID', null, $messageId);
            $writer->endElement();
        });

        $xpath = $this->request($context, 'dx', $body, self::MAX_MESSAGE_RESPONSE_BYTES);
        $this->assertStatus($xpath, 'isds_message_state');

        return [
            'state' => self::stateName($this->firstValue($xpath, 'dmMessageStatus')),
            'delivered_at' => $this->firstValue($xpath, 'dmDeliveryTime'),
            'accepted_at' => $this->firstValue($xpath, 'dmAcceptanceTime'),
        ];
    }

    public function listReceived(ChannelContext $context): array
    {
        $from = new \DateTimeImmutable('-90 days');
        $to = new \DateTimeImmutable('+1 day');
        $result = [];
        $seen = [];

        for ($page = 0; $page < self::MAX_LIST_PAGES; $page++) {
            $offset = ($page * self::LIST_PAGE_SIZE) + 1;
            $records = $this->listPage(
                $context,
                'GetListOfReceivedMessages',
                'dmRecipientOrgUnitNum',
                'isds_inbox_list',
                $from,
                $to,
                $offset,
            );
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

    /**
     * Jedna stránka seznamu zpráv.
     *
     * Přijatá i odeslaná strana mají v ISDS shodný tvar požadavku i odpovědi;
     * liší se jen jménem operace a tím, která organizační jednotka se filtruje.
     * Sdílená metoda je tu proto, aby se paging a fail-closed kontroly nemusely
     * udržovat dvakrát.
     *
     * @return list<array<string,?string>>
     */
    private function listPage(
        ChannelContext $context,
        string $operation,
        string $orgUnitElement,
        string $errorPrefix,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $offset,
    ): array {
        $body = $this->envelope(static function (\XMLWriter $writer) use ($operation, $orgUnitElement, $from, $to, $offset): void {
            $writer->startElementNS('isds', $operation, self::NS_ISDS);
            $writer->writeElementNS('isds', 'dmFromTime', null, $from->format(DATE_ATOM));
            $writer->writeElementNS('isds', 'dmToTime', null, $to->format(DATE_ATOM));
            $writer->startElementNS('isds', $orgUnitElement, null);
            $writer->writeAttributeNS('xsi', 'nil', self::NS_XSI, 'true');
            $writer->endElement();
            $writer->writeElementNS('isds', 'dmStatusFilter', null, '-1');
            $writer->writeElementNS('isds', 'dmOffset', null, (string) $offset);
            $writer->writeElementNS('isds', 'dmLimit', null, (string) self::LIST_PAGE_SIZE);
            $writer->endElement();
        });

        $xpath = $this->request($context, 'dx', $body, self::MAX_LIST_RESPONSE_BYTES);
        $this->assertStatus($xpath, $errorPrefix);
        $records = $xpath->query('//*[local-name()="dmRecord"]');
        if ($records === false) {
            throw new SubmissionChannelException($errorPrefix . '_malformed', 'Seznam zpráv z datové schránky se nepodařilo přečíst.', 502);
        }

        $result = [];
        foreach ($records as $record) {
            if (!$record instanceof \DOMElement) {
                continue;
            }
            $messageId = $this->childValue($xpath, $record, 'dmID') ?? '';
            if (preg_match('/^[0-9]{1,30}$/', $messageId) !== 1) {
                throw new SubmissionChannelException($errorPrefix . '_malformed', 'Seznam zpráv obsahuje neplatné ID datové zprávy.', 502);
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
        $messageId = trim($messageId);
        if (preg_match('/^[0-9]{1,30}$/', $messageId) !== 1) {
            throw new SubmissionChannelException('isds_message_id_invalid', 'ID datové zprávy není platné.', 400);
        }
        $body = $this->envelope(static function (\XMLWriter $writer) use ($messageId): void {
            $writer->startElementNS('isds', 'GetSignedDeliveryInfo', self::NS_ISDS);
            $writer->writeElementNS('isds', 'dmID', null, $messageId);
            $writer->endElement();
        });

        $xpath = $this->request($context, 'dx', $body, self::MAX_MESSAGE_RESPONSE_BYTES);
        $this->assertStatus($xpath, 'isds_delivery_receipt');
        $encoded = $this->firstValue($xpath, 'dmSignature');
        if ($encoded === null) {
            // Kontrakt: null = doručenka zatím NEEXISTUJE (zpráva ještě nebyla
            // dodána). ISDS na to odpoví stavem 0000 a prázdným podpisem.
            return null;
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $encoded) ?? '', true);
        if ($bytes === false || $bytes === '') {
            throw new SubmissionChannelException('isds_delivery_receipt_empty', 'Datová schránka vrátila nečitelnou doručenku.', 502);
        }
        // Podpis ani časové razítko se tu NEOVĚŘUJÍ — doručenka se dál vede
        // jako `unverified`, viz povinnost 7 v IsdsTransport.
        return $bytes;
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
            $this->assertHttpStatus($response['status'], $context);
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
            $this->assertHttpStatus($status, $context);
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

    private function assertStatus(
        \DOMXPath $xpath,
        string $prefix,
        string $codeElement = 'dmStatusCode',
        string $messageElement = 'dmStatusMessage',
    ): void {
        $code = $this->firstValue($xpath, $codeElement);
        if ($code !== '0000') {
            $message = $this->firstValue($xpath, $messageElement) ?? 'bez uvedení důvodu';
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

    /**
     * Odpověď HTTP.
     *
     * V relaci potvrzené člověkem má vypršení vlastní kód: ISDS na mrtvou
     * relaci odpovídá přesměrováním na přihlášení nebo 401, a to není „špatné
     * heslo". Relace se ZÁMĚRNĚ neobnovuje — nová by nebyla ta, kterou člověk
     * schválil.
     */
    private function assertHttpStatus(int $status, ChannelContext $context): void
    {
        $inSession = self::hasConfirmedSession($context);
        if ($status === 401 || $status === 403 || ($inSession && $status >= 300 && $status < 400)) {
            throw $inSession
                ? new SubmissionChannelException(
                    'isds_session_expired',
                    'Relace datové schránky mezitím vypršela. Přihlaste se znovu Mobilním klíčem a akci zopakujte.',
                    409,
                )
                : new SubmissionChannelException(
                    'isds_login_rejected',
                    'Datová schránka přihlášení odmítla. Zkontrolujte zvolený účet a způsob přihlášení.',
                    401,
                );
        }
        if ($status < 200 || $status >= 300) {
            throw new SubmissionChannelException('isds_http_error', 'Datová schránka odpověděla chybou HTTP ' . $status . '.', 502);
        }
    }

    private function assertConfirmedSession(ChannelContext $context): void
    {
        if (!in_array($context->credentials->authMode, self::CONFIRMED_SESSION_MODES, true)) {
            throw new SubmissionChannelException(
                'isds_send_requires_confirmed_session',
                'Datovou schránkou lze odeslat jen v relaci, kterou jste právě potvrdil(a) v Mobilním klíči '
                . 'nebo SMS kódem. Systémovým certifikátem se odesílat nesmí — u odeslání musí být člověk.',
                409,
            );
        }
        if ($context->credentials->sessionCookie === null) {
            throw new SubmissionChannelException(
                'isds_session_missing',
                'Přihlášení k datové schránce není dokončené, takže se odesílat nesmí. '
                . 'Potvrďte přihlášení a odeslání spusťte znovu.',
                409,
            );
        }
    }

    /**
     * Kontroly, které jdou udělat bez sítě. Limity jsou z `dmBaseTypes.xsd`
     * a jsou shodné s cestou přes odesílací bránu.
     *
     * @param list<array{filename:string,mime:string,bytes:string}> $files
     */
    private function assertSendable(string $recipientBoxId, string $subject, string $senderIdent, array $files): void
    {
        if (preg_match('/^[a-z0-9]{7}$/', $recipientBoxId) !== 1) {
            throw new SubmissionChannelException(
                'isds_recipient_box_invalid',
                'ID datové schránky příjemce nemá platný tvar (7 znaků, písmena a číslice).',
                422,
            );
        }
        if (trim($subject) === '' || mb_strlen($subject) > self::MAX_ANNOTATION_CHARS) {
            throw new SubmissionChannelException(
                'isds_annotation_invalid',
                'Věc datové zprávy musí být vyplněná a nejvýš 255 znaků dlouhá.',
                422,
            );
        }
        if ($senderIdent === '' || mb_strlen($senderIdent) > self::MAX_SENDER_IDENT_CHARS) {
            throw new SubmissionChannelException(
                'isds_sender_ident_invalid',
                'Spisová značka podání musí být vyplněná a nejvýš 50 znaků dlouhá.',
                422,
            );
        }
        if ($files === []) {
            throw new SubmissionChannelException(
                'isds_attachment_missing',
                'Datová zpráva musí mít alespoň jednu přílohu.',
                422,
            );
        }
        if (count($files) > self::MAX_ATTACHMENTS) {
            throw new SubmissionChannelException(
                'isds_too_many_attachments',
                'Datová schránka připouští nejvýš 50 příloh v jedné zprávě.',
                422,
            );
        }
        $total = 0;
        foreach ($files as $file) {
            if (trim($file['filename']) === '' || trim($file['mime']) === '' || $file['bytes'] === '') {
                throw new SubmissionChannelException(
                    'isds_attachment_invalid',
                    'Příloha datové zprávy nemá název, typ obsahu, nebo je prázdná.',
                    422,
                );
            }
            $total += strlen($file['bytes']);
        }
        if ($total > self::MAX_TOTAL_ATTACHMENT_BYTES) {
            throw new SubmissionChannelException(
                'isds_attachments_too_large',
                'Součet příloh přesahuje 20 MB, které datová schránka připouští pro běžnou zprávu.',
                422,
            );
        }
    }

    /**
     * Povinný, ale prázdný prvek obálky. Vynechání ani prázdný element XSD
     * nepřipouští — musí to být `xsi:nil`.
     */
    private static function nilElement(\XMLWriter $writer, string $name): void
    {
        $writer->startElementNS('isds', $name, null);
        $writer->writeAttributeNS('xsi', 'nil', null, 'true');
        $writer->endElement();
    }

    /**
     * Číselný `dmMessageStatus` na jména, kterým rozumí {@see IsdsChannel}.
     *
     * ⚠️ ISDS vrací ČÍSLO 1–10, ne text. Velká písmena níž jsou jména z
     * knihovny libisds, která se v tomhle modulu vžila jako slovník stavů —
     * na drátě žádné takové nejsou. Význam čísel je z kap. „Koloběh datových
     * zpráv" oficiální specifikace webových služeb ISDS.
     *
     * Neznámý kód se ZÁMĚRNĚ nemapuje na nic doručeného — kanál pak zprávu
     * nechá ve stavu „odesláno, doručenka nedorazila", což je bezpečná strana.
     */
    private static function stateName(?string $status): string
    {
        return match ($status !== null ? trim($status) : null) {
            '1' => 'POSTED',           // podána, vznikla v ISDS
            '2' => 'STAMPED',          // podepsána podacím časovým razítkem
            '3' => 'INFECTED',         // neprošla antivirovou kontrolou
            '4' => 'DELIVERED',        // dodána do schránky adresáta
            '5' => 'SUBSTITUTED',      // doručena fikcí po 10 dnech (§ 17 odst. 4)
            '6' => 'RECEIVED',         // doručena přihlášením (§ 17 odst. 3)
            '7' => 'READ',             // přečtena
            '8' => 'UNDELIVERABLE',    // schránka adresáta byla zpětně znepřístupněna
            '9' => 'IN_ARCHIVE',       // obsah smazán, obálka v archivu
            '10' => 'IN_SAFE',         // přesunuta do datového trezoru
            default => 'UNKNOWN',
        };
    }
}
