<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Eet\EetActiveCredential;
use MyInvoice\Service\Eet\EetCredentialProvider;
use MyInvoice\Service\Eet\Transport\EetEnvironment;
use MyInvoice\Service\Eet\Transport\EetMessageHeader;
use MyInvoice\Service\Eet\Transport\EetOutcome;
use MyInvoice\Service\Eet\Transport\EetSaleData;
use MyInvoice\Service\Eet\Transport\EetSendResult;
use MyInvoice\Service\Eet\Transport\EetSigningCredential;
use MyInvoice\Service\Eet\Transport\EetTimeouts;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;

/**
 * Transport EET pro testy dispatch: odpovědi ze skriptu, zaznamenané volání
 * (Data, hlavička). Bez sítě a bez podpisu. Skript je fronta výsledků nebo
 * callbacků; prázdná fronta = potvrzeno.
 */
final class ScriptedEetTransport implements EetTransportInterface
{
    /** @var list<EetSendResult|\Closure(EetSaleData, EetMessageHeader): EetSendResult> */
    public array $script = [];

    /** @var list<array{data: EetSaleData, header: EetMessageHeader, environment: EetEnvironment}> */
    public array $calls = [];

    public function send(EetEnvironment $environment, EetSaleData $data, EetMessageHeader $header, EetSigningCredential $credential, EetTimeouts $timeouts): EetSendResult
    {
        $this->calls[] = ['data' => $data, 'header' => $header, 'environment' => $environment];
        $next = array_shift($this->script) ?? self::confirmed();
        return $next instanceof \Closure ? $next($data, $header) : $next;
    }

    /** @return list<array{data: EetSaleData, header: EetMessageHeader, environment: EetEnvironment}> */
    public function callsFor(string $deviceCode): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c['data']->deviceCode === $deviceCode));
    }

    /**
     * Jediné místo, které staví výsledek transportu (při integraci s kódem
     * transportu stačí upravit tady).
     *
     * @param list<array{code:int|string,message:string}> $warnings
     */
    public static function result(string $outcome, ?string $pok = null, ?int $errorCode = null, ?string $errorMessage = null, array $warnings = []): EetSendResult
    {
        return new EetSendResult(
            outcome: EetOutcome::from($outcome),
            pok: $pok,
            testFlag: true,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            warnings: $warnings,
            requestXml: '<soap:Envelope>synthetic</soap:Envelope>',
            responseXml: $outcome === 'timeout' ? null : '<soap:Envelope>synthetic response</soap:Envelope>',
            httpStatus: $outcome === 'timeout' ? null : 200,
            durationMs: 12,
            receivedAt: null,
            responseUuid: null,
            signatureVerified: $pok !== null,
            signatureFailure: null,
        );
    }

    public static function confirmed(string $pok = 'TEST-POK-0001-ff'): EetSendResult
    {
        return self::result('confirmed', $pok);
    }

    public static function credentials(?int $certificateId = 7): EetCredentialProvider
    {
        return new class ($certificateId) implements EetCredentialProvider {
            public function __construct(public ?int $certificateId) {}

            public function activeCredential(int $supplierId, EetEnvironment $env, ?string $eic = null): ?EetActiveCredential
            {
                if ($this->certificateId === null) {
                    return null;
                }
                $credential = (new \ReflectionClass(EetSigningCredential::class))->newInstanceWithoutConstructor();
                return new EetActiveCredential(certificateId: $this->certificateId, credential: $credential);
            }
        };
    }
}
