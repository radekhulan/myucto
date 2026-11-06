<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { formInput, formLabel } from '@/components/ui/formStyles'
import type { EetCapabilityDevice, EetCreditNoteRefundInfo, EetCreditNoteRefundResult } from '@/api/eet'
import { invoicesApi, type Invoice } from '@/api/invoices'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { loadEetDevices } from '@/composables/useEetCapabilities'
import { appIsoDate } from '@/utils/date'

/**
 * Vrácení peněz k dobropisu, jehož opravovaná faktura má evidované tržby (EET).
 * Hotově nebo kartou na terminálu na místě se vrácení zaeviduje jako záporná tržba,
 * nejvýš do neopravené evidované části faktury; převodem na účet se neeviduje.
 */
const props = defineProps<{
  creditNoteId: number
  refund: EetCreditNoteRefundInfo
  defaultDeviceId?: number | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'done', invoice: Invoice): void
}>()

type Method = 'cash' | 'card_terminal' | 'bank_transfer'
const METHODS: Method[] = ['cash', 'card_terminal', 'bank_transfer']

const { t } = useI18n()
const form = reactive({ method: 'cash' as Method, deviceId: null as number | null })
const devices = ref<EetCapabilityDevice[]>([])
const submitting = ref(false)
const error = ref('')
const result = ref<EetCreditNoteRefundResult | null>(null)

onMounted(async () => {
  devices.value = await loadEetDevices()
  const ids = new Set(devices.value.map(d => d.id))
  const fallback = props.defaultDeviceId ?? null
  form.deviceId = fallback && (ids.size === 0 || ids.has(fallback)) ? fallback : devices.value[0]?.id ?? null
})

const recorded = computed(() => form.method !== 'bank_transfer')
/** Kolik se zaeviduje: vrácená částka, nejvýš neopravená evidovaná část faktury. */
const toRecord = computed(() => {
  const ev = props.refund.evidenced
  const sameCurrency = ev.currency === null ? props.refund.currency === 'CZK' : ev.currency === props.refund.currency
  const remaining = Number(sameCurrency && ev.remaining !== null ? ev.remaining : ev.remaining_czk)
  const refundAmount = Number(props.refund.refund_amount)
  return { amount: Math.min(refundAmount, remaining), over: Math.max(0, refundAmount - remaining) }
})

async function submit() {
  if (submitting.value || result.value) return
  submitting.value = true
  error.value = ''
  const eet: Record<string, string | number> = form.method === 'bank_transfer'
    ? { eet_context: 'remote_transfer' }
    : { channel: form.method, contact_context: 'in_person' }
  if (recorded.value && form.deviceId) eet.eet_device_id = form.deviceId
  try {
    const invoice = await invoicesApi.markPaid(props.creditNoteId, appIsoDate(), { eet })
    result.value = invoice.eet ?? null
    emit('done', invoice)
    if (!result.value) emit('close')
  } catch (e) {
    error.value = apiErrorMessage(e, t('invoice.operation_failed'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Modal :title="t('eet.refund.title')" width-class="max-w-lg" @close="emit('close')">
    <div v-if="result" class="space-y-3" data-test="eet-refund-result">
      <div class="rounded-md border border-success-600/30 bg-success-50 px-3 py-2 text-sm text-success-700">
        {{ t('eet.refund.saved', { amount: formatMoney(Number(result.refunded_czk), 'CZK') }) }}
      </div>
      <p v-if="result.warning" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-test="eet-refund-warning">
        {{ result.warning }}
      </p>
    </div>

    <form v-else class="space-y-3" @submit.prevent="submit">
      <p class="text-sm text-neutral-600">
        {{ t('eet.refund.intro', { number: refund.invoice_number, amount: formatMoney(Number(refund.refund_amount), refund.currency) }) }}
      </p>
      <div class="grid gap-3 sm:grid-cols-2">
        <label :class="formLabel">
          <span>{{ t('eet.refund.method') }}</span>
          <select v-model="form.method" :class="formInput" data-test="eet-refund-method">
            <option v-for="m in METHODS" :key="m" :value="m">{{ t(`eet.refund.methods.${m}`) }}</option>
          </select>
        </label>
        <label v-if="recorded" :class="formLabel">
          <span>{{ t('eet.pay.device') }}</span>
          <select v-if="devices.length > 0" v-model="form.deviceId" :class="formInput" data-test="eet-refund-device">
            <option v-for="d in devices" :key="d.id" :value="d.id">{{ d.code }} - {{ d.unit_name }}</option>
          </select>
          <span v-else class="flex h-9 items-center text-sm text-neutral-600">{{ t('eet.pay.device_default') }}</span>
        </label>
      </div>
      <div class="rounded-md border px-3 py-2 text-sm"
        :class="recorded ? 'border-primary-200 bg-primary-50 text-primary-800' : 'border-neutral-200 bg-neutral-50 text-neutral-700'"
        data-test="eet-refund-info">
        <template v-if="recorded">
          {{ t('eet.refund.will_record', { amount: formatMoney(toRecord.amount, refund.evidenced.currency ?? refund.currency) }) }}
          <span v-if="toRecord.over > 0" class="block text-xs mt-1">{{ t('eet.refund.over', { amount: formatMoney(toRecord.over, refund.currency) }) }}</span>
        </template>
        <template v-else>{{ t('eet.refund.remote') }}</template>
      </div>
      <p v-if="error" class="rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 text-sm text-danger-700" data-test="eet-refund-error">{{ error }}</p>
    </form>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">
          {{ result ? t('common.close') : t('common.cancel') }}
        </button>
        <button v-if="!result" type="button" :class="btnFilled('success')" class="whitespace-nowrap" :disabled="submitting"
          data-test="eet-refund-submit" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
          {{ submitting ? t('eet.pay.submitting') : t('eet.refund.submit') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
