<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { formInput, formLabel } from '@/components/ui/formStyles'
import {
  eetApi,
  type EetContactContext,
  type EetCapabilityDevice,
  type EetEnvironment,
  type EetPaymentEvent,
  type EetPaymentPreview,
  type EetPaymentRequest,
  type EetPaymentWarning,
} from '@/api/eet'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import { formatDate, formatDateTime, formatMoney } from '@/composables/useFormat'
import { loadEetDevices, newIdempotencyKey, rememberEetDevice, rememberedEetDevice } from '@/composables/useEetCapabilities'
import { useSupplierStore } from '@/stores/supplier'
import EetPaymentStates from './EetPaymentStates.vue'
import { eetIsOpen, useEetPaymentPolling } from './useEetPaymentPolling'

/**
 * Jednotný dialog „Přijmout platbu" u vydané faktury při aktivní EET. Politika
 * evidence se vyhodnocuje na serveru (náhled při každé změně); dialog jen
 * sbírá okolnosti platby a ukazuje oddělené stavy peněz, dokladu a EET.
 */
const props = defineProps<{
  invoiceId: number
  currency: string
  /** Zbývající částka k úhradě; předvyplní se do pole. */
  remaining: number | string
  defaultDeviceId?: number | null
  environment?: EetEnvironment
}>()

const emit = defineEmits<{
  (e: 'close'): void
  /** Platba je uložena na serveru; rodič obnoví doklad a EET souhrn. */
  (e: 'done', event: EetPaymentEvent): void
  /** Volba „QR na místě" přechází do samostatného toku. */
  (e: 'qr'): void
}>()

type Method = 'cash' | 'card_terminal' | 'bank_transfer' | 'stored_value' | 'qr_in_person'
const CONTEXTS: EetContactContext[] = ['in_person', 'on_premises', 'in_vehicle', 'remote']
/** Platba v cizí měně se do EET uvádí v Kč; QR na místě je česká QR platba jen v Kč. */
const isForeign = computed(() => props.currency.toUpperCase() !== 'CZK')
const METHODS = computed<Method[]>(() => isForeign.value
  ? ['cash', 'card_terminal', 'bank_transfer', 'stored_value']
  : ['cash', 'card_terminal', 'bank_transfer', 'stored_value', 'qr_in_person'])

const { t } = useI18n()
const supplierStore = useSupplierStore()
const supplierId = computed(() => supplierStore.currentSupplier?.id ?? null)

function pad(n: number): string { return String(n).padStart(2, '0') }
function localInputValue(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}
function moneyText(v: number | string): string {
  const n = typeof v === 'number' ? v : Number(String(v).replace(',', '.'))
  return Number.isFinite(n) && n > 0 ? n.toFixed(2) : ''
}

const initialReceivedAt = localInputValue(new Date())
const form = reactive({
  amount: moneyText(props.remaining),
  method: 'cash' as Method,
  context: 'in_person' as EetContactContext,
  deviceId: null as number | null,
  receivedAt: initialReceivedAt,
  note: '',
})
const receivedTouched = computed(() => form.receivedAt !== initialReceivedAt)
const devices = ref<EetCapabilityDevice[]>([])

const normalizedAmount = computed(() => {
  const raw = form.amount.replace(/\s/g, '').replace(',', '.')
  if (!/^\d+(\.\d{1,2})?$/.test(raw)) return null
  const n = Number(raw)
  return n > 0 ? n.toFixed(2) : null
})
const amountInvalid = computed(() => form.amount.trim() !== '' && normalizedAmount.value === null)
const accountingDate = computed(() => form.receivedAt.slice(0, 10))

function requestBody(): EetPaymentRequest | null {
  if (!normalizedAmount.value || form.method === 'qr_in_person') return null
  const body: EetPaymentRequest = {
    amount: normalizedAmount.value,
    currency: props.currency,
    invoice_id: props.invoiceId,
    contact_context: form.context,
  }
  if (form.method === 'cash') { body.channel = 'cash'; body.payment_method = 'cash' }
  else if (form.method === 'card_terminal') { body.channel = 'card_terminal'; body.payment_method = 'card' }
  // Uplatnění poukazu / čerpání kreditu: zápočet přijaté platby, do EET jako cerp_zuct.
  else if (form.method === 'stored_value') { body.channel = 'stored_value'; body.payment_method = 'offset' }
  else { body.channel = 'bank_transfer'; body.payment_method = 'bank_transfer' }
  if (form.deviceId) body.device_id = form.deviceId
  if (receivedTouched.value && form.receivedAt) {
    const d = new Date(form.receivedAt)
    if (!Number.isNaN(d.getTime())) body.received_at = d.toISOString()
  }
  if (form.note.trim()) body.note = form.note.trim().slice(0, 255)
  return body
}

watch(() => form.method, (m) => {
  if (m === 'bank_transfer') form.context = 'remote'
  else if (form.context === 'remote') form.context = 'in_person'
})

onMounted(async () => {
  devices.value = await loadEetDevices()
  const ids = new Set(devices.value.map(d => d.id))
  const remembered = rememberedEetDevice(supplierId.value)
  const fallback = props.defaultDeviceId ?? null
  form.deviceId = remembered && ids.has(remembered) ? remembered
    : fallback && (ids.size === 0 || ids.has(fallback)) ? fallback
      : devices.value[0]?.id ?? null
})

// ---- Náhled politiky ----
const preview = ref<EetPaymentPreview | null>(null)
const previewError = ref('')
const previewBusy = ref(false)
let previewTimer: ReturnType<typeof setTimeout> | null = null
let previewSeq = 0

async function runPreview() {
  const body = requestBody()
  if (!body) { preview.value = null; previewError.value = ''; return }
  const seq = ++previewSeq
  previewBusy.value = true
  try {
    const p = await eetApi.previewPayment(body)
    if (seq !== previewSeq) return
    preview.value = p
    previewError.value = ''
  } catch (e) {
    if (seq !== previewSeq) return
    preview.value = null
    previewError.value = apiErrorMessage(e, t('eet.pay.preview_failed'))
  } finally {
    if (seq === previewSeq) previewBusy.value = false
  }
}
function schedulePreview() {
  if (previewTimer) clearTimeout(previewTimer)
  previewTimer = setTimeout(() => { void runPreview() }, 350)
}
watch(
  () => [form.amount, form.method, form.context, form.deviceId, form.receivedAt],
  schedulePreview,
  { immediate: true },
)

/** Kreditová část tržby: dobití kreditu z položek faktury (urceno_cerp_zuct) a čerpání (cerp_zuct). */
const creditView = computed(() => {
  const p = preview.value
  if (!p || !p.creates_record) return null
  const parts: string[] = []
  if (p.credit_topup_amount) parts.push(t('eet.pay.credit_topup', { amount: formatMoney(Number(p.credit_topup_amount), 'CZK') }))
  if (p.credit_draw_amount) parts.push(t('eet.pay.credit_draw', { amount: formatMoney(Number(p.credit_draw_amount), 'CZK') }))
  return parts.length > 0 ? parts.join(' ') : null
})

/** Přepočet cizoměnové platby na Kč pro EET (kurz úhrady ke dni přijetí). */
const fxView = computed(() => {
  const p = preview.value
  if (!p || p.currency.toUpperCase() === 'CZK') return null
  if (p.exchange_rate_error) return { tone: 'bad', text: p.exchange_rate_error.message }
  if (!p.amount_czk || !p.exchange_rate) return null
  return { tone: 'info', text: t('eet.pay.fx_to_eet', { amount: formatMoney(Number(p.amount_czk), 'CZK'), rate: p.exchange_rate, currency: p.currency }) }
})

const policyView = computed(() => {
  const p = preview.value
  if (!p) return null
  if (p.blocks_acceptance) return { tone: 'bad', text: p.eet.message || t('eet.pay.policy.blocked') }
  if (p.creates_record) return { tone: 'ok', text: t('eet.pay.policy.records') }
  if (p.eet.decision === 'unsupported') return { tone: 'bad', text: p.eet.message || t('eet.pay.policy.unsupported') }
  return { tone: 'off', text: p.eet.message || t('eet.pay.policy.not_subject') }
})

/** Upozornění serveru (platbu neblokují); text z překladu podle kódu, jinak ze serveru. */
function warningText(w: EetPaymentWarning): string {
  const key = `eet.pay.warnings.${w.code}`
  const text = t(key, { balance: formatMoney(Number(w.balance ?? 0), 'CZK') })
  return text === key ? w.message : text
}
const previewWarnings = computed(() => (preview.value?.warnings ?? []).map(warningText))

// ---- Odeslání ----
const submitting = ref(false)
const result = ref<EetPaymentEvent | null>(null)
const submitError = ref('')
const networkUncertain = ref(false)
let idempotencyKey = newIdempotencyKey()
let lastBodyJson = ''

const canSubmit = computed(() =>
  !submitting.value && !result.value && form.method !== 'qr_in_person'
  && !!normalizedAmount.value && !previewBusy.value && !!preview.value && !preview.value.blocks_acceptance)

async function submit() {
  const body = requestBody()
  if (!body || !canSubmit.value) return
  const json = JSON.stringify(body)
  // Stejný klíč jen pro stejné tělo; po změně formuláře je to nová platba.
  if (json !== lastBodyJson) { idempotencyKey = newIdempotencyKey(); lastBodyJson = json }
  submitting.value = true
  submitError.value = ''
  networkUncertain.value = false
  try {
    const ev = await eetApi.createPayment(body, idempotencyKey)
    if (body.device_id) rememberEetDevice(supplierId.value, body.device_id)
    result.value = ev
    emit('done', ev)
    startPolling(ev)
  } catch (e) {
    if (apiErrorCode(e) === 'idempotency_conflict') {
      idempotencyKey = newIdempotencyKey()
      lastBodyJson = ''
    }
    const noResponse = !(e as { response?: unknown })?.response
    networkUncertain.value = noResponse
    submitError.value = noResponse ? t('eet.pay.network_uncertain') : apiErrorMessage(e, t('eet.pay.submit_failed'))
  } finally {
    submitting.value = false
  }
}

// ---- Krátký polling stavu EET ----
const { start: startPolling, timedOut: pollTimedOut } = useEetPaymentPolling((fresh) => {
  result.value = fresh
  emit('done', fresh)
})
onBeforeUnmount(() => {
  if (previewTimer) clearTimeout(previewTimer)
  previewSeq++
})

const eetUnconfirmed = computed(() => eetIsOpen(result.value))
const selectedDevice = computed(() => devices.value.find(d => d.id === form.deviceId) ?? null)
</script>

<template>
  <Modal :title="t('eet.pay.title')" width-class="max-w-xl" @close="emit('close')">
    <div v-if="environment === 'playground'" class="mb-3 rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-xs text-warning-700" data-test="eet-playground">
      {{ t('eet.pay.playground') }}
    </div>

    <!-- Výsledek -->
    <div v-if="result" class="space-y-3" data-test="eet-pay-result">
      <div class="rounded-md border border-success-600/30 bg-success-50 px-3 py-2 text-sm text-success-700">
        {{ t('eet.pay.saved') }}
      </div>
      <EetPaymentStates :event="result" />
      <p v-for="(w, i) in (result.warnings ?? [])" :key="`rw-${i}`" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-test="eet-pay-result-warning">
        {{ warningText(w) }}
      </p>
      <p v-if="result.amount_czk && result.exchange_rate" class="text-sm text-neutral-700" data-test="eet-pay-result-fx">
        {{ t('eet.pay.fx_to_eet', { amount: formatMoney(Number(result.amount_czk), 'CZK'), rate: result.exchange_rate, currency: result.currency }) }}
      </p>
      <p v-if="eetUnconfirmed" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-test="eet-pay-waiting">
        {{ t('eet.pay.eet_stored_waiting') }}
        <span v-if="pollTimedOut" class="block text-xs mt-1">{{ t('eet.pay.poll_stopped') }}</span>
      </p>
      <p class="text-xs text-neutral-500">{{ t('eet.pay.received_at', { at: formatDateTime(result.received_at || result.recorded_at) }) }}</p>
    </div>

    <!-- Formulář -->
    <form v-else class="space-y-3" @submit.prevent="submit">
      <div class="grid gap-3 sm:grid-cols-2">
        <label :class="formLabel">
          <span>{{ t('eet.pay.amount') }} ({{ currency }})</span>
          <input v-model="form.amount" type="text" inputmode="decimal" autocomplete="off"
            :class="[formInput, amountInvalid ? 'border-danger-500' : '']" data-test="eet-pay-amount" />
          <span v-if="amountInvalid" class="text-xs text-danger-600">{{ t('eet.pay.amount_invalid') }}</span>
          <span v-else class="text-xs text-neutral-500">{{ t('eet.pay.remaining', { amount: formatMoney(Number(remaining), currency) }) }}</span>
        </label>
        <label :class="formLabel">
          <span>{{ t('eet.pay.method') }}</span>
          <select v-model="form.method" :class="formInput" data-test="eet-pay-method">
            <option v-for="m in METHODS" :key="m" :value="m">{{ t(`eet.pay.methods.${m}`) }}</option>
          </select>
        </label>
      </div>

      <p v-if="form.method === 'card_terminal'" class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-700">
        {{ t('eet.pay.card_hint') }}
      </p>
      <p v-if="form.method === 'stored_value'" class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-700" data-test="eet-pay-stored-value-hint">
        {{ t('eet.pay.stored_value_hint') }}
      </p>
      <p v-if="isForeign" class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-700" data-test="eet-pay-foreign-hint">
        {{ t('eet.pay.foreign_hint', { currency }) }}
      </p>

      <div v-if="form.method === 'qr_in_person'" class="space-y-2 rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm">
        <p>{{ t('eet.pay.qr_hint') }}</p>
        <button type="button" :class="btnFilled('primary')" data-test="eet-pay-go-qr" @click="emit('qr')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.qr" /></svg>
          {{ t('eet.pay.qr_continue') }}
        </button>
      </div>

      <template v-else>
        <div class="grid gap-3 sm:grid-cols-2">
          <label :class="formLabel">
            <span>{{ t('eet.pay.context') }}</span>
            <select v-model="form.context" :class="formInput" data-test="eet-pay-context">
              <option v-for="c in CONTEXTS" :key="c" :value="c">{{ t(`eet.pay.contexts.${c}`) }}</option>
            </select>
          </label>
          <label :class="formLabel">
            <span>{{ t('eet.pay.device') }}</span>
            <select v-if="devices.length > 0" v-model="form.deviceId" :class="formInput" data-test="eet-pay-device">
              <option v-for="d in devices" :key="d.id" :value="d.id">{{ d.code }} - {{ d.unit_name }}</option>
            </select>
            <span v-else class="flex h-9 items-center text-sm text-neutral-600">{{ t('eet.pay.device_default') }}</span>
          </label>
        </div>
        <p v-if="selectedDevice && selectedDevice.id !== defaultDeviceId" class="text-xs text-neutral-500">{{ t('eet.pay.device_remembered') }}</p>
        <p v-if="selectedDevice?.delegation === 'single' || selectedDevice?.delegation === 'multiple'" class="text-xs text-primary-700" data-test="eet-pay-delegation">
          {{ selectedDevice.delegation === 'single'
            ? t('eet.pay.delegation_single', { eic: selectedDevice.delegating_eic ?? '' })
            : t('eet.pay.delegation_multiple') }}
        </p>

        <div class="grid gap-3 sm:grid-cols-2">
          <label :class="formLabel">
            <span>{{ t('eet.pay.received_at_label') }}</span>
            <input v-model="form.receivedAt" type="datetime-local" :class="formInput" data-test="eet-pay-received" />
          </label>
          <div :class="formLabel">
            <span>{{ t('eet.pay.accounting_date') }}</span>
            <span class="flex h-9 items-center text-sm text-neutral-700">{{ formatDate(accountingDate) }}</span>
            <span class="text-xs text-neutral-500">{{ t('eet.pay.accounting_date_hint') }}</span>
          </div>
        </div>

        <label :class="formLabel">
          <span>{{ t('eet.pay.note') }}</span>
          <input v-model="form.note" type="text" maxlength="255" :class="formInput" />
        </label>

        <!-- Rozhodnutí serverové politiky -->
        <div v-if="policyView" data-test="eet-pay-policy"
          class="rounded-md border px-3 py-2 text-sm"
          :class="policyView.tone === 'ok' ? 'border-success-600/30 bg-success-50 text-success-700'
            : policyView.tone === 'bad' ? 'border-danger-500/40 bg-danger-50 text-danger-700'
              : 'border-neutral-200 bg-neutral-50 text-neutral-700'">
          {{ policyView.text }}
        </div>
        <p v-if="creditView" class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm text-neutral-700" data-test="eet-pay-credit">
          {{ creditView }}
        </p>
        <p v-for="(w, i) in previewWarnings" :key="`pw-${i}`" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-test="eet-pay-warning">
          {{ w }}
        </p>
        <div v-if="fxView" data-test="eet-pay-fx"
          class="rounded-md border px-3 py-2 text-sm"
          :class="fxView.tone === 'bad' ? 'border-danger-500/40 bg-danger-50 text-danger-700' : 'border-neutral-200 bg-neutral-50 text-neutral-700'">
          {{ fxView.text }}
        </div>
        <p v-else-if="previewError" class="rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ previewError }}</p>
        <p v-else-if="previewBusy" class="text-xs text-neutral-500">{{ t('eet.pay.preview_loading') }}</p>

        <p v-if="submitError" class="rounded-md border px-3 py-2 text-sm"
          :class="networkUncertain ? 'border-warning-500/40 bg-warning-50 text-warning-700' : 'border-danger-500/40 bg-danger-50 text-danger-700'"
          data-test="eet-pay-error">{{ submitError }}</p>
      </template>
    </form>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">
          {{ result ? t('common.close') : t('common.cancel') }}
        </button>
        <button v-if="!result && form.method !== 'qr_in_person'" type="button" :class="btnFilled('success')" class="whitespace-nowrap"
          :disabled="!canSubmit" data-test="eet-pay-submit" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
          {{ submitting ? t('eet.pay.submitting') : networkUncertain ? t('eet.pay.submit_retry') : t('eet.pay.submit') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
