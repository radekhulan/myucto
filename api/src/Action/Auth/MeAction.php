<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Service\Auth\MfaOfferService;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\License\LicenseService;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MeAction
{
    private readonly EntityCache $cache;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly \MyInvoice\Repository\UserSupplierRepository $userSuppliers,
        private readonly PermissionResolver $permissions,
        private readonly LicenseService $license,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly MfaOfferService $mfaOffers,
        private readonly SessionLockPolicy $lockPolicy,
        private readonly ClockInterface $clock,
        ?EntityCache $cache = null,
    ) {
        // Volitelná a na konci, ať se nerozbijí volající, kteří akci staví
        // pozičně (unit testy). Produkci ji předává explicitní bind v Bootstrapu —
        // PHP-DI volitelný class-param sám nevyplní.
        $this->cache = $cache ?? EntityCache::disabled();
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
        $currentSupplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        // Epic F0/F6: membership filtr (zrcadlí SettingsAction::listSuppliers) —
        // uživatel s membership řádky vidí jen přiřazené firmy; role 'client'
        // VŽDY jen membership (0 řádků → prázdný seznam, fail-closed). Bez filtru
        // by klient viděl názvy/IČO cizích firem instance.
        $isSuperadmin = (bool) ($user['is_superadmin'] ?? false);
        $allowed = $isSuperadmin
            ? []
            : $this->userSuppliers->allowedSupplierIds((int) ($user['id'] ?? 0));
        if (!$isSuperadmin && $allowed === []) {
            $suppliers = [];
        } else {
            $ossSelect = $this->db->hasColumn('supplier', 'oss_enabled')
                ? 'oss_enabled'
                : '0 AS oss_enabled';
            $where  = '';
            $params = [];
            if ($allowed !== []) {
                $where  = ' WHERE id IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
                $params = $allowed;
            }
            // /api/auth/me volá frontend při každém načtení stránky, takže tenhle
            // seznam je na horké cestě. Skupina `supplier` — změna nastavení firmy
            // (plátcovství DPH, režim účetnictví, sklad) ji přetočí na úrovni PDO.
            // Rozsah přiřazených firem je součástí KLÍČE, takže změna membershipu
            // vede na jiný klíč a starou odpověď nemůže vrátit.
            $cacheKey = 'me:suppliers:' . ($allowed === [] ? 'all' : implode(',', $allowed))
                . ':' . ($ossSelect === 'oss_enabled' ? '1' : '0');
            $suppliers = (array) $this->cache->remember(
                EntityCache::GROUP_SUPPLIER,
                $cacheKey,
                function () use ($where, $params, $ossSelect): array {
                    $stmt = $this->db->pdo()->prepare(
                        // dic + adresa: dashboard pod názvem firmy vypisuje identifikaci
                        // (IČ · DIČ · adresa) na jeden řádek — bez nich by musel dělat
                        // druhý dotaz jen kvůli hlavičce stránky.
                        'SELECT id, company_name, ic, dic, street, city, zip,
                                is_vat_payer, is_identified, taxpayer_type,
                                default_payment_due_days, default_payment_due_unit, default_prices_include_vat,
                                auto_send_reminders, payment_thanks_enabled, payment_thanks_default_checked,
                                accounting_mode, accounting_enabled, payroll_enabled, stock_enabled, ' . $ossSelect . ',
                                ai_provider, ai_data_region, ai_eu_residency_required
                           FROM supplier' . $where . ' ORDER BY id'
                    );
                    $stmt->execute($params);

                    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
                },
            );
        }
        $domainContext = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        if ($domainContext instanceof TenantDomainContext && $domainContext->locksSupplier()) {
            $suppliers = array_values(array_filter(
                $suppliers,
                static fn (array $supplier): bool => (int) ($supplier['id'] ?? 0) === $domainContext->supplierId,
            ));
        }

        foreach ($suppliers as &$s) {
            $s['id']                       = (int) $s['id'];
            $s['is_vat_payer']             = (bool) $s['is_vat_payer'];
            // Identifikace firmy pro hlavičku dashboardu. `??` drží kompatibilitu
            // se záznamy, které mohou ještě viset v entity cache z doby před
            // rozšířením SELECTu výš — chybějící sloupec nesmí shodit odpověď.
            $s['dic']                      = isset($s['dic']) && $s['dic'] !== null ? (string) $s['dic'] : null;
            $s['street']                   = (string) ($s['street'] ?? '');
            $s['city']                     = (string) ($s['city'] ?? '');
            $s['zip']                      = (string) ($s['zip'] ?? '');
            // Režim účetnictví (Epic F1) — 'double_entry' zpřístupní účetní UI (deník,
            // osnova, období). Nav sekce se řídí touto hodnotou přes supplier store.
            $s['accounting_mode']          = (string) ($s['accounting_mode'] ?? 'tax_evidence');
            // „Vést účetnictví" (migrace 1179) — vypnuté schová účetní sekce z menu stejně,
            // jako by nebyla licence. Default TRUE i pro řádky bez sloupce (starší schéma),
            // aby vypnutí bylo vždy vědomé rozhodnutí, ne důsledek chybějící migrace.
            $s['accounting_enabled']       = (bool) ($s['accounting_enabled'] ?? true);
            // „Vést mzdy" (migrace 1187, opt-in od 1290) — narozdíl od účetnictví výš je
            // to samostatná agenda, kterou většina firem nevede, takže se zapíná vědomě.
            $s['payroll_enabled']          = (bool) ($s['payroll_enabled'] ?? false);
            // Sklad (Epic SKLAD, migrace 1023) — opt-in modul; nav sekce Sklad se řídí
            // touto hodnotou (stejný vzor jako accounting_mode výše).
            $s['stock_enabled']            = (bool) ($s['stock_enabled'] ?? false);
            $s['oss_enabled']              = (bool) ($s['oss_enabled'] ?? false);
            // Identifikovaná osoba (§ 6g–6l, issue #94) — neplátce s přeshraničními
            // povinnostmi; editor podle ní nabídne RC u zahraničních faktur.
            $s['is_identified']            = (bool) ($s['is_identified'] ?? false);
            // 'fo' = OSVČ (fyzická osoba), 'po' = s.r.o. (právnická osoba), null = nenastaveno.
            $s['taxpayer_type']            = $s['taxpayer_type'] !== null ? (string) $s['taxpayer_type'] : null;
            $s['default_payment_due_days'] = (int) $s['default_payment_due_days'];
            $s['default_payment_due_unit'] = (string) ($s['default_payment_due_unit'] ?? 'days');
            // Výchozí režim cen u nových faktur (0 = bez DPH, 1 = ceny s DPH) — předvyplní editor.
            $s['default_prices_include_vat'] = (bool) ($s['default_prices_include_vat'] ?? false);
            // Per-faktura přepínač upomínek v editoru se skryje, když dodavatel auto-upomínky nemá.
            $s['auto_send_reminders']      = (bool) ($s['auto_send_reminders'] ?? true);
            // Děkovný e-mail (issue #57) — UI v mark-paid modalu podle nich zobrazí checkbox.
            $s['payment_thanks_enabled']         = (bool) ($s['payment_thanks_enabled'] ?? false);
            $s['payment_thanks_default_checked'] = (bool) ($s['payment_thanks_default_checked'] ?? false);
            // F7 — AI provider selection (pro FE badge / Settings sekci; nav gate).
            $s['ai_provider']                    = (string) ($s['ai_provider'] ?? 'anthropic');
            $s['ai_data_region']                 = (string) ($s['ai_data_region'] ?? 'us');
            $s['ai_eu_residency_required']       = (bool) ($s['ai_eu_residency_required'] ?? false);
        }

        $totpEnabled  = (bool) ($user['totp_enabled'] ?? false);
        $requireTotp  = (bool) $this->config->get('auth.require_totp', false);
        $mustSetupTotp = $requireTotp && !$totpEnabled;
        $effectiveRole = $this->permissions->resolve($request);
        $roleSummary = $effectiveRole->id > 0 ? [
            'id' => $effectiveRole->id,
            'name' => $effectiveRole->name,
            'type' => $effectiveRole->type,
            'is_active' => $effectiveRole->isActive,
            'system_key' => $effectiveRole->systemKey,
        ] : (array) ($user['role_summary'] ?? []);
        $userId = (int) ($user['id'] ?? 0);
        $passkeyCount = $userId > 0 ? $this->credentials->countActiveForUser($userId) : 0;
        $mfaMethods = [];
        if ($passkeyCount > 0) {
            $mfaMethods[] = 'passkey';
        }
        if ($totpEnabled) {
            $mfaMethods[] = 'totp';
        }
        $assurance = (string) ($session['assurance_level'] ?? 'legacy');
        // ⚠️ Politika, ne jen historie session. `assurance_level` se do session
        // zapíše při vydání a nikdy se nemění, takže po vypnutí
        // `auth.require_mfa` by stará setup session pořád hlásila
        // `must_setup_mfa: true` a frontend by ji držel na `/setup-mfa`.
        // Viz {@see \MyInvoice\Middleware\RequireMfaMiddleware}, kde platí totéž.
        $mustSetupMfa = $assurance === 'setup' && $this->mfaPolicy->isRequired();
        // Protipól `must_setup_mfa`: když se MFA NEvynucuje, uživatel se o něm
        // jinak nedozví — /setup-mfa se mu nikdy nezobrazí. Nabídku dostane, dokud
        // nemá žádný faktor a dokud ji neodmítl (users.mfa_offer_dismissed_at).
        $shouldOfferMfa = $this->mfaOffers->shouldOffer($userId, $mfaMethods !== []);
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $userTimeout = ($user['session_lock_after_minutes'] ?? null) !== null
            ? (int) $user['session_lock_after_minutes']
            : null;
        $effectiveTimeout = $this->lockPolicy->effectiveTimeoutMinutes($userTimeout);
        $idleExpiresAt = null;
        if ($assurance !== 'setup' && $effectiveTimeout > 0) {
            // Autoritou zámku je SessionLockMiddleware; /me jen reportuje. Chybějící
            // nebo pokažený čas aktivity proto nesmí shodit celý profil na 500 —
            // klient si deadline dotáhne z /api/auth/session/status.
            $lastActivity = self::tryParseUtc((string) ($session['last_user_activity_at'] ?? ''));
            $idleExpiresAt = $lastActivity !== null
                ? self::isoUtc($lastActivity->modify(sprintf('+%d minutes', $effectiveTimeout)))
                : null;
        }

        $licenseSummary = (LicenseMiddleware::state($request) ?? $this->license->current())->toMeSummary();
        if ($effectiveRole->isClientType()) {
            $licenseSummary['subscription_state'] = null;
        }

        return Json::ok($response, [
            'user' => [
                'id'              => $userId,
                'email'           => $user['email'] ?? '',
                'name'            => $user['name'] ?? '',
                'role'            => $roleSummary,
                'is_superadmin'   => $isSuperadmin,
                'locale'          => $user['locale'] ?? 'cs',
                'totp_enabled'    => $totpEnabled,
                'must_setup_totp' => $mustSetupTotp,
                'mfa_enabled'     => $mfaMethods !== [],
                'mfa_methods'     => $mfaMethods,
                'passkey_count'   => $passkeyCount,
                'must_setup_mfa'  => $mustSetupMfa,
                'should_offer_mfa' => $shouldOfferMfa,
            ],
            'csrf_token'          => $session['csrf_token'] ?? '',
            'current_supplier_id' => $currentSupplierId,
            'suppliers'           => $suppliers,
            'domain_context'      => $domainContext instanceof TenantDomainContext
                ? $domainContext->toArray()
                : null,
            'permissions'         => $effectiveRole->isSuperadmin() ? [] : $effectiveRole->permissions,
            'permission_catalog_version' => PermissionCatalog::VERSION,
            'require_totp'        => $requireTotp,
            'require_mfa'         => $this->mfaPolicy->isRequired(),
            'allowed_mfa_methods' => $this->mfaPolicy->allowedMethods(),
            'session_state'       => 'active',
            'lock_after_minutes'  => $effectiveTimeout,
            'server_time'         => self::isoUtc($now),
            'idle_expires_at'     => $idleExpiresAt,
            // Stav licence (E4) pro FE bannery (trial odpočet, overage, degraded).
            'license'             => $licenseSummary,
        ]);
    }

    private static function tryParseUtc(string $time): ?\DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $time, new \DateTimeZone('UTC'));
            if ($parsed !== false) {
                return $parsed;
            }
        }
        return null;
    }

    private static function isoUtc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
