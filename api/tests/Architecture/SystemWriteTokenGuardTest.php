<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * Token s plným zápisem (`system_write`, #371) jedná v agendách jako přihlášený
 * uživatel. Brány agend proto stojí na `RequestAuthorization::actsAsSession()`
 * a `isSessionAuth()` zůstává jen tam, kam token nesmí nikdy: přihlášení, MFA,
 * správa tokenů a relací, licence, správa instance a přístupové údaje firmy.
 *
 * Seznam níž je úplný. Nová brána na `isSessionAuth()` mimo něj znamená vědomé
 * rozhodnutí, že ani token s plným zápisem na místo nesmí; pak ji sem přidej
 * s důvodem. Jinak patří na `actsAsSession()`.
 */
final class SystemWriteTokenGuardTest extends TestCase
{
    /** @var array<string,string> soubor => proč tam token s plným zápisem nesmí */
    private const SESSION_ONLY_SURFACES = [
        'Action/Admin/Import/AiProviderCredentialsAction.php' => 'klíče AI poskytovatelů',
        'Action/Admin/Import/MyuctoMigrationAction.php' => 'správa instance (migrace celé instalace)',
        'Action/Admin/TechnicalSupplierAccessAction.php' => 'superadmin: přístupy technického účtu',
        'Action/Admin/UserSessionAction.php' => 'superadmin: relace uživatelů',
        'Action/Auth/DefaultSupplierAction.php' => 'nastavení účtu',
        'Action/Auth/DomainLoginAction.php' => 'přihlášení',
        'Action/Auth/MfaOfferAction.php' => 'MFA',
        'Action/Auth/MfaRecoveryCodeAction.php' => 'MFA',
        'Action/Auth/MfaStepUpAction.php' => 'MFA step-up',
        'Action/Auth/OidcAction.php' => 'přihlášení přes poskytovatele identity',
        'Action/Auth/PasskeyAction.php' => 'passkey',
        'Action/Auth/SessionAction.php' => 'relace',
        'Action/Auth/Tokens/CreateTokenAction.php' => 'token nesmí zakládat tokeny',
        'Action/Auth/Tokens/DeleteTokenAction.php' => 'správa tokenů',
        'Action/Auth/TotpAction.php' => 'MFA',
        'Action/Eet/EetCertificatesAction.php' => 'certifikáty EET',
        'Action/InstanceAdmin/InstanceAdminAction.php' => 'správa instance',
        'Action/Invoice/UpdateInvoiceAction.php' => 'schopnost #355 rozlišuje kanál, ne bránu agendy',
        'Action/License/PurchaseCompleteAction.php' => 'licence',
        'Action/License/PurchaseStartAction.php' => 'licence',
        'Action/Logbook/OdometerSourceAction.php' => 'připojení účtu u výrobce vozidla',
        'Action/Mcp/HostedMcpSettingsAction.php' => 'připojení MCP',
        'Action/Mcp/McpOAuthAction.php' => 'připojení MCP (OAuth)',
        'Action/Mcp/McpOAuthGrantsAction.php' => 'připojení MCP (OAuth)',
        'Action/OAuth/OAuthCallbackAction.php' => 'OAuth napojení',
        'Action/Payroll/PayrollJmhzSigningProfileAction.php' => 'podpisový profil',
        'Action/PurchaseInvoice/BankPaymentOrderSubmissionAction.php' => 'podpis plateb v bance (přesměrování prohlížeče)',
        'Action/Settings/CertificateVaultAction.php' => 'trezor certifikátů',
        'Action/Settings/CsasOnboardingAction.php' => 'napojení banky',
        'Action/Settings/EmailProfilesAction.php' => 'SMTP přístupy',
        'Action/Settings/KbPlusOnboardingAction.php' => 'napojení banky',
        'Action/Settings/SettingsAction.php' => 'převzetí certifikátů z jiných firem',
        'Action/Settings/SigningProfilesAction.php' => 'podpisové profily',
        'Action/Settings/SupplierCarryOverAction.php' => 'převzetí certifikátů z jiných firem',
        'Action/Settings/SupplierDomainAction.php' => 'přihlašovací domény',
        'Action/Submission/DataBoxSettingsAction.php' => 'přístup do datové schránky',
        'Action/Submission/SubmissionInboxStorageSettingsAction.php' => 'nastavení datové schránky',
        'Action/WorkReport/PublicWorkReportGetAction.php' => 'veřejný odkaz, náhled jen pro člověka v aplikaci',
        'Middleware/PermissionMiddleware.php' => 'technický účet instance',
        'Middleware/SessionLockMiddleware.php' => 'zámek relace',
        'Middleware/SupplierScopeMiddleware.php' => 'technický účet instance',
        'Security/RequestAuthorization.php' => 'helpery samotné',
        'Service/Auth/LoginPolicyService.php' => 'politika přihlášení',
        'Service/Auth/SensitiveOperationReauth.php' => 'opětovné ověření',
        'Service/Bank/Connector/BankOAuthCallbackHandler.php' => 'napojení banky',
        'Service/Epo/EpoStepUpService.php' => 'MFA step-up podání',
    ];

    public function testSessionOnlyGateIsReservedForSecuritySurfaces(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $users = [];
        foreach (SourceCorpus::sources($root) as $rel => $src) {
            $code = self::withoutComments($src);
            if (preg_match('/(RequestAuthorization|self)::isSessionAuth\(/', $code) === 1) {
                $users[] = $rel;
            }
        }
        sort($users);
        $expected = array_keys(self::SESSION_ONLY_SURFACES);
        sort($expected);

        self::assertSame(
            $expected,
            $users,
            "Brána agendy patří na RequestAuthorization::actsAsSession(), aby ji prošel i token\n"
            . "s plným zápisem. isSessionAuth() je jen pro přihlášení, MFA, tokeny, licenci,\n"
            . "správu instance a přístupové údaje; nové místo zdůvodni v SESSION_ONLY_SURFACES.",
        );
    }

    /**
     * Uzavřené a podané období token nepřebije nikdy (#371 bod 5). Pravidlo žije
     * v jediném helperu a všechny brány přebití se na něj ptají.
     */
    public function testPeriodProtectionStaysSessionOnly(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $auth = self::withoutComments(SourceCorpus::read($root . '/Security/RequestAuthorization.php'));

        self::assertMatchesRegularExpression(
            '/function mayOverridePeriodProtection\(Request \$request\): bool\s*\{\s*return !self::isBearerAuth\(\$request\);\s*\}/',
            $auth,
            'Přebití uzavřeného a podaného období API token nesmí nikdy, ani s plným zápisem (ne actsAsSession()).',
        );
        foreach (['canChangeFiledVatPeriod', 'canUnlockProtectedPeriods', 'acknowledgesLockedPeriod'] as $method) {
            self::assertSame(1, preg_match('/function ' . $method . '\(.*?\n    \}/s', $auth, $m), $method);
            self::assertStringContainsString('self::mayOverridePeriodProtection($request)', $m[0], $method);
        }

        $guard = self::withoutComments(SourceCorpus::read($root . '/Http/GuardsDocumentLock.php'));
        self::assertStringContainsString('RequestAuthorization::isSystemWriteToken($request)', $guard);
        self::assertStringContainsString('RequestAuthorization::mayOverridePeriodProtection($request)', $guard);
        self::assertStringContainsString('$lock->inClosingPeriod || $lock->dateLocked', $guard);

        foreach ([
            'Action/Accounting/AccountingPeriodAction.php',
            'Action/TaxEvidence/AnnualClosingAction.php',
            'Action/Invoice/UpdateInvoiceAction.php',
        ] as $rel) {
            self::assertStringContainsString(
                'RequestAuthorization::mayOverridePeriodProtection($request)',
                self::withoutComments(SourceCorpus::read($root . '/' . $rel)),
                $rel,
            );
        }

        $readers = [];
        foreach (SourceCorpus::sources($root) as $rel => $src) {
            if (str_contains(self::withoutComments($src), 'ACK_LOCKED_PARAM]')) {
                $readers[] = $rel;
            }
        }
        self::assertSame(
            ['Security/RequestAuthorization.php'],
            $readers,
            'Potvrzení zásahu do zamčeného data (ack_locked) se čte jen přes RequestAuthorization::acknowledgesLockedPeriod().',
        );
    }

    public function testSystemWriteForbiddenCoversSecuritySurfaces(): void
    {
        foreach ([
            '/api/auth/tokens', '/api/auth/tokens/7', '/api/auth/change-password', '/api/auth/totp/setup',
            '/api/auth/webauthn/credentials', '/api/auth/me', '/api/auth/logout', '/api/admin/users',
            '/api/admin/roles/3', '/api/admin/user-sessions', '/api/instance-admin/status', '/api/license/activate',
            '/api/managed/license', '/api/mcp/grants', '/api/oauth/callback', '/api/maintenance/sample-data',
            '/api/settings/signing', '/api/settings/pdf-signing/profiles', '/api/settings/certificates',
            '/api/settings/email-profiles', '/api/settings/databox', '/api/settings/isds-gateway',
            '/api/settings/bank-connections', '/api/settings/domains', '/api/settings/ai-assist',
            '/api/settings/client/signing/profiles',
        ] as $path) {
            self::assertTrue(ApiScopeMiddleware::isSystemWriteForbidden($path), $path);
        }
        foreach ([
            '/api/auth/api-me', '/api/accounting/other-items/1/post', '/api/property/charges/issues',
            '/api/invoices/1/book', '/api/payroll/runs', '/api/settings/supplier', '/api/settings/number-series',
            '/api/settings/client/invoice-defaults', '/api/purchase-invoices/1/transition',
        ] as $path) {
            self::assertFalse(ApiScopeMiddleware::isSystemWriteForbidden($path), $path);
        }
    }

    private static function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }
}
