<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { domainsApi, type DomainPurpose, type SupplierDomain } from '@/api/domains'
import { authApi } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { navigateFullPage } from '@/workspace/fullPageNavigation'
import { takeSsoStepUpProof } from '@/security/oidc'
import { useSsoStepUp } from '@/composables/useSsoStepUp'
import SsoStepUpOption from '@/components/security/SsoStepUpOption.vue'

const { t } = useI18n()
const auth = useAuthStore()
const suppliers = useSupplierStore()
const toast = useToast()
const domains = ref<SupplierDomain[]>([])
const loading = ref(false)
const busy = ref<number | 'create' | null>(null)
const hostname = ref('')
const purpose = ref<DomainPurpose>('all')
const primary = ref(true)
const activation = ref<SupplierDomain | null>(null)
const totpCode = ref('')
const actionError = ref('')
const drafts = reactive<Record<number, { purpose: DomainPurpose; primary: boolean }>>({})
const canWrite = computed(() => auth.canWrite('settings.domains'))
const mfaMethods = computed(() => auth.user?.mfa_methods || [])
const canPasskey = computed(() => mfaMethods.value.includes('passkey') && isWebAuthnAvailable())
const canTotp = computed(() => mfaMethods.value.includes('totp'))
const canonicalBaseUrl = computed(() => auth.domainContext?.canonical_base_url || '')
const ssoAvailable = useSsoStepUp('domain.activate').available

async function load() {
  loading.value = true
  try {
    domains.value = await domainsApi.list()
    for (const domain of domains.value) syncDraft(domain)
  } catch (e: any) {
    actionError.value = e?.response?.data?.error?.message || t('domains.load_failed')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  // Návrat z ověření přes Microsoft: aktivace téže domény se dokončí rovnou.
  for (const domain of domains.value) {
    const proof = takeSsoStepUpProof(`domain.activate:${domain.id}`)
    if (proof) {
      openActivation(domain)
      await activateWithProof(domain, proof)
      return
    }
  }
})
watch(() => suppliers.currentSupplierId, load)

async function createDomain() {
  if (!hostname.value.trim() || !canWrite.value) return
  busy.value = 'create'
  actionError.value = ''
  try {
    replace(await domainsApi.create(hostname.value.trim(), purpose.value, primary.value))
    hostname.value = ''
    toast.success(t('domains.created'))
  } catch (e: any) {
    actionError.value = e?.response?.data?.error?.message || t('domains.create_failed')
  } finally {
    busy.value = null
  }
}

function draftFor(domain: SupplierDomain) {
  return drafts[domain.id] ??= { purpose: domain.purpose, primary: domain.is_primary }
}

function syncDraft(domain: SupplierDomain) {
  drafts[domain.id] = { purpose: domain.purpose, primary: domain.is_primary }
}

async function saveDomain(domain: SupplierDomain) {
  busy.value = domain.id
  actionError.value = ''
  const draft = draftFor(domain)
  try {
    replace(await domainsApi.update(domain.id, draft.purpose, draft.primary))
    toast.success(t('domains.saved'))
  } catch (e: any) {
    actionError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    busy.value = null
  }
}

async function verify(domain: SupplierDomain) {
  busy.value = domain.id
  actionError.value = ''
  try {
    const result = await domainsApi.verify(domain.id)
    replace(result.domain)
    if (result.checks.verified) toast.success(t('domains.verified'))
    else actionError.value = result.checks.error || t('domains.verify_failed')
  } catch (e: any) {
    actionError.value = e?.response?.data?.error?.message || t('domains.verify_failed')
  } finally {
    busy.value = null
  }
}

async function rotate(domain: SupplierDomain) {
  if (!confirm(t('domains.rotate_confirm'))) return
  busy.value = domain.id
  try {
    replace(await domainsApi.rotateChallenge(domain.id))
  } catch (e: any) {
    actionError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    busy.value = null
  }
}

function openActivation(domain: SupplierDomain) {
  activation.value = domain
  totpCode.value = ''
  actionError.value = ''
}

async function activateWithPasskey() {
  if (!activation.value || !canPasskey.value) return
  busy.value = activation.value.id
  const operation = `domain.activate:${activation.value.id}`
  try {
    const flow = await authApi.passkeyStepUpOptions(operation)
    const credential = await getCredential(flow.public_key)
    const proof = await authApi.passkeyStepUpVerify(flow.flow_token, operation, credential)
    replace(await domainsApi.activate(activation.value.id, proof, draftFor(activation.value).primary))
    activation.value = null
    toast.success(t('domains.activated'))
  } catch (e: any) {
    const key = webAuthnErrorKey(e)
    actionError.value = key ? t(key) : e?.response?.data?.error?.message || t('domains.activate_failed')
  } finally {
    busy.value = null
  }
}

async function activateWithTotp() {
  if (!activation.value || !/^\d{6}$/.test(totpCode.value)) return
  busy.value = activation.value.id
  const operation = `domain.activate:${activation.value.id}`
  try {
    const proof = await authApi.totpStepUp(operation, totpCode.value)
    replace(await domainsApi.activate(activation.value.id, proof, draftFor(activation.value).primary))
    activation.value = null
    toast.success(t('domains.activated'))
  } catch (e: any) {
    actionError.value = e?.response?.data?.error?.message || t('domains.activate_failed')
  } finally {
    busy.value = null
  }
}

async function activateWithProof(domain: SupplierDomain, proof: string) {
  busy.value = domain.id
  try {
    replace(await domainsApi.activate(domain.id, proof, draftFor(domain).primary))
    activation.value = null
    toast.success(t('domains.activated'))
  } catch (e: any) {
    actionError.value = e?.response?.data?.error?.message || t('domains.activate_failed')
  } finally {
    busy.value = null
  }
}

async function disable(domain: SupplierDomain) {
  if (!confirm(t('domains.disable_confirm', { hostname: domain.hostname }))) return
  busy.value = domain.id
  try {
    await domainsApi.disable(domain.id)
    await load()
    toast.success(t('domains.disabled'))
    if (window.location.hostname.toLowerCase() === domain.hostname.toLowerCase()) {
      const canonical = auth.domainContext?.canonical_login_url
      if (canonical) navigateFullPage(new URL('/admin/settings?tab=company', canonical))
    }
  } catch (e: any) {
    actionError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    busy.value = null
  }
}

async function remove(domain: SupplierDomain) {
  if (!confirm(t('domains.delete_confirm', { hostname: domain.hostname }))) return
  busy.value = domain.id
  try {
    await domainsApi.delete(domain.id)
    domains.value = domains.value.filter((item) => item.id !== domain.id)
    delete drafts[domain.id]
  } catch (e: any) {
    actionError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    busy.value = null
  }
}

async function copy(value: string) {
  await navigator.clipboard.writeText(value)
  toast.success(t('domains.copied'))
}

function replace(domain: SupplierDomain) {
  const index = domains.value.findIndex((item) => item.id === domain.id)
  if (index >= 0) domains.value[index] = domain
  else domains.value.push(domain)
  syncDraft(domain)
}

function statusClass(status: SupplierDomain['status']): string {
  if (status === 'active') return 'bg-success-50 text-success-700 border-success-200'
  if (status === 'verified') return 'bg-primary-50 text-primary-700 border-primary-200'
  if (status === 'verification_failed') return 'bg-danger-50 text-danger-600 border-danger-200'
  return 'bg-neutral-50 text-neutral-600 border-neutral-200'
}
</script>

<template>
  <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
    <div class="mb-4">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('domains.title') }}</h2>
      <p class="mt-1 text-sm text-neutral-600">{{ t('domains.intro') }}</p>
      <p v-if="canonicalBaseUrl" class="mt-2 text-xs text-neutral-500">
        {{ t('domains.default_url') }}
        <a :href="canonicalBaseUrl" class="font-mono text-primary-600 hover:underline">{{ canonicalBaseUrl }}</a>
      </p>
    </div>

    <form v-if="canWrite" class="mb-5 grid grid-cols-1 gap-3 rounded-md border border-neutral-200 bg-neutral-50 p-4 md:grid-cols-[1fr_12rem_auto]" @submit.prevent="createDomain">
      <div>
        <label class="mb-1 block text-sm font-medium">{{ t('domains.hostname') }}</label>
        <input v-model="hostname" required placeholder="portal.example.cz" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium">{{ t('domains.purpose') }}</label>
        <select v-model="purpose" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          <option value="all">{{ t('domains.purpose_all') }}</option>
          <option value="portal">{{ t('domains.purpose_portal') }}</option>
          <option value="public_links">{{ t('domains.purpose_public_links') }}</option>
        </select>
      </div>
      <button type="submit" :disabled="busy === 'create'" :class="[btnFilled('primary'), 'self-end whitespace-nowrap']">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ busy === 'create' ? '…' : t('domains.add') }}
      </button>
      <label class="flex items-center gap-2 text-sm md:col-span-3">
        <input v-model="primary" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
        {{ t('domains.primary') }}
      </label>
    </form>

    <p v-if="actionError" class="mb-4 rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ actionError }}</p>
    <p v-if="loading" class="py-5 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="domains.length === 0" class="rounded-md border border-dashed border-neutral-300 px-4 py-6 text-center text-sm text-neutral-500">{{ t('domains.empty') }}</p>

    <div v-else class="space-y-4">
      <article v-for="domain in domains" :key="domain.id" class="rounded-md border border-neutral-200 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <strong class="font-mono text-sm">{{ domain.hostname }}</strong>
              <span class="rounded-full border px-2 py-0.5 text-xs" :class="statusClass(domain.status)">{{ t(`domains.status_${domain.status}`) }}</span>
              <span v-if="domain.is_primary_portal" class="rounded-full bg-primary-50 px-2 py-0.5 text-xs text-primary-700">{{ t('domains.primary_portal_badge') }}</span>
              <span v-if="domain.is_primary_public" class="rounded-full bg-accent-50 px-2 py-0.5 text-xs text-accent-700">{{ t('domains.primary_public_badge') }}</span>
            </div>
            <p class="mt-1 text-xs text-neutral-500">{{ t(`domains.purpose_${domain.purpose}`) }}</p>
          </div>
          <div v-if="canWrite" class="flex flex-wrap gap-2">
            <button v-if="domain.status !== 'active'" type="button" :disabled="busy === domain.id" :class="btnOutline('primary')" @click="verify(domain)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>{{ t('domains.verify') }}
            </button>
            <button v-if="domain.status === 'verified'" type="button" :disabled="busy === domain.id" :class="btnFilled('success')" @click="openActivation(domain)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>{{ t('domains.activate') }}
            </button>
            <button v-if="domain.status === 'active'" type="button" :disabled="busy === domain.id" :class="btnOutline('warning')" @click="disable(domain)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>{{ t('domains.disable') }}
            </button>
            <button v-if="domain.status !== 'active'" type="button" :disabled="busy === domain.id" :class="btnOutline('danger')" @click="remove(domain)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>{{ t('common.delete') }}
            </button>
          </div>
        </div>

        <div v-if="canWrite && domain.status !== 'active'" class="mt-4 flex flex-wrap items-end gap-3 rounded-md border border-neutral-200 p-3">
          <label class="text-xs">
            <span class="mb-1 block font-medium">{{ t('domains.purpose') }}</span>
            <select v-model="draftFor(domain).purpose" class="h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm">
              <option value="all">{{ t('domains.purpose_all') }}</option>
              <option value="portal">{{ t('domains.purpose_portal') }}</option>
              <option value="public_links">{{ t('domains.purpose_public_links') }}</option>
            </select>
          </label>
          <label class="flex h-9 items-center gap-2 text-sm">
            <input v-model="draftFor(domain).primary" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('domains.primary') }}
          </label>
          <button type="button" :disabled="busy === domain.id" :class="btnOutline('primary')" @click="saveDomain(domain)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('domains.save_settings') }}
          </button>
        </div>

        <div v-if="domain.status !== 'active'" class="mt-4 space-y-2 rounded-md bg-neutral-50 p-3 text-xs">
          <p>{{ t('domains.dns_instruction') }}</p>
          <div class="flex flex-wrap items-center gap-2"><code class="break-all rounded bg-surface px-2 py-1">{{ domain.dns.name }}</code><button type="button" :class="btnOutlineSm('neutral')" @click="copy(domain.dns.name)"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" /></svg>{{ t('domains.copy') }}</button></div>
          <div class="flex flex-wrap items-center gap-2"><code class="break-all rounded bg-surface px-2 py-1">{{ domain.dns.value }}</code><button type="button" :class="btnOutlineSm('neutral')" @click="copy(domain.dns.value)"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" /></svg>{{ t('domains.copy') }}</button></div>
          <p>{{ t('domains.https_instruction') }}</p>
          <button v-if="canWrite" type="button" :class="btnOutlineSm('neutral')" @click="rotate(domain)"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>{{ t('domains.rotate_challenge') }}</button>
          <p v-if="domain.verification_error" class="text-danger-600">{{ domain.verification_error }}</p>
        </div>
        <div v-else class="mt-3 grid gap-1 text-xs text-neutral-600">
          <a v-if="domain.purpose !== 'public_links'" :href="domain.portal_url" class="break-all text-primary-600 hover:underline">{{ domain.portal_url }}</a>
          <span v-if="domain.purpose !== 'portal'" class="break-all">{{ domain.public_base_url }}</span>
        </div>
      </article>
    </div>

    <div v-if="activation" class="mt-4 rounded-md border border-primary-200 bg-primary-50 p-4">
      <h3 class="font-medium">{{ t('domains.activate_title', { hostname: activation.hostname }) }}</h3>
      <p class="mt-1 text-sm text-neutral-600">{{ t('domains.activate_mfa_hint') }}</p>
      <div class="mt-3 flex flex-wrap items-end gap-3">
        <button v-if="canPasskey" type="button" :disabled="busy === activation.id" :class="btnFilled('success')" @click="activateWithPasskey">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>{{ t('domains.activate_passkey') }}
        </button>
        <div v-if="canTotp" class="flex flex-wrap items-end gap-2">
          <label class="text-sm"><span class="mb-1 block">{{ t('auth.totp_code') }}</span><input v-model="totpCode" maxlength="6" inputmode="numeric" class="h-10 w-32 rounded-md border border-neutral-300 bg-surface px-3 font-mono" /></label>
          <button type="button" :disabled="!/^\d{6}$/.test(totpCode) || busy === activation.id" :class="btnFilled('success')" @click="activateWithTotp"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>{{ t('domains.activate_totp') }}</button>
        </div>
        <button type="button" :class="btnOutline('neutral')" @click="activation = null"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
      </div>
      <SsoStepUpOption :key="activation.id" class="mt-3" :operation="`domain.activate:${activation.id}`"
        :disabled="busy === activation.id"
        @verified="(proof: string) => { if (activation) void activateWithProof(activation, proof) }" />
      <p v-if="!canPasskey && !canTotp && !ssoAvailable" class="mt-3 text-sm text-warning-700">{{ t('domains.activate_no_mfa') }}</p>
    </div>
  </section>
</template>
