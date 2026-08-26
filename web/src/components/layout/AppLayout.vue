<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { RouterView, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useAutomationStore } from '@/stores/automation'
import { updateApi, type PublicVersion } from '@/api/update'
import { settingsApi } from '@/api/settings'
import { CHROME_FILLED_PRIMARY } from '@/components/ui/buttonStyles'
import { ensurePrefsLoaded } from '@/composables/useUserPrefs'
import { useNavOrder } from '@/composables/useNavOrder'
import SupplierSwitcher from './SupplierSwitcher.vue'
import GlobalSearch from './GlobalSearch.vue'
import CommandPalette from './CommandPalette.vue'
import FooterTip from './FooterTip.vue'
import ThemeToggle from './ThemeToggle.vue'
import LanguageToggle from './LanguageToggle.vue'
import DesktopMenuBar from './DesktopMenuBar.vue'
import StorageQuotaBanner from './StorageQuotaBanner.vue'
import InstanceCriticalBar from './InstanceCriticalBar.vue'
import InstancePreviewBar from './InstancePreviewBar.vue'
import { instanceStatus } from '@/api/instanceStatus'
import { hostingNavAttention, resolveHostingActions } from '@/api/hostingActions'
import WorkspaceHost from '@/components/workspace/WorkspaceHost.vue'
import WorkspaceLayoutToggle from '@/components/workspace/WorkspaceLayoutToggle.vue'
import WorkspaceNavLink from '@/components/workspace/WorkspaceNavLink.vue'
import PaneActivityScope from '@/components/workspace/PaneActivityScope.vue'
import type { PermissionKey } from '@/security/permissions'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { useToast } from '@/composables/useToast'
import { formatShortcut, useKeyboardShortcuts, type ShortcutAction } from '@/composables/useKeyboardShortcuts'
import { usesClientNavigation } from '@/security/clientRoutePolicy'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useWorkspaceStore } from '@/stores/workspace'
import { manualChapter } from '@/config/manualChapters'

const { t, locale } = useI18n()

const router = useRouter()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const automationStore = useAutomationStore()
const sessionSecurity = useSessionSecurityStore()
const toast = useToast()
const keyboardShortcuts = useKeyboardShortcuts()
const workspace = useWorkspaceStore()
const workspaceNavigation = useWorkspaceNavigation()
const activeRoute = computed(() => router.resolve(workspace.activeFullPath))
const clientExperience = computed(() => usesClientNavigation(auth.isClientRole, auth.domainContext))
const desktopSearchRef = ref<InstanceType<typeof GlobalSearch> | null>(null)
const mobileSearchRef = ref<InstanceType<typeof GlobalSearch> | null>(null)

const mobileOpen = ref(false)
const quickOpen = ref(false)
const userMenuOpen = ref(false)
const supportOpen = ref(false)
const featureOpen = ref(false)
const accountantSigningProfilesEnabled = ref(false)
const logoutBusy = ref(false)
const canLockSession = computed(() => sessionSecurity.state?.session_state === 'active'
  && sessionSecurity.state.unlock_methods.includes('passkey'))
let signingSettingsRequest = 0

/*
 * Podpora v patičce vede na vnitřní rozcestník /admin/support — tam je popsané,
 * co je zdarma a co placené, a teprve odtud se jde na portál (identifikovaně,
 * přes licenční klíč). Tlačítko je plná primární akce, jen v kompaktní výšce
 * lišty — proto vlastní geometrie a barvy z `CHROME_FILLED_PRIMARY`
 * (varianta pro `.nav-inverted`), ne celé `btnFilled()` s h-9.
 */
const supportBtnClass =
  'cursor-pointer inline-flex items-center gap-1 rounded-md px-2 h-6 text-[11px] font-medium ' +
  'whitespace-nowrap transition-all duration-150 active:translate-y-px ' + CHROME_FILLED_PRIMARY

async function logout() {
  if (logoutBusy.value) return
  logoutBusy.value = true
  try {
    await auth.logout()
    sessionSecurity.clear()
    mobileOpen.value = false
    await router.replace('/login')
  } catch {
    sessionSecurity.markLocked()
    sessionSecurity.error = 'logout_failed'
    toast.error(t('auth.logout_failed'))
  } finally {
    logoutBusy.value = false
  }
}

async function loadAccountantSigningMenu() {
  const requestId = ++signingSettingsRequest
  if (clientExperience.value || !auth.canRead('settings.signing')) {
    accountantSigningProfilesEnabled.value = false
    return
  }

  try {
    const settings = await settingsApi.getSigningSettings()
    if (requestId === signingSettingsRequest) {
      accountantSigningProfilesEnabled.value = settings.accountant_profiles_enabled === true
    }
  } catch {
    if (requestId === signingSettingsRequest) {
      accountantSigningProfilesEnabled.value = false
    }
  }
}

watch(
  () => [auth.user?.role?.id, supplierStore.currentSupplierId, auth.domainContext?.locked] as const,
  () => { void loadAccountantSigningMenu() },
  { immediate: true },
)

interface NavItem {
  to: string
  label: string
  icon: string
  /** True = externí odkaz (otevře se v novém tabu, ne RouterLink). Např. /manual. */
  external?: boolean
  /** Cílová route pro rychlé „+" (vytvořit nový) vpravo u položky. Jen pro zapisující. */
  newTo?: string
  permission?: PermissionKey
  newPermission?: PermissionKey
  badge?: number
  dividerBefore?: boolean
  /**
   * Barevné odlišení JEDNÉ položky uvnitř sekce — pro položku, která do sekce
   * patří, ale nemá s ní splynout (Hosting v Systému: je to placená služba, ne
   * další nastavení). Jantarová odlišuje; červená je vyhrazená stavu, kdy je
   * něco potřeba řešit — na to je {@link NavItem.attention}.
   */
  accent?: NavSection['accent']
  /**
   * Tečka „něco je k řešení". Bere se ze {@link resolveHostingActions}, takže
   * říká totéž co dashboard. `undefined` = klid.
   */
  attention?: 'danger' | 'warning' | null
}
interface NavSection {
  /** Stabilní jazykově-nezávislý klíč sekce — identita pro §10 nav.order. */
  key: string
  /** Hlavička sekce; pokud chybí, položky jsou bez visual grouping */
  title?: string
  /** Color accent pro vertikální pruh + text. Tailwind utility class group. */
  accent?: 'primary' | 'primaryDeep' | 'warning' | 'success' | 'danger' | 'neutral' | 'accent' | 'teal' | 'payroll'
  items: NavItem[]
}

// Barva sekce se nese tečkou u nadpisu a levou lištou u položek pod ním — ne
// výplní pilulky. Plná pilulka přetahovala pozornost na hlavičky a aktivní
// položka se v tom ztrácela; takhle accent jen rámuje, co k sekci patří.

/** Accent → tečka u nadpisu sekce (plná sytost, ať je barva čitelná i na 6px). */
const ACCENT_DOT: Record<NonNullable<NavSection['accent']>, string> = {
  primary: 'bg-primary-400',
  // Táž barevná rodina jako `primary`, jen o stupeň sytější — pro sekce, které
  // k sobě věcně patří (Účetnictví ⇄ Účetní nástroje) a mají se tak i tvářit.
  // Neutrální odstín se pro ně nehodí: ten už nese Globální nastavení a Systém.
  // Tmavý režim má oba stupně přemapované v styles/main.css, takže zůstává čitelný.
  primaryDeep: 'bg-primary-600',
  warning: 'bg-warning-500',
  success: 'bg-success-500',
  danger:  'bg-danger-500',
  neutral: 'bg-neutral-400',
  accent:  'bg-accent-500',
  teal:    'bg-teal-500',
  payroll: 'bg-payroll-500',
}

/** Accent → levá lišta rámující položky sekce. Tlumená na 40 %, ať nekřičí. */
const ACCENT_RAIL: Record<NonNullable<NavSection['accent']>, string> = {
  primary: 'border-primary-400/40',
  primaryDeep: 'border-primary-600/40',
  warning: 'border-warning-500/40',
  success: 'border-success-500/40',
  danger:  'border-danger-500/40',
  neutral: 'border-neutral-400/40',
  accent:  'border-accent-500/40',
  teal:    'border-teal-500/40',
  payroll: 'border-payroll-500/40',
}

/**
 * Accent JEDNÉ položky (neaktivní stav) — tlumená výplň v barvě accentu.
 *
 * Proč tak málo: aktivní položka si drží `bg-primary-50` a musí zůstat
 * nejsilnějším prvkem v menu. Odlišená položka má jít poznat na první pohled,
 * ale nesmí přebít to, kde uživatel právě je.
 */
const ACCENT_ITEM: Record<NonNullable<NavSection['accent']>, string> = {
  primary:     'text-primary-700 bg-primary-500/8 hover:bg-primary-500/15',
  primaryDeep: 'text-primary-700 bg-primary-600/8 hover:bg-primary-600/15',
  warning:     'text-warning-600 bg-warning-500/10 hover:bg-warning-500/20',
  success:     'text-success-600 bg-success-500/10 hover:bg-success-500/20',
  danger:      'text-danger-600 bg-danger-500/10 hover:bg-danger-500/20',
  neutral:     'text-neutral-600 bg-neutral-500/10 hover:bg-neutral-500/20',
  accent:      'text-accent-600 bg-accent-500/10 hover:bg-accent-500/20',
  teal:        'text-teal-600 bg-teal-500/10 hover:bg-teal-500/20',
  payroll:     'text-payroll-600 bg-payroll-500/10 hover:bg-payroll-500/20',
}

/**
 * Je s provozem něco k řešení? Totéž, co uvidí uživatel na dashboardu —
 * seznam se počítá na jednom místě, aby menu a dashboard nemohly tvrdit
 * každý něco jiného.
 *
 * ⚠️ Na self-hosted instalaci je `instance` null a vrací se `null`: položka
 * Hosting tam stejně není.
 */
const hostingAttention = computed(() => hostingNavAttention(resolveHostingActions(instanceStatus.status.value)))

/** Outline icon paths — Heroicons style, stroke 2, viewBox 24, currentColor */
const ICONS = {
  dashboard:  'M3 12l9-9 9 9M5 10v10h14V10',
  invoices:   'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  proforma:   'M2.25 8.25h19.5M2.25 9v6.75A2.25 2.25 0 0 0 4.5 18h15a2.25 2.25 0 0 0 2.25-2.25V9A2.25 2.25 0 0 0 19.5 6.75h-15A2.25 2.25 0 0 0 2.25 9zM14 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0z',
  recurring:  'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06',
  price_list: 'M4 6h16M4 12h16M4 18h16M7 4v4M13 10v4M17 16v4',
  purchase:   'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-8 2a2 2 0 1 1-4 0 2 2 0 0 1 4 0z',
  bank:       'M3 9l9-7 9 7m-2 0v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9m4 11V13h4v7',
  stats:      'M3 3v18h18M7 14l4-4 4 4 5-5',
  crm:        'M11 3.055A9.001 9.001 0 1 0 20.945 13H11V3.055zM20.488 9H15V3.512A9.025 9.025 0 0 1 20.488 9z',
  reports:    'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2zM9 7h1',
  clients:    'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  projects:   'M3 7l9-4 9 4-9 4-9-4zM3 12l9 4 9-4M3 17l9 4 9-4',
  settings:   'M10.325 4.317a1 1 0 0 1 1.94 0l.31 1.241a7.5 7.5 0 0 1 2.106.873l1.097-.633a1 1 0 0 1 1.371.366l.97 1.683a1 1 0 0 1-.366 1.366l-1.094.632a7.5 7.5 0 0 1 0 2.428l1.094.632a1 1 0 0 1 .366 1.366l-.97 1.683a1 1 0 0 1-1.371.366l-1.097-.633a7.5 7.5 0 0 1-2.106.873l-.31 1.241a1 1 0 0 1-1.94 0l-.31-1.241a7.5 7.5 0 0 1-2.106-.873l-1.097.633a1 1 0 0 1-1.371-.366l-.97-1.683a1 1 0 0 1 .366-1.366l1.094-.632a7.5 7.5 0 0 1 0-2.428l-1.094-.632a1 1 0 0 1-.366-1.366l.97-1.683a1 1 0 0 1 1.371-.366l1.097.633a7.5 7.5 0 0 1 2.106-.873l.31-1.241zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  branding:   'M4 16l4.586-4.586a2 2 0 0 1 2.828 0L16 16m-2-2 1.586-1.586a2 2 0 0 1 2.828 0L20 14m-6-6h.01M6 20h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2z',
  suppliers:  'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM23 11a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  codebooks:  'M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2m14 0V9a2 2 0 0 0-2-2M5 11V9a2 2 0 0 1 2-2m0 0V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 7h10',
  imports:    'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-8l-4-4m0 0l-4 4m4-4v12',
  exports:    'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
  payment_orders: 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3z',
  users:      'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  email:      'M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z',
  sent_email: 'M6 12L3.269 3.126A59.768 59.768 0 0 1 21.485 12 59.77 59.77 0 0 1 3.27 20.876L5.999 12Zm0 0h7.5',
  approvals:  'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0 1 12 2.944a11.955 11.955 0 0 1-8.618 3.04A12.02 12.02 0 0 0 3 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  log:        'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12h6m-6 4h4',
  cron:       'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  updates:    'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06',
  api_tokens: 'M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z',
  mcp:        'M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5',
  help:       'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827V14m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  diagnostics: 'M3 12h4l2-7 4 14 2-7h6',
  ai:         'M13 10V3L4 14h7v7l9-11h-7z',
  documents:  'M7 21h10a2 2 0 0 0 2-2V9.414a1 1 0 0 0-.293-.707l-5.414-5.414A1 1 0 0 0 12.586 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2zM9 13h6m-6 4h6',
  logbook:    'M5 13l1.4-4.2A2 2 0 0 1 8.3 7.5h7.4a2 2 0 0 1 1.9 1.3L19 13m-14 0h14m-14 0v4a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-1h8v1a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-4M7.5 16h.01M16.5 16h.01',
  fuel:       'M4 21h9M6 21V5a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v16M6 11h7M15 7l2.5 2.5a2 2 0 0 1 .5 1.4V17a1.5 1.5 0 0 0 3 0V10l-2-2',
  // Daně sekce — různé ikony pro každý report
  tax_dph:    'M3 10h18M3 14h18M5 21V3a1 1 0 011-1h12a1 1 0 011 1v18M9 7h6M9 11h6M9 15h6',
  tax_kh:     'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
  tax_shv:    'M12 21l-8-8 8-8m0 0l8 8-8 8M3 12h18',
  tax_income: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  tax_archive: 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
  tax_book:   'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
  tax_optimizer: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
  // Účetnictví (podvojné) — kniha / deník
  accounting: 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25M9 8.25h.008M9 11.25h.008',
  // Pokladna — bankovky + mince (odlišné od ICONS.bank, který patří bance)
  cash:       'M2.25 8.25h19.5a.75.75 0 0 1 .75.75v6a.75.75 0 0 1-.75.75H2.25a.75.75 0 0 1-.75-.75v-6a.75.75 0 0 1 .75-.75zm9.75 6.75a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM5.25 9.75v6M18.75 9.75v6',
  // Sklad (Epic SKLAD)
  stock_items:      'M20 7.5l-8-4-8 4m16 0l-8 4m8-4v9l-8 4m0-9L4 7.5m8 4v9M4 7.5v9l8 4',
  stock_documents:  'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  stock_takes:      'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-7 9l2 2 4-4',
  stock_warehouses: 'M3 21h18M4 21V8l8-5 8 5v13M9 21v-6h6v6M9 11h.01M15 11h.01M12 8h.01',
  factory:          'M3 21h18M3 10l5 3V10l5 3V10l5 3v8H3V10z',
  tag:              'M9.5 9.5h.01M21 11.5l-9-9H4a2 2 0 0 0-2 2v8l9 9a2 2 0 0 0 2.8 0l7.2-7.2a2 2 0 0 0 0-2.8z',
  coin:             'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  folderOpen:       'M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-6l-2-2H5a2 2 0 0 0-2 2z M3 9h18',
  requestDoc:       'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M12 11v4m-2-2h4',
  // Výmaz osobních údajů — koš. Odlišný od tax_archive (uschovávání), protože
  // sousední položka menu dělá pravý opak.
  erasure:          'M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3M4 7h16',
}

const navSections = computed<NavSection[]>(() => {
  // Role client (Epic F6): samostatný, minimální nav — Přehled (portál), Fakturace,
  // Nákupy, Kontakty, Nápověda. Vše ostatní skryto (BE stejně deny-by-default).
  if (clientExperience.value) {
    return filterNavigation([
      { key: 'dashboard', title: t('nav.dashboard'), accent: 'teal', items: [{ to: '/portal', label: t('nav.portal'), icon: ICONS.dashboard }] },
      {
        key: 'sales',
        title: t('nav.section_sales'),
        accent: 'primary',
        items: [
          { to: '/invoices',  label: t('nav.invoices'),  icon: ICONS.invoices,  newTo: '/invoices/new' },
          { to: '/recurring', label: t('nav.recurring'), icon: ICONS.recurring, newTo: '/recurring/new' },
        ],
      },
      {
        key: 'purchase',
        title: t('nav.section_purchase'),
        accent: 'warning',
        items: [
          { to: '/purchase-invoices', label: t('nav.purchase_invoices'), icon: ICONS.purchase, newTo: '/purchase-invoices/new' },
        ],
      },
      {
        key: 'contacts',
        title: t('nav.section_contacts'),
        accent: 'success',
        items: [
          { to: '/clients',              label: t('nav.clients'), icon: ICONS.clients,   newTo: '/clients/new' },
          { to: '/clients?role=vendors', label: t('nav.vendors'), icon: ICONS.suppliers, newTo: '/clients/new?role=vendor' },
        ],
      },
      {
        key: 'documents',
        title: t('nav.section_documents'),
        accent: 'neutral',
        items: [
          { to: '/portal/purchase-invoice-submissions', label: t('nav.submit_documents'), icon: ICONS.documents, permission: 'documents.submit' as PermissionKey },
          { to: '/portal/document-requests', label: t('nav.document_requests'), icon: ICONS.requestDoc, permission: 'documents.submit' as PermissionKey },
        ],
      },
    ])
  }

  const isAdmin = auth.isSuperadmin
  // Daňový optimalizátor (paušál vs standardní režim) je jen pro OSVČ (fyzická osoba).
  const isOsvc = supplierStore.currentSupplier?.taxpayer_type === 'fo'
  // Účetnictví, mzdy, sklad i OSS jsou komerční moduly: po vypršení trialu je
  // jejich API zavřené (CommercialFeatureAccess), takže je nesmí nabízet ani
  // menu — odkaz do sekce, kde každý požadavek skončí na 403, je horší než
  // žádný odkaz. Kde se moduly zapínají, tam licenci komunikujeme (Nastavení →
  // Daně a účetnictví).
  const ossEnabled = auth.hasCommercialFeatures && supplierStore.currentSupplier?.oss_enabled === true
  // „Vést účetnictví" (migrace 1179) — firemní opt-out účetní nadstavby. Vypnuté schová
  // účetní sekce z menu úplně stejně, jako by nebyla licence; fakturace, DPH a sklad
  // zůstávají. Undefined = zapnuto (starší /auth/me bez pole), aby chybějící migrace
  // uživateli nesebrala účetnictví.
  const accountingEnabled = auth.hasCommercialFeatures && supplierStore.currentSupplier?.accounting_enabled !== false
  // Mzdy jsou opt-in (migrace 1290), takže undefined = vypnuto — opačně než účetnictví výš.
  const payrollEnabled = auth.hasCommercialFeatures && supplierStore.currentSupplier?.payroll_enabled === true
  // Účetnictví (Epic F1) — sekce se zobrazí jen firmám v režimu podvojného účetnictví.
  const isDoubleEntry = accountingEnabled && supplierStore.currentSupplier?.accounting_mode === 'double_entry'
  // Daňová evidence (Epic DE) — zrcadlo isDoubleEntry; sekce jen pro režim daňové evidence.
  const isTaxEvidence = accountingEnabled && supplierStore.currentSupplier?.accounting_mode === 'tax_evidence'
  // Sklad (Epic SKLAD) — nezávislé na accounting_mode (funguje i pro tax_evidence).
  const isStockEnabled = auth.hasCommercialFeatures && supplierStore.currentSupplier?.stock_enabled === true
  const sections: NavSection[] = [
    {
      // Grafy — přehledové/analytické položky nahoře v menu: akce k řešení, náhled
      // portálu, CRM a tržby/náklady. Vše „na co se dívám", ne operativa.
      key: 'charts',
      title: t('nav.section_charts'),
      accent: 'teal',
      items: [
        { to: '/', label: t('crm.action_items.title'), icon: ICONS.dashboard },
        // Náhled klientského portálu (Epic F6) — i pro admina/účetní, ne jen roli client
        // (ta má vlastní samostatnou nav větev výše a sem se nedostane).
        { to: '/portal',          label: t('nav.portal'),         icon: ICONS.dashboard },
        { to: '/crm',             label: t('nav.crm'),            icon: ICONS.crm },
        { to: '/stats',           label: t('nav.stats'),          icon: ICONS.stats },
        { to: '/purchase-stats',  label: t('nav.purchase_stats'), icon: ICONS.purchase },
      ],
    },
    {
      // Vše co se týká vystavování faktur klientům — klienti/zakázky/schvalování
      // patří v životním cyklu jednoho prodeje (klient → zakázka → faktura → schválení → export pro účetní).
      key: 'sales',
      title: t('nav.section_sales'),
      accent: 'primary',
      items: [
        { to: '/invoices',         label: t('nav.invoices'),   icon: ICONS.invoices,  newTo: '/invoices/new' },
        { to: '/recurring',        label: t('nav.recurring'),  icon: ICONS.recurring, newTo: '/recurring/new' },
        ...(isAdmin && !isStockEnabled ? [{ to: '/admin/price-list', label: t('nav.price_list'), icon: ICONS.price_list }] : []),
        { to: '/clients',          label: t('nav.clients'),    icon: ICONS.clients,   newTo: '/clients/new' },
        { to: '/projects',         label: t('nav.projects'),   icon: ICONS.projects },
        // AI import vydané faktury — prodejní zrcadlo Nákup → AI import. Nahrát PDF/ISDOC →
        // draft vydané faktury. Permission invoices.create zrcadlí BE AiExtractPdfIssuedAction.
        { to: '/invoices/ai-import', label: t('nav.ai_import'),  icon: ICONS.ai, permission: 'invoices.create' },
        // Export/Import vydaných (reorg UX 2026-07, vytažené z Nástrojů) — Import je jen
        // pro superadmina, zrcadlí dřívější gating tabu v DataExchange.vue.
        { to: '/invoices/export',  label: t('nav.export'),     icon: ICONS.exports },
        ...(isAdmin ? [{ to: '/invoices/import', label: t('nav.import'), icon: ICONS.imports }] : []),
        ...(isAdmin ? [{ to: '/admin/approvals',          label: t('nav.approvals'),         icon: ICONS.approvals }] : []),
      ],
    },
    {
      key: 'purchase',
      title: t('nav.section_purchase'),
      accent: 'warning',
      items: [
        { to: '/purchase-invoices',          label: t('nav.purchase_invoices'),  icon: ICONS.purchase, newTo: '/purchase-invoices/new' },
        { to: '/purchase-invoices/incoming', label: t('nav.incoming_documents'), icon: ICONS.documents, permission: 'documents.inbox' as PermissionKey },
        // AI import přijaté faktury (§12b) — denní operativa účetní (nahrát PDF → draft PF);
        // nastavení AI brány (klíče, DPA) zůstává v adminu (Firma → AI nastavení).
        // Explicitní permission: scan zrcadlí BE check AiExtractPdfAction; readonly ji nevidí.
        { to: '/purchase-invoices/ai-import', label: t('nav.ai_import'),         icon: ICONS.ai, permission: 'purchase_invoices.scan' },
        { to: '/clients?role=vendors',       label: t('nav.vendors'),            icon: ICONS.suppliers, newTo: '/clients/new?role=vendor' },
        { to: '/purchase-invoices/payment-orders', label: t('nav.payment_orders'), icon: ICONS.payment_orders },
        // Pravidla zaúčtování nákladů se přesunula pod Šablony (záložka „Pravidla nákladů" na
        // /templates?section=expense) — patří k šablonám, ne do fronty přijatých faktur.
        // Export/Import přijatých (reorg UX 2026-07, vytažené z Nástrojů) — Import jen pro
        // superadmina, zrcadlí dřívější gating tabu v DataExchange.vue.
        { to: '/purchase-invoices/export',   label: t('nav.export'),             icon: ICONS.exports },
        ...(isAdmin ? [{ to: '/purchase-invoices/import', label: t('nav.import'), icon: ICONS.imports }] : []),
        // Majetek patří logicky k nákupu — pořizuje se přijatou fakturou. Zůstává
        // ale účetní funkcí (odpisy, 042/02x), takže se ukazuje jen firmám s aktivním
        // účetnictvím; stejná podmínka jako u sekce Účetnictví níž.
        // Drobný majetek stojí před Majetkem — je to jeho levnější varianta pod
        // hranicí §26/2 a uživatel volí mezi nimi, takže patří vedle sebe.
        // Explicitní `permission`: navPermission() by z prefixu /accounting/ vrátil
        // totéž, jenže spoléhat na odvození z cesty je křehké.
        ...(auth.hasCommercialFeatures && isDoubleEntry ? [
          { to: '/accounting/small-assets', label: t('nav.small_assets'),      icon: ICONS.tag,        permission: 'accounting' as PermissionKey },
          { to: '/accounting/assets',       label: t('nav.accounting_assets'), icon: ICONS.accounting, newTo: '/accounting/assets/new' },
        ] : []),
      ],
    },
    {
      // Sjednocená stránka: výpisy + měny/účty + stavy + avíza + pokladna (PPD/VPD).
      key: 'bank_cash',
      title: t('nav.section_bank_cash'),
      accent: 'success',
      items: [
        { to: '/bank',           label: t('nav.bank_accounts'),  icon: ICONS.bank },
        ...((isDoubleEntry || isTaxEvidence) ? [{ to: '/accounting/cash', label: t('nav.accounting_cash'), icon: ICONS.cash, newTo: '/accounting/cash/new' }] : []),
      ],
    },
    {
      key: 'documents',
      title: t('nav.section_documents'),
      accent: 'neutral',
      items: [
        { to: '/documents', label: t('nav.documents'), icon: ICONS.documents },
        { to: '/logbook', label: t('nav.logbook'), icon: ICONS.logbook, newTo: '/logbook?tab=trips&new=trip' },
      ],
    },
    ...(isStockEnabled ? [{
      key: 'stock',
      title: t('nav.section_stock_compact'),
      accent: 'accent',
      items: [
        { to: '/stock/items',      label: t('nav.stock_items'),      icon: ICONS.stock_items,      newTo: '/stock/items/new' },
        { to: '/stock/documents',  label: t('nav.stock_documents'),  icon: ICONS.stock_documents,  newTo: '/stock/documents/new' },
        // Objednávky dodavatelům (fáze 4 epicu SKLAD).
        { to: '/stock/purchase-orders', label: t('nav.stock_purchase_orders'), icon: ICONS.purchase, newTo: '/stock/purchase-orders/new', newPermission: 'stock.orders.write' },
        // „U dodavatele" — kdo zboží nabízí, za kolik a kolik kusů (fáze 3 epicu SKLAD).
        { to: '/stock/vendor-offers', label: t('nav.stock_vendor_offers'), icon: ICONS.factory },
        // E-shop — číselníky (Sklady/Výrobci/Kategorie/Atributy/Tagy/Poplatky) + import
        // jako záložky; 3. položka sekce „Zboží" (za Skladovými doklady).
        { to: '/eshop',            label: t('nav.section_eshop'),    icon: ICONS.folderOpen },
        { to: '/stock/takes',      label: t('nav.stock_takes'),      icon: ICONS.stock_takes },
        { to: '/stock/reports',    label: t('nav.stock_reports'),    icon: ICONS.reports },
      ],
    } as NavSection] : []),
    {
      key: 'taxes',
      title: t('nav.section_taxes'),
      accent: 'danger',
      items: [
        { to: '/reports/dph',         label: t('nav.reports_dph'),         icon: ICONS.tax_dph },
        { to: '/reports/kh',          label: t('nav.reports_kh'),          icon: ICONS.tax_kh },
        { to: '/reports/dph-book',    label: t('nav.reports_dph_book'),    icon: ICONS.tax_book },
        { to: '/reports/shv',         label: t('nav.reports_shv'),         icon: ICONS.tax_shv },
        // OSS hned za souhrnným hlášením: obojí je hlášení plnění do jiných členských
        // států, účetní je vyplňuje ve stejné části měsíce.
        ...(ossEnabled ? [{ to: '/reports/oss', label: t('nav.reports_oss'), icon: ICONS.tax_shv }] : []),
        // Daň z příjmů je komerční modul: základ daně se počítá z výsledku
        // hospodaření nebo z peněžního deníku a obojí je za licencí.
        ...(auth.hasCommercialFeatures ? [
          { to: '/reports/income-tax',  label: t('nav.reports_income_tax'),  icon: ICONS.tax_income },
        ] : []),
        // Oprava odpočtu §74b se přesunula do Účetních nástrojů za Spojené osoby
        // (vedle §46 — obě jsou korekce DPH nad saldem, ne běžná měsíční agenda).
        ...(auth.hasCommercialFeatures ? [
          { to: '/reports/vat-corrections', label: t('nav.reports_vat_corrections'), icon: ICONS.tax_dph },
        ] : []),
        { to: '/reports/cnb-rate-audit', label: t('nav.reports_cnb_audit'), icon: ICONS.coin },
        { to: '/reports/invoice-series-completeness', label: t('nav.reports_series_completeness'), icon: ICONS.logbook },
        ...(isOsvc && auth.hasCommercialFeatures ? [{ to: '/tax', label: t('nav.tax_optimizer'), icon: ICONS.tax_optimizer }] : []),
        { to: '/reports/monthly-export', label: t('nav.reports_monthly_export'), icon: ICONS.exports, permission: 'reports.export' },
      ],
    },
  ]

  if (auth.hasCommercialFeatures && isDoubleEntry) {
    sections.push({
      key: 'accounting',
      title: t('nav.section_accounting'),
      accent: 'primary',
      items: [
        // Přehled firem (Fáze F, audit 2026-07) — jen když má uživatel přístup k víc
        // firmám (membership/BC-multi); jednofiremní instalaci by jen zahltil menu.
        ...(supplierStore.hasMultiple ? [{ to: '/portfolio', label: t('nav.portfolio'), icon: ICONS.stock_warehouses }] : []),
        { to: '/accounting/journal',        label: t('nav.accounting_journal'),  icon: ICONS.accounting, newTo: '/accounting/journal/new' },
        { to: '/automation',                label: t('nav.automation'),          icon: ICONS.ai, badge: automationStore.actionable, permission: 'accounting' },
        { to: '/accounting/manual-posting-queue', label: t('nav.manual_posting_queue'), icon: ICONS.approvals, permission: 'accounting' },
        { to: '/accounting/general-ledger',   label: t('nav.accounting_general_ledger'),   icon: ICONS.tax_book },
        { to: '/accounting/trial-balance',    label: t('nav.accounting_trial_balance'),    icon: ICONS.stats },
        { to: '/accounting/balance-sheet',    label: t('nav.accounting_balance_sheet'),    icon: ICONS.reports },
        { to: '/accounting/income-statement', label: t('nav.accounting_income_statement'), icon: ICONS.tax_income },
        { to: '/accounting/income-statement-by-function', label: t('nav.accounting_income_statement_by_function'), icon: ICONS.tax_income },
        { to: '/accounting/saldo',            label: t('nav.accounting_saldo'),            icon: ICONS.coin },
        { to: '/accounting/document-completeness', label: t('nav.accounting_document_completeness'), icon: ICONS.approvals, permission: 'accounting' },
        { to: '/accounting/monthly-check',    label: t('nav.accounting_monthly_check'),    icon: ICONS.approvals },
        { to: '/accounting/monthly-report',   label: t('nav.accounting_monthly_report'),   icon: ICONS.reports },
        // Mzdová rekapitulace zůstává ZDE záměrně: položky nejdou přetáhnout mezi
        // sekcemi (useNavOrder), takže přesun do Nástrojů by byl nevratný.
        // V demu je skrytá — počítá odvody za konkrétního poplatníka a na sdílených
        // ukázkových datech nedává smysl.
        ...(auth.isDemo ? [] : [{ to: '/accounting/payroll', label: t('nav.accounting_payroll'), icon: ICONS.users }]),
        // Majetek a Drobný majetek se přesunuly do sekce Nákup (pořizují se přijatou
        // fakturou), pořád ale jen pro firmy s aktivním účetnictvím — viz tam.
      ],
    })
    // Účetní nástroje — méně používané / setup a periodické funkce vyčleněné
    // z přeplněné sekce Účetnictví (19–21 položek), aby denní práce zůstala
    // nahoře a nástroje se daly sbalit.
    sections.push({
      key: 'accounting_tools',
      title: t('nav.section_tools'),
      accent: 'primaryDeep',
      items: [
        { to: '/templates', label: t('nav.section_templates'), icon: ICONS.documents, permission: 'accounting.templates' },
        { to: '/accounting/accounts',       label: t('nav.accounting_accounts'), icon: ICONS.codebooks },
        { to: '/accounting/offsets',          label: t('nav.accounting_offsets'),          icon: ICONS.coin },
        ...(isAdmin ? [{ to: '/admin/accounting-activation', label: t('nav.accounting_activation'), icon: ICONS.updates }] : []),
        { to: '/accounting/balance-inventory', label: t('nav.accounting_balance_inventory'), icon: ICONS.reports },
        { to: '/accounting/section18-statements', label: t('nav.accounting_section18'), icon: ICONS.reports },
        { to: '/reports/related-parties', label: t('nav.reports_related_parties'), icon: ICONS.clients },
        { to: '/reports/s74b', label: t('nav.reports_s74b'), icon: ICONS.recurring },
        // Audit UI mezer 2026-07: dříve backend-only funkce zpřístupněné v menu.
        { to: '/reports/vat-coefficient', label: t('nav.reports_vat_coefficient'), icon: ICONS.tax_income },
        { to: '/reports/s46', label: t('nav.reports_s46'), icon: ICONS.coin },
        { to: '/accounting/retention', label: t('nav.accounting_retention'), icon: ICONS.tax_archive },
        // Nástroje — jedna položka, uvnitř záložky pro méně používané/setup funkce
        // (archivy, kurzový režim, repo sazba, předkontace). Hromadný export je
        // samostatná stránka v sekci Daně.
        { to: '/utilities', label: t('nav.accounting_settings'), icon: ICONS.exports },
        // Uzávěrka — účetní období vytažená ze záložky Nástrojů do vlastní top-level
        // položky menu (jen podvojné účetnictví).
        { to: '/accounting/periods', label: t('nav.section_closing'), icon: ICONS.approvals },
        { to: '/reports/submissions', label: t('nav.reports_submissions'), icon: ICONS.tax_archive },
      ],
    })

  }

  if (isTaxEvidence) {
    sections.push({
      key: 'tax_evidence',
      title: t('nav.section_tax_evidence'),
      accent: 'primary',
      items: [
        ...(supplierStore.hasMultiple ? [{ to: '/portfolio', label: t('nav.portfolio'), icon: ICONS.stock_warehouses }] : []),
        { to: '/tax-evidence/cash-journal',         label: t('nav.de_cash_journal'),         icon: ICONS.tax_book },
        { to: '/tax-evidence/receivables-payables', label: t('nav.de_receivables_payables'), icon: ICONS.crm },
        // Přechodový můstek § 7b → § 24 — jen u firem na DE (chystaný/probíhající přechod);
        // firmě, co už podvojné vede, se v menu neukazuje (stránka zůstává na URL).
        { to: '/accounting/transition-report', label: t('nav.accounting_transition_report'), icon: ICONS.reports },
        // Číselné řady: pokladní doklady se v daňové evidenci číslují z týchž řad jako
        // v podvojném, takže prefix a tvar čísla musí jít nastavit i tady. Bez odkazu
        // si firma vlastní řadu pokladny zapnula, ale opravit ji neměla kde — a hláška
        // `series_prefix_unavailable` ji přitom posílá právě sem. Stránka Nástrojů
        // v tomhle režimu nabízí jen tuhle jednu záložku, proto konkrétní popisek.
        { to: '/utilities', label: t('accounting.closing.series.title'), icon: ICONS.codebooks },
        // Pokladna (PPD/VPD) je v sekci Peníze hned za Bankovní účty (jako u podvojného).
        // Export/Import vydaných/přijaté faktury jsou nezávisle na účetním režimu pod Prodej/Nákup.
        // Šablony (předkontace, pravidla nákladů a zaúčtování banky) tu ZÁMĚRNĚ nejsou:
        // všechny tři jsou k účtování do deníku, takže stránka v daňové evidenci nemá
        // co zobrazit a odkaz vedl na prázdno.
      ],
    })
  }

  // Mzdy stojí za Nástroji, tedy až za účetními sekcemi a těsně před Firmou.
  if (payrollEnabled) {
    sections.push({
      key: 'payroll',
      title: t('nav.section_payroll'),
      accent: 'payroll',
      // Pořadí = pořadí měsíčního mzdového kroku, ne abeceda ani historie vzniku
      // stránek. Sled drží `PayrollGuide.vue` („Jak to funguje" na přehledu):
      // nepřítomnosti → docházka → rychlé vstupy → běh → platby → doklady →
      // podání. Menu do teď začínalo Běhy a Platbami, tedy prostředkem měsíce, a
      // vstupy, bez kterých běh nespočítá správně, měl uživatel až pod nimi.
      items: [
        // 1) Měsíční sled — přehled je rozcestník, zbytek jde v pořadí kroků.
        { to: '/payroll', label: t('nav.payroll_overview'), icon: ICONS.users, permission: 'payroll' as PermissionKey },
        { to: '/payroll/absences', label: t('nav.payroll_absences'), icon: ICONS.log, permission: 'payroll' as PermissionKey },
        { to: '/payroll/time', label: t('nav.payroll_time'), icon: ICONS.approvals, permission: 'payroll' as PermissionKey },
        { to: '/payroll/travel', label: t('nav.payroll_travel'), icon: ICONS.logbook, permission: 'payroll' as PermissionKey },
        { to: '/payroll/quick-inputs', label: t('nav.payroll_quick_inputs'), icon: ICONS.log, permission: 'payroll' as PermissionKey },
        { to: '/payroll/runs', label: t('nav.payroll_runs'), icon: ICONS.approvals, permission: 'payroll' as PermissionKey },
        { to: '/payroll/posting-reconciliation', label: t('nav.payroll_posting_reconciliation'), icon: ICONS.accounting, permission: 'payroll.post' as PermissionKey },
        { to: '/payroll/payments', label: t('nav.payroll_payments'), icon: ICONS.payment_orders, permission: 'payroll.payments' as PermissionKey },
        { to: '/payroll/documents', label: t('nav.payroll_documents'), icon: ICONS.documents, permission: 'payroll.documents' as PermissionKey },
        // Roční zúčtování stojí hned za dokumenty, protože je to jejich roční
        // protějšek — nepatří do měsíčního sledu, běží jen v lednu až březnu.
        { to: '/payroll/annual-settlement', label: t('nav.payroll_annual_settlement'), icon: ICONS.accounting, permission: 'payroll.documents' as PermissionKey },
        { to: '/payroll/submissions', label: t('nav.payroll_submissions'), icon: ICONS.exports, permission: 'payroll.submissions' as PermissionKey },
        // 2) Kmenová evidence zaměstnance — nemá měsíční takt, udržuje se průběžně.
        { to: '/payroll/people', label: t('nav.payroll_people'), icon: ICONS.clients, permission: 'payroll' as PermissionKey, dividerBefore: true },
        { to: '/payroll/deduction-agreements', label: t('nav.payroll_deduction_agreements'), icon: ICONS.tag, permission: 'payroll' as PermissionKey },
        { to: '/payroll/enforcement', label: t('nav.payroll_enforcement'), icon: ICONS.coin, permission: 'payroll.enforcement' as PermissionKey },
        // Roční koše osvobození benefitů se sledují průběžně, ne v měsíčním
        // taktu: kdo se dozví o překročení až u prosincového vstupu, dozví se to
        // pozdě.
        { to: '/payroll/benefit-baskets', label: t('nav.payroll_benefit_baskets'), icon: ICONS.stats, permission: 'payroll' as PermissionKey },
        // 3) Jednorázové nastavení — sáhne se do něj při zavádění a pak výjimečně.
        { to: '/payroll/settings', label: t('nav.payroll_settings'), icon: ICONS.settings, permission: 'payroll.settings' as PermissionKey, dividerBefore: true },
        { to: '/payroll/components', label: t('nav.payroll_components'), icon: ICONS.tag, permission: 'payroll' as PermissionKey },
        // Legislativní pravidla: stránka existovala od commitu 88853785, ale
        // nevedl na ni jediný odkaz — dalo se tam jen ručně napsanou URL.
        { to: '/payroll/rulesets', label: t('nav.payroll_rulesets'), icon: ICONS.codebooks, permission: 'payroll.rulesets' as PermissionKey },
        // Retenční lhůty patří k nastavení: sáhne se do nich při zavádění
        // (odchylka firmy) a pak už jen když se někdo ptá, jak dlouho co držíme.
        { to: '/payroll/retention', label: t('nav.payroll_retention'), icon: ICONS.tax_archive, permission: 'payroll.retention' as PermissionKey },
        // Výmaz stojí hned za lhůtami — bez nich nedává smysl —, ale má vlastní
        // právo: číst lhůty smí i ten, kdo nesmí odklepnout nevratné smazání.
        { to: '/payroll/erasure', label: t('nav.payroll_erasure'), icon: ICONS.erasure, permission: 'payroll.erasure' as PermissionKey },
      ],
    } as NavSection)
  }

  if (isAdmin || auth.isDemo) {
    // Firma — nastavení JEDNOHO konkrétního dodavatele (aktuální firmy): fakturační
    // údaje, napojení na iDoklad/Fakturoid/AI, číselníky dodavatelů/kategorií, API tokeny.
    sections.push({
      key: 'company',
      title: t('nav.section_company'),
      accent: 'warning',
      items: auth.isDemo ? [
        { to: '/admin/settings', label: t('nav.settings'), icon: ICONS.settings },
        { to: '/admin/branding', label: t('nav.branding'), icon: ICONS.branding, permission: 'settings.branding' as PermissionKey },
        { to: '/admin/codebooks?scope=company', label: t('nav.codebooks'), icon: ICONS.codebooks, permission: 'settings.company' as PermissionKey },
        // MCP server je v demu záměrně vidět: stránka jen čte (návod, přehled nástrojů,
        // log volání) a je to jedna z věcí, kvůli kterým si zájemce demo pouští.
        // Vydat token v demu nejde — mutace zastaví DemoReadOnlyMiddleware.
        { to: '/profile/mcp-server', label: t('nav.mcp_server'), icon: ICONS.mcp, permission: 'profile.tokens' as PermissionKey },
      ] : [
        { to: '/admin/settings',              label: t('nav.settings'),        icon: ICONS.settings },
        { to: '/admin/integrations',          label: t('nav.integrations'),    icon: ICONS.api_tokens },
        { to: '/admin/integrations?tab=ai',   label: t('nav.ai_settings'),     icon: ICONS.ai },
        { to: '/admin/branding',              label: t('nav.branding'),        icon: ICONS.branding },
        { to: '/admin/codebooks?scope=company', label: t('nav.codebooks'),     icon: ICONS.codebooks },
        { to: '/profile/api-tokens',          label: t('nav.api_tokens'),      icon: ICONS.api_tokens },
        { to: '/profile/mcp-server',          label: t('nav.mcp_server'),      icon: ICONS.mcp },
        { to: '/document-requests',           label: t('nav.document_requests'), icon: ICONS.requestDoc },
        { to: '/admin/databox',               label: t('nav.databox'),         icon: ICONS.documents, permission: 'settings.signing' as PermissionKey },
      ],
    })
    // Systém — globální nastavení a licenční agenda v jednom menu.
    sections.push({
      key: 'system_global',
      title: t('nav.system'),
      accent: 'neutral',
      items: auth.isDemo ? [
        { to: '/admin/codebooks?scope=global', label: t('nav.codebooks_global'), icon: ICONS.codebooks, permission: 'settings.company' as PermissionKey },
        { to: '/admin/tax-constants', label: t('codebooks.tab_tax_constants'), icon: ICONS.tax_optimizer, permission: 'settings.company' as PermissionKey },
      ] : [
        { to: '/admin/codebooks?scope=global', label: t('nav.codebooks_global'), icon: ICONS.codebooks },
        { to: '/admin/tax-constants', label: t('codebooks.tab_tax_constants'), icon: ICONS.tax_optimizer },
        { to: '/admin/bank-rule-templates', label: t('nav.bank_rule_templates'), icon: ICONS.bank },
        { to: '/admin/suppliers',        label: t('nav.suppliers'),       icon: ICONS.suppliers },
        { to: '/admin/users',            label: t('nav.users'),           icon: ICONS.users },
        { to: '/admin/roles',            label: t('nav.roles'),           icon: ICONS.approvals },
        { to: '/admin/emails',           label: t('nav.emails'),          icon: ICONS.email },
        { to: '/admin/activity-log',     label: t('nav.log'),             icon: ICONS.log },
        { to: '/admin/cron-jobs',        label: t('nav.cron_jobs'),       icon: ICONS.cron },
        { to: '/admin/isds-gateway',     label: t('nav.isds_gateway'),    icon: ICONS.documents },
        { to: '/admin/update',           label: t('nav.updates'),         icon: ICONS.updates },
        ...(isAdmin ? [
          // Hosting — JEN spravovaná (hostovaná) instalace. Na self-hosted se
          // položka nesmí objevit vůbec: stránka mluví o zaplaceném prostoru,
          // tarifu a předplaceném provozu, což tam nic neznamená.
          // Odlišená jantarovou: je to placená služba, ne další nastavení, a mezi
          // ostatními položkami Systému splývala. Červená sem NEPATŘÍ — ta je pro
          // stav, kdy je něco potřeba řešit, a ten nese tečka `attention`.
          ...(auth.isManagedInstallation ? [{
            to: '/hosting',
            label: t('nav.hosting'),
            icon: ICONS.stock_warehouses,
            dividerBefore: true,
            accent: 'warning' as const,
            attention: hostingAttention.value,
          }] : []),
          { to: '/activation/license',  label: t('nav.license'),               icon: ICONS.approvals, dividerBefore: true },
          { to: '/activation/terms',    label: t('nav.terms'),                 icon: ICONS.documents },
          { to: '/activation/purchase', label: t('nav.purchase_subscription'), icon: ICONS.coin },
          // Kompletní export dat firmy — stažení všeho v jednom archivu (H-14).
          { to: '/admin/instance-export', label: t('nav.instance_export'),     icon: ICONS.exports, dividerBefore: true },
          // Podklady k incidentu a rozcestník podpory — vlastní skupina na konci.
          { to: '/admin/diagnostics',   label: t('nav.diagnostics'),           icon: ICONS.diagnostics, dividerBefore: true },
          { to: '/admin/support',       label: t('nav.support'),               icon: ICONS.help },
        ] : []),
        // Manuál je poslední položka Systému — v novém tabu, ať člověk nepřijde
        // o rozdělanou práci.
        { to: '/manual', label: t('nav.manual'), icon: ICONS.documents, external: true },
      ],
    })
  }

  if (!isAdmin && !auth.isDemo) {
    // Non-admin role (accountant/readonly) nemá žádnou jinou cestu k vlastním API
    // tokenům — route /profile/api-tokens nemá adminOnly, ale dřív byl jediný
    // sidebar link uvnitř isAdmin bloku výše, takže k němu vedla jen přímá URL.
    if (auth.canWrite('settings.signing')) {
      sections.push({
        key: 'company_databox',
        title: t('nav.section_company'),
        accent: 'warning',
        items: [{ to: '/admin/databox', label: t('nav.databox'), icon: ICONS.documents, permission: 'settings.signing' }],
      })
    }
    const nonAdminSystemItems: NavItem[] = []
    if (auth.canRead('settings.signing') && accountantSigningProfilesEnabled.value) {
      nonAdminSystemItems.push({ to: '/admin/electronic-signatures', label: t('nav.electronic_signatures'), icon: ICONS.approvals })
    }
    nonAdminSystemItems.push({ to: '/profile/api-tokens', label: t('nav.api_tokens'), icon: ICONS.api_tokens })
    nonAdminSystemItems.push({ to: '/profile/mcp-server', label: t('nav.mcp_server'), icon: ICONS.mcp })
    nonAdminSystemItems.push({ to: '/manual', label: t('nav.manual'), icon: ICONS.documents, external: true })
    sections.push({
      key: 'system_signing',
      title: t('nav.system'),
      accent: 'neutral',
      items: nonAdminSystemItems,
    })
  }

  return filterNavigation(auth.isAccountantRole ? accountantFirst(sections) : sections)
})

/**
 * Účetní (systémová role `accountant`) otevírá menu kvůli daním a účetnictví, ne
 * kvůli grafům — vytáhneme jeho sekce nahoru. `tax_evidence` jede s nimi, je to
 * protějšek `accounting` pro režim daňové evidence (nikdy se nezobrazí spolu).
 *
 * Jen DEFAULT pořadí: useNavOrder aplikuje uložené `nav.order` až nad tímto, takže
 * vlastní přetažení uživatele pořád vyhrává a neuvedené klíče se řadí podle tohoto.
 */
function accountantFirst(sections: NavSection[]): NavSection[] {
  const first = ['taxes', 'payroll', 'accounting', 'accounting_tools', 'tax_evidence']
  const rank = (s: NavSection) => {
    const i = first.indexOf(s.key)
    return i === -1 ? first.length : i
  }
  // Stabilní řazení — zbytek sekcí si drží původní pořadí.
  return [...sections].sort((a, b) => rank(a) - rank(b))
}

function navPermission(item: NavItem): PermissionKey | null {
  if (item.permission) return item.permission
  const path = item.to.split('?')[0]
  if (path === '/' || path === '/stats' || path === '/purchase-stats' || path === '/crm') return 'dashboard'
  if (path === '/portal') return 'profile'
  if (path === '/portfolio') return 'dashboard.portfolio'
  if (path.startsWith('/invoices')) return 'invoices'
  if (path.startsWith('/purchase-invoices')) return path.includes('payment-orders') ? 'purchase_invoices.payment_orders' : 'purchase_invoices'
  if (path.startsWith('/recurring')) return 'recurring'
  if (path.startsWith('/clients')) return 'clients'
  if (path.startsWith('/projects')) return 'projects'
  if (path.startsWith('/documents') || path === '/document-requests') return path.includes('request') ? 'documents.requests' : 'documents'
  if (path.startsWith('/bank')) return 'bank'
  if (path.startsWith('/accounting/cash')) return 'cash'
  if (path.startsWith('/accounting/assets')) return 'assets'
  if (path.startsWith('/accounting')) return 'accounting'
  if (path.startsWith('/tax-evidence')) return 'tax_evidence'
  if (path.startsWith('/payroll')) return 'payroll'
  if (path.startsWith('/reports') || path === '/tax') return 'reports'
  if (path.startsWith('/stock')) return 'stock'
  if (path.startsWith('/eshop')) return 'eshop'
  if (path.startsWith('/logbook')) return 'logbook'
  if (path === '/templates') return 'accounting.templates'
  if (path === '/utilities') return 'utilities'
  if (path === '/admin/electronic-signatures') return 'settings.signing'
  if (path === '/admin/databox') return 'settings.signing'
  if (path.startsWith('/admin/settings') || path.startsWith('/admin/integrations')) return 'settings.company'
  if (path.startsWith('/admin/branding')) return 'settings.branding'
  if (path.startsWith('/profile/api-tokens') || path.startsWith('/profile/mcp-server')) return 'profile.tokens'
  if (path.startsWith('/profile')) return 'profile'
  return null
}

function filterNavigation(sections: NavSection[]): NavSection[] {
  return sections.map(section => ({
    ...section,
    items: section.items.filter(item => {
      if (item.external) return true
      const permission = navPermission(item)
      if (item.to.startsWith('/admin/settings') || item.to.startsWith('/admin/integrations')) return auth.isDemo || auth.canWrite('settings.company.write')
      if (item.to.startsWith('/admin/branding')) return auth.isDemo ? auth.canRead('settings.branding') : auth.canWrite('settings.branding')
      if (item.to.startsWith('/admin/electronic-signatures')) return auth.canWrite('settings.signing')
      if (item.to.startsWith('/admin/databox')) return auth.canWrite('settings.signing')
      return permission ? auth.canRead(permission) : auth.isSuperadmin
    }),
  })).filter(section => section.items.length > 0)
}

function canCreate(item: NavItem): boolean {
  if (!item.newTo) return false
  if (auth.isDemo && [
    '/invoices/new',
    '/purchase-invoices/new',
    '/clients/new',
    '/clients/new?role=vendor',
    '/accounting/journal/new',
  ].includes(item.newTo)) return true
  if (item.newPermission) return auth.canWrite(item.newPermission)
  const path = item.newTo
  if (path.startsWith('/invoices')) return auth.canWrite('invoices.create')
  if (path.startsWith('/purchase-invoices')) return auth.canWrite('purchase_invoices.create')
  if (path.startsWith('/recurring')) return auth.canWrite('recurring.create')
  if (path.startsWith('/clients')) return auth.canWrite('clients.create')
  if (path.startsWith('/accounting/journal')) return auth.canWrite('accounting.journal.write')
  if (path.startsWith('/accounting/assets')) return auth.canWrite('assets.write')
  if (path.startsWith('/accounting/cash')) return auth.canWrite('cash.document.write')
  if (path.startsWith('/stock/items')) return auth.canWrite('stock.items.write')
  if (path.startsWith('/stock/documents')) return auth.canWrite('stock.documents.write')
  if (path.startsWith('/logbook')) return auth.canWrite('logbook.write')
  return false
}

function createTarget(item: NavItem): string {
  return item.newTo ?? '/'
}

// §10: přeuspořádání menu (drag & drop). Logika v useNavOrder; zde jen aplikace pořadí.
const nav = useNavOrder()
const orderedNav = computed(() => nav.orderedSections(navSections.value))

// Od tabletové šířky je navigace buď nahoře, nebo jako trvalý levý panel.
const isDesktop = ref(false)
let desktopMql: MediaQueryList | null = null
const desktopNavHost = ref<HTMLElement | null>(null)
const sideSupplierHost = ref<HTMLElement | null>(null)
const autoSideNavigation = ref(false)
const NAV_LAYOUT_COOKIE = 'myinvoice_nav_layout'

/** Rezerva v px, o kterou horní lišta přeskočí na levé menu dřív, než dojde místo. */
const NAV_FIT_RESERVE = 12

function readCookie(name: string): string | null {
  const prefix = `${encodeURIComponent(name)}=`
  const match = document.cookie.split('; ').find(row => row.startsWith(prefix))
  return match ? decodeURIComponent(match.slice(prefix.length)) : null
}

function saveNavigationPreference(side: boolean): void {
  document.cookie = side
    ? `${NAV_LAYOUT_COOKIE}=side; Path=/; Max-Age=31536000; SameSite=Lax`
    : `${NAV_LAYOUT_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`
}

const preferSideNavigation = ref(readCookie(NAV_LAYOUT_COOKIE) === 'side')
const sideNavigation = computed(() => isDesktop.value && (preferSideNavigation.value || autoSideNavigation.value))
const topNavigation = computed(() => isDesktop.value && !sideNavigation.value)
let navResizeObserver: ResizeObserver | null = null

function evaluateDesktopNavFit(): void {
  const host = desktopNavHost.value
  if (!isDesktop.value || !host) {
    autoSideNavigation.value = false
    return
  }
  const row = host.querySelector<HTMLElement>('nav')
  const requiredWidth = row
    ? [...row.children].reduce((width, child) => width + (child as HTMLElement).offsetWidth, 0)
    : 0
  const reclaimableSideControls = sideSupplierHost.value
    ? sideSupplierHost.value.offsetWidth + 4
    : 0
  // Rezerva místo dřívější 2px tolerance přetečení: lišta se překlopí na levé menu
  // ještě než na ni položky přestanou stačit. Do NAV_FIT_RESERVE se vejde poslední
  // padding sekce, takže se nikdy neukáže napůl oříznutý popisek.
  autoSideNavigation.value = requiredWidth > host.clientWidth + reclaimableSideControls - NAV_FIT_RESERVE
}

function onDesktopChange(e: MediaQueryListEvent): void {
  isDesktop.value = e.matches
  void nextTick(evaluateDesktopNavFit)
}

function toggleNavigationLayout(): void {
  if (preferSideNavigation.value) {
    preferSideNavigation.value = false
    saveNavigationPreference(false)
    autoSideNavigation.value = false
    void nextTick(evaluateDesktopNavFit)
    return
  }
  if (autoSideNavigation.value) return
  preferSideNavigation.value = true
  saveNavigationPreference(true)
}

watch([orderedNav, locale], () => { void nextTick(evaluateDesktopNavFit) })
watch(sideNavigation, () => { void nextTick(evaluateDesktopNavFit) })

function onResetNavOrder(): void {
  if (confirm(t('common.nav_reset_order_confirm'))) nav.reset()
}

/**
 * Accent sekce, ve které uživatel právě je (Prodej = primary, Nákup = warning,
 * Peníze = success, Daně = danger, Sklad = accent…).
 *
 * Why: barvy modulů dosud žily jen v menu — tečka u nadpisu sekce a levá lišta
 * u položek. Jakmile jsi klikl do obsahu, byla aplikace zase jen indigová a
 * nebylo poznat, ve které agendě jsi. Accent posíláme přes `data-accent` na
 * <main>, kde ho styles/main.css převede na `--module-accent` a obarví jím
 * proužek pod topbarem, aktivní záložku a linky sekcí.
 *
 * Fallback 'primary' platí pro stránky mimo menu (profil, 404, nastavení).
 */
/** Paleta příkazů — otevírá ji i tlačítko v patičce, nejen zkratka. */
const paletteRef = ref<InstanceType<typeof CommandPalette> | null>(null)

const activeSectionAccent = computed<string>(() => {
  for (const section of orderedNav.value) {
    if (section.items.some(item => !item.external && isActive(item))) {
      return section.accent ?? 'primary'
    }
  }
  return 'primary'
})

/** Rychlé zkratky v topbaru (desktop) — ikony navazují na menu (ICONS). */
const quickActions = computed(() => {
  if (auth.isDemo && !clientExperience.value) return [
    { to: '/invoices/new', label: t('nav.quick_invoice'), icon: ICONS.invoices },
    { to: '/purchase-invoices/new', label: t('nav.quick_purchase'), icon: ICONS.purchase },
    { to: '/clients/new', label: t('nav.quick_client'), icon: ICONS.clients },
    { to: '/clients/new?role=vendor', label: t('nav.quick_vendor'), icon: ICONS.suppliers },
    { to: '/logbook?tab=fuel&new=fuel', label: t('nav.quick_fueling'), icon: ICONS.fuel },
    { to: '/accounting/journal/new', label: t('nav.quick_journal'), icon: ICONS.accounting },
  ]
  const actions = [
    { to: '/invoices/new',          label: t('nav.quick_invoice'),   icon: ICONS.invoices },
    { to: '/invoices/new?type=proforma', label: t('nav.quick_proforma'), icon: ICONS.proforma },
    { to: '/recurring/new',         label: t('nav.quick_recurring'), icon: ICONS.recurring },
    { to: '/clients/new',           label: t('nav.quick_client'),    icon: ICONS.clients },
    { to: '/clients/new?role=vendor', label: t('nav.quick_vendor'), icon: ICONS.suppliers },
    { to: '/purchase-invoices/new', label: t('nav.quick_purchase'), icon: ICONS.purchase },
    ...(!clientExperience.value && auth.hasCommercialFeatures && auth.canWrite('accounting.journal.write') ? [
      { to: '/accounting/journal/new', label: t('nav.quick_journal'), icon: ICONS.accounting },
    ] : []),
    ...(clientExperience.value ? [] : [
      { to: '/logbook?tab=trips&new=trip', label: t('nav.quick_trip'),    icon: ICONS.logbook },
      { to: '/logbook?tab=fuel&new=fuel',  label: t('nav.quick_fueling'), icon: ICONS.fuel },
    ]),
    ...(!clientExperience.value && auth.hasCommercialFeatures && supplierStore.currentSupplier?.stock_enabled ? [
      { to: '/stock/documents/new?doc_type=receipt', label: t('nav.quick_stock_receipt'), icon: ICONS.stock_documents },
      { to: '/stock/documents/new?doc_type=issue',   label: t('nav.quick_stock_issue'),   icon: ICONS.stock_documents },
      { to: '/stock/items/new',                      label: t('nav.quick_stock_item'),    icon: ICONS.stock_items },
    ] : []),
  ].filter(action => canCreate({
    to: action.to,
    label: action.label,
    icon: action.icon,
    newTo: action.to,
  }))
  // AI import přijaté faktury patří do rychlého „+" hned za Přijatou fakturu —
  // je to druhá nejčastější cesta, jak doklad do systému dostat (nahraju PDF,
  // AI z něj udělá draft). Nejde o zakládací route, takže `canCreate` by ji
  // vyhodila; gate je stejný jako u položky v menu — právo `purchase_invoices.scan`
  // (zrcadlí BE check v AiExtractPdfAction, readonly ji nevidí).
  if (!clientExperience.value && !auth.isDemo && auth.canWrite('purchase_invoices.scan')) {
    const purchaseIdx = actions.findIndex(a => a.to === '/purchase-invoices/new')
    const aiImport = { to: '/purchase-invoices/ai-import', label: t('nav.ai_import'), icon: ICONS.ai }
    if (purchaseIdx === -1) actions.push(aiImport)
    else actions.splice(purchaseIdx + 1, 0, aiImport)
  }
  return actions
})

/**
 * Cíl pro „nový záznam" podle agendy, ve které uživatel právě je — jedna zkratka
 * místo zvlášť namapovaného zakládání pro každou agendu. Bere nejdelší položku menu,
 * jejíž cesta je prefixem té aktuální (aby /invoices/123 spadlo pod /invoices), a
 * respektuje canCreate, takže bez práva nebo mimo agendu vrátí prázdno.
 */
const contextualCreateTarget = computed<string>(() => {
  const here = activeRoute.value.path
  let best: NavItem | null = null
  let bestScore = -1
  for (const section of orderedNav.value) {
    for (const item of section.items) {
      if (!item.newTo || item.external) continue
      const base = item.to.split('?')[0]
      if (here !== base && !here.startsWith(base + '/')) continue
      // Přesná shoda i s query (/clients?role=vendors) bije shodu jen na cestě.
      // Bez toho by na /clients mohla vyhrát položka Dodavatelé podle pořadí sekcí
      // a Alt+N by zakládal dodavatele místo klienta.
      const exact = activeRoute.value.fullPath === item.to
      const score = base.length * 2 + (exact ? 1 : 0) - (item.to.includes('?') && !exact ? 1 : 0)
      if (score > bestScore) {
        bestScore = score
        best = item
      }
    }
  }
  return best && canCreate(best) ? createTarget(best) : ''
})

const shortcutActions = computed<ShortcutAction[]>(() => {
  const registered: ShortcutAction[] = [{
    id: 'search.global',
    label: t('keyboard_shortcuts.search'),
    group: 'general',
  }, {
    // Registruje se vždy, i když v aktuální agendě zakládat nejde — jinak by
    // zkratka zmizela z nastavení v profilu. Prázdné `to` handler ignoruje.
    id: 'create.contextual',
    label: t('keyboard_shortcuts.create_contextual'),
    group: 'create',
    to: contextualCreateTarget.value,
  }]

  for (const section of orderedNav.value) {
    for (const item of section.items) {
      registered.push({
        id: `nav:${item.to}`,
        label: `${section.title}: ${item.label}`,
        group: 'menu',
        to: item.to,
        external: item.external,
      })
      if (canCreate(item)) {
        registered.push({
          id: `new:${createTarget(item)}`,
          label: `${t('nav.quick_new')}: ${item.label}`,
          group: 'create',
          to: createTarget(item),
        })
      }
    }
  }

  for (const action of quickActions.value) {
    registered.push({
      id: `new:${action.to}`,
      label: action.label,
      group: 'create',
      to: action.to,
    })
  }

  return registered
})

watch(shortcutActions, actions => keyboardShortcuts.registerActions(actions), { immediate: true })
const searchShortcutLabel = computed(() => {
  const combo = keyboardShortcuts.comboFor('search.global')
  return combo ? formatShortcut(combo) : ''
})

/** Popisek zkratky pro položku menu, nebo prázdno. Respektuje uživatelské přemapování. */
function navShortcutLabel(to: string): string {
  const combo = keyboardShortcuts.comboFor(`nav:${to}`)
  return combo ? formatShortcut(combo) : ''
}

function isEditableTarget(target: EventTarget | null): boolean {
  return target instanceof HTMLElement
    && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
}

function onGlobalShortcut(event: KeyboardEvent): void {
  if (event.repeat || event.isComposing || sessionSecurity.privacyCurtain) return
  const target = event.target instanceof HTMLElement ? event.target : null
  if (target?.closest('[data-shortcut-capture]')) return
  if (document.querySelector('[role="dialog"][aria-modal="true"], dialog[open]')) return

  const action = keyboardShortcuts.actionForEvent(event)
  if (!action) return
  if (isEditableTarget(event.target) && action.id !== 'search.global') return

  event.preventDefault()
  if (action.id === 'search.global') {
    if (isDesktop.value) {
      desktopSearchRef.value?.focus()
    } else {
      mobileOpen.value = true
      void nextTick(() => mobileSearchRef.value?.focus())
    }
    return
  }
  if (!action.to) return
  if (action.external) {
    window.open(action.to, '_blank', 'noopener')
  } else {
    void workspaceNavigation.navigate(action.to)
  }
}

/** Ploché položky menu pro globální search (našeptávač skáče přímo na body menu). */
const flatNavItems = computed(() =>
  navSections.value.flatMap(s => s.items.map(it => ({ to: it.to, label: it.label, icon: it.icon, external: it.external })))
)

/**
 * Totéž pro paletu příkazů, ale s kontextem sekce: název jde pod položku jako
 * popisek („Vydané faktury" / Prodej) a accent barví ikonu, takže se výsledky
 * dají rozeznat podle barev, ne jen čtením.
 */
const paletteNavItems = computed(() =>
  navSections.value.flatMap(s => s.items.map(it => ({
    to: it.to,
    label: it.label,
    icon: it.icon,
    external: it.external,
    section: s.title ?? '',
    accent: s.accent ?? 'primary',
  })))
)

const manualHref = computed(() => {
  const chapter = manualChapter(activeRoute.value.path)
  const path = chapter ? `/manual?ch=${chapter}` : '/manual'
  const canonicalBaseUrl = auth.domainContext?.canonical_base_url
  if (!auth.domainContext?.locked || !canonicalBaseUrl) return path

  try {
    return `${new URL(canonicalBaseUrl).origin}${path}`
  } catch {
    return path
  }
})

/**
 * „Pokrývá" URL (path + případná query) současnou route?
 * Path musí sedět přesně nebo jako rodič skutečného child segmentu — prostý
 * startsWith by matchoval i sourozence se stejným prefixem (např. /reports/dph
 * by matchoval /reports/dph-book). Query klíče z URL musí všechny sedět
 * s route.query; `queried` říká, že shoda vznikla i přes query (= specifičtější).
 */
function urlCoversRoute(url: string): { covers: boolean; queried: boolean } {
  const [path, qs] = url.split('?', 2)
  if (activeRoute.value.path !== path && !activeRoute.value.path.startsWith(path + '/')) return { covers: false, queried: false }
  if (!qs) return { covers: true, queried: false }
  for (const [k, v] of new URLSearchParams(qs)) {
    if (String(activeRoute.value.query[k] ?? '') !== v) return { covers: false, queried: false }
  }
  return { covers: true, queried: true }
}

/**
 * Kandidátní URL položky menu: `to` + případné `newTo`. Formulář „nový" patří
 * vizuálně k témuž itemu — /clients/new?role=vendor jsou „Dodavatelé", ne
 * „Klienti". Hodnoty query se u seznamu a formuláře liší záměrně (seznam
 * filtruje přes role=vendors, formulář dostává default přes role=vendor,
 * viz ClientList vs ClientForm), takže samotné `to` na match nestačí.
 */
function itemUrls(item: { to: string; newTo?: string }): string[] {
  return item.newTo ? [item.to, item.newTo] : [item.to]
}

function isActive(item: NavItem): boolean {
  if (!workspace.activePane?.fullPath) return false
  const to = item.to
  if (to === '/') return activeRoute.value.path === '/'

  const [toPath] = to.split('?', 2)

  const matches = itemUrls(item).map(urlCoversRoute)
  if (!matches.some(m => m.covers)) return false

  // Match bez query shody prohrává s itemem, který route pokrývá včetně query —
  // ať už přes `to` (/clients vs /clients?role=vendors na seznamu dodavatelů),
  // nebo přes `newTo` (/clients vs /clients/new?role=vendor na formuláři).
  if (!matches.some(m => m.queried)) {
    for (const section of navSections.value) {
      for (const it of section.items) {
        if (it.to === to) continue
        if (itemUrls(it).some(u => urlCoversRoute(u).queried)) return false
      }
    }
  }

  // Delší `to` v menu má prednost (např. /purchase-invoices vs /purchase-invoices/export).
  for (const section of navSections.value) {
    for (const it of section.items) {
      if (it.to !== to && it.to.startsWith(toPath + '/') && activeRoute.value.path.startsWith(it.to.split('?')[0])) {
        return false
      }
    }
  }
  return true
}

function syncActivePaneTitle(): void {
  if (!workspace.activePane?.fullPath) {
    workspace.updatePane(workspace.activePaneId, { title: null })
    return
  }
  const item = orderedNav.value
    .flatMap(section => section.items)
    .find(candidate => !candidate.external && isActive(candidate))
  workspace.updatePane(workspace.activePaneId, {
    title: item?.label ?? (typeof activeRoute.value.name === 'string' ? activeRoute.value.name : activeRoute.value.path),
  })
}

// Zavři mobile drawer + popupy po navigaci
watch([() => workspace.activeFullPath, () => workspace.layoutRevision], () => {
  mobileOpen.value = false
  quickOpen.value = false
  userMenuOpen.value = false
  syncActivePaneTitle()
}, { immediate: true })

// Licenční banner (E4) — trial odpočet (od 3. dne), overage výzva s deadline,
// degradovaný / prošlý-trial stav. Řídí se stavem z auth store (/auth/me).
const BANNER_CLASS: Record<'primary' | 'warning' | 'danger', string> = {
  primary: 'border-primary-200 bg-primary-50 text-primary-800',
  warning: 'border-warning-300 bg-warning-50 text-warning-800',
  danger:  'border-danger-300 bg-danger-50 text-danger-700',
}
const licenseBanner = computed<{ variant: 'primary' | 'warning' | 'danger'; text: string } | null>(() => {
  const lic = auth.license
  if (!lic || auth.isDemo) return null
  const now = Math.floor(Date.now() / 1000)
  if (lic.state === 'trial') {
    if (!lic.trial_ends_at) return null
    const daysLeft = Math.max(Math.ceil((lic.trial_ends_at - now) / 86400), 0)
    // Odpočet zobrazíme až od 3. dne trialu (zbývá 5 a méně dní ze 7).
    return daysLeft <= 5 ? { variant: 'primary', text: t('license.banner_trial', { days: daysLeft }) } : null
  }
  if (lic.state === 'overage') {
    const daysLeft = lic.overage_deadline ? Math.max(Math.ceil((lic.overage_deadline - now) / 86400), 0) : null
    const base = daysLeft !== null ? t('license.banner_overage', { days: daysLeft }) : t('license.banner_overage_nodeadline')
    // Zpřehlednění: kolik aktivních uživatelů / firem vs. licencováno.
    const parts: string[] = []
    if (lic.users_licensed > 0) parts.push(t('license.counts_users', { active: lic.users_active, licensed: lic.users_licensed }))
    if (lic.max_companies !== null) parts.push(t('license.counts_companies', { active: lic.companies_active, licensed: lic.max_companies }))
    return { variant: 'warning', text: parts.length ? `${base} ${parts.join(' · ')}` : base }
  }
  if (lic.state === 'trial_expired') return { variant: 'danger', text: t('license.banner_trial_expired') }
  if (lic.state === 'degraded') return { variant: 'danger', text: t('license.banner_degraded') }
  return null
})

const versionInfo = ref<PublicVersion | null>(null)
onMounted(async () => {
  void ensurePrefsLoaded()   // F5: prefetch per-user UI stavu (filtry + preference tabulek)
  void keyboardShortcuts.load()
  window.addEventListener('keydown', onGlobalShortcut)
  desktopMql = window.matchMedia('(min-width: 1024px)')
  isDesktop.value = desktopMql.matches
  desktopMql.addEventListener('change', onDesktopChange)
  navResizeObserver = new ResizeObserver(evaluateDesktopNavFit)
  if (desktopNavHost.value) navResizeObserver.observe(desktopNavHost.value)
  void nextTick(evaluateDesktopNavFit)
  // Bez zapnutého účetnictví nemá smysl ani polling automatu — jeho badge visí
  // u položky, která v menu není.
  if (!clientExperience.value
      && supplierStore.currentSupplier?.accounting_mode === 'double_entry'
      && supplierStore.currentSupplier?.accounting_enabled !== false
      && auth.canRead('accounting')) {
    automationStore.startPolling()
  }
  try { versionInfo.value = await updateApi.publicVersion() } catch {}
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onGlobalShortcut)
  desktopMql?.removeEventListener('change', onDesktopChange)
  navResizeObserver?.disconnect()
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50" @keydown.esc="userMenuOpen = false; quickOpen = false">

    <!-- ═════════════════════ TOPBAR ═════════════════════ -->
    <header class="nav-inverted sticky top-0 z-30 bg-surface border-b border-neutral-200 shadow-md">
      <!-- Blokující stav instalace (H-31) — červená linka úplně nahoře, uvnitř
           připnuté lišty, takže nejde odrolovat. Nemá zavírací prvek a na
           self-hosted instalaci se nezobrazí vůbec. Výšku hlásí do
           `--instance-alert-h`, o kterou se odsadí připnutý sidebar. -->
      <InstancePreviewBar />
      <InstanceCriticalBar />
      <div class="h-12 px-3 flex items-center gap-1">
        <WorkspaceNavLink to="/" class="flex h-10 items-center gap-2 shrink-0 px-1.5 rounded-md hover:bg-neutral-100" @click="mobileOpen = false">
          <img src="/styles/logo.svg" alt="MyÚčto" class="w-7 h-7" />
          <span class="text-sm font-semibold leading-tight select-none" :class="sideNavigation ? 'inline' : 'inline lg:hidden 2xl:inline'">
            My<span class="text-primary-600">Účto</span><span class="text-neutral-400 font-normal">.cz</span>
          </span>
        </WorkspaceNavLink>

        <div
          ref="desktopNavHost"
          class="hidden lg:flex min-w-0 flex-1 self-stretch overflow-visible"
          :class="topNavigation ? 'visible ml-1' : 'invisible pointer-events-none'"
          :aria-hidden="!topNavigation"
        >
          <DesktopMenuBar
            :sections="orderedNav"
            :is-active="isActive"
            :can-create="canCreate"
            :create-target="createTarget"
            :shortcut-for="navShortcutLabel"
            :quick-new-label="t('nav.quick_new')"
            :menu-label="t('nav.main_menu')"
          />
        </div>

        <div class="ml-auto flex h-full shrink-0 items-center gap-1 text-sm">
          <WorkspaceLayoutToggle />

          <div v-if="quickActions.length > 0" class="relative hidden lg:block">
            <button
              type="button"
              class="cursor-pointer inline-flex w-8 h-8 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-primary-700"
              :class="{ 'bg-neutral-100 text-primary-700': quickOpen }"
              :aria-expanded="quickOpen"
              :aria-label="t('nav.quick_new')"
              :title="t('nav.quick_new')"
              @click="quickOpen = !quickOpen"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
              </svg>
            </button>
            <transition
              enter-active-class="transition duration-100 ease-out"
              enter-from-class="opacity-0 -translate-y-1"
              enter-to-class="opacity-100 translate-y-0"
              leave-active-class="transition duration-75 ease-in"
              leave-from-class="opacity-100 translate-y-0"
              leave-to-class="opacity-0 -translate-y-1"
            >
              <div v-if="quickOpen" class="absolute right-0 top-full mt-1 w-56 bg-surface border border-neutral-200 rounded-lg shadow-xl py-1 z-40">
                <WorkspaceNavLink
                  v-for="s in quickActions"
                  :key="s.to"
                  :to="s.to"
                  class="flex items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700"
                  @click="quickOpen = false"
                >
                  <svg class="w-4 h-4 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="s.icon" />
                  </svg>
                  <span>{{ s.label }}</span>
                </WorkspaceNavLink>
              </div>
            </transition>
            <div v-if="quickOpen" class="fixed inset-0 z-10" aria-hidden="true" @click="quickOpen = false"></div>
          </div>

          <a
            :href="manualHref"
            target="_blank"
            rel="noopener"
            class="hidden sm:inline-flex w-8 h-8 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-primary-700"
            :title="t('nav.help')"
            :aria-label="t('nav.help')"
          >
            <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" />
            </svg>
          </a>

          <div
            v-if="sideNavigation && supplierStore.hasMultiple"
            ref="sideSupplierHost"
            class="hidden lg:flex items-center gap-2"
          >
            <span
              class="mx-1 h-6 w-px bg-neutral-200 dark:bg-neutral-300"
              aria-hidden="true"
            ></span>
            <SupplierSwitcher
              placement="below"
              compact
              align="right"
            />
          </div>

          <div class="relative hidden lg:flex h-full items-center">
            <button
              type="button"
              class="cursor-pointer inline-flex max-w-44 items-center gap-1.5 h-8 px-2 rounded-md text-neutral-700 hover:bg-neutral-100 hover:text-primary-700"
              :class="{ 'bg-neutral-100 text-primary-700': userMenuOpen }"
              :aria-expanded="userMenuOpen"
              aria-haspopup="menu"
              @click="userMenuOpen = !userMenuOpen"
            >
              <span class="inline-flex w-6 h-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-700">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path fill-rule="evenodd" d="M12 2.75a5 5 0 1 0 0 10 5 5 0 0 0 0-10ZM4 20.25a8 8 0 0 1 16 0 1 1 0 0 1-1 1H5a1 1 0 0 1-1-1Z" clip-rule="evenodd" />
                </svg>
              </span>
              <span class="truncate">{{ auth.user?.name }}</span>
              <svg class="w-3 h-3 shrink-0 transition-transform" :class="{ 'rotate-180': userMenuOpen }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <transition
              enter-active-class="transition duration-100 ease-out"
              enter-from-class="opacity-0 -translate-y-1"
              enter-to-class="opacity-100 translate-y-0"
              leave-active-class="transition duration-75 ease-in"
              leave-from-class="opacity-100 translate-y-0"
              leave-to-class="opacity-0 -translate-y-1"
            >
              <div v-if="userMenuOpen" class="absolute right-0 top-full w-64 bg-surface border border-neutral-200 rounded-b-lg shadow-xl py-1.5 z-40" role="menu">
                <div class="px-3 pt-1 pb-2 border-b border-neutral-200 mb-1">
                  <div class="font-medium text-neutral-900 truncate">{{ auth.user?.name }}</div>
                  <div class="text-xs text-neutral-500 truncate">{{ auth.user?.email }}</div>
                </div>
                <template v-if="!auth.isDemo">
                  <WorkspaceNavLink to="/profile/password" class="flex items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700" role="menuitem">
                    <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 1 1 2 2m4 0a6 6 0 1 1-7.7 5.75L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.6a1 1 0 0 1 .3-.7l6-6A6 6 0 0 1 21 9z"/></svg>
                    {{ t('auth.change_password_title') }}
                  </WorkspaceNavLink>
                  <WorkspaceNavLink to="/profile/password?tab=totp" class="flex items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700" role="menuitem">
                    <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
                    {{ t('auth.totp_tab') }}
                  </WorkspaceNavLink>
                  <WorkspaceNavLink to="/profile/password?tab=passkeys" class="flex items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700" role="menuitem">
                    <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 1 1 2 2m4 0a6 6 0 1 1-7.7 5.75L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.6a1 1 0 0 1 .3-.7l6-6A6 6 0 0 1 21 9z"/></svg>
                    {{ t('passkeys.title') }}
                  </WorkspaceNavLink>
                  <WorkspaceNavLink to="/profile/password?tab=session-lock" class="flex items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700" role="menuitem">
                    <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
                    {{ t('session_lock.preference_tab') }}
                  </WorkspaceNavLink>
                  <WorkspaceNavLink to="/profile/password?tab=shortcuts" class="flex items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700" role="menuitem">
                    <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75zM6 9h.01M9 9h.01M12 9h.01M15 9h.01M18 9h.01M7 13h.01M10 13h.01M13 13h.01M16 13h.01M8 16h8"/></svg>
                    {{ t('keyboard_shortcuts.title') }}
                  </WorkspaceNavLink>
                </template>
                <button
                  v-if="canLockSession"
                  type="button"
                  class="cursor-pointer flex w-full items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700"
                  role="menuitem"
                  @click="userMenuOpen = false; sessionSecurity.lock()"
                >
                  <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 10V8a6 6 0 0 1 12 0v2m-11 0h10a2 2 0 0 1 2 2v7H5v-7a2 2 0 0 1 2-2Z"/></svg>
                  {{ t('session_lock.lock_now') }}
                </button>
                <div class="my-1 border-t border-neutral-200" aria-hidden="true"></div>
                <button
                  type="button"
                  class="cursor-pointer flex w-full items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-danger-600 disabled:opacity-60"
                  :disabled="logoutBusy"
                  role="menuitem"
                  @click="logout"
                >
                  <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3-6 3 3m0 0-3 3m3-3H9"/></svg>
                  {{ t('nav.logout') }}
                </button>
              </div>
            </transition>
            <div v-if="userMenuOpen" class="fixed inset-0 z-10" aria-hidden="true" @click="userMenuOpen = false"></div>
          </div>

          <button
            type="button"
            class="lg:hidden inline-flex items-center justify-center w-9 h-9 rounded-md text-neutral-700 hover:bg-neutral-100"
            :aria-expanded="mobileOpen"
            aria-label="Menu"
            @click="mobileOpen = !mobileOpen"
          >
            <svg v-if="!mobileOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <div v-if="supplierStore.hasMultiple && supplierStore.currentSupplier" class="lg:hidden bg-primary-50 border-t border-primary-100">
        <div class="px-3 py-1.5">
          <span class="sr-only">{{ t('supplier.active_label') }}</span>
          <SupplierSwitcher full-width />
        </div>
      </div>
    </header>

    <!-- ═════════════════════ TĚLO: SIDEBAR + OBSAH ═════════════════════ -->
    <div class="flex flex-1 min-h-0">

      <!-- Mobile backdrop -->
      <div
        v-if="mobileOpen" @click="mobileOpen = false"
        class="lg:hidden fixed inset-0 bg-black/50 z-20"
        aria-hidden="true"
      ></div>

      <!-- ── SIDEBAR ──
           `--instance-alert-h` = výška červené linky nad aplikací (0 px, když
           není). Bez ní by připnutý sidebar začínal o linku výš a přišel
           o první položku menu. -->
      <aside
        :class="[
          'fixed z-30 lg:sticky lg:top-[calc(var(--instance-alert-h,0px)+3rem)] lg:h-[calc(100vh-3rem-var(--instance-alert-h,0px))]',
          supplierStore.hasMultiple && supplierStore.currentSupplier
            ? 'top-[calc(var(--instance-alert-h,0px)+5.125rem)] h-[calc(100vh-5.125rem-var(--instance-alert-h,0px))]'
            : 'top-[calc(var(--instance-alert-h,0px)+3rem)] h-[calc(100vh-3rem-var(--instance-alert-h,0px))]',
          'w-full lg:w-60 shrink-0',
          'nav-inverted bg-surface border-r border-neutral-200 shadow-lg lg:shadow-none',
          'flex flex-col',
          'transition-transform duration-200 ease-in-out',
          sideNavigation ? 'lg:flex lg:translate-x-0 lg:z-auto' : 'lg:hidden',
          mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
        ]"
      >
        <nav class="flex-1 overflow-y-auto scrollbar-slim px-2.5 py-3">
          <!-- Globální vyhledávač (před Přehled) — našeptává menu + hledá klienty/faktury -->
          <div class="lg:hidden">
            <GlobalSearch ref="mobileSearchRef" :menu-items="flatNavItems" @navigated="mobileOpen = false" />
          </div>

          <template v-for="(section, si) in orderedNav" :key="section.key">
            <!-- Section title — soft pill; §10: chytni za hlavičku a přetáhni celý blok -->
            <div
              v-if="section.title"
              :class="[
                si === 0 ? 'pt-1 pb-1.5' : 'pt-2.5 pb-1.5 mt-2',
                // Hairline mezi sekcemi. Při přetahování ho nahradí silnější
                // primary linka — jinak by se obě třídy braly o stejnou prioritu.
                nav.isSectionDropTarget(section.key)
                  ? 'border-t-2 border-primary-500'
                  : (si === 0 ? '' : 'border-t border-neutral-100'),
              ]"
              @dragover="nav.onSectionDragOver($event, section.key)"
              @drop="nav.onSectionDrop($event, section.key)"
            >
              <div
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider select-none text-neutral-600"
                :class="isDesktop ? 'cursor-move hover:bg-neutral-100' : ''"
                :draggable="isDesktop"
                @dragstart="nav.onSectionDragStart($event, section.key)"
                @dragend="nav.onDragEnd()"
                :aria-label="isDesktop ? t('common.nav_drag_section', { name: section.title }) : undefined"
              >
                <span
                  class="w-1.5 h-1.5 rounded-full shrink-0"
                  :class="section.accent ? ACCENT_DOT[section.accent] : 'bg-neutral-400'"
                  aria-hidden="true"
                ></span>
                {{ section.title }}
                <svg v-if="isDesktop" class="w-3 h-3 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 9h16M4 15h16" />
                </svg>
              </div>
            </div>

            <!-- Items: external (např. Nápověda → /manual v novém tabu) vs internal route.
                 Levá lišta v barvě sekce váže položky k hlavičce nad nimi; sekce
                 bez nadpisu (Nápověda) ji nemá, není co rámovat. -->
            <div
              :class="section.title
                ? ['ml-2 border-l-2', section.accent ? ACCENT_RAIL[section.accent] : 'border-neutral-200']
                : ''"
            >
            <template v-for="item in section.items" :key="item.to">
              <!-- Vizuální předěl mezi skupinami uvnitř sekce (např. měsíční mzdová
                   práce ↔ jednorázové nastavení). Postranní menu ho do teď ignorovalo,
                   i když `NavItem.dividerBefore` existuje a horní lišta ho kreslí. -->
              <div v-if="item.dividerBefore" class="my-1.5 border-t border-neutral-200" aria-hidden="true"></div>
              <a
                v-if="item.external"
                :href="item.to"
                target="_blank"
                rel="noopener"
                class="flex items-center gap-2.5 px-2.5 py-[7px] rounded-md text-sm transition-colors leading-tight text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100"
              >
                <svg class="w-[15px] h-[15px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                {{ item.label }}
                <svg class="w-3 h-3 ml-auto text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
              </a>
              <div
                v-else
                class="relative group rounded-md"
                :class="nav.isItemDropTarget(section.key, item.to) ? 'ring-2 ring-primary-400 bg-primary-50' : ''"
                :draggable="isDesktop"
                @dragstart="nav.onItemDragStart($event, section.key, item.to)"
                @dragover="nav.onItemDragOver($event, section.key, item.to)"
                @drop="nav.onItemDrop($event, section.key, item.to)"
                @dragend="nav.onDragEnd()"
                :aria-label="isDesktop ? t('common.nav_drag_item', { name: item.label }) : undefined"
              >
                <WorkspaceNavLink
                  :to="item.to"
                  :draggable="false"
                  active-class=""
                  exact-active-class=""
                  class="flex items-center gap-2.5 px-2.5 py-[7px] rounded-md text-sm transition-colors leading-tight"
                  :class="[
                    isActive(item)
                      ? 'bg-primary-50 text-primary-700 font-medium'
                      : item.accent
                        ? [ACCENT_ITEM[item.accent], 'font-medium']
                        : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100',
                    canCreate(item) ? 'pr-8' : '',
                  ]"
                >
                  <svg class="w-[15px] h-[15px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                  </svg>
                  {{ item.label }}
                  <!-- Tečka „je co řešit". Barvu určuje závažnost, ne položka —
                       viz `hostingNavAttention`. -->
                  <span
                    v-if="item.attention"
                    class="ml-auto inline-block h-2 w-2 shrink-0 rounded-full"
                    :class="item.attention === 'danger' ? 'bg-danger-500' : 'bg-warning-500'"
                    :title="t(`nav.attention_${item.attention}`)"
                    :aria-label="t(`nav.attention_${item.attention}`)"
                  ></span>
                  <span v-else-if="item.badge" class="ml-auto min-w-5 rounded-full bg-warning-500/20 px-1.5 py-0.5 text-center text-[10px] font-semibold text-warning-600">{{ item.badge }}</span>
                </WorkspaceNavLink>
                <!-- Rychlé „+" (vytvořit nový) — skryté, odhalí se až při hoveru nad položkou -->
                <WorkspaceNavLink
                  v-if="canCreate(item)"
                  :to="createTarget(item)"
                  :draggable="false"
                  :title="t('nav.quick_new')"
                  :aria-label="t('nav.quick_new')"
                  class="absolute right-1.5 top-1/2 -translate-y-1/2 inline-flex items-center justify-center w-5 h-5 rounded-md text-neutral-400 hover:text-primary-700 hover:bg-primary-100 transition-all opacity-100 lg:opacity-0 lg:group-hover:opacity-100 focus:opacity-100"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v8m4-4H8M6 4h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z" />
                  </svg>
                </WorkspaceNavLink>
              </div>
            </template>
            </div>
          </template>

          <!-- §10: obnovit výchozí pořadí menu (jen desktop, kde je reorder aktivní) -->
          <div v-if="isDesktop" class="pt-3 mt-2 border-t border-neutral-100">
            <button
              type="button"
              @click="onResetNavOrder"
              class="cursor-pointer flex items-center gap-2.5 w-full px-2.5 py-[7px] rounded-md text-xs text-neutral-500 hover:text-neutral-800 hover:bg-neutral-100 transition-colors"
              :title="t('common.nav_reset_order')"
            >
              <svg class="w-[15px] h-[15px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.updates" />
              </svg>
              {{ t('common.nav_reset_order') }}
            </button>
          </div>
        </nav>

        <!-- Mobile only: profil + ovládání relace (na dně sidebaru) -->
        <div class="lg:hidden border-t border-neutral-200 px-4 py-3 bg-neutral-50 space-y-3">
          <div class="flex items-center justify-between">
            <WorkspaceNavLink
              to="/profile/password"
              @click="mobileOpen = false"
              class="group min-w-0 flex-1 rounded-md -ml-2 px-2 py-1.5 text-sm hover:bg-surface"
              :title="t('auth.profile_title')"
            >
              <div class="truncate font-medium text-neutral-900 group-hover:text-primary-700 group-hover:underline">
                {{ auth.user?.name }}
              </div>
              <div class="truncate text-xs text-neutral-500">{{ auth.user?.email }} · {{ auth.user?.role?.name }}</div>
            </WorkspaceNavLink>
            <a
              :href="manualHref" target="_blank" rel="noopener"
              class="inline-flex w-9 h-9 items-center justify-center rounded-md text-neutral-600 hover:bg-surface"
              :title="t('nav.help')"
            >
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" />
              </svg>
            </a>
          </div>
          <div class="grid gap-2" :class="canLockSession ? 'grid-cols-2' : 'grid-cols-1'">
            <button
              v-if="canLockSession"
              @click="sessionSecurity.lock"
              class="cursor-pointer w-full px-2 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface"
            >{{ t('session_lock.lock_now') }}</button>
            <button
              @click="logout"
              :disabled="logoutBusy"
              class="cursor-pointer w-full px-2 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface disabled:opacity-60"
            >{{ t('nav.logout') }}</button>
          </div>
          <!-- Klávesové zkratky tu byly, ale na dotykovém zařízení je není čím
               vyvolat — odkaz jen zabíral místo na dně zásuvky. Na desktopu
               zůstávají v uživatelském menu. -->
        </div>
      </aside>

      <!-- ── HLAVNÍ OBSAH ── -->
      <div class="flex-1 min-w-0 flex flex-col">
        <!-- Paleta příkazů (Ctrl/⌘+K) — sama si registruje zkratku i teleport,
             stačí ji mít v layoutu jednou. -->
        <CommandPalette ref="paletteRef" :nav-items="paletteNavItems" :quick-actions="quickActions" />

        <main
          class="flex-1 px-5 sm:px-8 py-6 w-full"
          :class="workspace.paneCount > 1 ? 'workspace-main-multi' : ''"
          :data-accent="activeSectionAccent"
        >
          <div v-if="auth.isDemo" class="mb-5 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-800">
            {{ t('demo.banner') }}
          </div>
          <!-- H-10: docházející / vyčerpaný diskový prostor. Sám se skryje,
               dokud backend nic nehlásí (zdravá instalace, vypnutý režim nebo
               zatím NEZMĚŘENÁ spotřeba). -->
          <StorageQuotaBanner />
          <div
            v-if="licenseBanner"
            class="mb-5 rounded-lg border px-4 py-3 text-sm flex flex-wrap items-center justify-between gap-2"
            :class="BANNER_CLASS[licenseBanner.variant]"
          >
            <span>{{ licenseBanner.text }}</span>
            <WorkspaceNavLink v-if="auth.isSuperadmin && !clientExperience" to="/activation/purchase" class="font-medium underline whitespace-nowrap">
              {{ auth.license?.state === 'overage' ? t('license.banner_upgrade_cta') : t('license.banner_cta') }}
            </WorkspaceNavLink>
          </div>
          <WorkspaceHost>
            <template #primary="{ revision }">
              <PaneActivityScope pane-id="primary">
                <RouterView v-slot="{ Component }">
                  <component :is="Component" :key="revision" />
                </RouterView>
              </PaneActivityScope>
            </template>
          </WorkspaceHost>
        </main>

        <!-- Patička je stejné „chrome" jako topbar (globální hledání, jazyk, motiv),
             takže nosí i stejnou tmavou plochu. Světlá patička pod tmavou lištou
             působila jako nedodělaná půlka — takhle obsah rámují z obou stran. -->
        <footer class="nav-inverted sticky bottom-0 z-20 border-t border-neutral-200 bg-surface shadow-[0_-2px_10px_rgba(21,19,29,0.10)]">
          <div class="hidden lg:grid h-11 grid-cols-[minmax(14rem,1fr)_auto_minmax(23rem,1fr)] items-center gap-4 px-3">
            <div class="flex w-full max-w-lg items-center gap-2">
              <div class="min-w-0 flex-1">
                <GlobalSearch
                  ref="desktopSearchRef"
                  :menu-items="flatNavItems"
                  placement="above"
                  compact
                  :shortcut="searchShortcutLabel"
                />
              </div>
              <!-- Viditelné tlačítko palety. Klávesová zkratka sama o sobě je
                   neobjevitelná — kdo ji nezná, nedozví se o ní. Tohle je ta
                   jediná spolehlivá cesta: prvek na místě, kam uživatel kouká,
                   když chce něco najít nebo založit. -->
              <button
                type="button"
                class="cursor-pointer hidden xl:inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border border-neutral-200 px-2 text-[11px] leading-none text-neutral-500 transition-colors hover:bg-neutral-50 hover:text-neutral-700"
                :title="t('command.title')"
                @click="paletteRef?.show()"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3" />
                  <rect x="3" y="4" width="18" height="16" rx="2" />
                </svg>
                <kbd class="font-sans">Ctrl+K</kbd>
              </button>
            </div>

            <!-- Tip stojí vedle přepínače firem, ne místo něj: uživatel s víc
                 firmami by se jinak k tipům nikdy nedostal. Na užších obrazovkách
                 ustoupí, tam má přednost přepínač. -->
            <div class="flex min-w-0 items-center justify-center gap-3">
              <SupplierSwitcher v-if="topNavigation && supplierStore.hasMultiple" placement="above" compact />
              <FooterTip class="hidden 2xl:flex" />
            </div>

            <div class="flex min-w-0 items-center justify-end gap-1.5 text-[11px] leading-none text-neutral-500">
              <button
                type="button"
                class="cursor-pointer h-8 w-8 inline-flex items-center justify-center rounded-md border border-neutral-200 text-neutral-500 hover:bg-neutral-50 hover:text-neutral-700 disabled:cursor-not-allowed disabled:opacity-45"
                :disabled="autoSideNavigation && !preferSideNavigation"
                :title="autoSideNavigation && !preferSideNavigation ? t('nav.menu_top_unavailable') : t(sideNavigation ? 'nav.menu_top' : 'nav.menu_side')"
                :aria-label="autoSideNavigation && !preferSideNavigation ? t('nav.menu_top_unavailable') : t(sideNavigation ? 'nav.menu_top' : 'nav.menu_side')"
                @click="toggleNavigationLayout"
              >
                <svg v-if="sideNavigation" class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18v4H3V4zm0 8h5v8H3v-8zm9 0h9m-9 4h9m-9 4h9" />
                </svg>
                <svg v-else class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h5v16H3V4zm9 0h9m-9 4h9m-9 4h9m-9 4h9m-9 4h9" />
                </svg>
              </button>
              <LanguageToggle />
              <ThemeToggle />
              <span class="mx-1 h-5 w-px bg-neutral-200" aria-hidden="true"></span>
              <a href="https://myucto.cz/" target="_blank" rel="noopener"
                 class="whitespace-nowrap hover:text-primary-700 hover:underline transition-colors"
                 title="MyÚčto.cz">MyÚčto.cz</a>
              <template v-if="versionInfo">
                <WorkspaceNavLink
                  v-if="auth.isSuperadmin && !clientExperience"
                  to="/admin/update"
                  class="inline-flex items-center gap-1 text-neutral-400 hover:text-neutral-600 transition-colors"
                  :title="t('updates.title')"
                >
                  <span>v{{ versionInfo.current }}</span>
                  <span
                    v-if="versionInfo.has_update"
                    class="inline-flex items-center gap-1 rounded-full bg-primary-100 text-primary-700 px-1.5 py-0.5 text-[10px] font-semibold leading-none"
                  >
                    <svg class="w-2 h-2" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6"/></svg>
                    v{{ versionInfo.latest }}
                  </span>
                </WorkspaceNavLink>
                <span v-else class="text-neutral-400">v{{ versionInfo.current }}</span>
              </template>
              <span aria-hidden="true">·</span>
              <a href="https://mywebdesign.cz" target="_blank" rel="noopener" class="hidden xl:inline whitespace-nowrap hover:text-neutral-700">© MyWebdesign.cz</a>
              <span class="hidden xl:inline" aria-hidden="true">·</span>
              <WorkspaceNavLink v-if="!clientExperience" to="/admin/support" :class="supportBtnClass" :title="t('support.help_title')">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" />
                </svg>
                {{ t('support.help_link') }}
              </WorkspaceNavLink>
            </div>
          </div>

          <div class="lg:hidden min-h-12 px-3 py-2 flex flex-wrap items-center justify-between gap-2 text-[11px] text-neutral-500">
            <div class="flex items-center gap-1.5">
              <LanguageToggle />
              <ThemeToggle />
            </div>
            <div class="flex flex-wrap items-center justify-end gap-1.5">
              <a href="https://myucto.cz/" target="_blank" rel="noopener" class="hover:text-primary-700">MyÚčto.cz</a>
              <span v-if="versionInfo" class="text-neutral-400">v{{ versionInfo.current }}</span>
              <WorkspaceNavLink v-if="!clientExperience" to="/admin/support" :class="supportBtnClass" :title="t('support.help_title')">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" />
                </svg>
                {{ t('support.help_link') }}
              </WorkspaceNavLink>
            </div>
          </div>
        </footer>
      </div>
    </div>

    <!-- ── MODÁL: Podpora autora ── -->
    <div v-if="supportOpen" class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto"
         @click.self="supportOpen = false">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full my-8">
        <header class="px-5 py-4 border-b border-neutral-200 flex items-baseline justify-between gap-3">
          <h3 class="text-lg font-semibold">{{ t('support.author_title') }}</h3>
          <button @click="supportOpen = false" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
        </header>
        <div class="p-5 space-y-4 text-sm text-neutral-700">
          <p>{{ t('support.author_intro') }}</p>
          <dl class="space-y-1.5">
            <div class="flex flex-wrap gap-x-2">
              <dt class="text-neutral-500 w-28 shrink-0">{{ t('support.account') }}</dt>
              <dd class="font-medium">7700000038 / 6363 <span class="text-neutral-400 font-normal">({{ t('support.bank_name') }})</span></dd>
            </div>
            <div class="flex flex-wrap gap-x-2">
              <dt class="text-neutral-500 w-28 shrink-0">{{ t('support.iban') }}</dt>
              <dd class="font-medium">CZ21 6363 0000 0077 0000 0038</dd>
            </div>
            <div class="flex flex-wrap gap-x-2">
              <dt class="text-neutral-500 w-28 shrink-0">{{ t('support.bic') }}</dt>
              <dd class="font-medium">PTBNCZPP</dd>
            </div>
          </dl>
          <div>
            <p class="mb-2">{{ t('support.qr_hint') }}</p>
            <img src="/manual/donate/qrcode.jpg" :alt="t('support.author_title')"
                 class="w-full h-auto rounded-md border border-neutral-200"
                 style="filter: brightness(1.08);" />
          </div>
        </div>
        <footer class="px-5 py-4 border-t border-neutral-200 flex justify-end">
          <button @click="supportOpen = false"
                  class="cursor-pointer px-4 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface">{{ t('support.close') }}</button>
        </footer>
      </div>
    </div>

    <!-- ── MODÁL: Chcete jinou funkci? ── -->
    <div v-if="featureOpen" class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto"
         @click.self="featureOpen = false">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full my-8">
        <header class="px-5 py-4 border-b border-neutral-200 flex items-baseline justify-between gap-3">
          <h3 class="text-lg font-semibold">{{ t('support.feature_title') }}</h3>
          <button @click="featureOpen = false" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
        </header>
        <div class="p-5 space-y-3 text-sm text-neutral-700">
          <p>{{ t('support.feature_intro') }}</p>
          <p>{{ t('support.feature_text') }}</p>
          <p class="rounded-md bg-primary-50 border border-primary-500/30 text-primary-800 font-medium px-3 py-2.5">{{ t('support.feature_text2') }}</p>
          <p class="text-xs text-neutral-500 border-t border-neutral-200 pt-3">{{ t('support.feature_highlights') }}</p>
        </div>
        <footer class="px-5 py-4 border-t border-neutral-200 flex justify-end gap-2">
          <button @click="featureOpen = false"
                  class="cursor-pointer px-4 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-surface">{{ t('support.close') }}</button>
          <a href="https://mywebdesign.cz/#kontakt" target="_blank" rel="noopener" @click="featureOpen = false"
             class="cursor-pointer px-4 h-9 inline-flex items-center text-sm rounded-md bg-primary-600 hover:bg-primary-700 text-white font-medium">{{ t('support.feature_cta') }}</a>
        </footer>
      </div>
    </div>
  </div>
</template>
