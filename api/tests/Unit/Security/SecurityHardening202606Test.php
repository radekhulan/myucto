<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression guardy pro 4. interní audit (2026-06-12) — externí zneužitelnost dat.
 * Code-inspection (bez DB) — drží fixy uzamčené v CI. Behaviorální pokrytí
 * middleware je v PermissionMiddlewareTest / ApiScopeMiddlewareTest.
 *
 * Nálezy:
 *   NX-P1-1  PAT path allowlist + default scope read
 *   NX-P2-1  RBAC bez blanket GET * + purchase-invoices pro accountanta
 *   NX-P2-2  atomický approval decide + rate-limit
 *   NX-P3-*  LIKE escape, nosniff/CSP, health gate, LIBXML_NONET, redakce, dedup, addLink
 */
final class SecurityHardening202606Test extends TestCase
{
    private function src(string $rel): string
    {
        $code = file_get_contents(dirname(__DIR__, 4) . '/api/src/' . $rel);
        self::assertIsString($code, "Soubor $rel musí jít načíst");
        return $code;
    }

    // ---- NX-P1-1 — PAT scoping ------------------------------------------------

    public function testApiScopeHasBearerPathAllowlist(): void
    {
        $code = $this->src('Middleware/ApiScopeMiddleware.php');
        self::assertStringContainsString('BEARER_ALLOWED', $code,
            'ApiScopeMiddleware musí mít path allowlist pro bearer tokeny');
        self::assertStringContainsString('token_endpoint_forbidden', $code);
        self::assertStringContainsString('isBearerAllowed', $code);
        // Pozn.: chování (/api/admin → 403) je ověřeno v ApiScopeMiddlewareTest.
    }

    public function testCreateTokenDefaultScopeIsRead(): void
    {
        $code = $this->src('Action/Auth/Tokens/CreateTokenAction.php');
        self::assertStringContainsString("\$body['scope'] ?? 'read'", $code,
            'Default scope nového tokenu musí být read (least-privilege)');
        self::assertStringNotContainsString("\$body['scope'] ?? 'read_write'", $code);
    }

    // ---- NX-P2-1 — RBAC -------------------------------------------------------

    public function testPermissionMapHasNoBlanketGetStar(): void
    {
        $code = $this->src('Security/RoutePermissionMap.php');
        self::assertStringNotContainsString("'GET *',", $code,
            'RoutePermissionMap nesmí mít blanket GET * pravidlo');
        self::assertStringContainsString('#^/api/purchase-invoices(/|$)#', $code,
            'Přijaté faktury musí mít explicitní route mapování');
    }

    // ---- NX-P2-2 — approval atomicity + rate-limit ----------------------------

    public function testApprovalDecideIsAtomic(): void
    {
        $repo = $this->src('Repository/InvoiceRepository.php');
        self::assertStringContainsString('function decideIfRequested', $repo,
            'InvoiceRepository musí mít atomický decideIfRequested');
        self::assertStringContainsString("approval_status = 'requested'", $repo,
            'Atomický UPDATE musí být podmíněný approval_status=requested');

        $action = $this->src('Action/Approval/PublicApprovalDecideAction.php');
        self::assertStringContainsString('decideIfRequested', $action,
            'Public decide musí používat atomickou metodu (TOCTOU guard)');
        self::assertStringNotContainsString('setApprovalDecision', $action,
            'Public decide nesmí používat neatomický setApprovalDecision');
    }

    public function testApprovalRateLimitRule(): void
    {
        $code = $this->src('Middleware/RateLimitMiddleware.php');
        self::assertStringContainsString('/api/public/approval/', $code,
            'RateLimit musí mít pravidlo pro veřejné schvalování');
        self::assertStringContainsString('approval_per_min_per_ip', $code);
    }

    // ---- NX-P3-* --------------------------------------------------------------

    public function testDocumentSearchEscapesLikeWildcards(): void
    {
        $code = $this->src('Repository/DocumentRepository.php');
        self::assertStringContainsString("addcslashes(\$q, '%_\\\\')", $code,
            'DocumentRepository::search musí escapovat LIKE wildcardy');
    }

    public function testFileDownloadsSetNosniff(): void
    {
        foreach ([
            'Action/Invoice/Attachment/DownloadAttachmentAction.php',
            'Action/Invoice/DownloadArchivedPdfAction.php',
        ] as $rel) {
            $code = $this->src($rel);
            self::assertStringContainsString('X-Content-Type-Options', $code, "$rel musí mít nosniff");
            self::assertStringContainsString("default-src 'none'; sandbox", $code, "$rel musí mít CSP sandbox");
        }
    }

    public function testHealthGatesWarningsBehindAuth(): void
    {
        $code = $this->src('Action/System/HealthAction.php');
        self::assertStringContainsString('ATTR_USER', $code,
            'HealthAction musí podmínit diagnostiku přihlášením');
    }

    public function testCrpDphUsesLibxmlNonet(): void
    {
        $code = $this->src('Service/Ares/CrpDphClient.php');
        self::assertStringContainsString('LIBXML_NONET', $code);
    }

    public function testSupplierRedactionBroadened(): void
    {
        // Denylist podle názvu sloupce nahradil allowlist (MYU-2026-H39); tajná pole
        // v něm nejsou, hlídá SupplierResponseAllowlistTest.
        $code = $this->src('Action/Settings/SettingsAction.php');
        self::assertStringContainsString('SupplierResponseFields::full($row, $request)', $code,
            'Detail firmy musí jít ven přes allowlist SupplierResponseFields.');
    }

    public function testPurchasePdfDedupScopedBySupplier(): void
    {
        $code = $this->src('Action/PurchaseInvoice/DeletePurchaseInvoicePdfAction.php');
        self::assertStringContainsString('pdf_hash = ? AND supplier_id = ? AND id != ?', $code,
            'Dedup check musí být scope-ovaný na supplier_id');
    }

    public function testAddLinkValidatesEntityOwnership(): void
    {
        $repo = $this->src('Repository/DocumentLinkRepository.php');
        self::assertStringContainsString('function entityBelongsToSupplier', $repo);
        $action = $this->src('Action/Document/DocumentsAction.php');
        self::assertStringContainsString('entityBelongsToSupplier', $action,
            'addLink musí ověřit vlastnictví cílové entity');
    }

    public function testBankUploadHandlesNullSize(): void
    {
        $code = $this->src('Action/Bank/BankStatementAction.php');
        self::assertStringContainsString('$file->getSize() ?? $file->getStream()->getSize()', $code,
            'Bank upload musí řešit null getSize() fallbackem na stream');
    }
}
