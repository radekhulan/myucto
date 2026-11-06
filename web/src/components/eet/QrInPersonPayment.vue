<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { formInput, formLabel } from '@/components/ui/formStyles'
import {
  eetApi,
  type EetCapabilityDevice,
  type EetEnvironment,
  type EetPaymentEvent,
  type EetPaymentIntent,
  type EetPaymentIntentRequest,
} from '@/api/eet'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { loadEetDevices, newIdempotencyKey, rememberEetDevice, rememberedEetDevice } from '@/composables/useEetCapabilities'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import EetPaymentStates from './EetPaymentStates.vue'
import { eetIsOpen, useEetPaymentPolling } from './useEetPaymentPolling'

/**
 * Platba QR na místě: po otevření hned záměr + QR na celou zbývající částku, potom
 * výslovné potvrzení obsluhy „Zákazník zadal platbu". Zobrazení QR není inkaso ani
 * tržba; přijetí peněz bankou ověří až bankovní párování. Změna částky nebo zařízení
 * nepotvrzený záměr zruší a založí nový, zavření bez potvrzení záměr zruší.
 */
const props = defineProps<{
  invoiceId: number
  currency: string
  remaining: number | string
  defaultDeviceId?: number | null
  environment?: EetEnvironment
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'done', event: EetPaymentEvent): void
}>()

const foreign = computed(() => props.currency.toUpperCase() !== 'CZK')

type Ctx = 'in_person' | 'on_premises' | 'in_vehicle'
const CONTEXTS: Ctx[] = ['in_person', 'on_premises', 'in_vehicle']
const DEBOUNCE_MS = 600

const { t } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()
const supplierId = computed(() => supplierStore.currentSupplier?.id ?? null)

const remainingNumber = computed(() => {
  const n = Number(String(props.remaining).replace(',', '.'))
  return Number.isFinite(n) ? n : 0
})

const form = reactive({
  amount: remainingNumber.value > 0 ? remainingNumber.value.toFixed(2) : '',
  context: 'in_person' as Ctx,
  deviceId: null as number | null,
})
const devices = ref<EetCapabilityDevice[]>([])
const editOpen = ref(false)

const normalizedAmount = computed(() => {
  const raw = form.amount.replace(/\s/g, '').replace(',', '.')
  if (!/^\d+(\.\d{1,2})?$/.test(raw)) return null
  return Number(raw) > 0 ? Number(raw).toFixed(2) : null
})
// Server odečítá i potvrzené QR příkazy čekající na banku, proto první QR nechá spočítat jeho.
const serverAmount = ref(true)
const serverMax = ref<number | null>(null)
let syncingAmount = false
const maxAmount = computed(() => serverMax.value !== null ? Math.min(serverMax.value, remainingNumber.value) : remainingNumber.value)
const amountInvalid = computed(() => form.amount.trim() !== '' && normalizedAmount.value === null)
const amountOver = computed(() => normalizedAmount.value !== null && Number(normalizedAmount.value) > maxAmount.value + 0.004)
const amountUsable = computed(() => normalizedAmount.value !== null && !amountOver.value)

const intent = ref<EetPaymentIntent | null>(null)
const confirmed = ref<EetPaymentEvent | null>(null)
const loading = ref(false)
const confirming = ref(false)
// Chyba přípravy QR se ukáže místo QR kódu, chyba potvrzení pod ním (QR zůstává).
const createError = ref('')
const createUncertain = ref(false)
const error = ref('')
const networkUncertain = ref(false)
let intentBodyJson = ''
const pendingChange = ref(false)
// Klíč potvrzení patří k jednomu záměru; po ztracené odpovědi ho opakování znovu použije.
let confirmKey = newIdempotencyKey()
// Klíč založení se znovu použije jen při opakování nezdařeného požadavku se stejným tělem.
let failedCreate: { json: string, key: string } | null = null
let generation = 0
let queue: Promise<void> = Promise.resolve()
let debounceTimer: ReturnType<typeof setTimeout> | null = null
let ready = false
let closed = false

const { start: startPolling, timedOut: pollTimedOut } = useEetPaymentPolling((fresh) => {
  confirmed.value = fresh
  emit('done', fresh)
})

function requestBody(): EetPaymentIntentRequest | null {
  if (!serverAmount.value && (!amountUsable.value || !normalizedAmount.value)) return null
  const body: EetPaymentIntentRequest = {
    invoice_id: props.invoiceId,
    contact_context: form.context,
  }
  if (!serverAmount.value && normalizedAmount.value) body.amount = normalizedAmount.value
  if (form.deviceId) body.device_id = form.deviceId
  return body
}

function cancelQuietly(current: EetPaymentIntent | null) {
  if (!current || current.payment_order_status !== 'initiated') return Promise.resolve()
  return eetApi.cancelPaymentIntent(current.payment_event_id).then(() => undefined, () => undefined)
}

/** Zruší aktuální nepotvrzený záměr a založí nový; požadavky jdou za sebou, zastaralé se přeskočí. */
function regenerate() {
  const body = requestBody()
  if (!body || confirmed.value || closed) return
  const gen = ++generation
  loading.value = true
  createError.value = ''
  createUncertain.value = false
  error.value = ''
  networkUncertain.value = false
  queue = queue.then(async () => {
    if (gen !== generation || closed || confirmed.value) return
    const previous = intent.value
    intent.value = null
    await cancelQuietly(previous)
    const json = JSON.stringify(body)
    const key = failedCreate && failedCreate.json === json ? failedCreate.key : newIdempotencyKey()
    try {
      const created = await eetApi.createPaymentIntent(body, key)
      failedCreate = null
      if (closed) {
        // Dialog se mezitím zavřel: tenhle QR už nikdo neuvidí.
        await cancelQuietly(created)
        return
      }
      // Při novější změně ve frontě ho další krok zruší.
      intent.value = created
      intentBodyJson = json
      if (serverAmount.value) {
        const total = Number(created.qr?.amount ?? created.amount)
        if (Number.isFinite(total) && total > 0) {
          serverMax.value = total
          syncingAmount = true
          form.amount = total.toFixed(2)
          void nextTick(() => { syncingAmount = false })
        }
      }
      confirmKey = newIdempotencyKey()
      if (body.device_id) rememberEetDevice(supplierId.value, body.device_id)
    } catch (e) {
      failedCreate = { json, key }
      if (gen !== generation) return
      const noResponse = !(e as { response?: unknown })?.response
      createUncertain.value = noResponse
      createError.value = noResponse ? t('eet.pay.network_uncertain') : apiErrorMessage(e, t('eet.qr.create_failed'))
    } finally {
      if (gen === generation) loading.value = false
    }
  })
}

onMounted(async () => {
  if (foreign.value) return
  loading.value = true
  try {
    devices.value = await loadEetDevices()
  } catch {
    devices.value = []
  }
  const ids = new Set(devices.value.map(d => d.id))
  const remembered = rememberedEetDevice(supplierId.value)
  const fallback = props.defaultDeviceId ?? null
  form.deviceId = remembered && ids.has(remembered) ? remembered
    : fallback && (ids.size === 0 || ids.has(fallback)) ? fallback
      : devices.value[0]?.id ?? null
  ready = true
  if (remainingNumber.value > 0) {
    regenerate()
  } else {
    loading.value = false
    editOpen.value = true
    createError.value = t('eet.qr.nothing_to_pay')
  }
})

watch(() => form.amount, () => {
  if (ready && !syncingAmount) serverAmount.value = false
})

watch(() => [form.amount, form.context, form.deviceId], () => {
  if (!ready || confirmed.value || syncingAmount) return
  if (debounceTimer) clearTimeout(debounceTimer)
  // Do přegenerování nejde potvrdit QR, který už neodpovídá zadání.
  pendingChange.value = requestBody() !== null
  debounceTimer = setTimeout(() => {
    debounceTimer = null
    pendingChange.value = false
    const body = requestBody()
    if (!body) return
    if (intent.value && !createError.value && JSON.stringify(body) === intentBodyJson) return
    regenerate()
  }, DEBOUNCE_MS)
})

onBeforeUnmount(() => {
  if (debounceTimer) clearTimeout(debounceTimer)
})

async function confirmIntent() {
  if (!intent.value || loading.value || pendingChange.value || !amountUsable.value || confirming.value || confirmed.value) return
  confirming.value = true
  error.value = ''
  networkUncertain.value = false
  try {
    const ev = await eetApi.confirmPaymentIntent(intent.value.payment_event_id, confirmKey)
    confirmed.value = ev
    emit('done', ev)
    startPolling(ev)
  } catch (e) {
    const noResponse = !(e as { response?: unknown })?.response
    networkUncertain.value = noResponse
    error.value = noResponse ? t('eet.pay.network_uncertain') : apiErrorMessage(e, t('eet.qr.confirm_failed'))
  } finally {
    confirming.value = false
  }
}

/** Zavření (křížek, Esc, Zrušit): nepotvrzený záměr se zruší, chyba se ignoruje. */
function close() {
  if (closed) return
  closed = true
  if (debounceTimer) clearTimeout(debounceTimer)
  if (!confirmed.value && !confirming.value) void cancelQuietly(intent.value)
  emit('close')
}

async function copy(value: string | null | undefined) {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    toast.success(t('eet.qr.copied'))
  } catch {
    toast.error(t('eet.qr.copy_failed'))
  }
}

const transferRows = computed(() => {
  const qr = intent.value?.qr
  if (!qr) return []
  const rows: { key: string, label: string, value: string }[] = []
  if (qr.account_number && qr.bank_code) rows.push({ key: 'account', label: t('eet.qr.account'), value: `${qr.account_number}/${qr.bank_code}` })
  if (qr.iban) rows.push({ key: 'iban', label: t('eet.qr.iban'), value: qr.iban })
  if (qr.variable_symbol) rows.push({ key: 'vs', label: t('eet.qr.variable_symbol'), value: qr.variable_symbol })
  const amount = qr.amount ?? intent.value?.amount
  if (amount) rows.push({ key: 'amount', label: t('eet.qr.amount'), value: String(amount) })
  return rows
})

const eetUnconfirmed = computed(() => eetIsOpen(confirmed.value))
</script>

<template>
  <Modal :title="t('eet.qr.title')" width-class="max-w-2xl" @close="close">
    <div v-if="environment === 'playground'" class="mb-3 rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-xs text-warning-700">
      {{ t('eet.pay.playground') }}
    </div>

    <!-- Potvrzeno obsluhou -->
    <div v-if="confirmed" class="space-y-3" data-test="eet-qr-confirmed">
      <div class="rounded-md border border-success-600/30 bg-success-50 px-3 py-2 text-sm text-success-700">{{ t('eet.qr.confirmed') }}</div>
      <EetPaymentStates :event="confirmed" />
      <p class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-700" data-test="eet-qr-bank-unverified">
        {{ t('eet.qr.bank_unverified') }}
      </p>
      <p v-if="eetUnconfirmed" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700">
        {{ t('eet.pay.eet_stored_waiting') }}
        <span v-if="pollTimedOut" class="block text-xs mt-1">{{ t('eet.pay.poll_stopped') }}</span>
      </p>
    </div>

    <!-- Česká QR platba (SPAYD) je jen v Kč; fakturu v cizí měně přijměte hotově nebo kartou. -->
    <p v-else-if="foreign" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-test="eet-qr-foreign">
      {{ t('eet.qr.czk_only', { currency }) }}
    </p>

    <!-- QR hned po otevření -->
    <div v-else class="space-y-3" data-test="eet-qr-shown">
      <p class="text-sm text-neutral-700">{{ t('eet.qr.scan_hint') }}</p>
      <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
        <div class="flex h-64 w-64 shrink-0 items-center justify-center rounded-md border border-neutral-200 bg-white p-2">
          <div v-if="loading" class="flex flex-col items-center gap-2 text-sm text-neutral-500" data-test="eet-qr-loading">
            <svg class="h-8 w-8 animate-spin text-primary-600" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
            </svg>
            {{ t('eet.qr.loading') }}
          </div>
          <p v-else-if="createError" class="px-2 text-center text-sm"
            :class="createUncertain ? 'text-warning-700' : 'text-danger-700'" data-test="eet-qr-error">{{ createError }}</p>
          <img v-else-if="intent?.qr?.image_data_uri" :src="intent.qr.image_data_uri" :alt="t('eet.qr.alt')"
            class="h-full w-full" data-test="eet-qr-image" />
          <p v-else-if="intent" class="px-2 text-center text-sm text-warning-700">{{ t('eet.qr.no_image') }}</p>
        </div>

        <div class="w-full min-w-0 flex-1 space-y-3">
          <p v-if="intent && !loading" class="text-center text-2xl font-semibold sm:text-left" data-test="eet-qr-amount-big">
            {{ formatMoney(Number(intent.amount), intent.currency) }}
          </p>
          <dl v-if="intent && !loading && transferRows.length" class="divide-y divide-neutral-100 rounded-md border border-neutral-200 text-sm" data-test="eet-qr-transfer">
            <div v-for="row in transferRows" :key="row.key" class="flex items-center justify-between gap-2 px-3 py-1.5">
              <div class="min-w-0">
                <dt class="text-xs text-neutral-500">{{ row.label }}</dt>
                <dd class="break-all font-mono">{{ row.value }}</dd>
              </div>
              <button type="button" class="shrink-0 rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-primary-700 cursor-pointer"
                :title="t('eet.qr.copy_value', { label: row.label })" :aria-label="t('eet.qr.copy_value', { label: row.label })"
                :data-test="`eet-qr-copy-${row.key}`" @click="copy(row.value)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" /></svg>
              </button>
            </div>
          </dl>
          <button v-if="createError && !loading" type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :disabled="!amountUsable" data-test="eet-qr-retry" @click="regenerate">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.qr" /></svg>
            {{ t('eet.qr.retry') }}
          </button>

          <button type="button" class="inline-flex items-center gap-1 text-xs text-primary-700 hover:underline cursor-pointer"
            :aria-expanded="editOpen" data-test="eet-qr-edit-toggle" @click="editOpen = !editOpen">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
            {{ t('eet.qr.edit_toggle') }}
          </button>
          <div v-if="editOpen" class="space-y-3 rounded-md border border-neutral-200 p-3" data-test="eet-qr-edit">
            <label :class="formLabel">
              <span>{{ t('eet.pay.amount') }} ({{ currency }})</span>
              <input v-model="form.amount" type="text" inputmode="decimal" autocomplete="off"
                :class="[formInput, amountInvalid || amountOver ? 'border-danger-500' : '']" data-test="eet-qr-amount" />
              <span v-if="amountInvalid" class="text-xs text-danger-600">{{ t('eet.pay.amount_invalid') }}</span>
              <span v-else-if="amountOver" class="text-xs text-danger-600">{{ t('eet.qr.amount_over', { amount: formatMoney(maxAmount, currency) }) }}</span>
              <span v-else class="text-xs text-neutral-500">{{ t('eet.pay.remaining', { amount: formatMoney(maxAmount, currency) }) }}</span>
            </label>
            <label :class="formLabel">
              <span>{{ t('eet.pay.context') }}</span>
              <select v-model="form.context" :class="formInput" data-test="eet-qr-context">
                <option v-for="c in CONTEXTS" :key="c" :value="c">{{ t(`eet.pay.contexts.${c}`) }}</option>
              </select>
            </label>
            <label :class="formLabel">
              <span>{{ t('eet.pay.device') }}</span>
              <select v-if="devices.length > 0" v-model="form.deviceId" :class="formInput" data-test="eet-qr-device">
                <option v-for="d in devices" :key="d.id" :value="d.id">{{ d.code }} - {{ d.unit_name }}</option>
              </select>
              <span v-else class="flex h-9 items-center text-sm text-neutral-600">{{ t('eet.pay.device_default') }}</span>
            </label>
            <p class="text-xs text-neutral-500">{{ t('eet.qr.edit_hint') }}</p>
          </div>
        </div>
      </div>
      <p class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-700">{{ t('eet.qr.records_nothing') }}</p>
    </div>

    <p v-if="error" class="mt-3 rounded-md border px-3 py-2 text-sm"
      :class="networkUncertain ? 'border-warning-500/40 bg-warning-50 text-warning-700' : 'border-danger-500/40 bg-danger-50 text-danger-700'"
      data-test="eet-qr-confirm-error">{{ error }}</p>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <template v-if="confirmed || foreign">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="close">{{ t('common.close') }}</button>
        </template>
        <template v-else>
          <button type="button" :class="btnOutline('danger')" class="whitespace-nowrap" :disabled="confirming" data-test="eet-qr-cancel" @click="close">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('eet.qr.cancel') }}
          </button>
          <button type="button" :class="btnFilled('success')" class="whitespace-nowrap"
            :disabled="!intent || loading || pendingChange || !amountUsable || confirming" data-test="eet-qr-confirm" @click="confirmIntent">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
            {{ confirming ? t('eet.pay.submitting') : networkUncertain && intent ? t('eet.qr.confirm_retry') : t('eet.qr.confirm') }}
          </button>
        </template>
      </div>
    </template>
  </Modal>
</template>
