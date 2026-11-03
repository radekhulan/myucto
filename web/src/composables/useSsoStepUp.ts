import { computed, inject, ref, toValue, type MaybeRefOrGetter } from 'vue'
import { routeLocationKey } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { oidcApi, type ExternalIdentity } from '@/api/oidc'
import {
  hasStrongProvider,
  rememberPendingStepUp,
  ssoReturnPath,
  takeSsoStepUpProof,
} from '@/security/oidc'
import { navigateFullPage } from '@/workspace/fullPageNavigation'

const PROVIDER = 'entra'

// Propojené identity se načtou nejvýš jednou za sezení stránky; seznam se
// mění jen v profilu, a ten ho po změně obnoví přes invalidateSsoIdentities().
let identitiesPromise: Promise<ExternalIdentity[]> | null = null
const identities = ref<ExternalIdentity[] | null>(null)

function loadIdentities(): Promise<ExternalIdentity[]> {
  identitiesPromise ??= oidcApi.identities()
    .then((list) => {
      identities.value = list
      return list
    })
    .catch(() => {
      identitiesPromise = null
      identities.value = []
      return []
    })
  return identitiesPromise
}

export function invalidateSsoIdentities(list?: ExternalIdentity[]): void {
  identitiesPromise = list ? Promise.resolve(list) : null
  identities.value = list ?? null
}

/**
 * Ověření citlivé operace přes účet organizace (Microsoft Entra ID).
 *
 * Doplňuje passkey a TOTP: vrací stejný proof (`step_up_token`), jen cesta
 * vede přes přesměrování k poskytovateli a zpět na stejnou stránku. Po návratu
 * stránka proof vyzvedne přes `takeProof()` a pokračuje v rozdělané akci.
 */
export function useSsoStepUp(operation: string, context: MaybeRefOrGetter<string> = '') {
  const auth = useAuthStore()
  // Stránky vložené do jiné obrazovky běží i bez routeru (např. mzdový archiv podání).
  const route = inject(routeLocationKey, null)
  const busy = ref(false)
  const error = ref('')

  // Ověřit operaci smí jen silný poskytovatel; Google, Apple a Seznam jsou jen první faktor.
  const enabled = computed(() => hasStrongProvider(auth.setupStatus?.sso_providers, PROVIDER))
  const available = computed(() => enabled.value
    && (identities.value ?? []).some(identity => identity.provider === PROVIDER))

  if (enabled.value && identities.value === null) void loadIdentities()

  async function start(): Promise<void> {
    if (!enabled.value || busy.value) return
    busy.value = true
    error.value = ''
    const path = ssoReturnPath(route?.fullPath ?? `${window.location.pathname}${window.location.search}`)
    try {
      rememberPendingStepUp(operation, path, Date.now(), toValue(context))
      const url = await oidcApi.stepUp(PROVIDER, operation, path)
      navigateFullPage(url)
    } catch (e: any) {
      busy.value = false
      error.value = e?.response?.data?.error?.code ?? 'generic'
    }
  }

  return {
    enabled,
    available,
    busy,
    /** Kód chyby zahájení (např. `sso_not_linked`), prázdné = bez chyby. */
    error,
    start,
    takeProof: () => takeSsoStepUpProof(operation, toValue(context)),
  }
}
