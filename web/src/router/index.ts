import {
  createRouter,
  createWebHistory,
  type RouteLocationNormalized,
  type RouteLocationRaw,
  type RouteRecordRaw,
} from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { DomainContext } from '@/api/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import type { AccessLevel, PermissionKey } from '@/security/permissions'
import { ensureNamespaces, namespacesForRoute } from '@/i18n'
import { createWorkspaceRoutes } from './workspaceRoutes'
import {
  clientDomainCanonicalHandoffPath,
  clientDomainRedirect,
  isClientDomainAuthenticatedPath,
  isClientDomainRouteName,
} from '@/security/clientRoutePolicy'

declare module 'vue-router' {
  interface RouteMeta {
    permission?: PermissionKey
    access?: AccessLevel
    superadminOnly?: boolean
    requiresSupplier?: boolean
    requiresDoubleEntry?: boolean
    requiresTaxEvidence?: boolean
    requiresCashMode?: boolean
    requiresStock?: boolean
    requiresPayroll?: boolean
    commercialOnly?: boolean
    requiresNoStock?: boolean
    requiresOss?: boolean
    requiresAuth?: boolean
    public?: boolean
    totpSetupOnly?: boolean
    mfaSetupOnly?: boolean
  }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('@/components/layout/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: createWorkspaceRoutes(),
  },
  { path: '/login',  name: 'login',  component: () => import('@/pages/Login.vue'),          meta: { public: true } },
  { path: '/domain-login/callback', name: 'domain-login-callback', component: () => import('@/pages/DomainLoginCallback.vue'), meta: { public: true } },
  { path: '/setup',  name: 'setup',  component: () => import('@/pages/Setup.vue'),          meta: { public: true } },
  { path: '/setup-mfa', name: 'setup-mfa', component: () => import('@/pages/ForcedMfaSetup.vue'), meta: { requiresAuth: true, mfaSetupOnly: true } },
  { path: '/setup-totp', name: 'setup-totp', redirect: { path: '/setup-mfa', query: { method: 'totp' } } },
  { path: '/forgot', name: 'forgot', component: () => import('@/pages/ForgotPassword.vue'), meta: { public: true } },
  { path: '/reset',  name: 'reset',  component: () => import('@/pages/ResetPassword.vue'),  meta: { public: true } },
  { path: '/approval/:token([a-f0-9]{32,128})', name: 'approval',
    component: () => import('@/pages/ApprovalPublic.vue'), meta: { public: true } },
  { path: '/work-report/:token([a-f0-9]{32,128})', name: 'work-report-tracking',
    component: () => import('@/pages/WorkReportTrackingPublic.vue'), meta: { public: true } },
  // Web faktura — veřejný náhled vystavené faktury (singular /invoice/…, interní UI je /invoices/…)
  { path: '/invoice/:token([a-f0-9]{32,128})', name: 'invoice-public',
    component: () => import('@/pages/InvoicePublic.vue'), meta: { public: true } },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/pages/NotFound.vue'),
  },
]

const routePermissions: Record<string, [PermissionKey, AccessLevel?]> = {
  home: ['dashboard'], portal: ['profile'], 'portal-document-requests': ['documents.submit'],
  'portal-purchase-invoice-submissions': ['documents.submit'],
  clients: ['clients'], 'client-new': ['clients.create', 'write'], 'client-detail': ['clients'], 'client-edit': ['clients', 'write'],
  projects: ['projects'], 'project-new': ['projects.create', 'write'], 'project-detail': ['projects'], 'project-edit': ['projects', 'write'],
  invoices: ['invoices'], 'invoice-new': ['invoices.create', 'write'], 'invoice-detail': ['invoices'], 'invoice-edit': ['invoices', 'write'],
  // AI import vydané faktury jede na invoices.create (write) — stejný klíč kontroluje BE AiExtractPdfIssuedAction.
  'invoice-ai-import': ['invoices.create', 'write'],
  // Export/Import vydaných (reorg UX 2026-07) — nav pod Prodej, viz AppLayout.vue.
  'invoices-export': ['invoices'], 'invoices-import': ['invoices'],
  'purchase-invoices': ['purchase_invoices'], 'purchase-invoices-payment-orders': ['purchase_invoices.payment_orders'],
  'purchase-invoice-submissions': ['documents.inbox'],
  'purchase-invoice-new': ['purchase_invoices.create', 'write'], 'purchase-invoice-detail': ['purchase_invoices'], 'purchase-invoice-edit': ['purchase_invoices', 'write'],
  // Export/Import přijatých (reorg UX 2026-07) — nav pod Nákup, viz AppLayout.vue.
  'purchase-invoices-export': ['purchase_invoices'], 'purchase-invoices-import': ['purchase_invoices'],
  // AI import jede na purchase_invoices.scan (write) — stejný klíč kontroluje BE
  // AiExtractPdfAction; readonly/client roli položka nesvítí a route ji nepustí.
  'purchase-invoice-ai-import': ['purchase_invoices.scan', 'write'],
  documents: ['documents'], 'document-detail': ['documents'], 'document-requests': ['documents.requests'],
  'accounting-accounts': ['accounting'], 'accounting-account-detail': ['accounting'],
  'accounting-journal': ['accounting'], 'accounting-journal-new': ['accounting.journal.write', 'write'],
  // Čtení = zobrazení rozpadu mzdy; samotné zaúčtování hlídá server (accounting.journal.post).
  // Bez záznamu v téhle mapě guard route zahodí na homepage (deny-by-default, :327).
  'accounting-payroll': ['accounting'],
  'payroll-dashboard': ['payroll'],
  'payroll-runs': ['payroll'],
  'payroll-payments': ['payroll.payments'],
  'payroll-posting-reconciliation': ['payroll.post'],
  'payroll-people': ['payroll'],
  'payroll-quick-inputs': ['payroll'],
  'payroll-components': ['payroll'],
  'payroll-benefit-baskets': ['payroll'],
  'payroll-time': ['payroll'],
  'payroll-absences': ['payroll'],
  'payroll-travel': ['payroll'],
  'payroll-deduction-agreements': ['payroll'],
  'payroll-enforcement': ['payroll.enforcement'],
  'payroll-documents': ['payroll.documents'],
  'payroll-annual-settlement': ['payroll.documents'],
  'payroll-submissions': ['payroll.submissions'],
  'payroll-settings': ['payroll.settings'],
  'payroll-rulesets': ['payroll.rulesets'],
  'payroll-retention': ['payroll.retention'],
  'payroll-erasure': ['payroll.erasure'],
  'accounting-general-ledger': ['accounting'], 'accounting-trial-balance': ['accounting'], 'accounting-account-statement': ['accounting'],
  'accounting-balance-sheet': ['accounting'], 'accounting-income-statement': ['accounting'], 'accounting-income-statement-by-function': ['accounting'], 'accounting-saldo': ['accounting'],
  'accounting-document-completeness': ['accounting'],
  'accounting-balance-inventory': ['accounting'],
  'accounting-section18-statements': ['accounting'],
  'accounting-periods': ['accounting'],
  'accounting-monthly-check': ['accounting'], 'accounting-monthly-report': ['accounting'], 'accounting-offsets': ['accounting.offsets'],
  'accounting-tax-base-adjustments': ['accounting'],
  'manual-posting-queue': ['accounting'],
  'accounting-assets': ['assets'], 'accounting-asset-new': ['assets.write', 'write'], 'accounting-asset-detail': ['assets'], 'accounting-asset-edit': ['assets.write', 'write'],
  // Drobný majetek jede na `accounting`, ne na `assets` — musí sedět na RoutePermissionMap
  // na BE, kde /api/accounting/small-assets spadá pod fallback `accounting` (negativní
  // lookahead vylučuje jen `assets`, ne `small-assets`). Jiný klíč tady = menu svítí,
  // ale API vrátí 403.
  'accounting-small-assets': ['accounting'],
  'accounting-period-closing': ['accounting.periods.close'], 'accounting-closing-package': ['reports.export'], 'accounting-statement-notes': ['accounting'], 'accounting-retention': ['accounting'], 'accounting-transition-report': ['tax_evidence'], 'accounting-cash': ['cash'], 'accounting-cash-new': ['cash.document.write', 'write'], 'accounting-cash-edit': ['cash.document.write', 'write'], 'accounting-cash-book': ['cash'],
  'tax-evidence-cash-journal': ['tax_evidence'], 'tax-evidence-receivables-payables': ['tax_evidence'],
  'stock-items': ['stock'], 'stock-item-new': ['stock.items.write', 'write'], 'stock-item-detail': ['stock'], 'stock-item-edit': ['stock.items.write', 'write'],
  'stock-documents': ['stock'], 'stock-document-new': ['stock.documents.write', 'write'], 'stock-document-detail': ['stock'],
  'stock-purchase-orders': ['stock'], 'stock-purchase-order-new': ['stock.orders.write', 'write'], 'stock-purchase-order-detail': ['stock'],
  'stock-replenishment': ['stock'],
  'stock-warehouses': ['stock'], 'stock-vendor-offers': ['stock'],
  'stock-takes': ['stock'], 'stock-take-detail': ['stock'], 'stock-reports': ['stock'], eshop: ['eshop'],
  logbook: ['logbook'], stats: ['dashboard'], 'purchase-stats': ['dashboard'], 'bank-statements': ['bank'], 'bank-detail': ['bank'],
  'admin-electronic-signatures': ['settings.signing', 'write'], 'admin-databox': ['settings.signing', 'write'], templates: ['accounting.templates'], tools: ['utilities'], 'crm-dashboard': ['dashboard.portfolio'], 'portfolio-overview': ['dashboard.portfolio'],
  'automation-cockpit': ['accounting'],
  'admin-settings': ['settings.company.write', 'write'], 'admin-branding': ['settings.branding', 'write'], 'admin-integrations': ['settings.company.write', 'write'],
  'admin-price-list': ['settings.company.write', 'write'], 'admin-price-list-new': ['settings.company.write', 'write'], 'admin-price-list-edit': ['settings.company.write', 'write'],
  'accounting-activation': ['accounting.periods.manage', 'write'],
  'reports-dph': ['reports'], 'reports-kh': ['reports'], 'reports-dph-book': ['reports'], 'reports-s74b': ['reports'], 'reports-related-parties': ['reports'], 'reports-vat-coefficient': ['reports'], 'reports-s46': ['reports'], 'reports-vat-corrections': ['reports'], 'reports-shv': ['reports'], 'reports-oss': ['reports'],
  'reports-income-tax': ['reports'], 'reports-cnb-rate-audit': ['reports'], 'reports-invoice-series-completeness': ['reports'], 'reports-submissions': ['reports'], 'reports-monthly-export': ['reports.export'], 'tax-optimizer': ['reports'], recurring: ['recurring'], 'recurring-new': ['recurring.create', 'write'],
  'recurring-detail': ['recurring'], 'recurring-edit': ['recurring', 'write'], 'profile-api-tokens': ['profile.tokens'], 'profile-mcp-server': ['profile.tokens'], 'profile-shortcuts': ['profile', 'write'],
}

const superadminRouteNames = new Set([
  'activity-log', 'sent-emails', 'cron-jobs', 'admin-users', 'admin-roles', 'admin-suppliers',
  'admin-codebooks', 'admin-tax-constants', 'admin-bank-rule-templates', 'admin-email-templates', 'admin-emails', 'admin-approvals', 'admin-update',
  'admin-isds-gateway',
  'admin-price-list', 'admin-price-list-new', 'admin-price-list-edit',
  'activation-license', 'activation-terms', 'activation-purchase',
  'admin-diagnostics', 'admin-support',
  // Obojí čte administrátorské endpointy (/api/admin/*, /api/license/status),
  // takže sem patří ze stejného důvodu jako aktivace a diagnostika:
  // deny-by-default guard by je jinak tiše přesměroval na homepage.
  'admin-instance-export', 'hosting',
])

// Routy, které projdou deny-by-default guardem (:361) bez permission meta jinak než
// přes routePermissions — musí být zrcadleny se `selfServiceRoute` v beforeEach.
//
// Vynucené nastavení MFA (`meta.mfaSetupOnly`) tady ZÁMĚRNĚ není jménem: výjimku
// nese meta, ne seznam, aby druhá taková routa nezopakovala smyčku
// home → setup-mfa → home (#5). Deny-by-default to neoslabuje — routa je pořád za
// `requiresAuth` a `mfaSetupOnly` je sama gate: koho MFA nečeká, toho guard níž
// pošle z té stránky pryč.
const selfServiceRouteNames = new Set(['profile-password', 'setup-totp', 'isds-gateway-callback'])
const demoCreateRouteNames = new Set(['invoice-new', 'purchase-invoice-new', 'client-new', 'accounting-journal-new'])
const demoReadOnlyRouteNames = new Set(['admin-settings', 'admin-branding', 'admin-codebooks', 'admin-tax-constants'])
const commercialOnlyRouteNames = new Set([
  'accounting-activation',
  'automation-cockpit',
  'portfolio-overview',
  'reports-s74b',
  'reports-related-parties',
  'reports-vat-corrections',
  'templates',
  'tools',
])

export function applyAuthorizationMeta(records: RouteRecordRaw[], inheritedRequiresAuth = false): void {
  for (const record of records) {
    const name = typeof record.name === 'string' ? record.name : ''
    const requiresAuth = inheritedRequiresAuth || !!record.meta?.requiresAuth
    if (superadminRouteNames.has(name)) record.meta = { ...record.meta, superadminOnly: true }
    if (record.meta?.requiresDoubleEntry || record.meta?.requiresStock || commercialOnlyRouteNames.has(name)) {
      record.meta = { ...record.meta, commercialOnly: true }
    }
    const rule = routePermissions[name]
    if (rule) {
      const mayRenderWithoutSupplier = rule[0] === 'dashboard' || rule[0] === 'profile' || rule[0] === 'profile.tokens'
      record.meta = { ...record.meta, permission: rule[0], access: rule[1] ?? 'read', requiresSupplier: !mayRenderWithoutSupplier }
    }
    // Dev-time pojistka proti P1.5b (5b v REAL_data_followup_UX.md): route bez záznamu
    // v routePermissions projde deny-by-default guardem tiše na homepage — bez chyby,
    // bez logu. Upozorni na to hned při startu, ne až po hodině hledání v produkci.
    if (import.meta.env.DEV && name && requiresAuth && !rule
      && !superadminRouteNames.has(name) && !selfServiceRouteNames.has(name)
      && !record.meta?.mfaSetupOnly
      && !record.meta?.public && !record.redirect) {
      console.warn(`[router] Route "${name}" nemá záznam v routePermissions ani superadminOnly/self-service výjimku — deny-by-default guard ji bude tiše přesměrovávat na homepage/portal.`)
    }
    if (record.children) applyAuthorizationMeta(record.children, requiresAuth)
  }
}
applyAuthorizationMeta(routes)

export const router = createRouter({
  history: createWebHistory(),
  routes,
  // Scroll-to-top při navigaci sidebar linky; respektuj #hash a back/forward
  scrollBehavior(_to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (_to.hash) return { el: _to.hash, behavior: 'smooth' }
    return { top: 0, left: 0 }
  },
})

/**
 * Bezpečný fallback při zamítnutí. Vrací `true` (pusť dál), když už cílíme na tu
 * samou route — jinak vznikne NEKONEČNÁ smyčka.
 *
 * Reálně nastalo: ne-superadmin bez řádku v `user_suppliers` je na backendu
 * fail-closed (SupplierAccessResolver → denied), takže `/me` vrátí PRÁZDNÁ práva.
 * Tím propadne i `dashboard` na route `home`, guard přesměruje na `home`, ta se
 * zamítne znovu… a záložka zamrzne bez jediné hlášky. Radši pustit dál a nechat
 * stránku zobrazit prázdný stav / chybu z API než točit prohlížeč donekonečna.
 */
function denyFallback(toName: unknown, auth: ReturnType<typeof useAuthStore>) {
  const target = auth.isClientRole ? 'portal' : 'home'
  return toName === target ? true : { name: target }
}

/**
 * Pojistka proti zacyklení `/` ↔ `/login`.
 *
 * Guard i `/login` čtou `auth.isAuthenticated`, takže samy o sobě smyčku neudělají.
 * Vyrobí ji ale nekonzistentní odpověď serveru: když `/api/auth/me` vrací střídavě
 * 200 a 401 (cizí service worker na recyklovaném originu jako `localhost:8080`,
 * cachující proxy), guard nás pošle na `/login`, ten se úspěšně obnoví a pošle nás
 * zpátky — donekonečna. V nainstalované PWA je to nejhorší: okno nemá adresní řádek,
 * takže se z toho nedá vyklikat ani ručně přejít jinam.
 *
 * `sessionStorage` (ne modulová proměnná) proto, že část těch odrazů jde přes tvrdý
 * `window.location` redirect z api/client.ts, který by JS stav zahodil.
 */
const BOUNCE_KEY = 'myucto.login_bounces'
const BOUNCE_WINDOW_MS = 10_000
const BOUNCE_LIMIT = 3

function readBounces(): number[] {
  try {
    const raw = JSON.parse(sessionStorage.getItem(BOUNCE_KEY) || '[]')
    return Array.isArray(raw) ? raw.filter((t): t is number => typeof t === 'number') : []
  } catch {
    return []
  }
}

function recentBounces(): number[] {
  const now = Date.now()
  return readBounces().filter((t) => now - t < BOUNCE_WINDOW_MS)
}

function recordLoginBounce(): void {
  const recent = recentBounces()
  recent.push(Date.now())
  try {
    sessionStorage.setItem(BOUNCE_KEY, JSON.stringify(recent))
  } catch {
    // Private mode / zaplněné úložiště — pojistka je nice-to-have, ne blokující.
  }
}

/** `/login` se podle tohohle rozhodne, že se NEMÁ automaticky vracet na `/`. */
export function loginRedirectLoopDetected(): boolean {
  return recentBounces().length >= BOUNCE_LIMIT
}

export function clearLoginBounces(): void {
  try {
    sessionStorage.removeItem(BOUNCE_KEY)
  } catch {
    // viz výše
  }
}

export async function authorizationGuard(
  to: RouteLocationNormalized,
  from?: RouteLocationNormalized,
  options: {
    allowGlobalSideEffects?: boolean
    onGlobalNavigation?: (to: RouteLocationRaw) => void
  } = {},
): Promise<boolean | RouteLocationRaw> {
  const auth = useAuthStore()

  if (auth.setupStatus === null) {
    try {
      await auth.fetchSetupStatus()
    } catch {
      // ignore
    }
  }

  if (auth.needsSetup && to.name !== 'setup') {
    return { name: 'setup' }
  }
  if (!auth.needsSetup && to.name === 'setup') {
    return { name: 'login' }
  }

  const requiresAuth = to.matched.some((r) => r.meta.requiresAuth)
  if (requiresAuth && auth.domainContext?.locked) {
    const redirect = clientDomainRedirect(to.name)
    if (redirect !== null) return { path: redirect }

    if (clientDomainCanonicalHandoffPath(to.fullPath) !== null) {
      const returnPath = from
        && isClientDomainAuthenticatedPath(from.fullPath)
        && clientDomainCanonicalHandoffPath(from.fullPath) === null
        ? from.fullPath
        : '/portal'
      return {
        name: 'login',
        query: {
          return_to: returnPath,
          domain_handoff: to.fullPath,
        },
      }
    }

    const canonicalUrl = canonicalInternalUrl(to, auth.domainContext)
    if (canonicalUrl !== null) {
      if (options.allowGlobalSideEffects !== false) window.location.replace(canonicalUrl)
      else options.onGlobalNavigation?.(canonicalUrl)
      return false
    }
  }
  if (requiresAuth && !auth.isAuthenticated) {
    // Rozhoduje stav storu, ne návratová hodnota: refresh() při síťovém výpadku
    // vrací false, ale známou identitu si záměrně drží.
    await auth.refresh()
    if (!auth.isAuthenticated) {
      recordLoginBounce()
      return auth.domainContext?.locked && isClientDomainAuthenticatedPath(to.fullPath)
        ? { name: 'login', query: { return_to: to.fullPath } }
        : { name: 'login' }
    }
  }

  // Klientská role se z canonical kořene posílá na svůj přehled. Vlastní doména
  // řeší stejný vstup výše přes sdílenou route policy i pro staff náhled klienta.
  if (auth.isClientRole && to.name === 'home') {
    return { name: 'portal' }
  }
  if (requiresAuth && auth.lockedSession) {
    useSessionSecurityStore().apply(auth.lockedSession)
    return true
  }
  if (requiresAuth) {
    const sessionSecurity = useSessionSecurityStore()
    if (sessionSecurity.state === null) {
      await sessionSecurity.refresh()
    }
  }

  // Setup session nemá přístup k business routám, dokud uživatel nedokončí MFA.
  // Cíl se pozná podle `meta.mfaSetupOnly`, ne podle jména: jméno by muselo být
  // udržované na třech místech (redirect sem, výjimka z deny-by-default, odchod
  // pryč) a právě jejich rozejití vyrobilo smyčku home → setup-mfa → home (#5).
  const mfaSetupRoute = to.matched.some((r) => r.meta.mfaSetupOnly)
  const mustSetupMfa = auth.mustSetupMfa || auth.mustSetupTotp
  if (auth.isAuthenticated && mustSetupMfa && !mfaSetupRoute && requiresAuth) {
    return { name: 'setup-mfa' }
  }
  // Dobrovolná nabídka (`should_offer_mfa`) stránku POUZE otevírá — sem uživatele
  // posílá jen Login/ResetPassword po přihlášení, guard nikdy. Kdyby nabídka
  // uměla i přesměrovat dovnitř jako `must_setup_mfa`, byla by z ní vynucená MFA
  // bez politiky a odmítnutí by muselo dorazit dřív než další navigace — přesně
  // ta závislost vyrobila smyčku home → setup-mfa → home (#5).
  if (auth.isAuthenticated && !mustSetupMfa && !auth.shouldOfferMfa && mfaSetupRoute) {
    return { name: 'home' }
  }

  const superadminOnly = to.matched.some((r) => r.meta.superadminOnly)
  if (superadminOnly && !auth.isSuperadmin) {
    const demoReadOnlyRoute = auth.isDemo && typeof to.name === 'string' && demoReadOnlyRouteNames.has(to.name)
    if (!demoReadOnlyRoute) return denyFallback(to.name, auth)
  }

  const permissionMeta = [...to.matched].reverse().find(r => r.meta.permission)?.meta
  if (permissionMeta?.permission && !auth.can(permissionMeta.permission, permissionMeta.access ?? 'read')) {
    const demoCreateRoute = auth.isDemo && typeof to.name === 'string' && demoCreateRouteNames.has(to.name)
    const demoReadOnlyRoute = auth.isDemo && typeof to.name === 'string' && demoReadOnlyRouteNames.has(to.name)
    if (!demoCreateRoute && !demoReadOnlyRoute) return denyFallback(to.name, auth)
  }
  const selfServiceRoute = mfaSetupRoute
    || (typeof to.name === 'string' && selfServiceRouteNames.has(to.name))
  if (requiresAuth && !permissionMeta?.permission && !superadminOnly && !selfServiceRoute) {
    return denyFallback(to.name, auth)
  }

  const commercialOnly = to.matched.some((r) => r.meta.commercialOnly)
  if (commercialOnly && !auth.hasCommercialFeatures) {
    return auth.isSuperadmin ? { name: 'activation-license' } : denyFallback(to.name, auth)
  }

  // Onboarding gate: pokud uživatel v úvodním nastavení přeskočil dodavatele, nemá v DB
  // žádného supplier-a. Data (klienti, faktury, currencies) jsou supplier-scoped, takže
  // zakládací formuláře by jinak spadly na matoucí „Validace selhala" (#151). Místo toho
  // ho pošleme na dashboard, kde se zobrazí výzva k vytvoření prvního dodavatele.
  // Klient bez membershipu (žádná firma) končí na /portal s empty state
  // „kontaktujte svou účetní" — NE na dashboardu s výzvou „vytvořte dodavatele".
  const requiresSupplier = to.matched.some((r) => r.meta.requiresSupplier)
  if (requiresSupplier && auth.isAuthenticated && !useSupplierStore().hasSupplier) {
    return denyFallback(to.name, auth)
  }

  const requiresOss = to.matched.some((r) => r.meta.requiresOss)
  if (requiresOss && auth.isAuthenticated && useSupplierStore().currentSupplier?.oss_enabled !== true) {
    return { name: 'home' }
  }

  // Účetnictví (Epic F1) je dostupné jen firmám v režimu podvojného účetnictví.
  // Nav sekci gatuje AppLayout; tady tvrdě blokujeme i přímý přístup přes URL.
  const requiresDoubleEntry = to.matched.some((r) => r.meta.requiresDoubleEntry)
  if (requiresDoubleEntry && useSupplierStore().currentSupplier?.accounting_mode !== 'double_entry') {
    return { name: 'home' }
  }

  // Daňová evidence (Epic DE) je dostupná jen firmám v režimu daňové evidence.
  // Nav sekci gatuje AppLayout; tady tvrdě blokujeme i přímý přístup přes URL (zrcadlo double_entry).
  const requiresTaxEvidence = to.matched.some((r) => r.meta.requiresTaxEvidence)
  if (requiresTaxEvidence && useSupplierStore().currentSupplier?.accounting_mode !== 'tax_evidence') {
    return { name: 'home' }
  }

  // Pokladna (Epic DE §6) je dostupná v OBOU účetních režimech (double_entry i tax_evidence).
  // Nav položku gatuje AppLayout; tady jen zajistíme, že firma má některý z účetních režimů.
  const requiresCashMode = to.matched.some((r) => r.meta.requiresCashMode)
  if (requiresCashMode) {
    const mode = useSupplierStore().currentSupplier?.accounting_mode
    if (mode !== 'double_entry' && mode !== 'tax_evidence') {
      return { name: 'home' }
    }
  }

  // Sklad (Epic SKLAD) je dostupný jen firmám s zapnutou skladovou evidencí.
  // Nav sekci gatuje AppLayout; tady tvrdě blokujeme i přímý přístup přes URL.
  const requiresStock = to.matched.some((r) => r.meta.requiresStock)
  if (requiresStock && !useSupplierStore().currentSupplier?.stock_enabled) {
    return { name: 'home' }
  }

  const requiresPayroll = to.matched.some((r) => r.meta.requiresPayroll)
  if (requiresPayroll && !useSupplierStore().currentSupplier?.payroll_enabled) {
    return { name: 'home' }
  }

  // Upstreamový ceník je fallback pro firmy bez našeho skladu/e-shopu. Jakmile je
  // stock_enabled aktivní, zdrojem položek jsou výhradně skladové karty.
  const requiresNoStock = to.matched.some((r) => r.meta.requiresNoStock)
  if (requiresNoStock && useSupplierStore().currentSupplier?.stock_enabled) {
    return { name: 'home' }
  }

  return true
}

/** Vrátí bezpečný canonical cíl jen pro routy mimo sdílenou klientskou plochu. */
export function canonicalInternalUrl(
  to: RouteLocationNormalized,
  context: DomainContext | null,
): string | null {
  if (!context?.locked || isClientDomainRouteName(to.name)) return null
  if (!context.canonical_base_url || !to.fullPath.startsWith('/') || to.fullPath.startsWith('//')) return null
  try {
    const origin = new URL(context.canonical_base_url).origin
    return new URL(to.fullPath, `${origin}/`).toString()
  } catch {
    return null
  }
}

router.beforeEach((to, from) => authorizationGuard(to, from))

/**
 * Dotáhne překlady, které daná routa potřebuje nad rámec jádra (viz i18n/index.ts).
 *
 * `beforeResolve`, ne `beforeEach`: až tady jsou vyřešená všechna přesměrování
 * i líné komponenty, takže se nenačítají překlady pro stránku, na kterou se
 * nakonec vůbec nedostaneme. Navigace na výsledek čeká — bez toho by se stránka
 * na okamžik vykreslila se syrovými klíči.
 *
 * Selhání načtení nesmí zablokovat navigaci: bez překladu je stránka ošklivá,
 * bez navigace je aplikace rozbitá. Chybějící klíče stejně spadnou na fallback.
 */
router.beforeResolve(async (to) => {
  const needed = namespacesForRoute(to.name as string | undefined)
  if (needed.length > 0) {
    try {
      await ensureNamespaces(needed)
    } catch {
      // viz komentář výš — navigaci pouštíme dál
    }
  }
  return true
})
