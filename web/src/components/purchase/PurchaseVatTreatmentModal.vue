<script setup lang="ts">
/**
 * Oprava DPH zařazení přijaté faktury (nárok na odpočet, klasifikace hlavičky, položek
 * a rozpisu DPH) bez storna, i u zaúčtovaného dokladu. Server rozhoduje o právu
 * (RequestAuthorization::canChangePurchaseVatTreatment), zámku období a podaném období
 * DPH; modal jen sbírá změny a provede uživatele potvrzeními, která server vyžádá.
 */
import { computed, reactive, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  purchaseInvoicesApi, type PurchaseInvoice, type PurchaseVatTreatmentPayload, type VatDeduction,
} from '@/api/purchaseInvoices'
import type { VatClassification } from '@/api/vatClassifications'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ invoice: PurchaseInvoice; vatClassifications: VatClassification[]; isAdmin: boolean }>()
const emit = defineEmits<{ close: []; saved: [invoice: PurchaseInvoice] }>()

const { t } = useI18n()
const toast = useToast()
const uid = useId()
const DEDUCTIONS: VatDeduction[] = ['full', 'none', 'proportional', 'reduced']

const header = reactive({
  vat_deduction: props.invoice.vat_deduction ?? 'full',
  vat_deduction_percent: Number(props.invoice.vat_deduction_percent ?? 100),
  vat_classification_code: props.invoice.vat_classification_code ?? null,
})
const itemCodes = reactive<Record<number, string>>(Object.fromEntries(
  (props.invoice.items ?? []).filter(it => it.id).map(it => [it.id as number, it.vat_classification_code ?? '']),
))
const allocations = reactive((props.invoice.vat_allocations ?? []).map(a => ({
  id: a.id as number,
  description: a.description,
  usage_type: a.usage_type,
  total_amount: a.total_amount,
  vat_deduction: a.vat_deduction,
  vat_deduction_percent: Number(a.vat_deduction_percent ?? 100),
})))

const reasons = computed(() => props.invoice.locked?.reasons ?? [])
const postedLocked = computed(() => reasons.value.includes('posted')
  && (reasons.value.includes('period_closed') || props.invoice.locked?.date_locked === true))
const closedForMe = computed(() => reasons.value.includes('period_closed') && !props.isAdmin)

const reconcile = ref(false)
const needsFiledConfirm = ref<string | null>(null)
const filedConfirmed = ref(false)
const busy = ref(false)
const error = ref('')

function payload(): PurchaseVatTreatmentPayload {
  const out: PurchaseVatTreatmentPayload = {}
  const inv = props.invoice
  if (header.vat_deduction !== inv.vat_deduction
    || (header.vat_deduction === 'proportional' && header.vat_deduction_percent !== Number(inv.vat_deduction_percent))) {
    out.vat_deduction = header.vat_deduction
    if (header.vat_deduction === 'proportional') out.vat_deduction_percent = header.vat_deduction_percent
  }
  if ((header.vat_classification_code ?? null) !== (inv.vat_classification_code ?? null)) {
    out.vat_classification_code = header.vat_classification_code
  }
  const items = (inv.items ?? [])
    .filter(it => it.id && itemCodes[it.id] && itemCodes[it.id] !== (it.vat_classification_code ?? ''))
    .map(it => ({ id: it.id as number, vat_classification_code: itemCodes[it.id as number] }))
  if (items.length) out.items = items
  const changedAllocations = allocations
    .filter(a => {
      const old = (inv.vat_allocations ?? []).find(o => o.id === a.id)
      return !!old && (old.vat_deduction !== a.vat_deduction
        || (a.vat_deduction === 'proportional' && Number(old.vat_deduction_percent) !== a.vat_deduction_percent))
    })
    .map(a => ({ id: a.id, vat_deduction: a.vat_deduction, ...(a.vat_deduction === 'proportional' ? { vat_deduction_percent: a.vat_deduction_percent } : {}) }))
  if (changedAllocations.length) out.vat_allocations = changedAllocations
  return out
}
const hasChanges = computed(() => Object.keys(payload()).length > 0)
const canSubmit = computed(() => hasChanges.value && !busy.value && !closedForMe.value
  && (!postedLocked.value || reconcile.value)
  && (needsFiledConfirm.value === null || filedConfirmed.value))

async function save() {
  busy.value = true
  error.value = ''
  try {
    const body = payload()
    if (needsFiledConfirm.value !== null && filedConfirmed.value) body.confirm_filed_period = true
    const saved = await purchaseInvoicesApi.changeVatTreatment(props.invoice.id, body, {
      force: props.isAdmin && reasons.value.includes('period_closed'),
      forceMode: reconcile.value ? 'reconcile' : undefined,
    })
    toast.success(t('purchase_invoice.vat_treatment.change.saved'))
    emit('saved', saved)
  } catch (e: any) {
    const data = e?.response?.data?.error
    if (data?.code === 'period_filed_confirm_required') {
      needsFiledConfirm.value = String(data.filed_period ?? '')
    } else if (data?.code === 'force_mode_required') {
      reconcile.value = false
      error.value = t('purchase_invoice.vat_treatment.change.reconcile_required')
    } else {
      error.value = apiErrorMessage(e)
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="t('purchase_invoice.vat_treatment.change.title')" width-class="max-w-2xl" @close="emit('close')">
    <p class="text-sm text-neutral-600 mb-4">{{ t('purchase_invoice.vat_treatment.change.help') }}</p>

    <div v-if="closedForMe" class="mb-3 p-3 rounded-md bg-warning-50 border border-warning-500/40 text-sm text-warning-700" data-test="vat-treatment-closed">
      {{ t('purchase_invoice.vat_treatment.change.closed_period') }}
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <div>
        <label :for="`${uid}-deduction`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('purchase_invoice.classification.vat_deduction') }}</label>
        <select :id="`${uid}-deduction`" v-model="header.vat_deduction" data-test="vat-treatment-deduction"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="d in DEDUCTIONS" :key="d" :value="d">{{ t(`purchase_invoice.vat_deduction.${d}`) }}</option>
        </select>
        <div v-if="header.vat_deduction === 'proportional'" class="mt-2 flex items-center gap-2">
          <input v-model.number="header.vat_deduction_percent" type="number" min="0" max="100" step="0.01"
            :aria-label="t('purchase_invoice.vat_deduction_percent')"
            class="w-24 h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm text-right" />
          <span class="text-sm text-neutral-600">%</span>
        </div>
      </div>
      <div>
        <label :for="`${uid}-code`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('purchase_invoice.classification.vat_classification') }}</label>
        <select :id="`${uid}-code`" v-model="header.vat_classification_code"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option :value="null">— {{ t('purchase_invoice.classification.no_vat_class') }} —</option>
          <option v-for="vc in vatClassifications" :key="vc.id" :value="vc.code">
            {{ vc.code }} - {{ vc.label.length > 50 ? vc.label.slice(0, 50) + '…' : vc.label }}
          </option>
        </select>
      </div>
    </div>

    <div v-if="allocations.length" class="mt-4">
      <h4 class="text-xs font-medium text-neutral-600 mb-2">{{ t('purchase_invoice.vat_allocation.enable') }}</h4>
      <p class="text-xs text-neutral-500 mb-2">{{ t('purchase_invoice.vat_treatment.change.allocations_hint') }}</p>
      <div v-for="a in allocations" :key="a.id" class="flex flex-wrap items-center gap-2 py-1.5 border-t border-neutral-100 text-sm" data-test="vat-treatment-allocation">
        <span class="flex-1 min-w-40">{{ a.description }} <span class="text-xs text-neutral-500">{{ formatMoney(a.total_amount, invoice.currency) }}</span></span>
        <select v-model="a.vat_deduction" :aria-label="t('purchase_invoice.classification.vat_deduction')"
          :disabled="a.usage_type === 'personal' || a.usage_type === 'mixed'"
          class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="d in DEDUCTIONS" :key="d" :value="d">{{ t(`purchase_invoice.vat_deduction.${d}`) }}</option>
        </select>
        <input v-if="a.vat_deduction === 'proportional'" v-model.number="a.vat_deduction_percent" type="number" min="0" max="100" step="0.01"
          :aria-label="t('purchase_invoice.vat_deduction_percent')"
          class="w-20 h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm text-right" />
      </div>
    </div>

    <div v-if="invoice.items?.length" class="mt-4">
      <h4 class="text-xs font-medium text-neutral-600 mb-2">{{ t('purchase_invoice.vat_treatment.change.items') }}</h4>
      <div v-for="it in invoice.items" :key="it.id" class="flex flex-wrap items-center gap-2 py-1.5 border-t border-neutral-100 text-sm" data-test="vat-treatment-item">
        <span class="flex-1 min-w-40">{{ it.description }} <span class="text-xs text-neutral-500">{{ it.vat_rate_snapshot }}%</span></span>
        <select v-if="it.id" v-model="itemCodes[it.id]" :aria-label="t('purchase_invoice.items.vat_classification')"
          class="w-64 max-w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-if="!it.vat_classification_code" value="">—</option>
          <option v-for="vc in vatClassifications" :key="vc.id" :value="vc.code">
            {{ vc.code }} - {{ vc.label.length > 40 ? vc.label.slice(0, 40) + '…' : vc.label }}
          </option>
        </select>
      </div>
    </div>

    <label v-if="postedLocked && !closedForMe" class="mt-4 flex items-start gap-2 p-3 rounded-md bg-warning-50 border border-warning-500/40 text-sm text-warning-700" data-test="vat-treatment-reconcile">
      <input v-model="reconcile" type="checkbox" class="mt-0.5 rounded" />
      <span>{{ t('purchase_invoice.vat_treatment.change.reconcile') }}</span>
    </label>
    <label v-if="needsFiledConfirm !== null" class="mt-4 flex items-start gap-2 p-3 rounded-md bg-danger-50 border border-danger-500/40 text-sm text-danger-600" data-test="vat-treatment-filed-confirm">
      <input v-model="filedConfirmed" type="checkbox" class="mt-0.5 rounded" />
      <span>{{ t('purchase_invoice.vat_treatment.change.filed_confirm', { period: needsFiledConfirm }) }}</span>
    </label>
    <p v-if="error" class="mt-3 text-sm text-danger-600" data-test="vat-treatment-error">{{ error }}</p>

    <div class="flex flex-wrap justify-end gap-2 mt-4">
      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">{{ t('common.cancel') }}</button>
      <button type="button" :class="btnFilled('warning')" class="whitespace-nowrap" :disabled="!canSubmit" data-test="vat-treatment-save" @click="save">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ t('purchase_invoice.vat_treatment.change.save') }}
      </button>
    </div>
  </Modal>
</template>
