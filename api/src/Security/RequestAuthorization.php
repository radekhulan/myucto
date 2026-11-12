<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use Psr\Http\Message\ServerRequestInterface as Request;

final class RequestAuthorization
{
    private static ?PermissionCatalog $catalog = null;
    private static ?PermissionChecker $checker = null;

    /** MCP se uděluje samostatně; interní role zachovávají přístup přes správu tokenů. */
    public static function canUseMcp(Request $request, AccessLevel $minimum = AccessLevel::READ): bool
    {
        return self::allows($request, 'profile.mcp', $minimum)
            || (!self::isClientType($request) && self::allows($request, 'profile.tokens', $minimum));
    }

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
     * Je požadavek ověřený API tokenem s plným zápisem (`system_write`, #371)?
     *
     * Příznak platí jen na instanci s `api.allow_system_write_tokens` a jen pro token
     * se zápisem; o tom rozhoduje jednou AuthMiddleware
     * ({@see \MyInvoice\Service\Auth\SystemWriteTokenFeature::appliesTo()}).
     */
    public static function isSystemWriteToken(Request $request): bool
    {
        if (!self::isBearerAuth($request)) {
            return false;
        }
        $token = $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_API_TOKEN);

        return is_array($token) && ($token['system_write'] ?? false) === true;
    }

    /**
     * Je požadavek ověřený API tokenem BEZ plného zápisu? Protějšek {@see isBearerAuth()}
     * pro místa, která podle kanálu jen mění chování (zúžené tělo, přeskočení kroku):
     * token s plným zápisem (#371) se tam chová jako relace, požadavek bez způsobu
     * ověření (úloha na pozadí, vnitřní volání) jako dřív.
     */
    public static function isRestrictedBearer(Request $request): bool
    {
        return self::isBearerAuth($request) && !self::isSystemWriteToken($request);
    }

    /**
     * Smí požadavek do agend jako přihlášený uživatel ve webovém rozhraní?
     *
     * Brána omezení specifických pro API token (session-only agendy, účetní úkony,
     * zúžené tělo požadavku): přihlášená relace, nebo token s plným zápisem
     * ({@see isSystemWriteToken()}). Oprávnění uživatele (RBAC, firma) platí dál.
     *
     * ⚠️ Nepatří na přihlášení, MFA, heslo, správu tokenů a relací, licenci, správu
     * instance ani na úložiště přístupových údajů (certifikáty, podpisy, datové
     * schránky, napojení bank). Tam zůstává {@see isSessionAuth()}. Pojistky
     * uzavřeného a podaného období drží {@see mayOverridePeriodProtection()}.
     */
    public static function actsAsSession(Request $request): bool
    {
        return self::isSessionAuth($request) || self::isSystemWriteToken($request);
    }

    /**
     * Smí požadavek přebít ochranu uzavřeného nebo podaného období? Přebití
     * uzavřeného (uzavíraného, k datu zamčeného) účetního období přes `?force=1`,
     * změna částek v podaném DPH, KH, SH nebo OSS, potvrzení zásahu do zamčeného
     * data (`ack_locked`), posun zámku zpět přes chráněné období a znovuotevření
     * uzavřeného období jsou jen pro člověka v aplikaci. API token je nepřebije
     * nikdy, ani s plným zápisem a ani jako správce firmy. Role se ověřuje zvlášť.
     *
     * Odmítá se kanál tokenu, ne „všechno mimo relaci": vnitřní požadavky bez způsobu
     * ověření (úlohy na pozadí, výpočty nad rolí) se rozhodují jen podle role jako dřív.
     */
    public static function mayOverridePeriodProtection(Request $request): bool
    {
        return !self::isBearerAuth($request);
    }

    /**
     * Smí uživatel z přihlášené relace založit token s plným zápisem (#371)? Token
     * dostane jen práva uživatele, ale obejde pojistky kanálu, a o tom za firmu
     * rozhoduje její správce. Token bez vazby na firmu sahá do všech firem uživatele,
     * proto ho s plným zápisem zakládá jen superadmin. U vázaného tokenu se ptá
     * požadavek zúžený na tu firmu ({@see CrossSupplierAuthorization::scopedRequest()}).
     */
    public static function canGrantSystemWriteToken(Request $request, bool $supplierBound): bool
    {
        return self::isSessionAuth($request)
            && ($supplierBound ? self::isCompanyAdmin($request) : self::isSuperadmin($request));
    }

    /**
     * Potvrdil požadavek vědomý zásah do uzamčeného data (`?ack_locked=1`, typicky po
     * podání přiznání k DPH)? Potvrzení platí jen z přihlášené relace, token ho nemá.
     */
    public static function acknowledgesLockedPeriod(Request $request): bool
    {
        return self::mayOverridePeriodProtection($request)
            && ($request->getQueryParams()[\MyInvoice\Service\Accounting\JournalEntryDeletionRules::ACK_LOCKED_PARAM] ?? '') === '1';
    }

    /**
     * Je to syntetický požadavek úlohy na pozadí (cron, fronta), ne požadavek
     * přihlášeného člověka? Takový požadavek nevzniká z HTTP, skládá ho jen kód
     * přes {@see asBackground()}.
     */
    public static function isBackgroundAuth(Request $request): bool
    {
        return $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD)
            === \MyInvoice\Service\Auth\LoginPolicyService::BACKGROUND_AUTH_METHOD;
    }

    /** Syntetický požadavek úlohy na pozadí; protějšek {@see isBackgroundAuth()}. */
    public static function asBackground(Request $request): Request
    {
        return $request->withAttribute(
            \MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,
            \MyInvoice\Service\Auth\LoginPolicyService::BACKGROUND_AUTH_METHOD,
        );
    }

    /** Syntetický požadavek ověřený API tokenem; protějšek {@see isBearerAuth()}. */
    public static function asBearer(Request $request): Request
    {
        return $request->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'bearer');
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
     * Smí tenhle token vynuceně upravit vystavenou vydanou fakturu (`?force=1`)?
     *
     * Jen token vázaný na firmu, se zápisem a s výslovně zapnutou schopností.
     * Kontrola práva vlastníka (`invoices.edit_issued`) a přísnější zámky pro token
     * (uzavřené i podané období, přečíslování, úhrady) zůstávají na akci.
     */
    public static function tokenAllowsIssuedInvoiceEdit(Request $request): bool
    {
        if (self::isSessionAuth($request)) {
            return false;
        }
        $token = $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_API_TOKEN);

        return is_array($token)
            && !empty($token['allow_issued_invoice_edit'])
            && ($token['scope'] ?? '') === 'read_write'
            && ($token['supplier_id'] ?? null) !== null
            && (int) $token['supplier_id'] > 0;
    }

    /**
     * Firma, na kterou je požadavek pevně vázaný: API token vydaný pro jednu firmu,
     * nebo vlastní doména firmy. NULL = požadavek vázaný není.
     *
     * Přehledy napříč firmami (automatizace, přehled firem) se musí na tuhle firmu
     * zúžit samy, protože seznam firem skládají z členství uživatele a ne
     * z {@see \MyInvoice\Service\Tenant\SupplierAccessResolver}. Doména má přednost
     * stejně jako v resolveru; nesoulad tokenu a domény resolver odmítne dřív.
     */
    public static function boundSupplierId(Request $request): ?int
    {
        $domain = $request->getAttribute(\MyInvoice\Middleware\TenantDomainMiddleware::ATTR_CONTEXT);
        if ($domain instanceof \MyInvoice\Service\Tenant\TenantDomainContext && $domain->locksSupplier()) {
            return (int) $domain->supplierId;
        }
        $token = $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_API_TOKEN);
        // Poškozená vazba (0) zůstává vazbou: zúží výběr na nic, nerozšíří ho.
        if (is_array($token) && ($token['supplier_id'] ?? null) !== null) {
            return (int) $token['supplier_id'];
        }

        return null;
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
        if (InstanceAdminRoutePolicy::isTechnicalUser($user)) {
            $summary = (array) ($user['role_summary'] ?? []);
            return new EffectiveRole((int) ($user['role_id'] ?? $summary['id'] ?? 0),
                (string) ($summary['name'] ?? 'Správce instance'), (string) ($summary['type'] ?? ''),
                (bool) ($summary['is_active'] ?? false), [], (string) ($summary['system_key'] ?? ''));
        }
        $legacy = is_string($user['role'] ?? null) ? $user['role'] : '';
        if ($legacy === 'admin') {
            return new EffectiveRole(0, 'Superadmin', 'superadmin', true, [], 'superadmin');
        }
        if (!in_array($legacy, ['accountant', 'readonly', 'client'], true)) {
            return EffectiveRole::denied();
        }
        $catalog = self::$catalog ??= new PermissionCatalog();
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

    public static function isInstanceAdmin(Request $request): bool
    {
        $role = self::effectiveRole($request);
        return $role->isActive && $role->isInstanceAdmin();
    }

    public static function canManageInstance(Request $request): bool
    {
        $role = self::effectiveRole($request);
        return self::isSessionAuth($request) && $role->isActive
            && ($role->isSuperadmin() || $role->isInstanceAdmin());
    }

    /**
     * Pozvánky k propojení a odpojení identit cizích účtů. Účty spravuje jen
     * superadmin (UserAdminAction), proto i jejich identity.
     */
    public static function canManageExternalIdentities(Request $request): bool
    {
        $role = self::effectiveRole($request);
        return self::isSessionAuth($request) && $role->isActive && $role->isSuperadmin();
    }

    /**
     * Reset MFA jiného uživatele. Jen superadmin z webového přihlášení; účet
     * spravuje UserAdminAction a reset odebírá cizímu účtu faktory, proto ani
     * správce firmy, ani technický správce instance.
     */
    public static function canResetUserMfa(Request $request): bool
    {
        $role = self::effectiveRole($request);
        return self::isSessionAuth($request) && $role->isActive && $role->isSuperadmin();
    }

    /** Přehled a ukončování browserových relací patří výhradně superadminovi. */
    public static function canManageUserSessions(Request $request): bool
    {
        return self::isSessionAuth($request) && self::isSuperadmin($request);
    }

    public static function canCreateSupplier(Request $request): bool
    {
        return self::effectiveRole($request)->canCreateSupplier();
    }

    public static function isClientType(Request $request): bool
    {
        return self::effectiveRole($request)->isClientType();
    }

    /**
     * Předávání dokladů účetní (portálová fronta a požadavky účetní) rozhoduje právo
     * `documents.submit`, ne typ role: dostane ho klient i interní role, které ho
     * správce přidělí. Superadmin ho má implicitně.
     */
    public static function allowsClientDocumentPortal(Request $request, AccessLevel $minimum = AccessLevel::READ): bool
    {
        return self::allows($request, 'documents.submit', $minimum);
    }

    /**
     * Hromadné nastavení pravděpodobného výdělku (§ 355 ZP) zapisuje průměr
     * (`payroll.time.write`) i podmínky pracovního vztahu, jako oprava na kartě
     * vztahu (`payroll.employment.write`). Bez druhého práva by šlo hromadnou
     * akcí měnit podmínky, které role na kartě měnit nesmí.
     */
    public static function canSetProbableEarningInBulk(Request $request): bool
    {
        return self::allows($request, 'payroll.time.write', AccessLevel::WRITE)
            && self::allows($request, 'payroll.employment.write', AccessLevel::WRITE);
    }

    /**
     * Úprava vystavené faktury (?force=1) včetně jejího výkazu: správce firmy, nebo interní
     * role s právem `invoices.edit_issued`. Uzavřené účetní období dál přebíjí jen správce
     * ({@see \MyInvoice\Http\GuardsDocumentLock}) a skladová pojistka platí pro všechny.
     */
    public static function canEditIssuedInvoice(Request $request): bool
    {
        return self::isCompanyAdmin($request)
            || (!self::isClientType($request) && self::allows($request, 'invoices.edit_issued', AccessLevel::WRITE));
    }

    /**
     * Finanční úprava zaúčtované vystavené faktury přepíše (nebo stornuje a znovu založí)
     * zápis v deníku. Kromě práva upravit vystavenou fakturu k ní neadmin potřebuje i právo
     * zaúčtovat (`accounting.journal.post`), jinak by úpravou faktury účtoval bez něj.
     */
    public static function canRepostIssuedInvoice(Request $request): bool
    {
        return self::isCompanyAdmin($request)
            || (self::canEditIssuedInvoice($request)
                && self::allows($request, 'accounting.journal.post', AccessLevel::WRITE));
    }

    /**
     * Úzká oprava DPH zařazení přijaté faktury (nárok na odpočet, klasifikace hlavičky,
     * položek a rozpisu) i u přijatého nebo zaúčtovaného dokladu, bez storna: správce firmy,
     * nebo interní role s právem `purchase_invoices.vat_treatment` (výchozí účetní ho má).
     * Klient ho nedostane ani poškozeným řádkem matice.
     */
    public static function canChangePurchaseVatTreatment(Request $request): bool
    {
        return self::isCompanyAdmin($request)
            || (!self::isClientType($request) && self::allows($request, 'purchase_invoices.vat_treatment', AccessLevel::WRITE));
    }

    /**
     * Oprava DPH zařazení zaúčtované přijaté faktury přepíše její zápis v deníku. Neadmin
     * k ní kromě práva na opravu potřebuje i právo zaúčtovat, stejně jako u vystavené
     * faktury ({@see canRepostIssuedInvoice()}).
     */
    public static function canRepostPurchaseVatTreatment(Request $request): bool
    {
        return self::isCompanyAdmin($request)
            || (self::canChangePurchaseVatTreatment($request)
                && self::allows($request, 'accounting.journal.post', AccessLevel::WRITE));
    }

    /**
     * Částky, data a položky vystavené faktury v období, za které je podané přiznání k DPH,
     * kontrolní hlášení nebo OSS přiznání, mění jen správce firmy. Delegovaná role s právem
     * upravit vystavenou fakturu ho nepřebije: podané se opravuje opravným tvrzením.
     * Totéž platí pro změnu přijaté faktury, která se promítne do evidence DPH (vynucená
     * úprava, členění DPH položek, oprava DPH zařazení).
     */
    public static function canChangeFiledVatPeriod(Request $request): bool
    {
        return self::mayOverridePeriodProtection($request) && self::isCompanyAdmin($request);
    }

    /**
     * Ruční posun zámku účtování k datu (§ 52.14.10): kdo spravuje účetní období
     * (`accounting.periods.manage`, správci), nebo interní role s vlastním právem
     * `accounting.period_lock` (typicky hlavní účetní). Klient ho nedostane.
     */
    public static function canChangePeriodLock(Request $request): bool
    {
        return self::allows($request, 'accounting.periods.manage', AccessLevel::WRITE)
            || (!self::isClientType($request) && self::allows($request, 'accounting.period_lock', AccessLevel::WRITE));
    }

    /**
     * Posunout zámek ZPĚT přes podané období DPH nebo přes uzavřené účetní období smí jen
     * ten, kdo to směl před zavedením práva `accounting.period_lock`: správce účetních
     * období, nebo správce firmy ({@see canChangeFiledVatPeriod()}). Hlavní účetní s právem
     * jen na zámek posouvá zámek vpřed a zpět jen v nepodaném a otevřeném rozsahu.
     */
    public static function canUnlockProtectedPeriods(Request $request): bool
    {
        return self::mayOverridePeriodProtection($request)
            && (self::allows($request, 'accounting.periods.manage', AccessLevel::WRITE)
                || self::canChangeFiledVatPeriod($request));
    }

    /**
     * Formu účetního zápisu firmy (jednoduchý / složený od data) mění jen správce firmy:
     * přepíná, jak se účtují všechny zápisy od data, a spouští převod starších zápisů.
     * Delegovaná role s právem účtovat ji nemění.
     */
    public static function canChangeEntryForm(Request $request): bool
    {
        return self::isCompanyAdmin($request);
    }

    /**
     * Dokončit aktivaci podvojného účetnictví přes zbývající chyby doúčtování smí jen
     * správce firmy. Delegovaná role s právem spravovat účetní období průvodce projde,
     * ale rozhodnutí, že část historie zůstane nedoúčtovaná, za firmu nedělá.
     */
    public static function canForceAccountingActivation(Request $request): bool
    {
        return self::isCompanyAdmin($request)
            && self::allows($request, 'accounting.periods.manage', AccessLevel::WRITE);
    }

    /**
     * Pracuje požadavek s daňovými podáními jen v rozsahu archivu připravených podání?
     * Ten rozsah má klient a interní role, která má `reports.submissions` bez agendy
     * Daně a výkazy (`reports`): jen DPH, KH a SH ({@see ClientTaxSubmissionPolicy}),
     * jen z přihlášené relace a bez správy nastavení, důkazů a mazání archivu.
     * Interní role s `reports` zůstává u plné agendy výkazů a interní role bez
     * `reports.submissions` se chová jako dřív (i s legacy samotným `reports.submit`).
     */
    public static function usesClientTaxSubmissionScope(Request $request): bool
    {
        return self::roleUsesClientTaxSubmissionScope(self::effectiveRole($request));
    }

    /** Totéž rozhodnutí nad už vyřešenou rolí (PermissionMiddleware ji má dřív než request). */
    public static function roleUsesClientTaxSubmissionScope(EffectiveRole $role): bool
    {
        if ($role->isClientType()) return true;
        if ($role->isSuperadmin()) return false;
        self::$catalog ??= new PermissionCatalog();
        self::$checker ??= new PermissionChecker(self::$catalog);
        return !self::$checker->allows($role, 'reports', AccessLevel::READ)
            && self::$checker->allows($role, 'reports.submissions', AccessLevel::READ);
    }

    /** Rozsah připravených podání čte jen archiv, interní role dál používají agendu výkazů. */
    public static function canReadTaxSubmissions(Request $request): bool
    {
        return self::usesClientTaxSubmissionScope($request)
            ? self::actsAsSession($request) && self::allows($request, 'reports.submissions', AccessLevel::READ)
            : self::allows($request, 'reports', AccessLevel::READ);
    }

    /** XML a důkazy jsou součástí archivu připravených podání; interní export má vlastní právo. */
    public static function canExportTaxSubmission(Request $request): bool
    {
        return self::usesClientTaxSubmissionScope($request)
            ? self::canReadTaxSubmissions($request)
            : self::allows($request, 'reports.export', AccessLevel::READ);
    }

    /** Řádek musí volající nejprve dohledat v aktuální firmě. */
    public static function canAccessTaxSubmission(Request $request, array $submission): bool
    {
        return !self::usesClientTaxSubmissionScope($request)
            || (self::canReadTaxSubmissions($request)
                && ClientTaxSubmissionPolicy::allowsForm((string) ($submission['form_code'] ?? '')));
    }

    /** Mazání archivu zachovává původní právo interních rolí k exportu se zápisem. */
    public static function canDeleteTaxSubmission(Request $request): bool
    {
        return !self::usesClientTaxSubmissionScope($request)
            && self::allows($request, 'reports.export', AccessLevel::WRITE);
    }

    /** Správa důkazů a korespondence zůstává interním podávajícím rolím s agendou výkazů. */
    public static function canManageTaxSubmissionEvidence(Request $request): bool
    {
        return !self::usesClientTaxSubmissionScope($request)
            && self::allows($request, 'reports.submit', AccessLevel::WRITE);
    }

    /**
     * Stávající interní podávající role si zachovávají správu nastavení. Rozsah
     * připravených podání (klient i interní role bez `reports`) ji nemá ani
     * s `reports.submit`: odeslání nesmí otevřít správu kanálů a složek.
     */
    public static function canManageTaxSubmissions(Request $request): bool
    {
        return !self::usesClientTaxSubmissionScope($request)
            && (self::allows($request, 'reports.submissions.manage', AccessLevel::WRITE)
                || self::allows($request, 'reports.submit', AccessLevel::WRITE));
    }

    /**
     * Akceptace nativního výpočtu DPPO 504 odblokuje finalizaci, XML a podání. Jde o
     * rozhodnutí správce firmy, nikoli delegované účetní role.
     */
    public static function canAcceptNonprofitDppoCalculation(Request $request): bool
    {
        return self::isCompanyAdmin($request);
    }

    /**
     * Vrácení importu historie skladu smaže tisíce zaúčtovaných skladových dokladů
     * (a bez data přechodu vynuluje sklad). Rozhoduje o něm správce firmy, ne role
     * s právem zapisovat skladové doklady.
     */
    public static function canRollbackStockHistoryImport(Request $request): bool
    {
        return self::isCompanyAdmin($request);
    }

    /**
     * Správa automatického stavu tachometru (připojení účtu u zdroje, přiřazení aut, načtení):
     * patří k práci s knihou jízd, ne k administraci integrací.
     */
    public static function canManageOdometerSources(Request $request): bool
    {
        return self::allows($request, 'logbook.write', AccessLevel::WRITE);
    }

    /**
     * Rychlá účtenka doklad založí, vystaví a hned uhradí. Kromě založení faktury
     * (pravidlo route) proto vyžaduje i právo vystavit a označit zaplacené; klient
     * ji nevystaví nikdy. Právo evidovat tržbu (`eet.accept_payment`) se ověřuje
     * zvlášť, jen když firma eviduje.
     */
    public static function canIssueQuickReceipt(Request $request): bool
    {
        return !self::isClientType($request)
            && self::allows($request, 'invoices.create', AccessLevel::WRITE)
            && self::allows($request, 'invoices.issue', AccessLevel::WRITE)
            && self::allows($request, 'invoices.mark_paid', AccessLevel::WRITE);
    }

    /**
     * Založení interního dokladu (koncept). Stejné právo vyžaduje route
     * `POST /api/accounting/internal-documents` v RoutePermissionMap; AI asistent podle
     * něj rozhoduje, zda smí interní doklad vůbec navrhnout.
     */
    public static function canCreateInternalDocument(Request $request): bool
    {
        return self::allows($request, 'accounting.journal.write', AccessLevel::WRITE);
    }

    /**
     * XML pokusů o odeslání EET (podepsaná zpráva a odpověď FS) vidí jen ten, kdo
     * smí opravovat a opakovat evidenci, a jen z přihlášené relace. Bearer token
     * (mobil, integrace) ho nedostane nikdy.
     */
    public static function canViewEetAttemptXml(Request $request): bool
    {
        return self::actsAsSession($request) && self::allows($request, 'eet.correct', AccessLevel::WRITE);
    }

    /**
     * Smí čtecí požadavek (GET stažení, náhled) mimochodem zapsat? Doplnění kurzů,
     * posun zámku podání, založení konceptu nebo párování záloh dělá jen člověk
     * v aplikaci s právem zápisu. API token je pro účetní a daňovou vrstvu vždy
     * jen ke čtení, i když jeho uživatel právo zápisu má.
     */
    public static function allowsReadSideEffects(Request $request, string $permission): bool
    {
        return self::actsAsSession($request) && self::allows($request, $permission, AccessLevel::WRITE);
    }

    public static function allows(
        Request $request,
        string $permission,
        AccessLevel $minimum = AccessLevel::READ,
    ): bool {
        self::$catalog ??= new PermissionCatalog();
        self::$checker ??= new PermissionChecker(self::$catalog);
        if (in_array($permission, ['reports.submissions', 'reports.submit'], true)
            && !self::actsAsSession($request)
            && self::roleUsesClientTaxSubmissionScope(self::effectiveRole($request))
        ) {
            return false;
        }
        return self::$checker->allows(self::effectiveRole($request), $permission, $minimum);
    }
}
