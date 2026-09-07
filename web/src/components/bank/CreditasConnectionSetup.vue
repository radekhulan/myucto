<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { bankConnectionsApi, type BankConnection } from '@/api/bankConnections'
import { apiErrorCode } from '@/api/errors'
import { useDemoMode } from '@/composables/useDemoMode'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ currencyId: number; connection: BankConnection | null; canWrite: boolean; disabled?: boolean }>()
const emit = defineEmits<{ changed: []; 'busy-change': [value: boolean] }>()
const { t } = useI18n()
const { blockDemoMutation } = useDemoMode()
const enabled = ref(true)
const editing = ref(false)
const bearerToken = ref('')
const accountId = ref('')
const accountType = ref<'current' | 'savings'>('current')
const certificate = ref('')
const certificateName = ref('')
const password = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
const reading = ref(false)
const certificateInvalid = ref(false)
const busy = ref(false)
const error = ref('')
const saved = ref(false)
let readVersion = 0
let operationVersion = 0
const hasStored = computed(() => props.connection?.provider === 'creditas' && props.connection.has_token)
const showCredentials = computed(() => !hasStored.value || editing.value)
const validToken = computed(() => /^[A-Za-z0-9]{64}$/.test(bearerToken.value.trim()))
const validAccountId = computed(() => /^[A-Za-z0-9_-]{1,40}$/.test(accountId.value.trim()))
const canSave = computed(() => props.canWrite && !props.disabled && !busy.value && !reading.value
  && (!showCredentials.value || (validToken.value && validAccountId.value && !certificateInvalid.value)))

function clearCredentials() {
  readVersion++
  bearerToken.value = ''
  accountId.value = ''
  accountType.value = 'current'
  certificate.value = ''
  certificateName.value = ''
  certificateInvalid.value = false
  password.value = ''
  reading.value = false
  if (fileInput.value) fileInput.value.value = ''
}
function setBusy(value: boolean) {
  busy.value = value
  emit('busy-change', value)
}
function safeError(caught: unknown): string {
  const messages: Record<string, string> = {
    certificate_invalid: 'certificate', certificate_required: 'certificate', credential_invalid: 'credentials',
    creditas_invalid_credentials: 'credentials', creditas_invalid_account_id: 'account_id',
    certificate_runtime_unavailable: 'certificate_runtime',
    encryption_key_unavailable: 'encryption', credential_unavailable: 'encryption', credential_format_invalid: 'encryption',
    statement_account_mismatch: 'account', provider_account_mismatch: 'account', creditas_account_mismatch: 'account',
    creditas_invalid_currency: 'account', account_changed_revalidation_required: 'account',
    remote_unavailable: 'remote', remote_http_error: 'remote', bank_remote_unavailable: 'remote',
    rate_limited: 'cooldown', bank_rate_limited: 'cooldown', bank_connection_busy: 'busy',
  }
  const code = apiErrorCode(caught)
  return t(`creditas_bank.error_${Object.hasOwn(messages, code) ? messages[code] : 'generic'}`)
}
async function readCertificate(event: Event) {
  const version = ++readVersion
  certificate.value = ''
  certificateName.value = ''
  certificateInvalid.value = false
  reading.value = false
  error.value = ''
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  if (file.size === 0 || file.size > 24576) {
    certificateInvalid.value = true
    error.value = t('creditas_bank.error_certificate')
    return
  }
  reading.value = true
  try {
    const bytes = await file.arrayBuffer()
    if (version !== readVersion) return
    certificate.value = btoa(String.fromCharCode(...new Uint8Array(bytes)))
    certificateName.value = file.name
  } catch {
    if (version === readVersion) {
      certificateInvalid.value = true
      error.value = t('creditas_bank.error_certificate')
    }
  } finally {
    if (version === readVersion) reading.value = false
  }
}
async function save() {
  if (!canSave.value || blockDemoMutation()) return
  const version = operationVersion
  setBusy(true)
  error.value = ''
  saved.value = false
  try {
    await bankConnectionsApi.save(props.currencyId, { provider: 'creditas', enabled: enabled.value,
      ...(showCredentials.value ? { credentials: {
        bearer_token: bearerToken.value.trim(), account_id: accountId.value.trim(), account_type: accountType.value,
        ...(certificate.value ? { certificate: certificate.value, password: password.value } : {}),
      } } : {}),
    })
    if (version !== operationVersion) return
    clearCredentials()
    editing.value = false
    saved.value = true
    emit('changed')
  } catch (caught) {
    if (version === operationVersion) error.value = safeError(caught)
  } finally {
    if (version === operationVersion) setBusy(false)
  }
}
async function disconnect() {
  if (!props.canWrite || props.disabled || busy.value || !hasStored.value || blockDemoMutation()
    || !window.confirm(t('creditas_bank.disconnect_confirm'))) return
  const version = operationVersion
  setBusy(true)
  error.value = ''
  saved.value = false
  try {
    await bankConnectionsApi.disconnect(props.currencyId)
    if (version !== operationVersion) return
    clearCredentials()
    editing.value = false
    emit('changed')
  } catch (caught) {
    if (version === operationVersion) error.value = safeError(caught)
  } finally {
    if (version === operationVersion) setBusy(false)
  }
}
function cancelEdit() {
  clearCredentials()
  editing.value = false
  error.value = ''
}
watch([() => props.currencyId, () => props.connection?.id, () => props.connection?.provider, () => props.connection?.has_token, () => props.connection?.enabled], () => {
  operationVersion++
  clearCredentials()
  editing.value = false
  enabled.value = props.connection?.provider === 'creditas' ? props.connection.enabled : true
  if (busy.value) setBusy(false)
}, { immediate: true })
watch(() => props.canWrite, value => { if (!value) cancelEdit() })
onBeforeUnmount(() => { operationVersion++; clearCredentials(); if (busy.value) setBusy(false) })
</script>

<template>
  <section class="space-y-3" :aria-label="t('creditas_bank.title')">
    <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm space-y-2">
      <p class="font-medium">{{ t('creditas_bank.prerequisites') }}</p>
      <p>{{ t('creditas_bank.mtls_hint') }}</p>
      <p>{{ t('creditas_bank.token_hint') }}</p>
      <p>{{ t('creditas_bank.account_hint') }}</p>
      <p class="text-xs text-neutral-600">{{ t('creditas_bank.server_hint') }}</p>
      <p class="text-xs text-neutral-600">{{ t('creditas_bank.payment_hint') }}</p>
    </div>
    <form v-if="canWrite" class="space-y-3" @submit.prevent="save">
      <p v-if="hasStored && !editing" class="text-sm text-neutral-600">{{ t('creditas_bank.unchanged') }}</p>
      <div v-if="showCredentials" class="grid sm:grid-cols-2 gap-3">
        <label class="text-sm">{{ t('creditas_bank.bearer_token') }}
          <input v-model="bearerToken" name="bearer_token" type="password" autocomplete="new-password" spellcheck="false" autocapitalize="none" maxlength="64" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" :disabled="busy || disabled" />
          <span v-if="bearerToken && !validToken" class="text-xs text-warning-700">{{ t('creditas_bank.invalid_token') }}</span>
        </label>
        <label class="text-sm">{{ t('creditas_bank.account_id') }}
          <input v-model="accountId" name="account_id" autocomplete="off" spellcheck="false" autocapitalize="none" maxlength="40" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" :disabled="busy || disabled" />
          <span v-if="accountId && !validAccountId" class="text-xs text-warning-700">{{ t('creditas_bank.error_account_id') }}</span>
        </label>
        <label class="text-sm">{{ t('creditas_bank.account_type') }}
          <select v-model="accountType" name="account_type" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" :disabled="busy || disabled">
            <option value="current">{{ t('creditas_bank.current') }}</option>
            <option value="savings">{{ t('creditas_bank.savings') }}</option>
          </select>
        </label>
        <div class="text-sm min-w-0">
          <p>{{ t('creditas_bank.certificate') }}</p>
          <input ref="fileInput" type="file" accept=".p12,.pfx" class="hidden" tabindex="-1" :aria-label="t('creditas_bank.certificate')" :disabled="busy || disabled" @change="readCertificate" />
          <div class="flex flex-wrap items-center gap-2 mt-1">
            <button type="button" :class="btnOutline('neutral')" :disabled="busy || disabled" @click="fileInput?.click()">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
              {{ t('creditas_bank.choose_certificate') }}
            </button>
            <span class="text-neutral-600 break-all">{{ certificateName || t('creditas_bank.no_certificate') }}</span>
          </div>
        </div>
        <label v-if="certificate" class="text-sm">{{ t('creditas_bank.password') }}
          <input v-model="password" name="certificate_password" type="password" autocomplete="new-password" maxlength="1024" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" :disabled="busy || disabled" />
        </label>
        <p class="text-xs text-neutral-500 sm:col-span-2">{{ t('creditas_bank.credentials_hint') }}</p>
      </div>
      <label class="flex items-center gap-2 text-sm"><input v-model="enabled" name="enabled" type="checkbox" :disabled="busy || disabled" />{{ t('creditas_bank.enabled') }}</label>
      <div class="flex flex-wrap gap-2">
        <button type="submit" :class="btnFilled('primary')" :disabled="!canSave">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
          {{ busy ? t('common.loading') : t('creditas_bank.save') }}
        </button>
        <button v-if="hasStored && !editing" type="button" :class="btnOutline('neutral')" :disabled="busy || disabled" @click="editing = true">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>
          {{ t('creditas_bank.replace') }}
        </button>
        <button v-if="hasStored && editing" type="button" :class="btnOutline('neutral')" :disabled="busy || disabled" @click="cancelEdit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
          {{ t('creditas_bank.cancel') }}
        </button>
        <button v-if="hasStored" type="button" :class="btnOutline('danger')" :disabled="busy || disabled" @click="disconnect">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>
          {{ t('creditas_bank.disconnect') }}
        </button>
      </div>
    </form>
    <p v-if="saved" class="text-sm text-success-600" role="status">{{ t('creditas_bank.saved') }}</p>
    <p v-if="error" class="text-sm text-danger-600" role="alert">{{ error }}</p>
  </section>
</template>
