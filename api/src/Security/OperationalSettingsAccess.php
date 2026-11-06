<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use Psr\Http\Message\ServerRequestInterface as Request;

/** Autorizační SSOT pro samostatně delegovatelná provozní nastavení firmy. */
final class OperationalSettingsAccess
{
    public static function emailProfiles(Request $request): bool
    {
        return RequestAuthorization::allows($request, 'settings.email_profiles', AccessLevel::WRITE);
    }

    public static function emailTemplates(Request $request): bool
    {
        return RequestAuthorization::allows($request, 'settings.email_templates', AccessLevel::WRITE);
    }

    public static function branding(Request $request): bool
    {
        return RequestAuthorization::allows($request, 'settings.branding', AccessLevel::WRITE);
    }

    public static function invoiceDefaults(Request $request): bool
    {
        return RequestAuthorization::allows($request, 'settings.invoice_defaults', AccessLevel::WRITE);
    }

    public static function paymentQr(Request $request): bool
    {
        return RequestAuthorization::allows($request, 'settings.payment_qr', AccessLevel::WRITE);
    }

    public static function clientSigningProfiles(Request $request): bool
    {
        return RequestAuthorization::isClientType($request)
            && RequestAuthorization::allows($request, 'settings.signing_profiles', AccessLevel::WRITE);
    }
}
