<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;

final class RoutePermissionMapTest extends TestCase
{
    public function testSpecificActionsPrecedeModuleFallbacks(): void
    {
        $map = new RoutePermissionMap();
        $cases = [
            ['POST', '/api/invoices/7/issue', 'invoices.issue', AccessLevel::WRITE],
            ['POST', '/api/invoices/7/send', 'invoices.send', AccessLevel::WRITE],
            ['POST', '/api/invoices/bulk-reminder', 'invoices.reminder', AccessLevel::WRITE],
            ['POST', '/api/invoices/bulk-reissue', 'invoices.clone', AccessLevel::WRITE],
            ['DELETE', '/api/invoices/7', 'invoices.delete', AccessLevel::WRITE],
            ['GET', '/api/invoices/7', 'invoices', AccessLevel::READ],
            ['GET', '/api/purchase-invoices/payment-orders/history', 'purchase_invoices.payment_orders', AccessLevel::READ],
            ['POST', '/api/purchase-invoices/scan-inbox', 'purchase_invoices.scan', AccessLevel::WRITE],
            ['POST', '/api/purchase-invoices/import-structured', 'purchase_invoices.create', AccessLevel::WRITE],
            ['GET', '/api/purchase-invoice-submissions', 'documents.inbox', AccessLevel::READ],
            ['POST', '/api/purchase-invoice-submissions', 'documents.inbox', AccessLevel::WRITE],
            ['DELETE', '/api/purchase-invoice-submissions/7', 'documents.inbox.delete', AccessLevel::WRITE],
            ['GET', '/api/purchase-invoice-submissions/7/preview', 'documents.inbox', AccessLevel::READ],
            ['POST', '/api/purchase-invoice-submissions/7/extract', 'documents.inbox', AccessLevel::WRITE],
            ['GET', '/api/portal/purchase-invoice-submissions', 'documents.submit', AccessLevel::READ],
            ['POST', '/api/portal/purchase-invoice-submissions/7/resubmit', 'documents.submit', AccessLevel::WRITE],
            ['GET', '/api/portal/document-requests', 'documents.submit', AccessLevel::READ],
            ['POST', '/api/portal/document-requests/7/upload', 'documents.submit', AccessLevel::WRITE],
            ['DELETE', '/api/purchase-invoices/7/link-advance', 'purchase_invoices', AccessLevel::WRITE],
            ['DELETE', '/api/purchase-invoices/7/advance-suggestion', 'purchase_invoices', AccessLevel::WRITE],
            ['DELETE', '/api/purchase-invoices/7/pdf', 'purchase_invoices', AccessLevel::WRITE],
            ['GET', '/api/clients/7/work-report-link', 'clients.public_links', AccessLevel::READ],
            ['POST', '/api/clients/7/work-report-link/send', 'clients.public_links', AccessLevel::WRITE],
            ['GET', '/api/reports/monthly-export/preview', 'reports.export', AccessLevel::READ],
            ['POST', '/api/reports/monthly-export/start', 'reports.export', AccessLevel::WRITE],
            ['POST', '/api/reports/monthly-export/jobs/7/cancel', 'reports.export', AccessLevel::WRITE],
            ['DELETE', '/api/reports/monthly-export/jobs/7', 'reports.export', AccessLevel::WRITE],
            ['GET', '/api/reports/submissions/settings', 'reports.submit', AccessLevel::WRITE],
            ['GET', '/api/reports/submissions/7/artifacts/9/download', 'reports.export', AccessLevel::READ],
            ['POST', '/api/accounting/periods/4/close', 'accounting.periods.close', AccessLevel::WRITE],
            ['POST', '/api/accounting/assets/4/dispose', 'assets.dispose', AccessLevel::WRITE],
            ['POST', '/api/accounting/cash-documents', 'cash.document.write', AccessLevel::WRITE],
            ['GET', '/api/accounting/bank-accounts', 'accounting', AccessLevel::READ],
            ['PATCH', '/api/accounting/bank-accounts/7', 'accounting', AccessLevel::WRITE],
            ['GET', '/api/accounting/bank-posting-unposted', 'bank.rules', AccessLevel::READ],
            ['GET', '/api/accounting/bank-posting-unposted/count', 'bank.rules', AccessLevel::READ],
            // §DM — pravidla klasifikace výdaje spadají pod fallback `accounting`; role
            // „client" ho nemá, takže na ně (včetně GETů bez self-checku v Action) nedosáhne.
            ['GET', '/api/accounting/expense-rules', 'accounting', AccessLevel::READ],
            ['POST', '/api/accounting/expense-rules', 'accounting', AccessLevel::WRITE],
            ['PUT', '/api/accounting/expense-rules/7', 'accounting', AccessLevel::WRITE],
            ['DELETE', '/api/accounting/expense-rules/7', 'accounting', AccessLevel::WRITE],
            ['GET', '/api/accounting/purchase-invoices/7/expense-suggestions', 'accounting', AccessLevel::READ],
            ['POST', '/api/stock/takes/4/close', 'stock.close', AccessLevel::WRITE],
            ['GET', '/api/settings/currencies', 'settings.bank_accounts', AccessLevel::READ],
            ['POST', '/api/settings/currencies', 'settings.bank_accounts', AccessLevel::WRITE],
            ['GET', '/api/settings/email-branding/preview', 'settings.branding', AccessLevel::READ],
            ['POST', '/api/settings/email-branding/logo', 'settings.branding', AccessLevel::WRITE],
            ['GET', '/api/settings/accounting-activation/status', 'settings.company', AccessLevel::READ],
            ['POST', '/api/settings/accounting-activation/start', 'accounting.periods.manage', AccessLevel::WRITE],
            ['GET', '/api/price-list-items', 'invoices', AccessLevel::READ],
            ['GET', '/api/price-list-items/7/resolve', 'invoices', AccessLevel::READ],
            ['GET', '/api/bank-statements/7/match-suggestions', 'bank', AccessLevel::READ],
            ['POST', '/api/bank-match-suggestions/7/accept', 'bank.match', AccessLevel::WRITE],
            ['POST', '/api/bank-match-suggestions/7/reject', 'bank.match', AccessLevel::WRITE],
            ['GET', '/api/payroll/capabilities', 'payroll', AccessLevel::READ],
            ['GET', '/api/payroll/operational-health', 'payroll', AccessLevel::READ],
            ['GET', '/api/payroll/operational-reconciliation', 'payroll', AccessLevel::READ],
            ['GET', '/api/payroll/operational-reconciliation/issues/42', 'payroll', AccessLevel::READ],
            ['POST', '/api/payroll/operational-reconciliation/sweep', 'payroll', AccessLevel::WRITE],
            ['GET', '/api/payroll/components/jmhz-targets', 'payroll', AccessLevel::READ],
            ['GET', '/api/payroll/components/jmhz-mappings', 'payroll', AccessLevel::READ],
            ['GET', '/api/payroll/components/42/jmhz-mapping', 'payroll', AccessLevel::READ],
            ['PUT', '/api/payroll/components/42/jmhz-mapping', 'payroll.inputs.write', AccessLevel::WRITE],
            ['DELETE', '/api/payroll/components/42/jmhz-mapping', 'payroll.inputs.write', AccessLevel::WRITE],
            ['GET', '/api/payroll/people', 'payroll', AccessLevel::READ],
            ['POST', '/api/payroll/people', 'payroll.person.write', AccessLevel::WRITE],
            ['GET', '/api/payroll/people/42', 'payroll', AccessLevel::READ],
            ['GET', '/api/payroll/people/42/profile', 'payroll', AccessLevel::READ],
            ['PUT', '/api/payroll/people/42/profile', 'payroll.person.write', AccessLevel::WRITE],
            ['PUT', '/api/payroll/people/42/quick-edit', 'payroll.person.write', AccessLevel::WRITE],
            ['POST', '/api/payroll/people/42/sensitive-reveal', 'payroll.person.read_sensitive', AccessLevel::READ],
            ['POST', '/api/payroll/people/42/employments', 'payroll.employment.write', AccessLevel::WRITE],
            ['GET', '/api/payroll/insolvency/people/42/month/2026-08/options', 'payroll.insolvency', AccessLevel::READ],
            ['GET', '/api/payroll/insolvency/people/42/month/2026-08/evidence', 'payroll.insolvency', AccessLevel::READ],
            ['PUT', '/api/payroll/insolvency/people/42/month/2026-08/evidence', 'payroll.insolvency', AccessLevel::WRITE],
            ['POST', '/api/payroll/insolvency/people/42/month/2026-08/commands/cancel', 'payroll.insolvency', AccessLevel::WRITE],
            ['POST', '/api/payroll/enforcement/cooperation/requests/import', 'payroll.enforcement.cooperation', AccessLevel::WRITE],
            ['GET', '/api/payroll/enforcement/cooperation/candidates', 'payroll.enforcement.cooperation', AccessLevel::READ],
            ['GET', '/api/payroll/enforcement/cooperation/requests/42', 'payroll.enforcement.cooperation', AccessLevel::READ],
            ['POST', '/api/payroll/enforcement/cooperation/requests/42/preview', 'payroll.enforcement.cooperation', AccessLevel::WRITE],
            ['POST', '/api/payroll/enforcement/cooperation/requests/42/responses', 'payroll.enforcement.cooperation', AccessLevel::WRITE],
            ['POST', '/api/payroll/enforcement/cooperation/responses/42/enqueue', 'payroll.enforcement.cooperation', AccessLevel::WRITE],
            ['GET', '/api/payroll/submissions/regzel/profile', 'payroll.submissions', AccessLevel::READ],
            ['PUT', '/api/payroll/submissions/regzel/profile', 'payroll.submissions', AccessLevel::WRITE],
            ['POST', '/api/payroll/submissions/regzel/prepare', 'payroll.submissions', AccessLevel::WRITE],
            ['GET', '/api/payroll/submissions/regzel/snapshots', 'payroll.submissions', AccessLevel::READ],
            ['GET', '/api/payroll/submissions/regzel/snapshots/42/xml', 'payroll.submissions', AccessLevel::READ],
            ['GET', '/api/payroll/submissions/overview', 'payroll.submissions', AccessLevel::READ],
            ['POST', '/api/payroll/submissions/isds-gateway/outbox/42', 'payroll.submissions', AccessLevel::WRITE],
            ['POST', '/api/payroll/submissions/isds-gateway/callback', 'payroll.submissions', AccessLevel::WRITE],
            ['GET', '/api/submissions/gateway/capability', 'settings.signing', AccessLevel::READ],
            ['GET', '/api/payroll/submissions/inbox', 'payroll.submissions', AccessLevel::READ],
            ['POST', '/api/payroll/submissions/inbox/42/acknowledge', 'payroll.submissions', AccessLevel::WRITE],
            ['POST', '/api/payroll/submissions/inbox/42/snooze', 'payroll.submissions', AccessLevel::WRITE],
            ['GET', '/api/payroll/submissions/jmhz-pvpoj/42', 'payroll.submissions', AccessLevel::READ],
            ['GET', '/api/payroll/submissions/jmhz-pvpoj/42/download', 'payroll.submissions', AccessLevel::READ],
            ['GET', '/api/payroll/submissions/jmhz-ordinary-evidence/42', 'payroll.submissions', AccessLevel::READ],
            ['POST', '/api/payroll/submissions/jmhz-ordinary-evidence/42/101', 'payroll.submissions', AccessLevel::WRITE],
            ['POST', '/api/payroll/submissions/eldp/42/manual-completion', 'payroll.submissions', AccessLevel::WRITE],
            ['GET', '/api/payroll/submissions/registration/42/a1-profile', 'payroll.submissions', AccessLevel::READ],
            ['PUT', '/api/payroll/submissions/registration/42/a1-profile', 'payroll.submissions', AccessLevel::WRITE],
            ['GET', '/api/payroll/submissions/registration/42/a2-evidence-candidates', 'payroll.submissions', AccessLevel::READ],
            ['GET', '/api/payroll/submissions/42/jmhz-content-correction', 'payroll.submissions', AccessLevel::WRITE],
            ['GET', '/api/payroll/submissions/42/jmhz-content-correction-preparations', 'payroll.submissions', AccessLevel::WRITE],
            ['POST', '/api/payroll/submissions/42/jmhz-content-correction', 'payroll.submissions', AccessLevel::WRITE],
            ['GET', '/api/payroll/submissions/42', 'payroll.submissions', AccessLevel::READ],
            ['GET', '/api/payroll/submissions/health-overviews/42', 'payroll.submissions', AccessLevel::READ],
            ['GET', '/api/payroll/submissions/health-overviews/42/111/download', 'payroll.submissions', AccessLevel::READ],
            ['POST', '/api/payroll/submissions/health-notifications/duties/obligations', 'payroll.submissions', AccessLevel::WRITE],
            ['GET', '/api/payroll/settings/policies', 'payroll.settings', AccessLevel::READ],
            ['POST', '/api/payroll/settings/policies', 'payroll.settings', AccessLevel::WRITE],
            ['GET', '/api/payroll/settings/policies/42', 'payroll.settings', AccessLevel::READ],
            ['PUT', '/api/payroll/settings/policies/42', 'payroll.settings', AccessLevel::WRITE],
            ['GET', '/api/payroll/setup-check', 'payroll.settings', AccessLevel::READ],
            ['PUT', '/api/payroll/employments/42/terms', 'payroll.employment.write', AccessLevel::WRITE],
            ['GET', '/api/payroll/jmhz/employment-evidence-options', 'payroll', AccessLevel::READ],
            ['GET', '/api/payroll/jmhz/municipalities', 'payroll', AccessLevel::READ],
            ['POST', '/api/payroll/employments/42/transitions/active', 'payroll.employment.write', AccessLevel::WRITE],
            ['PUT', '/api/payroll/employments/42/checklist/employment_contract', 'payroll.employment.write', AccessLevel::WRITE],
            ['GET', '/api/payroll/people/42/foreign-permits', 'payroll', AccessLevel::READ],
            ['POST', '/api/payroll/people/42/foreign-permits', 'payroll.person.write', AccessLevel::WRITE],
            ['GET', '/api/payroll/settings/activation', 'payroll.settings', AccessLevel::READ],
            ['PUT', '/api/payroll/settings/activation', 'payroll.settings', AccessLevel::WRITE],
            ['GET', '/api/payroll/settings/account-options', 'payroll.settings', AccessLevel::READ],
            ['GET', '/api/payroll/settings/employer', 'payroll.settings', AccessLevel::READ],
            ['PUT', '/api/payroll/settings/employer', 'payroll.settings', AccessLevel::WRITE],
            ['GET', '/api/payroll/settings/institution-accounts', 'payroll.settings', AccessLevel::READ],
            ['POST', '/api/payroll/settings/institution-accounts', 'payroll.settings', AccessLevel::WRITE],
            ['GET', '/api/payroll/settings/institution-accounts/42', 'payroll.settings', AccessLevel::READ],
            ['PUT', '/api/payroll/settings/institution-accounts/42', 'payroll.settings', AccessLevel::WRITE],
            ['GET', '/api/payroll/time/month', 'payroll', AccessLevel::READ],
            ['PUT', '/api/payroll/time/calendars/42', 'payroll.time.write', AccessLevel::WRITE],
            ['POST', '/api/payroll/time/shifts', 'payroll.time.write', AccessLevel::WRITE],
            ['POST', '/api/payroll/time/entries', 'payroll.time.write', AccessLevel::WRITE],
            ['POST', '/api/payroll/time/imports/preview', 'payroll.time.write', AccessLevel::WRITE],
            ['POST', '/api/payroll/time/imports', 'payroll.time.write', AccessLevel::WRITE],
            ['POST', '/api/payroll/time/months/2026-05/approve', 'payroll.approve', AccessLevel::WRITE],
            ['POST', '/api/payroll/time/months/2026-05/reopen', 'payroll.reopen', AccessLevel::WRITE],
            ['GET', '/api/payroll/year-close/2026', 'payroll', AccessLevel::READ],
            ['POST', '/api/payroll/year-close/2026/close', 'payroll.approve', AccessLevel::WRITE],
            ['POST', '/api/payroll/year-close/2026/reopen', 'payroll.reopen', AccessLevel::WRITE],
            ['POST', '/api/payroll/inputs/42/approve', 'payroll.approve', AccessLevel::WRITE],
            ['DELETE', '/api/payroll/runs/42', 'payroll.inputs.write', AccessLevel::WRITE],
            ['GET', '/api/payroll/documents', 'payroll.documents', AccessLevel::READ],
            ['GET', '/api/payroll/documents/annual', 'payroll.documents', AccessLevel::READ],
            ['POST', '/api/payroll/people/7/documents/payroll-sheet/2026', 'payroll.documents', AccessLevel::WRITE],
            ['POST', '/api/payroll/runs/7/revisions/9/documents/monthly-bundle', 'payroll.documents', AccessLevel::WRITE],
            ['GET', '/api/payroll/employments/7/documents/exit', 'payroll.documents', AccessLevel::READ],
            ['POST', '/api/payroll/employments/7/documents/exit/employment-certificate', 'payroll.documents', AccessLevel::WRITE],
            ['POST', '/api/payroll/employments/7/documents/exit/average-earnings-certificate', 'payroll.documents', AccessLevel::WRITE],
            ['POST', '/api/payroll/documents/42/download-grant', 'payroll.documents', AccessLevel::READ],
            ['GET', '/api/payroll/documents/42/download', 'payroll.documents', AccessLevel::READ],
        ];
        foreach ($cases as [$method, $path, $key, $level]) {
            $match = $map->match($method, $path);
            self::assertNotNull($match, "$method $path");
            self::assertSame($key, $match->key, "$method $path");
            self::assertSame($level, $match->minimum, "$method $path");
        }
    }

    public function testEveryMappedPermissionExistsInCatalog(): void
    {
        $catalog = new PermissionCatalog();
        $map = new RoutePermissionMap();
        foreach ([
            ['GET', '/api/dashboard/summary'], ['GET', '/api/clients'], ['POST', '/api/projects'],
            ['GET', '/api/documents'], ['PUT', '/api/settings/supplier'], ['GET', '/api/tax-return/dpfo/2026'],
            ['POST', '/api/stock/takes'], ['DELETE', '/api/logbook/trips/2'], ['POST', '/api/eshop/categories'],
        ] as [$method, $path]) {
            $match = $map->match($method, $path);
            self::assertNotNull($match, "$method $path");
            self::assertTrue($catalog->has((string) $match->key), (string) $match->key);
        }
    }

    public function testUnknownProtectedRouteIsNotMatched(): void
    {
        self::assertNull((new RoutePermissionMap())->match('GET', '/api/future-dangerous-feature'));
    }

    /**
     * Import dokladů je práce s daty firmy, ne konfigurace systému — Action vrstva
     * u něj oprávnění deklaruje sama a middleware ji musí nechat rozhodnout. Dokud
     * všechno pod `/api/admin/` padalo rovnou na superadmina, byl `utilities.import`
     * mrtvý klíč: nešlo ho v rolích uplatnit, protože se guard nikdy nespustil.
     */
    public function testAdminImportRoutesAreResolvedByPermission(): void
    {
        $map = new RoutePermissionMap();
        foreach ([
            ['POST', '/api/admin/import', 'utilities.import', AccessLevel::WRITE],
            ['POST', '/api/admin/imports/idoklad/start', 'utilities.import', AccessLevel::WRITE],
            ['POST', '/api/admin/imports/fakturoid/start', 'utilities.import', AccessLevel::WRITE],
            ['GET', '/api/admin/imports/42', 'utilities.import', AccessLevel::READ],
            ['POST', '/api/admin/imports/42/cancel', 'utilities.import', AccessLevel::WRITE],
            ['DELETE', '/api/admin/imports/42', 'utilities.import', AccessLevel::WRITE],
            ['GET', '/api/admin/imports/idoklad/credentials', 'utilities.import', AccessLevel::WRITE],
            ['PUT', '/api/admin/imports/fakturoid/credentials', 'utilities.import', AccessLevel::WRITE],
            ['DELETE', '/api/admin/imports/idoklad/credentials', 'utilities.import', AccessLevel::WRITE],
            ['POST', '/api/admin/imports/ai-extract-pdf', 'purchase_invoices.scan', AccessLevel::WRITE],
            ['POST', '/api/admin/imports/ai-extract-pdf-issued', 'invoices.create', AccessLevel::WRITE],
        ] as [$method, $path, $key, $level]) {
            $match = $map->match($method, $path);
            self::assertSame(RoutePermissionMap::PERMISSION, $match?->kind, "$method $path");
            self::assertSame($key, $match?->key, "$method $path");
            self::assertSame($level, $match?->minimum, "$method $path");
        }
    }

    /** Klíče z admin výjimek musí existovat v katalogu — jinak by je nešlo v rolích nastavit. */
    public function testAdminImportPermissionKeysExistInCatalog(): void
    {
        $map = new RoutePermissionMap();
        $catalog = new PermissionCatalog();
        foreach ([
            ['POST', '/api/admin/import'],
            ['POST', '/api/admin/imports/idoklad/start'],
            ['GET', '/api/admin/imports/42'],
            ['POST', '/api/admin/imports/ai-extract-pdf'],
            ['POST', '/api/admin/imports/ai-extract-pdf-issued'],
        ] as [$method, $path]) {
            $key = (string) $map->match($method, $path)?->key;
            self::assertTrue($catalog->has($key), "$method $path → $key");
        }
    }

    /**
     * Výjimka je úzká a fail-closed: cokoli mimo vyjmenované importní routy zůstává
     * superadmin-only. Hlídá hlavně to, aby regex na `/imports/…` neuklouzl na
     * konfiguraci systému nebo na sousední admin agendy.
     */
    public function testOtherAdminRoutesStaySuperadminOnly(): void
    {
        $map = new RoutePermissionMap();
        foreach ([
            ['GET', '/api/admin/users'],
            ['POST', '/api/admin/users'],
            ['GET', '/api/admin/roles'],
            ['GET', '/api/admin/export'],
            ['GET', '/api/admin/invoices-zip'],
            ['GET', '/api/admin/approvals'],
            ['GET', '/api/admin/imports'],
            ['POST', '/api/admin/imports/idoklad/credentials/rotate'],
            ['GET', '/api/admin/imports/42/download'],
            ['POST', '/api/maintenance/reindex'],
            // Klíče k AI poskytovatelům zůstávají superadmin-only (F7).
            ['GET', '/api/admin/imports/ai/credentials'],
            ['PUT', '/api/admin/imports/ai/credentials'],
            ['DELETE', '/api/admin/imports/anthropic/credentials'],
            ['POST', '/api/admin/imports/ai/credentials/test'],
        ] as [$method, $path]) {
            self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match($method, $path)?->kind, "$method $path");
        }
    }

    /** Metoda mimo pravidlo nesmí propadnout na permission větev (GET nespouští import). */
    public function testAdminImportRulesRespectMethod(): void
    {
        $map = new RoutePermissionMap();
        self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match('GET', '/api/admin/import')?->kind);
        self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match('GET', '/api/admin/imports/idoklad/start')?->kind);
        self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match('PUT', '/api/admin/imports/42')?->kind);
        self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match('GET', '/api/admin/imports/ai-extract-pdf')?->kind);
    }

    public function testAdminAndSelfServiceAreFixedClasses(): void
    {
        $map = new RoutePermissionMap();
        self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match('GET', '/api/admin/users')?->kind);
        self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match('POST', '/api/price-list-items')?->kind);
        self::assertSame(RoutePermissionMap::SUPERADMIN, $map->match('DELETE', '/api/price-list-items/7')?->kind);
        self::assertSame(RoutePermissionMap::SELF_SERVICE, $map->match('GET', '/api/auth/me')?->kind);
        self::assertSame(RoutePermissionMap::PUBLIC, $map->match('POST', '/api/auth/login')?->kind);
    }
}
