<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api } from '@/api/client'
import { useDemoMode } from '@/composables/useDemoMode'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'
import { navigateFullPage } from '@/workspace/fullPageNavigation'

const props = defineProps<{ currencyId: number; canWrite: boolean }>()
const { t } = useI18n()
const route = useRoute()
const { blockDemoMutation } = useDemoMode()
const fields = ref({ api_key: '', client_id: '', client_secret: '' })
const fieldNames = ['api_key', 'client_id', 'client_secret'] as const
const callback = ref('')
const sandbox = ref(false)
const ready = ref(false)
const busy = ref(false)
const error = ref(false)
// Zakládání plateb (siblings.payments) je vědomá volba; bez ní zůstane připojení jen ke čtení.
const payments = ref(false)
type PaymentSubmissionState = 'available' | 'authorization_scope_missing' | 'account_not_suitable' | null
const paymentSubmission = ref<PaymentSubmissionState>(null)
let disposed = false
const canStart = computed(() => props.canWrite && ready.value && !busy.value && fieldNames.every(key => fields.value[key].trim()))
// Návrat z banky nese účet a důvod; starší návrat bez účtu platí pro všechny účty ČS.
const callbackError = computed(() => {
  if (route.query.csas !== 'error') return ''
  const currency = String(route.query.currency_id ?? '')
  if (currency !== '' && currency !== String(props.currencyId)) return ''
  return String(route.query.code ?? '') === 'statement_account_mismatch' ? 'bank_connection.csas_error_account_mismatch' : 'bank_connection.csas_error'
})
onMounted(async () => {
  try {
    const { data } = await api.get<{ callback_url: string; server_ready: boolean; environment: 'sandbox' | 'production'; payment_submission?: PaymentSubmissionState }>(`/settings/bank-connections/${props.currencyId}/csas/onboarding`)
    if (disposed) return
    callback.value = data.callback_url
    sandbox.value = data.environment === 'sandbox'
    ready.value = data.server_ready === true
    paymentSubmission.value = data.payment_submission ?? null
  } catch { if (!disposed) error.value = true }
})
onBeforeUnmount(() => { disposed = true; fields.value = { api_key: '', client_id: '', client_secret: '' } })
async function start() {
  if (!canStart.value || blockDemoMutation()) return
  busy.value = true
  error.value = false
  try {
    const { data } = await api.post<{ redirect_url: string }>(`/settings/bank-connections/${props.currencyId}/csas/onboarding`, { ...fields.value, payments: payments.value })
    fields.value = { api_key: '', client_id: '', client_secret: '' }
    if (disposed) return
    const url = new URL(data.redirect_url)
    const expectedOrigin = sandbox.value ? 'https://webapi.developers.erstegroup.com' : 'https://bezpecnost.csas.cz'
    const expectedPath = sandbox.value ? '/api/csas/sandbox/v1/sandbox-idp/auth' : '/api/psd2/fl/oidc/v1/auth'
    if (url.origin !== expectedOrigin || url.pathname !== expectedPath || url.username || url.password || url.hash) throw new Error()
    navigateFullPage(url)
  } catch { if (!disposed) error.value = true }
  finally { if (!disposed) busy.value = false }
}
</script>

<template>
  <div class="space-y-3">
    <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm space-y-2">
      <p class="font-medium">{{ t('bank_connection.csas_title') }}</p>
      <p v-if="sandbox" class="font-medium text-warning-700">{{ t('bank_connection.csas_sandbox_label') }}</p>
      <p>{{ t(sandbox ? 'bank_connection.csas_sandbox_hint' : 'bank_connection.csas_hint') }}</p>
      <a href="https://developers.erstegroup.com" target="_blank" rel="noopener noreferrer" class="underline">Erste Developer Portal</a>
      <p>{{ t('bank_connection.csas_read_only') }}</p>
      <p>{{ t('bank_connection.csas_payments_hint') }}</p>
      <p class="text-xs text-neutral-600">{{ t('bank_connection.csas_logging') }}</p>
      <p class="text-xs text-neutral-600">{{ t('bank_connection.credentials_storage_hint') }}</p>
    </div>
    <p v-if="callback" class="text-sm break-all">{{ t('bank_connection.csas_callback') }}: <code>{{ callback }}</code></p>
    <p v-if="paymentSubmission" class="text-sm" :class="paymentSubmission === 'available' ? 'text-success-700' : 'text-warning-700'" data-test="csas-payments-state">{{ t(`bank_connection.csas_payments_${paymentSubmission}`) }}</p>
    <form v-if="canWrite" class="space-y-3" @submit.prevent="start">
      <div class="grid sm:grid-cols-2 gap-3">
        <label v-for="key in fieldNames" :key="key" class="text-sm">{{ t(`bank_connection.csas_${key}`) }}
          <input v-model="fields[key]" type="password" autocomplete="new-password" spellcheck="false" maxlength="2048" :disabled="busy" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" />
        </label>
      </div>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="payments" type="checkbox" :disabled="busy" class="mt-0.5" data-test="csas-payments" />
        <span>{{ t('bank_connection.csas_payments_toggle') }}</span>
      </label>
      <button type="submit" :class="btnFilled('primary')" :disabled="!canStart"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg>{{ t('bank_connection.csas_connect') }}</button>
    </form>
    <p v-if="callback && !ready" class="text-sm text-warning-700">{{ t('bank_connection.csas_server') }}</p>
    <p v-if="error || callbackError" role="alert" class="text-sm text-danger-600" data-test="csas-error">{{ t(error ? 'bank_connection.csas_error' : callbackError) }}</p>
  </div>
</template>
