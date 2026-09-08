<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import type { CurrencyAccount } from '@/api/settings'
import { bankConnectionsApi, type BankConnection, type BankConnectionProvider, type BankReconciliationCandidate, type BankSyncRequest, type BankSyncResult } from '@/api/bankConnections'
import { bankConnectionErrorMessage, bankReconciliationCandidates } from '@/utils/bankConnectionError'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDateTime } from '@/composables/useFormat'
import { formatAccountNumber } from '@/utils/bankAccount'
import { appIsoDate } from '@/utils/date'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
import KbPlusOnboarding from './KbPlusOnboarding.vue'
import CreditasConnectionSetup from './CreditasConnectionSetup.vue'
import CsasOnboarding from './CsasOnboarding.vue'
import BankReconciliationConfirmation from './BankReconciliationConfirmation.vue'

const props = defineProps<{ account: CurrencyAccount; connection: BankConnection | null; providers: BankConnectionProvider[]; canWrite: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const route = useRoute()
const { blockDemoMutation } = useDemoMode()
const opened = ref(false)
const token = ref('')
const clientId = ref('')
const certificate = ref('')
const certificateName = ref('')
let certificateReadVersion = 0
const certificatePassword = ref('')
const certificateInput = ref<HTMLInputElement | null>(null)
const certificateProvider = computed(() => ['raiffeisenbank', 'csob'].includes(provider.value))
const enabled = ref(true)
const provider = ref('')
const busy = ref(false)
const error = ref('')
const saved = ref(false)
const result = ref<BankSyncResult | null>(null)
const from = ref('')
const to = ref(appIsoDate())
const reconciliationCandidates = ref<BankReconciliationCandidate[]>([])
const reconciliationConfirmations = ref<string[]>([])
const reconciliationRequest = ref<BankSyncRequest | null>(null)
const reconciliationRetrySeconds = ref(0)
let reconciliationTimer: number | null = null
const available = computed(() => props.providers.filter(p => p.implemented && p.capabilities.statement_import && p.bank_codes.includes(props.account.bank_code || '')))
const newCertificate = computed(() => !!clientId.value || !!certificate.value || !!certificatePassword.value)
const canSave = computed(() => props.canWrite && !busy.value && !!provider.value && (certificateProvider.value
  ? (newCertificate.value ? !!clientId.value.trim() && !!certificate.value : !!props.connection?.has_token)
  : (!!props.connection?.has_token || !!token.value.trim())))
const invalidPeriod = computed(() => {
  if (!from.value) return false
  if (!/^\d{4}-\d{2}-\d{2}$/.test(from.value) || !/^\d{4}-\d{2}-\d{2}$/.test(to.value)) return true
  const start = new Date(`${from.value}T00:00:00Z`)
  const end = new Date(`${to.value}T00:00:00Z`)
  if (!Number.isFinite(start.getTime()) || !Number.isFinite(end.getTime())) return true
  if (start.toISOString().slice(0, 10) !== from.value || end.toISOString().slice(0, 10) !== to.value) return true
  return from.value > to.value || to.value > appIsoDate() || (end.getTime() - start.getTime()) / 86_400_000 + 1 > 31
})
watch(() => props.connection, connection => {
  enabled.value = connection?.enabled ?? true
  provider.value = connection?.provider ?? available.value[0]?.code ?? ''
}, { immediate: true })
watch(available, value => { if (!provider.value) provider.value = value[0]?.code ?? '' })
watch([from, to], clearReconciliation)
watch(() => route.query.currency_id, currencyId => {
  if (String(currencyId) === String(props.account.id)) opened.value = true
}, { immediate: true })
watch(() => [route.query.kb_plus, route.query.currency_id], ([outcome, currencyId]) => {
  if (props.account.bank_code === '0100' && ['connected', 'error'].includes(String(outcome)) && String(currencyId) === String(props.account.id)) opened.value = true
}, { immediate: true })
function clearCredentials() {
  certificateReadVersion++
  certificateName.value = ''
  token.value = ''
  clientId.value = ''
  certificate.value = ''
  certificatePassword.value = ''
  if (certificateInput.value) certificateInput.value.value = ''
}
watch(opened, value => { if (!value) clearCredentials() })
watch(provider, clearCredentials)
onBeforeUnmount(clearReconciliation)
function clearReconciliation() {
  reconciliationCandidates.value = []
  reconciliationConfirmations.value = []
  reconciliationRequest.value = null
  reconciliationRetrySeconds.value = 0
  if (reconciliationTimer !== null) window.clearInterval(reconciliationTimer)
  reconciliationTimer = null
}
function startReconciliationCooldown() {
  reconciliationRetrySeconds.value = 30
  if (reconciliationTimer !== null) window.clearInterval(reconciliationTimer)
  reconciliationTimer = window.setInterval(() => {
    reconciliationRetrySeconds.value--
    if (reconciliationRetrySeconds.value <= 0 && reconciliationTimer !== null) {
      window.clearInterval(reconciliationTimer)
      reconciliationTimer = null
    }
  }, 1000)
}
async function readCertificate(event: Event) {
  const version = ++certificateReadVersion
  certificateName.value = ''
  certificate.value = ''
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  if (file.size > 24576) {
    error.value = t('bank_connection.error_certificate')
    return
  }
  try {
    const bytes = await file.arrayBuffer()
    if (version !== certificateReadVersion) return
    certificate.value = btoa(String.fromCharCode(...new Uint8Array(bytes)))
    certificateName.value = file.name
  } catch {
    error.value = t('bank_connection.error_certificate')
  }
}
async function save() {
  if (!canSave.value || blockDemoMutation()) return
  busy.value = true
  error.value = ''
  saved.value = false
  try {
    await bankConnectionsApi.save(props.account.id, { provider: provider.value, enabled: enabled.value,
      ...(certificateProvider.value
        ? (newCertificate.value ? { credentials: { [provider.value === 'csob' ? 'contract_number' : 'client_id']: clientId.value.trim(), certificate: certificate.value, password: certificatePassword.value } } : {})
        : (token.value.trim() ? { token: token.value.trim() } : {})),
    })
    clearCredentials()
    saved.value = true
    emit('changed')
  } catch (e) {
    error.value = bankConnectionErrorMessage(e, t, t('bank_connection.save_failed'))
  } finally {
    busy.value = false
  }
}
async function disconnect() {
  if (!props.canWrite || busy.value || blockDemoMutation() || !window.confirm(t('bank_connection.disconnect_confirm'))) return
  busy.value = true
  error.value = ''
  try {
    await bankConnectionsApi.disconnect(props.account.id)
    clearCredentials()
    result.value = null
    saved.value = false
    emit('changed')
  } catch (e) {
    error.value = bankConnectionErrorMessage(e, t, t('bank_connection.save_failed'))
  } finally {
    busy.value = false
  }
}
async function runSync(request: BankSyncRequest, confirmations: string[] = [], resetReconciliation = false) {
  if (!props.canWrite || busy.value || invalidPeriod.value || !props.connection?.enabled || blockDemoMutation()) return
  if (resetReconciliation) clearReconciliation()
  busy.value = true
  error.value = ''
  result.value = null
  try {
    result.value = await bankConnectionsApi.sync(props.account.id, {
      ...request,
      ...(confirmations.length > 0 ? { reconciliation_confirmations: confirmations } : {}),
    })
    clearReconciliation()
    emit('changed')
  } catch (e) {
    error.value = bankConnectionErrorMessage(e, t, t('bank_connection.sync_failed'))
    const candidates = bankReconciliationCandidates(e)
    if (candidates.length > 0) {
      reconciliationConfirmations.value = confirmations
      reconciliationCandidates.value = candidates
      reconciliationRequest.value = { ...request }
      startReconciliationCooldown()
    }
    emit('changed')
  } finally {
    busy.value = false
  }
}
async function sync() {
  await runSync(from.value ? { from: from.value, to: to.value } : {}, [], true)
}
async function confirmReconciliation() {
  if (reconciliationRetrySeconds.value > 0 || reconciliationRequest.value === null) return
  await runSync(
    reconciliationRequest.value,
    [...new Set([...reconciliationConfirmations.value, ...reconciliationCandidates.value.map(candidate => candidate.confirmation_key)])],
  )
}
</script>

<template>
  <div class="border-t border-neutral-200 px-5 py-4 space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0">
        <p class="font-medium text-sm break-words">{{ account.label || account.code }} <span class="font-mono text-neutral-600">{{ formatAccountNumber(account.account_number, account.bank_code) || account.iban }} ({{ account.code }})</span></p>
        <p class="text-xs text-neutral-500 mt-1">{{ connection?.has_token ? (connection.enabled ? t('bank_connection.enabled') : t('bank_connection.paused')) : t('bank_connection.disconnected') }}</p>
        <p v-if="connection?.last_sync_at" class="text-xs text-neutral-500">{{ t('bank_connection.last_sync', { date: formatDateTime(connection.last_sync_at) }) }}</p>
        <p v-if="connection?.last_sync_error_code" class="text-xs text-warning-700">{{ t('bank_connection.last_failed') }}</p>
      </div>
      <button v-if="available.length || connection" type="button" :class="btnOutline('neutral')" :aria-expanded="opened" @click="opened = !opened">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg>
        {{ t('bank_connection.settings') }}
      </button>
      <p v-else class="text-xs text-neutral-500">{{ t('bank_connection.bank_unavailable') }}</p>
    </div>
    <div v-if="opened" class="space-y-3">
      <CsasOnboarding v-if="provider === 'csas'" :currency-id="account.id" :can-write="canWrite" />
      <KbPlusOnboarding v-if="provider === 'kb_plus'" :currency-id="account.id" :can-write="canWrite" @changed="emit('changed')" />
      <CreditasConnectionSetup v-if="provider === 'creditas'" :currency-id="account.id" :connection="connection" :can-write="canWrite" :disabled="busy" @changed="emit('changed')" @busy-change="busy = $event" />
      <div v-if="!['kb_plus', 'creditas', 'csas'].includes(provider)" class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm space-y-2" data-testid="connection-help">
        <p class="font-medium">{{ t(provider === 'csob' ? 'bank_connection.csob_help_title' : certificateProvider ? 'bank_connection.rb_help_title' : 'bank_connection.fio_help_title') }}</p>
        <p>{{ t(provider === 'csob' ? 'bank_connection.csob_hint' : certificateProvider ? 'bank_connection.rb_hint' : 'bank_connection.token_hint') }}</p>
        <p v-if="provider !== 'csob'">{{ t('bank_connection.account_verification_hint') }}</p>
        <p class="text-xs text-neutral-600">{{ t('bank_connection.credentials_storage_hint') }}</p>
        <p class="text-xs text-neutral-600">{{ t('bank_connection.payment_help_hint') }}</p>
      </div>
      <form v-if="canWrite && provider !== 'creditas' && (!['kb_plus', 'csas'].includes(provider) || connection?.has_token)" class="space-y-3" @submit.prevent="save">
        <div v-if="!['kb_plus', 'csas'].includes(provider)" class="grid sm:grid-cols-2 gap-3">
          <label class="text-sm">{{ t('bank_connection.provider') }}
            <select v-model="provider" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" :disabled="busy">
              <option v-for="p in providers" :key="p.code" :value="p.code" :disabled="!available.includes(p)">{{ p.label }}{{ !available.includes(p) ? ` (${t('bank_connection.unavailable')})` : '' }}</option>
            </select>
          </label>
          <label v-if="!certificateProvider" class="text-sm">{{ t('bank_connection.token') }}
            <input v-model="token" type="password" autocomplete="new-password" spellcheck="false" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" :disabled="busy" :placeholder="connection?.has_token ? t('bank_connection.token_unchanged') : t('bank_connection.token_required')" />
          </label>
          <template v-else>
            <label class="text-sm">{{ t(provider === 'csob' ? 'bank_connection.contract_number' : 'bank_connection.client_id') }}
              <input v-model="clientId" autocomplete="off" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" :disabled="busy" />
            </label>
            <div class="text-sm min-w-0">
              <p>{{ t('bank_connection.certificate') }}</p>
              <input ref="certificateInput" type="file" accept=".p12,.pfx" class="hidden" tabindex="-1" :aria-label="t('bank_connection.certificate')" :disabled="busy" @change="readCertificate" />
              <div class="flex flex-wrap items-center gap-2 mt-1">
                <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="certificateInput?.click()">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
                  {{ t('bank_connection.choose_certificate') }}
                </button>
                <span class="text-neutral-600 break-all">{{ certificateName || t('bank_connection.no_certificate') }}</span>
              </div>
            </div>
            <label class="text-sm">{{ t('bank_connection.certificate_password') }}
              <input v-model="certificatePassword" type="password" autocomplete="new-password" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" :disabled="busy" />
            </label>
            <p v-if="connection?.has_token" class="text-xs text-neutral-500 sm:col-span-2">{{ t('bank_connection.certificate_unchanged') }}</p>
          </template>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="enabled" type="checkbox" :disabled="busy" />{{ t('bank_connection.enable') }}</label>
        <div class="flex flex-wrap gap-2">
          <button type="submit" :class="btnFilled('primary')" :disabled="!canSave"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('bank_connection.save') }}</button>
          <button v-if="connection" type="button" :class="btnOutline('danger')" :disabled="busy" @click="disconnect"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('bank_connection.disconnect') }}</button>
        </div>
        <p v-if="!certificateProvider && !connection?.has_token && !token.trim()" class="text-xs text-neutral-500">{{ t('bank_connection.token_required') }}</p>
      </form>
      <form v-if="canWrite && connection?.enabled" class="border-t border-neutral-200 pt-3 space-y-2" @submit.prevent="sync">
        <p class="text-sm text-neutral-600">{{ t(provider === 'csob' ? 'bank_connection.csob_sync_hint' : 'bank_connection.sync_hint') }}</p>
        <div class="flex flex-wrap items-end gap-3">
          <label class="flex flex-wrap items-center gap-2 text-sm"><span class="whitespace-nowrap">{{ t('bank_connection.from') }}:</span><DateInput v-model="from" :disabled="busy" :max="to || appIsoDate()" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface" /></label>
          <label class="flex flex-wrap items-center gap-2 text-sm"><span class="whitespace-nowrap">{{ t('bank_connection.to') }}:</span><DateInput v-model="to" :disabled="busy || !from" :min="from || undefined" :max="appIsoDate()" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface" /></label>
          <button type="submit" :class="btnOutline('primary')" :disabled="busy || invalidPeriod"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.download" /></svg>{{ busy ? t('common.loading') : t('bank_connection.sync') }}</button>
        </div>
        <p v-if="invalidPeriod" class="text-xs text-warning-700">{{ t('bank_connection.invalid_period') }}</p>
      </form>
      <p v-if="saved" class="text-sm text-success-600" role="status">{{ t('bank_connection.saved') }}</p>
      <div v-if="result" class="text-sm text-success-600 space-y-1" role="status">
        <p>{{ t('bank_connection.synced', { imported: result.import_result?.transactions ?? 0, matched: result.import_result?.matched ?? 0, skipped: result.import_result?.skipped_duplicates ?? 0 }) }}</p>
        <RouterLink v-if="result.imported_statement_id" :to="{ name: 'bank-detail', params: { id: result.imported_statement_id } }" class="underline">{{ t('bank_connection.open_statement') }}</RouterLink>
      </div>
      <p v-if="error" class="text-sm text-danger-600" role="alert">{{ error }}</p>
      <BankReconciliationConfirmation
        v-if="reconciliationCandidates.length"
        :candidates="reconciliationCandidates"
        :busy="busy"
        :retry-seconds="reconciliationRetrySeconds"
        @confirm="confirmReconciliation"
      />
    </div>
  </div>
</template>
