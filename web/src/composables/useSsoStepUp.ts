import { computed, getCurrentScope, onScopeDispose, ref, toValue, type MaybeRefOrGetter } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { oidcApi, type ExternalIdentity } from '@/api/oidc'
import {
  hasStrongProvider,
  newSsoStepUpNonce,
  SSO_STEP_UP_CHANNEL,
  SSO_STEP_UP_POPUP_PATH,
  takeSsoStepUpProof,
  type SsoStepUpMessage,
} from '@/security/oidc'

const PROVIDER = 'entra'
/** Jak dlouho dialog čeká na výsledek z okna; Entra drží přihlášení řádově minuty. */
const WAIT_LIMIT_MS = 10 * 60 * 1000

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
 * Doplňuje passkey a TOTP: vrací stejný proof (`step_up_token`). Přihlášení
 * u poskytovatele běží v novém okně, takže rozdělaný dialog i panel pracovní
 * plochy zůstanou, jak byly. Výsledek dostane `onVerified`.
 */
export function useSsoStepUp(
  operation: string,
  context: MaybeRefOrGetter<string> = '',
  onVerified?: (token: string) => void,
) {
  const auth = useAuthStore()
  const busy = ref(false)
  /** Okno ověření je otevřené a dialog čeká na jeho výsledek. */
  const waiting = ref(false)
  const error = ref('')

  // Ověřit operaci smí jen silný poskytovatel; Google, Apple a Seznam jsou jen první faktor.
  const enabled = computed(() => hasStrongProvider(auth.setupStatus?.sso_providers, PROVIDER))
  const available = computed(() => enabled.value
    && (identities.value ?? []).some(identity => identity.provider === PROVIDER))
  /** O dostupnosti je rozhodnuto: poskytovatel je vypnutý, nebo jsou identity načtené. */
  const settled = computed(() => !enabled.value || identities.value !== null)

  if (enabled.value && identities.value === null) void loadIdentities()

  let channel: BroadcastChannel | null = null
  let timer: ReturnType<typeof setTimeout> | null = null

  function stopWaiting(): void {
    channel?.close()
    channel = null
    if (timer !== null) clearTimeout(timer)
    timer = null
    waiting.value = false
  }

  function listen(nonce: string): void {
    stopWaiting()
    channel = new BroadcastChannel(SSO_STEP_UP_CHANNEL)
    channel.onmessage = (event: MessageEvent<SsoStepUpMessage>) => {
      const data = event.data
      // Zprávu z okna jiného dialogu (jiný nonce) nebo jiné operace ignoruj.
      if (data?.nonce !== nonce) return
      stopWaiting()
      if (typeof data.error === 'string' && data.error !== '') {
        error.value = data.error
      } else if (data.operation === operation && typeof data.token === 'string' && data.token !== '') {
        onVerified?.(data.token)
      } else {
        error.value = 'sso_flow_invalid'
      }
    }
    waiting.value = true
    timer = setTimeout(stopWaiting, WAIT_LIMIT_MS)
  }

  async function start(): Promise<void> {
    if (!enabled.value || busy.value) return
    error.value = ''
    if (typeof BroadcastChannel === 'undefined') {
      error.value = 'popup_unsupported'
      return
    }
    // Okno se otevře hned při kliknutí, jinak by ho prohlížeč po čekání na API zablokoval.
    const popup = window.open('about:blank', '_blank', 'popup=yes,width=520,height=720')
    if (!popup) {
      error.value = 'popup_blocked'
      return
    }
    busy.value = true
    const nonce = newSsoStepUpNonce()
    listen(nonce)
    try {
      const url = await oidcApi.stepUp(PROVIDER, operation, `${SSO_STEP_UP_POPUP_PATH}?n=${nonce}`)
      popup.location.href = url
    } catch (e: any) {
      stopWaiting()
      popup.close()
      error.value = e?.response?.data?.error?.code ?? 'generic'
    } finally {
      busy.value = false
    }
  }

  if (getCurrentScope()) onScopeDispose(stopWaiting)

  return {
    enabled,
    available,
    settled,
    busy,
    waiting,
    /** Kód chyby (např. `sso_not_linked`, `popup_blocked`), prázdné = bez chyby. */
    error,
    start,
    /** Návrat z dřívějšího ověření přesměrováním celé stránky. */
    takeProof: () => takeSsoStepUpProof(operation, toValue(context)),
  }
}
