<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Autorizační SSOT pro provozní nastavení delegovatelná klientovi.
 *
 * Klientská role používá existující `settings.company` na úrovni WRITE. Široké
 * `settings.company.write` zůstává výhradně interním rolím, protože otevírá i
 * daňové, účetní a právně významné údaje firmy.
 */
final class OperationalSettingsAccess
{
    public static function emailProfiles(Request $request): bool
    {
        if (RequestAuthorization::isClientType($request)) {
            return self::clientCompanyWrite($request);
        }
        return RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE);
    }

    public static function branding(Request $request): bool
    {
        if (RequestAuthorization::isClientType($request)) {
            return self::clientCompanyWrite($request);
        }
        return RequestAuthorization::allows($request, 'settings.branding', AccessLevel::WRITE);
    }

    public static function clientCompanyWrite(Request $request): bool
    {
        return RequestAuthorization::isClientType($request)
            && RequestAuthorization::allows($request, 'settings.company', AccessLevel::WRITE);
    }
}
