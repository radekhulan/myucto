import axios from 'axios'
import { domainLoginContinuationQuery, domainLoginRequestContext } from '@/security/domainLoginContext'
import { readStorageQuotaHeaders } from '@/api/storageQuota'
import { isClientDomainAuthenticatedPath } from '@/security/clientRoutePolicy'
import { safeReturnPath } from '@/utils/returnPath'
import { getWindowSupplierScope, requestSupplierId } from '@/workspace/supplierContext'
import { postWindowFrameMessage } from '@/workspace/frameProtocol'

export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  // Strop je 180 s, protože tolik dává `max_execution_time` na IIS — nejvyšší limit
  // celého stacku (Docker/Apache má 60 s, nginx `fastcgi_read_timeout` 120 s). Za tou
  // hranicí už žádný server v téhle sestavě odpovědět nemůže, takže dřív požadavek
  // vzdát by znamenalo zahodit odpověď, která ještě mohla přijít.
  //
  // Bez timeoutu axios čekal navěky: když serverová cesta vypršela, uživatel zůstal
  // u nekonečného spinneru a neměl jak poznat, že se nic nestane. Nejblíž tomu byly
  // uzávěrkové kontroly nad velkým deníkem.
  timeout: 180_000,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})

// CSRF token interceptor — token žije v Pinia auth store
let csrfToken: string | null = null
let domainSupplierLock: number | null = null
let forbiddenPermissionHandler: (() => void | Promise<void>) | null = null
export function setCsrfToken(token: string | null) {
  csrfToken = token
}
export function setDomainSupplierLock(supplierId: number | null) {
  domainSupplierLock = supplierId && supplierId > 0 ? supplierId : null
  if (domainSupplierLock !== null && !domainLoginRequestContext() && !getWindowSupplierScope()) localStorage.removeItem('myinvoice.current_supplier_id')
}
export function setForbiddenPermissionHandler(handler: () => void | Promise<void>) {
  forbiddenPermissionHandler = handler
}

api.interceptors.request.use((config) => {
  const flow = domainLoginRequestContext()
  // Veřejný bootstrap musí fungovat i po expiraci toku, aby šel zopakovat úklid.
  const publicDomainMetadata = ['/auth/setup-status', '/auth/domain-context'].includes(config.url ?? '')
  if (flow && !publicDomainMetadata) {
    config.headers.set('X-Domain-Login-Request', flow.requestToken)
    config.headers.set('X-Domain-Login-State', flow.state)
  }
  if (csrfToken && config.method && config.method.toUpperCase() !== 'GET'
      && !config.headers.has('X-CSRF-Token')) {
    config.headers.set('X-CSRF-Token', csrfToken)
  }
  // Pošli aktuální UI locale, aby backend hlášky chodily ve správném jazyce.
  // Auth middleware ji přepíše user.locale (pokud přihlášen).
  const locale = localStorage.getItem('locale') || 'cs'
  config.headers.set('Accept-Language', locale)

  // Multi-supplier — aktuální supplier z localStorage (Pinia persist).
  // Když chybí/neplatný, server použije výchozí firmu účtu (users.default_supplier_id).
  const windowScope = getWindowSupplierScope()
  if (flow || (domainSupplierLock !== null && !windowScope)) {
    config.headers.delete('X-Supplier-Id')
  } else {
    const sid = requestSupplierId()
    if (sid && (windowScope || !config.headers.has('X-Supplier-Id'))) {
      config.headers.set('X-Supplier-Id', sid)
    }
  }
  return config
})

// 401 → redirect na /login (kromě situace kdy už jsme na /login nebo /setup)
api.interceptors.response.use(
  (response) => {
    // H-10: stav diskové kvóty jede v hlavičkách každé odpovědi, ať se admin
    // o blížícím se zámku dozví dřív, než mu přestane jít uložit doklad.
    readStorageQuotaHeaders(response.headers)
    // Zapisující požadavek ohlásí změnu dat, aby si ji zaparkovaná okna
    // pracovní plochy obnovila při návratu (`useWindowRefresh`). Okno v iframe
    // ji pošle správci oken, hlavní dokument ji vyvolá jako událost, kterou
    // správce oken rozešle stejně.
    if (!['get', 'head', 'options'].includes(response.config.method?.toLowerCase() ?? 'get')) {
      const resource = new URL(response.config.url ?? '', window.location.origin).pathname.replace(/^\/api\//, '/').split('/')[1]
      if (resource && /^[a-z0-9-]+$/.test(resource)) {
        const scope = getWindowSupplierScope()
        if (scope) postWindowFrameMessage({ type: 'myucto-window-mutation', id: scope.windowId, resource })
        else window.dispatchEvent(new CustomEvent('myucto:data-mutation', { detail: { resource } }))
      }
    }
    return response
  },
  (error) => {
    const status = error.response?.status
    const code = error.response?.data?.error?.code
    // I odmítnutý zápis (507 storage_quota_exhausted) nese stav kvóty — právě
    // u něj je nejvíc potřeba, aby banner odpovídal skutečnosti.
    //
    // `trusted: false`: chybová odpověď smí stav NASTAVIT (hlavičky v ní jsou),
    // ale její mlčení neznamená „vše v pořádku" — 401 ani 500 o kvótě nevypovídá
    // nic, takže blokující stav kvůli nim nesmí zhasnout.
    if (error.response?.headers) readStorageQuotaHeaders(error.response.headers, { trusted: false })

    if (status === 401 && [
      'unauthenticated',
      'session_expired',
      'invalid_token',
      'mfa_reauthentication_required',
    ].includes(code)) {
      const windowScope = getWindowSupplierScope()
      if (windowScope) {
        postWindowFrameMessage({ type: 'myucto-window-global', id: windowScope.windowId, to: '/login' })
        return Promise.reject(error)
      }
      const path = window.location.pathname
      if (!path.startsWith('/login') && !path.startsWith('/setup')) {
        /*
         * Adresa, na které relace vypršela, jde do přihlášení VŽDY, ne jen na
         * klientské doméně. Tudy odchází většina lidí, kteří se ocitnou na
         * přihlášení: nekliknou na odkaz bez relace, ale relace jim vyprší
         * pod rukama. Bez `return_to` je přihlášení vrátí na přehled a
         * rozdělanou obrazovku si musí najít znovu.
         *
         * Bezpečnost řeší `safeReturnPath` na straně přihlášení; tady se
         * hodnota jen předává.
         */
        const returnPath = `${window.location.pathname}${window.location.search}${window.location.hash}`
        /*
         * Na zamčené zákaznické doméně smí návratová adresa být JEN jedna
         * z auditovaných klientských rout — jinak by se přes `return_to`
         * rozšířila klientská plocha o cestu, která do ní nepatří. Mimo
         * ni stačí, že je to vlastní cesta v aplikaci.
         */
        const safe = domainSupplierLock !== null && isClientDomainAuthenticatedPath(returnPath)
          ? returnPath
          : (domainSupplierLock !== null ? '' : safeReturnPath(returnPath, ''))
        const continuation = domainLoginContinuationQuery()
        if (continuation) {
          window.location.href = `/login?${new URLSearchParams(continuation)}`
        } else {
          window.location.href = safe === ''
            ? '/login'
            : `/login?return_to=${encodeURIComponent(safe)}`
        }
      }
    }
    if (status === 423 && code === 'session_locked') {
      const frame = getWindowSupplierScope()
      if (frame) postWindowFrameMessage({ type: 'myucto-window-locked', id: frame.windowId })
      window.dispatchEvent(new CustomEvent('myinvoice:session-locked'))
    }
    if (status === 423 && code === 'setup_required') {
      const windowScope = getWindowSupplierScope()
      if (windowScope) postWindowFrameMessage({ type: 'myucto-window-global', id: windowScope.windowId, to: '/setup' })
      else window.location.href = '/setup'
    }
    // 403 totp_setup_required = require_totp=true a uživatel nemá aktivní TOTP.
    // Backend takhle blokuje všechno mimo whitelist (/me, /logout, /totp/*).
    // Frontend má router guard, ale když 403 přijde z přímého API volání
    // (např. po HMR / bez navigation), interceptor zaručí redirect.
    // /login NEVYJÍMÁME — když máš stale session a otevřeš /login, redirect
    // na /setup-totp je správný.
    if (status === 403 && code === 'totp_setup_required') {
      const windowScope = getWindowSupplierScope()
      if (windowScope) {
        postWindowFrameMessage({ type: 'myucto-window-global', id: windowScope.windowId, to: '/setup-mfa?method=totp' })
      } else if (window.location.pathname !== '/setup-mfa') {
        window.location.href = '/setup-mfa?method=totp'
      }
    }
    if (status === 403 && code === 'mfa_setup_required') {
      const windowScope = getWindowSupplierScope()
      if (windowScope) {
        postWindowFrameMessage({ type: 'myucto-window-global', id: windowScope.windowId, to: '/setup-mfa' })
      } else if (window.location.pathname !== '/setup-mfa') {
        window.location.href = '/setup-mfa'
      }
    }

    // 403 forbidden_supplier (Epic F0) = v localStorage je stale supplier, ke kterému
    // uživatel ztratil membership (admin ho odebral). Bez zásahu by selhal i /auth/me
    // (interceptor hlavičku posílá vždy) a uživatel by se do aplikace vůbec nedostal.
    // Smaž stale výběr a reloadni — server bez hlavičky fallbackne na první přiřazenou
    // firmu. Reload jen když klíč existoval → žádná smyčka.
    if (status === 403 && code === 'forbidden_supplier' && !domainLoginRequestContext()) {
      const windowScope = getWindowSupplierScope()
      if (windowScope) {
        postWindowFrameMessage({ type: 'myucto-window-error', id: windowScope.windowId, code: 'access-revoked' })
      } else if (localStorage.getItem('myinvoice.current_supplier_id') !== null) {
        localStorage.removeItem('myinvoice.current_supplier_id')
        window.location.reload()
      }
    }
    if (status === 403 && code === 'forbidden_permission' && !domainLoginRequestContext()) {
      void forbiddenPermissionHandler?.()
    }

    // Demo režim odmítá mutace globálně (DemoReadOnlyMiddleware), takže hláška
    // patří sem, ne do jednotlivých formulářů. Dokud ji uměla jen hrstka
    // obrazovek přes `blockDemoMutation()`, zůstalo všude jinde tlačítko bez
    // odezvy — návštěvník nepoznal, jestli se uložilo, nebo aplikace spadla.
    //
    // Text se překládá až v AppLayoutu, stejně jako u `session-locked` níž:
    // `createI18n` běží při importu `@/i18n`, takže tahat ho sem znamená vtáhnout
    // ho do KAŽDÉHO testu, který se dotkne API klienta — a ty vue-i18n mockují
    // částečně, takže se rozbilo 36 testových souborů hned při načtení modulu.
    if (status === 403 && code === 'demo_read_only') {
      const scope = getWindowSupplierScope()
      if (scope) postWindowFrameMessage({ type: 'myucto-window-demo-read-only', id: scope.windowId })
      window.dispatchEvent(new CustomEvent('myinvoice:demo-read-only'))
    }

    // 503 config_missing / bootstrap_failed = backend není nakonfigurovaný
    // (chybí cfg.php nebo nelze do DB). Zobrazíme fullscreen overlay s návodem,
    // ať uživatel nedostane jen prázdný login form bez vysvětlení.
    if (status === 503 && (code === 'config_missing' || code === 'bootstrap_failed')) {
      showBootstrapErrorOverlay(
        code === 'config_missing'
          ? 'Backend není nakonfigurovaný (chybí cfg.php).'
          : 'Backend selhal při startu (typicky nedostupná databáze).',
        error.response?.data?.error?.message || '',
        error.response?.data?.error?.hint || '',
      )
    }

    return Promise.reject(error)
  },
)

function showBootstrapErrorOverlay(title: string, detail: string, hint: string): void {
  if (document.getElementById('bootstrap-error-overlay')) return  // už zobrazený
  const div = document.createElement('div')
  div.id = 'bootstrap-error-overlay'
  div.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(21,19,29,0.92);' +
    'display:flex;align-items:center;justify-content:center;font:14px/1.5 system-ui,sans-serif;'
  div.innerHTML = `
    <div style="background:#fff;max-width:560px;width:90%;padding:32px;border-radius:12px;
                box-shadow:0 8px 32px rgba(0,0,0,0.3);">
      <h2 style="margin:0 0 12px;color:#3B2D83;font-size:24px;">⚠ MyÚčto.cz</h2>
      <p style="margin:0 0 16px;color:#15131D;font-weight:600;">${escapeHtml(title)}</p>
      ${detail ? `<p style="margin:0 0 12px;color:#5A5470;font-family:monospace;
        background:#F4F2F8;padding:8px 12px;border-radius:6px;font-size:13px;">${escapeHtml(detail)}</p>` : ''}
      ${hint ? `<p style="margin:0 0 16px;color:#5A5470;">💡 ${escapeHtml(hint)}</p>` : ''}
      <p style="margin:0;color:#5A5470;font-size:13px;">
        Kontaktuj administrátora a pošli mu tuhle hlášku.
        Detail v <code>log/bootstrap-error.log</code>.
      </p>
    </div>`
  document.body.appendChild(div)
}

function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
}

export type AppUrlConfigurationState =
  | 'missing'
  | 'invalid'
  | 'routing_only'
  | 'hostname_conflict'
  | 'webauthn_ready'

export type AppUrlConfigurationReason =
  | 'app_url_missing'
  | 'app_url_invalid_origin'
  | 'app_url_webauthn_incompatible'
  | 'app_url_hostname_conflict'
  | 'app_url_valid'

export interface AppUrlConfigurationStatus {
  state: AppUrlConfigurationState
  reason_code: AppUrlConfigurationReason
  routing_compatible: boolean
  webauthn_compatible: boolean
}

export interface HealthWarning {
  code: string
  message: string
  reason_code?: string
}

export interface HealthResponse {
  status: 'ok'
  version: string
  db: boolean
  redis: boolean
  configuration: { app_url: AppUrlConfigurationStatus }
  warnings?: HealthWarning[]
  time: string
}

export const systemApi = {
  health: (signal?: AbortSignal) =>
    api.get<HealthResponse>('/health', { signal }).then((r) => r.data),
}
