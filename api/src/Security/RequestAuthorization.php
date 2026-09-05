<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use Psr\Http\Message\ServerRequestInterface as Request;

final class RequestAuthorization
{
    /**
     * Je požadavek z přihlášené browserové relace?
     *
     * ⚠️ Chybějící atribut je „NE". `SessionLockMiddleware` ho u zamčené relace
     * na veřejných cestách odstraní, takže požadavek bez metody je fakticky
     * anonymní — a ten přes session-only bránu projít nesmí. Podmínka opsaná
     * jako `=== 'bearer'` tohle nepokrývala a anonymní požadavek pouštěla dál;
     * brány se proto píšou `!isSessionAuth()`, ne `isBearerAuth()`.
     */
    public static function isSessionAuth(Request $request): bool
    {
        return $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD) === 'session';
    }

    /**
     * Je požadavek ověřený API tokenem?
     *
     * Pro místa, která podle kanálu jen MĚNÍ CHOVÁNÍ (přeskočení CSRF, užší tělo
     * požadavku, logování), ne pro brány. Na bránu patří {@see isSessionAuth()}.
     */
    public static function isBearerAuth(Request $request): bool
    {
        return $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD) === 'bearer';
    }

    /**
     * Smí tenhle token vidět v Dokumentech evidenci mzdových PODÁNÍ?
     *
     * ⚠️ Jediná výjimka z pravidla, že mzdová evidence je session-only, a týká
     * se VÝHRADNĚ podání — doručenek, protokolů ČSSZ, odpovědí pojišťoven.
     * Zdravotní údaje, exekuce, insolvence, cizinecká povolení a výplatní pásky
     * zůstávají zavřené i s tímhle příznakem: jsou to zvláštní kategorie
     * osobních údajů a dlouhodobé tajemství v konfiguráku integrace se na ně
     * nehodí. Schopnost se zapíná výslovně při vytváření tokenu, které je samo
     * chráněné relací, heslem a MFA.
     */
    public static function tokenAllowsPayrollSubmissionDocs(Request $request): bool
    {
        $token = $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_API_TOKEN);

        return is_array($token) && (int) ($token['allow_payroll_submission_docs'] ?? 0) === 1;
    }

    /**
     * Název způsobu ověření pro VÝPIS, ne pro rozhodování.
     *
     * Používá ho `/api/auth/api-me`, které o sobě říká, čím je požadavek
     * ověřený. Na bránu patří {@see isSessionAuth()} — porovnávat tenhle
     * řetězec ručně je přesně to, čemu se tu odvyká.
     */
    public static function authMethod(Request $request, string $default = 'session'): string
    {
        $method = $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, $default);

        return is_string($method) && $method !== '' ? $method : $default;
    }

    public static function effectiveRole(Request $request): EffectiveRole
    {
        $role = $request->getAttribute('auth.effective_role');
        if ($role instanceof EffectiveRole) return $role;

        $user = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []);
        $legacy = is_string($user['role'] ?? null) ? $user['role'] : '';
        if ($legacy === 'admin') {
            return new EffectiveRole(0, 'Superadmin', 'superadmin', true, [], 'superadmin');
        }
        if (!in_array($legacy, ['accountant', 'readonly', 'client'], true)) {
            return EffectiveRole::denied();
        }
        $catalog = new PermissionCatalog();
        return new EffectiveRole(
            0,
            $legacy,
            $legacy === 'client' ? 'client' : 'staff',
            true,
            $catalog->legacyPreset($legacy),
            $legacy,
        );
    }

    public static function isSuperadmin(Request $request): bool
    {
        return self::effectiveRole($request)->isSuperadmin();
    }

    public static function isCompanyAdmin(Request $request): bool
    {
        return self::effectiveRole($request)->isCompanyAdmin();
    }

    public static function canCreateSupplier(Request $request): bool
    {
        return self::effectiveRole($request)->canCreateSupplier();
    }

    public static function isClientType(Request $request): bool
    {
        return self::effectiveRole($request)->isClientType();
    }

    public static function allows(
        Request $request,
        string $permission,
        AccessLevel $minimum = AccessLevel::READ,
    ): bool {
        $role = self::effectiveRole($request);
        return $role->isSuperadmin() || ($role->isActive && $role->level($permission)->allows($minimum));
    }
}
