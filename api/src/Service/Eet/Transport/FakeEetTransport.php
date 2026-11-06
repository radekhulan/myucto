<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Transport pro testy ostatních modulů: nic neposílá, vrací naprogramované
 * výsledky z fronty a zaznamenává volání. Bez naprogramovaného výsledku vrátí
 * potvrzení s fiktivním POK (koncovka „-ff“), v ověřovacím módu `Chyba kod=0`.
 */
final class FakeEetTransport implements EetTransportInterface
{
    /** @var list<EetSendResult|\Throwable|\Closure> */
    private array $queue = [];

    /** @var list<array{environment:EetEnvironment, data:EetSaleData, header:EetMessageHeader, timeouts:EetTimeouts}> */
    private array $calls = [];

    /**
     * Naplánuje další výsledek: hotový výsledek, výjimku k vyhození, nebo
     * closure `fn(EetEnvironment, EetSaleData, EetMessageHeader): EetSendResult`.
     */
    public function queue(EetSendResult|\Throwable|\Closure ...$results): self
    {
        foreach ($results as $result) {
            $this->queue[] = $result;
        }

        return $this;
    }

    public function queueOutcome(EetOutcome $outcome, ?int $errorCode = null, ?string $errorMessage = null): self
    {
        return $this->queue(new EetSendResult(outcome: $outcome, errorCode: $errorCode, errorMessage: $errorMessage));
    }

    public function send(
        EetEnvironment $environment,
        EetSaleData $data,
        EetMessageHeader $header,
        EetSigningCredential $credential,
        EetTimeouts $timeouts,
    ): EetSendResult {
        $this->calls[] = ['environment' => $environment, 'data' => $data, 'header' => $header, 'timeouts' => $timeouts];
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if ($next instanceof \Closure) {
            return $next($environment, $data, $header);
        }
        if ($next instanceof EetSendResult) {
            return $next;
        }

        return self::defaultResult($environment, $header);
    }

    /** @return list<array{environment:EetEnvironment, data:EetSaleData, header:EetMessageHeader, timeouts:EetTimeouts}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** @return array{environment:EetEnvironment, data:EetSaleData, header:EetMessageHeader, timeouts:EetTimeouts}|null */
    public function lastCall(): ?array
    {
        return $this->calls === [] ? null : $this->calls[array_key_last($this->calls)];
    }

    public function pendingResults(): int
    {
        return count($this->queue);
    }

    public static function fictitiousPok(): string
    {
        $hex = bin2hex(random_bytes(15));

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 12, 3)
            . '-8' . substr($hex, 15, 3) . '-' . substr($hex, 18, 12) . '-ff';
    }

    private static function defaultResult(EetEnvironment $environment, EetMessageHeader $header): EetSendResult
    {
        $receivedAt = EetMessageBuilder::formatDateTime(new \DateTimeImmutable('now'));
        if ($header->verification) {
            return new EetSendResult(
                outcome: EetOutcome::VerificationOk,
                testFlag: $environment->isTest() ? true : null,
                errorCode: 0,
                errorMessage: EetErrorCatalog::ERRORS[0]['text'],
                receivedAt: $receivedAt,
                responseUuid: $header->uuid,
            );
        }

        return new EetSendResult(
            outcome: EetOutcome::Confirmed,
            pok: self::fictitiousPok(),
            testFlag: $environment->isTest() ? true : null,
            receivedAt: $receivedAt,
            responseUuid: $header->uuid,
            signatureVerified: true,
        );
    }
}
