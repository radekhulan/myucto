import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi, type User, type SetupStatus, type SessionState, type DomainContext } from '@/api/auth'
import type { LicenseSummary } from '@/api/license'
import { setCsrfToken, setDomainSupplierLock } from '@/api/client'
import { broadcastSessionEvent } from '@/security/sessionChannel'
import { useSupplierStore } from './supplier'
import { accessLevelValue, type AccessLevel, type PermissionKey, type PermissionValue } from '@/security/permissions'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const csrfToken = ref<string>('')
  const setupStatus = ref<SetupStatus | null>(null)
  const allowedMfaMethods = ref<Array<'passkey' | 'totp'>>([])
  const requireMfa = ref(false)
  const loading = ref(false)
  const permissions = ref<Partial<Record<PermissionKey, PermissionValue>>>({})
  const permissionCatalogVersion = ref('')
  const permissionsLoading = ref(false)
  const license = ref<LicenseSummary | null>(null)
  const lockedSession = ref<SessionState | null>(null)
  const profileHydrated = ref(false)
  const domainContext = ref<DomainContext | null>(null)
  let logoutRetryCsrfToken = ''
  let logoutPromise: Promise<void> | null = null

  const isAuthenticated = computed(() => user.value !== null)
  const needsSetup = computed(() => setupStatus.value?.needs_setup === true)
  const isDemo = computed(() => setupStatus.value?.demo?.enabled === true)
  // Spravovaná instalace (H-02). Fail-open na `false` je tu správně: zámek drží
  // backend a UI podle tohohle příznaku jen VYSVĚTLUJE, proč akce není. Kdyby
  // se fail-closed tvářilo jako spravované, self-hosted instalace by přišla
  // o tlačítko Aktualizovat vždycky, když setup-status ještě nedorazil.
  const isManagedInstallation = computed(() => setupStatus.value?.managed === true)
  const mustSetupTotp = computed(() => user.value?.must_setup_totp === true)
  const mustSetupMfa = computed(() => user.value?.must_setup_mfa === true)
  // Dobrovolná nabídka MFA. Na rozdíl od `mustSetupMfa` NIC neblokuje — router
  // podle ní jen otevře /setup-mfa tomu, kdo tam přijde po přihlášení. Fail-closed
  // na `false`: dokud server nabídku nepotvrdí, nikoho nikam neposíláme.
  const shouldOfferMfa = computed(() => user.value?.should_offer_mfa === true)
  const hasCommercialFeatures = computed(() => license.value?.commercial_features !== false)
  // ⚠️ Odemyká placené moduly TARIF? Bez toho obrazovka nerozliší „licence
  // propadla, zaplaťte" od „tenhle tarif to nikdy neměl" — a bezplatnému
  // tarifu nabízí zaplatit něco, co má zaplacené. Fail-open na `true` je
  // tady správně: starší token pole nenese a všechny takové licence jsou placené.
  const tierUnlocksCommercial = computed(() => license.value?.tier_commercial !== false)
  /** Proč nejde přidat dalšího zapisujícího uživatele. `null` = jde to. */
  const newUserBlocked = computed(() => license.value?.new_user_blocked ?? null)

  // Vlastní domény jsou opt-in v cfg.php. Než dorazí domain-context, tváříme
  // se jako vypnuté — plocha se raději objeví pozdě než nabídne to, co
  // backend stejně odmítne 404.
  const domainsFeatureEnabled = computed(() => domainContext.value?.feature_enabled === true)

  const isSuperadmin = computed(() => user.value?.is_superadmin === true)
  const isClientRole = computed(() => user.value?.role?.type === 'client')
  // Systémová role „účetní" — mění jen výchozí pořadí sekcí v menu, ne oprávnění.
  const isAccountantRole = computed(() => user.value?.role?.system_key === 'accountant')

  function can(permission: PermissionKey, level: AccessLevel = 'read'): boolean {
    if (permissionsLoading.value) return false
    if (isSuperadmin.value) return true
    return (permissions.value[permission] ?? 0) >= accessLevelValue[level]
  }
  function canRead(permission: PermissionKey): boolean { return can(permission, 'read') }
  function canWrite(permission: PermissionKey): boolean { return can(permission, 'write') }
  function clearPermissions(): void {
    permissions.value = {}
    permissionCatalogVersion.value = ''
  }

  async function fetchSetupStatus() {
    setupStatus.value = await authApi.setupStatus()
    // Na úplně čerstvé instalaci ještě nemusí existovat tabulka domén a setup
    // záměr schválně povoluje přístup i z LAN hostname odlišného od app.url.
    // Tenant kontext proto načítáme až po dokončení prvotního setupu.
    if (!setupStatus.value.needs_setup) {
      await fetchDomainContext()
    } else {
      domainContext.value = null
      setDomainSupplierLock(null)
    }
    return setupStatus.value
  }

  async function fetchDomainContext() {
    domainContext.value = await authApi.domainContext()
    setDomainSupplierLock(domainContext.value.locked ? domainContext.value.supplier_id : null)
    return domainContext.value
  }

  function setSessionCsrfToken(token: string) {
    csrfToken.value = token
    setCsrfToken(token || null)
    if (token) logoutRetryCsrfToken = ''
  }

  function setMfaPolicy(required: boolean, methods: Array<'passkey' | 'totp'>) {
    requireMfa.value = required
    allowedMfaMethods.value = methods
  }

  async function refresh() {
    permissionsLoading.value = true
    clearPermissions()
    try {
      await fetchDomainContext()
      const data = await authApi.me()
      user.value = data.user
      setSessionCsrfToken(data.csrf_token)
      setMfaPolicy(data.require_mfa, data.allowed_mfa_methods)
      permissions.value = data.permissions || {}
      permissionCatalogVersion.value = data.permission_catalog_version || ''
      license.value = data.license || null
      lockedSession.value = null
      profileHydrated.value = true
      domainContext.value = data.domain_context && domainContext.value
        ? { ...domainContext.value, ...data.domain_context }
        : data.domain_context || domainContext.value
      setDomainSupplierLock(domainContext.value?.locked ? domainContext.value.supplier_id : null)
      useSupplierStore().setAvailable(
        data.suppliers || [],
        data.current_supplier_id || 0,
        domainContext.value?.locked === true,
      )
      return true
    } catch (error: any) {
      if (error?.response?.status === 423
          && error?.response?.data?.error?.code === 'session_locked') {
        try {
          const session = await authApi.sessionStatus()
          if (session.session_state === 'locked') {
            user.value = session.user
            setSessionCsrfToken(session.csrf_token)
            lockedSession.value = session
            profileHydrated.value = false
            return true
          }
        } catch {
          if (user.value !== null) return false
        }
      }
      if (!error?.response && user.value !== null) return false
      user.value = null
      setSessionCsrfToken('')
      setMfaPolicy(false, [])
      license.value = null
      lockedSession.value = null
      profileHydrated.value = false
      useSupplierStore().setAvailable([], 0, domainContext.value?.locked === true)
      return false
    } finally {
      permissionsLoading.value = false
    }
  }

  async function login(
    email: string,
    password: string,
    captchaToken?: string,
    totp?: string,
    opts?: { emailOtp?: string; rememberDevice?: boolean; resendOtp?: boolean; recoveryCode?: string },
  ) {
    loading.value = true
    try {
      const data = await authApi.login({
        email,
        password,
        totp: totp || undefined,
        recovery_code: opts?.recoveryCode || undefined,
        email_otp: opts?.emailOtp || undefined,
        remember_device: opts?.rememberDevice || undefined,
        resend_otp: opts?.resendOtp || undefined,
        cf_turnstile_response: captchaToken,
      })
      user.value = data.user
      setSessionCsrfToken(data.csrf_token)
      setMfaPolicy(data.require_mfa, data.allowed_mfa_methods)
      lockedSession.value = null
      profileHydrated.value = false
      if (isDemo.value) localStorage.removeItem('myinvoice.current_supplier_id')
      // Po loginu načti suppliery (login response je nemá, /me je vrátí)
      await refresh()
      return data.user
    } finally {
      loading.value = false
    }
  }

  function clearPrivateState() {
    user.value = null
    csrfToken.value = ''
    setCsrfToken(null)
    setMfaPolicy(false, [])
    lockedSession.value = null
    profileHydrated.value = false
    license.value = null
    clearPermissions()
    useSupplierStore().setAvailable([], 0, domainContext.value?.locked === true)
  }

  async function performLogout() {
    const requestCsrfToken = logoutRetryCsrfToken || csrfToken.value
    if (requestCsrfToken) setCsrfToken(requestCsrfToken)

    try {
      await authApi.logout()
      logoutRetryCsrfToken = ''
      broadcastSessionEvent('logout')
    } catch (error) {
      logoutRetryCsrfToken = requestCsrfToken
      throw error
    } finally {
      clearPrivateState()
    }
  }

  function logout(): Promise<void> {
    if (logoutPromise) return logoutPromise

    const pending = performLogout()
    logoutPromise = pending
    void pending.then(
      () => {
        if (logoutPromise === pending) logoutPromise = null
      },
      () => {
        if (logoutPromise === pending) logoutPromise = null
      },
    )
    return pending
  }

  function setLockedSession(session: SessionState | null) {
    lockedSession.value = session
  }

  return {
    user,
    csrfToken,
    setupStatus,
    allowedMfaMethods,
    requireMfa,
    loading,
    license,
    lockedSession,
    profileHydrated,
    domainContext,
    domainsFeatureEnabled,
    isAuthenticated,
    needsSetup,
    isDemo,
    isManagedInstallation,
    mustSetupTotp,
    mustSetupMfa,
    shouldOfferMfa,
    hasCommercialFeatures,
    tierUnlocksCommercial,
    newUserBlocked,
    permissions,
    permissionCatalogVersion,
    permissionsLoading,
    isSuperadmin,
    isClientRole,
    isAccountantRole,
    can,
    canRead,
    canWrite,
    clearPermissions,
    fetchSetupStatus,
    fetchDomainContext,
    setSessionCsrfToken,
    setMfaPolicy,
    setLockedSession,
    refresh,
    login,
    logout,
  }
})
