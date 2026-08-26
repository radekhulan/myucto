<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

use MyInvoice\Service\Submission\Channel\Isds\IsdsClientCertificate;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportTimeout;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Skutečné volání odesílací brány přes cURL + SOAP 1.1.
 *
 * ── Proč cURL a ne PHP `SoapClient` ─────────────────────────────────────────
 * `SoapClient` s klientským certifikátem se na Windows chová podle SSL backendu
 * buildu (OpenSSL vs. Schannel) a `local_cert` vyžaduje jeden PEM se soukromým
 * klíčem na disku. Nechceme ani jedno. cURL umí certifikát i klíč předat
 * odděleně a chová se stejně na všech platformách.
 *
 * ── Proč se odpověď parsuje ručně přes DOMXPath ─────────────────────────────
 * Striktní deserializace do typovaných objektů je u cizí služby křehká: chybějící
 * prvek shodí přístup k neinicializované property, což je `\Error` MIMO hierarchii
 * výjimek — a stane se to až POTÉ, co koncept mohl vzniknout. Čtení do pole přes
 * `local-name()` XPath tenhle problém nemá a zároveň je odolné vůči tomu, že
 * dokumentace uvádí jmenný prostor `…/ats-ws/v1`, kdežto WSDL přibalené v příloze
 * je ve verzi `…/ats-ws/v1_1`.
 *
 * ── Certifikát jen v paměti ──────────────────────────────────────────────────
 * PKCS#12 se předává libcurl přes `CURLOPT_SSLCERT_BLOB`. Soukromý klíč proto
 * nevzniká ani krátce v systémovém TEMP; po ukončení volání se pracovní kopie
 * přepíše pomocí `sodium_memzero()`.
 */
final class SoapIsdsGatewayClient implements IsdsGatewayClient
{
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 120;
    private const MAX_RESPONSE_BYTES = 4 * 1024 * 1024;
    private const USER_AGENT = 'MyUcto-ISDS-OB/1.0';

    private const NS_ATS_V1 = 'http://agw-as.cz/ats-ws/v1';
    private const NS_EXTWS_V1 = 'http://agw-as.cz/ats-ws/extWs/v1';

    public function __construct(
        private readonly SetConceptRequestWriter $writer = new SetConceptRequestWriter(),
        private readonly LoggerInterface $logger = new NullLogger(),
        /**
         * Testovací šev. Produkce si staví volání sama — dosazená uzávěra by
         * obešla timeouty i TLS nastavení.
         *
         * @var null|callable(string,string,string,IsdsGatewayRegistration,?IsdsGatewayCredential):array{status:int,body:string}
         */
        private $httpDouble = null,
    ) {}

    public function exchangeSession(IsdsGatewayRegistration $registration, string $sessionId): IsdsGatewayCredential
    {
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $sessionId) !== 1) {
            // Hodnota přišla z přesměrování v prohlížeči. Tvar se kontroluje
            // dřív, než se dostane do XML — ne proto, že by escapování
            // nefungovalo, ale proto, že nesmyslný vstup nemá být důvod
            // k síťovému volání.
            throw new SubmissionChannelException(
                'isds_gateway_session_invalid',
                'Návrat z datové schránky nepřinesl platný identifikátor relace.',
                400,
            );
        }

        $body = $this->soapEnvelope(
            'm',
            self::NS_ATS_V1,
            static function (\XMLWriter $w) use ($sessionId): void {
                $w->startElementNS('m', 'authConfirmationRequest', self::NS_ATS_V1);
                $w->startElementNS('m', 'sessionId', null);
                $w->text($sessionId);
                $w->endElement();
                $w->endElement();
            },
        );

        $response = $this->post(
            $registration->credentialEndpoint(),
            $body,
            '',
            $registration,
            null,
            'isds_gateway_credential',
        );

        $document = $this->parse($response, 'isds_gateway_credential');
        $status = $this->firstValue($document, 'status');

        if ($status !== 'OK') {
            // Prokazatelné odmítnutí: nic nevzniklo, opakovat je bezpečné —
            // ale ne s týmž sessionId, to je jednorázové.
            throw new SubmissionChannelException(
                'isds_gateway_session_rejected',
                $status === 'SESSION_NOT_FOUND'
                    ? 'Datová schránka už tuhle relaci nezná. Spusťte odeslání znovu.'
                    : 'Datová schránka odmítla ověření relace odesílací brány.',
                409,
            );
        }

        $attributes = $this->attributes($document);
        $timeLimitedId = trim((string) ($attributes['timeLimitedId'] ?? ''));
        if ($timeLimitedId === '') {
            throw new SubmissionChannelException(
                'isds_gateway_credential_missing',
                'Datová schránka nevrátila pověření pro vložení konceptu.',
                502,
            );
        }

        return new IsdsGatewayCredential(
            // Producer, ne argument: plaintext v argumentu volání by skončil
            // ve stack trace první výjimky o pár řádků níž.
            timeLimitedId: \MyInvoice\Service\Submission\Channel\SensitiveValue::fromProducer(
                static fn (): string => $timeLimitedId,
            ),
            appToken: $this->nullable($attributes['appToken'] ?? null),
            conceptDmId: $this->nullable($attributes['conceptDmId'] ?? null),
            conceptStatusCode: $this->nullable($attributes['conceptStatusCode'] ?? null),
            conceptStatusMessage: $this->nullable($attributes['conceptStatusMessage'] ?? null),
        );
    }

    public function setConcept(
        IsdsGatewayRegistration $registration,
        IsdsGatewayCredential $credential,
        IsdsConceptMessage $message,
    ): string {
        $message->assertValid();

        $response = $this->post(
            $registration->conceptEndpoint(),
            $this->writer->envelope($message),
            SetConceptRequestWriter::SOAP_ACTION,
            $registration,
            $credential,
            'isds_gateway_concept',
        );

        $document = $this->parse($response, 'isds_gateway_concept');
        $statusCode = $this->firstValue($document, 'dmStatusCode');
        $conceptId = $this->firstValue($document, 'dmID');

        // Přesná rovnost, ne `str_starts_with('00')`. Tichý neúspěch je
        // u podání to nejdražší, co se může stát.
        if ($statusCode !== IsdsGatewayCredential::STATUS_OK) {
            throw new SubmissionChannelException(
                'isds_gateway_concept_rejected',
                'Datová schránka koncept nepřijala ('
                . ($this->firstValue($document, 'dmStatusMessage') ?? 'bez uvedení důvodu')
                . ').',
                409,
            );
        }
        if ($conceptId === null || $conceptId === '') {
            // Status říká „v pořádku", ale ID chybí. Nevíme, co se stalo —
            // a nevědomost se nesmí vydávat za selhání.
            throw new IsdsTransportTimeout(
                'isds_gateway_concept_id_missing',
                'Datová schránka potvrdila koncept, ale nevrátila jeho identifikátor.',
            );
        }

        return $conceptId;
    }

    public function logout(IsdsGatewayRegistration $registration, IsdsGatewayCredential $credential): void
    {
        $timeLimitedId = $credential->timeLimitedId->reveal();

        try {
            $body = $this->soapEnvelope(
                'v1',
                self::NS_EXTWS_V1,
                static function (\XMLWriter $w) use ($timeLimitedId): void {
                    $w->startElementNS('v1', 'extWsLogoutRequest', self::NS_EXTWS_V1);
                    $w->startElementNS('v1', 'timeLimitedId', null);
                    $w->text($timeLimitedId);
                    $w->endElement();
                    $w->endElement();
                },
            );
            $this->post($registration->logoutEndpoint(), $body, '', $registration, $credential, 'isds_gateway_logout');
        } catch (\Throwable $e) {
            // Úklid nesmí přebít výsledek podání. Token vyprší i sám.
            $this->logger->warning('Zneplatnění pověření odesílací brány selhalo', [
                'reason' => $e->getMessage(),
            ] + $registration->toLogContext());
        }
    }

    // ───────────────────────── interní ─────────────────────────

    /**
     * @param callable(\XMLWriter):void $writeBody
     */
    private function soapEnvelope(string $prefix, string $namespace, callable $writeBody): string
    {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElementNS('SOAP-ENV', 'Envelope', SetConceptRequestWriter::NS_SOAP);
        $writer->startElementNS('SOAP-ENV', 'Body', null);
        $writeBody($writer);
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * @return array{status:int,body:string}
     * @throws IsdsTransportTimeout
     * @throws SubmissionChannelException
     */
    private function post(
        string $url,
        string $body,
        string $soapAction,
        IsdsGatewayRegistration $registration,
        ?IsdsGatewayCredential $credential,
        string $errorPrefix,
    ): array {
        if ($this->httpDouble !== null) {
            return ($this->httpDouble)($url, $body, $soapAction, $registration, $credential);
        }

        try {
            $clientCertificate = IsdsClientCertificate::fromBase64(
                $registration->certificate->reveal(),
                $registration->certificatePassphrase?->reveal() ?? '',
            );
        } catch (\UnexpectedValueException) {
            throw new SubmissionChannelException(
                'isds_gateway_certificate_unreadable',
                'Certifikát odesílací brány se nepodařilo otevřít. Zkontrolujte soubor a jeho heslo.',
                500,
            );
        }
        $handle = null;

        try {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new IsdsTransportTimeout($errorPrefix . '_unavailable', 'Spojení s datovou schránkou se nepodařilo otevřít.');
            }

            $headers = [
                'Content-Type: text/xml; charset=utf-8',
                'Accept: text/xml, application/xml',
                'SOAPAction: "' . $soapAction . '"',
                'User-Agent: ' . self::USER_AGENT,
                'Expect:',
            ];

            $options = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                // Přesměrování by mohlo poslat pověření jinam, než kam patří.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                // Kap. 3.1 bod 5: „Komunikace se službou ISDS probíhá vždy
                // zabezpečeným způsobem přes protokol TLSv1.2."
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ];

            if ($credential !== null) {
                // Kap. 3.4: uživatel `ExtWS`, heslo `timeLimitedId`.
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                $options[CURLOPT_USERPWD] = IsdsGatewayRegistration::WS_USERNAME
                    . ':' . $credential->timeLimitedId->reveal();
            }

            curl_setopt_array($handle, $options);
            $clientCertificate->applyTo($handle);
            $raw = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);
            if ($raw === false || $raw === '') {
                // Nedovolali jsme se, NEBO se spojení přerušilo po odeslání.
                // Obojí je nevědomost, ne selhání.
                throw new IsdsTransportTimeout(
                    $errorPrefix . '_unavailable',
                    'Spojení s datovou schránkou se přerušilo' . ($curlError !== '' ? ' (' . $curlError . ')' : '') . '.',
                );
            }
            $raw = (string) $raw;
            if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
                throw new SubmissionChannelException(
                    $errorPrefix . '_response_too_large',
                    'Datová schránka vrátila nečekaně velkou odpověď.',
                    502,
                );
            }

            if ($status === 401) {
                // Kap. 3.4: 401 znamená spotřebované, vypršelé nebo cizí
                // `timeLimitedId`, případně certifikát neodpovídající atsId.
                // Ve všech případech koncept NEVZNIKL.
                throw new SubmissionChannelException(
                    $errorPrefix . '_unauthorized',
                    'Datová schránka odmítla pověření odesílací brány. '
                    . 'Buď vypršelo, nebo bylo už použité — spusťte odeslání znovu.',
                    409,
                );
            }
            if ($status < 200 || $status >= 300) {
                throw new IsdsTransportTimeout(
                    $errorPrefix . '_http_error',
                    'Datová schránka odpověděla chybou HTTP ' . $status . '.',
                );
            }

            return ['status' => $status, 'body' => $raw];
        } finally {
            if ($handle instanceof \CurlHandle) {
                unset($handle);
            }
            $clientCertificate->clear();
        }
    }

    /** @param array{status:int,body:string} $response */
    private function parse(array $response, string $errorPrefix): \DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            // LIBXML_NONET: žádné externí entity ze sítě. DTD se nepovoluje.
            $loaded = $document->loadXML($response['body'], LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            throw new SubmissionChannelException(
                $errorPrefix . '_malformed',
                'Odpověď datové schránky se nepodařilo přečíst jako XML.',
                502,
            );
        }

        return new \DOMXPath($document);
    }

    /**
     * Čtení přes `local-name()` schválně: dokumentace uvádí jmenný prostor
     * `…/ats-ws/v1`, přibalené WSDL je `…/ats-ws/v1_1`. Vázat se na konkrétní
     * URI by znamenalo rozbít se při další drobné revizi přílohy.
     */
    private function firstValue(\DOMXPath $xpath, string $name): ?string
    {
        $nodes = $xpath->query('//*[local-name()="' . $name . '"]');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $value = trim((string) $nodes->item(0)?->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * `<m:attribute name="…" value="…"/>` — plochý seznam atributů z
     * identitního prostoru (kap. 3.2.2 a 4.1).
     *
     * @return array<string,string>
     */
    private function attributes(\DOMXPath $xpath): array
    {
        $out = [];
        $nodes = $xpath->query('//*[local-name()="attribute"]');
        if ($nodes === false) {
            return $out;
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $name = trim($node->getAttribute('name'));
            if ($name === '') {
                continue;
            }
            // Ukázka v dokumentaci má u `timeLimitedId` hodnotu s vedoucí
            // mezerou (` T01-…`). Trim tedy není kosmetika.
            $out[$name] = trim($node->getAttribute('value'));
        }

        return $out;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
