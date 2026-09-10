<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * F7 §3.1 — jednotná brána AI extrakce PDF. 4 extrakční signatury zkopírované
 * VERBATIM z {@see AnthropicClient} (aby žádný caller neměnil), plus credentials/
 * testConnection a dva NOVÉ hooky (strongerModel / capabilities).
 *
 * Všechny extrakční návraty zůstávají array-shaped DTO — callery destrukturují
 * pole, NEkonvertovat na objekty (viz §1.2).
 */
interface LlmGatewayInterface
{
    /** Firma je na dokladu odběratel (přijatá faktura) — výchozí režim extrakce. */
    public const TENANT_ROLE_BUYER = 'buyer';

    /**
     * Role firmy není předem daná — sken k existujícímu dokladu může být přijatý
     * i vydaný doklad. Model strany neurčuje podle firmy a roli vrátí v `company_role`.
     */
    public const TENANT_ROLE_ANY = 'any';

    /**
     * Hlavní extrakce faktury.
     *
     * Vedle údajů pro založení dokladu vrací i pole pro přiřazení skenu
     * k existujícímu dokladu: `barcode`, `license_plate`, `card_last4`,
     * `company_role` (buyer|vendor|both|none) a odběratele v `customer`.
     *
     * @return array{ok:bool, data?:array<string,mixed>, error?:string, model?:string, usage?:array<string,int>}
     */
    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null, string $tenantRole = self::TENANT_ROLE_BUYER): array;

    /**
     * Řádky výpisu palivové karty.
     *
     * @return array{ok:bool, transactions?:list<array<string,mixed>>, error?:string, model?:string, usage?:array}
     */
    public function extractFuelTransactions(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array;

    /**
     * Levný recheck jen totalu.
     *
     * @return array{ok:bool, total?:?float, error?:string, model?:string, usage?:array}
     */
    public function extractPdfTotal(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array;

    /**
     * Doplnění bank. účtu dodavatele.
     *
     * @return array{ok:bool, bank_account?:?string, iban?:?string, variable_symbol?:?string, error?:string, model?:string, usage?:array}
     */
    public function extractPaymentAccount(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array;

    // ── credentials / admin ────────────────────────────────────────────────

    /** @return array{api_key:string, default_model:string}|null */
    public function getCredentials(int $supplierId): ?array;

    /** @return array<string,mixed> */
    public function testConnection(int $supplierId): array;

    // ── NOVÉ (F7) ──────────────────────────────────────────────────────────

    /** Provider si sám určí upgrade (nese tenant kontext kvůli per-tenant configu). null = žádný upgrade. */
    public function strongerModel(int $supplierId, ?string $currentModel): ?string;

    /** Descriptor schopností providera pro daného tenanta. */
    public function capabilities(int $supplierId): LlmProviderCapabilities;
}
