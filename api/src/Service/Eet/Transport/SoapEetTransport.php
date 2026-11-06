<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Odeslání datové zprávy EET: builder → XSD → podpis → HTTPS → rozbor → ověření.
 *
 * Potvrzení (`Confirmed*`) vznikne jen z odpovědi s ověřeným podpisem správce
 * daně, se shodným UUID pokusu a s příznakem prostředí, který odpovídá cíli.
 * Všechno ostatní, co nelze doložit, je `InvalidResponse` — nikdy potvrzení.
 * Samotné HTTP 200 nic neznamená.
 */
final class SoapEetTransport implements EetTransportInterface
{
    public const SOAP_ACTION = 'http://fs.gov.cz/eet/OdeslaniTrzby';

    private readonly EetMessageBuilder $builder;
    private readonly EetXmlValidator $validator;
    private readonly EetSigner $signer;
    private readonly EetResponseParser $parser;
    private readonly EetResponseVerifier $verifier;

    public function __construct(
        private readonly EetHttpClientInterface $http = new CurlEetHttpClient(),
        ?EetMessageBuilder $builder = null,
        ?EetXmlValidator $validator = null,
        ?EetSigner $signer = null,
        ?EetResponseParser $parser = null,
        ?EetResponseVerifier $verifier = null,
        /** Hodiny pro ověření platnosti certifikátu odpovědi (testy). */
        private readonly ?\Closure $clock = null,
    ) {
        $this->builder = $builder ?? new EetMessageBuilder();
        $this->validator = $validator ?? new EetXmlValidator();
        $this->signer = $signer ?? new EetSigner();
        $this->parser = $parser ?? new EetResponseParser($this->validator);
        $this->verifier = $verifier ?? new EetResponseVerifier();
    }

    public function send(
        EetEnvironment $environment,
        EetSaleData $data,
        EetMessageHeader $header,
        EetSigningCredential $credential,
        EetTimeouts $timeouts,
    ): EetSendResult {
        return $this->sendSigned($environment, $this->prepare($data, $header, $credential), $header, $timeouts);
    }

    /** Sestaví, zvaliduje a podepíše zprávu; nic neodesílá. */
    public function prepare(EetSaleData $data, EetMessageHeader $header, EetSigningCredential $credential): string
    {
        $trzba = $this->builder->build($data, $header);
        $this->validator->validate($trzba);

        return $this->signer->sign($trzba, $credential);
    }

    /**
     * Odešle už podepsanou zprávu a vyhodnotí odpověď. Odděleně od {@see send()}
     * kvůli diagnostice (např. negativní test s poškozeným podpisem).
     */
    public function sendSigned(EetEnvironment $environment, string $requestXml, EetMessageHeader $header, EetTimeouts $timeouts): EetSendResult
    {
        try {
            $response = $this->http->post($environment->endpoint(), $requestXml, [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '"' . self::SOAP_ACTION . '"',
            ], $timeouts);
        } catch (EetHttpException $exception) {
            return new EetSendResult(
                outcome: $exception->timeout ? EetOutcome::Timeout : EetOutcome::TransportError,
                errorMessage: $exception->getMessage(),
                requestXml: $requestXml,
                durationMs: $exception->durationMs,
            );
        }

        return $this->evaluate($environment, $header, $requestXml, $response);
    }

    private function evaluate(EetEnvironment $environment, EetMessageHeader $header, string $requestXml, EetHttpResponse $response): EetSendResult
    {
        $base = [
            'requestXml' => $requestXml,
            'responseXml' => $response->body,
            'httpStatus' => $response->status,
            'durationMs' => $response->durationMs,
            'transactionId' => $response->header('X-Global-Transaction-Id'),
        ];
        try {
            $parsed = $this->parser->parse($response->body);
        } catch (EetSignatureException $exception) {
            // Nečitelná odpověď s chybovým HTTP stavem je chyba přenosu (proxy, údržba),
            // nečitelná odpověď se stavem 200 je podezřelá odpověď.
            $ok = $response->status === 200;

            return self::result($base, [
                'outcome' => $ok ? EetOutcome::InvalidResponse : EetOutcome::TransportError,
                'errorMessage' => $ok
                    ? $exception->getMessage()
                    : sprintf('Správce daně vrátil HTTP %d bez srozumitelné odpovědi.', $response->status),
                'signatureFailure' => $ok ? $exception->reason : null,
            ]);
        }
        if ($parsed->isFault()) {
            return self::result($base, [
                'outcome' => EetOutcome::TransportError,
                'errorMessage' => trim(sprintf('SOAP Fault %s: %s', $parsed->faultCode, $parsed->faultString)),
            ]);
        }
        $common = $base + [
            'testFlag' => $parsed->testFlag,
            'warnings' => $parsed->warnings,
            'receivedAt' => $parsed->receivedAt ?? $parsed->rejectedAt,
            'responseUuid' => $parsed->uuid,
        ];
        $uuidMatches = $parsed->uuid !== null && strcasecmp($parsed->uuid, $header->uuid) === 0;

        if ($parsed->isConfirmation()) {
            $common['pok'] = $parsed->pok;
            if ($header->verification) {
                return self::invalid($common, 'unexpected_confirmation', 'Na zprávu v ověřovacím módu přišlo potvrzení s POK.');
            }
            try {
                $this->verifier->verify($parsed, $environment, $this->clock !== null ? ($this->clock)() : null);
            } catch (EetSignatureException $exception) {
                return self::invalid($common, $exception->reason, $exception->getMessage());
            }
            $common['signatureVerified'] = true;
            if (!$uuidMatches) {
                return self::invalid($common, 'uuid_mismatch', 'Potvrzení nepatří k odeslané zprávě (jiné UUID).');
            }
            $fictitious = str_ends_with(strtolower((string) $parsed->pok), '-ff');
            if ($environment->isTest() !== ($parsed->testFlag === true) || $environment->isTest() !== $fictitious) {
                return self::invalid($common, 'environment_mismatch', 'Příznak prostředí nebo tvar POK neodpovídá cílovému prostředí.');
            }

            return self::result($common, [
                'outcome' => $parsed->warnings === [] ? EetOutcome::Confirmed : EetOutcome::ConfirmedWithWarning,
            ]);
        }

        $code = (int) $parsed->errorCode;
        $common['errorCode'] = $code;
        if ($parsed->uuid !== null && !$uuidMatches) {
            return self::invalid($common, 'uuid_mismatch', 'Chybová odpověď nepatří k odeslané zprávě (jiné UUID).');
        }
        if ($code === 0) {
            if (!$header->verification) {
                return self::invalid($common, 'unexpected_verification_result', 'Kód 0 přišel na zprávu, která nebyla v ověřovacím módu.');
            }
            // Úspěšné ověření se uzná jen s UUID odeslané zprávy; bez něj nic nedokládá.
            if (!$uuidMatches) {
                return self::invalid($common, 'uuid_mismatch', 'Výsledek ověření nenese UUID odeslané zprávy.');
            }

            return self::result($common, ['outcome' => EetOutcome::VerificationOk, 'errorMessage' => $parsed->errorText]);
        }

        return self::result($common, [
            'outcome' => EetErrorCatalog::outcomeFor($code),
            'errorMessage' => $parsed->errorText,
        ]);
    }

    /**
     * @param array<string,mixed> $common
     */
    private static function invalid(array $common, string $reason, string $message): EetSendResult
    {
        return self::result($common, [
            'outcome' => EetOutcome::InvalidResponse,
            'errorMessage' => $message,
            'signatureFailure' => $reason,
        ]);
    }

    /**
     * @param array<string,mixed> $common
     * @param array<string,mixed> $specific
     */
    private static function result(array $common, array $specific): EetSendResult
    {
        return new EetSendResult(...array_merge($common, $specific));
    }
}
