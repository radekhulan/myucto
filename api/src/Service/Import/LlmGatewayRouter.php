<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * F7 §3.4 / §13.2 — per-tenant router AI extrakce. Implementuje
 * {@see LlmGatewayInterface} a je to to, co se v Bootstrapu bindne na interface.
 * Resolvuje providera / eu-required z `supplier`, vybere konkrétní klienta z
 * {@see LlmProviderRegistry}, získá REÁLNÝ region z `capabilities()->dataRegion`
 * (single source of truth — router netipuje) a vynutí {@see ResidencyPolicy}
 * (fail-closed) na KAŽDÉ delegované metodě (4× extrakce + strongerModel + testConnection).
 * NIKDY cross-provider ani cross-tenant fallback (§3.4/§3.5).
 *
 * Provenance (§3.7): router k array-shaped extrakčním návratům přidá `provider` a
 * `region`, aby je {@see AiPdfExtractor} / {@see \MyInvoice\Action\Admin\Import\AiExtractPdfAction}
 * mohly vyzvednout do response badge + activity_log.
 *
 * Inkrement `*_extractions_count` (§3.4 krok 6): ZÁMĚRNĚ NEdělá router — každý
 * konkrétní klient si vlastní svůj vlastní inkrement (per-client counter model),
 * takže nedochází k dvojímu počítání. Anthropic chování zůstává identické.
 */
final class LlmGatewayRouter implements LlmGatewayInterface
{
    /** Cache: existují už per-tenant AI sloupce v `supplier`? (probe jednou na instanci). */
    private ?bool $tenantColsPresent = null;

    public function __construct(
        private readonly Connection $db,
        private readonly LlmProviderRegistry $registry,
        private readonly ResidencyPolicy $residency,
        private readonly LoggerInterface $logger,
    ) {}

    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null, string $tenantRole = self::TENANT_ROLE_BUYER): array
    {
        return $this->dispatch($supplierId, fn (LlmGatewayInterface $c) => $c->extractInvoice($supplierId, $pdfBytes, $modelOverride, $tenantRole));
    }

    public function extractFuelTransactions(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        return $this->dispatch($supplierId, fn (LlmGatewayInterface $c) => $c->extractFuelTransactions($supplierId, $pdfBytes, $modelOverride));
    }

    public function extractPdfTotal(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        return $this->dispatch($supplierId, fn (LlmGatewayInterface $c) => $c->extractPdfTotal($supplierId, $pdfBytes, $modelOverride));
    }

    public function extractPaymentAccount(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        return $this->dispatch($supplierId, fn (LlmGatewayInterface $c) => $c->extractPaymentAccount($supplierId, $pdfBytes, $modelOverride));
    }

    public function testConnection(int $supplierId): array
    {
        [$provider, , $euRequired] = $this->resolveTenant($supplierId);
        try {
            $client = $this->registry->resolve($provider);
            // testConnection běží stejnou fail-closed cestou (§3.5) — real region z capabilities.
            $region = $client->capabilities($supplierId)->dataRegion;
            $this->residency->assertAllowed($provider, $region, $euRequired);
        } catch (ResidencyViolationException) {
            return ['ok' => false, 'error' => 'residency_conflict'];
        } catch (\RuntimeException) {
            return ['ok' => false, 'error' => 'provider_not_configured'];
        }
        return $client->testConnection($supplierId);
    }

    public function getCredentials(int $supplierId): ?array
    {
        [$provider] = $this->resolveTenant($supplierId);
        try {
            $client = $this->registry->resolve($provider);
        } catch (\RuntimeException) {
            return null;
        }
        return $client->getCredentials($supplierId);
    }

    public function strongerModel(int $supplierId, ?string $currentModel): ?string
    {
        [$provider, , $euRequired] = $this->resolveTenant($supplierId);
        try {
            $client = $this->registry->resolve($provider);
            // Upgrade se resolvuje ve STEJNÉM regionu (§3.5) — EU tenant nesmí
            // „upgradem" utéct na US endpoint. ResidencyViolationException extends
            // RuntimeException → zde chytneme obojí a fail-closed vrátíme null (žádný upgrade).
            $region = $client->capabilities($supplierId)->dataRegion;
            $this->residency->assertAllowed($provider, $region, $euRequired);
        } catch (\RuntimeException) {
            return null;
        }
        return $client->strongerModel($supplierId, $currentModel);
    }

    public function capabilities(int $supplierId): LlmProviderCapabilities
    {
        [$provider] = $this->resolveTenant($supplierId);
        return $this->registry->resolve($provider)->capabilities($supplierId);
    }

    /**
     * Společná cesta pro array-shaped extrakční metody: resolve tenant → resolve
     * client → real region z capabilities → ResidencyPolicy (fail-closed) → delegace →
     * obohacení o provenance (provider/region). NIKDY cross-provider fallback.
     *
     * @param callable(LlmGatewayInterface):array<string,mixed> $call
     * @return array<string,mixed>
     */
    private function dispatch(int $supplierId, callable $call): array
    {
        [$provider, , $euRequired] = $this->resolveTenant($supplierId);
        try {
            $client = $this->registry->resolve($provider);
            $region = $client->capabilities($supplierId)->dataRegion;
            $this->residency->assertAllowed($provider, $region, $euRequired);
        } catch (ResidencyViolationException) {
            $this->logger->warning('LlmGatewayRouter: residency conflict', [
                'supplier_id' => $supplierId,
                'provider'    => $provider,
            ]);
            return ['ok' => false, 'error' => 'residency_conflict'];
        } catch (\RuntimeException) {
            return ['ok' => false, 'error' => 'provider_not_configured'];
        }
        $result = $call($client);
        if (is_array($result)) {
            // Provenance badge (§3.7) — nepřepisuj, jen doplň (klient je běžně nenese).
            $result += ['provider' => $provider, 'region' => $region];
        }
        return $result;
    }

    /**
     * Resolvuje providera / (deklarovaný) region / eu-required z `supplier`.
     * Region z tohoto SELECTu je jen deklarovaný `ai_data_region` — pro residency
     * enforcement se použije REÁLNÝ region z `capabilities()->dataRegion` (§13.2).
     * Information_schema probe zůstává jako safety net (nové sloupce mohou u čerstvé
     * DB chybět → degradace na default anthropic/us/false, chování Anthropic identické).
     *
     * @return array{0:string,1:string,2:bool} [provider, declaredRegion, euRequired]
     */
    private function resolveTenant(int $supplierId): array
    {
        if (!$this->tenantColumnsPresent()) {
            return ['anthropic', 'us', false];
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT ai_provider, ai_data_region, ai_eu_residency_required FROM supplier WHERE id = ?'
            );
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $row = false;
        }
        if (!is_array($row)) {
            return ['anthropic', 'us', false];
        }
        $provider = !empty($row['ai_provider']) ? (string) $row['ai_provider'] : 'anthropic';
        $region   = !empty($row['ai_data_region']) ? (string) $row['ai_data_region'] : 'us';
        $euReq    = (bool) ($row['ai_eu_residency_required'] ?? false);
        return [$provider, $region, $euReq];
    }

    /**
     * Jednorázová (per-instance cache) detekce, zda `supplier.ai_provider` už existuje.
     */
    private function tenantColumnsPresent(): bool
    {
        if ($this->tenantColsPresent !== null) {
            return $this->tenantColsPresent;
        }
        try {
            $stmt = $this->db->pdo()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'supplier'
                   AND COLUMN_NAME = 'ai_provider'"
            );
            $this->tenantColsPresent = $stmt !== false && ((int) $stmt->fetchColumn()) > 0;
        } catch (\Throwable) {
            $this->tenantColsPresent = false;
        }
        return $this->tenantColsPresent;
    }
}
