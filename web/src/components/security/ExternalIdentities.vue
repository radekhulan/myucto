<script setup lang="ts">
/**
 * Profil → Propojené účty: identity u poskytovatelů (OpenID Connect),
 * propojení s poskytovatelem a odpojení. Obojí je citlivá operace (`oidc.link`)
 * a potvrzuje se stejně jako vydání API tokenu: passkey, účtem organizace
 * (jen silný poskytovatel), nebo heslem (+ kód z autentikátoru).
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { authApi } from '@/api/auth'
import { oidcApi, type ExternalIdentity, type ReauthFields } from '@/api/oidc'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { invalidateSsoIdentities } from '@/composables/useSsoStepUp'
import { getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import { ssoReturnPath, takeSsoStepUpProof } from '@/security/oidc'
import { navigateFullPage } from '@/workspace/fullPageNavigation'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import SsoProviderLogo from '@/components/security/SsoProviderLogo.vue'
import SsoStepUpOption from '@/components/security/SsoStepUpOption.vue'

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
    notice.value = t('sso.step_up_done')
  }
})

function linkedProviders(): Set<string> {
  return new Set(list.value.map(identity => identity.provider))
}

function request(action: PendingAction) {
  error.value = ''
  pending.value = action
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

function reauthFields(): ReauthFields {
  if (proof.value) return { step_up_token: proof.value }
  return {
    password: password.value,
    totp_code: totpEnabled.value && totpCode.value !== '' ? totpCode.value : undefined,
  }
}

async function confirm() {
  const action = pending.value
  if (!action || busy.value) return
  if (!proof.value && password.value === '') {
    error.value = t('sso.reauth_required')
    return
  }
  busy.value = true
  error.value = ''
  const fields = reauthFields()
  // Proof je jednorázový: server ho spotřebuje i při neúspěchu akce.
  proof.value = ''
  try {
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

    <div v-if="pending" class="bg-surface border border-primary-500/40 rounded-lg p-5 shadow-sm space-y-3" data-testid="sso-reauth">
      <p class="text-sm text-neutral-700">
        {{ pending.kind === 'link'
          ? t('sso.reauth_link', { provider: providerLabel(pending.provider) })
          : t('sso.reauth_unlink', { provider: providerLabel(pending.identity.provider) }) }}
      </p>
      <p v-if="proof" class="text-sm font-medium text-success-600">✓ {{ t('sso.step_up_verified') }}</p>
      <template v-else>
        <div v-if="hasPasskey && passkeySupported" class="flex flex-wrap gap-2">
          <button type="button" :class="btnOutline('primary')" :disabled="busy || passkeyBusy" @click="verifyPasskey">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
            {{ passkeyBusy ? t('auth.passkey_verifying') : t('sso.verify_passkey') }}
          </button>
        </div>
        <SsoStepUpOption :operation="OPERATION" :disabled="busy || passkeyBusy" />
      </template>
      <form class="space-y-3" @submit.prevent="confirm">
        <label v-if="!proof" class="block text-sm">
          <span class="text-neutral-700 font-medium">{{ t('auth.current_password') }}</span>
          <input v-model="password" type="password" autocomplete="current-password"
                 class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md" />
        </label>
        <label v-if="totpEnabled && !proof" class="block text-sm">
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
          <button type="submit" :class="btnFilled(pending.kind === 'unlink' ? 'danger' : 'primary')" :disabled="busy || passkeyBusy">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="pending.kind === 'unlink' ? ICONS.trash : ICONS.check" /></svg>
            {{ busy ? '…' : pending.kind === 'unlink' ? t('sso.unlink_button') : t('sso.confirm_link') }}
          </button>
        </div>
      </form>
    </div>

    <p v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</p>
    <p v-else-if="notice" class="rounded-md bg-primary-50 border border-primary-500/40 px-3 py-2 text-sm text-primary-700">{{ notice }}</p>

    <p v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="list.length === 0" class="rounded-md border border-dashed border-neutral-300 px-4 py-6 text-center text-sm text-neutral-500">
      {{ t('sso.identities_empty') }}
    </p>
    <div v-else class="space-y-3">
      <div v-for="identity in list" :key="identity.id"
           class="bg-surface border border-neutral-200 rounded-lg p-4 flex flex-wrap items-center justify-between gap-4"
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
    </div>
  </div>
</template>
