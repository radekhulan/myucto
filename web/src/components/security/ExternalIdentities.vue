<script setup lang="ts">
/**
 * Profil → Propojené účty: identity u poskytovatelů (OpenID Connect),
 * propojení s poskytovatelem a odpojení. Obojí je citlivá operace (`oidc.link`)
 * a potvrzuje se passkey, účtem organizace (jen silný poskytovatel, v novém
 * okně), nebo heslem (+ kód z autentikátoru).
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { authApi } from '@/api/auth'
import { oidcApi, type ExternalIdentity, type ReauthFields } from '@/api/oidc'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { invalidateSsoIdentities, useSsoStepUp } from '@/composables/useSsoStepUp'
import SsoStepUpOption from '@/components/security/SsoStepUpOption.vue'
import { useSsoEnforcement } from '@/composables/useSsoEnforcement'
import { getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import { ssoReturnPath, takeSsoStepUpProof } from '@/security/oidc'
import { navigateFullPage } from '@/workspace/fullPageNavigation'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import SsoProviderLogo from '@/components/security/SsoProviderLogo.vue'

const OPERATION = 'oidc.link'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const toast = useToast()

const providers = computed(() => auth.setupStatus?.sso_providers ?? [])
const list = ref<ExternalIdentity[]>([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')

type PendingAction = { kind: 'link'; provider: string } | { kind: 'unlink'; identity: ExternalIdentity }
const pending = ref<PendingAction | null>(null)
const proof = ref('')
const password = ref('')
const totpCode = ref('')
const passkeyBusy = ref(false)
const passkeySupported = isWebAuthnAvailable()
const hasPasskey = computed(() => auth.user?.mfa_methods?.includes('passkey') === true
  || (auth.user?.passkey_count ?? 0) > 0)
const totpEnabled = computed(() => auth.user?.totp_enabled === true)
// Passkey a účet organizace jsou alternativy k heslu. Oddělovač „nebo zadejte
// heslo" ukazuje, že stačí jedna z cest. Účet organizace se ověřuje v novém okně,
// takže rozpracované propojení zůstane.
const ssoStepUp = useSsoStepUp(OPERATION)
const ssoEnforcement = useSsoEnforcement()
const { passwordAllowed, localFactorsAllowed } = ssoEnforcement
// Proof vydaný ověřením účtem organizace (při vynucení silného poskytovatele jediný platný).
const ssoProof = ref('')
const hasAlternatives = computed(() => passwordAllowed.value
  && ((hasPasskey.value && passkeySupported) || ssoStepUp.available.value))

function stamp(value: string | null): string {
  return value?.replace('T', ' ').slice(0, 16) || '—'
}

function providerLabel(id: string): string {
  return providers.value.find(p => p.id === id)?.label ?? id
}

async function load() {
  loading.value = true
  try {
    list.value = await oidcApi.identities()
    invalidateSsoIdentities(list.value)
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('sso.identities_load_failed')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  // Návrat z ověření přes účet organizace: akci uživatel zvolí znovu,
  // proof už má a znovu se neověřuje.
  const returned = takeSsoStepUpProof(OPERATION)
  if (returned) {
    proof.value = returned
    ssoProof.value = returned
    notice.value = t('sso.step_up_done')
  }
})

function linkedProviders(): Set<string> {
  return new Set(list.value.map(identity => identity.provider))
}

const passwordInput = ref<HTMLInputElement | null>(null)

/** Kam se potvrzení vykreslí: odpojení pod řádek účtu, propojení na místě (null). */
const reauthTarget = computed(() => pending.value?.kind === 'unlink'
  ? `[data-sso-reauth-slot="${pending.value.identity.id}"]`
  : null)

async function request(action: PendingAction) {
  error.value = ''
  notice.value = ''
  pending.value = action
  await nextTick()
  passwordInput.value?.focus({ preventScroll: true })
  passwordInput.value?.closest('[data-testid="sso-reauth"]')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
}

function cancel() {
  if (busy.value || passkeyBusy.value) return
  pending.value = null
  password.value = ''
  totpCode.value = ''
}

async function verifyPasskey() {
  if (!passkeySupported) return
  passkeyBusy.value = true
  error.value = ''
  try {
    const flow = await authApi.passkeyStepUpOptions(OPERATION)
    const credential = await getCredential(flow.public_key)
    proof.value = await authApi.passkeyStepUpVerify(flow.flow_token, OPERATION, credential)
    await confirm()
  } catch (e: any) {
    const ceremonyError = webAuthnErrorKey(e)
    error.value = ceremonyError !== null
      ? t(ceremonyError)
      : e?.response?.data?.error?.message || t('sso.passkey_failed')
  } finally {
    passkeyBusy.value = false
  }
}

function reauthFields(): Promise<ReauthFields> {
  if (proof.value) return Promise.resolve({ step_up_token: proof.value })
  return ssoEnforcement.reauthProof(OPERATION, {
    password: password.value,
    totp_code: totpEnabled.value && totpCode.value !== '' ? totpCode.value : undefined,
  })
}

async function confirm() {
  const action = pending.value
  if (!action || busy.value) return
  const enforcedMissing = ssoEnforcement.enforcedStepUpMissing(
    proof.value, proof.value !== '' && proof.value === ssoProof.value, totpCode.value, totpEnabled.value)
  if (enforcedMissing !== null) {
    if (enforcedMissing !== '') {
      error.value = t(enforcedMissing)
      return
    }
  } else if (!proof.value && password.value === '') {
    error.value = t('sso.reauth_required')
    return
  }
  busy.value = true
  error.value = ''
  try {
    const fields = await reauthFields()
    // Proof je jednorázový: server ho spotřebuje i při neúspěchu akce.
    proof.value = ''
    if (action.kind === 'link') {
      const url = await oidcApi.link(action.provider, ssoReturnPath(route.fullPath, '/profile/password?tab=sso'), fields)
      navigateFullPage(url)
      return
    }
    await oidcApi.unlink(action.identity.id, fields)
    pending.value = null
    toast.success(t('sso.unlinked_toast'))
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    error.value = code === 'sso_required'
      ? t('sso.unlink_blocked')
      : e?.response?.data?.error?.message || t('common.error')
  } finally {
    busy.value = false
    password.value = ''
    totpCode.value = ''
  }
}
</script>

<template>
  <div class="space-y-5">
    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-3">
      <div>
        <h2 class="font-medium text-neutral-900">{{ t('sso.profile_title') }}</h2>
        <p class="text-sm text-neutral-500 mt-1">{{ t('sso.profile_description') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-for="provider in providers" :key="provider.id" type="button"
                :class="btnFilled('primary')" :disabled="busy || loading || linkedProviders().has(provider.id)"
                :data-testid="`sso-link-${provider.id}`"
                @click="request({ kind: 'link', provider: provider.id })">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
          {{ t('sso.link_button', { provider: provider.label }) }}
        </button>
      </div>
    </div>

    <!-- Propojení se potvrzuje tady pod tlačítky, odpojení přímo pod řádkem
         odpojovaného účtu, aby potvrzení nebylo daleko od místa kliknutí. -->
    <Teleport defer :to="reauthTarget" :disabled="reauthTarget === null">
    <div v-if="pending" class="bg-surface border border-primary-500/40 rounded-lg p-5 shadow-sm space-y-3" data-testid="sso-reauth">
      <p class="text-sm text-neutral-700">
        {{ pending.kind === 'link'
          ? t('sso.reauth_link', { provider: providerLabel(pending.provider) })
          : t('sso.reauth_unlink', { provider: providerLabel(pending.identity.provider) }) }}
      </p>
      <p v-if="proof" class="text-sm font-medium text-success-600">✓ {{ t('sso.step_up_verified') }}</p>
      <template v-else>
        <div v-if="localFactorsAllowed && hasPasskey && passkeySupported" class="flex flex-wrap gap-2">
          <button type="button" :class="btnOutline('primary')" :disabled="busy || passkeyBusy" @click="verifyPasskey">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
            {{ passkeyBusy ? t('auth.passkey_verifying') : t('sso.verify_passkey') }}
          </button>
        </div>
        <SsoStepUpOption :operation="OPERATION" :disabled="busy || passkeyBusy"
          @verified="(token: string) => { proof = token; ssoProof = token }" />
        <div v-if="hasAlternatives" class="flex items-center gap-3 text-xs text-neutral-500" data-testid="sso-reauth-or">
          <span class="h-px flex-1 bg-neutral-200" aria-hidden="true"></span>
          {{ t('sso.reauth_or_password') }}
          <span class="h-px flex-1 bg-neutral-200" aria-hidden="true"></span>
        </div>
      </template>
      <form class="space-y-3" @submit.prevent="confirm">
        <label v-if="passwordAllowed && !proof" class="block text-sm">
          <span class="text-neutral-700 font-medium">{{ t('auth.current_password') }}</span>
          <input ref="passwordInput" v-model="password" type="password" autocomplete="current-password"
                 class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md" />
        </label>
        <label v-if="localFactorsAllowed && totpEnabled && !proof" class="block text-sm">
          <span class="text-neutral-700 font-medium">{{ t('auth.totp_code') }}</span>
          <input v-model="totpCode" type="text" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="000000"
                 autocomplete="one-time-code"
                 class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md font-mono tracking-widest text-center" />
        </label>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="busy || passkeyBusy" @click="cancel">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" :class="btnFilled(pending.kind === 'unlink' ? 'danger' : 'primary')"
                  :disabled="busy || passkeyBusy || (ssoEnforcement.strong.value && (proof === '' || proof !== ssoProof))">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="pending.kind === 'unlink' ? ICONS.trash : ICONS.check" /></svg>
            {{ busy ? '…' : pending.kind === 'unlink' ? t('sso.unlink_button') : t('sso.confirm_link') }}
          </button>
        </div>
      </form>
      <p v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    </div>
    </Teleport>

    <p v-if="error && !pending" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    <p v-else-if="notice && !error" class="rounded-md bg-primary-50 border border-primary-500/40 px-3 py-2 text-sm text-primary-700">{{ notice }}</p>

    <p v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="list.length === 0" class="rounded-md border border-dashed border-neutral-300 px-4 py-6 text-center text-sm text-neutral-500">
      {{ t('sso.identities_empty') }}
    </p>
    <div v-else class="space-y-3">
      <template v-for="identity in list" :key="identity.id">
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 flex flex-wrap items-center justify-between gap-4"
           data-testid="sso-identity">
        <div class="min-w-0">
          <div class="font-medium inline-flex items-center gap-2">
            <SsoProviderLogo :provider="identity.provider" />
            {{ providerLabel(identity.provider) }}
          </div>
          <div class="text-sm text-neutral-700 break-all">{{ identity.email || identity.display_name || '—' }}</div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('sso.linked_at') }} {{ stamp(identity.created_at) }}
            · {{ t('sso.last_used') }} {{ stamp(identity.last_used_at) }}
          </div>
        </div>
        <button type="button" :class="btnOutlineSm('danger')" :disabled="busy"
                @click="request({ kind: 'unlink', identity })">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          {{ t('sso.unlink_button') }}
        </button>
      </div>
      <div :data-sso-reauth-slot="identity.id" class="empty:hidden"></div>
      </template>
    </div>
  </div>
</template>
