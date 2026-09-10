<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Service\Import\LlmProviderCapabilities;

/**
 * Testovací AI brána bez sítě. Odpověď na extrakci určuje callback nad bajty
 * dokumentu (null = extrakce selže). Počítá volání, ať jde ověřit, že se už
 * vytěžený obsah znovu nevytěžuje.
 */
final class FakeLlmGateway implements LlmGatewayInterface
{
    public int $invoiceCalls = 0;
    /** @var list<string> */
    public array $roles = [];

    /** @param \Closure(string):?array<string,mixed> $responder */
    public function __construct(private readonly \Closure $responder, private readonly bool $configured = true) {}

    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null, string $tenantRole = self::TENANT_ROLE_BUYER): array
    {
        $this->invoiceCalls++;
        $this->roles[] = $tenantRole;
        $data = ($this->responder)($pdfBytes);
        return $data === null
            ? ['ok' => false, 'error' => 'synthetic_failure']
            : ['ok' => true, 'data' => $data, 'model' => 'fake-model', 'provider' => 'fake'];
    }

    public function extractFuelTransactions(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        return ['ok' => false, 'error' => 'not_supported'];
    }

    public function extractPdfTotal(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        return ['ok' => false, 'error' => 'not_supported'];
    }

    public function extractPaymentAccount(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        return ['ok' => false, 'error' => 'not_supported'];
    }

    public function getCredentials(int $supplierId): ?array
    {
        return $this->configured ? ['api_key' => 'synthetic', 'default_model' => 'fake-model'] : null;
    }

    public function testConnection(int $supplierId): array
    {
        return ['ok' => $this->configured];
    }

    public function strongerModel(int $supplierId, ?string $currentModel): ?string
    {
        return null;
    }

    public function capabilities(int $supplierId): LlmProviderCapabilities
    {
        return LlmProviderCapabilities::anthropic('us');
    }
}
